//! Authentication and authorization for Utopia.
//!
//! Rust port of [`utopia-php/auth`](https://github.com/utopia-php/auth).

mod error;
mod hash;
mod proof;
mod proofs;
mod store;

pub mod hashes;

#[cfg(feature = "jwt")]
pub mod jwt;
#[cfg(feature = "oauth2")]
pub mod oauth2;

pub use error::{AuthError, VerificationException};
pub use hash::{Hash, HashMut, HashOptions};
pub use proof::{Proof, ProofBase};
pub use proofs::{Code, Password, Phrase, Token};
pub use store::Store;

#[cfg(feature = "argon2")]
pub use hashes::Argon2;
#[cfg(feature = "bcrypt")]
pub use hashes::Bcrypt;
#[cfg(feature = "legacy")]
pub use hashes::{Md5, PHPass, Plaintext, Scrypt, ScryptModified, Sha};

#[cfg(feature = "jwt")]
pub use jwt::{
    issuers::{AccessToken, AsymmetricIssuer, IdToken, RefreshToken, SymmetricIssuer},
    Claim, Header, Issuer, Verifier, VerifierConfig,
};

pub mod prelude {
    pub use crate::{
        AuthError, Code, Hash, Password, Phrase, Proof, Store, Token, VerificationException,
    };

    #[cfg(feature = "jwt")]
    pub use crate::jwt::{
        issuers::{AccessToken, AsymmetricIssuer, IdToken, RefreshToken, SymmetricIssuer},
        verifiers::{AsymmetricVerifier, SymmetricVerifier},
        Audience, Claim, Header, Issuer, Verifier, VerifierConfig,
    };
    #[cfg(feature = "oauth2")]
    pub use crate::oauth2::{
        ClientIdMetadataDocument, ClientIdentifierUrl, InvalidClientMetadataException,
        InvalidPromptException, InvalidRequestUriException, InvalidResourceException, Prompt,
        Prompts, RedirectUris, ResourceIndicators, ResourceInput, PAR,
    };
    #[cfg(feature = "argon2")]
    pub use crate::Argon2;
    #[cfg(feature = "bcrypt")]
    pub use crate::Bcrypt;
    #[cfg(feature = "legacy")]
    pub use crate::{Md5, PHPass, Plaintext, Scrypt, ScryptModified, Sha};
}
