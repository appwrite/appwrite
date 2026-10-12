//! [`Value`]: the PHP values queries carry, and the objects among them.

use php_std::json::{self, Flags};
use php_std::{Extension, Str};

use crate::enums::NullsPosition;
use crate::query::Query;

/// A value a query or a statement carries: a PHP value (bytes, integers
/// distinct from floats, ordered arrays with integer and string keys,
/// `stdClass`), where objects are [`Object`]s.
pub type Value = php_std::Value<Object>;

/// A PHP array of [`Value`]s.
pub type Array = php_std::Array<Object>;

/// An object among a query's values.
#[derive(Debug, Clone, PartialEq)]
pub enum Object {
    /// A nested query (the children of `and`, `or`, `having`, joins).
    ///
    /// PHP: `Utopia\Query\Query`.
    Query(Box<Query>),
    /// The `NULLS FIRST` / `NULLS LAST` of an order query.
    ///
    /// PHP: `Utopia\Query\NullsPosition`.
    Nulls(NullsPosition),
    /// A row read through array access, such as a document used as a cursor:
    /// its class and its fields.
    ///
    /// PHP: an object implementing `ArrayAccess` (`ArrayObject`, `Utopia\Database\Document`).
    Row {
        /// The PHP class.
        class: String,
        /// The fields.
        fields: Array,
    },
}

impl Extension for Object {
    /// The PHP class.
    fn class(&self) -> &str {
        match self {
            Object::Query(_) => "Utopia\\Query\\Query",
            Object::Nulls(_) => NullsPosition::PHP_CLASS,
            Object::Row { class, .. } => class,
        }
    }

    /// What `json_encode` writes for the object: a query has no public
    /// properties, a row its fields.
    fn to_array(&self) -> Array {
        match self {
            Object::Row { fields, .. } => fields.clone(),
            _ => Array::default(),
        }
    }
}

impl From<Query> for Value {
    /// A nested query.
    fn from(query: Query) -> Self {
        Value::Ext(Object::Query(Box::new(query)))
    }
}

impl From<NullsPosition> for Value {
    /// The enum case.
    fn from(nulls: NullsPosition) -> Self {
        Value::Ext(Object::Nulls(nulls))
    }
}

/// A string value.
pub fn string(s: impl AsRef<[u8]>) -> Value {
    Value::Str(Str::copy_from(s.as_ref()))
}

/// A list of values.
pub fn list<V: Into<Value>>(items: impl IntoIterator<Item = V>) -> Array {
    Array::from_list(items.into_iter().map(Into::into).collect())
}

/// The value as PHP's `(string)` cast prints it (lossy for bytes that are not
/// UTF-8, which PHP would keep).
pub(crate) fn to_php_string(value: &Value) -> String {
    match value.to_php_string() {
        Ok(s) => String::from_utf8_lossy(s.as_bytes()).into_owned(),
        Err(_) => String::new(),
    }
}

/// `json_encode($value)` with `$flags`: enums encode as their value, as PHP's
/// backed enums do.
pub(crate) fn json_encode(value: &Value, flags: Flags) -> Result<String, json::Error> {
    json::encode(&enums_as_values(value), flags, json::DEFAULT_DEPTH)
}

/// Backed enum cases replaced by their values (what `json_encode` writes for them).
fn enums_as_values(value: &Value) -> Value {
    match value {
        Value::Ext(Object::Nulls(n)) => Value::from(n.as_str()),
        Value::Array(a) => Value::Array(map_array(a)),
        Value::Object(a) => Value::Object(map_array(a)),
        other => other.clone(),
    }
}

fn map_array(a: &Array) -> Array {
    let mut out = Array::with_capacity(a.len());
    for (k, v) in a.iter() {
        out.set(k.to_owned(), enums_as_values(v));
    }
    out
}

/// `$a === $b` where enum cases are identical to themselves (PHP's enum
/// cases are singletons) and other objects to nothing.
pub(crate) fn identical(a: &Value, b: &Value) -> bool {
    match (a, b) {
        (Value::Ext(Object::Nulls(x)), Value::Ext(Object::Nulls(y))) => x == y,
        (Value::Array(x), Value::Array(y)) => {
            x.len() == y.len() && x.iter().zip(y.iter()).all(|((ka, va), (kb, vb))| ka == kb && identical(va, vb))
        }
        _ => a.strict_eq(b),
    }
}

/// Something usable as one PHP value: integers, floats, booleans, strings,
/// arrays, queries, `None` for `null`.
pub trait IntoValue {
    /// The value.
    fn into_value(self) -> Value;
}

impl IntoValue for Value {
    fn into_value(self) -> Value {
        self
    }
}

impl IntoValue for &Value {
    fn into_value(self) -> Value {
        self.clone()
    }
}

macro_rules! into_value_int {
    ($($t:ty),*) => {$(
        impl IntoValue for $t {
            fn into_value(self) -> Value {
                Value::Int(self as i64)
            }
        }
    )*};
}

into_value_int!(i8, i16, i32, i64, u8, u16, u32, isize);

impl IntoValue for f64 {
    fn into_value(self) -> Value {
        Value::Float(self)
    }
}

impl IntoValue for f32 {
    fn into_value(self) -> Value {
        Value::Float(self as f64)
    }
}

impl IntoValue for bool {
    fn into_value(self) -> Value {
        Value::Bool(self)
    }
}

impl IntoValue for &str {
    fn into_value(self) -> Value {
        string(self)
    }
}

impl IntoValue for String {
    fn into_value(self) -> Value {
        Value::Str(Str::from(self))
    }
}

impl IntoValue for &String {
    fn into_value(self) -> Value {
        string(self)
    }
}

impl IntoValue for Str {
    fn into_value(self) -> Value {
        Value::Str(self)
    }
}

impl IntoValue for Array {
    fn into_value(self) -> Value {
        Value::Array(self)
    }
}

impl IntoValue for Query {
    fn into_value(self) -> Value {
        Value::from(self)
    }
}

impl IntoValue for NullsPosition {
    fn into_value(self) -> Value {
        Value::from(self)
    }
}

impl<T: IntoValue> IntoValue for Option<T> {
    fn into_value(self) -> Value {
        self.map(IntoValue::into_value).unwrap_or(Value::Null)
    }
}

/// Something usable as a PHP array of values: a Rust array, a `Vec` or a
/// slice of [`IntoValue`]s (a list), or an [`Array`] (keys kept).
pub trait IntoValues {
    /// The array.
    fn into_values(self) -> Array;
}

impl IntoValues for Array {
    fn into_values(self) -> Array {
        self
    }
}

impl<T: IntoValue, const N: usize> IntoValues for [T; N] {
    fn into_values(self) -> Array {
        Array::from_list(self.into_iter().map(IntoValue::into_value).collect())
    }
}

impl<T: IntoValue> IntoValues for Vec<T> {
    fn into_values(self) -> Array {
        Array::from_list(self.into_iter().map(IntoValue::into_value).collect())
    }
}

impl<T: IntoValue + Clone> IntoValues for &[T] {
    fn into_values(self) -> Array {
        Array::from_list(self.iter().cloned().map(IntoValue::into_value).collect())
    }
}
