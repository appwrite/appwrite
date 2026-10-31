//! What publishers and consumers promise, whatever the broker.

use std::sync::Arc;

use php_std::{Array, Value};

use crate::connection::BoxFuture;
use crate::error::{Error, Result};
use crate::message::Message;
use crate::queue::Queue;

/// Publishes messages, waiting until the broker accepts each one
/// (`Utopia\Queue\Publisher\Synchronous`). Brokers implement it; wrappers
/// such as [`crate::broker::Background`] and [`crate::broker::Pool`] forward it.
pub trait Publisher: Send + Sync {
    /// Publishes one message; whether the broker took it.
    fn publish<'a>(&'a self, queue: &'a Queue, payload: Array) -> BoxFuture<'a, Result<bool>>;

    /// Publishes several messages, each independent, in as few round trips as
    /// the broker allows. An empty batch touches nothing.
    fn publish_many<'a>(&'a self, queue: &'a Queue, payloads: Vec<Array>) -> BoxFuture<'a, Result<bool>>;

    /// Requeues failed messages, up to `limit` (all when `None`).
    fn retry<'a>(&'a self, queue: &'a Queue, limit: Option<i64>) -> BoxFuture<'a, Result<()>>;

    /// Pending messages, or with `failed` everything the queue could not get
    /// through ([`Publisher::failed_count`]).
    fn queue_size<'a>(&'a self, queue: &'a Queue, failed: bool) -> BoxFuture<'a, Result<i64>> {
        Box::pin(async move { if failed { self.failed_count(queue).await } else { self.pending_count(queue).await } })
    }

    /// Messages waiting to be delivered.
    fn pending_count<'a>(&'a self, queue: &'a Queue) -> BoxFuture<'a, Result<i64>>;

    /// Messages this queue could not get through, whatever stopped them.
    fn failed_count<'a>(&'a self, queue: &'a Queue) -> BoxFuture<'a, Result<i64>>;
}

/// Accepts messages without waiting for the broker
/// (`Utopia\Queue\Publisher\Asynchronous`): the message is handed off for
/// background delivery. Fails with [`Error::BufferFull`] when it cannot be
/// accepted in time, so the caller can shed or slow down.
pub trait AsyncPublisher: Send + Sync {
    /// Hands a message off to be published in the background.
    fn enqueue<'a>(&'a self, queue: &'a Queue, payload: Array) -> BoxFuture<'a, Result<()>>;
}

/// Receives messages and settles them (`Utopia\Queue\Consumer`), plus the
/// optional capabilities a worker probes for: releasing prefetched work,
/// extending a delivery deadline, idle upkeep, an in-flight ceiling, and the
/// exclusive marker.
pub trait Consumer: Send + Sync {
    /// Blocks up to `timeout` seconds for the first message, then claims up
    /// to `n` (values below one use one) without waiting for the batch to
    /// fill. Empty on timeout. Each message needs its own commit or reject.
    fn receive<'a>(&'a self, queue: &'a Queue, timeout: i64, n: i64) -> BoxFuture<'a, Result<Vec<Message>>>;

    /// Acknowledges a processed message.
    fn commit<'a>(&'a self, queue: &'a Queue, message: &'a Message) -> BoxFuture<'a, Result<()>>;

    /// Marks a message as failed. The broker decides what the failure buys:
    /// another attempt, or (for a terminal message) the end of the line.
    fn reject<'a>(&'a self, queue: &'a Queue, message: &'a Message) -> BoxFuture<'a, Result<()>>;

    /// Closes the consumer.
    fn close(&self) -> BoxFuture<'_, ()>;

    /// Returns prefetched messages that never reached a handler without
    /// counting a failure. `None` when the broker cannot.
    fn release<'a>(&'a self, _queue: &'a Queue, _messages: &'a [Message]) -> Option<BoxFuture<'a, Result<()>>> {
        None
    }

    /// Tells the broker handlers are still working on these messages.
    fn extend<'a>(&'a self, _queue: &'a Queue, _messages: &'a [Message]) -> BoxFuture<'a, Result<()>> {
        Box::pin(async { Ok(()) })
    }

    /// How often [`Consumer::extend`] should be called, in seconds; `None`
    /// when the broker cannot extend.
    fn extend_interval(&self) -> BoxFuture<'_, Option<f64>> {
        Box::pin(async { None })
    }

    /// Idle upkeep, safe while a receive is running. `None` when the
    /// consumer has none.
    fn maintain(&self) -> Option<BoxFuture<'_, Result<()>>> {
        None
    }

    /// Most messages the broker lets this consumer hold unacknowledged, when
    /// it sets a ceiling (`Consumer\Bounded`).
    fn in_flight_ceiling(&self) -> BoxFuture<'_, Option<i64>> {
        Box::pin(async { None })
    }

    /// Whether the consumer drives one transport without serialising access
    /// to it (`Consumer\Exclusive`): a worker refuses to run it with more than
    /// one message in flight.
    fn is_exclusive(&self) -> bool {
        false
    }

    /// The consumer's name in errors (PHP's class name).
    fn name(&self) -> &str {
        "Consumer"
    }
}

impl<C: Consumer + ?Sized> Consumer for Arc<C> {
    fn receive<'a>(&'a self, queue: &'a Queue, timeout: i64, n: i64) -> BoxFuture<'a, Result<Vec<Message>>> {
        (**self).receive(queue, timeout, n)
    }
    fn commit<'a>(&'a self, queue: &'a Queue, message: &'a Message) -> BoxFuture<'a, Result<()>> {
        (**self).commit(queue, message)
    }
    fn reject<'a>(&'a self, queue: &'a Queue, message: &'a Message) -> BoxFuture<'a, Result<()>> {
        (**self).reject(queue, message)
    }
    fn close(&self) -> BoxFuture<'_, ()> {
        (**self).close()
    }
    fn release<'a>(&'a self, queue: &'a Queue, messages: &'a [Message]) -> Option<BoxFuture<'a, Result<()>>> {
        (**self).release(queue, messages)
    }
    fn extend<'a>(&'a self, queue: &'a Queue, messages: &'a [Message]) -> BoxFuture<'a, Result<()>> {
        (**self).extend(queue, messages)
    }
    fn extend_interval(&self) -> BoxFuture<'_, Option<f64>> {
        (**self).extend_interval()
    }
    fn maintain(&self) -> Option<BoxFuture<'_, Result<()>>> {
        (**self).maintain()
    }
    fn in_flight_ceiling(&self) -> BoxFuture<'_, Option<i64>> {
        (**self).in_flight_ceiling()
    }
    fn is_exclusive(&self) -> bool {
        (**self).is_exclusive()
    }
    fn name(&self) -> &str {
        (**self).name()
    }
}

impl<P: Publisher + ?Sized> Publisher for Arc<P> {
    fn publish<'a>(&'a self, queue: &'a Queue, payload: Array) -> BoxFuture<'a, Result<bool>> {
        (**self).publish(queue, payload)
    }
    fn publish_many<'a>(&'a self, queue: &'a Queue, payloads: Vec<Array>) -> BoxFuture<'a, Result<bool>> {
        (**self).publish_many(queue, payloads)
    }
    fn retry<'a>(&'a self, queue: &'a Queue, limit: Option<i64>) -> BoxFuture<'a, Result<()>> {
        (**self).retry(queue, limit)
    }
    fn queue_size<'a>(&'a self, queue: &'a Queue, failed: bool) -> BoxFuture<'a, Result<i64>> {
        (**self).queue_size(queue, failed)
    }
    fn pending_count<'a>(&'a self, queue: &'a Queue) -> BoxFuture<'a, Result<i64>> {
        (**self).pending_count(queue)
    }
    fn failed_count<'a>(&'a self, queue: &'a Queue) -> BoxFuture<'a, Result<i64>> {
        (**self).failed_count(queue)
    }
}

/// A payload from any serialisable value: a JSON object or array decoded the
/// way PHP decodes a request body (empty objects stay objects).
///
/// ```
/// let payload = utopia_queue::payload(&serde_json::json!({"id": 7}))?;
/// assert_eq!(payload.get_str("id"), Some(&php_std::Value::Int(7)));
/// # Ok::<(), utopia_queue::Error>(())
/// ```
///
/// ```php
/// $payload = json_decode('{"id":7}', true);
/// var_dump($payload['id']); // int(7)
/// ```
pub fn payload<T: serde::Serialize + ?Sized>(value: &T) -> Result<Array> {
    let json = serde_json::to_value(value).map_err(|e| Error::Json(e.to_string()))?;
    match Value::<php_std::Never>::from_json(&json) {
        Value::Array(a) => Ok(a),
        Value::Object(o) if o.is_empty() => Ok(Array::new()),
        other => Err(Error::Type(format!("A payload must be an array, {} given", other.debug_type()))),
    }
}

/// When things happen and how they are named: the clock and the ids a
/// broker stamps on messages and deliveries. Replace it to make runs
/// reproducible (tests pin both).
pub trait Clock: Send + Sync {
    /// `time()`: seconds since the epoch.
    fn time(&self) -> i64;
    /// `uniqid('', true)`: a new message pid.
    fn uniqid(&self) -> String;
    /// `bin2hex(random_bytes(16))`: a new delivery token.
    fn token(&self) -> String;
}

/// The system clock and random ids.
#[derive(Debug, Clone, Copy, Default)]
pub struct SystemClock;

impl Clock for SystemClock {
    fn time(&self) -> i64 {
        php_std::datetime::time()
    }

    fn uniqid(&self) -> String {
        php_std::system::uniqid("", true)
    }

    fn token(&self) -> String {
        let mut b = [0u8; 16];
        let _ = getrandom::fill(&mut b);
        hex::encode(b)
    }
}
