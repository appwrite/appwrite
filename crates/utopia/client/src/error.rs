//! The errors a client raises, one variant per PHP exception class.

/// An error sending a request or configuring a client.
///
/// The variants map one to one onto PHP's exception classes (see
/// [`Error::php_class`]), and carry the same message. The network variants
/// ([`Error::is_network`]) are the transient failures a retry may cure: the
/// request may not have reached the server, or its answer did not come back.
///
/// PHP: `Utopia\Client\Exception\*`, with `InvalidArgumentException` and
/// `ValueError` for invalid configuration. PSR-18's
/// `RequestExceptionInterface` is [`Error::is_request`] and
/// `NetworkExceptionInterface` is [`Error::is_network`].
#[derive(Debug, Clone, PartialEq, Eq, thiserror::Error)]
pub enum Error {
    /// The request itself is invalid; sending it again cannot help.
    ///
    /// PHP: `RequestException`.
    #[error("{0}")]
    Request(String),
    /// The transport could not be set up.
    ///
    /// PHP: `AdapterInitializationException`.
    #[error("{0}")]
    AdapterInitialization(String),
    /// Something the transport needs is missing.
    ///
    /// PHP: `AdapterPreconditionException` (a missing `curl` or `swoole`
    /// extension; nothing in Rust raises it).
    #[error("{0}")]
    AdapterPrecondition(String),
    /// The server answered with something that is not an HTTP response.
    ///
    /// PHP: `InvalidResponseException`.
    #[error("{0}")]
    InvalidResponse(String),
    /// The request URI (or a redirect's) is not an absolute HTTP URI.
    ///
    /// PHP: `InvalidUriException`.
    #[error("{0}")]
    InvalidUri(String),
    /// Any other network failure.
    ///
    /// PHP: `NetworkException`.
    #[error("{0}")]
    Network(String),
    /// The connection failed, or the server closed it.
    ///
    /// PHP: `ConnectionException`.
    #[error("{0}")]
    Connection(String),
    /// The connection reached an address the destinations do not allow, and
    /// nothing was sent.
    ///
    /// PHP: `DestinationException`.
    #[error("{0}")]
    Destination(String),
    /// The host name did not resolve.
    ///
    /// PHP: `DnsException`.
    #[error("{0}")]
    Dns(String),
    /// The server broke the HTTP protocol (a malformed or truncated
    /// response), or redirected too many times.
    ///
    /// PHP: `ProtocolException`.
    #[error("{0}")]
    Protocol(String),
    /// The proxy failed.
    ///
    /// PHP: `ProxyException`.
    #[error("{0}")]
    Proxy(String),
    /// The request took longer than its timeout.
    ///
    /// PHP: `TimeoutException`.
    #[error("{0}")]
    Timeout(String),
    /// The TLS handshake failed (an untrusted certificate, a name mismatch).
    ///
    /// PHP: `TlsException`.
    #[error("{0}")]
    Tls(String),
    /// An invalid argument: a relative base URI, an invalid header name, a
    /// file that cannot be read.
    ///
    /// PHP: `InvalidArgumentException`.
    #[error("{0}")]
    InvalidArgument(String),
    /// An out-of-range configuration value: a negative timeout or hop limit.
    ///
    /// PHP: `ValueError`.
    #[error("{0}")]
    Value(String),
    /// A [`Pool`](crate::Pool) had no adapter to lend in time, or could not
    /// create one.
    ///
    /// PHP: the `Exception` `utopia-php/pools` throws.
    #[error("{0}")]
    Pool(String),
    /// A response header named like an integer (`123`): PHP keys its header
    /// array by name, so the name becomes an `int` the PSR-7 message refuses.
    ///
    /// PHP: the `TypeError` `Message::withAddedHeader()` throws.
    #[error("{0}")]
    Type(String),
}

impl Error {
    /// The PHP exception class this error corresponds to.
    pub fn php_class(&self) -> &'static str {
        match self {
            Error::Request(_) => "Utopia\\Client\\Exception\\RequestException",
            Error::AdapterInitialization(_) => "Utopia\\Client\\Exception\\AdapterInitializationException",
            Error::AdapterPrecondition(_) => "Utopia\\Client\\Exception\\AdapterPreconditionException",
            Error::InvalidResponse(_) => "Utopia\\Client\\Exception\\InvalidResponseException",
            Error::InvalidUri(_) => "Utopia\\Client\\Exception\\InvalidUriException",
            Error::Network(_) => "Utopia\\Client\\Exception\\NetworkException",
            Error::Connection(_) => "Utopia\\Client\\Exception\\ConnectionException",
            Error::Destination(_) => "Utopia\\Client\\Exception\\DestinationException",
            Error::Dns(_) => "Utopia\\Client\\Exception\\DnsException",
            Error::Protocol(_) => "Utopia\\Client\\Exception\\ProtocolException",
            Error::Proxy(_) => "Utopia\\Client\\Exception\\ProxyException",
            Error::Timeout(_) => "Utopia\\Client\\Exception\\TimeoutException",
            Error::Tls(_) => "Utopia\\Client\\Exception\\TlsException",
            Error::InvalidArgument(_) => "InvalidArgumentException",
            Error::Value(_) => "ValueError",
            Error::Pool(_) => "Exception",
            Error::Type(_) => "TypeError",
        }
    }

    /// The message, as PHP's `getMessage()`.
    pub fn message(&self) -> &str {
        match self {
            Error::Request(m)
            | Error::AdapterInitialization(m)
            | Error::AdapterPrecondition(m)
            | Error::InvalidResponse(m)
            | Error::InvalidUri(m)
            | Error::Network(m)
            | Error::Connection(m)
            | Error::Destination(m)
            | Error::Dns(m)
            | Error::Protocol(m)
            | Error::Proxy(m)
            | Error::Timeout(m)
            | Error::Tls(m)
            | Error::InvalidArgument(m)
            | Error::Value(m)
            | Error::Pool(m)
            | Error::Type(m) => m,
        }
    }

    /// Whether the request failed in the network: the transient failures a
    /// retry may cure.
    ///
    /// PHP: `instanceof Psr\Http\Client\NetworkExceptionInterface`.
    pub fn is_network(&self) -> bool {
        matches!(
            self,
            Error::Network(_)
                | Error::Connection(_)
                | Error::Destination(_)
                | Error::Dns(_)
                | Error::Protocol(_)
                | Error::Proxy(_)
                | Error::Timeout(_)
                | Error::Tls(_)
        )
    }

    /// Whether the request itself is at fault.
    ///
    /// PHP: `instanceof Psr\Http\Client\RequestExceptionInterface`.
    pub fn is_request(&self) -> bool {
        matches!(
            self,
            Error::Request(_)
                | Error::AdapterInitialization(_)
                | Error::AdapterPrecondition(_)
                | Error::InvalidResponse(_)
                | Error::InvalidUri(_)
        )
    }

    /// Whether this is an error a client raises while sending (PSR-18's
    /// `ClientExceptionInterface`): every variant but the configuration ones.
    pub fn is_client(&self) -> bool {
        self.is_network() || self.is_request()
    }
}
