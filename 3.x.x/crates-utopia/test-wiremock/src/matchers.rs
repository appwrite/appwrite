//! Request matchers (WireMock admin JSON).

use serde_json::{json, Value};

/// Builder piece that becomes part of a WireMock `request` object.
#[derive(Debug, Clone)]
pub struct Matcher {
    pub(crate) key: &'static str,
    pub(crate) value: Value,
}

#[must_use]
pub fn method(value: &str) -> Matcher {
    Matcher {
        key: "method",
        value: Value::String(value.to_ascii_uppercase()),
    }
}

#[must_use]
pub fn path(value: &str) -> Matcher {
    Matcher {
        key: "urlPath",
        value: Value::String(value.to_string()),
    }
}

#[must_use]
pub fn path_regex(value: &str) -> Matcher {
    Matcher {
        key: "urlPathPattern",
        value: Value::String(value.to_string()),
    }
}

#[must_use]
pub fn query_param(name: &str, value: &str) -> Matcher {
    Matcher {
        key: "queryParameters",
        value: json!({ name: { "equalTo": value } }),
    }
}

#[must_use]
pub fn header(name: &str, value: &str) -> Matcher {
    Matcher {
        key: "headers",
        value: json!({ name: { "equalTo": value } }),
    }
}

#[must_use]
pub fn body_string_contains(value: &str) -> Matcher {
    Matcher {
        key: "bodyPatterns",
        value: json!([{ "contains": value }]),
    }
}

pub(crate) fn merge_matchers(matchers: &[Matcher]) -> Value {
    let mut request = serde_json::Map::new();
    for matcher in matchers {
        match matcher.key {
            "queryParameters" | "headers" => {
                let existing = request
                    .entry(matcher.key.to_string())
                    .or_insert_with(|| Value::Object(serde_json::Map::new()));
                if let (Value::Object(dst), Value::Object(src)) = (existing, &matcher.value) {
                    for (k, v) in src {
                        dst.insert(k.clone(), v.clone());
                    }
                }
            }
            "bodyPatterns" => {
                let existing = request
                    .entry("bodyPatterns".to_string())
                    .or_insert_with(|| Value::Array(Vec::new()));
                if let (Value::Array(dst), Value::Array(src)) = (existing, &matcher.value) {
                    dst.extend(src.iter().cloned());
                }
            }
            other => {
                request.insert(other.to_string(), matcher.value.clone());
            }
        }
    }
    if !request.contains_key("method") {
        request.insert("method".into(), Value::String("ANY".into()));
    }
    Value::Object(request)
}
