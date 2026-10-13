//! The OpenAI chat completions protocol, which OpenAI, OpenRouter,
//! Perplexity and xAI speak, each with its own differences ([`Dialect`]).

use php_std::Value;

use crate::adapter::{Base, Listener, api_error, append_token, is_image, sse_json, trim};
use crate::php::{self, map};
use crate::{Error, Message, Profile};

/// The attachment types OpenAI-compatible, Anthropic, Gemini and Deepseek
/// adapters accept.
pub(crate) const IMAGE_TYPES: &[&str] = &["image/png", "image/jpeg", "image/webp", "image/gif"];

/// The most attachments a message may carry.
pub(crate) const MAX_ATTACHMENTS_PER_MESSAGE: i64 = 10;
/// The largest attachment, in bytes.
pub(crate) const MAX_ATTACHMENT_BYTES: i64 = 5_000_000;
/// The most attachment bytes per message.
pub(crate) const MAX_TOTAL_ATTACHMENT_BYTES: i64 = 20_000_000;

/// How a provider differs from OpenAI.
#[derive(Debug, Clone)]
pub(crate) enum Dialect {
    OpenAi,
    OpenRouter { http_referer: Option<String>, x_title: Option<String> },
    Perplexity,
    Xai,
}

/// An OpenAI-compatible chat adapter.
///
/// PHP: `Utopia\Agents\Adapters\OpenAI` and the subclasses that override
/// parts of it.
#[derive(Debug, Clone)]
pub(crate) struct Chat {
    pub base: Base,
    pub api_key: String,
    pub model: String,
    pub max_tokens: i64,
    pub temperature: f64,
    pub endpoint: String,
    warned: bool,
    pub dialect: Dialect,
}

impl Chat {
    pub fn new(api_key: &str, model: &str, endpoint: &str, dialect: Dialect) -> Chat {
        Chat {
            base: Base::default(),
            api_key: api_key.to_owned(),
            model: model.to_owned(),
            max_tokens: 1024,
            temperature: 1.0,
            endpoint: endpoint.to_owned(),
            warned: false,
            dialect,
        }
    }

    pub fn name(&self) -> &'static str {
        match self.dialect {
            Dialect::OpenAi => "openai",
            Dialect::OpenRouter { .. } => "openrouter",
            Dialect::Perplexity => "perplexity",
            Dialect::Xai => "xai",
        }
    }

    /// PHP: `normalizeModelForCompatibilityChecks()`: OpenRouter strips the
    /// provider prefix and a `:` suffix (`openai/gpt-5-nano:free` is
    /// `gpt-5-nano`).
    fn normalized_model(&self) -> &str {
        match self.dialect {
            Dialect::OpenRouter { .. } => {
                let name = self.model.split_once('/').map_or(self.model.as_str(), |(_, rest)| rest);
                name.split_once(':').map_or(name, |(head, _)| head)
            }
            _ => &self.model,
        }
    }

    /// PHP: `usesMaxCompletionTokens()`.
    fn uses_max_completion_tokens(&self) -> bool {
        matches!(self.normalized_model(), "gpt-5-nano" | "o4-mini" | "o3" | "o3-mini")
    }

    /// PHP: `usesDefaultTemperatureOnly()`, which warns once (`error_log`)
    /// when it overrides another temperature.
    fn uses_default_temperature_only(&mut self) -> bool {
        let only = self.normalized_model() == "gpt-5-nano";
        if only && self.temperature != 1.0 && !self.warned {
            self.warned = true;
            eprintln!(
                "OpenAI adapter warning: model '{}' only supports temperature=1.0. Overriding provided value {}. Set temperature to 1.0 to remove this warning.",
                self.model,
                php_std::number::to_string(self.temperature)
            );
        }
        only
    }

    /// PHP: `headers()`.
    fn headers(&self) -> Vec<(&str, String)> {
        let mut headers = vec![("authorization", format!("Bearer {}", self.api_key))];
        if let Dialect::OpenRouter { http_referer, x_title } = &self.dialect {
            if let Some(referer) = http_referer.as_deref().filter(|v| !empty(v)) {
                headers.push(("HTTP-Referer", referer.to_owned()));
            }
            if let Some(title) = x_title.as_deref().filter(|v| !empty(v)) {
                headers.push(("X-Title", title.to_owned()));
            }
        }
        headers
    }

    pub async fn send(
        &mut self,
        profile: &Profile,
        messages: &[Message],
        mut listener: Option<Listener<'_>>,
    ) -> Result<Message, Error> {
        let mut formatted = Vec::with_capacity(messages.len() + 1);
        for message in messages {
            if empty(message.role()) || !has_text_or_image(message) {
                return Err(Error::exception("Invalid message format"));
            }
            formatted.push(map([("role", Value::from(message.role())), ("content", content(message, Part::OpenAi))]));
        }

        let system = system_message(profile);
        if !empty_bytes(system.as_bytes()) {
            formatted.insert(0, map([("role", Value::from("system")), ("content", Value::from(system.as_str()))]));
        }

        let mut payload = map([("model", Value::from(self.model.as_str())), ("messages", php::list(formatted))]);
        let temperature = if self.uses_default_temperature_only() { 1.0 } else { self.temperature };
        php::set(&mut payload, "temperature", Value::Float(temperature));

        let stream = match profile.schema() {
            Some(schema) => {
                let mut definition = map([
                    ("type", Value::from("object")),
                    ("properties", Value::Array(schema.properties().clone())),
                    ("required", required(schema.required())),
                ]);
                php::set(&mut definition, "additionalProperties", Value::Bool(false));
                let format = map([
                    ("type", Value::from("json_schema")),
                    (
                        "json_schema",
                        map([
                            ("name", Value::from(schema.name())),
                            ("strict", Value::Bool(true)),
                            ("schema", definition),
                        ]),
                    ),
                ]);
                php::set(&mut payload, "response_format", format);
                false
            }
            None => true,
        };
        php::set(&mut payload, "stream", Value::Bool(stream));
        let key = if self.uses_max_completion_tokens() { "max_completion_tokens" } else { "max_tokens" };
        php::set(&mut payload, key, Value::Int(self.max_tokens));

        let headers = self.headers();
        let headers: Vec<(&str, &str)> = headers.iter().map(|(n, v)| (*n, v.as_str())).collect();
        let request = Base::request(&self.endpoint, &payload, &headers)?;
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
            let tail = self.flush(&mut listener);
            content.extend_from_slice(&tail);
            self.base.reset_stream();
            if response.status() >= 400 {
                return Err(api_error(self.name(), &content, response.status()));
            }
            return Ok(Message::new(content));
        }

        let response = client.send(request).await?;
        let body = response.bytes();
        if response.status() >= 400 {
            let content = self.format_error(&php::decode(body));
            return Err(api_error(self.name(), &content, response.status()));
        }
        let json = php::decode(body);
        let text = php::array(&json, "choices")
            .and_then(|c| php::at(c, 0))
            .filter(|c| matches!(c, Value::Array(_)))
            .and_then(|c| php::array(c, "message"))
            .and_then(|m| php::string(m, "content"));
        match text {
            Some(text) => Ok(Message::new(text)),
            None => Err(Error::exception("Invalid response format received from the API")),
        }
    }

    /// PHP: `process($chunk, $listener)` (Perplexity's and xAI's
    /// overrides included).
    fn process(&mut self, chunk: &[u8], listener: &mut Option<Listener<'_>>) -> Vec<u8> {
        let lines = self.base.stream_lines(chunk);
        let json = php::decode_object(trim(chunk)).or_else(|| php::decode_object(&lines.data));
        if let Some(json) = json
            && php::get(&json, "error").is_some()
        {
            return self.format_error(&json);
        }
        if matches!(self.dialect, Dialect::Perplexity) {
            let trimmed = php_std::string::ltrim(chunk, php_std::string::TRIM_CHARACTERS);
            if starts_with_ignore_case(trimmed, b"<html") || starts_with_ignore_case(trimmed, b"<!DOCTYPE html") {
                return sanitize_html_error(chunk);
            }
        }
        process_lines(lines.iter(), listener)
    }

    /// PHP: `flushBufferedStreamData($listener)`.
    fn flush(&mut self, listener: &mut Option<Listener<'_>>) -> Vec<u8> {
        match self.base.take_stream_line() {
            Some(line) => process_lines(std::iter::once(line.as_slice()), listener),
            None => Vec::new(),
        }
    }

    /// PHP: `formatErrorMessage($json)` (xAI's override included).
    fn format_error(&self, json: &Value) -> Vec<u8> {
        if !matches!(json, Value::Array(_)) {
            return b"(unknown_error) Unknown error".to_vec();
        }
        let (kind, message) = match self.dialect {
            Dialect::Xai => (php::get(json, "code"), php::string(json, "error")),
            _ => {
                let error = php::array(json, "error");
                (error.and_then(|e| php::get(e, "code")), error.and_then(|e| php::string(e, "message")))
            }
        };
        let mut out = b"(".to_vec();
        out.extend_from_slice(&kind.and_then(php::scalar_string).unwrap_or_else(|| b"unknown_error".to_vec()));
        out.extend_from_slice(b") ");
        out.extend_from_slice(message.unwrap_or(b"Unknown error"));
        out
    }
}

/// PHP: `processStreamLines($lines, $listener)` of the OpenAI protocol.
fn process_lines<'l>(lines: impl Iterator<Item = &'l [u8]>, listener: &mut Option<Listener<'_>>) -> Vec<u8> {
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
    }
    block
}

/// `stripos($haystack, $needle) === 0`.
fn starts_with_ignore_case(haystack: &[u8], needle: &[u8]) -> bool {
    haystack.len() >= needle.len() && haystack[..needle.len()].eq_ignore_ascii_case(needle)
}

/// PHP: `Perplexity::sanitizeHtmlError($html)`.
fn sanitize_html_error(html: &[u8]) -> Vec<u8> {
    let status = |message: &[u8]| -> Vec<u8> {
        let parts = php_std::pcre::Regex::compiled(br"/^(\d{3})\s+(.+)$/i").ok().and_then(|re| {
            re.captures(message).map(|c| (c.get(1).unwrap_or_default().to_vec(), c.get(2).unwrap_or_default().to_vec()))
        });
        match parts {
            Some((code, text)) => [b"(http_".as_slice(), &code, b") ", &text].concat(),
            None => [b"(html_error) ".as_slice(), message].concat(),
        }
    };
    let capture = |pattern: &[u8]| {
        php_std::pcre::Regex::compiled(pattern)
            .ok()
            .and_then(|re| re.captures(html).map(|c| c.get(1).unwrap_or_default().to_vec()))
    };
    if let Some(title) = capture(br"/<title>(.*?)<\/title>/is") {
        return status(trim(&title));
    }
    if let Some(heading) = capture(br"/<h1>(.*?)<\/h1>/is") {
        let text = php_std::string::strip_tags(&heading);
        return status(trim(&text));
    }
    b"(html_error) Received HTML error response from API".to_vec()
}

/// PHP `empty()` of a string: `""` and `"0"`.
pub(crate) fn empty(s: &str) -> bool {
    empty_bytes(s.as_bytes())
}

/// PHP `empty()` of a byte string.
pub(crate) fn empty_bytes(s: &[u8]) -> bool {
    s.is_empty() || s == b"0"
}

/// PHP: `hasTextOrImageContent($message)`.
pub(crate) fn has_text_or_image(message: &Message) -> bool {
    !message.content().is_empty() || message.attachments().iter().any(is_image)
}

/// The description, then each instruction, separated by blank lines.
pub(crate) fn system_message(profile: &Profile) -> String {
    let mut system = profile.description().to_owned();
    let mut first = true;
    for text in profile.instruction_texts() {
        system.push_str(if first { "\n\n" } else { "\n\n" });
        first = false;
        system.push_str(&text);
    }
    system
}

/// How an adapter shapes an image part.
#[derive(Clone, Copy)]
pub(crate) enum Part {
    OpenAi,
    Anthropic,
}

/// PHP: `formatMessageContent($message)`: the text alone, or a list of a
/// text part and the image parts.
pub(crate) fn content(message: &Message, shape: Part) -> Value {
    let mut parts = Vec::new();
    if !message.content().is_empty() {
        parts.push(map([("type", Value::from("text")), ("text", php::bytes(message.content()))]));
    }
    for attachment in message.attachments().iter().filter(|a| is_image(a)) {
        parts.push(image_part(attachment, shape));
    }
    if parts.is_empty() {
        return php::bytes(message.content());
    }
    if parts.len() == 1 && message.attachments().iter().all(|a| !is_image(a)) {
        return php::bytes(message.content());
    }
    php::list(parts)
}

/// PHP: `buildImagePart($image)`.
fn image_part(image: &Message, shape: Part) -> Value {
    let mime = image.mime_type().unwrap_or("application/octet-stream");
    let data = php_std::encoding::base64_encode(image.content());
    match shape {
        Part::OpenAi => map([
            ("type", Value::from("image_url")),
            ("image_url", map([("url", Value::from(format!("data:{mime};base64,{data}").as_str()))])),
        ]),
        Part::Anthropic => {
            let media = if mime.starts_with("image/") { mime } else { "application/octet-stream" };
            map([
                ("type", Value::from("image")),
                (
                    "source",
                    map([
                        ("type", Value::from("base64")),
                        ("media_type", Value::from(media)),
                        ("data", Value::from(data.as_str())),
                    ]),
                ),
            ])
        }
    }
}

/// `required` as sent: a list of names.
pub(crate) fn required(names: &[String]) -> Value {
    php::list(names.iter().map(|n| Value::from(n.as_str())).collect())
}

