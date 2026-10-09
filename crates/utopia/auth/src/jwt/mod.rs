//! Compact JWS tokens (RFC 7515): issuers (`Utopia\Auth\Issuer`,
//! `Issuers\*`) and verifiers (`Utopia\Auth\Verifier`, `Verifiers\*`).
//!
//! Time is an argument (`now`, Unix seconds) wherever PHP calls `time()`.

mod issuer;
mod key;
mod verifier;

pub use issuer::{AccessToken, Asymmetric, IdToken, Jwt, RefreshToken, Symmetric};
pub use key::{KeyPair, Rsa, generate_key_pair};
pub use verifier::{Audience, Hs256, Rs256, SignatureCheck, Verifier};

use php_std::encoding::{base64_decode, base64_encode};

/// JWT claim names (`Utopia\Auth\Enums\Claim`).
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum Claim {
    Issuer,
    Subject,
    Audience,
    Expiration,
    NotBefore,
    IssuedAt,
    JwtId,
    ClientId,
    AuthTime,
    Scope,
    Nonce,
    AccessTokenHash,
    CodeHash,
}

impl Claim {
    pub const ALL: [Claim; 13] = [
        Claim::Issuer,
        Claim::Subject,
        Claim::Audience,
        Claim::Expiration,
        Claim::NotBefore,
        Claim::IssuedAt,
        Claim::JwtId,
        Claim::ClientId,
        Claim::AuthTime,
        Claim::Scope,
        Claim::Nonce,
        Claim::AccessTokenHash,
        Claim::CodeHash,
    ];

    /// The claim name (the enum's backing value).
    pub fn as_str(self) -> &'static str {
        match self {
            Claim::Issuer => "iss",
            Claim::Subject => "sub",
            Claim::Audience => "aud",
            Claim::Expiration => "exp",
            Claim::NotBefore => "nbf",
            Claim::IssuedAt => "iat",
            Claim::JwtId => "jti",
            Claim::ClientId => "client_id",
            Claim::AuthTime => "auth_time",
            Claim::Scope => "scope",
            Claim::Nonce => "nonce",
            Claim::AccessTokenHash => "at_hash",
            Claim::CodeHash => "c_hash",
        }
    }

    /// `Claim::tryFrom($value)`.
    pub fn from_name(name: &str) -> Option<Claim> {
        Self::ALL.into_iter().find(|c| c.as_str() == name)
    }
}

/// JOSE header parameters (`Utopia\Auth\Enums\Header`).
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum Header {
    Type,
    Algorithm,
    KeyId,
}

impl Header {
    pub const ALL: [Header; 3] = [Header::Type, Header::Algorithm, Header::KeyId];

    pub fn as_str(self) -> &'static str {
        match self {
            Header::Type => "typ",
            Header::Algorithm => "alg",
            Header::KeyId => "kid",
        }
    }

    /// `Header::tryFrom($value)`.
    pub fn from_name(name: &str) -> Option<Header> {
        Self::ALL.into_iter().find(|h| h.as_str() == name)
    }
}

/// `base64UrlEncode()`: base64url without padding.
pub(crate) fn base64url_encode(value: &[u8]) -> String {
    let mut s = base64_encode(value);
    while s.ends_with('=') {
        s.pop();
    }
    s.chars()
        .map(|c| match c {
            '+' => '-',
            '/' => '_',
            c => c,
        })
        .collect()
}

/// `base64UrlDecode()`: `base64_decode(strtr($value, '-_', '+/'), true)`.
pub(crate) fn base64url_decode(value: &[u8]) -> Option<Vec<u8>> {
    let translated: Vec<u8> = value
        .iter()
        .map(|c| match c {
            b'-' => b'+',
            b'_' => b'/',
            c => *c,
        })
        .collect();
    base64_decode(&translated, true)
}
