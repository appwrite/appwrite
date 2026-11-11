//! The `user` resource (PHP: `app/init/resources/request.php`,
//! `$context->set('user'`; hooks.json `resource-user`): the session's user
//! (cookie, `X-Appwrite-Session`, `X-Fallback-Cookies`), else a JWT's, and an
//! account API key's user.

use serde_json::Value;

use crate::auth;
use crate::database::project;
use crate::database::{Project, documents};
use crate::error::{Error, ErrorType};
use crate::platform::context::{Context, Mode, Store};
use crate::{Result, datetime};

/// The `user` resource for the `api` group's init hooks.
pub(in crate::platform) async fn resolve(ctx: &mut Context) -> Result<()> {
    // A user resource that throws is not kept: PHP resolves it again for the
    // error hook and once more after it, each time sending its headers.
    if let Err(e) = load(ctx).await {
        session_from_request(ctx);
        session_from_request(ctx);
        return Err(e);
    }
    Ok(())
}

/// The `id` and `secret` of a session store (`Utopia\Auth\Store::decode()`),
/// empty when missing.
pub(in crate::platform) fn session_store(data: &str) -> (String, String) {
    let mut store = utopia_auth::Store::new();
    store.decode(data.as_bytes());
    let field = |key: &[u8]| match store.property(key) {
        Some(php_std::Value::Str(s)) => String::from_utf8_lossy(s).into_owned(),
        Some(php_std::Value::Int(i)) => i.to_string(),
        Some(php_std::Value::Float(f)) => php_std::number::to_string(*f),
        _ => String::new(),
    };
    (field(b"id"), field(b"secret"))
}

/// The session the request carries (cookie, `X-Appwrite-Session`, then
/// `X-Fallback-Cookies`) as `(id, secret)`, adding the `X-Debug-Fallback`
/// headers PHP's user resource adds.
pub(in crate::platform) fn session_from_request(ctx: &mut Context) -> (String, String) {
    let cookie_name =
        if ctx.mode == Mode::Admin { "a_session_console".to_owned() } else { format!("a_session_{}", ctx.project.id) };
    let mut store = ctx
        .request
        .cookie(&cookie_name)
        .or_else(|| ctx.request.cookie(&format!("{cookie_name}_legacy")))
        .map(session_store)
        .unwrap_or_default();
    if store.0.is_empty()
        && store.1.is_empty()
        && let Some(h) = ctx.request.header("x-appwrite-session")
    {
        store = session_store(h);
    }
    ctx.response_headers.push(("x-debug-fallback", "false".to_owned()));
    if store.0.is_empty() && store.1.is_empty() {
        ctx.response_headers.push(("x-debug-fallback", "true".to_owned()));
        if let Some(raw) = ctx.request.header("x-fallback-cookies")
            && let Ok(Value::Object(map)) = serde_json::from_str::<Value>(raw)
            && let Some(Value::String(v)) = map.get(&cookie_name)
        {
            store = session_store(v);
        }
    }
    store
}

async fn load(ctx: &mut Context) -> Result<()> {
    let state = ctx.state.clone();
    let store = session_from_request(ctx);
    let key =
        if ctx.mode == Mode::Admin { "a_session_console".to_owned() } else { format!("a_session_{}", ctx.project.id) };
    ctx.store = Store { key, id: store.0.clone(), secret: store.1.clone() };

    // An unknown project has no users (PHP: an empty `User`), but the
    // fallback headers above are already sent, and an account key still
    // names its user.
    if !ctx.project_found && ctx.mode != Mode::Admin {
        return account_key_user(ctx).await;
    }
    let users_db = if ctx.mode == Mode::Admin || ctx.project.is_console() { ctx.platform_db()? } else { ctx.db()? };

    if !store.0.is_empty()
        && let Some(user) = documents::load_user(&users_db, &store.0).await?
        && let Some(session) = auth::session_verify(&user.sessions, &store.1).cloned()
    {
        ctx.session = Some(session);
        ctx.user = Some(user);
    }

    // JWT
    let jwt = ctx.request.header_or_empty("x-appwrite-jwt").to_owned();
    if !jwt.is_empty() && ctx.project_found {
        if ctx.user.is_some() {
            return Err(Error::new(ErrorType::UserJwtAndCookieSet));
        }
        let claims = auth::jwt::decode(&state.config.openssl_key_v1, &jwt, 3600)
            .map_err(|e| Error::with_message(ErrorType::UserJwtInvalid, format!("Failed to verify JWT. {e}")))?;
        let claim = |k: &str| claims.get(k).and_then(Value::as_str).unwrap_or("").to_owned();
        let expected = if ctx.mode == Mode::Admin { Project::CONSOLE.to_owned() } else { ctx.project.id.clone() };
        let project_claim = claim("projectId");
        let session_id = claim("sessionId");
        let bound = if !project_claim.is_empty() { project_claim == expected } else { !session_id.is_empty() };
        let user_id = claim("userId");
        if bound
            && !user_id.is_empty()
            && let Some(user) = documents::load_user(&users_db, &user_id).await?
        {
            if !session_id.is_empty() && !auth::session_active(&user, &session_id) {
                // Session gone: authenticate nobody.
            } else {
                ctx.session = user.sessions.iter().find(|s| s.id == session_id).cloned();
                ctx.user = Some(user);
            }
        }
    }

    account_key_user(ctx).await?;

    super::impersonation::resolve(ctx, &users_db).await?;
    Ok(())
}

/// The account API key's user: with `X-Appwrite-User` and a key, the
/// console user of that ID when one of its keys is the key sent (an expired
/// one fails). Its keys are kept for `ApiKey::decode`.
///
/// PHP: the end of the `user` resource ("Account based on account API key").
async fn account_key_user(ctx: &mut Context) -> Result<()> {
    let key = ctx.request.header_or_empty("x-appwrite-key").to_owned();
    let user_id = ctx.request.header_or_empty("x-appwrite-user").to_owned();
    if user_id.is_empty() || key.is_empty() {
        return Ok(());
    }
    if ctx.user.is_some() {
        return Err(Error::new(ErrorType::UserApiKeyAndSessionSet));
    }
    let platform = ctx.platform_db()?;
    let authorization = platform.authorization();
    let Some(user) = authorization.skip(documents::load_user(&platform, &user_id)).await? else {
        return Ok(());
    };
    let keys = project::owned_keys(&platform, "users", &user.sequence.to_string()).await?;
    let Some(stored) = keys.iter().find(|k| k.secret == key) else {
        return Ok(());
    };
    if stored.expire.is_some_and(|e| e < datetime::now()) {
        return Err(Error::new(ErrorType::AccountKeyExpired));
    }
    ctx.user = Some(user);
    ctx.account_keys = keys;
    Ok(())
}
