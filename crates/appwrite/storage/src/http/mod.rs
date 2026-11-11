//! Storage HTTP actions.
//!
//! One file per PHP action under `Http/Buckets`.

pub mod buckets;

use utopia_database::{Database, Document};

use appwrite_core::platform::Context;
use appwrite_core::{Error, ErrorType, Result};

use crate::base::{self, bucket_visible};

/// The bucket, read with authorization skipped the way file actions load it.
/// A missing or disabled bucket is `storage_bucket_not_found`.
pub async fn visible_bucket(ctx: &Context, db: &Database, id: &str) -> Result<Document> {
    let bucket =
        db.authorization().skip(db.get_document("buckets", id, Vec::new(), false)).await.map_err(base::map_db)?;
    if !bucket_visible(ctx, &bucket) {
        return Err(Error::new(ErrorType::StorageBucketNotFound));
    }
    Ok(bucket)
}
