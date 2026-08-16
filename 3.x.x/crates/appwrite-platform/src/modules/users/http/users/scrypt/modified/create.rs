//! `POST /v1/users/scrypt-modified` (`createScryptModifiedUser`). Rust port of
//! `Http/Users/Scrypt/Modified/Create.php`.

use std::collections::HashMap;

use serde_json::json;
use utopia_auth::Password;
use utopia_platform::Action;
use utopia_validators::Text;

use crate::modules::users::base;
use crate::modules::users::http::users::hash_create;

/// `POST /v1/users/scrypt-modified` (`createScryptModifiedUser`).
#[must_use]
pub fn create() -> Action {
    hash_create::create_action(
        "/v1/users/scrypt-modified",
        "Create user with Scrypt modified password",
    )
    .param(
        "password",
        json!(""),
        appwrite_auth::Password::new(false),
        "User password hashed using Scrypt Modified.",
        false,
    )
    .param(
        "passwordSalt",
        json!(""),
        Text::new(128),
        "Salt used to hash password.",
        false,
    )
    .param(
        "passwordSaltSeparator",
        json!(""),
        Text::new(128),
        "Salt separator used to hash password.",
        false,
    )
    .param(
        "passwordSignerKey",
        json!(""),
        Text::new(128),
        "Signer key used to hash password.",
        false,
    )
    .param("name", json!(""), Text::new(128), "User name.", true)
    .http_action(|ctx| async move {
        let mut options = HashMap::new();
        options.insert(
            "salt".to_string(),
            json!(base::param_str(&ctx, "passwordSalt").unwrap_or_default()),
        );
        options.insert(
            "saltSeparator".to_string(),
            json!(base::param_str(&ctx, "passwordSaltSeparator").unwrap_or_default()),
        );
        options.insert(
            "signerKey".to_string(),
            json!(base::param_str(&ctx, "passwordSignerKey").unwrap_or_default()),
        );
        let result = hash_create::run_create(
            &ctx,
            Password::create_hash(Password::SCRYPT_MODIFIED, options),
        );
        base::finish(&ctx, 201, appwrite_response::MODEL_USER, result)
    })
}
