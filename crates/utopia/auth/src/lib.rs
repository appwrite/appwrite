//! Authentication primitives with the semantics of `utopia-php/auth`.
//!
//! | PHP | Rust |
//! |---|---|
//! | `Utopia\Auth\Hash` (abstract) | [`Hash`] (trait), options [`Options`] |
//! | `Hash::setOption()`, `setOptions()`, `getOption()`, `getOptions()` | [`Hash::options_mut`] / [`Options::set`], [`proofs::set_options`], [`Options::get`], [`Hash::options`] |
//! | `Utopia\Auth\Hashes\Argon2` | [`hashes::Argon2`] |
//! | `Utopia\Auth\Hashes\Bcrypt` | [`hashes::Bcrypt`] |
//! | `Utopia\Auth\Hashes\MD5` | [`hashes::Md5`] |
//! | `Utopia\Auth\Hashes\PHPass` | [`hashes::PHPass`] |
//! | `Utopia\Auth\Hashes\Plaintext` | [`hashes::Plaintext`] |
//! | `Utopia\Auth\Hashes\Scrypt` | [`hashes::Scrypt`] |
//! | `Utopia\Auth\Hashes\ScryptModified` | [`hashes::ScryptModified`] |
//! | `Utopia\Auth\Hashes\Sha` (+ `SHA*` constants) | [`hashes::Sha`], [`hashes::ShaVersion`] |
//! | `Utopia\Auth\Proof` (abstract) | [`proofs::Proof`] (trait) |
//! | `Utopia\Auth\Proofs\Code`, `Phrase`, `Token`, `Password` | [`proofs::Code`], [`proofs::Phrase`], [`proofs::Token`], [`proofs::Password`] |
//! | `Password::createHash()` | [`proofs::Password::create_hash`] |
//! | `Utopia\Auth\Store` | [`Store`] |
//! | `Utopia\Auth\Issuer`, `Issuers\Symmetric`, `Issuers\Asymmetric` | [`jwt::Symmetric`], [`jwt::Asymmetric`] (`sign(typ, claims)`) |
//! | `Issuers\Symmetric\Jwt`, `RefreshToken` | [`jwt::Jwt`], [`jwt::RefreshToken`] |
//! | `Issuers\Asymmetric\AccessToken`, `IdToken` | [`jwt::AccessToken`], [`jwt::IdToken`] |
//! | `Asymmetric::generateKeyPair()` | [`jwt::generate_key_pair`] |
//! | `Utopia\Auth\Verifier`, `Verifiers\Symmetric`, `Verifiers\Asymmetric` | [`jwt::Verifier`] over [`jwt::Hs256`] / [`jwt::Rs256`] ([`jwt::SignatureCheck`]) |
//! | `Verifiers\VerificationException` | [`Error::Verification`] |
//! | `Utopia\Auth\Enums\Claim`, `Header` | [`jwt::Claim`], [`jwt::Header`] |
//! | `Utopia\Auth\Enums\Prompt`, `AuthorizationDetail` | [`oauth2::Prompt`], [`oauth2::AuthorizationDetail`] |
//! | `Utopia\Auth\OAuth2\PAR` | [`oauth2::Par`] |
//! | `OAuth2\Prompts`, `RedirectUris`, `ResourceIndicators` | [`oauth2::Prompts`], [`oauth2::RedirectUris`], [`oauth2::ResourceIndicators`] |
//! | `OAuth2\AuthorizationDetails` | [`oauth2::AuthorizationDetails`] |
//! | `OAuth2\ClientIdentifierUrl`, `ClientIdMetadataDocument` | [`oauth2::ClientIdentifierUrl`], [`oauth2::ClientIdMetadataDocument`] |
//! | `OAuth2\Invalid*Exception` | [`Error::InvalidPrompt`], [`Error::InvalidRequestUri`], [`Error::InvalidResource`], [`Error::InvalidClientMetadata`] ([`Error::oauth2_code`]) |
//! | `Utopia\Auth\Passkeys\Ceremony`, `Challenge`, `Credential` | [`passkeys::Ceremony`], [`passkeys::Challenge`], [`passkeys::Credential`] |
//! | `Passkeys\RelyingParty`, `Origin`, `Counter` | [`passkeys::RelyingParty`], [`passkeys::Origin`], [`passkeys::Counter`] |
//! | `Passkeys\Exception` | [`Error::Passkey`] |
//!
//! PHP strings are bytes, so values are `&[u8]`. Every place PHP reads the
//! clock (`time()`) takes `now` (Unix seconds) instead. Hashing is CPU
//! bound: async callers run it on the blocking pool.

mod error;
mod hash;
pub mod hashes;
pub mod jwt;
pub mod oauth2;
mod options;
pub mod passkeys;
pub mod proofs;
mod store;

pub use error::Error;
pub use hash::{Hash, hash_equals, random_bytes};
pub use options::{OptionValue, Options};
pub use store::Store;
