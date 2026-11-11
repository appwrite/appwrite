//! Usage (PHP: `app/controllers/general.php`, the shutdown hook that adds
//! the status and the missing resource to `$usage` and publishes it;
//! hooks.json `usage-status`, with the `usage-context` metadata).

use super::Job;
use crate::event::{self, Metric};

impl Job {
    pub(super) async fn usage(&self) {
        if self.project.is_console() || !self.state.config.usage_stats {
            return;
        }
        // `general.php` looks the IP up for every API request; here it runs
        // after the response, from the in-process cache when it can.
        let country = match self.state.geo.country_code(&self.request.ip).await.as_str() {
            "--" => String::new(),
            code => code.to_lowercase(),
        };
        // `$parts[1] ?? $parts[0]` of the URI's segments.
        let parts: Vec<&str> = self.request.path.trim_matches('/').split('/').collect();
        let service = parts.get(1).or(parts.first()).copied().unwrap_or("").to_owned();
        let base = |key: &str, value: i64| Metric {
            key: key.to_owned(),
            value,
            path: self.request.path.clone(),
            method: self.request.method.clone(),
            status: self.status.as_u16(),
            service: service.clone(),
            resource_type: "project".to_owned(),
            resource_id: self.project.id.clone(),
            resource_internal_id: self.project.sequence.clone(),
            resource_path: String::new(),
            team_id: String::new(),
            team_internal_id: String::new(),
            country: country.clone(),
            region: self.state.config.region.clone(),
            hostname: self.request.hostname.clone(),
            user_agent: self.request.user_agent.clone(),
            ip: self.request.ip.clone(),
            sdk: self.request.sdk.clone(),
            sdk_version: self.request.sdk_version.clone(),
            protocol: self.request.protocol.clone(),
            accept: self.request.accept.clone(),
            accept_language: self.request.accept_language.clone(),
            query_keys: String::new(),
        };
        let mut metrics: Vec<Metric> = self
            .metrics
            .iter()
            .map(|recorded| {
                let mut metric = base(&recorded.key, recorded.value);
                if let Some((kind, internal_id)) = &recorded.resource {
                    metric.resource_type = kind.clone();
                    metric.resource_internal_id = internal_id.clone();
                }
                metric
            })
            .collect();
        if !self.privileged {
            metrics.push(base("network.requests", 1));
            metrics.push(base("network.inbound", self.request.size as i64));
            metrics.push(base("network.outbound", self.response_size as i64));
        }
        if metrics.is_empty() {
            return;
        }
        // The message is still sent when the key disabled every metric.
        if !self.disabled_metrics.is_empty() {
            metrics.retain(|m| !self.disabled_metrics.iter().any(|p| m.key.ends_with(p.as_str())));
        }
        let message = event::usage_message(self.project.trimmed_payload(), &metrics);
        self.publish(&self.state.config.queue_usage, &message).await;
    }
}
