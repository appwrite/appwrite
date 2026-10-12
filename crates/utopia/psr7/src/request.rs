//! Outgoing requests and the factory that builds them with typed bodies.

use php_std::encoding::{QueryEncoding, http_build_query};
use serde_json::Value;

use crate::Error;
use crate::message::{Head, Message};
use crate::multipart::{Body, Part};
use crate::names::{content_type, header};
use crate::stream::{self, StreamFactory, StreamRef};
use crate::uri::{self, Uri, UriFactory};

/// An outgoing HTTP request.
///
/// PHP: `Utopia\Psr7\Request` (`Psr\Http\Message\RequestInterface`).
#[derive(Debug, Clone)]
pub struct Request {
    head: Head,
    method: String,
    uri: Uri,
}

impl Message for Request {
    fn head(&self) -> &Head {
        &self.head
    }

    fn head_mut(&mut self) -> &mut Head {
        &mut self.head
    }
}

impl Request {
    /// A request with the method as given, no headers and an empty body.
    /// [`Factory::create_request`] also upper-cases the method and sets `Host`.
    ///
    /// PHP: `new Request($method, $uri)`.
    pub fn new(method: &str, uri: Uri) -> Request {
        Request { head: Head::new(StreamRef::default()), method: method.to_owned(), uri }
    }

    /// The path and query (`/` for an empty path).
    ///
    /// PHP: `getRequestTarget()`.
    pub fn request_target(&self) -> String {
        target(&self.uri)
    }

    /// The request with its URI's path set to `target`.
    ///
    /// PHP: `withRequestTarget()`.
    pub fn with_request_target(self, target: &str) -> Request {
        let uri = self.uri.with_path(target);
        self.with_uri(uri, false)
    }

    /// The method.
    ///
    /// PHP: `getMethod()`.
    pub fn method(&self) -> &str {
        &self.method
    }

    /// The request with another method, upper-cased.
    ///
    /// PHP: `withMethod()`.
    pub fn with_method(mut self, method: &str) -> Request {
        self.method = method.to_ascii_uppercase();
        self
    }

    /// The URI.
    ///
    /// PHP: `getUri()`.
    pub fn uri(&self) -> &Uri {
        &self.uri
    }

    /// The request with another URI. A URI with a host sets `Host`, unless
    /// `preserve_host` and the request has a non-empty `Host` already.
    ///
    /// PHP: `withUri()`.
    pub fn with_uri(mut self, uri: Uri, preserve_host: bool) -> Request {
        let host = host(&uri);
        self.uri = uri;
        if let Some(host) = host
            && !(preserve_host && !self.header_line(header::HOST).is_empty())
        {
            let _ = self.head.headers.set(header::HOST, vec![host]);
        }
        self
    }
}

/// `path?query` of a URI, `/` for an empty path.
pub(crate) fn target(uri: &Uri) -> String {
    let path = if uri.path().is_empty() { "/" } else { uri.path() };
    let mut target = String::with_capacity(path.len() + uri.query().len() + 1);
    target.push_str(path);
    if !uri.query().is_empty() {
        target.push('?');
        target.push_str(uri.query());
    }
    target
}

/// The `Host` header a URI sets: `host` or `host:port`; none without a host.
pub(crate) fn host(uri: &Uri) -> Option<String> {
    if uri.host().is_empty() {
        return None;
    }
    Some(match uri.port() {
        None => uri.host().to_owned(),
        Some(port) => format!("{}:{port}", uri.host()),
    })
}

/// A URI given as text or already parsed.
pub enum Target<'a> {
    /// Parsed with the factory's [`UriFactory`].
    Text(&'a str),
    /// Used as it is.
    Uri(Uri),
}

impl<'a> From<&'a str> for Target<'a> {
    fn from(uri: &'a str) -> Self {
        Target::Text(uri)
    }
}

impl<'a> From<&'a String> for Target<'a> {
    fn from(uri: &'a String) -> Self {
        Target::Text(uri)
    }
}

impl From<Uri> for Target<'_> {
    fn from(uri: Uri) -> Self {
        Target::Uri(uri)
    }
}

/// Builds requests: plain ones, and ones with a JSON, form, text, XML, raw,
/// query-string or multipart payload and the matching `Content-Type`.
///
/// Headers given to a typed builder are set first, so a caller's
/// `Content-Type` (or `Accept`, for JSON) wins over the default; multipart
/// bodies always set their own `Content-Type`, with the generated boundary.
///
/// ```
/// use utopia_psr7::{Message, request::Factory};
/// use serde_json::json;
///
/// let factory = Factory::default();
/// let request = factory.form("POST", "https://example.com/users", &json!({
///     "email": "ada@example.com",
///     "name": "Ada Lovelace",
/// }), &[])?;
///
/// assert_eq!(request.header_line("Content-Type"), "application/x-www-form-urlencoded");
/// assert_eq!(request.body().to_bytes(), b"email=ada%40example.com&name=Ada%20Lovelace");
/// assert_eq!(request.header_line("Host"), "example.com");
/// # Ok::<(), utopia_psr7::Error>(())
/// ```
///
/// ```php
/// use Utopia\Psr7\Request;
///
/// $factory = new Request\Factory();
/// $request = $factory->form('POST', 'https://example.com/users', [
///     'email' => 'ada@example.com',
///     'name' => 'Ada Lovelace',
/// ]);
///
/// echo $request->getHeaderLine('Content-Type'), "\n"; // application/x-www-form-urlencoded
/// echo $request->getBody(), "\n";                     // email=ada%40example.com&name=Ada%20Lovelace
/// echo $request->getHeaderLine('Host'), "\n";         // example.com
/// ```
///
/// PHP: `Utopia\Psr7\Request\Factory` (`Psr\Http\Message\RequestFactoryInterface`).
#[derive(Debug, Clone)]
pub struct Factory<U = uri::Factory, S = stream::Factory> {
    uris: U,
    streams: S,
}

impl Default for Factory {
    /// URIs parsed with [`Uri::parse`], bodies in memory.
    fn default() -> Self {
        Factory { uris: uri::Factory, streams: stream::Factory }
    }
}

/// Headers to set: `(name, values)`.
pub type HeaderList<'a> = [(&'a str, Vec<String>)];

impl<U: UriFactory, S: StreamFactory> Factory<U, S> {
    /// A factory parsing URIs with `uris` and creating bodies with `streams`.
    ///
    /// PHP: `new Request\Factory($uriFactory, $streamFactory)`.
    pub fn new(uris: U, streams: S) -> Self {
        Factory { uris, streams }
    }

    fn uri(&self, uri: Target<'_>) -> Result<Uri, Error> {
        match uri {
            Target::Text(text) => self.uris.create_uri(text),
            Target::Uri(uri) => Ok(uri),
        }
    }

    /// A request with the method upper-cased and `Host` from the URI.
    ///
    /// PHP: `createRequest()`.
    pub fn create_request<'a>(&self, method: &str, uri: impl Into<Target<'a>>) -> Result<Request, Error> {
        let uri = self.uri(uri.into())?;
        Ok(Request::new(&method.to_ascii_uppercase(), uri.clone()).with_uri(uri, false))
    }

    /// A request with `data` as JSON, `Content-Type` and `Accept`
    /// `application/json` unless given.
    ///
    /// `data` is encoded as `json_encode()` encodes the PHP value it decodes
    /// to (`/` escaped, non-ASCII as `\u` escapes).
    ///
    /// Fails with [`Error::Json`] when `data` cannot be encoded (nested
    /// deeper than 512 levels).
    ///
    /// PHP: `json()`.
    pub fn json<'a>(
        &self,
        method: &str,
        uri: impl Into<Target<'a>>,
        data: &Value,
        headers: &HeaderList<'_>,
    ) -> Result<Request, Error> {
        let data: php_std::Value = php_std::Value::from_json(data);
        let body = php_std::json::encode(&data, php_std::json::Flags::THROW_ON_ERROR, php_std::json::DEFAULT_DEPTH)
            .map_err(|e| Error::Json(e.message().to_owned()))?;
        let request = self.body(method, uri, body.as_bytes(), content_type::JSON, headers)?;
        if request.has_header(header::ACCEPT) {
            return Ok(request);
        }
        request.with_header(header::ACCEPT, content_type::JSON)
    }

    /// A request with `data` URL-encoded (`%20` for spaces).
    ///
    /// PHP: `form()`.
    pub fn form<'a>(
        &self,
        method: &str,
        uri: impl Into<Target<'a>>,
        data: &Value,
        headers: &HeaderList<'_>,
    ) -> Result<Request, Error> {
        let body = http_build_query(data, b"", Some(b"&"), QueryEncoding::Rfc3986);
        self.body(method, uri, &body, content_type::FORM_URLENCODED, headers)
    }

    /// A request with a `text/plain` body.
    ///
    /// PHP: `text()`.
    pub fn text<'a>(
        &self,
        method: &str,
        uri: impl Into<Target<'a>>,
        text: &[u8],
        headers: &HeaderList<'_>,
    ) -> Result<Request, Error> {
        self.body(method, uri, text, content_type::PLAIN_TEXT, headers)
    }

    /// A request with an `application/xml` body.
    ///
    /// PHP: `xml()`.
    pub fn xml<'a>(
        &self,
        method: &str,
        uri: impl Into<Target<'a>>,
        xml: &[u8],
        headers: &HeaderList<'_>,
    ) -> Result<Request, Error> {
        self.body(method, uri, xml, content_type::XML, headers)
    }

    /// A request with `body` and `content_type` unless a `Content-Type` is given.
    ///
    /// PHP: `body()`.
    pub fn body<'a>(
        &self,
        method: &str,
        uri: impl Into<Target<'a>>,
        body: &[u8],
        content_type: &str,
        headers: &HeaderList<'_>,
    ) -> Result<Request, Error> {
        let request = apply(self.create_request(method, uri)?, headers)?.with_body(self.streams.create_stream(body));
        if request.has_header(header::CONTENT_TYPE) {
            return Ok(request);
        }
        request.with_header(header::CONTENT_TYPE, content_type)
    }

    /// A request with `parameters` URL-encoded and appended to the URI's query.
    ///
    /// PHP: `query()`.
    pub fn query<'a>(
        &self,
        method: &str,
        uri: impl Into<Target<'a>>,
        parameters: &Value,
        headers: &HeaderList<'_>,
    ) -> Result<Request, Error> {
        let request = apply(self.create_request(method, uri)?, headers)?;
        let empty = match parameters {
            Value::Array(a) => a.is_empty(),
            Value::Object(o) => o.is_empty(),
            _ => false,
        };
        if empty {
            return Ok(request);
        }
        let encoded = http_build_query(parameters, b"", Some(b"&"), QueryEncoding::Rfc3986);
        let encoded = String::from_utf8_lossy(&encoded);
        let query = if request.uri().query().is_empty() {
            encoded.into_owned()
        } else {
            format!("{}&{encoded}", request.uri().query())
        };
        let uri = request.uri().with_query(&query);
        Ok(request.with_uri(uri, false))
    }

    /// A `multipart/form-data` request of `parts`, with a random
    /// `utopia-<32 hex>` boundary set in `Content-Type` (replacing one the
    /// caller gave).
    ///
    /// Fails with [`Error::Runtime`] when a file part cannot be opened.
    ///
    /// PHP: `multipart()`, where a scalar value is [`Part::field`] of its key.
    pub fn multipart<'a>(
        &self,
        method: &str,
        uri: impl Into<Target<'a>>,
        parts: Vec<Part>,
        headers: &HeaderList<'_>,
    ) -> Result<Request, Error> {
        let boundary = boundary();
        let request = apply(self.create_request(method, uri)?, headers)?;
        let body = Body::new(&boundary, parts)?;
        request
            .with_body(StreamRef::new(body))
            .with_header(header::CONTENT_TYPE, format!("{}; boundary={boundary}", content_type::MULTIPART_FORM_DATA))
    }
}

fn apply(mut request: Request, headers: &HeaderList<'_>) -> Result<Request, Error> {
    for (name, values) in headers {
        request = request.with_header(name, values.clone())?;
    }
    Ok(request)
}

/// `utopia-` and 32 random hex digits.
fn boundary() -> String {
    let mut bytes = [0u8; 16];
    let _ = getrandom::fill(&mut bytes);
    let mut out = String::with_capacity(39);
    out.push_str("utopia-");
    for b in bytes {
        out.push_str(&format!("{b:02x}"));
    }
    out
}

impl<U, S> Factory<U, S> {
    /// The factory's URI factory.
    pub fn uris(&self) -> &U {
        &self.uris
    }

    /// The factory's stream factory.
    pub fn streams(&self) -> &S {
        &self.streams
    }
}
