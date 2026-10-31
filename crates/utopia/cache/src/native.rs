//! [`Native`]: the codec of entries only Rust reads and writes.

use serde::Serialize;
use serde::de::DeserializeOwned;

use crate::memory::fresh;
use crate::{Error, Result};

/// Typed Rust values as compact binary entries, for caches only Rust reads
/// and writes.
///
/// A cache shared with a PHP runtime keeps PHP's codec ([`Json`](crate::Json)
/// or [`Igbinary`](crate::Igbinary)), so both runtimes read each other's
/// entries; a cache only Rust reads uses this one. It stores any `serde`
/// type with [postcard](https://docs.rs/postcard), without building PHP
/// values first: the project store keeps its own `Project` struct, not a PHP
/// array. Postcard does not describe its data, so the type must not need a
/// self-describing format (no `serde_json::Value`, untagged enums or
/// `flatten`).
///
/// An entry is a header (a marker byte, `N`, and [`Native::VERSION`]), the
/// save time in unix seconds (8 bytes, little endian) and the payload. Bytes
/// without that header (an entry another codec or an older version wrote)
/// are a miss: the next save replaces them, nothing misreads them. Because
/// the payload carries no field names, change the field (or key) an entry is
/// stored under whenever its type changes.
///
/// [`Cache::load_native`](crate::Cache::load_native) and
/// [`Cache::save_native`](crate::Cache::save_native) store entries with it.
///
/// ```
/// use serde::{Deserialize, Serialize};
/// use utopia_cache::Native;
///
/// #[derive(Debug, PartialEq, Serialize, Deserialize)]
/// struct Team {
///     id: String,
///     members: Vec<String>,
/// }
///
/// let team = Team { id: "core".into(), members: vec!["ada".into(), "grace".into()] };
/// let stored = Native.encode(&team, 1_700_000_000)?;
///
/// // Fresh while `time + ttl > now`.
/// assert_eq!(Native.decode::<Team>(&stored, 60, 1_700_000_059), Some(team));
/// assert_eq!(Native.decode::<Team>(&stored, 60, 1_700_000_060), None);
///
/// // A JSON envelope is not a native entry: a miss, never a misread.
/// assert_eq!(Native.decode::<Team>(br#"{"time":1700000000,"data":"x"}"#, 60, 1_700_000_000), None);
/// # Ok::<(), utopia_cache::Error>(())
/// ```
#[derive(Debug, Clone, Copy, Default, PartialEq, Eq)]
pub struct Native;

/// Header bytes before the time: a marker no PHP codec starts with (`0xF5`
/// is never valid UTF-8, so never JSON or `serialize()` text, and igbinary
/// starts with `0x00`), `N`, and the format version.
const HEADER: [u8; 3] = [0xF5, b'N', Native::VERSION];

/// Bytes before the payload: the header and the time.
const PREFIX: usize = HEADER.len() + 8;

impl Native {
    /// The entry format's version, stored in every entry's header.
    pub const VERSION: u8 = 1;

    /// The entry stored for `value` saved at `time` (unix seconds).
    ///
    /// Fails ([`Error::Runtime`]) when the value cannot be written: a type
    /// whose `Serialize` needs a self-describing format.
    pub fn encode<T: Serialize + ?Sized>(&self, value: &T, time: i64) -> Result<Vec<u8>> {
        let mut out = Vec::with_capacity(256);
        out.extend_from_slice(&HEADER);
        out.extend_from_slice(&time.to_le_bytes());
        postcard::to_extend(value, out).map_err(|e| Error::Runtime(format!("Native cache entry: {e}")))
    }

    /// The value of an entry while it is fresh (`time + ttl > now`). `None`
    /// when it expired, or when the bytes are not a native entry of this
    /// version holding exactly one `T`: never an error.
    pub fn decode<T: DeserializeOwned>(&self, stored: &[u8], ttl: i64, now: i64) -> Option<T> {
        let time = Self::time(stored)?;
        if !fresh(time, ttl, now) {
            return None;
        }
        match postcard::take_from_bytes::<T>(&stored[PREFIX..]) {
            Ok((value, [])) => Some(value),
            _ => None,
        }
    }

    /// The save time of a native entry of this version; `None` for other bytes.
    pub fn time(stored: &[u8]) -> Option<i64> {
        if stored.len() < PREFIX || stored[..HEADER.len()] != HEADER {
            return None;
        }
        Some(i64::from_le_bytes(stored[HEADER.len()..PREFIX].try_into().ok()?))
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde::Deserialize;

    #[derive(Debug, PartialEq, Serialize, Deserialize)]
    struct Doc {
        id: String,
        sequence: i64,
        tags: Vec<String>,
        parent: Option<Box<Doc>>,
    }

    fn doc() -> Doc {
        Doc {
            id: "a".into(),
            sequence: -3,
            tags: vec!["x".into(), String::new()],
            parent: Some(Box::new(Doc { id: "b".into(), sequence: 9, tags: vec![], parent: None })),
        }
    }

    #[test]
    fn round_trips_while_fresh() {
        let stored = Native.encode(&doc(), 100).unwrap();
        assert_eq!(&stored[..3], &[0xF5, b'N', 1]);
        assert_eq!(Native::time(&stored), Some(100));
        assert_eq!(Native.decode::<Doc>(&stored, 10, 109), Some(doc()));
        assert_eq!(Native.decode::<Doc>(&stored, 10, 110), None);
        // PHP's overflow into floats: a huge TTL never expires.
        assert_eq!(Native.decode::<Doc>(&stored, i64::MAX, 110), Some(doc()));
    }

    #[test]
    fn foreign_bytes_are_misses() {
        let stored = Native.encode(&doc(), 100).unwrap();
        let mut older = stored.clone();
        older[2] = 0;
        let mut longer = stored.clone();
        longer.push(0);
        let cases: [&[u8]; 7] = [
            b"",
            b"{\"time\":100,\"data\":\"x\"}",
            b"\x00\x00\x00\x02\x14\x02",
            &stored[..PREFIX],
            &stored[..stored.len() - 1],
            &older,
            &longer,
        ];
        for bytes in cases {
            assert_eq!(Native.decode::<Doc>(bytes, 60, 100), None, "{bytes:?}");
        }
        // Another type's entry does not decode as this one.
        let list = Native.encode(&vec![1u8, 2, 3], 100).unwrap();
        assert_eq!(Native.decode::<Doc>(&list, 60, 100), None);
    }

    #[test]
    fn self_describing_types_fail_to_encode() {
        struct Unsized;
        impl Serialize for Unsized {
            fn serialize<S: serde::Serializer>(&self, serializer: S) -> std::result::Result<S::Ok, S::Error> {
                use serde::ser::SerializeSeq;
                serializer.serialize_seq(None)?.end()
            }
        }
        assert!(matches!(Native.encode(&Unsized, 0), Err(Error::Runtime(_))));
    }
}
