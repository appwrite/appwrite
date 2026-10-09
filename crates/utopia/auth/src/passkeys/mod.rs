//! WebAuthn passkeys for one relying party (`Utopia\Auth\Passkeys\*`):
//! registration and sign-in ceremonies with discoverable credentials,
//! required user verification and no attestation.

mod ceremony;
mod cose;
mod origin;

pub use ceremony::{Ceremony, Challenge, Counter, Credential, StrictCounter, TIMEOUT};
pub use cose::{Algorithm, PublicKey};
pub use origin::Origin;

use std::cmp::Ordering;

use php_std::value::{compare_numbers, numeric_str};
use sha2::{Digest, Sha256};

/// The relying party passkeys are bound to (`Utopia\Auth\Passkeys\RelyingParty`):
/// its ID (a domain), display name and the exact origins allowed to run ceremonies.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct RelyingParty {
    pub id: String,
    pub name: String,
    pub origins: Vec<String>,
}

/// `sort()`'s default comparison of two strings: numerically when both are numeric.
fn php_compare(a: &str, b: &str) -> Ordering {
    match (numeric_str(a), numeric_str(b)) {
        (Some(x), Some(y)) => compare_numbers(x, y),
        _ => a.as_bytes().cmp(b.as_bytes()),
    }
}

impl RelyingParty {
    pub fn new(id: impl Into<String>, name: impl Into<String>, origins: Vec<String>) -> Self {
        Self { id: id.into(), name: name.into(), origins }
    }

    /// `getFingerprint()`: SHA-256 (hex) of the ID and the sorted origins, to
    /// invalidate ceremonies started under another configuration.
    pub fn fingerprint(&self) -> String {
        let mut origins: Vec<&str> = self.origins.iter().map(String::as_str).collect();
        origins.sort_by(|a, b| php_compare(a, b));
        hex::encode(Sha256::digest(format!("{}\n{}", self.id, origins.join("\n"))))
    }
}
