//! [`Value`]: a PHP value, and the engine's type juggling and comparison on it.

use std::cmp::Ordering;

use serde_json::{Map, Number as JsonNumber, Value as Json};

use super::{Array, ArrayKey, EngineError, Extension, KeyRef, Never, Str};
use crate::format::str_to_float;
use crate::number;
use crate::value::{Number, numeric_bytes_ex};

/// A PHP value.
#[derive(Clone, PartialEq, Debug, Default)]
pub enum Value<X: Extension = Never> {
    /// PHP `null`.
    #[default]
    Null,
    /// A PHP `bool`.
    Bool(bool),
    /// A PHP `int`.
    Int(i64),
    /// A PHP `float`.
    Float(f64),
    /// A PHP `string`, as bytes.
    Str(Str),
    /// A PHP array.
    Array(Array<X>),
    /// A `stdClass` instance: its properties in order (string keys, not normalised).
    Object(Array<X>),
    /// An object of another class.
    Ext(X),
}

impl<X: Extension> From<bool> for Value<X> {
    /// A PHP `bool`.
    fn from(b: bool) -> Self {
        Value::Bool(b)
    }
}

impl<X: Extension> From<i64> for Value<X> {
    /// A PHP `int`.
    fn from(i: i64) -> Self {
        Value::Int(i)
    }
}

impl<X: Extension> From<f64> for Value<X> {
    /// A PHP `float`.
    fn from(f: f64) -> Self {
        Value::Float(f)
    }
}

impl<X: Extension> From<&str> for Value<X> {
    /// A PHP `string` with the bytes of `s`.
    fn from(s: &str) -> Self {
        Value::Str(Str::from(s))
    }
}

impl<X: Extension> From<Str> for Value<X> {
    /// A PHP `string`.
    fn from(s: Str) -> Self {
        Value::Str(s)
    }
}

impl<X: Extension> From<Array<X>> for Value<X> {
    /// A PHP array.
    fn from(a: Array<X>) -> Self {
        Value::Array(a)
    }
}

/// The decimal digits of `i`, kept inline (no allocation).
fn int_string(i: i64) -> Str {
    let mut buf = [0u8; 20];
    let mut pos = buf.len();
    let mut n = i.unsigned_abs();
    loop {
        pos -= 1;
        buf[pos] = b'0' + (n % 10) as u8;
        n /= 10;
        if n == 0 {
            break;
        }
    }
    if i < 0 {
        pos -= 1;
        buf[pos] = b'-';
    }
    Str::copy_from(&buf[pos..])
}

/// `ZEND_THREEWAY_COMPARE` for doubles: NaN compares greater.
fn threeway(a: f64, b: f64) -> i32 {
    if a == b {
        0
    } else if a < b {
        -1
    } else {
        1
    }
}

pub(crate) fn normalize(i: i32) -> i32 {
    i.signum()
}

/// `zend_binary_strcmp`, normalised to -1, 0 or 1.
pub(crate) fn binary_strcmp(a: &[u8], b: &[u8]) -> i32 {
    match a.cmp(b) {
        Ordering::Less => -1,
        Ordering::Equal => 0,
        Ordering::Greater => 1,
    }
}

/// `is_numeric_string_ex` on bytes: the number and the overflow direction.
pub(crate) fn numeric(s: &[u8]) -> Option<(Number, i8)> {
    numeric_bytes_ex(s)
}

/// `zendi_smart_strcmp`: numeric strings compare as numbers.
pub(crate) fn smart_strcmp(s1: &[u8], s2: &[u8]) -> i32 {
    smart_strcmp_parsed(s1, numeric(s1), s2, numeric(s2))
}

/// [`smart_strcmp`] with each string's [`numeric`] form already parsed.
pub(crate) fn smart_strcmp_parsed(s1: &[u8], p1: Option<(Number, i8)>, s2: &[u8], p2: Option<(Number, i8)>) -> i32 {
    if let (Some((n1, o1)), Some((n2, o2))) = (p1, p2) {
        let (d1, d2) = (n1.as_f64(), n2.as_f64());
        if o1 != 0 && o1 == o2 && d1 - d2 == 0.0 {
            return binary_strcmp(s1, s2);
        }
        return match (n1, n2) {
            (Number::Int(a), Number::Int(b)) => match a.cmp(&b) {
                Ordering::Less => -1,
                Ordering::Equal => 0,
                Ordering::Greater => 1,
            },
            (Number::Int(_), Number::Float(_)) if o2 != 0 => -i32::from(o2),
            (Number::Float(_), Number::Int(_)) if o1 != 0 => i32::from(o1),
            (Number::Float(a), Number::Float(b)) if a == b && !a.is_finite() => binary_strcmp(s1, s2),
            _ => {
                let d = d1 - d2;
                if d > 0.0 {
                    1
                } else if d < 0.0 {
                    -1
                } else {
                    0
                }
            }
        };
    }
    binary_strcmp(s1, s2)
}

/// `compare_longs_to_string`.
fn compare_long_to_string(l: i64, s: &[u8]) -> i32 {
    compare_long_to_string_parsed(l, s, numeric(s))
}

/// [`compare_long_to_string`] with the string's [`numeric`] form already parsed.
pub(crate) fn compare_long_to_string_parsed(l: i64, s: &[u8], parsed: Option<(Number, i8)>) -> i32 {
    match parsed {
        Some((Number::Int(i), _)) => match l.cmp(&i) {
            Ordering::Less => -1,
            Ordering::Equal => 0,
            Ordering::Greater => 1,
        },
        Some((Number::Float(d), _)) => threeway(l as f64, d),
        None => binary_strcmp(l.to_string().as_bytes(), s),
    }
}

/// `compare_doubles_to_string` (`d` is not NaN).
fn compare_double_to_string(d: f64, s: &[u8]) -> i32 {
    compare_double_to_string_parsed(d, s, numeric(s))
}

/// [`compare_double_to_string`] with the string's [`numeric`] form already parsed.
pub(crate) fn compare_double_to_string_parsed(d: f64, s: &[u8], parsed: Option<(Number, i8)>) -> i32 {
    match parsed {
        Some((Number::Int(i), _)) => threeway(d, i as f64),
        Some((Number::Float(f), _)) => threeway(d, f),
        None => binary_strcmp(number::to_string(d).as_bytes(), s),
    }
}

/// `zend_hash_compare(.., ordered = 0)` with `zend_compare`: sizes first,
/// then every entry of `a` against the same key in `b` (missing: 1).
fn compare_tables<X: Extension>(a: &Array<X>, b: &Array<X>) -> i32 {
    if a.len() != b.len() {
        return if a.len() > b.len() { 1 } else { -1 };
    }
    for (k, v) in a.iter() {
        let Some(w) = b.get_ref(k) else { return 1 };
        let r = v.compare(w);
        if r != 0 {
            return r;
        }
    }
    0
}

/// `zend_hash_compare(.., ordered = 1)` with `zend_is_identical`.
fn identical_tables<X: Extension>(a: &Array<X>, b: &Array<X>) -> bool {
    a.len() == b.len() && a.iter().zip(b.iter()).all(|((ka, va), (kb, vb))| ka == kb && va.strict_eq(vb))
}

impl<X: Extension> Value<X> {
    /// `gettype()`.
    pub fn type_name(&self) -> &'static str {
        match self {
            Value::Null => "NULL",
            Value::Bool(_) => "boolean",
            Value::Int(_) => "integer",
            Value::Float(_) => "double",
            Value::Str(_) => "string",
            Value::Array(_) => "array",
            Value::Object(_) | Value::Ext(_) => "object",
        }
    }

    /// `get_debug_type()`.
    pub fn debug_type(&self) -> &str {
        match self {
            Value::Null => "null",
            Value::Bool(_) => "bool",
            Value::Int(_) => "int",
            Value::Float(_) => "float",
            Value::Str(_) => "string",
            Value::Array(_) => "array",
            Value::Object(_) => "stdClass",
            Value::Ext(x) => x.class(),
        }
    }

    /// `(bool) $v` (`zend_is_true`).
    pub fn truthy(&self) -> bool {
        match self {
            Value::Null => false,
            Value::Bool(b) => *b,
            Value::Int(i) => *i != 0,
            Value::Float(f) => *f != 0.0,
            Value::Str(s) => !(s.is_empty() || s.as_bytes() == b"0"),
            Value::Array(a) => !a.is_empty(),
            Value::Object(_) | Value::Ext(_) => true,
        }
    }

    /// `(string) $v` (`zval_get_string`): floats with `precision` 14, arrays
    /// `"Array"` (PHP also warns); objects throw `Error`.
    pub fn to_php_string(&self) -> Result<Str, EngineError> {
        Ok(match self {
            Value::Null | Value::Bool(false) => Str::EMPTY,
            Value::Bool(true) => Str::from_static("1"),
            Value::Int(i) => int_string(*i),
            Value::Float(f) => Str::from(number::to_string(*f)),
            Value::Str(s) => s.clone(),
            Value::Array(_) => Str::from_static("Array"),
            Value::Object(_) | Value::Ext(_) => {
                return Err(EngineError::new(
                    "Error",
                    format!("Object of class {} could not be converted to string", self.debug_type()),
                ));
            }
        })
    }

    /// `(float) $v` (`zval_get_double`): strings by their leading number,
    /// arrays 0.0 or 1.0, objects 1.0 (PHP warns).
    pub fn to_double(&self) -> f64 {
        match self {
            Value::Null | Value::Bool(false) => 0.0,
            Value::Bool(true) => 1.0,
            Value::Int(i) => *i as f64,
            Value::Float(f) => *f,
            Value::Str(s) => str_to_float(s),
            Value::Array(a) => {
                if a.is_empty() {
                    0.0
                } else {
                    1.0
                }
            }
            Value::Object(_) | Value::Ext(_) => 1.0,
        }
    }

    /// PHP 8 `$a == $b`.
    pub fn loose_eq(&self, o: &Self) -> bool {
        self.compare(o) == 0
    }

    /// `$a === $b`: same type and value; arrays with the same keys in the
    /// same order and identical values. Objects are never identical (see the
    /// module docs).
    pub fn strict_eq(&self, o: &Self) -> bool {
        match (self, o) {
            (Value::Null, Value::Null) => true,
            (Value::Bool(a), Value::Bool(b)) => a == b,
            (Value::Int(a), Value::Int(b)) => a == b,
            (Value::Float(a), Value::Float(b)) => a == b,
            (Value::Str(a), Value::Str(b)) => a == b,
            (Value::Array(a), Value::Array(b)) => identical_tables(a, b),
            _ => false,
        }
    }

    /// PHP 8 `$a <=> $b`. Uncomparable values (an array missing a key of the
    /// other, objects of different classes, NaN) are `Greater`, as in PHP.
    pub fn cmp_php(&self, o: &Self) -> Ordering {
        self.compare(o).cmp(&0)
    }

    /// `zend_compare`: -1, 0 or 1 (1 also for uncomparable values).
    pub fn compare(&self, o: &Self) -> i32 {
        use Value::*;
        match (self, o) {
            (Int(a), Int(b)) => match a.cmp(b) {
                Ordering::Less => -1,
                Ordering::Equal => 0,
                Ordering::Greater => 1,
            },
            (Float(a), Int(b)) => threeway(*a, *b as f64),
            (Int(a), Float(b)) => threeway(*a as f64, *b),
            (Float(a), Float(b)) => threeway(*a, *b),
            (Array(a), Array(b)) => compare_tables(a, b),
            (Null | Bool(false), Null | Bool(false)) | (Bool(true), Bool(true)) => 0,
            (Null, Bool(true)) => -1,
            (Bool(true), Null) => 1,
            (Str(a), Str(b)) => smart_strcmp(a, b),
            (Null, Str(s)) => {
                if s.is_empty() {
                    0
                } else {
                    -1
                }
            }
            (Str(s), Null) => i32::from(!s.is_empty()),
            (Int(l), Str(s)) => compare_long_to_string(*l, s),
            (Str(s), Int(l)) => normalize(-compare_long_to_string(*l, s)),
            (Float(d), Str(_)) if d.is_nan() => 1,
            (Float(d), Str(s)) => compare_double_to_string(*d, s),
            (Str(_), Float(d)) if d.is_nan() => 1,
            (Str(s), Float(d)) => normalize(-compare_double_to_string(*d, s)),
            (Object(_) | Ext(_), Null) => 1,
            (Null, Object(_) | Ext(_)) => -1,
            (Object(a), Object(b)) => compare_tables(a, b),
            (Ext(a), Ext(b)) => {
                // spl_array_compare_objects: the storage, then the class.
                let r = compare_tables(&a.to_array(), &b.to_array());
                if r == 0 && a.class() != b.class() { 1 } else { r }
            }
            // Objects of different classes are uncomparable.
            (Object(_), Ext(_)) | (Ext(_), Object(_)) => 1,
            (Object(_) | Ext(_), other) => object_vs(other, true),
            (other, Object(_) | Ext(_)) => object_vs(other, false),
            // Booleans and null against the rest: by truthiness.
            (Null | Bool(false), other) => -i32::from(other.truthy()),
            (Bool(true), other) => i32::from(!other.truthy()),
            (other, Null | Bool(false)) => i32::from(other.truthy()),
            (other, Bool(true)) => -i32::from(!other.truthy()),
            // An array is greater than any other scalar.
            (Array(_), _) => 1,
            (_, Array(_)) => -1,
        }
    }

    /// The request-model value of JSON, as `Utopia\Http\Request` decodes a
    /// body: objects are arrays (keys normalised), except empty ones, which
    /// stay `stdClass`; integers beyond `i64` are floats.
    pub fn from_json(v: &Json) -> Self {
        match v {
            Json::Null => Value::Null,
            Json::Bool(b) => Value::Bool(*b),
            Json::Number(n) => match n.as_i64() {
                Some(i) => Value::Int(i),
                None => Value::Float(n.as_f64().unwrap_or(0.0)),
            },
            Json::String(s) => Value::Str(Str::from(s.as_str())),
            Json::Array(a) => Value::Array(a.iter().map(Value::from_json).collect()),
            Json::Object(o) if o.is_empty() => Value::Object(Array::new()),
            Json::Object(o) => Value::Array(
                o.iter().map(|(k, v)| (ArrayKey::normalize(Str::from(k.as_str())), Value::from_json(v))).collect(),
            ),
        }
    }

    /// JSON for this value: lists are arrays, other arrays and objects are
    /// objects (integer keys as strings). Lossy where JSON cannot hold the
    /// value: invalid UTF-8 becomes U+FFFD and non-finite floats `null`.
    /// Use [`crate::json::encode`] for PHP's exact `json_encode`.
    pub fn to_json(&self) -> Json {
        fn text(b: &[u8]) -> String {
            String::from_utf8_lossy(b).into_owned()
        }
        fn object<X: Extension>(a: &Array<X>) -> Json {
            let mut m = Map::with_capacity(a.len());
            for (k, v) in a.iter() {
                let key = match k {
                    KeyRef::Int(i) => i.to_string(),
                    KeyRef::Str(s) => text(s),
                };
                m.insert(key, v.to_json());
            }
            Json::Object(m)
        }
        match self {
            Value::Null => Json::Null,
            Value::Bool(b) => Json::Bool(*b),
            Value::Int(i) => Json::from(*i),
            Value::Float(f) => JsonNumber::from_f64(*f).map_or(Json::Null, Json::Number),
            Value::Str(s) => Json::String(text(s)),
            Value::Array(a) if a.is_list() => Json::Array(a.values().map(Value::to_json).collect()),
            Value::Array(a) | Value::Object(a) => object(a),
            Value::Ext(x) => object(&x.to_array()),
        }
    }
}

/// `zend_std_compare_objects` of an object against a non-object, from the
/// object's side (`object_lhs`): booleans by truthiness (objects are true),
/// numbers against 1 (PHP notices), strings and arrays uncomparable.
fn object_vs<X: Extension>(other: &Value<X>, object_lhs: bool) -> i32 {
    let casted: Value<X> = match other {
        Value::Bool(_) => Value::Bool(true),
        Value::Int(_) => Value::Int(1),
        Value::Float(_) => Value::Float(1.0),
        _ => return if object_lhs { 1 } else { -1 },
    };
    if object_lhs { casted.compare(other) } else { other.compare(&casted) }
}

impl<X: Extension> Value<X> {
    /// The request-model JSON of this value, exactly: like [`Value::to_json`]
    /// (lists are arrays, other arrays and objects are objects with integer
    /// keys as strings, an extension object its `to_array()`), but `None`
    /// where JSON cannot hold the value: a non-finite float, or a string or
    /// key that is not UTF-8.
    ///
    /// ```
    /// use php_std::{Str, Value};
    /// use serde_json::json;
    ///
    /// let list: Value = Value::from_json(&json!(["a", {"b": 1}]));
    /// assert_eq!(list.try_to_json(), Some(json!(["a", {"b": 1}])));
    /// assert_eq!(Value::<php_std::Never>::Str(Str::copy_from(&[0xff])).try_to_json(), None);
    /// assert_eq!(Value::<php_std::Never>::Float(f64::INFINITY).try_to_json(), None);
    /// ```
    pub fn try_to_json(&self) -> Option<Json> {
        fn text(b: &[u8]) -> Option<String> {
            std::str::from_utf8(b).ok().map(str::to_owned)
        }
        fn object<X: Extension>(a: &Array<X>) -> Option<Json> {
            let mut m = Map::with_capacity(a.len());
            for (k, v) in a.iter() {
                let key = match k {
                    KeyRef::Int(i) => i.to_string(),
                    KeyRef::Str(s) => text(s)?,
                };
                m.insert(key, v.try_to_json()?);
            }
            Some(Json::Object(m))
        }
        Some(match self {
            Value::Null => Json::Null,
            Value::Bool(b) => Json::Bool(*b),
            Value::Int(i) => Json::from(*i),
            Value::Float(f) => Json::Number(JsonNumber::from_f64(*f)?),
            Value::Str(s) => Json::String(text(s)?),
            Value::Array(a) if a.is_list() => Json::Array(a.values().map(Value::try_to_json).collect::<Option<_>>()?),
            Value::Array(a) | Value::Object(a) => object(a)?,
            Value::Ext(x) => {
                let a = x.to_array();
                if a.is_list() { Value::Array(a).try_to_json()? } else { object(&a)? }
            }
        })
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    type V = Value<Never>;

    fn v(j: Json) -> V {
        V::from_json(&j)
    }

    #[test]
    fn juggling_like_php() {
        assert!(v(json!("false")).truthy());
        assert!(!v(json!("0")).truthy());
        assert!(v(json!({})).truthy());
        assert!(!v(json!([])).truthy());
        assert_eq!(v(json!(1e25)).to_php_string().unwrap(), *"1.0E+25");
        assert_eq!(v(json!([1])).to_php_string().unwrap(), *"Array");
        assert_eq!(v(json!({})).to_php_string().unwrap_err().class, "Error");
        assert_eq!(v(json!(" 1.5x")).to_double(), 1.5);
    }

    #[test]
    fn comparisons_like_php() {
        assert!(!v(json!("abc")).loose_eq(&v(json!(0))));
        assert!(v(json!("1e3")).loose_eq(&v(json!("1000"))));
        assert!(v(json!(null)).loose_eq(&v(json!(0))));
        assert!(v(json!(null)).loose_eq(&v(json!(""))));
        assert_eq!(v(json!(0)).compare(&v(json!(""))), 1);
        assert!(v(json!([])).loose_eq(&v(json!(false))));
        assert!(!v(json!({})).loose_eq(&v(json!([]))));
        assert!(v(json!({})).loose_eq(&v(json!(1))));
        assert!(v(json!({"0": 1})).loose_eq(&v(json!([1]))));
        assert_eq!(v(json!([1, 2])).compare(&v(json!([1]))), 1);
        assert_eq!(v(json!({"a": 1})).compare(&v(json!({"b": 1}))), 1);
        assert_eq!(v(json!({"b": 1})).compare(&v(json!({"a": 1}))), 1);
        assert_eq!(V::Float(f64::NAN).compare(&V::Int(1)), 1);
        assert_eq!(V::Int(1).compare(&V::Float(f64::NAN)), 1);
        assert_eq!(v(json!("9223372036854775808")).compare(&v(json!("9223372036854775807"))), 1);
        assert!(v(json!([1, "a"])).strict_eq(&v(json!([1, "a"]))));
        assert!(!v(json!({"a": 1, "b": 2})).strict_eq(&v(json!({"b": 2, "a": 1}))));
        assert!(v(json!({"a": 1, "b": 2})).loose_eq(&v(json!({"b": 2, "a": 1}))));
    }

    #[test]
    fn request_model_round_trip() {
        let j = json!({"0": "a", "1": {"x": []}, "k": {}});
        assert_eq!(v(j.clone()).to_json(), j);
        assert_eq!(v(json!({"0": 1, "1": 2})).to_json(), json!([1, 2]));
    }
}
