//! PSR-7 style header maps: lowercased names, each with one or more values,
//! in the order the names were first set.

use indexmap::IndexMap;

/// Headers by lowercased name (`Request::$headers`, `Response::$headers`).
#[derive(Debug, Clone, Default, PartialEq, Eq)]
pub struct Headers {
    map: IndexMap<String, Vec<String>>,
}

impl Headers {
    pub fn new() -> Self {
        Self::default()
    }

    pub fn with_capacity(n: usize) -> Self {
        Self { map: IndexMap::with_capacity(n) }
    }

    fn key(name: &str) -> String {
        name.to_ascii_lowercase()
    }

    /// `hasHeader($key)`.
    pub fn has(&self, name: &str) -> bool {
        self.get(name).is_some()
    }

    /// `getHeader($key)`: every value, or `None`.
    pub fn get(&self, name: &str) -> Option<&[String]> {
        if name.bytes().any(|b| b.is_ascii_uppercase()) {
            self.map.get(&Self::key(name)).map(Vec::as_slice)
        } else {
            self.map.get(name).map(Vec::as_slice)
        }
    }

    /// The first value.
    pub fn first(&self, name: &str) -> Option<&str> {
        self.get(name).and_then(|v| v.first()).map(String::as_str)
    }

    /// `getHeaderLine($key, $default)`: the values joined with `", "`.
    pub fn line(&self, name: &str) -> Option<String> {
        self.get(name).filter(|v| !v.is_empty()).map(|v| v.join(", "))
    }

    /// `setHeader($key, $value)`: replaces every value.
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

    /// `addHeader($key, $value)`: appends a value.
    pub fn add(&mut self, name: &str, value: impl Into<String>) {
        self.map.entry(Self::key(name)).or_default().push(value.into());
    }

    /// `removeHeader($key)`, keeping the order of the others.
    pub fn remove(&mut self, name: &str) {
        self.map.shift_remove(&Self::key(name));
    }

    /// Every header in order.
    pub fn iter(&self) -> impl ExactSizeIterator<Item = (&str, &[String])> {
        self.map.iter().map(|(k, v)| (k.as_str(), v.as_slice()))
    }

    pub fn len(&self) -> usize {
        self.map.len()
    }

    pub fn is_empty(&self) -> bool {
        self.map.is_empty()
    }

    /// Appends a value under an already lowercased name (no case folding).
    pub(crate) fn push_lowercase(&mut self, name: String, value: String) {
        self.map.entry(name).or_default().push(value);
    }
}
