//! Request lifecycle: init hooks, action, shutdown hooks and error handling.
//!
//! The order of checks mirrors PHP exactly because it decides which error a
//! client sees (e.g. a scope error wins over a parameter error). The hooks
//! themselves live in [`super::hooks`]; this module only runs them.

use bytes::Bytes;
use http::Method;
use utopia_http::{Compression, Request, Response};

use super::Platform;
use super::context::Context;
use super::hooks::init::{
    abuse, admin_console, auth, auth_methods, certificates, console_forbidden, context, mfa_fresh, recent_session,
    request, security, session_active,
};
use super::hooks::resources::{self, hostnames};
use super::hooks::{error, preflight, shutdown};
use crate::{Result, network};

pub(super) async fn run(platform: &Platform, request: Request) -> Response {
    let state = platform.state.clone();
    if request.method() == Method::OPTIONS {
        return preflight::preflight(&state, &request);
    }
    // `Http::match()`: HEAD runs as GET.
    let method = if request.method() == Method::HEAD { Method::GET.as_str() } else { request.method() };
    let (route, params) = match platform.router.find(method, &request.route_path()) {
        Some(m) => (m.route.clone(), m.params),
        None => (platform.wildcard.clone(), Vec::new()),
    };
    let mut ctx = Context::new(state.clone(), request, route, params);
    let result = async {
        // Every init hook is bound to a group (`api`, `web`, ...): a route
        // outside them (the OAuth2 callbacks) runs its action alone.
        if ctx.route.has_group("api") {
            authenticate(&mut ctx).await?;
            state.hooks.init(&mut ctx).await?;
        } else if ctx.route.groups.iter().any(|g| console_forbidden::CONSOLE_FORBIDDEN.contains(g)) {
            resources::project::resolve_alone(&mut ctx).await;
        }
        // Hooks run group by group, in the route's order: these groups'
        // come after every `api` hook.
        console_forbidden::console_forbidden(&ctx)?;
        // A hook sent the response (`$response->isSent()`): no action
        // (`finalize` sends it).
        if ctx.sent.is_some() {
            return Ok(Response::new());
        }
        let action = ctx.route.action;
        action(&mut ctx).await
    }
    .await;
    // The error hook still resolves the project (its name titles the error page).
    if result.is_err() && !ctx.route.has_group("api") {
        resources::project::resolve_alone(&mut ctx).await;
    }
    finalize(ctx, result)
}

/// Init hooks of the `api` group (`general.php` I1–I4 and `shared/api.php`
/// I6), in PHP's order: each resource is resolved where a hook first
/// injects it.
pub(super) async fn authenticate(ctx: &mut Context) -> Result<()> {
    let state = ctx.state.clone();
    let platform_db = ctx.platform_db()?;

    let header_project = ctx.request.header_or_empty("x-appwrite-project").to_owned();
    let supplied_project = resources::project::resolve(ctx, &platform_db, &header_project).await?;
    // -- rule (the custom domain the request comes from) -------------------
    ctx.rule_domain = hostnames::rule_domain(&state, &platform_db, &ctx.project, &ctx.request).await?;
    resources::mode::resolve(ctx, &supplied_project);
    admin_console::admin_console(ctx)?;
    request::formats(ctx);
    resources::user::resolve(ctx).await?;
    let key_header = ctx.request.header_or_empty("x-appwrite-key").to_owned();
    let team_sequence = resources::key::resolve(ctx, &platform_db, &header_project, &key_header).await?;
    request::filters(ctx).await?;
    request::force_https(ctx)?;
    security::security(ctx, &key_header)?;
    certificates::certify(ctx).await?;
    auth::auth(ctx, &platform_db, &header_project, &team_sequence).await?;

    // The hooks routes opt into, in PHP's order.
    abuse::abuse(ctx).await?;
    context::context(ctx).await?;
    session_active::session_active(ctx)?;
    mfa_fresh::mfa_fresh(ctx)?;
    recent_session::recent_session(ctx)?;
    auth_methods::auth_methods(ctx).await?;
    Ok(())
}

/// Builds the final response (success or error) and schedules shutdown work.
pub(super) fn finalize(mut ctx: Context, result: Result<Response>) -> Response {
    let state = ctx.state.clone();
    let allowed =
        hostnames::allowed_hostnames(&state, &ctx.project, ctx.project_found, &ctx.request, ctx.rule_domain.as_deref());
    let cors = network::cors_headers(ctx.request.header_or_empty("origin"), &allowed);
    let https = ctx
        .request
        .header("x-forwarded-proto")
        .map(|p| p.split(',').next().unwrap_or("").trim().eq_ignore_ascii_case("https"))
        .unwrap_or(false);

    // A response a hook sent stays as it was sent (with the CORS and
    // security headers when they came before it); an error after it only
    // skips the shutdown hooks.
    if let Some(mut sent) = ctx.sent.take() {
        ctx.failed |= result.is_err();
        if ctx.sent_secured {
            for (k, v) in &cors {
                sent.add(k, v);
            }
            sent.set("server", "Appwrite");
            sent.set("x-content-type-options", "nosniff");
            if https {
                sent.set("strict-transport-security", "max-age=10886400");
            }
        } else {
            sent.set("server", "swoole-http-server");
        }
        return send(ctx, sent, true);
    }

    let mut response = match result {
        Ok(response) => response,
        Err(error) => error::error(&ctx, error),
    };
    let is_error = response.status().as_u16() >= 400;
    // CORS headers come from the `api` group's init hook, and the error hook.
    let api = ctx.route.has_group("api");
    if api || is_error {
        for (k, v) in &cors {
            response.add(k, v);
        }
    }
    if !ctx.secured {
        // Swoole's own header: PHP failed before `general.php` set it.
        response.set("server", "swoole-http-server");
    } else {
        response.set("server", "Appwrite");
        response.set("x-content-type-options", "nosniff");
        if https {
            response.set("strict-transport-security", "max-age=10886400");
        }
    }
    for (k, v) in &ctx.response_headers {
        response.add(k, v);
    }
    if is_error {
        response.set("cache-control", "no-cache, no-store, must-revalidate");
        response.set("expires", "0");
        response.set("pragma", "no-cache");
    }
    send(ctx, response, api)
}

/// `Response::send()`: compression, `X-Debug-Speed` (from the request
/// start), then the body; and the shutdown hooks of a success.
fn send(ctx: Context, mut response: Response, api: bool) -> Response {
    let state = ctx.state.clone();
    let is_error = response.status().as_u16() >= 400;
    let body: Bytes = response.staged().cloned().unwrap_or_default();
    response.set_started(ctx.started);
    let accept = ctx.request.header_line_or("accept-encoding", "");
    response.set_compression(
        &accept,
        &Compression {
            enabled: state.config.compression,
            min_size: state.config.compression_min_size,
            supported: Vec::new(),
        },
    );
    response.flush();

    if !is_error && api && !ctx.failed {
        shutdown::schedule(ctx, response.status(), response.content_type().to_owned(), body, response.size());
    }
    response
}
