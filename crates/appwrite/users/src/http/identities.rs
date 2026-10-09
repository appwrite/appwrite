//! OAuth identities.

use utopia_database::{FindOptions, QueryGroups};
use utopia_http::Response;
use utopia_validators::Text;

use appwrite_core::database::collections::{self, allowed};
use appwrite_core::database::documents::Identity;
use appwrite_core::platform::Context;
use appwrite_core::response::{IdentityListModel, IdentityModel};
use appwrite_core::validators::uid;
use appwrite_core::{Error, ErrorType, Result};

/// `GET /v1/users/identities`.
pub async fn list(ctx: &mut Context) -> Result<Response> {
    let queries = ctx.queries(&collections::IDENTITIES, allowed::IDENTITIES)?;
    let search = ctx.optional_str("search", "", &Text::new(256))?.unwrap_or_default();
    let include_total = ctx.optional_bool("total", true)?;
    if !search.is_empty() {
        // The identities collection has no `search` attribute.
        return Err(Error::with_message(
            ErrorType::GeneralQueryInvalid,
            "Invalid query: Attribute not found in schema: search",
        ));
    }
    let groups = QueryGroups::from_queries(&queries);
    let db = ctx.db()?;
    let identities = match db.find::<Identity>(&collections::IDENTITIES, FindOptions::from_groups(&groups, None)).await
    {
        Ok(i) => i,
        Err(utopia_database::Error::NotFound(m)) if m.starts_with("cursor:") => {
            let id = groups.cursor.as_ref().map(|c| c.id.clone()).unwrap_or_default();
            return Err(Error::with_message(
                ErrorType::GeneralCursorNotFound,
                format!("User '{id}' for the 'cursor' value not found."),
            ));
        }
        Err(e) => return Err(e.into()),
    };
    let total =
        if include_total { db.count(&collections::IDENTITIES, &groups.filters, Some(5000), None).await? } else { 0 };
    Ok(ctx.ok(&IdentityListModel { total, identities: &identities }))
}

/// `DELETE /v1/users/identities/:identityId`.
pub async fn delete(ctx: &mut Context) -> Result<Response> {
    let identity_id = ctx.required_str("identityId", &uid())?;
    let db = ctx.db()?;
    let Some(identity) = db.get::<Identity>("identities", &identity_id).await? else {
        return Err(Error::new(ErrorType::UserIdentityNotFound));
    };
    db.delete("identities", &identity.id).await?;
    ctx.event_param("userId", identity.user_id.clone().unwrap_or_default());
    ctx.event_param("identityId", identity.id.clone());
    ctx.event_payload(&IdentityModel { identity: &identity });
    Ok(ctx.no_content())
}
