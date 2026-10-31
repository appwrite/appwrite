//! Passkeys (authenticators of type `passkey`).

use utopia_database::PermissionType;
use utopia_database::query::ParsedQuery;
use utopia_database::{Document, Query, Value};
use utopia_http::Response;
use utopia_validators::Text;

use appwrite_core::database::documents::{self, Authenticator, User};
use appwrite_core::database::{LIMIT_COUNT, collections};
use appwrite_core::platform::Context;
use appwrite_core::response::{PasskeyListModel, PasskeyModel};
use appwrite_core::validators::uid;
use appwrite_core::{Error, ErrorType, Result};

use crate::base;

/// `Ceremony::TYPE`.
const PASSKEY: &str = "passkey";

/// Whether an authenticator is a passkey of `user`.
fn of_user(passkey: &Authenticator, user: &User) -> bool {
    passkey.kind.as_deref() == Some(PASSKEY) && passkey.user_internal_id == user.sequence.to_string()
}

async fn owned(ctx: &mut Context, user: &User, id: &str, require_verified: bool) -> Result<Authenticator> {
    let db = ctx.db()?;
    match documents::get::<Authenticator>(&db, "authenticators", id).await? {
        Some(a) if of_user(&a, user) && (!require_verified || a.verified == Some(true)) => Ok(a),
        _ => Err(Error::new(ErrorType::UserPasskeyNotFound)),
    }
}

/// `GET /v1/users/:userId/passkeys`.
pub async fn list(ctx: &mut Context) -> Result<Response> {
    let user_id = ctx.required_str("userId", &uid())?;
    let mut queries = ctx.queries(&collections::PASSKEYS)?;
    let include_total = ctx.optional_bool("total", true)?;
    let user = base::user_or_404(ctx, &user_id, &[]).await?;
    queries.push(Query::equal("userInternalId", [user.sequence.to_string()]));
    queries.push(Query::equal("type", [PASSKEY]));
    queries.push(Query::equal("verified", [true]));
    let db = ctx.db()?;
    if let Some(id) = base::cursor_id(&queries)? {
        let cursor = db.get_document("authenticators", &id, Vec::new(), false).await?;
        if cursor.is_empty() || !of_user(&Authenticator::from(&cursor), &user) {
            return Err(Error::with_message(
                ErrorType::GeneralCursorNotFound,
                format!("Passkey '{id}' for the 'cursor' value not found."),
            ));
        }
        base::set_cursor(&mut queries, cursor);
    }
    let ParsedQuery { filters, .. } = Query::group_by_type(&queries)?;
    let passkeys = db.find("authenticators", queries, PermissionType::Read).await?;
    let total = if include_total { db.count("authenticators", filters, Some(LIMIT_COUNT)).await? } else { 0 };
    let passkeys: Vec<Authenticator> = documents::all(&passkeys);
    Ok(ctx.ok(&PasskeyListModel { total, passkeys: &passkeys }))
}

/// `GET /v1/users/:userId/passkeys/:passkeyId`.
pub async fn get(ctx: &mut Context) -> Result<Response> {
    let user_id = ctx.required_str("userId", &uid())?;
    let passkey_id = ctx.required_str("passkeyId", &uid())?;
    let user = base::user_or_404(ctx, &user_id, &[]).await?;
    let passkey = owned(ctx, &user, &passkey_id, true).await?;
    Ok(ctx.ok(&PasskeyModel { passkey: &passkey }))
}

/// `PATCH /v1/users/:userId/passkeys/:passkeyId`.
pub async fn update(ctx: &mut Context) -> Result<Response> {
    let user_id = ctx.required_str("userId", &uid())?;
    let passkey_id = ctx.required_str("passkeyId", &uid())?;
    let name = ctx.required_str("name", &Text::new(128))?;
    let user = base::user_or_404(ctx, &user_id, &[]).await?;
    owned(ctx, &user, &passkey_id, true).await?;
    let db = ctx.db()?;
    let mut updates = Document::default();
    updates.put("name", Value::from(name.as_str()));
    let updated = db.update_document("authenticators", &passkey_id, updates).await?;
    db.purge_cached_document("users", &user.id).await?;
    Ok(ctx.ok(&PasskeyModel { passkey: &Authenticator::from(&updated) }))
}

/// `DELETE /v1/users/:userId/passkeys/:passkeyId`.
pub async fn delete(ctx: &mut Context) -> Result<Response> {
    let user_id = ctx.required_str("userId", &uid())?;
    let passkey_id = ctx.required_str("passkeyId", &uid())?;
    let user = base::user_or_404(ctx, &user_id, &[]).await?;
    owned(ctx, &user, &passkey_id, false).await?;
    let db = ctx.db()?;
    db.delete_document("authenticators", &passkey_id).await?;
    db.purge_cached_document("users", &user.id).await?;
    Ok(ctx.no_content())
}
