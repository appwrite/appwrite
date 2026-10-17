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
        // Every init hook is bound to a group (`api`, `web`, ...): a route
        // outside them (the OAuth2 callbacks) runs its action alone.
        if ctx.route.has_group("api") {
            authenticate(&mut ctx).await?;
            state.hooks.init(&mut ctx).await?;
        } else if ctx.route.groups.iter().any(|g| CONSOLE_FORBIDDEN.contains(g)) {
            resolve_project(&mut ctx).await;
        }
        // Hooks run group by group, in the route's order: these groups'
        // come after every `api` hook.
        console_forbidden(&ctx)?;
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
        resolve_project(&mut ctx).await;
    }
    finalize(ctx, result)
}

/// The `project` resource alone (for routes outside the `api` group).
async fn resolve_project(ctx: &mut Context) {
    let Ok(platform_db) = ctx.platform_db() else { return };
    let project_id = match ctx.request.params().get("project") {
        Some(Value::String(s)) => s.clone(),
        _ => ctx.request.header_or_empty("x-appwrite-project").to_owned(),
    };
    let project_id =
        if project_id.is_empty() { ctx.request.query_value("project").unwrap_or("").to_owned() } else { project_id };
    if project_id.is_empty() || project_id == Project::CONSOLE {
        return;
    }
    match ctx.state.projects.get(&platform_db, &project_id).await {
        Ok(Some(p)) => ctx.project = p,
        _ => {
            ctx.project = Arc::new(Project::empty());
            ctx.project_found = false;
        }
    }
}

/// The `projectIdFromPath` resource: the route's `projectId` when the
/// request path is under `/v1/projects`.
fn project_id_from_path(ctx: &Context) -> String {
    let path = ctx.request.route_path();
    path_project(&path, &ctx.path_params)
}

fn path_project(path: &str, params: &[(String, Value)]) -> String {
    let mut segments = path.split('/').filter(|s| !s.is_empty());
    if segments.next() != Some("v1") || segments.next() != Some("projects") {
        return String::new();
    }
    match params.iter().find(|(k, _)| k == "projectId") {
        Some((_, Value::String(id))) => id.clone(),
        _ => String::new(),
    }
}

/// Whether the product behind an SDK namespace is on (any namespace but
/// the operator's products is).
fn product_enabled(products: &[(&str, bool)], namespace: &str) -> bool {
    let namespace = namespace.to_lowercase();
    products.iter().find(|(name, _)| *name == namespace).is_none_or(|(_, on)| *on)
}

/// Whether the `Host` header names a host forced HTTPS leaves alone:
/// `localhost` and the migration host, with or without the port (internal
/// traffic before a public domain is set up).
fn local_host(host: &str, port: &str, migration_host: Option<&str>) -> bool {
    let mut local = vec!["localhost".to_owned(), format!("localhost:{port}")];
    if let Some(migration) = migration_host.filter(|h| !h.is_empty() && *h != "0") {
        local.push(migration.to_owned());
        local.push(format!("{migration}:{port}"));
    }
    local.iter().any(|h| h == host)
}

/// The groups of the project services the console has none of.
const CONSOLE_FORBIDDEN: [&str; 4] = ["database", "functions", "sites", "messaging"];

/// `general.php`'s init hook for the `database`, `functions`, `sites` and
/// `messaging` groups: the console project has no databases, functions,
/// sites or messaging, and a request that names no project is told so.
fn console_forbidden(ctx: &Context) -> Result<()> {
    refuse_console(ctx.route.groups, &ctx.project.id, ctx.request.header_or_empty("x-appwrite-project"))
}

/// [`console_forbidden`] for a route's groups, the project and the
/// `X-Appwrite-Project` header.
fn refuse_console(groups: &[&str], project_id: &str, header: &str) -> Result<()> {
    if !groups.iter().any(|g| CONSOLE_FORBIDDEN.contains(g)) || project_id != Project::CONSOLE {
        return Ok(());
    }
    let message = if header.is_empty() {
        "No Appwrite project was specified. Please specify your project ID when initializing your Appwrite SDK."
    } else {
        "This endpoint is not available for the console project. The Appwrite Console is a reserved project ID and cannot be used with the Appwrite SDKs and APIs. Please check if your project ID is correct."
    };
    Err(Error::with_message(ErrorType::GeneralAccessForbidden, message))
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

/// The `allowedSchemes` resource: the console's schemes, and for a project
/// `exp`, its callback scheme and its platforms' schemes.
pub(super) fn allowed_schemes(state: &super::State, project: &Project, project_found: bool) -> Vec<String> {
    let mut schemes = state.config.console_schemas.clone();
    if project_found && !project.is_console() {
        schemes.push("exp".to_owned());
        schemes.push(format!("appwrite-callback-{}", project.id));
        schemes.extend(network::platform_schemes(&project.platforms));
    }
    let mut unique: Vec<String> = Vec::with_capacity(schemes.len());
    for s in schemes {
        if !unique.contains(&s) {
            unique.push(s);
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
    // `version_compare()` against each filter's version (see
    // `Version::from_format`).
    let format = ctx
        .request
        .header("x-appwrite-response-format")
        .map(str::to_owned)
        .or_else(|| state.config.response_format.clone())
        .unwrap_or_default();
    if let Some(f) = Version::from_format(&format) {
        if crate::response::version_compare(&format, VERSION) == std::cmp::Ordering::Greater {
            ctx.response_headers.push((
                "x-appwrite-warning",
                format!(
                    "The current SDK is built for Appwrite {format}. However, the current Appwrite server version is {VERSION}. Please downgrade your SDK to match the Appwrite version: https://appwrite.io/docs/sdks"
                ),
            ));
        }
        if f < Version::parse(VERSION).expect("valid version") {
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
        // `V20` (before 1.8.0) reads the project database and the route's
        // collection.
        let db = if format < Version(1, 8, 0)
            && matches!(model.as_str(), "databases.getDocument" | "databases.listDocuments")
        {
            Some(ctx.db()?)
        } else {
            None
        };
        let path = |key: &str| match ctx.param(key) {
            Some(Value::String(s)) => s.clone(),
            _ => String::new(),
        };
        let related = db.as_ref().map(|db| crate::request::Related {
            db,
            database_id: path("databaseId"),
            collection_id: path("collectionId"),
        });
        crate::request::apply(format, &model, &mut params, related).await?;
        if matches!(ctx.request.method(), "POST" | "PUT" | "PATCH" | "DELETE") {
            ctx.request.set_payload(params);
        } else {
            ctx.request.set_query(params);
        }
    }

    // -- forced HTTPS ---------------------------------------------------------
    // `_APP_OPTIONS_FORCE_HTTPS`: plain HTTP is redirected (GET) or refused,
    // except on localhost and the migration host (proxies). The redirect is
    // sent here, before the security headers; the hooks after it still run,
    // and the action does not.
    if state.config.force_https && ctx.request.protocol() != "https" {
        let local = local_host(
            ctx.request.header_or_empty("host"),
            &ctx.request.port(),
            state.config.migration_host.as_deref(),
        );
        if !local {
            if ctx.request.method() != Method::GET {
                return Err(Error::with_message(
                    ErrorType::GeneralProtocolUnsupported,
                    "Method unsupported over HTTP. Please use HTTPS instead.",
                ));
            }
            let url = format!("https://{}{}", ctx.request.hostname(), ctx.request.uri());
            let mut response = Response::with_body(StatusCode::MOVED_PERMANENTLY, "text/html", Bytes::new());
            for (k, v) in &ctx.response_headers {
                response.add(k, v);
            }
            response.add("location", &url);
            ctx.sent = Some(response);
        }
    }

    // `general.php` adds the security headers after the resources above.
    ctx.secured = true;

    // -- CORS / origin (I4) ----------------------------------------------------
    let origin = ctx.request.header_or_empty("origin").to_owned();
    if !origin.is_empty() && key_header.is_empty() && !ctx.route.labels.any_origin {
        let allowed =
            allowed_hostnames(&state, &ctx.project, ctx.project_found, &ctx.request, ctx.rule_domain.as_deref());
        let schemes = allowed_schemes(&state, &ctx.project, ctx.project_found);
        if let Err(description) = network::validate_origin(&origin, &allowed, &schemes) {
            return Err(Error::with_message(ErrorType::GeneralUnknownOrigin, description));
        }
    }

    // -- certificate for the main domain -------------------------------------
    certify(ctx).await?;

    // -- auth (shared/api.php) -----------------------------------------------
    // Project management under `/v1/projects/:projectId` acts on the project
    // in the path: no other project may be named.
    let path_project = project_id_from_path(ctx);
    if !path_project.is_empty() {
        for named in [header_project.as_str(), ctx.project.id.as_str()] {
            if !named.is_empty() && named != Project::CONSOLE && named != path_project {
                return Err(Error::new(ErrorType::UserUnauthorized));
            }
        }
    }
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
            track_key_usage(ctx, owner, stored).await;
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
        ctx.authorization.set_status(false);
    }

    // Admin requests must be able to read the project.
    if ctx.user.is_some() && !ctx.project.is_console() && ctx.mode == Mode::Admin && ctx.key.is_none() {
        let read_roles = ctx.project.read_roles();
        if !read_roles.iter().any(|r| ctx.roles.contains(r)) {
            return Err(Error::new(ErrorType::ProjectNotFound));
        }
    }

    track_access(ctx).await;

    // Impersonation shows the target's account without letting the
    // impersonator change it: account writes are refused unless the route
    // allows them, and `deny` refuses a route on any method.
    if ctx.target_user.is_some() && !ctx.route.namespace.is_empty() {
        let impersonation = ctx.route.labels.impersonation;
        let account_write = ctx.route.namespace == "account" && ctx.request.method() != Method::GET;
        if impersonation == "deny" || (account_write && impersonation != "allow") {
            return Err(Error::new(ErrorType::UserImpersonationReadOnly));
        }
    }

    // Products an operator provisions (DocumentsDB, VectorsDB, embeddings)
    // are closed to everyone, keys included, until turned on.
    if !ctx.route.namespace.is_empty() && !product_enabled(&state.config.products, ctx.route.namespace) {
        return Err(Error::new(ErrorType::GeneralServiceDisabled));
    }

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
        authorization.skip(platform_db.get_document("rules", &id, Vec::new(), false)).await?
    } else {
        authorization.skip(platform_db.find_one("rules", vec![Query::equal("domain", [domain.as_str()])])).await?
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

/// `general.php`'s certificate hook: the first request to one of the
/// platform's own hostnames (a known, non-test domain) creates its `rules`
/// document and queues a certificate for it. Each hostname is looked at
/// once per process.
async fn certify(ctx: &Context) -> Result<()> {
    let state = &ctx.state;
    let hostname = ctx.request.hostname();
    let seen = |name: &str| state.certified.lock().unwrap_or_else(|e| e.into_inner()).contains(name);
    let remember = |name: &str| {
        state.certified.lock().unwrap_or_else(|e| e.into_inner()).insert(name.to_owned());
    };
    if seen(&hostname) {
        return Ok(());
    }
    let domain = utopia_domains::Domain::new(&hostname)
        .map_err(|e| Error::with_message(ErrorType::GeneralUnknown, e.to_string()))?;
    let name = domain.get().to_owned();
    if name.is_empty() || !domain.is_known() || domain.is_test() {
        remember(&name);
        return Ok(());
    }
    if ctx.request.uri().starts_with("/.well-known/acme-challenge") {
        return Ok(());
    }
    if !state.config.platform_hostnames().contains(&name) {
        return Ok(());
    }
    // Whatever happens next, the hostname is not looked at again.
    let issued = issue(ctx, &name).await;
    remember(&name);
    issued
}

/// Creates the `rules` document of a platform hostname and queues its
/// certificate, unless the rule exists.
async fn issue(ctx: &Context, domain: &str) -> Result<()> {
    let state = &ctx.state;
    let platform_db = ctx.platform_db()?;
    let authorization = platform_db.authorization();
    let existing = if state.config.rules_md5 {
        use md5::{Digest, Md5};
        let id = hex::encode(Md5::digest(domain.as_bytes()));
        authorization.skip(platform_db.get_document("rules", &id, Vec::new(), false)).await?
    } else {
        authorization.skip(platform_db.find_one("rules", vec![Query::equal("domain", [domain])])).await?
    };
    if !existing.is_empty() {
        return Ok(());
    }
    // A domain under one of Appwrite's own is Appwrite's.
    let owner =
        if state.config.appwrite_domains.iter().any(|d| domain.ends_with(d.as_str())) { "Appwrite" } else { "" };
    let id = if state.config.rules_md5 {
        use md5::{Digest, Md5};
        hex::encode(Md5::digest(domain.as_bytes()))
    } else {
        crate::database::unique_id()
    };
    let console = state.projects.console();
    let mut rule = Document::default();
    rule.put("$id", id.as_str().into());
    rule.put("domain", domain.into());
    rule.put("type", "api".into());
    rule.put("status", "verifying".into());
    rule.put("projectId", console.id.as_str().into());
    rule.put("projectInternalId", console.sequence.as_str().into());
    rule.put("search", format!("{id} {domain}").as_str().into());
    rule.put("owner", owner.into());
    rule.put("region", console.region.as_str().into());
    let rule = match authorization.skip(platform_db.create_document("rules", rule)).await {
        Ok(rule) => rule,
        Err(e) if e.is_a(utopia_database::Kind::Duplicate) => {
            tracing::info!("Certificate already exists");
            return Ok(());
        }
        Err(e) => return Err(e.into()),
    };
    // `RuleCreated` has no listener in CE.
    tracing::info!("Issuing a TLS certificate for the main domain ({domain}) in a few seconds...");
    let message = serde_json::json!({
        "project": {
            "$id": console.id,
            "$sequence": console.sequence,
            "database": console.database,
        },
        "domain": utopia_database::Value::Array(rule.array_copy()).to_json(),
        "skipRenewCheck": true,
        "validationDomain": null,
        "action": "generation",
        "skipDomainValidation": false,
    });
    ctx.enqueue(&state.config.queue_certificates, &message).await;
    Ok(())
}

async fn load_team_id(platform_db: &Database, team_internal_id: &str) -> Result<Option<String>> {
    if team_internal_id.is_empty() {
        return Ok(None);
    }
    let authorization = platform_db.authorization();
    let team =
        authorization.skip(platform_db.find_one("teams", vec![Query::equal("$sequence", [team_internal_id])])).await?;
    Ok(if team.is_empty() { None } else { Some(team.id().to_owned()) })
}

/// The user an impersonator names (the `targetUser` resource): by id,
/// else by email (lower-cased), else by phone; with authorization skipped.
async fn impersonated(db: &Database, id: &str, email: &str, phone: &str) -> Result<Option<User>> {
    let authorization = db.authorization();
    let document = if !id.is_empty() {
        authorization.skip(db.get_document("users", id, Vec::new(), false)).await?
    } else if !email.is_empty() {
        let email = email.to_lowercase();
        authorization.skip(db.find_one("users", vec![Query::equal("email", [email.as_str()])])).await?
    } else if !phone.is_empty() {
        authorization.skip(db.find_one("users", vec![Query::equal("phone", [phone])])).await?
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
    let key =
        if ctx.mode == Mode::Admin { "a_session_console".to_owned() } else { format!("a_session_{}", ctx.project.id) };
    ctx.store = super::context::Store { key, id: store.0.clone(), secret: store.1.clone() };

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

/// Updates `accessedAt` / `sdks` of a stored key (once per 24h or new SDK),
/// before the action as in PHP: the update and its cache purges are done
/// by the time the response is sent.
async fn track_key_usage(ctx: &Context, owner: KeyOwner, key: crate::database::project::Key) {
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
            updates.put("accessedAt", datetime::value(&now));
            if new_sdk {
                let mut sdks = key.sdks.clone();
                sdks.push(sdk);
                updates.put("sdks", list(sdks.iter().map(|s| utopia_database::Value::from(s.as_str()))));
            }
            match db.update_document("keys", &key.id, updates).await {
                Ok(_) => {
                    // The document whose `keys` sub-query holds the key.
                    let (collection, id) = match &owner {
                        KeyOwner::Project => ("projects", project.id.as_str()),
                        KeyOwner::User { id, .. } => ("users", id.as_str()),
                        KeyOwner::Team { id, .. } => ("teams", id.as_str()),
                    };
                    let purged = db.authorization().skip(db.purge_cached_document(collection, id)).await;
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
        utopia_async::spawn(async move {
            let Ok(db) = state.databases.platform(Databases::unchecked()) else { return };
            let key = format!("lock:platform:projects:{}:accessedAt", project.id);
            let state2 = state.clone();
            let _ = state
                .lock
                .try_with_key(&key, async move {
                    let mut updates = Document::default();
                    updates.put("accessedAt", datetime::value(&now));
                    let skipped = filters::names(filters::PROJECTS_SUBQUERIES);
                    let updated = db
                        .skip_filters(Some(skipped.as_slice()), db.update_document("projects", &project.id, updates))
                        .await;
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
            updates.put("accessedAt", datetime::value(&now));
            let skipped = filters::names(filters::USERS_SUBQUERIES);
            let updated =
                db.skip_filters(Some(skipped.as_slice()), db.update_document("users", &user_id, updates)).await;
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
pub(super) fn finalize(mut ctx: Context, result: Result<Response>) -> Response {
    let state = ctx.state.clone();
    // A response a hook sent stays as it was sent; an error after it only
    // skips the shutdown hooks.
    if let Some(mut sent) = ctx.sent.take() {
        ctx.failed |= result.is_err();
        // Sent before the security headers: Swoole's own `Server`.
        sent.set("server", "swoole-http-server");
        return send(ctx, sent, true);
    }
    let allowed = allowed_hostnames(&state, &ctx.project, ctx.project_found, &ctx.request, ctx.rule_domain.as_deref());
    let cors = network::cors_headers(ctx.request.header_or_empty("origin"), &allowed);
    let https = ctx
        .request
        .header("x-forwarded-proto")
        .map(|p| p.split(',').next().unwrap_or("").trim().eq_ignore_ascii_case("https"))
        .unwrap_or(false);

    let mut response = match result {
        Ok(response) => response,
        // Routes labelled `error` show browsers the error page; SDKs send
        // `accept: text/html` too, so only requests without `x-sdk-name`.
        Err(error) if ctx.route.labels.error_page && ctx.request.header_or_empty("x-sdk-name").is_empty() => {
            if error.status() >= 500 {
                tracing::error!(error = %error, location = %error.location, "request failed");
            }
            let url = if state.config.development {
                format!("{}://localhost", state.config.link_scheme)
            } else {
                let host = state.config.platform.get("consoleHostname").and_then(Value::as_str).unwrap_or("");
                format!("{}://{host}", state.config.link_scheme)
            };
            let html = super::error_page::render(
                &ctx.project.name,
                error.public_message(),
                error.kind.name(),
                error.status(),
                state.config.development,
                &url,
            );
            Response::with_body(
                StatusCode::from_u16(error.status()).unwrap_or(StatusCode::INTERNAL_SERVER_ERROR),
                "text/html; charset=UTF-8",
                html,
            )
        }
        Err(error) => error_response(&state, &error),
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

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn console_is_refused_by_project_services() {
        assert!(refuse_console(&["api", "users"], Project::CONSOLE, "").is_ok());
        assert!(refuse_console(&["api", "database"], "project1", "project1").is_ok());
        let missing = refuse_console(&["api", "database"], Project::CONSOLE, "").unwrap_err();
        assert_eq!(missing.kind, ErrorType::GeneralAccessForbidden);
        assert!(missing.public_message().starts_with("No Appwrite project was specified."));
        let console = refuse_console(&["api", "messaging"], Project::CONSOLE, "console").unwrap_err();
        assert!(console.public_message().starts_with("This endpoint is not available for the console project."));
        for group in CONSOLE_FORBIDDEN {
            assert!(refuse_console(&[group], Project::CONSOLE, "").is_err());
        }
    }

    #[test]
    fn forced_https_spares_local_hosts() {
        assert!(local_host("localhost", "", None));
        assert!(local_host("localhost:8080", "8080", None));
        assert!(!local_host("localhost:8080", "80", None));
        assert!(!local_host("appwrite.io", "443", None));
        assert!(local_host("migration", "80", Some("migration")));
        assert!(local_host("migration:80", "80", Some("migration")));
        assert!(!local_host("0", "80", Some("0")));
    }

    #[test]
    fn project_from_path_only_under_projects() {
        let params = vec![("projectId".to_owned(), Value::String("p1".to_owned()))];
        assert_eq!(path_project("/v1/projects/p1/keys", &params), "p1");
        assert_eq!(path_project("//v1//projects/p1", &params), "p1");
        assert_eq!(path_project("/v1/users", &params), "");
        assert_eq!(path_project("/v1/projects", &[]), "");
    }

    #[test]
    fn products_are_closed_until_enabled() {
        let products = [("documentsdb", false), ("vectorsdb", true), ("embeddings", true)];
        assert!(!product_enabled(&products, "documentsdb"));
        assert!(!product_enabled(&products, "DocumentsDB"));
        assert!(product_enabled(&products, "vectorsdb"));
        assert!(product_enabled(&products, "users"));
    }
}
