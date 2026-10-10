//! [`Str`]: a PHP string, which is a byte string.

use std::borrow::Borrow;
use std::cmp::Ordering;
use std::fmt;
use std::hash::{Hash, Hasher};
use std::ops::Deref;

use bytes::Bytes;

/// The longest string kept inline, without an allocation.
const INLINE: usize = 23;

/// A PHP string: bytes, not necessarily UTF-8.
///
/// Three representations, chosen on construction and invisible otherwise:
/// short strings (up to 23 bytes) are stored inline, string literals borrow
/// their `'static` bytes, and longer strings share a [`Bytes`] buffer, so
/// clones and slices of decoded input never copy.
#[derive(Clone)]
pub struct Str(Repr);

#[derive(Clone)]
enum Repr {
    Inline { len: u8, buf: [u8; INLINE] },
    Static(&'static [u8]),
    Shared(Bytes),
}

impl Str {
    /// The empty string.
    pub const EMPTY: Str = Str(Repr::Static(b""));

    /// A string literal (no allocation, no copy).
    pub const fn from_static(s: &'static str) -> Self {
        Str(Repr::Static(s.as_bytes()))
    }

    /// Static bytes (no allocation, no copy).
    pub const fn from_static_bytes(s: &'static [u8]) -> Self {
        Str(Repr::Static(s))
    }

    /// Shares `b` (short strings are copied inline instead).
    pub fn from_bytes(b: Bytes) -> Self {
        if b.len() <= INLINE { Self::inline(&b) } else { Str(Repr::Shared(b)) }
    }

    /// Copies `b`.
    pub fn copy_from(b: &[u8]) -> Self {
        if b.len() <= INLINE { Self::inline(b) } else { Str(Repr::Shared(Bytes::copy_from_slice(b))) }
    }

    fn inline(b: &[u8]) -> Self {
        let mut buf = [0u8; INLINE];
        buf[..b.len()].copy_from_slice(b);
        Str(Repr::Inline { len: b.len() as u8, buf })
    }

    pub fn as_bytes(&self) -> &[u8] {
        match &self.0 {
            Repr::Inline { len, buf } => &buf[..*len as usize],
            Repr::Static(s) => s,
            Repr::Shared(b) => b,
        }
    }

    /// The string as UTF-8 text, when it is valid UTF-8.
    pub fn as_str(&self) -> Option<&str> {
        std::str::from_utf8(self.as_bytes()).ok()
    }

    pub fn len(&self) -> usize {
        self.as_bytes().len()
    }

    pub fn is_empty(&self) -> bool {
        self.len() == 0
    }

    /// The bytes as a shared buffer (a copy only for inline and static strings).
    pub fn to_bytes(&self) -> Bytes {
        match &self.0 {
            Repr::Shared(b) => b.clone(),
            Repr::Static(s) => Bytes::from_static(s),
            Repr::Inline { .. } => Bytes::copy_from_slice(self.as_bytes()),
        }
    }
}

impl Default for Str {
    fn default() -> Self {
        Str::EMPTY
    }
}

impl Deref for Str {
    type Target = [u8];

    fn deref(&self) -> &[u8] {
        self.as_bytes()
    }
}

impl AsRef<[u8]> for Str {
    fn as_ref(&self) -> &[u8] {
        self.as_bytes()
    }
}

impl Borrow<[u8]> for Str {
    fn borrow(&self) -> &[u8] {
        self.as_bytes()
    }
}

impl PartialEq for Str {
    fn eq(&self, other: &Self) -> bool {
        self.as_bytes() == other.as_bytes()
    }
}

impl Eq for Str {}

impl PartialEq<[u8]> for Str {
    fn eq(&self, other: &[u8]) -> bool {
        self.as_bytes() == other
    }
}

impl PartialEq<str> for Str {
    fn eq(&self, other: &str) -> bool {
        self.as_bytes() == other.as_bytes()
    }
}

impl PartialEq<&str> for Str {
    fn eq(&self, other: &&str) -> bool {
        self.as_bytes() == other.as_bytes()
    }
}

impl PartialOrd for Str {
    fn partial_cmp(&self, other: &Self) -> Option<Ordering> {
        Some(self.cmp(other))
    }
}

/// Byte order (`strcmp`), not PHP's `<=>` (see [`crate::types::Value::cmp_php`]).
impl Ord for Str {
    fn cmp(&self, other: &Self) -> Ordering {
        self.as_bytes().cmp(other.as_bytes())
    }
}

/// Hashes like `[u8]`, so maps keyed by `Str` can be queried with `&[u8]`.
impl Hash for Str {
    fn hash<H: Hasher>(&self, state: &mut H) {
        self.as_bytes().hash(state);
    }
}

impl fmt::Debug for Str {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        fmt::Debug::fmt(&String::from_utf8_lossy(self.as_bytes()), f)
    }
}

impl From<&str> for Str {
    fn from(s: &str) -> Self {
        Str::copy_from(s.as_bytes())
    }
}

impl From<String> for Str {
    fn from(s: String) -> Self {
        Str::from(s.into_bytes())
    }
}

impl From<&[u8]> for Str {
    fn from(s: &[u8]) -> Self {
        Str::copy_from(s)
    }
}

impl From<Vec<u8>> for Str {
    fn from(s: Vec<u8>) -> Self {
        if s.len() <= INLINE { Str::inline(&s) } else { Str(Repr::Shared(Bytes::from(s))) }
    }
}

impl From<Bytes> for Str {
    fn from(b: Bytes) -> Self {
        Str::from_bytes(b)
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn representations_are_invisible() {
        let long = "x".repeat(40);
        for s in [Str::from("abc"), Str::from_static("abc"), Str::from_bytes(Bytes::from_static(b"abc"))] {
            assert_eq!(s, *"abc");
            assert_eq!(s.len(), 3);
        }
        assert_eq!(Str::from(long.clone()), Str::from_static(Box::leak(long.into_boxed_str())));
        assert!(Str::EMPTY.is_empty());
        assert_eq!(Str::copy_from(b"\xff").as_str(), None);
    }
}
