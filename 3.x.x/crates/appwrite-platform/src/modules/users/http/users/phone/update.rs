//! `PATCH /v1/users/:userId/phone` (`updateUserPhone`). Rust port of
//! `Http/Users/Phone/Update.php`.

use appwrite_exception::Exception;
use serde_json::{json, Value};
use utopia_platform::{Action, HttpMethod};
use utopia_validators::Validator;

use crate::modules::users::base::{self, inject};

use crate::state::document_from_json;

/// PHP `new Phone(allowEmpty: true)`: `appwrite-auth::Phone` has no
/// allow-empty variant (only `Nullable`, which accepts `null` rather than
/// `""`), so this local wrapper accepts either.
#[derive(Debug, Clone, Copy, Default)]
struct PhoneOrEmpty;

impl Validator for PhoneOrEmpty {
    fn description(&self) -> String {
        "Value must be a valid phone number".to_string()
    }

    fn value_type(&self) -> utopia_validators::ValueType {
        utopia_validators::ValueType::String
    }

    fn is_valid(&self, value: &Value) -> bool {
        if value.as_str() == Some("") {
            return true;
        }
        appwrite_auth::Phone::new().is_valid(value)
    }
}

/// `PATCH /v1/users/:userId/phone` (`updateUserPhone`).
#[must_use]
pub fn update() -> Action {
    inject(
        base::user_id_param(
            Action::new()
                .set_http_method(HttpMethod::Patch)
                .set_http_path("/v1/users/:userId/phone")
                .desc("Update phone")
                .groups(["api", "users"])
                .label("scope", "users.write")
                .label("audits.event", "user.update")
                .label("audits.resource", "user/{response.$id}"),
        )
        .param(
            "number",
            json!(""),
            PhoneOrEmpty,
            "User phone number.",
            false,
        ),
        &["response", "dbForProject"],
    )
    .http_action(|ctx| async move {
        let result = (|| -> Result<Value, Exception> {
            let db_handle = base::get_db(&ctx)?;
            let mut db = db_handle.lock();
            let user_id = base::param_str(&ctx, "userId")?;
            let number = ctx
                .param_value("number")
                .and_then(Value::as_str)
                .unwrap_or_default()
                .to_string();
            let user =
                base::require_document(&mut db, "users", &user_id, Exception::USER_NOT_FOUND)?;
            let old_phone = user
                .get_attribute("phone")
                .as_str()
                .unwrap_or_default()
                .to_string();

            // PHP checks unconditionally (not scoped to this user), so an
            // update to a number already used by *any* target -- including
            // this user's own current target -- fails the same way.
            if !number.is_empty()
                && base::find_one(&mut db, "targets", "identifier", number.as_str())?.is_some()
            {
                return Err(Exception::new(Exception::USER_TARGET_ALREADY_EXISTS));
            }

            let phone_value = if number.is_empty() {
                Value::Null
            } else {
                json!(number)
            };
            base::update_user_fields_and_search(
                &mut db,
                &user_id,
                json!({ "phone": phone_value, "phoneVerification": false }),
            )?;

            match base::find_target_by_identifier(&mut db, &user_id, &old_phone)? {
                Some(old_target) if !number.is_empty() => {
                    let _ = db.update_document(
                        "targets",
                        &old_target.get_id(),
                        document_from_json(json!({ "identifier": number })),
                    );
                }
                Some(old_target) => {
                    let _ = db.delete_document("targets", &old_target.get_id());
                }
                None if !number.is_empty() => {
                    base::create_target(&mut db, &user, "sms", &number)?;
                }
                None => {}
            }
            base::purge_user(&mut db, &user_id);

            let final_user =
                base::require_document(&mut db, "users", &user_id, Exception::USER_NOT_FOUND)?;
            Ok(base::user_with_targets(&mut db, &final_user))
        })();
        base::finish(&ctx, 200, appwrite_response::MODEL_USER, result)
    })
}
