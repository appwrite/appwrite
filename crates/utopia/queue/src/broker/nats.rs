//! The NATS JetStream broker.

use std::collections::{HashMap, HashSet};
use std::sync::atomic::{AtomicI64, Ordering};
use std::sync::{Arc, Mutex as StdMutex};
use std::time::{Duration, Instant};

use async_nats::jetstream::consumer::pull::Config as PullConfig;
use async_nats::jetstream::consumer::{AckPolicy, PullConsumer};
use async_nats::jetstream::stream::{Config as StreamConfig, DiscardPolicy, RetentionPolicy, StorageType};
use async_nats::jetstream::{self, AckKind, Context};
use async_nats::{Client, HeaderMap, Subscriber};
use futures_util::{FutureExt, StreamExt};
use php_std::{Array, Value};
use tokio::sync::Mutex;

use super::Provisioning;
use crate::codec::{Codec, Json};
use crate::connection::BoxFuture;
use crate::contract::{Clock, Consumer, Publisher, SystemClock};
use crate::error::{Error, Result};
use crate::message::{Message, envelope};
use crate::queue::Queue;

const STREAM_PREFIX: &str = "Q_";
const DEAD_STREAM_SUFFIX: &str = "_DEAD";
const SUBJECT_PREFIX: &str = "q";
const SUBJECT_NORMAL: &str = "normal";
const SUBJECT_DEAD: &str = "dead";
const CONSUMER_WORK: &str = "worker";
const CONSUMER_RETRY: &str = "retry";
const CONTENT_TYPE: &str = "Content-Type";
const MESSAGE_ID: &str = "Nats-Msg-Id";
const ADVISORY_MAX_DELIVERIES: &str = "$JS.EVENT.ADVISORY.CONSUMER.MAX_DELIVERIES";
const ADVISORY_GROUP: &str = "utopia_queue_dead_letter";
const MAX_STREAM_NAME: usize = 255;
const METADATA_IDENTITY: &str = "utopia_queue_identity";
const METADATA_SPARE_DELIVERY: &str = "utopia_queue_spare_delivery";
const PROVISION_ATTEMPTS: u32 = 5;
const PROVISION_BACKOFF_MS: u64 = 500;
const PUBLISH_RETRY_DELAYS: [f64; 4] = [0.0, 0.25, 1.0, 2.0];
const PUBLISH_RETRY_BUDGET: f64 = 10.0;

/// Derives a message's deduplication id from its payload.
pub type MessageId = Arc<dyn Fn(&Array) -> String + Send + Sync>;
/// Where the broker reports failures it cannot raise.
pub type OnError = Arc<dyn Fn(&Error) + Send + Sync>;

/// How the NATS broker provisions and drives a queue's streams and consumers.
///
/// Every setting is validated by [`Nats::new`] with PHP's messages, so a bad
/// combination fails at construction instead of inside the first publish.
#[derive(Clone)]
pub struct NatsConfig {
    /// Seconds the server waits for an ack before redelivering (default 30).
    pub ack_wait: f64,
    /// Deliveries before a message is dead-lettered (default 5).
    pub max_deliver: i64,
    /// Stream replicas (default 1).
    pub replicas: usize,
    /// Redelivery delays per attempt, in seconds; the first must equal `ack_wait`.
    pub backoff: Option<Vec<f64>>,
    /// Memory instead of file storage.
    pub memory: bool,
    /// Seconds a dead letter is kept (`None`: forever).
    pub dead_max_age: Option<f64>,
    /// Seconds the work stream remembers message ids (default 120).
    pub duplicate_window: f64,
    /// Derives message ids from payloads (default: a fresh `uniqid` each).
    pub message_id: Option<MessageId>,
    /// Receives failures that happen outside any call.
    pub on_error: Option<OnError>,
    /// Work stream message TTL in seconds (`None`: the queue's job TTL).
    pub max_age: Option<f64>,
    /// Largest message in bytes, on both streams.
    pub max_msg_size: Option<i64>,
    /// Most messages on the work stream (-1: unlimited).
    pub max_msgs: i64,
    /// Most bytes on the work stream (-1: unlimited).
    pub max_bytes: i64,
    /// Whether a full work stream refuses new messages (`true`) or drops old ones.
    pub discard_new: bool,
    /// Most unacknowledged messages per worker consumer.
    pub max_ack_pending: Option<i64>,
    /// Most parked pull requests per consumer.
    pub max_waiting: Option<i64>,
    /// Seconds of idleness before the server deletes a durable consumer.
    pub inactive_threshold: Option<f64>,
    /// Whether the broker may create and rewrite the queue's configuration.
    pub provisioning: Provisioning,
    /// How envelopes are written and read.
    pub codec: Arc<dyn Codec>,
    /// Seconds before a released message that could not be requeued is redelivered.
    pub release_delay: f64,
}

impl Default for NatsConfig {
    fn default() -> Self {
        Self {
            ack_wait: 30.0,
            max_deliver: 5,
            replicas: 1,
            backoff: None,
            memory: false,
            dead_max_age: None,
            duplicate_window: 120.0,
            message_id: None,
            on_error: None,
            max_age: None,
            max_msg_size: None,
            max_msgs: -1,
            max_bytes: -1,
            discard_new: false,
            max_ack_pending: None,
            max_waiting: None,
            inactive_threshold: None,
            provisioning: Provisioning::Ensure,
            codec: Arc::new(Json),
            release_delay: 5.0,
        }
    }
}

fn php_float(f: f64) -> String {
    php_std::number::to_string(f)
}

impl NatsConfig {
    fn validate(&self) -> Result<()> {
        let invalid = |m: &str| Err(Error::InvalidArgument(m.to_owned()));
        if let Some(backoff) = &self.backoff {
            if backoff.is_empty() || backoff.iter().any(|b| *b <= 0.0) {
                return invalid("backoff must be a non-empty list of positive delays (seconds)");
            }
            if backoff[0] != self.ack_wait {
                return Err(Error::InvalidArgument(format!(
                    "JetStream requires the first backoff entry to equal ackWait: got backoff[0]={}, ackWait={}",
                    php_float(backoff[0]),
                    php_float(self.ack_wait)
                )));
            }
            if self.max_deliver <= backoff.len() as i64 {
                return Err(Error::InvalidArgument(format!(
                    "JetStream requires maxDeliver ({}) to exceed the number of backoff entries ({})",
                    self.max_deliver,
                    backoff.len()
                )));
            }
        }
        if self.dead_max_age.is_some_and(|a| a <= 0.0) {
            return invalid("deadMaxAge must be a positive number of seconds, or null to keep dead messages forever");
        }
        if self.duplicate_window <= 0.0 {
            return invalid("duplicateWindow must be a positive number of seconds");
        }
        if self.release_delay < 0.0 {
            return invalid("releaseDelay must be zero or a positive number of seconds");
        }
        if self.max_age.is_some_and(|a| a <= 0.0) {
            return invalid(
                "maxAge must be a positive number of seconds, or null to derive it from the queue's jobTtl",
            );
        }
        if self.max_msg_size.is_some_and(|s| s <= 0) {
            return invalid("maxMsgSize must be a positive number of bytes, or null for no limit");
        }
        for (name, limit) in [("maxMsgs", self.max_msgs), ("maxBytes", self.max_bytes)] {
            if limit != -1 && limit <= 0 {
                return Err(Error::InvalidArgument(format!("{name} must be a positive limit, or -1 for unlimited")));
            }
        }
        if self.discard_new && self.max_msgs == -1 && self.max_bytes == -1 {
            return invalid(
                "discard: New requires a maxMsgs or maxBytes limit — on an unlimited stream it never applies",
            );
        }
        if self.max_ack_pending.is_some_and(|m| m < 1) {
            return invalid("maxAckPending must be at least 1, or null for the server default");
        }
        if self.max_waiting.is_some_and(|m| m < 1) {
            return invalid("maxWaiting must be at least 1, or null for the server default");
        }
        if self.inactive_threshold.is_some_and(|t| t <= 0.0) {
            return invalid(
                "inactiveThreshold must be a positive number of seconds, or null to keep durable consumers forever",
            );
        }
        Ok(())
    }
}

/// Where the broker's connection comes from.
#[derive(Clone)]
enum Source {
    /// A server URL: the broker connects, and reconnects after a failure.
    Url(String),
    /// A live client: the only connection the broker will ever have.
    Client(Client),
}

#[derive(Default)]
struct State {
    provisioned: HashSet<String>,
    consumers: HashMap<String, PullConsumer>,
    in_flight: HashMap<Vec<u8>, jetstream::Message>,
    advisories: HashMap<String, Arc<Mutex<Subscriber>>>,
    spare: HashMap<String, Option<i64>>,
    deferred: Vec<Error>,
}

/// The NATS JetStream broker (`Broker\Nats`): each queue is a work-queue
/// stream `Q_<NAME>` with one subject `q.<name>.normal` served by the durable
/// pull consumer `worker`, and a dead stream `Q_<NAME>_DEAD` on `q.<name>.dead`.
///
/// Redelivery and dead-lettering are the server's: a rejected message is
/// NAK'd with the tier backoff and redelivered until `max_deliver`, then
/// terminated and copied to the dead stream; a terminal message goes there at
/// once. A worker that dies is reclaimed by `ack_wait` redelivery, so
/// [`Nats::reap`] does nothing. Every publish carries its pid as
/// `Nats-Msg-Id`, so a retried publish inside the duplicate window is stored
/// once, and the codec's `Content-Type` header.
///
/// ```no_run
/// use utopia_queue::broker::{Nats, NatsConfig};
/// use utopia_queue::{Consumer, Publisher, Queue};
///
/// # async fn run() -> Result<(), utopia_queue::Error> {
/// let broker = Nats::new("nats://localhost:4222", NatsConfig::default())?;
/// let queue = Queue::new("audits")?;
/// broker.publish(&queue, utopia_queue::payload(&serde_json::json!({"event": "login"}))?).await?;
/// for message in broker.receive(&queue, 1, 10).await? {
///     broker.commit(&queue, &message).await?;
/// }
/// # Ok(()) }
/// ```
///
/// ```php
/// use Utopia\NATS\Connection;
/// use Utopia\Queue\Broker\Nats;
///
/// $broker = new Nats(fn (): Connection => Connection::connect('nats://compat-queue-nats:4222'), ackWait: 30.0);
/// var_dump($broker->inFlightCeiling()); // NULL
/// ```
pub struct Nats {
    source: Source,
    config: NatsConfig,
    client: Mutex<Option<Client>>,
    receive: Mutex<()>,
    state: StdMutex<State>,
    duplicates: AtomicI64,
    closed: std::sync::atomic::AtomicBool,
    commands_opened: std::sync::atomic::AtomicBool,
    commands_closed: std::sync::atomic::AtomicBool,
    clock: Arc<dyn Clock>,
}

fn nats_error(class: &'static str, e: impl std::fmt::Display) -> Error {
    Error::Nats { class, message: e.to_string() }
}

fn jetstream_error(e: impl std::fmt::Display) -> Error {
    nats_error("Utopia\\NATS\\Exception\\JetStreamException", e)
}

/// A failed publish as the PHP NATS client's exception: no stream answered,
/// the ack timed out, the socket closed, or the server refused the message.
fn publish_error(error: async_nats::jetstream::context::PublishError) -> Error {
    use async_nats::jetstream::context::PublishErrorKind as Kind;
    let class = match error.kind() {
        Kind::StreamNotFound => "Utopia\\NATS\\Exception\\NoRespondersException",
        Kind::TimedOut => "Utopia\\NATS\\Exception\\TimeoutException",
        Kind::BrokenPipe => "Utopia\\NATS\\Exception\\ConnectionException",
        _ => "Utopia\\NATS\\Exception\\JetStreamException",
    };
    nats_error(class, error)
}

fn not_found(e: &impl std::fmt::Display) -> bool {
    let text = e.to_string();
    text.contains("not found") || text.contains("10059") || text.contains("10014")
}

impl Nats {
    /// A broker that connects to `url` (and reconnects after a failure).
    pub fn new(url: impl Into<String>, config: NatsConfig) -> Result<Self> {
        Self::build(Source::Url(url.into()), config)
    }

    /// A broker on a live client, which it can never replace.
    pub fn with_client(client: Client, config: NatsConfig) -> Result<Self> {
        Self::build(Source::Client(client), config)
    }

    fn build(source: Source, config: NatsConfig) -> Result<Self> {
        config.validate()?;
        Ok(Self {
            source,
            config,
            client: Mutex::new(None),
            receive: Mutex::new(()),
            state: StdMutex::new(State::default()),
            duplicates: AtomicI64::new(0),
            closed: std::sync::atomic::AtomicBool::new(false),
            commands_opened: std::sync::atomic::AtomicBool::new(false),
            commands_closed: std::sync::atomic::AtomicBool::new(false),
            clock: Arc::new(SystemClock),
        })
    }

    /// The clock and id source (tests pin it).
    pub fn with_clock(mut self, clock: Arc<dyn Clock>) -> Self {
        self.clock = clock;
        self
    }

    fn state(&self) -> std::sync::MutexGuard<'_, State> {
        self.state.lock().unwrap_or_else(|e| e.into_inner())
    }

    async fn client(&self) -> Result<Client> {
        let mut slot = self.client.lock().await;
        if let Some(c) = slot.as_ref() {
            return Ok(c.clone());
        }
        let client = match &self.source {
            Source::Client(c) => c.clone(),
            Source::Url(url) => async_nats::connect(url.as_str())
                .await
                .map_err(|e| nats_error("Utopia\\NATS\\Exception\\ConnectionException", e))?,
        };
        *slot = Some(client.clone());
        Ok(client)
    }

    async fn js(&self) -> Result<Context> {
        Ok(jetstream::new(self.client().await?))
    }

    /// Publishes the stream recognised as duplicates of an id it already held.
    pub fn duplicates(&self) -> i64 {
        self.duplicates.load(Ordering::SeqCst)
    }

    fn report(&self, error: Error) {
        if self.config.on_error.is_some() {
            self.state().deferred.push(error);
        }
    }

    fn flush_reports(&self) {
        let owed = std::mem::take(&mut self.state().deferred);
        if let Some(on_error) = &self.config.on_error {
            for e in &owed {
                on_error(e);
            }
        }
    }

    fn message_id(&self, payload: &Array) -> Result<String> {
        let Some(derive) = &self.config.message_id else {
            return Ok(self.clock.uniqid());
        };
        let id = derive(payload);
        if id.is_empty() || id.bytes().any(|b| b == b'\r' || b == b'\n' || b == 0) {
            return Err(Error::InvalidArgument(
                "messageId must return a non-empty string free of CR, LF and NUL".into(),
            ));
        }
        Ok(id)
    }

    fn envelope(&self, queue: &Queue, payload: Array) -> Result<(String, Value)> {
        let pid = self.message_id(&payload)?;
        let value = envelope(&pid, queue.name(), self.clock.time(), payload, None);
        Ok((pid, value))
    }

    fn headers(&self, content_type: Option<&str>, id: Option<&str>) -> HeaderMap {
        let mut h = HeaderMap::new();
        if let Some(ct) = content_type {
            h.insert(CONTENT_TYPE, ct);
        }
        if let Some(id) = id {
            h.insert(MESSAGE_ID, id);
        }
        h
    }

    /// Runs `publish` again while the server could not take the message yet
    /// (no stream leader, a closed socket), within a budget. Every attempt is
    /// whole: a payload keeps its id, so the ones that did land collapse.
    async fn republishing<T, F, Fut>(&self, mut publish: F) -> Result<T>
    where
        F: FnMut() -> Fut,
        Fut: std::future::Future<Output = Result<T>>,
    {
        let deadline = Instant::now() + Duration::from_secs_f64(PUBLISH_RETRY_BUDGET);
        let mut republishes = 0;
        loop {
            let error = match publish().await {
                Ok(value) => return Ok(value),
                Err(e) => e,
            };
            let retryable = matches!(&error, Error::Nats { class, .. } if [
                "Utopia\\NATS\\Exception\\NoRespondersException",
                "Utopia\\NATS\\Exception\\TimeoutException",
                "Utopia\\NATS\\Exception\\ConnectionException",
            ]
            .contains(class));
            let delay = PUBLISH_RETRY_DELAYS.get(republishes).copied();
            republishes += 1;
            match delay {
                Some(d) if retryable && Instant::now() + Duration::from_secs_f64(d) < deadline => {
                    if d > 0.0 {
                        tokio::time::sleep(Duration::from_secs_f64(d)).await;
                    }
                }
                _ => return Err(error),
            }
        }
    }

    /// One attempt at the stream, waiting for its ack.
    async fn publish_once(
        &self,
        subject: &str,
        data: &[u8],
        headers: HeaderMap,
    ) -> Result<jetstream::publish::PublishAck> {
        let js = self.js().await?;
        let attempt = async { js.publish_with_headers(subject.to_owned(), headers, data.to_vec().into()).await?.await };
        attempt.await.map_err(publish_error)
    }

    /// Publishes on the stream with [`Nats::republishing`]. With a queue, it
    /// is provisioned inside each attempt, not before: in a rolling restart
    /// the provisioning request is what meets the next server going away.
    async fn publish_acked(
        &self,
        queue: Option<&Queue>,
        subject: &str,
        data: &[u8],
        headers: HeaderMap,
    ) -> Result<jetstream::publish::PublishAck> {
        self.republishing(|| {
            let headers = headers.clone();
            async move {
                if let Some(queue) = queue {
                    self.ensure(queue).await?;
                }
                self.publish_once(subject, data, headers).await
            }
        })
        .await
    }

    async fn publish_envelope(&self, queue: &Queue, subject: &str, pid: &str, value: &Value) -> Result<()> {
        let data = self.config.codec.encode(value)?;
        let headers = self.headers(Some(self.config.codec.content_type()), Some(pid));
        let ack = self.publish_acked(Some(queue), subject, &data, headers).await?;
        if ack.duplicate {
            self.duplicates.fetch_add(1, Ordering::SeqCst);
        }
        Ok(())
    }

    async fn pull(&self, queue: &Queue, timeout: i64, max: usize) -> Result<Vec<Message>> {
        self.ensure(queue).await?;
        let key = identity(queue);
        self.drain_dead_letters(queue, &key).await;
        let consumer =
            self.state().consumers.get(&key).cloned().ok_or_else(|| Error::Runtime("consumer missing".into()))?;
        let mut deliveries = self.fetch(&consumer, 1, timeout as f64, false).await?;
        if !deliveries.is_empty() && deliveries.len() < max {
            let more = self.fetch(&consumer, max - deliveries.len(), 0.25, true).await?;
            deliveries.extend(more);
        }
        let mut spare = self.state().spare.get(&key).copied().flatten();
        let mut messages = Vec::with_capacity(deliveries.len());
        for delivery in deliveries {
            let (delivered, sequence) = match delivery.info() {
                Ok(i) => (i.delivered, i.stream_sequence as i64),
                Err(_) => (1, 0),
            };
            if let Some(s) = spare
                && delivered >= s
            {
                match consumer.clone().info().await {
                    Ok(info) => {
                        spare = spare_of(&info.config);
                        self.state().spare.insert(key.clone(), spare);
                    }
                    Err(e) => self.report(jetstream_error(e)),
                }
                if spare.is_some_and(|s| delivered >= s) {
                    self.park(queue, &delivery, "max deliveries exceeded").await;
                    continue;
                }
            }
            let size = delivery.payload.len() as i64;
            Message::warn_if_large(queue.name(), size);
            let data = self.config.codec.decode(&delivery.payload).ok();
            let envelope = match data {
                Some(Value::Array(a))
                    if ["pid", "queue", "timestamp"]
                        .iter()
                        .all(|k| a.get_str(k).is_some_and(|v| !matches!(v, Value::Null))) =>
                {
                    a
                }
                _ => {
                    self.park(queue, &delivery, "payload could not be decoded").await;
                    continue;
                }
            };
            let mut message = Message::from_envelope(&envelope)?;
            message.set_attempts((delivered - 1).max(0)).set_sequence(Some(sequence)).set_size(Some(size));
            self.state().in_flight.insert(message.pid().as_bytes().to_vec(), delivery);
            messages.push(message);
        }
        Ok(messages)
    }

    async fn fetch(
        &self,
        consumer: &PullConsumer,
        batch: usize,
        timeout: f64,
        no_wait: bool,
    ) -> Result<Vec<jetstream::Message>> {
        let mut out = Vec::new();
        if !no_wait && timeout <= 0.0 {
            // A pull that may not wait at all collects nothing before its deadline.
            return Ok(out);
        }
        if no_wait {
            let mut stream = consumer.fetch().max_messages(batch).messages().await.map_err(jetstream_error)?;
            while let Some(m) = stream.next().await {
                match m {
                    Ok(m) => out.push(m),
                    Err(_) => break,
                }
            }
        } else {
            let expires = Duration::from_secs_f64(timeout.max(0.001));
            let mut stream =
                consumer.batch().max_messages(batch).expires(expires).messages().await.map_err(jetstream_error)?;
            while let Some(m) = stream.next().await {
                match m {
                    Ok(m) => out.push(m),
                    Err(_) => break,
                }
            }
        }
        Ok(out)
    }

    /// Sets aside a message no handler should see: straight to the dead
    /// stream with its own `Content-Type`, then terminated.
    async fn park(&self, queue: &Queue, delivery: &jetstream::Message, reason: &str) {
        let content_type = delivery.headers.as_ref().and_then(|h| h.get(CONTENT_TYPE)).map(|v| v.to_string());
        let headers = self.headers(content_type.as_deref(), None);
        let result = async {
            let js = self.js().await?;
            js.publish_with_headers(dead_subject(queue), headers, delivery.payload.clone())
                .await
                .map_err(jetstream_error)?
                .await
                .map_err(jetstream_error)?;
            delivery.ack_with(AckKind::Term).await.map_err(jetstream_error)?;
            let _ = reason;
            Ok::<_, Error>(())
        };
        if let Err(e) = result.await {
            self.report(e);
        }
    }

    /// Returns prefetched work without spending an attempt: requeued as a new
    /// message (id `<pid>.released.<sequence>`) and acked, or NAK'd after
    /// `release_delay` when the stream may evict to make room. Never fails.
    pub async fn release_messages(&self, queue: &Queue, messages: &[Message]) {
        let mut requeue: Option<bool> = None;
        for message in messages {
            let Some(delivery) = self.state().in_flight.remove(message.pid().as_bytes()) else { continue };
            let mut stored = false;
            let attempt = async {
                if requeue.is_none() {
                    let js = self.js().await?;
                    let mut stream = js.get_stream(work_stream(queue)).await.map_err(jetstream_error)?;
                    let config = &stream.info().await.map_err(jetstream_error)?.config;
                    requeue = Some(
                        matches!(config.discard, DiscardPolicy::New)
                            || (config.max_messages == -1
                                && config.max_bytes == -1
                                && config.max_messages_per_subject == -1),
                    );
                }
                if requeue == Some(true) {
                    let sequence = delivery.info().map(|i| i.stream_sequence).unwrap_or(0);
                    let content_type =
                        delivery.headers.as_ref().and_then(|h| h.get(CONTENT_TYPE)).map(|v| v.to_string());
                    let id = format!("{}.released.{sequence}", String::from_utf8_lossy(message.pid().as_bytes()));
                    self.publish_acked(
                        None,
                        &work_subject(queue),
                        &delivery.payload,
                        self.headers(content_type.as_deref(), Some(&id)),
                    )
                    .await?;
                    stored = true;
                    delivery.ack().await.map_err(jetstream_error)?;
                    return Ok::<_, Error>(true);
                }
                Ok(false)
            };
            match attempt.await {
                Ok(true) => continue,
                Ok(false) => {}
                Err(e) => {
                    self.report(e);
                    if stored {
                        continue;
                    }
                }
            }
            let delay = (self.config.release_delay > 0.0).then(|| Duration::from_secs_f64(self.config.release_delay));
            if let Err(e) = delivery.ack_with(AckKind::Nak(delay)).await {
                self.report(jetstream_error(e));
            }
        }
        self.flush_reports();
    }

    /// Tells the server handlers are still working on these messages.
    pub async fn extend_messages(&self, messages: &[Message]) -> Result<()> {
        let deliveries: Vec<jetstream::Message> = {
            let state = self.state();
            messages.iter().filter_map(|m| state.in_flight.get(m.pid().as_bytes()).cloned()).collect()
        };
        for d in deliveries {
            d.ack_with(AckKind::Progress).await.map_err(jetstream_error)?;
        }
        Ok(())
    }

    /// A third of `ack_wait`, at least 0.1s.
    pub fn extend_interval_secs(&self) -> f64 {
        (self.config.ack_wait / 3.0).max(0.1)
    }

    /// Drops the connection and everything derived from it; `false` when the
    /// broker was built on a live client it cannot replace.
    pub async fn reconnect(&self) -> bool {
        self.close_client().await;
        if matches!(self.source, Source::Client(_)) {
            return false;
        }
        *self.state() = State::default();
        self.commands_closed.store(false, Ordering::SeqCst);
        true
    }

    /// A publish on a closed broker reconnects first (when it can).
    async fn reopen(&self) -> Result<()> {
        if self.closed.load(Ordering::SeqCst) {
            if !self.reconnect().await {
                return Err(nats_error(
                    "Utopia\\NATS\\Exception\\ConnectionException",
                    "Not connected (status: closed)",
                ));
            }
            self.closed.store(false, Ordering::SeqCst);
        }
        Ok(())
    }

    async fn close_client(&self) {
        if let Some(c) = self.client.lock().await.take()
            && matches!(self.source, Source::Url(_))
        {
            let _ = c.drain().await;
        }
    }

    /// Re-drives dead-lettered messages onto the work queue, up to `limit`
    /// (all those on the dead stream when the sweep starts).
    pub async fn retry_dead(&self, queue: &Queue, limit: Option<i64>) -> Result<()> {
        let _guard = self.receive.lock().await;
        self.ensure(queue).await?;
        let js = self.js().await?;
        let stream = js.get_stream(dead_stream(queue)).await.map_err(jetstream_error)?;
        let mut consumer: PullConsumer = stream
            .create_consumer(PullConfig {
                durable_name: Some(CONSUMER_RETRY.into()),
                ack_policy: AckPolicy::Explicit,
                ack_wait: Duration::from_secs_f64(self.config.ack_wait),
                filter_subject: dead_subject(queue),
                ..Default::default()
            })
            .await
            .map_err(jetstream_error)?;
        let mut remaining = match limit {
            Some(l) => l,
            None => consumer.info().await.map_err(jetstream_error)?.num_pending as i64,
        };
        while remaining > 0 {
            let Some(delivery) = self.fetch(&consumer, 1, 1.0, false).await?.into_iter().next() else { break };
            js.publish(work_subject(queue), delivery.payload.clone())
                .await
                .map_err(jetstream_error)?
                .await
                .map_err(jetstream_error)?;
            delivery.double_ack().await.map_err(jetstream_error)?;
            remaining -= 1;
        }
        Ok(())
    }

    /// Always 0: the server's `ack_wait` redelivery reclaims stranded work.
    pub fn reap(&self) -> i64 {
        0
    }

    /// Creates the queue's streams and work consumer now (under
    /// [`Provisioning::Require`]: checks they exist).
    pub async fn provision(&self, queue: &Queue) -> Result<()> {
        let _guard = self.receive.lock().await;
        self.ensure(queue).await
    }

    async fn ensure(&self, queue: &Queue) -> Result<()> {
        let key = identity(queue);
        if self.state().provisioned.contains(&key) {
            return Ok(());
        }
        if self.config.provisioning == Provisioning::Require {
            return self.adopt(queue, &key).await;
        }
        let mut attempt = 0;
        loop {
            match self.provision_once(queue, &key).await {
                Err(Error::Nats { class, message }) if class == "Utopia\\NATS\\Exception\\TimeoutException" => {
                    attempt += 1;
                    if attempt >= PROVISION_ATTEMPTS {
                        return Err(Error::Nats { class, message });
                    }
                    let window = PROVISION_BACKOFF_MS << (attempt - 1);
                    tokio::time::sleep(Duration::from_millis(crate::connection::jitter(window))).await;
                }
                other => return other,
            }
        }
    }

    fn stream_config(&self, queue: &Queue, key: &str, dead: bool) -> StreamConfig {
        let max_age = self.config.max_age.or((queue.job_ttl() > 0).then_some(queue.job_ttl() as f64));
        let storage = if self.config.memory { StorageType::Memory } else { StorageType::File };
        let mut metadata = HashMap::new();
        metadata.insert(METADATA_IDENTITY.to_owned(), key.to_owned());
        let size = self.config.max_msg_size.map_or(-1, |s| s as i32);
        if dead {
            return StreamConfig {
                name: dead_stream(queue),
                subjects: vec![dead_subject(queue)],
                description: Some(key.to_owned()),
                retention: RetentionPolicy::WorkQueue,
                max_message_size: size,
                max_age: self.config.dead_max_age.map_or(Duration::ZERO, Duration::from_secs_f64),
                storage,
                num_replicas: self.config.replicas,
                metadata,
                ..Default::default()
            };
        }
        let window = max_age.map_or(self.config.duplicate_window, |a| self.config.duplicate_window.min(a));
        StreamConfig {
            name: work_stream(queue),
            subjects: vec![work_subject(queue)],
            description: Some(key.to_owned()),
            retention: RetentionPolicy::WorkQueue,
            max_messages: self.config.max_msgs,
            max_bytes: self.config.max_bytes,
            max_message_size: size,
            max_age: max_age.map_or(Duration::ZERO, Duration::from_secs_f64),
            storage,
            num_replicas: self.config.replicas,
            discard: if self.config.discard_new { DiscardPolicy::New } else { DiscardPolicy::Old },
            duplicate_window: Duration::from_secs_f64(window),
            metadata,
            ..Default::default()
        }
    }

    async fn create_or_update(&self, js: &Context, config: StreamConfig) -> Result<()> {
        match js.get_stream(&config.name).await {
            Ok(_) => js.update_stream(config).await.map(|_| ()).map_err(jetstream_error),
            Err(e) if not_found(&e) => js.create_stream(config).await.map(|_| ()).map_err(jetstream_error),
            Err(e) => Err(jetstream_error(e)),
        }
    }

    async fn provision_once(&self, queue: &Queue, key: &str) -> Result<()> {
        self.guard_stream_name(queue, key).await?;
        let js = self.js().await?;
        self.create_or_update(&js, self.stream_config(queue, key, false)).await?;
        self.create_or_update(&js, self.stream_config(queue, key, true)).await?;
        let stream = js.get_stream(work_stream(queue)).await.map_err(jetstream_error)?;
        let mut metadata = HashMap::new();
        metadata.insert(METADATA_SPARE_DELIVERY.to_owned(), "1".to_owned());
        let consumer: PullConsumer = stream
            .create_consumer(PullConfig {
                durable_name: Some(CONSUMER_WORK.into()),
                ack_policy: AckPolicy::Explicit,
                ack_wait: Duration::from_secs_f64(self.config.ack_wait),
                max_deliver: self.config.max_deliver + 1,
                filter_subject: work_subject(queue),
                max_waiting: self.config.max_waiting.unwrap_or(0),
                max_ack_pending: self.config.max_ack_pending.unwrap_or(0),
                inactive_threshold: self.config.inactive_threshold.map_or(Duration::ZERO, Duration::from_secs_f64),
                metadata,
                backoff: self
                    .config
                    .backoff
                    .as_ref()
                    .map_or_else(Vec::new, |b| b.iter().map(|s| Duration::from_secs_f64(*s)).collect()),
                ..Default::default()
            })
            .await
            .map_err(jetstream_error)?;
        let advisory = self.subscribe_advisory(queue).await?;
        let mut state = self.state();
        state.consumers.insert(key.to_owned(), consumer);
        state.advisories.insert(key.to_owned(), advisory);
        state.spare.insert(key.to_owned(), Some(self.config.max_deliver + 1));
        state.provisioned.insert(key.to_owned());
        Ok(())
    }

    async fn subscribe_advisory(&self, queue: &Queue) -> Result<Arc<Mutex<Subscriber>>> {
        let client = self.client().await?;
        let subject = format!("{ADVISORY_MAX_DELIVERIES}.{}.*", work_stream(queue));
        let sub = client
            .queue_subscribe(subject, ADVISORY_GROUP.to_owned())
            .await
            .map_err(|e| nats_error("Utopia\\NATS\\Exception\\ConnectionException", e))?;
        Ok(Arc::new(Mutex::new(sub)))
    }

    async fn adopt(&self, queue: &Queue, key: &str) -> Result<()> {
        self.guard_stream_name(queue, key).await?;
        let js = self.js().await?;
        for stream in [work_stream(queue), dead_stream(queue)] {
            if let Err(e) = js.get_stream(&stream).await {
                if not_found(&e) {
                    return Err(Error::Runtime(format!(
                        "NATS stream \"{stream}\" is not provisioned; queue \"{}\" must be created by its own producer or consumer before this broker can use it.",
                        queue.name()
                    )));
                }
                return Err(jetstream_error(e));
            }
        }
        let stream_name = work_stream(queue);
        let stream = js.get_stream(&stream_name).await.map_err(jetstream_error)?;
        let mut consumer: PullConsumer = match stream.get_consumer(CONSUMER_WORK).await {
            Ok(c) => c,
            Err(e) if not_found(&e) => {
                return Err(Error::Runtime(format!(
                    "NATS consumer \"{CONSUMER_WORK}\" on stream \"{stream_name}\" is not provisioned; queue \"{}\" must be created by its own producer or consumer before this broker can use it.",
                    queue.name()
                )));
            }
            Err(e) => return Err(jetstream_error(e)),
        };
        let config = consumer.info().await.map_err(jetstream_error)?.config.clone();
        let spare = spare_of(&config);
        let allowed = config.max_deliver;
        if spare.is_none() && allowed >= 1 {
            self.report(Error::Runtime(format!(
                "NATS consumer \"{CONSUMER_WORK}\" on stream \"{stream_name}\" allows no delivery past max_deliver {allowed}, so a message exhausted while no broker is subscribed is left on the work stream; reprovision it from the queue's owner."
            )));
        }
        let advisory = self.subscribe_advisory(queue).await?;
        let mut state = self.state();
        state.consumers.insert(key.to_owned(), consumer);
        state.advisories.insert(key.to_owned(), advisory);
        state.spare.insert(key.to_owned(), spare);
        state.provisioned.insert(key.to_owned());
        Ok(())
    }

    async fn guard_stream_name(&self, queue: &Queue, identity: &str) -> Result<()> {
        let longest = dead_stream(queue);
        if longest.len() > MAX_STREAM_NAME {
            return Err(Error::Runtime(format!(
                "NATS stream name \"{longest}\" exceeds JetStream's {MAX_STREAM_NAME}-byte limit; shorten queue \"{}\".",
                queue.name()
            )));
        }
        let stream = work_stream(queue);
        let js = self.js().await?;
        let owner = match js.get_stream(&stream).await {
            Ok(mut s) => s.info().await.map_err(jetstream_error)?.config.metadata.get(METADATA_IDENTITY).cloned(),
            Err(e) if not_found(&e) => None,
            Err(e) => return Err(jetstream_error(e)),
        };
        if let Some(owner) = owner
            && owner != identity
        {
            return Err(Error::Runtime(format!(
                "NATS stream \"{stream}\" already belongs to queue \"{owner}\", not \"{identity}\"; rename one queue."
            )));
        }
        Ok(())
    }

    /// Moves messages that exhausted `max_deliver` (per the advisory) to the dead stream.
    async fn drain_dead_letters(&self, queue: &Queue, key: &str) {
        let Some(advisory) = self.state().advisories.get(key).cloned() else { return };
        let mut sub = advisory.lock().await;
        while let Some(Some(event)) = sub.next().now_or_never() {
            let seq = serde_json::from_slice::<serde_json::Value>(&event.payload)
                .ok()
                .and_then(|v| v.get("stream_seq").and_then(serde_json::Value::as_u64));
            let Some(seq) = seq else { continue };
            let result = async {
                let js = self.js().await?;
                let stream = js.get_stream(work_stream(queue)).await.map_err(jetstream_error)?;
                let stored = stream.get_raw_message(seq).await.map_err(jetstream_error)?;
                js.publish(dead_subject(queue), stored.payload)
                    .await
                    .map_err(jetstream_error)?
                    .await
                    .map_err(jetstream_error)?;
                stream.delete_message(seq).await.map_err(jetstream_error)?;
                Ok::<_, Error>(())
            };
            if let Err(e) = result.await {
                self.report(e);
            }
        }
    }

    fn backoff_for(&self, delivered: i64) -> Option<Duration> {
        let backoff = self.config.backoff.as_ref()?;
        let index = (delivered.max(1) as usize).min(backoff.len()) - 1;
        Some(Duration::from_secs_f64(backoff[index]))
    }

    /// Before an acknowledgement or a count: the connection those use, which
    /// fails once after a close.
    fn command(&self) -> Result<()> {
        if self.commands_closed.swap(false, Ordering::SeqCst) {
            return Err(nats_error("Utopia\\NATS\\Exception\\ConnectionException", "Not connected (status: closed)"));
        }
        self.commands_opened.store(true, Ordering::SeqCst);
        Ok(())
    }

    async fn count(&self, read: impl Future<Output = Result<i64>>) -> Result<i64> {
        self.command()?;
        match read.await {
            Err(Error::Nats { message, .. }) if message.contains("not found") => Ok(0),
            other => other,
        }
    }
}

/// The spare delivery a work consumer's configuration allows, when marked.
fn spare_of(config: &async_nats::jetstream::consumer::Config) -> Option<i64> {
    let allowed = config.max_deliver;
    (config.metadata.contains_key(METADATA_SPARE_DELIVERY) && allowed >= 1).then_some(allowed)
}

/// Logical queue identity: length-prefixed namespace and name.
fn identity(queue: &Queue) -> String {
    format!("{}:{}:{}", queue.namespace().len(), queue.namespace(), queue.name())
}

/// Bytes outside `A-Za-z0-9_-` become `_`.
fn token(name: &str) -> String {
    name.bytes().map(|b| if b.is_ascii_alphanumeric() || b == b'_' || b == b'-' { b as char } else { '_' }).collect()
}

fn work_stream(queue: &Queue) -> String {
    format!("{STREAM_PREFIX}{}", token(queue.name()).to_ascii_uppercase())
}

fn dead_stream(queue: &Queue) -> String {
    format!("{}{DEAD_STREAM_SUFFIX}", work_stream(queue))
}

fn subject_base(queue: &Queue) -> String {
    format!("{SUBJECT_PREFIX}.{}", token(queue.name()).to_ascii_lowercase())
}

fn work_subject(queue: &Queue) -> String {
    format!("{}.{SUBJECT_NORMAL}", subject_base(queue))
}

fn dead_subject(queue: &Queue) -> String {
    format!("{}.{SUBJECT_DEAD}", subject_base(queue))
}

impl Publisher for Nats {
    fn publish<'a>(&'a self, queue: &'a Queue, payload: Array) -> BoxFuture<'a, Result<bool>> {
        Box::pin(async move {
            let subject = work_subject(queue);
            let (pid, value) = self.envelope(queue, payload)?;
            self.reopen().await?;
            let _guard = self.receive.lock().await;
            self.publish_envelope(queue, &subject, &pid, &value).await?;
            Ok(true)
        })
    }

    fn publish_many<'a>(&'a self, queue: &'a Queue, payloads: Vec<Array>) -> BoxFuture<'a, Result<bool>> {
        Box::pin(async move {
            if payloads.is_empty() {
                return Ok(true);
            }
            let subject = work_subject(queue);
            let mut prepared = Vec::with_capacity(payloads.len());
            for payload in payloads {
                let (pid, value) = self.envelope(queue, payload)?;
                prepared.push((pid, self.config.codec.encode(&value)?));
            }
            self.reopen().await?;
            let _guard = self.receive.lock().await;
            // One round trip per window: the whole batch is written before any
            // ack is read, and republished whole (every payload keeps its id,
            // so the ones that did land collapse), provisioned inside the
            // republish for the reason publish_acked() gives.
            let acks = self
                .republishing(|| {
                    let (subject, prepared) = (&subject, &prepared);
                    async move {
                        self.ensure(queue).await?;
                        let js = self.js().await?;
                        let mut pending = Vec::with_capacity(prepared.len());
                        for (pid, data) in prepared {
                            let headers = self.headers(Some(self.config.codec.content_type()), Some(pid));
                            pending.push(
                                js.publish_with_headers(subject.clone(), headers, data.clone().into())
                                    .await
                                    .map_err(publish_error)?,
                            );
                        }
                        let mut acks = Vec::with_capacity(pending.len());
                        for ack in pending {
                            acks.push(ack.await.map_err(publish_error)?);
                        }
                        Ok(acks)
                    }
                })
                .await?;
            for ack in acks {
                if ack.duplicate {
                    self.duplicates.fetch_add(1, Ordering::SeqCst);
                }
            }
            Ok(true)
        })
    }

    fn retry<'a>(&'a self, queue: &'a Queue, limit: Option<i64>) -> BoxFuture<'a, Result<()>> {
        Box::pin(self.retry_dead(queue, limit))
    }

    fn pending_count<'a>(&'a self, queue: &'a Queue) -> BoxFuture<'a, Result<i64>> {
        Box::pin(self.count(async move {
            let js = self.js().await?;
            let stream = js.get_stream(work_stream(queue)).await.map_err(jetstream_error)?;
            let mut consumer: PullConsumer = stream.get_consumer(CONSUMER_WORK).await.map_err(jetstream_error)?;
            Ok(consumer.info().await.map_err(jetstream_error)?.num_pending as i64)
        }))
    }

    /// The dead stream's message count.
    fn failed_count<'a>(&'a self, queue: &'a Queue) -> BoxFuture<'a, Result<i64>> {
        Box::pin(self.count(async move {
            let js = self.js().await?;
            let mut stream = js.get_stream(dead_stream(queue)).await.map_err(jetstream_error)?;
            Ok(stream.info().await.map_err(jetstream_error)?.state.messages as i64)
        }))
    }
}

impl Consumer for Nats {
    fn receive<'a>(&'a self, queue: &'a Queue, timeout: i64, n: i64) -> BoxFuture<'a, Result<Vec<Message>>> {
        Box::pin(async move {
            if self.closed.load(Ordering::SeqCst) {
                return Err(nats_error(
                    "Utopia\\NATS\\Exception\\ConnectionException",
                    "Not connected (status: closed)",
                ));
            }
            let result = {
                let _guard = self.receive.lock().await;
                self.pull(queue, timeout, n.max(1) as usize).await
            };
            self.flush_reports();
            result
        })
    }

    fn commit<'a>(&'a self, _queue: &'a Queue, message: &'a Message) -> BoxFuture<'a, Result<()>> {
        Box::pin(async move {
            let Some(delivery) = self.state().in_flight.remove(message.pid().as_bytes()) else { return Ok(()) };
            self.command()?;
            delivery.double_ack().await.map_err(jetstream_error)
        })
    }

    fn reject<'a>(&'a self, queue: &'a Queue, message: &'a Message) -> BoxFuture<'a, Result<()>> {
        Box::pin(async move {
            let Some(delivery) = self.state().in_flight.remove(message.pid().as_bytes()) else { return Ok(()) };
            self.command()?;
            let delivered = delivery.info().map(|i| i.delivered).unwrap_or(1);
            if message.is_terminal() || delivered >= self.config.max_deliver {
                let js = self.js().await?;
                js.publish(dead_subject(queue), delivery.payload.clone())
                    .await
                    .map_err(jetstream_error)?
                    .await
                    .map_err(jetstream_error)?;
                return delivery.ack_with(AckKind::Term).await.map_err(jetstream_error);
            }
            delivery.ack_with(AckKind::Nak(self.backoff_for(delivered))).await.map_err(jetstream_error)
        })
    }

    /// Closes the connection. Receiving then fails until a publish
    /// reconnects; the first acknowledgement or count after it fails too
    /// (when one had been made), and the next reconnects.
    fn close(&self) -> BoxFuture<'_, ()> {
        self.closed.store(true, Ordering::SeqCst);
        let opened = self.commands_opened.swap(false, Ordering::SeqCst);
        self.commands_closed.store(opened, Ordering::SeqCst);
        Box::pin(self.close_client())
    }

    fn release<'a>(&'a self, queue: &'a Queue, messages: &'a [Message]) -> Option<BoxFuture<'a, Result<()>>> {
        Some(Box::pin(async move {
            self.release_messages(queue, messages).await;
            Ok(())
        }))
    }

    fn extend<'a>(&'a self, _queue: &'a Queue, messages: &'a [Message]) -> BoxFuture<'a, Result<()>> {
        Box::pin(self.extend_messages(messages))
    }

    fn extend_interval(&self) -> BoxFuture<'_, Option<f64>> {
        let interval = self.extend_interval_secs();
        Box::pin(async move { Some(interval) })
    }

    /// Nothing to do: the client answers the server's pings itself.
    fn maintain(&self) -> Option<BoxFuture<'_, Result<()>>> {
        Some(Box::pin(async { Ok(()) }))
    }

    fn in_flight_ceiling(&self) -> BoxFuture<'_, Option<i64>> {
        let ceiling = self.config.max_ack_pending;
        Box::pin(async move { ceiling })
    }

    fn name(&self) -> &str {
        "Utopia\\Queue\\Broker\\Nats"
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn names() {
        let q = Queue::new("v1.audits é").expect("queue");
        assert_eq!(work_stream(&q), "Q_V1_AUDITS___");
        assert_eq!(dead_stream(&q), "Q_V1_AUDITS____DEAD");
        assert_eq!(work_subject(&q), "q.v1_audits___.normal");
        assert_eq!(identity(&q), "12:utopia-queue:v1.audits é");
    }
}
