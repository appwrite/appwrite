//! User attribute updates (`/v1/users/:userId/<attribute>`).

use serde_json::Value;
use utopia_auth::proofs::Password;
use utopia_database::{Document, Kind, Query, Value as DbValue};
use utopia_emails::EmailValidator;
use utopia_http::Response;
use utopia_validators::{AllOf, ArrayList, Boolean, Text, Validator, php};

use appwrite_core::database::documents::{self, User, text};
use appwrite_core::database::filters;
use appwrite_core::datetime;
use appwrite_core::platform::Context;
use appwrite_core::response::UserModel;
use appwrite_core::validators::{PasswordDictionary, PasswordStrength, Phone, uid};
use appwrite_core::{Error, ErrorType, Result};

use crate::base;

async fn respond(ctx: &mut Context, user: &User) -> Result<Response> {
    ctx.event_param("userId", user.id.clone());
    Ok(ctx.ok(&UserModel { user, render: ctx.render() }))
}

/// `getDocument('users', $userId)` then `updateDocument` with one attribute.
async fn update_one(ctx: &mut Context, user_id: &str, key: &str, value: DbValue) -> Result<Response> {
    let user = base::user_or_404(ctx, user_id, &[]).await?;
    let mut updates = Document::default();
    updates.put(key, value);
    let user = base::update_user(ctx, &user.id, updates, &[base::TARGETS]).await?;
    respond(ctx, &user).await
}

/// `PATCH /v1/users/:userId/status`.
pub async fn status(ctx: &mut Context) -> Result<Response> {
    let user_id = ctx.required_str("userId", &uid())?;
    let status = php::truthy(&ctx.required("status", &Boolean::LOOSE)?);
    update_one(ctx, &user_id, "status", DbValue::Bool(status)).await
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
    update_one(ctx, &user_id, "labels", documents::strings(&labels)).await
}

/// `PATCH /v1/users/:userId/impersonator`.
pub async fn impersonator(ctx: &mut Context) -> Result<Response> {
    let user_id = ctx.required_str("userId", &uid())?;
    let value = php::truthy(&ctx.required("impersonator", &Boolean::LOOSE)?);
    update_one(ctx, &user_id, "impersonator", DbValue::Bool(value)).await
}

/// `PATCH /v1/users/:userId/name`.
pub async fn name(ctx: &mut Context) -> Result<Response> {
    let user_id = ctx.required_str("userId", &uid())?;
    let name = ctx.required_str("name", &Text::with_min(128, 0))?;
    update_one(ctx, &user_id, "name", DbValue::from(name.as_str())).await
}

/// `PATCH /v1/users/:userId/verification`.
pub async fn email_verification(ctx: &mut Context) -> Result<Response> {
    let user_id = ctx.required_str("userId", &uid())?;
    let value = php::truthy(&ctx.required("emailVerification", &Boolean::STRICT)?);
    update_one(ctx, &user_id, "emailVerification", DbValue::Bool(value)).await
}

/// `PATCH /v1/users/:userId/verification/phone`.
pub async fn phone_verification(ctx: &mut Context) -> Result<Response> {
    let user_id = ctx.required_str("userId", &uid())?;
    let value = php::truthy(&ctx.required("phoneVerification", &Boolean::STRICT)?);
    update_one(ctx, &user_id, "phoneVerification", DbValue::Bool(value)).await
}

/// `PATCH /v1/users/:userId/password`.
pub async fn password(ctx: &mut Context) -> Result<Response> {
    let user_id = ctx.required_str("userId", &uid())?;
    let state = ctx.state.clone();
    let validator = AllOf::new(vec![
        Box::new(PasswordStrength::from_policy(ctx.project.auth("passwordStrength"), true)) as Box<dyn Validator + '_>,
        Box::new(PasswordDictionary {
            dictionary: &state.dictionary,
            enabled: ctx.project.auth_bool("passwordDictionary", false),
            allow_empty: true,
        }),
    ]);
    let password = ctx.required_str("password", &validator)?;
    drop(validator);

    let user = base::user_or_404(ctx, &user_id, &[]).await?;

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

    // An empty password clears it, ends the user's sessions and answers.
    if password.is_empty() {
        let mut updates = Document::default();
        updates.put("password", DbValue::from(""));
        updates.put("passwordPwned", DbValue::Null);
        updates.put("passwordUpdate", datetime::value(&datetime::now()));
        let user = base::update_user(ctx, &user.id, updates, &[base::TARGETS, base::SESSIONS]).await?;
        if ctx.project.auth_bool("invalidateSessions", true) {
            invalidate_sessions(ctx, &user).await?;
        }
        ctx.db()?.purge_cached_document("users", &user.id).await?;
        ctx.event_param("userId", user.id.clone());
        return Ok(ctx.ok(&UserModel { user: &user, render: ctx.render() }));
    }

    let updated = finish_password(ctx, &user, password, pwned).await?;
    respond(ctx, &updated).await
}

async fn finish_password(ctx: &mut Context, user: &User, password: String, pwned: Option<bool>) -> Result<User> {
    if !password.is_empty() {
        ctx.state.hooks.password_validator(ctx, &password, Some(user)).await?;
    }
    let new_hash = base::hash_password(password.clone()).await?;
    let proof = base::proof();

    let kind = user.hash.clone().unwrap_or_else(|| "argon2".to_owned());
    let options = user.hash_options.clone().unwrap_or(Value::Null);
    let options = match php_std::Value::from_json(&options) {
        php_std::Value::Array(a) => a,
        _ => php_std::Array::new(),
    };
    let current = Password::create_hash(&kind, &options).map_err(|e| Error::internal(e.to_string()))?;

    let limit = ctx.project.auth_int("passwordHistory", 0);
    let mut history = user.password_history.clone();
    if limit > 0 {
        let candidate = password.clone();
        let entries = history.clone();
        let reused = utopia_async::spawn_blocking(move || {
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

    let mut updates = Document::default();
    updates.put("password", DbValue::from(new_hash.as_str()));
    updates.put("passwordHistory", documents::strings(&history));
    updates.put("passwordPwned", pwned.map(DbValue::Bool).unwrap_or(DbValue::Null));
    updates.put("passwordUpdate", datetime::value(&datetime::now()));
    updates.put("hash", DbValue::from(proof.name()));
    updates.put("hashOptions", base::hash_options(proof.as_ref()));
    let updated = base::update_user(ctx, &user.id, updates, &[base::TARGETS, base::SESSIONS]).await?;

    if ctx.project.auth_bool("invalidateSessions", true) {
        invalidate_sessions(ctx, &updated).await?;
    }
    let db = ctx.db()?;
    db.purge_cached_document("users", &updated.id).await?;
    ctx.mqtt_revoke(&updated.id).await;
    Ok(updated)
}

/// `User::invalidateAuthentication`: deletes sessions and challenges.
async fn invalidate_sessions(ctx: &mut Context, user: &User) -> Result<()> {
    let db = ctx.db()?;
    for session in &user.sessions {
        db.delete_document("sessions", &session.id).await?;
    }
    db.delete_documents(
        "challenges",
        vec![Query::equal("userInternalId", [user.sequence.to_string()])],
        utopia_database::database::BATCH_SIZE,
        None,
    )
    .await?;
    Ok(())
}

/// `PATCH /v1/users/:userId/email`.
pub async fn email(ctx: &mut Context) -> Result<Response> {
    let user_id = ctx.required_str("userId", &uid())?;
    let email = ctx.required_str("email", &EmailValidator { allow_empty: true })?.to_lowercase();
    let user = base::user_or_404(ctx, &user_id, &[]).await?;
    let db = ctx.db()?;
    if !email.is_empty() {
        if base::identity_email_taken(&db, &email, Some(user.sequence)).await? {
            return Err(Error::new(ErrorType::UserEmailAlreadyExists));
        }
        if base::target_by_identifier(&db, &email).await?.is_some() {
            return Err(Error::new(ErrorType::UserTargetAlreadyExists));
        }
    }
    let meta = base::Metadata::of(Some(&email));
    base::check_email_policy(ctx, &meta)?;

    let mut updates = Document::default();
    updates.put("email", DbValue::from(email.as_str()));
    updates.put("emailVerification", DbValue::Bool(false));
    meta.apply(&mut updates);

    // Under the user's row lock: the address it had then moves (or loses)
    // its target, and a change of address voids the email-bound tokens
    // sent to the old one.
    let locked = Box::pin(db.with_transaction(|| {
        let db = db.clone();
        let id = user.id.clone();
        let updates = updates.clone();
        let email = email.clone();
        Box::pin(async move {
            let current = db.get_document("users", &id, Vec::new(), true).await?;
            if current.is_empty() {
                return Ok(None);
            }
            let locked_email = documents::Attributes::string(&current, "email");
            let skipped = base::skipped(&[]);
            let mut updated =
                db.skip_filters(Some(skipped.as_slice()), db.update_document("users", &id, updates)).await?;
            let targets = filters::sub_query(&db, base::TARGETS, &updated).await?;
            updated.put("targets", targets);
            let mut user = User::from(&updated);
            let old = locked_email.as_deref().and_then(|l| user.targets.iter().position(|t| t.identifier == l));
            match old {
                Some(index) if !email.is_empty() => {
                    let mut moved = Document::default();
                    moved.put("identifier", DbValue::from(email.as_str()));
                    db.update_document("targets", &user.targets[index].id, moved).await?;
                    user.targets[index].identifier = email.clone();
                }
                // The deleted target stays in the response (PHP keeps it in memory).
                Some(index) => {
                    db.delete_document("targets", &user.targets[index].id).await?;
                }
                None if !email.is_empty() => {
                    let target = db.create_document("targets", base::new_target(&user, "email", &email)).await?;
                    user.targets.push((&target).into());
                }
                None => {}
            }
            if locked_email.as_deref() != Some(email.as_str()) && user.sequence != 0 {
                db.delete_documents(
                    "tokens",
                    vec![
                        Query::equal("userInternalId", [user.sequence.to_string()]),
                        Query::equal("type", EMAIL_BOUND_TOKENS),
                    ],
                    utopia_database::database::BATCH_SIZE,
                    None,
                )
                .await?;
            }
            Ok(Some(user))
        })
    }))
    .await;
    let updated = match locked {
        Ok(Some(updated)) => updated,
        Ok(None) => return Err(Error::new(ErrorType::UserNotFound)),
        Err(e) if e.is_a(Kind::Duplicate) => return Err(Error::new(ErrorType::UserEmailAlreadyExists)),
        Err(e) => return Err(e.into()),
    };
    db.purge_cached_document("users", &updated.id).await?;
    respond(ctx, &updated).await
}

/// The token types bound to the address they were sent to: recovery,
/// recovery OTP, verification, verification OTP, magic URL and email OTP
/// (`TOKEN_TYPE_*`).
const EMAIL_BOUND_TOKENS: [i64; 6] = [3, 11, 2, 10, 5, 9];

/// `PATCH /v1/users/:userId/phone`.
pub async fn phone(ctx: &mut Context) -> Result<Response> {
    let user_id = ctx.required_str("userId", &uid())?;
    let number = ctx.required_str("number", &Phone { allow_empty: true })?;
    let user = base::user_or_404(ctx, &user_id, &[]).await?;
    let db = ctx.db()?;
    let old_phone = user.phone.clone();
    if !number.is_empty() && base::target_by_identifier(&db, &number).await?.is_some() {
        return Err(Error::new(ErrorType::UserTargetAlreadyExists));
    }
    // Null rather than empty, so the unique index allows many users without one.
    let value = if number.is_empty() { None } else { Some(number.as_str()) };
    let mut updates = Document::default();
    updates.put("phone", text(value));
    updates.put("phoneVerification", DbValue::Bool(false));
    let result = async {
        let mut updated = base::update_user(ctx, &user.id, updates, &[base::TARGETS]).await?;
        sync_target(ctx, &mut updated, old_phone.as_deref(), &number, "sms").await?;
        Ok::<User, Error>(updated)
    }
    .await;
    let updated = result.map_err(|e| duplicate_as(e, ErrorType::UserPhoneAlreadyExists))?;
    respond(ctx, &updated).await
}

/// PHP catches `Duplicate` around the update and the target sync.
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
                let mut updates = Document::default();
                updates.put("identifier", DbValue::from(new));
                db.update_document("targets", &target_id, updates).await?;
                // The response keeps the target as loaded, with the new identifier.
                user.targets[index].identifier = new.to_owned();
            } else {
                // The deleted target stays in the response (PHP keeps it in memory).
                db.delete_document("targets", &target_id).await?;
            }
        }
        None if !new.is_empty() => {
            match db.create_document("targets", base::new_target(user, provider_type, new)).await {
                Ok(target) => user.targets.push((&target).into()),
                Err(e) if e.is_a(Kind::Duplicate) => return Err(Error::new(ErrorType::DocumentAlreadyExists)),
                Err(e) => return Err(e.into()),
            }
        }
        None => {}
    }
    db.purge_cached_document("users", &user.id).await?;
    Ok(())
}
