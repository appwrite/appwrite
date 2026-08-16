//! NATS connection (PHP `Utopia\NATS\Connection`).

use crate::auth::{Authenticator, CredentialsAuth, NKeyAuth, NoAuth, TokenAuth, UserPassAuth};
use crate::error::{
    AuthenticationException, ConnectionException, MaxPayloadException, NatsError, NatsException,
    PermissionException, ProtocolException, TimeoutException,
};
use crate::headers::Headers;
use crate::inbox::Inbox;
use crate::message::Message;
use crate::protocol::{Parser, ServerEvent, Writer};
use crate::subscription::{MessageCallback, SlowConsumerCallback, Subscription};
use crate::transport::{TcpTransport, TlsTransport, Transport, WebSocketTransport};
use parking_lot::Mutex;
use serde_json::{json, Map, Value};
use std::collections::HashMap;
use std::sync::Arc;
use std::time::{Duration, Instant};
use url::Url;

pub const STATUS_DISCONNECTED: &str = "disconnected";
pub const STATUS_CONNECTING: &str = "connecting";
pub const STATUS_CONNECTED: &str = "connected";
pub const STATUS_RECONNECTING: &str = "reconnecting";
pub const STATUS_DRAINING: &str = "draining";
pub const STATUS_CLOSED: &str = "closed";

const CLIENT_LANG: &str = "rust";
const CLIENT_VERSION: &str = "0.1.0";

pub type TransportFactory = Arc<dyn Fn(&str) -> Arc<dyn Transport> + Send + Sync>;
pub type VoidCallback = Arc<dyn Fn() + Send + Sync>;
pub type ErrorCallback = Arc<dyn Fn(NatsException) + Send + Sync>;
pub type TokenProvider = Arc<dyn Fn() -> String + Send + Sync>;

#[derive(Clone)]
pub struct ConnectionOptions {
    pub servers: Vec<String>,
    pub name: String,
    pub user: Option<String>,
    pub pass: Option<String>,
    pub token: Option<String>,
    pub nkey: Option<String>,
    pub nkey_seed: Option<String>,
    pub credentials_file: Option<String>,
    pub tls: bool,
    pub tls_ca_file: Option<String>,
    pub tls_cert_file: Option<String>,
    pub tls_key_file: Option<String>,
    pub tls_verify: bool,
    pub tls_server_name: Option<String>,
    pub token_provider: Option<TokenProvider>,
    pub jwt_provider: Option<TokenProvider>,
    pub allow_reconnect: bool,
    pub max_reconnect_attempts: i64,
    pub reconnect_wait: f64,
    pub max_reconnect_wait: f64,
    pub reconnect_jitter: f64,
    pub reconnect_buf_size: i64,
    pub sub_pending_msgs_limit: i64,
    pub sub_pending_bytes_limit: i64,
    pub connect_timeout: f64,
    pub request_timeout: f64,
    pub drain_timeout: f64,
    pub ping_interval: f64,
    pub max_pings_out: i64,
    pub verbose: bool,
    pub pedantic: bool,
    pub echo: bool,
    pub no_randomize: bool,
    pub inbox_prefix: String,
    pub on_disconnect: Option<VoidCallback>,
    pub on_reconnect: Option<VoidCallback>,
    pub on_close: Option<VoidCallback>,
    pub on_error: Option<ErrorCallback>,
    pub on_slow_consumer: Option<SlowConsumerCallback>,
    pub on_lame_duck: Option<VoidCallback>,
    pub transport_factory: Option<TransportFactory>,
}

impl std::fmt::Debug for ConnectionOptions {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("ConnectionOptions")
            .field("servers", &self.servers)
            .field("name", &self.name)
            .finish_non_exhaustive()
    }
}

impl Default for ConnectionOptions {
    fn default() -> Self {
        Self {
            servers: vec!["nats://127.0.0.1:4222".into()],
            name: String::new(),
            user: None,
            pass: None,
            token: None,
            nkey: None,
            nkey_seed: None,
            credentials_file: None,
            tls: false,
            tls_ca_file: None,
            tls_cert_file: None,
            tls_key_file: None,
            tls_verify: true,
            tls_server_name: None,
            token_provider: None,
            jwt_provider: None,
            allow_reconnect: true,
            max_reconnect_attempts: 60,
            reconnect_wait: 2.0,
            max_reconnect_wait: 8.0,
            reconnect_jitter: 0.1,
            reconnect_buf_size: 8_388_608,
            sub_pending_msgs_limit: 65536,
            sub_pending_bytes_limit: 67_108_864,
            connect_timeout: 2.0,
            request_timeout: 5.0,
            drain_timeout: 30.0,
            ping_interval: 120.0,
            max_pings_out: 2,
            verbose: false,
            pedantic: false,
            echo: true,
            no_randomize: false,
            inbox_prefix: "_INBOX".into(),
            on_disconnect: None,
            on_reconnect: None,
            on_close: None,
            on_error: None,
            on_slow_consumer: None,
            on_lame_duck: None,
            transport_factory: None,
        }
    }
}

impl ConnectionOptions {
    pub fn servers(servers: impl Into<Vec<String>>) -> Self {
        Self {
            servers: servers.into(),
            ..Self::default()
        }
    }
}

#[derive(Clone, Debug)]
pub struct ServerInfo {
    pub server_id: String,
    pub server_name: String,
    pub version: String,
    pub proto: i64,
    pub host: String,
    pub port: i64,
    pub headers_supported: bool,
    pub auth_required: bool,
    pub tls_required: bool,
    pub tls_available: bool,
    pub max_payload: i64,
    pub connect_urls: Vec<String>,
    pub nonce: Option<String>,
    pub jetstream: bool,
    pub client_id: Option<i64>,
    pub client_ip: Option<String>,
}

impl ServerInfo {
    pub fn from_value(data: &Value) -> Self {
        Self {
            server_id: data
                .get("server_id")
                .and_then(Value::as_str)
                .unwrap_or("")
                .to_owned(),
            server_name: data
                .get("server_name")
                .and_then(Value::as_str)
                .unwrap_or("")
                .to_owned(),
            version: data
                .get("version")
                .and_then(Value::as_str)
                .unwrap_or("")
                .to_owned(),
            proto: data.get("proto").and_then(Value::as_i64).unwrap_or(0),
            host: data
                .get("host")
                .and_then(Value::as_str)
                .unwrap_or("")
                .to_owned(),
            port: data.get("port").and_then(Value::as_i64).unwrap_or(0),
            headers_supported: data
                .get("headers")
                .and_then(Value::as_bool)
                .unwrap_or(false),
            auth_required: data
                .get("auth_required")
                .and_then(Value::as_bool)
                .unwrap_or(false),
            tls_required: data
                .get("tls_required")
                .and_then(Value::as_bool)
                .unwrap_or(false),
            tls_available: data
                .get("tls_available")
                .and_then(Value::as_bool)
                .unwrap_or(false),
            max_payload: data
                .get("max_payload")
                .and_then(Value::as_i64)
                .unwrap_or(1_048_576),
            connect_urls: data
                .get("connect_urls")
                .and_then(Value::as_array)
                .map(|a| {
                    a.iter()
                        .filter_map(|v| v.as_str().map(str::to_owned))
                        .collect()
                })
                .unwrap_or_default(),
            nonce: data.get("nonce").and_then(Value::as_str).map(str::to_owned),
            jetstream: data
                .get("jetstream")
                .and_then(Value::as_bool)
                .unwrap_or(false),
            client_id: data.get("client_id").and_then(Value::as_i64),
            client_ip: data
                .get("client_ip")
                .and_then(Value::as_str)
                .map(str::to_owned),
        }
    }
}

struct ConnInner {
    transport: Option<Arc<dyn Transport>>,
    parser: Option<Parser>,
    writer: Writer,
    auth: Box<dyn Authenticator>,
    server_info: Option<ServerInfo>,
    options: ConnectionOptions,
    subscriptions: HashMap<String, Subscription>,
    next_sid: i64,
    status: String,
    outstanding_pings: i64,
    last_ping_time: Instant,
    inbox_sub: Option<Subscription>,
    inbox_prefix: String,
    pending_requests: HashMap<String, (Option<Message>, bool)>,
    server_pool: Vec<String>,
    current_server: String,
    pending_buffer: Vec<Vec<u8>>,
    pending_buffer_bytes: i64,
}

pub struct Connection {
    inner: Arc<Mutex<ConnInner>>,
}

impl Clone for Connection {
    fn clone(&self) -> Self {
        Self {
            inner: Arc::clone(&self.inner),
        }
    }
}

impl std::fmt::Debug for Connection {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Connection")
            .field("status", &self.get_status())
            .finish_non_exhaustive()
    }
}

impl Connection {
    pub fn connect(options: ConnectionOptions) -> Result<Self, NatsError> {
        let auth = resolve_authenticator(&options)?;
        let pool = build_server_pool(&options);
        let conn = Self {
            inner: Arc::new(Mutex::new(ConnInner {
                transport: None,
                parser: None,
                writer: Writer,
                auth,
                server_info: None,
                options,
                subscriptions: HashMap::new(),
                next_sid: 1,
                status: STATUS_DISCONNECTED.into(),
                outstanding_pings: 0,
                last_ping_time: Instant::now(),
                inbox_sub: None,
                inbox_prefix: String::new(),
                pending_requests: HashMap::new(),
                server_pool: pool,
                current_server: String::new(),
                pending_buffer: Vec::new(),
                pending_buffer_bytes: 0,
            })),
        };
        conn.do_connect()?;
        Ok(conn)
    }

    pub fn publish(
        &self,
        subject: &str,
        data: &[u8],
        reply_to: Option<&str>,
        headers: Option<&Headers>,
    ) -> Result<(), NatsError> {
        self.ensure_connected()?;
        let has_headers = headers.is_some_and(|h| !h.all().is_empty());
        let info = self.get_server_info();
        if has_headers && !info.headers_supported {
            return Err(ProtocolException("Server does not support message headers".into()).into());
        }
        let header_wire = if has_headers {
            headers.unwrap().to_wire().into_bytes()
        } else {
            Vec::new()
        };
        let wire_size = header_wire.len() + data.len();
        if wire_size as i64 > info.max_payload {
            return Err(MaxPayloadException(format!(
                "Payload size {wire_size} exceeds server maximum of {}",
                info.max_payload
            ))
            .into());
        }
        let cmd = {
            let inner = self.inner.lock();
            if has_headers {
                inner.writer.hpub(subject, &header_wire, data, reply_to)
            } else {
                inner.writer.pub_cmd(subject, data, reply_to)
            }
        };
        self.send(&cmd)
    }

    pub fn subscribe(
        &self,
        subject: &str,
        callback: Option<MessageCallback>,
        queue: Option<&str>,
    ) -> Result<Subscription, NatsError> {
        self.ensure_connected()?;
        let (sub, cmd) = {
            let mut inner = self.inner.lock();
            let sid = inner.next_sid.to_string();
            inner.next_sid += 1;
            let sub = Subscription::new(
                sid.clone(),
                subject,
                queue.map(str::to_owned),
                callback,
                inner.options.sub_pending_msgs_limit,
                inner.options.sub_pending_bytes_limit,
                inner.options.on_slow_consumer.clone(),
            );
            let conn = self.clone_arc();
            let conn_unsub = self.clone_arc();
            sub.set_process(Arc::new(move |timeout| {
                let _ = conn.process_message(timeout);
            }));
            sub.set_unsub(Arc::new(move |s, max| {
                let _ = conn_unsub.unsubscribe(s, max);
            }));
            inner.subscriptions.insert(sid.clone(), sub.clone());
            let cmd = inner.writer.sub(subject, &sid, queue);
            (sub, cmd)
        };
        self.send(cmd.as_bytes())?;
        Ok(sub)
    }

    pub fn queue_subscribe(
        &self,
        subject: &str,
        queue: &str,
        callback: Option<MessageCallback>,
    ) -> Result<Subscription, NatsError> {
        self.subscribe(subject, callback, Some(queue))
    }

    pub fn unsubscribe(
        &self,
        sub: &Subscription,
        max_messages: Option<i64>,
    ) -> Result<(), NatsError> {
        let cmd = {
            let mut inner = self.inner.lock();
            if let Some(max) = max_messages {
                sub.set_max_messages(sub.get_received() + max);
                inner.writer.unsub(&sub.sid, Some(max))
            } else {
                sub.set_inactive();
                inner.subscriptions.remove(&sub.sid);
                inner.writer.unsub(&sub.sid, None)
            }
        };
        self.send(cmd.as_bytes())
    }

    /// Request/reply: publish then wait for one reply on an inbox.
    /// PHP `Connection::request`.
    pub fn request(
        &self,
        subject: &str,
        data: &[u8],
        timeout: Option<f64>,
        headers: Option<&Headers>,
    ) -> Result<Message, NatsError> {
        self.ensure_connected()?;
        let timeout = timeout.unwrap_or(self.inner.lock().options.request_timeout);
        self.ensure_inbox_sub()?;
        let token = Inbox::generate_id();
        let reply_to = {
            let mut inner = self.inner.lock();
            let reply_to = format!("{}.{}", inner.inbox_prefix, token);
            inner.pending_requests.insert(token.clone(), (None, false));
            reply_to
        };
        self.publish(subject, data, Some(&reply_to), headers)?;
        let deadline = Instant::now() + Duration::from_secs_f64(timeout);
        loop {
            let remaining = deadline
                .saturating_duration_since(Instant::now())
                .as_secs_f64();
            if remaining <= 0.0 {
                self.inner.lock().pending_requests.remove(&token);
                return Err(TimeoutException(format!("Request timed out after {timeout}s")).into());
            }
            let _ = self.process_message(Some(remaining))?;
            let resolved = self
                .inner
                .lock()
                .pending_requests
                .get(&token)
                .is_some_and(|(_, resolved)| *resolved);
            if resolved {
                let msg = self
                    .inner
                    .lock()
                    .pending_requests
                    .remove(&token)
                    .and_then(|(m, _)| m);
                let msg = msg.ok_or_else(|| {
                    TimeoutException(format!("Request timed out after {timeout}s"))
                })?;
                if msg
                    .headers
                    .as_ref()
                    .is_some_and(|h| h.get_status() == "503")
                {
                    return Err(NatsException("No responders for request".into()).into());
                }
                return Ok(msg);
            }
        }
    }

    /// Request/reply collecting up to `max` replies (PHP `requestMany`).
    pub fn request_many(
        &self,
        subject: &str,
        data: &[u8],
        timeout: Option<f64>,
        max: Option<usize>,
        stall: Option<f64>,
    ) -> Result<Vec<Message>, NatsError> {
        self.ensure_connected()?;
        let timeout = timeout.unwrap_or(self.inner.lock().options.request_timeout);
        let prefix = self.inner.lock().options.inbox_prefix.clone();
        let inbox = Inbox::with_prefix(&prefix);
        let sub = self.subscribe(&inbox, None, None)?;
        self.publish(subject, data, Some(&inbox), None)?;
        let deadline = Instant::now() + Duration::from_secs_f64(timeout);
        let mut messages = Vec::new();
        loop {
            if max.is_some_and(|m| messages.len() >= m) {
                break;
            }
            let remaining = deadline
                .saturating_duration_since(Instant::now())
                .as_secs_f64();
            if remaining <= 0.0 {
                break;
            }
            let wait = stall.map_or(remaining, |s| s.min(remaining));
            match sub.next_message(Some(wait)) {
                Some(msg) => {
                    if msg
                        .headers
                        .as_ref()
                        .is_some_and(|h| h.get_status() == "503")
                    {
                        break;
                    }
                    messages.push(msg);
                }
                None => break,
            }
        }
        let _ = self.unsubscribe(&sub, None);
        Ok(messages)
    }

    /// Process messages in a loop (PHP `Connection::wait`).
    /// `count == 0` means forever until timeout.
    pub fn wait(&self, count: i64, timeout: Option<f64>) {
        let mut processed = 0i64;
        let deadline = timeout.map(|t| Instant::now() + Duration::from_secs_f64(t));
        while count == 0 || processed < count {
            let remaining =
                deadline.map(|d| d.saturating_duration_since(Instant::now()).as_secs_f64());
            if remaining.is_some_and(|r| r <= 0.0) {
                return;
            }
            match self.process_message(remaining) {
                Ok(Some(_)) => processed += 1,
                Ok(None) => {}
                Err(_) => return,
            }
        }
    }

    pub fn drain(&self, timeout: Option<f64>) -> Result<(), NatsError> {
        let timeout = timeout.unwrap_or(self.inner.lock().options.drain_timeout);
        self.inner.lock().status = STATUS_DRAINING.into();
        if let Some(t) = self.inner.lock().transport.clone() {
            let _ = t.write(&[]);
        }
        let deadline = Instant::now() + Duration::from_secs_f64(timeout);
        while Instant::now() < deadline {
            let empty = self.inner.lock().subscriptions.is_empty();
            if empty {
                break;
            }
            std::thread::sleep(Duration::from_millis(10));
        }
        self.close();
        Ok(())
    }

    fn ensure_inbox_sub(&self) -> Result<(), NatsError> {
        if self.inner.lock().inbox_sub.is_some() {
            return Ok(());
        }
        let prefix = {
            let inner = self.inner.lock();
            format!("{}.{}", inner.options.inbox_prefix, Inbox::generate_id())
        };
        let sub = self.subscribe(&format!("{prefix}.*"), None, None)?;
        let mut inner = self.inner.lock();
        inner.inbox_prefix = prefix;
        inner.inbox_sub = Some(sub);
        Ok(())
    }

    pub fn process_message(&self, timeout: Option<f64>) -> Result<Option<Message>, NatsError> {
        self.check_pings()?;
        let event = {
            let mut inner = self.inner.lock();
            let parser = inner
                .parser
                .as_mut()
                .ok_or_else(|| ConnectionException("Not connected".into()))?;
            match parser.next(timeout) {
                Ok((_op, event)) => event,
                Err(NatsError::Timeout(_)) => return Ok(None),
                Err(e) if e.is_connection() => {
                    drop(inner);
                    if self.inner.lock().options.allow_reconnect
                        && self.inner.lock().status != STATUS_CLOSED
                    {
                        let _ = self.attempt_reconnect();
                        return Ok(None);
                    }
                    return Err(e);
                }
                Err(e) => return Err(e),
            }
        };
        self.dispatch_op(event)
    }

    pub fn jetstream(
        &self,
        domain: Option<&str>,
        api_prefix: Option<&str>,
    ) -> crate::jetstream::JetStream {
        crate::jetstream::JetStream::new(self.clone_arc(), domain, api_prefix)
    }

    pub fn flush(&self, timeout: Option<f64>) -> Result<(), NatsError> {
        self.ensure_connected()?;
        let timeout = timeout.unwrap_or(self.inner.lock().options.connect_timeout);
        let ping = {
            let inner = self.inner.lock();
            inner.writer.ping().to_owned()
        };
        self.send(ping.as_bytes())?;
        let deadline = Instant::now() + Duration::from_secs_f64(timeout);
        loop {
            let remaining = deadline
                .saturating_duration_since(Instant::now())
                .as_secs_f64();
            if remaining <= 0.0 {
                return Err(TimeoutException("Flush timed out".into()).into());
            }
            let event = {
                let mut inner = self.inner.lock();
                inner.parser.as_mut().unwrap().next(Some(remaining))?.1
            };
            if matches!(event, ServerEvent::Pong) {
                self.inner.lock().outstanding_pings = 0;
                return Ok(());
            }
            let _ = self.dispatch_op(event)?;
        }
    }

    pub fn close(&self) {
        let mut inner = self.inner.lock();
        if inner.status == STATUS_CLOSED {
            return;
        }
        let previous = inner.status.clone();
        inner.status = STATUS_CLOSED.into();
        for sub in inner.subscriptions.values() {
            sub.set_inactive();
        }
        inner.subscriptions.clear();
        inner.pending_requests.clear();
        if let Some(t) = inner.transport.take() {
            t.close();
        }
        let on_close = inner.options.on_close.clone();
        drop(inner);
        if previous == STATUS_CONNECTED {
            if let Some(cb) = on_close {
                cb();
            }
        }
    }

    pub fn is_connected(&self) -> bool {
        self.inner.lock().status == STATUS_CONNECTED
    }

    pub fn is_closed(&self) -> bool {
        self.inner.lock().status == STATUS_CLOSED
    }

    pub fn is_reconnecting(&self) -> bool {
        self.inner.lock().status == STATUS_RECONNECTING
    }

    pub fn get_server_info(&self) -> ServerInfo {
        self.inner
            .lock()
            .server_info
            .clone()
            .unwrap_or_else(|| ServerInfo::from_value(&json!({})))
    }

    pub fn get_status(&self) -> String {
        self.inner.lock().status.clone()
    }

    pub fn new_inbox(&self) -> String {
        Inbox::with_prefix(&self.inner.lock().options.inbox_prefix)
    }

    /// PHP `Connection::tlsOptions` (tested via reflection).
    pub fn tls_options(&self) -> HashMap<String, Value> {
        let o = &self.inner.lock().options;
        let mut opts = HashMap::new();
        opts.insert("cafile".into(), json!(o.tls_ca_file));
        opts.insert("local_cert".into(), json!(o.tls_cert_file));
        opts.insert("local_pk".into(), json!(o.tls_key_file));
        opts.insert("verify_peer".into(), json!(o.tls_verify));
        opts.insert("verify_peer_name".into(), json!(o.tls_verify));
        if let Some(name) = &o.tls_server_name {
            opts.insert("peer_name".into(), json!(name));
        }
        opts
    }

    pub fn map_server_error(message: &str) -> NatsError {
        let lower = message.to_ascii_lowercase();
        if lower.contains("permissions violation") {
            return PermissionException(message.to_owned()).into();
        }
        if lower.contains("authorization violation")
            || lower.contains("authentication expired")
            || lower.contains("authorization")
            || lower.contains("authentication")
        {
            return AuthenticationException(message.to_owned()).into();
        }
        if lower.contains("maximum payload") {
            return MaxPayloadException(message.to_owned()).into();
        }
        ProtocolException(format!("Server error: {message}")).into()
    }

    pub fn reconnect_backoff(attempt: i64, base: f64, cap: f64, factor: f64) -> f64 {
        if attempt <= 0 {
            return 0.0;
        }
        let delay = base * factor.powi((attempt - 1) as i32);
        delay.min(cap)
    }

    pub fn reconnect_buffer_accepts(current_bytes: i64, incoming_bytes: i64, cap: i64) -> bool {
        if cap <= 0 {
            return false;
        }
        (current_bytes + incoming_bytes) <= cap
    }

    fn clone_arc(&self) -> Self {
        Self {
            inner: Arc::clone(&self.inner),
        }
    }

    fn do_connect(&self) -> Result<(), NatsError> {
        self.inner.lock().status = STATUS_CONNECTING.into();
        let pool = self.inner.lock().server_pool.clone();
        let mut last_error: Option<NatsError> = None;
        for url in pool {
            match self.connect_to_server(&url) {
                Ok(()) => {
                    self.inner.lock().status = STATUS_CONNECTED.into();
                    return Ok(());
                }
                Err(e) => last_error = Some(e),
            }
        }
        self.inner.lock().status = STATUS_DISCONNECTED.into();
        Err(ConnectionException("Failed to connect to any NATS server".into()).into())
            .map_err(|e: NatsError| last_error.unwrap_or(e))
    }

    fn connect_to_server(&self, url: &str) -> Result<(), NatsError> {
        let parsed = parse_url(url)?;
        let scheme = parsed.0;
        let host = parsed.1;
        let port = parsed.2;
        let options = self.inner.lock().options.clone();
        let transport: Arc<dyn Transport> = if let Some(factory) = &options.transport_factory {
            factory(&scheme)
        } else if scheme == "ws" || scheme == "wss" {
            Arc::new(WebSocketTransport::new(scheme == "wss", self.tls_options()))
        } else if scheme == "tls" || options.tls {
            Arc::new(TlsTransport::new(self.tls_options()))
        } else {
            Arc::new(TcpTransport::new())
        };
        transport.connect(&host, port, options.connect_timeout)?;
        let mut parser = Parser::new(Arc::clone(&transport));
        let (op, event) = parser.next(Some(options.connect_timeout))?;
        let ServerEvent::Info(data) = event else {
            return Err(ProtocolException(format!("Expected INFO, got {}", op.as_str())).into());
        };
        let info = ServerInfo::from_value(&data);
        {
            let mut inner = self.inner.lock();
            inner.transport = Some(Arc::clone(&transport));
            inner.parser = Some(parser);
            inner.server_info = Some(info.clone());
            url.clone_into(&mut inner.current_server);
            for cu in &info.connect_urls {
                let n = normalize_url(cu);
                if !inner.server_pool.contains(&n) {
                    inner.server_pool.push(n);
                }
            }
        }
        let payload = self.build_connect_payload()?;
        let connect_cmd = Writer.connect(&Value::Object(payload));
        transport.write(connect_cmd.as_bytes())?;
        transport.write(Writer.ping().as_bytes())?;
        let deadline = Instant::now() + Duration::from_secs_f64(options.connect_timeout);
        loop {
            let remaining = deadline
                .saturating_duration_since(Instant::now())
                .as_secs_f64();
            if remaining <= 0.0 {
                return Err(TimeoutException("Connection handshake timed out".into()).into());
            }
            let event = {
                let mut inner = self.inner.lock();
                inner.parser.as_mut().unwrap().next(Some(remaining))?.1
            };
            match event {
                ServerEvent::Pong => break,
                ServerEvent::Err(msg) => {
                    transport.close();
                    if msg.to_ascii_lowercase().contains("authorization")
                        || msg.to_ascii_lowercase().contains("authentication")
                    {
                        return Err(AuthenticationException(format!("Server error: {msg}")).into());
                    }
                    return Err(ConnectionException(format!("Server error: {msg}")).into());
                }
                _ => {}
            }
        }
        self.inner.lock().last_ping_time = Instant::now();
        Ok(())
    }

    fn build_connect_payload(&self) -> Result<Map<String, Value>, NatsError> {
        let inner = self.inner.lock();
        let headers_supported = inner
            .server_info
            .as_ref()
            .is_some_and(|i| i.headers_supported);
        let mut payload = Map::new();
        payload.insert("verbose".into(), json!(inner.options.verbose));
        payload.insert("pedantic".into(), json!(inner.options.pedantic));
        payload.insert("lang".into(), json!(CLIENT_LANG));
        payload.insert("version".into(), json!(CLIENT_VERSION));
        payload.insert("protocol".into(), json!(1));
        payload.insert("echo".into(), json!(inner.options.echo));
        payload.insert("headers".into(), json!(headers_supported));
        payload.insert("no_responders".into(), json!(headers_supported));
        if !inner.options.name.is_empty() {
            payload.insert("name".into(), json!(inner.options.name));
        }
        let nonce = inner.server_info.as_ref().and_then(|i| i.nonce.clone());
        let auth_fields = inner.auth.authenticate(nonce.as_deref())?;
        for (k, v) in auth_fields {
            payload.insert(k, v);
        }
        if let Some(tp) = &inner.options.token_provider {
            payload.insert("auth_token".into(), json!(tp()));
        }
        if let Some(jp) = &inner.options.jwt_provider {
            payload.insert("jwt".into(), json!(jp()));
        }
        Ok(payload)
    }

    fn dispatch_op(&self, event: ServerEvent) -> Result<Option<Message>, NatsError> {
        match event {
            ServerEvent::Msg(data) | ServerEvent::HMsg(data) => self.handle_message(data),
            ServerEvent::Ping => {
                let pong = self.inner.lock().writer.pong().to_owned();
                self.send(pong.as_bytes())?;
                Ok(None)
            }
            ServerEvent::Pong => {
                let mut inner = self.inner.lock();
                inner.outstanding_pings = (inner.outstanding_pings - 1).max(0);
                Ok(None)
            }
            ServerEvent::Err(msg) => {
                if let Some(cb) = self.inner.lock().options.on_error.clone() {
                    cb(NatsException(msg.clone()));
                }
                Err(Self::map_server_error(&msg))
            }
            ServerEvent::Ok => Ok(None),
            ServerEvent::Info(data) => {
                self.handle_info(&data)?;
                Ok(None)
            }
        }
    }

    fn handle_message(&self, data: crate::protocol::MsgData) -> Result<Option<Message>, NatsError> {
        let headers = match data.headers {
            Some(raw) => Some(Headers::from_wire(&String::from_utf8_lossy(&raw))?),
            None => None,
        };
        let msg = Message {
            subject: data.subject.clone(),
            data: data.payload,
            reply_to: data.reply_to,
            headers,
            sid: Some(data.sid.clone()),
        };
        let mut inner = self.inner.lock();
        if let Some(inbox) = &inner.inbox_sub {
            if data.sid == inbox.sid {
                if let Some(token) = extract_inbox_token(&inner.inbox_prefix, &data.subject) {
                    if let Some(slot) = inner.pending_requests.get_mut(&token) {
                        *slot = (Some(msg.clone()), true);
                        return Ok(Some(msg));
                    }
                }
            }
        }
        if let Some(sub) = inner.subscriptions.get(&data.sid).cloned() {
            drop(inner);
            if sub.is_active() {
                sub.deliver(msg.clone());
                if !sub.is_active() {
                    self.inner.lock().subscriptions.remove(&data.sid);
                }
            }
        }
        Ok(Some(msg))
    }

    fn handle_info(&self, data: &Value) -> Result<(), NatsError> {
        let info = ServerInfo::from_value(data);
        {
            let mut inner = self.inner.lock();
            inner.server_info = Some(info.clone());
            for cu in &info.connect_urls {
                let n = normalize_url(cu);
                if !inner.server_pool.contains(&n) {
                    inner.server_pool.push(n);
                }
            }
        }
        if data.get("ldm").and_then(Value::as_bool) == Some(true) {
            self.handle_lame_duck()?;
        }
        Ok(())
    }

    fn handle_lame_duck(&self) -> Result<(), NatsError> {
        let (cb, others, allow, current) = {
            let inner = self.inner.lock();
            let others: Vec<String> = inner
                .server_pool
                .iter()
                .filter(|u| *u != &inner.current_server)
                .cloned()
                .collect();
            (
                inner.options.on_lame_duck.clone(),
                others,
                inner.options.allow_reconnect,
                inner.current_server.clone(),
            )
        };
        if let Some(cb) = cb {
            cb();
        }
        if !others.is_empty() && allow {
            {
                let mut inner = self.inner.lock();
                inner.server_pool = others;
                inner.server_pool.push(current);
            }
            self.attempt_reconnect()?;
        }
        Ok(())
    }

    fn check_pings(&self) -> Result<(), NatsError> {
        let (status, interval, max_out, outstanding, last) = {
            let inner = self.inner.lock();
            (
                inner.status.clone(),
                inner.options.ping_interval,
                inner.options.max_pings_out,
                inner.outstanding_pings,
                inner.last_ping_time,
            )
        };
        if status != STATUS_CONNECTED {
            return Ok(());
        }
        if last.elapsed().as_secs_f64() >= interval {
            if outstanding >= max_out {
                if self.inner.lock().options.allow_reconnect {
                    return self.attempt_reconnect();
                }
                return Err(ConnectionException(
                    "Stale connection: too many outstanding pings".into(),
                )
                .into());
            }
            let ping = self.inner.lock().writer.ping().to_owned();
            self.send(ping.as_bytes())?;
            let mut inner = self.inner.lock();
            inner.outstanding_pings += 1;
            inner.last_ping_time = Instant::now();
        }
        Ok(())
    }

    fn attempt_reconnect(&self) -> Result<(), NatsError> {
        {
            let mut inner = self.inner.lock();
            if inner.status == STATUS_CLOSED || inner.status == STATUS_RECONNECTING {
                return Ok(());
            }
            inner.status = STATUS_RECONNECTING.into();
        }
        if let Some(cb) = self.inner.lock().options.on_disconnect.clone() {
            cb();
        }
        if let Some(t) = self.inner.lock().transport.clone() {
            t.close();
        }
        let (attempts, wait, cap) = {
            let o = &self.inner.lock().options;
            (
                o.max_reconnect_attempts,
                o.reconnect_wait,
                o.max_reconnect_wait,
            )
        };
        for attempt in 0..attempts {
            if attempt > 0 {
                let backoff = Self::reconnect_backoff(attempt, wait, cap, 2.0);
                std::thread::sleep(Duration::from_secs_f64(backoff.max(0.0)));
            }
            let pool = self.inner.lock().server_pool.clone();
            for url in pool {
                if self.connect_to_server(&url).is_ok() {
                    self.inner.lock().status = STATUS_CONNECTED.into();
                    self.inner.lock().outstanding_pings = 0;
                    let subs: Vec<Subscription> =
                        self.inner.lock().subscriptions.values().cloned().collect();
                    for sub in subs {
                        if sub.is_active() {
                            let cmd = self.inner.lock().writer.sub(
                                &sub.subject,
                                &sub.sid,
                                sub.queue.as_deref(),
                            );
                            let _ = self.send(cmd.as_bytes());
                        }
                    }
                    if let Some(cb) = self.inner.lock().options.on_reconnect.clone() {
                        cb();
                    }
                    return Ok(());
                }
            }
        }
        self.inner.lock().status = STATUS_DISCONNECTED.into();
        Err(ConnectionException("Failed to reconnect to any NATS server".into()).into())
    }

    fn ensure_connected(&self) -> Result<(), NatsError> {
        let status = self.inner.lock().status.clone();
        if status != STATUS_CONNECTED && status != STATUS_DRAINING {
            return Err(ConnectionException(format!("Not connected (status: {status})")).into());
        }
        Ok(())
    }

    fn send(&self, data: &[u8]) -> Result<(), NatsError> {
        if self.inner.lock().status == STATUS_RECONNECTING {
            self.buffer_pending(data);
            return Ok(());
        }
        let transport = self
            .inner
            .lock()
            .transport
            .clone()
            .ok_or_else(|| ConnectionException("Not connected".into()))?;
        match transport.write(data) {
            Ok(_) => Ok(()),
            Err(e) if e.is_connection() => {
                if self.inner.lock().options.allow_reconnect
                    && self.inner.lock().status != STATUS_CLOSED
                {
                    self.buffer_pending(data);
                    self.attempt_reconnect()?;
                    return Ok(());
                }
                Err(e)
            }
            Err(e) => Err(e),
        }
    }

    fn buffer_pending(&self, data: &[u8]) {
        let mut inner = self.inner.lock();
        if !Self::reconnect_buffer_accepts(
            inner.pending_buffer_bytes,
            data.len() as i64,
            inner.options.reconnect_buf_size,
        ) {
            if let Some(cb) = inner.options.on_error.clone() {
                drop(inner);
                cb(NatsException(
                    "Reconnect buffer full; dropping pending message".into(),
                ));
            }
            return;
        }
        inner.pending_buffer.push(data.to_vec());
        inner.pending_buffer_bytes += data.len() as i64;
    }
}

fn resolve_authenticator(options: &ConnectionOptions) -> Result<Box<dyn Authenticator>, NatsError> {
    if let Some(file) = &options.credentials_file {
        return Ok(Box::new(CredentialsAuth::new(file)?));
    }
    if let (Some(nkey), Some(seed)) = (&options.nkey, &options.nkey_seed) {
        return Ok(Box::new(NKeyAuth::new(nkey, seed)));
    }
    if let Some(token) = &options.token {
        return Ok(Box::new(TokenAuth::new(token)));
    }
    if let (Some(user), Some(pass)) = (&options.user, &options.pass) {
        return Ok(Box::new(UserPassAuth::new(user, pass)));
    }
    Ok(Box::new(NoAuth))
}

fn build_server_pool(options: &ConnectionOptions) -> Vec<String> {
    let mut servers: Vec<String> = options.servers.iter().map(|s| normalize_url(s)).collect();
    if !options.no_randomize && servers.len() > 1 {
        use rand::seq::SliceRandom;
        servers.shuffle(&mut rand::thread_rng());
    }
    servers
}

fn normalize_url(url: &str) -> String {
    if url.starts_with("nats://")
        || url.starts_with("tls://")
        || url.starts_with("ws://")
        || url.starts_with("wss://")
    {
        url.to_owned()
    } else {
        format!("nats://{url}")
    }
}

fn parse_url(url: &str) -> Result<(String, String, u16), NatsError> {
    let parsed = Url::parse(url)
        .or_else(|_| Url::parse(&format!("nats://{url}")))
        .map_err(|_| ConnectionException(format!("Invalid server URL: {url}")))?;
    Ok((
        parsed.scheme().to_owned(),
        parsed.host_str().unwrap_or("127.0.0.1").to_owned(),
        parsed.port().unwrap_or(4222),
    ))
}

fn extract_inbox_token(prefix: &str, subject: &str) -> Option<String> {
    let p = format!("{prefix}.");
    subject.strip_prefix(&p).map(str::to_owned)
}
