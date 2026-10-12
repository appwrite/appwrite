//! validator.*, role.*, permission.*: the validators under
//! `Utopia\Database\Validator` (except Authorization) and `Helpers\Role`,
//! `Helpers\Permission`. Twin of `tests/compat/database/ops/validators.php`
//! (read its docs for the validator specs).

use std::sync::Arc;

use php_std::datetime::{DateTime, TzInfo};
use serde_json::{Map, Value, json};
use utopia_database::Document;
use utopia_database::helpers::permission::{Permission, PermissionType};
use utopia_database::helpers::role::Role;
use utopia_database::validator::attribute::{Attribute, AttributeOptions};
use utopia_database::validator::big_int::BigInt;
use utopia_database::validator::byte_length::ByteLength;
use utopia_database::validator::datetime::{Datetime, Precision, default_max, default_min};
use utopia_database::validator::index::{Index, IndexSupport};
use utopia_database::validator::index_dependency::IndexDependency;
use utopia_database::validator::indexed_queries::IndexedQueries;
use utopia_database::validator::key::Key;
use utopia_database::validator::label::Label;
use utopia_database::validator::object::ObjectValidator;
use utopia_database::validator::operator::Operator as OperatorValidator;
use utopia_database::validator::partial_structure::PartialStructure;
use utopia_database::validator::permissions::{PERMISSIONS, Permissions};
use utopia_database::validator::queries::Queries;
use utopia_database::validator::queries::document::Document as DocumentQueries;
use utopia_database::validator::queries::documents::{Documents as DocumentsQueries, DocumentsOptions};
use utopia_database::validator::query::base::QueryValidator;
use utopia_database::validator::query::cursor::Cursor;
use utopia_database::validator::query::filter::{Filter, FilterOptions};
use utopia_database::validator::query::limit::Limit;
use utopia_database::validator::query::offset::Offset;
use utopia_database::validator::query::order::Order;
use utopia_database::validator::query::select::Select;
use utopia_database::validator::roles::{ROLES, Roles};
use utopia_database::validator::sequence::Sequence;
use utopia_database::validator::spatial::Spatial;
use utopia_database::validator::structure::{Structure, StructureOptions, formats};
use utopia_database::validator::uid::Uid;
use utopia_database::validator::vector::Vector;
use utopia_database::value::{Ext, Value as DbValue};
use utopia_validators::{Input, Range, Text, Type, Verdict, WhiteList};

use super::codec;
use crate::adapter::{Fault, OpResult, Outcome, Session};

/// Operations of this area (must match `spec.d/` and `ops/validators.php`).
pub const OPS: &[&str] = &[
    "validator.new",
    "validator.check",
    "validator.is_valid",
    "validator.description",
    "validator.meta",
    "validator.call",
    "validator.big_int",
    "validator.spatial_wkt",
    "validator.format_add",
    "validator.format_remove",
    "validator.format_has",
    "validator.format_get",
    "validator.formats",
    "role.parse",
    "role.new",
    "role.of",
    "permission.parse",
    "permission.new",
    "permission.of",
    "permission.aggregate",
];

/// A validator built from a spec.
enum V {
    Attribute(Attribute),
    BigInt(BigInt),
    ByteLength(ByteLength),
    Datetime(Datetime),
    Index(Index),
    IndexDependency(IndexDependency),
    IndexedQueries(IndexedQueries),
    Key(Key),
    Label(Label),
    Object(ObjectValidator),
    Operator(OperatorValidator),
    PartialStructure(PartialStructure),
    Permissions(Permissions),
    Queries(Queries),
    QueriesDocument(DocumentQueries),
    QueriesDocuments(DocumentsQueries),
    Cursor(Cursor),
    Filter(Filter),
    Limit(Limit),
    Offset(Offset),
    Order(Order),
    Select(Select),
    Roles(Roles),
    Sequence(Sequence),
    Spatial(Spatial),
    Structure(Structure),
    Uid(Uid),
    Vector(Vector),
}

/// `isValid` failed by throwing.
type Valid = Result<bool, utopia_database::Error>;

impl V {
    fn description(&self) -> String {
        match self {
            V::Attribute(v) => v.description(),
            V::BigInt(v) => v.description(),
            V::ByteLength(v) => v.description(),
            V::Datetime(v) => v.description(),
            V::Index(v) => v.description(),
            V::IndexDependency(v) => v.description(),
            V::IndexedQueries(v) => v.description(),
            V::Key(v) => v.description(),
            V::Label(v) => v.description(),
            V::Object(v) => v.description(),
            V::Operator(v) => v.description(),
            V::PartialStructure(v) => v.description(),
            V::Permissions(v) => v.description(),
            V::Queries(v) => v.description(),
            V::QueriesDocument(v) => v.description(),
            V::QueriesDocuments(v) => v.description(),
            V::Cursor(v) => v.description(),
            V::Filter(v) => v.description(),
            V::Limit(v) => v.description(),
            V::Offset(v) => v.description(),
            V::Order(v) => v.description(),
            V::Select(v) => v.description(),
            V::Roles(v) => v.description(),
            V::Sequence(v) => v.description(),
            V::Spatial(v) => v.description(),
            V::Structure(v) => v.description(),
            V::Uid(v) => v.description(),
            V::Vector(v) => v.description(),
        }
    }

    /// `isValid($value)`; documents are validated in place (Structure
    /// changes them, as PHP's does).
    fn is_valid(&self, value: &mut DbValue) -> Result<Valid, Fault> {
        Ok(Ok(match self {
            V::Attribute(v) => return Ok(v.is_valid(value)),
            V::BigInt(v) => v.is_valid(value),
            V::ByteLength(v) => v.is_valid(value),
            V::Datetime(v) => v.is_valid(value),
            V::Index(v) => v.is_valid(value),
            V::IndexDependency(v) => match value {
                DbValue::Ext(Ext::Document(_)) => v.is_valid(value),
                _ => return Err(Fault::new("IndexDependency validates documents (PHP fails on other values)")),
            },
            V::IndexedQueries(v) => v.is_valid(value),
            V::Key(v) => v.is_valid(value),
            V::Label(v) => v.is_valid(value),
            V::Object(v) => v.is_valid(value),
            V::Operator(v) => return Ok(v.is_valid(value)),
            V::PartialStructure(v) => {
                return Ok(match value {
                    DbValue::Ext(Ext::Document(d)) => v.validate(d),
                    other => v.is_valid(other),
                });
            }
            V::Permissions(v) => v.is_valid(value),
            V::Queries(v) => v.is_valid(value),
            V::QueriesDocument(v) => v.is_valid(value),
            V::QueriesDocuments(v) => v.is_valid(value),
            V::Cursor(v) => v.is_valid(value),
            V::Filter(v) => match value {
                DbValue::Ext(Ext::Query(q)) => v.check(q),
                _ => return Err(Fault::new("Filter validates queries (PHP fails on other values)")),
            },
            V::Limit(v) => v.is_valid(value),
            V::Offset(v) => v.is_valid(value),
            V::Order(v) => v.is_valid(value),
            V::Select(v) => v.is_valid(value),
            V::Roles(v) => v.is_valid(value),
            V::Sequence(v) => v.is_valid(value),
            V::Spatial(v) => v.is_valid(value),
            V::Structure(v) => {
                return Ok(match value {
                    DbValue::Ext(Ext::Document(d)) => v.validate(d),
                    other => v.is_valid(other),
                });
            }
            V::Uid(v) => v.is_valid(value),
            V::Vector(v) => v.is_valid(value),
        }))
    }

    /// `getType()`, `isArray()` and the other getters some validators have.
    fn meta(&self) -> Value {
        let base = |v: &dyn utopia_validators::Validator, kind: &str| {
            let mut m = Map::new();
            m.insert("type".into(), json!(kind));
            m.insert("is_array".into(), json!(v.is_array()));
            m
        };
        let mut m = match self {
            V::Attribute(v) => base(v, v.type_name()),
            V::BigInt(v) => base(v, v.type_name()),
            V::ByteLength(v) => base(v, v.type_name()),
            V::Datetime(v) => base(v, v.type_name()),
            V::Index(v) => base(v, v.type_name()),
            V::IndexDependency(v) => base(v, v.type_name()),
            V::IndexedQueries(v) => base(v, v.type_name()),
            V::Key(v) => base(v, v.type_name()),
            V::Label(v) => base(v, v.type_name()),
            V::Object(v) => base(v, v.type_name()),
            V::Operator(v) => base(v, v.type_name()),
            V::PartialStructure(v) => base(v, v.type_name()),
            V::Permissions(v) => base(v, v.type_name()),
            V::Queries(v) => base(v, v.type_name()),
            V::QueriesDocument(v) => base(v, v.type_name()),
            V::QueriesDocuments(v) => base(v, v.type_name()),
            V::Cursor(v) => base(v, v.type_name()),
            V::Filter(v) => base(v, v.type_name()),
            V::Limit(v) => base(v, v.type_name()),
            V::Offset(v) => base(v, v.type_name()),
            V::Order(v) => base(v, v.type_name()),
            V::Select(v) => base(v, v.type_name()),
            V::Roles(v) => base(v, v.type_name()),
            V::Sequence(v) => base(v, v.type_name()),
            V::Spatial(v) => base(v, v.type_name()),
            V::Structure(v) => base(v, v.type_name()),
            V::Uid(v) => base(v, v.type_name()),
            V::Vector(v) => base(v, v.type_name()),
        };
        let method_type = match self {
            V::Cursor(v) => Some(v.method_type()),
            V::Filter(v) => Some(v.method_type()),
            V::Limit(v) => Some(v.method_type()),
            V::Offset(v) => Some(v.method_type()),
            V::Order(v) => Some(v.method_type()),
            V::Select(v) => Some(v.method_type()),
            _ => None,
        };
        if let Some(t) = method_type {
            m.insert("method_type".into(), json!(t.as_str()));
        }
        if let V::Filter(v) = self {
            m.insert("max_values_count".into(), json!(v.max_values_count()));
        }
        if let V::Spatial(v) = self {
            m.insert("spatial_type".into(), json!(v.spatial_type()));
        }
        Value::Object(m)
    }
}

fn str_arg<'a>(a: &'a Value, key: &str) -> Option<&'a str> {
    a.get(key).and_then(Value::as_str)
}

fn bool_arg(a: &Value, key: &str, default: bool) -> bool {
    a.get(key).and_then(Value::as_bool).unwrap_or(default)
}

fn int_arg(a: &Value, key: &str, default: i64) -> i64 {
    a.get(key).and_then(Value::as_i64).unwrap_or(default)
}

fn date_arg(a: &Value, key: &str, default: fn() -> DateTime) -> Result<DateTime, Fault> {
    match str_arg(a, key) {
        Some(s) => DateTime::new(s.as_bytes(), None, &TzInfo::utc()).map_err(|e| Fault::new(e.message().into_owned())),
        None => Ok(default()),
    }
}

fn docs_arg(a: &Value, key: &str) -> Result<Vec<Document>, Fault> {
    match a.get(key) {
        Some(v) => codec::documents(v),
        None => Ok(Vec::new()),
    }
}

fn doc_arg(a: &Value, key: &str) -> Result<Option<Document>, Fault> {
    match a.get(key) {
        None | Some(Value::Null) => Ok(None),
        Some(v) => codec::document(v).map(Some),
    }
}

fn strings_arg(a: &Value, key: &str) -> Option<Vec<String>> {
    a.get(key).and_then(Value::as_array).map(|l| l.iter().map(|v| v.as_str().unwrap_or("").to_owned()).collect())
}

fn query_validators(a: &Value) -> Result<Vec<Box<dyn QueryValidator>>, Fault> {
    let mut out: Vec<Box<dyn QueryValidator>> = Vec::new();
    for spec in codec::list(a.get("validators").unwrap_or(&Value::Null))? {
        out.push(match make(spec)? {
            V::Cursor(v) => Box::new(v),
            V::Filter(v) => Box::new(v),
            V::Limit(v) => Box::new(v),
            V::Offset(v) => Box::new(v),
            V::Order(v) => Box::new(v),
            V::Select(v) => Box::new(v),
            _ => return Err(Fault::new("queries take query validators")),
        });
    }
    Ok(out)
}

/// The SQL adapters' `filter()` of a key (`preg_replace('/[^A-Za-z0-9_\-]/', '', ...)`).
fn adapter_filter(value: &str) -> String {
    value.chars().filter(|c| c.is_ascii_alphanumeric() || *c == '_' || *c == '-').collect()
}

fn make(spec: &Value) -> Result<V, Fault> {
    let a = spec;
    let name = str_arg(a, "validator").ok_or_else(|| Fault::new("expected a validator spec"))?;
    let collection =
        || -> Result<Document, Fault> { doc_arg(a, "collection")?.ok_or_else(|| Fault::new("collection is required")) };
    let id_type = || str_arg(a, "idAttributeType").unwrap_or("").to_owned();
    Ok(match name {
        "attribute" => {
            let count = a.get("attributeCount").and_then(Value::as_i64);
            let width = a.get("attributeWidth").and_then(Value::as_i64);
            V::Attribute(Attribute::new(
                &docs_arg(a, "attributes")?,
                &docs_arg(a, "schemaAttributes")?,
                AttributeOptions {
                    max_attributes: int_arg(a, "maxAttributes", 0),
                    max_width: int_arg(a, "maxWidth", 0),
                    max_string_length: int_arg(a, "maxStringLength", 0),
                    max_varchar_length: int_arg(a, "maxVarcharLength", 0),
                    max_int_length: int_arg(a, "maxIntLength", 0),
                    max_big_int_length: int_arg(a, "maxBigIntLength", 0),
                    support_for_schema_attributes: bool_arg(a, "supportForSchemaAttributes", false),
                    support_for_vectors: bool_arg(a, "supportForVectors", false),
                    support_for_spatial_attributes: bool_arg(a, "supportForSpatialAttributes", false),
                    support_for_object: bool_arg(a, "supportForObject", false),
                    support_unsigned_big_int: bool_arg(a, "supportUnsignedBigInt", false),
                    attribute_count: count.map(|c| Box::new(move |_: &Document| c) as _),
                    attribute_width: width.map(|w| Box::new(move |_: &Document| w) as _),
                    filter: a.get("filter").map(|_| Box::new(|v: &str| adapter_filter(v)) as _),
                    is_migrating: bool_arg(a, "isMigrating", false),
                    shared_tables: bool_arg(a, "sharedTables", false),
                },
            ))
        }
        "big_int" => V::BigInt(BigInt::new(bool_arg(a, "signed", true), bool_arg(a, "supportUnsigned64Bit", true))),
        "byte_length" => V::ByteLength(ByteLength::new(int_arg(a, "max", 0))),
        "datetime" => {
            let precision = match str_arg(a, "precision") {
                None => Precision::Any,
                Some(p) => Precision::from_name(p).ok_or_else(|| Fault::new("unknown precision (a deviation)"))?,
            };
            match Datetime::new(
                date_arg(a, "min", default_min)?,
                date_arg(a, "max", default_max)?,
                bool_arg(a, "requireDateInFuture", false),
                precision,
                int_arg(a, "offset", 0),
            ) {
                Ok(v) => V::Datetime(v),
                Err(e) => return Err(Fault::new(format!("!{}|{}", e.php_class(), e.message()))),
            }
        }
        "index" => V::Index(Index::new(
            &docs_arg(a, "attributes")?,
            docs_arg(a, "indexes")?,
            int_arg(a, "maxLength", 0),
            strings_arg(a, "reservedKeys").unwrap_or_default(),
            IndexSupport {
                array_indexes: bool_arg(a, "supportForArrayIndexes", false),
                spatial_index_null: bool_arg(a, "supportForSpatialIndexNull", false),
                spatial_index_order: bool_arg(a, "supportForSpatialIndexOrder", false),
                vector_indexes: bool_arg(a, "supportForVectorIndexes", false),
                attributes: bool_arg(a, "supportForAttributes", true),
                multiple_fulltext_indexes: bool_arg(a, "supportForMultipleFulltextIndexes", true),
                identical_indexes: bool_arg(a, "supportForIdenticalIndexes", true),
                object_indexes: bool_arg(a, "supportForObjectIndexes", false),
                trigram_indexes: bool_arg(a, "supportForTrigramIndexes", false),
                spatial_indexes: bool_arg(a, "supportForSpatialIndexes", false),
                key_indexes: bool_arg(a, "supportForKeyIndexes", true),
                unique_indexes: bool_arg(a, "supportForUniqueIndexes", true),
                fulltext_indexes: bool_arg(a, "supportForFulltextIndexes", true),
                ttl_indexes: bool_arg(a, "supportForTTLIndexes", false),
                objects: bool_arg(a, "supportForObjects", false),
            },
        )),
        "index_dependency" => {
            V::IndexDependency(IndexDependency::new(docs_arg(a, "indexes")?, bool_arg(a, "castIndexSupport", false)))
        }
        "indexed_queries" => V::IndexedQueries(IndexedQueries::new(
            docs_arg(a, "attributes")?,
            docs_arg(a, "indexes")?,
            query_validators(a)?,
        )),
        "key" => V::Key(Key::new(bool_arg(a, "allowInternal", false), int_arg(a, "maxLength", 36))),
        "label" => V::Label(Label::new(bool_arg(a, "allowInternal", false), int_arg(a, "maxLength", 36))),
        "object" => V::Object(ObjectValidator),
        "operator" => V::Operator(OperatorValidator::new(&collection()?, doc_arg(a, "currentDocument")?)),
        "partial_structure" => {
            V::PartialStructure(PartialStructure::with_options(collection()?, id_type(), structure_options(a)?))
        }
        "permissions" => V::Permissions(Permissions::new(
            int_arg(a, "length", 0),
            strings_arg(a, "allowed").unwrap_or_else(|| PERMISSIONS.iter().map(|p| (*p).to_owned()).collect()),
        )),
        "queries" => V::Queries(Queries::new(query_validators(a)?, int_arg(a, "length", 0))),
        "queries_document" => V::QueriesDocument(DocumentQueries::new(
            &docs_arg(a, "attributes")?,
            bool_arg(a, "supportForAttributes", true),
        )),
        "queries_documents" => V::QueriesDocuments(DocumentsQueries::with_options(
            &docs_arg(a, "attributes")?,
            &docs_arg(a, "indexes")?,
            &id_type(),
            DocumentsOptions {
                max_values_count: int_arg(a, "maxValuesCount", 5000),
                max_uid_length: int_arg(a, "maxUIDLength", 36),
                min_allowed_date: date_arg(a, "minAllowedDate", default_min)?,
                max_allowed_date: date_arg(a, "maxAllowedDate", default_max)?,
                support_for_attributes: bool_arg(a, "supportForAttributes", true),
                support_unsigned_big_int: bool_arg(a, "supportUnsignedBigInt", true),
            },
        )),
        "query_cursor" => V::Cursor(Cursor::new(int_arg(a, "maxLength", 36))),
        "query_filter" => V::Filter(Filter::with_options(
            &docs_arg(a, "attributes")?,
            id_type(),
            FilterOptions {
                max_values_count: int_arg(a, "maxValuesCount", 5000),
                min_allowed_date: date_arg(a, "minAllowedDate", default_min)?,
                max_allowed_date: date_arg(a, "maxAllowedDate", default_max)?,
                support_for_attributes: bool_arg(a, "supportForAttributes", true),
                support_unsigned_big_int: bool_arg(a, "supportUnsignedBigInt", true),
            },
        )),
        "query_limit" => V::Limit(Limit::new(int_arg(a, "maxLimit", i64::MAX))),
        "query_offset" => V::Offset(Offset::new(int_arg(a, "maxOffset", i64::MAX))),
        "query_order" => V::Order(Order::new(&docs_arg(a, "attributes")?, bool_arg(a, "supportForAttributes", true))),
        "query_select" => {
            V::Select(Select::new(&docs_arg(a, "attributes")?, bool_arg(a, "supportForAttributes", true)))
        }
        "roles" => V::Roles(Roles::new(
            int_arg(a, "length", 0),
            strings_arg(a, "allowed").unwrap_or_else(|| ROLES.iter().map(|r| (*r).to_owned()).collect()),
        )),
        "sequence" => V::Sequence(Sequence::new(id_type(), bool_arg(a, "primary", false))),
        "spatial" => V::Spatial(Spatial::new(str_arg(a, "spatialType").unwrap_or(""))),
        "structure" => V::Structure(Structure::with_options(collection()?, id_type(), structure_options(a)?)),
        "uid" => V::Uid(Uid::new(int_arg(a, "maxLength", 36))),
        "vector" => V::Vector(Vector::new(int_arg(a, "size", 0))),
        other => return Err(Fault::new(format!("unknown validator `{other}`"))),
    })
}

fn structure_options(a: &Value) -> Result<StructureOptions, Fault> {
    Ok(StructureOptions {
        min_allowed_date: date_arg(a, "minAllowedDate", default_min)?,
        max_allowed_date: date_arg(a, "maxAllowedDate", default_max)?,
        support_for_attributes: bool_arg(a, "supportForAttributes", true),
        support_unsigned_big_int: bool_arg(a, "supportUnsignedBigInt", true),
        current_document: doc_arg(a, "currentDocument")?,
    })
}

/// A spec's construction failure as the error PHP's constructor throws
/// (`make` reports it as a fault starting with `!class|`).
fn built(spec: &Value) -> Result<Result<V, Outcome>, Fault> {
    match make(spec) {
        Ok(v) => Ok(Ok(v)),
        Err(Fault(m)) if m.starts_with('!') => {
            let (class, message) = m[1..].split_once('|').unwrap_or(("Exception", ""));
            Ok(Err(Outcome::err(class, message)))
        }
        Err(f) => Err(f),
    }
}

fn validate(v: &V, value: &Value) -> OpResult {
    let mut value = codec::decode(value)?;
    let valid = match v.is_valid(&mut value)? {
        Ok(valid) => valid,
        Err(e) => return Ok(codec::error(&e)),
    };
    let mut out = Map::new();
    out.insert("valid".into(), json!(valid));
    out.insert("description".into(), json!(v.description()));
    if matches!(value, DbValue::Ext(Ext::Document(_))) {
        out.insert("value".into(), codec::encode(&value));
    }
    Ok(Outcome::Ok(Value::Object(out)))
}

fn role_json(r: &Role) -> Value {
    json!({
        "role": r.name().as_str(),
        "identifier": r.identifier(),
        "dimension": r.dimension(),
        "string": r.to_string(),
    })
}

fn permission_json(p: &Permission) -> Value {
    json!({
        "permission": p.permission().as_str(),
        "role": p.role().name().as_str(),
        "identifier": p.role().identifier(),
        "dimension": p.role().dimension(),
        "string": p.to_string(),
    })
}

fn role_of(a: &Value) -> Role {
    Role::named(
        str_arg(a, "role").unwrap_or(""),
        str_arg(a, "identifier").unwrap_or(""),
        str_arg(a, "dimension").unwrap_or(""),
    )
}

/// The email format of the cases: `filter_var($value, FILTER_VALIDATE_EMAIL)`.
struct Email;

impl utopia_validators::Validator for Email {
    fn description(&self) -> String {
        "Value must be a valid email address".to_owned()
    }

    fn is_valid(&self, value: &Value) -> bool {
        self.validate(Input::Json(value)).is_ok_and(|v| v.valid)
    }

    fn validate(&self, value: Input<'_>) -> Result<Verdict, utopia_validators::Error> {
        use php_std::filter::{FILTER_VALIDATE_EMAIL, Options, filter_var};
        let valid = match filter_var(&value.filter_value(), FILTER_VALIDATE_EMAIL, &Options::Flags(0)) {
            Ok(f) => match f.value {
                php_std::filter::Value::Bool(b) => b,
                php_std::filter::Value::Str(s) => !(s.is_empty() || s == b"0"),
                php_std::filter::Value::Null => false,
                _ => true,
            },
            Err(_) => false,
        };
        Ok(Verdict::of(valid))
    }
}

fn format_factory(name: &str) -> Option<formats::Factory> {
    Some(match name {
        "email" => Arc::new(|_: &Document| Box::new(Email) as Box<dyn utopia_validators::Validator>),
        "text" => Arc::new(|attribute: &Document| {
            let size = attribute.get("size").and_then(|v| match v {
                DbValue::Int(i) => usize::try_from(*i).ok(),
                _ => None,
            });
            Box::new(Text::with_min(size.unwrap_or(0), 0)) as Box<dyn utopia_validators::Validator>
        }),
        "range" => Arc::new(|attribute: &Document| {
            let options = attribute.get("formatOptions").cloned().unwrap_or(DbValue::Null);
            let bound = |k: &str| match &options {
                DbValue::Array(o) => match o.get_str(k) {
                    Some(DbValue::Int(i)) => php_std::value::Number::Int(*i),
                    Some(DbValue::Float(f)) => php_std::value::Number::Float(*f),
                    _ => php_std::value::Number::Int(0),
                },
                _ => php_std::value::Number::Int(0),
            };
            Box::new(Range::with_format(bound("min"), bound("max"), Type::Integer))
                as Box<dyn utopia_validators::Validator>
        }),
        "enum" => Arc::new(|attribute: &Document| {
            let elements: Vec<php_std::Value> = match attribute.get("formatOptions") {
                Some(DbValue::Array(o)) => match o.get_str("elements") {
                    Some(DbValue::Array(e)) => e.values().map(utopia_database::value::to_plain).collect(),
                    _ => Vec::new(),
                },
                _ => Vec::new(),
            };
            let list = WhiteList::with(elements, true, Type::String).unwrap_or_else(|_| WhiteList::strict(&[]));
            Box::new(list) as Box<dyn utopia_validators::Validator>
        }),
        _ => return None,
    })
}

pub async fn call(op: &str, args: &Value, session: &mut Session) -> OpResult {
    let a = args;
    match op {
        "validator.new" => match built(a)? {
            Ok(v) => Ok(Outcome::Ok(session.handle(v))),
            Err(e) => Ok(e),
        },
        "validator.check" => match built(a)? {
            Ok(v) => validate(&v, a.get("value").unwrap_or(&Value::Null)),
            Err(e) => Ok(e),
        },
        "validator.is_valid" => {
            let v = session.get::<V>(a.get("validator").unwrap_or(&Value::Null))?;
            validate(v, a.get("value").unwrap_or(&Value::Null))
        }
        "validator.description" => {
            let v = session.get::<V>(a.get("validator").unwrap_or(&Value::Null))?;
            Ok(Outcome::Ok(json!(v.description())))
        }
        "validator.meta" => {
            let v = session.get::<V>(a.get("validator").unwrap_or(&Value::Null))?;
            Ok(Outcome::Ok(v.meta()))
        }
        "validator.call" => {
            let v = session.get::<V>(a.get("validator").unwrap_or(&Value::Null))?;
            let method = str_arg(a, "method").unwrap_or("");
            let doc = codec::document(a.get("value").unwrap_or(&Value::Null))?;
            let result: Valid = match v {
                V::Attribute(v) => match method {
                    "checkDuplicateId" => v.check_duplicate_id(&doc),
                    "checkDuplicateInSchema" => v.check_duplicate_in_schema(&doc),
                    "checkRequiredFilters" => v.check_required_filters(&doc),
                    "checkFormat" => v.check_format(&doc),
                    "checkAttributeLimits" => v.check_attribute_limits(&doc),
                    "checkType" => v.check_type(&doc),
                    "checkDefaultValue" => v.check_default_value(&doc),
                    _ => return Err(Fault::new(format!("no check method `{method}`"))),
                },
                V::Index(v) => Ok(match method {
                    "checkValidIndex" => v.check_valid_index(&doc),
                    "checkValidAttributes" => v.check_valid_attributes(&doc),
                    "checkEmptyIndexAttributes" => v.check_empty_index_attributes(&doc),
                    "checkDuplicatedAttributes" => v.check_duplicated_attributes(&doc),
                    "checkFulltextIndexNonString" => v.check_fulltext_index_non_string(&doc),
                    "checkArrayIndexes" => v.check_array_indexes(&doc),
                    "checkIndexLengths" => v.check_index_lengths(&doc),
                    "checkReservedNames" => v.check_reserved_names(&doc),
                    "checkSpatialIndexes" => v.check_spatial_indexes(&doc),
                    "checkNonSpatialIndexOnSpatialAttributes" => v.check_non_spatial_index_on_spatial_attributes(&doc),
                    "checkVectorIndexes" => v.check_vector_indexes(&doc),
                    "checkTrigramIndexes" => v.check_trigram_indexes(&doc),
                    "checkKeyUniqueFulltextSupport" => v.check_key_unique_fulltext_support(&doc),
                    "checkMultipleFulltextIndexes" => v.check_multiple_fulltext_indexes(&doc),
                    "checkIdenticalIndexes" => v.check_identical_indexes(&doc),
                    "checkObjectIndexes" => v.check_object_indexes(&doc),
                    "checkTTLIndexes" => v.check_ttl_indexes(&doc),
                    _ => return Err(Fault::new(format!("no check method `{method}`"))),
                }),
                _ => return Err(Fault::new(format!("no check method `{method}`"))),
            };
            match result {
                Ok(valid) => Ok(Outcome::Ok(json!({"valid": valid, "description": v.description()}))),
                Err(e) => Ok(codec::error(&e)),
            }
        }
        "validator.big_int" => {
            let value = str_arg(a, "value").unwrap_or("").as_bytes();
            let signed = bool_arg(a, "signed", true);
            Ok(Outcome::Ok(match str_arg(a, "fn").unwrap_or("") {
                "isIntegerString" => json!(BigInt::is_integer_string(value, signed)),
                "fitsPhpInt" => json!(BigInt::fits_php_int(value, signed)),
                "fitsBigIntRange" => {
                    json!(BigInt::fits_big_int_range(value, signed, bool_arg(a, "supportUnsigned64Bit", true)))
                }
                "normalizeUnsignedString" => crate::adapter::bytes_value(&BigInt::normalize_unsigned_string(value)),
                "compareUnsignedStrings" => {
                    json!(BigInt::compare_unsigned_strings(value, str_arg(a, "other").unwrap_or("").as_bytes()))
                }
                "formatIntegerString" => json!(BigInt::format_integer_string(str_arg(a, "value").unwrap_or(""))),
                _ => return Err(Fault::new("unknown BigInt function")),
            }))
        }
        "validator.spatial_wkt" => {
            Ok(Outcome::Ok(json!(Spatial::is_wkt_string(str_arg(a, "value").unwrap_or("").as_bytes()))))
        }
        "validator.format_add" => {
            let factory = format_factory(str_arg(a, "factory").unwrap_or(""))
                .ok_or_else(|| Fault::new("unknown format factory"))?;
            formats::add(str_arg(a, "name").unwrap_or(""), factory, str_arg(a, "type").unwrap_or(""));
            Ok(Outcome::Ok(Value::Null))
        }
        "validator.format_remove" => {
            formats::remove(str_arg(a, "name").unwrap_or(""));
            Ok(Outcome::Ok(Value::Null))
        }
        "validator.format_has" => {
            Ok(Outcome::Ok(json!(formats::has(str_arg(a, "name").unwrap_or(""), str_arg(a, "type").unwrap_or("")))))
        }
        "validator.format_get" => {
            match formats::get(str_arg(a, "name").unwrap_or(""), str_arg(a, "type").unwrap_or("")) {
                Ok(f) => Ok(Outcome::Ok(json!({"type": f.kind}))),
                Err(e) => Ok(codec::error(&e)),
            }
        }
        "validator.formats" => {
            let all = formats::all();
            if all.is_empty() {
                return Ok(Outcome::Ok(json!([])));
            }
            let mut m = Map::new();
            for f in all {
                m.insert(f.name, json!(f.kind));
            }
            Ok(Outcome::Ok(Value::Object(m)))
        }
        "role.parse" => Ok(match Role::parse(str_arg(a, "role").unwrap_or("")) {
            Ok(r) => Outcome::Ok(role_json(&r)),
            Err(e) => codec::error(&e),
        }),
        "role.new" => Ok(Outcome::Ok(role_json(&role_of(a)))),
        "role.of" => {
            let id = str_arg(a, "identifier").unwrap_or("");
            let dimension = str_arg(a, "dimension").unwrap_or("");
            let r = match str_arg(a, "kind").unwrap_or("") {
                "any" => Role::any(),
                "guests" => Role::guests(),
                "users" => Role::users(dimension),
                "user" => Role::user(id, dimension),
                "team" => Role::team(id, dimension),
                "member" => Role::member(id),
                "label" => Role::label(id),
                _ => return Err(Fault::new("unknown role factory")),
            };
            Ok(Outcome::Ok(role_json(&r)))
        }
        "permission.parse" => Ok(match Permission::parse(str_arg(a, "permission").unwrap_or("")) {
            Ok(p) => Outcome::Ok(permission_json(&p)),
            Err(e) => codec::error(&e),
        }),
        "permission.new" => {
            let kind = PermissionType::from_name(str_arg(a, "permission").unwrap_or(""))
                .ok_or_else(|| Fault::new("unknown permission type (a deviation)"))?;
            Ok(Outcome::Ok(permission_json(&Permission::new(kind, role_of(a)))))
        }
        "permission.of" => {
            let r = role_of(a);
            Ok(Outcome::Ok(json!(match str_arg(a, "kind").unwrap_or("") {
                "read" => Permission::read(&r),
                "create" => Permission::create(&r),
                "update" => Permission::update(&r),
                "delete" => Permission::delete(&r),
                "write" => Permission::write(&r),
                _ => return Err(Fault::new("unknown permission factory")),
            })))
        }
        "permission.aggregate" => {
            let permissions = match a.get("permissions") {
                None | Some(Value::Null) => return Ok(Outcome::Ok(Value::Null)),
                Some(p) => codec::list(p)?.into_iter().map(plain_string).collect::<Vec<String>>(),
            };
            let allowed: Vec<PermissionType> = match a.get("allowed") {
                Some(l) => {
                    codec::list(l)?.into_iter().filter_map(|v| PermissionType::from_name(&plain_string(v))).collect()
                }
                None => PermissionType::ALL.to_vec(),
            };
            Ok(match Permission::aggregate(&permissions, &allowed) {
                Ok(list) => Outcome::Ok(json!(list)),
                Err(e) => codec::error(&e),
            })
        }
        _ => Err(Fault::new(format!("database: unknown operation `{op}`"))),
    }
}

/// `strval($value)` of a JSON scalar.
fn plain_string(v: &Value) -> String {
    match v {
        Value::String(s) => s.clone(),
        Value::Bool(true) => "1".to_owned(),
        Value::Bool(false) | Value::Null => String::new(),
        Value::Number(n) => match n.as_i64() {
            Some(i) => i.to_string(),
            None => php_std::number::to_string(n.as_f64().unwrap_or(0.0)),
        },
        other => other.to_string(),
    }
}
