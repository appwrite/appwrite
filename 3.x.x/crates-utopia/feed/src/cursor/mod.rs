mod cache;
mod memory;
mod none;
#[cfg(feature = "redis")]
mod pool;
#[cfg(feature = "redis")]
mod redis;

pub use cache::Cache;
pub use memory::Memory;
pub use none::None;
#[cfg(feature = "redis")]
pub use pool::Pool;
#[cfg(feature = "redis")]
pub use redis::Redis;

use crate::FeedError;

/// PHP `Utopia\Feed\Cursor`.
pub trait Cursor: Send + Sync {
    fn load(&self, feed: &str, consumer: &str) -> Result<Option<String>, FeedError>;
    fn save(&self, feed: &str, consumer: &str, event_id: &str) -> Result<(), FeedError>;
    fn reset(&self, feed: &str, consumer: &str) -> Result<(), FeedError>;

    /// PHP `Cursor::advance()`.
    fn advance(
        &self,
        feed: &str,
        consumer: &str,
        event_id: &str,
        expected: Option<&str>,
    ) -> Result<bool, FeedError> {
        if self.load(feed, consumer)?.as_deref() != expected {
            return Ok(false);
        }
        self.save(feed, consumer, event_id)?;
        Ok(true)
    }
}

pub(crate) fn cursor_key(feed: &str, consumer: &str) -> Result<String, FeedError> {
    if feed.is_empty() || consumer.is_empty() {
        return Err(FeedError::invalid(
            "Cursor requires a feed and a consumer name",
        ));
    }
    Ok(crate::Key::cursor(feed, consumer))
}
