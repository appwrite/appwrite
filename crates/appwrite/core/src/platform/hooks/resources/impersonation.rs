//! The `impersonatorUser` and `targetUser` resources (PHP:
//! `app/init/resources/request.php`, `$context->set('impersonatorUser'`;
//! hooks.json `resource-impersonation`).

use utopia_database::{Database, Document, Query};

use super::string_param;
use crate::Result;
use crate::database::documents::User;
use crate::platform::context::Context;

/// The user an impersonator names, as the request's target user.
pub(in crate::platform) async fn resolve(ctx: &mut Context, users_db: &Database) -> Result<()> {
    // Impersonation.
    if let Some(user) = &ctx.user
        && user.impersonator == Some(true)
    {
        let pick = |header: &str, a: &str, b: &str| -> String {
            if let Some(h) = ctx.request.header(header) {
                return h.to_owned();
            }
            let pa = string_param(&ctx.request, a).unwrap_or_default();
            if !pa.is_empty() && pa != "0" {
                return pa;
            }
            string_param(&ctx.request, b).unwrap_or_default()
        };
        let by_id = pick("x-appwrite-impersonate-user-id", "impersonateuserid", "impersonateUserId");
        let by_email = pick("x-appwrite-impersonate-user-email", "impersonateemail", "impersonateEmail");
        let by_phone = pick("x-appwrite-impersonate-user-phone", "impersonatephone", "impersonatePhone");
        if let Some(target) = impersonated(users_db, &by_id, &by_email, &by_phone).await? {
            ctx.target_user = Some(target);
        }
    }
    Ok(())
}

/// The user an impersonator names (the `targetUser` resource): by id,
/// else by email (lower-cased), else by phone; with authorization skipped.
async fn impersonated(db: &Database, id: &str, email: &str, phone: &str) -> Result<Option<User>> {
    let authorization = db.authorization();
    let document = if !id.is_empty() {
        authorization.skip(db.get_document("users", id, Vec::new(), false)).await?
    } else if !email.is_empty() {
        let email = email.to_lowercase();
        authorization.skip(db.find_one("users", vec![Query::equal("email", [email.as_str()])])).await?
    } else if !phone.is_empty() {
        authorization.skip(db.find_one("users", vec![Query::equal("phone", [phone])])).await?
    } else {
        Document::default()
    };
    Ok(if document.is_empty() { None } else { Some(User::from(&document)) })
}
