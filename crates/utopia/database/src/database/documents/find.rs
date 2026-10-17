//! `find()`, `findOne()`, `count()`, `sum()`, `aggregate()` and `cursor()`.
//!
//! PHP: `find()`, `fetch()`, `findOne()`, `count()`, `sum()`,
//! `resolveSumAttribute()`, `aliasedJoinCollections()`,
//! `qualifyJoinedAttribute()`, `declaresSumAttribute()`,
//! `validateSumAttribute()`, `getSumValidator()`, `cursor()`, `pages()`,
//! `aggregate()`, `aliasAggregates()`, `addTieBreaks()`, `nextPageCheck()`,
//! `showsJoinedRows()`, `joinMatchesAtMostOneRow()`,
//! `assertDistinctCursorOrder()`, `qualifyJoinedOrders()` and
//! `assertCursorHasOrderValues()` of `Trait\Documents`.

use std::collections::VecDeque;
use std::sync::Arc;

use utopia_query::{CursorDirection, Method, OrderDirection, Value as QValue};

use super::super::{BoxFuture, BoxStream, Database, METADATA, internal_attributes, warning};
use super::joins::{
    Joined, assert_join_count, assert_max, batch_size, joined, joined_by_alias, outer_join_id_selections,
    with_join_authorization,
};
use crate::adapter::Find;
use crate::aggregation::Aggregation;
use crate::capability::Capability;
use crate::collection::{Collection, DOCUMENT_SECURITY};
use crate::document::{self, Document};
use crate::error::{Error, Kind, Result};
use crate::event::{Domain, Event};
use crate::permission::PermissionType;
use crate::query::{ParsedQuery, Query};
use crate::validator::query::aggregate::Aggregate;
use crate::validator::query::joined::collection::Collection as JoinedCollection;
use crate::validator::uid::Uid;
use crate::value::{Array, ArrayKey, Str, Value, to_text};

/// The most validators kept per database (`DOCUMENTS_VALIDATOR_CACHE_LIMIT`).
const VALIDATOR_CACHE_LIMIT: usize = 256;

/// The aggregates that answer a double, which can overflow
/// (`FLOATING_AGGREGATES`).
const FLOATING_AGGREGATES: [Method; 8] = [
    Method::Sum,
    Method::Avg,
    Method::Stddev,
    Method::StddevPop,
    Method::StddevSamp,
    Method::Variance,
    Method::VarPop,
    Method::VarSamp,
];

/// What a read hands back: its documents (or rows), the queries the
/// adapter's `find()` was handed (`None` when they can match no document
/// and the adapter was not read), and the collection each join reads, by
/// alias (`fetch()`'s `$adapterQueries` and `$joinedByAlias`).
pub(crate) struct Fetched {
    /// The documents, or the rows of an aggregation.
    pub(crate) documents: Vec<Document>,
    /// The queries as the adapter's `find()` was handed them.
    pub(crate) adapter_queries: Option<Vec<Query>>,
    /// The collection each join reads, by alias.
    pub(crate) joined_by_alias: Joined,
}

/// The adapter's collection, its queries and whether authorization is
/// skipped for the read, or `None` when no document can match.
type Prepared = Option<(Collection, Vec<Query>, bool)>;

impl Database {
    /// The documents matching `queries` the caller may read (or act on
    /// under `for_permission`) (`find()`): filters, selects, orders,
    /// limit/offset, cursors and joins. Aggregates go through
    /// [`Database::aggregate`].
    ///
    /// Fails ([`crate::Kind::NotFound`]) for a missing collection,
    /// ([`crate::Kind::Authorization`]) when the caller cannot read it,
    /// ([`crate::Kind::Query`]) for invalid queries, ([`crate::Kind::Order`])
    /// for an unusable cursor.
    pub fn find<'a>(
        &'a self,
        collection: &'a str,
        queries: Vec<Query>,
        for_permission: PermissionType,
    ) -> BoxFuture<'a, Result<Vec<Document>>> {
        Box::pin(async move {
            let definition = self.silent(self.get_collection(collection)).await?;
            let queries = self.rewrite_queries(Event::DocumentFind, &definition, queries).await?;
            self.fetch(&definition, queries, for_permission, false).await
        })
    }

    /// What [`Database::find`] and [`Database::aggregate`] share
    /// (`fetch()`): `aggregate` is whether the queries must aggregate
    /// (`aggregate()`) or must not (`find()`). The bulk writes read their
    /// pages through it under the definition they resolved.
    pub(crate) fn fetch<'a>(
        &'a self,
        collection: &'a Collection,
        queries: Vec<Query>,
        for_permission: PermissionType,
        aggregate: bool,
    ) -> BoxFuture<'a, Result<Vec<Document>>> {
        Box::pin(
            async move { Ok(self.fetch_documents(collection, queries, for_permission, aggregate).await?.documents) },
        )
    }

    /// [`Database::fetch`] with what the read handed the adapter
    /// (`fetch()`'s `$adapterQueries` and `$joinedByAlias`).
    pub(crate) fn fetch_read<'a>(
        &'a self,
        collection: &'a Collection,
        queries: Vec<Query>,
        for_permission: PermissionType,
        aggregate: bool,
    ) -> BoxFuture<'a, Result<Fetched>> {
        Box::pin(self.fetch_documents(collection, queries, for_permission, aggregate))
    }

    /// [`Database::fetch`]'s body.
    async fn fetch_documents(
        &self,
        collection: &Collection,
        queries: Vec<Query>,
        for_permission: PermissionType,
        aggregate: bool,
    ) -> Result<Fetched> {
        let query_cache_queries = queries.clone();
        let queries = Database::resolve_one_to_many_joins(collection, queries)?;
        let mut adapter_queries: Option<Vec<Query>> = None;

        let mut joined_by_id: Option<Joined> = None;
        if self.is_validating() {
            let resolved = self.resolve_joined_collections(&queries).await?;
            self.validate_documents_queries(collection, &queries, Some(&resolved)).await?;
            self.validate_relationship_selects(collection, &queries).await?;
            joined_by_id = Some(resolved);
        }

        let document_security = collection.get(DOCUMENT_SECURITY).is_some_and(Value::truthy);
        let collection_granted = self.collection_granted(collection, for_permission)?;
        let authorization = self.authorization();
        if !collection_granted && !document_security && collection.id() != METADATA {
            return Err(Error::authorization(authorization.description()));
        }

        let relationships = Database::relationship_attributes(collection)?;

        let parsed = Query::group_by_type(&queries)?;
        let is_aggregation = parsed.is_aggregation();
        let supported = self.assert_supported(&parsed);
        let ParsedQuery {
            filters,
            selections: selects,
            aggregations,
            group_by,
            having,
            joins,
            distinct,
            limit,
            offset,
            mut order_attributes,
            mut order_types,
            cursor,
            cursor_direction,
            relationships: relationship_queries,
            ..
        } = parsed;
        // Skipping authorization would also skip the joined collections'
        // permission filters, so with joins the main collection's grant
        // travels to the adapter instead.
        let skip_auth = collection_granted && joins.is_empty();
        let cursor_direction = cursor_direction.unwrap_or(CursorDirection::After);

        if is_aggregation != aggregate {
            return Err(Error::query(if aggregate {
                "aggregate() needs an aggregate or groupBy query"
            } else {
                "find() does not run aggregate or groupBy queries: use aggregate()"
            }));
        }
        supported?;
        let adapter = &self.inner.adapter;

        let joined_by_id = match joined_by_id {
            Some(j) => j,
            None => self.resolve_joined_collections(&joins).await?,
        };
        let security =
            if joins.is_empty() { Vec::new() } else { self.authorize_joins(&joins, for_permission, &joined_by_id)? };
        let by_alias = joined_by_alias(&joins, &joined_by_id);
        let joined_collections: Joined = if is_aggregation { Vec::new() } else { by_alias.clone() };

        // 7.x read a random order with a cursor as a random order without
        // one, so the cursor is dropped as it was.
        let mut cursor = cursor;
        if order_types.contains(&OrderDirection::Random) {
            cursor = None;
        }

        if !joined_collections.is_empty()
            && let Some(c) = cursor.take()
        {
            let (orders, c) = self.qualify_joined_orders(collection, order_attributes, c, &joined_collections)?;
            order_attributes = orders;
            cursor = Some(c);
        }

        if !is_aggregation && !distinct {
            (order_attributes, order_types) = add_tie_breaks(
                order_attributes,
                order_types,
                &filters,
                &joins,
                &joined_collections,
                cursor.is_some(),
                &selects,
            );
        }

        if let Some(c) = &cursor {
            if is_aggregation {
                return Err(Error::query("Cursor pagination is not supported with aggregation queries"));
            }
            assert_cursor_orders_are_comparable(collection, &order_attributes, &joined_collections)?;
            if joins.is_empty() && !distinct && self.is_validating() && c.id().is_empty() {
                return Err(Error::query(format!(
                    "Invalid query: Invalid cursor: {}",
                    Uid::new(adapter.limits().uid_length).description()
                )));
            }
            if distinct {
                assert_distinct_cursor_order(&selects, &order_attributes)?;
            }
            if !joins.is_empty() || distinct {
                assert_cursor_has_order_values(c, &order_attributes)?;
            }
            if joins.is_empty() {
                for order in &order_attributes {
                    if c.get(order).is_none() {
                        return Err(Error::order(format!("Order attribute '{order}' is empty"), Some(order.clone())));
                    }
                }
            }
        }

        if let Some(c) = &cursor
            && c.collection() != collection.id()
        {
            return Err(Error::database("cursor Document must be from the same Collection."));
        }

        let cursor: Array = match cursor {
            Some(c) => {
                let c = self.encode(collection, c, true).await?;
                let c = self.cast_before(collection, c)?;
                let c = self.encode_joins(&c, &joined_collections).await?;
                c.array_copy()
            }
            None => Array::new(),
        };

        let outer_ids =
            if distinct { Vec::new() } else { outer_join_id_selections(&selects, &joins, &joined_collections) };

        let mut queries = self.read_queries(
            collection,
            &selects,
            filters,
            aggregations,
            having,
            &joins,
            group_by,
            distinct,
            &by_alias,
            &outer_ids,
        )?;

        let selections = self.validate_selections(collection, &selects, distinct)?;

        let hook = self.relationship_hook();
        let nested = match &hook {
            Some(h) if !is_aggregation => {
                // The relationship queries go after the others, and come
                // back out once their related documents' queries are read.
                let count = queries.len();
                queries.extend(relationship_queries);
                let nested = h.process_queries(&relationships, &mut queries)?;
                queries.truncate(count);
                nested
            }
            _ => Vec::new(),
        };

        // Relationship filters become subqueries; `None` means no document
        // can match.
        let converted = match &hook {
            Some(h) if !is_aggregation => h.convert_queries(&relationships, queries, Some(collection)).await?,
            _ => Some(queries),
        };

        let results = match converted {
            None => Vec::new(),
            Some(queries) => {
                adapter_queries = Some(queries.clone());
                let query_cache = self.query_cache();
                let mut results: Option<Vec<Document>> = None;
                let mut entry = None;
                let mut generation = String::new();
                if let Some(query_cache) = &query_cache
                    && collection.id() != METADATA
                    && adapter.supports(Capability::Caching)
                    && !adapter.in_transaction()
                    && joins.is_empty()
                {
                    let field =
                        || self.query_cache_field(Some(collection), &query_cache_queries, "documents", for_permission);
                    let context = if skip_auth { authorization.skip_sync(field)? } else { field()? };
                    if let Some(context) = context {
                        let cache_queries = assoc(vec![
                            (
                                "input",
                                Value::Array(
                                    query_cache_queries
                                        .iter()
                                        .map(|q| super::super::keys::serialize_query(q))
                                        .collect(),
                                ),
                            ),
                            (
                                "queries",
                                Value::Array(queries.iter().map(|q| super::super::keys::serialize_query(q)).collect()),
                            ),
                            ("limit", Value::Int(limit.unwrap_or(25))),
                            ("offset", Value::Int(offset.unwrap_or(0))),
                            (
                                "orderAttributes",
                                Value::Array(
                                    order_attributes.iter().map(|a| Value::Str(Str::from(a.as_str()))).collect(),
                                ),
                            ),
                            (
                                "orderTypes",
                                Value::Array(order_types.iter().map(|t| Value::Str(Str::from(t.as_str()))).collect()),
                            ),
                            ("cursor", super::super::keys::normalize_value(&Value::Array(cursor.clone()))),
                            ("cursorDirection", Value::Str(Str::from(cursor_direction.as_str()))),
                        ]);
                        let lookup = async {
                            let scope = self.query_cache_scope(None).await;
                            let Some(found) =
                                query_cache.entry(&scope, collection.id(), &cache_queries, &context).await?
                            else {
                                return Ok((None, None, String::new()));
                            };
                            if let Some(cached) = query_cache.get(&found).await? {
                                return Ok((Some(cached), None, String::new()));
                            }
                            let generation = query_cache.generation(&found).await?;
                            Ok::<_, Error>((None, Some(found), generation))
                        };
                        match lookup.await {
                            Ok((cached, found, g)) => {
                                results = cached;
                                entry = found;
                                generation = g;
                            }
                            Err(e) if !e.is_engine_error() => {
                                warning(format!("Warning: Failed to get query results from cache: {}", e.message()));
                            }
                            Err(e) => return Err(e),
                        }
                    }
                }

                match results {
                    Some(results) => results,
                    None => {
                        let adapter_collection =
                            self.read_collection(collection, &joins, &joined_by_id, &security, collection_granted)?;
                        let options = Find {
                            limit: Some(limit.unwrap_or(25)),
                            offset: Some(offset.unwrap_or(0)),
                            order_attributes: order_attributes.clone(),
                            order_types: order_types.clone(),
                            cursor: cursor.clone(),
                            cursor_direction,
                            for_permission,
                        };
                        let find = adapter.find(&adapter_collection, queries, options);
                        let results = if skip_auth { authorization.skip(find).await? } else { find.await? };

                        if let (Some(entry), Some(query_cache)) = (&entry, &query_cache)
                            && !self.is_read_from_replica()
                        {
                            match query_cache.set(entry, &results, &generation).await {
                                Ok(_) => {}
                                Err(e) if !e.is_engine_error() => {
                                    warning(format!("Failed to save query results to cache: {}", e.message()));
                                }
                                Err(e) => return Err(e),
                            }
                        }
                        results
                    }
                }
            }
        };

        if is_aggregation {
            return Ok(Fetched { documents: results, adapter_queries, joined_by_alias: by_alias });
        }

        let mut results = results;
        if let Some(hook) = &hook
            && !hook.is_in_batch_population()
            && hook.is_enabled()
            && !relationships.is_empty()
            && (selects.is_empty() || !nested.is_empty())
            && !results.is_empty()
        {
            results = self
                .silent(hook.populate_selected(
                    results,
                    collection,
                    hook.fetch_depth(),
                    nested,
                    Some(!selects.is_empty()),
                ))
                .await?;
        }

        let collection_id = collection.id();
        let custom_type = self.document_type(collection_id).is_some();
        let mut nodes = Vec::with_capacity(results.len());
        for node in self.cast_after(collection, results)? {
            let node = self.casting(collection, node)?;
            let mut node = self.decode(collection, node, &selections).await?;
            if !joined_collections.is_empty() {
                node = self.decode_joins(node, &joined_collections).await?;
                for id in &outer_ids {
                    node.remove(id);
                }
            }
            if custom_type {
                node = self.new_document(collection_id, node.array_copy())?;
            }
            if !node.is_empty() {
                node.put(document::COLLECTION, Value::Str(Str::from(collection_id)));
            }
            nodes.push(node);
        }

        let mut results = self.decorate_documents(Event::DocumentFind, collection, nodes).await?;

        if collection_id == METADATA {
            results =
                results.into_iter().map(|node| self.to_collection(node).map(Document::from)).collect::<Result<_>>()?;
        }

        self.trigger(Event::DocumentFind, || Domain::DocumentFound {
            collection: collection_id.to_owned(),
            documents: results.clone(),
        })
        .await?;

        Ok(Fetched { documents: results, adapter_queries, joined_by_alias: by_alias })
    }

    /// The adapter reads the aggregates, `distinct` and joins the queries
    /// hold (`assertReadable()`).
    ///
    /// Fails ([`crate::Kind::Query`]) when it cannot, or for too many joins.
    pub(crate) fn assert_supported(&self, parsed: &ParsedQuery) -> Result<()> {
        let adapter = &self.inner.adapter;
        if parsed.is_aggregation() && !adapter.supports(Capability::Aggregations) {
            return Err(Error::query("Aggregation queries are not supported by this adapter"));
        }
        if parsed.distinct && !adapter.supports(Capability::Aggregations) {
            return Err(Error::query("Distinct queries are not supported by this adapter"));
        }
        if !parsed.joins.is_empty() && !adapter.supports(Capability::Joins) {
            return Err(Error::query("Join queries are not supported by this adapter"));
        }
        assert_join_count(parsed.joins.len())
    }

    /// The queries the adapter reads for `aggregate()` and `find()`, and for
    /// `count()` and `sum()` over the same rows (`readQueries()`).
    #[allow(clippy::too_many_arguments)]
    pub(crate) fn read_queries(
        &self,
        collection: &Collection,
        selects: &[Query],
        filters: Vec<Query>,
        aggregations: Vec<Query>,
        having: Vec<Query>,
        joins: &[Query],
        group_by: Vec<QValue>,
        distinct: bool,
        joined_by_alias: &Joined,
        outer_join_ids: &[String],
    ) -> Result<Vec<Query>> {
        let mut queries: Vec<Query> = selects.to_vec();
        if !outer_join_ids.is_empty() {
            queries.push(Query::select(outer_join_ids.to_vec()));
        }
        let mut converted: Vec<Query> = filters;
        converted.extend(aggregations);
        converted.extend(having);
        converted.extend(joins.iter().cloned());
        queries.extend(self.convert_queries(collection, converted, joined_by_alias)?);
        if !group_by.is_empty() {
            queries.push(Query::new(Method::GroupBy, "", utopia_query::list(group_by), ""));
        }
        if distinct {
            queries.push(Query::distinct());
        }
        Ok(queries)
    }

    /// The collection handed to the adapter for a read, carrying what it
    /// needs to authorize and qualify each join (`readCollection()`).
    pub(crate) fn read_collection(
        &self,
        collection: &Collection,
        joins: &[Query],
        joined_by_id: &Joined,
        security: &[(String, bool)],
        collection_granted: bool,
    ) -> Result<Collection> {
        self.with_join_indexed(
            Database::with_join_attributes(
                with_join_authorization(collection.clone(), security, collection_granted)?,
                joins,
                joined_by_id,
            )?,
            joins,
            joined_by_id,
        )
    }

    /// The first document matching `queries`, or an empty document
    /// (`findOne()`), which fires no event.
    pub async fn find_one(&self, collection: &str, queries: Vec<Query>) -> Result<Document> {
        let mut all = vec![Query::limit(1)];
        all.extend(queries);
        let results = self.silent(self.find(collection, all, PermissionType::Read)).await?;
        let Some(found) = results.into_iter().next() else {
            return Ok(Document::empty());
        };
        self.trigger(Event::DocumentFind, || Domain::DocumentFound {
            collection: found.collection().to_owned(),
            documents: vec![found.clone()],
        })
        .await?;
        Ok(found)
    }

    /// How many documents match `queries`, counting at most `max`
    /// (`count()`).
    ///
    /// Fails ([`crate::Kind::Query`]) for a `max` not greater than 0 and
    /// invalid queries, ([`crate::Kind::Authorization`]) when the caller
    /// cannot read the collection.
    pub async fn count(&self, collection: &str, queries: Vec<Query>, max: Option<i64>) -> Result<i64> {
        assert_max(max)?;

        let collection = self.silent(self.get_collection(collection)).await?;
        let queries = self.rewrite_queries(Event::DocumentCount, &collection, queries).await?;
        let queries = Database::resolve_one_to_many_joins(&collection, queries)?;

        let mut joined: Option<Joined> = None;
        if self.is_validating() && !queries.is_empty() {
            let resolved = self.resolve_joined_collections(&queries).await?;
            self.validate_documents_queries(&collection, &queries, Some(&resolved)).await?;
            joined = Some(resolved);
        }

        let collection_granted = self.assert_readable(&collection)?;

        let prepared = self.prepare_read(&collection, queries, collection_granted, joined).await?;
        let count = match prepared {
            None => 0,
            Some((adapter_collection, queries, skip_auth)) => {
                let count = self.inner.adapter.count(&adapter_collection, queries, max);
                if skip_auth { self.authorization().skip(count).await? } else { count.await? }
            }
        };

        self.trigger(Event::DocumentCount, || Domain::DocumentCounted {
            collection: collection.id().to_owned(),
            count,
        })
        .await?;

        Ok(count)
    }

    /// What `count()` and `sum()` hand the adapter: the documents the
    /// queries match (`countDocuments()`, `sumDocuments()`), or with
    /// aggregates, `groupBy` or `distinct`, the rows `aggregate()` or
    /// `find()` reads for them, built and authorized as they build and
    /// authorize them (`readRows()`).
    async fn prepare_read(
        &self,
        collection: &Collection,
        queries: Vec<Query>,
        collection_granted: bool,
        joined: Option<Joined>,
    ) -> Result<Prepared> {
        let parsed = Query::group_by_type(&queries)?;
        if !shapes_rows(&parsed) {
            let relationships = Database::relationship_attributes(collection)?;
            return self
                .prepare_filter_join_queries(collection, &queries, &relationships, collection_granted, joined.as_ref())
                .await;
        }

        if self.is_validating() {
            self.validate_relationship_selects(collection, &queries).await?;
        }
        self.prepare_rows(collection, Query::group_by_type(&alias_aggregates(queries)?)?, collection_granted, joined)
            .await
    }

    /// The statement `aggregate()` or `find()` reads for the queries,
    /// without its order and page, as the adapter's collection and queries
    /// (`readRows()`).
    async fn prepare_rows(
        &self,
        collection: &Collection,
        parsed: ParsedQuery,
        collection_granted: bool,
        joined: Option<Joined>,
    ) -> Result<Prepared> {
        self.assert_supported(&parsed)?;

        let is_aggregation = parsed.is_aggregation();
        let joins = parsed.joins;
        let joined_by_id = match joined {
            Some(joined) => joined,
            None => self.resolve_joined_collections(&joins).await?,
        };
        let security = if joins.is_empty() {
            Vec::new()
        } else {
            self.authorize_joins(&joins, PermissionType::Read, &joined_by_id)?
        };

        let by_alias = joined_by_alias(&joins, &joined_by_id);
        let mut queries = self.read_queries(
            collection,
            &parsed.selections,
            parsed.filters,
            parsed.aggregations,
            parsed.having,
            &joins,
            parsed.group_by,
            parsed.distinct,
            &by_alias,
            &[],
        )?;

        if !is_aggregation && let Some(hook) = self.relationship_hook() {
            let relationships = Database::relationship_attributes(collection)?;
            match hook.convert_queries(&relationships, queries, Some(collection)).await? {
                Some(converted) => queries = converted,
                None => return Ok(None),
            }
        }

        let adapter_collection =
            self.read_collection(collection, &joins, &joined_by_id, &security, collection_granted)?;
        Ok(Some((adapter_collection, queries, collection_granted && joins.is_empty())))
    }

    /// The sum of `attribute` over the documents matching `queries`, over at
    /// most `max` documents (`sum()`): an integer or a float.
    ///
    /// A bare name the collection does not declare sums the attribute of
    /// the one join whose collection declares it.
    ///
    /// Fails ([`crate::Kind::Query`]) for a `max` not greater than 0,
    /// invalid queries and an attribute a sum cannot add up,
    /// ([`crate::Kind::Authorization`]) when the caller cannot read the
    /// collection.
    pub async fn sum(&self, collection: &str, attribute: &str, queries: Vec<Query>, max: Option<i64>) -> Result<Value> {
        assert_max(max)?;

        let collection = self.silent(self.get_collection(collection)).await?;
        let queries = self.rewrite_queries(Event::DocumentSum, &collection, queries).await?;
        let queries = Database::resolve_one_to_many_joins(&collection, queries)?;

        let mut joined: Option<Joined> = None;
        if self.is_validating() {
            let resolved = self.resolve_joined_collections(&queries).await?;
            if !queries.is_empty() {
                self.validate_documents_queries(&collection, &queries, Some(&resolved)).await?;
            }
            self.validate_sum_attribute(&collection, attribute, &queries, Some(&resolved)).await?;
            joined = Some(resolved);
        }

        let mut attribute = attribute.to_owned();
        if !attribute.contains('.') && !self.declares_sum_attribute(&collection, &attribute)? {
            let resolved = match joined.take() {
                Some(j) => j,
                None => self.resolve_joined_collections(&queries).await?,
            };
            attribute = self.resolve_sum_attribute(&collection, &attribute, &queries, &resolved)?;
            joined = Some(resolved);
        }

        let collection_granted = self.assert_readable(&collection)?;

        let prepared = self.prepare_read(&collection, queries, collection_granted, joined).await?;
        let sum = match prepared {
            None => Value::Int(0),
            Some((adapter_collection, queries, skip_auth)) => {
                let sum = self.inner.adapter.sum(&adapter_collection, &attribute, queries, max);
                if skip_auth { self.authorization().skip(sum).await? } else { sum.await? }
            }
        };

        assert_finite(Some(&sum))?;

        self.trigger(Event::DocumentSum, || Domain::DocumentSummed {
            collection: collection.id().to_owned(),
            attribute: attribute.clone(),
            sum: sum.clone(),
        })
        .await?;

        Ok(sum)
    }

    /// Whether the caller holds read on the collection, failing
    /// ([`crate::Kind::Authorization`]) when it does not and the
    /// collection has no document security (the check `count()` and
    /// `sum()` open with).
    fn assert_readable(&self, collection: &Collection) -> Result<bool> {
        let document_security = collection.get(DOCUMENT_SECURITY).is_some_and(Value::truthy);
        let granted = self.collection_granted(collection, PermissionType::Read)?;
        if !granted && !document_security && collection.id() != METADATA {
            return Err(Error::authorization(self.authorization().description()));
        }
        Ok(granted)
    }

    /// `alias.name` of the one aliased join whose collection declares a bare
    /// name the main collection does not (`resolveSumAttribute()`).
    ///
    /// Fails ([`crate::Kind::Query`]) for a name several joins declare
    /// (`Invalid query: ...`).
    fn resolve_sum_attribute(
        &self,
        collection: &Collection,
        attribute: &str,
        queries: &[Query],
        joined_collections: &Joined,
    ) -> Result<String> {
        let aliased = aliased_join_collections(queries, joined_collections);
        self.qualify_joined_attribute(collection, attribute, &aliased).map_err(|e| {
            if e.is_a(Kind::Query) {
                let message = format!("Invalid query: {}", e.message());
                Error::query(message).with_previous(e)
            } else {
                e
            }
        })
    }

    /// `alias.name` for a bare name the main collection does not declare
    /// and exactly one of the joined collections (by alias) declares as a
    /// non-relationship attribute; any other name as given
    /// (`qualifyJoinedAttribute()`).
    ///
    /// Fails ([`crate::Kind::Query`]) when several joined collections
    /// declare the name.
    pub(crate) fn qualify_joined_attribute(
        &self,
        collection: &Collection,
        attribute: &str,
        joined_collections: &Joined,
    ) -> Result<String> {
        if attribute.contains('.') || self.declares_sum_attribute(collection, attribute)? {
            return Ok(attribute.to_owned());
        }
        let mut aliases: Vec<&str> = Vec::new();
        for (alias, joined) in joined_collections {
            if joined.attributes()?.iter().any(|d| d.key == attribute && d.relationship.is_none()) {
                aliases.push(alias);
            }
        }
        if aliases.len() > 1 {
            return Err(Error::query(format!(
                "Attribute \"{attribute}\" is ambiguous across joins; qualify it with a join alias"
            )));
        }
        Ok(match aliases.first() {
            Some(alias) => format!("{alias}.{attribute}"),
            None => attribute.to_owned(),
        })
    }

    /// Whether the collection, or every document, declares `attribute`
    /// (`declaresSumAttribute()`).
    pub(crate) fn declares_sum_attribute(&self, collection: &Collection, attribute: &str) -> Result<bool> {
        Ok(internal_attributes(true).iter().any(|a| a.key == attribute)
            || collection.attributes()?.iter().any(|a| a.key == attribute))
    }

    /// `sum()` adds up what a sum aggregate adds up: a numeric attribute
    /// that is not an array, of the main collection or, under a join alias,
    /// of the collection that join reads (`validateSumAttribute()`).
    ///
    /// Fails ([`crate::Kind::Query`]) with the aggregate validator's
    /// description.
    async fn validate_sum_attribute(
        &self,
        collection: &Collection,
        attribute: &str,
        queries: &[Query],
        joined_collections: Option<&Joined>,
    ) -> Result<()> {
        let attributes = collection.attributes()?;
        if let Some(declared) = attributes.iter().find(|a| a.key == attribute)
            && !declared.array
            && declared.is_numeric()
        {
            return Ok(());
        }

        let support = self.inner.adapter.supports(Capability::DefinedAttributes);
        let query = Query::sum(attribute, "");
        let (valid, description) = if !attribute.contains('.') && self.declares_sum_attribute(collection, attribute)? {
            let validator = self.sum_validator(collection, attributes, support)?;
            (validator.check(&query), validator.description())
        } else {
            let validator = Aggregate::new(attributes, support, self.inner.adapter.shared_tables());
            let resolved;
            let joined_collections = match joined_collections {
                Some(j) => j,
                None => {
                    resolved = self.resolve_joined_collections(&join_queries(queries)).await?;
                    &resolved
                }
            };
            let mut joins = Vec::new();
            for (alias, joined) in aliased_join_collections(queries, joined_collections) {
                joins.push(Arc::new(JoinedCollection::of(alias, &joined)?));
            }
            validator.allow_joins(&joins);
            (validator.check(&query), validator.description())
        };
        if !valid {
            return Err(Error::query(format!("Invalid query: {description}")));
        }
        Ok(())
    }

    /// The aggregate validator of a sum of an attribute the collection
    /// declares, built once per collection schema (`getSumValidator()`). It
    /// is never handed joins, so no state of one sum reaches the next.
    fn sum_validator(
        &self,
        collection: &Collection,
        attributes: &[crate::attribute::Attribute],
        support_for_attributes: bool,
    ) -> Result<Arc<Aggregate>> {
        let shared_tables = self.inner.adapter.shared_tables();
        let key = format!(
            "{}::{}{}::{}",
            self.collection_metadata_cache_key(collection.id()),
            i64::from(support_for_attributes),
            i64::from(shared_tables),
            collection.fingerprint()?
        );
        if let Some(v) = self.settings().sum_validators.get(&key) {
            return Ok(v.clone());
        }
        let validator = Arc::new(Aggregate::new(attributes, support_for_attributes, shared_tables));
        let mut settings = self.settings();
        if settings.sum_validators.len() >= VALIDATOR_CACHE_LIMIT {
            settings.sum_validators.clear();
        }
        settings.sum_validators.insert(key, validator.clone());
        Ok(validator)
    }

    /// One row per group of the aggregation `queries` (count, sum, avg, min,
    /// max, groupBy) (`aggregate()`). An aggregate without an alias comes
    /// back under `<method>_<attribute>`, or `<method>` for `count('*')`.
    ///
    /// Fails ([`crate::Kind::Query`]) when the queries hold no aggregate or
    /// `groupBy`, and when two aggregates share an alias.
    pub async fn aggregate(&self, collection: &str, queries: Vec<Query>) -> Result<Vec<Array>> {
        let definition = self.silent(self.get_collection(collection)).await?;
        let queries = alias_aggregates(self.rewrite_queries(Event::DocumentAggregate, &definition, queries).await?)?;

        let floating: Vec<String> = queries
            .iter()
            .filter(|q| FLOATING_AGGREGATES.contains(&q.method()))
            .map(|q| q.alias().to_owned())
            .collect();
        let fetched = self.fetch_read(&definition, queries.clone(), PermissionType::Read, true).await?;
        let aggregation = Aggregation::from(self, &definition, &fetched.joined_by_alias, &queries);

        let mut rows: Vec<Array> = Vec::with_capacity(fetched.documents.len());
        for row in fetched.documents {
            let row = row.array_copy();
            for alias in &floating {
                assert_finite(row.get_str(alias))?;
            }
            rows.push(aggregation.decode(row).await?);
        }

        self.trigger(Event::DocumentAggregate, || Domain::DocumentAggregated {
            collection: collection.to_owned(),
            rows: rows.clone(),
        })
        .await?;

        Ok(rows)
    }

    /// The documents matching `queries`, read lazily in pages of
    /// `batch_size` (`cursor()`, PHP's generator). A limit in the queries
    /// caps the iteration, and an offset or a `cursorAfter` positions the
    /// first page only. Each page is read when the stream reaches it; a
    /// page that cannot be paged past fails before its documents are
    /// yielded.
    ///
    /// With a query hook registered, the collection is resolved and the
    /// queries rewritten here, so a missing collection fails here.
    ///
    /// Fails ([`crate::Kind::Database`]) for a `cursorBefore`, and
    /// ([`crate::Kind::Limit`]) for a batch above [`super::super::BATCH_SIZE`].
    pub async fn cursor(
        &self,
        collection: &str,
        queries: Vec<Query>,
        batch_size: i64,
        for_permission: PermissionType,
    ) -> Result<BoxStream<'static, Result<Document>>> {
        let batch_size = self::batch_size(batch_size)?;

        let hooked = !self.settings().query_hooks.is_empty();
        let queries = if hooked {
            let definition = self.silent(self.get_collection(collection)).await?;
            self.rewrite_queries(Event::DocumentFind, &definition, queries).await?
        } else {
            queries
        };

        let parsed = Query::group_by_type(&queries)?;
        if parsed.cursor.is_some() && parsed.cursor_direction == Some(CursorDirection::Before) {
            return Err(Error::database(format!(
                "Cursor {} not supported in this method.",
                CursorDirection::Before.as_str()
            )));
        }

        let filters: Vec<Query> = queries
            .into_iter()
            .filter(|q| {
                !matches!(q.method(), Method::Limit | Method::Offset | Method::CursorAfter | Method::CursorBefore)
            })
            .collect();

        let pages = Pages {
            database: self.clone(),
            collection: collection.to_owned(),
            queries: filters,
            batch_size,
            for_permission,
            remaining: parsed.limit,
            offset: parsed.offset,
            cursor: parsed.cursor,
            check: None,
            buffer: VecDeque::new(),
            done: false,
        };
        Ok(Box::pin(futures_util::stream::unfold(pages, |mut pages| async move {
            loop {
                if let Some(document) = pages.buffer.pop_front() {
                    return Some((Ok(document), pages));
                }
                if pages.done {
                    return None;
                }
                if let Err(e) = pages.next().await {
                    pages.done = true;
                    return Some((Err(e), pages));
                }
            }
        })))
    }

    /// What `find()` checks of the cursor it is given for a joined or
    /// distinct read, run on a page's last row before the page is yielded
    /// (`nextPageCheck()`).
    async fn next_page_check(&self, collection: &str, queries: &[Query]) -> Result<PageCheck> {
        let parsed = Query::group_by_type(queries)?;
        if (parsed.joins.is_empty() && !parsed.distinct) || parsed.is_aggregation() {
            return Ok(PageCheck::None);
        }
        let collection = self.silent(self.get_collection(collection)).await?;
        let parsed = Query::group_by_type(&Database::resolve_one_to_many_joins(&collection, queries.to_vec())?)?;
        let joined_by_id = self.resolve_joined_collections(&parsed.joins).await?;
        let joined_collections = joined_by_alias(&parsed.joins, &joined_by_id);
        Ok(PageCheck::Check(Box::new(Check { collection, parsed, joined_collections })))
    }

    /// A cursor on a bare order name only one join declares carries the
    /// value under `alias.name`, the name the adapter orders by: the order
    /// is qualified, and a cursor value under the bare name follows it
    /// (`qualifyJoinedOrders()`).
    ///
    /// Fails ([`crate::Kind::Query`]) for a name several joins declare.
    pub(crate) fn qualify_joined_orders(
        &self,
        collection: &Collection,
        mut order_attributes: Vec<String>,
        mut cursor: Document,
        joined_collections: &Joined,
    ) -> Result<(Vec<String>, Document)> {
        for attribute in order_attributes.iter_mut() {
            let qualified = self.qualify_joined_attribute(collection, attribute, joined_collections)?;
            if qualified == *attribute {
                continue;
            }
            if cursor.has(attribute) && !cursor.has(&qualified) {
                let value = cursor.get(attribute).cloned().unwrap_or(Value::Null);
                cursor.set(qualified.as_str(), value)?;
            }
            *attribute = qualified;
        }
        Ok((order_attributes, cursor))
    }
}

/// Whether the queries read rows other than one per matching document:
/// groups, aggregates or distinct values (`shapesRows()`).
fn shapes_rows(parsed: &ParsedQuery) -> bool {
    parsed.is_aggregation() || parsed.distinct
}

/// SQLite, MongoDB and the adapters that sum in PHP answer a double that
/// overflowed with infinity, which no caller can tell from a value, so it
/// is refused as MariaDB, MySQL and PostgreSQL refuse it (`assertFinite()`):
/// a float that is not finite, or a string naming one (`INF`, `-inf`,
/// `Infinity`, `NaN`, ...).
///
/// Fails ([`crate::Kind::Limit`]) with `Value out of range`.
fn assert_finite(value: Option<&Value>) -> Result<()> {
    let finite = match value {
        Some(Value::Float(f)) => f.is_finite(),
        Some(Value::Str(s)) => {
            // PCRE's `$` without `/D` also matches before a final newline.
            let text = s.as_bytes();
            let text = text
                .strip_suffix(
                    b"
",
                )
                .unwrap_or(text);
            let unsigned = text.strip_prefix(b"+").or_else(|| text.strip_prefix(b"-")).unwrap_or(text);
            !(unsigned.eq_ignore_ascii_case(b"inf")
                || unsigned.eq_ignore_ascii_case(b"infinity")
                || unsigned.eq_ignore_ascii_case(b"nan"))
        }
        _ => true,
    };
    if finite { Ok(()) } else { Err(Error::limit("Value out of range")) }
}

/// The state of a [`Database::cursor`] stream (`pages()`).
struct Pages {
    database: Database,
    collection: String,
    /// The queries without their limit, offset and cursor.
    queries: Vec<Query>,
    batch_size: i64,
    for_permission: PermissionType,
    remaining: Option<i64>,
    offset: Option<i64>,
    cursor: Option<Document>,
    check: Option<PageCheck>,
    buffer: VecDeque<Document>,
    done: bool,
}

impl Pages {
    /// Reads the next page into the buffer, marking the stream done after
    /// the last one.
    async fn next(&mut self) -> Result<()> {
        if self.remaining.is_some_and(|r| r <= 0) {
            self.done = true;
            return Ok(());
        }
        let size = match self.remaining {
            None => self.batch_size,
            Some(r) => self.batch_size.min(r),
        };
        let mut page = vec![Query::limit(size)];
        if let Some(offset) = self.offset {
            page.push(Query::offset(offset));
        }
        if let Some(cursor) = &self.cursor {
            page.push(Query::cursor_after(cursor));
        }
        page.extend(self.queries.iter().cloned());

        let documents = self.database.find(&self.collection, page, self.for_permission).await?;
        let pages = !documents.is_empty() && documents.len() as i64 == size && self.remaining.is_none_or(|r| r > size);

        if pages && let Some(last) = documents.last() {
            if self.check.is_none() {
                self.check = Some(self.database.next_page_check(&self.collection, &self.queries).await?);
            }
            if let Some(check) = &self.check {
                check.run(&self.database, last)?;
            }
        }

        let last = documents.last().cloned();
        let count = documents.len() as i64;
        self.buffer.extend(documents);

        if !pages {
            self.done = true;
            return Ok(());
        }
        if let Some(remaining) = &mut self.remaining {
            *remaining -= count;
        }
        self.offset = None;
        self.cursor = last;
        Ok(())
    }
}

/// What `nextPageCheck()` returns: nothing to check, or the checks of a
/// joined or distinct read.
enum PageCheck {
    None,
    Check(Box<Check>),
}

/// The read a joined or distinct [`PageCheck`] checks cursors for.
struct Check {
    collection: Collection,
    parsed: ParsedQuery,
    joined_collections: Joined,
}

impl PageCheck {
    /// Runs the check on a page's last row.
    fn run(&self, database: &Database, cursor: &Document) -> Result<()> {
        let PageCheck::Check(check) = self else { return Ok(()) };
        let parsed = &check.parsed;
        let mut orders = parsed.order_attributes.clone();
        let mut cursor = cursor.clone();
        if !check.joined_collections.is_empty() {
            (orders, cursor) =
                database.qualify_joined_orders(&check.collection, orders, cursor, &check.joined_collections)?;
        }
        if parsed.distinct {
            assert_distinct_cursor_order(&parsed.selections, &orders)?;
        } else {
            (orders, _) = add_tie_breaks(
                orders,
                parsed.order_types.clone(),
                &parsed.filters,
                &parsed.joins,
                &check.joined_collections,
                true,
                &parsed.selections,
            );
        }
        assert_cursor_has_order_values(&cursor, &orders)
    }
}

/// An associative array of `pairs`, in order.
fn assoc(pairs: Vec<(&str, Value)>) -> Value {
    Value::Array(pairs.into_iter().map(|(k, v)| (ArrayKey::normalize(Str::from(k)), v)).collect())
}

/// The join queries among `queries`.
fn join_queries(queries: &[Query]) -> Vec<Query> {
    queries.iter().filter(|q| q.method().is_join()).cloned().collect()
}

/// The collection each join reads, by its alias (`aliasedJoinCollections()`).
fn aliased_join_collections(queries: &[Query], joined_collections: &Joined) -> Joined {
    joined_by_alias(&join_queries(queries), joined_collections)
}

/// The aggregates of `queries`, each without an alias given
/// `<method>_<attribute>` (or `<method>` for `*`), with every character
/// but ASCII letters, digits and `_` replaced by `_`, cut to
/// [`MAX_ALIAS_LENGTH`] bytes (`aliasAggregates()`).
///
/// Fails ([`crate::Kind::Query`]) when two aggregates are given the same
/// alias, or an aggregate's default alias is already taken.
pub(crate) fn alias_aggregates(mut queries: Vec<Query>) -> Result<Vec<Query>> {
    let mut taken: Vec<String> = Vec::new();
    for query in &queries {
        let alias = query.alias();
        if !query.method().is_aggregate() || alias.is_empty() {
            continue;
        }
        if taken.iter().any(|t| t == alias) {
            return Err(Error::query(format!(
                "Invalid query: Aggregate alias \"{alias}\" is given to more than one aggregate"
            )));
        }
        taken.push(alias.to_owned());
    }

    for query in queries.iter_mut() {
        let method = query.method();
        if !method.is_aggregate() || !query.alias().is_empty() {
            continue;
        }
        let alias = Aggregation::alias(query);

        if taken.contains(&alias) {
            return Err(Error::query(format!(
                "The default aggregate alias '{alias}' is used by more than one aggregate; give each of them an explicit alias"
            )));
        }
        taken.push(alias.clone());

        *query = Query::new(method, query.attribute().to_owned(), query.values().clone(), alias);
    }
    Ok(queries)
}

/// The orders with the tie breaks that make a page boundary stable
/// (`addTieBreaks()`): `$sequence` unless the orders hold `$id` or
/// `$sequence` (in the leading order's direction when it is `$createdAt` or
/// `$updatedAt`), then each joined `$id` where the rows show the join or a
/// cursor pages them, unless the join matches at most one row. A vector
/// search without a cursor gets none: the index answers only the distance.
pub(crate) fn add_tie_breaks(
    mut order_attributes: Vec<String>,
    mut order_types: Vec<OrderDirection>,
    filters: &[Query],
    joins: &[Query],
    joined_collections: &Joined,
    paged: bool,
    selects: &[Query],
) -> (Vec<String>, Vec<OrderDirection>) {
    let unique =
        order_attributes.iter().any(|a| a == document::ID) || order_attributes.iter().any(|a| a == document::SEQUENCE);
    let vector_search = filters
        .iter()
        .any(|f| matches!(f.method(), Method::VectorCosine | Method::VectorDot | Method::VectorEuclidean));
    if vector_search && !paged {
        return (order_attributes, order_types);
    }

    if !unique {
        let leading = order_attributes.first().cloned();
        let leading_type = order_types.first().copied().unwrap_or(OrderDirection::Asc);
        order_attributes.push(document::SEQUENCE.to_owned());
        order_types.push(
            if leading.as_deref().is_some_and(|l| l == document::CREATED_AT || l == document::UPDATED_AT) {
                leading_type
            } else {
                OrderDirection::Asc
            },
        );
    }

    if joins.is_empty() || (!paged && !shows_joined_rows(selects, joined_collections)) {
        return (order_attributes, order_types);
    }

    if joined_collections.len() == joins.len() {
        for (position, join) in joins.iter().enumerate() {
            let alias = &joined_collections[position].0;
            let joined_id = format!("{alias}.{}", document::ID);
            let joined_sequence = format!("{alias}.{}", document::SEQUENCE);
            if !join_matches_at_most_one_row(join, alias)
                && !order_attributes.contains(&joined_id)
                && !order_attributes.contains(&joined_sequence)
            {
                order_attributes.push(joined_id);
                order_types.push(OrderDirection::Asc);
            }
        }
    }

    (order_attributes, order_types)
}

/// Whether a read's rows show anything of its joins (`showsJoinedRows()`):
/// no select, `*`, or an attribute under a join alias.
fn shows_joined_rows(selects: &[Query], joined_collections: &Joined) -> bool {
    if selects.is_empty() {
        return true;
    }
    for select in selects {
        for value in select.values().values() {
            let QValue::Str(s) = value else { continue };
            let s = String::from_utf8_lossy(s.as_bytes());
            if s == "*" {
                return true;
            }
            if let Some(dot) = s.find('.')
                && joined(joined_collections, &s[..dot]).is_some()
            {
                return true;
            }
        }
    }
    false
}

/// Whether an inner or left join on the joined `$id` pairs each row with at
/// most one joined row (`joinMatchesAtMostOneRow()`).
fn join_matches_at_most_one_row(join: &Query, alias: &str) -> bool {
    let on = join.join_on_queries();
    if !matches!(join.method(), Method::Join | Method::LeftJoin) || on.len() != 1 || on[0].method() != Method::On {
        return false;
    }
    let values: Vec<&QValue> = on[0].values().values().collect();
    let text = |i: usize| match values.get(i) {
        Some(QValue::Str(s)) => Some(String::from_utf8_lossy(s.as_bytes()).into_owned()),
        _ => None,
    };
    let (Some(left), Some(operator), Some(right)) = (text(0), text(1), text(2)) else {
        return false;
    };
    if operator != "=" {
        return false;
    }
    (right == document::ID || right == format!("{alias}.{}", document::ID)) && !left.starts_with(&format!("{alias}."))
}

/// A distinct row has no id: only its order values tell it from the next
/// one, so the order has to name every attribute the read selects
/// (`assertDistinctCursorOrder()`).
///
/// Fails ([`crate::Kind::Query`]) otherwise.
fn assert_distinct_cursor_order(selects: &[Query], order_attributes: &[String]) -> Result<()> {
    let mut selected: Vec<String> = Vec::new();
    for select in selects {
        for value in select.values().values() {
            if let QValue::Str(s) = value {
                selected.push(String::from_utf8_lossy(s.as_bytes()).into_owned());
            }
        }
    }
    if selected.iter().any(|a| a.ends_with('*')) {
        selected.clear();
    }
    if selected.is_empty() || order_attributes.is_empty() {
        return Err(Error::query(
            "A cursor on a distinct() read pages along its orders, so the read needs a select() of named attributes and an order on each of them",
        ));
    }
    for attribute in &selected {
        if !order_attributes.contains(attribute) {
            return Err(Error::query(format!(
                "A cursor on a distinct() read pages along its orders, so the read must order by every selected attribute, and '{attribute}' is not ordered"
            )));
        }
    }
    Ok(())
}

/// A cursor names its row by the values of the read's order, each under the
/// name the read orders by (`assertCursorHasOrderValues()`).
///
/// Fails ([`crate::Kind::Order`]) for a value it lacks.
fn assert_cursor_has_order_values(cursor: &Document, order_attributes: &[String]) -> Result<()> {
    for order in order_attributes {
        if !cursor.has(order) {
            return Err(Error::order(
                format!(
                    "Cursor has no value for order attribute '{order}'. Use a row this read returned as the cursor, and select '{order}' when the read selects attributes."
                ),
                Some(order.clone()),
            ));
        }
    }
    Ok(())
}

/// `(string) $value` of a sequence.
pub(crate) fn sequence_text(value: &Value) -> String {
    to_text(value)
}

/// The types a cursor cannot page along: no value of theirs compares as the
/// order does (`UNCOMPARABLE_TYPES`).
const UNCOMPARABLE_TYPES: [utopia_query::schema::ColumnType; 6] = [
    utopia_query::schema::ColumnType::Json,
    utopia_query::schema::ColumnType::Object,
    utopia_query::schema::ColumnType::Point,
    utopia_query::schema::ColumnType::Linestring,
    utopia_query::schema::ColumnType::Polygon,
    utopia_query::schema::ColumnType::Vector,
];

/// `assertCursorOrdersAreComparable()` (8.3.4): a cursor pages along the
/// values of a read's order, which the engine compares with the cursor's;
/// an array, object, spatial or vector attribute holds no value it can
/// compare, so a read ordered by one cannot be paged by a cursor, under a
/// join alias too.
///
/// Fails ([`crate::Kind::Query`]) for such an order.
fn assert_cursor_orders_are_comparable(collection: &Collection, orders: &[String], joined: &Joined) -> Result<()> {
    for order in orders {
        let (owner, key) = match order.split_once('.') {
            None => (Some(collection), order.as_str()),
            Some((alias, key)) => (joined.iter().find(|(a, _)| a == alias).map(|(_, c)| c), key),
        };
        let Some(owner) = owner else { continue };
        for attribute in owner.attributes()? {
            if attribute.key == key && (attribute.array || UNCOMPARABLE_TYPES.contains(&attribute.kind)) {
                return Err(Error::query(format!(
                    "Cannot page with a cursor along the order of '{order}': an array, object, spatial or vector attribute has no value a cursor can compare"
                )));
            }
        }
    }
    Ok(())
}
