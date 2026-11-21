//! Fastly's purge API.

use php_std::types::Value;
use utopia_client::Adapter as Client;

use crate::api::{self, DefaultClient, Reply};
use crate::cache::Adapter;
use crate::{BoxFuture, Error, domain};

/// Purges a Fastly service: single URLs, surrogate keys, or everything.
///
/// Fastly cannot purge by host, so a domain is addressed by the surrogate
/// key the origin attaches to every response it serves for it: the
/// `domain_key_prefix` followed by the hostname (pass `""` when the key is
/// the bare hostname). Key, domain and zone purges need a service ID;
/// without one they fail with [`Error::UnsupportedOperation`], while URL
/// purges still work.
///
/// ```no_run
/// use utopia_cdn::cache::{Adapter, Fastly};
///
/// # async fn run() -> Result<(), utopia_cdn::Error> {
/// let fastly = Fastly::new("api-token", "domain-").with_service_id("service-id").with_soft_purge(true);
/// fastly.purge_domain("example.com").await?; // purges the key `domain-example.com`
/// # Ok(())
/// # }
/// ```
///
/// ```php
/// use Utopia\Cdn\Cache\Adapter\Fastly;
///
/// $fastly = new Fastly('api-token', 'domain-', 'service-id', softPurge: true);
/// // $fastly->purgeDomain('example.com');
/// ```
///
/// PHP: `Utopia\Cdn\Cache\Adapter\Fastly`.
#[derive(Debug)]
pub struct Fastly<C = DefaultClient> {
    api_token: String,
    domain_key_prefix: String,
    service_id: Option<String>,
    soft_purge: bool,
    client: C,
    api_base: String,
}

impl Fastly {
    /// Fastly's documented ceiling for one batch surrogate key purge.
    ///
    /// PHP: `Fastly::KEYS_PER_PURGE`.
    pub const KEYS_PER_PURGE: usize = 256;

    /// An adapter without a service ID, purging hard, sending with the
    /// [`DefaultClient`] to `https://api.fastly.com`.
    ///
    /// PHP: `new Fastly($apiToken, $domainKeyPrefix)`.
    pub fn new(api_token: impl Into<String>, domain_key_prefix: impl Into<String>) -> Self {
        Fastly {
            api_token: api_token.into(),
            domain_key_prefix: domain_key_prefix.into(),
            service_id: None,
            soft_purge: false,
            client: api::default_client(),
            api_base: "https://api.fastly.com".into(),
        }
    }
}

impl<C: Client> Fastly<C> {
    /// The service whose keys, domains and whole cache can be purged.
    ///
    /// PHP: the constructor's `$serviceId`.
    pub fn with_service_id(mut self, service_id: impl Into<String>) -> Self {
        self.service_id = Some(service_id.into());
        self
    }

    /// Whether purges mark content stale (`Fastly-Soft-Purge: 1`) instead of
    /// removing it.
    ///
    /// PHP: the constructor's `$softPurge`.
    pub fn with_soft_purge(mut self, soft_purge: bool) -> Self {
        self.soft_purge = soft_purge;
        self
    }

    /// The same adapter sending with another client.
    ///
    /// PHP: the constructor's `$client`.
    pub fn with_client<D: Client>(self, client: D) -> Fastly<D> {
        Fastly {
            api_token: self.api_token,
            domain_key_prefix: self.domain_key_prefix,
            service_id: self.service_id,
            soft_purge: self.soft_purge,
            client,
            api_base: self.api_base,
        }
    }

    /// The same adapter calling another API base URL.
    ///
    /// PHP: the constructor's `$apiBase`.
    pub fn with_api_base(mut self, api_base: impl Into<String>) -> Self {
        self.api_base = api_base.into();
        self
    }

    fn service(&self, operation: &str) -> Result<&str, Error> {
        match self.service_id.as_deref() {
            Some(id) if !id.is_empty() => Ok(id),
            _ => Err(Error::UnsupportedOperation(format!("Fastly service ID is required for {operation}."))),
        }
    }

    async fn send(&self, url: &str, body: Option<Value>) -> Result<(), Error> {
        let url = format!("{}{url}", self.api_base);
        let mut headers = vec![
            ("User-Agent", "Utopia CDN Fastly Adapter"),
            ("Fastly-Key", self.api_token.as_str()),
            ("Accept", "application/json"),
        ];
        if self.soft_purge {
            headers.push(("Fastly-Soft-Purge", "1"));
        }
        let reply = api::send(&self.client, "POST", &url, body.as_ref(), &headers).await?;
        if !reply.is_2xx() {
            return Err(Error::Runtime(format_error(&reply)));
        }
        Ok(())
    }

    async fn paths(&self, domain: &str, paths: &[String]) -> Result<(), Error> {
        let domain = domain::validate(domain)?;
        domain::validate_paths(paths)?;
        // A URL purge carries one URL, so there is nothing to batch.
        for path in paths {
            self.send(&format!("/purge/{domain}{}", encode_path(path)), None).await?;
        }
        Ok(())
    }

    async fn keys(&self, keys: &[String]) -> Result<(), Error> {
        if keys.is_empty() {
            return Ok(());
        }
        let service = self.service("cache key purging")?;
        // Keys travel in the body, so they are sent as given: percent-encoding
        // one would purge a key the origin never attached.
        for chunk in keys.chunks(Fastly::KEYS_PER_PURGE) {
            let keys = chunk.iter().map(|key| api::text(key)).collect();
            self.send(&format!("/service/{service}/purge"), Some(api::object([("surrogate_keys", api::list(keys))])))
                .await?;
        }
        Ok(())
    }

    async fn domain(&self, domain: &str) -> Result<(), Error> {
        let key = format!("{}{}", self.domain_key_prefix, domain::validate(domain)?);
        self.keys(std::slice::from_ref(&key)).await
    }

    async fn zone(&self) -> Result<(), Error> {
        let service = self.service("zone purging")?;
        self.send(&format!("/service/{service}/purge_all"), None).await
    }
}

/// Percent-encodes every character of a path but `A-Za-z0-9-._~/%?=&:+`,
/// byte by byte.
///
/// PHP: `preg_replace_callback('/[^A-Za-z0-9\-._~\/%?=&:+]/u', rawurlencode)`.
fn encode_path(path: &str) -> String {
    let mut out = String::with_capacity(path.len());
    let mut buf = [0u8; 4];
    for c in path.chars() {
        if c.is_ascii_alphanumeric() || "-._~/%?=&:+".contains(c) {
            out.push(c);
        } else {
            out.push_str(&php_std::encoding::rawurlencode(c.encode_utf8(&mut buf).as_bytes()));
        }
    }
    out
}

fn format_error(reply: &Reply) -> String {
    let message = reply.error.clone().or_else(|| {
        let body = reply.array()?;
        api::get(body, "msg").or_else(|| api::get(body, "detail")).map(api::php_string)
    });
    format!("Fastly purge failed with status {}: {}", reply.status, message.as_deref().unwrap_or("Unknown purge error"))
}

impl<C: Client> Adapter for Fastly<C> {
    /// Purges each URL `<domain><path>` in its own request.
    fn purge_paths<'a>(&'a self, domain: &'a str, paths: &'a [String]) -> BoxFuture<'a, Result<(), Error>> {
        Box::pin(self.paths(domain, paths))
    }

    /// Purges the domain's surrogate key, leaving every other domain on the
    /// service cached.
    fn purge_domain<'a>(&'a self, domain: &'a str) -> BoxFuture<'a, Result<(), Error>> {
        Box::pin(self.domain(domain))
    }

    /// Purges these surrogate keys, 256 a request.
    fn purge_keys<'a>(&'a self, keys: &'a [String]) -> BoxFuture<'a, Result<(), Error>> {
        Box::pin(self.keys(keys))
    }

    /// Purges every object on the service (`purge_all`), which Fastly
    /// documents as slow and incompatible with soft purges.
    fn purge_zone<'a>(&'a self) -> BoxFuture<'a, Result<(), Error>> {
        Box::pin(self.zone())
    }
}
