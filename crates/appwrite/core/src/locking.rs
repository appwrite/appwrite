//! Appwrite's lock policy (`src/Appwrite/Locking/Lock.php`) on top of
//! `utopia_lock::Distributed`: a Redis outage never blocks a request (the
//! section runs without the lock), and `_APP_LOCKING_ENABLED=disabled` turns
//! locking off. Telemetry counters and error reports of the PHP class are
//! not emitted yet.

use std::future::Future;

use utopia_cache::redis::aio::ConnectionManager;
use utopia_lock::{Distributed, Error, Lock, Wait};

/// TTL of a lease taken by [`Locking::try_with_key`].
const SKIP_TTL_SECONDS: i64 = 5;

#[derive(Clone)]
pub struct Locking {
    conn: ConnectionManager,
    enabled: bool,
}

impl Locking {
    pub fn new(conn: ConnectionManager) -> Self {
        let enabled = utopia_system::env_or("_APP_LOCKING_ENABLED", "enabled") != "disabled";
        Self { conn, enabled }
    }

    /// `Lock::tryWithKey`: runs `section` when the lock on `key` is free,
    /// without waiting. Returns `None` when another holder has it. A Redis
    /// failure runs `section` unlocked.
    pub async fn try_with_key<F, T>(&self, key: &str, section: F) -> Option<T>
    where
        F: Future<Output = T> + Send,
        T: Send,
    {
        if !self.enabled {
            return Some(section.await);
        }
        let lock = Distributed::new(self.conn.clone(), key, SKIP_TTL_SECONDS);
        match lock.acquire(Wait::None).await {
            Ok(true) => {
                let result = section.await;
                if let Err(e) = lock.release().await {
                    tracing::warn!(key, error = %e, "lock release_error");
                }
                Some(result)
            }
            Ok(false) => None,
            Err(e @ Error::Redis(_)) => {
                tracing::warn!(key, error = %e, "lock backend_error");
                Some(section.await)
            }
            Err(e) => {
                tracing::warn!(key, error = %e, "lock error");
                Some(section.await)
            }
        }
    }
}
