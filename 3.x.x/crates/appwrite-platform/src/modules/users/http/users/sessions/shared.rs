//! Shared session/token helpers. Not a PHP action class.

use std::sync::Arc;

use appwrite_exception::Exception;
use utopia_auth::Proof;

/// PHP `SESSION_PROVIDER_SERVER` (`app/init/constants.php`).
pub(crate) const SESSION_PROVIDER_SERVER: &str = "server";
/// PHP `TOKEN_EXPIRATION_LOGIN_LONG` (`app/init/constants.php`): 1 year, in
/// seconds.
pub(crate) const TOKEN_EXPIRATION_LOGIN_LONG: i64 = 31_536_000;
/// PHP `TOKEN_EXPIRATION_GENERIC`: 15 minutes, in seconds.
pub(crate) const TOKEN_EXPIRATION_GENERIC: i64 = 900;
/// PHP `TOKEN_TYPE_GENERIC` (`app/init/constants.php`).
pub(crate) const TOKEN_TYPE_GENERIC: i64 = 8;

pub(crate) fn expire_at(seconds: i64) -> String {
    let expire = chrono::Utc::now() + chrono::Duration::seconds(seconds);
    expire.format("%Y-%m-%dT%H:%M:%S%.3f+00:00").to_string()
}

pub(crate) fn token_proof() -> Result<utopia_auth::Token, Exception> {
    let mut token = utopia_auth::Token::new(32).map_err(crate::modules::users::base::hash_error)?;
    token.set_hasher(Arc::new(utopia_auth::Sha::new()));
    Ok(token)
}
