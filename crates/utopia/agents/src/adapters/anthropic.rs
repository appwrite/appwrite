//! Anthropic.

use std::sync::Arc;

use php_std::json::{self, Flags};
use php_std::{Str, Value};

use super::chat::{self, Part, empty, required};
use crate::adapter::{self, BoxFuture, Embedding, Embeddings, Listener, api_error, append_token, json_or_sse};
use crate::php::{self, map};
use crate::{Adapter, Base, Error, Message, Profile, Transport};

/// Claude 4 Opus.
pub const MODEL_CLAUDE_4_OPUS: &str = "claude-opus-4-0";
/// Claude 3 Opus.
pub const MODEL_CLAUDE_3_OPUS: &str = "claude-3-opus-latest";
/// Claude 4 Sonnet.
pub const MODEL_CLAUDE_4_SONNET: &str = "claude-sonnet-4-0";
/// Claude 3.7 Sonnet.
pub const MODEL_CLAUDE_3_7_SONNET: &str = "claude-3-7-sonnet-latest";
/// Claude 3.5 Sonnet.
pub const MODEL_CLAUDE_3_5_SONNET: &str = "claude-3-5-sonnet-latest";
/// Claude 3.5 Haiku.
pub const MODEL_CLAUDE_3_5_HAIKU: &str = "claude-3-5-haiku-latest";
/// Claude 3 Haiku (the default).
pub const MODEL_CLAUDE_3_HAIKU: &str = "claude-3-haiku-20240307";

/// Every model, in PHP's order.
pub const MODELS: &[&str] = &[
    MODEL_CLAUDE_4_OPUS,
    MODEL_CLAUDE_3_OPUS,
    MODEL_CLAUDE_4_SONNET,
    MODEL_CLAUDE_3_7_SONNET,
    MODEL_CLAUDE_3_5_SONNET,
    MODEL_CLAUDE_3_5_HAIKU,
    MODEL_CLAUDE_3_HAIKU,
];

/// The Messages API endpoint.
///
/// PHP: `Anthropic::ENDPOINT`.
pub const ENDPOINT: &str = "https://api.anthropic.com/v1/messages";

/// The API version requests ask for (`anthropic-version`).
///
/// PHP: `Anthropic::API_VERSION`.
pub const API_VERSION: &str = "2023-06-01";

/// The most system blocks marked for prompt caching.
const CACHE_LIMIT: usize = 4;

/// Anthropic's Messages API: answers streamed token by token, with token
/// usage (prompt cache writes and reads included) counted from the stream's
/// events; with a schema, a forced tool call whose input is the answer. The
/// description and the first instructions (four blocks in all) are marked
/// for prompt caching. Images attach as base64 sources.
///
/// PHP: `Utopia\Agents\Adapters\Anthropic`: `new Anthropic($apiKey, $model,
/// $maxTokens, $temperature, $timeout, $client)` is `Anthropic::new` and the
/// `with_*` methods. The endpoint is fixed.
///
/// ```
/// use utopia_agents::{Adapter, Anthropic, adapters::anthropic};
///
/// let adapter = Anthropic::new("test-api-key").with_model(anthropic::MODEL_CLAUDE_4_SONNET);
/// assert_eq!((adapter.name(), adapter.model()), ("anthropic", "claude-sonnet-4-0"));
/// ```
///
/// ```php
/// use Utopia\Agents\Adapters\Anthropic;
///
/// $adapter = new Anthropic('test-api-key', Anthropic::MODEL_CLAUDE_4_SONNET);
/// echo $adapter->getName(), ' ', $adapter->getModel(), "\n"; // anthropic claude-sonnet-4-0
/// ```
#[derive(Debug, Clone)]
pub struct Anthropic {
    base: Base,
    api_key: String,
    model: String,
    max_tokens: i64,
    temperature: f64,
}

impl Anthropic {
    /// An adapter with the API key, model [`MODEL_CLAUDE_3_HAIKU`], 1024
    /// tokens and temperature 1.0.
    ///
    /// PHP: `new Anthropic($apiKey)`.
    pub fn new(api_key: &str) -> Anthropic {
        Anthropic {
            base: Base::default(),
            api_key: api_key.to_owned(),
            model: MODEL_CLAUDE_3_HAIKU.to_owned(),
            max_tokens: 1024,
            temperature: 1.0,
        }
    }

    /// With this model.
    pub fn with_model(mut self, model: &str) -> Anthropic {
        self.model = model.to_owned();
        self
    }

    /// With at most this many tokens per answer.
    pub fn with_max_tokens(mut self, max_tokens: i64) -> Anthropic {
        self.max_tokens = max_tokens;
        self
    }

    /// With this temperature.
    pub fn with_temperature(mut self, temperature: f64) -> Anthropic {
        self.temperature = temperature;
        self
    }

    /// With this timeout, in milliseconds.
    pub fn with_timeout(mut self, timeout: i64) -> Anthropic {
        self.base.assign_timeout(timeout);
        self
    }

    /// Sending through this client.
    pub fn with_client(mut self, client: Arc<dyn Transport>) -> Anthropic {
        self.base.set_client(client);
        self
    }

    /// Changes the most tokens per answer.
    ///
    /// PHP: `setMaxTokens($maxTokens)`.
    pub fn set_max_tokens(&mut self, max_tokens: i64) -> &mut Anthropic {
        self.max_tokens = max_tokens;
        self
    }

    /// Changes the temperature.
    ///
    /// PHP: `setTemperature($temperature)`.
    pub fn set_temperature(&mut self, temperature: f64) -> &mut Anthropic {
        self.temperature = temperature;
        self
    }

    async fn chat(&mut self, profile: &Profile, messages: &[Message], mut listener: Option<Listener<'_>>) -> Result<Message, Error> {
        let cache = || map([("type", Value::from("ephemeral"))]);
        let mut system = Vec::new();
        let described = !empty(profile.description());
        if described {
            system.push(map([
                ("type", Value::from("text")),
                ("text", Value::from(profile.description())),
                ("cache_control", cache()),
            ]));
        }
        let mut cached = usize::from(described);
        for text in profile.instruction_texts() {
            let mut block = map([("type", Value::from("text")), ("text", Value::from(text.as_str()))]);
            if cached < CACHE_LIMIT {
                php::set(&mut block, "cache_control", cache());
                cached += 1;
            }
            system.push(block);
        }

        let formatted = messages
            .iter()
            .map(|m| map([("role", Value::from(m.role())), ("content", chat::content(m, Part::Anthropic))]))
            .collect();

        let schema = profile.schema();
        let mut payload = map([
            ("model", Value::from(self.model.as_str())),
            ("system", php::list(system)),
            ("messages", php::list(formatted)),
            ("max_tokens", Value::Int(self.max_tokens)),
            ("temperature", Value::Float(self.temperature)),
        ]);
        if let Some(schema) = schema {
            let tool = map([
                ("name", Value::from(schema.name())),
                ("description", Value::from(schema.description())),
                (
                    "input_schema",
                    map([
                        ("type", Value::from("object")),
                        ("properties", Value::Array(schema.properties().clone())),
                        ("required", required(schema.required())),
                    ]),
                ),
            ]);
            php::set(&mut payload, "tools", php::list(vec![tool]));
            php::set(&mut payload, "tool_choice", map([("type", Value::from("tool")), ("name", Value::from(schema.name()))]));
        }
        let stream = schema.is_none();
        php::set(&mut payload, "stream", Value::Bool(stream));

        let headers = [("x-api-key", self.api_key.as_str()), ("anthropic-version", API_VERSION)];
        let request = Base::request(ENDPOINT, &payload, &headers)?;
        let client = self.base.client()?;

        if stream {
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
            let tail = match self.base.take_stream_line() {
                Some(line) => self.process_lines(std::iter::once(line.as_slice()), &mut listener),
                None => Vec::new(),
            };
            content.extend_from_slice(&tail);
            self.base.reset_stream();
            if response.status() >= 400 {
                return Err(api_error("anthropic", &content, response.status()));
            }
            return Ok(Message::new(content));
        }

        let response = client.send(request).await?;
        let body = response.bytes();
        if response.status() >= 400 {
            let content = format_error(&php::decode(body));
            return Err(api_error("anthropic", &content, response.status()));
        }

        let json = php::decode(body);
        let mut text = Vec::new();
        if let (Some(schema), Some(item)) = (schema, php::get(&json, "content").and_then(|c| php::at(c, 0))) {
            let tool_use = matches!(php::get(item, "type"), Some(Value::Str(t)) if t.as_bytes() == b"tool_use");
            let named = matches!(php::get(item, "name"), Some(Value::Str(n)) if n.as_bytes() == schema.name().as_bytes());
            if tool_use && named {
                // `$item['input']`: null when missing (after PHP's warning).
                let input = match item {
                    Value::Array(a) => a.get_str("input").cloned().unwrap_or(Value::Null),
                    _ => Value::Null,
                };
                text = match input {
                    Value::Str(s) => s.as_bytes().to_vec(),
                    other => match json::encode(&other, Flags::NONE, json::DEFAULT_DEPTH) {
                        // `json_encode(...) ?: ''`: "0" is falsy too.
                        Ok(encoded) if encoded != "0" => encoded.into_bytes(),
                        _ => Vec::new(),
                    },
                };
            }
        }
        if text.is_empty() {
            text = body.to_vec();
        }
        Ok(Message::new(text))
    }

    /// PHP: `process($chunk, $listener)`.
    fn process(&mut self, chunk: &[u8], listener: &mut Option<Listener<'_>>) -> Vec<u8> {
        let lines = self.base.stream_lines(chunk);
        self.process_lines(lines.iter(), listener)
    }

    /// PHP: `processStreamLines($lines, $listener)`: the stream's events,
    /// whose `type` PHP's `switch` compares loosely (`==`).
    fn process_lines<'l>(&mut self, lines: impl Iterator<Item = &'l [u8]>, listener: &mut Option<Listener<'_>>) -> Vec<u8> {
        const EVENTS: [&str; 7] = [
            "message_start",
            "content_block_start",
            "content_block_delta",
            "content_block_stop",
            "message_delta",
            "message_stop",
            "error",
        ];
        let mut block = Vec::new();
        for line in lines {
            let Some(json) = json_or_sse(line) else { continue };
            let Some(kind) = php::get(&json, "type") else { continue };
            let event = EVENTS.iter().position(|e| kind.loose_eq(&Value::Str(Str::from(*e))));
            match event {
                Some(0) => {
                    if let Some(usage) = php::array(&json, "message").and_then(|m| php::array(m, "usage")) {
                        let usage = (
                            php::int(usage, "input_tokens"),
                            php::int(usage, "output_tokens"),
                            php::int(usage, "cache_creation_input_tokens"),
                            php::int(usage, "cache_read_input_tokens"),
                        );
                        let counters = self.base.usage_mut();
                        if let Some(n) = usage.0 {
                            counters.count_input(n);
                        }
                        if let Some(n) = usage.1 {
                            counters.count_output(n);
                        }
                        if let Some(n) = usage.2 {
                            counters.count_cache_creation_input(n);
                        }
                        if let Some(n) = usage.3 {
                            counters.count_cache_read_input(n);
                        }
                    }
                }
                Some(2) => {
                    let Some(delta) = php::array(&json, "delta") else { continue };
                    let Some(kind) = php::get(delta, "type") else { continue };
                    if matches!(kind, Value::Str(k) if k.as_bytes() == b"text_delta")
                        && let Some(text) = php::string(delta, "text")
                    {
                        append_token(&mut block, text, listener);
                    }
                }
                Some(4) => {
                    if let Some(usage) = php::array(&json, "usage") {
                        let (input, output) = (php::int(usage, "input_tokens"), php::int(usage, "output_tokens"));
                        let counters = self.base.usage_mut();
                        if let Some(n) = input {
                            counters.count_input(n);
                        }
                        if let Some(n) = output {
                            counters.count_output(n);
                        }
                    }
                }
                Some(6) => return format_error(&json),
                _ => {}
            }
        }
        block
    }
}

/// PHP: `Anthropic::formatErrorMessage($json)`.
fn format_error(json: &Value) -> Vec<u8> {
    if !matches!(json, Value::Array(_)) {
        return b"(unknown_error) Unknown error".to_vec();
    }
    let error = php::array(json, "error");
    let kind = error.and_then(|e| php::string(e, "type")).unwrap_or(b"unknown_error");
    let message = error.and_then(|e| php::string(e, "message")).unwrap_or(b"Unknown error");
    [b"(".as_slice(), kind, b") ", message].concat()
}

impl Adapter for Anthropic {
    fn name(&self) -> &str {
        "anthropic"
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
