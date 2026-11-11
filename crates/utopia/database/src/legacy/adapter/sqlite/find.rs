//! Finds, counts and sums: `WHERE` conditions for every query method
//! (comparisons, `LIKE` with explicit escapes, `REGEXP`, FTS5 searches,
//! JSON array membership), cursor pagination and the document-level
//! permission filter.
//!
//! PHP: `SQL::find`, `SQL::count`, `SQL::sum`, `SQL::getSQLConditions`,
//! `SQL::getSQLPermissionsCondition`, `SQLite::getSQLCondition`,
//! `SQLite::getLikeCondition`, `SQLite::buildArrayContainsCondition`,
//! `SQLite::buildSearchLikeFallback`, `SQLite::getFTS5Value`,
//! `MariaDB::getSQLCondition`, `MariaDB::handleSpatialQueries`,
//! `MariaDB::handleDistanceSpatialQueries`.

use php_std::pcre;

use super::Sqlite;
use super::documents::{ALIAS, projection, selections, value_name};
use super::pdo::Statement;
use super::sql::{self, Binds, bind_typed, filter, quote};
use crate::adapter::FindOptions;
use crate::database::Database;
use crate::document::Document;
use crate::error::{Error, Result};
use crate::query::{Method, Query};
use crate::value::{ArrayKey, Str, Value};

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

/// `preg_replace($pattern, $replacement, $subject)`, `''` when it fails.
fn preg_replace(pattern: &[u8], replacement: &[u8], subject: &[u8]) -> Vec<u8> {
    match pcre::preg_replace(
        &pcre::StrOrArray::Str(pattern),
        &pcre::StrOrArray::Str(replacement),
        &pcre::StrOrArray::Str(subject),
        -1,
    ) {
        Ok(p) => match p.value.result {
            pcre::Value::Str(s) => s,
            _ => Vec::new(),
        },
        Err(_) => Vec::new(),
    }
}

/// `getFTS5Value($value)`: the search term as an FTS5 query: its words
/// (letters, digits and `_`) joined with `OR`, the last one a prefix
/// (`word*`), operators quoted; a `"quoted"` term stays a phrase.
fn fts5_value(value: &[u8]) -> Vec<u8> {
    let exact = value.len() >= 2
        && value.starts_with(b"\"")
        && value.ends_with(b"\"")
        && value.iter().filter(|b| **b == b'"').count() == 2;
    let sanitized = preg_replace(br"/[^\p{L}\p{N}_\s]+/u", b" ", value);
    let sanitized = preg_replace(br"/\s+/", b" ", &sanitized);
    let sanitized = php_std::string::trim(&sanitized, b" \n\r\t\x0B\0").to_vec();
    if sanitized.is_empty() {
        return Vec::new();
    }
    if exact {
        let mut out = b"\"".to_vec();
        out.extend_from_slice(&sanitized);
        out.push(b'"');
        return out;
    }
    let mut tokens: Vec<Vec<u8>> = sanitized
        .split(|b| *b == b' ')
        .map(|token| {
            let operator = matches!(
                pcre::preg_match_bare(br"/^(AND|OR|NOT|NEAR)$/i", token),
                Ok(r) if matches!(r.value, pcre::Value::Int(1))
            );
            if operator {
                let mut quoted = b"\"".to_vec();
                quoted.extend_from_slice(token);
                quoted.push(b'"');
                quoted
            } else {
                token.to_vec()
            }
        })
        .collect();
    if let Some(mut last) = tokens.pop() {
        if !last.starts_with(b"\"") {
            last.push(b'*');
        }
        tokens.push(last);
    }
    tokens.join(&b" OR "[..])
}

/// A value passed to `escapeWildcards(string $value)`: scalars are
/// converted as PHP's weak typing converts them; `null`, arrays and objects
/// are a `TypeError` naming the call site (a line of `SQLite.php`).
fn string_param(value: &Value, line: u32) -> Result<Str> {
    match value {
        Value::Str(s) => Ok(s.clone()),
        Value::Int(_) | Value::Float(_) | Value::Bool(_) => Ok(value.to_php_string().unwrap_or(Str::EMPTY)),
        other => Err(Error::new(
            crate::Kind::TypeError,
            format!(
                "Utopia\\Database\\Adapter\\SQL::escapeWildcards(): Argument #1 ($value) must be of type string, {} given, called in /usr/src/code/vendor/utopia-php/database/src/Database/Adapter/SQLite.php on line {line}",
                value_name(other)
            ),
        )),
    }
}

/// `getSQLOperator($method)` (SQL, with `LIKE` and `REGEXP`).
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
/// of `MariaDB.php` (PHP's `TypeError` names the call site).
fn wkt(value: Option<&Value>, line: u32) -> Result<Value> {
    match value {
        Some(Value::Array(a)) => Ok(Value::Str(sql::array_to_wkt(a)?.into())),
        other => Err(Error::new(
            crate::Kind::TypeError,
            format!(
                "Utopia\\Database\\Adapter\\SQL::convertArrayToWKT(): Argument #1 ($geometry) must be of type array, {} given, called in /usr/src/code/vendor/utopia-php/database/src/Database/Adapter/MariaDB.php on line {line}",
                value_name(other.unwrap_or(&Value::Null))
            ),
        )),
    }
}

impl Sqlite {
    /// `getSQLConditions($queries, $binds, $separator, $forCollection)`.
    pub(super) fn conditions(
        &self,
        queries: &[Query],
        binds: &mut Binds,
        separator: &str,
        collection: Option<&str>,
    ) -> Result<String> {
        let mut conditions = Vec::new();
        for query in queries {
            if *query.method() == Method::Select {
                continue;
            }
            if query.is_nested() {
                let nested: Vec<Query> = query.queries().cloned().collect();
                conditions.push(self.conditions(&nested, binds, query.method().as_str(), collection)?);
            } else {
                conditions.push(self.condition(query.clone(), binds, collection)?);
            }
        }
        let tmp = conditions.join(&format!(" {separator} "));
        Ok(if tmp.is_empty() || tmp == "0" { String::new() } else { format!("({tmp})") })
    }

    /// `getSQLCondition($query, $binds, $forCollection)` (SQLite): `LIKE`
    /// methods with an explicit escape, array membership through
    /// `json_each`, searches through the FTS5 table; MariaDB's for the rest.
    fn condition(&self, mut query: Query, binds: &mut Binds, collection: Option<&str>) -> Result<String> {
        let method = query.method().clone();
        let like = matches!(
            method,
            Method::StartsWith
                | Method::NotStartsWith
                | Method::EndsWith
                | Method::NotEndsWith
                | Method::Contains
                | Method::ContainsAny
                | Method::NotContains
        );
        if like {
            if query.on_array() && matches!(method, Method::Contains | Method::ContainsAny | Method::NotContains) {
                return array_contains_condition(query, binds);
            }
            return like_condition(query, binds);
        }
        if method != Method::Search && method != Method::NotSearch {
            return self.mariadb_condition(query, binds, collection);
        }
        let attribute = sql::internal_key(query.attribute()).to_owned();
        query.set_attribute(attribute.clone());
        let attribute = filter(&attribute);
        let alias = quote(ALIAS);
        let placeholder = unique();
        let raw = query.values().first().cloned().unwrap_or(Value::Null).to_php_string().map_err(Error::from)?;
        let value = fts5_value(raw.as_bytes());
        let search = method == Method::Search;
        if value.is_empty() {
            return Ok(if search { "1 = 0" } else { "1 = 1" }.to_owned());
        }
        let table = match collection {
            None => None,
            Some(c) => self.fulltext_table_for_attribute(c, &attribute).map_err(super::ddl::Failure::raw)?,
        };
        let Some(table) = table else {
            let mut bound = b"%".to_vec();
            bound.extend_from_slice(&escape_wildcards(raw.as_bytes()));
            bound.push(b'%');
            binds.set(format!(":{placeholder}_0"), Value::Str(Str::from(bound)));
            let sql = format!("{alias}.{} LIKE :{placeholder}_0 ESCAPE '\\'", quote(&attribute));
            return Ok(if search { sql } else { format!("NOT ({sql})") });
        };
        binds.set(format!(":{placeholder}_0"), Value::Str(Str::from(value)));
        let sub = format!("{alias}.`_id` IN (SELECT rowid FROM `{table}` WHERE `{table}` MATCH :{placeholder}_0)");
        Ok(if search { sub } else { format!("NOT ({sub})") })
    }

    /// `getSQLCondition($query, $binds)` (MariaDB).
    fn mariadb_condition(&self, mut query: Query, binds: &mut Binds, collection: Option<&str>) -> Result<String> {
        let attribute = sql::internal_key(query.attribute()).to_owned();
        query.set_attribute(attribute.clone());
        let attribute = quote(&filter(&attribute));
        let alias = quote(ALIAS);
        let placeholder = unique();
        if query.is_spatial_attribute() {
            return spatial_condition(&query, binds, &attribute, &alias, &placeholder);
        }
        let values = query.values();
        let method = query.method().clone();
        match method {
            Method::Or | Method::And => {
                let mut conditions = Vec::new();
                for q in query.queries() {
                    conditions.push(self.condition(q.clone(), binds, collection)?);
                }
                let m = method.as_str().to_uppercase();
                Ok(if conditions.is_empty() { String::new() } else { format!(" {m} ({})", conditions.join(" AND ")) })
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
            _ => {
                // The operator is looked up per value: no values, no error.
                let mut conditions = Vec::new();
                for (key, value) in values.iter().enumerate() {
                    binds.set(format!(":{placeholder}_{key}"), value.clone());
                    let operator = sql_operator(&method)?;
                    conditions.push(format!("{alias}.{attribute} {operator} :{placeholder}_{key}"));
                }
                Ok(if conditions.is_empty() { String::new() } else { format!("({})", conditions.join(" OR ")) })
            }
        }
    }

    /// `getSQLPermissionsCondition($collection, $roles, $alias, $type)` (SQL):
    /// the row's id among the documents its permissions table grants one of
    /// the roles.
    fn permissions_condition(&self, collection: &str, roles: &[Value], kind: &str) -> Result<String> {
        if !Database::PERMISSIONS.contains(&kind) {
            return Err(Error::database(format!("Unknown permission type: {kind}")));
        }
        let mut quoted = Vec::with_capacity(roles.len());
        for role in roles {
            let q = super::pdo::Pdo::quote(role.to_php_string().map_err(Error::from)?.as_bytes())?;
            quoted.push(String::from_utf8_lossy(&q).into_owned());
        }
        Ok(format!(
            "{}.{} IN (\n            SELECT _document\n            FROM {}\n            WHERE _permission IN ({})\n              AND _type = '{kind}'\n              {}\n        )",
            quote(ALIAS),
            quote("_uid"),
            self.sql_table(&format!("{collection}_perms")),
            quoted.join(", "),
            self.tenant_sql(collection)
        ))
    }

    /// The conditions shared by `count` and `sum`: the queries, the
    /// permission filter and the tenant.
    fn filters(&self, collection: &str, queries: &[Query], binds: &mut Binds, wheres: &mut Vec<String>) -> Result<()> {
        let name = filter(collection);
        let conditions = self.conditions(queries, binds, "AND", Some(&name))?;
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

    /// `find($collection, $queries, ...)` (SQL).
    pub(super) fn find_impl(
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
        let others: Vec<Query> = queries.iter().filter(|q| !q.method().is_vector()).cloned().collect();

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
        let conditions = self.conditions(&others, &mut binds, "AND", Some(&name))?;
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
        // Prepared (natively, so a bad statement fails here) before any
        // value is bound, inside the try.
        self.pdo().prepare(&stmt).map_err(sql::processed)?;
        for (key, value) in binds.0 {
            match value {
                Value::Float(f) => stmt.bind_str(&key, self.float_literal(f)?),
                other => bind_typed(&mut stmt, &key, other)?,
            }
        }
        let rows = self.run(&stmt).map_err(sql::processed)?.rows;
        let mut results = Vec::with_capacity(rows.len());
        for row in rows {
            results.push(sql::row_to_document(row, true)?);
        }
        if options.cursor_direction == Database::CURSOR_BEFORE {
            results.reverse();
        }
        Ok(results)
    }

    /// `count($collection, $queries, $max)` (SQL).
    pub(super) fn count_impl(&self, collection: &Document, queries: &[Query], max: Option<i64>) -> Result<i64> {
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
        self.pdo().prepare(&stmt).map_err(sql::raw)?;
        for (key, value) in binds.0 {
            bind_typed(&mut stmt, &key, value)?;
        }
        let rows = self.run(&stmt).map_err(sql::processed)?.rows;
        let sum = rows.first().and_then(|r| r.get_str("sum")).filter(|v| !matches!(v, Value::Null)).cloned();
        Ok(sum.map(|v| super::pdo::to_long(&v)).unwrap_or(0))
    }

    /// `sum($collection, $attribute, $queries, $max)` (SQL): an integer, or
    /// a float when the sum is not integral.
    pub(super) fn sum_impl(
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
        self.pdo().prepare(&stmt).map_err(sql::raw)?;
        for (key, value) in binds.0 {
            bind_typed(&mut stmt, &key, value)?;
        }
        let rows = self.run(&stmt).map_err(sql::processed)?.rows;
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

/// `buildArrayContainsCondition($query, $binds)`: an element of the JSON
/// array among the values (`json_each`).
fn array_contains_condition(mut query: Query, binds: &mut Binds) -> Result<String> {
    let method = query.method().clone();
    let attribute = sql::internal_key(query.attribute()).to_owned();
    query.set_attribute(attribute.clone());
    let attribute = quote(&filter(&attribute));
    let alias = quote(ALIAS);
    let placeholder = unique();
    let values = query.values();
    if values.is_empty() {
        return Ok(String::new());
    }
    let mut params = Vec::with_capacity(values.len());
    for (key, value) in values.iter().enumerate() {
        let param = format!(":{placeholder}_{key}");
        binds.set(param.clone(), value.clone());
        params.push(param);
    }
    let expression =
        format!("EXISTS (SELECT 1 FROM json_each({alias}.{attribute}) WHERE value IN ({}))", params.join(", "));
    Ok(if method == Method::NotContains { format!("NOT {expression}") } else { expression })
}

/// `getLikeCondition($query, $binds)`: `LIKE` with `ESCAPE '\'`.
fn like_condition(mut query: Query, binds: &mut Binds) -> Result<String> {
    let method = query.method().clone();
    let attribute = sql::internal_key(query.attribute()).to_owned();
    query.set_attribute(attribute.clone());
    let attribute = quote(&filter(&attribute));
    let alias = quote(ALIAS);
    let placeholder = unique();
    let is_not = matches!(method, Method::NotStartsWith | Method::NotEndsWith | Method::NotContains);
    let mut conditions = Vec::new();
    for (key, value) in query.values().iter().enumerate() {
        let like = |prefix: &[u8], suffix: &[u8], line: u32| -> Result<Value> {
            let body = string_param(value, line)?;
            let mut v = prefix.to_vec();
            v.extend_from_slice(&escape_wildcards(body.as_bytes()));
            v.extend_from_slice(suffix);
            Ok(Value::Str(Str::from(v)))
        };
        let bound = match method {
            Method::StartsWith | Method::NotStartsWith => like(b"", b"%", 3243)?,
            Method::EndsWith | Method::NotEndsWith => like(b"%", b"", 3244)?,
            Method::Contains | Method::ContainsAny | Method::NotContains => like(b"%", b"%", 3245)?,
            _ => value.clone(),
        };
        binds.set(format!(":{placeholder}_{key}"), bound);
        let operator = if is_not { "NOT LIKE" } else { "LIKE" };
        conditions.push(format!("{alias}.{attribute} {operator} :{placeholder}_{key} ESCAPE '\\'"));
    }
    let separator = if is_not { " AND " } else { " OR " };
    Ok(if conditions.is_empty() { String::new() } else { format!("({})", conditions.join(separator)) })
}

/// `handleSpatialQueries(...)` (MariaDB; SQLite passes the WKT through
/// `getSpatialGeomFromText` unchanged).
fn spatial_condition(
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
        Ok(format!("{not}{function}({alias}.{attribute}, {p0})"))
    };
    match query.method() {
        Method::Crosses => simple(binds, "ST_Crosses", false, 1402),
        Method::NotCrosses => simple(binds, "ST_Crosses", true, 1406),
        Method::DistanceEqual | Method::DistanceNotEqual | Method::DistanceGreaterThan | Method::DistanceLessThan => {
            let param = |i: i64| -> Option<Value> {
                match first {
                    Some(Value::Array(a)) => a.get(&ArrayKey::Int(i)).cloned(),
                    Some(Value::Str(s)) => s.as_bytes().get(i as usize).map(|b| Value::Str(vec![*b].into())),
                    _ => None,
                }
            };
            let wkt_value = wkt(param(0).as_ref(), 1353)?;
            binds.set(p0.clone(), wkt_value.clone());
            binds.set(format!(":{placeholder}_1"), param(1).unwrap_or(Value::Null));
            let meters = matches!(param(2), Some(Value::Bool(true)));
            let operator = match query.method() {
                Method::DistanceEqual => "=",
                Method::DistanceNotEqual => "!=",
                Method::DistanceGreaterThan => ">",
                _ => "<",
            };
            if meters {
                let wkt_type = sql::type_from_wkt(crate::value::to_text(&wkt_value).as_bytes())?;
                let attr_type = query.attribute_type().to_lowercase();
                if wkt_type != Database::VAR_POINT.as_bytes() || attr_type != Database::VAR_POINT {
                    return Err(Error::query(format!(
                        "Distance in meters is not supported between {attr_type} and {}",
                        String::from_utf8_lossy(&wkt_type)
                    )));
                }
                return Ok(format!(
                    "ST_DISTANCE_SPHERE({alias}.{attribute}, {p0}, {}) {operator} :{placeholder}_1",
                    Database::EARTH_RADIUS
                ));
            }
            Ok(format!("ST_Distance({alias}.{attribute}, {p0}) {operator} :{placeholder}_1"))
        }
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
