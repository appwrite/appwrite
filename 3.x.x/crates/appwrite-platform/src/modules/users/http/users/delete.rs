//! `DELETE /v1/users/:userId` (`deleteUser`). Rust port of `Http/Users/Delete.php`.

use appwrite_event::{DeleteMessage, DeletePublisher};
use appwrite_exception::Exception;
use serde_json::json;
use std::sync::Arc;
use utopia_database::Query;
use utopia_platform::{Action, HttpMethod};
use utopia_validators::Text;

use crate::modules::users::base::{self, inject};
use crate::state::document_to_json;

/// `DELETE /v1/users/:userId` (`deleteUser`): delete the user, batch-delete
/// identities/targets by `userInternalId`, and enqueue `v1-deletes` for
/// sessions/tokens/memberships (handled by the deletes worker in PHP).
#[must_use]
pub fn delete() -> Action {
    inject(
        Action::new()
            .set_http_method(HttpMethod::Delete)
            .set_http_path("/v1/users/:userId")
            .desc("Delete user")
            .groups(["api", "users"])
            .label("scope", "users.write")
            .label("audits.event", "user.delete")
            .label("audits.resource", "user/{request.userId}")
            .param("userId", json!(""), Text::new(36), "User ID.", false),
        &["response", "dbForProject", "publisherForDeletes"],
    )
    .http_action(|ctx| async move {
        let result = (|| -> Result<(), Exception> {
            let db_handle = base::get_db(&ctx)?;
            let deletes = ctx
                .container
                .get_as::<Arc<dyn DeletePublisher>>("publisherForDeletes")
                .map_err(|_| Exception::new(Exception::GENERAL_SERVER_ERROR))?;
            let mut db = db_handle.lock();
            let user_id = base::param_str(&ctx, "userId")?;
            let user =
                base::require_document(&mut db, "users", &user_id, Exception::USER_NOT_FOUND)?;
            let sequence = base::sequence_str(&user);

            // Match PHP order: delete the user first, then identities/targets.
            // Sessions/tokens are left to the deletes worker.
            db.delete_document("users", &user_id)
                .map_err(base::db_error)?;
            if !sequence.is_empty() {
                for collection in ["identities", "targets"] {
                    if let Ok(docs) = db.find(
                        collection,
                        &[
                            Query::equal("userInternalId", vec![sequence.clone().into()]),
                            Query::limit(1000),
                        ],
                        "read",
                    ) {
                        for doc in docs {
                            let _ = db.delete_document(collection, &doc.get_id());
                        }
                    }
                }
            }

            let message = DeleteMessage::new(appwrite_event::DELETE_TYPE_DOCUMENT)
                .with_document(document_to_json(&user))
                .with_resource_type(appwrite_event::RESOURCE_TYPE_USERS);
            let _ = deletes.enqueue(message);
            Ok(())
        })();
        base::finish_no_content(&ctx, result)
    })
}
