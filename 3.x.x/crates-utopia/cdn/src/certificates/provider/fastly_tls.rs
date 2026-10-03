//! PHP `Utopia\Cdn\Certificates\Provider\FastlyTls`.

use std::sync::Arc;

use chrono::{DateTime, Duration, Utc};
use serde_json::{json, Value};

use super::Provider;
use crate::http::{default_client, php_http_build_query, send, HttpClient, RequestResult};
use crate::{CdnError, Status};

/// Fastly TLS subscription certificates.
pub struct FastlyTls {
    api_token: String,
    tls_configuration_id: String,
    certificate_authority: String,
    api_base: String,
    client: Arc<dyn HttpClient>,
}

impl std::fmt::Debug for FastlyTls {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("FastlyTls")
            .field("tls_configuration_id", &self.tls_configuration_id)
            .field("certificate_authority", &self.certificate_authority)
            .field("api_base", &self.api_base)
            .finish_non_exhaustive()
    }
}

struct Subscription {
    resource: Value,
    included: Vec<Value>,
}

impl FastlyTls {
    #[must_use]
    pub fn new(api_token: impl Into<String>, tls_configuration_id: impl Into<String>) -> Self {
        Self {
            api_token: api_token.into(),
            tls_configuration_id: tls_configuration_id.into(),
            certificate_authority: "certainly".into(),
            api_base: "https://api.fastly.com".into(),
            client: Arc::new(default_client()),
        }
    }

    #[must_use]
    pub fn with_authority(mut self, authority: impl Into<String>) -> Self {
        self.certificate_authority = authority.into();
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

    fn request(&self, method: &str, path: &str, body: Option<&Value>) -> RequestResult {
        let headers = [
            ("User-Agent", "Utopia CDN Fastly TLS Provider".to_owned()),
            ("Fastly-Key", self.api_token.clone()),
            ("Accept", "application/vnd.api+json".to_owned()),
            ("Content-Type", "application/vnd.api+json".to_owned()),
        ];
        send(
            self.client.as_ref(),
            method,
            &format!("{}{path}", self.api_base),
            &headers,
            body,
        )
    }

    fn format_error(prefix: &str, result: &RequestResult) -> String {
        let message = result
            .error
            .clone()
            .or_else(|| {
                result
                    .response
                    .get("errors")
                    .and_then(Value::as_array)
                    .and_then(|errors| errors.first())
                    .and_then(|error| error.get("detail").or_else(|| error.get("title")))
                    .and_then(Value::as_str)
                    .map(str::to_owned)
                    .or_else(|| {
                        result
                            .response
                            .get("msg")
                            .and_then(Value::as_str)
                            .map(str::to_owned)
                    })
            })
            .unwrap_or_else(|| "Unknown Fastly TLS error".into());
        format!("{prefix} with status {}: {message}", result.status)
    }

    fn map_status(state: &str) -> &'static str {
        match state.to_ascii_lowercase().as_str() {
            "pending" => Status::PENDING,
            "processing" => Status::PROCESSING,
            "issued" => Status::ISSUED,
            "renewing" => Status::RENEWING,
            "failed" => Status::FAILED,
            _ => Status::UNKNOWN,
        }
    }

    fn find_subscription(&self, domain: &str) -> Result<Option<Subscription>, CdnError> {
        let query = php_http_build_query(&[
            ("filter[tls_domains.id]", domain),
            ("include", "tls_certificates"),
            ("page[size]", "1"),
        ]);
        let result = self.request("GET", &format!("/tls/subscriptions?{query}"), None);
        if result.status < 200 || result.status >= 300 {
            return Err(CdnError::runtime(Self::format_error(
                "Failed to fetch Fastly TLS subscriptions",
                &result,
            )));
        }
        if !result.response.is_object() {
            return Err(CdnError::runtime(
                "Fastly TLS subscriptions response was not valid JSON.",
            ));
        }
        let Some(data) = result.response.get("data").and_then(Value::as_array) else {
            return Err(CdnError::runtime(
                "Fastly TLS subscriptions response was missing its data list.",
            ));
        };
        let Some(resource) = data.first() else {
            return Ok(None);
        };
        if !resource.is_object() {
            return Err(CdnError::runtime(
                "Fastly TLS subscription resource was malformed.",
            ));
        }
        let included = match result.response.get("included") {
            None => Vec::new(),
            Some(Value::Array(items)) => items
                .iter()
                .filter(|value| value.is_object())
                .cloned()
                .collect(),
            Some(_) => {
                return Err(CdnError::runtime(
                    "Fastly TLS subscriptions response contained malformed included resources.",
                ));
            }
        };
        Ok(Some(Subscription {
            resource: resource.clone(),
            included,
        }))
    }

    fn create_subscription(&self, domain: &str) -> Result<Subscription, CdnError> {
        let body = json!({
            "data": {
                "type": "tls_subscription",
                "attributes": {"certificate_authority": self.certificate_authority},
                "relationships": {
                    "common_name": {"data": {"type": "tls_domain", "id": domain}},
                    "tls_configuration": {"data": {"type": "tls_configuration", "id": self.tls_configuration_id}},
                    "tls_domains": {"data": [{"type": "tls_domain", "id": domain}]},
                }
            }
        });
        let result = self.request("POST", "/tls/subscriptions", Some(&body));
        if result.status < 200 || result.status >= 300 {
            return Err(CdnError::runtime(Self::format_error(
                "Failed to create Fastly TLS subscription",
                &result,
            )));
        }
        parse_single(&result, "Fastly TLS subscription")
    }

    fn retry_subscription(&self, subscription_id: &str) -> Result<Subscription, CdnError> {
        let body = json!({
            "data": {
                "id": subscription_id,
                "type": "tls_subscription",
                "attributes": {"state": "retry"},
            }
        });
        let result = self.request(
            "PATCH",
            &format!("/tls/subscriptions/{subscription_id}"),
            Some(&body),
        );
        if result.status < 200 || result.status >= 300 {
            return Err(CdnError::runtime(Self::format_error(
                "Failed to retry Fastly TLS subscription",
                &result,
            )));
        }
        parse_single(&result, "Fastly TLS retry")
    }

    fn extract_renew_date(subscription: &Subscription) -> Option<String> {
        let state = subscription
            .resource
            .pointer("/attributes/state")
            .and_then(Value::as_str)
            .unwrap_or("");
        let mapped = Self::map_status(state);
        if mapped != Status::ISSUED && mapped != Status::RENEWING {
            return None;
        }
        let mut certificate_ids = Vec::new();
        if let Some(Value::Array(refs)) = subscription
            .resource
            .pointer("/relationships/tls_certificates/data")
        {
            for reference in refs {
                if let Some(id) = reference.get("id").and_then(Value::as_str) {
                    certificate_ids.push(id.to_owned());
                }
            }
        }
        let mut dates = Vec::new();
        for included in &subscription.included {
            if included.get("type").and_then(Value::as_str) != Some("tls_certificate") {
                continue;
            }
            let id = included.get("id").and_then(Value::as_str).unwrap_or("");
            if !certificate_ids.iter().any(|cert| cert == id) {
                continue;
            }
            if let Some(not_after) = included
                .pointer("/attributes/not_after")
                .and_then(Value::as_str)
            {
                if !not_after.is_empty() {
                    dates.push(not_after.to_owned());
                }
            }
        }
        if dates.is_empty() {
            return None;
        }
        dates.sort_by_key(|right| std::cmp::Reverse(parse_ts(right)));
        let parsed = DateTime::parse_from_rfc3339(&dates[0])
            .or_else(|_| DateTime::parse_from_str(&dates[0], "%Y-%m-%dT%H:%M:%SZ"))
            .ok()?
            .with_timezone(&Utc);
        let renew = parsed - Duration::days(30);
        Some(renew.format("%Y-%m-%d %H:%M:%S%.3f").to_string())
    }
}

fn parse_ts(value: &str) -> i64 {
    DateTime::parse_from_rfc3339(value)
        .ok()
        .map_or(0, |date| date.timestamp())
}

fn parse_single(result: &RequestResult, label: &str) -> Result<Subscription, CdnError> {
    if !result.response.is_object() {
        return Err(CdnError::runtime(format!(
            "{label} response was not valid JSON."
        )));
    }
    let data = result
        .response
        .get("data")
        .cloned()
        .ok_or_else(|| CdnError::runtime(format!("{label} response was missing data.")))?;
    if !data.is_object() {
        return Err(CdnError::runtime(format!(
            "{label} response was missing data."
        )));
    }
    let included = result
        .response
        .get("included")
        .and_then(Value::as_array)
        .map(|items| {
            items
                .iter()
                .filter(|value| value.is_object())
                .cloned()
                .collect()
        })
        .unwrap_or_default();
    Ok(Subscription {
        resource: data,
        included,
    })
}

impl Provider for FastlyTls {
    fn issue_certificate(
        &self,
        _cert_name: &str,
        domain: &str,
        _domain_type: Option<&str>,
    ) -> Result<Option<String>, CdnError> {
        let subscription = match self.find_subscription(domain)? {
            None => self.create_subscription(domain)?,
            Some(existing) => {
                let state = existing
                    .resource
                    .pointer("/attributes/state")
                    .and_then(Value::as_str)
                    .unwrap_or("");
                if Self::map_status(state) == Status::FAILED {
                    let id = existing
                        .resource
                        .get("id")
                        .and_then(Value::as_str)
                        .unwrap_or("");
                    self.retry_subscription(id)?
                } else {
                    existing
                }
            }
        };
        Ok(Self::extract_renew_date(&subscription))
    }

    fn is_instant_generation(
        &self,
        _domain: &str,
        _domain_type: Option<&str>,
    ) -> Result<bool, CdnError> {
        Ok(false)
    }

    fn get_certificate_status(
        &self,
        domain: &str,
        _domain_type: Option<&str>,
    ) -> Result<String, CdnError> {
        match self.find_subscription(domain)? {
            None => Ok(Status::UNKNOWN.to_owned()),
            Some(sub) => {
                let state = sub
                    .resource
                    .pointer("/attributes/state")
                    .and_then(Value::as_str)
                    .unwrap_or("");
                Ok(Self::map_status(state).to_owned())
            }
        }
    }

    fn is_renew_required(
        &self,
        domain: &str,
        _domain_type: Option<&str>,
    ) -> Result<bool, CdnError> {
        match self.find_subscription(domain)? {
            None => Ok(true),
            Some(sub) => {
                let state = sub
                    .resource
                    .pointer("/attributes/state")
                    .and_then(Value::as_str)
                    .unwrap_or("");
                Ok(Self::map_status(state) == Status::FAILED)
            }
        }
    }

    fn delete_certificate(&self, domain: &str, _domain_type: Option<&str>) -> Result<(), CdnError> {
        let Some(subscription) = self.find_subscription(domain)? else {
            return Ok(());
        };
        let id = subscription
            .resource
            .get("id")
            .and_then(Value::as_str)
            .unwrap_or("");
        let result = self.request("DELETE", &format!("/tls/subscriptions/{id}"), None);
        if result.status < 200 || result.status >= 300 {
            return Err(CdnError::runtime(Self::format_error(
                "Failed to delete Fastly TLS subscription",
                &result,
            )));
        }
        Ok(())
    }
}
