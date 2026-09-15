use serde_json::{Map, Value};

/// PHP mixed cache payload: string, JSON array/object, bytes, or null.
#[derive(Debug, Clone, PartialEq)]
pub enum CacheValue {
    String(String),
    Array(Value),
    Bytes(Vec<u8>),
    Null,
}

impl CacheValue {
    /// PHP `empty($data)` for `array|string`: `""`, `"0"`, and `[]` are empty.
    /// An empty JSON object (`{}` / `stdClass`) is **not** empty.
    #[must_use]
    pub fn is_php_empty(&self) -> bool {
        match self {
            Self::String(s) | Self::Array(Value::String(s)) => s.is_empty() || s == "0",
            Self::Array(Value::Array(items)) => items.is_empty(),
            Self::Array(Value::Null | Value::Bool(false)) | Self::Null => true,
            Self::Array(Value::Number(n)) => n.as_i64() == Some(0) || n.as_u64() == Some(0),
            Self::Array(_) => false,
            Self::Bytes(b) => b.is_empty(),
        }
    }

    #[must_use]
    pub fn as_str(&self) -> Option<&str> {
        match self {
            Self::String(s) | Self::Array(Value::String(s)) => Some(s.as_str()),
            _ => None,
        }
    }

    #[must_use]
    pub fn into_json(self) -> Value {
        match self {
            Self::String(s) => Value::String(s),
            Self::Array(v) => v,
            Self::Bytes(b) => Value::String(String::from_utf8_lossy(&b).into_owned()),
            Self::Null => Value::Null,
        }
    }

    #[must_use]
    pub fn from_json(value: Value) -> Self {
        match value {
            Value::String(s) => Self::String(s),
            Value::Null => Self::Null,
            other => Self::Array(other),
        }
    }

    /// PHP `implode('', $data)` used by `file_put_contents` when `$data` is an array.
    #[must_use]
    pub fn php_file_bytes(&self) -> Vec<u8> {
        match self {
            Self::String(s) => s.as_bytes().to_vec(),
            Self::Bytes(b) => b.clone(),
            Self::Null => Vec::new(),
            Self::Array(Value::Array(items)) => {
                let mut out = Vec::new();
                for item in items {
                    out.extend_from_slice(&php_stringify(item).into_bytes());
                }
                out
            }
            Self::Array(v) => php_stringify(v).into_bytes(),
        }
    }
}

fn php_stringify(value: &Value) -> String {
    match value {
        Value::Null | Value::Bool(false) => String::new(),
        Value::Bool(true) => "1".into(),
        Value::Number(n) => n.to_string(),
        Value::String(s) => s.clone(),
        Value::Array(_) | Value::Object(_) => "Array".into(),
    }
}

impl From<String> for CacheValue {
    fn from(value: String) -> Self {
        Self::String(value)
    }
}

impl From<&str> for CacheValue {
    fn from(value: &str) -> Self {
        Self::String(value.to_owned())
    }
}

impl From<Vec<String>> for CacheValue {
    fn from(value: Vec<String>) -> Self {
        Self::Array(Value::Array(value.into_iter().map(Value::String).collect()))
    }
}

impl From<Vec<&str>> for CacheValue {
    fn from(value: Vec<&str>) -> Self {
        Self::Array(Value::Array(
            value
                .into_iter()
                .map(|s| Value::String(s.to_owned()))
                .collect(),
        ))
    }
}

impl From<Value> for CacheValue {
    fn from(value: Value) -> Self {
        Self::from_json(value)
    }
}

impl From<Map<String, Value>> for CacheValue {
    fn from(value: Map<String, Value>) -> Self {
        Self::Array(Value::Object(value))
    }
}

/// PHP `load()`: `false` on miss, payload on hit (including JSON `null`).
#[derive(Debug, Clone, PartialEq)]
pub enum LoadResult {
    Miss,
    Hit(CacheValue),
}

impl LoadResult {
    #[must_use]
    pub fn is_miss(&self) -> bool {
        matches!(self, Self::Miss)
    }

    #[must_use]
    pub fn is_hit(&self) -> bool {
        matches!(self, Self::Hit(_))
    }

    #[must_use]
    pub fn as_value(&self) -> Option<&CacheValue> {
        match self {
            Self::Hit(v) => Some(v),
            Self::Miss => None,
        }
    }

    #[must_use]
    pub fn into_value(self) -> Option<CacheValue> {
        match self {
            Self::Hit(v) => Some(v),
            Self::Miss => None,
        }
    }
}

/// PHP `save()`: payload on success, `false` on failure.
#[derive(Debug, Clone, PartialEq)]
pub enum SaveResult {
    Failed,
    Saved(CacheValue),
}

impl SaveResult {
    #[must_use]
    pub fn is_failed(&self) -> bool {
        matches!(self, Self::Failed)
    }

    #[must_use]
    pub fn is_saved(&self) -> bool {
        matches!(self, Self::Saved(_))
    }

    #[must_use]
    pub fn as_value(&self) -> Option<&CacheValue> {
        match self {
            Self::Saved(v) => Some(v),
            Self::Failed => None,
        }
    }

    #[must_use]
    pub fn into_value(self) -> Option<CacheValue> {
        match self {
            Self::Saved(v) => Some(v),
            Self::Failed => None,
        }
    }
}

/// PHP `empty($key)` for string keys: `""` and `"0"`.
#[must_use]
pub fn is_empty_key(key: &str) -> bool {
    key.is_empty() || key == "0"
}

pub(crate) fn unix_now() -> i64 {
    std::time::SystemTime::now()
        .duration_since(std::time::UNIX_EPOCH)
        .map(|d| d.as_secs() as i64)
        .unwrap_or(0)
}
