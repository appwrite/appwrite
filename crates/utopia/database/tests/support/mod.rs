//! Helpers shared by the validator tests: documents, attributes, indexes
//! and query values from JSON, and profiles as PHP's
//! `tests/unit/Support/Profiles.php` builds them.

#![allow(dead_code)]

use std::sync::Arc;

use php_std::datetime::{DateTime, TzInfo};
use serde_json::Value as Json;
use utopia_database::adapter::{Features, Limits, Profile};
use utopia_database::attribute::Attribute;
use utopia_database::capability::{Capabilities, Capability};
use utopia_database::{Document, Index, Query, Value};
use utopia_query::schema::ColumnType;

/// `new Document($array)` of a JSON object.
pub fn doc(json: Json) -> Document {
    match utopia_database::value::from_plain(php_std::Value::from_json(&json)) {
        Value::Array(array) => Document::new(array).expect("a valid document"),
        _ => Document::empty(),
    }
}

/// `Attribute::fromDocument(new Document($array))`.
pub fn attr(json: Json) -> Attribute {
    Attribute::from_document(&doc(json)).expect("a valid attribute")
}

/// [`attr`] of each JSON object.
pub fn attrs(json: Json) -> Vec<Attribute> {
    match json {
        Json::Array(items) => items.into_iter().map(attr).collect(),
        other => vec![attr(other)],
    }
}

/// `Index::fromDocument(new Document($array))`.
pub fn index(json: Json) -> Index {
    Index::from_document(&doc(json)).expect("a valid index")
}

/// A query value of JSON.
pub fn qv(json: Json) -> utopia_query::Value {
    utopia_query::Value::from_json(&json)
}

/// A query array (`[...]`) of JSON.
pub fn qa(json: Json) -> utopia_query::Array {
    match qv(json) {
        utopia_query::Value::Array(array) => array,
        other => utopia_query::Array::from_list(vec![other]),
    }
}

/// A database value holding a list of entries (queries or other values).
pub fn list(items: Vec<Value>) -> Value {
    Value::Array(items.into_iter().collect())
}

/// A database value holding a query.
pub fn query_value(query: Query) -> Value {
    Value::from(query)
}

/// A string value.
pub fn s(text: &str) -> Value {
    utopia_database::value::string(text)
}

/// `new Query($method, $attribute, $values, $alias)` of JSON values.
pub fn q(method: utopia_query::Method, attribute: &str, values: Json) -> Query {
    Query::new(method, attribute, qa(values), "")
}

/// `new Query($method, $attribute, $values, $alias)` with nested queries.
pub fn q_nested(method: utopia_query::Method, attribute: &str, values: Vec<utopia_query::Value>, alias: &str) -> Query {
    Query::new(method, attribute, utopia_query::Array::from_list(values), alias)
}

/// A query as a query value (a nested query).
pub fn nested(query: Query) -> utopia_query::Value {
    utopia_query::Value::from(query.into_inner())
}

/// `Query::cursorAfter(new Document($array))`.
pub fn cursor_after(json: Json) -> Query {
    Query::cursor_after(&doc(json))
}

/// `Query::cursorBefore(new Document($array))`.
pub fn cursor_before(json: Json) -> Query {
    Query::cursor_before(&doc(json))
}

/// `new \DateTime($text)` in UTC.
pub fn date(text: &str) -> DateTime {
    DateTime::new(text.as_bytes(), None, &TzInfo::utc()).expect("a valid date")
}

/// The options of `Profiles::of()` the tests use.
#[derive(Clone)]
pub struct ProfileOptions {
    pub capabilities: Vec<Capability>,
    pub shared_tables: bool,
    pub uid_length: i64,
    pub max_date_time: Option<&'static str>,
}

impl Default for ProfileOptions {
    fn default() -> Self {
        ProfileOptions { capabilities: Vec::new(), shared_tables: false, uid_length: 36, max_date_time: None }
    }
}

/// `Profiles::of(capabilities: ...)`: only the capabilities named, every
/// other limit 0, the datetime range 0000-01-01 to 9999-12-31.
pub fn profile(capabilities: &[Capability]) -> Profile {
    profile_with(ProfileOptions { capabilities: capabilities.to_vec(), ..ProfileOptions::default() })
}

/// `Profiles::of(capabilities: ..., sharedTables: ...)`.
pub fn profile_shared(capabilities: &[Capability], shared_tables: bool) -> Profile {
    profile_with(ProfileOptions { capabilities: capabilities.to_vec(), shared_tables, ..ProfileOptions::default() })
}

/// `Profiles::of(...)`.
pub fn profile_with(options: ProfileOptions) -> Profile {
    let limits = Limits {
        string: 0,
        varchar: 0,
        integer: 0,
        big_integer: 0,
        attributes: 0,
        indexes: 0,
        default_attributes: 0,
        default_indexes: 0,
        index_length: 0,
        uid_length: options.uid_length,
        document_size: 0,
        min_date_time: date("0000-01-01"),
        max_date_time: date(options.max_date_time.unwrap_or("9999-12-31")),
        id_type: ColumnType::Integer,
        keywords: Vec::new(),
        internal_index_keys: Vec::new(),
    };
    Profile::new(
        Arc::new(limits),
        Capabilities::of(&options.capabilities),
        Features::EMPTY,
        options.shared_tables,
        false,
        None,
    )
}

/// The common capabilities: defined attributes and unsigned big integers.
pub const BASE: [Capability; 2] = [Capability::DefinedAttributes, Capability::UnsignedBigInt];
