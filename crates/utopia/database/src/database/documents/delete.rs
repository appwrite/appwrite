//! `deleteDocument()`, `dispatchDeleted()` and `deleteDocuments()`.

use std::sync::Arc;

use super::super::{BoxFuture, Callback, Database, METADATA};
use super::create::php_empty;
use super::numeric::{batch_target, document_security, metadata_target};
use super::update::reject_joins;
use crate::collection::Collection;
use crate::document::{self, Document};
use crate::error::{Error, Kind, Result};
use crate::event::{Domain, Event};
use crate::hook::Lifecycle;
use crate::permission::PermissionType;
use crate::query::{ParsedQuery, Query};
use crate::value::{Str, Value, to_text};
use crate::validator::authorization::Input;

impl Database {
    /// Deletes the document `id` (`deleteDocument()`), applying the
    /// on-delete rule of each of its relationships first; whether it was
    /// deleted. After [`Event::DocumentDelete`], dispatches
    /// [`Event::DocumentUpdate`] for each document on the other side of a
    /// two-way relationship the delete changed.
    ///
    /// Fails ([`crate::Kind::Authorization`]) without delete permission,
    /// ([`crate::Kind::Conflict`]) for a document changed after the request
    /// timestamp, ([`crate::Kind::Restricted`]) when a restricting
    /// relationship holds it.
    pub fn delete_document<'a>(&'a self, collection: &'a str, id: &'a str) -> BoxFuture<'a, Result<bool>> {
        Box::pin(async move {
            let collection = self.silent(self.get_collection(collection)).await?;

            let target = metadata_target(&collection, id);
            let update_listeners = self.listens(Event::DocumentUpdate);
            let report = !update_listeners.is_empty();
            let (deleted, changed) = self
                .with_mutation(Event::DocumentDelete, &target, || {
                    let collection = &collection;
                    async move {
                        let authorization = self.authorization();
                        let document = authorization
                            .skip(self.silent(self.skip_query_hooks(self.read_document(
                                collection,
                                id,
                                Vec::new(),
                                true,
                            ))))
                            .await?
                            .0;

                        if document.is_empty() {
                            return Ok((None, Vec::new()));
                        }

                        if collection.id() != METADATA {
                            let mut permissions = collection.permissions_by_type(PermissionType::Delete)?;
                            if document_security(collection) {
                                permissions.extend(document.permissions_by_type(PermissionType::Delete)?);
                            }
                            if !authorization.is_valid(&Input::new(PermissionType::Delete, permissions)) {
                                return Err(Error::authorization(authorization.description()));
                            }
                        }

                        self.assert_not_updated_after_request(document.updated_at(), true)?;

                        let mut changed = Vec::new();
                        if let Some(hook) = self.relationship_hook().filter(|h| h.is_enabled()) {
                            changed = self.silent(hook.before_document_delete(collection, &document, report)).await?;
                        }

                        let result = authorization.skip(self.inner.adapter.delete_document(collection, id)).await?;

                        self.purge_cached_document_internal(collection.id(), Some(id)).await?;

                        if result {
                            self.queue_document_purge(collection.id(), id).await?;
                        }

                        Ok((result.then_some(document), changed))
                    }
                })
                .await?;

            let Some(deleted) = deleted else {
                return Ok(false);
            };

            self.dispatch_deleted(collection.id(), deleted, changed, &update_listeners).await?;

            Ok(true)
        })
    }

    /// The events of a delete whose transaction has returned
    /// (`dispatchDeleted()`): a failing hook cannot undo it, so every event
    /// still fires, and the first failure reaches the caller once they have.
    async fn dispatch_deleted(
        &self,
        collection: &str,
        document: Document,
        changed: Vec<Document>,
        update_listeners: &[Arc<dyn Lifecycle>],
    ) -> Result<()> {
        let mut failure = None;

        let listeners = self.listens(Event::DocumentDelete);
        if !listeners.is_empty() {
            let event = Domain::DocumentDeleted { collection: collection.to_owned(), document };
            if let Err(e) = self.dispatch(&event, &listeners).await {
                failure = Some(e);
            }
        }

        if !update_listeners.is_empty() {
            for related in changed {
                let event = Domain::DocumentUpdated { collection: related.collection().to_owned(), document: related };
                if let Err(e) = self.dispatch(&event, update_listeners).await {
                    failure.get_or_insert(e);
                }
            }
        }

        match failure {
            Some(e) => Err(e),
            None => Ok(()),
        }
    }

    /// Deletes every document matching `queries`, in batches of at most
    /// `batch_size` (`deleteDocuments()`), applying each one's on-delete
    /// rules and handing `on_next` each deleted document once its batch is
    /// deleted; an error it returns aborts the call. Returns how many were
    /// deleted. (PHP also hands `$onNext` a copy of the document as
    /// `$previous`.)
    ///
    /// Each batch deletes only the documents that still match the filters
    /// when it is deleted: a document another write changed after it was
    /// found, so that it no longer matches, is kept, and is neither counted,
    /// handed to `on_next` nor cascaded. A limit counts the documents found,
    /// kept ones included. Without a transaction, as on a standalone MongoDB
    /// server, a document changed between the check and the delete is kept
    /// the same way, but its cascade may already have run, and a document
    /// another deleter removed meanwhile is counted as deleted by this call.
    ///
    /// Each batch commits on its own, so a batch that fails leaves the
    /// batches before it deleted; they were handed to `on_next` and
    /// [`Event::DocumentsDelete`] reports them when the failure is a
    /// contention. In a transaction, a batch that fails with a contention is
    /// retried in its savepoint; roll the whole transaction back and run it
    /// again when it still fails.
    ///
    /// Fails ([`crate::Kind::Query`]) for a join or invalid queries,
    /// ([`crate::Kind::Database`]) for a missing tenant,
    /// ([`crate::Kind::Authorization`]) without delete permission,
    /// ([`crate::Kind::Conflict`]) for a document changed after the request
    /// timestamp, ([`crate::Kind::Restricted`]) when a restricting
    /// relationship holds a document, ([`crate::Kind::Limit`]) for a batch
    /// above [`super::super::BATCH_SIZE`], ([`crate::Kind::Contention`]) when
    /// documents keep changing between the check and the delete of a batch,
    /// in a transaction.
    pub async fn delete_documents(
        &self,
        collection: &str,
        queries: Vec<Query>,
        batch_size: i64,
        on_next: Option<&mut dyn Callback<Document>>,
    ) -> Result<i64> {
        let mut on_next = on_next;
        let mut batch_size = super::joins::batch_size(batch_size)?;

        reject_joins(&queries, "Join queries are not supported for bulk deletes")?;

        let adapter = self.inner.adapter.clone();
        if adapter.shared_tables() && php_empty(&adapter.tenant()) {
            return Err(Error::database("Missing tenant. Tenant must be set when table sharing is enabled."));
        }

        let collection = self.silent(self.get_collection(collection)).await?;

        let security = document_security(&collection);
        let authorization = self.authorization();
        let skip_auth = authorization
            .is_valid(&Input::new(PermissionType::Delete, collection.permissions_by_type(PermissionType::Delete)?));

        if !skip_auth && !security && collection.id() != METADATA {
            return Err(Error::authorization(authorization.description()));
        }

        let queries = self.without_relationship_queries(queries)?;

        if self.is_validating() {
            let validator = self.queries_validator(&collection, &queries, false)?;
            if !validator.check(&queries) {
                return Err(Error::query(validator.description()));
            }
        }

        let parsed = Query::group_by_type(&queries)?;
        let mut limit = parsed.limit.unwrap_or(0);
        let cursor = parsed.cursor.clone().filter(|c| !c.is_empty());

        if let Some(cursor) = &cursor
            && cursor.collection() != collection.id()
        {
            return Err(Error::database("Cursor document must be from the same Collection."));
        }

        let original_limit = limit;
        let mut last = cursor;
        let mut found = 0;
        let mut modified = 0;

        loop {
            if limit != 0 && limit < batch_size && limit > 0 {
                batch_size = limit;
            } else if limit != 0 {
                limit -= batch_size;
            }

            let mut new = vec![Query::limit(batch_size)];
            if let Some(last) = &last {
                new.push(Query::cursor_after(last));
            }
            new.extend(queries.iter().cloned());

            let batch =
                self.silent(self.skip_query_hooks(self.fetch(&collection, new, PermissionType::Delete, false))).await?;

            if batch.is_empty() {
                break;
            }

            let target = batch_target(&collection, &batch);
            let deleted = self
                .with_mutation(Event::DocumentsDelete, &target, || self.delete_batch(&collection, &batch, &parsed))
                .await;
            let deleted = match deleted {
                Ok(deleted) => deleted,
                Err(e) if e.is_a(Kind::Contention) => {
                    if modified > 0 && !adapter.in_transaction() {
                        self.announce_batch_deleted(&collection, modified).await?;
                    }
                    return Err(e);
                }
                Err(e) => return Err(e),
            };

            for document in deleted {
                if let Some(callback) = on_next.as_deref_mut() {
                    callback.call(document).await?;
                }
                modified += 1;
            }

            let count = batch.len() as i64;
            found += count;
            last = batch.last().cloned();

            if count < batch_size || (original_limit != 0 && found >= original_limit) {
                break;
            }
        }

        self.announce_batch_deleted(&collection, modified).await?;

        Ok(modified)
    }

    /// [`Event::DocumentsDelete`] for the documents a bulk delete deleted
    /// (`announceBatchDeleted()`).
    async fn announce_batch_deleted(&self, collection: &Collection, modified: i64) -> Result<()> {
        let listeners = self.listens(Event::DocumentsDelete);
        if !listeners.is_empty() {
            let event = Domain::DocumentBatchDeleted { collection: collection.id().to_owned(), count: modified };
            self.dispatch(&event, &listeners).await?;
        }
        Ok(())
    }

    /// Deletes the documents of a batch [`Database::delete_documents`] found
    /// that still match its filters, in the transaction the caller runs it
    /// in, and returns them (`deleteBatch()`).
    ///
    /// A document whose filters are all on its immutable sequence still
    /// matches, so such a batch is not read again. The adapter's count is
    /// only a hint: when it is short, the batch's documents still stored are
    /// read, and one that is still stored changed between the read and the
    /// delete. In a transaction the batch then fails with a contention the
    /// transaction retries; without one, the documents still stored are left
    /// out, a document another deleter removed meanwhile is reported as
    /// deleted by this call, and a cascade that already ran for a kept
    /// document stays.
    async fn delete_batch(&self, collection: &Collection, batch: &[Document], parsed: &ParsedQuery) -> Result<Vec<Document>> {
        let mut filters = Vec::new();
        let mut documents = if on_sequence_only(&parsed.filters) {
            batch.to_vec()
        } else {
            self.still_matching(collection, batch, parsed, &mut filters).await?
        };

        let mut sequences = Vec::new();
        let mut permission_ids = Vec::new();
        for document in &documents {
            if let Some(sequence) = document.sequence() {
                sequences.push(sequence.clone());
            }
            if !document.permissions()?.is_empty() {
                permission_ids.push(document.id().to_owned());
            }

            if let Some(hook) = self.relationship_hook().filter(|h| h.is_enabled()) {
                self.silent(hook.before_document_delete(collection, document, false)).await?;
            }

            // Check whether the document was updated after the request timestamp.
            self.assert_not_updated_after_request(document.updated_at(), true)?;
        }

        if documents.is_empty() {
            return Ok(Vec::new());
        }

        let adapter = &self.inner.adapter;
        let expected = sequences.len() as i64;
        if filters.is_empty() {
            adapter.delete_documents(collection, sequences, permission_ids).await?;
        } else if adapter.delete_documents_matching(collection, sequences, permission_ids, filters).await? < expected
        {
            let removed = self.without_stored(collection, &documents).await?;
            if removed.len() < documents.len() {
                if adapter.in_transaction() {
                    return Err(Error::contention("Documents were changed while they were being deleted"));
                }
                documents = removed;
            }
        }

        for document in &documents {
            self.with_document_tenant(document, self.advance_collection_cache_epoch(collection.id(), document.id()))
                .await?;
        }

        self.queue_document_purges(collection.id(), &documents).await?;

        Ok(documents)
    }

    /// The documents no longer stored (`withoutStored()`).
    async fn without_stored(&self, collection: &Collection, documents: &[Document]) -> Result<Vec<Document>> {
        let sequences: Vec<Value> = documents
            .iter()
            .map(|d| Value::Str(Str::from(d.sequence().map(to_text).unwrap_or_default().as_str())))
            .collect();
        let queries = vec![Query::equal(document::SEQUENCE, sequences.iter().map(crate::value::to_query).collect::<Vec<_>>()), Query::limit(sequences.len() as i64)];

        let stored = self
            .silent(self.skip_query_hooks(self.skip_validation(self.skip_relationships(
                self.authorization().skip(self.fetch(collection, queries, PermissionType::Delete, false)),
            ))))
            .await?;
        let stored: Vec<String> = stored.iter().map(|d| d.sequence().map(to_text).unwrap_or_default()).collect();

        Ok(documents
            .iter()
            .filter(|d| !stored.contains(&d.sequence().map(to_text).unwrap_or_default()))
            .cloned()
            .collect())
    }

    /// The documents of a batch [`Database::delete_documents`] found that
    /// still match its queries, read again in the transaction that deletes
    /// them, in the order they were found, and into `filters` the filters as
    /// the adapter applies them (`stillMatching()`).
    async fn still_matching(
        &self,
        collection: &Collection,
        batch: &[Document],
        parsed: &ParsedQuery,
        filters: &mut Vec<Query>,
    ) -> Result<Vec<Document>> {
        filters.clear();
        let sequences: Vec<Value> = batch.iter().filter_map(|d| d.sequence().cloned()).collect();
        if sequences.is_empty() {
            return Ok(Vec::new());
        }

        let mut queries = vec![Query::equal(document::SEQUENCE, sequences.iter().map(crate::value::to_query).collect::<Vec<_>>()), Query::limit(sequences.len() as i64)];
        queries.extend(parsed.selections.iter().cloned());
        queries.extend(parsed.filters.iter().cloned());

        let read = self
            .silent(self.skip_query_hooks(self.skip_validation(self.fetch_read(
                collection,
                queries,
                PermissionType::Delete,
                false,
            ))))
            .await?;

        if let Some(adapter_queries) = &read.adapter_queries {
            *filters = Query::group_by_type(adapter_queries)?.filters;
        }

        let mut documents = Vec::with_capacity(read.documents.len());
        for sequence in &sequences {
            let sequence = to_text(sequence);
            if let Some(document) =
                read.documents.iter().rev().find(|d| d.sequence().map(to_text).as_deref() == Some(sequence.as_str()))
            {
                documents.push(document.clone());
            }
        }
        Ok(documents)
    }
}

/// Whether every filter, and every filter nested in one, is on the
/// sequence, which no write changes (`onSequenceOnly()`).
fn on_sequence_only(filters: &[Query]) -> bool {
    filters.iter().all(|filter| {
        if filter.is_nested() {
            let nested: Vec<Query> = filter
                .values()
                .values()
                .filter_map(|v| match v {
                    utopia_query::Value::Ext(utopia_query::Object::Query(q)) => Some(Query::from((**q).clone())),
                    _ => None,
                })
                .collect();
            on_sequence_only(&nested)
        } else {
            filter.attribute() == document::SEQUENCE
        }
    })
}
