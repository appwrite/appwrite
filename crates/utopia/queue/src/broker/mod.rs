//! Brokers: where messages are stored and how they are delivered.
//!
//! | PHP | Rust |
//! |---|---|
//! | `Broker\Redis` | [`Redis`] |
//! | `Broker\Nats` | [`Nats`] (feature `nats`) |
//! | `Broker\Pool` | [`Pool`] over [`PoolResources`] |
//! | `Broker\Background` | [`Background`] |
//! | `Broker\Provisioning` | [`Provisioning`] |

mod background;
#[cfg(feature = "nats")]
mod nats;
mod pool;
mod redis;

pub use background::{Background, Log};
#[cfg(feature = "nats")]
pub use nats::{DsnArgument, MessageId, Nats, NatsConfig, OnError};
pub use pool::{Pool, Resources as PoolResources};
pub use redis::{ReconnectCallback, ReconnectSuccessCallback, Redis};

use php_std::Value;

/// Seconds a decoded envelope has waited at `now`, as `getQueueAge()` reads
/// it: `max(0, time() - (int) $envelope['timestamp'])` when the envelope is
/// an array with a numeric timestamp, otherwise 0.
pub(crate) fn envelope_age(envelope: &Value, now: i64) -> i64 {
    let Value::Array(a) = envelope else { return 0 };
    let timestamp = match a.get_str("timestamp") {
        Some(Value::Int(i)) => *i,
        Some(Value::Float(f)) => php_std::format::dval_to_lval(*f),
        Some(Value::Str(s)) if php_std::value::numeric_bytes_ex(s.as_bytes()).is_some() => {
            php_std::format::str_to_int(s.as_bytes())
        }
        _ => return 0,
    };
    now.saturating_sub(timestamp).max(0)
}

/// Whether a broker may create and rewrite a queue's server-side
/// configuration (`Broker\Provisioning`).
#[derive(Debug, Clone, Copy, PartialEq, Eq, Default)]
pub enum Provisioning {
    /// Create streams and consumers if absent and bring an existing queue's
    /// configuration in line with the broker's settings.
    #[default]
    Ensure,
    /// Never send stream or worker-consumer configuration: the queue must be
    /// provisioned already, or the broker refuses.
    Require,
}

#[cfg(test)]
mod tests {
    use php_std::{Array, Value};

    use super::envelope_age;

    fn envelope(timestamp: Value) -> Value {
        let mut a = Array::new();
        a.set("timestamp".into(), timestamp);
        Value::Array(a)
    }

    #[test]
    fn age() {
        assert_eq!(envelope_age(&envelope(Value::Int(90)), 100), 10);
        assert_eq!(envelope_age(&envelope(Value::Float(89.9)), 100), 11);
        assert_eq!(envelope_age(&envelope(Value::from("80")), 100), 20);
        assert_eq!(envelope_age(&envelope(Value::from("1e1")), 100), 90);
        assert_eq!(envelope_age(&envelope(Value::Int(200)), 100), 0);
        assert_eq!(envelope_age(&envelope(Value::from("soon")), 100), 0);
        assert_eq!(envelope_age(&envelope(Value::Null), 100), 0);
        assert_eq!(envelope_age(&Value::Int(1), 100), 0);
    }
}
