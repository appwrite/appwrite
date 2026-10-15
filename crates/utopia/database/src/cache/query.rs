//! [`Query`]: `find()` results cached per collection, under epochs that
//! writes block and activate.

use std::collections::HashMap;
use std::sync::{Arc, RwLock};

use utopia_cache::Cache;

use super::{Entry, Region, Scope};
use crate::document::Document;
use crate::error::Result;
use crate::value::Value;

/// Caches `find()` results per collection. It holds no database: each call
/// takes the cache name and the writer timeout of the database it runs for
/// from the [`Scope`] it is handed, so one query cache can be shared by
/// databases named and configured differently.
///
/// PHP: `Utopia\Database\Cache\Query`.
pub struct Query {
    cache: Arc<Cache>,
    slots: i64,
    regions: RwLock<HashMap<String, Region>>,
}

impl std::fmt::Debug for Query {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Query").field("slots", &self.slots).finish_non_exhaustive()
    }
}

impl Query {
    /// The default number of slots (`Query::SLOTS`).
    pub const SLOTS: i64 = 1024;

    /// A query cache over `cache` keeping at most `slots` results per
    /// collection scope (`new Query($cache, $slots)`).
    ///
    /// Fails ([`crate::Kind::InvalidArgument`]) for fewer than one slot (`A
    /// query cache needs at least one slot`).
    pub fn new(cache: Arc<Cache>, slots: i64) -> Result<Self> {
        if slots < 1 {
            return Err(crate::Error::new(crate::Kind::InvalidArgument, "A query cache needs at least one slot"));
        }
        Ok(Query { cache, slots, regions: RwLock::new(HashMap::new()) })
    }

    /// The cache it stores in.
    pub fn cache(&self) -> &Arc<Cache> {
        &self.cache
    }

    /// `setRegion()`.
    pub fn set_region(&self, collection: &str, region: Region) -> &Self {
        self.regions.write().unwrap_or_else(|e| e.into_inner()).insert(collection.to_owned(), region);
        self
    }

    /// `getRegion()`.
    pub fn region(&self, collection: &str) -> Region {
        self.regions.read().unwrap_or_else(|e| e.into_inner()).get(collection).copied().unwrap_or_default()
    }

    /// `getCollectionKey()`: `<name>:qcache:<collection>:<md5(serialize(scope))>`.
    pub fn collection_key(&self, scope: &Scope, collection: &str) -> String {
        let _ = (scope, collection);
        todo!("cache area: Cache\\Query::getCollectionKey")
    }

    /// A query's entry in the collection's current epoch; `None` while the
    /// collection's region is disabled or a write to it is in progress
    /// (`getEntry()`). `queries` is the array PHP serializes.
    pub async fn entry(
        &self,
        scope: &Scope,
        collection: &str,
        queries: &Value,
        context: &str,
    ) -> Result<Option<Entry>> {
        let _ = (scope, collection, queries, context);
        todo!("cache area: Cache\\Query::getEntry")
    }

    /// The results cached for `entry` (`get()`).
    pub async fn get(&self, entry: &Entry) -> Result<Option<Vec<Document>>> {
        let _ = entry;
        todo!("cache area: Cache\\Query::get")
    }

    /// `getGeneration()`.
    pub async fn generation(&self, entry: &Entry) -> Result<String> {
        let _ = entry;
        todo!("cache area: Cache\\Query::getGeneration")
    }

    /// Caches `results` for `entry` under `generation` (`set()`).
    pub async fn set(&self, entry: &Entry, results: &[Document], generation: &str) -> Result<bool> {
        let _ = (entry, results, generation);
        todo!("cache area: Cache\\Query::set")
    }

    /// `invalidateCollection()`: block and activate at once.
    pub async fn invalidate_collection(&self, scope: &Scope, collection: &str) -> Result<()> {
        let _ = (scope, collection);
        todo!("cache area: Cache\\Query::invalidateCollection")
    }

    /// A write's token (`createToken()`): `<time>.<32 hex>`.
    pub fn create_token(&self) -> String {
        todo!("cache area: Cache\\Query::createToken")
    }

    /// Publishes a shared tombstone before a mutation starts
    /// (`blockCollection()`).
    pub async fn block_collection(&self, key: &str, token: &str) -> Result<()> {
        let _ = (key, token);
        todo!("cache area: Cache\\Query::blockCollection")
    }

    /// Replaces this mutation's tombstone with a fresh epoch once no other
    /// mutation is in progress (`activateCollection()`).
    pub async fn activate_collection(&self, key: &str, token: &str, writer_timeout: i64) -> Result<()> {
        let _ = (key, token, writer_timeout);
        todo!("cache area: Cache\\Query::activateCollection")
    }

    /// `flush()`.
    pub async fn flush(&self) -> Result<()> {
        todo!("cache area: Cache\\Query::flush")
    }
}
