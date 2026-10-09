//! Multi-factor authentication management.

use serde_json::Value;
use utopia_database::{Param, datetime};
use utopia_http::Response;
use utopia_validators::{Boolean, Text, WhiteList, php};

use appwrite_core::crypto;
use appwrite_core::database::documents::{Challenge, Relations, User};
use appwrite_core::platform::Context;
use appwrite_core::response::{MfaChallengeSecretModel, MfaFactorsModel, MfaRecoveryCodesModel, UserModel};
use appwrite_core::validators::uid;
use appwrite_core::{Error, ErrorType, Result};

use crate::base;

/// `PATCH /v1/users/:userId/mfa`.
pub async fn update(ctx: &mut Context) -> Result<Response> {
    let user_id = ctx.required_str("userId", &uid())?;
    let mfa = php::truthy(&ctx.required("mfa", &Boolean::STRICT)?);
    let mut user = base::user_or_404(ctx, &user_id, Relations::NONE).await?;
    user.mfa = Some(mfa);
    let user = base::update_user(ctx, &user, vec![("mfa", Param::Bool(mfa))]).await?;
    ctx.event_param("userId", user.id.clone());
    Ok(ctx.ok(&UserModel { user: &user, render: ctx.render() }))
}

/// `GET /v1/users/:userId/mfa/factors`.
pub async fn factors(ctx: &mut Context) -> Result<Response> {
    let user_id = ctx.required_str("userId", &uid())?;
    let rel = Relations { authenticators: true, ..Relations::NONE };
    let user = base::user_or_404(ctx, &user_id, rel).await?;
    let factors = ctx.project.auth("mfaFactors").cloned().unwrap_or(Value::Null);
    let flag = |k: &str, d: bool| factors.get(k).map(php::truthy).unwrap_or(d);
    let totp = user.authenticators.iter().find(|a| a.kind.as_deref() == Some("totp"));
    let truthy_str = |s: &Option<String>| s.as_deref().map(|v| !v.is_empty() && v != "0").unwrap_or(false);
    let model = MfaFactorsModel {
        totp: flag("totp", true) && totp.map(|t| t.verified == Some(true)).unwrap_or(false),
        phone: flag("phone", true) && truthy_str(&user.phone) && user.phone_verification == Some(true),
        email: flag("email", true) && truthy_str(&user.email) && user.email_verification == Some(true),
        recovery_code: !user.mfa_recovery_codes.is_empty(),
        custom: factors.get("custom").cloned().unwrap_or(Value::Bool(false)),
    };
    Ok(ctx.ok(&model))
}

/// `GET /v1/users/:userId/mfa/challenges/:challengeId`.
pub async fn challenge(ctx: &mut Context) -> Result<Response> {
    let user_id = ctx.required_str("userId", &uid())?;
    let challenge_id = ctx.required_str("challengeId", &Text::new(256))?;
    let user = base::user_or_404(ctx, &user_id, Relations::NONE).await?;
    let db = ctx.db()?;
    let challenge = db.get::<Challenge>("challenges", &challenge_id).await?;
    let now = datetime::now();
    let valid = matches!(&challenge, Some(c) if c.user_id == user.id
        && c.kind.as_deref() == Some("custom")
        && c.expire.map(|e| e >= now).unwrap_or(false));
    let Some(challenge) = challenge.filter(|_| valid) else {
        return Err(Error::new(ErrorType::UserInvalidToken));
    };
    Ok(ctx.ok(&MfaChallengeSecretModel { challenge: &challenge }))
}

/// `GET /v1/users/:userId/mfa/recovery-codes`.
pub async fn get_recovery_codes(ctx: &mut Context) -> Result<Response> {
    let user_id = ctx.required_str("userId", &uid())?;
    let user = base::user_or_404(ctx, &user_id, Relations::NONE).await?;
    if user.mfa_recovery_codes.is_empty() {
        return Err(Error::new(ErrorType::UserRecoveryCodesNotFound));
    }
    Ok(ctx.ok(&MfaRecoveryCodesModel { recovery_codes: &user.mfa_recovery_codes }))
}

async fn store_codes(ctx: &mut Context, user: &User, codes: &[String]) -> Result<()> {
    let encrypted: Vec<String> = codes.iter().map(|c| crypto::encrypt_env(c)).collect();
    base::update_user(ctx, user, vec![("mfaRecoveryCodes", Param::string_list(&encrypted))]).await?;
    ctx.event_param("userId", user.id.clone());
    Ok(())
}

/// `PATCH /v1/users/:userId/mfa/recovery-codes` (creates; responds 201).
pub async fn create_recovery_codes(ctx: &mut Context) -> Result<Response> {
    let user_id = ctx.required_str("userId", &uid())?;
    let user = base::user_or_404(ctx, &user_id, Relations::NONE).await?;
    if !user.mfa_recovery_codes.is_empty() {
        return Err(Error::new(ErrorType::UserRecoveryCodesAlreadyExists));
    }
    let codes = utopia_auth::proofs::backup_codes();
    store_codes(ctx, &user, &codes).await?;
    Ok(ctx.created(&MfaRecoveryCodesModel { recovery_codes: &codes }))
}

/// `PUT /v1/users/:userId/mfa/recovery-codes` (regenerates).
pub async fn update_recovery_codes(ctx: &mut Context) -> Result<Response> {
    let user_id = ctx.required_str("userId", &uid())?;
    let user = base::user_or_404(ctx, &user_id, Relations::NONE).await?;
    if user.mfa_recovery_codes.is_empty() {
        return Err(Error::new(ErrorType::UserRecoveryCodesNotFound));
    }
    let codes = utopia_auth::proofs::backup_codes();
    store_codes(ctx, &user, &codes).await?;
    Ok(ctx.ok(&MfaRecoveryCodesModel { recovery_codes: &codes }))
}

/// `DELETE /v1/users/:userId/mfa/authenticators/:type`.
pub async fn delete_authenticator(ctx: &mut Context) -> Result<Response> {
    let user_id = ctx.required_str("userId", &uid())?;
    let _type = ctx.required_str("type", &WhiteList::new(&["totp"]))?;
    let rel = Relations { authenticators: true, ..Relations::NONE };
    let user = base::user_or_404(ctx, &user_id, rel).await?;
    let Some(authenticator) = user.authenticators.iter().find(|a| a.kind.as_deref() == Some("totp")).cloned() else {
        return Err(Error::new(ErrorType::UserAuthenticatorNotFound));
    };
    let db = ctx.db()?;
    db.delete("authenticators", &authenticator.id).await?;
    db.purge_cached_document("users", &user.id).await;
    ctx.event_param("userId", user.id.clone());
    Ok(ctx.no_content())
}
