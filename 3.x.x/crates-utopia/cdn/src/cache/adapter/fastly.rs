//! PHP `Utopia\Cdn\Cache\Adapter\Fastly`.

use std::sync::Arc;

use serde_json::{json, Value};

use super::Adapter;
use crate::http::{default_client, push_percent, send, HttpClient, RequestResult};
use crate::{CdnError, Domain, UnsupportedOperation};

/// Fastly cache-purge adapter.
pub struct Fastly {
    api_token: String,
    domain_key_prefix: String,
    service_id: Option<String>,
    soft_purge: bool,
    api_base: String,
    client: Arc<dyn HttpClient>,
}

impl std::fmt::Debug for Fastly {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Fastly")
            .field("domain_key_prefix", &self.domain_key_prefix)
            .field("service_id", &self.service_id)
            .field("soft_purge", &self.soft_purge)
            .field("api_base", &self.api_base)
            .finish_non_exhaustive()
    }
}

impl Fastly {
    /// Fastly's documented ceiling for one batch surrogate-key purge.
    pub const KEYS_PER_PURGE: usize = 256;

    #[must_use]
    pub fn new(api_token: impl Into<String>, domain_key_prefix: impl Into<String>) -> Self {
        Self {
            api_token: api_token.into(),
            domain_key_prefix: domain_key_prefix.into(),
            service_id: None,
            soft_purge: false,
            api_base: "https://api.fastly.com".into(),
            client: Arc::new(default_client()),
        }
    }

    #[must_use]
    pub fn with_service_id(mut self, service_id: impl Into<String>) -> Self {
        let id = service_id.into();
        self.service_id = if id.is_empty() { None } else { Some(id) };
        self
    }

    #[must_use]
    pub fn with_soft_purge(mut self, soft_purge: bool) -> Self {
        self.soft_purge = soft_purge;
        self
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

    fn require_service_id(&self, operation: &str) -> Result<&str, CdnError> {
        match self.service_id.as_deref() {
            Some(id) if !id.is_empty() => Ok(id),
            _ => Err(UnsupportedOperation(format!(
                "Fastly service ID is required for {operation}."
            ))
            .into()),
        }
    }

    /// PHP `encodePath`: percent-encode bytes outside Fastly's allowed set.
    fn encode_path(path: &str) -> String {
        let mut out = String::new();
        for ch in path.chars() {
            if matches!(
                ch,
                'A'..='Z'
                    | 'a'..='z'
                    | '0'..='9'
                    | '-'
                    | '.'
                    | '_'
                    | '~'
                    | '/'
                    | '%'
                    | '?'
                    | '='
                    | '&'
                    | ':'
                    | '+'
            ) {
                out.push(ch);
            } else {
                for byte in ch.encode_utf8(&mut [0; 4]).bytes() {
                    push_percent(&mut out, byte);
                }
            }
        }
        out
    }

    fn send_req(&self, method: &str, path: &str, body: Option<Value>) -> Result<(), CdnError> {
        let result = self.request(method, path, body.as_ref());
        if result.status < 200 || result.status >= 300 {
            return Err(CdnError::runtime(format_error(&result)));
        }
        Ok(())
    }

    fn request(&self, method: &str, path: &str, body: Option<&Value>) -> RequestResult {
        let mut headers = vec![
            ("User-Agent", "Utopia CDN Fastly Adapter".to_owned()),
            ("Fastly-Key", self.api_token.clone()),
            ("Accept", "application/json".to_owned()),
        ];
        if self.soft_purge {
            headers.push(("Fastly-Soft-Purge", "1".to_owned()));
        }
        if body.is_some() {
            headers.push(("Content-Type", "application/json".to_owned()));
        }
        send(
            self.client.as_ref(),
            method,
            &format!("{}{path}", self.api_base),
            &headers,
            body,
        )
    }
}

fn format_error(result: &RequestResult) -> String {
    let message = result
        .error
        .clone()
        .or_else(|| {
            result
                .response
                .get("msg")
                .or_else(|| result.response.get("detail"))
                .and_then(Value::as_str)
                .map(str::to_owned)
        })
        .unwrap_or_else(|| "Unknown purge error".into());
    format!(
        "Fastly purge failed with status {}: {message}",
        result.status
    )
}

impl Adapter for Fastly {
    fn purge_paths(&self, domain: &str, paths: &[String]) -> Result<(), CdnError> {
        let domain = Domain::validate(domain)?;
        let paths = Domain::validate_paths(paths)?;
        if paths.is_empty() {
            return Ok(());
        }
        for path in paths {
            self.send_req(
                "POST",
                &format!("/purge/{domain}{}", Self::encode_path(&path)),
                None,
            )?;
        }
        Ok(())
    }

    fn purge_domain(&self, domain: &str) -> Result<(), CdnError> {
        let domain = Domain::validate(domain)?;
        self.purge_keys(&[format!("{}{domain}", self.domain_key_prefix)])
    }

    fn purge_keys(&self, keys: &[String]) -> Result<(), CdnError> {
        if keys.is_empty() {
            return Ok(());
        }
        let service = self.require_service_id("cache key purging")?;
        for chunk in keys.chunks(Self::KEYS_PER_PURGE) {
            self.send_req(
                "POST",
                &format!("/service/{service}/purge"),
                Some(json!({ "surrogate_keys": chunk })),
            )?;
        }
        Ok(())
    }

    fn purge_zone(&self) -> Result<(), CdnError> {
        let service = self.require_service_id("zone purging")?;
        self.send_req("POST", &format!("/service/{service}/purge_all"), None)
    }
}
