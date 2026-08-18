//! `POST /v1/users/md5` (`createMD5User`). Rust port of `Http/Users/MD5/Create.php`.

use std::collections::HashMap;

use serde_json::json;
use utopia_auth::Password;
use utopia_platform::Action;
use utopia_validators::Text;

use crate::modules::users::base;

/// `POST /v1/users/md5` (`createMD5User`).
#[must_use]
pub fn create() -> Action {
    base::create_hashed_user_action("/v1/users/md5", "Create user with MD5 password")
        .param(
            "password",
            json!(""),
            appwrite_auth::Password::new(false),
            "User password hashed using MD5.",
            false,
        )
        .param("name", json!(""), Text::new(128), "User name.", true)
        .http_action(|ctx| async move {
            base::finish_blocking(ctx, 201, appwrite_response::MODEL_USER, |ctx| {
                base::create_hashed_user(ctx, Password::create_hash(Password::MD5, HashMap::new()))
            })
            .await
        })
}
