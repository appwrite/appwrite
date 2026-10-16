//! Counts and sums.
//!
//! PHP: `SQL::count()`, `sum()`, `rowsColumn()`, `rangeChecked()`,
//! `configureCountBuilder()`, `qualifySumSelect()`, `fetchAggregate()`,
//! `executeWrappedCount()`, `executeWrappedSum()`, `countOf()`, `sumOf()`,
//! `mainRowQueries()`, `compileRowFilters()`, `onlyNarrowsRows()`,
//! `fetchAggregateRow()`.

use utopia_query::Method;
use utopia_query::builder::{Condition, Statement};
use utopia_query::hook::Filter as _;

use super::Sql;
use super::find::{JoinAlias, filters_per_document, keeps_unmatched_rows};
use super::hook::tenant;
use crate::adapter::Adapter;
use crate::builder::SqlBuilder;
use crate::collection::Collection;
use crate::database;
use crate::error::{Error, Result};
use crate::event::Event;
use crate::permission::PermissionType;
use crate::query::{DEFAULT_ALIAS, Query};
use crate::storage;
use crate::value::{Array, Value};

/// The groups that only combine row conditions (`SQL::ROW_CONDITION_GROUPS`).
const ROW_CONDITION_GROUPS: [Method; 4] = [Method::And, Method::Or, Method::ContainsAll, Method::ElemMatch];

/// `countOf()`: a count row's `sum`.
fn count_of(row: &Array) -> i64 {
    let count = row.get_str("sum").cloned().unwrap_or(Value::Int(0));
    if is_numeric(&count) { crate::value::to_long(&count) } else { 0 }
}

/// `sumOf()`: a sum row's `sum` as the number it is (`$sum + 0`: an
/// integer, or a float for one with a point or an exponent), with SQLite's
/// `INF`, `-INF` and `NAN` read as the doubles they name for
/// `Database::sum()` to refuse.
fn sum_of(row: &Array) -> Value {
    let sum = row.get_str("sum").cloned().unwrap_or(Value::Int(0));
    if is_numeric(&sum) {
        return match &sum {
            Value::Str(s) => match php_std::value::numeric_bytes_ex(s.as_bytes()) {
                Some((php_std::value::Number::Int(i), _)) => Value::Int(i),
                Some((php_std::value::Number::Float(d), _)) => Value::Float(d),
                None => Value::Int(0),
            },
            other => other.clone(),
        };
    }
    let named = match &sum {
        Value::Str(s) => s.as_bytes().to_ascii_uppercase(),
        _ => Vec::new(),
    };
    match named.as_slice() {
        b"INF" => Value::Float(f64::INFINITY),
        b"-INF" => Value::Float(f64::NEG_INFINITY),
        b"NAN" => Value::Float(f64::NAN),
        _ => Value::Int(0),
    }
}

/// `is_numeric()`.
fn is_numeric(value: &Value) -> bool {
    match value {
        Value::Int(_) | Value::Float(_) => true,
        Value::Str(s) => php_std::value::numeric_bytes_ex(s.as_bytes()).is_some(),
        _ => false,
    }
}

impl Sql {
    /// `count()`: how many rows the queries match, at most `max`.
    pub(crate) async fn count(&self, collection: &Collection, queries: Vec<Query>, max: Option<i64>) -> Result<i64> {
        let name = self.filter(collection.id());
        let authorization = self.authorization();
        let roles = authorization.role_names();
        let alias = DEFAULT_ALIAS;

        if super::find::shapes_rows(&queries) {
            let plan = self.plan(collection, queries)?;
            let rows = self.rows_builder(collection, plan, &name, alias, &roles, max).await?;
            return self.execute_wrapped_count(rows, &name).await;
        }

        let queries: Vec<Query> = queries.into_iter().filter(|q| !q.method().is_vector()).collect();
        if super::find::queries_have_joins(&queries) {
            let inner = self.configure_count_builder(collection, queries, &name, alias, &roles, max, None).await?;
            return self.execute_wrapped_count(inner, &name).await;
        }

        let count = || Query::count("1", "sum");
        if queries.is_empty() {
            let row = self.fetch_aggregate(collection, &name, &roles, count(), "1", max, Event::DocumentCount, None);
            return Ok(count_of(&row.await?));
        }

        let queries = self.main_row_queries(queries, collection)?;
        let mut builder = self.simple_builder(&name, alias)?;

        if let Some(filters) = self.compile_row_filters(&mut builder, &queries)? {
            let row = self
                .fetch_aggregate(collection, &name, &roles, count(), "1", max, Event::DocumentCount, Some(filters))
                .await?;
            return Ok(count_of(&row));
        }

        self.apply_filters(&mut builder, &queries, &name, alias).await?;

        if authorization.status() && filters_per_document(collection) {
            let hook = self.permission_hook(&name, roles.clone(), PermissionType::Read.as_str(), storage::UID)?;
            builder.add_hook(hook.into()).map_err(crate::query::query_error)?;
        }

        if max.is_none() && self.only_narrows_rows(&queries) {
            crate::each_builder!(&mut builder, b => { b.count("1", "sum"); });
            return Ok(count_of(&self.fetch_aggregate_row(&mut builder, Event::DocumentCount, &name).await?));
        }

        builder.select_raw("1", Vec::new());
        if let Some(max) = max {
            builder.limit(max);
        }
        self.execute_wrapped_count(builder, &name).await
    }

    /// `sum()`: the sum of an attribute over the rows the queries match,
    /// at most `max` of them.
    pub(crate) async fn sum(
        &self,
        collection: &Collection,
        attribute: &str,
        queries: Vec<Query>,
        max: Option<i64>,
    ) -> Result<Value> {
        let name = self.filter(collection.id());
        let authorization = self.authorization();
        let roles = authorization.role_names();
        let alias = DEFAULT_ALIAS;

        if super::find::shapes_rows(&queries) {
            let column = self.rows_column(attribute, &queries)?;
            let plan = self.plan(collection, queries)?;
            let rows = self.rows_builder(collection, plan, &name, alias, &roles, max).await?;

            let mut outer = self.dialect_builder();
            super::find::from_sub(&mut outer, rows, "table_count");
            let sum = self.range_checked(&format!("SUM({})", self.quote(&column)));
            outer.select_raw(&format!("{sum} AS {}", self.quote("sum")), Vec::new());
            return Ok(sum_of(&self.fetch_aggregate_row(&mut outer, Event::DocumentSum, &name).await?));
        }

        let queries: Vec<Query> = queries.into_iter().filter(|q| !q.method().is_vector()).collect();
        if super::find::queries_have_joins(&queries) {
            let inner =
                self.configure_count_builder(collection, queries, &name, alias, &roles, max, Some(attribute)).await?;
            return self.execute_wrapped_sum(inner, "sum_attr", &name).await;
        }

        let attribute = self.filter(attribute);
        let column = self.quote(&attribute);
        let aggregate = || Query::sum(attribute.as_str(), "sum");

        if queries.is_empty() {
            let row = self
                .fetch_aggregate(collection, &name, &roles, aggregate(), &column, max, Event::DocumentSum, None)
                .await?;
            return Ok(sum_of(&row));
        }

        let queries = self.main_row_queries(queries, collection)?;
        let mut builder = self.simple_builder(&name, alias)?;

        if let Some(filters) = self.compile_row_filters(&mut builder, &queries)? {
            let row = self
                .fetch_aggregate(
                    collection,
                    &name,
                    &roles,
                    aggregate(),
                    &column,
                    max,
                    Event::DocumentSum,
                    Some(filters),
                )
                .await?;
            return Ok(sum_of(&row));
        }

        self.apply_filters(&mut builder, &queries, &name, alias).await?;

        if authorization.status() && filters_per_document(collection) {
            let hook = self.permission_hook(&name, roles.clone(), PermissionType::Read.as_str(), storage::UID)?;
            builder.add_hook(hook.into()).map_err(crate::query::query_error)?;
        }

        if max.is_none() && self.only_narrows_rows(&queries) {
            crate::each_builder!(&mut builder, b => { b.sum(attribute.as_str(), "sum"); });
            return Ok(sum_of(&self.fetch_aggregate_row(&mut builder, Event::DocumentSum, &name).await?));
        }

        builder.select(vec![attribute.clone()]);
        if let Some(max) = max {
            builder.limit(max);
        }
        self.execute_wrapped_sum(builder, &attribute, &name).await
    }

    /// The column grouped rows hold a `groupBy` attribute in, or distinct
    /// rows hold a selected attribute in (`rowsColumn()`).
    ///
    /// Fails ([`crate::Kind::Query`]) when the attribute is not one of the
    /// groups or selections.
    fn rows_column(&self, attribute: &str, queries: &[Query]) -> Result<String> {
        let grouped = queries.iter().any(|q| q.method().is_aggregate() || q.method() == Method::GroupBy);
        let wanted = if grouped { Method::GroupBy } else { Method::Select };
        for query in queries {
            if query.method() != wanted {
                continue;
            }
            let values: Vec<String> =
                query.values().values().map(|v| crate::value::to_text(&crate::value::from_query(v))).collect();
            let Some(index) = values.iter().position(|v| v == attribute) else { continue };

            let (prefix, key) = match attribute.split_once('.') {
                Some((prefix, key)) => (Some(prefix), key),
                None => (None, attribute),
            };
            let column = self.filter(storage::column(key));

            if grouped {
                return Ok(self
                    .qualified_group_names(&values)
                    .into_iter()
                    .find(|(i, _)| *i == index)
                    .map_or(column, |(_, name)| name));
            }
            return Ok(match prefix {
                Some(prefix) => format!("{prefix}.{column}"),
                None => column,
            });
        }
        Err(Error::query(format!(
            "Invalid query: sum() over {} rows adds up one of their {} attributes, and {attribute} is not one of them",
            if grouped { "grouped" } else { "distinct" },
            if grouped { "groupBy" } else { "selected" },
        )))
    }

    /// A floating expression the engine raises an error for when it
    /// overflows a double, rather than answer a wrong number: MariaDB and
    /// MySQL check it once zero is added (`rangeChecked()`). The dialect
    /// builder checks the aggregates it compiles the same way.
    fn range_checked(&self, expression: &str) -> String {
        if self.engine.is_mariadb_family() { format!("{expression} + 0") } else { expression.to_owned() }
    }

    /// `configureCountBuilder()`: the rows a joined count or sum reads, one
    /// per joined row (`1`, or the summed column as `sum_attr`).
    #[allow(clippy::too_many_arguments)]
    async fn configure_count_builder(
        &self,
        collection: &Collection,
        queries: Vec<Query>,
        name: &str,
        alias: &str,
        roles: &[String],
        max: Option<i64>,
        sum_attribute: Option<&str>,
    ) -> Result<SqlBuilder> {
        let authorize = self.authorization().status();
        let mut adapter_filter_queries = Vec::new();
        let mut filter_queries = Vec::new();
        for query in queries {
            if self.is_adapter_filter_query(&query) {
                adapter_filter_queries.push(query);
            } else {
                filter_queries.push(query);
            }
        }
        let mut queries = filter_queries;

        let joins = self.remap_join_queries(&mut queries)?;
        let unindexed = self.unindexed_joins(collection, &queries, &joins);
        let select = match sum_attribute {
            None => "1".to_owned(),
            Some(attribute) => {
                format!("{} AS {}", self.qualify_sum_select(attribute, &joins, collection)?, self.quote("sum_attr"))
            }
        };

        let preserving = keeps_unmatched_rows(&queries);

        if self.needs_full_outer_join_emulation(&queries) {
            let (mut left_queries, mut right_queries) = self.emulate_full_outer_join(&queries, alias)?;
            let left_preserving = keeps_unmatched_rows(&left_queries);

            let mut left = self.new_builder(name, alias, left_preserving, Vec::new(), unindexed.clone())?;
            left.select_raw(&select, Vec::new());
            self.apply_find_filters(
                &mut left,
                collection,
                &mut left_queries,
                &joins,
                &adapter_filter_queries,
                name,
                alias,
                roles,
                PermissionType::Read,
                authorize,
            )
            .await?;

            let mut right = self.new_builder(name, alias, true, Vec::new(), unindexed)?;
            right.select_raw(&select, Vec::new());
            self.apply_find_filters(
                &mut right,
                collection,
                &mut right_queries,
                &joins,
                &adapter_filter_queries,
                name,
                alias,
                roles,
                PermissionType::Read,
                authorize,
            )
            .await?;

            union_all(&mut left, &mut right)?;
            if let Some(max) = max {
                self.apply_find_page(
                    &mut left,
                    &[],
                    &[],
                    Some(max),
                    None,
                    utopia_query::CursorDirection::After,
                    true,
                    &[],
                )?;
            }
            return Ok(left);
        }

        let mut builder = self.new_builder(name, alias, preserving, Vec::new(), unindexed)?;
        builder.select_raw(&select, Vec::new());
        self.apply_find_filters(
            &mut builder,
            collection,
            &mut queries,
            &joins,
            &adapter_filter_queries,
            name,
            alias,
            roles,
            PermissionType::Read,
            authorize,
        )
        .await?;
        if let Some(max) = max {
            builder.limit(max);
        }
        Ok(builder)
    }

    /// `qualifySumSelect()`: the summed attribute as a table-qualified
    /// column.
    fn qualify_sum_select(&self, attribute: &str, joins: &[JoinAlias], collection: &Collection) -> Result<String> {
        let mut alias_set: Vec<String> = joins.iter().map(|j| j.alias.clone()).collect();
        alias_set.push(DEFAULT_ALIAS.to_owned());
        let main: Vec<String> = collection.attributes()?.iter().map(|a| a.key.clone()).collect();

        let mut qualified = self.qualify_dotted_attribute(attribute, &alias_set, &main);
        if !qualified.contains('.') {
            qualified = format!("{DEFAULT_ALIAS}.{qualified}");
        }
        let (prefix, name) = qualified.split_once('.').unwrap_or((qualified.as_str(), ""));
        Ok(format!("{}.{}", self.quote(&self.filter(prefix)), self.quote(&self.filter(name))))
    }

    /// `fetchAggregate()`: the row of a count or sum written out: the
    /// filters, the tenant and the permission condition, each as its hook
    /// writes it.
    #[allow(clippy::too_many_arguments)]
    async fn fetch_aggregate(
        &self,
        collection: &Collection,
        name: &str,
        roles: &[String],
        aggregate: Query,
        column: &str,
        max: Option<i64>,
        event: Event,
        filters: Option<Condition>,
    ) -> Result<Array> {
        let alias = DEFAULT_ALIAS;
        let mut conditions = Vec::new();
        let mut bindings = Vec::new();

        if let Some(filters) = filters {
            conditions.push(filters.expression);
            bindings.extend(filters.bindings);
        }

        if self.shared_tables() {
            let condition = tenant::Filter::new(vec![crate::value::to_query(&self.current_tenant())])
                .with_metadata_collection(database::METADATA)
                .with_collection(name)
                .with_quote(self.identifier_quote())
                .filter(alias)
                .map_err(crate::query::query_error)?;
            conditions.push(condition.expression);
            bindings.extend(condition.bindings);
        }

        if self.authorization().status() && filters_per_document(collection) {
            let condition = self
                .permission_hook(name, roles.to_vec(), PermissionType::Read.as_str(), storage::UID)?
                .filter(alias)
                .map_err(crate::query::query_error)?;
            conditions.push(condition.expression);
            bindings.extend(condition.bindings);
        }

        let mut rows = format!("{} AS {}", self.table(name), self.quote(alias));
        if !conditions.is_empty() {
            rows.push_str(" WHERE ");
            rows.push_str(&conditions.join(" AND "));
        }

        let selection = self.dialect_builder().compile_aggregate(&aggregate).map_err(crate::query::query_error)?;
        let sql = match max {
            None => format!("SELECT {selection} FROM {rows}"),
            Some(max) => {
                bindings.push(utopia_query::Value::Int(max));
                format!(
                    "SELECT {selection} FROM (SELECT {column} FROM {rows} LIMIT ?) AS {}",
                    self.quote("table_count")
                )
            }
        };

        let rows = self.run_select(&Statement::new(sql, bindings), event, name).await?;
        Ok(rows.into_iter().next().unwrap_or_default())
    }

    /// `executeWrappedCount()`: the rows of a builder, counted.
    async fn execute_wrapped_count(&self, inner: SqlBuilder, collection: &str) -> Result<i64> {
        let mut outer = self.dialect_builder();
        super::find::from_sub(&mut outer, inner, "table_count");
        crate::each_builder!(&mut outer, b => { b.count("1", "sum"); });
        Ok(count_of(&self.fetch_aggregate_row(&mut outer, Event::DocumentCount, collection).await?))
    }

    /// `executeWrappedSum()`: a column of a builder's rows, summed.
    async fn execute_wrapped_sum(&self, inner: SqlBuilder, attribute: &str, collection: &str) -> Result<Value> {
        let mut outer = self.dialect_builder();
        super::find::from_sub(&mut outer, inner, "table_count");
        crate::each_builder!(&mut outer, b => { b.sum(attribute, "sum"); });
        Ok(sum_of(&self.fetch_aggregate_row(&mut outer, Event::DocumentSum, collection).await?))
    }

    /// `mainRowQueries()`: copies of the queries naming each dotted
    /// attribute by its column.
    pub(crate) fn main_row_queries(&self, mut queries: Vec<Query>, collection: &Collection) -> Result<Vec<Query>> {
        self.remap_dotted_query_attributes(&mut queries, &[], collection)?;
        Ok(queries)
    }

    /// `compileRowFilters()`: the queries as the builder compiles them into
    /// its `WHERE`, when every one only narrows the rows; `None` when the
    /// statement has to be built.
    fn compile_row_filters(&self, builder: &mut SqlBuilder, queries: &[Query]) -> Result<Option<Condition>> {
        if !self.only_narrows_rows(queries) || queries.iter().any(|q| self.is_adapter_filter_query(q)) {
            return Ok(None);
        }
        builder.compile_filters(queries).map(Some).map_err(crate::query::query_error)
    }

    /// `onlyNarrowsRows()`: whether every query only narrows the rows.
    fn only_narrows_rows(&self, queries: &[Query]) -> bool {
        queries.iter().all(|query| {
            let method = query.method();
            method.is_filter()
                || method.is_spatial()
                || method.is_json()
                || ROW_CONDITION_GROUPS.contains(&method)
                || self.is_adapter_filter_query(query)
        })
    }

    /// `fetchAggregateRow()`: the first row a builder reads.
    async fn fetch_aggregate_row(&self, builder: &mut SqlBuilder, event: Event, collection: &str) -> Result<Array> {
        Ok(self.execute_select(builder, event, collection).await?.into_iter().next().unwrap_or_default())
    }
}

/// `$left->unionAll($right)`.
fn union_all(left: &mut SqlBuilder, right: &mut SqlBuilder) -> Result<()> {
    super::find::union(left, right, true)
}

#[cfg(test)]
mod tests {
    use super::sum_of;
    use crate::value::{Array, Str, Value};

    fn sent(sum: Option<&str>) -> Value {
        let mut row = Array::new();
        row.set(crate::value::ArrayKey::from("sum"), sum.map_or(Value::Null, |s| Value::Str(Str::from(s))));
        sum_of(&row)
    }

    // PHP: tests/unit/Adapter/SumResultTest.php, sums(): `yield 'double with an exponent' => ['1e308', 1e308]`
    // and the rest of the sums MariaDB, MySQL and SQLite send.
    #[test]
    fn a_sum_is_read_as_the_number_the_engine_sent() {
        assert_eq!(sent(Some("2785")), Value::Int(2785));
        assert_eq!(sent(Some("10.50")), Value::Float(10.5));
        assert_eq!(sent(Some("1e308")), Value::Float(1e308));
        assert_eq!(sent(Some("-1.5e300")), Value::Float(-1.5e300));
        assert_eq!(sent(Some("18446744073709551614")), Value::Float(18446744073709551614.0));
        assert_eq!(sent(None), Value::Int(0));
        assert_eq!(sent(Some("INF")), Value::Float(f64::INFINITY));
        assert_eq!(sent(Some("-INF")), Value::Float(f64::NEG_INFINITY));
        assert_eq!(sent(Some("Inf")), Value::Float(f64::INFINITY));
        assert_eq!(sent(Some("overflow")), Value::Int(0));
    }

    // PHP: SumResultTest::testANotANumberSumIsReadAsNan, `$this->assertNan($this->sumOf('NAN'))`.
    #[test]
    fn a_not_a_number_sum_is_read_as_nan() {
        assert!(matches!(sent(Some("NAN")), Value::Float(f) if f.is_nan()));
    }
}
