use super::{cursor_key, Cursor};
use crate::FeedError;

/// PHP `Utopia\Feed\Cursor\None`.
#[derive(Clone, Debug, Default)]
pub struct None;

impl None {
    #[must_use]
    pub fn new() -> Self {
        Self
    }
}

impl Cursor for None {
    fn load(&self, feed: &str, consumer: &str) -> Result<Option<String>, FeedError> {
        cursor_key(feed, consumer)?;
        Ok(Option::None)
    }

    fn save(&self, feed: &str, consumer: &str, _event_id: &str) -> Result<(), FeedError> {
        cursor_key(feed, consumer)?;
        Ok(())
    }

    fn reset(&self, feed: &str, consumer: &str) -> Result<(), FeedError> {
        cursor_key(feed, consumer)?;
        Ok(())
    }

    fn advance(
        &self,
        feed: &str,
        consumer: &str,
        _event_id: &str,
        _expected: Option<&str>,
    ) -> Result<bool, FeedError> {
        cursor_key(feed, consumer)?;
        Ok(true)
    }
}
