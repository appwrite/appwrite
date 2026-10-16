//! PHP `Utopia\Client\Adapter\Curl\Client` - reqwest blocking backend.

use std::io::Read;
use std::sync::Arc;
use std::time::Duration;

use bytes::Bytes;
use http::header;
use http::{Request, Response};
use parking_lot::Mutex;
use reqwest::blocking::Client as ReqwestClient;
use utopia_pools::Recover;

use super::common::{
    auto_decompress, configure_blocking, content_encoding, copy_chunks, decode_body, decode_read,
    drop_decoded_headers, finish_response, map_io, map_reqwest, request_url, require_absolute_http,
    reqwest_method, status_u16, TransportConfig,
};
use crate::{Adapter, Error, StreamingClient, Tls};

/// Native-option stand-in for PHP `$options` (`CURLOPT_*` keys).
#[derive(Clone, Debug, Default)]
pub struct CurlOptions {
    /// `CURLOPT_TIMEOUT_MS`.
    pub timeout: Option<Duration>,
    /// `CURLOPT_CONNECTTIMEOUT_MS`.
    pub connect_timeout: Option<Duration>,
    /// HTTP/SOCKS proxy URL (`http://host:port` or `socks5://host:port`).
    pub proxy: Option<String>,
    /// When true, `send_request` fails like an invalid `CURLOPT_*` key.
    pub invalid: bool,
}

impl CurlOptions {
    pub fn timeout_secs(seconds: f64) -> Result<Self, Error> {
        Ok(Self {
            timeout: Some(super::common::require_finite_timeout(seconds)?),
            ..Self::default()
        })
    }

    #[must_use]
    pub fn with_timeout_ms(mut self, millis: u64) -> Self {
        self.timeout = Some(Duration::from_millis(millis));
        self
    }

    #[must_use]
    pub fn with_connect_timeout_ms(mut self, millis: u64) -> Self {
        self.connect_timeout = Some(Duration::from_millis(millis));
        self
    }

    #[must_use]
    pub fn with_http_proxy(mut self, url: impl Into<String>) -> Self {
        self.proxy = Some(url.into());
        self
    }

    #[must_use]
    pub fn with_socks5_proxy(mut self, host_port: impl Into<String>) -> Self {
        self.proxy = Some(format!("socks5://{}", host_port.into()));
        self
    }

    #[must_use]
    pub fn invalid() -> Self {
        Self {
            invalid: true,
            ..Self::default()
        }
    }
}

/// PHP `Utopia\Client\Adapter\Curl\Client`.
#[derive(Clone)]
pub struct Client {
    config: TransportConfig,
    inner: Arc<Mutex<Option<ReqwestClient>>>,
}

impl std::fmt::Debug for Client {
    fn fmt(&self, formatter: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        formatter
            .debug_struct("CurlClient")
            .field("reuse", &self.config.reuse)
            .finish_non_exhaustive()
    }
}

impl Default for Client {
    fn default() -> Self {
        Self::new()
    }
}

impl Client {
    /// PHP `new Client($responseFactory, $streamFactory, $options = [])`.
    #[must_use]
    pub fn new() -> Self {
        Self::with_options(CurlOptions::default())
    }

    #[must_use]
    pub fn with_options(options: CurlOptions) -> Self {
        let mut config = TransportConfig::default();
        if let Some(timeout) = options.timeout {
            config.timeout = timeout;
        }
        if let Some(connect_timeout) = options.connect_timeout {
            config.connect_timeout = connect_timeout;
        }
        config.proxy = options.proxy;
        config.invalid = options.invalid;
        Self {
            config,
            inner: Arc::new(Mutex::new(None)),
        }
    }

    fn fresh_clone(&self) -> Self {
        Self {
            config: self.config.clone(),
            inner: Arc::new(Mutex::new(None)),
        }
    }

    fn client(&self) -> Result<ReqwestClient, Error> {
        if self.config.reuse {
            let mut slot = self.inner.lock();
            if let Some(client) = slot.clone() {
                return Ok(client);
            }
            let client = self.build_client()?;
            *slot = Some(client.clone());
            return Ok(client);
        }
        self.build_client()
    }

    fn build_client(&self) -> Result<ReqwestClient, Error> {
        let builder = configure_blocking(reqwest::blocking::Client::builder(), &self.config)?;
        builder.build().map_err(|error| {
            Error::adapter_initialization(
                Request::builder()
                    .uri("http://invalid.invalid/")
                    .body(Bytes::new())
                    .unwrap_or_else(|_| Request::new(Bytes::new())),
                error.to_string(),
                0,
            )
        })
    }

    fn transfer(
        &self,
        request: Request<Bytes>,
        sink: &mut dyn FnMut(&[u8]),
        buffer_body: bool,
    ) -> Result<Response<Bytes>, Error> {
        require_absolute_http(&request)?;
        if self.config.invalid {
            return Err(Error::invalid_argument("Unable to configure curl."));
        }

        let decompress = auto_decompress(&request);
        let client = self.client().map_err(|error| {
            if error.kind() == crate::ErrorKind::AdapterInitialization {
                Error::adapter_initialization(request.clone(), error.to_string(), error.code())
            } else {
                error
            }
        })?;

        let method = reqwest_method(&request)?;
        let url = request_url(&request);
        let mut builder = client
            .request(method.clone(), url)
            .headers(request.headers().clone());

        if decompress {
            builder = builder.header(header::ACCEPT_ENCODING, "gzip, deflate, br");
        }

        if method != reqwest::Method::HEAD && !request.body().is_empty() {
            builder = builder.body(request.body().clone());
        }

        let mut response = builder
            .send()
            .map_err(|error| map_reqwest(&request, error))?;
        let status = status_u16(response.status());
        if !(100..=599).contains(&status) {
            return Err(Error::invalid_response(
                request.clone(),
                "Received an invalid HTTP response.",
            ));
        }

        let version = response.version();
        let mut headers = response.headers().clone();
        let encoding = content_encoding(&headers);

        if buffer_body {
            let mut body = Vec::new();
            response
                .read_to_end(&mut body)
                .map_err(|error| map_io(&request, error))?;
            let body = if decompress {
                if let Some(encoding) = encoding.as_deref() {
                    if matches!(encoding, "gzip" | "x-gzip" | "deflate" | "br") {
                        decode_body(encoding, &body).map_err(|error| map_io(&request, error))?
                    } else {
                        body
                    }
                } else {
                    body
                }
            } else {
                body
            };
            if decompress {
                drop_decoded_headers(&mut headers);
            }
            finish_response(status, version, headers, Bytes::from(body), false, false)
        } else {
            if decompress {
                if let Some(encoding) = encoding.as_deref() {
                    if matches!(encoding, "gzip" | "x-gzip" | "deflate" | "br") {
                        let decoder = decode_read(encoding, response);
                        copy_chunks(decoder, sink).map_err(|error| map_io(&request, error))?;
                    } else {
                        copy_chunks(response, sink).map_err(|error| map_io(&request, error))?;
                    }
                } else {
                    copy_chunks(response, sink).map_err(|error| map_io(&request, error))?;
                }
                drop_decoded_headers(&mut headers);
            } else {
                copy_chunks(response, sink).map_err(|error| map_io(&request, error))?;
            }
            finish_response(status, version, headers, Bytes::new(), false, false)
        }
    }
}

impl StreamingClient for Client {
    fn send_request(&self, request: Request<Bytes>) -> Result<Response<Bytes>, Error> {
        self.transfer(request, &mut |_| {}, true)
    }

    fn stream(
        &self,
        request: Request<Bytes>,
        sink: &mut dyn FnMut(&[u8]),
    ) -> Result<Response<Bytes>, Error> {
        self.transfer(request, sink, false)
    }
}

impl Adapter for Client {
    fn with_timeout(&self, seconds: f64) -> Result<Self, Error> {
        let mut clone = self.fresh_clone();
        clone.config = self.config.apply_timeout(seconds)?;
        Ok(clone)
    }

    fn with_connect_timeout(&self, seconds: f64) -> Result<Self, Error> {
        let mut clone = self.fresh_clone();
        clone.config = self.config.apply_connect_timeout(seconds)?;
        Ok(clone)
    }

    fn with_ssl_verification(&self, enabled: bool) -> Self {
        let mut clone = self.fresh_clone();
        clone.config.ssl_verify = enabled;
        clone
    }

    fn with_custom_ca(&self, path: impl Into<String>) -> Self {
        let mut clone = self.fresh_clone();
        clone.config.ca_path = Some(path.into());
        clone
    }

    fn with_certificate(
        &self,
        cert_path: impl Into<String>,
        key_path: impl Into<String>,
        passphrase: Option<String>,
    ) -> Self {
        let mut clone = self.fresh_clone();
        clone.config.certificate = Some((cert_path.into(), key_path.into(), passphrase));
        clone
    }

    fn with_min_tls_version(&self, version: Tls) -> Self {
        let mut clone = self.fresh_clone();
        clone.config.min_tls = Some(version);
        clone
    }

    fn with_connection_reuse(&self, enabled: bool) -> Self {
        let mut clone = self.fresh_clone();
        clone.config.reuse = enabled;
        clone
    }
}

impl Recover for Client {}
