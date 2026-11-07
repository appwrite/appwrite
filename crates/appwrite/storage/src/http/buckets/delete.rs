//! `DELETE /v1/storage/buckets/:bucketId`. PHP `Http/Buckets/Delete.php`.

use utopia_http::Response;

use appwrite_core::platform::Context;
use appwrite_core::validators::uid;
use appwrite_core::{Error, ErrorType, Result};

use crate::base;
use crate::model::BucketModel;

use super::base::load;

pub async fn delete(ctx: &mut Context) -> Result<Response> {
    let bucket_id = ctx.required_str("bucketId", &uid())?;
    let db = ctx.db()?;
    let bucket = load(&db, &bucket_id).await?;
    if !db.delete_document("buckets", &bucket_id).await.map_err(base::map_db)? {
        return Err(Error::with_message(ErrorType::GeneralServerError, "Failed to remove bucket from DB"));
    }
    let project = ctx.project.message_payload();
    let document = base::document_json(&bucket);
    ctx.events.deletes.push(appwrite_core::event::delete_message(&project, "document", document));
    ctx.event_param("bucketId", bucket.id().to_owned());
    ctx.event_payload(&BucketModel { document: &bucket });
    Ok(ctx.no_content())
}
