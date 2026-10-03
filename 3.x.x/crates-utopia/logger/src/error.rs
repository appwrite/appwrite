//! Error types for `utopia-logger`.

use thiserror::Error;

/// Errors raised while building or pushing logs.
#[derive(Debug, Error, Clone, PartialEq, Eq)]
pub enum LoggerError {
    /// Required log fields are missing or empty.
    #[error("Log is not ready to be pushed.")]
    NotReady,

    /// [`crate::Log::set_type`] received an unknown type.
    #[error(
        "Unsupported log type. Must be one of Log::TYPE_DEBUG, Log::TYPE_ERROR, Log::TYPE_WARNING, Log::TYPE_INFO, Log::VERBOSE."
    )]
    UnsupportedType,

    /// [`crate::Log::set_environment`] received an unknown environment.
    #[error(
        "Unsupported environment of log. Must be one of ENVIRONMENT_PRODUCTION, ENVIRONMENT_STAGING."
    )]
    UnsupportedEnvironment,

    /// [`crate::Breadcrumb`] constructed with an unknown type.
    #[error(
        "Type has to be one of Log::TYPE_DEBUG, Log::TYPE_ERROR, Log::TYPE_INFO, Log::TYPE_WARNING, Log::TYPE_VERBOSE."
    )]
    InvalidBreadcrumbType,

    /// Adapter does not support this log type.
    #[error("Supported log types for this adapter are: {0}")]
    UnsupportedAdapterLogType(String),

    /// Adapter does not support this environment.
    #[error("Supported environments for this adapter are: {0}")]
    UnsupportedAdapterEnvironment(String),

    /// Adapter does not support a breadcrumb type on this log.
    #[error("Supported breadcrumb types for this adapter are: {0}")]
    UnsupportedAdapterBreadcrumbType(String),

    /// Adapter-specific payload error (message matches PHP `Exception`).
    #[error("{0}")]
    Message(String),
}
