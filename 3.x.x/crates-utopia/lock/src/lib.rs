//! Locks for coordinating access to shared resources.
//!
//! Rust port of [`utopia-php/lock`](https://github.com/utopia-php/lock).

mod distributed;
mod error;
mod file;
mod lock;
mod mutex;
mod semaphore;

pub use distributed::{Distributed, RedisCommands};
pub use error::{Contention, LockError};
pub use file::{FileLock, LOCK_EX, LOCK_SH};
pub use lock::Lock;
pub use mutex::Mutex;
pub use semaphore::Semaphore;

/// PHP type alias: `Utopia\Lock\File`.
pub type File = FileLock;

pub mod prelude {
    pub use crate::{Contention, Distributed, FileLock, Lock, LockError, Mutex, Semaphore};
}
