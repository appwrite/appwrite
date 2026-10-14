//! Team memberships of a user.

use utopia_database::{Database, Query};
use utopia_http::Response;
use utopia_validators::Text;

use appwrite_core::Result;
use appwrite_core::database::collections;
use appwrite_core::database::documents::{self, Attributes, Membership};
use appwrite_core::platform::Context;
use appwrite_core::response::{MembershipListModel, MembershipModel};
use appwrite_core::validators::uid;

use crate::base;

/// `GET /v1/users/:userId/memberships`.
pub async fn list(ctx: &mut Context) -> Result<Response> {
    let user_id = ctx.required_str("userId", &uid())?;
    let mut queries = ctx.queries(&collections::MEMBERSHIPS)?;
    let search = ctx.optional_str("search", "", &Text::new(256))?.unwrap_or_default();
    let include_total = ctx.optional_bool("total", true)?;
    let user = base::user_or_404(ctx, &user_id, &[]).await?;
    if !search.is_empty() {
        queries.push(Query::search("search", &search));
    }
    queries.push(Query::equal("userInternalId", [documents::internal_id(user.sequence)]));
    let db = ctx.db()?;
    let memberships: Vec<Membership> =
        documents::all(&db.find("memberships", queries, Database::PERMISSION_READ).await?);

    // `getDocument('teams', $teamId)` for each membership.
    let mut team_names: Vec<String> = Vec::with_capacity(memberships.len());
    for membership in &memberships {
        let team = db.get_document("teams", &membership.team_id, Vec::new(), false).await?;
        team_names.push(team.string_or_empty("name"));
    }
    let user_name = user.name.clone().unwrap_or_default();
    let user_email = user.email.clone().unwrap_or_default();
    let render = ctx.render();
    let models: Vec<MembershipModel<'_>> = memberships
        .iter()
        .zip(team_names.iter())
        .map(|(m, team_name)| MembershipModel::new(m, &user_name, &user_email, team_name, render))
        .collect();
    let total = if include_total { models.len() as i64 } else { 0 };
    Ok(ctx.ok(&MembershipListModel { total, memberships: models }))
}
