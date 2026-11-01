//! Roles and their scopes (`app/config/roles.php`).

pub const GUESTS: &str = "guests";
pub const USERS: &str = "users";
pub const ADMIN: &str = "admin";
pub const DEVELOPER: &str = "developer";
pub const OWNER: &str = "owner";
pub const KEYS: &str = "keys";

const GUEST_SCOPES: &[&str] = &[
    "global",
    "public",
    "home",
    "console",
    "graphql",
    "sessions.write",
    "documents.read",
    "documents.write",
    "documentsdb.documents.read",
    "documentsdb.documents.write",
    "vectorsdb.documents.read",
    "vectorsdb.documents.write",
    "rows.read",
    "rows.write",
    "embeddings.write",
    "files.read",
    "files.write",
    "locale.read",
    "avatars.read",
    "executions.write",
];

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
    "documentsdb.documents.read",
    "documentsdb.documents.write",
    "vectorsdb.documents.read",
    "vectorsdb.documents.write",
    "rows.read",
    "rows.write",
    "embeddings.write",
    "files.read",
    "files.write",
    "projects.read",
    "locale.read",
    "avatars.read",
    "avatars.write",
    "executions.read",
    "executions.write",
    "targets.read",
    "targets.write",
    "subscribers.write",
    "subscribers.read",
    "rules.read",
];

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
    "documentsdb.read",
    "documentsdb.write",
    "documentsdb.collections.read",
    "documentsdb.collections.write",
    "documentsdb.documents.read",
    "documentsdb.documents.write",
    "documentsdb.indexes.read",
    "documentsdb.indexes.write",
    "vectorsdb.read",
    "vectorsdb.write",
    "vectorsdb.collections.read",
    "vectorsdb.collections.write",
    "vectorsdb.documents.read",
    "vectorsdb.documents.write",
    "vectorsdb.indexes.read",
    "vectorsdb.indexes.write",
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
    "organization.projects.keys.read",
    "organization.projects.keys.write",
    "webhooks.read",
    "webhooks.write",
    "project.read",
    "project.write",
    "usage.read",
    "locale.read",
    "avatars.read",
    "avatars.write",
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

const KEY_SCOPES: &[&str] = &["global", "health.read", "graphql"];

/// Scopes granted by a role.
pub fn scopes(role: &str) -> Vec<&'static str> {
    match role {
        GUESTS => GUEST_SCOPES.to_vec(),
        USERS => MEMBER_SCOPES.to_vec(),
        ADMIN | DEVELOPER => ADMIN_SCOPES.to_vec(),
        OWNER => MEMBER_SCOPES.iter().chain(ADMIN_SCOPES.iter()).copied().collect(),
        KEYS => KEY_SCOPES.to_vec(),
        _ => Vec::new(),
    }
}

/// Default scopes added to every project key.
pub fn key_scopes() -> &'static [&'static str] {
    KEY_SCOPES
}

/// Role label (lower-cased in scope errors).
pub fn label(role: &str) -> Option<&'static str> {
    Some(match role {
        GUESTS => "Guests",
        USERS => "Users",
        ADMIN => "Admin",
        DEVELOPER => "Developer",
        OWNER => "Owner",
        KEYS => "Applications",
        _ => return None,
    })
}

/// `User::isPrivileged`.
pub fn is_privileged(roles: &[String]) -> bool {
    roles.iter().any(|r| r == OWNER || r == DEVELOPER || r == ADMIN)
}

/// `User::isKey`.
pub fn is_key(roles: &[String]) -> bool {
    roles.iter().any(|r| r == KEYS)
}
