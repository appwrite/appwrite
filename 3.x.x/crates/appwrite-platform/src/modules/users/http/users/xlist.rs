//! `GET /v1/users` (`listUsers`). Rust port of `Http/Users/XList.php`.

use appwrite_exception::Exception;
use serde_json::{json, Value};
use utopia_database::Query;
use utopia_platform::{Action, HttpMethod};
use utopia_validators::{Boolean, Text};

use crate::modules::users::base::{self, inject};
use crate::modules::users::queries;

/// `GET /v1/users` (`listUsers`).
#[must_use]
pub fn xlist() -> Action {
    inject(
        Action::new()
            .set_http_method(HttpMethod::Get)
            .set_http_path("/v1/users")
            .desc("List users")
            .groups(["api", "users"])
            .label("scope", "users.read")
            .param(
                "queries",
                json!([]),
                queries::users(),
                "Array of query strings generated using the Query class \
                 provided by the SDK.",
                true,
            )
            .param("search", json!(""), Text::new(256), "Search term.", true)
            .param(
                "total",
                json!(true),
                Boolean::new().loose(true),
                "When set to false, the total count returned will be 0 and \
                 will not be calculated.",
                true,
            ),
        &["response", "dbForProject"],
    )
    .http_action(|ctx| async move {
        let result = (|| -> Result<Value, Exception> {
            let db_handle = base::get_db(&ctx)?;
            let mut db = db_handle.lock();
            let search = base::param_str(&ctx, "search").unwrap_or_default();
            let include_total = base::param_bool(&ctx, "total", true);

            let mut parsed = queries::parse(&queries::raw_param(ctx.param_value("queries")))?;
            queries::push_search(&mut parsed, queries::COLLECTION_USERS, &search)?;
            queries::resolve_cursor(&mut db, &mut parsed, "users", |id| {
                Exception::with_message(
                    Exception::GENERAL_CURSOR_NOT_FOUND,
                    format!("User '{id}' for the 'cursor' value not found."),
                )
            })?;
            if !queries::has_method(&parsed, utopia_database::query::TYPE_LIMIT) {
                parsed.push(Query::limit(25));
            }

            let users = db.find("users", &parsed, "read").map_err(base::db_error)?;
            // PHP `APP_LIMIT_COUNT` (5000) caps the count scan.
            let total = if include_total {
                db.count("users", &parsed, Some(5000))
                    .map_err(base::db_error)?
            } else {
                0
            };

            let items = base::users_with_targets(&mut db, &users);
            Ok(json!({ "users": items, "total": total }))
        })();
        base::finish(&ctx, 200, appwrite_response::MODEL_USER_LIST, result)
    })
}
