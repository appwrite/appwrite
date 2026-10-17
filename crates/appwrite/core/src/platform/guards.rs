//! The hooks a route opts into with its labels and groups (PHP:
//! `app/controllers/shared/api.php`, `app/controllers/shared/api/auth.php`):
//! abuse limits, an active session, MFA freshness, a recent session and the
//! project's authentication methods, in the order PHP runs them after the
//! `api` group's authentication.

use serde_json::Value;
use utopia_abuse::TimeLimit;
use utopia_abuse::time_limit::{Redis, Store};

use super::context::Context;
use crate::auth::roles;
use crate::error::{Error, ErrorType};
use crate::{Result, datetime};

/// `MFA_RECENT_DURATION`: an MFA challenge stays fresh this long.
const MFA_RECENT: i64 = 1800;
/// `SESSION_RECENT_DURATION`: a sign-in (or MFA challenge) stays recent this long.
const SESSION_RECENT: i64 = 600;

/// The `api` group's abuse hook: counts the request against each of the
/// route's `abuse-key`s, reports the closest limit in `X-RateLimit-*`, and
/// refuses it when a limit is exceeded. Keys and privileged users, and every
/// caller when `_APP_OPTIONS_ABUSE=disabled`, are counted without being
/// limited (`peek`).
pub(super) async fn abuse(ctx: &mut Context) -> Result<()> {
    let labels = ctx.route.labels;
    let enforce = ctx.state.config.abuse && !roles::is_key(&ctx.roles) && !roles::is_privileged(&ctx.roles);
    let mut limit = labels.abuse_limit;
    if ctx.state.config.abuse_increased_projects.contains(&ctx.project.id) {
        limit *= 100;
    }
    ctx.abuse_params = abuse_params(ctx);
    let now = datetime::now().and_utc().timestamp();
    let mut closest: Option<i64> = None;
    for key in labels.abuse_key {
        let limited = match TimeLimit::new(*key, limit, labels.abuse_time, Redis::new(ctx.state.abuse.clone())) {
            Ok(time_limit) => {
                let time_limit = time_limit.with_params(ctx.abuse_params.clone());
                let verdict = if enforce { time_limit.check_at(now).await } else { time_limit.peek_at(now).await };
                match verdict {
                    Ok(verdict) => {
                        if verdict.limit != 0 && closest.is_none_or(|c| verdict.remaining < c) {
                            closest = Some(verdict.remaining);
                            ctx.response_headers.push(("x-ratelimit-limit", verdict.limit.to_string()));
                            ctx.response_headers.push(("x-ratelimit-remaining", verdict.remaining.to_string()));
                            ctx.response_headers.push(("x-ratelimit-reset", verdict.reset.to_string()));
                        }
                        enforce && verdict.limited
                    }
                    Err(e) => {
                        tracing::warn!(error = %e, "abuse check failed");
                        continue;
                    }
                }
            }
            Err(e) => {
                tracing::warn!(error = %e, "abuse check failed");
                continue;
            }
        };
        if limited {
            return Err(Error::new(ErrorType::GeneralRateLimitExceeded));
        }
    }
    Ok(())
}

/// The abuse key parameters: the project, the user, the client and the
/// request's non-empty parameters (`{param-<name>}`).
fn abuse_params(ctx: &Context) -> Vec<(String, String)> {
    let chunk = match ctx.request.content_range() {
        Some(r) => {
            let end = r.end.unwrap_or(0);
            let span = end + 1 - r.start;
            if span == 0 { 0 } else { r.start / span }
        }
        None => 0,
    };
    let mut params = vec![
        ("{projectId}".to_owned(), ctx.project.id.clone()),
        ("{userId}".to_owned(), ctx.user.as_ref().map(|u| u.id.clone()).unwrap_or_default()),
        ("{userAgent}".to_owned(), ctx.request.header_or_empty("user-agent").to_owned()),
        ("{ip}".to_owned(), ctx.ip.clone()),
        ("{url}".to_owned(), format!("{}{}", ctx.hostname, ctx.route.path)),
        ("{method}".to_owned(), ctx.request.method().to_string()),
        ("{chunkId}".to_owned(), chunk.to_string()),
    ];
    for (name, value) in ctx.request.params() {
        if php_std::value::empty(value) {
            continue;
        }
        let text = match value {
            Value::String(s) => s.clone(),
            Value::Bool(_) => "1".to_owned(),
            Value::Number(n) => n.to_string(),
            other => crate::json::to_string(other),
        };
        params.push((format!("{{param-{name}}}"), text));
    }
    params
}

/// The `api` group's abuse reset (after the action): a response whose status
/// the route lists in `abuse-reset` clears its limits.
pub(super) async fn abuse_reset(state: &super::State, labels: super::Labels, status: u16, params: &[(String, String)]) {
    if !state.config.abuse {
        return;
    }
    let now = datetime::now().and_utc().timestamp();
    reset_limits(labels, status, params, now, Redis::new(state.abuse.clone())).await;
}

/// Sets the count of each of the route's `abuse-key`s (with the request's
/// parameters) to zero in the window `now` falls in, when `status` is one of
/// its `abuse-reset` codes.
async fn reset_limits<S: Store + Clone>(
    labels: super::Labels,
    status: u16,
    params: &[(String, String)],
    now: i64,
    store: S,
) {
    if !labels.abuse_reset.contains(&status) {
        return;
    }
    for key in labels.abuse_key {
        let Ok(time_limit) = TimeLimit::new(*key, labels.abuse_limit, labels.abuse_time, store.clone()) else {
            continue;
        };
        if let Err(e) = time_limit.with_params(params.to_vec()).reset_at(now).await {
            tracing::warn!(error = %e, "abuse reset failed");
        }
    }
}

/// The `session` group: a session is not created for a caller who has one,
/// unless the route is labelled `session.allowActive`.
pub(super) fn session_active(ctx: &Context) -> Result<()> {
    if !ctx.route.has_group("session") || ctx.route.labels.session_allow_active {
        return Ok(());
    }
    if ctx.user.is_some() {
        return Err(Error::new(ErrorType::UserSessionAlreadyExists));
    }
    Ok(())
}

/// The `mfaProtected` group: an MFA challenge completed within
/// `MFA_RECENT_DURATION`.
pub(super) fn mfa_fresh(ctx: &Context) -> Result<()> {
    if !ctx.route.has_group("mfaProtected") {
        return Ok(());
    }
    let now = datetime::now();
    let fresh = ctx
        .session
        .as_ref()
        .and_then(|s| s.mfa_updated_at)
        .is_some_and(|at| datetime::add_seconds(at, MFA_RECENT) >= now);
    if !fresh {
        return Err(Error::new(ErrorType::UserChallengeRequired));
    }
    Ok(())
}

/// The `recentSession` group: a session created, or MFA-verified, within
/// `SESSION_RECENT_DURATION`. Guests are left to the scope check.
pub(super) fn recent_session(ctx: &Context) -> Result<()> {
    if !ctx.route.has_group("recentSession") {
        return Ok(());
    }
    let Some(session) = ctx.session.as_ref() else { return Ok(()) };
    let recent = datetime::add_seconds(datetime::now(), -SESSION_RECENT);
    let signed_in = session.created_at.is_some_and(|at| at >= recent);
    let verified = session.mfa_updated_at.is_some_and(|at| at >= recent);
    if !signed_in && !verified {
        return Err(Error::new(ErrorType::UserReauthenticationRequired));
    }
    Ok(())
}

/// The `auth` group: the console refuses denied countries, and the project's
/// disabled authentication methods are refused to all but keys and
/// privileged users.
pub(super) async fn auth_methods(ctx: &Context) -> Result<()> {
    if !ctx.route.has_group("auth") {
        return Ok(());
    }
    let denylist = &ctx.state.config.console_countries_denylist;
    if !denylist.is_empty() && ctx.project.is_console() {
        let country = ctx.state.geo.country_code(&ctx.ip).await.to_uppercase();
        if denylist.contains(&country) {
            return Err(Error::new(ErrorType::GeneralRegionAccessDenied));
        }
    }
    if roles::is_key(&ctx.roles) || roles::is_privileged(&ctx.roles) {
        return Ok(());
    }
    // `app/config/auth.php`: the method, its `auths` key, the default and
    // the message when it is off.
    let (key, default, name) = match ctx.route.labels.auth_type {
        "email-password" => ("emailPassword", true, "Email / Password"),
        "magic-url" => ("usersAuthMagicURL", true, "Magic URL"),
        "anonymous" => ("anonymous", true, "Anonymous"),
        "phone" => ("phone", true, "Phone"),
        "invites" => ("invites", true, "Invites"),
        "jwt" => ("JWT", true, "JWT"),
        "email-otp" => ("emailOtp", true, "Email OTP"),
        "passkey" => ("passkey", false, "Passkey"),
        _ => {
            return Err(Error::with_message(ErrorType::UserAuthMethodUnsupported, "Unsupported authentication route"));
        }
    };
    // PHP refuses `=== false`, and a passkey `!== true`.
    let value = ctx.project.auths.get(key);
    let refused = if default { value == Some(&Value::Bool(false)) } else { value != Some(&Value::Bool(true)) };
    if refused {
        return Err(Error::with_message(
            ErrorType::UserAuthMethodUnsupported,
            format!("{name} authentication is disabled for this project"),
        ));
    }
    Ok(())
}

#[cfg(test)]
mod abuse_reset_tests {
    use std::sync::Arc;

    use utopia_abuse::TimeLimit;
    use utopia_abuse::time_limit::{Database, Memory};

    use super::reset_limits;
    use crate::platform::Labels;

    /// `POST /v1/account/sessions/email`'s labels.
    const LABELS: Labels =
        Labels { abuse_limit: 10, abuse_key: &["url:{url},email:{param-email}"], abuse_reset: &[201], ..Labels::NONE };

    /// A minute into a window.
    const NOW: i64 = 1_790_000_060;

    fn params(email: &str) -> Vec<(String, String)> {
        vec![
            ("{url}".to_owned(), "localhost/v1/account/sessions/email".to_owned()),
            ("{ip}".to_owned(), "127.0.0.1".to_owned()),
            ("{param-email}".to_owned(), email.to_owned()),
        ]
    }

    fn limit(store: &Arc<Memory>, email: &str) -> TimeLimit<Database<Arc<Memory>>> {
        TimeLimit::new(LABELS.abuse_key[0], LABELS.abuse_limit, LABELS.abuse_time, Database::new(store.clone()))
            .unwrap()
            .with_params(params(email))
    }

    async fn store() -> Arc<Memory> {
        let store = Arc::new(Memory::new());
        Database::new(store.clone()).setup().await.unwrap();
        store
    }

    async fn hit(store: &Arc<Memory>, email: &str, times: usize) {
        for _ in 0..times {
            limit(store, email).check_at(NOW).await.unwrap();
        }
    }

    async fn used(store: &Arc<Memory>, email: &str) -> i64 {
        use utopia_abuse::Adapter as _;
        use utopia_abuse::time_limit::Store as _;
        let limit = limit(store, email);
        limit.store().count(limit.key(), limit.window(NOW)).await.unwrap()
    }

    /// A status the route lists resets its key (with the request's
    /// parameters) in the current window; another status, or another
    /// request's parameters, leave the counts alone.
    #[utopia_async::test]
    async fn listed_statuses_reset_the_request_keys() {
        let store = store().await;
        hit(&store, "a@example.com", 3).await;
        hit(&store, "b@example.com", 2).await;

        reset_limits(LABELS, 401, &params("a@example.com"), NOW, Database::new(store.clone())).await;
        assert_eq!(used(&store, "a@example.com").await, 3);

        reset_limits(LABELS, 201, &params("a@example.com"), NOW, Database::new(store.clone())).await;
        assert_eq!(used(&store, "a@example.com").await, 0);
        assert_eq!(used(&store, "b@example.com").await, 2);
    }

    /// A route without `abuse-reset` codes never resets.
    #[utopia_async::test]
    async fn routes_without_reset_codes_keep_their_counts() {
        let store = store().await;
        hit(&store, "a@example.com", 4).await;
        let labels = Labels { abuse_reset: &[], ..LABELS };
        reset_limits(labels, 201, &params("a@example.com"), NOW, Database::new(store.clone())).await;
        assert_eq!(used(&store, "a@example.com").await, 4);
    }
}
