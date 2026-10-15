//! Reads: `find()`, its joins, cursors and pages.
//!
//! PHP: `SQL::find()` and its read machinery (`configureFindBuilder()`,
//! `applyFilters()`, `applyFindFilters()`, `populationStatistics()`,
//! `bitwiseInputs()`, `bitwiseResults()`, `bitwiseResultName()`,
//! `filtersPerDocument()`, the join document security), the `if
//! ($this->queriesHaveJoins($queries))` branch of `getDocument()`, and
//! `SQLite::find()`.
//!
//! | Module | PHP |
//! |---|---|
//! | `projection` | selections, `alias.*`, dotted attributes, group names |
//! | `join` | join remapping, full outer join emulation, unindexed joins, join orders |
//! | `cursor` | cursors, pages, bounded pages, vector orders and cursors |
//! | `search` | `isAdapterFilterQuery()`, `compileAdapterFilter()` (SQLite's FTS5 searches) |

use std::sync::Arc;

use utopia_query::builder::AnyBuilder;
use utopia_query::hook::{Filter as _, Hook};
use utopia_query::{CursorDirection, Method, OrderDirection};

use super::hook::column::AllowNull;
use super::hook::{join as join_hook, permission, tenant};
use super::row::remap_row;
use super::{Engine, Sql};
use crate::adapter::{Adapter, Find};
use crate::builder::SqlBuilder;
use crate::collection::Collection;
use crate::database;
use crate::document::{self, Document};
use crate::error::{Error, Result};
use crate::event::Event;
use crate::permission::PermissionType;
use crate::query::{DEFAULT_ALIAS, Query};
use crate::storage;
use crate::value::{Array, ArrayKey, Value};

mod cursor;
mod join;
pub(crate) mod projection;
mod search;

/// The rows of an emulated full outer join, read as one derived table
/// (`SQL::FOJ_ROWS_ALIAS`).
pub(crate) const FOJ_ROWS_ALIAS: &str = "foj_rows";

/// The internal attributes `alias.*` returns next to the joined `$id`
/// (`SQL::JOINED_ROW_INTERNALS`).
const JOINED_ROW_INTERNALS: [&str; 4] =
    [document::SEQUENCE, document::CREATED_AT, document::UPDATED_AT, document::PERMISSIONS];

/// The limit of an offset without one: MariaDB, MySQL and SQLite accept
/// `OFFSET` only after a `LIMIT` (`SQL::UNBOUNDED_LIMIT`).
pub(crate) const UNBOUNDED_LIMIT: i64 = i64::MAX;

/// The prefix of the columns the halves of an emulated full outer join
/// project for its aggregation (`SQL::FOJ_COLUMN_PREFIX`).
const FOJ_COLUMN_PREFIX: &str = "$foj_col_";

/// Where the rows of an emulated full outer join come from
/// (`SQL::UNPAIRED_MAIN_ROWS`, `PAIRED_ROWS`, `UNPAIRED_JOINED_ROWS`).
const UNPAIRED_MAIN_ROWS: u64 = 1;
const PAIRED_ROWS: u64 = 2;
const UNPAIRED_JOINED_ROWS: u64 = 4;

/// The groups that only combine row conditions (`SQL::ROW_CONDITION_GROUPS`).
const ROW_CONDITION_GROUPS: [Method; 4] = [Method::And, Method::Or, Method::ContainsAll, Method::ElemMatch];

/// The bitwise aggregates (`SQL::BITWISE_AGGREGATES`).
const BITWISE_AGGREGATES: [Method; 3] = [Method::BitAnd, Method::BitOr, Method::BitXor];

/// The methods whose values are columns (`SQL::COLUMN_LIST_METHODS`).
const COLUMN_LIST_METHODS: [Method; 3] = [Method::GroupBy, Method::Exists, Method::NotExists];

/// The prefix of the input count of a bitwise aggregate (`SQL::BITWISE_INPUTS`).
const BITWISE_INPUTS: &str = "$inputs:";

/// The aggregates SQLite has no function for (`SQLite::MISSING_AGGREGATES`).
const MISSING_AGGREGATES: [Method; 9] = [
    Method::Stddev,
    Method::StddevPop,
    Method::StddevSamp,
    Method::Variance,
    Method::VarPop,
    Method::VarSamp,
    Method::BitAnd,
    Method::BitOr,
    Method::BitXor,
];

/// Set on the collection of a join read when the caller holds the
/// collection-level permission (`Database::COLLECTION_GRANTED`).
pub(crate) const COLLECTION_GRANTED: &str = "collectionGranted";

/// Each joined table, mapped to whether its rows are filtered per document
/// (`Database::JOIN_DOCUMENT_SECURITY`).
pub(crate) const JOIN_DOCUMENT_SECURITY: &str = "joinDocumentSecurity";

/// Each joined collection, mapped to the attributes a join without a select
/// returns under its alias (`Database::JOIN_ATTRIBUTES`).
pub(crate) const JOIN_ATTRIBUTES: &str = "joinAttributes";

/// Under shared tables, each joined collection mapped to the attributes its
/// key or unique indexes lead with (`Database::JOIN_INDEXED`).
pub(crate) const JOIN_INDEXED: &str = "joinIndexed";

/// A joined collection and the alias a read joins it under.
///
/// PHP: `Utopia\Database\Adapter\SQL\JoinAlias`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub(crate) struct JoinAlias {
    /// The collection, as the join query names it.
    pub table: String,
    /// The alias.
    pub alias: String,
}

/// The queries of a `find()` or a row count split by how the statement
/// reads them (`SQL\Plan`).
pub(crate) struct Plan {
    /// Every query but the vector and adapter filter ones, the join columns
    /// qualified.
    pub(crate) queries: Vec<Query>,
    /// The vector queries.
    pub(crate) vector_queries: Vec<Query>,
    /// The queries the adapter compiles itself (SQLite's searches).
    pub(crate) adapter_filter_queries: Vec<Query>,
    /// The joins, by alias.
    pub(crate) joins: Vec<JoinAlias>,
    /// The join aliases a join cannot look its rows up by an index for.
    pub(crate) unindexed: Vec<String>,
    /// Whether the queries aggregate or group.
    pub(crate) has_aggregation: bool,
    /// Whether the queries join.
    pub(crate) has_joins: bool,
    /// Whether the queries read distinct rows.
    pub(crate) has_distinct: bool,
    /// Whether a right or full outer join keeps unmatched joined rows.
    pub(crate) has_preserving_outer_join: bool,
    /// Whether the engine emulates a full outer join.
    pub(crate) emulates_full_outer_join: bool,
}

/// Whether the queries read rows other than one per matching document:
/// groups, aggregates or distinct values (`shapesRows()`).
pub(crate) fn shapes_rows(queries: &[Query]) -> bool {
    queries.iter().any(|q| {
        let method = q.method();
        method.is_aggregate() || method == Method::GroupBy || method == Method::Distinct
    })
}

/// A SQL fragment with its positional bindings.
///
/// PHP: `Utopia\Database\Adapter\SQL\Expression`.
#[derive(Debug, Clone, Default, PartialEq)]
pub(crate) struct Expression {
    /// The SQL.
    pub sql: String,
    /// The values of its placeholders.
    pub bindings: Vec<utopia_query::Value>,
}

impl Expression {
    fn new(sql: impl Into<String>, bindings: Vec<utopia_query::Value>) -> Self {
        Expression { sql: sql.into(), bindings }
    }
}

/// `queriesHaveJoins()`: whether any query is a join.
pub(crate) fn queries_have_joins(queries: &[Query]) -> bool {
    queries.iter().any(|q| q.method().is_join())
}

/// The aliases of the joins.
fn aliases_of(joins: &[JoinAlias]) -> Vec<String> {
    joins.iter().map(|j| j.alias.clone()).collect()
}

/// The builder's queries of database queries.
fn inners(queries: &[Query]) -> Vec<utopia_query::Query> {
    queries.iter().map(|q| (**q).clone()).collect()
}

/// A base query as a database query.
fn wrap(query: utopia_query::Query) -> Query {
    Query::from(query)
}

/// `anyOf()`: the condition alone, or `or()` of them.
fn any_of(mut conditions: Vec<Query>) -> Query {
    if conditions.len() == 1 {
        return conditions.remove(0);
    }
    wrap(utopia_query::Query::or(Query::into_inners(conditions)))
}

/// `allOf()`: the condition alone, or `and()` of them.
fn all_of(mut conditions: Vec<Query>) -> Query {
    if conditions.len() == 1 {
        return conditions.remove(0);
    }
    wrap(utopia_query::Query::and(Query::into_inners(conditions)))
}

/// `is_numeric()`.
fn is_numeric(value: &Value) -> bool {
    match value {
        Value::Int(_) | Value::Float(_) => true,
        Value::Str(s) => php_std::value::numeric_bytes_ex(s.as_bytes()).is_some(),
        _ => false,
    }
}

/// `is_numeric()` of a string.
fn is_numeric_str(value: &str) -> bool {
    php_std::value::numeric_bytes_ex(value.as_bytes()).is_some()
}

/// The text of a query value (`(string)` of a scalar).
fn text(value: &utopia_query::Value) -> String {
    crate::value::to_text(&crate::value::from_query(value))
}

/// An owned PHP array key.
fn key(name: &str) -> ArrayKey {
    ArrayKey::from(name)
}

/// The string of an array key.
fn key_text(key: php_std::types::KeyRef<'_>) -> String {
    match key {
        php_std::types::KeyRef::Int(i) => i.to_string(),
        php_std::types::KeyRef::Str(s) => String::from_utf8_lossy(s).into_owned(),
    }
}

/// Sets `key` in an ordered map kept as pairs, in place when it is
/// already there (a PHP array assignment).
fn assoc_set<V>(map: &mut Vec<(String, V)>, key: String, value: V) {
    match map.iter_mut().find(|(k, _)| *k == key) {
        Some(entry) => entry.1 = value,
        None => map.push((key, value)),
    }
}

/// A builder as a subquery.
fn boxed(builder: SqlBuilder) -> Box<dyn AnyBuilder> {
    match builder {
        SqlBuilder::MySql(b) => Box::new(b),
        SqlBuilder::MariaDb(b) => Box::new(b),
        SqlBuilder::PostgreSql(b) => Box::new(b),
        SqlBuilder::Sqlite(b) => Box::new(b),
    }
}

/// `$builder->fromSub($subquery, $alias)`.
pub(crate) fn from_sub(builder: &mut SqlBuilder, subquery: SqlBuilder, alias: &str) {
    let subquery = boxed(subquery);
    crate::each_builder!(builder, b => { b.from_sub_boxed(subquery, alias); });
}

/// `$left->union($right)` / `$left->unionAll($right)`.
pub(crate) fn union(left: &mut SqlBuilder, right: &mut SqlBuilder, all: bool) -> Result<()> {
    let right: &mut dyn AnyBuilder = match right {
        SqlBuilder::MySql(b) => b,
        SqlBuilder::MariaDb(b) => b,
        SqlBuilder::PostgreSql(b) => b,
        SqlBuilder::Sqlite(b) => b,
    };
    crate::each_builder!(left, b => {
        if all { b.union_all(right).map(|_| ()) } else { b.union(right).map(|_| ()) }
    })
    .map_err(crate::query::query_error)
}

/// `$builder->whereRaw($sql, $bindings)`.
fn where_raw(builder: &mut SqlBuilder, sql: &str, bindings: Vec<utopia_query::Value>) {
    crate::each_builder!(builder, b => { b.where_raw(sql, bindings); });
}

/// `$builder->addHook($hook)`.
fn add_hook(builder: &mut SqlBuilder, hook: Hook) -> Result<()> {
    builder.add_hook(hook).map(|_| ()).map_err(crate::query::query_error)
}

impl Sql {
    /// `find()` (and `SQLite::find()`, which refuses the aggregates SQLite
    /// has no function for): the documents a read returns, or the rows of
    /// an aggregation.
    pub(crate) async fn find(
        &self,
        collection: &Collection,
        queries: Vec<Query>,
        options: Find,
    ) -> Result<Vec<Document>> {
        if self.engine == Engine::Sqlite {
            for query in &queries {
                let method = query.method();
                if MISSING_AGGREGATES.contains(&method) {
                    return Err(Error::query(format!(
                        "Aggregate {} is not supported by this adapter",
                        method.as_str()
                    )));
                }
            }
        }

        let Find { limit, offset, mut order_attributes, order_types, mut cursor, cursor_direction, for_permission } =
            options;
        let name = self.filter(collection.id());
        let authorization = self.authorization();
        let authorize = authorization.status();
        let roles = authorization.role_names();
        let alias = DEFAULT_ALIAS;

        // Fast path: a plain list ordered by `$sequence`.
        if queries.is_empty()
            && cursor.is_empty()
            && !authorize
            && !self.shared_tables()
            && order_attributes.len() == 1
            && order_attributes[0] == document::SEQUENCE
            && order_types.first().is_none_or(|t| *t == OrderDirection::Asc)
            && cursor_direction == CursorDirection::After
        {
            return self.find_page_fast(&name, alias, limit, offset).await;
        }

        let Plan {
            mut queries,
            mut vector_queries,
            adapter_filter_queries,
            joins,
            unindexed,
            has_aggregation,
            has_joins,
            has_distinct,
            has_preserving_outer_join,
            emulates_full_outer_join,
        } = self.plan(collection, queries)?;

        if !joins.is_empty() {
            (order_attributes, cursor) = self.qualify_joined_orders(order_attributes, cursor, collection, &joins)?;
        }

        let join_aliases = aliases_of(&joins);

        let results = if emulates_full_outer_join && has_aggregation {
            let (mut aggregation, columns, qualified_groups) = self
                .full_outer_join_aggregation(
                    collection,
                    &mut queries,
                    &joins,
                    has_distinct,
                    &adapter_filter_queries,
                    &name,
                    alias,
                    &roles,
                    for_permission,
                    &order_attributes,
                    &order_types,
                    limit,
                    offset,
                    &cursor,
                    cursor_direction,
                    authorize,
                )
                .await?;
            let rows = self.execute_select(&mut aggregation, Event::DocumentFind, &name).await?;
            self.full_outer_join_result_names(rows, &columns, &qualified_groups)
        } else if emulates_full_outer_join {
            let mut union = self
                .full_outer_join_union(
                    collection,
                    &queries,
                    &joins,
                    &unindexed,
                    has_aggregation,
                    has_distinct,
                    &adapter_filter_queries,
                    &name,
                    alias,
                    &roles,
                    for_permission,
                    &order_attributes,
                    &order_types,
                    limit,
                    offset,
                    &cursor,
                    cursor_direction,
                    authorize,
                )
                .await?;
            self.execute_select(&mut union, Event::DocumentFind, &name).await?
        } else {
            let bound = if has_joins
                && !has_aggregation
                && !has_distinct
                && vector_queries.is_empty()
                && self.bounds_joined_sort()
            {
                self.bounded_page(
                    collection,
                    &queries,
                    &adapter_filter_queries,
                    &joins,
                    &order_attributes,
                    &order_types,
                    limit,
                    offset,
                    &cursor,
                )?
            } else {
                None
            };

            let mut builder = self.new_builder(&name, alias, has_preserving_outer_join, Vec::new(), unindexed)?;
            let has_selection_projection = match &bound {
                Some(bound) => {
                    let mut kept = bound.without_searches(&queries);
                    let adapter_kept = bound.without_adapter_searches(&adapter_filter_queries);
                    self.configure_find_builder(
                        &mut builder,
                        collection,
                        &mut kept,
                        &joins,
                        has_aggregation,
                        has_distinct,
                        &adapter_kept,
                        &name,
                        alias,
                        &roles,
                        for_permission,
                        true,
                        &order_attributes,
                        authorize,
                    )
                    .await?
                }
                None => {
                    self.configure_find_builder(
                        &mut builder,
                        collection,
                        &mut queries,
                        &joins,
                        has_aggregation,
                        has_distinct,
                        &adapter_filter_queries,
                        &name,
                        alias,
                        &roles,
                        for_permission,
                        true,
                        &order_attributes,
                        authorize,
                    )
                    .await?
                }
            };

            let vector_distance = self.vector_distance(&mut builder, vector_queries.first_mut(), alias);

            if let Some(vector) = vector_distance.as_ref().filter(|_| !cursor.is_empty() && !has_distinct) {
                let distance = cursor.get_str(document::DISTANCE).cloned().unwrap_or(Value::Null);
                if !is_numeric(&distance) {
                    return Err(Error::query("Vector cursor is missing its distance"));
                }
                if order_attributes.is_empty() {
                    return Err(Error::query("Vector cursor requires a unique order attribute"));
                }
                let condition = self.vector_cursor_condition(
                    vector,
                    distance.to_double(),
                    &order_attributes,
                    &order_types,
                    &cursor,
                    cursor_direction,
                    alias,
                    &join_aliases,
                    has_joins,
                )?;
                where_raw(&mut builder, &condition.sql, condition.bindings);
            }

            if vector_distance.is_none() || has_distinct {
                self.apply_find_cursor(
                    &mut builder,
                    &order_attributes,
                    &order_types,
                    &cursor,
                    cursor_direction,
                    &join_aliases,
                    has_joins,
                )?;
            }

            if let Some(vector) = vector_distance.as_ref().filter(|_| !has_aggregation && !has_distinct) {
                let mut order = vector.sql.clone();
                if !cursor.is_empty() && cursor_direction == CursorDirection::Before {
                    order.push_str(" DESC");
                }
                crate::each_builder!(&mut builder, b => { b.order_by_raw(order.as_str(), vector.bindings.clone()); });
                if !has_selection_projection {
                    builder.select(vec!["*".to_owned()]);
                }
                builder.select_raw(
                    &format!("{} AS {}", self.readable_distance(&vector.sql), self.quote(storage::DISTANCE)),
                    vector.bindings.clone(),
                );
            }

            if let Some(bound) = &bound {
                self.join_from_bounded_page(
                    &mut builder,
                    collection,
                    bound,
                    &cursor,
                    cursor_direction,
                    &join_aliases,
                    &name,
                    alias,
                    &roles,
                    for_permission,
                    authorize,
                )
                .await?;
            }

            self.apply_find_page(
                &mut builder,
                &order_attributes,
                &order_types,
                limit,
                offset,
                cursor_direction,
                false,
                &join_aliases,
            )?;
            self.execute_select(&mut builder, Event::DocumentFind, &name).await?
        };

        if has_aggregation {
            let inputs = bitwise_inputs(&queries);
            return results.into_iter().map(|row| Document::from_row(self.bitwise_results(row, &inputs))).collect();
        }

        let mut documents = Vec::with_capacity(results.len());
        for mut row in results {
            remap_row(&mut row);
            documents.push(Document::from_row(row)?);
        }
        if cursor_direction == CursorDirection::Before {
            documents.reverse();
        }
        Ok(documents)
    }

    /// `find()`'s fast path: `SELECT *` ordered by `_id`, with the page
    /// written out.
    async fn find_page_fast(
        &self,
        name: &str,
        alias: &str,
        limit: Option<i64>,
        offset: Option<i64>,
    ) -> Result<Vec<Document>> {
        let internal_order = self.quote(storage::column(document::SEQUENCE));
        let offset = offset.filter(|o| *o > 0);
        let page_limit = limit.or(offset.map(|_| UNBOUNDED_LIMIT));
        let limit_clause = page_limit.map(|l| format!(" LIMIT {l}")).unwrap_or_default();
        let offset_clause = match (offset, page_limit) {
            (Some(o), _) => format!(" OFFSET {o}"),
            (None, Some(_)) => " OFFSET 0".to_owned(),
            (None, None) => String::new(),
        };
        let sql = format!(
            "SELECT * FROM {} AS {} ORDER BY {internal_order} ASC{limit_clause}{offset_clause}",
            self.table(name),
            self.quote(alias)
        );

        let run = async {
            let mut prepared = self.prepare_statement(&sql, Some(Event::DocumentFind)).await?;
            self.describe_statement(&mut prepared, Array::new(), name);
            self.execute(&mut prepared).await?;
            let rows = prepared.statement.fetch_all_assoc();
            prepared.statement.close_cursor();
            Ok::<_, Error>(rows)
        };
        let rows = run.await.map_err(|e| self.process_exception(e))?;

        let mut documents = Vec::with_capacity(rows.len());
        for mut row in rows {
            remap_row(&mut row);
            documents.push(Document::from_row(row)?);
        }
        Ok(documents)
    }

    /// `getDocument()`'s read of a document with joins: the rows of the
    /// document's joined read, the first row by each join's `$sequence`,
    /// before `remapRow()`. The caller refuses a locked read with joins.
    #[allow(dead_code)] // read by `getDocument()`, in the documents area
    pub(crate) async fn read_joined_document(
        &self,
        collection: &Collection,
        id: &str,
        queries: Vec<Query>,
    ) -> Result<Vec<Array>> {
        let name = self.filter(collection.id());
        let alias = DEFAULT_ALIAS;
        let authorization = self.authorization();
        let roles = authorization.role_names();

        let mut queries = queries;
        let joins = self.remap_join_queries(&mut queries)?;
        let mut queries = rewrite_full_outer_joins(&queries, Method::LeftJoin);

        let unindexed = self.unindexed_joins(collection, &queries, &joins);
        let mut builder = self.new_builder(&name, alias, keeps_unmatched_rows(&queries), Vec::new(), unindexed)?;
        self.configure_find_builder(
            &mut builder,
            collection,
            &mut queries,
            &joins,
            false,
            false,
            &[],
            &name,
            alias,
            &roles,
            PermissionType::Read,
            true,
            &[],
            authorization.status(),
        )
        .await?;
        builder.filter(vec![utopia_query::Query::equal(format!("{alias}.{}", storage::UID), vec![id])]);

        let join_aliases = aliases_of(&joins);
        for join_alias in &join_aliases {
            builder.sort_asc(
                &self.qualify_order_attribute(&format!("{join_alias}.{}", document::SEQUENCE), &join_aliases),
            );
        }
        builder.limit(1);

        self.execute_select(&mut builder, Event::DocumentRead, &name).await
    }

    /// The queries of a `find()` or a row count split by how the statement
    /// reads them, the join columns qualified (`plan()`).
    pub(crate) fn plan(&self, collection: &Collection, queries: Vec<Query>) -> Result<Plan> {
        let mut vector_queries = Vec::new();
        let mut adapter_filter_queries = Vec::new();
        let mut other_queries = Vec::new();
        let (mut has_aggregation, mut has_joins, mut has_distinct) = (false, false, false);
        for query in queries {
            let method = query.method();
            if method.is_vector() {
                vector_queries.push(query);
                continue;
            }
            if self.is_adapter_filter_query(&query) {
                adapter_filter_queries.push(query);
                continue;
            }
            if method.is_aggregate() || method == Method::GroupBy {
                has_aggregation = true;
            }
            if method.is_join() {
                has_joins = true;
            }
            if method == Method::Distinct {
                has_distinct = true;
            }
            other_queries.push(query);
        }
        let mut queries = other_queries;

        let joins = if has_joins { self.remap_join_queries(&mut queries)? } else { Vec::new() };
        let unindexed = self.unindexed_joins(collection, &queries, &joins);

        let has_preserving_outer_join =
            has_joins && queries.iter().any(|q| matches!(q.method(), Method::RightJoin | Method::FullOuterJoin));
        let emulates_full_outer_join = self.needs_full_outer_join_emulation(&queries);

        Ok(Plan {
            queries,
            vector_queries,
            adapter_filter_queries,
            joins,
            unindexed,
            has_aggregation,
            has_joins,
            has_distinct,
            has_preserving_outer_join,
            emulates_full_outer_join,
        })
    }

    /// Keeps the rows a vector query can rank, those with a vector, and
    /// returns the distance it ranks them by (`vectorDistance()`).
    pub(crate) fn vector_distance(
        &self,
        builder: &mut SqlBuilder,
        vector_query: Option<&mut Query>,
        alias: &str,
    ) -> Option<Expression> {
        let vector_query = vector_query?;
        let distance = self.vector_order_raw(vector_query, alias);
        if distance.is_some() {
            let attribute = self.quote(&self.filter(vector_query.attribute()));
            where_raw(builder, &format!("{}.{attribute} IS NOT NULL", self.quote(alias)), Vec::new());
        }
        distance
    }

    /// The rows of an emulated full outer join: its left and right halves,
    /// each filtered, projected and past the cursor, as one union, ordered
    /// and paged (`fullOuterJoinUnion()`).
    #[allow(clippy::too_many_arguments)]
    pub(crate) async fn full_outer_join_union(
        &self,
        collection: &Collection,
        queries: &[Query],
        joins: &[JoinAlias],
        unindexed: &[String],
        has_aggregation: bool,
        has_distinct: bool,
        adapter_filter_queries: &[Query],
        name: &str,
        alias: &str,
        roles: &[String],
        for_permission: PermissionType,
        order_attributes: &[String],
        order_types: &[OrderDirection],
        limit: Option<i64>,
        offset: Option<i64>,
        cursor: &Array,
        cursor_direction: CursorDirection,
        authorize: bool,
    ) -> Result<SqlBuilder> {
        let join_aliases = aliases_of(joins);
        if has_distinct {
            self.assert_distinct_order_is_selected(queries, order_attributes, order_types, &join_aliases)?;
        }

        let (left_queries, right_queries) = self.emulate_full_outer_join(queries, alias)?;
        let left_preserving = keeps_unmatched_rows(&left_queries);

        let mut halves = Vec::with_capacity(2);
        for (mut half_queries, preserving) in [(left_queries, left_preserving), (right_queries, true)] {
            let mut half = self.new_builder(name, alias, preserving, Vec::new(), unindexed.to_vec())?;
            let projected = self
                .configure_find_builder(
                    &mut half,
                    collection,
                    &mut half_queries,
                    joins,
                    has_aggregation,
                    has_distinct,
                    adapter_filter_queries,
                    name,
                    alias,
                    roles,
                    for_permission,
                    true,
                    order_attributes,
                    authorize,
                )
                .await?;
            self.apply_full_outer_join_order_projection(
                &mut half,
                collection,
                alias,
                order_attributes,
                order_types,
                projected,
                joins,
            )?;
            self.apply_find_cursor(&mut half, order_attributes, order_types, cursor, cursor_direction, &join_aliases, true)?;
            halves.push(half);
        }

        let mut right = halves.pop().expect("two halves");
        let mut left = halves.pop().expect("two halves");
        union(&mut left, &mut right, !has_distinct)?;
        self.apply_find_page(&mut left, order_attributes, order_types, limit, offset, cursor_direction, true, &[])?;
        Ok(left)
    }

    /// The statement `find()` reads for the plan, unordered and unpaged,
    /// bounded to the first `max` rows (`rowsBuilder()`).
    pub(crate) async fn rows_builder(
        &self,
        collection: &Collection,
        plan: Plan,
        name: &str,
        alias: &str,
        roles: &[String],
        max: Option<i64>,
    ) -> Result<SqlBuilder> {
        let authorize = self.authorization().status();
        let Plan {
            mut queries,
            mut vector_queries,
            adapter_filter_queries,
            joins,
            unindexed,
            has_aggregation,
            has_distinct,
            has_preserving_outer_join,
            emulates_full_outer_join,
            ..
        } = plan;
        let join_aliases = aliases_of(&joins);
        let read = PermissionType::Read;

        if emulates_full_outer_join && has_aggregation {
            let (aggregation, ..) = self
                .full_outer_join_aggregation(
                    collection,
                    &mut queries,
                    &joins,
                    has_distinct,
                    &adapter_filter_queries,
                    name,
                    alias,
                    roles,
                    read,
                    &[],
                    &[],
                    max,
                    None,
                    &Array::new(),
                    CursorDirection::After,
                    authorize,
                )
                .await?;
            return Ok(aggregation);
        }

        if emulates_full_outer_join {
            return self
                .full_outer_join_union(
                    collection,
                    &queries,
                    &joins,
                    &unindexed,
                    has_aggregation,
                    has_distinct,
                    &adapter_filter_queries,
                    name,
                    alias,
                    roles,
                    read,
                    &[],
                    &[],
                    max,
                    None,
                    &Array::new(),
                    CursorDirection::After,
                    authorize,
                )
                .await;
        }

        let mut builder = self.new_builder(name, alias, has_preserving_outer_join, Vec::new(), unindexed)?;
        self.configure_find_builder(
            &mut builder,
            collection,
            &mut queries,
            &joins,
            has_aggregation,
            has_distinct,
            &adapter_filter_queries,
            name,
            alias,
            roles,
            read,
            true,
            &[],
            authorize,
        )
        .await?;
        self.vector_distance(&mut builder, vector_queries.first_mut(), alias);
        self.apply_find_page(&mut builder, &[], &[], max, None, CursorDirection::After, false, &join_aliases)?;
        Ok(builder)
    }

    /// `configureFindBuilder()`: the projection, the groups an aggregation
    /// selects, then the filters, tenant and permission hooks of a read.
    /// Whether the read projects its own columns.
    ///
    /// It qualifies the aggregates and groups of a joined aggregation and
    /// names the dotted attributes of `queries` by their columns in place,
    /// as PHP changes the query objects themselves. `authorize` is the
    /// authorization status the read runs under.
    #[allow(clippy::too_many_arguments)]
    pub(crate) async fn configure_find_builder(
        &self,
        builder: &mut SqlBuilder,
        collection: &Collection,
        queries: &mut [Query],
        joins: &[JoinAlias],
        has_aggregation: bool,
        has_distinct: bool,
        adapter_filter_queries: &[Query],
        name: &str,
        alias: &str,
        roles: &[String],
        for_permission: PermissionType,
        qualify_colliding_groups: bool,
        order_attributes: &[String],
        authorize: bool,
    ) -> Result<bool> {
        let mut has_selection_projection = false;
        let mut drop_selects = false;
        let join_aliases = aliases_of(joins);

        if !has_aggregation {
            let values: Vec<utopia_query::Value> = queries
                .iter()
                .filter(|q| q.method() == Method::Select)
                .flat_map(|q| q.values().values().cloned().collect::<Vec<_>>())
                .collect();
            let (selections, star) = projection::php_selections(&values, Some("str_ends_with"))?;
            if !selections.is_empty() && !star {
                self.apply_selection_projection(
                    builder,
                    &selections,
                    !has_distinct,
                    &join_aliases,
                    &self.join_wildcard_selections(collection, joins),
                );
                drop_selects = true;
                has_selection_projection = true;
            } else if !joins.is_empty() {
                let mut additions = selections.clone();
                if !has_distinct {
                    additions.extend(order_attributes.iter().cloned());
                }
                self.apply_join_projection(builder, collection, joins, alias, &additions);
                has_selection_projection = true;
            } else if !selections.is_empty() {
                drop_selects = true;
            }
        }

        if has_aggregation && !joins.is_empty() {
            self.qualify_joined_aggregation(collection, queries, joins, alias)?;
        }

        if has_aggregation {
            drop_selects = true;
        }

        // The queries the builder reads, by position in `queries`.
        let kept: Vec<usize> =
            (0..queries.len()).filter(|&i| !(drop_selects && queries[i].method() == Method::Select)).collect();

        if has_aggregation {
            for &i in &kept {
                let query = &queries[i];
                if query.method() != Method::GroupBy {
                    continue;
                }
                let mut columns = query.clone();
                self.remap_dotted_query_attributes(std::slice::from_mut(&mut columns), joins, collection)?;
                let group_columns: Vec<String> = columns.values().values().map(text).collect();
                let groups: Vec<String> = query.values().values().map(text).collect();
                let qualified = if qualify_colliding_groups { self.qualified_group_names(&groups) } else { Vec::new() };
                let mut plain = Vec::new();
                for (index, column) in group_columns.iter().enumerate() {
                    if !qualified.iter().any(|(i, _)| *i == index) {
                        plain.push(if column.contains('.') {
                            column.clone()
                        } else {
                            self.filter(storage::column(column))
                        });
                    }
                }
                if !plain.is_empty() {
                    builder.select(plain);
                }
                for (index, group) in &qualified {
                    let column = group_columns.get(*index).cloned().unwrap_or_default();
                    let (table, column) = column.split_once('.').unwrap_or((column.as_str(), ""));
                    builder.select_raw(
                        &format!(
                            "{}.{} AS {}",
                            self.quote(&self.filter(table)),
                            self.quote(&self.filter(column)),
                            self.quote(group)
                        ),
                        Vec::new(),
                    );
                }
            }
        }

        let mut list: Vec<Query> = kept.iter().map(|&i| queries[i].clone()).collect();
        self.apply_find_filters(
            builder,
            collection,
            &mut list,
            joins,
            adapter_filter_queries,
            name,
            alias,
            roles,
            for_permission,
            authorize,
        )
        .await?;
        for (position, &i) in kept.iter().enumerate() {
            queries[i] = list[position].clone();
        }

        Ok(has_selection_projection)
    }

    /// The aggregates and groups of a joined aggregation, each qualified by
    /// the table that declares it (`configureFindBuilder()`'s `$qualify`).
    fn qualify_joined_aggregation(
        &self,
        collection: &Collection,
        queries: &mut [Query],
        joins: &[JoinAlias],
        alias: &str,
    ) -> Result<()> {
        let mut main_attributes: Vec<String> =
            database::internal_attributes(true).iter().map(|a| a.key.clone()).collect();
        main_attributes.extend(collection.attributes()?.iter().map(|a| a.key.clone()));

        let join_attributes = collection.get(JOIN_ATTRIBUTES);
        let mut declared: Vec<(String, Option<Vec<String>>)> = Vec::new();
        for join in joins {
            let keys = match join_attributes {
                Some(Value::Array(map)) => match map.get_str(&join.table) {
                    Some(Value::Array(keys)) => Some(
                        keys.values()
                            .filter_map(|k| match k {
                                Value::Str(s) => Some(String::from_utf8_lossy(s.as_bytes()).into_owned()),
                                _ => None,
                            })
                            .collect(),
                    ),
                    _ => None,
                },
                _ => None,
            };
            assoc_set(&mut declared, join.alias.clone(), keys);
        }

        let qualify = |attribute: &str| -> Result<String> {
            if attribute == "*"
                || attribute.is_empty()
                || is_numeric_str(attribute)
                || attribute.contains('.')
                || main_attributes.iter().any(|a| a == attribute)
            {
                return Ok(attribute.to_owned());
            }
            let aliases: Vec<&String> = declared
                .iter()
                .filter(|(_, attributes)| attributes.as_ref().is_none_or(|a| a.iter().any(|k| k == attribute)))
                .map(|(alias, _)| alias)
                .collect();
            if aliases.len() > 1 {
                return Err(Error::query(format!(
                    "Attribute \"{attribute}\" is ambiguous across joins; qualify it with a join alias"
                )));
            }
            let Some(alias) = aliases.first() else {
                return Err(Error::query(format!("Attribute not found in schema: {attribute}")));
            };
            Ok(format!("{alias}.{}", storage::column(attribute)))
        };

        for query in queries.iter_mut() {
            let method = query.method();
            if method.is_aggregate() {
                let attribute = query.attribute().to_owned();
                let qualified = if main_attributes.contains(&attribute) {
                    format!("{alias}.{attribute}")
                } else {
                    qualify(&attribute)?
                };
                query.set_attribute(qualified);
            } else if method == Method::GroupBy {
                let mut values = utopia_query::Array::new();
                for value in query.values().values() {
                    let value = match value {
                        utopia_query::Value::Str(s) => {
                            utopia_query::Value::Str(qualify(&String::from_utf8_lossy(s.as_bytes()))?.as_str().into())
                        }
                        other => other.clone(),
                    };
                    values.push(value).map_err(|_| Error::database("Array is full"))?;
                }
                query.set_values(values);
            }
        }
        Ok(())
    }

    /// `applyFilters()`: the builder's own filters, then the adapter's.
    pub(crate) async fn apply_filters(
        &self,
        builder: &mut SqlBuilder,
        queries: &[Query],
        name: &str,
        alias: &str,
    ) -> Result<()> {
        let mut builder_queries = Vec::new();
        let mut adapter_filters = Vec::new();
        for query in queries {
            if self.is_adapter_filter_query(query) {
                adapter_filters.push(self.compile_adapter_filter(query, name, alias, &[]).await?);
                continue;
            }
            builder_queries.push((**query).clone());
        }
        builder.filter(builder_queries);
        for filter in adapter_filters.into_iter().flatten() {
            where_raw(builder, &filter.sql, filter.bindings);
        }
        Ok(())
    }

    /// `applyFindFilters()`: the filters (dotted attributes named by their
    /// columns in `queries` itself), the adapter's filters, and the tenant
    /// and permission hooks of the main table and of every join.
    #[allow(clippy::too_many_arguments)]
    pub(crate) async fn apply_find_filters(
        &self,
        builder: &mut SqlBuilder,
        collection: &Collection,
        queries: &mut [Query],
        joins: &[JoinAlias],
        adapter_filter_queries: &[Query],
        name: &str,
        alias: &str,
        roles: &[String],
        for_permission: PermissionType,
        authorize: bool,
    ) -> Result<()> {
        // populationStatistics() replaces the ambiguous statistics with
        // copies, so only the other queries are renamed in place.
        let mut list = population_statistics(queries);
        let mut adapter_queries = adapter_filter_queries.to_vec();
        self.remap_dotted_query_attributes(&mut list, joins, collection)?;
        self.remap_dotted_query_attributes(&mut adapter_queries, joins, collection)?;
        for (i, query) in queries.iter_mut().enumerate() {
            if !matches!(query.method(), Method::Stddev | Method::Variance) {
                *query = list[i].clone();
            }
        }
        builder.filter(inners(&list));

        for query in &adapter_queries {
            if let Some(compiled) = self.compile_adapter_filter(query, name, alias, joins).await? {
                where_raw(builder, &compiled.sql, compiled.bindings);
            }
        }

        let chain = join_hook::Chain::from_queries(&list);
        let preserving = chain.has_preserving_outer_join();
        let quote = self.identifier_quote();

        if self.shared_tables() && preserving {
            let tenants = tenant::Filter::new(vec![crate::value::to_query(&self.current_tenant())]).with_quote(quote);
            let conditions: Vec<(String, utopia_query::builder::Condition)> =
                joins.iter().map(|j| (j.alias.clone(), tenants.joined(&j.alias))).collect();
            add_hook(builder, Hook::join_filter(join_hook::OuterChain::new(chain.clone(), conditions, quote)))?;
        }

        if !authorize {
            return Ok(());
        }

        let has_joins = !joins.is_empty();
        let granted = has_joins && matches!(collection.get(COLLECTION_GRANTED), Some(Value::Bool(true)));
        let mut permission_conditions: Vec<(String, utopia_query::builder::Condition)> = Vec::new();
        if !granted && filters_per_document(collection) {
            let document_column = if has_joins { format!("{alias}.{}", storage::UID) } else { storage::UID.to_owned() };
            let hook = self.permission_hook(name, roles.to_vec(), for_permission.as_str(), &document_column)?;
            if preserving {
                let condition = hook.filter(alias).map_err(crate::query::query_error)?;
                assoc_set(&mut permission_conditions, alias.to_owned(), condition);
                let relaxed = AllowNull::new(Arc::new(hook), &document_column, quote)?;
                add_hook(builder, Hook::filter(relaxed))?;
            } else {
                add_hook(builder, hook.into())?;
            }
        }

        let join_document_security = match collection.get(JOIN_DOCUMENT_SECURITY) {
            Some(Value::Array(map)) => map.clone(),
            _ => Array::new(),
        };

        for join in joins {
            if !self.join_document_security_enabled(&join_document_security, &join.table) {
                continue;
            }
            let hook = self.join_permission_hook(
                &self.filter(&join.table),
                roles.to_vec(),
                for_permission.as_str(),
                &format!("{}.{}", join.alias, storage::UID),
                joins.len(),
                chain.join_type(&join.alias),
            )?;
            if preserving {
                let condition = hook.filter(&join.alias).map_err(crate::query::query_error)?;
                assoc_set(&mut permission_conditions, join.alias.clone(), condition);
            }
            add_hook(builder, Hook::join_filter(permission::Join::new(hook, join.alias.as_str(), quote, preserving)))?;
        }

        if !permission_conditions.is_empty() {
            add_hook(
                builder,
                Hook::join_filter(permission::OuterJoin::new(alias, permission_conditions.clone(), quote)),
            )?;
            add_hook(builder, Hook::join_filter(join_hook::OuterChain::new(chain, permission_conditions, quote)))?;
        }

        Ok(())
    }

    /// `joinDocumentSecurityEnabled()`: whether a joined table's rows are
    /// filtered per document, as the Database layer mapped them.
    fn join_document_security_enabled(&self, join_document_security: &Array, table: &str) -> bool {
        let candidates = self.join_document_security_lookup_keys(table);
        for candidate in &candidates {
            if let Some(enabled) = join_document_security.get(&key(candidate)) {
                return enabled.truthy();
            }
        }
        if join_document_security.is_empty() {
            return true;
        }
        for (k, enabled) in join_document_security.iter() {
            let k = key_text(k);
            if k.is_empty() {
                continue;
            }
            let keys = self.join_document_security_lookup_keys(&k);
            if candidates.iter().any(|c| keys.contains(c)) {
                return enabled.truthy();
            }
        }
        true
    }

    /// `joinDocumentSecurityLookupKeys()`: a table as given, filtered,
    /// qualified, and without its database.
    fn join_document_security_lookup_keys(&self, table: &str) -> Vec<String> {
        let filtered = self.filter(table);
        let qualified = self.table_raw(&filtered);
        let mut keys = vec![table.to_owned(), filtered];
        keys.push(qualified.clone());
        if let Some(dot) = qualified.rfind('.') {
            keys.push(qualified[dot + 1..].to_owned());
        }
        let mut unique: Vec<String> = Vec::new();
        for k in keys {
            if !unique.contains(&k) {
                unique.push(k);
            }
        }
        unique
    }

    /// `bitwiseResults()`: `NULL` for each bitwise aggregate that had no
    /// input values, the input counts dropped.
    pub(crate) fn bitwise_results(&self, mut row: Array, inputs: &[(String, Query)]) -> Array {
        for (count, aggregate) in inputs {
            let Some(value) = row.remove(&key(count)) else {
                continue;
            };
            let name = self.bitwise_result_name(&row, aggregate);
            if let Some(name) = name
                && is_numeric(&value)
                && crate::value::to_long(&value) == 0
            {
                row.set(key(&name), Value::Null);
            }
        }
        row
    }

    /// `bitwiseResultName()`: the column a bitwise aggregate is returned in.
    fn bitwise_result_name(&self, row: &Array, aggregate: &Query) -> Option<String> {
        let alias = aggregate.alias();
        if !alias.is_empty() {
            return row.contains_key(&key(alias)).then(|| alias.to_owned());
        }

        let function = format!("{}(", aggregate.method().sql_function().unwrap_or(""));
        let quote = self.identifier_quote();
        let mut expressions: Vec<(String, String)> = Vec::new();
        for name in row.keys().map(key_text) {
            if name.to_ascii_uppercase().starts_with(&function) && name.ends_with(')') {
                let inner = php_substr(&name, function.len() as i64, Some(-1));
                assoc_set(&mut expressions, inner.replace(quote, ""), name.clone());
            }
        }

        let attribute = aggregate.attribute();
        let dot = attribute.rfind('.');
        let column = self.filter(storage::column(dot.map_or(attribute, |d| &attribute[d + 1..])));
        let exact = match dot {
            None => vec![column.clone(), format!("{DEFAULT_ALIAS}.{column}")],
            Some(d) => vec![format!("{}.{column}", self.filter(&attribute[..d]))],
        };
        for expression in &exact {
            if let Some((_, name)) = expressions.iter().find(|(e, _)| e == expression) {
                return Some(name.clone());
            }
        }
        let suffix = format!(".{column}");
        expressions.into_iter().find(|(e, _)| e.ends_with(&suffix)).map(|(_, name)| name)
    }
}

/// `substr($string, $offset, $length)` on bytes.
fn php_substr(s: &str, offset: i64, length: Option<i64>) -> String {
    let bytes = s.as_bytes();
    let len = bytes.len() as i64;
    let start = if offset < 0 { (len + offset).max(0) } else { offset.min(len) };
    let end = match length {
        None => len,
        Some(l) if l < 0 => (len + l).max(start),
        Some(l) => (start + l).min(len),
    };
    if end <= start {
        return String::new();
    }
    String::from_utf8_lossy(&bytes[start as usize..end as usize]).into_owned()
}

/// `keepsUnmatchedRows()`: whether a right or full outer join keeps rows
/// without a main-side row.
pub(crate) fn keeps_unmatched_rows(queries: &[Query]) -> bool {
    queries.iter().any(|q| matches!(q.method(), Method::RightJoin | Method::FullOuterJoin))
}

/// `rewriteFullOuterJoins()`: copies of the queries with every full outer
/// join run as `replacement`.
pub(crate) fn rewrite_full_outer_joins(queries: &[Query], replacement: Method) -> Vec<Query> {
    queries
        .iter()
        .map(|query| {
            let mut clone = query.clone();
            if clone.method() == Method::FullOuterJoin {
                clone.set_method(replacement);
            }
            clone
        })
        .collect()
}

/// `populationStatistics()`: `STDDEV` and `VARIANCE` as their population
/// forms (copies), and an input count after the queries for every bitwise
/// aggregate.
fn population_statistics(queries: &[Query]) -> Vec<Query> {
    let mut list: Vec<Query> = queries
        .iter()
        .map(|query| {
            let method = match query.method() {
                Method::Stddev => Method::StddevPop,
                Method::Variance => Method::VarPop,
                _ => return query.clone(),
            };
            let mut clone = query.clone();
            clone.set_method(method);
            clone
        })
        .collect();
    for (count, aggregate) in bitwise_inputs(&list) {
        list.push(wrap(utopia_query::Query::count(aggregate.attribute(), count)));
    }
    list
}

/// `bitwiseInputs()`: each bitwise aggregate, keyed by the alias of its
/// input count (`$inputs:<n>`).
pub(crate) fn bitwise_inputs(queries: &[Query]) -> Vec<(String, Query)> {
    let mut inputs = Vec::new();
    for query in queries {
        if BITWISE_AGGREGATES.contains(&query.method()) {
            inputs.push((format!("{BITWISE_INPUTS}{}", inputs.len()), query.clone()));
        }
    }
    inputs
}

/// `filtersPerDocument()`: whether a collection's rows are filtered per
/// document.
pub(crate) fn filters_per_document(collection: &Collection) -> bool {
    collection.get(crate::collection::DOCUMENT_SECURITY).is_some_and(Value::truthy)
        || collection.id() == database::METADATA
}
