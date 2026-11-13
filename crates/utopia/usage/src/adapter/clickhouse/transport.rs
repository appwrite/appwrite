//! How the adapter reaches ClickHouse: one HTTP `POST` at a time, through
//! whatever sends it.

use async_trait::async_trait;

/// A `POST` to ClickHouse's HTTP interface.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Request {
    /// The absolute URL, query string included.
    pub url: String,
    /// Header names and values, in the order they were set.
    pub headers: Vec<(String, String)>,
    /// The request body.
    pub body: Vec<u8>,
}

/// What ClickHouse answered. Any status is a response; only a request that
/// could not be answered is an error.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Response {
    /// The HTTP status code.
    pub status: u16,
    /// The whole response body.
    pub body: Vec<u8>,
}

/// Sends the adapter's requests. Timeouts, TLS, pooling and retries are the
/// transport's; it must not retry an insert, which is not idempotent.
///
/// PHP: the `Psr\Http\Client\ClientInterface` the adapter is given.
#[async_trait]
pub trait Transport: Send + Sync {
    /// Sends `request` and reads the whole response. The error is the
    /// reason the request could not be answered, as the adapter reports it.
    async fn send(&self, request: Request) -> Result<Response, String>;
}
