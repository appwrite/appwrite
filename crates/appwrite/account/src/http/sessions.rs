//! `/v1/account/sessions`: the caller's sessions, and the sign-ins that
//! create one (email and password, anonymous, a token, an ID token and
//! the OAuth2 redirect flow).

use serde_json::Value as Json;
use utopia_database::{Document, Query, Value};
use utopia_http::Response;
use utopia_validators::{Nullable, Range, Text, php};

use appwrite_core::database::documents::{self, Session, User};
use appwrite_core::database::filters;
use appwrite_core::datetime;
use appwrite_core::platform::{Audit, Context, Labels, Route};
use appwrite_core::response::{SessionListModel, SessionModel};
use appwrite_core::validators::{CustomId, Password, uid};
use appwrite_core::{Error, ErrorType, Result};
use utopia_emails::EmailValidator;

use crate::base::{self, token_type};
use crate::{GROUPS, GROUPS_AUTH_SESSION, GROUPS_SESSION, route};

const EVENT_CREATE: Option<&str> = Some("users.[userId].sessions.[sessionId].create");
const EVENT_DELETE: Option<&str> = Some("users.[userId].sessions.[sessionId].delete");
const AUDIT_CREATE: Option<Audit> = Some(Audit { event: "session.create", resource: "user/{response.userId}" });

pub fn routes() -> Vec<Route> {
    vec![
        route!(
            GET,
            "/v1/account/sessions",
            "listSessions",
            "listSessions",
            &["account"],
            None,
            None,
            GROUPS,
            Labels::NONE,
            list
        ),
        route!(
            DELETE,
            "/v1/account/sessions",
            "deleteSessions",
            "deleteSessions",
            &["account"],
            EVENT_DELETE,
            Some(Audit { event: "session.delete", resource: "user/{user.$id}" }),
            GROUPS,
            Labels { abuse_limit: 100, ..Labels::NONE },
            delete_all
        ),
        route!(
            GET,
            "/v1/account/sessions/:sessionId",
            "getSession",
            "getSession",
            &["account"],
            None,
            None,
            GROUPS,
            Labels::NONE,
            get
        ),
        route!(
            DELETE,
            "/v1/account/sessions/:sessionId",
            "deleteSession",
            "deleteSession",
            &["account"],
            EVENT_DELETE,
            Some(Audit { event: "session.delete", resource: "user/{user.$id}" }),
            &["api", "account", "mfa"],
            Labels { abuse_limit: 100, impersonation: "allow", ..Labels::NONE },
            delete
        ),
        route!(
            PATCH,
            "/v1/account/sessions/:sessionId",
            "updateSession",
            "updateSession",
            &["account"],
            Some("users.[userId].sessions.[sessionId].update"),
            Some(Audit { event: "session.update", resource: "user/{response.userId}" }),
            GROUPS,
            Labels { abuse_limit: 10, ..Labels::NONE },
            update
        ),
        route!(
            POST,
            "/v1/account/sessions/email",
            "createEmailPasswordSession",
            "createEmailPasswordSession",
            &["sessions.write"],
            EVENT_CREATE,
            AUDIT_CREATE,
            GROUPS_AUTH_SESSION,
            Labels {
                abuse_limit: 10,
                abuse_key: &["url:{url},email:{param-email}"],
                abuse_reset: &[201],
                auth_type: "email-password",
                ..Labels::NONE
            },
            email
        ),
        // The alias of the email session.
        route!(
            POST,
            "/v1/account/sessions",
            "createEmailPasswordSession",
            "createEmailPasswordSession",
            &["sessions.write"],
            EVENT_CREATE,
            AUDIT_CREATE,
            GROUPS_AUTH_SESSION,
            Labels {
                abuse_limit: 10,
                abuse_key: &["url:{url},email:{param-email}"],
                abuse_reset: &[201],
                auth_type: "email-password",
                ..Labels::NONE
            },
            email
        ),
        route!(
            POST,
            "/v1/account/sessions/anonymous",
            "createAnonymousSession",
            "createAnonymousSession",
            &["sessions.write"],
            EVENT_CREATE,
            AUDIT_CREATE,
            GROUPS_AUTH_SESSION,
            Labels { abuse_limit: 50, abuse_key: &["ip:{ip}"], auth_type: "anonymous", ..Labels::NONE },
            anonymous
        ),
        route!(
            POST,
            "/v1/account/sessions/token",
            "createSession",
            "createSession",
            &["sessions.write"],
            EVENT_CREATE,
            AUDIT_CREATE,
            GROUPS_SESSION,
            Labels {
                abuse_limit: 10,
                abuse_key: &["ip:{ip},userId:{param-userId}"],
                abuse_reset: &[201],
                ..Labels::NONE
            },
            token
        ),
        route!(
            PUT,
            "/v1/account/sessions/magic-url",
            "updateMagicURLSession",
            "updateMagicURLSession",
            &["sessions.write"],
            EVENT_CREATE,
            AUDIT_CREATE,
            GROUPS_SESSION,
            Labels {
                abuse_limit: 10,
                abuse_key: &["ip:{ip},userId:{param-userId}"],
                abuse_reset: &[201],
                ..Labels::NONE
            },
            magic_url
        ),
        route!(
            PUT,
            "/v1/account/sessions/phone",
            "updatePhoneSession",
            "updatePhoneSession",
            &["sessions.write"],
            EVENT_CREATE,
            AUDIT_CREATE,
            GROUPS_SESSION,
            Labels { abuse_limit: 10, abuse_key: &["ip:{ip},userId:{param-userId}"], ..Labels::NONE },
            token
        ),
        route!(
            POST,
            "/v1/account/sessions/id-token",
            "createIdTokenSession",
            "createIdTokenSession",
            &["sessions.write"],
            EVENT_CREATE,
            AUDIT_CREATE,
            GROUPS_SESSION,
            Labels {
                abuse_limit: 10,
                abuse_key: &["url:{url},ip:{ip}"],
                abuse_reset: &[201],
                session_allow_active: true,
                ..Labels::NONE
            },
            crate::oauth2::id_token_session
        ),
        route!(
            GET,
            "/v1/account/sessions/oauth2/:provider",
            "createOAuth2Session",
            "createOAuth2Session",
            &["sessions.write"],
            None,
            None,
            GROUPS,
            Labels { abuse_limit: 50, abuse_key: &["ip:{ip}"], error_page: true, ..Labels::NONE },
            crate::oauth2::create_session
        ),
        route!(
            namespace: "",
            GET,
            "/v1/account/sessions/oauth2/callback/:provider/:projectId",
            "",
            "",
            &["public"],
            None,
            None,
            &["account"],
            Labels { error_page: true, ..Labels::NONE },
            crate::oauth2::callback
        ),
        route!(
            namespace: "",
            POST,
            "/v1/account/sessions/oauth2/callback/:provider/:projectId",
            "",
            "",
            &["public"],
            None,
            None,
            &["account"],
            Labels { error_page: true, any_origin: true, ..Labels::NONE },
            crate::oauth2::callback
        ),
        route!(
            namespace: "",
            GET,
            "/v1/account/sessions/oauth2/:provider/redirect",
            "",
            "",
            &["public"],
            EVENT_CREATE,
            Some(Audit { event: "session.create", resource: "user/{user.$id}" }),
            &["api", "account", "session"],
            Labels {
                abuse_limit: 50,
                abuse_key: &["ip:{ip}"],
                session_allow_active: true,
                error_page: true,
                ..Labels::NONE
            },
            crate::oauth2::redirect
        ),
    ]
}

/// The user whose sessions are listed (the impersonated one, else the caller).
fn target_user(ctx: &Context) -> Result<User> {
    ctx.roles_user().cloned().ok_or_else(|| Error::new(ErrorType::UserUnauthorized))
}

/// The caller (the impersonator when impersonating).
fn caller(ctx: &Context) -> Result<User> {
    ctx.user.clone().ok_or_else(|| Error::new(ErrorType::UserUnauthorized))
}

/// `sessionId`, with `current` for the request's session.
fn session_id(ctx: &Context) -> Result<Option<String>> {
    let id = ctx.optional_str("sessionId", "current", &uid())?.unwrap_or_default();
    Ok(if id == "current" { ctx.session.as_ref().map(|s| s.id.clone()) } else { Some(id) })
}

/// `GET /v1/account/sessions`.
pub async fn list(ctx: &mut Context) -> Result<Response> {
    let include_total = ctx.optional_bool("total", true)?;
    let user = target_user(ctx)?;
    let current = ctx.session.as_ref().map(|s| s.id.clone());
    let names: Vec<String> =
        user.sessions.iter().map(|s| base::country_name(ctx, s.country_code.as_deref().unwrap_or(""))).collect();
    let render = ctx.render();
    let sessions: Vec<SessionModel<'_>> = user
        .sessions
        .iter()
        .zip(names.iter())
        .map(|(s, n)| SessionModel {
            session: s,
            country_name: n,
            current: current.as_deref() == Some(s.id.as_str()),
            secret: None,
            render,
        })
        .collect();
    let total = if include_total { sessions.len() as i64 } else { 0 };
    Ok(ctx.ok(&SessionListModel { total, sessions }))
}

/// `GET /v1/account/sessions/:sessionId`.
pub async fn get(ctx: &mut Context) -> Result<Response> {
    let id = session_id(ctx)?;
    let user = target_user(ctx)?;
    let current = ctx.session.as_ref().map(|s| s.id.clone());
    let Some(session) = user.sessions.iter().find(|s| Some(&s.id) == id.as_ref()) else {
        return Err(Error::new(ErrorType::UserSessionNotFound));
    };
    let country = base::country_name(ctx, session.country_code.as_deref().unwrap_or(""));
    Ok(ctx.ok(&SessionModel {
        session,
        country_name: &country,
        current: current.as_deref() == Some(session.id.as_str()),
        secret: None,
        render: ctx.render(),
    }))
}

/// The user's sessions as documents (the `subQuerySessions` filter), for
/// the delete messages that carry them whole.
async fn session_documents(ctx: &mut Context, user: &User) -> Result<Vec<Document>> {
    let db = ctx.db()?;
    let mut document = Document::default();
    document.put("$id", Value::from(user.id.as_str()));
    document.put("$sequence", Value::from(user.sequence.to_string().as_str()));
    Ok(match filters::sub_query(&db, "subQuerySessions", &document).await? {
        Value::Array(items) => items.values().filter_map(utopia_database::value::as_document).cloned().collect(),
        _ => Vec::new(),
    })
}

/// A document as PHP's `getArrayCopy()` encodes it in a queue message.
fn document_json(document: &Document) -> Json {
    utopia_database::value::to_plain(&Value::from(document.clone())).to_json()
}

/// `DELETE_TYPE_SESSION_TARGETS`: the targets of a deleted session.
fn delete_targets(ctx: &mut Context, mut session: Document, current: bool, country: &str) {
    session.put("current", Value::Bool(current));
    session.put("countryName", Value::from(country));
    let message = appwrite_core::event::delete_message(
        &ctx.project.message_payload(),
        "session_targets",
        document_json(&session),
    );
    ctx.events.deletes.push(message);
}

/// `DELETE /v1/account/sessions`.
pub async fn delete_all(ctx: &mut Context) -> Result<Response> {
    let current = ctx.optional_bool("current", true)?;
    let user = caller(ctx)?;
    let calling = ctx.session.as_ref().map(|s| s.id.clone());
    // Nothing to keep (an account API key): refused rather than deleting
    // every session.
    if !current && calling.is_none() {
        return Err(Error::new(ErrorType::UserSessionNotFound));
    }
    let documents = session_documents(ctx, &user).await?;
    let db = ctx.db()?;
    let mut response = ctx.no_content();
    let mut current_session: Option<Session> = None;
    let fallback = !base::domain_verification(ctx);
    for document in documents {
        let session = Session::from(&document);
        if !current && Some(&session.id) == calling.as_ref() {
            continue;
        }
        db.delete_document("sessions", &session.id).await?;
        if fallback && current {
            response.add("x-fallback-cookies", "[]");
        }
        let country = base::country_name(ctx, session.country_code.as_deref().unwrap_or(""));
        if base::token_matches(&ctx.store.secret, session.secret.as_deref().unwrap_or("")) {
            base::clear_session_cookies(ctx, &mut response);
            let payload = base::session_payload(ctx, &session, true, &country, None);
            ctx.events.payload = Some(payload);
            delete_targets(ctx, document, true, &country);
            current_session = Some(session);
        }
    }
    db.purge_cached_document("users", &user.id).await?;
    match current_session {
        Some(session) => {
            ctx.event_param("userId", user.id.clone());
            ctx.event_param("sessionId", session.id.clone());
        }
        // `$queueForEvents->reset()`: no event.
        None => {
            ctx.events.params.clear();
            ctx.events.payload = None;
        }
    }
    Ok(response)
}

/// `DELETE /v1/account/sessions/:sessionId`.
pub async fn delete(ctx: &mut Context) -> Result<Response> {
    let id = session_id(ctx)?;
    let user = caller(ctx)?;
    let current = ctx.session.as_ref().map(|s| s.id.clone());
    let documents = session_documents(ctx, &user).await?;
    let Some(document) = documents.into_iter().find(|d| Some(d.id()) == id.as_deref()) else {
        return Err(Error::new(ErrorType::UserSessionNotFound));
    };
    let session = Session::from(&document);
    let db = ctx.db()?;
    db.delete_document("sessions", &session.id).await?;
    let is_current = current.as_deref() == Some(session.id.as_str());
    let country =
        if is_current { base::country_name(ctx, session.country_code.as_deref().unwrap_or("")) } else { String::new() };
    let mut response = ctx.no_content();
    if base::token_matches(&ctx.store.secret, session.secret.as_deref().unwrap_or("")) {
        if !base::domain_verification(ctx) {
            response.add("x-fallback-cookies", "[]");
        }
        base::clear_session_cookies(ctx, &mut response);
    }
    db.purge_cached_document("users", &user.id).await?;
    ctx.event_param("userId", user.id.clone());
    ctx.event_param("sessionId", session.id.clone());
    ctx.events.payload = Some(base::session_payload(ctx, &session, is_current, &country, None));
    let mut document = document;
    document.put("current", Value::Bool(is_current));
    if is_current {
        document.put("countryName", Value::from(country.as_str()));
    }
    let message = appwrite_core::event::delete_message(
        &ctx.project.message_payload(),
        "session_targets",
        document_json(&document),
    );
    ctx.events.deletes.push(message);
    Ok(response)
}

/// `PATCH /v1/account/sessions/:sessionId`: extends the session and
/// refreshes its OAuth2 provider's access token.
pub async fn update(ctx: &mut Context) -> Result<Response> {
    let id = session_id(ctx)?;
    let user = caller(ctx)?;
    let Some(mut session) = user.sessions.iter().find(|s| Some(&s.id) == id.as_ref()).cloned() else {
        return Err(Error::new(ErrorType::UserSessionNotFound));
    };
    let expire = datetime::add_seconds(datetime::now(), base::duration(ctx));
    session.expire = Some(expire);
    let mut updates = Document::default();
    updates.put("expire", datetime::value(&expire));

    let provider = session.provider.clone().unwrap_or_default();
    let refresh = session.provider_refresh_token.clone().unwrap_or_default();
    if !refresh.is_empty() {
        let tokens = crate::oauth2::refresh(ctx, &provider, &refresh).await?;
        let expiry = datetime::add_seconds(datetime::now(), tokens.expires_in);
        session.provider_access_token = Some(tokens.access_token.clone());
        session.provider_refresh_token = Some(tokens.refresh_token.clone());
        session.provider_access_token_expiry = Some(expiry);
        updates.put("providerAccessToken", Value::from(tokens.access_token.as_str()));
        updates.put("providerRefreshToken", Value::from(tokens.refresh_token.as_str()));
        updates.put("providerAccessTokenExpiry", datetime::value(&expiry));
        // The photo refresh is best-effort.
        let db = ctx.db()?;
        if let Ok(identity) = db
            .find_one(
                "identities",
                vec![
                    Query::equal("provider", [provider.as_str()]),
                    Query::equal("providerUid", [session.provider_uid.as_deref().unwrap_or("")]),
                ],
            )
            .await
            && !identity.is_empty()
        {
            let mut photo = Document::default();
            photo.put("photo", Value::from(tokens.photo.as_str()));
            let _ = db.update_document("identities", identity.id(), photo).await;
        }
    }

    let db = ctx.db()?;
    db.update_document("sessions", &session.id, updates).await?;
    db.purge_cached_document("users", &user.id).await?;
    ctx.event_param("userId", user.id.clone());
    ctx.event_param("sessionId", session.id.clone());
    ctx.events.payload = Some(base::session_payload(ctx, &session, false, "", None));
    Ok(ctx.ok(&SessionModel {
        session: &session,
        country_name: "",
        current: false,
        secret: None,
        render: ctx.render(),
    }))
}

/// `POST /v1/account/sessions/email`.
pub async fn email(ctx: &mut Context) -> Result<Response> {
    let email = ctx.required_str("email", &EmailValidator::default())?;
    let password = ctx.required_str("password", &Password::default())?;
    let duration = ctx.optional("duration", Json::Null, &Nullable(Range::new(60, base::SESSION_DURATION)))?;
    let email = email.to_lowercase();
    let max = base::duration(ctx);
    let duration = match Range::value(&duration) {
        Some(d) if d > max => {
            return Err(Error::with_message(
                ErrorType::GeneralArgumentInvalid,
                format!("Session duration cannot exceed the project maximum session length of {max} seconds."),
            ));
        }
        Some(d) => d,
        None => max,
    };

    let db = ctx.db()?;
    let profile = db.find_one("users", vec![Query::equal("email", [email.as_str()])]).await?;
    let user = (!profile.is_empty()).then(|| User::from(&profile));
    let verified = match &user {
        Some(user) if user.password_update.is_some() => verify_password(user, &password).await?,
        _ => false,
    };
    let Some(mut user) = user.filter(|_| verified) else {
        return Err(Error::new(ErrorType::UserInvalidCredentials));
    };
    base::act_as(ctx, &user);
    if user.status == Some(false) {
        return Err(Error::new(ErrorType::UserBlocked));
    }
    let rotation = ctx.project.auth("passwordRotation").cloned().unwrap_or(Json::Null);
    if rotation.get("enabled").is_some_and(php::truthy)
        && let Some(updated) = user.password_update
    {
        let max_age = rotation.get("maxAge").and_then(Json::as_i64).unwrap_or(31_536_000);
        let age = chrono::Utc::now().naive_utc() - updated;
        if age.num_microseconds().unwrap_or(i64::MAX) as f64 / 1_000_000.0 > max_age as f64 {
            return Err(Error::new(ErrorType::UserPasswordResetRequired));
        }
    }
    ctx.state.hooks.password_validator(ctx, &password, Some(&user)).await?;

    let secret = base::token_secret(256)?;
    let expire = datetime::add_seconds(datetime::now(), duration);
    let mut session = base::new_session(ctx, &user, "email", &secret, &["password"], &expire).await?;
    session.put("providerUid", Value::from(email.as_str()));
    ctx.authorization.add_role(&format!("user:{}", user.id));

    let policy = ctx.project.auth("passwordPwned").cloned().unwrap_or(Json::Null);
    if policy.get("enabled").map(php::truthy).unwrap_or(true) {
        let pwned = ctx.state.pwned.is_pwned(&password).await?;
        if Some(pwned) != user.password_pwned {
            user.password_pwned = Some(pwned);
            let mut updates = Document::default();
            updates.put("passwordPwned", Value::Bool(pwned));
            db.update_document("users", &user.id, updates).await?;
        }
        if pwned && policy.get("sessions").is_some_and(php::truthy) {
            return Err(Error::new(ErrorType::UserPasswordResetRequired));
        }
    }

    // Re-hash with the current algorithm and costs.
    if user.hash.as_deref() != Some("argon2")
        || !user.password.as_deref().unwrap_or("").starts_with("$argon2id$v=19$m=7168,t=5,p=1$")
    {
        let hash = base::proof();
        let hashed = base::hash_password(password.clone()).await?;
        let mut updates = Document::default();
        updates.put("password", Value::from(hashed.as_str()));
        updates.put("hash", Value::from(hash.name()));
        updates.put("hashOptions", base::hash_options(hash.as_ref()));
        db.update_document("users", &user.id, updates).await?;
    }

    let session = Session::from(&db.create_document("sessions", session).await?);
    db.purge_cached_document("users", &user.id).await?;
    let encoded = base::encode_store(&user.id, &secret)?;
    // The response keeps the stored expiry; only the cookies use this one.
    let cookie_expire = datetime::add_seconds(datetime::now(), duration);
    let response = created_response(ctx, &session, &encoded, &cookie_expire, false);
    ctx.event_param("userId", user.id.clone());
    ctx.event_param("sessionId", session.id.clone());
    if let Err(e) = base::session_alert(ctx, &user, &session).await {
        tracing::warn!(error = %e, "session alert failed");
    }
    Ok(response)
}

/// The `201` of a sign-in: the session as stored (or with `expire` shown
/// as `cookie_expire` when `override_expire`), its cookies.
fn created_response(
    ctx: &Context,
    session: &Session,
    encoded: &str,
    cookie_expire: &chrono::NaiveDateTime,
    override_expire: bool,
) -> Response {
    let mut shown = session.clone();
    if override_expire {
        shown.expire = Some(*cookie_expire);
    }
    let country = base::country_name(ctx, session.country_code.as_deref().unwrap_or(""));
    let model = SessionModel {
        session: &shown,
        country_name: &country,
        current: true,
        secret: Some(encoded),
        render: ctx.render(),
    };
    let mut response = ctx.created(&model);
    base::add_session_cookies(ctx, &mut response, encoded, cookie_expire);
    response
}

/// Whether `password` matches the user's stored hash (its own algorithm
/// and options), on the blocking pool.
async fn verify_password(user: &User, password: &str) -> Result<bool> {
    let kind = user.hash.clone().unwrap_or_else(|| "argon2".to_owned());
    let options = match &user.hash_options {
        Some(json) => match php_std::Value::from_json(json) {
            php_std::Value::Array(a) => a,
            _ => php_std::types::Array::new(),
        },
        None => match utopia_database::value::to_plain(&base::hash_options(base::proof().as_ref())) {
            php_std::Value::Array(a) => a,
            _ => php_std::types::Array::new(),
        },
    };
    let stored = user.password.clone().unwrap_or_default();
    let password = password.to_owned();
    utopia_async::spawn_blocking(move || {
        let Ok(hash) = utopia_auth::proofs::Password::create_hash(&kind, &options) else { return false };
        hash.verify(password.as_bytes(), stored.as_bytes()).unwrap_or(false)
    })
    .await
    .map_err(|e| Error::internal(e.to_string()))
}

/// `POST /v1/account/sessions/anonymous`.
pub async fn anonymous(ctx: &mut Context) -> Result<Response> {
    if ctx.project.is_console() {
        return Err(Error::with_message(ErrorType::UserAnonymousConsoleProhibited, "Failed to create anonymous user"));
    }
    base::check_user_limit(ctx, false).await?;
    let user_id = appwrite_core::database::unique_id();
    let mut user = base::new_user(&user_id);
    user.put("email", Value::Null);
    base::set_default_hash(&mut user);
    user.put("name", Value::Null);
    user.put("mfa", Value::Bool(false));
    user.put("authenticators", Value::Null);
    user.put("search", Value::from(user_id.as_str()));
    let user = base::create_user(ctx, user).await?;
    base::act_as(ctx, &user);

    let secret = base::token_secret(256)?;
    let duration = base::duration(ctx);
    let expire = datetime::add_seconds(datetime::now(), duration);
    let session = base::new_session(ctx, &user, "anonymous", &secret, &["anonymous"], &expire).await?;
    ctx.authorization.add_role(&format!("user:{}", user.id));
    let db = ctx.db()?;
    let session = Session::from(&db.create_document("sessions", session).await?);
    db.purge_cached_document("users", &user.id).await?;
    ctx.event_param("userId", user.id.clone());
    ctx.event_param("sessionId", session.id.clone());
    let encoded = base::encode_store(&user.id, &secret)?;
    let cookie_expire = datetime::add_seconds(datetime::now(), duration);
    Ok(created_response(ctx, &session, &encoded, &cookie_expire, false))
}

/// `POST /v1/account/sessions/token` and `PUT /v1/account/sessions/phone`.
pub async fn token(ctx: &mut Context) -> Result<Response> {
    exchange(ctx, 256).await
}

/// `PUT /v1/account/sessions/magic-url`: the session secret has the magic
/// URL token's length.
pub async fn magic_url(ctx: &mut Context) -> Result<Response> {
    exchange(ctx, 64).await
}

/// Exchanges a token's secret for a session (`$createSession`).
async fn exchange(ctx: &mut Context, secret_length: i64) -> Result<Response> {
    let user_id = ctx.required_str("userId", &CustomId)?;
    let mut secret = ctx.required_str("secret", &Text::new(256))?;

    // An OAuth2 token flow's secret is a JWT carrying the provider.
    let mut oauth_provider: Option<String> = None;
    if let Ok(claims) = appwrite_core::auth::jwt::decode(&ctx.state.config.openssl_key_v1, &secret, 60) {
        let provider = claims.get("provider").and_then(Json::as_str).unwrap_or("").to_owned();
        if provider.is_empty() {
            return Err(Error::new(ErrorType::UserInvalidToken));
        }
        oauth_provider = Some(provider);
        secret = claims.get("secret").and_then(Json::as_str).unwrap_or("").to_owned();
    }

    let Some(mut user) = base::load_user(ctx, &user_id).await? else {
        return Err(Error::new(ErrorType::UserInvalidToken));
    };
    base::act_as(ctx, &user);
    let now = datetime::now();
    let tokens = base::user_tokens(ctx, &user).await?;
    let verified = tokens.into_iter().find(|t| {
        use appwrite_core::database::documents::Attributes;
        t.is_set("secret")
            && t.is_set("expire")
            && t.is_set("type")
            && base::token_matches(&secret, &t.string_or_empty("secret"))
            && t.datetime("expire").is_some_and(|e| e >= now)
    });
    let Some(verified) = verified else {
        return Err(Error::new(ErrorType::UserInvalidToken));
    };
    let kind = verified.get("type").map(utopia_database::value::to_long).unwrap_or(0);
    if kind == token_type::OAUTH2 && oauth_provider.is_none() {
        return Err(Error::new(ErrorType::UserInvalidToken));
    }
    let factor = match kind {
        token_type::MAGIC_URL | token_type::OAUTH2 | token_type::EMAIL => "email",
        token_type::PHONE => "phone",
        token_type::GENERIC => "token",
        token_type::PASSKEY => "passkey",
        _ => return Err(Error::new(ErrorType::UserInvalidToken)),
    };
    let provider = match kind {
        token_type::MAGIC_URL => "magic-url".to_owned(),
        token_type::PHONE => "phone".to_owned(),
        token_type::OAUTH2 => oauth_provider.clone().unwrap_or_default(),
        token_type::PASSKEY => "passkey".to_owned(),
        _ => "token".to_owned(),
    };

    let duration = base::duration(ctx);
    let session_secret = base::token_secret(secret_length)?;
    let expire = datetime::add_seconds(datetime::now(), duration);
    let mut session = base::new_session(ctx, &user, &provider, &session_secret, &[factor], &expire).await?;
    let db = ctx.db()?;
    if kind == token_type::OAUTH2 {
        let provider = oauth_provider.clone().unwrap_or_default();
        let identity = db
            .authorization()
            .skip(db.find_one(
                "identities",
                vec![
                    Query::equal("provider", [provider.as_str()]),
                    Query::equal("userInternalId", [user.sequence.to_string()]),
                ],
            ))
            .await?;
        if identity.is_empty() {
            return Err(Error::new(ErrorType::UserInvalidToken));
        }
        for key in ["providerUid", "providerRefreshToken", "providerAccessToken", "providerAccessTokenExpiry"] {
            session.put(key, identity.get(key).cloned().unwrap_or(Value::Null));
        }
        session.put("factors", documents::strings(&[factor.to_owned(), "oauth2".to_owned()]));
    }
    if kind == token_type::PASSKEY {
        session.put("factors", documents::strings(&["passkey".to_owned(), "userVerification".to_owned()]));
        session.put("mfaUpdatedAt", datetime::value(&datetime::now()));
    }
    ctx.authorization.add_role(&format!("user:{}", user.id));

    // Claim the token and issue the session together.
    let token_id = verified.id().to_owned();
    let created = db
        .with_transaction(|| {
            let db = db.clone();
            let session = session.clone();
            let token_id = token_id.clone();
            async move {
                let deleted = db.authorization().skip(db.delete_document("tokens", &token_id)).await?;
                if !deleted {
                    return Err(utopia_database::Error::new(utopia_database::Kind::NotFound, "token claimed"));
                }
                db.create_document("sessions", session).await
            }
        })
        .await;
    let session = match created {
        Ok(session) => Session::from(&session),
        Err(e) if e.is_a(utopia_database::Kind::NotFound) => return Err(Error::new(ErrorType::UserInvalidToken)),
        Err(e) => return Err(e.into()),
    };
    db.purge_cached_document("users", &user.id).await?;

    if matches!(kind, token_type::MAGIC_URL | token_type::EMAIL) {
        user.email_verification = Some(true);
    }
    if kind == token_type::PHONE {
        user.phone_verification = Some(true);
    }
    let mut updates = Document::default();
    updates.put("emailVerification", user.email_verification.map(Value::Bool).unwrap_or(Value::Null));
    updates.put("phoneVerification", user.phone_verification.map(Value::Bool).unwrap_or(Value::Null));
    if db.update_document("users", &user.id, updates).await.is_err() {
        return Err(Error::with_message(ErrorType::GeneralServerError, "Failed saving user to DB"));
    }

    if let Err(e) = base::session_alert(ctx, &user, &session).await {
        tracing::warn!(error = %e, "session alert failed");
    }
    ctx.event_param("userId", user.id.clone());
    ctx.event_param("sessionId", session.id.clone());
    let encoded = base::encode_store(&user.id, &session_secret)?;
    let cookie_expire = datetime::add_seconds(datetime::now(), duration);
    Ok(created_response(ctx, &session, &encoded, &cookie_expire, true))
}
