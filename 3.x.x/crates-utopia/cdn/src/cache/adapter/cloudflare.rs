//! PHP `Utopia\Cdn\Cache\Adapter\Cloudflare`.

use std::sync::Arc;

use serde_json::{json, Value};

use super::Adapter;
use crate::http::{default_client, send, HttpClient, RequestResult};
use crate::{CdnError, Domain};

/// Cloudflare cache-purge adapter.
pub struct Cloudflare {
    zone_id: String,
    api_token: String,
    api_base: String,
    client: Arc<dyn HttpClient>,
}

impl std::fmt::Debug for Cloudflare {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Cloudflare")
            .field("zone_id", &self.zone_id)
            .field("api_base", &self.api_base)
            .finish_non_exhaustive()
    }
}

impl Cloudflare {
    /// URLs per purge request. PHP `PATHS_PER_PURGE`.
    pub const PATHS_PER_PURGE: usize = 30;
    /// Cache tags per purge request. PHP `KEYS_PER_PURGE`.
    pub const KEYS_PER_PURGE: usize = 30;

    #[must_use]
    pub fn new(zone_id: impl Into<String>, api_token: impl Into<String>) -> Self {
        Self {
            zone_id: zone_id.into(),
            api_token: api_token.into(),
            api_base: "https://api.cloudflare.com/client/v4".into(),
            client: Arc::new(default_client()),
        }
    }

    #[must_use]
    pub fn with_client(mut self, client: Arc<dyn HttpClient>) -> Self {
        self.client = client;
        self
    }

    #[must_use]
    pub fn with_api_base(mut self, api_base: impl Into<String>) -> Self {
        self.api_base = api_base.into();
        self
    }

    fn send_body(&self, body: Value) -> Result<(), CdnError> {
        let result = self.request(
            "POST",
            &format!("/zones/{}/purge_cache", self.zone_id),
            Some(&body),
        );
        if !is_success(&result) {
            return Err(CdnError::runtime(format_error(&result)));
        }
        Ok(())
    }

    fn request(&self, method: &str, path: &str, body: Option<&Value>) -> RequestResult {
        let headers = [
            ("User-Agent", "Utopia CDN Cloudflare Adapter".to_owned()),
            ("Authorization", format!("Bearer {}", self.api_token)),
            ("Content-Type", "application/json".to_owned()),
        ];
        send(
            self.client.as_ref(),
            method,
            &format!("{}{path}", self.api_base),
            &headers,
            body,
        )
    }
}

fn is_success(result: &RequestResult) -> bool {
    result.status >= 200
        && result.status < 300
        && result.response.is_object()
        && result.response.get("success") == Some(&Value::Bool(true))
}

fn format_error(result: &RequestResult) -> String {
    let message = result
        .error
        .clone()
        .or_else(|| {
            result
                .response
                .get("errors")
                .and_then(Value::as_array)
                .and_then(|errors| errors.first())
                .and_then(|error| error.get("message"))
                .and_then(Value::as_str)
                .map(str::to_owned)
        })
        .unwrap_or_else(|| "Unknown purge error".into());
    format!(
        "Cloudflare purge failed with status {}: {message}",
        result.status
    )
}

impl Adapter for Cloudflare {
    fn purge_paths(&self, domain: &str, paths: &[String]) -> Result<(), CdnError> {
        let domain = Domain::validate(domain)?;
        let paths = Domain::validate_paths(paths)?;
        if paths.is_empty() {
            return Ok(());
        }
        for chunk in paths.chunks(Self::PATHS_PER_PURGE) {
            let urls: Vec<String> = chunk
                .iter()
                .map(|path| format!("https://{domain}{path}"))
                .collect();
            self.send_body(json!({ "files": urls }))?;
        }
        Ok(())
    }

    fn purge_domain(&self, domain: &str) -> Result<(), CdnError> {
        self.send_body(json!({ "hosts": [Domain::validate(domain)?] }))
    }

    fn purge_keys(&self, keys: &[String]) -> Result<(), CdnError> {
        if keys.is_empty() {
            return Ok(());
        }
        for chunk in keys.chunks(Self::KEYS_PER_PURGE) {
            self.send_body(json!({ "tags": chunk }))?;
        }
        Ok(())
    }

    fn purge_zone(&self) -> Result<(), CdnError> {
        self.send_body(json!({ "purge_everything": true }))
    }
}
