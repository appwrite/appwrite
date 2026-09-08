//! PHP `Utopia\Client\Response\Builder`.

use bytes::Bytes;
use http::{HeaderMap, Response, StatusCode, Version};

use crate::Error;

/// Builds an `http` response from status, headers, and body.
///
/// PHP injects PSR-17 factories; Rust constructs [`http::Response`] directly.
/// Custom reason phrases are dropped - the `http` crate has no reason-phrase field.
#[derive(Debug, Clone, Copy, Default)]
pub struct Builder;

impl Builder {
    #[must_use]
    pub fn new() -> Self {
        Self
    }

    /// PHP `Builder::build($statusCode, $reasonPhrase, $headers, $body, $protocolVersion = '1.1')`.
    pub fn build(
        &self,
        status_code: u16,
        _reason_phrase: &str,
        headers: HeaderMap,
        body: impl Into<Bytes>,
        protocol_version: &str,
    ) -> Result<Response<Bytes>, Error> {
        let status = StatusCode::from_u16(status_code).map_err(|_| {
            Error::invalid_argument(format!("invalid HTTP status code {status_code}"))
        })?;
        let version = parse_version(protocol_version);
        let mut response = Response::builder()
            .status(status)
            .version(version)
            .body(body.into())
            .map_err(|error| Error::invalid_argument(error.to_string()))?;
        *response.headers_mut() = headers;
        Ok(response)
    }
}

fn parse_version(value: &str) -> Version {
    match value {
        "1.0" => Version::HTTP_10,
        "2" | "2.0" => Version::HTTP_2,
        "3" | "3.0" => Version::HTTP_3,
        _ => Version::HTTP_11,
    }
}
