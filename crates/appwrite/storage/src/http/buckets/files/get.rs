//! `GET /v1/storage/buckets/:bucketId/files/:fileId`. PHP `Http/Buckets/Files/Get.php`.

use utopia_database::{Database, Document, PermissionType};
use utopia_http::{Response, StatusCode};

use appwrite_core::database::documents::Attributes;
use appwrite_core::platform::Context;
use appwrite_core::validators::uid;
use appwrite_core::{Error, ErrorType, Result};

use crate::base::{self, allows, bucket_collection, unauthorized};
use crate::model::FileModel;

pub async fn get(ctx: &mut Context) -> Result<Response> {
    let bucket_id = ctx.required_str("bucketId", &uid())?;
    let file_id = ctx.required_str("fileId", &uid())?;
    let db = ctx.db()?;
    let bucket = crate::http::visible_bucket(ctx, &db, &bucket_id).await?;
    let file = readable_file(ctx, &db, &bucket, &file_id, PermissionType::Read).await?;
    Ok(ctx.json(StatusCode::OK, &FileModel { document: &file }))
}

async fn readable_file(
    ctx: &Context,
    db: &Database,
    bucket: &Document,
    file_id: &str,
    kind: PermissionType,
) -> Result<Document> {
    let secured = bucket.boolean("fileSecurity").unwrap_or(false);
    let permitted = allows(ctx, bucket, kind)?;
    if !secured && !permitted {
        return Err(unauthorized(ctx));
    }
    let collection = bucket_collection(bucket);
    let file = if secured && !permitted {
        db.get_document(&collection, file_id, Vec::new(), false).await.map_err(base::map_db)?
    } else {
        db.authorization().skip(db.get_document(&collection, file_id, Vec::new(), false)).await.map_err(base::map_db)?
    };
    if file.is_empty() {
        return Err(Error::new(ErrorType::StorageFileNotFound));
    }
    Ok(file)
}
