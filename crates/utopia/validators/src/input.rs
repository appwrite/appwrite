//! [`Input`]: a PHP value borrowed from a request value or an exact PHP value.

use std::borrow::Cow;

use php_std::Value;
use php_std::filter;
use php_std::format::Arg;
use php_std::json;
use php_std::types::{Iter, KeyRef, numeric_key};
use php_std::value::{self, Number};

use crate::Error;

/// A PHP value to validate: a request value (`serde_json::Value`, decoded
/// like `Utopia\Http\Request` decodes it) or an exact PHP value ([`Value`]).
#[derive(Debug, Clone, Copy)]
pub enum Input<'a> {
    /// A request value: non-empty objects are associative arrays, numeric
    /// string keys are integer keys, integers beyond `i64` are floats.
    Json(&'a serde_json::Value),
    /// An exact PHP value: binary strings, non-finite floats, `stdClass`.
    Value(&'a Value),
}

impl<'a> From<&'a serde_json::Value> for Input<'a> {
    fn from(value: &'a serde_json::Value) -> Self {
        Input::Json(value)
    }
}

impl<'a> From<&'a Value> for Input<'a> {
    fn from(value: &'a Value) -> Self {
        Input::Value(value)
    }
}

/// One level of an [`Input`], as `gettype()` sees it.
pub enum View<'a> {
    Null,
    Bool(bool),
    Int(i64),
    Float(f64),
    /// A string: bytes, not necessarily UTF-8.
    Str(&'a [u8]),
    /// An array, with its entries in order.
    Array(Entries<'a>),
    /// A `stdClass` instance, with its properties in order.
    Object(Entries<'a>),
}

/// The entries of an array or the properties of an object.
pub enum Entries<'a> {
    /// A request list's elements.
    List(std::iter::Enumerate<std::slice::Iter<'a, serde_json::Value>>),
    /// A request object's members (numeric keys read as integers).
    Map(serde_json::map::Iter<'a>),
    /// A PHP value's entries.
    Value(Iter<'a, php_std::Never>),
}

impl<'a> Iterator for Entries<'a> {
    type Item = (KeyRef<'a>, Input<'a>);

    fn next(&mut self) -> Option<Self::Item> {
        match self {
            Entries::List(it) => it.next().map(|(i, v)| (KeyRef::Int(i as i64), Input::Json(v))),
            Entries::Map(it) => it.next().map(|(k, v)| (KeyRef::normalize(k.as_bytes()), Input::Json(v))),
            Entries::Value(it) => it.next().map(|(k, v)| (k, Input::Value(v))),
        }
    }

    fn size_hint(&self) -> (usize, Option<usize>) {
        match self {
            Entries::List(it) => it.size_hint(),
            Entries::Map(it) => it.size_hint(),
            Entries::Value(it) => it.size_hint(),
        }
    }
}

impl ExactSizeIterator for Entries<'_> {}

impl<'a> Input<'a> {
    /// One level of the value, as `gettype()` sees it.
    pub fn view(self) -> View<'a> {
        use serde_json::Value as Json;
        match self {
            Input::Json(v) => match v {
                Json::Null => View::Null,
                Json::Bool(b) => View::Bool(*b),
                Json::Number(n) => match n.as_i64() {
                    Some(i) => View::Int(i),
                    None => View::Float(n.as_f64().unwrap_or(0.0)),
                },
                Json::String(s) => View::Str(s.as_bytes()),
                Json::Array(a) => View::Array(Entries::List(a.iter().enumerate())),
                Json::Object(o) if o.is_empty() => View::Object(Entries::Map(o.iter())),
                Json::Object(o) => View::Array(Entries::Map(o.iter())),
            },
            Input::Value(z) => match z {
                Value::Null => View::Null,
                Value::Bool(b) => View::Bool(*b),
                Value::Int(i) => View::Int(*i),
                Value::Float(f) => View::Float(*f),
                Value::Str(s) => View::Str(s.as_bytes()),
                Value::Array(a) => View::Array(Entries::Value(a.iter())),
                Value::Object(a) => View::Object(Entries::Value(a.iter())),
                Value::Ext(never) => match *never {},
            },
        }
    }

    /// The value as PHP's conversion functions see it.
    pub fn arg(self) -> Arg<'a> {
        match self.view() {
            View::Null => Arg::Null,
            View::Bool(b) => Arg::Bool(b),
            View::Int(i) => Arg::Int(i),
            View::Float(f) => Arg::Float(f),
            View::Str(s) => Arg::Str(s),
            View::Array(e) => Arg::Array(e.len()),
            View::Object(_) => Arg::Object,
        }
    }

    /// `is_null()`.
    pub fn is_null(self) -> bool {
        matches!(self.view(), View::Null)
    }

    /// The string, when `is_string()`.
    pub fn as_str(self) -> Option<&'a [u8]> {
        match self.view() {
            View::Str(s) => Some(s),
            _ => None,
        }
    }

    /// `is_array()`.
    pub fn is_array(self) -> bool {
        matches!(self.view(), View::Array(_))
    }

    /// `$value instanceof \stdClass`.
    pub fn is_object(self) -> bool {
        matches!(self.view(), View::Object(_))
    }

    /// `array_is_list()` of an array (`false` for anything else).
    pub fn is_list(self) -> bool {
        match self {
            Input::Json(serde_json::Value::Array(_)) => true,
            Input::Json(serde_json::Value::Object(o)) => {
                !o.is_empty() && o.keys().enumerate().all(|(i, k)| numeric_key(k.as_bytes()) == Some(i as i64))
            }
            Input::Json(_) => false,
            Input::Value(Value::Array(a)) => a.is_list(),
            Input::Value(_) => false,
        }
    }

    /// `is_numeric($value) ? $value + 0 : null`.
    pub fn number(self) -> Option<Number> {
        match self.view() {
            View::Int(i) => Some(Number::Int(i)),
            View::Float(f) => Some(Number::Float(f)),
            // Numeric strings are ASCII: a string that is not UTF-8 is not numeric.
            View::Str(s) => std::str::from_utf8(s).ok().and_then(value::numeric_str),
            _ => None,
        }
    }

    /// `(bool) $value`.
    pub fn truthy(self) -> bool {
        self.arg().to_bool()
    }

    /// `(string) $value`: arrays are `"Array"`, a `stdClass` throws `Error`.
    pub fn to_bytes(self) -> Result<Cow<'a, [u8]>, Error> {
        match self.arg() {
            Arg::Str(s) => Ok(Cow::Borrowed(s)),
            other => Ok(Cow::Owned(other.to_bytes()?.into_owned())),
        }
    }

    /// `json_encode($value)`, `None` where PHP returns `false`.
    pub fn json_encode(self) -> Option<String> {
        match self {
            Input::Json(v) => {
                json::encode(&php_std::Value::<php_std::Never>::from_json(v), json::Flags::NONE, json::DEFAULT_DEPTH)
                    .ok()
            }
            Input::Value(z) => json::encode(z, json::Flags::NONE, json::DEFAULT_DEPTH).ok(),
        }
    }

    /// `$value[$key]` of an array or the property `$key` of a `stdClass`
    /// (`get_object_vars()`): `None` when missing or for any other value.
    pub fn get(self, key: &str) -> Option<Input<'a>> {
        let wanted = KeyRef::normalize(key.as_bytes());
        match self.view() {
            View::Array(mut e) => e.find(|(k, _)| *k == wanted).map(|(_, v)| v),
            // Property names are strings; get_object_vars() turns numeric ones into integer keys.
            View::Object(mut e) => e.find(|(k, _)| matches!(k, KeyRef::Str(s) if *s == key.as_bytes())).map(|(_, v)| v),
            _ => None,
        }
    }

    /// The value as `filter_var()` takes it.
    pub fn filter_value(self) -> filter::Value {
        match self.view() {
            View::Null => filter::Value::Null,
            View::Bool(b) => filter::Value::Bool(b),
            View::Int(i) => filter::Value::Int(i),
            View::Float(f) => filter::Value::Float(f),
            View::Str(s) => filter::Value::Str(s.to_vec()),
            View::Array(e) => filter::Value::Array(
                e.map(|(k, v)| {
                    let key = match k {
                        KeyRef::Int(i) => filter::Key::Int(i),
                        KeyRef::Str(s) => filter::Key::Str(s.to_vec()),
                    };
                    (key, v.filter_value())
                })
                .collect(),
            ),
            View::Object(_) => filter::Value::Object,
        }
    }
}

/// `filter_var($value, $filter, $flags) !== false`, and the filtered value.
pub(crate) fn filter(value: Input<'_>, filter: i64, flags: i64) -> Option<filter::Value> {
    match filter::filter_var(&value.filter_value(), filter, &filter::Options::Flags(flags)) {
        Ok(f) if f.value != filter::Value::Bool(false) => Some(f.value),
        _ => None,
    }
}

/// `filter_var($string, $filter, $flags) !== false` for a string.
pub(crate) fn filter_str(value: &[u8], filter: i64, flags: i64) -> bool {
    matches!(
        filter::filter_var(&filter::Value::Str(value.to_vec()), filter, &filter::Options::Flags(flags)),
        Ok(f) if f.value != filter::Value::Bool(false)
    )
}

/// PHP `$a == $b` for two strings.
pub(crate) fn loose_str_eq(a: &[u8], b: &[u8]) -> bool {
    a == b || value::loose_str_eq(a, b)
}
