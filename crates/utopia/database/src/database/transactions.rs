//! Transactions and the invalidation scope around them.
//!
//! PHP: `Trait\Transactions`. PHP keys the state of an open invalidation
//! scope by coroutine id; here it is a [`Context`] the outermost scope opens
//! for the future it runs ([`Inner::context`](super::Inner)), so nested
//! scopes of that future join it and other tasks never see it.

use std::collections::{HashMap, HashSet};
use std::future::Future;
use std::sync::{Arc, Mutex, MutexGuard};

use super::Database;
use crate::cache::Tokens;
use crate::capability::Capability;
use crate::document::Document;
use crate::error::{Error, Kind, Mark, Result};
use crate::event::{Domain, Event};
use crate::value::Value;

/// The state of an open invalidation scope: what its writes blocked and
/// purged, and what to announce and refill once it ends.
///
/// PHP: the `[$context]` entries of `$queryCacheMutations`,
/// `$documentCacheMutations`, `$documentCachePurges`,
/// `$documentCacheDefinitions`, `$documentPurgeEvents`, `$transactionWrites`,
/// `$transactionDefinitions` and `$definitionRefills`.
#[derive(Default)]
pub struct Context {
    state: Mutex<ContextState>,
}

impl std::fmt::Debug for Context {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Context").finish_non_exhaustive()
    }
}

/// The fields of a [`Context`].
#[derive(Default)]
pub(crate) struct ContextState {
    /// Query cache tombstones by collection key (`$queryCacheMutations`).
    pub(crate) query_cache_mutations: Tokens,
    /// Document cache tombstones by collection key (`$documentCacheMutations`).
    pub(crate) document_cache_mutations: Tokens,
    /// The written documents' keys and their collection keys
    /// (`$documentCachePurges`), each document key once.
    pub(crate) document_cache_purges: Vec<(String, String)>,
    /// The definition keys of the collections written, by collection key
    /// (`$documentCacheDefinitions`).
    pub(crate) document_cache_definitions: Vec<(String, String)>,
    /// The document purge events to announce after commit
    /// (`$documentPurgeEvents`).
    pub(crate) document_purge_events: Vec<PurgeEvent>,
    /// The (lower-cased) document keys written in the transaction, when the
    /// scope started it (`$transactionWrites`).
    pub(crate) transaction_writes: Option<HashSet<String>>,
    /// The collection definitions read in the transaction, by lower-cased
    /// document key and field (`$transactionDefinitions`).
    pub(crate) transaction_definitions: HashMap<String, HashMap<String, Document>>,
    /// The definitions to cache after commit, by lower-cased document key
    /// and field, in order (`$definitionRefills`).
    pub(crate) definition_refills: Vec<(String, String, Refill)>,
}

impl ContextState {
    /// Sets `key` to `value` in an ordered map (PHP's `$map[$key] = $value`).
    pub(crate) fn put(map: &mut Vec<(String, String)>, key: String, value: String) {
        match map.iter_mut().find(|(k, _)| *k == key) {
            Some(entry) => entry.1 = value,
            None => map.push((key, value)),
        }
    }

    /// The value of `key` in an ordered map.
    pub(crate) fn get<'a>(map: &'a [(String, String)], key: &str) -> Option<&'a str> {
        map.iter().find(|(k, _)| k == key).map(|(_, v)| v.as_str())
    }
}

/// A document purge to announce once the outermost transaction committed,
/// under the tenant and the hook silences in force when it was written.
#[derive(Debug, Clone)]
pub(crate) struct PurgeEvent {
    /// The tenant then.
    pub(crate) tenant: Value,
    /// The named hooks silenced then.
    pub(crate) silenced: Vec<String>,
    /// The collection.
    pub(crate) collection: String,
    /// The document.
    pub(crate) id: String,
}

/// A collection definition to read (and so cache) again after commit,
/// under the settings in force when it was read in the transaction.
#[derive(Debug, Clone)]
pub(crate) struct Refill {
    /// The tenant.
    pub(crate) tenant: Value,
    /// Whether filtering was on.
    pub(crate) filtering: bool,
    /// The filters excluded.
    pub(crate) exclusions: Option<Arc<Vec<(String, bool)>>>,
    /// The collection id.
    pub(crate) id: String,
}

impl Context {
    /// Locks the state. Never held across an `.await`.
    pub(crate) fn state(&self) -> MutexGuard<'_, ContextState> {
        self.state.lock().unwrap_or_else(|e| e.into_inner())
    }
}

impl Database {
    /// The invalidation scope the current future runs in, if any.
    pub(crate) fn context(&self) -> Option<Arc<Context>> {
        self.inner.context.get()
    }

    /// Runs `callback` inside a transaction (`withTransaction()`): committed
    /// when it succeeds, rolled back when it fails, run again (up to twice)
    /// after a failure another attempt can clear. The document and query
    /// caches of what it wrote are invalidated once it ends.
    pub async fn with_transaction<T, F, Fut>(&self, callback: F) -> Result<T>
    where
        F: FnMut() -> Fut + Send,
        Fut: Future<Output = Result<T>> + Send,
        T: Send,
    {
        if let Some(parts) = self.mirror_parts() {
            return Box::pin(parts.source.with_transaction(callback)).await;
        }
        self.with_invalidation_scope(self.with_adapter_transaction(callback)).await
    }

    /// Runs a mutation with its query cache invalidation ordered around it
    /// (`withMutation()`): the collections it writes are blocked once the
    /// transaction started, and activated once the outer scope ends.
    pub(crate) async fn with_mutation<T, F, Fut>(
        &self,
        event: Event,
        data: &[crate::cache::Target],
        mut callback: F,
    ) -> Result<T>
    where
        F: FnMut() -> Fut + Send,
        Fut: Future<Output = Result<T>> + Send,
        T: Send,
    {
        self.with_invalidation_scope(self.with_adapter_transaction(|| {
            let blocked = self.block_mutation(event, data);
            let run = callback();
            async move {
                blocked.await?;
                run.await
            }
        }))
        .await
    }

    /// Runs `callback` in a savepoint of the open transaction, without
    /// retrying it (`withSavepoint()`, internal). When it fails, the
    /// savepoint is rolled back and `fallback`'s result returned, unless the
    /// failure is one the transaction retries.
    pub async fn with_savepoint<T, F, Fut, G, GFut>(&self, callback: F, fallback: G) -> Result<T>
    where
        F: FnOnce() -> Fut,
        Fut: Future<Output = Result<T>>,
        G: FnOnce(Error) -> GFut,
        GFut: Future<Output = Result<T>>,
    {
        let context = self.context();
        let queued = context.as_ref().map_or(0, |c| c.state().document_purge_events.len());
        let adapter = &self.inner.adapter;
        adapter.start_transaction().await?;
        match callback().await {
            Ok(result) => {
                adapter.commit_transaction().await?;
                Ok(result)
            }
            Err(error) => {
                let rolled_back = match adapter.rollback_transaction().await {
                    Ok(r) => r,
                    Err(_) => return Err(error),
                };
                if !rolled_back || adapter.is_retryable(&error) {
                    return Err(error);
                }
                if let Some(context) = &context {
                    context.state().document_purge_events.truncate(queued);
                }
                fallback(error).await
            }
        }
    }

    /// Blocks the query cache of the collections a mutation writes, once per
    /// invalidation scope (`blockMutation()`).
    pub(crate) async fn block_mutation(&self, event: Event, data: &[crate::cache::Target]) -> Result<()> {
        let tokens = self.invalidation_tokens(event, data).await;
        let context = self.context();
        let pending: Tokens = match &context {
            Some(c) => {
                let state = c.state();
                tokens
                    .into_iter()
                    .filter(|(k, _)| ContextState::get(&state.query_cache_mutations, k).is_none())
                    .collect()
            }
            None => tokens,
        };
        self.block_invalidation(&pending).await?;
        if let Some(c) = &context {
            let mut state = c.state();
            for (key, token) in pending {
                ContextState::put(&mut state.query_cache_mutations, key, token);
            }
        }
        Ok(())
    }

    /// Runs `callback` in an adapter transaction, dropping the document
    /// purge events of every attempt the adapter rolls back
    /// (`withAdapterTransaction()`).
    async fn with_adapter_transaction<T, F, Fut>(&self, mut callback: F) -> Result<T>
    where
        F: FnMut() -> Fut + Send,
        Fut: Future<Output = Result<T>> + Send,
        T: Send,
    {
        let context = self.context();
        let queued = context.as_ref().map_or(0, |c| c.state().document_purge_events.len());
        let discard = || {
            if let Some(c) = &context {
                c.state().document_purge_events.truncate(queued);
            }
        };
        let returned = std::sync::atomic::AtomicBool::new(false);
        let adapter = self.inner.adapter.clone();
        let result = crate::adapter::with_transaction(adapter.as_ref(), || {
            returned.store(false, std::sync::atomic::Ordering::SeqCst);
            discard();
            let run = callback();
            let returned = &returned;
            async move {
                let value = run.await?;
                returned.store(true, std::sync::atomic::Ordering::SeqCst);
                Ok(value)
            }
        })
        .await;
        match result {
            Ok(value) => Ok(value),
            Err(mut error) => {
                if returned.load(std::sync::atomic::Ordering::SeqCst) && error.kind() == Kind::Unconfirmed {
                    error.mark(Mark::UnconfirmedCommit);
                } else if self.inner.adapter.supports(Capability::TransactionNested) {
                    discard();
                }
                error.mark(Mark::Transaction);
                Err(error)
            }
        }
    }

    /// Fires every queued document purge event, even after one fails,
    /// returning the first failure (`announceDocumentPurges()`).
    async fn announce_document_purges(&self, events: Vec<PurgeEvent>) -> Option<Error> {
        let mut failure = None;
        for event in events {
            let announce = async {
                let listeners = self.listens(Event::DocumentPurge);
                let purged = Domain::DocumentPurged { collection: event.collection.clone(), id: event.id.clone() };
                self.dispatch_propagating(&purged, &listeners).await
            };
            let result = if event.silenced.is_empty() {
                self.with_tenant(event.tenant.clone(), announce).await
            } else {
                self.with_tenant(event.tenant.clone(), self.silent_hooks(&event.silenced, announce)).await
            };
            if let Err(e) = result {
                failure.get_or_insert(e);
            }
        }
        failure
    }

    /// Runs `future` in an invalidation scope (`withInvalidationScope()`):
    /// the outermost one, once `future` ends, purges every written document
    /// again, activates the document and query cache tombstones its writes
    /// blocked, and announces the queued document purges (after a commit,
    /// or a commit that could not be confirmed). A failure after a commit is
    /// marked as one ([`Database::failed_after_commit`]).
    pub(crate) async fn with_invalidation_scope<T, F>(&self, future: F) -> Result<T>
    where
        F: Future<Output = Result<T>>,
    {
        if self.context().is_some() {
            return future.await;
        }
        let context = Arc::new(Context::default());
        if !self.inner.adapter.in_transaction() {
            context.state().transaction_writes = Some(HashSet::new());
        }
        let result = self.inner.context.with(Some(context.clone()), future).await;
        let state = std::mem::take(&mut *context.state());
        match result {
            Err(mut error) => {
                let events = if error.take_mark(Mark::UnconfirmedCommit) {
                    // Answers once: a later transaction rethrowing it counts as rolled back.
                    state.document_purge_events
                } else {
                    Vec::new()
                };
                let _ =
                    self.purge_written_documents(&state.document_cache_purges, &state.document_cache_definitions).await;
                let _ = self
                    .activate_document_invalidation(&state.document_cache_mutations, &state.document_cache_definitions)
                    .await;
                let _ = self.activate_invalidation(&state.query_cache_mutations).await;
                let _ = self.announce_document_purges(events).await;
                Err(error)
            }
            Ok(value) => {
                let mut failure = self
                    .purge_written_documents(&state.document_cache_purges, &state.document_cache_definitions)
                    .await
                    .err();
                if let Err(e) = self
                    .activate_document_invalidation(&state.document_cache_mutations, &state.document_cache_definitions)
                    .await
                {
                    failure.get_or_insert(e);
                }
                if let Err(e) = self.activate_invalidation(&state.query_cache_mutations).await {
                    failure.get_or_insert(e);
                }
                if let Some(e) = self.announce_document_purges(state.document_purge_events).await {
                    failure.get_or_insert(e);
                }
                if let Some(mut failure) = failure {
                    failure.mark(Mark::Committed);
                    return Err(failure);
                }
                self.cache_transaction_definitions(state.definition_refills).await;
                Ok(value)
            }
        }
    }

    /// Queues the definition of collection `id`, read in the transaction, to
    /// be read and cached again after commit (`queueDefinitionRefill()`).
    pub(crate) fn queue_definition_refill(&self, definition_key: &str, field: &str, id: &str) {
        let Some(context) = self.context() else { return };
        let refill = Refill {
            tenant: self.inner.adapter.tenant(),
            filtering: self.is_filtering(),
            exclusions: self.filter_exclusions(),
            id: id.to_owned(),
        };
        let mut state = context.state();
        match state.definition_refills.iter_mut().find(|(k, f, _)| k == definition_key && f == field) {
            Some(entry) => entry.2 = refill,
            // PHP nests fields under their definition key: a new field of a
            // known key runs after that key's other fields.
            None => {
                let at = state
                    .definition_refills
                    .iter()
                    .rposition(|(k, _, _)| k == definition_key)
                    .map_or(state.definition_refills.len(), |i| i + 1);
                state.definition_refills.insert(at, (definition_key.to_owned(), field.to_owned(), refill));
            }
        }
    }

    /// Reads (and so caches) each queued definition after commit
    /// (`cacheTransactionDefinitions()`); stops once fills fail, or after a
    /// read served by a replica.
    async fn cache_transaction_definitions(&self, refills: Vec<(String, String, Refill)>) {
        for (_, _, refill) in refills {
            if self.settings().definition_fills_fail {
                return;
            }
            let read = self.with_tenant(
                refill.tenant.clone(),
                self.inner.toggles.filtering.with(
                    refill.filtering,
                    self.inner.toggles.filter_exclusions.with(
                        refill.exclusions.clone(),
                        self.silent(self.get_document(super::METADATA, &refill.id, Vec::new(), false)),
                    ),
                ),
            );
            if let Err(e) = read.await {
                super::warning(format!("Warning: Failed to cache collection definition after commit: {}", e.message()));
            }
            if self.is_read_from_replica() {
                self.settings().definition_fills_fail = true;
            }
        }
    }

    /// Whether `error` was raised after its outermost transaction committed
    /// (`failedAfterCommit()`).
    pub(crate) fn failed_after_commit(&self, error: &Error) -> bool {
        error.is_marked(Mark::Committed)
    }

    /// Whether the writes `error` reports on may be stored
    /// (`mayHaveCommitted()`): an unconfirmed commit, or a failure after
    /// commit.
    pub(crate) fn may_have_committed(&self, error: &Error) -> bool {
        error.kind() == Kind::Unconfirmed || self.failed_after_commit(error)
    }

    /// Whether an adapter transaction let `error` through although it
    /// retries such a failure (`retriedByTransaction()`).
    pub(crate) fn retried_by_transaction(&self, error: &Error) -> bool {
        error.is_marked(Mark::Transaction) && self.inner.adapter.is_retryable(error)
    }
}
