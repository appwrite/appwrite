//! Indexes.
//!
//! PHP: `Trait\Indexes`.

use utopia_query::schema::ColumnType;

use super::Database;
use super::attributes::write_indexes;
use super::retry::Rollback;
use crate::adapter;
use crate::attribute::Attribute;
use crate::capability::Capability;
use crate::collection::Collection;
use crate::error::{Error, Kind, Result};
use crate::event::{Domain, Event};
use crate::index::Index;
use crate::schema;
use crate::storage;

impl Database {
    /// Adds an index to a collection (`createIndex()`): returns it as
    /// stored (fitted to its attributes).
    ///
    /// Fails ([`crate::Kind::NotFound`]) for a missing collection,
    /// ([`crate::Kind::Database`]) for an index on no attribute,
    /// ([`crate::Kind::Duplicate`]) for an existing key,
    /// ([`crate::Kind::Limit`]) past the index limit,
    /// ([`crate::Kind::Index`]) for an invalid index,
    /// ([`crate::Kind::Unique`]) when the stored documents violate a unique
    /// index, and ([`crate::Kind::Refused`]) when the adapter does not
    /// create it.
    pub async fn create_index(&self, collection: &str, index: Index) -> Result<Index> {
        let mut stored = self.store_indexes(collection, vec![index]).await?;
        Ok(stored.remove(0))
    }

    /// Adds indexes to a collection at once (`createIndexes()`): every one
    /// is validated before any is created, and the metadata is written once;
    /// when the engine fails part way, the indexes this call created are
    /// dropped again. Returns them as stored.
    ///
    /// Fails as [`Database::create_index`] does.
    pub async fn create_indexes(&self, collection: &str, indexes: Vec<Index>) -> Result<Vec<Index>> {
        if indexes.is_empty() {
            return Ok(Vec::new());
        }

        let stored = self.store_indexes(collection, indexes).await?;

        self.trigger(Event::IndexesCreate, || Domain::IndexBatchCreated {
            collection: collection.to_owned(),
            indexes: stored.clone(),
        })
        .await?;

        Ok(stored)
    }

    /// Renames an index (`renameIndex()`).
    ///
    /// Fails ([`crate::Kind::NotFound`]) for a missing index,
    /// ([`crate::Kind::Duplicate`]) when `new` is taken,
    /// ([`crate::Kind::Database`]) when the adapter fails, and
    /// ([`crate::Kind::Refused`]) when it does not rename the index.
    pub async fn rename_index(&self, collection: &str, old: &str, new: &str) -> Result<()> {
        let mut definition = self.silent(self.get_collection(collection)).await?;
        let id = definition.id().to_owned();
        let mut indexes = definition.indexes()?.to_vec();

        let Some(position) = index_position(&indexes, old) else {
            return Err(Error::not_found("Index not found"));
        };

        if index_position(&indexes, new).is_some() {
            return Err(Error::duplicate("Index name already used"));
        }

        let renamed = indexes[position].with_key(new);
        indexes[position] = renamed.clone();
        write_indexes(&mut definition, &indexes)?;

        let adapter = &self.inner.adapter;
        let renamed_in_schema = match adapter.rename_index(&id, old, new).await {
            Ok(r) => r,
            Err(error) => {
                self.complete_prior_index_rename(&id, old, new, error).await?;
                true
            }
        };

        if !renamed_in_schema {
            return Err(Error::refused(format!("Failed to rename index '{old}' to '{new}'")));
        }

        self.update_metadata(
            &definition,
            Rollback::Throwing(Box::pin(async { adapter.rename_index(&id, new, old).await.map(|_| ()) })),
            true,
            &format!("index rename '{old}' to '{new}'"),
        )
        .await?;

        self.with_retries(|| self.purge_cached_collection(&id), 3, 100).await?;

        self.trigger(Event::IndexRename, || Domain::IndexRenamed {
            collection: id.clone(),
            old: old.to_owned(),
            index: renamed.clone(),
        })
        .await?;

        Ok(())
    }

    /// Removes an index (`deleteIndex()`).
    ///
    /// Fails ([`crate::Kind::NotFound`]) for a missing index, and
    /// ([`crate::Kind::Refused`]) when the adapter does not drop it.
    pub async fn delete_index(&self, collection: &str, key: &str) -> Result<()> {
        let mut definition = self.silent(self.get_collection(collection)).await?;
        let id = definition.id().to_owned();
        let mut indexes = definition.indexes()?.to_vec();

        let Some(position) = index_position(&indexes, key) else {
            return Err(Error::not_found("Index not found"));
        };

        let deleted = indexes[position].clone();

        let deleted_in_schema = self.delete_index_from_schema(&id, key).await?;

        indexes.remove(position);
        write_indexes(&mut definition, &indexes)?;

        let attribute_types = index_attribute_types(&deleted, definition.attributes()?);
        let adapter = &self.inner.adapter;

        self.update_metadata(
            &definition,
            Rollback::Silent(Box::pin(async {
                adapter.create_index(&id, &deleted, &attribute_types, &[]).await.map(|_| ())
            })),
            deleted_in_schema,
            &format!("index deletion '{key}'"),
        )
        .await?;

        self.with_retries(|| self.purge_cached_collection(&id), 3, 100).await?;

        self.trigger(Event::IndexDelete, || Domain::IndexDeleted { collection: id.clone(), index: deleted.clone() })
            .await?;

        Ok(())
    }

    /// Validates, creates and stores indexes (`storeIndexes()`).
    async fn store_indexes(&self, collection: &str, indexes: Vec<Index>) -> Result<Vec<Index>> {
        let mut definition = self.silent(self.get_collection(collection)).await?;
        let id = definition.id().to_owned();
        let attributes = definition.attributes()?.to_vec();
        let existing = definition.indexes()?.to_vec();

        let prepared = self.prepare_indexes(&definition, &attributes, &existing, &indexes)?;

        let mut created: Vec<String> = Vec::new();
        for index in &prepared {
            match self.create_index_in_schema(&id, index, &attributes).await {
                Ok(true) => created.push(index.key.clone()),
                Ok(false) => {}
                Err(error) => {
                    if let Err(cleanup) = self.cleanup_indexes(&id, &created, 3).await {
                        super::console_error(format!(
                            "Failed to roll back indexes created before the failure: {}",
                            cleanup.message()
                        ));
                    }
                    return Err(error);
                }
            }
        }

        let all: Vec<Index> = existing.iter().chain(prepared.iter()).cloned().collect();
        write_indexes(&mut definition, &all)?;

        let keys = prepared.iter().map(|i| i.key.as_str()).collect::<Vec<_>>().join("', '");

        self.update_metadata(
            &definition,
            Rollback::Throwing(Box::pin(self.cleanup_indexes(&id, &created, 3))),
            !created.is_empty(),
            &format!("index creation '{keys}'"),
        )
        .await?;

        self.with_retries(|| self.purge_cached_collection(&id), 3, 100).await?;

        let listeners = self.listens(Event::IndexCreate);
        if !listeners.is_empty() {
            for index in &prepared {
                self.dispatch(&Domain::IndexCreated { collection: id.clone(), index: index.clone() }, &listeners)
                    .await?;
            }
        }

        Ok(prepared)
    }

    /// Fits each index to its attributes and validates it against the
    /// stored indexes and the ones before it (`prepareIndexes()`).
    fn prepare_indexes(
        &self,
        definition: &Collection,
        attributes: &[Attribute],
        existing: &[Index],
        indexes: &[Index],
    ) -> Result<Vec<Index>> {
        let mut keys: Vec<String> = existing.iter().map(|i| i.key.to_ascii_lowercase()).collect();

        for index in indexes {
            if index.attributes.is_empty() {
                return Err(Error::database("Missing attributes"));
            }
            let key = index.key.to_ascii_lowercase();
            if keys.contains(&key) {
                return Err(Error::duplicate("Index already exists"));
            }
            keys.push(key);
        }

        let adapter = &self.inner.adapter;
        if adapter.count_of_indexes(definition)? + indexes.len() as i64 > adapter.limits().indexes {
            return Err(Error::limit("Index limit reached. Cannot create new index."));
        }

        let mut prepared: Vec<Index> = Vec::with_capacity(indexes.len());
        for index in indexes {
            let index = self.fit_index_to_attributes(index, attributes)?;

            if self.is_validating() {
                let beside: Vec<Index> = existing.iter().chain(prepared.iter()).cloned().collect();
                let validator = self.index_validator(attributes.to_vec(), beside);
                if !validator.check(&index)? {
                    return Err(Error::index(validator.description()));
                }
            }

            prepared.push(index);
        }

        Ok(prepared)
    }

    /// Creates an index in the schema (`createIndexInSchema()`): whether
    /// this call created it, `false` when the schema already held it.
    ///
    /// Fails ([`crate::Kind::Unique`]) when the stored documents violate a
    /// unique index, and ([`crate::Kind::Refused`]) when the adapter does
    /// not create it.
    async fn create_index_in_schema(&self, collection: &str, index: &Index, attributes: &[Attribute]) -> Result<bool> {
        if self.reconcile_schema_only_index(collection, index).await? {
            return Ok(false);
        }

        match self.inner.adapter.create_index(collection, index, &index_attribute_types(index, attributes), &[]).await {
            Ok(true) => Ok(true),
            Ok(false) => Err(Error::refused("Failed to create index")),
            Err(error) if error.is_a(Kind::Unique) => Err(error),
            // The metadata holds no index under this key, so the schema's
            // copy is an orphan of a partial failure: it is kept and the
            // metadata written for it.
            Err(error) if error.is_a(Kind::Duplicate) => Ok(false),
            Err(error) => Err(error),
        }
    }

    /// Drops an index from the schema (`deleteIndexFromSchema()`): whether
    /// this call dropped it, `false` when the schema no longer held it.
    ///
    /// Fails ([`crate::Kind::Refused`]) when the adapter does not drop it.
    async fn delete_index_from_schema(&self, collection: &str, key: &str) -> Result<bool> {
        match self.inner.adapter.delete_index(collection, key).await {
            Ok(true) => Ok(true),
            Ok(false) => Err(Error::refused("Failed to delete index")),
            // Already absent from the schema; the metadata is still removed.
            Err(error) if error.is_a(Kind::NotFound) => Ok(false),
            Err(error) => Err(error),
        }
    }

    /// A failed rename may follow a prior partial failure whose rename
    /// reached the schema while its metadata update and rollback failed:
    /// renaming back and forth again proves the schema holds the index
    /// under the new name and completes the rename
    /// (`completePriorIndexRename()`).
    ///
    /// Fails with `error` when the round trip fails after a
    /// [`crate::Kind::Duplicate`] or [`crate::Kind::NotFound`], and
    /// ([`crate::Kind::Database`]) wrapping it otherwise.
    async fn complete_prior_index_rename(&self, collection: &str, old: &str, new: &str, error: Error) -> Result<()> {
        let adapter = &self.inner.adapter;
        let completed = matches!(adapter.rename_index(collection, new, old).await, Ok(true))
            && matches!(adapter.rename_index(collection, old, new).await, Ok(true));

        if completed {
            return Ok(());
        }

        if error.is_a(Kind::Duplicate) || error.is_a(Kind::NotFound) {
            return Err(error);
        }

        Err(Error::database(format!("Failed to rename index '{old}' to '{new}': {}", error.message()))
            .with_previous(error))
    }

    /// An index in the schema but not in this collection's metadata is
    /// reused when its definition matches the request, and dropped to be
    /// recreated otherwise; under shared tables it serves another tenant's
    /// collection, so a mismatch is refused instead
    /// (`reconcileSchemaOnlyIndex()`). Whether the index is reused.
    ///
    /// Fails ([`crate::Kind::Duplicate`]) for a shared index of another
    /// definition.
    async fn reconcile_schema_only_index(&self, collection: &str, index: &Index) -> Result<bool> {
        let adapter = &self.inner.adapter;
        if !adapter.supports(Capability::SchemaIntrospection) || (self.shared_tables() && self.is_migrating()) {
            return Ok(false);
        }

        let id = adapter::filter(&index.key).to_ascii_lowercase();
        if adapter
            .limits()
            .internal_index_keys
            .iter()
            .any(|internal| adapter::filter(internal).to_ascii_lowercase() == id)
        {
            return Ok(false);
        }

        for schema_index in self.schema_indexes(collection).await? {
            if schema_index.name.to_ascii_lowercase() != id {
                continue;
            }

            if self.schema_index_matches(&schema_index, index) {
                return Ok(true);
            }

            if self.shared_tables() {
                return Err(Error::duplicate("Index exists in the shared table with another definition"));
            }

            match adapter.delete_index(collection, &index.key).await {
                Ok(_) => {}
                // Already absent from the schema.
                Err(error) if error.is_a(Kind::NotFound) => {}
                Err(error) => return Err(error),
            }

            return Ok(false);
        }

        Ok(false)
    }

    /// Whether the schema's index is the requested one
    /// (`schemaIndexMatches()`): the same columns (past the tenant under
    /// shared tables), lengths and type.
    fn schema_index_matches(&self, schema_index: &schema::Index, index: &Index) -> bool {
        let mut columns: Vec<String> = schema_index.columns.iter().map(|c| c.to_ascii_lowercase()).collect();
        let mut lengths = schema_index.lengths.clone();

        if self.shared_tables() && columns.first().map(String::as_str) == Some(storage::TENANT) {
            columns.remove(0);
            if !lengths.is_empty() {
                lengths.remove(0);
            }
        }

        if columns.len() != index.attributes.len() {
            return false;
        }

        let adapter = &self.inner.adapter;
        for (position, attribute) in index.attributes.iter().enumerate() {
            if columns[position].is_empty() {
                continue;
            }
            if columns[position] != adapter::filter(storage::column(attribute)).to_ascii_lowercase() {
                return false;
            }
            if let Some(length) = lengths.get(position).copied().flatten()
                && length != index.lengths.get(position).copied().flatten().unwrap_or(0)
            {
                return false;
            }
        }

        schema_index.kind == adapter.schema_index_type(index.kind)
    }

    /// Drops indexes created in the adapter whose metadata could not be
    /// stored (`cleanupIndexes()`): every one, then the first failure.
    pub(crate) async fn cleanup_indexes(&self, collection: &str, keys: &[String], attempts: u32) -> Result<()> {
        let mut failure = None;
        for key in keys {
            let result = self
                .cleanup(
                    || async { self.inner.adapter.delete_index(collection, key).await.map(|_| ()) },
                    "index",
                    key,
                    attempts,
                )
                .await;
            if let Err(error) = result {
                failure.get_or_insert(error);
            }
        }
        failure.map_or(Ok(()), Err)
    }
}

/// The position of the index stored under `key` (`indexPosition()`).
pub(crate) fn index_position(indexes: &[Index], key: &str) -> Option<usize> {
    indexes.iter().position(|i| i.key == key)
}

/// Index attributes by the type of the attribute they index; a dotted path
/// into an object attribute takes the object attribute's type
/// (`indexAttributeTypes()`).
pub(crate) fn index_attribute_types(index: &Index, attributes: &[Attribute]) -> Vec<(String, ColumnType)> {
    let mut types: Vec<(String, ColumnType)> = Vec::new();
    for indexed in &index.attributes {
        let base = indexed.split('.').next().unwrap_or("");
        if let Some(attribute) = attributes.iter().find(|a| a.key == base) {
            match types.iter_mut().find(|(k, _)| k == indexed) {
                Some(entry) => entry.1 = attribute.kind,
                None => types.push((indexed.clone(), attribute.kind)),
            }
        }
    }
    types
}
