//! PHP `mixed` query values.

use serde_json::{Map, Number, Value as JsonValue};
use std::collections::BTreeMap;

use crate::enums::NullsPosition;
use crate::query::Query;

/// A PHP `mixed` value stored on a [`Query`].
#[derive(Debug, Clone, PartialEq, Default)]
pub enum QueryValue {
    #[default]
    Null,
    Bool(bool),
    Int(i64),
    UInt(u64),
    Float(f64),
    String(String),
    Array(Vec<QueryValue>),
    Object(BTreeMap<String, QueryValue>),
    Query(Box<Query>),
    NullsPosition(NullsPosition),
}

impl QueryValue {
    pub fn is_null(&self) -> bool {
        matches!(self, Self::Null)
    }

    pub fn as_query(&self) -> Option<&Query> {
        match self {
            Self::Query(q) => Some(q),
            _ => None,
        }
    }

    pub fn as_query_mut(&mut self) -> Option<&mut Query> {
        match self {
            Self::Query(q) => Some(q),
            _ => None,
        }
    }

    pub fn as_str(&self) -> Option<&str> {
        match self {
            Self::String(s) => Some(s),
            _ => None,
        }
    }

    pub fn as_i64(&self) -> Option<i64> {
        match self {
            Self::Int(n) => Some(*n),
            Self::UInt(n) => i64::try_from(*n).ok(),
            Self::Float(n) if n.fract() == 0.0 => Some(*n as i64),
            Self::Bool(true) => Some(1),
            Self::Bool(false) => Some(0),
            _ => None,
        }
    }

    pub fn as_f64(&self) -> Option<f64> {
        match self {
            Self::Float(n) => Some(*n),
            Self::Int(n) => Some(*n as f64),
            Self::UInt(n) => Some(*n as f64),
            _ => None,
        }
    }

    pub fn as_bool(&self) -> Option<bool> {
        match self {
            Self::Bool(b) => Some(*b),
            _ => None,
        }
    }

    pub fn as_array(&self) -> Option<&[QueryValue]> {
        match self {
            Self::Array(a) => Some(a),
            _ => None,
        }
    }

    pub fn as_nulls_position(&self) -> Option<NullsPosition> {
        match self {
            Self::NullsPosition(p) => Some(*p),
            _ => None,
        }
    }

    /// PHP `(string)` cast used by LIKE / AST helpers.
    pub fn php_to_string(&self) -> String {
        match self {
            Self::Null | Self::Bool(false) | Self::Query(_) => String::new(),
            Self::Bool(true) => "1".to_owned(),
            Self::Int(n) => n.to_string(),
            Self::UInt(n) => n.to_string(),
            Self::Float(n) => php_float_to_string(*n),
            Self::String(s) => s.clone(),
            Self::Array(_) | Self::Object(_) => "Array".to_owned(),
            Self::NullsPosition(p) => p.as_str().to_owned(),
        }
    }

    pub fn to_json(&self) -> JsonValue {
        match self {
            Self::Null => JsonValue::Null,
            Self::Bool(b) => JsonValue::Bool(*b),
            Self::Int(n) => JsonValue::Number((*n).into()),
            Self::UInt(n) => JsonValue::Number((*n).into()),
            Self::Float(n) => Number::from_f64(*n).map_or(JsonValue::Null, JsonValue::Number),
            Self::String(s) => JsonValue::String(s.clone()),
            Self::Array(items) => JsonValue::Array(items.iter().map(Self::to_json).collect()),
            Self::Object(map) => {
                let mut out = Map::new();
                for (k, v) in map {
                    out.insert(k.clone(), v.to_json());
                }
                JsonValue::Object(out)
            }
            Self::Query(q) => q.to_json_value(),
            Self::NullsPosition(p) => JsonValue::String(p.as_str().to_owned()),
        }
    }

    pub fn from_json(value: &JsonValue) -> Self {
        match value {
            JsonValue::Null => Self::Null,
            JsonValue::Bool(b) => Self::Bool(*b),
            JsonValue::Number(n) => {
                if let Some(i) = n.as_i64() {
                    Self::Int(i)
                } else if let Some(u) = n.as_u64() {
                    Self::UInt(u)
                } else if let Some(f) = n.as_f64() {
                    Self::Float(f)
                } else {
                    Self::Null
                }
            }
            JsonValue::String(s) => Self::String(s.clone()),
            JsonValue::Array(items) => Self::Array(items.iter().map(Self::from_json).collect()),
            JsonValue::Object(map) => {
                let mut out = BTreeMap::new();
                for (k, v) in map {
                    out.insert(k.clone(), Self::from_json(v));
                }
                Self::Object(out)
            }
        }
    }

    pub fn php_gettype(value: &JsonValue) -> &'static str {
        match value {
            JsonValue::Null => "NULL",
            JsonValue::Bool(_) => "boolean",
            JsonValue::Number(n) => {
                if n.is_i64() || n.is_u64() {
                    "integer"
                } else {
                    "double"
                }
            }
            JsonValue::String(_) => "string",
            JsonValue::Array(_) | JsonValue::Object(_) => "array",
        }
    }
}

fn php_float_to_string(n: f64) -> String {
    let s = n.to_string();
    if s.contains('.') || s.contains('e') || s.contains('E') {
        s
    } else {
        format!("{n:.1}")
    }
}

impl From<()> for QueryValue {
    fn from((): ()) -> Self {
        Self::Null
    }
}

impl From<bool> for QueryValue {
    fn from(v: bool) -> Self {
        Self::Bool(v)
    }
}

impl From<i32> for QueryValue {
    fn from(v: i32) -> Self {
        Self::Int(i64::from(v))
    }
}

impl From<i64> for QueryValue {
    fn from(v: i64) -> Self {
        Self::Int(v)
    }
}

impl From<u32> for QueryValue {
    fn from(v: u32) -> Self {
        Self::UInt(u64::from(v))
    }
}

impl From<u64> for QueryValue {
    fn from(v: u64) -> Self {
        Self::UInt(v)
    }
}

impl From<usize> for QueryValue {
    fn from(v: usize) -> Self {
        Self::UInt(v as u64)
    }
}

impl From<f32> for QueryValue {
    fn from(v: f32) -> Self {
        Self::Float(f64::from(v))
    }
}

impl From<f64> for QueryValue {
    fn from(v: f64) -> Self {
        Self::Float(v)
    }
}

impl From<String> for QueryValue {
    fn from(v: String) -> Self {
        Self::String(v)
    }
}

impl From<&str> for QueryValue {
    fn from(v: &str) -> Self {
        Self::String(v.to_owned())
    }
}

impl From<NullsPosition> for QueryValue {
    fn from(v: NullsPosition) -> Self {
        Self::NullsPosition(v)
    }
}

impl From<Query> for QueryValue {
    fn from(v: Query) -> Self {
        Self::Query(Box::new(v))
    }
}

impl<T: Into<QueryValue>> From<Vec<T>> for QueryValue {
    fn from(v: Vec<T>) -> Self {
        Self::Array(v.into_iter().map(Into::into).collect())
    }
}

impl From<JsonValue> for QueryValue {
    fn from(v: JsonValue) -> Self {
        Self::from_json(&v)
    }
}

impl From<&JsonValue> for QueryValue {
    fn from(v: &JsonValue) -> Self {
        Self::from_json(v)
    }
}

impl<T: Into<QueryValue>> From<Option<T>> for QueryValue {
    fn from(v: Option<T>) -> Self {
        v.map_or(Self::Null, Into::into)
    }
}

/// Convert a list of values into `Vec<QueryValue>`.
pub trait IntoValues {
    fn into_values(self) -> Vec<QueryValue>;
}

impl<T: Into<QueryValue>> IntoValues for Vec<T> {
    fn into_values(self) -> Vec<QueryValue> {
        self.into_iter().map(Into::into).collect()
    }
}

impl<T: Into<QueryValue> + Clone> IntoValues for &[T] {
    fn into_values(self) -> Vec<QueryValue> {
        self.iter().cloned().map(Into::into).collect()
    }
}

impl<T: Into<QueryValue>, const N: usize> IntoValues for [T; N] {
    fn into_values(self) -> Vec<QueryValue> {
        self.into_iter().map(Into::into).collect()
    }
}

impl IntoValues for () {
    fn into_values(self) -> Vec<QueryValue> {
        Vec::new()
    }
}
