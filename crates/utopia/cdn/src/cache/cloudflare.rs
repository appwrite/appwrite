//! Cloudflare's purge API.

use php_std::types::Value;
use utopia_client::Adapter as Client;

use crate::api::{self, DefaultClient, Reply};
use crate::cache::Adapter;
use crate::{BoxFuture, Error, domain};

/// Purges a Cloudflare zone through its `purge_cache` endpoint: URLs,
/// hostnames, cache tags, or everything.
///
/// ```no_run
/// use utopia_cdn::cache::{Adapter, Cloudflare};
///
/// # async fn run() -> Result<(), utopia_cdn::Error> {
/// let cloudflare = Cloudflare::new("zone-id", "api-token");
/// cloudflare.purge_keys(&["product-42".into()]).await?;
/// # Ok(())
/// # }
/// ```
///
/// ```php
/// use Utopia\Cdn\Cache\Adapter\Cloudflare;
///
/// $cloudflare = new Cloudflare('zone-id', 'api-token');
/// // $cloudflare->purgeKeys(['product-42']);
/// ```
///
/// PHP: `Utopia\Cdn\Cache\Adapter\Cloudflare`.
#[derive(Debug)]
pub struct Cloudflare<C = DefaultClient> {
    zone_id: String,
    api_token: String,
    client: C,
    api_base: String,
}

impl Cloudflare {
    /// URLs per purge request. Cloudflare documents 100 per request (500 on
    /// Enterprise); 30 is within every plan.
    ///
    /// PHP: `Cloudflare::PATHS_PER_PURGE`.
    pub const PATHS_PER_PURGE: usize = 30;

    /// Cache tags per purge request.
    ///
    /// PHP: `Cloudflare::KEYS_PER_PURGE`.
    pub const KEYS_PER_PURGE: usize = 30;

    /// An adapter for a zone, sending with the [`DefaultClient`] to
    /// `https://api.cloudflare.com/client/v4`.
    ///
    /// PHP: `new Cloudflare($zoneId, $apiToken)`.
    pub fn new(zone_id: impl Into<String>, api_token: impl Into<String>) -> Self {
        Cloudflare {
            zone_id: zone_id.into(),
            api_token: api_token.into(),
            client: api::default_client(),
            api_base: "https://api.cloudflare.com/client/v4".into(),
        }
    }
}

impl<C: Client> Cloudflare<C> {
    /// The same adapter sending with another client.
    ///
    /// PHP: the constructor's `$client`.
    pub fn with_client<D: Client>(self, client: D) -> Cloudflare<D> {
        Cloudflare { zone_id: self.zone_id, api_token: self.api_token, client, api_base: self.api_base }
    }

    /// The same adapter calling another API base URL.
    ///
    /// PHP: the constructor's `$apiBase`.
    pub fn with_api_base(mut self, api_base: impl Into<String>) -> Self {
        self.api_base = api_base.into();
        self
    }

    async fn send(&self, body: Value) -> Result<(), Error> {
        let url = format!("{}/zones/{}/purge_cache", self.api_base, self.zone_id);
        let authorization = format!("Bearer {}", self.api_token);
        let reply = api::send(
            &self.client,
            "POST",
            &url,
            Some(&body),
            &[
                ("User-Agent", "Utopia CDN Cloudflare Adapter"),
                ("Authorization", &authorization),
                ("Content-Type", "application/json"),
            ],
        )
        .await?;
        if !is_success(&reply) {
            return Err(Error::Runtime(format_error(&reply)));
        }
        Ok(())
    }

    async fn paths(&self, domain: &str, paths: &[String]) -> Result<(), Error> {
        let domain = domain::validate(domain)?;
        domain::validate_paths(paths)?;
        for chunk in paths.chunks(Cloudflare::PATHS_PER_PURGE) {
            let urls = chunk.iter().map(|path| api::text(&format!("https://{domain}{path}"))).collect();
            self.send(api::object([("files", api::list(urls))])).await?;
        }
        Ok(())
    }

    async fn keys(&self, keys: &[String]) -> Result<(), Error> {
        // Cache tags only match responses the origin tagged with a Cache-Tag header.
        for chunk in keys.chunks(Cloudflare::KEYS_PER_PURGE) {
            let tags = chunk.iter().map(|key| api::text(key)).collect();
            self.send(api::object([("tags", api::list(tags))])).await?;
        }
        Ok(())
    }
}

/// The status line decides; an explicit `success: false` in the envelope
/// still rejects a 2xx, but a 2xx without the envelope is acceptance.
fn is_success(reply: &Reply) -> bool {
    if !reply.is_2xx() {
        return false;
    }
    match reply.array() {
        Some(Value::Array(body)) if body.get_str("success").is_some() => {
            body.get_str("success") == Some(&Value::Bool(true))
        }
        _ => true,
    }
}

fn format_error(reply: &Reply) -> String {
    let message = reply.error.clone().or_else(|| {
        let body = reply.array()?;
        let first = api::get(body, "errors").and_then(|e| api::at(e, 0));
        first.and_then(|e| api::get(e, "message")).map(api::php_string)
    });
    format!(
        "Cloudflare purge failed with status {}: {}",
        reply.status,
        message.as_deref().unwrap_or("Unknown purge error")
    )
}

impl<C: Client> Adapter for Cloudflare<C> {
    /// Purges `https://<domain><path>` for each path, 30 URLs a request.
    fn purge_paths<'a>(&'a self, domain: &'a str, paths: &'a [String]) -> BoxFuture<'a, Result<(), Error>> {
        Box::pin(self.paths(domain, paths))
    }

    /// Purges every response cached for the hostname, and nothing cached
    /// for another.
    fn purge_domain<'a>(&'a self, domain: &'a str) -> BoxFuture<'a, Result<(), Error>> {
        Box::pin(async move {
            let domain = domain::validate(domain)?;
            self.send(api::object([("hosts", api::list(vec![api::text(&domain)]))])).await
        })
    }

    /// Purges these cache tags, 30 a request.
    fn purge_keys<'a>(&'a self, keys: &'a [String]) -> BoxFuture<'a, Result<(), Error>> {
        Box::pin(self.keys(keys))
    }

    /// Purges every response cached in the zone.
    fn purge_zone<'a>(&'a self) -> BoxFuture<'a, Result<(), Error>> {
        Box::pin(self.send(api::object([("purge_everything", Value::Bool(true))])))
    }
}
