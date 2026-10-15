//! Helpers shared by the ported PHP unit tests: PHP array literals as
//! [`Array`]s and values.

#![allow(dead_code, unused_macros, unused_imports)]

use utopia_database::value::{Array, ArrayKey, Str, Value};
use utopia_database::{Document, Error, Kind};

/// A value a literal in a PHP array stands for.
pub trait IntoValue {
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
impl IntoValue for &str {
    fn into_value(self) -> Value {
        Value::Str(Str::from(self))
    }
}
impl IntoValue for String {
    fn into_value(self) -> Value {
        Value::Str(Str::from(self))
    }
}
impl IntoValue for &String {
    fn into_value(self) -> Value {
        Value::Str(Str::from(self.as_str()))
    }
}
impl IntoValue for i64 {
    fn into_value(self) -> Value {
        Value::Int(self)
    }
}
impl IntoValue for i32 {
    fn into_value(self) -> Value {
        Value::Int(i64::from(self))
    }
}
impl IntoValue for usize {
    fn into_value(self) -> Value {
        Value::Int(self as i64)
    }
}
impl IntoValue for f64 {
    fn into_value(self) -> Value {
        Value::Float(self)
    }
}
impl IntoValue for bool {
    fn into_value(self) -> Value {
        Value::Bool(self)
    }
}
impl IntoValue for () {
    fn into_value(self) -> Value {
        Value::Null
    }
}
impl IntoValue for Document {
    fn into_value(self) -> Value {
        Value::from(self)
    }
}
impl IntoValue for Array {
    fn into_value(self) -> Value {
        Value::Array(self)
    }
}
impl<T: IntoValue> IntoValue for Vec<T> {
    fn into_value(self) -> Value {
        Value::Array(self.into_iter().map(IntoValue::into_value).collect())
    }
}
impl<T: IntoValue> IntoValue for Option<T> {
    fn into_value(self) -> Value {
        self.map_or(Value::Null, IntoValue::into_value)
    }
}

/// An array key a PHP literal stands for (`"5"` is the key `5`).
pub trait IntoKey {
    fn into_key(self) -> ArrayKey;
}
impl IntoKey for &str {
    fn into_key(self) -> ArrayKey {
        ArrayKey::normalize(Str::from(self))
    }
}
impl IntoKey for String {
    fn into_key(self) -> ArrayKey {
        ArrayKey::normalize(Str::from(self))
    }
}
impl IntoKey for &String {
    fn into_key(self) -> ArrayKey {
        ArrayKey::normalize(Str::from(self.as_str()))
    }
}
impl IntoKey for i64 {
    fn into_key(self) -> ArrayKey {
        ArrayKey::Int(self)
    }
}
impl IntoKey for i32 {
    fn into_key(self) -> ArrayKey {
        ArrayKey::Int(i64::from(self))
    }
}

pub fn v(x: impl IntoValue) -> Value {
    x.into_value()
}

pub fn key(k: impl IntoKey) -> ArrayKey {
    k.into_key()
}

/// `['k' => v, 7 => v]`.
macro_rules! arr {
    () => { ::utopia_database::value::Array::new() };
    ($($k:expr => $v:expr),+ $(,)?) => {{
        let mut a = ::utopia_database::value::Array::new();
        $( a.set($crate::common::key($k), $crate::common::v($v)); )+
        a
    }};
}

/// `[v, v, v]`.
macro_rules! list {
    () => { ::utopia_database::value::Array::new() };
    ($($v:expr),+ $(,)?) => {{
        let mut a = ::utopia_database::value::Array::new();
        $( let _ = a.push($crate::common::v($v)); )+
        a
    }};
}

/// `new Document([...])`, unwrapped.
macro_rules! document {
    () => { ::utopia_database::Document::empty() };
    ($($k:expr => $v:expr),+ $(,)?) => {
        ::utopia_database::Document::new(arr![$($k => $v),+]).expect("valid document")
    };
}

pub(crate) use {arr, document, list};

/// Asserts an error's kind and message, byte for byte.
#[track_caller]
pub fn assert_error<T: std::fmt::Debug>(result: Result<T, Error>, kind: Kind, message: &str) {
    let e = result.expect_err("expected an error");
    assert_eq!(e.kind(), kind, "kind of {e:?}");
    assert_eq!(e.message(), message);
}
