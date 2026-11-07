//! Finds, counts and sums: `WHERE` conditions for every query method
//! (comparisons, `LIKE`, `REGEXP`, `MATCH ... AGAINST` full-text search,
//! JSON arrays, spatial filters), cursor pagination and the document-level
//! permission filter.
//!
//! PHP: `SQL::find`, `SQL::count`, `SQL::sum`, `SQL::getSQLConditions`,
//! `SQL::getSQLPermissionsCondition`, `SQL::getFulltextValue`,
//! `MariaDB::getSQLCondition`, `MariaDB::handleSpatialQueries`,
//! `MariaDB::handleDistanceSpatialQueries` (and MySQL's).

use php_std::pcre;

use super::MariaDB;
use super::documents::{ALIAS, projection, selections};
use super::pdo::Statement;
use super::sql::{self, Binds, bind_typed, filter, quote};
use crate::adapter::FindOptions;
use crate::database::Database;
use crate::document::Document;
use crate::error::{Error, Result};
use crate::query::{Method, Query};
use crate::value::{ArrayKey, Str, Value};

/// The file PHP's `TypeError`s name as the call site of the condition
/// builders.
const MARIADB_PHP: &str = "/usr/src/code/vendor/utopia-php/database/src/Database/Adapter/MariaDB.php";

/// `ID::unique()`: a fresh placeholder name (`uniqid()` plus 7 random hex
/// digits), so a query's binds never collide.
pub(super) fn unique() -> String {
    let now = std::time::SystemTime::now().duration_since(std::time::UNIX_EPOCH).unwrap_or_default();
    let mut random = [0u8; 4];
    let _ = getrandom::fill(&mut random);
    let hex: String = random.iter().map(|b| format!("{b:02x}")).collect();
    format!("{:08x}{:05x}{}", now.as_secs(), now.subsec_micros(), &hex[..7])
}

/// `escapeWildcards($value)`.
fn escape_wildcards(value: &[u8]) -> Vec<u8> {
    let mut out = value.to_vec();
    for c in b"%_[]^-.*+?(){}|" {
        let mut replaced = Vec::with_capacity(out.len());
        for &b in &out {
            if b == *c {
                replaced.push(b'\\');
            }
            replaced.push(b);
        }
        out = replaced;
    }
    out
}

/// `getFulltextValue($value)` (SQL): letters, digits, `_` and whitespace
/// kept, whitespace collapsed; quoted values stay an exact phrase, others
/// get a trailing `*`; `''` when nothing (or `0`) remains.
fn fulltext_value(value: &Value, line: u32) -> Result<Vec<u8>> {
    let v = string_param(value, "Utopia\\Database\\Adapter\\SQL::getFulltextValue", line)?;
    let v = v.as_bytes();
    let exact = v.ends_with(b"\"") && v.starts_with(b"\"");
    let replace = |pattern: &[u8], subject: &[u8]| -> Vec<u8> {
        match pcre::preg_replace(
            &pcre::StrOrArray::Str(pattern),
            &pcre::StrOrArray::Str(b" "),
            &pcre::StrOrArray::Str(subject),
            -1,
        ) {
            Ok(p) => match p.value.result {
                pcre::Value::Str(s) => s,
                _ => Vec::new(),
            },
            Err(_) => Vec::new(),
        }
    };
    let v = replace(br"/[^\p{L}\p{N}_\s]/u", v);
    let v = replace(br"/\s+/", &v);
    let v = php_std::string::trim(&v, b" \n\r\t\x0B\0").to_vec();
    if v.is_empty() || v == b"0" {
        return Ok(Vec::new());
    }
    let mut out = Vec::with_capacity(v.len() + 2);
    if exact {
        out.push(b'"');
        out.extend_from_slice(&v);
        out.push(b'"');
    } else {
        out.extend_from_slice(&v);
        out.push(b'*');
    }
    Ok(out)
}

/// A value passed to a PHP method's `string` parameter: scalars are
/// converted as PHP's weak typing converts them; `null`, arrays and objects
/// are a `TypeError` naming the call site (a line of `MariaDB.php`).
fn string_param(value: &Value, function: &str, line: u32) -> Result<Str> {
    match value {
        Value::Str(s) => Ok(s.clone()),
        Value::Int(_) | Value::Float(_) | Value::Bool(_) => Ok(value.to_php_string().unwrap_or(Str::EMPTY)),
        other => Err(Error::new(
            crate::Kind::TypeError,
            format!(
                "{function}(): Argument #1 ($value) must be of type string, {} given, called in {MARIADB_PHP} on line {line}",
                sql::value_name(other)
            ),
        )),
    }
}

/// `getSQLOperator($method)`.
fn sql_operator(method: &Method) -> Result<&'static str> {
    Ok(match method {
        Method::Equal => "=",
        Method::NotEqual => "!=",
        Method::LessThan => "<",
        Method::LessThanEqual => "<=",
        Method::GreaterThan => ">",
        Method::GreaterThanEqual => ">=",
        Method::IsNull => "IS NULL",
        Method::IsNotNull => "IS NOT NULL",
        Method::StartsWith
        | Method::EndsWith
        | Method::Contains
        | Method::ContainsAny
        | Method::ContainsAll
        | Method::NotStartsWith
        | Method::NotEndsWith
        | Method::NotContains => "LIKE",
        Method::Regex => "REGEXP",
        Method::VectorDot | Method::VectorCosine | Method::VectorEuclidean => {
            return Err(Error::database("Vector queries are not supported by this database"));
        }
        Method::Exists | Method::NotExists => {
            return Err(Error::database("Exists queries are not supported by this database"));
        }
        other => return Err(Error::database(format!("Unknown method: {}", other.as_str()))),
    })
}

/// `convertArrayToWKT` of a query value (arrays only), called from `line`
/// of `file` (PHP's `TypeError` names the call site).
fn wkt(value: Option<&Value>, file: &str, line: u32) -> Result<Value> {
    match value {
        Some(Value::Array(a)) => Ok(Value::Str(super::spatial::array_to_wkt(a)?.into())),
        other => Err(Error::new(
            crate::Kind::TypeError,
            format!(
                "Utopia\\Database\\Adapter\\SQL::convertArrayToWKT(): Argument #1 ($geometry) must be of type array, {} given, called in {file} on line {line}",
                sql::value_name(other.unwrap_or(&Value::Null))
            ),
        )),
    }
}

impl MariaDB {
    /// `getSQLConditions($queries, $binds, $separator)`.
    pub(super) fn conditions(&self, queries: &[Query], binds: &mut Binds, separator: &str) -> Result<String> {
        let mut conditions = Vec::new();
        for query in queries {
            if *query.method() == Method::Select {
                continue;
            }
            if query.is_nested() {
                let nested: Vec<Query> = query.queries().cloned().collect();
                conditions.push(self.conditions(&nested, binds, query.method().as_str())?);
            } else {
                conditions.push(self.condition(query.clone(), binds)?);
            }
        }
        let tmp = conditions.join(&format!(" {separator} "));
        Ok(if tmp.is_empty() || tmp == "0" { String::new() } else { format!("({tmp})") })
    }

    /// `getSQLCondition($query, $binds)` (MariaDB).
    fn condition(&self, mut query: Query, binds: &mut Binds) -> Result<String> {
        let attribute = sql::internal_key(query.attribute()).to_owned();
        query.set_attribute(attribute.clone());
        let attribute = quote(&filter(&attribute));
        let alias = quote(ALIAS);
        let placeholder = unique();

        if query.is_spatial_attribute() {
            return self.spatial_condition(&query, binds, &attribute, &alias, &placeholder);
        }
        let values = query.values();
        let method = query.method().clone();
        match method {
            Method::Or | Method::And => {
                let mut conditions = Vec::new();
                for q in query.queries() {
                    conditions.push(self.condition(q.clone(), binds)?);
                }
                let m = method.as_str().to_uppercase();
                Ok(if conditions.is_empty() { String::new() } else { format!(" {m} ({})", conditions.join(" AND ")) })
            }
            Method::Search | Method::NotSearch => {
                let line = if method == Method::Search { 1500 } else { 1509 };
                let value = fulltext_value(values.first().unwrap_or(&Value::Null), line)?;
                if value.is_empty() {
                    return Ok(if method == Method::Search { "0 = 1" } else { "1 = 1" }.to_owned());
                }
                binds.set(format!(":{placeholder}_0"), Value::Str(Str::from(value)));
                let expr = format!("MATCH({alias}.{attribute}) AGAINST (:{placeholder}_0 IN BOOLEAN MODE)");
                Ok(if method == Method::Search { expr } else { format!("NOT ({expr})") })
            }
            Method::Between | Method::NotBetween => {
                binds.set(format!(":{placeholder}_0"), values.first().cloned().unwrap_or(Value::Null));
                binds.set(format!(":{placeholder}_1"), values.get(1).cloned().unwrap_or(Value::Null));
                let not = if method == Method::NotBetween { "NOT " } else { "" };
                Ok(format!("{alias}.{attribute} {not}BETWEEN :{placeholder}_0 AND :{placeholder}_1"))
            }
            Method::IsNull | Method::IsNotNull => Ok(format!("{alias}.{attribute} {}", sql_operator(&method)?)),
            Method::ContainsAll if query.on_array() => {
                binds.set(
                    format!(":{placeholder}_0"),
                    sql::json_encode(&Value::Array(values.iter().cloned().collect())),
                );
                Ok(format!("JSON_CONTAINS({alias}.{attribute}, :{placeholder}_0)"))
            }
            Method::Contains | Method::ContainsAny | Method::NotContains if query.on_array() => {
                // getSupportForJSONOverlaps(): MariaDB and MySQL have JSON_OVERLAPS.
                binds.set(
                    format!(":{placeholder}_0"),
                    sql::json_encode(&Value::Array(values.iter().cloned().collect())),
                );
                let expr = format!("JSON_OVERLAPS({alias}.{attribute}, :{placeholder}_0)");
                Ok(if method == Method::NotContains { format!("NOT ({expr})") } else { expr })
            }
            _ => {
                let is_not = matches!(method, Method::NotStartsWith | Method::NotEndsWith | Method::NotContains);
                let mut conditions = Vec::new();
                for (key, value) in values.iter().enumerate() {
                    // escapeWildcards($value) from the match arm on `line` of MariaDB.php.
                    let like = |prefix: &[u8], suffix: &[u8], line: u32| -> Result<Value> {
                        let body = string_param(value, "Utopia\\Database\\Adapter\\SQL::escapeWildcards", line)?;
                        let mut v = prefix.to_vec();
                        v.extend_from_slice(&escape_wildcards(body.as_bytes()));
                        v.extend_from_slice(suffix);
                        Ok(Value::Str(Str::from(v)))
                    };
                    let value = match method {
                        Method::StartsWith => like(b"", b"%", 1578)?,
                        Method::NotStartsWith => like(b"", b"%", 1579)?,
                        Method::EndsWith => like(b"%", b"", 1580)?,
                        Method::NotEndsWith => like(b"%", b"", 1581)?,
                        Method::Contains | Method::ContainsAny | Method::NotContains => like(b"%", b"%", 1582)?,
                        _ => value.clone(),
                    };
                    binds.set(format!(":{placeholder}_{key}"), value);
                    let operator = sql_operator(&method)?;
                    if is_not {
                        conditions.push(format!("{alias}.{attribute} NOT {operator} :{placeholder}_{key}"));
                    } else {
                        conditions.push(format!("{alias}.{attribute} {operator} :{placeholder}_{key}"));
                    }
                }
                let separator = if is_not { " AND " } else { " OR " };
                Ok(if conditions.is_empty() { String::new() } else { format!("({})", conditions.join(separator)) })
            }
        }
    }

    /// `handleSpatialQueries(...)` (MariaDB).
    fn spatial_condition(
        &self,
        query: &Query,
        binds: &mut Binds,
        attribute: &str,
        alias: &str,
        placeholder: &str,
    ) -> Result<String> {
        let first = query.values().first();
        let p0 = format!(":{placeholder}_0");
        let simple = |binds: &mut Binds, function: &str, not: bool, line: u32| -> Result<String> {
            binds.set(p0.clone(), wkt(first, MARIADB_PHP, line)?);
            let not = if not { "NOT " } else { "" };
            Ok(format!("{not}{function}({alias}.{attribute}, {})", self.geom_from_text(&p0, None)))
        };
        match query.method() {
            Method::Crosses => simple(binds, "ST_Crosses", false, 1402),
            Method::NotCrosses => simple(binds, "ST_Crosses", true, 1406),
            Method::DistanceEqual
            | Method::DistanceNotEqual
            | Method::DistanceGreaterThan
            | Method::DistanceLessThan => self.distance_condition(query, binds, attribute, alias, placeholder),
            Method::Intersects => simple(binds, "ST_Intersects", false, 1416),
            Method::NotIntersects => simple(binds, "ST_Intersects", true, 1420),
            Method::Overlaps => simple(binds, "ST_Overlaps", false, 1424),
            Method::NotOverlaps => simple(binds, "ST_Overlaps", true, 1428),
            Method::Touches => simple(binds, "ST_Touches", false, 1432),
            Method::NotTouches => simple(binds, "ST_Touches", true, 1436),
            Method::Equal => simple(binds, "ST_Equals", false, 1440),
            Method::NotEqual => simple(binds, "ST_Equals", true, 1444),
            Method::Contains => simple(binds, "ST_Contains", false, 1448),
            Method::NotContains => simple(binds, "ST_Contains", true, 1452),
            Method::IsNull | Method::IsNotNull => Ok(format!("{alias}.{attribute} {}", sql_operator(query.method())?)),
            other => Err(Error::database(format!("Unknown spatial query method: {}", other.as_str()))),
        }
    }

    /// `handleDistanceSpatialQueries(...)`: MariaDB measures meters with
    /// `ST_DISTANCE_SPHERE` between points only; MySQL with `ST_Distance`
    /// in `metre` on SRID 4326, and plain distances on SRID 0.
    fn distance_condition(
        &self,
        query: &Query,
        binds: &mut Binds,
        attribute: &str,
        alias: &str,
        placeholder: &str,
    ) -> Result<String> {
        let first = query.values().first();
        // $distanceParams[$i]: an array element, a string's byte, or null.
        let param = |i: i64| -> Option<Value> {
            match first {
                Some(Value::Array(a)) => a.get(&ArrayKey::Int(i)).cloned(),
                Some(Value::Str(s)) => s.as_bytes().get(i as usize).map(|b| Value::Str(vec![*b].into())),
                _ => None,
            }
        };
        let p0 = format!(":{placeholder}_0");
        let mysql = self.mysql();
        let shape = if mysql {
            wkt(param(0).as_ref(), "/usr/src/code/vendor/utopia-php/database/src/Database/Adapter/MySQL.php", 99)?
        } else {
            wkt(param(0).as_ref(), MARIADB_PHP, 1353)?
        };
        binds.set(p0.clone(), shape.clone());
        binds.set(format!(":{placeholder}_1"), param(1).unwrap_or(Value::Null));
        let meters = matches!(param(2), Some(Value::Bool(true)));
        let operator = match query.method() {
            Method::DistanceEqual => "=",
            Method::DistanceNotEqual => "!=",
            Method::DistanceGreaterThan => ">",
            Method::DistanceLessThan => "<",
            other => return Err(Error::database(format!("Unknown spatial query method: {}", other.as_str()))),
        };
        if mysql {
            if meters {
                let attr = format!("ST_SRID({alias}.{attribute}, {})", Database::DEFAULT_SRID);
                let geom = self.geom_from_text(&p0, None);
                return Ok(format!("ST_Distance({attr}, {geom}, 'metre') {operator} :{placeholder}_1"));
            }
            let attr = format!("ST_SRID({alias}.{attribute}, 0)");
            let geom = self.geom_from_text(&p0, Some(0));
            return Ok(format!("ST_Distance({attr}, {geom}) {operator} :{placeholder}_1"));
        }
        if meters {
            let shape = match &shape {
                Value::Str(s) => s.as_bytes().to_vec(),
                _ => Vec::new(),
            };
            let wkt_type = String::from_utf8_lossy(&super::spatial::type_from_wkt(&shape)?).into_owned();
            let attr_type = query.attribute_type().to_ascii_lowercase();
            if wkt_type != Database::VAR_POINT || attr_type != Database::VAR_POINT {
                return Err(Error::query(format!(
                    "Distance in meters is not supported between {attr_type} and {wkt_type}"
                )));
            }
            return Ok(format!(
                "ST_DISTANCE_SPHERE({alias}.{attribute}, {}, {}) {operator} :{placeholder}_1",
                self.geom_from_text(&p0, None),
                Database::EARTH_RADIUS
            ));
        }
        Ok(format!("ST_Distance({alias}.{attribute}, {}) {operator} :{placeholder}_1", self.geom_from_text(&p0, None)))
    }

    /// `getSQLPermissionsCondition($collection, $roles, $alias, $type)`
    /// (SQL): the document among those the roles' permission rows name.
    fn permissions_condition(&self, collection: &str, roles: &[Value], kind: &str) -> Result<String> {
        if !Database::PERMISSIONS.contains(&kind) {
            return Err(Error::database(format!("Unknown permission type: {kind}")));
        }
        let roles: Vec<String> = roles
            .iter()
            .map(|role| {
                let text = role.to_php_string().unwrap_or(Str::EMPTY);
                String::from_utf8_lossy(&super::pdo::quote(text.as_bytes())).into_owned()
            })
            .collect();
        Ok(format!(
            "{}.{} IN (\n            SELECT _document\n            FROM {}\n            WHERE _permission IN ({})\n              AND _type = '{kind}'\n              {}\n        )",
            quote(ALIAS),
            quote("_uid"),
            self.sql_table(&format!("{collection}_perms")),
            roles.join(", "),
            self.tenant_sql(collection)
        ))
    }

    /// The conditions shared by `count` and `sum`: the queries, the
    /// permission filter and the tenant.
    fn filters(&self, collection: &str, queries: &[Query], binds: &mut Binds, wheres: &mut Vec<String>) -> Result<()> {
        let name = filter(collection);
        let conditions = self.conditions(queries, binds, "AND")?;
        if !conditions.is_empty() {
            wheres.push(conditions);
        }
        let authorization = self.state.lock().authorization.clone();
        if authorization.status() {
            wheres.push(self.permissions_condition(&name, &authorization.roles(), Database::PERMISSION_READ)?);
        }
        if self.shared() {
            binds.set(":_tenant", self.tenant_of_state());
            wheres.push(self.tenant_query_with(collection, ALIAS, 0, ""));
        }
        Ok(())
    }

    /// `find($collection, $queries, ...)`.
    pub(super) async fn find_impl(
        &self,
        collection: &Document,
        queries: &[Query],
        options: FindOptions<'_>,
    ) -> Result<Vec<Document>> {
        let collection = collection.id().to_owned();
        let name = filter(&collection);
        let alias = quote(ALIAS);
        let mut binds = Binds::default();
        let mut wheres: Vec<String> = Vec::new();
        let mut orders: Vec<String> = Vec::new();
        // Vector queries order by distance where the adapter supports them
        // (getSQLVectorDistance() is null here): they are dropped.
        let others: Vec<Query> = queries.iter().filter(|q| !q.method().is_vector()).cloned().collect();

        let mut cursor_where = Vec::new();
        let count = options.order_attributes.len();
        for (i, original) in options.order_attributes.iter().enumerate() {
            let order_type = options.order_types.get(i).map(String::as_str).unwrap_or(Database::ORDER_ASC);
            if order_type == Database::ORDER_RANDOM {
                orders.push("RAND()".to_owned());
                continue;
            }
            let attribute = filter(sql::internal_key(original));
            let mut direction = filter(order_type);
            if options.cursor_direction == Database::CURSOR_BEFORE {
                direction = if direction == Database::ORDER_ASC { Database::ORDER_DESC } else { Database::ORDER_ASC }
                    .to_owned();
            }
            orders.push(format!("{} {direction}", quote(&attribute)));
            if !options.cursor.is_empty() {
                let cursor_value = |key: &str| {
                    options.cursor.get(&ArrayKey::normalize(Str::from(key))).cloned().unwrap_or(Value::Null)
                };
                let operator = if direction == Database::ORDER_DESC { "<" } else { ">" };
                if count == 1 && i == 0 && original == "$sequence" {
                    binds.set(":cursor_pk", cursor_value(original));
                    cursor_where.push(format!("{alias}.{} {operator} :cursor_pk", quote(&attribute)));
                    break;
                }
                let mut conditions = Vec::new();
                for (j, previous) in options.order_attributes.iter().enumerate().take(i) {
                    let previous_attr = filter(sql::internal_key(previous));
                    binds.set(format!(":cursor_{j}"), cursor_value(previous));
                    conditions.push(format!("{alias}.{} = :cursor_{j}", quote(&previous_attr)));
                }
                binds.set(format!(":cursor_{i}"), cursor_value(original));
                conditions.push(format!("{alias}.{} {operator} :cursor_{i}", quote(&attribute)));
                cursor_where.push(format!("({})", conditions.join(" AND ")));
            }
        }
        if !cursor_where.is_empty() {
            wheres.push(format!("({})", cursor_where.join(" OR ")));
        }
        let conditions = self.conditions(&others, &mut binds, "AND")?;
        if !conditions.is_empty() {
            wheres.push(conditions);
        }
        let authorization = self.state.lock().authorization.clone();
        if authorization.status() {
            wheres.push(self.permissions_condition(&name, &authorization.roles(), options.for_permission)?);
        }
        if self.shared() {
            binds.set(":_tenant", self.tenant_of_state());
            wheres.push(self.tenant_query_with(&collection, ALIAS, 0, ""));
        }
        let sql_where = if wheres.is_empty() { String::new() } else { format!("WHERE {}", wheres.join(" AND ")) };
        let sql_order = if orders.is_empty() { String::new() } else { format!("ORDER BY {}", orders.join(", ")) };
        let mut sql_limit = String::new();
        if let Some(limit) = options.limit {
            binds.set(":limit", Value::Int(limit));
            sql_limit = "LIMIT :limit".to_owned();
        }
        if let Some(offset) = options.offset {
            binds.set(":offset", Value::Int(offset));
            sql_limit.push_str(" OFFSET :offset");
        }
        let projection = projection(&selections(&others), ALIAS)?;
        let sql = format!(
            "\n            SELECT {projection}\n            FROM {} AS {alias}\n            {sql_where}\n            {sql_order}\n            {sql_limit};\n        ",
            self.sql_table(&name)
        );
        let sql = self.trigger_sql(Database::EVENT_DOCUMENT_FIND, sql);
        let mut stmt = Statement::new(sql);
        for (key, value) in binds.0 {
            match value {
                Value::Float(f) => stmt.bind_str(&key, self.float_literal(f)?),
                other => bind_typed(&mut stmt, &key, other)?,
            }
        }
        let rows = self.run(&stmt).await.map_err(|e| self.processed(e))?.rows;
        let mut results = Vec::with_capacity(rows.len());
        for row in rows {
            results.push(sql::row_to_document(row, true)?);
        }
        if options.cursor_direction == Database::CURSOR_BEFORE {
            results.reverse();
        }
        Ok(results)
    }

    /// `count($collection, $queries, $max)`.
    pub(super) async fn count_impl(&self, collection: &Document, queries: &[Query], max: Option<i64>) -> Result<i64> {
        let collection = collection.id().to_owned();
        let name = filter(&collection);
        let alias = quote(ALIAS);
        let mut binds = Binds::default();
        let mut wheres = Vec::new();
        let mut limit = String::new();
        if let Some(max) = max {
            binds.set(":limit", Value::Int(max));
            limit = "LIMIT :limit".to_owned();
        }
        let others: Vec<Query> = queries.iter().filter(|q| !q.method().is_vector()).cloned().collect();
        self.filters(&collection, &others, &mut binds, &mut wheres)?;
        let sql_where = if wheres.is_empty() { String::new() } else { format!("WHERE {}", wheres.join(" AND ")) };
        let table = self.sql_table(&name);
        let sql = if limit.is_empty() {
            format!("\n            SELECT COUNT(1) as sum\n\t\t\tFROM {table} AS {alias}\n\t\t\t{sql_where}\n        ")
        } else {
            format!(
                "\n\t\t\tSELECT COUNT(1) as sum FROM (\n\t\t\t\tSELECT 1\n\t\t\t\tFROM {table} AS {alias}\n                {sql_where}\n                {limit}\n\t\t\t) table_count\n        "
            )
        };
        let sql = self.trigger_sql(Database::EVENT_DOCUMENT_COUNT, sql);
        let mut stmt = Statement::new(sql);
        for (key, value) in binds.0 {
            bind_typed(&mut stmt, &key, value)?;
        }
        let rows = self.run(&stmt).await.map_err(|e| self.processed(e))?.rows;
        let sum = rows.first().and_then(|r| r.get_str("sum")).filter(|v| !matches!(v, Value::Null)).cloned();
        Ok(sum.map(|v| super::pdo::to_long(&v)).unwrap_or(0))
    }

    /// `sum($collection, $attribute, $queries, $max)`: an integer, or a
    /// float when the sum is not integral.
    pub(super) async fn sum_impl(
        &self,
        collection: &Document,
        attribute: &str,
        queries: &[Query],
        max: Option<i64>,
    ) -> Result<Value> {
        let collection = collection.id().to_owned();
        let name = filter(&collection);
        let attribute = quote(&filter(attribute));
        let alias = quote(ALIAS);
        let mut binds = Binds::default();
        let mut wheres = Vec::new();
        let mut limit = String::new();
        if let Some(max) = max {
            binds.set(":limit", Value::Int(max));
            limit = "LIMIT :limit".to_owned();
        }
        let others: Vec<Query> = queries.iter().filter(|q| !q.method().is_vector()).cloned().collect();
        self.filters(&collection, &others, &mut binds, &mut wheres)?;
        let sql_where = if wheres.is_empty() { String::new() } else { format!("WHERE {}", wheres.join(" AND ")) };
        let table = self.sql_table(&name);
        let sql = if limit.is_empty() {
            format!(
                "\n\t\t\tSELECT SUM({attribute}) as sum\n\t\t\tFROM {table} AS {alias}\n\t\t\t{sql_where}\n        "
            )
        } else {
            format!(
                "\n\t\t\tSELECT SUM({attribute}) as sum FROM (\n\t\t\t\tSELECT {attribute}\n\t\t\t\tFROM {table} AS {alias}\n\t\t\t\t{sql_where}\n\t\t\t\t{limit}\n\t\t\t) table_count\n        "
            )
        };
        let sql = self.trigger_sql(Database::EVENT_DOCUMENT_SUM, sql);
        let mut stmt = Statement::new(sql);
        for (key, value) in binds.0 {
            bind_typed(&mut stmt, &key, value)?;
        }
        let rows = self.run(&stmt).await.map_err(|e| self.processed(e))?.rows;
        let sum = rows.first().and_then(|r| r.get_str("sum")).filter(|v| !matches!(v, Value::Null)).cloned();
        Ok(match sum {
            None => Value::Int(0),
            Some(Value::Str(s)) => match php_std::value::numeric_bytes_ex(s.as_bytes()) {
                Some((php_std::value::Number::Int(i), _)) => Value::Int(i),
                Some((php_std::value::Number::Float(f), _)) => Value::Float(f),
                None => {
                    return Err(Error::new(
                        crate::Kind::TypeError,
                        "Utopia\\Database\\Adapter\\SQL::sum(): Return value must be of type int|float, string returned",
                    ));
                }
            },
            Some(other) => other,
        })
    }
}
