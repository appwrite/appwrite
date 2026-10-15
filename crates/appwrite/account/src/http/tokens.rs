//! `/v1/account/tokens`: the tokens a sign-in exchanges for a session (a
//! magic URL, an email or SMS code, an OAuth2 or a passkey sign-in).

use serde_json::{Map, Value as Json, json};
use utopia_database::{Document, Query, Value};
use utopia_http::Response;
use utopia_validators::Boolean;

use appwrite_core::database::documents::{self, Token, User};
use appwrite_core::datetime;
use appwrite_core::platform::{Audit, Context, Labels, Route};
use appwrite_core::response::TokenModel;
use appwrite_core::validators::{CustomId, Phone};
use appwrite_core::{Error, ErrorType, Result};
use utopia_emails::EmailValidator;

use crate::base::{self, Mail, Metadata, PHP_ROOT, Template, token_type};
use crate::{GROUPS, GROUPS_AUTH, route};

const AUDIT_SESSION: Option<Audit> = Some(Audit { event: "session.create", resource: "user/{response.userId}" });

pub fn routes() -> Vec<Route> {
    let magic_labels = Labels {
        abuse_limit: 60,
        abuse_key: &["url:{url},email:{param-email}", "url:{url},ip:{ip}"],
        auth_type: "magic-url",
        ..Labels::NONE
    };
    let phone_labels = Labels {
        abuse_limit: 10,
        abuse_key: &["url:{url},phone:{param-phone}", "url:{url},ip:{ip}"],
        auth_type: "phone",
        ..Labels::NONE
    };
    vec![
        route!(
            POST,
            "/v1/account/tokens/magic-url",
            "createMagicURLToken",
            "createMagicURLToken",
            &["sessions.write"],
            None,
            AUDIT_SESSION,
            GROUPS_AUTH,
            magic_labels,
            magic_url
        ),
        route!(
            POST,
            "/v1/account/sessions/magic-url",
            "createMagicURLToken",
            "createMagicURLToken",
            &["sessions.write"],
            None,
            AUDIT_SESSION,
            GROUPS_AUTH,
            magic_labels,
            magic_url
        ),
        route!(
            POST,
            "/v1/account/tokens/email",
            "createEmailToken",
            "createEmailToken",
            &["sessions.write"],
            None,
            AUDIT_SESSION,
            GROUPS_AUTH,
            Labels {
                abuse_limit: 10,
                abuse_key: &["url:{url},email:{param-email}", "url:{url},ip:{ip}"],
                auth_type: "email-otp",
                ..Labels::NONE
            },
            email
        ),
        route!(
            POST,
            "/v1/account/tokens/phone",
            "createPhoneToken",
            "createPhoneToken",
            &["sessions.write"],
            None,
            AUDIT_SESSION,
            GROUPS_AUTH,
            phone_labels,
            phone
        ),
        route!(
            POST,
            "/v1/account/sessions/phone",
            "createPhoneToken",
            "createPhoneToken",
            &["sessions.write"],
            None,
            AUDIT_SESSION,
            GROUPS_AUTH,
            phone_labels,
            phone
        ),
        route!(
            GET,
            "/v1/account/tokens/oauth2/:provider",
            "createOAuth2Token",
            "createOAuth2Token",
            &["sessions.write"],
            None,
            None,
            GROUPS,
            Labels { abuse_limit: 50, abuse_key: &["ip:{ip}"], error_page: true, ..Labels::NONE },
            crate::oauth2::create_token
        ),
        route!(
            POST,
            "/v1/account/tokens/passkey",
            "createPasskeyToken",
            "createPasskeyToken",
            &["sessions.write"],
            None,
            None,
            GROUPS_AUTH,
            Labels { abuse_limit: 10, abuse_key: &["url:{url},ip:{ip}"], auth_type: "passkey", ..Labels::NONE },
            crate::http::passkeys::create_token
        ),
        route!(
            PUT,
            "/v1/account/tokens/passkey",
            "updatePasskeyToken",
            "updatePasskeyToken",
            &["sessions.write"],
            None,
            Some(Audit { event: "passkey.token.create", resource: "user/{response.userId}" }),
            GROUPS,
            Labels { abuse_limit: 10, abuse_key: &["url:{url},ip:{ip}"], ..Labels::NONE },
            crate::http::passkeys::update_token
        ),
    ]
}

/// The secret is shown only to a key that can mint tokens itself
/// (`users.write`); `None` hides it.
fn secret_shown(ctx: &Context) -> bool {
    ctx.key.as_ref().is_none_or(|k| k.scopes.iter().any(|s| s == "users.write"))
}

/// A `201` with the token.
fn token_created(ctx: &Context, token: &Token, secret: &str, phrase: &str) -> Response {
    let secret = if secret_shown(ctx) { secret } else { "" };
    ctx.created(&TokenModel { token, secret: Some(secret), phrase, reveal: false, render: ctx.render() })
}

/// Stores a sign-in token for the user (with the user's role, as PHP adds it).
async fn create_token(
    ctx: &mut Context,
    user: &User,
    kind: i64,
    hash: &str,
    expire: &chrono::NaiveDateTime,
) -> Result<Token> {
    let mut token = Document::default();
    token.set("$id", Value::from(appwrite_core::database::unique_id().as_str()));
    token.set("userId", Value::from(user.id.as_str()));
    token.set("userInternalId", documents::internal_id(user.sequence));
    token.set("type", Value::Int(kind));
    token.set("secret", Value::from(hash));
    token.set("expire", datetime::value(expire));
    token.set("userAgent", Value::from(ctx.user_agent("UNKNOWN").as_str()));
    token.set("ip", Value::from(ctx.ip.as_str()));
    token.set("$permissions", base::owner_permissions(&user.id));
    ctx.authorization.add_role(&format!("user:{}", user.id));
    let db = ctx.db()?;
    let token = Token::from(&db.create_document("tokens", token).await?);
    db.purge_cached_document("users", Some(&user.id)).await?;
    Ok(token)
}

/// `$smtpBaseTemplate` (`FileName`-valid, else a bad request).
fn base_template(ctx: &Context) -> Result<String> {
    let name = base::smtp_base_template(ctx);
    let valid = !name.is_empty()
        && name != "0"
        && name.chars().filter(|c| !matches!(c, '.' | '-' | '_')).all(|c| c.is_ascii_alphanumeric())
        && name.chars().any(|c| !matches!(c, '.' | '-' | '_'));
    if !valid {
        return Err(Error::with_message(ErrorType::GeneralBadRequest, "Invalid template path"));
    }
    Ok(name)
}

/// A user signing up by email (magic URL, email OTP): the new user's
/// document after the limit, identity and email policy checks.
async fn email_sign_up(ctx: &mut Context, user_id: String, email: &str, identity_error: ErrorType) -> Result<Document> {
    base::check_user_limit(ctx, false).await?;
    let db = ctx.db()?;
    if !db.find_one("identities", vec![Query::equal("providerEmail", [email])]).await?.is_empty() {
        return Err(Error::new(identity_error));
    }
    let user_id = if user_id == "unique()" { appwrite_core::database::unique_id() } else { user_id };
    let meta = Metadata::of(email)?;
    base::check_email_policy(ctx, &meta, false)?;
    let mut user = base::new_user(&user_id);
    user.set("email", Value::from(email));
    user.set("search", Value::from(format!("{user_id} {email}").as_str()));
    meta.apply(&mut user);
    Ok(user)
}

/// `POST /v1/account/tokens/magic-url`.
pub async fn magic_url(ctx: &mut Context) -> Result<Response> {
    let user_id = ctx.required_str("userId", &CustomId)?;
    let email = ctx.required_str("email", &EmailValidator::default())?;
    let redirect = base::Redirect::of(ctx);
    let url = ctx.optional_str("url", "", &redirect)?.unwrap_or_default();
    let phrase = utopia_validators::php::truthy(&ctx.optional("phrase", json!(false), &Boolean::STRICT)?);
    if ctx.state.config.smtp_host.is_empty() {
        return Err(Error::with_message(ErrorType::GeneralSmtpDisabled, "SMTP disabled"));
    }
    let phrase = if phrase { utopia_auth::proofs::Phrase::new().phrase() } else { String::new() };

    let db = ctx.db()?;
    let found = db.find_one("users", vec![Query::equal("email", [email.as_str()])]).await?;
    let user = if !found.is_empty() {
        User::from(&found)
    } else {
        let mut user = email_sign_up(ctx, user_id, &email, ErrorType::UserEmailAlreadyExists).await?;
        base::set_default_hash(&mut user);
        user.set("mfa", Value::Bool(false));
        user.set("authenticators", Value::Null);
        base::create_user(ctx, user).await?
    };
    base::act_as(ctx, &user);

    let secret = base::token_secret(64)?;
    let expire = datetime::add_seconds(datetime::now(), base::EXPIRATION_CONFIRM);
    let token = create_token(ctx, &user, token_type::MAGIC_URL, &base::token_hash(&secret)?, &expire).await?;

    let url = if url.is_empty() { format!("{}/auth/magic-url", base::console_base(ctx)) } else { url };
    let expire_text = datetime::format_tz(&expire);
    let url = base::link(
        &url,
        &[("userId", &user.id), ("secret", &secret), ("expire", &expire_text), ("project", &ctx.project.id)],
    );

    let mut subject = ctx.text("emails.magicSession.subject", None);
    let preview = ctx.text("emails.magicSession.preview", None);
    let custom = base::custom_template(ctx, "email.magicSession");
    let security = if phrase.is_empty() { String::new() } else { ctx.text("emails.magicSession.securityPhrase", None) };
    let mut body = Template::file(ctx, "email-magic-url.tpl")?
        .param("{{hello}}", &ctx.text("emails.magicSession.hello", None))
        .param("{{optionButton}}", &ctx.text("emails.magicSession.optionButton", None))
        .param("{{buttonText}}", &ctx.text("emails.magicSession.buttonText", None))
        .param("{{optionUrl}}", &ctx.text("emails.magicSession.optionUrl", None))
        .param("{{clientInfo}}", &ctx.text("emails.magicSession.clientInfo", None))
        .param("{{thanks}}", &ctx.text("emails.magicSession.thanks", None))
        .param("{{signature}}", &ctx.text("emails.magicSession.signature", None))
        .param("{{securityPhrase}}", &security)
        .render();
    let smtp = base::smtp_settings(ctx, custom.as_ref(), &mut subject, &mut body);

    let mut variables = Map::new();
    variables.insert("direction".into(), Json::String(ctx.text("settings.direction", None)));
    variables.insert("user".into(), user.name.clone().map(Json::String).unwrap_or(Json::Null));
    variables.insert("project".into(), Json::String(base::project_name(ctx)));
    variables.insert("redirect".into(), Json::String(url));
    base::agent_variables(ctx, &mut variables);
    variables.insert("phrase".into(), Json::String(phrase.clone()));
    variables.insert("team".into(), Json::String(String::new()));
    Mail {
        recipient: email,
        subject,
        template: "magic-url",
        body_template: String::new(),
        body,
        preview,
        smtp,
        variables,
        custom_mail_options: if ctx.project.is_console() {
            json!({"senderName": base::platform(ctx, "emailSenderName")})
        } else {
            Json::Array(Vec::new())
        },
    }
    .send(ctx)
    .await;

    Ok(token_created(ctx, &token, &secret, &phrase))
}

/// `POST /v1/account/tokens/email`.
pub async fn email(ctx: &mut Context) -> Result<Response> {
    let user_id = ctx.required_str("userId", &CustomId)?;
    let email = ctx.required_str("email", &EmailValidator::default())?;
    let phrase = utopia_validators::php::truthy(&ctx.optional("phrase", json!(false), &Boolean::STRICT)?);
    if ctx.state.config.smtp_host.is_empty() {
        return Err(Error::with_message(ErrorType::GeneralSmtpDisabled, "SMTP disabled"));
    }
    let phrase = if phrase { utopia_auth::proofs::Phrase::new().phrase() } else { String::new() };

    let db = ctx.db()?;
    let found = db.find_one("users", vec![Query::equal("email", [email.as_str()])]).await?;
    let user = if !found.is_empty() {
        User::from(&found)
    } else {
        let mut user = email_sign_up(ctx, user_id, &email, ErrorType::GeneralBadRequest).await?;
        base::set_default_hash(&mut user);
        let mut user = base::create_user(ctx, user).await?;
        if let Some(target) = base::create_target(ctx, &user, "email", &email).await? {
            user.targets.push(target);
        }
        ctx.db()?.purge_cached_document("users", Some(&user.id)).await?;
        user
    };
    base::act_as(ctx, &user);

    let secret = base::code_secret();
    let expire = datetime::add_seconds(datetime::now(), base::EXPIRATION_OTP);
    let token = create_token(ctx, &user, token_type::EMAIL, &base::token_hash(&secret)?, &expire).await?;

    let mut subject = ctx.text("emails.otpSession.subject", None);
    let preview = ctx.text("emails.otpSession.preview", None);
    let heading = ctx.text("emails.otpSession.heading", None);
    let custom = base::custom_template(ctx, "email.otpSession");
    let template = base_template(ctx)?;
    let security = if phrase.is_empty() { String::new() } else { ctx.text("emails.otpSession.securityPhrase", None) };
    let mut body = Template::file(ctx, "email-otp.tpl")?
        .param("{{hello}}", &ctx.text("emails.otpSession.hello", None))
        .param("{{description}}", &ctx.text("emails.otpSession.description", None))
        .param("{{clientInfo}}", &ctx.text("emails.otpSession.clientInfo", None))
        .param("{{thanks}}", &ctx.text("emails.otpSession.thanks", None))
        .param("{{signature}}", &ctx.text("emails.otpSession.signature", None))
        .param("{{securityPhrase}}", &security)
        .render();
    let smtp = base::smtp_settings(ctx, custom.as_ref(), &mut subject, &mut body);

    let mut variables = Map::new();
    variables.insert("heading".into(), Json::String(heading));
    variables.insert("direction".into(), Json::String(ctx.text("settings.direction", None)));
    variables.insert("user".into(), user.name.clone().map(Json::String).unwrap_or(Json::Null));
    variables.insert("project".into(), Json::String(base::project_name(ctx)));
    variables.insert("otp".into(), Json::String(secret.clone()));
    base::agent_variables(ctx, &mut variables);
    variables.insert("phrase".into(), Json::String(phrase.clone()));
    variables.insert("team".into(), Json::String(String::new()));
    let branded = template == "email-base-styled";
    if branded {
        base::branded_variables(ctx, &mut variables);
    }
    Mail {
        recipient: email,
        subject,
        template: "otp",
        body_template: format!("{PHP_ROOT}/app/controllers/api/../../config/locale/templates/{template}.tpl"),
        body,
        preview,
        smtp,
        variables,
        custom_mail_options: if branded {
            json!({"senderName": base::platform(ctx, "emailSenderName")})
        } else {
            Json::Array(Vec::new())
        },
    }
    .send(ctx)
    .await;

    Ok(token_created(ctx, &token, &secret, &phrase))
}

/// `POST /v1/account/tokens/phone`.
pub async fn phone(ctx: &mut Context) -> Result<Response> {
    let user_id = ctx.required_str("userId", &CustomId)?;
    let phone = ctx.required_str("phone", &Phone::default())?;
    if ctx.state.config.sms_provider.is_empty() {
        return Err(Error::with_message(ErrorType::GeneralPhoneDisabled, "Phone provider not configured"));
    }
    let db = ctx.db()?;
    let found = db.find_one("users", vec![Query::equal("phone", [phone.as_str()])]).await?;
    let user = if !found.is_empty() {
        User::from(&found)
    } else {
        base::check_user_limit(ctx, false).await?;
        let user_id = if user_id == "unique()" { appwrite_core::database::unique_id() } else { user_id };
        let mut user = base::new_user(&user_id);
        user.set("email", Value::Null);
        user.set("phone", Value::from(phone.as_str()));
        user.set("phoneVerification", Value::Bool(false));
        user.set("password", Value::Null);
        user.set("passwordUpdate", Value::Null);
        user.set("search", Value::from(format!("{user_id} {phone}").as_str()));
        Metadata::default().apply(&mut user);
        let mut user = base::create_user(ctx, user).await?;
        if let Some(target) = base::create_target(ctx, &user, "sms", &phone).await? {
            user.targets.push(target);
        }
        ctx.db()?.purge_cached_document("users", Some(&user.id)).await?;
        user
    };
    base::act_as(ctx, &user);

    // A mock number signs in with its fixed code, and no SMS is sent.
    let mock = ctx
        .project
        .auth("mockNumbers")
        .and_then(Json::as_array)
        .and_then(|numbers| {
            numbers.iter().find(|n| n.get("phone").and_then(Json::as_str) == Some(phone.as_str())).cloned()
        })
        .map(|n| n.get("otp").and_then(Json::as_str).unwrap_or("").to_owned());
    let send = mock.is_none();
    let secret = mock.unwrap_or_else(base::code_secret);
    let expire = datetime::add_seconds(datetime::now(), base::EXPIRATION_OTP);
    let token = create_token(ctx, &user, token_type::PHONE, &base::token_hash(&secret)?, &expire).await?;

    if send {
        let content = Template::string(&ctx.text("sms.verification.body", None))
            .param("{{project}}", &base::project_name(ctx))
            .param("{{secret}}", &secret)
            .render();
        let content = String::from_utf8_lossy(&php_std::string::strip_tags(content.as_bytes())).into_owned();
        let message = Template::file(ctx, "sms-base.tpl")?.param("{{token}}", &content).render();
        base::send_sms(ctx, &token.id, &message, &phone).await;
    }

    let encoded = base::encode_store(&user.id, &secret)?;
    Ok(token_created(ctx, &token, &encoded, ""))
}
