//! Documents of the [`Redis`] adapter: reads, writes, bulk writes, upserts,
//! sequences, deletes and increments, journalled inside transactions.

use super::{Entry, Redis, lower, raw_error, tx_error};
use crate::adapter::Adapter;
use crate::change::Change;
use crate::database::Database;
use crate::document::Document;
use crate::error::{Error, Result};
use crate::helpers::id;
use crate::query::{Method, Query};
use crate::value::{Array, KeyRef, Value, to_text};

impl Redis {
    /// Sets every relationship attribute the document lacks to `null`, so
    /// relationship population sees them (`surfaceRelationshipAttributesUsing()`).
    pub(super) fn surface(keys: &[String], mut document: Document) -> Document {
        for key in keys {
            if !document.has(key) {
                document.set(key.as_str(), Value::Null);
            }
        }
        document
    }

    /// The relationship attribute keys of a collection's meta hash
    /// (`extractRelationshipKeys()` of `readAttributesField()`); none for the
    /// metadata collection.
    pub(super) async fn relationship_keys(&self, collection: &str) -> Result<Vec<String>> {
        if collection == Database::METADATA {
            return Ok(Vec::new());
        }
        let meta = self.meta_key(&self.filter(collection)?);
        let attributes = self.read_attributes(&meta).await?;
        Ok(relationship_keys(&attributes))
    }

    /// `getDocument()`: the stored document (the null-tenant metadata row
    /// under shared tables), hidden from other tenants, with its missing
    /// relationship attributes set to `null` and `select` queries applied.
    pub(super) async fn read_document(&self, collection: &Document, id: &str, queries: &[Query]) -> Result<Document> {
        let col = self.filter(collection.id())?;
        let mut payload: Option<Vec<u8>> =
            self.run(redis::cmd("GET").arg(self.doc_key(&col, id, None))).await.map_err(raw_error)?;
        if payload.as_ref().is_none_or(|p| p.is_empty()) && self.shared_tables() && col == Database::METADATA {
            payload = self
                .run(redis::cmd("GET").arg(self.doc_key(&col, id, Some(&Value::from("_")))))
                .await
                .map_err(raw_error)?;
        }
        let Some(payload) = payload.filter(|p| !p.is_empty()) else {
            return Ok(Document::default());
        };
        let mut document = Self::decode(&payload)?;
        if self.shared_tables() {
            let row_tenant = document.raw("$tenant").cloned().unwrap_or(Value::Null);
            let allow_null = col == Database::METADATA && matches!(row_tenant, Value::Null);
            if !allow_null && !row_tenant.strict_eq(&self.tenant()) {
                return Ok(Document::default());
            }
        }
        if col != Database::METADATA {
            let keys = self.relationship_keys(&col).await?;
            document = Self::surface(&keys, document);
        }
        let selections: Vec<String> = queries
            .iter()
            .filter(|q| *q.method() == Method::Select)
            .flat_map(|q| q.values().iter().map(to_text).collect::<Vec<_>>())
            .collect();
        if !selections.is_empty() && !selections.iter().any(|s| s == "*") {
            let mut projected = Array::new();
            for (field, value) in document.iter() {
                let keep = match field {
                    KeyRef::Str(s) => {
                        s.starts_with(b"$") || s.starts_with(b"_") || selections.iter().any(|x| x.as_bytes() == s)
                    }
                    // `in_array($field, $selections, true)`: an integer key never matches.
                    KeyRef::Int(_) => false,
                };
                if keep {
                    projected.set(field.to_owned(), value.clone());
                }
            }
            document = Document::new(projected)?;
        }
        Ok(document)
    }

    /// `createDocument()`: refuses (or, skipping duplicates, ignores) an
    /// existing id or a unique index violation; gives a `$sequence`; stores
    /// the document, its id and its permissions.
    pub(super) async fn insert_document(&self, collection: &Document, mut document: Document) -> Result<Document> {
        let col = self.filter(collection.id())?;
        let mut id = document.id().to_owned();
        if id.is_empty() {
            id = id::unique()?;
            document.set("$id", Value::from(id.as_str()));
        }
        let tenant = document.tenant();
        let doc_key = self.doc_key(&col, &id, tenant.as_ref());
        let idx_key = self.idx_key(&col, tenant.as_ref());
        let seq_key = self.seq_key(&col, tenant.as_ref());
        let perm_doc_key = self.perm_doc_key(&col, &id);

        let exists: i64 = self.run(redis::cmd("EXISTS").arg(&doc_key)).await.map_err(tx_error)?;
        if exists > 0 {
            if self.skip_duplicates() {
                let existing: Option<Vec<u8>> = self.run(redis::cmd("GET").arg(&doc_key)).await.map_err(tx_error)?;
                if let Some(existing) = existing.filter(|p| !p.is_empty()) {
                    let existing = Self::decode(&existing)?;
                    let sequence = existing.sequence().cloned().unwrap_or(Value::from(""));
                    document.set("$sequence", sequence);
                }
                return Ok(document);
            }
            return Err(Error::duplicate("Document already exists"));
        }
        if let Err(e) = self.enforce_unique_indexes(&col, &document, None).await {
            if e.is_a(crate::error::Kind::Duplicate) && self.skip_duplicates() {
                return Ok(document);
            }
            return Err(e);
        }
        let sequence = self.next_sequence(&seq_key, document.sequence()).await?;
        document.set("$sequence", Value::from(sequence.as_str()));

        let payload = Self::encode(&document)?;
        let _: () = self.run(redis::cmd("SET").arg(&doc_key).arg(payload)).await.map_err(tx_error)?;
        let _: i64 = self.run(redis::cmd("SADD").arg(&idx_key).arg(lower(&id))).await.map_err(tx_error)?;
        self.write_permissions(&col, &id, &document).await?;
        self.record(Entry::CreateDoc { collection: col, id, doc_key, idx_key, perm_doc_key });
        Ok(document)
    }

    /// The `$sequence` of a new document: the counter's next value, or the
    /// one given (which raises the counter when higher).
    async fn next_sequence(&self, seq_key: &str, given: Option<&Value>) -> Result<String> {
        match given.filter(|s| s.truthy()) {
            None => {
                let next: i64 = self.run(redis::cmd("INCR").arg(seq_key)).await.map_err(tx_error)?;
                Ok(next.to_string())
            }
            Some(sequence) => {
                let sequence = to_text(sequence);
                let current: Option<String> = self.run(redis::cmd("GET").arg(seq_key)).await.map_err(tx_error)?;
                let higher = match &current {
                    None => true,
                    Some(c) => {
                        crate::adapter::redis::query::int_of(&Value::from(sequence.as_str()))
                            > crate::adapter::redis::query::int_of(&Value::from(c.as_str()))
                    }
                };
                if higher {
                    let _: () = self.run(redis::cmd("SET").arg(seq_key).arg(&sequence)).await.map_err(tx_error)?;
                }
                Ok(sequence)
            }
        }
    }

    /// `updateDocument()`: merges the update (operators applied) over the
    /// stored document, refuses a taken new id or a unique index violation,
    /// moves the document when its id changes, and rewrites its permissions
    /// unless skipped. Under shared tables a metadata row only the null
    /// tenant holds is updated in place.
    pub(super) async fn replace_document(
        &self,
        collection: &Document,
        id: &str,
        document: Document,
        skip_permissions: bool,
    ) -> Result<Document> {
        let col = self.filter(collection.id())?;
        let mut old_key = self.doc_key(&col, id, None);
        let idx_key = self.idx_key(&col, None);
        let mut null_tenant = false;
        if col == Database::METADATA
            && self.shared_tables()
            && !matches!(self.tenant(), Value::Null)
            && !self.exists_key(&old_key).await?
        {
            old_key = self.doc_key(&col, id, Some(&Value::from("_")));
            null_tenant = true;
        }
        let existing_payload: Option<Vec<u8>> = self.run(redis::cmd("GET").arg(&old_key)).await.map_err(tx_error)?;
        let Some(existing_payload) = existing_payload.filter(|p| !p.is_empty()) else {
            return Err(Error::not_found("Document not found"));
        };
        let mut existing = Self::decode(&existing_payload)?;
        if col != Database::METADATA {
            let keys = self.relationship_keys(&col).await?;
            existing = Self::surface(&keys, existing);
        }
        let new_id = if document.id().is_empty() { id.to_owned() } else { document.id().to_owned() };
        let new_key = if null_tenant {
            self.doc_key(&col, &new_id, Some(&Value::from("_")))
        } else {
            self.doc_key(&col, &new_id, None)
        };
        let idx_key = if null_tenant { self.idx_key(&col, Some(&Value::from("_"))) } else { idx_key };
        if new_id != id {
            let taken: i64 = self.run(redis::cmd("EXISTS").arg(&new_key)).await.map_err(tx_error)?;
            if taken > 0 {
                return Err(Error::duplicate("Document already exists"));
            }
        }
        let resolved = self.apply_operators(document.array_copy(&[], &[]), &existing.array_copy(&[], &[]))?;
        let mut merged = existing.array_copy(&[], &[]);
        for (k, v) in resolved.iter() {
            merged.set(k.to_owned(), v.clone());
        }
        merged.set(crate::value::ArrayKey::from("$id"), Value::from(new_id.as_str()));
        let merged = Document::new(merged)?;
        self.enforce_unique_indexes(&col, &merged, Some(id)).await?;
        let payload = Self::encode(&merged)?;
        if new_id != id {
            let _: i64 = self.run(redis::cmd("DEL").arg(&old_key)).await.map_err(tx_error)?;
            let _: i64 = self.run(redis::cmd("SREM").arg(&idx_key).arg(lower(id))).await.map_err(tx_error)?;
        }
        let _: () = self.run(redis::cmd("SET").arg(&new_key).arg(payload)).await.map_err(tx_error)?;
        let _: i64 = self.run(redis::cmd("SADD").arg(&idx_key).arg(lower(&new_id))).await.map_err(tx_error)?;
        self.record(Entry::UpdateDoc {
            collection: col.clone(),
            id: id.to_owned(),
            new_id: new_id.clone(),
            payload: existing_payload,
            doc_key: old_key,
            new_doc_key: Some(new_key),
            idx_key: Some(idx_key),
        });
        if !skip_permissions {
            self.clear_permissions(&col, id).await?;
            if new_id != id {
                self.clear_permissions(&col, &new_id).await?;
            }
            self.write_permissions(&col, &new_id, &merged).await?;
        }
        Ok(merged)
    }

    /// `updateDocuments()`: applies the updates (operators, dates,
    /// permissions) to each document still stored; how many were updated.
    pub(super) async fn replace_documents(
        &self,
        collection: &Document,
        updates: &Document,
        documents: &[Document],
    ) -> Result<i64> {
        if documents.is_empty() {
            return Ok(0);
        }
        let attrs = updates.attributes();
        let created_at = updates.created_at().filter(|c| !c.is_empty() && *c != "0").map(str::to_owned);
        let updated_at = updates.updated_at().filter(|c| !c.is_empty() && *c != "0").map(str::to_owned);
        let has_permissions = updates.has("$permissions");
        if attrs.is_empty() && created_at.is_none() && updated_at.is_none() && !has_permissions {
            return Ok(0);
        }
        let col = self.filter(collection.id())?;
        let keys: Vec<String> = documents.iter().map(|d| self.doc_key(&col, d.id(), None)).collect();
        let mut pipe = redis::pipe();
        for key in &keys {
            pipe.cmd("GET").arg(key);
        }
        let payloads: Vec<Option<Vec<u8>>> = self.pipe(&pipe).await.map_err(tx_error)?;
        let relationship_keys = if col != Database::METADATA {
            let meta = self.meta_key(&self.filter(&col)?);
            relationship_keys(&self.read_attributes(&meta).await?)
        } else {
            Vec::new()
        };
        let mut count = 0;
        for (i, document) in documents.iter().enumerate() {
            let uid = document.id().to_owned();
            let Some(existing_payload) = payloads.get(i).cloned().flatten().filter(|p| !p.is_empty()) else {
                continue;
            };
            let mut existing = Self::decode(&existing_payload)?;
            if !relationship_keys.is_empty() {
                existing = Self::surface(&relationship_keys, existing);
            }
            let mut merged = existing.array_copy(&[], &[]);
            let resolved = self.apply_operators(attrs.clone(), &merged)?;
            for (k, v) in resolved.iter() {
                merged.set(k.to_owned(), v.clone());
            }
            if let Some(c) = &created_at {
                merged.set(crate::value::ArrayKey::from("$createdAt"), Value::from(c.as_str()));
            }
            if let Some(u) = &updated_at {
                merged.set(crate::value::ArrayKey::from("$updatedAt"), Value::from(u.as_str()));
            }
            if has_permissions {
                merged.set(
                    crate::value::ArrayKey::from("$permissions"),
                    Value::Array(updates.permissions().into_iter().map(|p| Value::from(p.as_str())).collect()),
                );
            }
            let merged = Document::new(merged)?;
            let _: () =
                self.run(redis::cmd("SET").arg(&keys[i]).arg(Self::encode(&merged)?)).await.map_err(tx_error)?;
            self.record(Entry::UpdateDoc {
                collection: col.clone(),
                id: uid.clone(),
                new_id: uid.clone(),
                payload: existing_payload,
                doc_key: keys[i].clone(),
                new_doc_key: None,
                idx_key: None,
            });
            if has_permissions {
                self.clear_permissions(&col, &uid).await?;
                self.write_permissions(&col, &uid, &merged).await?;
            }
            count += 1;
        }
        Ok(count)
    }

    /// `upsertDocuments()`: each change updates the stored document (adding
    /// to `attribute` when given) or creates it, journalled like the single
    /// writes; the written documents, in order.
    pub(super) async fn upsert(
        &self,
        collection: &Document,
        attribute: &str,
        changes: Vec<Change>,
    ) -> Result<Vec<Document>> {
        if changes.is_empty() {
            return Ok(Vec::new());
        }
        let col = self.filter(collection.id())?;
        let mut pipe = redis::pipe();
        for change in &changes {
            let tenant = change.new.tenant();
            pipe.cmd("GET").arg(self.doc_key(&col, change.new.id(), tenant.as_ref()));
        }
        let payloads: Vec<Option<Vec<u8>>> = self.pipe(&pipe).await.map_err(tx_error)?;
        let relationship_keys = if col != Database::METADATA {
            let meta = self.meta_key(&self.filter(&col)?);
            relationship_keys(&self.read_attributes(&meta).await?)
        } else {
            Vec::new()
        };
        let mut results = Vec::with_capacity(changes.len());
        for (i, change) in changes.into_iter().enumerate() {
            let mut document = change.new;
            let id = document.id().to_owned();
            let tenant = document.tenant();
            let doc_key = self.doc_key(&col, &id, tenant.as_ref());
            let idx_key = self.idx_key(&col, tenant.as_ref());
            let seq_key = self.seq_key(&col, tenant.as_ref());
            match payloads.get(i).cloned().flatten().filter(|p| !p.is_empty()) {
                Some(existing_payload) => {
                    let mut existing = Self::decode(&existing_payload)?;
                    if !relationship_keys.is_empty() {
                        existing = Self::surface(&relationship_keys, existing);
                    }
                    let existing_array = existing.array_copy(&[], &[]);
                    let resolved = self.apply_operators(document.array_copy(&[], &[]), &existing_array)?;
                    let mut merged = existing_array;
                    for (k, v) in resolved.iter() {
                        merged.set(k.to_owned(), v.clone());
                    }
                    merged.set(crate::value::ArrayKey::from("$id"), Value::from(id.as_str()));
                    if !attribute.is_empty() {
                        let previous = existing.get(attribute).cloned().unwrap_or(Value::Null);
                        let delta = document.get(attribute).cloned().unwrap_or(Value::Null);
                        let numeric = |v: &Value| super::query::numeric(v).unwrap_or(Value::Int(0));
                        let sum = crate::validator::operator::arith(
                            crate::validator::operator::Arith::Add,
                            &numeric(&previous),
                            &numeric(&delta),
                        )?;
                        merged.set(crate::value::ArrayKey::normalize(attribute.into()), sum);
                    }
                    let merged = Document::new(merged)?;
                    let _: () = self
                        .run(redis::cmd("SET").arg(&doc_key).arg(Self::encode(&merged)?))
                        .await
                        .map_err(tx_error)?;
                    self.record(Entry::UpdateDoc {
                        collection: col.clone(),
                        id: id.clone(),
                        new_id: id.clone(),
                        payload: existing_payload,
                        doc_key: doc_key.clone(),
                        new_doc_key: None,
                        idx_key: None,
                    });
                    self.clear_permissions(&col, &id).await?;
                    self.write_permissions(&col, &id, &merged).await?;
                    results.push(merged);
                }
                None => {
                    self.enforce_unique_indexes(&col, &document, None).await?;
                    let sequence = self.next_sequence(&seq_key, document.sequence()).await?;
                    document.set("$sequence", Value::from(sequence.as_str()));
                    let resolved = self.apply_operators(document.array_copy(&[], &[]), &Array::new())?;
                    for (k, v) in resolved.iter() {
                        document.as_array_mut().set(k.to_owned(), v.clone());
                    }
                    let _: () = self
                        .run(redis::cmd("SET").arg(&doc_key).arg(Self::encode(&document)?))
                        .await
                        .map_err(tx_error)?;
                    let _: i64 = self.run(redis::cmd("SADD").arg(&idx_key).arg(lower(&id))).await.map_err(tx_error)?;
                    self.write_permissions(&col, &id, &document).await?;
                    let perm_doc_key = self.perm_doc_key(&col, &id);
                    self.record(Entry::CreateDoc {
                        collection: col.clone(),
                        id: id.clone(),
                        doc_key,
                        idx_key,
                        perm_doc_key,
                    });
                    results.push(document);
                }
            }
        }
        Ok(results)
    }

    /// `getSequences()`: the stored `$sequence` of each document lacking one.
    pub(super) async fn sequences(&self, collection: &str, mut documents: Vec<Document>) -> Result<Vec<Document>> {
        if documents.is_empty() {
            return Ok(documents);
        }
        let mut pipe = redis::pipe();
        let mut indexes = Vec::new();
        for (index, document) in documents.iter().enumerate() {
            if document.sequence().is_some_and(Value::truthy) {
                continue;
            }
            pipe.cmd("GET").arg(self.doc_key(collection, document.id(), None));
            indexes.push(index);
        }
        if indexes.is_empty() {
            return Ok(documents);
        }
        let payloads: Vec<Option<Vec<u8>>> = self
            .pipe(&pipe)
            .await
            .map_err(|e| Error::transaction(format!("Failed to load sequences: {}", super::redis_message(&e))))?;
        for (position, index) in indexes.into_iter().enumerate() {
            let Some(payload) = payloads.get(position).cloned().flatten().filter(|p| !p.is_empty()) else {
                continue;
            };
            let existing = Self::decode(&payload)?;
            if let Some(sequence) = existing.sequence().filter(|s| s.truthy()) {
                documents[index].set("$sequence", Value::from(to_text(sequence).as_str()));
            }
        }
        Ok(documents)
    }

    /// `deleteDocument()`: whether a document was there to delete.
    pub(super) async fn remove_document(&self, collection: &str, id: &str) -> Result<bool> {
        let collection = self.filter(collection)?;
        let doc_key = self.doc_key(&collection, id, None);
        let idx_key = self.idx_key(&collection, None);
        let payload: Option<Vec<u8>> = self.run(redis::cmd("GET").arg(&doc_key)).await.map_err(tx_error)?;
        let Some(payload) = payload.filter(|p| !p.is_empty()) else {
            return Ok(false);
        };
        self.record(Entry::DeleteDoc {
            collection: collection.clone(),
            id: id.to_owned(),
            payload,
            doc_key: doc_key.clone(),
            idx_key: idx_key.clone(),
        });
        self.clear_permissions(&collection, id).await?;
        let _: i64 = self.run(redis::cmd("DEL").arg(&doc_key)).await.map_err(tx_error)?;
        let _: i64 = self.run(redis::cmd("SREM").arg(&idx_key).arg(lower(id))).await.map_err(tx_error)?;
        Ok(true)
    }

    /// `deleteDocuments()`: the documents with the given sequences, and the
    /// permissions of the other ids listed; how many were deleted.
    pub(super) async fn remove_documents(
        &self,
        collection: &str,
        sequences: &[Value],
        permission_ids: &[String],
    ) -> Result<i64> {
        if sequences.is_empty() && permission_ids.is_empty() {
            return Ok(0);
        }
        let collection = self.filter(collection)?;
        let idx_key = self.idx_key(&collection, None);
        let wanted: Vec<String> = sequences.iter().map(to_text).collect();
        let ids: Vec<String> = self.run(redis::cmd("SMEMBERS").arg(&idx_key)).await.map_err(tx_error)?;
        let keys: Vec<String> = ids.iter().map(|id| self.doc_key(&collection, id, None)).collect();
        let mut pipe = redis::pipe();
        for key in &keys {
            pipe.cmd("GET").arg(key);
        }
        let payloads: Vec<Option<Vec<u8>>> =
            if keys.is_empty() { Vec::new() } else { self.pipe(&pipe).await.map_err(tx_error)? };
        // Document id => (payload, key), in the order found; a later
        // document with the same id replaces the payload in place.
        let mut deleted: Vec<(String, Vec<u8>, String)> = Vec::new();
        for (position, key) in keys.iter().enumerate() {
            let Some(payload) = payloads.get(position).cloned().flatten().filter(|p| !p.is_empty()) else {
                continue;
            };
            let document = Self::decode(&payload)?;
            let sequence = document.sequence().map(to_text).unwrap_or_default();
            if wanted.contains(&sequence) {
                let id = document.id().to_owned();
                match deleted.iter_mut().find(|(d, _, _)| *d == id) {
                    Some(entry) => {
                        entry.1 = payload;
                        entry.2 = key.clone();
                    }
                    None => deleted.push((id, payload, key.clone())),
                }
            }
        }
        for (id, payload, key) in &deleted {
            self.record(Entry::DeleteDoc {
                collection: collection.clone(),
                id: id.clone(),
                payload: payload.clone(),
                doc_key: key.clone(),
                idx_key: idx_key.clone(),
            });
            self.clear_permissions(&collection, id).await?;
            let _: i64 = self.run(redis::cmd("DEL").arg(key)).await.map_err(tx_error)?;
            let _: i64 = self.run(redis::cmd("SREM").arg(&idx_key).arg(lower(id))).await.map_err(tx_error)?;
        }
        for id in permission_ids {
            if deleted.iter().any(|(d, _, _)| d == id) {
                continue;
            }
            self.clear_permissions(&collection, id).await?;
        }
        Ok(deleted.len() as i64)
    }

    /// `increaseDocumentAttribute()`: adds `value` unless the current value
    /// is outside the bounds (a silent no-op), and sets `$updatedAt`.
    #[allow(clippy::too_many_arguments)]
    pub(super) async fn increase(
        &self,
        collection: &str,
        id: &str,
        attribute: &str,
        value: &Value,
        updated_at: &str,
        min: Option<&Value>,
        max: Option<&Value>,
    ) -> Result<bool> {
        let collection = self.filter(collection)?;
        let doc_key = self.doc_key(&collection, id, None);
        let payload: Option<Vec<u8>> = self.run(redis::cmd("GET").arg(&doc_key)).await.map_err(tx_error)?;
        let Some(payload) = payload.filter(|p| !p.is_empty()) else {
            return Err(Error::not_found("Document not found"));
        };
        let mut document = Self::decode(&payload)?;
        let current = super::query::numeric(document.get(attribute).unwrap_or(&Value::Null)).unwrap_or(Value::Int(0));
        if let Some(min) = min.filter(|m| !matches!(m, Value::Null))
            && current.compare(min) < 0
        {
            return Ok(true);
        }
        if let Some(max) = max.filter(|m| !matches!(m, Value::Null))
            && max.compare(&current) < 0
        {
            return Ok(true);
        }
        let sum = crate::validator::operator::arith(crate::validator::operator::Arith::Add, &current, value)?;
        document.set(attribute, sum);
        document.set("$updatedAt", Value::from(updated_at));
        let _: () = self.run(redis::cmd("SET").arg(&doc_key).arg(Self::encode(&document)?)).await.map_err(tx_error)?;
        self.record(Entry::UpdateDoc {
            collection,
            id: id.to_owned(),
            new_id: id.to_owned(),
            payload,
            doc_key,
            new_doc_key: None,
            idx_key: None,
        });
        Ok(true)
    }

    /// Refuses a document whose unique-index values another document of the
    /// same tenant already holds (`enforceUniqueIndexes()`); `exclude` is
    /// the id of the document being updated.
    pub(super) async fn enforce_unique_indexes(
        &self,
        collection: &str,
        document: &Document,
        exclude: Option<&str>,
    ) -> Result<()> {
        let meta = self.meta_key(collection);
        let indexes = self.read_indexes(&meta).await?;
        let unique: Vec<Vec<String>> = indexes
            .iter()
            .filter(|index| matches!(index.get_str("type"), Some(Value::Str(s)) if s.as_bytes() == Database::INDEX_UNIQUE.as_bytes()))
            .map(|index| match index.get_str("attributes") {
                Some(Value::Array(a)) => a.values().map(to_text).collect::<Vec<_>>(),
                _ => Vec::new(),
            })
            .filter(|attributes| !attributes.is_empty())
            .collect();
        if unique.is_empty() {
            return Ok(());
        }
        let shared = self.shared_tables();
        let tenant = if shared {
            document.raw("$tenant").cloned().filter(|t| !matches!(t, Value::Null)).unwrap_or_else(|| self.tenant())
        } else {
            Value::Null
        };
        let tenant_ref = shared.then_some(&tenant);
        let signatures: Vec<(usize, Vec<u8>)> = unique
            .iter()
            .enumerate()
            .filter_map(|(i, attributes)| self.signature(document, attributes, tenant_ref).map(|s| (i, s)))
            .collect();
        if signatures.is_empty() {
            return Ok(());
        }
        let idx = self.idx_key(collection, None);
        let ids: Vec<String> = self.run(redis::cmd("SMEMBERS").arg(&idx)).await.map_err(tx_error)?;
        if ids.is_empty() {
            return Ok(());
        }
        let exclude = exclude.map(lower);
        let keys: Vec<String> = ids
            .iter()
            .filter(|id| exclude.as_ref().is_none_or(|e| lower(id) != *e))
            .map(|id| self.doc_key(collection, id, None))
            .collect();
        if keys.is_empty() {
            return Ok(());
        }
        let payloads: Vec<Option<Vec<u8>>> = self.run(redis::cmd("MGET").arg(&keys)).await.map_err(tx_error)?;
        for payload in payloads.into_iter().flatten().filter(|p| !p.is_empty()) {
            let existing = Self::decode(&payload)?;
            if shared && !existing.raw("$tenant").cloned().unwrap_or(Value::Null).strict_eq(&tenant) {
                continue;
            }
            for (i, signature) in &signatures {
                if self.signature(&existing, &unique[*i], tenant_ref).as_ref() == Some(signature) {
                    return Err(Error::unique("Unique index violation"));
                }
            }
        }
        Ok(())
    }

    /// The unique-index signature of a document: `serialize()` of its
    /// normalised values (the tenant first under shared tables); `None`
    /// when one is `null`, which never collides.
    pub(super) fn signature(
        &self,
        document: &Document,
        attributes: &[String],
        tenant: Option<&Value>,
    ) -> Option<Vec<u8>> {
        let mut signature: Vec<Value> = Vec::with_capacity(attributes.len() + 1);
        if let Some(tenant) = tenant {
            signature.push(tenant.clone());
        }
        for attribute in attributes {
            let value = self.resolve(document, attribute);
            if matches!(value, Value::Null) {
                return None;
            }
            signature.push(normalize_index_value(value));
        }
        let list = Value::Array(signature.into_iter().collect());
        Some(php_std::serialize::serialize(&crate::value::to_plain(&list)))
    }
}

/// `normalizeIndexValue()`: booleans as 0 or 1, numeric strings as numbers.
fn normalize_index_value(value: Value) -> Value {
    match value {
        Value::Bool(b) => Value::Int(i64::from(b)),
        Value::Str(ref s) if php_std::value::numeric_bytes_ex(s.as_bytes()).is_some() => {
            super::query::numeric(&value).unwrap_or(value)
        }
        other => other,
    }
}

/// The relationship attribute keys of attribute records
/// (`extractRelationshipKeys()`).
pub(super) fn relationship_keys(attributes: &[Array]) -> Vec<String> {
    let mut keys = Vec::new();
    for attribute in attributes {
        if !matches!(attribute.get_str("type"), Some(Value::Str(s)) if s.as_bytes() == Database::VAR_RELATIONSHIP.as_bytes())
        {
            continue;
        }
        let key = match attribute.get_str("$id").filter(|v| !matches!(v, Value::Null)) {
            Some(v) => to_text(v),
            None => attribute.get_str("key").filter(|v| !matches!(v, Value::Null)).map(to_text).unwrap_or_default(),
        };
        if !key.is_empty() {
            keys.push(key);
        }
    }
    keys
}
