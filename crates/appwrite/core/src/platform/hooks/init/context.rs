//! Events, audit context and response cache (PHP:
//! `app/controllers/shared/api.php`, the `api` group's init hook that sets
//! the events queue and the audit context and reads the `cache` label;
//! hooks.json `context`). The events and audit context are read from the
//! route by the shutdown hooks; this module holds the cache read.

use http::StatusCode;
use serde_json::Value;
use utopia_database::validator::authorization::Input;
use utopia_database::{Document, PermissionType};
use utopia_http::Response;

use crate::auth::roles;
use crate::database::documents::Attributes;
use crate::error::{Error, ErrorType};
use crate::platform::context::Context;
use crate::platform::hooks::resources::resource_token::resource_token;
use crate::platform::hooks::shutdown::cache_write;
use crate::{Result, datetime};

/// How long a cached response is served (180 days).
const CACHE_TTL: i64 = 60 * 60 * 24 * 180;
/// `APP_CACHE_UPDATE`: a hit refreshes the cache entry this often.
const CACHE_UPDATE: i64 = 24 * 60 * 60;
/// `APP_PROJECT_ACCESS`: a file's `transformedAt` is refreshed this often.
const PROJECT_ACCESS: i64 = 24 * 60 * 60;
/// The image preview route, whose cache the plan's image transformations
/// switch also governs.
const PREVIEW: &str = "/v1/storage/buckets/:bucketId/files/:fileId/preview";

/// The `api` group's context hook, after the events and audit context
/// (which the shutdown reads from the route): resolves the resource token,
/// and for routes labelled `cache` serves a stored response (`hit`, after
/// the bucket, file and token checks) or marks a `miss` for the action to
/// fill.
pub(in crate::platform) async fn context(ctx: &mut Context) -> Result<()> {
    let token = resource_token(ctx).await?;
    if !ctx.route.labels.cache {
        return Ok(());
    }
    let roles = ctx.roles.clone();
    let key_user = roles::is_key(&roles);
    let privileged = roles::is_privileged(&roles);
    let preview = ctx.route.path == PREVIEW;
    // `$plan['imageTransformations'] === -1`.
    let disabled = ctx.state.hooks.plan(&ctx.project).is_some_and(|plan| {
        plan.get("imageTransformations").and_then(Value::as_i64) == Some(-1)
            && plan.get("imageTransformations").is_some_and(Value::is_i64)
    }) && !privileged;

    let key = cache_write::identifier(
        ctx.request.uri(),
        ctx.request.params(),
        ctx.route.labels.cache_params,
        ctx.request.header_or_empty("x-appwrite-project"),
    );
    let db = ctx.db()?;
    let authorization = db.authorization();
    let log = authorization.skip(db.get_document("cache", &key, Vec::new(), false)).await?;
    let root = ctx.state.config.storage_cache.join(format!("app-{}", ctx.project.id));
    let cache = utopia_cache::Cache::new(utopia_cache::Filesystem::new(root));
    let data = cache
        .load(&key, CACHE_TTL, "")
        .await
        .map_err(|e| Error::with_message(ErrorType::GeneralUnknown, e.to_string()))?;
    // `!empty($data)`.
    let data = match data {
        Some(php_std::Value::Str(s)) if !s.as_bytes().is_empty() && s.as_bytes() != b"0" => Some(s.as_bytes().to_vec()),
        _ => None,
    };
    let (Some(data), false) = (data, log.is_empty()) else {
        ctx.response_headers.push(("cache-control", "no-cache, no-store, must-revalidate".to_owned()));
        ctx.response_headers.push(("pragma", "no-cache".to_owned()));
        ctx.response_headers.push(("expires", "0".to_owned()));
        ctx.response_headers.push(("x-appwrite-cache", "miss".to_owned()));
        return Ok(());
    };

    let mut cache_control = format!("private, max-age={CACHE_TTL}");
    let resource_type = log.string_or_empty("resourceType");
    let mut parts = resource_type.split('/');
    if parts.next() == Some("bucket") && (!preview || !disabled) {
        let bucket_id = parts.next().unwrap_or("");
        let bucket = authorization.skip(db.get_document("buckets", bucket_id, Vec::new(), false)).await?;
        let bucket_sequence = crate::database::documents::sequence_text(&bucket);
        let is_token = token.as_ref().is_some_and(|t| t.bucket_internal_id == bucket_sequence);
        if bucket.is_empty() || (bucket.boolean("enabled") != Some(true) && !key_user && !privileged) {
            return Err(Error::new(ErrorType::StorageBucketNotFound));
        }
        if bucket.boolean("transformations") == Some(false) && !key_user && !privileged {
            return Err(Error::new(ErrorType::StorageBucketTransformationsDisabled));
        }
        let file_security = bucket.boolean("fileSecurity").unwrap_or(false);
        let read = bucket.permissions_by_type("read").unwrap_or_default();
        let valid = ctx.authorization.is_valid(&Input::new(PermissionType::Read, read));
        if !file_security && !valid && !is_token {
            return Err(Error::new(ErrorType::UserUnauthorized));
        }
        let resource = log.string_or_empty("resource");
        let file_id = resource.split('/').nth(1).unwrap_or("");
        let collection = format!("bucket_{bucket_sequence}");
        let file = if file_security && !valid && !is_token {
            db.get_document(&collection, file_id, Vec::new(), false).await?
        } else {
            authorization.skip(db.get_document(&collection, file_id, Vec::new(), false)).await?
        };
        let file_sequence = crate::database::documents::sequence_text(&file);
        if token.as_ref().is_some_and(|t| t.file_internal_id != file_sequence) {
            return Err(Error::new(ErrorType::UserUnauthorized));
        }
        if file.is_empty() {
            return Err(Error::new(ErrorType::StorageFileNotFound));
        }
        // A console user's view does not count as a transformation.
        if !privileged {
            let now = datetime::now();
            let stale =
                file.datetime("transformedAt").is_none_or(|at| at < datetime::add_seconds(now, -PROJECT_ACCESS));
            if stale {
                let mut updates = Document::default();
                updates.put("transformedAt", datetime::value(&now));
                let collection = format!("bucket_{}", file.string_or_empty("bucketInternalId"));
                authorization.skip(db.update_document(&collection, file.id(), updates)).await?;
            }
        }
        // `cacheControlForStorage` (CE: the same private max-age).
        if preview {
            cache_control = format!("private, max-age={CACHE_TTL}");
        }
    }

    let now = datetime::now();
    if log.datetime("accessedAt").is_none_or(|at| at < datetime::add_seconds(now, -CACHE_UPDATE)) {
        let mut updates = Document::default();
        updates.put("accessedAt", datetime::value(&now));
        authorization.skip(db.update_document("cache", log.id(), updates)).await?;
        // Refreshes the file's time, which the TTL reads.
        let saved = cache.save(&key, &php_std::Value::Str(php_std::Str::from(data.clone())), "", 0).await;
        saved.map_err(|e| Error::with_message(ErrorType::GeneralUnknown, e.to_string()))?;
    }

    let mime = log.string_or_empty("mimeType");
    if !preview || !disabled {
        let mut response = Response::with_body(StatusCode::OK, &mime, data);
        response.add("cache-control", &cache_control);
        response.add("x-appwrite-cache", "hit");
        ctx.send(response);
    } else {
        ctx.response_headers.push(("cache-control", cache_control));
        ctx.response_headers.push(("x-appwrite-cache", "hit".to_owned()));
    }
    Ok(())
}
