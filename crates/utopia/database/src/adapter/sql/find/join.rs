//! The joins of a read: their tables and conditions, the full outer joins
//! an engine cannot run, the joins no index serves, and the orders they
//! qualify.
//!
//! PHP: `SQL::remapJoinQueries()`, `assertJoinAliases()`,
//! `remapJoinOnQueries()`, `remapJoinOnQuery()`, `qualifyJoinColumn()`,
//! `needsFullOuterJoinEmulation()`, `emulateFullOuterJoin()`,
//! `joinConditionAliases()`, `findFullOuterJoinAggregate()`,
//! `shapesAggregatedRows()`, `fullOuterJoinColumns()`,
//! `fullOuterJoinColumnSpellings()`, `fullOuterJoinResultNames()`,
//! `assertDistinctOrderIsSelected()`, `applyFullOuterJoinOrderProjection()`,
//! `quoteOrderColumn()`, `unindexedJoins()`, `joinEqualityColumns()`,
//! `looksUpByTenantAlone()` (MySQL), `qualifyJoinedOrders()`,
//! `qualifyOrderAttribute()`.

use utopia_query::Object;
use utopia_query::hook::{Hook, Map};
use utopia_query::{CursorDirection, Method, OrderDirection};

use super::super::{Engine, Sql};
use super::{
    FOJ_COLUMN_PREFIX, FOJ_ROWS_ALIAS, JOIN_ATTRIBUTES, JOIN_INDEXED, JoinAlias, PAIRED_ROWS, UNPAIRED_JOINED_ROWS,
    UNPAIRED_MAIN_ROWS, add_hook, aliases_of, all_of, any_of, assoc_set, from_sub, is_numeric_str,
    keeps_unmatched_rows, key, key_text, rewrite_full_outer_joins, union, wrap,
};
use crate::adapter::Adapter;
use crate::builder::SqlBuilder;
use crate::collection::Collection;
use crate::database;
use crate::error::{Error, Result};
use crate::permission::PermissionType;
use crate::query::{DEFAULT_ALIAS, Query};
use crate::storage;
use crate::validator::query::join::Join as JoinValidator;
use crate::value::{Array, Value};

/// An aggregation over an emulated full outer join, the columns its halves
/// project, and its joined groups qualified by alias.
pub(crate) type FullOuterJoinAggregation = (SqlBuilder, Vec<(String, String)>, Vec<(String, String)>);

/// `ON`'s queries of a join, as boxed base queries.
fn on_queries(query: &utopia_query::Query) -> Vec<utopia_query::Query> {
    query.join_on_queries().into_iter().cloned().collect()
}

impl Sql {
    /// `remapJoinQueries()`: each join reads its collection's table, and
    /// its `ON` columns are qualified by alias. The joins, in order.
    pub(crate) fn remap_join_queries(&self, queries: &mut [Query]) -> Result<Vec<JoinAlias>> {
        assert_join_aliases(queries)?;
        let mut joins = Vec::new();
        for query in queries.iter_mut() {
            if !query.method().is_join() {
                continue;
            }
            let table = query.attribute().to_owned();
            query.set_attribute(self.table_raw(&self.filter(&table)));
            let alias = query.alias().to_owned();
            if query.is_nested_join() {
                let mut values = utopia_query::Array::new();
                for on in on_queries(query) {
                    let on = remap_join_on_query(on, DEFAULT_ALIAS, &alias)?;
                    values
                        .push(utopia_query::Value::Ext(Object::Query(Box::new(on))))
                        .map_err(|_| Error::database("Array is full"))?;
                }
                query.set_values(values);
            }
            joins.push(JoinAlias { table, alias });
        }
        Ok(joins)
    }

    /// `needsFullOuterJoinEmulation()`: whether the read holds a full outer
    /// join the engine's builder cannot write (all but PostgreSQL's).
    pub(crate) fn needs_full_outer_join_emulation(&self, queries: &[Query]) -> bool {
        self.engine != Engine::Postgres && queries.iter().any(|q| q.method() == Method::FullOuterJoin)
    }

    /// `emulateFullOuterJoin()`: the two halves of a flat `UNION ALL` that
    /// read what the full outer join reads.
    pub(crate) fn emulate_full_outer_join(&self, queries: &[Query], alias: &str) -> Result<(Vec<Query>, Vec<Query>)> {
        let mut full_join_alias: Option<String> = None;
        let mut joined_aliases = vec![alias.to_owned()];
        let mut main_side_aliases: Vec<String> = Vec::new();
        let mut reach: Vec<(String, u64)> = Vec::new();
        let mut first_half_rows = UNPAIRED_MAIN_ROWS | PAIRED_ROWS;
        let mut second_half_rows = PAIRED_ROWS | UNPAIRED_JOINED_ROWS;
        let mut present_rows = first_half_rows | second_half_rows;
        let mut next_rows = UNPAIRED_JOINED_ROWS << 1;
        let mut first_half_exclusions = Vec::new();
        let mut second_half_inclusions = Vec::new();

        for query in queries {
            let method = query.method();
            if !method.is_join() {
                continue;
            }
            let join_alias = query.alias().to_owned();

            if method == Method::FullOuterJoin {
                if full_join_alias.is_some() {
                    return Err(Error::query("A query can hold only one full outer join on this database"));
                }
                full_join_alias = Some(join_alias.clone());
                main_side_aliases = joined_aliases.clone();
                for main_side in &main_side_aliases {
                    assoc_set(&mut reach, main_side.clone(), UNPAIRED_MAIN_ROWS | PAIRED_ROWS);
                }
                assoc_set(&mut reach, join_alias.clone(), PAIRED_ROWS | UNPAIRED_JOINED_ROWS);
                joined_aliases.push(join_alias);
                continue;
            }

            if full_join_alias.is_none() {
                joined_aliases.push(join_alias);
                continue;
            }

            let mut rows = present_rows;
            for condition_alias in join_condition_aliases(query) {
                rows &= reach.iter().find(|(a, _)| *a == condition_alias).map_or(present_rows, |(_, r)| *r);
            }

            if method == Method::RightJoin {
                let unmatched_rows = next_rows;
                next_rows <<= 1;

                if rows & !first_half_rows == 0 {
                    first_half_rows |= unmatched_rows;
                } else if rows & !second_half_rows == 0 {
                    second_half_rows |= unmatched_rows;
                    let mut any = Vec::new();
                    for joined in &joined_aliases {
                        any.push(wrap(utopia_query::Query::is_not_null(format!("{joined}.{}", storage::UID))));
                    }
                    any.push(wrap(utopia_query::Query::is_null(format!("{join_alias}.{}", storage::UID))));
                    first_half_exclusions.push(any_of(any));
                    let between = joined_aliases.iter().skip(main_side_aliases.len() + 1);
                    let mut all = Vec::new();
                    for joined in between {
                        all.push(wrap(utopia_query::Query::is_null(format!("{joined}.{}", storage::UID))));
                    }
                    all.push(wrap(utopia_query::Query::is_not_null(format!("{join_alias}.{}", storage::UID))));
                    second_half_inclusions.push(all_of(all));
                } else {
                    return Err(Error::query(
                        "A right join after a full outer join has to join on a table joined before it, or on the full outer joined table",
                    ));
                }

                rows |= unmatched_rows;
                present_rows |= unmatched_rows;
            } else if method == Method::CrossJoin || method == Method::NaturalJoin {
                rows = present_rows;
            }

            assoc_set(&mut reach, join_alias.clone(), rows);
            joined_aliases.push(join_alias);
        }

        let Some(full_join_alias) = full_join_alias else {
            return Err(Error::database("The query holds no full outer join to emulate"));
        };

        let mut first_half = rewrite_full_outer_joins(queries, Method::LeftJoin);
        first_half.extend(first_half_exclusions);

        let mut second_half = rewrite_full_outer_joins(queries, Method::RightJoin);
        for main_side in &main_side_aliases {
            second_half.push(wrap(utopia_query::Query::is_null(format!("{main_side}.{}", storage::UID))));
        }
        let mut any = vec![wrap(utopia_query::Query::is_not_null(format!("{full_join_alias}.{}", storage::UID)))];
        any.extend(second_half_inclusions);
        second_half.push(any_of(any));

        Ok((first_half, second_half))
    }

    /// `fullOuterJoinAggregation()`: an aggregation over an emulated full
    /// outer join, to run once over the rows of both halves, with the
    /// columns the halves project and the joined groups qualified by alias
    /// (see [`Sql::full_outer_join_result_names`]). Its aggregates and
    /// groups are qualified in `queries` itself.
    #[allow(clippy::too_many_arguments)]
    pub(crate) async fn full_outer_join_aggregation(
        &self,
        collection: &Collection,
        queries: &mut [Query],
        joins: &[JoinAlias],
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
    ) -> Result<FullOuterJoinAggregation> {
        let mut aggregation_positions = Vec::new();
        let mut row_queries = Vec::new();
        let mut aggregate_aliases: Vec<String> = Vec::new();
        for (i, query) in queries.iter().enumerate() {
            let method = query.method();
            if !shapes_aggregated_rows(method) {
                row_queries.push(query.clone());
                continue;
            }
            aggregation_positions.push(i);
            if method.is_aggregate()
                && !query.alias().is_empty()
                && !aggregate_aliases.iter().any(|a| a == query.alias())
            {
                aggregate_aliases.push(query.alias().to_owned());
            }
        }
        let mut aggregation_queries: Vec<Query> = aggregation_positions.iter().map(|&i| queries[i].clone()).collect();

        let join_aliases = aliases_of(joins);
        let mut aggregation = self.dialect_builder();
        // The halves carry every tenant and permission condition of the
        // read: the aggregation reads only their rows.
        self.configure_find_builder(
            &mut aggregation,
            collection,
            &mut aggregation_queries,
            joins,
            true,
            has_distinct,
            &[],
            name,
            alias,
            roles,
            for_permission,
            false,
            &[],
            false,
        )
        .await?;
        for (position, &i) in aggregation_positions.iter().enumerate() {
            queries[i] = aggregation_queries[position].clone();
        }
        self.apply_find_page(
            &mut aggregation,
            order_attributes,
            order_types,
            limit,
            offset,
            cursor_direction,
            false,
            &join_aliases,
        )?;
        let columns = self.full_outer_join_columns(
            &aggregation_queries,
            order_attributes,
            order_types,
            &join_aliases,
            &aggregate_aliases,
            alias,
        );

        let (left_queries, right_queries) = self.emulate_full_outer_join(&row_queries, alias)?;
        let left_preserving = keeps_unmatched_rows(&left_queries);
        let unindexed = self.unindexed_joins(collection, &row_queries, joins);
        let mut halves = Vec::new();
        for (mut half_queries, preserving) in [(left_queries, left_preserving), (right_queries, true)] {
            let mut half = self.new_builder(name, alias, preserving, Vec::new(), unindexed.clone())?;
            if columns.is_empty() {
                half.select_raw("1", Vec::new());
            }
            for (source, column) in &columns {
                half.select_raw(
                    &format!("{} AS {}", self.quote_order_column(source, alias), self.quote(column)),
                    Vec::new(),
                );
            }
            self.apply_find_filters(
                &mut half,
                collection,
                &mut half_queries,
                joins,
                adapter_filter_queries,
                name,
                alias,
                roles,
                for_permission,
                authorize,
            )
            .await?;
            self.apply_find_cursor(
                &mut half,
                order_attributes,
                order_types,
                cursor,
                cursor_direction,
                &join_aliases,
                false,
            )?;
            halves.push(half);
        }

        let mut right = halves.pop().expect("two halves");
        let mut left = halves.pop().expect("two halves");
        union(&mut left, &mut right, true)?;
        from_sub(&mut aggregation, left, FOJ_ROWS_ALIAS);
        add_hook(
            &mut aggregation,
            Hook::from(Map::new(self.full_outer_join_column_spellings(&columns, &aggregate_aliases, alias))),
        )?;

        let mut qualified_groups: Vec<(String, String)> = Vec::new();
        for query in &aggregation_queries {
            if query.method() != Method::GroupBy {
                continue;
            }
            let groups: Vec<String> = query.values().values().map(super::text).collect();
            for (_, group) in self.qualified_group_names(&groups) {
                let (table, column) = group.split_once('.').unwrap_or((group.as_str(), ""));
                assoc_set(&mut qualified_groups, format!("{table}.{}", storage::column(column)), group.clone());
            }
        }

        Ok((aggregation, columns, qualified_groups))
    }

    /// `fullOuterJoinColumns()`: the columns an aggregation over an
    /// emulated full outer join reads, by table-qualified column, each with
    /// the column both halves project it as.
    fn full_outer_join_columns(
        &self,
        queries: &[Query],
        order_attributes: &[String],
        order_types: &[OrderDirection],
        join_aliases: &[String],
        aggregate_aliases: &[String],
        alias: &str,
    ) -> Vec<(String, String)> {
        let mut references = Vec::new();
        let mut queue: std::collections::VecDeque<utopia_query::Query> =
            queries.iter().map(|q| (**q).clone()).collect();
        while let Some(query) = queue.pop_front() {
            let method = query.method();
            if method.is_nested() {
                queue.extend(query.nested().cloned());
            } else if method == Method::GroupBy {
                for value in query.values().values() {
                    if let utopia_query::Value::Str(s) = value {
                        references.push(String::from_utf8_lossy(s.as_bytes()).into_owned());
                    }
                }
            } else if method != Method::Select {
                references.push(query.attribute().to_owned());
            }
        }

        for (i, attribute) in order_attributes.iter().enumerate() {
            if order_types.get(i).copied().unwrap_or(OrderDirection::Asc) != OrderDirection::Random {
                references.push(self.qualify_order_attribute(attribute, join_aliases));
            }
        }

        let mut columns: Vec<(String, String)> = Vec::new();
        for reference in references {
            if reference.is_empty()
                || reference == "*"
                || is_numeric_str(&reference)
                || aggregate_aliases.contains(&reference)
            {
                continue;
            }
            let source = match reference.split_once('.') {
                None => format!("{alias}.{}", storage::column(&reference)),
                Some((table, name)) => format!("{table}.{}", storage::column(name)),
            };
            if !columns.iter().any(|(s, _)| *s == source) {
                let column = format!("{FOJ_COLUMN_PREFIX}{}", columns.len());
                columns.push((source, column));
            }
        }
        columns
    }

    /// `fullOuterJoinColumnSpellings()`: every spelling of a projected
    /// column, resolved to the derived column that holds it.
    fn full_outer_join_column_spellings(
        &self,
        columns: &[(String, String)],
        aggregate_aliases: &[String],
        alias: &str,
    ) -> Vec<(String, String)> {
        let mut spellings: Vec<(String, String)> = Vec::new();
        for (source, column) in columns {
            let (table, name) = source.split_once('.').unwrap_or((source.as_str(), ""));
            let mut candidates = vec![source.clone(), format!("{table}.{}", storage::attribute(name))];
            if table == alias {
                candidates.push(name.to_owned());
                candidates.push(storage::attribute(name).to_owned());
            }
            for spelling in candidates {
                if !aggregate_aliases.contains(&spelling) {
                    assoc_set(&mut spellings, spelling, format!("{FOJ_ROWS_ALIAS}.{column}"));
                }
            }
        }
        spellings
    }

    /// `fullOuterJoinResultNames()`: each result column named as the single
    /// statement names it.
    pub(crate) fn full_outer_join_result_names(
        &self,
        rows: Vec<Array>,
        columns: &[(String, String)],
        qualified_groups: &[(String, String)],
    ) -> Vec<Array> {
        let mut names: Vec<(String, String)> = Vec::new();
        let mut qualified: Vec<(String, String)> = Vec::new();
        let mut expressions: Vec<(Vec<u8>, Vec<u8>)> = Vec::new();
        for (source, column) in columns {
            let (table, name) = source.split_once('.').unwrap_or((source.as_str(), ""));
            assoc_set(&mut names, column.clone(), name.to_owned());
            if let Some((_, group)) = qualified_groups.iter().find(|(s, _)| s == source) {
                assoc_set(&mut qualified, column.clone(), group.clone());
            }
            let from = format!("{}.{}", self.quote(FOJ_ROWS_ALIAS), self.quote(column)).into_bytes();
            let to = format!("{}.{}", self.quote(table), self.quote(name)).into_bytes();
            match expressions.iter_mut().find(|(f, _)| *f == from) {
                Some(entry) => entry.1 = to,
                None => expressions.push((from, to)),
            }
        }
        let pairs: Vec<(&[u8], &[u8])> = expressions.iter().map(|(f, t)| (f.as_slice(), t.as_slice())).collect();

        rows.into_iter()
            .map(|row| {
                let mut named = Array::new();
                let mut trailing = Array::new();
                for (k, value) in row.iter() {
                    let k = key_text(k);
                    if let Some((_, group)) = qualified.iter().find(|(c, _)| *c == k) {
                        trailing.set(key(group), value.clone());
                        continue;
                    }
                    let name = match names.iter().find(|(c, _)| *c == k) {
                        Some((_, name)) => name.clone(),
                        None => {
                            String::from_utf8_lossy(&php_std::string::strtr_array(k.as_bytes(), &pairs)).into_owned()
                        }
                    };
                    named.set(key(&name), value.clone());
                }
                for (k, value) in trailing.iter() {
                    named.set(k.to_owned(), value.clone());
                }
                named
            })
            .collect()
    }

    /// `assertDistinctOrderIsSelected()`: `distinct()` over an emulated
    /// full outer join is ordered by selected attributes only.
    pub(crate) fn assert_distinct_order_is_selected(
        &self,
        queries: &[Query],
        order_attributes: &[String],
        order_types: &[OrderDirection],
        join_aliases: &[String],
    ) -> Result<()> {
        let mut selected = Vec::new();
        for query in queries.iter().filter(|q| q.method() == Method::Select) {
            for value in query.values().values() {
                if let utopia_query::Value::Str(s) = value {
                    let value = String::from_utf8_lossy(s.as_bytes()).into_owned();
                    if value == "*" {
                        return Ok(());
                    }
                    selected.push(self.qualify_order_attribute(&value, join_aliases));
                }
            }
        }
        if selected.is_empty() {
            return Ok(());
        }
        for (i, attribute) in order_attributes.iter().enumerate() {
            if order_types.get(i).copied().unwrap_or(OrderDirection::Asc) == OrderDirection::Random {
                continue;
            }
            if !selected.contains(&self.qualify_order_attribute(attribute, join_aliases)) {
                return Err(Error::query(format!(
                    "A distinct() query over a full outer join can only be ordered by a selected attribute on this database, and {attribute} is not selected"
                )));
            }
        }
        Ok(())
    }

    /// `applyFullOuterJoinOrderProjection()`: each half of an emulated full
    /// outer join projects the columns the union is ordered by.
    #[allow(clippy::too_many_arguments)]
    pub(crate) fn apply_full_outer_join_order_projection(
        &self,
        builder: &mut SqlBuilder,
        collection: &Collection,
        alias: &str,
        order_attributes: &[String],
        order_types: &[OrderDirection],
        has_selection_projection: bool,
        joins: &[JoinAlias],
    ) -> Result<()> {
        let random = |i: usize| order_types.get(i).copied().unwrap_or(OrderDirection::Asc) == OrderDirection::Random;
        if (0..order_attributes.len()).all(random) {
            return Ok(());
        }
        if !has_selection_projection {
            if joins.is_empty() {
                builder.select(vec!["*".to_owned()]);
            } else {
                self.apply_join_projection(builder, collection, joins, alias, &[]);
            }
        }
        let join_aliases = aliases_of(joins);
        for (i, attribute) in order_attributes.iter().enumerate() {
            if random(i) {
                continue;
            }
            let expression = self.quote_order_column(&self.qualify_order_attribute(attribute, &join_aliases), alias);
            builder.select_raw(
                &format!(
                    "{expression} AS {}",
                    self.quote(&format!("{}{i}", super::super::row::FOJ_ORDER_ALIAS_PREFIX))
                ),
                Vec::new(),
            );
        }
        Ok(())
    }

    /// `quoteOrderColumn()`: an order key as a table-qualified column.
    pub(crate) fn quote_order_column(&self, key: &str, alias: &str) -> String {
        match key.split_once('.') {
            None => format!("{}.{}", self.quote(alias), self.quote(key)),
            Some((table, column)) => format!("{}.{}", self.quote(table), self.quote(column)),
        }
    }

    /// `looksUpByTenantAlone()`: MySQL looks a joined table's rows up by
    /// the leading `_tenant` of an index alone when no index serves it.
    fn looks_up_by_tenant_alone(&self) -> bool {
        self.engine == Engine::MySql
    }

    /// `unindexedJoins()`: the aliases of the joins no index of their
    /// collection serves, under shared tables on an engine that looks them
    /// up by tenant alone.
    pub(crate) fn unindexed_joins(
        &self,
        collection: &Collection,
        queries: &[Query],
        joins: &[JoinAlias],
    ) -> Vec<String> {
        if !self.shared_tables() || joins.is_empty() || !self.looks_up_by_tenant_alone() {
            return Vec::new();
        }
        let Some(Value::Array(join_indexed)) = collection.get(JOIN_INDEXED) else {
            return Vec::new();
        };

        let bound = join_equality_columns(queries);
        let internal: Vec<String> = [storage::UID, storage::SEQUENCE, storage::CREATED_AT, storage::UPDATED_AT]
            .iter()
            .map(|c| c.to_lowercase())
            .collect();

        let mut unindexed = Vec::new();
        for join in joins {
            let Some(Value::Array(leads)) = join_indexed.get_str(&join.table) else {
                continue;
            };
            let mut indexed = internal.clone();
            for lead in leads.values() {
                if let Value::Str(s) = lead {
                    indexed.push(storage::column(&String::from_utf8_lossy(s.as_bytes())).to_lowercase());
                }
            }
            let columns = bound.iter().find(|(a, _)| *a == join.alias).map(|(_, c)| c.as_slice()).unwrap_or(&[]);
            if !columns.iter().any(|c| indexed.contains(c)) {
                unindexed.push(join.alias.clone());
            }
        }
        unindexed
    }

    /// `qualifyJoinedOrders()`: a bare order attribute only one join
    /// declares is ordered by that join's column, and the cursor value
    /// under its name follows it.
    pub(crate) fn qualify_joined_orders(
        &self,
        mut order_attributes: Vec<String>,
        mut cursor: Array,
        collection: &Collection,
        joins: &[JoinAlias],
    ) -> Result<(Vec<String>, Array)> {
        let mut main: Vec<String> = database::internal_attributes(true).iter().map(|a| a.key.clone()).collect();
        main.extend(collection.attributes()?.iter().map(|a| a.key.clone()));

        let mut declared: Vec<(String, Vec<String>)> = Vec::new();
        if let Some(Value::Array(join_attributes)) = collection.get(JOIN_ATTRIBUTES) {
            for join in joins {
                if let Some(Value::Array(keys)) = join_attributes.get_str(&join.table) {
                    for k in keys.values() {
                        if let Value::Str(s) = k {
                            let k = String::from_utf8_lossy(s.as_bytes()).into_owned();
                            match declared.iter_mut().find(|(d, _)| *d == k) {
                                Some((_, aliases)) => aliases.push(join.alias.clone()),
                                None => declared.push((k, vec![join.alias.clone()])),
                            }
                        }
                    }
                }
            }
        }

        for attribute in order_attributes.iter_mut() {
            if attribute.contains('.') || main.contains(attribute) {
                continue;
            }
            let Some((_, aliases)) = declared.iter().find(|(d, _)| d == attribute) else {
                continue;
            };
            let mut unique: Vec<&String> = Vec::new();
            for a in aliases {
                if !unique.contains(&a) {
                    unique.push(a);
                }
            }
            if unique.len() > 1 {
                return Err(Error::query(format!(
                    "Attribute \"{attribute}\" is ambiguous across joins; qualify it with a join alias"
                )));
            }
            let qualified = format!("{}.{attribute}", unique[0]);
            if let Some(value) = cursor.get_str(attribute).cloned()
                && !cursor.contains_key(&key(&qualified))
            {
                cursor.set(key(&qualified), value);
            }
            *attribute = qualified;
        }
        Ok((order_attributes, cursor))
    }

    /// `qualifyOrderAttribute()`: an order attribute by its column, a
    /// joined one qualified by its alias.
    pub(crate) fn qualify_order_attribute(&self, attribute: &str, join_aliases: &[String]) -> String {
        if let Some((prefix, name)) = attribute.split_once('.')
            && join_aliases.iter().any(|a| a == prefix)
        {
            return format!("{}.{}", self.filter(prefix), self.filter(storage::column(name)));
        }
        self.filter(storage::column(attribute))
    }
}

/// `assertJoinAliases()`: every join alias is valid and declared once.
fn assert_join_aliases(queries: &[Query]) -> Result<()> {
    let mut declared: Vec<String> = Vec::new();
    for query in queries.iter().filter(|q| q.method().is_join()) {
        let alias = query.alias();
        let defaulted = query.is_alias_defaulted();
        if let Some(invalid) = JoinValidator::describe_invalid_alias(alias, defaulted) {
            return Err(Error::query(invalid));
        }
        let lower = alias.to_ascii_lowercase();
        if declared.contains(&lower) {
            return Err(Error::query(JoinValidator::describe_duplicate_alias(alias, defaulted)));
        }
        declared.push(lower);
    }
    Ok(())
}

/// `remapJoinOnQuery()`: an `ON` condition's columns qualified by the
/// main alias and the join's.
fn remap_join_on_query(mut on: utopia_query::Query, main_alias: &str, join_alias: &str) -> Result<utopia_query::Query> {
    if on.method() != Method::On {
        return Ok(on);
    }
    let mut values: Vec<utopia_query::Value> = on.values().values().cloned().collect();
    let column = |v: Option<&utopia_query::Value>| match v {
        Some(utopia_query::Value::Str(s)) if !s.is_empty() => Some(String::from_utf8_lossy(s.as_bytes()).into_owned()),
        _ => None,
    };
    let (Some(left), Some(right)) = (column(values.first()), column(values.get(2))) else {
        return Err(Error::query("Join ON requires left and right columns"));
    };
    values[0] = utopia_query::Value::Str(qualify_join_column(&left, main_alias).as_str().into());
    values[2] = utopia_query::Value::Str(qualify_join_column(&right, join_alias).as_str().into());
    on.set_values(values.into_iter().collect());
    Ok(on)
}

/// `qualifyJoinColumn()`: a bare column qualified by the default alias,
/// both named by their columns.
fn qualify_join_column(column: &str, default_alias: &str) -> String {
    match column.split_once('.') {
        None => format!("{default_alias}.{}", storage::column(column)),
        Some((prefix, name)) => format!("{prefix}.{}", storage::column(name)),
    }
}

/// `joinConditionAliases()`: the aliases a join's `ON` compares, other than
/// its own.
fn join_condition_aliases(join: &Query) -> Vec<String> {
    let method = join.method();
    if method == Method::CrossJoin || method == Method::NaturalJoin {
        return Vec::new();
    }
    let mut columns = Vec::new();
    for condition in join.join_on_queries() {
        if condition.method() == Method::On {
            let values: Vec<&utopia_query::Value> = condition.values().values().collect();
            columns.push(values.first().copied().cloned());
            columns.push(values.get(2).copied().cloned());
        }
    }
    let join_alias = join.alias();
    let mut aliases = Vec::new();
    for column in columns.into_iter().flatten() {
        let utopia_query::Value::Str(s) = column else {
            continue;
        };
        let column = String::from_utf8_lossy(s.as_bytes()).into_owned();
        if let Some((alias, _)) = column.split_once('.')
            && alias != join_alias
        {
            aliases.push(alias.to_owned());
        }
    }
    aliases
}

/// `shapesAggregatedRows()`.
fn shapes_aggregated_rows(method: Method) -> bool {
    method.is_aggregate() || matches!(method, Method::GroupBy | Method::Having | Method::Select | Method::Distinct)
}

/// `joinEqualityColumns()`: the columns each join's own `ON` compares for
/// equality with another alias's column, by the join's alias.
fn join_equality_columns(queries: &[Query]) -> Vec<(String, Vec<String>)> {
    let mut columns: Vec<(String, Vec<String>)> = Vec::new();
    for query in queries.iter().filter(|q| q.method().is_join()) {
        let alias = query.alias().to_owned();
        for on in query.join_on_queries() {
            let values: Vec<&utopia_query::Value> = on.values().values().collect();
            let string = |i: usize| match values.get(i) {
                Some(utopia_query::Value::Str(s)) => Some(String::from_utf8_lossy(s.as_bytes()).into_owned()),
                _ => None,
            };
            if on.method() != Method::On || string(1).as_deref() != Some("=") {
                continue;
            }
            let (Some(left), Some(right)) = (string(0), string(2)) else {
                continue;
            };
            let (Some(left), Some(right)) = (left.split_once('.'), right.split_once('.')) else {
                continue;
            };
            if left.0 == right.0 {
                continue;
            }
            for (side, column) in [left, right] {
                if side == alias {
                    match columns.iter_mut().find(|(a, _)| *a == alias) {
                        Some((_, list)) => list.push(column.to_lowercase()),
                        None => columns.push((alias.clone(), vec![column.to_lowercase()])),
                    }
                }
            }
        }
    }
    columns
}
