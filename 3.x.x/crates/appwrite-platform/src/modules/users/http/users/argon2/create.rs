//! `POST /v1/users/argon2` (`createArgon2User`). Rust port of
//! `Http/Users/Argon2/Create.php`.

use std::collections::HashMap;

use serde_json::json;
use utopia_auth::Password;
use utopia_platform::Action;
use utopia_validators::Text;

use crate::modules::users::base;

/// `POST /v1/users/argon2` (`createArgon2User`).
#[must_use]
pub fn create() -> Action {
    base::create_hashed_user_action("/v1/users/argon2", "Create user with Argon2 password")
        .param(
            "password",
            json!(""),
            appwrite_auth::Password::new(false),
            "User password hashed using Argon2.",
            false,
        )
        .param("name", json!(""), Text::new(128), "User name.", true)
        .http_action(|ctx| async move {
            let result = base::create_hashed_user(
                &ctx,
                Password::create_hash(Password::ARGON2, HashMap::new()),
            );
            base::finish(&ctx, 201, appwrite_response::MODEL_USER, result)
        })
}
