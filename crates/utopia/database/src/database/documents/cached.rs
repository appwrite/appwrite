//! What cached reads use: the cached copies of documents and collection
//! definitions, the epoch a collection's documents are cached under, and
//! the document cache's constants.
//!
//! PHP: `loadCachedDocument()`, `saveCachedDocument()`,
//! `createDefinitionInstance()`, `loadCachedDefinition()`,
//! `saveCachedDefinition()`, `isCachedInTransaction()`, `isTtlExpired()`,
//! `loadDocumentCacheState()`, `restoreDocumentCacheEpoch()` and
//! `createDocumentCacheToken()` of `Trait\Documents`. The entries are PHP's
//! arrays, byte for byte once the cache's codec writes them.

use std::collections::HashMap;
use std::sync::{LazyLock, Mutex};

use super::super::{Database, METADATA, TTL};
use crate::cache::Epoch;
use crate::capability::Capability;
use crate::collection::Collection;
use crate::document::Document;
use crate::error::Result;
use crate::value::{Array, ArrayKey, Str, Value, from_plain, to_plain};

/// The marker a cached miss holds (`CACHE_EMPTY_MARKER`).
pub(crate) const CACHE_EMPTY_MARKER: &str = "$empty";
/// `DOCUMENT_CACHE_ACTIVE_PREFIX`.
pub(crate) const ACTIVE_PREFIX: &str = "active:";
/// `DOCUMENT_CACHE_BLOCKED_PREFIX`.
pub(crate) const BLOCKED_PREFIX: &str = "blocked:";
/// `DOCUMENT_CACHE_LAPSED_PREFIX`.
pub(crate) const LAPSED_PREFIX: &str = "lapsed:";
/// `DOCUMENT_CACHE_SEPARATOR`.
pub(crate) const SEPARATOR: &str = "@";
/// `DOCUMENT_CACHE_TOKEN_SEPARATOR`.
pub(crate) const TOKEN_SEPARATOR: &str = ".";
/// `DOCUMENT_CACHE_PERMANENT` (`PHP_INT_MAX`).
pub(crate) const PERMANENT: i64 = i64::MAX;
/// `DOCUMENT_CACHE_RECHECK`.
pub(crate) const RECHECK: i64 = 60;
/// `DOCUMENT_CACHE_EPOCH`.
pub(crate) const EPOCH: &str = "epoch";
/// `DOCUMENT_CACHE_FIELD`.
pub(crate) const FIELD: &str = "field";
/// `DOCUMENT_CACHE_VALUE`.
pub(crate) const VALUE: &str = "document";
/// `DOCUMENT_CACHE_COLLECTION_EPOCH`.
pub(crate) const COLLECTION_EPOCH: &str = "collectionEpoch";
/// `DOCUMENT_CACHE_BLOCKED_AT`.
pub(crate) const BLOCKED_AT: &str = "blockedAt";
/// `DOCUMENT_CACHE_CHECKED_AT`.
pub(crate) const CHECKED_AT: &str = "checkedAt";
/// `DEFINITION_MODELS_LIMIT`.
const DEFINITION_MODELS_LIMIT: usize = 256;

/// A cached collection definition's entry, as a read may serve it
/// (`loadCachedDefinition()`'s array): the definition, and the epoch its
/// collection's documents are cached under (`None` when blocked).
#[derive(Debug, Clone, PartialEq)]
pub(crate) struct CachedDefinition {
    /// `document`.
    pub(crate) document: Array,
    /// `collectionEpoch`.
    pub(crate) collection_epoch: Option<String>,
}

/// What a cached definition's entry records about the state it was filled
/// under (`saveCachedDefinition()`'s `$validity`, all three keys present).
#[derive(Debug, Clone, PartialEq, Default)]
pub(crate) struct Validity {
    /// `collectionEpoch`.
    pub(crate) collection_epoch: Option<String>,
    /// `blockedAt`.
    pub(crate) blocked_at: Option<i64>,
    /// `checkedAt`.
    pub(crate) checked_at: Option<i64>,
}

/// `bin2hex(random_bytes(16))`.
pub(crate) fn random_hex() -> String {
    let mut bytes = [0u8; 16];
    getrandom::fill(&mut bytes).expect("random bytes");
    hex::encode(bytes)
}

/// A write's token: `<time>.<32 hex>` (`createDocumentCacheToken()`).
pub(crate) fn create_document_cache_token() -> String {
    format!("{}{TOKEN_SEPARATOR}{}", php_std::datetime::time(), random_hex())
}

/// A cache array (string keys, plain values) as a database array.
pub(crate) fn plain_array(array: &php_std::Array) -> Array {
    match from_plain(php_std::Value::Array(array.clone())) {
        Value::Array(a) => a,
        _ => Array::new(),
    }
}

/// An associative array of cache values.
pub(crate) fn assoc(pairs: Vec<(&str, php_std::Value)>) -> php_std::Value {
    php_std::Value::Array(pairs.into_iter().map(|(k, v)| (ArrayKey::Str(Str::from(k)), v)).collect())
}

/// A string of the cache.
pub(crate) fn text(value: &str) -> php_std::Value {
    php_std::Value::Str(Str::from(value))
}

/// Whether a cache value is the string `expected`.
fn is_text(value: Option<&php_std::Value>, expected: &str) -> bool {
    matches!(value, Some(php_std::Value::Str(s)) if s.as_bytes() == expected.as_bytes())
}

impl Database {
    /// Whether a read inside a transaction may serve the document's cached
    /// copy: only when the open scope started the transaction and has not
    /// written the document (`isCachedInTransaction()`).
    pub(crate) fn is_cached_in_transaction(&self, document_key: &str) -> bool {
        self.context().is_some_and(|c| {
            c.state().transaction_writes.as_ref().is_some_and(|w| !w.contains(&document_key.to_lowercase()))
        })
    }

    /// What a read under `epoch` may serve from a document's cache slot
    /// (`loadCachedDocument()`): its copy (or the absence marker).
    pub(crate) async fn load_cached_document(
        &self,
        document_key: &str,
        field: &str,
        epoch: &str,
    ) -> Result<Option<Array>> {
        let Some(php_std::Value::Array(entry)) = self.cache().load(document_key, TTL, field).await? else {
            return Ok(None);
        };
        let Some(php_std::Value::Array(document)) = entry.get_str(VALUE) else {
            return Ok(None);
        };
        if !is_text(entry.get_str(EPOCH), epoch) || !is_text(entry.get_str(FIELD), field) {
            return Ok(None);
        }
        Ok(Some(plain_array(document)))
    }

    /// Caches a document's copy (or the absence marker) under `epoch`
    /// (`saveCachedDocument()`).
    pub(crate) async fn save_cached_document(
        &self,
        document_key: &str,
        field: &str,
        epoch: &str,
        document: Array,
        generation: &str,
    ) -> Result<()> {
        let entry = assoc(vec![(EPOCH, text(epoch)), (FIELD, text(field)), (VALUE, to_plain(&Value::Array(document)))]);
        self.cache().save_with_lease(document_key, &entry, field, generation).await?;
        Ok(())
    }

    /// The collection model of a definition's cached copy, the one built the
    /// last time this copy was read when the copy is the same
    /// (`createDefinitionInstance()`).
    pub(crate) fn create_definition_instance(&self, document_key: &str, cached: Array) -> Result<Document> {
        if self.document_type(METADATA).as_deref() != Some(crate::collection::CLASS) {
            return self.new_document(METADATA, cached);
        }
        static MODELS: LazyLock<Mutex<HashMap<String, (Array, Document)>>> = LazyLock::new(Default::default);
        let mut models = MODELS.lock().unwrap_or_else(|e| e.into_inner());
        if let Some((source, model)) = models.get(document_key)
            && *source == cached
        {
            return Ok(model.clone());
        }
        let model = self.new_document(METADATA, cached.clone())?;
        if models.len() >= DEFINITION_MODELS_LIMIT {
            models.clear();
        }
        models.insert(document_key.to_owned(), (cached, model.clone()));
        Ok(model)
    }

    /// What a read may serve from a definition's cache slot
    /// (`loadCachedDefinition()`): nothing for a blocked collection due for
    /// another look.
    pub(crate) async fn load_cached_definition(
        &self,
        document_key: &str,
        field: &str,
    ) -> Result<Option<CachedDefinition>> {
        let Some(php_std::Value::Array(entry)) = self.cache().load(document_key, TTL, field).await? else {
            return Ok(None);
        };
        let Some(php_std::Value::Array(document)) = entry.get_str(VALUE) else {
            return Ok(None);
        };
        if !is_text(entry.get_str(FIELD), field) {
            return Ok(None);
        }
        let document = plain_array(document);
        if document.get_str(CACHE_EMPTY_MARKER).is_some_and(|v| !matches!(v, Value::Null)) {
            return Ok(Some(CachedDefinition { document, collection_epoch: None }));
        }
        let epoch = entry.get_str(COLLECTION_EPOCH);
        if matches!(epoch, None | Some(php_std::Value::Null)) {
            let now = php_std::datetime::time();
            let int = |key: &str| match entry.get_str(key) {
                Some(php_std::Value::Int(i)) => Some(*i),
                _ => None,
            };
            match (int(BLOCKED_AT), int(CHECKED_AT)) {
                (Some(blocked_at), Some(checked_at))
                    if blocked_at.saturating_add(self.cache_writer_timeout()) > now
                        && checked_at.saturating_add(RECHECK) > now => {}
                _ => return Ok(None),
            }
        }
        // PHP hands any epoch value on; readers take it only as a string.
        let collection_epoch = match epoch {
            Some(php_std::Value::Str(s)) => Some(String::from_utf8_lossy(s.as_bytes()).into_owned()),
            _ => None,
        };
        Ok(Some(CachedDefinition { document, collection_epoch }))
    }

    /// Caches a definition's copy (`saveCachedDefinition()`); `validity`
    /// `None` stores no validity keys (PHP's `[]`). With `is_current` (the
    /// collection key the definition describes, and the epoch it was read
    /// under: PHP's `$isCurrent`), a fill made without generations is
    /// dropped when that epoch moved on.
    pub(crate) async fn save_cached_definition(
        &self,
        document_key: &str,
        field: &str,
        document: Array,
        validity: Option<Validity>,
        generation: &str,
        is_current: Option<(&str, Option<&str>)>,
    ) -> Result<()> {
        let mut pairs: Vec<(&str, php_std::Value)> = Vec::new();
        if let Some(validity) = validity {
            pairs.push((COLLECTION_EPOCH, validity.collection_epoch.as_deref().map_or(php_std::Value::Null, text)));
            pairs.push((BLOCKED_AT, validity.blocked_at.map_or(php_std::Value::Null, php_std::Value::Int)));
            pairs.push((CHECKED_AT, validity.checked_at.map_or(php_std::Value::Null, php_std::Value::Int)));
        }
        pairs.push((FIELD, text(field)));
        pairs.push((VALUE, to_plain(&Value::Array(document))));
        let cache = self.cache();
        let saved = cache.save_with_lease(document_key, &assoc(pairs), field, generation).await?;
        if !saved {
            let fails = generation == "0" && cache.generation(document_key).await? == "0";
            self.settings().definition_fills_fail = fails;
            return Ok(());
        }
        self.settings().definition_fills_fail = false;
        if generation == "0"
            && let Some((described, epoch)) = is_current
            && self.load_document_cache_state(described).await.value.as_deref() != epoch
        {
            cache.purge(document_key, "").await?;
        }
        Ok(())
    }

    /// Whether a TTL index of the collection has expired the document
    /// (`isTtlExpired()`).
    pub(crate) fn is_ttl_expired(&self, collection: &Collection, document: &Document) -> Result<bool> {
        if !self.inner.adapter.supports(Capability::IndexTtl) {
            return Ok(false);
        }
        for index in collection.indexes()? {
            if index.kind != utopia_query::schema::IndexType::Ttl {
                continue;
            }
            let seconds = index.ttl.unwrap_or(0);
            let attribute = index.attributes.first().map(String::as_str).unwrap_or("");
            if seconds <= 0 || attribute.is_empty() {
                return Ok(false);
            }
            if let Some(Value::Str(value)) = document.get(attribute) {
                let utc = php_std::datetime::TzInfo::utc();
                let Ok(start) = php_std::datetime::DateTime::new(value.as_bytes(), None, &utc) else {
                    return Ok(false);
                };
                let start = start.timestamp() as f64 + start.microsecond() as f64 / 1_000_000.0 + seconds as f64;
                return Ok(php_std::datetime::microtime_float() > start);
            }
        }
        Ok(false)
    }

    /// The epoch a collection's documents may be cached under, or none
    /// while a write to it is in flight, with the time that write blocked it
    /// (`loadDocumentCacheState()`). A tombstone older than the writer
    /// timeout lapses into an epoch of its own.
    pub(crate) async fn load_document_cache_state(&self, collection_key: &str) -> Epoch {
        let now = php_std::datetime::time();
        match self.try_load_document_cache_state(collection_key, now).await {
            Ok(epoch) => epoch,
            Err(e) => {
                super::super::warning(format!("Warning: Failed to load document cache epoch: {}", e.message()));
                Epoch { value: None, blocked_at: Some(now) }
            }
        }
    }

    async fn try_load_document_cache_state(&self, collection_key: &str, now: i64) -> Result<Epoch> {
        let cache = self.cache();
        let record = match cache.load(&format!("{collection_key}#epoch"), PERMANENT, "").await? {
            Some(php_std::Value::Str(s)) if !s.is_empty() => String::from_utf8_lossy(s.as_bytes()).into_owned(),
            _ => return self.restore_document_cache_epoch(collection_key, now).await,
        };
        let separator = record.rfind(SEPARATOR);
        let marker = separator.map_or(record.as_str(), |i| &record[..i]);
        let stamp = separator.map_or("", |i| &record[i + 1..]);
        if let Some(tombstone) = record.strip_prefix(BLOCKED_PREFIX) {
            let blocked_at = if !stamp.is_empty() && stamp.bytes().all(|b| b.is_ascii_digit()) {
                php_std::format::str_to_int(stamp.as_bytes())
            } else {
                0
            };
            if blocked_at.saturating_add(self.cache_writer_timeout()) > now {
                return Ok(Epoch { value: None, blocked_at: Some(blocked_at) });
            }
            let finished = cache.generation(&format!("{collection_key}#finished")).await?;
            return Ok(Epoch {
                value: Some(format!("{LAPSED_PREFIX}{tombstone}{SEPARATOR}{finished}")),
                blocked_at: None,
            });
        }
        let started = cache.generation(&format!("{collection_key}#started")).await?;
        if (separator.is_some() && started == stamp)
            || started == cache.generation(&format!("{collection_key}#finished")).await?
        {
            return Ok(Epoch { value: Some(marker.to_owned()), blocked_at: None });
        }
        self.restore_document_cache_epoch(collection_key, now).await
    }

    /// Replaces a missing or unusable epoch: a fresh one when no write is
    /// counted in flight, else a tombstone of its own
    /// (`restoreDocumentCacheEpoch()`).
    async fn restore_document_cache_epoch(&self, collection_key: &str, now: i64) -> Result<Epoch> {
        let cache = self.cache();
        let started = cache.generation(&format!("{collection_key}#started")).await?;
        let epoch_key = format!("{collection_key}#epoch");
        if started != cache.generation(&format!("{collection_key}#finished")).await? {
            let tombstone = format!("{BLOCKED_PREFIX}{}{SEPARATOR}{now}", create_document_cache_token());
            cache.save(&epoch_key, &text(&tombstone), "", 0).await?;
            return Ok(Epoch { value: None, blocked_at: Some(now) });
        }
        let epoch = format!("{ACTIVE_PREFIX}{}", random_hex());
        if !cache.save(&epoch_key, &text(&format!("{epoch}{SEPARATOR}{started}")), "", 0).await? {
            return Ok(Epoch { value: None, blocked_at: Some(now) });
        }
        Ok(Epoch { value: Some(epoch), blocked_at: None })
    }
}
