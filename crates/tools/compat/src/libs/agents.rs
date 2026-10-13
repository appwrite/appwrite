//! Compat adapter for `agents`: maps `tests/compat/agents/spec.json`
//! operations onto `utopia-agents`. Providers are never called: requests go
//! to a recording queue client or to the `agents-http` fixture server.
//!
//! PHP objects refer to each other (an adapter's agent, a conversation's
//! agent); here an agent owns its adapter and a conversation its agent. Each
//! adapter lives in a slot that an agent and then a conversation take over,
//! and the handles of the adapter, the agent and the conversation all reach
//! it there.

use std::borrow::Cow;
use std::collections::VecDeque;
use std::sync::{Arc, Mutex};

use base64::Engine as _;
use base64::engine::general_purpose::STANDARD as BASE64;
use php_std::Value as Php;
use php_std::types::{ArrayKey, KeyRef};
use serde_json::{Map, Value, json};
use utopia_agents::adapter::{BoxFuture, Embedding, Embeddings, Listener};
use utopia_agents::adapters::openrouter::models;
use utopia_agents::diff_check::{Options, Repository, Runner, Source};
use utopia_agents::{
    Adapter, Agent, Anthropic, Appwrite, Assistant, Base, Conversation, Deepseek, DiffCheck, Error, Gemini,
    Instruction, Message, Ollama, OpenAi, OpenRouter, Perplexity, Profile, Role, Schema, SchemaObject, Transport, User,
    Xai,
};
use utopia_client::{Client, Http, Request, Response, Uri};

use crate::adapter::{Args, Fault, OpResult, Outcome, Session, bytes, bytes_value, float, float_value};

/// Operations this adapter implements (must match `spec.json`).
pub const OPS: &[&str] = &[
    "message.inspect",
    "message.with_role",
    "message.mime",
    "role.inspect",
    "role.constants",
    "schema_object.new",
    "schema_object.add",
    "schema_object.remove",
    "schema_object.inspect",
    "schema_object.validate",
    "schema_object.valid_types",
    "schema.new",
    "schema.inspect",
    "schema.to_json",
    "adapter.new",
    "adapter.inspect",
    "adapter.set_model",
    "adapter.set",
    "adapter.count",
    "adapter.supports_attachment",
    "adapter.embedding_dimension",
    "adapter.agent",
    "adapter.send",
    "adapter.embed",
    "adapter.bulk_embed",
    "adapter.requests",
    "fake.inspect",
    "agent.new",
    "agent.set_description",
    "agent.set_instructions",
    "agent.add_instruction",
    "agent.set_schema",
    "agent.inspect",
    "agent.embed",
    "agent.bulk_embed",
    "conversation.new",
    "conversation.listen",
    "conversation.message",
    "conversation.send",
    "conversation.count",
    "conversation.inspect",
    "fixture.queue",
    "fixture.requests",
    "fs.write",
    "fs.git",
    "diff.run",
    "diff.options",
    "diff.repository",
    "stream.parse",
    "sync.run",
    "data.digest",
];

// ---------------------------------------------------------------------------
// Values
// ---------------------------------------------------------------------------

/// A PHP value from case JSON, decoded as the PHP driver decodes it
/// (`$bytes` and `$float` tags, empty objects as `stdClass`).
fn php(value: &Value) -> Php {
    match value {
        Value::Object(o) if o.len() == 1 && o.contains_key("$bytes") => {
            Php::Str(bytes(value).unwrap_or_default().into())
        }
        Value::Object(o) if o.len() == 1 && o.contains_key("$float") => Php::Float(float(value).unwrap_or(0.0)),
        Value::Object(o) if o.is_empty() => Php::Object(php_std::Array::new()),
        Value::Object(o) => {
            Php::Array(o.iter().map(|(k, v)| (ArrayKey::normalize(php_std::Str::from(k.as_str())), php(v))).collect())
        }
        Value::Array(a) => Php::Array(a.iter().map(php).collect()),
        other => Php::from_json(other),
    }
}

/// A PHP value as the PHP driver encodes it.
fn json(value: &Php) -> Value {
    match value {
        Php::Null => Value::Null,
        Php::Bool(b) => Value::Bool(*b),
        Php::Int(i) => json!(i),
        Php::Float(f) => float_value(*f),
        Php::Str(s) => bytes_value(s.as_bytes()),
        Php::Array(a) if a.is_list() => Value::Array(a.values().map(json).collect()),
        Php::Array(a) | Php::Object(a) => {
            let mut map = Map::with_capacity(a.len());
            for (k, v) in a.iter() {
                let key = match k {
                    KeyRef::Int(i) => i.to_string(),
                    KeyRef::Str(s) => String::from_utf8_lossy(s).into_owned(),
                };
                map.insert(key, json(v));
            }
            Value::Object(map)
        }
        Php::Ext(_) => Value::Null,
    }
}

fn php_array(value: &Value) -> php_std::Array {
    match php(value) {
        Php::Array(a) | Php::Object(a) => a,
        _ => php_std::Array::new(),
    }
}

fn err(e: Error) -> OpResult {
    Ok(Outcome::err(e.php_class(), e.to_string()))
}

macro_rules! tri {
    ($e:expr) => {
        match $e {
            Ok(v) => v,
            Err(e) => return err(e),
        }
    };
}

/// A message from its description: a string (its content), or {content,
/// role?, attachments?, add?, set?}.
fn message(spec: &Value) -> Result<Message, Fault> {
    if spec.is_string() || spec.get("$bytes").is_some() {
        return Ok(Message::new(bytes(spec).unwrap_or_default()));
    }
    let content = bytes(spec.get("content").ok_or_else(|| Fault::new("message without content"))?)
        .ok_or_else(|| Fault::new("message content must be a string"))?;
    let list = |key: &str| -> Result<Vec<Message>, Fault> {
        spec.get(key).and_then(Value::as_array).into_iter().flatten().map(message).collect()
    };
    let mut m = Message::with(content, spec.get("role").and_then(Value::as_str), list("attachments")?);
    for attachment in list("add")? {
        m.add_attachment(attachment);
    }
    if spec.get("set").is_some() {
        m.set_attachments(list("set")?);
    }
    Ok(m)
}

fn encode(m: &Message) -> Value {
    json!({
        "role": m.role(),
        "content": bytes_value(m.content()),
        "mime": m.mime_type(),
        "hasAttachments": m.has_attachments(),
        "attachments": m.attachments().iter().map(encode).collect::<Vec<_>>(),
    })
}

enum Participant {
    User(User),
    Assistant(Assistant),
}

impl Participant {
    fn role(&mut self) -> &mut dyn Role {
        match self {
            Participant::User(u) => u,
            Participant::Assistant(a) => a,
        }
    }
}

fn participant(a: &Args<'_>) -> Result<Participant, Fault> {
    let id = a.str("id")?;
    let name = a.opt_str("name")?.unwrap_or("");
    Ok(match a.str("kind")? {
        "user" => Participant::User(User::new(id, name)),
        "assistant" => Participant::Assistant(Assistant::new(id, name)),
        other => return Err(Fault::new(format!("unknown role kind {other}"))),
    })
}

fn tokens(usage: &utopia_agents::Usage) -> Value {
    json!({
        "input": usage.input(),
        "output": usage.output(),
        "cacheCreation": usage.cache_creation_input(),
        "cacheRead": usage.cache_read_input(),
        "total": usage.total(),
    })
}

fn embedding(e: &Embedding) -> Value {
    let mut out = json!({
        "embedding": json(&Php::Array(e.embedding.clone())),
        "tokensProcessed": e.tokens_processed,
        "totalDuration": e.total_duration,
    });
    if let Some(loading) = e.model_loading_duration {
        out["modelLoadingDuration"] = json!(loading);
    }
    out
}

fn embeddings(e: &Embeddings) -> Value {
    json!({
        "embeddings": e.embeddings.iter().map(|v| json(&Php::Array(v.clone()))).collect::<Vec<_>>(),
        "tokensProcessed": e.tokens_processed,
        "totalDuration": e.total_duration,
    })
}

// ---------------------------------------------------------------------------
// Clients
// ---------------------------------------------------------------------------

/// A response a case queues: {status?, body?, chunks?}.
struct Canned {
    status: u16,
    body: Vec<u8>,
    chunks: Vec<Vec<u8>>,
}

fn canned(spec: &Value) -> Canned {
    Canned {
        status: spec.get("status").and_then(Value::as_u64).unwrap_or(200) as u16,
        body: spec.get("body").and_then(bytes).unwrap_or_default(),
        chunks: spec.get("chunks").and_then(Value::as_array).into_iter().flatten().filter_map(bytes).collect(),
    }
}

enum Mode {
    /// Canned responses, chunks delivered to the sink as given.
    Queue(Mutex<VecDeque<Canned>>),
    /// The fixture server: the URI becomes `<base><path>?<query>`.
    Forward { base: String, client: Box<Client<Http>> },
}

/// Keeps every request an adapter sends, as the adapter built it. Its twin
/// is `Recording` in tests/compat/agents/Clients.php.
struct Recorded {
    requests: Mutex<Vec<Request>>,
    mode: Mode,
}

impl Recorded {
    fn redirect(base: &str, request: Request) -> Result<Request, utopia_client::Error> {
        let uri = request.uri();
        let target = format!(
            "{base}{}{}",
            uri.path(),
            if uri.query().is_empty() { String::new() } else { format!("?{}", uri.query()) }
        );
        Ok(request.with_uri(Uri::parse(&target)?))
    }

    fn next(&self) -> Result<Canned, utopia_client::Error> {
        match &self.mode {
            Mode::Queue(queue) => queue
                .lock()
                .unwrap_or_else(|e| e.into_inner())
                .pop_front()
                .ok_or_else(|| utopia_client::Error::Request("no response queued for this request".into())),
            Mode::Forward { .. } => unreachable!(),
        }
    }

    fn recorded(&self) -> Value {
        let requests = self.requests.lock().unwrap_or_else(|e| e.into_inner());
        Value::Array(
            requests
                .iter()
                .map(|r| {
                    let headers: Vec<Value> = r
                        .headers()
                        .iter()
                        .filter(|(name, _)| !name.eq_ignore_ascii_case("Host"))
                        .map(|(name, values)| json!([name, values.join(", ")]))
                        .collect();
                    let body = match r.get_body() {
                        utopia_client::Body::Bytes(b) => b.to_vec(),
                        _ => Vec::new(),
                    };
                    json!({ "method": r.method(), "uri": r.uri().to_string(), "headers": headers, "body": bytes_value(&body) })
                })
                .collect(),
        )
    }
}

impl Transport for Recorded {
    fn send(&self, request: Request) -> BoxFuture<'_, Result<Response, utopia_client::Error>> {
        Box::pin(async move {
            self.requests.lock().unwrap_or_else(|e| e.into_inner()).push(request.clone());
            match &self.mode {
                Mode::Queue(_) => {
                    let canned = self.next()?;
                    Ok(Response::new(canned.status)?.with_body(canned.body))
                }
                Mode::Forward { base, client } => client.send(Recorded::redirect(base, request)?).await,
            }
        })
    }

    fn stream<'a>(
        &'a self,
        request: Request,
        sink: &'a mut (dyn FnMut(&[u8]) + Send),
    ) -> BoxFuture<'a, Result<Response, utopia_client::Error>> {
        Box::pin(async move {
            self.requests.lock().unwrap_or_else(|e| e.into_inner()).push(request.clone());
            match &self.mode {
                Mode::Queue(_) => {
                    let canned = self.next()?;
                    for chunk in &canned.chunks {
                        sink(chunk);
                    }
                    Response::new(canned.status)
                }
                Mode::Forward { base, client } => {
                    let mut forward = |chunk: &[u8]| sink(chunk);
                    client.stream(Recorded::redirect(base, request)?, &mut forward).await
                }
            }
        })
    }
}

// ---------------------------------------------------------------------------
// The fake adapter
// ---------------------------------------------------------------------------

#[derive(Default)]
struct FakeState {
    send_calls: i64,
    last: Option<Message>,
}

/// The fake adapters of the library's tests in one. Its twin is `Fake` in
/// tests/compat/agents/Fake.php.
struct Fake {
    base: Base,
    name: String,
    response: Vec<u8>,
    schema: bool,
    images: bool,
    dimension: Option<i64>,
    max_per_message: Option<i64>,
    max_bytes: Option<i64>,
    max_total: Option<i64>,
    mimes: Option<Vec<String>>,
    state: Arc<Mutex<FakeState>>,
}

impl Fake {
    fn new(config: &Value) -> Fake {
        let int = |key: &str| config.get(key).and_then(Value::as_i64);
        Fake {
            base: Base::default(),
            name: config.get("name").and_then(Value::as_str).unwrap_or("fake").to_owned(),
            response: config.get("response").and_then(bytes).unwrap_or_default(),
            schema: config.get("schema").and_then(Value::as_bool).unwrap_or(false),
            images: config.get("attachments").and_then(Value::as_str) == Some("images"),
            dimension: int("dimension"),
            max_per_message: int("maxAttachmentsPerMessage"),
            max_bytes: int("maxAttachmentBytes"),
            max_total: int("maxTotalAttachmentBytes"),
            mimes: config
                .get("allowedAttachmentMimeTypes")
                .and_then(Value::as_array)
                .map(|m| m.iter().filter_map(Value::as_str).map(str::to_owned).collect()),
            state: Arc::default(),
        }
    }
}

fn unsupported() -> Error {
    Error::Exception { message: "Embeddings not supported".into(), code: 0 }
}

impl Adapter for Fake {
    fn name(&self) -> &str {
        &self.name
    }

    fn send<'a>(
        &'a mut self,
        _profile: &'a Profile,
        messages: &'a [Message],
        _listener: Option<Listener<'a>>,
    ) -> BoxFuture<'a, Result<Message, Error>> {
        Box::pin(async move {
            let mut state = self.state.lock().unwrap_or_else(|e| e.into_inner());
            state.send_calls += 1;
            state.last = messages.last().cloned();
            Ok(Message::new(self.response.clone()))
        })
    }

    fn models(&self) -> &'static [&'static str] {
        &["fake-model"]
    }

    fn model(&self) -> &str {
        "fake-model"
    }

    fn set_model(&mut self, _model: &str) -> Result<(), Error> {
        Ok(())
    }

    fn is_schema_supported(&self) -> bool {
        self.schema
    }

    fn supports_embeddings(&self) -> bool {
        false
    }

    fn embed<'a>(&'a mut self, _text: &'a str) -> BoxFuture<'a, Result<Embedding, Error>> {
        Box::pin(async { Err(unsupported()) })
    }

    fn bulk_embed<'a>(&'a mut self, _texts: &'a [String]) -> BoxFuture<'a, Result<Embeddings, Error>> {
        Box::pin(async { Err(unsupported()) })
    }

    fn embedding_dimension(&self) -> Result<i64, Error> {
        self.dimension.ok_or_else(unsupported)
    }

    fn base(&self) -> &Base {
        &self.base
    }

    fn base_mut(&mut self) -> &mut Base {
        &mut self.base
    }

    fn supports_attachments(&self) -> bool {
        self.images
    }

    fn supports_attachment(&self, attachment: &Message) -> bool {
        if !self.images {
            return self.supports_attachments();
        }
        attachment.mime_type().is_some_and(|m| m.starts_with("image/"))
    }

    fn max_attachments_per_message(&self) -> Option<i64> {
        self.max_per_message
    }

    fn max_attachment_bytes(&self) -> Option<i64> {
        self.max_bytes
    }

    fn max_total_attachment_bytes(&self) -> Option<i64> {
        self.max_total
    }

    fn allowed_attachment_mime_types(&self) -> Option<Cow<'_, [&str]>> {
        self.mimes.as_ref().map(|m| Cow::Owned(m.iter().map(String::as_str).collect()))
    }
}

// ---------------------------------------------------------------------------
// Adapters of every kind
// ---------------------------------------------------------------------------

/// The adapter of a slot, of any kind the cases build.
enum Any {
    OpenAi(OpenAi),
    Anthropic(Anthropic),
    Gemini(Gemini),
    Deepseek(Deepseek),
    OpenRouter(OpenRouter),
    Perplexity(Perplexity),
    Xai(Xai),
    Ollama(Ollama),
    Appwrite(Appwrite),
    Fake(Fake),
}

macro_rules! each {
    ($self:expr, $a:ident => $e:expr) => {
        match $self {
            Any::OpenAi($a) => $e,
            Any::Anthropic($a) => $e,
            Any::Gemini($a) => $e,
            Any::Deepseek($a) => $e,
            Any::OpenRouter($a) => $e,
            Any::Perplexity($a) => $e,
            Any::Xai($a) => $e,
            Any::Ollama($a) => $e,
            Any::Appwrite($a) => $e,
            Any::Fake($a) => $e,
        }
    };
}

impl Adapter for Any {
    fn name(&self) -> &str {
        each!(self, a => a.name())
    }
    fn send<'a>(
        &'a mut self,
        profile: &'a Profile,
        messages: &'a [Message],
        listener: Option<Listener<'a>>,
    ) -> BoxFuture<'a, Result<Message, Error>> {
        each!(self, a => a.send(profile, messages, listener))
    }
    fn models(&self) -> &'static [&'static str] {
        each!(self, a => a.models())
    }
    fn model(&self) -> &str {
        each!(self, a => a.model())
    }
    fn set_model(&mut self, model: &str) -> Result<(), Error> {
        each!(self, a => a.set_model(model))
    }
    fn is_schema_supported(&self) -> bool {
        each!(self, a => a.is_schema_supported())
    }
    fn supports_embeddings(&self) -> bool {
        each!(self, a => a.supports_embeddings())
    }
    fn embed<'a>(&'a mut self, text: &'a str) -> BoxFuture<'a, Result<Embedding, Error>> {
        each!(self, a => a.embed(text))
    }
    fn bulk_embed<'a>(&'a mut self, texts: &'a [String]) -> BoxFuture<'a, Result<Embeddings, Error>> {
        each!(self, a => a.bulk_embed(texts))
    }
    fn embedding_dimension(&self) -> Result<i64, Error> {
        each!(self, a => a.embedding_dimension())
    }
    fn base(&self) -> &Base {
        each!(self, a => a.base())
    }
    fn base_mut(&mut self) -> &mut Base {
        each!(self, a => a.base_mut())
    }
    fn supports_attachments(&self) -> bool {
        each!(self, a => a.supports_attachments())
    }
    fn supports_attachment(&self, attachment: &Message) -> bool {
        each!(self, a => a.supports_attachment(attachment))
    }
    fn max_attachments_per_message(&self) -> Option<i64> {
        each!(self, a => a.max_attachments_per_message())
    }
    fn max_attachment_bytes(&self) -> Option<i64> {
        each!(self, a => a.max_attachment_bytes())
    }
    fn max_total_attachment_bytes(&self) -> Option<i64> {
        each!(self, a => a.max_total_attachment_bytes())
    }
    fn allowed_attachment_mime_types(&self) -> Option<Cow<'_, [&str]>> {
        each!(self, a => a.allowed_attachment_mime_types())
    }
}

impl Any {
    fn endpoint(&self) -> Option<&str> {
        Some(match self {
            Any::OpenAi(a) => a.endpoint(),
            Any::Gemini(a) => a.endpoint(),
            Any::OpenRouter(a) => a.endpoint(),
            Any::Perplexity(a) => a.endpoint(),
            Any::Xai(a) => a.endpoint(),
            Any::Ollama(a) => a.endpoint(),
            Any::Appwrite(a) => a.endpoint(),
            _ => return None,
        })
    }

    fn set_endpoint(&mut self, endpoint: &str) -> Result<(), Fault> {
        match self {
            Any::OpenAi(a) => drop(a.set_endpoint(endpoint)),
            Any::Gemini(a) => drop(a.set_endpoint(endpoint)),
            Any::OpenRouter(a) => drop(a.set_endpoint(endpoint)),
            Any::Perplexity(a) => drop(a.set_endpoint(endpoint)),
            Any::Xai(a) => drop(a.set_endpoint(endpoint)),
            Any::Ollama(a) => drop(a.set_endpoint(endpoint)),
            Any::Appwrite(a) => drop(a.set_endpoint(endpoint)),
            _ => return Err(Fault::new("the adapter has no setEndpoint()")),
        }
        Ok(())
    }

    fn set_max_tokens(&mut self, tokens: i64) -> Result<(), Fault> {
        match self {
            Any::OpenAi(a) => drop(a.set_max_tokens(tokens)),
            Any::Anthropic(a) => drop(a.set_max_tokens(tokens)),
            Any::Gemini(a) => drop(a.set_max_tokens(tokens)),
            Any::Deepseek(a) => drop(a.set_max_tokens(tokens)),
            Any::OpenRouter(a) => drop(a.set_max_tokens(tokens)),
            Any::Perplexity(a) => drop(a.set_max_tokens(tokens)),
            Any::Xai(a) => drop(a.set_max_tokens(tokens)),
            _ => return Err(Fault::new("the adapter has no setMaxTokens()")),
        }
        Ok(())
    }

    fn set_temperature(&mut self, temperature: f64) -> Result<(), Fault> {
        match self {
            Any::OpenAi(a) => drop(a.set_temperature(temperature)),
            Any::Anthropic(a) => drop(a.set_temperature(temperature)),
            Any::Gemini(a) => drop(a.set_temperature(temperature)),
            Any::Deepseek(a) => drop(a.set_temperature(temperature)),
            Any::OpenRouter(a) => drop(a.set_temperature(temperature)),
            Any::Perplexity(a) => drop(a.set_temperature(temperature)),
            Any::Xai(a) => drop(a.set_temperature(temperature)),
            _ => return Err(Fault::new("the adapter has no setTemperature()")),
        }
        Ok(())
    }
}

/// The fixture server's serve route for this run.
fn serve(session: &Session) -> Result<String, Fault> {
    Ok(format!("{}/serve/{}", session.service("agents-http")?, session.ns))
}

/// `${fixture}` in an endpoint: the serve route.
fn fixture(value: &str, session: &Session) -> Result<String, Fault> {
    Ok(value.replace("${fixture}", &serve(session)?))
}

/// The serve route back to `${fixture}` in every string.
fn unfixture(value: Value, session: &Session) -> Value {
    let Ok(base) = serve(session) else { return value };
    match value {
        Value::String(s) => Value::String(s.replace(&base, "${fixture}")),
        Value::Array(a) => Value::Array(a.into_iter().map(|v| unfixture(v, session)).collect()),
        Value::Object(o) => Value::Object(o.into_iter().map(|(k, v)| (k, unfixture(v, session))).collect()),
        other => other,
    }
}

/// `adapter.new`: the adapter and its recording client, if any.
/// An adapter and its recording client, or the library's error.
type Built = Result<(Any, Option<Arc<Recorded>>), Error>;

fn build(a: &Args<'_>, session: &Session) -> Result<Built, Fault> {
    let args = a.opt("args").cloned().unwrap_or_else(|| json!({}));
    let get = |k: &str| args.get(k).filter(|v| !v.is_null());
    let timeout = get("timeout").and_then(Value::as_i64).unwrap_or(90_000);
    let recorded = match a.opt("client") {
        None => None,
        Some(Value::String(s)) if s == "forward" => {
            let client = Client::new(Http::new())
                .with_connection_reuse(true)
                .with_timeout(timeout as f64 / 1000.0)
                .map_err(|e| Fault::new(e.to_string()))?;
            Some(Arc::new(Recorded {
                requests: Mutex::default(),
                mode: Mode::Forward { base: serve(session)?, client: Box::new(client) },
            }))
        }
        Some(spec) => {
            let queue = spec.get("queue").and_then(Value::as_array).into_iter().flatten().map(canned).collect();
            Some(Arc::new(Recorded { requests: Mutex::default(), mode: Mode::Queue(Mutex::new(queue)) }))
        }
    };
    let transport = recorded.clone().map(|r| r as Arc<dyn Transport>);
    let endpoint = get("endpoint").and_then(Value::as_str).map(|e| fixture(e, session)).transpose()?;
    let key = get("apiKey").and_then(Value::as_str).unwrap_or("test-api-key");
    let model = get("model").and_then(Value::as_str);
    let tokens = get("maxTokens").and_then(Value::as_i64).unwrap_or(1024);
    let temperature = get("temperature").and_then(float).unwrap_or(1.0);

    macro_rules! chat {
        ($ty:ident, $variant:ident, endpoint) => {{
            let mut adapter = $ty::new(key).with_max_tokens(tokens).with_temperature(temperature).with_timeout(timeout);
            if let Some(model) = model {
                adapter = adapter.with_model(model);
            }
            if let Some(endpoint) = &endpoint {
                adapter = adapter.with_endpoint(endpoint);
            }
            if let Some(client) = transport.clone() {
                adapter = adapter.with_client(client);
            }
            Any::$variant(adapter)
        }};
        ($ty:ident, $variant:ident) => {{
            let mut adapter = $ty::new(key).with_max_tokens(tokens).with_temperature(temperature).with_timeout(timeout);
            if let Some(model) = model {
                adapter = adapter.with_model(model);
            }
            if let Some(client) = transport.clone() {
                adapter = adapter.with_client(client);
            }
            Any::$variant(adapter)
        }};
    }

    let adapter = match a.str("kind")? {
        "openai" => chat!(OpenAi, OpenAi, endpoint),
        "anthropic" => chat!(Anthropic, Anthropic),
        "gemini" => chat!(Gemini, Gemini, endpoint),
        "deepseek" => chat!(Deepseek, Deepseek),
        "perplexity" => chat!(Perplexity, Perplexity, endpoint),
        "xai" => chat!(Xai, Xai, endpoint),
        "openrouter" => {
            let mut adapter = OpenRouter::new(key)
                .with_max_tokens(tokens)
                .with_temperature(temperature)
                .with_timeout(timeout)
                .with_app(get("httpReferer").and_then(Value::as_str), get("xTitle").and_then(Value::as_str));
            if let Some(model) = model {
                adapter = adapter.with_model(model);
            }
            if let Some(endpoint) = &endpoint {
                adapter = adapter.with_endpoint(endpoint);
            }
            if let Some(client) = transport.clone() {
                adapter = adapter.with_client(client);
            }
            Any::OpenRouter(adapter)
        }
        "ollama" => {
            let mut adapter =
                match Ollama::with_model(model.unwrap_or(utopia_agents::adapters::ollama::MODEL_EMBEDDING_GEMMA)) {
                    Ok(adapter) => adapter,
                    Err(e) => return Ok(Err(e)),
                };
            if let Some(client) = transport.clone() {
                adapter = adapter.with_client(client);
            }
            Any::Ollama(adapter.with_timeout(timeout))
        }
        "appwrite" => {
            let mut adapter = match Appwrite::with_model(
                model.unwrap_or(utopia_agents::adapters::appwrite::MODEL_NOMIC_EMBED_TEXT),
            ) {
                Ok(adapter) => adapter,
                Err(e) => return Ok(Err(e)),
            };
            if let Some(client) = transport.clone() {
                adapter = adapter.with_client(client);
            }
            Any::Appwrite(adapter.with_timeout(timeout))
        }
        "fake" => Any::Fake(Fake::new(a.opt("fake").unwrap_or(&Value::Null))),
        other => return Err(Fault::new(format!("unknown adapter kind {other}"))),
    };
    Ok(Ok((adapter, recorded)))
}

// ---------------------------------------------------------------------------
// Slots
// ---------------------------------------------------------------------------

enum Holder {
    Adapter(Any),
    Agent(Agent<Any>),
    Conversation(Box<Conversation<Any>>),
    Moved,
}

struct Slot {
    holder: Holder,
    recorded: Option<Arc<Recorded>>,
    listened: Option<Arc<Mutex<Vec<String>>>>,
}

#[derive(Default)]
struct Slots(Vec<Slot>);

/// What a handle of this adapter refers to: its slot.
struct Of(usize);

fn slot<'s>(session: &'s mut Session, handle: &Value) -> Result<&'s mut Slot, Fault> {
    let index = session.get::<Of>(handle)?.0;
    session.state::<Slots>().0.get_mut(index).ok_or_else(|| Fault::new("unknown slot"))
}

impl Slot {
    fn adapter(&mut self) -> Result<&mut Any, Fault> {
        Ok(match &mut self.holder {
            Holder::Adapter(a) => a,
            Holder::Agent(agent) => agent.adapter_mut(),
            Holder::Conversation(c) => c.agent_mut().adapter_mut(),
            Holder::Moved => return Err(Fault::new("moved")),
        })
    }

    fn agent(&mut self) -> Result<&mut Agent<Any>, Fault> {
        match &mut self.holder {
            Holder::Agent(agent) => Ok(agent),
            Holder::Conversation(c) => Ok(c.agent_mut()),
            _ => Err(Fault::new("not an agent")),
        }
    }

    fn conversation(&mut self) -> Result<&mut Conversation<Any>, Fault> {
        match &mut self.holder {
            Holder::Conversation(c) => Ok(c),
            _ => Err(Fault::new("not a conversation")),
        }
    }
}

fn handle_of(session: &mut Session, index: usize) -> Value {
    session.handle(Of(index))
}

fn instructions(value: &Value) -> Vec<(String, Instruction)> {
    value
        .as_array()
        .into_iter()
        .flatten()
        .filter_map(|pair| {
            let name = pair.get(0)?.as_str()?.to_owned();
            let content = match pair.get(1)? {
                Value::Array(lines) => {
                    Instruction::Lines(lines.iter().filter_map(Value::as_str).map(str::to_owned).collect())
                }
                other => Instruction::Text(other.as_str()?.to_owned()),
            };
            Some((name, content))
        })
        .collect()
}

fn instruction_json(content: &Instruction) -> Value {
    match content {
        Instruction::Text(t) => json!(t),
        Instruction::Lines(lines) => json!(lines),
    }
}

// ---------------------------------------------------------------------------
// Diff checks, files, the fixture server, the sync task
// ---------------------------------------------------------------------------

/// macOS's /tmp is /private/tmp: real paths of the shared /tmp/compat-fs
/// read as PHP's container reads them.
fn untmp(text: &str) -> String {
    text.replace("/private/tmp/", "/tmp/")
}

/// Prompts DiffCheck built name its temporary copies with random suffixes.
fn untemp(text: &str) -> String {
    let mut out = String::with_capacity(text.len());
    let mut rest = text;
    while let Some(at) = rest.find("utopia-diff-check-") {
        out.push_str(&rest[..at]);
        let tail = &rest[at + "utopia-diff-check-".len()..];
        let side = ["base-", "target-"].into_iter().find(|s| tail.starts_with(s));
        match side {
            Some(side)
                if tail.len() >= side.len() + 16
                    && tail[side.len()..side.len() + 16]
                        .bytes()
                        .all(|b| b.is_ascii_digit() || (b'a'..=b'f').contains(&b)) =>
            {
                out.push_str("utopia-diff-check-");
                out.push_str(side);
                out.push_str("<random>");
                rest = &tail[side.len() + 16..];
            }
            _ => {
                out.push_str("utopia-diff-check-");
                rest = tail;
            }
        }
    }
    out.push_str(rest);
    out
}

fn git(a: &Args<'_>) -> Result<Value, Fault> {
    let dir = a.str("dir")?;
    std::fs::create_dir_all(dir).map_err(|e| Fault::new(e.to_string()))?;
    let run = |args: &[&str]| -> Result<(), Fault> {
        let status = std::process::Command::new("git")
            .args(args)
            .current_dir(dir)
            .env_clear()
            .env("PATH", std::env::var("PATH").unwrap_or_default())
            .envs([
                ("GIT_AUTHOR_NAME", "Compat"),
                ("GIT_AUTHOR_EMAIL", "compat@example.test"),
                ("GIT_AUTHOR_DATE", "2024-01-01T00:00:00Z"),
                ("GIT_COMMITTER_NAME", "Compat"),
                ("GIT_COMMITTER_EMAIL", "compat@example.test"),
                ("GIT_COMMITTER_DATE", "2024-01-01T00:00:00Z"),
                ("GIT_CONFIG_GLOBAL", "/dev/null"),
                ("GIT_CONFIG_NOSYSTEM", "1"),
                ("HOME", "/tmp"),
            ])
            .stdout(std::process::Stdio::null())
            .stderr(std::process::Stdio::null())
            .status()
            .map_err(|e| Fault::new(e.to_string()))?;
        if !status.success() {
            return Err(Fault::new(format!("git failed: {}", args.join(" "))));
        }
        Ok(())
    };
    run(&["init", "-q", "-b", "main"])?;
    for commit in a.array("commits")? {
        if let Some(branch) = commit.get("branch").and_then(Value::as_str) {
            run(&["checkout", "-q", "-B", branch])?;
        }
        write(dir, commit.get("files").unwrap_or(&Value::Null))?;
        run(&["add", "-A"])?;
        run(&["commit", "-q", "-m", commit.get("message").and_then(Value::as_str).unwrap_or("commit")])?;
        if let Some(tag) = commit.get("tag").and_then(Value::as_str) {
            run(&["tag", tag])?;
        }
    }
    Ok(Value::Null)
}

fn write(dir: &str, files: &Value) -> Result<(), Fault> {
    for (path, content) in files.as_object().into_iter().flatten() {
        let file = std::path::Path::new(dir).join(path);
        if let Some(parent) = file.parent() {
            std::fs::create_dir_all(parent).map_err(|e| Fault::new(e.to_string()))?;
        }
        std::fs::write(&file, bytes(content).unwrap_or_default()).map_err(|e| Fault::new(e.to_string()))?;
    }
    Ok(())
}

fn source(value: &Value) -> Result<Result<Source, Error>, Fault> {
    if let Some(text) = value.as_str() {
        return Ok(Ok(Source::from(text)));
    }
    let a = Args(value);
    Ok(Repository::new(a.str("source")?, a.opt_bool("remote")?.unwrap_or(false), a.opt_str("ref")?).map(Source::from))
}

/// Options after the setter calls [[method, argument], ...].
fn options(calls: &Value, session: &Session) -> Result<Result<Options, Error>, Fault> {
    let mut options = Options::default();
    for call in calls.as_array().into_iter().flatten() {
        let method = call.get(0).and_then(Value::as_str).ok_or_else(|| Fault::new("option call"))?;
        let argument = call.get(1).unwrap_or(&Value::Null);
        let strings = |v: &Value| -> Vec<String> {
            v.as_array().into_iter().flatten().filter_map(Value::as_str).map(str::to_owned).collect()
        };
        let result = match method {
            "setMaxDiffLines" => options.set_max_diff_lines(argument.as_i64().unwrap_or(0)).map(drop),
            "setExcludePaths" => {
                let paths = strings(argument);
                options.set_exclude_paths(&paths.iter().map(String::as_str).collect::<Vec<_>>());
                Ok(())
            }
            "addExcludePath" => {
                options.add_exclude_path(argument.as_str().unwrap_or(""));
                Ok(())
            }
            "setIgnoreAllSpace" => {
                options.set_ignore_all_space(argument.as_bool().unwrap_or(false));
                Ok(())
            }
            "setIgnoreBlankLines" => {
                options.set_ignore_blank_lines(argument.as_bool().unwrap_or(false));
                Ok(())
            }
            "setSchema" => {
                let schema = if argument.is_null() { None } else { Some(session.get::<Schema>(argument)?.clone()) };
                options.set_schema(schema);
                Ok(())
            }
            "setInstructions" => {
                let pairs = argument
                    .as_array()
                    .into_iter()
                    .flatten()
                    .filter_map(|p| Some((p.get(0)?.as_str()?.to_owned(), p.get(1)?.as_str()?.to_owned())))
                    .collect();
                options.set_instructions(pairs);
                Ok(())
            }
            "addInstruction" => {
                let name = argument.get(0).and_then(Value::as_str).unwrap_or("");
                let content = argument.get(1).and_then(Value::as_str).unwrap_or("");
                options.add_instruction(name, content);
                Ok(())
            }
            "setDescription" => {
                options.set_description(argument.as_str().unwrap_or(""));
                Ok(())
            }
            "setUserId" => options.set_user_id(argument.as_str().unwrap_or("")).map(drop),
            "setTrimResponse" => {
                options.set_trim_response(argument.as_bool().unwrap_or(false));
                Ok(())
            }
            other => return Err(Fault::new(format!("unknown option {other}"))),
        };
        if let Err(e) = result {
            return Ok(Err(e));
        }
    }
    Ok(Ok(options))
}

fn describe(options: &Options) -> Value {
    json!({
        "maxDiffLines": options.max_diff_lines(),
        "excludePaths": options.exclude_paths(),
        "ignoreAllSpace": options.ignore_all_space(),
        "ignoreBlankLines": options.ignore_blank_lines(),
        "schema": options.schema().map(Schema::name),
        "instructions": options.instructions().iter().map(|(n, c)| json!([n, c])).collect::<Vec<_>>(),
        "description": options.description(),
        "userId": options.user_id(),
        "trimResponse": options.trim_response(),
    })
}

/// A request to the fixture server's own routes.
async fn fixture_call(method: &str, path: &str, body: Vec<u8>, session: &Session) -> Result<Vec<u8>, Fault> {
    let client = Client::new(Http::new()).with_timeout(30.0).map_err(|e| Fault::new(e.to_string()))?;
    let request = Request::new(method, &format!("{}{path}", session.service("agents-http")?))
        .and_then(|r| r.header("Content-Type", "application/json"))
        .map_err(|e| Fault::new(e.to_string()))?
        .body(body);
    let response = client
        .send(request)
        .await
        .map_err(|e| Fault::new(format!("the agents-http fixture server did not answer: {e}")))?;
    Ok(response.bytes().to_vec())
}

async fn fixture_requests(session: &Session) -> Result<Vec<Value>, Fault> {
    let body = fixture_call("GET", &format!("/requests/{}", session.ns), Vec::new(), session).await?;
    let recorded: Vec<Value> = serde_json::from_slice(&body).map_err(|e| Fault::new(e.to_string()))?;
    Ok(recorded)
}

/// The catalog the sync task wrote to `dir`: {constants: [[name, id]],
/// models}, or null when it wrote nothing.
fn catalog(dir: &str) -> Value {
    let Ok(constants) = std::fs::read_to_string(format!("{dir}/openrouter-constants.txt")) else { return Value::Null };
    let models = std::fs::read_to_string(format!("{dir}/openrouter-models.txt")).unwrap_or_default();
    let unescape = |s: &str| s.replace("\\n", "\n").replace("\\r", "\r").replace("\\\\", "\\");
    json!({
        "constants": constants
            .lines()
            .filter(|l| !l.is_empty() && !l.starts_with('#'))
            .filter_map(|l| l.split_once(' '))
            .map(|(n, id)| json!([n, unescape(id)]))
            .collect::<Vec<_>>(),
        "models": models.lines().filter(|l| !l.is_empty()).map(unescape).collect::<Vec<_>>(),
    })
}

async fn sync(a: &Args<'_>, session: &Session) -> Result<Value, Fault> {
    let dir = format!("/tmp/compat-fs/{}/agents", session.ns);
    std::fs::create_dir_all(&dir).map_err(|e| Fault::new(e.to_string()))?;
    if let Some(catalog) = a.opt("catalog") {
        let body = serde_json::to_vec(&json!([catalog])).map_err(|e| Fault::new(e.to_string()))?;
        fixture_call("POST", &format!("/queue/{}", session.ns), body, session).await?;
    }
    let server = session.service("agents-http")?.to_owned();
    let env = vec![
        ("OPENROUTER_MODELS_ENDPOINT".to_owned(), format!("{server}/serve/{}/api/v1/models", session.ns)),
        ("OPENROUTER_API_KEY".to_owned(), a.opt_str("key")?.unwrap_or("").to_owned()),
        ("LLM_KEY_OPENROUTER".to_owned(), a.opt_str("fallbackKey")?.unwrap_or("").to_owned()),
    ];
    let out = crate::task::run("utopia-agents", "agents-sync-openrouter-models", std::slice::from_ref(&dir), &env)?;
    let mut result = out.to_json(&[(dir.as_str(), "${output}"), (server.as_str(), "${fixture}")]);
    let requests = fixture_requests(session).await?;
    result["request"] = match requests.first() {
        Some(r) => {
            let find = |name: &str| {
                r["headers"]
                    .as_array()
                    .into_iter()
                    .flatten()
                    .find(|h| h[0].as_str().is_some_and(|n| n.eq_ignore_ascii_case(name)))
                    .map(|h| h[1].clone())
                    .unwrap_or(Value::Null)
            };
            json!({ "method": r["method"], "target": r["target"], "accept": find("Accept"), "authorization": find("Authorization") })
        }
        None => Value::Null,
    };
    result["catalog"] = catalog(&dir);
    Ok(result)
}

/// The catalog as one line per constant then one per model.
fn digest() -> Value {
    use sha2::{Digest, Sha256};
    let mut text = String::new();
    for (name, id) in models::CONSTANTS {
        text.push_str(&format!("{name} {id}\n"));
    }
    text.push_str(&models::MODELS.join("\n"));
    text.push('\n');
    json!({
        "constants": models::CONSTANTS.len(),
        "models": models::MODELS.len(),
        "sha256": format!("{:x}", Sha256::digest(text.as_bytes())),
    })
}

/// `stream.parse`: a streamed answer cut at `cuts`.
async fn parse(a: &Args<'_>) -> Result<Value, Fault> {
    let events = bytes(a.value("events")?).ok_or_else(|| Fault::new("events"))?;
    let mut cuts: Vec<usize> = a
        .opt("cuts")
        .and_then(Value::as_array)
        .into_iter()
        .flatten()
        .filter_map(Value::as_i64)
        .filter(|&c| c > 0 && (c as usize) < events.len())
        .map(|c| c as usize)
        .collect();
    cuts.sort_unstable();
    cuts.dedup();
    let mut chunks = Vec::new();
    let mut start = 0;
    for cut in cuts.into_iter().chain(std::iter::once(events.len())) {
        chunks.push(events[start..cut].to_vec());
        start = cut;
    }
    let status = a.opt_i64("status")?.unwrap_or(200) as u16;
    let recorded = Arc::new(Recorded {
        requests: Mutex::default(),
        mode: Mode::Queue(Mutex::new(VecDeque::from([Canned { status, body: Vec::new(), chunks }]))),
    });
    let client: Arc<dyn Transport> = recorded.clone();
    let model = a.opt_str("model")?;
    let temperature = a.opt_f64("temperature")?.unwrap_or(1.0);
    let max = a.opt_i64("maxTokens")?.unwrap_or(1024);
    macro_rules! configured {
        ($adapter:expr) => {{
            let mut adapter = $adapter.with_max_tokens(max).with_temperature(temperature).with_client(client);
            if let Some(model) = model {
                adapter = adapter.with_model(model);
            }
            adapter
        }};
    }
    let adapter = match a.str("kind")? {
        "openai" => Any::OpenAi(configured!(OpenAi::new("test-api-key"))),
        "openrouter" => Any::OpenRouter(configured!(OpenRouter::new("test-api-key"))),
        "perplexity" => Any::Perplexity(configured!(Perplexity::new("test-api-key"))),
        "xai" => Any::Xai(configured!(Xai::new("test-api-key"))),
        "anthropic" => Any::Anthropic(configured!(Anthropic::new("test-api-key"))),
        "gemini" => Any::Gemini(configured!(Gemini::new("test-api-key"))),
        "deepseek" => Any::Deepseek(configured!(Deepseek::new("test-api-key"))),
        other => return Err(Fault::new(format!("unknown adapter kind {other}"))),
    };
    let mut agent = Agent::new(adapter);
    agent.set_description(a.opt_str("description")?.unwrap_or(""));
    agent.set_instructions(a.opt("instructions").map(instructions).unwrap_or_default());
    let messages: Vec<Message> = match a.opt("messages") {
        Some(list) => list.as_array().into_iter().flatten().map(message).collect::<Result<_, _>>()?,
        None => vec![Message::new("hi")],
    };
    let mut tokens: Vec<String> = Vec::new();
    let mut listener = |token: &str| tokens.push(token.to_owned());
    let result = agent.send(&messages, Some(&mut listener)).await;
    let mut out = match result {
        Ok(message) => json!({ "content": bytes_value(message.content()) }),
        Err(e) => json!({ "error": { "class": e.php_class(), "message": e.to_string(), "code": e.code() } }),
    };
    out["tokens"] = json!(tokens);
    out["usage"] = tokens_of(agent.adapter());
    out["request"] = recorded.recorded().get(0).map_or(Value::Null, |r| r["body"].clone());
    Ok(out)
}

fn tokens_of(adapter: &Any) -> Value {
    tokens(adapter.usage())
}

fn inspect(adapter: &Any, models: bool) -> Value {
    let mut out = json!({
        "name": adapter.name(),
        "model": adapter.model(),
        "schema": adapter.is_schema_supported(),
        "embeddings": adapter.supports_embeddings(),
        "timeout": adapter.timeout(),
        "attachments": {
            "supported": adapter.supports_attachments(),
            "maxPerMessage": adapter.max_attachments_per_message(),
            "maxBytes": adapter.max_attachment_bytes(),
            "maxTotalBytes": adapter.max_total_attachment_bytes(),
            "mimeTypes": adapter.allowed_attachment_mime_types().map(|m| m.to_vec()),
        },
        "tokens": tokens(adapter.usage()),
        "modelCount": adapter.models().len(),
        "modelListed": adapter.models().contains(&adapter.model()),
    });
    if models {
        out["models"] = json!(adapter.models());
    }
    if let Some(endpoint) = adapter.endpoint() {
        out["endpoint"] = json!(endpoint);
    }
    out
}

// ---------------------------------------------------------------------------
// Operations
// ---------------------------------------------------------------------------

pub async fn call(op: &str, args: &Value, session: &mut Session) -> OpResult {
    let a = Args(args);
    match op {
        "message.inspect" => Ok(Outcome::ok(encode(&message(a.value("message")?)?))),
        "message.with_role" => {
            let original = message(a.value("message")?)?;
            let copy = original.with_role(a.str("role")?);
            Ok(Outcome::ok(json!({ "copy": encode(&copy), "original": encode(&original) })))
        }
        "message.mime" => Ok(Outcome::ok(json!(Message::new(a.bytes("content")?).mime_type()))),
        "role.inspect" => {
            let mut p = participant(&a)?;
            let role = p.role();
            if let Some(name) = a.opt_str("rename")? {
                role.set_name(name);
            }
            Ok(Outcome::ok(json!({ "id": role.id(), "name": role.name(), "identifier": role.identifier() })))
        }
        "role.constants" => {
            Ok(Outcome::ok(json!({ "assistant": utopia_agents::ROLE_ASSISTANT, "user": utopia_agents::ROLE_USER })))
        }

        "schema_object.new" => {
            let properties = a.opt("properties").map(php_array).unwrap_or_default();
            let entries: Vec<(Vec<u8>, php_std::Array)> = properties
                .iter()
                .map(|(k, v)| {
                    let name = match k {
                        KeyRef::Int(i) => i.to_string().into_bytes(),
                        KeyRef::Str(s) => s.to_vec(),
                    };
                    let definition = match v {
                        Php::Array(d) | Php::Object(d) => d.clone(),
                        _ => php_std::Array::new(),
                    };
                    (name, definition)
                })
                .collect();
            let object = tri!(SchemaObject::new(entries));
            Ok(Outcome::ok(session.handle(object)))
        }
        "schema_object.add" => {
            let property = php_array(a.value("property")?);
            let name = a.str("name")?.to_owned();
            let object = session.get_mut::<SchemaObject>(a.value("object")?)?;
            tri!(object.add_property(&name, property));
            Ok(Outcome::ok(Value::Null))
        }
        "schema_object.remove" => {
            let name = a.str("name")?.to_owned();
            session.get_mut::<SchemaObject>(a.value("object")?)?.remove_property(&name);
            Ok(Outcome::ok(Value::Null))
        }
        "schema_object.inspect" => {
            let object = session.get::<SchemaObject>(a.value("object")?)?;
            let names: Vec<Value> = object
                .keys()
                .map(|k| match k {
                    KeyRef::Int(i) => json!(i),
                    KeyRef::Str(s) => bytes_value(s),
                })
                .collect();
            let mut out = json!({ "properties": json(&Php::Array(object.properties().clone())), "names": names });
            if let Some(name) = a.opt_str("name")? {
                out["property"] = object.property(name).map_or(Value::Null, |p| json(&Php::Array(p.clone())));
            }
            Ok(Outcome::ok(out))
        }
        "schema_object.validate" => {
            let property = php_array(a.value("property")?);
            let object = session.get::<SchemaObject>(a.value("object")?)?;
            Ok(Outcome::ok(json!(object.validate_property(a.str("name")?, &property))))
        }
        "schema_object.valid_types" => Ok(Outcome::ok(json!(SchemaObject::valid_types()))),
        "schema.new" => {
            let object = session.get::<SchemaObject>(a.value("object")?)?.clone();
            let required = a
                .opt("required")
                .and_then(Value::as_array)
                .into_iter()
                .flatten()
                .filter_map(Value::as_str)
                .map(str::to_owned)
                .collect();
            let schema = Schema::new(a.str("name")?, a.str("description")?, object, required);
            Ok(Outcome::ok(session.handle(schema)))
        }
        "schema.inspect" => {
            let schema = session.get::<Schema>(a.value("schema")?)?;
            Ok(Outcome::ok(json!({
                "name": schema.name(),
                "description": schema.description(),
                "required": schema.required(),
                "properties": json(&Php::Array(schema.properties().clone())),
                "object": json(&Php::Array(schema.object().properties().clone())),
            })))
        }
        "schema.to_json" => Ok(match session.get::<Schema>(a.value("schema")?)?.to_json() {
            Ok(text) => Outcome::ok(text),
            Err(e) => Outcome::err(e.php_class(), e.to_string()),
        }),

        "adapter.new" => {
            let (adapter, recorded) = match build(&a, session)? {
                Ok(built) => built,
                Err(e) => return err(e),
            };
            let slots = session.state::<Slots>();
            slots.0.push(Slot { holder: Holder::Adapter(adapter), recorded, listened: None });
            let index = slots.0.len() - 1;
            Ok(Outcome::ok(handle_of(session, index)))
        }
        "adapter.inspect" => {
            let models = a.opt_bool("models")?.unwrap_or(false);
            let out = inspect(slot(session, a.value("adapter")?)?.adapter()?, models);
            Ok(Outcome::ok(unfixture(out, session)))
        }
        "adapter.set_model" => {
            let model = a.str("model")?.to_owned();
            tri!(slot(session, a.value("adapter")?)?.adapter()?.set_model(&model));
            Ok(Outcome::ok(Value::Null))
        }
        "adapter.set" => {
            let endpoint = a.opt_str("endpoint")?.map(|e| fixture(e, session)).transpose()?;
            let adapter = slot(session, a.value("adapter")?)?.adapter()?;
            if let Some(timeout) = a.opt_i64("timeout")? {
                adapter.set_timeout(timeout);
            }
            if let Some(tokens) = a.opt_i64("maxTokens")? {
                adapter.set_max_tokens(tokens)?;
            }
            if let Some(temperature) = a.opt_f64("temperature")? {
                adapter.set_temperature(temperature)?;
            }
            if let Some(endpoint) = endpoint {
                adapter.set_endpoint(&endpoint)?;
            }
            Ok(Outcome::ok(Value::Null))
        }
        "adapter.count" => {
            let adapter = slot(session, a.value("adapter")?)?.adapter()?;
            adapter
                .usage_mut()
                .count_input(a.opt_i64("input")?.unwrap_or(0))
                .count_output(a.opt_i64("output")?.unwrap_or(0))
                .count_cache_creation_input(a.opt_i64("cacheCreation")?.unwrap_or(0))
                .count_cache_read_input(a.opt_i64("cacheRead")?.unwrap_or(0));
            Ok(Outcome::ok(tokens(adapter.usage())))
        }
        "adapter.supports_attachment" => {
            let m = message(a.value("message")?)?;
            Ok(Outcome::ok(json!(slot(session, a.value("adapter")?)?.adapter()?.supports_attachment(&m))))
        }
        "adapter.embedding_dimension" => {
            Ok(match slot(session, a.value("adapter")?)?.adapter()?.embedding_dimension() {
                Ok(d) => Outcome::ok(d),
                Err(e) => Outcome::err(e.php_class(), e.to_string()),
            })
        }
        "adapter.agent" => {
            let s = slot(session, a.value("adapter")?)?;
            Ok(Outcome::ok(json!(!matches!(s.holder, Holder::Adapter(_)))))
        }
        "adapter.send" => {
            let messages: Vec<Message> = a
                .opt("messages")
                .and_then(Value::as_array)
                .into_iter()
                .flatten()
                .map(message)
                .collect::<Result<_, _>>()?;
            let listen = a.opt_bool("listen")?.unwrap_or(false);
            let code = a.opt_bool("code")?.unwrap_or(false);
            let s = slot(session, a.value("adapter")?)?;
            let mut tokens: Vec<String> = Vec::new();
            let mut collect = |token: &str| tokens.push(token.to_owned());
            let listener: Option<Listener<'_>> = if listen { Some(&mut collect) } else { None };
            let result = match &mut s.holder {
                Holder::Adapter(adapter) => adapter.send(&Profile::default(), &messages, listener).await,
                Holder::Agent(agent) => agent.send(&messages, listener).await,
                Holder::Conversation(c) => c.agent_mut().send(&messages, listener).await,
                Holder::Moved => return Err(Fault::new("moved")),
            };
            let out = match result {
                Ok(m) => {
                    let mut out = json!({ "content": bytes_value(m.content()), "role": m.role() });
                    if listen {
                        out["tokens"] = json!(tokens);
                    }
                    out
                }
                Err(e) if code => json!({
                    "class": e.php_class(),
                    "message": e.to_string(),
                    "code": e.code(),
                    "tokens": tokens,
                }),
                Err(e) => return Ok(Outcome::err(e.php_class(), e.to_string())),
            };
            Ok(Outcome::ok(unfixture(out, session)))
        }
        "adapter.embed" => {
            let text = a.str("text")?.to_owned();
            Ok(match slot(session, a.value("adapter")?)?.adapter()?.embed(&text).await {
                Ok(e) => Outcome::ok(embedding(&e)),
                Err(e) => Outcome::err(e.php_class(), e.to_string()),
            })
        }
        "adapter.bulk_embed" => {
            let texts: Vec<String> = a.array("texts")?.iter().filter_map(Value::as_str).map(str::to_owned).collect();
            Ok(match slot(session, a.value("adapter")?)?.adapter()?.bulk_embed(&texts).await {
                Ok(e) => Outcome::ok(embeddings(&e)),
                Err(e) => Outcome::err(e.php_class(), e.to_string()),
            })
        }
        "adapter.requests" => {
            let s = slot(session, a.value("adapter")?)?;
            let recorded =
                s.recorded.as_ref().ok_or_else(|| Fault::new("the adapter has no recording client"))?.recorded();
            Ok(Outcome::ok(unfixture(recorded, session)))
        }
        "fake.inspect" => {
            let s = slot(session, a.value("adapter")?)?;
            let Any::Fake(fake) = s.adapter()? else { return Err(Fault::new("not a fake adapter")) };
            let state = fake.state.lock().unwrap_or_else(|e| e.into_inner());
            let last = state.last.as_ref().map(|m| {
                let mut encoded = encode(m);
                if let Some(content) = encoded["content"].as_str() {
                    encoded["content"] = json!(untemp(&untmp(content)));
                }
                encoded
            });
            Ok(Outcome::ok(json!({ "sendCalls": state.send_calls, "last": last })))
        }

        "agent.new" => {
            let handle = a.value("adapter")?;
            let index = session.get::<Of>(handle)?.0;
            let s = slot(session, handle)?;
            let Holder::Adapter(adapter) = std::mem::replace(&mut s.holder, Holder::Moved) else {
                return Err(Fault::new("the adapter already has an agent here"));
            };
            s.holder = Holder::Agent(Agent::new(adapter));
            Ok(Outcome::ok(handle_of(session, index)))
        }
        "agent.set_description" => {
            let description = a.str("description")?.to_owned();
            slot(session, a.value("agent")?)?.agent()?.set_description(&description);
            Ok(Outcome::ok(Value::Null))
        }
        "agent.set_instructions" => {
            let list = instructions(a.value("instructions")?);
            slot(session, a.value("agent")?)?.agent()?.set_instructions(list);
            Ok(Outcome::ok(Value::Null))
        }
        "agent.add_instruction" => {
            let (name, content) = (a.str("name")?.to_owned(), a.str("content")?.to_owned());
            slot(session, a.value("agent")?)?.agent()?.add_instruction(&name, &content);
            Ok(Outcome::ok(Value::Null))
        }
        "agent.set_schema" => {
            let schema = session.get::<Schema>(a.value("schema")?)?.clone();
            tri!(slot(session, a.value("agent")?)?.agent()?.set_schema(schema));
            Ok(Outcome::ok(Value::Null))
        }
        "agent.inspect" => {
            let agent = slot(session, a.value("agent")?)?.agent()?;
            Ok(Outcome::ok(json!({
                "description": agent.description(),
                "instructions": agent.instructions().iter().map(|(n, c)| json!([n, instruction_json(c)])).collect::<Vec<_>>(),
                "schema": agent.schema().map(Schema::name),
                "adapter": agent.adapter().name(),
            })))
        }
        "agent.embed" => {
            let text = a.str("text")?.to_owned();
            Ok(match slot(session, a.value("agent")?)?.agent()?.embed(&text).await {
                Ok(e) => Outcome::ok(embedding(&e)),
                Err(e) => Outcome::err(e.php_class(), e.to_string()),
            })
        }
        "agent.bulk_embed" => {
            let texts: Vec<String> = a.array("texts")?.iter().filter_map(Value::as_str).map(str::to_owned).collect();
            Ok(match slot(session, a.value("agent")?)?.agent()?.bulk_embed(&texts).await {
                Ok(e) => Outcome::ok(embeddings(&e)),
                Err(e) => Outcome::err(e.php_class(), e.to_string()),
            })
        }

        "conversation.new" => {
            let handle = a.value("agent")?;
            let index = session.get::<Of>(handle)?.0;
            let s = slot(session, handle)?;
            let Holder::Agent(agent) = std::mem::replace(&mut s.holder, Holder::Moved) else {
                return Err(Fault::new("not an agent, or it already has a conversation here"));
            };
            s.holder = Holder::Conversation(Box::new(Conversation::new(agent)));
            Ok(Outcome::ok(handle_of(session, index)))
        }
        "conversation.listen" => {
            let s = slot(session, a.value("conversation")?)?;
            let listened: Arc<Mutex<Vec<String>>> = Arc::default();
            let sink = Arc::clone(&listened);
            s.conversation()?
                .listen(move |token: &str| sink.lock().unwrap_or_else(|e| e.into_inner()).push(token.to_owned()));
            s.listened = Some(listened);
            Ok(Outcome::ok(Value::Null))
        }
        "conversation.message" => {
            let from = a.value("from")?;
            let mut participant = participant(&Args(from))?;
            let m = message(a.value("message")?)?;
            let attachments: Vec<Message> = a
                .opt("attachments")
                .and_then(Value::as_array)
                .into_iter()
                .flatten()
                .map(message)
                .collect::<Result<_, _>>()?;
            let conversation = slot(session, a.value("conversation")?)?.conversation()?;
            tri!(conversation.message(participant.role(), m, attachments));
            Ok(Outcome::ok(Value::Null))
        }
        "conversation.send" => {
            let conversation = slot(session, a.value("conversation")?)?.conversation()?;
            let m = tri!(conversation.send().await);
            Ok(Outcome::ok(unfixture(json!({ "content": bytes_value(m.content()), "role": m.role() }), session)))
        }
        "conversation.count" => {
            let conversation = slot(session, a.value("conversation")?)?.conversation()?;
            conversation
                .usage_mut()
                .count_input(a.opt_i64("input")?.unwrap_or(0))
                .count_output(a.opt_i64("output")?.unwrap_or(0))
                .count_cache_creation_input(a.opt_i64("cacheCreation")?.unwrap_or(0))
                .count_cache_read_input(a.opt_i64("cacheRead")?.unwrap_or(0));
            Ok(Outcome::ok(tokens(conversation.usage())))
        }
        "conversation.inspect" => {
            let s = slot(session, a.value("conversation")?)?;
            let listened = s.listened.as_ref().map(|l| l.lock().unwrap_or_else(|e| e.into_inner()).clone());
            let conversation = s.conversation()?;
            Ok(Outcome::ok(json!({
                "messages": conversation.messages().iter().map(encode).collect::<Vec<_>>(),
                "tokens": tokens(conversation.usage()),
                "listened": listened,
            })))
        }

        "fixture.queue" => {
            let body = serde_json::to_vec(a.value("responses")?).map_err(|e| Fault::new(e.to_string()))?;
            fixture_call("POST", &format!("/queue/{}", session.ns), body, session).await?;
            Ok(Outcome::ok(Value::Null))
        }
        "fixture.requests" => {
            let service = session.service("agents-http")?.to_owned();
            let authority = service.trim_start_matches("http://").to_owned();
            let mut recorded = fixture_requests(session).await?;
            for request in &mut recorded {
                if let Some(headers) = request["headers"].as_array_mut() {
                    for header in headers {
                        if header[0].as_str().is_some_and(|n| n.eq_ignore_ascii_case("Host"))
                            && let Some(value) = header[1].as_str()
                        {
                            header[1] = json!(value.replace(&authority, "${fixture}"));
                        }
                    }
                }
                if request["body"].is_object() {
                    request["body"] = bytes_value(&bytes(&request["body"]).unwrap_or_default());
                }
                if let Some(body) = request["body"].as_str() {
                    request["body"] = json!(untemp(body));
                }
            }
            Ok(Outcome::ok(Value::Array(recorded)))
        }

        "fs.write" => {
            write(a.str("dir")?, a.value("files")?)?;
            Ok(Outcome::ok(Value::Null))
        }
        "fs.git" => Ok(Outcome::ok(git(&a)?)),
        "diff.run" => {
            let options = match options(a.opt("options").unwrap_or(&Value::Null), session)? {
                Ok(options) => options,
                Err(e) => return err(e),
            };
            let base = match source(a.value("base")?)? {
                Ok(s) => s,
                Err(e) => return err(e),
            };
            let target = match source(a.value("target")?)? {
                Ok(s) => s,
                Err(e) => return err(e),
            };
            let prompt = a.str("prompt")?.to_owned();
            let check = DiffCheck::new().with_temp_dir("/tmp");
            // git abbreviates hashes by the size of the repository it runs in
            // (the checkout this driver starts in is one): run outside any, in
            // the run's directory, as the PHP driver does.
            let dir = format!("/tmp/compat-fs/{}", session.ns);
            std::fs::create_dir_all(&dir).map_err(|e| Fault::new(e.to_string()))?;
            let cwd = std::env::current_dir().map_err(|e| Fault::new(e.to_string()))?;
            std::env::set_current_dir(&dir).map_err(|e| Fault::new(e.to_string()))?;
            let s = slot(session, a.value("runner")?)?;
            let result = match &mut s.holder {
                Holder::Adapter(adapter) => check.run(Runner::Adapter(adapter), base, target, &prompt, &options).await,
                Holder::Agent(agent) => check.run(Runner::Agent(agent), base, target, &prompt, &options).await,
                _ => Err(Error::Runtime("not an adapter or agent".into())),
            };
            std::env::set_current_dir(cwd).map_err(|e| Fault::new(e.to_string()))?;
            Ok(match result {
                Ok(outcome) => Outcome::ok(
                    json!({ "hasChanges": outcome.has_changes, "response": bytes_value(&outcome.response) }),
                ),
                Err(e) => Outcome::err(e.php_class(), untemp(&untmp(&e.to_string()))),
            })
        }
        "diff.options" => Ok(match options(a.opt("calls").unwrap_or(&Value::Null), session)? {
            Ok(options) => Outcome::ok(describe(&options)),
            Err(e) => Outcome::err(e.php_class(), e.to_string()),
        }),
        "diff.repository" => {
            let source = a.str("source")?;
            let reference = a.opt_str("ref")?;
            let repository = match a.str("how")? {
                "new" => Repository::new(source, a.opt_bool("remote")?.unwrap_or(false), reference),
                "local" => Repository::local(source, reference),
                "remote" => Repository::remote(source, reference),
                "from" => Repository::parse(source),
                other => return Err(Fault::new(format!("unknown how {other}"))),
            };
            Ok(match repository {
                Ok(r) => {
                    Outcome::ok(json!({ "source": untmp(r.source()), "remote": r.is_remote(), "ref": r.reference() }))
                }
                Err(e) => Outcome::err(e.php_class(), untmp(&e.to_string())),
            })
        }

        "stream.parse" => Ok(Outcome::ok(parse(&a).await?)),
        "sync.run" => Ok(Outcome::ok(sync(&a, session).await?)),
        "data.digest" => Ok(Outcome::ok(digest())),
        _ => Err(Fault::new(format!("unknown operation {op}"))),
    }
}

/// Benchmarks (same names and work as `benchmarks()` in Adapter.php).
pub const BENCHES: &[(&str, &str)] = &[
    (
        "openai_stream",
        "Send a conversation and parse a 50-token OpenAI stream cut in 97-byte chunks (in-memory client)",
    ),
    (
        "anthropic_stream",
        "Send a conversation and parse a 50-token Anthropic stream cut in 97-byte chunks (in-memory client)",
    ),
    (
        "conversation_message",
        "Add a message with a PNG attachment to a conversation (attachment validation and MIME detection)",
    ),
    ("schema_to_json", "Describe a three-property schema as JSON"),
    ("fixture_send", "Send a conversation to the fixture server and parse its streamed answer (one HTTP round trip)"),
];

/// The in-memory client of the stream benchmarks: every request gets the
/// same chunks.
struct Chunks(Vec<Vec<u8>>);

impl Transport for Chunks {
    fn send(&self, _request: Request) -> BoxFuture<'_, Result<Response, utopia_client::Error>> {
        Box::pin(async { Response::new(200) })
    }

    fn stream<'a>(
        &'a self,
        _request: Request,
        sink: &'a mut (dyn FnMut(&[u8]) + Send),
    ) -> BoxFuture<'a, Result<Response, utopia_client::Error>> {
        Box::pin(async move {
            for chunk in &self.0 {
                sink(chunk);
            }
            Response::new(200)
        })
    }
}

pub async fn bench(name: &str, session: &mut Session) -> Result<crate::bench::Bench, Fault> {
    use std::cell::RefCell;
    use std::hint::black_box;
    use std::rc::Rc;

    use crate::bench::Bench;

    let split = |text: String| -> Vec<Vec<u8>> { text.as_bytes().chunks(97).map(<[u8]>::to_vec).collect() };
    // The agent is taken out of its cell for the send, so no borrow is held
    // across the await.
    let stream = |agent: Agent<Any>| {
        let agent = Rc::new(RefCell::new(Some(agent)));
        let messages = Rc::new(vec![Message::new("Hello")]);
        Bench::future(move || {
            let (cell, messages) = (Rc::clone(&agent), Rc::clone(&messages));
            async move {
                let Some(mut agent) = cell.borrow_mut().take() else { return };
                black_box(agent.send(&messages, None).await.ok());
                *cell.borrow_mut() = Some(agent);
            }
        })
    };
    Ok(match name {
        "openai_stream" => {
            let mut events = String::new();
            for i in 0..50 {
                events.push_str(&format!("data: {{\"choices\":[{{\"delta\":{{\"content\":\"token {i} \"}}}}]}}\n\n"));
            }
            events.push_str("data: [DONE]\n\n");
            let client: Arc<dyn Transport> = Arc::new(Chunks(split(events)));
            stream(Agent::new(Any::OpenAi(OpenAi::new("test-api-key").with_model("gpt-4o").with_client(client))))
        }
        "anthropic_stream" => {
            let mut events = String::new();
            for i in 0..50 {
                events.push_str(&format!(
                    "data: {{\"type\":\"content_block_delta\",\"delta\":{{\"type\":\"text_delta\",\"text\":\"token {i} \"}}}}\n\n"
                ));
            }
            let client: Arc<dyn Transport> = Arc::new(Chunks(split(events)));
            stream(Agent::new(Any::Anthropic(Anthropic::new("test-api-key").with_client(client))))
        }
        "conversation_message" => {
            let png = BASE64
                .decode("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVQYV2NgYAAAAAMAAWgmWQ0AAAAASUVORK5CYII=")
                .map_err(|e| Fault::new(e.to_string()))?;
            let user = User::new("user-1", "");
            let mut adapter = OpenAi::new("test-api-key");
            Bench::sync(move || {
                // PHP's `new Conversation($agent)` shares one agent: borrow one adapter.
                let mut conversation = Conversation::new(Agent::new(&mut adapter));
                let _ = black_box(conversation.message(
                    &user,
                    Message::new("What is in this image?"),
                    vec![Message::new(png.clone())],
                ));
            })
        }
        "schema_to_json" => {
            let object = SchemaObject::new([
                ("id", utopia_agents::property("string", "The ID of the user")),
                ("name", utopia_agents::property("string", "The name of the user")),
                ("age", utopia_agents::property("integer", "The age of the user")),
            ])
            .map_err(|e| Fault::new(e.to_string()))?;
            let schema = Schema::new("User", "A user", object, vec!["id".into(), "name".into()]);
            Bench::sync(move || {
                black_box(schema.to_json().ok());
            })
        }
        "fixture_send" => {
            let service = session.service("agents-http")?.to_owned();
            let endpoint = format!("{service}/serve/bench-rust");
            let agent = Rc::new(RefCell::new(Some(Agent::new(
                OpenAi::new("test-api-key").with_model("gpt-4o").with_endpoint(&endpoint),
            ))));
            let messages = Rc::new(vec![Message::new("Hello")]);
            let queue = Rc::new(
                serde_json::to_vec(&json!([{
                    "status": 200,
                    "chunks": ["data: {\"choices\":[{\"delta\":{\"content\":\"Hi\"}}]}\n\ndata: [DONE]\n\n"],
                    "delay": 0,
                }]))
                .map_err(|e| Fault::new(e.to_string()))?,
            );
            let queue_client = Rc::new(Client::new(Http::new()).with_connection_reuse(true));
            let queue_url = Rc::new(format!("{service}/queue/bench-rust"));
            Bench::future(move || {
                let (agent, messages, queue, client, url) = (
                    Rc::clone(&agent),
                    Rc::clone(&messages),
                    Rc::clone(&queue),
                    Rc::clone(&queue_client),
                    Rc::clone(&queue_url),
                );
                async move {
                    if let Ok(request) =
                        Request::new("POST", &url).and_then(|r| r.header("Content-Type", "application/json"))
                    {
                        let _ = client.send(request.body((*queue).clone())).await;
                    }
                    let Some(mut taken) = agent.borrow_mut().take() else { return };
                    black_box(taken.send(&messages, None).await.ok());
                    *agent.borrow_mut() = Some(taken);
                }
            })
        }
        other => return Err(Fault::new(format!("unknown benchmark {other}"))),
    })
}
