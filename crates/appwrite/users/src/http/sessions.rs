//! Sessions, tokens and JWTs created on behalf of a user.

use serde_json::{Map, Value, json};
use utopia_database::{FromRow, Param, datetime};
use utopia_http::Response;
use utopia_validators::Range;

use appwrite_core::auth::jwt;
use appwrite_core::crypto;
use appwrite_core::database::documents::{Relations, Session, Token};
use appwrite_core::platform::Context;
use appwrite_core::response::{JwtModel, SessionListModel, SessionModel, TokenModel, Version};
use appwrite_core::validators::{CustomId, KeywordId, uid};
use appwrite_core::{Error, ErrorType, Result};

use crate::base;

fn require_server(ctx: &Context) -> Result<()> {
    if ctx.is_key() || ctx.is_privileged() { Ok(()) } else { Err(Error::new(ErrorType::GeneralUnauthorizedScope)) }
}

fn country_name(ctx: &Context, code: &str) -> String {
    let unknown = ctx.text("locale.country.unknown", None);
    ctx.text(&format!("countries.{}", code.to_lowercase()), Some(&unknown))
}

/// `POST /v1/users/:userId/sessions`.
pub async fn create(ctx: &mut Context) -> Result<Response> {
    let user_id = ctx.required_str("userId", &CustomId)?;
    require_server(ctx)?;
    let user = base::user_or_404(ctx, &user_id, Relations::NONE).await?;

    let secret = utopia_auth::proofs::token(256);
    let user_agent = ctx.user_agent("UNKNOWN");
    let detected = appwrite_core::detector::detect(&user_agent);
    let duration = ctx.project.auth_int("duration", 31_536_000);
    let expire = datetime::add_seconds(datetime::now(), duration);
    let country = ctx.state.geo.country_code(&ctx.ip).await.to_lowercase();
    let id = utopia_database::id::unique();
    let columns = vec![
        ("userId", Param::text(user.id.as_str())),
        ("userInternalId", Param::Text(user.sequence.to_string())),
        ("provider", Param::text("server")),
        ("secret", Param::Text(crypto::encrypt_env(&utopia_auth::proofs::sha256(&secret)))),
        ("userAgent", Param::Text(user_agent)),
        ("factors", Param::string_list(&["server".to_owned()])),
        ("ip", Param::text(ctx.ip.as_str())),
        ("countryCode", Param::text(country.as_str())),
        ("expire", Param::Timestamp(expire)),
        ("osCode", Param::Text(detected.os_code)),
        ("osName", Param::Text(detected.os_name)),
        ("osVersion", Param::Text(detected.os_version)),
        ("clientType", Param::Text(detected.client_type)),
        ("clientCode", Param::Text(detected.client_code)),
        ("clientName", Param::Text(detected.client_name)),
        ("clientVersion", Param::Text(detected.client_version)),
        ("clientEngine", Param::Text(detected.client_engine)),
        ("clientEngineVersion", Param::Text(detected.client_engine_version)),
        ("deviceName", Param::opt_text(detected.device_name)),
        ("deviceBrand", Param::opt_text(detected.device_brand)),
        ("deviceModel", Param::opt_text(detected.device_model)),
    ];
    let db = ctx.db()?;
    let row = db.insert("sessions", &id, &base::owner_permissions(&user.id), columns, Session::COLUMNS).await?;
    let session = Session::from_row(&row)?;
    db.purge_cached_document("users", &user.id).await;
    ctx.metric("sessions", 1);

    let encoded = utopia_auth::store::encode(&user.id, &secret);
    let country_name = country_name(ctx, &country);
    let model = SessionModel {
        session: &session,
        country_name: &country_name,
        current: false,
        secret: Some(&encoded),
        render: ctx.render(),
    };
    ctx.event_param("userId", user.id.clone());
    ctx.event_param("sessionId", session.id.clone());
    ctx.event_payload(&model);
    Ok(ctx.created(&model))
}

/// `GET /v1/users/:userId/sessions`.
pub async fn list(ctx: &mut Context) -> Result<Response> {
    let user_id = ctx.required_str("userId", &uid())?;
    let include_total = ctx.optional_bool("total", true)?;
    let user = base::user_or_404(ctx, &user_id, Relations::SESSIONS).await?;
    let names: Vec<String> =
        user.sessions.iter().map(|s| country_name(ctx, s.country_code.as_deref().unwrap_or(""))).collect();
    let render = ctx.render();
    let sessions: Vec<SessionModel<'_>> = user
        .sessions
        .iter()
        .zip(names.iter())
        .map(|(s, n)| SessionModel { session: s, country_name: n, current: false, secret: None, render })
        .collect();
    let total = if include_total { sessions.len() as i64 } else { 0 };
    Ok(ctx.ok(&SessionListModel { total, sessions }))
}

/// `DELETE /v1/users/:userId/sessions/:sessionId`.
pub async fn delete(ctx: &mut Context) -> Result<Response> {
    let user_id = ctx.required_str("userId", &uid())?;
    let session_id = ctx.required_str("sessionId", &uid())?;
    require_server(ctx)?;
    let user = base::user_or_404(ctx, &user_id, Relations::NONE).await?;
    let db = ctx.db()?;
    let session = match db.get::<Session>("sessions", &session_id).await? {
        Some(s) if s.user_id == user.id => s,
        _ => return Err(Error::new(ErrorType::UserSessionNotFound)),
    };
    if db.delete("sessions", &session.id).await? {
        ctx.metric("sessions", -1);
    }
    db.purge_cached_document("users", &user.id).await;
    let country = country_name(ctx, session.country_code.as_deref().unwrap_or(""));
    let model =
        SessionModel { session: &session, country_name: &country, current: false, secret: None, render: ctx.render() };
    ctx.event_param("userId", user.id.clone());
    ctx.event_param("sessionId", session.id.clone());
    ctx.event_payload(&model);
    Ok(ctx.no_content())
}

/// `DELETE /v1/users/:userId/sessions`.
pub async fn delete_all(ctx: &mut Context) -> Result<Response> {
    let user_id = ctx.required_str("userId", &uid())?;
    require_server(ctx)?;
    let user = base::user_or_404(ctx, &user_id, Relations::SESSIONS).await?;
    let db = ctx.db()?;
    for session in &user.sessions {
        if db.delete("sessions", &session.id).await? {
            ctx.metric("sessions", -1);
        }
    }
    db.purge_cached_document("users", &user.id).await;
    ctx.event_param("userId", user.id.clone());
    let payload = base::user_payload(ctx, &user);
    ctx.events.payload = Some(payload);
    Ok(ctx.no_content())
}

/// `POST /v1/users/:userId/tokens`.
pub async fn token(ctx: &mut Context) -> Result<Response> {
    let user_id = ctx.required_str("userId", &uid())?;
    let length = ctx.optional("length", json!(6), &Range::new(4, 128))?;
    let expire = ctx.optional("expire", json!(900), &Range::new(60, 31_536_000))?;
    let length = Range::value(&length).unwrap_or(6) as usize;
    let expire = Range::value(&expire).unwrap_or(900);
    let user = base::user_or_404(ctx, &user_id, Relations::NONE).await?;

    let secret = utopia_auth::proofs::token(length);
    let expire_at = datetime::add_seconds(datetime::now(), expire);
    let id = utopia_database::id::unique();
    let columns = vec![
        ("userId", Param::text(user.id.as_str())),
        ("userInternalId", Param::Text(user.sequence.to_string())),
        ("type", Param::Int4(8)),
        ("secret", Param::Text(crypto::encrypt_env(&utopia_auth::proofs::sha256(&secret)))),
        ("expire", Param::Timestamp(expire_at)),
        ("userAgent", Param::Text(ctx.user_agent("UNKNOWN"))),
        ("ip", Param::text(ctx.ip.as_str())),
    ];
    let db = ctx.db()?;
    let row = db.insert("tokens", &id, &[], columns, Token::COLUMNS).await?;
    let token = Token::from_row(&row)?;
    db.purge_cached_document("users", &user.id).await;
    let model = TokenModel { token: &token, secret: Some(&secret), render: ctx.render() };
    ctx.event_param("userId", user.id.clone());
    ctx.event_param("tokenId", token.id.clone());
    ctx.event_payload(&model);
    Ok(ctx.created(&model))
}

/// `POST /v1/users/:userId/jwts`.
pub async fn jwt(ctx: &mut Context) -> Result<Response> {
    let user_id = ctx.required_str("userId", &uid())?;
    let mut session_id =
        ctx.optional_str("sessionId", "recent()", &KeywordId("recent()"))?.unwrap_or_else(|| "recent()".to_owned());
    let duration = ctx.optional("duration", json!(900), &Range::new(0, 3600))?;
    let duration = Range::value(&duration).unwrap_or(900);
    // Request filter for clients older than 2.0.0.
    if session_id == "recent" && ctx.format.is_some_and(|f| f < Version(2, 0, 0)) {
        session_id = "recent()".to_owned();
    }
    let user = base::user_or_404(ctx, &user_id, Relations::SESSIONS).await?;
    let session =
        if session_id == "recent()" { user.sessions.last() } else { user.sessions.iter().find(|s| s.id == session_id) };
    if duration <= 0 {
        return Err(Error::internal("Invalid maxAge"));
    }
    let mut claims = Map::new();
    claims.insert("projectId".into(), Value::String(ctx.project.id.clone()));
    claims.insert("userId".into(), Value::String(user.id.clone()));
    claims.insert("sessionId".into(), Value::String(session.map(|s| s.id.clone()).unwrap_or_default()));
    let token = jwt::encode(&ctx.state.config.openssl_key_v1, claims, duration);
    Ok(ctx.created(&JwtModel { jwt: &token }))
}
