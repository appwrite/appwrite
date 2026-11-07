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
pub use nats::{MessageId, Nats, NatsConfig, OnError};
pub use pool::{Pool, Resources as PoolResources};
pub use redis::{ReconnectCallback, ReconnectSuccessCallback, Redis};

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
