//! `POST/GET /v1/teams`, `GET/PUT/DELETE /v1/teams/:teamId`.

use async_trait::async_trait;
use serde_json::{Value as Json, json};
use utopia_database::database::Callback;
use utopia_database::helpers::role::Role;
use utopia_database::{Database, Document, Kind, Query, Value};
use utopia_http::Response;
use utopia_validators::Text;

use appwrite_core::database::documents::{self, Team};
use appwrite_core::database::{LIMIT_COUNT, collections, unique_id};
use appwrite_core::datetime;
use appwrite_core::platform::Context;
use appwrite_core::response::{TeamListModel, TeamModel};
use appwrite_core::validators::{CustomId, roles, uid};
use appwrite_core::{Error, ErrorType, Result};

use crate::base;

/// The lock that serializes the console's single-organization check.
const ORGANIZATIONS_LOCK: &str = "console:organizations:create";

/// `POST /v1/teams`.
pub async fn create(ctx: &mut Context) -> Result<Response> {
    let team_id = ctx.required_str("teamId", &CustomId)?;
    let name = ctx.required_str("name", &Text::new(128))?;
    let roles = ctx.optional("roles", json!(["owner"]), &roles())?;
    let mut roles: Vec<String> =
        roles.as_array().into_iter().flatten().filter_map(Json::as_str).map(str::to_owned).collect();

    let privileged = ctx.is_privileged();
    let app = ctx.is_key();
    let team_id = base::id_or_unique(team_id);
    // Self-hosted allows a single console organization.
    let limited = ctx.project.is_console();
    // Clients join the team they create (keys and privileged users do not).
    let member = if privileged || app { None } else { Some(ctx.user.clone().unwrap_or_default()) };
    if member.is_some() && !roles.iter().any(|r| r == "owner") {
        roles.push("owner".to_owned());
    }
    let db = ctx.db()?;

    let create = async {
        // The seeded total only holds if the owner membership lands with the team.
        db.with_transaction(|| async {
            if limited {
                let organization = db.authorization().skip(|| db.find_one("teams", Vec::new())).await?;
                if !organization.is_empty() {
                    return Ok(None);
                }
            }
            let mut team = Document::default();
            team.set("$id", Value::from(team_id.as_str()));
            team.set(
                "$permissions",
                base::permissions(&[
                    ("read", Role::team(&team_id, "")),
                    ("update", Role::team(&team_id, "owner")),
                    ("delete", Role::team(&team_id, "owner")),
                ]),
            );
            team.set("labels", utopia_database::value::list([]));
            team.set("name", Value::from(name.as_str()));
            team.set("total", Value::Int(if member.is_some() { 1 } else { 0 }));
            team.set("prefs", Value::Object(Default::default()));
            team.set("search", Value::from(format!("{team_id} {name}").as_str()));
            let team = db.authorization().skip(|| db.create_document("teams", team)).await?;

            if let Some(user) = &member {
                let membership_id = unique_id();
                let team_role = |dimension: &str| Role::team(team.id(), dimension);
                let mut membership = Document::default();
                membership.set("$id", Value::from(membership_id.as_str()));
                membership.set(
                    "$permissions",
                    base::permissions(&[
                        ("read", Role::user(&user.id, "")),
                        ("read", team_role("")),
                        ("update", Role::user(&user.id, "")),
                        ("update", team_role("owner")),
                        ("delete", Role::user(&user.id, "")),
                        ("delete", team_role("owner")),
                    ]),
                );
                membership.set("userId", Value::from(user.id.as_str()));
                membership.set("userInternalId", documents::internal_id(user.sequence));
                membership.set("teamId", Value::from(team.id()));
                membership.set("teamInternalId", team.sequence().cloned().unwrap_or(Value::Null));
                membership.set("roles", documents::strings(&roles));
                let now = datetime::value(&datetime::now());
                membership.set("invited", now.clone());
                membership.set("joined", now);
                membership.set("confirm", Value::Bool(true));
                membership.set("secret", Value::from(""));
                membership.set("search", Value::from(format!("{membership_id} {}", user.id).as_str()));
                db.create_document("memberships", membership).await?;
                db.purge_cached_document("users", Some(&user.id)).await?;
            }
            Ok(Some(team))
        })
        .await
    };

    let created = if limited {
        if ctx.user.is_none() {
            return Err(Error::new(ErrorType::UserUnauthorized));
        }
        // Serialize the instance-wide check and commit across accounts.
        // Unlike best-effort request locks, failure must not bypass this limit.
        match ctx.state.lock.with_lock(ORGANIZATIONS_LOCK, 3.0, create).await? {
            Some(created) => created,
            None => return Err(Error::new(ErrorType::GeneralResourceLocked)),
        }
    } else {
        create.await
    };
    let team = match created {
        Ok(Some(team)) => Team::from(&team),
        Ok(None) => return Err(Error::new(ErrorType::OrganizationCreationProhibited)),
        Err(e) if e.is_a(Kind::Duplicate) => return Err(Error::new(ErrorType::TeamAlreadyExists)),
        Err(e) => return Err(e.into()),
    };

    ctx.event_param("teamId", team.id.clone());
    if let Some(user) = ctx.user.as_ref().filter(|u| !u.id.is_empty()) {
        let id = user.id.clone();
        ctx.event_param("userId", id);
    }
    Ok(ctx.created(&TeamModel { team: &team }))
}

/// `GET /v1/teams`.
pub async fn list(ctx: &mut Context) -> Result<Response> {
    let mut queries = ctx.queries(&collections::TEAMS)?;
    let search = ctx.optional_str("search", "", &Text::new(256))?.unwrap_or_default();
    let include_total = ctx.optional_bool("total", true)?;

    if !search.is_empty() {
        queries.push(Query::search("search", &search));
    }
    let db = ctx.db()?;
    base::resolve_cursor(&db, "teams", &mut queries, "Team").await?;
    let filters = Query::group_by_type(&queries).filters;
    let teams: Vec<Team> = documents::all(&db.find("teams", queries, Database::PERMISSION_READ).await?);
    let total = if include_total { db.count("teams", filters, Some(LIMIT_COUNT)).await? } else { 0 };
    Ok(ctx.ok(&TeamListModel { total, teams: &teams }))
}

/// `GET /v1/teams/:teamId`.
pub async fn get(ctx: &mut Context) -> Result<Response> {
    let team_id = ctx.required_str("teamId", &uid())?;
    let db = ctx.db()?;
    let (_, team) = base::team_or_404(&db, &team_id).await?;
    Ok(ctx.ok(&TeamModel { team: &team }))
}

/// `PUT /v1/teams/:teamId`.
pub async fn name(ctx: &mut Context) -> Result<Response> {
    let team_id = ctx.required_str("teamId", &uid())?;
    let name = ctx.required_str("name", &Text::new(128))?;
    let db = ctx.db()?;
    let (document, _) = base::team_or_404(&db, &team_id).await?;
    let mut updates = Document::default();
    updates.set("name", Value::from(name.as_str()));
    updates.set("search", Value::from(format!("{team_id} {name}").as_str()));
    let team = Team::from(&db.update_document("teams", document.id(), updates).await?);
    ctx.event_param("teamId", team.id.clone());
    Ok(ctx.ok(&TeamModel { team: &team }))
}

/// `DELETE /v1/teams/:teamId`.
pub async fn delete(ctx: &mut Context) -> Result<Response> {
    let team_id = ctx.required_str("teamId", &uid())?;
    let db = ctx.db()?;
    let (document, team) = base::team_or_404(&db, &team_id).await?;
    if !db.delete_document("teams", &team_id).await? {
        return Err(Error::with_message(ErrorType::GeneralServerError, "Failed to remove team from DB"));
    }

    // Sync delete (`Deletes::deleteMemberships`): failures are logged.
    let deleted = db
        .delete_documents(
            "memberships",
            vec![Query::equal("teamInternalId", [documents::internal_id(team.sequence)]), Query::order_asc("")],
            Database::DELETE_BATCH_SIZE,
            Some(&mut PurgeMember { db: &db }),
            None,
        )
        .await;
    if let Err(e) = deleted {
        tracing::error!(error = %e, "Failed to delete documents for collection memberships");
    }

    // Async delete.
    let project = ctx.project.message_payload();
    let copy = document.clone();
    let team_document = Value::from(copy).try_to_json().unwrap_or(Json::Null);
    if ctx.project.is_console() {
        let message = appwrite_core::event::delete_message(&project, "teams_projects", team_document.clone());
        ctx.events.deletes.push(message);
    }
    let message = appwrite_core::event::delete_message(&project, "document", team_document);
    ctx.events.deletes.push(message);

    ctx.event_param("teamId", team.id.clone());
    ctx.event_payload(&TeamModel { team: &team });
    Ok(ctx.no_content())
}

/// Purges each deleted membership's user from the cache.
struct PurgeMember<'a> {
    db: &'a Database,
}

#[async_trait]
impl Callback<(Document, Document)> for PurgeMember<'_> {
    async fn call(&mut self, (membership, _): (Document, Document)) -> utopia_database::Result<()> {
        let user_id = membership.get("userId").map(utopia_database::value::to_text).unwrap_or_default();
        self.db.purge_cached_document("users", Some(&user_id)).await?;
        Ok(())
    }
}
