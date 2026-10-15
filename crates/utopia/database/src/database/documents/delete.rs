//! `deleteDocument()`, `dispatchDeleted()` and `deleteDocuments()`.

use std::sync::Arc;

use super::super::{BoxFuture, Callback, Database, METADATA};
use super::create::php_empty;
use super::numeric::{batch_target, document_security, metadata_target};
use super::update::reject_joins;
use crate::document::Document;
use crate::error::{Error, Result};
use crate::event::{Domain, Event};
use crate::hook::Lifecycle;
use crate::permission::PermissionType;
use crate::query::Query;
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
        if let Some(parts) = self.mirror_parts() {
            return Box::pin(async move { parts.delete_document(self, collection, id).await });
        }
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
    /// Fails ([`crate::Kind::Query`]) for a join or invalid queries,
    /// ([`crate::Kind::Database`]) for a missing tenant,
    /// ([`crate::Kind::Authorization`]) without delete permission,
    /// ([`crate::Kind::Conflict`]) for a document changed after the request
    /// timestamp, ([`crate::Kind::Restricted`]) when a restricting
    /// relationship holds a document, ([`crate::Kind::Limit`]) for a batch
    /// above [`super::super::BATCH_SIZE`].
    pub async fn delete_documents(
        &self,
        collection: &str,
        queries: Vec<Query>,
        batch_size: i64,
        on_next: Option<&mut dyn Callback<Document>>,
    ) -> Result<i64> {
        if let Some(parts) = self.mirror_parts() {
            return Box::pin(parts.delete_documents(self, collection, queries, batch_size, on_next)).await;
        }
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

        if self.is_validating() {
            let validator = self.queries_validator(&collection, &queries, false)?;
            if !validator.check(&queries) {
                return Err(Error::query(validator.description()));
            }
        }

        let parsed = Query::group_by_type(&queries)?;
        let mut limit = parsed.limit.unwrap_or(0);
        let cursor = parsed.cursor.filter(|c| !c.is_empty());

        if let Some(cursor) = &cursor
            && cursor.collection() != collection.id()
        {
            return Err(Error::database("Cursor document must be from the same Collection."));
        }

        let original_limit = limit;
        let mut last = cursor;
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
            self.with_mutation(Event::DocumentsDelete, &target, || {
                let (collection, batch) = (&collection, &batch);
                async move {
                    let mut sequences = Vec::new();
                    let mut permission_ids = Vec::new();
                    for document in batch {
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

                    self.inner.adapter.delete_documents(collection, sequences, permission_ids).await?;

                    for document in batch {
                        self.with_document_tenant(
                            document,
                            self.advance_collection_cache_epoch(collection.id(), document.id()),
                        )
                        .await?;
                    }

                    self.queue_document_purges(collection.id(), batch).await?;
                    Ok(())
                }
            })
            .await?;

            let count = batch.len() as i64;
            last = batch.last().cloned();
            for document in batch {
                if let Some(callback) = on_next.as_deref_mut() {
                    callback.call(document).await?;
                }
                modified += 1;
            }

            if count < batch_size || (original_limit != 0 && modified >= original_limit) {
                break;
            }
        }

        let listeners = self.listens(Event::DocumentsDelete);
        if !listeners.is_empty() {
            let event = Domain::DocumentBatchDeleted { collection: collection.id().to_owned(), count: modified };
            self.dispatch(&event, &listeners).await?;
        }

        Ok(modified)
    }
}
