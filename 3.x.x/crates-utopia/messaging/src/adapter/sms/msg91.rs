//! PHP `Utopia\Messaging\Adapter\SMS\Msg91` and `MetadataParameter`.

use serde_json::{json, Map, Value};

use super::TYPE;
use crate::adapter::{expect_sms, Adapter, AdapterBase, SendResult};
use crate::error::MessagingError;
use crate::message::{Message, MessageKind};
use crate::messages::SMS;
use crate::php::ltrim_plus;
use crate::response::{Response, ResponseData};

/// PHP `Adapter\SMS\Msg91\MetadataParameter`.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum MetadataParameter {
    /// PHP `CLIENT_ID = 'clientId'`.
    ClientId,
    /// PHP `CRQID = 'CRQID'`.
    Crqid,
    /// PHP `UUID = 'UUID'`.
    Uuid,
}

impl MetadataParameter {
    /// PHP enum `value`.
    #[must_use]
    pub const fn as_str(self) -> &'static str {
        match self {
            Self::ClientId => "clientId",
            Self::Crqid => "CRQID",
            Self::Uuid => "UUID",
        }
    }

    /// All cases (PHP `MetadataParameter::cases()`).
    #[must_use]
    pub const fn cases() -> [Self; 3] {
        [Self::ClientId, Self::Crqid, Self::Uuid]
    }
}

/// Validate Msg91 CRQID/UUID metadata (PHP Msg91 + GEOSMS).
pub(crate) fn validate_tracking_metadata(
    metadata: &Map<String, Value>,
) -> Result<(), MessagingError> {
    for key in [
        MetadataParameter::Crqid.as_str(),
        MetadataParameter::Uuid.as_str(),
    ] {
        let Some(value) = metadata.get(key) else {
            continue;
        };
        let Some(text) = value.as_str() else {
            return Err(MessagingError::invalid_argument(format!(
                "Msg91 {key} metadata must be a string."
            )));
        };
        if text.len() > 80 || !tracking_id_ok(text) {
            return Err(MessagingError::invalid_argument(format!(
                "Msg91 {key} metadata must be 80 characters or less and contain only alphanumeric characters, underscores, dots, or hyphens."
            )));
        }
    }
    Ok(())
}

fn tracking_id_ok(value: &str) -> bool {
    !value.is_empty()
        && value
            .chars()
            .all(|c| c.is_ascii_alphanumeric() || c == '_' || c == '.' || c == '-')
}

/// PHP `Adapter\SMS\Msg91`.
#[derive(Debug)]
pub struct Msg91 {
    base: AdapterBase,
    sender_id: String,
    auth_key: String,
    template_id: String,
}

impl Msg91 {
    /// PHP `__construct($senderId, $authKey, $templateId)`.
    #[must_use]
    pub fn new(
        sender_id: impl Into<String>,
        auth_key: impl Into<String>,
        template_id: impl Into<String>,
    ) -> Self {
        Self {
            base: AdapterBase::default(),
            sender_id: sender_id.into(),
            auth_key: auth_key.into(),
            template_id: template_id.into(),
        }
    }

    fn process_sms(&self, message: &SMS) -> Result<ResponseData, MessagingError> {
        let allowed: Vec<&str> = MetadataParameter::cases()
            .iter()
            .map(|p| p.as_str())
            .collect();
        let mut metadata = Map::new();
        if let Some(raw) = message.get_metadata() {
            for (key, value) in raw {
                if allowed.contains(&key.as_str()) {
                    metadata.insert(key.clone(), value.clone());
                }
            }
        }
        for (key, value) in &metadata {
            if !value.is_string() {
                return Err(MessagingError::invalid_argument(format!(
                    "Msg91 {key} metadata must be a string."
                )));
            }
        }
        validate_tracking_metadata(&metadata)?;

        let recipients: Vec<Value> = message
            .get_to()
            .iter()
            .map(|recipient| {
                json!({
                    "mobiles": ltrim_plus(recipient),
                    "content": message.get_content(),
                    "otp": message.get_content(),
                })
            })
            .collect();

        let mut body = json!({
            "sender": self.sender_id,
            "template_id": self.template_id,
            "recipients": recipients,
        });
        if let Some(obj) = body.as_object_mut() {
            for (key, value) in metadata {
                obj.insert(key, value);
            }
        }

        let result = self.request_default(
            "POST",
            "https://api.msg91.com/api/v5/flow/",
            &[
                "Content-Type: application/json".into(),
                format!("Authkey: {}", self.auth_key),
            ],
            Some(body),
        );
        let mut response = Response::new(TYPE);
        if result.status_code == 200 {
            response.set_delivered_to(message.get_to().len() as i64);
            for to in message.get_to() {
                response.add_result(to, "");
            }
        } else {
            for to in message.get_to() {
                response.add_result(to, "Unknown error");
            }
        }
        Ok(response.to_array())
    }
}

impl Adapter for Msg91 {
    fn get_name(&self) -> &'static str {
        "Msg91"
    }
    fn get_type(&self) -> &'static str {
        TYPE
    }
    fn get_message_type(&self) -> MessageKind {
        MessageKind::SMS
    }
    fn get_max_messages_per_request(&self) -> usize {
        100
    }
    fn base(&self) -> &AdapterBase {
        &self.base
    }
    fn process(&self, message: &dyn Message) -> Result<SendResult, MessagingError> {
        Ok(SendResult::Response(
            self.process_sms(expect_sms(message)?)?,
        ))
    }
}
