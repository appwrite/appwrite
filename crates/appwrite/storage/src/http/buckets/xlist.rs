//! `GET /v1/storage/buckets`. PHP `Http/Buckets/XList.php`.

use utopia_database::validator::query::cursor::Cursor as CursorValidator;
use utopia_database::{Database, PermissionType, Query, Value};
use utopia_http::{Response, StatusCode};
use utopia_query::Method;
use utopia_validators::Text;

use appwrite_core::database::LIMIT_COUNT;
use appwrite_core::database::collections;
use appwrite_core::platform::Context;
use appwrite_core::{Error, ErrorType, Result};

use crate::model::BucketList;

pub async fn xlist(ctx: &mut Context) -> Result<Response> {
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
