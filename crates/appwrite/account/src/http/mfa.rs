//! `/v1/account/mfa`: multi-factor authentication of the caller's account
//! (the switch, factors, TOTP authenticators, recovery codes, challenges).

use serde_json::{Map, Value as Json, json};
use utopia_database::{Document, Value};
use utopia_http::Response;
use utopia_validators::{Boolean, Text, WhiteList, php};

use appwrite_core::database::documents::{self, Attributes, Challenge, Session, User};
use appwrite_core::database::filters;
use appwrite_core::datetime;
use appwrite_core::platform::{Audit, Context, Labels, Route};
use appwrite_core::response::{
    AccountModel, MfaChallengeModel, MfaFactorsModel, MfaRecoveryCodesModel, MfaTypeModel, SessionModel,
};
use appwrite_core::{Error, ErrorType, Result};

use crate::base::{self, Mail, PHP_ROOT, Template};
use crate::{GROUPS, GROUPS_MFA, route};

const EVENT_MFA: Option<&str> = Some("users.[userId].update.mfa");
const AUDIT_USER: Option<Audit> = Some(Audit { event: "user.update", resource: "user/{response.$id}" });
const PROTECTED: &[&str] = &["api", "account", "mfaProtected"];

pub fn routes() -> Vec<Route> {
    let challenge =
        Labels { abuse_limit: 10, abuse_key: &["url:{url},userId:{userId}"], impersonation: "allow", ..Labels::NONE };
    let confirm = Labels {
        abuse_limit: 10,
        abuse_key: &["url:{url},challengeId:{param-challengeId}"],
        impersonation: "allow",
        ..Labels::NONE
    };
    let challenge_audit = Some(Audit { event: "challenge.create", resource: "user/{response.userId}" });
    let confirm_audit = Some(Audit { event: "challenges.update", resource: "user/{response.userId}" });
    let confirm_event = Some("users.[userId].sessions.[sessionId].create");
    let challenge_event = Some("users.[userId].challenges.[challengeId].create");
    vec![
        route!(
            PATCH,
            "/v1/account/mfa",
            "updateMFA",
            "updateMFA",
            &["account"],
            EVENT_MFA,
            AUDIT_USER,
            GROUPS,
            Labels::NONE,
            update
        ),
        route!(
            GET,
            "/v1/account/mfa/factors",
            "listMFAFactors",
            "listMfaFactors",
            &["account"],
            None,
            None,
            GROUPS_MFA,
            Labels::NONE,
            factors
        ),
        route!(
            POST,
            "/v1/account/mfa/authenticators/:type",
            "createMFAAuthenticator",
            "createMfaAuthenticator",
            &["account"],
            EVENT_MFA,
            AUDIT_USER,
            GROUPS,
            Labels::NONE,
            create_authenticator
        ),
        route!(
            PUT,
            "/v1/account/mfa/authenticators/:type",
            "updateMFAAuthenticator",
            "updateMfaAuthenticator",
            &["account"],
            EVENT_MFA,
            AUDIT_USER,
            GROUPS,
            Labels::NONE,
            update_authenticator
        ),
        route!(
            DELETE,
            "/v1/account/mfa/authenticators/:type",
            "deleteMFAAuthenticator",
            "deleteMfaAuthenticator",
            &["account"],
            Some("users.[userId].delete.mfa"),
            Some(Audit { event: "user.update", resource: "user/{user.$id}" }),
            PROTECTED,
            Labels::NONE,
            delete_authenticator
        ),
        route!(
            POST,
            "/v1/account/mfa/recovery-codes",
            "createMFARecoveryCodes",
            "createMfaRecoveryCodes",
            &["account"],
            EVENT_MFA,
            AUDIT_USER,
            GROUPS,
            Labels::NONE,
            create_recovery_codes
        ),
        route!(
            GET,
            "/v1/account/mfa/recovery-codes",
            "getMFARecoveryCodes",
            "getMfaRecoveryCodes",
            &["account"],
            None,
            None,
            PROTECTED,
            Labels { impersonation: "deny", ..Labels::NONE },
            get_recovery_codes
        ),
        route!(
            PATCH,
            "/v1/account/mfa/recovery-codes",
            "updateMFARecoveryCodes",
            "updateMfaRecoveryCodes",
            &["account"],
            EVENT_MFA,
            AUDIT_USER,
            PROTECTED,
            Labels::NONE,
            update_recovery_codes
        ),
        route!(
            POST,
            "/v1/account/mfa/challenges",
            "createMFAChallenge",
            "createMfaChallenge",
            &["account"],
            challenge_event,
            challenge_audit,
            GROUPS_MFA,
            challenge,
            create_challenge
        ),
        route!(
            POST,
            "/v1/account/mfa/challenge",
            "createMFAChallenge",
            "createMfaChallenge",
            &["account"],
            challenge_event,
            challenge_audit,
            GROUPS_MFA,
            challenge,
            create_challenge
        ),
        route!(
            PUT,
            "/v1/account/mfa/challenges",
            "updateMFAChallenge",
            "updateMfaChallenge",
            &["account"],
            confirm_event,
            confirm_audit,
            GROUPS_MFA,
            confirm,
            update_challenge
        ),
        route!(
            PUT,
            "/v1/account/mfa/challenge",
            "updateMFAChallenge",
            "updateMfaChallenge",
            &["account"],
            confirm_event,
            confirm_audit,
            GROUPS_MFA,
            confirm,
            update_challenge
        ),
    ]
}

/// The caller (the `user` resource).
fn caller(ctx: &Context) -> Result<User> {
    ctx.user.clone().ok_or_else(|| Error::new(ErrorType::UserUnauthorized))
}

/// The `session` resource, which these actions take as a `Document`: a
/// request without one fails as PHP's injection does.
fn session(ctx: &Context) -> Result<Session> {
    ctx.session.clone().ok_or_else(|| Error::internal("session resource is null"))
}

/// The TOTP authenticator of a user (`TOTP::getAuthenticatorFromUser`).
fn totp_authenticator(user: &User) -> Option<&documents::Authenticator> {
    user.authenticators.iter().find(|a| a.kind.as_deref() == Some("totp"))
}

/// Adds `factor` to the session's factors and stores them (with
/// `mfaUpdatedAt` when given).
async fn add_factor(
    ctx: &mut Context,
    session: &mut Session,
    factors: Vec<String>,
    mfa_updated_at: Option<chrono::NaiveDateTime>,
) -> Result<()> {
    let mut unique: Vec<String> = Vec::with_capacity(factors.len());
    for f in factors {
        if !unique.contains(&f) {
            unique.push(f);
        }
    }
    let mut updates = Document::default();
    updates.put("factors", documents::strings(&unique));
    if let Some(at) = mfa_updated_at {
        updates.put("mfaUpdatedAt", datetime::value(&at));
        session.mfa_updated_at = Some(at);
    }
    session.factors = unique;
    let db = ctx.db()?;
    db.update_document("sessions", &session.id, updates).await?;
    Ok(())
}

/// Updates the user as `updateDocument` returns it: decoded with the
/// sub-queries the account model and the factors read.
async fn update_user(ctx: &mut Context, id: &str, updates: Document) -> Result<User> {
    let db = ctx.db()?;
    let skipped = base::skipped(&[]);
    let mut updated = db.skip_filters(Some(skipped.as_slice()), db.update_document("users", id, updates)).await?;
    for (filter, attribute) in [("subQueryTargets", "targets"), ("subQueryAuthenticators", "authenticators")] {
        let value = filters::sub_query(&db, filter, &updated).await?;
        updated.put(attribute, value);
    }
    Ok(User::from(&updated))
}

/// `PATCH /v1/account/mfa`.
pub async fn update(ctx: &mut Context) -> Result<Response> {
    let mfa = php::truthy(&ctx.required("mfa", &Boolean::STRICT)?);
    let user = caller(ctx)?;
    let mut session = session(ctx)?;
    let mut updates = Document::default();
    updates.put("mfa", Value::Bool(mfa));
    let user = update_user(ctx, &user.id, updates).await?;
    if mfa {
        let mut factors = session.factors.clone();
        if totp_authenticator(&user).is_some_and(|a| a.verified == Some(true)) {
            factors.push("totp".to_owned());
        }
        let set = |v: &Option<String>| v.as_deref().is_some_and(|s| !s.is_empty() && s != "0");
        if set(&user.email) && user.email_verification == Some(true) {
            factors.push("email".to_owned());
        }
        if set(&user.phone) && user.phone_verification == Some(true) {
            factors.push("phone".to_owned());
        }
        add_factor(ctx, &mut session, factors, None).await?;
    }
    ctx.event_param("userId", user.id.clone());
    Ok(ctx.ok(&AccountModel { user: &user, impersonator_user_id: None, render: ctx.render() }))
}

/// `GET /v1/account/mfa/factors`.
pub async fn factors(ctx: &mut Context) -> Result<Response> {
    let user = ctx.roles_user().cloned().ok_or_else(|| Error::new(ErrorType::UserUnauthorized))?;
    let factors = ctx.project.auth("mfaFactors").cloned().unwrap_or(Json::Null);
    let flag = |k: &str, d: bool| factors.get(k).map(php::truthy).unwrap_or(d);
    let set = |s: &Option<String>| s.as_deref().map(|v| !v.is_empty() && v != "0").unwrap_or(false);
    let model = MfaFactorsModel {
        totp: flag("totp", true) && totp_authenticator(&user).is_some_and(|t| t.verified == Some(true)),
        phone: flag("phone", true) && set(&user.phone) && user.phone_verification == Some(true),
        email: flag("email", true) && set(&user.email) && user.email_verification == Some(true),
        recovery_code: !user.mfa_recovery_codes.is_empty(),
        custom: factors.get("custom").cloned().unwrap_or(Json::Bool(false)),
    };
    Ok(ctx.ok(&model))
}

// ---- TOTP ------------------------------------------------------------------

/// RFC 4648 base32, upper case, without padding.
fn base32(bytes: &[u8]) -> String {
    const ALPHABET: &[u8; 32] = b"ABCDEFGHIJKLMNOPQRSTUVWXYZ234567";
    let mut out = String::with_capacity(bytes.len().div_ceil(5) * 8);
    let (mut buffer, mut bits) = (0u32, 0u32);
    for &b in bytes {
        buffer = (buffer << 8) | u32::from(b);
        bits += 8;
        while bits >= 5 {
            out.push(char::from(ALPHABET[((buffer >> (bits - 5)) & 31) as usize]));
            bits -= 5;
        }
    }
    if bits > 0 {
        out.push(char::from(ALPHABET[((buffer << (5 - bits)) & 31) as usize]));
    }
    out
}

/// Decodes upper case base32 (padding ignored).
fn base32_decode(text: &str) -> Option<Vec<u8>> {
    let mut out = Vec::with_capacity(text.len() * 5 / 8);
    let (mut buffer, mut bits) = (0u64, 0u32);
    for c in text.trim_end_matches('=').bytes() {
        let v = match c {
            b'A'..=b'Z' => c - b'A',
            b'2'..=b'7' => c - b'2' + 26,
            _ => return None,
        };
        buffer = (buffer << 5) | u64::from(v);
        bits += 5;
        if bits >= 8 {
            out.push(((buffer >> (bits - 8)) & 0xFF) as u8);
            bits -= 8;
        }
    }
    Some(out)
}

/// The current six digit TOTP code of a base32 secret (SHA-1, 30 seconds).
fn totp_now(secret: &str) -> Option<String> {
    use hmac::{Hmac, Mac};
    let key = base32_decode(&secret.to_uppercase())?;
    if key.is_empty() {
        return None;
    }
    let counter = (chrono::Utc::now().timestamp() / 30) as u64;
    let mut mac = Hmac::<sha1::Sha1>::new_from_slice(&key).ok()?;
    mac.update(&counter.to_be_bytes());
    let hash = mac.finalize().into_bytes();
    let offset = (hash[hash.len() - 1] & 0xF) as usize;
    let code = (u32::from(hash[offset] & 0x7F) << 24)
        | (u32::from(hash[offset + 1]) << 16)
        | (u32::from(hash[offset + 2]) << 8)
        | u32::from(hash[offset + 3]);
    Some(format!("{:06}", code % 1_000_000))
}

/// `TOTP::getProvisioningUri()` for a secret, label and issuer.
fn provisioning_uri(secret: &str, label: &str, issuer: &str) -> String {
    let mut query = Map::new();
    query.insert("issuer".into(), Json::String(issuer.to_owned()));
    query.insert("secret".into(), Json::String(secret.to_owned()));
    let params = base::build_query(&query).replace('+', "%20").replace("%7E", "~");
    let label = php_std::encoding::rawurlencode(format!("{issuer}:{label}").as_bytes());
    format!("otpauth://totp/{label}?{params}")
}

/// The stored secret of an authenticator (`data.secret`).
async fn authenticator_secret(ctx: &mut Context, id: &str) -> Result<Option<String>> {
    let db = ctx.db()?;
    let document = db.authorization().skip(db.get_document("authenticators", id, Vec::new(), false)).await?;
    Ok(document.json("data").and_then(|d| d.get("secret").and_then(Json::as_str).map(str::to_owned)))
}

/// Whether `otp` is the user's TOTP code now (`Challenge\TOTP::verify`).
async fn totp_verify(ctx: &mut Context, user: &User, otp: &str) -> Result<bool> {
    let Some(authenticator) = totp_authenticator(user) else {
        return Err(Error::internal("Call to a member function getAttribute() on null"));
    };
    let secret = authenticator_secret(ctx, &authenticator.id.clone()).await?.unwrap_or_default();
    Ok(totp_now(&secret).is_some_and(|now| now == otp))
}

/// `POST /v1/account/mfa/authenticators/:type`.
pub async fn create_authenticator(ctx: &mut Context) -> Result<Response> {
    let kind = ctx.required_str("type", &WhiteList::new(&["totp"]))?;
    let user = caller(ctx)?;
    if kind != "totp" {
        return Err(Error::with_message(ErrorType::GeneralArgumentInvalid, "Unknown type."));
    }
    // OTPHP refuses an empty or missing label (the email) and issuer.
    let label = user.email.clone().unwrap_or_default();
    let issuer = ctx.project.name.clone();
    if label.is_empty() || issuer.is_empty() || label.contains(':') || issuer.contains(':') {
        return Err(Error::internal("Invalid TOTP label or issuer"));
    }
    let mut bytes = [0u8; 64];
    utopia_auth::random_bytes(&mut bytes);
    let secret = base32(&bytes);

    let db = ctx.db()?;
    if let Some(existing) = totp_authenticator(&user) {
        if existing.verified == Some(true) {
            return Err(Error::new(ErrorType::UserAuthenticatorAlreadyVerified));
        }
        db.delete_document("authenticators", &existing.id).await?;
    }
    let mut authenticator = Document::default();
    authenticator.put("$id", Value::from(appwrite_core::database::unique_id().as_str()));
    authenticator.put("userId", Value::from(user.id.as_str()));
    authenticator.put("userInternalId", documents::internal_id(user.sequence));
    authenticator.put("type", Value::from("totp"));
    authenticator.put("verified", Value::Bool(false));
    let mut data = Map::new();
    data.insert("secret".into(), Json::String(secret.clone()));
    authenticator.put("data", utopia_database::Value::from_json(&Json::Object(data)));
    authenticator.set("$permissions", base::owner_permissions(&user.id))?;
    db.create_document("authenticators", authenticator).await?;
    db.purge_cached_document("users", &user.id).await?;
    ctx.event_param("userId", user.id.clone());
    let uri = provisioning_uri(&secret, &label, &issuer);
    Ok(ctx.ok(&MfaTypeModel { secret: &secret, uri: &uri }))
}

/// `PUT /v1/account/mfa/authenticators/:type`.
pub async fn update_authenticator(ctx: &mut Context) -> Result<Response> {
    let kind = ctx.required_str("type", &WhiteList::new(&["totp"]))?;
    let otp = ctx.required_str("otp", &Text::new(256))?;
    let user = caller(ctx)?;
    let mut session = session(ctx)?;
    let authenticator = if kind == "totp" { totp_authenticator(&user).cloned() } else { None };
    let Some(authenticator) = authenticator else {
        return Err(Error::new(ErrorType::UserAuthenticatorNotFound));
    };
    if authenticator.verified == Some(true) {
        return Err(Error::new(ErrorType::UserAuthenticatorAlreadyVerified));
    }
    if !totp_verify(ctx, &user, &otp).await? {
        return Err(Error::new(ErrorType::UserInvalidToken));
    }
    let db = ctx.db()?;
    let mut updates = Document::default();
    updates.put("verified", Value::Bool(true));
    db.update_document("authenticators", &authenticator.id, updates).await?;
    db.purge_cached_document("users", &user.id).await?;
    let mut factors = session.factors.clone();
    factors.push(kind);
    add_factor(ctx, &mut session, factors, None).await?;
    ctx.event_param("userId", user.id.clone());
    Ok(ctx.ok(&AccountModel { user: &user, impersonator_user_id: None, render: ctx.render() }))
}

/// `DELETE /v1/account/mfa/authenticators/:type`.
pub async fn delete_authenticator(ctx: &mut Context) -> Result<Response> {
    let kind = ctx.required_str("type", &WhiteList::new(&["totp"]))?;
    let user = caller(ctx)?;
    let authenticator = if kind == "totp" { totp_authenticator(&user).cloned() } else { None };
    let Some(authenticator) = authenticator else {
        return Err(Error::new(ErrorType::UserAuthenticatorNotFound));
    };
    let db = ctx.db()?;
    db.delete_document("authenticators", &authenticator.id).await?;
    db.purge_cached_document("users", &user.id).await?;
    ctx.event_param("userId", user.id.clone());
    Ok(ctx.no_content())
}

// ---- recovery codes ----------------------------------------------------------

/// `Type::generateBackupCodes()`: six 10-character codes.
fn backup_codes() -> Result<Vec<String>> {
    (0..6).map(|_| base::token_secret(10)).collect()
}

/// Stores the codes (`updateDocument('users', ...)`).
async fn store_codes(ctx: &mut Context, user: &User, codes: &[String]) -> Result<()> {
    let db = ctx.db()?;
    let mut updates = Document::default();
    updates.put("mfaRecoveryCodes", documents::strings(codes));
    db.update_document("users", &user.id, updates).await?;
    ctx.event_param("userId", user.id.clone());
    Ok(())
}

/// `POST /v1/account/mfa/recovery-codes`.
pub async fn create_recovery_codes(ctx: &mut Context) -> Result<Response> {
    let user = caller(ctx)?;
    if !user.mfa_recovery_codes.is_empty() {
        return Err(Error::new(ErrorType::UserRecoveryCodesAlreadyExists));
    }
    let codes = backup_codes()?;
    store_codes(ctx, &user, &codes).await?;
    Ok(ctx.created(&MfaRecoveryCodesModel { recovery_codes: &codes }))
}

/// `GET /v1/account/mfa/recovery-codes`.
pub async fn get_recovery_codes(ctx: &mut Context) -> Result<Response> {
    let user = caller(ctx)?;
    if user.mfa_recovery_codes.is_empty() {
        return Err(Error::new(ErrorType::UserRecoveryCodesNotFound));
    }
    Ok(ctx.ok(&MfaRecoveryCodesModel { recovery_codes: &user.mfa_recovery_codes }))
}

/// `PATCH /v1/account/mfa/recovery-codes`.
pub async fn update_recovery_codes(ctx: &mut Context) -> Result<Response> {
    let user = caller(ctx)?;
    if user.mfa_recovery_codes.is_empty() {
        return Err(Error::new(ErrorType::UserRecoveryCodesNotFound));
    }
    let codes = backup_codes()?;
    store_codes(ctx, &user, &codes).await?;
    Ok(ctx.ok(&MfaRecoveryCodesModel { recovery_codes: &codes }))
}

// ---- challenges ----------------------------------------------------------------

/// Whether the project's MFA policy allows a factor (recovery codes always).
fn factor_enabled(ctx: &Context, factor: &str) -> bool {
    let factors = ctx.project.auth("mfaFactors").cloned().unwrap_or(Json::Null);
    let flag = |k: &str, d: bool| factors.get(k).map(php::truthy).unwrap_or(d);
    match factor {
        "totp" => flag("totp", true),
        "email" => flag("email", true),
        "phone" => flag("phone", true),
        "custom" => flag("custom", false),
        _ => true,
    }
}

/// `POST /v1/account/mfa/challenges`.
pub async fn create_challenge(ctx: &mut Context) -> Result<Response> {
    let factor = ctx.required_str("factor", &WhiteList::new(&["email", "phone", "totp", "recoveryCode", "custom"]))?;
    let user = caller(ctx)?;
    if !factor_enabled(ctx, &factor) {
        return Err(Error::with_message(
            ErrorType::UserAuthMethodUnsupported,
            "The requested factor is disabled by the MFA factors policy",
        ));
    }
    let expire = datetime::add_seconds(datetime::now(), base::EXPIRATION_CONFIRM);
    let code = base::code_secret();
    let mut challenge = Document::default();
    challenge.put("userId", Value::from(user.id.as_str()));
    challenge.put("userInternalId", documents::internal_id(user.sequence));
    challenge.put("type", Value::from(factor.as_str()));
    challenge.put("token", Value::from(base::token_secret(256)?.as_str()));
    challenge.put("code", Value::from(code.as_str()));
    challenge.put("expire", datetime::value(&expire));
    challenge.set("$permissions", base::owner_permissions(&user.id))?;
    let db = ctx.db()?;
    let challenge = Challenge::from(&db.create_document("challenges", challenge).await?);

    match factor.as_str() {
        "phone" => {
            if ctx.state.config.sms_provider.is_empty() {
                return Err(Error::with_message(ErrorType::GeneralPhoneDisabled, "Phone provider not configured"));
            }
            let phone = user.phone.clone().unwrap_or_default();
            if phone.is_empty() {
                return Err(Error::new(ErrorType::UserPhoneNotFound));
            }
            if user.phone_verification != Some(true) {
                return Err(Error::new(ErrorType::UserPhoneNotVerified));
            }
            base::send_sms(ctx, &challenge.id, &code, &phone).await;
        }
        "email" => {
            if ctx.state.config.smtp_host.is_empty() {
                return Err(Error::with_message(ErrorType::GeneralSmtpDisabled, "SMTP disabled"));
            }
            let email = user.email.clone().unwrap_or_default();
            if email.is_empty() {
                return Err(Error::new(ErrorType::UserEmailNotFound));
            }
            if user.email_verification != Some(true) {
                return Err(Error::new(ErrorType::UserEmailNotVerified));
            }
            challenge_email(ctx, &user, &email, &code).await?;
        }
        _ => {}
    }
    ctx.event_param("userId", user.id.clone());
    ctx.event_param("challengeId", challenge.id.clone());
    Ok(ctx.created(&MfaChallengeModel { challenge: &challenge }))
}

/// The MFA challenge email.
async fn challenge_email(ctx: &mut Context, user: &User, email: &str, code: &str) -> Result<()> {
    let mut subject = ctx.text("emails.mfaChallenge.subject", None);
    let preview = ctx.text("emails.mfaChallenge.preview", None);
    let heading = ctx.text("emails.mfaChallenge.heading", None);
    let custom = base::custom_template(ctx, "email.mfaChallenge");
    let template = base::smtp_base_template(ctx);
    let valid = !template.is_empty()
        && template != "0"
        && template.chars().filter(|c| !matches!(c, '.' | '-' | '_')).all(|c| c.is_ascii_alphanumeric());
    if !valid {
        return Err(Error::with_message(ErrorType::GeneralBadRequest, "Invalid template path"));
    }
    let mut body = Template::file(ctx, "email-mfa-challenge.tpl")?
        .param("{{hello}}", &ctx.text("emails.mfaChallenge.hello", None))
        .param("{{description}}", &ctx.text("emails.mfaChallenge.description", None))
        .param("{{clientInfo}}", &ctx.text("emails.mfaChallenge.clientInfo", None))
        .param("{{thanks}}", &ctx.text("emails.mfaChallenge.thanks", None))
        .param("{{signature}}", &ctx.text("emails.mfaChallenge.signature", None))
        .render();
    let smtp = base::smtp_settings(ctx, custom.as_ref(), &mut subject, &mut body);
    let mut variables = Map::new();
    variables.insert("heading".into(), Json::String(heading));
    variables.insert("direction".into(), Json::String(ctx.text("settings.direction", None)));
    variables.insert("user".into(), user.name.clone().map(Json::String).unwrap_or(Json::Null));
    variables.insert("project".into(), Json::String(base::project_name(ctx)));
    variables.insert("otp".into(), Json::String(code.to_owned()));
    base::agent_variables(ctx, &mut variables);
    let branded = template == "email-base-styled";
    if branded {
        base::branded_variables(ctx, &mut variables);
    }
    Mail {
        recipient: email.to_owned(),
        subject,
        template: "mfa-challenge",
        body_template: format!("{PHP_ROOT}/app/config/locale/templates/{template}.tpl"),
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
    Ok(())
}

/// `PUT /v1/account/mfa/challenges`.
pub async fn update_challenge(ctx: &mut Context) -> Result<Response> {
    let challenge_id = ctx.required_str("challengeId", &Text::new(256))?;
    let otp = ctx.required_str("otp", &Text::new(256))?;
    let user = caller(ctx)?;
    let mut session = session(ctx)?;
    let db = ctx.db()?;
    let document = db.get_document("challenges", &challenge_id, Vec::new(), false).await?;
    if document.is_empty() {
        return Err(Error::new(ErrorType::UserInvalidToken));
    }
    let challenge = Challenge::from(&document);
    if challenge.expire.is_none_or(|e| e < datetime::now()) {
        return Err(Error::new(ErrorType::UserInvalidToken));
    }
    let kind = challenge.kind.clone().unwrap_or_default();
    if !factor_enabled(ctx, &kind) {
        return Err(Error::with_message(
            ErrorType::UserAuthMethodUnsupported,
            "The requested factor is disabled by the MFA factors policy",
        ));
    }
    let code_matches = || challenge.code.as_deref() == Some(otp.as_str());
    let success = match kind.as_str() {
        "totp" => totp_verify(ctx, &user, &otp).await?,
        "phone" | "email" | "custom" => code_matches(),
        "recoveryCode" if user.mfa_recovery_codes.contains(&otp) => {
            let codes: Vec<String> = user.mfa_recovery_codes.iter().filter(|c| **c != otp).cloned().collect();
            let mut updates = Document::default();
            updates.put("mfaRecoveryCodes", documents::strings(&codes));
            db.update_document("users", &user.id, updates).await?;
            true
        }
        _ => false,
    };
    if !success {
        return Err(Error::new(ErrorType::UserInvalidToken));
    }
    db.delete_document("challenges", &challenge_id).await?;
    db.purge_cached_document("users", &user.id).await?;
    let mut factors = session.factors.clone();
    factors.push(kind);
    add_factor(ctx, &mut session, factors, Some(datetime::now())).await?;
    ctx.event_param("userId", user.id.clone());
    ctx.event_param("sessionId", session.id.clone());
    Ok(ctx.ok(&SessionModel {
        session: &session,
        country_name: "",
        current: false,
        secret: None,
        render: ctx.render(),
    }))
}
