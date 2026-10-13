use std::sync::{Arc, RwLock};

use indexmap::IndexMap;
use php_std::types::ArrayKey;
use php_std::{Str, Value};
use utopia_telemetry::{NoTelemetry, Telemetry};

use super::gateways::tracking_id;
use super::{TYPE, calling_code, sms};
use crate::adapter::{Adapter, Base};
use crate::error::Error;
use crate::http::{BoxFuture, Http};
use crate::message::{Message, MessageType, Sms};
use crate::response::{Batch, Sent};

/// Routes each recipient to the adapter registered for its calling code,
/// the rest to a default adapter. Consecutive recipients of one adapter go
/// in one batch; the result is each adapter's response, by name.
///
/// PHP: `Utopia\Messaging\Adapter\SMS\GEOSMS`.
pub struct GeoSms {
    default: Arc<dyn Adapter>,
    locals: RwLock<IndexMap<String, Arc<dyn Adapter>>>,
    telemetry: RwLock<Arc<dyn Telemetry>>,
    base: Base,
}

impl std::fmt::Debug for GeoSms {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("GeoSms").field("default", &self.default.name()).finish_non_exhaustive()
    }
}

fn same(a: &Arc<dyn Adapter>, b: &Arc<dyn Adapter>) -> bool {
    std::ptr::eq(Arc::as_ptr(a) as *const (), Arc::as_ptr(b) as *const ())
}

impl GeoSms {
    /// Routes to `default` unless a local adapter is set for a recipient's
    /// calling code. The default adapter's telemetry is reset to none, the
    /// router's own until one is set.
    ///
    /// PHP: `new GEOSMS($defaultAdapter)`.
    pub fn new(default: Arc<dyn Adapter>) -> GeoSms {
        let telemetry: Arc<dyn Telemetry> = Arc::new(NoTelemetry);
        default.set_telemetry(telemetry.clone());
        GeoSms {
            default,
            locals: RwLock::new(IndexMap::new()),
            telemetry: RwLock::new(telemetry),
            base: Base::new(Http::new("GEOSMS")),
        }
    }

    /// Sends recipients with `calling_code` (`91`, see [`calling_code`])
    /// through `adapter`, which records on the router's telemetry.
    ///
    /// PHP: `setLocal()`.
    pub fn set_local(&self, calling_code: &str, adapter: Arc<dyn Adapter>) -> &GeoSms {
        adapter.set_telemetry(self.telemetry.read().unwrap_or_else(|e| e.into_inner()).clone());
        self.locals.write().unwrap_or_else(|e| e.into_inner()).insert(calling_code.to_owned(), adapter);
        self
    }

    /// [`GeoSms::set_local`], by value.
    pub fn with_local(self, calling_code: &str, adapter: Arc<dyn Adapter>) -> GeoSms {
        self.set_local(calling_code, adapter);
        self
    }

    fn adapter_for(&self, number: &str) -> Arc<dyn Adapter> {
        match calling_code::from_phone_number(number) {
            None | Some("") | Some("0") => self.default.clone(),
            Some(code) => self
                .locals
                .read()
                .unwrap_or_else(|e| e.into_inner())
                .get(code)
                .cloned()
                .unwrap_or_else(|| self.default.clone()),
        }
    }

    async fn route(&self, sms: &Sms) -> Result<Sent, Error> {
        let mut recipients: Vec<String> = sms.to().to_vec();
        let mut batches: Vec<(Vec<String>, Option<Arc<dyn Adapter>>)> = Vec::new();
        loop {
            let mut next: Vec<String> = Vec::new();
            let mut adapter: Option<Arc<dyn Adapter>> = None;
            for recipient in &recipients {
                let candidate = self.adapter_for(recipient);
                if adapter.as_ref().is_none_or(|a| same(a, &candidate)) {
                    adapter = Some(candidate);
                    next.push(recipient.clone());
                }
            }
            recipients.retain(|r| !next.contains(r));
            batches.push((next, adapter));
            if recipients.is_empty() {
                break;
            }
        }

        let count = batches.len();
        let mut results: IndexMap<String, Batch> = IndexMap::new();
        for (index, (to, adapter)) in batches.into_iter().enumerate() {
            let mut metadata = sms.metadata().cloned();
            if count > 1
                && let Some(Value::Array(entries)) = &mut metadata
            {
                for key in ["CRQID", "UUID"] {
                    let Some(value) = entries.get_str(key).cloned() else { continue };
                    let Value::Str(value) = value else {
                        return Err(Error::InvalidArgument(format!("Msg91 {key} metadata must be a string.")));
                    };
                    if !tracking_id(value.as_bytes()) {
                        return Err(Error::InvalidArgument(format!(
                            "Msg91 {key} metadata must be 80 characters or less and contain only alphanumeric characters, underscores, dots, or hyphens."
                        )));
                    }
                    let suffix = format!("-{}", index + 1);
                    let mut updated =
                        php_std::string::substr(value.as_bytes(), 0, Some(80 - suffix.len() as i64)).to_vec();
                    updated.extend_from_slice(suffix.as_bytes());
                    entries.set(ArrayKey::normalize(Str::from(key)), Value::Str(Str::from(updated)));
                }
            }
            let Some(adapter) = adapter else {
                return Err(Error::Fatal("Call to a member function getName() on null".into()));
            };
            let mut message =
                Sms::new(to, sms.content()).with_metadata(metadata).with_origin(sms.origin().map(str::to_owned));
            if let Some(from) = sms.from() {
                message = message.with_from(from);
            }
            if let Some(attachments) = sms.attachments() {
                message = message.with_attachments(attachments.to_vec());
            }
            let name = adapter.name().to_owned();
            let outcome = match adapter.send(&Message::Sms(message)).await {
                Ok(sent) => Batch::Sent(sent),
                Err(e) if e.is_exception() => Batch::Failed(e.message()),
                Err(e) => return Err(e),
            };
            results.insert(name, outcome);
        }
        Ok(Sent::Grouped(results))
    }
}

impl Adapter for GeoSms {
    fn name(&self) -> &str {
        "GEOSMS"
    }

    fn kind(&self) -> &str {
        TYPE
    }

    fn message_type(&self) -> MessageType {
        MessageType::Sms
    }

    fn max_messages_per_request(&self) -> i64 {
        i64::MAX
    }

    fn base(&self) -> &Base {
        &self.base
    }

    fn set_telemetry(&self, telemetry: Arc<dyn Telemetry>) {
        *self.telemetry.write().unwrap_or_else(|e| e.into_inner()) = telemetry.clone();
        self.base.set_telemetry(&*telemetry);
        self.default.set_telemetry(telemetry.clone());
        for adapter in self.locals.read().unwrap_or_else(|e| e.into_inner()).values() {
            adapter.set_telemetry(telemetry.clone());
        }
    }

    fn process<'a>(&'a self, message: &'a Message) -> BoxFuture<'a, Result<Sent, Error>> {
        Box::pin(self.route(sms(message)))
    }
}
