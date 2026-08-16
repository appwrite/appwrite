//! `GET /v1/users/:userId/mfa/factors` (`listUserMFAFactors`). Rust port of
//! `Http/Users/MFA/Factors/XList.php`.

use appwrite_exception::Exception;
use serde_json::{json, Value};
use utopia_platform::{Action, HttpMethod};
use utopia_validators::Text;

use crate::modules::users::base::{self, inject};

/// `GET /v1/users/:userId/mfa/factors` (`listUserMFAFactors`).
#[must_use]
pub fn xlist() -> Action {
    inject(
        Action::new()
            .set_http_method(HttpMethod::Get)
            .set_http_path("/v1/users/:userId/mfa/factors")
            .desc("List factors")
            .groups(["api", "users"])
            .label("scope", "users.read")
            .param("userId", json!(""), Text::new(36), "User ID.", false),
        &["response", "dbForProject", "project"],
    )
    .http_action(|ctx| async move {
        let result = (|| -> Result<Value, Exception> {
            let db_handle = base::get_db(&ctx)?;
            let mut db = db_handle.lock();
            let user_id = base::param_str(&ctx, "userId")?;
            let user =
                base::require_document(&mut db, "users", &user_id, Exception::USER_NOT_FOUND)?;
            let project = base::get_project(&ctx)?;

            let totp = base::totp_authenticator(&mut db, &user_id)?;
            let totp_verified = totp
                .as_ref()
                .is_some_and(|doc| doc.get_attribute("verified").as_bool().unwrap_or(false));

            let mfa_factors = project
                .get("auths")
                .and_then(|auths| auths.get("mfaFactors"))
                .cloned()
                .unwrap_or_else(|| json!({}));
            let factor_enabled = |name: &str, default: bool| -> bool {
                mfa_factors
                    .get(name)
                    .and_then(Value::as_bool)
                    .unwrap_or(default)
            };

            let email = user
                .get_attribute("email")
                .as_str()
                .unwrap_or_default()
                .to_string();
            let email_verified = user
                .get_attribute("emailVerification")
                .as_bool()
                .unwrap_or(false);
            let phone = user
                .get_attribute("phone")
                .as_str()
                .unwrap_or_default()
                .to_string();
            let phone_verified = user
                .get_attribute("phoneVerification")
                .as_bool()
                .unwrap_or(false);

            Ok(json!({
                "totp": factor_enabled("totp", true) && totp_verified,
                "email": factor_enabled("email", true) && !email.is_empty() && email_verified,
                "phone": factor_enabled("phone", true) && !phone.is_empty() && phone_verified,
                "custom": factor_enabled("custom", false),
            }))
        })();
        base::finish(&ctx, 200, appwrite_response::MODEL_MFA_FACTORS, result)
    })
}
