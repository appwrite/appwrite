//! Authentication, roles and scopes (PHP: `app/controllers/shared/api.php`,
//! the `api` group's first init hook; hooks.json `auth`): the project in the
//! path, the role and scopes of the session, API key or console membership,
//! impersonation, products and service toggles, the scope check, blocked
//! users and MFA.

mod access;
mod admin;
mod keys;

use http::Method;
use serde_json::Value;
use utopia_database::Database;

use self::access::track_access;
use self::admin::admin_team;
use self::keys::{KeyOwner, track_key_usage};
use crate::Result;
use crate::auth::{KeyType, roles};
use crate::database::Project;
use crate::error::{Error, ErrorType};
use crate::platform::context::{Context, Mode};

/// The `api` group's authentication: `team_sequence` is the sequence of the
/// organization that owns an organization key (see the `apiKey` resource).
pub(in crate::platform) async fn auth(
    ctx: &mut Context,
    platform_db: &Database,
    header_project: &str,
    team_sequence: &str,
) -> Result<()> {
    let state = ctx.state.clone();
    // -- auth (shared/api.php) -----------------------------------------------
    // Project management under `/v1/projects/:projectId` acts on the project
    // in the path: no other project may be named.
    let path_project = project_id_from_path(ctx);
    if !path_project.is_empty() {
        for named in [header_project, ctx.project.id.as_str()] {
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
                KeyOwner::Team { id: key.team_id.clone(), sequence: team_sequence.to_owned() }
            };
            track_key_usage(ctx, owner, stored).await;
        }
        // An organization key acts as the organization's owner.
        if key.kind == KeyType::Organization {
            extra_roles.push(format!("team:{}", key.team_id));
            extra_roles.push(format!("team:{}/owner", key.team_id));
        }
    } else if let Some(team_id) = admin_team(ctx, platform_db).await? {
        let team_id = Some(team_id);
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
        // On the console, project routes act on the project in the path.
        let project_id = if ctx.project.is_console() && ctx.route.path.starts_with("/v1/projects/:projectId") {
            path_project.clone()
        } else {
            ctx.project.id.clone()
        };
        scopes = vec!["teams.read".to_owned(), "projects.read".to_owned()];
        let project_prefix = format!("project-{project_id}");
        for admin_role in admin_roles {
            let team_wide = !admin_role.starts_with("project-");
            if team_wide || (project_id != Project::CONSOLE && admin_role.starts_with(&project_prefix)) {
                let r =
                    if team_wide { admin_role.clone() } else { admin_role.rsplit('-').next().unwrap_or("").to_owned() };
                scopes.extend(roles::scopes(&r).into_iter().map(str::to_owned));
                extra_roles.push(r.clone());
                role = r;
            }
        }
        // The console's project list and project read the platform
        // database, where permissions limit an admin to their projects.
        let projects = ctx.project.is_console() && matches!(ctx.route.path, "/v1/projects" | "/v1/projects/:projectId");
        if !projects {
            authorization = false;
        }
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
    Ok(())
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

#[cfg(test)]
mod tests {
    use super::*;

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
