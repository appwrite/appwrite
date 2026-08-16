//! Membership endpoints. Rust port of `Http/Users/Memberships/XList.php`.
//!
//! Simplifications versus PHP (documented, not silently dropped): no
//! `queries` DSL / cursor pagination (see `crud::list`); the `memberships`
//! and `teams` collections are never populated by this milestone (the
//! Teams module is not yet ported), so `list` only enriches whatever a
//! future Teams port writes into them.

use appwrite_exception::Exception;
use serde_json::{json, Value};
use utopia_database::Query;
use utopia_platform::{Action, HttpMethod};
use utopia_validators::{Boolean, Text};

use crate::modules::users::base::{self, inject};
use crate::state::document_to_json;

/// `GET /v1/users/:userId/memberships` (`listUserMemberships`).
#[must_use]
pub fn list() -> Action {
    inject(
        Action::new()
            .set_http_method(HttpMethod::Get)
            .set_http_path("/v1/users/:userId/memberships")
            .desc("List user memberships")
            .groups(["api", "users"])
            .label("scope", "users.read")
            .param("userId", json!(""), Text::new(36), "User ID.", false)
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
            let user_id = base::param_str(&ctx, "userId")?;
            let user =
                base::require_document(&mut db, "users", &user_id, Exception::USER_NOT_FOUND)?;
            let search = base::param_str(&ctx, "search").unwrap_or_default();
            let include_total = ctx
                .param_value("total")
                .and_then(Value::as_bool)
                .unwrap_or(true);

            let mut queries = vec![
                Query::equal("userId", vec![user_id.clone().into()]),
                Query::limit(100),
            ];
            if !search.is_empty() {
                queries.push(Query::search("search", search));
            }
            let memberships = db
                .find("memberships", &queries, "read")
                .map_err(base::db_error)?;
            let total = if include_total {
                memberships.len() as i64
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
        })();
        base::finish(&ctx, 200, appwrite_response::MODEL_MEMBERSHIP_LIST, result)
    })
}
