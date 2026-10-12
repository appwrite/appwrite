//! JetStream: streams, consumers, acknowledged publishes, and the API the
//! key-value and object stores are built on.

mod config;
mod consumer;
mod info;

use std::time::{Duration, Instant};

use indexmap::IndexMap;
use serde_json::Value;

pub use config::{
    AckPolicy, ConsumerConfig, ConsumerLimits, DeliverPolicy, DiscardPolicy, ExternalStream, Placement, ReplayPolicy,
    Republish, RetentionPolicy, StorageType, StreamConfig, StreamSource, SubjectTransform, nanos_to_seconds,
    seconds_to_nanos,
};
pub use consumer::{
    AckKind, Consumer, JetStreamMessage, MessageBatch, OrderedConsumer, PushCallback, PushSubscription, Stream,
};
pub use info::{
    AccountInfo, ApiError, ConsumerInfo, MsgMetadata, PubAck, SequenceInfo, StreamInfo, StreamMessage, StreamState,
};

use crate::connection::Connection;
use crate::error::{CallbackError, Error, Result};
use crate::fields::Object;
use crate::headers::Headers;
use crate::key_value::{KeyValue, KeyValueConfig};
use crate::message::{Inbox, Message, Request};
use crate::object_store::ObjectStore;

/// A per-message TTL (`Nats-TTL`): seconds, or a duration the server parses
/// (`"1s"`, `"never"`).
///
/// PHP: the `int|string $ttl` argument.
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum Ttl {
    /// Whole seconds, sent as `<n>s`.
    Seconds(i64),
    /// A duration string, sent as is.
    Duration(String),
}

/// The headers and expectations of a JetStream publish.
///
/// PHP: the named arguments of `JetStream::publish()` and the message
/// arrays of `publishMany()`.
#[derive(Debug, Clone, Default, PartialEq)]
pub struct PublishOptions {
    /// Headers to send (the ones below are added to them).
    pub headers: Option<Headers>,
    /// `Nats-Msg-Id`, for deduplication.
    pub msg_id: Option<String>,
    /// `Nats-Expected-Last-Msg-Id`.
    pub expected_last_msg_id: Option<String>,
    /// `Nats-Expected-Last-Sequence`.
    pub expected_last_seq: Option<i64>,
    /// `Nats-Expected-Last-Subject-Sequence`.
    pub expected_last_subject_seq: Option<i64>,
    /// `Nats-Expected-Stream`.
    pub expected_stream: Option<String>,
    /// `Nats-TTL`.
    pub ttl: Option<Ttl>,
}

/// One message of [`JetStream::publish_many`].
#[derive(Debug, Clone, Default, PartialEq)]
pub struct PublishMessage {
    /// The subject.
    pub subject: String,
    /// The payload.
    pub data: Vec<u8>,
    /// Its headers and expectations.
    pub options: PublishOptions,
}

/// A JetStream context: the API prefix of an account or domain.
///
/// Its calls take the [`Connection`] to send on.
///
/// ```
/// # tokio::runtime::Builder::new_current_thread().enable_all().build()?.block_on(async {
/// use utopia_nats::jetstream::{PublishOptions, StorageType, StreamConfig};
/// # let options = utopia_nats::ConnectionOptions { connect_timeout: std::time::Duration::from_millis(200),
/// #     ..utopia_nats::ConnectionOptions::new([std::env::var("NATS_URL").unwrap_or("nats://127.0.0.1:4222".into())]) };
/// # let Ok(mut connection) = utopia_nats::Connection::connect(options).await else { return Ok(()) };
/// let js = connection.jetstream(None, None);
/// # let _ = js.delete_stream(&mut connection, "DOC_ORDERS").await;
/// js.create_stream(&mut connection, &StreamConfig { subjects: vec!["doc.orders.>".into()], storage: StorageType::Memory, ..StreamConfig::new("DOC_ORDERS") }).await?;
/// let ack = js.publish(&mut connection, "doc.orders.new", b"{}", PublishOptions { msg_id: Some("order-1".into()), ..Default::default() }, 0).await?;
/// assert_eq!((ack.stream.as_str(), ack.sequence), ("DOC_ORDERS", 1));
/// js.delete_stream(&mut connection, "DOC_ORDERS").await?;
/// # Ok::<(), Box<dyn std::error::Error>>(())
/// # })?;
/// # Ok::<(), Box<dyn std::error::Error>>(())
/// ```
///
/// ```php
/// use Utopia\NATS\Connection;
/// use Utopia\NATS\ConnectionOptions;
/// use Utopia\NATS\JetStream\StorageType;
/// use Utopia\NATS\JetStream\StreamConfig;
/// use Utopia\NATS\Exception\ConnectionException;
///
/// try {
///     $connection = Connection::connect(new ConnectionOptions(servers: getenv('NATS_URL') ?: 'nats://127.0.0.1:4222', connectTimeout: 0.2));
/// } catch (ConnectionException) {
///     return;
/// }
/// $js = $connection->jetStream();
/// try {
///     $js->deleteStream('DOC_ORDERS');
/// } catch (\Throwable) {
/// }
/// $js->createStream(new StreamConfig(name: 'DOC_ORDERS', subjects: ['doc.orders.>'], storage: StorageType::Memory));
/// $ack = $js->publish('doc.orders.new', '{}', msgId: 'order-1'); // $ack->sequence === 1
/// $js->deleteStream('DOC_ORDERS');
/// ```
///
/// PHP: `Utopia\NATS\JetStream\JetStream`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct JetStream {
    api_prefix: String,
}

impl JetStream {
    /// The API error code of a rejected optimistic-concurrency publish.
    ///
    /// PHP: `JetStream::ERR_WRONG_LAST_SEQUENCE`.
    pub const ERR_WRONG_LAST_SEQUENCE: i64 = 10071;

    /// The context for `api_prefix`, else `domain`'s (`$JS.<domain>.API`),
    /// else the account's (`$JS.API`).
    ///
    /// PHP: `new JetStream($connection, $domain, $apiPrefix)`.
    pub fn new(domain: Option<&str>, api_prefix: Option<&str>) -> Self {
        let api_prefix = match (api_prefix, domain) {
            (Some(prefix), _) => prefix.to_owned(),
            (None, Some(domain)) => format!("$JS.{domain}.API"),
            (None, None) => "$JS.API".to_owned(),
        };
        JetStream { api_prefix }
    }

    /// The API subject prefix.
    pub fn api_prefix(&self) -> &str {
        &self.api_prefix
    }

    /// Acknowledges the selected messages together and calls `confirmed`
    /// with each one's index and `None` once the server confirmed it (or
    /// the error).
    ///
    /// # Errors
    ///
    /// [`Error::Runtime`] for a message without reply subject, and the
    /// errors of [`Connection::request_batch`].
    ///
    /// PHP: `JetStream::ackBatch()`.
    pub async fn ack_batch<F>(
        &self,
        conn: &mut Connection,
        messages: &[JetStreamMessage],
        mut confirmed: F,
        timeout: Option<Duration>,
    ) -> Result<()>
    where
        F: FnMut(usize, Option<Error>) -> Result<(), CallbackError> + Send,
    {
        let mut requests = Vec::with_capacity(messages.len());
        for message in messages {
            let Some(reply) = &message.message.reply_to else {
                return Err(Error::Runtime("Cannot acknowledge: message has no reply subject".into()));
            };
            requests.push(Request::new(reply.clone(), "", None)?);
        }
        conn.request_batch(
            &requests,
            |index, result| confirmed(index, result.err()),
            Some(timeout.unwrap_or(Duration::from_secs(5))),
        )
        .await
    }

    /// Creates a stream.
    ///
    /// PHP: `JetStream::createStream()`.
    pub async fn create_stream(&self, conn: &mut Connection, config: &StreamConfig) -> Result<Stream> {
        let data = self.api(conn, &format!("STREAM.CREATE.{}", config.name), Some(config.to_value())).await?;
        Ok(Stream::new(self.clone(), StreamInfo::from_value(&data)))
    }

    /// Updates a stream.
    ///
    /// PHP: `JetStream::updateStream()`.
    pub async fn update_stream(&self, conn: &mut Connection, config: &StreamConfig) -> Result<Stream> {
        let data = self.api(conn, &format!("STREAM.UPDATE.{}", config.name), Some(config.to_value())).await?;
        Ok(Stream::new(self.clone(), StreamInfo::from_value(&data)))
    }

    /// Updates a stream, creating it when the update answers 404.
    ///
    /// PHP: `JetStream::createOrUpdateStream()`.
    pub async fn create_or_update_stream(&self, conn: &mut Connection, config: &StreamConfig) -> Result<Stream> {
        match self.update_stream(conn, config).await {
            Err(Error::JetStream { api: Some(api), .. }) if api.code == 404 => self.create_stream(conn, config).await,
            other => other,
        }
    }

    /// Deletes a stream.
    ///
    /// PHP: `JetStream::deleteStream()`.
    pub async fn delete_stream(&self, conn: &mut Connection, name: &str) -> Result<()> {
        self.api(conn, &format!("STREAM.DELETE.{name}"), None).await.map(drop)
    }

    /// A stream, with its current info.
    ///
    /// PHP: `JetStream::getStream()`.
    pub async fn get_stream(&self, conn: &mut Connection, name: &str) -> Result<Stream> {
        Ok(Stream::new(self.clone(), self.get_stream_info(conn, name).await?))
    }

    /// A stream's info.
    ///
    /// PHP: `JetStream::getStreamInfo()`.
    pub async fn get_stream_info(&self, conn: &mut Connection, name: &str) -> Result<StreamInfo> {
        let data = self.api(conn, &format!("STREAM.INFO.{name}"), None).await?;
        Ok(StreamInfo::from_value(&data))
    }

    /// The names of the streams (that take `subject`).
    ///
    /// PHP: `JetStream::getStreamNames()`.
    pub async fn get_stream_names(&self, conn: &mut Connection, subject: Option<&str>) -> Result<Vec<String>> {
        let payload = subject.map(|s| Object::new().set("subject", s).build());
        let data = self.api(conn, "STREAM.NAMES", payload).await?;
        Ok(crate::fields::opt_strings(&data, "streams").unwrap_or_default())
    }

    /// The infos of the streams (that take `subject`).
    ///
    /// PHP: `JetStream::listStreams()`.
    pub async fn list_streams(&self, conn: &mut Connection, subject: Option<&str>) -> Result<Vec<StreamInfo>> {
        let payload = subject.map(|s| Object::new().set("subject", s).build());
        let data = self.api(conn, "STREAM.LIST", payload).await?;
        Ok(list(&data, "streams").iter().map(StreamInfo::from_value).collect())
    }

    /// Purges a stream (one subject's messages, or all).
    ///
    /// PHP: `JetStream::purgeStream()`.
    pub async fn purge_stream(&self, conn: &mut Connection, name: &str, subject: Option<&str>) -> Result<()> {
        let payload = subject.map(|s| Object::new().set("filter", s).build());
        self.api(conn, &format!("STREAM.PURGE.{name}"), payload).await.map(drop)
    }

    /// Creates (or updates) a consumer; named by `name`, else
    /// `durable_name`, else ephemeral.
    ///
    /// PHP: `JetStream::createConsumer()`.
    pub async fn create_consumer(
        &self,
        conn: &mut Connection,
        stream: &str,
        config: &ConsumerConfig,
    ) -> Result<Consumer> {
        let subject = match config.name.as_deref().or(config.durable_name.as_deref()) {
            Some(name) => format!("CONSUMER.CREATE.{stream}.{name}"),
            None => format!("CONSUMER.CREATE.{stream}"),
        };
        let payload = Object::new().set("stream_name", stream).set("config", config.to_value()).build();
        let data = self.api(conn, &subject, Some(payload)).await?;
        Ok(Consumer::new(stream, ConsumerInfo::from_value(&data), self.api_prefix.clone()))
    }

    /// [`JetStream::create_consumer`].
    ///
    /// PHP: `JetStream::updateConsumer()`.
    pub async fn update_consumer(
        &self,
        conn: &mut Connection,
        stream: &str,
        config: &ConsumerConfig,
    ) -> Result<Consumer> {
        self.create_consumer(conn, stream, config).await
    }

    /// Deletes a consumer.
    ///
    /// PHP: `JetStream::deleteConsumer()`.
    pub async fn delete_consumer(&self, conn: &mut Connection, stream: &str, consumer: &str) -> Result<()> {
        self.api(conn, &format!("CONSUMER.DELETE.{stream}.{consumer}"), None).await.map(drop)
    }

    /// A consumer, with its current info.
    ///
    /// PHP: `JetStream::getConsumer()`.
    pub async fn get_consumer(&self, conn: &mut Connection, stream: &str, consumer: &str) -> Result<Consumer> {
        let data = self.api(conn, &format!("CONSUMER.INFO.{stream}.{consumer}"), None).await?;
        Ok(Consumer::new(stream, ConsumerInfo::from_value(&data), self.api_prefix.clone()))
    }

    /// The names of a stream's consumers.
    ///
    /// PHP: `JetStream::getConsumerNames()`.
    pub async fn get_consumer_names(&self, conn: &mut Connection, stream: &str) -> Result<Vec<String>> {
        let data = self.api(conn, &format!("CONSUMER.NAMES.{stream}"), None).await?;
        Ok(crate::fields::opt_strings(&data, "consumers").unwrap_or_default())
    }

    /// The infos of a stream's consumers, every page of them.
    ///
    /// PHP: `JetStream::getConsumers()`.
    pub async fn get_consumers(&self, conn: &mut Connection, stream: &str) -> Result<Vec<ConsumerInfo>> {
        let mut consumers = Vec::new();
        let mut offset = 0i64;
        loop {
            let payload = Object::new().set("offset", offset).build();
            let data = self.api(conn, &format!("CONSUMER.LIST.{stream}"), Some(payload)).await?;
            let page = list(&data, "consumers");
            consumers.extend(page.iter().map(ConsumerInfo::from_value));
            let total = crate::fields::opt_int(&data, "total").unwrap_or(consumers.len() as i64);
            offset = consumers.len() as i64;
            if page.is_empty() || offset >= total {
                return Ok(consumers);
            }
        }
    }

    /// Creates a push consumer (on a new inbox when it has no deliver
    /// subject) and delivers its messages to `callback`.
    ///
    /// PHP: `JetStream::pushSubscribe()`.
    pub async fn push_subscribe(
        &self,
        conn: &mut Connection,
        stream: &str,
        config: &ConsumerConfig,
        callback: PushCallback,
    ) -> Result<PushSubscription> {
        let mut config = config.clone();
        if config.deliver_subject.is_none() {
            config.deliver_subject = Some(conn.new_inbox());
        }
        let consumer = self.create_consumer(conn, stream, &config).await?;
        PushSubscription::new(conn, consumer.cached_info().clone(), callback).await
    }

    /// An ordered consumer of `stream`.
    ///
    /// PHP: `JetStream::orderedConsumer()`.
    pub async fn ordered_consumer(
        &self,
        conn: &mut Connection,
        stream: &str,
        deliver_policy: DeliverPolicy,
        filter_subject: Option<&str>,
        idle_heartbeat: f64,
    ) -> Result<OrderedConsumer> {
        OrderedConsumer::new(
            conn,
            self.clone(),
            stream,
            deliver_policy,
            filter_subject.map(str::to_owned),
            idle_heartbeat,
        )
        .await
    }

    /// A stored message by sequence.
    ///
    /// PHP: `JetStream::getMessage()`.
    pub async fn get_message(&self, conn: &mut Connection, stream: &str, seq: i64) -> Result<StreamMessage> {
        let payload = Object::new().set("seq", seq).build();
        let data = self.api(conn, &format!("STREAM.MSG.GET.{stream}"), Some(payload)).await?;
        StreamMessage::from_value(crate::fields::get(&data, "message").unwrap_or(&Value::Null))
    }

    /// The last stored message of a subject.
    ///
    /// PHP: `JetStream::getLastMessage()`.
    pub async fn get_last_message(&self, conn: &mut Connection, stream: &str, subject: &str) -> Result<StreamMessage> {
        let payload = Object::new().set("last_by_subj", subject).build();
        let data = self.api(conn, &format!("STREAM.MSG.GET.{stream}"), Some(payload)).await?;
        StreamMessage::from_value(crate::fields::get(&data, "message").unwrap_or(&Value::Null))
    }

    /// Deletes a stored message (overwriting it unless `no_erase`).
    ///
    /// PHP: `JetStream::deleteMessage()`.
    pub async fn delete_message(&self, conn: &mut Connection, stream: &str, seq: i64, no_erase: bool) -> Result<()> {
        let mut payload = Object::new();
        payload.set("seq", seq);
        if no_erase {
            payload.set("no_erase", true);
        }
        self.api(conn, &format!("STREAM.MSG.DELETE.{stream}"), Some(payload.build())).await.map(drop)
    }

    /// Publishes and waits for the stream's acknowledgement, retrying up to
    /// `retry_on_no_responders` times (50 ms more each time) while no stream
    /// answers.
    ///
    /// # Errors
    ///
    /// [`Error::NoResponders`] when no stream takes the subject,
    /// [`Error::JetStream`] when the stream refuses the message (a failed
    /// expectation), and the request errors.
    ///
    /// PHP: `JetStream::publish()`.
    pub async fn publish(
        &self,
        conn: &mut Connection,
        subject: &str,
        data: &[u8],
        options: PublishOptions,
        retry_on_no_responders: i64,
    ) -> Result<PubAck> {
        let headers = publish_headers(options);
        let mut attempt = 0;
        let response = loop {
            match conn.request(subject, data, None, headers.as_ref()).await {
                Ok(response) => break response,
                Err(Error::NoResponders(message)) => {
                    if attempt >= retry_on_no_responders {
                        return Err(Error::NoResponders(message));
                    }
                    attempt += 1;
                    tokio::time::sleep(Duration::from_millis(50 * attempt as u64)).await;
                }
                Err(error) => return Err(error),
            }
        };
        let data = crate::json::decode(&response.data)?;
        Self::check_error(&data)?;
        Ok(PubAck::from_value(&data))
    }

    /// Publishes many messages, a window at a time: every publish of a
    /// window is written before its acknowledgements are read, so a window
    /// costs one round trip. Acknowledgements come back in message order.
    ///
    /// # Errors
    ///
    /// [`Error::InvalidArgument`] for a window below 1,
    /// [`Error::JetStream`] for a refused message, [`Error::NoResponders`],
    /// and [`Error::Timeout`] when a window's acknowledgements do not all
    /// arrive.
    ///
    /// PHP: `JetStream::publishMany()`.
    pub async fn publish_many(
        &self,
        conn: &mut Connection,
        messages: &[PublishMessage],
        timeout: Option<Duration>,
        window: i64,
    ) -> Result<Vec<PubAck>> {
        if messages.is_empty() {
            return Ok(Vec::new());
        }
        if window < 1 {
            return Err(Error::InvalidArgument(format!("Publish window must be at least 1, got {window}")));
        }
        let mut acks = Vec::with_capacity(messages.len());
        for (chunk, start) in messages.chunks(window as usize).zip((0..).step_by(window as usize)) {
            let mut window_acks = self.publish_window(conn, chunk, start, timeout).await?;
            window_acks.sort_by_key(|(index, _)| *index);
            acks.extend(window_acks.into_iter().map(|(_, ack)| ack));
        }
        Ok(acks)
    }

    async fn publish_window(
        &self,
        conn: &mut Connection,
        chunk: &[PublishMessage],
        start: usize,
        timeout: Option<Duration>,
    ) -> Result<Vec<(i64, PubAck)>> {
        let inbox = Inbox::create(&conn.options().inbox_prefix);
        let sub = conn.subscribe(&format!("{inbox}.*"), None, None).await?;
        let collected = async {
            for (offset, message) in chunk.iter().enumerate() {
                let reply = format!("{inbox}.{}", start + offset);
                let headers = publish_headers(message.options.clone());
                conn.publish(&message.subject, &message.data, Some(&reply), headers.as_ref()).await?;
            }
            let deadline = Instant::now() + timeout.unwrap_or(conn.options().request_timeout);
            let mut acks: IndexMap<i64, PubAck> = IndexMap::with_capacity(chunk.len());
            while acks.len() < chunk.len() {
                let remaining = deadline.saturating_duration_since(Instant::now());
                if remaining.is_zero() {
                    break;
                }
                let Some(reply) = conn.next_message(&sub, Some(remaining)).await? else {
                    break;
                };
                if reply.status() == "503" {
                    return Err(Error::NoResponders("No responders for request".into()));
                }
                let data = crate::json::decode(&reply.data)?;
                Self::check_error(&data)?;
                let index =
                    php_std::format::str_to_int(reply.subject.rsplit('.').next().unwrap_or_default().as_bytes());
                acks.insert(index, PubAck::from_value(&data));
            }
            if acks.len() < chunk.len() {
                return Err(Error::Timeout(format!(
                    "Timed out waiting for JetStream publish acknowledgments: received {} of {}",
                    acks.len(),
                    chunk.len()
                )));
            }
            Ok(acks.into_iter().collect::<Vec<_>>())
        }
        .await;
        let released = conn.unsubscribe(&sub, None).await;
        match released {
            Err(error) => Err(error),
            Ok(()) => collected,
        }
    }

    /// Creates (or updates) a key-value bucket.
    ///
    /// PHP: `JetStream::createKeyValue()`.
    pub async fn create_key_value(&self, conn: &mut Connection, config: &KeyValueConfig) -> Result<KeyValue> {
        self.create_or_update_stream(conn, &config.to_stream_config()).await?;
        Ok(KeyValue::new(self.clone(), &config.bucket))
    }

    /// An existing key-value bucket.
    ///
    /// PHP: `JetStream::getKeyValue()`.
    pub async fn get_key_value(&self, conn: &mut Connection, bucket: &str) -> Result<KeyValue> {
        self.get_stream_info(conn, &format!("KV_{bucket}")).await?;
        Ok(KeyValue::new(self.clone(), bucket))
    }

    /// Deletes a key-value bucket.
    ///
    /// PHP: `JetStream::deleteKeyValue()`.
    pub async fn delete_key_value(&self, conn: &mut Connection, bucket: &str) -> Result<()> {
        self.delete_stream(conn, &format!("KV_{bucket}")).await
    }

    /// An existing object store bucket.
    ///
    /// PHP: `JetStream::getObjectStore()`.
    pub async fn get_object_store(&self, conn: &mut Connection, bucket: &str) -> Result<ObjectStore> {
        self.get_stream_info(conn, &format!("OBJ_{bucket}")).await?;
        Ok(ObjectStore::new(self.clone(), bucket))
    }

    /// Deletes an object store bucket.
    ///
    /// PHP: `JetStream::deleteObjectStore()`.
    pub async fn delete_object_store(&self, conn: &mut Connection, bucket: &str) -> Result<()> {
        self.delete_stream(conn, &format!("OBJ_{bucket}")).await
    }

    /// The account's usage and limits.
    ///
    /// PHP: `JetStream::accountInfo()`.
    pub async fn account_info(&self, conn: &mut Connection) -> Result<AccountInfo> {
        Ok(AccountInfo::from_value(&self.api(conn, "INFO", None).await?))
    }

    /// Sends an API request and decodes its answer.
    pub(crate) async fn api(&self, conn: &mut Connection, subject: &str, payload: Option<Value>) -> Result<Value> {
        let body = match &payload {
            Some(payload) => crate::json::encode(payload)?,
            None => String::new(),
        };
        let response: Message = conn.request(&format!("{}.{subject}", self.api_prefix), body, None, None).await?;
        let data = crate::json::decode(&response.data)?;
        Self::check_error(&data)?;
        Ok(data)
    }

    /// The API error a response carries, as an [`Error::JetStream`].
    ///
    /// PHP: `JetStream::checkError()`.
    pub fn check_error(data: &Value) -> Result<()> {
        if let Some(error) = crate::fields::get(data, "error") {
            let api = ApiError::from_value(error);
            return Err(Error::JetStream { message: api.description.clone(), code: api.code, api: Some(api) });
        }
        Ok(())
    }
}

fn list<'a>(data: &'a Value, key: &str) -> &'a [Value] {
    crate::fields::get(data, key).and_then(Value::as_array).map_or(&[], Vec::as_slice)
}

/// The headers of a JetStream publish: the given ones plus the
/// expectations; `None` when there are none.
fn publish_headers(options: PublishOptions) -> Option<Headers> {
    let mut headers = options.headers.unwrap_or_default();
    if let Some(id) = options.msg_id {
        headers.set("Nats-Msg-Id", id);
    }
    if let Some(id) = options.expected_last_msg_id {
        headers.set("Nats-Expected-Last-Msg-Id", id);
    }
    if let Some(seq) = options.expected_last_seq {
        headers.set("Nats-Expected-Last-Sequence", seq.to_string());
    }
    if let Some(seq) = options.expected_last_subject_seq {
        headers.set("Nats-Expected-Last-Subject-Sequence", seq.to_string());
    }
    if let Some(stream) = options.expected_stream {
        headers.set("Nats-Expected-Stream", stream);
    }
    match options.ttl {
        Some(Ttl::Seconds(seconds)) => {
            headers.set("Nats-TTL", format!("{seconds}s"));
        }
        Some(Ttl::Duration(duration)) => {
            headers.set("Nats-TTL", duration);
        }
        None => {}
    }
    if headers.is_empty() { None } else { Some(headers) }
}
