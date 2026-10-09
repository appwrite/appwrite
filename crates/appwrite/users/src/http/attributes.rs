//! User attribute updates (`/v1/users/:userId/<attribute>`).

use serde_json::Value;
use utopia_auth::proofs::Password;
use utopia_database::sql::Builder;
use utopia_database::{Param, datetime};
use utopia_emails::{EmailValidator, Metadata};
use utopia_http::Response;
use utopia_validators::{AllOf, ArrayList, Boolean, Text, Validator, php};

use appwrite_core::crypto;
use appwrite_core::database::documents::{Relations, User};
use appwrite_core::platform::Context;
use appwrite_core::response::UserModel;
use appwrite_core::validators::{PasswordDictionary, PasswordStrength, Phone, uid};
use appwrite_core::{Error, ErrorType, Result};

use crate::base;

async fn respond(ctx: &mut Context, user: &User) -> Result<Response> {
    ctx.event_param("userId", user.id.clone());
    Ok(ctx.ok(&UserModel { user, render: ctx.render() }))
}

/// `PATCH /v1/users/:userId/status`.
pub async fn status(ctx: &mut Context) -> Result<Response> {
    let user_id = ctx.required_str("userId", &uid())?;
    let status = php::truthy(&ctx.required("status", &Boolean::LOOSE)?);
    let mut user = base::user_or_404(ctx, &user_id, Relations::NONE).await?;
    user.status = Some(status);
    let user = base::update_user(ctx, &user, vec![("status", Param::Bool(status))]).await?;
    respond(ctx, &user).await
}

/// `PUT /v1/users/:userId/labels`.
pub async fn labels(ctx: &mut Context) -> Result<Response> {
    let user_id = ctx.required_str("userId", &uid())?;
    let mut allow: Vec<&'static str> = Vec::with_capacity(62);
    allow.extend(Text::NUMBERS);
    allow.extend(Text::ALPHABET_UPPER);
    allow.extend(Text::ALPHABET_LOWER);
    let validator = ArrayList::new(Text::new(36).with_allow_list(allow), 1000);
    let value = ctx.required("labels", &validator)?;
    let mut labels: Vec<String> = Vec::new();
    for v in php::array_values(&value) {
        let s = php::to_string(v).unwrap_or_default();
        if !labels.contains(&s) {
            labels.push(s);
        }
    }
    let mut user = base::user_or_404(ctx, &user_id, Relations::NONE).await?;
    user.labels = labels.clone();
    let user = base::update_user(ctx, &user, vec![("labels", Param::string_list(&labels))]).await?;
    respond(ctx, &user).await
}

/// `PATCH /v1/users/:userId/impersonator`.
pub async fn impersonator(ctx: &mut Context) -> Result<Response> {
    let user_id = ctx.required_str("userId", &uid())?;
    let value = php::truthy(&ctx.required("impersonator", &Boolean::LOOSE)?);
    let mut user = base::user_or_404(ctx, &user_id, Relations::NONE).await?;
    user.impersonator = Some(value);
    let user = base::update_user(ctx, &user, vec![("impersonator", Param::Bool(value))]).await?;
    respond(ctx, &user).await
}

/// `PATCH /v1/users/:userId/name`.
pub async fn name(ctx: &mut Context) -> Result<Response> {
    let user_id = ctx.required_str("userId", &uid())?;
    let name = ctx.required_str("name", &Text::with_min(128, 0))?;
    let mut user = base::user_or_404(ctx, &user_id, Relations::NONE).await?;
    user.name = Some(name.clone());
    let user = base::update_user(ctx, &user, vec![("name", Param::Text(name))]).await?;
    respond(ctx, &user).await
}

/// `PATCH /v1/users/:userId/verification`.
pub async fn email_verification(ctx: &mut Context) -> Result<Response> {
    let user_id = ctx.required_str("userId", &uid())?;
    let value = php::truthy(&ctx.required("emailVerification", &Boolean::STRICT)?);
    let mut user = base::user_or_404(ctx, &user_id, Relations::NONE).await?;
    user.email_verification = Some(value);
    let user = base::update_user(ctx, &user, vec![("emailVerification", Param::Bool(value))]).await?;
    respond(ctx, &user).await
}

/// `PATCH /v1/users/:userId/verification/phone`.
pub async fn phone_verification(ctx: &mut Context) -> Result<Response> {
    let user_id = ctx.required_str("userId", &uid())?;
    let value = php::truthy(&ctx.required("phoneVerification", &Boolean::STRICT)?);
    let mut user = base::user_or_404(ctx, &user_id, Relations::NONE).await?;
    user.phone_verification = Some(value);
    let user = base::update_user(ctx, &user, vec![("phoneVerification", Param::Bool(value))]).await?;
    respond(ctx, &user).await
}

/// `PATCH /v1/users/:userId/password`.
pub async fn password(ctx: &mut Context) -> Result<Response> {
    let user_id = ctx.required_str("userId", &uid())?;
    let state = ctx.state.clone();
    let validator = AllOf(vec![
        Box::new(PasswordStrength::from_policy(ctx.project.auth("passwordStrength"), true)) as Box<dyn Validator + '_>,
        Box::new(PasswordDictionary {
            dictionary: &state.dictionary,
            enabled: ctx.project.auth_bool("passwordDictionary", false),
            allow_empty: true,
        }),
    ]);
    let password = ctx.required_str("password", &validator)?;
    drop(validator);

    let user = base::user_or_404(ctx, &user_id, Relations::SESSIONS).await?;

    if ctx.project.auth_bool("personalDataCheck", false)
        && !appwrite_core::validators::personal_data_ok(
            Some(&password),
            &user.id,
            user.email.as_deref(),
            user.name.as_deref(),
            user.phone.as_deref(),
            false,
        )
    {
        return Err(Error::new(ErrorType::UserPasswordPersonalData));
    }

    let policy = ctx.project.auth("passwordPwned").cloned().unwrap_or(Value::Null);
    let enabled = policy.get("enabled").map(php::truthy).unwrap_or(true);
    let pwned = if password.is_empty() || !enabled { None } else { Some(ctx.state.pwned.is_pwned(&password).await?) };
    if pwned == Some(true) && policy.get("users").map(php::truthy).unwrap_or(false) {
        return Err(Error::new(ErrorType::UserPasswordPwned));
    }

    // PHP answers immediately for an empty password and then keeps going
    // (the client only ever sees this first response).
    let mut early: Option<Response> = None;
    if password.is_empty() {
        let mut cleared = user.clone();
        cleared.password = Some(String::new());
        let updated = base::update_user(
            ctx,
            &cleared,
            vec![
                ("password", Param::Text(crypto::encrypt_env(""))),
                ("passwordPwned", Param::opt_bool(None)),
                ("passwordUpdate", Param::Timestamp(datetime::now())),
            ],
        )
        .await?;
        ctx.event_param("userId", updated.id.clone());
        early = Some(ctx.ok(&UserModel { user: &updated, render: ctx.render() }));
    }

    let result = finish_password(ctx, &user, password, pwned).await;
    match (early, result) {
        (Some(response), _) => Ok(response),
        (None, Ok(updated)) => respond(ctx, &updated).await,
        (None, Err(e)) => Err(e),
    }
}

async fn finish_password(ctx: &mut Context, user: &User, password: String, pwned: Option<bool>) -> Result<User> {
    if !password.is_empty() {
        ctx.state.hooks.password_validator(ctx, &password, Some(user)).await?;
    }
    let new_hash = base::hash_password(password.clone()).await?;
    let proof = base::proof();

    let kind = user.hash.clone().unwrap_or_else(|| "argon2".to_owned());
    let options = user.hash_options.clone().unwrap_or(Value::Null);
    let options = match php_std::zval::Zval::from(&options) {
        php_std::zval::Zval::Array(a) => a,
        _ => php_std::zval::Array::new(),
    };
    let current = Password::create_hash(&kind, &options).map_err(|e| Error::internal(e.to_string()))?;

    let limit = ctx.project.auth_int("passwordHistory", 0);
    let mut history = user.password_history.clone();
    if limit > 0 {
        let candidate = password.clone();
        let entries = history.clone();
        let reused = tokio::task::spawn_blocking(move || {
            if !(8..=256).contains(&candidate.len()) {
                return true;
            }
            entries
                .iter()
                .filter(|h| !h.is_empty())
                .any(|h| current.verify(candidate.as_bytes(), h.as_bytes()).unwrap_or(false))
        })
        .await
        .map_err(|e| Error::internal(e.to_string()))?;
        if reused {
            return Err(Error::new(ErrorType::UserPasswordRecentlyUsed));
        }
        history.push(new_hash.clone());
        let limit = limit as usize;
        if history.len() > limit {
            history.drain(..history.len() - limit);
        }
    }

    let mut merged = user.clone();
    merged.password = Some(new_hash.clone());
    let updated = base::update_user(
        ctx,
        &merged,
        vec![
            ("password", Param::Text(crypto::encrypt_env(&new_hash))),
            ("passwordHistory", Param::string_list(&history)),
            ("passwordPwned", Param::opt_bool(pwned)),
            ("passwordUpdate", Param::Timestamp(datetime::now())),
            ("hash", Param::text(proof.name())),
            ("hashOptions", Param::Text(appwrite_core::json::to_string(&base::hash_options(proof.as_ref())))),
        ],
    )
    .await?;

    if ctx.project.auth_bool("invalidateSessions", true) {
        invalidate_sessions(ctx, user).await?;
    }
    Ok(updated)
}

/// `User::invalidateAuthentication`: deletes sessions and challenges.
async fn invalidate_sessions(ctx: &mut Context, user: &User) -> Result<()> {
    let db = ctx.db()?;
    for session in &user.sessions {
        if db.delete("sessions", &session.id).await? {
            ctx.metric("sessions", -1);
        }
    }
    let mut b = Builder::new();
    let seq = b.bind(Param::Text(user.sequence.to_string()));
    let tenant = match db.tenant() {
        Some(t) => format!(" AND \"_tenant\" = {}", b.bind(Param::Int(t))),
        None => String::new(),
    };
    let sql = format!(
        "WITH d AS (DELETE FROM {} WHERE \"userInternalId\" = {seq}{tenant} RETURNING \"_uid\"), \
         p AS (DELETE FROM {} WHERE \"_document\" IN (SELECT \"_uid\" FROM d){tenant}) SELECT \"_uid\" FROM d",
        db.table("challenges"),
        db.perms_table("challenges")
    );
    for row in db.query(&sql, &b).await? {
        let id: String = row.try_get(0).unwrap_or_default();
        db.purge_cached_document("challenges", &id).await;
    }
    db.purge_cached_document("users", &user.id).await;
    Ok(())
}

/// `PATCH /v1/users/:userId/email`.
pub async fn email(ctx: &mut Context) -> Result<Response> {
    let user_id = ctx.required_str("userId", &uid())?;
    let email = ctx.required_str("email", &EmailValidator { allow_empty: true })?.to_lowercase();
    let user = base::user_or_404(ctx, &user_id, Relations::TARGETS).await?;
    let db = ctx.db()?;
    if !email.is_empty() {
        if base::identity_email_taken(&db, &email, Some(user.sequence)).await? {
            return Err(Error::new(ErrorType::UserEmailAlreadyExists));
        }
        if base::target_by_identifier(&db, &email).await?.is_some() {
            return Err(Error::new(ErrorType::UserTargetAlreadyExists));
        }
    }
    let old_email = user.email.clone();
    let meta = Metadata::of(Some(&email));
    base::check_email_policy(ctx, &meta)?;

    let mut merged = user.clone();
    merged.email = Some(email.clone());
    let result = async {
        let mut updated = base::update_user(
            ctx,
            &merged,
            vec![
                ("email", Param::Text(email.clone())),
                ("emailVerification", Param::Bool(false)),
                ("emailCanonical", Param::opt_text(meta.canonical.clone())),
                ("emailIsCanonical", Param::opt_bool(meta.is_canonical)),
                ("emailIsCorporate", Param::opt_bool(meta.is_corporate)),
                ("emailIsDisposable", Param::opt_bool(meta.is_disposable)),
                ("emailIsFree", Param::opt_bool(meta.is_free)),
            ],
        )
        .await?;
        sync_target(ctx, &mut updated, old_email.as_deref(), &email, "email").await?;
        Ok::<User, Error>(updated)
    }
    .await;
    let updated = result.map_err(|e| duplicate_as(e, ErrorType::UserEmailAlreadyExists))?;
    respond(ctx, &updated).await
}

/// `PATCH /v1/users/:userId/phone`.
pub async fn phone(ctx: &mut Context) -> Result<Response> {
    let user_id = ctx.required_str("userId", &uid())?;
    let number = ctx.required_str("number", &Phone { allow_empty: true })?;
    let user = base::user_or_404(ctx, &user_id, Relations::TARGETS).await?;
    let db = ctx.db()?;
    let old_phone = user.phone.clone();
    if !number.is_empty() && base::target_by_identifier(&db, &number).await?.is_some() {
        return Err(Error::new(ErrorType::UserTargetAlreadyExists));
    }
    let value = if number.is_empty() { None } else { Some(number.clone()) };
    let mut merged = user.clone();
    merged.phone = value.clone();
    let result = async {
        let mut updated = base::update_user(
            ctx,
            &merged,
            vec![("phone", Param::opt_text(value.clone())), ("phoneVerification", Param::Bool(false))],
        )
        .await?;
        sync_target(ctx, &mut updated, old_phone.as_deref(), &number, "sms").await?;
        Ok::<User, Error>(updated)
    }
    .await;
    let updated = result.map_err(|e| duplicate_as(e, ErrorType::UserPhoneAlreadyExists))?;
    respond(ctx, &updated).await
}

fn duplicate_as(error: Error, kind: ErrorType) -> Error {
    if error.kind == ErrorType::DocumentAlreadyExists { Error::new(kind) } else { error }
}

/// Keeps the email/sms target in sync with the new identifier.
async fn sync_target(
    ctx: &mut Context,
    user: &mut User,
    old: Option<&str>,
    new: &str,
    provider_type: &str,
) -> Result<()> {
    let db = ctx.db()?;
    let existing = old.and_then(|o| user.targets.iter().position(|t| t.identifier == o));
    match existing {
        Some(index) => {
            let target_id = user.targets[index].id.clone();
            if !new.is_empty() {
                db.update("targets", &target_id, vec![("identifier", Param::text(new))], "\"_uid\"").await?;
                user.targets[index].identifier = new.to_owned();
            } else {
                // The deleted target stays in the response (PHP keeps it in memory).
                db.delete("targets", &target_id).await?;
            }
        }
        None if !new.is_empty() => {
            if let Some(t) = base::create_target(&db, user, provider_type, new).await? {
                user.targets.push(t);
            }
        }
        None => {}
    }
    db.purge_cached_document("users", &user.id).await;
    Ok(())
}
