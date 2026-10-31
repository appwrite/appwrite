//! `upsertDocument()` and `upsertDocuments()`, with `upsert()`,
//! `findDocumentsToUpsert()` and `upsertKey()`.

use std::collections::HashMap;

use super::super::filters::key_text;
use super::super::{Callback, Database, internal_attributes};
use super::create::php_empty;
use super::joins::batch_size;
use super::numeric::document_security;
use super::read::empty;
use super::update::keep_stored;
use crate::cache::Target;
use crate::change::Change;
use crate::document::{self, Document};
use crate::error::{Error, Result};
use crate::event::{Domain, Event};
use crate::operator::Operator;
use crate::permission::PermissionType;
use crate::query::Query;
use crate::validator::structure::Structure;
use crate::value::{Ext, Str, Value, to_text};

impl Database {
    /// Creates the document, or updates the stored one of its id
    /// (`upsertDocument()`); returns it as written, or the stored one when
    /// nothing changed, which dispatches no event.
    ///
    /// Fails as [`Database::upsert_documents`] does.
    pub async fn upsert_document(&self, collection: &str, document: Document) -> Result<Document> {
        if let Some(parts) = self.mirror_parts() {
            return Box::pin(parts.upsert_document(self, collection, document)).await;
        }
        self.upsert_document_tracked(collection, document, None).await
    }

    /// [`Database::upsert_document`], leaving in `tracked` the document as
    /// PHP leaves the object it was given (see
    /// [`Database::upsert_documents_tracked`]).
    pub(crate) async fn upsert_document_tracked(
        &self,
        collection: &str,
        document: Document,
        tracked: Option<&mut Document>,
    ) -> Result<Document> {
        let id = document.id().to_owned();
        let mut slots = tracked.as_ref().map(|t| vec![(**t).clone()]);
        let mut result: Option<(Document, bool)> = None;
        {
            let mut record = |(upserted, previous): (Document, Option<Document>)| -> Result<()> {
                result = Some((upserted, previous.is_none()));
                Ok(())
            };
            let upserted = self.upsert(collection, vec![document], 1, Some(&mut record), None, slots.as_mut()).await;
            if let (Some(tracked), Some(slot)) = (tracked, slots.and_then(|s| s.into_iter().next())) {
                *tracked = slot;
            }
            upserted?;
        }

        let Some((result, created)) = result else {
            return self.skip_query_hooks(self.get_document(collection, &id, Vec::new(), false)).await;
        };

        let listeners = self.listens(Event::DocumentUpsert);
        if !listeners.is_empty() {
            let event =
                Domain::DocumentUpserted { collection: collection.to_owned(), document: result.clone(), created };
            self.dispatch(&event, &listeners).await?;
        }

        Ok(result)
    }

    /// Upserts documents in batches of at most `batch_size`
    /// (`upsertDocuments()`); with `increase`, an update adds the new
    /// value of that attribute to the stored one. `on_next` is handed each
    /// written document once its batch is written, and the stored one it
    /// replaced (`None` when it was created); an error it returns aborts the
    /// call. Returns how many were written.
    ///
    /// Fails ([`crate::Kind::Database`]) for an adapter without upserts or a
    /// missing tenant, ([`crate::Kind::Limit`]) for a batch above
    /// [`super::super::BATCH_SIZE`], ([`crate::Kind::Authorization`]) without
    /// create (or update) permission, ([`crate::Kind::Structure`]) for an
    /// invalid document, ([`crate::Kind::Duplicate`]) for an id given twice.
    pub async fn upsert_documents(
        &self,
        collection: &str,
        documents: Vec<Document>,
        batch_size: i64,
        on_next: Option<&mut dyn Callback<(Document, Option<Document>)>>,
        increase: Option<&str>,
    ) -> Result<i64> {
        if let Some(parts) = self.mirror_parts() {
            return Box::pin(parts.upsert_documents(self, collection, documents, batch_size, on_next, increase)).await;
        }
        self.upsert_documents_tracked(collection, documents, batch_size, on_next, increase, None).await
    }

    /// [`Database::upsert_documents`], leaving in `tracked` (the documents
    /// given) each document as PHP leaves the object it was given: as
    /// compared when it changes nothing, else as written and, once its batch
    /// is written, as decoded.
    pub(crate) async fn upsert_documents_tracked(
        &self,
        collection: &str,
        documents: Vec<Document>,
        batch_size: i64,
        on_next: Option<&mut dyn Callback<(Document, Option<Document>)>>,
        increase: Option<&str>,
        tracked: Option<&mut Vec<Document>>,
    ) -> Result<i64> {
        let given = !documents.is_empty();
        let (created, updated) =
            self.upsert(collection, documents, self::batch_size(batch_size)?, on_next, increase, tracked).await?;

        let listeners = if given { self.listens(Event::DocumentsUpsert) } else { Vec::new() };
        if !listeners.is_empty() {
            let event = Domain::document_batch_upserted(collection, created, updated);
            self.dispatch(&event, &listeners).await?;
        }

        Ok(created + updated)
    }

    /// The upsert both entry points share (`upsert()`): how many documents
    /// were created and how many updated.
    async fn upsert(
        &self,
        collection: &str,
        documents: Vec<Document>,
        batch_size: i64,
        on_next: Option<&mut dyn Callback<(Document, Option<Document>)>>,
        increase: Option<&str>,
        mut tracked: Option<&mut Vec<Document>>,
    ) -> Result<(i64, i64)> {
        let mut on_next = on_next;
        let adapter = self.inner.adapter.clone();
        if adapter.upserts().is_none() {
            return Err(Error::database("Adapter does not support upserts"));
        }

        if adapter.shared_tables() && !adapter.tenant_per_document() && php_empty(&adapter.tenant()) {
            return Err(Error::database("Missing tenant. Tenant must be set when table sharing is enabled."));
        }
        if !adapter.shared_tables() && adapter.tenant_per_document() {
            return Err(Error::database("Shared tables must be enabled if tenant per document is enabled."));
        }

        if documents.is_empty() {
            return Ok((0, 0));
        }

        let collection = self.silent(self.get_collection(collection)).await?;
        let security = document_security(&collection);
        let attributes = collection.attributes()?.to_vec();
        let time = crate::datetime::now();
        let mut created = 0;
        let mut updated = 0;
        let mut operator_ids: Vec<String> = Vec::new();
        let mut seen_ids: Vec<String> = Vec::new();
        let has_relationships = !Database::relationship_attributes(&collection)?.is_empty();

        let mut documents = documents;
        let preparation = if has_relationships { self.nested_preparation(None, false) } else { None };
        if let Some(preparation) = &preparation {
            let mut prepared = Vec::with_capacity(documents.len());
            for document in documents {
                prepared.push(self.prepare_nested(&collection, document, preparation).await?);
            }
            documents = prepared;
        }
        let internal: Vec<&str> = internal_attributes(true).iter().map(|a| a.key.as_str()).collect();
        let existing = self.find_documents_to_upsert(collection.id(), &documents).await?;
        let preserve_dates = self.is_preserving_dates();
        let authorization = self.authorization();

        let mut changes: Vec<Change> = Vec::with_capacity(documents.len());
        // The index of the document given each change writes.
        let mut given: Vec<usize> = Vec::with_capacity(documents.len());
        for (index, document) in documents.into_iter().enumerate() {
            let old = existing.get(&self.upsert_key(&document)).cloned().unwrap_or_default();

            let mut document = self.remove_unknown_attributes(&collection, document, None);
            let unchanged = self.round_operators(&collection, &mut document)?;
            if !old.is_empty() {
                keep_stored(&mut document, &old, &unchanged);
            }

            // Extract operators early to avoid comparison issues. PHP's
            // extraction names the operators the document holds after their
            // keys too.
            let keys: Vec<crate::value::ArrayKey> = document.keys().map(|k| k.to_owned()).collect();
            for key in keys {
                if let Some(Value::Ext(Ext::Operator(operator))) = document.as_array_mut().get_mut(&key)
                    && (operator.attribute().is_empty() || operator.attribute() == "0")
                {
                    operator.set_attribute(key_text(&key.as_ref()));
                }
            }
            let extracted = Operator::extract_operators(document.as_array().clone());
            let has_operators = !extracted.operators.is_empty();
            let regular: Vec<(String, Value)> = extracted
                .updates
                .iter()
                .map(|(k, v)| (key_text(&k), v.clone()))
                .filter(|(k, _)| !internal.contains(&k.as_str()))
                .collect();

            let mut skip_permissions_update = true;
            if document.has(document::PERMISSIONS) {
                let mut original = old.permissions()?;
                let mut current = document.permissions()?;
                original.sort();
                current.sort();
                skip_permissions_update = original == current;
            }

            // Only skip when there are no operators and no regular attribute changed.
            let has_changes = if has_operators || increase.is_some_and(|i| !i.is_empty()) || !skip_permissions_update {
                true
            } else {
                let old_attributes = old.attributes();
                // Check whether any of the attributes given differ from the stored document.
                let differs = regular.iter().any(|(key, value)| {
                    let previous = old_attributes.get_str(key).cloned().unwrap_or(Value::Null);
                    !previous.loose_eq(value)
                });
                // Also check whether the stored document has attributes the new one does not.
                differs
                    || old_attributes.keys().any(|key| {
                        let key = key_text(&key);
                        !internal.contains(&key.as_str()) && !regular.iter().any(|(k, _)| *k == key)
                    })
            };

            if !has_changes {
                // The document is the stored one: skip it.
                if let Some(slot) = tracked.as_deref_mut().and_then(|t| t.get_mut(index)) {
                    *slot = document;
                }
                continue;
            }

            // A new document needs create permission on the collection; a stored one update
            // permission on the collection, or on the document under document security.
            if old.is_empty() {
                self.authorize_permissions(
                    PermissionType::Create,
                    collection.permissions_by_type(PermissionType::Create)?,
                )?;
            } else {
                let mut permissions = collection.permissions_by_type(PermissionType::Update)?;
                if security {
                    permissions.extend(old.permissions_by_type(PermissionType::Update)?);
                }
                self.authorize_permissions(PermissionType::Update, permissions)?;
            }

            let updated_at = document.updated_at().map(str::to_owned);
            let id = if empty(document.id()) { crate::id::unique()? } else { document.id().to_owned() };
            document
                .put(document::ID, Value::Str(Str::from(id)))
                .put(document::COLLECTION, Value::Str(Str::from(collection.id())))
                .put(
                    document::UPDATED_AT,
                    Value::Str(Str::from(match updated_at {
                        Some(u) if preserve_dates => u,
                        _ => time.clone(),
                    })),
                );

            if !self.is_preserving_sequence() {
                document.remove(document::SEQUENCE);
            }

            let created_at = match document.created_at().map(str::to_owned) {
                Some(c) if preserve_dates => Value::Str(Str::from(c)),
                _ if old.is_empty() => Value::Str(Str::from(time.as_str())),
                _ => old.created_at().map_or(Value::Null, |c| Value::Str(Str::from(c))),
            };
            document.put(document::CREATED_AT, created_at);

            // Force matching optional parameter sets. Doesn't use decode, as that
            // intentionally skips null defaults to reduce the payload size.
            for declared in &attributes {
                if !declared.required && !document.has(&declared.key) {
                    let value = old.get_or(&declared.key, &declared.default).clone();
                    document.put(declared.key.as_str(), value);
                }
            }

            if skip_permissions_update {
                let permissions = old.permissions()?.into_iter().map(|p| Value::Str(Str::from(p)));
                document.put(document::PERMISSIONS, crate::value::list(permissions));
            }

            if adapter.shared_tables() {
                if adapter.tenant_per_document() {
                    let Some(tenant) = document.tenant() else {
                        return Err(Error::database(
                            "Missing tenant. Tenant must be set when tenant per document is enabled.",
                        ));
                    };
                    if !old.is_empty() && !old.tenant().is_some_and(|t| t.strict_eq(&tenant)) {
                        return Err(Error::database("Tenant cannot be changed."));
                    }
                } else {
                    document.put(document::TENANT, adapter.tenant());
                }
            }

            let mut document = self.encode(&collection, document, true).await?;

            if self.is_validating() {
                let validator = Structure::new(collection.document().clone(), self.profile())
                    .current_document(if old.is_empty() { None } else { Some(old.clone()) });
                if !validator.validate(&mut document)? {
                    return Err(Error::structure(validator.description()));
                }
            }

            if !old.is_empty() {
                // Check whether the document was updated after the request timestamp.
                self.assert_not_updated_after_request(old.updated_at(), true)?;
            }

            if let Some(hook) = self.relationship_hook().filter(|h| h.is_enabled()) {
                document = self.silent(hook.after_document_create(&collection, document)).await?;
            }

            let identity = self.document_identity(&document);
            if has_operators {
                operator_ids.push(identity.clone());
            }
            seen_ids.push(identity);
            let old = self.cast_before(&collection, old)?;
            let document = self.cast_before(&collection, document)?;

            if let Some(slot) = tracked.as_deref_mut().and_then(|t| t.get_mut(index)) {
                *slot = document.clone();
            }
            given.push(index);
            changes.push(Change::new(old, document));
        }

        // Required because *some* databases allow duplicate ids for upserts.
        let mut unique: Vec<&String> = Vec::with_capacity(seen_ids.len());
        for id in &seen_ids {
            if !unique.contains(&id) {
                unique.push(id);
            }
        }
        if unique.len() != seen_ids.len() {
            return Err(Error::duplicate("Duplicate document IDs found in the input array."));
        }

        for (number, chunk) in changes.chunks(batch_size as usize).enumerate() {
            let indexes = &given[number * batch_size as usize..number * batch_size as usize + chunk.len()];
            let mut track = |batch: &[Document]| {
                if batch.len() != chunk.len() {
                    return;
                }
                if let Some(tracked) = tracked.as_deref_mut() {
                    for (document, index) in batch.iter().zip(indexes) {
                        if let Some(slot) = tracked.get_mut(*index) {
                            *slot = document.clone();
                        }
                    }
                }
            };
            let has_operators = chunk.iter().any(|c| operator_ids.contains(&self.document_identity(&c.new)));

            let target: Vec<Target> = chunk.iter().map(|c| Target::Document(c.new.clone())).collect();
            let mut batch = self
                .with_mutation(Event::DocumentsUpsert, &target, || {
                    let (collection, chunk, authorization) = (&collection, chunk.to_vec(), &authorization);
                    async move {
                        let Some(upserts) = self.inner.adapter.upserts() else {
                            return Err(Error::database("Adapter does not support upserts"));
                        };
                        let batch = authorization.skip(upserts.upsert_documents(collection, chunk, increase)).await?;

                        for document in &batch {
                            self.with_document_tenant(
                                document,
                                self.advance_collection_cache_epoch(collection.id(), document.id()),
                            )
                            .await?;
                        }

                        self.queue_document_purges(collection.id(), &batch).await?;

                        Ok(batch)
                    }
                })
                .await?;

            for (document, change) in batch.iter_mut().zip(chunk) {
                let sequence = |d: &Document| d.sequence().is_some_and(|s| !php_empty(s));
                if !sequence(document) && sequence(&change.old) {
                    let stored = change.old.sequence().cloned().unwrap_or(Value::Null);
                    document.put(document::SEQUENCE, stored);
                }
            }

            if on_next.is_some() || has_relationships {
                batch = adapter.get_sequences(&collection, batch).await?;
            }
            track(&batch);

            for change in chunk {
                if change.old.is_empty() {
                    created += 1;
                } else {
                    updated += 1;
                }
            }

            if let Some(hook) = self.relationship_hook()
                && !hook.is_in_batch_population()
                && hook.is_enabled()
            {
                let depth = hook.fetch_depth();
                batch = self
                    .silent(self.skip_query_hooks(hook.populate_documents(batch, &collection, depth, Vec::new())))
                    .await?;
            }

            if has_operators && on_next.is_some() {
                batch = self.refetch_documents(&collection, batch, Vec::new()).await?;
            }

            let mut batch = self.cast_after(&collection, batch)?;
            if !has_operators {
                let mut decoded = Vec::with_capacity(batch.len());
                for document in batch {
                    decoded.push(self.decode(&collection, document, &[]).await?);
                }
                batch = decoded;
                track(&batch);
            }

            let batch = self.decorate_documents(Event::DocumentsUpsert, &collection, batch).await?;

            for (document, change) in batch.into_iter().zip(chunk) {
                let mut old = change.old.clone();
                if !old.is_empty() {
                    old = self.cast_after_document(&collection, old)?;
                }
                if let Some(callback) = on_next.as_deref_mut() {
                    callback.call((document, if old.is_empty() { None } else { Some(old) })).await?;
                }
            }
        }

        Ok((created, updated))
    }

    /// The stored documents an upsert batch is compared against, by
    /// [`Database::upsert_key`], read in one query per tenant and chunk of
    /// ids instead of one per document (`findDocumentsToUpsert()`).
    async fn find_documents_to_upsert(
        &self,
        collection: &str,
        documents: &[Document],
    ) -> Result<HashMap<String, Document>> {
        let per_tenant = self.shared_tables() && self.tenant_per_document();

        let mut batches: Vec<(String, Value, Vec<String>)> = Vec::new();
        for document in documents {
            if document.id().is_empty() {
                continue;
            }
            let tenant = if per_tenant { document.tenant().unwrap_or(Value::Null) } else { Value::Null };
            let key = if matches!(tenant, Value::Null) { String::new() } else { to_text(&tenant) };
            match batches.iter_mut().find(|(k, _, _)| *k == key) {
                Some((_, _, ids)) => ids.push(document.id().to_owned()),
                None => batches.push((key, tenant, vec![document.id().to_owned()])),
            }
        }

        let max = self.max_query_values();
        let authorization = self.authorization();
        let mut existing = HashMap::new();
        for (_, tenant, ids) in batches {
            let mut unique: Vec<String> = Vec::with_capacity(ids.len());
            for id in ids {
                if !unique.contains(&id) {
                    unique.push(id);
                }
            }
            for chunk in unique.chunks(max.max(1) as usize) {
                let queries = vec![Query::equal(document::ID, chunk.to_vec()), Query::limit(max)];
                let read = authorization.skip(self.silent(self.skip_query_hooks(self.find(
                    collection,
                    queries,
                    PermissionType::Update,
                ))));
                let found = if per_tenant { self.with_tenant(tenant.clone(), read).await? } else { read.await? };
                for document in found {
                    existing.insert(self.upsert_key(&document), document);
                }
            }
        }

        Ok(existing)
    }

    /// The identity of a document within one upsert batch (`upsertKey()`):
    /// with the tenant when a batch can span tenants.
    fn upsert_key(&self, document: &Document) -> String {
        if self.shared_tables() && self.tenant_per_document() {
            let tenant = document.tenant().map(|t| to_text(&t)).unwrap_or_default();
            format!("{tenant}:{}", document.id())
        } else {
            document.id().to_owned()
        }
    }
}
