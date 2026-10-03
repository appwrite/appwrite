use std::sync::Arc;

use utopia_cache::{Cache as UtopiaCache, CacheValue, LoadResult, SaveResult};

use super::{cursor_key, Cursor};
use crate::FeedError;

/// PHP `Utopia\Feed\Cursor\Cache`.
#[derive(Clone)]
pub struct Cache {
    cache: Arc<UtopiaCache>,
    ttl: i64,
}

impl std::fmt::Debug for Cache {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Cache")
            .field("ttl", &self.ttl)
            .finish_non_exhaustive()
    }
}

impl Cache {
    /// PHP `Cursor\Cache::TTL`.
    pub const TTL: i64 = 30 * 24 * 60 * 60;

    #[must_use]
    pub fn new(cache: UtopiaCache) -> Self {
        Self::from_arc(Arc::new(cache))
    }

    /// Share one [`UtopiaCache`] with a [`crate::CacheStore`] (PHP object handle).
    #[must_use]
    pub fn from_arc(cache: Arc<UtopiaCache>) -> Self {
        Self {
            cache,
            ttl: Self::TTL,
        }
    }

    #[must_use]
    pub fn with_ttl(mut self, ttl: i64) -> Self {
        self.ttl = ttl;
        self
    }
}

impl Cursor for Cache {
    fn load(&self, feed: &str, consumer: &str) -> Result<Option<String>, FeedError> {
        let key = cursor_key(feed, consumer)?;
        let loaded = self.cache.load(&key, self.ttl, "").map_err(|e| {
            FeedError::transport(format!("Failed to load the {consumer} cursor: {e}"))
        })?;
        Ok(match loaded {
            LoadResult::Hit(v) => v.as_str().filter(|s| !s.is_empty()).map(str::to_owned),
            LoadResult::Miss => None,
        })
    }

    fn save(&self, feed: &str, consumer: &str, event_id: &str) -> Result<(), FeedError> {
        let key = cursor_key(feed, consumer)?;
        match self
            .cache
            .save(&key, CacheValue::from(event_id.to_owned()), "")
        {
            Ok(SaveResult::Saved(_)) => Ok(()),
            Ok(SaveResult::Failed) => Err(FeedError::transport(format!(
                "Failed to save the {consumer} cursor on the {feed} feed"
            ))),
            Err(e) => Err(FeedError::transport(format!(
                "Failed to save the {consumer} cursor: {e}"
            ))),
        }
    }

    fn reset(&self, feed: &str, consumer: &str) -> Result<(), FeedError> {
        let key = cursor_key(feed, consumer)?;
        self.cache.purge(&key, "").map_err(|e| {
            FeedError::transport(format!("Failed to reset the {consumer} cursor: {e}"))
        })?;
        Ok(())
    }
}
