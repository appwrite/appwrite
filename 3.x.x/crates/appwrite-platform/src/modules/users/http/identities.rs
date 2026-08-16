//! Identity endpoints. Rust port of
//! `Http/Users/Identities/{XList,Delete}.php`.
//!
//! Simplifications versus PHP (documented, not silently dropped): the
//! `identities` collection is never populated by this milestone (OAuth
//! login lives in the not-yet-ported Account module), so `list`/`delete`
//! only operate on whatever a caller (or a future Account port) writes
//! into it directly.

use appwrite_exception::Exception;
use serde_json::{json, Value};
use utopia_database::Query;
use utopia_platform::{Action, HttpMethod};
use utopia_validators::{Boolean, Text};

use crate::modules::users::base::{self, inject};
use crate::modules::users::queries;
use crate::state::document_to_json;

/// `GET /v1/users/identities` (`listIdentities`).
#[must_use]
pub fn list() -> Action {
    inject(
        Action::new()
            .set_http_method(HttpMethod::Get)
            .set_http_path("/v1/users/identities")
            .desc("List identities")
            .groups(["api", "users"])
            .label("scope", "users.read")
            .param(
                "queries",
                json!([]),
                queries::identities(),
                "Array of query strings generated using the Query class \
                 provided by the SDK.",
                true,
            )
            .param(
                "search",
                json!(""),
                Text::new(256),
                "Search term to filter your list results. Max length: 256 chars.",
                true,
            )
            .param(
                "total",
                json!(true),
                Boolean::new().loose(true),
                "When set to false, the total count returned will be 0 and will not be calculated.",
                true,
            ),
        &["response", "dbForProject"],
    )
    .http_action(|ctx| async move {
        let result = (|| -> Result<Value, Exception> {
            let db_handle = base::get_db(&ctx)?;
            let mut db = db_handle.lock().unwrap_or_else(|e| e.into_inner());
            let search = base::param_str(&ctx, "search").unwrap_or_default();
            let include_total = base::param_bool(&ctx, "total", true);

            let mut parsed = queries::parse(&queries::raw_param(ctx.param_value("queries")))?;
            // `identities` has no `search` attribute, so PHP's blanket
            // `Query::search('search', ...)` append is exactly what makes a
            // non-empty search term a 400 here.
            queries::push_search(&mut parsed, queries::COLLECTION_IDENTITIES, &search)?;
            queries::resolve_cursor(&mut db, &mut parsed, "identities", |id| {
                Exception::with_message(
                    Exception::GENERAL_CURSOR_NOT_FOUND,
                    format!("User '{id}' for the 'cursor' value not found."),
                )
            })?;
            if !queries::has_method(&parsed, utopia_database::query::TYPE_LIMIT) {
                parsed.push(Query::limit(25));
            }

            let identities = db
                .find("identities", &parsed, "read")
                .map_err(base::db_error)?;
            let total = if include_total {
                db.count("identities", &parsed, Some(5000))
                    .map_err(base::db_error)?
            } else {
                0
            };
            Ok(json!({
                "identities": identities.iter().map(document_to_json).collect::<Vec<_>>(),
                "total": total,
            }))
        })();
        base::finish(&ctx, 200, appwrite_response::MODEL_IDENTITY_LIST, result)
    })
}

/// `DELETE /v1/users/identities/:identityId` (`deleteIdentity`).
#[must_use]
pub fn delete() -> Action {
    inject(
        Action::new()
            .set_http_method(HttpMethod::Delete)
            .set_http_path("/v1/users/identities/:identityId")
            .desc("Delete identity")
            .groups(["api", "users"])
            .label("scope", "users.write")
            .label("audits.event", "identity.delete")
            .label("audits.resource", "identity/{request.$identityId}")
            .param(
                "identityId",
                json!(""),
                Text::new(36),
                "Identity ID.",
                false,
            ),
        &["response", "dbForProject"],
    )
    .http_action(|ctx| async move {
        let result = (|| -> Result<(), Exception> {
            let db_handle = base::get_db(&ctx)?;
            let mut db = db_handle.lock().unwrap_or_else(|e| e.into_inner());
            let identity_id = base::param_str(&ctx, "identityId")?;
            let identity = db
                .get_document("identities", &identity_id, &[], false)
                .map_err(base::db_error)?;
            if identity.is_empty() {
                return Err(Exception::new(Exception::USER_IDENTITY_NOT_FOUND));
            }
            db.delete_document("identities", &identity_id)
                .map_err(base::db_error)?;
            Ok(())
        })();
        base::finish_no_content(&ctx, result)
    })
}
