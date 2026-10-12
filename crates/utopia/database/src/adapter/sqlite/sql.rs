//! The building blocks every statement uses: identifiers, table names,
//! tenant conditions, column types, parameter types, statement execution,
//! the mapping of driver errors onto the library's errors, and geometry
//! text.
//!
//! PHP: the protected helpers of `Utopia\Database\Adapter\SQL`,
//! `Adapter\MariaDB` and `Adapter\SQLite` (`quote`, `getSQLTable`,
//! `getTenantQuery`, `getSQLType`, `getPDOType`, `processException`,
//! `convertArrayToWKT`, `decodePoint`, ...).

use php_std::format::str_to_float;
use php_std::pcre;
use php_std::string::{explode, substr, trim};

use super::Sqlite;
use super::pdo::{Executed, ParamType, PdoError, PdoFailure, Statement};
use crate::database::Database;
use crate::document::Document;
use crate::error::{Error, Kind, Result};
use crate::value::{Array, ArrayKey, Str, Value};

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

/// `getPDOType($value)` (MariaDB): strings and floats bind as strings,
/// integers and booleans as integers.
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

/// `getSQLType($type, $size, $signed, $array, $required)` (MariaDB).
pub fn sql_type(kind: &str, size: i64, signed: bool, array: bool, required: bool) -> Result<String> {
    if Database::SPATIAL_TYPES.contains(&kind) {
        return Ok(spatial_sql_type(kind, required));
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

/// `getSpatialSQLType($type, $required)` (MariaDB): SQLite has no null
/// support in spatial indexes, so the nullability is explicit.
fn spatial_sql_type(kind: &str, required: bool) -> String {
    let srid = Database::DEFAULT_SRID;
    let nullability = if required { " NOT NULL" } else { " NULL" };
    match kind {
        Database::VAR_POINT => format!("POINT({srid}){nullability}"),
        Database::VAR_LINESTRING => format!("LINESTRING({srid}){nullability}"),
        Database::VAR_POLYGON => format!("POLYGON({srid}){nullability}"),
        _ => String::new(),
    }
}

/// `processException($e)` (SQLite): driver errors SQLite reports for
/// existing and missing tables, unique violations and oversized values
/// become the library's errors; others stay `PDOException`s.
pub fn process_exception(e: PdoError) -> Error {
    let general = e.sqlstate == "HY000";
    let code = e.native;
    let message = e.message.as_bytes();
    if general && code == Some(3024) {
        return Error::new(Kind::Timeout, "Query timed out");
    }
    if general && code == Some(1) && contains_ci(message, b"already exists") {
        return Error::new(Kind::Duplicate, "Collection already exists");
    }
    if general && code == Some(1) && contains_ci(message, b"no such table") {
        return Error::new(Kind::NotFound, "Collection not found");
    }
    let constraint = e.sqlstate == "23000";
    if ((general && (code == Some(1) || code == Some(19))) || constraint)
        && (code == Some(19) || constraint || contains_ci(message, b"unique") || contains_ci(message, b"duplicate"))
    {
        let columns = violated_columns(&e.message);
        if let Some(c) = &columns
            && c != &["_uid"]
            && c != &["_tenant", "_uid"]
        {
            return Error::new(Kind::Unique, "Unique index violation");
        }
        return Error::new(Kind::Duplicate, "Document already exists");
    }
    if general && code == Some(18) {
        return Error::new(Kind::Limit, "Value too large");
    }
    e.into_error()
}

/// `stripos($haystack, $needle) !== false`.
fn contains_ci(haystack: &[u8], needle: &[u8]) -> bool {
    let haystack = haystack.to_ascii_lowercase();
    let needle = needle.to_ascii_lowercase();
    haystack.windows(needle.len()).any(|w| w == needle.as_slice())
}

/// `getViolatedColumns($message)` (SQLite): the sorted columns of
/// `UNIQUE constraint failed: t.a, t.b`, or `None` when the message has none.
pub fn violated_columns(message: &str) -> Option<Vec<String>> {
    let result = pcre::preg_match(br"/UNIQUE constraint failed:\s*(.+)/", message.as_bytes(), 0, 0).ok()?;
    if !matches!(result.value.result, pcre::Value::Int(1)) {
        return None;
    }
    let matches = result.value.matches?;
    let pcre::Value::Str(group) = matches.get(&pcre::Key::Int(1))? else { return None };
    let mut columns: Vec<String> = group
        .split(|b| *b == b',')
        .map(|c| {
            let column = match c.iter().rposition(|b| *b == b'.') {
                Some(i) => &c[i + 1..],
                None => c,
            };
            String::from_utf8_lossy(trim(column, b" \t`\"")).into_owned()
        })
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
        let key = ArrayKey::from(*from);
        if let Some(v) = row.remove(&key) {
            row.set(ArrayKey::from(*to), v);
        }
    }
    let key = ArrayKey::from("_permissions");
    if let Some(v) = row.remove(&key) {
        let json = match &v {
            Value::Str(s) => s.as_bytes().to_vec(),
            _ => b"[]".to_vec(),
        };
        let decoded =
            php_std::json::decode(&json, Some(true), php_std::json::DEFAULT_DEPTH, php_std::json::Flags::NONE)
                .map(crate::value::from_plain)
                .unwrap_or(Value::Null);
        row.set(ArrayKey::from("$permissions"), decoded);
    }
    if find {
        let key = ArrayKey::from("_distance");
        if let Some(v) = row.remove(&key) {
            let numeric = match &v {
                Value::Str(s) => php_std::value::numeric_bytes_ex(s.as_bytes()).map(|(n, _)| n.as_f64()),
                _ => None,
            };
            row.set(ArrayKey::from(Database::VECTOR_DISTANCE), numeric.map_or(Value::Null, Value::Float));
        }
    }
    Document::new(row)
}

impl Sqlite {
    /// `getSQLTable($name)` (SQLite): `` `namespace_name` ``.
    pub(super) fn sql_table(&self, name: &str) -> String {
        quote(&format!("{}_{}", self.state.lock().namespace, filter(name)))
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

    pub(super) fn namespace_of_state(&self) -> String {
        self.state.lock().namespace.clone()
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
    pub(super) fn run(&self, stmt: &Statement) -> Result<Executed, PdoFailure> {
        self.pdo().execute(stmt)
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

// ----- Geometries (SQL) -----

const WS: &[u8] = b" \n\r\t\x0B\0";

fn is_numeric(v: Option<&Value>) -> bool {
    match v {
        Some(Value::Int(_) | Value::Float(_)) => true,
        Some(Value::Str(s)) => php_std::value::numeric_bytes_ex(s.as_bytes()).is_some(),
        _ => false,
    }
}

fn at(a: &Array, i: i64) -> Option<&Value> {
    a.get(&ArrayKey::Int(i))
}

fn text(v: Option<&Value>) -> String {
    v.map(crate::value::to_text).unwrap_or_default()
}

/// `convertArrayToWKT($geometry)`: `POINT(x y)`, `LINESTRING(...)` or
/// `POLYGON((...), ...)`.
pub fn array_to_wkt(geometry: &Array) -> Result<String> {
    if geometry.len() == 2 && is_numeric(at(geometry, 0)) && is_numeric(at(geometry, 1)) {
        return Ok(format!("POINT({} {})", text(at(geometry, 0)), text(at(geometry, 1))));
    }
    let first = at(geometry, 0);
    if let Some(Value::Array(p0)) = first
        && p0.len() == 2
        && is_numeric(at(p0, 0))
    {
        let mut points = Vec::new();
        for point in geometry.values() {
            match point {
                Value::Array(p) if p.len() == 2 && is_numeric(at(p, 0)) && is_numeric(at(p, 1)) => {
                    points.push(format!("{} {}", text(at(p, 0)), text(at(p, 1))));
                }
                _ => return Err(Error::database("Invalid point format in geometry array")),
            }
        }
        return Ok(format!("LINESTRING({})", points.join(", ")));
    }
    if let Some(Value::Array(p0)) = first
        && matches!(at(p0, 0), Some(Value::Array(a)) if a.len() == 2)
    {
        let mut rings = Vec::new();
        for ring in geometry.values() {
            let Value::Array(ring) = ring else {
                return Err(Error::database("Invalid ring format in polygon geometry"));
            };
            let mut points = Vec::new();
            for point in ring.values() {
                match point {
                    Value::Array(p) if p.len() == 2 && is_numeric(at(p, 0)) && is_numeric(at(p, 1)) => {
                        points.push(format!("{} {}", text(at(p, 0)), text(at(p, 1))));
                    }
                    _ => return Err(Error::database("Invalid point format in polygon ring")),
                }
            }
            rings.push(format!("({})", points.join(", ")));
        }
        return Ok(format!("POLYGON({})", rings.join(", ")));
    }
    Err(Error::database("Unrecognized geometry array format"))
}

/// `getSpatialTypeFromWKT($wkt)`: the lowercase type before `(`.
pub fn type_from_wkt(wkt: &[u8]) -> Result<Vec<u8>> {
    let wkt = trim(wkt, WS);
    let Some(pos) = wkt.iter().position(|c| *c == b'(') else {
        return Err(Error::database("Invalid spatial type"));
    };
    Ok(trim(&wkt[..pos], WS).to_ascii_lowercase())
}

fn pair(point: &[u8]) -> Value {
    let coords = explode(b" ", trim(point, WS), i64::MAX).unwrap_or_default();
    let x = coords.first().map_or(0.0, |c| str_to_float(c));
    let y = coords.get(1).map_or(0.0, |c| str_to_float(c));
    Value::Array([Value::Float(x), Value::Float(y)].into_iter().collect())
}

/// `substr($s, $start, $end - $start)` with PHP's `strpos`/`strrpos`
/// results (`false` counts as 0).
fn inside(s: &[u8], start: i64, end: Option<usize>) -> Vec<u8> {
    let end = end.map_or(0, |e| e as i64);
    substr(s, start, Some(end - start)).to_vec()
}

fn find(s: &[u8], needle: &[u8]) -> Option<usize> {
    s.windows(needle.len()).position(|w| w == needle)
}

fn rfind(s: &[u8], needle: &[u8]) -> Option<usize> {
    s.windows(needle.len()).rposition(|w| w == needle)
}

/// `unpack('V', substr($wkb, $offset, 4))[1]`, or `None` when short.
fn u32_le(b: &[u8], offset: usize) -> Option<u32> {
    b.get(offset..offset + 4).map(|s| u32::from_le_bytes([s[0], s[1], s[2], s[3]]))
}

/// `unpack('d', substr($wkb, $offset, 8))[1]`, or `None` when short.
fn f64_le(b: &[u8], offset: usize) -> Option<f64> {
    b.get(offset..offset + 8).map(|s| f64::from_le_bytes(s.try_into().unwrap_or([0; 8])))
}

/// `decodePoint($wkb)` (SQL): `[x, y]` from WKT or SRID-prefixed WKB.
pub fn decode_point(wkb: &[u8]) -> Result<Value> {
    if wkb.to_ascii_uppercase().starts_with(b"POINT(") {
        let start = find(wkb, b"(").map_or(0, |p| p as i64) + 1;
        return Ok(pair(&inside(wkb, start, rfind(wkb, b")"))));
    }
    if wkb.len() < 25 {
        return Err(Error::database("Invalid WKB: too short for POINT"));
    }
    if wkb[4] != 1 {
        return Err(Error::database("Only little-endian WKB supported"));
    }
    let (Some(x), Some(y)) = (f64_le(wkb, 9), f64_le(wkb, 17)) else {
        return Err(Error::database("Invalid WKB: missing coordinate bytes"));
    };
    Ok(Value::Array([Value::Float(x), Value::Float(y)].into_iter().collect()))
}

/// `decodeLinestring($wkb)` (SQL): `[[x, y], ...]`.
pub fn decode_linestring(wkb: &[u8]) -> Result<Value> {
    if wkb.to_ascii_uppercase().starts_with(b"LINESTRING(") {
        let start = find(wkb, b"(").map_or(0, |p| p as i64) + 1;
        let body = inside(wkb, start, rfind(wkb, b")"));
        let points = explode(b",", &body, i64::MAX).unwrap_or_default();
        return Ok(Value::Array(points.into_iter().map(pair).collect()));
    }
    let mut offset = 9;
    let n = u32_le(wkb, offset).ok_or_else(|| Error::database("Invalid WKB: cannot unpack number of points"))?;
    offset += 4;
    let mut points = Array::new();
    for _ in 0..n {
        let (Some(x), Some(y)) = (f64_le(wkb, offset), f64_le(wkb, offset + 8)) else {
            return Err(Error::database("Invalid WKB: cannot unpack point coordinates"));
        };
        let _ = points.push(Value::Array([Value::Float(x), Value::Float(y)].into_iter().collect()));
        offset += 16;
    }
    Ok(Value::Array(points))
}

/// `decodePolygon($wkb)` (SQL): `[[[x, y], ...], ...]`.
pub fn decode_polygon(wkb: &[u8]) -> Result<Value> {
    if wkb.starts_with(b"POLYGON((") {
        let start = find(wkb, b"((").map_or(0, |p| p as i64) + 2;
        let body = inside(wkb, start, rfind(wkb, b"))"));
        let rings = explode(b"),(", &body, i64::MAX).unwrap_or_default();
        return Ok(Value::Array(
            rings
                .into_iter()
                .map(|ring| {
                    let points = explode(b",", ring, i64::MAX).unwrap_or_default();
                    Value::Array(points.into_iter().map(pair).collect())
                })
                .collect(),
        ));
    }
    let mut wkb = wkb.to_vec();
    if wkb.starts_with(b"0x") || php_std::string::ctype_xdigit(&wkb) {
        let hex = if wkb.starts_with(b"0x") { wkb[2..].to_vec() } else { wkb.clone() };
        wkb = php_std::encoding::hex2bin(&hex).ok_or_else(|| Error::database("Invalid hex WKB"))?;
    }
    if wkb.len() < 21 {
        return Err(Error::database("WKB too short to be a POLYGON"));
    }
    let mut offset = 4;
    if wkb[offset] != 1 {
        return Err(Error::database("Only little-endian WKB supported"));
    }
    offset += 1;
    let kind = u32_le(&wkb, offset).ok_or_else(|| Error::database("Invalid WKB: cannot unpack geometry type"))?;
    let has_srid = kind & 0x2000_0000 == 0x2000_0000;
    let geom = kind & 0xFF;
    offset += 4;
    if geom != 3 {
        return Err(Error::database(format!("Not a POLYGON geometry type, got {geom}")));
    }
    if has_srid {
        offset += 4;
    }
    let n = u32_le(&wkb, offset).ok_or_else(|| Error::database("Invalid WKB: cannot unpack number of rings"))?;
    offset += 4;
    let mut rings = Array::new();
    for _ in 0..n {
        let points =
            u32_le(&wkb, offset).ok_or_else(|| Error::database("Invalid WKB: cannot unpack number of points"))?;
        offset += 4;
        let mut ring = Array::new();
        for _ in 0..points {
            let x = f64_le(&wkb, offset).ok_or_else(|| Error::database("Failed to unpack X coordinate from WKB."))?;
            let y =
                f64_le(&wkb, offset + 8).ok_or_else(|| Error::database("Failed to unpack Y coordinate from WKB."))?;
            let _ = ring.push(Value::Array([Value::Float(x), Value::Float(y)].into_iter().collect()));
            offset += 16;
        }
        let _ = rings.push(Value::Array(ring));
    }
    Ok(Value::Array(rings))
}
