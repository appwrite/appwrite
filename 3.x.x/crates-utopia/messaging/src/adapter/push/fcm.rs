//! PHP `Utopia\Messaging\Adapter\Push\FCM`.

use serde_json::{json, Value};

use super::{EXPIRED_MESSAGE, TYPE};
use crate::adapter::{expect_push, Adapter, AdapterBase, SendResult};
use crate::error::MessagingError;
use crate::helpers::JWT;
use crate::http::MultiResult;
use crate::message::{Message, MessageKind};
use crate::messages::Push;
use crate::priority::Priority;
use crate::response::{Response, ResponseData};

const DEFAULT_EXPIRY_SECONDS: i64 = 3600;
const DEFAULT_SKEW_SECONDS: i64 = 60;
const GOOGLE_TOKEN_URL: &str = "https://www.googleapis.com/oauth2/v4/token";

/// PHP `Adapter\Push\FCM`.
#[derive(Debug)]
pub struct FCM {
    base: AdapterBase,
    service_account_json: String,
}

impl FCM {
    /// PHP `__construct($serviceAccountJSON)`.
    #[must_use]
    pub fn new(service_account_json: impl Into<String>) -> Self {
        Self {
            base: AdapterBase::default(),
            service_account_json: service_account_json.into(),
        }
    }

    fn process_push(&self, message: &Push) -> Result<ResponseData, MessagingError> {
        let credentials: Value = serde_json::from_str(&self.service_account_json)
            .map_err(|e| MessagingError::message(e.to_string()))?;
        let now = unix_now();
        let signing_key = credentials
            .get("private_key")
            .and_then(Value::as_str)
            .unwrap_or("");
        let payload = json!({
            "iss": credentials.get("client_email").and_then(Value::as_str).unwrap_or(""),
            "exp": now + DEFAULT_EXPIRY_SECONDS,
            "iat": now - DEFAULT_SKEW_SECONDS,
            "scope": "https://www.googleapis.com/auth/firebase.messaging",
            "aud": GOOGLE_TOKEN_URL,
        });
        let jwt = JWT::encode(&payload, signing_key, "RS256", None)?;
        let token = self.request_default(
            "POST",
            GOOGLE_TOKEN_URL,
            &["Content-Type: application/x-www-form-urlencoded".into()],
            Some(json!({
                "grant_type": "urn:ietf:params:oauth:grant-type:jwt-bearer",
                "assertion": jwt,
            })),
        );
        if token.status_code != 200
            || !token.response.is_object()
            || token.response.get("access_token").is_none()
        {
            let err = if token.error.is_empty() {
                format!("HTTP {}", token.status_code)
            } else {
                token.error.clone()
            };
            return Err(MessagingError::message(format!(
                "Failed to obtain FCM access token: {err}"
            )));
        }
        let access_token = token
            .response
            .get("access_token")
            .and_then(Value::as_str)
            .unwrap_or("");

        let mut shared = json!({});
        if let Some(title) = message.get_title() {
            shared["message"]["notification"]["title"] = json!(title);
        }
        if let Some(body) = message.get_body() {
            shared["message"]["notification"]["body"] = json!(body);
        }
        if let Some(data) = message.get_data() {
            shared["message"]["data"] = Value::Object(data.clone());
        }
        if let Some(action) = message.get_action() {
            shared["message"]["android"]["notification"]["click_action"] = json!(action);
            shared["message"]["apns"]["payload"]["aps"]["category"] = json!(action);
        }
        if let Some(image) = message.get_image() {
            shared["message"]["android"]["notification"]["image"] = json!(image);
            shared["message"]["apns"]["payload"]["aps"]["mutable-content"] = json!(1);
            shared["message"]["apns"]["fcm_options"]["image"] = json!(image);
        }
        if message.get_critical().is_some() {
            shared["message"]["apns"]["payload"]["aps"]["sound"]["critical"] = json!(1);
        }
        if let Some(sound) = message.get_sound() {
            shared["message"]["android"]["notification"]["sound"] = json!(sound);
            if message.get_critical().is_some() {
                shared["message"]["apns"]["payload"]["aps"]["sound"]["name"] = json!(sound);
            } else {
                shared["message"]["apns"]["payload"]["aps"]["sound"] = json!(sound);
            }
        }
        if let Some(icon) = message.get_icon() {
            shared["message"]["android"]["notification"]["icon"] = json!(icon);
        }
        if let Some(color) = message.get_color() {
            shared["message"]["android"]["notification"]["color"] = json!(color);
        }
        if let Some(tag) = message.get_tag() {
            shared["message"]["android"]["notification"]["tag"] = json!(tag);
        }
        if let Some(badge) = message.get_badge() {
            shared["message"]["apns"]["payload"]["aps"]["badge"] = json!(badge);
        }
        if let Some(available) = message.get_content_available() {
            shared["message"]["apns"]["payload"]["aps"]["content-available"] =
                json!(i32::from(available));
        }
        if let Some(priority) = message.get_priority() {
            shared["message"]["android"]["priority"] = json!(match priority {
                Priority::High => "high",
                Priority::Normal => "normal",
            });
            shared["message"]["apns"]["headers"]["apns-priority"] = json!(match priority {
                Priority::High => "10",
                Priority::Normal => "5",
            });
        }

        let bodies: Vec<Value> = message
            .get_to()
            .iter()
            .map(|to| {
                let mut body = shared.clone();
                body["message"]["token"] = json!(to);
                body
            })
            .collect();

        let project_id = credentials
            .get("project_id")
            .and_then(Value::as_str)
            .unwrap_or("");
        let results = self.request_multi(
            "POST",
            &[format!(
                "https://fcm.googleapis.com/v1/projects/{project_id}/messages:send"
            )],
            &[
                "Content-Type: application/json".into(),
                format!("Authorization: Bearer {access_token}"),
            ],
            &bodies,
            30,
            10,
        )?;

        let mut response = Response::new(TYPE);
        for item in results {
            if item.result.status_code == 200 {
                response.increment_delivered_to();
                response.add_result(
                    message
                        .get_to()
                        .get(item.index)
                        .cloned()
                        .unwrap_or_default(),
                    "",
                );
            } else {
                response.add_result(
                    message
                        .get_to()
                        .get(item.index)
                        .cloned()
                        .unwrap_or_default(),
                    get_error(&item),
                );
            }
        }
        Ok(response.to_array())
    }
}

fn get_error(result: &MultiResult) -> String {
    let response = if result.result.response.is_object() {
        &result.result.response
    } else {
        return fallback(result);
    };
    let error = response.get("error");
    let status = error.and_then(|e| e.get("status")).and_then(Value::as_str);
    if status == Some("UNREGISTERED") || status == Some("NOT_FOUND") {
        return EXPIRED_MESSAGE.to_string();
    }
    if let Some(msg) = error.and_then(|e| e.get("message")).and_then(Value::as_str) {
        if !msg.is_empty() {
            return msg.to_string();
        }
    }
    fallback(result)
}

fn fallback(result: &MultiResult) -> String {
    let details = format!(
        "HTTP status {}; cURL error code {}",
        result.result.status_code, result.result.error_code
    );
    if result.result.error.is_empty() {
        format!("Request failed ({details})")
    } else {
        format!("{} ({details})", result.result.error)
    }
}

fn unix_now() -> i64 {
    std::time::SystemTime::now()
        .duration_since(std::time::UNIX_EPOCH)
        .map(|d| d.as_secs() as i64)
        .unwrap_or(0)
}

impl Adapter for FCM {
    fn get_name(&self) -> &'static str {
        "FCM"
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
