//! Core user CRUD. Rust port of
//! `Http/Users/{Create,Get,XList,Delete}.php`.

use appwrite_event::{DeleteMessage, DeletePublisher};
use appwrite_exception::Exception;
use serde_json::{json, Value};
use std::sync::Arc;
use utopia_database::Query;
use utopia_platform::{Action, HttpMethod};
use utopia_validators::{Boolean, Nullable, Text};

use crate::modules::users::base::{self, inject};
use crate::modules::users::queries;
use crate::modules::users::validators::Email;
use crate::state::document_to_json;

/// Marker [`utopia_auth::Hash`] standing in for PHP `Utopia\Auth\Hashes\Plaintext`
/// (not exposed by `utopia-auth`'s public API): only `name()` is read by
/// [`base::create_user`] to decide whether `password` needs hashing with the
/// project's default hasher.
#[derive(Debug, Clone, Copy, Default)]
struct PlaintextMarker;

impl utopia_auth::Hash for PlaintextMarker {
    fn hash(&self, value: &str) -> Result<String, utopia_auth::AuthError> {
        Ok(value.to_string())
    }
    fn verify(&self, value: &str, hash: &str) -> bool {
        value == hash
    }
    fn name(&self) -> &'static str {
        "plaintext"
    }
    fn options(&self) -> &std::collections::HashMap<String, Value> {
        static EMPTY: std::sync::OnceLock<std::collections::HashMap<String, Value>> =
            std::sync::OnceLock::new();
        EMPTY.get_or_init(std::collections::HashMap::new)
    }
}

/// `POST /v1/users` (`createUser`). Rust port of `Http/Users/Create.php`:
/// plaintext password, hashed with the project's default hasher inside
/// [`base::create_user`].
#[must_use]
pub fn create() -> Action {
    inject(
        Action::new()
            .set_http_method(HttpMethod::Post)
            .set_http_path("/v1/users")
            .desc("Create user")
            .groups(["api", "users"])
            .label("scope", "users.write")
            .label("audits.event", "user.create")
            .label("audits.resource", "user/{response.$id}")
            .param(
                "userId",
                json!(""),
                appwrite_database::CustomId::default(),
                "User ID.",
                false,
            )
            .param(
                "email",
                Value::Null,
                Nullable::new(Email::new(false)),
                "User email.",
                true,
            )
            .param(
                "phone",
                Value::Null,
                Nullable::new(appwrite_auth::Phone::new()),
                "Phone number.",
                true,
            )
            .param(
                "password",
                json!(""),
                appwrite_auth::Password::new(true),
                "Plain text user password. Must be at least 8 chars.",
                true,
            )
            .param("name", json!(""), Text::new(128), "User name.", true),
        &["response", "project", "dbForProject", "hooks"],
    )
    .http_action(|ctx| async move {
        let result = (|| -> Result<Value, Exception> {
            let db_handle = base::get_db(&ctx)?;
            let hooks = base::get_hooks(&ctx)?;
            let mut db = db_handle.lock().unwrap_or_else(|e| e.into_inner());
            let hasher: Arc<dyn utopia_auth::Hash> = Arc::new(PlaintextMarker);

            base::create_user(
                &mut db,
                &hooks,
                hasher,
                base::CreateUserParams {
                    user_id: base::param_str(&ctx, "userId")?,
                    email: ctx
                        .param_value("email")
                        .and_then(Value::as_str)
                        .map(str::to_string),
                    password: ctx
                        .param_value("password")
                        .and_then(Value::as_str)
                        .map(str::to_string),
                    phone: ctx
                        .param_value("phone")
                        .and_then(Value::as_str)
                        .map(str::to_string),
                    name: ctx
                        .param_value("name")
                        .and_then(Value::as_str)
                        .map(str::to_string),
                },
            )
        })();
        base::finish(&ctx, 201, appwrite_response::MODEL_USER, result)
    })
}

/// `GET /v1/users/:userId` (`getUser`). Rust port of `Http/Users/Get.php`.
#[must_use]
pub fn get() -> Action {
    inject(
        Action::new()
            .set_http_method(HttpMethod::Get)
            .set_http_path("/v1/users/:userId")
            .desc("Get user")
            .groups(["api", "users"])
            .label("scope", "users.read")
            .param("userId", json!(""), Text::new(36), "User ID.", false),
        &["response", "dbForProject"],
    )
    .http_action(|ctx| async move {
        let result = (|| -> Result<Value, Exception> {
            let db_handle = base::get_db(&ctx)?;
            let mut db = db_handle.lock().unwrap_or_else(|e| e.into_inner());
            let user_id = base::param_str(&ctx, "userId")?;
            let user =
                base::require_document(&mut db, "users", &user_id, Exception::USER_NOT_FOUND)?;
            Ok(base::user_with_targets(&mut db, &user))
        })();
        base::finish(&ctx, 200, appwrite_response::MODEL_USER, result)
    })
}

/// `GET /v1/users` (`listUsers`). Rust port of `Http/Users/XList.php`.
#[must_use]
pub fn list() -> Action {
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
            let mut db = db_handle.lock().unwrap_or_else(|e| e.into_inner());
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

/// `DELETE /v1/users/:userId` (`deleteUser`). Rust port of
/// `Http/Users/Delete.php`: delete the user, batch-delete identities/targets
/// by `userInternalId`, and enqueue `v1-deletes` for sessions/tokens/memberships
/// (handled by the deletes worker in PHP).
#[must_use]
pub fn delete() -> Action {
    inject(
        Action::new()
            .set_http_method(HttpMethod::Delete)
            .set_http_path("/v1/users/:userId")
            .desc("Delete user")
            .groups(["api", "users"])
            .label("scope", "users.write")
            .label("audits.event", "user.delete")
            .label("audits.resource", "user/{request.userId}")
            .param("userId", json!(""), Text::new(36), "User ID.", false),
        &["response", "dbForProject", "publisherForDeletes"],
    )
    .http_action(|ctx| async move {
        let result = (|| -> Result<(), Exception> {
            let db_handle = base::get_db(&ctx)?;
            let deletes = ctx
                .container
                .get_as::<Arc<dyn DeletePublisher>>("publisherForDeletes")
                .map_err(|_| Exception::new(Exception::GENERAL_SERVER_ERROR))?;
            let mut db = db_handle.lock().unwrap_or_else(|e| e.into_inner());
            let user_id = base::param_str(&ctx, "userId")?;
            let user =
                base::require_document(&mut db, "users", &user_id, Exception::USER_NOT_FOUND)?;
            let sequence = base::sequence_str(&user);

            // Match PHP order: delete the user first, then identities/targets.
            // Sessions/tokens are left to the deletes worker.
            db.delete_document("users", &user_id)
                .map_err(base::db_error)?;
            if !sequence.is_empty() {
                for collection in ["identities", "targets"] {
                    if let Ok(docs) = db.find(
                        collection,
                        &[
                            Query::equal("userInternalId", vec![sequence.clone().into()]),
                            Query::limit(1000),
                        ],
                        "read",
                    ) {
                        for doc in docs {
                            let _ = db.delete_document(collection, &doc.get_id());
                        }
                    }
                }
            }

            let message = DeleteMessage::new(appwrite_event::DELETE_TYPE_DOCUMENT)
                .with_document(document_to_json(&user))
                .with_resource_type(appwrite_event::RESOURCE_TYPE_USERS);
            let _ = deletes.enqueue(message);
            Ok(())
        })();
        base::finish_no_content(&ctx, result)
    })
}
