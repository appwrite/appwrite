//! Passkeys (authenticators of type `passkey`).

use serde_json::json;
use utopia_database::{FindOptions, FromRow, Param, Query, QueryGroups};
use utopia_http::Response;
use utopia_validators::Text;

use appwrite_core::database::collections::{self, allowed};
use appwrite_core::database::documents::{Authenticator, Relations, User};
use appwrite_core::platform::Context;
use appwrite_core::response::{PasskeyListModel, PasskeyModel};
use appwrite_core::validators::uid;
use appwrite_core::{Error, ErrorType, Result};

use crate::base;

const PASSKEY: &str = "passkey";

async fn owned(ctx: &mut Context, user: &User, id: &str, require_verified: bool) -> Result<Authenticator> {
    let db = ctx.db()?;
    match db.get::<Authenticator>("authenticators", id).await? {
        Some(a)
            if a.kind.as_deref() == Some(PASSKEY)
                && a.user_internal_id == user.sequence.to_string()
                && (!require_verified || a.verified == Some(true)) =>
        {
            Ok(a)
        }
        _ => Err(Error::new(ErrorType::UserPasskeyNotFound)),
    }
}

/// `GET /v1/users/:userId/passkeys`.
pub async fn list(ctx: &mut Context) -> Result<Response> {
    let user_id = ctx.required_str("userId", &uid())?;
    let mut queries = ctx.queries(&collections::AUTHENTICATORS, allowed::PASSKEYS)?;
    let include_total = ctx.optional_bool("total", true)?;
    let user = base::user_or_404(ctx, &user_id, Relations::NONE).await?;
    queries.push(Query::equal("userInternalId", vec![json!(user.sequence.to_string())]));
    queries.push(Query::equal("type", vec![json!(PASSKEY)]));
    queries.push(Query::equal("verified", vec![json!(true)]));
    let groups = QueryGroups::from_queries(&queries);
    let db = ctx.db()?;
    if let Some(cursor) = &groups.cursor {
        let found = db.get::<Authenticator>("authenticators", &cursor.id).await?;
        let ok = matches!(&found, Some(a) if a.kind.as_deref() == Some(PASSKEY) && a.user_internal_id == user.sequence.to_string());
        if !ok {
            return Err(Error::with_message(
                ErrorType::GeneralCursorNotFound,
                format!("Passkey '{}' for the 'cursor' value not found.", cursor.id),
            ));
        }
    }
    let passkeys =
        db.find::<Authenticator>(&collections::AUTHENTICATORS, FindOptions::from_groups(&groups, None)).await?;
    let total = if include_total {
        db.count(&collections::AUTHENTICATORS, &groups.filters, Some(5000), None).await?
    } else {
        0
    };
    Ok(ctx.ok(&PasskeyListModel { total, passkeys: &passkeys }))
}

/// `GET /v1/users/:userId/passkeys/:passkeyId`.
pub async fn get(ctx: &mut Context) -> Result<Response> {
    let user_id = ctx.required_str("userId", &uid())?;
    let passkey_id = ctx.required_str("passkeyId", &uid())?;
    let user = base::user_or_404(ctx, &user_id, Relations::NONE).await?;
    let passkey = owned(ctx, &user, &passkey_id, true).await?;
    Ok(ctx.ok(&PasskeyModel { passkey: &passkey }))
}

/// `PATCH /v1/users/:userId/passkeys/:passkeyId`.
pub async fn update(ctx: &mut Context) -> Result<Response> {
    let user_id = ctx.required_str("userId", &uid())?;
    let passkey_id = ctx.required_str("passkeyId", &uid())?;
    let name = ctx.required_str("name", &Text::new(128))?;
    let user = base::user_or_404(ctx, &user_id, Relations::NONE).await?;
    let passkey = owned(ctx, &user, &passkey_id, true).await?;
    let db = ctx.db()?;
    let row = db
        .update("authenticators", &passkey.id, vec![("name", Param::Text(name))], Authenticator::COLUMNS)
        .await?
        .ok_or_else(|| Error::new(ErrorType::UserPasskeyNotFound))?;
    let updated = Authenticator::from_row(&row)?;
    db.purge_cached_document("users", &user.id).await;
    Ok(ctx.ok(&PasskeyModel { passkey: &updated }))
}

/// `DELETE /v1/users/:userId/passkeys/:passkeyId`.
pub async fn delete(ctx: &mut Context) -> Result<Response> {
    let user_id = ctx.required_str("userId", &uid())?;
    let passkey_id = ctx.required_str("passkeyId", &uid())?;
    let user = base::user_or_404(ctx, &user_id, Relations::NONE).await?;
    let passkey = owned(ctx, &user, &passkey_id, false).await?;
    let db = ctx.db()?;
    db.delete("authenticators", &passkey.id).await?;
    db.purge_cached_document("users", &user.id).await;
    Ok(ctx.no_content())
}
