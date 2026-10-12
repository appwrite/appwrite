//! Databases (schemas) and collections.
//!
//! Area: schema.

use super::Database;
use super::metadata::documents_at;
use crate::document::Document;
use crate::error::{Error, Kind, Result};
use crate::helpers::permission::Permission;
use crate::helpers::role::Role;
use crate::query::Query;
use crate::validator::index::{Index as IndexValidator, IndexSupport};
use crate::validator::permissions::Permissions;
use crate::value::{Array, ArrayKey, Ext, Str, Value, to_text};

impl Database {
    /// Creates the database (schema) `database` (the current one by
    /// default) and its `_metadata` collection; triggers
    /// [`Database::EVENT_DATABASE_CREATE`].
    ///
    /// PHP: `Database::create()`.
    pub async fn create(&self, database: Option<&str>) -> Result<bool> {
        let database = match database {
            Some(d) => d.to_owned(),
            None => self.inner.adapter.database(),
        };
        self.inner.adapter.create(&database).await?;
        let attributes = match Self::metadata_collection().get("attributes") {
            Some(Value::Array(a)) => a
                .values()
                .map(|v| match v {
                    Value::Ext(Ext::Document(d)) => (**d).clone(),
                    Value::Array(a) => Document::from_array_unchecked(a.clone()),
                    _ => Document::empty(),
                })
                .collect(),
            _ => Vec::new(),
        };
        self.silent(|| self.create_collection(Self::METADATA, attributes, Vec::new(), None, true), None).await?;
        let _ = self.trigger(Self::EVENT_DATABASE_CREATE, &Value::Str(Str::from(database)));
        Ok(true)
    }

    /// Whether the database (the current one by default) exists, or, with
    /// `collection`, whether that collection does.
    ///
    /// PHP: `Database::exists()`.
    pub async fn exists(&self, database: Option<&str>, collection: Option<&str>) -> Result<bool> {
        let database = match database {
            Some(d) => d.to_owned(),
            None => self.inner.adapter.database(),
        };
        self.inner.adapter.exists(&database, collection).await
    }

    /// The databases; triggers [`Database::EVENT_DATABASE_LIST`].
    ///
    /// PHP: `Database::list()`.
    pub async fn list(&self) -> Result<Vec<Document>> {
        let databases = self.inner.adapter.list().await?;
        let payload = Value::Array(databases.iter().cloned().map(Value::from).collect());
        let _ = self.trigger(Self::EVENT_DATABASE_LIST, &payload);
        Ok(databases)
    }

    /// Drops the database (the current one by default) and flushes the
    /// cache; triggers [`Database::EVENT_DATABASE_DELETE`].
    ///
    /// PHP: `Database::delete()`.
    pub async fn delete(&self, database: Option<&str>) -> Result<bool> {
        let database = match database {
            Some(d) => d.to_owned(),
            None => self.inner.adapter.database(),
        };
        let deleted = self.inner.adapter.delete(&database).await?;
        let payload: Array = [
            (ArrayKey::from("name"), Value::Str(Str::from(database))),
            (ArrayKey::from("deleted"), Value::Bool(deleted)),
        ]
        .into_iter()
        .collect();
        let _ = self.trigger(Self::EVENT_DATABASE_DELETE, &Value::Array(payload));
        self.cache().flush().await.map_err(|e| Error::new(Kind::Exception, e.to_string()))?;
        Ok(deleted)
    }

    /// Creates collection `id` with its attributes and indexes (validated
    /// against the adapter's limits), permissions (`None`: any user can
    /// create) and document security; triggers
    /// [`Database::EVENT_COLLECTION_CREATE`].
    ///
    /// PHP: `Database::createCollection()`. Validators: `Index`,
    /// `IndexDependency`, `Permissions` (`Permissions::new(..).is_valid(..)`).
    pub async fn create_collection(
        &self,
        id: &str,
        attributes: Vec<Document>,
        indexes: Vec<Document>,
        permissions: Option<Vec<String>>,
        document_security: bool,
    ) -> Result<Document> {
        let adapter = &self.inner.adapter;
        let mut attributes = attributes;
        for attribute in &mut attributes {
            let kind = attribute.get("type").cloned().unwrap_or(Value::Null);
            if Self::ATTRIBUTE_FILTER_TYPES.iter().any(|t| Value::Str(Str::from(*t)).loose_eq(&kind)) {
                let existing = match attribute.get("filters") {
                    Some(Value::Array(a)) => a.values().cloned().collect::<Vec<_>>(),
                    Some(other) => vec![other.clone()],
                    None => Vec::new(),
                };
                let mut filters: Vec<Value> = Vec::new();
                for filter in existing.into_iter().chain([kind]) {
                    if !filters.iter().any(|f| to_text(f) == to_text(&filter)) {
                        filters.push(filter);
                    }
                }
                attribute.set("filters", Value::Array(filters.into_iter().collect()));
            }
        }

        let permissions = permissions.unwrap_or_else(|| vec![Permission::create(&Role::any())]);
        let validate = self.state().validate;
        if validate {
            let validator = Permissions::default();
            let list = Value::Array(permissions.iter().map(|p| Value::Str(Str::from(p.as_str()))).collect());
            if !validator.is_valid(&list) {
                return Err(Error::database(validator.description()));
            }
        }

        let existing = self.silent(|| self.get_collection(id), None).await?;
        if !existing.is_empty() && id != Self::METADATA {
            return Err(Error::duplicate(format!("Collection {id} already exists")));
        }

        if validate && adapter.support_for_ttl_indexes() {
            let ttl = indexes
                .iter()
                .filter(|i| matches!(i.get("type"), Some(Value::Str(t)) if t.as_bytes() == Self::INDEX_TTL.as_bytes()))
                .count();
            if ttl > 1 {
                return Err(Error::index("There can be only one TTL index in a collection"));
            }
        }

        let max_index_length = adapter.max_index_length();
        let mut indexes = indexes;
        for index in &mut indexes {
            let mut lengths = match index.get("lengths") {
                Some(Value::Array(a)) => a.clone(),
                _ => Array::new(),
            };
            let mut orders = match index.get("orders") {
                Some(Value::Array(a)) => a.clone(),
                _ => Array::new(),
            };
            let index_attributes = match index.get("attributes") {
                Some(Value::Array(a)) => a.iter().map(|(k, v)| (k.to_owned(), v.clone())).collect::<Vec<_>>(),
                _ => Vec::new(),
            };
            for (i, attr) in index_attributes {
                for collection_attribute in &attributes {
                    if !collection_attribute.get("$id").is_some_and(|v| v.strict_eq(&attr)) {
                        continue;
                    }
                    let kind = collection_attribute.get("type").cloned().unwrap_or(Value::Null);
                    if Self::STRING_TYPES.iter().any(|t| Value::Str(Str::from(*t)).loose_eq(&kind)) {
                        let length = lengths.get(&i).cloned().unwrap_or(Value::Null);
                        let size = collection_attribute.get("size").cloned().unwrap_or(Value::Null);
                        if length.truthy() && length.strict_eq(&size) && max_index_length > 0 {
                            lengths.set(i.clone(), Value::Null);
                        }
                    }
                    if collection_attribute.get("array").is_some_and(Value::truthy) {
                        if max_index_length > 0 {
                            lengths.set(i.clone(), Value::Int(Self::MAX_ARRAY_INDEX_LENGTH));
                        }
                        orders.set(i.clone(), Value::Null);
                    }
                    break;
                }
            }
            index.set("lengths", Value::Array(lengths));
            index.set("orders", Value::Array(orders));
        }

        let collection = Document::new(
            [
                (ArrayKey::from("$id"), Value::Str(Str::from(crate::helpers::id::custom(id)))),
                (
                    ArrayKey::from("$permissions"),
                    Value::Array(permissions.iter().map(|p| Value::Str(Str::from(p.as_str()))).collect()),
                ),
                (ArrayKey::from("name"), Value::Str(Str::from(id))),
                (ArrayKey::from("attributes"), Value::Array(attributes.iter().cloned().map(Value::from).collect())),
                (ArrayKey::from("indexes"), Value::Array(indexes.iter().cloned().map(Value::from).collect())),
                (ArrayKey::from("documentSecurity"), Value::Bool(document_security)),
            ]
            .into_iter()
            .collect(),
        )?;

        if validate {
            let validator = IndexValidator::new(
                &attributes,
                Vec::new(),
                max_index_length,
                adapter.internal_indexes_keys().iter().map(|k| (*k).to_owned()).collect(),
                self.index_support(),
            );
            for index in &indexes {
                if !validator.is_valid(&Value::from(index.clone())) {
                    return Err(Error::index(validator.description()));
                }
            }
        }

        if !indexes.is_empty() && adapter.count_of_indexes(&collection) > adapter.limit_for_indexes() {
            return Err(Error::limit(format!(
                "Index limit of {} exceeded. Cannot create collection.",
                adapter.limit_for_indexes()
            )));
        }
        if !attributes.is_empty() {
            if adapter.limit_for_attributes() > 0
                && adapter.count_of_attributes(&collection) > adapter.limit_for_attributes()
            {
                return Err(Error::limit(format!(
                    "Attribute limit of {} exceeded. Cannot create collection.",
                    adapter.limit_for_attributes()
                )));
            }
            if adapter.document_size_limit() > 0
                && adapter.attribute_width(&collection)? > adapter.document_size_limit()
            {
                return Err(Error::limit(format!(
                    "Document size limit of {} exceeded. Cannot create collection.",
                    adapter.document_size_limit()
                )));
            }
        }

        let mut created_physical_table = false;
        match adapter.create_collection(id, &attributes, &indexes).await {
            Ok(_) => created_physical_table = true,
            Err(e) if e.is_a(Kind::Duplicate) => {
                let reused = id == Self::METADATA
                    || (adapter.shared_tables() && adapter.exists(&adapter.database(), Some(id)).await?);
                if !reused {
                    let _ = self.purge_cached_document(Self::METADATA, Some(id)).await;
                    return Err(Error::duplicate(format!("Collection {id} already exists")).with_previous(e));
                }
            }
            Err(e) => return Err(e),
        }

        if id == Self::METADATA {
            return Ok(Self::metadata_collection());
        }

        let created = match self.silent(|| self.create_document(Self::METADATA, collection), None).await {
            Ok(created) => created,
            Err(e) if e.is_a(Kind::Duplicate) => {
                let _ = self.purge_cached_document(Self::METADATA, Some(id)).await;
                return Err(Error::duplicate(format!("Collection {id} already exists")).with_previous(e));
            }
            Err(e) => {
                if created_physical_table {
                    let _ = self.cleanup_collection(id, 3).await;
                }
                return Err(Error::database(format!(
                    "Failed to create collection metadata for '{id}': {}",
                    e.message()
                ))
                .with_previous(e));
            }
        };

        let _ = self.trigger(Self::EVENT_COLLECTION_CREATE, &Value::from(created.clone()));
        Ok(created)
    }

    /// Updates a collection's permissions and document security; triggers
    /// [`Database::EVENT_COLLECTION_UPDATE`].
    ///
    /// PHP: `Database::updateCollection()`.
    pub async fn update_collection(
        &self,
        id: &str,
        permissions: Vec<String>,
        document_security: bool,
    ) -> Result<Document> {
        if self.state().validate {
            let validator = Permissions::default();
            let list = Value::Array(permissions.iter().map(|p| Value::Str(Str::from(p.as_str()))).collect());
            if !validator.is_valid(&list) {
                return Err(Error::database(validator.description()));
            }
        }

        let mut collection = self.silent(|| self.get_collection(id), None).await?;
        if collection.is_empty() {
            return Err(Error::not_found("Collection not found"));
        }
        if self.inner.adapter.shared_tables() && !self.is_current_tenant(&collection) {
            return Err(Error::not_found("Collection not found"));
        }

        collection
            .set("$permissions", Value::Array(permissions.iter().map(|p| Value::Str(Str::from(p.as_str()))).collect()));
        collection.set("documentSecurity", Value::Bool(document_security));
        let collection_id = collection.id().to_owned();
        let collection = self.silent(|| self.update_document(Self::METADATA, &collection_id, collection), None).await?;

        let _ = self.trigger(Self::EVENT_COLLECTION_UPDATE, &Value::from(collection.clone()));
        Ok(collection)
    }

    /// The collection document `id` (empty when it does not exist, or
    /// belongs to another tenant); triggers
    /// [`Database::EVENT_COLLECTION_READ`].
    ///
    /// PHP: `Database::getCollection()`.
    pub async fn get_collection(&self, id: &str) -> Result<Document> {
        // PHP reads it with `getDocument(METADATA, $id)`, whose first step
        // returns the metadata collection's own definition for
        // `getDocument(METADATA, METADATA)` without a read.
        let collection = if id == Self::METADATA {
            Self::metadata_collection()
        } else {
            self.silent(|| self.get_document(Self::METADATA, id, Vec::new(), false), None).await?
        };
        if id != Self::METADATA && self.inner.adapter.shared_tables() {
            let tenant = collection.tenant().unwrap_or(Value::Null);
            if !matches!(tenant, Value::Null) && !tenant.loose_eq(&self.inner.adapter.tenant()) {
                return Ok(Document::empty());
            }
        }
        let _ = self.trigger(Self::EVENT_COLLECTION_READ, &Value::from(collection.clone()));
        Ok(collection)
    }

    /// A page of collections; triggers [`Database::EVENT_COLLECTION_LIST`].
    ///
    /// PHP: `Database::listCollections($limit = 25, $offset = 0)`.
    pub async fn list_collections(&self, limit: i64, offset: i64) -> Result<Vec<Document>> {
        let result = self
            .silent(
                || self.find(Self::METADATA, vec![Query::limit(limit), Query::offset(offset)], Self::PERMISSION_READ),
                None,
            )
            .await?;
        let payload = Value::Array(result.iter().cloned().map(Value::from).collect());
        let _ = self.trigger(Self::EVENT_COLLECTION_LIST, &payload);
        Ok(result)
    }

    /// The size of a collection's data, in bytes.
    ///
    /// PHP: `Database::getSizeOfCollection()`.
    pub async fn size_of_collection(&self, collection: &str) -> Result<i64> {
        let collection = self.silent(|| self.get_collection(collection), None).await?;
        if collection.is_empty() {
            return Err(Error::not_found("Collection not found"));
        }
        if self.inner.adapter.shared_tables() && !self.is_current_tenant(&collection) {
            return Err(Error::not_found("Collection not found"));
        }
        self.inner.adapter.size_of_collection(collection.id()).await
    }

    /// The size of a collection on disk, in bytes.
    ///
    /// PHP: `Database::getSizeOfCollectionOnDisk()`.
    pub async fn size_of_collection_on_disk(&self, collection: &str) -> Result<i64> {
        let adapter = &self.inner.adapter;
        if adapter.shared_tables() && !adapter.tenant().truthy() {
            return Err(Error::database("Missing tenant. Tenant must be set when table sharing is enabled."));
        }
        let collection = self.silent(|| self.get_collection(collection), None).await?;
        if collection.is_empty() {
            return Err(Error::not_found("Collection not found"));
        }
        if adapter.shared_tables() && !self.is_current_tenant(&collection) {
            return Err(Error::not_found("Collection not found"));
        }
        adapter.size_of_collection_on_disk(collection.id()).await
    }

    /// Refreshes a collection's statistics.
    ///
    /// PHP: `Database::analyzeCollection()`.
    pub async fn analyze_collection(&self, collection: &str) -> Result<bool> {
        self.inner.adapter.analyze_collection(collection).await
    }

    /// Deletes a collection, its relationships and its metadata; triggers
    /// [`Database::EVENT_COLLECTION_DELETE`].
    ///
    /// PHP: `Database::deleteCollection()`.
    pub async fn delete_collection(&self, id: &str) -> Result<bool> {
        let adapter = &self.inner.adapter;
        let collection = self.silent(|| self.get_document(Self::METADATA, id, Vec::new(), false), None).await?;
        if collection.is_empty() {
            return Err(Error::not_found("Collection not found"));
        }
        if adapter.shared_tables() && !self.is_current_tenant(&collection) {
            return Err(Error::not_found("Collection not found"));
        }

        let relationships: Vec<Document> = documents_at(&collection, "attributes")
            .into_iter()
            .filter(
                |a| matches!(a.get("type"), Some(Value::Str(t)) if t.as_bytes() == Self::VAR_RELATIONSHIP.as_bytes()),
            )
            .collect();
        for relationship in &relationships {
            self.delete_relationship(collection.id(), relationship.id()).await?;
        }

        // Re-fetch the collection: the relationship deletions changed it.
        let current = self.silent(|| self.get_document(Self::METADATA, id, Vec::new(), false), None).await?;
        let (current_attributes, current_indexes) = if current.is_empty() {
            (Vec::new(), Vec::new())
        } else {
            (documents_at(&current, "attributes"), documents_at(&current, "indexes"))
        };

        let mut schema_deleted = false;
        match adapter.delete_collection(id).await {
            Ok(_) => schema_deleted = true,
            Err(e) if e.is_a(Kind::NotFound) => {}
            Err(e) => return Err(e),
        }

        let deleted = if id == Self::METADATA {
            true
        } else {
            match self.silent(|| self.delete_document(Self::METADATA, id), None).await {
                Ok(deleted) => deleted,
                Err(e) => {
                    if schema_deleted {
                        let _ = adapter.create_collection(id, &current_attributes, &current_indexes).await;
                    }
                    return Err(Error::database(format!(
                        "Failed to persist metadata for collection deletion '{id}': {}",
                        e.message()
                    ))
                    .with_previous(e));
                }
            }
        };

        if deleted {
            let _ = self.trigger(Self::EVENT_COLLECTION_DELETE, &Value::from(collection.clone()));
        }
        self.purge_cached_collection(id).await?;
        Ok(deleted)
    }

    /// Whether `collection` belongs to the adapter's tenant
    /// (`$collection->getTenant() == $this->adapter->getTenant()`).
    pub(crate) fn is_current_tenant(&self, collection: &Document) -> bool {
        collection.tenant().unwrap_or(Value::Null).loose_eq(&self.inner.adapter.tenant())
    }

    /// What the adapter supports, for the `Index` validator.
    ///
    /// PHP: the `$this->adapter->getSupportFor*()` arguments every
    /// `new IndexValidator(...)` of `Database` passes.
    pub(crate) fn index_support(&self) -> IndexSupport {
        let a = &self.inner.adapter;
        IndexSupport {
            array_indexes: a.support_for_index_array(),
            spatial_index_null: a.support_for_spatial_index_null(),
            spatial_index_order: a.support_for_spatial_index_order(),
            vector_indexes: a.support_for_vectors(),
            attributes: a.support_for_attributes(),
            multiple_fulltext_indexes: a.support_for_multiple_fulltext_indexes(),
            identical_indexes: a.support_for_identical_indexes(),
            object_indexes: a.support_for_object_indexes(),
            trigram_indexes: a.support_for_trigram_index(),
            spatial_indexes: a.support_for_spatial_attributes(),
            key_indexes: a.support_for_index(),
            unique_indexes: a.support_for_unique_index(),
            fulltext_indexes: a.support_for_fulltext_index(),
            ttl_indexes: a.support_for_ttl_indexes(),
            objects: a.support_for_object(),
        }
    }
}
