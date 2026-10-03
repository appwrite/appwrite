//! PHP `Utopia\Messaging\Adapter` and `Utopia\Messaging\Adapter\*`.

//! PHP `Utopia\Messaging\Adapter` base: send validation, telemetry, HTTP.

use std::collections::HashMap;
use std::sync::Arc;

use parking_lot::Mutex;
use serde_json::Value;
use utopia_telemetry::adapters::NoneAdapter;
use utopia_telemetry::{Adapter as TelemetryAdapter, Attributes, Counter};

use crate::error::MessagingError;
use crate::http::{
    build_prepared, default_factory, run_multi, ClientFactory, HttpClient, HttpResult, MultiResult,
};
use crate::message::{Message, MessageKind};
use crate::response::ResponseData;

/// Outcome of [`Adapter::send`] (PHP `array` - standard response or GEOSMS map).
#[derive(Debug, Clone)]
pub enum SendResult {
    /// PHP `{deliveredTo, type, results}`.
    Response(ResponseData),
    /// PHP GEOSMS map keyed by adapter name.
    Grouped(HashMap<String, GroupedSend>),
}

/// One GEOSMS child result.
#[derive(Debug, Clone)]
pub enum GroupedSend {
    /// Successful nested `send()`.
    Response(ResponseData),
    /// PHP `['type' => 'error', 'message' => ...]`.
    Error {
        /// Always `"error"`.
        type_name: String,
        /// Exception message.
        message: String,
    },
}

/// Shared HTTP + telemetry state (PHP `Adapter` constructor fields).
pub struct AdapterBase {
    counter: Mutex<Arc<dyn Counter>>,
    client_factory: Mutex<ClientFactory>,
}

impl std::fmt::Debug for AdapterBase {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("AdapterBase").finish_non_exhaustive()
    }
}

impl Default for AdapterBase {
    fn default() -> Self {
        Self::new(None, None)
    }
}

impl AdapterBase {
    /// PHP `__construct(?Telemetry $telemetry = null, ?Closure $clientFactory = null)`.
    #[must_use]
    pub fn new(
        telemetry: Option<Arc<dyn TelemetryAdapter>>,
        client_factory: Option<ClientFactory>,
    ) -> Self {
        let telemetry = telemetry.unwrap_or_else(|| Arc::new(NoneAdapter::new()));
        let counter = telemetry.create_counter("messaging.send", None, None, HashMap::new());
        Self {
            counter: Mutex::new(counter),
            client_factory: Mutex::new(client_factory.unwrap_or_else(default_factory)),
        }
    }

    /// PHP `setTelemetry`.
    pub fn set_telemetry(&self, telemetry: Arc<dyn TelemetryAdapter>) {
        let counter = telemetry.create_counter("messaging.send", None, None, HashMap::new());
        *self.counter.lock() = counter;
    }

    /// Inject a client factory (PHP constructor `$clientFactory`).
    pub fn set_client_factory(&self, factory: ClientFactory) {
        *self.client_factory.lock() = factory;
    }

    /// Current client factory.
    #[must_use]
    pub fn client_factory(&self) -> ClientFactory {
        Arc::clone(&self.client_factory.lock())
    }

    /// PHP `request()`.
    #[allow(clippy::too_many_arguments)]
    pub fn request(
        &self,
        adapter_name: &str,
        method: &str,
        url: &str,
        headers: &[String],
        body: Option<Value>,
        timeout: u64,
        connect_timeout: u64,
    ) -> HttpResult {
        let prepared = build_prepared(adapter_name, method, url, headers, body);
        let factory = self.client_factory();
        let client: Arc<dyn HttpClient> = factory(timeout, connect_timeout);
        client.execute(&prepared)
    }

    /// PHP `requestMulti()`.
    #[allow(clippy::too_many_arguments)]
    pub fn request_multi(
        &self,
        adapter_name: &str,
        method: &str,
        urls: &[String],
        headers: &[String],
        bodies: &[Value],
        timeout: u64,
        connect_timeout: u64,
    ) -> Result<Vec<MultiResult>, MessagingError> {
        let factory = self.client_factory();
        run_multi(
            &factory,
            method,
            adapter_name,
            urls,
            headers,
            bodies,
            timeout,
            connect_timeout,
        )
    }

    fn record_send(
        &self,
        adapter_name: &str,
        adapter_type: &str,
        message: &dyn Message,
        recipients: usize,
        delivered: usize,
    ) {
        if delivered > 0 {
            self.counter.lock().add(
                delivered as f64,
                &telemetry_attributes(adapter_name, adapter_type, message, "success"),
            );
        }
        let failed = recipients.saturating_sub(delivered);
        if failed > 0 {
            self.counter.lock().add(
                failed as f64,
                &telemetry_attributes(adapter_name, adapter_type, message, "failure"),
            );
        }
    }

    fn record_response(
        &self,
        adapter_name: &str,
        adapter_type: &str,
        message: &dyn Message,
        response: &SendResult,
    ) {
        let results = match response {
            SendResult::Response(data) => &data.results,
            SendResult::Grouped(_) => return,
        };
        if results.is_empty() {
            return;
        }
        let delivered = results.iter().filter(|row| row.status == "success").count();
        let failed = results.len() - delivered;
        self.record_send(
            adapter_name,
            adapter_type,
            message,
            delivered + failed,
            delivered,
        );
    }
}

fn telemetry_attributes(
    adapter_name: &str,
    adapter_type: &str,
    message: &dyn Message,
    result: &str,
) -> Attributes {
    let mut attrs = Attributes::new();
    attrs.insert("result".into(), result.into());
    if let Some(origin) = message.get_origin() {
        attrs.insert("origin".into(), origin.to_string());
    }
    attrs.insert("type".into(), adapter_type.to_string());
    attrs.insert("provider".into(), adapter_name.to_ascii_lowercase());
    attrs
}

/// PHP `Utopia\Messaging\Adapter`.
pub trait Adapter: Send + Sync {
    /// PHP `getName()`.
    fn get_name(&self) -> &'static str;

    /// PHP `getType()`.
    fn get_type(&self) -> &'static str;

    /// PHP `getMessageType()` as a [`MessageKind`] (PHP returns a class name).
    fn get_message_type(&self) -> MessageKind;

    /// PHP `getMaxMessagesPerRequest()`.
    fn get_max_messages_per_request(&self) -> usize;

    /// Shared HTTP/telemetry state.
    fn base(&self) -> &AdapterBase;

    /// PHP `process($message)` - default throws like a missing method.
    fn process(&self, _message: &dyn Message) -> Result<SendResult, MessagingError> {
        Err(MessagingError::MissingProcess)
    }

    /// PHP `setTelemetry`.
    fn set_telemetry(&self, telemetry: Arc<dyn TelemetryAdapter>) {
        self.base().set_telemetry(telemetry);
    }

    /// Inject HTTP client factory (PHP `$clientFactory`).
    fn set_client_factory(&self, factory: ClientFactory) {
        self.base().set_client_factory(factory);
    }

    /// PHP `send(Message $message)`.
    fn send(&self, message: &dyn Message) -> Result<SendResult, MessagingError> {
        if message.kind() != self.get_message_type() {
            return Err(MessagingError::InvalidMessageType);
        }
        if let Some(count) = message.to_count() {
            if count > self.get_max_messages_per_request() {
                return Err(MessagingError::TooManyMessages {
                    name: self.get_name().to_string(),
                    max: self.get_max_messages_per_request(),
                });
            }
        }
        match self.process(message) {
            Ok(response) => {
                self.base()
                    .record_response(self.get_name(), self.get_type(), message, &response);
                Ok(response)
            }
            Err(error) => {
                let recipients = message.to_count().unwrap_or(1);
                self.base()
                    .record_send(self.get_name(), self.get_type(), message, recipients, 0);
                Err(error)
            }
        }
    }

    /// PHP `request()` with adapter name for the default User-Agent.
    fn request(
        &self,
        method: &str,
        url: &str,
        headers: &[String],
        body: Option<Value>,
        timeout: u64,
        connect_timeout: u64,
    ) -> HttpResult {
        self.base().request(
            self.get_name(),
            method,
            url,
            headers,
            body,
            timeout,
            connect_timeout,
        )
    }

    /// PHP `request()` with default timeouts (30 / 10).
    fn request_default(
        &self,
        method: &str,
        url: &str,
        headers: &[String],
        body: Option<Value>,
    ) -> HttpResult {
        self.request(method, url, headers, body, 30, 10)
    }

    /// PHP `requestMulti()`.
    fn request_multi(
        &self,
        method: &str,
        urls: &[String],
        headers: &[String],
        bodies: &[Value],
        timeout: u64,
        connect_timeout: u64,
    ) -> Result<Vec<MultiResult>, MessagingError> {
        self.base().request_multi(
            self.get_name(),
            method,
            urls,
            headers,
            bodies,
            timeout,
            connect_timeout,
        )
    }
}

/// Require an SMS message or `Invalid message type.`
pub fn expect_sms(message: &dyn Message) -> Result<&crate::messages::SMS, MessagingError> {
    message.as_sms().ok_or(MessagingError::InvalidMessageType)
}

/// Require an email message or `Invalid message type.`
pub fn expect_email(message: &dyn Message) -> Result<&crate::messages::Email, MessagingError> {
    message.as_email().ok_or(MessagingError::InvalidMessageType)
}

/// Require a push message or `Invalid message type.`
pub fn expect_push(message: &dyn Message) -> Result<&crate::messages::Push, MessagingError> {
    message.as_push().ok_or(MessagingError::InvalidMessageType)
}

/// Require a Discord message or `Invalid message type.`
pub fn expect_discord(message: &dyn Message) -> Result<&crate::messages::Discord, MessagingError> {
    message
        .as_discord()
        .ok_or(MessagingError::InvalidMessageType)
}

pub mod chat;
pub mod email;
pub mod push;
pub mod sms;
