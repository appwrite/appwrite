//! The public API every builder has: selects, aggregates, joins, unions,
//! CTEs, inserts, updates, deletes, hooks and window functions.

use std::sync::Arc;

use crate::builder::AnyBuilder;
use crate::builder::case::CaseExpression;
use crate::builder::core::{
    AfterBuild, Builder, Dialect, ExistsSubquery, ExplainOptions, LateralJoin, SubSelect, WhereInSubquery,
};
use crate::builder::join::JoinBuilder;
use crate::builder::types::{
    CteClause, Executor, JoinType, Statement, UnionClause, UnionType, WindowDefinition, WindowFrame, WindowSelect,
};
use crate::enums::NullsPosition;
use crate::error::{Error, Result};
use crate::hook::Hook;
use crate::method::Method;
use crate::query::{Compiler, Query};
use crate::value::{Array, IntoValues, Value};

fn strings<S: Into<String>>(items: impl IntoIterator<Item = S>) -> Vec<String> {
    items.into_iter().map(Into::into).collect()
}

/// `/^[A-Za-z_][A-Za-z0-9_]*\s*\(\s*[A-Za-z0-9_,.\s*"`]*\s*\)$/` on the
/// trimmed function (`$` also matches before a final newline).
fn is_window_function(function: &str) -> bool {
    let text = function.trim_matches([' ', '\t', '\n', '\r', '\0', '\x0B']);
    let text = text.strip_suffix('\n').unwrap_or(text).as_bytes();
    let is_space = |b: u8| matches!(b, b' ' | b'\t' | b'\n' | b'\r' | b'\x0B' | b'\x0C');
    let mut i = 0;
    match text.first() {
        Some(b) if b.is_ascii_alphabetic() || *b == b'_' => i += 1,
        _ => return false,
    }
    while i < text.len() && (text[i].is_ascii_alphanumeric() || text[i] == b'_') {
        i += 1;
    }
    while i < text.len() && is_space(text[i]) {
        i += 1;
    }
    if text.get(i) != Some(&b'(') || text.last() != Some(&b')') || i == text.len() - 1 {
        return false;
    }
    text[i + 1..text.len() - 1]
        .iter()
        .all(|&b| b.is_ascii_alphanumeric() || matches!(b, b'_' | b',' | b'.' | b'*' | b'"' | b'`') || is_space(b))
}

impl<D: Dialect> Builder<D> {
    /// The table (and its alias) to select from, update or delete from; an
    /// empty table makes a select without `FROM`.
    ///
    /// PHP: `from($table, $alias)`.
    pub fn from(&mut self, table: impl Into<String>, alias: impl Into<String>) -> &mut Self {
        self.core.table = table.into();
        self.core.alias = alias.into();
        self.core.from_subquery = None;
        self.core.tableless = self.core.table.is_empty();
        self
    }

    /// A select without `FROM`.
    ///
    /// PHP: `fromNone()`.
    pub fn from_none(&mut self) -> &mut Self {
        self.from("", "")
    }

    /// `FROM (subquery) AS alias`.
    ///
    /// PHP: `fromSub()`.
    pub fn from_sub(&mut self, subquery: impl AnyBuilder + 'static, alias: impl Into<String>) -> &mut Self {
        self.from_sub_boxed(Box::new(subquery), alias)
    }

    /// [`Builder::from_sub`] with a boxed builder.
    pub fn from_sub_boxed(&mut self, subquery: Box<dyn AnyBuilder>, alias: impl Into<String>) -> &mut Self {
        self.core.from_subquery = Some(SubSelect { subquery, alias: alias.into() });
        self.core.table.clear();
        self
    }

    /// `(subquery) AS alias` in the select list.
    ///
    /// PHP: `selectSub()`.
    pub fn select_sub(&mut self, subquery: Box<dyn AnyBuilder>, alias: impl Into<String>) -> &mut Self {
        self.core.sub_selects.push(SubSelect { subquery, alias: alias.into() });
        self
    }

    /// `column IN (subquery)`.
    ///
    /// PHP: `filterWhereIn()`.
    pub fn filter_where_in(&mut self, column: impl Into<String>, subquery: Box<dyn AnyBuilder>) -> &mut Self {
        self.core.where_in_subqueries.push(WhereInSubquery { column: column.into(), subquery, not: false });
        self
    }

    /// `column NOT IN (subquery)`.
    ///
    /// PHP: `filterWhereNotIn()`.
    pub fn filter_where_not_in(&mut self, column: impl Into<String>, subquery: Box<dyn AnyBuilder>) -> &mut Self {
        self.core.where_in_subqueries.push(WhereInSubquery { column: column.into(), subquery, not: true });
        self
    }

    /// `EXISTS (subquery)`.
    ///
    /// PHP: `filterExists()`.
    pub fn filter_exists(&mut self, subquery: Box<dyn AnyBuilder>) -> &mut Self {
        self.core.exists_subqueries.push(ExistsSubquery { subquery, not: false });
        self
    }

    /// `NOT EXISTS (subquery)`.
    ///
    /// PHP: `filterNotExists()`.
    pub fn filter_not_exists(&mut self, subquery: Box<dyn AnyBuilder>) -> &mut Self {
        self.core.exists_subqueries.push(ExistsSubquery { subquery, not: true });
        self
    }

    /// The columns to select.
    ///
    /// PHP: `select(array $columns)`.
    pub fn select<S: Into<String>>(&mut self, columns: impl IntoIterator<Item = S>) -> &mut Self {
        self.core.pending.push(Query::select(columns));
        self
    }

    /// A raw select expression with its bindings.
    ///
    /// PHP: `select(string $expression, $bindings)`, `selectRaw()`.
    pub fn select_raw(&mut self, expression: impl Into<String>, bindings: impl IntoValues) -> &mut Self {
        self.core
            .raw_selects
            .push(crate::builder::Condition::new(expression, bindings.into_values().values().cloned().collect()));
        self
    }

    /// `SELECT DISTINCT`.
    ///
    /// PHP: `distinct()`.
    pub fn distinct(&mut self) -> &mut Self {
        self.core.pending.push(Query::distinct());
        self
    }

    /// Adds queries (filters, or any query).
    ///
    /// PHP: `filter()`.
    pub fn filter(&mut self, queries: impl IntoIterator<Item = Query>) -> &mut Self {
        self.core.pending.extend(queries);
        self
    }

    /// Adds queries of any kind.
    ///
    /// PHP: `queries()`.
    pub fn queries(&mut self, queries: impl IntoIterator<Item = Query>) -> &mut Self {
        self.core.pending.extend(queries);
        self
    }

    /// `ORDER BY attribute ASC` (with `NULLS FIRST`/`LAST`).
    ///
    /// PHP: `sortAsc()`.
    pub fn sort_asc(&mut self, attribute: impl Into<String>, nulls: Option<NullsPosition>) -> &mut Self {
        self.core.pending.push(Query::order_asc(attribute, nulls));
        self
    }

    /// `ORDER BY attribute DESC` (with `NULLS FIRST`/`LAST`).
    ///
    /// PHP: `sortDesc()`.
    pub fn sort_desc(&mut self, attribute: impl Into<String>, nulls: Option<NullsPosition>) -> &mut Self {
        self.core.pending.push(Query::order_desc(attribute, nulls));
        self
    }

    /// Random order.
    ///
    /// PHP: `sortRandom()`.
    pub fn sort_random(&mut self) -> &mut Self {
        self.core.pending.push(Query::order_random());
        self
    }

    /// `LIMIT`.
    ///
    /// PHP: `limit()`.
    pub fn limit(&mut self, value: i64) -> &mut Self {
        self.core.pending.push(Query::limit(value));
        self
    }

    /// `OFFSET`.
    ///
    /// PHP: `offset()`.
    pub fn offset(&mut self, value: i64) -> &mut Self {
        self.core.pending.push(Query::offset(value));
        self
    }

    /// `FETCH FIRST count ROWS ONLY` (or `WITH TIES`).
    ///
    /// PHP: `fetch()`.
    pub fn fetch(&mut self, count: i64, with_ties: bool) -> &mut Self {
        self.core.fetch_count = Some(count);
        self.core.fetch_with_ties = with_ties;
        self
    }

    /// The limit and offset of page `page` (from 1) of `per_page` rows.
    ///
    /// PHP: `page()`.
    pub fn page(&mut self, page: i64, per_page: i64) -> Result<&mut Self> {
        let [limit, offset] = Query::page(page, per_page)?;
        self.core.pending.push(limit);
        self.core.pending.push(offset);
        Ok(self)
    }

    /// Rows after a cursor row (an array with `_cursor`, or a row object).
    ///
    /// PHP: `cursorAfter()`.
    pub fn cursor_after(&mut self, row: Value) -> &mut Self {
        self.core.pending.push(Query::cursor_after(row));
        self
    }

    /// Rows before a cursor row.
    ///
    /// PHP: `cursorBefore()`.
    pub fn cursor_before(&mut self, row: Value) -> &mut Self {
        self.core.pending.push(Query::cursor_before(row));
        self
    }

    /// Runs `callback` with the builder when `condition` holds.
    ///
    /// PHP: `when()`.
    pub fn when(&mut self, condition: bool, callback: impl FnOnce(&mut Self) -> Result<()>) -> Result<&mut Self> {
        if condition {
            callback(self)?;
        }
        Ok(self)
    }

    /// A callback run with the builder at the start of every build.
    ///
    /// PHP: `beforeBuild()`.
    pub fn before_build(&mut self, callback: impl Fn(&mut Self) -> Result<()> + Send + Sync + 'static) -> &mut Self {
        self.before_build.push(Arc::new(callback));
        self
    }

    /// A callback run on every built statement, returning the statement to use.
    ///
    /// PHP: `afterBuild()`.
    pub fn after_build(
        &mut self,
        callback: impl Fn(Statement) -> Result<Statement> + Send + Sync + 'static,
    ) -> &mut Self {
        let callback: AfterBuild = Arc::new(callback);
        self.core.after_build.push(callback);
        self
    }

    /// What runs the statements this builder builds ([`Builder::execute`]).
    ///
    /// PHP: `setExecutor()`.
    pub fn set_executor(&mut self, executor: Executor) -> &mut Self {
        self.core.executor = Some(executor);
        self
    }

    /// `EXPLAIN` (or `EXPLAIN ANALYZE`) of the select.
    ///
    /// PHP: `explain($analyze)`.
    pub fn explain(&mut self, analyze: bool) -> Result<Statement> {
        D::explain(self, analyze, &ExplainOptions::default())
    }

    /// `EXPLAIN` with the dialect's options (format; PostgreSQL's verbose and buffers).
    ///
    /// PHP: `explain($analyze, ...)` of MySQL and PostgreSQL.
    pub fn explain_with(&mut self, analyze: bool, options: &ExplainOptions) -> Result<Statement> {
        D::explain(self, analyze, options)
    }

    /// Builds the select and runs it with the executor.
    ///
    /// PHP: `execute()`.
    pub fn execute(&mut self) -> Result<Value> {
        self.build()?.execute()
    }

    /// The select with its bindings inlined: strings quoted (`'` doubled),
    /// numbers as PHP prints them, booleans as `1`/`0`, anything else `NULL`.
    /// For logs only: never execute it.
    ///
    /// PHP: `toRawSql()`.
    pub fn to_raw_sql(&mut self) -> Result<String> {
        let result = self.build()?;
        let mut sql = result.query.into_bytes();
        let mut offset = 0;
        for binding in &result.bindings {
            let literal: Vec<u8> = match binding {
                Value::Str(s) => {
                    let mut out = Vec::with_capacity(s.len() + 2);
                    out.push(b'\'');
                    for &b in s.as_bytes() {
                        if b == b'\'' {
                            out.push(b'\'');
                        }
                        out.push(b);
                    }
                    out.push(b'\'');
                    out
                }
                Value::Int(_) | Value::Float(_) => crate::value::to_php_string(binding).into_bytes(),
                Value::Bool(b) => {
                    if *b {
                        b"1".to_vec()
                    } else {
                        b"0".to_vec()
                    }
                }
                _ => b"NULL".to_vec(),
            };
            if let Some(pos) =
                sql.get(offset..).and_then(|rest| rest.iter().position(|&b| b == b'?')).map(|p| p + offset)
            {
                sql.splice(pos..pos + 1, literal.iter().copied());
                offset = pos + literal.len();
            }
        }
        Ok(String::from_utf8_lossy(&sql).into_owned())
    }

    /// The bindings of the last statement built.
    ///
    /// PHP: `getBindings()`.
    pub fn bindings(&self) -> Vec<Value> {
        self.binding_values()
    }

    /// Clears everything but hooks and the executor.
    ///
    /// PHP: `reset()`.
    pub fn reset(&mut self) -> &mut Self {
        let core = &mut self.core;
        core.pending.clear();
        core.bindings.clear();
        core.resolved.clear();
        core.table.clear();
        core.alias.clear();
        core.unions.clear();
        core.rows.clear();
        core.raw_sets.clear();
        core.raw_set_bindings.clear();
        core.conflict_keys.clear();
        core.conflict_update_columns.clear();
        core.conflict_raw_sets.clear();
        core.conflict_raw_set_bindings.clear();
        core.insert_column_expressions.clear();
        core.insert_column_expression_bindings.clear();
        core.insert_alias.clear();
        core.lock_mode = None;
        core.lock_of_table = None;
        core.insert_select_source = None;
        core.insert_select_columns.clear();
        core.ctes.clear();
        core.raw_selects.clear();
        core.window_selects.clear();
        core.window_definitions.clear();
        core.sample = None;
        core.cases.clear();
        core.case_sets.clear();
        core.where_in_subqueries.clear();
        core.sub_selects.clear();
        core.from_subquery = None;
        core.tableless = false;
        core.raw_orders.clear();
        core.raw_groups.clear();
        core.raw_havings.clear();
        core.raw_wheres.clear();
        core.column_predicates.clear();
        core.joins.clear();
        core.exists_subqueries.clear();
        core.lateral_joins.clear();
        core.after_build.clear();
        core.fetch_count = None;
        core.fetch_with_ties = false;
        core.qualify = false;
        core.aggregation_aliases.clear();
        self.before_build.clear();
        D::reset(self);
        self
    }

    /// Compiles the select.
    ///
    /// PHP: `build()`.
    pub fn build(&mut self) -> Result<Statement> {
        D::build(self)
    }

    /// `count(attribute)` (`*` counts rows) as `alias`.
    ///
    /// PHP: `count()`.
    pub fn count(&mut self, attribute: impl Into<String>, alias: impl Into<String>) -> &mut Self {
        self.core.pending.push(Query::count(attribute, alias));
        self
    }

    /// `COUNT(DISTINCT attribute)`.
    ///
    /// PHP: `countDistinct()`.
    pub fn count_distinct(&mut self, attribute: impl Into<String>, alias: impl Into<String>) -> &mut Self {
        self.core.pending.push(Query::count_distinct(attribute, alias));
        self
    }

    /// `SUM`.
    ///
    /// PHP: `sum()`.
    pub fn sum(&mut self, attribute: impl Into<String>, alias: impl Into<String>) -> &mut Self {
        self.core.pending.push(Query::sum(attribute, alias));
        self
    }

    /// `AVG`.
    ///
    /// PHP: `avg()`.
    pub fn avg(&mut self, attribute: impl Into<String>, alias: impl Into<String>) -> &mut Self {
        self.core.pending.push(Query::avg(attribute, alias));
        self
    }

    /// `MIN`.
    ///
    /// PHP: `min()`.
    pub fn min(&mut self, attribute: impl Into<String>, alias: impl Into<String>) -> &mut Self {
        self.core.pending.push(Query::min(attribute, alias));
        self
    }

    /// `MAX`.
    ///
    /// PHP: `max()`.
    pub fn max(&mut self, attribute: impl Into<String>, alias: impl Into<String>) -> &mut Self {
        self.core.pending.push(Query::max(attribute, alias));
        self
    }

    /// `GROUP BY`.
    ///
    /// PHP: `groupBy()`.
    pub fn group_by<S: Into<String>>(&mut self, columns: impl IntoIterator<Item = S>) -> &mut Self {
        self.core.pending.push(Query::group_by(columns));
        self
    }

    /// Groups by `attribute` cut into windows of `interval`.
    ///
    /// PHP: `groupByTimeBucket()`.
    pub fn group_by_time_bucket(&mut self, attribute: impl Into<String>, interval: &str) -> Result<&mut Self> {
        self.core.pending.push(Query::group_by_time_bucket(attribute, interval)?);
        Ok(self)
    }

    /// `HAVING` conditions (aggregate aliases resolve to their expressions).
    ///
    /// PHP: `having()`.
    pub fn having(&mut self, queries: impl IntoIterator<Item = Query>) -> &mut Self {
        self.core.pending.push(Query::having(queries));
        self
    }

    fn join_on(
        &mut self,
        method: Method,
        table: String,
        left: &str,
        right: &str,
        operator: &str,
        alias: String,
    ) -> &mut Self {
        let on = Query::on(left, right, operator);
        self.core.pending.push(Query::new(method, table, crate::value::list([on]), alias));
        self
    }

    /// `JOIN table [AS alias] ON left operator right`.
    ///
    /// PHP: `join()`.
    pub fn join(
        &mut self,
        table: impl Into<String>,
        left: &str,
        right: &str,
        operator: &str,
        alias: impl Into<String>,
    ) -> &mut Self {
        self.join_on(Method::Join, table.into(), left, right, operator, alias.into())
    }

    /// `LEFT JOIN`.
    ///
    /// PHP: `leftJoin()`.
    pub fn left_join(
        &mut self,
        table: impl Into<String>,
        left: &str,
        right: &str,
        operator: &str,
        alias: impl Into<String>,
    ) -> &mut Self {
        self.join_on(Method::LeftJoin, table.into(), left, right, operator, alias.into())
    }

    /// `RIGHT JOIN`.
    ///
    /// PHP: `rightJoin()`.
    pub fn right_join(
        &mut self,
        table: impl Into<String>,
        left: &str,
        right: &str,
        operator: &str,
        alias: impl Into<String>,
    ) -> &mut Self {
        self.join_on(Method::RightJoin, table.into(), left, right, operator, alias.into())
    }

    /// A join whose conditions `callback` builds on a [`JoinBuilder`].
    ///
    /// PHP: `joinWhere()`.
    pub fn join_where(
        &mut self,
        table: impl Into<String>,
        callback: impl FnOnce(&mut JoinBuilder) -> Result<()>,
        join_type: JoinType,
        alias: impl Into<String>,
    ) -> Result<&mut Self> {
        let mut join = JoinBuilder::new();
        callback(&mut join)?;
        let method = match join_type {
            JoinType::Left => Method::LeftJoin,
            JoinType::Right => Method::RightJoin,
            JoinType::Cross => Method::CrossJoin,
            JoinType::FullOuter => Method::FullOuterJoin,
            JoinType::Natural => Method::NaturalJoin,
            JoinType::Inner => Method::Join,
        };
        self.core.pending.push(Query::new(method, table, Array::default(), alias));
        let index = self.core.pending.len() - 1;
        self.core.joins.insert(index, join);
        Ok(self)
    }

    fn union_with(&mut self, union_type: UnionType, other: &mut dyn AnyBuilder) -> Result<&mut Self> {
        let result = other.build()?;
        self.core.unions.push(UnionClause::new(union_type, result.query, result.bindings));
        Ok(self)
    }

    /// `UNION` with another builder's select (built now).
    ///
    /// PHP: `union()`.
    pub fn union(&mut self, other: &mut dyn AnyBuilder) -> Result<&mut Self> {
        self.union_with(UnionType::Union, other)
    }

    /// `UNION ALL`.
    ///
    /// PHP: `unionAll()`.
    pub fn union_all(&mut self, other: &mut dyn AnyBuilder) -> Result<&mut Self> {
        self.union_with(UnionType::UnionAll, other)
    }

    /// `INTERSECT`.
    ///
    /// PHP: `intersect()`.
    pub fn intersect(&mut self, other: &mut dyn AnyBuilder) -> Result<&mut Self> {
        self.union_with(UnionType::Intersect, other)
    }

    /// `INTERSECT ALL`.
    ///
    /// PHP: `intersectAll()`.
    pub fn intersect_all(&mut self, other: &mut dyn AnyBuilder) -> Result<&mut Self> {
        self.union_with(UnionType::IntersectAll, other)
    }

    /// `EXCEPT`.
    ///
    /// PHP: `except()`.
    pub fn except(&mut self, other: &mut dyn AnyBuilder) -> Result<&mut Self> {
        self.union_with(UnionType::Except, other)
    }

    /// `EXCEPT ALL`.
    ///
    /// PHP: `exceptAll()`.
    pub fn except_all(&mut self, other: &mut dyn AnyBuilder) -> Result<&mut Self> {
        self.union_with(UnionType::ExceptAll, other)
    }

    /// `WITH name [(columns)] AS (query)` (built now).
    ///
    /// PHP: `with()`.
    pub fn with<S: Into<String>>(
        &mut self,
        name: impl Into<String>,
        query: &mut dyn AnyBuilder,
        columns: impl IntoIterator<Item = S>,
    ) -> Result<&mut Self> {
        let result = query.build()?;
        self.core.ctes.push(CteClause::new(name, result.query, result.bindings, false, strings(columns)));
        Ok(self)
    }

    /// `WITH RECURSIVE name AS (query)`.
    ///
    /// PHP: `withRecursive()`.
    pub fn with_recursive<S: Into<String>>(
        &mut self,
        name: impl Into<String>,
        query: &mut dyn AnyBuilder,
        columns: impl IntoIterator<Item = S>,
    ) -> Result<&mut Self> {
        let result = query.build()?;
        self.core.ctes.push(CteClause::new(name, result.query, result.bindings, true, strings(columns)));
        Ok(self)
    }

    /// `WITH RECURSIVE name AS (seed UNION ALL step)`.
    ///
    /// PHP: `withRecursiveSeedStep()`.
    pub fn with_recursive_seed_step<S: Into<String>>(
        &mut self,
        name: impl Into<String>,
        seed: &mut dyn AnyBuilder,
        step: &mut dyn AnyBuilder,
        columns: impl IntoIterator<Item = S>,
    ) -> Result<&mut Self> {
        let seed = seed.build()?;
        let step = step.build()?;
        let mut bindings = seed.bindings;
        bindings.extend(step.bindings);
        self.core.ctes.push(CteClause::new(
            name,
            format!("{} UNION ALL {}", seed.query, step.query),
            bindings,
            true,
            strings(columns),
        ));
        Ok(self)
    }

    /// The table to insert into.
    ///
    /// PHP: `into()`.
    pub fn into(&mut self, table: impl Into<String>) -> &mut Self {
        self.core.table = table.into();
        self
    }

    /// `INSERT INTO table AS alias`.
    ///
    /// PHP: `insertAs()`.
    pub fn insert_as(&mut self, alias: impl Into<String>) -> &mut Self {
        self.core.insert_alias = alias.into();
        self
    }

    /// A row to insert (column => value), or the values of an update.
    ///
    /// PHP: `set()`.
    pub fn set(&mut self, row: Array) -> Result<&mut Self> {
        D::set(self, row)?;
        Ok(self)
    }

    /// The conflict target and the columns an upsert updates.
    ///
    /// PHP: `onConflict()`.
    pub fn on_conflict<K: Into<String>, U: Into<String>>(
        &mut self,
        keys: impl IntoIterator<Item = K>,
        update_columns: impl IntoIterator<Item = U>,
    ) -> &mut Self {
        self.core.conflict_keys = strings(keys);
        self.core.conflict_update_columns = strings(update_columns);
        self
    }

    /// `INSERT INTO table (columns) SELECT ...` from `source` (built at insert time).
    ///
    /// PHP: `fromSelect()`.
    pub fn from_select<S: Into<String>>(
        &mut self,
        columns: impl IntoIterator<Item = S>,
        source: Box<dyn AnyBuilder>,
    ) -> &mut Self {
        self.core.insert_select_columns = strings(columns);
        self.core.insert_select_source = Some(source);
        self
    }

    /// Compiles the insert.
    ///
    /// PHP: `insert()`.
    pub fn insert(&mut self) -> Result<Statement> {
        D::insert(self)
    }

    /// `INSERT INTO table DEFAULT VALUES` (MySQL: `() VALUES ()`).
    ///
    /// PHP: `insertDefaultValues()`.
    pub fn insert_default_values(&mut self) -> Result<Statement> {
        D::insert_default_values(self)
    }

    /// `INSERT INTO table (columns) SELECT ...`.
    ///
    /// PHP: `insertSelect()`.
    pub fn insert_select(&mut self) -> Result<Statement> {
        self.base_insert_select()
    }

    /// Compiles the update.
    ///
    /// PHP: `update()`.
    pub fn update(&mut self) -> Result<Statement> {
        D::update(self)
    }

    /// Compiles the delete.
    ///
    /// PHP: `delete()`.
    pub fn delete(&mut self) -> Result<Statement> {
        D::delete(self)
    }

    /// Adds a hook: a filter, an attribute resolver, a join filter, or several at once.
    ///
    /// PHP: `addHook()`.
    pub fn add_hook(&mut self, hook: impl Into<Hook>) -> Result<&mut Self> {
        D::add_hook(self, hook.into())?;
        Ok(self)
    }

    /// A window function in the select list: `function OVER (...) AS alias`,
    /// or `OVER window_name`. The function must look like `NAME(args)`.
    ///
    /// PHP: `selectWindow()`.
    pub fn select_window(
        &mut self,
        function: impl Into<String>,
        alias: impl Into<String>,
        partition_by: Option<Vec<String>>,
        order_by: Option<Vec<String>>,
        window_name: Option<String>,
        frame: Option<WindowFrame>,
    ) -> Result<&mut Self> {
        let function = function.into();
        if !is_window_function(&function) {
            return Err(Error::Validation(format!("Invalid window function: {function}")));
        }
        self.core.window_selects.push(WindowSelect::new(function, alias, partition_by, order_by, window_name, frame));
        Ok(self)
    }

    /// A named window: `WINDOW name AS (...)`.
    ///
    /// PHP: `window()`.
    pub fn window(
        &mut self,
        name: impl Into<String>,
        partition_by: Option<Vec<String>>,
        order_by: Option<Vec<String>>,
        frame: Option<WindowFrame>,
    ) -> &mut Self {
        self.core.window_definitions.push(WindowDefinition::new(name, partition_by, order_by, frame));
        self
    }

    /// The queries added so far.
    pub fn pending(&self) -> &[Query] {
        &self.core.pending
    }

    pub(crate) fn push_case(&mut self, case: CaseExpression) {
        self.core.cases.push(case);
    }

    pub(crate) fn add_lateral(&mut self, subquery: Box<dyn AnyBuilder>, alias: String, join_type: JoinType) {
        self.core.lateral_joins.push(LateralJoin { subquery, alias, join_type });
    }
}

impl<D: Dialect> Compiler for Builder<D> {
    fn compile_filter(&mut self, query: &Query) -> Result<String> {
        D::compile_filter(self, query)
    }

    fn compile_order(&mut self, query: &Query) -> Result<String> {
        self.compile_order_query(query)
    }

    fn compile_limit(&mut self, query: &Query) -> Result<String> {
        Ok(self.compile_limit_query(query))
    }

    fn compile_offset(&mut self, query: &Query) -> Result<String> {
        Ok(self.compile_offset_query(query))
    }

    fn compile_select(&mut self, query: &Query) -> Result<String> {
        self.compile_select_query(query)
    }

    fn compile_cursor(&mut self, query: &Query) -> Result<String> {
        self.compile_cursor_query(query)
    }

    fn compile_aggregate(&mut self, query: &Query) -> Result<String> {
        self.compile_aggregate_query(query)
    }

    fn compile_group_by(&mut self, query: &Query) -> Result<String> {
        self.compile_group_by_query(query)
    }

    fn compile_join(&mut self, query: &Query) -> Result<String> {
        self.compile_join_query(query)
    }
}

impl<D: Dialect> AnyBuilder for Builder<D> {
    fn build(&mut self) -> Result<Statement> {
        D::build(self)
    }

    fn clone_box(&self) -> Box<dyn AnyBuilder> {
        Box::new(self.clone())
    }

    fn php_class(&self) -> &'static str {
        D::CLASS
    }

    fn as_any(&self) -> &dyn std::any::Any {
        self
    }
}
