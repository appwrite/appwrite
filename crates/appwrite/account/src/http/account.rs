//! `POST /v1/account`: sign-up with an email and a password.

use serde_json::Value as Json;
use utopia_database::{Query, Value};
use utopia_http::Response;
use utopia_validators::{AllOf, Text, Validator};

use appwrite_core::datetime;
use appwrite_core::platform::{Audit, Context, Labels, Route};
use appwrite_core::response::AccountModel;
use appwrite_core::validators::{CustomId, PasswordDictionary, PasswordStrength};
use appwrite_core::{Error, ErrorType, Result};
use utopia_emails::EmailValidator;

use crate::base::{self, Metadata};
use crate::{GROUPS_AUTH, route};

pub fn routes() -> Vec<Route> {
    vec![route!(
        POST,
        "/v1/account",
        "create",
        "create",
        &["sessions.write"],
        None,
        Some(Audit { event: "user.create", resource: "user/{response.$id}" }),
        GROUPS_AUTH,
        Labels { abuse_limit: 10, auth_type: "email-password", ..Labels::NONE },
        create
    )]
}

/// `EmailWhitelist`: the address is listed, or its domain as `*@domain`.
fn whitelisted(list: &[String], email: &str) -> bool {
    let Ok(parsed) = utopia_emails::Email::new(email) else { return false };
    let (address, domain) = (parsed.get().to_lowercase(), parsed.domain().to_lowercase());
    list.iter().map(|entry| entry.trim()).any(|entry| {
        if !entry.contains('*') {
            return entry.to_lowercase() == address;
        }
        entry.starts_with("*@") && entry.matches('*').count() == 1 && entry[2..].to_lowercase() == domain
    })
}

/// `POST /v1/account`.
pub async fn create(ctx: &mut Context) -> Result<Response> {
    let user_id = ctx.required_str("userId", &CustomId)?;
    let email = ctx.required_str("email", &EmailValidator::default())?;
    let state = ctx.state.clone();
    let password_validator = AllOf::new(vec![
        Box::new(PasswordStrength::from_policy(ctx.project.auth("passwordStrength"), false)) as Box<dyn Validator + '_>,
        Box::new(PasswordDictionary {
            dictionary: &state.dictionary,
            enabled: ctx.project.auth_bool("passwordDictionary", false),
            allow_empty: false,
        }),
    ]);
    let password = ctx.required_str("password", &password_validator)?;
    let name = ctx.optional_str("name", "", &Text::new(128))?.unwrap_or_default();
    let email = email.to_lowercase();

    if ctx.project.is_console() {
        let emails = &ctx.project.auth_whitelist_emails;
        if !emails.is_empty() && !whitelisted(emails, &email) {
            return Err(Error::new(ErrorType::UserEmailNotWhitelisted));
        }
        let ips = &ctx.project.auth_whitelist_ips;
        if !ips.is_empty() && !ips.contains(&ctx.ip) {
            return Err(Error::new(ErrorType::UserIpNotWhitelisted));
        }
    }

    base::check_user_limit(ctx, true).await?;

    // An email already used by an identity: a generic bad request, so the
    // account is not revealed.
    let db = ctx.db()?;
    if !db.find_one("identities", vec![Query::equal("providerEmail", [email.as_str()])]).await?.is_empty() {
        return Err(Error::new(ErrorType::GeneralBadRequest));
    }

    if ctx.project.auth_bool("personalDataCheck", false)
        && !appwrite_core::validators::personal_data_ok(
            Some(&password),
            &user_id,
            Some(&email),
            Some(&name),
            None,
            true,
        )
    {
        return Err(Error::new(ErrorType::UserPasswordPersonalData));
    }

    // `null` when the policy did not look, `false` when it found nothing.
    let policy = ctx.project.auth("passwordPwned").cloned().unwrap_or(Json::Null);
    let pwned = if policy.get("enabled").map(utopia_validators::php::truthy).unwrap_or(true) {
        Some(ctx.state.pwned.is_pwned(&password).await?)
    } else {
        None
    };
    if pwned == Some(true) && policy.get("users").map(utopia_validators::php::truthy).unwrap_or(false) {
        return Err(Error::new(ErrorType::UserPasswordPwned));
    }

    ctx.state.hooks.password_validator(ctx, &password, None).await?;

    let history = ctx.project.auth_int("passwordHistory", 0);
    let hash = base::proof();
    let hashed = base::hash_password(password.clone()).await?;
    let meta = Metadata::of(&email)?;
    base::check_email_policy(ctx, &meta, false)?;

    let user_id = if user_id == "unique()" { appwrite_core::database::unique_id() } else { user_id };
    let mut user = base::new_user(&user_id);
    user.set("email", Value::from(email.as_str()));
    user.set("password", Value::from(hashed.as_str()));
    user.set(
        "passwordHistory",
        if history > 0 {
            utopia_database::value::list([Value::from(hashed.as_str())])
        } else {
            utopia_database::value::list([])
        },
    );
    user.set("passwordUpdate", datetime::value(&datetime::now()));
    user.set("hash", Value::from(hash.name()));
    user.set("hashOptions", base::hash_options(hash.as_ref()));
    user.set("name", Value::from(name.as_str()));
    user.set("mfa", Value::Bool(false));
    user.set("authenticators", Value::Null);
    user.set("search", Value::from(format!("{user_id} {email} {name}").as_str()));
    meta.apply(&mut user);
    user.set("passwordPwned", pwned.map(Value::Bool).unwrap_or(Value::Null));
    let mut user = base::create_user(ctx, user).await?;
    base::act_as(ctx, &user);

    if let Some(target) = base::create_target(ctx, &user, "email", &email).await? {
        user.targets.push(target);
    }
    let db = ctx.db()?;
    db.purge_cached_document("users", Some(&user.id)).await?;

    Ok(ctx.created(&AccountModel { user: &user, impersonator: None, render: ctx.render() }))
}
