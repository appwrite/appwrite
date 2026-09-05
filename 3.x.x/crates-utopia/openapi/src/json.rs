//! JSON-like values with PHP empty-array dualism (object and list).

use indexmap::IndexMap;
use serde_json::Number as SerdeNumber;

/// A decoded JSON value. Object key order matches the source document.
#[derive(Clone, Debug, PartialEq)]
pub enum Json {
    Null,
    Bool(bool),
    Number(JsonNumber),
    String(String),
    Array(Vec<Json>),
    Object(IndexMap<String, Json>),
}

/// JSON number that preserves PHP int vs float.
#[derive(Clone, Debug)]
pub enum JsonNumber {
    Int(i64),
    UInt(u64),
    Float(f64),
}

impl PartialEq for JsonNumber {
    fn eq(&self, other: &Self) -> bool {
        match (self.as_f64(), other.as_f64()) {
            (Some(a), Some(b)) => a == b,
            _ => false,
        }
    }
}

impl JsonNumber {
    pub fn as_i64(&self) -> Option<i64> {
        match self {
            Self::Int(v) => Some(*v),
            Self::UInt(v) => i64::try_from(*v).ok(),
            Self::Float(v) if v.fract() == 0.0 => {
                let truncated = *v as i64;
                if (truncated as f64 - *v).abs() < f64::EPSILON {
                    Some(truncated)
                } else {
                    None
                }
            }
            Self::Float(_) => None,
        }
    }

    pub fn is_int(&self) -> bool {
        matches!(self, Self::Int(_) | Self::UInt(_))
    }

    #[allow(clippy::match_same_arms)]
    pub fn as_f64(&self) -> Option<f64> {
        match self {
            Self::Int(v) => Some(*v as f64),
            Self::UInt(v) => Some(*v as f64),
            Self::Float(v) => Some(*v),
        }
    }
}

impl Json {
    pub fn from_serde(value: serde_json::Value) -> Self {
        match value {
            serde_json::Value::Null => Self::Null,
            serde_json::Value::Bool(b) => Self::Bool(b),
            serde_json::Value::Number(n) => Self::Number(json_number_from_serde(&n)),
            serde_json::Value::String(s) => Self::String(s),
            serde_json::Value::Array(items) => {
                Self::Array(items.into_iter().map(Self::from_serde).collect())
            }
            serde_json::Value::Object(map) => Self::Object(
                map.into_iter()
                    .map(|(k, v)| (k, Self::from_serde(v)))
                    .collect(),
            ),
        }
    }

    pub fn parse_str(input: &str) -> Result<Self, serde_json::Error> {
        let value: serde_json::Value = serde_json::from_str(input)?;
        Ok(Self::from_serde(value))
    }

    pub fn is_empty_php_array(&self) -> bool {
        match self {
            Self::Array(a) => a.is_empty(),
            Self::Object(o) => o.is_empty(),
            _ => false,
        }
    }

    /// PHP `array_is_list` after empty-object normalization.
    pub fn is_list(&self) -> bool {
        match self {
            Self::Array(_) => true,
            Self::Object(o) if o.is_empty() => true,
            _ => false,
        }
    }

    pub fn as_object(&self) -> Option<&IndexMap<String, Json>> {
        match self {
            Self::Object(o) => Some(o),
            Self::Array(a) if a.is_empty() => {
                static EMPTY: OnceLockEmpty = OnceLockEmpty;
                Some(EMPTY.get())
            }
            _ => None,
        }
    }

    pub fn into_object(self) -> Option<IndexMap<String, Json>> {
        match self {
            Self::Object(o) => Some(o),
            Self::Array(a) if a.is_empty() => Some(IndexMap::new()),
            _ => None,
        }
    }

    pub fn as_str(&self) -> Option<&str> {
        match self {
            Self::String(s) => Some(s),
            _ => None,
        }
    }

    pub fn php_bool(&self) -> bool {
        match self {
            Self::Null => false,
            Self::Bool(b) => *b,
            Self::Number(n) => n.as_f64().is_some_and(|v| v != 0.0),
            Self::String(s) => !s.is_empty() && s != "0",
            Self::Array(a) => !a.is_empty(),
            Self::Object(o) => !o.is_empty(),
        }
    }
}

struct OnceLockEmpty;

impl OnceLockEmpty {
    fn get(&self) -> &'static IndexMap<String, Json> {
        use std::sync::OnceLock;
        static EMPTY: OnceLock<IndexMap<String, Json>> = OnceLock::new();
        EMPTY.get_or_init(IndexMap::new)
    }
}

fn json_number_from_serde(n: &SerdeNumber) -> JsonNumber {
    if let Some(i) = n.as_i64() {
        JsonNumber::Int(i)
    } else if let Some(u) = n.as_u64() {
        JsonNumber::UInt(u)
    } else {
        JsonNumber::Float(n.as_f64().unwrap_or(0.0))
    }
}

impl From<serde_json::Value> for Json {
    fn from(value: serde_json::Value) -> Self {
        Self::from_serde(value)
    }
}

impl From<IndexMap<String, Json>> for Json {
    fn from(value: IndexMap<String, Json>) -> Self {
        Self::Object(value)
    }
}

impl Default for Json {
    fn default() -> Self {
        Self::Null
    }
}

pub fn empty_object() -> &'static Json {
    use std::sync::OnceLock;
    static V: OnceLock<Json> = OnceLock::new();
    V.get_or_init(|| Json::Object(IndexMap::new()))
}

pub fn empty_array() -> &'static Json {
    use std::sync::OnceLock;
    static V: OnceLock<Json> = OnceLock::new();
    V.get_or_init(|| Json::Array(Vec::new()))
}
