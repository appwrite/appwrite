//! `POST /v1/users` (`createUser`). Rust port of `Http/Users/Create.php`.

use appwrite_exception::Exception;
use serde_json::{json, Value};
use std::sync::Arc;
use utopia_platform::{Action, HttpMethod};
use utopia_validators::{Nullable, Text};

use crate::modules::users::base::{self, inject};
use crate::modules::users::validators::Email;

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

/// `POST /v1/users` (`createUser`): plaintext password, hashed with the project's
/// default hasher inside [`base::create_user`].
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
