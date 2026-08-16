//! PHP `Utopia\Cdn\Certificates\Provider\Cloudflare`.

use std::sync::Arc;

use serde_json::{json, Value};

use super::Provider;
use crate::http::{
    default_client, php_http_build_query, php_rawurlencode, send, HttpClient, RequestResult,
};
use crate::{CdnError, Domain, UnsupportedOperation};

/// Cloudflare for `SaaS` custom-hostname certificates.
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

    fn hostnames_path(&self) -> String {
        format!("/zones/{}/custom_hostnames", self.zone_id)
    }

    fn request(&self, method: &str, path: &str, body: Option<&Value>) -> RequestResult {
        let headers = [
            (
                "User-Agent",
                "Utopia CDN Cloudflare Certificates Provider".to_owned(),
            ),
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

    fn is_duplicate(result: &RequestResult) -> bool {
        result
            .response
            .get("errors")
            .and_then(Value::as_array)
            .and_then(|errors| errors.first())
            .and_then(|error| error.get("code"))
            .and_then(Value::as_i64)
            == Some(1406)
    }

    fn assert_success(
        operation: &str,
        result: &RequestResult,
        expected: Option<&[u16]>,
    ) -> Result<(), CdnError> {
        let http_ok = match expected {
            Some(list) => list.contains(&result.status),
            None => (200..300).contains(&result.status),
        };
        let envelope_ok = result.response.as_object().map_or(true, |object| {
            !object.contains_key("success") || object.get("success") == Some(&Value::Bool(true))
        });
        if !http_ok || !envelope_ok {
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
                .unwrap_or_else(|| "Unknown Cloudflare error".into());
            return Err(CdnError::runtime(format!(
                "Failed to {operation} with status {}: {message}",
                result.status
            )));
        }
        Ok(())
    }

    fn find_hostname(&self, domain: &str) -> Result<Option<Value>, CdnError> {
        let query = php_http_build_query(&[("hostname", domain)]);
        let result = self.request("GET", &format!("{}?{query}", self.hostnames_path()), None);
        Self::assert_success("fetch Cloudflare custom hostnames", &result, None)?;
        if !result.response.is_object() {
            return Err(CdnError::runtime(
                "Cloudflare custom hostname response was not valid JSON.",
            ));
        }
        let Some(hostnames) = result.response.get("result").and_then(Value::as_array) else {
            return Err(CdnError::runtime(
                "Cloudflare custom hostname response was missing its result list.",
            ));
        };
        for hostname in hostnames {
            if hostname.get("hostname").and_then(Value::as_str) == Some(domain) {
                return Ok(Some(hostname.clone()));
            }
        }
        Ok(None)
    }
}

impl Provider for Cloudflare {
    fn issue_certificate(
        &self,
        _cert_name: &str,
        domain: &str,
        _domain_type: Option<&str>,
    ) -> Result<Option<String>, CdnError> {
        let domain = Domain::validate(domain)?;
        let result = self.request(
            "POST",
            &self.hostnames_path(),
            Some(&json!({
                "hostname": domain,
                "ssl": {"method": "http", "type": "dv", "wildcard": false},
            })),
        );
        if Self::is_duplicate(&result) {
            return Ok(None);
        }
        Self::assert_success("create Cloudflare custom hostname", &result, Some(&[201]))?;
        Ok(None)
    }

    fn is_instant_generation(
        &self,
        domain: &str,
        _domain_type: Option<&str>,
    ) -> Result<bool, CdnError> {
        Domain::validate(domain)?;
        Ok(true)
    }

    fn get_certificate_status(
        &self,
        _domain: &str,
        _domain_type: Option<&str>,
    ) -> Result<String, CdnError> {
        Err(UnsupportedOperation(
            "Certificate status retrieval is not supported by the Cloudflare provider.".into(),
        )
        .into())
    }

    fn is_renew_required(
        &self,
        domain: &str,
        _domain_type: Option<&str>,
    ) -> Result<bool, CdnError> {
        Ok(self.find_hostname(&Domain::validate(domain)?)?.is_none())
    }

    fn delete_certificate(&self, domain: &str, _domain_type: Option<&str>) -> Result<(), CdnError> {
        let Some(hostname) = self.find_hostname(&Domain::validate(domain)?)? else {
            return Ok(());
        };
        let id = hostname.get("id").and_then(Value::as_str).unwrap_or("");
        if id.is_empty() {
            return Err(CdnError::runtime(
                "Cloudflare custom hostname response was missing an ID.",
            ));
        }
        let encoded = php_rawurlencode(id);
        let result = self.request(
            "DELETE",
            &format!("{}/{encoded}", self.hostnames_path()),
            None,
        );
        Self::assert_success("delete Cloudflare custom hostname", &result, None)
    }
}
