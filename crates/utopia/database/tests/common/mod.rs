//! Helpers shared by the ported PHP unit tests: PHP array literals as
//! [`Array`]s and values, PHP's `Tests\Unit\Support\Profiles`, and values
//! written as JSON (decoded as PHP's `json_decode($json, true)` decodes
//! them, so `1` is an integer and `1.0` a float).

#![allow(dead_code, unused_macros, unused_imports)]

use std::sync::Arc;

use php_std::datetime::DateTime;
use utopia_database::adapter::{Feature, Features, Limits, Profile};
use utopia_database::capability::{Capabilities, Capability};
use utopia_database::validator::datetime::{default_max, default_min};
use utopia_database::value::{Array, ArrayKey, Ext, Str, Value};
use utopia_database::{Document, Error, Kind};
use utopia_query::schema::ColumnType;

/// The arguments of `Profiles::of()`, with its defaults.
pub struct P {
    pub capabilities: Vec<Capability>,
    pub features: Vec<Feature>,
    pub shared_tables: bool,
    pub migrating: bool,
    pub string: i64,
    pub varchar: i64,
    pub integer: i64,
    pub attributes: i64,
    pub index_length: i64,
    pub uid_length: i64,
    pub document_size: i64,
    pub id_type: ColumnType,
    pub min_date_time: Option<DateTime>,
    pub max_date_time: Option<DateTime>,
    pub internal_index_keys: Vec<String>,
}

impl Default for P {
    fn default() -> Self {
        P {
            capabilities: vec![],
            features: vec![],
            shared_tables: false,
            migrating: false,
            string: 0,
            varchar: 0,
            integer: 0,
            attributes: 0,
            index_length: 0,
            uid_length: 36,
            document_size: 0,
            id_type: ColumnType::Integer,
            min_date_time: None,
            max_date_time: None,
            internal_index_keys: vec![],
        }
    }
}

impl P {
    /// `Profiles::of(...)`.
    pub fn build(self) -> Profile {
        let limits = Limits {
            string: self.string,
            varchar: self.varchar,
            integer: self.integer,
            big_integer: self.integer,
            attributes: self.attributes,
            indexes: 0,
            default_attributes: 0,
            default_indexes: 0,
            index_length: self.index_length,
            uid_length: self.uid_length,
            document_size: self.document_size,
            min_date_time: self.min_date_time.unwrap_or_else(default_min),
            max_date_time: self.max_date_time.unwrap_or_else(default_max),
            id_type: self.id_type,
            keywords: vec![],
            internal_index_keys: self.internal_index_keys,
        };
        Profile::new(
            Arc::new(limits),
            Capabilities::of(&self.capabilities),
            Features::of(&self.features),
            self.shared_tables,
            self.migrating,
            None,
        )
    }
}

/// `Profiles::of(capabilities: $capabilities)`.
pub fn caps(capabilities: &[Capability]) -> Profile {
    P { capabilities: capabilities.to_vec(), ..P::default() }.build()
}

/// `json_decode($json, true)`.
pub fn val(json: &str) -> Value {
    php_std::json::decode_ext::<Ext>(
        json.as_bytes(),
        Some(true),
        php_std::json::DEFAULT_DEPTH,
        php_std::json::Flags::NONE,
    )
    .unwrap_or_else(|e| panic!("invalid JSON {json}: {e}"))
}

/// `new Document(json_decode($json, true))`.
pub fn doc(json: &str) -> Document {
    match val(json) {
        Value::Array(a) => Document::new(a).expect("a valid document"),
        other => panic!("not an object: {other:?}"),
    }
}

/// A document as a value.
pub fn dv(document: Document) -> Value {
    Value::from(document)
}

/// A string value.
pub fn s(text: &str) -> Value {
    Value::Str(text.into())
}

/// Asserts an error of `kind` with exactly `message`.
#[track_caller]
pub fn assert_error<T: std::fmt::Debug>(result: Result<T, Error>, kind: Kind, message: &str) {
    match result {
        Err(e) => {
            assert!(e.is_a(kind), "expected {kind:?}, got {:?}: {}", e.kind(), e.message());
            assert_eq!(e.message(), message);
        }
        Ok(v) => panic!("expected {kind:?} error \"{message}\", got Ok({v:?})"),
    }
}

/// Asserts an error of `kind` whose message contains `part`
/// (PHPUnit's `expectExceptionMessage`).
#[track_caller]
pub fn assert_error_contains<T: std::fmt::Debug>(result: Result<T, Error>, kind: Kind, part: &str) {
    match result {
        Err(e) => {
            assert!(e.is_a(kind), "expected {kind:?}, got {:?}: {}", e.kind(), e.message());
            assert!(e.message().contains(part), "\"{}\" does not contain \"{part}\"", e.message());
        }
        Ok(v) => panic!("expected {kind:?} error containing \"{part}\", got Ok({v:?})"),
    }
}

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
        ::utopia_database::Document::new($crate::common::arr![$($k => $v),+]).expect("valid document")
    };
}

pub(crate) use {arr, document, list};
