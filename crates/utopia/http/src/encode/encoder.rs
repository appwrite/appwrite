use std::fmt::Write;

use serde_json::{Map, Value};

use crate::encode::MAX_DEPTH;

/// A `json_encode()` in progress: the output so far and the nesting depth.
pub(super) struct Encoder {
    out: String,
    depth: usize,
    unescaped_unicode: bool,
}

impl Encoder {
    pub(super) fn new(unescaped_unicode: bool) -> Self {
        Self { out: String::with_capacity(256), depth: 0, unescaped_unicode }
    }

    pub(super) fn into_output(self) -> String {
        self.out
    }

    /// `php_json_encode_zval`; `false` on failure.
    pub(super) fn value(&mut self, value: &Value) -> bool {
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
