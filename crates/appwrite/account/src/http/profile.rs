//! The account itself: `GET/DELETE /v1/account`, its status, name, email,
//! phone, password and preferences.

use serde_json::Value as Json;
use utopia_database::{Database, Document, Query, Value};
use utopia_emails::EmailValidator;
use utopia_http::{Cookie, Response};
use utopia_validators::{AllOf, Assoc, Text, Validator};

use appwrite_core::database::documents::{self, User};
use appwrite_core::platform::{Context, Route};
use appwrite_core::response::{UserModel, prefs_value};
use appwrite_core::validators::{Password, PasswordDictionary, PasswordStrength, Phone};
use appwrite_core::{Error, ErrorType, Result};

use crate::base::profile as base;
use crate::{ACCOUNT, abuse, audit};

pub fn routes() -> Vec<Route> {
    vec![
        profile_route!(GET, "/v1/account", "get", "get", ACCOUNT, None, None, get),
        profile_route!(
            DELETE,
            "/v1/account",
            "delete",
            "delete",
            ACCOUNT,
            None,
            audit("user.delete", "user/{user.$id}"),
            delete
        ),
        profile_route!(
            PATCH,
            "/v1/account/status",
            "updateStatus",
            "updateStatus",
            ACCOUNT,
            Some("users.[userId].update.status"),
            audit("user.update", "user/{response.$id}"),
            status
        ),
        profile_route!(
            PATCH,
            "/v1/account/name",
            "updateName",
            "updateName",
            ACCOUNT,
            Some("users.[userId].update.name"),
            audit("user.update", "user/{response.$id}"),
            name
        ),
        profile_route!(
            PATCH,
            "/v1/account/password",
            "updatePassword",
            "updatePassword",
            ACCOUNT,
            Some("users.[userId].update.password"),
            audit("user.update", "user/{response.$id}"),
            password,
            crate::GROUPS,
            abuse(&["url:{url},ip:{ip}"])
        ),
        profile_route!(
            PATCH,
            "/v1/account/email",
            "updateEmail",
            "updateEmail",
            ACCOUNT,
            Some("users.[userId].update.email"),
            audit("user.update", "user/{response.$id}"),
            email
        ),
        profile_route!(
            PATCH,
            "/v1/account/phone",
            "updatePhone",
            "updatePhone",
            ACCOUNT,
            Some("users.[userId].update.phone"),
            audit("user.update", "user/{response.$id}"),
            phone
        ),
        profile_route!(GET, "/v1/account/prefs", "getPrefs", "getPrefs", ACCOUNT, None, None, get_prefs),
        profile_route!(
            PATCH,
            "/v1/account/prefs",
            "updatePrefs",
            "updatePrefs",
            ACCOUNT,
            Some("users.[userId].update.prefs"),
            audit("user.update", "user/{response.$id}"),
            update_prefs
        ),
    ]
}

/// The `targetUser` resource: the impersonated user, else the session's.
fn target_user(ctx: &Context) -> Result<User> {
    ctx.roles_user().cloned().ok_or_else(|| Error::new(ErrorType::UserNotFound))
}

/// `GET /v1/account`.
pub async fn get(ctx: &mut Context) -> Result<Response> {
    let user = target_user(ctx)?;
    Ok(base::account(ctx, &user))
}

/// `DELETE /v1/account`.
pub async fn delete(ctx: &mut Context) -> Result<Response> {
    let user = target_user(ctx)?;
    let db = ctx.db()?;
    // The user as the request read it (`targetUser`, every sub-query): the
    // deletes worker gets the whole document.
    let document = base::user_document(&db, &user.id, filters_all()).await?;

    // A failure rolls back to an intact account to retry; the rest is
    // queued only once these rows are gone.
    let authorization = db.authorization();
    let skipped = base::skipped(&[]);
    authorization
        .skip(|| {
            db.with_transaction(|| {
                let db = db.clone();
                let id = user.id.clone();
                let skipped = skipped.clone();
                let sequence = user.sequence;
                async move {
                    db.skip_filters(|| db.delete_document("users", &id), Some(&skipped)).await?;
                    to_db(base::delete_user_identities(&db, sequence).await)?;
                    to_db(base::delete_user_targets(&db, sequence).await)?;
                    db.delete_documents(
                        "sessions",
                        vec![Query::equal("userInternalId", [documents::internal_id(sequence)])],
                        Database::DELETE_BATCH_SIZE,
                        None,
                        None,
                    )
                    .await?;
                    Ok(())
                }
            })
        })
        .await?;
    // The delete's own purge ran inside the transaction.
    db.purge_cached_document("users", Some(&user.id)).await?;

    base::mqtt_revoke(ctx, &user.id).await;

    let payload = Value::Array(document.array_copy(&[], &[])).to_json();
    let message = appwrite_core::event::delete_message(&ctx.project.message_payload(), "document", payload);
    ctx.events.deletes.push(message);
    ctx.event_param("userId", user.id.clone());
    let render = ctx.render();
    ctx.event_payload(&UserModel { user: &user, render });
    Ok(ctx.no_content())
}

/// Every user sub-query.
fn filters_all() -> &'static [&'static str] {
    appwrite_core::database::filters::USERS_SUBQUERIES
}

/// An account error inside a database transaction.
fn to_db(result: Result<()>) -> utopia_database::Result<()> {
    result.map_err(|e| utopia_database::Error::new(utopia_database::Kind::Exception, e.message.into_owned()))
}

/// `PATCH /v1/account/status`.
pub async fn status(ctx: &mut Context) -> Result<Response> {
    let user = base::session_user(ctx)?;
    let db = ctx.db()?;
    let mut updates = Document::default();
    updates.set("status", Value::Bool(false));
    let user = base::update_user(&db, &user.id, updates, &[base::TARGETS]).await?;

    base::mqtt_revoke(ctx, &user.id).await;

    ctx.event_param("userId", user.id.clone());
    let payload = base::account_payload(ctx, &user);
    ctx.events.payload = Some(payload);

    let mut response = base::account(ctx, &user);
    if !base::domain_verification(ctx) {
        response.add("x-fallback-cookies", "[]");
    }
    let secure = ctx.request.protocol() == "https";
    let domain = base::cookie_domain(ctx);
    let name = base::cookie_name(ctx);
    let expire = Some(now_unix() - 3600);
    response.add_cookie(Cookie {
        name: format!("{name}_legacy"),
        value: Some(String::new()),
        expire,
        path: Some("/".to_owned()),
        domain: domain.clone(),
        secure: Some(secure),
        http_only: Some(true),
        same_site: None,
    });
    response.add_cookie(Cookie {
        name,
        value: Some(String::new()),
        expire,
        path: Some("/".to_owned()),
        domain,
        secure: Some(secure),
        http_only: Some(true),
        same_site: Some("None".to_owned()),
    });
    Ok(response)
}

fn now_unix() -> i64 {
    appwrite_core::datetime::now().and_utc().timestamp()
}

/// `PATCH /v1/account/name`.
pub async fn name(ctx: &mut Context) -> Result<Response> {
    let name = ctx.required_str("name", &Text::new(128))?;
    let user = base::session_user(ctx)?;
    let db = ctx.db()?;
    let mut updates = Document::default();
    updates.set("name", Value::from(name.as_str()));
    let user = base::update_user(&db, &user.id, updates, &[base::TARGETS]).await?;
    ctx.event_param("userId", user.id.clone());
    Ok(base::account(ctx, &user))
}

/// `PATCH /v1/account/password`.
pub async fn password(ctx: &mut Context) -> Result<Response> {
    let state = ctx.state.clone();
    let validator = AllOf::new(vec![
        Box::new(PasswordStrength::from_policy(ctx.project.auth("passwordStrength"), false)) as Box<dyn Validator + '_>,
        Box::new(PasswordDictionary {
            dictionary: &state.dictionary,
            enabled: ctx.project.auth_bool("passwordDictionary", false),
            allow_empty: false,
        }),
    ]);
    let password = ctx.required_str("password", &validator)?;
    drop(validator);
    let old = ctx.optional_str("oldPassword", "", &Text::with_min(256, 0))?.unwrap_or_default();
    let user = base::session_user(ctx)?;

    // Check the old password only for a user who has one.
    if user.password_update.is_some() && !base::verify_password(&user, &old).await? {
        return Err(Error::new(ErrorType::UserInvalidCredentials));
    }

    let hashed = base::hash_password(password.clone()).await?;
    let limit = ctx.project.auth_int("passwordHistory", 0);
    let mut history = user.password_history.clone();
    if limit > 0 {
        if base::recently_used(&user, &history, &password).await? {
            return Err(Error::new(ErrorType::UserPasswordRecentlyUsed));
        }
        history.push(hashed.clone());
        let limit = limit as usize;
        if history.len() > limit {
            history.drain(..history.len() - limit);
        }
    }

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

    let pwned = base::pwned(ctx, &password).await?;
    ctx.state.hooks.password_validator(ctx, &password, Some(&user)).await?;

    let db = ctx.db()?;
    if ctx.project.auth_bool("invalidateSessions", true)
        && let Some(current) = ctx.session.as_ref()
    {
        base::invalidate_authentication(&db, &user, Some(&current.id)).await?;
    }

    let mut updates = Document::default();
    base::password_updates(&mut updates, &hashed, &history, pwned);
    let user = base::update_user(&db, &user.id, updates, &[base::TARGETS]).await?;
    ctx.event_param("userId", user.id.clone());
    Ok(base::account(ctx, &user))
}

/// The policies a first password meets (an anonymous account setting its
/// email or phone): strength, dictionary, personal data and breaches.
async fn first_password(
    ctx: &Context,
    user: &User,
    password: &str,
    email: Option<&str>,
    phone: Option<&str>,
) -> Result<Option<bool>> {
    let value = Json::String(password.to_owned());
    let strength = PasswordStrength::from_policy(ctx.project.auth("passwordStrength"), false);
    if !strength.is_valid(&value) {
        return Err(Error::invalid_param("password", &strength.description()));
    }
    let dictionary = PasswordDictionary {
        dictionary: &ctx.state.dictionary,
        enabled: ctx.project.auth_bool("passwordDictionary", false),
        allow_empty: false,
    };
    if !dictionary.is_valid(&value) {
        return Err(Error::invalid_param("password", &dictionary.description()));
    }
    if ctx.project.auth_bool("personalDataCheck", false)
        && !appwrite_core::validators::personal_data_ok(
            Some(password),
            &user.id,
            email.or(user.email.as_deref()),
            user.name.as_deref(),
            phone.or(user.phone.as_deref()),
            false,
        )
    {
        return Err(Error::new(ErrorType::UserPasswordPersonalData));
    }
    base::pwned(ctx, password).await
}

/// The first password's attributes, for a user who has none.
async fn set_first_password(ctx: &Context, updates: &mut Document, password: &str, pwned: Option<bool>) -> Result<()> {
    let hashed = base::hash_password(password.to_owned()).await?;
    let history = if ctx.project.auth_int("passwordHistory", 0) > 0 { vec![hashed.clone()] } else { Vec::new() };
    base::password_updates(updates, &hashed, &history, pwned);
    Ok(())
}

/// The account's checks before its email or phone changes: the current
/// password, or the first password's policies.
async fn check_password(
    ctx: &Context,
    user: &User,
    password: &str,
    email: Option<&str>,
    phone: Option<&str>,
) -> Result<Option<bool>> {
    if user.password_update.is_some() && !base::verify_password(user, password).await? {
        return Err(Error::new(ErrorType::UserInvalidCredentials));
    }
    let pwned =
        if user.password_update.is_none() { first_password(ctx, user, password, email, phone).await? } else { None };
    ctx.state.hooks.password_validator(ctx, password, Some(user)).await?;
    Ok(pwned)
}

/// The target with `old` as its identifier gets `new` (in the response's
/// targets too, as PHP changes the document it holds).
async fn move_target(db: &Database, user: &mut User, old: Option<&str>, new: &str) -> Result<()> {
    let Some(old) = old else { return Ok(()) };
    if let Some(target) = user.targets.iter_mut().find(|t| t.identifier == old) {
        let mut updates = Document::default();
        updates.set("identifier", Value::from(new));
        let authorization = db.authorization();
        authorization.skip(|| db.update_document("targets", &target.id, updates)).await?;
        target.identifier = new.to_owned();
    }
    Ok(())
}

/// `findOne('targets', [equal('identifier', [$identifier])])`, skipping
/// authorization.
async fn target_exists(db: &Database, identifier: &str) -> Result<bool> {
    let authorization = db.authorization();
    let target = authorization.skip(|| db.find_one("targets", vec![Query::equal("identifier", [identifier])])).await?;
    Ok(!target.is_empty())
}

/// `PATCH /v1/account/email`.
pub async fn email(ctx: &mut Context) -> Result<Response> {
    let email = ctx.required_str("email", &EmailValidator::default())?;
    let password = ctx.required_str("password", &Password::default())?;
    let user = base::session_user(ctx)?;
    let pwned = check_password(ctx, &user, &password, Some(&email), None).await?;

    let old_email = user.email.clone();
    let email = email.to_lowercase();
    let db = ctx.db()?;

    // A generic error: an existing account is not exposed.
    let identity = db
        .find_one(
            "identities",
            vec![
                Query::equal("providerEmail", [email.as_str()]),
                Query::not_equal("userInternalId", documents::internal_id(user.sequence)),
            ],
        )
        .await?;
    if !identity.is_empty() {
        return Err(Error::new(ErrorType::GeneralBadRequest));
    }

    let meta = base::Metadata::of(&email).ok_or_else(|| Error::new(ErrorType::GeneralInvalidEmail))?;
    base::check_email_policy(ctx, &meta)?;

    let mut updates = Document::default();
    updates.set("email", Value::from(email.as_str()));
    updates.set("emailVerification", Value::Bool(false));
    meta.apply(&mut updates);
    if user.password_update.is_none() {
        set_first_password(ctx, &mut updates, &password, pwned).await?;
    }

    if target_exists(&db, &email).await? {
        return Err(Error::new(ErrorType::UserTargetAlreadyExists));
    }

    let result = async {
        let mut updated = base::update_user(&db, &user.id, updates, &[base::TARGETS]).await?;
        move_target(&db, &mut updated, old_email.as_deref(), &email).await?;
        db.purge_cached_document("users", Some(&updated.id)).await?;
        Ok::<User, Error>(updated)
    }
    .await;
    let user = result.map_err(|e| duplicate_as(e, ErrorType::GeneralBadRequest))?;
    ctx.event_param("userId", user.id.clone());
    Ok(base::account(ctx, &user))
}

/// `PATCH /v1/account/phone`.
pub async fn phone(ctx: &mut Context) -> Result<Response> {
    let phone = ctx.required_str("phone", &Phone::default())?;
    let password = ctx.required_str("password", &Password::default())?;
    let user = base::session_user(ctx)?;
    let pwned = check_password(ctx, &user, &password, None, Some(&phone)).await?;

    let db = ctx.db()?;
    if target_exists(&db, &phone).await? {
        return Err(Error::new(ErrorType::UserTargetAlreadyExists));
    }
    let old_phone = user.phone.clone();

    let mut updates = Document::default();
    updates.set("phone", Value::from(phone.as_str()));
    updates.set("phoneVerification", Value::Bool(false));
    if user.password_update.is_none() {
        set_first_password(ctx, &mut updates, &password, pwned).await?;
    }

    let result = async {
        let mut updated = base::update_user(&db, &user.id, updates, &[base::TARGETS]).await?;
        move_target(&db, &mut updated, old_phone.as_deref(), &phone).await?;
        db.purge_cached_document("users", Some(&updated.id)).await?;
        Ok::<User, Error>(updated)
    }
    .await;
    let user = result.map_err(|e| duplicate_as(e, ErrorType::UserPhoneAlreadyExists))?;
    ctx.event_param("userId", user.id.clone());
    Ok(base::account(ctx, &user))
}

/// PHP catches `Duplicate` around the update and the target's.
fn duplicate_as(error: Error, kind: ErrorType) -> Error {
    if error.kind == ErrorType::DocumentAlreadyExists { Error::new(kind) } else { error }
}

/// `GET /v1/account/prefs`.
pub async fn get_prefs(ctx: &mut Context) -> Result<Response> {
    let prefs = ctx.roles_user().and_then(|u| u.prefs.clone());
    Ok(ctx.ok(&prefs_value(&prefs)))
}

/// `PATCH /v1/account/prefs`.
pub async fn update_prefs(ctx: &mut Context) -> Result<Response> {
    let prefs = ctx.required("prefs", &Assoc::default())?;
    let user = base::session_user(ctx)?;
    let db = ctx.db()?;
    let mut updates = Document::default();
    // The request's value: the `json` filter encodes it as PHP does.
    updates.set("prefs", Value::from_json(&prefs));
    let user = base::update_user(&db, &user.id, updates, &[base::TARGETS]).await?;
    ctx.event_param("userId", user.id.clone());
    Ok(base::account(ctx, &user))
}
