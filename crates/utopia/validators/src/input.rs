//! The values validators read: a request value or an exact value, borrowed,
//! and a one-level view of either.

use std::borrow::Cow;

use php_std::Value;
use php_std::filter;
use php_std::format::Arg;
use php_std::json;
use php_std::types::{Iter, KeyRef, numeric_key};
use php_std::value::{self, Number};

use crate::Error;

/// A value to validate, borrowed: a request value or an exact value.
///
/// PHP: a request value is decoded like `Utopia\Http\Request` decodes it; an
/// exact value is any PHP value ([`Value`]).
#[derive(Debug, Clone, Copy)]
pub enum Input<'a> {
    /// A request value, read as a decoded request: a non-empty object is a map
    /// (an array with string keys), numeric string keys are integer keys, and
    /// integers beyond `i64` are floats.
    Json(&'a serde_json::Value),
    /// An exact value, which can also hold binary strings, non-finite floats and
    /// objects with properties.
    ///
    /// PHP: any PHP value, `stdClass` objects included.
    Value(&'a Value),
}

impl<'a> From<&'a serde_json::Value> for Input<'a> {
    /// Borrows a request value.
    fn from(value: &'a serde_json::Value) -> Self {
        Input::Json(value)
    }
}

impl<'a> From<&'a Value> for Input<'a> {
    /// Borrows an exact value.
    fn from(value: &'a Value) -> Self {
        Input::Value(value)
    }
}

/// One level of an [`Input`]: its type, with its scalar value or its entries.
///
/// PHP: the types `gettype()` reports.
pub enum View<'a> {
    /// No value (`null`).
    Null,
    /// A boolean.
    Bool(bool),
    /// An integer.
    Int(i64),
    /// A float.
    Float(f64),
    /// A string: bytes, not necessarily UTF-8.
    Str(&'a [u8]),
    /// An array (a list or a map), with its entries in order.
    Array(Entries<'a>),
    /// An object, with its properties in order. An empty request object is one.
    ///
    /// PHP: a `stdClass` instance.
    Object(Entries<'a>),
}

/// The entries of an array or the properties of an object.
pub enum Entries<'a> {
    /// A request list's elements.
    List(std::iter::Enumerate<std::slice::Iter<'a, serde_json::Value>>),
    /// A request object's members (numeric keys read as integers).
    Map(serde_json::map::Iter<'a>),
    /// An exact value's entries.
    Value(Iter<'a, php_std::Never>),
}

impl<'a> Iterator for Entries<'a> {
    type Item = (KeyRef<'a>, Input<'a>);

    /// The next key and value.
    fn next(&mut self) -> Option<Self::Item> {
        match self {
            Entries::List(it) => it.next().map(|(i, v)| (KeyRef::Int(i as i64), Input::Json(v))),
            Entries::Map(it) => it.next().map(|(k, v)| (KeyRef::normalize(k.as_bytes()), Input::Json(v))),
            Entries::Value(it) => it.next().map(|(k, v)| (k, Input::Value(v))),
        }
    }

    /// The exact number of entries left.
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
    /// One level of the value: its type, with its scalar value or its entries.
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

    /// The value as an [`Arg`], the form `php-std`'s conversions take.
    ///
    /// PHP: the value as PHP's conversion functions see it.
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

    /// Whether the value is null.
    ///
    /// PHP: `is_null()`.
    pub fn is_null(self) -> bool {
        matches!(self.view(), View::Null)
    }

    /// The bytes of a string value; `None` for any other type.
    pub fn as_str(self) -> Option<&'a [u8]> {
        match self.view() {
            View::Str(s) => Some(s),
            _ => None,
        }
    }

    /// Whether the value is an array (a list or a map, not an object).
    ///
    /// PHP: `is_array()`.
    pub fn is_array(self) -> bool {
        matches!(self.view(), View::Array(_))
    }

    /// Whether the value is an object.
    ///
    /// PHP: `$value instanceof \stdClass`.
    pub fn is_object(self) -> bool {
        matches!(self.view(), View::Object(_))
    }

    /// Whether the value is a list: an array whose keys are 0, 1, 2, ... in order;
    /// `false` for anything else.
    ///
    /// PHP: `array_is_list()`.
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

    /// The number a value holds or spells: an integer, a float, or the number in a
    /// numeric string; `None` otherwise.
    ///
    /// PHP: `is_numeric($value) ? $value + 0 : null`.
    pub fn number(self) -> Option<Number> {
        match self.view() {
            View::Int(i) => Some(Number::Int(i)),
            View::Float(f) => Some(Number::Float(f)),
            // Numeric strings are ASCII: a string that is not UTF-8 is not numeric.
            View::Str(s) => std::str::from_utf8(s).ok().and_then(value::numeric_str),
            _ => None,
        }
    }

    /// Whether the value counts as true: anything but `null`, `false`, `0`,
    /// `0.0`, `""`, `"0"` and an empty array.
    ///
    /// PHP: `(bool) $value`.
    pub fn truthy(self) -> bool {
        self.arg().to_bool()
    }

    /// The value converted to a string: arrays are `"Array"`, and an object fails
    /// with [`Error::Engine`].
    ///
    /// PHP: `(string) $value`.
    pub fn to_bytes(self) -> Result<Cow<'a, [u8]>, Error> {
        match self.arg() {
            Arg::Str(s) => Ok(Cow::Borrowed(s)),
            other => Ok(Cow::Owned(other.to_bytes()?.into_owned())),
        }
    }

    /// The value encoded as JSON, or `None` when it cannot be encoded.
    ///
    /// PHP: `json_encode($value)`, with `None` where PHP returns `false`.
    pub fn json_encode(self) -> Option<String> {
        match self {
            Input::Json(v) => {
                json::encode(&php_std::Value::<php_std::Never>::from_json(v), json::Flags::NONE, json::DEFAULT_DEPTH)
                    .ok()
            }
            Input::Value(z) => json::encode(z, json::Flags::NONE, json::DEFAULT_DEPTH).ok(),
        }
    }

    /// The entry `key` of an array, or the property `key` of an object; `None`
    /// when it is missing or for any other value.
    ///
    /// PHP: `$value[$key]`, or the property as `get_object_vars()` reads it.
    pub fn get(self, key: &str) -> Option<Input<'a>> {
        let wanted = KeyRef::normalize(key.as_bytes());
        match self.view() {
            View::Array(mut e) => e.find(|(k, _)| *k == wanted).map(|(_, v)| v),
            // Property names are strings; get_object_vars() turns numeric ones into integer keys.
            View::Object(mut e) => e.find(|(k, _)| matches!(k, KeyRef::Str(s) if *s == key.as_bytes())).map(|(_, v)| v),
            _ => None,
        }
    }

    /// The value as a [`filter::Value`], the form `php-std`'s filters take.
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
