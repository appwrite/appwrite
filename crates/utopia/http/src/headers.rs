//! Header maps: lowercased names, each with one or more values, in the order
//! the names were first set.
//!
//! PHP: PSR-7 style headers, as `Request::$headers` and `Response::$headers`
//! hold them.

use std::borrow::Cow;

use indexmap::IndexMap;

/// Header names common enough to share one static, lowercased copy: a
/// request or response naming them allocates nothing for the name.
const KNOWN: [&str; 48] = [
    "accept",
    "accept-encoding",
    "accept-language",
    "access-control-allow-credentials",
    "access-control-allow-headers",
    "access-control-allow-methods",
    "access-control-allow-origin",
    "access-control-expose-headers",
    "authorization",
    "cache-control",
    "connection",
    "content-encoding",
    "content-length",
    "content-range",
    "content-type",
    "cookie",
    "dnt",
    "expires",
    "host",
    "if-none-match",
    "location",
    "origin",
    "pragma",
    "priority",
    "range",
    "referer",
    "sec-ch-ua",
    "sec-ch-ua-mobile",
    "sec-ch-ua-platform",
    "sec-fetch-dest",
    "sec-fetch-mode",
    "sec-fetch-site",
    "user-agent",
    "vary",
    "x-appwrite-jwt",
    "x-appwrite-key",
    "x-appwrite-locale",
    "x-appwrite-mode",
    "x-appwrite-project",
    "x-appwrite-response-format",
    "x-appwrite-session",
    "x-content-type-options",
    "x-debug-speed",
    "x-fallback-cookies",
    "x-forwarded-for",
    "x-forwarded-proto",
    "x-real-ip",
    "x-sdk-version",
];

/// A header name, lowercased: a static copy for a [`KNOWN`] name, else a
/// new string.
pub(crate) fn name(name: &str) -> Cow<'static, str> {
    match KNOWN.iter().find(|known| known.len() == name.len() && known.eq_ignore_ascii_case(name)) {
        Some(known) => Cow::Borrowed(known),
        None => Cow::Owned(name.to_ascii_lowercase()),
    }
}

/// The values of one header: most headers have one, kept without a list.
#[derive(Debug, Clone)]
enum Values {
    One(String),
    Many(Vec<String>),
}

impl Values {
    fn as_slice(&self) -> &[String] {
        match self {
            Values::One(value) => std::slice::from_ref(value),
            Values::Many(values) => values,
        }
    }

    fn push(&mut self, value: String) {
        match self {
            Values::One(first) => *self = Values::Many(vec![std::mem::take(first), value]),
            Values::Many(values) => values.push(value),
        }
    }
}

/// Headers by lowercased name, each with its values in the order they were
/// added. Lookups are case-insensitive.
///
/// PHP: `Request::$headers`, `Response::$headers`.
#[derive(Debug, Clone, Default)]
pub struct Headers {
    map: IndexMap<Cow<'static, str>, Values>,
}

impl PartialEq for Headers {
    /// Whether both have the same names, in the same order, with the same values.
    fn eq(&self, other: &Self) -> bool {
        self.len() == other.len() && self.iter().zip(other.iter()).all(|(a, b)| a == b)
    }
}

impl Eq for Headers {}

impl Headers {
    /// An empty header map.
    pub fn new() -> Self {
        Self::default()
    }

    /// An empty header map with room for `n` names.
    pub fn with_capacity(n: usize) -> Self {
        Self { map: IndexMap::with_capacity(n) }
    }

    /// Whether a header is set.
    ///
    /// PHP: `hasHeader($key)`.
    pub fn has(&self, name: &str) -> bool {
        self.get(name).is_some()
    }

    /// Every value of a header, or `None` when it is not set.
    ///
    /// PHP: `getHeader($key)`.
    pub fn get(&self, name: &str) -> Option<&[String]> {
        let values = if name.bytes().any(|b| b.is_ascii_uppercase()) {
            self.map.get(self::name(name).as_ref())
        } else {
            self.map.get(name)
        };
        values.map(Values::as_slice)
    }

    /// The first value of a header.
    pub fn first(&self, name: &str) -> Option<&str> {
        self.get(name).and_then(|v| v.first()).map(String::as_str)
    }

    /// The values of a header joined with `", "`, or `None` when it has none.
    ///
    /// PHP: `getHeaderLine($key, $default)`.
    pub fn line(&self, name: &str) -> Option<String> {
        self.get(name).filter(|v| !v.is_empty()).map(|v| v.join(", "))
    }

    /// Sets a header to a single value, replacing every value it had.
    ///
    /// PHP: `setHeader($key, $value)`.
    pub fn set(&mut self, name: &str, value: impl Into<String>) {
        self.set_lowercase(self::name(name), value.into());
    }

    /// Appends a value to a header.
    ///
    /// PHP: `addHeader($key, $value)`.
    pub fn add(&mut self, name: &str, value: impl Into<String>) {
        self.push_lowercase(self::name(name), value.into());
    }

    /// Removes a header, keeping the order of the others.
    ///
    /// PHP: `removeHeader($key)`.
    pub fn remove(&mut self, name: &str) {
        self.map.shift_remove(self::name(name).as_ref());
    }

    /// Every header with its values, in order.
    pub fn iter(&self) -> impl ExactSizeIterator<Item = (&str, &[String])> {
        self.map.iter().map(|(k, v)| (k.as_ref(), v.as_slice()))
    }

    /// The number of header names.
    pub fn len(&self) -> usize {
        self.map.len()
    }

    /// Whether no header is set.
    pub fn is_empty(&self) -> bool {
        self.map.is_empty()
    }

    /// Sets a header under an already lowercased name (no case folding),
    /// replacing every value it had.
    pub(crate) fn set_lowercase(&mut self, name: Cow<'static, str>, value: String) {
        match self.map.get_mut(name.as_ref()) {
            Some(values) => *values = Values::One(value),
            None => {
                self.map.insert(name, Values::One(value));
            }
        }
    }

    /// Appends a value under an already lowercased name (no case folding).
    pub(crate) fn push_lowercase(&mut self, name: Cow<'static, str>, value: String) {
        match self.map.get_mut(name.as_ref()) {
            Some(values) => values.push(value),
            None => {
                self.map.insert(name, Values::One(value));
            }
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn names_fold_once() {
        assert!(matches!(name("Content-Type"), Cow::Borrowed("content-type")));
        assert_eq!(name("X-Custom"), "x-custom");
        let mut headers = Headers::new();
        headers.add("X-A", "1");
        headers.add("x-a", "2");
        headers.set("Host", "h");
        headers.add("HOST", "i");
        assert_eq!(headers.get("x-A"), Some(&["1".to_owned(), "2".to_owned()][..]));
        assert_eq!(headers.line("host").as_deref(), Some("h, i"));
        headers.set("x-a", "3");
        assert_eq!(headers.get("X-A"), Some(&["3".to_owned()][..]));
        headers.remove("X-A");
        assert_eq!(headers.iter().map(|(k, _)| k).collect::<Vec<_>>(), ["host"]);
    }
}
