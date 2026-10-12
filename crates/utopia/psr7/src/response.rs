//! Responses, and reading their bodies as JSON, text, XML, forms or multipart.

use php_std::{Array, Value};

use crate::Error;
use crate::message::{Head, Message};
use crate::multipart::Received;
use crate::names::header;
use crate::stream::StreamRef;
use crate::xml::{self, Element};

/// An HTTP response.
///
/// ```
/// use utopia_psr7::{Message, Response, StreamRef};
///
/// let response = Response::new(200, "")?
///     .with_header("Content-Type", "Application/JSON; charset=utf-8")?
///     .with_body(StreamRef::from_bytes(br#"{"name":"Ada"}"#));
///
/// assert_eq!(response.content_type(), "application/json");
/// assert_eq!(response.json(true, 512, Default::default())?.try_to_json(), Some(serde_json::json!({"name": "Ada"})));
/// # Ok::<(), utopia_psr7::Error>(())
/// ```
///
/// ```php
/// use Utopia\Psr7\Response;
/// use Utopia\Psr7\Stream;
///
/// $response = (new Response(200))
///     ->withHeader('Content-Type', 'Application/JSON; charset=utf-8')
///     ->withBody(new Stream('{"name":"Ada"}'));
///
/// echo $response->contentType(), "\n"; // application/json
/// var_dump($response->json());         // array(1) { ["name"]=> string(3) "Ada" }
/// ```
///
/// PHP: `Utopia\Psr7\Response` (`Psr\Http\Message\ResponseInterface`).
#[derive(Debug, Clone)]
pub struct Response {
    head: Head,
    status: u16,
    reason: String,
}

impl Message for Response {
    fn head(&self) -> &Head {
        &self.head
    }

    fn head_mut(&mut self) -> &mut Head {
        &mut self.head
    }
}

fn check_status(status: u16) -> Result<(), Error> {
    if (100..=599).contains(&status) {
        return Ok(());
    }
    Err(Error::invalid("Invalid response status code."))
}

impl Response {
    /// A response with an empty body.
    ///
    /// Fails with [`Error::InvalidArgument`] (`Invalid response status
    /// code.`) for a status outside 100..=599.
    ///
    /// PHP: `new Response($code, $reasonPhrase)`, `Response\Factory::createResponse()`.
    pub fn new(status: u16, reason: &str) -> Result<Response, Error> {
        check_status(status)?;
        Ok(Response { head: Head::new(StreamRef::default()), status, reason: reason.to_owned() })
    }

    /// The status code.
    ///
    /// PHP: `getStatusCode()`.
    pub fn status(&self) -> u16 {
        self.status
    }

    /// The response with another status and reason phrase.
    ///
    /// PHP: `withStatus()`.
    pub fn with_status(mut self, status: u16, reason: &str) -> Result<Response, Error> {
        check_status(status)?;
        self.status = status;
        self.reason = reason.to_owned();
        Ok(self)
    }

    /// The reason phrase.
    ///
    /// PHP: `getReasonPhrase()`.
    pub fn reason(&self) -> &str {
        &self.reason
    }

    /// The media type of `Content-Type`, without parameters, lower case.
    ///
    /// PHP: `contentType()`.
    pub fn content_type(&self) -> String {
        let line = self.header_line(header::CONTENT_TYPE);
        let media = line.split(';').next().unwrap_or_default();
        String::from_utf8_lossy(php_std::string::trim(media.as_bytes(), php_std::string::TRIM_CHARACTERS))
            .to_ascii_lowercase()
    }

    /// The body decoded as JSON, as `json_decode()` decodes it: objects as
    /// arrays with `associative`, as `stdClass` otherwise.
    ///
    /// Fails with [`Error::Value`] when `depth` is below 1 and
    /// [`Error::Json`] when the body is not valid JSON.
    ///
    /// PHP: `json()`.
    pub fn json(&self, associative: bool, depth: i64, flags: php_std::json::Flags) -> Result<Value, Error> {
        if depth < 1 {
            return Err(Error::Value("JSON decode depth must be greater than zero.".to_owned()));
        }
        let body = self.body().to_bytes();
        php_std::json::decode(&body, Some(associative), depth, flags | php_std::json::Flags::THROW_ON_ERROR).map_err(
            |e| match e {
                php_std::json::Error::Value(message) => Error::Value(message),
                other => Error::Json(other.message().to_owned()),
            },
        )
    }

    /// The body.
    ///
    /// PHP: `text()`.
    pub fn text(&self) -> Vec<u8> {
        self.body().to_bytes()
    }

    /// The body parsed as an XML document: its root element.
    ///
    /// Fails with [`Error::InvalidArgument`] (`Invalid XML response.`) when
    /// the body is not a well-formed document.
    ///
    /// PHP: `xml()` (a `SimpleXMLElement`).
    pub fn xml(&self) -> Result<Element, Error> {
        xml::parse(&self.body().to_bytes()).ok_or_else(|| Error::invalid("Invalid XML response."))
    }

    /// The body decoded as a URL-encoded form, as `parse_str()` decodes one.
    ///
    /// PHP: `form()`.
    pub fn form(&self) -> Array {
        php_std::encoding::parse_str(&self.body().to_bytes())
    }

    /// The body split into its multipart parts, by the `boundary` parameter
    /// of `Content-Type`. Text before the first delimiter and after the
    /// closing one is ignored, and empty parts are skipped.
    ///
    /// Fails with [`Error::InvalidArgument`] (`Multipart response is missing
    /// a boundary.`) when `Content-Type` has no boundary.
    ///
    /// PHP: `multipart()`.
    pub fn multipart(&self) -> Result<Vec<Received>, Error> {
        let content_type = self.header_line(header::CONTENT_TYPE);
        let boundary = boundary(content_type.as_bytes())
            .ok_or_else(|| Error::invalid("Multipart response is missing a boundary."))?;
        let body = self.body().to_bytes();
        Ok(segments(&body, boundary).into_iter().filter(|s| !s.is_empty()).map(part).collect())
    }
}

/// PCRE's `\s` without `/u`: space, `\t`, `\n`, `\v`, `\f`, `\r`.
fn is_space(b: u8) -> bool {
    matches!(b, b' ' | b'\t' | b'\n' | 0x0B | 0x0C | b'\r')
}

/// `(?:^|;\s*)boundary=(?:"(?P<quoted>[^"]+)"|(?P<plain>[^;\s]+))`, the
/// first match: the quoted value, else the plain one.
fn boundary(content_type: &[u8]) -> Option<&[u8]> {
    const KEY: &[u8] = b"boundary=";
    for start in 0..content_type.len() {
        let after = if start == 0 && content_type.starts_with(KEY) {
            KEY.len()
        } else if content_type[start] == b';' {
            let mut at = start + 1;
            while at < content_type.len() && is_space(content_type[at]) {
                at += 1;
            }
            if !content_type[at..].starts_with(KEY) {
                continue;
            }
            at + KEY.len()
        } else {
            continue;
        };
        let rest = &content_type[after..];
        if let Some(inner) = rest.strip_prefix(b"\"") {
            let len = inner.iter().take_while(|&&b| b != b'"').count();
            if len > 0 && inner.get(len) == Some(&b'"') {
                return Some(&inner[..len]);
            }
        }
        let len = rest.iter().take_while(|&&b| b != b';' && !is_space(b)).count();
        if len > 0 {
            return Some(&rest[..len]);
        }
    }
    None
}

/// The bodies between delimiter lines
/// (`(^|\r\n|\n|\r)--<boundary>(--)?[ \t]*(?:\r\n|\n|\r|$)`), up to the
/// closing one.
fn segments<'a>(body: &'a [u8], boundary: &[u8]) -> Vec<&'a [u8]> {
    let mut segments = Vec::new();
    let mut previous_end: Option<usize> = None;
    let mut at = 0;
    while at <= body.len() {
        let Some((start, end, closing)) = delimiter(body, at, boundary) else {
            at += 1;
            continue;
        };
        if let Some(previous) = previous_end {
            segments.push(&body[previous..start]);
        }
        previous_end = Some(end);
        if closing {
            break;
        }
        at = end.max(at + 1);
    }
    segments
}

/// A delimiter starting at `at`: its end and whether it closes the body.
fn delimiter(body: &[u8], at: usize, boundary: &[u8]) -> Option<(usize, usize, bool)> {
    let mut prefixes: [Option<usize>; 4] = [None; 4];
    if at == 0 {
        prefixes[0] = Some(0);
    }
    let rest = &body[at..];
    if rest.starts_with(b"\r\n") {
        prefixes[1] = Some(2);
    }
    if rest.starts_with(b"\n") {
        prefixes[2] = Some(1);
    }
    if rest.starts_with(b"\r") {
        prefixes[3] = Some(1);
    }
    for prefix in prefixes.into_iter().flatten() {
        let mut pos = at + prefix;
        if !body[pos..].starts_with(b"--") || !body[pos + 2..].starts_with(boundary) {
            continue;
        }
        pos += 2 + boundary.len();
        // (--)? greedily, then backtrack to without it.
        let options: &[bool] = if body[pos..].starts_with(b"--") { &[true, false] } else { &[false] };
        for &closing in options {
            let mut end = if closing { pos + 2 } else { pos };
            while end < body.len() && matches!(body[end], b' ' | b'\t') {
                end += 1;
            }
            if let Some(line_end) = line_end(body, end) {
                return Some((at, line_end, closing));
            }
            // Fewer blanks cannot help: the byte after them is a blank.
        }
    }
    None
}

/// `(?:\r\n|\n|\r|$)` at `at` (`$`: the end, or before a final `\n`).
fn line_end(body: &[u8], at: usize) -> Option<usize> {
    let rest = &body[at..];
    if rest.starts_with(b"\r\n") {
        Some(at + 2)
    } else if rest.starts_with(b"\n") || rest.starts_with(b"\r") {
        Some(at + 1)
    } else if rest.is_empty() {
        Some(at)
    } else {
        None
    }
}

fn part(segment: &[u8]) -> Received {
    let (raw, body) = split(segment);
    Received::new(headers(raw), body.to_vec())
}

fn split(segment: &[u8]) -> (&[u8], &[u8]) {
    for separator in [&b"\r\n\r\n"[..], b"\n\n", b"\r\r"] {
        if let Some(position) = segment.windows(separator.len()).position(|w| w == separator) {
            return (&segment[..position], &segment[position + separator.len()..]);
        }
    }
    (b"", segment)
}

/// Header lines `Name: value`, trimmed; a repeated name collects its values.
fn headers(raw: &[u8]) -> Vec<(Vec<u8>, Vec<Vec<u8>>)> {
    let mut headers: Vec<(Vec<u8>, Vec<Vec<u8>>)> = Vec::new();
    for line in lines(raw) {
        let Some(colon) = line.iter().position(|&b| b == b':') else {
            continue;
        };
        let name = php_std::string::trim(&line[..colon], php_std::string::TRIM_CHARACTERS);
        if name.is_empty() {
            continue;
        }
        let value = php_std::string::trim(&line[colon + 1..], php_std::string::TRIM_CHARACTERS).to_vec();
        match headers.iter_mut().find(|(n, _)| n == name) {
            Some((_, values)) => values.push(value),
            None => headers.push((name.to_vec(), vec![value])),
        }
    }
    headers
}

/// `preg_split("/\r\n|\n|\r/")`.
fn lines(raw: &[u8]) -> Vec<&[u8]> {
    let mut lines = Vec::new();
    let mut start = 0;
    let mut i = 0;
    while i < raw.len() {
        match raw[i] {
            b'\r' => {
                lines.push(&raw[start..i]);
                i += if raw.get(i + 1) == Some(&b'\n') { 2 } else { 1 };
                start = i;
            }
            b'\n' => {
                lines.push(&raw[start..i]);
                i += 1;
                start = i;
            }
            _ => i += 1,
        }
    }
    lines.push(&raw[start..]);
    lines
}

/// Creates responses.
///
/// PHP: `Psr\Http\Message\ResponseFactoryInterface`.
pub trait ResponseFactory {
    /// A response with an empty body.
    ///
    /// PHP: `createResponse()`.
    fn create_response(&self, status: u16, reason: &str) -> Result<Response, Error>;
}

/// The default [`ResponseFactory`].
///
/// PHP: `Utopia\Psr7\Response\Factory`.
#[derive(Debug, Clone, Copy, Default)]
pub struct Factory;

impl ResponseFactory for Factory {
    fn create_response(&self, status: u16, reason: &str) -> Result<Response, Error> {
        Response::new(status, reason)
    }
}
