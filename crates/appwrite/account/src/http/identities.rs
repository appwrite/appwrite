//! OAuth identities of the account: `/v1/account/identities`.

use utopia_database::query::Method;
use utopia_database::validator::query::cursor::Cursor as CursorValidator;
use utopia_database::value::to_text;
use utopia_database::{Database, Query, Value};
use utopia_http::Response;

use appwrite_core::database::documents::{self, Identity};
use appwrite_core::database::{LIMIT_COUNT, collections};
use appwrite_core::platform::{Context, Route};
use appwrite_core::response::{IdentityListModel, IdentityModel};
use appwrite_core::validators::uid;
use appwrite_core::{Error, ErrorType, Result};

use crate::{ACCOUNT, audit};

pub fn routes() -> Vec<Route> {
    vec![
        route!(GET, "/v1/account/identities", "listIdentities", "listIdentities", ACCOUNT, None, None, list),
        route!(
            DELETE,
            "/v1/account/identities/:identityId",
            "deleteIdentity",
            "deleteIdentity",
            ACCOUNT,
            Some("users.[userId].identities.[identityId].delete"),
            audit("identity.delete", "identity/{request.$identityId}"),
            delete
        ),
    ]
}

/// `GET /v1/account/identities`.
pub async fn list(ctx: &mut Context) -> Result<Response> {
    let mut queries = ctx.queries(&collections::IDENTITIES)?;
    let include_total = ctx.optional_bool("total", true)?;
    let sequence = ctx.roles_user().map(|u| u.sequence).unwrap_or(0);
    queries.push(Query::equal("userInternalId", [documents::internal_id(sequence)]));

    let db = ctx.db()?;
    let cursor = queries.iter().position(|q| matches!(q.method(), Method::CursorAfter | Method::CursorBefore));
    if let Some(i) = cursor {
        let validator = CursorValidator::default();
        if !validator.check(&queries[i]) {
            return Err(Error::with_message(ErrorType::GeneralQueryInvalid, validator.description()));
        }
        let id = queries[i].value().map(to_text).unwrap_or_default();
        let document = db.get_document("identities", &id, Vec::new(), false).await?;
        if document.is_empty() {
            return Err(Error::with_message(
                ErrorType::GeneralCursorNotFound,
                format!("Identity '{id}' for the 'cursor' value not found."),
            ));
        }
        queries[i].set_value(Value::from(document));
    }

    let filters = Query::group_by_type(&queries).filters;
    let identities = db.find("identities", queries, Database::PERMISSION_READ).await?;
    let total = if include_total { db.count("identities", filters, Some(LIMIT_COUNT)).await? } else { 0 };
    let identities: Vec<Identity> = documents::all(&identities);
    Ok(ctx.ok(&IdentityListModel { total, identities: &identities }))
}

/// `DELETE /v1/account/identities/:identityId`.
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
