//! Password recovery: `/v1/account/recovery` (a link) and
//! `/v1/account/recovery/otp` (a code), created and confirmed.

use serde_json::{Map, Value as Json};
use utopia_database::{Document, Query, Value};
use utopia_emails::EmailValidator;
use utopia_http::Response;
use utopia_validators::{AllOf, Boolean, Text, Validator, php};

use appwrite_core::database::documents::{self, Token, User};
use appwrite_core::datetime;
use appwrite_core::platform::{Context, Route};
use appwrite_core::response::TokenModel;
use appwrite_core::validators::{PasswordDictionary, PasswordStrength, uid};
use appwrite_core::{Error, ErrorType, Result};

use crate::base::profile as base;
use crate::{abuse, audit};

const SESSIONS_WRITE: &[&str] = &["sessions.write"];

pub fn routes() -> Vec<Route> {
    vec![
        profile_route!(
            POST,
            "/v1/account/recovery",
            "createRecovery",
            "createRecovery",
            SESSIONS_WRITE,
            Some("users.[userId].recovery.[tokenId].create"),
            audit("recovery.create", "user/{response.userId}"),
            create,
            crate::GROUPS,
            abuse(&["url:{url},email:{param-email}", "url:{url},ip:{ip}"])
        ),
        profile_route!(
            PUT,
            "/v1/account/recovery",
            "updateRecovery",
            "updateRecovery",
            SESSIONS_WRITE,
            Some("users.[userId].recovery.[tokenId].update"),
            audit("recovery.update", "user/{response.userId}"),
            update,
            crate::GROUPS,
            abuse(&["url:{url},userId:{param-userId}"])
        ),
        profile_route!(
            POST,
            "/v1/account/recovery/otp",
            "createRecoveryOTP",
            "createRecoveryOTP",
            SESSIONS_WRITE,
            Some("users.[userId].recovery.[tokenId].create"),
            audit("recovery.create", "user/{response.userId}"),
            create_otp,
            crate::GROUPS,
            abuse(&["url:{url},email:{param-email}", "url:{url},ip:{ip}"])
        ),
        profile_route!(
            PUT,
            "/v1/account/recovery/otp",
            "updateRecoveryOTP",
            "updateRecoveryOTP",
            SESSIONS_WRITE,
            Some("users.[userId].recovery.[tokenId].update"),
            audit("recovery.update", "user/{response.userId}"),
            update_otp,
            crate::GROUPS,
            abuse(&["url:{url},userId:{param-userId}"])
        ),
    ]
}

/// `SMTP Disabled` unless `_APP_SMTP_HOST` is set.
pub(crate) fn require_smtp(ctx: &Context) -> Result<()> {
    if ctx.state.config.smtp_host.is_empty() {
        return Err(Error::with_message(ErrorType::GeneralSmtpDisabled, "SMTP Disabled"));
    }
    Ok(())
}

/// The new password's validator (`PasswordStrength` and `PasswordDictionary`).
fn password_param(ctx: &Context) -> Result<String> {
    let state = ctx.state.clone();
    let validator = AllOf::new(vec![
        Box::new(PasswordStrength::from_policy(ctx.project.auth("passwordStrength"), false)) as Box<dyn Validator + '_>,
        Box::new(PasswordDictionary {
            dictionary: &state.dictionary,
            enabled: ctx.project.auth_bool("passwordDictionary", false),
            allow_empty: false,
        }),
    ]);
    ctx.required_str("password", &validator)
}

/// The account a recovery is for: the user with that email, unless blocked.
struct Recovery {
    profile: Option<User>,
    user_id: String,
    token: Document,
    secret: String,
}

/// The recovery token of `kind` for `email` (deleted again when no account
/// can receive it, so the response does not tell).
async fn recovery(ctx: &mut Context, email: &str, kind: i64, secret: String, expire: &Value) -> Result<Recovery> {
    let db = ctx.db()?;
    let skipped = base::skipped(&[]);
    let profile =
        db.skip_filters(Some(skipped.as_slice()), db.find_one("users", vec![Query::equal("email", [email])])).await?;
    let profile = (!profile.is_empty()).then(|| User::from(&profile)).filter(|p| p.status != Some(false));
    let unique = appwrite_core::database::unique_id;
    let (user_id, internal_id) = match &profile {
        Some(p) => (p.id.clone(), documents::internal_id(p.sequence)),
        None => (unique(), Value::from(unique().as_str())),
    };
    let token = base::new_token(ctx, &user_id, internal_id, kind, &secret, expire)?;
    let token = base::create_token(ctx, &user_id, token).await?;
    match &profile {
        Some(p) => {
            db.purge_cached_document("users", &p.id).await?;
        }
        None => {
            let authorization = db.authorization();
            authorization.skip(db.delete_document("tokens", token.id())).await?;
        }
    }
    Ok(Recovery { profile, user_id, token, secret })
}

/// The events and the response of a created recovery.
fn created(ctx: &mut Context, recovery: Recovery, phrase: Option<String>) -> Response {
    let mut token = Token::from(&recovery.token);
    token.secret = Some(recovery.secret.clone());
    token.phrase = phrase;
    ctx.event_param("userId", recovery.user_id.clone());
    ctx.event_param("tokenId", token.id.clone());
    ctx.events.actor = Some(match &recovery.profile {
        Some(p) => p.message_payload(),
        None => appwrite_core::event::empty_user(),
    });
    ctx.events.payload = Some(base::token_payload(&token));
    ctx.events.sensitive = vec!["secret"];
    // The secret proves control of the inbox; only keys that can already
    // mint tokens through `users.write` read it back.
    let hidden = ctx.key.as_ref().is_some_and(|k| !k.scopes.iter().any(|s| s == "users.write"));
    let secret = if hidden { "" } else { recovery.secret.as_str() };
    ctx.created(&TokenModel { token: &token, secret: Some(secret), phrase: "", reveal: false, render: ctx.render() })
}

/// `POST /v1/account/recovery`.
pub async fn create(ctx: &mut Context) -> Result<Response> {
    let email = ctx.required_str("email", &EmailValidator::default())?;
    let url = ctx.required_str("url", &base::Redirect::new(ctx))?;
    require_smtp(ctx)?;
    let email = email.to_lowercase();

    let expire = datetime::add_seconds(datetime::now(), base::TOKEN_EXPIRATION_RECOVERY);
    let expire_text = datetime::format_tz(&expire);
    let secret = utopia_auth::proofs::Token::default().token();
    let recovery = recovery(ctx, &email, base::TOKEN_TYPE_RECOVERY, secret, &datetime::value(&expire)).await?;

    let url = appwrite_core::template::with_query(
        &url,
        &[("userId", &recovery.user_id), ("secret", &recovery.secret), ("expire", &expire_text)],
    );
    let inner = base::template_file(ctx, "email-inner-base.tpl")
        .await?
        .param("{{body}}", &ctx.text("emails.recovery.body", None), false)
        .param("{{hello}}", &ctx.text("emails.recovery.hello", None), true)
        .param("{{footer}}", &ctx.text("emails.recovery.footer", None), true)
        .param("{{thanks}}", &ctx.text("emails.recovery.thanks", None), true)
        .param("{{buttonText}}", &ctx.text("emails.recovery.buttonText", None), true)
        .param("{{signature}}", &ctx.text("emails.recovery.signature", None), true);

    if let Some(profile) = &recovery.profile {
        let mut variables = Map::new();
        variables.insert("direction".into(), Json::String(ctx.text("settings.direction", None)));
        variables.insert("user".into(), profile.name.clone().map(Json::String).unwrap_or(Json::Null));
        variables.insert("redirect".into(), Json::String(url));
        variables.insert("project".into(), Json::String(base::project_name(ctx)));
        variables.insert("team".into(), Json::String(String::new()));
        base::branded_variables(ctx, &mut variables);
        base::queue_mail(
            ctx,
            base::Mail {
                key: "recovery",
                template: "recovery",
                recipient: profile.email.clone().unwrap_or_default(),
                name: profile.name.clone().unwrap_or_default(),
                subject: ctx.text("emails.recovery.subject", None),
                preview: ctx.text("emails.recovery.preview", None),
                body: inner.render(),
                variables,
            },
        );
    }
    Ok(created(ctx, recovery, None))
}

/// `POST /v1/account/recovery/otp`.
pub async fn create_otp(ctx: &mut Context) -> Result<Response> {
    let email = ctx.required_str("email", &EmailValidator::default())?;
    let phrase = php::truthy(&ctx.optional("phrase", Json::Bool(false), &Boolean::STRICT)?);
    require_smtp(ctx)?;
    let email = email.to_lowercase();
    let phrase = phrase.then(|| utopia_auth::proofs::Phrase::new().phrase());

    let secret = utopia_auth::proofs::Code::default().code();
    let expire = datetime::add_seconds(datetime::now(), base::TOKEN_EXPIRATION_OTP);
    let recovery = recovery(ctx, &email, base::TOKEN_TYPE_RECOVERY_OTP, secret, &datetime::value(&expire)).await?;

    if let Some(profile) = &recovery.profile {
        let detected = appwrite_core::detector::detect(&ctx.user_agent("UNKNOWN"));
        let message = base::template_file(ctx, "email-otp.tpl")
            .await?
            .param("{{hello}}", &ctx.text("emails.otpRecovery.hello", None), true)
            .param("{{description}}", &ctx.text("emails.otpRecovery.description", None), true)
            .param("{{clientInfo}}", &ctx.text("emails.otpRecovery.clientInfo", None), true)
            .param("{{thanks}}", &ctx.text("emails.otpRecovery.thanks", None), true)
            .param("{{signature}}", &ctx.text("emails.otpRecovery.signature", None), true);
        let security =
            if phrase.is_some() { ctx.text("emails.otpRecovery.securityPhrase", None) } else { String::new() };
        let message = message.param("{{securityPhrase}}", &security, true);

        let mut variables = Map::new();
        variables.insert("heading".into(), Json::String(ctx.text("emails.otpRecovery.heading", None)));
        variables.insert("direction".into(), Json::String(ctx.text("settings.direction", None)));
        variables.insert("user".into(), profile.name.clone().map(Json::String).unwrap_or(Json::Null));
        variables.insert("project".into(), Json::String(base::project_name(ctx)));
        variables.insert("otp".into(), Json::String(recovery.secret.clone()));
        variables.insert("agentDevice".into(), Json::String(detected.device_brand.unwrap_or_else(|| "UNKNOWN".into())));
        variables.insert("agentClient".into(), Json::String(detected.client_name));
        variables.insert("agentOs".into(), Json::String(detected.os_name));
        variables.insert("phrase".into(), Json::String(phrase.clone().unwrap_or_default()));
        variables.insert("team".into(), Json::String(String::new()));
        base::branded_variables(ctx, &mut variables);
        base::queue_mail(
            ctx,
            base::Mail {
                key: "otpRecovery",
                template: "otp-recovery",
                recipient: profile.email.clone().unwrap_or_default(),
                name: profile.name.clone().unwrap_or_default(),
                subject: ctx.text("emails.otpRecovery.subject", None),
                preview: ctx.text("emails.otpRecovery.preview", None),
                body: message.render(),
                variables,
            },
        );
    }
    Ok(created(ctx, recovery, phrase))
}

/// `PUT /v1/account/recovery`.
pub async fn update(ctx: &mut Context) -> Result<Response> {
    confirm(ctx, base::TOKEN_TYPE_RECOVERY).await
}

/// `PUT /v1/account/recovery/otp`.
pub async fn update_otp(ctx: &mut Context) -> Result<Response> {
    confirm(ctx, base::TOKEN_TYPE_RECOVERY_OTP).await
}

/// A recovery confirmed with its secret: the new password, the email
/// verified, every session and challenge of the user gone.
async fn confirm(ctx: &mut Context, kind: i64) -> Result<Response> {
    let user_id = ctx.required_str("userId", &uid())?;
    let secret = ctx.required_str("secret", &Text::new(256))?;
    let password = password_param(ctx)?;

    let db = ctx.db()?;
    let document = base::user_document(&db, &user_id, &[base::TOKENS, base::SESSIONS]).await?;
    if document.is_empty() {
        return Err(Error::new(ErrorType::UserNotFound));
    }
    let Some(verified) = base::token_verify(&document, kind, &secret) else {
        return Err(Error::new(ErrorType::UserInvalidToken));
    };
    let profile = User::from(&document);
    ctx.authorization.add_role(&format!("user:{}", profile.id));

    let hashed = base::hash_password(password.clone()).await?;
    let limit = ctx.project.auth_int("passwordHistory", 0);
    let mut history = profile.password_history.clone();
    if limit > 0 {
        if base::recently_used(&profile, &history, &password).await? {
            return Err(Error::new(ErrorType::UserPasswordRecentlyUsed));
        }
        history.push(hashed.clone());
        let limit = limit as usize;
        if history.len() > limit {
            history.drain(..history.len() - limit);
        }
    }
    let pwned = base::pwned(ctx, &password).await?;
    ctx.state.hooks.password_validator(ctx, &password, ctx.user.as_ref()).await?;

    // The token is claimed and the password set together: of concurrent
    // confirmations only the one that deletes the token changes it.
    let token_id = verified.id().to_owned();
    let claimed = db
        .with_transaction(|| {
            let db = db.clone();
            let token_id = token_id.clone();
            let profile_id = profile.id.clone();
            let mut updates = Document::default();
            base::password_updates(&mut updates, &hashed, &history, pwned);
            updates.put("emailVerification", Value::Bool(true));
            async move {
                let token = db.get_document("tokens", &token_id, Vec::new(), false).await?;
                if !db.delete_document("tokens", &token_id).await? {
                    return Ok(None);
                }
                let skipped = base::skipped(&[]);
                db.skip_filters(Some(skipped.as_slice()), db.update_document("users", &profile_id, updates)).await?;
                Ok(Some(token))
            }
        })
        .await?;
    let Some(token) = claimed else {
        return Err(Error::new(ErrorType::UserInvalidToken));
    };

    // The user as stored now, with the sessions it had.
    let reloaded = base::user_document(&db, &profile.id, &[]).await?;
    let mut updated = User::from(&reloaded);
    updated.sessions = profile.sessions.clone();
    if ctx.project.auth_bool("invalidateSessions", true) {
        base::invalidate_authentication(&db, &updated, None).await?;
    }
    db.purge_cached_document("users", &updated.id).await?;

    let token = Token::from(&token);
    ctx.event_param("userId", updated.id.clone());
    ctx.event_param("tokenId", token.id.clone());
    ctx.events.actor = Some(updated.message_payload());
    ctx.events.payload = Some(base::token_payload(&token));
    ctx.events.sensitive = vec!["secret"];
    Ok(ctx.ok(&TokenModel { token: &token, secret: None, phrase: "", reveal: false, render: ctx.render() }))
}
