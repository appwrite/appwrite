//! `DELETE /v1/storage/buckets/:bucketId/files/:fileId`. PHP `Http/Buckets/Files/Delete.php`.

use utopia_database::{Document, PermissionType};
use utopia_http::Response;

use appwrite_core::database::documents::Attributes;
use appwrite_core::platform::Context;
use appwrite_core::validators::uid;
use appwrite_core::{Error, ErrorType, Result};

use crate::base::{self, allows, bucket_collection, unauthorized};
use crate::device::Stored;
use crate::model::FileModel;

use super::meta;

pub async fn delete(ctx: &mut Context) -> Result<Response> {
    let bucket_id = ctx.required_str("bucketId", &uid())?;
    let file_id = ctx.required_str("fileId", &uid())?;
    let db = ctx.db()?;
    let bucket = crate::http::visible_bucket(ctx, &db, &bucket_id).await?;
    let secured = bucket.boolean("fileSecurity").unwrap_or(false);
    let permitted = allows(ctx, &bucket, PermissionType::Delete)?;
    if !secured && !permitted {
        return Err(unauthorized(ctx));
    }
    let collection = bucket_collection(&bucket);
    let file = db
        .authorization()
        .skip(db.get_document(&collection, &file_id, Vec::new(), false))
        .await
        .map_err(base::map_db)?;
    if file.is_empty() {
        return Err(Error::new(ErrorType::StorageFileNotFound));
    }
    if secured && !permitted && !allows(ctx, &file, PermissionType::Delete)? {
        return Err(unauthorized(ctx));
    }
    let device = Stored::for_project(&ctx.project.id)?;
    let path = file.string_or_empty("path");
    let device_deleted = if !chunks_settled(&file) {
        device.abort(&path, &meta::upload_id(&file)).await.unwrap_or(true)
    } else {
        device.delete(&path).await?
    };
    if !device_deleted {
        return Err(Error::with_message(ErrorType::GeneralServerError, "Failed to delete file from device"));
    }
    let project = ctx.project.message_payload();
    ctx.events.deletes.push(base::cache_delete(&project, &file_id, bucket.id()));
    let deleted = if secured && !permitted {
        db.delete_document(&collection, &file_id).await.map_err(base::map_db)?
    } else {
        db.authorization().skip(db.delete_document(&collection, &file_id)).await.map_err(base::map_db)?
    };
    if !deleted {
        let exists = db
            .authorization()
            .skip(db.get_document(&collection, &file_id, Vec::new(), false))
            .await
            .map_err(base::map_db)?;
        if !exists.is_empty() {
            return Err(Error::with_message(ErrorType::GeneralServerError, "Failed to remove file from DB"));
        }
        return Err(Error::new(ErrorType::StorageFileNotFound));
    }
    ctx.event_param("bucketId", bucket.id().to_owned());
    ctx.event_param("fileId", file.id().to_owned());
    ctx.event_payload(&FileModel { document: &file });
    Ok(ctx.no_content())
}

fn chunks_settled(file: &Document) -> bool {
    match (file.get("chunksTotal"), file.get("chunksUploaded")) {
        (None, None) => true,
        (Some(total), Some(uploaded)) => {
            utopia_database::value::to_long(total) == utopia_database::value::to_long(uploaded)
        }
        _ => false,
    }
}
