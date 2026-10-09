//! [`Input`]: a PHP value borrowed from either `php-std` model.

use std::borrow::Cow;

use php_std::filter;
use php_std::format::Arg;
use php_std::json;
use php_std::value::{self, Number};
use php_std::zval::{self, JsonEntries, KeyRef, PhpValue, Zval, ZvalEntries};

use crate::Error;

/// A PHP value to validate: a request value (`serde_json::Value`, decoded
/// like `Utopia\Http\Request` decodes it) or an exact PHP value ([`Zval`]).
#[derive(Debug, Clone, Copy)]
pub enum Input<'a> {
    Json(&'a serde_json::Value),
    Zval(&'a Zval),
}

impl<'a> From<&'a serde_json::Value> for Input<'a> {
    fn from(value: &'a serde_json::Value) -> Self {
        Input::Json(value)
    }
}

impl<'a> From<&'a Zval> for Input<'a> {
    fn from(value: &'a Zval) -> Self {
        Input::Zval(value)
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
    Json(JsonEntries<'a>),
    Zval(ZvalEntries<'a>),
}

impl<'a> Iterator for Entries<'a> {
    type Item = (KeyRef<'a>, Input<'a>);

    fn next(&mut self) -> Option<Self::Item> {
        match self {
            Entries::Json(it) => it.next().map(|(k, v)| (k, Input::Json(v))),
            Entries::Zval(it) => it.next().map(|(k, v)| (k, Input::Zval(v))),
        }
    }

    fn size_hint(&self) -> (usize, Option<usize>) {
        match self {
            Entries::Json(it) => it.size_hint(),
            Entries::Zval(it) => it.size_hint(),
        }
    }
}

impl ExactSizeIterator for Entries<'_> {}

impl<'a> Input<'a> {
    pub fn view(self) -> View<'a> {
        match self {
            Input::Json(v) => match v.view() {
                zval::View::Null => View::Null,
                zval::View::Bool(b) => View::Bool(b),
                zval::View::Int(i) => View::Int(i),
                zval::View::Float(f) => View::Float(f),
                zval::View::Str(s) => View::Str(s),
                zval::View::Array(e) => View::Array(Entries::Json(e)),
                zval::View::Object(e) => View::Object(Entries::Json(e)),
            },
            Input::Zval(z) => match z.view() {
                zval::View::Null => View::Null,
                zval::View::Bool(b) => View::Bool(b),
                zval::View::Int(i) => View::Int(i),
                zval::View::Float(f) => View::Float(f),
                zval::View::Str(s) => View::Str(s),
                zval::View::Array(e) => View::Array(Entries::Zval(e)),
                zval::View::Object(e) => View::Object(Entries::Zval(e)),
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
            Input::Json(v) => v.is_list(),
            Input::Zval(z) => z.is_list(),
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
            Input::Json(v) => json::encode(v, json::Flags::NONE, json::DEFAULT_DEPTH).ok(),
            Input::Zval(z) => json::encode(z, json::Flags::NONE, json::DEFAULT_DEPTH).ok(),
        }
    }

    /// `$value[$key]` of an array or the property `$key` of a `stdClass`
    /// (`get_object_vars()`): `None` when missing or for any other value.
    pub fn get(self, key: &str) -> Option<Input<'a>> {
        let wanted = match zval::numeric_key(key.as_bytes()) {
            Some(i) => KeyRef::Int(i),
            None => KeyRef::Str(key.as_bytes()),
        };
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
