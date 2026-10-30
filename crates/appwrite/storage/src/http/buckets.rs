//! Bucket actions.

use serde_json::{Value as Json, json};
use utopia_database::collection::Update;
use utopia_database::validator::permissions::PERMISSIONS;
use utopia_database::validator::query::cursor::Cursor as CursorValidator;
use utopia_database::{Database, Document, PermissionType, Query, Value};
use utopia_http::{Response, StatusCode};
use utopia_query::Method;
use utopia_validators::{ArrayList, Nullable, Range, Text, WhiteList};

use appwrite_core::database::LIMIT_COUNT;
use appwrite_core::database::collections;
use appwrite_core::database::documents::Attributes;
use appwrite_core::platform::Context;
use appwrite_core::validators::{CustomId, uid};
use appwrite_core::{Error, ErrorType, Result};

use crate::base::{self, aggregate, id_or_unique, list_value, long, permission_list, storage_limit, strings};
use crate::model::{BucketList, BucketModel};

const COMPRESSION: &[&str] = &["none", "gzip", "zstd"];

/// `POST /v1/storage/buckets`.
pub async fn create(ctx: &mut Context) -> Result<Response> {
    let bucket_id = id_or_unique(ctx.required_str("bucketId", &CustomId)?);
    let name = ctx.required_str("name", &Text::new(128))?;
    let permissions = bucket_permissions(ctx, None)?;
    let file_security = ctx.optional_bool("fileSecurity", false)?;
    let enabled = ctx.optional_bool("enabled", true)?;
    let limit = storage_limit(ctx);
    let maximum = optional_limit(ctx, "maximumFileSize", limit, None)?;
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

/// `GET /v1/storage/buckets`.
pub async fn list(ctx: &mut Context) -> Result<Response> {
    let mut queries = ctx.queries(&collections::BUCKETS)?;
    let search = ctx.optional_str("search", "", &Text::with_min(256, 0))?.unwrap_or_default();
    let total = ctx.optional_bool("total", true)?;
    if !search.is_empty() {
        queries.push(Query::search("search", &search));
    }
    let db = ctx.db()?;
    resolve_cursor(&db, "buckets", &mut queries, "Bucket").await?;
    let filters = Query::group_by_type(&queries)?.filters;
    let buckets = db.find("buckets", queries, PermissionType::Read).await?;
    let total = if total { db.count("buckets", filters, Some(LIMIT_COUNT)).await? } else { 0 };
    Ok(ctx.json(StatusCode::OK, &BucketList { total, buckets: &buckets }))
}

/// `GET /v1/storage/buckets/:bucketId`.
pub async fn get(ctx: &mut Context) -> Result<Response> {
    let bucket_id = ctx.required_str("bucketId", &uid())?;
    let db = ctx.db()?;
    let bucket = load(&db, &bucket_id).await?;
    Ok(ctx.json(StatusCode::OK, &BucketModel { document: &bucket }))
}

/// `PUT /v1/storage/buckets/:bucketId`.
pub async fn update(ctx: &mut Context) -> Result<Response> {
    let bucket_id = ctx.required_str("bucketId", &uid())?;
    let name = ctx.required_str("name", &Text::new(128))?;
    let db = ctx.db()?;
    let bucket = load(&db, &bucket_id).await?;
    let permissions = bucket_permissions(ctx, Some(&bucket))?;
    let file_security = ctx.optional_bool("fileSecurity", false)?;
    let enabled = ctx.optional_bool("enabled", true)?;
    let limit = storage_limit(ctx);
    let previous_limit = long(&bucket, "maximumFileSize");
    let maximum = optional_limit(ctx, "maximumFileSize", limit, Some(previous_limit))?;
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
    let encryption = nullable_bool(ctx, "encryption", true, bucket.boolean("encryption").unwrap_or(true))?;
    let antivirus = ctx.optional_bool("antivirus", true)?;
    let transformations = ctx.optional_bool("transformations", true)?;
    let previous = bucket.boolean("stripMetadata").unwrap_or(false);
    let strip_metadata = nullable_bool(ctx, "stripMetadata", previous, previous)?;
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

/// `DELETE /v1/storage/buckets/:bucketId`.
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

async fn load(db: &Database, id: &str) -> Result<Document> {
    let bucket = db.get_document("buckets", id, Vec::new(), false).await.map_err(base::map_db)?;
    if bucket.is_empty() {
        return Err(Error::new(ErrorType::StorageBucketNotFound));
    }
    Ok(bucket)
}

fn bucket_permissions(ctx: &Context, previous: Option<&Document>) -> Result<Vec<String>> {
    let raw = ctx.param("permissions").cloned().unwrap_or(Json::Null);
    match permission_list(&raw)? {
        None => match previous {
            Some(bucket) => bucket.permissions().map_err(Error::from),
            None => Ok(Vec::new()),
        },
        Some(items) => aggregate(&items, &PERMISSIONS),
    }
}

/// Omitted uses `fallback` (the plan or env limit). Null keeps `previous`.
fn optional_limit(ctx: &Context, key: &str, fallback: i64, previous: Option<i64>) -> Result<i64> {
    match ctx.param(key) {
        None => Ok(fallback),
        Some(value) if value.is_null() => Ok(previous.unwrap_or(fallback)),
        Some(_) => {
            let value = ctx.required(key, &Nullable(Range::new(1, fallback)))?;
            if value.is_null() {
                Ok(previous.unwrap_or(fallback))
            } else {
                Ok(Range::value(&value).unwrap_or(fallback))
            }
        }
    }
}

fn nullable_bool(ctx: &Context, key: &str, default: bool, previous: bool) -> Result<bool> {
    match ctx.param(key) {
        None => Ok(default),
        Some(value) if value.is_null() => Ok(previous),
        Some(_) => ctx.optional_bool(key, default),
    }
}

async fn resolve_cursor(db: &Database, collection: &str, queries: &mut [Query], label: &str) -> Result<()> {
    let Some(cursor) =
        queries.iter().position(|query| matches!(query.method(), Method::CursorAfter | Method::CursorBefore))
    else {
        return Ok(());
    };
    let validator = CursorValidator::default();
    if !validator.check(&queries[cursor]) {
        return Err(Error::with_message(ErrorType::GeneralQueryInvalid, validator.description()));
    }
    let id = queries[cursor]
        .value()
        .map(|value| utopia_database::value::to_text(&utopia_database::value::from_query(value)))
        .unwrap_or_default();
    let document = db.get_document(collection, &id, Vec::new(), false).await?;
    if document.is_empty() {
        return Err(Error::with_message(
            ErrorType::GeneralCursorNotFound,
            format!("{label} '{id}' for the 'cursor' value not found."),
        ));
    }
    queries[cursor].set_value(utopia_database::value::to_query(&Value::from(document)));
    Ok(())
}
