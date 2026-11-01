//! The `team` resource (PHP: `app/init/resources/request.php`,
//! `$context->set('team'`; hooks.json `resource-team`).

use serde_json::Value;
use utopia_database::{Database, Query};
use utopia_http::Request;

use crate::Result;
use crate::database::Project;
use crate::platform::Route;
use crate::platform::context::Context;

/// The `team` resource: the organization that owns the project, or on the
/// console the one the route acts on: the project of a
/// `/v1/projects/:projectId` route, the `teamId` param of `/v1/projects`,
/// or `X-Appwrite-Organization` on an `organization` group route. Empty
/// when there is none. Authorization is skipped.
pub(in crate::platform) async fn team(
    platform_db: &Database,
    project: &Project,
    route: &Route,
    request: &Request,
    path_params: &[(String, Value)],
) -> Result<utopia_database::Document> {
    let authorization = platform_db.authorization();
    let text = |document: &utopia_database::Document, key: &str| {
        document.get(key).map(utopia_database::value::to_text).unwrap_or_default()
    };
    let skipping = &authorization;
    let get = |collection: &'static str, id: String| async move {
        if id.is_empty() {
            return Ok(utopia_database::Document::default());
        }
        skipping.skip(platform_db.get_document(collection, &id, Vec::new(), false)).await
    };
    let team_internal_id = if !project.is_console() {
        project.team_internal_id.clone()
    } else {
        // The matched route's path; an unmatched request's URI.
        let path = if route.name == "wildcard" { request.uri() } else { route.path };
        let organization = request.header_or_empty("x-appwrite-organization");
        if path.starts_with("/v1/projects/:projectId") {
            text(&get("projects", project_id_from_path(request, path_params)).await?, "teamInternalId")
        } else if path == "/v1/projects" {
            let team_id = request.params().get("teamId").and_then(Value::as_str).unwrap_or_default().to_owned();
            return Ok(get("teams", team_id).await?);
        } else if route.has_group("organization") && !organization.is_empty() {
            return Ok(get("teams", organization.to_owned()).await?);
        } else {
            String::new()
        }
    };
    if team_internal_id.is_empty() {
        return Ok(utopia_database::Document::default());
    }
    Ok(authorization
        .skip(platform_db.find_one("teams", vec![Query::equal("$sequence", [team_internal_id.as_str()])]))
        .await?)
}

/// The `projectIdFromPath` resource: the `projectId` route param of a
/// `/v1/projects/...` URI, else empty.
fn project_id_from_path(request: &Request, path_params: &[(String, Value)]) -> String {
    let mut segments = request.uri().split('/').filter(|s| !s.is_empty());
    if segments.next() != Some("v1") || segments.next() != Some("projects") {
        return String::new();
    }
    path_params
        .iter()
        .find(|(name, _)| name == "projectId")
        .and_then(|(_, v)| v.as_str())
        .unwrap_or_default()
        .to_owned()
}

/// The `$id` and `$sequence` of the `team` resource ([`team`]), `None` when
/// it is empty.
pub(in crate::platform) async fn load_team(platform_db: &Database, ctx: &Context) -> Result<Option<(String, String)>> {
    let team = team(platform_db, &ctx.project, &ctx.route, &ctx.request, &ctx.path_params).await?;
    if team.is_empty() {
        return Ok(None);
    }
    let sequence = team.sequence().map(utopia_database::value::to_text).unwrap_or_default();
    Ok(Some((team.id().to_owned(), sequence)))
}

#[cfg(test)]
mod team_tests {
    use std::sync::Arc;

    use bytes::Bytes;
    use utopia_database::{Attribute, Collection, Document, Value};

    use super::*;

    /// A platform database with an organization `t1` and its project `p1`.
    async fn platform() -> (Database, String) {
        let cache = Arc::new(utopia_cache::Cache::from_dyn(Arc::new(utopia_cache::Memory::new())));
        let db = Database::new(Arc::new(utopia_database::adapter::memory::Memory::new()), cache);
        db.set_authorization(crate::database::Databases::unchecked());
        db.set_database("appwrite").set_namespace("_console");
        db.create(None).await.unwrap();
        for (id, attributes) in [
            ("teams", vec![Attribute::string("name", 128)]),
            ("projects", vec![Attribute::string("teamInternalId", 64)]),
        ] {
            let collection = Collection::create(id, id, attributes, vec![], None, false, Default::default()).unwrap();
            db.create_collection(collection).await.unwrap();
        }
        let mut team = Document::default();
        team.put("$id", Value::from("t1"));
        team.put("name", Value::from("Org"));
        let team = db.create_document("teams", team).await.unwrap();
        let sequence = team.sequence().map(utopia_database::value::to_text).unwrap_or_default();
        let mut project = Document::default();
        project.put("$id", Value::from("p1"));
        project.put("teamInternalId", Value::from(sequence.as_str()));
        db.create_document("projects", project).await.unwrap();
        (db, sequence)
    }

    fn route(path: &'static str, groups: &'static [&'static str]) -> Route {
        Route { path, name: "route", groups, ..crate::platform::hooks::wildcard::wildcard_route() }
    }

    async fn team_of(
        db: &Database,
        project: &Project,
        route: &Route,
        target: &str,
        headers: &[(&str, &str)],
        path_params: &[(&str, &str)],
    ) -> String {
        let request = Request::new("GET", target, headers.iter().copied(), Bytes::new());
        let params: Vec<(String, serde_json::Value)> =
            path_params.iter().map(|(k, v)| (k.to_string(), serde_json::Value::String(v.to_string()))).collect();
        team(db, project, route, &request, &params).await.unwrap().id().to_owned()
    }

    /// PHP's `team` resource: a project's own organization; on the console,
    /// the organization a projects route or an `organization` route names.
    #[utopia_async::test]
    async fn teams_follow_php() {
        let (db, sequence) = platform().await;
        let users = route("/v1/users", &["api", "users"]);
        let mut project = Project::empty();
        project.id = "p1".into();
        project.team_internal_id = sequence;
        assert_eq!(team_of(&db, &project, &users, "/v1/users", &[], &[]).await, "t1");
        project.team_internal_id = String::new();
        assert_eq!(team_of(&db, &project, &users, "/v1/users", &[], &[]).await, "");

        let mut console = Project::empty();
        console.id = Project::CONSOLE.into();
        let organization = [("x-appwrite-organization", "t1")];
        // Console routes name no organization...
        assert_eq!(team_of(&db, &console, &users, "/v1/users", &organization, &[]).await, "");
        // ...unless they act on a project,
        let project_route = route("/v1/projects/:projectId", &["api", "projects"]);
        let path = [("projectId", "p1")];
        assert_eq!(team_of(&db, &console, &project_route, "/v1/projects/p1", &[], &path).await, "t1");
        let missing = [("projectId", "missing")];
        assert_eq!(team_of(&db, &console, &project_route, "/v1/projects/missing", &[], &missing).await, "");
        // list the projects of a `teamId`,
        let projects = route("/v1/projects", &["api", "projects"]);
        assert_eq!(team_of(&db, &console, &projects, "/v1/projects?teamId=t1", &[], &[]).await, "t1");
        assert_eq!(team_of(&db, &console, &projects, "/v1/projects", &organization, &[]).await, "");
        // or are in the `organization` group, with the header.
        let keys = route("/v1/organization/keys", &["api", "organization"]);
        assert_eq!(team_of(&db, &console, &keys, "/v1/organization/keys", &organization, &[]).await, "t1");
        assert_eq!(team_of(&db, &console, &keys, "/v1/organization/keys", &[], &[]).await, "");
    }
}
