use utopia_cloudevents::CloudEvent;
use utopia_pools::Pool as UtopiaPool;

use super::redis::{redis_append, redis_read, redis_tip, RedisConn};
use super::{store_poll, validate_store, DEFAULT_MAX_SIZE, DEFAULT_POLL_INTERVAL};
use crate::{Appendable, FeedError, Readable, Store, TIP};

/// PHP `Utopia\Feed\Store\Pool`.
pub struct Pool {
    pool: UtopiaPool<RedisConn>,
    name: String,
    max_size: usize,
    poll_interval: i64,
}

impl std::fmt::Debug for Pool {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Pool")
            .field("name", &self.name)
            .field("max_size", &self.max_size)
            .field("poll_interval", &self.poll_interval)
            .finish_non_exhaustive()
    }
}

impl Pool {
    pub fn new(pool: UtopiaPool<RedisConn>, name: impl Into<String>) -> Result<Self, FeedError> {
        Self::with_limits(pool, name, DEFAULT_MAX_SIZE, DEFAULT_POLL_INTERVAL)
    }

    pub fn with_limits(
        pool: UtopiaPool<RedisConn>,
        name: impl Into<String>,
        max_size: usize,
        poll_interval: i64,
    ) -> Result<Self, FeedError> {
        let name = name.into();
        validate_store(&name, max_size, poll_interval)?;
        Ok(Self {
            pool,
            name,
            max_size,
            poll_interval,
        })
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

impl Readable for Pool {
    fn get_name(&self) -> &str {
        &self.name
    }

    fn is_store(&self) -> bool {
        true
    }

    fn read(&self, last_event_id: Option<&str>, limit: i64) -> Result<Vec<CloudEvent>, FeedError> {
        self.with_conn(|conn| {
            let tip = if last_event_id == Some(TIP) {
                redis_tip(&mut conn.inner, &self.name)?
            } else {
                None
            };
            redis_read(&mut conn.inner, &self.name, last_event_id, limit, tip)
        })
    }

    fn poll(
        &self,
        last_event_id: Option<&str>,
        limit: i64,
        timeout: i64,
    ) -> Result<Vec<CloudEvent>, FeedError> {
        store_poll(
            self,
            last_event_id,
            limit,
            timeout,
            self.poll_interval as u64,
        )
    }

    fn tip(&self) -> Result<Option<String>, FeedError> {
        self.with_conn(|conn| redis_tip(&mut conn.inner, &self.name))
    }
}

impl Appendable for Pool {
    fn append(&self, event: CloudEvent) -> Result<String, FeedError> {
        self.with_conn(|conn| redis_append(&mut conn.inner, &self.name, self.max_size, &event))
    }
}

impl Store for Pool {}
