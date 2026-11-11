//! Steps shared by the bucket actions.

use serde_json::Value as Json;
use utopia_database::validator::permissions::PERMISSIONS;
use utopia_database::{Database, Document};
use utopia_validators::{Nullable, Range};

use appwrite_core::platform::Context;
use appwrite_core::{Error, ErrorType, Result};

use crate::base::{self, aggregate, permission_list};

pub(super) const COMPRESSION: &[&str] = &["none", "gzip", "zstd"];

pub(super) async fn load(db: &Database, id: &str) -> Result<Document> {
    let bucket = db.get_document("buckets", id, Vec::new(), false).await.map_err(base::map_db)?;
    if bucket.is_empty() {
        return Err(Error::new(ErrorType::StorageBucketNotFound));
    }
    Ok(bucket)
}

pub(super) fn permissions(ctx: &Context, previous: Option<&Document>) -> Result<Vec<String>> {
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
pub(super) fn optional_limit(ctx: &Context, key: &str, fallback: i64, previous: Option<i64>) -> Result<i64> {
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

pub(super) fn nullable_bool(ctx: &Context, key: &str, default: bool, previous: bool) -> Result<bool> {
    match ctx.param(key) {
        None => Ok(default),
        Some(value) if value.is_null() => Ok(previous),
        Some(_) => ctx.optional_bool(key, default),
    }
}
