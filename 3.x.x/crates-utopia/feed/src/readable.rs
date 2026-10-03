use utopia_cloudevents::CloudEvent;

use crate::FeedError;

/// PHP `Utopia\Feed\Readable::TIP`.
pub const TIP: &str = "$";
/// PHP `Utopia\Feed\Readable::MEDIA_TYPE`.
pub const MEDIA_TYPE: &str = "application/cloudevents-batch+json";
/// PHP `Utopia\Feed\Readable::MAX_BATCH`.
pub const MAX_BATCH: i64 = 1000;
/// PHP `Utopia\Feed\Readable::MAX_TIMEOUT`.
pub const MAX_TIMEOUT: i64 = 30_000;

/// PHP `Utopia\Feed\Readable`.
pub trait Readable: Send + Sync {
    fn get_name(&self) -> &str;
    fn read(&self, last_event_id: Option<&str>, limit: i64) -> Result<Vec<CloudEvent>, FeedError>;
    fn poll(
        &self,
        last_event_id: Option<&str>,
        limit: i64,
        timeout: i64,
    ) -> Result<Vec<CloudEvent>, FeedError>;
    fn tip(&self) -> Result<Option<String>, FeedError>;

    /// PHP `instanceof Store` (local stores mint `{ms}-{seq}` ids).
    fn is_store(&self) -> bool {
        false
    }
}

/// PHP `Utopia\Feed\Appendable`.
pub trait Appendable: Send + Sync {
    fn append(&self, event: CloudEvent) -> Result<String, FeedError>;
}

/// PHP abstract `Utopia\Feed\Store` (Readable + Appendable, owns ids).
pub trait Store: Readable + Appendable {}
