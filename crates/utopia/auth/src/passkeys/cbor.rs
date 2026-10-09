//! CBOR as spomky-labs/cbor-php decodes and normalizes it for webauthn-lib:
//! the same reads (so the same "Out of range" errors), the same head and
//! indefinite-length checks, and the same normalization into PHP values
//! (integers become decimal strings, map keys become array offsets).

use php_std::zval::{Array, Key, Zval};

/// A decoded item. Lengths and integer arguments are kept as read.
#[derive(Debug, Clone, PartialEq)]
pub(crate) enum Item {
    /// Major type 0 / 1: the decimal value (cbor-php's `getValue()`).
    Int(String),
    Bytes(Vec<u8>),
    Text(Vec<u8>),
    List(Vec<Item>),
    /// A definite-length map (`MapObject`).
    Map(Vec<(Item, Item)>),
    /// An indefinite-length map (`IndefiniteLengthMapObject`, not a `MapObject`).
    MapIndefinite(Vec<(Item, Item)>),
    /// A tag and its content.
    Tag(u128, Box<Item>),
    Simple(i64),
    Bool(bool),
    Null,
    Float(f64),
    Break,
}

const MAX_DEPTH: usize = 1000;

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

    fn read(&mut self, length: usize) -> Result<&'a [u8], String> {
        if length == 0 {
            return Ok(&[]);
        }
        let available = self.data.len() - self.at;
        if available < length {
            return Err(format!("Out of range. Expected: {length}, read: {available}."));
        }
        let out = &self.data[self.at..self.at + length];
        self.at += length;
        Ok(out)
    }
}

/// `Utils::binToInt()`: an argument as a PHP integer.
fn bin_to_int(value: &[u8]) -> Result<usize, String> {
    let v = value.iter().fold(0u128, |acc, b| (acc << 8) | u128::from(*b));
    if v > i64::MAX as u128 {
        return Err(format!("Out of range. \"{v}\" cannot be represented as a PHP integer."));
    }
    usize::try_from(v).map_err(|_| format!("Out of range. \"{v}\" cannot be represented as a PHP integer."))
}

fn argument(ai: u8, val: Option<&[u8]>) -> u128 {
    match val {
        None => u128::from(ai),
        Some(v) => v.iter().fold(0u128, |acc, b| (acc << 8) | u128::from(*b)),
    }
}

/// `Decoder::decode()`.
pub(crate) fn decode(stream: &mut Stream<'_>) -> Result<Item, String> {
    process(stream, false, 0)
}

fn process(stream: &mut Stream<'_>, breakable: bool, depth: usize) -> Result<Item, String> {
    if depth > MAX_DEPTH {
        return Err(format!("Cannot parse the data. Maximum nesting depth of {MAX_DEPTH} exceeded."));
    }
    let ib = stream.read(1)?[0];
    let (mt, ai) = (ib >> 5, ib & 0x1f);
    let val = match ai {
        24 => Some(stream.read(1)?),
        25 => Some(stream.read(2)?),
        26 => Some(stream.read(4)?),
        27 => Some(stream.read(8)?),
        28..=30 => {
            return Err(format!("Cannot parse the data. Found invalid Additional Information \"{ai:08b}\" ({ai})."));
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
            let mut items = Vec::new();
            for _ in 0..n {
                let key = process(stream, false, depth + 1)?;
                let value = process(stream, false, depth + 1)?;
                items.push((key, value));
            }
            Item::Map(items)
        }
        6 => {
            let tag = argument(ai, val);
            let content = process(stream, false, depth + 1)?;
            tag_check(tag, &content)?;
            Item::Tag(tag, Box::new(content))
        }
        _ => other(ai, val)?,
    })
}

/// The content checks of cbor-php's registered tags (its `TagManager`).
fn tag_check(tag: u128, content: &Item) -> Result<(), String> {
    let text = matches!(content, Item::Text(_));
    let bytes = matches!(content, Item::Bytes(_));
    match tag {
        // DatetimeTag says "Byte String" although it wants a text string.
        0 if !text => Err("This tag only accepts a Byte String object.".into()),
        2 | 3 | 24 if !bytes => Err("This tag only accepts a Byte String object.".into()),
        32..=36 if !text => Err("This tag only accepts a Text String object.".into()),
        _ => Ok(()),
    }
}

/// RFC 3339 as `DateTimeImmutable::createFromFormat(DATE_RFC3339 | 'Y-m-d\TH:i:s.uP')` accepts it.
fn is_rfc3339(s: &[u8]) -> bool {
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
fn other(ai: u8, val: Option<&[u8]>) -> Result<Item, String> {
    Ok(match ai {
        20 => Item::Bool(false),
        21 => Item::Bool(true),
        22 | 23 => Item::Null,
        25 => {
            let bits = u16::from_be_bytes([val.unwrap_or(&[0, 0])[0], val.unwrap_or(&[0, 0])[1]]);
            let exponent = (bits >> 10) & 0x1f;
            let mantissa = f64::from(bits & 0x3ff);
            let sign = if bits >> 15 == 1 { -1.0 } else { 1.0 };
            let v = match exponent {
                0 => mantissa * 2f64.powi(-24),
                0x1f if mantissa == 0.0 => f64::INFINITY,
                0x1f => f64::NAN,
                e => (mantissa + 1024.0) * 2f64.powi(i32::from(e) - 25),
            };
            Item::Float(sign * v)
        }
        26 => Item::Float(f64::from(f32::from_be_bytes(val.unwrap_or(&[0; 4]).try_into().unwrap_or([0; 4])))),
        27 => Item::Float(f64::from_be_bytes(val.unwrap_or(&[0; 8]).try_into().unwrap_or([0; 8]))),
        _ => Item::Simple(argument(ai, val) as i64),
    })
}

fn process_infinite(stream: &mut Stream<'_>, mt: u8, breakable: bool, depth: usize) -> Result<Item, String> {
    match mt {
        2 | 3 => {
            let mut out = Vec::new();
            loop {
                match process(stream, true, depth + 1)? {
                    Item::Break => break,
                    Item::Bytes(b) if mt == 2 => out.extend_from_slice(&b),
                    Item::Text(t) if mt == 3 => out.extend_from_slice(&t),
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
            let mut items = Vec::new();
            loop {
                let key = process(stream, true, depth + 1)?;
                if key == Item::Break {
                    break;
                }
                let value = process(stream, false, depth + 1)?;
                items.push((key, value));
            }
            Ok(Item::MapIndefinite(items))
        }
        7 if breakable => Ok(Item::Break),
        7 => Err("Cannot parse the data. No enclosing indefinite.".into()),
        _ => Err(format!("Cannot parse the data. Found infinite length for Major Type \"{mt:05b}\" ({mt}).")),
    }
}

/// PHP's `get_debug_type()` of a normalized value, for map key errors.
fn debug_type(item: &Item) -> &'static str {
    match item {
        Item::List(_) | Item::Map(_) | Item::MapIndefinite(_) => "array",
        Item::Float(_) => "float",
        Item::Bool(_) => "bool",
        Item::Null => "null",
        Item::Tag(..) | Item::Break => "object",
        Item::Int(_) | Item::Bytes(_) | Item::Text(_) => "string",
        Item::Simple(_) => "int",
    }
}

impl Item {
    /// Whether cbor-php's object implements `Normalizable`.
    pub(crate) fn is_normalizable(&self) -> bool {
        !matches!(self, Item::Break)
    }

    /// `normalize()`: the PHP value webauthn-lib reads.
    pub(crate) fn normalize(&self) -> Result<Zval, String> {
        Ok(match self {
            Item::Int(s) => Zval::String(s.clone().into_bytes()),
            Item::Bytes(b) | Item::Text(b) => Zval::String(b.clone()),
            Item::List(items) => {
                let mut out = Array::with_capacity(items.len());
                for item in items {
                    out.push(if item.is_normalizable() { item.normalize()? } else { Zval::Null });
                }
                Zval::Array(out)
            }
            Item::Map(items) | Item::MapIndefinite(items) => {
                let mut out = Array::with_capacity(items.len());
                let mut kinds: Vec<(Key, &'static str)> = Vec::new();
                for (key, value) in items {
                    let offset = match key {
                        Item::Int(s) => Key::from_bytes(s.as_bytes()),
                        Item::Bytes(b) | Item::Text(b) => Key::from_bytes(b),
                        Item::Simple(i) => Key::Int(*i),
                        other => {
                            return Err(format!(
                                "Invalid key. A map key shall normalize to an integer or a string, got \"{}\".",
                                debug_type(other)
                            ));
                        }
                    };
                    let kind = match key {
                        Item::Int(s) if s.starts_with('-') => "1",
                        Item::Int(_) => "0",
                        Item::Bytes(_) => "2",
                        Item::Text(_) => "3",
                        _ => "7",
                    };
                    if let Some((_, previous)) = kinds.iter().find(|(k, _)| *k == offset)
                        && *previous != kind
                    {
                        let shown = match &offset {
                            Key::Int(i) => i.to_string(),
                            Key::Str(s) => String::from_utf8_lossy(s).into_owned(),
                        };
                        return Err(format!(
                            "Invalid key. A key of major type {previous} and a key of major type {kind} both resolve to the offset \"{shown}\"."
                        ));
                    }
                    kinds.push((offset.clone(), kind));
                    out.insert(offset, if value.is_normalizable() { value.normalize()? } else { Zval::Null });
                }
                Zval::Array(out)
            }
            Item::Simple(i) => Zval::Int(*i),
            Item::Bool(b) => Zval::Bool(*b),
            // A datetime normalizes to a DateTimeImmutable, which no caller here reads.
            Item::Tag(0, content) => match content.as_ref() {
                Item::Text(t) if !t.contains(&0) && is_rfc3339(t) => Zval::Null,
                _ => return Err("Invalid data. Cannot be converted into a datetime object".into()),
            },
            Item::Tag(_, content) => content.normalize()?,
            Item::Null | Item::Break => Zval::Null,
            Item::Float(f) => Zval::Float(*f),
        })
    }
}
