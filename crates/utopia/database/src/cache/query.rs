//! [`Query`]: `find()` results cached per collection, under epochs that
//! writes block and activate.

use std::collections::HashMap;
use std::sync::{Arc, RwLock};

use php_std::Str;
use utopia_cache::Cache;

use super::{Entry, Mutation, Region, Scope};
use crate::document::Document;
use crate::error::{Error, Kind, Result};
use crate::value::{Array, ArrayKey, Value, to_plain};

/// `VERSION`.
const VERSION: i64 = 2;
/// `BLOCK_FIELD`.
const BLOCK_FIELD: &str = "block";

/// A string of the cache.
fn text(value: &str) -> php_std::Value {
    php_std::Value::Str(Str::from(value))
}

/// Whether a loaded value is a miss (`false` or `null`).
fn missing(value: &Option<php_std::Value>) -> bool {
    matches!(value, None | Some(php_std::Value::Null) | Some(php_std::Value::Bool(false)))
}

/// Whether a cache value is the string `expected` (PHP's `===`).
fn is_text(value: Option<&php_std::Value>, expected: &str) -> bool {
    matches!(value, Some(php_std::Value::Str(s)) if s.as_bytes() == expected.as_bytes())
}

/// A `RuntimeException`.
fn runtime(message: String) -> Error {
    Error::new(Kind::Runtime, message)
}

/// `bin2hex(random_bytes(16))`.
fn random_hex() -> String {
    let mut bytes = [0u8; 16];
    getrandom::fill(&mut bytes).expect("random bytes");
    hex::encode(bytes)
}

/// `md5(serialize($value))`.
fn md5_serialized(value: &Value) -> String {
    use md5::Digest;
    hex::encode(md5::Md5::digest(php_std::serialize::serialize(value)))
}

/// An associative array of database values.
fn assoc(pairs: Vec<(&str, Value)>) -> Value {
    Value::Array(pairs.into_iter().map(|(k, v)| (ArrayKey::Str(Str::from(k)), v)).collect())
}

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
        let hash = md5_serialized(&assoc(vec![
            ("hostname", Value::Str(Str::from(scope.hostname.as_str()))),
            ("database", Value::Str(Str::from(scope.database.as_str()))),
            ("namespace", Value::Str(Str::from(scope.namespace.as_str()))),
            ("tenant", scope.tenant.clone()),
            ("collection", Value::Str(Str::from(collection))),
        ]));
        format!("{}:qcache:{collection}:{hash}", scope.name)
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
        if !self.cache.supports_versioning() || !self.region(collection).enabled {
            return Ok(None);
        }
        let key = self.collection_key(scope, collection);
        let Some(epoch) = Mutation::new(self.cache.clone()).read(&key, scope.writer_timeout).await?.value else {
            return Ok(None);
        };
        let field =
            md5_serialized(&assoc(vec![("queries", queries.clone()), ("context", Value::Str(Str::from(context)))]));
        // hexdec() of 8 hex digits always fits an integer.
        let slot = i64::from_str_radix(&field[..8], 16).unwrap_or(0) % self.slots;
        // Cache transports alias field '0' to the default field; conditional
        // fills must use the same field.
        let slot = if slot == 0 { String::new() } else { slot.to_string() };
        Ok(Some(Entry { key, collection: collection.to_owned(), field, epoch, slot }))
    }

    /// The results cached for `entry` (`get()`).
    ///
    /// Fails ([`crate::Kind::Runtime`]) when an entry that is not one of
    /// this version cannot be purged.
    pub async fn get(&self, entry: &Entry) -> Result<Option<Vec<Document>>> {
        let data = self.cache.load(&entry.key, self.region(&entry.collection).ttl, &entry.slot).await?;
        if missing(&data) {
            return Ok(None);
        }
        let data = match data {
            Some(php_std::Value::Array(a))
                if matches!(a.get_str("version"), Some(php_std::Value::Int(VERSION)))
                    && matches!(a.get_str("documents"), Some(php_std::Value::Array(_))) =>
            {
                a
            }
            _ => {
                self.purge_loaded_entry(entry).await?;
                return Ok(None);
            }
        };
        if !is_text(data.get_str("epoch"), &entry.epoch) || !is_text(data.get_str("field"), &entry.field) {
            return Ok(None);
        }
        let Some(php_std::Value::Array(items)) = data.get_str("documents") else {
            return Ok(None);
        };
        let mut documents = Vec::with_capacity(items.len());
        for (_, item) in items.iter() {
            let php_std::Value::Array(item) = item else {
                self.purge_loaded_entry(entry).await?;
                return Ok(None);
            };
            let typed: Array = item
                .iter()
                .filter(|(k, _)| matches!(k, php_std::types::KeyRef::Str(_)))
                .map(|(k, v)| (k.to_owned(), crate::value::from_plain(v.clone())))
                .collect();
            documents.push(Document::from_storage(typed)?);
        }
        Ok(Some(documents))
    }

    /// The version a fill of `entry` is presented with (`getLease()`).
    pub async fn lease(&self, entry: &Entry) -> Result<String> {
        Ok(self.cache.lease(&entry.key).await?)
    }

    /// Caches `results` for `entry` while its version is still `version`
    /// (`set()`).
    pub async fn set(&self, entry: &Entry, results: &[Document], version: &str) -> Result<bool> {
        let documents: php_std::Array = results
            .iter()
            .enumerate()
            .map(|(i, d)| (ArrayKey::Int(i as i64), to_plain(&Value::Array(d.array_copy()))))
            .collect();
        let data = php_std::Value::Array(
            [
                ("version", php_std::Value::Int(VERSION)),
                ("epoch", text(&entry.epoch)),
                ("field", text(&entry.field)),
                ("documents", php_std::Value::Array(documents)),
            ]
            .into_iter()
            .map(|(k, v)| (ArrayKey::Str(Str::from(k)), v))
            .collect(),
        );
        Ok(self.cache.save_with_lease(&entry.key, &data, &entry.slot, version).await?)
    }

    /// `invalidateCollection()`: block and activate at once.
    pub async fn invalidate_collection(&self, scope: &Scope, collection: &str) -> Result<()> {
        let key = self.collection_key(scope, collection);
        let token = self.create_token();
        self.block_collection(&key, &token).await?;
        self.activate_collection(&key, &token, scope.writer_timeout).await
    }

    /// A write's token (`createToken()`): `<time>.<32 hex>`, which records
    /// when it was created so a later activation can tell an abandoned write.
    pub fn create_token(&self) -> String {
        format!("{}.{}", php_std::datetime::time(), random_hex())
    }

    /// Registers the mutation's token before it starts, then retires the
    /// block field (`blockCollection()`). A cache without versions caches
    /// nothing, so there is nothing to block.
    pub async fn block_collection(&self, key: &str, token: &str) -> Result<()> {
        if self.cache.supports_versioning() {
            Mutation::new(self.cache.clone()).begin(key, token, Scope::WRITER_TIMEOUT).await?;
            self.cache.purge(key, BLOCK_FIELD).await?;
        }
        Ok(())
    }

    /// Releases the mutation's token, which rotates the collection's epoch
    /// (`activateCollection()`); `writer_timeout` is the seconds after which
    /// another writer's registration is treated as abandoned.
    pub async fn activate_collection(&self, key: &str, token: &str, writer_timeout: i64) -> Result<()> {
        if self.cache.supports_versioning() {
            Mutation::new(self.cache.clone()).finish(key, token, writer_timeout).await?;
        }
        Ok(())
    }

    /// `flush()`.
    ///
    /// Fails ([`crate::Kind::CacheUnavailable`]) when the cache is not
    /// flushed (`Failed to flush query cache`).
    pub async fn flush(&self) -> Result<()> {
        if !self.cache.flush().await? {
            return Err(Error::new(Kind::CacheUnavailable, "Failed to flush query cache"));
        }
        Ok(())
    }

    /// `purgeLoadedEntry()`.
    async fn purge_loaded_entry(&self, entry: &Entry) -> Result<()> {
        if !self.cache.purge(&entry.key, &entry.slot).await? {
            return Err(runtime(format!("Failed to purge invalid query cache entry '{}'", entry.key)));
        }
        Ok(())
    }
}
