//! `igbinary_serialize` and `igbinary_unserialize`, byte-identical to the
//! igbinary extension (3.2, format version 2, `igbinary.compact_strings=On`)
//! of the PHP image. Appwrite's cache and queue payloads use it.
//!
//! Every function here is checked against the extension by
//! `bin/compat fuzz php-std` (operations `igbinary.*`).
//!
//! | PHP | Rust |
//! |---|---|
//! | `igbinary_serialize($v)` | [`serialize`] |
//! | `igbinary_unserialize($s)` | [`unserialize`] (strings share the input buffer) |
//! | its warnings (`igbinary_unserialize_*: ...`) | [`Error::Invalid`] |
//!
//! The format: a 4-byte version header (`00 00 00 02`), then one value.
//! Integers take the smallest of 1, 2, 4 or 8 bytes with the sign in the
//! type; floats are IEEE 754 big-endian; every non-empty string (values,
//! keys, class names) is written once and referenced by its index after
//! that; arrays and objects are a count and key/value pairs; containers
//! get a reference id in pre-order for back-references.
//!
//! Identity: PHP writes a back-reference when the same array or object
//! instance appears twice, which a [`Value`] (no identity) cannot know. The
//! engine shares one immutable empty array (`[]` literals, `json_decode`,
//! `unserialize`, `igbinary_unserialize`), so [`serialize`] writes every
//! empty array after the first as a back-reference to it, as PHP does for
//! such data. Back-references PHP wrote for other shared instances or PHP
//! references read as copies, and are written in full again.
//! Objects of other classes (`__PHP_Incomplete_Class` in PHP), recursive
//! structures and nesting deeper than [`MAX_DEPTH`] are [`Error::Unsupported`].

use std::collections::{HashMap, HashSet};
use std::fmt;

use bytes::Bytes;

use crate::types::{Array, ArrayKey, Extension, KeyRef, Str, Value};

const NULL: u8 = 0x00;
const REF8: u8 = 0x01;
const REF16: u8 = 0x02;
const REF32: u8 = 0x03;
const FALSE: u8 = 0x04;
const TRUE: u8 = 0x05;
const LONG8P: u8 = 0x06;
const LONG8N: u8 = 0x07;
const LONG16P: u8 = 0x08;
const LONG16N: u8 = 0x09;
const LONG32P: u8 = 0x0a;
const LONG32N: u8 = 0x0b;
const DOUBLE: u8 = 0x0c;
const STRING_EMPTY: u8 = 0x0d;
const STRING_ID8: u8 = 0x0e;
const STRING_ID16: u8 = 0x0f;
const STRING_ID32: u8 = 0x10;
const STRING8: u8 = 0x11;
const STRING16: u8 = 0x12;
const STRING32: u8 = 0x13;
const ARRAY8: u8 = 0x14;
const ARRAY16: u8 = 0x15;
const ARRAY32: u8 = 0x16;
const OBJECT8: u8 = 0x17;
const OBJECT16: u8 = 0x18;
const OBJECT32: u8 = 0x19;
const OBJECT_ID8: u8 = 0x1a;
const OBJECT_ID16: u8 = 0x1b;
const OBJECT_ID32: u8 = 0x1c;
const LONG64P: u8 = 0x20;
const LONG64N: u8 = 0x21;
const OBJREF8: u8 = 0x22;
const OBJREF16: u8 = 0x23;
const OBJREF32: u8 = 0x24;
const REF: u8 = 0x25;
const STRING64: u8 = 0x26;

/// Nesting deeper than this is refused when reading (PHP recurses without a
/// limit and exhausts its stack eventually).
pub const MAX_DEPTH: usize = 4096;

// ---------------------------------------------------------------------------
// igbinary_serialize
// ---------------------------------------------------------------------------

/// `igbinary_serialize($value)`.
pub fn serialize<X: Extension>(value: &Value<X>) -> Vec<u8> {
    let mut s = Serializer { out: Vec::with_capacity(64), strings: HashMap::new(), references: 0, empty_array: None };
    s.out.extend_from_slice(&[0, 0, 0, 2]);
    s.value(value);
    s.out
}

struct Serializer {
    out: Vec<u8>,
    /// The string table: each non-empty string's index.
    strings: HashMap<Str, u32>,
    /// Reference ids handed out (arrays and objects, in pre-order).
    references: u32,
    /// The id of the shared empty array, once written.
    empty_array: Option<u32>,
}

impl Serializer {
    fn sized(&mut self, n: u64, t8: u8, t16: u8, t32: u8) {
        if n <= 0xff {
            self.out.extend_from_slice(&[t8, n as u8]);
        } else if n <= 0xffff {
            self.out.push(t16);
            self.out.extend_from_slice(&(n as u16).to_be_bytes());
        } else {
            self.out.push(t32);
            self.out.extend_from_slice(&(n as u32).to_be_bytes());
        }
    }

    fn long(&mut self, i: i64) {
        let (m, p) = (i.unsigned_abs(), i >= 0);
        if m <= 0xffff_ffff {
            if p { self.sized(m, LONG8P, LONG16P, LONG32P) } else { self.sized(m, LONG8N, LONG16N, LONG32N) }
        } else {
            self.out.push(if p { LONG64P } else { LONG64N });
            self.out.extend_from_slice(&m.to_be_bytes());
        }
    }

    /// `igbinary_serialize_string`: the empty string, an index into the
    /// table, or the bytes (which join the table).
    fn string(&mut self, s: &[u8], owned: impl FnOnce() -> Str) {
        if s.is_empty() {
            self.out.push(STRING_EMPTY);
            return;
        }
        if let Some(&id) = self.strings.get(s) {
            self.sized(u64::from(id), STRING_ID8, STRING_ID16, STRING_ID32);
            return;
        }
        let id = self.strings.len() as u32;
        self.strings.insert(owned(), id);
        self.chararray(s, STRING8, STRING16, STRING32);
    }

    fn chararray(&mut self, s: &[u8], t8: u8, t16: u8, t32: u8) {
        self.sized(s.len() as u64, t8, t16, t32);
        self.out.extend_from_slice(s);
    }

    fn value<X: Extension>(&mut self, value: &Value<X>) {
        match value {
            Value::Null => self.out.push(NULL),
            Value::Bool(b) => self.out.push(if *b { TRUE } else { FALSE }),
            Value::Int(i) => self.long(*i),
            Value::Float(f) => {
                self.out.push(DOUBLE);
                self.out.extend_from_slice(&f.to_bits().to_be_bytes());
            }
            Value::Str(s) => self.string(s, || s.clone()),
            Value::Array(a) => self.array(a),
            Value::Ext(x) => self.array(&x.to_array()),
            Value::Object(props) => {
                self.references += 1;
                const STD_CLASS: &[u8] = b"stdClass";
                match self.strings.get(STD_CLASS) {
                    Some(&id) => self.sized(u64::from(id), OBJECT_ID8, OBJECT_ID16, OBJECT_ID32),
                    None => {
                        let id = self.strings.len() as u32;
                        self.strings.insert(Str::from_static_bytes(STD_CLASS), id);
                        self.chararray(STD_CLASS, OBJECT8, OBJECT16, OBJECT32);
                    }
                }
                self.entries(props);
            }
        }
    }

    fn array<X: Extension>(&mut self, a: &Array<X>) {
        if a.is_empty() {
            if let Some(id) = self.empty_array {
                self.sized(u64::from(id), REF8, REF16, REF32);
                return;
            }
            self.empty_array = Some(self.references);
        }
        self.references += 1;
        self.entries(a);
    }

    fn entries<X: Extension>(&mut self, a: &Array<X>) {
        self.sized(a.len() as u64, ARRAY8, ARRAY16, ARRAY32);
        for (k, v) in a.iter() {
            match k {
                KeyRef::Int(i) => self.long(i),
                KeyRef::Str(s) => self.string(s, || Str::copy_from(s)),
            }
            self.value(v);
        }
    }
}

// ---------------------------------------------------------------------------
// igbinary_unserialize
// ---------------------------------------------------------------------------

/// Why `igbinary_unserialize()` failed.
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum Error {
    /// Empty input: PHP returns `false` without a warning.
    Empty,
    /// PHP returns `null` after this warning.
    Invalid(String),
    /// Data PHP reads into something [`Value`] cannot hold: objects of other
    /// classes, recursive structures, or nesting deeper than [`MAX_DEPTH`].
    Unsupported(&'static str),
}

impl Error {
    /// The PHP class of the failure (`igbinary_unserialize` warns, it never throws).
    pub fn php_class(&self) -> &'static str {
        "Error"
    }
}

impl fmt::Display for Error {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        match self {
            Error::Empty => f.write_str("igbinary_unserialize(): empty input"),
            Error::Invalid(warning) => f.write_str(warning),
            Error::Unsupported(what) => write!(f, "igbinary_unserialize(): unsupported data: {what}"),
        }
    }
}

impl std::error::Error for Error {}

/// `igbinary_unserialize($data)`. Strings longer than an inline string
/// share `input`'s buffer.
pub fn unserialize(input: &Bytes) -> Result<Value, Error> {
    if input.is_empty() {
        return Err(Error::Empty);
    }
    header(input)?;
    let mut u = Unserializer::new(input);
    let value = u.run()?;
    if u.wanted.is_empty() {
        return Ok(value);
    }
    // Back-references to non-empty containers: read again, keeping copies of those.
    let wanted = std::mem::take(&mut u.wanted);
    let mut u = Unserializer::new(input);
    u.keep = wanted;
    u.run()
}

/// `igbinary_unserialize_header`.
fn header(b: &[u8]) -> Result<(), Error> {
    if b.len() < 5 {
        return Err(Error::Invalid(format!(
            "igbinary_unserialize_header: expected at least 5 bytes of data, got {} byte(s)",
            b.len()
        )));
    }
    let version = u32::from_be_bytes([b[0], b[1], b[2], b[3]]);
    if version == 1 || version == 2 {
        return Ok(());
    }
    // C `isprint` in the C locale.
    if b[..4].iter().any(|&c| !(0x20..0x7f).contains(&c)) {
        let hint = if version != 0 && version & 0xff00_0000 == version { " (wrong endianness?)" } else { "" };
        return Err(Error::Invalid(format!(
            "igbinary_unserialize_header: unsupported version: {version}, should be 1 or 2{hint}"
        )));
    }
    let mut shown = String::new();
    for &c in &b[..4] {
        if c == b'"' || c == b'\\' {
            shown.push('\\');
        }
        shown.push(char::from(c));
    }
    Err(Error::Invalid(format!(
        "igbinary_unserialize_header: unsupported version: \"{shown}\"..., should begin with a binary version header of \"\\x00\\x00\\x00\\x01\" or \"\\x00\\x00\\x00\\x02\""
    )))
}

/// A slot of the reference table.
enum Slot {
    /// A container still being read.
    Open,
    /// Not kept (no back-reference to it was seen on the first pass).
    Skipped,
    Value(Value),
}

struct Unserializer<'a> {
    input: &'a Bytes,
    pos: usize,
    strings: Vec<Str>,
    references: Vec<Slot>,
    /// Ids whose values must be kept (second pass).
    keep: HashSet<usize>,
    /// Ids a back-reference needed but that were not kept (first pass).
    wanted: HashSet<usize>,
    depth: usize,
}

fn invalid<T>(message: impl Into<String>) -> Result<T, Error> {
    Err(Error::Invalid(message.into()))
}

impl<'a> Unserializer<'a> {
    fn new(input: &'a Bytes) -> Self {
        Unserializer {
            input,
            pos: 4,
            strings: Vec::new(),
            references: Vec::new(),
            keep: HashSet::new(),
            wanted: HashSet::new(),
            depth: 0,
        }
    }

    fn run(&mut self) -> Result<Value, Error> {
        let value = self.value(false)?;
        if self.pos < self.input.len() {
            return invalid("igbinary_unserialize: received more data to unserialize than expected");
        }
        Ok(value)
    }

    fn remaining(&self) -> usize {
        self.input.len() - self.pos
    }

    /// An unsigned big-endian number of `n` bytes, or `end-of-data` from `func`.
    fn uint(&mut self, n: usize, func: &str) -> Result<u64, Error> {
        if self.remaining() < n {
            return invalid(format!("{func}: end-of-data"));
        }
        let mut v = 0u64;
        for &b in &self.input[self.pos..self.pos + n] {
            v = v << 8 | u64::from(b);
        }
        self.pos += n;
        Ok(v)
    }

    fn size_of(t: u8, t8: u8, t16: u8) -> usize {
        if t == t8 {
            1
        } else if t == t16 {
            2
        } else {
            4
        }
    }

    fn long(&mut self, t: u8) -> Result<i64, Error> {
        let n = match t {
            LONG8P | LONG8N => 1,
            LONG16P | LONG16N => 2,
            LONG32P | LONG32N => 4,
            _ => 8,
        };
        let m = self.uint(n, "igbinary_unserialize_long")?;
        Ok(if matches!(t, LONG8P | LONG16P | LONG32P | LONG64P) { m as i64 } else { (m as i64).wrapping_neg() })
    }

    /// `igbinary_unserialize_chararray`: a new string, added to the table.
    fn chararray(&mut self, t: u8) -> Result<Str, Error> {
        let n = match t {
            STRING8 | OBJECT8 => 1,
            STRING16 | OBJECT16 => 2,
            STRING32 | OBJECT32 => 4,
            _ => 8,
        };
        let len = self.uint(n, "igbinary_unserialize_chararray")?;
        if (self.remaining() as u64) < len {
            return invalid("igbinary_unserialize_chararray: end-of-data");
        }
        let end = self.pos + len as usize;
        let s = Str::from_bytes(self.input.slice(self.pos..end));
        self.pos = end;
        self.strings.push(s.clone());
        Ok(s)
    }

    /// `igbinary_unserialize_string`: a string from the table.
    fn string_id(&mut self, n: usize) -> Result<Str, Error> {
        let id = self.uint(n, "igbinary_unserialize_string")? as usize;
        match self.strings.get(id) {
            Some(s) => Ok(s.clone()),
            None => invalid("igbinary_unserialize_string: string index is out-of-bounds"),
        }
    }

    /// A string of any of the string types, or `None` if `t` is not one.
    fn any_string(&mut self, t: u8) -> Result<Option<Str>, Error> {
        Ok(Some(match t {
            STRING_EMPTY => Str::EMPTY,
            STRING8 | STRING16 | STRING32 | STRING64 => self.chararray(t)?,
            STRING_ID8 | STRING_ID16 | STRING_ID32 => self.string_id(Self::size_of(t, STRING_ID8, STRING_ID16))?,
            _ => return Ok(None),
        }))
    }

    fn byte(&mut self, func: &str) -> Result<u8, Error> {
        match self.input.get(self.pos) {
            Some(&b) => {
                self.pos += 1;
                Ok(b)
            }
            None => invalid(format!("{func}: end-of-data")),
        }
    }

    /// Opens a reference slot for a container (or a referenced scalar).
    fn open(&mut self) -> usize {
        self.references.push(Slot::Open);
        self.references.len() - 1
    }

    /// Closes a slot: keeps a copy when a back-reference needs it.
    fn close(&mut self, id: usize, value: &Value) {
        self.references[id] = if matches!(value, Value::Array(a) if a.is_empty())
            || !matches!(value, Value::Array(_) | Value::Object(_))
            || self.keep.contains(&id)
        {
            Slot::Value(value.clone())
        } else {
            Slot::Skipped
        };
    }

    /// `igbinary_unserialize_ref`: a copy of the value at a reference id.
    fn back_reference(&mut self, n: usize) -> Result<Value, Error> {
        let id = self.uint(n, "igbinary_unserialize_ref")? as usize;
        match self.references.get(id) {
            None => invalid(format!("igbinary_unserialize_ref: invalid reference {id} >= {}", self.references.len())),
            Some(Slot::Open) => Err(Error::Unsupported("a reference to a container that is still being read")),
            Some(Slot::Value(v)) => Ok(v.clone()),
            Some(Slot::Skipped) => {
                self.wanted.insert(id);
                Ok(Value::Null)
            }
        }
    }

    /// `igbinary_unserialize_zval`. `referenced`: the value follows a `REF`
    /// marker, so a scalar gets a reference slot.
    fn value(&mut self, referenced: bool) -> Result<Value, Error> {
        let t = self.byte("igbinary_unserialize_zval")?;
        let scalar = |u: &mut Self, v: Value| {
            if referenced {
                let id = u.open();
                u.close(id, &v);
            }
            Ok(v)
        };
        match t {
            NULL => scalar(self, Value::Null),
            FALSE => scalar(self, Value::Bool(false)),
            TRUE => scalar(self, Value::Bool(true)),
            LONG8P | LONG8N | LONG16P | LONG16N | LONG32P | LONG32N | LONG64P | LONG64N => {
                let i = self.long(t)?;
                scalar(self, Value::Int(i))
            }
            DOUBLE => {
                let bits = self.uint(8, "igbinary_unserialize_double")?;
                scalar(self, Value::Float(f64::from_bits(bits)))
            }
            STRING_EMPTY | STRING8 | STRING16 | STRING32 | STRING64 | STRING_ID8 | STRING_ID16 | STRING_ID32 => {
                let s = self.any_string(t)?.unwrap_or_default();
                scalar(self, Value::Str(s))
            }
            REF8 | REF16 | REF32 => self.back_reference(Self::size_of(t, REF8, REF16)),
            OBJREF8 | OBJREF16 | OBJREF32 => self.back_reference(Self::size_of(t, OBJREF8, OBJREF16)),
            REF => self.value(true),
            ARRAY8 | ARRAY16 | ARRAY32 => {
                let id = self.open();
                let a = self.entries(t, false)?;
                let v = Value::Array(a);
                self.close(id, &v);
                Ok(v)
            }
            OBJECT8 | OBJECT16 | OBJECT32 | OBJECT_ID8 | OBJECT_ID16 | OBJECT_ID32 => {
                let class = match t {
                    OBJECT8 | OBJECT16 | OBJECT32 => self.chararray(t)?,
                    _ => self.string_id(Self::size_of(t, OBJECT_ID8, OBJECT_ID16))?,
                };
                let id = self.open();
                let inner = self.byte("igbinary_unserialize_object")?;
                if !matches!(inner, ARRAY8 | ARRAY16 | ARRAY32) {
                    return invalid(format!(
                        "igbinary_unserialize_object: unknown object inner type '{inner:02x}', position {}",
                        self.pos
                    ));
                }
                let props = self.entries(inner, true)?;
                if !class.eq_ignore_ascii_case(b"stdClass") {
                    return Err(Error::Unsupported("an object of a class other than stdClass"));
                }
                let v = Value::Object(props);
                self.close(id, &v);
                Ok(v)
            }
            _ => invalid(format!("igbinary_unserialize_zval: unknown type '{t:02x}', position {}", self.pos)),
        }
    }

    /// `igbinary_unserialize_array` after its type byte: count, then pairs.
    /// Object properties keep integer keys as strings.
    fn entries(&mut self, t: u8, object: bool) -> Result<Array, Error> {
        let n = self.uint(Self::size_of(t, ARRAY8, ARRAY16), "igbinary_unserialize_array")? as usize;
        if self.remaining() < n {
            return invalid(format!(
                "igbinary_unserialize_array: data size {} smaller that requested array length {n}.",
                self.remaining()
            ));
        }
        self.depth += 1;
        if self.depth > MAX_DEPTH {
            return Err(Error::Unsupported("nesting deeper than igbinary::MAX_DEPTH"));
        }
        let mut a = Array::with_capacity(if object { 0 } else { n });
        for _ in 0..n {
            let kt = self.byte("igbinary_unserialize_array")?;
            let key = match kt {
                NULL => continue,
                LONG8P | LONG8N | LONG16P | LONG16N | LONG32P | LONG32N | LONG64P | LONG64N => {
                    let i = self.long(kt)?;
                    if object { ArrayKey::Str(Str::from(i.to_string())) } else { ArrayKey::Int(i) }
                }
                _ => match self.any_string(kt)? {
                    Some(s) => ArrayKey::Str(s),
                    None => {
                        return invalid(format!(
                            "igbinary_unserialize_array: unknown key type '{kt:02x}', position {}",
                            self.pos
                        ));
                    }
                },
            };
            let v = self.value(false)?;
            a.set(key, v);
        }
        self.depth -= 1;
        Ok(a)
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::types::Never;
    use serde_json::json;

    fn hex(b: &[u8]) -> String {
        b.iter().map(|b| format!("{b:02x}")).collect()
    }

    fn ser(j: serde_json::Value) -> String {
        hex(&serialize(&Value::<Never>::from_json(&j)))
    }

    #[test]
    fn serializes_like_the_extension() {
        assert_eq!(ser(json!(null)), "0000000200");
        assert_eq!(ser(json!(-257)), "00000002090101");
        assert_eq!(ser(json!(4294967296i64)), "00000002200000000100000000");
        assert_eq!(hex(&serialize(&Value::<Never>::Int(i64::MIN))), "00000002218000000000000000");
        assert_eq!(ser(json!(1.5)), "000000020c3ff8000000000000");
        assert_eq!(
            ser(json!({"a": 1, "b": "a", "c": "abc", "abc": "b"})),
            "00000002140411016106011101620e0011016311036162630e030e01"
        );
        assert_eq!(
            ser(json!([[], [], {"a": []}, {}])),
            "000000021404060014000601010106021401110161010106031708737464436c6173731400"
        );
        assert_eq!(ser(json!({"": 1, "x": ""})), "0000000214020d06011101780d");
    }

    #[test]
    fn round_trips_and_reads_back_references() {
        let b = Bytes::from(serialize(&Value::<Never>::from_json(&json!([{"k": [1, 2]}, [], [], "k", {}]))));
        assert_eq!(serialize(&unserialize(&b).unwrap()), b.as_ref());
        // [$a, $a] with $a = [1, 2]: the second is a back-reference.
        let shared = Bytes::from_static(
            b"\x00\x00\x00\x02\x14\x02\x06\x00\x14\x02\x06\x00\x06\x01\x06\x01\x06\x02\x06\x01\x01\x01",
        );
        assert_eq!(unserialize(&shared).unwrap().to_json(), json!([[1, 2], [1, 2]]));
        let reference = Bytes::from_static(b"\x00\x00\x00\x02\x14\x02\x06\x00\x25\x06\x01\x06\x01\x25\x01\x01");
        assert_eq!(unserialize(&reference).unwrap().to_json(), json!([1, 1]));
    }

    #[test]
    fn errors_like_the_extension() {
        let err = |b: &'static [u8]| match unserialize(&Bytes::from_static(b)) {
            Err(Error::Invalid(m)) => m,
            other => panic!("{other:?}"),
        };
        assert_eq!(unserialize(&Bytes::new()), Err(Error::Empty));
        assert_eq!(
            err(b"\x00\x00\x00\x02"),
            "igbinary_unserialize_header: expected at least 5 bytes of data, got 4 byte(s)"
        );
        assert_eq!(
            err(b"\x00\x00\x00\x03\x00"),
            "igbinary_unserialize_header: unsupported version: 3, should be 1 or 2"
        );
        assert_eq!(err(b"\x00\x00\x00\x02\xff"), "igbinary_unserialize_zval: unknown type 'ff', position 5");
        assert_eq!(
            err(b"\x00\x00\x00\x02\x14\x05\x06\x00"),
            "igbinary_unserialize_array: data size 2 smaller that requested array length 5."
        );
        assert_eq!(err(b"\x00\x00\x00\x02\x01\x00"), "igbinary_unserialize_ref: invalid reference 0 >= 0");
        assert!(matches!(
            unserialize(&Bytes::from_static(b"\x00\x00\x00\x02\x14\x01\x06\x00\x01\x00")),
            Err(Error::Unsupported(_))
        ));
    }
}
