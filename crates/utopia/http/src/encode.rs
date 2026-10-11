//! `json_encode()` of response data, written straight from JSON values.
//!
//! PHP: the response's data is a PHP array; here it is the request model
//! ([`serde_json::Value`]), which [`php_std::Value::from_json`] maps onto
//! PHP values: an object whose keys are `"0"`, `"1"`, ... in order is a
//! list (and encodes as a JSON array), an empty object stays an object.
//! The output is `json_encode($data, $flags)` byte for byte: escaped
//! slashes, `\u` escapes in lowercase hex, U+2028 and U+2029 escaped even
//! with `JSON_UNESCAPED_UNICODE`, floats as `serialize_precision = -1`
//! prints them, and failure past 512 levels of nesting.

use std::fmt::Write;

use serde_json::{Map, Value};

/// `json_encode()`'s default depth.
const MAX_DEPTH: usize = 512;

/// `json_encode($data, $unicode ? JSON_UNESCAPED_UNICODE : 0)`, or `None`
/// where PHP returns `false` (nesting deeper than 512 levels).
pub(crate) fn json(data: &Value, unescaped_unicode: bool) -> Option<String> {
    let mut encoder = Encoder { out: String::with_capacity(256), depth: 0, unescaped_unicode };
    encoder.value(data).then_some(encoder.out)
}

struct Encoder {
    out: String,
    depth: usize,
    unescaped_unicode: bool,
}

impl Encoder {
    /// `php_json_encode_zval`; `false` on failure.
    fn value(&mut self, value: &Value) -> bool {
        match value {
            Value::Null => self.out.push_str("null"),
            Value::Bool(true) => self.out.push_str("true"),
            Value::Bool(false) => self.out.push_str("false"),
            Value::Number(n) => match n.as_i64() {
                Some(i) => {
                    let _ = write!(self.out, "{i}");
                }
                // Floats, and integers past `PHP_INT_MAX`, which PHP holds as floats.
                None => self.out.push_str(&php_std::number::serialize(n.as_f64().unwrap_or(0.0))),
            },
            Value::String(s) => self.string(s),
            Value::Array(items) => return self.list(items.iter()),
            Value::Object(members) if is_list(members) => return self.list(members.values()),
            Value::Object(members) => return self.object(members),
        }
        true
    }

    /// A PHP list: `[...]`.
    fn list<'v>(&mut self, items: impl Iterator<Item = &'v Value>) -> bool {
        self.out.push('[');
        self.depth += 1;
        for (i, item) in items.enumerate() {
            if i > 0 {
                self.out.push(',');
            }
            if !self.value(item) {
                return false;
            }
        }
        self.close(']')
    }

    /// Any other PHP array, or an empty `stdClass`: `{...}`.
    fn object(&mut self, members: &Map<String, Value>) -> bool {
        self.out.push('{');
        self.depth += 1;
        for (i, (key, member)) in members.iter().enumerate() {
            if i > 0 {
                self.out.push(',');
            }
            self.string(key);
            self.out.push(':');
            if !self.value(member) {
                return false;
            }
        }
        self.close('}')
    }

    /// Closes a container: PHP checks the depth once its members are written.
    fn close(&mut self, bracket: char) -> bool {
        if self.depth > MAX_DEPTH {
            return false;
        }
        self.depth -= 1;
        self.out.push(bracket);
        true
    }

    /// `php_json_escape_string` for UTF-8 text: runs of bytes that need no
    /// escape are copied whole.
    fn string(&mut self, s: &str) {
        const HEX: &[u8; 16] = b"0123456789abcdef";
        let bytes = s.as_bytes();
        self.out.reserve(s.len() + 2);
        self.out.push('"');
        let (mut start, mut i) = (0, 0);
        while i < bytes.len() {
            let b = bytes[i];
            if PLAIN[usize::from(b)] {
                i += 1;
                continue;
            }
            let escape: &str = match b {
                b'"' => "\\\"",
                b'\\' => "\\\\",
                b'/' => "\\/",
                0x08 => "\\b",
                0x0c => "\\f",
                b'\n' => "\\n",
                b'\r' => "\\r",
                b'\t' => "\\t",
                0x00..=0x1f => "",
                0x20..=0x7f => {
                    i += 1;
                    continue;
                }
                // U+2028 and U+2029 (E2 80 A8, E2 80 A9) are always escaped.
                0xe2 if bytes[i + 1] == 0x80 && matches!(bytes[i + 2], 0xa8 | 0xa9) => "",
                _ if self.unescaped_unicode => {
                    i += 1;
                    continue;
                }
                _ => "",
            };
            self.out.push_str(&s[start..i]);
            if !escape.is_empty() {
                self.out.push_str(escape);
                i += 1;
                start = i;
                continue;
            }
            // `\uXXXX`, as a surrogate pair past the BMP. `i` is at the
            // start of a character: every byte before it was ASCII or
            // part of a whole character.
            let c = s[i..].chars().next().unwrap_or_default();
            let mut units = [0u16; 2];
            for &unit in c.encode_utf16(&mut units).iter() {
                let unit = usize::from(unit);
                for b in [b'\\', b'u', HEX[unit >> 12], HEX[(unit >> 8) & 15], HEX[(unit >> 4) & 15], HEX[unit & 15]] {
                    self.out.push(char::from(b));
                }
            }
            i += c.len_utf8();
            start = i;
        }
        self.out.push_str(&s[start..]);
        self.out.push('"');
    }
}

/// ASCII bytes copied as they are: everything from a space on except `"`,
/// `/` and `\`.
const PLAIN: [bool; 256] = {
    let mut plain = [false; 256];
    let mut b = 0x20;
    while b < 0x80 {
        plain[b] = !matches!(b as u8, b'"' | b'/' | b'\\');
        b += 1;
    }
    plain
};

/// Whether an object's keys are `0`, `1`, ... in order: the PHP array it
/// maps to is a list. Empty objects stay objects.
fn is_list(members: &Map<String, Value>) -> bool {
    !members.is_empty()
        && members.keys().enumerate().all(|(i, k)| php_std::types::numeric_key(k.as_bytes()) == Some(i as i64))
}

#[cfg(test)]
mod tests {
    use php_std::json::{DEFAULT_DEPTH, Flags, encode};
    use serde_json::json;

    use super::*;

    /// PHP's output, through the PHP value model.
    fn php(value: &Value, unicode: bool) -> Option<String> {
        let flags = if unicode { Flags::UNESCAPED_UNICODE } else { Flags::NONE };
        encode(&php_std::Value::<php_std::Never>::from_json(value), flags, DEFAULT_DEPTH).ok()
    }

    struct Rng(u64);

    impl Rng {
        fn next(&mut self) -> u64 {
            self.0 ^= self.0 << 13;
            self.0 ^= self.0 >> 7;
            self.0 ^= self.0 << 17;
            self.0
        }

        fn below(&mut self, n: usize) -> usize {
            (self.next() % n as u64) as usize
        }
    }

    const PIECES: &[&str] = &[
        "",
        "a",
        "/",
        "\\",
        "\"",
        "\n",
        "\r\t",
        "\u{0}",
        "\u{1f}",
        "\u{7f}",
        "\u{80}",
        "é",
        "\u{2028}",
        "\u{2029}",
        "\u{ffff}",
        "😀",
        "<&'>",
        "0",
        "1",
        "01",
        "-1",
        "9223372036854775808",
        " ",
        "\u{0}k",
    ];

    fn string(rng: &mut Rng) -> String {
        (0..rng.below(4)).map(|_| PIECES[rng.below(PIECES.len())]).collect()
    }

    fn value(rng: &mut Rng, depth: usize) -> Value {
        match rng.below(if depth > 4 { 5 } else { 8 }) {
            0 => Value::Null,
            1 => Value::Bool(rng.below(2) == 1),
            2 => json!(rng.next() as i64 >> rng.below(64)),
            3 => {
                let f = f64::from_bits(rng.next());
                let f = if f.is_finite() { f } else { [0.1, -0.0, 1e25, 1.5e-7, 100.0][rng.below(5)] };
                json!(f)
            }
            4 => Value::String(string(rng)),
            5 => Value::Array((0..rng.below(4)).map(|_| value(rng, depth + 1)).collect()),
            _ => {
                let mut map = Map::new();
                let numeric = rng.below(3) == 0;
                for i in 0..rng.below(4) {
                    let key = if numeric { i.to_string() } else { string(rng) };
                    map.insert(key, value(rng, depth + 1));
                }
                Value::Object(map)
            }
        }
    }

    #[test]
    fn matches_php_json_encode() {
        let mut rng = Rng(0x2545_f491_4f6c_dd1d);
        for _ in 0..20_000 {
            let v = value(&mut rng, 0);
            for unicode in [true, false] {
                assert_eq!(json(&v, unicode), php(&v, unicode), "{v}");
            }
        }
        for v in [json!(u64::MAX), json!({"0": 1, "1": 2}), json!({"1": 1}), json!({}), json!([]), json!({"": {}})] {
            assert_eq!(json(&v, true), php(&v, true), "{v}");
        }
    }

    #[test]
    fn fails_past_512_levels_like_php() {
        for (depth, object) in [(511, false), (512, false), (513, false), (512, true), (513, true)] {
            let mut v = if object { json!({}) } else { json!([]) };
            for _ in 1..depth {
                v = if object { json!({ "k": v }) } else { json!([v]) };
            }
            assert_eq!(json(&v, true), php(&v, true), "depth {depth}");
            assert_eq!(json(&v, true).is_some(), depth <= 512);
        }
    }
}
