//! WebAuthn passkeys for one relying party (`Utopia\Auth\Passkeys\*`):
//! registration and sign-in ceremonies with discoverable credentials,
//! required user verification and no attestation.

mod cbor;
mod ceremony;
mod cose;
mod origin;
mod tags;

pub use ceremony::{Ceremony, Challenge, Counter, Credential, StrictCounter, TIMEOUT};
pub use cose::{Algorithm, PublicKey};
pub use origin::Origin;

use sha2::{Digest, Sha256};

/// The relying party passkeys are bound to (`Utopia\Auth\Passkeys\RelyingParty`):
/// its ID (a domain), display name and the exact origins allowed to run ceremonies.
///
/// ```
/// use utopia_auth::passkeys::RelyingParty;
///
/// let a = RelyingParty::new("example.com", "Example", vec!["https://b.example.com".into(), "https://a.example.com".into()]);
/// let b = RelyingParty::new("example.com", "Example", vec!["https://a.example.com".into(), "https://b.example.com".into()]);
/// // The fingerprint ignores the order of the origins.
/// assert_eq!(a.fingerprint(), b.fingerprint());
/// assert_eq!(a.fingerprint().len(), 64);
/// ```
///
/// ```php
/// use Utopia\Auth\Passkeys\RelyingParty;
///
/// $a = new RelyingParty('example.com', 'Example', ['https://b.example.com', 'https://a.example.com']);
/// $b = new RelyingParty('example.com', 'Example', ['https://a.example.com', 'https://b.example.com']);
/// var_dump($a->getFingerprint() === $b->getFingerprint()); // bool(true)
/// ```
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct RelyingParty {
    /// The relying party ID: the registrable domain credentials are scoped to.
    pub id: String,
    /// The name authenticators show.
    pub name: String,
    /// The origins ceremonies may come from (empty: the ID's HTTPS origin).
    pub origins: Vec<String>,
}

impl RelyingParty {
    /// `new RelyingParty($id, $name, $origins)`.
    pub fn new(id: impl Into<String>, name: impl Into<String>, origins: Vec<String>) -> Self {
        Self { id: id.into(), name: name.into(), origins }
    }

    /// `getFingerprint()`: SHA-256 (hex) of the ID and the sorted origins, to
    /// invalidate ceremonies started under another configuration.
    pub fn fingerprint(&self) -> String {
        let mut origins: php_std::Array = self.origins.iter().map(|o| php_std::Value::from(o.as_str())).collect();
        // `sort()` compares strings with `<=>`, which never fails.
        let _ = php_std::sort::sort(&mut origins, php_std::sort::SortFlags::Regular);
        let origins: Vec<&[u8]> = origins
            .values()
            .map(|o| match o {
                php_std::Value::Str(s) => s.as_bytes(),
                _ => b"",
            })
            .collect();
        hex::encode(Sha256::digest([self.id.as_bytes(), b"\n", &origins.join(&b'\n')].concat()))
    }
}
