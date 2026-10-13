//! Finding documents: `find`, `find_one`, `count`, `sum`, `foreach`,
//! `iterate`, and the query conversion and checks they run.
//!
//! Area: documents.

use std::collections::VecDeque;

use futures_util::StreamExt;
use futures_util::future::BoxFuture;

use super::documents::{attribute_documents, relationship_attributes};
use super::{BoxStream, Callback, Database};
use crate::adapter::FindOptions;
use crate::document::Document;
use crate::error::{Error, Kind, Result};
use crate::query::{Method, Query};
use crate::value::{Array, Ext, Value, to_text};

impl Database {
    /// The documents of `collection` matching `queries` that the
    /// authorization allows for `for_permission` (filters, selections,
    /// order, limit, offset, cursor), with relationships resolved; triggers
    /// [`Database::EVENT_DOCUMENT_FIND`].
    ///
    /// PHP: `Database::find($collection, $queries = [], $forPermission = Database::PERMISSION_READ)`.
    ///
    /// Boxed: relationship population and relationship queries find
    /// through here.
    pub fn find<'a>(
        &'a self,
        collection: &'a str,
        queries: Vec<Query>,
        for_permission: &'a str,
    ) -> BoxFuture<'a, Result<Vec<Document>>> {
        Box::pin(self.find_documents(collection, queries, for_permission))
    }

    /// [`Database::find`].
    async fn find_documents(
        &self,
        collection: &str,
        queries: Vec<Query>,
        for_permission: &str,
    ) -> Result<Vec<Document>> {
        let collection = self.silent(|| self.get_collection(collection), None).await?;
        if collection.is_empty() {
            return Err(Error::not_found("Collection not found"));
        }
        let adapter = self.inner.adapter.clone();

        self.validate_queries(&collection, &queries)?;

        let document_security = collection.get("documentSecurity").is_some_and(Value::truthy);
        let skip_auth = self.allowed(for_permission, collection.permissions_by_type(for_permission));
        if !skip_auth && !document_security && collection.id() != Self::METADATA {
            return Err(self.unauthorized());
        }

        let relationships = relationship_attributes(&collection);
        let grouped = Query::group_by_type(&queries);
        let filters = grouped.filters;
        let selects = grouped.selections;
        let limit = grouped.limit.as_ref().map(crate::value::to_long);
        let offset = grouped.offset.as_ref().map(crate::value::to_long);
        let mut order_attributes = grouped.order_attributes;
        let mut order_types: Vec<String> = grouped.order_types.iter().map(|t| (*t).to_owned()).collect();
        let cursor = grouped.cursor.filter(Value::truthy);
        let cursor_direction = grouped.cursor_direction.unwrap_or(Self::CURSOR_AFTER);

        let unique_order_by = order_attributes.iter().any(|o| o == "$id" || o == "$sequence");
        let vector_search = filters.iter().any(|f| f.method().is_vector());

        if !unique_order_by && (!vector_search || cursor.is_some()) {
            let leading_attribute = order_attributes.first().cloned();
            let leading_order_type = order_types.first().cloned().unwrap_or_else(|| Self::ORDER_ASC.to_owned());
            order_attributes.push("$sequence".to_owned());
            if matches!(leading_attribute.as_deref(), Some("$createdAt" | "$updatedAt")) {
                order_types.push(leading_order_type);
            } else {
                order_types.push(Self::ORDER_ASC.to_owned());
            }
        }

        let cursor = match cursor {
            Some(Value::Ext(Ext::Document(cursor))) => {
                for order in &order_attributes {
                    if cursor.get(order).is_none() {
                        return Err(Error::order(format!("Order attribute '{order}' is empty"), Some(order.clone())));
                    }
                }
                if cursor.collection() != collection.id() {
                    return Err(Error::database("cursor Document must be from the same Collection."));
                }
                let cursor = self.encode(&collection, *cursor, true).await?;
                adapter.casting_before(&collection, cursor)?.into_array()
            }
            Some(other) => {
                return Err(Error::new(
                    Kind::Error,
                    format!("Call to a member function getAttribute() on {}", other.debug_type()),
                ));
            }
            None => Array::new(),
        };

        let mut queries = selects.clone();
        queries.extend(self.convert_queries(&collection, filters)?);

        let selections = self.validate_selections(&collection, &selects)?;
        let nested_selections = if relationships.is_empty() {
            Vec::new()
        } else {
            self.process_relationship_queries(&relationships, &mut queries)?
        };

        let queries = if relationships.is_empty() {
            Some(queries)
        } else {
            self.convert_relationship_queries(&relationships, queries, Some(&collection)).await?
        };

        let mut results = match queries {
            None => Vec::new(),
            Some(queries) => {
                let options = FindOptions {
                    limit: Some(limit.unwrap_or(25)),
                    offset: Some(offset.unwrap_or(0)),
                    order_attributes: &order_attributes,
                    order_types: &order_types,
                    cursor: &cursor,
                    cursor_direction,
                    for_permission,
                };
                let get_results = || adapter.find(&collection, &queries, options.clone());
                if skip_auth { self.authorization().skip(get_results).await? } else { get_results().await? }
            }
        };

        let (in_batch, resolve, depth) = {
            let s = self.state();
            (s.in_batch_relationship_population, s.resolve_relationships, s.relationship_fetch_depth)
        };
        if !in_batch
            && resolve
            && !relationships.is_empty()
            && (selects.is_empty() || !nested_selections.is_empty())
            && !results.is_empty()
        {
            results = self
                .silent(|| self.populate_documents_relationships(results, &collection, depth, &nested_selections), None)
                .await?;
        }

        let mut documents = Vec::with_capacity(results.len());
        for node in results {
            let node = adapter.casting_after(&collection, node)?;
            let node = self.casting(&collection, node)?;
            let node = self.decode(&collection, node, &selections).await?;
            let mut node = self.typed(collection.id(), node)?;
            if !node.is_empty() {
                node.set("$collection", Value::from(collection.id()));
            }
            documents.push(node);
        }

        self.trigger_with(Self::EVENT_DOCUMENT_FIND, || {
            Value::Array(documents.iter().cloned().map(Value::from).collect())
        })?;
        Ok(documents)
    }

    /// The first document matching `queries` (empty when none).
    ///
    /// PHP: `Database::findOne()`.
    pub async fn find_one(&self, collection: &str, queries: Vec<Query>) -> Result<Document> {
        let mut all = vec![Query::limit(1)];
        all.extend(queries);
        let results = self.silent(|| self.find(collection, all, Self::PERMISSION_READ), None).await?;
        let found = results.into_iter().next();
        let payload = found.clone().map(Value::from).unwrap_or(Value::Bool(false));
        self.trigger(Self::EVENT_DOCUMENT_FIND, &payload)?;
        Ok(found.unwrap_or_default())
    }

    /// The collection, its read check and its converted filters, shared by
    /// [`Database::count`] and [`Database::sum`]: `None` for the queries when
    /// relationship filters cannot match.
    async fn aggregate_queries(
        &self,
        collection: &str,
        queries: Vec<Query>,
        filters_only: bool,
    ) -> Result<(Document, bool, Option<Vec<Query>>)> {
        let collection = self.silent(|| self.get_collection(collection), None).await?;
        if collection.is_empty() {
            return Err(Error::not_found("Collection not found"));
        }
        self.validate_queries(&collection, &queries)?;

        let document_security = collection.get("documentSecurity").is_some_and(Value::truthy);
        let skip_auth = self.allowed(Self::PERMISSION_READ, collection.read());
        if !skip_auth && !document_security && collection.id() != Self::METADATA {
            return Err(self.unauthorized());
        }

        let relationships = relationship_attributes(&collection);
        let queries = if filters_only { Query::group_by_type(&queries).filters } else { queries };
        let queries = self.convert_queries(&collection, queries)?;
        let queries = if relationships.is_empty() {
            Some(queries)
        } else {
            self.convert_relationship_queries(&relationships, queries, Some(&collection)).await?
        };
        Ok((collection, skip_auth, queries))
    }

    /// The number of documents matching `queries`, counting at most `max`;
    /// triggers [`Database::EVENT_DOCUMENT_COUNT`].
    ///
    /// PHP: `Database::count($collection, $queries = [], $max = null)`.
    pub async fn count(&self, collection: &str, queries: Vec<Query>, max: Option<i64>) -> Result<i64> {
        let (collection, skip_auth, queries) = self.aggregate_queries(collection, queries, true).await?;
        let Some(queries) = queries else {
            return Ok(0);
        };
        let adapter = &self.inner.adapter;
        let get_count = || adapter.count(&collection, &queries, max);
        let count = if skip_auth { self.authorization().skip(get_count).await? } else { get_count().await? };
        self.trigger(Self::EVENT_DOCUMENT_COUNT, &Value::Int(count))?;
        Ok(count)
    }

    /// The sum of `attribute` over the documents matching `queries` (at
    /// most `max` of them): an int or a float; triggers
    /// [`Database::EVENT_DOCUMENT_SUM`].
    ///
    /// PHP: `Database::sum($collection, $attribute, $queries = [], $max = null)`.
    pub async fn sum(&self, collection: &str, attribute: &str, queries: Vec<Query>, max: Option<i64>) -> Result<Value> {
        let (collection, skip_auth, queries) = self.aggregate_queries(collection, queries, false).await?;
        let Some(queries) = queries else {
            return Ok(Value::Int(0));
        };
        let adapter = &self.inner.adapter;
        let get_sum = || adapter.sum(&collection, attribute, &queries, max);
        let sum = if skip_auth { self.authorization().skip(get_sum).await? } else { get_sum().await? };
        self.trigger(Self::EVENT_DOCUMENT_SUM, &sum)?;
        Ok(sum)
    }

    /// Calls `callback` with every document matching `queries`, paging
    /// through them as [`Database::iterate`] does.
    ///
    /// PHP: `Database::foreach($collection, $callback, $queries = [], $forPermission = Database::PERMISSION_READ)`.
    pub async fn foreach(
        &self,
        collection: &str,
        callback: &mut dyn Callback<Document>,
        queries: Vec<Query>,
        for_permission: &str,
    ) -> Result<()> {
        let mut documents = self.iterate(collection, queries, for_permission);
        while let Some(document) = documents.next().await {
            callback.call(document?).await?;
        }
        Ok(())
    }

    /// Every document matching `queries`, fetched lazily page by page (the
    /// query's limit, 25 by default) with a cursor after the last document
    /// of the previous page. The stream owns a clone of the handle; it ends
    /// at the first error. A `cursorBefore` query is an error.
    ///
    /// PHP: `Database::iterate()` (a `Generator`).
    pub fn iterate(
        &self,
        collection: &str,
        queries: Vec<Query>,
        for_permission: &str,
    ) -> BoxStream<'static, Result<Document>> {
        let grouped = Query::group_by_type(&queries);
        let limit_exists = grouped.limit.is_some();
        let limit = grouped.limit.clone().unwrap_or(Value::Int(25));
        let has_offset = grouped.offset.is_some();
        let cursor_before = grouped.cursor.is_some() && grouped.cursor_direction == Some(Self::CURSOR_BEFORE);

        struct Pages {
            db: Database,
            collection: String,
            queries: Vec<Query>,
            for_permission: String,
            limit: Value,
            limit_exists: bool,
            has_offset: bool,
            /// `$sum === $limit`: whether another page is due.
            more: bool,
            latest: Option<Document>,
            buffer: VecDeque<Document>,
            failed: Option<Error>,
            done: bool,
        }

        let pages = Pages {
            db: self.clone(),
            collection: collection.to_owned(),
            queries,
            for_permission: for_permission.to_owned(),
            limit,
            limit_exists,
            has_offset,
            more: true,
            latest: None,
            buffer: VecDeque::new(),
            failed: cursor_before
                .then(|| Error::database(format!("Cursor {} not supported in this method.", Self::CURSOR_BEFORE))),
            done: false,
        };

        futures_util::stream::unfold(pages, |mut pages| async move {
            loop {
                if pages.done {
                    return None;
                }
                if let Some(error) = pages.failed.take() {
                    pages.done = true;
                    return Some((Err(error), pages));
                }
                if let Some(document) = pages.buffer.pop_front() {
                    return Some((Ok(document), pages));
                }
                if !pages.more {
                    return None;
                }
                let mut queries = pages.queries.clone();
                if let Some(latest) = &pages.latest {
                    if pages.has_offset {
                        queries.insert(0, Query::offset(0));
                    }
                    queries.insert(0, Query::cursor_after(latest.clone()));
                }
                if !pages.limit_exists {
                    queries.push(Query::new(Method::Limit, "", vec![pages.limit.clone()]));
                }
                match pages.db.find(&pages.collection, queries, &pages.for_permission).await {
                    Err(e) => {
                        pages.done = true;
                        return Some((Err(e), pages));
                    }
                    Ok(results) => {
                        if results.is_empty() {
                            return None;
                        }
                        pages.more = Value::Int(results.len() as i64).strict_eq(&pages.limit);
                        pages.latest = results.last().cloned();
                        pages.buffer.extend(results);
                    }
                }
            }
        })
        .boxed()
    }

    /// `queries` with their values converted to what the adapter expects
    /// for each attribute's type (dates, nested queries, ...).
    ///
    /// PHP: `Database::convertQueries()`.
    pub fn convert_queries(&self, collection: &Document, queries: Vec<Query>) -> Result<Vec<Query>> {
        let mut out = Vec::with_capacity(queries.len());
        for mut query in queries {
            if query.is_nested() {
                let mut values = Vec::with_capacity(query.values().len());
                for value in query.values() {
                    match value {
                        Value::Ext(Ext::Query(q)) => {
                            let converted = self.convert_queries(collection, vec![(**q).clone()])?;
                            values.extend(converted.into_iter().map(Value::from));
                        }
                        other => values.push(other.clone()),
                    }
                }
                query.set_values(values);
            }
            out.push(self.convert_query(collection, query)?);
        }
        Ok(out)
    }

    /// One query converted as [`Database::convert_queries`] does.
    ///
    /// PHP: `Database::convertQuery()`.
    pub fn convert_query(&self, collection: &Document, mut query: Query) -> Result<Query> {
        let adapter = &self.inner.adapter;
        let mut attributes = attribute_documents(collection);
        attributes.extend(Self::internal_attributes_const());

        let query_attribute = query.attribute().to_owned();
        let nested = adapter.support_for_attributes() && adapter.support_for_object() && query_attribute.contains('.');

        let mut attribute = Document::empty();
        for attr in &attributes {
            if attr.id() == query_attribute {
                attribute = attr.clone();
            } else if nested {
                let base = query_attribute.split('.').next().unwrap_or("");
                if base == attr.id()
                    && matches!(attr.get("type"), Some(Value::Str(t)) if t.as_bytes() == Self::VAR_OBJECT.as_bytes())
                {
                    query.set_attribute_type(Self::VAR_OBJECT);
                }
            }
        }

        if !attribute.is_empty() {
            query.set_on_array(attribute.get("array").is_some_and(Value::truthy));
            let kind = attribute.get("type").cloned().unwrap_or(Value::Null);
            query.set_attribute_type(to_text(&kind));
            if kind.loose_eq(&Value::from(Self::VAR_DATETIME)) {
                let mut values = Vec::with_capacity(query.values().len());
                for value in query.values() {
                    let text = to_text(value);
                    let converted = if adapter.support_for_utc_casting() {
                        adapter.set_utc_datetime(&text)
                    } else {
                        crate::datetime::set_timezone(text.as_bytes()).map(crate::value::string)
                    };
                    values.push(converted.map_err(|e| Error::query(e.message()).with_previous(e))?);
                }
                query.set_values(values);
            }
        } else if !adapter.support_for_attributes()
            && adapter.support_for_object()
            && self.is_compatible_object_value(query.values())
        {
            query.set_attribute_type(Self::VAR_OBJECT);
        }

        Ok(query)
    }

    /// Whether `values` suit a query on an `object` attribute.
    ///
    /// PHP: `Database::isCompatibleObjectValue()` (private).
    pub(crate) fn is_compatible_object_value(&self, values: &[Value]) -> bool {
        if values.is_empty() {
            return false;
        }
        for value in values {
            let Value::Array(array) = value else {
                return false;
            };
            if array.is_empty() {
                continue;
            }
            if array.is_list() {
                return false;
            }
        }
        true
    }

    /// The attributes `select` queries select, checked against
    /// `collection`.
    ///
    /// PHP: `Database::validateSelections()` (private).
    pub(crate) fn validate_selections(&self, collection: &Document, queries: &[Query]) -> Result<Vec<String>> {
        if queries.is_empty() {
            return Ok(Vec::new());
        }
        let mut selections: Vec<String> = Vec::new();
        let mut relationship_selections: Vec<String> = Vec::new();
        for query in queries {
            if *query.method() != Method::Select {
                continue;
            }
            for value in query.values() {
                let Value::Str(s) = value else {
                    return Err(Error::query(format!(
                        "Attribute selection must be a string, got {}",
                        value.debug_type()
                    )));
                };
                let text = String::from_utf8_lossy(s.as_bytes()).into_owned();
                if text.contains('.') {
                    relationship_selections.push(text);
                } else {
                    selections.push(text);
                }
            }
        }

        let mut keys: Vec<String> = self.internal_attributes().iter().map(|a| a.id().to_owned()).collect();
        for attribute in attribute_documents(collection) {
            let is_relationship = matches!(attribute.raw("type"), Some(Value::Str(t)) if t.as_bytes() == Self::VAR_RELATIONSHIP.as_bytes());
            if !is_relationship {
                let key = attribute.get("key").or_else(|| attribute.get("$id")).cloned().unwrap_or(Value::Null);
                keys.push(to_text(&key));
            }
        }
        if self.inner.adapter.support_for_attributes() {
            let invalid: Vec<&String> = selections.iter().filter(|s| !keys.contains(s)).collect();
            if !invalid.is_empty() && !invalid.iter().any(|s| s.as_str() == "*") {
                return Err(Error::query(format!(
                    "Cannot select attributes: {}",
                    invalid.iter().map(|s| s.as_str()).collect::<Vec<_>>().join(", ")
                )));
            }
        }

        selections.extend(relationship_selections);
        for internal in ["$id", "$sequence", "$collection", "$createdAt", "$updatedAt", "$permissions"] {
            selections.push(internal.to_owned());
        }
        let mut unique: Vec<String> = Vec::with_capacity(selections.len());
        for selection in selections {
            if !unique.contains(&selection) {
                unique.push(selection);
            }
        }
        Ok(unique)
    }

    /// Checks that nested query values are queries.
    ///
    /// PHP: `Database::checkQueryTypes()` (private). Top-level queries are
    /// typed here; only the values of nested queries (`and`, `or`,
    /// `elemMatch`, ...) can hold something else.
    // A method like PHP's (Rust 1.88's clippy flags `self` as only recursed with).
    #[allow(clippy::only_used_in_recursion)]
    pub(crate) fn check_query_types(&self, queries: &[Query]) -> Result<()> {
        for query in queries {
            if !query.is_nested() {
                continue;
            }
            let mut nested = Vec::with_capacity(query.values().len());
            for value in query.values() {
                match value {
                    Value::Ext(Ext::Query(q)) => nested.push((**q).clone()),
                    other => {
                        let kind = match other {
                            Value::Null => "NULL",
                            Value::Bool(_) => "boolean",
                            Value::Int(_) => "integer",
                            Value::Float(_) => "double",
                            Value::Str(_) => "string",
                            Value::Array(_) => "array",
                            _ => "object",
                        };
                        return Err(crate::error::Error::query(format!(
                            "Invalid query type: \"{kind}\". Expected instances of \"Utopia\\Database\\Query\""
                        )));
                    }
                }
            }
            self.check_query_types(&nested)?;
        }
        Ok(())
    }

    /// How many attributes a collection can have beyond the defaults (`0`:
    /// unlimited).
    ///
    /// PHP: `Database::getLimitForAttributes()`.
    pub fn limit_for_attributes(&self) -> i64 {
        let adapter = &self.inner.adapter;
        if adapter.limit_for_attributes() == 0 {
            return 0;
        }
        adapter.limit_for_attributes() - adapter.count_of_default_attributes()
    }

    /// How many indexes a collection can have beyond the defaults.
    ///
    /// PHP: `Database::getLimitForIndexes()`.
    pub fn limit_for_indexes(&self) -> i64 {
        let adapter = &self.inner.adapter;
        adapter.limit_for_indexes() - adapter.count_of_default_indexes()
    }

    /// The internal attributes (without `$tenant` unless tables are
    /// shared).
    ///
    /// PHP: `Database::getInternalAttributes()`.
    pub fn internal_attributes(&self) -> Vec<Document> {
        let mut attributes = Self::internal_attributes_const();
        if !self.shared_tables() {
            attributes.retain(|a| a.id() != "$tenant");
        }
        attributes
    }

    /// [`Database::internal_attributes`] as attribute values, built once.
    pub(crate) fn internal_attribute_values(&self) -> &'static [Value] {
        static SHARED: std::sync::LazyLock<Vec<Value>> =
            std::sync::LazyLock::new(|| Database::internal_attributes_const().into_iter().map(Value::from).collect());
        static UNSHARED: std::sync::LazyLock<Vec<Value>> = std::sync::LazyLock::new(|| {
            Database::internal_attributes_const().into_iter().filter(|a| a.id() != "$tenant").map(Value::from).collect()
        });
        if self.shared_tables() { &SHARED } else { &UNSHARED }
    }

    /// The columns of a collection's table, as the adapter reports them.
    ///
    /// PHP: `Database::getSchemaAttributes()`.
    pub async fn schema_attributes(&self, collection: &str) -> Result<Vec<Document>> {
        self.inner.adapter.schema_attributes(collection).await
    }

    /// The indexes of a collection's table, as the adapter reports them.
    ///
    /// PHP: `Database::getSchemaIndexes()`.
    pub async fn schema_indexes(&self, collection: &str) -> Result<Vec<Document>> {
        self.inner.adapter.schema_indexes(collection).await
    }
}
