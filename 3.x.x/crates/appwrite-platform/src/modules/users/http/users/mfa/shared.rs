//! Shared MFA helpers. Not a PHP action class.

use appwrite_exception::Exception;
use serde_json::Value;
use utopia_auth::Proof;
use utopia_database::{AttrValue, Query};

use crate::modules::users::base;
use crate::state::document_to_json;

/// PHP `TOTP::getAuthenticatorFromUser()`: the Memory adapter has no
/// relationship attributes, so this queries `authenticators` directly by
/// `userId` + `type` rather than scanning an already-populated
/// `$user->getAttribute('authenticators')`.
pub(crate) fn totp_authenticator(
    db: &mut crate::state::ProjectDb,
    user_id: &str,
) -> Result<Option<utopia_database::Document>, Exception> {
    let mut matches = db
        .find(
            "authenticators",
            &[
                Query::equal("userId", vec![AttrValue::from(user_id)]),
                Query::equal("type", vec![AttrValue::from(appwrite_auth::mfa::TOTP)]),
                Query::limit(1),
            ],
            "read",
        )
        .map_err(base::db_error)?;
    Ok(if matches.is_empty() {
        None
    } else {
        Some(matches.remove(0))
    })
}

/// PHP `Appwrite\Auth\MFA\Type::generateBackupCodes(10, 6)`.
pub(crate) fn generate_backup_codes() -> Result<Vec<String>, Exception> {
    let proof = utopia_auth::Token::new(10).map_err(base::hash_error)?;
    (0..6)
        .map(|_| proof.generate().map_err(base::hash_error))
        .collect()
}

/// PHP `$user->getAttribute('mfaRecoveryCodes', [])`.
pub(crate) fn recovery_codes_of(user: &utopia_database::Document) -> Vec<Value> {
    document_to_json(user)
        .get("mfaRecoveryCodes")
        .and_then(Value::as_array)
        .cloned()
        .unwrap_or_default()
}
