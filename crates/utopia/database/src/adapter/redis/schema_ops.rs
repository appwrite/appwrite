//! Databases, collections, attributes, indexes and relationships of the
//! [`Redis`] adapter: the meta hash of each collection, and the scans that
//! move, rewrite, drop or measure its keys.

use redis::aio::ConnectionLike;
use utopia_query::schema::{ColumnType, IndexType};

use super::{
    Redis, SCAN_BATCH_SIZE, SEP, assoc, field, index_identifier, json_decode_plain, json_encode, meta_flags,
    payload_flags, record_identifier, reply_pairs, string, upsert_attribute_record, utf8,
};
use crate::adapter::{Adapter, filter};
use crate::attribute::Attribute;
use crate::database;
use crate::document::{self, Document};
use crate::error::{Error, Result};
use crate::index::Index;
use crate::relationship::{self, Relationship, RelationshipSide, RelationshipType};
use crate::value::{Array, ArrayKey, Value};

impl<C: ConnectionLike + Clone + Send + Sync + 'static> Redis<C> {
    // ----- Databases -----

    /// `create()`: the name joins the namespace's database set.
    pub(super) async fn create_database(&self, name: &str) -> Result<bool> {
        let name = filter(name);
        let dbs = self.dbs_key();
        self.tx(async {
            let _: i64 = self.run(redis::cmd("SADD").arg(&dbs).arg(&name)).await?;
            Ok(())
        })
        .await?;
        Ok(true)
    }

    /// `update()`: every key of the database moves to the new name, the
    /// grant sets' members with them; a failure moves the moved keys back,
    /// newest first, and fails. Shared tables refuse the rename.
    pub(super) async fn rename_database(&self, name: &str, new: &str) -> Result<bool> {
        if self.shared_tables() {
            return Err(Error::database("Cannot rename a database while shared tables are enabled"));
        }
        let name = filter(name);
        let new = filter(new);
        let namespace = self.namespace();
        let dbs = self.dbs_key();
        let member: i64 = self.run(redis::cmd("SISMEMBER").arg(&dbs).arg(&name)).await?;
        if member == 0 {
            return Err(Error::not_found("Database not found"));
        }
        let member: i64 = self.run(redis::cmd("SISMEMBER").arg(&dbs).arg(&new)).await?;
        if member != 0 {
            return Err(Error::duplicate("Database already exists"));
        }
        let from = format!("{}{SEP}", Self::ns_for(&namespace, &name));
        let to = format!("{}{SEP}", Self::ns_for(&namespace, &new));
        let grants = format!("{to}grants{SEP}");
        self.tx(async {
            let mut moved: Vec<(String, String)> = Vec::new();
            let forward: Result<()> = async {
                for key in self.scan_keys(&format!("{from}*")).await? {
                    let Some(rest) = key.strip_prefix(from.as_str()) else {
                        continue;
                    };
                    let target = format!("{to}{rest}");
                    self.rename_key(&key, &target).await?;
                    moved.push((key.clone(), target.clone()));
                    if target.starts_with(&grants) {
                        self.move_grant_members(&target, &from, &to).await?;
                    }
                }
                let _: i64 = self.run(redis::cmd("SREM").arg(&dbs).arg(&name)).await?;
                let _: i64 = self.run(redis::cmd("SADD").arg(&dbs).arg(&new)).await?;
                Ok(())
            }
            .await;
            if let Err(error) = forward {
                for (key, target) in moved.iter().rev() {
                    if target.starts_with(&grants) {
                        self.move_grant_members(target, &to, &from).await?;
                    }
                    self.rename_key(target, key).await?;
                }
                let _: i64 = self.run(redis::cmd("SREM").arg(&dbs).arg(&new)).await?;
                let _: i64 = self.run(redis::cmd("SADD").arg(&dbs).arg(&name)).await?;
                return Err(error);
            }
            Ok(())
        })
        .await?;
        Ok(true)
    }

    /// `renameKey()`: fails when the key is gone.
    async fn rename_key(&self, key: &str, target: &str) -> Result<()> {
        let reply: redis::Value = self.run(redis::cmd("RENAME").arg(key).arg(target)).await?;
        if matches!(reply, redis::Value::Nil | redis::Value::Boolean(false) | redis::Value::Int(0)) {
            return Err(Error::database(format!("Failed to move {key} to {target}")));
        }
        Ok(())
    }

    /// `moveGrantMembers()`: the members of a grant set under `from` moved
    /// under `to`.
    async fn move_grant_members(&self, grants_key: &str, from: &str, to: &str) -> Result<()> {
        let members = self.members(grants_key).await?;
        if members.is_empty() {
            return Ok(());
        }
        let moved: Vec<String> = members
            .into_iter()
            .map(|member| match member.strip_prefix(from) {
                Some(rest) => format!("{to}{rest}"),
                None => member,
            })
            .collect();
        let _: i64 = self.run(redis::cmd("DEL").arg(grants_key)).await?;
        let _: i64 = self.run(redis::cmd("SADD").arg(grants_key).arg(&moved)).await?;
        Ok(())
    }

    /// `delete()`: every collection's keys, the collection set and the name.
    pub(super) async fn delete_database(&self, name: &str) -> Result<bool> {
        let name = filter(name);
        let namespace = self.namespace();
        let dbs = self.dbs_key();
        let cols = Self::key(&[&Self::ns_for(&namespace, &name), "cols"]);
        self.tx(async {
            for collection in self.members(&cols).await? {
                self.purge_collection_keys(&namespace, &name, &collection).await?;
            }
            let _: i64 = self.run(redis::cmd("DEL").arg(&cols)).await?;
            let _: i64 = self.run(redis::cmd("SREM").arg(&dbs).arg(&name)).await?;
            Ok(())
        })
        .await?;
        Ok(true)
    }

    // ----- Collections -----

    /// `createCollection()`: the meta hash and the collection's membership.
    pub(super) async fn create_collection_keys(
        &self,
        collection: &str,
        attributes: &[Attribute],
        indexes: &[Index],
    ) -> Result<bool> {
        let id = filter(collection);
        let cols = Self::key(&[&self.ns(), "cols"]);
        let meta = self.meta_key(&id);
        let idx = self.idx_key(&id, None);
        if self.exists_key(&meta).await? {
            return Err(Error::duplicate("Collection already exists"));
        }
        let attribute_payload: Vec<Value> =
            attributes.iter().map(|a| Self::attribute_record(&a.key, a)).collect();
        let index_payload: Vec<Value> = indexes
            .iter()
            .map(|i| Self::index_record(&i.key, i.kind, &i.attributes, &i.lengths, Self::order_values(i)))
            .collect();
        let schema = Document::new(assoc([
            (document::ID, string(id.as_str())),
            ("name", string(collection)),
            ("attributes", crate::value::list(attribute_payload.iter().cloned())),
            ("indexes", crate::value::list(index_payload.iter().cloned())),
        ]))?;
        let schema_json = json_encode(&Value::Array(schema.array_copy()), meta_flags())?;
        let attrs_json = json_encode(&crate::value::list(attribute_payload), meta_flags())?;
        let indexes_json = json_encode(&crate::value::list(index_payload), meta_flags())?;
        self.tx(async {
            let mut hmset = redis::cmd("HMSET");
            hmset
                .arg(&meta)
                .arg("schema")
                .arg(&schema_json)
                .arg("attrs")
                .arg(&attrs_json)
                .arg("indexes")
                .arg(&indexes_json)
                .arg("docCount")
                .arg("0")
                .arg("sizeBytes")
                .arg("0");
            let _: redis::Value = self.run(&hmset).await?;
            let _: i64 = self.run(redis::cmd("DEL").arg(&idx)).await?;
            let _: i64 = self.run(redis::cmd("SADD").arg(&cols).arg(&id)).await?;
            Ok(())
        })
        .await?;
        Ok(true)
    }

    /// `deleteCollection()`.
    pub(super) async fn delete_collection_keys(&self, collection: &str) -> Result<bool> {
        let id = filter(collection);
        let (namespace, database) = (self.namespace(), self.database());
        let cols = Self::key(&[&self.ns(), "cols"]);
        self.tx(async {
            self.purge_collection_keys(&namespace, &database, &id).await?;
            let _: i64 = self.run(redis::cmd("SREM").arg(&cols).arg(&id)).await?;
            Ok(())
        })
        .await?;
        Ok(true)
    }

    /// `purgeCollectionKeys()`: the registered grants, then for each tenant
    /// bucket holding an id set or a sequence, the id set, the sequence, the
    /// documents, their permission hashes and the role sets those name, then
    /// the meta hash and the grant set, deleted in batches.
    async fn purge_collection_keys(&self, namespace: &str, database: &str, collection: &str) -> Result<()> {
        let collection = filter(collection);
        let prefix = Self::ns_for(namespace, database);
        let grants_key = Self::grants_key(&prefix, &collection);
        let mut keys = self.members(&grants_key).await?;
        let mut buckets: Vec<Option<String>> = vec![None];
        for bucket in self.tenant_buckets(&prefix, "idx", &collection).await? {
            buckets.push(Some(bucket));
        }
        for bucket in self.tenant_buckets(&prefix, "seq", &collection).await? {
            buckets.push(Some(bucket));
        }
        let mut unique_buckets: Vec<Option<String>> = Vec::new();
        for bucket in buckets {
            if !unique_buckets.contains(&bucket) {
                unique_buckets.push(bucket);
            }
        }
        for bucket in &unique_buckets {
            let bucket = bucket.as_deref();
            let idx_key = Self::scoped_key(&prefix, "idx", bucket, &[&collection]);
            keys.push(idx_key.clone());
            keys.push(Self::scoped_key(&prefix, "seq", bucket, &[&collection]));
            let ids = self.members(&idx_key).await?;
            if ids.is_empty() {
                continue;
            }
            let mut perm_doc_keys = Vec::with_capacity(ids.len());
            for id in &ids {
                keys.push(Self::scoped_key(&prefix, "doc", bucket, &[&collection, id]));
                perm_doc_keys.push(Self::scoped_key(&prefix, "perm", bucket, &["doc", &collection, id]));
            }
            let role_keys = self.role_set_keys(&prefix, bucket, &collection, &perm_doc_keys).await?;
            keys.extend(perm_doc_keys);
            keys.extend(role_keys);
        }
        keys.push(Self::key(&[&prefix, "meta", &collection]));
        keys.push(grants_key);
        let mut unique: Vec<String> = Vec::with_capacity(keys.len());
        let mut seen = std::collections::HashSet::new();
        for key in keys {
            if seen.insert(key.clone()) {
                unique.push(key);
            }
        }
        for batch in unique.chunks(SCAN_BATCH_SIZE) {
            let _: i64 = self.run(redis::cmd("DEL").arg(batch)).await?;
        }
        Ok(())
    }

    /// `roleSetKeys()`: the role sets the permission hashes name.
    async fn role_set_keys(
        &self,
        prefix: &str,
        bucket: Option<&str>,
        collection: &str,
        perm_doc_keys: &[String],
    ) -> Result<Vec<String>> {
        if perm_doc_keys.is_empty() {
            return Ok(Vec::new());
        }
        let mut pipe = redis::pipe();
        for key in perm_doc_keys {
            pipe.cmd("HGETALL").arg(key);
        }
        let grants = self.pipe(&pipe).await?;
        let mut keys = Vec::new();
        for reply in &grants {
            for (role, letters) in reply_pairs(reply) {
                for letter in letters.split(',') {
                    if !letter.is_empty() {
                        keys.push(Self::scoped_key(prefix, "perm", bucket, &[collection, letter, &role]));
                    }
                }
            }
        }
        Ok(keys)
    }

    /// `registeredGrantKeys()`: the registered permission keys of the
    /// collection in the tenant bucket.
    async fn registered_grant_keys(&self, prefix: &str, bucket: Option<&str>, collection: &str) -> Result<Vec<String>> {
        let keys = self.members(&Self::grants_key(prefix, collection)).await?;
        let Some(bucket) = bucket else {
            return Ok(keys);
        };
        let scope = format!("{}{SEP}", Self::scoped_key(prefix, "perm", Some(bucket), &[]));
        Ok(keys.into_iter().filter(|key| key.starts_with(&scope)).collect())
    }

    /// `tenantBuckets()`: the buckets holding a `{family}:t:{bucket}:{collection}` key.
    async fn tenant_buckets(&self, prefix: &str, family: &str, collection: &str) -> Result<Vec<String>> {
        let head = format!("{}{SEP}", Self::key(&[prefix, family, "t"]));
        let tail = format!("{SEP}{collection}");
        let mut buckets = Vec::new();
        for key in self.scan(&format!("{head}*{tail}")).await? {
            if !key.starts_with(&head) || !key.ends_with(&tail) || key.len() < head.len() + tail.len() {
                continue;
            }
            let bucket = &key[head.len()..key.len() - tail.len()];
            if !bucket.is_empty() && !bucket.contains(SEP) {
                buckets.push(bucket.to_owned());
            }
        }
        Ok(buckets)
    }

    /// `computeCollectionSize()`: the bytes of the collection's keys in the
    /// adapter's tenant bucket.
    pub(super) async fn collection_size(&self, collection: &str) -> Result<i64> {
        let collection = filter(collection);
        let prefix = self.ns();
        let meta = Self::key(&[&prefix, "meta", &collection]);
        if !self.exists_key(&meta).await? {
            return Ok(0);
        }
        let bucket = self.tenant_bucket(None);
        let idx_key = self.idx_key(&collection, None);
        let mut keys = vec![meta, idx_key.clone()];
        let mut perm_doc_keys = Vec::new();
        for id in self.members(&idx_key).await? {
            keys.push(self.doc_key(&collection, &id, None));
            perm_doc_keys.push(self.perm_doc_key(&collection, &id, None));
        }
        let role_keys = self.role_set_keys(&prefix, bucket.as_deref(), &collection, &perm_doc_keys).await?;
        let grant_keys = self.registered_grant_keys(&prefix, bucket.as_deref(), &collection).await?;
        keys.extend(perm_doc_keys);
        keys.extend(role_keys);
        keys.extend(grant_keys);
        let mut seen = std::collections::HashSet::new();
        let mut total = 0;
        for key in keys {
            if seen.insert(key.clone()) {
                total += self.measure_key(&key).await?;
            }
        }
        Ok(total)
    }

    /// `measureKey()`: `MEMORY USAGE`, else the bytes of the key and its
    /// content.
    async fn measure_key(&self, key: &str) -> Result<i64> {
        if let Ok(redis::Value::Int(usage)) =
            self.run::<redis::Value>(redis::cmd("MEMORY").arg("USAGE").arg(key)).await
        {
            return Ok(usage);
        }
        let kind: String = self.run(redis::cmd("TYPE").arg(key)).await?;
        Ok(match kind.as_str() {
            "string" => {
                let value: Option<Vec<u8>> = self.run(redis::cmd("GET").arg(key)).await?;
                value.map_or(0, |v| (v.len() + key.len()) as i64)
            }
            "hash" => {
                let reply: redis::Value = self.run(redis::cmd("HGETALL").arg(key)).await?;
                let pairs = reply_pairs(&reply);
                key.len() as i64 + pairs.iter().map(|(f, v)| (f.len() + v.len()) as i64).sum::<i64>()
            }
            "set" => {
                let members: Vec<Vec<u8>> = self.run(redis::cmd("SMEMBERS").arg(key)).await?;
                key.len() as i64 + members.iter().map(|m| m.len() as i64).sum::<i64>()
            }
            _ => 0,
        })
    }

    // ----- Attributes -----

    /// `createAttribute()`: the record replaces the one with its id, or is
    /// appended.
    pub(super) async fn put_attribute(&self, collection: &str, attribute: &Attribute) -> Result<bool> {
        let collection = filter(collection);
        let id = filter(&attribute.key);
        let meta = self.meta_key(&collection);
        if !self.exists_key(&meta).await? {
            return Err(Error::not_found("Collection not found"));
        }
        let record = Self::attribute_record(&id, attribute);
        self.upsert_record(&meta, record).await?;
        Ok(true)
    }

    /// Reads the `attrs` field, upserts `record`, writes it back.
    async fn upsert_record(&self, meta: &str, record: Value) -> Result<()> {
        self.tx(async {
            let attributes = upsert_attribute_record(self.read_attributes(meta).await?, record);
            self.write_meta_field(meta, "attrs", &crate::value::list(attributes)).await
        })
        .await
    }

    /// `updateAttribute()`: a changed key renames the attribute first.
    pub(super) async fn change_attribute(&self, collection: &str, key: &str, attribute: &Attribute) -> Result<bool> {
        let collection = filter(collection);
        let mut id = filter(key);
        let meta = self.meta_key(&collection);
        if !self.exists_key(&meta).await? {
            return Err(Error::not_found("Collection not found"));
        }
        if attribute.key != key {
            self.rename_attribute_keys(&collection, &id, &attribute.key).await?;
            id = filter(&attribute.key);
        }
        let record = Self::attribute_record(&id, attribute);
        self.upsert_record(&meta, record).await?;
        Ok(true)
    }

    /// `deleteAttribute()`: the record, then the field of every document.
    pub(super) async fn drop_attribute(&self, collection: &str, key: &str) -> Result<bool> {
        let collection = filter(collection);
        let id = filter(key);
        let meta = self.meta_key(&collection);
        if !self.exists_key(&meta).await? {
            return Ok(true);
        }
        self.tx(async {
            let attributes: Vec<Value> = self
                .read_attributes(&meta)
                .await?
                .into_iter()
                .filter(|attribute| filter(&record_identifier(attribute)) != id)
                .collect();
            self.write_meta_field(&meta, "attrs", &crate::value::list(attributes)).await
        })
        .await?;
        self.rewrite_documents(&collection, &id, None).await?;
        Ok(true)
    }

    /// `renameAttribute()`: the record, then the field of every document.
    pub(super) async fn rename_attribute_keys(&self, collection: &str, old: &str, new: &str) -> Result<bool> {
        let collection = filter(collection);
        let old = filter(old);
        let new = filter(new);
        let meta = self.meta_key(&collection);
        if !self.exists_key(&meta).await? {
            return Err(Error::not_found("Collection not found"));
        }
        self.tx(async {
            let mut attributes = self.read_attributes(&meta).await?;
            let mut touched = false;
            for attribute in attributes.iter_mut() {
                if filter(&record_identifier(attribute)) != old {
                    continue;
                }
                if let Value::Array(record) = attribute {
                    record.set(ArrayKey::from(document::ID), string(new.as_str()));
                    record.set(ArrayKey::from("key"), string(new.as_str()));
                }
                touched = true;
            }
            if !touched {
                return Ok(());
            }
            self.write_meta_field(&meta, "attrs", &crate::value::list(attributes)).await
        })
        .await?;
        if old != new {
            self.rewrite_documents(&collection, &old, Some(&new)).await?;
        }
        Ok(true)
    }

    /// `renameDocumentField()` (with `new`) and `dropDocumentField()`
    /// (without): every stored document of the collection holding the
    /// field, rewritten.
    async fn rewrite_documents(&self, collection: &str, field: &str, new: Option<&str>) -> Result<()> {
        let idx_key = self.idx_key(collection, None);
        self.tx(async {
            for id in self.members(&idx_key).await? {
                let doc_key = self.doc_key(collection, &id, None);
                let Some(payload) = self.get_payload(&doc_key).await? else {
                    continue;
                };
                let php_std::Value::Array(mut decoded) = json_decode_plain(&payload)? else {
                    continue;
                };
                let old = ArrayKey::normalize(field.into());
                let Some(value) = decoded.get(&old).cloned() else {
                    continue;
                };
                if let Some(new) = new {
                    decoded.set(ArrayKey::normalize(new.into()), value);
                }
                decoded.remove(&old);
                let json = php_std::json::encode(
                    &php_std::Value::Array(decoded),
                    payload_flags() | php_std::json::Flags::THROW_ON_ERROR,
                    512,
                )
                .map_err(|e| super::json_exception(&e))?;
                let _: redis::Value = self.run(redis::cmd("SET").arg(&doc_key).arg(json)).await?;
            }
            Ok(())
        })
        .await
    }

    // ----- Relationships -----

    /// `createRelationship()`: a relationship record on each side holding a
    /// key.
    pub(super) async fn create_relationship_fields(&self, collection: &str, relationship: &Relationship) -> Result<bool> {
        let related = relationship.related_collection.as_str();
        let key = relationship.key.as_deref().unwrap_or("");
        let two_way_key = relationship.two_way_key.as_deref().unwrap_or("");
        match relationship.kind {
            RelationshipType::OneToOne => {
                self.register_relationship_field(collection, key).await?;
                if relationship.two_way {
                    self.register_relationship_field(related, two_way_key).await?;
                }
            }
            RelationshipType::OneToMany => self.register_relationship_field(related, two_way_key).await?,
            RelationshipType::ManyToOne => self.register_relationship_field(collection, key).await?,
            RelationshipType::ManyToMany => {}
        }
        Ok(true)
    }

    /// `registerRelationshipField()`.
    async fn register_relationship_field(&self, collection: &str, field: &str) -> Result<()> {
        let collection = filter(collection);
        let field = filter(field);
        let meta = self.meta_key(&collection);
        if !self.exists_key(&meta).await? {
            return Ok(());
        }
        let record = Value::Array(assoc([
            (document::ID, string(field.as_str())),
            ("key", string(field.as_str())),
            ("type", string(ColumnType::Relationship.as_str())),
            ("size", Value::Int(0)),
            ("signed", Value::Bool(true)),
            ("array", Value::Bool(false)),
            ("required", Value::Bool(false)),
        ]));
        self.upsert_record(&meta, record).await
    }

    /// `updateRelationship()`: the stored keys renamed on the sides that
    /// hold them.
    pub(super) async fn update_relationship_fields(
        &self,
        collection: &str,
        relationship: &Relationship,
        side: RelationshipSide,
        update: &relationship::Update,
    ) -> Result<bool> {
        let related = relationship.related_collection.as_str();
        let key = filter(relationship.key.as_deref().unwrap_or(""));
        let two_way_key = filter(relationship.two_way_key.as_deref().unwrap_or(""));
        let new_key = update.key.as_deref().map(filter);
        let new_two_way_key = update.two_way_key.as_deref().map(filter);
        let two_way = update.two_way.unwrap_or(relationship.two_way);
        let changed = |new: &Option<String>, old: &str| new.as_deref().filter(|n| *n != old).map(str::to_owned);
        match relationship.kind {
            RelationshipType::OneToOne => {
                if (two_way || side == RelationshipSide::Parent)
                    && let Some(new) = changed(&new_key, &key)
                {
                    self.rename_attribute_keys(collection, &key, &new).await?;
                }
                if (two_way || side == RelationshipSide::Child)
                    && let Some(new) = changed(&new_two_way_key, &two_way_key)
                {
                    self.rename_attribute_keys(related, &two_way_key, &new).await?;
                }
            }
            RelationshipType::OneToMany => {
                if side == RelationshipSide::Parent {
                    if let Some(new) = changed(&new_two_way_key, &two_way_key) {
                        self.rename_attribute_keys(related, &two_way_key, &new).await?;
                    }
                } else if let Some(new) = changed(&new_key, &key) {
                    self.rename_attribute_keys(collection, &key, &new).await?;
                }
            }
            RelationshipType::ManyToOne => {
                if side == RelationshipSide::Child {
                    if let Some(new) = changed(&new_two_way_key, &two_way_key) {
                        self.rename_attribute_keys(related, &two_way_key, &new).await?;
                    }
                } else if let Some(new) = changed(&new_key, &key) {
                    self.rename_attribute_keys(collection, &key, &new).await?;
                }
            }
            RelationshipType::ManyToMany => {
                if let Some(junction) = self.junction_collection(collection, related, side).await? {
                    if let Some(new) = changed(&new_key, &key) {
                        self.rename_attribute_keys(&junction, &key, &new).await?;
                    }
                    if let Some(new) = changed(&new_two_way_key, &two_way_key) {
                        self.rename_attribute_keys(&junction, &two_way_key, &new).await?;
                    }
                }
            }
        }
        Ok(true)
    }

    /// `deleteRelationship()`: the stored keys dropped, or the junction.
    pub(super) async fn delete_relationship_fields(
        &self,
        collection: &str,
        relationship: &Relationship,
        side: RelationshipSide,
    ) -> Result<bool> {
        let related = relationship.related_collection.as_str();
        let key = filter(relationship.key.as_deref().unwrap_or(""));
        let two_way_key = filter(relationship.two_way_key.as_deref().unwrap_or(""));
        let two_way = relationship.two_way;
        let parent = side == RelationshipSide::Parent;
        match relationship.kind {
            RelationshipType::OneToOne => {
                if parent {
                    self.drop_attribute(collection, &key).await?;
                    if two_way {
                        self.drop_attribute(related, &two_way_key).await?;
                    }
                } else {
                    self.drop_attribute(related, &two_way_key).await?;
                    if two_way {
                        self.drop_attribute(collection, &key).await?;
                    }
                }
            }
            RelationshipType::OneToMany => {
                if parent {
                    self.drop_attribute(related, &two_way_key).await?;
                } else {
                    self.drop_attribute(collection, &key).await?;
                }
            }
            RelationshipType::ManyToOne => {
                if parent {
                    self.drop_attribute(collection, &key).await?;
                } else {
                    self.drop_attribute(related, &two_way_key).await?;
                }
            }
            RelationshipType::ManyToMany => {
                if let Some(junction) = self.junction_collection(collection, related, side).await? {
                    self.delete_collection_keys(&junction).await?;
                }
            }
        }
        Ok(true)
    }

    /// `resolveJunctionCollection()`: `_{parent sequence}_{child sequence}`
    /// of the stored definitions, `None` without them.
    async fn junction_collection(&self, collection: &str, related: &str, side: RelationshipSide) -> Result<Option<String>> {
        let (Some(collection), Some(related)) =
            (self.metadata_document(collection).await?, self.metadata_document(related).await?)
        else {
            return Ok(None);
        };
        let sequence = |d: &Document| d.sequence().map(crate::value::to_text).filter(|s| !s.is_empty());
        let (Some(collection), Some(related)) = (sequence(&collection), sequence(&related)) else {
            return Ok(None);
        };
        Ok(Some(if side == RelationshipSide::Parent {
            format!("_{collection}_{related}")
        } else {
            format!("_{related}_{collection}")
        }))
    }

    /// `loadMetadataDocument()`: a collection's definition, the tenant's or
    /// (under shared tables) the tenantless one.
    async fn metadata_document(&self, collection: &str) -> Result<Option<Document>> {
        let id = filter(collection);
        let mut payload = self.get_payload(&self.doc_key(database::METADATA, &id, None)).await?;
        if payload.is_none() && self.shared_tables() {
            payload = self.get_payload(&self.doc_key(database::METADATA, &id, Some(&string("_")))).await?;
        }
        payload.map(|p| Self::decode(&p)).transpose()
    }

    // ----- Indexes -----

    /// `createIndex()`: refused for an id already recorded, and for a unique
    /// index the stored documents already break.
    pub(super) async fn put_index(&self, collection: &str, index: &Index) -> Result<bool> {
        let collection = filter(collection);
        let id = filter(&index.key);
        let meta = self.meta_key(&collection);
        if !self.exists_key(&meta).await? {
            return Err(Error::not_found("Collection not found"));
        }
        self.tx(async {
            let mut indexes = self.read_indexes(&meta).await?;
            let target = string(id.as_str());
            if indexes.values().any(|existing| index_identifier(existing).strict_eq(&target)) {
                return Err(Error::duplicate("Index already exists"));
            }
            if index.kind == IndexType::Unique && !index.attributes.is_empty() {
                self.check_unique_over_stored(&collection, &index.attributes).await?;
            }
            let _ = indexes.push(Self::index_record(
                &id,
                index.kind,
                &index.attributes,
                &index.lengths,
                Self::order_values(index),
            ));
            self.write_meta_field(&meta, "indexes", &Value::Array(indexes)).await
        })
        .await?;
        Ok(true)
    }

    /// The unique check of a new index over the stored documents of the
    /// adapter's tenant.
    async fn check_unique_over_stored(&self, collection: &str, attributes: &[String]) -> Result<()> {
        let ids = self.members(&self.idx_key(collection, None)).await?;
        if ids.is_empty() {
            return Ok(());
        }
        let shared = self.shared_tables();
        let tenant = if shared { self.tenant() } else { Value::Null };
        let keys: Vec<String> = ids.iter().map(|id| self.doc_key(collection, id, None)).collect();
        let payloads = self.mget(&keys).await?;
        let mut seen = std::collections::HashSet::new();
        'documents: for payload in &payloads {
            let Some(payload) = super::reply_bytes(payload) else {
                continue;
            };
            let document = Self::decode(&payload)?;
            if shared {
                let row = document.get(document::TENANT).cloned().unwrap_or(Value::Null);
                if !row.strict_eq(&tenant) {
                    continue;
                }
            }
            let mut signature = Vec::with_capacity(attributes.len() + 1);
            for attribute in attributes {
                let value = self.resolve(&document, attribute);
                if matches!(value, Value::Null) {
                    continue 'documents;
                }
                signature.push(super::unique::normalize_index_value(value));
            }
            if shared {
                signature.insert(0, tenant.clone());
            }
            let hash = php_std::serialize::serialize(&Value::Array(signature.into_iter().collect()));
            if !seen.insert(hash) {
                return Err(Error::unique(crate::error::UNIQUE_MESSAGE));
            }
        }
        Ok(())
    }

    /// `deleteIndex()`.
    pub(super) async fn drop_index(&self, collection: &str, key: &str) -> Result<bool> {
        let collection = filter(collection);
        let id = filter(key);
        let meta = self.meta_key(&collection);
        if !self.exists_key(&meta).await? {
            return Ok(true);
        }
        self.tx(async {
            let target = string(id.as_str());
            let indexes: Vec<Value> = self
                .read_indexes(&meta)
                .await?
                .values()
                .filter(|index| !index_identifier(index).strict_eq(&target))
                .cloned()
                .collect();
            self.write_meta_field(&meta, "indexes", &crate::value::list(indexes)).await
        })
        .await?;
        Ok(true)
    }

    /// `renameIndex()`: `false` when neither the old nor the new id is
    /// recorded.
    pub(super) async fn rename_index_keys(&self, collection: &str, old: &str, new: &str) -> Result<bool> {
        let collection = filter(collection);
        let old = filter(old);
        let new = filter(new);
        let meta = self.meta_key(&collection);
        if !self.exists_key(&meta).await? {
            return Err(Error::not_found("Collection not found"));
        }
        self.tx(async {
            let mut indexes = self.read_indexes(&meta).await?;
            let (old, new) = (string(old.as_str()), string(new.as_str()));
            let position = indexes
                .iter()
                .find(|(_, index)| index_identifier(index).strict_eq(&old))
                .map(|(k, _)| k.to_owned());
            let Some(position) = position else {
                return Ok(indexes.values().any(|index| index_identifier(index).strict_eq(&new)));
            };
            if let Some(Value::Array(index)) = indexes.get_mut(&position) {
                index.set(ArrayKey::from(document::ID), new.clone());
                index.set(ArrayKey::from("key"), new.clone());
            }
            let list = crate::value::list(indexes.values().cloned());
            self.write_meta_field(&meta, "indexes", &list).await?;
            Ok(true)
        })
        .await
    }
}

/// Whether an index record's type is `unique` with attributes, and its
/// attribute names (`uniqueIndexAttributes()`).
pub(super) fn unique_index_attributes(indexes: &Array) -> Vec<Vec<String>> {
    let mut unique = Vec::new();
    for index in indexes.values() {
        if !matches!(field(index, "type"), Some(Value::Str(s)) if s.as_bytes() == IndexType::Unique.as_str().as_bytes())
        {
            continue;
        }
        let Some(Value::Array(attributes)) = field(index, "attributes") else {
            continue;
        };
        if attributes.is_empty() {
            continue;
        }
        let names: Vec<String> = attributes
            .values()
            .filter_map(|a| match a {
                Value::Str(s) if !s.is_empty() => Some(utf8(s.as_bytes())),
                _ => None,
            })
            .collect();
        if !names.is_empty() {
            unique.push(names);
        }
    }
    unique
}
