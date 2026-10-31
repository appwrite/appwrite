//! The document cache's writes: per-collection epochs that writes block and
//! activate, purges, and `withCache()`.
//!
//! PHP: the cache section of `Trait\Documents` (`purgeCachedCollection()`
//! through `withCache()`). Keys, fields and stored values are byte for byte
//! PHP's: the Appwrite PHP API shares this cache. What cached reads use is
//! in `cached.rs`.

use std::future::Future;

use futures_util::StreamExt;

use super::super::transactions::{ContextState, PurgeEvent};
use super::super::{Database, METADATA, TTL, warning};
use super::cached::{
    ACTIVE_PREFIX, BLOCKED_PREFIX, PERMANENT, SEPARATOR, TOKEN_SEPARATOR, create_document_cache_token, random_hex, text,
};
use crate::cache::{Owners, Target, Tokens};
use crate::document::Document;
use crate::error::{Error, Kind, Result};
use crate::event::{Domain, Event};
use crate::permission::PermissionType;
use crate::validator::authorization::Input;
use crate::value::{Array, ArrayKey, Ext, Str, Value, from_plain, to_plain};

/// How many times a write tries to register as an owner of a collection's
/// document cache (`DOCUMENT_CACHE_REGISTRATION_ATTEMPTS`).
const DOCUMENT_CACHE_REGISTRATION_ATTEMPTS: usize = 2;

/// A `RuntimeException`.
fn runtime(message: String) -> Error {
    Error::new(Kind::Runtime, message)
}

/// Whether a loaded cache value is a miss (`false` or `null`).
fn missing(value: &Option<php_std::Value>) -> bool {
    matches!(value, None | Some(php_std::Value::Null) | Some(php_std::Value::Bool(false)))
}

/// A cache value as a string, when it is one.
fn string(value: &Option<php_std::Value>) -> Option<String> {
    match value {
        Some(php_std::Value::Str(s)) => Some(String::from_utf8_lossy(s.as_bytes()).into_owned()),
        _ => None,
    }
}

impl Database {
    /// Cleans all of a collection's documents from the cache
    /// (`purgeCachedCollection()`): its epoch retired, its query cache
    /// invalidated; for the metadata collection every cached definition.
    pub async fn purge_cached_collection(&self, collection: &str) -> Result<()> {
        if collection == METADATA {
            self.purge_cached_definitions().await?;
            if let Some(query_cache) = self.query_cache() {
                query_cache.invalidate_collection(&self.query_cache_scope(None).await, collection).await?;
            }
            return Ok(());
        }
        let (collection_key, _) = self.cache_base_keys(collection, None).await?;
        let definition_key = self.definition_cache_key(collection).await?;
        self.advance_document_cache_epoch(&collection_key, &definition_key).await?;
        if let Some(query_cache) = self.query_cache() {
            query_cache.invalidate_collection(&self.query_cache_scope(None).await, collection).await?;
        }
        Ok(())
    }

    /// Invalidates a collection's cached documents after a schema change
    /// committed (`purgeCommittedCollection()`). The write to its definition
    /// already retired them before the commit, so a failure here leaves
    /// nothing stale and is not the caller's: it is logged.
    pub(crate) async fn purge_committed_collection(&self, collection: &str) -> Result<()> {
        if collection == METADATA {
            return self.with_retries(|| self.purge_cached_collection(collection), 3, 100).await;
        }
        let (collection_key, _) = self.cache_base_keys(collection, None).await?;
        let definition_key = self.definition_cache_key(collection).await?;
        let advanced = self
            .with_retries(
                || async { self.advance_document_cache_epoch(&collection_key, &definition_key).await.map(|_| ()) },
                3,
                100,
            )
            .await;
        if let Err(error) = advanced {
            warning(format!(
                "Warning: Failed to invalidate the cached documents of '{collection}' after its schema change: {}",
                error.message()
            ));
        }
        self.with_retries(
            || async {
                if let Some(query_cache) = self.query_cache() {
                    query_cache.invalidate_collection(&self.query_cache_scope(None).await, collection).await?;
                }
                Ok(())
            },
            3,
            100,
        )
        .await
    }

    /// Purges a document's cache slot, and once more after the open
    /// invalidation scope ends (`purgeCachedDocumentInternal()`).
    pub(crate) async fn purge_cached_document_internal(&self, collection: &str, id: Option<&str>) -> Result<bool> {
        let Some(id) = id else {
            return Ok(true);
        };
        let (collection_key, document_key) = self.cache_base_keys(collection, Some(id)).await?;
        if let Some(context) = self.context() {
            let definition_key =
                if collection != METADATA { Some(self.definition_cache_key(collection).await?) } else { None };
            {
                let mut state = context.state();
                ContextState::put(&mut state.document_cache_purges, document_key.clone(), collection_key.clone());
                if let Some(definition_key) = definition_key {
                    ContextState::put(&mut state.document_cache_definitions, collection_key.clone(), definition_key);
                }
                if let Some(writes) = state.transaction_writes.as_mut() {
                    let lower = document_key.to_lowercase();
                    writes.insert(lower.clone());
                    state.transaction_definitions.remove(&lower);
                    state.definition_refills.retain(|(k, _, _)| *k != lower);
                }
            }
            // A definition written inside an invalidation scope retires the
            // collection's cached documents with it, so a reader cannot fill
            // them under the old definition before the scope ends.
            if collection == METADATA && id != METADATA {
                let (described_key, _) = self.cache_base_keys(id, None).await?;
                self.advance_document_cache_epoch(&described_key, &document_key).await?;
            }
        }
        // A write whose purge cannot be confirmed blocks its collection's
        // epoch instead, before it commits, so the document cached before it
        // is never served again; only when that fails too is the write
        // refused (8.3.14).
        let context = self.context();
        let scoped = collection != METADATA && context.is_some();
        let blocked = scoped
            && context
                .is_some_and(|c| ContextState::get(&c.state().document_cache_mutations, &collection_key).is_some());
        let purged = if blocked {
            self.cache().purge(&document_key, "").await.map(|_| ()).map_err(Error::from)
        } else {
            self.with_retries(
                || async {
                    self.cache().purge(&document_key, "").await?;
                    Ok(())
                },
                3,
                100,
            )
            .await
        };
        if let Err(error) = purged {
            if !scoped {
                return Err(error);
            }
            let definition_key = self.definition_cache_key(collection).await?;
            self.advance_document_cache_epoch(&collection_key, &definition_key).await?;
        }
        Ok(true)
    }

    /// A batch write retires the documents of their collection at once
    /// (`advanceCollectionCacheEpoch()`); for the metadata collection it
    /// purges the definition written, as a cached definition is checked
    /// against no epoch.
    pub(crate) async fn advance_collection_cache_epoch(&self, collection: &str, document: &str) -> Result<bool> {
        if collection == METADATA {
            return self.purge_cached_document_internal(METADATA, Some(document)).await;
        }
        let (collection_key, _) = self.cache_base_keys(collection, None).await?;
        let definition_key = self.definition_cache_key(collection).await?;
        self.advance_document_cache_epoch(&collection_key, &definition_key).await
    }

    /// The cache key of a collection's definition (`getDefinitionCacheKey()`).
    pub(crate) async fn definition_cache_key(&self, collection: &str) -> Result<String> {
        Ok(self.cache_base_keys(super::super::METADATA, Some(collection)).await?.1)
    }

    /// Cached definitions are checked against no epoch, so each one the
    /// database lists is purged (`purgeCachedDefinitions()`).
    async fn purge_cached_definitions(&self) -> Result<()> {
        let authorization = self.authorization();
        self.silent(authorization.skip(async {
            let mut definitions = self.cursor(METADATA, Vec::new(), 25, PermissionType::Read).await?;
            while let Some(definition) = definitions.next().await {
                let key = self.definition_cache_key(definition?.id()).await?;
                self.cache().purge(&key, "").await?;
            }
            Ok(())
        }))
        .await
    }

    /// Purges the documents a transaction wrote once it committed or rolled
    /// back; a failed purge retires the collection's epoch instead
    /// (`purgeWrittenDocuments()`). `documents` are collection keys by
    /// document key; `definitions` definition keys by collection key.
    ///
    /// A reader outside the transaction may have cached the pre-commit row
    /// after the purge inside it, so when this purge fails the collection's
    /// epoch is retired, which no such fill survives. A collection
    /// definition has no epoch of its own, so its failed purge is the one
    /// returned. Purges are retried until one fails; after that the cache is
    /// taken as unreachable and each is tried once.
    pub(crate) async fn purge_written_documents(
        &self,
        documents: &[(String, String)],
        definitions: &[(String, String)],
    ) -> Result<()> {
        if documents.is_empty() {
            return Ok(());
        }
        let mut failure: Option<Error> = None;
        let mut retired: Vec<&str> = Vec::new();
        let mut reachable = true;
        for (document_key, collection_key) in documents {
            if retired.contains(&collection_key.as_str()) {
                continue;
            }
            let purged = if reachable {
                self.with_retries(
                    || async {
                        self.cache().purge(document_key, "").await?;
                        Ok(())
                    },
                    3,
                    100,
                )
                .await
            } else {
                self.cache().purge(document_key, "").await.map(|_| ()).map_err(Error::from)
            };
            let Err(error) = purged else { continue };
            reachable = false;
            let Some(definition_key) = ContextState::get(definitions, collection_key) else {
                failure.get_or_insert(error);
                continue;
            };
            match self.advance_document_cache_epoch(collection_key, definition_key).await {
                Ok(_) => retired.push(collection_key),
                Err(_) => {
                    failure.get_or_insert(error);
                }
            }
        }
        failure.map_or(Ok(()), Err)
    }

    /// Blocks a collection's document cache for a write, activating at once
    /// outside an invalidation scope (`advanceDocumentCacheEpoch()`).
    pub(crate) async fn advance_document_cache_epoch(
        &self,
        collection_key: &str,
        definition_key: &str,
    ) -> Result<bool> {
        let context = self.context();
        if let Some(c) = &context
            && ContextState::get(&c.state().document_cache_mutations, collection_key).is_some()
        {
            return Ok(true);
        }
        let token = create_document_cache_token();
        if !self.block_document_cache_epoch(collection_key, &token, definition_key).await? {
            return Ok(true);
        }
        if let Some(c) = &context {
            let mut state = c.state();
            ContextState::put(&mut state.document_cache_mutations, collection_key.to_owned(), token);
            ContextState::put(
                &mut state.document_cache_definitions,
                collection_key.to_owned(),
                definition_key.to_owned(),
            );
            return Ok(true);
        }
        self.activate_document_cache_epoch(collection_key, &token, definition_key).await?;
        Ok(true)
    }

    /// Publishes a tombstone before the write, then drops the definition
    /// that carried the previous epoch, so readers refill it with the
    /// tombstone (`blockDocumentCacheEpoch()`). A write whose registration
    /// keeps failing still blocks with its tombstone: its activation
    /// finishes it while the tombstone is its own, and otherwise retires
    /// whatever readers filled while it ran. An epoch that cannot be read is
    /// purged all the same, so a cache that cannot confirm the purge fails
    /// the write; `false` when the cache holds no epoch Each step is retried
    /// first, so a blip shorter than the retries does not cost the write.
    async fn block_document_cache_epoch(
        &self,
        collection_key: &str,
        token: &str,
        definition_key: &str,
    ) -> Result<bool> {
        let cache = self.cache();
        let epoch_key = format!("{collection_key}#epoch");
        if !self.register_document_cache_owner(collection_key, token).await?
            && missing(&cache.load(&epoch_key, PERMANENT, "").await?)
        {
            self.with_retries(
                || async {
                    cache.purge(&epoch_key, "").await?;
                    Ok(())
                },
                3,
                100,
            )
            .await?;
            return Ok(false);
        }
        self.with_retries(
            || async {
                let tombstone = format!("{BLOCKED_PREFIX}{token}{SEPARATOR}{}", php_std::datetime::time());
                if !cache.save(&epoch_key, &text(&tombstone), "", 0).await? {
                    return Err(runtime(format!("Failed to block document cache epoch '{epoch_key}'")));
                }
                Ok(())
            },
            3,
            100,
        )
        .await?;
        let started_key = format!("{collection_key}#started");
        self.with_retries(
            || async {
                cache.purge(&started_key, "").await?;
                Ok(())
            },
            3,
            100,
        )
        .await?;
        self.with_retries(
            || async {
                self.purge_cached_definition(definition_key).await?;
                Ok(())
            },
            3,
            100,
        )
        .await?;
        Ok(true)
    }

    /// Registers a write as an owner of a collection's document cache,
    /// trying [`DOCUMENT_CACHE_REGISTRATION_ATTEMPTS`] times
    /// (`registerDocumentCacheOwner()`).
    async fn register_document_cache_owner(&self, collection_key: &str, token: &str) -> Result<bool> {
        let owners = Owners::new(self.cache());
        for _ in 0..DOCUMENT_CACHE_REGISTRATION_ATTEMPTS {
            if owners.register(collection_key, token).await? {
                return Ok(true);
            }
        }
        Ok(false)
    }

    /// `purgeCachedDefinition()`.
    async fn purge_cached_definition(&self, definition_key: &str) -> Result<bool> {
        if !definition_key.is_empty() {
            self.cache().purge(definition_key, "").await?;
        }
        Ok(true)
    }

    /// Activates the document cache tombstones of an ended scope
    /// (`activateDocumentInvalidation()`): every one, then the first failure.
    pub(crate) async fn activate_document_invalidation(
        &self,
        tokens: &Tokens,
        definitions: &[(String, String)],
    ) -> Result<()> {
        if tokens.is_empty() {
            return Ok(());
        }
        let mut failure: Option<Error> = None;
        for (collection_key, token) in tokens {
            let definition_key = ContextState::get(definitions, collection_key).unwrap_or("");
            if let Err(e) = self.activate_document_cache_epoch(collection_key, token, definition_key).await {
                failure.get_or_insert(e);
            }
        }
        failure.map_or(Ok(()), Err)
    }

    /// Replaces this write's tombstone with a fresh epoch once no other
    /// write to the collection is in flight; writes older than the writer
    /// timeout no longer count as in flight (`activateDocumentCacheEpoch()`).
    async fn activate_document_cache_epoch(
        &self,
        collection_key: &str,
        token: &str,
        definition_key: &str,
    ) -> Result<()> {
        let cache = self.cache();
        let registration = Owners::new(cache.clone()).find(collection_key, token).await?;
        let owner = cache.load(&registration.key, TTL, &registration.field).await?;
        let is_token = |owner: &Option<php_std::Value>| string(owner).as_deref() == Some(token);
        if !missing(&owner) && !is_token(&owner) {
            return Err(runtime(format!("Invalid document cache owner '{token}' for '{collection_key}'")));
        }
        let owned = is_token(&owner)
            && self.release_document_cache_owner(&registration.key, &registration.field, collection_key, token).await?;

        let started_key = format!("{collection_key}#started");
        let finished_key = format!("{collection_key}#finished");
        let started = cache.generation(&started_key).await?;
        let finished = cache.generation(&finished_key).await?;
        let epoch_key = format!("{collection_key}#epoch");
        let loaded = cache.load(&epoch_key, PERMANENT, "").await?;
        let epoch = string(&loaded);
        let blocked = epoch.as_deref().is_some_and(|e| e.starts_with(BLOCKED_PREFIX));
        let ours =
            blocked && epoch.as_deref().is_some_and(|e| e.starts_with(&format!("{BLOCKED_PREFIX}{token}{SEPARATOR}")));

        if started == finished {
            if !blocked || ours {
                self.publish_document_cache_epoch(collection_key, &finished, definition_key).await?;
            }
            return Ok(());
        }

        if !owned && !ours {
            // This token was cleared by a cache flush or released as
            // abandoned. Leave another writer's tombstone fail-closed, but
            // retire whatever readers filled while it ran.
            if epoch.is_some() && !blocked {
                self.publish_document_cache_epoch(collection_key, &started, definition_key).await?;
            }
            return Ok(());
        }

        let next_finished = self.finish_document_cache_write(collection_key, &finished, &loaded).await?;

        let next_started = cache.generation(&started_key).await?;
        if next_started == next_finished {
            return self.publish_document_cache_epoch(collection_key, &next_finished, definition_key).await;
        }

        if !registration.field.is_empty() && self.release_abandoned_document_cache_owners(&registration.key).await? {
            return self.publish_document_cache_epoch(collection_key, &next_started, definition_key).await;
        }

        self.purge_cached_definition(definition_key).await?;
        Ok(())
    }

    /// Releases this write's registration, and reports whether it was still
    /// this write's to release; a purge that cannot be confirmed is retried
    /// while the registration is still in place
    /// (`releaseDocumentCacheOwner()`).
    async fn release_document_cache_owner(
        &self,
        key: &str,
        field: &str,
        collection_key: &str,
        token: &str,
    ) -> Result<bool> {
        let cache = self.cache();
        let released = std::sync::atomic::AtomicBool::new(true);
        self.with_retries(
            || async {
                if cache.purge(key, field).await? {
                    return Ok(());
                }
                let owner = cache.load(key, TTL, field).await?;
                if !missing(&owner) {
                    return Err(runtime(format!(
                        "Failed to release document cache owner '{token}' for '{collection_key}'"
                    )));
                }
                released.store(false, std::sync::atomic::Ordering::Relaxed);
                Ok(())
            },
            3,
            100,
        )
        .await?;
        Ok(released.into_inner())
    }

    /// Counts this write as finished and returns the `#finished` generation
    /// after it (`finishDocumentCacheWrite()`). A cache flush restarts
    /// generations, so an unchanged `#finished` only proves the purge was
    /// lost while the epoch read with it is still in place; the purge is
    /// retried only then, so a purge that did land is never counted twice.
    async fn finish_document_cache_write(
        &self,
        collection_key: &str,
        finished: &str,
        loaded: &Option<php_std::Value>,
    ) -> Result<String> {
        let cache = self.cache();
        let finished_key = format!("{collection_key}#finished");
        let epoch_key = format!("{collection_key}#epoch");
        let next = std::sync::Mutex::new(finished.to_owned());
        let attempted = std::sync::atomic::AtomicBool::new(false);
        self.with_retries(
            || async {
                if attempted.load(std::sync::atomic::Ordering::Relaxed) {
                    let generation = cache.generation(&finished_key).await?;
                    *next.lock().unwrap_or_else(std::sync::PoisonError::into_inner) = generation;
                }
                let unchanged = *next.lock().unwrap_or_else(std::sync::PoisonError::into_inner) == finished;
                if unchanged {
                    attempted.store(true, std::sync::atomic::Ordering::Relaxed);
                    cache.purge(&finished_key, "").await?;
                }
                let generation = cache.generation(&finished_key).await?;
                *next.lock().unwrap_or_else(std::sync::PoisonError::into_inner) = generation.clone();
                if generation == finished
                    && string(loaded).is_some()
                    && cache.load(&epoch_key, PERMANENT, "").await? == *loaded
                {
                    return Err(runtime(format!("Failed to finish document cache invalidation '{epoch_key}'")));
                }
                Ok(())
            },
            3,
            100,
        )
        .await?;
        Ok(next.into_inner().unwrap_or_else(std::sync::PoisonError::into_inner))
    }

    /// Publishes a fresh epoch carrying the started generation
    /// (`publishDocumentCacheEpoch()`): until the next write starts, it is
    /// current even while writes judged abandoned still count as unfinished.
    async fn publish_document_cache_epoch(
        &self,
        collection_key: &str,
        started: &str,
        definition_key: &str,
    ) -> Result<()> {
        let epoch_key = format!("{collection_key}#epoch");
        let epoch = format!("{ACTIVE_PREFIX}{}{SEPARATOR}{started}", random_hex());
        self.with_retries(
            || async {
                if !self.cache().save(&epoch_key, &text(&epoch), "", 0).await? {
                    return Err(runtime(format!("Failed to activate document cache epoch '{epoch_key}'")));
                }
                Ok(())
            },
            3,
            100,
        )
        .await?;
        self.with_retries(
            || async {
                self.purge_cached_definition(definition_key).await?;
                Ok(())
            },
            3,
            100,
        )
        .await
    }

    /// Releases every other writer still registered when all of them are
    /// older than the writer timeout; a token without a creation time counts
    /// as live (`releaseAbandonedDocumentCacheOwners()`).
    async fn release_abandoned_document_cache_owners(&self, owners: &str) -> Result<bool> {
        let cache = self.cache();
        let now = php_std::datetime::time();
        let timeout = self.cache_writer_timeout();
        let mut abandoned = Vec::new();
        for token in cache.list(owners).await? {
            let created = token.find(TOKEN_SEPARATOR).map_or("", |i| &token[..i]);
            if created.is_empty()
                || !created.bytes().all(|b| b.is_ascii_digit())
                || php_std::format::str_to_int(created.as_bytes()).wrapping_add(timeout) > now
            {
                return Ok(false);
            }
            abandoned.push(token);
        }
        for token in abandoned {
            cache.purge(owners, &token).await?;
        }
        Ok(true)
    }

    /// Runs a cache operation under the document's tenant when tenant per
    /// document is on and it has one (`withDocumentTenant()`).
    pub(crate) async fn with_document_tenant<F: Future>(&self, document: &Document, future: F) -> F::Output {
        match document.tenant() {
            Some(tenant) if self.shared_tables() && self.tenant_per_document() => {
                self.with_tenant(tenant, future).await
            }
            _ => future.await,
        }
    }

    /// What tells documents apart in a batch (`getDocumentIdentity()`): the
    /// id, with the tenant under tenant per document.
    pub(crate) fn document_identity(&self, document: &Document) -> String {
        if !self.inner.adapter.tenant_per_document() {
            return document.id().to_owned();
        }
        let tenant = document.tenant().map(|t| crate::value::to_text(&t)).unwrap_or_default();
        format!("{tenant}\\0{}", document.id())
    }

    /// Cleans a document from the cache and dispatches
    /// [`crate::Event::DocumentPurge`], whose hooks' failures reach the caller
    /// (`purgeCachedDocument()`). Not retried: it triggers events.
    pub async fn purge_cached_document(&self, collection: &str, id: &str) -> Result<()> {
        self.purge_cached_document_internal(collection, Some(id)).await?;
        let target = Document::from_pairs([
            (crate::document::ID, Value::Str(Str::from(id))),
            (crate::document::COLLECTION, Value::Str(Str::from(collection))),
        ])?;
        self.invalidate(Event::DocumentPurge, &[Target::Document(target)]).await?;
        let listeners = self.listens(Event::DocumentPurge);
        if !listeners.is_empty() {
            let purged = Domain::DocumentPurged { collection: collection.to_owned(), id: id.to_owned() };
            self.dispatch_propagating(&purged, &listeners).await?;
        }
        Ok(())
    }

    /// Announces the purge of a written document once the outermost
    /// transaction commits, or at once outside one (`queueDocumentPurge()`):
    /// under the tenant and the hook silences in force when it was written.
    pub(crate) async fn queue_document_purge(&self, collection: &str, id: &str) -> Result<()> {
        let listeners = self.listens(Event::DocumentPurge);
        if listeners.is_empty() {
            return Ok(());
        }
        if !self.inner.adapter.in_transaction() {
            let purged = Domain::DocumentPurged { collection: collection.to_owned(), id: id.to_owned() };
            return self.dispatch_propagating(&purged, &listeners).await;
        }
        // PHP queues under a context no scope opened as well; no scope ever
        // announces those.
        if let Some(context) = self.context() {
            let event = PurgeEvent {
                tenant: self.tenant(),
                silenced: self.silenced_listeners().as_ref().clone(),
                collection: collection.to_owned(),
                id: id.to_owned(),
            };
            context.state().document_purge_events.push(event);
        }
        Ok(())
    }

    /// [`Database::queue_document_purge`] of each document, under its
    /// tenant (`queueDocumentPurges()`).
    pub(crate) async fn queue_document_purges(&self, collection: &str, documents: &[Document]) -> Result<()> {
        for document in documents {
            self.with_document_tenant(document, self.queue_document_purge(collection, document.id())).await?;
        }
        Ok(())
    }

    /// Purges every cached query result of a collection namespace: the
    /// query cache and the [`Database::with_cache`] region
    /// (`purgeCachedQueries()`). `false` when either could not be purged.
    pub async fn purge_cached_queries(&self, collection: &str, namespace: Option<&str>) -> Result<bool> {
        let collection = match self.silent(self.find_collection(collection)).await? {
            Some(c) => c.id().to_owned(),
            None => collection.to_owned(),
        };
        let epoch_key = format!("{}#epoch", self.query_cache_key(&collection, namespace).await?);
        let cache = self.cache();
        let rotate = async {
            let existing = cache.load(&epoch_key, TTL, "").await?;
            Ok::<bool, Error>(
                (missing(&existing) || cache.purge(&epoch_key, "").await?)
                    && cache.save(&epoch_key, &text(&random_hex()), "", 0).await?,
            )
        };
        let rotated = match rotate.await {
            Ok(r) => r,
            Err(e) if !e.is_engine_error() => {
                warning(format!("Warning: Failed to purge the cached queries: {}", e.message()));
                false
            }
            Err(e) => return Err(e),
        };
        if let Some(query_cache) = self.query_cache() {
            let scope = self.query_cache_scope(namespace).await;
            match query_cache.invalidate_collection(&scope, &collection).await {
                Ok(()) => {}
                Err(e) if !e.is_engine_error() => {
                    warning(format!("Warning: Failed to purge the query cache: {}", e.message()));
                    return Ok(false);
                }
                Err(e) => return Err(e),
            }
        }
        Ok(rotated)
    }

    /// Runs `callback` behind a generation-protected cache-aside lookup
    /// (`withCache()`): the cached value of `key` (and `hash`), else the
    /// callback's, cached. `hash` `None` (and any call inside a
    /// transaction) runs the callback uncached; PHP's default hash is `""`.
    ///
    /// Cached documents are read back as documents of their collection,
    /// cast, checked for TTL expiry and for read permission.
    ///
    /// Fails ([`crate::Kind::Authorization`]) when cached documents belong
    /// to a collection the roles cannot read.
    pub async fn with_cache<F, Fut>(&self, key: &str, callback: F, hash: Option<&str>) -> Result<Value>
    where
        F: FnOnce() -> Fut,
        Fut: Future<Output = Result<Value>>,
    {
        let Some(hash) = hash else {
            return callback().await;
        };
        if self.inner.adapter.in_transaction() {
            return callback().await;
        }
        let cache = self.cache();

        let epoch_key = format!("{key}#epoch");
        let epoch = async {
            let mut epoch = cache.load(&epoch_key, TTL, "").await?;
            if missing(&epoch) {
                let fresh = text(&random_hex());
                if !cache.save(&epoch_key, &fresh, "", 0).await? {
                    return Ok::<_, Error>(None);
                }
                epoch = Some(fresh);
            }
            Ok(string(&epoch).filter(|e| !e.is_empty()))
        };
        let epoch = match epoch.await {
            Ok(Some(epoch)) => epoch,
            Ok(None) => return callback().await,
            Err(e) => {
                warning(format!("Warning: Failed to load cache epoch: {}", e.message()));
                return callback().await;
            }
        };

        let physical_key = format!("{key}#{epoch}:{hash}");
        let cached = match cache.load(&physical_key, TTL, "").await {
            Ok(c) => c,
            Err(e) => {
                warning(format!("Warning: Failed to load cache value: {}", Error::from(e).message()));
                None
            }
        };

        if !missing(&cached) {
            if let Some(decoded) = self.decode_cached(cached.as_ref()).await? {
                return Ok(decoded);
            }
            if let Err(e) = cache.purge(&physical_key, "").await {
                warning(format!("Warning: Failed to purge rejected cache value: {}", Error::from(e).message()));
            }
        }

        let generation = match cache.generation(&physical_key).await {
            Ok(g) => g,
            Err(e) => {
                warning(format!("Warning: Failed to get cache generation: {}", Error::from(e).message()));
                "0".to_owned()
            }
        };

        let value = callback().await?;

        if !matches!(value, Value::Bool(false))
            && let Some(encoded) = encode_cache_value(&value)
            && let Err(e) = cache.save_with_lease(&physical_key, &encoded, "", generation.as_str()).await
        {
            warning(format!("Warning: Failed to save cache value: {}", Error::from(e).message()));
        }
        Ok(value)
    }

    /// What a [`Database::with_cache`] entry holds, as the caller gets it;
    /// `None` (PHP's `false`) to read it again.
    async fn decode_cached(&self, cached: Option<&php_std::Value>) -> Result<Option<Value>> {
        let Some(php_std::Value::Array(cached)) = cached else {
            return Ok(None);
        };
        let Some(value) = cached.get_str("value") else {
            return Ok(None);
        };
        if matches!(value, php_std::Value::Bool(false)) {
            return Ok(None);
        }
        let collection_id = match cached.get_str("collection") {
            Some(php_std::Value::Str(s)) if !s.is_empty() => String::from_utf8_lossy(s.as_bytes()).into_owned(),
            _ => return Ok(Some(from_plain(value.clone()))),
        };
        let Some(collection) = self.silent(self.find_collection(&collection_id)).await? else {
            return Ok(None);
        };
        let authorization = self.authorization();
        let document_security = collection.get(crate::collection::DOCUMENT_SECURITY).is_some_and(Value::truthy);
        let skip_auth = authorization
            .is_valid(&Input::new(PermissionType::Read, collection.permissions_by_type(PermissionType::Read)?));
        if !skip_auth && !document_security && collection.id() != METADATA {
            return Err(Error::authorization(authorization.description()));
        }

        let single = matches!(cached.get_str("type"), Some(php_std::Value::Str(t)) if t.as_bytes() == b"document");
        let payload: Vec<&php_std::Value> = if single {
            vec![value]
        } else {
            match value {
                php_std::Value::Array(items) => items.iter().map(|(_, v)| v).collect(),
                _ => return Ok(None),
            }
        };
        let mut documents = Vec::new();
        for item in payload {
            let php_std::Value::Array(item) = item else {
                return Ok(None);
            };
            let Value::Array(item) = from_plain(php_std::Value::Array(item.clone())) else {
                return Ok(None);
            };
            let document = self.new_document(collection.id(), item)?;
            let document = self.casting(&collection, document)?;
            if self.is_ttl_expired(&collection, &document)? {
                return Ok(None);
            }
            if !skip_auth
                && document_security
                && collection.id() != METADATA
                && !authorization
                    .is_valid(&Input::new(PermissionType::Read, document.permissions_by_type(PermissionType::Read)?))
            {
                if single {
                    return Ok(None);
                }
                continue;
            }
            documents.push(document);
        }
        if single {
            return Ok(documents.into_iter().next().map(|d| Value::Ext(Ext::Document(Box::new(d)))));
        }
        Ok(Some(Value::Array(documents.into_iter().map(|d| Value::Ext(Ext::Document(Box::new(d)))).collect())))
    }
}

/// The entry [`Database::with_cache`] stores for a value
/// (`encodeCacheValue()`): documents of one collection with that collection
/// and their type, anything else as it is; `None` (PHP's `false`) for a
/// value it cannot store, such as documents mixed with other values.
fn encode_cache_value(value: &Value) -> Option<php_std::Value> {
    let entry = |pairs: Vec<(&str, php_std::Value)>| {
        php_std::Value::Array(pairs.into_iter().map(|(k, v)| (ArrayKey::Str(Str::from(k)), v)).collect())
    };
    if let Some(document) = crate::value::as_document(value) {
        let collection = document.collection();
        if collection.is_empty() {
            return None;
        }
        return Some(entry(vec![
            ("collection", text(collection)),
            ("type", text("document")),
            ("value", to_plain(&Value::Array(document.array_copy()))),
        ]));
    }
    let Value::Array(items) = value else {
        return Some(entry(vec![("value", to_plain(value))]));
    };
    let mut collection: Option<&str> = None;
    let mut documents: Array = Array::new();
    let mut has_documents = false;
    let mut has_non_documents = false;
    for (_, item) in items.iter() {
        let Some(document) = crate::value::as_document(item) else {
            if has_documents || contains_document(item) {
                return None;
            }
            has_non_documents = true;
            continue;
        };
        if has_non_documents {
            return None;
        }
        let document_collection = document.collection();
        if document_collection.is_empty() || collection.is_some_and(|c| c != document_collection) {
            return None;
        }
        collection = Some(document_collection);
        has_documents = true;
        let _ = documents.push(Value::Array(document.array_copy()));
    }
    Some(if has_documents {
        entry(vec![
            ("collection", text(collection.unwrap_or_default())),
            ("type", text("documents")),
            ("value", to_plain(&Value::Array(documents))),
        ])
    } else {
        entry(vec![("value", to_plain(value))])
    })
}

/// Whether a value is or holds a document (`containsDocument()`).
fn contains_document(value: &Value) -> bool {
    match value {
        Value::Ext(Ext::Document(_)) => true,
        Value::Array(items) => items.iter().any(|(_, v)| contains_document(v)),
        _ => false,
    }
}

#[cfg(test)]
#[path = "cache_tests.rs"]
mod tests;
