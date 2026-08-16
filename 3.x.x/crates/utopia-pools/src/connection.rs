use std::sync::{Arc, Weak};

use parking_lot::{ArcMutexGuard, Mutex, RawMutex};

use crate::pool::PoolInner;
use crate::Recover;

/// An owned handle to a checked-out connection's resource, decoupled from
/// any borrow into a [`Connection`].
///
/// [`Connection::resource`] ties its `MutexGuard` to `&self`, which is fine
/// for call sites that keep the `Connection` value around alongside it. A
/// pool-backed `lock()`-style API for a multi-operation handler needs to
/// move *both* the connection and an exclusive handle to its resource out of
/// one function, so a borrow-based guard will not do. This wraps
/// `parking_lot`'s `arc_lock` guard, which clones the same `Arc<Mutex<T>>`
/// internally instead of borrowing it -- no lifetime, no `unsafe`, unlocks
/// on `Drop` like any other guard.
pub struct ResourceGuard<T> {
    guard: ArcMutexGuard<RawMutex, T>,
}

impl<T> std::ops::Deref for ResourceGuard<T> {
    type Target = T;
    fn deref(&self) -> &T {
        &self.guard
    }
}

impl<T> std::ops::DerefMut for ResourceGuard<T> {
    fn deref_mut(&mut self) -> &mut T {
        &mut self.guard
    }
}

impl<T> std::fmt::Debug for ResourceGuard<T> {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("ResourceGuard").finish_non_exhaustive()
    }
}

/// PHP `Utopia\Pools\Connection`.
///
/// `id` matches `$connection->id`. The resource is behind a mutex so the pool can
/// keep a handle for `reclaim()` / `destroy()` with no argument (PHP keeps the
/// object in `$active`).
pub struct Connection<T> {
    /// PHP `$connection->id` (`"{pool-name}-{uniqid}"`).
    pub id: String,
    pub(crate) resource: Arc<Mutex<T>>,
    pub(crate) pool: Weak<PoolInner<T>>,
}

impl<T> Clone for Connection<T> {
    fn clone(&self) -> Self {
        Self {
            id: self.id.clone(),
            resource: Arc::clone(&self.resource),
            pool: Weak::clone(&self.pool),
        }
    }
}

impl<T: Recover + Send + 'static> Connection<T> {
    pub(crate) fn new(id: String, resource: T, pool: Weak<PoolInner<T>>) -> Self {
        Self {
            id,
            resource: Arc::new(Mutex::new(resource)),
            pool,
        }
    }

    /// PHP `$connection->resource`.
    pub fn resource(&self) -> parking_lot::MutexGuard<'_, T> {
        self.resource.lock()
    }

    /// See [`ResourceGuard`].
    pub fn resource_owned(&self) -> ResourceGuard<T> {
        ResourceGuard {
            guard: Mutex::lock_arc(&self.resource),
        }
    }

    /// PHP `Connection::reclaim()`.
    pub fn reclaim(&self) {
        if let Some(pool) = self.pool.upgrade() {
            pool.reclaim_one(self);
        }
    }

    /// PHP `Connection::destroy()`.
    pub fn destroy(&self) {
        if let Some(pool) = self.pool.upgrade() {
            pool.destroy_one(self);
        }
    }
}

impl<T> std::fmt::Debug for Connection<T> {
    fn fmt(&self, formatter: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        formatter
            .debug_struct("Connection")
            .field("id", &self.id)
            .finish_non_exhaustive()
    }
}
