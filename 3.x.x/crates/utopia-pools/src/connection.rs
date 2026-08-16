use std::sync::{Arc, Weak};

use parking_lot::Mutex;

use crate::pool::PoolInner;
use crate::Recover;

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
