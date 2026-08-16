//! `GET /v1/users/:userId/memberships` (`listUserMemberships`). Rust port of
//! `Http/Users/Memberships/XList.php`.

use appwrite_exception::Exception;
use serde_json::{json, Value};
use utopia_database::Query;
use utopia_platform::{Action, HttpMethod};
use utopia_validators::{Boolean, Text};

use crate::modules::users::base::{self, inject};
use crate::modules::users::queries;
use crate::state::document_to_json;

/// `GET /v1/users/:userId/memberships` (`listUserMemberships`).
#[must_use]
pub fn xlist() -> Action {
    inject(
        Action::new()
            .set_http_method(HttpMethod::Get)
            .set_http_path("/v1/users/:userId/memberships")
            .desc("List user memberships")
            .groups(["api", "users"])
            .label("scope", "users.read")
            .param("userId", json!(""), Text::new(36), "User ID.", false)
            .param(
                "queries",
                json!([]),
                queries::memberships(),
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
        base::finish_blocking(ctx, 200, appwrite_response::MODEL_MEMBERSHIP_LIST, |ctx| {
            let db_handle = base::get_db(&ctx)?;
            let mut db = db_handle.lock();
            let user_id = base::param_str(&ctx, "userId")?;
            let user =
                base::require_document(&mut db, "users", &user_id, Exception::USER_NOT_FOUND)?;
            let search = base::param_str(&ctx, "search").unwrap_or_default();
            let include_total = base::param_bool(&ctx, "total", true);

            let mut parsed = queries::parse(&queries::raw_param(ctx.param_value("queries")))?;
            queries::push_search(&mut parsed, queries::COLLECTION_MEMBERSHIPS, &search)?;
            queries::resolve_cursor(&mut db, &mut parsed, "memberships", |id| {
                Exception::with_message(
                    Exception::GENERAL_CURSOR_NOT_FOUND,
                    format!("Membership '{id}' for the 'cursor' value not found."),
                )
            })?;
            if !queries::has_method(&parsed, utopia_database::query::TYPE_LIMIT) {
                parsed.push(Query::limit(25));
            }
            // PHP scopes the list to the route's user via the `memberships`
            // relationship on the user document rather than a query, so the
            // caller's queries never have to carry it.
            parsed.insert(0, Query::equal("userId", vec![user_id.clone().into()]));

            let memberships = db
                .find("memberships", &parsed, "read")
                .map_err(base::db_error)?;
            let total = if include_total {
                db.count("memberships", &parsed, Some(5000))
                    .map_err(base::db_error)?
            } else {
                0
            };

            let user_name = user
                .get_attribute("name")
                .as_str()
                .unwrap_or_default()
                .to_string();
            let user_email = user
                .get_attribute("email")
                .as_str()
                .unwrap_or_default()
                .to_string();

            let items: Vec<Value> = memberships
                .iter()
                .map(|membership| {
                    let team_id = membership
                        .get_attribute("teamId")
                        .as_str()
                        .unwrap_or_default()
                        .to_string();
                    let team = db
                        .get_document("teams", &team_id, &[], false)
                        .unwrap_or_default();
                    let mut out = document_to_json(membership);
                    out["teamName"] =
                        json!(team.get_attribute("name").as_str().unwrap_or_default());
                    out["userName"] = json!(user_name);
                    out["userEmail"] = json!(user_email);
                    out
                })
                .collect();

            Ok(json!({ "memberships": items, "total": total }))
        })
        .await
    })
}
