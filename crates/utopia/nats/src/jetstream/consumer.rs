//! Streams, consumers and the messages they deliver.

use std::time::{Duration, Instant};

use super::JetStream;
use super::config::{AckPolicy, ConsumerConfig, DeliverPolicy, ReplayPolicy, StreamConfig, seconds_to_nanos};
use super::info::{ConsumerInfo, MsgMetadata, StreamInfo, StreamState};
use crate::connection::Connection;
use crate::error::{CallbackError, Error, Result};
use crate::fields::Object;
use crate::headers::Headers;
use crate::message::Message;
use crate::publisher::Publisher;
use crate::subscription::Subscription;

/// A stream, with the info it was fetched with.
///
/// PHP: `Utopia\NATS\JetStream\Stream`.
#[derive(Debug, Clone)]
pub struct Stream {
    js: JetStream,
    info: StreamInfo,
}

impl Stream {
    pub(crate) fn new(js: JetStream, info: StreamInfo) -> Self {
        Stream { js, info }
    }

    /// The stream's name.
    ///
    /// PHP: `Stream::getName()`.
    pub fn name(&self) -> &str {
        &self.info.config.name
    }

    /// Its configuration.
    ///
    /// PHP: `Stream::getConfig()`.
    pub fn config(&self) -> &StreamConfig {
        &self.info.config
    }

    /// Its state, as last fetched.
    ///
    /// PHP: `Stream::getState()`.
    pub fn state(&self) -> &StreamState {
        &self.info.state
    }

    /// The info it was fetched (or last refreshed) with.
    pub fn cached_info(&self) -> &StreamInfo {
        &self.info
    }

    /// Its info, fetched again when `refresh`.
    ///
    /// PHP: `Stream::info()`.
    pub async fn info(&mut self, conn: &mut Connection, refresh: bool) -> Result<&StreamInfo> {
        if refresh {
            let name = self.name().to_owned();
            self.info = self.js.get_stream_info(conn, &name).await?;
        }
        Ok(&self.info)
    }

    /// Creates a consumer on it.
    ///
    /// PHP: `Stream::createConsumer()`.
    pub async fn create_consumer(&self, conn: &mut Connection, config: &ConsumerConfig) -> Result<Consumer> {
        self.js.create_consumer(conn, self.name(), config).await
    }

    /// One of its consumers.
    ///
    /// PHP: `Stream::getConsumer()`.
    pub async fn get_consumer(&self, conn: &mut Connection, name: &str) -> Result<Consumer> {
        self.js.get_consumer(conn, self.name(), name).await
    }

    /// Deletes one of its consumers.
    ///
    /// PHP: `Stream::deleteConsumer()`.
    pub async fn delete_consumer(&self, conn: &mut Connection, name: &str) -> Result<()> {
        self.js.delete_consumer(conn, self.name(), name).await
    }

    /// Purges it (one subject's messages, or all).
    ///
    /// PHP: `Stream::purge()`.
    pub async fn purge(&self, conn: &mut Connection, subject: Option<&str>) -> Result<()> {
        self.js.purge_stream(conn, self.name(), subject).await
    }

    /// Deletes it.
    ///
    /// PHP: `Stream::delete()`.
    pub async fn delete(&self, conn: &mut Connection) -> Result<()> {
        self.js.delete_stream(conn, self.name()).await
    }
}

/// How a JetStream message is answered.
#[derive(Debug, Clone, PartialEq)]
pub enum AckKind {
    /// Processed: `""`.
    Ack,
    /// Redeliver, after an optional delay in seconds: `-NAK`.
    Nak(Option<f64>),
    /// Still working on it, extend the ack deadline: `+WPI`.
    InProgress,
    /// Never redeliver, with an optional reason: `+TERM`.
    Term(Option<String>),
}

impl AckKind {
    /// The reply payload.
    pub fn payload(&self) -> String {
        match self {
            AckKind::Ack => String::new(),
            AckKind::Nak(None) => "-NAK".into(),
            AckKind::Nak(Some(delay)) => format!("-NAK {{\"delay\":{}}}", seconds_to_nanos(*delay)),
            AckKind::InProgress => "+WPI".into(),
            AckKind::Term(None) => "+TERM".into(),
            AckKind::Term(Some(reason)) => format!("+TERM {reason}"),
        }
    }
}

/// A message delivered by a JetStream consumer, to acknowledge.
///
/// PHP: `Utopia\NATS\JetStream\JetStreamMessage`.
#[derive(Debug, Clone, PartialEq)]
pub struct JetStreamMessage {
    /// The message as delivered.
    pub message: Message,
}

impl JetStreamMessage {
    /// Wraps a delivered message.
    ///
    /// PHP: `new JetStreamMessage($connection, $message)`.
    pub fn new(message: Message) -> Self {
        JetStreamMessage { message }
    }

    fn reply(&self) -> Result<&str> {
        self.message
            .reply_to
            .as_deref()
            .ok_or_else(|| Error::Runtime("Cannot acknowledge: message has no reply subject".into()))
    }

    /// Answers the message (fire and forget).
    ///
    /// # Errors
    ///
    /// [`Error::Runtime`] without a reply subject, and the publish errors.
    pub async fn respond(&self, conn: &mut Connection, kind: AckKind) -> Result<()> {
        let reply = self.reply()?.to_owned();
        conn.publish(&reply, kind.payload(), None, None).await
    }

    /// [`JetStreamMessage::respond`] through a [`Publisher`], from a
    /// callback.
    ///
    /// # Errors
    ///
    /// [`Error::Runtime`] without a reply subject.
    pub fn respond_via(&self, publisher: &Publisher, kind: AckKind) -> Result<()> {
        let reply = self.reply()?.to_owned();
        publisher.publish(reply, kind.payload(), None, None);
        Ok(())
    }

    /// Acknowledges it.
    ///
    /// PHP: `JetStreamMessage::ack()`.
    pub async fn ack(&self, conn: &mut Connection) -> Result<()> {
        self.respond(conn, AckKind::Ack).await
    }

    /// Acknowledges it and waits for the server to confirm.
    ///
    /// PHP: `JetStreamMessage::ackSync()`.
    pub async fn ack_sync(&self, conn: &mut Connection, timeout: Option<Duration>) -> Result<()> {
        let reply = self.reply()?.to_owned();
        conn.request(&reply, "", Some(timeout.unwrap_or(Duration::from_secs(5))), None).await?;
        Ok(())
    }

    /// Asks for redelivery (after `delay` seconds).
    ///
    /// PHP: `JetStreamMessage::nak()`.
    pub async fn nak(&self, conn: &mut Connection, delay: Option<f64>) -> Result<()> {
        self.respond(conn, AckKind::Nak(delay)).await
    }

    /// Extends the ack deadline.
    ///
    /// PHP: `JetStreamMessage::inProgress()`.
    pub async fn in_progress(&self, conn: &mut Connection) -> Result<()> {
        self.respond(conn, AckKind::InProgress).await
    }

    /// Stops redelivery for good.
    ///
    /// PHP: `JetStreamMessage::term()`.
    pub async fn term(&self, conn: &mut Connection, reason: Option<&str>) -> Result<()> {
        self.respond(conn, AckKind::Term(reason.map(str::to_owned))).await
    }

    /// Its stream, consumer, sequences and delivery count, from the reply
    /// subject.
    ///
    /// # Errors
    ///
    /// [`Error::Runtime`] without a reply subject, [`Error::InvalidArgument`]
    /// for a reply subject that is not a JetStream one.
    ///
    /// PHP: `JetStreamMessage::metadata()`.
    pub fn metadata(&self) -> Result<MsgMetadata> {
        match &self.message.reply_to {
            Some(reply) => MsgMetadata::from_reply_subject(reply),
            None => Err(Error::Runtime("Message has no reply subject for metadata parsing".into())),
        }
    }

    /// The payload.
    ///
    /// PHP: `JetStreamMessage::getData()`.
    pub fn data(&self) -> &[u8] {
        &self.message.data
    }

    /// The subject it was stored under.
    ///
    /// PHP: `JetStreamMessage::getSubject()`.
    pub fn subject(&self) -> &str {
        &self.message.subject
    }

    /// Its headers.
    ///
    /// PHP: `JetStreamMessage::getHeaders()`.
    pub fn headers(&self) -> Option<&Headers> {
        self.message.headers.as_ref()
    }
}

/// The messages one pull returned.
///
/// PHP: `Utopia\NATS\JetStream\MessageBatch`.
#[derive(Debug, Clone, Default, PartialEq)]
pub struct MessageBatch {
    messages: Vec<JetStreamMessage>,
}

impl MessageBatch {
    /// Adds a delivered message.
    ///
    /// PHP: `MessageBatch::addMessage()`.
    pub fn add_message(&mut self, message: Message) {
        self.messages.push(JetStreamMessage::new(message));
    }

    /// The messages, in delivery order.
    ///
    /// PHP: `MessageBatch::getMessages()`, `getIterator()`.
    pub fn messages(&self) -> &[JetStreamMessage] {
        &self.messages
    }

    /// How many messages.
    ///
    /// PHP: `MessageBatch::count()`.
    pub fn len(&self) -> usize {
        self.messages.len()
    }

    /// Whether the pull returned nothing.
    pub fn is_empty(&self) -> bool {
        self.messages.is_empty()
    }
}

impl IntoIterator for MessageBatch {
    type Item = JetStreamMessage;
    type IntoIter = std::vec::IntoIter<JetStreamMessage>;

    fn into_iter(self) -> Self::IntoIter {
        self.messages.into_iter()
    }
}

/// A pull consumer.
///
/// PHP: `Utopia\NATS\JetStream\Consumer`.
#[derive(Debug, Clone)]
pub struct Consumer {
    stream: String,
    info: ConsumerInfo,
    api_prefix: String,
}

/// How much earlier than the client the server's pull expires (seconds),
/// capped at a tenth of the timeout.
const FETCH_EXPIRY_MARGIN: f64 = 0.1;

impl Consumer {
    /// A consumer of `stream` with this info.
    ///
    /// PHP: `new Consumer($connection, $stream, $info, $apiPrefix)`.
    pub fn new(stream: impl Into<String>, info: ConsumerInfo, api_prefix: impl Into<String>) -> Self {
        Consumer { stream: stream.into(), info, api_prefix: api_prefix.into() }
    }

    /// Pulls up to `batch` messages, waiting up to `timeout` (5 s), or
    /// answering at once when `no_wait`. Status frames end the pull (100,
    /// flow control, is answered and skipped).
    ///
    /// The inbox subscription is released on every path; when both the
    /// pull and the release fail, the pull's error wins.
    ///
    /// PHP: `Consumer::fetch()`.
    pub async fn fetch(
        &self,
        conn: &mut Connection,
        batch: i64,
        timeout: Option<Duration>,
        no_wait: bool,
        max_bytes: Option<i64>,
    ) -> Result<MessageBatch> {
        let timeout = timeout.map_or(5.0, |t| t.as_secs_f64());
        let subject = format!("{}.CONSUMER.MSG.NEXT.{}.{}", self.api_prefix, self.stream, self.name());
        let mut request = Object::new();
        request.set("batch", batch);
        if no_wait {
            request.set("no_wait", true);
        } else {
            let margin = if timeout * 0.1 < FETCH_EXPIRY_MARGIN { timeout * 0.1 } else { FETCH_EXPIRY_MARGIN };
            request.set("expires", seconds_to_nanos(timeout - margin));
        }
        request.opt("max_bytes", max_bytes);
        let payload = crate::json::encode(&request.build())?;

        let inbox = conn.new_inbox();
        let sub = conn.subscribe(&inbox, None, None).await?;
        let mut messages = MessageBatch::default();
        let mut pending = match conn.publish(&subject, &payload, Some(&inbox), None).await {
            Ok(()) => self.collect(conn, &sub, &mut messages, batch, timeout).await.err(),
            Err(error) => Some(error),
        };
        if let Err(error) = conn.unsubscribe(&sub, None).await {
            pending.get_or_insert(error);
        }
        match pending {
            Some(error) => Err(error),
            None => Ok(messages),
        }
    }

    async fn collect(
        &self,
        conn: &mut Connection,
        sub: &Subscription,
        messages: &mut MessageBatch,
        batch: i64,
        timeout: f64,
    ) -> Result<()> {
        let deadline = Instant::now() + Duration::from_secs_f64(timeout.max(0.0));
        while (messages.len() as i64) < batch {
            let remaining = deadline.saturating_duration_since(Instant::now());
            if remaining.is_zero() {
                break;
            }
            let Some(message) = conn.next_message(sub, Some(remaining)).await? else {
                break;
            };
            if message.headers.is_some() {
                let status = message.status();
                if status == "100" {
                    if let Some(reply) = message.reply_to.as_deref().filter(|r| !r.is_empty()) {
                        conn.publish(reply, "", None, None).await?;
                    }
                    continue;
                }
                if !status.is_empty() {
                    break;
                }
            }
            messages.add_message(message);
        }
        Ok(())
    }

    /// Pulls one message.
    ///
    /// PHP: `Consumer::next()`.
    pub async fn next(&self, conn: &mut Connection, timeout: Option<Duration>) -> Result<Option<JetStreamMessage>> {
        Ok(self.fetch(conn, 1, timeout, false, None).await?.into_iter().next())
    }

    /// Its info, fetched again when `refresh` (keeping the cached info if
    /// that times out).
    ///
    /// PHP: `Consumer::info()`.
    pub async fn info(&mut self, conn: &mut Connection, refresh: bool) -> Result<&ConsumerInfo> {
        if refresh {
            let subject = format!("{}.CONSUMER.INFO.{}.{}", self.api_prefix, self.stream, self.name());
            match conn.request(&subject, "", None, None).await {
                Ok(response) => {
                    let data = crate::json::decode(&response.data)?;
                    JetStream::check_error(&data)?;
                    self.info = ConsumerInfo::from_value(&data);
                }
                Err(Error::Timeout(_)) => {}
                Err(error) => return Err(error),
            }
        }
        Ok(&self.info)
    }

    /// The info it was created or last refreshed with.
    pub fn cached_info(&self) -> &ConsumerInfo {
        &self.info
    }

    /// Its name.
    ///
    /// PHP: `Consumer::getName()`.
    pub fn name(&self) -> &str {
        &self.info.name
    }

    /// Its stream's name.
    ///
    /// PHP: `Consumer::getStream()`.
    pub fn stream(&self) -> &str {
        &self.stream
    }
}

/// A callback receiving the messages of a push consumer.
pub type PushCallback = Box<dyn FnMut(JetStreamMessage) -> Result<(), CallbackError> + Send>;

/// A push consumer's subscription: data messages go to the callback,
/// flow-control requests are answered and heartbeats swallowed. Drive it
/// with [`Connection::wait`] or [`Connection::process_message`].
///
/// PHP: `Utopia\NATS\JetStream\PushSubscription`.
#[derive(Debug, Clone)]
pub struct PushSubscription {
    sub: Subscription,
    info: ConsumerInfo,
}

impl PushSubscription {
    /// Subscribes to the consumer's deliver subject (in its deliver group).
    ///
    /// # Errors
    ///
    /// [`Error::Runtime`] for a consumer without a deliver subject.
    ///
    /// PHP: `new PushSubscription($connection, $info, $callback)`.
    pub async fn new(conn: &mut Connection, info: ConsumerInfo, mut callback: PushCallback) -> Result<Self> {
        let Some(deliver) = info.config.deliver_subject.clone() else {
            return Err(Error::Runtime("Consumer has no deliver subject; not a push consumer".into()));
        };
        let publisher = conn.publisher();
        let handler = Box::new(move |message: Message| {
            if Self::handle_control(&publisher, &message) {
                return Ok(());
            }
            callback(JetStreamMessage::new(message))
        });
        let group = info.config.deliver_group.clone();
        let sub = conn.subscribe(&deliver, Some(handler), group.as_deref()).await?;
        Ok(PushSubscription { sub, info })
    }

    /// For a control message (status 100), the subject to answer, if any:
    /// a flow-control request's reply subject, or an idle heartbeat's
    /// `Nats-Consumer-Stalled`. `None` for a data message.
    pub fn control(message: &Message) -> Option<Option<String>> {
        let headers = message.headers.as_ref()?;
        if headers.status() != "100" {
            return None;
        }
        if let Some(reply) = message.reply_to.as_deref().filter(|r| !r.is_empty()) {
            return Some(Some(reply.to_owned()));
        }
        Some(headers.get("Nats-Consumer-Stalled").filter(|s| !s.is_empty()).map(str::to_owned))
    }

    /// Answers a control message through `publisher`; whether it was one.
    ///
    /// PHP: `PushSubscription::handleControl()`.
    pub fn handle_control(publisher: &Publisher, message: &Message) -> bool {
        match Self::control(message) {
            None => false,
            Some(reply) => {
                if let Some(reply) = reply {
                    publisher.publish(reply, "", None, None);
                }
                true
            }
        }
    }

    /// The subscription on the deliver subject.
    ///
    /// PHP: `PushSubscription::getSubscription()`.
    pub fn subscription(&self) -> &Subscription {
        &self.sub
    }

    /// The consumer's info.
    ///
    /// PHP: `PushSubscription::getConsumerInfo()`.
    pub fn consumer_info(&self) -> &ConsumerInfo {
        &self.info
    }

    /// The consumer's name.
    ///
    /// PHP: `PushSubscription::getConsumerName()`.
    pub fn consumer_name(&self) -> &str {
        &self.info.name
    }

    /// Unsubscribes.
    ///
    /// PHP: `PushSubscription::unsubscribe()`.
    pub async fn unsubscribe(&self, conn: &mut Connection) -> Result<()> {
        conn.unsubscribe(&self.sub, None).await
    }
}

/// An ordered consumer: an ephemeral push consumer with flow control and
/// heartbeats, delivering in order, and recreated from the next stream
/// sequence when a gap shows.
///
/// PHP: `Utopia\NATS\JetStream\OrderedConsumer`.
#[derive(Debug)]
pub struct OrderedConsumer {
    js: JetStream,
    stream: String,
    deliver_policy: DeliverPolicy,
    filter_subject: Option<String>,
    idle_heartbeat: f64,
    sub: Subscription,
    info: ConsumerInfo,
    expected_consumer_seq: i64,
    last_stream_seq: i64,
}

impl OrderedConsumer {
    /// Creates the consumer.
    ///
    /// PHP: `new OrderedConsumer(...)`, `JetStream::orderedConsumer()`.
    pub async fn new(
        conn: &mut Connection,
        js: JetStream,
        stream: impl Into<String>,
        deliver_policy: DeliverPolicy,
        filter_subject: Option<String>,
        idle_heartbeat: f64,
    ) -> Result<Self> {
        let stream = stream.into();
        let (sub, info) =
            Self::create(conn, &js, &stream, deliver_policy, filter_subject.as_deref(), idle_heartbeat, None).await?;
        Ok(OrderedConsumer {
            js,
            stream,
            deliver_policy,
            filter_subject,
            idle_heartbeat,
            sub,
            info,
            expected_consumer_seq: 1,
            last_stream_seq: 0,
        })
    }

    /// The next message in order, or `None` after `timeout` (5 s).
    ///
    /// PHP: `OrderedConsumer::next()`.
    pub async fn next(&mut self, conn: &mut Connection, timeout: Option<Duration>) -> Result<Option<JetStreamMessage>> {
        let deadline = Instant::now() + timeout.unwrap_or(Duration::from_secs(5));
        loop {
            let remaining = deadline.saturating_duration_since(Instant::now());
            if remaining.is_zero() {
                return Ok(None);
            }
            let Some(message) = conn.next_message(&self.sub, Some(remaining)).await? else {
                return Ok(None);
            };
            if let Some(reply) = PushSubscription::control(&message) {
                if let Some(reply) = reply {
                    conn.publish(&reply, "", None, None).await?;
                }
                let last = message.headers.as_ref().and_then(|h| h.get("Nats-Last-Consumer"));
                if let Some(last) = last
                    && php_std::format::str_to_int(last.as_bytes()) > self.expected_consumer_seq - 1
                {
                    self.reset(conn, self.last_stream_seq + 1).await?;
                }
                continue;
            }
            let message = JetStreamMessage::new(message);
            let meta = message.metadata()?;
            if meta.consumer_sequence != self.expected_consumer_seq {
                self.reset(conn, self.last_stream_seq + 1).await?;
                continue;
            }
            self.expected_consumer_seq += 1;
            self.last_stream_seq = meta.stream_sequence;
            return Ok(Some(message));
        }
    }

    /// The consumer sequence the next message must carry.
    pub fn expected_sequence(&self) -> i64 {
        self.expected_consumer_seq
    }

    /// Moves the expected consumer sequence (a mismatch on the next message
    /// recreates the consumer).
    pub fn set_expected_sequence(&mut self, sequence: i64) {
        self.expected_consumer_seq = sequence;
    }

    /// The current ephemeral consumer's name.
    ///
    /// PHP: `OrderedConsumer::getConsumerName()`.
    pub fn consumer_name(&self) -> &str {
        &self.info.name
    }

    /// Unsubscribes and deletes the consumer.
    ///
    /// PHP: `OrderedConsumer::stop()`.
    pub async fn stop(&mut self, conn: &mut Connection) -> Result<()> {
        self.teardown(conn).await
    }

    async fn reset(&mut self, conn: &mut Connection, start: i64) -> Result<()> {
        self.teardown(conn).await?;
        let (sub, info) = Self::create(
            conn,
            &self.js,
            &self.stream,
            self.deliver_policy,
            self.filter_subject.as_deref(),
            self.idle_heartbeat,
            Some(start),
        )
        .await?;
        self.sub = sub;
        self.info = info;
        self.expected_consumer_seq = 1;
        Ok(())
    }

    async fn teardown(&mut self, conn: &mut Connection) -> Result<()> {
        conn.unsubscribe(&self.sub, None).await?;
        let _ = self.js.delete_consumer(conn, &self.stream, &self.info.name).await;
        Ok(())
    }

    async fn create(
        conn: &mut Connection,
        js: &JetStream,
        stream: &str,
        deliver_policy: DeliverPolicy,
        filter_subject: Option<&str>,
        idle_heartbeat: f64,
        start: Option<i64>,
    ) -> Result<(Subscription, ConsumerInfo)> {
        let deliver = conn.new_inbox();
        let sub = conn.subscribe(&deliver, None, None).await?;
        let config = ConsumerConfig {
            deliver_policy: if start.is_some() { DeliverPolicy::ByStartSequence } else { deliver_policy },
            ack_policy: AckPolicy::None,
            filter_subject: filter_subject.map(str::to_owned),
            replay_policy: ReplayPolicy::Instant,
            inactive_threshold: Some(30.0),
            opt_start_seq: start,
            deliver_subject: Some(deliver),
            flow_control: true,
            idle_heartbeat: Some(idle_heartbeat),
            ..Default::default()
        };
        let consumer = js.create_consumer(conn, stream, &config).await?;
        Ok((sub, consumer.cached_info().clone()))
    }
}
