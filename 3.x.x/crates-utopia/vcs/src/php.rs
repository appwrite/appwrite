//! PHP helper ports (`empty`, `urlencode`, `escapeshellarg`, `fnmatch`, JSON).

use hmac::{Hmac, Mac};
use serde_json::{Map, Value};
use sha2::Sha256;
use subtle::ConstantTimeEq;

type HmacSha256 = Hmac<Sha256>;

/// PHP `empty($s)` for strings (`""` and `"0"`).
#[must_use]
pub fn php_empty_str(value: &str) -> bool {
    value.is_empty() || value == "0"
}

/// PHP `empty($value)` for JSON (used by `array_filter` / check-run bodies).
#[must_use]
pub fn php_empty_value(value: &Value) -> bool {
    match value {
        Value::Null | Value::Bool(false) => true,
        Value::Number(n) => n.as_i64() == Some(0) || n.as_f64() == Some(0.0),
        Value::String(s) => php_empty_str(s),
        Value::Array(a) => a.is_empty(),
        Value::Object(o) => o.is_empty(),
        Value::Bool(true) => false,
    }
}

/// PHP `strval`.
#[must_use]
pub fn strval(value: &Value) -> String {
    match value {
        Value::Null | Value::Bool(false) => String::new(),
        Value::Bool(true) => "1".into(),
        Value::Number(n) => n.to_string(),
        Value::String(s) => s.clone(),
        Value::Array(_) | Value::Object(_) => "Array".into(),
    }
}

/// PHP `$arr[$key] ?? []` when the fallback is used as an associative array.
#[must_use]
pub fn obj_field<'a>(value: &'a Value, key: &str) -> &'a Value {
    match value.get(key) {
        Some(v) if v.is_object() || v.is_array() => v,
        _ => empty_object(),
    }
}

fn empty_object() -> &'static Value {
    static EMPTY: std::sync::OnceLock<Value> = std::sync::OnceLock::new();
    EMPTY.get_or_init(|| Value::Object(Map::new()))
}

/// PHP `$arr[$key] ?? ''` then `strval` when the caller uses `strval(...)`.
#[must_use]
pub fn str_field(value: &Value, key: &str) -> String {
    match value.get(key) {
        None | Some(Value::Null) => String::new(),
        Some(v) => strval(v),
    }
}

/// Clone a field or JSON null.
#[must_use]
pub fn field_or_null<'a>(value: &'a Value, key: &str) -> &'a Value {
    value.get(key).unwrap_or(&Value::Null)
}

fn push_percent_hex(out: &mut String, b: u8) {
    const HEX: &[u8; 16] = b"0123456789ABCDEF";
    out.push('%');
    out.push(HEX[(b >> 4) as usize] as char);
    out.push(HEX[(b & 0x0f) as usize] as char);
}

/// PHP `urlencode` (RFC 1738: space as `+`, `~` encoded).
#[must_use]
pub fn php_urlencode(input: &str) -> String {
    let mut out = String::new();
    for &b in input.as_bytes() {
        match b {
            b'A'..=b'Z' | b'a'..=b'z' | b'0'..=b'9' | b'-' | b'_' | b'.' => {
                out.push(b as char);
            }
            b' ' => out.push('+'),
            _ => push_percent_hex(&mut out, b),
        }
    }
    out
}

/// PHP `rawurlencode` (RFC 3986).
#[must_use]
pub fn php_rawurlencode(input: &str) -> String {
    let mut out = String::new();
    for &b in input.as_bytes() {
        match b {
            b'A'..=b'Z' | b'a'..=b'z' | b'0'..=b'9' | b'-' | b'_' | b'.' | b'~' => {
                out.push(b as char);
            }
            _ => push_percent_hex(&mut out, b),
        }
    }
    out
}

/// PHP `http_build_query`.
#[must_use]
pub fn http_build_query(params: &Value) -> String {
    let mut parts = Vec::new();
    append_query(&mut parts, "", params);
    parts.join("&")
}

fn append_query(parts: &mut Vec<String>, prefix: &str, value: &Value) {
    match value {
        Value::Object(map) => {
            for (key, nested) in map {
                let next = if prefix.is_empty() {
                    key.clone()
                } else {
                    format!("{prefix}[{key}]")
                };
                append_query(parts, &next, nested);
            }
        }
        Value::Array(items) => {
            for (index, nested) in items.iter().enumerate() {
                let next = if prefix.is_empty() {
                    index.to_string()
                } else {
                    format!("{prefix}[{index}]")
                };
                append_query(parts, &next, nested);
            }
        }
        Value::Null => {}
        Value::Bool(flag) => {
            let encoded_key = php_urlencode(prefix);
            parts.push(format!("{encoded_key}={}", if *flag { "1" } else { "0" }));
        }
        Value::Number(number) => {
            parts.push(format!(
                "{}={}",
                php_urlencode(prefix),
                php_urlencode(&number.to_string())
            ));
        }
        Value::String(text) => {
            parts.push(format!("{}={}", php_urlencode(prefix), php_urlencode(text)));
        }
    }
}

/// PHP `escapeshellarg`.
#[must_use]
pub fn escape_shell_arg(value: &str) -> String {
    format!("'{}'", value.replace('\'', "'\\''"))
}

/// PHP `hash_hmac('sha256', $payload, $key)` hex digest.
#[must_use]
pub fn hmac_sha256_hex(payload: &[u8], key: &[u8]) -> String {
    let mut mac = HmacSha256::new_from_slice(key).unwrap_or_else(|_| {
        HmacSha256::new_from_slice(b"").expect("HMAC-SHA256 accepts empty key")
    });
    mac.update(payload);
    hex::encode(mac.finalize().into_bytes())
}

/// PHP `hash_equals`.
#[must_use]
pub fn hash_equals(left: &str, right: &str) -> bool {
    if left.len() != right.len() {
        return false;
    }
    left.as_bytes().ct_eq(right.as_bytes()).into()
}

/// Default adapter webhook check: unprefixed HMAC-SHA256.
#[must_use]
pub fn validate_hmac_sha256(payload: &str, signature: &str, signature_key: &str) -> bool {
    let expected = hmac_sha256_hex(payload.as_bytes(), signature_key.as_bytes());
    hash_equals(&expected, signature)
}

/// Prefixed `sha256=` HMAC used by GitHub and Bitbucket.
#[must_use]
pub fn validate_hmac_sha256_prefixed(payload: &str, signature: &str, signature_key: &str) -> bool {
    let expected = format!(
        "sha256={}",
        hmac_sha256_hex(payload.as_bytes(), signature_key.as_bytes())
    );
    hash_equals(&expected, signature)
}

/// PHP `fnmatch` (case-sensitive, `*` matches `/`).
#[must_use]
pub fn fnmatch(pattern: &str, name: &str) -> bool {
    glob_to_regex(pattern).is_match(name)
}

fn glob_to_regex(pattern: &str) -> regex::Regex {
    let mut regex = String::from("^");
    let bytes = pattern.as_bytes();
    let mut i = 0;
    while i < bytes.len() {
        match bytes[i] {
            b'*' => regex.push_str(".*"),
            b'?' => regex.push('.'),
            b'[' => {
                regex.push('[');
                i += 1;
                if i < bytes.len() && (bytes[i] == b'!' || bytes[i] == b'^') {
                    regex.push('^');
                    i += 1;
                }
                while i < bytes.len() && bytes[i] != b']' {
                    if bytes[i] == b'\\' {
                        regex.push_str(r"\\");
                    } else {
                        regex.push(bytes[i] as char);
                    }
                    i += 1;
                }
                regex.push(']');
            }
            b'\\' => {
                regex.push('\\');
                i += 1;
                if i < bytes.len() {
                    regex.push(bytes[i] as char);
                }
                i += 1;
                continue;
            }
            c @ (b'.' | b'+' | b'(' | b')' | b'{' | b'}' | b'|' | b'^' | b'$') => {
                regex.push('\\');
                regex.push(c as char);
            }
            c => regex.push(c as char),
        }
        i += 1;
    }
    regex.push('$');
    regex::Regex::new(&regex).unwrap_or_else(|_| regex::Regex::new("^$").expect("empty regex"))
}

/// PHP `Git::normalizeRepositoryPath`.
#[must_use]
pub fn normalize_repository_path(path: &str) -> String {
    path.split('/')
        .filter(|segment| !segment.is_empty() && *segment != ".")
        .collect::<Vec<_>>()
        .join("/")
}

/// PHP `Git::matchGlob`.
#[must_use]
pub fn match_glob(names: Vec<String>, pattern: &str) -> Vec<String> {
    if pattern.is_empty() {
        return names;
    }
    names
        .into_iter()
        .filter(|name| fnmatch(pattern, name))
        .collect()
}

/// PHP `array_column($items, $key)` for objects, skipping non-objects.
#[must_use]
pub fn array_column_str(items: &[Value], key: &str) -> Vec<String> {
    items
        .iter()
        .filter_map(|item| item.get(key).map(strval))
        .collect()
}

/// PHP `array_keys` for a JSON object (insertion / btree order).
#[must_use]
pub fn array_keys(value: &Value) -> Vec<String> {
    match value {
        Value::Object(map) => map.keys().cloned().collect(),
        _ => Vec::new(),
    }
}

/// PHP `gmdate('Y-m-d\TH:i:s\Z')`.
#[must_use]
pub fn gmdate_iso() -> String {
    let now = time::OffsetDateTime::now_utc();
    format!(
        "{:04}-{:02}-{:02}T{:02}:{:02}:{:02}Z",
        now.year(),
        u8::from(now.month()),
        now.day(),
        now.hour(),
        now.minute(),
        now.second()
    )
}

/// Standard User-Agent sent by PHP `Adapter::call()`.
pub const USER_AGENT: &str =
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/70.0.3538.77 Safari/537.36";
