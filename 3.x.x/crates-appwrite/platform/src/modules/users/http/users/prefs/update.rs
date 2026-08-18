//! `PATCH /v1/users/:userId/prefs` (`updateUserPrefs`). Rust port of
//! `Http/Users/Prefs/Update.php`.

use serde_json::json;
use utopia_platform::{Action, HttpMethod};
use utopia_validators::Assoc;

use crate::modules::users::base::{self, inject};

/// `PATCH /v1/users/:userId/prefs` (`updateUserPrefs`).
#[must_use]
pub fn update() -> Action {
    inject(
        base::user_id_param(
            Action::new()
                .set_http_method(HttpMethod::Patch)
                .set_http_path("/v1/users/:userId/prefs")
                .desc("Update user preferences")
                .groups(["api", "users"])
                .label("scope", "users.write"),
        )
        .param(
            "prefs",
            json!({}),
            Assoc,
            "Prefs key-value JSON object.",
            false,
        ),
        &["response", "dbForProject"],
    )
    .http_action(|ctx| async move {
        base::finish_blocking(ctx, 200, appwrite_response::MODEL_PREFERENCES, |ctx| {
            let db_handle = base::get_db(&ctx)?;
            let mut db = db_handle.lock();
            let user_id = base::param_str(&ctx, "userId")?;
            let prefs = ctx
                .param_value("prefs")
                .cloned()
                .unwrap_or_else(|| json!({}));
            base::update_user_fields(&mut db, &user_id, json!({ "prefs": prefs.clone() }))?;
            Ok(prefs)
        })
        .await
    })
}
