//! Declarative input generators for `compat fuzz`, and a shrinker.
//!
//! A generator is JSON with a `gen` key:
//!
//! | `gen` | Fields | Produces |
//! |---|---|---|
//! | `const` | `value` | that value |
//! | `pick` | `from: [...]` | one of the listed values |
//! | `int` | `min`, `max` (i64) | an integer, edges (min, max, 0, ±1) favoured |
//! | `float` | `min`, `max`, `integral` (bool) | a float |
//! | `bool` / `null` | | |
//! | `string` | `min`, `max`, `alphabet`, `tricky` (0..1) | a string; `tricky` is the chance of a known-awkward string |
//! | `concat` | `parts: [gen...]` | the parts' string forms joined |
//! | `repeat` | `part`, `sep`, `min`, `max` | `part` repeated, joined by `sep` |
//! | `array` | `items`, `min`, `max` | a list |
//! | `object` | `fields: {k: gen}`, `optional: [k...]` | an object (field order kept) |
//! | `one_of` | `of: [gen...]` | one of the generators |
//! | `any` | `depth` | any JSON value |
//! | `ref` | `name` | a named generator from the spec's `generators` |
//!
//! Alphabets: `ascii` (printable), `alnum`, `lower`, `upper`, `digits`, `hex`,
//! `hostname`, `url`, `email`, `space`, `unicode` (multi-byte, combining,
//! RTL, emoji, control characters), or any literal set of characters.

use std::collections::BTreeMap;

use serde_json::{Map, Number, Value};

/// SplitMix64: small, fast, reproducible from a seed.
pub struct Rng(u64);

impl Rng {
    pub fn new(seed: u64) -> Self {
        Rng(seed)
    }

    pub fn next_u64(&mut self) -> u64 {
        self.0 = self.0.wrapping_add(0x9E37_79B9_7F4A_7C15);
        let mut z = self.0;
        z = (z ^ (z >> 30)).wrapping_mul(0xBF58_476D_1CE4_E5B9);
        z = (z ^ (z >> 27)).wrapping_mul(0x94D0_49BB_1331_11EB);
        z ^ (z >> 31)
    }

    /// Uniform in `0..n` (n > 0).
    pub fn below(&mut self, n: u64) -> u64 {
        if n == 0 { 0 } else { self.next_u64() % n }
    }

    pub fn chance(&mut self, p: f64) -> bool {
        (self.next_u64() >> 11) as f64 / (1u64 << 53) as f64 * 1.0 < p
    }

    pub fn range_i64(&mut self, min: i64, max: i64) -> i64 {
        if max <= min {
            return min;
        }
        let span = (max as i128 - min as i128 + 1) as u128;
        (min as i128 + (self.next_u64() as u128 % span) as i128) as i64
    }

    pub fn pick<'a, T>(&mut self, items: &'a [T]) -> &'a T {
        &items[self.below(items.len() as u64) as usize]
    }
}

/// Strings that commonly expose differences between PHP and Rust.
pub const TRICKY: &[&str] = &[
    "",
    " ",
    "  ",
    "\t",
    "\n",
    "\r\n",
    "0",
    "-0",
    "00",
    "01",
    "+1",
    "-1",
    "1.",
    ".5",
    "1.0",
    "1e3",
    "1E+3",
    "-1e-3",
    "0x1A",
    "0b11",
    "0o7",
    "1_000",
    " 1",
    "1 ",
    "١٢٣",
    "１２３",
    "9223372036854775807",
    "9223372036854775808",
    "-9223372036854775808",
    "-9223372036854775809",
    "1.7976931348623157e308",
    "1e309",
    "NaN",
    "INF",
    "-INF",
    "true",
    "false",
    "null",
    "[]",
    "{}",
    "\u{0}",
    "a\u{0}b",
    "é",
    "e\u{301}",
    "İ",
    "ß",
    "Σ",
    "ǅ",
    "😀",
    "👩‍👩‍👧",
    "\u{202e}abc",
    "\u{feff}x",
    "%00",
    "%",
    "%2F",
    "../",
    "..\\",
    "<script>",
    "'\"",
    "\\",
    "a@b",
    "a@b.c",
    "@",
    "localhost",
    "127.0.0.1",
    "::1",
    "[::1]",
    "http://",
    "https://a.b/c?d=e#f",
    "mailto:a@b.c",
    "xn--bcher-kva.com",
    "bücher.com",
    "*",
    "**",
    "a*b",
    "*.example.com",
];

/// Characters for the `unicode` alphabet: ASCII plus awkward code points.
const UNICODE_EXTRA: &[char] = &[
    'é',
    'ß',
    'İ',
    'ı',
    'Σ',
    'σ',
    'ς',
    'ǅ',
    'ﬀ',
    'Å',
    '\u{301}',
    '\u{308}',
    '\u{200b}',
    '\u{200d}',
    '\u{202e}',
    '\u{feff}',
    '\u{0}',
    '\u{7}',
    '\u{1b}',
    '\u{7f}',
    '\u{85}',
    '\u{a0}',
    '\u{2028}',
    '日',
    '本',
    '語',
    'ア',
    '한',
    'ض',
    'ש',
    '١',
    '５',
    '😀',
    '🇺',
    '🇸',
    '𝔘',
    '\u{10ffff}',
];

fn alphabet(name: &str) -> Vec<char> {
    let ascii: Vec<char> = (0x20u8..0x7f).map(char::from).collect();
    match name {
        "" | "ascii" => ascii,
        "alnum" => ('a'..='z').chain('A'..='Z').chain('0'..='9').collect(),
        "lower" => ('a'..='z').collect(),
        "upper" => ('A'..='Z').collect(),
        "digits" => ('0'..='9').collect(),
        "hex" => ('0'..='9').chain('a'..='f').chain('A'..='F').collect(),
        "hostname" => ('a'..='z').chain('0'..='9').chain("-.".chars()).collect(),
        "url" => ('a'..='z').chain('A'..='Z').chain('0'..='9').chain("-._~:/?#[]@!$&'()*+,;=%".chars()).collect(),
        "email" => ('a'..='z').chain('0'..='9').chain(".+-_@".chars()).collect(),
        "space" => " \t\n\r\u{b}\u{c}".chars().collect(),
        "unicode" => ascii.into_iter().chain(UNICODE_EXTRA.iter().copied()).collect(),
        literal => literal.chars().collect(),
    }
}

/// Generates one value. `named` holds the spec's named generators.
pub fn generate(spec: &Value, named: &BTreeMap<String, Value>, rng: &mut Rng) -> Result<Value, String> {
    gen_depth(spec, named, rng, 0)
}

fn num(spec: &Value, key: &str) -> Option<i64> {
    spec.get(key).and_then(Value::as_i64)
}

fn gen_depth(spec: &Value, named: &BTreeMap<String, Value>, rng: &mut Rng, depth: u32) -> Result<Value, String> {
    if depth > 32 {
        return Err("generator nesting is too deep (recursive `ref`?)".into());
    }
    let kind = spec.get("gen").and_then(Value::as_str).ok_or_else(|| format!("generator without `gen`: {spec}"))?;
    let sub = |s: &Value, rng: &mut Rng| gen_depth(s, named, rng, depth + 1);
    Ok(match kind {
        "const" => spec.get("value").cloned().unwrap_or(Value::Null),
        "pick" => {
            let from =
                spec.get("from").and_then(Value::as_array).filter(|a| !a.is_empty()).ok_or("`pick` needs `from`")?;
            rng.pick(from).clone()
        }
        "int" => {
            let min = num(spec, "min").unwrap_or(i64::MIN);
            let max = num(spec, "max").unwrap_or(i64::MAX);
            let edges = [min, max, 0, 1, -1, min.saturating_add(1), max.saturating_sub(1)];
            let v = if rng.chance(0.3) {
                *rng.pick(&edges)
            } else if rng.chance(0.5) {
                rng.range_i64(min.max(-1000), max.min(1000))
            } else {
                rng.range_i64(min, max)
            };
            Value::Number(v.clamp(min, max).into())
        }
        "float" => {
            let min = spec.get("min").and_then(Value::as_f64).unwrap_or(-1e6);
            let max = spec.get("max").and_then(Value::as_f64).unwrap_or(1e6);
            let integral = spec.get("integral").and_then(Value::as_bool).unwrap_or(false);
            let edges = [min, max, 0.0, -0.0, 0.5, -0.5, 1.0, 0.1, 1e-9, 123456.789];
            let mut f = if rng.chance(0.3) {
                *rng.pick(&edges)
            } else {
                min + (rng.next_u64() >> 11) as f64 / (1u64 << 53) as f64 * (max - min)
            };
            if integral {
                f = f.trunc();
            }
            Value::Number(Number::from_f64(f.clamp(min, max)).unwrap_or_else(|| 0.into()))
        }
        "bool" => Value::Bool(rng.chance(0.5)),
        "null" => Value::Null,
        "string" => {
            let tricky = spec.get("tricky").and_then(Value::as_f64).unwrap_or(0.0);
            if rng.chance(tricky) {
                return Ok(Value::String((*rng.pick(TRICKY)).to_owned()));
            }
            let min = num(spec, "min").unwrap_or(0).max(0) as u64;
            let max = num(spec, "max").unwrap_or(32).max(min as i64) as u64;
            let chars = alphabet(spec.get("alphabet").and_then(Value::as_str).unwrap_or(""));
            if chars.is_empty() {
                return Err("`string` alphabet is empty".into());
            }
            let len = min + rng.below(max - min + 1);
            Value::String((0..len).map(|_| *rng.pick(&chars)).collect())
        }
        "concat" => {
            let parts = spec.get("parts").and_then(Value::as_array).ok_or("`concat` needs `parts`")?;
            let mut s = String::new();
            for p in parts {
                s.push_str(&as_text(&sub(p, rng)?));
            }
            Value::String(s)
        }
        "repeat" => {
            let part = spec.get("part").ok_or("`repeat` needs `part`")?;
            let sep = spec.get("sep").and_then(Value::as_str).unwrap_or("");
            let min = num(spec, "min").unwrap_or(1).max(0) as u64;
            let max = num(spec, "max").unwrap_or(4).max(min as i64) as u64;
            let n = min + rng.below(max - min + 1);
            let mut items = Vec::new();
            for _ in 0..n {
                items.push(as_text(&sub(part, rng)?));
            }
            Value::String(items.join(sep))
        }
        "array" => {
            let items = spec.get("items").ok_or("`array` needs `items`")?;
            let min = num(spec, "min").unwrap_or(0).max(0) as u64;
            let max = num(spec, "max").unwrap_or(4).max(min as i64) as u64;
            let n = min + rng.below(max - min + 1);
            Value::Array((0..n).map(|_| sub(items, rng)).collect::<Result<_, _>>()?)
        }
        "object" => {
            let fields = spec.get("fields").and_then(Value::as_object).ok_or("`object` needs `fields`")?;
            let optional: Vec<&str> = spec
                .get("optional")
                .and_then(Value::as_array)
                .map(|a| a.iter().filter_map(Value::as_str).collect())
                .unwrap_or_default();
            let mut out = Map::new();
            for (k, g) in fields {
                if optional.contains(&k.as_str()) && rng.chance(0.4) {
                    continue;
                }
                out.insert(k.clone(), sub(g, rng)?);
            }
            Value::Object(out)
        }
        "one_of" => {
            let of = spec.get("of").and_then(Value::as_array).filter(|a| !a.is_empty()).ok_or("`one_of` needs `of`")?;
            sub(rng.pick(of), rng)?
        }
        "any" => any(rng, num(spec, "depth").unwrap_or(2) as u32),
        "ref" => {
            let name = spec.get("name").and_then(Value::as_str).ok_or("`ref` needs `name`")?;
            let target = named.get(name).ok_or_else(|| format!("unknown generator `{name}`"))?;
            sub(target, rng)?
        }
        other => return Err(format!("unknown generator `{other}`")),
    })
}

fn as_text(v: &Value) -> String {
    match v {
        Value::String(s) => s.clone(),
        other => other.to_string(),
    }
}

fn any(rng: &mut Rng, depth: u32) -> Value {
    let leaf = |rng: &mut Rng| -> Value {
        match rng.below(6) {
            0 => Value::Null,
            1 => Value::Bool(rng.chance(0.5)),
            2 => Value::Number(rng.range_i64(-1000, 1000).into()),
            3 => {
                Value::Number(Number::from_f64((rng.range_i64(-10000, 10000) as f64) / 7.0).unwrap_or_else(|| 0.into()))
            }
            _ => Value::String((*rng.pick(TRICKY)).to_owned()),
        }
    };
    if depth == 0 || rng.chance(0.6) {
        return leaf(rng);
    }
    if rng.chance(0.5) {
        Value::Array((0..rng.below(4)).map(|_| any(rng, depth - 1)).collect())
    } else {
        Value::Object((0..rng.below(4)).map(|i| (format!("k{i}"), any(rng, depth - 1))).collect())
    }
}

/// Smaller variants of a value, most aggressive first.
pub fn shrink(value: &Value) -> Vec<Value> {
    let mut out = Vec::new();
    match value {
        Value::String(s) => {
            let chars: Vec<char> = s.chars().collect();
            if !chars.is_empty() {
                out.push(Value::String(String::new()));
                let half = chars.len() / 2;
                if half > 0 {
                    out.push(Value::String(chars[..half].iter().collect()));
                    out.push(Value::String(chars[half..].iter().collect()));
                }
                for i in 0..chars.len().min(64) {
                    let mut c = chars.clone();
                    c.remove(i);
                    out.push(Value::String(c.into_iter().collect()));
                }
            }
        }
        Value::Number(n) => {
            if let Some(i) = n.as_i64() {
                if i != 0 {
                    out.push(Value::Number(0.into()));
                    out.push(Value::Number((i / 2).into()));
                }
            } else if let Some(f) = n.as_f64()
                && f != 0.0
            {
                out.push(Value::Number(Number::from_f64(f.trunc()).unwrap_or_else(|| 0.into())));
            }
        }
        Value::Array(a) => {
            for i in 0..a.len() {
                let mut c = a.clone();
                c.remove(i);
                out.push(Value::Array(c));
            }
            for (i, item) in a.iter().enumerate() {
                for smaller in shrink(item) {
                    let mut c = a.clone();
                    c[i] = smaller;
                    out.push(Value::Array(c));
                }
            }
        }
        Value::Object(o) => {
            for (k, v) in o {
                for smaller in shrink(v) {
                    let mut c = o.clone();
                    c.insert(k.clone(), smaller);
                    out.push(Value::Object(c));
                }
            }
        }
        _ => {}
    }
    out
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    #[test]
    fn same_seed_same_values() {
        let spec = json!({"gen": "object", "fields": {
            "s": {"gen": "string", "alphabet": "unicode", "tricky": 0.2},
            "n": {"gen": "int", "min": -5, "max": 5},
            "l": {"gen": "array", "items": {"gen": "any"}}
        }});
        let named = BTreeMap::new();
        let a: Vec<Value> = {
            let mut r = Rng::new(7);
            (0..50).map(|_| generate(&spec, &named, &mut r).unwrap()).collect()
        };
        let b: Vec<Value> = {
            let mut r = Rng::new(7);
            (0..50).map(|_| generate(&spec, &named, &mut r).unwrap()).collect()
        };
        assert_eq!(a, b);
        assert!(a.iter().all(|v| (-5..=5).contains(&v["n"].as_i64().unwrap())));
    }

    #[test]
    fn refs_resolve() {
        let named = BTreeMap::from([("host".to_owned(), json!({"gen": "pick", "from": ["a.com"]}))]);
        let mut r = Rng::new(1);
        let v = generate(
            &json!({"gen": "concat", "parts": [{"gen": "const", "value": "x@"}, {"gen": "ref", "name": "host"}]}),
            &named,
            &mut r,
        )
        .unwrap();
        assert_eq!(v, json!("x@a.com"));
    }

    #[test]
    fn shrinks_strings_and_lists() {
        assert!(shrink(&json!("abc")).contains(&json!("")));
        assert!(shrink(&json!([1, 2])).contains(&json!([2])));
    }
}
