//! `POST /v1/users/bcrypt` (`createBcryptUser`). Rust port of
//! `Http/Users/Bcrypt/Create.php`.

use std::collections::HashMap;

use serde_json::json;
use utopia_auth::Password;
use utopia_platform::Action;
use utopia_validators::Text;

use crate::modules::users::base;

/// `POST /v1/users/bcrypt` (`createBcryptUser`). Default cost `8` (PHP
/// `$bcrypt->setCost(8)`).
#[must_use]
pub fn create() -> Action {
    base::create_hashed_user_action("/v1/users/bcrypt", "Create user with bcrypt password")
        .param(
            "password",
            json!(""),
            appwrite_auth::Password::new(false),
            "User password hashed using Bcrypt.",
            false,
        )
        .param("name", json!(""), Text::new(128), "User name.", true)
        .http_action(|ctx| async move {
            let mut options = HashMap::new();
            options.insert("cost".to_string(), json!(8));
            base::finish_blocking(ctx, 201, appwrite_response::MODEL_USER, |ctx| {
                base::create_hashed_user(ctx, Password::create_hash(Password::BCRYPT, options))
            })
            .await
        })
}
