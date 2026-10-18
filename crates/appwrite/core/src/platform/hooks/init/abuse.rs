//! Abuse limits (PHP: `app/controllers/shared/api.php`, the `api` group's
//! init hook reading `abuse-limit`; hooks.json `abuse`).

use serde_json::Value;
use utopia_abuse::TimeLimit;
use utopia_abuse::time_limit::Redis;

use crate::auth::roles;
use crate::error::{Error, ErrorType};
use crate::platform::context::Context;
use crate::{Result, datetime};

/// The `api` group's abuse hook: counts the request against each of the
/// route's `abuse-key`s, reports the closest limit in `X-RateLimit-*`, and
/// refuses it when a limit is exceeded. Keys and privileged users, and every
/// caller when `_APP_OPTIONS_ABUSE=disabled`, are counted without being
/// limited (`peek`).
pub(in crate::platform) async fn abuse(ctx: &mut Context) -> Result<()> {
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
