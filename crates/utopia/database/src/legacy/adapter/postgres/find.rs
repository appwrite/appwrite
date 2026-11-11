//! Finds, counts and sums: `WHERE` conditions for every query method
//! (comparisons, `LIKE`, POSIX regexes, full-text search, JSONB arrays and
//! objects, PostGIS spatial filters), pgvector distance ordering, cursor
//! pagination and the document-level permission filter.
//!
//! PHP: `SQL::find`, `SQL::count`, `SQL::sum`, `SQL::getSQLConditions`,
//! `Postgres::getSQLCondition`, `handleSpatialQueries`,
//! `handleDistanceSpatialQueries`, `handleObjectQueries`,
//! `getSQLVectorDistance`, `getSQLPermissionsCondition`,
//! `getFulltextValue`, `buildJsonbPath`.

use php_std::pcre;

use super::Postgres;
use super::documents::{ALIAS, projection, selections};
use super::pdo::Statement;
use super::sql::{self, Binds, bind_typed, filter, interpolate, quote};
use crate::adapter::FindOptions;
use crate::database::Database;
use crate::document::Document;
use crate::error::{Error, Result};
use crate::query::{Method, Query};
use crate::value::{Array, ArrayKey, Str, Value};

/// `ID::unique()`: a fresh placeholder name (`uniqid()` plus 7 random hex
/// digits), so a query's binds never collide.
pub(super) fn unique() -> String {
    let now = std::time::SystemTime::now().duration_since(std::time::UNIX_EPOCH).unwrap_or_default();
    let mut random = [0u8; 4];
    let _ = getrandom::fill(&mut random);
    let hex: String = random.iter().map(|b| format!("{b:02x}")).collect();
    format!("{:08x}{:05x}{}", now.as_secs(), now.subsec_micros(), &hex[..7])
}

/// `buildJsonbPath($path, $asText)`: `"col"->'a'->>'b'` for `col.a.b`.
pub(super) fn jsonb_path(path: &str, as_text: bool) -> Result<String> {
    let parts: Vec<&str> = path.split('.').collect();
    for part in &parts {
        let body = part.strip_suffix('\n').unwrap_or(part);
        let valid = !body.is_empty() && body.bytes().all(|c| c.is_ascii_alphanumeric() || c == b'_' || c == b'-');
        if !valid {
            return Err(Error::database(format!("Invalid JSON key {part}")));
        }
    }
    if parts.len() == 1 {
        return Ok(quote(&filter(parts[0])));
    }
    let base = quote(&filter(parts[0]));
    let last = parts[parts.len() - 1];
    let mut chain = base;
    for key in &parts[1..parts.len() - 1] {
        chain.push_str(&format!("->'{key}'"));
    }
    let result = format!("{chain}->>'{last}'");
    Ok(if as_text { format!("(({result})::text)") } else { result })
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

/// `getFulltextValue($value)` (Postgres): letters, digits, `_` and
/// whitespace kept, words joined with ` or ` unless the value is quoted,
/// the result in single quotes; `''` when nothing remains.
fn fulltext_value(value: &Value, line: u32) -> Result<Vec<u8>> {
    let v = string_param(value, "Utopia\\Database\\Adapter\\Postgres::getFulltextValue", line)?;
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
    let v = if exact { v } else { php_std::string::str_replace(b" ", b" or ", &v).0.into_owned() };
    let mut out = Vec::with_capacity(v.len() + 2);
    out.push(b'\'');
    out.extend_from_slice(&v);
    out.push(b'\'');
    Ok(out)
}

/// A value passed to a PHP method's `string` parameter: scalars are
/// converted as PHP's weak typing converts them; `null`, arrays and objects
/// are a `TypeError` naming the call site (a line of `Postgres.php`).
fn string_param(value: &Value, function: &str, line: u32) -> Result<Str> {
    match value {
        Value::Str(s) => Ok(s.clone()),
        Value::Int(_) | Value::Float(_) | Value::Bool(_) => Ok(value.to_php_string().unwrap_or(Str::EMPTY)),
        other => Err(Error::new(
            crate::Kind::TypeError,
            format!(
                "{function}(): Argument #1 ($value) must be of type string, {} given, called in /usr/src/code/vendor/utopia-php/database/src/Database/Adapter/Postgres.php on line {line}",
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
        | Method::NotContains => "ILIKE",
        Method::Regex => "~",
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
/// of `Postgres.php` (PHP's `TypeError` names the call site).
fn wkt(value: Option<&Value>, line: u32) -> Result<Value> {
    match value {
        Some(Value::Array(a)) => Ok(Value::Str(super::spatial::array_to_wkt(a)?.into())),
        other => Err(Error::new(
            crate::Kind::TypeError,
            format!(
                "Utopia\\Database\\Adapter\\SQL::convertArrayToWKT(): Argument #1 ($geometry) must be of type array, {} given, called in /usr/src/code/vendor/utopia-php/database/src/Database/Adapter/Postgres.php on line {line}",
                sql::value_name(other.unwrap_or(&Value::Null))
            ),
        )),
    }
}

fn geom(placeholder: &str) -> String {
    super::documents::geom_from_text(placeholder)
}

impl Postgres {
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

    /// `getSQLCondition($query, $binds)` (Postgres).
    fn condition(&self, mut query: Query, binds: &mut Binds) -> Result<String> {
        let attribute = sql::internal_key(query.attribute()).to_owned();
        query.set_attribute(attribute.clone());
        let nested_object = query.is_object_attribute() && attribute.contains('.');
        let attribute = if nested_object { jsonb_path(&attribute, false)? } else { quote(&filter(&attribute)) };
        let alias = quote(ALIAS);
        let placeholder = unique();

        if query.is_spatial_attribute() {
            return self.spatial_condition(&query, binds, &attribute, &alias, &placeholder);
        }
        if query.is_object_attribute() && !nested_object {
            return object_condition(&query, binds, &attribute, &alias, &placeholder);
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
                let line = if method == Method::Search { 1686 } else { 1694 };
                let value = fulltext_value(values.first().unwrap_or(&Value::Null), line)?;
                if value.is_empty() {
                    return Ok(if method == Method::Search { "0 = 1" } else { "1 = 1" }.to_owned());
                }
                binds.set(format!(":{placeholder}_0"), Value::Str(Str::from(value)));
                let expr = format!(
                    "to_tsvector(regexp_replace({attribute}, '[^\\w]+',' ','g')) @@ websearch_to_tsquery(:{placeholder}_0)"
                );
                Ok(if method == Method::Search { expr } else { format!("NOT ({expr})") })
            }
            Method::VectorDot | Method::VectorCosine | Method::VectorEuclidean => Ok(String::new()),
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
                Ok(format!("{alias}.{attribute} @> :{placeholder}_0::jsonb"))
            }
            _ => {
                let array_contains = matches!(
                    method,
                    Method::Contains | Method::ContainsAny | Method::NotContains | Method::ContainsAll
                ) && query.on_array();
                let operator = if array_contains { "@>" } else { sql_operator(&method)? };
                let is_not = matches!(method, Method::NotStartsWith | Method::NotEndsWith | Method::NotContains);
                let mut conditions = Vec::new();
                for (key, value) in values.iter().enumerate() {
                    // escapeWildcards($value) from the match arm on `line` of Postgres.php.
                    let like = |prefix: &[u8], suffix: &[u8], line: u32| -> Result<Value> {
                        let body = string_param(value, "Utopia\\Database\\Adapter\\SQL::escapeWildcards", line)?;
                        let mut v = prefix.to_vec();
                        v.extend_from_slice(&escape_wildcards(body.as_bytes()));
                        v.extend_from_slice(suffix);
                        Ok(Value::Str(Str::from(v)))
                    };
                    let value = match method {
                        Method::StartsWith => like(b"", b"%", 1746)?,
                        Method::NotStartsWith => like(b"", b"%", 1747)?,
                        Method::EndsWith => like(b"%", b"", 1748)?,
                        Method::NotEndsWith => like(b"%", b"", 1749)?,
                        Method::Contains | Method::ContainsAny | Method::NotContains => {
                            if query.on_array() {
                                sql::json_encode(value)
                            } else {
                                like(b"%", b"%", if method == Method::NotContains { 1751 } else { 1750 })?
                            }
                        }
                        _ => value.clone(),
                    };
                    binds.set(format!(":{placeholder}_{key}"), value);
                    if is_not && query.on_array() {
                        conditions.push(format!("NOT ({alias}.{attribute} {operator} :{placeholder}_{key})"));
                    } else if is_not {
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

    /// `handleSpatialQueries(...)`.
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
            binds.set(p0.clone(), wkt(first, line)?);
            let not = if not { "NOT " } else { "" };
            Ok(format!("{not}{function}({alias}.{attribute}, {})", geom(&p0)))
        };
        match query.method() {
            Method::Crosses => simple(binds, "ST_Crosses", false, 1520),
            Method::NotCrosses => simple(binds, "ST_Crosses", true, 1524),
            Method::DistanceEqual
            | Method::DistanceNotEqual
            | Method::DistanceGreaterThan
            | Method::DistanceLessThan => {
                // $distanceParams[$i]: an array element, a string's byte, or null.
                let param = |i: i64| -> Option<Value> {
                    match first {
                        Some(Value::Array(a)) => a.get(&ArrayKey::Int(i)).cloned(),
                        Some(Value::Str(s)) => s.as_bytes().get(i as usize).map(|b| Value::Str(vec![*b].into())),
                        _ => None,
                    }
                };
                binds.set(p0.clone(), wkt(param(0).as_ref(), 1473)?);
                binds.set(format!(":{placeholder}_1"), param(1).unwrap_or(Value::Null));
                let meters = matches!(param(2), Some(Value::Bool(true)));
                let operator = match query.method() {
                    Method::DistanceEqual => "=",
                    Method::DistanceNotEqual => "!=",
                    Method::DistanceGreaterThan => ">",
                    _ => "<",
                };
                if meters {
                    let attr = format!("({alias}.{attribute}::geography)");
                    let g = format!("ST_SetSRID({}, {})::geography", geom(&p0), Database::DEFAULT_SRID);
                    return Ok(format!("ST_Distance({attr}, {g}) {operator} :{placeholder}_1"));
                }
                Ok(format!("ST_Distance({alias}.{attribute}, {}) {operator} :{placeholder}_1", geom(&p0)))
            }
            Method::Equal => simple(binds, "ST_Equals", false, 1533),
            Method::NotEqual => simple(binds, "ST_Equals", true, 1537),
            Method::Intersects => simple(binds, "ST_Intersects", false, 1541),
            Method::NotIntersects => simple(binds, "ST_Intersects", true, 1545),
            Method::Overlaps => simple(binds, "ST_Overlaps", false, 1549),
            Method::NotOverlaps => simple(binds, "ST_Overlaps", true, 1553),
            Method::Touches => simple(binds, "ST_Touches", false, 1557),
            Method::NotTouches => simple(binds, "ST_Touches", true, 1561),
            Method::Contains => simple(binds, "ST_Covers", false, 1569),
            Method::NotContains => simple(binds, "ST_Covers", true, 1569),
            Method::IsNull | Method::IsNotNull => Ok(format!("{alias}.{attribute} {}", sql_operator(query.method())?)),
            other => Err(Error::database(format!("Unknown spatial query method: {}", other.as_str()))),
        }
    }

    /// `getSQLVectorDistance($query, $binds, $alias)`.
    fn vector_distance(&self, query: &Query, binds: &mut Binds) -> Result<Option<String>> {
        let attribute = quote(&filter(sql::internal_key(query.attribute())));
        let alias = quote(ALIAS);
        let placeholder = unique();
        let vector: Array = match query.values().first() {
            Some(Value::Array(a)) => a.iter().map(|(k, v)| (k.to_owned(), Value::Float(v.to_double()))).collect(),
            Some(other) => {
                return Err(Error::new(
                    crate::Kind::TypeError,
                    format!(
                        "array_map(): Argument #2 ($array) must be of type array, {} given",
                        sql::value_name(other)
                    ),
                ));
            }
            None => Array::new(),
        };
        binds.set(format!(":vector_{placeholder}"), sql::json_encode(&Value::Array(vector)));
        let op = match query.method() {
            Method::VectorDot => "<#>",
            Method::VectorCosine => "<=>",
            Method::VectorEuclidean => "<->",
            _ => return Ok(None),
        };
        Ok(Some(format!("({alias}.{attribute} {op} :vector_{placeholder}::vector)")))
    }

    /// `getSQLPermissionsCondition(...)` (Postgres): the row's own
    /// `_permissions` containing one of the roles' permissions.
    fn permissions_condition(&self, roles: &[Value], kind: &str) -> Result<String> {
        if !Database::PERMISSIONS.contains(&kind) {
            return Err(Error::database(format!("Unknown permission type: {kind}")));
        }
        let column = format!("{}.{}", quote(ALIAS), quote("_permissions"));
        if roles.is_empty() {
            return Ok("FALSE".to_owned());
        }
        let parts: Vec<String> = roles
            .iter()
            .map(|role| {
                let permission = format!("{kind}(\"{}\")", interpolate(role));
                let json = sql::json_text(&Value::Array([Value::Str(permission.into())].into_iter().collect()));
                let quoted = super::pdo::quote(json.as_bytes()).unwrap_or_else(|| b"''".to_vec());
                format!("{column} @> {}::jsonb", String::from_utf8_lossy(&quoted))
            })
            .collect();
        Ok(format!("({})", parts.join(" OR ")))
    }

    /// The conditions shared by `find`, `count` and `sum`: the queries,
    /// the permission filter and the tenant.
    fn filters(
        &self,
        collection: &str,
        queries: &[Query],
        binds: &mut Binds,
        kind: &str,
        wheres: &mut Vec<String>,
    ) -> Result<()> {
        let name = filter(collection);
        let conditions = self.conditions(queries, binds, "AND")?;
        if !conditions.is_empty() {
            wheres.push(conditions);
        }
        let _ = name;
        let authorization = self.state.lock().authorization.clone();
        if authorization.status() {
            wheres.push(self.permissions_condition(&authorization.roles(), kind)?);
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
        let (vectors, others): (Vec<&Query>, Vec<&Query>) = queries.iter().partition(|q| q.method().is_vector());
        let others: Vec<Query> = others.into_iter().cloned().collect();

        let mut cursor_where = Vec::new();
        let count = options.order_attributes.len();
        for (i, original) in options.order_attributes.iter().enumerate() {
            let order_type = options.order_types.get(i).map(String::as_str).unwrap_or(Database::ORDER_ASC);
            if order_type == Database::ORDER_RANDOM {
                orders.push("RANDOM()".to_owned());
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
            wheres.push(self.permissions_condition(&authorization.roles(), options.for_permission)?);
        }
        if self.shared() {
            binds.set(":_tenant", self.tenant_of_state());
            wheres.push(self.tenant_query_with(&collection, ALIAS, 0, ""));
        }
        let sql_where = if wheres.is_empty() { String::new() } else { format!("WHERE {}", wheres.join(" AND ")) };

        let mut distances = Vec::new();
        for q in &vectors {
            if let Some(d) = self.vector_distance(q, &mut binds)? {
                distances.push(d);
            }
        }
        if !distances.is_empty() {
            let mut merged = distances.clone();
            merged.extend(orders);
            orders = merged;
        }
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
        let mut projection = projection(&selections(&others), ALIAS)?;
        if let Some(first) = distances.first() {
            projection.push_str(&format!(", {first}::text AS {}", quote("_distance")));
        }
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
        let rows = self.run_timed(&stmt).await.map_err(sql::processed)?.rows;
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
        self.filters(&collection, &others, &mut binds, Database::PERMISSION_READ, &mut wheres)?;
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
        let rows = self.run_timed(&stmt).await.map_err(sql::processed)?.rows;
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
        self.filters(&collection, &others, &mut binds, Database::PERMISSION_READ, &mut wheres)?;
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
        let rows = self.run_timed(&stmt).await.map_err(sql::processed)?.rows;
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

/// `handleObjectQueries(...)`: JSONB containment.
fn object_condition(
    query: &Query,
    binds: &mut Binds,
    attribute: &str,
    alias: &str,
    placeholder: &str,
) -> Result<String> {
    let method = query.method();
    match method {
        Method::Equal | Method::NotEqual => {
            let is_not = *method == Method::NotEqual;
            let mut conditions = Vec::new();
            for (key, value) in query.values().iter().enumerate() {
                binds.set(format!(":{placeholder}_{key}"), sql::json_encode(value));
                let fragment = format!("{alias}.{attribute} @> :{placeholder}_{key}::jsonb");
                conditions.push(if is_not { format!("NOT ({fragment})") } else { fragment });
            }
            let separator = if is_not { " AND " } else { " OR " };
            Ok(if conditions.is_empty() { String::new() } else { format!("({})", conditions.join(separator)) })
        }
        Method::Contains | Method::ContainsAny | Method::ContainsAll | Method::NotContains => {
            let is_not = *method == Method::NotContains;
            let mut conditions = Vec::new();
            for (key, value) in query.values().iter().enumerate() {
                let mut value = value.clone();
                match &mut value {
                    Value::Array(a) if a.len() == 1 => {
                        let first = a.keys().next().map(|k| k.to_owned());
                        if let Some(k) = first
                            && let Some(v) = a.get(&k).cloned()
                            && !matches!(v, Value::Array(_))
                        {
                            a.set(k, Value::Array([v].into_iter().collect()));
                        }
                    }
                    Value::Array(_) => {}
                    other => {
                        return Err(Error::new(
                            crate::Kind::TypeError,
                            format!(
                                "count(): Argument #1 ($value) must be of type Countable|array, {} given",
                                sql::value_name(other)
                            ),
                        ));
                    }
                }
                binds.set(format!(":{placeholder}_{key}"), sql::json_encode(&value));
                let fragment = format!("{alias}.{attribute} @> :{placeholder}_{key}::jsonb");
                conditions.push(if is_not { format!("NOT ({fragment})") } else { fragment });
            }
            let separator = if is_not { " AND " } else { " OR " };
            Ok(if conditions.is_empty() { String::new() } else { format!("({})", conditions.join(separator)) })
        }
        other => Err(Error::database(format!("Query method {} not supported for object attributes", other.as_str()))),
    }
}
