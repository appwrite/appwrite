//! `POST /v1/users/scrypt` (`createScryptUser`). Rust port of
//! `Http/Users/Scrypt/Create.php`.

use std::collections::HashMap;

use serde_json::json;
use utopia_auth::Password;
use utopia_platform::Action;
use utopia_validators::{Integer, Text};

use crate::modules::users::base;

/// `POST /v1/users/scrypt` (`createScryptUser`).
#[must_use]
pub fn create() -> Action {
    base::create_hashed_user_action("/v1/users/scrypt", "Create user with Scrypt password")
        .param(
            "password",
            json!(""),
            appwrite_auth::Password::new(false),
            "User password hashed using Scrypt.",
            false,
        )
        .param(
            "passwordSalt",
            json!(""),
            Text::new(128),
            "Optional salt used to hash password.",
            true,
        )
        .param(
            "passwordCpu",
            json!(8),
            Integer::new(),
            "Optional CPU cost used to hash password.",
            true,
        )
        .param(
            "passwordMemory",
            json!(14),
            Integer::new(),
            "Optional memory cost used to hash password.",
            true,
        )
        .param(
            "passwordParallel",
            json!(1),
            Integer::new(),
            "Optional parallelization cost used to hash password.",
            true,
        )
        .param(
            "passwordLength",
            json!(64),
            Integer::new(),
            "Optional hash length used to hash password.",
            true,
        )
        .param("name", json!(""), Text::new(128), "User name.", true)
        .http_action(|ctx| async move {
            let mut options = HashMap::new();
            options.insert(
                "salt".to_string(),
                json!(ctx
                    .param_value("passwordSalt")
                    .cloned()
                    .unwrap_or(json!(""))),
            );
            options.insert(
                "costCpu".to_string(),
                ctx.param_value("passwordCpu").cloned().unwrap_or(json!(8)),
            );
            options.insert(
                "costMemory".to_string(),
                ctx.param_value("passwordMemory")
                    .cloned()
                    .unwrap_or(json!(14)),
            );
            options.insert(
                "costParallel".to_string(),
                ctx.param_value("passwordParallel")
                    .cloned()
                    .unwrap_or(json!(1)),
            );
            options.insert(
                "length".to_string(),
                ctx.param_value("passwordLength")
                    .cloned()
                    .unwrap_or(json!(64)),
            );
            let result =
                base::create_hashed_user(&ctx, Password::create_hash(Password::SCRYPT, options));
            base::finish(&ctx, 201, appwrite_response::MODEL_USER, result)
        })
}
