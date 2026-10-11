//! The transport interface every adapter and decorator implements, and the
//! configuration they share.

use std::future::Future;
use std::path::PathBuf;
use std::sync::Arc;
use std::time::Duration;

use crate::destinations::{Anywhere, Destinations};
use crate::{Error, Request, Response, redirect};

/// The lowest TLS protocol version a connection may negotiate.
///
/// The Rust transport speaks TLS 1.2 and 1.3 only, so [`Tls::V1_0`] and
/// [`Tls::V1_1`] allow what [`Tls::V1_2`] allows: older versions are never
/// negotiated.
///
/// PHP: `Utopia\Client\Tls`.
#[derive(Debug, Clone, Copy, PartialEq, Eq, PartialOrd, Ord)]
pub enum Tls {
    /// TLS 1.0 or later.
    V1_0,
    /// TLS 1.1 or later.
    V1_1,
    /// TLS 1.2 or later.
    V1_2,
    /// TLS 1.3 only.
    V1_3,
}

/// A client certificate for mutual TLS.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Certificate {
    /// The PEM certificate (chain) file.
    pub cert: PathBuf,
    /// The PEM private key file.
    pub key: PathBuf,
    /// The key's passphrase.
    ///
    /// The Rust transport reads unencrypted keys only: an encrypted key
    /// fails the handshake with [`Error::Tls`].
    pub passphrase: Option<String>,
}

/// How a transport connects and what it does with responses. Every field
/// has the PHP default; change them through [`Client`](crate::Client)'s
/// `with_*` methods, which validate them.
///
/// PHP: the settings the `with*()` methods of `Utopia\Client\Adapter` set.
#[derive(Debug, Clone)]
pub struct Options {
    pub(crate) timeout: Option<Duration>,
    pub(crate) connect_timeout: Option<Duration>,
    pub(crate) verify: bool,
    pub(crate) ca: Option<PathBuf>,
    pub(crate) certificate: Option<Certificate>,
    pub(crate) min_tls: Option<Tls>,
    pub(crate) reuse: bool,
    pub(crate) follow: bool,
    pub(crate) max_hops: u32,
    pub(crate) destinations: Arc<dyn Destinations>,
}

impl Default for Options {
    fn default() -> Options {
        Options {
            timeout: Some(Duration::from_secs(30)),
            connect_timeout: Some(Duration::from_secs(5)),
            verify: true,
            ca: None,
            certificate: None,
            min_tls: None,
            reuse: false,
            follow: false,
            max_hops: redirect::MAX_HOPS,
            destinations: Arc::new(Anywhere),
        }
    }
}

impl Options {
    /// The whole-request timeout (`None`: no limit). Default 30 seconds.
    pub fn timeout(&self) -> Option<Duration> {
        self.timeout
    }

    /// The connect timeout (`None`: no limit). Default 5 seconds.
    pub fn connect_timeout(&self) -> Option<Duration> {
        self.connect_timeout
    }

    /// Whether TLS certificates and host names are verified. Default `true`.
    pub fn verifies(&self) -> bool {
        self.verify
    }

    /// The CA bundle trusted instead of the built-in roots.
    pub fn ca(&self) -> Option<&PathBuf> {
        self.ca.as_ref()
    }

    /// The client certificate.
    pub fn certificate(&self) -> Option<&Certificate> {
        self.certificate.as_ref()
    }

    /// The lowest TLS version allowed.
    pub fn min_tls(&self) -> Option<Tls> {
        self.min_tls
    }

    /// Whether connections are kept and reused. Default `false`.
    pub fn reuses_connections(&self) -> bool {
        self.reuse
    }

    /// Whether redirects are followed. Default `false`.
    pub fn follows_redirects(&self) -> bool {
        self.follow
    }

    /// The most redirects followed. Default [`redirect::MAX_HOPS`].
    pub fn max_hops(&self) -> u32 {
        self.max_hops
    }

    /// Where requests may connect. Default [`Anywhere`].
    pub fn destinations(&self) -> &Arc<dyn Destinations> {
        &self.destinations
    }

    /// Seconds as a duration, rounded to milliseconds; 0 is no limit.
    ///
    /// PHP: `milliseconds($seconds)` (cURL's 0 is no limit) and its
    /// `ValueError`.
    pub(crate) fn duration(seconds: f64) -> Result<Option<Duration>, Error> {
        if seconds < 0.0 || !seconds.is_finite() {
            return Err(Error::Value("Timeout must be a finite number greater than or equal to zero.".into()));
        }
        let ms = php_std::format::round(
            php_std::value::Number::Float(seconds * 1000.0),
            0,
            php_std::format::RoundingMode::default(),
        );
        Ok(if ms == 0.0 { None } else { Some(Duration::from_millis(ms as u64)) })
    }
}

/// Where a streamed response body goes, chunk by chunk.
///
/// Any `FnMut(&[u8]) + Send` closure is a sink.
///
/// PHP: the `callable(string): void $sink` of `stream()`.
pub trait Sink: Send {
    /// Takes the next chunk of the body.
    fn write(&mut self, chunk: &[u8]);
}

impl<F: FnMut(&[u8]) + Send> Sink for F {
    fn write(&mut self, chunk: &[u8]) {
        self(chunk)
    }
}

/// A transport: sends requests, buffering or streaming the response, under
/// [`Options`] changed by returning a reconfigured copy.
///
/// Decorators implement it too, wrapping another adapter, so they compose:
/// [`Retry`](crate::Retry) is one. Implement it to add a transport or a
/// decorator of your own.
///
/// PHP: `Utopia\Client\Adapter` (PSR-18 `ClientInterface` plus
/// `Psr18\StreamingClientInterface`), and `Utopia\Client\Decorator`, the
/// base class whose forwarding [`Adapter::options`] and
/// [`Adapter::with_options`] give a decorator.
pub trait Adapter: Send + Sync + Sized {
    /// Sends a request and returns the response with its body.
    ///
    /// A 4xx or 5xx response is returned, not an error.
    ///
    /// PHP: `sendRequest($request)`.
    fn send(&self, request: Request) -> impl Future<Output = Result<Response, Error>> + Send;

    /// Sends a request, passing each body chunk to `sink` as it arrives; the
    /// returned response carries the status and headers, and an empty body.
    ///
    /// PHP: `stream($request, $sink)`.
    fn stream<S: Sink>(&self, request: Request, sink: &mut S) -> impl Future<Output = Result<Response, Error>> + Send;

    /// The configuration in effect.
    fn options(&self) -> &Options;

    /// A copy configured with these options. A copy keeps no connection of
    /// the original.
    ///
    /// PHP: what each `with*()` does to a clone.
    fn with_options(&self, options: Options) -> Self;
}
