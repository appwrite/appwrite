use std::fmt::Write as _;
use std::io::{self, Read};
use std::time::Duration;

use bytes::Bytes;
use flate2::read::{DeflateDecoder, GzDecoder};
use std::error::Error as StdError;

use http::{header, HeaderMap, Request, Response};
use reqwest::redirect::Policy;

use crate::response::Builder as ResponseBuilder;
use crate::{Error, Tls};

pub(crate) const DEFAULT_TIMEOUT: f64 = 30.0;
pub(crate) const DEFAULT_CONNECT_TIMEOUT: f64 = 5.0;

pub(crate) fn require_finite_timeout(seconds: f64) -> Result<Duration, Error> {
    if !seconds.is_finite() || seconds < 0.0 {
        return Err(Error::value());
    }
    Ok(Duration::from_millis((seconds * 1000.0).round() as u64))
}

pub(crate) fn require_absolute_http(request: &Request<Bytes>) -> Result<(), Error> {
    let scheme = request.uri().scheme_str().unwrap_or("");
    let host = request.uri().host().unwrap_or("");
    if (scheme != "http" && scheme != "https") || host.is_empty() {
        return Err(Error::invalid_uri(
            request.clone(),
            "Requests must use an absolute URI.",
        ));
    }
    Ok(())
}

pub(crate) fn auto_decompress(request: &Request<Bytes>) -> bool {
    !request.headers().contains_key(header::ACCEPT_ENCODING)
}

#[derive(Clone, Debug)]
pub(crate) struct TransportConfig {
    pub timeout: Duration,
    pub connect_timeout: Duration,
    pub ssl_verify: bool,
    pub ca_path: Option<String>,
    pub certificate: Option<(String, String, Option<String>)>,
    pub min_tls: Option<Tls>,
    pub reuse: bool,
    pub proxy: Option<String>,
    pub invalid: bool,
}

impl Default for TransportConfig {
    fn default() -> Self {
        Self {
            timeout: Duration::from_secs_f64(DEFAULT_TIMEOUT),
            connect_timeout: Duration::from_secs_f64(DEFAULT_CONNECT_TIMEOUT),
            ssl_verify: true,
            ca_path: None,
            certificate: None,
            min_tls: None,
            reuse: false,
            proxy: None,
            invalid: false,
        }
    }
}

impl TransportConfig {
    pub(crate) fn apply_timeout(&self, seconds: f64) -> Result<Self, Error> {
        let mut clone = self.clone();
        clone.timeout = require_finite_timeout(seconds)?;
        Ok(clone)
    }

    pub(crate) fn apply_connect_timeout(&self, seconds: f64) -> Result<Self, Error> {
        let mut clone = self.clone();
        clone.connect_timeout = require_finite_timeout(seconds)?;
        Ok(clone)
    }
}

pub(crate) fn configure_reqwest(
    builder: reqwest::ClientBuilder,
    config: &TransportConfig,
) -> Result<reqwest::ClientBuilder, Error> {
    apply_transport(builder, config)
}

pub(crate) fn configure_blocking(
    builder: reqwest::blocking::ClientBuilder,
    config: &TransportConfig,
) -> Result<reqwest::blocking::ClientBuilder, Error> {
    apply_transport(builder, config)
}

fn apply_transport<B>(mut builder: B, config: &TransportConfig) -> Result<B, Error>
where
    B: TransportBuilder,
{
    builder = builder
        .redirect_none()
        .http1_only()
        .timeout(config.timeout)
        .connect_timeout(config.connect_timeout)
        .pool_max_idle_per_host(if config.reuse { usize::MAX } else { 0 })
        .no_compression();

    if !config.ssl_verify {
        builder = builder.danger_accept_invalid_certs(true);
    }

    if let Some(path) = &config.ca_path {
        let pem = std::fs::read(path).map_err(|error| {
            Error::invalid_argument(format!("Unable to read custom CA: {error}"))
        })?;
        let cert = reqwest::Certificate::from_pem(&pem).map_err(|error| {
            Error::invalid_argument(format!("Unable to parse custom CA: {error}"))
        })?;
        builder = builder.add_root_certificate(cert);
    }

    if let Some((cert_path, key_path, passphrase)) = &config.certificate {
        if passphrase.is_some() {
            return Err(Error::invalid_argument(
                "Encrypted client certificates (passphrase) are not supported with rustls.",
            ));
        }
        let mut pem = std::fs::read(cert_path).map_err(|error| {
            Error::invalid_argument(format!("Unable to read client certificate: {error}"))
        })?;
        pem.extend_from_slice(&std::fs::read(key_path).map_err(|error| {
            Error::invalid_argument(format!("Unable to read client key: {error}"))
        })?);
        let identity = reqwest::Identity::from_pem(&pem).map_err(|error| {
            Error::invalid_argument(format!("Unable to parse client certificate: {error}"))
        })?;
        builder = builder.identity(identity);
    }

    if let Some(version) = config.min_tls {
        builder = builder.min_tls_version(version.reqwest());
    }

    if let Some(proxy) = &config.proxy {
        let proxy = reqwest::Proxy::all(proxy).map_err(|error| {
            Error::invalid_argument(format!("Unable to configure proxy: {error}"))
        })?;
        builder = builder.proxy(proxy);
    }

    Ok(builder)
}

trait TransportBuilder: Sized {
    fn redirect_none(self) -> Self;
    fn http1_only(self) -> Self;
    fn timeout(self, timeout: Duration) -> Self;
    fn connect_timeout(self, timeout: Duration) -> Self;
    fn pool_max_idle_per_host(self, max: usize) -> Self;
    fn no_compression(self) -> Self;
    fn danger_accept_invalid_certs(self, accept: bool) -> Self;
    fn add_root_certificate(self, cert: reqwest::Certificate) -> Self;
    fn identity(self, identity: reqwest::Identity) -> Self;
    fn min_tls_version(self, version: reqwest::tls::Version) -> Self;
    fn proxy(self, proxy: reqwest::Proxy) -> Self;
}

macro_rules! impl_transport_builder {
    ($ty:ty) => {
        impl TransportBuilder for $ty {
            fn redirect_none(self) -> Self {
                self.redirect(Policy::none())
            }
            fn http1_only(self) -> Self {
                self.http1_only()
            }
            fn timeout(self, timeout: Duration) -> Self {
                self.timeout(timeout)
            }
            fn connect_timeout(self, timeout: Duration) -> Self {
                self.connect_timeout(timeout)
            }
            fn pool_max_idle_per_host(self, max: usize) -> Self {
                self.pool_max_idle_per_host(max)
            }
            fn no_compression(self) -> Self {
                self.no_gzip().no_deflate().no_brotli()
            }
            fn danger_accept_invalid_certs(self, accept: bool) -> Self {
                self.danger_accept_invalid_certs(accept)
            }
            fn add_root_certificate(self, cert: reqwest::Certificate) -> Self {
                self.add_root_certificate(cert)
            }
            fn identity(self, identity: reqwest::Identity) -> Self {
                self.identity(identity)
            }
            fn min_tls_version(self, version: reqwest::tls::Version) -> Self {
                self.min_tls_version(version)
            }
            fn proxy(self, proxy: reqwest::Proxy) -> Self {
                self.proxy(proxy)
            }
        }
    };
}

impl_transport_builder!(reqwest::ClientBuilder);
impl_transport_builder!(reqwest::blocking::ClientBuilder);

pub(crate) fn request_url(request: &Request<Bytes>) -> String {
    let uri = request.uri();
    let mut url = uri.to_string();
    if uri.path().is_empty() {
        if let Some(query) = uri.query() {
            let authority = uri.authority().map(ToString::to_string).unwrap_or_default();
            let scheme = uri.scheme_str().unwrap_or("http");
            url = format!("{scheme}://{authority}/?{query}");
        }
    }
    url
}

pub(crate) fn reqwest_method(request: &Request<Bytes>) -> Result<reqwest::Method, Error> {
    reqwest::Method::from_bytes(request.method().as_str().as_bytes())
        .map_err(|error| Error::invalid_argument(error.to_string()))
}

pub(crate) fn drop_decoded_headers(headers: &mut HeaderMap) {
    let encoded = headers.keys().any(|name| name == header::CONTENT_ENCODING);
    if !encoded {
        return;
    }
    headers.remove(header::CONTENT_ENCODING);
    headers.remove(header::CONTENT_LENGTH);
}

pub(crate) fn content_encoding(headers: &HeaderMap) -> Option<String> {
    headers
        .get(header::CONTENT_ENCODING)
        .and_then(|value| value.to_str().ok())
        .map(|value| value.trim().to_ascii_lowercase())
}

pub(crate) fn decode_body(encoding: &str, body: &[u8]) -> io::Result<Vec<u8>> {
    let mut out = Vec::new();
    match encoding {
        "gzip" | "x-gzip" => {
            GzDecoder::new(body).read_to_end(&mut out)?;
        }
        "deflate" => {
            DeflateDecoder::new(body).read_to_end(&mut out)?;
        }
        "br" => {
            brotli::Decompressor::new(body, 4096).read_to_end(&mut out)?;
        }
        _ => return Ok(body.to_vec()),
    }
    Ok(out)
}

pub(crate) fn decode_read<'a, R: Read + 'a>(encoding: &str, reader: R) -> Box<dyn Read + 'a> {
    match encoding {
        "gzip" | "x-gzip" => Box::new(GzDecoder::new(reader)),
        "deflate" => Box::new(DeflateDecoder::new(reader)),
        "br" => Box::new(brotli::Decompressor::new(reader, 4096)),
        _ => Box::new(reader),
    }
}

pub(crate) fn copy_chunks<R: Read>(mut reader: R, sink: &mut dyn FnMut(&[u8])) -> io::Result<()> {
    let mut buf = [0u8; 8192];
    loop {
        let read = reader.read(&mut buf)?;
        if read == 0 {
            break;
        }
        sink(&buf[..read]);
    }
    Ok(())
}

pub(crate) fn finish_response(
    status: u16,
    version: http::Version,
    mut headers: HeaderMap,
    body: Bytes,
    decompress: bool,
    drop_encoding_on_any: bool,
) -> Result<Response<Bytes>, Error> {
    if !(100..=599).contains(&status) {
        return Err(Error::invalid_argument(
            "Received an invalid HTTP response.",
        ));
    }
    if decompress
        && (drop_encoding_on_any
            || matches!(
                content_encoding(&headers).as_deref(),
                Some("gzip" | "x-gzip" | "deflate" | "br")
            ))
    {
        drop_decoded_headers(&mut headers);
    }
    let protocol = match version {
        http::Version::HTTP_10 => "1.0",
        http::Version::HTTP_2 => "2",
        http::Version::HTTP_3 => "3",
        _ => "1.1",
    };
    ResponseBuilder::new().build(status, "", headers, body, protocol)
}

pub(crate) fn map_reqwest(request: &Request<Bytes>, error: reqwest::Error) -> Error {
    let message = error.to_string();
    let mut combined = message.clone();
    let mut source = StdError::source(&error);
    while let Some(item) = source {
        combined.push(' ');
        combined.push_str(&item.to_string());
        source = item.source();
    }
    let lower = combined.to_ascii_lowercase();

    if error.is_timeout()
        || lower.contains("timed out")
        || lower.contains("timeout")
        || lower.contains("deadline")
    {
        return Error::timeout(request.clone(), message, 0).with_source(error);
    }

    if lower.contains("dns")
        || lower.contains("failed to lookup")
        || lower.contains("no such host")
        || lower.contains("name or service not known")
        || lower.contains("nodename nor servname")
        || lower.contains("temporary failure in name resolution")
        || lower.contains("name resolution")
    {
        return Error::dns(request.clone(), message, 0).with_source(error);
    }

    if lower.contains("certificate")
        || lower.contains("cert")
        || lower.contains("tls")
        || lower.contains("ssl")
        || lower.contains("handshake")
        || lower.contains("rustls")
        || lower.contains("pkix")
        || lower.contains("unknown issuer")
        || lower.contains("corrupt message")
        || lower.contains("invalid content type")
        || lower.contains("unexpected message")
        || lower.contains("peer misbehaved")
        || lower.contains("alert received")
        || lower.contains("inappropriate handshake")
    {
        return Error::tls(request.clone(), message, 0).with_source(error);
    }

    if lower.contains("proxy") || lower.contains("socks") {
        return Error::proxy(request.clone(), message, 0).with_source(error);
    }

    if lower.contains("http2")
        || lower.contains("malformed")
        || lower.contains("invalid http")
        || lower.contains("incomplete")
        || lower.contains("unexpected eof")
        || lower.contains("end of file")
        || lower.contains("connection closed before message completed")
        || lower.contains("peer closed")
        || lower.contains("protocol")
        || lower.contains("chunk")
        || lower.contains("incompletebody")
        || lower.contains("body error")
    {
        if lower.contains("before") && lower.contains("response")
            || lower.contains("connection closed before")
            || (lower.contains("end of file") && !lower.contains("body"))
            || lower.contains("empty") && lower.contains("response")
        {
            return Error::connection(request.clone(), message, 0).with_source(error);
        }
        return Error::protocol(request.clone(), message, 0).with_source(error);
    }

    if error.is_connect()
        || lower.contains("connection refused")
        || lower.contains("connection reset")
        || lower.contains("broken pipe")
        || lower.contains("network is unreachable")
        || lower.contains("no route to host")
        || lower.contains("connection abort")
    {
        return Error::connection(request.clone(), message, 0).with_source(error);
    }

    Error::network(request.clone(), message, 0).with_source(error)
}

pub(crate) fn map_io(request: &Request<Bytes>, error: io::Error) -> Error {
    let mut combined = format!("{error} {:?}", error.kind());
    let mut source = StdError::source(&error);
    while let Some(item) = source {
        combined.push(' ');
        combined.push_str(&item.to_string());
        write!(combined, " {item:?}").ok();
        source = item.source();
    }
    let message = error.to_string();
    let lower = combined.to_ascii_lowercase();
    if lower.contains("timed out") || lower.contains("timeout") {
        return Error::timeout(request.clone(), message, 0).with_source(error);
    }
    if lower.contains("unexpected eof")
        || lower.contains("end of file")
        || lower.contains("incomplete")
        || lower.contains("failed to fill whole buffer")
        || lower.contains("incompletebody")
    {
        return Error::protocol(request.clone(), message, 0).with_source(error);
    }
    if matches!(
        error.kind(),
        io::ErrorKind::ConnectionRefused
            | io::ErrorKind::ConnectionReset
            | io::ErrorKind::ConnectionAborted
            | io::ErrorKind::BrokenPipe
            | io::ErrorKind::NotConnected
    ) || lower.contains("connection")
    {
        return Error::connection(request.clone(), message, 0).with_source(error);
    }
    Error::network(request.clone(), message, 0).with_source(error)
}

pub(crate) fn status_u16(status: reqwest::StatusCode) -> u16 {
    status.as_u16()
}
