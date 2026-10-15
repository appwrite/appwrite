//! Tests of the caches over the Memory adapter.
//!
//! PHP: the `Adapter\Memory` cases of `tests/unit/Cache/*`,
//! `WithCacheTest`, `WithCacheLeaseTest`, `Documents/WithCacheTest`,
//! `Documents/DocumentCache*Test`, `Documents/Transaction*ReadTest`,
//! `Event/DocumentPurgeTest` and `Transactions/NoRetryTest`.

mod query_purge;

use std::sync::Arc;

use crate::cache::Query as QueryCache;
use crate::cache::testing::{Leasable, over};

/// A query cache over a fresh `LeasableHashCache`.
pub(crate) fn query_cache() -> Arc<QueryCache> {
    Arc::new(QueryCache::new(over(&Leasable::lenient()), QueryCache::SLOTS).unwrap())
}
