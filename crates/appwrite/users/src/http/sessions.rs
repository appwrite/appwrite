//! Sessions, tokens and JWTs created on behalf of a user.

use serde_json::{Map, Value, json};
use utopia_database::Document;
use utopia_database::Value as DbValue;
use utopia_http::Response;
use utopia_validators::Range;

use appwrite_core::auth::jwt;
use appwrite_core::database::documents::{self, Session, Token, text};
use appwrite_core::datetime;
use appwrite_core::platform::Context;
use appwrite_core::response::{JwtModel, SessionListModel, SessionModel, TokenModel};
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
    let user = base::user_or_404(ctx, &user_id, &[]).await?;

    let secret = utopia_auth::proofs::Token::default().token();
    let user_agent = ctx.user_agent("UNKNOWN");
    let detected = appwrite_core::detector::detect(&user_agent);
    let duration = ctx.project.auth_int("duration", 31_536_000);
    let expire = datetime::add_seconds(datetime::now(), duration);
    let country = ctx.state.geo.country_code(&ctx.ip).await.to_lowercase();
    let mut session = Document::default();
    session.put("$id", DbValue::from(appwrite_core::database::unique_id().as_str()));
    session.put("userId", DbValue::from(user.id.as_str()));
    session.put("userInternalId", documents::internal_id(user.sequence));
    session.put("provider", DbValue::from("server"));
    session.put("secret", DbValue::from(base::token_hash(&secret)?.as_str()));
    session.put("userAgent", DbValue::from(user_agent.as_str()));
    session.put("factors", documents::strings(&["server".to_owned()]));
    session.put("ip", DbValue::from(ctx.ip.as_str()));
    session.put("countryCode", DbValue::from(country.as_str()));
    session.put("expire", datetime::value(&expire));
    session.put("osCode", DbValue::from(detected.os_code.as_str()));
    session.put("osName", DbValue::from(detected.os_name.as_str()));
    session.put("osVersion", DbValue::from(detected.os_version.as_str()));
    session.put("clientType", DbValue::from(detected.client_type.as_str()));
    session.put("clientCode", DbValue::from(detected.client_code.as_str()));
    session.put("clientName", DbValue::from(detected.client_name.as_str()));
    session.put("clientVersion", DbValue::from(detected.client_version.as_str()));
    session.put("clientEngine", DbValue::from(detected.client_engine.as_str()));
    session.put("clientEngineVersion", DbValue::from(detected.client_engine_version.as_str()));
    session.put("deviceName", text(detected.device_name.as_deref()));
    session.put("deviceBrand", text(detected.device_brand.as_deref()));
    session.put("deviceModel", text(detected.device_model.as_deref()));
    session.set("$permissions", base::owner_permissions(&user.id))?;
    let db = ctx.db()?;
    let session = Session::from(&db.create_document("sessions", session).await?);
    db.purge_cached_document("users", &user.id).await?;

    let encoded = base::session_store(&user.id, &secret)?;
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
    let user = base::user_or_404(ctx, &user_id, &[base::SESSIONS]).await?;
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
    let user = base::user_or_404(ctx, &user_id, &[]).await?;
    let db = ctx.db()?;
    let session = match documents::get::<Session>(&db, "sessions", &session_id).await? {
        Some(s) if s.user_id == user.id => s,
        _ => return Err(Error::new(ErrorType::UserSessionNotFound)),
    };
    db.delete_document("sessions", &session.id).await?;
    db.purge_cached_document("users", &user.id).await?;
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
    let user = base::user_or_404(ctx, &user_id, &[base::SESSIONS, base::TARGETS]).await?;
    let db = ctx.db()?;
    for session in &user.sessions {
        db.delete_document("sessions", &session.id).await?;
    }
    db.purge_cached_document("users", &user.id).await?;
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
    let user = base::user_or_404(ctx, &user_id, &[]).await?;

    let secret = utopia_auth::proofs::Token::new(length as i64).map_err(|e| Error::internal(e.to_string()))?.token();
    let expire_at = datetime::add_seconds(datetime::now(), expire);
    let mut token = Document::default();
    token.put("$id", DbValue::from(appwrite_core::database::unique_id().as_str()));
    token.put("userId", DbValue::from(user.id.as_str()));
    token.put("userInternalId", documents::internal_id(user.sequence));
    // `TOKEN_TYPE_GENERIC`.
    token.put("type", DbValue::Int(8));
    token.put("secret", DbValue::from(base::token_hash(&secret)?.as_str()));
    token.put("expire", datetime::value(&expire_at));
    token.put("userAgent", DbValue::from(ctx.user_agent("UNKNOWN").as_str()));
    token.put("ip", DbValue::from(ctx.ip.as_str()));
    let db = ctx.db()?;
    let token = Token::from(&db.create_document("tokens", token).await?);
    db.purge_cached_document("users", &user.id).await?;
    let model = TokenModel { token: &token, secret: Some(&secret), phrase: "", reveal: false, render: ctx.render() };
    ctx.event_param("userId", user.id.clone());
    ctx.event_param("tokenId", token.id.clone());
    ctx.event_payload(&model);
    Ok(ctx.created(&model))
}

/// `POST /v1/users/:userId/jwts`.
pub async fn jwt(ctx: &mut Context) -> Result<Response> {
    let user_id = ctx.required_str("userId", &uid())?;
    // Clients older than 2.0.0 send the bare `recent`: the V27 request
    // filter rewrites it (`appwrite_core::request`).
    let session_id =
        ctx.optional_str("sessionId", "recent()", &KeywordId("recent()"))?.unwrap_or_else(|| "recent()".to_owned());
    let duration = ctx.optional("duration", json!(900), &Range::new(0, 3600))?;
    let duration = Range::value(&duration).unwrap_or(900);
    let user = base::user_or_404(ctx, &user_id, &[base::SESSIONS]).await?;
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
