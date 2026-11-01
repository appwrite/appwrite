//! The building blocks every statement uses: identifiers, table names,
//! tenant conditions, column types, parameter types, and the mapping of
//! driver errors onto the library's errors.
//!
//! PHP: the protected helpers of `Utopia\Database\Adapter\SQL` and
//! `Adapter\MariaDB` / `Adapter\MySQL` (`quote`, `getSQLTable`,
//! `getTenantQuery`, `getSQLType`, `getPDOType`, `processException`,
//! `getViolatedKey`, ...).

use php_std::pcre;

use super::MariaDB;
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

/// `getPDOType($value)` (MariaDB): strings and floats `PARAM_STR`, integers
/// and booleans `PARAM_INT`, `null` `PARAM_NULL`.
pub fn pdo_type(value: &Value) -> Result<ParamType> {
    Ok(match value {
        Value::Str(_) | Value::Float(_) => ParamType::Str,
        Value::Bool(_) | Value::Int(_) => ParamType::Int,
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

/// `quote($string)` (MariaDB): `` `string` ``.
pub fn quote(s: &str) -> String {
    format!("`{s}`")
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

/// The value as PHP interpolates it into a string (`"{$value}"`).
pub fn interpolate(value: &Value) -> String {
    crate::value::to_text(value)
}

/// `getMaxVarcharLength()`.
pub const MAX_VARCHAR_LENGTH: i64 = 16381;

/// `getSpatialSQLType($type, $required)`: MariaDB's `POINT(4326)` or
/// MySQL's `POINT SRID 4326`, `NULL`/`NOT NULL` (spatial indexes do not
/// take null values).
fn spatial_sql_type(kind: &str, required: bool, mysql: bool) -> String {
    let nullability = if required { " NOT NULL" } else { " NULL" };
    let name = match kind {
        Database::VAR_POINT => "POINT",
        Database::VAR_LINESTRING => "LINESTRING",
        Database::VAR_POLYGON => "POLYGON",
        _ => return String::new(),
    };
    if mysql {
        format!("{name} SRID {}{nullability}", Database::DEFAULT_SRID)
    } else {
        format!("{name}({}){nullability}", Database::DEFAULT_SRID)
    }
}

/// `getSQLType($type, $size, $signed, $array, $required)` (MariaDB).
pub fn sql_type(kind: &str, size: i64, signed: bool, array: bool, required: bool, mysql: bool) -> Result<String> {
    if Database::SPATIAL_TYPES.contains(&kind) {
        return Ok(spatial_sql_type(kind, required, mysql));
    }
    if array {
        return Ok("JSON".to_owned());
    }
    let unsigned = if signed { "" } else { " UNSIGNED" };
    Ok(match kind {
        Database::VAR_ID => "BIGINT UNSIGNED".to_owned(),
        Database::VAR_STRING => {
            if size > Database::MAX_MEDIUMTEXT_BYTES {
                "LONGTEXT".to_owned()
            } else if size > Database::MAX_TEXT_BYTES {
                "MEDIUMTEXT".to_owned()
            } else if size > MAX_VARCHAR_LENGTH {
                "TEXT".to_owned()
            } else {
                format!("VARCHAR({size})")
            }
        }
        Database::VAR_VARCHAR => {
            if size <= 0 {
                return Err(Error::database(format!(
                    "VARCHAR size {size} is invalid; must be > 0. Use TEXT, MEDIUMTEXT, or LONGTEXT instead."
                )));
            }
            if size > MAX_VARCHAR_LENGTH {
                return Err(Error::database(format!(
                    "VARCHAR size {size} exceeds maximum varchar length {MAX_VARCHAR_LENGTH}. Use TEXT, MEDIUMTEXT, or LONGTEXT instead."
                )));
            }
            format!("VARCHAR({size})")
        }
        Database::VAR_TEXT => "TEXT".to_owned(),
        Database::VAR_MEDIUMTEXT => "MEDIUMTEXT".to_owned(),
        Database::VAR_LONGTEXT => "LONGTEXT".to_owned(),
        Database::VAR_INTEGER => {
            if size >= 8 {
                format!("BIGINT{unsigned}")
            } else {
                format!("INT{unsigned}")
            }
        }
        Database::VAR_BIGINT => format!("BIGINT{unsigned}"),
        Database::VAR_FLOAT => format!("DOUBLE{unsigned}"),
        Database::VAR_BOOLEAN => "TINYINT(1)".to_owned(),
        Database::VAR_RELATIONSHIP => "VARCHAR(255)".to_owned(),
        Database::VAR_DATETIME => "DATETIME(3)".to_owned(),
        _ => {
            return Err(Error::database(format!(
                "Unknown type: {kind}. Must be one of {}, {}, {}, {}, {}, {}, {}, {}, {}, {}, {}, {}, {}, {}",
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
                Database::VAR_POINT,
                Database::VAR_LINESTRING,
                Database::VAR_POLYGON
            )));
        }
    })
}

/// `processException($e)` (MariaDB, and MySQL's codes first when `mysql`):
/// driver errors with a known SQLSTATE and code become the library's
/// errors; others stay `PDOException`s.
pub fn process_exception(e: PdoError, mysql: bool) -> Error {
    let code = e.native;
    let state = e.sqlstate.as_str();
    let is = |s: &str, c: i64| state == s && code == Some(c);
    let mapped = |kind: Kind, message: &str| Error::new(kind, message);
    if mysql {
        if is("HY000", 1366) {
            return mapped(Kind::Character, "Invalid character");
        }
        if is("HY000", 3024) || is("HY000", 3699) {
            return mapped(Kind::Timeout, "Query timed out");
        }
        if is("HY000", 3837) {
            return mapped(Kind::Dependency, "Attribute cannot be deleted because it is used in an index");
        }
        if is("22004", 1138) {
            return mapped(Kind::Structure, "Attribute does not allow null values");
        }
    }
    if is("22007", 1366) {
        return mapped(Kind::Character, "Invalid character");
    }
    if is("70100", 1969) {
        return mapped(Kind::Timeout, "Query timed out");
    }
    if is("42S01", 1050) {
        return mapped(Kind::Duplicate, "Collection already exists");
    }
    if is("42S21", 1060) {
        return mapped(Kind::Duplicate, "Attribute already exists");
    }
    if is("42000", 1061) {
        return mapped(Kind::Duplicate, "Index already exists");
    }
    if is("42000", 1071) {
        return mapped(Kind::Index, "Index key length exceeds the maximum");
    }
    if is("23000", 1062) {
        let key = violated_key(&e.message);
        if key.as_deref() == Some("_index1") {
            return mapped(Kind::Duplicate, "Duplicate permissions for document");
        }
        if let Some(k) = &key
            && k != "_uid"
            && k != "PRIMARY"
        {
            return mapped(Kind::Unique, "Unique index violation");
        }
        return mapped(Kind::Duplicate, "Document already exists");
    }
    if is("22001", 1406) || is("01000", 1265) {
        return mapped(Kind::Truncate, "Resize would result in data truncation");
    }
    if is("22003", 1264) || is("22003", 1690) {
        return mapped(Kind::Limit, "Value out of range");
    }
    if is("HY000", 1690) {
        return mapped(Kind::Limit, "Value is out of range");
    }
    if is("42000", 1049) {
        return mapped(Kind::NotFound, "Database not found");
    }
    if is("42S02", 1049) || is("42S02", 1051) {
        return mapped(Kind::NotFound, "Collection not found");
    }
    if is("42000", 1091) {
        return mapped(Kind::NotFound, "Attribute not found");
    }
    e.into_error()
}

/// `getViolatedKey($message)`: the index name of a duplicate entry error
/// (`for key 'movies._uid'` is `_uid`), or `None`.
pub fn violated_key(message: &str) -> Option<String> {
    let result = pcre::preg_match(br"/for key '(?:[^'.]*\.)?([^']+)'/", message.as_bytes(), 0, 0).ok()?;
    if !matches!(result.value.result, pcre::Value::Int(1)) {
        return None;
    }
    let matches = result.value.matches?;
    let pcre::Value::Str(group) = matches.get(&pcre::Key::Int(1))? else { return None };
    Some(String::from_utf8_lossy(group).into_owned())
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
    if find {
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

impl MariaDB {
    /// `getSQLTable($name)`: `` `database`.`namespace_name` ``.
    pub(super) fn sql_table(&self, name: &str) -> String {
        let (database, namespace) = {
            let s = self.state.lock();
            (s.database.clone(), s.namespace.clone())
        };
        format!("{}.{}", quote(&database), quote(&format!("{namespace}_{}", filter(name))))
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

    /// `$stmt->execute()`, returning rows and count.
    pub(super) async fn run(&self, stmt: &Statement) -> Result<Executed, PdoFailure> {
        let mut pdo = self.pdo.lock().await;
        pdo.execute(stmt).await
    }

    /// A failed statement through `processException`.
    pub(super) fn processed(&self, e: PdoFailure) -> Error {
        match e {
            PdoFailure::Pdo(e) => process_exception(e, self.mysql()),
            PdoFailure::Other(e) => e,
        }
    }

    /// A driver error through `processException`.
    pub(super) fn process(&self, e: PdoError) -> Error {
        process_exception(e, self.mysql())
    }
}

/// A failed statement as it escapes unprocessed (`PDOException`).
pub fn raw(e: PdoFailure) -> Error {
    match e {
        PdoFailure::Pdo(e) => e.into_error(),
        PdoFailure::Other(e) => e,
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn reads_violated_keys() {
        assert_eq!(violated_key("Duplicate entry 'x' for key 'movies._uid'").as_deref(), Some("_uid"));
        assert_eq!(violated_key("Duplicate entry '1' for key 'PRIMARY'").as_deref(), Some("PRIMARY"));
        assert_eq!(violated_key("nothing"), None);
    }

    #[test]
    fn maps_types() {
        assert_eq!(sql_type(Database::VAR_INTEGER, 4, false, false, false, false).unwrap(), "INT UNSIGNED");
        assert_eq!(sql_type(Database::VAR_POINT, 0, true, false, true, false).unwrap(), "POINT(4326) NOT NULL");
        assert_eq!(sql_type(Database::VAR_POINT, 0, true, false, false, true).unwrap(), "POINT SRID 4326 NULL");
        assert_eq!(sql_type(Database::VAR_STRING, 20000, true, false, false, false).unwrap(), "TEXT");
    }
}
