//! Request lifecycle: init hooks, action, shutdown hooks and error handling.
//!
//! The order of checks mirrors PHP exactly because it decides which error a
//! client sees (e.g. a scope error wins over a parameter error).

use std::sync::Arc;

use bytes::Bytes;
use http::{Method, StatusCode};
use serde_json::Value;
use utopia_database::Param;
use utopia_database::datetime;
use utopia_database::sql::Builder;
use utopia_http::{Compression, Request, Response};

use super::context::{Context, Mode};
use super::{Platform, Route, shutdown};
use crate::auth::{self, ApiKey, KeyType, roles};
use crate::database::Project;
use crate::database::documents::{self, Relations};
use crate::error::{Error, ErrorType};
use crate::response::{ErrorModel, Version};
use crate::{Result, VERSION, network};

/// Fallback route for unmatched paths (`general.php` wildcard).
pub(super) fn wildcard_route() -> Route {
    fn not_found(_ctx: &mut Context) -> super::BoxFuture<'_, Result<Response>> {
        Box::pin(async { Err(Error::new(ErrorType::GeneralRouteNotFound)) })
    }
    Route {
        method: Method::GET,
        path: "/*",
        name: "wildcard",
        namespace: "",
        sdk_method: "",
        scopes: &["global"],
        event: None,
        audit: None,
        groups: &["api"],
        action: not_found,
    }
}

pub(super) async fn run(platform: &Platform, request: Request) -> Response {
    let state = platform.state.clone();
    if request.method() == Method::OPTIONS {
        return preflight(&state, &request);
    }
    // `Http::match()`: HEAD runs as GET.
    let method = if request.method() == Method::HEAD { Method::GET.as_str() } else { request.method() };
    let (route, params) = match platform.router.find(method, request.path()) {
        Some(m) => (m.route.clone(), m.params),
        None => (platform.wildcard.clone(), Vec::new()),
    };
    let mut ctx = Context::new(state.clone(), request, route, params);
    let result = async {
        authenticate(&mut ctx).await?;
        state.hooks.init(&mut ctx).await?;
        let action = ctx.route.action;
        action(&mut ctx).await
    }
    .await;
    finalize(ctx, result)
}

fn allowed_hostnames(
    ctx_state: &super::State,
    project: &Project,
    project_found: bool,
    request: &Request,
) -> Vec<String> {
    let mut allowed = ctx_state.config.platform_hostnames();
    if let Some(host) = network::url_host(&ctx_state.config.console_url) {
        allowed.push(host);
    }
    if project_found && !project.is_console() && !project.id.is_empty() {
        allowed.extend(network::platform_hostnames(&project.platforms));
    }
    if request.method() == Method::OPTIONS {
        let origin = request.header("origin").filter(|o| !o.is_empty()).or_else(|| request.header("referer"));
        if let Some(host) = origin.and_then(network::url_host) {
            allowed.push(host);
        }
    }
    let mut unique: Vec<String> = Vec::with_capacity(allowed.len());
    for h in allowed {
        if !unique.contains(&h) {
            unique.push(h);
        }
    }
    unique
}

fn preflight(state: &Arc<super::State>, request: &Request) -> Response {
    let mut response = Response::no_content_response();
    let console = state.projects.console();
    let allowed = allowed_hostnames(state, &console, true, request);
    for (k, v) in network::cors_headers(request.header_or_empty("origin"), &allowed) {
        response.add(k, &v);
    }
    response.set("server", "Appwrite");
    response
}

fn string_param(request: &Request, key: &str) -> Option<String> {
    match request.params().get(key) {
        Some(Value::String(s)) => Some(s.clone()),
        _ => None,
    }
}

/// Init hooks (`general.php` I1–I4 and `shared/api.php` I6).
pub(super) async fn authenticate(ctx: &mut Context) -> Result<()> {
    let state = ctx.state.clone();
    let platform_db = ctx.platform_db();

    // -- project -----------------------------------------------------------
    let header_project = ctx.request.header_or_empty("x-appwrite-project").to_owned();
    let supplied_project = match ctx.request.params().get("project") {
        Some(Value::String(s)) => s.clone(),
        _ => header_project.clone(),
    };
    let mut project_id = supplied_project.clone();
    if project_id.is_empty() {
        project_id = ctx.request.query_value("project").unwrap_or("").to_owned();
    }
    if project_id.is_empty() || project_id == Project::CONSOLE {
        ctx.project = state.projects.console();
    } else {
        match state.projects.get(&platform_db, &project_id).await? {
            Some(p) => ctx.project = p,
            None => {
                ctx.project = Arc::new(Project::empty());
                ctx.project_found = false;
            }
        }
    }

    // -- mode --------------------------------------------------------------
    let mode = string_param(&ctx.request, "mode")
        .unwrap_or_else(|| ctx.request.header("x-appwrite-mode").unwrap_or("default").to_owned());
    ctx.mode = if mode == "admin" { Mode::Admin } else { Mode::Default };
    if !supplied_project.is_empty() && ctx.project.id != supplied_project {
        ctx.mode = Mode::Admin;
    }
    if ctx.mode == Mode::Admin && ctx.project_found && ctx.project.is_console() {
        return Err(Error::with_message(ErrorType::GeneralBadRequest, "Admin mode is not allowed for console project"));
    }

    // -- response format, locale -----------------------------------------
    let format = ctx
        .request
        .header("x-appwrite-response-format")
        .map(str::to_owned)
        .or_else(|| state.config.response_format.clone());
    if let Some(f) = format.as_deref().and_then(Version::parse) {
        let current = Version::parse(VERSION).expect("valid version");
        if f > current {
            ctx.response_headers.push((
                "x-appwrite-warning",
                format!(
                    "The current SDK is built for Appwrite {}. However, the current Appwrite server version is {VERSION}. Please downgrade your SDK to match the Appwrite version: https://appwrite.io/docs/sdks",
                    format.as_deref().unwrap_or("")
                ),
            ));
        }
        if f < current {
            ctx.format = Some(f);
        }
    }
    let locale = string_param(&ctx.request, "locale")
        .unwrap_or_else(|| ctx.request.header_or_empty("x-appwrite-locale").to_owned());
    if !locale.is_empty() && state.translations.has(&locale) {
        ctx.locale = locale;
    }

    // -- user (session cookie / header / fallback cookies, then JWT) --------
    if ctx.project_found {
        resolve_user(ctx).await?;
    }

    // -- API key -------------------------------------------------------------
    let key_header = ctx.request.header_or_empty("x-appwrite-key").to_owned();
    if !key_header.is_empty() {
        let key = ApiKey::decode(&ctx.project, &key_header, &state.config.openssl_key_v1);
        if !key.project_id.is_empty() && (header_project.is_empty() || header_project != key.project_id) {
            return Err(Error::new(ErrorType::ProjectIdMissing));
        }
        ctx.key = Some(key);
    }

    // -- CORS / origin (I4) ----------------------------------------------------
    let origin = ctx.request.header_or_empty("origin").to_owned();
    if !origin.is_empty() && key_header.is_empty() {
        let allowed = allowed_hostnames(&state, &ctx.project, ctx.project_found, &ctx.request);
        let mut schemes = state.config.console_schemas.clone();
        if ctx.project_found && !ctx.project.is_console() {
            schemes.push("exp".to_owned());
            schemes.push(format!("appwrite-callback-{}", ctx.project.id));
            schemes.extend(network::platform_schemes(&ctx.project.platforms));
        }
        if let Err(description) = network::validate_origin(&origin, &allowed, &schemes) {
            return Err(Error::with_message(ErrorType::GeneralUnknownOrigin, description));
        }
    }

    // -- auth (shared/api.php) -----------------------------------------------
    if !ctx.project_found {
        return Err(Error::new(ErrorType::ProjectNotFound));
    }

    let mut role = if ctx.user.is_some() { roles::USERS } else { roles::GUESTS }.to_owned();
    let mut scopes: Vec<String> = roles::scopes(&role).into_iter().map(str::to_owned).collect();
    let mut authorization = true;
    let mut extra_roles: Vec<String> = Vec::new();
    // Email shown in scope errors ("User" when unknown).
    let mut actor_email: Option<String> =
        ctx.user.as_ref().map(|u| u.email.clone().unwrap_or_else(|| "User".to_owned()));
    let mut key_actor = false;

    if let Some(key) = ctx.key.clone() {
        if key.expired {
            return Err(Error::new(ErrorType::ProjectKeyExpired));
        }
        role = key.role.clone();
        scopes = key.scopes.clone();
        if key.role == roles::KEYS {
            if matches!(key.kind, KeyType::Standard | KeyType::Ephemeral | KeyType::Dynamic)
                && key.project_id == ctx.project.id
            {
                authorization = false;
            }
            actor_email = Some(format!("app.{}@service.{}", ctx.project.id, ctx.hostname));
            key_actor = true;
        }
        if key.kind == KeyType::Standard {
            let Some(stored) = key.stored.clone() else {
                return Err(Error::new(ErrorType::UserUnauthorized));
            };
            track_key_usage(ctx, stored);
        }
    } else if ctx.user.is_some() && ctx.mode == Mode::Admin && !ctx.project.is_console() {
        let team_id = load_team_id(&platform_db, &ctx.project.team_internal_id).await?;
        let source = ctx.roles_user().cloned().unwrap_or_default();
        let admin_roles = source
            .memberships
            .iter()
            .find(|m| m.confirm == Some(true) && Some(&m.team_id) == team_id.as_ref())
            .map(|m| m.roles.clone())
            .unwrap_or_default();
        if admin_roles.is_empty() {
            return Err(Error::new(ErrorType::UserUnauthorized));
        }
        scopes = vec!["teams.read".to_owned(), "projects.read".to_owned()];
        let project_prefix = format!("project-{}", ctx.project.id);
        for admin_role in admin_roles {
            let team_wide = !admin_role.starts_with("project-");
            if team_wide || admin_role.starts_with(&project_prefix) {
                let r =
                    if team_wide { admin_role.clone() } else { admin_role.rsplit('-').next().unwrap_or("").to_owned() };
                scopes.extend(roles::scopes(&r).into_iter().map(str::to_owned));
                extra_roles.push(r.clone());
                role = r;
            }
        }
        authorization = false;
    }

    let mut unique_scopes: Vec<String> = Vec::with_capacity(scopes.len());
    for s in scopes {
        if !unique_scopes.contains(&s) {
            unique_scopes.push(s);
        }
    }
    let mut scopes = unique_scopes;

    // Impersonators may discover users (list and get).
    let discovery = ctx.request.method() == Method::GET && matches!(ctx.route.path, "/v1/users" | "/v1/users/:userId");
    if discovery
        && !key_actor
        && let Some(user) = &ctx.user
        && (user.impersonator == Some(true) || ctx.target_user.is_some())
        && !scopes.iter().any(|s| s == "users.read")
    {
        scopes.push("users.read".to_owned());
    }

    // Authorization roles.
    let mut all_roles = vec!["any".to_owned()];
    all_roles.extend(extra_roles);
    if !all_roles.contains(&role) {
        all_roles.push(role.clone());
    }
    if !key_actor {
        match ctx.roles_user() {
            Some(u) => all_roles.extend(u.roles()),
            None if ctx.key.is_none() => all_roles.push(roles::GUESTS.to_owned()),
            None => {}
        }
    }
    ctx.roles = all_roles;
    ctx.role = role.clone();
    ctx.scopes = scopes;
    ctx.authorization = authorization;

    // Admin requests must be able to read the project.
    if ctx.user.is_some() && !ctx.project.is_console() && ctx.mode == Mode::Admin && ctx.key.is_none() {
        let read_roles = ctx.project.read_roles();
        if !read_roles.iter().any(|r| ctx.roles.contains(r)) {
            return Err(Error::new(ErrorType::ProjectNotFound));
        }
    }

    track_access(ctx);

    // Service and API toggles.
    let privileged_or_key = roles::is_privileged(&ctx.roles) || roles::is_key(&ctx.roles);
    if !ctx.route.namespace.is_empty() && ctx.project.service_disabled(ctx.route.namespace) && !privileged_or_key {
        return Err(Error::new(ErrorType::GeneralServiceDisabled));
    }
    if ctx.project.api_disabled("rest") && !privileged_or_key {
        return Err(Error::new(ErrorType::GeneralApiDisabled));
    }

    // Scopes.
    if !ctx.route.scopes.iter().any(|s| ctx.scopes.iter().any(|x| x == s)) {
        let label = roles::label(&role).unwrap_or("").to_lowercase();
        let allowed = crate::json::to_string(&ctx.route.scopes);
        let email = actor_email.unwrap_or_else(|| "User".to_owned());
        return Err(Error::with_message(
            ErrorType::GeneralUnauthorizedScope,
            format!("{email} (role: {label}) missing scopes ({allowed})"),
        ));
    }

    if let Some(u) = ctx.roles_user().filter(|_| !key_actor) {
        if u.status == Some(false) {
            return Err(Error::new(ErrorType::UserBlocked));
        }
        if u.reset == Some(true) {
            return Err(Error::new(ErrorType::UserPasswordResetRequired));
        }
    }

    // MFA.
    if let Some(user) = ctx.user.as_ref().filter(|_| !key_actor) {
        let has_totp =
            user.authenticators.iter().any(|a| a.kind.as_deref() == Some("totp") && a.verified == Some(true));
        let minimum = if user.mfa == Some(true) && (user.verified() || has_totp) { 2 } else { 1 };
        if !ctx.route.has_group("mfa") {
            let impersonating = ctx.target_user.is_some();
            let factors = ctx.session.as_ref().map(|s| s.factors.len());
            if (impersonating && factors.is_none()) || factors.is_some_and(|f| f < minimum) {
                return Err(Error::new(ErrorType::UserMoreFactorsRequired));
            }
        }
    }
    Ok(())
}

async fn load_team_id(platform_db: &utopia_database::Database, team_internal_id: &str) -> Result<Option<String>> {
    let Ok(sequence) = team_internal_id.parse::<i64>() else {
        return Ok(None);
    };
    let mut b = Builder::new();
    let p = b.bind(Param::Int(sequence));
    let sql = format!("SELECT \"_uid\" FROM {} WHERE \"_id\" = {p}", platform_db.table("teams"));
    Ok(platform_db.query_opt(&sql, &b).await?.and_then(|r| r.try_get::<_, String>(0).ok()))
}

const USER_RELATIONS: Relations = Relations { targets: false, sessions: true, memberships: true, authenticators: true };

async fn resolve_user(ctx: &mut Context) -> Result<()> {
    let state = ctx.state.clone();
    let cookie_name =
        if ctx.mode == Mode::Admin { "a_session_console".to_owned() } else { format!("a_session_{}", ctx.project.id) };
    let mut store = ctx
        .request
        .cookie(&cookie_name)
        .or_else(|| ctx.request.cookie(&format!("{cookie_name}_legacy")))
        .map(utopia_auth::store::decode)
        .unwrap_or_default();
    if store.0.is_empty()
        && store.1.is_empty()
        && let Some(h) = ctx.request.header("x-appwrite-session")
    {
        store = utopia_auth::store::decode(h);
    }
    ctx.response_headers.push(("x-debug-fallback", "false".to_owned()));
    if store.0.is_empty() && store.1.is_empty() {
        ctx.response_headers.push(("x-debug-fallback", "true".to_owned()));
        if let Some(raw) = ctx.request.header("x-fallback-cookies")
            && let Ok(Value::Object(map)) = serde_json::from_str::<Value>(raw)
            && let Some(Value::String(v)) = map.get(&cookie_name)
        {
            store = utopia_auth::store::decode(v);
        }
    }

    let users_db = if ctx.mode == Mode::Admin || ctx.project.is_console() { ctx.platform_db() } else { ctx.db()? };

    if !store.0.is_empty()
        && let Some(user) = documents::load_user(&users_db, &store.0, USER_RELATIONS).await?
        && let Some(session) = auth::session_verify(&user.sessions, &store.1).cloned()
    {
        ctx.session = Some(session);
        ctx.user = Some(user);
    }

    // JWT
    let jwt = ctx.request.header_or_empty("x-appwrite-jwt").to_owned();
    if !jwt.is_empty() {
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
            && let Some(user) = documents::load_user(&users_db, &user_id, USER_RELATIONS).await?
        {
            if !session_id.is_empty() && !auth::session_active(&user, &session_id) {
                // Session gone: authenticate nobody.
            } else {
                ctx.session = user.sessions.iter().find(|s| s.id == session_id).cloned();
                ctx.user = Some(user);
            }
        }
    }

    // Impersonation.
    if let Some(user) = &ctx.user
        && user.impersonator == Some(true)
    {
        let pick = |header: &str, a: &str, b: &str| -> String {
            if let Some(h) = ctx.request.header(header) {
                return h.to_owned();
            }
            let pa = string_param(&ctx.request, a).unwrap_or_default();
            if !pa.is_empty() && pa != "0" {
                return pa;
            }
            string_param(&ctx.request, b).unwrap_or_default()
        };
        let by_id = pick("x-appwrite-impersonate-user-id", "impersonateuserid", "impersonateUserId");
        let by_email = pick("x-appwrite-impersonate-user-email", "impersonateemail", "impersonateEmail");
        let by_phone = pick("x-appwrite-impersonate-user-phone", "impersonatephone", "impersonatePhone");
        let target_id = if !by_id.is_empty() {
            Some(by_id)
        } else if !by_email.is_empty() {
            find_user_id(&users_db, "email", &by_email.to_lowercase()).await?
        } else if !by_phone.is_empty() {
            find_user_id(&users_db, "phone", &by_phone).await?
        } else {
            None
        };
        if let Some(target_id) = target_id
            && let Some(target) = documents::load_user(&users_db, &target_id, USER_RELATIONS).await?
        {
            ctx.target_user = Some(target);
        }
    }
    Ok(())
}

async fn find_user_id(db: &utopia_database::Database, column: &str, value: &str) -> Result<Option<String>> {
    let mut b = Builder::new();
    let p = b.bind(Param::text(value));
    let tenant = db.tenant_condition(&mut b, "main");
    let sql = format!(
        "SELECT \"_uid\" FROM {} AS \"main\" WHERE \"main\".\"{column}\" = {p}{tenant} ORDER BY \"_id\" ASC LIMIT 1",
        db.table("users")
    );
    Ok(db.query_opt(&sql, &b).await?.and_then(|r| r.try_get::<_, String>(0).ok()))
}

/// Updates `accessedAt` / `sdks` of a standard key (once per 24h or new SDK).
fn track_key_usage(ctx: &Context, key: crate::database::project::Key) {
    const SERVERS: [&str; 13] = [
        "node.js",
        "php",
        "python",
        "ruby",
        "go",
        ".net",
        "dart",
        "kotlin",
        "swift",
        "rust",
        "graphql",
        "rest",
        "terraform",
    ];
    let now = datetime::now();
    let stale = key.accessed_at.map(|a| a < datetime::add_seconds(now, -86_400)).unwrap_or(true);
    let sdk = ctx.request.header_or_empty("x-sdk-name").to_lowercase();
    let new_sdk = !sdk.is_empty() && SERVERS.contains(&sdk.as_str()) && !key.sdks.contains(&sdk);
    if !stale && !new_sdk {
        return;
    }
    let state = ctx.state.clone();
    let project = ctx.project.clone();
    tokio::spawn(async move {
        let db = state.databases.platform();
        let lock_key = format!("lock:platform:{}:keys:{}", project.sequence, key.id);
        let state2 = state.clone();
        let _ = state
            .lock
            .try_with_key(&lock_key, async move {
                let mut columns = vec![("accessedAt", Param::Timestamp(now))];
                if new_sdk {
                    let mut sdks = key.sdks.clone();
                    sdks.push(sdk);
                    columns.push(("sdks", Param::string_list(&sdks)));
                }
                match db.update("keys", &key.id, columns, "\"_uid\"").await {
                    Ok(_) => {
                        db.purge_cached_document("projects", &project.id).await;
                        state2.projects.forget(&project.id);
                    }
                    Err(e) => tracing::warn!(error = %e, "key usage update failed"),
                }
            })
            .await;
    });
}

/// Updates project and user `accessedAt` (once per 24h).
fn track_access(ctx: &Context) {
    let now = datetime::now();
    let threshold = datetime::add_seconds(now, -86_400);
    let state = ctx.state.clone();
    if !ctx.project.is_console() && ctx.project.accessed_at.map(|a| a < threshold).unwrap_or(true) {
        let project = ctx.project.clone();
        let state = state.clone();
        tokio::spawn(async move {
            let db = state.databases.platform();
            let key = format!("lock:platform:projects:{}:accessedAt", project.id);
            let state2 = state.clone();
            let _ = state
                .lock
                .try_with_key(&key, async move {
                    if db
                        .update("projects", &project.id, vec![("accessedAt", Param::Timestamp(now))], "\"_uid\"")
                        .await
                        .is_ok()
                    {
                        state2.projects.forget(&project.id);
                    }
                })
                .await;
        });
    }
    let Some(user) = &ctx.user else { return };
    if ctx.target_user.is_some() || user.accessed_at.map(|a| a >= threshold).unwrap_or(false) {
        return;
    }
    let user_id = user.id.clone();
    let user_seq = user.sequence;
    let (db, lock_key) = if !ctx.project.is_console() && ctx.mode != Mode::Admin {
        match state.databases.project(&ctx.project) {
            Ok(db) => (db, format!("lock:project:{}:users:{}:accessedAt", ctx.project.sequence, user_seq)),
            Err(_) => return,
        }
    } else {
        (state.databases.platform(), format!("lock:platform:{user_seq}:users:{user_id}:accessedAt"))
    };
    tokio::spawn(async move {
        let _ = state
            .lock
            .try_with_key(&lock_key, async move {
                if db.update("users", &user_id, vec![("accessedAt", Param::Timestamp(now))], "\"_uid\"").await.is_ok() {
                }
            })
            .await;
    });
}

/// Builds the final response (success or error) and schedules shutdown work.
pub(super) fn finalize(ctx: Context, result: Result<Response>) -> Response {
    let state = ctx.state.clone();
    let allowed = allowed_hostnames(&state, &ctx.project, ctx.project_found, &ctx.request);
    let cors = network::cors_headers(ctx.request.header_or_empty("origin"), &allowed);
    let https = ctx
        .request
        .header("x-forwarded-proto")
        .map(|p| p.split(',').next().unwrap_or("").trim().eq_ignore_ascii_case("https"))
        .unwrap_or(false);

    let mut response = match result {
        Ok(response) => response,
        Err(error) => error_response(&state, &error),
    };
    let is_error = response.status().as_u16() >= 400;
    for (k, v) in &cors {
        response.add(k, v);
    }
    response.set("server", "Appwrite");
    response.set("x-content-type-options", "nosniff");
    if https {
        response.set("strict-transport-security", "max-age=10886400");
    }
    for (k, v) in &ctx.response_headers {
        response.add(k, v);
    }
    if is_error {
        response.set("cache-control", "no-cache, no-store, must-revalidate");
        response.set("expires", "0");
        response.set("pragma", "no-cache");
    }
    // `Response::send()`: compression, `X-Debug-Speed` (from the request start), then the body.
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

    if !is_error {
        shutdown::schedule(ctx, response.status(), body, response.size());
    }
    response
}

/// Error JSON (`general.php` error hook).
pub(crate) fn error_response(state: &super::State, error: &Error) -> Response {
    let status = error.status();
    if status >= 500 {
        tracing::error!(error = %error, location = %error.location, "request failed");
    }
    let model = ErrorModel {
        message: error.public_message(),
        code: status,
        kind: error.kind.name(),
        version: VERSION,
        dev: if state.config.development { Some((error.location.file(), error.location.line())) } else { None },
    };
    Response::json_bytes(
        StatusCode::from_u16(status).unwrap_or(StatusCode::INTERNAL_SERVER_ERROR),
        crate::json::to_vec(&model),
    )
}
