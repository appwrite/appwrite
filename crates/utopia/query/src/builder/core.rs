//! [`Builder`]: the state every dialect shares, and the compilation PHP's
//! abstract `Utopia\Query\Builder` implements; [`Dialect`]: what a dialect
//! changes.

use std::collections::{HashMap, HashSet};
use std::sync::Arc;

use php_std::ArrayKey;
use php_std::json::Flags;

use crate::builder::AnyBuilder;
use crate::builder::case::{CaseExpression, Kind as CaseKind, WhenClause};
use crate::builder::join::JoinBuilder;
use crate::builder::types::{
    Binding, ColumnPredicate, Condition, CteClause, Executor, JoinType, LockMode, Statement, UnionClause,
    WindowDefinition, WindowSelect,
};
use crate::error::{Error, Result};
use crate::hook::{Attribute, Filter, Hook, JoinFilter, Placement};
use crate::method::Method;
use crate::query::{ParsedQuery, Query, is_numeric};
use crate::value::{self, Array, Object, Param, Value};

/// A builder used as a source: `FROM (subquery) AS alias`, a select-list
/// subquery.
///
/// PHP: `Utopia\Query\Builder\SubSelect`.
#[derive(Clone)]
pub struct SubSelect {
    /// The subquery.
    pub subquery: Box<dyn AnyBuilder>,
    /// Its alias.
    pub alias: String,
}

/// `column [NOT] IN (subquery)`.
///
/// PHP: `Utopia\Query\Builder\WhereInSubquery`.
#[derive(Clone)]
pub struct WhereInSubquery {
    /// The column.
    pub column: String,
    /// The subquery.
    pub subquery: Box<dyn AnyBuilder>,
    /// Whether it is `NOT IN`.
    pub not: bool,
}

/// `[NOT] EXISTS (subquery)`.
///
/// PHP: `Utopia\Query\Builder\ExistsSubquery`.
#[derive(Clone)]
pub struct ExistsSubquery {
    /// The subquery.
    pub subquery: Box<dyn AnyBuilder>,
    /// Whether it is `NOT EXISTS`.
    pub not: bool,
}

/// `[LEFT] JOIN LATERAL (subquery) AS alias ON true`.
///
/// PHP: `Utopia\Query\Builder\LateralJoin`.
#[derive(Clone)]
pub struct LateralJoin {
    /// The subquery.
    pub subquery: Box<dyn AnyBuilder>,
    /// Its alias.
    pub alias: String,
    /// `Inner` or `Left`.
    pub join_type: JoinType,
}

/// A map kept in insertion order where setting a key again replaces its
/// value in place (a PHP array keyed by column).
#[derive(Debug, Clone, PartialEq)]
pub(crate) struct Ordered<T>(pub(crate) Vec<(String, T)>);

impl<T> Default for Ordered<T> {
    fn default() -> Self {
        Ordered(Vec::new())
    }
}

impl<T> Ordered<T> {
    pub(crate) fn set(&mut self, key: impl Into<String>, value: T) {
        let key = key.into();
        match self.0.iter_mut().find(|(k, _)| *k == key) {
            Some(entry) => entry.1 = value,
            None => self.0.push((key, value)),
        }
    }

    pub(crate) fn get(&self, key: &str) -> Option<&T> {
        self.0.iter().find(|(k, _)| k == key).map(|(_, v)| v)
    }

    pub(crate) fn clear(&mut self) {
        self.0.clear();
    }
}

/// A callback run at the start of every build, with the builder.
pub type BeforeBuild<D> = Arc<dyn Fn(&mut Builder<D>) -> Result<()> + Send + Sync>;

/// A callback run on every built statement, returning the statement to use.
pub type AfterBuild = Arc<dyn Fn(Statement) -> Result<Statement> + Send + Sync>;

/// The state of a builder that every dialect shares: what PHP's abstract
/// `Builder` and its traits hold.
#[derive(Clone, Default)]
pub struct Core {
    pub(crate) table: String,
    pub(crate) alias: String,
    pub(crate) pending: Vec<Query>,
    pub(crate) bindings: Vec<Binding>,
    pub(crate) unions: Vec<UnionClause>,
    pub(crate) filter_hooks: Vec<Arc<dyn Filter>>,
    pub(crate) attribute_hooks: Vec<Arc<dyn Attribute>>,
    pub(crate) join_filter_hooks: Vec<Arc<dyn JoinFilter>>,
    pub(crate) resolved: HashMap<String, String>,
    pub(crate) rows: Vec<Array>,
    pub(crate) raw_sets: Ordered<String>,
    pub(crate) raw_set_bindings: Ordered<Vec<Value>>,
    pub(crate) lock_mode: Option<LockMode>,
    pub(crate) lock_of_table: Option<String>,
    pub(crate) insert_select_source: Option<Box<dyn AnyBuilder>>,
    pub(crate) insert_select_columns: Vec<String>,
    pub(crate) ctes: Vec<CteClause>,
    pub(crate) raw_selects: Vec<Condition>,
    pub(crate) window_selects: Vec<WindowSelect>,
    pub(crate) window_definitions: Vec<WindowDefinition>,
    pub(crate) sample: Option<(f64, String)>,
    pub(crate) cases: Vec<CaseExpression>,
    pub(crate) case_sets: Ordered<CaseExpression>,
    pub(crate) insert_column_expressions: Ordered<String>,
    pub(crate) insert_column_expression_bindings: Ordered<Vec<Value>>,
    pub(crate) insert_alias: String,
    pub(crate) where_in_subqueries: Vec<WhereInSubquery>,
    pub(crate) sub_selects: Vec<SubSelect>,
    pub(crate) from_subquery: Option<SubSelect>,
    pub(crate) tableless: bool,
    pub(crate) raw_orders: Vec<Condition>,
    pub(crate) raw_groups: Vec<Condition>,
    pub(crate) raw_havings: Vec<Condition>,
    pub(crate) raw_wheres: Vec<Condition>,
    pub(crate) column_predicates: Vec<ColumnPredicate>,
    pub(crate) joins: HashMap<usize, JoinBuilder>,
    pub(crate) exists_subqueries: Vec<ExistsSubquery>,
    pub(crate) lateral_joins: Vec<LateralJoin>,
    pub(crate) after_build: Vec<AfterBuild>,
    pub(crate) executor: Option<Executor>,
    pub(crate) qualify: bool,
    pub(crate) aggregation_aliases: HashSet<String>,
    pub(crate) fetch_count: Option<i64>,
    pub(crate) fetch_with_ties: bool,
    pub(crate) conflict_keys: Vec<String>,
    pub(crate) conflict_update_columns: Vec<String>,
    pub(crate) conflict_raw_sets: Ordered<String>,
    pub(crate) conflict_raw_set_bindings: Ordered<Vec<Value>>,
    pub(crate) hints: Vec<String>,
    pub(crate) json_sets: Ordered<Condition>,
    pub(crate) returning: Vec<String>,
    pub(crate) group_by_modifier: Option<String>,
}

/// A query builder for one dialect: from a table, with queries (filters,
/// ordering, pagination, aggregates, joins) and the builder's own clauses
/// (subqueries, raw SQL, window functions, CTEs, unions), compiled by
/// [`Builder::build`] into a [`Statement`]; also inserts, updates, deletes and
/// upserts.
///
/// The dialects are type aliases: [`MySql`](crate::builder::MySql) and the
/// others. Features only some dialects have are traits in
/// [`feature`](crate::builder::feature).
///
/// PHP: the abstract `Utopia\Query\Builder` and its subclasses.
pub struct Builder<D: Dialect> {
    pub(crate) core: Core,
    pub(crate) before_build: Vec<BeforeBuild<D>>,
    pub(crate) dialect: D,
}

impl<D: Dialect> Clone for Builder<D> {
    /// A deep copy (subqueries included); hooks, callbacks and the executor are shared.
    fn clone(&self) -> Self {
        Builder { core: self.core.clone(), before_build: self.before_build.clone(), dialect: self.dialect.clone() }
    }
}

impl<D: Dialect> Default for Builder<D> {
    fn default() -> Self {
        Builder { core: Core::default(), before_build: Vec::new(), dialect: D::default() }
    }
}

/// What a dialect changes in the shared compilation: identifier quoting,
/// random order, regex, and the clauses only some dialects emit. Every hook
/// has the behaviour of PHP's base `Builder` unless the dialect overrides it.
pub trait Dialect: Clone + Default + Send + Sync + Sized + 'static {
    /// The PHP class (`Utopia\Query\Builder\MySQL`), as messages name it.
    const CLASS: &'static str;

    /// The identifier quote character.
    const WRAP: char = '`';

    /// Quotes an identifier (`` `users`.`id` ``).
    fn quote(_b: &Builder<Self>, identifier: &str) -> Result<String> {
        crate::quote::quote(identifier, Self::WRAP)
    }

    /// The random-order expression (`RAND()`).
    fn compile_random(b: &Builder<Self>) -> String;

    /// A regex filter on the wrapped attribute.
    fn compile_regex(b: &mut Builder<Self>, attribute: &str, values: &Array, column: Option<&str>) -> Result<String>;

    /// A filter query (the base compiles comparisons, LIKE, IN, logical and raw filters).
    fn compile_filter(b: &mut Builder<Self>, query: &Query) -> Result<String> {
        b.base_compile_filter(query)
    }

    /// `LIKE` with a prefix and suffix around the escaped value.
    fn compile_like(
        b: &mut Builder<Self>,
        attribute: &str,
        values: &Array,
        prefix: &str,
        suffix: &str,
        not: bool,
        column: Option<&str>,
    ) -> Result<String> {
        b.base_compile_like(attribute, values, prefix, suffix, not, column)
    }

    /// `contains` (`LIKE '%v%'`, `OR`ed for several values).
    fn compile_contains(
        b: &mut Builder<Self>,
        attribute: &str,
        values: &Array,
        column: Option<&str>,
    ) -> Result<String> {
        b.base_compile_contains(attribute, values, column)
    }

    /// `containsAll` (`LIKE '%v%'`, `AND`ed).
    fn compile_contains_all(
        b: &mut Builder<Self>,
        attribute: &str,
        values: &Array,
        column: Option<&str>,
    ) -> Result<String> {
        b.base_compile_contains_all(attribute, values, column)
    }

    /// `notContains` (`NOT LIKE '%v%'`, `AND`ed).
    fn compile_not_contains(
        b: &mut Builder<Self>,
        attribute: &str,
        values: &Array,
        column: Option<&str>,
    ) -> Result<String> {
        b.base_compile_not_contains(attribute, values, column)
    }

    /// The `LIKE` keyword (`ILIKE` on PostgreSQL).
    fn like_keyword(_b: &Builder<Self>) -> &'static str {
        "LIKE"
    }

    /// The `FROM` clause.
    fn build_table_clause(b: &mut Builder<Self>) -> Result<String> {
        b.base_build_table_clause()
    }

    /// Clauses after the joins and before `WHERE` (ClickHouse `ARRAY JOIN`, `PREWHERE`).
    fn build_after_joins_clause(_b: &mut Builder<Self>, _grouped: &ParsedQuery) -> Result<String> {
        Ok(String::new())
    }

    /// Clauses after `GROUP BY` (ClickHouse `WITH TOTALS`).
    fn build_after_group_by_clause(_b: &mut Builder<Self>) -> Result<String> {
        Ok(String::new())
    }

    /// Clauses after `ORDER BY` (ClickHouse `LIMIT BY`).
    fn build_after_order_by_clause(_b: &mut Builder<Self>) -> Result<String> {
        Ok(String::new())
    }

    /// Clauses at the very end of a select (ClickHouse `SETTINGS`).
    fn build_settings_clause(_b: &mut Builder<Self>) -> Result<String> {
        Ok(String::new())
    }

    /// One member of a `UNION` chain (parenthesised by default).
    fn wrap_union_member(_b: &Builder<Self>, sql: &str) -> String {
        format!("({sql})")
    }

    /// Whether to emit `OFFSET`; the base refuses an offset without a limit.
    fn should_emit_offset(_b: &Builder<Self>, offset: Option<i64>, limit: Option<i64>) -> Result<bool> {
        match (offset, limit) {
            (None, _) => Ok(false),
            (Some(_), None) => Err(Error::Validation(
                "OFFSET requires LIMIT on this engine. Set a limit or use the dialect's native no-limit form.".into(),
            )),
            (Some(_), Some(_)) => Ok(true),
        }
    }

    /// An `ORDER BY` vector distance expression (PostgreSQL).
    fn compile_vector_order_expression(_b: &mut Builder<Self>) -> Result<Option<Condition>> {
        Ok(None)
    }

    /// A `groupByTimeBucket` expression; unsupported by default.
    fn compile_group_by_time_bucket(_b: &mut Builder<Self>, _attribute: &str, _interval: &str) -> Result<String> {
        Err(Error::Unsupported(format!("groupByTimeBucket is not supported by {}", Self::CLASS)))
    }

    /// Where a join filter's condition goes (cross and natural joins have no `ON`).
    fn resolve_join_filter_placement(_b: &Builder<Self>, requested: Placement, is_cross_join: bool) -> Placement {
        if is_cross_join { Placement::Where } else { requested }
    }

    /// The `ON` of a join built with a [`JoinBuilder`].
    fn compile_join_with_builder(b: &mut Builder<Self>, query: &Query, join: &JoinBuilder) -> Result<String> {
        b.base_compile_join_with_builder(query, join)
    }

    /// Compiles the select statement.
    fn build(b: &mut Builder<Self>) -> Result<Statement> {
        b.base_build()
    }

    /// Compiles the insert statement.
    fn insert(b: &mut Builder<Self>) -> Result<Statement> {
        b.base_insert()
    }

    /// `INSERT ... DEFAULT VALUES`.
    fn insert_default_values(b: &mut Builder<Self>) -> Result<Statement> {
        b.base_insert_default_values()
    }

    /// Compiles the update statement.
    fn update(b: &mut Builder<Self>) -> Result<Statement> {
        b.base_update()
    }

    /// Compiles the delete statement.
    fn delete(b: &mut Builder<Self>) -> Result<Statement> {
        b.base_delete()
    }

    /// `EXPLAIN` of the select statement.
    fn explain(b: &mut Builder<Self>, analyze: bool, _options: &ExplainOptions) -> Result<Statement> {
        b.base_explain(analyze)
    }

    /// Clears the dialect's own state (after the shared state is cleared).
    fn reset(_b: &mut Builder<Self>) {}

    /// Adds a hook.
    fn add_hook(b: &mut Builder<Self>, hook: Hook) -> Result<()> {
        b.base_add_hook(hook);
        Ok(())
    }

    /// Adds a row to insert or the values to update.
    fn set(b: &mut Builder<Self>, row: Array) -> Result<()> {
        b.core.rows.push(row);
        Ok(())
    }

    /// The serializer `fromAst`/`toAst` use for expressions.
    fn ast_dialect() -> crate::builder::AstDialect {
        crate::builder::AstDialect::Standard
    }
}

/// The options of `EXPLAIN` beyond `ANALYZE`, for the dialects that have them.
#[derive(Debug, Clone, Default, PartialEq, Eq)]
pub struct ExplainOptions {
    /// `VERBOSE` (PostgreSQL).
    pub verbose: bool,
    /// `BUFFERS` (PostgreSQL).
    pub buffers: bool,
    /// The output format (`JSON`), or empty.
    pub format: String,
}

impl<D: Dialect> Builder<D> {
    /// An empty builder.
    pub fn new() -> Self {
        Builder::default()
    }

    /// The dialect's state.
    pub fn dialect(&self) -> &D {
        &self.dialect
    }

    pub(crate) fn quote(&self, identifier: &str) -> Result<String> {
        D::quote(self, identifier)
    }

    pub(crate) fn add_binding(&mut self, value: Value, column: Option<&str>) {
        self.core.bindings.push(Binding::new(value, column.map(str::to_owned)));
    }

    pub(crate) fn add_bindings(&mut self, values: impl IntoIterator<Item = Value>) {
        for value in values {
            self.core.bindings.push(Binding::new(value, None));
        }
    }

    pub(crate) fn binding_values(&self) -> Vec<Value> {
        self.core.bindings.iter().map(|b| b.value.clone()).collect()
    }

    pub(crate) fn statement(&self, sql: impl Into<String>) -> Statement {
        Statement::with_parts(sql, self.binding_values(), false, self.core.executor.clone(), None)
    }

    pub(crate) fn resolve_attribute(&mut self, attribute: &str) -> Result<String> {
        if let Some(resolved) = self.core.resolved.get(attribute) {
            return Ok(resolved.clone());
        }
        let mut resolved = attribute.to_owned();
        for hook in self.core.attribute_hooks.clone() {
            resolved = hook.resolve(&resolved)?;
        }
        self.core.resolved.insert(attribute.to_owned(), resolved.clone());
        Ok(resolved)
    }

    pub(crate) fn resolve_and_wrap(&mut self, attribute: &str) -> Result<String> {
        let mut resolved = self.resolve_attribute(attribute)?;
        if self.core.qualify
            && resolved != "*"
            && !resolved.contains('.')
            && !self.core.aggregation_aliases.contains(&resolved)
        {
            resolved = format!("{}.{}", self.core.alias, resolved);
        }
        self.quote(&resolved)
    }

    pub(crate) fn validate_table(&self) -> Result<()> {
        if self.core.tableless {
            return Ok(());
        }
        if self.core.table.is_empty() && self.core.from_subquery.is_none() {
            return Err(Error::Validation("No table specified. Call from() or into() before building a query.".into()));
        }
        Ok(())
    }

    pub(crate) fn validate_rows(&self, operation: &str) -> Result<()> {
        if self.core.rows.is_empty() {
            return Err(Error::Validation(format!("No rows to {operation}. Call set() before {operation}().")));
        }
        if self.core.rows.iter().any(Array::is_empty) {
            return Err(Error::Validation(format!(
                "Cannot {operation} an empty row. Each set() call must include at least one column."
            )));
        }
        Ok(())
    }

    /// The columns of the rows: the first row's keys; every row must have the same set.
    pub(crate) fn validate_and_get_columns(&self) -> Result<Vec<ArrayKey>> {
        let columns: Vec<ArrayKey> = self.core.rows[0].keys().map(|k| k.to_owned()).collect();
        if columns.iter().any(|c| matches!(c, ArrayKey::Str(s) if s.is_empty())) {
            return Err(Error::Validation("Column names must be non-empty strings.".into()));
        }
        if self.core.rows.len() > 1 {
            let sorted = |keys: Vec<ArrayKey>| {
                let mut values: Vec<Value> = keys.into_iter().map(key_value).collect();
                values.sort_by(|a, b| a.cmp_php(b));
                values
            };
            let expected = sorted(columns.clone());
            for (i, row) in self.core.rows.iter().enumerate() {
                let keys = sorted(row.keys().map(|k| k.to_owned()).collect());
                if keys.len() != expected.len() || keys.iter().zip(&expected).any(|(a, b)| !a.strict_eq(b)) {
                    return Err(Error::Validation(format!(
                        "Row {i} has different columns than row 0. All rows in a batch must have the same columns."
                    )));
                }
            }
        }
        Ok(columns)
    }

    // ----- select ---------------------------------------------------------

    pub(crate) fn base_build(&mut self) -> Result<Statement> {
        self.core.bindings.clear();
        self.core.resolved.clear();
        for callback in self.before_build.clone() {
            callback(self)?;
        }
        self.validate_table()?;
        let cte_prefix = self.build_cte_prefix()?;
        let grouped = Query::group_by_type(&self.core.pending);
        self.prepare_alias_qualification(&grouped);

        let mut join_filter_where = Vec::new();
        let mut parts = vec![self.build_select_clause(&grouped)?];
        let push = |parts: &mut Vec<String>, fragment: String| {
            if !fragment.is_empty() {
                parts.push(fragment);
            }
        };
        let from = D::build_table_clause(self)?;
        push(&mut parts, from);
        let joins = self.build_joins_clause(&grouped, &mut join_filter_where)?;
        push(&mut parts, joins);
        let after_joins = D::build_after_joins_clause(self, &grouped)?;
        push(&mut parts, after_joins);
        let where_ = self.build_where_clause(&grouped, join_filter_where)?;
        push(&mut parts, where_);
        let group_by = self.build_group_by_clause(&grouped)?;
        push(&mut parts, group_by);
        let after_group_by = D::build_after_group_by_clause(self)?;
        push(&mut parts, after_group_by);
        let having = self.build_having_clause(&grouped)?;
        push(&mut parts, having);
        let window = self.build_window_clause()?;
        push(&mut parts, window);
        let order_by = self.build_order_by_clause()?;
        push(&mut parts, order_by);
        let after_order_by = D::build_after_order_by_clause(self)?;
        push(&mut parts, after_order_by);
        let limit = self.build_limit_clause(&grouped)?;
        push(&mut parts, limit);
        let locking = self.build_locking_clause()?;
        push(&mut parts, locking);
        let settings = D::build_settings_clause(self)?;
        push(&mut parts, settings);

        let mut sql = parts.join(" ");
        let union_suffix = self.build_union_suffix();
        if !union_suffix.is_empty() {
            sql = D::wrap_union_member(self, &sql) + &union_suffix;
        }
        let sql = cte_prefix + &sql;

        let mut result = Statement::with_parts(sql, self.binding_values(), true, self.core.executor.clone(), None);
        for callback in self.core.after_build.clone() {
            result = callback(result)?;
        }
        Ok(result)
    }

    fn build_cte_prefix(&mut self) -> Result<String> {
        if self.core.ctes.is_empty() {
            return Ok(String::new());
        }
        let mut recursive = false;
        let mut parts = Vec::with_capacity(self.core.ctes.len());
        for cte in self.core.ctes.clone() {
            if cte.recursive {
                recursive = true;
            }
            self.add_bindings(cte.bindings);
            let mut name = self.quote(&cte.name)?;
            if !cte.columns.is_empty() {
                let columns = cte.columns.iter().map(|c| self.quote(c)).collect::<Result<Vec<_>>>()?;
                name.push('(');
                name.push_str(&columns.join(", "));
                name.push(')');
            }
            parts.push(format!("{name} AS ({})", cte.query));
        }
        let keyword = if recursive { "WITH RECURSIVE" } else { "WITH" };
        Ok(format!("{keyword} {} ", parts.join(", ")))
    }

    pub(crate) fn prepare_alias_qualification(&mut self, grouped: &ParsedQuery) {
        self.core.qualify = false;
        self.core.aggregation_aliases.clear();
        if grouped.joins.is_empty() || self.core.alias.is_empty() {
            return;
        }
        self.core.qualify = true;
        for aggregate in &grouped.aggregations {
            if !aggregate.alias().is_empty() {
                self.core.aggregation_aliases.insert(aggregate.alias().to_owned());
            }
        }
    }

    fn build_select_clause(&mut self, grouped: &ParsedQuery) -> Result<String> {
        let mut parts = Vec::new();
        for aggregate in &grouped.aggregations {
            parts.push(self.compile_aggregate_query(aggregate)?);
        }
        if let Some(selection) = grouped.selections.first() {
            parts.push(self.compile_select_query(selection)?);
        }
        for sub in self.core.sub_selects.clone() {
            let mut subquery = sub.subquery.clone();
            let result = subquery.build()?;
            parts.push(format!("({}) AS {}", result.query, self.quote(&sub.alias)?));
            self.add_bindings(result.bindings);
        }
        for raw in self.core.raw_selects.clone() {
            parts.push(raw.expression);
            self.add_bindings(raw.bindings);
        }
        for window in self.core.window_selects.clone() {
            parts.push(self.compile_window_select(&window)?);
        }
        for case in self.core.cases.clone() {
            parts.push(self.compile_case(&case)?);
        }
        let list = if parts.is_empty() { "*".to_owned() } else { parts.join(", ") };
        let keyword = if grouped.distinct { "SELECT DISTINCT" } else { "SELECT" };
        Ok(format!("{keyword} {list}"))
    }

    fn compile_window_select(&mut self, window: &WindowSelect) -> Result<String> {
        if let Some(name) = &window.window_name {
            return Ok(format!("{} OVER {} AS {}", window.function, self.quote(name)?, self.quote(&window.alias)?));
        }
        let mut over = Vec::new();
        if let Some(partition) = window.partition_by.as_ref().filter(|p| !p.is_empty()) {
            let columns = partition.iter().map(|c| self.resolve_and_wrap(c)).collect::<Result<Vec<_>>>()?;
            over.push(format!("PARTITION BY {}", columns.join(", ")));
        }
        if let Some(order) = window.order_by.as_ref().filter(|o| !o.is_empty()) {
            over.push(format!("ORDER BY {}", self.compile_order_by_list(order)?));
        }
        if let Some(frame) = &window.frame {
            over.push(frame.to_sql());
        }
        Ok(format!("{} OVER ({}) AS {}", window.function, over.join(" "), self.quote(&window.alias)?))
    }

    pub(crate) fn compile_order_by_list(&mut self, order_by: &[String]) -> Result<String> {
        let mut columns = Vec::with_capacity(order_by.len());
        for column in order_by {
            match column.strip_prefix('-') {
                Some(name) => columns.push(format!("{} DESC", self.resolve_and_wrap(name)?)),
                None => columns.push(format!("{} ASC", self.resolve_and_wrap(column)?)),
            }
        }
        Ok(columns.join(", "))
    }

    pub(crate) fn base_build_table_clause(&mut self) -> Result<String> {
        if self.core.tableless {
            return Ok(String::new());
        }
        if let Some(sub) = self.core.from_subquery.clone() {
            let mut subquery = sub.subquery;
            let result = subquery.build()?;
            self.add_bindings(result.bindings);
            return Ok(format!("FROM ({}) AS {}", result.query, self.quote(&sub.alias)?));
        }
        let mut sql = format!("FROM {}", self.quote(&self.core.table)?);
        if !self.core.alias.is_empty() {
            sql.push_str(" AS ");
            sql.push_str(&self.quote(&self.core.alias)?);
        }
        if let Some((percent, method)) = &self.core.sample {
            sql.push_str(&format!(" TABLESAMPLE {method}({})", php_std::number::to_string(*percent)));
        }
        Ok(sql)
    }

    fn build_joins_clause(&mut self, grouped: &ParsedQuery, join_filter_where: &mut Vec<Condition>) -> Result<String> {
        let mut parts = Vec::new();
        if !grouped.joins.is_empty() {
            let join_indices: Vec<usize> =
                self.core.pending.iter().enumerate().filter(|(_, q)| q.method().is_join()).map(|(i, _)| i).collect();
            for (join_index, join) in grouped.joins.iter().enumerate() {
                let pending_index = join_indices.get(join_index).copied();
                let builder = pending_index.and_then(|i| self.core.joins.get(&i)).cloned();
                let mut sql = match builder {
                    Some(builder) => D::compile_join_with_builder(self, join, &builder)?,
                    None => self.compile_join_query(join)?,
                };
                let join_type = match join.method() {
                    Method::Join => JoinType::Inner,
                    Method::LeftJoin => JoinType::Left,
                    Method::RightJoin => JoinType::Right,
                    Method::CrossJoin => JoinType::Cross,
                    Method::FullOuterJoin => JoinType::FullOuter,
                    Method::NaturalJoin => JoinType::Natural,
                    other => return Err(Error::Unsupported(format!("Unsupported join method: {}", other.as_str()))),
                };
                let is_cross = matches!(join_type, JoinType::Cross | JoinType::Natural);
                let table = if join.alias().is_empty() { join.attribute() } else { join.alias() }.to_owned();
                for hook in self.core.join_filter_hooks.clone() {
                    let Some(result) = hook.filter_join(&table, join_type)? else {
                        continue;
                    };
                    let placement = D::resolve_join_filter_placement(self, result.placement, is_cross);
                    if placement == Placement::On {
                        sql.push_str(" AND ");
                        sql.push_str(&result.condition.expression);
                        self.add_bindings(result.condition.bindings);
                    } else {
                        join_filter_where.push(result.condition);
                    }
                }
                parts.push(sql);
            }
        }
        for lateral in self.core.lateral_joins.clone() {
            let mut subquery = lateral.subquery;
            let result = subquery.build()?;
            self.add_bindings(result.bindings);
            let keyword = if lateral.join_type == JoinType::Left { "LEFT JOIN" } else { "JOIN" };
            parts.push(format!("{keyword} LATERAL ({}) AS {} ON true", result.query, self.quote(&lateral.alias)?));
        }
        Ok(parts.join(" "))
    }

    fn build_where_clause(&mut self, grouped: &ParsedQuery, join_filter_where: Vec<Condition>) -> Result<String> {
        let mut clauses = Vec::new();
        for filter in &grouped.filters {
            clauses.push(D::compile_filter(self, filter)?);
        }
        let table = if self.core.alias.is_empty() { self.core.table.clone() } else { self.core.alias.clone() };
        for hook in self.core.filter_hooks.clone() {
            let condition = hook.filter(&table)?;
            clauses.push(condition.expression);
            self.add_bindings(condition.bindings);
        }
        for condition in join_filter_where {
            clauses.push(condition.expression);
            self.add_bindings(condition.bindings);
        }
        for sub in self.core.where_in_subqueries.clone() {
            let mut subquery = sub.subquery;
            let result = subquery.build()?;
            let prefix = if sub.not { "NOT IN" } else { "IN" };
            clauses.push(format!("{} {prefix} ({})", self.resolve_and_wrap(&sub.column)?, result.query));
            self.add_bindings(result.bindings);
        }
        for sub in self.core.exists_subqueries.clone() {
            let mut subquery = sub.subquery;
            let result = subquery.build()?;
            let prefix = if sub.not { "NOT EXISTS" } else { "EXISTS" };
            clauses.push(format!("{prefix} ({})", result.query));
            self.add_bindings(result.bindings);
        }
        if grouped.cursor.is_some() && grouped.cursor_direction.is_some() {
            let cursor = Query::cursor_queries(&self.core.pending).first().map(|q| (*q).clone());
            if let Some(cursor) = cursor {
                let sql = self.compile_cursor_query(&cursor)?;
                if !sql.is_empty() {
                    clauses.push(sql);
                }
            }
        }
        for raw in self.core.raw_wheres.clone() {
            clauses.push(raw.expression);
            self.add_bindings(raw.bindings);
        }
        for predicate in self.core.column_predicates.clone() {
            clauses.push(format!(
                "{} {} {}",
                self.resolve_and_wrap(&predicate.left)?,
                predicate.operator,
                self.resolve_and_wrap(&predicate.right)?
            ));
        }
        if clauses.is_empty() {
            return Ok(String::new());
        }
        Ok(format!("WHERE {}", clauses.join(" AND ")))
    }

    fn build_group_by_clause(&mut self, grouped: &ParsedQuery) -> Result<String> {
        const COLUMN: Param<'static> =
            Param::method("Utopia\\Query\\Builder::resolveAndWrap", 1, "attribute", "Builder.php", 776);
        let mut parts = Vec::new();
        for column in &grouped.group_by {
            parts.push(self.resolve_and_wrap(&COLUMN.string(column)?)?);
        }
        let function = time_bucket_function::<D>();
        for bucket in &grouped.time_buckets {
            let interval = Param::method(function, 2, "interval", "Builder.php", 781).string(&bucket.interval)?;
            parts.push(D::compile_group_by_time_bucket(self, &bucket.attribute, &interval)?);
        }
        for raw in self.core.raw_groups.clone() {
            parts.push(raw.expression);
            self.add_bindings(raw.bindings);
        }
        if parts.is_empty() {
            return Ok(String::new());
        }
        Ok(format!("GROUP BY {}", parts.join(", ")))
    }

    fn build_having_clause(&mut self, grouped: &ParsedQuery) -> Result<String> {
        let alias_to_expression = self.build_aggregation_alias_map(grouped)?;
        let mut clauses = Vec::new();
        for having in &grouped.having {
            for value in having.values().values() {
                let sub = query_value(value, "compileFilter")?;
                match alias_to_expression.iter().find(|(alias, _)| alias == sub.attribute()) {
                    Some((_, expression)) => clauses.push(self.compile_having_condition(sub, expression)?),
                    None => clauses.push(D::compile_filter(self, sub)?),
                }
            }
        }
        for raw in self.core.raw_havings.clone() {
            clauses.push(raw.expression);
            self.add_bindings(raw.bindings);
        }
        if clauses.is_empty() {
            return Ok(String::new());
        }
        Ok(format!("HAVING {}", clauses.join(" AND ")))
    }

    fn build_aggregation_alias_map(&mut self, grouped: &ParsedQuery) -> Result<Vec<(String, String)>> {
        let mut map: Vec<(String, String)> = Vec::new();
        for aggregate in &grouped.aggregations {
            let alias = aggregate.alias();
            if alias.is_empty() {
                continue;
            }
            let attribute = aggregate.attribute();
            let column = if attribute == "*" || attribute.is_empty() {
                "*".to_owned()
            } else if is_numeric(&value::string(attribute)) {
                attribute.to_owned()
            } else {
                self.resolve_and_wrap(attribute)?
            };
            let expression = if aggregate.method() == Method::CountDistinct {
                format!("COUNT(DISTINCT {column})")
            } else {
                let function = aggregate.method().sql_function().unwrap_or(aggregate.method().as_str());
                format!("{function}({column})")
            };
            match map.iter_mut().find(|(a, _)| a == alias) {
                Some(entry) => entry.1 = expression,
                None => map.push((alias.to_owned(), expression)),
            }
        }
        Ok(map)
    }

    fn build_window_clause(&mut self) -> Result<String> {
        if self.core.window_definitions.is_empty() {
            return Ok(String::new());
        }
        let mut parts = Vec::new();
        for definition in self.core.window_definitions.clone() {
            let mut over = Vec::new();
            if let Some(partition) = definition.partition_by.as_ref().filter(|p| !p.is_empty()) {
                let columns = partition.iter().map(|c| self.resolve_and_wrap(c)).collect::<Result<Vec<_>>>()?;
                over.push(format!("PARTITION BY {}", columns.join(", ")));
            }
            if let Some(order) = definition.order_by.as_ref().filter(|o| !o.is_empty()) {
                over.push(format!("ORDER BY {}", self.compile_order_by_list(order)?));
            }
            if let Some(frame) = &definition.frame {
                over.push(frame.to_sql());
            }
            parts.push(format!("{} AS ({})", self.quote(&definition.name)?, over.join(" ")));
        }
        Ok(format!("WINDOW {}", parts.join(", ")))
    }

    fn build_order_by_clause(&mut self) -> Result<String> {
        let mut clauses = Vec::new();
        if let Some(vector) = D::compile_vector_order_expression(self)? {
            clauses.push(vector.expression);
            self.add_bindings(vector.bindings);
        }
        for raw in self.core.raw_orders.clone() {
            clauses.push(raw.expression);
            self.add_bindings(raw.bindings);
        }
        let orders: Vec<Query> =
            Query::by_type(&self.core.pending, &[Method::OrderAsc, Method::OrderDesc, Method::OrderRandom])
                .into_iter()
                .cloned()
                .collect();
        for order in &orders {
            clauses.push(self.compile_order_query(order)?);
        }
        if clauses.is_empty() {
            return Ok(String::new());
        }
        Ok(format!("ORDER BY {}", clauses.join(", ")))
    }

    fn build_limit_clause(&mut self, grouped: &ParsedQuery) -> Result<String> {
        let mut parts = Vec::new();
        if let Some(limit) = grouped.limit {
            parts.push("LIMIT ?");
            self.add_binding(Value::Int(limit), None);
        }
        if D::should_emit_offset(self, grouped.offset, grouped.limit)? {
            parts.push("OFFSET ?");
            self.add_binding(grouped.offset.map(Value::Int).unwrap_or(Value::Null), None);
        }
        if let Some(count) = self.core.fetch_count {
            self.add_binding(Value::Int(count), None);
            parts.push(if self.core.fetch_with_ties {
                "FETCH FIRST ? ROWS WITH TIES"
            } else {
                "FETCH FIRST ? ROWS ONLY"
            });
        }
        Ok(parts.join(" "))
    }

    fn build_locking_clause(&mut self) -> Result<String> {
        let Some(mode) = self.core.lock_mode else {
            return Ok(String::new());
        };
        let mut sql = mode.to_sql().to_owned();
        if let Some(table) = &self.core.lock_of_table {
            sql.push_str(" OF ");
            sql.push_str(&self.quote(table)?);
        }
        Ok(sql)
    }

    fn build_union_suffix(&mut self) -> String {
        let mut suffix = String::new();
        for union in self.core.unions.clone() {
            suffix.push(' ');
            suffix.push_str(union.union_type.as_str());
            suffix.push(' ');
            suffix.push_str(&D::wrap_union_member(self, &union.query));
            self.add_bindings(union.bindings);
        }
        suffix
    }

    // ----- writes ---------------------------------------------------------

    /// `INSERT INTO table (columns) VALUES (...), ...` and its bindings.
    pub(crate) fn compile_insert_body(&mut self) -> Result<(String, Vec<Value>)> {
        self.validate_table()?;
        self.validate_rows("insert")?;
        let columns = self.validate_and_get_columns()?;
        let wrapped = columns.iter().map(|c| self.resolve_and_wrap(&key_string(c))).collect::<Result<Vec<_>>>()?;
        let mut bindings = Vec::new();
        let mut rows = Vec::with_capacity(self.core.rows.len());
        for row in &self.core.rows {
            let mut placeholders = Vec::with_capacity(columns.len());
            for column in &columns {
                bindings.push(row_value(row, column));
                match self.core.insert_column_expressions.get(&key_string(column)) {
                    Some(expression) => {
                        placeholders.push(expression.clone());
                        if let Some(extra) = self.core.insert_column_expression_bindings.get(&key_string(column)) {
                            bindings.extend(extra.iter().cloned());
                        }
                    }
                    None => placeholders.push("?".to_owned()),
                }
            }
            rows.push(format!("({})", placeholders.join(", ")));
        }
        let mut table = self.quote(&self.core.table)?;
        if !self.core.insert_alias.is_empty() {
            table.push_str(" AS ");
            table.push_str(&self.quote(&self.core.insert_alias)?);
        }
        Ok((format!("INSERT INTO {table} ({}) VALUES {}", wrapped.join(", "), rows.join(", ")), bindings))
    }

    pub(crate) fn compile_assignments(&mut self) -> Result<Vec<String>> {
        let mut assignments = Vec::new();
        if let Some(row) = self.core.rows.first().cloned() {
            for (column, value) in row.iter() {
                assignments.push(format!("{} = ?", self.resolve_and_wrap(&key_ref_string(column))?));
                self.add_binding(value.clone(), None);
            }
        }
        for (column, expression) in self.core.raw_sets.0.clone() {
            assignments.push(format!("{} = {expression}", self.resolve_and_wrap(&column)?));
            if let Some(bindings) = self.core.raw_set_bindings.get(&column).cloned() {
                self.add_bindings(bindings);
            }
        }
        for (column, case) in self.core.case_sets.0.clone() {
            let wrapped = self.resolve_and_wrap(&column)?;
            assignments.push(format!("{wrapped} = {}", self.compile_case(&case)?));
        }
        Ok(assignments)
    }

    /// The `WHERE` of updates and deletes.
    pub(crate) fn compile_where_clauses(
        &mut self,
        parts: &mut Vec<String>,
        grouped: Option<&ParsedQuery>,
    ) -> Result<()> {
        let owned;
        let grouped = match grouped {
            Some(g) => g,
            None => {
                owned = Query::group_by_type(&self.core.pending);
                &owned
            }
        };
        let mut clauses = Vec::new();
        for filter in &grouped.filters {
            clauses.push(D::compile_filter(self, filter)?);
        }
        let table = if self.core.alias.is_empty() { self.core.table.clone() } else { self.core.alias.clone() };
        for hook in self.core.filter_hooks.clone() {
            let condition = hook.filter(&table)?;
            clauses.push(condition.expression);
            self.add_bindings(condition.bindings);
        }
        for sub in self.core.where_in_subqueries.clone() {
            let mut subquery = sub.subquery;
            let result = subquery.build()?;
            let prefix = if sub.not { "NOT IN" } else { "IN" };
            clauses.push(format!("{} {prefix} ({})", self.resolve_and_wrap(&sub.column)?, result.query));
            self.add_bindings(result.bindings);
        }
        for sub in self.core.exists_subqueries.clone() {
            let mut subquery = sub.subquery;
            let result = subquery.build()?;
            let prefix = if sub.not { "NOT EXISTS" } else { "EXISTS" };
            clauses.push(format!("{prefix} ({})", result.query));
            self.add_bindings(result.bindings);
        }
        for raw in self.core.raw_wheres.clone() {
            clauses.push(raw.expression);
            self.add_bindings(raw.bindings);
        }
        for predicate in self.core.column_predicates.clone() {
            clauses.push(format!(
                "{} {} {}",
                self.resolve_and_wrap(&predicate.left)?,
                predicate.operator,
                self.resolve_and_wrap(&predicate.right)?
            ));
        }
        if !clauses.is_empty() {
            parts.push(format!("WHERE {}", clauses.join(" AND ")));
        }
        Ok(())
    }

    /// The `ORDER BY` and `LIMIT` of updates and deletes.
    pub(crate) fn compile_order_and_limit(
        &mut self,
        parts: &mut Vec<String>,
        grouped: Option<&ParsedQuery>,
    ) -> Result<()> {
        let owned;
        let grouped = match grouped {
            Some(g) => g,
            None => {
                owned = Query::group_by_type(&self.core.pending);
                &owned
            }
        };
        let mut clauses = Vec::new();
        let orders: Vec<Query> =
            Query::by_type(&self.core.pending, &[Method::OrderAsc, Method::OrderDesc, Method::OrderRandom])
                .into_iter()
                .cloned()
                .collect();
        for order in &orders {
            clauses.push(self.compile_order_query(order)?);
        }
        for raw in self.core.raw_orders.clone() {
            clauses.push(raw.expression);
            self.add_bindings(raw.bindings);
        }
        if !clauses.is_empty() {
            parts.push(format!("ORDER BY {}", clauses.join(", ")));
        }
        if let Some(limit) = grouped.limit {
            parts.push("LIMIT ?".to_owned());
            self.add_binding(Value::Int(limit), None);
        }
        Ok(())
    }

    pub(crate) fn base_insert(&mut self) -> Result<Statement> {
        self.core.bindings.clear();
        let (sql, bindings) = self.compile_insert_body()?;
        self.add_bindings(bindings);
        Ok(self.statement(sql))
    }

    pub(crate) fn base_insert_default_values(&mut self) -> Result<Statement> {
        self.core.bindings.clear();
        self.validate_table()?;
        let sql = format!("INSERT INTO {} DEFAULT VALUES", self.quote(&self.core.table)?);
        Ok(self.statement(sql))
    }

    pub(crate) fn base_insert_select(&mut self) -> Result<Statement> {
        self.core.bindings.clear();
        self.validate_table()?;
        let Some(source) = self.core.insert_select_source.clone() else {
            return Err(Error::Validation(
                "No SELECT source specified. Call fromSelect() before insertSelect().".into(),
            ));
        };
        if self.core.insert_select_columns.is_empty() {
            return Err(Error::Validation(
                "No columns specified. Call fromSelect() with columns before insertSelect().".into(),
            ));
        }
        let columns = self.core.insert_select_columns.clone();
        let wrapped = columns.iter().map(|c| self.resolve_and_wrap(c)).collect::<Result<Vec<_>>>()?;
        let mut source = source;
        let result = source.build()?;
        let sql = format!("INSERT INTO {} ({}) {}", self.quote(&self.core.table)?, wrapped.join(", "), result.query);
        self.add_bindings(result.bindings);
        Ok(self.statement(sql))
    }

    pub(crate) fn base_update(&mut self) -> Result<Statement> {
        self.core.bindings.clear();
        self.validate_table()?;
        let assignments = self.compile_assignments()?;
        if assignments.is_empty() {
            return Err(Error::Validation("No assignments for UPDATE. Call set() or setRaw() before update().".into()));
        }
        let grouped = Query::group_by_type(&self.core.pending);
        let mut parts = vec![format!("UPDATE {} SET {}", self.quote(&self.core.table)?, assignments.join(", "))];
        self.compile_where_clauses(&mut parts, Some(&grouped))?;
        self.compile_order_and_limit(&mut parts, Some(&grouped))?;
        Ok(self.statement(parts.join(" ")))
    }

    pub(crate) fn base_delete(&mut self) -> Result<Statement> {
        self.core.bindings.clear();
        self.validate_table()?;
        let grouped = Query::group_by_type(&self.core.pending);
        let mut parts = vec![format!("DELETE FROM {}", self.quote(&self.core.table)?)];
        self.compile_where_clauses(&mut parts, Some(&grouped))?;
        self.compile_order_and_limit(&mut parts, Some(&grouped))?;
        Ok(self.statement(parts.join(" ")))
    }

    pub(crate) fn base_explain(&mut self, analyze: bool) -> Result<Statement> {
        let result = D::build(self)?;
        let prefix = if analyze { "EXPLAIN ANALYZE " } else { "EXPLAIN " };
        Ok(Statement::with_parts(
            format!("{prefix}{}", result.query),
            result.bindings,
            true,
            self.core.executor.clone(),
            None,
        ))
    }

    pub(crate) fn base_add_hook(&mut self, hook: Hook) {
        if let Some(filter) = hook.filter {
            self.core.filter_hooks.push(filter);
        }
        if let Some(attribute) = hook.attribute {
            self.core.attribute_hooks.push(attribute);
        }
        if let Some(join_filter) = hook.join_filter {
            self.core.join_filter_hooks.push(join_filter);
        }
    }

    /// The SQL dialects' `ON CONFLICT` / `ON DUPLICATE KEY UPDATE` clause.
    pub(crate) fn compile_conflict_clause(
        &mut self,
        header: &str,
        assignment: impl Fn(&str) -> String,
    ) -> Result<String> {
        let mut updates = Vec::new();
        for column in self.core.conflict_update_columns.clone() {
            let wrapped = self.resolve_and_wrap(&column)?;
            match self.core.conflict_raw_sets.get(&column).cloned() {
                Some(expression) => {
                    updates.push(format!("{wrapped} = {expression}"));
                    if let Some(bindings) = self.core.conflict_raw_set_bindings.get(&column).cloned() {
                        self.add_bindings(bindings);
                    }
                }
                None => updates.push(format!("{wrapped} = {}", assignment(&wrapped))),
            }
        }
        Ok(format!("{header} {}", updates.join(", ")))
    }

    /// `INSERT ... VALUES ... <conflict clause>` (the shared upsert).
    pub(crate) fn compile_upsert(&mut self, conflict: impl FnOnce(&mut Self) -> Result<String>) -> Result<Statement> {
        self.core.bindings.clear();
        self.validate_table()?;
        self.validate_rows("upsert")?;
        let columns = self.validate_and_get_columns()?;
        if self.core.conflict_keys.is_empty() {
            return Err(Error::Validation("No conflict keys specified. Call onConflict() before upsert().".into()));
        }
        if self.core.conflict_update_columns.is_empty() {
            return Err(Error::Validation(
                "No conflict update columns specified. Call onConflict() with update columns before upsert().".into(),
            ));
        }
        for column in &self.core.conflict_update_columns {
            if !columns.iter().any(|c| matches!(c, ArrayKey::Str(s) if s.as_bytes() == column.as_bytes())) {
                return Err(Error::Validation(format!(
                    "Conflict update column '{column}' is not present in the row data."
                )));
            }
        }
        let wrapped = columns.iter().map(|c| self.resolve_and_wrap(&key_string(c))).collect::<Result<Vec<_>>>()?;
        let mut rows = Vec::new();
        for row in self.core.rows.clone() {
            let mut placeholders = Vec::with_capacity(columns.len());
            for column in &columns {
                self.add_binding(row_value(&row, column), None);
                match self.core.insert_column_expressions.get(&key_string(column)).cloned() {
                    Some(expression) => {
                        placeholders.push(expression);
                        if let Some(extra) =
                            self.core.insert_column_expression_bindings.get(&key_string(column)).cloned()
                        {
                            self.add_bindings(extra);
                        }
                    }
                    None => placeholders.push("?".to_owned()),
                }
            }
            rows.push(format!("({})", placeholders.join(", ")));
        }
        let mut table = self.quote(&self.core.table)?;
        if !self.core.insert_alias.is_empty() {
            table.push_str(" AS ");
            table.push_str(&self.quote(&self.core.insert_alias)?);
        }
        let mut sql = format!("INSERT INTO {table} ({}) VALUES {}", wrapped.join(", "), rows.join(", "));
        sql.push(' ');
        sql.push_str(&conflict(self)?);
        Ok(self.statement(sql))
    }

    /// `INSERT ... SELECT ... <conflict clause>` (the shared upsertSelect).
    pub(crate) fn compile_upsert_select(
        &mut self,
        conflict: impl FnOnce(&mut Self) -> Result<String>,
    ) -> Result<Statement> {
        self.core.bindings.clear();
        self.validate_table()?;
        let Some(source) = self.core.insert_select_source.clone() else {
            return Err(Error::Validation(
                "No SELECT source specified. Call fromSelect() before upsertSelect().".into(),
            ));
        };
        if self.core.insert_select_columns.is_empty() {
            return Err(Error::Validation(
                "No columns specified. Call fromSelect() with columns before upsertSelect().".into(),
            ));
        }
        if self.core.conflict_keys.is_empty() {
            return Err(Error::Validation(
                "No conflict keys specified. Call onConflict() before upsertSelect().".into(),
            ));
        }
        if self.core.conflict_update_columns.is_empty() {
            return Err(Error::Validation(
                "No conflict update columns specified. Call onConflict() with update columns before upsertSelect()."
                    .into(),
            ));
        }
        let columns = self.core.insert_select_columns.clone();
        let wrapped = columns.iter().map(|c| self.resolve_and_wrap(c)).collect::<Result<Vec<_>>>()?;
        let mut source = source;
        let result = source.build()?;
        let mut sql =
            format!("INSERT INTO {} ({}) {}", self.quote(&self.core.table)?, wrapped.join(", "), result.query);
        self.add_bindings(result.bindings);
        sql.push(' ');
        sql.push_str(&conflict(self)?);
        Ok(self.statement(sql))
    }

    /// Appends ` RETURNING columns` (PostgreSQL, MariaDB).
    pub(crate) fn append_returning(&mut self, result: Statement) -> Result<Statement> {
        if self.core.returning.is_empty() {
            return Ok(result);
        }
        let mut columns = Vec::with_capacity(self.core.returning.len());
        for column in self.core.returning.clone() {
            columns.push(if column == "*" { "*".to_owned() } else { self.resolve_and_wrap(&column)? });
        }
        Ok(Statement::with_parts(
            format!("{} RETURNING {}", result.query, columns.join(", ")),
            result.bindings,
            false,
            self.core.executor.clone(),
            None,
        ))
    }

    // ----- compile ----------------------------------------------------------

    pub(crate) fn compile_case(&mut self, case: &CaseExpression) -> Result<String> {
        if case.whens().is_empty() {
            return Err(Error::Validation("CASE expression requires at least one WHEN clause.".into()));
        }
        let mut sql = "CASE".to_owned();
        for when in case.whens() {
            let condition = self.compile_when_condition(when)?;
            sql.push_str(" WHEN ");
            sql.push_str(&condition);
            sql.push_str(" THEN ?");
            self.add_binding(when.then.clone(), None);
        }
        if case.has_else() {
            sql.push_str(" ELSE ?");
            self.add_binding(case.else_value().clone(), None);
        }
        sql.push_str(" END");
        if !case.get_alias().is_empty() {
            sql.push_str(" AS ");
            sql.push_str(&self.quote(case.get_alias())?);
        }
        Ok(sql)
    }

    fn compile_when_condition(&mut self, when: &WhenClause) -> Result<String> {
        match when.kind {
            CaseKind::Comparison => {
                let (Some(column), Some(operator)) = (&when.column, when.operator) else {
                    return Err(Error::Validation("Comparison WHEN clause requires column and operator.".into()));
                };
                self.add_binding(when.value.clone(), None);
                Ok(format!("{} {} ?", self.quote(column)?, operator.sql_operator()))
            }
            CaseKind::Null => {
                let column = when
                    .column
                    .as_ref()
                    .ok_or_else(|| Error::Validation("Null WHEN clause requires column.".into()))?;
                Ok(format!("{} IS NULL", self.quote(column)?))
            }
            CaseKind::NotNull => {
                let column = when
                    .column
                    .as_ref()
                    .ok_or_else(|| Error::Validation("NotNull WHEN clause requires column.".into()))?;
                Ok(format!("{} IS NOT NULL", self.quote(column)?))
            }
            CaseKind::In => {
                let column =
                    when.column.as_ref().ok_or_else(|| Error::Validation("In WHEN clause requires column.".into()))?;
                if when.values.is_empty() {
                    return Err(Error::Validation("In WHEN clause requires at least one value.".into()));
                }
                let placeholders = vec!["?"; when.values.len()].join(", ");
                for value in &when.values {
                    self.add_binding(value.clone(), None);
                }
                Ok(format!("{} IN ({placeholders})", self.quote(column)?))
            }
            CaseKind::Raw => {
                let condition = when
                    .raw_condition
                    .clone()
                    .ok_or_else(|| Error::Validation("Raw WHEN clause requires condition.".into()))?;
                for binding in &when.raw_bindings {
                    self.add_binding(binding.clone(), None);
                }
                Ok(condition)
            }
        }
    }

    /// PHP's base `compileFilter()`.
    pub(crate) fn base_compile_filter(&mut self, query: &Query) -> Result<String> {
        let method = query.method();
        let raw = query.attribute();
        let attribute = self.resolve_and_wrap(raw)?;
        let values = query.values();
        let column = if raw.is_empty() { None } else { Some(raw) };
        use Method::*;
        match method {
            Equal => self.compile_in(&attribute, values, column),
            NotEqual => self.compile_not_in(&attribute, values, column),
            LessThan => Ok(self.compile_comparison(&attribute, "<", values, column)),
            LessThanEqual => Ok(self.compile_comparison(&attribute, "<=", values, column)),
            GreaterThan => Ok(self.compile_comparison(&attribute, ">", values, column)),
            GreaterThanEqual => Ok(self.compile_comparison(&attribute, ">=", values, column)),
            Between => Ok(self.compile_between(&attribute, values, false, column)),
            NotBetween => Ok(self.compile_between(&attribute, values, true, column)),
            StartsWith => D::compile_like(self, &attribute, values, "", "%", false, column),
            NotStartsWith => D::compile_like(self, &attribute, values, "", "%", true, column),
            EndsWith => D::compile_like(self, &attribute, values, "%", "", false, column),
            NotEndsWith => D::compile_like(self, &attribute, values, "%", "", true, column),
            Contains => D::compile_contains(self, &attribute, values, column),
            ContainsAny => {
                if query.on_array() {
                    self.compile_in(&attribute, values, column)
                } else {
                    D::compile_contains(self, &attribute, values, column)
                }
            }
            ContainsAll => D::compile_contains_all(self, &attribute, values, column),
            NotContains => D::compile_not_contains(self, &attribute, values, column),
            Search | NotSearch => Err(Error::Unsupported("Full-text search is not supported by this dialect.".into())),
            Regex => D::compile_regex(self, &attribute, values, column),
            IsNull => Ok(format!("{attribute} IS NULL")),
            IsNotNull => Ok(format!("{attribute} IS NOT NULL")),
            And | Having => self.compile_logical(query, "AND"),
            Or => self.compile_logical(query, "OR"),
            Exists => self.compile_exists(query, false),
            NotExists => self.compile_exists(query, true),
            Raw => Ok(self.compile_raw(query)),
            On => self.compile_on(query),
            other => Err(Error::Unsupported(format!("Unsupported filter type: {}", other.as_str()))),
        }
    }

    fn compile_having_condition(&mut self, query: &Query, expression: &str) -> Result<String> {
        let values = query.values();
        use Method::*;
        match query.method() {
            Equal => self.compile_in(expression, values, None),
            NotEqual => self.compile_not_in(expression, values, None),
            LessThan => Ok(self.compile_comparison(expression, "<", values, None)),
            LessThanEqual => Ok(self.compile_comparison(expression, "<=", values, None)),
            GreaterThan => Ok(self.compile_comparison(expression, ">", values, None)),
            GreaterThanEqual => Ok(self.compile_comparison(expression, ">=", values, None)),
            Between => Ok(self.compile_between(expression, values, false, None)),
            NotBetween => Ok(self.compile_between(expression, values, true, None)),
            IsNull => Ok(format!("{expression} IS NULL")),
            IsNotNull => Ok(format!("{expression} IS NOT NULL")),
            other => Err(Error::Unsupported(format!("Unsupported HAVING condition type: {}", other.as_str()))),
        }
    }

    /// PHP's `compileOrder()`.
    pub(crate) fn compile_order_query(&mut self, query: &Query) -> Result<String> {
        let mut sql = match query.method() {
            Method::OrderAsc => format!("{} ASC", self.resolve_and_wrap(query.attribute())?),
            Method::OrderDesc => format!("{} DESC", self.resolve_and_wrap(query.attribute())?),
            Method::OrderRandom => D::compile_random(self),
            other => return Err(Error::Unsupported(format!("Unsupported order type: {}", other.as_str()))),
        };
        if let Some(Value::Ext(Object::Nulls(nulls))) = query.value() {
            sql.push_str(" NULLS ");
            sql.push_str(nulls.as_str());
        }
        Ok(sql)
    }

    /// PHP's `compileLimit()`.
    pub(crate) fn compile_limit_query(&mut self, query: &Query) -> String {
        self.add_binding(query.value().cloned().unwrap_or(Value::Null), None);
        "LIMIT ?".to_owned()
    }

    /// PHP's `compileOffset()`.
    pub(crate) fn compile_offset_query(&mut self, query: &Query) -> String {
        self.add_binding(query.value().cloned().unwrap_or(Value::Null), None);
        "OFFSET ?".to_owned()
    }

    /// PHP's `compileSelect()`.
    pub(crate) fn compile_select_query(&mut self, query: &Query) -> Result<String> {
        const COLUMN: Param =
            Param::closure("Utopia\\Query\\Builder::{closure:Utopia\\Query\\Builder::compileSelect():1362}", 1, "col");
        let mut columns = Vec::with_capacity(query.values().len());
        for column in query.values().values() {
            columns.push(self.resolve_and_wrap(&COLUMN.string(column)?)?);
        }
        Ok(columns.join(", "))
    }

    /// PHP's `compileCursor()`.
    pub(crate) fn compile_cursor_query(&mut self, query: &Query) -> Result<String> {
        let value = resolve_cursor_value(query.value().cloned().unwrap_or(Value::Null))?;
        self.add_binding(value, None);
        let operator = if query.method() == Method::CursorAfter { ">" } else { "<" };
        Ok(format!("{} {operator} ?", self.quote("_cursor")?))
    }

    /// PHP's `compileAggregate()`.
    pub(crate) fn compile_aggregate_query(&mut self, query: &Query) -> Result<String> {
        let method = query.method();
        let attribute = query.attribute();
        if method == Method::CountDistinct {
            let column = if attribute == "*" || attribute.is_empty() {
                "*".to_owned()
            } else {
                self.resolve_and_wrap(attribute)?
            };
            let mut sql = format!("COUNT(DISTINCT {column})");
            if !query.alias().is_empty() {
                sql.push_str(" AS ");
                sql.push_str(&self.quote(query.alias())?);
            }
            return Ok(sql);
        }
        let function = method
            .sql_function()
            .ok_or_else(|| Error::Validation(format!("Unknown aggregate: {}", method.as_str())))?;
        let column = if attribute == "*" || attribute.is_empty() {
            "*".to_owned()
        } else if is_numeric(&value::string(attribute)) {
            attribute.to_owned()
        } else {
            self.resolve_and_wrap(attribute)?
        };
        let mut sql = format!("{function}({column})");
        if !query.alias().is_empty() {
            sql.push_str(" AS ");
            sql.push_str(&self.quote(query.alias())?);
        }
        Ok(sql)
    }

    /// PHP's `compileGroupBy()`.
    pub(crate) fn compile_group_by_query(&mut self, query: &Query) -> Result<String> {
        let interval_param = Param::method(time_bucket_function::<D>(), 2, "interval", "Builder.php", 1448);
        const COLUMN: Param =
            Param::closure("Utopia\\Query\\Builder::{closure:Utopia\\Query\\Builder::compileGroupBy():1454}", 1, "col");
        if query.method() == Method::GroupByTimeBucket {
            let interval = match query.value() {
                None | Some(Value::Null) => value::string(""),
                Some(v) => v.clone(),
            };
            let interval = interval_param.string(&interval)?;
            return D::compile_group_by_time_bucket(self, query.attribute(), &interval);
        }
        let mut columns = Vec::with_capacity(query.values().len());
        for column in query.values().values() {
            columns.push(self.resolve_and_wrap(&COLUMN.string(column)?)?);
        }
        Ok(columns.join(", "))
    }

    /// PHP's `compileJoin()`.
    pub(crate) fn compile_join_query(&mut self, query: &Query) -> Result<String> {
        let keyword = join_keyword(query.method())?;
        let mut table = self.quote(query.attribute())?;
        if !query.alias().is_empty() {
            table.push_str(" AS ");
            table.push_str(&self.quote(query.alias())?);
        }
        if matches!(query.method(), Method::CrossJoin | Method::NaturalJoin) {
            return Ok(format!("{keyword} {table}"));
        }
        let on = self.compile_join_on(query)?;
        Ok(format!("{keyword} {table} ON {}", on.join(" AND ")))
    }

    fn compile_join_on(&mut self, query: &Query) -> Result<Vec<String>> {
        let on: Vec<Query> = query.join_on_queries().into_iter().cloned().collect();
        if on.is_empty() {
            return Err(Error::Validation("Join ON requires at least one condition".into()));
        }
        let mut parts = Vec::with_capacity(on.len());
        for condition in &on {
            assert_join_on_predicate(condition)?;
            if condition.method() == Method::On {
                parts.push(self.compile_on(condition)?);
            } else {
                parts.push(D::compile_filter(self, condition)?);
            }
        }
        Ok(parts)
    }

    fn compile_on(&mut self, query: &Query) -> Result<String> {
        const COLUMN: Param =
            Param::method("Utopia\\Query\\Builder::resolveAndWrap", 1, "attribute", "Builder.php", 1547);
        let (left, operator, right) = on_values(query);
        if is_empty_string(&left) || is_empty_string(&right) {
            return Err(Error::Validation("Join ON requires left and right columns".into()));
        }
        let operator = match operator {
            Value::Str(s) if ["=", "!=", "<", ">", "<=", ">=", "<>"].iter().any(|o| o.as_bytes() == s.as_bytes()) => {
                String::from_utf8_lossy(s.as_bytes()).into_owned()
            }
            other => return Err(Error::Validation(format!("Invalid join operator: {}", value::concat(&other)?))),
        };
        let left = self.resolve_and_wrap(&COLUMN.string(&left)?)?;
        let right = self.resolve_and_wrap(&COLUMN.string(&right)?)?;
        Ok(format!("{left} {operator} {right}"))
    }

    pub(crate) fn base_compile_join_with_builder(&mut self, query: &Query, join: &JoinBuilder) -> Result<String> {
        let keyword = join_keyword(query.method())?;
        let mut table = self.quote(query.attribute())?;
        if !query.alias().is_empty() {
            table.push_str(" AS ");
            table.push_str(&self.quote(query.alias())?);
        }
        let mut parts = Vec::new();
        for on in join.ons() {
            parts.push(format!(
                "{} {} {}",
                self.resolve_and_wrap(&on.left)?,
                on.operator,
                self.resolve_and_wrap(&on.right)?
            ));
        }
        for condition in join.wheres() {
            parts.push(condition.expression.clone());
            self.add_bindings(condition.bindings.clone());
        }
        if parts.is_empty() {
            return Ok(format!("{keyword} {table}"));
        }
        Ok(format!("{keyword} {table} ON {}", parts.join(" AND ")))
    }

    pub(crate) fn base_compile_like(
        &mut self,
        attribute: &str,
        values: &Array,
        prefix: &str,
        suffix: &str,
        not: bool,
        column: Option<&str>,
    ) -> Result<String> {
        let escaped = escape_like_value(&first(values));
        self.add_binding(value::string(format!("{prefix}{escaped}{suffix}")), column);
        let like = D::like_keyword(self);
        Ok(if not { format!("{attribute} NOT {like} ?") } else { format!("{attribute} {like} ?") })
    }

    pub(crate) fn base_compile_contains(
        &mut self,
        attribute: &str,
        values: &Array,
        column: Option<&str>,
    ) -> Result<String> {
        let like = D::like_keyword(self);
        if values.len() == 1 {
            self.add_binding(value::string(format!("%{}%", escape_like_value(&first(values)))), column);
            return Ok(format!("{attribute} {like} ?"));
        }
        let mut parts = Vec::with_capacity(values.len());
        for v in values.values() {
            self.add_binding(value::string(format!("%{}%", escape_like_value(v))), column);
            parts.push(format!("{attribute} {like} ?"));
        }
        Ok(format!("({})", parts.join(" OR ")))
    }

    pub(crate) fn base_compile_contains_all(
        &mut self,
        attribute: &str,
        values: &Array,
        column: Option<&str>,
    ) -> Result<String> {
        let like = D::like_keyword(self);
        let mut parts = Vec::with_capacity(values.len());
        for v in values.values() {
            self.add_binding(value::string(format!("%{}%", escape_like_value(v))), column);
            parts.push(format!("{attribute} {like} ?"));
        }
        Ok(format!("({})", parts.join(" AND ")))
    }

    pub(crate) fn base_compile_not_contains(
        &mut self,
        attribute: &str,
        values: &Array,
        column: Option<&str>,
    ) -> Result<String> {
        let like = D::like_keyword(self);
        if values.len() == 1 {
            self.add_binding(value::string(format!("%{}%", escape_like_value(&first(values)))), column);
            return Ok(format!("{attribute} NOT {like} ?"));
        }
        let mut parts = Vec::with_capacity(values.len());
        for v in values.values() {
            self.add_binding(value::string(format!("%{}%", escape_like_value(v))), column);
            parts.push(format!("{attribute} NOT {like} ?"));
        }
        Ok(format!("({})", parts.join(" AND ")))
    }

    /// `attribute IN (?, ...)`, with `IS NULL` for null values; `1 = 0` for none.
    pub(crate) fn compile_in(&mut self, attribute: &str, values: &Array, column: Option<&str>) -> Result<String> {
        if values.is_empty() {
            return Ok("1 = 0".into());
        }
        let has_nulls = values.values().any(|v| matches!(v, Value::Null));
        let non_nulls: Vec<Value> = values.values().filter(|v| !matches!(v, Value::Null)).cloned().collect();
        if has_nulls && non_nulls.is_empty() {
            return Ok(format!("{attribute} IS NULL"));
        }
        let placeholders = vec!["?"; non_nulls.len()].join(", ");
        for v in non_nulls {
            self.add_binding(v, column);
        }
        let clause = format!("{attribute} IN ({placeholders})");
        Ok(if has_nulls { format!("({clause} OR {attribute} IS NULL)") } else { clause })
    }

    /// `attribute != ?` / `NOT IN (...)`, with `IS NOT NULL` for null values; `1 = 1` for none.
    pub(crate) fn compile_not_in(&mut self, attribute: &str, values: &Array, column: Option<&str>) -> Result<String> {
        if values.is_empty() {
            return Ok("1 = 1".into());
        }
        let has_nulls = values.values().any(|v| matches!(v, Value::Null));
        let non_nulls: Vec<Value> = values.values().filter(|v| !matches!(v, Value::Null)).cloned().collect();
        if has_nulls && non_nulls.is_empty() {
            return Ok(format!("{attribute} IS NOT NULL"));
        }
        let clause = if non_nulls.len() == 1 {
            self.add_binding(non_nulls[0].clone(), column);
            format!("{attribute} != ?")
        } else {
            let placeholders = vec!["?"; non_nulls.len()].join(", ");
            for v in non_nulls {
                self.add_binding(v, column);
            }
            format!("{attribute} NOT IN ({placeholders})")
        };
        Ok(if has_nulls { format!("({clause} AND {attribute} IS NOT NULL)") } else { clause })
    }

    pub(crate) fn compile_comparison(
        &mut self,
        attribute: &str,
        operator: &str,
        values: &Array,
        column: Option<&str>,
    ) -> String {
        self.add_binding(first(values), column);
        format!("{attribute} {operator} ?")
    }

    fn compile_between(&mut self, attribute: &str, values: &Array, not: bool, column: Option<&str>) -> String {
        self.add_binding(first(values), column);
        self.add_binding(values.get(&ArrayKey::Int(1)).cloned().unwrap_or(Value::Null), column);
        let keyword = if not { "NOT BETWEEN" } else { "BETWEEN" };
        format!("{attribute} {keyword} ? AND ?")
    }

    fn compile_logical(&mut self, query: &Query, operator: &str) -> Result<String> {
        let mut parts = Vec::with_capacity(query.values().len());
        for value in query.values().values() {
            let sub = query_value(value, "compileFilter")?;
            parts.push(D::compile_filter(self, sub)?);
        }
        if parts.is_empty() {
            return Ok(if operator == "OR" { "1 = 0" } else { "1 = 1" }.into());
        }
        Ok(format!("({})", parts.join(&format!(" {operator} "))))
    }

    fn compile_exists(&mut self, query: &Query, not: bool) -> Result<String> {
        let mut parts = Vec::with_capacity(query.values().len());
        let suffix = if not { "IS NULL" } else { "IS NOT NULL" };
        let param = Param::method(
            "Utopia\\Query\\Builder::resolveAndWrap",
            1,
            "attribute",
            "Builder.php",
            if not { 1906 } else { 1891 },
        );
        for attribute in query.values().values() {
            parts.push(format!("{} {suffix}", self.resolve_and_wrap(&param.string(attribute)?)?));
        }
        if parts.is_empty() {
            return Ok("1 = 1".into());
        }
        Ok(format!("({})", parts.join(" AND ")))
    }

    fn compile_raw(&mut self, query: &Query) -> String {
        if query.attribute().is_empty() {
            return "1 = 1".into();
        }
        for binding in query.values().values() {
            self.add_binding(binding.clone(), None);
        }
        query.attribute().to_owned()
    }
}

/// The PHP method compiling a time bucket for dialect `D` (ClickHouse
/// overrides the base one): what PHP's type errors name.
fn time_bucket_function<D: Dialect>() -> &'static str {
    if D::CLASS == "Utopia\\Query\\Builder\\ClickHouse" {
        "Utopia\\Query\\Builder\\ClickHouse::compileGroupByTimeBucket"
    } else {
        "Utopia\\Query\\Builder::compileGroupByTimeBucket"
    }
}

/// The value at index 0 (`$values[0]`, null when absent).
pub(crate) fn first(values: &Array) -> Value {
    values.get(&ArrayKey::Int(0)).cloned().unwrap_or(Value::Null)
}

/// The query of a nested value (PHP's typed parameter rejects anything else).
pub(crate) fn query_value<'a>(value: &'a Value, function: &str) -> Result<&'a Query> {
    match value {
        Value::Ext(Object::Query(q)) => Ok(q),
        other => Err(Error::Type(format!(
            "Utopia\\Query\\Builder::{function}(): Argument #1 ($query) must be of type Utopia\\Query\\Query, {} given",
            value::value_name(other)
        ))),
    }
}

/// `left`, `operator` (default `=`) and `right` of an `on` query, as the
/// query holds them (`$values[0] ?? ''`, `$values[1] ?? '='`, `$values[2] ?? ''`).
pub(crate) fn on_values(query: &Query) -> (Value, Value, Value) {
    let at = |i: i64, default: &str| match query.values().get(&ArrayKey::Int(i)) {
        None | Some(Value::Null) => value::string(default),
        Some(v) => v.clone(),
    };
    (at(0, ""), at(1, "="), at(2, ""))
}

/// `$value === ''`.
pub(crate) fn is_empty_string(value: &Value) -> bool {
    matches!(value, Value::Str(s) if s.is_empty())
}

/// `left`, `operator` (default `=`) and `right` of an `on` query.
pub(crate) fn on_parts(query: &Query) -> (String, String, String) {
    let at = |i: i64, default: &str| {
        query
            .values()
            .get(&ArrayKey::Int(i))
            .filter(|v| !matches!(v, Value::Null))
            .map(value::to_php_string)
            .unwrap_or_else(|| default.to_owned())
    };
    (at(0, ""), at(1, "="), at(2, ""))
}

/// Refuses join conditions other than `on`, filters and `and`/`or` of them.
pub(crate) fn assert_join_on_predicate(query: &Query) -> Result<()> {
    let method = query.method();
    if !method.is_join_condition() {
        return Err(Error::Validation(format!("Unsupported join ON condition: {}", method.as_str())));
    }
    if method != Method::And && method != Method::Or {
        return Ok(());
    }
    for child in query.nested() {
        assert_join_on_predicate(child)?;
    }
    Ok(())
}

/// The SQL keyword of a join method.
pub(crate) fn join_keyword(method: Method) -> Result<&'static str> {
    Ok(match method {
        Method::Join => "JOIN",
        Method::LeftJoin => "LEFT JOIN",
        Method::RightJoin => "RIGHT JOIN",
        Method::CrossJoin => "CROSS JOIN",
        Method::FullOuterJoin => "FULL OUTER JOIN",
        Method::NaturalJoin => "NATURAL JOIN",
        other => return Err(Error::Unsupported(format!("Unsupported join type: {}", other.as_str()))),
    })
}

/// The value a cursor compares: the row's `_cursor` column (a bare value is used as is).
pub(crate) fn resolve_cursor_value(cursor: Value) -> Result<Value> {
    let row = match &cursor {
        Value::Array(a) => Some(a.get(&ArrayKey::from("_cursor")).cloned().ok_or(()).map_err(|_| ())),
        Value::Ext(Object::Row { fields, .. }) => Some(fields.get(&ArrayKey::from("_cursor")).cloned().ok_or(())),
        Value::Object(props) => Some(props.get(&ArrayKey::Str(php_std::Str::from("_cursor"))).cloned().ok_or(())),
        Value::Ext(_) => Some(Err(())),
        _ => None,
    };
    match row {
        None => Ok(cursor),
        Some(Ok(value)) => Ok(value),
        Some(Err(())) => Err(Error::Validation("Cursor row has no _cursor value".into())),
    }
}

/// PHP's `escapeLikeValue()`: the value as a string (arrays as JSON), with
/// `\`, `%` and `_` escaped.
pub(crate) fn escape_like_value(value: &Value) -> String {
    let text = match value {
        Value::Array(_) => value::json_encode(value, Flags::NONE).unwrap_or_default(),
        Value::Int(_) | Value::Float(_) | Value::Bool(_) => value::to_php_string(value),
        Value::Str(s) => String::from_utf8_lossy(s.as_bytes()).into_owned(),
        _ => String::new(),
    };
    let mut out = String::with_capacity(text.len() + 4);
    for c in text.chars() {
        match c {
            '\\' => out.push_str("\\\\"),
            '%' => out.push_str("\\%"),
            '_' => out.push_str("\\_"),
            c => out.push(c),
        }
    }
    out
}

fn key_value(key: ArrayKey) -> Value {
    match key {
        ArrayKey::Int(i) => Value::Int(i),
        ArrayKey::Str(s) => Value::Str(s),
    }
}

/// A column key as the string PHP's string parameters coerce it to.
pub(crate) fn key_string(key: &ArrayKey) -> String {
    match key {
        ArrayKey::Int(i) => i.to_string(),
        ArrayKey::Str(s) => String::from_utf8_lossy(s.as_bytes()).into_owned(),
    }
}

fn key_ref_string(key: php_std::types::KeyRef<'_>) -> String {
    key_string(&key.to_owned())
}

/// `$row[$column] ?? null`.
pub(crate) fn row_value(row: &Array, column: &ArrayKey) -> Value {
    row.get(column).cloned().unwrap_or(Value::Null)
}
