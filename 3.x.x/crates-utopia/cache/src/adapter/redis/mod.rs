//! PHP `Utopia\Cache\Adapter\Redis\*` protocol types and multiplexing.

pub(crate) mod client;
pub(crate) mod envelope;
pub(crate) mod leasable;
pub(crate) mod multiplexing;
pub(crate) mod noscript;
pub(crate) mod types;

pub use client::Client;
pub use envelope::Envelope;
pub use leasable::{
    effective_hash, is_reserved, GENERATION_FIELD, LUA_PURGE_BUMP, LUA_PURGE_FIELD,
    LUA_SAVE_WITH_LEASE, TOMBSTONE_FIELD,
};
pub use multiplexing::Multiplexing;
pub use noscript::NoScript;
pub use types::{
    ConnectionContext, ConnectionError, ConnectionException, ParseOutcome, RedisError, RespValue,
};
