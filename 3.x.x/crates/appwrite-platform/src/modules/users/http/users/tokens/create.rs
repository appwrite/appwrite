//! `POST /v1/users/:userId/tokens` (`createUserToken`). Rust port of
//! `Http/Users/Tokens/Create.php`.

use std::sync::Arc;

use appwrite_exception::Exception;
use serde_json::{json, Value};
use utopia_auth::Proof;
use utopia_platform::{Action, HttpMethod};
use utopia_validators::Range;

use crate::modules::users::base::{
    self, expire_at, inject, TOKEN_EXPIRATION_GENERIC, TOKEN_EXPIRATION_LOGIN_LONG,
    TOKEN_TYPE_GENERIC,
};
use crate::state::{document_from_json, document_to_json};

/// `POST /v1/users/:userId/tokens` (`createUserToken`).
#[must_use]
pub fn create() -> Action {
    inject(
        Action::new()
            .set_http_method(HttpMethod::Post)
            .set_http_path("/v1/users/:userId/tokens")
            .desc("Create token")
            .groups(["api", "users"])
            .label("scope", "users.write")
            .label("audits.event", "tokens.create")
            .label("audits.resource", "user/{request.userId}")
            .param(
                "userId",
                json!(""),
                utopia_validators::Text::new(36),
                "User ID.",
                false,
            )
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
        base::finish_blocking(ctx, 201, appwrite_response::MODEL_TOKEN, |ctx| {
            let db_handle = base::get_db(&ctx)?;
            let mut db = db_handle.lock();
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
        })
        .await
    })
}
