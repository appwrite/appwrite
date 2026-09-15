use utopia_cloudevents::CloudEvent;

use super::validate_store;
use crate::{Appendable, FeedError, Readable, Store};

/// PHP `Utopia\Feed\Store\None`.
#[derive(Debug)]
pub struct None {
    name: String,
}

impl None {
    /// PHP `__construct(string $name = 'none')`.
    pub fn new(name: impl Into<String>) -> Result<Self, FeedError> {
        let name = name.into();
        validate_store(&name, 1, 1)?;
        Ok(Self { name })
    }
}

impl Default for None {
    fn default() -> Self {
        Self {
            name: "none".into(),
        }
    }
}

fn unsupported(name: &str) -> FeedError {
    FeedError::unsupported(format!("No feed backend is configured for the {name} feed"))
}

impl Readable for None {
    fn get_name(&self) -> &str {
        &self.name
    }

    fn is_store(&self) -> bool {
        true
    }

    fn read(
        &self,
        _last_event_id: Option<&str>,
        _limit: i64,
    ) -> Result<Vec<CloudEvent>, FeedError> {
        Err(unsupported(&self.name))
    }

    fn poll(
        &self,
        last_event_id: Option<&str>,
        limit: i64,
        _timeout: i64,
    ) -> Result<Vec<CloudEvent>, FeedError> {
        self.read(last_event_id, limit)
    }

    fn tip(&self) -> Result<Option<String>, FeedError> {
        Err(unsupported(&self.name))
    }
}

impl Appendable for None {
    fn append(&self, _event: CloudEvent) -> Result<String, FeedError> {
        Err(unsupported(&self.name))
    }
}

impl Store for None {}
