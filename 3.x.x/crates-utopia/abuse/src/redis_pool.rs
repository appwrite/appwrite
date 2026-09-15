use parking_lot::Mutex;
use redis::cluster::ClusterConnection;
use redis::Connection;
use std::sync::Arc;
use utopia_pools::{Pool as UtopiaPool, Recover, RecoverCall, Stack};

use crate::error::AbuseError;

/// A pooled Redis connection (standalone or cluster).
pub enum PooledRedis {
    /// `ext-redis` `\Redis`.
    Standalone(Connection),
    /// `ext-redis` `\RedisCluster`.
    Cluster(ClusterConnection),
}

impl std::fmt::Debug for PooledRedis {
    fn fmt(&self, formatter: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        match self {
            Self::Standalone(_) => formatter.write_str("PooledRedis::Standalone"),
            Self::Cluster(_) => formatter.write_str("PooledRedis::Cluster"),
        }
    }
}

impl PooledRedis {
    /// Whether this handle is a cluster connection.
    #[must_use]
    pub fn is_cluster(&self) -> bool {
        matches!(self, Self::Cluster(_))
    }
}

struct RecyclableRedis(PooledRedis);

impl Recover for RecyclableRedis {
    fn reset(&mut self) -> RecoverCall {
        RecoverCall::Succeeded
    }
}

/// [`utopia_pools::Pool`] of Redis connections (PHP `Utopia\Pools\Pool`).
///
/// `use_connection` checks a handle out, runs the closure, then returns it.
pub struct Pool {
    inner: UtopiaPool<RecyclableRedis>,
}

impl std::fmt::Debug for Pool {
    fn fmt(&self, formatter: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        formatter
            .debug_struct("Pool")
            .field("name", &self.inner.name())
            .field("size", &self.inner.size())
            .finish_non_exhaustive()
    }
}

impl Pool {
    /// Create a pool from already-open connections.
    #[must_use]
    pub fn new(connections: Vec<PooledRedis>) -> Self {
        let remaining = Arc::new(Mutex::new(connections));
        let size = remaining.lock().len().max(1);
        Self {
            inner: UtopiaPool::new(
                Stack::new(),
                "abuse-redis",
                size,
                move || {
                    RecyclableRedis(remaining.lock().pop().unwrap_or_else(|| {
                        panic!("abuse redis pool factory called more times than size")
                    }))
                },
                30.0,
            )
            .expect("abuse redis pool"),
        }
    }

    /// Open `size` standalone connections from `url`.
    ///
    /// # Errors
    ///
    /// Returns Redis connection errors.
    pub fn from_url(url: &str, size: usize) -> Result<Self, AbuseError> {
        let client = redis::Client::open(url)?;
        let size = size.max(1);
        Ok(Self {
            inner: UtopiaPool::new(
                Stack::new(),
                "abuse-redis",
                size,
                move || {
                    RecyclableRedis(PooledRedis::Standalone(
                        client.get_connection().expect("redis connection"),
                    ))
                },
                30.0,
            )
            .expect("abuse redis pool"),
        })
    }

    /// Configured pool size (PHP `$pool->getSize()`).
    #[must_use]
    pub fn len(&self) -> usize {
        self.inner.size()
    }

    /// Whether the pool has no slots.
    #[must_use]
    pub fn is_empty(&self) -> bool {
        self.inner.size() == 0
    }

    /// PHP `$pool->use(function ($redis) { ... })`.
    ///
    /// # Errors
    ///
    /// Returns [`AbuseError::PoolEmpty`] or the closure error.
    pub fn use_connection<F, R>(&self, func: F) -> Result<R, AbuseError>
    where
        F: FnOnce(&mut PooledRedis) -> Result<R, AbuseError>,
    {
        match self.inner.use_sync(|conn| func(&mut conn.0)) {
            Ok(result) => result,
            Err(_) => Err(AbuseError::PoolEmpty),
        }
    }
}
