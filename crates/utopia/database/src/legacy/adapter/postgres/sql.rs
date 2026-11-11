//! The building blocks every statement uses: identifiers, table names,
//! tenant conditions, column types, parameter types, statement execution
//! with the statement timeout, and the mapping of driver errors onto the
//! library's errors.
//!
//! PHP: the protected helpers of `Utopia\Database\Adapter\SQL` and
//! `Adapter\Postgres` (`quote`, `getSQLTable`, `getShortKey`,
//! `getTenantQuery`, `getSQLType`, `getPDOType`, `execute`,
//! `processException`, ...).

use php_std::pcre;

use super::Postgres;
use super::pdo::{Executed, ParamType, PdoError, PdoFailure, Statement};
use crate::database::Database;
use crate::document::Document;
use crate::error::{Error, Kind, Result};
use crate::value::{Array, Str, Value};

/// Bound values of a statement in the making, by placeholder, in the order
/// they were added (`$binds`).
#[derive(Clone, Debug, Default)]
pub struct Binds(pub Vec<(String, Value)>);

impl Binds {
    /// `$binds[$key] = $value`.
    pub fn set(&mut self, key: impl Into<String>, value: Value) {
        let key = key.into();
        match self.0.iter_mut().find(|(k, _)| *k == key) {
            Some(slot) => slot.1 = value,
            None => self.0.push((key, value)),
        }
    }
}

/// `getPDOType($value)`.
pub fn pdo_type(value: &Value) -> Result<ParamType> {
    Ok(match value {
        Value::Str(_) | Value::Float(_) => ParamType::Str,
        Value::Bool(_) => ParamType::Bool,
        Value::Int(_) => ParamType::Int,
        Value::Null => ParamType::Null,
        Value::Array(_) => return Err(Error::database("Unknown PDO Type for array")),
        Value::Object(_) | Value::Ext(_) => return Err(Error::database("Unknown PDO Type for object")),
    })
}

/// `$stmt->bindValue($key, $value, $this->getPDOType($value))`.
pub fn bind_typed(stmt: &mut Statement, key: &str, value: Value) -> Result<()> {
    let ty = pdo_type(&value)?;
    stmt.bind(key, value, ty);
    Ok(())
}

/// `zend_zval_value_name()`: how a `TypeError` names a value's type.
pub fn value_name(v: &Value) -> &str {
    match v {
        Value::Null => "null",
        Value::Bool(true) => "true",
        Value::Bool(false) => "false",
        Value::Int(_) => "int",
        Value::Float(_) => "float",
        Value::Str(_) => "string",
        Value::Array(_) => "array",
        Value::Object(_) => "stdClass",
        Value::Ext(x) => php_std::Extension::class(x),
    }
}

/// `quote($string)`: `"string"`.
pub fn quote(s: &str) -> String {
    format!("\"{s}\"")
}

/// `filter($value)`: keeps `[A-Za-z0-9_-]`.
pub fn filter(value: &str) -> String {
    value.chars().filter(|c| c.is_ascii_alphanumeric() || *c == '_' || *c == '-').collect()
}

/// `getInternalKeyForAttribute($attribute)`.
pub fn internal_key(attribute: &str) -> &str {
    match attribute {
        "$id" => "_uid",
        "$sequence" => "_id",
        "$collection" => "_collection",
        "$tenant" => "_tenant",
        "$createdAt" => "_createdAt",
        "$updatedAt" => "_updatedAt",
        "$deletedAt" => "_deletedAt",
        "$permissions" => "_permissions",
        other => other,
    }
}

/// `Postgres::MAX_IDENTIFIER_NAME`.
pub const MAX_IDENTIFIER_NAME: usize = 63;

/// `getShortKey($key)`: keys longer than 63 bytes become their MD5, with
/// the part after the last `_` kept as a suffix when it fits.
pub fn short_key(key: &str) -> String {
    if key.len() <= MAX_IDENTIFIER_NAME {
        return key.to_owned();
    }
    let suffix = key.rfind('_').map(|i| &key[i + 1..]).unwrap_or("");
    let hash = php_std::encoding::bin2hex(&md5(key.as_bytes()));
    if !suffix.is_empty() {
        let hashed = format!("{hash}_{suffix}");
        if hashed.len() <= MAX_IDENTIFIER_NAME {
            return hashed;
        }
    }
    hash[..MAX_IDENTIFIER_NAME.min(hash.len())].to_owned()
}

fn md5(input: &[u8]) -> [u8; 16] {
    use md5::{Digest, Md5};
    Md5::digest(input).into()
}

/// The value as PHP interpolates it into a string (`"{$value}"`).
pub fn interpolate(value: &Value) -> String {
    crate::value::to_text(value)
}

/// `getSQLType($type, $size, $signed, $array, $required)`.
pub fn sql_type(kind: &str, size: i64, _signed: bool, array: bool, _required: bool) -> Result<String> {
    if array {
        return Ok("JSONB".to_owned());
    }
    Ok(match kind {
        Database::VAR_ID => "BIGINT".to_owned(),
        Database::VAR_STRING => {
            if size > MAX_VARCHAR_LENGTH {
                "TEXT".to_owned()
            } else {
                format!("VARCHAR({size})")
            }
        }
        Database::VAR_VARCHAR => format!("VARCHAR({size})"),
        Database::VAR_TEXT | Database::VAR_MEDIUMTEXT | Database::VAR_LONGTEXT => "TEXT".to_owned(),
        Database::VAR_INTEGER => {
            if size >= 8 {
                "BIGINT".to_owned()
            } else {
                "INTEGER".to_owned()
            }
        }
        Database::VAR_BIGINT => "BIGINT".to_owned(),
        Database::VAR_FLOAT => "DOUBLE PRECISION".to_owned(),
        Database::VAR_BOOLEAN => "BOOLEAN".to_owned(),
        Database::VAR_RELATIONSHIP => "VARCHAR(255)".to_owned(),
        Database::VAR_DATETIME => "TIMESTAMP(3)".to_owned(),
        Database::VAR_OBJECT => "JSONB".to_owned(),
        Database::VAR_POINT => format!("GEOMETRY(POINT,{})", Database::DEFAULT_SRID),
        Database::VAR_LINESTRING => format!("GEOMETRY(LINESTRING,{})", Database::DEFAULT_SRID),
        Database::VAR_POLYGON => format!("GEOMETRY(POLYGON,{})", Database::DEFAULT_SRID),
        Database::VAR_VECTOR => format!("VECTOR({size})"),
        _ => {
            return Err(Error::database(format!(
                "Unknown Type: {kind}. Must be one of {}, {}, {}, {}, {}, {}, {}, {}, {}, {}, {}, {}, {}, {}, {}",
                Database::VAR_STRING,
                Database::VAR_VARCHAR,
                Database::VAR_TEXT,
                Database::VAR_MEDIUMTEXT,
                Database::VAR_LONGTEXT,
                Database::VAR_INTEGER,
                Database::VAR_BIGINT,
                Database::VAR_FLOAT,
                Database::VAR_BOOLEAN,
                Database::VAR_DATETIME,
                Database::VAR_RELATIONSHIP,
                Database::VAR_OBJECT,
                Database::VAR_POINT,
                Database::VAR_LINESTRING,
                Database::VAR_POLYGON
            )));
        }
    })
}

/// `getMaxVarcharLength()`.
pub const MAX_VARCHAR_LENGTH: i64 = 16381;

/// `processException($e)` (Postgres): driver errors with a known SQLSTATE
/// become the library's errors; others stay `PDOException`s.
pub fn process_exception(e: PdoError) -> Error {
    let server = e.native == Some(7);
    let mapped = |kind: Kind, message: &str| Error::new(kind, message);
    if server {
        match e.sqlstate.as_str() {
            "57014" => return mapped(Kind::Timeout, "Query timed out"),
            "42P07" => return mapped(Kind::Duplicate, "Collection already exists"),
            "42701" => return mapped(Kind::Duplicate, "Attribute already exists"),
            "23505" => {
                let columns = violated_columns(&e.message);
                if let Some(c) = &columns
                    && c != &["_uid"]
                    && c != &["_tenant", "_uid"]
                {
                    return mapped(Kind::Unique, "Unique index violation");
                }
                return mapped(Kind::Duplicate, "Document already exists");
            }
            "22001" => return mapped(Kind::Truncate, "Resize would result in data truncation"),
            "22003" => return mapped(Kind::Limit, "Numeric value out of range"),
            "2201F" => return mapped(Kind::Limit, "Value out of range"),
            "22008" => return mapped(Kind::Limit, "Datetime field overflow"),
            "54000" if e.message.contains("index row") => {
                return mapped(Kind::Limit, "Index row size exceeds the maximum");
            }
            "42P01" => return mapped(Kind::NotFound, "Collection not found"),
            "42703" => return mapped(Kind::NotFound, "Attribute not found"),
            _ => {}
        }
    }
    e.into_error()
}

/// `getViolatedColumns($message)`: the sorted columns of
/// `Key (a, b)=(...)`, or `None` when the message has none.
pub fn violated_columns(message: &str) -> Option<Vec<String>> {
    let result = pcre::preg_match(br"/Key \(([^)]+)\)=/", message.as_bytes(), 0, 0).ok()?;
    if !matches!(result.value.result, pcre::Value::Int(1)) {
        return None;
    }
    let matches = result.value.matches?;
    let pcre::Value::Str(group) = matches.get(&pcre::Key::Int(1))? else { return None };
    let mut columns: Vec<String> = group
        .split(|b| *b == b',')
        .map(|c| String::from_utf8_lossy(php_std::string::trim(c, b" \t\"'")).into_owned())
        .collect();
    columns.sort_by(|a, b| php_std::string::strcmp(a.as_bytes(), b.as_bytes()).cmp(&0));
    Some(columns)
}

/// `getSpatialAttributes($collection)`: ids of the collection's spatial
/// attribute documents.
pub fn spatial_attributes(collection: &Document) -> Vec<String> {
    let mut out = Vec::new();
    if let Some(Value::Array(attributes)) = collection.get("attributes") {
        for attr in attributes.values() {
            if let Some(d) = crate::value::as_document(attr)
                && let Some(Value::Str(t)) = d.get("type")
                && Database::SPATIAL_TYPES.iter().any(|s| s.as_bytes() == t.as_bytes())
            {
                out.push(d.id().to_owned());
            }
        }
    }
    out
}

/// `json_encode($value)`: the JSON text, or `false` when it fails.
pub fn json_encode(value: &Value) -> Value {
    match php_std::json::encode(value, php_std::json::Flags::NONE, php_std::json::DEFAULT_DEPTH) {
        Ok(s) => Value::Str(Str::from(s)),
        Err(_) => Value::Bool(false),
    }
}

/// `json_encode($value)` as text (`""` when it fails, as interpolating
/// `false` gives).
pub fn json_text(value: &Value) -> String {
    match json_encode(value) {
        Value::Str(s) => String::from_utf8_lossy(s.as_bytes()).into_owned(),
        _ => String::new(),
    }
}

/// `$document->getSequence()`: `null`, or the value as a string (the
/// return type coerces it; an array is a `TypeError`).
pub fn sequence(document: &Document) -> Result<Option<Value>> {
    match document.raw("$sequence") {
        None | Some(Value::Null) => Ok(None),
        Some(v @ Value::Str(_)) => Ok(Some(v.clone())),
        Some(v @ (Value::Int(_) | Value::Float(_) | Value::Bool(_))) => {
            Ok(Some(Value::Str(v.to_php_string().unwrap_or(Str::EMPTY))))
        }
        Some(other) => Err(Error::new(
            Kind::TypeError,
            format!(
                "Utopia\\Database\\Document::getSequence(): Return value must be of type ?string, {} returned",
                other.debug_type()
            ),
        )),
    }
}

/// `!empty($document->getSequence())`.
pub fn has_sequence(document: &Document) -> Result<bool> {
    Ok(sequence(document)?.is_some_and(|v| v.truthy()))
}

/// A `?string` getter of a document (`getCreatedAt()`, `getUpdatedAt()`):
/// the raw value coerced to a string.
pub fn string_attribute(document: &Document, key: &str, getter: &str) -> Result<Value> {
    match document.raw(key) {
        None | Some(Value::Null) => Ok(Value::Null),
        Some(v @ Value::Str(_)) => Ok(v.clone()),
        Some(v @ (Value::Int(_) | Value::Float(_) | Value::Bool(_))) => {
            Ok(Value::Str(v.to_php_string().unwrap_or(Str::EMPTY)))
        }
        Some(other) => Err(Error::new(
            Kind::TypeError,
            format!(
                "Utopia\\Database\\Document::{getter}(): Return value must be of type ?string, {} returned",
                other.debug_type()
            ),
        )),
    }
}

/// `$document->getId()`.
pub fn id_value(document: &Document) -> Value {
    match document.raw("$id") {
        None | Some(Value::Null) => Value::Str(Str::EMPTY),
        Some(v) => v.clone(),
    }
}

/// `$document->getTenant()`.
pub fn tenant_value(document: &Document) -> Value {
    document.tenant().unwrap_or(Value::Null)
}

/// A fetched row as a document: internal columns renamed to their `$`
/// attributes, each renamed key moving to the end, in the order
/// `getDocument()` (`find = false`) or `find()` renames them.
pub fn row_to_document(mut row: Array, find: bool) -> Result<Document> {
    let vector_distance = find;
    let renames: &[(&str, &str)] = if find {
        &[
            ("_uid", "$id"),
            ("_id", "$sequence"),
            ("_tenant", "$tenant"),
            ("_createdAt", "$createdAt"),
            ("_updatedAt", "$updatedAt"),
        ]
    } else {
        &[
            ("_id", "$sequence"),
            ("_uid", "$id"),
            ("_tenant", "$tenant"),
            ("_createdAt", "$createdAt"),
            ("_updatedAt", "$updatedAt"),
            ("_deletedAt", "$deletedAt"),
        ]
    };
    for (from, to) in renames {
        let key = crate::value::ArrayKey::from(*from);
        if let Some(v) = row.remove(&key) {
            row.set(crate::value::ArrayKey::from(*to), v);
        }
    }
    let key = crate::value::ArrayKey::from("_permissions");
    if let Some(v) = row.remove(&key) {
        let json = match &v {
            Value::Str(s) => s.as_bytes().to_vec(),
            _ => b"[]".to_vec(),
        };
        let decoded =
            php_std::json::decode(&json, Some(true), php_std::json::DEFAULT_DEPTH, php_std::json::Flags::NONE)
                .map(crate::value::from_plain)
                .unwrap_or(Value::Null);
        row.set(crate::value::ArrayKey::from("$permissions"), decoded);
    }
    if vector_distance {
        let key = crate::value::ArrayKey::from("_distance");
        if let Some(v) = row.remove(&key) {
            let numeric = match &v {
                Value::Str(s) => php_std::value::numeric_bytes_ex(s.as_bytes()).map(|(n, _)| n.as_f64()),
                _ => None,
            };
            row.set(crate::value::ArrayKey::from(Database::VECTOR_DISTANCE), numeric.map_or(Value::Null, Value::Float));
        }
    }
    Document::new(row)
}

impl Postgres {
    /// `getSQLTable($name)`: `"schema"."namespace_name"`, shortened.
    pub(super) fn sql_table(&self, name: &str) -> String {
        let (database, namespace) = {
            let s = self.state.lock();
            (s.database.clone(), s.namespace.clone())
        };
        let table = short_key(&format!("{namespace}_{}", filter(name)));
        format!("{}.{}", quote(&database), quote(&table))
    }

    /// `getTenantQuery($collection, $alias, $tenantCount, $condition)`.
    pub(super) fn tenant_query_with(
        &self,
        collection: &str,
        alias: &str,
        tenant_count: usize,
        condition: &str,
    ) -> String {
        if !self.state.lock().shared_tables {
            return String::new();
        }
        let (dot, alias) = if alias.is_empty() { ("", String::new()) } else { (".", quote(alias)) };
        let bindings = if tenant_count == 0 {
            ":_tenant".to_owned()
        } else {
            (0..tenant_count).map(|i| format!(":_tenant_{i}")).collect::<Vec<_>>().join(",")
        };
        let or_is_null =
            if collection == Database::METADATA { format!(" OR {alias}{dot}_tenant IS NULL") } else { String::new() };
        format!("{condition} ({alias}{dot}_tenant IN ({bindings}) {or_is_null})")
    }

    /// `getTenantQuery($collection)`.
    pub(super) fn tenant_sql(&self, collection: &str) -> String {
        self.tenant_query_with(collection, "", 0, "AND")
    }

    pub(super) fn shared(&self) -> bool {
        self.state.lock().shared_tables
    }

    pub(super) fn tenant_of_state(&self) -> Value {
        self.state.lock().tenant.clone()
    }

    pub(super) fn trigger_sql(&self, event: &str, sql: String) -> String {
        self.state.trigger(event, sql)
    }

    pub(super) fn depth(&self) -> i64 {
        self.state.lock().in_transaction
    }

    /// `$stmt->execute()` (no statement timeout), returning rows and count.
    pub(super) async fn run(&self, stmt: &Statement) -> Result<Executed, PdoFailure> {
        let mut pdo = self.pdo.lock().await;
        pdo.execute(stmt).await
    }

    /// `execute($stmt)`: the statement with the adapter's statement timeout
    /// (`SET LOCAL` inside a transaction, `SET` and `RESET` around it
    /// outside one; see [`Pdo::execute_timed`]).
    pub(super) async fn run_timed(&self, stmt: &Statement) -> Result<Executed, PdoFailure> {
        let (depth, timeout) = {
            let s = self.state.lock();
            (s.in_transaction, s.timeout)
        };
        let mut pdo = self.pdo.lock().await;
        pdo.execute_timed(timeout, depth, stmt).await
    }
}

/// A failed statement through `processException`.
pub fn processed(e: PdoFailure) -> Error {
    match e {
        PdoFailure::Pdo(e) => process_exception(e),
        PdoFailure::Other(e) => e,
    }
}

/// A failed statement as it escapes unprocessed (`PDOException`).
pub fn raw(e: PdoFailure) -> Error {
    match e {
        PdoFailure::Pdo(e) => e.into_error(),
        PdoFailure::Other(e) => e,
    }
}
