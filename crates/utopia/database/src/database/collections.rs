//! Collections.
//!
//! PHP: `Trait\Collections`.

use utopia_query::schema::{ColumnType, IndexType};

use super::{Database, MAX_ARRAY_INDEX_LENGTH, METADATA};
use crate::attribute::Attribute;
use crate::capability::Capability;
use crate::collection::{self, Collection, Update};
use crate::document::{self, Document};
use crate::error::{Error, Kind, Result};
use crate::event::{Domain, Event};
use crate::index::Index;
use crate::permission::{Permission, PermissionType};
use crate::query::Query;
use crate::role::Role;
use crate::validator::index_definition::IndexDefinition;
use crate::validator::permissions::Permissions;
use crate::value::{Array, KeyRef, Str, Value};

/// The keys of a collection definition `createCollection()` sets itself
/// rather than carrying over as metadata (`COLLECTION_RESERVED_KEYS`).
const COLLECTION_RESERVED_KEYS: [&str; 11] = [
    document::ID,
    document::SEQUENCE,
    document::COLLECTION,
    document::TENANT,
    document::CREATED_AT,
    document::UPDATED_AT,
    document::PERMISSIONS,
    collection::NAME,
    collection::ATTRIBUTES,
    collection::INDEXES,
    collection::DOCUMENT_SECURITY,
];

/// A list of strings as a PHP list value.
pub(crate) fn string_list<S: AsRef<str>>(items: &[S]) -> Value {
    Value::Array(Array::from_list(items.iter().map(|s| Value::Str(Str::from(s.as_ref()))).collect()))
}

impl Database {
    /// Creates a collection: its storage, then its definition in the
    /// metadata collection (`createCollection()`).
    ///
    /// Fails ([`crate::Kind::Duplicate`]) for an existing collection,
    /// ([`crate::Kind::Limit`]) beyond the adapter's limits,
    /// ([`crate::Kind::Structure`]) for a key the metadata collection does
    /// not store, ([`crate::Kind::Refused`]) when the adapter does not
    /// create the table, and as the definition validators do.
    pub async fn create_collection(&self, collection: Collection) -> Result<Collection> {
        let unknown: Vec<String> = collection
            .document()
            .keys()
            .filter_map(|k| match k {
                KeyRef::Str(s) if COLLECTION_RESERVED_KEYS.iter().any(|r| r.as_bytes() == s) => None,
                KeyRef::Str(s) => Some(String::from_utf8_lossy(s).into_owned()),
                KeyRef::Int(i) => Some(i.to_string()),
            })
            .collect();
        if !unknown.is_empty() {
            return Err(Error::structure(format!("Unknown collection keys: {}", unknown.join(", "))));
        }

        let id = collection.id().to_owned();
        let attributes =
            collection.attributes()?.iter().map(Database::normalise).collect::<Result<Vec<Attribute>>>()?;
        let permissions = collection.declared_permissions()?.unwrap_or_else(|| vec![Permission::create(&Role::any())]);

        let type_validator = self.type_validator();
        for attribute in &attributes {
            type_validator.check_type(attribute, None, None)?;
        }

        if self.is_validating() {
            let validator = Permissions::default();
            if !validator.is_valid(&string_list(&permissions)) {
                return Err(Error::database(validator.description()));
            }
        }

        if id != METADATA && self.silent(self.find_collection(&id)).await?.is_some() {
            return Err(Error::duplicate(format!("Collection {id} already exists")));
        }

        let indexes = collection.indexes()?.to_vec();

        if self.is_validating()
            && self.inner.adapter.supports(Capability::IndexTtl)
            && indexes.iter().filter(|i| i.kind == IndexType::Ttl).count() > 1
        {
            return Err(Error::index("There can be only one TTL index in a collection"));
        }

        let indexes = indexes
            .iter()
            .map(|index| self.fit_index_to_attributes(index, &attributes))
            .collect::<Result<Vec<Index>>>()?;

        let definition = Collection::create(
            id.clone(),
            collection.name(),
            attributes.clone(),
            indexes.clone(),
            Some(permissions),
            collection.document_security(),
            Array::new(),
        )?;

        if self.is_validating() {
            let validator = IndexDefinition::new(attributes.clone(), Vec::new(), self.profile());
            for index in &indexes {
                if !validator.check(index)? {
                    return Err(Error::index(validator.description()));
                }
            }
        }

        let adapter = &self.inner.adapter;
        let limits = adapter.limits();
        if !indexes.is_empty() && adapter.count_of_indexes(&definition)? > limits.indexes {
            return Err(Error::limit(format!("Index limit of {} exceeded. Cannot create collection.", limits.indexes)));
        }

        if !attributes.is_empty() {
            if limits.attributes > 0 && adapter.count_of_attributes(&definition)? > limits.attributes {
                return Err(Error::limit(format!(
                    "Attribute limit of {} exceeded. Cannot create collection.",
                    limits.attributes
                )));
            }
            if limits.document_size > 0 && adapter.attribute_width(&definition)? > limits.document_size {
                return Err(Error::limit(format!(
                    "Document size limit of {} exceeded. Cannot create collection.",
                    limits.document_size
                )));
            }
        }

        let created = self.create_collection_in_schema(&id, &attributes, &indexes).await?;

        if id == METADATA {
            return Ok(super::collection_definition());
        }

        let stored = match self.silent(self.create_document(METADATA, definition.into())).await {
            Ok(stored) => stored,
            Err(error) if error.is_a(Kind::Duplicate) => {
                // A concurrent creator committed the metadata for this id
                // first, so the physical table is the one its metadata
                // describes: rolling back would drop a live collection.
                self.purge_stale_collection_cache(&id).await;
                return Err(Error::duplicate(format!("Collection {id} already exists")).with_previous(error));
            }
            Err(error) => {
                if self.may_have_committed(&error) {
                    return Err(error);
                }
                if created
                    && !adapter.shared_tables()
                    && let Err(cleanup) = self.cleanup_collection(&id, 3).await
                {
                    super::console_error(format!("Failed to rollback collection '{id}': {}", cleanup.message()));
                }
                return Err(Error::database(format!(
                    "Failed to create collection metadata for '{id}': {}",
                    error.message()
                ))
                .with_previous(error));
            }
        };

        let stored = self.to_collection(stored)?;

        self.trigger(Event::CollectionCreate, || Domain::CollectionCreated {
            collection: stored.id().to_owned(),
            definition: stored.clone(),
        })
        .await?;

        Ok(stored)
    }

    /// Changes a collection's permissions or document security
    /// (`updateCollection()`).
    ///
    /// Fails ([`crate::Kind::Database`]) for invalid permissions,
    /// ([`crate::Kind::NotFound`]) for a missing collection (or one of
    /// another tenant under shared tables), and as
    /// [`Database::update_document`] does.
    pub async fn update_collection(&self, collection: &str, update: Update) -> Result<Collection> {
        if let Some(permissions) = &update.permissions
            && self.is_validating()
        {
            let validator = Permissions::default();
            if !validator.is_valid(&string_list(permissions)) {
                return Err(Error::database(validator.description()));
            }
        }

        let mut definition = self.silent(self.get_own_collection(collection)).await?;

        if update.permissions.is_none() && update.document_security.is_none() {
            return Ok(definition);
        }

        if let Some(permissions) = &update.permissions {
            definition.set(document::PERMISSIONS, string_list(permissions))?;
        }

        if let Some(security) = update.document_security {
            definition.set(collection::DOCUMENT_SECURITY, Value::Bool(security))?;
        }

        let id = definition.id().to_owned();
        let updated = self.silent(self.update_document(METADATA, &id, definition.into())).await?;
        let updated = self.to_collection(updated)?;

        self.trigger(Event::CollectionUpdate, || Domain::CollectionUpdated {
            collection: updated.id().to_owned(),
            definition: updated.clone(),
        })
        .await?;

        Ok(updated)
    }

    /// The collection's definition (`getCollection()`).
    ///
    /// Fails ([`crate::Kind::NotFound`]) for a missing collection
    /// (`Collection not found`).
    pub async fn get_collection(&self, collection: &str) -> Result<Collection> {
        match self.find_collection(collection).await? {
            Some(c) => Ok(c),
            None => Err(crate::Error::not_found("Collection not found")),
        }
    }

    /// The collection's definition, `None` when it does not exist or
    /// belongs to another tenant under shared tables (`findCollection()`).
    pub async fn find_collection(&self, collection: &str) -> Result<Option<Collection>> {
        if collection == super::METADATA {
            let definition = super::collection_definition();
            self.trigger(Event::CollectionRead, || Domain::CollectionRead {
                collection: definition.id().to_owned(),
                definition: definition.clone(),
            })
            .await?;
            return Ok(Some(definition));
        }
        let (stored, epoch) =
            self.silent(self.get_document_with_epoch(super::METADATA, collection, Vec::new(), false)).await?;
        if stored.id().is_empty() {
            return Ok(None);
        }
        let adapter = &self.inner.adapter;
        if adapter.shared_tables()
            && let Some(tenant) = stored.tenant()
            && !tenant.strict_eq(&adapter.tenant())
        {
            return Ok(None);
        }
        let mut definition = self.to_collection(stored)?;
        definition.set_cache_epoch(epoch);
        self.trigger(Event::CollectionRead, || Domain::CollectionRead {
            collection: definition.id().to_owned(),
            definition: definition.clone(),
        })
        .await?;
        Ok(Some(definition))
    }

    /// The collections' definitions (`listCollections()`, by default 25
    /// from offset 0).
    pub async fn list_collections(&self, limit: i64, offset: i64) -> Result<Vec<Collection>> {
        let result = self
            .silent(self.find(METADATA, vec![Query::limit(limit), Query::offset(offset)], PermissionType::Read))
            .await?;

        let collections = result.into_iter().map(|d| self.to_collection(d)).collect::<Result<Vec<_>>>()?;

        self.trigger(Event::CollectionList, || Domain::CollectionListed { collections: collections.clone() }).await?;

        Ok(collections)
    }

    /// The size of a collection's data (`getSizeOfCollection()`).
    ///
    /// Fails ([`crate::Kind::NotFound`]) for a missing collection (or one
    /// of another tenant under shared tables).
    pub async fn size_of_collection(&self, collection: &str) -> Result<i64> {
        let definition = self.silent(self.get_own_collection(collection)).await?;
        self.inner.adapter.size_of_collection(definition.id()).await
    }

    /// The size of a collection on disk (`getSizeOfCollectionOnDisk()`).
    ///
    /// Fails ([`crate::Kind::Database`]) under shared tables without a
    /// tenant, and as [`Database::size_of_collection`] does.
    pub async fn size_of_collection_on_disk(&self, collection: &str) -> Result<i64> {
        let adapter = &self.inner.adapter;
        if adapter.shared_tables() && !adapter.tenant().truthy() {
            return Err(Error::database("Missing tenant. Tenant must be set when table sharing is enabled."));
        }
        let definition = self.silent(self.get_own_collection(collection)).await?;
        adapter.size_of_collection_on_disk(definition.id()).await
    }

    /// Refreshes a collection's planner statistics (`analyzeCollection()`).
    pub async fn analyze_collection(&self, collection: &str) -> Result<bool> {
        self.inner.adapter.analyze_collection(collection).await
    }

    /// Deletes a collection, its relationships and its storage
    /// (`deleteCollection()`). When the metadata cannot be removed, the
    /// dropped table is created again (best effort).
    ///
    /// Fails ([`crate::Kind::NotFound`]) for a missing collection (or one
    /// of another tenant under shared tables), ([`crate::Kind::Refused`])
    /// when the adapter does not drop the table, and
    /// ([`crate::Kind::Database`]) when the metadata cannot be removed.
    pub async fn delete_collection(&self, collection: &str) -> Result<()> {
        let definition = self.silent(self.get_own_collection(collection)).await?;

        for attribute in definition.attributes()? {
            if attribute.kind == ColumnType::Relationship {
                self.delete_relationship(collection, &attribute.key).await?;
            }
        }

        let current = self.silent(self.find_collection(collection)).await?;
        let (current_attributes, current_indexes) = match &current {
            Some(c) => (c.attributes()?.to_vec(), c.indexes()?.to_vec()),
            None => (Vec::new(), Vec::new()),
        };

        if collection == METADATA {
            self.purge_cached_collection(collection).await?;
        }

        let schema_deleted = self.delete_collection_from_schema(collection).await?;

        let mut deleted = true;
        if collection != METADATA {
            match self.silent(self.delete_document(METADATA, collection)).await {
                Ok(d) => deleted = d,
                Err(error) => {
                    if schema_deleted {
                        // Best-effort restore; the metadata error is what the caller needs.
                        let _ = self
                            .inner
                            .adapter
                            .create_collection(collection, &current_attributes, &current_indexes)
                            .await;
                    }
                    return Err(Error::database(format!(
                        "Failed to persist metadata for collection deletion '{collection}': {}",
                        error.message()
                    ))
                    .with_previous(error));
                }
            }

            self.purge_cached_collection(collection).await?;
        }

        if deleted {
            self.trigger(Event::CollectionDelete, || Domain::CollectionDeleted {
                collection: definition.id().to_owned(),
                definition: definition.clone(),
            })
            .await?;
        }
        Ok(())
    }

    /// The collection, refused when it belongs to another tenant under
    /// shared tables (`getOwnCollection()`).
    ///
    /// Fails ([`crate::Kind::NotFound`]) with `Collection not found`.
    pub(crate) async fn get_own_collection(&self, collection: &str) -> Result<Collection> {
        let definition = self.get_collection(collection).await?;
        let adapter = &self.inner.adapter;
        if adapter.shared_tables() && !definition.tenant().unwrap_or(Value::Null).strict_eq(&adapter.tenant()) {
            return Err(Error::not_found("Collection not found"));
        }
        Ok(definition)
    }

    /// A metadata document as a [`Collection`] (`toCollection()`).
    pub(crate) fn to_collection(&self, definition: Document) -> Result<Collection> {
        Collection::from_array(definition.into_array())
    }

    /// The index fitted to the attributes it indexes
    /// (`fitIndexToAttributes()`): engines that keep index lengths do not
    /// store one equal to a string's size, and index arrays by a fixed
    /// prefix in no order.
    ///
    /// Fails ([`crate::Kind::Index`]) as [`Index::with_lengths`] and
    /// [`Index::with_orders`] do.
    pub(crate) fn fit_index_to_attributes(&self, index: &Index, attributes: &[Attribute]) -> Result<Index> {
        let max_index_length = self.inner.adapter.limits().index_length;
        let mut lengths = index.lengths.clone();
        let mut orders = index.orders.clone();

        for (position, key) in index.attributes.iter().enumerate() {
            let Some(attribute) = attributes.iter().find(|a| a.key == *key) else {
                continue;
            };

            let length = lengths.get(position).copied().flatten();
            if attribute.kind == ColumnType::String
                && length.is_some_and(|l| l != 0)
                && length == attribute.size
                && max_index_length > 0
            {
                with_position(&mut lengths, position, None);
            }

            if attribute.array {
                if max_index_length > 0 && index.kind != IndexType::Fulltext {
                    with_position(&mut lengths, position, Some(MAX_ARRAY_INDEX_LENGTH));
                }
                if orders.get(position).copied().flatten().is_some() {
                    with_position(&mut orders, position, None);
                }
            }
        }

        let mut index = index.clone();
        if lengths != index.lengths {
            index = index.with_lengths(lengths)?;
        }
        if orders != index.orders {
            index = index.with_orders(orders)?;
        }
        Ok(index)
    }

    /// Purges the cached definition of a collection whose creation lost a
    /// race, warning when it cannot (`purgeStaleCollectionCache()`).
    async fn purge_stale_collection_cache(&self, collection: &str) {
        if let Err(e) = self.purge_cached_document(METADATA, collection).await {
            super::warning(format!("Warning: Failed to purge stale collection cache: {}", e.message()));
        }
    }

    /// Creates the collection's table (`createCollectionInSchema()`):
    /// whether this call created it, `false` when it reuses the metadata
    /// table or a table shared with other tenants.
    ///
    /// Fails ([`crate::Kind::Duplicate`]) when the table exists and is
    /// neither, and ([`crate::Kind::Refused`]) when the adapter does not
    /// create it.
    async fn create_collection_in_schema(&self, id: &str, attributes: &[Attribute], indexes: &[Index]) -> Result<bool> {
        let adapter = &self.inner.adapter;
        match adapter.create_collection(id, attributes, indexes).await {
            Ok(true) => Ok(true),
            Ok(false) => Err(Error::refused("Failed to create collection")),
            Err(error) if error.is_a(Kind::Duplicate) => {
                // The metadata table is never dropped; under shared tables
                // the physical table is reused across tenants.
                if id == METADATA
                    || (adapter.shared_tables() && adapter.collection_exists(&adapter.database(), id).await?)
                {
                    return Ok(false);
                }
                // The table exists and this process did not create it: it
                // may belong to a peer that has not committed its metadata
                // yet, or be an orphan. It is left as it is.
                self.purge_stale_collection_cache(id).await;
                Err(Error::duplicate(format!("Collection {id} already exists")).with_previous(error))
            }
            Err(error) => Err(error),
        }
    }

    /// Drops the collection's table (`deleteCollectionFromSchema()`):
    /// whether this call dropped it, `false` when the schema no longer held
    /// it.
    ///
    /// Fails ([`crate::Kind::Refused`]) when the adapter does not drop it.
    async fn delete_collection_from_schema(&self, collection: &str) -> Result<bool> {
        match self.inner.adapter.delete_collection(collection).await {
            Ok(true) => Ok(true),
            Ok(false) => Err(Error::refused("Failed to delete collection")),
            // Already absent from the schema; the metadata is still removed.
            Err(error) if error.is_a(Kind::NotFound) => Ok(false),
            Err(error) => Err(error),
        }
    }

    /// Drops a collection's table, with retries (`cleanupCollection()`).
    ///
    /// Fails as the last attempt does.
    pub(crate) async fn cleanup_collection(&self, collection: &str, attempts: u32) -> Result<()> {
        self.cleanup(
            || async { self.inner.adapter.delete_collection(collection).await.map(|_| ()) },
            "collection",
            collection,
            attempts,
        )
        .await
    }
}

/// `withPosition()`: `list` padded with `None` to hold `position`, its
/// value there replaced.
fn with_position<T: Copy>(list: &mut Vec<Option<T>>, position: usize, value: Option<T>) {
    if list.len() < position + 1 {
        list.resize(position + 1, None);
    }
    list[position] = value;
}
