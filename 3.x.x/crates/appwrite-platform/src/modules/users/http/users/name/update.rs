//! `PATCH /v1/users/:userId/name` (`updateUserName`). Rust port of
//! `Http/Users/Name/Update.php`.

use appwrite_exception::Exception;
use serde_json::{json, Value};
use utopia_platform::{Action, HttpMethod};
use utopia_validators::Text;

use crate::modules::users::base::{self, inject};

/// `PATCH /v1/users/:userId/name` (`updateUserName`).
#[must_use]
pub fn update() -> Action {
    inject(
        base::user_id_param(
            Action::new()
                .set_http_method(HttpMethod::Patch)
                .set_http_path("/v1/users/:userId/name")
                .desc("Update name")
                .groups(["api", "users"])
                .label("scope", "users.write")
                .label("audits.event", "user.update")
                .label("audits.resource", "user/{response.$id}"),
        )
        .param(
            "name",
            json!(""),
            Text::new(128),
            "User name. Max length: 128 chars.",
            false,
        ),
        &["response", "dbForProject"],
    )
    .http_action(|ctx| async move {
        let result = (|| -> Result<Value, Exception> {
            let db_handle = base::get_db(&ctx)?;
            let mut db = db_handle.lock();
            let user_id = base::param_str(&ctx, "userId")?;
            let name = base::param_str(&ctx, "name")?;
            base::update_user_fields_and_search(&mut db, &user_id, json!({ "name": name }))
        })();
        base::finish(&ctx, 200, appwrite_response::MODEL_USER, result)
    })
}
