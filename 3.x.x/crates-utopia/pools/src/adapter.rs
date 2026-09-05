//! PHP `Utopia\Pools\Adapter`.

use std::time::Duration;

use async_trait::async_trait;

use crate::{Connection, PoolError};

pub mod stack;
pub mod swoole;

pub use stack::Stack;
pub use swoole::Swoole;

/// Storage and synchronisation for a pool's idle resources.
///
/// The pool owns capacity accounting; an adapter only holds what is idle and
/// serialises access to the pool's bookkeeping.
#[async_trait]
pub trait Adapter<T: Send>: Send + Sync {
    /// Prepare to hold up to `size` idle resources. A sizing hint, not a cap.
    fn initialize(&self, size: usize);

    /// Push an idle connection.
    fn push(&self, connection: Connection<T>);

    /// Take an idle resource, waiting up to `timeout` for one to arrive.
    ///
    /// [`Stack`] ignores `timeout` and returns immediately.
    async fn pop(&self, timeout: Duration) -> Option<Connection<T>>;

    /// Number of idle connections currently held.
    fn count(&self) -> usize;

    /// Run `callback` atomically with respect to other pool operations.
    ///
    /// [`Stack`] has no concurrency and runs the callback directly.
    fn synchronized(&self, callback: Box<dyn FnOnce() + Send>) -> Result<(), PoolError>;
}
