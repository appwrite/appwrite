//! Databases, collections, attributes, relationships and indexes.
//!
//! PHP: `Mongo::exists`, `list`, `createCollection`, `getSizeOfCollection`,
//! `deleteAttribute`, `renameAttribute`, `updateRelationship`,
//! `deleteRelationship`, `createIndex`, `renameIndex`.

use std::time::Duration;

use utopia_mongo::bson::{Bson, Document as BsonDocument, doc};
use utopia_mongo::{Options, Reply};

use super::{Mongo, bson, from_client};
use crate::adapter::Adapter;
use crate::database::Database;
use crate::document::Document;
use crate::error::{Error, Kind, Result};
use crate::value::Value;

/// `getMongoTypeCode($type)`: the BSON type alias(es) an index's partial
/// filter requires of an attribute of `kind` (every non-null type when the
/// kind is unknown).
fn type_code(kind: Option<&str>) -> Bson {
    match kind {
        None => Bson::Array(["string", "number", "bool", "date", "object"].iter().map(|s| Bson::from(*s)).collect()),
        Some(Database::VAR_INTEGER | Database::VAR_BIGINT | Database::VAR_FLOAT) => Bson::from("number"),
        Some(Database::VAR_BOOLEAN) => Bson::from("bool"),
        Some(Database::VAR_DATETIME) => Bson::from("date"),
        Some(_) => Bson::from("string"),
    }
}

/// A string attribute of a document (`''` when missing or not a string).
fn text(document: &Document, key: &str) -> String {
    match document.get(key) {
        Some(Value::Str(s)) => String::from_utf8_lossy(s.as_bytes()).into_owned(),
        Some(Value::Int(i)) => i.to_string(),
        _ => String::new(),
    }
}

/// A list attribute of a document, as strings (`$orders[$j] ?? ASC` keeps nulls as `None`).
fn strings(value: Option<&Value>) -> Vec<Option<String>> {
    match value {
        Some(Value::Array(a)) => a
            .values()
            .map(|v| match v {
                Value::Null => None,
                other => Some(crate::value::to_text(other)),
            })
            .collect(),
        _ => Vec::new(),
    }
}

impl Mongo {
    /// `exists($database, $collection)`: whether the collection exists (the
    /// database always does: MongoDB creates databases on the fly).
    pub(super) async fn exists_impl(&self, _database: &str, collection: Option<&str>) -> Result<bool> {
        let Some(collection) = collection else { return Ok(true) };
        let name = format!("{}_{collection}", self.namespace());
        let reply = self.client.query(doc! { "listCollections": 1, "filter": { "name": name } }, None).await;
        Ok(match reply {
            Ok(Reply::Document(d)) => {
                matches!(d.get_document("cursor").ok().and_then(|c| c.get_array("firstBatch").ok()), Some(b) if !b.is_empty())
            }
            _ => false,
        })
    }

    /// `list()`: the values of the `listDatabases` reply (the database
    /// list, `ok`, the cluster and operation times), each as a document of
    /// its array form.
    pub(super) async fn list_impl(&self) -> Result<Vec<Document>> {
        let mut out = Vec::new();
        for value in self.database_list().await? {
            let array = match value {
                Value::Array(a) | Value::Object(a) => a,
                other => crate::value::Array::from_list(vec![other]),
            };
            out.push(Document::new(array)?);
        }
        Ok(out)
    }

    /// `list()` as PHP returns it: the values of the `listDatabases` reply
    /// (the database list, `ok`, the cluster and operation times).
    pub async fn database_list(&self) -> Result<Vec<Value>> {
        let reply = self.client.list_database_names().await.map_err(from_client)?;
        Ok(reply.iter().map(|(_, v)| bson::decode(v)).collect())
    }

    /// `createCollection($name, $attributes, $indexes)`.
    pub(super) async fn create_collection_impl(
        &self,
        name: &str,
        attributes: &[Document],
        indexes: &[Document],
    ) -> Result<bool> {
        let id = self.name(name)?;
        let shared = self.shared_tables() || name == Database::METADATA;
        if self.depth() == 0 && shared && self.exists_impl(&self.namespace(), Some(name)).await? {
            return Ok(true);
        }
        if let Err(e) = self.client.create_collection(&id, self.options(BsonDocument::new())).await {
            let processed = self.process_exception(e.clone());
            if processed.is_a(Kind::Duplicate) {
                if shared {
                    return Ok(true);
                }
                return Err(processed);
            }
            if processed.code() == 0 && processed.message().to_ascii_lowercase().contains("collection exists") {
                if shared {
                    return Ok(true);
                }
                return Err(Error::duplicate("Collection already exists").with_previous(processed));
            }
            return Err(processed);
        }
        let asc = Self::order(Database::ORDER_ASC)?;
        let mut internal = vec![
            doc! { "key": { "_uid": asc }, "name": "_uid", "unique": true, "collation": { "locale": "en", "strength": 1 } },
            doc! { "key": { "_createdAt": asc }, "name": "_createdAt" },
            doc! { "key": { "_updatedAt": asc }, "name": "_updatedAt" },
            doc! { "key": { "_permissions": asc }, "name": "_permissions" },
        ];
        if self.shared_tables() {
            for index in &mut internal {
                let mut key = doc! { "_tenant": asc };
                if let Ok(rest) = index.get_document("key") {
                    key.extend(rest.clone());
                }
                index.insert("key", key);
            }
        }
        self.client
            .create_indexes(&id, internal, self.options(BsonDocument::new()))
            .await
            .map_err(|e| self.process_exception(e))?;
        if indexes.is_empty() {
            return Ok(true);
        }
        let mut created = Vec::with_capacity(indexes.len());
        for index in indexes {
            let kind = text(index, "type");
            let index_attributes = strings(index.get("attributes"));
            let orders = strings(index.get("orders"));
            let mut key = BsonDocument::new();
            let mut unique = false;
            if self.should_add_tenant_to_index(&kind) {
                key.insert("_tenant", asc);
            }
            for (j, attribute) in index_attributes.iter().enumerate() {
                let attribute = self.filter(Self::internal_key(attribute.as_deref().unwrap_or("")))?;
                let order = || -> Result<Bson> {
                    let order = orders.get(j).cloned().flatten().unwrap_or_else(|| Database::ORDER_ASC.to_owned());
                    Ok(Bson::Int32(Self::order(&self.filter(&order)?)?))
                };
                let value = match kind.as_str() {
                    Database::INDEX_KEY | Database::INDEX_TTL => order()?,
                    Database::INDEX_FULLTEXT => Bson::from("text"),
                    Database::INDEX_UNIQUE => {
                        unique = true;
                        order()?
                    }
                    _ => return Ok(false),
                };
                key.insert(attribute, value);
            }
            let mut spec = doc! { "key": key, "name": self.filter(index.id())?, "unique": unique };
            if kind == Database::INDEX_FULLTEXT {
                spec.insert("default_language", "none");
            }
            if kind == Database::INDEX_TTL {
                let ttl = index.get("ttl").cloned().unwrap_or(Value::Int(0));
                if ttl.cmp_php(&Value::Int(0)) == std::cmp::Ordering::Greater {
                    spec.insert("expireAfterSeconds", bson::value(&ttl)?);
                }
            }
            if kind == Database::INDEX_UNIQUE || kind == Database::INDEX_KEY {
                let mut partial = BsonDocument::new();
                for attribute in &index_attributes {
                    let attribute = attribute.clone().unwrap_or_default();
                    let attribute_type = attributes
                        .iter()
                        .find(|a| a.id() == attribute)
                        .map(|a| type_code(Some(&text(a, "type"))))
                        .unwrap_or_else(|| type_code(None));
                    let field = self.filter(Self::internal_key(&attribute))?;
                    partial.insert(field, doc! { "$exists": true, "$type": attribute_type });
                }
                if !partial.is_empty() {
                    spec.insert("partialFilterExpression", partial);
                }
            }
            created.push(spec);
        }
        self.client
            .create_indexes(&id, created, self.options(BsonDocument::new()))
            .await
            .map_err(|e| self.process_exception(e))?;
        Ok(true)
    }

    /// `shouldAddTenantToIndex($type)`: in shared tables, every index but
    /// TTL ones starts with `_tenant`.
    pub(super) fn should_add_tenant_to_index(&self, kind: &str) -> bool {
        self.shared_tables() && kind != Database::INDEX_TTL
    }

    /// `getSizeOfCollection($collection)`: `collStats`'s `totalSize`.
    pub(super) async fn size_of_collection_impl(&self, collection: &str) -> Result<i64> {
        let name = self.name(collection)?;
        match self.client.query(doc! { "collStats": name, "scale": 1 }, None).await {
            Ok(Reply::Document(d)) => Ok(match d.get("totalSize") {
                Some(Bson::Int32(i)) => i64::from(*i),
                Some(Bson::Int64(i)) => *i,
                Some(Bson::Double(f)) => *f as i64,
                _ => 0,
            }),
            Ok(Reply::Count(_)) => Err(Error::database("Failed to get collection size: No size found")),
            Err(e) => Err(Error::database(format!("Failed to get collection size: {}", e.message()))),
        }
    }

    /// `deleteAttribute($collection, $id)`: `$unset` of the field in every document.
    pub(super) async fn delete_attribute_impl(&self, collection: &str, id: &str) -> Result<bool> {
        let name = self.name(collection)?;
        let mut unset = BsonDocument::new();
        unset.insert(id, "");
        self.client
            .update(&name, doc! {}, doc! { "$unset": unset }, Options::new(), true)
            .await
            .map_err(from_client)?;
        Ok(true)
    }

    /// `renameAttribute($collection, $id, $name)`: `$rename` in every document.
    pub(super) async fn rename_attribute_impl(&self, collection: &str, old: &str, new: &str) -> Result<bool> {
        let name = self.name(collection)?;
        let from = self.filter(Self::internal_key(old))?;
        let to = self.filter(Self::internal_key(new))?;
        let mut rename = BsonDocument::new();
        rename.insert(from, to);
        self.client
            .update(&name, doc! {}, doc! { "$rename": rename }, self.options(BsonDocument::new()), true)
            .await
            .map_err(from_client)?;
        Ok(true)
    }

    /// `$rename` of `from` to `to` (`null` when there is no new key) in
    /// every document of `collection`.
    async fn rename_field(&self, collection: &str, from: &str, to: Option<&str>) -> Result<()> {
        let mut rename = BsonDocument::new();
        rename.insert(from, to.map_or(Bson::Null, Bson::from));
        self.client
            .update(collection, doc! {}, doc! { "$rename": rename }, Options::new(), true)
            .await
            .map_err(from_client)?;
        Ok(())
    }

    /// `$unset` of `field` in every document of `collection`.
    async fn unset_field(&self, collection: &str, field: &str) -> Result<()> {
        let mut unset = BsonDocument::new();
        unset.insert(field, "");
        self.client
            .update(collection, doc! {}, doc! { "$unset": unset }, Options::new(), true)
            .await
            .map_err(from_client)?;
        Ok(())
    }

    /// The junction collection of a many-to-many relationship, from the
    /// two collections' sequences in the metadata.
    async fn junction(&self, collection: &str, related: &str, side: &str) -> Result<String> {
        let metadata = Document::from_pairs([("$id", Value::from(Database::METADATA))])?;
        let parent = self.get_document_impl(&metadata, collection, &[], false).await?;
        let child = self.get_document_impl(&metadata, related, &[], false).await?;
        if parent.is_empty() || child.is_empty() {
            return Err(Error::database("Collection or related collection not found"));
        }
        let sequence = |d: &Document| d.sequence().map(crate::value::to_text).unwrap_or_default();
        let id = if side == Database::RELATION_SIDE_PARENT {
            format!("_{}_{}", sequence(&parent), sequence(&child))
        } else {
            format!("_{}_{}", sequence(&child), sequence(&parent))
        };
        self.name(&id)
    }

    /// `updateRelationship(...)`: renames the relationship's fields.
    #[allow(clippy::too_many_arguments)]
    pub(super) async fn update_relationship_impl(
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
        let collection_name = self.name(collection)?;
        let related_name = self.name(related_collection)?;
        let escaped_key = Self::escape_field_name(key);
        let escaped_new_key = new_key.map(Self::escape_field_name);
        let escaped_two_way_key = Self::escape_field_name(two_way_key);
        let escaped_new_two_way_key = new_two_way_key.map(Self::escape_field_name);
        let rename_key = new_key.is_some_and(|n| n != key);
        let rename_two_way = two_way && new_two_way_key.is_some_and(|n| n != two_way_key);
        match kind {
            Database::RELATION_ONE_TO_ONE => {
                if rename_key {
                    self.rename_field(&collection_name, &escaped_key, escaped_new_key.as_deref()).await?;
                }
                if rename_two_way {
                    self.rename_field(&related_name, &escaped_two_way_key, escaped_new_two_way_key.as_deref()).await?;
                }
            }
            Database::RELATION_ONE_TO_MANY => {
                if rename_two_way {
                    self.rename_field(&related_name, &escaped_two_way_key, escaped_new_two_way_key.as_deref()).await?;
                }
            }
            Database::RELATION_MANY_TO_ONE => {
                if rename_key {
                    self.rename_field(&collection_name, &escaped_key, escaped_new_key.as_deref()).await?;
                }
            }
            Database::RELATION_MANY_TO_MANY => {
                let junction = self.junction(collection, related_collection, side).await?;
                if rename_key {
                    self.rename_field(&junction, &escaped_key, escaped_new_key.as_deref()).await?;
                }
                if rename_two_way {
                    self.rename_field(&junction, &escaped_two_way_key, escaped_new_two_way_key.as_deref()).await?;
                }
            }
            _ => return Err(Error::database("Invalid relationship type")),
        }
        Ok(true)
    }

    /// `deleteRelationship(...)`: unsets the relationship's fields, or drops
    /// the junction collection of a many-to-many one.
    #[allow(clippy::too_many_arguments)]
    pub(super) async fn delete_relationship_impl(
        &self,
        collection: &str,
        related_collection: &str,
        kind: &str,
        two_way: bool,
        key: &str,
        two_way_key: &str,
        side: &str,
    ) -> Result<bool> {
        let collection_name = self.name(collection)?;
        let related_name = self.name(related_collection)?;
        let key = Self::escape_field_name(key);
        let two_way_key = Self::escape_field_name(two_way_key);
        let parent = side == Database::RELATION_SIDE_PARENT;
        match kind {
            Database::RELATION_ONE_TO_ONE => {
                if parent {
                    self.unset_field(&collection_name, &key).await?;
                    if two_way {
                        self.unset_field(&related_name, &two_way_key).await?;
                    }
                } else if side == Database::RELATION_SIDE_CHILD {
                    self.unset_field(&related_name, &two_way_key).await?;
                    if two_way {
                        self.unset_field(&collection_name, &key).await?;
                    }
                }
            }
            Database::RELATION_ONE_TO_MANY => {
                if parent {
                    self.unset_field(&related_name, &two_way_key).await?;
                } else {
                    self.unset_field(&collection_name, &key).await?;
                }
            }
            Database::RELATION_MANY_TO_ONE => {
                if parent {
                    self.unset_field(&collection_name, &key).await?;
                } else {
                    self.unset_field(&related_name, &two_way_key).await?;
                }
            }
            Database::RELATION_MANY_TO_MANY => {
                let junction = self.junction(collection, related_collection, side).await?;
                self.client.drop_collection(&junction, Options::new()).await.map_err(from_client)?;
            }
            _ => return Err(Error::database("Invalid relationship type")),
        }
        Ok(true)
    }

    /// `createIndex(...)`: the index, with a partial filter of the
    /// attributes' types (unique and key indexes) and English, case
    /// insensitive collation when asked; a unique index is waited for until
    /// it is built.
    #[allow(clippy::too_many_arguments)]
    pub(super) async fn create_index_impl(
        &self,
        collection: &str,
        id: &str,
        kind: &str,
        attributes: &[String],
        _lengths: &[Value],
        orders: &[Value],
        attribute_types: &[(String, String)],
        collation: &[String],
        ttl: i64,
    ) -> Result<bool> {
        let name = self.name(collection)?;
        let id = self.filter(id)?;
        let mut key = BsonDocument::new();
        let mut unique = false;
        if self.should_add_tenant_to_index(kind) {
            key.insert("_tenant", Self::order(Database::ORDER_ASC)?);
        }
        let type_of = |attribute: &str| attribute_types.iter().find(|(a, _)| a == attribute).map(|(_, t)| t.as_str());
        let bson_types: Vec<Bson> = attributes.iter().map(|a| type_code(type_of(a))).collect();
        let mut fields = Vec::with_capacity(attributes.len());
        for (i, attribute) in attributes.iter().enumerate() {
            let field = if type_of(attribute) == Some(Database::VAR_OBJECT) && attribute.contains('.') {
                attribute.split('.').map(|part| self.filter(part)).collect::<Result<Vec<_>>>()?.join(".")
            } else {
                self.filter(Self::internal_key(attribute))?
            };
            let order = match orders.get(i) {
                None | Some(Value::Null) => Database::ORDER_ASC.to_owned(),
                Some(v) => crate::value::to_text(v),
            };
            let order = Self::order(&self.filter(&order)?)?;
            key.insert(field.clone(), order);
            match kind {
                Database::INDEX_KEY | Database::INDEX_TTL => {}
                Database::INDEX_FULLTEXT => {
                    key.insert(field.clone(), "text");
                }
                Database::INDEX_UNIQUE => unique = true,
                _ => return Ok(false),
            }
            fields.push(field);
        }
        // PHP's array order: `name`, then `key` (once a key is set), then
        // `unique` (set inside the loop, after the first key).
        let mut spec = doc! { "name": &id };
        if !key.is_empty() {
            spec.insert("key", key);
        }
        if unique {
            spec.insert("unique", true);
        }
        if !collation.is_empty() && kind != Database::INDEX_FULLTEXT {
            spec.insert("collation", doc! { "locale": "en", "strength": 1 });
        }
        if kind == Database::INDEX_FULLTEXT {
            spec.insert("default_language", "none");
        }
        if kind == Database::INDEX_TTL && ttl > 0 {
            spec.insert("expireAfterSeconds", utopia_mongo::client::int(ttl));
        }
        if kind == Database::INDEX_UNIQUE || kind == Database::INDEX_KEY {
            let mut partial = BsonDocument::new();
            for (i, field) in fields.iter().enumerate() {
                partial.insert(field.clone(), doc! { "$exists": true, "$type": bson_types[i].clone() });
            }
            if !partial.is_empty() {
                spec.insert("partialFilterExpression", partial);
            }
        }
        if let Err(e) = self.client.create_indexes(&name, vec![spec], Options::new()).await {
            if matches!(e.code(), 11000 | 11001) {
                return Err(Error::new(Kind::Unique, "Unique index violation")
                    .with_code(e.code())
                    .with_previous(from_client(e)));
            }
            return Err(self.process_exception(e));
        }
        if kind != Database::INDEX_UNIQUE {
            return Ok(true);
        }
        // MongoDB builds indexes in the background: wait until it is ready,
        // so the constraint holds when this returns.
        let retries = 10;
        for attempt in 0..retries {
            match self.client.query(doc! { "listIndexes": &name }, None).await {
                Ok(Reply::Document(list)) => {
                    let batch = list.get_document("cursor").ok().and_then(|c| c.get_array("firstBatch").ok());
                    let ready = batch.is_some_and(|b| {
                        b.iter().any(|i| match i {
                            Bson::Document(i) => {
                                i.get_str("name").is_ok_and(|n| n == id)
                                    && i.get("buildState").is_none_or(|s| s.as_str() == Some("ready"))
                            }
                            _ => false,
                        })
                    });
                    if ready {
                        return Ok(true);
                    }
                }
                Ok(Reply::Count(_)) => {}
                Err(e) => {
                    if attempt >= retries - 1 {
                        let code = e.code();
                        return Err(Error::database(format!("Timeout waiting for index creation: {}", e.message()))
                            .with_code(code)
                            .with_previous(from_client(e)));
                    }
                }
            }
            let delay = (50_000u64 * 2u64.pow(attempt)).min(500_000);
            tokio::time::sleep(Duration::from_micros(delay)).await;
        }
        Err(Error::database(format!("Index {id} creation timed out after {retries} retries")))
    }

    /// `renameIndex($collection, $old, $new)`: the index dropped and created
    /// again under the new name, from its definition in the metadata.
    pub(super) async fn rename_index_impl(&self, collection: &str, old: &str, new: &str) -> Result<bool> {
        let collection = self.filter(collection)?;
        let metadata = Document::from_pairs([("$id", Value::from(Database::METADATA))])?;
        let document = self.get_document_impl(&metadata, &collection, &[], false).await?;
        let old = self.filter(old)?;
        let new = self.filter(new)?;
        let decode = |key: &str| -> Value {
            match document.get(key) {
                Some(Value::Str(s)) => {
                    php_std::json::decode_ext(s.as_bytes(), Some(true), 512, php_std::json::Flags::NONE)
                        .unwrap_or(Value::Null)
                }
                _ => Value::Null,
            }
        };
        let indexes = decode("indexes");
        let attributes = decode("attributes");
        let field = |node: &Value, key: &str| -> Option<Value> {
            match node {
                Value::Array(a) => a.get_str(key).cloned(),
                _ => None,
            }
        };
        let index = match &indexes {
            Value::Array(list) => list
                .values()
                .find(|node| {
                    let id = field(node, "$id")
                        .filter(|v| !super::null(v))
                        .or_else(|| field(node, "key").filter(|v| !super::null(v)));
                    id.map(|v| crate::value::to_text(&v)).unwrap_or_default() == old
                })
                .cloned(),
            _ => None,
        };
        let Some(index) = index else {
            return Err(Error::database(format!("Index not found: {old}")));
        };
        let index_attributes: Vec<String> = match field(&index, "attributes") {
            Some(Value::Array(a)) => a.values().map(crate::value::to_text).collect(),
            _ => Vec::new(),
        };
        let mut types = Vec::new();
        if let Value::Array(list) = &attributes {
            for name in &index_attributes {
                if let Some(attribute) =
                    list.values().find(|a| field(a, "key").is_some_and(|k| crate::value::to_text(&k) == *name))
                {
                    types.push((
                        name.clone(),
                        field(attribute, "type").map(|t| crate::value::to_text(&t)).unwrap_or_default(),
                    ));
                }
            }
        }
        let list = |key: &str| -> Vec<Value> {
            match field(&index, key) {
                Some(Value::Array(a)) => a.values().cloned().collect(),
                _ => Vec::new(),
            }
        };
        let kind = field(&index, "type").map(|t| crate::value::to_text(&t)).unwrap_or_default();
        let ttl = match field(&index, "ttl") {
            Some(Value::Int(i)) => i,
            _ => 0,
        };
        let deleted = self.delete_index(&collection, &old).await.map_err(|e| self.process(e))?;
        let created = self
            .create_index_impl(
                &collection,
                &new,
                &kind,
                &index_attributes,
                &list("lengths"),
                &list("orders"),
                &types,
                &[],
                ttl,
            )
            .await
            .map_err(|e| self.process(e))?;
        Ok(deleted && created)
    }
}
