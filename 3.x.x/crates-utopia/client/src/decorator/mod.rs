//! PHP `Utopia\Client\Decorator`.

pub mod retry;

use bytes::Bytes;
use http::{Request, Response};
use utopia_pools::Recover;

use crate::{Adapter, Error, StreamingClient, Tls};

pub use retry::{Backoff, Retry, Strategy};

/// PHP `Utopia\Client\Decorator`.
#[derive(Clone, Debug)]
pub struct Decorator<A: Adapter> {
    adapter: A,
}

impl<A: Adapter> Decorator<A> {
    pub fn new(adapter: A) -> Self {
        Self { adapter }
    }

    fn wrap(&self, adapter: A) -> Self {
        Self { adapter }
    }
}

impl<A: Adapter> StreamingClient for Decorator<A> {
    fn send_request(&self, request: Request<Bytes>) -> Result<Response<Bytes>, Error> {
        self.adapter.send_request(request)
    }

    fn stream(
        &self,
        request: Request<Bytes>,
        sink: &mut dyn FnMut(&[u8]),
    ) -> Result<Response<Bytes>, Error> {
        self.adapter.stream(request, sink)
    }
}

impl<A: Adapter> Adapter for Decorator<A> {
    fn with_timeout(&self, seconds: f64) -> Result<Self, Error> {
        Ok(self.wrap(self.adapter.with_timeout(seconds)?))
    }

    fn with_connect_timeout(&self, seconds: f64) -> Result<Self, Error> {
        Ok(self.wrap(self.adapter.with_connect_timeout(seconds)?))
    }

    fn with_ssl_verification(&self, enabled: bool) -> Self {
        self.wrap(self.adapter.with_ssl_verification(enabled))
    }

    fn with_custom_ca(&self, path: impl Into<String>) -> Self {
        self.wrap(self.adapter.with_custom_ca(path))
    }

    fn with_certificate(
        &self,
        cert_path: impl Into<String>,
        key_path: impl Into<String>,
        passphrase: Option<String>,
    ) -> Self {
        self.wrap(
            self.adapter
                .with_certificate(cert_path, key_path, passphrase),
        )
    }

    fn with_min_tls_version(&self, version: Tls) -> Self {
        self.wrap(self.adapter.with_min_tls_version(version))
    }

    fn with_connection_reuse(&self, enabled: bool) -> Self {
        self.wrap(self.adapter.with_connection_reuse(enabled))
    }
}

impl<A: Adapter + Recover> Recover for Decorator<A> {}
