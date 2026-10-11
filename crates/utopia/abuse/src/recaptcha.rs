//! [`ReCaptcha`]: asks Google whether a reCAPTCHA token came from a person.

use std::future::Future;
use std::pin::Pin;

use bytes::Bytes;
use http_body_util::{BodyExt, Full};
use php_std::Value;

use crate::{Error, Result};

/// A request [`ReCaptcha`] sends.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Request {
    /// Always `POST`.
    pub method: &'static str,
    /// The verification endpoint, [`ReCaptcha::URL`].
    pub url: &'static str,
    /// Always `application/x-www-form-urlencoded`.
    pub content_type: &'static str,
    /// The form: `secret`, `response` and `remoteip`, percent-encoded as
    /// RFC 3986 says.
    pub body: String,
}

/// A boxed future, so [`Client`] can be a trait object.
pub type BoxFuture<'a, T> = Pin<Box<dyn Future<Output = T> + Send + 'a>>;

/// Sends [`ReCaptcha`]'s request and returns the response's body. Give
/// [`ReCaptcha`] your own client to route, record or replay its traffic;
/// [`HttpClient`] is the default.
///
/// PHP: the `Psr\Http\Client\ClientInterface` given to `ReCaptcha`. Its
/// failures are [`Error::Client`] (`ClientExceptionInterface`).
pub trait Client: Send + Sync {
    /// Sends `request` and returns the response's body.
    fn send<'a>(&'a self, request: Request) -> BoxFuture<'a, Result<Vec<u8>>>;
}

type Hyper = hyper_util::client::legacy::Client<
    hyper_rustls::HttpsConnector<hyper_util::client::legacy::connect::HttpConnector>,
    Full<Bytes>,
>;

/// The default [`Client`]: HTTPS with the WebPKI roots.
///
/// PHP: `Utopia\Client\Client` over curl.
#[derive(Clone)]
pub struct HttpClient {
    inner: Hyper,
}

impl std::fmt::Debug for HttpClient {
    /// Writes the client's name.
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.write_str("HttpClient")
    }
}

impl HttpClient {
    /// A client over HTTPS (and plain HTTP).
    pub fn new() -> Result<Self> {
        let mut http = hyper_util::client::legacy::connect::HttpConnector::new();
        http.enforce_http(false);
        let https = hyper_rustls::HttpsConnectorBuilder::new()
            .with_provider_and_webpki_roots(rustls::crypto::ring::default_provider())
            .map_err(|e| Error::Client(format!("TLS setup failed: {e}")))?
            .https_or_http()
            .enable_http1()
            .wrap_connector(http);
        let inner = hyper_util::client::legacy::Client::builder(hyper_util::rt::TokioExecutor::new()).build(https);
        Ok(HttpClient { inner })
    }
}

impl Client for HttpClient {
    /// Sends the form and reads the whole body, whatever the status.
    fn send<'a>(&'a self, request: Request) -> BoxFuture<'a, Result<Vec<u8>>> {
        Box::pin(async move {
            let req = hyper::Request::builder()
                .method(request.method)
                .uri(request.url)
                .header("Content-Type", request.content_type)
                .body(Full::new(Bytes::from(request.body)))
                .map_err(|e| Error::Client(e.to_string()))?;
            let res = self.inner.request(req).await.map_err(|e| Error::Client(e.to_string()))?;
            let body = res.into_body().collect().await.map_err(|e| Error::Client(e.to_string()))?;
            Ok(body.to_bytes().to_vec())
        })
    }
}

/// Verifies reCAPTCHA v3 tokens: a token passes when Google answers
/// `"success": true` with a score of at least the threshold.
///
/// ```
/// use utopia_abuse::ReCaptcha;
/// use utopia_abuse::recaptcha::{BoxFuture, Client, Request};
///
/// // A client that answers as Google would.
/// struct Google;
///
/// impl Client for Google {
///     fn send<'a>(&'a self, _request: Request) -> BoxFuture<'a, utopia_abuse::Result<Vec<u8>>> {
///         Box::pin(async { Ok(br#"{"success": true, "score": 0.9}"#.to_vec()) })
///     }
/// }
///
/// # tokio::runtime::Builder::new_current_thread().build()?.block_on(async {
/// let recaptcha = ReCaptcha::with_client("secret", Google);
/// assert!(recaptcha.verify("token", "203.0.113.7", 0.5).await?);
/// assert!(!recaptcha.verify("token", "203.0.113.7", 0.95).await?);
/// # Ok::<(), utopia_abuse::Error>(())
/// # })?;
/// # Ok::<(), Box<dyn std::error::Error>>(())
/// ```
///
/// ```php
/// use Psr\Http\Client\ClientInterface;
/// use Psr\Http\Message\RequestInterface;
/// use Psr\Http\Message\ResponseInterface;
/// use Utopia\Abuse\ReCaptcha;
/// use Utopia\Psr7\Response;
/// use Utopia\Psr7\Stream;
///
/// $google = new class () implements ClientInterface {
///     public function sendRequest(RequestInterface $request): ResponseInterface
///     {
///         return new Response(200, body: new Stream('{"success": true, "score": 0.9}'));
///     }
/// };
///
/// $recaptcha = new ReCaptcha('secret', $google);
/// var_dump($recaptcha->verify('token', '203.0.113.7')); // bool(true)
/// var_dump($recaptcha->verify('token', '203.0.113.7', 0.95)); // bool(false)
/// ```
///
/// PHP: `Utopia\Abuse\ReCaptcha`.
pub struct ReCaptcha<C = HttpClient> {
    secret: String,
    client: C,
}

impl<C> std::fmt::Debug for ReCaptcha<C> {
    /// Writes the type, not the secret.
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.write_str("ReCaptcha")
    }
}

impl ReCaptcha<HttpClient> {
    /// A verifier with the site's `secret`, over HTTPS.
    pub fn new(secret: impl Into<String>) -> Result<Self> {
        Ok(ReCaptcha { secret: secret.into(), client: HttpClient::new()? })
    }
}

impl<C: Client> ReCaptcha<C> {
    /// Google's verification endpoint.
    ///
    /// PHP: `ReCaptcha::URL`.
    pub const URL: &'static str = "https://www.google.com/recaptcha/api/siteverify";

    /// A verifier with the site's `secret`, sending through `client`.
    pub fn with_client(secret: impl Into<String>, client: C) -> Self {
        ReCaptcha { secret: secret.into(), client }
    }

    /// The client requests go through.
    pub fn client(&self) -> &C {
        &self.client
    }

    /// The request [`ReCaptcha::verify`] sends for `response` from `ip`.
    pub fn request(&self, response: &str, ip: &str) -> Request {
        let fields = [("secret", self.secret.as_str()), ("response", response), ("remoteip", ip)];
        let mut body = String::new();
        for (name, value) in fields {
            if !body.is_empty() {
                body.push('&');
            }
            body.push_str(name);
            body.push('=');
            body.push_str(&php_std::encoding::rawurlencode(value.as_bytes()));
        }
        Request { method: "POST", url: Self::URL, content_type: "application/x-www-form-urlencoded", body }
    }

    /// Whether Google verifies the token `response`, sent by `ip`, as human
    /// with a score of at least `score` (PHP's default is 0.5). Anything but
    /// a JSON object with `"success": true` and a numeric `score` is `false`.
    pub async fn verify(&self, response: &str, ip: &str, score: f64) -> Result<bool> {
        let body = self.client.send(self.request(response, ip)).await?;
        let Ok(Value::Array(body)) = php_std::json::decode(&body, Some(true), 512, php_std::json::Flags::NONE) else {
            return Ok(false);
        };
        if !matches!(body.get_str("success"), Some(Value::Bool(true))) {
            return Ok(false);
        }
        Ok(match body.get_str("score") {
            Some(Value::Int(i)) => *i as f64 >= score,
            Some(Value::Float(f)) => *f >= score,
            _ => false,
        })
    }
}
