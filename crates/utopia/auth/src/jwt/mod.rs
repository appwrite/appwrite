//! Signed JSON Web Tokens (compact JWS, RFC 7515): issuers that sign
//! claims with HS256 or RS256, and a verifier that checks the signature and
//! the registered claims.
//!
//! Time is an argument (`now`, Unix seconds), never read from the clock.
//!
//! PHP: issuers are `Utopia\Auth\Issuer` and `Issuers\*`, verifiers
//! `Utopia\Auth\Verifier` and `Verifiers\*`; `now` replaces `time()`.

mod issuer;
pub(crate) mod key;
mod verifier;

pub use issuer::{AccessToken, Asymmetric, IdToken, Jwt, RefreshToken, Symmetric};
pub use key::{KeyPair, Rsa, generate_key_pair};
pub use verifier::{Audience, Hs256, Rs256, SignatureCheck, Verifier};

use php_std::encoding::{base64_decode, base64_encode};

/// A registered JWT claim name, such as `iss` or `exp`.
///
/// PHP: `Utopia\Auth\Enums\Claim`.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum Claim {
    /// `iss`: who issued the token.
    Issuer,
    /// `sub`: whom the token is about.
    Subject,
    /// `aud`: who the token is for.
    Audience,
    /// `exp`: when the token expires (Unix seconds).
    Expiration,
    /// `nbf`: when the token becomes valid.
    NotBefore,
    /// `iat`: when the token was issued.
    IssuedAt,
    /// `jti`: a unique token identifier.
    JwtId,
    /// `client_id`: the OAuth2 client.
    ClientId,
    /// `auth_time`: when the user authenticated.
    AuthTime,
    /// `scope`: granted scopes, space separated.
    Scope,
    /// `nonce`: the OpenID Connect request nonce.
    Nonce,
    /// `at_hash`: the access token hash (ID tokens).
    AccessTokenHash,
    /// `c_hash`: the authorization code hash (ID tokens).
    CodeHash,
}

impl Claim {
    /// Every claim, in declaration order.
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

    /// The claim's name in a token (`"iss"`, `"exp"`, ...).
    ///
    /// PHP: the enum's backing value.
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

    /// The claim with the name `name`, or `None` for any other claim.
    ///
    /// PHP: `Claim::tryFrom($value)`.
    pub fn from_name(name: &str) -> Option<Claim> {
        Self::ALL.into_iter().find(|c| c.as_str() == name)
    }
}

/// A JOSE header parameter of a JWT: `typ`, `alg` or `kid`.
///
/// PHP: `Utopia\Auth\Enums\Header`.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum Header {
    /// `typ`: the token type.
    Type,
    /// `alg`: the signature algorithm.
    Algorithm,
    /// `kid`: the signing key.
    KeyId,
}

impl Header {
    /// Every header parameter, in declaration order.
    pub const ALL: [Header; 3] = [Header::Type, Header::Algorithm, Header::KeyId];

    /// The parameter's name in a token header (`"typ"`, `"alg"`, `"kid"`).
    ///
    /// PHP: the enum's backing value.
    pub fn as_str(self) -> &'static str {
        match self {
            Header::Type => "typ",
            Header::Algorithm => "alg",
            Header::KeyId => "kid",
        }
    }

    /// The header parameter with the name `name`, or `None` for any other.
    ///
    /// PHP: `Header::tryFrom($value)`.
    pub fn from_name(name: &str) -> Option<Header> {
        Self::ALL.into_iter().find(|h| h.as_str() == name)
    }
}

/// The HS256 signature of `input`: its HMAC-SHA256 under `secret`, as raw bytes.
///
/// PHP: `hash_hmac('sha256', $input, $secret, true)`.
pub fn hs256(secret: &[u8], input: &[u8]) -> Vec<u8> {
    use hmac::Mac;
    let mut mac = hmac::Hmac::<sha2::Sha256>::new_from_slice(secret).expect("HMAC takes any key length");
    mac.update(input);
    mac.finalize().into_bytes().to_vec()
}

/// The RS256 signature of `input` with a PEM RSA private key, as raw bytes.
///
/// Fails with [`Error::Exception`](crate::Error::Exception) when the key
/// cannot be read or cannot sign.
///
/// PHP: `openssl_sign($input, $signature, $privateKey, OPENSSL_ALGO_SHA256)`.
pub fn rs256(private_key: &[u8], input: &[u8]) -> Result<Vec<u8>, crate::Error> {
    let key = key::private_key(private_key)?
        .ok_or_else(|| crate::Error::Exception("Unable to parse the private key".into()))?;
    key::sign(key, input)
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
