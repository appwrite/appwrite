//! Audits (PHP: `app/controllers/shared/api.php`, the `api` group's
//! shutdown hook that enqueues `$queueForAudits`; hooks.json `audits`).

use bytes::Bytes;
use serde_json::Value;

use super::Job;
use crate::platform::context::Mode;

impl Job {
    pub(super) async fn audit(&self) {
        let Some(audit) = self.route.audit else { return };
        if self.state.config.edition == "self-hosted" {
            return;
        }
        let resource = render_template(audit.resource, &self.params, &self.payload);
        if resource.is_empty() {
            return;
        }
        let message = serde_json::json!({
            "project": self.project.message_payload(),
            "user": self.actor,
            "impersonatorUser": [],
            // PHP no longer sends the response (`audits.payload` is gone).
            "payload": [],
            "resource": resource,
            "mode": if self.mode == Mode::Admin { "admin" } else { "default" },
            "ip": self.request.ip,
            "userAgent": self.request.user_agent,
            "event": audit.event,
            "hostname": self.request.hostname,
            "sdk": self.request.sdk,
            "sdkVersion": self.request.sdk_version,
        });
        self.publish(&self.state.config.queue_audits, &message).await;
    }
}

/// Renders audit resource templates (`user/{request.userId}`, `user/{response.$id}`).
fn render_template(template: &str, params: &[(String, String)], payload: &Bytes) -> String {
    let mut out = template.to_owned();
    if out.contains("{response.") {
        let response: Value = serde_json::from_slice(payload).unwrap_or(Value::Null);
        while let Some(start) = out.find("{response.") {
            let Some(end) = out[start..].find('}') else { break };
            let key = &out[start + 10..start + end];
            let value = response.get(key).and_then(Value::as_str).unwrap_or("").to_owned();
            out.replace_range(start..start + end + 1, &value);
        }
    }
    while let Some(start) = out.find("{request.") {
        let Some(end) = out[start..].find('}') else { break };
        let key = &out[start + 9..start + end];
        let value = params.iter().find(|(k, _)| k == key).map(|(_, v)| v.clone()).unwrap_or_default();
        out.replace_range(start..start + end + 1, &value);
    }
    out
}
