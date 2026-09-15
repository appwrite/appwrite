//! Resolver trait + result/error types. Mirrors `src/Resolver.php`, `Resolver/Result.php`,
//! `Resolver/Exception.php`.

pub mod fixed;

use std::collections::HashMap;
use std::time::Duration;

use async_trait::async_trait;

/// Backend resolver. The single method takes protocol-specific input (raw TCP bytes,
/// HTTP hostname, SMTP domain) and returns a backend endpoint + metadata.
#[async_trait]
pub trait Resolver: Send + Sync {
    async fn resolve(&self, data: &[u8]) -> Result<ResolverResult, ResolverError>;
}

/// Result of a successful resolution.
#[derive(Debug, Clone)]
pub struct ResolverResult {
    pub endpoint: String,
    pub metadata: HashMap<String, String>,
    pub timeout: Option<Duration>,
}

impl ResolverResult {
    pub fn new(endpoint: impl Into<String>) -> Self {
        Self {
            endpoint: endpoint.into(),
            metadata: HashMap::new(),
            timeout: None,
        }
    }

    pub fn with_metadata(mut self, metadata: HashMap<String, String>) -> Self {
        self.metadata = metadata;
        self
    }

    pub fn with_timeout(mut self, timeout: Duration) -> Self {
        self.timeout = Some(timeout);
        self
    }
}

/// Resolver error. HTTP-like status codes match `Resolver/Exception.php`:
/// NotFound=404, Unavailable=503, Timeout=504, Forbidden=403, Internal=500.
#[derive(Debug, thiserror::Error)]
pub enum ResolverError {
    #[error("not found: {message}")]
    NotFound {
        message: String,
        context: HashMap<String, String>,
    },
    #[error("unavailable: {message}")]
    Unavailable {
        message: String,
        context: HashMap<String, String>,
    },
    #[error("timeout: {message}")]
    Timeout {
        message: String,
        context: HashMap<String, String>,
    },
    #[error("forbidden: {message}")]
    Forbidden {
        message: String,
        context: HashMap<String, String>,
    },
    #[error("internal: {message}")]
    Internal {
        message: String,
        context: HashMap<String, String>,
    },
}

impl ResolverError {
    pub const NOT_FOUND: u16 = 404;
    pub const UNAVAILABLE: u16 = 503;
    pub const TIMEOUT: u16 = 504;
    pub const FORBIDDEN: u16 = 403;
    pub const INTERNAL: u16 = 500;

    pub fn not_found(message: impl Into<String>) -> Self {
        Self::NotFound {
            message: message.into(),
            context: HashMap::new(),
        }
    }

    pub fn unavailable(message: impl Into<String>) -> Self {
        Self::Unavailable {
            message: message.into(),
            context: HashMap::new(),
        }
    }

    pub fn timeout(message: impl Into<String>) -> Self {
        Self::Timeout {
            message: message.into(),
            context: HashMap::new(),
        }
    }

    pub fn forbidden(message: impl Into<String>) -> Self {
        Self::Forbidden {
            message: message.into(),
            context: HashMap::new(),
        }
    }

    pub fn internal(message: impl Into<String>) -> Self {
        Self::Internal {
            message: message.into(),
            context: HashMap::new(),
        }
    }

    pub fn code(&self) -> u16 {
        match self {
            Self::NotFound { .. } => Self::NOT_FOUND,
            Self::Unavailable { .. } => Self::UNAVAILABLE,
            Self::Timeout { .. } => Self::TIMEOUT,
            Self::Forbidden { .. } => Self::FORBIDDEN,
            Self::Internal { .. } => Self::INTERNAL,
        }
    }

    pub fn context(&self) -> &HashMap<String, String> {
        match self {
            Self::NotFound { context, .. }
            | Self::Unavailable { context, .. }
            | Self::Timeout { context, .. }
            | Self::Forbidden { context, .. }
            | Self::Internal { context, .. } => context,
        }
    }

    pub fn with_context(mut self, context: HashMap<String, String>) -> Self {
        match &mut self {
            Self::NotFound { context: c, .. }
            | Self::Unavailable { context: c, .. }
            | Self::Timeout { context: c, .. }
            | Self::Forbidden { context: c, .. }
            | Self::Internal { context: c, .. } => *c = context,
        }
        self
    }
}
