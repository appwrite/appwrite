//! `PATCH /v1/users/:userId/password` (`updateUserPassword`). Rust port of
//! `Http/Users/Password/Update.php`.
//!
//! Simplifications versus PHP (documented, not silently dropped): strength/
//! dictionary/history enforcement and session invalidation project policies
//! are not implemented unless the project enables them (see module docs in
//! [`crate::modules::users::base`]). An empty `password` clears the stored
//! hash and returns immediately, matching the early-response branch PHP's
//! handler takes.

use std::collections::HashMap;

use appwrite_exception::Exception;
use serde_json::{json, Value};
use utopia_auth::Password;
use utopia_platform::{Action, HttpMethod};

use crate::modules::users::base::{self, inject};

/// PHP `$project->getAttribute('auths', [])['<flag>'] ?? false`.
fn auths_flag(project: &Value, flag: &str) -> bool {
    project
        .get("auths")
        .and_then(|auths| auths.get(flag))
        .is_some_and(|value| match value {
            Value::Bool(value) => *value,
            Value::Number(value) => value.as_f64().is_some_and(|value| value != 0.0),
            Value::String(value) => !matches!(value.as_str(), "" | "0" | "false"),
            _ => false,
        })
}

/// `PATCH /v1/users/:userId/password` (`updateUserPassword`).
#[must_use]
pub fn update() -> Action {
    inject(
        base::user_id_param(
            Action::new()
                .set_http_method(HttpMethod::Patch)
                .set_http_path("/v1/users/:userId/password")
                .desc("Update password")
                .groups(["api", "users"])
                .label("scope", "users.write")
                .label("audits.event", "user.update")
                .label("audits.resource", "user/{response.$id}"),
        )
        .param(
            "password",
            json!(""),
            appwrite_auth::Password::new(true),
            "New user password. Must be at least 8 chars.",
            false,
        ),
        &["response", "project", "dbForProject"],
    )
    .http_action(|ctx| async move {
        base::finish_blocking(ctx, 200, appwrite_response::MODEL_USER, |ctx| {
            let project = base::get_project(&ctx)?;
            let user_id = base::param_str(&ctx, "userId")?;
            let password = base::param_str(&ctx, "password")?;

            // Argon2-hash before checking `dbForProject` out of the pool -
            // the same reasoning as `Http/Users/Create.php`'s plaintext path
            // (see `base::resolve_password`).
            let hashed_fields = if password.is_empty() {
                None
            } else {
                let hasher = Password::create_hash(Password::ARGON2, HashMap::new())
                    .map_err(base::hash_error)?;
                let hashed = hasher.hash(&password).map_err(base::hash_error)?;
                Some(json!({
                    "password": hashed,
                    "passwordUpdate": base::now_iso(),
                    "hash": hasher.name(),
                    "hashOptions": hasher.options(),
                }))
            };

            let db_handle = base::get_db(&ctx)?;
            let mut db = db_handle.lock();
            base::require_document(&mut db, "users", &user_id, Exception::USER_NOT_FOUND)?;

            let updated = base::update_user_fields(
                &mut db,
                &user_id,
                hashed_fields.unwrap_or_else(
                    || json!({ "password": "", "passwordUpdate": base::now_iso() }),
                ),
            )?;

            if auths_flag(&project, "invalidateSessions") {
                base::delete_user_sessions(&mut db, &user_id)?;
            }
            base::purge_user(&mut db, &user_id);

            Ok(updated)
        })
        .await
    })
}
