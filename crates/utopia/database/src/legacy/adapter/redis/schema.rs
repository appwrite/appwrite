//! Databases, collections, attributes, indexes and relationships of the
//! [`Redis`] adapter: the meta hash of each collection and the scans that
//! rewrite or drop its keys.

use super::{Redis, SCAN_BATCH_SIZE, SEP, json_decode, raw_error, tx_error};
use crate::adapter::Adapter;
use crate::database::Database;
use crate::document::Document;
use crate::error::{Error, Result};
use crate::value::{Array, ArrayKey, Value, to_text};

/// `(string) ($record['$id'] ?? $record['key'] ?? '')`.
fn record_id(record: &Array) -> String {
    match record.get_str("$id").filter(|v| !matches!(v, Value::Null)) {
        Some(v) => to_text(v),
        None => record.get_str("key").filter(|v| !matches!(v, Value::Null)).map(to_text).unwrap_or_default(),
    }
}

/// An attribute record of the meta hash.
fn attribute_record(id: &str, kind: &str, size: i64, signed: bool, array: bool, required: bool) -> Array {
    [
        ("$id", Value::from(id)),
        ("key", Value::from(id)),
        ("type", Value::from(kind)),
        ("size", Value::Int(size)),
        ("signed", Value::Bool(signed)),
        ("array", Value::Bool(array)),
        ("required", Value::Bool(required)),
    ]
    .into_iter()
    .map(|(k, v)| (ArrayKey::from(k), v))
    .collect()
}

impl Redis {
    // ----- Databases -----

    /// `create()`: the name joins the namespace's database set.
    pub(super) async fn create_database(&self, name: &str) -> Result<bool> {
        let name = self.filter(name)?;
        let dbs = format!("{}{SEP}dbs", self.ns_base());
        let _: i64 = self.run(redis::cmd("SADD").arg(dbs).arg(name)).await.map_err(tx_error)?;
        Ok(true)
    }

    /// `exists()`.
    pub(super) async fn exists_in(&self, database: &str, collection: Option<&str>) -> Result<bool> {
        let database = self.filter(database)?;
        let dbs = format!("{}{SEP}dbs", self.ns_base());
        let member: i64 = self.run(redis::cmd("SISMEMBER").arg(dbs).arg(&database)).await.map_err(raw_error)?;
        if member == 0 {
            return Ok(false);
        }
        let Some(collection) = collection else {
            return Ok(true);
        };
        let collection = self.filter(collection)?;
        let cols = format!("{}{SEP}cols", Self::ns_for(&self.namespace(), &database));
        let member: i64 = self.run(redis::cmd("SISMEMBER").arg(cols).arg(collection)).await.map_err(raw_error)?;
        Ok(member != 0)
    }

    /// `list()`: the namespace's databases, as `{name}` documents.
    pub(super) async fn list_databases(&self) -> Result<Vec<Document>> {
        let dbs = format!("{}{SEP}dbs", self.ns_base());
        let names: Vec<String> = self.run(redis::cmd("SMEMBERS").arg(dbs)).await.map_err(raw_error)?;
        Ok(names
            .into_iter()
            .map(|name| {
                Document::from_array_unchecked(
                    [(ArrayKey::from("name"), Value::from(name.as_str()))].into_iter().collect(),
                )
            })
            .collect())
    }

    /// `delete()`: every collection's keys, the collection set, and the name.
    pub(super) async fn delete_database(&self, name: &str) -> Result<bool> {
        let name = self.filter(name)?;
        let namespace = self.namespace();
        let dbs = format!("{}{SEP}dbs", self.ns_base());
        let cols = format!("{}{SEP}cols", Self::ns_for(&namespace, &name));
        let collections: Vec<String> = self.run(redis::cmd("SMEMBERS").arg(&cols)).await.map_err(tx_error)?;
        for collection in collections {
            self.purge_collection_keys(&namespace, &name, &collection).await?;
        }
        let _: i64 = self.run(redis::cmd("DEL").arg(&cols)).await.map_err(tx_error)?;
        let _: i64 = self.run(redis::cmd("SREM").arg(&dbs).arg(&name)).await.map_err(tx_error)?;
        Ok(true)
    }

    // ----- Collections -----

    /// `createCollection()`: the meta hash and the collection's membership.
    pub(super) async fn create_collection_keys(
        &self,
        name: &str,
        attributes: &[Document],
        indexes: &[Document],
    ) -> Result<bool> {
        let id = self.filter(name)?;
        let cols = self.key(&["cols"]);
        let meta = self.meta_key(&id);
        let idx = self.idx_key(&id, None);
        if self.exists_key(&meta).await? {
            return Err(Error::duplicate("Collection already exists"));
        }
        let attribute_payload: Vec<Value> = attributes.iter().map(|a| Value::Array(a.array_copy(&[], &[]))).collect();
        let index_payload: Vec<Value> = indexes.iter().map(|i| Value::Array(i.array_copy(&[], &[]))).collect();
        let schema: Array = [
            ("$id", Value::from(id.as_str())),
            ("name", Value::from(name)),
            ("attributes", Value::Array(attribute_payload.iter().cloned().collect())),
            ("indexes", Value::Array(index_payload.iter().cloned().collect())),
        ]
        .into_iter()
        .map(|(k, v)| (ArrayKey::from(k), v))
        .collect();
        let schema = Document::new(schema)?;
        let schema_json = Self::meta_json(&Value::Array(schema.array_copy(&[], &[])))?;
        let attrs_json = Self::meta_json(&Value::Array(attribute_payload.into_iter().collect()))?;
        let indexes_json = Self::meta_json(&Value::Array(index_payload.into_iter().collect()))?;
        let mut hmset = redis::cmd("HMSET");
        hmset
            .arg(&meta)
            .arg("schema")
            .arg(schema_json)
            .arg("attrs")
            .arg(attrs_json)
            .arg("indexes")
            .arg(indexes_json)
            .arg("docCount")
            .arg("0")
            .arg("sizeBytes")
            .arg("0");
        let _: () = self.run(&hmset).await.map_err(tx_error)?;
        let _: i64 = self.run(redis::cmd("DEL").arg(&idx)).await.map_err(tx_error)?;
        let _: i64 = self.run(redis::cmd("SADD").arg(&cols).arg(&id)).await.map_err(tx_error)?;
        Ok(true)
    }

    /// `deleteCollection()`.
    pub(super) async fn delete_collection_keys(&self, id: &str) -> Result<bool> {
        let id = self.filter(id)?;
        let (namespace, database) = (self.namespace(), self.database());
        let cols = self.key(&["cols"]);
        self.purge_collection_keys(&namespace, &database, &id).await?;
        let _: i64 = self.run(redis::cmd("SREM").arg(&cols).arg(&id)).await.map_err(tx_error)?;
        Ok(true)
    }

    /// Every key of a collection: its documents and their permission hashes
    /// (by its id set), the tenant-bucketed documents, id sets and
    /// sequences, its permission sets, then the meta hash, id set and
    /// sequence (`purgeCollectionKeys()`).
    async fn purge_collection_keys(&self, namespace: &str, database: &str, collection: &str) -> Result<()> {
        let collection = self.filter(collection)?;
        let prefix = Self::ns_for(namespace, database);
        let at = |parts: &[&str]| {
            let mut key = prefix.clone();
            for p in parts {
                key.push_str(SEP);
                key.push_str(p);
            }
            key
        };
        let meta = at(&["meta", &collection]);
        let idx = at(&["idx", &collection]);
        let seq = at(&["seq", &collection]);
        let ids: Vec<String> = self.run(redis::cmd("SMEMBERS").arg(&idx)).await.map_err(tx_error)?;
        let mut keys: Vec<String> = Vec::new();
        for id in ids {
            keys.push(at(&["doc", &collection, &id]));
            keys.push(at(&["perm", "doc", &collection, &id]));
            if keys.len() >= SCAN_BATCH_SIZE {
                let _: i64 = self.run(redis::cmd("DEL").arg(&keys)).await.map_err(tx_error)?;
                keys.clear();
            }
        }
        if !keys.is_empty() {
            let _: i64 = self.run(redis::cmd("DEL").arg(&keys)).await.map_err(tx_error)?;
        }
        self.delete_by_pattern(&format!("{prefix}{SEP}doc{SEP}t{SEP}*{SEP}{collection}{SEP}*")).await?;
        self.delete_by_pattern(&format!("{prefix}{SEP}idx{SEP}t{SEP}*{SEP}{collection}")).await?;
        self.delete_by_pattern(&format!("{prefix}{SEP}seq{SEP}t{SEP}*{SEP}{collection}")).await?;
        self.delete_by_pattern(&format!("{}{SEP}*", at(&["perm", &collection]))).await?;
        self.delete_by_pattern(&format!("{prefix}{SEP}perm{SEP}t{SEP}*{SEP}{collection}{SEP}*")).await?;
        self.delete_by_pattern(&format!("{prefix}{SEP}perm{SEP}t{SEP}*{SEP}doc{SEP}{collection}{SEP}*")).await?;
        self.delete_by_pattern(&format!("{}{SEP}*", at(&["tenants", &collection]))).await?;
        let _: i64 = self.run(redis::cmd("DEL").arg(&meta).arg(&idx).arg(&seq)).await.map_err(tx_error)?;
        Ok(())
    }

    /// Deletes the keys a glob matches, `SCAN`ning in batches
    /// (`deleteByPattern()`).
    async fn delete_by_pattern(&self, pattern: &str) -> Result<()> {
        let mut cursor: u64 = 0;
        loop {
            let (next, batch): (u64, Vec<String>) = self
                .run(redis::cmd("SCAN").arg(cursor).arg("MATCH").arg(pattern).arg("COUNT").arg(SCAN_BATCH_SIZE))
                .await
                .map_err(tx_error)?;
            if !batch.is_empty() {
                let _: i64 = self.run(redis::cmd("DEL").arg(&batch)).await.map_err(tx_error)?;
            }
            if next == 0 {
                return Ok(());
            }
            cursor = next;
        }
    }

    /// The memory of a collection's keys (`computeCollectionSize()`): its
    /// meta hash, id set, documents and their permission hashes, and its
    /// permission sets; `0` for a missing collection.
    pub(super) async fn collection_size(&self, collection: &str) -> Result<i64> {
        let collection = self.filter(collection)?;
        let meta = self.meta_key(&collection);
        if !self.exists_key(&meta).await? {
            return Ok(0);
        }
        let mut total = self.measure_key(&meta).await?;
        let idx = self.idx_key(&collection, None);
        total += self.measure_key(&idx).await?;
        let ids: Vec<String> = self.run(redis::cmd("SMEMBERS").arg(&idx)).await.map_err(raw_error)?;
        for id in ids {
            total += self.measure_key(&self.doc_key(&collection, &id, None)).await?;
            total += self.measure_key(&self.perm_doc_key(&collection, &id)).await?;
        }
        let pattern = match self.tenant_bucket() {
            Some(bucket) => format!("{}{SEP}*", self.key(&["perm", "t", &bucket, &collection])),
            None => format!("{}{SEP}*", self.key(&["perm", &collection])),
        };
        let mut cursor: u64 = 0;
        loop {
            let (next, batch): (u64, Vec<String>) = self
                .run(redis::cmd("SCAN").arg(cursor).arg("MATCH").arg(&pattern).arg("COUNT").arg(SCAN_BATCH_SIZE))
                .await
                .map_err(raw_error)?;
            for key in batch {
                total += self.measure_key(&key).await?;
            }
            if next == 0 {
                return Ok(total);
            }
            cursor = next;
        }
    }

    /// The bytes Redis reports for a key (`MEMORY USAGE`), or its encoded
    /// length when it cannot (`measureKey()`).
    async fn measure_key(&self, key: &str) -> Result<i64> {
        if let Ok(redis::Value::Int(usage)) = self.run::<redis::Value>(redis::cmd("MEMORY").arg("USAGE").arg(key)).await
        {
            return Ok(usage);
        }
        let kind: String = self.run(redis::cmd("TYPE").arg(key)).await.map_err(raw_error)?;
        Ok(match kind.as_str() {
            "string" => {
                let value: Option<Vec<u8>> = self.run(redis::cmd("GET").arg(key)).await.map_err(raw_error)?;
                value.map(|v| (v.len() + key.len()) as i64).unwrap_or(0)
            }
            "hash" => {
                let entries: Vec<(Vec<u8>, Vec<u8>)> =
                    self.run(redis::cmd("HGETALL").arg(key)).await.map_err(raw_error)?;
                (key.len() + entries.iter().map(|(f, v)| f.len() + v.len()).sum::<usize>()) as i64
            }
            "set" => {
                let members: Vec<Vec<u8>> = self.run(redis::cmd("SMEMBERS").arg(key)).await.map_err(raw_error)?;
                (key.len() + members.iter().map(Vec::len).sum::<usize>()) as i64
            }
            _ => 0,
        })
    }

    // ----- Meta fields -----

    /// The `attrs` field of a meta hash, as a list of records
    /// (`readAttributesField()`).
    pub(super) async fn read_attributes(&self, meta: &str) -> Result<Vec<Array>> {
        self.read_records(meta, "attrs", true).await
    }

    /// The `indexes` field of a meta hash (`readIndexesField()`).
    pub(super) async fn read_indexes(&self, meta: &str) -> Result<Vec<Array>> {
        self.read_records(meta, "indexes", false).await
    }

    async fn read_records(&self, meta: &str, field: &str, values: bool) -> Result<Vec<Array>> {
        let raw: Option<Vec<u8>> = self.run(redis::cmd("HGET").arg(meta).arg(field)).await.map_err(tx_error)?;
        let Some(raw) = raw.filter(|r| !r.is_empty()) else {
            return Ok(Vec::new());
        };
        let decoded = json_decode(&raw)?;
        let Value::Array(list) = decoded else {
            // `array_values()` of a non-array is a TypeError; the indexes
            // field is returned as is unless it is an array.
            return Ok(Vec::new());
        };
        let _ = values;
        Ok(list
            .values()
            .map(|v| match v {
                Value::Array(a) => a.clone(),
                _ => Array::new(),
            })
            .collect())
    }

    /// Writes a meta field.
    async fn write_records(&self, meta: &str, field: &str, records: &[Array]) -> Result<()> {
        let json = Self::meta_json(&Value::Array(records.iter().map(|r| Value::Array(r.clone())).collect()))?;
        let _: i64 = self.run(redis::cmd("HSET").arg(meta).arg(field).arg(json)).await.map_err(tx_error)?;
        Ok(())
    }

    // ----- Attributes -----

    /// `createAttribute()`: its record in the meta hash.
    #[allow(clippy::too_many_arguments)]
    pub(super) async fn put_attribute(
        &self,
        collection: &str,
        id: &str,
        kind: &str,
        size: i64,
        signed: bool,
        array: bool,
        required: bool,
    ) -> Result<bool> {
        let collection = self.filter(collection)?;
        let id = self.filter(id)?;
        let meta = self.meta_key(&collection);
        if !self.exists_key(&meta).await? {
            return Err(Error::not_found("Collection not found"));
        }
        let attrs = self.read_attributes(&meta).await?;
        let attrs = upsert_record(attrs, attribute_record(&id, kind, size, signed, array, required));
        self.write_records(&meta, "attrs", &attrs).await?;
        Ok(true)
    }

    /// `updateAttribute()`: renamed first when `new_key` differs, then its
    /// record replaced.
    #[allow(clippy::too_many_arguments)]
    pub(super) async fn change_attribute(
        &self,
        collection: &str,
        id: &str,
        kind: &str,
        size: i64,
        signed: bool,
        array: bool,
        new_key: Option<&str>,
        required: bool,
    ) -> Result<bool> {
        let collection = self.filter(collection)?;
        let mut id = self.filter(id)?;
        let meta = self.meta_key(&collection);
        if !self.exists_key(&meta).await? {
            return Err(Error::not_found("Collection not found"));
        }
        if let Some(new_key) = new_key.filter(|k| !k.is_empty() && *k != "0" && *k != id) {
            self.rename_attribute_keys(&collection, &id, new_key).await?;
            id = self.filter(new_key)?;
        }
        let attrs = self.read_attributes(&meta).await?;
        let attrs = upsert_record(attrs, attribute_record(&id, kind, size, signed, array, required));
        self.write_records(&meta, "attrs", &attrs).await?;
        Ok(true)
    }

    /// `deleteAttribute()`: its record, then the field of every document.
    pub(super) async fn drop_attribute(&self, collection: &str, id: &str) -> Result<bool> {
        let collection = self.filter(collection)?;
        let id = self.filter(id)?;
        let meta = self.meta_key(&collection);
        if !self.exists_key(&meta).await? {
            return Ok(true);
        }
        let attrs = self.read_attributes(&meta).await?;
        let mut kept = Vec::with_capacity(attrs.len());
        for attribute in attrs {
            if self.filter(&record_id(&attribute))? == id {
                continue;
            }
            kept.push(attribute);
        }
        self.write_records(&meta, "attrs", &kept).await?;
        self.drop_document_field(&collection, &id).await?;
        Ok(true)
    }

    /// `renameAttribute()`: its record, then the field of every document.
    pub(super) async fn rename_attribute_keys(&self, collection: &str, old: &str, new: &str) -> Result<bool> {
        let collection = self.filter(collection)?;
        let old = self.filter(old)?;
        let new = self.filter(new)?;
        let meta = self.meta_key(&collection);
        if !self.exists_key(&meta).await? {
            return Err(Error::not_found("Collection not found"));
        }
        let mut attrs = self.read_attributes(&meta).await?;
        let mut touched = false;
        for attribute in attrs.iter_mut() {
            if self.filter(&record_id(attribute))? != old {
                continue;
            }
            attribute.set(ArrayKey::from("$id"), Value::from(new.as_str()));
            attribute.set(ArrayKey::from("key"), Value::from(new.as_str()));
            touched = true;
        }
        if touched {
            self.write_records(&meta, "attrs", &attrs).await?;
        }
        self.rename_document_field(&collection, &old, &new).await?;
        Ok(true)
    }

    /// Moves a top-level field of every document of a collection
    /// (`renameDocumentField()`).
    async fn rename_document_field(&self, collection: &str, old: &str, new: &str) -> Result<()> {
        let collection = self.filter(collection)?;
        let old = self.filter(old)?;
        let new = self.filter(new)?;
        if old == new {
            return Ok(());
        }
        self.rewrite_documents(&collection, |decoded| {
            let Some(value) = decoded.get_str(&old).cloned() else {
                return false;
            };
            decoded.set(ArrayKey::normalize(new.as_str().into()), value);
            decoded.remove(&ArrayKey::normalize(old.as_str().into()));
            true
        })
        .await
    }

    /// Drops a top-level field of every document of a collection
    /// (`dropDocumentField()`).
    async fn drop_document_field(&self, collection: &str, field: &str) -> Result<()> {
        let collection = self.filter(collection)?;
        let field = self.filter(field)?;
        self.rewrite_documents(&collection, |decoded| {
            if decoded.get_str(&field).is_none() {
                return false;
            }
            decoded.remove(&ArrayKey::normalize(field.as_str().into()));
            true
        })
        .await
    }

    /// Rewrites the stored payload of every document of the collection
    /// `change` changes.
    async fn rewrite_documents(&self, collection: &str, change: impl Fn(&mut Array) -> bool) -> Result<()> {
        let idx = self.idx_key(collection, None);
        let ids: Vec<String> = self.run(redis::cmd("SMEMBERS").arg(&idx)).await.map_err(tx_error)?;
        for id in ids {
            let key = self.doc_key(collection, &id, None);
            let payload: Option<Vec<u8>> = self.run(redis::cmd("GET").arg(&key)).await.map_err(tx_error)?;
            let Some(payload) = payload.filter(|p| !p.is_empty()) else {
                continue;
            };
            let Value::Array(mut decoded) = json_decode(&payload)? else {
                continue;
            };
            if !change(&mut decoded) {
                continue;
            }
            let json = super::json_encode(&Value::Array(decoded), super::payload_flags())?;
            let _: () = self.run(redis::cmd("SET").arg(&key).arg(json)).await.map_err(tx_error)?;
        }
        Ok(())
    }

    // ----- Relationships -----

    /// `createRelationship()`: the relationship attributes in the meta
    /// hashes (a junction's columns are the junction collection's own).
    pub(super) async fn create_relationship_attributes(
        &self,
        collection: &str,
        related_collection: &str,
        kind: &str,
        two_way: bool,
        id: &str,
        two_way_key: &str,
    ) -> Result<bool> {
        let relationship = Database::VAR_RELATIONSHIP;
        match kind {
            Database::RELATION_ONE_TO_ONE => {
                self.put_attribute(collection, id, relationship, 0, true, false, false).await?;
                if two_way {
                    self.put_attribute(related_collection, two_way_key, relationship, 0, true, false, false).await?;
                }
            }
            Database::RELATION_ONE_TO_MANY => {
                self.put_attribute(related_collection, two_way_key, relationship, 0, true, false, false).await?;
            }
            Database::RELATION_MANY_TO_ONE => {
                self.put_attribute(collection, id, relationship, 0, true, false, false).await?;
            }
            Database::RELATION_MANY_TO_MANY => {}
            _ => return Err(Error::database("Invalid relationship type")),
        }
        Ok(true)
    }

    /// `updateRelationship()`: renames the relationship attributes (and a
    /// junction's columns).
    #[allow(clippy::too_many_arguments)]
    pub(super) async fn update_relationship_attributes(
        &self,
        collection: &str,
        related_collection: &str,
        kind: &str,
        two_way: bool,
        key: &str,
        two_way_key: &str,
        side: &str,
        new_key: Option<&str>,
        new_two_way_key: Option<&str>,
    ) -> Result<bool> {
        let key = self.filter(key)?;
        let two_way_key = self.filter(two_way_key)?;
        let new_key = new_key.map(|k| self.filter(k)).transpose()?;
        let new_two_way_key = new_two_way_key.map(|k| self.filter(k)).transpose()?;
        let renames_key = new_key.as_ref().filter(|k| **k != key);
        let renames_two_way = new_two_way_key.as_ref().filter(|k| **k != two_way_key);
        match kind {
            Database::RELATION_ONE_TO_ONE => {
                if let Some(new) = renames_key {
                    self.rename_attribute_keys(collection, &key, new).await?;
                }
                if two_way && let Some(new) = renames_two_way {
                    self.rename_attribute_keys(related_collection, &two_way_key, new).await?;
                }
            }
            Database::RELATION_ONE_TO_MANY => {
                if side == Database::RELATION_SIDE_PARENT {
                    if let Some(new) = renames_two_way {
                        self.rename_attribute_keys(related_collection, &two_way_key, new).await?;
                    }
                } else if let Some(new) = renames_key {
                    self.rename_attribute_keys(collection, &key, new).await?;
                }
            }
            Database::RELATION_MANY_TO_ONE => {
                if side == Database::RELATION_SIDE_CHILD {
                    if let Some(new) = renames_two_way {
                        self.rename_attribute_keys(related_collection, &two_way_key, new).await?;
                    }
                } else if let Some(new) = renames_key {
                    self.rename_attribute_keys(collection, &key, new).await?;
                }
            }
            Database::RELATION_MANY_TO_MANY => {
                if let Some(junction) = self.junction(collection, related_collection, side).await? {
                    if let Some(new) = renames_key {
                        self.rename_attribute_keys(&junction, &key, new).await?;
                    }
                    if let Some(new) = renames_two_way {
                        self.rename_attribute_keys(&junction, &two_way_key, new).await?;
                    }
                }
            }
            _ => return Err(Error::database("Invalid relationship type")),
        }
        Ok(true)
    }

    /// `deleteRelationship()`: drops the relationship attributes (the
    /// junction collection is the database's to drop).
    #[allow(clippy::too_many_arguments)]
    pub(super) async fn delete_relationship_attributes(
        &self,
        collection: &str,
        related_collection: &str,
        kind: &str,
        two_way: bool,
        key: &str,
        two_way_key: &str,
        side: &str,
    ) -> Result<bool> {
        let key = self.filter(key)?;
        let two_way_key = self.filter(two_way_key)?;
        let parent = side == Database::RELATION_SIDE_PARENT;
        match kind {
            Database::RELATION_ONE_TO_ONE => {
                if parent {
                    self.drop_attribute(collection, &key).await?;
                    if two_way {
                        self.drop_attribute(related_collection, &two_way_key).await?;
                    }
                } else {
                    self.drop_attribute(related_collection, &two_way_key).await?;
                    if two_way {
                        self.drop_attribute(collection, &key).await?;
                    }
                }
            }
            Database::RELATION_ONE_TO_MANY => {
                if parent {
                    self.drop_attribute(related_collection, &two_way_key).await?;
                } else {
                    self.drop_attribute(collection, &key).await?;
                }
            }
            Database::RELATION_MANY_TO_ONE => {
                if parent {
                    self.drop_attribute(collection, &key).await?;
                } else {
                    self.drop_attribute(related_collection, &two_way_key).await?;
                }
            }
            Database::RELATION_MANY_TO_MANY => {}
            _ => return Err(Error::database("Invalid relationship type")),
        }
        Ok(true)
    }

    /// The junction collection of a many-to-many relationship: `_{a}_{b}` of
    /// the two collections' sequences, ordered by side; `None` when either
    /// metadata document or sequence is missing (`resolveJunctionCollection()`).
    async fn junction(&self, collection: &str, related_collection: &str, side: &str) -> Result<Option<String>> {
        let (Some(collection), Some(related)) =
            (self.metadata_document(collection).await?, self.metadata_document(related_collection).await?)
        else {
            return Ok(None);
        };
        let sequence = |d: &Document| d.sequence().filter(|s| !to_text(s).is_empty()).map(to_text);
        let (Some(a), Some(b)) = (sequence(&collection), sequence(&related)) else {
            return Ok(None);
        };
        Ok(Some(if side == Database::RELATION_SIDE_PARENT { format!("_{a}_{b}") } else { format!("_{b}_{a}") }))
    }

    /// A collection's metadata document, read straight from its key (the
    /// null-tenant one under shared tables when the tenant's is missing)
    /// (`loadMetadataDocument()`).
    async fn metadata_document(&self, collection: &str) -> Result<Option<Document>> {
        let id = self.filter(collection)?;
        let mut payload: Option<Vec<u8>> =
            self.run(redis::cmd("GET").arg(self.doc_key(Database::METADATA, &id, None))).await.map_err(raw_error)?;
        if payload.as_ref().is_none_or(|p| p.is_empty()) && self.shared_tables() {
            payload = self
                .run(redis::cmd("GET").arg(self.doc_key(Database::METADATA, &id, Some(&Value::from("_")))))
                .await
                .map_err(raw_error)?;
        }
        match payload.filter(|p| !p.is_empty()) {
            Some(p) => Ok(Some(Self::decode(&p)?)),
            None => Ok(None),
        }
    }

    // ----- Indexes -----

    /// `createIndex()`: refuses an existing id and, for a unique index,
    /// documents already holding duplicate values; then its record.
    pub(super) async fn put_index(
        &self,
        collection: &str,
        id: &str,
        kind: &str,
        attributes: &[String],
        lengths: &[Value],
        orders: &[Value],
    ) -> Result<bool> {
        let collection = self.filter(collection)?;
        let id = self.filter(id)?;
        let meta = self.meta_key(&collection);
        if !self.exists_key(&meta).await? {
            return Err(Error::not_found("Collection not found"));
        }
        let mut indexes = self.read_indexes(&meta).await?;
        for existing in &indexes {
            let existing_id = existing
                .get_str("$id")
                .filter(|v| !matches!(v, Value::Null))
                .or_else(|| existing.get_str("key").filter(|v| !matches!(v, Value::Null)));
            if existing_id.is_some_and(|v| matches!(v, Value::Str(s) if s.as_bytes() == id.as_bytes())) {
                return Err(Error::duplicate("Index already exists"));
            }
        }
        if kind == Database::INDEX_UNIQUE && !attributes.is_empty() {
            self.check_unique_index(&collection, attributes).await?;
        }
        let record: Array = [
            ("$id", Value::from(id.as_str())),
            ("key", Value::from(id.as_str())),
            ("type", Value::from(kind)),
            ("attributes", Value::Array(attributes.iter().map(|a| Value::from(a.as_str())).collect())),
            ("lengths", Value::Array(lengths.iter().cloned().collect())),
            ("orders", Value::Array(orders.iter().cloned().collect())),
        ]
        .into_iter()
        .map(|(k, v)| (ArrayKey::from(k), v))
        .collect();
        indexes.push(record);
        self.write_records(&meta, "indexes", &indexes).await?;
        Ok(true)
    }

    /// `deleteIndex()`.
    pub(super) async fn drop_index(&self, collection: &str, id: &str) -> Result<bool> {
        let collection = self.filter(collection)?;
        let id = self.filter(id)?;
        let meta = self.meta_key(&collection);
        if !self.exists_key(&meta).await? {
            return Ok(true);
        }
        let indexes = self.read_indexes(&meta).await?;
        let kept: Vec<Array> = indexes.into_iter().filter(|index| !index_is(index, &id)).collect();
        self.write_records(&meta, "indexes", &kept).await?;
        Ok(true)
    }

    /// `renameIndex()`.
    pub(super) async fn rename_index_keys(&self, collection: &str, old: &str, new: &str) -> Result<bool> {
        let collection = self.filter(collection)?;
        let old = self.filter(old)?;
        let new = self.filter(new)?;
        let meta = self.meta_key(&collection);
        if !self.exists_key(&meta).await? {
            return Err(Error::not_found("Collection not found"));
        }
        let mut indexes = self.read_indexes(&meta).await?;
        let Some(index) = indexes.iter_mut().find(|index| index_is(index, &old)) else {
            return Ok(true);
        };
        index.set(ArrayKey::from("$id"), Value::from(new.as_str()));
        index.set(ArrayKey::from("key"), Value::from(new.as_str()));
        self.write_records(&meta, "indexes", &indexes).await?;
        Ok(true)
    }

    /// The unique-index pre-flight of `createIndex()`: the active tenant's
    /// documents must not already share values.
    async fn check_unique_index(&self, collection: &str, attributes: &[String]) -> Result<()> {
        let idx = self.idx_key(collection, None);
        let ids: Vec<String> = self.run(redis::cmd("SMEMBERS").arg(&idx)).await.map_err(tx_error)?;
        if ids.is_empty() {
            return Ok(());
        }
        let shared = self.shared_tables();
        let tenant = if shared { self.tenant() } else { Value::Null };
        let keys: Vec<String> = ids.iter().map(|id| self.doc_key(collection, id, None)).collect();
        let payloads: Vec<Option<Vec<u8>>> = self.run(redis::cmd("MGET").arg(&keys)).await.map_err(tx_error)?;
        let mut seen: Vec<Vec<u8>> = Vec::new();
        for payload in payloads.into_iter().flatten() {
            let document = Self::decode(&payload)?;
            if shared && !document.raw("$tenant").cloned().unwrap_or(Value::Null).strict_eq(&tenant) {
                continue;
            }
            let Some(signature) = self.signature(&document, attributes, shared.then_some(&tenant)) else {
                continue;
            };
            if seen.contains(&signature) {
                return Err(Error::unique(
                    "Cannot create unique index: existing rows already contain duplicate values",
                ));
            }
            seen.push(signature);
        }
        Ok(())
    }
}

/// Whether an index record has the id (`($index['$id'] ?? $index['key'] ?? null) === $id`).
fn index_is(index: &Array, id: &str) -> bool {
    let value = index
        .get_str("$id")
        .filter(|v| !matches!(v, Value::Null))
        .or_else(|| index.get_str("key").filter(|v| !matches!(v, Value::Null)));
    matches!(value, Some(Value::Str(s)) if s.as_bytes() == id.as_bytes())
}

/// Replaces the record with the same id, or appends it
/// (`upsertAttributeRecord()`).
fn upsert_record(mut records: Vec<Array>, record: Array) -> Vec<Array> {
    let target = match record.get_str("$id") {
        Some(v) => to_text(v),
        None => String::new(),
    };
    match records.iter_mut().find(|existing| record_id(existing) == target) {
        Some(existing) => *existing = record,
        None => records.push(record),
    }
    records
}
