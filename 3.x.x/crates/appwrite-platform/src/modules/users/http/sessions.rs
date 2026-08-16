//! User session endpoints. Rust port of
//! `Http/Users/Sessions/{Create,XList,Delete}.php` and
//! `Http/Users/Sessions/Bulk/Delete.php`.
//!
//! Simplifications versus PHP (documented, not silently dropped): no
//! `Detector` user-agent parsing (OS/client/device fields are left unset)
//! and no `GeoRecord`/`Locale` country-name lookup (`countryName` is left
//! empty) -- neither crate is wired into this milestone's DI container.

use appwrite_exception::Exception;
use serde_json::{json, Value};
use std::sync::Arc;
use utopia_auth::Proof;
use utopia_database::Query;
use utopia_platform::{Action, HttpMethod};
use utopia_validators::{Boolean, Range, Text};

use crate::modules::users::base::{self, inject};
use crate::state::{document_from_json, document_to_json};

/// PHP `SESSION_PROVIDER_SERVER` (`app/init/constants.php`).
const SESSION_PROVIDER_SERVER: &str = "server";
/// PHP `TOKEN_EXPIRATION_LOGIN_LONG` (`app/init/constants.php`): 1 year, in
/// seconds.
const TOKEN_EXPIRATION_LOGIN_LONG: i64 = 31_536_000;
/// PHP `TOKEN_EXPIRATION_GENERIC`: 15 minutes, in seconds.
const TOKEN_EXPIRATION_GENERIC: i64 = 900;
/// PHP `TOKEN_TYPE_GENERIC` (`app/init/constants.php`).
const TOKEN_TYPE_GENERIC: i64 = 8;

fn expire_at(seconds: i64) -> String {
    let expire = chrono::Utc::now() + chrono::Duration::seconds(seconds);
    expire.format("%Y-%m-%dT%H:%M:%S%.3f+00:00").to_string()
}

fn token_proof() -> Result<utopia_auth::Token, Exception> {
    let mut token = utopia_auth::Token::new(32).map_err(base::hash_error)?;
    token.set_hasher(Arc::new(utopia_auth::Sha::new()));
    Ok(token)
}

/// `POST /v1/users/:userId/sessions` (`createUserSession`).
#[must_use]
pub fn create() -> Action {
    inject(
        Action::new()
            .set_http_method(HttpMethod::Post)
            .set_http_path("/v1/users/:userId/sessions")
            .desc("Create session")
            .groups(["api", "users"])
            .label("scope", ["users.write", "sessions.write"])
            .label("audits.event", "session.create")
            .label("audits.resource", "user/{request.userId}")
            .param(
                "userId",
                json!(""),
                appwrite_database::CustomId::default(),
                "User ID. Choose a custom ID or generate a random ID with `ID.unique()`.",
                false,
            ),
        &["request", "response", "dbForProject"],
    )
    .http_action(|ctx| async move {
        let result = (|| -> Result<Value, Exception> {
            let db_handle = base::get_db(&ctx)?;
            let mut db = db_handle.lock().unwrap_or_else(|e| e.into_inner());
            let user_id = base::param_str(&ctx, "userId")?;
            let user =
                base::require_document(&mut db, "users", &user_id, Exception::USER_NOT_FOUND)?;

            let proof = token_proof()?;
            let secret = proof.generate().map_err(base::hash_error)?;
            let hashed = proof.hash(&secret).map_err(base::hash_error)?;
            let expire = expire_at(TOKEN_EXPIRATION_LOGIN_LONG);
            let user_agent = ctx.request().header_line("user-agent");
            let ip = ctx.request().ip();

            let session_json = json!({
                "$id": appwrite_database::resolve_id(appwrite_database::UNIQUE_SENTINEL),
                "$permissions": base::user_permissions(&user_id),
                "userId": user_id,
                "userInternalId": base::sequence_of(&user),
                "provider": SESSION_PROVIDER_SERVER,
                "secret": hashed,
                "userAgent": if user_agent.is_empty() { "UNKNOWN".to_string() } else { user_agent },
                "factors": ["server"],
                "ip": ip,
                "countryCode": "",
                "expire": expire,
            });
            let created = db
                .create_document("sessions", document_from_json(session_json))
                .map_err(base::db_error)?;
            base::purge_user(&mut db, &user_id);

            // PHP returns the Store-encoded `{id, secret}` pair, not the raw
            // token: `x-appwrite-session` / the session cookie carry that
            // encoding, and the DB keeps only the one-way hash.
            let mut store = utopia_auth::Store::new();
            store
                .set_property("id", user_id.clone())
                .set_property("secret", secret);
            let encoded = store.encode().map_err(base::hash_error)?;

            let mut session_out = document_to_json(&created);
            session_out["secret"] = json!(encoded);
            Ok(session_out)
        })();
        base::finish(&ctx, 201, appwrite_response::MODEL_SESSION, result)
    })
}

/// `GET /v1/users/:userId/sessions` (`listUserSessions`).
#[must_use]
pub fn list() -> Action {
    inject(
        Action::new()
            .set_http_method(HttpMethod::Get)
            .set_http_path("/v1/users/:userId/sessions")
            .desc("List user sessions")
            .groups(["api", "users"])
            .label("scope", ["users.read", "sessions.read"])
            .param("userId", json!(""), Text::new(36), "User ID.", false)
            .param(
                "total",
                json!(true),
                Boolean::new().loose(true),
                "Include total count.",
                true,
            ),
        &["response", "dbForProject"],
    )
    .http_action(|ctx| async move {
        let result = (|| -> Result<Value, Exception> {
            let db_handle = base::get_db(&ctx)?;
            let mut db = db_handle.lock().unwrap_or_else(|e| e.into_inner());
            let user_id = base::param_str(&ctx, "userId")?;
            base::require_document(&mut db, "users", &user_id, Exception::USER_NOT_FOUND)?;
            let include_total = ctx
                .param_value("total")
                .and_then(Value::as_bool)
                .unwrap_or(true);

            let queries = [
                Query::equal("userId", vec![user_id.clone().into()]),
                Query::limit(100),
            ];
            let sessions = db
                .find("sessions", &queries, "read")
                .map_err(base::db_error)?;
            let total = if include_total {
                sessions.len() as i64
            } else {
                0
            };
            let items: Vec<Value> = sessions
                .iter()
                .map(|session| {
                    let mut out = document_to_json(session);
                    out["current"] = json!(false);
                    out
                })
                .collect();
            Ok(json!({ "sessions": items, "total": total }))
        })();
        base::finish(&ctx, 200, appwrite_response::MODEL_SESSION_LIST, result)
    })
}

/// `DELETE /v1/users/:userId/sessions/:sessionId` (`deleteUserSession`).
#[must_use]
pub fn delete() -> Action {
    inject(
        Action::new()
            .set_http_method(HttpMethod::Delete)
            .set_http_path("/v1/users/:userId/sessions/:sessionId")
            .desc("Delete user session")
            .groups(["api", "users"])
            .label("scope", ["users.write", "sessions.write"])
            .label("audits.event", "session.delete")
            .label("audits.resource", "user/{request.userId}")
            .param("userId", json!(""), Text::new(36), "User ID.", false)
            .param("sessionId", json!(""), Text::new(36), "Session ID.", false),
        &["response", "dbForProject"],
    )
    .http_action(|ctx| async move {
        let result = (|| -> Result<(), Exception> {
            let db_handle = base::get_db(&ctx)?;
            let mut db = db_handle.lock().unwrap_or_else(|e| e.into_inner());
            let user_id = base::param_str(&ctx, "userId")?;
            let session_id = base::param_str(&ctx, "sessionId")?;
            base::require_document(&mut db, "users", &user_id, Exception::USER_NOT_FOUND)?;

            let session = db
                .get_document("sessions", &session_id, &[], false)
                .map_err(base::db_error)?;
            if session.is_empty()
                || session.get_attribute("userId").as_str() != Some(user_id.as_str())
            {
                return Err(Exception::new(Exception::USER_SESSION_NOT_FOUND));
            }
            db.delete_document("sessions", &session_id)
                .map_err(base::db_error)?;
            base::purge_user(&mut db, &user_id);
            Ok(())
        })();
        base::finish_no_content(&ctx, result)
    })
}

/// `DELETE /v1/users/:userId/sessions` (`deleteUserSessions`).
#[must_use]
pub fn delete_all() -> Action {
    inject(
        Action::new()
            .set_http_method(HttpMethod::Delete)
            .set_http_path("/v1/users/:userId/sessions")
            .desc("Delete user sessions")
            .groups(["api", "users"])
            .label("scope", ["users.write", "sessions.write"])
            .label("audits.event", "session.delete")
            .label("audits.resource", "user/{request.userId}")
            .param("userId", json!(""), Text::new(36), "User ID.", false),
        &["response", "dbForProject"],
    )
    .http_action(|ctx| async move {
        let result = (|| -> Result<(), Exception> {
            let db_handle = base::get_db(&ctx)?;
            let mut db = db_handle.lock().unwrap_or_else(|e| e.into_inner());
            let user_id = base::param_str(&ctx, "userId")?;
            base::require_document(&mut db, "users", &user_id, Exception::USER_NOT_FOUND)?;
            base::delete_user_sessions(&mut db, &user_id)
        })();
        base::finish_no_content(&ctx, result)
    })
}

/// `POST /v1/users/:userId/tokens` (`createUserToken`). Rust port of
/// `Http/Users/Tokens/Create.php`.
#[must_use]
pub fn create_token() -> Action {
    inject(
        Action::new()
            .set_http_method(HttpMethod::Post)
            .set_http_path("/v1/users/:userId/tokens")
            .desc("Create token")
            .groups(["api", "users"])
            .label("scope", "users.write")
            .label("audits.event", "tokens.create")
            .label("audits.resource", "user/{request.userId}")
            .param("userId", json!(""), Text::new(36), "User ID.", false)
            .param(
                "length",
                json!(6),
                Range::integer(4, 128),
                "Token length in characters. The default length is 6 characters",
                true,
            )
            .param(
                "expire",
                json!(TOKEN_EXPIRATION_GENERIC),
                Range::integer(60, TOKEN_EXPIRATION_LOGIN_LONG),
                "Token expiration period in seconds. The default expiration is 15 minutes.",
                true,
            ),
        &["request", "response", "dbForProject"],
    )
    .http_action(|ctx| async move {
        let result = (|| -> Result<Value, Exception> {
            let db_handle = base::get_db(&ctx)?;
            let mut db = db_handle.lock().unwrap_or_else(|e| e.into_inner());
            let user_id = base::param_str(&ctx, "userId")?;
            let length = ctx
                .param_value("length")
                .and_then(Value::as_i64)
                .unwrap_or(6)
                .max(1) as usize;
            let expire_seconds = ctx
                .param_value("expire")
                .and_then(Value::as_i64)
                .unwrap_or(TOKEN_EXPIRATION_GENERIC);
            let user =
                base::require_document(&mut db, "users", &user_id, Exception::USER_NOT_FOUND)?;

            let mut proof = utopia_auth::Token::new(length).map_err(base::hash_error)?;
            proof.set_hasher(Arc::new(utopia_auth::Sha::new()));
            let secret = proof.generate().map_err(base::hash_error)?;
            let hashed = proof.hash(&secret).map_err(base::hash_error)?;
            let expire = expire_at(expire_seconds);
            let user_agent = ctx.request().header_line("user-agent");
            let ip = ctx.request().ip();

            let token_json = json!({
                "$id": appwrite_database::resolve_id(appwrite_database::UNIQUE_SENTINEL),
                "userId": user_id,
                "userInternalId": base::sequence_of(&user),
                "type": TOKEN_TYPE_GENERIC,
                "secret": hashed,
                "expire": expire,
                "userAgent": if user_agent.is_empty() { "UNKNOWN".to_string() } else { user_agent },
                "ip": ip,
            });
            let created = db
                .create_document("tokens", document_from_json(token_json))
                .map_err(base::db_error)?;
            base::purge_user(&mut db, &user_id);
            let mut token_out = document_to_json(&created);
            token_out["secret"] = json!(secret);
            Ok(token_out)
        })();
        base::finish(&ctx, 201, appwrite_response::MODEL_TOKEN, result)
    })
}

/// `POST /v1/users/:userId/jwts` (`createUserJWT`). Rust port of
/// `Http/Users/JWTs/Create.php`.
#[must_use]
pub fn create_jwt() -> Action {
    inject(
        Action::new()
            .set_http_method(HttpMethod::Post)
            .set_http_path("/v1/users/:userId/jwts")
            .desc("Create user JWT")
            .groups(["api", "users"])
            .label("scope", "users.write")
            .param("userId", json!(""), Text::new(36), "User ID.", false)
            .param(
                "sessionId",
                json!("recent"),
                Text::new(36),
                "Session ID. Use the string 'recent' to use the most recent session.",
                true,
            )
            .param(
                "duration",
                json!(900),
                Range::integer(0, 3600),
                "Time in seconds before JWT expires.",
                true,
            ),
        &["response", "dbForProject"],
    )
    .http_action(|ctx| async move {
        let result = (|| -> Result<Value, Exception> {
            let db_handle = base::get_db(&ctx)?;
            let mut db = db_handle.lock().unwrap_or_else(|e| e.into_inner());
            let user_id = base::param_str(&ctx, "userId")?;
            let session_id = ctx
                .param_value("sessionId")
                .and_then(Value::as_str)
                .unwrap_or("recent")
                .to_string();
            let duration = ctx
                .param_value("duration")
                .and_then(Value::as_i64)
                .unwrap_or(900);
            let user =
                base::require_document(&mut db, "users", &user_id, Exception::USER_NOT_FOUND)?;

            // PHP reads `$user->getAttribute('sessions')`, the relationship
            // keyed on `userInternalId`, so match on the same column rather
            // than `userId`.
            let sessions = db
                .find(
                    "sessions",
                    &[
                        Query::equal("userInternalId", vec![base::sequence_str(&user).into()]),
                        Query::limit(1000),
                    ],
                    "read",
                )
                .map_err(base::db_error)?;
            let session = if session_id == "recent" {
                sessions.last().cloned()
            } else {
                sessions.into_iter().find(|s| s.get_id() == session_id)
            };

            #[derive(serde::Serialize)]
            struct Claims {
                #[serde(rename = "userId")]
                user_id: String,
                #[serde(rename = "sessionId")]
                session_id: String,
                exp: i64,
            }
            let secret = std::env::var("_APP_OPENSSL_KEY_V1")
                .unwrap_or_else(|_| "appwrite-dev-key".to_string());
            let exp = chrono::Utc::now().timestamp() + duration;
            let claims = Claims {
                user_id,
                session_id: session.map(|s| s.get_id()).unwrap_or_default(),
                exp,
            };
            let jwt = jsonwebtoken::encode(
                &jsonwebtoken::Header::new(jsonwebtoken::Algorithm::HS256),
                &claims,
                &jsonwebtoken::EncodingKey::from_secret(secret.as_bytes()),
            )
            .map_err(|err| {
                Exception::with_message(Exception::GENERAL_SERVER_ERROR, err.to_string())
            })?;

            Ok(json!({ "jwt": jwt }))
        })();
        base::finish(&ctx, 201, appwrite_response::MODEL_JWT, result)
    })
}
