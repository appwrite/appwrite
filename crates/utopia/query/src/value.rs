//! [`Value`]: the PHP values queries carry, and the objects among them.

use php_std::json::{self, Flags};
use php_std::{ArrayKey, Extension, Str};

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

/// The value as PHP's string concatenation (`'x' . $value`) writes it: arrays
/// as `"Array"` (PHP warns), objects fail as PHP's `Error` does.
pub(crate) fn concat(value: &Value) -> Result<std::borrow::Cow<'_, str>, crate::Error> {
    use std::borrow::Cow;
    match value {
        Value::Str(s) => Ok(String::from_utf8_lossy(s.as_bytes())),
        Value::Object(_) | Value::Ext(_) => Err(crate::Error::Engine(format!(
            "Object of class {} could not be converted to string",
            value.debug_type()
        ))),
        other => Ok(Cow::Owned(to_php_string(other))),
    }
}

/// The key `$array[$value] = ...` writes: numeric strings as integers,
/// `null` as `""`, booleans as `0` / `1`, floats truncated (PHP deprecates
/// it); arrays and objects fail with PHP's `TypeError`.
pub(crate) fn array_key(value: &Value) -> Result<ArrayKey, crate::Error> {
    Ok(match value {
        Value::Str(s) => ArrayKey::normalize(s.clone()),
        Value::Int(i) => ArrayKey::Int(*i),
        Value::Float(f) => ArrayKey::Int(php_std::format::dval_to_lval(*f)),
        Value::Bool(b) => ArrayKey::Int(i64::from(*b)),
        Value::Null => ArrayKey::Str(Str::EMPTY),
        other => {
            return Err(crate::Error::Type(format!("Cannot access offset of type {} on array", value_name(other))));
        }
    })
}

/// How PHP names a value's type in a `TypeError`: `true` / `false` for
/// booleans, the class for objects, else the type (`null`, `int`, `array`).
///
/// PHP: `zend_zval_value_name()`.
pub(crate) fn value_name(value: &Value) -> &str {
    match value {
        Value::Bool(true) => "true",
        Value::Bool(false) => "false",
        other => other.debug_type(),
    }
}

/// [`concat`] as bytes (PHP strings are bytes).
pub(crate) fn concat_bytes(value: &Value) -> Result<std::borrow::Cow<'_, [u8]>, crate::Error> {
    use std::borrow::Cow;
    match value {
        Value::Str(s) => Ok(Cow::Borrowed(s.as_bytes())),
        other => Ok(Cow::Owned(concat(other)?.into_owned().into_bytes())),
    }
}

/// Where the library's PHP sources live in the image Appwrite runs: PHP's
/// type errors name the file and line of the call.
const PHP_SOURCES: &str = "/usr/src/code/packages/query/src/";

/// A typed PHP parameter that a query value, of whatever type the client
/// sent, is passed to: converts the value as PHP's coercive typing mode does
/// ([`Param::string`], [`Param::bool`], [`Param::array_or_string`]) or checks
/// it ([`Param::array`]), failing with PHP's `TypeError`.
///
/// A library function's `string` parameter rejects `null`, arrays and
/// objects; an internal function's (`str_ends_with()`) takes `null` as `""`
/// (PHP deprecates it). Integers, floats and booleans become their
/// `(string)` form for both.
///
/// PHP: the engine's parameter type check (`zend_verify_arg_type`).
#[derive(Debug, Clone, Copy)]
pub(crate) struct Param<'f> {
    function: &'f str,
    position: u8,
    name: &'static str,
    callee: Callee,
}

#[derive(Debug, Clone, Copy)]
enum Callee {
    /// A library method, called from `file` (relative to the sources) at `line`.
    Method { file: &'static str, line: u32 },
    /// A library closure, called by an internal function (`array_map`): PHP
    /// names no call site.
    Closure,
    /// An internal PHP function.
    Internal,
}

impl<'f> Param<'f> {
    /// Parameter `position` (`$name`) of the library method `function`
    /// (`Utopia\Query\Builder::resolveAndWrap`), called from `file` at `line`.
    pub(crate) const fn method(
        function: &'f str,
        position: u8,
        name: &'static str,
        file: &'static str,
        line: u32,
    ) -> Self {
        Param { function, position, name, callee: Callee::Method { file, line } }
    }

    /// Parameter `position` (`$name`) of a library closure, named as PHP names
    /// it (`Utopia\Query\Builder::{closure:Utopia\Query\Builder::compileSelect():1362}`).
    pub(crate) const fn closure(function: &'f str, position: u8, name: &'static str) -> Self {
        Param { function, position, name, callee: Callee::Closure }
    }

    /// Parameter `position` (`$name`) of the internal PHP function `function`.
    pub(crate) const fn internal(function: &'f str, position: u8, name: &'static str) -> Self {
        Param { function, position, name, callee: Callee::Internal }
    }

    /// The string PHP passes for `value` to a `string` parameter, or its `TypeError`.
    pub(crate) fn string<'a>(&self, value: &'a Value) -> Result<std::borrow::Cow<'a, str>, crate::Error> {
        use std::borrow::Cow;
        Ok(match value {
            Value::Str(s) => String::from_utf8_lossy(s.as_bytes()),
            Value::Null if matches!(self.callee, Callee::Internal) => Cow::Borrowed(""),
            Value::Int(_) | Value::Float(_) | Value::Bool(_) => Cow::Owned(to_php_string(value)),
            other => return Err(self.type_error("string", other)),
        })
    }

    /// [`Param::string`] as bytes (PHP strings are bytes).
    pub(crate) fn bytes<'a>(&self, value: &'a Value) -> Result<std::borrow::Cow<'a, [u8]>, crate::Error> {
        use std::borrow::Cow;
        match value {
            Value::Str(s) => Ok(Cow::Borrowed(s.as_bytes())),
            other => Ok(Cow::Owned(self.string(other)?.into_owned().into_bytes())),
        }
    }

    /// The array `value` is, for an `array` parameter, or PHP's `TypeError`.
    pub(crate) fn array<'a>(&self, value: &'a Value) -> Result<&'a Array, crate::Error> {
        match value {
            Value::Array(a) => Ok(a),
            other => Err(self.type_error("array", other)),
        }
    }

    /// The boolean PHP passes for `value` to a `bool` parameter (scalars by
    /// their truthiness), or its `TypeError`.
    pub(crate) fn bool(&self, value: &Value) -> Result<bool, crate::Error> {
        match value {
            Value::Bool(_) | Value::Int(_) | Value::Float(_) | Value::Str(_) => Ok(value.truthy()),
            Value::Null if matches!(self.callee, Callee::Internal) => Ok(false),
            other => Err(self.type_error("bool", other)),
        }
    }

    /// What PHP passes for `value` to a `string|array` parameter: arrays and
    /// strings as they are, other scalars as strings; or its `TypeError`.
    pub(crate) fn array_or_string(&self, value: &Value) -> Result<Value, crate::Error> {
        match value {
            Value::Array(_) | Value::Str(_) => Ok(value.clone()),
            Value::Int(_) | Value::Float(_) | Value::Bool(_) => Ok(string(to_php_string(value))),
            other => Err(self.type_error("array|string", other)),
        }
    }

    /// PHP's `TypeError` for passing `value` to a parameter of type `expected`.
    pub(crate) fn type_error(&self, expected: &str, value: &Value) -> crate::Error {
        let mut message = format!(
            "{}(): Argument #{} (${}) must be of type {expected}, {} given",
            self.function,
            self.position,
            self.name,
            value_name(value)
        );
        if let Callee::Method { file, line } = self.callee {
            message.push_str(&format!(", called in {PHP_SOURCES}{file} on line {line}"));
        }
        crate::Error::Type(message)
    }
}
