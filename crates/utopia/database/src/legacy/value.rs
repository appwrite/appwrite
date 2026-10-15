//! The values documents and queries carry.
//!
//! A document attribute holds a PHP value: `null`, a bool, an integer, a
//! float, a byte string, an array (a list or an ordered map), a `stdClass`,
//! or one of the library's own objects: a nested [`Document`] (relationships),
//! an [`Operator`] (an atomic update such as an increment) or a [`Query`] (a
//! nested query inside `and`, `or` and `elemMatch`). [`Value`] is
//! [`php_std::Value`] with those three objects as its extension, so type
//! juggling, comparisons and the JSON codec behave as the engine does.
//!
//! PHP: values are plain PHP values; `$value instanceof Document`,
//! `instanceof Operator` and `instanceof Query` are [`Value::Ext`] with
//! [`Ext::Document`], [`Ext::Operator`] and [`Ext::Query`].

use crate::document::Document;
use crate::operator::Operator;
use crate::query::Query;

pub use php_std::types::KeyRef;
pub use php_std::{ArrayKey, Str};

/// A PHP value as the database carries it.
pub type Value = php_std::Value<Ext>;

/// A PHP array of database values.
pub type Array = php_std::Array<Ext>;

/// The objects (other than `stdClass`) a [`Value`] can hold.
#[derive(Clone, PartialEq, Debug)]
pub enum Ext {
    /// `Utopia\Database\Document`.
    Document(Box<Document>),
    /// `Utopia\Database\Operator`.
    Operator(Box<Operator>),
    /// `Utopia\Database\Query`.
    Query(Box<Query>),
    /// A BSON value the MongoDB adapter carries in documents and queries
    /// (`MongoDB\BSON\UTCDateTime`, `ObjectId`, `Regex`).
    Bson(Box<BsonValue>),
}

/// The BSON types (besides documents, arrays and scalars) a MongoDB
/// adapter's values hold, as PHP's extension decodes them.
#[derive(Clone, PartialEq, Debug)]
pub enum BsonValue {
    /// `MongoDB\BSON\UTCDateTime`: milliseconds since the epoch.
    UtcDateTime(i64),
    /// `MongoDB\BSON\ObjectId`: its 12 bytes.
    ObjectId([u8; 12]),
    /// `MongoDB\BSON\Regex`.
    Regex {
        /// `getPattern()`.
        pattern: String,
        /// `getFlags()`.
        flags: String,
    },
}

impl BsonValue {
    /// The PHP class.
    pub fn class(&self) -> &'static str {
        match self {
            BsonValue::UtcDateTime(_) => "MongoDB\\BSON\\UTCDateTime",
            BsonValue::ObjectId(_) => "MongoDB\\BSON\\ObjectId",
            BsonValue::Regex { .. } => "MongoDB\\BSON\\Regex",
        }
    }

    /// `(array)` of the object: its properties, as the extension exposes them.
    pub fn to_array(&self) -> Array {
        let pairs: Vec<(&str, String)> = match self {
            BsonValue::UtcDateTime(ms) => vec![("milliseconds", ms.to_string())],
            BsonValue::ObjectId(bytes) => vec![("oid", hex::encode(bytes))],
            BsonValue::Regex { pattern, flags } => vec![("pattern", pattern.clone()), ("flags", flags.clone())],
        };
        pairs.into_iter().map(|(k, v)| (php_std::ArrayKey::Str(k.into()), Value::Str(v.into()))).collect()
    }
}

impl From<BsonValue> for Value {
    fn from(b: BsonValue) -> Self {
        Value::Ext(Ext::Bson(Box::new(b)))
    }
}

impl php_std::Extension for Ext {
    fn class(&self) -> &str {
        match self {
            Ext::Document(d) => d.class(),
            Ext::Operator(_) => "Utopia\\Database\\Operator",
            Ext::Query(_) => "Utopia\\Database\\Query",
            Ext::Bson(b) => b.class(),
        }
    }

    fn to_array(&self) -> Array {
        match self {
            Ext::Document(d) => d.array_copy(&[], &[]),
            Ext::Operator(o) => o.to_array(),
            Ext::Bson(b) => b.to_array(),
            // A logical query with values that are not queries has no array
            // form (PHP throws `Error`); it is empty here.
            Ext::Query(q) => q.to_array().unwrap_or_default(),
        }
    }
}

impl From<Document> for Value {
    fn from(d: Document) -> Self {
        Value::Ext(Ext::Document(Box::new(d)))
    }
}

impl From<Operator> for Value {
    fn from(o: Operator) -> Self {
        Value::Ext(Ext::Operator(Box::new(o)))
    }
}

impl From<Query> for Value {
    fn from(q: Query) -> Self {
        Value::Ext(Ext::Query(Box::new(q)))
    }
}

/// The document a value holds, if it is one (`$value instanceof Document`).
pub fn as_document(value: &Value) -> Option<&Document> {
    match value {
        Value::Ext(Ext::Document(d)) => Some(d),
        _ => None,
    }
}

/// The document a value holds, mutably.
pub fn as_document_mut(value: &mut Value) -> Option<&mut Document> {
    match value {
        Value::Ext(Ext::Document(d)) => Some(d),
        _ => None,
    }
}

/// The operator a value holds, if it is one (`$value instanceof Operator`).
pub fn as_operator(value: &Value) -> Option<&Operator> {
    match value {
        Value::Ext(Ext::Operator(o)) => Some(o),
        _ => None,
    }
}

/// The query a value holds, if it is one (`$value instanceof Query`).
pub fn as_query(value: &Value) -> Option<&Query> {
    match value {
        Value::Ext(Ext::Query(q)) => Some(q),
        _ => None,
    }
}

/// A string value (`Value::Str`) from Rust text.
pub fn string(s: impl Into<Str>) -> Value {
    Value::Str(s.into())
}

/// A list value from values.
pub fn list(items: impl IntoIterator<Item = Value>) -> Value {
    Value::Array(items.into_iter().collect())
}

/// The UTF-8 text of a string value; `None` for other values and for
/// strings that are not UTF-8.
pub fn as_str(value: &Value) -> Option<&str> {
    match value {
        Value::Str(s) => s.as_str(),
        _ => None,
    }
}

/// A plain PHP value (no objects of this library) as a database value.
pub fn from_plain(value: php_std::Value) -> Value {
    match value {
        php_std::Value::Null => Value::Null,
        php_std::Value::Bool(b) => Value::Bool(b),
        php_std::Value::Int(i) => Value::Int(i),
        php_std::Value::Float(f) => Value::Float(f),
        php_std::Value::Str(s) => Value::Str(s),
        php_std::Value::Array(a) => Value::Array(a.into_iter().map(|(k, v)| (k, from_plain(v))).collect()),
        php_std::Value::Object(a) => Value::Object(a.into_iter().map(|(k, v)| (k, from_plain(v))).collect()),
        php_std::Value::Ext(never) => match never {},
    }
}

/// A database value as a plain PHP value: documents become their
/// `getArrayCopy()`, queries and operators their `toArray()` (what a codec
/// such as the cache's writes for them).
pub fn to_plain(value: &Value) -> php_std::Value {
    fn array(a: &Array) -> php_std::Array {
        a.iter().map(|(k, v)| (k.to_owned(), to_plain(v))).collect()
    }
    match value {
        Value::Null => php_std::Value::Null,
        Value::Bool(b) => php_std::Value::Bool(*b),
        Value::Int(i) => php_std::Value::Int(*i),
        Value::Float(f) => php_std::Value::Float(*f),
        Value::Str(s) => php_std::Value::Str(s.clone()),
        Value::Array(a) => php_std::Value::Array(array(a)),
        Value::Object(a) => php_std::Value::Object(array(a)),
        Value::Ext(x) => php_std::Value::Array(array(&php_std::Extension::to_array(x))),
    }
}

/// The value as an integer (`zval_get_long()`, PHP's `(int)` cast).
pub fn to_long(value: &Value) -> i64 {
    match value {
        Value::Null | Value::Bool(false) => 0,
        Value::Bool(true) => 1,
        Value::Int(i) => *i,
        Value::Float(f) => php_std::format::dval_to_lval(*f),
        Value::Str(s) => php_std::format::str_to_int(s.as_bytes()),
        Value::Array(a) => i64::from(!a.is_empty()),
        Value::Object(_) | Value::Ext(_) => 1,
    }
}

/// The value as PHP's `(string)` cast would print it, lossily for bytes
/// that are not UTF-8 (identifiers and messages are text).
pub fn to_text(value: &Value) -> String {
    match value.to_php_string() {
        Ok(s) => String::from_utf8_lossy(s.as_bytes()).into_owned(),
        Err(_) => String::new(),
    }
}
