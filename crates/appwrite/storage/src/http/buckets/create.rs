//! `POST /v1/storage/buckets`. PHP `Http/Buckets/Create.php`.

use serde_json::json;
use utopia_database::{Document, Value};
use utopia_http::Response;
use utopia_validators::{ArrayList, Text, WhiteList};

use appwrite_core::platform::Context;
use appwrite_core::validators::CustomId;
use appwrite_core::{ErrorType, Result};

use crate::base::{self, id_or_unique, list_value, storage_limit, strings};
use crate::model::BucketModel;

use super::base::{self as bucket, COMPRESSION};

pub async fn create(ctx: &mut Context) -> Result<Response> {
    let bucket_id = id_or_unique(ctx.required_str("bucketId", &CustomId)?);
    let name = ctx.required_str("name", &Text::new(128))?;
    let permissions = bucket::permissions(ctx, None)?;
    let file_security = ctx.optional_bool("fileSecurity", false)?;
    let enabled = ctx.optional_bool("enabled", true)?;
    let limit = storage_limit(ctx);
    let maximum = bucket::optional_limit(ctx, "maximumFileSize", limit, None)?;
    let extensions = strings(&ctx.optional(
        "allowedFileExtensions",
        json!([]),
        &ArrayList::new(Text::new(64), appwrite_core::validators::LIMIT_ARRAY_PARAMS_SIZE),
    )?);
    let compression =
        ctx.optional_str("compression", "none", &WhiteList::strict(COMPRESSION))?.unwrap_or_else(|| "none".into());
    let encryption = ctx.optional_bool("encryption", true)?;
    let antivirus = ctx.optional_bool("antivirus", true)?;
    let transformations = ctx.optional_bool("transformations", true)?;
    let strip_metadata = ctx.optional_bool("stripMetadata", false)?;
    let description = ctx.optional_str("description", "", &Text::with_min(1024, 0))?.unwrap_or_default();

    let db = ctx.db()?;
    let mut document = Document::default();
    document.put("$id", Value::from(bucket_id.as_str()));
    document.put("$collection", Value::from("buckets"));
    document.put("$permissions", list_value(&permissions));
    document.put("name", Value::from(name.as_str()));
    document.put("description", Value::from(description.as_str()));
    document.put("maximumFileSize", Value::Int(maximum));
    document.put("allowedFileExtensions", list_value(&extensions));
    document.put("fileSecurity", Value::Bool(file_security));
    document.put("enabled", Value::Bool(enabled));
    document.put("compression", Value::from(compression.as_str()));
    document.put("encryption", Value::Bool(encryption));
    document.put("antivirus", Value::Bool(antivirus));
    document.put("transformations", Value::Bool(transformations));
    document.put("stripMetadata", Value::Bool(strip_metadata));
    document.put("search", Value::from(format!("{bucket_id} {name} {description}").as_str()));

    let created = db
        .create_document("buckets", document)
        .await
        .map_err(|e| base::map_duplicate(e, ErrorType::StorageBucketAlreadyExists))?;
    let bucket = db.get_document("buckets", created.id(), Vec::new(), false).await.map_err(base::map_db)?;
    let files = base::files_collection(&base::bucket_collection(&bucket), permissions, file_security);
    if let Err(err) = db.create_collection(files).await {
        let _ = db.delete_document("buckets", bucket.id()).await;
        return Err(base::map_duplicate(err, ErrorType::StorageBucketAlreadyExists));
    }

    ctx.event_param("bucketId", bucket.id().to_owned());
    Ok(ctx.created(&BucketModel { document: &bucket }))
}
