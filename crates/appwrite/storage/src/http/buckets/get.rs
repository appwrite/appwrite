//! `GET /v1/storage/buckets/:bucketId`. PHP `Http/Buckets/Get.php`.

use utopia_http::{Response, StatusCode};

use appwrite_core::Result;
use appwrite_core::platform::Context;
use appwrite_core::validators::uid;

use crate::model::BucketModel;

use super::base;

pub async fn get(ctx: &mut Context) -> Result<Response> {
    let bucket_id = ctx.required_str("bucketId", &uid())?;
    let db = ctx.db()?;
    let bucket = base::load(&db, &bucket_id).await?;
    Ok(ctx.json(StatusCode::OK, &BucketModel { document: &bucket }))
}
