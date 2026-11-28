//! A JetStream worker that shares its queue with PHP workers on `Utopia\Queue\Broker\Nats`.
//!
//! [`Worker`] consumes a queue the way `Broker\Nats` does, so a Rust worker and PHP workers
//! can take messages from the same queue at the same time:
//!
//! - It joins the `worker` durable consumer on the `Q_<NAME>` stream that `Broker\Nats`
//!   provisions. It provisions nothing itself: publishers create the stream on their first
//!   publish, and a worker started before that waits for it.
//! - Messages are the JSON envelope `Broker\Nats` writes: `pid`, `queue`, `timestamp` and
//!   `payload`.
//! - The redelivery budget and the backoff are read from the consumer, which carries the values
//!   the queue's DSN gave it, so this worker retries exactly as the PHP ones do.
//! - What `Broker\Nats` dead-letters, this dead-letters to the same subjects with the same
//!   headers, so `retry()` in PHP requeues it.
//!
//! What stays with the PHP workers sharing the queue: provisioning, the max-deliveries advisory
//! and `reap()`. Both of the last two only matter for a message whose every delivery died
//! unacknowledged, which [`Worker::receive`] dead-letters itself on its spare delivery.
//!
//! [`broker::Nats`](crate::broker::Nats) is the full port of `Broker\Nats`, for a Rust [`Server`](crate::Server)
//! that provisions and publishes too.

mod metrics;

use std::time::{Duration, Instant};

use async_nats::HeaderMap;
use async_nats::jetstream::consumer::PullConsumer;
use async_nats::jetstream::context::{GetStreamErrorKind, PublishErrorKind};
use async_nats::jetstream::message::{AckKind, PublishMessage};
use async_nats::jetstream::{self, Context, ErrorCode};
use bytes::Bytes;
use futures_util::StreamExt;
use serde::Deserialize;
use utopia_dsn::Dsn;

use self::metrics::Metrics;

/// The durable consumer every worker on a queue shares (`Broker\Nats::CONSUMER_WORK`).
const CONSUMER: &str = "worker";
/// Consumer metadata `Broker\Nats` sets when it provisions one delivery past its budget.
const SPARE_DELIVERY: &str = "utopia_queue_spare_delivery";
const CONTENT_TYPE: &str = "Content-Type";
const WORK_SEQUENCE: &str = "Utopia-Queue-Work-Sequence";
const WORK_TIME: &str = "Utopia-Queue-Work-Time";
/// How long a receive waits for more once it has a message (`Broker\Nats::pull()`'s top-up).
const TOP_UP: Duration = Duration::from_millis(250);
/// How long a worker waits for a stream no publisher has created yet before asking again.
const PROVISION_POLL: Duration = Duration::from_secs(5);

/// What a [`Worker`] can fail with.
#[derive(Debug, thiserror::Error)]
pub enum Error {
    /// The DSN is not a `nats://` one.
    #[error("invalid queue DSN: {0}")]
    Dsn(String),
    /// The server or the connection failed.
    #[error("NATS: {0}")]
    Nats(async_nats::Error),
}

fn nats(error: impl Into<async_nats::Error>) -> Error {
    Error::Nats(error.into())
}

/// A [`Worker`] result.
pub type Result<T> = std::result::Result<T, Error>;

/// A message as `Broker\Nats` enqueues it.
#[derive(Debug, Clone, Deserialize)]
pub struct Envelope {
    /// The publisher's message id.
    pub pid: String,
    /// The queue it was published to.
    pub queue: String,
    /// When it was published, in Unix seconds.
    pub timestamp: i64,
    /// The job's payload.
    #[serde(default)]
    pub payload: serde_json::Value,
}

/// A message taken from the queue, held until it is committed or rejected.
///
/// Dropping one without either leaves it to come back once the queue's ack wait passes.
pub struct Delivery {
    /// The message as it was published.
    pub envelope: Envelope,
    /// Deliveries before this one, as `Utopia\Queue\Message::getAttempts()` counts them.
    pub attempts: u64,
    message: jetstream::Message,
    received: Instant,
}

/// A consumer of one queue, alongside the PHP workers on it.
pub struct Worker {
    js: Context,
    consumer: PullConsumer,
    dead_subject: String,
    /// The delivery a rejection dead-letters on (`Broker\Nats`'s `maxDeliver`).
    max_deliver: u64,
    /// The extra delivery a message reaches only when every attempt before it died
    /// unacknowledged. It is dead-lettered on arrival.
    spare: Option<u64>,
    backoff: Vec<Duration>,
    ack_wait: Duration,
    metrics: Metrics,
}

impl Worker {
    /// Connect with the `nats://[user:password@]host[:port]` DSN `Broker\Nats` takes (its query
    /// configures provisioning and is not read here) and join `queue`.
    pub async fn connect(dsn: &str, queue: &str) -> Result<Self> {
        let dsn = Dsn::parse(dsn).map_err(|error| Error::Dsn(error.to_string()))?;
        if dsn.scheme() != "nats" {
            return Err(Error::Dsn(format!("expected the nats scheme, got {}", dsn.scheme())));
        }
        let user = String::from_utf8_lossy(dsn.user().unwrap_or_default()).into_owned();
        let password = String::from_utf8_lossy(dsn.password().unwrap_or_default()).into_owned();
        // As Broker\Nats::connectionOptions(): a user alone is a token.
        let options = match (user.is_empty(), password.is_empty()) {
            (false, false) => async_nats::ConnectOptions::with_user_and_password(user, password),
            (false, true) => async_nats::ConnectOptions::with_token(user),
            _ => async_nats::ConnectOptions::new(),
        };
        let server = format!("nats://{}:{}", dsn.host(), dsn.port().unwrap_or(4222));
        let client = options.connect(server).await.map_err(nats)?;

        Self::join(jetstream::new(client), queue).await
    }

    /// Join `queue` on an existing JetStream context.
    pub async fn join(js: Context, queue: &str) -> Result<Self> {
        let stream = format!("Q_{}", token(queue).to_uppercase());
        let mut consumer: PullConsumer = loop {
            match js.get_stream(&stream).await {
                Ok(stream) => break stream.get_consumer(CONSUMER).await.map_err(nats)?,
                Err(error) if matches!(error.kind(), GetStreamErrorKind::JetStream(e) if e.error_code() == ErrorCode::STREAM_NOT_FOUND) =>
                {
                    utopia_async::sleep(PROVISION_POLL).await;
                }
                Err(error) => return Err(nats(error)),
            }
        };

        let config = &consumer.info().await.map_err(nats)?.config;
        let spare = config.metadata.get(SPARE_DELIVERY).is_some_and(|value| value == "1");
        let (max_deliver, spare) = match u64::try_from(config.max_deliver) {
            Ok(n) if spare && n > 1 => (n - 1, Some(n)),
            Ok(n) if n > 0 => (n, None),
            _ => (u64::MAX, None),
        };
        let backoff = config.backoff.clone();
        let ack_wait = config.ack_wait.max(Duration::from_secs(1));
        let dead_subject = format!("q.{}.dead", token(queue).to_lowercase());

        // The global provider as it is now: one installed after a worker joins is not picked up.
        let metrics = Metrics::new(&opentelemetry::global::meter("utopia-queue"), queue);

        Ok(Self { js, consumer, dead_subject, max_deliver, spare, backoff, ack_wait, metrics })
    }

    /// Up to `max` messages, waiting at most `expires` for the first.
    ///
    /// The rest are only what arrives within a short top-up, as in `Broker\Nats::pull()`: a
    /// batch the consumer's maxAckPending will not let fill would otherwise hold the first
    /// messages, unextended, for all of `expires`.
    ///
    /// A message no worker could read, or one on its spare delivery, is dead-lettered here and
    /// not returned, as `Broker\Nats` parks it.
    pub async fn receive(&self, max: usize, expires: Duration) -> Result<Vec<Delivery>> {
        let mut deliveries = Vec::new();
        self.fetch(1, expires, &mut deliveries).await?;
        if max > 1 && !deliveries.is_empty() {
            self.fetch(max - 1, TOP_UP, &mut deliveries).await?;
        }
        // The work starts when the caller has the batch, as PHP's starts when its job callback
        // runs: the top-up wait counts as waiting, not as processing.
        let now = Instant::now();
        for delivery in &mut deliveries {
            delivery.received = now;
            self.metrics.received(delivery.envelope.timestamp, delivery.message.payload.len());
        }
        Ok(deliveries)
    }

    async fn fetch(&self, max: usize, expires: Duration, deliveries: &mut Vec<Delivery>) -> Result<()> {
        let mut batch = self.consumer.fetch().max_messages(max).expires(expires).messages().await.map_err(nats)?;
        while let Some(message) = batch.next().await {
            let message = message.map_err(nats)?;
            let delivered = delivered(&message)?;

            if self.spare.is_some_and(|spare| delivered >= spare) {
                self.park(&message).await?;
                continue;
            }

            match serde_json::from_slice::<Envelope>(&message.payload) {
                Ok(envelope) => {
                    deliveries.push(Delivery { envelope, attempts: delivered - 1, message, received: Instant::now() })
                }
                Err(_) => self.park(&message).await?,
            }
        }
        Ok(())
    }

    /// The message is done with.
    pub async fn commit(&self, delivery: Delivery) -> Result<()> {
        self.metrics.settled(delivery.received);
        delivery.message.double_ack().await.map_err(nats)
    }

    /// The message failed. It comes back after the queue's backoff or, on its last attempt or
    /// when `terminal`, goes to the dead stream, where PHP's `retry()` can requeue it.
    pub async fn reject(&self, delivery: Delivery, terminal: bool) -> Result<()> {
        self.metrics.settled(delivery.received);
        let message = &delivery.message;
        let delivered = delivered(message)?;

        if !terminal && delivered < self.max_deliver {
            // Broker\Nats::backoffFor(): the step for this delivery, the last one past the end.
            let step = delivered.min(self.backoff.len() as u64).saturating_sub(1) as usize;
            return message.ack_with(AckKind::Nak(self.backoff.get(step).copied())).await.map_err(nats);
        }

        let info = message.info().map_err(nats)?;
        let sequence = info.stream_sequence;
        let time = info.published.unix_timestamp_nanos().to_string();
        let id = format!("{}.dead.{sequence}", delivery.envelope.pid);
        self.dead_letter(&id, sequence, &time, &message.payload).await?;
        message.ack_with(AckKind::Term).await.map_err(nats)
    }

    /// Keep a message that is still being worked on from coming back for another ack wait.
    pub async fn extend(&self, delivery: &Delivery) -> Result<()> {
        delivery.message.ack_with(AckKind::Progress).await.map_err(nats)
    }

    /// Run `work` while holding `deliveries`: each is extended every third of the ack wait
    /// (`Broker\Nats::extendInterval()`), so none is redelivered to another worker while this
    /// one is still on it.
    pub async fn hold<F: Future>(&self, deliveries: &[&Delivery], work: F) -> F::Output {
        let beat = self.ack_wait / 3;
        utopia_async::pin!(work);
        loop {
            utopia_async::select! {
                output = &mut work => return output,
                _ = utopia_async::sleep(beat) => {
                    for delivery in deliveries {
                        // A missed beat costs a redelivery, never the work in hand.
                        let _ = self.extend(delivery).await;
                    }
                }
            }
        }
    }

    /// `Broker\Nats::park()`: the bytes as they arrived, on the shared dead subject.
    async fn park(&self, message: &jetstream::Message) -> Result<()> {
        let mut headers = HeaderMap::new();
        if let Some(content_type) = message.headers.as_ref().and_then(|headers| headers.get(CONTENT_TYPE)) {
            headers.insert(CONTENT_TYPE, content_type.as_str());
        }
        let publish = PublishMessage::build().payload(message.payload.clone()).headers(headers);
        self.js.send_publish(self.dead_subject.clone(), publish).await.map_err(nats)?.await.map_err(nats)?;
        message.ack_with(AckKind::Term).await.map_err(nats)
    }

    /// `Broker\Nats::deadLetter()`: one subject per dead letter, written once, so two workers
    /// dead-lettering the same delivery store one copy.
    async fn dead_letter(&self, id: &str, sequence: u64, time: &str, data: &Bytes) -> Result<()> {
        let mut key = Vec::with_capacity(id.len() + 1 + data.len());
        key.extend_from_slice(id.as_bytes());
        key.push(b'\n');
        key.extend_from_slice(data);
        let subject = format!("{}.{:032x}", self.dead_subject, xxhash_rust::xxh3::xxh3_128(&key));

        let mut headers = HeaderMap::new();
        headers.insert(WORK_SEQUENCE, sequence.to_string().as_str());
        headers.insert(WORK_TIME, time);
        let publish = PublishMessage::build()
            .payload(data.clone())
            .headers(headers)
            .message_id(id)
            .expected_last_subject_sequence(0);

        match self.js.send_publish(subject, publish).await.map_err(nats)?.await {
            Ok(_) => Ok(()),
            Err(error) if error.kind() == PublishErrorKind::WrongLastSequence => Ok(()),
            Err(error) => Err(nats(error)),
        }
    }
}

/// Which delivery this is, counting from 1.
fn delivered(message: &jetstream::Message) -> Result<u64> {
    Ok(message.info().map_err(nats)?.delivered.max(1).unsigned_abs())
}

/// A queue name as one stream or subject token: anything outside A-Z, a-z, 0-9, `_` and `-`
/// becomes `_` (`Broker\Nats::streamToken()` and `subjectToken()`, before their case change).
fn token(name: &str) -> String {
    name.chars().map(|c| if c.is_ascii_alphanumeric() || c == '_' || c == '-' { c } else { '_' }).collect()
}

#[cfg(test)]
mod tests {
    use super::token;

    #[test]
    fn names_the_stream_and_subject_as_broker_nats_does() {
        assert_eq!(format!("Q_{}", token("v1-analytics").to_uppercase()), "Q_V1-ANALYTICS");
        assert_eq!(format!("q.{}.dead", token("v1.mails").to_lowercase()), "q.v1_mails.dead");
    }
}
