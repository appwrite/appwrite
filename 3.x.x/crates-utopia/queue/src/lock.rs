//! PHP `Utopia\Lock` types used by the queue. Re-exports [`utopia_lock`].

pub use utopia_lock::{Contention, Lock, Mutex};

/// PHP `Utopia\Lock\Mutex` (historically `MutexLock` in this crate).
pub type MutexLock = Mutex;
