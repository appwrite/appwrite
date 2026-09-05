use utopia_pools::Pool as UtopiaPool;

use super::redis::{redis_load, redis_reset, redis_save};
use super::Cursor;
use crate::store::RedisConn;
use crate::FeedError;

/// PHP `Utopia\Feed\Cursor\Pool`.
pub struct Pool {
    pool: UtopiaPool<RedisConn>,
}

impl std::fmt::Debug for Pool {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Pool").finish_non_exhaustive()
    }
}

impl Pool {
    #[must_use]
    pub fn new(pool: UtopiaPool<RedisConn>) -> Self {
        Self { pool }
    }

    fn with_conn<R>(
        &self,
        f: impl FnOnce(&mut RedisConn) -> Result<R, FeedError>,
    ) -> Result<R, FeedError> {
        self.pool
            .use_sync(f)
            .map_err(|e| FeedError::transport(e.to_string()))?
    }
}

impl Cursor for Pool {
    fn load(&self, feed: &str, consumer: &str) -> Result<Option<String>, FeedError> {
        self.with_conn(|conn| redis_load(&mut conn.inner, feed, consumer))
    }

    fn save(&self, feed: &str, consumer: &str, event_id: &str) -> Result<(), FeedError> {
        self.with_conn(|conn| redis_save(&mut conn.inner, feed, consumer, event_id))
    }

    fn reset(&self, feed: &str, consumer: &str) -> Result<(), FeedError> {
        self.with_conn(|conn| redis_reset(&mut conn.inner, feed, consumer))
    }

    fn advance(
        &self,
        feed: &str,
        consumer: &str,
        event_id: &str,
        expected: Option<&str>,
    ) -> Result<bool, FeedError> {
        self.with_conn(|conn| {
            if redis_load(&mut conn.inner, feed, consumer)?.as_deref() != expected {
                return Ok(false);
            }
            redis_save(&mut conn.inner, feed, consumer, event_id)?;
            Ok(true)
        })
    }
}
