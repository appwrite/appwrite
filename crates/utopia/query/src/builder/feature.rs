//! What only some dialects can do: each feature is a trait a dialect
//! implements, and [`Builder`] has the feature's methods for the dialects
//! that implement it (so `MySql::new().hint(...)` compiles and a ClickHouse
//! builder has `prewhere`, while a MongoDB builder has neither).
//!
//! PHP: the interfaces of `Utopia\Query\Builder\Feature` (and their traits),
//! which the builder classes implement.
//!
//! | PHP interface | Rust trait |
//! |---|---|
//! | `Feature\Locking` | [`Locking`] |
//! | `Feature\Transactions` | [`Transactions`] |
//! | `Feature\StatisticalAggregates` | [`StatisticalAggregates`] |
//! | `Feature\BitwiseAggregates` | [`BitwiseAggregates`] |
//! | `Feature\RawSql` | [`RawSql`] |
//! | `Feature\CrossJoins` | [`CrossJoins`] |
//! | `Feature\FullOuterJoins` | [`FullOuterJoins`] |
//! | `Feature\LateralJoins` | [`LateralJoins`] |
//! | `Feature\Json` | [`Json`] |
//! | `Feature\Hints` | [`Hints`] |
//! | `Feature\ConditionalAggregates` | [`ConditionalAggregates`] |
//! | `Feature\StringAggregates` | [`StringAggregates`] |
//! | `Feature\Rollup`, `Feature\Cube`, `Feature\Totals` | [`Rollup`], [`Cube`], [`Totals`] |
//! | `Feature\Spatial` | [`Spatial`] |
//! | `Feature\FullTextSearch`, `Feature\NegatedFullTextSearch` | [`FullTextSearch`], [`NegatedFullTextSearch`] |
//! | `Feature\Upsert`, `Feature\UpsertSelect`, `Feature\InsertOrIgnore` | [`Upsert`], [`UpsertSelect`], [`InsertOrIgnore`] |
//! | `Feature\TableSampling` | [`TableSampling`] |
//! | `Feature\Sequences` | [`Sequences`] |
//! | `Feature\MariaDB\Returning`, `Feature\PostgreSQL\Returning` | [`Returning`] |

use crate::builder::core::{Builder, Dialect};
use crate::builder::types::{ColumnPredicate, Condition, LockMode, Statement};
use crate::builder::{AnyBuilder, CaseExpression, JoinType};
use crate::error::{Error, Result};
use crate::query::Query;
use crate::value::{Array, IntoValue, IntoValues, Value};

fn values(values: impl IntoValues) -> Vec<Value> {
    values.into_values().values().cloned().collect()
}

/// `FOR UPDATE`, `FOR SHARE` and their `SKIP LOCKED` / `NOWAIT` forms.
pub trait Locking: Dialect {}

/// `BEGIN`, `COMMIT`, `ROLLBACK` and savepoints.
pub trait Transactions: Dialect {}

/// `STDDEV`, `VARIANCE` and their population/sample forms.
pub trait StatisticalAggregates: Dialect {}

/// `BIT_AND`, `BIT_OR`, `BIT_XOR`.
pub trait BitwiseAggregates: Dialect {}

/// Raw SQL in select, where, group, having and order clauses, casts, `CASE`
/// expressions and raw assignments.
pub trait RawSql: Dialect {}

/// `CROSS JOIN` and `NATURAL JOIN`.
pub trait CrossJoins: Dialect {}

/// `FULL OUTER JOIN`.
pub trait FullOuterJoins: Dialect {}

/// `JOIN LATERAL (subquery)`.
pub trait LateralJoins: Dialect {}

/// `GROUP BY ... WITH ROLLUP` / `ROLLUP(...)`.
pub trait Rollup: Dialect {
    /// Sets the dialect's rollup modifier.
    fn with_rollup(b: &mut Builder<Self>);
}

/// `GROUP BY CUBE(...)` / `WITH CUBE`.
pub trait Cube: Dialect {
    /// Sets the dialect's cube modifier.
    fn with_cube(b: &mut Builder<Self>);
}

/// `WITH TOTALS` (ClickHouse).
pub trait Totals: Dialect {
    /// Sets the totals modifier.
    fn with_totals(b: &mut Builder<Self>);
}

/// Optimizer hints.
pub trait Hints: Dialect {
    /// Adds a hint, validated by the dialect.
    fn hint(b: &mut Builder<Self>, hint: &str) -> Result<()>;
}

/// `COUNT(CASE WHEN ... THEN 1 END)` and the other conditional aggregates.
pub trait ConditionalAggregates: Dialect {
    /// The select expression of `aggregate` over `column` (or `1` for counts)
    /// where `condition` holds, with its alias.
    fn aggregate_filter(
        b: &mut Builder<Self>,
        aggregate: &str,
        column: Option<&str>,
        condition: &str,
        alias: &str,
    ) -> Result<String> {
        let then = match column {
            None => "1".to_owned(),
            Some(c) => b.resolve_and_wrap(c)?,
        };
        let mut expression = format!("{aggregate}(CASE WHEN {condition} THEN {then} END)");
        if !alias.is_empty() {
            expression.push_str(" AS ");
            expression.push_str(&b.quote(alias)?);
        }
        Ok(expression)
    }
}

/// `GROUP_CONCAT` / `STRING_AGG`, `JSON_ARRAYAGG`, `JSON_OBJECTAGG`.
pub trait StringAggregates: Dialect {
    /// The concatenation of `column` (wrapped) with an `ORDER BY` fragment.
    fn group_concat_expr(column: &str, order_by: &str) -> String;
    /// The JSON array aggregate of `column`.
    fn json_array_agg_expr(column: &str) -> String;
    /// The JSON object aggregate of key and value columns.
    fn json_object_agg_expr(key: &str, value: &str) -> String;
}

/// JSON filters and JSON column updates.
pub trait Json: Dialect {
    /// Appends `values` to a JSON array column.
    fn set_json_append(b: &mut Builder<Self>, column: &str, values: &Array) -> Result<()>;
    /// Prepends `values` to a JSON array column.
    fn set_json_prepend(b: &mut Builder<Self>, column: &str, values: &Array) -> Result<()>;
    /// Inserts `value` at `index` of a JSON array column.
    fn set_json_insert(b: &mut Builder<Self>, column: &str, index: i64, value: Value) -> Result<()>;
    /// Removes `value` from a JSON array column.
    fn set_json_remove(b: &mut Builder<Self>, column: &str, value: Value) -> Result<()>;
    /// Keeps the elements also in `values`.
    fn set_json_intersect(b: &mut Builder<Self>, column: &str, values: &Array) -> Result<()>;
    /// Removes the elements in `values`.
    fn set_json_diff(b: &mut Builder<Self>, column: &str, values: &Array) -> Result<()>;
    /// Removes duplicate elements.
    fn set_json_unique(b: &mut Builder<Self>, column: &str) -> Result<()>;
    /// Sets the value at a JSON path.
    fn set_json_path(b: &mut Builder<Self>, column: &str, path: &str, value: Value) -> Result<()>;
}

/// Spatial filters.
pub trait Spatial: Dialect {}

/// `filterSearch()`: full-text search.
pub trait FullTextSearch: Dialect {
    /// Adds the search (MongoDB keeps it apart from filters).
    fn filter_search(b: &mut Builder<Self>, attribute: &str, value: &str) -> Result<()> {
        b.core.pending.push(Query::search(attribute, value));
        Ok(())
    }
}

/// `filterNotSearch()`.
pub trait NegatedFullTextSearch: Dialect {}

/// `upsert()`.
pub trait Upsert: Dialect {
    /// Compiles the upsert.
    fn upsert(b: &mut Builder<Self>) -> Result<Statement>;
}

/// `upsertSelect()`.
pub trait UpsertSelect: Dialect {
    /// Compiles the upsert from a select.
    fn upsert_select(b: &mut Builder<Self>) -> Result<Statement>;
}

/// `insertOrIgnore()`.
pub trait InsertOrIgnore: Dialect {
    /// Compiles the insert that skips conflicting rows.
    fn insert_or_ignore(b: &mut Builder<Self>) -> Result<Statement>;
}

/// `TABLESAMPLE` / `SAMPLE`.
pub trait TableSampling: Dialect {
    /// Samples `percent` of the table with `method` (PHP's default `BERNOULLI`).
    fn tablesample(b: &mut Builder<Self>, percent: f64, method: &str) -> Result<()>;
}

/// `nextVal()` and `currVal()` of sequences.
pub trait Sequences: Dialect {
    /// The select expression of `function` (`next` or `current`) on `sequence` (validated).
    fn sequence_expression(b: &Builder<Self>, next: bool, sequence: &str) -> Result<String>;
}

/// `RETURNING` after inserts, updates and deletes.
pub trait Returning: Dialect {}

/// `/^[a-zA-Z_][a-zA-Z0-9_]*$/` (where `$` also matches before a final newline).
pub(crate) fn is_plain_identifier(name: &str) -> bool {
    let bytes = name.strip_suffix('\n').unwrap_or(name).as_bytes();
    match bytes.split_first() {
        Some((first, rest)) => {
            (first.is_ascii_alphabetic() || *first == b'_')
                && rest.iter().all(|b| b.is_ascii_alphanumeric() || *b == b'_')
        }
        None => false,
    }
}

/// `/^[A-Za-z_][A-Za-z0-9_]*(\s+[A-Za-z_][A-Za-z0-9_]*)*(\s*\(\s*[A-Za-z0-9_,\s]+\s*\))?$/`:
/// a type name of words, with an optional parenthesised argument list.
pub(crate) fn is_cast_type(text: &str) -> bool {
    let text = text.strip_suffix('\n').unwrap_or(text).as_bytes();
    let is_space = |b: u8| matches!(b, b' ' | b'\t' | b'\n' | b'\r' | b'\x0B' | b'\x0C');
    let word_start = |b: u8| b.is_ascii_alphabetic() || b == b'_';
    let word = |b: u8| b.is_ascii_alphanumeric() || b == b'_';
    let mut i = 0;
    if !text.first().is_some_and(|&b| word_start(b)) {
        return false;
    }
    while i < text.len() && word(text[i]) {
        i += 1;
    }
    loop {
        // (\s+word)* — a run of spaces followed by a word; otherwise stop.
        let mut j = i;
        while j < text.len() && is_space(text[j]) {
            j += 1;
        }
        if j > i && j < text.len() && word_start(text[j]) {
            i = j;
            while i < text.len() && word(text[i]) {
                i += 1;
            }
        } else {
            break;
        }
    }
    if i == text.len() {
        return true;
    }
    // (\s*\(\s*[A-Za-z0-9_,\s]+\s*\))?$
    while i < text.len() && is_space(text[i]) {
        i += 1;
    }
    if text.get(i) != Some(&b'(') || text.last() != Some(&b')') || i + 1 >= text.len() - 1 {
        return false;
    }
    let inner = &text[i + 1..text.len() - 1];
    inner.iter().all(|&b| word(b) || b == b',' || is_space(b))
}

impl<D: Locking> Builder<D> {
    /// `FOR UPDATE`.
    ///
    /// PHP: `forUpdate()`.
    pub fn for_update(&mut self) -> &mut Self {
        self.core.lock_mode = Some(LockMode::ForUpdate);
        self
    }

    /// `FOR SHARE`.
    ///
    /// PHP: `forShare()`.
    pub fn for_share(&mut self) -> &mut Self {
        self.core.lock_mode = Some(LockMode::ForShare);
        self
    }

    /// `FOR UPDATE SKIP LOCKED`.
    ///
    /// PHP: `forUpdateSkipLocked()`.
    pub fn for_update_skip_locked(&mut self) -> &mut Self {
        self.core.lock_mode = Some(LockMode::ForUpdateSkipLocked);
        self
    }

    /// `FOR UPDATE NOWAIT`.
    ///
    /// PHP: `forUpdateNoWait()`.
    pub fn for_update_no_wait(&mut self) -> &mut Self {
        self.core.lock_mode = Some(LockMode::ForUpdateNoWait);
        self
    }

    /// `FOR SHARE SKIP LOCKED`.
    ///
    /// PHP: `forShareSkipLocked()`.
    pub fn for_share_skip_locked(&mut self) -> &mut Self {
        self.core.lock_mode = Some(LockMode::ForShareSkipLocked);
        self
    }

    /// `FOR SHARE NOWAIT`.
    ///
    /// PHP: `forShareNoWait()`.
    pub fn for_share_no_wait(&mut self) -> &mut Self {
        self.core.lock_mode = Some(LockMode::ForShareNoWait);
        self
    }
}

impl<D: Transactions> Builder<D> {
    /// `BEGIN`.
    ///
    /// PHP: `begin()`.
    pub fn begin(&self) -> Statement {
        Statement::with_parts("BEGIN", Vec::new(), false, self.core.executor.clone(), None)
    }

    /// `COMMIT`.
    ///
    /// PHP: `commit()`.
    pub fn commit(&self) -> Statement {
        Statement::with_parts("COMMIT", Vec::new(), false, self.core.executor.clone(), None)
    }

    /// `ROLLBACK`.
    ///
    /// PHP: `rollback()`.
    pub fn rollback(&self) -> Statement {
        Statement::with_parts("ROLLBACK", Vec::new(), false, self.core.executor.clone(), None)
    }

    /// `SAVEPOINT name`.
    ///
    /// PHP: `savepoint()`.
    pub fn savepoint(&self, name: &str) -> Result<Statement> {
        Ok(Statement::with_parts(
            format!("SAVEPOINT {}", self.quote(name)?),
            Vec::new(),
            false,
            self.core.executor.clone(),
            None,
        ))
    }

    /// `RELEASE SAVEPOINT name`.
    ///
    /// PHP: `releaseSavepoint()`.
    pub fn release_savepoint(&self, name: &str) -> Result<Statement> {
        Ok(Statement::with_parts(
            format!("RELEASE SAVEPOINT {}", self.quote(name)?),
            Vec::new(),
            false,
            self.core.executor.clone(),
            None,
        ))
    }

    /// `ROLLBACK TO SAVEPOINT name`.
    ///
    /// PHP: `rollbackToSavepoint()`.
    pub fn rollback_to_savepoint(&self, name: &str) -> Result<Statement> {
        Ok(Statement::with_parts(
            format!("ROLLBACK TO SAVEPOINT {}", self.quote(name)?),
            Vec::new(),
            false,
            self.core.executor.clone(),
            None,
        ))
    }
}

impl<D: StatisticalAggregates> Builder<D> {
    /// `STDDEV`.
    ///
    /// PHP: `stddev()`.
    pub fn stddev(&mut self, attribute: impl Into<String>, alias: impl Into<String>) -> &mut Self {
        self.core.pending.push(Query::stddev(attribute, alias));
        self
    }

    /// `STDDEV_POP`.
    ///
    /// PHP: `stddevPop()`.
    pub fn stddev_pop(&mut self, attribute: impl Into<String>, alias: impl Into<String>) -> &mut Self {
        self.core.pending.push(Query::stddev_pop(attribute, alias));
        self
    }

    /// `STDDEV_SAMP`.
    ///
    /// PHP: `stddevSamp()`.
    pub fn stddev_samp(&mut self, attribute: impl Into<String>, alias: impl Into<String>) -> &mut Self {
        self.core.pending.push(Query::stddev_samp(attribute, alias));
        self
    }

    /// `VARIANCE`.
    ///
    /// PHP: `variance()`.
    pub fn variance(&mut self, attribute: impl Into<String>, alias: impl Into<String>) -> &mut Self {
        self.core.pending.push(Query::variance(attribute, alias));
        self
    }

    /// `VAR_POP`.
    ///
    /// PHP: `varPop()`.
    pub fn var_pop(&mut self, attribute: impl Into<String>, alias: impl Into<String>) -> &mut Self {
        self.core.pending.push(Query::var_pop(attribute, alias));
        self
    }

    /// `VAR_SAMP`.
    ///
    /// PHP: `varSamp()`.
    pub fn var_samp(&mut self, attribute: impl Into<String>, alias: impl Into<String>) -> &mut Self {
        self.core.pending.push(Query::var_samp(attribute, alias));
        self
    }
}

impl<D: BitwiseAggregates> Builder<D> {
    /// `BIT_AND`.
    ///
    /// PHP: `bitAnd()`.
    pub fn bit_and(&mut self, attribute: impl Into<String>, alias: impl Into<String>) -> &mut Self {
        self.core.pending.push(Query::bit_and(attribute, alias));
        self
    }

    /// `BIT_OR`.
    ///
    /// PHP: `bitOr()`.
    pub fn bit_or(&mut self, attribute: impl Into<String>, alias: impl Into<String>) -> &mut Self {
        self.core.pending.push(Query::bit_or(attribute, alias));
        self
    }

    /// `BIT_XOR`.
    ///
    /// PHP: `bitXor()`.
    pub fn bit_xor(&mut self, attribute: impl Into<String>, alias: impl Into<String>) -> &mut Self {
        self.core.pending.push(Query::bit_xor(attribute, alias));
        self
    }
}

impl<D: RawSql> Builder<D> {
    /// `CAST(column AS type) [AS alias]`; the type must be a type name
    /// (words, with an optional argument list).
    ///
    /// PHP: `selectCast()`.
    pub fn select_cast(&mut self, column: &str, cast_type: &str, alias: &str) -> Result<&mut Self> {
        if !is_cast_type(cast_type) {
            return Err(Error::Validation(format!("Invalid cast type: {cast_type}")));
        }
        let mut expression = format!("CAST({} AS {cast_type})", self.resolve_and_wrap(column)?);
        if !alias.is_empty() {
            expression.push_str(" AS ");
            expression.push_str(&self.quote(alias)?);
        }
        self.core.raw_selects.push(Condition::new(expression, Vec::new()));
        Ok(self)
    }

    /// A raw `ORDER BY` expression.
    ///
    /// PHP: `orderByRaw()`.
    pub fn order_by_raw(&mut self, expression: impl Into<String>, bindings: impl IntoValues) -> &mut Self {
        self.core.raw_orders.push(Condition::new(expression, values(bindings)));
        self
    }

    /// A raw `GROUP BY` expression.
    ///
    /// PHP: `groupByRaw()`.
    pub fn group_by_raw(&mut self, expression: impl Into<String>, bindings: impl IntoValues) -> &mut Self {
        self.core.raw_groups.push(Condition::new(expression, values(bindings)));
        self
    }

    /// A raw `HAVING` condition.
    ///
    /// PHP: `havingRaw()`.
    pub fn having_raw(&mut self, expression: impl Into<String>, bindings: impl IntoValues) -> &mut Self {
        self.core.raw_havings.push(Condition::new(expression, values(bindings)));
        self
    }

    /// A raw `WHERE` condition.
    ///
    /// PHP: `whereRaw()`.
    pub fn where_raw(&mut self, expression: impl Into<String>, bindings: impl IntoValues) -> &mut Self {
        self.core.raw_wheres.push(Condition::new(expression, values(bindings)));
        self
    }

    /// `left operator right`, both columns.
    ///
    /// PHP: `whereColumn()`.
    pub fn where_column(&mut self, left: &str, operator: &str, right: &str) -> Result<&mut Self> {
        if !["=", "!=", "<>", "<", ">", "<=", ">="].contains(&operator) {
            return Err(Error::Validation(format!("Invalid whereColumn operator: {operator}")));
        }
        self.core.column_predicates.push(ColumnPredicate::new(left, operator, right));
        Ok(self)
    }

    /// A `CASE` expression in the select list.
    ///
    /// PHP: `selectCase()`.
    pub fn select_case(&mut self, case: CaseExpression) -> &mut Self {
        self.push_case(case);
        self
    }

    /// `column = CASE ...` in an update.
    ///
    /// PHP: `setCase()`.
    pub fn set_case(&mut self, column: impl Into<String>, case: CaseExpression) -> &mut Self {
        self.core.case_sets.set(column, case);
        self
    }

    /// `column = expression` in an update.
    ///
    /// PHP: `setRaw()`.
    pub fn set_raw(
        &mut self,
        column: impl Into<String>,
        expression: impl Into<String>,
        bindings: impl IntoValues,
    ) -> &mut Self {
        let column = column.into();
        self.core.raw_sets.set(column.clone(), expression.into());
        self.core.raw_set_bindings.set(column, values(bindings));
        self
    }

    /// `column = expression` in an upsert's conflict update.
    ///
    /// PHP: `conflictSetRaw()`.
    pub fn conflict_set_raw(
        &mut self,
        column: impl Into<String>,
        expression: impl Into<String>,
        bindings: impl IntoValues,
    ) -> &mut Self {
        let column = column.into();
        self.core.conflict_raw_sets.set(column.clone(), expression.into());
        self.core.conflict_raw_set_bindings.set(column, values(bindings));
        self
    }

    /// The SQL inserted for `column` instead of `?` (`ST_GeomFromText(?)`),
    /// with extra bindings after the value.
    ///
    /// PHP: `insertColumnExpression()`.
    pub fn insert_column_expression(
        &mut self,
        column: impl Into<String>,
        expression: impl Into<String>,
        extra_bindings: impl IntoValues,
    ) -> &mut Self {
        let column = column.into();
        let extra = values(extra_bindings);
        self.core.insert_column_expressions.set(column.clone(), expression.into());
        if !extra.is_empty() {
            self.core.insert_column_expression_bindings.set(column, extra);
        }
        self
    }
}

impl<D: CrossJoins> Builder<D> {
    /// `CROSS JOIN table [AS alias]`.
    ///
    /// PHP: `crossJoin()`.
    pub fn cross_join(&mut self, table: impl Into<String>, alias: impl Into<String>) -> &mut Self {
        self.core.pending.push(Query::new(crate::Method::CrossJoin, table, Array::default(), alias));
        self
    }

    /// `NATURAL JOIN table [AS alias]`.
    ///
    /// PHP: `naturalJoin()`.
    pub fn natural_join(&mut self, table: impl Into<String>, alias: impl Into<String>) -> &mut Self {
        self.core.pending.push(Query::new(crate::Method::NaturalJoin, table, Array::default(), alias));
        self
    }
}

impl<D: FullOuterJoins> Builder<D> {
    /// `FULL OUTER JOIN table ON left operator right`.
    ///
    /// PHP: `fullOuterJoin()`.
    pub fn full_outer_join(
        &mut self,
        table: impl Into<String>,
        left: &str,
        right: &str,
        operator: &str,
        alias: impl Into<String>,
    ) -> &mut Self {
        let on = Query::on(left, right, operator);
        self.core.pending.push(Query::new(crate::Method::FullOuterJoin, table, crate::value::list([on]), alias));
        self
    }
}

impl<D: LateralJoins> Builder<D> {
    /// `[LEFT] JOIN LATERAL (subquery) AS alias ON true`.
    ///
    /// PHP: `joinLateral()`.
    pub fn join_lateral(
        &mut self,
        subquery: Box<dyn AnyBuilder>,
        alias: impl Into<String>,
        join_type: JoinType,
    ) -> &mut Self {
        self.add_lateral(subquery, alias.into(), join_type);
        self
    }

    /// `LEFT JOIN LATERAL`.
    ///
    /// PHP: `leftJoinLateral()`.
    pub fn left_join_lateral(&mut self, subquery: Box<dyn AnyBuilder>, alias: impl Into<String>) -> &mut Self {
        self.join_lateral(subquery, alias, JoinType::Left)
    }
}

impl<D: Rollup> Builder<D> {
    /// `WITH ROLLUP` / `ROLLUP(...)`.
    ///
    /// PHP: `withRollup()`.
    pub fn with_rollup(&mut self) -> &mut Self {
        D::with_rollup(self);
        self
    }
}

impl<D: Cube> Builder<D> {
    /// `CUBE(...)` / `WITH CUBE`.
    ///
    /// PHP: `withCube()`.
    pub fn with_cube(&mut self) -> &mut Self {
        D::with_cube(self);
        self
    }
}

impl<D: Totals> Builder<D> {
    /// `WITH TOTALS`.
    ///
    /// PHP: `withTotals()`.
    pub fn with_totals(&mut self) -> &mut Self {
        D::with_totals(self);
        self
    }
}

impl<D: Hints> Builder<D> {
    /// An optimizer hint.
    ///
    /// PHP: `hint()`.
    pub fn hint(&mut self, hint: &str) -> Result<&mut Self> {
        D::hint(self, hint)?;
        Ok(self)
    }
}

impl<D: ConditionalAggregates> Builder<D> {
    fn conditional(
        &mut self,
        aggregate: &str,
        column: Option<&str>,
        condition: &str,
        alias: &str,
        bindings: impl IntoValues,
    ) -> Result<&mut Self> {
        let expression = D::aggregate_filter(self, aggregate, column, condition, alias)?;
        Ok(self.select_raw(expression, bindings))
    }

    /// `COUNT(CASE WHEN condition THEN 1 END) [AS alias]`.
    ///
    /// PHP: `countWhen()`.
    pub fn count_when(&mut self, condition: &str, alias: &str, bindings: impl IntoValues) -> Result<&mut Self> {
        self.conditional("COUNT", None, condition, alias, bindings)
    }

    /// `SUM(CASE WHEN condition THEN column END)`.
    ///
    /// PHP: `sumWhen()`.
    pub fn sum_when(
        &mut self,
        column: &str,
        condition: &str,
        alias: &str,
        bindings: impl IntoValues,
    ) -> Result<&mut Self> {
        self.conditional("SUM", Some(column), condition, alias, bindings)
    }

    /// `AVG(CASE WHEN ...)`.
    ///
    /// PHP: `avgWhen()`.
    pub fn avg_when(
        &mut self,
        column: &str,
        condition: &str,
        alias: &str,
        bindings: impl IntoValues,
    ) -> Result<&mut Self> {
        self.conditional("AVG", Some(column), condition, alias, bindings)
    }

    /// `MIN(CASE WHEN ...)`.
    ///
    /// PHP: `minWhen()`.
    pub fn min_when(
        &mut self,
        column: &str,
        condition: &str,
        alias: &str,
        bindings: impl IntoValues,
    ) -> Result<&mut Self> {
        self.conditional("MIN", Some(column), condition, alias, bindings)
    }

    /// `MAX(CASE WHEN ...)`.
    ///
    /// PHP: `maxWhen()`.
    pub fn max_when(
        &mut self,
        column: &str,
        condition: &str,
        alias: &str,
        bindings: impl IntoValues,
    ) -> Result<&mut Self> {
        self.conditional("MAX", Some(column), condition, alias, bindings)
    }
}

impl<D: StringAggregates> Builder<D> {
    /// The values of `column` concatenated with `separator`, optionally
    /// ordered (`-column` descending).
    ///
    /// PHP: `groupConcat()`.
    pub fn group_concat(
        &mut self,
        column: &str,
        separator: &str,
        alias: &str,
        order_by: Option<&[String]>,
    ) -> Result<&mut Self> {
        let order = match order_by {
            Some(columns) if !columns.is_empty() => format!("ORDER BY {}", self.compile_order_by_list(columns)?),
            _ => String::new(),
        };
        let mut expression = D::group_concat_expr(&self.resolve_and_wrap(column)?, &order);
        if !alias.is_empty() {
            expression.push_str(" AS ");
            expression.push_str(&self.quote(alias)?);
        }
        Ok(self.select_raw(expression, [separator]))
    }

    /// A JSON array of `column`'s values.
    ///
    /// PHP: `jsonArrayAgg()`.
    pub fn json_array_agg(&mut self, column: &str, alias: &str) -> Result<&mut Self> {
        let mut expression = D::json_array_agg_expr(&self.resolve_and_wrap(column)?);
        if !alias.is_empty() {
            expression.push_str(" AS ");
            expression.push_str(&self.quote(alias)?);
        }
        Ok(self.select_raw(expression, Array::default()))
    }

    /// A JSON object of key and value columns.
    ///
    /// PHP: `jsonObjectAgg()`.
    pub fn json_object_agg(&mut self, key_column: &str, value_column: &str, alias: &str) -> Result<&mut Self> {
        let key = self.resolve_and_wrap(key_column)?;
        let value = self.resolve_and_wrap(value_column)?;
        let mut expression = D::json_object_agg_expr(&key, &value);
        if !alias.is_empty() {
            expression.push_str(" AS ");
            expression.push_str(&self.quote(alias)?);
        }
        Ok(self.select_raw(expression, Array::default()))
    }
}

impl<D: Json> Builder<D> {
    /// The JSON column contains `value`.
    ///
    /// PHP: `filterJsonContains()`.
    pub fn filter_json_contains(&mut self, attribute: impl Into<String>, value: impl IntoValue) -> &mut Self {
        self.core.pending.push(Query::json_contains(attribute, value));
        self
    }

    /// The JSON column does not contain `value`.
    ///
    /// PHP: `filterJsonNotContains()`.
    pub fn filter_json_not_contains(&mut self, attribute: impl Into<String>, value: impl IntoValue) -> &mut Self {
        self.core.pending.push(Query::json_not_contains(attribute, value));
        self
    }

    /// The JSON array column shares an element with `values`.
    ///
    /// PHP: `filterJsonOverlaps()`.
    pub fn filter_json_overlaps(&mut self, attribute: impl Into<String>, values: impl IntoValues) -> &mut Self {
        self.core.pending.push(Query::json_overlaps(attribute, values.into_values()));
        self
    }

    /// The value at `path` compares to `value`.
    ///
    /// PHP: `filterJsonPath()`.
    pub fn filter_json_path(
        &mut self,
        attribute: impl Into<String>,
        path: &str,
        operator: &str,
        value: impl IntoValue,
    ) -> &mut Self {
        self.core.pending.push(Query::json_path(attribute, path, operator, value));
        self
    }

    /// Appends `values` to the JSON array column (in an update).
    ///
    /// PHP: `setJsonAppend()`.
    pub fn set_json_append(&mut self, column: &str, values: impl IntoValues) -> Result<&mut Self> {
        D::set_json_append(self, column, &values.into_values())?;
        Ok(self)
    }

    /// Prepends `values`.
    ///
    /// PHP: `setJsonPrepend()`.
    pub fn set_json_prepend(&mut self, column: &str, values: impl IntoValues) -> Result<&mut Self> {
        D::set_json_prepend(self, column, &values.into_values())?;
        Ok(self)
    }

    /// Inserts `value` at `index`.
    ///
    /// PHP: `setJsonInsert()`.
    pub fn set_json_insert(&mut self, column: &str, index: i64, value: impl IntoValue) -> Result<&mut Self> {
        D::set_json_insert(self, column, index, value.into_value())?;
        Ok(self)
    }

    /// Removes `value`.
    ///
    /// PHP: `setJsonRemove()`.
    pub fn set_json_remove(&mut self, column: &str, value: impl IntoValue) -> Result<&mut Self> {
        D::set_json_remove(self, column, value.into_value())?;
        Ok(self)
    }

    /// Keeps the elements also in `values`.
    ///
    /// PHP: `setJsonIntersect()`.
    pub fn set_json_intersect(&mut self, column: &str, values: impl IntoValues) -> Result<&mut Self> {
        D::set_json_intersect(self, column, &values.into_values())?;
        Ok(self)
    }

    /// Removes the elements in `values`.
    ///
    /// PHP: `setJsonDiff()`.
    pub fn set_json_diff(&mut self, column: &str, values: impl IntoValues) -> Result<&mut Self> {
        D::set_json_diff(self, column, &values.into_values())?;
        Ok(self)
    }

    /// Removes duplicates.
    ///
    /// PHP: `setJsonUnique()`.
    pub fn set_json_unique(&mut self, column: &str) -> Result<&mut Self> {
        D::set_json_unique(self, column)?;
        Ok(self)
    }

    /// Sets the value at `path`.
    ///
    /// PHP: `setJsonPath()`.
    pub fn set_json_path(&mut self, column: &str, path: &str, value: impl IntoValue) -> Result<&mut Self> {
        D::set_json_path(self, column, path, value.into_value())?;
        Ok(self)
    }
}

impl<D: FullTextSearch> Builder<D> {
    /// Full-text search on `attribute`.
    ///
    /// PHP: `filterSearch()`.
    pub fn filter_search(&mut self, attribute: &str, value: &str) -> Result<&mut Self> {
        D::filter_search(self, attribute, value)?;
        Ok(self)
    }
}

impl<D: NegatedFullTextSearch> Builder<D> {
    /// Rows not matching a full-text search.
    ///
    /// PHP: `filterNotSearch()`.
    pub fn filter_not_search(&mut self, attribute: &str, value: &str) -> &mut Self {
        self.core.pending.push(Query::not_search(attribute, value));
        self
    }
}

impl<D: Upsert> Builder<D> {
    /// Compiles the upsert (insert, updating the conflict columns on conflict).
    ///
    /// PHP: `upsert()`.
    pub fn upsert(&mut self) -> Result<Statement> {
        D::upsert(self)
    }
}

impl<D: UpsertSelect> Builder<D> {
    /// Compiles the upsert from the select source.
    ///
    /// PHP: `upsertSelect()`.
    pub fn upsert_select(&mut self) -> Result<Statement> {
        D::upsert_select(self)
    }
}

impl<D: InsertOrIgnore> Builder<D> {
    /// Compiles an insert that skips rows that conflict.
    ///
    /// PHP: `insertOrIgnore()`.
    pub fn insert_or_ignore(&mut self) -> Result<Statement> {
        D::insert_or_ignore(self)
    }
}

impl<D: TableSampling> Builder<D> {
    /// Samples the table.
    ///
    /// PHP: `tablesample()`.
    pub fn tablesample(&mut self, percent: f64, method: &str) -> Result<&mut Self> {
        D::tablesample(self, percent, method)?;
        Ok(self)
    }
}

impl<D: Sequences> Builder<D> {
    fn sequence(&mut self, next: bool, sequence: &str, alias: &str) -> Result<&mut Self> {
        if !is_plain_identifier(sequence) {
            return Err(Error::Validation(format!("Invalid sequence name: {sequence}")));
        }
        let mut expression = D::sequence_expression(self, next, sequence)?;
        if !alias.is_empty() {
            if !is_plain_identifier(alias) {
                return Err(Error::Validation(format!("Invalid sequence alias: {alias}")));
            }
            expression.push_str(" AS ");
            expression.push_str(&self.quote(alias)?);
        }
        Ok(self.select_raw(expression, Array::default()))
    }

    /// The next value of `sequence`.
    ///
    /// PHP: `nextVal()`.
    pub fn next_val(&mut self, sequence: &str, alias: &str) -> Result<&mut Self> {
        self.sequence(true, sequence, alias)
    }

    /// The current value of `sequence`.
    ///
    /// PHP: `currVal()`.
    pub fn curr_val(&mut self, sequence: &str, alias: &str) -> Result<&mut Self> {
        self.sequence(false, sequence, alias)
    }
}

impl<D: Returning> Builder<D> {
    /// The columns returned after inserts, updates and deletes (`["*"]` for all;
    /// empty to stop returning).
    ///
    /// PHP: `returning()`.
    pub fn returning<S: Into<String>>(&mut self, columns: impl IntoIterator<Item = S>) -> &mut Self {
        self.core.returning = columns.into_iter().map(Into::into).collect();
        self
    }
}

impl<D: Spatial> Builder<D> {
    /// The distance from `point` (`[x, y]`) compares to `distance` with
    /// `operator` (`<`, `>`, `=`, `!=`; anything else is `<`), in meters when
    /// `meters`.
    ///
    /// PHP: `filterDistance()`.
    pub fn filter_distance(
        &mut self,
        attribute: impl Into<String>,
        point: &Array,
        operator: &str,
        distance: f64,
        meters: bool,
    ) -> &mut Self {
        let coordinate = |i: i64| {
            php_std::number::to_string(point.get(&php_std::ArrayKey::Int(i)).map(Value::to_double).unwrap_or(0.0))
        };
        let wkt = format!("POINT({} {})", coordinate(0), coordinate(1));
        let method = match operator {
            ">" => crate::Method::DistanceGreaterThan,
            "=" => crate::Method::DistanceEqual,
            "!=" => crate::Method::DistanceNotEqual,
            _ => crate::Method::DistanceLessThan,
        };
        let tuple = crate::value::list([crate::value::string(wkt), Value::Float(distance), Value::Bool(meters)]);
        self.core.pending.push(Query::new(method, attribute, crate::value::list([Value::Array(tuple)]), ""));
        self
    }

    /// The geometry column intersects `geometry`.
    ///
    /// PHP: `filterIntersects()`.
    pub fn filter_intersects(&mut self, attribute: impl Into<String>, geometry: Array) -> &mut Self {
        self.core.pending.push(Query::intersects(attribute, geometry));
        self
    }

    /// Does not intersect.
    ///
    /// PHP: `filterNotIntersects()`.
    pub fn filter_not_intersects(&mut self, attribute: impl Into<String>, geometry: Array) -> &mut Self {
        self.core.pending.push(Query::not_intersects(attribute, geometry));
        self
    }

    /// Crosses.
    ///
    /// PHP: `filterCrosses()`.
    pub fn filter_crosses(&mut self, attribute: impl Into<String>, geometry: Array) -> &mut Self {
        self.core.pending.push(Query::crosses(attribute, geometry));
        self
    }

    /// Does not cross.
    ///
    /// PHP: `filterNotCrosses()`.
    pub fn filter_not_crosses(&mut self, attribute: impl Into<String>, geometry: Array) -> &mut Self {
        self.core.pending.push(Query::not_crosses(attribute, geometry));
        self
    }

    /// Overlaps.
    ///
    /// PHP: `filterOverlaps()`.
    pub fn filter_overlaps(&mut self, attribute: impl Into<String>, geometry: Array) -> &mut Self {
        self.core.pending.push(Query::overlaps(attribute, geometry));
        self
    }

    /// Does not overlap.
    ///
    /// PHP: `filterNotOverlaps()`.
    pub fn filter_not_overlaps(&mut self, attribute: impl Into<String>, geometry: Array) -> &mut Self {
        self.core.pending.push(Query::not_overlaps(attribute, geometry));
        self
    }

    /// Touches.
    ///
    /// PHP: `filterTouches()`.
    pub fn filter_touches(&mut self, attribute: impl Into<String>, geometry: Array) -> &mut Self {
        self.core.pending.push(Query::touches(attribute, geometry));
        self
    }

    /// Does not touch.
    ///
    /// PHP: `filterNotTouches()`.
    pub fn filter_not_touches(&mut self, attribute: impl Into<String>, geometry: Array) -> &mut Self {
        self.core.pending.push(Query::not_touches(attribute, geometry));
        self
    }

    /// Covers.
    ///
    /// PHP: `filterCovers()`.
    pub fn filter_covers(&mut self, attribute: impl Into<String>, geometry: Array) -> &mut Self {
        self.core.pending.push(Query::covers(attribute, geometry));
        self
    }

    /// Does not cover.
    ///
    /// PHP: `filterNotCovers()`.
    pub fn filter_not_covers(&mut self, attribute: impl Into<String>, geometry: Array) -> &mut Self {
        self.core.pending.push(Query::not_covers(attribute, geometry));
        self
    }

    /// Spatially equal.
    ///
    /// PHP: `filterSpatialEquals()`.
    pub fn filter_spatial_equals(&mut self, attribute: impl Into<String>, geometry: Array) -> &mut Self {
        self.core.pending.push(Query::spatial_equals(attribute, geometry));
        self
    }

    /// Not spatially equal.
    ///
    /// PHP: `filterNotSpatialEquals()`.
    pub fn filter_not_spatial_equals(&mut self, attribute: impl Into<String>, geometry: Array) -> &mut Self {
        self.core.pending.push(Query::not_spatial_equals(attribute, geometry));
        self
    }
}
