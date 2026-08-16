//! Create-user-with-pre-hashed-password endpoints. Rust port of
//! `Http/Users/{Argon2,Bcrypt,MD5,SHA,PHPass,Scrypt,Scrypt/Modified}/Create.php`.
//!
//! Every endpoint here receives a password already hashed by the caller
//! (migrating users from another system); [`base::create_user`] treats a
//! non-`"plaintext"` hasher name as "already hashed", matching
//! `Base::createUser()`'s `$isHashed = !$hash instanceof Plaintext`.

use std::collections::HashMap;

use appwrite_exception::Exception;
use serde_json::{json, Value};
use utopia_auth::Password;
use utopia_platform::{Action, HttpMethod};
use utopia_validators::{Integer, Text, WhiteList};

use crate::modules::users::base::{self, inject};
use crate::modules::users::validators::Email;

fn common_params(action: Action) -> Action {
    action
        .param(
            "userId",
            json!(""),
            appwrite_database::CustomId::default(),
            "User ID.",
            false,
        )
        .param("email", json!(""), Email::new(false), "User email.", false)
}

fn create_action(path: &'static str, desc: &'static str) -> Action {
    inject(
        common_params(
            Action::new()
                .set_http_method(HttpMethod::Post)
                .set_http_path(path)
                .desc(desc)
                .groups(["api", "users"])
                .label("scope", "users.write")
                .label("audits.event", "user.create")
                .label("audits.resource", "user/{response.$id}"),
        ),
        &["response", "project", "dbForProject", "hooks"],
    )
}

fn run_create(
    ctx: &utopia_http::ActionContext,
    hasher: Result<std::sync::Arc<dyn utopia_auth::Hash>, utopia_auth::AuthError>,
) -> Result<Value, Exception> {
    let hasher = hasher.map_err(base::hash_error)?;
    let db_handle = base::get_db(ctx)?;
    let hooks = base::get_hooks(ctx)?;
    let mut db = db_handle.lock().unwrap_or_else(|e| e.into_inner());
    base::create_user(
        &mut db,
        &hooks,
        hasher,
        base::CreateUserParams {
            user_id: base::param_str(ctx, "userId")?,
            email: Some(base::param_str(ctx, "email")?),
            password: Some(base::param_str(ctx, "password")?),
            phone: None,
            name: ctx
                .param_value("name")
                .and_then(Value::as_str)
                .map(str::to_string),
        },
    )
}

/// `POST /v1/users/argon2` (`createArgon2User`).
#[must_use]
pub fn create_argon2() -> Action {
    create_action("/v1/users/argon2", "Create user with Argon2 password")
        .param(
            "password",
            json!(""),
            appwrite_auth::Password::new(false),
            "User password hashed using Argon2.",
            false,
        )
        .param("name", json!(""), Text::new(128), "User name.", true)
        .http_action(|ctx| async move {
            let result = run_create(
                &ctx,
                Password::create_hash(Password::ARGON2, HashMap::new()),
            );
            base::finish(&ctx, 201, appwrite_response::MODEL_USER, result)
        })
}

/// `POST /v1/users/bcrypt` (`createBcryptUser`). Default cost `8` (PHP
/// `$bcrypt->setCost(8)`).
#[must_use]
pub fn create_bcrypt() -> Action {
    create_action("/v1/users/bcrypt", "Create user with bcrypt password")
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
            let result = run_create(&ctx, Password::create_hash(Password::BCRYPT, options));
            base::finish(&ctx, 201, appwrite_response::MODEL_USER, result)
        })
}

/// `POST /v1/users/md5` (`createMD5User`).
#[must_use]
pub fn create_md5() -> Action {
    create_action("/v1/users/md5", "Create user with MD5 password")
        .param(
            "password",
            json!(""),
            appwrite_auth::Password::new(false),
            "User password hashed using MD5.",
            false,
        )
        .param("name", json!(""), Text::new(128), "User name.", true)
        .http_action(|ctx| async move {
            let result = run_create(&ctx, Password::create_hash(Password::MD5, HashMap::new()));
            base::finish(&ctx, 201, appwrite_response::MODEL_USER, result)
        })
}

/// `POST /v1/users/sha` (`createSHAUser`). Optional `passwordVersion`
/// (PHP `$sha->setVersion($passwordVersion)`).
#[must_use]
pub fn create_sha() -> Action {
    create_action("/v1/users/sha", "Create user with SHA password")
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
            let result = run_create(&ctx, Password::create_hash(Password::SHA, options));
            base::finish(&ctx, 201, appwrite_response::MODEL_USER, result)
        })
}

/// `POST /v1/users/phpass` (`createPHPassUser`).
#[must_use]
pub fn create_phpass() -> Action {
    create_action("/v1/users/phpass", "Create user with PHPass password")
        .param(
            "password",
            json!(""),
            appwrite_auth::Password::new(false),
            "User password hashed using PHPass.",
            false,
        )
        .param("name", json!(""), Text::new(128), "User name.", true)
        .http_action(|ctx| async move {
            let result = run_create(
                &ctx,
                Password::create_hash(Password::PHPASS, HashMap::new()),
            );
            base::finish(&ctx, 201, appwrite_response::MODEL_USER, result)
        })
}

/// `POST /v1/users/scrypt` (`createScryptUser`).
#[must_use]
pub fn create_scrypt() -> Action {
    create_action("/v1/users/scrypt", "Create user with Scrypt password")
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
            let result = run_create(&ctx, Password::create_hash(Password::SCRYPT, options));
            base::finish(&ctx, 201, appwrite_response::MODEL_USER, result)
        })
}

/// `POST /v1/users/scrypt-modified` (`createScryptModifiedUser`).
#[must_use]
pub fn create_scrypt_modified() -> Action {
    create_action(
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
        let result = run_create(
            &ctx,
            Password::create_hash(Password::SCRYPT_MODIFIED, options),
        );
        base::finish(&ctx, 201, appwrite_response::MODEL_USER, result)
    })
}
