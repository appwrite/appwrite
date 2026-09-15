//! Shared HTTP helpers matching PHP `Utopia\Fetch\Client` via [`utopia-client`].

use bytes::Bytes;
use http::{Method, Request};
use serde_json::{Map, Value};
use utopia_client::adapter::curl;
use utopia_client::{Client, StreamingClient};

pub const DEFAULT_TIMEOUT: i32 = 5;
pub const DEFAULT_CONNECT_TIMEOUT: i32 = 1;
pub const CONTENT_TYPE_JSON: &str = "application/json";

pub fn normalize_timeout(timeout: i32) -> u64 {
    if timeout > 0 {
        timeout as u64
    } else {
        DEFAULT_TIMEOUT as u64
    }
}

pub fn normalize_connect_timeout(connect_timeout: i32) -> u64 {
    if connect_timeout > 0 {
        connect_timeout as u64
    } else {
        DEFAULT_CONNECT_TIMEOUT as u64
    }
}

/// POST JSON like PHP `Utopia\Fetch\Client` (mapped to [`utopia-client`]).
/// Transport errors become HTTP 500.
pub fn post_json(
    url: &str,
    extra_headers: &[(&str, &str)],
    body: &Value,
    timeout: i32,
    connect_timeout: i32,
    adapter_label: &str,
) -> u16 {
    let client = match Client::new(curl::Client::new())
        .with_timeout(normalize_timeout(timeout) as f64)
        .and_then(|client| {
            client.with_connect_timeout(normalize_connect_timeout(connect_timeout) as f64)
        }) {
        Ok(client) => client,
        Err(error) => {
            eprintln!("{adapter_label} push failed with fetch error: {error}");
            return 500;
        }
    };

    let payload = match serde_json::to_vec(body) {
        Ok(bytes) => bytes,
        Err(error) => {
            eprintln!("{adapter_label} push failed with fetch error: {error}");
            return 500;
        }
    };
    let mut builder = Request::builder()
        .method(Method::POST)
        .uri(url)
        .header("content-type", CONTENT_TYPE_JSON);
    for (name, value) in extra_headers {
        builder = builder.header(*name, *value);
    }
    let request = match builder.body(Bytes::from(payload)) {
        Ok(request) => request,
        Err(error) => {
            eprintln!("{adapter_label} push failed with fetch error: {error}");
            return 500;
        }
    };

    match client.send_request(request) {
        Ok(response) => {
            let code = response.status().as_u16();
            if code >= 400 {
                let text = String::from_utf8_lossy(response.body());
                eprintln!("{adapter_label} push failed with status code {code}: {text}");
            }
            code
        }
        Err(error) => {
            eprintln!("{adapter_label} push failed with fetch error: {error}");
            500
        }
    }
}

/// PHP `json_encode` of an empty array is `[]`, not `{}`.
pub fn php_assoc(map: Map<String, Value>) -> Value {
    if map.is_empty() {
        Value::Array(Vec::new())
    } else {
        Value::Object(map)
    }
}

/// PHP `empty($string)` including `null`.
pub fn php_empty_opt(value: Option<&str>) -> bool {
    match value {
        None => true,
        Some(s) => crate::log::Log::php_empty(s),
    }
}

/// PHP `intval($float)`.
pub fn php_intval(value: f64) -> i64 {
    value as i64
}

/// PHP `isset($array[$key])` - false for missing or JSON null.
pub fn php_isset(map: &Map<String, Value>, key: &str) -> bool {
    match map.get(key) {
        None | Some(Value::Null) => false,
        Some(_) => true,
    }
}

/// PHP `is_array()` - true for both JSON arrays and objects.
pub fn php_is_array(value: &Value) -> bool {
    matches!(value, Value::Array(_) | Value::Object(_))
}

/// Iterate PHP-array values in insertion order.
pub fn php_array_values(value: &Value) -> Vec<&Value> {
    match value {
        Value::Array(items) => items.iter().collect(),
        Value::Object(map) => map.values().collect(),
        _ => Vec::new(),
    }
}

/// PHP `$arr[$key] ?? $default` on an associative array.
pub fn php_index<'a>(value: &'a Value, key: &str) -> Option<&'a Value> {
    match value {
        Value::Object(map) => map.get(key),
        Value::Array(items) => key.parse::<usize>().ok().and_then(|i| items.get(i)),
        _ => None,
    }
}

/// PHP `var_export($value, true)` for `AppSignal` params.
pub fn php_var_export(value: &Value) -> String {
    php_var_export_inner(value, 0)
}

fn php_var_export_inner(value: &Value, indent: usize) -> String {
    match value {
        Value::Null => "NULL".to_string(),
        Value::Bool(true) => "true".to_string(),
        Value::Bool(false) => "false".to_string(),
        Value::Number(number) => {
            if let Some(i) = number.as_i64() {
                i.to_string()
            } else if let Some(u) = number.as_u64() {
                u.to_string()
            } else {
                number.to_string()
            }
        }
        Value::String(s) => format!("'{}'", escape_php_single(s)),
        Value::Array(items) => php_var_export_array(
            items
                .iter()
                .enumerate()
                .map(|(i, item)| (i.to_string(), false, item)),
            indent,
        ),
        Value::Object(map) => {
            php_var_export_array(map.iter().map(|(k, v)| (k.clone(), true, v)), indent)
        }
    }
}

fn php_var_export_array<'a>(
    entries: impl Iterator<Item = (String, bool, &'a Value)>,
    indent: usize,
) -> String {
    let pad = "  ".repeat(indent + 1);
    let close = "  ".repeat(indent);
    let mut out = String::from("array (\n");
    for (key, quoted, value) in entries {
        let key_s = if quoted {
            format!("'{}'", escape_php_single(&key))
        } else {
            key
        };
        out.push_str(&pad);
        out.push_str(&key_s);
        out.push_str(" => ");
        out.push_str(&php_var_export_inner(value, indent + 1));
        out.push_str(",\n");
    }
    out.push_str(&close);
    out.push(')');
    out
}

fn escape_php_single(s: &str) -> String {
    s.replace('\\', "\\\\").replace('\'', "\\'")
}
