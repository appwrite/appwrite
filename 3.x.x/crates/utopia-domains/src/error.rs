use thiserror::Error;

/// Error type covering parser failures and registrar adapter errors.
///
/// PHP maps these to `Utopia\Domains\Exception` and the registrar exception
/// subclasses. Variant identity is the Rust equivalent of `instanceof`.
#[derive(Debug, Error)]
pub enum DomainsError {
    /// Generic domain / hostname error (PHP `\Exception` from `Domain::__construct`).
    #[error("'{domain}' must be a valid domain or hostname")]
    InvalidDomain { domain: String },

    /// Unclassified registrar / HTTP failure (`Utopia\Domains\Exception`).
    #[error("{message}")]
    Generic { message: String, code: i64 },

    /// Authentication failed (`AuthException`).
    #[error("{message}")]
    Auth { message: String, code: i64 },

    /// Domain was not found (`DomainNotFoundException`).
    #[error("{message}")]
    DomainNotFound { message: String, code: i64 },

    /// Domain cannot be transferred (`DomainNotTransferableException`).
    #[error("{message}")]
    DomainNotTransferable { message: String, code: i64 },

    /// Domain is already registered / in-account (`DomainTakenException`).
    #[error("{message}")]
    DomainTaken { message: String, code: i64 },

    /// Transfer auth code was rejected (`InvalidAuthCodeException`).
    #[error("{message}")]
    InvalidAuthCode { message: String, code: i64 },

    /// Contact payload was incomplete or invalid (`InvalidContactException`).
    #[error("{message}")]
    InvalidContact { message: String, code: i64 },

    /// Registration period is not allowed (`InvalidPeriodException`).
    #[error("{message}")]
    InvalidPeriod { message: String, code: i64 },

    /// Registrar returned no price (`PriceNotFoundException`).
    #[error("{message}")]
    PriceNotFound { message: String, code: i64 },

    /// Registrar rate-limited the client (`RateLimitException`).
    #[error("{message}")]
    RateLimit { message: String, code: i64 },

    /// TLD is not supported (`UnsupportedTldException`).
    #[error("{message}")]
    UnsupportedTld { message: String, code: i64 },
}

impl DomainsError {
    /// PHP `Exception::getCode()`.
    pub fn code(&self) -> i64 {
        match self {
            Self::InvalidDomain { .. } => 0,
            Self::Generic { code, .. }
            | Self::Auth { code, .. }
            | Self::DomainNotFound { code, .. }
            | Self::DomainNotTransferable { code, .. }
            | Self::DomainTaken { code, .. }
            | Self::InvalidAuthCode { code, .. }
            | Self::InvalidContact { code, .. }
            | Self::InvalidPeriod { code, .. }
            | Self::PriceNotFound { code, .. }
            | Self::RateLimit { code, .. }
            | Self::UnsupportedTld { code, .. } => *code,
        }
    }

    /// PHP `Exception::getMessage()`.
    pub fn message(&self) -> String {
        self.to_string()
    }

    pub(crate) fn generic(message: impl Into<String>, code: i64) -> Self {
        Self::Generic {
            message: message.into(),
            code,
        }
    }

    pub(crate) fn auth(message: impl Into<String>, code: i64) -> Self {
        Self::Auth {
            message: message.into(),
            code,
        }
    }

    pub(crate) fn domain_not_found(message: impl Into<String>, code: i64) -> Self {
        Self::DomainNotFound {
            message: message.into(),
            code,
        }
    }

    pub(crate) fn domain_not_transferable(message: impl Into<String>, code: i64) -> Self {
        Self::DomainNotTransferable {
            message: message.into(),
            code,
        }
    }

    pub(crate) fn domain_taken(message: impl Into<String>, code: i64) -> Self {
        Self::DomainTaken {
            message: message.into(),
            code,
        }
    }

    pub(crate) fn invalid_auth_code(message: impl Into<String>, code: i64) -> Self {
        Self::InvalidAuthCode {
            message: message.into(),
            code,
        }
    }

    pub(crate) fn invalid_contact(message: impl Into<String>, code: i64) -> Self {
        Self::InvalidContact {
            message: message.into(),
            code,
        }
    }

    pub(crate) fn invalid_period(message: impl Into<String>, code: i64) -> Self {
        Self::InvalidPeriod {
            message: message.into(),
            code,
        }
    }

    pub(crate) fn price_not_found(message: impl Into<String>, code: i64) -> Self {
        Self::PriceNotFound {
            message: message.into(),
            code,
        }
    }

    pub(crate) fn rate_limit(message: impl Into<String>, code: i64) -> Self {
        Self::RateLimit {
            message: message.into(),
            code,
        }
    }

    pub(crate) fn unsupported_tld(message: impl Into<String>, code: i64) -> Self {
        Self::UnsupportedTld {
            message: message.into(),
            code,
        }
    }
}
