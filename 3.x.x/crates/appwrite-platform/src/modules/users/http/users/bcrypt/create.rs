//! `POST /v1/users/bcrypt` (`createBcryptUser`). Rust port of
//! `Http/Users/Bcrypt/Create.php`.

use std::collections::HashMap;

use serde_json::json;
use utopia_auth::Password;
use utopia_platform::Action;
use utopia_validators::Text;

use crate::modules::users::base;
use crate::modules::users::http::users::hash_create;

/// `POST /v1/users/bcrypt` (`createBcryptUser`). Default cost `8` (PHP
/// `$bcrypt->setCost(8)`).
#[must_use]
pub fn create() -> Action {
    hash_create::create_action("/v1/users/bcrypt", "Create user with bcrypt password")
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
            let result =
                hash_create::run_create(&ctx, Password::create_hash(Password::BCRYPT, options));
            base::finish(&ctx, 201, appwrite_response::MODEL_USER, result)
        })
}
