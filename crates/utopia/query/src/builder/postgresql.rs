//! PostgreSQL: [`PostgreSql`], with `DISTINCT ON`, `MERGE`, `UPDATE ... FROM`,
//! `DELETE ... USING`, `RETURNING`, pgvector ordering, JSONB filters and
//! updates, `FILTER (WHERE ...)` aggregates and ordered-set aggregates.
//!
//! PHP: `Utopia\Query\Builder\PostgreSQL` and its `Trait\PostgreSQL\*` traits.

use php_std::ArrayKey;
use php_std::json::Flags;

use crate::builder::AnyBuilder;
use crate::builder::core::{Builder, Dialect, ExplainOptions, first};
use crate::builder::feature::{
    BitwiseAggregates, ConditionalAggregates, CrossJoins, Cube, FullOuterJoins, FullTextSearch, InsertOrIgnore, Json,
    LateralJoins, Locking, NegatedFullTextSearch, RawSql, Returning, Rollup, Sequences, Spatial, StatisticalAggregates,
    StringAggregates, TableSampling, Transactions, Upsert, UpsertSelect,
};
use crate::builder::mysql::conflict_clause;
use crate::builder::sql::{self, SqlDialect, distance_operator, wkt_of};
use crate::builder::types::{Condition, LockMode, MergeClause, Statement, VectorMetric};
use crate::error::{Error, Result};
use crate::method::Method;
use crate::query::Query;
use crate::value::{self, Array, IntoValues, Param, Value};

/// A PostgreSQL builder.
///
/// PHP: `Utopia\Query\Builder\PostgreSQL`.
///
/// ```
/// use utopia_query::Query;
/// use utopia_query::builder::PostgreSql;
///
/// let statement = PostgreSql::new()
///     .from("users", "")
///     .distinct_on(["email"])
///     .filter([Query::starts_with("name", "Al")])
///     .build()?;
/// assert_eq!(statement.query, r#"SELECT DISTINCT ON ("email") * FROM "users" WHERE "name" ILIKE ?"#);
/// # Ok::<(), utopia_query::Error>(())
/// ```
///
/// ```php
/// use Utopia\Query\Builder\PostgreSQL;
/// use Utopia\Query\Query;
///
/// echo (new PostgreSQL())->from('users')->distinctOn(['email'])
///     ->filter([Query::startsWith('name', 'Al')])->build()->query, "\n";
/// // SELECT DISTINCT ON ("email") * FROM "users" WHERE "name" ILIKE ?
/// ```
pub type PostgreSql = Builder<PostgreSqlDialect>;

/// The table joined by `UPDATE ... FROM`, with an extra condition.
///
/// PHP: `Utopia\Query\Builder\PostgreSQL\UpdateFrom`.
#[derive(Debug, Clone, Default, PartialEq)]
pub struct UpdateFrom {
    /// The joined table.
    pub table: String,
    /// Its alias.
    pub alias: String,
    /// The extra `WHERE` condition, or empty.
    pub condition: String,
    /// The condition's bindings.
    pub bindings: Vec<Value>,
}

/// The table of `DELETE ... USING`, with an extra condition.
///
/// PHP: `Utopia\Query\Builder\PostgreSQL\DeleteUsing`.
#[derive(Debug, Clone, Default, PartialEq)]
pub struct DeleteUsing {
    /// The `USING` table.
    pub table: String,
    /// The extra `WHERE` condition, or empty.
    pub condition: String,
    /// The condition's bindings.
    pub bindings: Vec<Value>,
}

/// The target, source and condition of a `MERGE`.
///
/// PHP: `Utopia\Query\Builder\PostgreSQL\MergeTarget`.
#[derive(Clone, Default)]
pub struct MergeTarget {
    /// The table merged into.
    pub target: String,
    /// The source query.
    pub source: Option<Box<dyn AnyBuilder>>,
    /// The source's alias.
    pub alias: String,
    /// The `ON` condition.
    pub condition: String,
    /// The condition's bindings.
    pub bindings: Vec<Value>,
}

#[derive(Clone)]
struct VectorOrder {
    attribute: String,
    vector: Array,
    metric: VectorMetric,
}

/// The PostgreSQL dialect and its own state.
#[derive(Clone, Default)]
pub struct PostgreSqlDialect {
    vector_order: Option<VectorOrder>,
    update_from: Option<UpdateFrom>,
    delete_using: Option<DeleteUsing>,
    merge_target: Option<MergeTarget>,
    merge_clauses: Vec<MergeClause>,
    distinct_on: Vec<String>,
}

/// `json_encode($value)` as a binding: the JSON string, or `false` when it fails.
pub(crate) fn json_binding(value: &Value) -> Value {
    match value::json_encode(value, Flags::NONE) {
        Ok(json) => value::string(json),
        Err(_) => Value::Bool(false),
    }
}

/// `/^[a-zA-Z0-9_.\[\]]+$/` (where `$` also matches before a final newline).
pub(crate) fn is_json_path(path: &str) -> bool {
    let bytes = path.strip_suffix('\n').unwrap_or(path).as_bytes();
    !bytes.is_empty() && bytes.iter().all(|&b| b.is_ascii_alphanumeric() || matches!(b, b'_' | b'.' | b'[' | b']'))
}

/// The value at `index` of a list (`$values[i]`, null when absent).
pub(crate) fn at(values: &Array, index: i64) -> Value {
    values.get(&ArrayKey::Int(index)).cloned().unwrap_or(Value::Null)
}

/// Validates the path and operator of a `jsonPath` filter and binds its value.
pub(crate) fn json_path_parts<D: Dialect>(b: &mut Builder<D>, values: &Array) -> Result<(String, String)> {
    const SUBJECT: Param<'static> = Param::internal("preg_match", 2, "subject");
    let path = SUBJECT.string(&at(values, 0))?.into_owned();
    if !is_json_path(&path) {
        return Err(Error::Validation(format!("Invalid JSON path: {path}")));
    }
    let operator = match at(values, 1) {
        Value::Str(s) if ["=", "!=", "<", ">", "<=", ">=", "<>"].iter().any(|o| o.as_bytes() == s.as_bytes()) => {
            String::from_utf8_lossy(s.as_bytes()).into_owned()
        }
        other => {
            return Err(Error::Validation(format!("Invalid JSON path operator: {}", value::concat(&other)?)));
        }
    };
    b.add_binding(at(values, 2), None);
    Ok((path, operator))
}

/// A full-text search term: `$values[0] ?? ''` as `str_ends_with()` takes it.
pub(crate) fn search_term(values: &Array) -> Result<String> {
    const HAYSTACK: Param<'static> = Param::internal("str_ends_with", 1, "haystack");
    Ok(match values.get(&ArrayKey::Int(0)) {
        None | Some(Value::Null) => String::new(),
        Some(term) => HAYSTACK.string(term)?.into_owned(),
    })
}

/// PHP's `trim()` default characters.
const TRIM: [char; 6] = [' ', '\t', '\n', '\r', '\0', '\x0B'];

fn is_pcre_space(c: char) -> bool {
    matches!(c, ' ' | '\t' | '\n' | '\r' | '\x0B' | '\x0C')
}

/// `ON CONFLICT (keys) DO UPDATE SET` (PostgreSQL and SQLite).
pub(crate) fn on_conflict_header<D: Dialect>(b: &mut Builder<D>) -> Result<String> {
    let keys = b.core.conflict_keys.clone();
    let wrapped = keys.iter().map(|k| b.resolve_and_wrap(k)).collect::<Result<Vec<_>>>()?;
    Ok(format!("ON CONFLICT ({}) DO UPDATE SET", wrapped.join(", ")))
}

fn search_expression(b: &mut Builder<PostgreSqlDialect>, attribute: &str, values: &Array, not: bool) -> Result<String> {
    let term = search_term(values)?;
    let exact = term.ends_with('"') && term.starts_with('"');
    let mut collapsed = String::with_capacity(term.len());
    let mut in_space = false;
    for c in term.chars() {
        let c =
            if matches!(c, '@' | '+' | '-' | '*' | '.' | '\'' | '"' | ')' | '(' | '<' | '>' | '~') { ' ' } else { c };
        if is_pcre_space(c) {
            if !in_space {
                collapsed.push(' ');
            }
            in_space = true;
        } else {
            collapsed.push(c);
            in_space = false;
        }
    }
    let sanitized = collapsed.trim_matches(TRIM);
    if sanitized.is_empty() {
        return Ok(if not { "1 = 1" } else { "1 = 0" }.into());
    }
    let sanitized = if exact { format!("\"{sanitized}\"") } else { sanitized.replace(' ', " or ") };
    b.add_binding(value::string(sanitized), None);
    let tsvector = format!("to_tsvector(regexp_replace({attribute}, '[^\\w]+', ' ', 'g'))");
    Ok(if not {
        format!("NOT ({tsvector} @@ websearch_to_tsquery(?))")
    } else {
        format!("{tsvector} @@ websearch_to_tsquery(?)")
    })
}

/// A JSON array element of a `jsonb_set` path: plain when it is only
/// letters, digits and `_` (and not `NULL`), else double-quoted.
fn quote_array_element(element: &str) -> String {
    let plain = !element.is_empty()
        && element.bytes().all(|b| b.is_ascii_alphanumeric() || b == b'_')
        && !element.eq_ignore_ascii_case("NULL");
    if plain {
        return element.to_owned();
    }
    format!("\"{}\"", element.replace('\\', "\\\\").replace('"', "\\\""))
}

/// A JSON key as a string literal (`E'...'` when it has a backslash).
fn quote_json_key(key: &str) -> Result<String> {
    if key.contains('\0') {
        return Err(Error::Validation("JSON key contains a NUL byte".into()));
    }
    let escaped = key.replace('\'', "''");
    if !key.contains('\\') {
        return Ok(format!("'{escaped}'"));
    }
    Ok(format!("E'{}'", escaped.replace('\\', "\\\\")))
}

impl Builder<PostgreSqlDialect> {
    /// `"col"->'a'->>'b'` for `col.a.b`.
    fn jsonb_path(&mut self, path: &str) -> Result<String> {
        let mut parts: Vec<&str> = path.split('.').collect();
        if parts.len() == 1 {
            return self.resolve_and_wrap(parts[0]);
        }
        let base = self.resolve_attribute(parts[0])?;
        let mut chain = self.quote(&base)?;
        let last = parts.pop().unwrap_or_default();
        for key in &parts[1..] {
            chain.push_str("->");
            chain.push_str(&quote_json_key(key)?);
        }
        chain.push_str("->>");
        chain.push_str(&quote_json_key(last)?);
        Ok(chain)
    }

    fn jsonb_containment(&mut self, attribute: &str, values: &Array, not: bool, wrap_scalars: bool) -> String {
        let mut conditions = Vec::with_capacity(values.len());
        for value in values.values() {
            let mut value = value.clone();
            if wrap_scalars
                && let Value::Array(object) = &mut value
                && object.len() == 1
            {
                let entry = object.iter().next().map(|(k, v)| (k.to_owned(), v.clone()));
                if let Some((key, inner)) = entry
                    && !matches!(inner, Value::Array(_))
                {
                    object.set(key, Value::Array(value::list([inner])));
                }
            }
            self.add_binding(json_binding(&value), None);
            let fragment = format!("{attribute} @> ?::jsonb");
            conditions.push(if not { format!("NOT ({fragment})") } else { fragment });
        }
        format!("({})", conditions.join(if not { " AND " } else { " OR " }))
    }

    fn object_filter(&mut self, query: &Query) -> Result<String> {
        let method = query.method();
        let raw = query.attribute();
        let values = query.values();
        use Method::*;
        if raw.contains('.') {
            let attribute = self.jsonb_path(raw)?;
            return match method {
                Equal => self.compile_in(&attribute, values, None),
                NotEqual => self.compile_not_in(&attribute, values, None),
                LessThan => Ok(self.compile_comparison(&attribute, "<", values, None)),
                LessThanEqual => Ok(self.compile_comparison(&attribute, "<=", values, None)),
                GreaterThan => Ok(self.compile_comparison(&attribute, ">", values, None)),
                GreaterThanEqual => Ok(self.compile_comparison(&attribute, ">=", values, None)),
                StartsWith => PostgreSqlDialect::compile_like(self, &attribute, values, "", "%", false, None),
                NotStartsWith => PostgreSqlDialect::compile_like(self, &attribute, values, "", "%", true, None),
                EndsWith => PostgreSqlDialect::compile_like(self, &attribute, values, "%", "", false, None),
                NotEndsWith => PostgreSqlDialect::compile_like(self, &attribute, values, "%", "", true, None),
                Contains => PostgreSqlDialect::compile_like(self, &attribute, values, "%", "%", false, None),
                NotContains => PostgreSqlDialect::compile_like(self, &attribute, values, "%", "%", true, None),
                IsNull => Ok(format!("{attribute} IS NULL")),
                IsNotNull => Ok(format!("{attribute} IS NOT NULL")),
                _ => sql::sql_compile_filter(self, query),
            };
        }
        let attribute = self.resolve_and_wrap(raw)?;
        let text = format!("{attribute}::text");
        match method {
            Equal | NotEqual => Ok(self.jsonb_containment(&attribute, values, method == NotEqual, false)),
            Contains | ContainsAny | ContainsAll | NotContains => {
                Ok(self.jsonb_containment(&attribute, values, method == NotContains, true))
            }
            StartsWith => PostgreSqlDialect::compile_like(self, &text, values, "", "%", false, None),
            NotStartsWith => PostgreSqlDialect::compile_like(self, &text, values, "", "%", true, None),
            EndsWith => PostgreSqlDialect::compile_like(self, &text, values, "%", "", false, None),
            NotEndsWith => PostgreSqlDialect::compile_like(self, &text, values, "%", "", true, None),
            IsNull => Ok(format!("{attribute} IS NULL")),
            IsNotNull => Ok(format!("{attribute} IS NOT NULL")),
            _ => sql::sql_compile_filter(self, query),
        }
    }

    fn vector_filter(&mut self, method: Method, attribute: &str, query: &Query) -> String {
        let operator = match method {
            Method::VectorEuclidean => "<->",
            Method::VectorDot => "<#>",
            _ => "<=>",
        };
        self.add_binding(json_binding(&first(query.values())), None);
        format!("({attribute} {operator} ?::vector)")
    }

    /// Appends `extra` to the trailing `WHERE` of `parts`, or adds one.
    fn merge_into_where(parts: &mut Vec<String>, extra: Vec<String>) {
        if extra.is_empty() {
            return;
        }
        match parts.last_mut() {
            Some(last) if last.starts_with("WHERE ") => {
                last.push_str(" AND ");
                last.push_str(&extra.join(" AND "));
            }
            _ => parts.push(format!("WHERE {}", extra.join(" AND "))),
        }
    }

    fn build_update_from(&mut self) -> Result<Statement> {
        self.core.bindings.clear();
        self.validate_table()?;
        let Some(from) = self.dialect.update_from.clone() else {
            return Err(Error::Validation("No UPDATE FROM target specified.".into()));
        };
        let assignments = self.compile_assignments()?;
        if assignments.is_empty() {
            return Err(Error::Validation("No assignments for UPDATE. Call set() or setRaw() before update().".into()));
        }
        let mut from_clause = self.quote(&from.table)?;
        if !from.alias.is_empty() {
            from_clause.push_str(" AS ");
            from_clause.push_str(&self.quote(&from.alias)?);
        }
        let mut parts =
            vec![format!("UPDATE {} SET {} FROM {from_clause}", self.quote(&self.core.table)?, assignments.join(", "))];
        let mut extra = Vec::new();
        if !from.condition.is_empty() {
            extra.push(from.condition);
            self.add_bindings(from.bindings);
        }
        self.compile_where_clauses(&mut parts, None)?;
        Self::merge_into_where(&mut parts, extra);
        Ok(self.statement(parts.join(" ")))
    }

    fn build_delete_using(&mut self) -> Result<Statement> {
        self.core.bindings.clear();
        self.validate_table()?;
        let Some(using) = self.dialect.delete_using.clone() else {
            return Err(Error::Validation("No DELETE USING target specified.".into()));
        };
        let mut parts =
            vec![format!("DELETE FROM {} USING {}", self.quote(&self.core.table)?, self.quote(&using.table)?)];
        let mut extra = Vec::new();
        if !using.condition.is_empty() {
            extra.push(using.condition);
            self.add_bindings(using.bindings);
        }
        self.compile_where_clauses(&mut parts, None)?;
        Self::merge_into_where(&mut parts, extra);
        Ok(self.statement(parts.join(" ")))
    }

    /// `UPDATE table SET ... FROM other [AS alias]`; keeps a condition set by
    /// [`Builder::update_from_where`].
    ///
    /// PHP: `updateFrom()`.
    pub fn update_from(&mut self, table: impl Into<String>, alias: impl Into<String>) -> &mut Self {
        let current = self.dialect.update_from.take().unwrap_or_default();
        self.dialect.update_from = Some(UpdateFrom { table: table.into(), alias: alias.into(), ..current });
        self
    }

    /// The condition joining the `UPDATE ... FROM` table, with its bindings.
    ///
    /// PHP: `updateFromWhere()`.
    pub fn update_from_where(&mut self, condition: impl Into<String>, bindings: impl IntoValues) -> &mut Self {
        let current = self.dialect.update_from.take().unwrap_or_default();
        self.dialect.update_from = Some(UpdateFrom {
            condition: condition.into(),
            bindings: bindings.into_values().values().cloned().collect(),
            ..current
        });
        self
    }

    /// `DELETE FROM table USING other WHERE condition`.
    ///
    /// PHP: `deleteUsing()`.
    pub fn delete_using(
        &mut self,
        table: impl Into<String>,
        condition: impl Into<String>,
        bindings: impl IntoValues,
    ) -> &mut Self {
        self.dialect.delete_using = Some(DeleteUsing {
            table: table.into(),
            condition: condition.into(),
            bindings: bindings.into_values().values().cloned().collect(),
        });
        self
    }

    /// `SELECT DISTINCT ON (columns)`.
    ///
    /// PHP: `distinctOn()`.
    pub fn distinct_on<S: Into<String>>(&mut self, columns: impl IntoIterator<Item = S>) -> &mut Self {
        self.dialect.distinct_on = columns.into_iter().map(Into::into).collect();
        self
    }

    /// `FOR UPDATE OF table`.
    ///
    /// PHP: `forUpdateOf()`.
    pub fn for_update_of(&mut self, table: impl Into<String>) -> &mut Self {
        self.core.lock_mode = Some(LockMode::ForUpdate);
        self.core.lock_of_table = Some(table.into());
        self
    }

    /// `FOR SHARE OF table`.
    ///
    /// PHP: `forShareOf()`.
    pub fn for_share_of(&mut self, table: impl Into<String>) -> &mut Self {
        self.core.lock_mode = Some(LockMode::ForShare);
        self.core.lock_of_table = Some(table.into());
        self
    }

    /// Orders by the pgvector distance of `attribute` to `vector` (nearest first).
    ///
    /// PHP: `orderByVectorDistance()`.
    pub fn order_by_vector_distance(
        &mut self,
        attribute: impl Into<String>,
        vector: impl IntoValues,
        metric: VectorMetric,
    ) -> &mut Self {
        self.dialect.vector_order =
            Some(VectorOrder { attribute: attribute.into(), vector: vector.into_values(), metric });
        self
    }

    /// `aggregate FILTER (WHERE condition) [AS alias]` in the select list.
    ///
    /// PHP: `selectAggregateFilter()`.
    pub fn select_aggregate_filter(
        &mut self,
        aggregate: &str,
        condition: &str,
        alias: &str,
        bindings: impl IntoValues,
    ) -> Result<&mut Self> {
        let mut expression = format!("{aggregate} FILTER (WHERE {condition})");
        if !alias.is_empty() {
            expression.push_str(" AS ");
            expression.push_str(&self.quote(alias)?);
        }
        Ok(self.select_raw(expression, bindings))
    }

    fn ordered_set(&mut self, expression: String, alias: &str, bindings: Vec<Value>) -> Result<&mut Self> {
        let mut expression = expression;
        if !alias.is_empty() {
            expression.push_str(" AS ");
            expression.push_str(&self.quote(alias)?);
        }
        Ok(self.select_raw(expression, bindings))
    }

    fn column_aggregate(&mut self, function: &str, column: &str, alias: &str) -> Result<&mut Self> {
        let expression = format!("{function}({})", self.resolve_and_wrap(column)?);
        self.ordered_set(expression, alias, Vec::new())
    }

    /// `ARRAY_AGG(column)`.
    ///
    /// PHP: `arrayAgg()`.
    pub fn array_agg(&mut self, column: &str, alias: &str) -> Result<&mut Self> {
        self.column_aggregate("ARRAY_AGG", column, alias)
    }

    /// `BOOL_AND(column)`.
    ///
    /// PHP: `boolAnd()`.
    pub fn bool_and(&mut self, column: &str, alias: &str) -> Result<&mut Self> {
        self.column_aggregate("BOOL_AND", column, alias)
    }

    /// `BOOL_OR(column)`.
    ///
    /// PHP: `boolOr()`.
    pub fn bool_or(&mut self, column: &str, alias: &str) -> Result<&mut Self> {
        self.column_aggregate("BOOL_OR", column, alias)
    }

    /// `EVERY(column)`.
    ///
    /// PHP: `every()`.
    pub fn every(&mut self, column: &str, alias: &str) -> Result<&mut Self> {
        self.column_aggregate("EVERY", column, alias)
    }

    /// `MODE() WITHIN GROUP (ORDER BY column)`.
    ///
    /// PHP: `mode()`.
    pub fn mode(&mut self, column: &str, alias: &str) -> Result<&mut Self> {
        let expression = format!("MODE() WITHIN GROUP (ORDER BY {})", self.resolve_and_wrap(column)?);
        self.ordered_set(expression, alias, Vec::new())
    }

    /// `PERCENTILE_CONT(fraction) WITHIN GROUP (ORDER BY column)`.
    ///
    /// PHP: `percentileCont()`.
    pub fn percentile_cont(&mut self, fraction: f64, order_column: &str, alias: &str) -> Result<&mut Self> {
        let expression = format!("PERCENTILE_CONT(?) WITHIN GROUP (ORDER BY {})", self.resolve_and_wrap(order_column)?);
        self.ordered_set(expression, alias, vec![Value::Float(fraction)])
    }

    /// `PERCENTILE_DISC(fraction) WITHIN GROUP (ORDER BY column)`.
    ///
    /// PHP: `percentileDisc()`.
    pub fn percentile_disc(&mut self, fraction: f64, order_column: &str, alias: &str) -> Result<&mut Self> {
        let expression = format!("PERCENTILE_DISC(?) WITHIN GROUP (ORDER BY {})", self.resolve_and_wrap(order_column)?);
        self.ordered_set(expression, alias, vec![Value::Float(fraction)])
    }

    /// The table a `MERGE` writes to.
    ///
    /// PHP: `mergeInto()`.
    pub fn merge_into(&mut self, target: impl Into<String>) -> &mut Self {
        let current = self.dialect.merge_target.take().unwrap_or_default();
        self.dialect.merge_target = Some(MergeTarget { target: target.into(), ..current });
        self
    }

    /// The source query of a `MERGE`, and its alias.
    ///
    /// PHP: `using()`.
    pub fn using(&mut self, source: Box<dyn AnyBuilder>, alias: impl Into<String>) -> &mut Self {
        let current = self.dialect.merge_target.take().unwrap_or_default();
        self.dialect.merge_target = Some(MergeTarget { source: Some(source), alias: alias.into(), ..current });
        self
    }

    /// The `ON` condition of a `MERGE`, with its bindings.
    ///
    /// PHP: `on()`.
    pub fn on(&mut self, condition: impl Into<String>, bindings: impl IntoValues) -> &mut Self {
        let current = self.dialect.merge_target.take().unwrap_or_default();
        self.dialect.merge_target = Some(MergeTarget {
            condition: condition.into(),
            bindings: bindings.into_values().values().cloned().collect(),
            ..current
        });
        self
    }

    /// `WHEN MATCHED THEN action`.
    ///
    /// PHP: `whenMatched()`.
    pub fn when_matched(&mut self, action: impl Into<String>, bindings: impl IntoValues) -> &mut Self {
        self.dialect.merge_clauses.push(MergeClause::new(
            action,
            true,
            bindings.into_values().values().cloned().collect(),
        ));
        self
    }

    /// `WHEN NOT MATCHED THEN action`.
    ///
    /// PHP: `whenNotMatched()`.
    pub fn when_not_matched(&mut self, action: impl Into<String>, bindings: impl IntoValues) -> &mut Self {
        self.dialect.merge_clauses.push(MergeClause::new(
            action,
            false,
            bindings.into_values().values().cloned().collect(),
        ));
        self
    }

    /// Compiles the `MERGE INTO target USING (source) AS alias ON condition WHEN ...`.
    ///
    /// PHP: `executeMerge()`.
    pub fn execute_merge(&mut self) -> Result<Statement> {
        let merge = match &self.dialect.merge_target {
            Some(m) if !m.target.is_empty() => m.clone(),
            _ => {
                return Err(Error::Validation(
                    "No merge target specified. Call mergeInto() before executeMerge().".into(),
                ));
            }
        };
        let Some(mut source) = merge.source else {
            return Err(Error::Validation("No merge source specified. Call using() before executeMerge().".into()));
        };
        if merge.condition.is_empty() {
            return Err(Error::Validation("No merge condition specified. Call on() before executeMerge().".into()));
        }
        self.core.bindings.clear();
        let result = source.build()?;
        self.add_bindings(result.bindings);
        let mut sql = format!(
            "MERGE INTO {} USING ({}) AS {} ON {}",
            self.quote(&merge.target)?,
            result.query,
            self.quote(&merge.alias)?,
            merge.condition
        );
        self.add_bindings(merge.bindings);
        for clause in self.dialect.merge_clauses.clone() {
            sql.push_str(if clause.matched { " WHEN MATCHED THEN " } else { " WHEN NOT MATCHED THEN " });
            sql.push_str(&clause.action);
            self.add_bindings(clause.bindings);
        }
        Ok(self.statement(sql))
    }
}

/// `^SELECT(\s+DISTINCT)?`: the length of the select keyword (and `DISTINCT`) to replace.
fn select_keyword_len(query: &str) -> Option<usize> {
    let rest = query.strip_prefix("SELECT")?;
    let space = rest.len() - rest.trim_start_matches(is_pcre_space).len();
    if space > 0 && rest[space..].starts_with("DISTINCT") {
        return Some(6 + space + 8);
    }
    Some(6)
}

impl Dialect for PostgreSqlDialect {
    const CLASS: &'static str = "Utopia\\Query\\Builder\\PostgreSQL";
    const WRAP: char = '"';

    fn compile_random(_b: &Builder<Self>) -> String {
        "RANDOM()".into()
    }

    fn compile_regex(b: &mut Builder<Self>, attribute: &str, values: &Array, column: Option<&str>) -> Result<String> {
        b.add_binding(first(values), column);
        Ok(format!("{attribute} ~ ?"))
    }

    fn compile_filter(b: &mut Builder<Self>, query: &Query) -> Result<String> {
        let method = query.method();
        if method.is_vector() {
            let attribute = b.resolve_and_wrap(query.attribute())?;
            return Ok(b.vector_filter(method, &attribute, query));
        }
        if query.attribute_type() == "object" {
            return b.object_filter(query);
        }
        sql::sql_compile_filter(b, query)
    }

    fn like_keyword(_b: &Builder<Self>) -> &'static str {
        "ILIKE"
    }

    fn should_emit_offset(_b: &Builder<Self>, offset: Option<i64>, _limit: Option<i64>) -> Result<bool> {
        Ok(offset.is_some())
    }

    fn compile_vector_order_expression(b: &mut Builder<Self>) -> Result<Option<Condition>> {
        let Some(order) = b.dialect.vector_order.clone() else {
            return Ok(None);
        };
        let attribute = b.resolve_and_wrap(&order.attribute)?;
        Ok(Some(Condition::new(
            format!("({attribute} {} ?::vector) ASC", order.metric.to_operator()),
            vec![json_binding(&Value::Array(order.vector))],
        )))
    }

    fn build(b: &mut Builder<Self>) -> Result<Statement> {
        let result = b.base_build()?;
        let mut query = result.query.clone();
        let mut modified = false;
        if !b.dialect.distinct_on.is_empty() {
            let columns = b.dialect.distinct_on.clone();
            let wrapped = columns.iter().map(|c| b.resolve_and_wrap(c)).collect::<Result<Vec<_>>>()?;
            if let Some(len) = select_keyword_len(&query) {
                query = format!("SELECT DISTINCT ON ({}){}", wrapped.join(", "), &query[len..]);
            }
            modified = true;
        }
        if let Some(modifier) = b.core.group_by_modifier.clone() {
            if let Some(position) = query.find("GROUP BY ") {
                let after = position + 9;
                let end = ["HAVING ", "WINDOW ", "ORDER BY ", "LIMIT ", "OFFSET ", "FETCH ", "FOR "]
                    .iter()
                    .filter_map(|keyword| query[after..].find(keyword).map(|p| p + after))
                    .min();
                let columns = match end {
                    Some(end) => query[after..end].trim_end_matches(TRIM),
                    None => &query[after..],
                };
                let mut rebuilt = format!("{}GROUP BY {modifier}({columns})", &query[..position]);
                if let Some(end) = end {
                    rebuilt.push(' ');
                    rebuilt.push_str(&query[end..]);
                }
                query = rebuilt;
            }
            modified = true;
        }
        if modified {
            return Ok(Statement::with_parts(query, result.bindings, result.read_only, b.core.executor.clone(), None));
        }
        Ok(result)
    }

    fn insert(b: &mut Builder<Self>) -> Result<Statement> {
        let result = b.base_insert()?;
        b.append_returning(result)
    }

    fn insert_default_values(b: &mut Builder<Self>) -> Result<Statement> {
        let result = b.base_insert_default_values()?;
        b.append_returning(result)
    }

    fn update(b: &mut Builder<Self>) -> Result<Statement> {
        for (column, condition) in b.core.json_sets.0.clone() {
            b.core.raw_sets.set(column.clone(), condition.expression);
            b.core.raw_set_bindings.set(column, condition.bindings);
        }
        let from = b.dialect.update_from.as_ref().is_some_and(|f| !f.table.is_empty());
        let result = if from { b.build_update_from()? } else { b.base_update()? };
        b.core.json_sets.clear();
        b.append_returning(result)
    }

    fn delete(b: &mut Builder<Self>) -> Result<Statement> {
        let using = b.dialect.delete_using.as_ref().is_some_and(|d| !d.table.is_empty());
        let result = if using { b.build_delete_using()? } else { b.base_delete()? };
        b.append_returning(result)
    }

    fn explain(b: &mut Builder<Self>, analyze: bool, options: &ExplainOptions) -> Result<Statement> {
        let format = options.format.to_ascii_uppercase();
        if !["", "TEXT", "XML", "JSON", "YAML"].contains(&format.as_str()) {
            return Err(Error::Validation(format!("Invalid EXPLAIN format: {}", options.format)));
        }
        let result = Self::build(b)?;
        let mut flags = Vec::new();
        if analyze {
            flags.push("ANALYZE".to_owned());
        }
        if options.verbose {
            flags.push("VERBOSE".to_owned());
        }
        if options.buffers {
            flags.push("BUFFERS".to_owned());
        }
        if !format.is_empty() {
            flags.push(format!("FORMAT {format}"));
        }
        let prefix = if flags.is_empty() { "EXPLAIN".to_owned() } else { format!("EXPLAIN ({})", flags.join(", ")) };
        Ok(Statement::with_parts(
            format!("{prefix} {}", result.query),
            result.bindings,
            true,
            b.core.executor.clone(),
            None,
        ))
    }

    fn reset(b: &mut Builder<Self>) {
        b.core.json_sets.clear();
        b.core.returning.clear();
        b.core.group_by_modifier = None;
        b.dialect = PostgreSqlDialect::default();
    }

    fn ast_dialect() -> crate::builder::AstDialect {
        crate::builder::AstDialect::PostgreSql
    }
}

impl SqlDialect for PostgreSqlDialect {
    fn compile_search_expression(b: &mut Builder<Self>, attribute: &str, values: &Array, not: bool) -> Result<String> {
        search_expression(b, attribute, values, not)
    }

    fn compile_spatial_distance(
        b: &mut Builder<Self>,
        method: Method,
        attribute: &str,
        values: &Array,
    ) -> Result<String> {
        let filter = sql::distance_filter(values, "Builder/PostgreSQL.php", 832)?;
        let operator = distance_operator(method);
        b.add_binding(wkt_of(&filter.geometry, Self::CLASS)?, None);
        b.add_binding(Value::Float(filter.distance), None);
        if filter.meters {
            return Ok(format!(
                "ST_Distance(({attribute}::geography), ST_SetSRID(ST_GeomFromText(?), 4326)::geography) {operator} ?"
            ));
        }
        Ok(format!("ST_Distance({attribute}, ST_GeomFromText(?, 4326)) {operator} ?"))
    }

    fn compile_spatial_predicate(
        b: &mut Builder<Self>,
        function: &str,
        attribute: &str,
        values: &Array,
        not: bool,
    ) -> Result<String> {
        let wkt = sql::predicate_wkt(values, Self::CLASS, "Builder/PostgreSQL.php", 861)?;
        b.add_binding(value::string(wkt), None);
        let expression = format!("{function}({attribute}, ST_GeomFromText(?, 4326))");
        Ok(if not { format!("NOT {expression}") } else { expression })
    }

    fn compile_spatial_covers_predicate(
        b: &mut Builder<Self>,
        attribute: &str,
        values: &Array,
        not: bool,
    ) -> Result<String> {
        Self::compile_spatial_predicate(b, "ST_Covers", attribute, values, not)
    }

    fn compile_json_contains_expression(
        b: &mut Builder<Self>,
        attribute: &str,
        values: &Array,
        not: bool,
    ) -> Result<String> {
        b.add_binding(json_binding(&first(values)), None);
        let expression = format!("{attribute} @> ?::jsonb");
        Ok(if not { format!("NOT ({expression})") } else { expression })
    }

    fn compile_json_overlaps_expression(b: &mut Builder<Self>, attribute: &str, values: &Array) -> Result<String> {
        let mut conditions = Vec::new();
        // `foreach` walks an array or an object's properties, and skips anything else.
        if let Value::Array(items) | Value::Object(items) = first(values) {
            for item in items.values() {
                b.add_binding(json_binding(item), None);
                conditions.push(format!("{attribute} @> ?::jsonb"));
            }
        }
        Ok(format!("({})", conditions.join(" OR ")))
    }

    fn compile_json_path_expression(b: &mut Builder<Self>, attribute: &str, values: &Array) -> Result<String> {
        let (path, operator) = json_path_parts(b, values)?;
        Ok(format!("{attribute}->>'{path}' {operator} ?"))
    }

    fn compile_conflict_header(b: &mut Builder<Self>) -> Result<String> {
        on_conflict_header(b)
    }

    fn compile_conflict_assignment(wrapped: &str) -> String {
        format!("EXCLUDED.{wrapped}")
    }
}

impl Locking for PostgreSqlDialect {}
impl Transactions for PostgreSqlDialect {}
impl StatisticalAggregates for PostgreSqlDialect {}
impl BitwiseAggregates for PostgreSqlDialect {}
impl RawSql for PostgreSqlDialect {}
impl CrossJoins for PostgreSqlDialect {}
impl FullOuterJoins for PostgreSqlDialect {}
impl LateralJoins for PostgreSqlDialect {}
impl Spatial for PostgreSqlDialect {}
impl FullTextSearch for PostgreSqlDialect {}
impl NegatedFullTextSearch for PostgreSqlDialect {}
impl Returning for PostgreSqlDialect {}

impl ConditionalAggregates for PostgreSqlDialect {
    /// `aggregate(column | *) FILTER (WHERE condition)`.
    fn aggregate_filter(
        b: &mut Builder<Self>,
        aggregate: &str,
        column: Option<&str>,
        condition: &str,
        alias: &str,
    ) -> Result<String> {
        let argument = match column {
            None => "*".to_owned(),
            Some(c) => b.resolve_and_wrap(c)?,
        };
        let mut expression = format!("{aggregate}({argument}) FILTER (WHERE {condition})");
        if !alias.is_empty() {
            expression.push_str(" AS ");
            expression.push_str(&b.quote(alias)?);
        }
        Ok(expression)
    }
}

impl StringAggregates for PostgreSqlDialect {
    fn group_concat_expr(column: &str, order_by: &str) -> String {
        if order_by.is_empty() {
            format!("STRING_AGG({column}, ?)")
        } else {
            format!("STRING_AGG({column}, ? {order_by})")
        }
    }
    fn json_array_agg_expr(column: &str) -> String {
        format!("JSON_AGG({column})")
    }
    fn json_object_agg_expr(key: &str, value: &str) -> String {
        format!("JSON_OBJECT_AGG({key}, {value})")
    }
}

impl Rollup for PostgreSqlDialect {
    fn with_rollup(b: &mut Builder<Self>) {
        b.core.group_by_modifier = Some("ROLLUP".into());
    }
}

impl Cube for PostgreSqlDialect {
    fn with_cube(b: &mut Builder<Self>) {
        b.core.group_by_modifier = Some("CUBE".into());
    }
}

impl TableSampling for PostgreSqlDialect {
    fn tablesample(b: &mut Builder<Self>, percent: f64, method: &str) -> Result<()> {
        let normalized = method.to_ascii_uppercase();
        if normalized != "BERNOULLI" && normalized != "SYSTEM" {
            return Err(Error::Validation(format!("Invalid TABLESAMPLE method: {method}")));
        }
        b.core.sample = Some((percent, normalized));
        Ok(())
    }
}

impl Sequences for PostgreSqlDialect {
    fn sequence_expression(_b: &Builder<Self>, next: bool, sequence: &str) -> Result<String> {
        Ok(format!("{}('{sequence}')", if next { "nextval" } else { "currval" }))
    }
}

impl Upsert for PostgreSqlDialect {
    fn upsert(b: &mut Builder<Self>) -> Result<Statement> {
        let result = b.compile_upsert(conflict_clause)?;
        b.append_returning(result)
    }
}

impl UpsertSelect for PostgreSqlDialect {
    fn upsert_select(b: &mut Builder<Self>) -> Result<Statement> {
        let result = b.compile_upsert_select(conflict_clause)?;
        b.append_returning(result)
    }
}

impl InsertOrIgnore for PostgreSqlDialect {
    fn insert_or_ignore(b: &mut Builder<Self>) -> Result<Statement> {
        b.core.bindings.clear();
        let (sql, bindings) = b.compile_insert_body()?;
        b.add_bindings(bindings);
        let result = b.statement(format!("{sql} ON CONFLICT DO NOTHING"));
        b.append_returning(result)
    }
}

impl Json for PostgreSqlDialect {
    fn set_json_append(b: &mut Builder<Self>, column: &str, values: &Array) -> Result<()> {
        let wrapped = b.resolve_and_wrap(column)?;
        b.core.json_sets.set(
            column,
            Condition::new(
                format!("COALESCE({wrapped}, '[]'::jsonb) || ?::jsonb"),
                vec![json_binding(&Value::Array(values.clone()))],
            ),
        );
        Ok(())
    }

    fn set_json_prepend(b: &mut Builder<Self>, column: &str, values: &Array) -> Result<()> {
        let wrapped = b.resolve_and_wrap(column)?;
        b.core.json_sets.set(
            column,
            Condition::new(
                format!("?::jsonb || COALESCE({wrapped}, '[]'::jsonb)"),
                vec![json_binding(&Value::Array(values.clone()))],
            ),
        );
        Ok(())
    }

    fn set_json_insert(b: &mut Builder<Self>, column: &str, index: i64, value: Value) -> Result<()> {
        let wrapped = b.resolve_and_wrap(column)?;
        b.core.json_sets.set(
            column,
            Condition::new(format!("jsonb_insert({wrapped}, '{{{index}}}', ?::jsonb)"), vec![json_binding(&value)]),
        );
        Ok(())
    }

    fn set_json_remove(b: &mut Builder<Self>, column: &str, value: Value) -> Result<()> {
        let wrapped = b.resolve_and_wrap(column)?;
        b.core.json_sets.set(column, Condition::new(format!("{wrapped} - ?"), vec![json_binding(&value)]));
        Ok(())
    }

    fn set_json_intersect(b: &mut Builder<Self>, column: &str, values: &Array) -> Result<()> {
        let wrapped = b.resolve_and_wrap(column)?;
        b.set_raw(
            column,
            format!("(SELECT jsonb_agg(elem) FROM jsonb_array_elements({wrapped}) AS elem WHERE elem <@ ?::jsonb)"),
            vec![json_binding(&Value::Array(values.clone()))],
        );
        Ok(())
    }

    fn set_json_diff(b: &mut Builder<Self>, column: &str, values: &Array) -> Result<()> {
        let wrapped = b.resolve_and_wrap(column)?;
        b.set_raw(
            column,
            format!(
                "(SELECT COALESCE(jsonb_agg(elem), '[]'::jsonb) FROM jsonb_array_elements({wrapped}) AS elem WHERE NOT elem <@ ?::jsonb)"
            ),
            vec![json_binding(&Value::Array(values.clone()))],
        );
        Ok(())
    }

    fn set_json_unique(b: &mut Builder<Self>, column: &str) -> Result<()> {
        let wrapped = b.resolve_and_wrap(column)?;
        b.set_raw(
            column,
            format!("(SELECT jsonb_agg(DISTINCT elem) FROM jsonb_array_elements({wrapped}) AS elem)"),
            Vec::<Value>::new(),
        );
        Ok(())
    }

    fn set_json_path(b: &mut Builder<Self>, column: &str, path: &str, value: Value) -> Result<()> {
        let Some(rest) = path.strip_prefix('$') else {
            return Err(Error::Validation(format!("JSON path must start with '$': {path}")));
        };
        let trimmed = rest.trim_start_matches('.');
        if trimmed.is_empty() {
            return Err(Error::Validation(format!("JSON path must reference at least one key: {path}")));
        }
        let segments: Vec<&str> = trimmed.split('.').collect();
        if segments.iter().any(|s| s.is_empty()) {
            return Err(Error::Validation(format!("JSON path contains an empty segment: {path}")));
        }
        let array = format!("{{{}}}", segments.iter().map(|s| quote_array_element(s)).collect::<Vec<_>>().join(","));
        let wrapped = b.resolve_and_wrap(column)?;
        b.core.json_sets.set(
            column,
            Condition::new(
                format!("jsonb_set({wrapped}, ?, to_jsonb(?::text)::jsonb, true)"),
                vec![value::string(array), value],
            ),
        );
        Ok(())
    }
}
