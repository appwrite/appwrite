//! What every HTTP message has: a protocol version, headers and a body.

use std::collections::HashMap;

use indexmap::IndexMap;

use crate::Error;
use crate::stream::StreamRef;

/// Header fields: case-insensitive names, each with its values, in the order
/// they were set.
///
/// A name keeps the spelling it was last set with (`withHeader('X-Test')`
/// then `withHeader('x-test')` lists `x-test`). Setting a header again moves
/// it to the end; adding values to it keeps its place.
///
/// PHP: the `$headers` and `$headerNames` arrays of `Utopia\Psr7\Message`.
#[derive(Debug, Clone, Default)]
pub struct Headers {
    entries: IndexMap<String, Vec<String>>,
    names: HashMap<String, String>,
}

impl Headers {
    /// No headers.
    pub fn new() -> Self {
        Self::default()
    }

    /// Headers from `(name, values)` pairs, as given: names are not checked,
    /// and of two names differing only in case, lookups find the later
    /// while both are listed.
    ///
    /// PHP: the `$headers` argument of `new ServerRequest(...)`.
    pub fn from_pairs<I, N, V>(pairs: I) -> Self
    where
        I: IntoIterator<Item = (N, V)>,
        N: Into<String>,
        V: HeaderValues,
    {
        let mut headers = Headers::new();
        for (name, values) in pairs {
            let name = name.into();
            headers.names.insert(name.to_ascii_lowercase(), name.clone());
            headers.entries.insert(name, values.into_values());
        }
        headers
    }

    /// Every header and its values, in order.
    pub fn iter(&self) -> impl ExactSizeIterator<Item = (&str, &[String])> {
        self.entries.iter().map(|(n, v)| (n.as_str(), v.as_slice()))
    }

    /// How many headers there are.
    pub fn len(&self) -> usize {
        self.entries.len()
    }

    /// Whether there are none.
    pub fn is_empty(&self) -> bool {
        self.entries.is_empty()
    }

    /// Whether `name` (any case) is set.
    pub fn contains(&self, name: &str) -> bool {
        self.names.contains_key(&name.to_ascii_lowercase())
    }

    /// The values of `name` (any case); none when it is not set.
    pub fn get(&self, name: &str) -> &[String] {
        self.names
            .get(&name.to_ascii_lowercase())
            .and_then(|original| self.entries.get(original))
            .map_or(&[], Vec::as_slice)
    }

    /// The values of `name` joined with `", "`.
    pub fn line(&self, name: &str) -> String {
        self.get(name).join(", ")
    }

    /// Replaces `name`'s values (any case), spelling it as given.
    pub fn set(&mut self, name: &str, values: Vec<String>) -> Result<(), Error> {
        check_name(name)?;
        let lower = name.to_ascii_lowercase();
        if let Some(previous) = self.names.get(&lower) {
            self.entries.shift_remove(previous);
        }
        self.names.insert(lower, name.to_owned());
        self.entries.insert(name.to_owned(), values);
        Ok(())
    }

    /// Adds values to `name` (any case), or sets it when it is new.
    pub fn append(&mut self, name: &str, values: Vec<String>) -> Result<(), Error> {
        check_name(name)?;
        let lower = name.to_ascii_lowercase();
        match self.names.get(&lower) {
            None => {
                self.names.insert(lower, name.to_owned());
                self.entries.insert(name.to_owned(), values);
            }
            Some(original) => {
                let original = original.clone();
                self.entries.entry(original).or_default().extend(values);
            }
        }
        Ok(())
    }

    /// Removes `name` (any case).
    pub fn remove(&mut self, name: &str) {
        if let Some(original) = self.names.remove(&name.to_ascii_lowercase()) {
            self.entries.shift_remove(&original);
        }
    }
}

/// A header name is a token (RFC 7230): letters, digits and
/// ``!#$%&'*+-.^_`|~``.
///
/// PHP checks it with `preg_match('/^[...]+$/')`, whose `$` also matches
/// before a final newline: one trailing `"\n"` is accepted (a recorded quirk).
fn check_name(name: &str) -> Result<(), Error> {
    let token = name.strip_suffix('\n').unwrap_or(name);
    if !token.is_empty() && token.bytes().all(is_tchar) {
        return Ok(());
    }
    Err(Error::invalid("Invalid header name."))
}

fn is_tchar(b: u8) -> bool {
    b.is_ascii_alphanumeric() || b"'`#$%&*+.^_|~!-".contains(&b)
}

/// Values a header can be set to: a string or number, or a list of them.
///
/// Numbers and booleans read as PHP casts them to strings (`true` is `"1"`,
/// `false` is `""`, floats with 14 significant digits).
pub trait HeaderValues {
    /// The values as strings.
    fn into_values(self) -> Vec<String>;
}

/// One header value.
pub trait HeaderValue {
    /// The value as a string.
    fn into_value(self) -> String;
}

impl HeaderValue for &str {
    fn into_value(self) -> String {
        self.to_owned()
    }
}

impl HeaderValue for String {
    fn into_value(self) -> String {
        self
    }
}

impl HeaderValue for &String {
    fn into_value(self) -> String {
        self.clone()
    }
}

impl HeaderValue for bool {
    fn into_value(self) -> String {
        if self { "1".to_owned() } else { String::new() }
    }
}

impl HeaderValue for f64 {
    fn into_value(self) -> String {
        php_std::number::to_string(self)
    }
}

macro_rules! integer_values {
    ($($t:ty),*) => {$(
        impl HeaderValue for $t {
            fn into_value(self) -> String {
                self.to_string()
            }
        }
    )*};
}

integer_values!(i8, i16, i32, i64, u8, u16, u32, u64, usize, isize);

impl<T: HeaderValue> HeaderValues for T {
    fn into_values(self) -> Vec<String> {
        vec![self.into_value()]
    }
}

impl<T: HeaderValue> HeaderValues for Vec<T> {
    fn into_values(self) -> Vec<String> {
        self.into_iter().map(HeaderValue::into_value).collect()
    }
}

impl<T: HeaderValue + Clone> HeaderValues for &[T] {
    fn into_values(self) -> Vec<String> {
        self.iter().cloned().map(HeaderValue::into_value).collect()
    }
}

impl<T: HeaderValue, const N: usize> HeaderValues for [T; N] {
    fn into_values(self) -> Vec<String> {
        self.into_iter().map(HeaderValue::into_value).collect()
    }
}

/// The parts every message has.
#[derive(Debug, Clone)]
pub struct Head {
    /// The HTTP version (`"1.1"`).
    pub protocol: String,
    /// The header fields.
    pub headers: Headers,
    /// The body.
    pub body: StreamRef,
}

impl Head {
    /// HTTP/1.1, no headers and an empty body.
    pub fn new(body: StreamRef) -> Self {
        Head { protocol: "1.1".to_owned(), headers: Headers::new(), body }
    }
}

/// An HTTP message: requests and responses.
///
/// Every `with_*` method consumes the message and returns the changed one;
/// clone a message to keep the original. Clones share their body, as every
/// copy of a PHP message holds the same stream object.
///
/// PHP: `Utopia\Psr7\Message` (`Psr\Http\Message\MessageInterface`).
pub trait Message: Sized {
    /// The protocol version, headers and body.
    fn head(&self) -> &Head;

    /// The protocol version, headers and body, to change.
    fn head_mut(&mut self) -> &mut Head;

    /// The HTTP version, such as `"1.1"`.
    ///
    /// PHP: `getProtocolVersion()`.
    fn protocol_version(&self) -> &str {
        &self.head().protocol
    }

    /// The message with another HTTP version.
    ///
    /// PHP: `withProtocolVersion()`.
    fn with_protocol_version(mut self, version: &str) -> Self {
        self.head_mut().protocol = version.to_owned();
        self
    }

    /// Every header.
    ///
    /// PHP: `getHeaders()`.
    fn headers(&self) -> &Headers {
        &self.head().headers
    }

    /// Whether a header is set, in any case.
    ///
    /// PHP: `hasHeader()`.
    fn has_header(&self, name: &str) -> bool {
        self.head().headers.contains(name)
    }

    /// The values of a header, in any case.
    ///
    /// PHP: `getHeader()`.
    fn header(&self, name: &str) -> &[String] {
        self.head().headers.get(name)
    }

    /// The values of a header joined with `", "`.
    ///
    /// PHP: `getHeaderLine()`.
    fn header_line(&self, name: &str) -> String {
        self.head().headers.line(name)
    }

    /// The message with a header replaced.
    ///
    /// Fails with [`Error::InvalidArgument`] (`Invalid header name.`) when
    /// the name is not a token.
    ///
    /// PHP: `withHeader()`.
    fn with_header(mut self, name: &str, values: impl HeaderValues) -> Result<Self, Error> {
        self.head_mut().headers.set(name, values.into_values())?;
        Ok(self)
    }

    /// The message with values added to a header.
    ///
    /// PHP: `withAddedHeader()`.
    fn with_added_header(mut self, name: &str, values: impl HeaderValues) -> Result<Self, Error> {
        self.head_mut().headers.append(name, values.into_values())?;
        Ok(self)
    }

    /// The message without a header.
    ///
    /// PHP: `withoutHeader()`.
    fn without_header(mut self, name: &str) -> Self {
        self.head_mut().headers.remove(name);
        self
    }

    /// The body.
    ///
    /// PHP: `getBody()`.
    fn body(&self) -> &StreamRef {
        &self.head().body
    }

    /// The message with another body.
    ///
    /// PHP: `withBody()`.
    fn with_body(mut self, body: StreamRef) -> Self {
        self.head_mut().body = body;
        self
    }
}
