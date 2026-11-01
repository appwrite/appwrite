//! Shared steps of the Storage module.

use serde_json::{Value as Json, json};
use utopia_database::attribute::IntegerWidth;
use utopia_database::filter::Filter;
use utopia_database::validator::authorization::Input;
use utopia_database::{Attribute, Collection, Document, Index, Kind, Permission, PermissionType, Role, Value};
use utopia_query::OrderDirection;

use appwrite_core::database::documents::{self, Attributes};
use appwrite_core::platform::Context;
use appwrite_core::validators::LIMIT_ARRAY_PARAMS_SIZE;
use appwrite_core::{Error, ErrorType, Result};

use crate::device::Stored;

/// `APP_STORAGE_READ_BUFFER`: 20 * 1000 * 1000, not 20 MiB.
pub const READ_BUFFER: i64 = 20_000_000;
/// `APP_LIMIT_ANTIVIRUS`.
pub const ANTIVIRUS_LIMIT: i64 = 20_000_000;
/// `APP_LIMIT_UPLOAD_CHUNK_SIZE`.
pub const CHUNK_SIZE: i64 = 5 * 1024 * 1024;
/// `MAX_OUTPUT_CHUNK_SIZE`.
pub const OUTPUT_CHUNK: i64 = 10 * 1024 * 1024;
/// Download and view `Cache-Control` max-age (45 days).
pub const DOWNLOAD_MAX_AGE: i64 = 3_888_000;
/// Preview action `Cache-Control` max-age (30 days).
pub const PREVIEW_MAX_AGE: i64 = 2_592_000;
/// `APP_PROJECT_ACCESS`: how long a preview counts as fresh.
pub const PROJECT_ACCESS: i64 = 24 * 60 * 60;

const FILE_PERMISSIONS: [PermissionType; 3] = [PermissionType::Read, PermissionType::Update, PermissionType::Delete];

/// `_APP_STORAGE_LIMIT`, or the plan's `fileSize` in megabytes.
pub fn storage_limit(ctx: &Context) -> i64 {
    if let Some(plan) = ctx.state.hooks.plan(&ctx.project)
        && let Some(size) = plan.get("fileSize").and_then(Json::as_i64)
        && size != 0
    {
        return size.saturating_mul(1_000_000);
    }
    appwrite_core::config::env_int("_APP_STORAGE_LIMIT", 0)
}

pub fn user_id(ctx: &Context) -> String {
    ctx.user.as_ref().map(|user| user.id.clone()).unwrap_or_default()
}

pub fn id_or_unique(id: String) -> String {
    if id == "unique()" { appwrite_core::database::unique_id() } else { id }
}

pub fn bucket_collection(bucket: &Document) -> String {
    format!("bucket_{}", documents::sequence_text(bucket))
}

pub fn long(document: &Document, key: &str) -> i64 {
    document.get(key).map(utopia_database::value::to_long).unwrap_or(0)
}

/// A disabled bucket is not found, unless the caller is a key or a privileged user.
pub fn bucket_visible(ctx: &Context, bucket: &Document) -> bool {
    !bucket.is_empty() && (bucket.boolean("enabled") == Some(true) || ctx.is_key() || ctx.is_privileged())
}

pub fn allows(ctx: &Context, document: &Document, kind: PermissionType) -> Result<bool> {
    let permissions = document.permissions_by_type(kind.as_str()).map_err(Error::from)?;
    Ok(ctx.authorization.is_valid(&Input::new(kind, permissions)))
}

pub fn unauthorized(ctx: &Context) -> Error {
    Error::with_message(ErrorType::UserUnauthorized, ctx.authorization.description())
}

/// `Permission::aggregate`, or `general_argument_invalid` when a string does not parse.
pub fn aggregate(permissions: &[String], allowed: &[PermissionType]) -> Result<Vec<String>> {
    Permission::aggregate(permissions, allowed).map_err(|e| Error::invalid_param("permissions", e.message()))
}

pub fn file_permissions() -> &'static [PermissionType] {
    &FILE_PERMISSIONS
}

/// Permissions of the current user when a create omits them. Guests and
/// privileged users get none.
pub fn default_file_permissions(ctx: &Context) -> Vec<String> {
    let id = user_id(ctx);
    if id.is_empty() || ctx.is_privileged() {
        return Vec::new();
    }
    let role = Role::user(id, "");
    vec![Permission::read(&role), Permission::update(&role), Permission::delete(&role)]
}

/// Clients may only grant roles they hold. Keys and privileged users may grant any.
pub fn assert_assignable(ctx: &Context, permissions: &[String]) -> Result<()> {
    if ctx.is_key() || ctx.is_privileged() {
        return Ok(());
    }
    for permission in permissions {
        let parsed = Permission::parse(permission).map_err(|e| Error::invalid_param("permissions", e.message()))?;
        if !ctx.authorization.has_role(&parsed.role().to_string()) {
            return Err(Error::with_message(
                ErrorType::UserUnauthorized,
                format!("Permissions must be one of: ({})", ctx.roles.join(", ")),
            ));
        }
    }
    Ok(())
}

pub fn permission_list(value: &Json) -> Result<Option<Vec<String>>> {
    if value.is_null() {
        return Ok(None);
    }
    let Some(items) = value.as_array() else {
        return Err(Error::invalid_param("permissions", "Value must be a valid array"));
    };
    if items.len() > LIMIT_ARRAY_PARAMS_SIZE {
        return Err(Error::invalid_param(
            "permissions",
            &format!("Value must be a valid array no longer than {LIMIT_ARRAY_PARAMS_SIZE} items"),
        ));
    }
    let mut permissions = Vec::with_capacity(items.len());
    for item in items {
        let Some(text) = item.as_str() else {
            return Err(Error::invalid_param("permissions", "Value must be a valid string"));
        };
        permissions.push(text.to_owned());
    }
    Ok(Some(permissions))
}

pub fn strings(value: &Json) -> Vec<String> {
    value.as_array().into_iter().flatten().filter_map(Json::as_str).map(str::to_owned).collect()
}

pub fn map_db(err: utopia_database::Error) -> Error {
    if err.is_a(Kind::NotFound) { Error::new(ErrorType::StorageBucketNotFound) } else { Error::from(err) }
}

pub fn map_duplicate(err: utopia_database::Error, kind: ErrorType) -> Error {
    if err.is_a(Kind::Duplicate) { Error::new(kind) } else { map_db(err) }
}

pub fn list_value(items: &[String]) -> Value {
    utopia_database::value::list(items.iter().map(|item| Value::from(item.as_str())))
}

/// The per-bucket files collection (`collections['buckets']['files']`).
pub fn files_collection(id: &str, permissions: Vec<String>, document_security: bool) -> Collection {
    let key_len = utopia_database::database::LENGTH_KEY;
    let string = |key, size| Attribute::string(key, size).required(false);
    let count = |key| Attribute::integer(key).required(false).signed(false);
    let attributes = vec![
        string("bucketId", key_len),
        Attribute::string("bucketInternalId", key_len).required(true),
        string("name", 2048),
        string("path", 2048),
        string("folder", 2048).default(""),
        string("signature", 2048),
        string("mimeType", key_len),
        string("metadata", 75000).filters([Filter::Json]),
        Attribute::integer("sizeOriginal").required(false).signed(false).width(IntegerWidth::Bits64),
        Attribute::integer("sizeActual").required(false).signed(false).width(IntegerWidth::Bits64),
        string("algorithm", key_len),
        string("comment", 2048),
        string("openSSLVersion", 64),
        string("openSSLCipher", 64),
        string("openSSLTag", 2048),
        string("openSSLIV", 2048),
        count("chunksTotal"),
        count("chunksUploaded"),
        Attribute::datetime("transformedAt").required(false),
        string("search", 16384),
    ];
    let key = |name, attributes, lengths, orders| {
        Index::key(name, attributes, lengths, orders).expect("files collection index")
    };
    let asc = vec![Some(OrderDirection::Asc)];
    let indexes = vec![
        Index::fulltext("_key_search", ["search"]),
        key("_key_bucket", ["bucketId"], vec![Some(utopia_database::database::LENGTH_KEY)], asc.clone()),
        key("_key_name", ["name"], vec![Some(256)], asc.clone()),
        key("_key_folder", ["folder"], vec![Some(256)], asc.clone()),
        key("_key_signature", ["signature"], vec![Some(256)], asc.clone()),
        key("_key_mimeType", ["mimeType"], Vec::new(), asc.clone()),
        key("_key_sizeOriginal", ["sizeOriginal"], Vec::new(), asc.clone()),
        key("_key_chunksTotal", ["chunksTotal"], Vec::new(), asc.clone()),
        key("_key_chunksUploaded", ["chunksUploaded"], Vec::new(), asc),
        Index::key("_key_transformedAt", ["transformedAt"], Vec::new(), Vec::new()).expect("transformedAt index"),
    ];
    Collection::create(id, "Files", attributes, indexes, Some(permissions), document_security, Default::default())
        .expect("files collection")
}

pub fn device_error(err: utopia_storage::Error) -> Error {
    Error::with_message(ErrorType::GeneralServerError, err.to_string())
}

pub async fn read_all(device: &Stored, path: &str) -> Result<Vec<u8>> {
    let body = device.read(path, 0, None).await?;
    let bytes = body.bytes().await.map_err(|e| Error::with_message(ErrorType::GeneralServerError, e.to_string()))?;
    Ok(bytes.to_vec())
}

pub fn extension(name: &str) -> &str {
    match name.rsplit_once('.') {
        Some((_, ext)) if !ext.is_empty() && !name.ends_with('.') => ext,
        _ => "",
    }
}

/// `$queueForEvents->reset()`.
pub fn reset_events(ctx: &mut Context) {
    ctx.events.event = Some(String::new());
}

pub fn document_json(document: &Document) -> Json {
    Value::from(document.clone()).try_to_json().unwrap_or(Json::Null)
}

pub fn cache_delete(project: &Json, file_id: &str, bucket_id: &str) -> Json {
    json!({
        "project": project,
        "type": "cacheByResource",
        "document": null,
        "resource": format!("file/{file_id}"),
        "resourceType": format!("bucket/{bucket_id}"),
        "datetime": null,
    })
}

/// A token grants this bucket when its internal id matches the bucket sequence.
pub fn token_for_bucket(ctx: &Context, bucket: &Document) -> bool {
    ctx.resource_token.as_ref().is_some_and(|token| token.bucket_internal_id == documents::sequence_text(bucket))
}

pub fn token_matches_file(ctx: &Context, file: &Document) -> Result<()> {
    if ctx.resource_token.as_ref().is_some_and(|token| token.file_internal_id != documents::sequence_text(file)) {
        return Err(Error::new(ErrorType::UserUnauthorized));
    }
    Ok(())
}
