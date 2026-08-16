//! `POST /v1/users/phpass` (`createPHPassUser`). Rust port of
//! `Http/Users/PHPass/Create.php`.

use std::collections::HashMap;

use serde_json::json;
use utopia_auth::Password;
use utopia_platform::Action;
use utopia_validators::Text;

use crate::modules::users::base;
use crate::modules::users::http::users::hash_create;

/// `POST /v1/users/phpass` (`createPHPassUser`).
#[must_use]
pub fn create() -> Action {
    hash_create::create_action("/v1/users/phpass", "Create user with PHPass password")
        .param(
            "password",
            json!(""),
            appwrite_auth::Password::new(false),
            "User password hashed using PHPass.",
            false,
        )
        .param("name", json!(""), Text::new(128), "User name.", true)
        .http_action(|ctx| async move {
            let result = hash_create::run_create(
                &ctx,
                Password::create_hash(Password::PHPASS, HashMap::new()),
            );
            base::finish(&ctx, 201, appwrite_response::MODEL_USER, result)
        })
}
