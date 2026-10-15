//! Tests of the caches over the Memory adapter.
//!
//! PHP: the `Adapter\Memory` cases of `tests/unit/Cache/*`,
//! `WithCacheTest`, `WithCacheLeaseTest`, `Documents/WithCacheTest`,
//! `Documents/DocumentCache*Test`, `Documents/Transaction*ReadTest`,
//! `Event/DocumentPurgeTest` and `Transactions/NoRetryTest`.

mod database;
mod epoch;
mod lease;
mod query_purge;
mod scope;
mod with_cache;

use std::future::Future;
use std::sync::Arc;

use futures_util::future::BoxFuture;
use utopia_cache::Cache;

use super::observed::{Observed, Op};
use crate::cache::Query as QueryCache;
use crate::cache::testing::{Leasable, over};
use crate::error::Result;

/// A query cache over a fresh `LeasableHashCache`.
pub(crate) fn query_cache() -> Arc<QueryCache> {
    Arc::new(QueryCache::new(over(&Leasable::lenient()), QueryCache::SLOTS).unwrap())
}

/// A cache that stores nothing (`new Cache(new None())`).
pub(crate) fn none_cache() -> Arc<Cache> {
    Arc::new(Cache::from_dyn(Arc::new(utopia_cache::None)))
}

/// How many `find()`s of `collection` the adapter ran (`getObservedFinds()`).
pub(crate) fn finds(adapter: &Observed, collection: &str) -> usize {
    adapter.count(|op| matches!(op, Op::Find { collection: c } if c == collection))
}

/// How many reads of the definition of `collection` the adapter ran
/// (`getObservedMetadataReads()`).
pub(crate) fn definition_reads(adapter: &Observed, collection: &str) -> usize {
    adapter.count(|op| matches!(op, Op::GetDocument { collection: c, id } if c == super::METADATA && id == collection))
}

/// Runs `future` before the adapter's next commit (`pauseNextCommit()`).
pub(crate) fn on_commit(adapter: &Observed, future: impl Future<Output = Result<()>> + Send + 'static) {
    let mut future: Option<BoxFuture<'static, Result<()>>> = Some(Box::pin(future));
    adapter.once(move |op| if *op == Op::Commit { future.take() } else { None });
}

/// Runs `future` after the adapter's next read of the definition of
/// `collection` (`observeMetadata()`, `afterNextDefinitionRead()`).
pub(crate) fn after_definition_read(
    adapter: &Observed,
    collection: &str,
    future: impl Future<Output = Result<()>> + Send + 'static,
) {
    let collection = collection.to_owned();
    let mut future: Option<BoxFuture<'static, Result<()>>> = Some(Box::pin(future));
    adapter.once(move |op| match op {
        Op::GetDocument { collection: c, id } if c == super::METADATA && *id == collection => future.take(),
        _ => None,
    });
}
