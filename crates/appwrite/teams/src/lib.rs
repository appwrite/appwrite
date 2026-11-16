//! The Teams service (`src/Appwrite/Platform/Modules/Teams`).
//!
//! Every route keeps the PHP contract: path, parameters and validators (in
//! declaration order), scopes, events, audits, labels, status codes and
//! response models.

mod base;
mod http;

use appwrite_core::action;
use appwrite_core::platform::{Audit, Labels, Limit, Module, Route};
use utopia_http::Method;

/// The Teams module.
pub struct Teams;

const GROUPS: &[&str] = &["api", "teams"];
const READ: &[&str] = &["teams.read"];
const WRITE: &[&str] = &["teams.write"];

const fn audit(event: &'static str, resource: &'static str) -> Option<Audit> {
    Some(Audit { event, resource })
}

/// `createMembership`: `groups(['api', 'teams', 'auth'])`, `auth.type`
/// `invites`, 10 attempts per `url` and `ip`.
const INVITES: Labels =
    Labels { abuse: &[Limit::attempts(&["url", "ip"], 10, 3600)], auth_type: "invites", ..Labels::NONE };

/// `updateMembershipStatus`: 10 attempts per `ip` and `url`.
const STATUS: Labels = Labels { abuse: &[Limit::attempts(&["ip", "url"], 10, 3600)], ..Labels::NONE };

macro_rules! route {
    ($method:ident, $path:literal, $name:literal, $sdk:literal, $scopes:expr, $event:expr, $audit:expr, $action:path) => {
        route!($method, $path, $name, $sdk, $scopes, $event, $audit, GROUPS, Labels::NONE, $action)
    };
    (
        $method:ident,
        $path:literal,
        $name:literal,
        $sdk:literal,
        $scopes:expr,
        $event:expr,
        $audit:expr,
        $groups:expr,
        $labels:expr,
        $action:path
    ) => {
        Route {
            method: Method::$method,
            path: $path,
            name: $name,
            namespace: "teams",
            sdk_method: $sdk,
            scopes: $scopes,
            event: $event,
            audit: $audit,
            groups: $groups,
            labels: $labels,
            action: action!($action),
        }
    };
}

impl Module for Teams {
    fn name(&self) -> &'static str {
        "teams"
    }

    fn routes(&self) -> Vec<Route> {
        use http::*;
        vec![
            // Teams
            route!(
                POST,
                "/v1/teams",
                "createTeam",
                "create",
                WRITE,
                Some("teams.[teamId].create"),
                audit("team.create", "team/{response.$id}"),
                teams::create
            ),
            route!(GET, "/v1/teams", "listTeams", "list", READ, None, None, teams::list),
            route!(GET, "/v1/teams/:teamId", "getTeam", "get", READ, None, None, teams::get),
            route!(
                PUT,
                "/v1/teams/:teamId",
                "updateTeamName",
                "updateName",
                WRITE,
                Some("teams.[teamId].update"),
                audit("team.update", "team/{response.$id}"),
                teams::name
            ),
            route!(
                DELETE,
                "/v1/teams/:teamId",
                "deleteTeam",
                "delete",
                WRITE,
                Some("teams.[teamId].delete"),
                audit("team.delete", "team/{request.teamId}"),
                teams::delete
            ),
            // Preferences
            route!(GET, "/v1/teams/:teamId/prefs", "getTeamPreferences", "getPrefs", READ, None, None, prefs::get),
            route!(
                PUT,
                "/v1/teams/:teamId/prefs",
                "updateTeamPreferences",
                "updatePrefs",
                WRITE,
                Some("teams.[teamId].update.prefs"),
                audit("team.update", "team/{response.$id}"),
                prefs::update
            ),
            // Memberships
            route!(
                POST,
                "/v1/teams/:teamId/memberships",
                "createTeamMembership",
                "createMembership",
                WRITE,
                Some("teams.[teamId].memberships.[membershipId].create"),
                audit("membership.create", "team/{request.teamId}"),
                &["api", "teams", "auth"],
                INVITES,
                memberships::create
            ),
            route!(
                GET,
                "/v1/teams/:teamId/memberships",
                "listTeamMemberships",
                "listMemberships",
                READ,
                None,
                None,
                memberships::list
            ),
            route!(
                GET,
                "/v1/teams/:teamId/memberships/:membershipId",
                "getTeamMembership",
                "getMembership",
                READ,
                None,
                None,
                memberships::get
            ),
            route!(
                PATCH,
                "/v1/teams/:teamId/memberships/:membershipId",
                "updateTeamMembership",
                "updateMembership",
                WRITE,
                Some("teams.[teamId].memberships.[membershipId].update"),
                audit("membership.update", "team/{request.teamId}"),
                memberships::update
            ),
            route!(
                DELETE,
                "/v1/teams/:teamId/memberships/:membershipId",
                "deleteTeamMembership",
                "deleteMembership",
                WRITE,
                Some("teams.[teamId].memberships.[membershipId].delete"),
                audit("membership.delete", "team/{request.teamId}"),
                memberships::delete
            ),
            route!(
                PATCH,
                "/v1/teams/:teamId/memberships/:membershipId/status",
                "updateTeamMembershipStatus",
                "updateMembershipStatus",
                &["public"],
                Some("teams.[teamId].memberships.[membershipId].update.status"),
                audit("membership.update", "team/{request.teamId}"),
                GROUPS,
                STATUS,
                status::update
            ),
        ]
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn routes_keep_the_php_labels() {
        let routes = Teams.routes();
        assert_eq!(routes.len(), 13);
        let invite = routes.iter().find(|r| r.name == "createTeamMembership").unwrap();
        assert!(invite.has_group("auth"));
        assert_eq!(invite.labels.auth_type, "invites");
        assert_eq!(invite.labels.abuse, &[Limit::attempts(&["url", "ip"], 10, 3600)]);
        let status = routes.iter().find(|r| r.name == "updateTeamMembershipStatus").unwrap();
        assert_eq!(status.scopes, &["public"]);
        assert_eq!(status.labels.abuse, &[Limit::attempts(&["ip", "url"], 10, 3600)]);
        assert!(!status.has_group("auth"));
    }
}
