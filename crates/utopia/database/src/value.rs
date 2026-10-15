//! The values documents carry, and how they meet the values queries carry.
//!
//! A document attribute holds a PHP value: `null`, a bool, an integer, a
//! float, a byte string, an array (a list or an ordered map), a `stdClass`,
//! or one of the library's own objects: a nested [`Document`] (relationships)
//! or an [`Operator`] (an atomic update such as an increment). [`Value`] is
//! [`php_std::Value`] with those objects as its extension ([`Ext`]), so type
//! juggling, comparisons and the JSON codec behave as the engine does.
//!
//! # Document values and query values
//!
//! A [`Query`](crate::Query) is a `utopia_query::Query` (as PHP's
//! `Utopia\Database\Query` extends `Utopia\Query\Query`), so its values are
//! [`utopia_query::Value`]s: `php_std::Value<utopia_query::Object>`, whose
//! objects are nested queries, `NullsPosition` cases and rows read through
//! array access. The builders of `utopia-query` take those queries as they
//! are: handing the database's queries to a builder moves them, it never
//! converts or copies their values.
//!
//! The two value types differ only in their objects, so values cross over
//! where a query is built from a document or a document from a query: a
//! cursor ([`to_query`] turns a [`Document`] into a
//! [`utopia_query::Object::Row`] of class `Utopia\Database\Document`, and
//! [`from_query`] turns such a row back into a document), and filter values
//! taken from documents (relationship ids, sequences). Strings share their
//! bytes and arrays are rebuilt only when they hold values, so scalars cost
//! nothing to cross.
//!
//! PHP: values are plain PHP values; `$value instanceof Document` and
//! `instanceof Operator` are [`Value::Ext`] with [`Ext::Document`] and
//! [`Ext::Operator`].

use crate::document::Document;
use crate::operator::Operator;

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
    /// A query held as a value (`Utopia\Database\Query`, or
    /// `Utopia\Query\Query`), as a query value converted with
    /// [`from_query`] carries its nested queries.
    Query(Box<utopia_query::Query>),
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
            Ext::Document(d) => d.array_copy(),
            Ext::Operator(o) => o.to_array(),
            Ext::Bson(b) => b.to_array(),
            Ext::Query(q) => from_query_array(&q.to_array()),
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

impl From<crate::Query> for Value {
    fn from(q: crate::Query) -> Self {
        Value::Ext(Ext::Query(Box::new(q.into_inner())))
    }
}

/// A document value as a query value: scalars as they are (strings share
/// their bytes), arrays element by element, a [`Document`] as a row of its
/// class read through array access ([`utopia_query::Object::Row`], as
/// `utopia-php/query` reads a cursor document), an [`Operator`] or a BSON
/// value as a row of its class and properties, a query as itself.
///
/// ```
/// use utopia_database::value::{self, Value};
/// use utopia_database::Document;
///
/// let cursor = Document::from_pairs([("$id", value::string("a"))]).unwrap();
/// let row = value::to_query(&Value::from(cursor.clone()));
/// assert!(matches!(&row, utopia_query::Value::Ext(utopia_query::Object::Row { class, .. }) if class == "Utopia\\Database\\Document"));
/// assert_eq!(value::from_query(&row), Value::from(cursor));
/// ```
pub fn to_query(value: &Value) -> utopia_query::Value {
    use utopia_query::{Object, Value as Q};
    match value {
        Value::Null => Q::Null,
        Value::Bool(b) => Q::Bool(*b),
        Value::Int(i) => Q::Int(*i),
        Value::Float(f) => Q::Float(*f),
        Value::Str(s) => Q::Str(s.clone()),
        Value::Array(a) => Q::Array(to_query_array(a)),
        Value::Object(a) => Q::Object(to_query_array(a)),
        Value::Ext(Ext::Query(q)) => Q::Ext(Object::Query(q.clone())),
        Value::Ext(Ext::Document(d)) => {
            Q::Ext(Object::Row { class: d.class().to_owned(), fields: to_query_array(d.as_array()) })
        }
        Value::Ext(x) => Q::Ext(Object::Row {
            class: php_std::Extension::class(x).to_owned(),
            fields: to_query_array(&php_std::Extension::to_array(x)),
        }),
    }
}

/// [`to_query`] over an array, keys kept.
pub fn to_query_array(array: &Array) -> utopia_query::Array {
    let mut out = utopia_query::Array::with_capacity(array.len());
    for (k, v) in array.iter() {
        out.set(k.to_owned(), to_query(v));
    }
    out
}

/// A query value as a document value: the inverse of [`to_query`]. A row of
/// class `Utopia\Database\Document` (or of a document class registered with
/// a database) becomes a [`Document`] of that class; any other row its
/// fields as an array; a nested query [`Ext::Query`]; a `NullsPosition` case
/// its backing value.
pub fn from_query(value: &utopia_query::Value) -> Value {
    use utopia_query::{Object, Value as Q};
    match value {
        Q::Null => Value::Null,
        Q::Bool(b) => Value::Bool(*b),
        Q::Int(i) => Value::Int(*i),
        Q::Float(f) => Value::Float(*f),
        Q::Str(s) => Value::Str(s.clone()),
        Q::Array(a) => Value::Array(from_query_array(a)),
        Q::Object(a) => Value::Object(from_query_array(a)),
        Q::Ext(Object::Query(q)) => Value::Ext(Ext::Query(q.clone())),
        Q::Ext(Object::Nulls(n)) => Value::Str(Str::from_static(n.as_str())),
        Q::Ext(Object::Row { class, fields }) => {
            let fields = from_query_array(fields);
            if class == "Utopia\\Database\\Operator" || class.starts_with("MongoDB\\") {
                Value::Array(fields)
            } else {
                Value::from(Document::from_array_unchecked(fields).with_class(class.as_str()))
            }
        }
    }
}

/// [`from_query`] over an array, keys kept.
pub fn from_query_array(array: &utopia_query::Array) -> Array {
    let mut out = Array::with_capacity(array.len());
    for (k, v) in array.iter() {
        out.set(k.to_owned(), from_query(v));
    }
    out
}

/// A value as `json_encode` sees it: a document is an object of its
/// attributes (an `ArrayObject`), a query or operator an object without
/// public properties (`{}`), a BSON value its extended JSON.
pub(crate) fn json_value(value: &Value) -> php_std::Value {
    fn array(a: &Array) -> php_std::Array {
        a.iter().map(|(k, v)| (k.to_owned(), json_value(v))).collect()
    }
    match value {
        Value::Null => php_std::Value::Null,
        Value::Bool(b) => php_std::Value::Bool(*b),
        Value::Int(i) => php_std::Value::Int(*i),
        Value::Float(f) => php_std::Value::Float(*f),
        Value::Str(s) => php_std::Value::Str(s.clone()),
        Value::Array(a) => php_std::Value::Array(array(a)),
        Value::Object(a) => php_std::Value::Object(array(a)),
        Value::Ext(Ext::Document(d)) => php_std::Value::Object(array(d.as_array())),
        Value::Ext(Ext::Query(_) | Ext::Operator(_)) => php_std::Value::Object(php_std::Array::new()),
        Value::Ext(Ext::Bson(b)) => {
            let text = |s: String| php_std::Value::Str(s.into());
            let object = |pairs: Vec<(&str, php_std::Value)>| {
                php_std::Value::Object(pairs.into_iter().map(|(k, v)| (php_std::ArrayKey::Str(k.into()), v)).collect())
            };
            match &**b {
                BsonValue::UtcDateTime(ms) => {
                    object(vec![("$date", object(vec![("$numberLong", text(ms.to_string()))]))])
                }
                BsonValue::ObjectId(bytes) => object(vec![("$oid", text(hex::encode(bytes)))]),
                BsonValue::Regex { pattern, flags } => {
                    object(vec![("$regex", text(pattern.clone())), ("$options", text(flags.clone()))])
                }
            }
        }
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
pub fn as_query(value: &Value) -> Option<&utopia_query::Query> {
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
