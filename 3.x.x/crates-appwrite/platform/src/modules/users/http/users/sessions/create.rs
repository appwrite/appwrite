//! `POST /v1/users/:userId/sessions` (`createUserSession`). Rust port of
//! `Http/Users/Sessions/Create.php`.
//!
//! Simplifications versus PHP (documented, not silently dropped): no
//! `Detector` user-agent parsing and no `GeoRecord`/`Locale` country-name
//! lookup (`countryName` is left empty).

use appwrite_exception::Exception;
use serde_json::json;
use utopia_auth::Proof;
use utopia_platform::{Action, HttpMethod};

use crate::modules::users::base::{
    self, expire_at, inject, token_proof, SESSION_PROVIDER_SERVER, TOKEN_EXPIRATION_LOGIN_LONG,
};
use crate::state::{document_from_json, document_to_json};

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
        base::finish_blocking(ctx, 201, appwrite_response::MODEL_SESSION, |ctx| {
            let db_handle = base::get_db(&ctx)?;
            let mut db = db_handle.lock();
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
        })
        .await
    })
}
