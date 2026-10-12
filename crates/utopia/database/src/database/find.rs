//! Finding documents: `find`, `find_one`, `count`, `sum`, `foreach`,
//! `iterate`, and the query conversion and checks they run.
//!
//! Area: documents.
//!
//! Validators these call (being ported in `crate::validator`):
//! `DocumentsValidator` (`queries::documents`), `Authorization`.

// TEMPORARY (phase A skeleton): the `todo!()` bodies leave parameters and
// helpers unused until the documents area is implemented.
#![allow(unused_variables, dead_code)]

use super::{BoxStream, Callback, Database};
use crate::document::Document;
use crate::error::Result;
use crate::query::Query;
use crate::value::Value;

impl Database {
    /// The documents of `collection` matching `queries` that the
    /// authorization allows for `for_permission` (filters, selections,
    /// order, limit, offset, cursor), with relationships resolved; triggers
    /// [`Database::EVENT_DOCUMENT_FIND`].
    ///
    /// PHP: `Database::find($collection, $queries = [], $forPermission = Database::PERMISSION_READ)`.
    pub async fn find(&self, collection: &str, queries: Vec<Query>, for_permission: &str) -> Result<Vec<Document>> {
        todo!("documents")
    }

    /// The first document matching `queries` (empty when none).
    ///
    /// PHP: `Database::findOne()`.
    pub async fn find_one(&self, collection: &str, queries: Vec<Query>) -> Result<Document> {
        todo!("documents")
    }

    /// The number of documents matching `queries`, counting at most `max`;
    /// triggers [`Database::EVENT_DOCUMENT_COUNT`].
    ///
    /// PHP: `Database::count($collection, $queries = [], $max = null)`.
    pub async fn count(&self, collection: &str, queries: Vec<Query>, max: Option<i64>) -> Result<i64> {
        todo!("documents")
    }

    /// The sum of `attribute` over the documents matching `queries` (at
    /// most `max` of them): an int or a float; triggers
    /// [`Database::EVENT_DOCUMENT_SUM`].
    ///
    /// PHP: `Database::sum($collection, $attribute, $queries = [], $max = null)`.
    pub async fn sum(&self, collection: &str, attribute: &str, queries: Vec<Query>, max: Option<i64>) -> Result<Value> {
        todo!("documents")
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
        todo!("documents")
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
        todo!("documents")
    }

    /// `queries` with their values converted to what the adapter expects
    /// for each attribute's type (dates, nested queries, ...).
    ///
    /// PHP: `Database::convertQueries()`.
    pub fn convert_queries(&self, collection: &Document, queries: Vec<Query>) -> Result<Vec<Query>> {
        todo!("documents")
    }

    /// One query converted as [`Database::convert_queries`] does.
    ///
    /// PHP: `Database::convertQuery()`.
    pub fn convert_query(&self, collection: &Document, query: Query) -> Result<Query> {
        todo!("documents")
    }

    /// Whether `values` suit a query on an `object` attribute.
    ///
    /// PHP: `Database::isCompatibleObjectValue()` (private).
    pub(crate) fn is_compatible_object_value(&self, values: &[Value]) -> bool {
        todo!("documents")
    }

    /// The attributes `select` queries select, checked against
    /// `collection`.
    ///
    /// PHP: `Database::validateSelections()` (private).
    pub(crate) fn validate_selections(&self, collection: &Document, queries: &[Query]) -> Result<Vec<String>> {
        todo!("documents")
    }

    /// Checks that nested query values are queries.
    ///
    /// PHP: `Database::checkQueryTypes()` (private). Top-level queries are
    /// typed here; only the values of nested queries (`and`, `or`,
    /// `elemMatch`, ...) can hold something else.
    pub(crate) fn check_query_types(&self, queries: &[Query]) -> Result<()> {
        todo!("documents")
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
