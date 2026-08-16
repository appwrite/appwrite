//! PSR-18-shaped HTTP client errors.
//!
//! Hierarchy matches PHP `Utopia\Client\Exception`:
//!
//! ```text
//! Error (ClientExceptionInterface)
//! ├── network kinds (NetworkExceptionInterface)
//! │   ├── Network, Dns, Timeout, Protocol, Proxy
//! │   └── Connection
//! │       └── Tls
//! └── request kinds (RequestExceptionInterface)
//!     └── Request, AdapterInitialization, AdapterPrecondition,
//!         InvalidResponse, InvalidUri
//! ```

use std::fmt;

use bytes::Bytes;
use http::Request;

/// Discriminator matching the PHP exception class.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum ErrorKind {
    AdapterInitialization,
    AdapterPrecondition,
    Connection,
    Dns,
    InvalidResponse,
    InvalidUri,
    Network,
    Protocol,
    Proxy,
    Request,
    Timeout,
    Tls,
    /// PHP `ValueError` (invalid timeout). Not a PSR-18 client exception.
    Value,
    /// PHP `InvalidArgumentException`.
    InvalidArgument,
    /// Error from [`utopia_pools::Pool`].
    Pool,
}

/// PHP `Utopia\Client\Exception\*` plus config errors.
#[derive(Debug)]
pub struct Error {
    kind: ErrorKind,
    message: String,
    code: i32,
    request: Option<Box<Request<Bytes>>>,
    source: Option<Box<dyn std::error::Error + Send + Sync>>,
}

impl Error {
    fn new(
        kind: ErrorKind,
        request: Option<Request<Bytes>>,
        message: impl Into<String>,
        code: i32,
    ) -> Self {
        Self {
            kind,
            message: message.into(),
            code,
            request: request.map(Box::new),
            source: None,
        }
    }

    #[must_use]
    pub fn kind(&self) -> ErrorKind {
        self.kind
    }

    #[must_use]
    pub fn code(&self) -> i32 {
        self.code
    }

    /// PHP `getRequest()`.
    #[must_use]
    pub fn get_request(&self) -> Option<&Request<Bytes>> {
        self.request.as_deref()
    }

    /// PSR-18 `NetworkExceptionInterface`.
    #[must_use]
    pub fn is_network(&self) -> bool {
        matches!(
            self.kind,
            ErrorKind::Network
                | ErrorKind::Dns
                | ErrorKind::Timeout
                | ErrorKind::Protocol
                | ErrorKind::Proxy
                | ErrorKind::Connection
                | ErrorKind::Tls
        )
    }

    /// PSR-18 `RequestExceptionInterface`.
    #[must_use]
    pub fn is_request_exception(&self) -> bool {
        matches!(
            self.kind,
            ErrorKind::Request
                | ErrorKind::AdapterInitialization
                | ErrorKind::AdapterPrecondition
                | ErrorKind::InvalidResponse
                | ErrorKind::InvalidUri
        )
    }

    /// PHP `ValueError`.
    #[must_use]
    pub fn is_value_error(&self) -> bool {
        self.kind == ErrorKind::Value
    }

    #[must_use]
    pub fn value() -> Self {
        Self::new(
            ErrorKind::Value,
            None,
            "Timeout must be a finite number greater than or equal to zero.",
            0,
        )
    }

    #[must_use]
    pub fn invalid_argument(message: impl Into<String>) -> Self {
        Self::new(ErrorKind::InvalidArgument, None, message, 0)
    }

    #[must_use]
    pub fn adapter_initialization(
        request: Request<Bytes>,
        message: impl Into<String>,
        code: i32,
    ) -> Self {
        Self::new(
            ErrorKind::AdapterInitialization,
            Some(request),
            message,
            code,
        )
    }

    #[must_use]
    pub fn adapter_precondition(request: Request<Bytes>, message: impl Into<String>) -> Self {
        Self::new(ErrorKind::AdapterPrecondition, Some(request), message, 0)
    }

    #[must_use]
    pub fn invalid_uri(request: Request<Bytes>, message: impl Into<String>) -> Self {
        Self::new(ErrorKind::InvalidUri, Some(request), message, 0)
    }

    #[must_use]
    pub fn invalid_response(request: Request<Bytes>, message: impl Into<String>) -> Self {
        Self::new(ErrorKind::InvalidResponse, Some(request), message, 0)
    }

    #[must_use]
    pub fn request(request: Request<Bytes>, message: impl Into<String>, code: i32) -> Self {
        Self::new(ErrorKind::Request, Some(request), message, code)
    }

    #[must_use]
    pub fn network(request: Request<Bytes>, message: impl Into<String>, code: i32) -> Self {
        Self::new(ErrorKind::Network, Some(request), message, code)
    }

    #[must_use]
    pub fn dns(request: Request<Bytes>, message: impl Into<String>, code: i32) -> Self {
        Self::new(ErrorKind::Dns, Some(request), message, code)
    }

    #[must_use]
    pub fn timeout(request: Request<Bytes>, message: impl Into<String>, code: i32) -> Self {
        Self::new(ErrorKind::Timeout, Some(request), message, code)
    }

    #[must_use]
    pub fn protocol(request: Request<Bytes>, message: impl Into<String>, code: i32) -> Self {
        Self::new(ErrorKind::Protocol, Some(request), message, code)
    }

    #[must_use]
    pub fn proxy(request: Request<Bytes>, message: impl Into<String>, code: i32) -> Self {
        Self::new(ErrorKind::Proxy, Some(request), message, code)
    }

    #[must_use]
    pub fn connection(request: Request<Bytes>, message: impl Into<String>, code: i32) -> Self {
        Self::new(ErrorKind::Connection, Some(request), message, code)
    }

    #[must_use]
    pub fn tls(request: Request<Bytes>, message: impl Into<String>, code: i32) -> Self {
        Self::new(ErrorKind::Tls, Some(request), message, code)
    }

    #[must_use]
    pub fn with_source(
        mut self,
        source: impl Into<Box<dyn std::error::Error + Send + Sync>>,
    ) -> Self {
        self.source = Some(source.into());
        self
    }
}

impl fmt::Display for Error {
    fn fmt(&self, formatter: &mut fmt::Formatter<'_>) -> fmt::Result {
        formatter.write_str(&self.message)
    }
}

impl std::error::Error for Error {
    fn source(&self) -> Option<&(dyn std::error::Error + 'static)> {
        Some(&**self.source.as_ref()?)
    }
}

impl From<utopia_pools::PoolError> for Error {
    fn from(error: utopia_pools::PoolError) -> Self {
        Self {
            kind: ErrorKind::Pool,
            message: error.to_string(),
            code: 0,
            request: None,
            source: Some(Box::new(error)),
        }
    }
}

/// PHP `Utopia\Client\Exception\RequestException`.
pub type RequestException = Error;
/// PHP `Utopia\Client\Exception\AdapterInitializationException`.
pub type AdapterInitializationException = Error;
/// PHP `Utopia\Client\Exception\AdapterPreconditionException`.
pub type AdapterPreconditionException = Error;
/// PHP `Utopia\Client\Exception\InvalidResponseException`.
pub type InvalidResponseException = Error;
/// PHP `Utopia\Client\Exception\InvalidUriException`.
pub type InvalidUriException = Error;
/// PHP `Utopia\Client\Exception\NetworkException`.
pub type NetworkException = Error;
/// PHP `Utopia\Client\Exception\ConnectionException`.
pub type ConnectionException = Error;
/// PHP `Utopia\Client\Exception\DnsException`.
pub type DnsException = Error;
/// PHP `Utopia\Client\Exception\ProtocolException`.
pub type ProtocolException = Error;
/// PHP `Utopia\Client\Exception\ProxyException`.
pub type ProxyException = Error;
/// PHP `Utopia\Client\Exception\TimeoutException`.
pub type TimeoutException = Error;
/// PHP `Utopia\Client\Exception\TlsException`.
pub type TlsException = Error;
