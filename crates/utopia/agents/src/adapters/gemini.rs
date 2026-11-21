//! Google Gemini.

use std::sync::Arc;

use php_std::Value;
use php_std::json::{self, Flags};

use super::chat;
use crate::adapter::{
    self, BoxFuture, Embedding, Embeddings, Listener, api_error, append_token, is_image, sse_json, trim,
};
use crate::php::{self, map};
use crate::{Adapter, Base, Error, Message, Profile, Transport};

/// Gemini 2.5 Flash Preview.
pub const MODEL_GEMINI_2_5_FLASH_PREVIEW: &str = "gemini-2.5-flash-preview-04-17";
/// Gemini 2.5 Pro Preview.
pub const MODEL_GEMINI_2_5_PRO_PREVIEW: &str = "gemini-2.5-pro-preview-03-25";
/// Gemini 2.0 Flash (the default).
pub const MODEL_GEMINI_2_0_FLASH: &str = "gemini-2.0-flash";
/// Gemini 2.0 Flash Lite.
pub const MODEL_GEMINI_2_0_FLASH_LITE: &str = "gemini-2.0-flash-lite";
/// Gemini 1.5 Pro.
pub const MODEL_GEMINI_1_5_PRO: &str = "gemini-1.5-pro";

/// Every model, in PHP's order.
pub const MODELS: &[&str] = &[
    MODEL_GEMINI_2_0_FLASH,
    MODEL_GEMINI_2_0_FLASH_LITE,
    MODEL_GEMINI_1_5_PRO,
    MODEL_GEMINI_2_5_FLASH_PREVIEW,
    MODEL_GEMINI_2_5_PRO_PREVIEW,
];

/// The streaming endpoint of a model, with the API key in the query.
///
/// PHP: the constructor's default `$endpoint`.
pub fn endpoint(model: &str, api_key: &str) -> String {
    format!(
        "https://generativelanguage.googleapis.com/v1beta/models/{model}:streamGenerateContent?alt=sse&key={api_key}"
    )
}

/// Google's Gemini API: answers always streamed (server-sent events), the
/// description and instructions as the system instruction, the assistant's
/// messages as the `model` role, images inline. No structured output.
///
/// PHP: `Utopia\Agents\Adapters\Gemini`: the endpoint defaults to the
/// streaming endpoint of the model given to the constructor
/// ([`endpoint`]); here of the model given to [`Gemini::with_model`],
/// unless [`Gemini::with_endpoint`] set one. [`Adapter::set_model`] keeps
/// the endpoint, as `setModel()` does.
///
/// ```
/// use utopia_agents::{Adapter, Gemini, adapters::gemini};
///
/// let adapter = Gemini::new("test-api-key").with_model(gemini::MODEL_GEMINI_2_0_FLASH_LITE);
/// assert_eq!(adapter.name(), "gemini");
/// assert_eq!(
///     adapter.endpoint(),
///     "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash-lite:streamGenerateContent?alt=sse&key=test-api-key"
/// );
/// ```
///
/// ```php
/// use Utopia\Agents\Adapters\Gemini;
///
/// $adapter = new Gemini('test-api-key', Gemini::MODEL_GEMINI_2_0_FLASH_LITE);
/// echo $adapter->getName(), "\n"; // gemini
/// echo $adapter->getEndpoint(), "\n";
/// // https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash-lite:streamGenerateContent?alt=sse&key=test-api-key
/// ```
#[derive(Debug, Clone)]
pub struct Gemini {
    base: Base,
    api_key: String,
    model: String,
    max_tokens: i64,
    temperature: f64,
    endpoint: String,
    custom_endpoint: bool,
}

impl Gemini {
    /// An adapter with the API key, model [`MODEL_GEMINI_2_0_FLASH`], 1024
    /// tokens, temperature 1.0 and the model's [`endpoint`].
    ///
    /// PHP: `new Gemini($apiKey)`.
    pub fn new(api_key: &str) -> Gemini {
        Gemini {
            base: Base::default(),
            api_key: api_key.to_owned(),
            model: MODEL_GEMINI_2_0_FLASH.to_owned(),
            max_tokens: 1024,
            temperature: 1.0,
            endpoint: endpoint(MODEL_GEMINI_2_0_FLASH, api_key),
            custom_endpoint: false,
        }
    }

    /// With this model (and its endpoint, unless one was given).
    pub fn with_model(mut self, model: &str) -> Gemini {
        self.model = model.to_owned();
        if !self.custom_endpoint {
            self.endpoint = endpoint(model, &self.api_key);
        }
        self
    }

    /// With at most this many tokens per answer.
    pub fn with_max_tokens(mut self, max_tokens: i64) -> Gemini {
        self.max_tokens = max_tokens;
        self
    }

    /// With this temperature.
    pub fn with_temperature(mut self, temperature: f64) -> Gemini {
        self.temperature = temperature;
        self
    }

    /// Sending to this endpoint.
    pub fn with_endpoint(mut self, endpoint: &str) -> Gemini {
        self.endpoint = endpoint.to_owned();
        self.custom_endpoint = true;
        self
    }

    /// With this timeout, in milliseconds.
    pub fn with_timeout(mut self, timeout: i64) -> Gemini {
        self.base.assign_timeout(timeout);
        self
    }

    /// Sending through this client.
    pub fn with_client(mut self, client: Arc<dyn Transport>) -> Gemini {
        self.base.set_client(client);
        self
    }

    /// Changes the most tokens per answer.
    ///
    /// PHP: `setMaxTokens($maxTokens)`.
    pub fn set_max_tokens(&mut self, max_tokens: i64) -> &mut Gemini {
        self.max_tokens = max_tokens;
        self
    }

    /// Changes the temperature.
    ///
    /// PHP: `setTemperature($temperature)`.
    pub fn set_temperature(&mut self, temperature: f64) -> &mut Gemini {
        self.temperature = temperature;
        self
    }

    /// The endpoint.
    ///
    /// PHP: `getEndpoint()`.
    pub fn endpoint(&self) -> &str {
        &self.endpoint
    }

    /// Changes the endpoint.
    ///
    /// PHP: `setEndpoint($endpoint)`.
    pub fn set_endpoint(&mut self, endpoint: &str) -> &mut Gemini {
        self.endpoint = endpoint.to_owned();
        self
    }

    async fn chat(
        &mut self,
        profile: &Profile,
        messages: &[Message],
        mut listener: Option<Listener<'_>>,
    ) -> Result<Message, Error> {
        let mut system = vec![map([("text", Value::from(profile.description()))])];
        system.extend(profile.instruction_texts().map(|text| map([("text", Value::from(text.as_str()))])));

        let contents = messages
            .iter()
            .map(|m| {
                let role = if m.role() == "user" { "user" } else { "model" };
                map([("role", Value::from(role)), ("parts", parts(m))])
            })
            .collect();

        let payload = map([
            ("system_instruction", map([("parts", php::list(system))])),
            ("contents", php::list(contents)),
            (
                "generationConfig",
                map([
                    ("maxOutputTokens", Value::Int(self.max_tokens)),
                    ("temperature", Value::Float(self.temperature)),
                ]),
            ),
        ]);

        let request = Base::request(&self.endpoint, &payload, &[])?;
        let client = self.base.client()?;
        self.base.reset_stream();
        let mut content = Vec::new();
        let result = {
            let mut sink = |chunk: &[u8]| {
                let block = self.process(chunk, &mut listener);
                content.extend_from_slice(&block);
            };
            client.stream(request, &mut sink).await
        };
        let response = match result {
            Ok(response) => response,
            Err(e) => {
                self.base.reset_stream();
                return Err(e.into());
            }
        };
        if let Some(line) = self.base.take_stream_line() {
            content.extend_from_slice(&process_lines(std::iter::once(line.as_slice()), &mut listener));
        }
        self.base.reset_stream();
        if response.status() >= 400 {
            return Err(api_error("gemini", &content, response.status()));
        }
        Ok(Message::new(content))
    }

    /// PHP: `process($chunk, $listener)`.
    fn process(&mut self, chunk: &[u8], listener: &mut Option<Listener<'_>>) -> Vec<u8> {
        let lines = self.base.stream_lines(chunk);
        let json = php::decode_object(trim(chunk)).or_else(|| php::decode_object(&lines.data));
        if let Some(json) = json
            && php::get(&json, "error").is_some()
        {
            return format_error(&json);
        }
        process_lines(lines.iter(), listener)
    }
}

/// PHP: `formatMessageParts($message)`.
fn parts(message: &Message) -> Value {
    let mut parts = Vec::new();
    if !message.content().is_empty() {
        parts.push(map([("text", php::bytes(message.content()))]));
    }
    for image in message.attachments().iter().filter(|a| is_image(a)) {
        let mime = image.mime_type().unwrap_or("application/octet-stream");
        parts.push(map([(
            "inline_data",
            map([
                ("mime_type", Value::from(mime)),
                ("data", Value::from(php_std::encoding::base64_encode(image.content()).as_str())),
            ]),
        )]));
    }
    if parts.is_empty() {
        parts.push(map([("text", Value::from(""))]));
    }
    php::list(parts)
}

/// PHP: `processStreamLines($lines, $listener)`.
fn process_lines<'l>(lines: impl Iterator<Item = &'l [u8]>, listener: &mut Option<Listener<'_>>) -> Vec<u8> {
    let mut block = Vec::new();
    for line in lines {
        let Some(json) = sse_json(line) else { continue };
        let text = php::array(&json, "candidates")
            .and_then(|c| php::at(c, 0))
            .filter(|c| matches!(c, Value::Array(_)))
            .and_then(|c| php::array(c, "content"))
            .and_then(|c| php::array(c, "parts"))
            .and_then(|p| php::at(p, 0))
            .and_then(|p| php::string(p, "text"));
        if let Some(text) = text {
            append_token(&mut block, text, listener);
        }
    }
    block
}

/// PHP: `Gemini::formatErrorMessage($json)`: `(<status>) <message>`, a
/// newline, and the details as pretty-printed JSON.
fn format_error(json: &Value) -> Vec<u8> {
    if !matches!(json, Value::Array(_)) {
        return b"(unknown_error) Unknown error".to_vec();
    }
    let error = php::array(json, "error");
    let kind = error.and_then(|e| php::string(e, "status")).unwrap_or(b"unknown_error");
    let message = error.and_then(|e| php::string(e, "message")).unwrap_or(b"Unknown error");
    let details = match error.and_then(|e| php::get(e, "details")) {
        Some(details) => json::encode(details, Flags::PRETTY_PRINT, json::DEFAULT_DEPTH).unwrap_or_default(),
        None => String::new(),
    };
    [b"(".as_slice(), kind, b") ", message, b"\n", details.as_bytes()].concat()
}

impl Adapter for Gemini {
    fn name(&self) -> &str {
        "gemini"
    }

    fn send<'a>(
        &'a mut self,
        profile: &'a Profile,
        messages: &'a [Message],
        listener: Option<Listener<'a>>,
    ) -> BoxFuture<'a, Result<Message, Error>> {
        Box::pin(self.chat(profile, messages, listener))
    }

    fn models(&self) -> &'static [&'static str] {
        MODELS
    }

    fn model(&self) -> &str {
        &self.model
    }

    fn set_model(&mut self, model: &str) -> Result<(), Error> {
        self.model = model.to_owned();
        Ok(())
    }

    fn is_schema_supported(&self) -> bool {
        false
    }

    fn supports_embeddings(&self) -> bool {
        false
    }

    fn embed<'a>(&'a mut self, _text: &'a str) -> BoxFuture<'a, Result<Embedding, Error>> {
        Box::pin(async { Err(adapter::no_embeddings()) })
    }

    fn bulk_embed<'a>(&'a mut self, _texts: &'a [String]) -> BoxFuture<'a, Result<Embeddings, Error>> {
        Box::pin(async { Err(adapter::no_embeddings()) })
    }

    fn embedding_dimension(&self) -> Result<i64, Error> {
        Err(adapter::no_embeddings())
    }

    fn base(&self) -> &Base {
        &self.base
    }

    fn base_mut(&mut self) -> &mut Base {
        &mut self.base
    }

    fn supports_attachments(&self) -> bool {
        true
    }

    fn supports_attachment(&self, attachment: &Message) -> bool {
        is_image(attachment)
    }

    fn max_attachments_per_message(&self) -> Option<i64> {
        Some(chat::MAX_ATTACHMENTS_PER_MESSAGE)
    }

    fn max_attachment_bytes(&self) -> Option<i64> {
        Some(chat::MAX_ATTACHMENT_BYTES)
    }

    fn max_total_attachment_bytes(&self) -> Option<i64> {
        Some(chat::MAX_TOTAL_ATTACHMENT_BYTES)
    }

    fn allowed_attachment_mime_types(&self) -> Option<std::borrow::Cow<'_, [&str]>> {
        Some(std::borrow::Cow::Borrowed(chat::IMAGE_TYPES))
    }
}
