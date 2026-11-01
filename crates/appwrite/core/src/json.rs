//! JSON encoding with PHP `json_encode` semantics.
//!
//! PHP escapes forward slashes (`\/`) and prints floats with a zero
//! fraction as integers. Responses are encoded through [`PhpFormatter`] so
//! bodies are byte-compatible with the PHP API.

use std::io;

use serde::Serialize;
use serde_json::ser::{Formatter, Serializer};

/// `serde_json` formatter reproducing `json_encode(JSON_UNESCAPED_UNICODE)`.
#[derive(Debug, Default, Clone, Copy)]
pub struct PhpFormatter;

impl Formatter for PhpFormatter {
    fn write_string_fragment<W: ?Sized + io::Write>(&mut self, writer: &mut W, fragment: &str) -> io::Result<()> {
        let bytes = fragment.as_bytes();
        let mut start = 0;
        for (i, b) in bytes.iter().enumerate() {
            if *b == b'/' {
                writer.write_all(&bytes[start..i])?;
                writer.write_all(b"\\/")?;
                start = i + 1;
            }
        }
        writer.write_all(&bytes[start..])
    }

    fn write_f64<W: ?Sized + io::Write>(&mut self, writer: &mut W, value: f64) -> io::Result<()> {
        if value.is_finite() && value.fract() == 0.0 && value.abs() < 1e15 {
            write!(writer, "{}", value as i64)
        } else {
            let mut buffer = ryu_like(value);
            if buffer.ends_with(".0") {
                buffer.truncate(buffer.len() - 2);
            }
            writer.write_all(buffer.as_bytes())
        }
    }
}

fn ryu_like(value: f64) -> String {
    serde_json::Number::from_f64(value).map(|n| n.to_string()).unwrap_or_else(|| "0".to_owned())
}

/// Encodes a value with PHP semantics.
pub fn to_vec<T: Serialize + ?Sized>(value: &T) -> Vec<u8> {
    let mut out = Vec::with_capacity(512);
    let mut ser = Serializer::with_formatter(&mut out, PhpFormatter);
    if value.serialize(&mut ser).is_err() {
        out.clear();
        out.extend_from_slice(b"{}");
    }
    out
}

/// Encodes a value with PHP semantics into a `String`.
pub fn to_string<T: Serialize + ?Sized>(value: &T) -> String {
    String::from_utf8(to_vec(value)).unwrap_or_default()
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    #[test]
    fn escapes_like_php() {
        assert_eq!(
            to_string(&json!({"a": "x/y", "b": 1.0, "c": 1.5, "d": "é"})),
            r#"{"a":"x\/y","b":1,"c":1.5,"d":"é"}"#
        );
    }
}
