//! `GET /v1/users/:userId/prefs` (`getUserPrefs`). Rust port of
//! `Http/Users/Prefs/Get.php`.

use appwrite_exception::Exception;
use serde_json::Value;
use utopia_platform::{Action, HttpMethod};

use crate::modules::users::base::{self, inject};

use crate::modules::users::http::users::prefs;

/// `GET /v1/users/:userId/prefs` (`getUserPrefs`).
#[must_use]
pub fn get() -> Action {
    inject(
        base::user_id_param(
            Action::new()
                .set_http_method(HttpMethod::Get)
                .set_http_path("/v1/users/:userId/prefs")
                .desc("Get user preferences")
                .groups(["api", "users"])
                .label("scope", "users.read"),
        ),
        &["response", "dbForProject"],
    )
    .http_action(|ctx| async move {
        let result = (|| -> Result<Value, Exception> {
            let db_handle = base::get_db(&ctx)?;
            let mut db = db_handle.lock();
            let user_id = base::param_str(&ctx, "userId")?;
            let user =
                base::require_document(&mut db, "users", &user_id, Exception::USER_NOT_FOUND)?;
            Ok(prefs::prefs_of(&user))
        })();
        base::finish(&ctx, 200, appwrite_response::MODEL_PREFERENCES, result)
    })
}
