//! `find()`, `findOne()`, `count()`, `sum()`, `aggregate()` and `cursor()`.

use super::super::{BoxFuture, BoxStream, Database};
use crate::document::Document;
use crate::error::Result;
use crate::permission::PermissionType;
use crate::query::Query;
use crate::value::{Array, Value};

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
            let _ = (collection, queries, for_permission);
            todo!("reads area: find")
        })
    }

    /// [`Database::find`] under a definition the caller already resolved,
    /// its queries not rewritten (`fetch()`); with `aggregate`, the queries
    /// must aggregate (`aggregate()`), else they must not.
    pub(crate) fn fetch<'a>(
        &'a self,
        collection: &'a crate::collection::Collection,
        queries: Vec<Query>,
        for_permission: PermissionType,
        aggregate: bool,
    ) -> BoxFuture<'a, Result<Vec<Document>>> {
        Box::pin(async move {
            let _ = (collection, queries, for_permission, aggregate);
            todo!("reads area: fetch")
        })
    }

    /// The first document matching `queries`, or an empty document
    /// (`findOne()`).
    pub async fn find_one(&self, collection: &str, queries: Vec<Query>) -> Result<Document> {
        let _ = (collection, queries);
        todo!("reads area: findOne")
    }

    /// How many documents match `queries`, counting at most `max`
    /// (`count()`).
    pub async fn count(&self, collection: &str, queries: Vec<Query>, max: Option<i64>) -> Result<i64> {
        let _ = (collection, queries, max);
        todo!("reads area: count")
    }

    /// The sum of `attribute` over the documents matching `queries`, over at
    /// most `max` documents (`sum()`): an integer or a float.
    pub async fn sum(&self, collection: &str, attribute: &str, queries: Vec<Query>, max: Option<i64>) -> Result<Value> {
        let _ = (collection, attribute, queries, max);
        todo!("reads area: sum")
    }

    /// One row per group of the aggregation `queries` (count, sum, avg, min,
    /// max, groupBy) (`aggregate()`).
    pub async fn aggregate(&self, collection: &str, queries: Vec<Query>) -> Result<Vec<Array>> {
        let _ = (collection, queries);
        todo!("reads area: aggregate")
    }

    /// The documents matching `queries`, read lazily in pages of
    /// `batch_size` (`cursor()`, PHP's generator).
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
        let _ = (collection, queries, batch_size, for_permission);
        todo!("reads area: cursor")
    }
}
