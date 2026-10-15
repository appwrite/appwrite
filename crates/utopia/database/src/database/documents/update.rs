//! `updateDocument()` and `updateDocuments()`, with `keepStored()` and
//! `rejectJoins()`.

use super::super::filters::{key_text, values_equal};
use super::super::{BoxFuture, Callback, Database, METADATA, RELATION_MAX_DEPTH};
use super::create::holds_related_document;
use super::numeric::{batch_target, document_security, metadata_target};
use super::read::empty;
use crate::attribute::Attribute;
use crate::collection::Collection;
use crate::document::{self, Document};
use crate::error::{Error, Kind, Result};
use crate::event::{Domain, Event};
use crate::operator::Operator;
use crate::permission::PermissionType;
use crate::query::Query;
use crate::relationship::{RelationshipSide, RelationshipType};
use crate::validator::authorization::Input;
use crate::validator::partial_structure::PartialStructure;
use crate::validator::structure::Structure;
use crate::value::{Ext, Str, Value};

impl Database {
    /// Updates the document `id` with the attributes `document` carries
    /// (`updateDocument()`): operators applied, related documents related;
    /// returns it as stored and decoded, an empty document when it does not
    /// exist. An update that changes nothing needs only read permission and
    /// keeps `$updatedAt`.
    ///
    /// Fails ([`crate::Kind::Database`]) without an id,
    /// ([`crate::Kind::Authorization`]) without update permission,
    /// ([`crate::Kind::Conflict`]) for a document changed after the request
    /// timestamp, ([`crate::Kind::Structure`]) for an invalid result,
    /// ([`crate::Kind::Relationship`]) for an invalid relationship value.
    pub fn update_document<'a>(
        &'a self,
        collection: &'a str,
        id: &'a str,
        document: Document,
    ) -> BoxFuture<'a, Result<Document>> {
        Box::pin(async move {
            if empty(id) {
                return Err(Error::database("Must define $id attribute"));
            }

            let collection = self.silent(self.get_collection(collection)).await?;
            let preparation = self.nested_preparation(None, true);
            let new_updated_at = document.updated_at().map(str::to_owned);
            let target = metadata_target(&collection, id);

            let (mut document, has_operators) = self
                .with_mutation(Event::DocumentUpdate, &target, || {
                    let (collection, document, preparation, new_updated_at) =
                        (&collection, document.clone(), &preparation, &new_updated_at);
                    async move {
                        let authorization = self.authorization();
                        let old = authorization
                            .skip(self.silent(self.skip_query_hooks(self.read_document(
                                collection,
                                id,
                                Vec::new(),
                                true,
                            ))))
                            .await?
                            .0;
                        if old.is_empty() {
                            return Ok((Document::empty(), false));
                        }
                        let document = self.prepare_nested_copy(collection, document, preparation.as_ref()).await?;
                        let time = crate::datetime::now_after(old.updated_at().filter(|u| !empty(u)))?;

                        let mut skip_permissions_update = true;
                        if document.has(document::PERMISSIONS) {
                            let mut original = old.permissions()?;
                            let mut current = document.permissions()?;
                            original.sort();
                            current.sort();
                            skip_permissions_update = original == current;
                        }
                        let created_at = document.created_at().map(str::to_owned);

                        let mut merged = old.array_copy();
                        for (key, value) in document.array_copy() {
                            merged.set(key, value);
                        }
                        merged.set(
                            str_key(document::COLLECTION),
                            old.raw(document::COLLECTION).cloned().unwrap_or(Value::Null),
                        );
                        merged.set(str_key(document::SEQUENCE), old.sequence().cloned().unwrap_or(Value::Null));
                        if !merged.get_str(document::ID).is_some_and(|v| v.strict_eq(&Value::Str(Str::from(old.id()))))
                        {
                            skip_permissions_update = false;
                        }
                        let created_at = match created_at {
                            Some(c) if self.is_preserving_dates() => Value::Str(Str::from(c)),
                            _ => old.created_at().map_or(Value::Null, |c| Value::Str(Str::from(c))),
                        };
                        merged.set(str_key(document::CREATED_AT), created_at);
                        if self.inner.adapter.shared_tables() {
                            merged.set(str_key(document::TENANT), old.tenant().unwrap_or(Value::Null));
                        }
                        let document = Document::new(merged)?;

                        // Ahead of change detection: a dropped attribute is never persisted, so
                        // counting it as a change would bump $updatedAt and fire an update event
                        // for a write that leaves the stored document identical.
                        let mut document = self.remove_unknown_attributes(collection, document, None);

                        let unchanged = self.round_operators(collection, &mut document)?;
                        keep_stored(&mut document, &old, &unchanged);

                        let mut should_update = false;

                        if collection.id() != METADATA {
                            should_update = self.changes(collection, &old, &document)?;

                            let security = document_security(collection);
                            let mut update_permissions = collection.permissions_by_type(PermissionType::Update)?;
                            let mut read_permissions = collection.permissions_by_type(PermissionType::Read)?;
                            if security {
                                update_permissions.extend(old.permissions_by_type(PermissionType::Update)?);
                                read_permissions.extend(old.permissions_by_type(PermissionType::Read)?);
                            }
                            if should_update {
                                if !authorization.is_valid(&Input::new(PermissionType::Update, update_permissions)) {
                                    return Err(Error::authorization(authorization.description()));
                                }
                            } else if !authorization.is_valid(&Input::new(PermissionType::Read, read_permissions)) {
                                return Err(Error::authorization(authorization.description()));
                            }
                        }

                        if should_update {
                            let updated_at = match new_updated_at {
                                Some(u) if self.is_preserving_dates() => u.clone(),
                                _ => time,
                            };
                            document.put(document::UPDATED_AT, Value::Str(Str::from(updated_at)));
                        }

                        // Check if document was updated after the request timestamp
                        self.assert_not_updated_after_request(old.updated_at(), false)?;

                        let mut stored_attributes = Vec::new();
                        if self.is_validating() && collection.id() != METADATA {
                            for (key, value) in document.iter() {
                                let key = key_text(&key);
                                let previous = old.get(&key).cloned().unwrap_or(Value::Null);
                                if old.has(&key) && values_equal(value, &previous) {
                                    stored_attributes.push(key);
                                }
                            }
                        }

                        let mut document = self.encode(collection, document, true).await?;

                        if self.is_validating() {
                            let validator = Structure::new(collection.document().clone(), self.profile())
                                .current_document(Some(old.clone()))
                                .stored_attributes(stored_attributes);
                            // Make sure the updated structure still applies the collection's rules.
                            if !validator.validate(&mut document)? {
                                return Err(Error::structure(validator.description()));
                            }
                        }

                        if let Some(hook) = self.relationship_hook().filter(|h| h.is_enabled()) {
                            document = self.silent(hook.after_document_update(collection, &old, document)).await?;
                        }

                        let has_operators = document.iter().any(|(_, v)| Operator::is_operator(v));

                        let document = self.cast_before(collection, document)?;

                        authorization
                            .skip(self.inner.adapter.update_document(
                                collection,
                                old.id(),
                                document.clone(),
                                skip_permissions_update,
                            ))
                            .await?;

                        let mut document = self.cast_after_document(collection, document)?;

                        let mut purged: Vec<String> = Vec::new();
                        for purged_id in [id, old.id(), document.id()] {
                            if !purged.iter().any(|p| p == purged_id) {
                                purged.push(purged_id.to_owned());
                            }
                        }
                        for purged_id in &purged {
                            self.purge_cached_document_internal(collection.id(), Some(purged_id)).await?;
                        }
                        for purged_id in &purged {
                            self.queue_document_purge(collection.id(), purged_id).await?;
                        }

                        if has_operators {
                            let refetched =
                                self.refetch_documents(collection, vec![document.clone()], Vec::new()).await?;
                            document = refetched.into_iter().next().unwrap_or(document);
                        }

                        Ok((document, has_operators))
                    }
                })
                .await?;

            if document.is_empty() {
                return Ok(document);
            }

            if let Some(hook) = self.relationship_hook()
                && !hook.is_in_batch_population()
                && hook.is_enabled()
            {
                let depth = hook.fetch_depth();
                let populated = self
                    .silent(self.skip_query_hooks(hook.populate_documents(
                        vec![document],
                        &collection,
                        depth,
                        Vec::new(),
                    )))
                    .await?;
                document = populated.into_iter().next().unwrap_or_default();
            }

            if !has_operators {
                document = self.decode(&collection, document, &[]).await?;
            }

            // Convert to the custom document type when one is mapped.
            if self.document_type(collection.id()).is_some() {
                document = self.new_document(collection.id(), document.array_copy())?;
            }

            let document = self.decorate_document(Event::DocumentUpdate, &collection, document).await?;

            let listeners = self.listens(Event::DocumentUpdate);
            if !listeners.is_empty() {
                let event =
                    Domain::DocumentUpdated { collection: collection.id().to_owned(), document: document.clone() };
                self.dispatch(&event, &listeners).await?;
            }

            Ok(document)
        })
    }

    /// Whether `document` changes the stored `old` (the change detection of
    /// `updateDocument()`): an operator, a relationship now linking other
    /// documents, or any other value that differs.
    ///
    /// Fails ([`crate::Kind::Relationship`]) for a to-many relationship value
    /// that is not a list.
    fn changes(&self, collection: &Collection, old: &Document, document: &Document) -> Result<bool> {
        let relationships: Vec<&Attribute> =
            collection.attributes()?.iter().filter(|a| a.relationship.is_some()).collect();

        if document.iter().any(|(_, v)| Operator::is_operator(v)) {
            return Ok(true);
        }

        let hook = self.relationship_hook();
        let internal = [document::COLLECTION, document::TENANT, document::SEQUENCE];

        // Compare whether the document has any changes.
        for (key, value) in document.iter() {
            let key = key_text(&key);
            if internal.contains(&key.as_str()) {
                continue;
            }

            let old_value = old.get(&key).cloned().unwrap_or(Value::Null);

            let declared = relationships.iter().find(|a| a.key == key);
            if let Some(attribute) = declared
                && let Some(relationship) = &attribute.relationship
            {
                let kind = relationship.kind;
                let side = attribute.side;
                let parent_many_to_one = kind == RelationshipType::ManyToOne && side == Some(RelationshipSide::Parent);
                let child_one_to_many = kind == RelationshipType::OneToMany && side == Some(RelationshipSide::Child);
                let stores_key = kind == RelationshipType::OneToOne || parent_many_to_one || child_one_to_many;

                if !stores_key && hook.as_ref().is_some_and(|h| h.write_stack_count() >= RELATION_MAX_DEPTH - 1) {
                    continue;
                }

                if stores_key {
                    if links_another(value, &related_id(&old_value)) {
                        return Ok(true);
                    }
                    continue;
                }

                if Operator::is_operator(value) {
                    return Ok(true);
                }

                let values = match value {
                    Value::Array(values) if values.is_list() => values,
                    _ => {
                        return Err(Error::relationship(format!(
                            "Invalid relationship value. Must be either an array of documents or document IDs, {} given.",
                            value.type_name()
                        )));
                    }
                };

                let old_values: Vec<Value> = match &old_value {
                    Value::Array(a) => a.values().cloned().collect(),
                    other => {
                        return Err(Error::new(
                            Kind::TypeError,
                            format!(
                                "count(): Argument #1 ($value) must be of type Countable|array, {} given",
                                other.debug_type()
                            ),
                        ));
                    }
                };
                if old_values.len() != values.len() {
                    return Ok(true);
                }

                for (relation, stored) in values.values().zip(old_values.iter()) {
                    let stored = related_id(stored);
                    let changed = match relation {
                        Value::Str(_) => !relation.strict_eq(&stored),
                        Value::Ext(Ext::Document(d)) => !Value::Str(Str::from(d.id())).strict_eq(&stored),
                        _ => false,
                    };
                    if changed {
                        return Ok(true);
                    }
                }
                continue;
            }

            // If values are not equal the document must be updated.
            if !values_equal(value, &old_value) {
                return Ok(true);
            }
        }

        Ok(false)
    }

    /// Applies `updates` to every document matching `queries`, in batches of
    /// at most `batch_size` (`updateDocuments()`), handing `on_next` each
    /// updated document and the stored document it updated once its batch
    /// is written; an error it returns aborts the call. Returns how many
    /// were updated.
    ///
    /// Fails ([`crate::Kind::Query`]) for a join or invalid queries,
    /// ([`crate::Kind::Authorization`]) without update permission,
    /// ([`crate::Kind::Structure`]) for invalid updates,
    /// ([`crate::Kind::Conflict`]) for a document changed after the request
    /// timestamp, ([`crate::Kind::Limit`]) for a batch above
    /// [`super::super::BATCH_SIZE`].
    pub async fn update_documents(
        &self,
        collection: &str,
        updates: Document,
        queries: Vec<Query>,
        batch_size: i64,
        on_next: Option<&mut dyn Callback<(Document, Option<Document>)>>,
    ) -> Result<i64> {
        let mut on_next = on_next;
        let mut batch_size = super::joins::batch_size(batch_size)?;

        reject_joins(&queries, "Join queries are not supported for bulk updates")?;

        if updates.is_empty() {
            return Ok(0);
        }

        let collection = self.silent(self.get_collection(collection)).await?;

        let mut updates = updates;
        for key in self.round_operators(&collection, &mut updates)? {
            updates.remove(&key);
        }
        if updates.is_empty() {
            return Ok(0);
        }

        if self.checks_grants()
            && self.relationship_hook().is_some_and(|h| h.is_enabled())
            && holds_related_document(&collection, &updates)?
        {
            return Err(Error::relationship(
                "Invalid relationship value. A bulk update takes related documents by ID while grants are checked.",
            ));
        }

        let security = document_security(&collection);
        let authorization = self.authorization();
        let skip_auth = authorization
            .is_valid(&Input::new(PermissionType::Update, collection.permissions_by_type(PermissionType::Update)?));

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
        let cursor = parsed.cursor.clone();

        if let Some(cursor) = &cursor
            && !cursor.is_empty()
            && cursor.collection() != collection.id()
        {
            return Err(Error::database("Cursor document must be from the same Collection."));
        }

        updates.remove(document::ID);
        updates.remove(document::TENANT);

        match updates.created_at().map(str::to_owned) {
            Some(created_at) if self.is_preserving_dates() => {
                updates.put(document::CREATED_AT, Value::Str(Str::from(created_at)));
            }
            _ => {
                updates.remove(document::CREATED_AT);
            }
        }

        if self.inner.adapter.shared_tables() {
            updates.put(document::TENANT, self.inner.adapter.tenant());
        }

        let updated_at = match updates.updated_at() {
            Some(u) if self.is_preserving_dates() => u.to_owned(),
            _ => crate::datetime::now(),
        };
        updates.put(document::UPDATED_AT, Value::Str(Str::from(updated_at)));

        let decoded_updates = updates.clone();
        let mut updates = self.encode(&collection, updates, false).await?;

        if self.is_validating() {
            let validator = PartialStructure::new(collection.document().clone(), self.profile());
            if !validator.validate(&mut updates)? {
                return Err(Error::structure(validator.description()));
            }
        }

        let has_operators = updates.iter().any(|(_, v)| Operator::is_operator(v));
        let adapter_data = updates.as_array().clone();
        let selections = self.validate_selections(&collection, &parsed.selections)?;
        let decoded_keys: Vec<String> = if selections.is_empty() {
            Vec::new()
        } else {
            let mut keys = selections.clone();
            for key in adapter_data.keys() {
                let key = key_text(&key);
                if !keys.contains(&key) {
                    keys.push(key);
                }
            }
            keys
        };
        let adapter_updates = self.cast_before(&collection, Document::new(adapter_data)?)?;

        let original_limit = limit;
        let mut last = cursor.filter(|c| !c.is_empty());
        let mut modified = 0;

        let mut current_permissions = updates.permissions()?;
        current_permissions.sort();

        loop {
            if limit != 0 && limit < batch_size {
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
                self.silent(self.skip_query_hooks(self.fetch(&collection, new, PermissionType::Update, false))).await?;

            if batch.is_empty() {
                break;
            }

            let old = batch.clone();
            let target = batch_target(&collection, &batch);
            let found = &batch;
            let batch = self
                .with_mutation(Event::DocumentsUpdate, &target, || {
                    let (collection, updates, decoded_updates, adapter_updates, current_permissions) =
                        (&collection, &updates, &decoded_updates, &adapter_updates, &current_permissions);
                    async move {
                        let mut keeps_permissions: Vec<(String, bool)> = Vec::new();
                        let mut written = Vec::with_capacity(found.len());
                        for document in found {
                            let mut skip_permissions_update = true;

                            if updates.has(document::PERMISSIONS) {
                                if !document.has(document::PERMISSIONS) {
                                    return Err(Error::query("Permission document missing in select"));
                                }
                                let mut original = document.permissions()?;
                                original.sort();
                                skip_permissions_update = &original == current_permissions;
                            }

                            // An id repeats across tenants in a tenant-per-document batch: one that
                            // changes its permissions keeps the permission rows of every document
                            // under that id rewritten.
                            match keeps_permissions.iter_mut().find(|(id, _)| id == document.id()) {
                                Some(entry) => entry.1 = skip_permissions_update && entry.1,
                                None => keeps_permissions.push((document.id().to_owned(), skip_permissions_update)),
                            }

                            let mut merged = document.array_copy();
                            for (key, value) in decoded_updates.array_copy() {
                                merged.set(key, value);
                            }
                            let mut new = Document::new(merged)?;

                            // PHP's hook changes `$new` in place; here it hands back the document.
                            if let Some(hook) = self.relationship_hook().filter(|h| h.is_enabled()) {
                                new = self.silent(hook.after_document_update(collection, document, new)).await?;
                            }

                            self.assert_not_updated_after_request(document.updated_at(), true)?;

                            let encoded = self.encode(collection, new, true).await?;
                            written.push(self.cast_before(collection, encoded)?);
                        }

                        let keeps: Vec<String> =
                            keeps_permissions.into_iter().filter(|(_, keeps)| *keeps).map(|(id, _)| id).collect();
                        self.inner
                            .adapter
                            .update_documents(collection, adapter_updates, written.clone(), &keeps)
                            .await?;

                        for document in &written {
                            self.with_document_tenant(
                                document,
                                self.advance_collection_cache_epoch(collection.id(), document.id()),
                            )
                            .await?;
                        }

                        self.queue_document_purges(collection.id(), &written).await?;

                        Ok(written)
                    }
                })
                .await?;

            let batch = if has_operators {
                self.refetch_documents(&collection, batch, parsed.selections.clone()).await?
            } else {
                batch
            };

            // The operator refetch goes through find(), which already decoded every document;
            // decoding again would run each decode filter twice.
            let mut batch = self.cast_after(&collection, batch)?;
            if !has_operators {
                let mut decoded = Vec::with_capacity(batch.len());
                for document in batch {
                    decoded.push(self.decode(&collection, document, &decoded_keys).await?);
                }
                batch = decoded;
            }

            let batch = self.decorate_documents(Event::DocumentsUpdate, &collection, batch).await?;

            let count = batch.len() as i64;
            for (document, previous) in batch.iter().cloned().zip(old) {
                if let Some(callback) = on_next.as_deref_mut() {
                    callback.call((document, Some(previous))).await?;
                }
                modified += 1;
            }

            if count < batch_size || (original_limit != 0 && modified == original_limit) {
                break;
            }

            last = batch.last().cloned();
        }

        let listeners = self.listens(Event::DocumentsUpdate);
        if !listeners.is_empty() {
            let event = Domain::DocumentBatchUpdated { collection: collection.id().to_owned(), count: modified };
            self.dispatch(&event, &listeners).await?;
        }

        Ok(modified)
    }
}

/// Fails ([`crate::Kind::Query`]) with `message` when a query joins
/// (`rejectJoins()`).
pub(crate) fn reject_joins(queries: &[Query], message: &str) -> Result<()> {
    if queries.iter().any(|q| q.method().is_join()) {
        return Err(Error::query(message));
    }
    Ok(())
}

/// Sets each of `keys` back to its stored value, or removes it when the
/// stored document lacks it (`keepStored()`).
pub(crate) fn keep_stored(document: &mut Document, stored: &Document, keys: &[String]) {
    for key in keys {
        match stored.raw(key) {
            Some(value) => {
                document.put(key.as_str(), value.clone());
            }
            None => {
                document.remove(key);
            }
        }
    }
}

/// A string array key.
fn str_key(key: &str) -> crate::value::ArrayKey {
    crate::value::ArrayKey::Str(Str::from(key))
}

/// The id a related value names: a document's id, any other value as it is.
fn related_id(value: &Value) -> Value {
    match value {
        Value::Ext(Ext::Document(d)) => Value::Str(Str::from(d.id())),
        other => other.clone(),
    }
}

/// Whether a single relationship value links another document than `old`
/// (an id or `null`).
fn links_another(value: &Value, old: &Value) -> bool {
    (matches!(value, Value::Null) != matches!(old, Value::Null))
        || (matches!(value, Value::Str(_)) && !value.strict_eq(old))
        || matches!(value, Value::Ext(Ext::Document(d)) if !Value::Str(Str::from(d.id())).strict_eq(old))
}
