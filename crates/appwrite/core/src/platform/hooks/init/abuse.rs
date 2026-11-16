//! Abuse limits (PHP: `app/controllers/shared/api.php`, the `api` group's
//! init hook reading the `abuse` label; hooks.json `abuse`).

use serde_json::Value;
use utopia_abuse::TimeLimit;
use utopia_abuse::time_limit::Redis;

use crate::auth::roles;
use crate::error::{Error, ErrorType};
use crate::platform::Limit;
use crate::platform::context::Context;
use crate::{Result, datetime};

/// `APP_LIMIT_UPLOAD_CHUNK_SIZE`.
const UPLOAD_CHUNK_SIZE: i64 = 5 * 1024 * 1024;

/// The `api` group's abuse hook: counts the request against each of the
/// route's limits, reports the closest one in `X-RateLimit-*`, and refuses
/// it when a limit is exceeded. A key or a privileged caller is trusted:
/// the limits that only pace legitimate work are read for its headers
/// (`peek`) and never counted, while a limit declared for everyone still
/// counts. With `_APP_OPTIONS_ABUSE=disabled` nothing counts.
///
/// A failure limit's hit is reserved here, and given back by the shutdown
/// hook when the request succeeds; a refused request gives back the hits
/// it reserved, since being refused is not a failed attempt.
pub(in crate::platform) async fn abuse(ctx: &mut Context) -> Result<()> {
    let limits: &'static [Limit] = ctx.route.labels.abuse;
    let trusted = roles::is_key(&ctx.roles) || roles::is_privileged(&ctx.roles);
    let multiplier = if ctx.state.config.abuse_increased_projects.contains(&ctx.project.id) { 100 } else { 1 };
    ctx.abuse_params = if limits.is_empty() { Vec::new() } else { values(ctx) };
    let now = datetime::now().and_utc().timestamp();
    let mut closest: Option<i64> = None;
    let mut reserved: Vec<&Limit> = Vec::new();
    for limit in limits {
        let check = ctx.state.config.abuse && limit.counts(trusted);
        let key = limit.key(&ctx.abuse_params);
        let verdict =
            match TimeLimit::new(key, limit.limit * multiplier, limit.seconds, Redis::new(ctx.state.abuse.clone())) {
                Ok(time_limit) => {
                    if check {
                        time_limit.check_at(now).await
                    } else {
                        time_limit.peek_at(now).await
                    }
                }
                Err(e) => Err(e),
            };
        // A limiter outage fails open.
        let verdict = match verdict {
            Ok(verdict) => verdict,
            Err(e) => {
                tracing::warn!(error = %e, "abuse check failed");
                continue;
            }
        };
        if verdict.limit != 0 && closest.is_none_or(|c| verdict.remaining < c) {
            closest = Some(verdict.remaining);
            ctx.response_headers.push(("x-ratelimit-limit", verdict.limit.to_string()));
            ctx.response_headers.push(("x-ratelimit-remaining", verdict.remaining.to_string()));
            ctx.response_headers.push(("x-ratelimit-reset", verdict.reset.to_string()));
        }
        if check && verdict.limited {
            for hit in reserved {
                release(ctx, hit, now).await;
            }
            return Err(Error::new(ErrorType::GeneralRateLimitExceeded));
        }
        if check && limit.failures {
            reserved.push(limit);
        }
    }
    Ok(())
}

/// Gives back one hit of `limit` (with its unmultiplied limit, as PHP does)
/// in the window `now` falls in; an outage is only logged.
pub(in crate::platform) async fn release_limit(
    abuse: &utopia_cache::redis::aio::ConnectionManager,
    limit: &Limit,
    values: &[(String, String)],
    now: i64,
) {
    let result = match TimeLimit::new(limit.key(values), limit.limit, limit.seconds, Redis::new(abuse.clone())) {
        Ok(time_limit) => time_limit.release_at(now).await,
        Err(e) => Err(e),
    };
    if let Err(e) = result {
        tracing::warn!(error = %e, "abuse release failed");
    }
}

async fn release(ctx: &Context, limit: &Limit, now: i64) {
    release_limit(&ctx.state.abuse, limit, &ctx.abuse_params, now).await;
}

/// `Limit::values()`: every part a limit can be kept per, for this request:
/// the project, the user, the client and the request's and the route's
/// non-empty parameters (`param.<name>`).
fn values(ctx: &Context) -> Vec<(String, String)> {
    // `getContentRangeChunk(APP_LIMIT_UPLOAD_CHUNK_SIZE)`: the chunk the
    // range starts in, keyed on the server's chunk size (a size bound that
    // saturates is no valid range).
    let chunk = match ctx.request.content_range() {
        Some(r) if r.size.is_none_or(|size| size < i64::MAX) => r.start / UPLOAD_CHUNK_SIZE,
        _ => 0,
    };
    let mut values = vec![
        ("projectId".to_owned(), ctx.project.id.clone()),
        ("userId".to_owned(), ctx.user.as_ref().map(|u| u.id.clone()).unwrap_or_default()),
        ("userAgent".to_owned(), ctx.request.header_or_empty("user-agent").to_owned()),
        ("ip".to_owned(), ctx.ip.clone()),
        ("url".to_owned(), ctx.route.path.to_owned()),
        ("method".to_owned(), ctx.request.method().to_string()),
        ("chunkId".to_owned(), chunk.to_string()),
    ];
    // `array_merge($request->getParams(), $pathParams)`: path params are not
    // part of the request input, and the query must not override them.
    let request = ctx.request.params().iter().filter(|(name, _)| !ctx.path_params.iter().any(|(k, _)| k == *name));
    let path = ctx.path_params.iter().map(|(k, v)| (k, v));
    for (name, value) in request.chain(path) {
        if php_std::value::empty(value) {
            continue;
        }
        let text = match value {
            Value::String(s) => s.clone(),
            Value::Bool(_) => "1".to_owned(),
            Value::Number(n) => n.to_string(),
            other => crate::json::to_string(other),
        };
        // Sign in matches emails, and directories match usernames,
        // case-insensitively, so the limit must too.
        let text = match name.as_str() {
            "email" => text.to_ascii_lowercase(),
            "username" => text.trim_matches(TRIM).to_ascii_lowercase(),
            _ => text,
        };
        values.push((format!("param.{name}"), text));
    }
    values
}

/// The characters PHP's `trim()` strips.
const TRIM: &[char] = &[' ', '\t', '\n', '\r', '\0', '\x0B'];
