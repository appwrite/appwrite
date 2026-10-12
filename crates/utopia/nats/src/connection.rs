//! The connection: handshake, publish, subscribe, request-reply,
//! keepalive, reconnection and the server's errors.

use std::collections::HashMap;
use std::time::{Duration, Instant};

use futures_util::future::BoxFuture;
use indexmap::IndexMap;
use rand::RngExt;
use serde_json::{Map, Value};

use crate::auth::{Authenticator, CredentialsAuth, NKeyAuth, NoAuth, TokenAuth, UserPassAuth};
use crate::error::{CallbackError, Error, Result};
use crate::headers::Headers;
use crate::jetstream::JetStream;
use crate::message::{Inbox, Message, Request};
use crate::options::{ConnectionOptions, Hook};
use crate::protocol::{Op, Parser, RawMessage, Writer};
use crate::publisher::Publisher;
use crate::server_info::ServerInfo;
use crate::subscription::{MessageCallback, Subscription, SubscriptionState};
use crate::transport::{TcpTransport, TlsTransport, Transport, WebSocketTransport};

/// The `lang` and `version` a connection announces.
const CLIENT_LANG: &str = "php";
const CLIENT_VERSION: &str = "0.1.0";

/// How long, and how many times, `tick` reads to collect its own PONGs.
const TICK_READ_TIMEOUT: Duration = Duration::from_millis(50);
const TICK_MAX_READS: usize = 4;

/// Server `-ERR` texts (lower case, matched as substrings) after which the
/// server closes the connection.
const CLOSING_ERRORS: [&str; 12] = [
    "stale connection",
    "slow consumer",
    "maximum payload",
    "invalid client protocol",
    "authorization violation",
    "authentication timeout",
    "authentication expired",
    "secure connection - tls required",
    "maximum connections exceeded",
    "maximum control line exceeded",
    "unknown protocol operation",
    "parser error",
];

/// The closing errors a reconnect cannot fix.
const UNRECOVERABLE_ERRORS: [&str; 8] = [
    "authorization violation",
    "authentication timeout",
    "authentication expired",
    "secure connection - tls required",
    "maximum connections exceeded",
    "maximum control line exceeded",
    "unknown protocol operation",
    "parser error",
];

/// Where a connection is in its life.
///
/// PHP: the `Connection::STATUS_*` strings `getStatus()` returns.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum Status {
    /// Not connected (never, or the connection was lost for good).
    Disconnected,
    /// The first connect is in progress.
    Connecting,
    /// Connected.
    Connected,
    /// Rebuilding a lost connection.
    Reconnecting,
    /// Unsubscribing and flushing before closing.
    Draining,
    /// Closed by the caller.
    Closed,
}

impl Status {
    /// `disconnected`, `connecting`, `connected`, `reconnecting`,
    /// `draining` or `closed`.
    pub fn as_str(self) -> &'static str {
        match self {
            Status::Disconnected => "disconnected",
            Status::Connecting => "connecting",
            Status::Connected => "connected",
            Status::Reconnecting => "reconnecting",
            Status::Draining => "draining",
            Status::Closed => "closed",
        }
    }
}

/// Stop conditions of [`Connection::request_many`].
///
/// PHP: the `$opts` array (`max`, `timeout`, `stall`).
#[derive(Debug, Clone, Copy, Default, PartialEq, Eq)]
pub struct RequestMany {
    /// Stop after this many replies.
    pub max: Option<usize>,
    /// Overall deadline (the connection's request timeout by default).
    pub timeout: Option<Duration>,
    /// Stop when no reply arrives for this long.
    pub stall: Option<Duration>,
}

/// A connection to a NATS server (or cluster).
///
/// One owner drives it: every call takes `&mut self`, and nothing happens
/// between calls. Messages for subscriptions are read and dispatched by
/// [`Connection::process_message`], [`Connection::wait`],
/// [`Connection::next_message`] and the request calls; keepalives by those
/// and [`Connection::tick`].
///
/// ```
/// # tokio::runtime::Builder::new_current_thread().enable_all().build()?.block_on(async {
/// use utopia_nats::{Connection, ConnectionOptions};
///
/// # let url = std::env::var("NATS_URL").unwrap_or("nats://127.0.0.1:4222".into());
/// # let options = ConnectionOptions { connect_timeout: std::time::Duration::from_millis(200), ..ConnectionOptions::new([url]) };
/// let Ok(mut connection) = Connection::connect(options).await else { return Ok(()) };
/// let orders = connection.subscribe("orders.*", None, None).await?;
/// connection.publish("orders.new", "{\"id\":1}", None, None).await?;
/// let message = connection.next_message(&orders, Some(std::time::Duration::from_secs(1))).await?.unwrap();
/// assert_eq!(message.subject, "orders.new");
/// connection.close().await?;
/// # Ok::<(), Box<dyn std::error::Error>>(())
/// # })?;
/// # Ok::<(), Box<dyn std::error::Error>>(())
/// ```
///
/// ```php
/// use Utopia\NATS\Connection;
/// use Utopia\NATS\ConnectionOptions;
/// use Utopia\NATS\Exception\ConnectionException;
///
/// try {
///     $connection = Connection::connect(new ConnectionOptions(servers: getenv('NATS_URL') ?: 'nats://127.0.0.1:4222', connectTimeout: 0.2));
/// } catch (ConnectionException) {
///     return;
/// }
/// $orders = $connection->subscribe('orders.*');
/// $connection->publish('orders.new', '{"id":1}');
/// $orders->nextMessage(1.0)->subject; // 'orders.new'
/// $connection->close();
/// ```
///
/// PHP: `Utopia\NATS\Connection`.
pub struct Connection {
    options: ConnectionOptions,
    auth: Box<dyn Authenticator>,
    transport: Option<Box<dyn Transport>>,
    /// Whether `transport` is the built-in TCP transport, which upgrades
    /// to TLS when the server asks.
    plain_tcp: bool,
    parser: Parser,
    server_info: Option<ServerInfo>,
    subscriptions: IndexMap<String, SubscriptionState>,
    /// Unsubscribed subscriptions whose queue still holds messages.
    retired: HashMap<String, SubscriptionState>,
    next_sid: u64,
    status: Status,
    outstanding_pings: i64,
    last_ping: Instant,
    inbox_sid: Option<String>,
    inbox_prefix: String,
    collecting: bool,
    server_pool: Vec<String>,
    current_server: String,
    pending_buffer: Vec<Vec<u8>>,
    pending_buffer_bytes: i64,
    publisher: Publisher,
    /// The buffer publishes are encoded into, kept between calls.
    scratch: Vec<u8>,
}

/// The largest publish buffer kept for the next publish.
const SCRATCH_KEEP: usize = 64 * 1024;

impl std::fmt::Debug for Connection {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Connection")
            .field("status", &self.status)
            .field("server", &self.current_server)
            .field("subscriptions", &self.subscriptions.len())
            .finish_non_exhaustive()
    }
}

impl Connection {
    /// Connects to the first server of the pool that completes the
    /// handshake (`INFO`, `CONNECT`, `PING`/`PONG`).
    ///
    /// # Errors
    ///
    /// [`Error::Connection`] (`Failed to connect to any NATS server`) when
    /// no server answers; [`Error::Authentication`] for an unreadable
    /// credentials file.
    ///
    /// PHP: `Connection::connect()`.
    pub async fn connect(options: impl Into<ConnectionOptions>) -> Result<Connection> {
        let options = options.into();
        let auth = resolve_authenticator(&options).await?;
        let server_pool = build_server_pool(&options);
        let mut connection = Connection {
            inbox_prefix: String::new(),
            options,
            auth,
            transport: None,
            plain_tcp: false,
            parser: Parser::new(),
            server_info: None,
            subscriptions: IndexMap::new(),
            retired: HashMap::new(),
            next_sid: 1,
            status: Status::Disconnected,
            outstanding_pings: 0,
            last_ping: Instant::now(),
            inbox_sid: None,
            collecting: false,
            server_pool,
            current_server: String::new(),
            pending_buffer: Vec::new(),
            pending_buffer_bytes: 0,
            publisher: Publisher::default(),
            scratch: Vec::new(),
        };
        connection.do_connect().await?;
        Ok(connection)
    }

    /// Publishes `data` to `subject`, with an optional reply subject and
    /// headers.
    ///
    /// # Errors
    ///
    /// [`Error::Connection`] when not connected, [`Error::Protocol`] for
    /// headers the server does not support, [`Error::MaxPayload`] when the
    /// headers and payload exceed the server's maximum.
    ///
    /// PHP: `Connection::publish()`.
    pub async fn publish(
        &mut self,
        subject: &str,
        data: impl AsRef<[u8]>,
        reply_to: Option<&str>,
        headers: Option<&Headers>,
    ) -> Result<()> {
        self.ensure_connected().await?;
        let mut wire = std::mem::take(&mut self.scratch);
        wire.clear();
        let encoded = self.encode_into(&mut wire, subject, data.as_ref(), reply_to, headers);
        let sent = match encoded {
            Ok(()) => self.send_bytes(&wire).await,
            Err(error) => Err(error),
        };
        if wire.capacity() <= SCRATCH_KEEP {
            self.scratch = wire;
        }
        sent
    }

    fn encode(&self, subject: &str, data: &[u8], reply_to: Option<&str>, headers: Option<&Headers>) -> Result<Vec<u8>> {
        let mut wire = Vec::new();
        self.encode_into(&mut wire, subject, data, reply_to, headers)?;
        Ok(wire)
    }

    fn encode_into(
        &self,
        wire: &mut Vec<u8>,
        subject: &str,
        data: &[u8],
        reply_to: Option<&str>,
        headers: Option<&Headers>,
    ) -> Result<()> {
        let headers = headers.filter(|h| !h.is_empty());
        if headers.is_some() && self.server_info.as_ref().is_some_and(|i| !i.headers_supported) {
            return Err(Error::Protocol("Server does not support message headers".into()));
        }
        let header_wire = headers.map(Headers::to_wire).unwrap_or_default();
        let size = (header_wire.len() + data.len()) as i64;
        if let Some(info) = &self.server_info
            && size > info.max_payload
        {
            return Err(Error::MaxPayload(format!(
                "Payload size {size} exceeds server maximum of {}",
                info.max_payload
            )));
        }
        wire.reserve(subject.len() + header_wire.len() + data.len() + 48);
        if headers.is_some() {
            Writer::hpub_into(wire, subject, &header_wire, data, reply_to);
        } else {
            Writer::publish_into(wire, subject, data, reply_to);
        }
        Ok(())
    }

    /// Subscribes to `subject` (wildcards allowed), in `queue` group if
    /// given. With a callback, every message goes to it as it is read;
    /// without, messages queue for [`Connection::next_message`].
    ///
    /// # Errors
    ///
    /// [`Error::Connection`] when not connected.
    ///
    /// PHP: `Connection::subscribe()`.
    pub async fn subscribe(
        &mut self,
        subject: &str,
        callback: Option<MessageCallback>,
        queue: Option<&str>,
    ) -> Result<Subscription> {
        self.ensure_connected().await?;
        let sid = self.next_sid.to_string();
        self.next_sid += 1;
        let state = SubscriptionState::new(
            sid.clone(),
            subject,
            queue.map(str::to_owned),
            callback,
            self.options.sub_pending_msgs_limit,
            self.options.sub_pending_bytes_limit,
            self.options.on_slow_consumer.clone(),
        );
        let handle = state.handle();
        self.subscriptions.insert(sid.clone(), state);
        self.send(Writer::sub(subject, &sid, queue)).await?;
        Ok(handle)
    }

    /// [`Connection::subscribe`] in a queue group: each message goes to one
    /// member of the group.
    ///
    /// PHP: `Connection::queueSubscribe()`.
    pub async fn queue_subscribe(
        &mut self,
        subject: &str,
        queue: &str,
        callback: Option<MessageCallback>,
    ) -> Result<Subscription> {
        self.subscribe(subject, callback, Some(queue)).await
    }

    /// Unsubscribes now, or after `max_messages` more messages. A
    /// subscription unsubscribed now keeps the messages it queued.
    ///
    /// PHP: `Connection::unsubscribe()`, `Subscription::unsubscribe()`.
    pub async fn unsubscribe(&mut self, subscription: &Subscription, max_messages: Option<i64>) -> Result<()> {
        let sid = &subscription.sid;
        match max_messages {
            Some(max) => {
                if let Some(state) = self.subscriptions.get_mut(sid) {
                    let total = state.received() + max;
                    state.set_max_messages(total);
                }
                self.send(Writer::unsub(sid, Some(max))).await
            }
            None => {
                if let Some(mut state) = self.subscriptions.shift_remove(sid) {
                    state.set_inactive();
                    self.retire(state);
                }
                self.send(Writer::unsub(sid, None)).await
            }
        }
    }

    fn retire(&mut self, state: SubscriptionState) {
        if state.pending_count() > 0 {
            self.retired.insert(state.sid().to_owned(), state);
        }
    }

    /// The state of a subscription (its counters, queue and limits), while
    /// the connection still has it.
    ///
    /// PHP: `Subscription::isActive()`, `getReceived()`,
    /// `getPendingCount()`, `getPendingBytes()`, `hasCallback()`.
    pub fn subscription_state(&self, subscription: &Subscription) -> Option<&SubscriptionState> {
        self.subscriptions.get(&subscription.sid).or_else(|| self.retired.get(&subscription.sid))
    }

    /// How many subscriptions the connection holds (the request inbox
    /// included).
    pub fn subscription_count(&self) -> usize {
        self.subscriptions.len()
    }

    /// The next message queued for `subscription`, reading the connection
    /// until one arrives or `timeout` passes (`None` waits without limit).
    ///
    /// PHP: `Subscription::nextMessage()`.
    pub async fn next_message(
        &mut self,
        subscription: &Subscription,
        timeout: Option<Duration>,
    ) -> Result<Option<Message>> {
        let deadline = timeout.map(|t| Instant::now() + t);
        loop {
            let sid = &subscription.sid;
            if let Some(state) = self.subscriptions.get_mut(sid) {
                if let Some(message) = state.next_message() {
                    return Ok(Some(message));
                }
                if !state.is_active() {
                    return Ok(None);
                }
            } else if let Some(state) = self.retired.get_mut(sid) {
                let message = state.next_message();
                if state.pending_count() == 0 {
                    self.retired.remove(sid);
                }
                return Ok(message);
            } else {
                return Ok(None);
            }
            let remaining = match deadline {
                Some(deadline) => match deadline.checked_duration_since(Instant::now()) {
                    Some(left) if !left.is_zero() => Some(left),
                    _ => return Ok(None),
                },
                None => None,
            };
            self.process_message(remaining).await?;
        }
    }

    /// A request: publishes `data` to `subject` with a reply inbox and
    /// waits for the first reply. Retries once when the server reported a
    /// stale connection and reconnecting is allowed.
    ///
    /// # Errors
    ///
    /// [`Error::InvalidArgument`] for a subject that cannot be published to,
    /// [`Error::NoResponders`] when nobody is subscribed, [`Error::Timeout`]
    /// (`Request timed out after ...s`), and the errors of
    /// [`Connection::publish`].
    ///
    /// PHP: `Connection::request()`.
    pub async fn request(
        &mut self,
        subject: &str,
        data: impl AsRef<[u8]>,
        timeout: Option<Duration>,
        headers: Option<&Headers>,
    ) -> Result<Message> {
        let request = Request::new(subject, data.as_ref(), headers.cloned())?;
        let requests = [request];
        let mut attempt = 0;
        loop {
            let mut outcome: Option<Result<Message>> = None;
            self.request_batch(
                &requests,
                |_, result| {
                    outcome = Some(result);
                    Ok(())
                },
                timeout,
            )
            .await?;
            if attempt == 0
                && let Some(Err(Error::Protocol(message))) = &outcome
                && message.to_ascii_lowercase().contains("stale connection")
                && self.options.allow_reconnect
                && self.status == Status::Disconnected
            {
                self.attempt_reconnect().await?;
                attempt += 1;
                continue;
            }
            return match outcome {
                Some(result) => result,
                None => Err(Error::Logic("Request completed without an outcome".into())),
            };
        }
    }

    /// Sends independent requests together and calls `reply` with each
    /// request's index and outcome as it arrives. One deadline, starting
    /// after the write, covers every reply (callbacks included). Requests
    /// already written are never sent again.
    ///
    /// # Errors
    ///
    /// [`Error::Logic`] when called while a batch is collecting,
    /// [`Error::InvalidArgument`] for a zero timeout, the encoding errors of
    /// [`Connection::publish`] (before anything is written), and the error
    /// `reply` returns. Failures after the write go to `reply` instead.
    ///
    /// PHP: `Connection::requestBatch()`.
    pub async fn request_batch<F>(
        &mut self,
        requests: &[Request],
        mut reply: F,
        timeout: Option<Duration>,
    ) -> Result<()>
    where
        F: FnMut(usize, Result<Message>) -> Result<(), CallbackError> + Send,
    {
        self.assert_readable()?;
        let timeout = timeout.unwrap_or(self.options.request_timeout);
        if timeout.is_zero() {
            return Err(Error::InvalidArgument("Requests must be a list with a finite positive timeout".into()));
        }
        if requests.is_empty() {
            return Ok(());
        }
        let mut pending: IndexMap<String, usize> = IndexMap::with_capacity(requests.len());
        let mut publishing = false;
        let mut written = false;
        self.collecting = true;
        let collected = self.collect(requests, &mut reply, timeout, &mut pending, &mut publishing, &mut written).await;
        let result = match collected {
            Ok(()) => Ok(()),
            Err(error) if error.is_nats() => {
                self.fail_batch(error, timeout, &mut pending, publishing, written, &mut reply).await
            }
            Err(error) => Err(error),
        };
        self.collecting = false;
        result
    }

    async fn collect<F>(
        &mut self,
        requests: &[Request],
        reply: &mut F,
        timeout: Duration,
        pending: &mut IndexMap<String, usize>,
        publishing: &mut bool,
        written: &mut bool,
    ) -> Result<()>
    where
        F: FnMut(usize, Result<Message>) -> Result<(), CallbackError> + Send,
    {
        self.ensure_connected().await?;
        self.ensure_inbox_sub().await?;
        let mut wire = Vec::new();
        for (index, request) in requests.iter().enumerate() {
            let token = Inbox::generate_id();
            let inbox = format!("{}.{token}", self.inbox_prefix);
            pending.insert(token, index);
            wire.extend(self.encode(request.subject(), request.data(), Some(&inbox), request.headers())?);
        }
        *publishing = true;
        self.transport()?.write(&wire).await?;
        *written = true;
        let deadline = Instant::now() + timeout;
        while !pending.is_empty() {
            let remaining = deadline.saturating_duration_since(Instant::now());
            if remaining.is_zero() {
                return Err(Error::Timeout(format!("Requests timed out after {}s", seconds(timeout))));
            }
            self.check_pings().await?;
            let Some(message) = self.read_message(Some(remaining), false).await? else {
                continue;
            };
            let Some(token) = self.extract_inbox_token(&message.subject) else {
                continue;
            };
            if let Some(index) = pending.shift_remove(token) {
                let outcome = if message.status() == "503" {
                    Err(Error::NoResponders("No responders for request".into()))
                } else {
                    Ok(message)
                };
                reply(index, outcome).map_err(|e| Error::Callback(e.into()))?;
            }
        }
        Ok(())
    }

    async fn fail_batch<F>(
        &mut self,
        error: Error,
        timeout: Duration,
        pending: &mut IndexMap<String, usize>,
        publishing: bool,
        written: bool,
        reply: &mut F,
    ) -> Result<()>
    where
        F: FnMut(usize, Result<Message>) -> Result<(), CallbackError> + Send,
    {
        let error = if written && matches!(error, Error::Timeout(_)) {
            Error::Timeout(format!("Request timed out after {}s", seconds(timeout)))
        } else {
            error
        };
        if error.is_connection() && (!written || !matches!(error, Error::Timeout(_))) {
            self.recycle_dead_connection(false).await?;
        }
        if !publishing {
            return Err(error);
        }
        for (_, index) in pending.drain(..) {
            reply(index, Err(error.clone())).map_err(|e| Error::Callback(e.into()))?;
        }
        Ok(())
    }

    fn assert_readable(&self) -> Result<()> {
        if self.collecting {
            return Err(Error::Logic("Cannot read the connection while collecting requests".into()));
        }
        Ok(())
    }

    /// Calls an option hook, its error as [`Error::Callback`].
    fn notify(&self, hook: &Option<Hook>) -> Result<()> {
        match hook {
            Some(hook) => hook().map_err(|e| Error::Callback(e.into())),
            None => Ok(()),
        }
    }

    /// Scatter-gather: publishes once to a dedicated inbox and collects
    /// replies until `max` arrived, the timeout passed, no reply came for
    /// `stall`, or a 503 (no responders) arrived.
    ///
    /// PHP: `Connection::requestMany()`.
    pub async fn request_many(
        &mut self,
        subject: &str,
        data: impl AsRef<[u8]>,
        options: RequestMany,
    ) -> Result<Vec<Message>> {
        self.assert_readable()?;
        self.ensure_connected().await?;
        let timeout = options.timeout.unwrap_or(self.options.request_timeout);
        let inbox = Inbox::create(&self.options.inbox_prefix);
        let subscription = self.subscribe(&inbox, None, None).await?;
        self.publish(subject, data, Some(&inbox), None).await?;
        let deadline = Instant::now() + timeout;
        let mut messages = Vec::new();
        while options.max.is_none_or(|max| messages.len() < max) {
            let remaining = deadline.saturating_duration_since(Instant::now());
            if remaining.is_zero() {
                break;
            }
            let wait = options.stall.map_or(remaining, |stall| remaining.min(stall));
            let Some(message) = self.next_message(&subscription, Some(wait)).await? else {
                break;
            };
            if message.status() == "503" {
                break;
            }
            messages.push(message);
        }
        self.unsubscribe(&subscription, None).await?;
        Ok(messages)
    }

    /// A new unique inbox subject under the configured prefix.
    ///
    /// PHP: `Connection::newInbox()`.
    pub fn new_inbox(&self) -> String {
        Inbox::create(&self.options.inbox_prefix)
    }

    /// Sends the keepalive `PING` when it is due and recycles a connection
    /// whose server stopped answering, without consuming messages. A holder
    /// that never reads (a pooled publisher) calls this regularly.
    ///
    /// PHP: `Connection::tick()`.
    pub async fn tick(&mut self) -> Result<()> {
        self.assert_readable()?;
        self.check_pings().await?;
        let mut reads = 0;
        while self.outstanding_pings > 0 && reads < TICK_MAX_READS {
            self.read_message(Some(TICK_READ_TIMEOUT), true).await?;
            reads += 1;
        }
        Ok(())
    }

    /// Reads and dispatches one server operation; returns the message when
    /// it was one. A timeout returns `None`; a lost connection reconnects
    /// (when allowed) and returns `None`.
    ///
    /// PHP: `Connection::processMessage()`.
    pub async fn process_message(&mut self, timeout: Option<Duration>) -> Result<Option<Message>> {
        self.assert_readable()?;
        self.send_queued().await?;
        self.check_pings().await?;
        self.read_message(timeout, true).await
    }

    async fn read_message(&mut self, timeout: Option<Duration>, reconnect: bool) -> Result<Option<Message>> {
        let next = match self.transport.as_deref_mut() {
            Some(transport) => self.parser.next(transport, timeout).await,
            None => Err(Error::Connection("Not connected".into())),
        };
        match next {
            Ok(op) => self.dispatch(op).await,
            Err(Error::Timeout(message)) => {
                if reconnect {
                    Ok(None)
                } else {
                    Err(Error::Timeout(message))
                }
            }
            Err(error) if error.is_connection() => {
                if reconnect && self.options.allow_reconnect && self.status != Status::Closed {
                    self.attempt_reconnect().await?;
                    return Ok(None);
                }
                Err(error)
            }
            Err(error) => Err(error),
        }
    }

    /// Processes `count` messages (`0`: without end), or until `timeout`.
    ///
    /// PHP: `Connection::wait()`.
    pub async fn wait(&mut self, count: usize, timeout: Option<Duration>) -> Result<()> {
        let deadline = timeout.map(|t| Instant::now() + t);
        let mut processed = 0;
        while count == 0 || processed < count {
            let remaining = match deadline {
                Some(deadline) => match deadline.checked_duration_since(Instant::now()) {
                    Some(left) if !left.is_zero() => Some(left),
                    _ => return Ok(()),
                },
                None => None,
            };
            if self.process_message(remaining).await?.is_some() {
                processed += 1;
            }
        }
        Ok(())
    }

    /// A JetStream context on this connection's account (or `domain`, or
    /// an explicit API prefix).
    ///
    /// PHP: `Connection::jetStream()`.
    pub fn jetstream(&self, domain: Option<&str>, api_prefix: Option<&str>) -> JetStream {
        JetStream::new(domain, api_prefix)
    }

    /// A [`Publisher`] for callbacks: what it queues, this connection
    /// sends.
    pub fn publisher(&self) -> Publisher {
        self.publisher.clone()
    }

    /// Sends a `PING` and waits for its `PONG`: everything written before
    /// has reached the server.
    ///
    /// # Errors
    ///
    /// [`Error::Timeout`] (`Flush timed out`) and the read errors.
    ///
    /// PHP: `Connection::flush()`.
    pub async fn flush(&mut self, timeout: Option<Duration>) -> Result<()> {
        self.assert_readable()?;
        self.send_queued().await?;
        self.ensure_connected().await?;
        let timeout = timeout.unwrap_or(self.options.connect_timeout);
        self.send(Writer::ping().to_vec()).await?;
        let deadline = Instant::now() + timeout;
        loop {
            let remaining = deadline.saturating_duration_since(Instant::now());
            if remaining.is_zero() {
                return Err(Error::Timeout("Flush timed out".into()));
            }
            let op = self.next_op(remaining).await?;
            if op == Op::Pong {
                self.outstanding_pings = 0;
                return Ok(());
            }
            self.dispatch(op).await?;
        }
    }

    async fn next_op(&mut self, timeout: Duration) -> Result<Op> {
        match self.transport.as_deref_mut() {
            Some(transport) => self.parser.next(transport, Some(timeout)).await,
            None => Err(Error::Connection("Not connected".into())),
        }
    }

    /// Unsubscribes everything, delivers what the server already sent
    /// (up to its `PONG`, or the timeout), then closes.
    ///
    /// PHP: `Connection::drain()`.
    pub async fn drain(&mut self, timeout: Option<Duration>) -> Result<()> {
        self.assert_readable()?;
        if self.status != Status::Connected {
            return Ok(());
        }
        self.status = Status::Draining;
        let timeout = timeout.unwrap_or(self.options.drain_timeout);
        let sids: Vec<String> = self.subscriptions.keys().cloned().collect();
        for sid in sids {
            self.send(Writer::unsub(&sid, None)).await?;
        }
        self.send(Writer::ping().to_vec()).await?;
        let deadline = Instant::now() + timeout;
        loop {
            let remaining = deadline.saturating_duration_since(Instant::now());
            if remaining.is_zero() {
                break;
            }
            let op = match self.next_op(remaining).await {
                Ok(op) => op,
                Err(error) if error.is_connection() => break,
                Err(error) => return Err(error),
            };
            if op == Op::Pong {
                break;
            }
            self.dispatch(op).await?;
        }
        self.close().await
    }

    /// Closes the connection and every subscription (queued messages stay
    /// readable). Calls `on_close` when it was connected.
    ///
    /// PHP: `Connection::close()`.
    pub async fn close(&mut self) -> Result<()> {
        if self.status == Status::Closed {
            return Ok(());
        }
        let previous = self.status;
        self.status = Status::Closed;
        for (_, mut state) in std::mem::take(&mut self.subscriptions) {
            state.set_inactive();
            self.retire(state);
        }
        if let Some(transport) = self.transport.as_deref_mut() {
            transport.close().await;
        }
        if previous == Status::Connected {
            self.notify(&self.options.on_close)?;
        }
        Ok(())
    }

    /// Whether the connection is usable.
    ///
    /// PHP: `Connection::isConnected()`.
    pub fn is_connected(&self) -> bool {
        self.status == Status::Connected
    }

    /// Whether [`Connection::close`] ran.
    ///
    /// PHP: `Connection::isClosed()`.
    pub fn is_closed(&self) -> bool {
        self.status == Status::Closed
    }

    /// Whether a lost connection is being rebuilt.
    ///
    /// PHP: `Connection::isReconnecting()`.
    pub fn is_reconnecting(&self) -> bool {
        self.status == Status::Reconnecting
    }

    /// What the server announced (in its latest `INFO`).
    ///
    /// PHP: `Connection::getServerInfo()`.
    pub fn server_info(&self) -> Option<&ServerInfo> {
        self.server_info.as_ref()
    }

    /// The options the connection was made with.
    ///
    /// PHP: `Connection::getOptions()`.
    pub fn options(&self) -> &ConnectionOptions {
        &self.options
    }

    /// Where the connection is in its life.
    ///
    /// PHP: `Connection::getStatus()`.
    pub fn status(&self) -> Status {
        self.status
    }

    fn transport(&mut self) -> Result<&mut (dyn Transport + 'static)> {
        self.transport.as_deref_mut().ok_or_else(|| Error::Connection("Not connected".into()))
    }

    async fn do_connect(&mut self) -> Result<()> {
        self.status = Status::Connecting;
        for url in self.server_pool.clone() {
            if self.connect_to_server(&url).await.is_ok() {
                self.status = Status::Connected;
                return Ok(());
            }
        }
        self.status = Status::Disconnected;
        Err(Error::Connection("Failed to connect to any NATS server".into()))
    }

    async fn connect_to_server(&mut self, url: &str) -> Result<()> {
        let (scheme, host, port) = parse_server_url(url)?;
        let tls = self.options.tls_options();
        let (transport, plain_tcp): (Box<dyn Transport>, bool) = match &self.options.transport_factory {
            Some(factory) => (factory(&scheme).map_err(|e| Error::Callback(e.into()))?, false),
            None if scheme == "ws" || scheme == "wss" => {
                (Box::new(WebSocketTransport::new(scheme == "wss", tls.clone(), "/")), false)
            }
            None if scheme == "tls" || self.options.tls => (Box::new(TlsTransport::new(tls.clone())), false),
            None => (Box::new(TcpTransport::new()), true),
        };
        self.transport = Some(transport);
        self.plain_tcp = plain_tcp;
        let connect_timeout = self.options.connect_timeout;
        self.transport()?.connect(&host, port, connect_timeout).await?;
        self.parser = Parser::new();

        let op = self.next_op(connect_timeout).await?;
        let Op::Info(data) = op else {
            return Err(Error::Protocol(format!("Expected INFO, got {}", op.kind().as_str())));
        };
        if !data.is_object() {
            return Err(Error::Protocol("Expected INFO to be an object".into()));
        }
        let info = ServerInfo::from_value(&data);
        for connect_url in &info.connect_urls {
            if !self.server_pool.contains(connect_url) {
                self.server_pool.push(normalize_url(connect_url));
            }
        }
        let wants_upgrade = info.tls_required || (self.options.tls && info.tls_available);
        let nonce = info.nonce.clone();
        let headers_supported = info.headers_supported;
        self.server_info = Some(info);
        if self.plain_tcp && scheme != "tls" && wants_upgrade {
            self.transport()?.upgrade_tls(&tls).await?;
        }

        let payload = self.connect_payload(headers_supported, nonce.as_deref())?;
        let connect = Writer::connect(&Value::Object(payload))?;
        self.transport()?.write(&connect).await?;
        self.transport()?.write(Writer::ping()).await?;

        let deadline = Instant::now() + connect_timeout;
        loop {
            let remaining = deadline.saturating_duration_since(Instant::now());
            if remaining.is_zero() {
                return Err(Error::Timeout("Connection handshake timed out".into()));
            }
            match self.next_op(remaining).await? {
                Op::Pong => break,
                Op::Err(message) => {
                    self.transport()?.close().await;
                    let lower = message.to_ascii_lowercase();
                    if lower.contains("authorization") || lower.contains("authentication") {
                        return Err(Error::Authentication(format!("Server error: {message}")));
                    }
                    return Err(Error::Connection(format!("Server error: {message}")));
                }
                _ => {}
            }
        }
        self.last_ping = Instant::now();
        self.current_server = url.to_owned();
        Ok(())
    }

    fn connect_payload(&self, headers_supported: bool, nonce: Option<&str>) -> Result<Map<String, Value>> {
        let mut payload = Map::new();
        payload.insert("verbose".into(), Value::Bool(self.options.verbose));
        payload.insert("pedantic".into(), Value::Bool(self.options.pedantic));
        payload.insert("lang".into(), Value::String(CLIENT_LANG.into()));
        payload.insert("version".into(), Value::String(CLIENT_VERSION.into()));
        payload.insert("protocol".into(), Value::from(1));
        payload.insert("echo".into(), Value::Bool(self.options.echo));
        payload.insert("headers".into(), Value::Bool(headers_supported));
        payload.insert("no_responders".into(), Value::Bool(headers_supported));
        if !self.options.name.is_empty() {
            payload.insert("name".into(), Value::String(self.options.name.clone()));
        }
        for (key, value) in self.auth.authenticate(nonce)? {
            payload.insert(key, value);
        }
        if let Some(provider) = &self.options.token_provider {
            payload.insert("auth_token".into(), Value::String(provider().map_err(|e| Error::Callback(e.into()))?));
        }
        if let Some(provider) = &self.options.jwt_provider {
            payload.insert("jwt".into(), Value::String(provider().map_err(|e| Error::Callback(e.into()))?));
        }
        Ok(payload)
    }

    async fn dispatch(&mut self, op: Op) -> Result<Option<Message>> {
        match op {
            Op::Msg(raw) | Op::HMsg(raw) => self.handle_message(raw).await.map(Some),
            Op::Ping => {
                self.send(Writer::pong().to_vec()).await?;
                Ok(None)
            }
            Op::Pong => {
                self.outstanding_pings = (self.outstanding_pings - 1).max(0);
                Ok(None)
            }
            Op::Err(message) => Err(self.handle_error(message).await),
            Op::Ok => Ok(None),
            Op::Info(data) => {
                self.handle_info(data).await?;
                Ok(None)
            }
        }
    }

    async fn handle_message(&mut self, raw: RawMessage) -> Result<Message> {
        let headers = match &raw.headers {
            Some(block) => Some(Headers::from_wire(block)?),
            None => None,
        };
        let message = Message {
            subject: raw.subject,
            data: raw.payload.into(),
            reply_to: raw.reply_to,
            headers,
            sid: Some(raw.sid),
        };
        let sid = message.sid.as_deref().unwrap_or_default();
        if self.inbox_sid.as_deref() == Some(sid) {
            return Ok(message);
        }
        if let Some(state) = self.subscriptions.get_mut(sid)
            && state.is_active()
        {
            let delivered = state.deliver(message.clone());
            if let Err(error) = delivered {
                // What the callback queued before it failed still goes out.
                self.send_queued().await?;
                return Err(error);
            }
            if !state.is_active()
                && let Some(state) = self.subscriptions.shift_remove(sid)
            {
                self.retire(state);
            }
            self.send_queued().await?;
        }
        Ok(message)
    }

    /// Sends what [`Publisher`]s queued, each as [`Connection::publish`]
    /// would (the connection does this itself after every callback and
    /// before each read). While a batch collects, a failure is the
    /// callback's.
    pub async fn send_queued(&mut self) -> Result<()> {
        if self.publisher.is_empty() {
            return Ok(());
        }
        for queued in self.publisher.take() {
            let sent =
                self.publish(&queued.subject, &queued.data, queued.reply_to.as_deref(), queued.headers.as_ref()).await;
            if let Err(error) = sent {
                return Err(if self.collecting { Error::Callback(std::sync::Arc::new(error)) } else { error });
            }
        }
        Ok(())
    }

    /// The error for a server `-ERR`, after recycling the connection when
    /// the server closes it, and calling `on_error`.
    async fn handle_error(&mut self, message: String) -> Error {
        let error = Self::map_server_error(&message);
        if Self::closes_connection(&message) {
            let reconnect = !self.collecting && Self::reconnects_after(&message);
            if let Err(e) = self.recycle_dead_connection(reconnect).await {
                return e;
            }
        }
        if let Some(hook) = &self.options.on_error
            && let Err(e) = hook(&Error::Nats(message))
        {
            return Error::Callback(e.into());
        }
        error
    }

    async fn recycle_dead_connection(&mut self, may_reconnect: bool) -> Result<()> {
        if may_reconnect && self.options.allow_reconnect && self.status != Status::Closed {
            return match self.attempt_reconnect().await {
                Err(error) if self.catches_connection(&error) => Ok(()),
                other => other,
            };
        }
        self.status = Status::Disconnected;
        if let Some(transport) = self.transport.as_deref_mut() {
            transport.close().await;
        }
        Ok(())
    }

    /// Whether PHP's `catch (ConnectionException)` takes this error: a
    /// connection error, or one a callback raised outside a batch (PHP
    /// only wraps callback errors while collecting).
    fn catches_connection(&self, error: &Error) -> bool {
        match error {
            Error::Callback(inner) if !self.collecting => {
                inner.downcast_ref::<Error>().is_some_and(Error::is_connection)
            }
            other => other.is_connection(),
        }
    }

    /// Whether the server closes the connection after this `-ERR`.
    ///
    /// PHP: `Connection::closesConnection()`.
    pub fn closes_connection(message: &str) -> bool {
        let lower = message.to_ascii_lowercase();
        CLOSING_ERRORS.iter().any(|needle| lower.contains(needle))
    }

    /// Whether reconnecting can help after this (closing) `-ERR`: not for
    /// rejected credentials, a TLS requirement, a full server or a protocol
    /// error.
    ///
    /// PHP: `Connection::reconnectsAfter()`.
    pub fn reconnects_after(message: &str) -> bool {
        let lower = message.to_ascii_lowercase();
        !UNRECOVERABLE_ERRORS.iter().any(|needle| lower.contains(needle))
    }

    /// The error a server `-ERR` text maps to.
    ///
    /// PHP: `Connection::mapServerError()`.
    pub fn map_server_error(message: &str) -> Error {
        let lower = message.to_ascii_lowercase();
        if lower.contains("permissions violation") {
            Error::Permission(message.to_owned())
        } else if lower.contains("authorization") || lower.contains("authentication") {
            Error::Authentication(message.to_owned())
        } else if lower.contains("maximum payload") {
            Error::MaxPayload(message.to_owned())
        } else {
            Error::Protocol(format!("Server error: {message}"))
        }
    }

    async fn handle_info(&mut self, data: Value) -> Result<()> {
        if !data.is_object() {
            return Ok(());
        }
        let info = ServerInfo::from_value(&data);
        for connect_url in &info.connect_urls {
            let normalized = normalize_url(connect_url);
            if !self.server_pool.contains(&normalized) {
                self.server_pool.push(normalized);
            }
        }
        self.server_info = Some(info);
        if data.get("ldm") == Some(&Value::Bool(true)) {
            self.notify(&self.options.on_lame_duck)?;
            let others: Vec<String> = self.server_pool.iter().filter(|u| **u != self.current_server).cloned().collect();
            if !others.is_empty() && !self.collecting && self.options.allow_reconnect {
                let current = self.current_server.clone();
                self.server_pool = others;
                self.server_pool.push(current);
                self.attempt_reconnect().await?;
            }
        }
        Ok(())
    }

    async fn check_pings(&mut self) -> Result<()> {
        if self.status != Status::Connected || self.parser.has_buffered_data() {
            return Ok(());
        }
        let now = Instant::now();
        if now.duration_since(self.last_ping) < self.options.ping_interval {
            return Ok(());
        }
        if self.check_stale().await? {
            return Ok(());
        }
        match self.send(Writer::ping().to_vec()).await {
            Ok(()) => {
                self.outstanding_pings += 1;
                self.last_ping = now;
                Ok(())
            }
            Err(error) if error.is_connection() => {
                if self.collecting {
                    return Err(error);
                }
                if self.options.allow_reconnect {
                    self.attempt_reconnect().await?;
                }
                Ok(())
            }
            Err(error) => Err(error),
        }
    }

    async fn check_stale(&mut self) -> Result<bool> {
        if self.status != Status::Connected || self.parser.has_buffered_data() {
            return Ok(false);
        }
        if self.outstanding_pings < self.options.max_pings_out {
            return Ok(false);
        }
        if !self.collecting && self.options.allow_reconnect {
            self.attempt_reconnect().await?;
            return Ok(true);
        }
        Err(Error::Connection("Stale connection: too many outstanding pings".into()))
    }

    fn attempt_reconnect(&mut self) -> BoxFuture<'_, Result<()>> {
        Box::pin(async move {
            if self.status == Status::Closed || self.status == Status::Reconnecting {
                return Ok(());
            }
            self.status = Status::Reconnecting;
            self.notify(&self.options.on_disconnect)?;
            if let Some(transport) = self.transport.as_deref_mut() {
                transport.close().await;
            }
            for attempt in 0..self.options.max_reconnect_attempts {
                if attempt > 0 {
                    let backoff = Self::reconnect_backoff(
                        attempt,
                        self.options.reconnect_wait.as_secs_f64(),
                        self.options.max_reconnect_wait.as_secs_f64(),
                        2.0,
                    );
                    let jitter: f64 = rand::rng().random();
                    let wait = backoff + jitter * self.options.reconnect_jitter.as_secs_f64();
                    tokio::time::sleep(Duration::from_secs_f64(wait.max(0.0))).await;
                }
                for url in self.server_pool.clone() {
                    match self.reconnect_to(&url).await {
                        Ok(()) => return Ok(()),
                        Err(error @ Error::Callback(_)) if self.collecting => return Err(error),
                        Err(_) => continue,
                    }
                }
            }
            self.status = Status::Disconnected;
            Err(Error::Connection("Failed to reconnect to any NATS server".into()))
        })
    }

    async fn reconnect_to(&mut self, url: &str) -> Result<()> {
        self.connect_to_server(url).await?;
        self.status = Status::Connected;
        self.outstanding_pings = 0;
        let active: Vec<Vec<u8>> = self
            .subscriptions
            .values()
            .filter(|s| s.is_active())
            .map(|s| Writer::sub(s.subject(), s.sid(), s.queue()))
            .collect();
        for sub in active {
            self.send(sub).await?;
        }
        let buffered = std::mem::take(&mut self.pending_buffer);
        self.pending_buffer_bytes = 0;
        for command in buffered {
            self.send(command).await?;
        }
        self.notify(&self.options.on_reconnect)
    }

    async fn ensure_connected(&mut self) -> Result<()> {
        if self.status != Status::Connected && self.status != Status::Draining {
            return Err(Error::Connection(format!("Not connected (status: {})", self.status.as_str())));
        }
        self.check_stale().await?;
        Ok(())
    }

    async fn send(&mut self, data: Vec<u8>) -> Result<()> {
        self.send_bytes(&data).await
    }

    async fn send_bytes(&mut self, data: &[u8]) -> Result<()> {
        if self.status == Status::Reconnecting {
            return self.buffer_pending(data);
        }
        let written = self.transport()?.write(data).await;
        match written {
            Ok(_) => Ok(()),
            Err(error) if error.is_connection() => {
                if !self.collecting && self.options.allow_reconnect && self.status != Status::Closed {
                    self.buffer_pending(data)?;
                    self.attempt_reconnect().await?;
                    return Ok(());
                }
                self.recycle_dead_connection(false).await?;
                Err(error)
            }
            Err(error) => Err(error),
        }
    }

    fn buffer_pending(&mut self, data: &[u8]) -> Result<()> {
        if !Self::reconnect_buffer_accepts(
            self.pending_buffer_bytes,
            data.len() as i64,
            self.options.reconnect_buf_size,
        ) {
            if let Some(hook) = &self.options.on_error {
                hook(&Error::Nats("Reconnect buffer full; dropping pending message".into()))
                    .map_err(|e| Error::Callback(e.into()))?;
            }
            return Ok(());
        }
        self.pending_buffer_bytes += data.len() as i64;
        self.pending_buffer.push(data.to_vec());
        Ok(())
    }

    /// The reconnect delay before `attempt` (seconds): none before the
    /// first, then `base * factor^(attempt - 1)`, at most `cap`.
    ///
    /// PHP: `Connection::reconnectBackoff()`.
    pub fn reconnect_backoff(attempt: i64, base: f64, cap: f64, factor: f64) -> f64 {
        if attempt <= 0 {
            return 0.0;
        }
        let delay = base * factor.powf((attempt - 1) as f64);
        php_min(delay, cap)
    }

    /// Whether `incoming` more bytes fit the reconnect buffer under `cap`
    /// (a cap of zero or less disables buffering).
    ///
    /// PHP: `Connection::reconnectBufferAccepts()`.
    pub fn reconnect_buffer_accepts(current: i64, incoming: i64, cap: i64) -> bool {
        cap > 0 && current.wrapping_add(incoming) <= cap
    }

    async fn ensure_inbox_sub(&mut self) -> Result<()> {
        if self.inbox_sid.is_some() {
            return Ok(());
        }
        self.inbox_prefix = format!("{}.{}", self.options.inbox_prefix, Inbox::generate_id());
        let subject = format!("{}.*", self.inbox_prefix);
        let subscription = self.subscribe(&subject, None, None).await?;
        self.inbox_sid = Some(subscription.sid);
        Ok(())
    }

    fn extract_inbox_token<'a>(&self, subject: &'a str) -> Option<&'a str> {
        subject.strip_prefix(self.inbox_prefix.as_str())?.strip_prefix('.')
    }
}

/// PHP's `min($a, $b)`: the first argument unless the second is smaller.
fn php_min(a: f64, b: f64) -> f64 {
    if b < a { b } else { a }
}

/// A float of seconds as PHP prints it in a string (`0.5`, `5`).
pub(crate) fn seconds(duration: Duration) -> String {
    php_std::number::to_string(duration.as_secs_f64())
}

async fn resolve_authenticator(options: &ConnectionOptions) -> Result<Box<dyn Authenticator>> {
    if let Some(file) = &options.credentials_file {
        return Ok(Box::new(CredentialsAuth::from_file(file).await?));
    }
    if let (Some(nkey), Some(seed)) = (&options.nkey, &options.nkey_seed) {
        return Ok(Box::new(NKeyAuth::new(nkey.clone(), seed.clone())));
    }
    if let Some(token) = &options.token {
        return Ok(Box::new(TokenAuth::new(token.clone())));
    }
    if let (Some(user), Some(pass)) = (&options.user, &options.pass) {
        return Ok(Box::new(UserPassAuth::new(user.clone(), pass.clone())));
    }
    for url in &options.servers {
        let Some(parsed) = php_std::url::parse_url(url.as_bytes()) else {
            continue;
        };
        if let Some(user) = parsed.user() {
            let user = String::from_utf8_lossy(&php_std::encoding::rawurldecode(&user)).into_owned();
            let pass =
                parsed.pass().map(|p| String::from_utf8_lossy(&php_std::encoding::rawurldecode(&p)).into_owned());
            return Ok(match pass {
                Some(pass) if !pass.is_empty() => Box::new(UserPassAuth::new(user, pass)),
                _ => Box::new(TokenAuth::new(user)),
            });
        }
    }
    Ok(Box::new(NoAuth))
}

fn build_server_pool(options: &ConnectionOptions) -> Vec<String> {
    let mut servers: Vec<String> = options.servers.iter().map(|s| normalize_url(s)).collect();
    if !options.no_randomize && servers.len() > 1 {
        let mut rng = rand::rng();
        for i in (1..servers.len()).rev() {
            let j = rng.random_range(0..=i);
            servers.swap(i, j);
        }
    }
    servers
}

/// Adds `nats://` to a URL without a known scheme.
fn normalize_url(url: &str) -> String {
    if ["nats://", "tls://", "ws://", "wss://"].iter().any(|scheme| url.starts_with(scheme)) {
        url.to_owned()
    } else {
        format!("nats://{url}")
    }
}

/// The scheme, host and port of a server URL (`nats`, `127.0.0.1` and
/// `4222` when absent), as PHP's `parse_url` reads it.
fn parse_server_url(url: &str) -> Result<(String, String, u16)> {
    let Some(parsed) = php_std::url::parse_url(url.as_bytes()) else {
        return Err(Error::Connection(format!("Invalid server URL: {url}")));
    };
    let text = |b: Option<std::borrow::Cow<'_, [u8]>>| b.map(|b| String::from_utf8_lossy(&b).into_owned());
    let scheme = text(parsed.scheme()).unwrap_or_else(|| "nats".into());
    let host = text(parsed.host()).unwrap_or_else(|| "127.0.0.1".into());
    let port = parsed.port().unwrap_or(4222);
    Ok((scheme, host, port))
}
