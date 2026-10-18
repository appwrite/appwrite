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

mod encoder;

use serde_json::Value;

use encoder::Encoder;

/// `json_encode()`'s default depth.
const MAX_DEPTH: usize = 512;

/// `json_encode($data, $unicode ? JSON_UNESCAPED_UNICODE : 0)`, or `None`
/// where PHP returns `false` (nesting deeper than 512 levels).
pub(crate) fn json(data: &Value, unescaped_unicode: bool) -> Option<String> {
    let mut encoder = Encoder::new(unescaped_unicode);
    encoder.value(data).then_some(encoder.into_output())
}

#[cfg(test)]
mod tests {
    use php_std::json::{DEFAULT_DEPTH, Flags, encode};
    use serde_json::{Map, json};

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
