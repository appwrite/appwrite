//! [`Query`]: `find()` results cached per collection, under epochs that
//! writes block and activate.

use std::collections::HashMap;
use std::sync::{Arc, RwLock};

use php_std::Str;
use utopia_cache::Cache;

use super::{Entry, Owners, Region, Scope};
use crate::document::Document;
use crate::error::{Error, Kind, Result};
use crate::value::{Array, ArrayKey, Value, to_plain};

/// `ACTIVE_PREFIX`.
const ACTIVE_PREFIX: &str = "active:";
/// `INITIAL_EPOCH`.
const INITIAL_EPOCH: &str = "active:initial";
/// `BLOCKED_PREFIX`.
const BLOCKED_PREFIX: &str = "blocked:";
/// `LAPSED_PREFIX`.
const LAPSED_PREFIX: &str = "lapsed:";
/// `TOKEN_SEPARATOR`.
const TOKEN_SEPARATOR: &str = ".";
/// `SEPARATOR`.
const SEPARATOR: &str = "@";
/// `NEVER_STARTED`.
const NEVER_STARTED: &str = "0";
/// `PERMANENT` (`PHP_INT_MAX`).
const PERMANENT: i64 = i64::MAX;
/// `VERSION`.
const VERSION: i64 = 2;
/// `BLOCK_FIELD`.
const BLOCK_FIELD: &str = "block";

/// A string of the cache.
fn text(value: &str) -> php_std::Value {
    php_std::Value::Str(Str::from(value))
}

/// A cache value as a string, when it is one (PHP's `is_string()`).
fn string(value: &Option<php_std::Value>) -> Option<String> {
    match value {
        Some(php_std::Value::Str(s)) => Some(String::from_utf8_lossy(s.as_bytes()).into_owned()),
        _ => None,
    }
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
        if !self.region(collection).enabled {
            return Ok(None);
        }
        let key = self.collection_key(scope, collection);
        let Some(epoch) = self.epoch(&key, collection, scope.writer_timeout).await? else {
            return Ok(None);
        };
        let field =
            md5_serialized(&assoc(vec![("queries", queries.clone()), ("context", Value::Str(Str::from(context)))]));
        // hexdec() of 8 hex digits always fits an integer.
        let slot = i64::from_str_radix(&field[..8], 16).unwrap_or(0) % self.slots;
        Ok(Some(Entry { key, collection: collection.to_owned(), field, epoch, slot: slot.to_string() }))
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

    /// `getGeneration()`.
    pub async fn generation(&self, entry: &Entry) -> Result<String> {
        Ok(self.cache.generation(&entry.key).await?)
    }

    /// Caches `results` for `entry` under `generation` (`set()`).
    pub async fn set(&self, entry: &Entry, results: &[Document], generation: &str) -> Result<bool> {
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
        Ok(self.cache.save_with_lease(&entry.key, &data, &entry.slot, generation).await?)
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
        format!("{}{TOKEN_SEPARATOR}{}", php_std::datetime::time(), random_hex())
    }

    /// Publishes a shared tombstone before a mutation starts
    /// (`blockCollection()`). The previous epoch's results stay until a fill
    /// of their slot replaces them.
    ///
    /// Fails ([`crate::Kind::Runtime`]) when the owner cannot be registered
    /// or the tombstone saved.
    pub async fn block_collection(&self, key: &str, token: &str) -> Result<()> {
        if !Owners::new(self.cache.clone()).register(key, token).await? {
            return Err(runtime(format!("Failed to register query cache owner for '{key}'")));
        }
        let tombstone = format!("{BLOCKED_PREFIX}{token}{SEPARATOR}{}", php_std::datetime::time());
        if !self.cache.save(&epoch_key(key), &text(&tombstone), "", 0).await? {
            return Err(runtime(format!("Failed to block query cache epoch for '{key}'")));
        }
        self.cache.purge(&started_key(key), "").await?;
        self.cache.purge(key, BLOCK_FIELD).await?;
        Ok(())
    }

    /// Replaces this mutation's tombstone with a fresh epoch once no other
    /// mutation is in progress (`activateCollection()`); `writer_timeout`
    /// is the seconds after which another writer's registration is treated
    /// as abandoned.
    ///
    /// Fails ([`crate::Kind::Runtime`]) for another owner's registration,
    /// an owner that cannot be released, a lost finish, or an epoch that
    /// cannot be published.
    pub async fn activate_collection(&self, key: &str, token: &str, writer_timeout: i64) -> Result<()> {
        let cache = &self.cache;
        let registration = Owners::new(cache.clone()).find(key, token).await?;
        let owner = cache.load(&registration.key, PERMANENT, &registration.field).await?;
        if !missing(&owner) && !is_text(owner.as_ref(), token) {
            return Err(runtime(format!("Invalid query cache owner for '{key}'")));
        }
        let mut owned = is_text(owner.as_ref(), token);
        if owned && !cache.purge(&registration.key, &registration.field).await? {
            let owner = cache.load(&registration.key, PERMANENT, &registration.field).await?;
            if !missing(&owner) {
                return Err(runtime(format!("Failed to release query cache owner for '{key}'")));
            }
            owned = false;
        }

        let epoch_key = epoch_key(key);
        let started_key = started_key(key);
        let finished_key = finished_key(key);
        let started = cache.generation(&started_key).await?;
        let finished = cache.generation(&finished_key).await?;
        let current = string(&cache.load(&epoch_key, PERMANENT, "").await?);
        let ours = is_tombstone_of(current.as_deref(), token);

        if started == finished {
            if !owned && (ours || !is_tombstone(current.as_deref())) {
                self.publish(key, &finished).await?;
            }
            return Ok(());
        }

        if !owned && !ours {
            if current.as_deref().is_some_and(|c| c.starts_with(ACTIVE_PREFIX)) {
                self.publish(key, &started).await?;
            }
            return Ok(());
        }

        cache.purge(&finished_key, "").await?;
        let next_started = cache.generation(&started_key).await?;
        let next_finished = cache.generation(&finished_key).await?;

        if next_started == next_finished {
            return self.publish(key, &next_finished).await;
        }

        if next_finished == finished
            && is_tombstone_of(string(&cache.load(&epoch_key, PERMANENT, "").await?).as_deref(), token)
        {
            return Err(runtime(format!("Failed to finish query cache invalidation for '{key}'")));
        }

        if !registration.field.is_empty() && self.release_abandoned_owners(&registration.key, writer_timeout).await? {
            self.publish(key, &next_started).await?;
        }
        Ok(())
    }

    /// `flush()`.
    ///
    /// Fails ([`crate::Kind::Runtime`]) when the cache is not flushed
    /// (`Failed to flush query cache`).
    pub async fn flush(&self) -> Result<()> {
        if !self.cache.flush().await? {
            return Err(runtime("Failed to flush query cache".to_owned()));
        }
        Ok(())
    }

    /// The epoch results may be read and filled under, or none while a write
    /// is in flight (`getEpoch()`). Epochs never expire; an active epoch
    /// carries the started generation it was published at, and the initial
    /// epoch needs a results hash no write has purged.
    async fn epoch(&self, key: &str, collection: &str, writer_timeout: i64) -> Result<Option<String>> {
        let value = self.cache.load(&epoch_key(key), PERMANENT, "").await?;
        if missing(&value) {
            return Ok((self.cache.generation(key).await? == NEVER_STARTED).then(|| INITIAL_EPOCH.to_owned()));
        }
        let Some(value) = string(&value).filter(|v| !v.is_empty()) else {
            return Ok(None);
        };
        let Some(separator) = value.rfind(SEPARATOR) else {
            return Ok(None);
        };
        let marker = &value[..separator];
        let stamp = &value[separator + 1..];

        if value.starts_with(BLOCKED_PREFIX) {
            let stamp = php_std::format::str_to_int(stamp.as_bytes());
            return self.lapsed_epoch(key, collection, &value, stamp, writer_timeout).await;
        }
        if !value.starts_with(ACTIVE_PREFIX) {
            return Ok(None);
        }
        let started = self.cache.generation(&started_key(key)).await?;
        if started != stamp && started != self.cache.generation(&finished_key(key)).await? {
            return Ok(None);
        }
        Ok(Some(marker.to_owned()))
    }

    /// The epoch a tombstone lapses into: after the region TTL once no
    /// write is counted in flight, after the writer timeout while one is
    /// (`getLapsedEpoch()`).
    async fn lapsed_epoch(
        &self,
        key: &str,
        collection: &str,
        tombstone: &str,
        stamp: i64,
        writer_timeout: i64,
    ) -> Result<Option<String>> {
        let now = php_std::datetime::time();
        let ttl = self.region(collection).ttl;
        if stamp.wrapping_add(ttl.min(writer_timeout)) > now {
            return Ok(None);
        }
        let started = self.cache.generation(&started_key(key)).await?;
        let finished = self.cache.generation(&finished_key(key)).await?;
        if stamp.wrapping_add(if started == finished { ttl } else { writer_timeout }) > now {
            return Ok(None);
        }
        Ok(Some(format!("{LAPSED_PREFIX}{}{SEPARATOR}{finished}", &tombstone[BLOCKED_PREFIX.len()..])))
    }

    /// Releases every other writer still registered when all of them are
    /// older than the writer timeout; a token without a creation time counts
    /// as live (`releaseAbandonedOwners()`).
    async fn release_abandoned_owners(&self, owners: &str, writer_timeout: i64) -> Result<bool> {
        let now = php_std::datetime::time();
        let mut abandoned = Vec::new();
        for token in self.cache.list(owners).await? {
            match token_time(&token) {
                Some(created) if created.wrapping_add(writer_timeout) <= now => abandoned.push(token),
                _ => return Ok(false),
            }
        }
        for token in abandoned {
            self.cache.purge(owners, &token).await?;
        }
        Ok(true)
    }

    /// Publishes a fresh active epoch carrying `finished` (`publish()`).
    async fn publish(&self, key: &str, finished: &str) -> Result<()> {
        let epoch = format!("{ACTIVE_PREFIX}{}{SEPARATOR}{finished}", random_hex());
        if !self.cache.save(&epoch_key(key), &text(&epoch), "", 0).await? {
            return Err(runtime(format!("Failed to activate query cache for '{key}'")));
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

/// The time a token was created at (`getTokenTime()`): its digits before
/// the first `.`, `None` when there are none.
pub(crate) fn token_time(token: &str) -> Option<i64> {
    let time = token.find(TOKEN_SEPARATOR).map_or("", |i| &token[..i]);
    (!time.is_empty() && time.bytes().all(|b| b.is_ascii_digit())).then(|| php_std::format::str_to_int(time.as_bytes()))
}

fn is_tombstone(value: Option<&str>) -> bool {
    value.is_some_and(|v| v.starts_with(BLOCKED_PREFIX))
}

fn is_tombstone_of(value: Option<&str>, token: &str) -> bool {
    value.is_some_and(|v| v.starts_with(&format!("{BLOCKED_PREFIX}{token}{SEPARATOR}")))
}

fn epoch_key(key: &str) -> String {
    format!("{key}#epoch")
}

fn finished_key(key: &str) -> String {
    format!("{key}#finished")
}

fn started_key(key: &str) -> String {
    format!("{key}#started")
}
