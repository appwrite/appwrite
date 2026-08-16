//! `POST /v1/users/sha` (`createSHAUser`). Rust port of `Http/Users/SHA/Create.php`.

use std::collections::HashMap;

use serde_json::{json, Value};
use utopia_auth::Password;
use utopia_platform::Action;
use utopia_validators::{Text, WhiteList};

use crate::modules::users::base;

/// `POST /v1/users/sha` (`createSHAUser`). Optional `passwordVersion`
/// (PHP `$sha->setVersion($passwordVersion)`).
#[must_use]
pub fn create() -> Action {
    base::create_hashed_user_action("/v1/users/sha", "Create user with SHA password")
        .param(
            "password",
            json!(""),
            appwrite_auth::Password::new(false),
            "User password hashed using SHA.",
            false,
        )
        .param(
            "passwordVersion",
            json!(""),
            WhiteList::new([
                "sha1",
                "sha224",
                "sha256",
                "sha384",
                "sha512/224",
                "sha512/256",
                "sha512",
                "sha3-224",
                "sha3-256",
                "sha3-384",
                "sha3-512",
            ]),
            "Optional SHA version used to hash password.",
            true,
        )
        .param("name", json!(""), Text::new(128), "User name.", true)
        .http_action(|ctx| async move {
            let mut options = HashMap::new();
            if let Some(version) = ctx.param_value("passwordVersion").and_then(Value::as_str) {
                if !version.is_empty() {
                    options.insert("version".to_string(), json!(version));
                }
            }
            let result =
                base::create_hashed_user(&ctx, Password::create_hash(Password::SHA, options));
            base::finish(&ctx, 201, appwrite_response::MODEL_USER, result)
        })
}
