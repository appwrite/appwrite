//! Documents: reads, creates, updates, deletes, sequences, increments, the
//! permission indexes and the unique-index hash tables.
//!
//! PHP: `Memory::getDocument`, `createDocument(s)` (`insertDocument`),
//! `updateDocument(s)`, `getSequences`, `deleteDocument(s)`,
//! `increaseDocumentAttribute`, `documentToRow`, `rowToDocument`,
//! `getSelectAttributes`, `projectRow`, `writePermissions`,
//! `addPermissionEntry`, `removePermissionsForDocument`,
//! `permissionBucket`, `pruneDocumentPermissions`, `probeUniqueHash`,
//! `rowUniqueSignatures`, `documentUniqueSignatures`,
//! `checkUniqueSignatures`, `resolveAttributeValue`,
//! `resolveDocumentValue`, `resolveNestedPath`, `mapAttribute`,
//! `locateDocument`, `documentKey`.

use super::operators::{self, apply_operators};
use super::schema::{normalize_index_value, prepend, serialize};
use super::store::{self, array_merge, k, key_of, key_text, key_value, s};
use super::{Ctx, Store, filter};
use crate::collection::Collection;
use crate::database::METADATA;
use crate::document::{self, Document};
use crate::error::{Error, Kind, Result, UNIQUE_MESSAGE};
use crate::permission::PermissionType;
use crate::query::Query;
use crate::storage;
use crate::validator::big_int::{BigInt, Operand};
use crate::value::{Array, ArrayKey, KeyRef, Str, Value};

/// The permission types written per document, in PHP's order.
const TYPES: [PermissionType; 4] =
    [PermissionType::Create, PermissionType::Read, PermissionType::Update, PermissionType::Delete];

/// `json_encode($value)`: the JSON text, or `false` when it fails.
pub(crate) fn json_encode(value: &Value) -> Value {
    match php_std::json::encode(value, php_std::json::Flags::NONE, php_std::json::DEFAULT_DEPTH) {
        Ok(s) => Value::Str(Str::from(s)),
        Err(_) => Value::Bool(false),
    }
}

/// `json_decode($text, true)` when it decodes to an array.
pub(crate) fn json_array(text: &[u8]) -> Option<Array> {
    match php_std::json::decode(text, Some(true), php_std::json::DEFAULT_DEPTH, php_std::json::Flags::NONE) {
        Ok(php_std::Value::Array(a)) => Some(a.into_iter().map(|(k, v)| (k, crate::value::from_plain(v))).collect()),
        _ => None,
    }
}

/// A string that starts like JSON (`[` or `{`), decoded to an array.
pub(crate) fn decode_json_like(value: &Value) -> Option<Array> {
    match value {
        Value::Str(s) if s.as_bytes().first().is_some_and(|c| *c == b'[' || *c == b'{') => json_array(s.as_bytes()),
        _ => None,
    }
}

/// `strtolower($text)` (ASCII).
fn lower(text: &[u8]) -> Vec<u8> {
    text.to_ascii_lowercase()
}

/// `"{$value}"`.
fn text(v: &Value) -> String {
    crate::value::to_text(v)
}

/// `$document->getSequence()` (`?string`).
fn sequence_of(document: &Document) -> Option<Value> {
    match document.raw(document::SEQUENCE) {
        None | Some(Value::Null) => None,
        Some(v @ Value::Str(_)) => Some(v.clone()),
        Some(v) => Some(Value::Str(v.to_php_string().unwrap_or(Str::EMPTY))),
    }
}

/// `empty($document->getSequence())`.
fn has_sequence(document: &Document) -> bool {
    sequence_of(document).is_some_and(|v| v.truthy())
}

/// The value `getCreatedAt()` / `getUpdatedAt()` return (`?string`).
fn string_or_null(document: &Document, key: &str) -> Value {
    match document.raw(key) {
        None | Some(Value::Null) => Value::Null,
        Some(v @ Value::Str(_)) => v.clone(),
        Some(v) => Value::Str(v.to_php_string().unwrap_or(Str::EMPTY)),
    }
}

/// `$document->getTenant() ?? $this->getTenant()`.
fn tenant_of(ctx: &Ctx, document: &Document) -> Value {
    document.tenant().unwrap_or_else(|| ctx.tenant.clone())
}

/// `is_scalar($value) ? (string) $value : ''`.
fn scalar_text(value: Option<&Value>) -> Value {
    match value {
        Some(v @ (Value::Bool(_) | Value::Int(_) | Value::Float(_) | Value::Str(_))) => {
            Value::Str(v.to_php_string().unwrap_or(Str::EMPTY))
        }
        _ => s(""),
    }
}

/// `permissionBucket($tenant)`: `'__null__'` for `null`, the tenant as text
/// otherwise.
fn bucket_of(tenant: &Value) -> ArrayKey {
    if matches!(tenant, Value::Null) {
        k("__null__")
    } else {
        key_of(&Value::Str(tenant.to_php_string().unwrap_or(Str::EMPTY)))
    }
}

/// `mapAttribute($attribute)`: the column of an attribute, filtered.
pub(crate) fn map_attribute(attribute: &str) -> String {
    filter(storage::column(attribute))
}

/// `resolveNestedPath($value, $path)`.
pub(crate) fn resolve_nested_path(mut value: Value, path: &str) -> Value {
    for part in path.split('.') {
        match value {
            Value::Array(a) => match a.get(&k(part)) {
                Some(v) => value = v.clone(),
                None => return Value::Null,
            },
            _ => return Value::Null,
        }
    }
    value
}

/// The string values of the `select` queries (`getSelectAttributes()`,
/// `extractSelections()`).
pub(crate) fn selections(queries: &[Query]) -> Vec<Vec<u8>> {
    queries
        .iter()
        .filter(|q| q.method() == utopia_query::Method::Select)
        .flat_map(|q| {
            q.values()
                .values()
                .filter_map(|v| match v {
                    utopia_query::Value::Str(s) => Some(s.as_bytes().to_vec()),
                    _ => None,
                })
                .collect::<Vec<_>>()
        })
        .collect()
}

/// `projectRow($row, $selections)`: the selected attributes and every
/// internal one (`$...`, `_...`).
fn project_row(row: Array, selections: &[Vec<u8>]) -> Array {
    if selections.iter().any(|v| v == b"*") {
        return row;
    }
    let mut projected = Array::new();
    for (field, value) in row.iter() {
        let name = key_text(field);
        if name.starts_with('$') || name.starts_with('_') {
            projected.set(field.to_owned(), value.clone());
            continue;
        }
        // `in_array($field, $selections, true)`: an integer key never equals a string.
        if let KeyRef::Str(f) = field
            && selections.iter().any(|v| v.as_slice() == f)
        {
            projected.set(field.to_owned(), value.clone());
        }
    }
    projected
}

impl Store {
    /// `documentKey($id, $tenant)` as the string PHP holds: the lower-cased
    /// id, prefixed with the tenant under shared tables.
    pub(crate) fn document_key(ctx: &Ctx, id: &str, tenant: Option<&Value>) -> Value {
        let id = lower(id.as_bytes());
        if !ctx.shared {
            return Value::Str(Str::from(id));
        }
        let tenant = match tenant {
            Some(t) if !matches!(t, Value::Null) => t.clone(),
            _ => ctx.tenant.clone(),
        };
        let mut key = text(&tenant).into_bytes();
        key.push(b'|');
        key.extend_from_slice(&id);
        Value::Str(Str::from(key))
    }

    /// The row stored under a document key.
    fn row(&self, key: &ArrayKey, doc_key: &Value) -> Option<&Array> {
        match store::get(&self.data, &[key.clone(), k("documents"), key_of(doc_key)]) {
            Some(Value::Array(row)) => Some(row),
            _ => None,
        }
    }

    /// `locateDocument($key, $collectionId, $id)`: the storage key and row,
    /// falling back to a `null`-tenant copy for the metadata collection
    /// under shared tables.
    pub(crate) fn locate_document(
        &self,
        ctx: &Ctx,
        key: &ArrayKey,
        collection: &str,
        id: &str,
    ) -> Option<(Value, Array)> {
        let primary = Self::document_key(ctx, id, None);
        if let Some(row) = self.row(key, &primary) {
            return Some((primary, row.clone()));
        }
        if ctx.shared && collection == METADATA {
            let wanted = lower(id.as_bytes());
            if let Some(documents) = store::get_array(&self.data, &[key.clone(), k("documents")]) {
                for (storage_key, candidate) in documents.iter() {
                    let Value::Array(candidate) = candidate else { continue };
                    let Some(Value::Str(uid)) = candidate.get_str(storage::UID) else { continue };
                    let tenant = candidate.get_str(storage::TENANT).cloned().unwrap_or(Value::Null);
                    if lower(uid.as_bytes()) == wanted && matches!(tenant, Value::Null) {
                        return Some((key_value(storage_key), candidate.clone()));
                    }
                }
            }
        }
        None
    }

    /// `resolveAttributeValue($row, $attribute)`: the column, or a dotted
    /// path into a JSON value.
    pub(crate) fn resolve_attribute_value(row: &Array, attribute: &str) -> Value {
        if let Some(v) = row.get(&k(&map_attribute(attribute))) {
            return v.clone();
        }
        let Some((head, rest)) = attribute.split_once('.') else {
            return Value::Null;
        };
        let mut value = row.get(&k(&map_attribute(head))).cloned().unwrap_or(Value::Null);
        if let Some(decoded) = decode_json_like(&value) {
            value = Value::Array(decoded);
        }
        resolve_nested_path(value, rest)
    }

    /// `resolveDocumentValue($document, $attribute)`.
    fn resolve_document_value(document: &Document, attribute: &str) -> Value {
        let Some((head, rest)) = attribute.split_once('.') else {
            return match document.get(attribute) {
                Some(v) => v.clone(),
                None => document.get(&map_attribute(attribute)).cloned().unwrap_or(Value::Null),
            };
        };
        let mut value = document.get(head).cloned().unwrap_or(Value::Null);
        if let Some(decoded) = decode_json_like(&value) {
            value = Value::Array(decoded);
        }
        resolve_nested_path(value, rest)
    }

    /// `getDocument($collection, $id, $queries)`.
    pub(crate) fn get_document(
        &self,
        ctx: &Ctx,
        collection: &Collection,
        id: &str,
        queries: &[Query],
    ) -> Result<Document> {
        let key = Self::key(ctx, collection.id());
        if !self.has(&key) {
            return Ok(Document::empty());
        }
        let Some((_, row)) = self.locate_document(ctx, &key, collection.id(), id) else {
            return Ok(Document::empty());
        };
        let mut row = self.row_to_document(&row, None, Some(&key));
        let selections = selections(queries);
        if !selections.is_empty() {
            row = project_row(row, &selections);
        }
        Document::from_storage(row)
    }

    /// `rowToDocument($row, $selections, $storageKey)`: internal columns to
    /// their `$` attributes, the selection applied, and relationship fields
    /// the row lacks as `null`.
    pub(crate) fn row_to_document(
        &self,
        row: &Array,
        selections: Option<&[Vec<u8>]>,
        storage: Option<&ArrayKey>,
    ) -> Array {
        let allowed: Option<Array> = match selections {
            Some(sel) if !sel.is_empty() && !sel.iter().any(|v| v == b"*") => {
                Some(sel.iter().map(|v| (k(&filter(&String::from_utf8_lossy(v))), Value::Bool(true))).collect())
            }
            _ => None,
        };
        let mut document = Array::new();
        for (key, value) in row.iter() {
            let name = match key {
                KeyRef::Str(s) => Some(s),
                KeyRef::Int(_) => None,
            };
            match name.and_then(|n| std::str::from_utf8(n).ok()) {
                Some(storage::SEQUENCE) => {
                    document.set(k(document::SEQUENCE), scalar_text(Some(value)));
                }
                Some(storage::UID) => {
                    document.set(k(document::ID), value.clone());
                }
                Some(storage::TENANT) => {
                    document.set(k(document::TENANT), value.clone());
                }
                Some(storage::CREATED_AT) => {
                    document.set(k(document::CREATED_AT), value.clone());
                }
                Some(storage::UPDATED_AT) => {
                    document.set(k(document::UPDATED_AT), value.clone());
                }
                Some(storage::PERMISSIONS) => {
                    let v = if matches!(value, Value::Null) { Value::Array(Array::new()) } else { value.clone() };
                    document.set(k(document::PERMISSIONS), v);
                }
                _ => {
                    if let Some(allowed) = &allowed
                        && !store::isset(allowed, &[key.to_owned()])
                    {
                        continue;
                    }
                    document.set(key.to_owned(), value.clone());
                }
            }
        }
        if let Some(storage) = storage
            && let Some(attributes) = store::get_array(&self.data, &[storage.clone(), k("attributes")])
        {
            let relationship = s(utopia_query::schema::ColumnType::Relationship.as_str());
            for (id, definition) in attributes.iter() {
                let is_relationship = match definition {
                    Value::Array(d) => d.get_str("type").is_some_and(|t| t.strict_eq(&relationship)),
                    _ => false,
                };
                if !is_relationship {
                    continue;
                }
                if let Some(allowed) = &allowed
                    && !store::isset(allowed, &[id.to_owned()])
                {
                    continue;
                }
                if !document.contains_key(&id.to_owned()) {
                    document.set(id.to_owned(), Value::Null);
                }
            }
        }
        document
    }

    /// `documentToRow($document)`.
    fn document_to_row(ctx: &Ctx, document: &Document) -> Result<Array> {
        let mut row = Array::new();
        for (key, value) in document.attributes().iter() {
            row.set(k(&filter(&key_text(key))), value.clone());
        }
        row.set(k(storage::UID), s(document.id()));
        row.set(k(storage::CREATED_AT), string_or_null(document, document::CREATED_AT));
        row.set(k(storage::UPDATED_AT), string_or_null(document, document::UPDATED_AT));
        let permissions = document.permissions()?;
        row.set(k(storage::PERMISSIONS), Value::Array(permissions.iter().map(|p| s(p)).collect()));
        if ctx.shared {
            row.set(k(storage::TENANT), tenant_of(ctx, document));
        }
        Ok(row)
    }

    /// `createDocument($collection, $document)`: the stored document, or
    /// the document as given when `ignoreDuplicates()` skipped it.
    pub(crate) fn create_document(
        &mut self,
        ctx: &Ctx,
        collection: &Collection,
        document: Document,
    ) -> Result<Document> {
        Ok(self.insert_document(ctx, collection, document)?.1)
    }

    /// `insertDocument($collection, $document)`: `(true, stored)` when
    /// stored, `(false, document)` when `ignoreDuplicates()` skipped it (with
    /// the stored row's sequence when the id was taken).
    pub(crate) fn insert_document(
        &mut self,
        ctx: &Ctx,
        collection: &Collection,
        mut document: Document,
    ) -> Result<(bool, Document)> {
        let key = Self::key(ctx, collection.id());
        if !self.has(&key) {
            return Err(Error::not_found("Collection not found"));
        }
        let doc_key = Self::document_key(ctx, document.id(), document.tenant().as_ref());
        if let Some(existing) = self.row(&key, &doc_key) {
            if ctx.ignoring_duplicates {
                let sequence = scalar_text(existing.get_str(storage::SEQUENCE));
                document.put(document::SEQUENCE, sequence);
                return Ok((false, document));
            }
            return Err(Error::duplicate("Document already exists"));
        }
        let signatures = self.document_unique_signatures(ctx, &key, &document);
        if let Err(e) = self.check_unique_signatures(&key, &signatures, &doc_key) {
            if ctx.ignoring_duplicates && e.is_a(Kind::Duplicate) {
                return Ok((false, document));
            }
            return Err(e);
        }
        let sequence_path = [key.clone(), k("sequence")];
        let before = store::get(&self.data, &sequence_path).cloned().unwrap_or(Value::Null);
        let sequence = if has_sequence(&document) {
            let v = operators::to_long(&sequence_of(&document).unwrap_or(Value::Null));
            if v > operators::to_long(&before) {
                store::set(&mut self.data, &sequence_path, Value::Int(v));
            }
            v
        } else {
            let next = operators::add(&before, &Value::Int(1))?;
            store::set(&mut self.data, &sequence_path, next.clone());
            operators::to_long(&next)
        };
        let mut row = Self::document_to_row(ctx, &document)?;
        row.set(k(storage::SEQUENCE), Value::Int(sequence));
        let doc_path = [key.clone(), k("documents"), key_of(&doc_key)];
        store::set(&mut self.data, &doc_path, Value::Array(row));
        self.journal(ctx, move |s: &mut Store| {
            store::unset(&mut s.data, &doc_path);
            store::set(&mut s.data, &sequence_path, before);
        });
        for (index_id, hash) in signatures {
            self.probe_unique_hash(ctx, &key, &index_id, Some(&hash), None, &doc_key)?;
        }
        self.write_permissions(ctx, &key, &document)?;
        document.put(document::SEQUENCE, Value::Str(Str::from(sequence.to_string())));
        Ok((true, document))
    }

    /// `createDocuments($collection, $documents)`: the documents stored (all
    /// set a sequence, or none does).
    pub(crate) fn create_documents(
        &mut self,
        ctx: &Ctx,
        collection: &Collection,
        documents: Vec<Document>,
    ) -> Result<Vec<Document>> {
        let mut first: Option<bool> = None;
        for document in &documents {
            let set = has_sequence(document);
            match first {
                None => first = Some(set),
                Some(f) if f != set => {
                    return Err(Error::database("All documents must have an sequence if one is set"));
                }
                Some(_) => {}
            }
        }
        let mut created = Vec::new();
        for document in documents {
            if let (true, stored) = self.insert_document(ctx, collection, document)? {
                created.push(stored);
            }
        }
        Ok(created)
    }

    /// `updateDocument($collection, $id, $document, $skipPermissions)`.
    pub(crate) fn update_document(
        &mut self,
        ctx: &Ctx,
        collection: &Collection,
        id: &str,
        mut document: Document,
        skip_permissions: bool,
    ) -> Result<Document> {
        let key = Self::key(ctx, collection.id());
        if !self.has(&key) {
            return Err(Error::not_found("Collection not found"));
        }
        let Some((old_key, existing)) = self.locate_document(ctx, &key, collection.id(), id) else {
            return Err(Error::not_found("Document not found"));
        };
        let resolved = apply_operators(&document.attributes(), &existing)?;
        for (attribute, value) in resolved.iter() {
            document.set(Str::from(key_text(attribute)), value.clone())?;
        }
        let new_id = document.id().to_owned();
        let candidate = Self::document_key(ctx, &new_id, None);
        if !candidate.strict_eq(&old_key) && self.row(&key, &candidate).is_some() {
            return Err(Error::duplicate("Document already exists"));
        }
        let update = Self::document_to_row(ctx, &document)?;
        let mut row = array_merge(&[&existing, &update]);
        let new_signatures = self.row_unique_signatures(ctx, &key, &row);
        let old_signatures = self.row_unique_signatures(ctx, &key, &existing);
        self.check_unique_signatures(&key, &new_signatures, &old_key)?;
        row.set(k(storage::SEQUENCE), existing.get_str(storage::SEQUENCE).cloned().unwrap_or(Value::Null));
        if ctx.shared
            && let Some(t) = existing.get_str(storage::TENANT)
        {
            row.set(k(storage::TENANT), t.clone());
        }
        let new_key = if ctx.shared {
            let tenant = existing
                .get_str(storage::TENANT)
                .filter(|v| !matches!(v, Value::Null))
                .cloned()
                .unwrap_or_else(|| ctx.tenant.clone());
            let mut key = match scalar_text(Some(&tenant)) {
                Value::Str(t) => t.as_bytes().to_vec(),
                _ => Vec::new(),
            };
            key.push(b'|');
            key.extend_from_slice(&lower(new_id.as_bytes()));
            Value::Str(Str::from(key))
        } else {
            Value::Str(Str::from(lower(new_id.as_bytes())))
        };
        let documents = [key.clone(), k("documents")];
        let (old_array_key, new_array_key) = (key_of(&old_key), key_of(&new_key));
        let old_had_row = store::isset(&self.data, &[key.clone(), k("documents"), old_array_key.clone()]);
        let previous_at_new = store::get(&self.data, &[key.clone(), k("documents"), new_array_key.clone()])
            .cloned()
            .filter(|v| !matches!(v, Value::Null));
        let same_key = old_key.strict_eq(&new_key);
        if new_id != id || !same_key {
            store::unset(&mut self.data, &[key.clone(), k("documents"), old_array_key.clone()]);
        }
        store::vivify(&mut self.data, &documents).set(new_array_key.clone(), Value::Array(row));
        {
            let (existing, old_array_key, new_array_key) =
                (existing.clone(), old_array_key.clone(), new_array_key.clone());
            let collection_key = key.clone();
            self.journal(ctx, move |s: &mut Store| {
                if !s.has(&collection_key) {
                    return;
                }
                let table = store::vivify(&mut s.data, &documents);
                if !same_key {
                    match previous_at_new {
                        None => {
                            table.remove(&new_array_key);
                        }
                        Some(p) => {
                            table.set(new_array_key, p);
                        }
                    }
                    if old_had_row {
                        table.set(old_array_key, Value::Array(existing));
                    }
                } else {
                    table.set(old_array_key, Value::Array(existing));
                }
            });
        }
        let mut all: Vec<ArrayKey> = old_signatures.iter().map(|(i, _)| i.clone()).collect();
        for (index_id, _) in &new_signatures {
            if !all.contains(index_id) {
                all.push(index_id.clone());
            }
        }
        let find =
            |list: &[(ArrayKey, ArrayKey)], id: &ArrayKey| list.iter().find(|(i, _)| i == id).map(|(_, h)| h.clone());
        for index_id in all {
            let old_hash = find(&old_signatures, &index_id);
            let new_hash = find(&new_signatures, &index_id);
            if !same_key && let Some(old_hash) = &old_hash {
                let path = [key.clone(), index_id.clone(), old_hash.clone()];
                if store::get(&self.hashes, &path).is_some_and(|v| v.strict_eq(&old_key)) {
                    store::unset(&mut self.hashes, &path);
                    let old_key = old_key.clone();
                    self.journal(ctx, move |s: &mut Store| store::set(&mut s.hashes, &path, old_key));
                }
            }
            self.probe_unique_hash(ctx, &key, &index_id, new_hash.as_ref(), old_hash.as_ref(), &new_key)?;
        }
        if !skip_permissions {
            self.remove_permissions_for_document(ctx, &key, id, &ctx.tenant.clone(), ctx.shared);
            if new_id != id {
                self.remove_permissions_for_document(ctx, &key, &new_id, &ctx.tenant.clone(), ctx.shared);
            }
            self.write_permissions(ctx, &key, &document)?;
        } else if new_id != id {
            let entries: Vec<Array> = match self.permissions.get(&key) {
                Some(Value::Array(list)) => list
                    .values()
                    .filter_map(|e| match e {
                        Value::Array(e) if e.get_str("document").is_some_and(|d| d.strict_eq(&s(id))) => {
                            Some(e.clone())
                        }
                        _ => None,
                    })
                    .collect(),
                _ => Vec::new(),
            };
            self.remove_permissions_for_document(ctx, &key, id, &Value::Null, false);
            for entry in entries {
                let field = |name: &str| entry.get_str(name).cloned().unwrap_or(Value::Null);
                self.add_permission_entry(
                    ctx,
                    &key,
                    &new_id,
                    &text(&field("type")),
                    &text(&field("permission")),
                    &field("tenant"),
                );
            }
        }
        Ok(document)
    }

    /// `updateDocuments($collection, $updates, $documents)`: every unique
    /// index checked before any row is written.
    pub(crate) fn update_documents(
        &mut self,
        ctx: &Ctx,
        collection: &Collection,
        updates: &Document,
        documents: &[Document],
    ) -> Result<i64> {
        if documents.is_empty() {
            return Ok(0);
        }
        let key = Self::key(ctx, collection.id());
        if !self.has(&key) {
            return Err(Error::not_found("Collection not found"));
        }
        let attributes = updates.attributes();
        let created_at = updates.created_at().filter(|v| !v.is_empty() && *v != "0").map(str::to_owned);
        let updated_at = updates.updated_at().filter(|v| !v.is_empty() && *v != "0").map(str::to_owned);
        let has_permissions = updates.has(document::PERMISSIONS);
        if attributes.is_empty() && created_at.is_none() && updated_at.is_none() && !has_permissions {
            return Ok(0);
        }
        struct Prepared {
            uid: String,
            doc_key: Value,
            attrs: Array,
            new: Vec<(ArrayKey, ArrayKey)>,
            old: Vec<(ArrayKey, ArrayKey)>,
        }
        let mut prepared: Vec<Prepared> = Vec::new();
        for doc in documents {
            let uid = doc.id().to_owned();
            let doc_key = Self::document_key(ctx, &uid, None);
            let Some(existing) = self.row(&key, &doc_key).cloned() else { continue };
            let resolved = apply_operators(&attributes, &existing)?;
            let new = if resolved.is_empty() {
                Vec::new()
            } else {
                let mut id = Array::new();
                id.set(k(document::ID), s(&uid));
                let merged = Document::from_storage(array_merge(&[
                    &self.row_to_document(&existing, None, None),
                    &resolved,
                    &id,
                ]))?;
                self.document_unique_signatures(ctx, &key, &merged)
            };
            let old = self.row_unique_signatures(ctx, &key, &existing);
            prepared.push(Prepared { uid, doc_key, attrs: resolved, new, old });
        }
        let mut pending: Vec<(ArrayKey, ArrayKey, Value)> = Vec::new();
        for entry in &prepared {
            for (index_id, hash) in &entry.new {
                if let Some(existing) = store::get(&self.hashes, &[key.clone(), index_id.clone(), hash.clone()])
                    .filter(|v| !matches!(v, Value::Null))
                    && !existing.strict_eq(&entry.doc_key)
                {
                    let is_self = prepared.iter().any(|other| {
                        other.doc_key.strict_eq(existing) && other.old.iter().any(|(i, h)| i == index_id && h == hash)
                    });
                    if !is_self {
                        return Err(Error::unique(UNIQUE_MESSAGE));
                    }
                }
                match pending.iter_mut().find(|(i, h, _)| i == index_id && h == hash) {
                    Some((_, _, bound)) => {
                        if !bound.strict_eq(&entry.doc_key) {
                            return Err(Error::unique(UNIQUE_MESSAGE));
                        }
                        *bound = entry.doc_key.clone();
                    }
                    None => pending.push((index_id.clone(), hash.clone(), entry.doc_key.clone())),
                }
            }
        }
        let tenant = ctx.tenant.clone();
        let count = prepared.len() as i64;
        for entry in prepared {
            let path = [key.clone(), k("documents"), key_of(&entry.doc_key)];
            let previous = store::get(&self.data, &path).cloned().unwrap_or(Value::Null);
            let row = store::vivify(&mut self.data, &path);
            for (attribute, value) in entry.attrs.iter() {
                row.set(k(&filter(&key_text(attribute))), value.clone());
            }
            if let Some(c) = &created_at {
                row.set(k(storage::CREATED_AT), s(c));
            }
            if let Some(u) = &updated_at {
                row.set(k(storage::UPDATED_AT), s(u));
            }
            if has_permissions {
                let permissions = updates.permissions()?;
                row.set(k(storage::PERMISSIONS), Value::Array(permissions.iter().map(|p| s(p)).collect()));
            }
            {
                let path = path.clone();
                self.journal(ctx, move |s: &mut Store| store::set(&mut s.data, &path, previous));
            }
            if has_permissions {
                self.remove_permissions_for_document(ctx, &key, &entry.uid, &tenant, ctx.shared);
                for kind in TYPES {
                    for role in updates.permissions_by_type(kind.as_str())? {
                        self.add_permission_entry(ctx, &key, &entry.uid, kind.as_str(), &role, &tenant);
                    }
                }
            }
            let mut all: Vec<ArrayKey> = entry.old.iter().map(|(i, _)| i.clone()).collect();
            for (index_id, _) in &entry.new {
                if !all.contains(index_id) {
                    all.push(index_id.clone());
                }
            }
            for index_id in all {
                let new_hash = entry.new.iter().find(|(i, _)| *i == index_id).map(|(_, h)| h.clone());
                let old_hash = entry.old.iter().find(|(i, _)| *i == index_id).map(|(_, h)| h.clone());
                self.probe_unique_hash(ctx, &key, &index_id, new_hash.as_ref(), old_hash.as_ref(), &entry.doc_key)?;
            }
        }
        Ok(count)
    }

    /// `getSequences($collection, $documents)`: each document without a
    /// sequence given the stored one (looked up under its own tenant).
    pub(crate) fn get_sequences(
        &self,
        ctx: &Ctx,
        collection: &Collection,
        mut documents: Vec<Document>,
    ) -> Vec<Document> {
        let key = Self::key(ctx, collection.id());
        if !self.has(&key) {
            return documents;
        }
        for document in &mut documents {
            if has_sequence(document) {
                continue;
            }
            let doc_key = Self::document_key(ctx, document.id(), document.tenant().as_ref());
            if let Some(existing) = self.row(&key, &doc_key) {
                let sequence = scalar_text(existing.get_str(storage::SEQUENCE));
                document.put(document::SEQUENCE, sequence);
            }
        }
        documents
    }

    /// `deleteDocument($collection, $id)`.
    pub(crate) fn delete_document(&mut self, ctx: &Ctx, collection: &Collection, id: &str) -> Result<bool> {
        let key = Self::key(ctx, collection.id());
        if !self.has(&key) {
            return Err(Error::not_found("Collection not found"));
        }
        let doc_key = Self::document_key(ctx, id, None);
        let Some(existing) = self.row(&key, &doc_key).cloned() else {
            return Ok(false);
        };
        let old_signatures = self.row_unique_signatures(ctx, &key, &existing);
        let path = [key.clone(), k("documents"), key_of(&doc_key)];
        store::unset(&mut self.data, &path);
        {
            let existing = existing.clone();
            self.journal(ctx, move |s: &mut Store| store::set(&mut s.data, &path, Value::Array(existing)));
        }
        self.release_hashes(ctx, &key, &old_signatures, &doc_key);
        let stored = match existing.get_str(storage::UID) {
            Some(Value::Str(s)) => String::from_utf8_lossy(s.as_bytes()).into_owned(),
            Some(Value::Null) | None => id.to_owned(),
            Some(_) => id.to_owned(),
        };
        self.remove_permissions_for_document(ctx, &key, &stored, &ctx.tenant.clone(), ctx.shared);
        Ok(true)
    }

    /// Drops the hash bindings a deleted row held.
    fn release_hashes(&mut self, ctx: &Ctx, key: &ArrayKey, signatures: &[(ArrayKey, ArrayKey)], doc_key: &Value) {
        for (index_id, hash) in signatures {
            let path = [key.clone(), index_id.clone(), hash.clone()];
            if store::get(&self.hashes, &path).is_some_and(|v| v.strict_eq(doc_key)) {
                store::unset(&mut self.hashes, &path);
                let doc_key = doc_key.clone();
                self.journal(ctx, move |s: &mut Store| store::set(&mut s.hashes, &path, doc_key));
            }
        }
    }

    /// `deleteDocuments($collection, $sequences, $permissionIds)`: the rows
    /// of the current tenant with those sequences that match every one of
    /// `filters` (`deletionFilters()`), and the permissions of the deleted
    /// documents and of `permission_ids` but a kept row's.
    pub(crate) fn delete_documents(
        &mut self,
        ctx: &Ctx,
        collection: &Collection,
        sequences: &[Value],
        permission_ids: &[String],
        filters: &[Query],
    ) -> Result<i64> {
        let key = Self::key(ctx, collection.id());
        if !self.has(&key) {
            return Err(Error::not_found("Collection not found"));
        }
        let mut wanted = Array::new();
        for sequence in sequences {
            wanted.set(key_of(&Value::Str(sequence.to_php_string().map_err(Error::from)?)), Value::Bool(true));
        }
        let rows: Vec<(Value, Array)> = match store::get_array(&self.data, &[key.clone(), k("documents")]) {
            Some(documents) => documents
                .iter()
                .filter_map(|(doc_key, row)| match row {
                    Value::Array(row) => Some((key_value(doc_key), row.clone())),
                    _ => None,
                })
                .collect(),
            None => Vec::new(),
        };
        let mut count = 0;
        let mut deleted = Array::new();
        let mut kept = Array::new();
        for (doc_key, row) in rows {
            if ctx.shared && !row.get_str(storage::TENANT).cloned().unwrap_or(Value::Null).strict_eq(&ctx.tenant) {
                continue;
            }
            let sequence = scalar_text(row.get_str(storage::SEQUENCE));
            if !store::isset(&wanted, &[key_of(&sequence)]) {
                continue;
            }
            let uid = match row.get_str(storage::UID) {
                Some(v @ (Value::Bool(_) | Value::Int(_) | Value::Float(_) | Value::Str(_))) => {
                    Value::Str(v.to_php_string().unwrap_or(Str::EMPTY))
                }
                _ => doc_key.clone(),
            };
            if !super::query::matches_all(&row, filters)? {
                kept.set(key_of(&uid), Value::Bool(true));
                continue;
            }
            deleted.set(key_of(&uid), Value::Bool(true));
            let old_signatures = self.row_unique_signatures(ctx, &key, &row);
            let path = [key.clone(), k("documents"), key_of(&doc_key)];
            store::unset(&mut self.data, &path);
            self.journal(ctx, move |s: &mut Store| store::set(&mut s.data, &path, Value::Array(row)));
            self.release_hashes(ctx, &key, &old_signatures, &doc_key);
            count += 1;
        }
        let mut permissions = Array::new();
        for id in permission_ids {
            permissions.set(k(id), Value::Bool(true));
        }
        if !deleted.is_empty() || !permissions.is_empty() {
            let tenant = ctx.tenant.clone();
            for id in deleted.keys().map(key_text).collect::<Vec<_>>() {
                self.remove_permissions_for_document(ctx, &key, &id, &tenant, ctx.shared);
            }
            for id in permissions.keys().map(|k| k.to_owned()).collect::<Vec<_>>() {
                if deleted.contains_key(&id) || kept.contains_key(&id) {
                    continue;
                }
                self.remove_permissions_for_document(ctx, &key, &key_text(id.as_ref()), &tenant, ctx.shared);
            }
        }
        Ok(count)
    }

    /// `increaseDocumentAttribute(...)`: a silent no-op when the stored
    /// value already crosses a bound; exact for integers beyond PHP's range.
    #[allow(clippy::too_many_arguments)]
    pub(crate) fn increase(
        &mut self,
        ctx: &Ctx,
        collection: &Collection,
        id: &str,
        attribute: &str,
        value: &Value,
        updated_at: &str,
        min: Option<&Value>,
        max: Option<&Value>,
    ) -> Result<bool> {
        let key = Self::key(ctx, collection.id());
        let doc_key = Self::document_key(ctx, id, None);
        let doc_path = [key.clone(), k("documents"), key_of(&doc_key)];
        if !store::isset(&self.data, &doc_path) {
            return Err(Error::not_found("Document not found"));
        }
        let column = k(&filter(attribute));
        let row = store::get_array(&self.data, &doc_path).cloned().unwrap_or_default();
        let previous = row.get(&column).cloned().filter(|v| !matches!(v, Value::Null));
        let previous_updated_at = row.get_str(storage::UPDATED_AT).cloned().filter(|v| !matches!(v, Value::Null));
        let current = previous.clone().unwrap_or(Value::Int(0));
        let min = min.filter(|v| !matches!(v, Value::Null));
        let max = max.filter(|v| !matches!(v, Value::Null));
        fn operand(v: &Value) -> Result<Operand<'_>> {
            Operand::from_value(v).ok_or_else(|| Error::new(Kind::InvalidArgument, "Value must be an integer."))
        }
        let result = if BigInt::is_integer_value(&current) && BigInt::is_integer_value(value) {
            let current = BigInt::to_native(operand(&current)?)?;
            let value = BigInt::to_native(operand(value)?)?;
            if let Some(min) = min
                && BigInt::compare(&current, operand(min)?)?.is_lt()
            {
                return Ok(true);
            }
            if let Some(max) = max
                && BigInt::compare(&current, operand(max)?)?.is_gt()
            {
                return Ok(true);
            }
            Value::from(BigInt::add(&current, &value)?)
        } else {
            let numeric = |v: &Value| match v {
                Value::Int(_) | Value::Float(_) => v.clone(),
                Value::Str(_) => operators::numeric(v).unwrap_or(Value::Int(0)),
                _ => Value::Int(0),
            };
            let (current, value) = (numeric(&current), numeric(value));
            if let Some(min) = min
                && operators::lt(&current, min)
            {
                return Ok(true);
            }
            if let Some(max) = max
                && operators::gt(&current, max)
            {
                return Ok(true);
            }
            operators::add(&current, &value)?
        };
        let target = store::vivify(&mut self.data, &doc_path);
        target.set(column.clone(), result);
        target.set(k(storage::UPDATED_AT), s(updated_at));
        let collection_key = key.clone();
        self.journal(ctx, move |s: &mut Store| {
            if !store::isset(&s.data, &doc_path) {
                return;
            }
            let _ = &collection_key;
            let row = store::vivify(&mut s.data, &doc_path);
            match previous {
                None => {
                    row.remove(&column);
                }
                Some(p) => {
                    row.set(column, p);
                }
            }
            match previous_updated_at {
                None => {
                    row.remove(&k(storage::UPDATED_AT));
                }
                Some(p) => {
                    row.set(k(storage::UPDATED_AT), p);
                }
            }
        });
        Ok(true)
    }

    /// `writePermissions($key, $document)`.
    fn write_permissions(&mut self, ctx: &Ctx, key: &ArrayKey, document: &Document) -> Result<()> {
        let uid = document.id().to_owned();
        let tenant = tenant_of(ctx, document);
        for kind in TYPES {
            for role in document.permissions_by_type(kind.as_str())? {
                self.add_permission_entry(ctx, key, &uid, kind.as_str(), &role, &tenant);
            }
        }
        Ok(())
    }

    /// `addPermissionEntry($key, $document, $type, $permission, $tenant)`.
    fn add_permission_entry(
        &mut self,
        ctx: &Ctx,
        key: &ArrayKey,
        document: &str,
        kind: &str,
        permission: &str,
        tenant: &Value,
    ) {
        let clean = permission.replace('"', "");
        let mut entry = Array::new();
        entry.set(k("document"), s(document));
        entry.set(k("type"), s(kind));
        entry.set(k("permission"), s(&clean));
        entry.set(k("tenant"), tenant.clone());
        let flat = store::push(&mut self.permissions, std::slice::from_ref(key), Value::Array(entry));
        let (doc, ty, perm) = (k(document), k(kind), k(&clean));
        let bucket = bucket_of(tenant);
        store::set(
            &mut self.by_document,
            &[key.clone(), bucket.clone(), doc.clone(), ty.clone(), perm.clone()],
            Value::Bool(true),
        );
        store::set(
            &mut self.by_permission,
            &[key.clone(), ty.clone(), bucket.clone(), perm.clone(), doc.clone()],
            Value::Bool(true),
        );
        let key = key.clone();
        self.journal(ctx, move |s: &mut Store| {
            store::unset(&mut s.permissions, &[key.clone(), ArrayKey::Int(flat)]);
            store::unset(&mut s.by_document, &[key.clone(), bucket.clone(), doc.clone(), ty.clone(), perm.clone()]);
            s.prune_document_permissions(&key, &bucket, &doc, &ty);
            s.prune_permission(&key, &ty, &bucket, &perm, &doc);
        });
    }

    /// Unsets a document from the by-permission index, pruning what empties.
    fn prune_permission(&mut self, key: &ArrayKey, ty: &ArrayKey, bucket: &ArrayKey, perm: &ArrayKey, doc: &ArrayKey) {
        store::unset(&mut self.by_permission, &[key.clone(), ty.clone(), bucket.clone(), perm.clone(), doc.clone()]);
        if store::empty(store::get(&self.by_permission, &[key.clone(), ty.clone(), bucket.clone(), perm.clone()])) {
            store::unset(&mut self.by_permission, &[key.clone(), ty.clone(), bucket.clone(), perm.clone()]);
            if store::empty(store::get(&self.by_permission, &[key.clone(), ty.clone(), bucket.clone()])) {
                store::unset(&mut self.by_permission, &[key.clone(), ty.clone(), bucket.clone()]);
            }
        }
    }

    /// `pruneDocumentPermissions($key, $bucket, $documentId, $type)`.
    fn prune_document_permissions(&mut self, key: &ArrayKey, bucket: &ArrayKey, doc: &ArrayKey, ty: &ArrayKey) {
        if !store::empty(store::get(&self.by_document, &[key.clone(), bucket.clone(), doc.clone(), ty.clone()])) {
            return;
        }
        store::unset(&mut self.by_document, &[key.clone(), bucket.clone(), doc.clone(), ty.clone()]);
        if !store::empty(store::get(&self.by_document, &[key.clone(), bucket.clone(), doc.clone()])) {
            return;
        }
        store::unset(&mut self.by_document, &[key.clone(), bucket.clone(), doc.clone()]);
        if store::empty(store::get(&self.by_document, &[key.clone(), bucket.clone()])) {
            store::unset(&mut self.by_document, &[key.clone(), bucket.clone()]);
        }
    }

    /// `removePermissionsForDocument($key, $documentId, $tenantScope,
    /// $sharedTablesScope)`: every permission entry of the document, only
    /// the tenant's when `scoped`.
    pub(crate) fn remove_permissions_for_document(
        &mut self,
        ctx: &Ctx,
        key: &ArrayKey,
        document: &str,
        tenant: &Value,
        scoped: bool,
    ) {
        let doc = k(document);
        let buckets: Vec<ArrayKey> = if scoped {
            vec![bucket_of(tenant)]
        } else {
            match self.by_document.get(key) {
                Some(Value::Array(b)) => b.keys().map(|k| k.to_owned()).collect(),
                _ => Vec::new(),
            }
        };
        if !buckets.iter().any(|b| store::isset(&self.by_document, &[key.clone(), b.clone(), doc.clone()])) {
            return;
        }
        let flat: Vec<(ArrayKey, Array)> = match self.permissions.get(key) {
            Some(Value::Array(list)) => list
                .iter()
                .filter_map(|(i, e)| match e {
                    Value::Array(e) => Some((i.to_owned(), e.clone())),
                    _ => None,
                })
                .collect(),
            _ => Vec::new(),
        };
        let mut removed: Vec<(ArrayKey, Array)> = Vec::new();
        for (index, entry) in flat {
            if !entry.get_str("document").is_some_and(|d| d.strict_eq(&s(document))) {
                continue;
            }
            let entry_tenant = entry.get_str("tenant").cloned().unwrap_or(Value::Null);
            if scoped && !entry_tenant.strict_eq(tenant) {
                continue;
            }
            store::unset(&mut self.permissions, &[key.clone(), index.clone()]);
            let bucket = bucket_of(&entry_tenant);
            let ty = key_of(entry.get_str("type").unwrap_or(&Value::Null));
            let perm = key_of(entry.get_str("permission").unwrap_or(&Value::Null));
            self.prune_permission(key, &ty, &bucket, &perm, &doc);
            store::unset(&mut self.by_document, &[key.clone(), bucket.clone(), doc.clone(), ty.clone(), perm]);
            self.prune_document_permissions(key, &bucket, &doc, &ty);
            removed.push((index, entry));
        }
        let key = key.clone();
        self.journal(ctx, move |s: &mut Store| {
            for (index, entry) in removed {
                let tenant = entry.get_str("tenant").cloned().unwrap_or(Value::Null);
                let bucket = bucket_of(&tenant);
                let doc = key_of(entry.get_str("document").unwrap_or(&Value::Null));
                let ty = key_of(entry.get_str("type").unwrap_or(&Value::Null));
                let perm = key_of(entry.get_str("permission").unwrap_or(&Value::Null));
                store::set(&mut s.permissions, &[key.clone(), index], Value::Array(entry));
                store::set(
                    &mut s.by_document,
                    &[key.clone(), bucket.clone(), doc.clone(), ty.clone(), perm.clone()],
                    Value::Bool(true),
                );
                store::set(&mut s.by_permission, &[key.clone(), ty, bucket, perm, doc], Value::Bool(true));
            }
        });
    }

    /// `probeUniqueHash($key, $indexId, $newHash, $oldHash, $docKey)`.
    fn probe_unique_hash(
        &mut self,
        ctx: &Ctx,
        key: &ArrayKey,
        index_id: &ArrayKey,
        new_hash: Option<&ArrayKey>,
        old_hash: Option<&ArrayKey>,
        doc_key: &Value,
    ) -> Result<()> {
        let at = |h: &ArrayKey| [key.clone(), index_id.clone(), h.clone()];
        if let Some(h) = new_hash
            && let Some(bound) = store::get(&self.hashes, &at(h)).filter(|v| !matches!(v, Value::Null))
            && !bound.strict_eq(doc_key)
        {
            return Err(Error::unique(UNIQUE_MESSAGE));
        }
        let previous_at_new =
            new_hash.and_then(|h| store::get(&self.hashes, &at(h)).cloned().filter(|v| !matches!(v, Value::Null)));
        if let Some(o) = old_hash
            && Some(o) != new_hash
            && store::get(&self.hashes, &at(o)).is_some_and(|v| v.strict_eq(doc_key))
        {
            store::unset(&mut self.hashes, &at(o));
        }
        if let Some(h) = new_hash {
            store::set(&mut self.hashes, &at(h), doc_key.clone());
        }
        let (key, index_id) = (key.clone(), index_id.clone());
        let (new_hash, old_hash, doc_key) = (new_hash.cloned(), old_hash.cloned(), doc_key.clone());
        self.journal(ctx, move |s: &mut Store| {
            if let Some(h) = &new_hash {
                let path = [key.clone(), index_id.clone(), h.clone()];
                match previous_at_new {
                    None => store::unset(&mut s.hashes, &path),
                    Some(p) => store::set(&mut s.hashes, &path, p),
                }
            }
            if let Some(o) = old_hash
                && Some(&o) != new_hash.as_ref()
            {
                store::set(&mut s.hashes, &[key, index_id, o], doc_key);
            }
        });
        Ok(())
    }

    /// The unique indexes of a collection: id and string attributes.
    fn unique_indexes(&self, key: &ArrayKey) -> Vec<(ArrayKey, Vec<String>)> {
        let mut out = Vec::new();
        let unique = s(utopia_query::schema::IndexType::Unique.as_str());
        if let Some(indexes) = store::get_array(&self.data, &[key.clone(), k("indexes")]) {
            for (index_id, index) in indexes.iter() {
                let Value::Array(index) = index else { continue };
                if !index.get_str("type").is_some_and(|t| t.strict_eq(&unique)) {
                    continue;
                }
                let attributes: Vec<String> = match index.get_str("attributes") {
                    Some(Value::Array(a)) if !a.is_empty() => a
                        .values()
                        .filter_map(|v| match v {
                            Value::Str(s) => Some(String::from_utf8_lossy(s.as_bytes()).into_owned()),
                            _ => None,
                        })
                        .collect(),
                    _ => continue,
                };
                out.push((index_id.to_owned(), attributes));
            }
        }
        out
    }

    /// `rowUniqueSignatures($key, $row)`.
    fn row_unique_signatures(&self, ctx: &Ctx, key: &ArrayKey, row: &Array) -> Vec<(ArrayKey, ArrayKey)> {
        let mut out = Vec::new();
        for (index_id, attributes) in self.unique_indexes(key) {
            let mut signature = Array::new();
            for attribute in &attributes {
                let _ = signature.push(normalize_index_value(Self::resolve_attribute_value(row, attribute)));
            }
            if signature.values().any(|v| matches!(v, Value::Null)) {
                continue;
            }
            if ctx.shared {
                signature = prepend(signature, row.get_str(storage::TENANT).cloned().unwrap_or(Value::Null));
            }
            out.push((index_id, serialize(&signature)));
        }
        out
    }

    /// `documentUniqueSignatures($key, $document)`.
    fn document_unique_signatures(&self, ctx: &Ctx, key: &ArrayKey, document: &Document) -> Vec<(ArrayKey, ArrayKey)> {
        let mut out = Vec::new();
        for (index_id, attributes) in self.unique_indexes(key) {
            let mut signature = Array::new();
            for attribute in &attributes {
                let _ = signature.push(normalize_index_value(Self::resolve_document_value(document, attribute)));
            }
            if signature.values().any(|v| matches!(v, Value::Null)) {
                continue;
            }
            if ctx.shared {
                signature = prepend(signature, tenant_of(ctx, document));
            }
            out.push((index_id, serialize(&signature)));
        }
        out
    }

    /// `checkUniqueSignatures($key, $newSignatures, $docKey)`.
    fn check_unique_signatures(
        &self,
        key: &ArrayKey,
        signatures: &[(ArrayKey, ArrayKey)],
        doc_key: &Value,
    ) -> Result<()> {
        for (index_id, hash) in signatures {
            if let Some(existing) = store::get(&self.hashes, &[key.clone(), index_id.clone(), hash.clone()])
                .filter(|v| !matches!(v, Value::Null))
                && !existing.strict_eq(doc_key)
            {
                return Err(Error::unique(UNIQUE_MESSAGE));
            }
        }
        Ok(())
    }
}
