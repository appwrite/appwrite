//! The provider interface every adapter implements, the state they share
//! (token counts, timeout, HTTP client, stream buffer) and the HTTP client
//! interface requests go through.

use std::future::Future;
use std::pin::Pin;
use std::sync::Arc;

use php_std::json::{self, Flags};
use php_std::{Array, Value};
use utopia_client::{Client, Http, Request, Response};

use crate::{Error, Message, Profile};

/// A boxed future, as the object-safe [`Adapter`] and [`Transport`] return.
pub type BoxFuture<'a, T> = Pin<Box<dyn Future<Output = T> + Send + 'a>>;

/// Receives each token of a streamed answer as it arrives.
///
/// PHP: the `callable(string): void $listener` of `send()`.
pub type Listener<'a> = &'a mut (dyn FnMut(&str) + Send);

/// An embedding of one text.
///
/// PHP: the array `embed()` returns (`embedding`, `tokensProcessed`,
/// `totalDuration`, and for Ollama `modelLoadingDuration`).
#[derive(Debug, Clone, PartialEq)]
pub struct Embedding {
    /// The vector, as the provider sent it: a PHP array of (normally)
    /// floats, keys and value types kept.
    pub embedding: Array,
    /// Tokens the provider processed, when it says.
    pub tokens_processed: Option<i64>,
    /// How long the provider took (Ollama: nanoseconds, model loading
    /// included), when it says.
    pub total_duration: Option<i64>,
    /// How long loading the model took: `Some` for adapters that report it
    /// (Ollama), holding `None` when the provider did not say.
    pub model_loading_duration: Option<Option<i64>>,
}

/// Embeddings of several texts, in order.
///
/// PHP: the array `bulkEmbed()` returns (`embeddings`, `tokensProcessed`,
/// `totalDuration`).
#[derive(Debug, Clone, PartialEq)]
pub struct Embeddings {
    /// One vector per text.
    pub embeddings: Vec<Array>,
    /// Tokens processed in total, when the provider says.
    pub tokens_processed: Option<i64>,
    /// Total duration, when the provider says.
    pub total_duration: Option<i64>,
}

/// Tokens counted so far, by kind.
///
/// PHP: the token counters of `Adapter` and `Conversation`
/// (`getInputTokens()`, `countInputTokens()`, ...). Counts wrap where PHP's
/// integers would become floats, past `i64::MAX`.
#[derive(Debug, Clone, Copy, Default, PartialEq, Eq)]
pub struct Usage {
    input: i64,
    output: i64,
    cache_creation_input: i64,
    cache_read_input: i64,
}

impl Usage {
    /// Input tokens.
    ///
    /// PHP: `getInputTokens()`.
    pub fn input(&self) -> i64 {
        self.input
    }

    /// Output tokens.
    ///
    /// PHP: `getOutputTokens()`.
    pub fn output(&self) -> i64 {
        self.output
    }

    /// Input tokens written to the provider's prompt cache.
    ///
    /// PHP: `getCacheCreationInputTokens()`.
    pub fn cache_creation_input(&self) -> i64 {
        self.cache_creation_input
    }

    /// Input tokens read from the provider's prompt cache.
    ///
    /// PHP: `getCacheReadInputTokens()`.
    pub fn cache_read_input(&self) -> i64 {
        self.cache_read_input
    }

    /// Every kind added up.
    ///
    /// PHP: `getTotalTokens()`.
    pub fn total(&self) -> i64 {
        self.input.wrapping_add(self.output).wrapping_add(self.cache_creation_input).wrapping_add(self.cache_read_input)
    }

    /// Adds input tokens.
    ///
    /// PHP: `countInputTokens($tokens)`.
    pub fn count_input(&mut self, tokens: i64) -> &mut Usage {
        self.input = self.input.wrapping_add(tokens);
        self
    }

    /// Adds output tokens.
    ///
    /// PHP: `countOutputTokens($tokens)`.
    pub fn count_output(&mut self, tokens: i64) -> &mut Usage {
        self.output = self.output.wrapping_add(tokens);
        self
    }

    /// Adds cache creation input tokens.
    ///
    /// PHP: `countCacheCreationInputTokens($tokens)`.
    pub fn count_cache_creation_input(&mut self, tokens: i64) -> &mut Usage {
        self.cache_creation_input = self.cache_creation_input.wrapping_add(tokens);
        self
    }

    /// Adds cache read input tokens.
    ///
    /// PHP: `countCacheReadInputTokens($tokens)`.
    pub fn count_cache_read_input(&mut self, tokens: i64) -> &mut Usage {
        self.cache_read_input = self.cache_read_input.wrapping_add(tokens);
        self
    }
}

/// The HTTP client an adapter sends its requests through: any
/// [`utopia_client::Adapter`] (a [`Client`], a [`utopia_client::Retry`]
/// decorator, ...), or a client of your own, such as one that records
/// requests in tests.
///
/// PHP: the `(ClientInterface&StreamingClientInterface)|null $client` every
/// adapter's constructor takes.
pub trait Transport: Send + Sync {
    /// Sends a request and returns the whole response.
    ///
    /// PHP: `sendRequest($request)`.
    fn send(&self, request: Request) -> BoxFuture<'_, Result<Response, utopia_client::Error>>;

    /// Sends a request, passing each chunk of the body to `sink`; the
    /// response carries the status and headers.
    ///
    /// PHP: `stream($request, $sink)`.
    fn stream<'a>(
        &'a self,
        request: Request,
        sink: &'a mut (dyn FnMut(&[u8]) + Send),
    ) -> BoxFuture<'a, Result<Response, utopia_client::Error>>;
}

impl<A: utopia_client::Adapter + 'static> Transport for A {
    fn send(&self, request: Request) -> BoxFuture<'_, Result<Response, utopia_client::Error>> {
        Box::pin(utopia_client::Adapter::send(self, request))
    }

    fn stream<'a>(
        &'a self,
        request: Request,
        sink: &'a mut (dyn FnMut(&[u8]) + Send),
    ) -> BoxFuture<'a, Result<Response, utopia_client::Error>> {
        Box::pin(async move {
            let mut forward = |chunk: &[u8]| sink(chunk);
            utopia_client::Adapter::stream(self, request, &mut forward).await
        })
    }
}

/// An AI provider: sends a conversation and returns the answer (streamed
/// token by token to a listener where the provider streams), or embeds
/// texts. Implement it to add a provider.
///
/// Every adapter keeps a [`Base`] (token counts, timeout, HTTP client),
/// which the provided methods read.
///
/// PHP: the abstract class `Utopia\Agents\Adapter`. PHP's adapter reads
/// the agent's description, instructions and schema from the agent it was
/// handed to (`setAgent()`); here [`Adapter::send`] takes them, as the
/// [`Profile`] of the [`Agent`](crate::Agent) that owns the adapter.
pub trait Adapter: Send {
    /// The adapter's name (`openai`, `anthropic`, ...).
    ///
    /// PHP: `getName()`.
    fn name(&self) -> &str;

    /// Sends the messages, with the agent's description, instructions and
    /// schema, and returns the answer (a [`Message`] whose role is
    /// [`ROLE_USER`](crate::ROLE_USER), as PHP's `new Message($content)`).
    ///
    /// PHP: `send($messages, $listener)`.
    fn send<'a>(
        &'a mut self,
        profile: &'a Profile,
        messages: &'a [Message],
        listener: Option<Listener<'a>>,
    ) -> BoxFuture<'a, Result<Message, Error>>;

    /// The models the adapter offers.
    ///
    /// PHP: `getModels()`.
    fn models(&self) -> &'static [&'static str];

    /// The model in use.
    ///
    /// PHP: `getModel()`.
    fn model(&self) -> &str;

    /// Uses another model. Embedding adapters refuse models they do not
    /// offer ([`Error::InvalidArgument`]).
    ///
    /// PHP: `setModel($model)`.
    fn set_model(&mut self, model: &str) -> Result<(), Error>;

    /// Whether the adapter supports structured output ([`crate::Schema`]).
    ///
    /// PHP: `isSchemaSupported()`.
    fn is_schema_supported(&self) -> bool;

    /// Whether the adapter embeds texts.
    ///
    /// PHP: `getSupportForEmbeddings()`.
    fn supports_embeddings(&self) -> bool;

    /// Embeds one text.
    ///
    /// PHP: `embed($text)`.
    fn embed<'a>(&'a mut self, text: &'a str) -> BoxFuture<'a, Result<Embedding, Error>>;

    /// Embeds several texts.
    ///
    /// PHP: `bulkEmbed($texts)`.
    fn bulk_embed<'a>(&'a mut self, texts: &'a [String]) -> BoxFuture<'a, Result<Embeddings, Error>>;

    /// The size of the current model's embeddings.
    ///
    /// PHP: `getEmbeddingDimension()`.
    fn embedding_dimension(&self) -> Result<i64, Error>;

    /// The shared state.
    fn base(&self) -> &Base;

    /// The shared state, to change it.
    fn base_mut(&mut self) -> &mut Base;

    /// Whether messages may carry attachments.
    ///
    /// PHP: `supportsAttachments()`.
    fn supports_attachments(&self) -> bool {
        false
    }

    /// Whether this attachment is supported.
    ///
    /// PHP: `supportsAttachment($attachment)`.
    fn supports_attachment(&self, _attachment: &Message) -> bool {
        self.supports_attachments()
    }

    /// The most attachments one message may carry (`None`: no limit).
    ///
    /// PHP: `getMaxAttachmentsPerMessage()`.
    fn max_attachments_per_message(&self) -> Option<i64> {
        None
    }

    /// The largest attachment, in bytes (`None`: no limit).
    ///
    /// PHP: `getMaxAttachmentBytes()`.
    fn max_attachment_bytes(&self) -> Option<i64> {
        None
    }

    /// The most attachment bytes one message may carry (`None`: no limit).
    ///
    /// PHP: `getMaxTotalAttachmentBytes()`.
    fn max_total_attachment_bytes(&self) -> Option<i64> {
        None
    }

    /// The MIME types attachments may have (`None`: any).
    ///
    /// PHP: `getAllowedAttachmentMimeTypes()`.
    fn allowed_attachment_mime_types(&self) -> Option<std::borrow::Cow<'_, [&str]>> {
        None
    }

    /// The tokens counted so far.
    ///
    /// PHP: `getInputTokens()`, `getOutputTokens()`, ...
    fn usage(&self) -> &Usage {
        &self.base().usage
    }

    /// The token counters, to add to them.
    ///
    /// PHP: `countInputTokens()`, `countOutputTokens()`, ...
    fn usage_mut(&mut self) -> &mut Usage {
        &mut self.base_mut().usage
    }

    /// The request timeout, in milliseconds.
    ///
    /// PHP: `getTimeout()`.
    fn timeout(&self) -> i64 {
        self.base().timeout
    }

    /// Changes the request timeout, in milliseconds. A client the adapter
    /// built is rebuilt with it on the next request; a client given to the
    /// adapter keeps its own.
    ///
    /// PHP: `setTimeout($timeout)`.
    fn set_timeout(&mut self, timeout: i64) {
        self.base_mut().set_timeout(timeout);
    }
}

impl<A: Adapter + ?Sized> Adapter for Box<A> {
    fn name(&self) -> &str {
        (**self).name()
    }
    fn send<'a>(
        &'a mut self,
        profile: &'a Profile,
        messages: &'a [Message],
        listener: Option<Listener<'a>>,
    ) -> BoxFuture<'a, Result<Message, Error>> {
        (**self).send(profile, messages, listener)
    }
    fn models(&self) -> &'static [&'static str] {
        (**self).models()
    }
    fn model(&self) -> &str {
        (**self).model()
    }
    fn set_model(&mut self, model: &str) -> Result<(), Error> {
        (**self).set_model(model)
    }
    fn is_schema_supported(&self) -> bool {
        (**self).is_schema_supported()
    }
    fn supports_embeddings(&self) -> bool {
        (**self).supports_embeddings()
    }
    fn embed<'a>(&'a mut self, text: &'a str) -> BoxFuture<'a, Result<Embedding, Error>> {
        (**self).embed(text)
    }
    fn bulk_embed<'a>(&'a mut self, texts: &'a [String]) -> BoxFuture<'a, Result<Embeddings, Error>> {
        (**self).bulk_embed(texts)
    }
    fn embedding_dimension(&self) -> Result<i64, Error> {
        (**self).embedding_dimension()
    }
    fn base(&self) -> &Base {
        (**self).base()
    }
    fn base_mut(&mut self) -> &mut Base {
        (**self).base_mut()
    }
    fn supports_attachments(&self) -> bool {
        (**self).supports_attachments()
    }
    fn supports_attachment(&self, attachment: &Message) -> bool {
        (**self).supports_attachment(attachment)
    }
    fn max_attachments_per_message(&self) -> Option<i64> {
        (**self).max_attachments_per_message()
    }
    fn max_attachment_bytes(&self) -> Option<i64> {
        (**self).max_attachment_bytes()
    }
    fn max_total_attachment_bytes(&self) -> Option<i64> {
        (**self).max_total_attachment_bytes()
    }
    fn allowed_attachment_mime_types(&self) -> Option<std::borrow::Cow<'_, [&str]>> {
        (**self).allowed_attachment_mime_types()
    }
    fn usage(&self) -> &Usage {
        (**self).usage()
    }
    fn usage_mut(&mut self) -> &mut Usage {
        (**self).usage_mut()
    }
    fn timeout(&self) -> i64 {
        (**self).timeout()
    }
    fn set_timeout(&mut self, timeout: i64) {
        (**self).set_timeout(timeout)
    }
}

impl<A: Adapter + ?Sized> Adapter for &mut A {
    fn name(&self) -> &str {
        (**self).name()
    }
    fn send<'a>(
        &'a mut self,
        profile: &'a Profile,
        messages: &'a [Message],
        listener: Option<Listener<'a>>,
    ) -> BoxFuture<'a, Result<Message, Error>> {
        (**self).send(profile, messages, listener)
    }
    fn models(&self) -> &'static [&'static str] {
        (**self).models()
    }
    fn model(&self) -> &str {
        (**self).model()
    }
    fn set_model(&mut self, model: &str) -> Result<(), Error> {
        (**self).set_model(model)
    }
    fn is_schema_supported(&self) -> bool {
        (**self).is_schema_supported()
    }
    fn supports_embeddings(&self) -> bool {
        (**self).supports_embeddings()
    }
    fn embed<'a>(&'a mut self, text: &'a str) -> BoxFuture<'a, Result<Embedding, Error>> {
        (**self).embed(text)
    }
    fn bulk_embed<'a>(&'a mut self, texts: &'a [String]) -> BoxFuture<'a, Result<Embeddings, Error>> {
        (**self).bulk_embed(texts)
    }
    fn embedding_dimension(&self) -> Result<i64, Error> {
        (**self).embedding_dimension()
    }
    fn base(&self) -> &Base {
        (**self).base()
    }
    fn base_mut(&mut self) -> &mut Base {
        (**self).base_mut()
    }
    fn supports_attachments(&self) -> bool {
        (**self).supports_attachments()
    }
    fn supports_attachment(&self, attachment: &Message) -> bool {
        (**self).supports_attachment(attachment)
    }
    fn max_attachments_per_message(&self) -> Option<i64> {
        (**self).max_attachments_per_message()
    }
    fn max_attachment_bytes(&self) -> Option<i64> {
        (**self).max_attachment_bytes()
    }
    fn max_total_attachment_bytes(&self) -> Option<i64> {
        (**self).max_total_attachment_bytes()
    }
    fn allowed_attachment_mime_types(&self) -> Option<std::borrow::Cow<'_, [&str]>> {
        (**self).allowed_attachment_mime_types()
    }
    fn usage(&self) -> &Usage {
        (**self).usage()
    }
    fn usage_mut(&mut self) -> &mut Usage {
        (**self).usage_mut()
    }
    fn timeout(&self) -> i64 {
        (**self).timeout()
    }
    fn set_timeout(&mut self, timeout: i64) {
        (**self).set_timeout(timeout)
    }
}

/// Upper bound for retained incomplete SSE fragments.
///
/// PHP: `Adapter::STREAM_BUFFER_MAX_BYTES`.
pub const STREAM_BUFFER_MAX_BYTES: usize = 1_048_576;

/// The state every adapter keeps: token counts, the request timeout, the
/// HTTP client, and the fragment of a streamed line still incomplete.
///
/// PHP: the protected properties of `Utopia\Agents\Adapter`.
#[derive(Clone)]
pub struct Base {
    usage: Usage,
    timeout: i64,
    client: Option<Arc<dyn Transport>>,
    owns_client: bool,
    buffer: Vec<u8>,
}

impl std::fmt::Debug for Base {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Base")
            .field("usage", &self.usage)
            .field("timeout", &self.timeout)
            .field("client", &self.client.is_some())
            .field("owns_client", &self.owns_client)
            .finish_non_exhaustive()
    }
}

impl Default for Base {
    fn default() -> Base {
        Base::new(90_000, None)
    }
}

impl Base {
    /// State with a timeout (milliseconds) and the client to send through
    /// (`None`: one built on first use).
    pub fn new(timeout: i64, client: Option<Arc<dyn Transport>>) -> Base {
        Base { usage: Usage::default(), timeout, client, owns_client: false, buffer: Vec::new() }
    }

    /// The tokens counted so far.
    pub fn usage(&self) -> &Usage {
        &self.usage
    }

    /// The token counters.
    pub fn usage_mut(&mut self) -> &mut Usage {
        &mut self.usage
    }

    /// The timeout, in milliseconds.
    pub fn timeout(&self) -> i64 {
        self.timeout
    }

    /// Sets the timeout; a client built here is rebuilt on next use.
    ///
    /// PHP: `Adapter::setTimeout()`.
    pub fn set_timeout(&mut self, timeout: i64) {
        self.timeout = timeout;
        if self.owns_client {
            self.client = None;
            self.owns_client = false;
        }
    }

    /// Sets the timeout without touching the client, as PHP's constructors
    /// assign `$this->timeout`.
    pub(crate) fn assign_timeout(&mut self, timeout: i64) {
        self.timeout = timeout;
    }

    /// Sends through this client from now on.
    pub fn set_client(&mut self, client: Arc<dyn Transport>) {
        self.client = Some(client);
        self.owns_client = false;
    }

    /// The client: the one given, or a keep-alive client built on first
    /// use with the timeout.
    ///
    /// PHP: `client()` and `defaultClient()`: outside a Swoole coroutine, a
    /// keep-alive cURL client with the timeout. Inside one, PHP pools eight
    /// Swoole clients per adapter; an adapter sends one request at a time
    /// (`send()` takes `&mut self`), so one keep-alive connection serves it.
    pub fn client(&mut self) -> Result<Arc<dyn Transport>, Error> {
        if let Some(client) = &self.client {
            return Ok(Arc::clone(client));
        }
        let client: Arc<dyn Transport> =
            Arc::new(Client::new(Http::new()).with_connection_reuse(true).with_timeout(self.timeout as f64 / 1000.0)?);
        self.client = Some(Arc::clone(&client));
        self.owns_client = true;
        Ok(client)
    }

    /// The JSON POST request PHP's `Utopia\Psr7\Request\Factory::json()`
    /// builds: the payload encoded (`JsonException` on failure), the
    /// headers in order, then `Content-Type` and `Accept` (both
    /// `application/json`) unless given.
    pub(crate) fn request(url: &str, payload: &Value, headers: &[(&str, &str)]) -> Result<Request, Error> {
        let body = json::encode(payload, Flags::THROW_ON_ERROR, json::DEFAULT_DEPTH)
            .map_err(|e| Error::Json { message: e.message().to_owned(), code: e.code().code() })?;
        let mut request = Request::new("POST", url)?;
        for (name, value) in headers {
            request = request.header(name, value)?;
        }
        if !request.headers().has("Content-Type") {
            request = request.header("Content-Type", "application/json")?;
        }
        if !request.headers().has("Accept") {
            request = request.header("Accept", "application/json")?;
        }
        Ok(request.body(body))
    }

    /// Clears the fragment kept from an earlier stream.
    ///
    /// PHP: `beginStreamProcessing()`, `endStreamProcessing()`,
    /// `resetStreamBuffer()`.
    pub(crate) fn reset_stream(&mut self) {
        self.buffer.clear();
    }

    /// The kept fragment as a final line, once the stream ended.
    ///
    /// PHP: `consumeStreamBufferLine()`.
    pub(crate) fn take_stream_line(&mut self) -> Option<Vec<u8>> {
        if self.buffer.is_empty() {
            return None;
        }
        Some(std::mem::take(&mut self.buffer))
    }

    /// Splits the kept fragment and a new chunk into complete lines, keeping
    /// the incomplete last one (its last [`STREAM_BUFFER_MAX_BYTES`]).
    ///
    /// PHP: `prepareStreamLines($chunk)`.
    pub(crate) fn stream_lines(&mut self, chunk: &[u8]) -> Lines {
        let mut combined = std::mem::take(&mut self.buffer);
        combined.extend_from_slice(chunk);
        if !combined.is_empty() && !combined.ends_with(b"\n") {
            let (end, lines) = match combined.iter().rposition(|&b| b == b'\n') {
                Some(newline) => (newline, true),
                None => (0, false),
            };
            let start = if lines { end + 1 } else { 0 };
            let fragment = &combined[start..];
            let keep = fragment.len().saturating_sub(STREAM_BUFFER_MAX_BYTES);
            self.buffer = fragment[keep..].to_vec();
            combined.truncate(end);
            return Lines { data: combined, lines };
        }
        Lines { data: combined, lines: true }
    }
}

/// Complete lines of a stream ([`Base::stream_lines`]).
pub(crate) struct Lines {
    /// The lines joined by `\n`.
    pub data: Vec<u8>,
    /// Whether there is any line (an incomplete chunk alone has none).
    lines: bool,
}

impl Lines {
    /// Each line.
    pub fn iter(&self) -> impl Iterator<Item = &[u8]> {
        let lines = if self.lines { Some(self.data.split(|&b| b == b'\n')) } else { None };
        lines.into_iter().flatten()
    }
}

/// `trim()` with PHP's default characters.
pub(crate) fn trim(s: &[u8]) -> &[u8] {
    php_std::string::trim(s, php_std::string::TRIM_CHARACTERS)
}

/// `Adapter::decodeSseJsonLine()`: the JSON object of an SSE `data: ` line.
pub(crate) fn sse_json(line: &[u8]) -> Option<Value> {
    if trim(line).is_empty() {
        return None;
    }
    let payload = line.strip_prefix(b"data: ")?;
    if trim(payload) == b"[DONE]" {
        return None;
    }
    crate::php::decode_object(payload)
}

/// `Adapter::decodeJsonOrSseLine()`: the JSON object of a raw JSON line or
/// an SSE `data: ` line.
pub(crate) fn json_or_sse(line: &[u8]) -> Option<Value> {
    if trim(line).is_empty() {
        return None;
    }
    let payload = line.strip_prefix(b"data: ").unwrap_or(line);
    if trim(payload) == b"[DONE]" {
        return None;
    }
    crate::php::decode_object(payload)
}

/// `Adapter::appendStreamToken()`: adds a token to the answer and passes it
/// to the listener.
pub(crate) fn append_token(block: &mut Vec<u8>, token: &[u8], listener: &mut Option<Listener<'_>>) {
    if token.is_empty() {
        return;
    }
    block.extend_from_slice(token);
    if let Some(listener) = listener {
        listener(&String::from_utf8_lossy(token));
    }
}

/// `ucfirst($name).' API error: '.$content` with the status as the code.
pub(crate) fn api_error(name: &str, content: &[u8], status: u16) -> Error {
    let name = php_std::string::ucfirst(name.as_bytes());
    let mut message = String::from_utf8_lossy(&name).into_owned();
    message.push_str(" API error: ");
    message.push_str(&String::from_utf8_lossy(content));
    Error::Exception { message, code: i64::from(status) }
}

/// The error of an adapter that does not embed.
pub(crate) fn no_embeddings() -> Error {
    Error::exception("Embeddings are not supported for this adapter.")
}

/// `$this->isImageAttachment($attachment)`: non-empty content of an
/// `image/*` type.
pub(crate) fn is_image(attachment: &Message) -> bool {
    !attachment.content().is_empty() && attachment.mime_type().is_some_and(|m| m.starts_with("image/"))
}
