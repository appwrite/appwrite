//! PHP `tests/Client/DecoratorTest.php`.

mod support;

use bytes::Bytes;
use http::{Request, Response, StatusCode};
use support::request;
use utopia_client::{Adapter, Decorator, Error, StreamingClient, Tls};

#[derive(Clone, Debug)]
struct SwappableAdapter {
    status: u16,
}

impl SwappableAdapter {
    fn new(status: u16) -> Self {
        Self { status }
    }
}

impl StreamingClient for SwappableAdapter {
    fn send_request(&self, _request: Request<Bytes>) -> Result<Response<Bytes>, Error> {
        Ok(Response::builder()
            .status(StatusCode::from_u16(self.status).unwrap())
            .body(Bytes::new())
            .unwrap())
    }

    fn stream(
        &self,
        request: Request<Bytes>,
        sink: &mut dyn FnMut(&[u8]),
    ) -> Result<Response<Bytes>, Error> {
        sink(b"chunk");
        self.send_request(request)
    }
}

impl Adapter for SwappableAdapter {
    fn with_timeout(&self, _seconds: f64) -> Result<Self, Error> {
        Ok(Self { status: 299 })
    }

    fn with_connect_timeout(&self, _seconds: f64) -> Result<Self, Error> {
        Ok(self.clone())
    }

    fn with_ssl_verification(&self, _enabled: bool) -> Self {
        self.clone()
    }

    fn with_custom_ca(&self, _path: impl Into<String>) -> Self {
        self.clone()
    }

    fn with_certificate(
        &self,
        _cert_path: impl Into<String>,
        _key_path: impl Into<String>,
        _passphrase: Option<String>,
    ) -> Self {
        self.clone()
    }

    fn with_min_tls_version(&self, _version: Tls) -> Self {
        self.clone()
    }

    fn with_connection_reuse(&self, _enabled: bool) -> Self {
        self.clone()
    }
}

#[test]
fn it_delegates_send_request_to_the_inner_adapter() {
    let decorator = Decorator::new(SwappableAdapter::new(200));
    assert_eq!(
        decorator
            .send_request(request("GET", "https://example.com"))
            .unwrap()
            .status(),
        200
    );
}

#[test]
fn it_delegates_stream_request_to_the_inner_adapter() {
    let decorator = Decorator::new(SwappableAdapter::new(200));
    let mut received = Vec::new();
    let response = decorator
        .stream(request("GET", "https://example.com"), &mut |chunk| {
            received.extend_from_slice(chunk);
        })
        .unwrap();
    assert_eq!(received, b"chunk");
    assert_eq!(response.status(), 200);
}

#[test]
fn it_forwards_configuration_to_a_reconfigured_inner_clone() {
    let decorator = Decorator::new(SwappableAdapter::new(200));
    let configured = decorator.with_timeout(5.0).unwrap();
    assert_eq!(
        decorator
            .send_request(request("GET", "https://example.com"))
            .unwrap()
            .status(),
        200
    );
    assert_eq!(
        configured
            .send_request(request("GET", "https://example.com"))
            .unwrap()
            .status(),
        299
    );
}
