use utopia_cloudevents::CloudEvent;

use crate::readable::MEDIA_TYPE;

/// PHP `Utopia\Feed\Batch`.
#[derive(Debug, Clone)]
pub struct Batch {
    events: Vec<CloudEvent>,
    limit: i64,
}

impl Batch {
    /// PHP `Batch::MEDIA_TYPE`.
    pub const MEDIA_TYPE: &'static str = MEDIA_TYPE;

    #[must_use]
    pub fn new(events: Vec<CloudEvent>, limit: i64) -> Self {
        Self { events, limit }
    }

    #[must_use]
    pub fn events(&self) -> &[CloudEvent] {
        &self.events
    }

    #[must_use]
    pub fn limit(&self) -> i64 {
        self.limit
    }

    /// PHP `count($batch)`.
    #[must_use]
    pub fn count(&self) -> usize {
        self.events.len()
    }

    #[must_use]
    pub fn is_empty(&self) -> bool {
        self.events.is_empty()
    }

    #[must_use]
    pub fn last_id(&self) -> Option<&str> {
        self.events.last().map(|event| event.id.as_str())
    }

    /// PHP `cacheControl(bool $public = false)`.
    #[must_use]
    pub fn cache_control(&self, public: bool) -> String {
        let count = self.events.len() as i64;
        if count < self.limit || count == 0 {
            return "no-store".into();
        }
        let vis = if public { "public, " } else { "private, " };
        format!("{vis}max-age=31536000")
    }

    /// PHP `toArray()` - a plain JSON array of `CloudEvents`, no envelope.
    #[must_use]
    pub fn to_array(&self) -> Vec<serde_json::Map<String, serde_json::Value>> {
        self.events.iter().map(CloudEvent::to_array).collect()
    }

    /// Wire JSON for an HTTP body (`[]` when empty).
    #[must_use]
    pub fn to_json_value(&self) -> serde_json::Value {
        serde_json::Value::Array(
            self.to_array()
                .into_iter()
                .map(serde_json::Value::Object)
                .collect(),
        )
    }
}

impl<'a> IntoIterator for &'a Batch {
    type Item = &'a CloudEvent;
    type IntoIter = std::slice::Iter<'a, CloudEvent>;
    fn into_iter(self) -> Self::IntoIter {
        self.events.iter()
    }
}

impl IntoIterator for Batch {
    type Item = CloudEvent;
    type IntoIter = std::vec::IntoIter<CloudEvent>;
    fn into_iter(self) -> Self::IntoIter {
        self.events.into_iter()
    }
}

impl std::ops::Deref for Batch {
    type Target = [CloudEvent];
    fn deref(&self) -> &Self::Target {
        &self.events
    }
}
