//! Request lifecycle: init hooks, action, shutdown hooks and error handling.
//!
//! The order of checks mirrors PHP exactly because it decides which error a
//! client sees (e.g. a scope error wins over a parameter error).

use std::sync::Arc;

use bytes::Bytes;
use http::{Method, StatusCode};
use serde_json::Value;
use utopia_database::value::list;
use utopia_database::{Database, Document, Query};
use utopia_http::{Compression, Request, Response};

use super::context::{Context, Mode};
use super::{Platform, Route, guards, shutdown};
use crate::auth::{self, ApiKey, KeyType, Owners, roles};
use crate::database::documents::{self, Attributes, User};
use crate::database::project;
use crate::database::{Databases, Project, filters};
use crate::error::{Error, ErrorType};
use crate::response::{ErrorModel, Version};
use crate::{Result, VERSION, datetime, network};

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
        labels: super::Labels::NONE,
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
    let (route, params) = match platform.router.find(method, &request.route_path()) {
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

/// The `allowedSchemes` resource: the console's, and for a project `exp`,
/// its callback scheme and its platforms' schemes.
pub(super) fn allowed_schemes(state: &super::State, project: &Project, project_found: bool) -> Vec<String> {
    let mut schemes = state.config.console_schemas.clone();
    if project_found && !project.is_console() {
        schemes.push("exp".to_owned());
        schemes.push(format!("appwrite-callback-{}", project.id));
        schemes.extend(network::platform_schemes(&project.platforms));
    }
    schemes
}

/// The `allowedHostnames` resource.
pub(super) fn allowed_hostnames(
    ctx_state: &super::State,
    project: &Project,
    project_found: bool,
    request: &Request,
    rule_domain: Option<&str>,
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
    if let Some(domain) = rule_domain.filter(|d| !d.is_empty()) {
        allowed.push(domain.to_owned());
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
    let allowed = allowed_hostnames(state, &console, true, request, None);
    for (k, v) in network::cors_headers(request.header_or_empty("origin"), &allowed) {
        response.add(k, &v);
    }
    response.set("server", "Appwrite");
    // `noContent()` sends the default content type.
    response.set("content-type", "text/html");
    // The user resource the handler's injections resolve: a session from the
    // project's cookie (the console's without one), else the fallback.
    let project = request.query_value("project").filter(|p| !p.is_empty()).unwrap_or(Project::CONSOLE);
    let cookie = format!("a_session_{project}");
    let session = request
        .cookie(&cookie)
        .or_else(|| request.cookie(&format!("{cookie}_legacy")))
        .or_else(|| request.header("x-appwrite-session"))
        .map(session_store)
        .is_some_and(|(id, secret)| !id.is_empty() || !secret.is_empty());
    response.add("x-debug-fallback", "false");
    if !session {
        response.add("x-debug-fallback", "true");
    }
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
    let platform_db = ctx.platform_db()?;

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

    // -- rule (the custom domain the request comes from) -------------------
    ctx.rule_domain = rule_domain(&state, &platform_db, &ctx.project, &ctx.request).await?;

    // -- mode --------------------------------------------------------------
    let mode = string_param(&ctx.request, "mode")
        .unwrap_or_else(|| ctx.request.header("x-appwrite-mode").unwrap_or("default").to_owned());
    ctx.mode = if mode == "admin" { Mode::Admin } else { Mode::Default };
    if !supplied_project.is_empty() && ctx.project.id != supplied_project {
        ctx.mode = Mode::Admin;
    }
    if ctx.mode == Mode::Admin && ctx.project_found && ctx.project.is_console() {
        session_from_request(ctx);
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
    // A user resource that throws is not kept: PHP resolves it again for the
    // error hook and once more after it, each time sending its headers.
    if let Err(e) = resolve_user(ctx).await {
        session_from_request(ctx);
        session_from_request(ctx);
        return Err(e);
    }

    // -- API key ---------------------------------------------------------------
    let key_header = ctx.request.header_or_empty("x-appwrite-key").to_owned();
    if !key_header.is_empty() {
        // The project's organization (`$team`) and its keys, for an
        // organization key.
        let organization = if key_header.starts_with("organization_") {
            match load_team_id(&platform_db, &ctx.project.team_internal_id).await? {
                Some(id) => {
                    let keys = project::owned_keys(&platform_db, "teams", &ctx.project.team_internal_id).await?;
                    Some((id, keys))
                }
                None => None,
            }
        } else {
            None
        };
        let account_user = ctx.user.as_ref().map(|u| u.id.clone()).unwrap_or_default();
        let owners = Owners {
            account: (!ctx.account_keys.is_empty()).then_some((account_user.as_str(), ctx.account_keys.as_slice())),
            organization: organization.as_ref().map(|(id, keys)| (id.as_str(), keys.as_slice())),
        };
        let key = ApiKey::decode(&ctx.project, &key_header, &state.config.openssl_key_v1, &owners);
        if !key.project_id.is_empty() && (header_project.is_empty() || header_project != key.project_id) {
            return Err(Error::new(ErrorType::ProjectIdMissing));
        }
        let user_header = ctx.request.header_or_empty("x-appwrite-user");
        if !key.user_id.is_empty() && (user_header.is_empty() || user_header != key.user_id) {
            return Err(Error::new(ErrorType::UserIdMissing));
        }
        let organization_header = ctx.request.header_or_empty("x-appwrite-organization");
        if !key.team_id.is_empty() && (organization_header.is_empty() || organization_header != key.team_id) {
            return Err(Error::new(ErrorType::OrganizationIdMissing));
        }
        ctx.key = Some(key);
    }
    // Request filters for an older response format: `general.php` adds them,
    // and the first read of the params runs them, before its headers.
    if let Some(format) = ctx.format
        && !ctx.route.namespace.is_empty()
    {
        let model = format!("{}.{}", ctx.route.namespace, ctx.route.sdk_method);
        let mut params = ctx.request.params().clone();
        crate::request::apply(format, &model, &mut params)?;
        if matches!(ctx.request.method(), "POST" | "PUT" | "PATCH" | "DELETE") {
            ctx.request.set_payload(params);
        } else {
            ctx.request.set_query(params);
        }
    }

    // `general.php` adds the security headers after the resources above.
    ctx.secured = true;

    // -- CORS / origin (I4) ----------------------------------------------------
    let origin = ctx.request.header_or_empty("origin").to_owned();
    if !origin.is_empty() && key_header.is_empty() {
        let allowed =
            allowed_hostnames(&state, &ctx.project, ctx.project_found, &ctx.request, ctx.rule_domain.as_deref());
        let schemes = allowed_schemes(&state, &ctx.project, ctx.project_found);
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
        if matches!(key.kind, KeyType::Standard | KeyType::Organization | KeyType::Account) {
            let Some(stored) = key.stored.clone() else {
                return Err(Error::new(ErrorType::UserUnauthorized));
            };
            let owner = if !key.project_id.is_empty() {
                KeyOwner::Project
            } else if !key.user_id.is_empty() {
                KeyOwner::User {
                    id: key.user_id.clone(),
                    sequence: ctx.user.as_ref().map(|u| u.sequence.to_string()).unwrap_or_default(),
                }
            } else {
                KeyOwner::Team { id: key.team_id.clone(), sequence: ctx.project.team_internal_id.clone() }
            };
            track_key_usage(ctx, owner, stored);
        }
        // An organization key acts as the organization's owner.
        if key.kind == KeyType::Organization {
            extra_roles.push(format!("team:{}", key.team_id));
            extra_roles.push(format!("team:{}/owner", key.team_id));
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
    for r in &ctx.roles {
        ctx.authorization.add_role(r);
    }
    if !authorization {
        ctx.authorization.set_default_status(false);
    }

    // Admin requests must be able to read the project.
    if ctx.user.is_some() && !ctx.project.is_console() && ctx.mode == Mode::Admin && ctx.key.is_none() {
        let read_roles = ctx.project.read_roles();
        if !read_roles.iter().any(|r| ctx.roles.contains(r)) {
            return Err(Error::new(ErrorType::ProjectNotFound));
        }
    }

    track_access(ctx).await;

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

    // The hooks routes opt into, in PHP's order.
    guards::abuse(ctx).await?;
    guards::session_active(ctx)?;
    guards::mfa_fresh(ctx)?;
    guards::recent_session(ctx)?;
    guards::auth_methods(ctx).await?;
    Ok(())
}

/// The `$id` of the project's team (the `team` resource:
/// `findOne('teams', [equal('$sequence', [$teamInternalId])])`).
/// PHP's `rule` resource: the custom domain rule of the request's origin
/// (or referer), when it serves the project or a trusted one.
async fn rule_domain(
    state: &super::State,
    platform_db: &Database,
    project: &Project,
    request: &Request,
) -> Result<Option<String>> {
    let source = request.header("origin").and_then(network::url_host).filter(|h| !h.is_empty());
    let Some(domain) =
        source.or_else(|| request.header("referer").and_then(network::url_host)).filter(|h| !h.is_empty())
    else {
        return Ok(None);
    };
    let authorization = platform_db.authorization();
    let rule = if state.config.rules_md5 {
        use md5::{Digest, Md5};
        let id = hex::encode(Md5::digest(domain.as_bytes()));
        authorization.skip(|| platform_db.get_document("rules", &id, Vec::new(), false)).await?
    } else {
        authorization.skip(|| platform_db.find_one("rules", vec![Query::equal("domain", [domain.as_str()])])).await?
    };
    if rule.is_empty() {
        return Ok(None);
    }
    // A project without a sequence (the console's) matches no rule.
    let permits = !project.sequence.is_empty() && rule.string_or_empty("projectInternalId") == project.sequence;
    let rule_project = rule.string_or_empty("projectId");
    let trusted = !rule_project.is_empty() && state.config.console_trusted_projects.contains(&rule_project);
    if !permits && !trusted {
        return Ok(None);
    }
    Ok(Some(rule.string_or_empty("domain")))
}

async fn load_team_id(platform_db: &Database, team_internal_id: &str) -> Result<Option<String>> {
    if team_internal_id.is_empty() {
        return Ok(None);
    }
    let authorization = platform_db.authorization();
    let team = authorization
        .skip(|| platform_db.find_one("teams", vec![Query::equal("$sequence", [team_internal_id])]))
        .await?;
    Ok(if team.is_empty() { None } else { Some(team.id().to_owned()) })
}

/// The user an impersonator names (the `targetUser` resource): by id,
/// else by email (lower-cased), else by phone; with authorization skipped.
async fn impersonated(db: &Database, id: &str, email: &str, phone: &str) -> Result<Option<User>> {
    let authorization = db.authorization();
    let document = if !id.is_empty() {
        authorization.skip(|| db.get_document("users", id, Vec::new(), false)).await?
    } else if !email.is_empty() {
        let email = email.to_lowercase();
        authorization.skip(|| db.find_one("users", vec![Query::equal("email", [email.as_str()])])).await?
    } else if !phone.is_empty() {
        authorization.skip(|| db.find_one("users", vec![Query::equal("phone", [phone])])).await?
    } else {
        Document::default()
    };
    Ok(if document.is_empty() { None } else { Some(User::from(&document)) })
}

/// The `id` and `secret` of a session store (`Utopia\Auth\Store::decode()`),
/// empty when missing.
fn session_store(data: &str) -> (String, String) {
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
fn session_from_request(ctx: &mut Context) -> (String, String) {
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

async fn resolve_user(ctx: &mut Context) -> Result<()> {
    let state = ctx.state.clone();
    let store = session_from_request(ctx);

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
        if let Some(target) = impersonated(&users_db, &by_id, &by_email, &by_phone).await? {
            ctx.target_user = Some(target);
        }
    }
    Ok(())
}

/// Who owns a key: the project (standard keys), a console user (account
/// keys) or the project's organization (organization keys), with its
/// internal id for the lock.
enum KeyOwner {
    Project,
    User { id: String, sequence: String },
    Team { id: String, sequence: String },
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
    let Some(user) = authorization.skip(|| documents::load_user(&platform, &user_id)).await? else {
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

/// Updates `accessedAt` / `sdks` of a stored key (once per 24h or new SDK).
fn track_key_usage(ctx: &Context, owner: KeyOwner, key: crate::database::project::Key) {
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
        let Ok(db) = state.databases.platform(Databases::unchecked()) else { return };
        let owner_sequence = match &owner {
            KeyOwner::Project => project.sequence.clone(),
            KeyOwner::User { sequence, .. } | KeyOwner::Team { sequence, .. } => sequence.clone(),
        };
        let lock_key = format!("lock:platform:{owner_sequence}:keys:{}", key.id);
        let state2 = state.clone();
        let _ = state
            .lock
            .try_with_key(&lock_key, async move {
                let mut updates = Document::default();
                updates.set("accessedAt", datetime::value(&now));
                if new_sdk {
                    let mut sdks = key.sdks.clone();
                    sdks.push(sdk);
                    updates.set("sdks", list(sdks.iter().map(|s| utopia_database::Value::from(s.as_str()))));
                }
                match db.update_document("keys", &key.id, updates).await {
                    Ok(_) => {
                        // The document whose `keys` sub-query holds the key.
                        let (collection, id) = match &owner {
                            KeyOwner::Project => ("projects", project.id.as_str()),
                            KeyOwner::User { id, .. } => ("users", id.as_str()),
                            KeyOwner::Team { id, .. } => ("teams", id.as_str()),
                        };
                        let purged = db.authorization().skip(|| db.purge_cached_document(collection, Some(id))).await;
                        if let Err(e) = purged {
                            tracing::warn!(error = %e, collection, "key owner cache purge failed");
                        }
                        if matches!(owner, KeyOwner::Project) {
                            state2.projects.forget(&project.id);
                        }
                    }
                    Err(e) => tracing::warn!(error = %e, "key usage update failed"),
                }
            })
            .await;
    });
}

/// Updates project and user `accessedAt` (once per 24h), without the
/// sub-queries (`updateDocument` returns the document decoded, and nothing
/// reads it).
async fn track_access(ctx: &mut Context) {
    let now = datetime::now();
    let threshold = datetime::add_seconds(now, -86_400);
    let state = ctx.state.clone();
    if !ctx.project.is_console() && ctx.project.accessed_at.map(|a| a < threshold).unwrap_or(true) {
        let project = ctx.project.clone();
        let state = state.clone();
        tokio::spawn(async move {
            let Ok(db) = state.databases.platform(Databases::unchecked()) else { return };
            let key = format!("lock:platform:projects:{}:accessedAt", project.id);
            let state2 = state.clone();
            let _ = state
                .lock
                .try_with_key(&key, async move {
                    let mut updates = Document::default();
                    updates.set("accessedAt", datetime::value(&now));
                    let skipped = filters::names(filters::PROJECTS_SUBQUERIES);
                    let updated =
                        db.skip_filters(|| db.update_document("projects", &project.id, updates), Some(&skipped)).await;
                    match updated {
                        Ok(_) => state2.projects.forget(&project.id),
                        Err(e) => tracing::warn!(error = %e, "project access update failed"),
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
        match state.databases.project(&ctx.project, Databases::unchecked()) {
            Ok(db) => (db, format!("lock:project:{}:users:{}:accessedAt", ctx.project.sequence, user_seq)),
            Err(_) => return,
        }
    } else {
        match state.databases.platform(Databases::unchecked()) {
            Ok(db) => (db, format!("lock:platform:{user_seq}:users:{user_id}:accessedAt")),
            Err(_) => return,
        }
    };
    // Before the action, as in PHP: an action that writes the user returns
    // it with its access time, and the action reads it too.
    let _ = state
        .lock
        .try_with_key(&lock_key, async move {
            let mut updates = Document::default();
            updates.set("accessedAt", datetime::value(&now));
            let skipped = filters::names(filters::USERS_SUBQUERIES);
            let updated = db.skip_filters(|| db.update_document("users", &user_id, updates), Some(&skipped)).await;
            if let Err(e) = updated {
                tracing::warn!(error = %e, "user access update failed");
            }
        })
        .await;
    if let Some(user) = ctx.user.as_mut() {
        user.accessed_at = Some(datetime::truncate(now));
    }
}

/// Builds the final response (success or error) and schedules shutdown work.
pub(super) fn finalize(ctx: Context, result: Result<Response>) -> Response {
    let state = ctx.state.clone();
    let allowed = allowed_hostnames(&state, &ctx.project, ctx.project_found, &ctx.request, ctx.rule_domain.as_deref());
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
