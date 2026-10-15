//! Documents: reads, writes, upserts, counts, sums and sequences.
//!
//! PHP: `Mongo::getDocument`, `createDocument`, `createDocuments`,
//! `insertDocument`, `storedSequences`, `updateDocument`, `updateDocuments`,
//! `updateWithPipeline`, `executeUpsert`, `upsertDocuments`,
//! `getUpsertAttributeRemovals`, `deleteDocument`, `deleteDocuments`,
//! `increaseDocumentAttribute`, `normalizeAtomicNumber`, `find`, `count`,
//! `sum`, `getSequences`, `collectSequences`, `sequenceKey`.

use std::collections::HashSet;

use utopia_mongo::bson::{Bson, Document as BsonDocument};
use utopia_mongo::{Session, Upsert};
use utopia_query::{CursorDirection, Method, OrderDirection};

use super::casting::{self, read_casts};
use super::filters::{assoc, empty, map, remove_null_keys, replace_characters, replace_internal_ids_keys, stringify};
use super::{BATCH_SIZE, Mongo, batch, bson, from_client, is_mongo, key, process_exception, text};
use crate::adapter::{Adapter, Find, attribute_selections, filter};
use crate::capability::Capability;
use crate::change::Change;
use crate::collection::Collection;
use crate::document::{self, Document};
use crate::error::{Error, Kind, Result};
use crate::permission::PermissionType;
use crate::query::Query;
use crate::storage;
use crate::validator::big_int::BigInt;
use crate::value::{Array, ArrayKey, Str, Value};

/// A list value.
fn list(items: Vec<Value>) -> Value {
    Value::Array(Array::from_list(items))
}

/// `$array[$name] ?? null`.
fn get(array: &Array, name: &str) -> Value {
    array.get_str(name).cloned().unwrap_or(Value::Null)
}

/// `$array[$name]` set when it is not null (`isset`).
fn isset(array: &Array, name: &str) -> bool {
    array.get_str(name).is_some_and(|v| !matches!(v, Value::Null))
}

/// `(array) $row` of a decoded row.
fn fields(row: Value) -> Array {
    match row {
        Value::Object(a) | Value::Array(a) => a,
        _ => Array::new(),
    }
}

/// `$value->{$name} ?? null` of a decoded row.
fn field(row: &Value, name: &str) -> Value {
    match row {
        Value::Object(a) | Value::Array(a) => get(a, name),
        _ => Value::Null,
    }
}

/// A record read by `find()` as a document: keys restored, objects as
/// arrays.
fn found(row: Value) -> Result<Document> {
    let record = replace_characters("_", "$", fields(row));
    let record = match casting::std_class_to_array(Value::Array(record)) {
        Value::Array(a) => a,
        _ => Array::new(),
    };
    Document::from_storage(record)
}

/// `sequenceKey($tenant, $id)`: `_uid` is unique only per tenant under
/// shared tables, so a batch spanning tenants matches each row back to the
/// document of the same tenant.
fn sequence_key(tenant: &Value, id: &Str) -> Vec<u8> {
    let mut k = match tenant {
        Value::Null => Vec::new(),
        other => stringify(other).as_bytes().to_vec(),
    };
    k.push(0);
    k.extend_from_slice(id.as_bytes());
    k
}

/// The values of `values`, first occurrences only (`array_unique()`, which
/// compares as strings).
fn unique(values: Vec<Value>) -> Vec<Value> {
    let mut seen: HashSet<Vec<u8>> = HashSet::new();
    values
        .into_iter()
        .filter(|v| seen.insert(v.to_php_string().map(|s| s.as_bytes().to_vec()).unwrap_or_default()))
        .collect()
}

impl Mongo {
    /// The documents of a reply's first batch and the rest of its cursor,
    /// killing the cursor when a batch it still holds comes back empty.
    async fn read_cursor(&self, name: &str, reply: BsonDocument) -> Result<Vec<Value>> {
        let (mut rows, mut cursor) = batch(&reply, "firstBatch");
        let outcome: Result<()> = async {
            while let Some(id) = cursor {
                let more = self.driver.get_more(id, name, BATCH_SIZE).await.map_err(|e| self.mongo(e))?;
                let (next, next_id) = batch(&more, "nextBatch");
                if next.is_empty() {
                    break;
                }
                rows.extend(next);
                cursor = next_id;
            }
            Ok(())
        }
        .await;
        if let Some(id) = cursor {
            let command = utopia_mongo::bson::doc! { "killCursors": name, "cursors": [utopia_mongo::client::int(id)] };
            let _ = self.driver.query(None, command, None).await;
        }
        outcome.map(|()| rows)
    }

    /// `getDocument($collection, $id, $queries)`: the stored document, its
    /// values read back by type; an empty document when missing.
    pub(crate) async fn get_document_impl(
        &self,
        collection: &Collection,
        id: &str,
        queries: &[Query],
    ) -> Result<Document> {
        let name = self.name(collection.id());
        let filters = self.apply_tenant_filter(map([(storage::UID, text(id))]), collection.id());
        let selections = attribute_selections(queries);
        let projection =
            !selections.is_empty() && !selections.iter().any(|s| crate::value::from_query(s).loose_eq(&text("*")));
        let mut fields = BsonDocument::new();
        if projection {
            fields.insert("projection", bson::document(&Self::attribute_projection(&selections))?);
        }
        let reply = self
            .driver
            .find(&name, bson::document(&filters)?, self.options(fields))
            .await
            .map_err(|e| self.mongo(e))?;
        let (rows, _) = batch(&reply, "firstBatch");
        let Some(row) = rows.into_iter().next() else { return Ok(Document::empty()) };
        let record = replace_characters("_", "$", bson::to_array(&row));
        let document = Document::from_storage(record)?;
        let mut document =
            Self::cast_read(&read_casts(collection)?, self.supports(Capability::DefinedAttributes), document)?;
        if !projection {
            Self::ensure_relationship_defaults(collection, &mut document)?;
        }
        Ok(document)
    }

    /// `createDocument($collection, $document)`: the document as stored.
    pub(crate) async fn create_document_impl(
        &self,
        collection: &Collection,
        mut document: Document,
    ) -> Result<Document> {
        let name = self.name(collection.id());
        let sequence = document.sequence().cloned();
        document.remove(document::SEQUENCE);
        let record = replace_characters("$", "_", document.as_array().clone());
        let mut record = self.decorate_row(record, &document);
        if !empty(sequence.as_ref()) {
            record.set(key(storage::SEQUENCE), sequence.unwrap_or(Value::Null));
        }
        let stored = self.insert_document(&name, remove_null_keys(record)).await?;
        for (k, value) in replace_characters("_", "$", stored) {
            match k {
                ArrayKey::Str(s) if s.as_bytes() == document::PERMISSIONS.as_bytes() => {
                    document.set(s, value)?;
                }
                k => {
                    document.as_array_mut().set(k, value);
                }
            }
        }
        Ok(document)
    }

    /// `insertDocument($name, $document)`: inserts, then reads the document
    /// back.
    async fn insert_document(&self, name: &str, record: Array) -> Result<Array> {
        let document = bson::document(&record)?;
        self.driver.insert(name, document, self.options(BsonDocument::new())).await.map_err(|e| self.mongo(e))?;
        let mut filters = map([(storage::UID, get(&record, storage::UID))]);
        if self.shared_tables() {
            filters.set(key(storage::TENANT), get(&record, storage::TENANT));
        }
        let reply = self
            .driver
            .find(name, bson::document(&filters)?, self.options(utopia_mongo::bson::doc! { "limit": 1 }))
            .await
            .map_err(|e| self.mongo(e))?;
        let (rows, _) = batch(&reply, "firstBatch");
        Ok(rows.into_iter().next().map(|r| bson::to_array(&r)).unwrap_or_default())
    }

    /// `createDocuments($collection, $documents)`: under
    /// `ignoreDuplicates()`, an upsert with `$setOnInsert` per document,
    /// only the documents it inserted returned.
    pub(crate) async fn create_documents_impl(
        &self,
        collection: &Collection,
        documents: Vec<Document>,
    ) -> Result<Vec<Document>> {
        let name = self.name(collection.id());
        let mut records: Vec<Array> = Vec::with_capacity(documents.len());
        let mut has_sequence: Option<bool> = None;
        for document in &documents {
            let sequence = document.sequence().cloned();
            let missing = empty(sequence.as_ref());
            match has_sequence {
                None => has_sequence = Some(!missing),
                Some(has) if has == missing => {
                    return Err(Error::database("All documents must have an sequence if one is set"));
                }
                Some(_) => {}
            }
            let record = replace_characters("$", "_", document.as_array().clone());
            let mut record = self.decorate_row(record, document);
            if !missing {
                record.set(key(storage::SEQUENCE), sequence.unwrap_or(Value::Null));
            }
            records.push(record);
        }
        if self.state.ignoring_duplicates().get() {
            return self.create_documents_ignoring_duplicates(&name, documents, records).await;
        }
        let records: Vec<BsonDocument> = records.iter().map(bson::document).collect::<Result<_>>()?;
        let inserted = self
            .driver
            .insert_many(&name, records, self.options(BsonDocument::new()))
            .await
            .map_err(|e| self.mongo(e))?;
        inserted
            .into_iter()
            .map(|d| {
                let array = bson::to_array(&Value::Object(bson::properties(&d)));
                Document::new(replace_characters("_", "$", array))
            })
            .collect()
    }

    /// `createDocuments()` under `ignoreDuplicates()`: `insertMany` aborts a
    /// transaction on any duplicate, an upsert with `$setOnInsert` does
    /// nothing instead.
    async fn create_documents_ignoring_duplicates(
        &self,
        name: &str,
        documents: Vec<Document>,
        records: Vec<Array>,
    ) -> Result<Vec<Document>> {
        if records.is_empty() {
            return Ok(Vec::new());
        }
        let mut provided: Vec<Value> = Vec::new();
        let mut sequences: Vec<Value> = Vec::with_capacity(records.len());
        let mut updates: Vec<Bson> = Vec::with_capacity(records.len());
        for mut record in records {
            if isset(&record, storage::SEQUENCE) {
                provided.push(get(&record, storage::SEQUENCE));
            } else {
                record.set(key(storage::SEQUENCE), text(&self.driver.create_uuid()));
            }
            sequences.push(get(&record, storage::SEQUENCE));
            let mut filters = map([(
                storage::UID,
                record.get_str(storage::UID).filter(|v| !matches!(v, Value::Null)).cloned().unwrap_or_else(|| text("")),
            )]);
            if self.shared_tables() {
                let tenant = match record.get_str(storage::TENANT) {
                    Some(t) if !matches!(t, Value::Null) => t.clone(),
                    _ => self.tenant(),
                };
                filters.set(key(storage::TENANT), tenant);
            }
            let mut insert = record;
            insert.remove(&key(storage::UID));
            insert.remove(&key(storage::TENANT));
            let update = map([
                ("q", Value::Object(filters)),
                ("u", Value::Object(map([("$setOnInsert", Value::Array(insert))]))),
                ("upsert", Value::Bool(true)),
                ("multi", Value::Bool(false)),
                ("collation", assoc([("locale", text("en")), ("strength", Value::Int(1))])),
            ]);
            updates.push(Bson::Document(bson::document(&update)?));
        }
        let stored = if provided.is_empty() { HashSet::new() } else { self.stored_sequences(name, &provided).await? };
        let command = utopia_mongo::bson::doc! { "update": name, "updates": updates };
        self.driver.query(self.transaction_session().as_ref(), command, None).await.map_err(|e| self.mongo(e))?;
        let mut inserted: HashSet<Vec<u8>> =
            self.stored_sequences(name, &sequences).await?.difference(&stored).cloned().collect();
        let mut created = Vec::new();
        for (document, sequence) in documents.into_iter().zip(sequences.iter()) {
            if inserted.remove(stringify(sequence).as_bytes()) {
                created.push(document);
            }
        }
        Ok(created)
    }

    /// `storedSequences($name, $sequences)`: which of the given `_id`s are
    /// stored.
    async fn stored_sequences(&self, name: &str, sequences: &[Value]) -> Result<HashSet<Vec<u8>>> {
        let filters = map([(storage::SEQUENCE, assoc([("$in", list(sequences.to_vec()))]))]);
        let fields = utopia_mongo::bson::doc! {
            "projection": { storage::SEQUENCE: 1 },
            "batchSize": utopia_mongo::client::int(sequences.len() as i64 + 1),
            "singleBatch": true,
        };
        let reply =
            self.driver.find(name, bson::document(&filters)?, self.options(fields)).await.map_err(|e| self.mongo(e))?;
        let (rows, _) = batch(&reply, "firstBatch");
        Ok(rows.iter().map(|row| stringify(&field(row, storage::SEQUENCE)).as_bytes().to_vec()).collect())
    }

    /// `updateDocument($collection, $id, $document)`.
    pub(crate) async fn update_document_impl(
        &self,
        collection: &Collection,
        id: &str,
        document: Document,
    ) -> Result<Document> {
        let name = self.name(collection.id());
        let mut record = replace_characters("$", "_", document.array_copy());
        let filters = self.apply_tenant_filter(map([(storage::UID, text(id))]), collection.id());
        record.remove(&key(storage::SEQUENCE));
        match Self::operator_pipeline(&record)? {
            Some(pipeline) => {
                self.update_with_pipeline(&name, &filters, pipeline, false).await?;
            }
            None => {
                let update = map([("$set", Value::Array(record))]);
                self.driver
                    .update(
                        &name,
                        bson::document(&filters)?,
                        bson::document(&update)?,
                        self.options(BsonDocument::new()),
                        false,
                    )
                    .await
                    .map_err(|e| self.mongo(e))?;
            }
        }
        Ok(document)
    }

    /// `updateDocuments($collection, $updates, $documents)`: `updates`
    /// applied to every document of `documents` by sequence.
    pub(crate) async fn update_documents_impl(
        &self,
        collection: &Collection,
        updates: &Document,
        documents: &[Document],
    ) -> Result<i64> {
        let name = self.name(collection.id());
        let sequences: Vec<Value> = documents.iter().map(|d| d.sequence().cloned().unwrap_or(Value::Null)).collect();
        let query = Query::new(
            Method::Equal,
            document::SEQUENCE,
            crate::value::to_query_array(&Array::from_list(sequences)),
            "",
        );
        let filters = self.build_filters(&[query], "$and")?;
        let filters = self.apply_tenant_filter(filters, collection.id());
        let record = replace_characters("$", "_", updates.array_copy());
        if let Some(pipeline) = Self::operator_pipeline(&record)? {
            return self.update_with_pipeline(&name, &filters, pipeline, true).await;
        }
        let update = map([("$set", Value::Array(record))]);
        self.driver
            .update(&name, bson::document(&filters)?, bson::document(&update)?, self.options(BsonDocument::new()), true)
            .await
            .map_err(|e| self.mongo(e))
    }

    /// `updateWithPipeline($collection, $filters, $pipeline, $options, $multi)`:
    /// an `update` command whose update is a pipeline; the documents it
    /// matched.
    async fn update_with_pipeline(&self, name: &str, filters: &Array, pipeline: Array, multi: bool) -> Result<i64> {
        let statement = map([
            ("q", Value::Object(filters.clone())),
            ("u", Value::Array(pipeline)),
            ("multi", Value::Bool(multi)),
            ("upsert", Value::Bool(false)),
        ]);
        let command = utopia_mongo::bson::doc! { "update": name, "updates": [bson::document(&statement)?] };
        let session = self.transaction_session();
        let reply = self.driver.query(session.as_ref(), command, None).await.map_err(|e| self.mongo(e))?;
        Ok(reply.count().unwrap_or(0))
    }

    /// `executeUpsert($collection, $operations)`: the upserts as one
    /// `update` command (their updates may be pipelines).
    async fn execute_upsert(
        &self,
        name: &str,
        operations: Vec<(Array, Value)>,
        session: Option<&Session>,
    ) -> Result<i64> {
        let mut updates: Vec<Bson> = Vec::with_capacity(operations.len());
        for (filters, update) in operations {
            let statement = map([
                ("q", Value::Object(filters)),
                ("u", update),
                ("upsert", Value::Bool(true)),
                ("multi", Value::Bool(false)),
            ]);
            updates.push(Bson::Document(bson::document(&statement)?));
        }
        let command = utopia_mongo::bson::doc! { "update": name, "updates": updates };
        let reply = self.driver.query(session, command, None).await.map_err(from_client)?;
        Ok(reply.count().unwrap_or(0))
    }

    /// `getUpsertAttributeRemovals($oldDocument, $newDocument, $record)`: in
    /// schemaless mode, the stored fields of the old document the new one
    /// no longer holds.
    fn upsert_attribute_removals(&self, old: &Document, new: &Document, record: &Array) -> Array {
        let mut unset = Array::new();
        if self.supports(Capability::DefinedAttributes) || old.is_empty() {
            return unset;
        }
        let protected = [
            storage::UID,
            storage::SEQUENCE,
            storage::CREATED_AT,
            storage::UPDATED_AT,
            storage::PERMISSIONS,
            storage::TENANT,
        ];
        let is_protected =
            |k: &ArrayKey| matches!(k, ArrayKey::Str(s) if protected.iter().any(|p| p.as_bytes() == s.as_bytes()));
        let new_attributes = new.attributes();
        for (k, value) in old.attributes() {
            if is_protected(&k) || new_attributes.contains_key(&k) {
                continue;
            }
            let transformed = replace_characters("$", "_", [(k, value)].into_iter().collect());
            let Some(db_key) = transformed.keys().next().map(|k| k.to_owned()) else { continue };
            let truthy = match &db_key {
                ArrayKey::Int(i) => *i != 0,
                ArrayKey::Str(s) => !(s.is_empty() || s.as_bytes() == b"0"),
            };
            if truthy && !record.contains_key(&db_key) && !is_protected(&db_key) {
                unset.set(db_key, text(""));
            }
        }
        unset
    }

    /// `upsertDocuments($collection, $changes, $increase)`.
    pub(crate) async fn upsert_documents_impl(
        &self,
        collection: &Collection,
        changes: Vec<Change>,
        increase: Option<&str>,
    ) -> Result<Vec<Document>> {
        if changes.is_empty() {
            return Ok(Vec::new());
        }
        let name = self.name(collection.id());
        let attribute = filter(increase.unwrap_or_default());
        let mut operations: Vec<(Array, Value)> = Vec::with_capacity(changes.len());
        let mut pipelined = false;
        for change in &changes {
            let document = &change.new;
            let mut attributes = document.attributes();
            attributes.set(key(storage::UID), text(document.id()));
            attributes.set(key(storage::CREATED_AT), get(document.as_array(), document::CREATED_AT));
            attributes.set(key(storage::UPDATED_AT), get(document.as_array(), document::UPDATED_AT));
            let permissions: Vec<Value> = document.permissions()?.into_iter().map(|p| text(&p)).collect();
            attributes.set(key(storage::PERMISSIONS), list(permissions));
            let sequence = document.sequence().cloned();
            if !empty(sequence.as_ref()) {
                attributes.set(key(storage::SEQUENCE), sequence.clone().unwrap_or(Value::Null));
            }
            let mut filters = map([(storage::UID, text(document.id()))]);
            if self.shared_tables() {
                let tenant = document.tenant().unwrap_or_else(|| self.tenant());
                attributes.set(key(storage::TENANT), tenant.clone());
                filters.set(key(storage::TENANT), self.tenant_filters(collection.id(), &[tenant]));
            }
            let record = replace_characters("$", "_", attributes);
            let mut record = self.decorate_row(record, document);
            record.remove(&key(storage::SEQUENCE));
            let mut unset = self.upsert_attribute_removals(&change.old, document, &record);
            let update = if !attribute.is_empty() {
                let value =
                    record.get_str(&attribute).filter(|v| !matches!(v, Value::Null)).cloned().unwrap_or(Value::Int(0));
                record.remove(&key(&attribute));
                unset.remove(&key(&attribute));
                let mut update = map([("$inc", assoc([(attribute.as_str(), value)])), ("$set", Value::Array(record))]);
                if !unset.is_empty() {
                    update.set(key("$unset"), Value::Array(unset));
                }
                Value::Array(update)
            } else if let Some(pipeline) = Self::operator_pipeline(&record)? {
                let mut set = match pipeline.values().next() {
                    Some(Value::Array(stage)) => match stage.get_str("$set") {
                        Some(Value::Array(set)) => set.clone(),
                        _ => Array::new(),
                    },
                    _ => Array::new(),
                };
                if empty(sequence.as_ref()) {
                    set.set(
                        key(storage::SEQUENCE),
                        assoc([("$ifNull", list(vec![text("$_id"), text(&self.driver.create_uuid())]))]),
                    );
                }
                let mut stages = vec![assoc([("$set", Value::Array(set))])];
                if !unset.is_empty() {
                    let names: Vec<Value> =
                        unset.keys().map(|k| Value::Str(Str::from(super::filters::key_text(k)))).collect();
                    stages.push(assoc([("$unset", list(names))]));
                }
                pipelined = true;
                list(stages)
            } else {
                let mut update = map([("$set", Value::Array(record))]);
                if !unset.is_empty() {
                    update.set(key("$unset"), Value::Array(unset));
                }
                if empty(sequence.as_ref()) {
                    update.set(key("$setOnInsert"), assoc([(storage::SEQUENCE, text(&self.driver.create_uuid()))]));
                }
                Value::Array(update)
            };
            operations.push((filters, update));
        }
        let session = self.transaction_session();
        let outcome = if pipelined {
            self.execute_upsert(&name, operations, session.as_ref()).await
        } else {
            let mut upserts = Vec::with_capacity(operations.len());
            for (filters, update) in operations {
                let update = match update {
                    Value::Array(a) => bson::document(&a)?,
                    _ => BsonDocument::new(),
                };
                upserts.push(Upsert { filter: bson::document(&filters)?, update, multi: false });
            }
            self.driver.upsert(&name, upserts, super::options(session, BsonDocument::new())).await.map_err(from_client)
        };
        outcome.map_err(|e| if is_mongo(&e) { process_exception(e) } else { e })?;
        Ok(changes.into_iter().map(|c| c.new).collect())
    }

    /// `deleteDocument($collection, $id)`.
    pub(crate) async fn delete_document_impl(&self, collection: &Collection, id: &str) -> Result<bool> {
        let name = self.name(collection.id());
        let filters = self.apply_tenant_filter(map([(storage::UID, text(id))]), collection.id());
        let deleted = self
            .driver
            .delete(&name, bson::document(&filters)?, 1, BsonDocument::new(), self.options(BsonDocument::new()))
            .await
            .map_err(from_client)?;
        Ok(deleted != 0)
    }

    /// `deleteDocuments($collection, $sequences)`: the documents of the
    /// given sequences.
    pub(crate) async fn delete_documents_impl(&self, collection: &Collection, sequences: Vec<Value>) -> Result<i64> {
        let name = self.name(collection.id());
        let values = crate::value::to_query_array(&Array::from_list(sequences));
        let query = Query::new(Method::Equal, storage::SEQUENCE, values, "");
        let filters = self.build_filters(&[query], "$and")?;
        let filters = self.apply_tenant_filter(filters, collection.id());
        let filters = replace_internal_ids_keys(&filters);
        self.driver
            .delete(&name, bson::document(&filters)?, 0, BsonDocument::new(), self.options(BsonDocument::new()))
            .await
            .map_err(|e| self.mongo(e))
    }

    /// `normalizeAtomicNumber($value, $name)`: a numeric string as an
    /// integer.
    ///
    /// Fails ([`Kind::Type`]) outside the signed 64-bit range.
    fn atomic_number(value: Value, name: &str) -> Result<Value> {
        let Value::Str(s) = &value else { return Ok(value) };
        if !BigInt::fits_php_int(s.as_bytes(), true) {
            return Err(Error::new(
                Kind::Type,
                format!("MongoDB cannot safely apply {name} outside the signed 64-bit integer range."),
            ));
        }
        Ok(Value::Int(php_std::format::str_to_int(s.as_bytes())))
    }

    /// `increaseDocumentAttribute(...)`: `value` added to the attribute
    /// (missing counts as 0) by a pipeline update, only while the current
    /// value is within `min` and `max`, and `_updatedAt` set.
    #[allow(clippy::too_many_arguments)]
    pub(crate) async fn increase_document_attribute_impl(
        &self,
        collection: &Collection,
        id: &str,
        attribute: &str,
        value: Value,
        updated_at: &str,
        min: Option<Value>,
        max: Option<Value>,
    ) -> Result<bool> {
        let value = Self::atomic_number(value, "value")?;
        let min = min.map(|m| Self::atomic_number(m, "minimum")).transpose()?;
        let max = max.map(|m| Self::atomic_number(m, "maximum")).transpose()?;
        let attribute = filter(attribute);
        let current = assoc([("$ifNull", list(vec![text(&format!("${attribute}")), Value::Int(0)]))]);
        let mut filters = self.apply_tenant_filter(map([(storage::UID, text(id))]), collection.id());
        let mut bounds: Vec<Value> = Vec::new();
        if let Some(max) = max {
            bounds.push(assoc([("$lte", list(vec![current.clone(), max]))]));
        }
        if let Some(min) = min {
            bounds.push(assoc([("$gte", list(vec![current.clone(), min]))]));
        }
        if !bounds.is_empty() {
            let expression = if bounds.len() == 1 { bounds.remove(0) } else { assoc([("$and", list(bounds))]) };
            filters.set(key("$expr"), expression);
        }
        let updated_at = casting::utc_datetime(updated_at.as_bytes())?;
        let mut set = Array::new();
        set.set(key(&attribute), assoc([("$add", list(vec![current, value]))]));
        set.set(key(storage::UPDATED_AT), assoc([("$literal", updated_at)]));
        let pipeline = Array::from_list(vec![assoc([("$set", Value::Array(set))])]);
        let name = self.name(collection.id());
        self.update_with_pipeline(&name, &filters, pipeline, false).await?;
        Ok(true)
    }

    /// `find($collection, $queries, ...)`: documents, ordered, paged after
    /// or before a cursor, filtered by the queries, the tenant and the
    /// permission the read needs.
    pub(crate) async fn find_impl(
        &self,
        collection: &Collection,
        queries: Vec<Query>,
        options: Find,
    ) -> Result<Vec<Document>> {
        let name = self.name(collection.id());
        let mut queries = queries;
        Self::escape_query_attributes(collection, &mut queries)?;
        let filters = self.build_filters(&queries, "$and")?;
        let mut filters = self.apply_read_filters(filters, collection.id(), options.for_permission);
        let mut fields = BsonDocument::new();
        if let Some(limit) = options.limit {
            fields.insert("limit", utopia_mongo::client::int(limit));
        }
        if let Some(offset) = options.offset {
            fields.insert("skip", utopia_mongo::client::int(offset));
        }
        let timeout = self.read_timeout();
        if timeout != 0 {
            fields.insert("maxTimeMS", utopia_mongo::client::int(timeout));
        }
        let selections = attribute_selections(&queries);
        let projection =
            !selections.is_empty() && !selections.iter().any(|s| crate::value::from_query(s).loose_eq(&text("*")));
        if projection {
            fields.insert("projection", bson::document(&Self::attribute_projection(&selections))?);
        }
        let mut ors: Vec<Value> = Vec::new();
        let mut sort = BsonDocument::new();
        for (i, original) in options.order_attributes.iter().enumerate() {
            let attribute = filter(Self::internal_key(original));
            let order = options.order_types.get(i).copied().unwrap_or(OrderDirection::Asc);
            let direction = match (options.cursor_direction, order) {
                (CursorDirection::Before, OrderDirection::Asc) => OrderDirection::Desc,
                (CursorDirection::Before, _) => OrderDirection::Asc,
                (_, order) => order,
            };
            sort.insert(attribute.clone(), Self::order(direction)?);
            fields.insert("sort", sort.clone());
            let method = match (options.cursor_direction, order) {
                (CursorDirection::After, OrderDirection::Desc) => Method::LessThan,
                (CursorDirection::After, _) => Method::GreaterThan,
                (_, OrderDirection::Desc) => Method::GreaterThan,
                (_, _) => Method::LessThan,
            };
            let operator = Self::query_operator(method)?;
            if options.cursor.is_empty() {
                continue;
            }
            let mut conditions: Vec<Value> = Vec::new();
            for previous in &options.order_attributes[..i] {
                let field = filter(Self::internal_key(previous));
                conditions.push(assoc([(field.as_str(), get(&options.cursor, previous))]));
            }
            let reference = get(&options.cursor, original);
            if original == document::SEQUENCE && options.order_attributes.len() == 1 {
                filters.set(key(&attribute), assoc([(operator, reference)]));
                break;
            }
            conditions.push(assoc([(attribute.as_str(), assoc([(operator, reference)]))]));
            ors.push(assoc([("$and", list(conditions))]));
        }
        if !ors.is_empty() {
            filters.set(key("$or"), list(ors));
        }
        let filters = replace_internal_ids_keys(&filters);
        fields.insert("batchSize", utopia_mongo::client::int(BATCH_SIZE));
        let reply = self
            .driver
            .find(&name, bson::document(&filters)?, self.options(fields))
            .await
            .map_err(|e| self.mongo(e))?;
        let rows = self.read_cursor(&name, reply).await?;
        let mut documents = rows.into_iter().map(found).collect::<Result<Vec<_>>>()?;
        if options.cursor_direction == CursorDirection::Before {
            documents.reverse();
        }
        if !projection {
            for document in documents.iter_mut() {
                Self::ensure_relationship_defaults(collection, document)?;
            }
        }
        Ok(documents)
    }

    /// The `$match` filter of a count or a sum: the queries (escaped), the
    /// tenant and the read permission.
    fn aggregate_filters(&self, collection: &Collection, queries: Vec<Query>) -> Result<Array> {
        let mut queries = queries;
        Self::escape_query_attributes(collection, &mut queries)?;
        let filters = self.build_filters(&queries, "$and")?;
        Ok(self.apply_read_filters(filters, collection.id(), PermissionType::Read))
    }

    /// The options of a count or a sum: the session and the timeout.
    fn aggregate_options(&self) -> utopia_mongo::Options {
        let mut fields = BsonDocument::new();
        let timeout = self.read_timeout();
        if timeout != 0 {
            fields.insert("maxTimeMS", utopia_mongo::client::int(timeout));
        }
        self.options(fields)
    }

    /// `$cursor->firstBatch[0]->total` of an aggregation reply.
    fn total(reply: &BsonDocument) -> Option<Value> {
        let (rows, _) = batch(reply, "firstBatch");
        let first = rows.into_iter().next()?;
        Some(field(&first, "total")).filter(|v| !matches!(v, Value::Null))
    }

    /// `count($collection, $queries, $max)`: an aggregation (`$match`,
    /// `$limit`, then `$group` with a limit and `$count` without).
    pub(crate) async fn count_impl(
        &self,
        collection: &Collection,
        queries: Vec<Query>,
        max: Option<i64>,
    ) -> Result<i64> {
        let name = self.name(collection.id());
        let filters = self.aggregate_filters(collection, queries)?;
        let mut pipeline: Vec<Value> = Vec::new();
        if !filters.is_empty() {
            pipeline.push(assoc([("$match", Value::Object(filters))]));
        }
        let limited = max.filter(|m| *m > 0);
        if let Some(max) = limited {
            pipeline.push(assoc([("$limit", Value::Int(max))]));
            pipeline.push(assoc([(
                "$group",
                assoc([(storage::SEQUENCE, Value::Null), ("total", assoc([("$sum", Value::Int(1))]))]),
            )]));
        } else {
            pipeline.push(assoc([("$count", text("total"))]));
        }
        let pipeline: Vec<Bson> = pipeline.iter().map(bson::value).collect::<Result<_>>()?;
        let reply =
            self.driver.aggregate(&name, pipeline, self.aggregate_options()).await.map_err(|e| self.mongo(e))?;
        Ok(match Self::total(&reply) {
            Some(Value::Int(i)) => i,
            Some(v) if casting::is_numeric(&v) => casting::int(&v),
            _ => 0,
        })
    }

    /// `sum($collection, $attribute, $queries, $max)`: an aggregation
    /// summing the attribute's stored field; an integer or a float.
    pub(crate) async fn sum_impl(
        &self,
        collection: &Collection,
        attribute: &str,
        queries: Vec<Query>,
        max: Option<i64>,
    ) -> Result<Value> {
        let name = self.name(collection.id());
        let field_name = Self::escaped_attributes(collection)?
            .into_iter()
            .find(|(a, _)| a == attribute)
            .map_or_else(|| attribute.to_owned(), |(_, f)| f);
        let filters = self.aggregate_filters(collection, queries)?;
        let mut pipeline: Vec<Value> = Vec::new();
        if !filters.is_empty() {
            pipeline.push(assoc([("$match", Value::Array(filters))]));
        }
        if let Some(max) = max.filter(|m| *m != 0) {
            pipeline.push(assoc([("$limit", Value::Int(max))]));
        }
        pipeline.push(assoc([(
            "$group",
            assoc([(storage::SEQUENCE, Value::Null), ("total", assoc([("$sum", text(&format!("${field_name}")))]))]),
        )]));
        let pipeline: Vec<Bson> = pipeline.iter().map(bson::value).collect::<Result<_>>()?;
        let reply =
            self.driver.aggregate(&name, pipeline, self.aggregate_options()).await.map_err(|e| self.mongo(e))?;
        Ok(match Self::total(&reply) {
            Some(v @ (Value::Int(_) | Value::Float(_))) => v,
            Some(v) if casting::is_numeric(&v) => Value::Int(casting::int(&v)),
            _ => Value::Int(0),
        })
    }

    /// `getSequences($collection, $documents)`: the documents without a
    /// sequence given the one stored for their id (and tenant).
    pub(crate) async fn get_sequences_impl(
        &self,
        collection: &Collection,
        mut documents: Vec<Document>,
    ) -> Result<Vec<Document>> {
        let shared = self.shared_tables();
        let mut ids: Vec<Value> = Vec::new();
        let mut tenants: Vec<Value> = Vec::new();
        for document in &documents {
            if empty(document.sequence()) {
                ids.push(text(document.id()));
                if shared {
                    tenants.push(document.tenant().unwrap_or_else(|| self.tenant()));
                }
            }
        }
        if ids.is_empty() {
            return Ok(documents);
        }
        let name = self.name(collection.id());
        let mut filters = map([(storage::UID, assoc([("$in", list(unique(ids)))]))]);
        if shared {
            filters.set(key(storage::TENANT), self.tenant_filters(collection.id(), &unique(tenants)));
        }
        let fields = utopia_mongo::bson::doc! {
            "projection": { storage::UID: 1, storage::SEQUENCE: 1, storage::TENANT: 1 },
            "batchSize": utopia_mongo::client::int(BATCH_SIZE),
        };
        let reply = self
            .driver
            .find(&name, bson::document(&filters)?, self.options(fields))
            .await
            .map_err(|e| self.mongo(e))?;
        let (mut rows, mut cursor) = batch(&reply, "firstBatch");
        while let Some(id) = cursor {
            let more = self.driver.get_more(id, &name, BATCH_SIZE).await.map_err(|e| self.mongo(e))?;
            let (next, next_id) = batch(&more, "nextBatch");
            if next.is_empty() {
                break;
            }
            rows.extend(next);
            cursor = next_id;
        }
        let mut sequences: Vec<(Vec<u8>, Str)> = Vec::new();
        for row in &rows {
            let tenant = if shared { field(row, storage::TENANT) } else { Value::Null };
            let k = sequence_key(&tenant, &stringify(&field(row, storage::UID)));
            let sequence = stringify(&field(row, storage::SEQUENCE));
            match sequences.iter_mut().find(|(existing, _)| *existing == k) {
                Some(entry) => entry.1 = sequence,
                None => sequences.push((k, sequence)),
            }
        }
        for document in documents.iter_mut() {
            let tenant = if shared { document.tenant().unwrap_or_else(|| self.tenant()) } else { Value::Null };
            let k = sequence_key(&tenant, &Str::from(document.id()));
            if let Some((_, sequence)) = sequences.iter().find(|(existing, _)| *existing == k) {
                document.put(document::SEQUENCE, Value::Str(sequence.clone()));
            }
        }
        Ok(documents)
    }
}
