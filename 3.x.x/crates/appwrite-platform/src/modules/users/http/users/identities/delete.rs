//! `DELETE /v1/users/identities/:identityId` (`deleteIdentity`). Rust port of
//! `Http/Users/Identities/Delete.php`.

use appwrite_exception::Exception;
use serde_json::json;
use utopia_platform::{Action, HttpMethod};
use utopia_validators::Text;

use crate::modules::users::base::{self, inject};

/// `DELETE /v1/users/identities/:identityId` (`deleteIdentity`).
#[must_use]
pub fn delete() -> Action {
    inject(
        Action::new()
            .set_http_method(HttpMethod::Delete)
            .set_http_path("/v1/users/identities/:identityId")
            .desc("Delete identity")
            .groups(["api", "users"])
            .label("scope", "users.write")
            .label("audits.event", "identity.delete")
            .label("audits.resource", "identity/{request.$identityId}")
            .param(
                "identityId",
                json!(""),
                Text::new(36),
                "Identity ID.",
                false,
            ),
        &["response", "dbForProject"],
    )
    .http_action(|ctx| async move {
        let result = (|| -> Result<(), Exception> {
            let db_handle = base::get_db(&ctx)?;
            let mut db = db_handle.lock();
            let identity_id = base::param_str(&ctx, "identityId")?;
            let identity = db
                .get_document("identities", &identity_id, &[], false)
                .map_err(base::db_error)?;
            if identity.is_empty() {
                return Err(Exception::new(Exception::USER_IDENTITY_NOT_FOUND));
            }
            db.delete_document("identities", &identity_id)
                .map_err(base::db_error)?;
            Ok(())
        })();
        base::finish_no_content(&ctx, result)
    })
}
