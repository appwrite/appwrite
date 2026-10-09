//! CBOR as spomky-labs/cbor-php decodes and normalizes it for webauthn-lib:
//! the same reads (so the same "Out of range" errors), the same head and
//! indefinite-length checks, and the same normalization into PHP values
//! (integers become decimal strings, map keys become array offsets).
//! Errors are bytes: cbor-php quotes map keys, which need not be UTF-8.

use std::collections::{HashMap, HashSet};

use php_std::zval::{Array, Key, Zval};

use super::tags;

/// A decoded item. Lengths and integer arguments are kept as read.
#[derive(Debug, Clone, PartialEq)]
pub(crate) enum Item {
    /// Major type 0 / 1: the decimal value (cbor-php's `getValue()`).
    Int(String),
    Bytes(Vec<u8>),
    Text(Vec<u8>),
    List(Vec<Item>),
    /// A definite-length map (`MapObject`).
    Map(Box<Map>),
    /// An indefinite-length map (`IndefiniteLengthMapObject`, not a `MapObject`).
    MapIndefinite(Box<Map>),
    /// A tag and its content.
    Tag(u128, Box<Item>),
    Simple(i64),
    Bool(bool),
    Null,
    Undefined,
    Float(f64),
    Break,
}

const MAX_DEPTH: usize = 1000;

/// One map entry, stored at the PHP array offset its key resolves to.
#[derive(Debug, Clone, PartialEq)]
pub(crate) struct Entry {
    offset: Key,
    key: Item,
    value: Item,
}

/// A map's entries and cbor-php's `MapKeyRegistryTrait` bookkeeping: the
/// identity (`<major type>:<offset>`) owning each offset, the opaque offsets
/// (keys that do not normalize to an integer or a string, stored under NUL
/// and their encoding) and the ambiguous ones (offsets two major types
/// resolve to).
#[derive(Debug, Clone, PartialEq, Default)]
pub(crate) struct Map {
    entries: Vec<Entry>,
    identities: HashMap<Key, Vec<u8>>,
    opaque: HashSet<Key>,
    ambiguous: HashMap<Key, u8>,
}

/// The CBOR major type of a decoded item.
fn major(item: &Item) -> u8 {
    match item {
        Item::Int(s) if s.starts_with('-') => 1,
        Item::Int(_) => 0,
        Item::Bytes(_) => 2,
        Item::Text(_) => 3,
        Item::List(_) => 4,
        Item::Map(_) | Item::MapIndefinite(_) => 5,
        Item::Tag(..) => 6,
        _ => 7,
    }
}

/// `(string) $offset` of a PHP array offset.
fn written(offset: &Key) -> Vec<u8> {
    match offset {
        Key::Int(i) => i.to_string().into_bytes(),
        Key::Str(s) => s.clone(),
    }
}

/// The major type an identity (`<major>:<offset>`) records.
fn identity_major(identity: &[u8]) -> &[u8] {
    identity.split(|b| *b == b':').next().unwrap_or_default()
}

impl Map {
    pub(crate) fn len(&self) -> usize {
        self.entries.len()
    }

    /// `registerKey($key, false)` and the insertion `add()` makes.
    fn add(&mut self, key: Item, raw: &[u8], value: Item) -> Result<(), Vec<u8>> {
        let major = major(&key);
        let identity = |offset: &[u8]| [major.to_string().as_bytes(), b":", offset].concat();
        // offsetOf(): lists and maps are opaque without normalizing.
        let scalar = match &key {
            Item::List(_) | Item::Map(_) | Item::MapIndefinite(_) => None,
            k if !k.is_normalizable() => None,
            k => match k.normalize()? {
                Zval::Int(i) => Some(i.to_string().into_bytes()),
                Zval::String(s) => Some(s),
                _ => None,
            },
        };
        let opaque_offset = || {
            let mut o = vec![0u8];
            o.extend_from_slice(&canonical(raw));
            o
        };
        let (mut offset, mut opaque) = match scalar {
            Some(s) => (s, false),
            None => (opaque_offset(), true),
        };
        let mut slot = Key::from_bytes(&offset);
        let mut existing = self.identities.get(&slot).cloned();
        if !opaque && existing.as_ref().is_some_and(|e| *e != identity(&offset)) {
            // Another major type owns the offset: this key is stored opaque.
            self.ambiguous.insert(slot, major);
            offset = opaque_offset();
            opaque = true;
            slot = Key::Str(offset.clone());
            existing = self.identities.get(&slot).cloned();
        }
        let own = identity(&offset);
        if let Some(e) = existing {
            if e != own {
                return Err([
                    b"Invalid key. A key of major type ".as_slice(),
                    identity_major(&e),
                    b" and a key of major type ",
                    major.to_string().as_bytes(),
                    b" both resolve to the offset \"",
                    &offset,
                    b"\".",
                ]
                .concat());
            }
            let shown = if opaque { hex::encode(&offset[1..]).into_bytes() } else { offset };
            return Err(
                [b"Invalid key. The key \"".as_slice(), &shown, b"\" is defined more than once in the map."].concat()
            );
        }
        self.identities.insert(slot.clone(), own);
        if opaque {
            self.opaque.insert(slot.clone());
        }
        self.entries.push(Entry { offset: slot, key, value });
        Ok(())
    }

    /// `normalizeEntries()`.
    fn normalize(&self) -> Result<Zval, Vec<u8>> {
        let mut out = Array::with_capacity(self.entries.len());
        for Entry { offset, key, value } in &self.entries {
            if let Some(second) = self.ambiguous.get(offset) {
                let first = self.identities.get(offset).map(|i| identity_major(i).to_vec()).unwrap_or_default();
                return Err([
                    b"Invalid key. A key of major type ".as_slice(),
                    &first,
                    b" and a key of major type ",
                    second.to_string().as_bytes(),
                    b" both resolve to the offset \"",
                    &written(offset),
                    b"\".",
                ]
                .concat());
            }
            if self.opaque.contains(offset) {
                let kind = match key {
                    Item::List(_) | Item::Map(_) | Item::MapIndefinite(_) => "array".to_owned(),
                    k if k.is_normalizable() => debug_type(&k.normalize()?),
                    k => debug_type(&k.as_object()),
                };
                return Err(format!(
                    "Invalid key. A map key shall normalize to an integer or a string, got \"{kind}\"."
                )
                .into_bytes());
            }
            out.insert(offset.clone(), if value.is_normalizable() { value.normalize()? } else { value.as_object() });
        }
        Ok(Zval::Array(out))
    }
}

/// An item as cbor-php writes it back (`__toString()`): the bytes as read,
/// except that definite lists and maps get a minimal head for their count.
fn canonical(raw: &[u8]) -> Vec<u8> {
    fn head(mt: u8, n: u64, out: &mut Vec<u8>) {
        let mt = mt << 5;
        match n {
            0..=23 => out.push(mt | n as u8),
            24..=0xff => out.extend_from_slice(&[mt | 24, n as u8]),
            0x100..=0xffff => {
                out.push(mt | 25);
                out.extend_from_slice(&(n as u16).to_be_bytes());
            }
            0x1_0000..=0xffff_ffff => {
                out.push(mt | 26);
                out.extend_from_slice(&(n as u32).to_be_bytes());
            }
            _ => {
                out.push(mt | 27);
                out.extend_from_slice(&n.to_be_bytes());
            }
        }
    }
    fn walk(raw: &[u8], at: &mut usize, out: &mut Vec<u8>) {
        let Some(&ib) = raw.get(*at) else { return };
        *at += 1;
        let (mt, ai) = (ib >> 5, ib & 0x1f);
        let extra = match ai {
            24 => 1,
            25 => 2,
            26 => 4,
            27 => 8,
            _ => 0,
        };
        let end = (*at + extra).min(raw.len());
        let arg_bytes = &raw[*at..end];
        *at = end;
        let arg = if extra == 0 { u64::from(ai) } else { arg_bytes.iter().fold(0u64, |a, b| (a << 8) | u64::from(*b)) };
        match (mt, ai) {
            (2..=5, 31) => {
                out.push(ib);
                while *at < raw.len() && raw[*at] != 0xff {
                    walk(raw, at, out);
                    if mt == 5 {
                        walk(raw, at, out);
                    }
                }
                out.push(0xff);
                *at += 1;
            }
            (4, _) | (5, _) => {
                head(mt, arg, out);
                for _ in 0..arg * if mt == 5 { 2 } else { 1 } {
                    walk(raw, at, out);
                }
            }
            (6, _) => {
                out.push(ib);
                out.extend_from_slice(arg_bytes);
                walk(raw, at, out);
            }
            (2, _) | (3, _) => {
                out.push(ib);
                out.extend_from_slice(arg_bytes);
                let end = at.saturating_add(arg as usize).min(raw.len());
                out.extend_from_slice(&raw[*at..end]);
                *at = end;
            }
            _ => {
                out.push(ib);
                out.extend_from_slice(arg_bytes);
            }
        }
    }
    let mut out = Vec::with_capacity(raw.len());
    walk(raw, &mut 0, &mut out);
    out
}

/// cbor-php's `StringStream`.
pub(crate) struct Stream<'a> {
    data: &'a [u8],
    at: usize,
}

impl<'a> Stream<'a> {
    pub(crate) fn new(data: &'a [u8]) -> Self {
        Stream { data, at: 0 }
    }

    pub(crate) fn position(&self) -> usize {
        self.at
    }

    pub(crate) fn is_eof(&self) -> bool {
        self.at >= self.data.len()
    }

    fn read(&mut self, length: usize) -> Result<&'a [u8], Vec<u8>> {
        if length == 0 {
            return Ok(&[]);
        }
        let available = self.data.len() - self.at;
        if available < length {
            return Err(format!("Out of range. Expected: {length}, read: {available}.").into());
        }
        let out = &self.data[self.at..self.at + length];
        self.at += length;
        Ok(out)
    }
}

/// `Utils::binToInt()`: an argument as a PHP integer.
fn bin_to_int(value: &[u8]) -> Result<usize, Vec<u8>> {
    let v = value.iter().fold(0u128, |acc, b| (acc << 8) | u128::from(*b));
    let range = || format!("Out of range. \"{v}\" cannot be represented as a PHP integer.").into_bytes();
    if v > i64::MAX as u128 {
        return Err(range());
    }
    usize::try_from(v).map_err(|_| range())
}

fn argument(ai: u8, val: Option<&[u8]>) -> u128 {
    match val {
        None => u128::from(ai),
        Some(v) => v.iter().fold(0u128, |acc, b| (acc << 8) | u128::from(*b)),
    }
}

/// `Decoder::decode()`.
pub(crate) fn decode(stream: &mut Stream<'_>) -> Result<Item, Vec<u8>> {
    process(stream, false, 0)
}

fn process(stream: &mut Stream<'_>, breakable: bool, depth: usize) -> Result<Item, Vec<u8>> {
    if depth > MAX_DEPTH {
        return Err(format!("Cannot parse the data. Maximum nesting depth of {MAX_DEPTH} exceeded.").into());
    }
    let ib = stream.read(1)?[0];
    let (mt, ai) = (ib >> 5, ib & 0x1f);
    let val = match ai {
        24 => Some(stream.read(1)?),
        25 => Some(stream.read(2)?),
        26 => Some(stream.read(4)?),
        27 => Some(stream.read(8)?),
        28..=30 => {
            return Err(
                format!("Cannot parse the data. Found invalid Additional Information \"{ai:08b}\" ({ai}).").into()
            );
        }
        31 => return process_infinite(stream, mt, breakable, depth),
        _ => None,
    };
    let length = |val: Option<&[u8]>| match val {
        None => Ok(usize::from(ai)),
        Some(v) => bin_to_int(v),
    };
    Ok(match mt {
        0 => Item::Int(argument(ai, val).to_string()),
        1 => Item::Int((-1 - argument(ai, val) as i128).to_string()),
        2 => Item::Bytes(stream.read(length(val)?)?.to_vec()),
        3 => Item::Text(stream.read(length(val)?)?.to_vec()),
        4 => {
            let n = length(val)?;
            let mut items = Vec::new();
            for _ in 0..n {
                items.push(process(stream, false, depth + 1)?);
            }
            Item::List(items)
        }
        5 => {
            let n = length(val)?;
            let mut map = Map::default();
            for _ in 0..n {
                let start = stream.at;
                let key = process(stream, false, depth + 1)?;
                let raw = &stream.data[start..stream.at];
                let value = process(stream, false, depth + 1)?;
                map.add(key, raw, value)?;
            }
            Item::Map(Box::new(map))
        }
        6 => {
            let tag = argument(ai, val);
            let content = process(stream, false, depth + 1)?;
            // TagManager: the tag number as a PHP integer, then the class's checks.
            if let Some(v) = val {
                bin_to_int(v)?;
            }
            tags::check(tag, &content)?;
            Item::Tag(tag, Box::new(content))
        }
        _ => other(ai, val)?,
    })
}

/// PHP's `get_debug_type()` of a normalized value.
fn debug_type(value: &Zval) -> String {
    match value {
        Zval::Null => "null".into(),
        Zval::Bool(_) => "bool".into(),
        Zval::Float(_) => "float".into(),
        Zval::Int(_) => "int".into(),
        Zval::String(_) => "string".into(),
        Zval::Array(_) => "array".into(),
        Zval::Object(_) => tags::class_of(value).unwrap_or_default(),
    }
}

/// `HalfPrecisionFloatObject::normalize()`.
pub(crate) fn half_float(bits: u16) -> f64 {
    let exponent = (bits >> 10) & 0x1f;
    let mantissa = f64::from(bits & 0x3ff);
    let sign = if bits >> 15 == 1 { -1.0 } else { 1.0 };
    let v = match exponent {
        0 => mantissa * 2f64.powi(-24),
        0x1f if mantissa == 0.0 => f64::INFINITY,
        0x1f => f64::NAN,
        e => (mantissa + 1024.0) * 2f64.powi(i32::from(e) - 25),
    };
    sign * v
}

/// RFC 3339 as `DateTimeImmutable::createFromFormat(DATE_RFC3339 | 'Y-m-d\TH:i:s.uP')` accepts it.
pub(crate) fn is_rfc3339(s: &[u8]) -> bool {
    let digits = |r: std::ops::Range<usize>| s.get(r).is_some_and(|d| d.iter().all(u8::is_ascii_digit));
    if s.len() < 20 || !digits(0..4) || s[4] != b'-' || !digits(5..7) || s[7] != b'-' || !digits(8..10) {
        return false;
    }
    if s[10] != b'T' || !digits(11..13) || s[13] != b':' || !digits(14..16) || s[16] != b':' || !digits(17..19) {
        return false;
    }
    let mut rest = &s[19..];
    if let Some(r) = rest.strip_prefix(b".") {
        let n = r.iter().take_while(|b| b.is_ascii_digit()).count();
        if n == 0 || n > 6 {
            return false;
        }
        rest = &r[n..];
    }
    rest == b"Z" || (rest.len() == 6 && matches!(rest[0], b'+' | b'-') && rest[3] == b':')
}

/// The "other" major type: simple values and floats.
fn other(ai: u8, val: Option<&[u8]>) -> Result<Item, Vec<u8>> {
    Ok(match ai {
        24 if val.is_some_and(|v| v[0] < 32) => {
            return Err(b"Invalid simple value. Content data must be between 32 and 255.".to_vec());
        }
        20 => Item::Bool(false),
        21 => Item::Bool(true),
        22 => Item::Null,
        23 => Item::Undefined,
        25 => Item::Float(half_float(u16::from_be_bytes([val.unwrap_or(&[0, 0])[0], val.unwrap_or(&[0, 0])[1]]))),
        26 => Item::Float(f64::from(f32::from_be_bytes(val.unwrap_or(&[0; 4]).try_into().unwrap_or([0; 4])))),
        27 => Item::Float(f64::from_be_bytes(val.unwrap_or(&[0; 8]).try_into().unwrap_or([0; 8]))),
        _ => Item::Simple(argument(ai, val) as i64),
    })
}

fn process_infinite(stream: &mut Stream<'_>, mt: u8, breakable: bool, depth: usize) -> Result<Item, Vec<u8>> {
    match mt {
        2 | 3 => {
            let mut out = Vec::new();
            loop {
                // A chunk must be a definite string (not another indefinite one).
                let definite = stream.data.get(stream.at).is_some_and(|b| b & 0x1f != 31);
                match process(stream, true, depth + 1)? {
                    Item::Break => break,
                    Item::Bytes(b) if mt == 2 && definite => out.extend_from_slice(&b),
                    Item::Text(t) if mt == 3 && definite => out.extend_from_slice(&t),
                    _ if mt == 2 => {
                        return Err(
                            "Unable to parse the data. Infinite Byte String object can only get Byte String objects."
                                .into(),
                        );
                    }
                    _ => {
                        return Err(
                            "Unable to parse the data. Infinite Text String object can only get Text String objects."
                                .into(),
                        );
                    }
                }
            }
            Ok(if mt == 2 { Item::Bytes(out) } else { Item::Text(out) })
        }
        4 => {
            let mut items = Vec::new();
            loop {
                match process(stream, true, depth + 1)? {
                    Item::Break => break,
                    item => items.push(item),
                }
            }
            Ok(Item::List(items))
        }
        5 => {
            let mut map = Map::default();
            loop {
                let start = stream.at;
                let key = process(stream, true, depth + 1)?;
                if key == Item::Break {
                    break;
                }
                let raw = &stream.data[start..stream.at];
                let value = process(stream, false, depth + 1)?;
                map.add(key, raw, value)?;
            }
            Ok(Item::MapIndefinite(Box::new(map)))
        }
        7 if breakable => Ok(Item::Break),
        7 => Err("Cannot parse the data. No enclosing indefinite.".into()),
        _ => Err(format!("Cannot parse the data. Found infinite length for Major Type \"{mt:05b}\" ({mt}).").into()),
    }
}

impl Item {
    /// Whether cbor-php's object implements `Normalizable`.
    pub(crate) fn is_normalizable(&self) -> bool {
        match self {
            Item::Break => false,
            Item::Tag(t, _) => tags::normalizable(*t),
            _ => true,
        }
    }

    /// `normalize()`: the PHP value webauthn-lib reads.
    pub(crate) fn normalize(&self) -> Result<Zval, Vec<u8>> {
        Ok(match self {
            Item::Int(s) => Zval::String(s.clone().into_bytes()),
            Item::Bytes(b) | Item::Text(b) => Zval::String(b.clone()),
            Item::List(items) => {
                let mut out = Array::with_capacity(items.len());
                for item in items {
                    out.push(if item.is_normalizable() { item.normalize()? } else { item.as_object() });
                }
                Zval::Array(out)
            }
            Item::Map(map) | Item::MapIndefinite(map) => map.normalize()?,
            Item::Simple(i) => Zval::Int(*i),
            Item::Bool(b) => Zval::Bool(*b),
            Item::Tag(tag, _) if !tags::normalizable(*tag) => self.as_object(),
            Item::Tag(tag, content) => tags::normalize(*tag, content)?,
            Item::Null | Item::Undefined | Item::Break => Zval::Null,
            Item::Float(f) => Zval::Float(*f),
        })
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    /// cbor-php's nesting limit is reachable on a thread's default 2 MiB
    /// stack in optimised builds (unoptimised frames are several times
    /// larger): deep input from a client fails with its error, not an overflow.
    #[test]
    fn deep_nesting_fits_a_default_thread_stack() {
        let stack = if cfg!(debug_assertions) { 16 << 20 } else { 2 << 20 };
        std::thread::Builder::new()
            .stack_size(stack)
            .spawn(|| {
                let shapes: [(&[u8], &[u8]); 4] =
                    [(b"\x81", b""), (b"\xa1\x00", b""), (b"\x9f", b"\xff"), (b"\xd8\x18", b"")];
                for (open, close) in shapes {
                    let mut data = open.repeat(MAX_DEPTH);
                    data.push(0);
                    data.extend(close.repeat(MAX_DEPTH));
                    if let Ok(item) = decode(&mut Stream::new(&data)) {
                        let _ = item.normalize();
                    }
                    let mut deeper = open.repeat(MAX_DEPTH + 2);
                    deeper.push(0);
                    assert!(decode(&mut Stream::new(&deeper)).is_err());
                }
            })
            .expect("spawn")
            .join()
            .expect("no stack overflow");
    }

    #[test]
    fn duplicate_keys_are_rejected_while_decoding() {
        let err = decode(&mut Stream::new(b"\xa2\x01\x01\x01\x02")).expect_err("duplicate");
        assert_eq!(err, b"Invalid key. The key \"1\" is defined more than once in the map.");
        let err = decode(&mut Stream::new(b"\xa2\x81\x01\x01\x98\x01\x01\x02")).expect_err("duplicate");
        assert_eq!(err, b"Invalid key. The key \"8101\" is defined more than once in the map.");
    }

    #[test]
    fn big_numbers_normalize_to_decimal() {
        assert_eq!(tags::decimal(&[0x01, 0, 0, 0, 0, 0, 0, 0, 0]), b"18446744073709551616");
        assert_eq!(tags::decimal(&[]), b"0");
        assert_eq!(tags::increment(&[0xff, 0xff]), vec![1, 0, 0]);
    }
}
