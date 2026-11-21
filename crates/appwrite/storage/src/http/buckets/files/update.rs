//! `PUT /v1/storage/buckets/:bucketId/files/:fileId`. PHP `Http/Buckets/Files/Update.php`.

use serde_json::Value as Json;
use utopia_database::{Document, PermissionType, Value};
use utopia_http::{Response, StatusCode};
use utopia_storage::validator::FileExt;
use utopia_validators::{Text, Validator};

use appwrite_core::database::documents::Attributes;
use appwrite_core::platform::Context;
use appwrite_core::validators::uid;
use appwrite_core::{Error, ErrorType, Result};

use crate::base::{
    self, aggregate, allows, assert_assignable, bucket_collection, file_permissions, list_value, permission_list,
    unauthorized,
};
use crate::filename::Filename;
use crate::model::FileModel;

pub async fn update(ctx: &mut Context) -> Result<Response> {
    let bucket_id = ctx.required_str("bucketId", &uid())?;
    let file_id = ctx.required_str("fileId", &uid())?;
    let name = match ctx.param("name") {
        None => None,
        Some(value) if value.is_null() => None,
        Some(_) => Some(ctx.required_str("name", &Filename(Text::new(128)))?),
    };
    let raw = ctx.param("permissions").cloned().unwrap_or(Json::Null);
    let db = ctx.db()?;
    let bucket = crate::http::visible_bucket(ctx, &db, &bucket_id).await?;
    let secured = bucket.boolean("fileSecurity").unwrap_or(false);
    let permitted = allows(ctx, &bucket, PermissionType::Update)?;
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
    let permissions = match permission_list(&raw)? {
        None => file.permissions().map_err(Error::from)?,
        Some(items) => {
            let permissions = aggregate(&items, file_permissions())?;
            assert_assignable(ctx, &permissions)?;
            permissions
        }
    };
    let mut updates = Document::default();
    updates.put("$permissions", list_value(&permissions));
    if let Some(name) = &name {
        let allowed = bucket.strings("allowedFileExtensions");
        if !allowed.is_empty()
            && *name != file.string_or_empty("name")
            && !FileExt::new(allowed).is_valid(&Json::String(name.clone()))
        {
            return Err(Error::with_message(ErrorType::StorageFileTypeUnsupported, "File extension not allowed"));
        }
        updates.put("name", Value::from(name.as_str()));
    }
    let file = if secured && !permitted {
        db.update_document(&collection, &file_id, updates).await.map_err(base::map_db)?
    } else {
        db.authorization().skip(db.update_document(&collection, &file_id, updates)).await.map_err(base::map_db)?
    };
    ctx.event_param("bucketId", bucket.id().to_owned());
    ctx.event_param("fileId", file.id().to_owned());
    Ok(ctx.json(StatusCode::OK, &FileModel { document: &file }))
}
