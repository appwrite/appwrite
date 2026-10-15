//! Documents of the [`Redis`] adapter: writes with their journal entries,
//! permission sets and unique checks, and reads by id.

use std::collections::HashSet;

use redis::aio::ConnectionLike;

use super::unique::Write;
use super::{Redis, lower, payload_of, relationship_keys, reply_bytes, reply_pairs, string, utf8};
use crate::adapter::{Adapter, filter};
use crate::change::Change;
use crate::collection::Collection;
use crate::database;
use crate::document::{self, Document};
use crate::error::{Error, Kind, Result};
use crate::permission::PermissionType;
use crate::query::Query;
use crate::validator::big_int::{BigInt, Operand};
use crate::value::{Array, KeyRef, Value, to_text};

/// `empty($value)`.
fn empty(value: Option<&Value>) -> bool {
    value.is_none_or(|v| !v.truthy())
}

/// PHP's `array_merge($a, $b)`: string keys of `b` replace those of `a`,
/// integer keys are appended and renumbered.
pub(super) fn array_merge(a: Array, b: Array) -> Array {
    let mut out = Array::with_capacity(a.len() + b.len());
    for (k, v) in a.into_iter().chain(b) {
        match k.as_ref() {
            KeyRef::Int(_) => {
                let _ = out.push(v);
            }
            KeyRef::Str(_) => {
                out.set(k, v);
            }
        }
    }
    out
}

/// The letter of a permission type in permission keys (`actionLetter()`).
pub(super) fn action_letter(action: PermissionType) -> &'static str {
    match action {
        PermissionType::Read => "r",
        PermissionType::Create => "c",
        PermissionType::Update => "u",
        PermissionType::Delete => "d",
        PermissionType::Write => "w",
    }
}

impl<C: ConnectionLike + Clone + Send + Sync + 'static> Redis<C> {
    // ----- Reads -----

    /// `getDocument()`: the stored document the tenant may see (the
    /// tenantless definition for the metadata collection), missing
    /// relationship attributes as `null`, projected on the selections.
    pub(super) async fn read_document(&self, collection: &Collection, id: &str, queries: &[Query]) -> Result<Document> {
        let col = filter(collection.id());
        let mut payload = self.get_payload(&self.doc_key(&col, id, None)).await?;
        if payload.is_none() && self.shared_tables() && col == database::METADATA {
            payload = self.get_payload(&self.doc_key(&col, id, Some(&string("_")))).await?;
        }
        let Some(payload) = payload else {
            return Ok(Document::empty());
        };
        let mut document = Self::decode(&payload)?;
        if self.shared_tables() {
            let row = document.get(document::TENANT).cloned().unwrap_or(Value::Null);
            let tenant = self.tenant();
            let allow_null = col == database::METADATA && matches!(row, Value::Null);
            if !allow_null && !row.strict_eq(&tenant) {
                return Ok(Document::empty());
            }
        }
        if col != database::METADATA {
            document = self.surface(&col, document).await?;
        }
        let selections = super::query::selections(queries);
        if !selections.is_empty() && !selections.iter().any(|s| s == "*") {
            document = super::query::project(document, &selections)?;
        }
        Ok(document)
    }

    /// `surfaceRelationshipAttributes()`: the collection's relationship
    /// attributes the document lacks, set to `null`.
    pub(super) async fn surface(&self, collection: &str, document: Document) -> Result<Document> {
        if collection == database::METADATA {
            return Ok(document);
        }
        let keys = relationship_keys(&self.read_attributes(&self.meta_key(&filter(collection))).await?);
        Ok(surface_using(&keys, document))
    }

    // ----- Creates -----

    /// `insertDocument()`: the stored document, or (under
    /// `ignoreDuplicates()`) `Err` of the document skipped, carrying the
    /// stored `$sequence` when its id is taken.
    pub(super) async fn insert_document(
        &self,
        collection: &Collection,
        mut document: Document,
    ) -> Result<std::result::Result<Document, Document>> {
        let col = filter(collection.id());
        let mut id = document.id().to_owned();
        if id.is_empty() {
            id = crate::id::unique()?;
            document.set(document::ID, string(id.as_str()))?;
        }
        let tenant = document.tenant();
        let doc_key = self.doc_key(&col, &id, tenant.as_ref());
        let idx_key = self.idx_key(&col, tenant.as_ref());
        let seq_key = self.seq_key(&col, tenant.as_ref());
        let perm_doc_key = self.perm_doc_key(&col, &id, tenant.as_ref());
        let ignoring = self.state.ignoring_duplicates().get();
        self.tx(async move {
            if self.exists_key(&doc_key).await? {
                if ignoring {
                    if let Some(existing) = self.get_payload(&doc_key).await? {
                        let existing = Self::decode(&existing)?;
                        let sequence = existing.sequence().cloned().unwrap_or_else(|| string(""));
                        document.set(document::SEQUENCE, sequence)?;
                    }
                    return Ok(Err(document));
                }
                return Err(Error::duplicate("Document already exists"));
            }
            if let Err(e) = self.enforce_unique(&col, &document, None).await {
                if e.is_a(Kind::Duplicate) && ignoring {
                    return Ok(Err(document));
                }
                return Err(e);
            }
            let sequence = self.next_sequence(&seq_key, document.sequence()).await?;
            document.set(document::SEQUENCE, string(sequence))?;
            let _: redis::Value = self.run(redis::cmd("SET").arg(&doc_key).arg(Self::encode(&document)?)).await?;
            let _: i64 = self.run(redis::cmd("SADD").arg(&idx_key).arg(lower(&id))).await?;
            self.write_permissions(&col, &id, &document).await?;
            self.journal(
                "createDoc",
                vec![
                    ("collection", col.clone().into_bytes()),
                    ("id", id.clone().into_bytes()),
                    ("docKey", doc_key.clone().into_bytes()),
                    ("idxKey", idx_key.clone().into_bytes()),
                    ("permDocKey", perm_doc_key.clone().into_bytes()),
                ],
            );
            Ok(Ok(document))
        })
        .await
    }

    /// The `$sequence` of a new document: the next of the counter when it
    /// has none, else its own, which raises the counter when above it.
    async fn next_sequence(&self, seq_key: &str, sequence: Option<&Value>) -> Result<String> {
        if empty(sequence) {
            let next: i64 = self.run(redis::cmd("INCR").arg(seq_key)).await?;
            return Ok(next.to_string());
        }
        let sequence = sequence.map(to_text).unwrap_or_default();
        let current: Option<Vec<u8>> = self.run(redis::cmd("GET").arg(seq_key)).await?;
        let raise = match &current {
            None => true,
            Some(current) => {
                php_std::format::str_to_int(sequence.as_bytes()) > php_std::format::str_to_int(current)
            }
        };
        if raise {
            let _: redis::Value = self.run(redis::cmd("SET").arg(seq_key).arg(&sequence)).await?;
        }
        Ok(sequence)
    }

    // ----- Updates -----

    /// `updateDocument()`: the stored document merged with the update (its
    /// operators applied), under the update's `$id`; permissions rewritten
    /// unless skipped. Returns the merged document.
    pub(super) async fn replace_document(
        &self,
        collection: &Collection,
        id: &str,
        document: Document,
        skip_permissions: bool,
    ) -> Result<Document> {
        let col = filter(collection.id());
        let mut old_key = self.doc_key(&col, id, None);
        let idx_key = self.idx_key(&col, None);
        let mut null_tenant = false;
        if col == database::METADATA
            && self.shared_tables()
            && !matches!(self.tenant(), Value::Null)
            && !self.exists_key(&old_key).await?
        {
            old_key = self.doc_key(&col, id, Some(&string("_")));
            null_tenant = true;
        }
        self.tx(async {
            let Some(existing_payload) = self.get_payload(&old_key).await? else {
                return Err(Error::not_found("Document not found"));
            };
            let mut existing = Self::decode(&existing_payload)?;
            if col != database::METADATA {
                existing = self.surface(&col, existing).await?;
            }
            let new_id = if document.id().is_empty() { id.to_owned() } else { document.id().to_owned() };
            let underscore = string("_");
            let new_key = self.doc_key(&col, &new_id, null_tenant.then_some(&underscore));
            let effective_idx = if null_tenant { self.idx_key(&col, Some(&underscore)) } else { idx_key.clone() };
            if new_key != old_key && self.exists_key(&new_key).await? {
                return Err(Error::duplicate("Document already exists"));
            }
            let existing_copy = existing.array_copy();
            let resolved = self.apply_operators(document.array_copy(), &existing_copy)?;
            let mut merged = array_merge(existing_copy, resolved);
            merged.set(document::ID.into(), string(new_id.as_str()));
            let merged = Document::new(merged)?;
            self.enforce_unique(&col, &merged, Some(id)).await?;
            let payload = Self::encode(&merged)?;
            if new_id != id {
                let _: i64 = self.run(redis::cmd("DEL").arg(&old_key)).await?;
                let _: i64 = self.run(redis::cmd("SREM").arg(&effective_idx).arg(lower(id))).await?;
            }
            let _: redis::Value = self.run(redis::cmd("SET").arg(&new_key).arg(payload)).await?;
            let _: i64 = self.run(redis::cmd("SADD").arg(&effective_idx).arg(lower(&new_id))).await?;
            self.journal(
                "updateDoc",
                vec![
                    ("collection", col.clone().into_bytes()),
                    ("id", id.as_bytes().to_vec()),
                    ("newId", new_id.clone().into_bytes()),
                    ("payload", existing_payload),
                    ("docKey", old_key.clone().into_bytes()),
                    ("newDocKey", new_key.into_bytes()),
                    ("idxKey", effective_idx.into_bytes()),
                ],
            );
            if !skip_permissions {
                self.clear_permissions(&col, id, None).await?;
                if new_id != id {
                    self.clear_permissions(&col, &new_id, None).await?;
                }
                self.write_permissions(&col, &new_id, &merged).await?;
            }
            Ok(merged)
        })
        .await
    }

    /// `updateDocuments()`: `updates` (its operators applied, and its
    /// `$createdAt`, `$updatedAt` and `$permissions` when given) written onto
    /// each stored document; the count written.
    pub(super) async fn replace_documents(
        &self,
        collection: &Collection,
        updates: &Document,
        documents: Vec<Document>,
    ) -> Result<i64> {
        if documents.is_empty() {
            return Ok(0);
        }
        let changed = updates.attributes();
        let created_at = updates.created_at().filter(|s| !s.is_empty()).map(str::to_owned);
        let updated_at = updates.updated_at().filter(|s| !s.is_empty()).map(str::to_owned);
        let permissions = updates.has(document::PERMISSIONS);
        if changed.is_empty() && created_at.is_none() && updated_at.is_none() && !permissions {
            return Ok(0);
        }
        let col = filter(collection.id());
        self.tx(async {
            let doc_keys: Vec<String> = documents.iter().map(|d| self.doc_key(&col, d.id(), None)).collect();
            let mut pipe = redis::pipe();
            for key in &doc_keys {
                pipe.cmd("GET").arg(key);
            }
            let payloads = self.pipe(&pipe).await?;
            let keys = if col != database::METADATA {
                relationship_keys(&self.read_attributes(&self.meta_key(&col)).await?)
            } else {
                Vec::new()
            };
            let mut writes = Vec::new();
            for (i, doc) in documents.iter().enumerate() {
                let Some(payload) = payload_of(payloads.get(i)) else {
                    continue;
                };
                let existing = surface_using(&keys, Self::decode(&payload)?);
                let mut merged = existing.array_copy();
                for (attribute, value) in self.apply_operators(changed.clone(), &merged)? {
                    merged.set(attribute, value);
                }
                if let Some(created_at) = &created_at {
                    merged.set(document::CREATED_AT.into(), string(created_at.as_str()));
                }
                if let Some(updated_at) = &updated_at {
                    merged.set(document::UPDATED_AT.into(), string(updated_at.as_str()));
                }
                if permissions {
                    let list = updates.permissions()?;
                    merged.set(document::PERMISSIONS.into(), crate::value::list(list.into_iter().map(string)));
                }
                writes.push(Write {
                    id: doc.id().to_owned(),
                    key: doc_keys[i].clone(),
                    payload: Some(payload),
                    document: Document::new(merged)?,
                    tenant: None,
                });
            }
            if !changed.is_empty() {
                let merged: Vec<Document> = writes.iter().map(|w| w.document.clone()).collect();
                let ids: Vec<String> = writes.iter().map(|w| w.id.clone()).collect();
                self.enforce_unique_for(&col, &merged, &ids).await?;
            }
            for write in &writes {
                let _: redis::Value =
                    self.run(redis::cmd("SET").arg(&write.key).arg(Self::encode(&write.document)?)).await?;
                self.journal(
                    "updateDoc",
                    vec![
                        ("collection", col.clone().into_bytes()),
                        ("id", write.id.clone().into_bytes()),
                        ("newId", write.id.clone().into_bytes()),
                        ("payload", write.payload.clone().unwrap_or_default()),
                        ("docKey", write.key.clone().into_bytes()),
                    ],
                );
                if permissions {
                    self.clear_permissions(&col, &write.id, None).await?;
                    self.write_permissions(&col, &write.id, &write.document).await?;
                }
            }
            Ok(writes.len() as i64)
        })
        .await
    }

    /// `upsertDocuments()`: each change's new document created, or merged
    /// onto the stored one (with `increase`, that attribute summed), after
    /// the batch's unique checks.
    pub(super) async fn upsert(
        &self,
        collection: &Collection,
        changes: Vec<Change>,
        increase: Option<&str>,
    ) -> Result<Vec<Document>> {
        if changes.is_empty() {
            return Ok(Vec::new());
        }
        let col = filter(collection.id());
        let attribute = increase.unwrap_or("");
        self.tx(async {
            let mut pipe = redis::pipe();
            for change in &changes {
                pipe.cmd("GET").arg(self.doc_key(&col, change.new.id(), change.new.tenant().as_ref()));
            }
            let payloads = self.pipe(&pipe).await?;
            let keys = if col != database::METADATA {
                relationship_keys(&self.read_attributes(&self.meta_key(&col)).await?)
            } else {
                Vec::new()
            };
            let mut writes = Vec::with_capacity(changes.len());
            for (i, change) in changes.into_iter().enumerate() {
                let document = change.new;
                let id = document.id().to_owned();
                let tenant = document.tenant();
                let key = self.doc_key(&col, &id, tenant.as_ref());
                let Some(payload) = payload_of(payloads.get(i)) else {
                    writes.push(Write { id, key, payload: None, document, tenant });
                    continue;
                };
                let existing = surface_using(&keys, Self::decode(&payload)?);
                let existing_copy = existing.array_copy();
                let resolved = self.apply_operators(document.array_copy(), &existing_copy)?;
                let mut merged = array_merge(existing_copy, resolved);
                merged.set(document::ID.into(), string(id.as_str()));
                if !attribute.is_empty() {
                    let number = |v: Option<&Value>| super::operators::numeric_or_zero(v.unwrap_or(&Value::Null));
                    let sum = php_std::format::add(number(existing.get(attribute)), number(document.get(attribute)));
                    merged.set(attribute.into(), super::operators::number_value(sum));
                }
                writes.push(Write { id, key, payload: Some(payload), document: Document::new(merged)?, tenant });
            }
            self.enforce_unique_in_order(&col, &writes).await?;
            let mut results = Vec::with_capacity(writes.len());
            for write in writes {
                let Write { id, key, payload, mut document, tenant } = write;
                if let Some(payload) = payload {
                    let _: redis::Value = self.run(redis::cmd("SET").arg(&key).arg(Self::encode(&document)?)).await?;
                    self.journal(
                        "updateDoc",
                        vec![
                            ("collection", col.clone().into_bytes()),
                            ("id", id.clone().into_bytes()),
                            ("newId", id.clone().into_bytes()),
                            ("payload", payload),
                            ("docKey", key.into_bytes()),
                        ],
                    );
                    self.clear_permissions(&col, &id, tenant.as_ref()).await?;
                    self.write_permissions(&col, &id, &document).await?;
                    results.push(document);
                    continue;
                }
                let idx_key = self.idx_key(&col, tenant.as_ref());
                let seq_key = self.seq_key(&col, tenant.as_ref());
                let sequence = self.next_sequence(&seq_key, document.sequence()).await?;
                document.set(document::SEQUENCE, string(sequence))?;
                for (attr, value) in self.apply_operators(document.array_copy(), &Array::new())? {
                    let name = match attr.as_ref() {
                        KeyRef::Int(i) => i.to_string(),
                        KeyRef::Str(s) => utf8(s),
                    };
                    document.set(name, value)?;
                }
                let _: redis::Value = self.run(redis::cmd("SET").arg(&key).arg(Self::encode(&document)?)).await?;
                let _: i64 = self.run(redis::cmd("SADD").arg(&idx_key).arg(lower(&id))).await?;
                self.write_permissions(&col, &id, &document).await?;
                self.journal(
                    "createDoc",
                    vec![
                        ("collection", col.clone().into_bytes()),
                        ("id", id.clone().into_bytes()),
                        ("docKey", key.into_bytes()),
                        ("idxKey", idx_key.into_bytes()),
                        ("permDocKey", self.perm_doc_key(&col, &id, tenant.as_ref()).into_bytes()),
                    ],
                );
                results.push(document);
            }
            Ok(results)
        })
        .await
    }

    /// `getSequences()`: the stored `$sequence` of each document lacking one.
    pub(super) async fn sequences(&self, collection: &Collection, mut documents: Vec<Document>) -> Result<Vec<Document>> {
        if documents.is_empty() {
            return Ok(documents);
        }
        let col = filter(collection.id());
        let mut pipe = redis::pipe();
        let mut positions = Vec::new();
        for (position, doc) in documents.iter().enumerate() {
            if !empty(doc.sequence()) {
                continue;
            }
            pipe.cmd("GET").arg(self.doc_key(&col, doc.id(), doc.tenant().as_ref()));
            positions.push(position);
        }
        if positions.is_empty() {
            return Ok(documents);
        }
        let payloads = self.pipe(&pipe).await.map_err(|e| {
            Error::transaction(format!("Failed to load sequences: {}", e.message())).with_previous(e)
        })?;
        for (i, position) in positions.into_iter().enumerate() {
            let Some(payload) = payload_of(payloads.get(i)) else {
                continue;
            };
            let existing = Self::decode(&payload)?;
            if let Some(sequence) = existing.sequence().filter(|s| s.truthy()) {
                documents[position].set(document::SEQUENCE, string(to_text(sequence)))?;
            }
        }
        Ok(documents)
    }

    // ----- Deletes -----

    /// `deleteDocument()`: `false` when there is nothing stored.
    pub(super) async fn remove_document(&self, collection: &Collection, id: &str) -> Result<bool> {
        let col = filter(collection.id());
        let doc_key = self.doc_key(&col, id, None);
        let idx_key = self.idx_key(&col, None);
        self.tx(async {
            let Some(payload) = self.get_payload(&doc_key).await? else {
                return Ok(false);
            };
            self.journal(
                "deleteDoc",
                vec![
                    ("collection", col.clone().into_bytes()),
                    ("id", id.as_bytes().to_vec()),
                    ("payload", payload),
                    ("docKey", doc_key.clone().into_bytes()),
                    ("idxKey", idx_key.clone().into_bytes()),
                ],
            );
            self.clear_permissions(&col, id, None).await?;
            let _: i64 = self.run(redis::cmd("DEL").arg(&doc_key)).await?;
            let _: i64 = self.run(redis::cmd("SREM").arg(&idx_key).arg(lower(id))).await?;
            Ok(true)
        })
        .await
    }

    /// `deleteDocuments()`: the stored documents with one of `sequences`,
    /// and the permissions of `permission_ids`; the count deleted.
    pub(super) async fn remove_documents(
        &self,
        collection: &Collection,
        sequences: Vec<Value>,
        permission_ids: Vec<String>,
    ) -> Result<i64> {
        if sequences.is_empty() && permission_ids.is_empty() {
            return Ok(0);
        }
        let col = filter(collection.id());
        let idx_key = self.idx_key(&col, None);
        self.tx(async {
            let wanted: HashSet<String> = sequences.iter().map(to_text).collect();
            let ids = self.members(&idx_key).await?;
            let mut pipe = redis::pipe();
            let doc_keys: Vec<String> = ids.iter().map(|id| self.doc_key(&col, id, None)).collect();
            for key in &doc_keys {
                pipe.cmd("GET").arg(key);
            }
            let payloads = if doc_keys.is_empty() { Vec::new() } else { self.pipe(&pipe).await? };
            let mut deleted: Vec<(String, Vec<u8>, String)> = Vec::new();
            for (position, _) in ids.iter().enumerate() {
                let Some(payload) = payload_of(payloads.get(position)) else {
                    continue;
                };
                let document = Self::decode(&payload)?;
                let sequence = document.sequence().map(to_text).unwrap_or_default();
                if wanted.contains(&sequence) {
                    let entry = (document.id().to_owned(), payload, doc_keys[position].clone());
                    match deleted.iter_mut().find(|(id, _, _)| *id == entry.0) {
                        Some(slot) => *slot = entry,
                        None => deleted.push(entry),
                    }
                }
            }
            for (id, payload, doc_key) in &deleted {
                self.journal(
                    "deleteDoc",
                    vec![
                        ("collection", col.clone().into_bytes()),
                        ("id", id.clone().into_bytes()),
                        ("payload", payload.clone()),
                        ("docKey", doc_key.clone().into_bytes()),
                        ("idxKey", idx_key.clone().into_bytes()),
                    ],
                );
                self.clear_permissions(&col, id, None).await?;
                let _: i64 = self.run(redis::cmd("DEL").arg(doc_key)).await?;
                let _: i64 = self.run(redis::cmd("SREM").arg(&idx_key).arg(lower(id))).await?;
            }
            for id in &permission_ids {
                if deleted.iter().any(|(d, _, _)| d == id) {
                    continue;
                }
                self.clear_permissions(&col, id, None).await?;
            }
            Ok(deleted.len() as i64)
        })
        .await
    }

    // ----- Increments -----

    /// `increaseDocumentAttribute()`: exact for integers (and integer
    /// strings) at any size; nothing changes when the stored value is below
    /// `min` or above `max`.
    #[allow(clippy::too_many_arguments)]
    pub(super) async fn increase(
        &self,
        collection: &Collection,
        id: &str,
        attribute: &str,
        value: Value,
        updated_at: &str,
        min: Option<Value>,
        max: Option<Value>,
    ) -> Result<bool> {
        let col = filter(collection.id());
        let doc_key = self.doc_key(&col, id, None);
        self.tx(async {
            let Some(payload) = self.get_payload(&doc_key).await? else {
                return Err(Error::not_found("Document not found"));
            };
            let mut document = Self::decode(&payload)?;
            let current = document.get(attribute).cloned().unwrap_or(Value::Null);
            let integer = |v: &Value| match v {
                Value::Int(_) => true,
                Value::Str(s) => BigInt::is_integer_string(s.as_bytes(), true),
                _ => false,
            };
            let result = if integer(&current) && integer(&value) {
                let current = BigInt::to_native(operand(&current)?)?;
                let value = BigInt::to_native(operand(&value)?)?;
                if let Some(min) = &min
                    && BigInt::compare(&current, operand(min)?)?.is_lt()
                {
                    return Ok(true);
                }
                if let Some(max) = &max
                    && BigInt::compare(&current, operand(max)?)?.is_gt()
                {
                    return Ok(true);
                }
                Value::from(BigInt::add(&current, &value)?)
            } else {
                let current = super::operators::number_value(super::operators::numeric_or_zero(&current));
                let value = super::operators::number_value(super::operators::numeric_or_zero(&value));
                if let Some(min) = &min
                    && super::operators::lt(&current, min)
                {
                    return Ok(true);
                }
                if let Some(max) = &max
                    && super::operators::lt(max, &current)
                {
                    return Ok(true);
                }
                super::operators::add_values(&current, &value)
            };
            document.set(attribute, result)?;
            document.set(document::UPDATED_AT, string(updated_at))?;
            let _: redis::Value = self.run(redis::cmd("SET").arg(&doc_key).arg(Self::encode(&document)?)).await?;
            self.journal(
                "updateDoc",
                vec![
                    ("collection", col.clone().into_bytes()),
                    ("id", id.as_bytes().to_vec()),
                    ("newId", id.as_bytes().to_vec()),
                    ("payload", payload),
                    ("docKey", doc_key.clone().into_bytes()),
                ],
            );
            Ok(true)
        })
        .await
    }

    // ----- Permissions -----

    /// `writePermissions()`: each role's letters in the document's
    /// permission hash, the id in each role set, the keys registered in the
    /// collection's grant set; each grant journalled.
    pub(super) async fn write_permissions(&self, collection: &str, id: &str, document: &Document) -> Result<()> {
        let id = lower(id);
        let tenant = document.tenant();
        let mut by_role: Vec<(String, Vec<&'static str>)> = Vec::new();
        for kind in [PermissionType::Create, PermissionType::Read, PermissionType::Update, PermissionType::Delete] {
            for role in document.permissions_by_type(kind.as_str())? {
                let letter = action_letter(kind);
                match by_role.iter_mut().find(|(r, _)| *r == role) {
                    Some((_, letters)) => letters.push(letter),
                    None => by_role.push((role, vec![letter])),
                }
            }
        }
        if by_role.is_empty() {
            return Ok(());
        }
        let hash_key = self.perm_doc_key(collection, &id, tenant.as_ref());
        let mut fields: Vec<(String, String)> = Vec::new();
        let mut writes: Vec<(String, String, String)> = Vec::new();
        for (role, letters) in &by_role {
            let mut unique: Vec<&str> = Vec::new();
            for letter in letters {
                if !unique.contains(letter) {
                    unique.push(letter);
                }
            }
            unique.sort_unstable();
            fields.push((role.clone(), unique.join(",")));
            for letter in unique {
                writes.push((role.clone(), letter.to_owned(), self.perm_key(collection, letter, role, tenant.as_ref())));
            }
        }
        let mut pipe = redis::pipe();
        for (_, _, set_key) in &writes {
            pipe.cmd("SADD").arg(set_key).arg(&id);
        }
        let mut hmset = redis::cmd("HMSET");
        hmset.arg(&hash_key);
        for (field, value) in &fields {
            hmset.arg(field).arg(value);
        }
        pipe.add_command(hmset);
        let mut grants = redis::cmd("SADD");
        grants.arg(Self::grants_key(&self.ns(), collection)).arg(&hash_key);
        for (_, _, set_key) in &writes {
            grants.arg(set_key);
        }
        pipe.add_command(grants);
        self.pipe(&pipe).await?;
        for (role, letter, set_key) in writes {
            self.journal(
                "createPerm",
                vec![
                    ("collection", collection.as_bytes().to_vec()),
                    ("id", id.clone().into_bytes()),
                    ("role", role.into_bytes()),
                    ("letter", letter.into_bytes()),
                    ("permKey", set_key.into_bytes()),
                    ("permDocKey", hash_key.clone().into_bytes()),
                ],
            );
        }
        Ok(())
    }

    /// `clearPermissions()`: the id removed from the role sets its hash
    /// names, the hash dropped and unregistered; each removal journalled.
    pub(super) async fn clear_permissions(&self, collection: &str, id: &str, tenant: Option<&Value>) -> Result<()> {
        let id = lower(id);
        let hash_key = self.perm_doc_key(collection, &id, tenant);
        let reply: redis::Value = self.run(redis::cmd("HGETALL").arg(&hash_key)).await?;
        let hash = reply_pairs(&reply);
        if hash.is_empty() {
            return Ok(());
        }
        let mut removals: Vec<(String, String, String)> = Vec::new();
        for (role, csv) in &hash {
            if csv.is_empty() {
                continue;
            }
            for letter in csv.split(',') {
                removals.push((role.clone(), letter.to_owned(), self.perm_key(collection, letter, role, tenant)));
            }
        }
        let mut pipe = redis::pipe();
        for (_, _, set_key) in &removals {
            pipe.cmd("SREM").arg(set_key).arg(&id);
        }
        pipe.cmd("DEL").arg(&hash_key);
        pipe.cmd("SREM").arg(Self::grants_key(&self.ns(), collection)).arg(&hash_key);
        self.pipe(&pipe).await?;
        for (role, letter, set_key) in removals {
            let previous = hash.iter().find(|(r, _)| *r == role).map(|(_, v)| v.clone()).unwrap_or_default();
            self.journal(
                "deletePerm",
                vec![
                    ("collection", collection.as_bytes().to_vec()),
                    ("id", id.clone().into_bytes()),
                    ("role", role.into_bytes()),
                    ("letter", letter.into_bytes()),
                    ("previous", previous.into_bytes()),
                    ("permKey", set_key.into_bytes()),
                    ("permDocKey", hash_key.clone().into_bytes()),
                ],
            );
        }
        Ok(())
    }

    /// `applyPermissionFilter()`: the ids (in order) the authorization's
    /// roles may act on with `action`; all of them when authorization is
    /// off, none without roles.
    pub(super) async fn permitted(&self, collection: &str, ids: Vec<String>, action: PermissionType) -> Result<Vec<String>> {
        if ids.is_empty() {
            return Ok(ids);
        }
        let authorization = self.authorization();
        if !authorization.status() {
            return Ok(ids);
        }
        let roles = authorization.roles();
        if roles.is_empty() {
            return Ok(Vec::new());
        }
        let letter = action_letter(action);
        let keys: Vec<String> = roles.iter().map(|r| self.perm_key(collection, letter, &to_text(r), None)).collect();
        let allowed: Vec<Vec<u8>> = if keys.len() == 1 {
            self.run(redis::cmd("SMEMBERS").arg(&keys[0])).await?
        } else {
            self.run(redis::cmd("SUNION").arg(&keys)).await?
        };
        if allowed.is_empty() {
            return Ok(Vec::new());
        }
        let allowed: HashSet<String> = allowed.iter().map(|a| utf8(a)).collect();
        Ok(ids.into_iter().filter(|id| allowed.contains(id)).collect())
    }

    /// `loadCollectionDocuments()`: the documents of a collection the
    /// authorization may act on with `permission` and the tenant may see,
    /// missing relationship attributes as `null`, in id set order.
    pub(super) async fn load(&self, collection: &str, permission: PermissionType) -> Result<Vec<Document>> {
        let mut ids = self.members(&self.idx_key(collection, None)).await?;
        if ids.is_empty() {
            return Ok(Vec::new());
        }
        if self.authorization().status() {
            ids = self.permitted(collection, ids, permission).await?;
            if ids.is_empty() {
                return Ok(Vec::new());
            }
        }
        let keys: Vec<String> = ids.iter().map(|id| self.doc_key(collection, id, None)).collect();
        let payloads = self.mget(&keys).await?;
        let shared = self.shared_tables();
        let tenant = if shared { self.tenant() } else { Value::Null };
        let allow_null = shared && collection == database::METADATA;
        let relationships = if collection != database::METADATA {
            relationship_keys(&self.read_attributes(&self.meta_key(&filter(collection))).await?)
        } else {
            Vec::new()
        };
        let mut documents = Vec::with_capacity(payloads.len());
        for payload in &payloads {
            let Some(payload) = reply_bytes(payload).filter(|p| !p.is_empty()) else {
                continue;
            };
            let document = Self::decode(&payload)?;
            if shared {
                let row = document.get(document::TENANT).cloned().unwrap_or(Value::Null);
                if !row.strict_eq(&tenant) && !(allow_null && matches!(row, Value::Null)) {
                    continue;
                }
            }
            documents.push(surface_using(&relationships, document));
        }
        Ok(documents)
    }
}

/// A value as an `int|float|string` argument of [`BigInt`]; PHP throws a
/// `TypeError` for anything else.
fn operand(value: &Value) -> Result<Operand<'_>> {
    Operand::from_value(value).ok_or_else(|| {
        Error::new(Kind::TypeError, format!("BigInt argument must be of type int|float|string, {} given", value.debug_type()))
    })
}

/// `surfaceRelationshipAttributesUsing()`: each of `keys` the document
/// lacks, set to `null`.
pub(super) fn surface_using(keys: &[String], mut document: Document) -> Document {
    for key in keys {
        if !document.has(key) {
            document.put(key.as_str(), Value::Null);
        }
    }
    document
}

