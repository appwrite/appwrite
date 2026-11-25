//! Which buckets may hold an unfinished chunked upload.
//! PHP `src/Appwrite/Storage/Uploads.php`.

use redis::AsyncCommands;
use redis::aio::ConnectionManager;

/// How long a chunked upload may sit idle before maintenance aborts it, in
/// seconds (seven days).
///
/// PHP: `APP_STORAGE_UPLOAD_RETENTION`.
pub const RETENTION: i64 = 60 * 60 * 24 * 7;

/// Remembers which buckets of a project may hold an unfinished chunked
/// upload, so maintenance can skip the others. Redis may lose the record, so
/// a full scan still runs once per retention period.
///
/// PHP: `Appwrite\Storage\Uploads`, over the `lock` pool.
#[derive(Clone)]
pub struct Uploads {
    redis: ConnectionManager,
}

impl Uploads {
    /// The record in `redis`.
    pub fn new(redis: ConnectionManager) -> Self {
        Uploads { redis }
    }

    /// Records that `bucket_id` of `project_id` may hold an unfinished upload.
    ///
    /// PHP: `track($projectId, $bucketId)`.
    pub async fn track(&self, project_id: &str, bucket_id: &str) -> redis::RedisResult<()> {
        let key = pending_key(project_id);
        // Outlives any upload that could still expire; a project that stops uploading lets it lapse.
        redis::pipe()
            .sadd(&key, bucket_id)
            .ignore()
            .expire(&key, RETENTION * 2)
            .ignore()
            .query_async(&mut self.redis.clone())
            .await
    }

    /// The buckets of `project_id` that may hold an unfinished upload.
    ///
    /// PHP: `buckets($projectId)`.
    pub async fn buckets(&self, project_id: &str) -> redis::RedisResult<Vec<String>> {
        self.redis.clone().smembers(pending_key(project_id)).await
    }

    /// Forgets `bucket_id` of `project_id`.
    ///
    /// PHP: `forget($projectId, $bucketId)`.
    pub async fn forget(&self, project_id: &str, bucket_id: &str) -> redis::RedisResult<()> {
        self.redis.clone().srem(pending_key(project_id), bucket_id).await
    }

    /// Whether this run should scan every bucket of `project_id`. Only one run
    /// per retention period gets `true`, and a lost key only brings the next
    /// scan forward.
    ///
    /// PHP: `claimScan($projectId)`.
    pub async fn claim_scan(&self, project_id: &str) -> redis::RedisResult<bool> {
        let key = format!("uploads:scanned:{project_id}");
        // Spreads the scans of projects that started together over a day.
        let ttl = RETENTION + rand::random_range(0..=86_400_i64);
        let reply: redis::Value = redis::cmd("SET")
            .arg(&key)
            .arg("1")
            .arg("NX")
            .arg("EX")
            .arg(ttl)
            .query_async(&mut self.redis.clone())
            .await?;
        Ok(matches!(reply, redis::Value::Okay))
    }
}

fn pending_key(project_id: &str) -> String {
    format!("uploads:pending:{project_id}")
}
