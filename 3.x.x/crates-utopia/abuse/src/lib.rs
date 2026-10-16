//! Rate limiting and abuse control for Utopia.
//!
//! Rust port of [`utopia-php/abuse`](https://github.com/utopia-php/abuse).

pub mod adapters;
pub mod database;
pub mod redis_pool;

mod abuse;
mod adapter;
mod error;
mod logs;
mod redis_ops;
mod time_util;

pub use abuse::Abuse;
pub use adapter::{remaining_from, Adapter, AdapterState};
pub use error::AbuseError;
pub use logs::Logs;
pub use redis_ops::ClusterConnectionExt;
pub use time_util::{align_timestamp, format_datetime};

pub use adapters::ReCaptcha;
pub use adapters::SITEVERIFY_URL;

/// Prelude for the common abuse types.
pub mod prelude {
    pub use crate::{
        adapters, database, redis_pool, Abuse, AbuseError, Adapter, AdapterState, Logs, ReCaptcha,
    };
}
