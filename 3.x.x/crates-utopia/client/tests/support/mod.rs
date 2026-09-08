//! Shared test helpers.
#![allow(dead_code)]

use bytes::Bytes;
use http::{Request, Response, StatusCode, Uri};
use utopia_client::{Adapter, Error, RelativeUri, StreamingClient, Tls};

pub mod http_server;

fn header_line(request: &Request<Bytes>, name: &str) -> Option<String> {
    let values: Vec<&str> = request
        .headers()
        .get_all(name)
        .iter()
        .filter_map(|value| value.to_str().ok())
        .collect();
    if values.is_empty() {
        None
    } else {
        Some(values.join(", "))
    }
}

/// Parse a PHP-style request target. Path-rootless refs (`users?x=1`) are not
/// valid `http::Uri` values; they are attached as [`RelativeUri`].
pub fn parse_target(uri: &str) -> Uri {
    uri.parse().unwrap_or_else(|_| Uri::from_static("/"))
}

pub fn request(method: &str, uri: &str) -> Request<Bytes> {
    let mut request = Request::builder()
        .method(method)
        .uri(parse_target(uri))
        .body(Bytes::new())
        .expect("request");
    if uri.parse::<Uri>().is_err() {
        request.extensions_mut().insert(RelativeUri(uri.to_owned()));
    }
    request
}

pub fn request_body(method: &str, uri: &str, body: impl Into<Bytes>) -> Request<Bytes> {
    let mut request = Request::builder()
        .method(method)
        .uri(parse_target(uri))
        .body(body.into())
        .expect("request");
    if uri.parse::<Uri>().is_err() {
        request.extensions_mut().insert(RelativeUri(uri.to_owned()));
    }
    request
}

pub fn response(status: u16) -> Response<Bytes> {
    Response::builder()
        .status(StatusCode::from_u16(status).unwrap())
        .body(Bytes::new())
        .unwrap()
}

/// PHP `RecordingAdapter` from ClientTest.php.
#[derive(Clone, Debug, Default)]
pub struct RecordingAdapter {
    timeout: Option<f64>,
    connect_timeout: Option<f64>,
    ssl_verification: Option<bool>,
    custom_ca: Option<String>,
    certificate: Option<String>,
    min_tls_version: Option<Tls>,
    connection_reuse: Option<bool>,
}

impl RecordingAdapter {
    pub fn new() -> Self {
        Self::default()
    }
}

impl StreamingClient for RecordingAdapter {
    fn send_request(&self, request: Request<Bytes>) -> Result<Response<Bytes>, Error> {
        let mut builder = Response::builder().status(200);
        {
            let headers = builder.headers_mut().unwrap();
            if let Ok(value) = request.uri().to_string().parse() {
                headers.insert("x-request-uri", value);
            }
            if let Some(host) = header_line(&request, "host") {
                if let Ok(value) = host.parse() {
                    headers.insert("x-request-host", value);
                }
            }
            if let Some(accept) = header_line(&request, "accept") {
                if let Ok(value) = accept.parse() {
                    headers.insert("x-request-accept", value);
                }
            }
            if let Some(auth) = header_line(&request, "authorization") {
                if let Ok(value) = auth.parse() {
                    headers.insert("x-request-authorization", value);
                }
            }
            if let Some(trace) = header_line(&request, "x-trace") {
                if let Ok(value) = trace.parse() {
                    headers.insert("x-request-trace", value);
                }
            }
            if let Some(traceparent) = header_line(&request, "traceparent") {
                if let Ok(value) = traceparent.parse() {
                    headers.insert("x-request-traceparent", value);
                }
            }
            if let Some(timeout) = self.timeout {
                headers.insert("x-timeout", timeout.to_string().parse().unwrap());
            }
            if let Some(verify) = self.ssl_verification {
                headers.insert(
                    "x-tls-verify",
                    if verify { "on" } else { "off" }.parse().unwrap(),
                );
            }
            if let Some(ca) = &self.custom_ca {
                headers.insert("x-tls-ca", ca.parse().unwrap());
            }
            if let Some(cert) = &self.certificate {
                headers.insert("x-tls-cert", cert.parse().unwrap());
            }
            if let Some(version) = self.min_tls_version {
                headers.insert("x-tls-min-version", version.name().parse().unwrap());
            }
            if let Some(reuse) = self.connection_reuse {
                headers.insert(
                    "x-connection-reuse",
                    if reuse { "on" } else { "off" }.parse().unwrap(),
                );
            }
            if let Some(connect) = self.connect_timeout {
                headers.insert("x-connect-timeout", connect.to_string().parse().unwrap());
            }
        }
        Ok(builder.body(Bytes::new()).unwrap())
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

impl Adapter for RecordingAdapter {
    fn with_timeout(&self, seconds: f64) -> Result<Self, Error> {
        if !seconds.is_finite() || seconds < 0.0 {
            return Err(Error::value());
        }
        let mut clone = self.clone();
        clone.timeout = Some(seconds);
        Ok(clone)
    }

    fn with_connect_timeout(&self, seconds: f64) -> Result<Self, Error> {
        if !seconds.is_finite() || seconds < 0.0 {
            return Err(Error::value());
        }
        let mut clone = self.clone();
        clone.connect_timeout = Some(seconds);
        Ok(clone)
    }

    fn with_ssl_verification(&self, enabled: bool) -> Self {
        let mut clone = self.clone();
        clone.ssl_verification = Some(enabled);
        clone
    }

    fn with_custom_ca(&self, path: impl Into<String>) -> Self {
        let mut clone = self.clone();
        clone.custom_ca = Some(path.into());
        clone
    }

    fn with_certificate(
        &self,
        cert_path: impl Into<String>,
        key_path: impl Into<String>,
        passphrase: Option<String>,
    ) -> Self {
        let mut clone = self.clone();
        let mut value = format!("{}:{}", cert_path.into(), key_path.into());
        if let Some(passphrase) = passphrase {
            value.push(':');
            value.push_str(&passphrase);
        }
        clone.certificate = Some(value);
        clone
    }

    fn with_min_tls_version(&self, version: Tls) -> Self {
        let mut clone = self.clone();
        clone.min_tls_version = Some(version);
        clone
    }

    fn with_connection_reuse(&self, enabled: bool) -> Self {
        let mut clone = self.clone();
        clone.connection_reuse = Some(enabled);
        clone
    }
}

impl utopia_pools::Recover for RecordingAdapter {}
