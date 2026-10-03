//! JWT claim and header names.

/// Registered JWT claim names.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Claim {
    /// Issuer (`iss`).
    Issuer,
    /// Subject (`sub`).
    Subject,
    /// Audience (`aud`).
    Audience,
    /// Expiration (`exp`).
    Expiration,
    /// Not before (`nbf`).
    NotBefore,
    /// Issued at (`iat`).
    IssuedAt,
    /// JWT ID (`jti`).
    JwtId,
    /// `OAuth2` client ID (`client_id`).
    ClientId,
    /// End-user authentication time (`auth_time`).
    AuthTime,
    /// Granted scopes (`scope`).
    Scope,
    /// OIDC nonce (`nonce`).
    Nonce,
    /// Access token hash (`at_hash`).
    AccessTokenHash,
    /// Authorization code hash (`c_hash`).
    CodeHash,
}

impl Claim {
    /// Claim name as it appears in a JWT payload.
    #[must_use]
    pub const fn as_str(self) -> &'static str {
        match self {
            Self::Issuer => "iss",
            Self::Subject => "sub",
            Self::Audience => "aud",
            Self::Expiration => "exp",
            Self::NotBefore => "nbf",
            Self::IssuedAt => "iat",
            Self::JwtId => "jti",
            Self::ClientId => "client_id",
            Self::AuthTime => "auth_time",
            Self::Scope => "scope",
            Self::Nonce => "nonce",
            Self::AccessTokenHash => "at_hash",
            Self::CodeHash => "c_hash",
        }
    }
}

/// JOSE header parameter names.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Header {
    /// Token type (`typ`).
    Type,
    /// Signing algorithm (`alg`).
    Algorithm,
    /// Key identifier (`kid`).
    KeyId,
}

impl Header {
    /// Header name as it appears in a JWT header.
    #[must_use]
    pub const fn as_str(self) -> &'static str {
        match self {
            Self::Type => "typ",
            Self::Algorithm => "alg",
            Self::KeyId => "kid",
        }
    }
}
