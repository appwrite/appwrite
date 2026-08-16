//! PHP `Utopia\Messaging\Adapter\SMS\GEOSMS`.

use std::collections::HashMap;
use std::sync::Arc;

use parking_lot::Mutex;
use serde_json::Value;
use utopia_telemetry::adapters::NoneAdapter;
use utopia_telemetry::Adapter as TelemetryAdapter;

mod calling_code;
use super::msg91::{validate_tracking_metadata, MetadataParameter};
use super::TYPE;
use crate::adapter::{expect_sms, Adapter, AdapterBase, GroupedSend, SendResult};
use crate::error::MessagingError;
use crate::http::ClientFactory;
use crate::message::{Message, MessageKind};
use crate::messages::SMS;
use crate::php::php_empty_str;
pub use calling_code::CallingCode;

/// PHP `Adapter\SMS\GEOSMS`.
pub struct GEOSMS {
    base: AdapterBase,
    default_adapter: Arc<dyn Adapter>,
    local_adapters: Mutex<HashMap<String, Arc<dyn Adapter>>>,
    telemetry: Mutex<Arc<dyn TelemetryAdapter>>,
}

impl std::fmt::Debug for GEOSMS {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("GEOSMS")
            .field("default", &self.default_adapter.get_name())
            .finish_non_exhaustive()
    }
}

impl GEOSMS {
    /// PHP `__construct(SMSAdapter $defaultAdapter)`.
    #[must_use]
    pub fn new(default_adapter: Arc<dyn Adapter>) -> Self {
        let telemetry: Arc<dyn TelemetryAdapter> = Arc::new(NoneAdapter::new());
        let base = AdapterBase::new(Some(Arc::clone(&telemetry)), None);
        default_adapter.set_telemetry(Arc::clone(&telemetry));
        Self {
            base,
            default_adapter,
            local_adapters: Mutex::new(HashMap::new()),
            telemetry: Mutex::new(telemetry),
        }
    }

    /// PHP `setLocal($callingCode, $adapter)`.
    pub fn set_local(&self, calling_code: impl Into<String>, adapter: Arc<dyn Adapter>) -> &Self {
        adapter.set_telemetry(Arc::clone(&self.telemetry.lock()));
        self.local_adapters
            .lock()
            .insert(calling_code.into(), adapter);
        self
    }

    /// PHP `filterCallingCodesByAdapter`.
    #[must_use]
    pub fn filter_calling_codes_by_adapter(&self, adapter: &dyn Adapter) -> Vec<String> {
        let name = adapter.get_name();
        self.local_adapters
            .lock()
            .iter()
            .filter(|(_, local)| local.get_name() == name)
            .map(|(code, _)| code.clone())
            .collect()
    }

    fn get_adapter_by_phone_number(&self, phone: &str) -> Arc<dyn Adapter> {
        let calling_code = CallingCode::from_phone_number(phone);
        let code = calling_code.as_deref().unwrap_or("");
        if php_empty_str(code) {
            return Arc::clone(&self.default_adapter);
        }
        self.local_adapters
            .lock()
            .get(code)
            .cloned()
            .unwrap_or_else(|| Arc::clone(&self.default_adapter))
    }

    fn get_next_recipients_and_adapter(
        &self,
        recipients: &[String],
    ) -> (Vec<String>, Arc<dyn Adapter>) {
        let mut next_recipients = Vec::new();
        let mut next_adapter: Option<Arc<dyn Adapter>> = None;
        for recipient in recipients {
            let adapter = self.get_adapter_by_phone_number(recipient);
            match &next_adapter {
                None => {
                    next_adapter = Some(adapter);
                    next_recipients.push(recipient.clone());
                }
                Some(current) if Arc::ptr_eq(current, &adapter) => {
                    next_recipients.push(recipient.clone());
                }
                Some(_) => {}
            }
        }
        (
            next_recipients,
            next_adapter.unwrap_or_else(|| Arc::clone(&self.default_adapter)),
        )
    }

    fn process_sms(&self, message: &SMS) -> Result<SendResult, MessagingError> {
        let mut results = HashMap::new();
        let mut remaining: Vec<String> = message.get_to().to_vec();
        let mut batches: Vec<(Vec<String>, Arc<dyn Adapter>)> = Vec::new();

        while !remaining.is_empty() {
            let (next, adapter) = self.get_next_recipients_and_adapter(&remaining);
            remaining.retain(|r| !next.contains(r));
            batches.push((next, adapter));
        }

        for (index, (recipients, adapter)) in batches.iter().enumerate() {
            let mut metadata = message.get_metadata().cloned();
            if batches.len() > 1 {
                if let Some(meta) = metadata.as_mut() {
                    for key in [
                        MetadataParameter::Crqid.as_str(),
                        MetadataParameter::Uuid.as_str(),
                    ] {
                        if !meta.contains_key(key) {
                            continue;
                        }
                        if !meta[key].is_string() {
                            return Err(MessagingError::invalid_argument(format!(
                                "Msg91 {key} metadata must be a string."
                            )));
                        }
                    }
                    validate_tracking_metadata(meta)?;
                    for key in [
                        MetadataParameter::Crqid.as_str(),
                        MetadataParameter::Uuid.as_str(),
                    ] {
                        if let Some(Value::String(value)) = meta.get(key).cloned() {
                            let suffix = format!("-{}", index + 1);
                            let keep = 80usize.saturating_sub(suffix.len());
                            let truncated: String = value.chars().take(keep).collect();
                            meta.insert(
                                key.to_string(),
                                Value::String(format!("{truncated}{suffix}")),
                            );
                        }
                    }
                }
            }

            let mut sms = SMS::new(
                recipients.clone(),
                message.get_content().to_string(),
                message.get_from().map(str::to_owned),
                message.get_attachments().map(|a| a.to_vec()),
                metadata,
            );
            sms.set_origin(message.get_origin().map(str::to_owned));
            match adapter.send(&sms) {
                Ok(SendResult::Response(data)) => {
                    results.insert(adapter.get_name().to_string(), GroupedSend::Response(data));
                }
                Ok(SendResult::Grouped(_)) => {
                    results.insert(
                        adapter.get_name().to_string(),
                        GroupedSend::Error {
                            type_name: "error".into(),
                            message: "nested GEOSMS is not supported".into(),
                        },
                    );
                }
                Err(error) => {
                    results.insert(
                        adapter.get_name().to_string(),
                        GroupedSend::Error {
                            type_name: "error".into(),
                            message: error.to_string(),
                        },
                    );
                }
            }
        }

        Ok(SendResult::Grouped(results))
    }
}

impl Adapter for GEOSMS {
    fn get_name(&self) -> &'static str {
        "GEOSMS"
    }

    fn get_type(&self) -> &'static str {
        TYPE
    }

    fn get_message_type(&self) -> MessageKind {
        MessageKind::SMS
    }

    fn get_max_messages_per_request(&self) -> usize {
        usize::MAX
    }

    fn base(&self) -> &AdapterBase {
        &self.base
    }

    fn set_telemetry(&self, telemetry: Arc<dyn TelemetryAdapter>) {
        *self.telemetry.lock() = Arc::clone(&telemetry);
        self.base.set_telemetry(Arc::clone(&telemetry));
        self.default_adapter.set_telemetry(Arc::clone(&telemetry));
        for adapter in self.local_adapters.lock().values() {
            adapter.set_telemetry(Arc::clone(&telemetry));
        }
    }

    fn set_client_factory(&self, factory: ClientFactory) {
        self.base.set_client_factory(factory);
    }

    fn process(&self, message: &dyn Message) -> Result<SendResult, MessagingError> {
        self.process_sms(expect_sms(message)?)
    }
}
