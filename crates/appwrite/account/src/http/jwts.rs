//! `POST /v1/account/jwts`: a short-lived JWT for the caller's session.

use serde_json::{Map, Value, json};
use utopia_http::Response;
use utopia_validators::Range;

use appwrite_core::auth::jwt;
use appwrite_core::platform::{Context, Labels, Mode, Route};
use appwrite_core::response::JwtModel;
use appwrite_core::{Error, ErrorType, Result};

use crate::{GROUPS_AUTH, route};

pub fn routes() -> Vec<Route> {
    let labels = Labels {
        abuse_limit: 120,
        abuse_time: 60,
        abuse_key: &["url:{url},userId:{userId}"],
        auth_type: "jwt",
        impersonation: "allow",
        ..Labels::NONE
    };
    vec![
        route!(
            POST,
            "/v1/account/jwts",
            "createJWT",
            "createJWT",
            &["account"],
            None,
            None,
            GROUPS_AUTH,
            labels,
            create
        ),
        route!(
            POST,
            "/v1/account/jwt",
            "createJWT",
            "createJWT",
            &["account"],
            None,
            None,
            GROUPS_AUTH,
            labels,
            create
        ),
    ]
}

/// `POST /v1/account/jwts`.
pub async fn create(ctx: &mut Context) -> Result<Response> {
    let duration = ctx.optional("duration", json!(900), &Range::new(0, 3600))?;
    let duration = Range::value(&duration).unwrap_or(900);
    if !ctx.request.header_or_empty("x-appwrite-jwt").is_empty() {
        return Err(Error::new(ErrorType::UserJwtCreationDenied));
    }
    let user = ctx.user.clone().ok_or_else(|| Error::new(ErrorType::UserSessionNotFound))?;
    let Some(session) = appwrite_core::auth::session_verify(&user.sessions, &ctx.store.secret) else {
        return Err(Error::new(ErrorType::UserSessionNotFound));
    };
    if duration <= 0 {
        return Err(Error::internal("Invalid maxAge"));
    }
    let mut claims = Map::new();
    // In admin mode the session is a console session, whatever project is managed.
    let project = if ctx.mode == Mode::Admin { "console".to_owned() } else { ctx.project.id.clone() };
    claims.insert("projectId".into(), Value::String(project));
    claims.insert("userId".into(), Value::String(user.id.clone()));
    claims.insert("sessionId".into(), Value::String(session.id.clone()));
    let token = jwt::encode(&ctx.state.config.openssl_key_v1, claims, duration);
    Ok(ctx.created(&JwtModel { jwt: &token }))
}
