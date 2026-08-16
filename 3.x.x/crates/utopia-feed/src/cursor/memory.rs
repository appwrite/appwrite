use parking_lot::Mutex;
use std::collections::HashMap;
use std::sync::Arc;

use super::{cursor_key, Cursor};
use crate::FeedError;

/// PHP `Utopia\Feed\Cursor\Memory`.
#[derive(Clone, Debug, Default)]
pub struct Memory {
    cursors: Arc<Mutex<HashMap<String, String>>>,
}

impl Memory {
    #[must_use]
    pub fn new() -> Self {
        Self::default()
    }
}

impl Cursor for Memory {
    fn load(&self, feed: &str, consumer: &str) -> Result<Option<String>, FeedError> {
        let key = cursor_key(feed, consumer)?;
        Ok(self.cursors.lock().get(&key).cloned())
    }

    fn save(&self, feed: &str, consumer: &str, event_id: &str) -> Result<(), FeedError> {
        let key = cursor_key(feed, consumer)?;
        self.cursors.lock().insert(key, event_id.to_owned());
        Ok(())
    }

    fn reset(&self, feed: &str, consumer: &str) -> Result<(), FeedError> {
        let key = cursor_key(feed, consumer)?;
        self.cursors.lock().remove(&key);
        Ok(())
    }
}
