//! Breached password detection (`_APP_PWNED_PASSWORDS_DSN`).
//!
//! `none` and `mock` are evaluated locally. `hibp` and `appwrite` require an
//! outbound HTTPS client; until the Rust API ships one, those schemes report
//! the service as unavailable (HTTP 503), the same error PHP raises when the
//! breach service cannot be reached.

use crate::{Error, ErrorType, Result};

#[derive(Debug, Clone)]
pub enum Pwned {
    None,
    Mock,
    Remote(String),
}

impl Pwned {
    pub fn from_dsn(dsn: &str, production: bool) -> std::result::Result<Self, String> {
        let scheme = dsn.split("://").next().unwrap_or("");
        match scheme {
            "none" => Ok(Pwned::None),
            "mock" if production => Err("The mock breach validator cannot be used in production.".into()),
            "mock" => Ok(Pwned::Mock),
            "hibp" | "appwrite" => Ok(Pwned::Remote(scheme.to_owned())),
            other => Err(format!("Unknown _APP_PWNED_PASSWORDS_DSN scheme: {other}")),
        }
    }

    /// `PasswordPwned::isValid()` inverted: whether the password is breached
    /// (non-strings and passwords outside 8..256 bytes count as breached).
    pub async fn is_pwned(&self, password: &str) -> Result<bool> {
        if !(8..=256).contains(&password.len()) {
            return Ok(true);
        }
        match self {
            Pwned::None => Ok(false),
            Pwned::Mock => {
                Ok(matches!(password, "pwned-fixture-common" | "pwned-fixture-uncommon" | "pwned-fixture-rare"))
            }
            Pwned::Remote(_) => Err(Error::new(ErrorType::GeneralPwnedPasswordsUnavailable)),
        }
    }
}
