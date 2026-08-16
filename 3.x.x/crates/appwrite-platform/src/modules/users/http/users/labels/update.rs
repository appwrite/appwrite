//! `PUT /v1/users/:userId/labels` (`updateUserLabels`). Rust port of
//! `Http/Users/Labels/Update.php`.

use appwrite_exception::Exception;
use serde_json::{json, Value};
use utopia_platform::{Action, HttpMethod};
use utopia_validators::Text;

use crate::modules::users::base::{self, inject};

/// PHP `APP_LIMIT_ARRAY_LABELS_SIZE` (`app/init/constants.php`).
const LIMIT_ARRAY_LABELS_SIZE: usize = 1000;

/// PHP `new Text(36, allowList: [...Text::NUMBERS, ...Text::ALPHABET_UPPER,
/// ...Text::ALPHABET_LOWER])`: labels are alphanumeric only.
fn label_text() -> Text {
    Text::new(36).with_allow_list(
        ('0'..='9')
            .chain('A'..='Z')
            .chain('a'..='z')
            .collect::<Vec<_>>(),
    )
}

/// `PUT /v1/users/:userId/labels` (`updateUserLabels`).
#[must_use]
pub fn update() -> Action {
    inject(
        base::user_id_param(
            Action::new()
                .set_http_method(HttpMethod::Put)
                .set_http_path("/v1/users/:userId/labels")
                .desc("Update user labels")
                .groups(["api", "users"])
                .label("scope", "users.write")
                .label("audits.event", "user.update")
                .label("audits.resource", "user/{response.$id}"),
        )
        .param(
            "labels",
            json!([]),
            utopia_validators::ArrayList::with_length(label_text(), LIMIT_ARRAY_LABELS_SIZE),
            "Array of user labels. Replaces the previous labels. Maximum of \
             1000 labels are allowed, each up to 36 alphanumeric characters \
             long.",
            false,
        ),
        &["response", "dbForProject"],
    )
    .http_action(|ctx| async move {
        let result = (|| -> Result<Value, Exception> {
            let db_handle = base::get_db(&ctx)?;
            let mut db = db_handle.lock().unwrap_or_else(|e| e.into_inner());
            let user_id = base::param_str(&ctx, "userId")?;
            let labels = ctx
                .param_value("labels")
                .and_then(Value::as_array)
                .cloned()
                .unwrap_or_default();
            let mut unique = Vec::with_capacity(labels.len());
            for label in labels {
                if !unique.contains(&label) {
                    unique.push(label);
                }
            }
            base::update_user_fields_and_search(&mut db, &user_id, json!({ "labels": unique }))
        })();
        base::finish(&ctx, 200, appwrite_response::MODEL_USER, result)
    })
}
