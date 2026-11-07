//! `GET /v1/storage/buckets/:bucketId/files`. PHP `Http/Buckets/Files/XList.php`.

use utopia_database::validator::query::cursor::Cursor as CursorValidator;
use utopia_database::{Database, PermissionType, Query, Value};
use utopia_http::{Response, StatusCode};
use utopia_query::Method;
use utopia_validators::Text;

use appwrite_core::database::LIMIT_COUNT;
use appwrite_core::database::collections;
use appwrite_core::database::documents::Attributes;
use appwrite_core::platform::Context;
use appwrite_core::validators::uid;
use appwrite_core::{Error, ErrorType, Result};

use crate::base::{self, allows, bucket_collection, unauthorized};
use crate::model::FileList;

pub async fn xlist(ctx: &mut Context) -> Result<Response> {
    let bucket_id = ctx.required_str("bucketId", &uid())?;
    let mut queries = ctx.queries(&collections::FILES)?;
    let search = ctx.optional_str("search", "", &Text::with_min(256, 0))?.unwrap_or_default();
    let include_total = ctx.optional_bool("total", true)?;
    if !search.is_empty() {
        queries.push(Query::search("search", &search));
    }
    let db = ctx.db()?;
    let bucket = crate::http::visible_bucket(ctx, &db, &bucket_id).await?;
    let secured = bucket.boolean("fileSecurity").unwrap_or(false);
    let readable = allows(ctx, &bucket, PermissionType::Read)?;
    if !secured && !readable {
        return Err(unauthorized(ctx));
    }
    let collection = bucket_collection(&bucket);
    resolve_cursor(&db, &collection, &mut queries, secured && !readable).await?;
    let filters = Query::group_by_type(&queries)?.filters;
    let (files, total) = if secured && !readable {
        let files = db.find(&collection, queries, PermissionType::Read).await.map_err(base::map_db)?;
        let total = if include_total {
            db.count(&collection, filters, Some(LIMIT_COUNT)).await.map_err(base::map_db)?
        } else {
            0
        };
        (files, total)
    } else {
        let auth = db.authorization();
        let files = auth.skip(db.find(&collection, queries, PermissionType::Read)).await.map_err(base::map_db)?;
        let total = if include_total {
            auth.skip(db.count(&collection, filters, Some(LIMIT_COUNT))).await.map_err(base::map_db)?
        } else {
            0
        };
        (files, total)
    };
    Ok(ctx.json(StatusCode::OK, &FileList { total, files: &files }))
}

async fn resolve_cursor(db: &Database, collection: &str, queries: &mut [Query], enforce: bool) -> Result<()> {
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
    let document = if enforce {
        db.get_document(collection, &id, Vec::new(), false).await.map_err(base::map_db)?
    } else {
        db.authorization().skip(db.get_document(collection, &id, Vec::new(), false)).await.map_err(base::map_db)?
    };
    if document.is_empty() {
        return Err(Error::with_message(
            ErrorType::GeneralCursorNotFound,
            format!("File '{id}' for the 'cursor' value not found."),
        ));
    }
    queries[cursor].set_value(utopia_database::value::to_query(&Value::from(document)));
    Ok(())
}
