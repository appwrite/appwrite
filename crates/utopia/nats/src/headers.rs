//! NATS message headers: the `NATS/1.0` block sent with `HPUB` and received
//! with `HMSG`.

use indexmap::IndexMap;

use crate::error::{Error, Result};

/// The characters PHP's `trim()` and `ltrim()` strip by default.
const PHP_WHITESPACE: &[u8] = b" \t\n\r\0\x0B";

/// Message headers: names with one or more values each, in insertion
/// order, plus the optional status line a server sets (`503 No Responders`,
/// `100 Idle Heartbeat`, ...).
///
/// Names are case sensitive and kept as given.
///
/// ```
/// use utopia_nats::Headers;
///
/// let mut headers = Headers::new();
/// headers.set("Nats-Msg-Id", "order-1").add("X-Tag", "a").add("X-Tag", "b");
/// assert_eq!(headers.get("X-Tag"), Some("a"));
/// assert_eq!(headers.to_wire(), b"NATS/1.0\r\nNats-Msg-Id: order-1\r\nX-Tag: a\r\nX-Tag: b\r\n\r\n");
///
/// let parsed = Headers::from_wire(b"NATS/1.0 503 No Responders\r\n\r\n")?;
/// assert_eq!((parsed.status(), parsed.description()), ("503", "No Responders"));
/// # Ok::<(), utopia_nats::Error>(())
/// ```
///
/// ```php
/// use Utopia\NATS\Headers;
///
/// $headers = (new Headers())->set('Nats-Msg-Id', 'order-1')->add('X-Tag', 'a')->add('X-Tag', 'b');
/// $headers->get('X-Tag'); // 'a'
///
/// $parsed = Headers::fromWire("NATS/1.0 503 No Responders\r\n\r\n");
/// [$parsed->getStatus(), $parsed->getDescription()]; // ['503', 'No Responders']
/// ```
///
/// PHP: `Utopia\NATS\Headers`.
#[derive(Debug, Clone, Default, PartialEq, Eq)]
pub struct Headers {
    values: IndexMap<String, Vec<String>>,
    status: String,
    description: String,
}

impl Headers {
    /// No headers and no status.
    ///
    /// PHP: `new Headers()`.
    pub fn new() -> Self {
        Self::default()
    }

    /// Sets `name` to the single value `value`, replacing any values it had.
    ///
    /// PHP: `Headers::set()`.
    pub fn set(&mut self, name: impl Into<String>, value: impl Into<String>) -> &mut Self {
        self.values.insert(name.into(), vec![value.into()]);
        self
    }

    /// Appends `value` to the values of `name`.
    ///
    /// PHP: `Headers::add()`.
    pub fn add(&mut self, name: impl Into<String>, value: impl Into<String>) -> &mut Self {
        self.values.entry(name.into()).or_default().push(value.into());
        self
    }

    /// The first value of `name`.
    ///
    /// PHP: `Headers::get()`.
    pub fn get(&self, name: &str) -> Option<&str> {
        self.values.get(name).and_then(|v| v.first()).map(String::as_str)
    }

    /// Every value of `name`, in order (empty when it is not set).
    ///
    /// PHP: `Headers::getAll()`.
    pub fn get_all(&self, name: &str) -> &[String] {
        self.values.get(name).map_or(&[], Vec::as_slice)
    }

    /// Whether `name` is set.
    ///
    /// PHP: `Headers::has()`.
    pub fn has(&self, name: &str) -> bool {
        self.values.contains_key(name)
    }

    /// Removes `name` and its values, keeping the order of the others.
    ///
    /// PHP: `Headers::delete()`.
    pub fn delete(&mut self, name: &str) -> &mut Self {
        self.values.shift_remove(name);
        self
    }

    /// Every name with its values, in insertion order.
    ///
    /// PHP: `Headers::all()`, `getIterator()`.
    pub fn all(&self) -> &IndexMap<String, Vec<String>> {
        &self.values
    }

    /// Iterates names with their values, in insertion order.
    ///
    /// PHP: `Headers::getIterator()`.
    pub fn iter(&self) -> impl Iterator<Item = (&str, &[String])> {
        self.values.iter().map(|(k, v)| (k.as_str(), v.as_slice()))
    }

    /// How many names are set.
    ///
    /// PHP: `Headers::count()`.
    pub fn len(&self) -> usize {
        self.values.len()
    }

    /// Whether no name is set (the status line does not count).
    pub fn is_empty(&self) -> bool {
        self.values.is_empty()
    }

    /// The status code of the status line (`503`), or `""`.
    ///
    /// PHP: `Headers::getStatus()`.
    pub fn status(&self) -> &str {
        &self.status
    }

    /// Sets the status line's code and description.
    ///
    /// PHP: `Headers::setStatus()`.
    pub fn set_status(&mut self, status: impl Into<String>, description: impl Into<String>) -> &mut Self {
        self.status = status.into();
        self.description = description.into();
        self
    }

    /// The description of the status line (`No Responders`), or `""`.
    ///
    /// PHP: `Headers::getDescription()`.
    pub fn description(&self) -> &str {
        &self.description
    }

    /// The header block as sent on the wire: `NATS/1.0[ status[ description]]`,
    /// one `Name: value` line per value, and a blank line.
    ///
    /// PHP: `Headers::toWire()`.
    pub fn to_wire(&self) -> Vec<u8> {
        let mut out = Vec::with_capacity(12 + self.values.len() * 24);
        self.write_wire(&mut out);
        out
    }

    /// [`Headers::to_wire`], appended to `out`.
    pub(crate) fn write_wire(&self, out: &mut Vec<u8>) {
        out.extend_from_slice(b"NATS/1.0");
        if !self.status.is_empty() {
            out.push(b' ');
            out.extend_from_slice(self.status.as_bytes());
            if !self.description.is_empty() {
                out.push(b' ');
                out.extend_from_slice(self.description.as_bytes());
            }
        }
        out.extend_from_slice(b"\r\n");
        for (name, values) in &self.values {
            for value in values {
                out.extend_from_slice(name.as_bytes());
                out.extend_from_slice(b": ");
                out.extend_from_slice(value.as_bytes());
                out.extend_from_slice(b"\r\n");
            }
        }
        out.extend_from_slice(b"\r\n");
    }

    /// Parses a header block: the `NATS/1.0` status line, then `Name: value`
    /// lines (leading whitespace of values dropped). Lines without a colon
    /// are skipped. Bytes that are not UTF-8 become U+FFFD.
    ///
    /// # Errors
    ///
    /// [`Error::Protocol`] for an empty block or one that does not start
    /// with `NATS/1.0`.
    ///
    /// PHP: `Headers::fromWire()`.
    pub fn from_wire(raw: &[u8]) -> Result<Headers> {
        if raw.is_empty() {
            return Err(Error::Protocol("Empty header block".into()));
        }
        let mut headers = Headers::new();
        let mut lines = Lines { rest: Some(raw) };
        let status_line = lines.next().unwrap_or_default();
        if !status_line.starts_with(b"NATS/1.0") {
            return Err(Error::Protocol(format!("Invalid header version: {}", String::from_utf8_lossy(status_line))));
        }
        let remainder = php_std::string::trim(&status_line[8..], PHP_WHITESPACE);
        if !remainder.is_empty() {
            match remainder.iter().position(|&b| b == b' ') {
                Some(at) => {
                    headers.status = text(&remainder[..at]);
                    headers.description = text(&remainder[at + 1..]);
                }
                None => headers.status = text(remainder),
            }
        }
        for line in lines {
            if line.is_empty() {
                continue;
            }
            let Some(colon) = line.iter().position(|&b| b == b':') else {
                continue;
            };
            let name = text(&line[..colon]);
            let value = text(php_std::string::ltrim(&line[colon + 1..], PHP_WHITESPACE));
            headers.values.entry(name).or_default().push(value);
        }
        Ok(headers)
    }
}

impl<'a> IntoIterator for &'a Headers {
    type Item = (&'a String, &'a Vec<String>);
    type IntoIter = indexmap::map::Iter<'a, String, Vec<String>>;

    fn into_iter(self) -> Self::IntoIter {
        self.values.iter()
    }
}

fn text(bytes: &[u8]) -> String {
    String::from_utf8_lossy(bytes).into_owned()
}

/// `explode("\r\n", $raw)`.
struct Lines<'a> {
    rest: Option<&'a [u8]>,
}

impl<'a> Iterator for Lines<'a> {
    type Item = &'a [u8];

    fn next(&mut self) -> Option<&'a [u8]> {
        let rest = self.rest?;
        match rest.windows(2).position(|w| w == b"\r\n") {
            Some(at) => {
                self.rest = Some(&rest[at + 2..]);
                Some(&rest[..at])
            }
            None => {
                self.rest = None;
                Some(rest)
            }
        }
    }
}
