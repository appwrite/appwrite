//! Import users with pre-hashed passwords (`POST /v1/users/<algorithm>`).

use utopia_auth::Hash;
use utopia_auth::hashes::{Argon2, Bcrypt, Md5, PHPass, Scrypt, ScryptModified, Sha};
use utopia_http::Response;
use utopia_validators::{Integer, Text, WhiteList};

use appwrite_core::platform::Context;
use appwrite_core::response::UserModel;
use appwrite_core::validators::{CustomId, Password};
use appwrite_core::{Error, ErrorType, Result};
use utopia_emails::EmailValidator;

use crate::base::{self, NewUser};

struct Common {
    user_id: String,
    email: String,
    password: String,
}

fn common(ctx: &Context) -> Result<Common> {
    Ok(Common {
        user_id: ctx.required_str("userId", &CustomId)?,
        email: ctx.required_str("email", &EmailValidator::default())?,
        password: ctx.required_str("password", &Password::default())?,
    })
}

fn name(ctx: &Context) -> Result<Option<String>> {
    ctx.optional_str("name", "", &Text::new(128))
}

async fn finish(ctx: &mut Context, c: Common, name: Option<String>, hash: Box<dyn Hash>) -> Result<Response> {
    let user = base::create_user(
        ctx,
        NewUser {
            hash,
            user_id: c.user_id,
            email: Some(c.email),
            password: Some(c.password),
            phone: None,
            name,
            password_pwned: None,
        },
    )
    .await?;
    Ok(ctx.created(&UserModel { user: &user, render: ctx.render() }))
}

/// Setter failures that PHP does not catch surface as 500 errors.
fn uncaught(e: utopia_auth::Error) -> Error {
    Error::internal(e.to_string())
}

pub async fn argon2(ctx: &mut Context) -> Result<Response> {
    let c = common(ctx)?;
    let n = name(ctx)?;
    finish(ctx, c, n, Box::new(Argon2::new())).await
}

pub async fn bcrypt(ctx: &mut Context) -> Result<Response> {
    let c = common(ctx)?;
    let n = name(ctx)?;
    finish(ctx, c, n, Box::new(Bcrypt::new())).await
}

pub async fn md5(ctx: &mut Context) -> Result<Response> {
    let c = common(ctx)?;
    let n = name(ctx)?;
    finish(ctx, c, n, Box::new(Md5::new())).await
}

pub async fn sha(ctx: &mut Context) -> Result<Response> {
    let c = common(ctx)?;
    let version = ctx.optional_str(
        "passwordVersion",
        "",
        &WhiteList::new(&[
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
    )?;
    let n = name(ctx)?;
    let mut hash = Sha::new();
    if let Some(version) = version.as_deref().filter(|v| !v.is_empty()) {
        hash.set_version_name(version.as_bytes()).map_err(uncaught)?;
    }
    finish(ctx, c, n, Box::new(hash)).await
}

pub async fn phpass(ctx: &mut Context) -> Result<Response> {
    let c = common(ctx)?;
    let n = name(ctx)?;
    finish(ctx, c, n, Box::new(PHPass::new())).await
}

pub async fn scrypt(ctx: &mut Context) -> Result<Response> {
    let c = common(ctx)?;
    let salt = ctx.required_str("passwordSalt", &Text::new(128))?;
    let int = Integer::default();
    let cpu = ctx.required("passwordCpu", &int)?;
    let memory = ctx.required("passwordMemory", &int)?;
    let parallel = ctx.required("passwordParallel", &int)?;
    let length = ctx.required("passwordLength", &int)?;
    let n = name(ctx)?;
    let as_int = |v: &serde_json::Value| v.as_i64().unwrap_or(0);
    let mut hash = Scrypt::new();
    hash.set_salt(salt.as_bytes())
        .and_then(|h| h.set_cpu_cost(as_int(&cpu)))
        .and_then(|h| h.set_memory_cost(as_int(&memory)))
        .and_then(|h| h.set_parallel_cost(as_int(&parallel)))
        .and_then(|h| h.set_length(as_int(&length)))
        .map_err(uncaught)?;
    finish(ctx, c, n, Box::new(hash)).await
}

pub async fn scrypt_modified(ctx: &mut Context) -> Result<Response> {
    let c = common(ctx)?;
    let salt = ctx.required_str("passwordSalt", &Text::new(128))?;
    let separator = ctx.required_str("passwordSaltSeparator", &Text::new(128))?;
    let signer = ctx.required_str("passwordSignerKey", &Text::new(128))?;
    let n = name(ctx)?;
    let mut hash = ScryptModified::new();
    hash.set_salt(salt.as_bytes())
        .and_then(|h| h.set_salt_separator(separator.as_bytes()))
        .and_then(|h| h.set_signer_key(signer.as_bytes()))
        .map_err(|e| match e {
            utopia_auth::Error::InvalidArgument(m) => Error::with_message(ErrorType::GeneralArgumentInvalid, m),
            other => Error::internal(other.to_string()),
        })?;
    finish(ctx, c, n, Box::new(hash)).await
}
