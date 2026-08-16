//! `POST /v1/users/:userId/jwts` (`createUserJWT`). Rust port of
//! `Http/Users/JWTs/Create.php`.

use appwrite_exception::Exception;
use serde_json::{json, Value};
use utopia_database::Query;
use utopia_platform::{Action, HttpMethod};
use utopia_validators::{Range, Text};

use crate::modules::users::base::{self, inject};

/// `POST /v1/users/:userId/jwts` (`createUserJWT`).
#[must_use]
pub fn create() -> Action {
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
            let mut db = db_handle.lock();
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

            // PHP reads `$user->getAttribute('sessions')` from the cached
            // relationship. Avoid loading every session: for `recent` take the
            // newest by `$id` with limit 1; for a concrete id, get by primary key.
            let session = if session_id == "recent" {
                db.find_one(
                    "sessions",
                    &[
                        Query::equal("userInternalId", vec![base::sequence_str(&user).into()]),
                        Query::order_desc("$id"),
                    ],
                )
                .map_err(base::db_error)?
            } else {
                let found = db
                    .get_document("sessions", &session_id, &[], false)
                    .map_err(base::db_error)?;
                if found.is_empty()
                    || found.get_attribute("userId").as_str() != Some(user_id.as_str())
                {
                    utopia_database::Document::default()
                } else {
                    found
                }
            };
            let session_id_claim = if session.is_empty() {
                String::new()
            } else {
                session.get_id()
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
                session_id: session_id_claim,
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
