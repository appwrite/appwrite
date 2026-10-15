//! Helpers of the validator tests: PHP's `Tests\Unit\Support\Profiles`, and
//! values written as JSON (decoded as PHP's `json_decode($json, true)`
//! decodes them, so `1` is an integer and `1.0` a float).

#![allow(dead_code)]

use std::sync::Arc;

use php_std::datetime::DateTime;
use utopia_database::adapter::{Feature, Features, Limits, Profile};
use utopia_database::capability::{Capabilities, Capability};
use utopia_database::validator::datetime::{default_max, default_min};
use utopia_database::value::{Ext, Value};
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
