//! PHP `Utopia\Client\Adapter\SwooleCoroutine\Client`.
//!
//! **Deviation:** PHP uses `ext-swoole` coroutine HTTP. This adapter is a Tokio
//! and reqwest async client. `send_request` / `stream` must run on a Tokio
//! runtime (the coroutine stand-in); otherwise they return
//! [`Error::adapter_precondition`] matching PHP's
//! `"Swoole coroutine HTTP requests must run inside a coroutine."`.

use std::sync::Arc;

use bytes::Bytes;
use http::header;
use http::{Request, Response};
use parking_lot::Mutex;
use reqwest::Client as ReqwestClient;
use utopia_pools::Recover;

use super::common::{
    auto_decompress, configure_reqwest, content_encoding, decode_body, drop_decoded_headers,
    finish_response, map_reqwest, request_url, require_absolute_http, require_finite_timeout,
    reqwest_method, status_u16, TransportConfig,
};
use crate::{Adapter, Error, StreamingClient, Tls};

type ChunkSink<'a> = &'a mut dyn FnMut(&[u8]);

/// PHP `$settings` constructor argument.
#[derive(Clone, Debug, Default)]
pub struct SwooleSettings {
    pub timeout: Option<SwooleValue>,
    pub connect_timeout: Option<SwooleValue>,
    pub http2: Option<SwooleValue>,
    pub socks5_host: Option<String>,
    pub socks5_port: Option<u16>,
}

/// A Swoole setting value. [`SwooleValue::Invalid`] maps PHP `timeout => []`.
#[derive(Clone, Debug)]
pub enum SwooleValue {
    Number(f64),
    Bool(bool),
    Invalid,
}

impl SwooleSettings {
    #[must_use]
    pub fn timeout_secs(timeout: f64, connect_timeout: Option<f64>) -> Self {
        Self {
            timeout: Some(SwooleValue::Number(timeout)),
            connect_timeout: connect_timeout.map(SwooleValue::Number),
            ..Self::default()
        }
    }

    #[must_use]
    pub fn invalid_timeout() -> Self {
        Self {
            timeout: Some(SwooleValue::Invalid),
            ..Self::default()
        }
    }

    #[must_use]
    pub fn socks5(host: impl Into<String>, port: u16) -> Self {
        Self {
            socks5_host: Some(host.into()),
            socks5_port: Some(port),
            ..Self::default()
        }
    }
}

/// PHP `Utopia\Client\Adapter\SwooleCoroutine\Client`.
#[derive(Clone)]
pub struct Client {
    config: TransportConfig,
    settings: SwooleSettings,
    inner: Arc<Mutex<Option<ReqwestClient>>>,
}

impl std::fmt::Debug for Client {
    fn fmt(&self, formatter: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        formatter
            .debug_struct("SwooleCoroutineClient")
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
    /// PHP `new Client($responseFactory, $streamFactory, $settings = [])`.
    #[must_use]
    pub fn new() -> Self {
        Self::with_settings(SwooleSettings::default())
    }

    #[must_use]
    pub fn with_settings(settings: SwooleSettings) -> Self {
        let mut config = TransportConfig::default();
        if let Some(SwooleValue::Number(timeout)) = settings.timeout {
            if timeout.is_finite() && timeout >= 0.0 {
                if let Ok(duration) = require_finite_timeout(timeout) {
                    config.timeout = duration;
                }
            }
        }
        if let Some(SwooleValue::Number(timeout)) = settings.connect_timeout {
            if timeout.is_finite() && timeout >= 0.0 {
                if let Ok(duration) = require_finite_timeout(timeout) {
                    config.connect_timeout = duration;
                }
            }
        }
        if let (Some(host), Some(port)) = (&settings.socks5_host, settings.socks5_port) {
            config.proxy = Some(format!("socks5://{host}:{port}"));
        }
        Self {
            config,
            settings,
            inner: Arc::new(Mutex::new(None)),
        }
    }

    fn fresh_clone(&self) -> Self {
        Self {
            config: self.config.clone(),
            settings: self.settings.clone(),
            inner: Arc::new(Mutex::new(None)),
        }
    }

    fn validate_settings(&self, request: &Request<Bytes>) -> Result<(), Error> {
        for (name, value) in [
            ("timeout", self.settings.timeout.as_ref()),
            ("connect_timeout", self.settings.connect_timeout.as_ref()),
        ] {
            match value {
                Some(SwooleValue::Invalid | SwooleValue::Bool(_)) => {
                    return Err(Error::invalid_argument(format!(
                        "Swoole setting \"{name}\" must be a finite number greater than or equal to zero."
                    )));
                }
                Some(SwooleValue::Number(number)) if !number.is_finite() || *number < 0.0 => {
                    return Err(Error::invalid_argument(format!(
                        "Swoole setting \"{name}\" must be a finite number greater than or equal to zero."
                    )));
                }
                _ => {}
            }
        }
        if let Some(SwooleValue::Number(_) | SwooleValue::Invalid) = self.settings.http2 {
            return Err(Error::invalid_argument(
                "Swoole setting \"http2\" must be a boolean.",
            ));
        }
        let _ = request;
        Ok(())
    }

    fn require_runtime(&self, request: &Request<Bytes>) -> Result<tokio::runtime::Handle, Error> {
        tokio::runtime::Handle::try_current().map_err(|_| {
            Error::adapter_precondition(
                request.clone(),
                "Swoole coroutine HTTP requests must run inside a coroutine.",
            )
        })
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
        let builder = configure_reqwest(reqwest::Client::builder(), &self.config)?;
        builder
            .build()
            .map_err(|error| Error::invalid_argument(error.to_string()))
    }

    async fn perform(
        &self,
        request: Request<Bytes>,
        mut sink: Option<ChunkSink<'_>>,
    ) -> Result<Response<Bytes>, Error> {
        require_absolute_http(&request)?;
        self.validate_settings(&request)?;

        let streaming = sink.is_some();
        let decompress = auto_decompress(&request) && !streaming;
        let identity = streaming && auto_decompress(&request);

        let client = self.client()?;
        let method = reqwest_method(&request)?;
        let url = request_url(&request);
        let mut builder = client
            .request(method.clone(), url)
            .headers(request.headers().clone());
        if identity {
            builder = builder.header(header::ACCEPT_ENCODING, "identity");
        } else if decompress {
            builder = builder.header(header::ACCEPT_ENCODING, "gzip, deflate, br");
        }
        if method != reqwest::Method::HEAD && !request.body().is_empty() {
            builder = builder.body(request.body().clone());
        }

        let response = builder
            .send()
            .await
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

        if let Some(sink) = sink.as_mut() {
            use futures::StreamExt as _;
            let mut stream = response.bytes_stream();
            while let Some(chunk) = stream.next().await {
                let chunk = chunk.map_err(|error| map_reqwest(&request, error))?;
                sink(&chunk);
            }
            finish_response(status, version, headers, Bytes::new(), false, false)
        } else {
            let body = response
                .bytes()
                .await
                .map_err(|error| map_reqwest(&request, error))?;
            let body = if decompress {
                if let Some(encoding) = encoding.as_deref() {
                    if matches!(encoding, "gzip" | "x-gzip" | "deflate" | "br") {
                        Bytes::from(
                            decode_body(encoding, &body)
                                .map_err(|error| crate::adapter::common::map_io(&request, error))?,
                        )
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
            finish_response(status, version, headers, body, false, false)
        }
    }

    fn block_on_request(
        &self,
        request: Request<Bytes>,
        sink: Option<ChunkSink<'_>>,
    ) -> Result<Response<Bytes>, Error> {
        let handle = self.require_runtime(&request)?;
        tokio::task::block_in_place(|| handle.block_on(self.perform(request, sink)))
    }
}

impl StreamingClient for Client {
    fn send_request(&self, request: Request<Bytes>) -> Result<Response<Bytes>, Error> {
        self.block_on_request(request, None)
    }

    fn stream(
        &self,
        request: Request<Bytes>,
        sink: &mut dyn FnMut(&[u8]),
    ) -> Result<Response<Bytes>, Error> {
        self.block_on_request(request, Some(sink))
    }
}

impl Adapter for Client {
    fn with_timeout(&self, seconds: f64) -> Result<Self, Error> {
        let mut clone = self.fresh_clone();
        clone.config = self.config.apply_timeout(seconds)?;
        clone.settings.timeout = Some(SwooleValue::Number(seconds));
        Ok(clone)
    }

    fn with_connect_timeout(&self, seconds: f64) -> Result<Self, Error> {
        let mut clone = self.fresh_clone();
        clone.config = self.config.apply_connect_timeout(seconds)?;
        clone.settings.connect_timeout = Some(SwooleValue::Number(seconds));
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
