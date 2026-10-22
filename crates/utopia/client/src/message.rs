//! Requests, responses, their headers and bodies.

use std::path::PathBuf;

use bytes::Bytes;

use crate::{Error, Uri};

/// HTTP header fields in order, each name with its values.
///
/// Names compare case-insensitively and keep the spelling they were first
/// added with; they are sent as spelled. Setting a field replaces it and
/// moves it last; adding to one appends values in place.
///
/// PHP: the header methods of `Utopia\Psr7\Message` (`getHeaders()`,
/// `hasHeader()`, `getHeader()`, `getHeaderLine()`, `withHeader()`,
/// `withAddedHeader()`, `withoutHeader()`).
#[derive(Debug, Clone, Default, PartialEq, Eq)]
pub struct Headers {
    fields: Vec<(String, Vec<String>)>,
}

impl Headers {
    /// No fields.
    pub fn new() -> Headers {
        Headers::default()
    }

    /// The fields in order: name as spelled, then values.
    pub fn iter(&self) -> impl Iterator<Item = (&str, &[String])> {
        self.fields.iter().map(|(name, values)| (name.as_str(), values.as_slice()))
    }

    /// Whether a field is present (any case).
    pub fn has(&self, name: &str) -> bool {
        self.position(name).is_some()
    }

    /// The values of a field, empty when absent.
    pub fn get(&self, name: &str) -> &[String] {
        self.position(name).map(|i| self.fields[i].1.as_slice()).unwrap_or(&[])
    }

    /// The values of a field joined with `, `, empty when absent.
    pub fn line(&self, name: &str) -> String {
        self.get(name).join(", ")
    }

    /// Replaces a field (any case) with these values, last.
    ///
    /// Fails with [`Error::InvalidArgument`] (`Invalid header name.`) unless
    /// the name is an RFC 9110 token, then (`Header values must not contain
    /// CR, LF or NUL characters.`) when a value holds one of them.
    pub fn set<V: Into<String>>(
        &mut self,
        name: &str,
        values: impl IntoIterator<Item = V>,
    ) -> Result<&mut Headers, Error> {
        check(name)?;
        let values = checked(values)?;
        if let Some(i) = self.position(name) {
            self.fields.remove(i);
        }
        self.fields.push((name.to_owned(), values));
        Ok(self)
    }

    /// Adds values to a field, creating it last when absent.
    ///
    /// Fails like [`Headers::set`].
    pub fn add<V: Into<String>>(
        &mut self,
        name: &str,
        values: impl IntoIterator<Item = V>,
    ) -> Result<&mut Headers, Error> {
        check(name)?;
        let values = checked(values)?;
        match self.position(name) {
            Some(i) => self.fields[i].1.extend(values),
            None => self.fields.push((name.to_owned(), values)),
        }
        Ok(self)
    }

    /// Adds a value without checking the name, for parsing a response
    /// whose names are checked once it is complete ([`Headers::check`]).
    pub(crate) fn add_unchecked(&mut self, name: &str, value: String) {
        match self.position(name) {
            Some(i) => self.fields[i].1.push(value),
            None => self.fields.push((name.to_owned(), vec![value])),
        }
    }

    /// Checks the fields of a received response in order, as PHP's response
    /// builder adds them: an integer-like name is a `TypeError` (PHP keys
    /// the headers by name), any other non-token an invalid name, then a
    /// value with CR, LF or NUL an invalid value.
    pub(crate) fn check(&self) -> Result<(), Error> {
        self.fields.iter().try_for_each(|(name, values)| {
            built(name)?;
            values.iter().try_for_each(|v| value(v))
        })
    }

    /// Removes a field (any case).
    pub fn remove(&mut self, name: &str) -> &mut Headers {
        if let Some(i) = self.position(name) {
            self.fields.remove(i);
        }
        self
    }

    /// The number of fields.
    pub fn len(&self) -> usize {
        self.fields.len()
    }

    /// Whether there are no fields.
    pub fn is_empty(&self) -> bool {
        self.fields.is_empty()
    }

    fn position(&self, name: &str) -> Option<usize> {
        self.fields.iter().position(|(n, _)| n.eq_ignore_ascii_case(name))
    }
}

/// A header name as `Response\Builder::build()` adds it.
fn built(name: &str) -> Result<(), Error> {
    if php_std::types::numeric_key(name.as_bytes()).is_some() {
        return Err(Error::Type(
            "Utopia\\Psr7\\Message::withAddedHeader(): Argument #1 ($name) must be of type string, int given, called in /usr/src/code/packages/client/src/Response/Builder.php on line 36"
                .into(),
        ));
    }
    check(name)
}

/// Whether a header name is a token.
///
/// PHP: `/^[A-Za-z0-9'`#$%&*+.^_|~!-]+$/`.
fn check(name: &str) -> Result<(), Error> {
    let token = |b: u8| b.is_ascii_alphanumeric() || b"'`#$%&*+.^_|~!-".contains(&b);
    if name.is_empty() || !name.bytes().all(token) {
        return Err(Error::InvalidArgument("Invalid header name.".into()));
    }
    Ok(())
}

/// A header value: no CR, LF or NUL (PSR-7).
///
/// PHP: `strpbrk($value, "\r\n\0") !== false` in `normalizeHeaderValue()`.
fn value(value: &str) -> Result<(), Error> {
    if value.bytes().any(|b| matches!(b, b'\r' | b'\n' | 0)) {
        return Err(Error::InvalidArgument("Header values must not contain CR, LF or NUL characters.".into()));
    }
    Ok(())
}

/// Values checked in order, failing at the first invalid one.
fn checked<V: Into<String>>(values: impl IntoIterator<Item = V>) -> Result<Vec<String>, Error> {
    values
        .into_iter()
        .map(|v| {
            let v = v.into();
            value(&v)?;
            Ok(v)
        })
        .collect()
}

/// A request body.
///
/// PHP: the request's `StreamInterface`: an in-memory stream, or a file
/// stream that cURL reads in chunks.
#[derive(Debug, Clone, Default, PartialEq, Eq)]
pub enum Body {
    /// No body.
    #[default]
    Empty,
    /// Bytes in memory.
    Bytes(Bytes),
    /// A file, streamed from disk as it is sent, so a large upload is never
    /// held in memory.
    File(PathBuf),
}

impl Body {
    /// The body's size in bytes, `None` for a file that cannot be read.
    pub async fn size(&self) -> Option<u64> {
        match self {
            Body::Empty => Some(0),
            Body::Bytes(b) => Some(b.len() as u64),
            Body::File(path) => utopia_async::fs::metadata(path).await.ok().map(|m| m.len()),
        }
    }
}

impl From<Bytes> for Body {
    fn from(bytes: Bytes) -> Body {
        if bytes.is_empty() { Body::Empty } else { Body::Bytes(bytes) }
    }
}

impl From<Vec<u8>> for Body {
    fn from(bytes: Vec<u8>) -> Body {
        Body::from(Bytes::from(bytes))
    }
}

impl From<&'static str> for Body {
    fn from(text: &'static str) -> Body {
        Body::from(Bytes::from_static(text.as_bytes()))
    }
}

impl From<String> for Body {
    fn from(text: String) -> Body {
        Body::from(Bytes::from(text))
    }
}

/// An HTTP request: method, URI, headers and body.
///
/// PHP: a PSR-7 `RequestInterface` (`Utopia\Psr7\Request`).
///
/// ```
/// use utopia_client::Request;
///
/// let request = Request::new("POST", "https://api.example.com/users")?
///     .header("Content-Type", "application/json")?
///     .body(r#"{"name":"Ada"}"#);
/// assert_eq!(request.uri().host(), "api.example.com");
/// assert_eq!(request.headers().line("content-type"), "application/json");
/// # Ok::<(), utopia_client::Error>(())
/// ```
///
/// ```php
/// use Utopia\Psr7\Request;
/// use Utopia\Psr7\Stream;
/// use Utopia\Psr7\Uri;
///
/// $request = (new Request('POST', Uri::parse('https://api.example.com/users')))
///     ->withHeader('Content-Type', 'application/json')
///     ->withBody((new Stream\Factory())->createStream('{"name":"Ada"}'));
/// echo $request->getUri()->getHost(), "\n"; // api.example.com
/// echo $request->getHeaderLine('content-type'), "\n"; // application/json
/// ```
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Request {
    method: String,
    uri: Uri,
    headers: Headers,
    body: Body,
}

impl Request {
    /// A request without headers or body; the URI is parsed with
    /// [`Uri::parse`].
    pub fn new(method: &str, uri: &str) -> Result<Request, Error> {
        Ok(Request::to(method, Uri::parse(uri)?))
    }

    /// A request to a parsed URI.
    pub fn to(method: &str, uri: Uri) -> Request {
        Request { method: method.to_owned(), uri, headers: Headers::new(), body: Body::Empty }
    }

    /// With a header set to one value (replacing any of that name).
    pub fn header(mut self, name: &str, value: &str) -> Result<Request, Error> {
        self.headers.set(name, [value])?;
        Ok(self)
    }

    /// With a header added (keeping the values it already has).
    pub fn added_header(mut self, name: &str, value: &str) -> Result<Request, Error> {
        self.headers.add(name, [value])?;
        Ok(self)
    }

    /// With this body.
    pub fn body(mut self, body: impl Into<Body>) -> Request {
        self.body = body.into();
        self
    }

    /// The method, as given (`GET`, `post`, `PURGE`).
    pub fn method(&self) -> &str {
        &self.method
    }

    /// The URI.
    pub fn uri(&self) -> &Uri {
        &self.uri
    }

    /// The headers.
    pub fn headers(&self) -> &Headers {
        &self.headers
    }

    /// The headers, to change them.
    pub fn headers_mut(&mut self) -> &mut Headers {
        &mut self.headers
    }

    /// The body.
    pub fn get_body(&self) -> &Body {
        &self.body
    }

    /// With another method.
    pub fn with_method(mut self, method: &str) -> Request {
        self.method = method.to_owned();
        self
    }

    /// With another URI.
    pub fn with_uri(mut self, uri: Uri) -> Request {
        self.uri = uri;
        self
    }
}

/// An HTTP response: protocol version, status, reason phrase, headers and
/// body.
///
/// A response with a 4xx or 5xx status is still a response, not an error.
/// A streamed response carries an empty body: its bytes went to the sink.
///
/// PHP: a PSR-7 `ResponseInterface`, as `Utopia\Client\Response\Builder`
/// builds it.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Response {
    protocol: String,
    status: u16,
    reason: String,
    headers: Headers,
    body: Bytes,
}

impl Response {
    /// A response with this status, reason phrase, headers (each value added
    /// in order, merging names that differ only in case), body and protocol
    /// version.
    ///
    /// Fails with [`Error::InvalidArgument`] for a status outside 100–599
    /// (`Invalid response status code.`), a header name that is not a
    /// token (`Invalid header name.`) or a value with CR, LF or NUL
    /// (`Header values must not contain CR, LF or NUL characters.`).
    ///
    /// PHP: `(new Builder($responseFactory, $streamFactory))->build($statusCode,
    /// $reasonPhrase, $headers, $body, $protocolVersion)`.
    ///
    /// ```
    /// use utopia_client::Response;
    ///
    /// let response = Response::build(
    ///     200,
    ///     "OK",
    ///     [("Set-Cookie", "a=1"), ("set-cookie", "b=2")],
    ///     "hello",
    ///     "1.1",
    /// )?;
    /// assert_eq!(response.headers().get("set-cookie"), ["a=1", "b=2"]);
    /// assert_eq!(response.text(), "hello");
    /// # Ok::<(), utopia_client::Error>(())
    /// ```
    ///
    /// ```php
    /// use Utopia\Client\Response\Builder;
    /// use Utopia\Psr7\Response;
    /// use Utopia\Psr7\Stream;
    ///
    /// $response = (new Builder(new Response\Factory(), new Stream\Factory()))
    ///     ->build(200, 'OK', ['Set-Cookie' => ['a=1'], 'set-cookie' => ['b=2']], 'hello', '1.1');
    /// echo json_encode($response->getHeader('set-cookie')), "\n"; // ["a=1","b=2"]
    /// echo $response->getBody(), "\n"; // hello
    /// ```
    pub fn build<'a>(
        status: u16,
        reason: &str,
        headers: impl IntoIterator<Item = (&'a str, &'a str)>,
        body: impl Into<Bytes>,
        protocol: &str,
    ) -> Result<Response, Error> {
        if !(100..=599).contains(&status) {
            return Err(Error::InvalidArgument("Invalid response status code.".into()));
        }
        let mut headers_built = Headers::new();
        for (name, value) in headers {
            headers_built.add_unchecked(name, value.to_owned());
        }
        headers_built.check()?;
        let built = headers_built;
        Ok(Response {
            protocol: protocol.to_owned(),
            status,
            reason: reason.to_owned(),
            headers: built,
            body: body.into(),
        })
    }

    /// An empty HTTP/1.1 response with this status, to build one by hand
    /// (a test double, a strategy's input).
    ///
    /// Fails with [`Error::InvalidArgument`] (`Invalid response status
    /// code.`) outside 100–599.
    ///
    /// PHP: `new Response($statusCode)`.
    pub fn new(status: u16) -> Result<Response, Error> {
        Response::build(status, "", [], Bytes::new(), "1.1")
    }

    /// With a header value added (keeping the values it already has).
    ///
    /// Fails like [`Headers::set`].
    ///
    /// PHP: `withAddedHeader($name, $value)`.
    pub fn with_header(mut self, name: &str, value: &str) -> Result<Response, Error> {
        self.headers.add(name, [value])?;
        Ok(self)
    }

    /// With this body.
    ///
    /// PHP: `withBody($body)`.
    pub fn with_body(mut self, body: impl Into<Bytes>) -> Response {
        self.body = body.into();
        self
    }

    pub(crate) fn from_parts(protocol: String, status: u16, reason: String, headers: Headers, body: Bytes) -> Response {
        Response { protocol, status, reason, headers, body }
    }

    /// The protocol version (`1.1`, `1.0`, `2`).
    pub fn protocol(&self) -> &str {
        &self.protocol
    }

    /// The status code.
    pub fn status(&self) -> u16 {
        self.status
    }

    /// The reason phrase, as the server sent it (may be empty).
    pub fn reason(&self) -> &str {
        &self.reason
    }

    /// The headers.
    pub fn headers(&self) -> &Headers {
        &self.headers
    }

    /// The body bytes.
    pub fn bytes(&self) -> &Bytes {
        &self.body
    }

    /// The body as text, lossily decoded as UTF-8.
    pub fn text(&self) -> std::borrow::Cow<'_, str> {
        String::from_utf8_lossy(&self.body)
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn rejects_line_breaks_and_nul_in_values() {
        for value in ["one\rtwo", "one\ntwo", "one\r\nX-Injected: yes", "one\0two"] {
            let error = Request::new("GET", "http://localhost/").unwrap().header("X-Test", value).unwrap_err();
            assert_eq!(
                error.to_string(),
                Error::InvalidArgument("Header values must not contain CR, LF or NUL characters.".into()).to_string()
            );
            assert!(Headers::new().add("X-Test", ["valid", value]).is_err());
            assert!(Response::build(200, "OK", [("X-Test", value)], "", "1.1").is_err());
        }
        let mut headers = Headers::new();
        headers.set("X-Test", ["one\ttwo é"]).unwrap();
        assert_eq!(headers.line("X-Test"), "one\ttwo é");
        // The name is checked first.
        let error = Headers::new().set("Bad Name", ["a\nb"]).unwrap_err();
        assert_eq!(error.to_string(), Error::InvalidArgument("Invalid header name.".into()).to_string());
    }
}
