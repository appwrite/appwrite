//! Console (session-cookie) authentication for the `api` group. Rust port of
//! the session half of `app/init/resources/request.php`'s `user` resource plus
//! the "Admin User Authentication" branch of `app/controllers/shared/api.php`.
//!
//! The Console sends `cookie: a_session_console=<store>` and
//! `x-appwrite-mode: admin` with no API key. Resolving that into a scope list
//! takes four steps, all mirrored below: decode the Store payload, load the
//! console user from the platform database, verify the session secret against
//! that user's sessions, and turn the user's confirmed membership on the
//! project's team into the scopes `app/config/roles.php` grants those roles.
//!
//! Simplifications versus PHP (documented, not silently dropped): no
//! `x-fallback-cookies` header (a workaround for clients that block
//! third-party cookies), and no JWT or account-API-key branch. Neither is
//! reachable from the Console flows this module serves.

use std::sync::Arc;

use appwrite_auth::Key;
use serde_json::Value;
use utopia_auth::{Proof, Store};
use utopia_database::{AttrValue, Query};
use utopia_http::ActionContext;

use crate::state::{AppwriteState, ProjectDatabase, ProjectDb};

/// PHP `APP_MODE_ADMIN`.
pub const MODE_ADMIN: &str = "admin";
/// PHP's console project id.
const CONSOLE: &str = "console";

/// PHP `app/config/roles.php`'s `$member`.
const MEMBER_SCOPES: &[&str] = &[
    "global",
    "public",
    "home",
    "console",
    "graphql",
    "sessions.write",
    "account",
    "teams.read",
    "teams.write",
    "presences.read",
    "presences.write",
    "documents.read",
    "documents.write",
    "rows.read",
    "rows.write",
    "embeddings.write",
    "files.read",
    "files.write",
    "projects.read",
    "locale.read",
    "avatars.read",
    "executions.read",
    "executions.write",
    "targets.read",
    "targets.write",
    "subscribers.write",
    "subscribers.read",
    "assistant.read",
    "rules.read",
];

/// PHP `app/config/roles.php`'s `$admins`.
const ADMIN_SCOPES: &[&str] = &[
    "global",
    "graphql",
    "sessions.write",
    "teams.read",
    "teams.write",
    "documents.read",
    "documents.write",
    "rows.read",
    "rows.write",
    "embeddings.write",
    "files.read",
    "files.write",
    "buckets.read",
    "buckets.write",
    "users.read",
    "users.write",
    "presences.read",
    "presences.write",
    "databases.read",
    "databases.write",
    "collections.read",
    "collections.write",
    "tables.read",
    "tables.write",
    "platforms.read",
    "platforms.write",
    "mocks.read",
    "mocks.write",
    "project.policies.read",
    "project.policies.write",
    "project.oauth2.read",
    "project.oauth2.write",
    "templates.read",
    "templates.write",
    "projects.write",
    "keys.read",
    "keys.write",
    "devKeys.read",
    "devKeys.write",
    "webhooks.read",
    "webhooks.write",
    "project.read",
    "project.write",
    "locale.read",
    "avatars.read",
    "health.read",
    "functions.read",
    "functions.write",
    "sites.read",
    "sites.write",
    "log.read",
    "log.write",
    "executions.read",
    "executions.write",
    "rules.read",
    "rules.write",
    "migrations.read",
    "migrations.write",
    "vcs.read",
    "vcs.write",
    "targets.read",
    "targets.write",
    "providers.write",
    "providers.read",
    "messages.write",
    "messages.read",
    "topics.write",
    "topics.read",
    "subscribers.write",
    "subscribers.read",
    "tokens.read",
    "tokens.write",
    "schedules.read",
    "schedules.write",
    "stages.read",
    "stages.write",
    "insights.read",
    "insights.write",
    "reports.read",
    "reports.write",
];

/// PHP `$roles[$role]['scopes']`.
fn scopes_for_role(role: &str) -> Vec<&'static str> {
    match role {
        "admin" | "developer" => ADMIN_SCOPES.to_vec(),
        "owner" => {
            let mut scopes = MEMBER_SCOPES.to_vec();
            scopes.extend_from_slice(ADMIN_SCOPES);
            scopes
        }
        "users" => MEMBER_SCOPES.to_vec(),
        _ => Vec::new(),
    }
}

/// An authenticated Console user and the scopes their team membership grants.
#[derive(Debug)]
pub struct Session {
    pub user: Value,
    pub key: Key,
}

/// PHP `$request->getHeaderLine('x-appwrite-mode', APP_MODE_DEFAULT)`.
#[must_use]
pub fn mode(ctx: &ActionContext) -> String {
    let header = ctx.request().header_line("x-appwrite-mode");
    if header.is_empty() {
        "default".to_string()
    } else {
        header
    }
}

/// Resolve the session-authenticated user for this request, or `None` when
/// the request carries no session, the session does not verify, or an admin
/// request's user has no confirmed membership on the project's team.
#[must_use]
pub fn resolve(
    state: &Arc<AppwriteState>,
    ctx: &ActionContext,
    project: &Value,
    project_db: &ProjectDatabase,
) -> Option<Session> {
    let project_id = project.get("$id").and_then(Value::as_str).unwrap_or_default();
    let mode = mode(ctx);

    let store = decode_store(ctx, project_id, &mode)?;
    let id = store.get_property("id")?.as_str()?.to_string();
    let secret = store.get_property("secret")?.as_str()?.to_string();
    if id.is_empty() || secret.is_empty() {
        return None;
    }

    // PHP reads the platform database for admin mode and for the console
    // project itself; every other request reads the project's own users.
    let is_admin = mode == MODE_ADMIN || project_id == CONSOLE;
    let platform = if is_admin { state.platform_db() } else { None };
    let mut guard = match &platform {
        Some(platform) => platform.lock().unwrap_or_else(|error| error.into_inner()),
        None if is_admin => return None,
        None => project_db.lock().unwrap_or_else(|error| error.into_inner()),
    };
    let db = &mut *guard;

    let user = db.get_document("users", &id, &[], false).ok()?;
    if user.is_empty() || !session_verifies(db, &user, &secret) {
        return None;
    }

    let mut scopes = if is_admin {
        admin_scopes(db, &user, project)?
    } else {
        // A plain project session is PHP's `ROLE_USERS`.
        MEMBER_SCOPES.iter().map(|scope| (*scope).to_string()).collect()
    };

    // PHP grants `users.read` to an impersonator so the Console can look a
    // target user up before impersonation starts, and keeps it for the
    // duration of the impersonation.
    let impersonator = user
        .get_attribute("impersonator")
        .as_bool()
        .unwrap_or(false);
    if (impersonator || impersonating(ctx)) && !scopes.iter().any(|scope| scope == "users.read") {
        scopes.push("users.read".to_string());
    }
    scopes.sort_unstable();
    scopes.dedup();

    let user_json = crate::state::document_to_json(&user);
    Some(Session {
        key: Key {
            project_id: project_id.to_string(),
            scopes,
            name: "Session".to_string(),
            key_type: appwrite_auth::TYPE_STANDARD.to_string(),
            expired: false,
            role: if is_admin { "admin" } else { "users" }.to_string(),
        },
        user: user_json,
    })
}

/// PHP's "Admin User Authentication" branch: the scopes the user's confirmed
/// membership on the project's team grants. `None` where PHP throws
/// `USER_UNAUTHORIZED`, which the caller turns into the same scope failure an
/// anonymous request gets.
fn admin_scopes(db: &mut ProjectDb, user: &utopia_database::Document, project: &Value) -> Option<Vec<String>> {
    let project_id = project.get("$id").and_then(Value::as_str).unwrap_or_default();
    let team_id = project
        .get("teamId")
        .and_then(Value::as_str)
        .unwrap_or_default();
    let roles = confirmed_roles(db, user, team_id);
    if roles.is_empty() {
        return None;
    }

    // PHP seeds `['teams.read', 'projects.read']` so an admin with only
    // project-scoped roles can still list the teams and projects they see.
    let mut scopes: Vec<String> = vec!["teams.read".into(), "projects.read".into()];
    for role in &roles {
        let Some(role) = applicable_role(role, project_id) else {
            continue;
        };
        scopes.extend(scopes_for_role(role).into_iter().map(str::to_string));
    }
    Some(scopes)
}

/// Whether the request asks to impersonate someone, by header or by the
/// query-param fallback the Console uses for direct file URLs.
fn impersonating(ctx: &ActionContext) -> bool {
    const HEADERS: [&str; 3] = [
        "x-appwrite-impersonate-user-id",
        "x-appwrite-impersonate-user-email",
        "x-appwrite-impersonate-user-phone",
    ];
    const PARAMS: [&str; 6] = [
        "impersonateuserid",
        "impersonateUserId",
        "impersonateemail",
        "impersonateEmail",
        "impersonatephone",
        "impersonatePhone",
    ];
    HEADERS
        .iter()
        .any(|header| !ctx.request().header_line(header).is_empty())
        || PARAMS.iter().any(|param| {
            ctx.request()
                .param_ref(param)
                .and_then(Value::as_str)
                .is_some_and(|value| !value.is_empty())
        })
}

/// PHP's `project-<projectId>-<role>` handling: a team-wide role always
/// applies, a project-scoped one only on its own project.
fn applicable_role<'a>(role: &'a str, project_id: &str) -> Option<&'a str> {
    let Some(scoped) = role.strip_prefix("project-") else {
        return Some(role);
    };
    if project_id == CONSOLE || !scoped.starts_with(project_id) {
        return None;
    }
    scoped.rsplit_once('-').map(|(_, role)| role)
}

/// PHP `$store->decode($request->getCookie(...))` with the
/// `x-appwrite-session` header fallback SSR clients use.
fn decode_store(ctx: &ActionContext, project_id: &str, mode: &str) -> Option<Store> {
    let key = if mode == MODE_ADMIN {
        format!("a_session_{CONSOLE}")
    } else {
        format!("a_session_{project_id}")
    };

    let mut store = Store::new();
    let mut cookie = ctx.request().cookie(&key, "");
    if cookie.is_empty() {
        cookie = ctx.request().cookie(&format!("{key}_legacy"), "");
    }
    if !cookie.is_empty() {
        store.decode(&cookie);
    }
    if store.get_property("secret").is_none() {
        let header = ctx.request().header_line("x-appwrite-session");
        if header.is_empty() {
            return None;
        }
        store.decode(&header);
    }
    Some(store)
}

/// PHP `User::sessionVerify()`: the secret must hash to one of the user's
/// unexpired sessions.
fn session_verifies(db: &mut ProjectDb, user: &utopia_database::Document, secret: &str) -> bool {
    let Some(sequence) = user.get_sequence() else {
        return false;
    };
    let Ok(mut proof) = utopia_auth::Token::new(32) else {
        return false;
    };
    proof.set_hasher(Arc::new(utopia_auth::Sha::new()));

    let now = crate::modules::users::base::now_iso();
    db.find(
        "sessions",
        &[
            Query::equal("userInternalId", vec![AttrValue::from(sequence.as_str())]),
            Query::limit(100),
        ],
        "read",
    )
    .unwrap_or_default()
    .iter()
    .any(|session| {
        let stored = session.get_attribute("secret").as_str().unwrap_or_default();
        let expire = session.get_attribute("expire").as_str().unwrap_or_default();
        !stored.is_empty() && proof.verify(secret, stored) && expire >= now.as_str()
    })
}

/// PHP's membership loop: the roles on the user's first confirmed membership
/// for `team_id`.
fn confirmed_roles(
    db: &mut ProjectDb,
    user: &utopia_database::Document,
    team_id: &str,
) -> Vec<String> {
    if team_id.is_empty() {
        return Vec::new();
    }
    let Some(sequence) = user.get_sequence() else {
        return Vec::new();
    };
    db.find(
        "memberships",
        &[
            Query::equal("userInternalId", vec![AttrValue::from(sequence.as_str())]),
            Query::equal("teamId", vec![AttrValue::from(team_id)]),
            Query::limit(100),
        ],
        "read",
    )
    .unwrap_or_default()
    .iter()
    .find(|membership| {
        membership
            .get_attribute("confirm")
            .as_bool()
            .unwrap_or(false)
    })
    .map(|membership| {
        membership
            .get_attribute("roles")
            .to_json()
            .as_array()
            .map(|roles| {
                roles
                    .iter()
                    .filter_map(|role| role.as_str().map(str::to_string))
                    .collect()
            })
            .unwrap_or_default()
    })
    .unwrap_or_default()
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn owner_gets_both_member_and_admin_scopes() {
        let scopes = scopes_for_role("owner");
        assert!(scopes.contains(&"account"));
        assert!(scopes.contains(&"users.write"));
    }

    #[test]
    fn admin_and_developer_share_the_admin_scope_list() {
        assert_eq!(scopes_for_role("admin"), scopes_for_role("developer"));
        assert!(scopes_for_role("admin").contains(&"users.read"));
        assert!(!scopes_for_role("admin").contains(&"account"));
    }

    #[test]
    fn unknown_role_grants_nothing() {
        assert!(scopes_for_role("editor").is_empty());
    }

    #[test]
    fn team_wide_roles_apply_everywhere() {
        assert_eq!(applicable_role("owner", "proj1"), Some("owner"));
        assert_eq!(applicable_role("owner", CONSOLE), Some("owner"));
    }

    #[test]
    fn project_scoped_roles_apply_only_to_their_project() {
        assert_eq!(applicable_role("project-proj1-admin", "proj1"), Some("admin"));
        assert_eq!(applicable_role("project-proj1-admin", "proj2"), None);
        assert_eq!(applicable_role("project-proj1-admin", CONSOLE), None);
    }
}
