//! Header maps: lowercased names, each with one or more values, in the order
//! the names were first set.
//!
//! PHP: PSR-7 style headers, as `Request::$headers` and `Response::$headers`
//! hold them.

use indexmap::IndexMap;

/// Headers by lowercased name, each with its values in the order they were
/// added. Lookups are case-insensitive.
///
/// PHP: `Request::$headers`, `Response::$headers`.
#[derive(Debug, Clone, Default, PartialEq, Eq)]
pub struct Headers {
    map: IndexMap<String, Vec<String>>,
}

impl Headers {
    /// An empty header map.
    pub fn new() -> Self {
        Self::default()
    }

    /// An empty header map with room for `n` names.
    pub fn with_capacity(n: usize) -> Self {
        Self { map: IndexMap::with_capacity(n) }
    }

    fn key(name: &str) -> String {
        name.to_ascii_lowercase()
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
        if name.bytes().any(|b| b.is_ascii_uppercase()) {
            self.map.get(&Self::key(name)).map(Vec::as_slice)
        } else {
            self.map.get(name).map(Vec::as_slice)
        }
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
        let key = Self::key(name);
        match self.map.get_mut(&key) {
            Some(values) => {
                values.clear();
                values.push(value.into());
            }
            None => {
                self.map.insert(key, vec![value.into()]);
            }
        }
    }

    /// Appends a value to a header.
    ///
    /// PHP: `addHeader($key, $value)`.
    pub fn add(&mut self, name: &str, value: impl Into<String>) {
        self.map.entry(Self::key(name)).or_default().push(value.into());
    }

    /// Removes a header, keeping the order of the others.
    ///
    /// PHP: `removeHeader($key)`.
    pub fn remove(&mut self, name: &str) {
        self.map.shift_remove(&Self::key(name));
    }

    /// Every header with its values, in order.
    pub fn iter(&self) -> impl ExactSizeIterator<Item = (&str, &[String])> {
        self.map.iter().map(|(k, v)| (k.as_str(), v.as_slice()))
    }

    /// The number of header names.
    pub fn len(&self) -> usize {
        self.map.len()
    }

    /// Whether no header is set.
    pub fn is_empty(&self) -> bool {
        self.map.is_empty()
    }

    /// Appends a value under an already lowercased name (no case folding).
    pub(crate) fn push_lowercase(&mut self, name: String, value: String) {
        self.map.entry(name).or_default().push(value);
    }
}
