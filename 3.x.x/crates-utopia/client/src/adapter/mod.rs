//! PHP `Utopia\Client\Adapter`.

pub mod curl;
pub mod swoole_coroutine;

pub(crate) mod common;

use bytes::Bytes;
use http::{Request, Response};

use crate::psr18::StreamingClientInterface;
use crate::{Error, Tls};

/// PSR-18 `sendRequest` plus streaming.
///
/// PHP intersection of `ClientInterface` and `StreamingClientInterface`.
pub trait StreamingClient: Send + Sync {
    fn send_request(&self, request: Request<Bytes>) -> Result<Response<Bytes>, Error>;

    fn stream(
        &self,
        request: Request<Bytes>,
        sink: &mut dyn FnMut(&[u8]),
    ) -> Result<Response<Bytes>, Error>;
}

impl<T: StreamingClient + ?Sized> StreamingClientInterface for T {
    fn stream(
        &self,
        request: Request<Bytes>,
        sink: &mut dyn FnMut(&[u8]),
    ) -> Result<Response<Bytes>, Error> {
        StreamingClient::stream(self, request, sink)
    }
}

/// PHP `Utopia\Client\Adapter`.
pub trait Adapter: StreamingClient + Clone {
    fn with_timeout(&self, seconds: f64) -> Result<Self, Error>
    where
        Self: Sized;

    fn with_connect_timeout(&self, seconds: f64) -> Result<Self, Error>
    where
        Self: Sized;

    fn with_ssl_verification(&self, enabled: bool) -> Self;

    fn with_custom_ca(&self, path: impl Into<String>) -> Self;

    fn with_certificate(
        &self,
        cert_path: impl Into<String>,
        key_path: impl Into<String>,
        passphrase: Option<String>,
    ) -> Self;

    fn with_min_tls_version(&self, version: Tls) -> Self;

    fn with_connection_reuse(&self, enabled: bool) -> Self;
}
