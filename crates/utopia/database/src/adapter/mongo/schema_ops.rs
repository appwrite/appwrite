//! Databases, collections, indexes and relationships.
//!
//! PHP: `Mongo::update`, `getCollectionNames`, `getDatabaseNames`,
//! `collectionExists`, `list`, `createCollection`, `adoptCollection`,
//! `createCollectionIndexes`, `indexCreationError`, `dropCreatedCollection`,
//! `getSizeOfCollection`, `createIndex`, `renameIndex`,
//! `getPartialFilterExpression`, `getMongoTypeCode`, `indexedColumnType`,
//! `updateRelationship`, `deleteRelationship`, `getJunctionName`.

use std::time::Duration;

use utopia_mongo::bson::{Bson, Document as BsonDocument, doc};
use utopia_mongo::{Options, Reply};
use utopia_query::OrderDirection;
use utopia_query::schema::{ColumnType, IndexType};

use super::filters::{assoc, map};
use super::{Mongo, bson, from_client, is_mongo, key, process_exception, text};
use crate::adapter::{Adapter, filter};
use crate::attribute::Attribute;
use crate::collection::Collection;
use crate::database;
use crate::document::Document;
use crate::error::{Error, Kind, Result};
use crate::index::Index;
use crate::relationship::{self, Relationship, RelationshipSide, RelationshipType};
use crate::storage;
use crate::value::{Array, Value};

/// Every BSON type the driver writes but null (`NON_NULL_BSON_TYPES`).
const NON_NULL_BSON_TYPES: [&str; 16] = [
    "double",
    "string",
    "object",
    "array",
    "binData",
    "objectId",
    "bool",
    "date",
    "regex",
    "javascript",
    "int",
    "timestamp",
    "long",
    "decimal",
    "minKey",
    "maxKey",
];

/// `getMongoTypeCode($type)`: the BSON types a stored value of the column
/// type can have; any but null for an unknown type.
fn type_code(kind: Option<ColumnType>) -> Value {
    let list = |types: &[&str]| Value::Array(types.iter().map(|t| text(t)).collect());
    match kind {
        None => list(&NON_NULL_BSON_TYPES),
        Some(
            ColumnType::String
            | ColumnType::Varchar
            | ColumnType::Text
            | ColumnType::MediumText
            | ColumnType::LongText
            | ColumnType::Id
            | ColumnType::Uuid7,
        ) => text("string"),
        Some(ColumnType::BigInteger | ColumnType::Integer) => list(&["int", "long"]),
        Some(ColumnType::Float | ColumnType::Double) => list(&["double", "int", "long"]),
        Some(ColumnType::Boolean) => text("bool"),
        Some(ColumnType::Datetime) => text("date"),
        Some(_) => text("string"),
    }
}

/// `getPartialFilterExpression($type, $fields)`: a unique index requires
/// every field to exist with its stored type, a key index only its leading
/// field to exist.
fn partial_filter_expression(kind: IndexType, fields: &[(String, Option<ColumnType>)]) -> Value {
    if kind != IndexType::Unique {
        let first = fields.first().map(|(f, _)| f.as_str()).unwrap_or_default();
        return assoc([(first, assoc([("$exists", Value::Bool(true))]))]);
    }
    let mut filter = Array::new();
    for (field, kind) in fields {
        filter.set(key(field), assoc([("$exists", Value::Bool(true)), ("$type", type_code(*kind))]));
    }
    Value::Array(filter)
}

/// `indexedColumnType($type)`: the type of a stored type string, `None`
/// for one the library does not know.
fn indexed_column_type(kind: Option<ColumnType>) -> Option<ColumnType> {
    Attribute::type_from_stored(kind?.as_str()).ok()
}

/// `$reply->cursor->firstBatch` of a reply.
fn first_batch(reply: &Reply) -> Option<Vec<Value>> {
    let Reply::Document(d) = reply else { return None };
    let cursor = d.get_document("cursor").ok()?;
    match cursor.get("firstBatch") {
        Some(Bson::Array(items)) => Some(items.iter().map(bson::decode).collect()),
        _ => None,
    }
}

/// `$object->$name ?? null` of a decoded object.
fn property<'a>(value: &'a Value, name: &str) -> Option<&'a Value> {
    match value {
        Value::Object(a) | Value::Array(a) => a.get_str(name).filter(|v| !matches!(v, Value::Null)),
        _ => None,
    }
}

impl Mongo {
    /// `getDatabaseNames()`: the names of the databases.
    pub(crate) async fn database_names(&self) -> Result<Vec<String>> {
        let listed = self.driver.list_database_names().await.map_err(from_client)?;
        let mut names = Vec::new();
        if let Some(Bson::Array(databases)) = listed.get("databases") {
            for database in databases {
                if let Some(Value::Str(name)) = property(&bson::decode(database), "name") {
                    names.push(String::from_utf8_lossy(name.as_bytes()).into_owned());
                }
            }
        }
        Ok(names)
    }

    /// `list()` as PHP returns it: the values of the `listDatabases` reply
    /// (the database list, the sizes, `ok`).
    pub async fn database_list(&self) -> Result<Vec<Value>> {
        let reply = self.driver.list_database_names().await.map_err(from_client)?;
        Ok(reply.iter().map(|(_, v)| bson::decode(v)).collect())
    }

    /// `list()`: each value of the `listDatabases` reply as a document.
    pub(crate) async fn list_databases(&self) -> Result<Vec<Document>> {
        let mut out = Vec::new();
        for value in self.database_list().await? {
            let array = match value {
                Value::Array(a) | Value::Object(a) => a,
                other => Array::from_list(vec![other]),
            };
            out.push(Document::new(array)?);
        }
        Ok(out)
    }

    /// `getCollectionNames($database)`: the collections a rename moves,
    /// every one but the server's own.
    async fn collection_names(&self, database: &str) -> Result<Vec<String>> {
        let listed = self
            .driver
            .query(None, doc! { "listCollections": 1, "nameOnly": true }, Some(database))
            .await
            .map_err(from_client)?;
        let Reply::Document(listed) = listed else { return Ok(Vec::new()) };
        let cursor = listed.get_document("cursor").cloned().unwrap_or_default();
        if cursor.get("id").is_some_and(|id| bson::decode(id).truthy()) {
            return Err(Error::database(
                "Database has more collections than one listing returns, so it cannot be renamed",
            ));
        }
        let mut names = Vec::new();
        if let Some(Bson::Array(collections)) = cursor.get("firstBatch") {
            for collection in collections {
                if let Some(Value::Str(name)) = property(&bson::decode(collection), "name") {
                    let name = String::from_utf8_lossy(name.as_bytes()).into_owned();
                    if !name.starts_with("system.") {
                        names.push(name);
                    }
                }
            }
        }
        Ok(names)
    }

    /// `update($name, $new)`: moves every collection into the new database
    /// with `renameCollection`, then drops the emptied one; a failure part
    /// way moves the moved collections back, newest first.
    pub(crate) async fn rename_database(&self, name: &str, new: &str) -> Result<bool> {
        if self.shared_tables() {
            return Err(Error::database("Cannot rename a database while shared tables are enabled"));
        }
        let name = filter(name);
        let new = filter(new);
        let hello = self.driver.query(None, doc! { "hello": 1 }, Some("admin")).await.map_err(from_client)?;
        if let Reply::Document(hello) = &hello
            && matches!(hello.get("msg"), Some(Bson::String(m)) if m == "isdbgrid")
        {
            return Err(Error::database("Renaming a database is not supported on a sharded MongoDB cluster"));
        }
        let databases = self.database_names().await?;
        if !databases.contains(&name) {
            return Err(Error::not_found("Database not found"));
        }
        if databases.contains(&new) {
            return Err(Error::duplicate("Database already exists"));
        }
        let mut moved: Vec<String> = Vec::new();
        let outcome: Result<()> = async {
            for collection in self.collection_names(&name).await? {
                let command =
                    doc! { "renameCollection": format!("{name}.{collection}"), "to": format!("{new}.{collection}") };
                self.driver.query(None, command, Some("admin")).await.map_err(from_client)?;
                moved.push(collection);
            }
            Ok(())
        }
        .await;
        if let Err(error) = outcome {
            for collection in moved.iter().rev() {
                let command =
                    doc! { "renameCollection": format!("{new}.{collection}"), "to": format!("{name}.{collection}") };
                self.driver.query(None, command, Some("admin")).await.map_err(from_client)?;
            }
            return Err(if is_mongo(&error) { process_exception(error) } else { error });
        }
        self.driver.drop_database(BsonDocument::new(), Some(&name)).await.map_err(from_client)?;
        Ok(true)
    }

    /// `collectionExists($database, $collection)`: an empty database name
    /// asks the client's database; a failure is `false`.
    pub(crate) async fn collection_exists_in(&self, database: &str, collection: &str) -> bool {
        let database = filter(database);
        let command = doc! { "listCollections": 1, "filter": { "name": self.name(collection) } };
        let db = if database.is_empty() { None } else { Some(database.as_str()) };
        match self.driver.query(None, command, db).await {
            Ok(reply) => first_batch(&reply).is_some_and(|batch| !batch.is_empty()),
            Err(_) => false,
        }
    }

    /// `createCollection($collection, $attributes, $indexes)`.
    ///
    /// A shared (or metadata) collection another creator made gets its
    /// indexes and is reported as existing ([`Kind::Duplicate`]); a
    /// collection this call created without its indexes is dropped (outside
    /// a transaction).
    pub(crate) async fn create_collection_impl(
        &self,
        collection: &str,
        attributes: &[Attribute],
        indexes: &[Index],
    ) -> Result<bool> {
        let id = self.name(collection);
        let shared = self.shared_tables() || collection == database::METADATA;
        if self.depth() == 0 && shared && self.collection_exists_in(&self.database(), collection).await {
            return self.adopt_collection(&id, attributes, indexes, None).await;
        }
        if let Err(e) = self.driver.create_collection(&id, self.options(BsonDocument::new())).await {
            let error = from_client(e);
            if is_mongo(&error) {
                let error = if error.message().contains("Collection Exists") {
                    Error::duplicate("Collection already exists").with_previous(error)
                } else {
                    process_exception(error)
                };
                if error.is_a(Kind::Duplicate) && shared {
                    return self.adopt_collection(&id, attributes, indexes, Some(error)).await;
                }
                return Err(error);
            }
            return Err(error);
        }
        if shared {
            return self.create_collection_indexes(&id, attributes, indexes).await;
        }
        let indexed = match self.create_collection_indexes(&id, attributes, indexes).await {
            Ok(indexed) => indexed,
            Err(e) => {
                self.drop_created_collection(&id).await;
                return Err(e);
            }
        };
        if !indexed {
            self.drop_created_collection(&id).await;
        }
        Ok(indexed)
    }

    /// `adoptCollection()`: the indexes, then the collection reported as
    /// existing; `false` when an index could not be created.
    async fn adopt_collection(
        &self,
        id: &str,
        attributes: &[Attribute],
        indexes: &[Index],
        exists: Option<Error>,
    ) -> Result<bool> {
        if !self.create_collection_indexes(id, attributes, indexes).await? {
            return Ok(false);
        }
        Err(exists.unwrap_or_else(|| Error::duplicate("Collection already exists")))
    }

    /// `indexCreationError($error)`: an index the collection's indexes
    /// conflict with is an index error, not the collection existing.
    fn index_creation_error(error: Error) -> Error {
        let mapped = process_exception(error.clone());
        if mapped.is_a(Kind::Duplicate) {
            return Error::index(mapped.message()).with_previous(error);
        }
        mapped
    }

    /// `dropCreatedCollection($id)`: best effort, and never inside a
    /// transaction (MongoDB runs no drop in one; its abort discards the
    /// collection).
    async fn drop_created_collection(&self, id: &str) {
        if self.depth() > 0 {
            return;
        }
        let _ = self.driver.drop_collection(id, Options::new()).await;
    }

    /// `createCollectionIndexes($id, $attributes, $indexes)`: the internal
    /// indexes, then the declared ones; `false` when one is not created.
    async fn create_collection_indexes(&self, id: &str, attributes: &[Attribute], indexes: &[Index]) -> Result<bool> {
        let asc = || Value::Int(1);
        let mut internal = [
            map([
                ("key", assoc([(storage::UID, asc())])),
                ("name", text(storage::UID)),
                ("unique", Value::Bool(true)),
                ("collation", assoc([("locale", text("en")), ("strength", Value::Int(1))])),
            ]),
            map([("key", assoc([(storage::CREATED_AT, asc())])), ("name", text(storage::CREATED_AT))]),
            map([("key", assoc([(storage::UPDATED_AT, asc())])), ("name", text(storage::UPDATED_AT))]),
            map([("key", assoc([(storage::PERMISSIONS, asc())])), ("name", text(storage::PERMISSIONS))]),
        ];
        if self.shared_tables() {
            for index in internal.iter_mut() {
                let mut k = map([(storage::TENANT, asc())]);
                if let Some(Value::Array(existing)) = index.get_str("key") {
                    for (name, order) in existing.iter() {
                        k.set(name.to_owned(), order.clone());
                    }
                }
                index.set(key("key"), Value::Array(k));
            }
        }
        let internal: Vec<BsonDocument> = internal.iter().map(bson::document).collect::<Result<_>>()?;
        let created = self
            .driver
            .create_indexes(id, internal, self.options(BsonDocument::new()))
            .await
            .map_err(|e| Self::index_creation_error(from_client(e)))?;
        if !created {
            return Ok(false);
        }
        if indexes.is_empty() {
            return Ok(true);
        }
        let mut declared: Vec<BsonDocument> = Vec::with_capacity(indexes.len());
        for index in indexes {
            let mut k = Array::new();
            let mut unique = false;
            if self.should_add_tenant_to_index(index.kind) {
                k.set(key(storage::TENANT), Value::Int(1));
            }
            for (position, attribute) in index.attributes.iter().enumerate() {
                let field = filter(Self::internal_key(attribute));
                let direction = index.orders.get(position).copied().flatten().unwrap_or(OrderDirection::Asc);
                let order = match index.kind {
                    IndexType::Key | IndexType::Ttl => Value::Int(Self::order(direction)?),
                    IndexType::Fulltext => text("text"),
                    IndexType::Unique => {
                        unique = true;
                        Value::Int(Self::order(direction)?)
                    }
                    _ => return Ok(false),
                };
                k.set(key(&field), order);
            }
            let mut spec =
                map([("key", Value::Array(k)), ("name", text(&filter(&index.key))), ("unique", Value::Bool(unique))]);
            if index.kind == IndexType::Fulltext {
                spec.set(key("default_language"), text("none"));
            }
            if index.kind == IndexType::Ttl
                && let Some(ttl) = index.ttl.filter(|t| *t > 0)
            {
                spec.set(key("expireAfterSeconds"), Value::Int(ttl));
            }
            if matches!(index.kind, IndexType::Unique | IndexType::Key) {
                let mut fields: Vec<(String, Option<ColumnType>)> = Vec::new();
                for indexed in &index.attributes {
                    let kind = attributes.iter().find(|a| &a.key == indexed).map(|a| a.kind);
                    let field = filter(Self::internal_key(indexed));
                    match fields.iter_mut().find(|(f, _)| *f == field) {
                        Some(entry) => entry.1 = kind,
                        None => fields.push((field, kind)),
                    }
                }
                if !fields.is_empty() {
                    spec.set(key("partialFilterExpression"), partial_filter_expression(index.kind, &fields));
                }
            }
            declared.push(bson::document(&spec)?);
        }
        let created = self
            .driver
            .create_indexes(id, declared, self.options(BsonDocument::new()))
            .await
            .map_err(|e| Self::index_creation_error(from_client(e)))?;
        Ok(created)
    }

    /// `getSizeOfCollection($collection)`: the collection's `totalSize`.
    pub(crate) async fn size_of_collection_impl(&self, collection: &str) -> Result<i64> {
        let command = doc! { "collStats": self.name(collection), "scale": 1 };
        let failed = |message: &str| Error::database(format!("Failed to get collection size: {message}"));
        match self.driver.query(None, command, None).await {
            Ok(Reply::Document(d)) => match d.get("totalSize").map(bson::decode) {
                Some(Value::Int(i)) => Ok(i),
                Some(v) if super::casting::is_numeric(&v) => Ok(super::casting::int(&v)),
                Some(Value::Null) | None => Err(failed("No size found")),
                Some(_) => Ok(0),
            },
            Ok(Reply::Count(_)) => Err(failed("No size found")),
            Err(e) => Err(failed(e.message())),
        }
    }

    /// `createIndex($collection, $index, $indexAttributeTypes, $collation)`:
    /// the index, waiting for a unique one to be built so it is enforced at
    /// once.
    ///
    /// Fails ([`Kind::Unique`]) when stored values violate a unique index.
    pub(crate) async fn create_index_impl(
        &self,
        collection: &str,
        index: &Index,
        attribute_types: &[(String, ColumnType)],
        collation: bool,
    ) -> Result<bool> {
        let name = self.name(collection);
        let id = filter(&index.key);
        let kind = index.kind;
        let type_of = |attribute: &str| attribute_types.iter().rev().find(|(a, _)| a == attribute).map(|(_, t)| *t);
        let mut spec = map([("name", text(&id))]);
        let mut index_key = Array::new();
        if self.should_add_tenant_to_index(kind) {
            index_key.set(key(storage::TENANT), Value::Int(1));
        }
        let mut fields: Vec<String> = Vec::with_capacity(index.attributes.len());
        for (position, attribute) in index.attributes.iter().enumerate() {
            let field = if attribute.contains('.') && type_of(attribute) == Some(ColumnType::Object) {
                attribute.split('.').map(filter).collect::<Vec<_>>().join(".")
            } else {
                filter(Self::internal_key(attribute))
            };
            let direction = index.orders.get(position).copied().flatten().unwrap_or(OrderDirection::Asc);
            index_key.set(key(&field), Value::Int(Self::order(direction)?));
            match kind {
                IndexType::Key | IndexType::Ttl => {}
                IndexType::Fulltext => {
                    index_key.set(key(&field), text("text"));
                }
                IndexType::Unique => {
                    spec.set(key("unique"), Value::Bool(true));
                }
                _ => return Ok(false),
            }
            fields.push(field);
        }
        spec.set(key("key"), Value::Array(index_key));
        if collation && kind != IndexType::Fulltext {
            spec.set(key("collation"), assoc([("locale", text("en")), ("strength", Value::Int(1))]));
        }
        if kind == IndexType::Fulltext {
            spec.set(key("default_language"), text("none"));
        }
        if kind == IndexType::Ttl
            && let Some(ttl) = index.ttl.filter(|t| *t > 0)
        {
            spec.set(key("expireAfterSeconds"), Value::Int(ttl));
        }
        if matches!(kind, IndexType::Unique | IndexType::Key) {
            let mut typed: Vec<(String, Option<ColumnType>)> = Vec::new();
            for (position, field) in fields.iter().enumerate() {
                let kind = indexed_column_type(type_of(&index.attributes[position]));
                match typed.iter_mut().find(|(f, _)| f == field) {
                    Some(entry) => entry.1 = kind,
                    None => typed.push((field.clone(), kind)),
                }
            }
            if !typed.is_empty() {
                spec.set(key("partialFilterExpression"), partial_filter_expression(kind, &typed));
            }
        }
        let outcome: Result<bool> = async {
            let spec = bson::document(&spec)?;
            let result = self.driver.create_indexes(&name, vec![spec], Options::new()).await.map_err(from_client)?;
            if kind != IndexType::Unique {
                return Ok(result);
            }
            const RETRIES: u32 = 10;
            let base = Duration::from_millis(50);
            let max = Duration::from_millis(500);
            let mut retry: u32 = 0;
            while retry < RETRIES {
                match self.driver.query(None, doc! { "listIndexes": &name }, None).await {
                    Ok(reply) => {
                        for existing in first_batch(&reply).unwrap_or_default() {
                            let named = matches!(property(&existing, "name"), Some(Value::Str(n)) if n.as_bytes() == id.as_bytes());
                            let ready = match property(&existing, "buildState") {
                                None => true,
                                Some(Value::Str(state)) => state.as_bytes() == b"ready",
                                Some(_) => false,
                            };
                            if named && ready {
                                return Ok(result);
                            }
                        }
                    }
                    Err(e) => {
                        if retry >= RETRIES - 1 {
                            let e = from_client(e);
                            return Err(Error::database(format!("Timeout waiting for index creation: {}", e.message()))
                                .with_code(e.code())
                                .with_previous(e));
                        }
                    }
                }
                utopia_async::sleep((base * 2u32.pow(retry)).min(max)).await;
                retry += 1;
            }
            Err(Error::database(format!("Index {id} creation timed out after {RETRIES} retries")))
        }
        .await;
        outcome.map_err(|error| {
            if matches!(error.code(), 11000 | 11001) {
                return Error::unique(crate::error::UNIQUE_MESSAGE).with_code(error.code()).with_previous(error);
            }
            process_exception(error)
        })
    }

    /// The metadata document of a collection (`getDocument()` of the
    /// metadata collection).
    async fn metadata_of(&self, collection: &str) -> Result<Document> {
        let metadata =
            Collection::from_document(&Document::from_pairs([(crate::document::ID, text(database::METADATA))])?)?;
        self.get_document_impl(&metadata, collection, &[]).await
    }

    /// `renameIndex($collection, $old, $new)`: the index dropped and created
    /// again under the new name, as the collection's metadata defines it.
    pub(crate) async fn rename_index_impl(&self, collection: &str, old: &str, new: &str) -> Result<bool> {
        let collection = filter(collection);
        let document = self.metadata_of(&collection).await?;
        let definition = Collection::from_document(&document)?;
        let old = filter(old);
        let new = filter(new);
        let index = definition.indexes()?.iter().find(|i| i.key == old).cloned();
        let mut types: Vec<(String, ColumnType)> = Vec::new();
        if let Some(index) = &index {
            let attributes = definition.attributes()?;
            for indexed in &index.attributes {
                if let Some(attribute) = attributes.iter().find(|a| &a.key == indexed) {
                    types.push((indexed.clone(), attribute.kind));
                }
            }
        }
        let outcome: Result<bool> = async {
            let Some(index) = index else {
                return Err(Error::database(format!("Index not found: {old}")));
            };
            let deleted = self.delete_index(&collection, &old).await?;
            let created = self.create_index_impl(&collection, &index.with_key(new.clone()), &types, false).await?;
            Ok(deleted && created)
        }
        .await;
        outcome.map_err(process_exception)
    }

    /// `getJunctionName($collection, $relatedCollection, $side)`: the
    /// junction collection of a many-to-many relationship, named after the
    /// parent's sequence first.
    async fn junction_name(&self, collection: &str, related: &str, side: RelationshipSide) -> Result<String> {
        let document = self.metadata_of(collection).await?;
        let related = self.metadata_of(related).await?;
        if document.is_empty() || related.is_empty() {
            return Err(Error::database("Collection or related collection not found"));
        }
        let sequence = |d: &Document| d.sequence().map(super::filters::stringify).unwrap_or_default();
        let (first, second) = match side {
            RelationshipSide::Parent => (sequence(&document), sequence(&related)),
            RelationshipSide::Child => (sequence(&related), sequence(&document)),
        };
        let name =
            format!("_{}_{}", String::from_utf8_lossy(first.as_bytes()), String::from_utf8_lossy(second.as_bytes()));
        Ok(self.name(&name))
    }

    /// `$this->getClient()->update($name, updates: $update, multi: true)`.
    async fn update_all(&self, name: &str, update: BsonDocument) -> Result<()> {
        self.driver.update(name, BsonDocument::new(), update, Options::new(), true).await.map_err(from_client)?;
        Ok(())
    }

    /// `updateRelationship($collection, $relationship, $side, $update)`:
    /// the stored keys renamed in the collections that hold them.
    pub(crate) async fn update_relationship_impl(
        &self,
        collection: &str,
        relationship: &Relationship,
        side: RelationshipSide,
        update: &relationship::Update,
    ) -> Result<bool> {
        let collection_name = self.name(collection);
        let related_name = self.name(&relationship.related_collection);
        let k = relationship.key.clone().unwrap_or_default();
        let two_way_key = relationship.two_way_key.clone().unwrap_or_default();
        let new_key = update.key.clone();
        let new_two_way_key = update.two_way_key.clone();
        let two_way = update.two_way.unwrap_or(relationship.two_way);
        let rename = |from: &str, to: &Option<String>| -> BsonDocument {
            let to = match to {
                Some(to) => Bson::String(Self::escape_field_name(to)),
                None => Bson::Null,
            };
            let mut fields = BsonDocument::new();
            fields.insert(Self::escape_field_name(from), to);
            doc! { "$rename": fields }
        };
        let key_changes = new_key.as_ref().is_some_and(|n| *n != k);
        let two_way_key_changes = new_two_way_key.as_ref().is_some_and(|n| *n != two_way_key);
        match relationship.kind {
            RelationshipType::OneToOne => {
                if (two_way || side == RelationshipSide::Parent) && key_changes {
                    self.update_all(&collection_name, rename(&k, &new_key)).await?;
                }
                if (two_way || side == RelationshipSide::Child) && two_way_key_changes {
                    self.update_all(&related_name, rename(&two_way_key, &new_two_way_key)).await?;
                }
            }
            RelationshipType::OneToMany => {
                if side == RelationshipSide::Parent {
                    if two_way_key_changes {
                        self.update_all(&related_name, rename(&two_way_key, &new_two_way_key)).await?;
                    }
                } else if key_changes {
                    self.update_all(&collection_name, rename(&k, &new_key)).await?;
                }
            }
            RelationshipType::ManyToOne => {
                if side == RelationshipSide::Child {
                    if two_way_key_changes {
                        self.update_all(&related_name, rename(&two_way_key, &new_two_way_key)).await?;
                    }
                } else if key_changes {
                    self.update_all(&collection_name, rename(&k, &new_key)).await?;
                }
            }
            RelationshipType::ManyToMany => {
                let junction = self.junction_name(collection, &relationship.related_collection, side).await?;
                if key_changes {
                    self.update_all(&junction, rename(&k, &new_key)).await?;
                }
                if two_way_key_changes {
                    self.update_all(&junction, rename(&two_way_key, &new_two_way_key)).await?;
                }
            }
        }
        Ok(true)
    }

    /// `deleteRelationship($collection, $relationship, $side)`: the stored
    /// keys unset, or the junction collection dropped.
    pub(crate) async fn delete_relationship_impl(
        &self,
        collection: &str,
        relationship: &Relationship,
        side: RelationshipSide,
    ) -> Result<bool> {
        let collection_name = self.name(collection);
        let related_name = self.name(&relationship.related_collection);
        let unset = |field: &Option<String>| {
            let mut fields = BsonDocument::new();
            fields.insert(Self::escape_field_name(field.as_deref().unwrap_or_default()), "");
            doc! { "$unset": fields }
        };
        let parent = side == RelationshipSide::Parent;
        match relationship.kind {
            RelationshipType::OneToOne => {
                if parent {
                    self.update_all(&collection_name, unset(&relationship.key)).await?;
                    if relationship.two_way {
                        self.update_all(&related_name, unset(&relationship.two_way_key)).await?;
                    }
                } else {
                    self.update_all(&related_name, unset(&relationship.two_way_key)).await?;
                    if relationship.two_way {
                        self.update_all(&collection_name, unset(&relationship.key)).await?;
                    }
                }
            }
            RelationshipType::OneToMany => {
                if parent {
                    self.update_all(&related_name, unset(&relationship.two_way_key)).await?;
                } else {
                    self.update_all(&collection_name, unset(&relationship.key)).await?;
                }
            }
            RelationshipType::ManyToOne => {
                if parent {
                    self.update_all(&collection_name, unset(&relationship.key)).await?;
                } else {
                    self.update_all(&related_name, unset(&relationship.two_way_key)).await?;
                }
            }
            RelationshipType::ManyToMany => {
                let junction = self.junction_name(collection, &relationship.related_collection, side).await?;
                self.driver.drop_collection(&junction, Options::new()).await.map_err(from_client)?;
            }
        }
        Ok(true)
    }
}
