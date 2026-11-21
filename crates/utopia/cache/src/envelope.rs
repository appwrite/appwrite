//! [`Envelope`]: the timestamped record the hash-based adapters store.

use php_std::types::{Array, ArrayKey, Value};

use crate::{Codec, Result};

/// The `{"time": <unix>, "data": <value>}` record the Redis, Redis Cluster
/// and Hazelcast adapters store, run through a [`Codec`]. Encoding adds the
/// timestamp; decoding enforces the TTL against a caller-supplied `now`.
///
/// ```
/// use php_std::types::Value;
/// use utopia_cache::{Envelope, Json};
///
/// let envelope = Envelope::new(Json);
/// let stored = envelope.encode(&Value::from("hello"), 1_700_000_000)?;
/// assert_eq!(stored, br#"{"time":1700000000,"data":"hello"}"#);
///
/// // Fresh while `time + ttl > now`.
/// assert_eq!(envelope.decode(&stored, 60, 1_700_000_059), Some(Value::from("hello")));
/// assert_eq!(envelope.decode(&stored, 60, 1_700_000_060), None);
/// # Ok::<(), Box<dyn std::error::Error>>(())
/// ```
///
/// ```php
/// use Utopia\Cache\Codec\Json;
/// use Utopia\Cache\Envelope;
///
/// $envelope = new Envelope(new Json());
/// $stored = $envelope->encode('hello', 1700000000);
/// echo $stored, "\n"; // {"time":1700000000,"data":"hello"}
/// var_dump($envelope->decode($stored, 60, 1700000059)); // string(5) "hello"
/// var_dump($envelope->decode($stored, 60, 1700000060)); // bool(false)
/// ```
#[derive(Debug, Clone, Copy, Default, PartialEq, Eq)]
pub struct Envelope<C> {
    codec: C,
}

impl<C: Codec> Envelope<C> {
    /// An envelope written with `codec`.
    pub const fn new(codec: C) -> Self {
        Envelope { codec }
    }

    /// The codec.
    pub fn codec(&self) -> &C {
        &self.codec
    }

    /// The bytes stored for `data` written at `time` (unix seconds).
    ///
    /// Scalars other than strings are stored as strings; null and objects
    /// fail, as do values the codec cannot encode.
    ///
    /// PHP: the coercion of the `array|string` parameter.
    pub fn encode(&self, data: &Value, time: i64) -> Result<Vec<u8>> {
        let data = crate::adapter::coerce(data, "Utopia\\Cache\\Envelope::encode(): Argument #1 ($data)")?;
        // The record is written around the borrowed data where the codec can.
        self.codec.encode_envelope(time, &data)
    }

    /// The data of a stored envelope while it is fresh (`time + ttl > now`).
    /// `None` when it expired, or when the bytes are not an envelope (an
    /// undecodable payload, a missing or non-integer `time`, a missing or
    /// null `data`): never an error.
    pub fn decode(&self, stored: &[u8], ttl: i64, now: i64) -> Option<Value> {
        let mut record = self.unwrap(stored)?;
        let Some(Value::Int(time)) = record.get(&ArrayKey::from("time")) else { return None };
        let fresh = match time.checked_add(ttl) {
            Some(expiry) => expiry > now,
            // PHP overflows into floats.
            None => (*time as f64 + ttl as f64) > now as f64,
        };
        if !fresh {
            return None;
        }
        record.remove(&ArrayKey::from("data"))
    }

    /// The envelope re-stamped with `time`, its data unchanged. `None` when
    /// the bytes are not an envelope with data, or cannot be re-encoded.
    pub fn touch(&self, stored: &[u8], time: i64) -> Option<Vec<u8>> {
        let mut record = self.unwrap(stored)?;
        record.set(ArrayKey::from("time"), Value::Int(time));
        self.codec.encode(&Value::Array(record)).ok()
    }

    /// The decoded record when it is an array with non-null `data`.
    fn unwrap(&self, stored: &[u8]) -> Option<Array> {
        match self.codec.decode(stored).ok()? {
            Value::Array(record) if !matches!(record.get(&ArrayKey::from("data")), None | Some(Value::Null)) => {
                Some(record)
            }
            _ => None,
        }
    }
}
