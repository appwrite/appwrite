//! cbor-php's default tags (`Decoder::DEFAULT_TAGS`): the checks each tag
//! class makes on its content when it is decoded, and what it normalizes to.
//! Unregistered tags are `GenericTag`s, which normalize to their content.
//!
//! The decimal fraction and big float tags (4, 5) need PHP's bcmath
//! extension, which the reference runtime does not load: their constructor
//! throws, so they never decode.

use php_std::zval::{Array, Object, Zval};

use super::cbor::{Item, half_float};

/// The property marking a stand-in for a PHP object other than `stdClass`.
const CLASS: &[u8] = b"\0class";

/// A PHP object of `class` (a CBOR object cbor-php cannot normalize, or a
/// `DateTimeImmutable`), as a `stdClass` carrying the class name.
pub(crate) fn object(class: &str) -> Zval {
    let mut o = Object::new();
    o.set(CLASS.to_vec(), Zval::String(class.as_bytes().to_vec()));
    Zval::Object(o)
}

/// The class of a value, as `get_debug_type()` names objects.
pub(crate) fn class_of(value: &Zval) -> Option<String> {
    match value {
        Zval::Object(o) => Some(match o.get(CLASS) {
            Some(Zval::String(c)) => String::from_utf8_lossy(c).into_owned(),
            _ => "stdClass".to_owned(),
        }),
        _ => None,
    }
}

const DATE_TIME: &str = "DateTimeImmutable";

/// Whether the tag's class implements `Normalizable`.
pub(crate) fn normalizable(tag: u128) -> bool {
    !matches!(tag, 21..=24 | 33 | 34 | 63 | 83 | 87)
}

/// The class of a tag that does not implement `Normalizable`.
pub(crate) fn class(tag: u128) -> &'static str {
    match tag {
        21 => "CBOR\\Tag\\Base64UrlEncodingTag",
        22 => "CBOR\\Tag\\Base64EncodingTag",
        23 => "CBOR\\Tag\\Base16EncodingTag",
        24 => "CBOR\\Tag\\CBOREncodingTag",
        33 => "CBOR\\Tag\\Base64UrlTag",
        34 => "CBOR\\Tag\\Base64Tag",
        63 => "CBOR\\Tag\\CBORSequenceTag",
        83 => "CBOR\\Tag\\TypedArray\\Float128BigEndianArrayTag",
        87 => "CBOR\\Tag\\TypedArray\\Float128LittleEndianArrayTag",
        _ => "CBOR\\Tag\\GenericTag",
    }
}

fn is_uint(item: &Item) -> bool {
    matches!(item, Item::Int(s) if !s.starts_with('-'))
}

fn is_list(item: &Item) -> bool {
    matches!(item, Item::List(_))
}

fn is_map(item: &Item) -> bool {
    matches!(item, Item::Map(_) | Item::MapIndefinite(_))
}

fn bytes(item: &Item) -> Option<&[u8]> {
    match item {
        Item::Bytes(b) => Some(b),
        _ => None,
    }
}

fn items(item: &Item) -> &[Item] {
    match item {
        Item::List(items) => items,
        _ => &[],
    }
}

fn fail<T>(message: impl Into<Vec<u8>>) -> Result<T, Vec<u8>> {
    Err(message.into())
}

/// The bytes per element of a typed array tag.
fn element_size(tag: u128) -> Option<usize> {
    Some(match tag {
        64 | 68 | 72 => 1,
        65 | 69 | 73 | 77 | 80 | 84 => 2,
        66 | 70 | 74 | 78 | 81 | 85 => 4,
        67 | 71 | 75 | 79 | 82 | 86 => 8,
        83 | 87 => 16,
        _ => return None,
    })
}

/// The COSE structures: (name, item count, payload index, byte string index, list index).
fn cose(tag: u128) -> Option<(&'static str, usize, Option<usize>, Option<usize>)> {
    Some(match tag {
        16 => ("CoseEncrypt0", 3, None, None),
        17 => ("CoseMac0", 4, Some(3), None),
        18 => ("CoseSign1", 4, Some(3), None),
        96 => ("CoseEncrypt", 4, None, Some(3)),
        97 => ("CoseMac", 5, Some(3), Some(4)),
        98 => ("CoseSign", 4, None, Some(3)),
        _ => return None,
    })
}

/// The tag class's constructor checks.
pub(crate) fn check(tag: u128, content: &Item) -> Result<(), Vec<u8>> {
    let text = matches!(content, Item::Text(_));
    let byte_string = matches!(content, Item::Bytes(_));
    let integer = matches!(content, Item::Int(_));
    if let Some((name, count, bytes_at, list_at)) = cose(tag) {
        if !is_list(content) {
            return fail(format!("Not a valid {name} object. Expected a List object."));
        }
        let list = items(content);
        if list.len() != count {
            return fail(format!("Not a valid {name} object. The list shall have {count} items."));
        }
        if bytes(&list[0]).is_none() {
            return fail(format!("Not a valid {name} object. The item 1 shall be a Byte String object."));
        }
        if !is_map(&list[1]) {
            return fail(format!("Not a valid {name} object. The item 2 shall be a Map object."));
        }
        if !matches!(list[2], Item::Bytes(_) | Item::Null) {
            return fail(format!("Not a valid {name} object. The item 3 shall be a Byte String object or null."));
        }
        if let Some(i) = bytes_at
            && bytes(&list[i]).is_none()
        {
            return fail(format!("Not a valid {name} object. The item {} shall be a Byte String object.", i + 1));
        }
        if let Some(i) = list_at
            && !is_list(&list[i])
        {
            return fail(format!("Not a valid {name} object. The item {} shall be a List object.", i + 1));
        }
        return Ok(());
    }
    if let Some(size) = element_size(tag) {
        let Some(b) = bytes(content) else { return fail("This tag only accepts a Byte String object.") };
        if b.len() % size != 0 {
            return fail(format!("This tag only accepts a Byte String object whose length is a multiple of {size}."));
        }
        return Ok(());
    }
    match tag {
        // DatetimeTag and MimeTag say "Byte String" although they want text.
        0 | 36 if !text => fail("This tag only accepts a Byte String object."),
        1 if !matches!(content, Item::Int(_) | Item::Float(_)) => {
            fail("This tag only accepts integer-based or float-based objects.")
        }
        2 | 3 | 24 | 37 | 42 | 63 | 257 | 260 if !byte_string => fail("This tag only accepts a Byte String object."),
        4 | 5 => fail("The extension \"bcmath\" is required to use this tag"),
        25 | 29 if !is_uint(content) => fail("This tag only accepts an Unsigned Integer object."),
        26 | 27 => {
            if !is_list(content) {
                return fail("This tag only accepts a List object.");
            }
            match items(content).first() {
                None => fail("This tag only accepts a List object that contains at least 1 item."),
                Some(Item::Text(_)) => Ok(()),
                Some(_) if tag == 26 => fail("Invalid class name. Expected a Text String object."),
                Some(_) => fail("Invalid type name. Expected a Text String object."),
            }
        }
        30 => {
            if !is_list(content) {
                return fail("This tag only accepts a List object.");
            }
            let list = items(content);
            if list.len() != 2 {
                return fail("This tag only accepts a List object that contains 2 items.");
            }
            if !matches!(&list[0], Item::Int(_) | Item::Tag(2 | 3, _)) {
                return fail("Invalid numerator. Expected an integer or a big integer object.");
            }
            if !is_uint(&list[1]) && !matches!(&list[1], Item::Tag(2, _)) {
                return fail("Invalid denominator. Expected an unsigned integer or an unsigned big integer object.");
            }
            if matches!(&list[1], Item::Int(s) if s == "0") {
                return fail("Invalid denominator. The value shall not be zero.");
            }
            Ok(())
        }
        32..=35 | 1004 if !text => fail("This tag only accepts a Text String object."),
        37 if bytes(content).is_some_and(|b| b.len() != 16) => {
            fail("This tag only accepts a 16 byte Byte String object.")
        }
        38 => {
            if !is_list(content) {
                return fail("This tag only accepts a List object.");
            }
            let list = items(content);
            if list.len() != 2 {
                return fail("This tag only accepts a List object that contains 2 items.");
            }
            if !list.iter().all(|i| matches!(i, Item::Text(_))) {
                return fail("This tag only accepts Text String objects.");
            }
            Ok(())
        }
        40 | 1040 => {
            if !is_list(content) {
                return fail("This tag only accepts a List object.");
            }
            let list = items(content);
            if list.len() != 2 {
                return fail("This tag only accepts a List object that contains 2 items.");
            }
            if !is_list(&list[0]) {
                return fail("Invalid dimensions. Expected a List object.");
            }
            if items(&list[0]).is_empty() {
                return fail("Invalid dimensions. Expected at least one dimension.");
            }
            if !items(&list[0]).iter().all(is_uint) {
                return fail("Invalid dimensions. Expected Unsigned Integer objects.");
            }
            match &list[1] {
                Item::List(_) => Ok(()),
                Item::Tag(t, _) if normalizable(*t) => Ok(()),
                _ => fail("Invalid values. Expected a List object or a typed array tag."),
            }
        }
        41 | 258 | 1003 if !is_list(content) => fail("This tag only accepts a List object."),
        52 | 54 => check_ip(if tag == 52 { 4 } else { 16 }, content),
        61 if !is_map(content) && !is_list(content) && !matches!(content, Item::Tag(..)) => {
            fail("This tag only accepts a Map object, a List object or a COSE tag.")
        }
        100 if !integer => fail("This tag only accepts an Integer object."),
        259 | 1001 | 1002 if !is_map(content) => fail("This tag only accepts a Map object."),
        260 if bytes(content).is_some_and(|b| ![4, 6, 16].contains(&b.len())) => {
            fail("This tag only accepts a 4, 6 or 16 byte Byte String object.")
        }
        261 => {
            if !is_map(content) {
                return fail("This tag only accepts a Map object.");
            }
            match content {
                Item::Map(m) | Item::MapIndefinite(m) if m.len() != 1 => {
                    fail("This tag only accepts a Map object that contains 1 item.")
                }
                _ => Ok(()),
            }
        }
        _ => Ok(()),
    }
}

/// `AbstractIpAddressTag`'s constructor.
fn check_ip(length: usize, content: &Item) -> Result<(), Vec<u8>> {
    if let Some(b) = bytes(content) {
        if b.len() != length {
            return fail(format!("This tag only accepts a {length} byte Byte String object as an address."));
        }
        return Ok(());
    }
    if !is_list(content) {
        return fail("This tag only accepts a Byte String object or a List object.");
    }
    let list = items(content);
    if list.len() != 2 {
        return fail("This tag only accepts a List object that contains 2 items.");
    }
    if let Item::Int(prefix) = &list[0]
        && !prefix.starts_with('-')
    {
        let Some(b) = bytes(&list[1]) else { return fail("Invalid prefix. Expected a Byte String object.") };
        if b.len() > length {
            return fail(format!("Invalid prefix. Expected at most {length} bytes."));
        }
        if prefix.parse::<u128>().unwrap_or(u128::MAX) > (length * 8) as u128 {
            return fail(format!("Invalid prefix length. Expected at most {}.", length * 8));
        }
        return Ok(());
    }
    let Some(address) = bytes(&list[0]) else { return fail("Invalid address. Expected a Byte String object.") };
    if address.len() != length {
        return fail(format!("Invalid address. Expected exactly {length} bytes."));
    }
    if !is_uint(&list[1]) && !matches!(&list[1], Item::Text(_)) {
        return fail("Invalid zone identifier. Expected a Text String or an Unsigned Integer object.");
    }
    Ok(())
}

/// What a normalizable content normalizes to, or the object itself.
fn passthrough(content: &Item) -> Result<Zval, Vec<u8>> {
    if content.is_normalizable() { content.normalize() } else { Ok(content.as_object()) }
}

/// The tag's `normalize()`.
pub(crate) fn normalize(tag: u128, content: &Item) -> Result<Zval, Vec<u8>> {
    const DATE: &[u8] = b"Invalid data. Cannot be converted into a datetime object";
    Ok(match tag {
        0 => match content {
            Item::Text(t) if !t.contains(&0) && super::cbor::is_rfc3339(t) => object(DATE_TIME),
            _ => return fail(DATE),
        },
        1 => match content {
            Item::Float(f) if !f.is_finite() || f.abs() > 1.0e18 => return fail(DATE),
            Item::Int(s) if s.parse::<i64>().is_err() => return fail(DATE),
            _ => object(DATE_TIME),
        },
        2 | 3 => {
            let Some(b) = bytes(content) else { return passthrough(content) };
            if b.len() > 256 {
                return fail(format!(
                    "The big number is out of range. Its byte string shall not exceed 256 bytes, got {}.",
                    b.len()
                ));
            }
            Zval::String(if tag == 2 { decimal(b) } else { [b"-".as_slice(), &decimal(&increment(b))].concat() })
        }
        30 => Zval::String(rational(content)?),
        37 => {
            let h = hex::encode(bytes(content).unwrap_or_default());
            Zval::String(
                format!("{}-{}-{}-{}-{}", &h[0..8], &h[8..12], &h[12..16], &h[16..20], &h[20..32]).into_bytes(),
            )
        }
        38 => {
            let mut out = Array::new();
            for item in items(content) {
                out.push(item.normalize()?);
            }
            Zval::Array(out)
        }
        52 | 54 => Zval::String(ip(if tag == 52 { 4 } else { 16 }, content)?),
        100 => match content {
            Item::Int(s) if s.parse::<i64>().ok().and_then(|d| d.checked_mul(86_400)).is_some() => object(DATE_TIME),
            _ => return fail(DATE),
        },
        260 => {
            let b = bytes(content).unwrap_or_default();
            Zval::String(match b.len() {
                6 => b.iter().map(|x| format!("{x:02x}")).collect::<Vec<_>>().join(":").into_bytes(),
                4 => ipv4(b),
                _ => ipv6(b),
            })
        }
        1004 => match content {
            Item::Text(t) if is_date(t) => object(DATE_TIME),
            _ => return fail(DATE),
        },
        t if element_size(t).is_some() => typed_array(t, bytes(content).unwrap_or_default())?,
        _ => passthrough(content)?,
    })
}

/// `getChunks()` decoded as the typed array's element type.
fn typed_array(tag: u128, data: &[u8]) -> Result<Zval, Vec<u8>> {
    let size = element_size(tag).unwrap_or(1);
    let mut out = Array::new();
    for chunk in data.chunks(size) {
        let mut be = chunk.to_vec();
        if matches!(tag, 69..=71 | 77..=79 | 84..=86) {
            be.reverse();
        }
        let unsigned = be.iter().fold(0u64, |acc, b| (acc << 8) | u64::from(*b));
        out.push(match tag {
            64 | 68 | 65 | 69 | 66 | 70 => Zval::Int(unsigned as i64),
            67 | 71 => match i64::try_from(unsigned) {
                Ok(i) => Zval::Int(i),
                Err(_) => Zval::String(unsigned.to_string().into_bytes()),
            },
            72 => Zval::Int(i64::from(unsigned as u8 as i8)),
            73 | 77 => Zval::Int(i64::from(unsigned as u16 as i16)),
            74 | 78 => Zval::Int(i64::from(unsigned as u32 as i32)),
            75 | 79 => Zval::Int(unsigned as i64),
            80 | 84 => Zval::Float(half_float(unsigned as u16)),
            81 | 85 => Zval::Float(f64::from(f32::from_bits(unsigned as u32))),
            _ => Zval::Float(f64::from_bits(unsigned)),
        });
    }
    Ok(Zval::Array(out))
}

/// `Utils::hexToString()`: a big-endian unsigned integer in decimal.
pub(crate) fn decimal(bytes: &[u8]) -> Vec<u8> {
    let mut limbs: Vec<u32> = bytes.iter().map(|b| u32::from(*b)).collect();
    let mut digits = Vec::new();
    while limbs.iter().any(|l| *l != 0) {
        let mut rem = 0u32;
        for l in limbs.iter_mut() {
            let cur = (rem << 8) | *l;
            *l = cur / 10;
            rem = cur % 10;
        }
        digits.push(b'0' + rem as u8);
    }
    if digits.is_empty() {
        digits.push(b'0');
    }
    digits.reverse();
    digits
}

/// A big-endian unsigned integer plus one.
pub(crate) fn increment(bytes: &[u8]) -> Vec<u8> {
    let mut out = bytes.to_vec();
    for b in out.iter_mut().rev() {
        let (v, carry) = b.overflowing_add(1);
        *b = v;
        if !carry {
            return out;
        }
    }
    out.insert(0, 1);
    out
}

/// `RationalNumberTag::normalize()` for values that fit 128 bits.
fn rational(content: &Item) -> Result<Vec<u8>, Vec<u8>> {
    let value = |item: &Item| -> Result<String, Vec<u8>> {
        match item.normalize()? {
            Zval::String(s) => Ok(String::from_utf8_lossy(&s).into_owned()),
            other => Ok(format!("{other:?}")),
        }
    };
    let list = items(content);
    let (n, d) = (value(&list[0])?, value(&list[1])?);
    if d == "0" {
        return fail("Invalid denominator. The value shall not be zero.");
    }
    let (Ok(n), Ok(d)) = (n.parse::<i128>(), d.parse::<i128>()) else {
        return Ok(format!("{n}/{d}").into_bytes());
    };
    let (mut a, mut b) = (n.unsigned_abs(), d.unsigned_abs());
    while b != 0 {
        (a, b) = (b, a % b);
    }
    let g = a.max(1) as i128;
    let (n, d) = (n / g, d / g);
    Ok(if d == 1 { n.to_string() } else { format!("{n}/{d}") }.into_bytes())
}

/// `AbstractIpAddressTag::normalize()`.
fn ip(length: usize, content: &Item) -> Result<Vec<u8>, Vec<u8>> {
    let text = |b: &[u8]| if length == 4 { ipv4(b) } else { ipv6(b) };
    if let Some(b) = bytes(content) {
        return Ok(text(b));
    }
    let list = items(content);
    if let Item::Int(prefix) = &list[0]
        && !prefix.starts_with('-')
    {
        let mut b = bytes(&list[1]).unwrap_or_default().to_vec();
        b.resize(length, 0);
        return Ok([text(&b).as_slice(), b"/", prefix.as_bytes()].concat());
    }
    let zone = match list[1].normalize()? {
        Zval::String(s) => s,
        other => format!("{other:?}").into_bytes(),
    };
    Ok([text(bytes(&list[0]).unwrap_or_default()).as_slice(), b"%", &zone].concat())
}

fn ipv4(b: &[u8]) -> Vec<u8> {
    b.iter().map(u8::to_string).collect::<Vec<_>>().join(".").into_bytes()
}

/// musl's `inet_ntop(AF_INET6)`: eight hex groups (the last two as a dotted
/// quad for IPv4-mapped addresses), then the longest run of zero groups
/// (more than one) becomes "::".
fn ipv6(b: &[u8]) -> Vec<u8> {
    let w: Vec<u16> = b.chunks(2).map(|p| u16::from_be_bytes([p[0], p.get(1).copied().unwrap_or(0)])).collect();
    let mut buf = if b[..12] == [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0xff, 0xff] {
        format!(
            "{:x}:{:x}:{:x}:{:x}:{:x}:{:x}:{}.{}.{}.{}",
            w[0], w[1], w[2], w[3], w[4], w[5], b[12], b[13], b[14], b[15]
        )
    } else {
        format!("{:x}:{:x}:{:x}:{:x}:{:x}:{:x}:{:x}:{:x}", w[0], w[1], w[2], w[3], w[4], w[5], w[6], w[7])
    }
    .into_bytes();
    let (mut best, mut max) = (0, 2);
    for i in 0..buf.len() {
        if i != 0 && buf[i] != b':' {
            continue;
        }
        let j = buf[i..].iter().take_while(|c| **c == b':' || **c == b'0').count();
        if j > max {
            best = i;
            max = j;
        }
    }
    if max > 3 {
        buf[best] = b':';
        buf[best + 1] = b':';
        buf.drain(best + 2..best + max);
    }
    buf
}

/// `DateTimeImmutable::createFromFormat('!Y-m-d', ...)` without warnings.
fn is_date(t: &[u8]) -> bool {
    let parts: Vec<&[u8]> = t.split(|c| *c == b'-').collect();
    let [y, m, d] = parts.as_slice() else { return false };
    let number = |p: &[u8], max: usize| (1..=max).contains(&p.len()) && p.iter().all(u8::is_ascii_digit);
    if !number(y, 4) || !number(m, 2) || !number(d, 2) {
        return false;
    }
    let parse = |p: &[u8]| std::str::from_utf8(p).ok().and_then(|s| s.parse::<u32>().ok()).unwrap_or(0);
    let (y, m, d) = (parse(y), parse(m), parse(d));
    let leap = (y % 4 == 0 && y % 100 != 0) || y % 400 == 0;
    let days = match m {
        1 | 3 | 5 | 7 | 8 | 10 | 12 => 31,
        4 | 6 | 9 | 11 => 30,
        2 if leap => 29,
        2 => 28,
        _ => return false,
    };
    (1..=days).contains(&d)
}

impl Item {
    /// A value cbor-php leaves as an object (its class) when normalizing.
    pub(crate) fn as_object(&self) -> Zval {
        match self {
            Item::Tag(t, _) => object(class(*t)),
            Item::Break => object("CBOR\\OtherObject\\BreakObject"),
            _ => Zval::Null,
        }
    }
}
