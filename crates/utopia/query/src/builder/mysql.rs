//! MySQL: [`MySql`], and the behaviour MariaDB shares ([`MySqlFamily`]).
//!
//! PHP: `Utopia\Query\Builder\MySQL`.

use php_std::json::Flags;

use crate::builder::core::{Builder, Dialect, ExplainOptions};
use crate::builder::feature::{
    BitwiseAggregates, ConditionalAggregates, CrossJoins, FullTextSearch, Hints, InsertOrIgnore, Json, LateralJoins,
    Locking, NegatedFullTextSearch, RawSql, Rollup, Spatial, StatisticalAggregates, StringAggregates, Transactions,
    Upsert, UpsertSelect,
};
use crate::builder::sql::{self, SqlDialect, distance_operator, wkt_of};
use crate::builder::types::{Condition, Statement};
use crate::error::{Error, Result};
use crate::method::Method;
use crate::query::Query;
use crate::value::{self, Array, Value};

/// A MySQL builder.
///
/// PHP: `Utopia\Query\Builder\MySQL`.
///
/// ```
/// use utopia_query::Query;
/// use utopia_query::builder::MySql;
///
/// let statement = MySql::new().from("t", "").filter([Query::equal("status", ["active", "pending"])]).build()?;
/// assert_eq!(statement.query, "SELECT * FROM `t` WHERE `status` IN (?, ?)");
/// # Ok::<(), utopia_query::Error>(())
/// ```
///
/// ```php
/// use Utopia\Query\Builder\MySQL;
/// use Utopia\Query\Query;
///
/// echo (new MySQL())->from('t')->filter([Query::equal('status', ['active', 'pending'])])->build()->query, "\n";
/// // SELECT * FROM `t` WHERE `status` IN (?, ?)
/// ```
pub type MySql = Builder<MySqlDialect>;

/// MySQL's own state: the join of a multi-table update or delete.
#[derive(Debug, Clone, Default, PartialEq, Eq)]
pub struct MySqlState {
    update_join: Option<(String, String, String, String)>,
    delete_join: Option<(String, String, String, String)>,
}

/// The MySQL dialect.
#[derive(Debug, Clone, Default)]
pub struct MySqlDialect {
    state: MySqlState,
}

/// The class whose `Spatial` trait MySQL and MariaDB use (PHP names its
/// closures and methods after it).
pub(crate) const MYSQL_CLASS: &str = "Utopia\\Query\\Builder\\MySQL";

/// What MySQL and MariaDB share: their state, and the geometry constructor.
pub trait MySqlFamily: SqlDialect {
    /// The MySQL state.
    fn mysql(b: &Builder<Self>) -> &MySqlState;
    /// The MySQL state, mutable.
    fn mysql_mut(b: &mut Builder<Self>) -> &mut MySqlState;
    /// `ST_GeomFromText(?, srid, 'axis-order=long-lat')` (MariaDB has no axis order).
    fn geom_from_text(srid: i64) -> String {
        format!("ST_GeomFromText(?, {srid}, 'axis-order=long-lat')")
    }
}

impl MySqlFamily for MySqlDialect {
    fn mysql(b: &Builder<Self>) -> &MySqlState {
        &b.dialect.state
    }

    fn mysql_mut(b: &mut Builder<Self>) -> &mut MySqlState {
        &mut b.dialect.state
    }
}

/// `/^[A-Za-z0-9_()=, \`.]+$/` (where `$` also matches before a final newline).
pub(crate) fn is_hint(hint: &str) -> bool {
    let bytes = hint.strip_suffix('\n').unwrap_or(hint).as_bytes();
    !bytes.is_empty()
        && bytes
            .iter()
            .all(|&b| b.is_ascii_alphanumeric() || matches!(b, b'_' | b'(' | b')' | b'=' | b',' | b' ' | b'`' | b'.'))
}

/// `json_encode($payload)` (default flags), `false` as `""`... PHP casts the
/// failure to a binding string only where it cannot fail; here failures are
/// the library's validation error.
pub(crate) fn encode_json_payload(payload: &Value) -> Result<String> {
    value::json_encode(payload, Flags::THROW_ON_ERROR)
        .map_err(|e| Error::Validation(format!("Invalid JSON payload: {}", e.message())))
}

/// `json_encode($values)` with default flags (`false` becomes `""` in a binding).
pub(crate) fn json_or_empty(values: &Array) -> Value {
    match value::json_encode(&Value::Array(values.clone()), Flags::NONE) {
        Ok(json) => value::string(json),
        Err(_) => Value::Bool(false),
    }
}

/// The `MATCH ... AGAINST` search shared by MySQL and MariaDB.
pub(crate) fn search_expression<D: Dialect>(
    b: &mut Builder<D>,
    attribute: &str,
    values: &Array,
    not: bool,
) -> Result<String> {
    let term = crate::builder::postgresql::search_term(values)?;
    let exact = term.ends_with('"') && term.starts_with('"');
    let mut replaced = String::with_capacity(term.len());
    for c in term.chars() {
        replaced.push(if matches!(c, '@' | '+' | '-' | '*' | ')' | '(' | '<' | '>' | '~' | '"') { ' ' } else { c });
    }
    // preg_replace('/\s+/', ' ') then trim(): runs of ASCII whitespace become one space.
    let mut collapsed = String::with_capacity(replaced.len());
    let mut in_space = false;
    for c in replaced.chars() {
        if matches!(c, ' ' | '\t' | '\n' | '\r' | '\x0B' | '\x0C') {
            if !in_space {
                collapsed.push(' ');
            }
            in_space = true;
        } else {
            collapsed.push(c);
            in_space = false;
        }
    }
    let sanitized = collapsed.trim_matches([' ', '\t', '\n', '\r', '\0', '\x0B']);
    if sanitized.is_empty() {
        return Ok(if not { "1 = 1" } else { "1 = 0" }.into());
    }
    let sanitized = if exact { format!("\"{sanitized}\"") } else { format!("{sanitized}*") };
    b.add_binding(value::string(sanitized), None);
    Ok(if not {
        format!("NOT (MATCH({attribute}) AGAINST(? IN BOOLEAN MODE))")
    } else {
        format!("MATCH({attribute}) AGAINST(? IN BOOLEAN MODE)")
    })
}

/// MySQL's `build()`: the group-by modifier and the hints.
pub(crate) fn build<D: Dialect>(b: &mut Builder<D>) -> Result<Statement> {
    let result = b.base_build()?;
    let mut query = result.query.clone();
    if let Some(modifier) = b.core.group_by_modifier.clone()
        && let Some(group_by) = query.find("GROUP BY ")
    {
        let after = group_by + 9;
        let end = ["HAVING ", "WINDOW ", "ORDER BY ", "LIMIT ", "FOR "]
            .iter()
            .filter_map(|keyword| query.get(after..).and_then(|rest| rest.find(keyword)).map(|p| p + after))
            .min();
        let insert_at = end.unwrap_or(query.len());
        let head = query[..insert_at].trim_end_matches([' ', '\t', '\n', '\r', '\0', '\x0B']);
        let mut rebuilt = format!("{head} {modifier}");
        if let Some(end) = end {
            rebuilt.push(' ');
            rebuilt.push_str(&query[end..]);
        }
        query = rebuilt;
    }
    if !b.core.hints.is_empty() {
        let hint = format!("/*+ {} */", b.core.hints.join(" "));
        if let Some(rest) = query.strip_prefix("SELECT") {
            let space = rest.len() - rest.trim_start_matches([' ', '\t', '\n', '\r', '\x0B', '\x0C']).len();
            let distinct = space > 0 && rest[space..].starts_with("DISTINCT");
            let keep = if distinct { space + 8 } else { 0 };
            query = format!("SELECT{} {hint}{}", &rest[..keep], &rest[keep..]);
        }
    }
    if query != result.query {
        return Ok(Statement::with_parts(query, result.bindings, result.read_only, b.core.executor.clone(), None));
    }
    Ok(result)
}

/// MySQL's `update()`: JSON assignments, then an update join or the base update.
pub(crate) fn update<D: MySqlFamily>(b: &mut Builder<D>) -> Result<Statement> {
    for (column, condition) in b.core.json_sets.0.clone() {
        b.core.raw_sets.set(column.clone(), condition.expression);
        b.core.raw_set_bindings.set(column, condition.bindings);
    }
    let result = if D::mysql(b).update_join.is_some() { update_join(b) } else { b.base_update() };
    b.core.json_sets.clear();
    result
}

fn update_join<D: MySqlFamily>(b: &mut Builder<D>) -> Result<Statement> {
    b.core.bindings.clear();
    b.validate_table()?;
    let (table, left, right, alias) = D::mysql(b).update_join.clone().unwrap_or_default();
    let mut join = b.quote(&table)?;
    if !alias.is_empty() {
        join.push_str(" AS ");
        join.push_str(&b.quote(&alias)?);
    }
    let mut sql = format!(
        "UPDATE {} JOIN {join} ON {} = {}",
        b.quote(&b.core.table)?,
        b.resolve_and_wrap(&left)?,
        b.resolve_and_wrap(&right)?
    );
    let assignments = b.compile_assignments()?;
    if assignments.is_empty() {
        return Err(Error::Validation("No assignments for UPDATE. Call set() or setRaw() before update().".into()));
    }
    sql.push_str(" SET ");
    sql.push_str(&assignments.join(", "));
    let mut parts = vec![sql];
    b.compile_where_clauses(&mut parts, None)?;
    Ok(b.statement(parts.join(" ")))
}

/// MySQL's `delete()`: a delete join, or the base delete.
pub(crate) fn delete<D: MySqlFamily>(b: &mut Builder<D>) -> Result<Statement> {
    let Some((alias, table, left, right)) = D::mysql(b).delete_join.clone() else {
        return b.base_delete();
    };
    b.core.bindings.clear();
    b.validate_table()?;
    let sql = format!(
        "DELETE {} FROM {} AS {} JOIN {} ON {} = {}",
        b.quote(&alias)?,
        b.quote(&b.core.table)?,
        b.quote(&alias)?,
        b.quote(&table)?,
        b.resolve_and_wrap(&left)?,
        b.resolve_and_wrap(&right)?
    );
    let mut parts = vec![sql];
    b.compile_where_clauses(&mut parts, None)?;
    Ok(b.statement(parts.join(" ")))
}

/// `INSERT IGNORE INTO ...`.
pub(crate) fn insert_or_ignore<D: Dialect>(b: &mut Builder<D>) -> Result<Statement> {
    b.core.bindings.clear();
    let (sql, bindings) = b.compile_insert_body()?;
    b.add_bindings(bindings);
    let sql = match sql.strip_prefix("INSERT INTO") {
        Some(rest) => format!("INSERT IGNORE INTO{rest}"),
        None => sql,
    };
    Ok(b.statement(sql))
}

/// `INSERT INTO table () VALUES ()`.
pub(crate) fn insert_default_values<D: Dialect>(b: &mut Builder<D>) -> Result<Statement> {
    b.core.bindings.clear();
    b.validate_table()?;
    let sql = format!("INSERT INTO {} () VALUES ()", b.quote(&b.core.table)?);
    Ok(b.statement(sql))
}

/// `EXPLAIN [ANALYZE] [FORMAT=...]`.
pub(crate) fn explain<D: Dialect>(b: &mut Builder<D>, analyze: bool, options: &ExplainOptions) -> Result<Statement> {
    let result = D::build(b)?;
    let mut prefix = "EXPLAIN".to_owned();
    if analyze {
        prefix.push_str(" ANALYZE");
    }
    if !options.format.is_empty() {
        prefix.push_str(" FORMAT=");
        prefix.push_str(&options.format.to_ascii_uppercase());
    }
    Ok(Statement::with_parts(
        format!("{prefix} {}", result.query),
        result.bindings,
        true,
        b.core.executor.clone(),
        None,
    ))
}

/// MySQL's `reset()` of its own state (hints, JSON sets, the modifier, joins).
pub(crate) fn reset<D: MySqlFamily>(b: &mut Builder<D>) {
    b.core.hints.clear();
    b.core.json_sets.clear();
    b.core.group_by_modifier = None;
    *D::mysql_mut(b) = MySqlState::default();
}

/// `ST_Distance(...) operator ?`.
pub(crate) fn spatial_distance<D: MySqlFamily>(
    b: &mut Builder<D>,
    method: Method,
    attribute: &str,
    values: &Array,
) -> Result<String> {
    let filter = sql::distance_filter(values, "Builder/MySQL.php", 432)?;
    let wkt = wkt_of(&filter.geometry, MYSQL_CLASS)?;
    let operator = distance_operator(method);
    b.add_binding(wkt, None);
    b.add_binding(Value::Float(filter.distance), None);
    if filter.meters {
        return Ok(format!(
            "ST_Distance(ST_SRID({attribute}, 4326), {}, 'metre') {operator} ?",
            D::geom_from_text(4326)
        ));
    }
    Ok(format!("ST_Distance(ST_SRID({attribute}, 0), {}) {operator} ?", D::geom_from_text(0)))
}

/// `function(attribute, ST_GeomFromText(?, 4326, ...))`, negated with `NOT`.
pub(crate) fn spatial_predicate<D: MySqlFamily>(
    b: &mut Builder<D>,
    function: &str,
    attribute: &str,
    values: &Array,
    not: bool,
) -> Result<String> {
    let wkt = sql::predicate_wkt(values, MYSQL_CLASS, "Builder/MySQL.php", 461)?;
    b.add_binding(value::string(wkt), None);
    let expression = format!("{function}({attribute}, {})", D::geom_from_text(4326));
    Ok(if not { format!("NOT {expression}") } else { expression })
}

/// `JSON_CONTAINS(attribute, ?)`.
pub(crate) fn json_contains<D: Dialect>(
    b: &mut Builder<D>,
    attribute: &str,
    values: &Array,
    not: bool,
) -> Result<String> {
    let payload = values.get(&php_std::ArrayKey::Int(0)).cloned().unwrap_or(Value::Null);
    b.add_binding(value::string(encode_json_payload(&payload)?), None);
    let expression = format!("JSON_CONTAINS({attribute}, ?)");
    Ok(if not { format!("NOT {expression}") } else { expression })
}

/// `JSON_OVERLAPS(attribute, ?)`.
pub(crate) fn json_overlaps<D: Dialect>(b: &mut Builder<D>, attribute: &str, values: &Array) -> Result<String> {
    let payload = values.get(&php_std::ArrayKey::Int(0)).cloned().unwrap_or(Value::Null);
    b.add_binding(value::string(encode_json_payload(&payload)?), None);
    Ok(format!("JSON_OVERLAPS({attribute}, ?)"))
}

/// `JSON_EXTRACT(attribute, '$.path') operator ?`.
pub(crate) fn json_path<D: Dialect>(b: &mut Builder<D>, attribute: &str, values: &Array) -> Result<String> {
    let (path, operator) = crate::builder::postgresql::json_path_parts(b, values)?;
    Ok(format!("JSON_EXTRACT({attribute}, '$.{path}') {operator} ?"))
}

impl Dialect for MySqlDialect {
    const CLASS: &'static str = "Utopia\\Query\\Builder\\MySQL";

    fn compile_random(_b: &Builder<Self>) -> String {
        "RAND()".into()
    }

    fn compile_regex(b: &mut Builder<Self>, attribute: &str, values: &Array, column: Option<&str>) -> Result<String> {
        b.add_binding(crate::builder::core::first(values), column);
        Ok(format!("{attribute} REGEXP ?"))
    }

    fn compile_filter(b: &mut Builder<Self>, query: &Query) -> Result<String> {
        sql::sql_compile_filter(b, query)
    }

    fn build(b: &mut Builder<Self>) -> Result<Statement> {
        build(b)
    }

    fn update(b: &mut Builder<Self>) -> Result<Statement> {
        update(b)
    }

    fn delete(b: &mut Builder<Self>) -> Result<Statement> {
        delete(b)
    }

    fn insert_default_values(b: &mut Builder<Self>) -> Result<Statement> {
        insert_default_values(b)
    }

    fn explain(b: &mut Builder<Self>, analyze: bool, options: &ExplainOptions) -> Result<Statement> {
        explain(b, analyze, options)
    }

    fn reset(b: &mut Builder<Self>) {
        reset(b)
    }

    fn ast_dialect() -> crate::builder::AstDialect {
        crate::builder::AstDialect::MySql
    }
}

impl SqlDialect for MySqlDialect {
    fn compile_search_expression(b: &mut Builder<Self>, attribute: &str, values: &Array, not: bool) -> Result<String> {
        search_expression(b, attribute, values, not)
    }

    fn compile_spatial_distance(
        b: &mut Builder<Self>,
        method: Method,
        attribute: &str,
        values: &Array,
    ) -> Result<String> {
        spatial_distance(b, method, attribute, values)
    }

    fn compile_spatial_predicate(
        b: &mut Builder<Self>,
        function: &str,
        attribute: &str,
        values: &Array,
        not: bool,
    ) -> Result<String> {
        spatial_predicate(b, function, attribute, values, not)
    }

    fn compile_spatial_covers_predicate(
        b: &mut Builder<Self>,
        attribute: &str,
        values: &Array,
        not: bool,
    ) -> Result<String> {
        spatial_predicate(b, "ST_Contains", attribute, values, not)
    }

    fn compile_json_contains_expression(
        b: &mut Builder<Self>,
        attribute: &str,
        values: &Array,
        not: bool,
    ) -> Result<String> {
        json_contains(b, attribute, values, not)
    }

    fn compile_json_overlaps_expression(b: &mut Builder<Self>, attribute: &str, values: &Array) -> Result<String> {
        json_overlaps(b, attribute, values)
    }

    fn compile_json_path_expression(b: &mut Builder<Self>, attribute: &str, values: &Array) -> Result<String> {
        json_path(b, attribute, values)
    }

    fn compile_conflict_header(_b: &mut Builder<Self>) -> Result<String> {
        Ok("ON DUPLICATE KEY UPDATE".into())
    }

    fn compile_conflict_assignment(wrapped: &str) -> String {
        format!("VALUES({wrapped})")
    }
}

/// The SQL of a conflict clause for a SQL dialect.
pub(crate) fn conflict_clause<D: SqlDialect>(b: &mut Builder<D>) -> Result<String> {
    let header = D::compile_conflict_header(b)?;
    b.compile_conflict_clause(&header, D::compile_conflict_assignment)
}

/// MySQL's JSON column updates, shared with MariaDB.
pub(crate) mod json_sets {
    use super::*;

    pub(crate) fn append<D: Dialect>(b: &mut Builder<D>, column: &str, values: &Array) -> Result<()> {
        let wrapped = b.resolve_and_wrap(column)?;
        b.core.json_sets.set(
            column,
            Condition::new(
                format!("JSON_MERGE_PRESERVE(IFNULL({wrapped}, JSON_ARRAY()), ?)"),
                vec![json_or_empty(values)],
            ),
        );
        Ok(())
    }

    pub(crate) fn prepend<D: Dialect>(b: &mut Builder<D>, column: &str, values: &Array) -> Result<()> {
        let wrapped = b.resolve_and_wrap(column)?;
        b.core.json_sets.set(
            column,
            Condition::new(
                format!("JSON_MERGE_PRESERVE(?, IFNULL({wrapped}, JSON_ARRAY()))"),
                vec![json_or_empty(values)],
            ),
        );
        Ok(())
    }

    pub(crate) fn insert<D: Dialect>(b: &mut Builder<D>, column: &str, index: i64, value: Value) -> Result<()> {
        let wrapped = b.resolve_and_wrap(column)?;
        b.core.json_sets.set(
            column,
            Condition::new(
                format!("JSON_ARRAY_INSERT({wrapped}, ?, ?)"),
                vec![value::string(format!("$[{index}]")), value],
            ),
        );
        Ok(())
    }

    pub(crate) fn remove<D: Dialect>(b: &mut Builder<D>, column: &str, value: Value) -> Result<()> {
        let wrapped = b.resolve_and_wrap(column)?;
        b.core.json_sets.set(
            column,
            Condition::new(
                format!("JSON_REMOVE({wrapped}, JSON_UNQUOTE(JSON_SEARCH({wrapped}, 'one', ?)))"),
                vec![value],
            ),
        );
        Ok(())
    }

    pub(crate) fn intersect<D: Dialect>(b: &mut Builder<D>, column: &str, values: &Array) -> Result<()> {
        let wrapped = b.resolve_and_wrap(column)?;
        let expression = format!(
            "(SELECT JSON_ARRAYAGG(val) FROM JSON_TABLE({wrapped}, '$[*]' COLUMNS(val JSON PATH '$')) AS jt WHERE JSON_CONTAINS(?, val))"
        );
        b.core.raw_sets.set(column, expression);
        b.core.raw_set_bindings.set(column, vec![json_or_empty(values)]);
        Ok(())
    }

    pub(crate) fn diff<D: Dialect>(b: &mut Builder<D>, column: &str, values: &Array) -> Result<()> {
        let wrapped = b.resolve_and_wrap(column)?;
        let expression = format!(
            "(SELECT JSON_ARRAYAGG(val) FROM JSON_TABLE({wrapped}, '$[*]' COLUMNS(val JSON PATH '$')) AS jt WHERE NOT JSON_CONTAINS(?, val))"
        );
        b.core.raw_sets.set(column, expression);
        b.core.raw_set_bindings.set(column, vec![json_or_empty(values)]);
        Ok(())
    }

    pub(crate) fn unique<D: Dialect>(b: &mut Builder<D>, column: &str) -> Result<()> {
        let wrapped = b.resolve_and_wrap(column)?;
        let expression = format!(
            "(SELECT JSON_ARRAYAGG(val) FROM (SELECT DISTINCT val FROM JSON_TABLE({wrapped}, '$[*]' COLUMNS(val JSON PATH '$')) AS jt) AS dt)"
        );
        b.core.raw_sets.set(column, expression);
        b.core.raw_set_bindings.set(column, Vec::new());
        Ok(())
    }

    pub(crate) fn path<D: Dialect>(b: &mut Builder<D>, column: &str, path: &str, value: Value) -> Result<()> {
        if !path.starts_with('$') {
            return Err(Error::Validation(format!("JSON path must start with '$': {path}")));
        }
        let wrapped = b.resolve_and_wrap(column)?;
        b.core
            .json_sets
            .set(column, Condition::new(format!("JSON_SET({wrapped}, ?, ?)"), vec![value::string(path), value]));
        Ok(())
    }
}

impl Locking for MySqlDialect {}
impl Transactions for MySqlDialect {}
impl StatisticalAggregates for MySqlDialect {}
impl BitwiseAggregates for MySqlDialect {}
impl RawSql for MySqlDialect {}
impl CrossJoins for MySqlDialect {}
impl LateralJoins for MySqlDialect {}
impl Spatial for MySqlDialect {}
impl FullTextSearch for MySqlDialect {}
impl NegatedFullTextSearch for MySqlDialect {}
impl ConditionalAggregates for MySqlDialect {}

impl Json for MySqlDialect {
    fn set_json_append(b: &mut Builder<Self>, column: &str, values: &Array) -> Result<()> {
        json_sets::append(b, column, values)
    }
    fn set_json_prepend(b: &mut Builder<Self>, column: &str, values: &Array) -> Result<()> {
        json_sets::prepend(b, column, values)
    }
    fn set_json_insert(b: &mut Builder<Self>, column: &str, index: i64, value: Value) -> Result<()> {
        json_sets::insert(b, column, index, value)
    }
    fn set_json_remove(b: &mut Builder<Self>, column: &str, value: Value) -> Result<()> {
        json_sets::remove(b, column, value)
    }
    fn set_json_intersect(b: &mut Builder<Self>, column: &str, values: &Array) -> Result<()> {
        json_sets::intersect(b, column, values)
    }
    fn set_json_diff(b: &mut Builder<Self>, column: &str, values: &Array) -> Result<()> {
        json_sets::diff(b, column, values)
    }
    fn set_json_unique(b: &mut Builder<Self>, column: &str) -> Result<()> {
        json_sets::unique(b, column)
    }
    fn set_json_path(b: &mut Builder<Self>, column: &str, path: &str, value: Value) -> Result<()> {
        json_sets::path(b, column, path, value)
    }
}

impl Hints for MySqlDialect {
    fn hint(b: &mut Builder<Self>, hint: &str) -> Result<()> {
        add_hint(b, hint)
    }
}

/// Adds a validated optimizer hint.
pub(crate) fn add_hint<D: Dialect>(b: &mut Builder<D>, hint: &str) -> Result<()> {
    if !is_hint(hint) {
        return Err(Error::Validation(format!("Invalid hint: {hint}")));
    }
    b.core.hints.push(hint.to_owned());
    Ok(())
}

impl StringAggregates for MySqlDialect {
    fn group_concat_expr(column: &str, order_by: &str) -> String {
        if order_by.is_empty() {
            format!("GROUP_CONCAT({column} SEPARATOR ?)")
        } else {
            format!("GROUP_CONCAT({column} {order_by} SEPARATOR ?)")
        }
    }
    fn json_array_agg_expr(column: &str) -> String {
        format!("JSON_ARRAYAGG({column})")
    }
    fn json_object_agg_expr(key: &str, value: &str) -> String {
        format!("JSON_OBJECTAGG({key}, {value})")
    }
}

impl Rollup for MySqlDialect {
    fn with_rollup(b: &mut Builder<Self>) {
        b.core.group_by_modifier = Some("WITH ROLLUP".into());
    }
}

impl Upsert for MySqlDialect {
    fn upsert(b: &mut Builder<Self>) -> Result<Statement> {
        b.compile_upsert(conflict_clause)
    }
}

impl UpsertSelect for MySqlDialect {
    fn upsert_select(b: &mut Builder<Self>) -> Result<Statement> {
        b.compile_upsert_select(conflict_clause)
    }
}

impl InsertOrIgnore for MySqlDialect {
    fn insert_or_ignore(b: &mut Builder<Self>) -> Result<Statement> {
        insert_or_ignore(b)
    }
}

impl<D: MySqlFamily> Builder<D> {
    /// `UPDATE table JOIN join [AS alias] ON left = right SET ...`.
    ///
    /// PHP: `updateJoin()`.
    pub fn update_join(
        &mut self,
        table: impl Into<String>,
        left: impl Into<String>,
        right: impl Into<String>,
        alias: impl Into<String>,
    ) -> &mut Self {
        D::mysql_mut(self).update_join = Some((table.into(), left.into(), right.into(), alias.into()));
        self
    }

    /// `DELETE alias FROM table AS alias JOIN join ON left = right`.
    ///
    /// PHP: `deleteJoin()`.
    pub fn delete_join(
        &mut self,
        alias: impl Into<String>,
        table: impl Into<String>,
        left: impl Into<String>,
        right: impl Into<String>,
    ) -> &mut Self {
        D::mysql_mut(self).delete_join = Some((alias.into(), table.into(), left.into(), right.into()));
        self
    }

    /// The `MAX_EXECUTION_TIME(ms)` hint.
    ///
    /// PHP: `maxExecutionTime()`.
    pub fn max_execution_time(&mut self, ms: i64) -> Result<&mut Self> {
        add_hint(self, &format!("MAX_EXECUTION_TIME({ms})"))?;
        Ok(self)
    }
}
