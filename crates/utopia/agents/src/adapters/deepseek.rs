//! Deepseek.

use std::sync::Arc;

use php_std::Value;

use super::chat::{self, Part, empty, empty_bytes, has_text_or_image, system_message};
use crate::adapter::{self, BoxFuture, Embedding, Embeddings, Listener, api_error, append_token, sse_json, trim};
use crate::php::{self, map};
use crate::{Adapter, Base, Error, Message, Profile, Transport};

/// Deepseek Chat (the default).
pub const MODEL_DEEPSEEK_CHAT: &str = "deepseek-chat";
/// Deepseek Coder.
pub const MODEL_DEEPSEEK_CODER: &str = "deepseek-coder";

/// Every model, in PHP's order.
pub const MODELS: &[&str] = &[MODEL_DEEPSEEK_CHAT, MODEL_DEEPSEEK_CODER];

/// The chat completions endpoint.
///
/// PHP: `Deepseek::ENDPOINT`.
pub const ENDPOINT: &str = "https://api.deepseek.com/chat/completions";

/// Deepseek's chat completions, always streamed, with token usage counted
/// from the stream. Messages without a role or any text or image are left
/// out. With a schema, the answer is asked for in JSON mode and the schema
/// is described in the system message ([`Schema::to_json`](crate::Schema::to_json)).
///
/// PHP: `Utopia\Agents\Adapters\Deepseek`. The endpoint is fixed.
///
/// ```
/// use utopia_agents::{Adapter, Deepseek};
///
/// let adapter = Deepseek::new("test-api-key");
/// assert_eq!((adapter.name(), adapter.model()), ("deepseek", "deepseek-chat"));
/// ```
///
/// ```php
/// use Utopia\Agents\Adapters\Deepseek;
///
/// $adapter = new Deepseek('test-api-key');
/// echo $adapter->getName(), ' ', $adapter->getModel(), "\n"; // deepseek deepseek-chat
/// ```
#[derive(Debug, Clone)]
pub struct Deepseek {
    base: Base,
    api_key: String,
    model: String,
    max_tokens: i64,
    temperature: f64,
}

impl Deepseek {
    /// An adapter with the API key, model [`MODEL_DEEPSEEK_CHAT`], 1024
    /// tokens and temperature 1.0.
    ///
    /// PHP: `new Deepseek($apiKey)`.
    pub fn new(api_key: &str) -> Deepseek {
        Deepseek {
            base: Base::default(),
            api_key: api_key.to_owned(),
            model: MODEL_DEEPSEEK_CHAT.to_owned(),
            max_tokens: 1024,
            temperature: 1.0,
        }
    }

    /// With this model.
    pub fn with_model(mut self, model: &str) -> Deepseek {
        self.model = model.to_owned();
        self
    }

    /// With at most this many tokens per answer.
    pub fn with_max_tokens(mut self, max_tokens: i64) -> Deepseek {
        self.max_tokens = max_tokens;
        self
    }

    /// With this temperature.
    pub fn with_temperature(mut self, temperature: f64) -> Deepseek {
        self.temperature = temperature;
        self
    }

    /// With this timeout, in milliseconds.
    pub fn with_timeout(mut self, timeout: i64) -> Deepseek {
        self.base.assign_timeout(timeout);
        self
    }

    /// Sending through this client.
    pub fn with_client(mut self, client: Arc<dyn Transport>) -> Deepseek {
        self.base.set_client(client);
        self
    }

    /// Changes the most tokens per answer.
    ///
    /// PHP: `setMaxTokens($maxTokens)`.
    pub fn set_max_tokens(&mut self, max_tokens: i64) -> &mut Deepseek {
        self.max_tokens = max_tokens;
        self
    }

    /// Changes the temperature.
    ///
    /// PHP: `setTemperature($temperature)`.
    pub fn set_temperature(&mut self, temperature: f64) -> &mut Deepseek {
        self.temperature = temperature;
        self
    }

    async fn chat(&mut self, profile: &Profile, messages: &[Message], mut listener: Option<Listener<'_>>) -> Result<Message, Error> {
        let mut formatted: Vec<Value> = messages
            .iter()
            .filter(|m| !empty(m.role()) && has_text_or_image(m))
            .map(|m| map([("role", Value::from(m.role())), ("content", chat::content(m, Part::OpenAi))]))
            .collect();

        let mut system = system_message(profile);
        let schema = profile.schema();
        if let Some(schema) = schema {
            system.push_str("\n\nUSE THE JSON SCHEMA BELOW TO GENERATE A VALID JSON RESPONSE: \n");
            system.push_str(&schema.to_json()?);
        }
        if !empty_bytes(system.as_bytes()) {
            formatted.insert(0, map([("role", Value::from("system")), ("content", Value::from(system.as_str()))]));
        }

        let mut payload = map([
            ("model", Value::from(self.model.as_str())),
            ("messages", php::list(formatted)),
            ("max_tokens", Value::Int(self.max_tokens)),
            ("temperature", Value::Float(self.temperature)),
            ("stream", Value::Bool(true)),
        ]);
        if schema.is_some() {
            php::set(&mut payload, "response_format", map([("type", Value::from("json_object"))]));
        }

        let authorization = format!("Bearer {}", self.api_key);
        let request = Base::request(ENDPOINT, &payload, &[("authorization", authorization.as_str())])?;
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
            let tail = self.process_lines(std::iter::once(line.as_slice()), &mut listener);
            content.extend_from_slice(&tail);
        }
        self.base.reset_stream();
        if response.status() >= 400 {
            return Err(api_error("deepseek", &content, response.status()));
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
        self.process_lines(lines.iter(), listener)
    }

    /// PHP: `processStreamLines($lines, $listener)`.
    fn process_lines<'l>(&mut self, lines: impl Iterator<Item = &'l [u8]>, listener: &mut Option<Listener<'_>>) -> Vec<u8> {
        let mut block = Vec::new();
        for line in lines {
            let Some(json) = sse_json(line) else { continue };
            let token = php::array(&json, "choices")
                .and_then(|c| php::at(c, 0))
                .filter(|c| matches!(c, Value::Array(_)))
                .and_then(|c| php::array(c, "delta"))
                .and_then(|d| php::string(d, "content"));
            if let Some(token) = token {
                append_token(&mut block, token, listener);
            }
            if let Some(usage) = php::array(&json, "usage") {
                let (input, output) = (php::int(usage, "prompt_tokens"), php::int(usage, "completion_tokens"));
                let counters = self.base.usage_mut();
                if let Some(n) = input {
                    counters.count_input(n);
                }
                if let Some(n) = output {
                    counters.count_output(n);
                }
            }
        }
        block
    }
}

/// PHP: `Deepseek::formatErrorMessage($json)`.
fn format_error(json: &Value) -> Vec<u8> {
    if !matches!(json, Value::Array(_)) {
        return b"(unknown_error) Unknown error".to_vec();
    }
    let error = php::array(json, "error");
    let kind = error.and_then(|e| php::string(e, "type")).unwrap_or(b"unknown_error");
    let message = error.and_then(|e| php::string(e, "message")).unwrap_or(b"Unknown error");
    [b"(".as_slice(), kind, b") ", message].concat()
}

impl Adapter for Deepseek {
    fn name(&self) -> &str {
        "deepseek"
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
        true
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
        adapter::is_image(attachment)
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
