//! ClickHouse: [`ClickHouse`], its bulk-insert [`Format`]s and
//! [`FormattedInsertStatement`], and the ASOF join [`AsofOperator`].
//!
//! ClickHouse extends the base builder directly (not the SQL dialects): it
//! adds `PREWHERE`, `FINAL`, `SAMPLE`, `ARRAY JOIN`, `ASOF JOIN`,
//! `LIMIT ... BY`, `WITH FILL`, `SETTINGS`, the `-If` conditional
//! aggregates, the approximate aggregates (`quantile`, `uniq`, `topK`, ...),
//! mutations (`ALTER TABLE ... UPDATE` / `DELETE`), `FORMAT` inserts and
//! named, typed placeholders (`{param0:Int64}`).
//!
//! | PHP | Rust |
//! |---|---|
//! | `Builder\ClickHouse` | [`ClickHouse`] ([`ClickHouseDialect`]) |
//! | `ClickHouse::DELETE_MODE_LIGHTWEIGHT`, `DELETE_MODE_MUTATION`, `deleteMode()` | [`DeleteMode`], [`Builder::delete_mode`] |
//! | `Builder\ClickHouse\AsofOperator` | [`AsofOperator`] |
//! | `Builder\ClickHouse\Format`, `Format::serialize()` | [`Format`], [`Format::serialize`] |
//! | `Builder\ClickHouse\FormattedInsertStatement` | [`FormattedInsertStatement`] |
//! | `bulkInsert()`, `insertFormat()` + `insert()` | [`Builder::bulk_insert`], [`Builder::insert_format`] + [`Builder::formatted_insert`] |
//! | `Feature\ClickHouse\LimitBy`, `ArrayJoins`, `AsofJoins`, `WithFill`, `ApproximateAggregates` | inherent methods of [`ClickHouse`] |
//! | `prewhere()`, `final()`, `sample()`, `settings()` | [`Builder::prewhere`], [`Builder::final_`], [`Builder::sample`], [`Builder::settings`] |
//! | `useNamedBindings()`, `withParamType()`, `withParamTypes()` | [`Builder::use_named_bindings`], [`Builder::with_param_type`], [`Builder::with_param_types`] |

use std::collections::HashMap;
use std::ops::Deref;

use php_std::ArrayKey;
use php_std::json::Flags;

use crate::builder::core::{Builder, Dialect, escape_like_value, first, key_string};
use crate::builder::feature::{
    BitwiseAggregates, ConditionalAggregates, CrossJoins, Cube, FullOuterJoins, Hints, RawSql, Rollup,
    StringAggregates, TableSampling, Totals, is_plain_identifier,
};
use crate::builder::types::{Condition, Executor, Statement};
use crate::error::{Error, Result};
use crate::hook::Placement;
use crate::method::backed_enum;
use crate::query::{ParsedQuery, Query};
use crate::value::{self, Array, Value};

/// A ClickHouse builder.
///
/// PHP: `Utopia\Query\Builder\ClickHouse`.
///
/// ```
/// use utopia_query::Query;
/// use utopia_query::builder::ClickHouse;
///
/// let statement = ClickHouse::new()
///     .from("events", "")
///     .final_()
///     .sample(0.1)?
///     .prewhere([Query::equal("type", ["click"])])
///     .filter([Query::greater_than("count", 5)])
///     .build()?;
/// assert_eq!(
///     statement.query,
///     "SELECT * FROM `events` FINAL SAMPLE 0.1 PREWHERE `type` IN (?) WHERE `count` > ?"
/// );
/// # Ok::<(), utopia_query::Error>(())
/// ```
///
/// ```php
/// use Utopia\Query\Builder\ClickHouse;
/// use Utopia\Query\Query;
///
/// echo (new ClickHouse())
///     ->from('events')
///     ->final()
///     ->sample(0.1)
///     ->prewhere([Query::equal('type', ['click'])])
///     ->filter([Query::greaterThan('count', 5)])
///     ->build()->query, "\n";
/// // SELECT * FROM `events` FINAL SAMPLE 0.1 PREWHERE `type` IN (?) WHERE `count` > ?
/// ```
pub type ClickHouse = Builder<ClickHouseDialect>;

/// An `ARRAY JOIN` / `LEFT ARRAY JOIN` of a column.
#[derive(Debug, Clone, PartialEq, Eq)]
struct ArrayJoin {
    left: bool,
    column: String,
    alias: String,
}

/// The ClickHouse dialect and its own state.
#[derive(Debug, Clone, Default)]
pub struct ClickHouseDialect {
    prewhere: Vec<Query>,
    final_: bool,
    sample_fraction: Option<f64>,
    limit_by: Option<(i64, Vec<String>)>,
    array_joins: Vec<ArrayJoin>,
    raw_joins: Vec<String>,
    insert_format: Option<String>,
    insert_format_columns: Vec<String>,
    param_types: HashMap<String, String>,
    named_bindings: bool,
    delete_mode: DeleteMode,
}

/// Which `DELETE` form [`Builder::delete`] emits.
///
/// PHP: the `ClickHouse::DELETE_MODE_*` constants.
#[derive(Debug, Clone, Copy, Default, PartialEq, Eq, Hash)]
pub enum DeleteMode {
    /// `DELETE FROM t WHERE ...`: rows are masked as deleted (the default).
    ///
    /// PHP: `ClickHouse::DELETE_MODE_LIGHTWEIGHT` (`'lightweight'`).
    #[default]
    Lightweight,
    /// `ALTER TABLE t DELETE WHERE ...`: parts are rewritten on disk.
    ///
    /// PHP: `ClickHouse::DELETE_MODE_MUTATION` (`'mutation'`).
    Mutation,
}

impl DeleteMode {
    /// The mode's name: `lightweight` or `mutation`.
    pub const fn as_str(self) -> &'static str {
        match self {
            DeleteMode::Lightweight => "lightweight",
            DeleteMode::Mutation => "mutation",
        }
    }

    /// The mode named `mode`.
    ///
    /// PHP: the validation of `deleteMode()`, which throws `ValidationException`.
    pub fn parse(mode: &str) -> Result<DeleteMode> {
        match mode {
            "lightweight" => Ok(DeleteMode::Lightweight),
            "mutation" => Ok(DeleteMode::Mutation),
            other => Err(Error::Validation(format!(
                "Invalid ClickHouse delete mode: {other}. Allowed: lightweight, mutation"
            ))),
        }
    }
}

backed_enum! {
    /// The inequality of an `ASOF JOIN`.
    ///
    /// PHP: `Utopia\Query\Builder\ClickHouse\AsofOperator`.
    pub enum AsofOperator: "Utopia\\Query\\Builder\\ClickHouse\\AsofOperator" {
        /// `<`.
        LessThan = "<",
        /// `<=`.
        LessThanEqual = "<=",
        /// `>`.
        GreaterThan = ">",
        /// `>=`.
        GreaterThanEqual = ">=",
    }
}

backed_enum! {
    /// A ClickHouse input format for bulk inserts.
    ///
    /// PHP: `Utopia\Query\Builder\ClickHouse\Format`.
    pub enum Format: "Utopia\\Query\\Builder\\ClickHouse\\Format" {
        /// One JSON object per line.
        JSONEachRow = "JSONEachRow",
        /// Tab-separated values, one row per line, `\N` for null.
        TabSeparated = "TabSeparated",
    }
}

impl Format {
    /// The rows in this format, one per line. With `columns`, each row has
    /// exactly those columns in that order (missing ones are null).
    ///
    /// PHP: `Format::serialize()`.
    ///
    /// ```
    /// use utopia_query::builder::Format;
    /// use utopia_query::{Array, Value};
    ///
    /// let mut row = Array::default();
    /// row.set("id".into(), Value::Int(1));
    /// row.set("note".into(), Value::Null);
    /// assert_eq!(Format::TabSeparated.serialize(&[row.clone()], None)?, "1\t\\N");
    /// assert_eq!(Format::JSONEachRow.serialize(&[row], None)?, r#"{"id":1,"note":null}"#);
    /// # Ok::<(), utopia_query::Error>(())
    /// ```
    ///
    /// ```php
    /// use Utopia\Query\Builder\ClickHouse\Format;
    ///
    /// echo json_encode(Format::TabSeparated->serialize([['id' => 1, 'note' => null]])), "\n";
    /// // "1\t\\N"
    /// echo Format::JSONEachRow->serialize([['id' => 1, 'note' => null]]), "\n";
    /// // {"id":1,"note":null}
    /// ```
    pub fn serialize(self, rows: &[Array], columns: Option<&[String]>) -> Result<String> {
        let mut lines = Vec::with_capacity(rows.len());
        for row in rows {
            lines.push(match self {
                Format::JSONEachRow => json_each_row_line(row, columns)?,
                Format::TabSeparated => tab_separated_line(row, columns)?,
            });
        }
        Ok(lines.join("\n"))
    }
}

/// `$row[$column] ?? null`.
fn column_value(row: &Array, column: &str) -> Value {
    row.get(&ArrayKey::from(column)).cloned().unwrap_or(Value::Null)
}

fn json_each_row_line(row: &Array, columns: Option<&[String]>) -> Result<String> {
    let object = match columns {
        None => row.clone(),
        Some(columns) => {
            let mut ordered = Array::with_capacity(columns.len());
            for column in columns {
                ordered.set(ArrayKey::from(column.as_str()), column_value(row, column));
            }
            ordered
        }
    };
    let flags = Flags::THROW_ON_ERROR | Flags::UNESCAPED_SLASHES | Flags::UNESCAPED_UNICODE;
    value::json_encode(&Value::Object(object), flags)
        .map_err(|e| Error::Json { message: e.message().to_owned(), code: e.code().code() })
}

fn tab_separated_line(row: &Array, columns: Option<&[String]>) -> Result<String> {
    let mut values = Vec::new();
    match columns {
        None => {
            for v in row.values() {
                values.push(escape_tab_separated(v)?);
            }
        }
        Some(columns) => {
            for column in columns {
                values.push(escape_tab_separated(&column_value(row, column))?);
            }
        }
    }
    Ok(values.join("\t"))
}

/// One TabSeparated field: `\N` for null, `1`/`0` for booleans, numbers as
/// PHP prints them, strings with `\`, tab, newline and carriage return escaped.
fn escape_tab_separated(value: &Value) -> Result<String> {
    let text = match value {
        Value::Null => return Ok("\\N".into()),
        Value::Bool(b) => return Ok(if *b { "1" } else { "0" }.into()),
        Value::Int(_) | Value::Float(_) => return Ok(value::to_php_string(value)),
        Value::Str(s) => String::from_utf8_lossy(s.as_bytes()).into_owned(),
        other => {
            return Err(Error::Validation(format!(
                "TabSeparated values must be scalar, null, or stringable. Received: {}",
                other.debug_type()
            )));
        }
    };
    let mut out = String::with_capacity(text.len());
    for c in text.chars() {
        match c {
            '\\' => out.push_str("\\\\"),
            '\t' => out.push_str("\\t"),
            '\n' => out.push_str("\\n"),
            '\r' => out.push_str("\\r"),
            c => out.push(c),
        }
    }
    Ok(out)
}

/// An `INSERT INTO table [(columns)] FORMAT name` envelope (no bindings),
/// with the format, the columns and, from [`Builder::bulk_insert`], the
/// serialized rows to send with it.
///
/// It is a [`Statement`] (it derefs to one) with three more fields.
///
/// PHP: `Utopia\Query\Builder\ClickHouse\FormattedInsertStatement` (extends `Statement`).
#[derive(Debug, Clone, PartialEq)]
pub struct FormattedInsertStatement {
    /// The envelope statement.
    pub statement: Statement,
    /// The columns, in order.
    pub columns: Vec<String>,
    /// The format name (`JSONEachRow`).
    pub format: String,
    /// The serialized rows; `None` when built by `insert()` after `insert_format()`.
    pub body: Option<String>,
}

impl Deref for FormattedInsertStatement {
    type Target = Statement;

    /// The envelope statement.
    fn deref(&self) -> &Statement {
        &self.statement
    }
}

impl FormattedInsertStatement {
    /// The same statement run by `executor`.
    ///
    /// PHP: `withExecutor()`.
    pub fn with_executor(&self, executor: Executor) -> FormattedInsertStatement {
        FormattedInsertStatement { statement: self.statement.with_executor(executor), ..self.clone() }
    }
}

/// `$` without `/D` also matches before a final newline: the text the
/// pattern's body must cover.
fn before_final_newline(text: &str) -> &str {
    text.strip_suffix('\n').unwrap_or(text)
}

/// `/^[A-Za-z0-9_=., ]+$/`.
fn is_hint(hint: &str) -> bool {
    let bytes = before_final_newline(hint).as_bytes();
    !bytes.is_empty()
        && bytes.iter().all(|&b| b.is_ascii_alphanumeric() || matches!(b, b'_' | b'=' | b'.' | b',' | b' '))
}

/// `/^[a-zA-Z0-9_.]+$/`.
fn is_setting_value(text: &str) -> bool {
    let bytes = before_final_newline(text).as_bytes();
    !bytes.is_empty() && bytes.iter().all(|&b| b.is_ascii_alphanumeric() || matches!(b, b'_' | b'.'))
}

/// `/^[A-Za-z][A-Za-z0-9_]*$/`.
fn is_format_name(text: &str) -> bool {
    let bytes = before_final_newline(text).as_bytes();
    bytes.first().is_some_and(u8::is_ascii_alphabetic) && bytes.iter().all(|&b| b.is_ascii_alphanumeric() || b == b'_')
}

/// `/^[A-Za-z][A-Za-z0-9_]*(?:\((?:[^()]*|\([^()]*\))*\))?$/`: a type name
/// with an optional argument list nesting parentheses one level deep.
fn is_clickhouse_type(text: &str) -> bool {
    fn full(bytes: &[u8]) -> bool {
        if !bytes.first().is_some_and(u8::is_ascii_alphabetic) {
            return false;
        }
        let mut i = 1;
        while i < bytes.len() && (bytes[i].is_ascii_alphanumeric() || bytes[i] == b'_') {
            i += 1;
        }
        if i == bytes.len() {
            return true;
        }
        if bytes[i] != b'(' {
            return false;
        }
        i += 1;
        while i < bytes.len() {
            match bytes[i] {
                b')' => return i == bytes.len() - 1,
                b'(' => {
                    let mut j = i + 1;
                    while j < bytes.len() && bytes[j] != b'(' && bytes[j] != b')' {
                        j += 1;
                    }
                    if j == bytes.len() || bytes[j] == b'(' {
                        return false;
                    }
                    i = j + 1;
                }
                _ => i += 1,
            }
        }
        false
    }
    let bytes = text.as_bytes();
    full(bytes) || bytes.strip_suffix(b"\n").is_some_and(full)
}

/// The ClickHouse type of a binding without a registered type.
fn infer_type(value: Option<&Value>) -> &'static str {
    match value {
        Some(Value::Int(_)) => "Int64",
        Some(Value::Float(_)) => "Float64",
        Some(Value::Bool(_)) => "UInt8",
        None | Some(Value::Null) => "Nullable(String)",
        Some(_) => "String",
    }
}

/// Rewrites the `?` placeholders (not `??`, `?|`, `?&`) to `{paramN:Type}`
/// and attaches the named bindings, when named bindings are on.
fn apply_named_typed_bindings(b: &ClickHouse, statement: Statement) -> Statement {
    if !b.dialect.named_bindings || statement.bindings.is_empty() {
        return statement;
    }
    let sql = statement.query.as_bytes();
    let mut rewritten = Vec::with_capacity(sql.len() + statement.bindings.len() * 16);
    let mut named = Array::with_capacity(statement.bindings.len());
    let mut index = 0usize;
    for (i, &byte) in sql.iter().enumerate() {
        let placeholder =
            byte == b'?' && (i == 0 || sql[i - 1] != b'?') && !matches!(sql.get(i + 1), Some(b'|' | b'&' | b'?'));
        if !placeholder {
            rewritten.push(byte);
            continue;
        }
        let binding = b.core.bindings.get(index);
        let type_name = match binding.and_then(|binding| binding.column.as_ref()) {
            Some(column) if b.dialect.param_types.contains_key(column) => b.dialect.param_types[column].as_str(),
            _ => infer_type(binding.map(|binding| &binding.value)),
        };
        let name = format!("param{index}");
        named.set(ArrayKey::from(name.as_str()), statement.bindings.get(index).cloned().unwrap_or(Value::Null));
        rewritten.extend_from_slice(format!("{{{name}:{type_name}}}").as_bytes());
        index += 1;
    }
    Statement::with_parts(
        String::from_utf8_lossy(&rewritten).into_owned(),
        statement.bindings,
        statement.read_only,
        b.core.executor.clone(),
        Some(named),
    )
}

/// `function(column) [AS alias]` in the select list.
fn select_function(b: &mut ClickHouse, expression: String, alias: &str) -> Result<()> {
    let mut expression = expression;
    if !alias.is_empty() {
        expression.push_str(" AS ");
        expression.push_str(&b.quote(alias)?);
    }
    b.select_raw(expression, Array::default());
    Ok(())
}

impl Dialect for ClickHouseDialect {
    const CLASS: &'static str = "Utopia\\Query\\Builder\\ClickHouse";

    fn compile_random(_b: &Builder<Self>) -> String {
        "rand()".into()
    }

    fn compile_regex(b: &mut Builder<Self>, attribute: &str, values: &Array, column: Option<&str>) -> Result<String> {
        b.add_binding(first(values), column);
        Ok(format!("match({attribute}, ?)"))
    }

    fn compile_like(
        b: &mut Builder<Self>,
        attribute: &str,
        values: &Array,
        prefix: &str,
        suffix: &str,
        not: bool,
        column: Option<&str>,
    ) -> Result<String> {
        let raw = first(values);
        let function = match (prefix, suffix) {
            ("", "%") => Some("startsWith"),
            ("%", "") => Some("endsWith"),
            _ => None,
        };
        if let Some(function) = function {
            b.add_binding(raw, column);
            let not = if not { "NOT " } else { "" };
            return Ok(format!("{not}{function}({attribute}, ?)"));
        }
        let escaped = escape_like_value(&raw);
        b.add_binding(value::string(format!("{prefix}{escaped}{suffix}")), column);
        Ok(format!("{attribute} {} ?", if not { "NOT LIKE" } else { "LIKE" }))
    }

    fn compile_contains(
        b: &mut Builder<Self>,
        attribute: &str,
        values: &Array,
        column: Option<&str>,
    ) -> Result<String> {
        if values.len() == 1 {
            b.add_binding(first(values), column);
            return Ok(format!("position({attribute}, ?) > 0"));
        }
        let mut parts = Vec::with_capacity(values.len());
        for v in values.values() {
            b.add_binding(v.clone(), column);
            parts.push(format!("position({attribute}, ?) > 0"));
        }
        Ok(format!("({})", parts.join(" OR ")))
    }

    fn compile_contains_all(
        b: &mut Builder<Self>,
        attribute: &str,
        values: &Array,
        column: Option<&str>,
    ) -> Result<String> {
        let mut parts = Vec::with_capacity(values.len());
        for v in values.values() {
            b.add_binding(v.clone(), column);
            parts.push(format!("position({attribute}, ?) > 0"));
        }
        Ok(format!("({})", parts.join(" AND ")))
    }

    fn compile_not_contains(
        b: &mut Builder<Self>,
        attribute: &str,
        values: &Array,
        column: Option<&str>,
    ) -> Result<String> {
        if values.len() == 1 {
            b.add_binding(first(values), column);
            return Ok(format!("position({attribute}, ?) = 0"));
        }
        let mut parts = Vec::with_capacity(values.len());
        for v in values.values() {
            b.add_binding(v.clone(), column);
            parts.push(format!("position({attribute}, ?) = 0"));
        }
        Ok(format!("({})", parts.join(" AND ")))
    }

    fn build_table_clause(b: &mut Builder<Self>) -> Result<String> {
        if let Some(sub) = b.core.from_subquery.clone() {
            let mut subquery = sub.subquery;
            let result = subquery.build()?;
            b.add_bindings(result.bindings);
            return Ok(format!("FROM ({}) AS {}", result.query, b.quote(&sub.alias)?));
        }
        let mut sql = format!("FROM {}", b.quote(&b.core.table)?);
        if b.dialect.final_ {
            sql.push_str(" FINAL");
        }
        if let Some(fraction) = b.dialect.sample_fraction {
            // `%.10g` of a float cannot fail.
            let formatted =
                php_std::format::sprintf(b"%.10g", &[php_std::format::Arg::Float(fraction)]).unwrap_or_default();
            sql.push_str(" SAMPLE ");
            sql.push_str(&String::from_utf8_lossy(&formatted));
        }
        if !b.core.alias.is_empty() {
            sql.push_str(" AS ");
            sql.push_str(&b.quote(&b.core.alias)?);
        }
        Ok(sql)
    }

    fn build_after_joins_clause(b: &mut Builder<Self>, _grouped: &ParsedQuery) -> Result<String> {
        let mut parts = Vec::new();
        if !b.dialect.array_joins.is_empty() {
            let mut joins = Vec::with_capacity(b.dialect.array_joins.len());
            for join in b.dialect.array_joins.clone() {
                let keyword = if join.left { "LEFT ARRAY JOIN" } else { "ARRAY JOIN" };
                let mut clause = format!("{keyword} {}", b.resolve_and_wrap(&join.column)?);
                if !join.alias.is_empty() {
                    clause.push_str(" AS ");
                    clause.push_str(&b.quote(&join.alias)?);
                }
                joins.push(clause);
            }
            parts.push(joins.join(" "));
        }
        if !b.dialect.raw_joins.is_empty() {
            parts.push(b.dialect.raw_joins.join(" "));
        }
        if !b.dialect.prewhere.is_empty() {
            let mut clauses = Vec::with_capacity(b.dialect.prewhere.len());
            for query in b.dialect.prewhere.clone() {
                clauses.push(Self::compile_filter(b, &query)?);
            }
            parts.push(format!("PREWHERE {}", clauses.join(" AND ")));
        }
        Ok(parts.join(" "))
    }

    fn build_after_group_by_clause(b: &mut Builder<Self>) -> Result<String> {
        Ok(b.core.group_by_modifier.clone().unwrap_or_default())
    }

    fn build_after_order_by_clause(b: &mut Builder<Self>) -> Result<String> {
        let Some((count, columns)) = b.dialect.limit_by.clone() else {
            return Ok(String::new());
        };
        let columns = columns.iter().map(|c| b.resolve_and_wrap(c)).collect::<Result<Vec<_>>>()?;
        b.add_binding(Value::Int(count), None);
        Ok(format!("LIMIT ? BY {}", columns.join(", ")))
    }

    fn build_settings_clause(b: &mut Builder<Self>) -> Result<String> {
        if b.core.hints.is_empty() {
            return Ok(String::new());
        }
        Ok(format!("SETTINGS {}", b.core.hints.join(", ")))
    }

    fn compile_group_by_time_bucket(b: &mut Builder<Self>, attribute: &str, interval: &str) -> Result<String> {
        let function = match interval {
            "1m" => "toStartOfMinute",
            "5m" => "toStartOfFiveMinutes",
            "15m" => "toStartOfFifteenMinutes",
            "1h" => "toStartOfHour",
            "1d" => "toStartOfDay",
            "1w" => "toStartOfWeek",
            "1M" => "toStartOfMonth",
            _ => {
                return Err(Error::Validation(format!(
                    "Invalid groupByTimeBucket interval for ClickHouse: {interval}"
                )));
            }
        };
        Ok(format!("{function}({})", b.resolve_and_wrap(attribute)?))
    }

    fn resolve_join_filter_placement(_b: &Builder<Self>, _requested: Placement, _is_cross_join: bool) -> Placement {
        Placement::Where
    }

    fn build(b: &mut Builder<Self>) -> Result<Statement> {
        let result = b.base_build()?;
        Ok(apply_named_typed_bindings(b, result))
    }

    fn insert(b: &mut Builder<Self>) -> Result<Statement> {
        match b.formatted_insert()? {
            Some(formatted) => Ok(formatted.statement),
            None => {
                let result = b.base_insert()?;
                Ok(apply_named_typed_bindings(b, result))
            }
        }
    }

    fn update(b: &mut Builder<Self>) -> Result<Statement> {
        b.core.bindings.clear();
        b.validate_table()?;
        let assignments = b.compile_assignments()?;
        if assignments.is_empty() {
            return Err(Error::Validation("No assignments for UPDATE. Call set() or setRaw() before update().".into()));
        }
        let mut parts = Vec::new();
        b.compile_where_clauses(&mut parts, None)?;
        if parts.is_empty() {
            return Err(Error::Validation("ClickHouse UPDATE requires a WHERE clause.".into()));
        }
        let sql =
            format!("ALTER TABLE {} UPDATE {} {}", b.quote(&b.core.table)?, assignments.join(", "), parts.join(" "));
        let statement = b.statement(sql);
        Ok(apply_named_typed_bindings(b, statement))
    }

    fn delete(b: &mut Builder<Self>) -> Result<Statement> {
        b.core.bindings.clear();
        b.validate_table()?;
        let mut parts = Vec::new();
        b.compile_where_clauses(&mut parts, None)?;
        if parts.is_empty() {
            return Err(Error::Validation("ClickHouse DELETE requires a WHERE clause.".into()));
        }
        let table = b.quote(&b.core.table)?;
        let mut sql = match b.dialect.delete_mode {
            DeleteMode::Lightweight => format!("DELETE FROM {table} {}", parts.join(" ")),
            DeleteMode::Mutation => format!("ALTER TABLE {table} DELETE {}", parts.join(" ")),
        };
        let settings = Self::build_settings_clause(b)?;
        if !settings.is_empty() {
            sql.push(' ');
            sql.push_str(&settings);
        }
        let statement = b.statement(sql);
        Ok(apply_named_typed_bindings(b, statement))
    }

    fn reset(b: &mut Builder<Self>) {
        b.dialect = ClickHouseDialect::default();
        b.core.hints.clear();
        b.core.group_by_modifier = None;
    }

    fn ast_dialect() -> crate::builder::AstDialect {
        crate::builder::AstDialect::ClickHouse
    }
}

impl Hints for ClickHouseDialect {
    fn hint(b: &mut Builder<Self>, hint: &str) -> Result<()> {
        if !is_hint(hint) {
            return Err(Error::Validation(format!("Invalid hint: {hint}")));
        }
        b.core.hints.push(hint.to_owned());
        Ok(())
    }
}

impl ConditionalAggregates for ClickHouseDialect {
    /// ClickHouse's `-If` combinator: `countIf(condition)`, `sumIf(column, condition)`.
    fn aggregate_filter(
        b: &mut Builder<Self>,
        aggregate: &str,
        column: Option<&str>,
        condition: &str,
        alias: &str,
    ) -> Result<String> {
        let arguments = match column {
            None => condition.to_owned(),
            Some(column) => format!("{}, {condition}", b.resolve_and_wrap(column)?),
        };
        let mut expression = format!("{}If({arguments})", aggregate.to_ascii_lowercase());
        if !alias.is_empty() {
            expression.push_str(" AS ");
            expression.push_str(&b.quote(alias)?);
        }
        Ok(expression)
    }
}

impl TableSampling for ClickHouseDialect {
    /// `SAMPLE percent / 100` (the method is ignored).
    fn tablesample(b: &mut Builder<Self>, percent: f64, _method: &str) -> Result<()> {
        b.sample(percent / 100.0)?;
        Ok(())
    }
}

impl StringAggregates for ClickHouseDialect {
    fn group_concat_expr(column: &str, _order_by: &str) -> String {
        format!("arrayStringConcat(groupArray({column}), ?)")
    }
    fn json_array_agg_expr(column: &str) -> String {
        format!("toJSONString(groupArray({column}))")
    }
    fn json_object_agg_expr(key: &str, value: &str) -> String {
        format!("toJSONString(CAST((groupArray({key}), groupArray({value})) AS Map(String, String)))")
    }
}

impl Totals for ClickHouseDialect {
    fn with_totals(b: &mut Builder<Self>) {
        b.core.group_by_modifier = Some("WITH TOTALS".into());
    }
}

impl Rollup for ClickHouseDialect {
    fn with_rollup(b: &mut Builder<Self>) {
        b.core.group_by_modifier = Some("WITH ROLLUP".into());
    }
}

impl Cube for ClickHouseDialect {
    fn with_cube(b: &mut Builder<Self>) {
        b.core.group_by_modifier = Some("WITH CUBE".into());
    }
}

impl FullOuterJoins for ClickHouseDialect {}
impl BitwiseAggregates for ClickHouseDialect {}
impl RawSql for ClickHouseDialect {}
impl CrossJoins for ClickHouseDialect {}

impl Builder<ClickHouseDialect> {
    /// `PREWHERE` filters, evaluated before the other columns are read.
    ///
    /// PHP: `prewhere()`.
    pub fn prewhere(&mut self, queries: impl IntoIterator<Item = Query>) -> &mut Self {
        self.dialect.prewhere.extend(queries);
        self
    }

    /// `FINAL` after the table (merges the data parts first).
    ///
    /// PHP: `final()`.
    pub fn final_(&mut self) -> &mut Self {
        self.dialect.final_ = true;
        self
    }

    /// `SAMPLE fraction` after the table; the fraction must be in `(0, 1)`.
    ///
    /// PHP: `sample()`.
    pub fn sample(&mut self, fraction: f64) -> Result<&mut Self> {
        if fraction <= 0.0 || fraction >= 1.0 {
            return Err(Error::Validation("Sample fraction must be between 0 and 1 exclusive".into()));
        }
        self.dialect.sample_fraction = Some(fraction);
        Ok(self)
    }

    /// `SETTINGS key=value, ...` at the end of selects and deletes; keys are
    /// identifiers, values letters, digits, `_` and `.`.
    ///
    /// PHP: `settings()`.
    pub fn settings<K: AsRef<str>, V: AsRef<str>>(
        &mut self,
        settings: impl IntoIterator<Item = (K, V)>,
    ) -> Result<&mut Self> {
        for (key, value) in settings {
            let (key, value) = (key.as_ref(), value.as_ref());
            if !is_plain_identifier(key) {
                return Err(Error::Validation(format!("Invalid ClickHouse setting key: {key}")));
            }
            if !is_setting_value(value) {
                return Err(Error::Validation(format!("Invalid ClickHouse setting value: {value}")));
            }
            self.core.hints.push(format!("{key}={value}"));
        }
        Ok(self)
    }

    /// The `INSERT INTO table [(columns)] FORMAT format` envelope with the
    /// rows serialized in `format`. Each row must be an array; the columns
    /// default to the first row's keys.
    ///
    /// PHP: `bulkInsert()`.
    ///
    /// ```
    /// use utopia_query::builder::{ClickHouse, Format};
    /// use utopia_query::{Array, Value};
    ///
    /// let mut row = Array::default();
    /// row.set("id".into(), Value::Int(1));
    /// let insert = ClickHouse::new().from("events", "").bulk_insert(Format::JSONEachRow, [Value::Array(row)], Vec::new())?;
    /// assert_eq!(insert.query, "INSERT INTO `events` (`id`) FORMAT JSONEachRow");
    /// assert_eq!(insert.body.as_deref(), Some(r#"{"id":1}"#));
    /// # Ok::<(), utopia_query::Error>(())
    /// ```
    ///
    /// ```php
    /// use Utopia\Query\Builder\ClickHouse;
    /// use Utopia\Query\Builder\ClickHouse\Format;
    ///
    /// $insert = (new ClickHouse())->from('events')->bulkInsert(Format::JSONEachRow, [['id' => 1]]);
    /// echo $insert->query, "\n", $insert->body, "\n";
    /// // INSERT INTO `events` (`id`) FORMAT JSONEachRow
    /// // {"id":1}
    /// ```
    pub fn bulk_insert(
        &mut self,
        format: Format,
        rows: impl IntoIterator<Item = Value>,
        columns: Vec<String>,
    ) -> Result<FormattedInsertStatement> {
        let mut materialized = Vec::new();
        for row in rows {
            match row {
                Value::Array(row) => materialized.push(row),
                _ => return Err(Error::Validation("bulkInsert() rows must be associative arrays.".into())),
            }
        }
        let mut columns = columns;
        if columns.is_empty()
            && let Some(row) = materialized.first()
        {
            columns = row.keys().map(|k| key_string(&k.to_owned())).collect();
        }
        let sql = self.format_insert_envelope(format.as_str(), &columns)?;
        let body = format.serialize(&materialized, if columns.is_empty() { None } else { Some(&columns) })?;
        Ok(FormattedInsertStatement {
            statement: Statement::with_parts(sql, Vec::new(), false, self.core.executor.clone(), None),
            columns,
            format: format.as_str().to_owned(),
            body: Some(body),
        })
    }

    /// Makes [`Builder::insert`] (and [`Builder::formatted_insert`]) emit the
    /// `FORMAT format` envelope only, for a body sent separately; the columns
    /// default to the keys of the first row set.
    ///
    /// PHP: `insertFormat()`.
    pub fn insert_format<S: Into<String>>(
        &mut self,
        format: &str,
        columns: impl IntoIterator<Item = S>,
    ) -> Result<&mut Self> {
        if !is_format_name(format) {
            return Err(Error::Validation(format!("Invalid ClickHouse INSERT format: {format}")));
        }
        self.dialect.insert_format = Some(format.to_owned());
        self.dialect.insert_format_columns = columns.into_iter().map(Into::into).collect();
        Ok(self)
    }

    /// The `FORMAT` envelope of an [`Builder::insert_format`] insert (with no
    /// body), or `None` when no format was set.
    ///
    /// PHP: `insert()`, which returns a `FormattedInsertStatement` after `insertFormat()`.
    pub fn formatted_insert(&mut self) -> Result<Option<FormattedInsertStatement>> {
        let Some(format) = self.dialect.insert_format.clone() else {
            return Ok(None);
        };
        let columns = if !self.dialect.insert_format_columns.is_empty() {
            self.dialect.insert_format_columns.clone()
        } else {
            match self.core.rows.first() {
                Some(row) => row.keys().map(|k| key_string(&k.to_owned())).collect(),
                None => Vec::new(),
            }
        };
        let sql = self.format_insert_envelope(&format, &columns)?;
        Ok(Some(FormattedInsertStatement {
            statement: Statement::with_parts(sql, Vec::new(), false, self.core.executor.clone(), None),
            columns,
            format,
            body: None,
        }))
    }

    fn format_insert_envelope(&mut self, format: &str, columns: &[String]) -> Result<String> {
        self.core.bindings.clear();
        self.validate_table()?;
        if columns.iter().any(String::is_empty) {
            return Err(Error::Validation("Column names for FORMAT INSERT must be non-empty strings.".into()));
        }
        let mut sql = format!("INSERT INTO {}", self.quote(&self.core.table)?);
        if !columns.is_empty() {
            let wrapped = columns.iter().map(|c| self.resolve_and_wrap(c)).collect::<Result<Vec<_>>>()?;
            sql.push_str(" (");
            sql.push_str(&wrapped.join(", "));
            sql.push(')');
        }
        sql.push_str(" FORMAT ");
        sql.push_str(format);
        Ok(sql)
    }

    /// Rewrites the `?` placeholders of built statements to ClickHouse's
    /// `{paramN:Type}` and fills [`Statement::named_bindings`].
    ///
    /// PHP: `useNamedBindings()`.
    ///
    /// ```
    /// use utopia_query::Query;
    /// use utopia_query::builder::ClickHouse;
    ///
    /// let statement = ClickHouse::new()
    ///     .use_named_bindings(true)
    ///     .with_param_type("time", "DateTime64(3)")?
    ///     .from("events", "")
    ///     .filter([Query::greater_than("time", "2024-01-01 00:00:00"), Query::less_than_equal("value", 100)])
    ///     .build()?;
    /// assert_eq!(
    ///     statement.query,
    ///     "SELECT * FROM `events` WHERE `time` > {param0:DateTime64(3)} AND `value` <= {param1:Int64}"
    /// );
    /// # Ok::<(), utopia_query::Error>(())
    /// ```
    ///
    /// ```php
    /// use Utopia\Query\Builder\ClickHouse;
    /// use Utopia\Query\Query;
    ///
    /// echo (new ClickHouse())
    ///     ->useNamedBindings()
    ///     ->withParamType('time', 'DateTime64(3)')
    ///     ->from('events')
    ///     ->filter([Query::greaterThan('time', '2024-01-01 00:00:00'), Query::lessThanEqual('value', 100)])
    ///     ->build()->query, "\n";
    /// // SELECT * FROM `events` WHERE `time` > {param0:DateTime64(3)} AND `value` <= {param1:Int64}
    /// ```
    pub fn use_named_bindings(&mut self, enabled: bool) -> &mut Self {
        self.dialect.named_bindings = enabled;
        self
    }

    /// The ClickHouse type of the named placeholders bound against `column`
    /// (others are inferred: `Int64`, `Float64`, `UInt8`, `Nullable(String)`,
    /// `String`).
    ///
    /// PHP: `withParamType()`.
    pub fn with_param_type(&mut self, column: impl Into<String>, type_name: &str) -> Result<&mut Self> {
        if !is_clickhouse_type(type_name) {
            return Err(Error::Validation(format!("Invalid ClickHouse type: {type_name}")));
        }
        self.dialect.param_types.insert(column.into(), type_name.to_owned());
        Ok(self)
    }

    /// [`Builder::with_param_type`] for each pair.
    ///
    /// PHP: `withParamTypes()`.
    pub fn with_param_types<K: Into<String>, V: AsRef<str>>(
        &mut self,
        types: impl IntoIterator<Item = (K, V)>,
    ) -> Result<&mut Self> {
        for (column, type_name) in types {
            self.with_param_type(column, type_name.as_ref())?;
        }
        Ok(self)
    }

    /// Which `DELETE` form [`Builder::delete`] emits.
    ///
    /// PHP: `deleteMode()` (with the mode's name; see [`DeleteMode::parse`]).
    pub fn delete_mode(&mut self, mode: DeleteMode) -> &mut Self {
        self.dialect.delete_mode = mode;
        self
    }

    // ----- statistical aggregates (ClickHouse spells STDDEV and VARIANCE) ----

    /// `stddevPop(column) [AS alias]`: ClickHouse has no bare `STDDEV`.
    ///
    /// PHP: `stddev()`.
    pub fn stddev(&mut self, attribute: &str, alias: &str) -> Result<&mut Self> {
        let expression = format!("stddevPop({})", self.resolve_and_wrap(attribute)?);
        select_function(self, expression, alias)?;
        Ok(self)
    }

    /// `STDDEV_POP` (as an aggregate query).
    ///
    /// PHP: `stddevPop()`.
    pub fn stddev_pop(&mut self, attribute: impl Into<String>, alias: impl Into<String>) -> &mut Self {
        self.core.pending.push(Query::stddev_pop(attribute, alias));
        self
    }

    /// `STDDEV_SAMP` (as an aggregate query).
    ///
    /// PHP: `stddevSamp()`.
    pub fn stddev_samp(&mut self, attribute: impl Into<String>, alias: impl Into<String>) -> &mut Self {
        self.core.pending.push(Query::stddev_samp(attribute, alias));
        self
    }

    /// `varPop(column) [AS alias]`: ClickHouse has no bare `VARIANCE`.
    ///
    /// PHP: `variance()`.
    pub fn variance(&mut self, attribute: &str, alias: &str) -> Result<&mut Self> {
        let expression = format!("varPop({})", self.resolve_and_wrap(attribute)?);
        select_function(self, expression, alias)?;
        Ok(self)
    }

    /// `VAR_POP` (as an aggregate query).
    ///
    /// PHP: `varPop()`.
    pub fn var_pop(&mut self, attribute: impl Into<String>, alias: impl Into<String>) -> &mut Self {
        self.core.pending.push(Query::var_pop(attribute, alias));
        self
    }

    /// `VAR_SAMP` (as an aggregate query).
    ///
    /// PHP: `varSamp()`.
    pub fn var_samp(&mut self, attribute: impl Into<String>, alias: impl Into<String>) -> &mut Self {
        self.core.pending.push(Query::var_samp(attribute, alias));
        self
    }

    // ----- approximate aggregates ---------------------------------------------

    fn aggregate_function(&mut self, function: &str, columns: &[&str], alias: &str) -> Result<&mut Self> {
        let columns = columns.iter().map(|c| self.resolve_and_wrap(c)).collect::<Result<Vec<_>>>()?;
        select_function(self, format!("{function}({})", columns.join(", ")), alias)?;
        Ok(self)
    }

    /// `quantile(level)(column)`.
    ///
    /// PHP: `quantile()`.
    pub fn quantile(&mut self, level: f64, column: &str, alias: &str) -> Result<&mut Self> {
        self.aggregate_function(&format!("quantile({})", php_std::number::to_string(level)), &[column], alias)
    }

    /// `quantiles(level, ...)(column)`; levels must be in `[0, 1]`.
    ///
    /// PHP: `quantiles()`.
    pub fn quantiles(&mut self, levels: &[f64], column: &str, alias: &str) -> Result<&mut Self> {
        if levels.is_empty() {
            return Err(Error::Validation("quantiles() requires at least one level.".into()));
        }
        if levels.iter().any(|&level| !(0.0..=1.0).contains(&level)) {
            return Err(Error::Validation("quantiles() levels must be in the range [0, 1].".into()));
        }
        let levels: Vec<String> = levels.iter().map(|&level| php_std::number::to_string(level)).collect();
        self.aggregate_function(&format!("quantiles({})", levels.join(", ")), &[column], alias)
    }

    /// `quantileExact(level)(column)`.
    ///
    /// PHP: `quantileExact()`.
    pub fn quantile_exact(&mut self, level: f64, column: &str, alias: &str) -> Result<&mut Self> {
        self.aggregate_function(&format!("quantileExact({})", php_std::number::to_string(level)), &[column], alias)
    }

    /// `median(column)`.
    ///
    /// PHP: `median()`.
    pub fn median(&mut self, column: &str, alias: &str) -> Result<&mut Self> {
        self.aggregate_function("median", &[column], alias)
    }

    /// `uniq(column)`.
    ///
    /// PHP: `uniq()`.
    pub fn uniq(&mut self, column: &str, alias: &str) -> Result<&mut Self> {
        self.aggregate_function("uniq", &[column], alias)
    }

    /// `uniqExact(column)`.
    ///
    /// PHP: `uniqExact()`.
    pub fn uniq_exact(&mut self, column: &str, alias: &str) -> Result<&mut Self> {
        self.aggregate_function("uniqExact", &[column], alias)
    }

    /// `uniqCombined(column)`.
    ///
    /// PHP: `uniqCombined()`.
    pub fn uniq_combined(&mut self, column: &str, alias: &str) -> Result<&mut Self> {
        self.aggregate_function("uniqCombined", &[column], alias)
    }

    /// `argMin(value, arg)`.
    ///
    /// PHP: `argMin()`.
    pub fn arg_min(&mut self, value_column: &str, arg_column: &str, alias: &str) -> Result<&mut Self> {
        self.aggregate_function("argMin", &[value_column, arg_column], alias)
    }

    /// `argMax(value, arg)`.
    ///
    /// PHP: `argMax()`.
    pub fn arg_max(&mut self, value_column: &str, arg_column: &str, alias: &str) -> Result<&mut Self> {
        self.aggregate_function("argMax", &[value_column, arg_column], alias)
    }

    /// `topK(k)(column)`.
    ///
    /// PHP: `topK()`.
    pub fn top_k(&mut self, k: i64, column: &str, alias: &str) -> Result<&mut Self> {
        self.aggregate_function(&format!("topK({k})"), &[column], alias)
    }

    /// `topKWeighted(k)(column, weight)`.
    ///
    /// PHP: `topKWeighted()`.
    pub fn top_k_weighted(&mut self, k: i64, column: &str, weight_column: &str, alias: &str) -> Result<&mut Self> {
        self.aggregate_function(&format!("topKWeighted({k})"), &[column, weight_column], alias)
    }

    /// `any(column)`.
    ///
    /// PHP: `anyValue()`.
    pub fn any_value(&mut self, column: &str, alias: &str) -> Result<&mut Self> {
        self.aggregate_function("any", &[column], alias)
    }

    /// `anyLast(column)`.
    ///
    /// PHP: `anyLastValue()`.
    pub fn any_last_value(&mut self, column: &str, alias: &str) -> Result<&mut Self> {
        self.aggregate_function("anyLast", &[column], alias)
    }

    /// `groupUniqArray(column)`.
    ///
    /// PHP: `groupUniqArray()`.
    pub fn group_uniq_array(&mut self, column: &str, alias: &str) -> Result<&mut Self> {
        self.aggregate_function("groupUniqArray", &[column], alias)
    }

    /// `groupArrayMovingAvg(column)`.
    ///
    /// PHP: `groupArrayMovingAvg()`.
    pub fn group_array_moving_avg(&mut self, column: &str, alias: &str) -> Result<&mut Self> {
        self.aggregate_function("groupArrayMovingAvg", &[column], alias)
    }

    /// `groupArrayMovingSum(column)`.
    ///
    /// PHP: `groupArrayMovingSum()`.
    pub fn group_array_moving_sum(&mut self, column: &str, alias: &str) -> Result<&mut Self> {
        self.aggregate_function("groupArrayMovingSum", &[column], alias)
    }

    // ----- joins, LIMIT BY, WITH FILL -----------------------------------------

    /// `ARRAY JOIN column [AS alias]`.
    ///
    /// PHP: `arrayJoin()`.
    pub fn array_join(&mut self, column: impl Into<String>, alias: impl Into<String>) -> &mut Self {
        self.dialect.array_joins.push(ArrayJoin { left: false, column: column.into(), alias: alias.into() });
        self
    }

    /// `LEFT ARRAY JOIN column [AS alias]`.
    ///
    /// PHP: `leftArrayJoin()`.
    pub fn left_array_join(&mut self, column: impl Into<String>, alias: impl Into<String>) -> &mut Self {
        self.dialect.array_joins.push(ArrayJoin { left: true, column: column.into(), alias: alias.into() });
        self
    }

    /// `ASOF JOIN table [AS alias] ON l = r AND ... AND left operator right`.
    ///
    /// PHP: `asofJoin()`.
    pub fn asof_join<L: AsRef<str>, R: AsRef<str>>(
        &mut self,
        table: &str,
        equi_pairs: impl IntoIterator<Item = (L, R)>,
        left_inequality: &str,
        operator: AsofOperator,
        right_inequality: &str,
        alias: &str,
    ) -> Result<&mut Self> {
        let clause =
            self.asof_clause("ASOF JOIN", table, equi_pairs, left_inequality, operator, right_inequality, alias)?;
        self.dialect.raw_joins.push(clause);
        Ok(self)
    }

    /// `ASOF LEFT JOIN ...`.
    ///
    /// PHP: `asofLeftJoin()`.
    pub fn asof_left_join<L: AsRef<str>, R: AsRef<str>>(
        &mut self,
        table: &str,
        equi_pairs: impl IntoIterator<Item = (L, R)>,
        left_inequality: &str,
        operator: AsofOperator,
        right_inequality: &str,
        alias: &str,
    ) -> Result<&mut Self> {
        let clause =
            self.asof_clause("ASOF LEFT JOIN", table, equi_pairs, left_inequality, operator, right_inequality, alias)?;
        self.dialect.raw_joins.push(clause);
        Ok(self)
    }

    #[allow(clippy::too_many_arguments)]
    fn asof_clause<L: AsRef<str>, R: AsRef<str>>(
        &mut self,
        keyword: &str,
        table: &str,
        equi_pairs: impl IntoIterator<Item = (L, R)>,
        left_inequality: &str,
        operator: AsofOperator,
        right_inequality: &str,
        alias: &str,
    ) -> Result<String> {
        let pairs: Vec<(L, R)> = equi_pairs.into_iter().collect();
        if pairs.is_empty() {
            return Err(Error::Validation("ASOF JOIN requires at least one equi-join column pair.".into()));
        }
        let mut table = self.quote(table)?;
        if !alias.is_empty() {
            table.push_str(" AS ");
            table.push_str(&self.quote(alias)?);
        }
        let mut conditions = Vec::with_capacity(pairs.len() + 1);
        for (left, right) in &pairs {
            conditions.push(format!(
                "{} = {}",
                self.resolve_and_wrap(left.as_ref())?,
                self.resolve_and_wrap(right.as_ref())?
            ));
        }
        conditions.push(format!(
            "{} {} {}",
            self.resolve_and_wrap(left_inequality)?,
            operator.as_str(),
            self.resolve_and_wrap(right_inequality)?
        ));
        Ok(format!("{keyword} {table} ON {}", conditions.join(" AND ")))
    }

    /// `LIMIT count BY columns`.
    ///
    /// PHP: `limitBy()`.
    pub fn limit_by<S: Into<String>>(&mut self, count: i64, columns: impl IntoIterator<Item = S>) -> &mut Self {
        self.dialect.limit_by = Some((count, columns.into_iter().map(Into::into).collect()));
        self
    }

    /// `ORDER BY column ASC|DESC WITH FILL [FROM ?] [TO ?] [STEP ?]`.
    ///
    /// PHP: `orderWithFill()`.
    pub fn order_with_fill(
        &mut self,
        column: &str,
        direction: &str,
        from: Option<Value>,
        to: Option<Value>,
        step: Option<Value>,
    ) -> Result<&mut Self> {
        let normalized = direction.to_ascii_uppercase();
        if normalized != "ASC" && normalized != "DESC" {
            return Err(Error::Validation(format!("Invalid direction for orderWithFill: {direction}")));
        }
        let mut expression = format!("{} {normalized} WITH FILL", self.resolve_and_wrap(column)?);
        let mut bindings = Vec::new();
        for (keyword, value) in [(" FROM ?", from), (" TO ?", to), (" STEP ?", step)] {
            if let Some(value) = value.filter(|v| !matches!(v, Value::Null)) {
                expression.push_str(keyword);
                bindings.push(value);
            }
        }
        self.core.raw_orders.push(Condition::new(expression, bindings));
        Ok(self)
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn type_names() {
        assert!(is_clickhouse_type("Int64"));
        assert!(is_clickhouse_type("Nullable(DateTime64(3))"));
        assert!(is_clickhouse_type("Decimal(10, 2)"));
        assert!(is_clickhouse_type("Int64\n"));
        assert!(!is_clickhouse_type("DROP TABLE x; --"));
        assert!(!is_clickhouse_type("A(B(C(1)))"));
        assert!(!is_clickhouse_type("A(1))"));
    }
}
