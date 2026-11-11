//! Documents: reads, creates, updates, deletes, sequences, increments, the
//! permission indexes and the unique-index hash tables.
//!
//! PHP: `Memory::getDocument`, `createDocument(s)`, `updateDocument(s)`,
//! `getSequences`, `deleteDocument(s)`, `increaseDocumentAttribute`,
//! `documentToRow`, `rowToDocument`, `writePermissions`,
//! `addPermissionEntry`, `removePermissionsForDocument`, `probeUniqueHash`,
//! `rowUniqueSignatures`, `documentUniqueSignatures`,
//! `checkUniqueSignatures`, `resolveAttributeValue`,
//! `resolveDocumentValue`, `resolveNestedPath`, `mapAttribute`,
//! `locateDocument`, `documentKey`, `applyOperators`.

use super::schema::{normalize_index_value, prepend, serialize};
use super::store::{self, array_merge, k, key_of, key_value, s};
use super::{Ctx, Store, filter};
use crate::database::Database;
use crate::document::Document;
use crate::error::{Error, Kind, Result};
use crate::query::{Method, Query};
use crate::value::{Array, ArrayKey, KeyRef, Str, Value};

/// `json_encode($value)`: the JSON text, or `false` when it fails.
pub(super) fn json_encode(value: &Value) -> Value {
    match php_std::json::encode(value, php_std::json::Flags::NONE, php_std::json::DEFAULT_DEPTH) {
        Ok(s) => Value::Str(Str::from(s)),
        Err(_) => Value::Bool(false),
    }
}

/// `json_decode($text, true)` when it decodes to an array.
pub(super) fn json_array(text: &[u8]) -> Option<Array> {
    match php_std::json::decode(text, Some(true), php_std::json::DEFAULT_DEPTH, php_std::json::Flags::NONE) {
        Ok(php_std::Value::Array(a)) => Some(a.into_iter().map(|(k, v)| (k, crate::value::from_plain(v))).collect()),
        _ => None,
    }
}

/// A string that starts like JSON (`[` or `{`), decoded to an array.
pub(super) fn decode_json_like(value: &Value) -> Option<Array> {
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

/// `$document->getId()`.
fn id_of(document: &Document) -> String {
    document.raw("$id").map(text).unwrap_or_default()
}

/// `$document->getSequence()` as `?string`.
fn sequence_of(document: &Document) -> Option<Value> {
    match document.raw("$sequence") {
        None | Some(Value::Null) => None,
        Some(v @ Value::Str(_)) => Some(v.clone()),
        Some(v) => Some(Value::Str(v.to_php_string().unwrap_or(Str::EMPTY))),
    }
}

/// The value `getCreatedAt()` / `getUpdatedAt()` return.
fn string_or_null(document: &Document, key: &str) -> Value {
    match document.raw(key) {
        None | Some(Value::Null) => Value::Null,
        Some(v @ Value::Str(_)) => v.clone(),
        Some(v) => Value::Str(v.to_php_string().unwrap_or(Str::EMPTY)),
    }
}

impl Store {
    /// `documentKey($id, $tenant)`: the lower-cased id, prefixed with the
    /// tenant under shared tables.
    pub(super) fn document_key(ctx: &Ctx, id: &str, tenant: Option<&Value>) -> ArrayKey {
        key_of(&Self::document_key_text(ctx, id, tenant))
    }

    /// `documentKey($id, $tenant)` as the string PHP holds (the array key
    /// it becomes is [`Store::document_key`]).
    pub(super) fn document_key_text(ctx: &Ctx, id: &str, tenant: Option<&Value>) -> Value {
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

    /// `locateDocument($key, $collectionId, $id)`: the storage key and row,
    /// falling back to a `null`-tenant copy for the metadata collection
    /// under shared tables.
    pub(super) fn locate_document(
        &self,
        ctx: &Ctx,
        key: &ArrayKey,
        collection: &str,
        id: &str,
    ) -> Option<(Value, Array)> {
        let primary = Self::document_key_text(ctx, id, None);
        if let Some(Value::Array(row)) = store::get(&self.data, &[key.clone(), k("documents"), key_of(&primary)]) {
            return Some((primary, row.clone()));
        }
        if ctx.shared && collection == Database::METADATA {
            let wanted = lower(id.as_bytes());
            if let Some(documents) = store::get_array(&self.data, &[key.clone(), k("documents")]) {
                for (storage_key, candidate) in documents.iter() {
                    let Value::Array(candidate) = candidate else { continue };
                    let uid = candidate.get_str("_uid").map(text).unwrap_or_default();
                    let tenant = candidate.get_str("_tenant").cloned().unwrap_or(Value::Null);
                    if lower(uid.as_bytes()) == wanted && matches!(tenant, Value::Null) {
                        return Some((key_value(storage_key), candidate.clone()));
                    }
                }
            }
        }
        None
    }

    /// `mapAttribute($attribute)`: internal attributes to their columns.
    pub(super) fn map_attribute(attribute: &str) -> String {
        match attribute {
            "$id" => "_uid".to_owned(),
            "$sequence" => "_id".to_owned(),
            "$tenant" => "_tenant".to_owned(),
            "$createdAt" => "_createdAt".to_owned(),
            "$updatedAt" => "_updatedAt".to_owned(),
            "$permissions" => "_permissions".to_owned(),
            other => filter(other),
        }
    }

    /// `resolveAttributeValue($row, $attribute)`: the column, or a dotted
    /// path into a JSON value.
    pub(super) fn resolve_attribute_value(&self, _ctx: &Ctx, row: &Array, attribute: &str) -> Value {
        let flat = k(&Self::map_attribute(attribute));
        if let Some(v) = row.get(&flat) {
            return v.clone();
        }
        let Some((head, rest)) = attribute.split_once('.') else {
            return Value::Null;
        };
        let mut value = row.get(&k(&Self::map_attribute(head))).cloned().unwrap_or(Value::Null);
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
                None => document.get(&Self::map_attribute(attribute)).cloned().unwrap_or(Value::Null),
            };
        };
        let mut value = document.get(head).cloned().unwrap_or(Value::Null);
        if let Some(decoded) = decode_json_like(&value) {
            value = Value::Array(decoded);
        }
        resolve_nested_path(value, rest)
    }

    /// `getDocument($collection, $id, $queries)`.
    pub(super) fn get_document(
        &self,
        ctx: &Ctx,
        collection: &Document,
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
        let selections: Vec<Value> = queries
            .iter()
            .filter(|q| *q.method() == Method::Select)
            .flat_map(|q| q.values().iter().map(|v| Value::Str(v.to_php_string().unwrap_or(Str::EMPTY))))
            .collect();
        if !selections.is_empty() {
            row = project_row(row, &selections);
        }
        Document::new(row)
    }

    /// `rowToDocument($row, $selections, $storageKey)`: internal columns to
    /// their `$` attributes, the selection applied, and relationship fields
    /// the row lacks as `null`.
    pub(super) fn row_to_document(
        &self,
        row: &Array,
        selections: Option<&[Value]>,
        storage: Option<&ArrayKey>,
    ) -> Array {
        let allowed: Option<Array> = match selections {
            Some(sel) if !sel.is_empty() && !sel.iter().any(|v| v.strict_eq(&s("*"))) => {
                Some(sel.iter().map(|v| (k(&filter(&text(v))), Value::Bool(true))).collect())
            }
            _ => None,
        };
        let mut document = Array::new();
        for (key, value) in row.iter() {
            let name = match key {
                KeyRef::Str(s) => Some(s),
                KeyRef::Int(_) => None,
            };
            match name {
                Some(b"_id") => {
                    document.set(k("$sequence"), Value::Str(value.to_php_string().unwrap_or(Str::EMPTY)));
                }
                Some(b"_uid") => {
                    document.set(k("$id"), value.clone());
                }
                Some(b"_tenant") => {
                    document.set(k("$tenant"), value.clone());
                }
                Some(b"_createdAt") => {
                    document.set(k("$createdAt"), value.clone());
                }
                Some(b"_updatedAt") => {
                    document.set(k("$updatedAt"), value.clone());
                }
                Some(b"_permissions") => {
                    let v = if matches!(value, Value::Null) { Value::Array(Array::new()) } else { value.clone() };
                    document.set(k("$permissions"), v);
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
            for (id, definition) in attributes.iter() {
                let relationship = match definition {
                    Value::Array(d) => d.get_str("type").is_some_and(|t| t.strict_eq(&s(Database::VAR_RELATIONSHIP))),
                    _ => false,
                };
                if !relationship {
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
            let column = match key {
                KeyRef::Str(s) => filter(&String::from_utf8_lossy(s)),
                KeyRef::Int(i) => i.to_string(),
            };
            row.set(k(&column), value.clone());
        }
        row.set(k("_uid"), Value::Str(Str::from(id_of(document))));
        row.set(k("_createdAt"), string_or_null(document, "$createdAt"));
        row.set(k("_updatedAt"), string_or_null(document, "$updatedAt"));
        row.set(k("_permissions"), Value::Array(document.permission_list()?));
        if ctx.shared {
            let tenant = document.tenant().filter(|t| !matches!(t, Value::Null)).unwrap_or_else(|| ctx.tenant.clone());
            row.set(k("_tenant"), tenant);
        }
        Ok(row)
    }

    /// `createDocument($collection, $document)`.
    pub(super) fn create_document(
        &mut self,
        ctx: &Ctx,
        collection: &Document,
        mut document: Document,
    ) -> Result<Document> {
        let key = Self::key(ctx, collection.id());
        if !self.has(&key) {
            return Err(Error::not_found("Collection not found"));
        }
        let doc_key_value = Self::document_key_text(ctx, &id_of(&document), document.tenant().as_ref());
        let doc_key = key_of(&doc_key_value);
        let doc_path = [key.clone(), k("documents"), doc_key.clone()];
        if let Some(Value::Array(existing)) = store::get(&self.data, &doc_path) {
            if ctx.skip_duplicates {
                let sequence = existing.get_str("_id").map(text).unwrap_or_default();
                document.set("$sequence", Value::Str(Str::from(sequence)));
                return Ok(document);
            }
            return Err(Error::duplicate("Document already exists"));
        }
        let signatures = self.document_unique_signatures(ctx, &key, &document);
        if let Err(e) = self.check_unique_signatures(&key, &signatures, &doc_key_value) {
            if ctx.skip_duplicates {
                return Ok(document);
            }
            return Err(e);
        }
        let sequence_path = [key.clone(), k("sequence")];
        let before = store::get(&self.data, &sequence_path).cloned().unwrap_or(Value::Int(0));
        let current = super::operators::to_long(&before);
        let sequence = match sequence_of(&document).filter(|v| v.truthy()) {
            None => {
                store::set(&mut self.data, &sequence_path, Value::Int(current + 1));
                current + 1
            }
            Some(v) => {
                let v = super::operators::to_long(&v);
                if v > current {
                    store::set(&mut self.data, &sequence_path, Value::Int(v));
                }
                v
            }
        };
        let mut row = Self::document_to_row(ctx, &document)?;
        row.set(k("_id"), Value::Int(sequence));
        store::set(&mut self.data, &doc_path, Value::Array(row));
        {
            let doc_path = doc_path.clone();
            self.journal(ctx, move |s: &mut Store| {
                store::unset(&mut s.data, &doc_path);
                store::set(&mut s.data, &sequence_path, before);
            });
        }
        for (index_id, hash) in signatures {
            self.probe_unique_hash(ctx, &key, &index_id, Some(&hash), None, &doc_key_value)?;
        }
        self.write_permissions(ctx, &key, &document)?;
        document.set("$sequence", Value::Str(Str::from(sequence.to_string())));
        Ok(document)
    }

    /// `updateDocument($collection, $id, $document, $skipPermissions)`.
    pub(super) fn update_document(
        &mut self,
        ctx: &Ctx,
        collection: &Document,
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
            document.set(Str::from(key_text(attribute)), value.clone());
        }
        let new_id = id_of(&document);
        let candidate = Self::document_key(ctx, &new_id, None);
        if new_id != id && store::isset(&self.data, &[key.clone(), k("documents"), candidate]) {
            return Err(Error::duplicate("Document already exists"));
        }
        let update = Self::document_to_row(ctx, &document)?;
        let mut row = array_merge(&[&existing, &update]);
        let new_signatures = self.row_unique_signatures(ctx, &key, &row);
        let old_signatures = self.row_unique_signatures(ctx, &key, &existing);
        self.check_unique_signatures(&key, &new_signatures, &old_key)?;
        row.set(k("_id"), existing.get_str("_id").cloned().unwrap_or(Value::Null));
        if ctx.shared
            && let Some(t) = existing.get_str("_tenant")
        {
            row.set(k("_tenant"), t.clone());
        }
        let new_key = if ctx.shared {
            let tenant = existing
                .get_str("_tenant")
                .filter(|v| !matches!(v, Value::Null))
                .cloned()
                .unwrap_or_else(|| ctx.tenant.clone());
            let mut key = text(&tenant).into_bytes();
            key.push(b'|');
            key.extend_from_slice(&lower(new_id.as_bytes()));
            ArrayKey::normalize(Str::from(key))
        } else {
            ArrayKey::normalize(Str::from(lower(new_id.as_bytes())))
        };
        let new_key_value = Value::Str(match &new_key {
            ArrayKey::Str(s) => s.clone(),
            ArrayKey::Int(i) => Str::from(i.to_string()),
        });
        let old_array_key = key_of(&old_key);
        let documents = [key.clone(), k("documents")];
        let old_had_row = store::isset(&self.data, &[key.clone(), k("documents"), old_array_key.clone()]);
        let previous_at_new = store::get(&self.data, &[key.clone(), k("documents"), new_key.clone()])
            .cloned()
            .filter(|v| !matches!(v, Value::Null));
        let same_key = old_key.strict_eq(&new_key_value);
        if new_id != id || !same_key {
            store::unset(&mut self.data, &[key.clone(), k("documents"), old_array_key.clone()]);
        }
        store::vivify(&mut self.data, &documents).set(new_key.clone(), Value::Array(row));
        {
            let (documents, old_array_key, new_key) = (documents.clone(), old_array_key.clone(), new_key.clone());
            let existing = existing.clone();
            self.journal(ctx, move |s: &mut Store| {
                let table = store::vivify(&mut s.data, &documents);
                if !same_key {
                    match previous_at_new {
                        None => {
                            table.remove(&new_key);
                        }
                        Some(p) => {
                            table.set(new_key, p);
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
        let mut all: Array = Array::new();
        for id in old_signatures.iter().map(|(i, _)| i).chain(new_signatures.iter().map(|(i, _)| i)) {
            let _ = all.push(key_value(id.as_ref()));
        }
        let all = php_std::sort::array_unique(&all, php_std::sort::SortFlags::String).map_err(Error::from)?;
        for index_id in all.values() {
            let index_id = key_of(index_id);
            let new_hash = new_signatures.iter().find(|(i, _)| *i == index_id).map(|(_, h)| h.clone());
            let old_hash = old_signatures.iter().find(|(i, _)| *i == index_id).map(|(_, h)| h.clone());
            self.probe_unique_hash(ctx, &key, &index_id, new_hash.as_ref(), old_hash.as_ref(), &new_key_value)?;
            if !same_key && let Some(old_hash) = old_hash {
                let path = [key.clone(), index_id.clone(), old_hash.clone()];
                if store::get(&self.hashes, &path).is_some_and(|v| v.strict_eq(&old_key)) {
                    store::unset(&mut self.hashes, &path);
                    let old_key = old_key.clone();
                    self.journal(ctx, move |s: &mut Store| {
                        store::set(&mut s.hashes, &path, old_key);
                    });
                }
            }
        }
        if !skip_permissions {
            let tenant = ctx.tenant.clone();
            self.remove_permissions_for_document(ctx, &key, id, &tenant, ctx.shared);
            if new_id != id {
                self.remove_permissions_for_document(ctx, &key, &new_id, &tenant, ctx.shared);
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
                let field = |name: &str| entry.get_str(name).map(text).unwrap_or_default();
                let tenant = entry.get_str("tenant").cloned().unwrap_or(Value::Null);
                self.add_permission_entry(ctx, &key, &new_id, &field("type"), &field("permission"), &tenant);
            }
        }
        Ok(document)
    }

    /// `updateDocuments($collection, $updates, $documents)`: every row
    /// checked against the unique indexes before any is written.
    pub(super) fn update_documents(
        &mut self,
        ctx: &Ctx,
        collection: &Document,
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
        let attrs = updates.attributes();
        let created_at = string_or_null(updates, "$createdAt");
        let updated_at = string_or_null(updates, "$updatedAt");
        let has_created_at = created_at.truthy();
        let has_updated_at = updated_at.truthy();
        let has_permissions = updates.has("$permissions");
        if attrs.is_empty() && !has_created_at && !has_updated_at && !has_permissions {
            return Ok(0);
        }
        struct Prepared {
            uid: String,
            doc_key: ArrayKey,
            doc_key_value: Value,
            attrs: Array,
            new_signatures: Vec<(ArrayKey, ArrayKey)>,
            old_signatures: Vec<(ArrayKey, ArrayKey)>,
        }
        let mut prepared: Vec<Prepared> = Vec::new();
        for document in documents {
            let uid = id_of(document);
            let doc_key_value = Self::document_key_text(ctx, &uid, None);
            let doc_key = key_of(&doc_key_value);
            let Some(Value::Array(existing)) =
                store::get(&self.data, &[key.clone(), k("documents"), doc_key.clone()]).cloned()
            else {
                continue;
            };
            let resolved = apply_operators(&attrs, &existing)?;
            let new_signatures = if resolved.is_empty() {
                Vec::new()
            } else {
                let base = self.row_to_document(&existing, None, None);
                let mut id = Array::new();
                id.set(k("$id"), Value::Str(Str::from(uid.as_str())));
                let merged = Document::new(array_merge(&[&base, &resolved, &id]))?;
                self.document_unique_signatures(ctx, &key, &merged)
            };
            let old_signatures = self.row_unique_signatures(ctx, &key, &existing);
            prepared.push(Prepared { uid, doc_key, doc_key_value, attrs: resolved, new_signatures, old_signatures });
        }
        let mut pending: Vec<(ArrayKey, ArrayKey, Value)> = Vec::new();
        for entry in &prepared {
            let doc_key = entry.doc_key_value.clone();
            for (index_id, hash) in &entry.new_signatures {
                let existing = store::get(&self.hashes, &[key.clone(), index_id.clone(), hash.clone()])
                    .cloned()
                    .filter(|v| !matches!(v, Value::Null));
                if let Some(existing) = existing
                    && !existing.strict_eq(&doc_key)
                {
                    let is_self = prepared.iter().any(|other| {
                        other.doc_key_value.strict_eq(&existing)
                            && other.old_signatures.iter().any(|(i, h)| i == index_id && h == hash)
                    });
                    if !is_self {
                        return Err(Error::new(Kind::Unique, "Unique index violation"));
                    }
                }
                if let Some((_, _, owner)) = pending.iter().find(|(i, h, _)| i == index_id && h == hash)
                    && !owner.strict_eq(&entry.doc_key_value)
                {
                    return Err(Error::new(Kind::Unique, "Unique index violation"));
                }
                match pending.iter_mut().find(|(i, h, _)| i == index_id && h == hash) {
                    Some(slot) => slot.2 = entry.doc_key_value.clone(),
                    None => pending.push((index_id.clone(), hash.clone(), entry.doc_key_value.clone())),
                }
            }
        }
        let tenant = ctx.tenant.clone();
        let count = prepared.len() as i64;
        for entry in prepared {
            let path = [key.clone(), k("documents"), entry.doc_key.clone()];
            let previous = store::get(&self.data, &path).cloned().unwrap_or(Value::Null);
            let row = store::vivify(&mut self.data, &path);
            for (attribute, value) in entry.attrs.iter() {
                row.set(k(&filter(&key_text(attribute))), value.clone());
            }
            if has_created_at {
                row.set(k("_createdAt"), created_at.clone());
            }
            if has_updated_at {
                row.set(k("_updatedAt"), updated_at.clone());
            }
            if has_permissions {
                row.set(k("_permissions"), Value::Array(updates.permission_list()?));
            }
            {
                let path = path.clone();
                self.journal(ctx, move |s: &mut Store| {
                    store::set(&mut s.data, &path, previous);
                });
            }
            if has_permissions {
                self.remove_permissions_for_document(ctx, &key, &entry.uid, &tenant, ctx.shared);
                for kind in Database::PERMISSIONS {
                    for permission in updates.permissions_by_type(kind) {
                        self.add_permission_entry(ctx, &key, &entry.uid, kind, &permission, &tenant);
                    }
                }
            }
            let mut all: Array = Array::new();
            for id in entry.old_signatures.iter().map(|(i, _)| i).chain(entry.new_signatures.iter().map(|(i, _)| i)) {
                let _ = all.push(key_value(id.as_ref()));
            }
            let all = php_std::sort::array_unique(&all, php_std::sort::SortFlags::String).map_err(Error::from)?;
            let doc_key = entry.doc_key_value.clone();
            for index_id in all.values() {
                let index_id = key_of(index_id);
                let new_hash = entry.new_signatures.iter().find(|(i, _)| *i == index_id).map(|(_, h)| h.clone());
                let old_hash = entry.old_signatures.iter().find(|(i, _)| *i == index_id).map(|(_, h)| h.clone());
                self.probe_unique_hash(ctx, &key, &index_id, new_hash.as_ref(), old_hash.as_ref(), &doc_key)?;
            }
        }
        Ok(count)
    }

    /// `getSequences($collection, $documents)`.
    pub(super) fn get_sequences(&self, ctx: &Ctx, collection: &str, mut documents: Vec<Document>) -> Vec<Document> {
        let key = Self::key(ctx, collection);
        if !self.has(&key) {
            return documents;
        }
        for document in &mut documents {
            if sequence_of(document).is_some_and(|v| v.truthy()) {
                continue;
            }
            let doc_key = Self::document_key(ctx, &id_of(document), document.tenant().as_ref());
            if let Some(Value::Array(existing)) = store::get(&self.data, &[key.clone(), k("documents"), doc_key]) {
                let sequence = existing.get_str("_id").map(text).unwrap_or_default();
                document.set("$sequence", Value::Str(Str::from(sequence)));
            }
        }
        documents
    }

    /// `deleteDocument($collection, $id)`.
    pub(super) fn delete_document(&mut self, ctx: &Ctx, collection: &str, id: &str) -> Result<bool> {
        let key = Self::key(ctx, collection);
        if !self.has(&key) {
            return Err(Error::not_found("Collection not found"));
        }
        let doc_key_value = Self::document_key_text(ctx, id, None);
        let path = [key.clone(), k("documents"), key_of(&doc_key_value)];
        let Some(Value::Array(existing)) = store::get(&self.data, &path).cloned() else {
            return Ok(false);
        };
        let old_signatures = self.row_unique_signatures(ctx, &key, &existing);
        store::unset(&mut self.data, &path);
        {
            let path = path.clone();
            self.journal(ctx, move |s: &mut Store| {
                store::set(&mut s.data, &path, Value::Array(existing));
            });
        }
        for (index_id, hash) in old_signatures {
            let path = [key.clone(), index_id, hash];
            if store::get(&self.hashes, &path).is_some_and(|v| v.strict_eq(&doc_key_value)) {
                store::unset(&mut self.hashes, &path);
                let doc_key_value = doc_key_value.clone();
                self.journal(ctx, move |s: &mut Store| {
                    store::set(&mut s.hashes, &path, doc_key_value);
                });
            }
        }
        let tenant = ctx.tenant.clone();
        self.remove_permissions_for_document(ctx, &key, id, &tenant, ctx.shared);
        Ok(true)
    }

    /// `deleteDocuments($collection, $sequences, $permissionIds)`.
    pub(super) fn delete_documents(
        &mut self,
        ctx: &Ctx,
        collection: &str,
        sequences: &[Value],
        permission_ids: &[String],
    ) -> Result<i64> {
        let key = Self::key(ctx, collection);
        if !self.has(&key) {
            return Err(Error::not_found("Collection not found"));
        }
        let wanted: Array = sequences.iter().map(|v| (k(&text(v)), Value::Bool(true))).collect();
        let mut count = 0;
        let mut deleted: Array = Array::new();
        let rows: Vec<(ArrayKey, Array)> = match store::get_array(&self.data, &[key.clone(), k("documents")]) {
            Some(documents) => documents
                .iter()
                .filter_map(|(dk, row)| match row {
                    Value::Array(row) => Some((dk.to_owned(), row.clone())),
                    _ => None,
                })
                .collect(),
            None => Vec::new(),
        };
        for (doc_key, row) in rows {
            if ctx.shared && !row.get_str("_tenant").cloned().unwrap_or(Value::Null).strict_eq(&ctx.tenant) {
                continue;
            }
            let sequence = row.get_str("_id").map(text).unwrap_or_default();
            if !store::isset(&wanted, &[k(&sequence)]) {
                continue;
            }
            let uid = match row.get_str("_uid").filter(|v| !matches!(v, Value::Null)) {
                Some(u) => text(u),
                None => key_text(doc_key.as_ref()),
            };
            deleted.set(k(&uid), Value::Bool(true));
            let old_signatures = self.row_unique_signatures(ctx, &key, &row);
            let path = [key.clone(), k("documents"), doc_key.clone()];
            store::unset(&mut self.data, &path);
            {
                let path = path.clone();
                self.journal(ctx, move |s: &mut Store| {
                    store::set(&mut s.data, &path, Value::Array(row));
                });
            }
            let doc_key_value = key_value(doc_key.as_ref());
            for (index_id, hash) in old_signatures {
                let path = [key.clone(), index_id, hash];
                if store::get(&self.hashes, &path).is_some_and(|v| v.strict_eq(&doc_key_value)) {
                    store::unset(&mut self.hashes, &path);
                    let doc_key_value = doc_key_value.clone();
                    self.journal(ctx, move |s: &mut Store| {
                        store::set(&mut s.hashes, &path, doc_key_value);
                    });
                }
            }
            count += 1;
        }
        let permission_set: Array = permission_ids.iter().map(|p| (k(p), Value::Bool(true))).collect();
        if !deleted.is_empty() || !permission_set.is_empty() {
            let tenant = ctx.tenant.clone();
            for document_id in deleted.keys() {
                let document_id = key_text(document_id);
                self.remove_permissions_for_document(ctx, &key, &document_id, &tenant, ctx.shared);
            }
            for document_id in permission_set.keys() {
                if deleted.contains_key(&document_id.to_owned()) {
                    continue;
                }
                let document_id = key_text(document_id);
                self.remove_permissions_for_document(ctx, &key, &document_id, &tenant, ctx.shared);
            }
        }
        Ok(count)
    }

    /// `increaseDocumentAttribute(...)`: a bound the current value breaks
    /// leaves it unchanged (still `true`).
    #[allow(clippy::too_many_arguments)]
    pub(super) fn increase(
        &mut self,
        ctx: &Ctx,
        collection: &str,
        id: &str,
        attribute: &str,
        value: &Value,
        updated_at: &str,
        min: Option<&Value>,
        max: Option<&Value>,
    ) -> Result<bool> {
        let key = Self::key(ctx, collection);
        let doc_key = Self::document_key(ctx, id, None);
        let path = [key.clone(), k("documents"), doc_key.clone()];
        if !store::isset(&self.data, &path) {
            return Err(Error::not_found("Document not found"));
        }
        let column = k(&filter(attribute));
        let row = store::get_array(&self.data, &path).cloned().unwrap_or_default();
        let previous_value = row.get(&column).cloned().filter(|v| !matches!(v, Value::Null));
        let previous_updated_at = row.get_str("_updatedAt").cloned().filter(|v| !matches!(v, Value::Null));
        let current = previous_value.clone().unwrap_or(Value::Int(0));
        let current = match super::operators::numeric(&current) {
            Some(n) => n,
            None => Value::Int(0),
        };
        if let Some(min) = min
            && current.compare(min) < 0
        {
            return Ok(true);
        }
        if let Some(max) = max
            && current.compare(max) > 0
        {
            return Ok(true);
        }
        let sum = super::operators::add(&current, value)?;
        let target = store::vivify(&mut self.data, &path);
        target.set(column.clone(), sum);
        target.set(k("_updatedAt"), Value::Str(Str::from(updated_at)));
        self.journal(ctx, move |s: &mut Store| {
            let target = store::vivify(&mut s.data, &path);
            match previous_value {
                None => {
                    target.remove(&column);
                }
                Some(v) => {
                    target.set(column, v);
                }
            }
            match previous_updated_at {
                None => {
                    target.remove(&k("_updatedAt"));
                }
                Some(v) => {
                    target.set(k("_updatedAt"), v);
                }
            }
        });
        Ok(true)
    }

    /// `writePermissions($key, $document)`.
    fn write_permissions(&mut self, ctx: &Ctx, key: &ArrayKey, document: &Document) -> Result<()> {
        let uid = id_of(document);
        let tenant = document.tenant().filter(|t| !matches!(t, Value::Null)).unwrap_or_else(|| ctx.tenant.clone());
        for kind in Database::PERMISSIONS {
            for role in document.roles(kind)?.values() {
                self.add_permission_entry(ctx, key, &uid, kind, &text(role), &tenant);
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
        store::set(&mut self.by_document, &[key.clone(), doc.clone(), ty.clone(), perm.clone()], Value::Bool(true));
        let bucket = k(&bucket_of(tenant));
        store::set(
            &mut self.by_permission,
            &[key.clone(), ty.clone(), bucket.clone(), perm.clone(), doc.clone()],
            Value::Bool(true),
        );
        let key = key.clone();
        self.journal(ctx, move |s: &mut Store| {
            store::unset(&mut s.permissions, &[key.clone(), ArrayKey::Int(flat)]);
            store::unset(&mut s.by_document, &[key.clone(), doc.clone(), ty.clone(), perm.clone()]);
            if store::empty(store::get(&s.by_document, &[key.clone(), doc.clone(), ty.clone()])) {
                store::unset(&mut s.by_document, &[key.clone(), doc.clone(), ty.clone()]);
                if store::empty(store::get(&s.by_document, &[key.clone(), doc.clone()])) {
                    store::unset(&mut s.by_document, &[key.clone(), doc.clone()]);
                }
            }
            store::unset(&mut s.by_permission, &[key.clone(), ty.clone(), bucket.clone(), perm.clone(), doc]);
            if store::empty(store::get(&s.by_permission, &[key.clone(), ty.clone(), bucket.clone(), perm.clone()])) {
                store::unset(&mut s.by_permission, &[key.clone(), ty.clone(), bucket.clone(), perm]);
                if store::empty(store::get(&s.by_permission, &[key.clone(), ty.clone(), bucket.clone()])) {
                    store::unset(&mut s.by_permission, &[key, ty, bucket]);
                }
            }
        });
    }

    /// `removePermissionsForDocument($key, $documentId, $tenantScope, $sharedTablesScope)`.
    fn remove_permissions_for_document(
        &mut self,
        ctx: &Ctx,
        key: &ArrayKey,
        document: &str,
        tenant: &Value,
        scoped: bool,
    ) {
        let doc = k(document);
        if !store::isset(&self.by_document, &[key.clone(), doc.clone()]) {
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
            if scoped && !entry.get_str("tenant").cloned().unwrap_or(Value::Null).strict_eq(tenant) {
                continue;
            }
            store::unset(&mut self.permissions, &[key.clone(), index.clone()]);
            let entry_tenant = entry.get_str("tenant").cloned().unwrap_or(Value::Null);
            let bucket = k(&bucket_of(&entry_tenant));
            let ty = key_of(entry.get_str("type").unwrap_or(&Value::Null));
            let perm = key_of(entry.get_str("permission").unwrap_or(&Value::Null));
            store::unset(
                &mut self.by_permission,
                &[key.clone(), ty.clone(), bucket.clone(), perm.clone(), doc.clone()],
            );
            if store::empty(store::get(&self.by_permission, &[key.clone(), ty.clone(), bucket.clone(), perm.clone()])) {
                store::unset(&mut self.by_permission, &[key.clone(), ty.clone(), bucket.clone(), perm.clone()]);
                if store::empty(store::get(&self.by_permission, &[key.clone(), ty.clone(), bucket.clone()])) {
                    store::unset(&mut self.by_permission, &[key.clone(), ty.clone(), bucket]);
                }
            }
            store::unset(&mut self.by_document, &[key.clone(), doc.clone(), ty.clone(), perm]);
            if store::empty(store::get(&self.by_document, &[key.clone(), doc.clone(), ty.clone()])) {
                store::unset(&mut self.by_document, &[key.clone(), doc.clone(), ty]);
            }
            removed.push((index, entry));
        }
        if store::empty(store::get(&self.by_document, &[key.clone(), doc.clone()])) {
            store::unset(&mut self.by_document, &[key.clone(), doc]);
        }
        let key = key.clone();
        self.journal(ctx, move |s: &mut Store| {
            for (index, entry) in removed {
                let tenant = entry.get_str("tenant").cloned().unwrap_or(Value::Null);
                let doc = key_of(entry.get_str("document").unwrap_or(&Value::Null));
                let ty = key_of(entry.get_str("type").unwrap_or(&Value::Null));
                let perm = key_of(entry.get_str("permission").unwrap_or(&Value::Null));
                store::set(&mut s.permissions, &[key.clone(), index], Value::Array(entry));
                store::set(
                    &mut s.by_document,
                    &[key.clone(), doc.clone(), ty.clone(), perm.clone()],
                    Value::Bool(true),
                );
                store::set(
                    &mut s.by_permission,
                    &[key.clone(), ty, k(&bucket_of(&tenant)), perm, doc],
                    Value::Bool(true),
                );
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
            return Err(Error::new(Kind::Unique, "Unique index violation"));
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

    /// The unique indexes of a collection: id and attributes.
    fn unique_indexes(&self, key: &ArrayKey) -> Vec<(ArrayKey, Vec<String>)> {
        let mut out = Vec::new();
        if let Some(indexes) = store::get_array(&self.data, &[key.clone(), k("indexes")]) {
            for (index_id, index) in indexes.iter() {
                let Value::Array(index) = index else { continue };
                if !index.get_str("type").is_some_and(|t| t.strict_eq(&s(Database::INDEX_UNIQUE))) {
                    continue;
                }
                let attributes: Vec<String> = match index.get_str("attributes") {
                    Some(Value::Array(a)) if !a.is_empty() => a.values().map(text).collect(),
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
                let _ = signature.push(normalize_index_value(self.resolve_attribute_value(ctx, row, attribute)));
            }
            if signature.values().any(|v| matches!(v, Value::Null)) {
                continue;
            }
            if ctx.shared {
                signature = prepend(signature, row.get_str("_tenant").cloned().unwrap_or(Value::Null));
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
                signature = prepend(signature, ctx.tenant.clone());
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
                return Err(Error::new(Kind::Unique, "Unique index violation"));
            }
        }
        Ok(())
    }
}

/// The tenant bucket of the permission index (`'__null__'` for `null`).
fn bucket_of(tenant: &Value) -> String {
    if matches!(tenant, Value::Null) { "__null__".to_owned() } else { text(tenant) }
}

/// A key's text.
pub(super) fn key_text(key: KeyRef<'_>) -> String {
    match key {
        KeyRef::Int(i) => i.to_string(),
        KeyRef::Str(s) => String::from_utf8_lossy(s).into_owned(),
    }
}

/// `resolveNestedPath($value, $path)`.
pub(super) fn resolve_nested_path(mut value: Value, path: &str) -> Value {
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

/// `projectRow($row, $selections)`.
fn project_row(row: Array, selections: &[Value]) -> Array {
    if selections.iter().any(|v| v.strict_eq(&s("*"))) {
        return row;
    }
    let mut projected = Array::new();
    for (field, value) in row.iter() {
        let name = key_text(field);
        if name.starts_with('$') || name.starts_with('_') {
            projected.set(field.to_owned(), value.clone());
            continue;
        }
        if selections.iter().any(|v| v.strict_eq(&key_value(field))) {
            projected.set(field.to_owned(), value.clone());
        }
    }
    projected
}

/// `applyOperators($attrs, $row)`: operators applied to the row's values.
pub(super) fn apply_operators(attrs: &Array, row: &Array) -> Result<Array> {
    let mut out = Array::new();
    for (attribute, value) in attrs.iter() {
        if let Some(operator) = crate::value::as_operator(value) {
            let mut current = row.get(&k(&filter(&key_text(attribute)))).cloned().unwrap_or(Value::Null);
            if let Some(decoded) = decode_json_like(&current) {
                current = Value::Array(decoded);
            }
            out.set(attribute.to_owned(), super::operators::apply(current, operator)?);
            continue;
        }
        out.set(attribute.to_owned(), value.clone());
    }
    Ok(out)
}
