//! Appwrite authentication helpers on `utopia-auth` and `utopia-validators`.
//!
//! Ports the pieces of `Appwrite\Auth\*` the Users API foundation needs:
//! API key decoding ([`Key`]), password/phone validators, and MFA factor
//! type identifiers ([`mfa`]). Password hashing itself is not reimplemented
//! here; it is re-exported from `utopia-auth` (Rust port of
//! `utopia-php/auth`), which already carries the full `Hash` trait plus
//! Argon2/Bcrypt/legacy algorithm implementations PHP `Appwrite\Auth\Hash\*`
//! and `Appwrite\Auth\Auth::passwordHash()` use.
//!
//! ```
//! use appwrite_auth::{mfa, Key, Password};
//! use utopia_validators::Validator;
//! use serde_json::json;
//!
//! assert!(Password::new(false).is_valid(&json!("longenoughpassword")));
//! assert!(!Password::new(false).is_valid(&json!("short")));
//!
//! let project = json!({
//!     "$id": "proj1",
//!     "keys": [{ "secret": "abc123", "scopes": ["users.write"], "name": "CI" }],
//! });
//! let key = Key::decode_standard(&project, "abc123");
//! assert_eq!(key.name, "CI");
//! assert!(!key.expired);
//!
//! assert_eq!(mfa::TOTP, "totp");
//! ```

mod key;
mod validator;

pub mod mfa;

pub use key::{Key, ROLE_GUESTS, ROLE_KEYS, TYPE_STANDARD};
pub use validator::{Password, Phone};

// Password hashing lives in `utopia-auth` (Rust port of `utopia-php/auth`);
// re-export the trait and default algorithm implementations so callers only
// need this crate's dependency, matching PHP's `Appwrite\Auth\Hash\*`
// wrapping `Utopia\Auth\Hash`.
#[cfg(feature = "argon2")]
pub use utopia_auth::Argon2;
#[cfg(feature = "bcrypt")]
pub use utopia_auth::Bcrypt;
pub use utopia_auth::{Hash, HashOptions};

/// Verify a plaintext password against a stored hash using the given
/// algorithm. Thin wrapper over [`Hash::verify`] so callers do not need to
/// import `utopia_auth::Hash` themselves.
#[must_use]
pub fn verify_password(hasher: &dyn Hash, password: &str, hash: &str) -> bool {
    hasher.verify(password, hash)
}

/// Hash a plaintext password using the given algorithm. Thin wrapper over
/// [`Hash::hash`].
pub fn hash_password(
    hasher: &dyn Hash,
    password: &str,
) -> Result<String, appwrite_exception::Exception> {
    hasher.hash(password).map_err(|err| {
        appwrite_exception::Exception::with_message(
            appwrite_exception::Exception::GENERAL_SERVER_ERROR,
            err.to_string(),
        )
    })
}
