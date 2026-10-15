//! OAuth identities.

use utopia_database::PermissionType;
use utopia_database::Query;
use utopia_http::Response;
use utopia_validators::Text;

use appwrite_core::database::documents::{self, Identity};
use appwrite_core::database::{LIMIT_COUNT, collections};
use appwrite_core::platform::Context;
use appwrite_core::response::{IdentityListModel, IdentityModel};
use appwrite_core::validators::uid;
use appwrite_core::{Error, ErrorType, Result};

use crate::base;

/// `GET /v1/users/identities`.
pub async fn list(ctx: &mut Context) -> Result<Response> {
    let mut queries = ctx.queries(&collections::IDENTITIES)?;
    let search = ctx.optional_str("search", "", &Text::new(256))?.unwrap_or_default();
    let include_total = ctx.optional_bool("total", true)?;
    if !search.is_empty() {
        queries.push(Query::search("search", &search));
    }
    let db = ctx.db()?;
    base::resolve_cursor(&db, "identities", &mut queries, "User").await?;
    let identities = db.find("identities", queries.clone(), PermissionType::Read).await?;
    let total = if include_total { db.count("identities", queries, Some(LIMIT_COUNT)).await? } else { 0 };
    let identities: Vec<Identity> = documents::all(&identities);
    Ok(ctx.ok(&IdentityListModel { total, identities: &identities }))
}

/// `DELETE /v1/users/identities/:identityId`.
pub async fn delete(ctx: &mut Context) -> Result<Response> {
    let identity_id = ctx.required_str("identityId", &uid())?;
    let db = ctx.db()?;
    let Some(identity) = documents::get::<Identity>(&db, "identities", &identity_id).await? else {
        return Err(Error::new(ErrorType::UserIdentityNotFound));
    };
    db.delete_document("identities", &identity_id).await?;
    ctx.event_param("userId", identity.user_id.clone().unwrap_or_default());
    ctx.event_param("identityId", identity.id.clone());
    ctx.event_payload(&IdentityModel { identity: &identity });
    Ok(ctx.no_content())
}
