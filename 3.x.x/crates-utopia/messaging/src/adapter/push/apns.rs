//! PHP `Utopia\Messaging\Adapter\Push\APNS`.

use serde_json::{json, Value};

use super::{EXPIRED_MESSAGE, TYPE};
use crate::adapter::{expect_push, Adapter, AdapterBase, SendResult};
use crate::error::MessagingError;
use crate::helpers::JWT;
use crate::message::{Message, MessageKind};
use crate::messages::Push;
use crate::priority::Priority;
use crate::response::{Response, ResponseData};

/// PHP `Adapter\Push\APNS`.
#[derive(Debug)]
pub struct APNS {
    base: AdapterBase,
    auth_key: String,
    auth_key_id: String,
    team_id: String,
    bundle_id: String,
    sandbox: bool,
}

impl APNS {
    /// PHP `__construct($authKey, $authKeyId, $teamId, $bundleId, $sandbox = false)`.
    #[must_use]
    pub fn new(
        auth_key: impl Into<String>,
        auth_key_id: impl Into<String>,
        team_id: impl Into<String>,
        bundle_id: impl Into<String>,
        sandbox: bool,
    ) -> Self {
        Self {
            base: AdapterBase::default(),
            auth_key: auth_key.into(),
            auth_key_id: auth_key_id.into(),
            team_id: team_id.into(),
            bundle_id: bundle_id.into(),
            sandbox,
        }
    }

    fn process_push(&self, message: &Push) -> Result<ResponseData, MessagingError> {
        let mut payload = json!({});
        if let Some(title) = message.get_title() {
            payload["aps"]["alert"]["title"] = json!(title);
        }
        if let Some(body) = message.get_body() {
            payload["aps"]["alert"]["body"] = json!(body);
        }
        if let Some(data) = message.get_data() {
            payload["aps"]["data"] = Value::Object(data.clone());
        }
        if let Some(action) = message.get_action() {
            payload["aps"]["category"] = json!(action);
        }
        if message.get_critical().is_some() {
            payload["aps"]["sound"]["critical"] = json!(1);
            payload["aps"]["sound"]["name"] = json!("default");
            payload["aps"]["sound"]["volume"] = json!(1.0);
        }
        if let Some(sound) = message.get_sound() {
            if message.get_critical().is_some() {
                payload["aps"]["sound"]["name"] = json!(sound);
            } else {
                payload["aps"]["sound"] = json!(sound);
            }
        }
        if let Some(badge) = message.get_badge() {
            payload["aps"]["badge"] = json!(badge);
        }
        if let Some(available) = message.get_content_available() {
            payload["aps"]["content-available"] = json!(i32::from(available));
        }
        if let Some(priority) = message.get_priority() {
            payload["headers"]["apns-priority"] = json!(match priority {
                Priority::High => "10",
                Priority::Normal => "5",
            });
        }

        let now = unix_now();
        let claims = json!({
            "iss": self.team_id,
            "iat": now,
            "exp": now + 3600,
        });
        let jwt = JWT::encode(&claims, &self.auth_key, "ES256", Some(&self.auth_key_id))?;

        let endpoint = if self.sandbox {
            "https://api.development.push.apple.com"
        } else {
            "https://api.push.apple.com"
        };
        let urls: Vec<String> = message
            .get_to()
            .iter()
            .map(|token| format!("{endpoint}/3/device/{token}"))
            .collect();

        let results = self.request_multi(
            "POST",
            &urls,
            &[
                "Content-Type: application/json".into(),
                format!("Authorization: Bearer {jwt}"),
                format!("apns-topic: {}", self.bundle_id),
                "apns-push-type: alert".into(),
            ],
            &[payload],
            30,
            10,
        )?;

        let mut response = Response::new(TYPE);
        for item in results {
            let device = item.result.url.rsplit('/').next().unwrap_or("").to_string();
            if item.result.status_code == 200 {
                response.increment_delivered_to();
                response.add_result(device, "");
            } else {
                let reason = item.result.response.get("reason").and_then(Value::as_str);
                let error = if reason == Some("ExpiredToken") || reason == Some("BadDeviceToken") {
                    EXPIRED_MESSAGE.to_string()
                } else {
                    reason
                        .map(str::to_owned)
                        .filter(|s| !s.is_empty())
                        .or_else(|| {
                            if item.result.error.is_empty() {
                                None
                            } else {
                                Some(item.result.error.clone())
                            }
                        })
                        .unwrap_or_else(|| "Unknown error".into())
                };
                response.add_result(device, error);
            }
        }
        Ok(response.to_array())
    }
}

fn unix_now() -> i64 {
    std::time::SystemTime::now()
        .duration_since(std::time::UNIX_EPOCH)
        .map(|d| d.as_secs() as i64)
        .unwrap_or(0)
}

impl Adapter for APNS {
    fn get_name(&self) -> &'static str {
        "APNS"
    }
    fn get_type(&self) -> &'static str {
        TYPE
    }
    fn get_message_type(&self) -> MessageKind {
        MessageKind::Push
    }
    fn get_max_messages_per_request(&self) -> usize {
        5000
    }
    fn base(&self) -> &AdapterBase {
        &self.base
    }
    fn process(&self, message: &dyn Message) -> Result<SendResult, MessagingError> {
        Ok(SendResult::Response(
            self.process_push(expect_push(message)?)?,
        ))
    }
}
