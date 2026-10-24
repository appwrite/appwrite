//! Queries as the API takes them: JSON strings.
//!
//! PHP: `Appwrite\Query` (the static constructors return the JSON string).

use php_std::{Array, Value};

/// `json_encode(array_filter(['method' => ..., 'attribute' => ..., 'values' => ...]))`.
fn build(method: &str, attribute: Option<&str>, values: Option<Value>) -> String {
    let values = values.map(|v| match v {
        Value::Array(a) => a,
        Value::Null => Array::new(),
        v => Array::from_list(vec![v]),
    });
    let mut a = Array::new();
    a.set("method".into(), Value::from(method));
    if let Some(attribute) = attribute.filter(|s| !s.is_empty() && *s != "0") {
        a.set("attribute".into(), Value::from(attribute));
    }
    if let Some(values) = values.filter(|v| !v.is_empty()) {
        a.set("values".into(), Value::Array(values));
    }
    php_std::json::encode(&Value::Array(a), php_std::json::Flags::NONE, php_std::json::DEFAULT_DEPTH)
        .unwrap_or_default()
}

/// `Query::equal($attribute, $value)`: a list of values, or one value.
pub fn equal(attribute: &str, value: Value) -> String {
    build("equal", Some(attribute), Some(value))
}

/// `Query::select($attributes)`.
pub fn select(attributes: Vec<String>) -> String {
    build(
        "select",
        None,
        Some(Value::Array(Array::from_list(attributes.iter().map(|s| Value::from(s.as_str())).collect()))),
    )
}

/// `Query::cursorAfter($documentId)`.
pub fn cursor_after(id: &str) -> String {
    build("cursorAfter", None, Some(Value::from(id)))
}

/// `Query::limit($limit)`.
pub fn limit(limit: i64) -> String {
    build("limit", None, Some(Value::Int(limit)))
}

/// `Query::offset($offset)`.
pub fn offset(offset: i64) -> String {
    build("offset", None, Some(Value::Int(offset)))
}

/// A list of queries as a request argument.
pub fn list(queries: Vec<String>) -> Value {
    Value::Array(Array::from_list(queries.into_iter().map(|q| Value::from(q.as_str())).collect()))
}
