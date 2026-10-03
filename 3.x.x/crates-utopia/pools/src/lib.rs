//! Generic resource pools for Utopia.
//!
//! Rust port of [`utopia-php/pools`](https://github.com/utopia-php/pools).

pub mod adapter;
mod connection;
mod error;
mod group;
mod pool;
mod recover;

pub use adapter::{Adapter, Stack, Swoole};
pub use connection::{Connection, ResourceGuard};
pub use error::{BoxError, PoolError, TypeError};
pub use group::Group;
pub use pool::Pool;
pub use recover::{Recover, RecoverCall};

/// Prelude for the PHP-shaped surface.
pub mod prelude {
    pub use crate::{
        adapter::{Stack, Swoole},
        Adapter, Connection, Group, Pool, PoolError, Recover, RecoverCall, ResourceGuard,
    };
}
