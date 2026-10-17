//! Shape checks on a decoded document: is this value an object, a list, a
//! string? Every failure is an [`Error::InvalidSpecification`] naming the
//! JSON Pointer location that was being read, so it can be traced back into
//! the source.
//!
//! PHP: `Utopia\OpenAPI\Parser\Value`, one function per static method.
//!
//! | PHP | Rust |
//! |---|---|
//! | `Value::object($value, $location)` | [`object`] |
//! | `Value::list($value, $location)` | [`list`] |
//! | `Value::stringList($value, $location)` | [`string_list`] |
//! | `Value::requiredString($data, $key, $location)` | [`required_string`] |
//! | `Value::optionalString($data, $key)` | [`optional_string`] |
//! | `Value::nullableInt($value, $location)` | [`nullable_int`] |
//! | `Value::nullableNumber($value, $location)` | [`nullable_number`] |
//! | `Value::extensions($data)` | [`extensions`] |

use std::borrow::Cow;
use std::fmt;

use php_std::types::KeyRef;
use php_std::types::numeric_key;
use php_std::value::Number;
use php_std::{Array, ArrayKey, Str, Value};

use crate::Error;

/// Where a value sits in the document, rendered only when an error names it.
///
/// Reading builds a location for every value it visits; keeping it as a
/// chain of borrowed segments means none is formatted unless reading fails.
#[derive(Clone, Copy)]
pub(crate) enum Loc<'a> {
    /// A location given as text (`#/info`, or a `$ref` being followed).
    Root(&'a str),
    /// `{parent}/{name}`.
    Name(&'a Loc<'a>, &'a str),
    /// `{parent}/{key}` for a key of the document.
    Key(&'a Loc<'a>, KeyRef<'a>),
    /// `{parent}/{index}`.
    Index(&'a Loc<'a>, usize),
}

impl<'a> Loc<'a> {
    /// `{self}/{name}`.
    pub(crate) fn name(&'a self, name: &'a str) -> Loc<'a> {
        Loc::Name(self, name)
    }

    /// `{self}/{key}`.
    pub(crate) fn key(&'a self, key: KeyRef<'a>) -> Loc<'a> {
        Loc::Key(self, key)
    }

    /// `{self}/{index}`.
    pub(crate) fn index(&'a self, index: usize) -> Loc<'a> {
        Loc::Index(self, index)
    }
}

impl fmt::Display for Loc<'_> {
    /// The location as PHP interpolates it (`#/paths/~1pets/get`).
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        match self {
            Loc::Root(text) => f.write_str(text),
            Loc::Name(parent, name) => write!(f, "{parent}/{name}"),
            Loc::Key(parent, KeyRef::Int(i)) => write!(f, "{parent}/{i}"),
            Loc::Key(parent, KeyRef::Str(s)) => write!(f, "{parent}/{}", String::from_utf8_lossy(s)),
            Loc::Index(parent, i) => write!(f, "{parent}/{i}"),
        }
    }
}

fn invalid(message: String) -> Error {
    Error::InvalidSpecification(message)
}

/// The text of a key, as PHP interpolates or casts it (`(string) $key`).
pub(crate) fn key_text(key: KeyRef<'_>) -> String {
    match key {
        KeyRef::Int(i) => i.to_string(),
        KeyRef::Str(s) => String::from_utf8_lossy(s).into_owned(),
    }
}

/// The text of a string value.
pub(crate) fn text(s: &Str) -> String {
    String::from_utf8_lossy(s).into_owned()
}

/// `(array) $object` of a `stdClass`: property names that are canonical
/// integers become integer keys; anything else is borrowed as it is.
fn cast_object(properties: &Array) -> Cow<'_, Array> {
    let numeric = |k: KeyRef<'_>| matches!(k, KeyRef::Str(s) if numeric_key(s).is_some());
    if !properties.keys().any(numeric) {
        return Cow::Borrowed(properties);
    }
    Cow::Owned(
        properties
            .iter()
            .map(|(k, v)| {
                let key = match k {
                    KeyRef::Int(i) => ArrayKey::Int(i),
                    KeyRef::Str(s) => ArrayKey::normalize(Str::copy_from(s)),
                };
                (key, v.clone())
            })
            .collect(),
    )
}

pub(crate) fn object_at<'v>(value: &'v Value, location: &Loc<'_>) -> Result<Cow<'v, Array>, Error> {
    match value {
        Value::Object(properties) => Ok(cast_object(properties)),
        Value::Array(a) if a.is_empty() || !a.is_list() => Ok(Cow::Borrowed(a)),
        _ => Err(invalid(format!("Expected an object at {location}"))),
    }
}

pub(crate) fn list_at<'v>(value: &'v Value, location: &Loc<'_>) -> Result<&'v Array, Error> {
    match value {
        Value::Array(a) if a.is_list() => Ok(a),
        _ => Err(invalid(format!("Expected a list at {location}"))),
    }
}

pub(crate) fn required_at<'v>(data: &'v Array, key: &str, location: &Loc<'_>) -> Result<&'v Str, Error> {
    match data.get_str(key) {
        Some(Value::Str(s)) => Ok(s),
        _ => Err(invalid(format!("Expected string {location}/{key}"))),
    }
}

pub(crate) fn int_at(value: Option<&Value>, location: &Loc<'_>) -> Result<Option<i64>, Error> {
    match value {
        None | Some(Value::Null) => Ok(None),
        Some(Value::Int(i)) => Ok(Some(*i)),
        Some(_) => Err(invalid(format!("Expected integer at {location}"))),
    }
}

pub(crate) fn number_at(value: Option<&Value>, location: &Loc<'_>) -> Result<Option<Number>, Error> {
    match value {
        None | Some(Value::Null) => Ok(None),
        Some(Value::Int(i)) => Ok(Some(Number::Int(*i))),
        Some(Value::Float(f)) => Ok(Some(Number::Float(*f))),
        Some(_) => Err(invalid(format!("Expected number at {location}"))),
    }
}

/// The value as an object: a non-list array (an empty array is both an
/// object and a list), or a `stdClass` cast to an array.
///
/// PHP: `Value::object($value, $location)`.
///
/// ```
/// use utopia_openapi::shape;
/// use utopia_openapi::{Array, Value};
///
/// let empty = Value::Array(Array::new());
/// assert!(shape::object(&empty, "#/x").unwrap().is_empty());
///
/// let list = Value::Array(Array::from_list(vec![Value::Int(1)]));
/// let error = shape::object(&list, "#/paths").unwrap_err();
/// assert_eq!(error.to_string(), "Expected an object at #/paths");
/// ```
///
/// ```php
/// use Utopia\OpenAPI\Parser\Value;
///
/// var_dump(Value::object([], '#/x'));   // array(0) {}
/// try {
///     Value::object([1], '#/paths');
/// } catch (\Utopia\OpenAPI\Exception\InvalidSpecification $e) {
///     echo $e->getMessage(), "\n";       // Expected an object at #/paths
/// }
/// ```
pub fn object<'v>(value: &'v Value, location: &str) -> Result<Cow<'v, Array>, Error> {
    object_at(value, &Loc::Root(location))
}

/// The value as a list: an array whose keys are `0, 1, 2, ...`.
///
/// PHP: `Value::list($value, $location)`.
pub fn list<'v>(value: &'v Value, location: &str) -> Result<&'v Array, Error> {
    list_at(value, &Loc::Root(location))
}

/// The value as a list of strings; the error names the first item that is not.
///
/// PHP: `Value::stringList($value, $location)`.
pub fn string_list<'v>(value: &'v Value, location: &str) -> Result<&'v Array, Error> {
    let values = list(value, location)?;
    for (index, item) in values.values().enumerate() {
        if !matches!(item, Value::Str(_)) {
            return Err(invalid(format!("Expected string at {location}/{index}")));
        }
    }
    Ok(values)
}

/// The string at `key`, which must be present.
///
/// PHP: `Value::requiredString($data, $key, $location)`.
///
/// ```
/// use utopia_openapi::{Array, Value, shape};
///
/// let error = shape::required_string(&Array::new(), "title", "#/info").unwrap_err();
/// assert_eq!(error.to_string(), "Expected string #/info/title");
/// ```
///
/// ```php
/// use Utopia\OpenAPI\Parser\Value;
///
/// try {
///     Value::requiredString([], 'title', '#/info');
/// } catch (\Utopia\OpenAPI\Exception\InvalidSpecification $e) {
///     echo $e->getMessage(), "\n"; // Expected string #/info/title
/// }
/// ```
pub fn required_string<'v>(data: &'v Array, key: &str, location: &str) -> Result<&'v Str, Error> {
    required_at(data, key, &Loc::Root(location))
}

/// The string at `key`, or `None` when it is missing or `null`.
///
/// PHP: `Value::optionalString($data, $key)`; its error names the key, not a
/// location.
pub fn optional_string<'v>(data: &'v Array, key: &str) -> Result<Option<&'v Str>, Error> {
    match data.get_str(key) {
        None | Some(Value::Null) => Ok(None),
        Some(Value::Str(s)) => Ok(Some(s)),
        Some(_) => Err(invalid(format!("Expected '{key}' to be a string"))),
    }
}

/// An integer, or `None` for `null`; a float is an error.
///
/// PHP: `Value::nullableInt($value, $location)`.
pub fn nullable_int(value: &Value, location: &str) -> Result<Option<i64>, Error> {
    int_at(Some(value), &Loc::Root(location))
}

/// An integer or a float, or `None` for `null`.
///
/// PHP: `Value::nullableNumber($value, $location)`.
pub fn nullable_number(value: &Value, location: &str) -> Result<Option<Number>, Error> {
    number_at(Some(value), &Loc::Root(location))
}

/// The entries whose key starts with `x-` (in any case), in order. Integer
/// keys (and numeric strings, which PHP stores as integers) are never
/// extensions.
///
/// PHP: `Value::extensions($data)`.
///
/// ```
/// use utopia_openapi::{Array, Value, shape};
///
/// let mut data = Array::new();
/// data.set("title".into(), Value::from("Pets"));
/// data.set("X-Trace".into(), Value::Bool(true));
/// let extensions = shape::extensions(&data);
/// assert_eq!(extensions.len(), 1);
/// assert_eq!(extensions.get_str("X-Trace"), Some(&Value::Bool(true)));
/// ```
///
/// ```php
/// use Utopia\OpenAPI\Parser\Value;
///
/// var_dump(Value::extensions(['title' => 'Pets', 'X-Trace' => true])); // ['X-Trace' => true]
/// ```
pub fn extensions(data: &Array) -> Array {
    let is_extension =
        |k: KeyRef<'_>| matches!(k, KeyRef::Str(s) if s.len() >= 2 && s[0].eq_ignore_ascii_case(&b'x') && s[1] == b'-');
    if !data.keys().any(is_extension) {
        return Array::new();
    }
    data.iter().filter(|(k, _)| is_extension(*k)).map(|(k, v)| (k.to_owned(), v.clone())).collect()
}
