//! Unified proxy error type used by servers in Phase 2.

use std::io;

use crate::resolver::ResolverError;

#[derive(Debug, thiserror::Error)]
pub enum ProxyError {
    #[error(transparent)]
    Resolver(#[from] ResolverError),

    #[error("io error: {0}")]
    Io(#[from] io::Error),

    #[error("tls error: {0}")]
    Tls(String),

    #[error("invalid configuration: {0}")]
    Configuration(String),

    #[error("backend connection failed: {0}")]
    BackendConnect(String),

    #[error("protocol error: {0}")]
    Protocol(String),

    #[error("{0}")]
    Other(String),
}
