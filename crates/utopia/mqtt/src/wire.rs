//! The primitive encodings MQTT packets are built from: variable byte
//! integers, length-prefixed strings, two-byte integers and single bytes.
//!
//! Readers never fail on short input: a byte past the end reads as `0` and a
//! string past the end is empty, so a truncated packet decodes to zeros and
//! empty strings rather than an error, as PHP's string offsets read them.
//!
//! PHP: the static primitives of `Utopia\Mqtt\Packet` (`decodeLength()`,
//! `encodeLength()`, `readString()`, `encodeString()`, `readInt16()`,
//! `readByte()`).

/// A decoded variable byte integer.
///
/// MQTT caps the encoding at four bytes (268,435,455). The decoder accepts
/// any run of continuation bytes, as PHP's does: up to nine bytes the value is
/// an [`Int`](Length::Int); from the tenth, PHP's integer arithmetic
/// overflows into a [`Float`](Length::Float), and a run long enough reaches
/// infinity and NaN.
///
/// ```
/// use utopia_mqtt::wire::{Length, decode_length};
///
/// assert_eq!(decode_length(&[0xC8, 0x01], 0), (Length::Int(200), 2));
/// assert_eq!(decode_length(&[0x80; 9].iter().chain(&[0x01]).copied().collect::<Vec<_>>(), 0).0, Length::Float(9223372036854775808.0));
/// ```
///
/// ```php
/// use Utopia\Mqtt\Packet;
///
/// var_dump(Packet::decodeLength("\xC8\x01", 0)); // [200, 2]
/// var_dump(Packet::decodeLength(str_repeat("\x80", 9) . "\x01", 0)[0]); // float(9.2233720368547758E+18)
/// ```
///
/// PHP: the `int|float` value `Packet::decodeLength()` returns.
#[derive(Debug, Clone, Copy, PartialEq)]
pub enum Length {
    /// A value that fits a 64-bit integer.
    Int(i64),
    /// A value PHP's arithmetic carried past the 64-bit range.
    Float(f64),
}

impl Length {
    /// The value as a non-negative size, when it is an integer.
    pub fn as_usize(self) -> Option<usize> {
        match self {
            Length::Int(i) => usize::try_from(i).ok(),
            Length::Float(_) => None,
        }
    }

    /// What a PHP `int` parameter makes of the value: integers as they are,
    /// floats truncated when finite and in the 64-bit range, `None` (a
    /// `TypeError` in PHP) otherwise.
    pub(crate) fn as_int(self) -> Option<i64> {
        match self {
            Length::Int(i) => Some(i),
            Length::Float(f) if f.is_finite() && (-9223372036854775808.0..9223372036854775808.0).contains(&f) => {
                Some(f as i64)
            }
            Length::Float(_) => None,
        }
    }

    /// `$this + $other`: integer addition, carried into a float on overflow.
    pub(crate) fn plus(self, other: Length) -> Length {
        match (self, other) {
            (Length::Int(a), Length::Int(b)) => {
                a.checked_add(b).map_or(Length::Float(a as f64 + b as f64), Length::Int)
            }
            (a, b) => Length::Float(a.as_f64() + b.as_f64()),
        }
    }

    /// `$this * $n`: integer multiplication, carried into a float on overflow.
    fn times(self, n: i64) -> Length {
        match self {
            Length::Int(a) => a.checked_mul(n).map_or(Length::Float(a as f64 * n as f64), Length::Int),
            Length::Float(f) => Length::Float(f * n as f64),
        }
    }

    fn as_f64(self) -> f64 {
        match self {
            Length::Int(i) => i as f64,
            Length::Float(f) => f,
        }
    }
}

impl From<usize> for Length {
    fn from(n: usize) -> Self {
        Length::Int(n as i64)
    }
}

/// The byte at `offset`, `0` past the end.
#[inline]
pub(crate) fn byte(data: &[u8], offset: usize) -> u8 {
    data.get(offset).copied().unwrap_or(0)
}

/// Decodes the variable byte integer at `offset`: the value and the number of
/// bytes it took.
///
/// PHP: `Packet::decodeLength(string $data, int $offset)`.
pub fn decode_length(data: &[u8], offset: usize) -> (Length, usize) {
    // The common case (MQTT's own limit): integers that cannot overflow.
    let mut value: i64 = 0;
    let mut bytes = 0;
    loop {
        let b = byte(data, offset.saturating_add(bytes));
        value += i64::from(b & 0x7F) << (7 * bytes);
        bytes += 1;
        if b & 0x80 == 0 {
            return (Length::Int(value), bytes);
        }
        if bytes == 8 {
            break;
        }
    }
    let mut value = Length::Int(value);
    let mut multiplier = Length::Int(1 << 56);
    loop {
        let b = byte(data, offset.saturating_add(bytes));
        value = value.plus(multiplier.times(i64::from(b & 0x7F)));
        multiplier = multiplier.times(128);
        bytes += 1;
        if b & 0x80 == 0 {
            return (value, bytes);
        }
    }
}

/// Appends `length` as a variable byte integer (one to ten bytes; MQTT allows
/// up to four).
///
/// PHP: `Packet::encodeLength(int $length)`.
pub fn encode_length(length: u64, out: &mut Vec<u8>) {
    let mut length = length;
    loop {
        let mut b = (length % 128) as u8;
        length /= 128;
        if length > 0 {
            b |= 0x80;
        }
        out.push(b);
        if length == 0 {
            return;
        }
    }
}

/// `Packet::encodeLength()` of any PHP integer: a negative one is one byte,
/// its remainder by 128 as `chr()` wraps it.
pub(crate) fn encode_php_length(length: i64, out: &mut Vec<u8>) {
    if length >= 0 {
        encode_length(length as u64, out);
    } else {
        out.push((length % 128).rem_euclid(256) as u8);
    }
}

/// Reads the length-prefixed string at `offset`: its bytes and the offset
/// past it.
///
/// PHP: `Packet::readString(string $data, int $offset)`.
pub fn read_string(data: &[u8], offset: usize) -> (&[u8], usize) {
    let length = (usize::from(byte(data, offset)) << 8) + usize::from(byte(data, offset.saturating_add(1)));
    let start = offset.saturating_add(2);
    let next = start.saturating_add(length);
    (&data[start.min(data.len())..next.min(data.len())], next)
}

/// Appends `value` with its two-byte length prefix. A value longer than
/// 65,535 bytes keeps the low 16 bits of its length in the prefix.
///
/// PHP: `Packet::encodeString(string $value)`.
pub fn encode_string(value: &[u8], out: &mut Vec<u8>) {
    out.push((value.len() >> 8) as u8);
    out.push(value.len() as u8);
    out.extend_from_slice(value);
}

/// Reads the two-byte big-endian integer at `offset` (a packet id, a
/// keep-alive): its value and the offset past it.
///
/// PHP: `Packet::readInt16(string $data, int $offset)`.
pub fn read_int16(data: &[u8], offset: usize) -> (u16, usize) {
    ((u16::from(byte(data, offset)) << 8) + u16::from(byte(data, offset.saturating_add(1))), offset.saturating_add(2))
}

/// Reads the byte at `offset` (a reason code, a protocol level, a flags
/// byte): its value and the offset past it.
///
/// PHP: `Packet::readByte(string $data, int $offset)`.
pub fn read_byte(data: &[u8], offset: usize) -> (u8, usize) {
    (byte(data, offset), offset.saturating_add(1))
}

/// Reading at offsets PHP holds as `int|float`: the decoders' cursor once a
/// malformed variable byte integer has moved it past the 64-bit range. At an
/// ordinary offset these read exactly as the public primitives do.
pub(crate) mod at {
    use super::Length;
    use crate::Error;

    fn int(offset: Length, function: &str) -> Result<i64, Error> {
        offset.as_int().ok_or_else(|| {
            Error::Type(format!(
                "Utopia\\Mqtt\\Packet::{function}(): Argument #2 ($offset) must be of type int, float given"
            ))
        })
    }

    /// `ord($data[$i])`: negative offsets count from the end, anything out of
    /// range reads `0`.
    fn index(data: &[u8], i: Length) -> u8 {
        let i = match i {
            Length::Int(i) => i,
            Length::Float(f) => php_std::format::dval_to_lval(f),
        };
        let i = if i < 0 { i + data.len() as i64 } else { i };
        usize::try_from(i).ok().and_then(|i| data.get(i)).copied().unwrap_or(0)
    }

    /// `substr($data, $start, $length)` for a non-negative `$length`.
    pub(crate) fn substr(data: &[u8], start: Length, length: Option<i64>) -> Result<&[u8], Error> {
        let start = start
            .as_int()
            .ok_or_else(|| Error::Type("substr(): Argument #2 ($offset) must be of type int, float given".into()))?;
        let len = data.len() as i64;
        let start = if start < 0 { (len + start).max(0) } else { start };
        if start > len {
            return Ok(&[]);
        }
        let end = match length {
            Some(n) => start.saturating_add(n).min(len),
            None => len,
        };
        Ok(&data[start as usize..end as usize])
    }

    /// `Packet::readString()`.
    pub(crate) fn string(data: &[u8], offset: Length) -> Result<(&[u8], Length), Error> {
        let o = int(offset, "readString")?;
        let at = Length::Int(o);
        let length = (i64::from(index(data, at)) << 8) + i64::from(index(data, at.plus(Length::Int(1))));
        let start = at.plus(Length::Int(2));
        let value = substr(data, start, Some(length))?;
        Ok((value, start.plus(Length::Int(length))))
    }

    /// `Packet::readByte()`.
    pub(crate) fn byte(data: &[u8], offset: Length) -> Result<(u8, Length), Error> {
        let o = int(offset, "readByte")?;
        let at = Length::Int(o);
        Ok((index(data, at), at.plus(Length::Int(1))))
    }

    /// `isset($data[$offset])`.
    pub(crate) fn isset(data: &[u8], offset: Length) -> bool {
        match offset {
            Length::Int(i) => {
                let i = if i < 0 { i + data.len() as i64 } else { i };
                (0..data.len() as i64).contains(&i)
            }
            Length::Float(f) => {
                let i = php_std::format::dval_to_lval(f);
                let i = if i < 0 { i + data.len() as i64 } else { i };
                (0..data.len() as i64).contains(&i)
            }
        }
    }

    /// `$offset < strlen($data)`.
    pub(crate) fn before(offset: Length, end: usize) -> bool {
        match offset {
            Length::Int(i) => i < end as i64,
            Length::Float(f) => f < end as f64,
        }
    }
}
