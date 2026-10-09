//! Team memberships of a user.

use serde_json::json;
use utopia_database::sql::Builder;
use utopia_database::{FindOptions, Param, Query, QueryGroups};
use utopia_http::Response;
use utopia_validators::Text;

use appwrite_core::Result;
use appwrite_core::database::collections::{self, allowed};
use appwrite_core::database::documents::{Membership, Relations};
use appwrite_core::platform::Context;
use appwrite_core::response::{MembershipListModel, MembershipModel};
use appwrite_core::validators::uid;

use crate::base;

/// `GET /v1/users/:userId/memberships`.
pub async fn list(ctx: &mut Context) -> Result<Response> {
    let user_id = ctx.required_str("userId", &uid())?;
    let mut queries = ctx.queries(&collections::MEMBERSHIPS, allowed::MEMBERSHIPS)?;
    let search = ctx.optional_str("search", "", &Text::new(256))?.unwrap_or_default();
    let include_total = ctx.optional_bool("total", true)?;
    let user = base::user_or_404(ctx, &user_id, Relations::NONE).await?;
    if !search.is_empty() {
        queries.push(Query::search("search", &search));
    }
    queries.push(Query::equal("userInternalId", vec![json!(user.sequence.to_string())]));
    let groups = QueryGroups::from_queries(&queries);
    let db = ctx.db()?;
    let memberships = db.find::<Membership>(&collections::MEMBERSHIPS, FindOptions::from_groups(&groups, None)).await?;

    // Team names in one query.
    let mut team_names: Vec<(String, String)> = Vec::new();
    if !memberships.is_empty() {
        let ids: Vec<String> = memberships.iter().map(|m| m.team_id.clone()).collect();
        let mut b = Builder::new();
        let p = b.bind(Param::TextArray(ids));
        let tenant = db.tenant_condition(&mut b, "main");
        let sql = format!(
            "SELECT \"_uid\", \"name\" FROM {} AS \"main\" WHERE \"main\".\"_uid\" = ANY({p}){tenant}",
            db.table("teams")
        );
        for row in db.query(&sql, &b).await? {
            let id: String = row.try_get(0).unwrap_or_default();
            let name: Option<String> = row.try_get(1).unwrap_or(None);
            team_names.push((id, name.unwrap_or_default()));
        }
    }
    let user_name = user.name.clone().unwrap_or_default();
    let user_email = user.email.clone().unwrap_or_default();
    let render = ctx.render();
    let models: Vec<MembershipModel<'_>> = memberships
        .iter()
        .map(|m| MembershipModel {
            membership: m,
            user_name: &user_name,
            user_email: &user_email,
            team_name: team_names.iter().find(|(id, _)| *id == m.team_id).map(|(_, n)| n.as_str()).unwrap_or(""),
            render,
        })
        .collect();
    let total = if include_total { models.len() as i64 } else { 0 };
    Ok(ctx.ok(&MembershipListModel { total, memberships: models }))
}
