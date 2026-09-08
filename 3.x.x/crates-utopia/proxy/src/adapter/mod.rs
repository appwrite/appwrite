//! Base adapter with routing, caching, SSRF validation. Mirrors `src/Adapter.php`.

pub mod tcp;

use std::collections::HashMap;
use std::net::{IpAddr, Ipv4Addr, Ipv6Addr};
use std::sync::Arc;
use std::time::{Duration, Instant};

use moka::future::Cache;
use tokio::sync::RwLock;

use crate::connection_result::ConnectionResult;
use crate::dns;
use crate::protocol::Protocol;
use crate::resolver::{Resolver, ResolverError, ResolverResult};
use serde_json::json;
use utopia_validators::{Ip, Range, Validator};

/// Synchronous callback returning a resolver result override.
pub type OnResolveFn = dyn Fn(&[u8]) -> Option<Result<ResolverResult, ResolverError>> + Send + Sync;

/// Entry stored in the routing cache.
#[derive(Clone, Debug)]
struct CacheEntry {
    endpoint: String,
    inserted_at: Instant,
}

/// Base proxy adapter. Holds the resolver, optional override callback, routing cache,
/// SSRF skip flag, and protocol. The TCP adapter composes this.
pub struct Adapter {
    resolver: Option<Arc<dyn Resolver>>,
    protocol: Protocol,
    cache: Cache<Vec<u8>, CacheEntry>,
    cache_ttl: RwLock<Duration>,
    skip_validation: RwLock<bool>,
    on_resolve: RwLock<Option<Arc<OnResolveFn>>>,
}

impl std::fmt::Debug for Adapter {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Adapter")
            .field("protocol", &self.protocol)
            .finish_non_exhaustive()
    }
}

impl Adapter {
    /// Default DNS lookup timeout for SSRF validation - matches PHP `Dns::resolve()`
    /// timeout parameter default of 1.0s.
    pub const DNS_TIMEOUT: Duration = Duration::from_millis(1000);

    /// Routing cache capacity - matches PHP `Table` size of 10_000.
    pub const CACHE_CAPACITY: u64 = 10_000;

    pub fn new(resolver: Option<Arc<dyn Resolver>>, protocol: Protocol) -> Self {
        Self {
            resolver,
            protocol,
            cache: Cache::builder()
                .max_capacity(Self::CACHE_CAPACITY)
                .time_to_live(Duration::from_secs(3600))
                .build(),
            cache_ttl: RwLock::new(Duration::ZERO),
            skip_validation: RwLock::new(false),
            on_resolve: RwLock::new(None),
        }
    }

    pub fn protocol(&self) -> Protocol {
        self.protocol
    }

    pub fn resolver(&self) -> Option<&Arc<dyn Resolver>> {
        self.resolver.as_ref()
    }

    /// Install a callback that short-circuits the resolver. Returns `Some(Ok|Err)`
    /// to answer, or `None` to fall through to the resolver.
    pub async fn set_on_resolve<F>(&self, callback: F)
    where
        F: Fn(&[u8]) -> Option<Result<ResolverResult, ResolverError>> + Send + Sync + 'static,
    {
        *self.on_resolve.write().await = Some(Arc::new(callback));
    }

    pub async fn set_skip_validation(&self, skip: bool) {
        *self.skip_validation.write().await = skip;
    }

    pub async fn set_cache_ttl(&self, seconds: u64) {
        *self.cache_ttl.write().await = Duration::from_secs(seconds);
    }

    /// Route a request payload to a validated backend endpoint.
    pub async fn route(&self, data: &[u8]) -> Result<ConnectionResult, ResolverError> {
        let cache_ttl = *self.cache_ttl.read().await;

        if !cache_ttl.is_zero() {
            if let Some(entry) = self.cache.get(data).await {
                if entry.inserted_at.elapsed() < cache_ttl {
                    let mut metadata = HashMap::new();
                    metadata.insert("cached".to_string(), "true".to_string());
                    return Ok(ConnectionResult::new(
                        entry.endpoint,
                        self.protocol,
                        metadata,
                    ));
                }
            }
        }

        let result = if let Some(callback) = self.on_resolve.read().await.clone() {
            match callback(data) {
                Some(r) => r?,
                None => self.resolve_via_resolver(data).await?,
            }
        } else {
            self.resolve_via_resolver(data).await?
        };

        if result.endpoint.is_empty() {
            return Err(ResolverError::not_found(format!(
                "Resolver returned empty endpoint for: {}",
                String::from_utf8_lossy(data)
            )));
        }

        let endpoint = if *self.skip_validation.read().await {
            result.endpoint.clone()
        } else {
            self.validate(&result.endpoint).await?
        };

        if !cache_ttl.is_zero() {
            self.cache
                .insert(
                    data.to_vec(),
                    CacheEntry {
                        endpoint: endpoint.clone(),
                        inserted_at: Instant::now(),
                    },
                )
                .await;
        }

        let mut metadata = result.metadata;
        metadata.insert("cached".to_string(), "false".to_string());

        Ok(ConnectionResult::new(endpoint, self.protocol, metadata))
    }

    async fn resolve_via_resolver(&self, data: &[u8]) -> Result<ResolverResult, ResolverError> {
        match &self.resolver {
            Some(r) => r.resolve(data).await,
            None => Err(ResolverError::not_found(
                "No resolver or resolve callback configured",
            )),
        }
    }

    /// Validate endpoint against SSRF rules. Returns the endpoint with hostname
    /// replaced by resolved IP to prevent DNS rebinding (TOCTOU).
    pub async fn validate(&self, endpoint: &str) -> Result<String, ResolverError> {
        let parts: Vec<&str> = endpoint.splitn(3, ':').collect();
        if parts.len() > 2 {
            return Err(ResolverError::internal(format!(
                "Invalid endpoint format: {endpoint}"
            )));
        }

        let host = parts[0];
        let has_port = parts.len() == 2 && !parts[1].is_empty();
        let port: u16 = if has_port {
            parts[1].parse().map_err(|_| {
                ResolverError::internal(format!("Invalid port number: {}", parts[1]))
            })?
        } else {
            0
        };

        if has_port && !Range::integer(1, 65535).is_valid(&json!(port)) {
            return Err(ResolverError::internal(format!(
                "Invalid port number: {port}"
            )));
        }

        let resolved = dns::resolve(host, Self::DNS_TIMEOUT).await;
        if resolved == host && !Ip::new().is_valid(&json!(resolved)) {
            return Err(ResolverError::internal(format!(
                "Cannot resolve hostname: {host}"
            )));
        }
        let ip: IpAddr = resolved
            .parse()
            .map_err(|_| ResolverError::internal(format!("Cannot resolve hostname: {host}")))?;

        if Ip::v4().is_valid(&json!(ip.to_string()))
            && is_blocked_ipv4(match ip {
                IpAddr::V4(v) => v,
                IpAddr::V6(_) => unreachable!(),
            })
        {
            return Err(ResolverError::forbidden(format!(
                "Access to private/reserved IP address is forbidden: {ip}"
            )));
        } else if Ip::v6().is_valid(&json!(ip.to_string()))
            && is_blocked_ipv6(match ip {
                IpAddr::V6(v) => v,
                IpAddr::V4(_) => unreachable!(),
            })
        {
            return Err(ResolverError::forbidden(format!(
                "Access to private/reserved IPv6 address is forbidden: {ip}"
            )));
        }

        Ok(if has_port {
            format!("{ip}:{port}")
        } else {
            ip.to_string()
        })
    }

    /// Parse "host:port" with a default port fallback. Mirrors `Adapter::parseEndpoint`.
    pub fn parse_endpoint(endpoint: &str, default_port: u16) -> (String, u16) {
        match endpoint.split_once(':') {
            Some((host, port_str)) if !port_str.is_empty() => {
                let port = port_str.parse().unwrap_or(default_port);
                (host.to_string(), port)
            }
            Some((host, _)) => (host.to_string(), default_port),
            None => (endpoint.to_string(), default_port),
        }
    }
}

/// Check whether an IP falls into any blocked SSRF range. Mirrors PHP `Adapter::validate`.
pub fn is_blocked_ip(ip: IpAddr) -> bool {
    match ip {
        IpAddr::V4(v4) => is_blocked_ipv4(v4),
        IpAddr::V6(v6) => is_blocked_ipv6(v6),
    }
}

fn is_blocked_ipv4(ip: Ipv4Addr) -> bool {
    let octets = ip.octets();
    let a = octets[0];
    let b = octets[1];

    // 10.0.0.0/8
    if a == 10 {
        return true;
    }
    // 172.16.0.0/12
    if a == 172 && (16..=31).contains(&b) {
        return true;
    }
    // 192.168.0.0/16
    if a == 192 && b == 168 {
        return true;
    }
    // 127.0.0.0/8
    if a == 127 {
        return true;
    }
    // 169.254.0.0/16
    if a == 169 && b == 254 {
        return true;
    }
    // 224.0.0.0/4 (224..=239) and 240.0.0.0/4 (240..=255)
    if a >= 224 {
        return true;
    }
    // 0.0.0.0/8
    if a == 0 {
        return true;
    }
    false
}

fn is_blocked_ipv6(ip: Ipv6Addr) -> bool {
    // ::1
    if ip.is_loopback() {
        return true;
    }
    let segments = ip.segments();
    // fe80::/10 - first 10 bits 1111 1110 10
    if (segments[0] & 0xffc0) == 0xfe80 {
        return true;
    }
    // fc00::/7 - first 7 bits 1111 110
    if (segments[0] & 0xfe00) == 0xfc00 {
        return true;
    }
    // ::ffff:0:0/96 - IPv4-mapped
    if segments[0] == 0
        && segments[1] == 0
        && segments[2] == 0
        && segments[3] == 0
        && segments[4] == 0
        && segments[5] == 0xffff
    {
        return true;
    }
    false
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn blocks_private_ipv4() {
        assert!(is_blocked_ip("10.0.0.1".parse().unwrap()));
        assert!(is_blocked_ip("172.16.5.5".parse().unwrap()));
        assert!(is_blocked_ip("192.168.1.1".parse().unwrap()));
        assert!(is_blocked_ip("127.0.0.1".parse().unwrap()));
        assert!(is_blocked_ip("169.254.1.1".parse().unwrap()));
        assert!(is_blocked_ip("224.0.0.1".parse().unwrap()));
        assert!(is_blocked_ip("240.0.0.1".parse().unwrap()));
        assert!(is_blocked_ip("0.0.0.0".parse().unwrap()));
    }

    #[test]
    fn allows_public_ipv4() {
        assert!(!is_blocked_ip("8.8.8.8".parse().unwrap()));
        assert!(!is_blocked_ip("1.1.1.1".parse().unwrap()));
        assert!(!is_blocked_ip("172.15.0.1".parse().unwrap()));
        assert!(!is_blocked_ip("172.32.0.1".parse().unwrap()));
    }

    #[test]
    fn blocks_private_ipv6() {
        assert!(is_blocked_ip("::1".parse().unwrap()));
        assert!(is_blocked_ip("fe80::1".parse().unwrap()));
        assert!(is_blocked_ip("fc00::1".parse().unwrap()));
        assert!(is_blocked_ip("fd00::1".parse().unwrap()));
        assert!(is_blocked_ip("::ffff:0:0".parse().unwrap()));
    }

    #[test]
    fn parse_endpoint_with_port() {
        let (h, p) = Adapter::parse_endpoint("example.com:1234", 80);
        assert_eq!(h, "example.com");
        assert_eq!(p, 1234);
    }

    #[test]
    fn parse_endpoint_without_port() {
        let (h, p) = Adapter::parse_endpoint("example.com", 80);
        assert_eq!(h, "example.com");
        assert_eq!(p, 80);
    }

    #[test]
    fn parse_endpoint_trailing_colon_uses_default() {
        let (h, p) = Adapter::parse_endpoint("example.com:", 80);
        assert_eq!(h, "example.com");
        assert_eq!(p, 80);
    }
}
