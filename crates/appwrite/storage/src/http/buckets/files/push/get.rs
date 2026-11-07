//! `GET /v1/storage/buckets/:bucketId/files/:fileId/push`.
//! PHP `Http/Buckets/Files/Push/Get.php`.

use serde_json::Value as Json;
use utopia_http::Response;
use utopia_validators::Text;

use appwrite_core::database::documents::Attributes;
use appwrite_core::platform::Context;
use appwrite_core::validators::uid;
use appwrite_core::{Error, ErrorType, Result};

use crate::base::{self, READ_BUFFER};
use crate::device::Stored;

use super::super::send::{MIMES, incomplete, serve};

pub async fn get(ctx: &mut Context) -> Result<Response> {
    let bucket_id = ctx.required_str("bucketId", &uid())?;
    let file_id = ctx.required_str("fileId", &uid())?;
    let jwt = ctx.optional_str("jwt", "", &Text::with_min(2048, 0))?.unwrap_or_default();
    let claims = appwrite_core::auth::jwt::decode(&ctx.state.config.openssl_key_v1, &jwt, 3600)
        .map_err(|_| Error::new(ErrorType::UserUnauthorized))?;
    if claim(&claims, "projectId") != ctx.project.id
        || claim(&claims, "bucketId") != bucket_id
        || claim(&claims, "fileId") != file_id
    {
        return Err(Error::new(ErrorType::UserUnauthorized));
    }
    let mut disposition = if claim(&claims, "disposition") == "attachment" { "attachment" } else { "inline" };
    let internal = claim_truthy(claims.get("internal"));
    let db = if internal { ctx.platform_db()? } else { ctx.db()? };
    let bucket = crate::http::visible_bucket(ctx, &db, &bucket_id).await?;
    let collection = base::bucket_collection(&bucket);
    let file = db
        .authorization()
        .skip(db.get_document(&collection, &file_id, Vec::new(), false))
        .await
        .map_err(base::map_db)?;
    if incomplete(&file) {
        return Err(Error::new(ErrorType::StorageFileNotFound));
    }
    let device = Stored::for_project(&ctx.project.id)?;
    let path = file.string_or_empty("path");
    if !device.exists(&path).await? {
        return Err(Error::with_message(ErrorType::StorageFileNotFound, format!("File not found in {path}")));
    }
    let mime = file.string_or_empty("mimeType");
    let content_type = if MIMES.contains(&mime.as_str()) { mime } else { "text/plain".to_owned() };
    if content_type == "image/svg+xml" {
        disposition = "attachment";
    }
    let response =
        serve(ctx, &device, &file, &content_type, disposition, "script-src 'none';", true, READ_BUFFER).await?;
    Ok(response)
}

fn claim(claims: &serde_json::Map<String, Json>, key: &str) -> String {
    match claims.get(key) {
        Some(Json::String(value)) => value.clone(),
        Some(Json::Number(value)) => value.to_string(),
        Some(Json::Bool(value)) => value.to_string(),
        _ => String::new(),
    }
}

fn claim_truthy(value: Option<&Json>) -> bool {
    match value {
        Some(Json::Bool(value)) => *value,
        Some(Json::Number(value)) => value.as_i64() != Some(0) && value.as_f64() != Some(0.0),
        Some(Json::String(value)) => !value.is_empty() && value != "0",
        _ => false,
    }
}
