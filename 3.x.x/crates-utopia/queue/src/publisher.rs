use serde_json::Value;

use crate::error::QueueError;
use crate::queue::Queue;

/// Enqueue, retry failed jobs, and report queue depth.
///
/// PHP `Utopia\Queue\Publisher`. `retry` / `reap` extra arguments match
/// `Broker\Redis` (the interface only documents `$limit`).
pub trait Publisher: Send + Sync {
    fn enqueue(&self, queue: &Queue, payload: Value, priority: bool) -> Result<bool, QueueError>;

    fn retry(
        &self,
        queue: &Queue,
        limit: Option<i64>,
        max_attempts: Option<i64>,
        newer_than: Option<i64>,
    ) -> Result<(), QueueError>;

    fn get_queue_size(&self, queue: &Queue, failed_jobs: bool) -> Result<i64, QueueError>;

    /// Reclaim stranded processing claims. Default is a no-op (NATS `reap()`).
    fn reap(
        &self,
        _queue: &Queue,
        _older_than: i64,
        _limit: Option<i64>,
        _max_attempts: Option<i64>,
        _newer_than: Option<i64>,
    ) -> Result<i64, QueueError> {
        Ok(0)
    }
}
