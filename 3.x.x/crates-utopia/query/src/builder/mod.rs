//! PHP `Utopia\Query\Builder` and dialect constructors.

pub mod parsed_query;
pub mod statement;
pub mod types;

pub use parsed_query::{ParsedQuery, TimeBucket};
pub use statement::{ExecuteResult, Executor, Statement};
pub use types::*;

use std::collections::HashMap;
use std::sync::Arc;

use crate::compiler::Compiler;
use crate::enums::NullsPosition;
use crate::error::QueryError;
use crate::hook::{AttributeHook, FilterHook, JoinFilterHook, Placement};
use crate::method::Method;
use crate::query::Query;
use crate::quotes::{quote_identifier, quote_literal};
use crate::schema::column_type::ColumnType;
use crate::value::{IntoValues, QueryValue};

#[derive(Clone)]
pub struct Builder {
    pub kind: DialectKind,
    pub table: String,
    pub alias: String,
    pub pending_queries: Vec<Query>,
    pub bindings: Vec<Binding>,
    pub unions: Vec<UnionClause>,
    pub filter_hooks: Vec<Arc<dyn FilterHook>>,
    pub attribute_hooks: Vec<Arc<dyn AttributeHook>>,
    pub join_filter_hooks: Vec<Arc<dyn JoinFilterHook>>,
    pub resolved_attribute_cache: HashMap<String, String>,
    pub rows: Vec<HashMap<String, QueryValue>>,
    pub raw_sets: HashMap<String, String>,
    pub raw_set_bindings: HashMap<String, Vec<QueryValue>>,
    pub lock_mode: Option<LockMode>,
    pub lock_of_table: Option<String>,
    pub insert_select_source: Option<Box<Builder>>,
    pub insert_select_columns: Vec<String>,
    pub ctes: Vec<CteClause>,
    pub raw_selects: Vec<Condition>,
    pub window_selects: Vec<WindowSelect>,
    pub window_definitions: Vec<WindowDefinition>,
    pub sample: Option<(f64, String)>,
    pub cases: Vec<CaseExpression>,
    pub case_sets: HashMap<String, CaseExpression>,
    pub insert_column_expressions: HashMap<String, String>,
    pub insert_column_expression_bindings: HashMap<String, Vec<QueryValue>>,
    pub insert_alias: String,
    pub where_in_subqueries: Vec<(String, Box<Builder>, bool)>,
    pub sub_selects: Vec<(Box<Builder>, String)>,
    pub from_subquery: Option<(Box<Builder>, String)>,
    pub tableless: bool,
    pub raw_orders: Vec<Condition>,
    pub raw_groups: Vec<Condition>,
    pub raw_havings: Vec<Condition>,
    pub raw_wheres: Vec<Condition>,
    pub column_predicates: Vec<ColumnPredicate>,
    pub joins: HashMap<usize, JoinBuilder>,
    pub exists_subqueries: Vec<(Box<Builder>, bool)>,
    pub lateral_joins: Vec<(Box<Builder>, String, JoinType)>,
    pub qualify: bool,
    pub aggregation_aliases: HashMap<String, bool>,
    pub fetch_count: Option<i64>,
    pub fetch_with_ties: bool,
    pub conflict_keys: Vec<String>,
    pub conflict_update_columns: Vec<String>,
    pub conflict_raw_sets: HashMap<String, String>,
    pub conflict_raw_set_bindings: HashMap<String, Vec<QueryValue>>,
    pub json_sets: HashMap<String, Condition>,
    pub returning_columns: Vec<String>,
    pub hints: Vec<String>,
    pub index_hints: Vec<String>,
    pub prewhere_queries: Vec<Query>,
    pub distinct_on_columns: Vec<String>,
    pub limit_by: Option<(i64, Vec<String>)>,
    pub executor: Option<Executor>,
    pub wrap_override: Option<char>,
}

impl std::fmt::Debug for Builder {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Builder")
            .field("kind", &self.kind)
            .field("table", &self.table)
            .field("alias", &self.alias)
            .finish_non_exhaustive()
    }
}

impl Builder {
    pub fn new(kind: DialectKind) -> Self {
        Self {
            kind,
            table: String::new(),
            alias: String::new(),
            pending_queries: Vec::new(),
            bindings: Vec::new(),
            unions: Vec::new(),
            filter_hooks: Vec::new(),
            attribute_hooks: Vec::new(),
            join_filter_hooks: Vec::new(),
            resolved_attribute_cache: HashMap::new(),
            rows: Vec::new(),
            raw_sets: HashMap::new(),
            raw_set_bindings: HashMap::new(),
            lock_mode: None,
            lock_of_table: None,
            insert_select_source: None,
            insert_select_columns: Vec::new(),
            ctes: Vec::new(),
            raw_selects: Vec::new(),
            window_selects: Vec::new(),
            window_definitions: Vec::new(),
            sample: None,
            cases: Vec::new(),
            case_sets: HashMap::new(),
            insert_column_expressions: HashMap::new(),
            insert_column_expression_bindings: HashMap::new(),
            insert_alias: String::new(),
            where_in_subqueries: Vec::new(),
            sub_selects: Vec::new(),
            from_subquery: None,
            tableless: false,
            raw_orders: Vec::new(),
            raw_groups: Vec::new(),
            raw_havings: Vec::new(),
            raw_wheres: Vec::new(),
            column_predicates: Vec::new(),
            joins: HashMap::new(),
            exists_subqueries: Vec::new(),
            lateral_joins: Vec::new(),
            qualify: false,
            aggregation_aliases: HashMap::new(),
            fetch_count: None,
            fetch_with_ties: false,
            conflict_keys: Vec::new(),
            conflict_update_columns: Vec::new(),
            conflict_raw_sets: HashMap::new(),
            conflict_raw_set_bindings: HashMap::new(),
            json_sets: HashMap::new(),
            returning_columns: Vec::new(),
            hints: Vec::new(),
            index_hints: Vec::new(),
            prewhere_queries: Vec::new(),
            distinct_on_columns: Vec::new(),
            limit_by: None,
            executor: None,
            wrap_override: None,
        }
    }

    pub fn mysql() -> Self {
        Self::new(DialectKind::Mysql)
    }

    pub fn mariadb() -> Self {
        Self::new(DialectKind::Mariadb)
    }

    pub fn postgres() -> Self {
        Self::new(DialectKind::Postgres)
    }

    pub fn sqlite() -> Self {
        Self::new(DialectKind::Sqlite)
    }

    pub fn clickhouse() -> Self {
        Self::new(DialectKind::Clickhouse)
    }

    pub fn mongodb() -> Self {
        Self::new(DialectKind::Mongodb)
    }

    fn wrap_char(&self) -> char {
        self.wrap_override.unwrap_or_else(|| self.kind.wrap_char())
    }

    pub fn quote(&self, identifier: &str) -> Result<String, QueryError> {
        if self.kind == DialectKind::Mongodb {
            return Ok(identifier.to_owned());
        }
        quote_identifier(self.wrap_char(), identifier)
    }

    pub fn quote_literal(&self, identifier: &str) -> Result<String, QueryError> {
        if self.kind == DialectKind::Mongodb {
            return Ok(identifier.to_owned());
        }
        quote_literal(self.wrap_char(), identifier)
    }

    pub fn from(&mut self, table: impl Into<String>, alias: impl Into<String>) -> &mut Self {
        self.table = table.into();
        self.alias = alias.into();
        self.from_subquery = None;
        self.tableless = self.table.is_empty();
        self
    }

    pub fn from_table(&mut self, table: impl Into<String>) -> &mut Self {
        self.from(table, "")
    }

    pub fn from_none(&mut self) -> &mut Self {
        self.from("", "")
    }

    pub fn from_sub(&mut self, subquery: Builder, alias: impl Into<String>) -> &mut Self {
        self.from_subquery = Some((Box::new(subquery), alias.into()));
        self.table = String::new();
        self
    }

    pub fn select(&mut self, columns: impl IntoSelect) -> &mut Self {
        columns.apply(self);
        self
    }

    pub fn distinct(&mut self) -> &mut Self {
        self.pending_queries.push(Query::distinct());
        self
    }

    pub fn filter(&mut self, queries: impl IntoIterator<Item = Query>) -> &mut Self {
        self.pending_queries.extend(queries);
        self
    }

    pub fn queries(&mut self, queries: impl IntoIterator<Item = Query>) -> &mut Self {
        self.pending_queries.extend(queries);
        self
    }

    pub fn sort_asc(
        &mut self,
        attribute: impl Into<String>,
        nulls: Option<NullsPosition>,
    ) -> &mut Self {
        self.pending_queries
            .push(Query::order_asc(attribute, nulls));
        self
    }

    pub fn sort_desc(
        &mut self,
        attribute: impl Into<String>,
        nulls: Option<NullsPosition>,
    ) -> &mut Self {
        self.pending_queries
            .push(Query::order_desc(attribute, nulls));
        self
    }

    pub fn sort_random(&mut self) -> &mut Self {
        self.pending_queries.push(Query::order_random());
        self
    }

    pub fn limit(&mut self, value: i64) -> &mut Self {
        self.pending_queries.push(Query::limit(value));
        self
    }

    pub fn offset(&mut self, value: i64) -> &mut Self {
        self.pending_queries.push(Query::offset(value));
        self
    }

    pub fn fetch(&mut self, count: i64, with_ties: bool) -> &mut Self {
        self.fetch_count = Some(count);
        self.fetch_with_ties = with_ties;
        self
    }

    pub fn page(&mut self, page: i64, per_page: i64) -> Result<&mut Self, QueryError> {
        if page < 1 {
            return Err(QueryError::validation(format!(
                "Page must be >= 1, got {page}"
            )));
        }
        if per_page < 1 {
            return Err(QueryError::validation(format!(
                "Per page must be >= 1, got {per_page}"
            )));
        }
        self.pending_queries.push(Query::limit(per_page));
        self.pending_queries
            .push(Query::offset((page - 1) * per_page));
        Ok(self)
    }

    pub fn cursor_after(&mut self, value: impl Into<QueryValue>) -> &mut Self {
        self.pending_queries.push(Query::cursor_after(value));
        self
    }

    pub fn cursor_before(&mut self, value: impl Into<QueryValue>) -> &mut Self {
        self.pending_queries.push(Query::cursor_before(value));
        self
    }

    pub fn when(&mut self, condition: bool, callback: impl FnOnce(&mut Self)) -> &mut Self {
        if condition {
            callback(self);
        }
        self
    }

    pub fn count(&mut self, attribute: impl Into<String>, alias: impl Into<String>) -> &mut Self {
        self.pending_queries.push(Query::count(attribute, alias));
        self
    }

    pub fn count_distinct(
        &mut self,
        attribute: impl Into<String>,
        alias: impl Into<String>,
    ) -> &mut Self {
        self.pending_queries
            .push(Query::count_distinct(attribute, alias));
        self
    }

    pub fn sum(&mut self, attribute: impl Into<String>, alias: impl Into<String>) -> &mut Self {
        self.pending_queries.push(Query::sum(attribute, alias));
        self
    }

    pub fn avg(&mut self, attribute: impl Into<String>, alias: impl Into<String>) -> &mut Self {
        self.pending_queries.push(Query::avg(attribute, alias));
        self
    }

    pub fn min(&mut self, attribute: impl Into<String>, alias: impl Into<String>) -> &mut Self {
        self.pending_queries.push(Query::min(attribute, alias));
        self
    }

    pub fn max(&mut self, attribute: impl Into<String>, alias: impl Into<String>) -> &mut Self {
        self.pending_queries.push(Query::max(attribute, alias));
        self
    }

    pub fn group_by(&mut self, columns: impl IntoValues) -> &mut Self {
        self.pending_queries.push(Query::group_by(columns));
        self
    }

    pub fn group_by_time_bucket(
        &mut self,
        attribute: impl Into<String>,
        interval: impl AsRef<str>,
    ) -> Result<&mut Self, QueryError> {
        self.pending_queries
            .push(Query::group_by_time_bucket(attribute, interval)?);
        Ok(self)
    }

    pub fn having(&mut self, queries: impl IntoIterator<Item = Query>) -> &mut Self {
        self.pending_queries.push(Query::having(queries));
        self
    }

    pub fn join(
        &mut self,
        table: impl Into<String>,
        left: impl Into<String>,
        right: impl Into<String>,
        operator: impl Into<String>,
        alias: impl Into<String>,
    ) -> &mut Self {
        self.pending_queries
            .push(Query::join(table, left, right, operator, alias));
        self
    }

    pub fn left_join(
        &mut self,
        table: impl Into<String>,
        left: impl Into<String>,
        right: impl Into<String>,
        operator: impl Into<String>,
        alias: impl Into<String>,
    ) -> &mut Self {
        self.pending_queries
            .push(Query::left_join(table, left, right, operator, alias));
        self
    }

    pub fn right_join(
        &mut self,
        table: impl Into<String>,
        left: impl Into<String>,
        right: impl Into<String>,
        operator: impl Into<String>,
        alias: impl Into<String>,
    ) -> &mut Self {
        self.pending_queries
            .push(Query::right_join(table, left, right, operator, alias));
        self
    }

    pub fn cross_join(&mut self, table: impl Into<String>, alias: impl Into<String>) -> &mut Self {
        self.pending_queries.push(Query::cross_join(table, alias));
        self
    }

    pub fn full_outer_join(
        &mut self,
        table: impl Into<String>,
        left: impl Into<String>,
        right: impl Into<String>,
        operator: impl Into<String>,
        alias: impl Into<String>,
    ) -> &mut Self {
        self.pending_queries
            .push(Query::full_outer_join(table, left, right, operator, alias));
        self
    }

    pub fn natural_join(
        &mut self,
        table: impl Into<String>,
        alias: impl Into<String>,
    ) -> &mut Self {
        self.pending_queries.push(Query::natural_join(table, alias));
        self
    }

    pub fn select_sub(&mut self, subquery: Builder, alias: impl Into<String>) -> &mut Self {
        self.sub_selects.push((Box::new(subquery), alias.into()));
        self
    }

    pub fn filter_where_in(&mut self, column: impl Into<String>, subquery: Builder) -> &mut Self {
        self.where_in_subqueries
            .push((column.into(), Box::new(subquery), false));
        self
    }

    pub fn filter_where_not_in(
        &mut self,
        column: impl Into<String>,
        subquery: Builder,
    ) -> &mut Self {
        self.where_in_subqueries
            .push((column.into(), Box::new(subquery), true));
        self
    }

    pub fn filter_exists(&mut self, subquery: Builder) -> &mut Self {
        self.exists_subqueries.push((Box::new(subquery), false));
        self
    }

    pub fn filter_not_exists(&mut self, subquery: Builder) -> &mut Self {
        self.exists_subqueries.push((Box::new(subquery), true));
        self
    }

    pub fn with(
        &mut self,
        name: impl Into<String>,
        mut query: Builder,
        columns: Vec<String>,
    ) -> Result<&mut Self, QueryError> {
        let result = query.build()?;
        self.ctes.push(CteClause {
            name: name.into(),
            query: result.query,
            bindings: result.bindings,
            recursive: false,
            columns,
        });
        Ok(self)
    }

    pub fn with_recursive(
        &mut self,
        name: impl Into<String>,
        mut query: Builder,
        columns: Vec<String>,
    ) -> Result<&mut Self, QueryError> {
        let result = query.build()?;
        self.ctes.push(CteClause {
            name: name.into(),
            query: result.query,
            bindings: result.bindings,
            recursive: true,
            columns,
        });
        Ok(self)
    }

    pub fn with_recursive_seed_step(
        &mut self,
        name: impl Into<String>,
        mut seed: Builder,
        mut step: Builder,
        columns: Vec<String>,
    ) -> Result<&mut Self, QueryError> {
        let seed_result = seed.build()?;
        let step_result = step.build()?;
        let query = format!("{} UNION ALL {}", seed_result.query, step_result.query);
        let mut bindings = seed_result.bindings;
        bindings.extend(step_result.bindings);
        self.ctes.push(CteClause {
            name: name.into(),
            query,
            bindings,
            recursive: true,
            columns,
        });
        Ok(self)
    }

    pub fn union(&mut self, mut other: Builder) -> Result<&mut Self, QueryError> {
        self.push_union(UnionType::Union, &mut other)
    }

    pub fn union_all(&mut self, mut other: Builder) -> Result<&mut Self, QueryError> {
        self.push_union(UnionType::UnionAll, &mut other)
    }

    pub fn intersect(&mut self, mut other: Builder) -> Result<&mut Self, QueryError> {
        self.push_union(UnionType::Intersect, &mut other)
    }

    pub fn intersect_all(&mut self, mut other: Builder) -> Result<&mut Self, QueryError> {
        self.push_union(UnionType::IntersectAll, &mut other)
    }

    pub fn except(&mut self, mut other: Builder) -> Result<&mut Self, QueryError> {
        self.push_union(UnionType::Except, &mut other)
    }

    pub fn except_all(&mut self, mut other: Builder) -> Result<&mut Self, QueryError> {
        self.push_union(UnionType::ExceptAll, &mut other)
    }

    fn push_union(
        &mut self,
        union_type: UnionType,
        other: &mut Builder,
    ) -> Result<&mut Self, QueryError> {
        let result = other.build()?;
        self.unions.push(UnionClause {
            union_type,
            query: result.query,
            bindings: result.bindings,
        });
        Ok(self)
    }

    pub fn prewhere(&mut self, queries: impl IntoIterator<Item = Query>) -> &mut Self {
        self.prewhere_queries.extend(queries);
        self
    }

    pub fn sample(&mut self, percent: f64, method: impl Into<String>) -> &mut Self {
        self.sample = Some((percent, method.into()));
        self
    }

    pub fn select_raw(
        &mut self,
        expression: impl Into<String>,
        bindings: Vec<QueryValue>,
    ) -> &mut Self {
        self.raw_selects.push(Condition::new(expression, bindings));
        self
    }

    pub fn order_by_raw(
        &mut self,
        expression: impl Into<String>,
        bindings: Vec<QueryValue>,
    ) -> &mut Self {
        self.raw_orders.push(Condition::new(expression, bindings));
        self
    }

    pub fn group_by_raw(
        &mut self,
        expression: impl Into<String>,
        bindings: Vec<QueryValue>,
    ) -> &mut Self {
        self.raw_groups.push(Condition::new(expression, bindings));
        self
    }

    pub fn having_raw(
        &mut self,
        expression: impl Into<String>,
        bindings: Vec<QueryValue>,
    ) -> &mut Self {
        self.raw_havings.push(Condition::new(expression, bindings));
        self
    }

    pub fn where_raw(
        &mut self,
        expression: impl Into<String>,
        bindings: Vec<QueryValue>,
    ) -> &mut Self {
        self.raw_wheres.push(Condition::new(expression, bindings));
        self
    }

    pub fn where_column(
        &mut self,
        left: impl Into<String>,
        operator: impl Into<String>,
        right: impl Into<String>,
    ) -> Result<&mut Self, QueryError> {
        let operator = operator.into();
        if !["=", "!=", "<>", "<", ">", "<=", ">="].contains(&operator.as_str()) {
            return Err(QueryError::validation(format!(
                "Invalid whereColumn operator: {operator}"
            )));
        }
        self.column_predicates.push(ColumnPredicate {
            left: left.into(),
            operator,
            right: right.into(),
        });
        Ok(self)
    }

    pub fn select_case(&mut self, case: CaseExpression) -> &mut Self {
        self.cases.push(case);
        self
    }

    pub fn set_case(&mut self, column: impl Into<String>, case: CaseExpression) -> &mut Self {
        self.case_sets.insert(column.into(), case);
        self
    }

    pub fn set_raw(
        &mut self,
        column: impl Into<String>,
        expression: impl Into<String>,
        bindings: Vec<QueryValue>,
    ) -> &mut Self {
        let column = column.into();
        self.raw_sets.insert(column.clone(), expression.into());
        self.raw_set_bindings.insert(column, bindings);
        self
    }

    pub fn conflict_set_raw(
        &mut self,
        column: impl Into<String>,
        expression: impl Into<String>,
        bindings: Vec<QueryValue>,
    ) -> &mut Self {
        let column = column.into();
        self.conflict_raw_sets
            .insert(column.clone(), expression.into());
        self.conflict_raw_set_bindings.insert(column, bindings);
        self
    }

    pub fn insert_column_expression(
        &mut self,
        column: impl Into<String>,
        expression: impl Into<String>,
        extra_bindings: Vec<QueryValue>,
    ) -> &mut Self {
        let column = column.into();
        self.insert_column_expressions
            .insert(column.clone(), expression.into());
        if !extra_bindings.is_empty() {
            self.insert_column_expression_bindings
                .insert(column, extra_bindings);
        }
        self
    }

    pub fn hint(&mut self, hint: impl Into<String>) -> Result<&mut Self, QueryError> {
        let hint = hint.into();
        let re = regex::Regex::new(r"^[A-Za-z0-9_()=, `.]+$").expect("static");
        if !re.is_match(&hint) {
            return Err(QueryError::validation(format!("Invalid hint: {hint}")));
        }
        self.hints.push(hint);
        Ok(self)
    }

    pub fn select_cast(
        &mut self,
        column: impl Into<String>,
        type_name: impl Into<String>,
        alias: impl Into<String>,
    ) -> Result<&mut Self, QueryError> {
        let type_name = type_name.into();
        let re = regex::Regex::new(
            r"^[A-Za-z_][A-Za-z0-9_]*(\s+[A-Za-z_][A-Za-z0-9_]*)*(\s*\(\s*[A-Za-z0-9_,\s]+\s*\))?$",
        )
        .expect("static");
        if !re.is_match(&type_name) {
            return Err(QueryError::validation(format!(
                "Invalid cast type: {type_name}"
            )));
        }
        let mut expr = format!(
            "CAST({} AS {type_name})",
            self.resolve_and_wrap(&column.into())?
        );
        let alias = alias.into();
        if !alias.is_empty() {
            expr.push_str(" AS ");
            expr.push_str(&self.quote(&alias)?);
        }
        self.raw_selects.push(Condition::expr(expr));
        Ok(self)
    }

    pub fn into_table(&mut self, table: impl Into<String>) -> &mut Self {
        self.table = table.into();
        self
    }

    pub fn set(&mut self, row: HashMap<String, QueryValue>) -> &mut Self {
        self.rows.push(row);
        self
    }

    pub fn set_pairs<I, K, V>(&mut self, pairs: I) -> &mut Self
    where
        I: IntoIterator<Item = (K, V)>,
        K: Into<String>,
        V: Into<QueryValue>,
    {
        let row = pairs
            .into_iter()
            .map(|(k, v)| (k.into(), v.into()))
            .collect();
        self.rows.push(row);
        self
    }

    pub fn on_conflict(&mut self, keys: Vec<String>, update_columns: Vec<String>) -> &mut Self {
        self.conflict_keys = keys;
        self.conflict_update_columns = update_columns;
        self
    }

    pub fn add_hook_filter(&mut self, hook: Arc<dyn FilterHook>) -> &mut Self {
        self.filter_hooks.push(hook);
        self
    }

    pub fn add_hook_attribute(&mut self, hook: Arc<dyn AttributeHook>) -> &mut Self {
        self.attribute_hooks.push(hook);
        self
    }

    pub fn add_hook_join_filter(&mut self, hook: Arc<dyn JoinFilterHook>) -> &mut Self {
        self.join_filter_hooks.push(hook);
        self
    }

    pub fn get_bindings(&self) -> Vec<QueryValue> {
        self.get_binding_values()
    }

    pub fn get_binding_values(&self) -> Vec<QueryValue> {
        self.bindings.iter().map(|b| b.value.clone()).collect()
    }

    fn add_binding(&mut self, value: impl Into<QueryValue>, column: Option<&str>) {
        self.bindings
            .push(Binding::new(value, column.map(ToOwned::to_owned)));
    }

    fn add_bindings(&mut self, values: impl IntoIterator<Item = QueryValue>) {
        for value in values {
            self.bindings.push(Binding::new(value, None));
        }
    }

    pub fn reset(&mut self) -> &mut Self {
        self.pending_queries.clear();
        self.bindings.clear();
        self.resolved_attribute_cache.clear();
        self.table.clear();
        self.alias.clear();
        self.unions.clear();
        self.rows.clear();
        self.raw_sets.clear();
        self.raw_set_bindings.clear();
        self.conflict_keys.clear();
        self.conflict_update_columns.clear();
        self.conflict_raw_sets.clear();
        self.conflict_raw_set_bindings.clear();
        self.insert_column_expressions.clear();
        self.insert_column_expression_bindings.clear();
        self.insert_alias.clear();
        self.lock_mode = None;
        self.lock_of_table = None;
        self.insert_select_source = None;
        self.insert_select_columns.clear();
        self.ctes.clear();
        self.raw_selects.clear();
        self.window_selects.clear();
        self.window_definitions.clear();
        self.sample = None;
        self.cases.clear();
        self.case_sets.clear();
        self.where_in_subqueries.clear();
        self.sub_selects.clear();
        self.from_subquery = None;
        self.tableless = false;
        self.raw_orders.clear();
        self.raw_groups.clear();
        self.raw_havings.clear();
        self.raw_wheres.clear();
        self.column_predicates.clear();
        self.joins.clear();
        self.exists_subqueries.clear();
        self.lateral_joins.clear();
        self.fetch_count = None;
        self.fetch_with_ties = false;
        self.qualify = false;
        self.aggregation_aliases.clear();
        self.json_sets.clear();
        self.returning_columns.clear();
        self.hints.clear();
        self.index_hints.clear();
        self.prewhere_queries.clear();
        self.distinct_on_columns.clear();
        self.limit_by = None;
        self
    }

    pub fn clone_builder(&self) -> Self {
        self.clone()
    }

    pub fn build(&mut self) -> Result<Statement, QueryError> {
        if self.kind == DialectKind::Mongodb {
            return self.build_mongo();
        }
        self.bindings.clear();
        self.resolved_attribute_cache.clear();
        self.validate_table()?;
        let cte_prefix = self.build_cte_prefix()?;
        let grouped = Query::group_by_type(&self.pending_queries);
        self.prepare_alias_qualification(&grouped);
        let mut join_filter_where = Vec::new();
        let mut parts = vec![self.build_select_clause(&grouped)?];
        append_if_not_empty(&mut parts, self.build_from_clause()?);
        append_if_not_empty(
            &mut parts,
            self.build_joins_clause(&grouped, &mut join_filter_where)?,
        );
        append_if_not_empty(&mut parts, self.build_after_joins_clause(&grouped)?);
        append_if_not_empty(
            &mut parts,
            self.build_where_clause(&grouped, &join_filter_where)?,
        );
        append_if_not_empty(&mut parts, self.build_group_by_clause(&grouped)?);
        append_if_not_empty(&mut parts, self.build_after_group_by_clause());
        append_if_not_empty(&mut parts, self.build_having_clause(&grouped)?);
        append_if_not_empty(&mut parts, self.build_window_clause()?);
        append_if_not_empty(&mut parts, self.build_order_by_clause()?);
        append_if_not_empty(&mut parts, self.build_after_order_by_clause());
        append_if_not_empty(&mut parts, self.build_limit_clause(&grouped)?);
        append_if_not_empty(&mut parts, self.build_locking_clause()?);
        append_if_not_empty(&mut parts, self.build_settings_clause());
        let mut sql = parts.join(" ");
        let union_suffix = self.build_union_suffix()?;
        if !union_suffix.is_empty() {
            sql = format!("{}{union_suffix}", self.wrap_union_member(&sql));
        }
        sql = format!("{cte_prefix}{sql}");
        let mut result = Statement::new(sql, self.get_binding_values()).with_read_only(true);
        if let Some(exec) = &self.executor {
            result = result.with_executor(Arc::clone(exec));
        }
        Ok(result)
    }

    fn build_mongo(&mut self) -> Result<Statement, QueryError> {
        self.bindings.clear();
        self.validate_table()?;
        let grouped = Query::group_by_type(&self.pending_queries);
        let filter = self.build_mongo_filter(&grouped)?;
        let mut op = serde_json::Map::new();
        op.insert(
            "collection".to_owned(),
            serde_json::Value::String(self.table.clone()),
        );
        op.insert(
            "operation".to_owned(),
            serde_json::Value::String(MongoOperation::Find.as_str().to_owned()),
        );
        op.insert("filter".to_owned(), filter);
        let sql = serde_json::to_string(&op).map_err(|e| QueryError::exception(e.to_string()))?;
        Ok(Statement::new(sql, self.get_binding_values()).with_read_only(true))
    }

    fn build_mongo_filter(
        &mut self,
        grouped: &ParsedQuery,
    ) -> Result<serde_json::Value, QueryError> {
        let mut and_clauses = Vec::new();
        for filter in &grouped.filters {
            and_clauses.push(self.compile_mongo_filter(filter)?);
        }
        if and_clauses.is_empty() {
            return Ok(serde_json::Value::Object(serde_json::Map::new()));
        }
        if and_clauses.len() == 1 {
            return Ok(and_clauses.remove(0));
        }
        Ok(serde_json::json!({ "$and": and_clauses }))
    }

    fn compile_mongo_filter(&mut self, query: &Query) -> Result<serde_json::Value, QueryError> {
        let attr = query.get_attribute();
        let values: Vec<serde_json::Value> =
            query.get_values().iter().map(QueryValue::to_json).collect();
        let doc = match query.get_method() {
            Method::Equal => {
                self.add_bindings(query.get_values().iter().cloned());
                serde_json::json!({ attr: { "$in": vec!["?"; values.len()] } })
            }
            Method::GreaterThan => {
                self.add_binding(query.get_value(), Some(attr));
                serde_json::json!({ attr: { "$gt": "?" } })
            }
            Method::LessThan => {
                self.add_binding(query.get_value(), Some(attr));
                serde_json::json!({ attr: { "$lt": "?" } })
            }
            Method::And => {
                let mut parts = Vec::new();
                for v in query.get_values() {
                    if let Some(q) = v.as_query() {
                        parts.push(self.compile_mongo_filter(q)?);
                    }
                }
                serde_json::json!({ "$and": parts })
            }
            Method::Or => {
                let mut parts = Vec::new();
                for v in query.get_values() {
                    if let Some(q) = v.as_query() {
                        parts.push(self.compile_mongo_filter(q)?);
                    }
                }
                serde_json::json!({ "$or": parts })
            }
            _ => serde_json::json!({ attr: "?" }),
        };
        Ok(doc)
    }

    pub fn to_raw_sql(&mut self) -> Result<String, QueryError> {
        let result = self.build()?;
        let mut sql = result.query;
        let mut offset = 0usize;
        for binding in &result.bindings {
            let value = match binding {
                QueryValue::String(s) => format!("'{}'", s.replace('\'', "''")),
                QueryValue::Int(n) => n.to_string(),
                QueryValue::UInt(n) => n.to_string(),
                QueryValue::Float(n) => n.to_string(),
                QueryValue::Bool(true) => "1".to_owned(),
                QueryValue::Bool(false) => "0".to_owned(),
                _ => "NULL".to_owned(),
            };
            if let Some(pos) = sql[offset..].find('?') {
                let abs = offset + pos;
                sql.replace_range(abs..=abs, &value);
                offset = abs + value.len();
            }
        }
        Ok(sql)
    }

    pub fn explain(&mut self, analyze: bool) -> Result<Statement, QueryError> {
        let result = self.build()?;
        let prefix = if analyze {
            "EXPLAIN ANALYZE "
        } else {
            "EXPLAIN "
        };
        Ok(
            Statement::new(format!("{prefix}{}", result.query), result.bindings)
                .with_read_only(true),
        )
    }

    fn validate_table(&self) -> Result<(), QueryError> {
        if self.tableless {
            return Ok(());
        }
        if self.table.is_empty() && self.from_subquery.is_none() {
            return Err(QueryError::validation(
                "No table specified. Call from() or into() before building a query.",
            ));
        }
        Ok(())
    }

    fn prepare_alias_qualification(&mut self, grouped: &ParsedQuery) {
        self.qualify = false;
        self.aggregation_aliases.clear();
        if grouped.joins.is_empty() || self.alias.is_empty() {
            return;
        }
        self.qualify = true;
        for agg in &grouped.aggregations {
            let alias = agg.get_value_or("");
            let alias = alias.php_to_string();
            if !alias.is_empty() {
                self.aggregation_aliases.insert(alias, true);
            }
        }
    }

    fn build_cte_prefix(&mut self) -> Result<String, QueryError> {
        if self.ctes.is_empty() {
            return Ok(String::new());
        }
        let mut has_recursive = false;
        let mut cte_parts = Vec::new();
        let ctes = self.ctes.clone();
        for cte in &ctes {
            if cte.recursive {
                has_recursive = true;
            }
            self.add_bindings(cte.bindings.clone());
            let mut cte_name = self.quote(&cte.name)?;
            if !cte.columns.is_empty() {
                let cols: Result<Vec<_>, _> = cte.columns.iter().map(|c| self.quote(c)).collect();
                cte_name.push('(');
                cte_name.push_str(&cols?.join(", "));
                cte_name.push(')');
            }
            cte_parts.push(format!("{cte_name} AS ({})", cte.query));
        }
        let keyword = if has_recursive {
            "WITH RECURSIVE"
        } else {
            "WITH"
        };
        Ok(format!("{keyword} {} ", cte_parts.join(", ")))
    }

    fn build_select_clause(&mut self, grouped: &ParsedQuery) -> Result<String, QueryError> {
        let mut select_parts = Vec::new();
        let aggs = grouped.aggregations.clone();
        for agg in &aggs {
            select_parts.push(self.compile_aggregate(agg)?);
        }
        if let Some(sel) = grouped.selections.first() {
            select_parts.push(self.compile_select(sel)?);
        }
        let sub_selects = self.sub_selects.clone();
        for (sub, alias) in sub_selects {
            let mut sub = *sub;
            let sub_result = sub.build()?;
            select_parts.push(format!("({}) AS {}", sub_result.query, self.quote(&alias)?));
            self.add_bindings(sub_result.bindings);
        }
        let raw_selects = self.raw_selects.clone();
        for raw in &raw_selects {
            select_parts.push(raw.expression.clone());
            self.add_bindings(raw.bindings.clone());
        }
        let windows = self.window_selects.clone();
        for win in &windows {
            select_parts.push(self.compile_window_select(win)?);
        }
        let cases = self.cases.clone();
        for case in &cases {
            select_parts.push(self.compile_case(case)?);
        }
        let hints = if self.index_hints.is_empty() {
            String::new()
        } else {
            format!(" {}", self.index_hints.join(" "))
        };
        let select_sql = if select_parts.is_empty() {
            "*".to_owned()
        } else {
            select_parts.join(", ")
        };
        let keyword = if !self.distinct_on_columns.is_empty() {
            let cols = self.distinct_on_columns.clone();
            let quoted: Result<Vec<_>, _> = cols.iter().map(|c| self.quote(c)).collect();
            format!("SELECT DISTINCT ON ({})", quoted?.join(", "))
        } else if grouped.distinct {
            "SELECT DISTINCT".to_owned()
        } else {
            "SELECT".to_owned()
        };
        Ok(format!("{keyword}{hints} {select_sql}"))
    }

    fn compile_window_select(&mut self, win: &WindowSelect) -> Result<String, QueryError> {
        if let Some(name) = &win.window_name {
            return Ok(format!(
                "{} OVER {} AS {}",
                win.function,
                self.quote(name)?,
                self.quote(&win.alias)?
            ));
        }
        let mut over_parts = Vec::new();
        if let Some(part) = &win.partition_by {
            if !part.is_empty() {
                let cols: Result<Vec<_>, _> =
                    part.iter().map(|c| self.resolve_and_wrap(c)).collect();
                over_parts.push(format!("PARTITION BY {}", cols?.join(", ")));
            }
        }
        if let Some(order) = &win.order_by {
            if !order.is_empty() {
                over_parts.push(format!("ORDER BY {}", self.compile_order_by_list(order)?));
            }
        }
        if let Some(frame) = &win.frame {
            over_parts.push(frame.to_sql());
        }
        Ok(format!(
            "{} OVER ({}) AS {}",
            win.function,
            over_parts.join(" "),
            self.quote(&win.alias)?
        ))
    }

    fn compile_order_by_list(&mut self, order_by: &[String]) -> Result<String, QueryError> {
        let mut order_cols = Vec::new();
        for col in order_by {
            if let Some(rest) = col.strip_prefix('-') {
                order_cols.push(format!("{} DESC", self.resolve_and_wrap(rest)?));
            } else {
                order_cols.push(format!("{} ASC", self.resolve_and_wrap(col)?));
            }
        }
        Ok(order_cols.join(", "))
    }

    fn build_from_clause(&mut self) -> Result<String, QueryError> {
        self.build_table_clause()
    }

    fn build_table_clause(&mut self) -> Result<String, QueryError> {
        if self.tableless {
            return Ok(String::new());
        }
        if let Some((sub, alias)) = self.from_subquery.clone() {
            let mut sub = *sub;
            let sub_result = sub.build()?;
            self.add_bindings(sub_result.bindings);
            return Ok(format!(
                "FROM ({}) AS {}",
                sub_result.query,
                self.quote(&alias)?
            ));
        }
        let mut sql = format!("FROM {}", self.quote(&self.table)?);
        if !self.alias.is_empty() {
            sql.push_str(" AS ");
            sql.push_str(&self.quote(&self.alias)?);
        }
        if let Some((percent, method)) = &self.sample {
            sql.push_str(" TABLESAMPLE ");
            sql.push_str(method);
            sql.push('(');
            sql.push_str(&percent.to_string());
            sql.push(')');
        }
        Ok(sql)
    }

    fn build_after_joins_clause(&mut self, grouped: &ParsedQuery) -> Result<String, QueryError> {
        if self.kind != DialectKind::Clickhouse || self.prewhere_queries.is_empty() {
            let _ = grouped;
            return Ok(String::new());
        }
        let mut parts = Vec::new();
        let queries = self.prewhere_queries.clone();
        for q in &queries {
            parts.push(self.compile_filter(q)?);
        }
        if parts.is_empty() {
            Ok(String::new())
        } else {
            Ok(format!("PREWHERE {}", parts.join(" AND ")))
        }
    }

    fn build_after_group_by_clause(&self) -> String {
        String::new()
    }

    fn build_after_order_by_clause(&self) -> String {
        String::new()
    }

    fn build_settings_clause(&self) -> String {
        String::new()
    }

    fn build_joins_clause(
        &mut self,
        grouped: &ParsedQuery,
        join_filter_where: &mut Vec<Condition>,
    ) -> Result<String, QueryError> {
        let mut join_parts = Vec::new();
        if !grouped.joins.is_empty() {
            let mut join_query_indices = Vec::new();
            for (idx, pq) in self.pending_queries.iter().enumerate() {
                if pq.get_method().is_join() {
                    join_query_indices.push(idx);
                }
            }
            let joins = grouped.joins.clone();
            for (join_idx, join_query) in joins.iter().enumerate() {
                let pending_idx = join_query_indices
                    .get(join_idx)
                    .copied()
                    .unwrap_or(usize::MAX);
                let join_builder = self.joins.get(&pending_idx).cloned();
                let mut join_sql = if let Some(jb) = join_builder {
                    self.compile_join_with_builder(join_query, &jb)?
                } else {
                    self.compile_join(join_query)?
                };
                let join_table = join_query.get_attribute();
                let join_type = match join_query.get_method() {
                    Method::Join => JoinType::Inner,
                    Method::LeftJoin => JoinType::Left,
                    Method::RightJoin => JoinType::Right,
                    Method::CrossJoin => JoinType::Cross,
                    Method::FullOuterJoin => JoinType::FullOuter,
                    Method::NaturalJoin => JoinType::Natural,
                    other => {
                        return Err(QueryError::unsupported(format!(
                            "Unsupported join method: {}",
                            other.as_str()
                        )));
                    }
                };
                let is_cross = join_type == JoinType::Cross || join_type == JoinType::Natural;
                let join_values = join_query.get_values();
                let join_alias = if is_cross {
                    join_values
                        .first()
                        .map(QueryValue::php_to_string)
                        .unwrap_or_default()
                } else {
                    join_values
                        .get(3)
                        .map(QueryValue::php_to_string)
                        .unwrap_or_default()
                };
                let effective = if join_alias.is_empty() {
                    join_table.to_owned()
                } else {
                    join_alias
                };
                let hooks = self.join_filter_hooks.clone();
                for hook in hooks {
                    if let Some(result) = hook.filter_join(&effective, join_type) {
                        let placement =
                            self.resolve_join_filter_placement(result.placement, is_cross);
                        if placement == Placement::On {
                            join_sql.push_str(" AND ");
                            join_sql.push_str(&result.condition.expression);
                            self.add_bindings(result.condition.bindings);
                        } else {
                            join_filter_where.push(result.condition);
                        }
                    }
                }
                join_parts.push(join_sql);
            }
        }
        let laterals = self.lateral_joins.clone();
        for (sub, alias, jtype) in laterals {
            let mut sub = *sub;
            let sub_result = sub.build()?;
            self.add_bindings(sub_result.bindings);
            let keyword = match jtype {
                JoinType::Left => "LEFT JOIN",
                _ => "JOIN",
            };
            join_parts.push(format!(
                "{keyword} LATERAL ({}) AS {} ON true",
                sub_result.query,
                self.quote(&alias)?
            ));
        }
        Ok(join_parts.join(" "))
    }

    fn resolve_join_filter_placement(&self, requested: Placement, is_cross: bool) -> Placement {
        if self.kind == DialectKind::Clickhouse {
            return Placement::Where;
        }
        if is_cross {
            Placement::Where
        } else {
            requested
        }
    }

    fn build_where_clause(
        &mut self,
        grouped: &ParsedQuery,
        join_filter_where: &[Condition],
    ) -> Result<String, QueryError> {
        let mut where_clauses = Vec::new();
        let filters = grouped.filters.clone();
        for filter in &filters {
            where_clauses.push(self.compile_filter(filter)?);
        }
        let hooks = self.filter_hooks.clone();
        let table = if self.alias.is_empty() {
            self.table.clone()
        } else {
            self.alias.clone()
        };
        for hook in hooks {
            let condition = hook.filter(&table);
            where_clauses.push(condition.expression);
            self.add_bindings(condition.bindings);
        }
        for condition in join_filter_where {
            where_clauses.push(condition.expression.clone());
            self.add_bindings(condition.bindings.clone());
        }
        let where_ins = self.where_in_subqueries.clone();
        for (column, sub, not) in where_ins {
            let mut sub = *sub;
            let sub_result = sub.build()?;
            let prefix = if not { "NOT IN" } else { "IN" };
            where_clauses.push(format!(
                "{} {prefix} ({})",
                self.resolve_and_wrap(&column)?,
                sub_result.query
            ));
            self.add_bindings(sub_result.bindings);
        }
        let exists = self.exists_subqueries.clone();
        for (sub, not) in exists {
            let mut sub = *sub;
            let sub_result = sub.build()?;
            let prefix = if not { "NOT EXISTS" } else { "EXISTS" };
            where_clauses.push(format!("{prefix} ({})", sub_result.query));
            self.add_bindings(sub_result.bindings);
        }
        if grouped.cursor.is_some() && grouped.cursor_direction.is_some() {
            let cursor_queries = Query::get_cursor_queries(&self.pending_queries, false);
            if let Some(cq) = cursor_queries.first() {
                let cursor_sql = self.compile_cursor(cq)?;
                if !cursor_sql.is_empty() {
                    where_clauses.push(cursor_sql);
                }
            }
        }
        let raw_wheres = self.raw_wheres.clone();
        for raw in &raw_wheres {
            where_clauses.push(raw.expression.clone());
            self.add_bindings(raw.bindings.clone());
        }
        let preds = self.column_predicates.clone();
        for predicate in &preds {
            where_clauses.push(format!(
                "{} {} {}",
                self.resolve_and_wrap(&predicate.left)?,
                predicate.operator,
                self.resolve_and_wrap(&predicate.right)?
            ));
        }
        if where_clauses.is_empty() {
            return Ok(String::new());
        }
        Ok(format!("WHERE {}", where_clauses.join(" AND ")))
    }

    fn build_group_by_clause(&mut self, grouped: &ParsedQuery) -> Result<String, QueryError> {
        let mut parts = Vec::new();
        for col in &grouped.group_by {
            parts.push(self.resolve_and_wrap(col)?);
        }
        let buckets = grouped.time_buckets.clone();
        for bucket in &buckets {
            parts.push(self.compile_group_by_time_bucket(&bucket.attribute, &bucket.interval)?);
        }
        let raw_groups = self.raw_groups.clone();
        for raw in &raw_groups {
            parts.push(raw.expression.clone());
            self.add_bindings(raw.bindings.clone());
        }
        if parts.is_empty() {
            Ok(String::new())
        } else {
            Ok(format!("GROUP BY {}", parts.join(", ")))
        }
    }

    fn build_having_clause(&mut self, grouped: &ParsedQuery) -> Result<String, QueryError> {
        let alias_to_expr = self.build_aggregation_alias_map(grouped)?;
        let mut having_clauses = Vec::new();
        let havings = grouped.having.clone();
        for having_query in &havings {
            for sub in having_query.get_values() {
                if let Some(q) = sub.as_query() {
                    if let Some(expr) = alias_to_expr.get(q.get_attribute()) {
                        having_clauses.push(self.compile_having_condition(q, expr)?);
                    } else {
                        having_clauses.push(self.compile_filter(q)?);
                    }
                }
            }
        }
        let raw_havings = self.raw_havings.clone();
        for raw in &raw_havings {
            having_clauses.push(raw.expression.clone());
            self.add_bindings(raw.bindings.clone());
        }
        if having_clauses.is_empty() {
            Ok(String::new())
        } else {
            Ok(format!("HAVING {}", having_clauses.join(" AND ")))
        }
    }

    fn build_aggregation_alias_map(
        &mut self,
        grouped: &ParsedQuery,
    ) -> Result<HashMap<String, String>, QueryError> {
        let mut map = HashMap::new();
        for agg in &grouped.aggregations {
            let alias = agg.get_value_or("").php_to_string();
            if alias.is_empty() {
                continue;
            }
            let method = agg.get_method();
            let attr = agg.get_attribute();
            let col = if attr == "*" || attr.is_empty() {
                "*".to_owned()
            } else if attr.chars().all(|c| c.is_ascii_digit()) {
                attr.to_owned()
            } else {
                self.resolve_and_wrap(attr)?
            };
            if method == Method::CountDistinct {
                map.insert(alias, format!("COUNT(DISTINCT {col})"));
                continue;
            }
            let func = method.sql_function().unwrap_or(method.as_str());
            map.insert(alias, format!("{func}({col})"));
        }
        Ok(map)
    }

    fn compile_having_condition(
        &mut self,
        query: &Query,
        expression: &str,
    ) -> Result<String, QueryError> {
        let values = query.get_values();
        match query.get_method() {
            Method::Equal => self.compile_in(expression, values, None),
            Method::NotEqual => self.compile_not_in(expression, values, None),
            Method::LessThan => self.compile_comparison(expression, "<", values, None),
            Method::LessThanEqual => self.compile_comparison(expression, "<=", values, None),
            Method::GreaterThan => self.compile_comparison(expression, ">", values, None),
            Method::GreaterThanEqual => self.compile_comparison(expression, ">=", values, None),
            Method::Between => self.compile_between(expression, values, false, None),
            Method::NotBetween => self.compile_between(expression, values, true, None),
            Method::IsNull => Ok(format!("{expression} IS NULL")),
            Method::IsNotNull => Ok(format!("{expression} IS NOT NULL")),
            other => Err(QueryError::unsupported(format!(
                "Unsupported HAVING condition type: {}",
                other.as_str()
            ))),
        }
    }

    fn build_window_clause(&mut self) -> Result<String, QueryError> {
        if self.window_definitions.is_empty() {
            return Ok(String::new());
        }
        let definitions = self.window_definitions.clone();
        let mut window_parts = Vec::new();
        for win_def in &definitions {
            let mut over_parts = Vec::new();
            if let Some(part) = &win_def.partition_by {
                if !part.is_empty() {
                    let cols: Result<Vec<_>, _> =
                        part.iter().map(|c| self.resolve_and_wrap(c)).collect();
                    over_parts.push(format!("PARTITION BY {}", cols?.join(", ")));
                }
            }
            if let Some(order) = &win_def.order_by {
                if !order.is_empty() {
                    over_parts.push(format!("ORDER BY {}", self.compile_order_by_list(order)?));
                }
            }
            if let Some(frame) = &win_def.frame {
                over_parts.push(frame.to_sql());
            }
            window_parts.push(format!(
                "{} AS ({})",
                self.quote(&win_def.name)?,
                over_parts.join(" ")
            ));
        }
        Ok(format!("WINDOW {}", window_parts.join(", ")))
    }

    fn build_order_by_clause(&mut self) -> Result<String, QueryError> {
        let mut order_clauses = Vec::new();
        if let Some(expr) = self.compile_vector_order_expr() {
            order_clauses.push(expr.expression);
            self.add_bindings(expr.bindings);
        }
        let raw_orders = self.raw_orders.clone();
        for raw in &raw_orders {
            order_clauses.push(raw.expression.clone());
            self.add_bindings(raw.bindings.clone());
        }
        let order_queries = Query::get_by_type(
            &self.pending_queries,
            &[Method::OrderAsc, Method::OrderDesc, Method::OrderRandom],
            false,
        );
        for order_query in &order_queries {
            order_clauses.push(self.compile_order(order_query)?);
        }
        if order_clauses.is_empty() {
            Ok(String::new())
        } else {
            Ok(format!("ORDER BY {}", order_clauses.join(", ")))
        }
    }

    fn compile_vector_order_expr(&self) -> Option<Condition> {
        None
    }

    fn build_limit_clause(&mut self, grouped: &ParsedQuery) -> Result<String, QueryError> {
        let mut limit_parts = Vec::new();
        if let Some(limit) = grouped.limit {
            limit_parts.push("LIMIT ?".to_owned());
            self.add_binding(limit, None);
        }
        if self.should_emit_offset(grouped.offset, grouped.limit)? {
            limit_parts.push("OFFSET ?".to_owned());
            self.add_binding(grouped.offset.unwrap_or(0), None);
        }
        if let Some(count) = self.fetch_count {
            self.add_binding(count, None);
            limit_parts.push(if self.fetch_with_ties {
                "FETCH FIRST ? ROWS WITH TIES".to_owned()
            } else {
                "FETCH FIRST ? ROWS ONLY".to_owned()
            });
        }
        if let Some((count, columns)) = &self.limit_by {
            let cols = columns.clone();
            let n = *count;
            let quoted: Result<Vec<_>, _> = cols.iter().map(|c| self.quote(c)).collect();
            self.add_binding(n, None);
            limit_parts.push(format!("LIMIT ? BY {}", quoted?.join(", ")));
        }
        Ok(limit_parts.join(" "))
    }

    fn should_emit_offset(
        &self,
        offset: Option<i64>,
        limit: Option<i64>,
    ) -> Result<bool, QueryError> {
        if offset.is_none() {
            return Ok(false);
        }
        if limit.is_none() {
            return Err(QueryError::validation(
                "OFFSET requires LIMIT on this engine. Set a limit or use the dialect's native no-limit form.",
            ));
        }
        Ok(true)
    }

    fn build_locking_clause(&self) -> Result<String, QueryError> {
        let Some(mode) = self.lock_mode else {
            return Ok(String::new());
        };
        let mut sql = mode.to_sql().to_owned();
        if let Some(table) = &self.lock_of_table {
            sql.push_str(" OF ");
            sql.push_str(&self.quote(table)?);
        }
        Ok(sql)
    }

    #[allow(clippy::unnecessary_wraps)]
    fn build_union_suffix(&mut self) -> Result<String, QueryError> {
        if self.unions.is_empty() {
            return Ok(String::new());
        }
        let mut suffix = String::new();
        let unions = self.unions.clone();
        for union in &unions {
            suffix.push(' ');
            suffix.push_str(union.union_type.as_str());
            suffix.push(' ');
            suffix.push_str(&self.wrap_union_member(&union.query));
            self.add_bindings(union.bindings.clone());
        }
        Ok(suffix)
    }

    fn wrap_union_member(&self, sql: &str) -> String {
        if self.kind == DialectKind::Sqlite {
            sql.to_owned()
        } else {
            format!("({sql})")
        }
    }

    fn compile_case(&mut self, case: &CaseExpression) -> Result<String, QueryError> {
        if case.whens.is_empty() {
            return Err(QueryError::validation(
                "CASE expression requires at least one WHEN clause.",
            ));
        }
        let mut sql = String::from("CASE");
        let whens = case.whens.clone();
        for when in &whens {
            sql.push_str(" WHEN ");
            sql.push_str(&self.compile_when_condition(when)?);
            sql.push_str(" THEN ?");
            self.add_binding(when.then.clone(), None);
        }
        if case.has_else {
            sql.push_str(" ELSE ?");
            self.add_binding(case.else_value.clone(), None);
        }
        sql.push_str(" END");
        if !case.alias.is_empty() {
            sql.push_str(" AS ");
            sql.push_str(&self.quote(&case.alias)?);
        }
        Ok(sql)
    }

    fn compile_when_condition(&mut self, when: &WhenClause) -> Result<String, QueryError> {
        match when.kind {
            CaseKind::Comparison => {
                let column = when.column.as_deref().ok_or_else(|| {
                    QueryError::validation("Comparison WHEN clause requires column and operator.")
                })?;
                let operator = when.operator.ok_or_else(|| {
                    QueryError::validation("Comparison WHEN clause requires column and operator.")
                })?;
                self.add_binding(when.value.clone(), None);
                Ok(format!(
                    "{} {} ?",
                    self.quote(column)?,
                    operator.sql_operator()
                ))
            }
            CaseKind::Null => {
                let column = when
                    .column
                    .as_deref()
                    .ok_or_else(|| QueryError::validation("Null WHEN clause requires column."))?;
                Ok(format!("{} IS NULL", self.quote(column)?))
            }
            CaseKind::NotNull => {
                let column = when.column.as_deref().ok_or_else(|| {
                    QueryError::validation("NotNull WHEN clause requires column.")
                })?;
                Ok(format!("{} IS NOT NULL", self.quote(column)?))
            }
            CaseKind::In => {
                let column = when
                    .column
                    .as_deref()
                    .ok_or_else(|| QueryError::validation("In WHEN clause requires column."))?;
                if when.values.is_empty() {
                    return Err(QueryError::validation(
                        "In WHEN clause requires at least one value.",
                    ));
                }
                let placeholders = vec!["?"; when.values.len()].join(", ");
                for value in &when.values {
                    self.add_binding(value.clone(), None);
                }
                Ok(format!("{} IN ({placeholders})", self.quote(column)?))
            }
            CaseKind::Raw => {
                let cond = when
                    .raw_condition
                    .as_deref()
                    .ok_or_else(|| QueryError::validation("Raw WHEN clause requires condition."))?;
                for binding in &when.raw_bindings {
                    self.add_binding(binding.clone(), None);
                }
                Ok(cond.to_owned())
            }
        }
    }

    fn resolve_and_wrap(&mut self, attribute: &str) -> Result<String, QueryError> {
        let mut resolved = if let Some(cached) = self.resolved_attribute_cache.get(attribute) {
            cached.clone()
        } else {
            let mut resolved = attribute.to_owned();
            for hook in &self.attribute_hooks {
                resolved = hook.resolve(&resolved);
            }
            self.resolved_attribute_cache
                .insert(attribute.to_owned(), resolved.clone());
            resolved
        };
        if self.qualify
            && resolved != "*"
            && !resolved.contains('.')
            && !self.aggregation_aliases.contains_key(&resolved)
        {
            resolved = format!("{}.{}", self.alias, resolved);
        }
        self.quote(&resolved)
    }

    fn compile_random(&self) -> String {
        match self.kind {
            DialectKind::Sqlite | DialectKind::Postgres => "RANDOM()".to_owned(),
            DialectKind::Clickhouse => "rand()".to_owned(),
            DialectKind::Mongodb => "$rand".to_owned(),
            _ => "RAND()".to_owned(),
        }
    }

    fn compile_regex(
        &mut self,
        attribute: &str,
        values: &[QueryValue],
        column: Option<&str>,
    ) -> Result<String, QueryError> {
        match self.kind {
            DialectKind::Sqlite => Err(QueryError::unsupported(
                "REGEXP is not natively supported in SQLite.",
            )),
            DialectKind::Postgres => {
                self.add_binding(values.first().cloned().unwrap_or(QueryValue::Null), column);
                Ok(format!("{attribute} ~ ?"))
            }
            DialectKind::Clickhouse => {
                self.add_binding(values.first().cloned().unwrap_or(QueryValue::Null), column);
                Ok(format!("match({attribute}, ?)"))
            }
            _ => {
                self.add_binding(values.first().cloned().unwrap_or(QueryValue::Null), column);
                Ok(format!("{attribute} REGEXP ?"))
            }
        }
    }

    fn get_like_keyword(&self) -> &'static str {
        "LIKE"
    }

    fn escape_like_value(&self, value: &QueryValue) -> String {
        let s = match value {
            QueryValue::Array(_) | QueryValue::Object(_) => {
                value.to_json().to_string().trim_matches('"').to_owned()
            }
            QueryValue::Int(_)
            | QueryValue::UInt(_)
            | QueryValue::Float(_)
            | QueryValue::Bool(_) => value.php_to_string(),
            QueryValue::String(s) => s.clone(),
            _ => String::new(),
        };
        s.replace('\\', "\\\\")
            .replace('%', "\\%")
            .replace('_', "\\_")
    }

    fn compile_like(
        &mut self,
        attribute: &str,
        values: &[QueryValue],
        prefix: &str,
        suffix: &str,
        not: bool,
        column: Option<&str>,
    ) -> String {
        let raw = values.first().cloned().unwrap_or(QueryValue::Null);
        let val = self.escape_like_value(&raw);
        self.add_binding(format!("{prefix}{val}{suffix}"), column);
        let like = self.get_like_keyword();
        let keyword = if not {
            format!("NOT {like}")
        } else {
            like.to_owned()
        };
        format!("{attribute} {keyword} ?")
    }

    fn compile_contains(
        &mut self,
        attribute: &str,
        values: &[QueryValue],
        column: Option<&str>,
    ) -> String {
        let like = self.get_like_keyword();
        if values.len() == 1 {
            let escaped = self.escape_like_value(&values[0]);
            self.add_binding(format!("%{escaped}%"), column);
            return format!("{attribute} {like} ?");
        }
        let mut parts = Vec::new();
        for value in values {
            let escaped = self.escape_like_value(value);
            self.add_binding(format!("%{escaped}%"), column);
            parts.push(format!("{attribute} {like} ?"));
        }
        format!("({})", parts.join(" OR "))
    }

    fn compile_contains_all(
        &mut self,
        attribute: &str,
        values: &[QueryValue],
        column: Option<&str>,
    ) -> String {
        let like = self.get_like_keyword();
        let mut parts = Vec::new();
        for value in values {
            let escaped = self.escape_like_value(value);
            self.add_binding(format!("%{escaped}%"), column);
            parts.push(format!("{attribute} {like} ?"));
        }
        format!("({})", parts.join(" AND "))
    }

    fn compile_not_contains(
        &mut self,
        attribute: &str,
        values: &[QueryValue],
        column: Option<&str>,
    ) -> String {
        let like = self.get_like_keyword();
        if values.len() == 1 {
            let escaped = self.escape_like_value(&values[0]);
            self.add_binding(format!("%{escaped}%"), column);
            return format!("{attribute} NOT {like} ?");
        }
        let mut parts = Vec::new();
        for value in values {
            let escaped = self.escape_like_value(value);
            self.add_binding(format!("%{escaped}%"), column);
            parts.push(format!("{attribute} NOT {like} ?"));
        }
        format!("({})", parts.join(" AND "))
    }

    #[allow(clippy::unnecessary_wraps)]
    fn compile_in(
        &mut self,
        attribute: &str,
        values: &[QueryValue],
        column: Option<&str>,
    ) -> Result<String, QueryError> {
        if values.is_empty() {
            return Ok("1 = 0".to_owned());
        }
        let mut has_nulls = false;
        let mut non_nulls = Vec::new();
        for value in values {
            if value.is_null() {
                has_nulls = true;
            } else {
                non_nulls.push(value.clone());
            }
        }
        if has_nulls && non_nulls.is_empty() {
            return Ok(format!("{attribute} IS NULL"));
        }
        let placeholders = vec!["?"; non_nulls.len()].join(", ");
        for value in &non_nulls {
            self.add_binding(value.clone(), column);
        }
        let in_clause = format!("{attribute} IN ({placeholders})");
        if has_nulls {
            Ok(format!("({in_clause} OR {attribute} IS NULL)"))
        } else {
            Ok(in_clause)
        }
    }

    #[allow(clippy::unnecessary_wraps)]
    fn compile_not_in(
        &mut self,
        attribute: &str,
        values: &[QueryValue],
        column: Option<&str>,
    ) -> Result<String, QueryError> {
        if values.is_empty() {
            return Ok("1 = 1".to_owned());
        }
        let mut has_nulls = false;
        let mut non_nulls = Vec::new();
        for value in values {
            if value.is_null() {
                has_nulls = true;
            } else {
                non_nulls.push(value.clone());
            }
        }
        if has_nulls && non_nulls.is_empty() {
            return Ok(format!("{attribute} IS NOT NULL"));
        }
        let not_clause = if non_nulls.len() == 1 {
            self.add_binding(non_nulls[0].clone(), column);
            format!("{attribute} != ?")
        } else {
            let placeholders = vec!["?"; non_nulls.len()].join(", ");
            for value in &non_nulls {
                self.add_binding(value.clone(), column);
            }
            format!("{attribute} NOT IN ({placeholders})")
        };
        if has_nulls {
            Ok(format!("({not_clause} AND {attribute} IS NOT NULL)"))
        } else {
            Ok(not_clause)
        }
    }

    #[allow(clippy::unnecessary_wraps)]
    fn compile_comparison(
        &mut self,
        attribute: &str,
        operator: &str,
        values: &[QueryValue],
        column: Option<&str>,
    ) -> Result<String, QueryError> {
        self.add_binding(values.first().cloned().unwrap_or(QueryValue::Null), column);
        Ok(format!("{attribute} {operator} ?"))
    }

    #[allow(clippy::unnecessary_wraps)]
    fn compile_between(
        &mut self,
        attribute: &str,
        values: &[QueryValue],
        not: bool,
        column: Option<&str>,
    ) -> Result<String, QueryError> {
        self.add_binding(values.first().cloned().unwrap_or(QueryValue::Null), column);
        self.add_binding(values.get(1).cloned().unwrap_or(QueryValue::Null), column);
        let keyword = if not { "NOT BETWEEN" } else { "BETWEEN" };
        Ok(format!("{attribute} {keyword} ? AND ?"))
    }

    fn compile_logical(&mut self, query: &Query, operator: &str) -> Result<String, QueryError> {
        let mut parts = Vec::new();
        for sub in query.get_values() {
            if let Some(q) = sub.as_query() {
                parts.push(self.compile_filter(q)?);
            }
        }
        if parts.is_empty() {
            return Ok(if operator == "OR" {
                "1 = 0".to_owned()
            } else {
                "1 = 1".to_owned()
            });
        }
        Ok(format!("({})", parts.join(&format!(" {operator} "))))
    }

    fn compile_exists_attrs(&mut self, query: &Query) -> Result<String, QueryError> {
        let mut parts = Vec::new();
        for attr in query.get_values() {
            parts.push(format!(
                "{} IS NOT NULL",
                self.resolve_and_wrap(&attr.php_to_string())?
            ));
        }
        if parts.is_empty() {
            Ok("1 = 1".to_owned())
        } else {
            Ok(format!("({})", parts.join(" AND ")))
        }
    }

    fn compile_not_exists_attrs(&mut self, query: &Query) -> Result<String, QueryError> {
        let mut parts = Vec::new();
        for attr in query.get_values() {
            parts.push(format!(
                "{} IS NULL",
                self.resolve_and_wrap(&attr.php_to_string())?
            ));
        }
        if parts.is_empty() {
            Ok("1 = 1".to_owned())
        } else {
            Ok(format!("({})", parts.join(" AND ")))
        }
    }

    fn compile_raw_filter(&mut self, query: &Query) -> String {
        let attribute = query.get_attribute();
        if attribute.is_empty() {
            return "1 = 1".to_owned();
        }
        for binding in query.get_values() {
            self.add_binding(binding.clone(), None);
        }
        attribute.to_owned()
    }

    fn compile_search_expr(
        &mut self,
        attribute: &str,
        values: &[QueryValue],
        not: bool,
    ) -> Result<String, QueryError> {
        match self.kind {
            DialectKind::Sqlite => Err(QueryError::unsupported(
                "Full-text search is not supported in the SQLite query builder.",
            )),
            DialectKind::Postgres => {
                let term = values
                    .first()
                    .map(QueryValue::php_to_string)
                    .unwrap_or_default();
                self.add_binding(term, None);
                if not {
                    Ok(format!(
                        "NOT (to_tsvector({attribute}) @@ plainto_tsquery(?))"
                    ))
                } else {
                    Ok(format!("to_tsvector({attribute}) @@ plainto_tsquery(?)"))
                }
            }
            DialectKind::Mysql | DialectKind::Mariadb => {
                let term = values
                    .first()
                    .map(QueryValue::php_to_string)
                    .unwrap_or_default();
                let exact = term.starts_with('"') && term.ends_with('"');
                let special = ['@', '+', '-', '*', ')', '(', '<', '>', '~', '"'];
                let mut sanitized: String = term
                    .chars()
                    .map(|c| if special.contains(&c) { ' ' } else { c })
                    .collect();
                let re = regex::Regex::new(r"\s+").expect("static");
                let collapsed = re.replace_all(&sanitized, " ").into_owned();
                sanitized.clear();
                sanitized.push_str(collapsed.trim());
                if sanitized.is_empty() {
                    return Ok(if not { "1 = 1" } else { "1 = 0" }.to_owned());
                }
                if exact {
                    sanitized = format!("\"{sanitized}\"");
                } else {
                    sanitized.push('*');
                }
                self.add_binding(sanitized, None);
                if not {
                    Ok(format!(
                        "NOT (MATCH({attribute}) AGAINST(? IN BOOLEAN MODE))"
                    ))
                } else {
                    Ok(format!("MATCH({attribute}) AGAINST(? IN BOOLEAN MODE)"))
                }
            }
            _ => Err(QueryError::unsupported(
                "Full-text search is not supported by this dialect.",
            )),
        }
    }

    fn compile_group_by_time_bucket(
        &mut self,
        attribute: &str,
        interval: &str,
    ) -> Result<String, QueryError> {
        let wrapped = self.resolve_and_wrap(attribute)?;
        match self.kind {
            DialectKind::Clickhouse => {
                let func = match interval {
                    "1m" => "toStartOfMinute",
                    "5m" => "toStartOfFiveMinutes",
                    "15m" => "toStartOfFifteenMinutes",
                    "1h" => "toStartOfHour",
                    "1d" => "toStartOfDay",
                    "1w" => "toStartOfWeek",
                    "1M" => "toStartOfMonth",
                    _ => {
                        return Err(QueryError::unsupported(format!(
                            "groupByTimeBucket is not supported by {}",
                            self.kind.class_name()
                        )));
                    }
                };
                Ok(format!("{func}({wrapped})"))
            }
            _ => Err(QueryError::unsupported(format!(
                "groupByTimeBucket is not supported by {}",
                self.kind.class_name()
            ))),
        }
    }

    fn compile_join_with_builder(
        &mut self,
        query: &Query,
        join_builder: &JoinBuilder,
    ) -> Result<String, QueryError> {
        let type_sql = join_keyword(query.get_method())?;
        let mut table = self.quote(query.get_attribute())?;
        let values = query.get_values();
        let alias = if matches!(query.get_method(), Method::CrossJoin | Method::NaturalJoin) {
            values
                .first()
                .map(QueryValue::php_to_string)
                .unwrap_or_default()
        } else {
            values
                .get(3)
                .map(QueryValue::php_to_string)
                .unwrap_or_default()
        };
        if !alias.is_empty() {
            table = format!("{table} AS {}", self.quote(&alias)?);
        }
        let mut on_parts = Vec::new();
        for on in &join_builder.ons {
            on_parts.push(format!(
                "{} {} {}",
                self.resolve_and_wrap(&on.left)?,
                on.operator,
                self.resolve_and_wrap(&on.right)?
            ));
        }
        for where_c in &join_builder.wheres {
            on_parts.push(where_c.expression.clone());
            self.add_bindings(where_c.bindings.clone());
        }
        if on_parts.is_empty() {
            Ok(format!("{type_sql} {table}"))
        } else {
            Ok(format!("{type_sql} {table} ON {}", on_parts.join(" AND ")))
        }
    }

    pub fn insert(&mut self) -> Result<Statement, QueryError> {
        if self.kind == DialectKind::Mongodb {
            return self.mongo_insert();
        }
        self.bindings.clear();
        let (sql, bindings) = self.compile_insert_body()?;
        self.add_bindings(bindings);
        self.finish_write(sql)
    }

    pub fn update(&mut self) -> Result<Statement, QueryError> {
        if self.kind == DialectKind::Mongodb {
            return self.mongo_update();
        }
        self.bindings.clear();
        self.validate_table()?;
        let assignments = self.compile_assignments()?;
        if assignments.is_empty() {
            return Err(QueryError::validation(
                "No assignments for UPDATE. Call set() or setRaw() before update().",
            ));
        }
        let grouped = Query::group_by_type(&self.pending_queries);
        let mut parts = vec![format!(
            "UPDATE {} SET {}",
            self.quote(&self.table)?,
            assignments.join(", ")
        )];
        self.compile_where_clauses(&mut parts, &grouped)?;
        self.compile_order_and_limit(&mut parts, &grouped)?;
        self.finish_write(parts.join(" "))
    }

    pub fn delete(&mut self) -> Result<Statement, QueryError> {
        if self.kind == DialectKind::Mongodb {
            return self.mongo_delete();
        }
        self.bindings.clear();
        self.validate_table()?;
        let grouped = Query::group_by_type(&self.pending_queries);
        let mut parts = vec![format!("DELETE FROM {}", self.quote(&self.table)?)];
        self.compile_where_clauses(&mut parts, &grouped)?;
        self.compile_order_and_limit(&mut parts, &grouped)?;
        self.finish_write(parts.join(" "))
    }

    fn finish_write(&mut self, sql: String) -> Result<Statement, QueryError> {
        let sql = self.append_returning(sql)?;
        let mut stmt = Statement::new(sql, self.get_binding_values());
        if let Some(exec) = &self.executor {
            stmt = stmt.with_executor(Arc::clone(exec));
        }
        Ok(stmt)
    }

    fn append_returning(&mut self, sql: String) -> Result<String, QueryError> {
        if self.returning_columns.is_empty() {
            return Ok(sql);
        }
        let cols = self.returning_columns.clone();
        let quoted: Result<Vec<_>, _> = cols
            .iter()
            .map(|c| {
                if c == "*" {
                    Ok("*".to_owned())
                } else {
                    self.resolve_and_wrap(c)
                }
            })
            .collect();
        Ok(format!("{sql} RETURNING {}", quoted?.join(", ")))
    }

    fn compile_insert_body(&mut self) -> Result<(String, Vec<QueryValue>), QueryError> {
        self.validate_table()?;
        self.validate_rows("insert")?;
        let columns = self.validate_and_get_columns()?;
        let wrapped: Result<Vec<_>, _> = columns.iter().map(|c| self.resolve_and_wrap(c)).collect();
        let wrapped = wrapped?;
        let mut bindings = Vec::new();
        let mut row_placeholders = Vec::new();
        for row in &self.rows {
            let mut placeholders = Vec::new();
            for col in &columns {
                bindings.push(row.get(col).cloned().unwrap_or(QueryValue::Null));
                if let Some(expr) = self.insert_column_expressions.get(col) {
                    placeholders.push(expr.clone());
                    if let Some(extra) = self.insert_column_expression_bindings.get(col) {
                        bindings.extend(extra.clone());
                    }
                } else {
                    placeholders.push("?".to_owned());
                }
            }
            row_placeholders.push(format!("({})", placeholders.join(", ")));
        }
        let mut table_part = self.quote(&self.table)?;
        if !self.insert_alias.is_empty() {
            table_part.push_str(" AS ");
            table_part.push_str(&self.quote(&self.insert_alias)?);
        }
        let sql = format!(
            "INSERT INTO {table_part} ({}) VALUES {}",
            wrapped.join(", "),
            row_placeholders.join(", ")
        );
        Ok((sql, bindings))
    }

    fn compile_assignments(&mut self) -> Result<Vec<String>, QueryError> {
        let mut assignments = Vec::new();
        if let Some(row) = self.rows.first().cloned() {
            for (col, value) in row {
                assignments.push(format!("{} = ?", self.resolve_and_wrap(&col)?));
                self.add_binding(value, None);
            }
        }
        let raw_sets = self.raw_sets.clone();
        for (col, expression) in &raw_sets {
            assignments.push(format!("{} = {expression}", self.resolve_and_wrap(col)?));
            if let Some(b) = self.raw_set_bindings.get(col).cloned() {
                self.add_bindings(b);
            }
        }
        let case_sets = self.case_sets.clone();
        for (col, case_data) in &case_sets {
            assignments.push(format!(
                "{} = {}",
                self.resolve_and_wrap(col)?,
                self.compile_case(case_data)?
            ));
        }
        Ok(assignments)
    }

    fn compile_where_clauses(
        &mut self,
        parts: &mut Vec<String>,
        grouped: &ParsedQuery,
    ) -> Result<(), QueryError> {
        let sql = self.build_where_clause(grouped, &[])?;
        if !sql.is_empty() {
            parts.push(sql);
        }
        Ok(())
    }

    fn compile_order_and_limit(
        &mut self,
        parts: &mut Vec<String>,
        grouped: &ParsedQuery,
    ) -> Result<(), QueryError> {
        let order = self.build_order_by_clause()?;
        if !order.is_empty() {
            parts.push(order);
        }
        if grouped.limit.is_some() {
            parts.push("LIMIT ?".to_owned());
            self.add_binding(grouped.limit.unwrap_or(0), None);
        }
        Ok(())
    }

    fn validate_rows(&self, operation: &str) -> Result<(), QueryError> {
        if self.rows.is_empty() {
            return Err(QueryError::validation(format!(
                "No rows to {operation}. Call set() before {operation}()."
            )));
        }
        for row in &self.rows {
            if row.is_empty() {
                return Err(QueryError::validation(format!(
                    "Cannot {operation} an empty row. Each set() call must include at least one column."
                )));
            }
        }
        Ok(())
    }

    fn validate_and_get_columns(&self) -> Result<Vec<String>, QueryError> {
        let columns: Vec<String> = self.rows[0].keys().cloned().collect();
        for col in &columns {
            if col.is_empty() {
                return Err(QueryError::validation(
                    "Column names must be non-empty strings.",
                ));
            }
        }
        if self.rows.len() > 1 {
            let mut expected = columns.clone();
            expected.sort();
            for (i, row) in self.rows.iter().enumerate() {
                let mut row_keys: Vec<String> = row.keys().cloned().collect();
                row_keys.sort();
                if row_keys != expected {
                    return Err(QueryError::validation(format!(
                        "Row {i} has different columns than row 0. All rows in a batch must have the same columns."
                    )));
                }
            }
        }
        Ok(columns)
    }

    fn mongo_insert(&mut self) -> Result<Statement, QueryError> {
        self.bindings.clear();
        self.validate_table()?;
        self.validate_rows("insert")?;
        let mut documents = Vec::new();
        let rows = self.rows.clone();
        for row in &rows {
            let mut doc = serde_json::Map::new();
            for (col, value) in row {
                self.add_binding(value.clone(), None);
                doc.insert(col.clone(), serde_json::Value::String("?".to_owned()));
            }
            documents.push(serde_json::Value::Object(doc));
        }
        let op = serde_json::json!({
            "collection": self.table,
            "operation": MongoOperation::InsertMany.as_str(),
            "documents": documents,
        });
        self.finish_write(serde_json::to_string(&op).unwrap_or_default())
    }

    fn mongo_update(&mut self) -> Result<Statement, QueryError> {
        self.bindings.clear();
        self.validate_table()?;
        Err(QueryError::validation(
            "No update operations specified. Call set() before update().",
        ))
    }

    fn mongo_delete(&mut self) -> Result<Statement, QueryError> {
        self.bindings.clear();
        self.validate_table()?;
        let grouped = Query::group_by_type(&self.pending_queries);
        let filter = self.build_mongo_filter(&grouped)?;
        let op = serde_json::json!({
            "collection": self.table,
            "operation": MongoOperation::DeleteMany.as_str(),
            "filter": filter,
        });
        self.finish_write(serde_json::to_string(&op).unwrap_or_default())
    }

    pub fn force_index(&mut self, index: impl Into<String>) -> &mut Self {
        self.index_hints
            .push(format!("FORCE INDEX ({})", index.into()));
        self
    }

    pub fn use_index(&mut self, index: impl Into<String>) -> &mut Self {
        self.index_hints
            .push(format!("USE INDEX ({})", index.into()));
        self
    }

    pub fn ignore_index(&mut self, index: impl Into<String>) -> &mut Self {
        self.index_hints
            .push(format!("IGNORE INDEX ({})", index.into()));
        self
    }

    pub fn lock(&mut self, mode: LockMode) -> &mut Self {
        self.lock_mode = Some(mode);
        self
    }

    pub fn lock_of(&mut self, table: impl Into<String>) -> &mut Self {
        self.lock_of_table = Some(table.into());
        self
    }

    pub fn join_lateral(
        &mut self,
        subquery: Builder,
        alias: impl Into<String>,
        join_type: JoinType,
    ) -> &mut Self {
        self.lateral_joins
            .push((Box::new(subquery), alias.into(), join_type));
        self
    }

    pub fn left_join_lateral(&mut self, subquery: Builder, alias: impl Into<String>) -> &mut Self {
        self.join_lateral(subquery, alias, JoinType::Left)
    }

    pub fn distinct_on(&mut self, columns: Vec<String>) -> &mut Self {
        self.distinct_on_columns = columns;
        self
    }

    pub fn limit_by(&mut self, count: i64, columns: Vec<String>) -> &mut Self {
        self.limit_by = Some((count, columns));
        self
    }

    pub fn returning(&mut self, columns: Vec<String>) -> &mut Self {
        self.returning_columns = columns;
        self
    }

    pub fn begin(&self) -> Statement {
        Statement::new("BEGIN", vec![])
    }

    pub fn commit(&self) -> Statement {
        Statement::new("COMMIT", vec![])
    }

    pub fn rollback(&self) -> Statement {
        Statement::new("ROLLBACK", vec![])
    }

    pub fn insert_or_ignore(&mut self) -> Result<Statement, QueryError> {
        self.bindings.clear();
        let (sql, bindings) = self.compile_insert_body()?;
        self.add_bindings(bindings);
        let sql = match self.kind {
            DialectKind::Sqlite | DialectKind::Postgres => {
                sql.replacen("INSERT INTO", "INSERT OR IGNORE INTO", 1)
            }
            _ => sql.replacen("INSERT INTO", "INSERT IGNORE INTO", 1),
        };
        self.finish_write(sql)
    }

    pub fn upsert(&mut self) -> Result<Statement, QueryError> {
        self.bindings.clear();
        let (sql, bindings) = self.compile_insert_body()?;
        self.add_bindings(bindings);
        let conflict = self.compile_conflict_clause()?;
        self.finish_write(format!("{sql} {conflict}"))
    }

    fn compile_conflict_clause(&mut self) -> Result<String, QueryError> {
        let mut updates = Vec::new();
        let cols = self.conflict_update_columns.clone();
        for col in &cols {
            let wrapped = self.resolve_and_wrap(col)?;
            if let Some(raw) = self.conflict_raw_sets.get(col).cloned() {
                updates.push(format!("{wrapped} = {raw}"));
                if let Some(b) = self.conflict_raw_set_bindings.get(col).cloned() {
                    self.add_bindings(b);
                }
            } else {
                let assignment = match self.kind {
                    DialectKind::Postgres | DialectKind::Sqlite => {
                        format!("excluded.{wrapped}")
                    }
                    _ => format!("VALUES({wrapped})"),
                };
                updates.push(format!("{wrapped} = {assignment}"));
            }
        }
        let header = match self.kind {
            DialectKind::Postgres | DialectKind::Sqlite => {
                let keys = self.conflict_keys.clone();
                let quoted: Result<Vec<_>, _> =
                    keys.iter().map(|k| self.resolve_and_wrap(k)).collect();
                format!("ON CONFLICT ({}) DO UPDATE SET", quoted?.join(", "))
            }
            _ => "ON DUPLICATE KEY UPDATE".to_owned(),
        };
        Ok(format!("{header} {}", updates.join(", ")))
    }
}

impl Compiler for Builder {
    fn compile_filter(&mut self, query: &Query) -> Result<String, QueryError> {
        let method = query.get_method();
        let raw_attribute = query.get_attribute();
        let attribute = self.resolve_and_wrap(raw_attribute)?;
        let values = query.get_values();
        let column = if raw_attribute.is_empty() {
            None
        } else {
            Some(raw_attribute)
        };

        if method == Method::Search {
            return self.compile_search_expr(&attribute, values, false);
        }
        if method == Method::NotSearch {
            return self.compile_search_expr(&attribute, values, true);
        }
        if method.is_spatial() {
            return self.compile_spatial_filter(method, &attribute, query);
        }
        let attr_type = query.get_attribute_type();
        let is_spatial_attr = matches!(
            attr_type,
            t if t == ColumnType::Point.as_str()
                || t == ColumnType::Linestring.as_str()
                || t == ColumnType::Polygon.as_str()
        );
        if is_spatial_attr {
            let spatial_method = match method {
                Method::Equal => Some(Method::SpatialEquals),
                Method::NotEqual => Some(Method::NotSpatialEquals),
                Method::Contains => Some(Method::Covers),
                Method::NotContains => Some(Method::NotCovers),
                _ => None,
            };
            if let Some(sm) = spatial_method {
                return self.compile_spatial_filter(sm, &attribute, query);
            }
        }
        if method.is_json() {
            return self.compile_json_filter(method, &attribute, query);
        }
        if query.on_array()
            && matches!(
                method,
                Method::Contains | Method::ContainsAny | Method::NotContains | Method::ContainsAll
            )
        {
            return self.compile_array_filter(method, &attribute, query);
        }

        match method {
            Method::Equal => self.compile_in(&attribute, values, column),
            Method::NotEqual => self.compile_not_in(&attribute, values, column),
            Method::LessThan => self.compile_comparison(&attribute, "<", values, column),
            Method::LessThanEqual => self.compile_comparison(&attribute, "<=", values, column),
            Method::GreaterThan => self.compile_comparison(&attribute, ">", values, column),
            Method::GreaterThanEqual => self.compile_comparison(&attribute, ">=", values, column),
            Method::Between => self.compile_between(&attribute, values, false, column),
            Method::NotBetween => self.compile_between(&attribute, values, true, column),
            Method::StartsWith => Ok(self.compile_like(&attribute, values, "", "%", false, column)),
            Method::NotStartsWith => {
                Ok(self.compile_like(&attribute, values, "", "%", true, column))
            }
            Method::EndsWith => Ok(self.compile_like(&attribute, values, "%", "", false, column)),
            Method::NotEndsWith => Ok(self.compile_like(&attribute, values, "%", "", true, column)),
            Method::Contains => Ok(self.compile_contains(&attribute, values, column)),
            Method::ContainsAny => {
                if query.on_array() {
                    self.compile_in(&attribute, values, column)
                } else {
                    Ok(self.compile_contains(&attribute, values, column))
                }
            }
            Method::ContainsAll => Ok(self.compile_contains_all(&attribute, values, column)),
            Method::NotContains => Ok(self.compile_not_contains(&attribute, values, column)),
            Method::Regex => self.compile_regex(&attribute, values, column),
            Method::IsNull => Ok(format!("{attribute} IS NULL")),
            Method::IsNotNull => Ok(format!("{attribute} IS NOT NULL")),
            Method::And | Method::Having => self.compile_logical(query, "AND"),
            Method::Or => self.compile_logical(query, "OR"),
            Method::Exists => self.compile_exists_attrs(query),
            Method::NotExists => self.compile_not_exists_attrs(query),
            Method::Raw => Ok(self.compile_raw_filter(query)),
            Method::ElemMatch => Err(QueryError::unsupported(
                "elemMatch is not supported by this dialect.",
            )),
            other => Err(QueryError::unsupported(format!(
                "Unsupported filter type: {}",
                other.as_str()
            ))),
        }
    }

    fn compile_order(&mut self, query: &Query) -> Result<String, QueryError> {
        let mut sql = match query.get_method() {
            Method::OrderAsc => format!("{} ASC", self.resolve_and_wrap(query.get_attribute())?),
            Method::OrderDesc => format!("{} DESC", self.resolve_and_wrap(query.get_attribute())?),
            Method::OrderRandom => self.compile_random(),
            other => {
                return Err(QueryError::unsupported(format!(
                    "Unsupported order type: {}",
                    other.as_str()
                )));
            }
        };
        if let Some(NullsPosition::First | NullsPosition::Last) =
            query.get_value().as_nulls_position()
        {
            sql.push_str(" NULLS ");
            sql.push_str(query.get_value().as_nulls_position().unwrap().as_str());
        }
        Ok(sql)
    }

    fn compile_limit(&mut self, query: &Query) -> Result<String, QueryError> {
        self.add_binding(query.get_value(), None);
        Ok("LIMIT ?".to_owned())
    }

    fn compile_offset(&mut self, query: &Query) -> Result<String, QueryError> {
        self.add_binding(query.get_value(), None);
        Ok("OFFSET ?".to_owned())
    }

    fn compile_select(&mut self, query: &Query) -> Result<String, QueryError> {
        let columns: Result<Vec<_>, _> = query
            .get_values()
            .iter()
            .map(|v| self.resolve_and_wrap(&v.php_to_string()))
            .collect();
        Ok(columns?.join(", "))
    }

    fn compile_cursor(&mut self, query: &Query) -> Result<String, QueryError> {
        self.add_binding(query.get_value(), None);
        let operator = if query.get_method() == Method::CursorAfter {
            ">"
        } else {
            "<"
        };
        Ok(format!("{} {operator} ?", self.quote("_cursor")?))
    }

    fn compile_aggregate(&mut self, query: &Query) -> Result<String, QueryError> {
        let method = query.get_method();
        if method == Method::CountDistinct {
            let attr = query.get_attribute();
            let col = if attr == "*" || attr.is_empty() {
                "*".to_owned()
            } else {
                self.resolve_and_wrap(attr)?
            };
            let alias = query.get_value_or("").php_to_string();
            let mut sql = format!("COUNT(DISTINCT {col})");
            if !alias.is_empty() {
                sql.push_str(" AS ");
                sql.push_str(&self.quote(&alias)?);
            }
            return Ok(sql);
        }
        let func = method.sql_function().ok_or_else(|| {
            QueryError::validation(format!("Unknown aggregate: {}", method.as_str()))
        })?;
        let attr = query.get_attribute();
        let col = if attr == "*" || attr.is_empty() {
            "*".to_owned()
        } else if attr.chars().all(|c| c.is_ascii_digit()) {
            attr.to_owned()
        } else {
            self.resolve_and_wrap(attr)?
        };
        let alias = query.get_value_or("").php_to_string();
        let mut sql = format!("{func}({col})");
        if !alias.is_empty() {
            sql.push_str(" AS ");
            sql.push_str(&self.quote(&alias)?);
        }
        Ok(sql)
    }

    fn compile_group_by(&mut self, query: &Query) -> Result<String, QueryError> {
        if query.get_method() == Method::GroupByTimeBucket {
            let interval = query.get_value_or("").php_to_string();
            return self.compile_group_by_time_bucket(query.get_attribute(), &interval);
        }
        let columns: Result<Vec<_>, _> = query
            .get_values()
            .iter()
            .map(|v| self.resolve_and_wrap(&v.php_to_string()))
            .collect();
        Ok(columns?.join(", "))
    }

    fn compile_join(&mut self, query: &Query) -> Result<String, QueryError> {
        let type_sql = join_keyword(query.get_method())?;
        let mut table = self.quote(query.get_attribute())?;
        let values = query.get_values();
        if matches!(query.get_method(), Method::CrossJoin | Method::NaturalJoin) {
            let alias = values
                .first()
                .map(QueryValue::php_to_string)
                .unwrap_or_default();
            if !alias.is_empty() {
                table = format!("{table} AS {}", self.quote(&alias)?);
            }
            return Ok(format!("{type_sql} {table}"));
        }
        if values.is_empty() {
            return Ok(format!("{type_sql} {table}"));
        }
        let left_col = values
            .first()
            .map(QueryValue::php_to_string)
            .unwrap_or_default();
        let operator = values
            .get(1)
            .map_or_else(|| "=".to_owned(), QueryValue::php_to_string);
        let right_col = values
            .get(2)
            .map(QueryValue::php_to_string)
            .unwrap_or_default();
        let alias = values
            .get(3)
            .map(QueryValue::php_to_string)
            .unwrap_or_default();
        if !alias.is_empty() {
            table = format!("{table} AS {}", self.quote(&alias)?);
        }
        if !matches!(
            operator.as_str(),
            "=" | "!=" | "<" | ">" | "<=" | ">=" | "<>"
        ) {
            return Err(QueryError::validation(format!(
                "Invalid join operator: {operator}"
            )));
        }
        Ok(format!(
            "{type_sql} {table} ON {} {operator} {}",
            self.resolve_and_wrap(&left_col)?,
            self.resolve_and_wrap(&right_col)?
        ))
    }
}

impl Builder {
    fn compile_spatial_filter(
        &mut self,
        method: Method,
        attribute: &str,
        query: &Query,
    ) -> Result<String, QueryError> {
        let values = query.get_values();
        match method {
            Method::DistanceLessThan
            | Method::DistanceGreaterThan
            | Method::DistanceEqual
            | Method::DistanceNotEqual => self.compile_spatial_distance(method, attribute, values),
            Method::Intersects => {
                self.compile_spatial_predicate("ST_Intersects", attribute, values, false)
            }
            Method::NotIntersects => {
                self.compile_spatial_predicate("ST_Intersects", attribute, values, true)
            }
            Method::Crosses => {
                self.compile_spatial_predicate("ST_Crosses", attribute, values, false)
            }
            Method::NotCrosses => {
                self.compile_spatial_predicate("ST_Crosses", attribute, values, true)
            }
            Method::Overlaps => {
                self.compile_spatial_predicate("ST_Overlaps", attribute, values, false)
            }
            Method::NotOverlaps => {
                self.compile_spatial_predicate("ST_Overlaps", attribute, values, true)
            }
            Method::Touches => {
                self.compile_spatial_predicate("ST_Touches", attribute, values, false)
            }
            Method::NotTouches => {
                self.compile_spatial_predicate("ST_Touches", attribute, values, true)
            }
            Method::Covers => self.compile_spatial_covers(attribute, values, false),
            Method::NotCovers => self.compile_spatial_covers(attribute, values, true),
            Method::SpatialEquals => {
                self.compile_spatial_predicate("ST_Equals", attribute, values, false)
            }
            Method::NotSpatialEquals => {
                self.compile_spatial_predicate("ST_Equals", attribute, values, true)
            }
            _ => Err(QueryError::unsupported(format!(
                "Unsupported filter type: {}",
                method.as_str()
            ))),
        }
    }

    fn compile_spatial_distance(
        &mut self,
        method: Method,
        attribute: &str,
        values: &[QueryValue],
    ) -> Result<String, QueryError> {
        let filter =
            SpatialDistanceFilter::from_tuple(values.first().unwrap_or(&QueryValue::Null))?;
        let wkt = geometry_to_wkt(&filter.geometry);
        let operator = match method {
            Method::DistanceGreaterThan => ">",
            Method::DistanceEqual => "=",
            Method::DistanceNotEqual => "!=",
            _ => "<",
        };
        self.add_binding(wkt, None);
        self.add_binding(filter.distance, None);
        let func = if filter.meters {
            "ST_DISTANCE_SPHERE"
        } else {
            "ST_Distance"
        };
        Ok(format!(
            "{func}({attribute}, ST_GeomFromText(?, 4326)) {operator} ?"
        ))
    }

    #[allow(clippy::unnecessary_wraps)]
    fn compile_spatial_predicate(
        &mut self,
        function: &str,
        attribute: &str,
        values: &[QueryValue],
        not: bool,
    ) -> Result<String, QueryError> {
        let geom = values.first().cloned().unwrap_or(QueryValue::Null);
        let wkt = geometry_to_wkt(&geom);
        self.add_binding(wkt, None);
        let expr = format!("{function}({attribute}, ST_GeomFromText(?, 4326))");
        if not {
            Ok(format!("NOT {expr}"))
        } else {
            Ok(expr)
        }
    }

    fn compile_spatial_covers(
        &mut self,
        attribute: &str,
        values: &[QueryValue],
        not: bool,
    ) -> Result<String, QueryError> {
        let func = if self.kind == DialectKind::Postgres {
            "ST_Covers"
        } else {
            "ST_Contains"
        };
        self.compile_spatial_predicate(func, attribute, values, not)
    }

    fn compile_json_filter(
        &mut self,
        method: Method,
        attribute: &str,
        query: &Query,
    ) -> Result<String, QueryError> {
        let values = query.get_values();
        match method {
            Method::JsonContains => self.compile_json_contains(attribute, values, false),
            Method::JsonNotContains => self.compile_json_contains(attribute, values, true),
            Method::JsonOverlaps => self.compile_json_overlaps(attribute, values),
            Method::JsonPath => self.compile_json_path(attribute, values),
            other => Err(QueryError::unsupported(format!(
                "Unsupported filter type: {}",
                other.as_str()
            ))),
        }
    }

    #[allow(clippy::unnecessary_wraps)]
    fn compile_json_contains(
        &mut self,
        attribute: &str,
        values: &[QueryValue],
        not: bool,
    ) -> Result<String, QueryError> {
        let encoded = values
            .first()
            .map_or_else(|| "null".to_owned(), |v| v.to_json().to_string());
        self.add_binding(encoded, None);
        let expr = match self.kind {
            DialectKind::Postgres => format!("{attribute} @> ?::jsonb"),
            DialectKind::Sqlite => {
                format!("EXISTS (SELECT 1 FROM json_each({attribute}) WHERE value = ?)")
            }
            _ => format!("JSON_CONTAINS({attribute}, ?)"),
        };
        if not {
            Ok(format!("NOT {expr}"))
        } else {
            Ok(expr)
        }
    }

    #[allow(clippy::unnecessary_wraps)]
    fn compile_json_overlaps(
        &mut self,
        attribute: &str,
        values: &[QueryValue],
    ) -> Result<String, QueryError> {
        let encoded = values
            .first()
            .map_or_else(|| "null".to_owned(), |v| v.to_json().to_string());
        self.add_binding(encoded, None);
        Ok(format!("JSON_OVERLAPS({attribute}, ?)"))
    }

    #[allow(clippy::unnecessary_wraps)]
    fn compile_json_path(
        &mut self,
        attribute: &str,
        values: &[QueryValue],
    ) -> Result<String, QueryError> {
        let path = values
            .first()
            .map(QueryValue::php_to_string)
            .unwrap_or_default();
        let operator = values
            .get(1)
            .map(QueryValue::php_to_string)
            .unwrap_or_default();
        let value = values.get(2).cloned().unwrap_or(QueryValue::Null);
        self.add_binding(path, None);
        self.add_binding(value, None);
        Ok(format!("JSON_EXTRACT({attribute}, ?) {operator} ?"))
    }

    fn compile_array_filter(
        &mut self,
        method: Method,
        attribute: &str,
        query: &Query,
    ) -> Result<String, QueryError> {
        let values = query.get_values();
        match method {
            Method::Contains | Method::ContainsAny => {
                self.compile_json_overlaps(attribute, &[QueryValue::Array(values.to_vec())])
            }
            Method::NotContains => {
                let expr =
                    self.compile_json_overlaps(attribute, &[QueryValue::Array(values.to_vec())])?;
                Ok(format!("NOT {expr}"))
            }
            Method::ContainsAll => {
                self.compile_json_contains(attribute, &[QueryValue::Array(values.to_vec())], false)
            }
            _ => self.compile_filter(query),
        }
    }
}

fn join_keyword(method: Method) -> Result<&'static str, QueryError> {
    Ok(match method {
        Method::Join => "JOIN",
        Method::LeftJoin => "LEFT JOIN",
        Method::RightJoin => "RIGHT JOIN",
        Method::CrossJoin => "CROSS JOIN",
        Method::FullOuterJoin => "FULL OUTER JOIN",
        Method::NaturalJoin => "NATURAL JOIN",
        other => {
            return Err(QueryError::unsupported(format!(
                "Unsupported join type: {}",
                other.as_str()
            )));
        }
    })
}

fn append_if_not_empty(parts: &mut Vec<String>, fragment: String) {
    if !fragment.is_empty() {
        parts.push(fragment);
    }
}

fn geometry_to_wkt(value: &QueryValue) -> String {
    match value {
        QueryValue::String(s) => s.clone(),
        QueryValue::Array(coords) if coords.len() == 2 => {
            format!(
                "POINT({} {})",
                coords[0].php_to_string(),
                coords[1].php_to_string()
            )
        }
        QueryValue::Array(points) => {
            let mut parts = Vec::new();
            for p in points {
                if let Some(xy) = p.as_array() {
                    if xy.len() >= 2 {
                        parts.push(format!(
                            "{} {}",
                            xy[0].php_to_string(),
                            xy[1].php_to_string()
                        ));
                    }
                }
            }
            format!("POLYGON(({}))", parts.join(", "))
        }
        _ => value.php_to_string(),
    }
}

/// PHP `select(string|array $columns)`.
pub trait IntoSelect {
    fn apply(self, builder: &mut Builder);
}

impl IntoSelect for Vec<String> {
    fn apply(self, builder: &mut Builder) {
        builder.pending_queries.push(Query::select(self));
    }
}

impl IntoSelect for Vec<&str> {
    fn apply(self, builder: &mut Builder) {
        builder.pending_queries.push(Query::select(
            self.into_iter().map(str::to_owned).collect::<Vec<_>>(),
        ));
    }
}

impl<const N: usize> IntoSelect for [&str; N] {
    fn apply(self, builder: &mut Builder) {
        builder.pending_queries.push(Query::select(
            self.into_iter().map(str::to_owned).collect::<Vec<_>>(),
        ));
    }
}

impl IntoSelect for &str {
    fn apply(self, builder: &mut Builder) {
        builder.raw_selects.push(Condition::expr(self));
    }
}

impl IntoSelect for String {
    fn apply(self, builder: &mut Builder) {
        builder.raw_selects.push(Condition::expr(self));
    }
}

#[derive(Debug, Clone, Copy)]
pub struct MySql;
#[derive(Debug, Clone, Copy)]
pub struct MariaDb;
#[derive(Debug, Clone, Copy)]
pub struct PostgreSql;
#[derive(Debug, Clone, Copy)]
pub struct Sqlite;
#[derive(Debug, Clone, Copy)]
pub struct ClickHouse;
#[derive(Debug, Clone, Copy)]
pub struct MongoDb;

impl MySql {
    #[allow(clippy::new_ret_no_self)]
    pub fn new() -> Builder {
        Builder::mysql()
    }
}

impl MariaDb {
    #[allow(clippy::new_ret_no_self)]
    pub fn new() -> Builder {
        Builder::mariadb()
    }
}

impl PostgreSql {
    #[allow(clippy::new_ret_no_self)]
    pub fn new() -> Builder {
        Builder::postgres()
    }
}

impl Sqlite {
    #[allow(clippy::new_ret_no_self)]
    pub fn new() -> Builder {
        Builder::sqlite()
    }
}

impl ClickHouse {
    #[allow(clippy::new_ret_no_self)]
    pub fn new() -> Builder {
        Builder::clickhouse()
    }
}

impl MongoDb {
    #[allow(clippy::new_ret_no_self)]
    pub fn new() -> Builder {
        Builder::mongodb()
    }
}

pub type MySQL = MySql;
pub type MariaDB = MariaDb;
pub type PostgreSQL = PostgreSql;
pub type SQLite = Sqlite;
pub type MongoDB = MongoDb;
