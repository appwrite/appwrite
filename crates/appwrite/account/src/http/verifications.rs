//! Email and phone verification: `/v1/account/verifications/email` (a
//! link), `/verifications/email/otp` (a code) and `/verifications/phone`
//! (an SMS code), created and confirmed.

use serde_json::{Map, Value as Json, json};
use utopia_database::{Document, Value};
use utopia_http::Response;
use utopia_validators::{Boolean, Text, php};

use appwrite_core::database::documents::{self, Token, User};
use appwrite_core::datetime;
use appwrite_core::platform::{Context, Labels, Route};
use appwrite_core::response::TokenModel;
use appwrite_core::validators::uid;
use appwrite_core::{Error, ErrorType, Result};

use crate::base::profile as base;
use crate::http::recovery::require_smtp;
use crate::{ACCOUNT, abuse, audit};

const PUBLIC: &[&str] = &["public"];
const AUTH_GROUPS: &[&str] = &["api", "account", "auth"];

pub fn routes() -> Vec<Route> {
    vec![
        profile_route!(
            POST,
            "/v1/account/verifications/email",
            "createEmailVerification",
            "createEmailVerification",
            ACCOUNT,
            Some("users.[userId].verification.[tokenId].create"),
            audit("verification.create", "user/{response.userId}"),
            create_email,
            crate::GROUPS,
            abuse(&["url:{url},userId:{userId}"])
        ),
        profile_route!(
            PUT,
            "/v1/account/verifications/email",
            "updateEmailVerification",
            "updateEmailVerification",
            PUBLIC,
            Some("users.[userId].verification.[tokenId].update"),
            audit("verification.update", "user/{response.userId}"),
            update_email,
            crate::GROUPS,
            abuse(&["url:{url},userId:{param-userId}"])
        ),
        profile_route!(
            POST,
            "/v1/account/verifications/email/otp",
            "createEmailVerificationOTP",
            "createEmailVerificationOTP",
            ACCOUNT,
            Some("users.[userId].verification.[tokenId].create"),
            audit("verification.create", "user/{response.userId}"),
            create_email_otp,
            crate::GROUPS,
            abuse(&["url:{url},userId:{userId}"])
        ),
        profile_route!(
            PUT,
            "/v1/account/verifications/email/otp",
            "updateEmailVerificationOTP",
            "updateEmailVerificationOTP",
            PUBLIC,
            Some("users.[userId].verification.[tokenId].update"),
            audit("verification.update", "user/{response.userId}"),
            update_email_otp,
            crate::GROUPS,
            abuse(&["url:{url},userId:{param-userId}"])
        ),
        profile_route!(
            POST,
            "/v1/account/verifications/phone",
            "createPhoneVerification",
            "createPhoneVerification",
            ACCOUNT,
            Some("users.[userId].verification.[tokenId].create"),
            audit("verification.create", "user/{response.userId}"),
            create_phone,
            AUTH_GROUPS,
            Labels { auth_type: "phone", ..abuse(&["url:{url},userId:{userId}", "url:{url},ip:{ip}"]) }
        ),
        profile_route!(
            PUT,
            "/v1/account/verifications/phone",
            "updatePhoneVerification",
            "updatePhoneVerification",
            PUBLIC,
            Some("users.[userId].verification.[tokenId].update"),
            audit("verification.update", "user/{response.userId}"),
            update_phone,
            crate::GROUPS,
            abuse(&["userId:{param-userId}"])
        ),
    ]
}

/// A new verification token of the session user.
async fn verification(ctx: &mut Context, user: &User, kind: i64, secret: &str, expire: &Value) -> Result<Document> {
    let token = base::new_token(ctx, &user.id, documents::internal_id(user.sequence), kind, secret, expire)?;
    let token = base::create_token(ctx, &user.id, token).await?;
    let db = ctx.db()?;
    db.purge_cached_document("users", &user.id).await?;
    Ok(token)
}

/// The session user, with an email still to verify.
fn unverified_email(ctx: &Context) -> Result<User> {
    let user = base::session_user(ctx)?;
    if user.email.as_deref().unwrap_or("").is_empty() {
        return Err(Error::new(ErrorType::UserEmailNotFound));
    }
    if user.email_verification == Some(true) {
        return Err(Error::new(ErrorType::UserEmailAlreadyVerified));
    }
    Ok(user)
}

/// The events and the response of a created verification.
fn created(ctx: &mut Context, user: &User, token: &Document, secret: &str, phrase: Option<String>) -> Response {
    let mut token = Token::from(token);
    token.secret = Some(secret.to_owned());
    token.phrase = phrase;
    ctx.event_param("userId", user.id.clone());
    ctx.event_param("tokenId", token.id.clone());
    ctx.events.payload = Some(base::token_payload(&token));
    ctx.events.sensitive = vec!["secret"];
    ctx.created(&TokenModel { token: &token, secret: Some(secret), phrase: "", reveal: false, render: ctx.render() })
}

/// `POST /v1/account/verifications/email`.
pub async fn create_email(ctx: &mut Context) -> Result<Response> {
    let url = ctx.required_str("url", &base::Redirect::new(ctx))?;
    require_smtp(ctx)?;
    let user = unverified_email(ctx)?;

    let secret = utopia_auth::proofs::Token::default().token();
    let expire = datetime::add_seconds(datetime::now(), base::TOKEN_EXPIRATION_CONFIRM);
    let expire_text = datetime::format_tz(&expire);
    let token = verification(ctx, &user, base::TOKEN_TYPE_VERIFICATION, &secret, &datetime::value(&expire)).await?;

    let url = appwrite_core::template::with_query(
        &url,
        &[("userId", &user.id), ("secret", &secret), ("expire", &expire_text)],
    );
    let inner = base::template_file(ctx, "email-inner-base.tpl")
        .await?
        .param("{{body}}", &ctx.text("emails.verification.body", None), false)
        .param("{{hello}}", &ctx.text("emails.verification.hello", None), true)
        .param("{{footer}}", &ctx.text("emails.verification.footer", None), true)
        .param("{{thanks}}", &ctx.text("emails.verification.thanks", None), true)
        .param("{{buttonText}}", &ctx.text("emails.verification.buttonText", None), true)
        .param("{{signature}}", &ctx.text("emails.verification.signature", None), true);

    let mut variables = Map::new();
    variables.insert("heading".into(), Json::String(ctx.text("emails.verification.heading", None)));
    variables.insert("direction".into(), Json::String(ctx.text("settings.direction", None)));
    variables.insert("user".into(), user.name.clone().map(Json::String).unwrap_or(Json::Null));
    variables.insert("redirect".into(), Json::String(url));
    variables.insert("project".into(), Json::String(base::project_name(ctx)));
    variables.insert("team".into(), Json::String(String::new()));
    base::branded_variables(ctx, &mut variables);
    base::queue_mail(
        ctx,
        base::Mail {
            key: "verification",
            template: "verification",
            recipient: user.email.clone().unwrap_or_default(),
            name: user.name.clone().unwrap_or_default(),
            subject: ctx.text("emails.verification.subject", None),
            preview: ctx.text("emails.verification.preview", None),
            body: inner.render(),
            variables,
        },
    );
    Ok(created(ctx, &user, &token, &secret, None))
}

/// `POST /v1/account/verifications/email/otp`.
pub async fn create_email_otp(ctx: &mut Context) -> Result<Response> {
    let phrase = php::truthy(&ctx.optional("phrase", Json::Bool(false), &Boolean::STRICT)?);
    require_smtp(ctx)?;
    let user = unverified_email(ctx)?;
    let phrase = phrase.then(|| utopia_auth::proofs::Phrase::new().phrase());

    let secret = utopia_auth::proofs::Code::default().code();
    let expire = datetime::add_seconds(datetime::now(), base::TOKEN_EXPIRATION_OTP);
    let token = verification(ctx, &user, base::TOKEN_TYPE_VERIFICATION_OTP, &secret, &datetime::value(&expire)).await?;

    let detected = appwrite_core::detector::detect(&ctx.user_agent("UNKNOWN"));
    let message = base::template_file(ctx, "email-otp.tpl")
        .await?
        .param("{{hello}}", &ctx.text("emails.otpVerification.hello", None), true)
        .param("{{description}}", &ctx.text("emails.otpVerification.description", None), true)
        .param("{{clientInfo}}", &ctx.text("emails.otpVerification.clientInfo", None), true)
        .param("{{thanks}}", &ctx.text("emails.otpVerification.thanks", None), true)
        .param("{{signature}}", &ctx.text("emails.otpVerification.signature", None), true);
    let security =
        if phrase.is_some() { ctx.text("emails.otpVerification.securityPhrase", None) } else { String::new() };
    let message = message.param("{{securityPhrase}}", &security, true);

    let mut variables = Map::new();
    variables.insert("heading".into(), Json::String(ctx.text("emails.otpVerification.heading", None)));
    variables.insert("direction".into(), Json::String(ctx.text("settings.direction", None)));
    variables.insert("user".into(), user.name.clone().map(Json::String).unwrap_or(Json::Null));
    variables.insert("project".into(), Json::String(base::project_name(ctx)));
    variables.insert("otp".into(), Json::String(secret.clone()));
    variables.insert("agentDevice".into(), Json::String(detected.device_brand.unwrap_or_else(|| "UNKNOWN".into())));
    variables.insert("agentClient".into(), Json::String(detected.client_name));
    variables.insert("agentOs".into(), Json::String(detected.os_name));
    variables.insert("phrase".into(), Json::String(phrase.clone().unwrap_or_default()));
    variables.insert("team".into(), Json::String(String::new()));
    base::branded_variables(ctx, &mut variables);
    base::queue_mail(
        ctx,
        base::Mail {
            key: "otpVerification",
            template: "otp-verification",
            recipient: user.email.clone().unwrap_or_default(),
            name: user.name.clone().unwrap_or_default(),
            subject: ctx.text("emails.otpVerification.subject", None),
            preview: ctx.text("emails.otpVerification.preview", None),
            body: message.render(),
            variables,
        },
    );
    Ok(created(ctx, &user, &token, &secret, phrase))
}

/// `POST /v1/account/verifications/phone`.
pub async fn create_phone(ctx: &mut Context) -> Result<Response> {
    if ctx.state.config.sms_provider.is_empty() {
        return Err(Error::with_message(ErrorType::GeneralPhoneDisabled, "Phone provider not configured"));
    }
    let user = base::session_user(ctx)?;
    let phone = user.phone.clone().unwrap_or_default();
    if phone.is_empty() {
        return Err(Error::new(ErrorType::UserPhoneNotFound));
    }
    if user.phone_verification == Some(true) {
        return Err(Error::new(ErrorType::UserPhoneAlreadyVerified));
    }

    // A mock number has a fixed code and gets no SMS.
    let mock = ctx.project.auth("mockNumbers").and_then(Json::as_array).and_then(|numbers| {
        numbers
            .iter()
            .find(|m| m.get("phone").and_then(Json::as_str) == Some(phone.as_str()))
            .map(|m| m.get("otp").and_then(Json::as_str).unwrap_or("").to_owned())
    });
    let send = mock.is_none();
    let secret = mock.unwrap_or_else(|| utopia_auth::proofs::Code::default().code());
    let expire = datetime::add_seconds(datetime::now(), base::TOKEN_EXPIRATION_CONFIRM);
    let token = verification(ctx, &user, base::TOKEN_TYPE_PHONE, &secret, &datetime::value(&expire)).await?;

    if send {
        let content = base::template_text(&ctx.text("sms.verification.body", None))
            .param("{{project}}", &ctx.project.name, true)
            .param("{{secret}}", &secret, true);
        let content = String::from_utf8_lossy(&php_std::string::strip_tags(content.render().as_bytes())).into_owned();
        let message = base::template_file(ctx, "sms-base.tpl").await?.param("{{token}}", &content, true);
        let message = json!({
            "type": "internal",
            "project": ctx.project.message_payload(),
            "messageId": null,
            "message": {"$id": token.id(), "data": {"content": message.render()}},
            "recipients": [phone],
            "providerType": "sms",
        });
        let queue = ctx.state.config.queue_messaging.clone();
        ctx.events.queued.push((queue, message));
        if let Some(code) = appwrite_core::validators::calling_code(&phone) {
            ctx.metric(&format!("auth.method.phone.{code}"), 1);
        }
        ctx.metric("auth.method.phone", 1);
    }

    let mut model = Token::from(&token);
    model.secret = Some(secret.clone());
    ctx.event_param("userId", user.id.clone());
    ctx.event_param("tokenId", model.id.clone());
    // The payload is the response's output: the secret only for keys and
    // privileged users.
    let render = ctx.render();
    ctx.events.payload = Some(bytes::Bytes::from(appwrite_core::response::current(&TokenModel {
        token: &model,
        secret: Some(&secret),
        phrase: "",
        reveal: false,
        render,
    })));
    ctx.events.sensitive = vec!["secret"];
    Ok(ctx.created(&TokenModel { token: &model, secret: Some(&secret), phrase: "", reveal: false, render }))
}

/// `PUT /v1/account/verifications/email`.
pub async fn update_email(ctx: &mut Context) -> Result<Response> {
    confirm(ctx, base::TOKEN_TYPE_VERIFICATION, "emailVerification", true).await
}

/// `PUT /v1/account/verifications/email/otp`.
pub async fn update_email_otp(ctx: &mut Context) -> Result<Response> {
    confirm(ctx, base::TOKEN_TYPE_VERIFICATION_OTP, "emailVerification", true).await
}

/// `PUT /v1/account/verifications/phone`.
pub async fn update_phone(ctx: &mut Context) -> Result<Response> {
    confirm(ctx, base::TOKEN_TYPE_PHONE, "phoneVerification", false).await
}

/// A verification confirmed with its secret: the user's `attribute` set,
/// the token gone. `payload`: the event carries the token with its secret
/// (else the response).
async fn confirm(ctx: &mut Context, kind: i64, attribute: &str, payload: bool) -> Result<Response> {
    let user_id = ctx.required_str("userId", &uid())?;
    let secret = ctx.required_str("secret", &Text::new(256))?;

    let db = ctx.db()?;
    let authorization = db.authorization();
    let document = authorization.skip(base::user_document(&db, &user_id, &[base::TOKENS])).await?;
    if document.is_empty() {
        return Err(Error::new(ErrorType::UserNotFound));
    }
    let Some(verified) = base::token_verify(&document, kind, &secret) else {
        return Err(Error::new(ErrorType::UserInvalidToken));
    };
    ctx.authorization.add_role(&format!("user:{}", document.id()));

    let mut updates = Document::default();
    updates.put(attribute, Value::Bool(true));
    let profile = base::update_user(&db, document.id(), updates, &[]).await?;

    let token = db.get_document("tokens", verified.id(), Vec::new(), false).await?;
    db.delete_document("tokens", verified.id()).await?;
    db.purge_cached_document("users", &profile.id).await?;

    let token = Token::from(&token);
    // PHP fills the request's user with the profile: the event's user.
    ctx.events.actor = Some(profile.message_payload());
    ctx.event_param("userId", if payload { user_id } else { profile.id.clone() });
    ctx.event_param("tokenId", token.id.clone());
    if payload {
        ctx.events.payload = Some(base::token_payload(&token));
        ctx.events.sensitive = vec!["secret"];
    }
    Ok(ctx.ok(&TokenModel { token: &token, secret: None, phrase: "", reveal: false, render: ctx.render() }))
}
