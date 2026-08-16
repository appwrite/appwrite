//! Mixed document / query / operator values (PHP `mixed`).

use indexmap::IndexMap;
use serde_json::{Map, Number, Value};

use crate::document::Document;
use crate::operator::Operator;
use crate::query::Query;

/// PHP mixed payload stored on documents, queries, and operators.
#[derive(Debug, Clone)]
pub enum AttrValue {
    Null,
    Bool(bool),
    Number(Number),
    String(String),
    /// PHP array: list or associative. Keys stay insertion-ordered.
    Array(IndexMap<String, AttrValue>),
    Document(Box<Document>),
    Query(Box<Query>),
    Operator(Box<Operator>),
}

impl PartialEq for AttrValue {
    fn eq(&self, other: &Self) -> bool {
        match (self, other) {
            (Self::Null, Self::Null) => true,
            (Self::Bool(a), Self::Bool(b)) => a == b,
            (Self::Number(a), Self::Number(b)) => a == b,
            (Self::String(a), Self::String(b)) => a == b,
            (Self::Array(a), Self::Array(b)) => a == b,
            (Self::Document(a), Self::Document(b)) => a == b,
            (Self::Query(a), Self::Query(b)) => a.eq_shape(b) && a.values() == b.values(),
            (Self::Operator(a), Self::Operator(b)) => {
                a.get_method() == b.get_method()
                    && a.get_attribute() == b.get_attribute()
                    && a.get_values() == b.get_values()
            }
            _ => false,
        }
    }
}

impl AttrValue {
    #[must_use]
    pub fn is_null(&self) -> bool {
        matches!(self, Self::Null)
    }

    /// PHP `isset`: null is not set.
    #[must_use]
    pub fn is_set(&self) -> bool {
        !self.is_null()
    }

    /// PHP `empty()`.
    #[must_use]
    pub fn is_php_empty(&self) -> bool {
        match self {
            Self::Null => true,
            Self::Bool(false) => true,
            Self::Number(n) => {
                n.as_i64() == Some(0) || n.as_u64() == Some(0) || n.as_f64() == Some(0.0)
            }
            Self::String(s) => s.is_empty() || s == "0",
            Self::Array(a) => a.is_empty(),
            Self::Document(d) => d.is_empty(),
            Self::Query(_) | Self::Operator(_) | Self::Bool(true) => false,
        }
    }

    #[must_use]
    pub fn as_str(&self) -> Option<&str> {
        match self {
            Self::String(s) => Some(s),
            _ => None,
        }
    }

    #[must_use]
    pub fn as_bool(&self) -> Option<bool> {
        match self {
            Self::Bool(b) => Some(*b),
            _ => None,
        }
    }

    #[must_use]
    pub fn as_i64(&self) -> Option<i64> {
        match self {
            Self::Number(n) => n.as_i64(),
            Self::String(s) => s.parse().ok(),
            _ => None,
        }
    }

    #[must_use]
    pub fn as_f64(&self) -> Option<f64> {
        match self {
            Self::Number(n) => n.as_f64(),
            Self::String(s) => s.parse().ok(),
            _ => None,
        }
    }

    #[must_use]
    pub fn as_array(&self) -> Option<&IndexMap<String, AttrValue>> {
        match self {
            Self::Array(a) => Some(a),
            _ => None,
        }
    }

    pub fn as_array_mut(&mut self) -> Option<&mut IndexMap<String, AttrValue>> {
        match self {
            Self::Array(a) => Some(a),
            _ => None,
        }
    }

    #[must_use]
    pub fn as_document(&self) -> Option<&Document> {
        match self {
            Self::Document(d) => Some(d),
            _ => None,
        }
    }

    pub fn as_document_mut(&mut self) -> Option<&mut Document> {
        match self {
            Self::Document(d) => Some(d),
            _ => None,
        }
    }

    #[must_use]
    pub fn as_operator(&self) -> Option<&Operator> {
        match self {
            Self::Operator(o) => Some(o),
            _ => None,
        }
    }

    pub fn as_operator_mut(&mut self) -> Option<&mut Operator> {
        match self {
            Self::Operator(o) => Some(o),
            _ => None,
        }
    }

    #[must_use]
    pub fn as_query(&self) -> Option<&Query> {
        match self {
            Self::Query(q) => Some(q),
            _ => None,
        }
    }

    /// Index a list-shaped array with a numeric PHP key.
    #[must_use]
    pub fn get_index(&self, index: i64) -> Option<&AttrValue> {
        self.as_array()?.get(&index.to_string())
    }

    pub fn get_index_mut(&mut self, index: i64) -> Option<&mut AttrValue> {
        self.as_array_mut()?.get_mut(&index.to_string())
    }

    #[must_use]
    pub fn from_json(value: Value) -> Self {
        match value {
            Value::Null => Self::Null,
            Value::Bool(b) => Self::Bool(b),
            Value::Number(n) => Self::Number(n),
            Value::String(s) => Self::String(s),
            Value::Array(items) => {
                let mut map = IndexMap::new();
                for (i, item) in items.into_iter().enumerate() {
                    map.insert(i.to_string(), Self::from_json(item));
                }
                Self::Array(map)
            }
            Value::Object(obj) => {
                if obj.contains_key("$id") || obj.contains_key("$collection") {
                    match Document::try_from_json_object(obj) {
                        Ok(doc) => Self::Document(Box::new(doc)),
                        Err(_) => Self::Null,
                    }
                } else {
                    let mut map = IndexMap::new();
                    for (k, v) in obj {
                        map.insert(k, Self::from_json(v));
                    }
                    Self::Array(map)
                }
            }
        }
    }

    #[must_use]
    pub fn to_json(&self) -> Value {
        match self {
            Self::Null => Value::Null,
            Self::Bool(b) => Value::Bool(*b),
            Self::Number(n) => Value::Number(n.clone()),
            Self::String(s) => Value::String(s.clone()),
            Self::Array(items) => {
                if is_json_list(items) {
                    Value::Array(items.values().map(Self::to_json).collect())
                } else {
                    let mut obj = Map::new();
                    for (k, v) in items {
                        obj.insert(k.clone(), v.to_json());
                    }
                    Value::Object(obj)
                }
            }
            Self::Document(d) => Value::Object(d.get_array_copy_json(&[], &[])),
            Self::Query(q) => q.to_json_value(),
            Self::Operator(o) => o.to_json_value(),
        }
    }

    /// PHP `array_is_list`.
    #[must_use]
    pub fn is_list(&self) -> bool {
        match self {
            Self::Array(items) => is_json_list(items),
            _ => false,
        }
    }

    pub fn list_from_iter<I: IntoIterator<Item = AttrValue>>(iter: I) -> Self {
        let mut map = IndexMap::new();
        for (i, v) in iter.into_iter().enumerate() {
            map.insert(i.to_string(), v);
        }
        Self::Array(map)
    }

    pub fn push(&mut self, value: AttrValue) {
        let arr = if let Self::Array(a) = self {
            a
        } else {
            *self = Self::Array(IndexMap::new());
            if let Self::Array(a) = self {
                a
            } else {
                unreachable!()
            }
        };
        let next = arr
            .keys()
            .filter_map(|k| k.parse::<i64>().ok())
            .max()
            .map_or(0, |n| n + 1);
        arr.insert(next.to_string(), value);
    }

    pub fn prepend(&mut self, value: AttrValue) {
        let arr = if let Self::Array(a) = self {
            a
        } else {
            *self = Self::Array(IndexMap::new());
            if let Self::Array(a) = self {
                a
            } else {
                unreachable!()
            }
        };
        let mut next = IndexMap::new();
        next.insert("0".into(), value);
        for (i, (_, v)) in arr.drain(..).enumerate() {
            next.insert((i + 1).to_string(), v);
        }
        *arr = next;
    }
}

fn is_json_list(items: &IndexMap<String, AttrValue>) -> bool {
    if items.is_empty() {
        return true;
    }
    items
        .keys()
        .enumerate()
        .all(|(i, k)| k.parse::<usize>().ok() == Some(i))
}

impl Default for AttrValue {
    fn default() -> Self {
        Self::Null
    }
}

impl From<Value> for AttrValue {
    fn from(value: Value) -> Self {
        Self::from_json(value)
    }
}

impl From<String> for AttrValue {
    fn from(value: String) -> Self {
        Self::String(value)
    }
}

impl From<&str> for AttrValue {
    fn from(value: &str) -> Self {
        Self::String(value.to_owned())
    }
}

impl From<bool> for AttrValue {
    fn from(value: bool) -> Self {
        Self::Bool(value)
    }
}

impl From<i32> for AttrValue {
    fn from(value: i32) -> Self {
        Self::Number(Number::from(value))
    }
}

impl From<i64> for AttrValue {
    fn from(value: i64) -> Self {
        Self::Number(Number::from(value))
    }
}

impl From<u64> for AttrValue {
    fn from(value: u64) -> Self {
        Self::Number(Number::from(value))
    }
}

impl From<usize> for AttrValue {
    fn from(value: usize) -> Self {
        Self::Number(Number::from(value as u64))
    }
}

impl From<f64> for AttrValue {
    fn from(value: f64) -> Self {
        Number::from_f64(value).map_or(Self::Null, Self::Number)
    }
}

impl From<Document> for AttrValue {
    fn from(value: Document) -> Self {
        Self::Document(Box::new(value))
    }
}

impl From<Query> for AttrValue {
    fn from(value: Query) -> Self {
        Self::Query(Box::new(value))
    }
}

impl From<Operator> for AttrValue {
    fn from(value: Operator) -> Self {
        Self::Operator(Box::new(value))
    }
}

impl From<Vec<AttrValue>> for AttrValue {
    fn from(value: Vec<AttrValue>) -> Self {
        Self::list_from_iter(value)
    }
}

impl From<Vec<String>> for AttrValue {
    fn from(value: Vec<String>) -> Self {
        Self::list_from_iter(value.into_iter().map(AttrValue::String))
    }
}

impl From<Vec<&str>> for AttrValue {
    fn from(value: Vec<&str>) -> Self {
        Self::list_from_iter(value.into_iter().map(AttrValue::from))
    }
}

impl From<IndexMap<String, AttrValue>> for AttrValue {
    fn from(value: IndexMap<String, AttrValue>) -> Self {
        Self::Array(value)
    }
}

/// PHP `gettype()` labels used in exception messages.
#[must_use]
pub fn php_gettype(value: &Value) -> &'static str {
    match value {
        Value::Null => "NULL",
        Value::Bool(_) => "boolean",
        Value::Number(n) if n.is_i64() || n.is_u64() => "integer",
        Value::Number(_) => "double",
        Value::String(_) => "string",
        Value::Array(_) => "array",
        Value::Object(_) => "array",
    }
}

#[must_use]
pub fn php_gettype_attr(value: &AttrValue) -> &'static str {
    match value {
        AttrValue::Null => "NULL",
        AttrValue::Bool(_) => "boolean",
        AttrValue::Number(n) if n.is_i64() || n.is_u64() => "integer",
        AttrValue::Number(_) => "double",
        AttrValue::String(_) => "string",
        AttrValue::Array(_) => "array",
        AttrValue::Document(_) => "object",
        AttrValue::Query(_) => "object",
        AttrValue::Operator(_) => "object",
    }
}

/// PHP loose compare used by Memory `looseEquals` for scalars.
#[must_use]
pub fn loose_equals(left: &AttrValue, right: &AttrValue) -> bool {
    if left == right {
        return true;
    }
    match (left, right) {
        (AttrValue::Number(a), AttrValue::String(b)) => number_eq_str(a, b),
        (AttrValue::String(a), AttrValue::Number(b)) => number_eq_str(b, a),
        (AttrValue::Number(a), AttrValue::Bool(b)) => {
            a.as_u64() == Some(u64::from(*b)) || a.as_i64() == Some(i64::from(*b))
        }
        (AttrValue::Bool(a), AttrValue::Number(b)) => {
            b.as_u64() == Some(u64::from(*a)) || b.as_i64() == Some(i64::from(*a))
        }
        (AttrValue::String(a), AttrValue::Bool(b)) => {
            (a == "1" && *b) || (a == "0" && !*b) || (a.is_empty() && !*b)
        }
        (AttrValue::Bool(a), AttrValue::String(b)) => {
            (b == "1" && *a) || (b == "0" && !*a) || (b.is_empty() && !*a)
        }
        (AttrValue::Document(d), other) | (other, AttrValue::Document(d)) => loose_equals(
            &AttrValue::from_json(Value::Object(d.get_array_copy_json(&[], &[]))),
            other,
        ),
        _ => false,
    }
}

fn number_eq_str(n: &Number, s: &str) -> bool {
    if let Ok(i) = s.parse::<i64>() {
        return n.as_i64() == Some(i);
    }
    if let Ok(u) = s.parse::<u64>() {
        return n.as_u64() == Some(u);
    }
    if let Ok(f) = s.parse::<f64>() {
        return n.as_f64() == Some(f);
    }
    false
}
