//! `PUT /v1/storage/buckets/:bucketId`. PHP `Http/Buckets/Update.php`.

use serde_json::json;
use utopia_database::collection::Update;
use utopia_database::{Document, Value};
use utopia_http::{Response, StatusCode};
use utopia_validators::{ArrayList, Nullable, Text, WhiteList};

use appwrite_core::Result;
use appwrite_core::database::documents::Attributes;
use appwrite_core::platform::Context;
use appwrite_core::validators::uid;

use crate::base::{self, list_value, long, storage_limit, strings};
use crate::model::BucketModel;

use super::base::{self as bucket, COMPRESSION};

pub async fn update(ctx: &mut Context) -> Result<Response> {
    let bucket_id = ctx.required_str("bucketId", &uid())?;
    let name = ctx.required_str("name", &Text::new(128))?;
    let db = ctx.db()?;
    let bucket = bucket::load(&db, &bucket_id).await?;
    let permissions = bucket::permissions(ctx, Some(&bucket))?;
    let file_security = ctx.optional_bool("fileSecurity", false)?;
    let enabled = ctx.optional_bool("enabled", true)?;
    let limit = storage_limit(ctx);
    let previous_limit = long(&bucket, "maximumFileSize");
    let maximum = bucket::optional_limit(ctx, "maximumFileSize", limit, Some(previous_limit))?;
    let extensions = strings(&ctx.optional(
        "allowedFileExtensions",
        json!([]),
        &ArrayList::new(Text::new(64), appwrite_core::validators::LIMIT_ARRAY_PARAMS_SIZE),
    )?);
    let compression = match ctx.param("compression") {
        None => "none".to_owned(),
        Some(value) if value.is_null() => {
            let previous = bucket.string_or_empty("compression");
            if previous.is_empty() { "none".to_owned() } else { previous }
        }
        Some(_) => ctx
            .optional_str("compression", "none", &Nullable(WhiteList::strict(COMPRESSION)))?
            .unwrap_or_else(|| "none".into()),
    };
    let encryption = bucket::nullable_bool(ctx, "encryption", true, bucket.boolean("encryption").unwrap_or(true))?;
    let antivirus = ctx.optional_bool("antivirus", true)?;
    let transformations = ctx.optional_bool("transformations", true)?;
    let previous = bucket.boolean("stripMetadata").unwrap_or(false);
    let strip_metadata = bucket::nullable_bool(ctx, "stripMetadata", previous, previous)?;
    let description = match ctx.param("description") {
        None => bucket.string_or_empty("description"),
        Some(value) if value.is_null() => bucket.string_or_empty("description"),
        Some(_) => ctx.required_str("description", &Nullable(Text::with_min(1024, 0)))?,
    };

    let mut updates = Document::default();
    updates.put("name", Value::from(name.as_str()));
    updates.put("description", Value::from(description.as_str()));
    updates.put("$permissions", list_value(&permissions));
    updates.put("maximumFileSize", Value::Int(maximum));
    updates.put("allowedFileExtensions", list_value(&extensions));
    updates.put("fileSecurity", Value::Bool(file_security));
    updates.put("enabled", Value::Bool(enabled));
    updates.put("encryption", Value::Bool(encryption));
    updates.put("compression", Value::from(compression.as_str()));
    updates.put("antivirus", Value::Bool(antivirus));
    updates.put("transformations", Value::Bool(transformations));
    updates.put("stripMetadata", Value::Bool(strip_metadata));
    updates.put("search", Value::from(format!("{bucket_id} {name} {description}").as_str()));
    let bucket = db.update_document("buckets", bucket.id(), updates).await.map_err(base::map_db)?;
    db.update_collection(
        &base::bucket_collection(&bucket),
        Update { permissions: Some(permissions), document_security: Some(file_security) },
    )
    .await
    .map_err(base::map_db)?;

    ctx.event_param("bucketId", bucket.id().to_owned());
    Ok(ctx.json(StatusCode::OK, &BucketModel { document: &bucket }))
}
