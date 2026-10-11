//! Attribute values.

use php_std::Str;

/// The value of a span attribute: a scalar or null.
///
/// Strings are bytes, as attribute values copied from requests can be any
/// bytes.
///
/// PHP: `string|int|float|bool|null`.
///
/// ```
/// use utopia_span::Value;
///
/// assert_eq!(Value::from("GET").as_str(), Some("GET"));
/// assert_eq!(Value::from(200), Value::Int(200));
/// assert_eq!(Value::from(None::<i64>), Value::Null);
/// ```
#[derive(Debug, Clone, PartialEq, Default)]
pub enum Value {
    /// No value. Reading an attribute that is not set returns it too.
    #[default]
    Null,
    /// A boolean.
    Bool(bool),
    /// An integer.
    Int(i64),
    /// A float.
    Float(f64),
    /// A string, as bytes.
    Str(Str),
}

impl Value {
    /// The string, when the value is a UTF-8 string.
    pub fn as_str(&self) -> Option<&str> {
        match self {
            Value::Str(s) => s.as_str(),
            _ => None,
        }
    }

    /// The bytes, when the value is a string.
    pub fn as_bytes(&self) -> Option<&[u8]> {
        match self {
            Value::Str(s) => Some(s.as_bytes()),
            _ => None,
        }
    }

    /// The float, when the value is a float.
    pub fn as_float(&self) -> Option<f64> {
        match self {
            Value::Float(f) => Some(*f),
            _ => None,
        }
    }

    /// The integer, when the value is an integer.
    pub fn as_int(&self) -> Option<i64> {
        match self {
            Value::Int(i) => Some(*i),
            _ => None,
        }
    }

    /// The boolean, when the value is a boolean.
    pub fn as_bool(&self) -> Option<bool> {
        match self {
            Value::Bool(b) => Some(*b),
            _ => None,
        }
    }

    /// Whether the value is [`Value::Null`].
    pub fn is_null(&self) -> bool {
        matches!(self, Value::Null)
    }

    /// The value as PHP prints it: `(string) $value` (`true` is `1`, `false`
    /// and `null` are empty, floats have 14 significant digits).
    pub fn to_php_string(&self) -> Vec<u8> {
        match self {
            Value::Null | Value::Bool(false) => Vec::new(),
            Value::Bool(true) => b"1".to_vec(),
            Value::Int(i) => i.to_string().into_bytes(),
            Value::Float(f) => php_std::number::to_string(*f).into_bytes(),
            Value::Str(s) => s.as_bytes().to_vec(),
        }
    }

    /// The value as a float: `(float) $value`.
    pub fn to_number(&self) -> f64 {
        self.to_php().to_double()
    }

    /// The value in PHP's value model.
    pub fn to_php(&self) -> php_std::Value {
        match self {
            Value::Null => php_std::Value::Null,
            Value::Bool(b) => php_std::Value::Bool(*b),
            Value::Int(i) => php_std::Value::Int(*i),
            Value::Float(f) => php_std::Value::Float(*f),
            Value::Str(s) => php_std::Value::Str(s.clone()),
        }
    }
}

impl From<bool> for Value {
    fn from(b: bool) -> Self {
        Value::Bool(b)
    }
}

macro_rules! from_int {
    ($($t:ty),*) => {$(
        impl From<$t> for Value {
            fn from(i: $t) -> Self {
                Value::Int(i64::from(i))
            }
        }
    )*};
}
from_int!(i8, i16, i32, i64, u8, u16, u32);

impl From<f64> for Value {
    fn from(f: f64) -> Self {
        Value::Float(f)
    }
}

impl From<f32> for Value {
    fn from(f: f32) -> Self {
        Value::Float(f64::from(f))
    }
}

impl From<&str> for Value {
    fn from(s: &str) -> Self {
        Value::Str(Str::from(s))
    }
}

impl From<String> for Value {
    fn from(s: String) -> Self {
        Value::Str(Str::from(s))
    }
}

impl From<&[u8]> for Value {
    fn from(s: &[u8]) -> Self {
        Value::Str(Str::from(s))
    }
}

impl From<Vec<u8>> for Value {
    fn from(s: Vec<u8>) -> Self {
        Value::Str(Str::from(s))
    }
}

impl From<Str> for Value {
    fn from(s: Str) -> Self {
        Value::Str(s)
    }
}

impl<T: Into<Value>> From<Option<T>> for Value {
    fn from(v: Option<T>) -> Self {
        v.map_or(Value::Null, Into::into)
    }
}
