//! Synchronisation between tasks: mutexes, read-write locks, semaphores,
//! notifications, barriers, cells and channels. Waiting parks the task, not
//! the thread.
//!
//! These primitives need no engine (they run on any), so they are the same
//! whichever adapter the build uses. They are Tokio's, the fastest and most
//! used in the ecosystem.
//!
//! | PHP (Swoole) | Rust |
//! |---|---|
//! | `Swoole\Coroutine\Lock` | [`Mutex`] |
//! | `Swoole\Coroutine\Channel` (capacity `n`) | [`mpsc::channel`] (capacity `n`) |
//! | `Swoole\Coroutine\Channel` of one value | [`oneshot::channel`] |
//! | `Swoole\Coroutine\WaitGroup` | [`crate::task::JoinSet`], [`Semaphore`] |
//! | `Swoole\Coroutine\Barrier` | [`Barrier`] |

#[doc(no_inline)]
pub use ::tokio::sync::{
    AcquireError, Barrier, BarrierWaitResult, MappedMutexGuard, Mutex, MutexGuard, Notify, OnceCell,
    OwnedMappedMutexGuard, OwnedMutexGuard, OwnedRwLockReadGuard, OwnedRwLockWriteGuard, OwnedSemaphorePermit, RwLock,
    RwLockMappedWriteGuard, RwLockReadGuard, RwLockWriteGuard, Semaphore, SemaphorePermit, SetError, TryAcquireError,
    TryLockError, broadcast, mpsc, oneshot, watch,
};
