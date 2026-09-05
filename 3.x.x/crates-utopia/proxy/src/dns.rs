//! Coroutine-aware DNS resolver with per-worker TTL cache. Mirrors `src/Dns.php`.

use std::net::IpAddr;
use std::str::FromStr;
use std::sync::atomic::{AtomicU64, Ordering};
use std::time::Duration;

use hickory_resolver::config::{ResolverConfig, ResolverOpts};
use hickory_resolver::TokioAsyncResolver;
use moka::future::Cache;
use once_cell::sync::Lazy;
use tokio::time::timeout;

#[derive(Debug, thiserror::Error)]
pub enum DnsError {
    #[error("hostname not resolvable: {0}")]
    NotResolvable(String),
    #[error("dns lookup timed out")]
    Timeout,
    #[error("dns resolver not initialised: {0}")]
    ResolverInit(String),
}

static TTL_SECONDS: AtomicU64 = AtomicU64::new(60);

static CACHE: Lazy<Cache<String, IpAddr>> = Lazy::new(|| {
    Cache::builder()
        .max_capacity(10_000)
        .time_to_live(Duration::from_secs(TTL_SECONDS.load(Ordering::Relaxed)))
        .build()
});

static RESOLVER: Lazy<Result<TokioAsyncResolver, String>> = Lazy::new(|| {
    TokioAsyncResolver::tokio_from_system_conf()
        .map_err(|e| e.to_string())
        .or_else(|_| {
            Ok(TokioAsyncResolver::tokio(
                ResolverConfig::default(),
                ResolverOpts::default(),
            ))
        })
});

/// Set DNS TTL in seconds. Takes effect for future cache entries.
pub fn set_ttl(seconds: u64) {
    TTL_SECONDS.store(seconds, Ordering::Relaxed);
}

/// Current configured DNS TTL in seconds.
pub fn ttl() -> u64 {
    TTL_SECONDS.load(Ordering::Relaxed)
}

/// Clear the DNS cache.
pub async fn clear() {
    CACHE.invalidate_all();
    CACHE.run_pending_tasks().await;
}

/// Resolve a hostname to an IP address. Literal IPs are returned as-is. On failure,
/// returns the original hostname back as a string (caller validates). Successful
/// lookups are cached; failures are not.
///
/// This matches the PHP `Dns::resolve()` contract - returns the input unchanged
/// for literal IPs, returns the resolved IP for successful DNS lookups, and
/// returns the input unchanged on resolver failure.
pub async fn resolve(host: &str, lookup_timeout: Duration) -> String {
    if host.is_empty() {
        return host.to_string();
    }

    if IpAddr::from_str(host).is_ok() {
        return host.to_string();
    }

    if let Some(ip) = CACHE.get(host).await {
        return ip.to_string();
    }

    let Ok(resolver) = RESOLVER.as_ref() else {
        return host.to_string();
    };

    let lookup = resolver.lookup_ip(host);
    let result = match timeout(lookup_timeout, lookup).await {
        Ok(Ok(r)) => r,
        _ => return host.to_string(),
    };

    let Some(ip) = result
        .iter()
        .find(|ip| ip.is_ipv4())
        .or_else(|| result.iter().next())
    else {
        return host.to_string();
    };

    CACHE.insert(host.to_string(), ip).await;
    ip.to_string()
}

/// Strict variant that returns `Err` for resolution failures instead of echoing the input.
pub async fn resolve_strict(host: &str, lookup_timeout: Duration) -> Result<IpAddr, DnsError> {
    if host.is_empty() {
        return Err(DnsError::NotResolvable(host.to_string()));
    }

    if let Ok(ip) = IpAddr::from_str(host) {
        return Ok(ip);
    }

    if let Some(ip) = CACHE.get(host).await {
        return Ok(ip);
    }

    let resolver = RESOLVER
        .as_ref()
        .map_err(|e| DnsError::ResolverInit(e.clone()))?;

    let lookup = resolver.lookup_ip(host);
    let result = match timeout(lookup_timeout, lookup).await {
        Ok(Ok(r)) => r,
        Ok(Err(_)) => return Err(DnsError::NotResolvable(host.to_string())),
        Err(_) => return Err(DnsError::Timeout),
    };

    let ip = result
        .iter()
        .find(|ip| ip.is_ipv4())
        .or_else(|| result.iter().next())
        .ok_or_else(|| DnsError::NotResolvable(host.to_string()))?;

    CACHE.insert(host.to_string(), ip).await;
    Ok(ip)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[tokio::test]
    async fn literal_ipv4_bypasses_lookup() {
        let out = resolve("127.0.0.1", Duration::from_secs(1)).await;
        assert_eq!(out, "127.0.0.1");
    }

    #[tokio::test]
    async fn literal_ipv6_bypasses_lookup() {
        let out = resolve("::1", Duration::from_secs(1)).await;
        assert_eq!(out, "::1");
    }

    #[test]
    fn ttl_roundtrip() {
        let original = ttl();
        set_ttl(123);
        assert_eq!(ttl(), 123);
        set_ttl(original);
    }
}
