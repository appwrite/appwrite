//! Attributes.
//!
//! PHP: `Trait\Attributes`.

use std::sync::Arc;

use php_std::value::Number;
use utopia_query::schema::{ColumnType, IndexType};

use super::retry::Rollback;
use super::{Database, MAX_VECTOR_DIMENSIONS, METADATA};
use crate::adapter::{self, Feature};
use crate::attribute::{Attribute, Patch, Update};
use crate::capability::Capability;
use crate::collection::{self, Collection};
use crate::document::SetType;
use crate::error::{Error, Kind, Result};
use crate::event::{Domain, Event};
use crate::index::Index;
use crate::schema::Column;
use crate::validator::attribute_definition::AttributeDefinition;
use crate::validator::big_int::BigInt;
use crate::validator::index_definition::IndexDefinition;
use crate::validator::index_dependency::IndexDependency;
use crate::validator::spatial::Spatial;
use crate::validator::structure::formats;
use crate::value::{Array, Value};

/// The `attributes` field of a stored index (`INDEX_ATTRIBUTES`).
const INDEX_ATTRIBUTES: &str = "attributes";

/// `number_format($n)`.
fn number_format(n: Number) -> String {
    String::from_utf8_lossy(&php_std::format::number_format(n, 0, b".", b",")).into_owned()
}

impl Database {
    /// Adds an attribute to a collection (`createAttribute()`): its column
    /// (reusing a column only the schema holds when its type matches), then
    /// its definition. Returns the attribute as stored.
    ///
    /// Fails ([`crate::Kind::NotFound`]) for a missing collection,
    /// ([`crate::Kind::Duplicate`]) for an existing key,
    /// ([`crate::Kind::Limit`]) past the adapter's limits,
    /// ([`crate::Kind::Refused`]) when the adapter does not create the
    /// column, and as the attribute validator does.
    pub async fn create_attribute(&self, collection: &str, attribute: Attribute) -> Result<Attribute> {
        if let Some(parts) = self.mirror_parts() {
            return Box::pin(parts.create_attribute(collection, attribute)).await;
        }
        let mut definition = self.silent(self.get_collection(collection)).await?;
        let attribute = Database::normalise(&attribute)?;
        let id = definition.id().to_owned();

        let schema_attributes = self.schema_attributes(&id).await?;

        let exists_in_schema = match self.validate_attribute(&definition, &attribute, &schema_attributes) {
            Ok(()) => false,
            Err(error) if error.is_a(Kind::Duplicate) => {
                self.reconcile_schema_only_column(&definition, &attribute, &schema_attributes, error).await?
            }
            Err(error) => return Err(error),
        };

        let created = !exists_in_schema && self.create_attribute_in_schema(&id, &attribute).await?;

        definition.set_with(collection::ATTRIBUTES, Value::from(attribute.to_document()?), SetType::Append)?;

        self.update_metadata(
            &definition,
            Rollback::Throwing(Box::pin(self.cleanup_attribute(&id, &attribute.key, 3))),
            created,
            &format!("attribute creation '{}'", attribute.key),
        )
        .await?;

        self.purge_collection_caches(&id).await?;

        self.trigger(Event::AttributeCreate, || Domain::AttributeCreated {
            collection: id.clone(),
            attribute: attribute.clone(),
        })
        .await?;

        Ok(attribute)
    }

    /// Adds attributes to a collection at once (`createAttributes()`):
    /// every one validated, the columns created in one call, the
    /// definitions written once. Returns the attributes as stored.
    ///
    /// Fails ([`crate::Kind::Database`]) for no attributes (`No attributes
    /// to create`) or one without a key (`Missing attribute key`), and as
    /// [`Database::create_attribute`] does.
    pub async fn create_attributes(&self, collection: &str, attributes: Vec<Attribute>) -> Result<Vec<Attribute>> {
        if let Some(parts) = self.mirror_parts() {
            return Box::pin(parts.create_attributes(collection, attributes)).await;
        }
        if attributes.is_empty() {
            return Err(Error::database("No attributes to create"));
        }

        let mut definition = self.silent(self.get_collection(collection)).await?;
        let id = definition.id().to_owned();

        let schema_attributes = self.schema_attributes(&id).await?;

        let mut stored = Vec::with_capacity(attributes.len());
        let mut to_create = Vec::new();
        for attribute in &attributes {
            if attribute.key.is_empty() {
                return Err(Error::database("Missing attribute key"));
            }

            let attribute = Database::normalise(attribute)?;
            let exists_in_schema = match self.validate_attribute(&definition, &attribute, &schema_attributes) {
                Ok(()) => false,
                Err(error) if error.is_a(Kind::Duplicate) => {
                    self.reconcile_schema_only_column(&definition, &attribute, &schema_attributes, error).await?
                }
                Err(error) => return Err(error),
            };

            if !exists_in_schema {
                to_create.push(attribute.clone());
            }
            stored.push(attribute);
        }

        let created =
            if to_create.is_empty() { Vec::new() } else { self.create_attributes_in_schema(&id, &to_create).await? };

        for attribute in &stored {
            definition.set_with(collection::ATTRIBUTES, Value::from(attribute.to_document()?), SetType::Append)?;
        }

        self.update_metadata(
            &definition,
            Rollback::Errors(Box::pin(self.cleanup_attributes(&id, &created, 3))),
            !created.is_empty(),
            "attributes creation",
        )
        .await?;

        self.purge_collection_caches(&id).await?;

        let listeners = self.listens(Event::AttributeCreate);
        if !listeners.is_empty() {
            for attribute in &stored {
                self.dispatch(
                    &Domain::AttributeCreated { collection: id.clone(), attribute: attribute.clone() },
                    &listeners,
                )
                .await?;
            }
        }

        self.trigger(Event::AttributesCreate, || Domain::AttributeBatchCreated {
            collection: id.clone(),
            attributes: stored.clone(),
        })
        .await?;

        Ok(stored)
    }

    /// Changes an attribute (`updateAttribute()`): a sparse update, where an
    /// unset field keeps its value and `default: null` clears the default.
    /// An explicit `required: true` clears the default; a default on an
    /// attribute that is or becomes required is refused; `required: false`
    /// relaxes the column's `NOT NULL`. Returns the attribute as stored.
    ///
    /// Fails ([`crate::Kind::Database`]) for the metadata collection, a
    /// relationship or an invalid change, ([`crate::Kind::NotFound`]) for a
    /// missing attribute, ([`crate::Kind::Duplicate`]) for a rename onto an
    /// existing key, ([`crate::Kind::Limit`]) past the row width,
    /// ([`crate::Kind::Dependency`]) for an indexed array renamed,
    /// ([`crate::Kind::Index`]) for an index the change invalidates, and
    /// ([`crate::Kind::Refused`]) when the adapter does not alter the
    /// column.
    pub async fn update_attribute(&self, collection: &str, key: &str, update: Update) -> Result<Attribute> {
        if let Some(parts) = self.mirror_parts() {
            return Box::pin(parts.update_attribute(collection, key, update)).await;
        }
        let mut definition = self.silent(self.get_collection(collection)).await?;
        let id = definition.id().to_owned();

        if id == METADATA {
            return Err(Error::database("Cannot update metadata attributes"));
        }

        let attributes = definition.attributes()?.to_vec();
        let Some(position) = attribute_position(&attributes, key) else {
            return Err(Error::not_found("Attribute not found"));
        };

        let stored = attributes[position].clone();

        if stored.kind == ColumnType::Relationship {
            return Err(Error::database("Cannot update relationship as an attribute"));
        }

        if update.is_empty() {
            return Ok(stored);
        }

        let required = update.required.unwrap_or(stored.required);
        if required && matches!(&update.default, Patch::Set(v) if !matches!(v, Value::Null)) {
            return Err(Error::database("Cannot set a default value on a required attribute"));
        }

        let mut updated = stored.apply(&update)?;
        if update.required == Some(true) && !matches!(updated.default, Value::Null) {
            updated = updated.apply(&Update { default: Patch::Set(Value::Null), ..Default::default() })?;
        }

        let new_key = updated.key.clone();
        let renaming = new_key != key;
        if renaming && attribute_position(&attributes, &new_key).is_some() {
            return Err(Error::duplicate("Attribute name already used"));
        }

        self.validate_attribute_update(
            &updated,
            update.size.or(stored.size).unwrap_or(0),
            update.array.unwrap_or(stored.array),
        )?;

        let adapter = &self.inner.adapter;
        let spatial_null = adapter.supports(Capability::IndexSpatialNull);
        let altering = update.kind.is_some()
            || update.size.is_some()
            || update.signed.is_some()
            || update.array.is_some()
            || update.key.is_some()
            || (updated.is_spatial() && !spatial_null);

        let original_indexes = definition.indexes()?.to_vec();
        let attributes = replacing(&attributes, key, &updated);
        let indexes = if renaming {
            rename_indexed_attribute(&original_indexes, key, &new_key)?
        } else {
            original_indexes.clone()
        };

        write_attributes(&mut definition, &attributes)?;
        if renaming {
            write_indexes(&mut definition, &indexes)?;
        }

        let limits = adapter.limits();
        if limits.document_size > 0 && adapter.attribute_width(&definition)? >= limits.document_size {
            return Err(Error::limit("Row width limit reached. Cannot update attribute."));
        }

        if updated.is_spatial() && !spatial_null {
            assert_spatial_indexes_required(&attributes, &indexes)?;
        }

        let mut updated_in_schema = false;

        if altering {
            if renaming {
                let validator = IndexDependency::new(indexes.clone(), adapter.supports(Capability::IndexArrayCast));
                if !validator.check(&updated) {
                    return Err(Error::dependency(validator.description()));
                }
            }

            if self.is_validating() {
                let validator = self.index_validator(attributes.clone(), original_indexes.clone());
                for index in &indexes {
                    if !validator.check(index)? {
                        return Err(Error::index(validator.description()));
                    }
                }
            }

            updated_in_schema = adapter.update_attribute(&id, key, &updated).await?;

            if !updated_in_schema {
                return Err(Error::refused("Failed to update attribute"));
            }
        } else if stored.required && !updated.required && !adapter.relax_attribute_required(&id, key).await? {
            return Err(Error::refused("Failed to update attribute"));
        }

        self.update_metadata(
            &definition,
            Rollback::Silent(Box::pin(async { adapter.update_attribute(&id, &new_key, &stored).await.map(|_| ()) })),
            updated_in_schema,
            &format!("attribute update '{key}'"),
        )
        .await?;

        if altering {
            self.with_retries(|| self.purge_cached_collection(&id), 3, 100).await?;
        }
        self.with_retries(
            || async { self.purge_cached_document_internal(METADATA, Some(&id)).await.map(|_| ()) },
            3,
            100,
        )
        .await?;

        self.trigger(Event::DocumentPurge, || Domain::DocumentPurged {
            collection: METADATA.to_owned(),
            id: id.clone(),
        })
        .await?;

        self.trigger(Event::AttributeUpdate, || Domain::AttributeUpdated {
            collection: id.clone(),
            attribute: updated.clone(),
        })
        .await?;

        Ok(updated)
    }

    /// Whether the collection can take the attribute without exceeding its
    /// limits (`checkAttribute()`).
    ///
    /// Fails ([`crate::Kind::NotFound`]) for a missing collection and
    /// ([`crate::Kind::Limit`]) past the attribute count or row width.
    pub async fn check_attribute(&self, collection: &str, attribute: &Attribute) -> Result<bool> {
        let mut definition = self.silent(self.get_collection(collection)).await?;

        definition.set_with(collection::ATTRIBUTES, Value::from(attribute.to_document()?), SetType::Append)?;

        let adapter = &self.inner.adapter;
        let limits = adapter.limits();
        if limits.attributes > 0 && adapter.count_of_attributes(&definition)? > limits.attributes {
            return Err(Error::limit(format!(
                "Column limit reached. Cannot create new attribute. Current attribute count is {} but the maximum is {}. Remove some attributes to free up space.",
                adapter.count_of_attributes(&definition)?,
                limits.attributes
            )));
        }

        if limits.document_size > 0 && adapter.attribute_width(&definition)? >= limits.document_size {
            return Err(Error::limit(format!(
                "Row width limit reached. Cannot create new attribute. Current row width is {} bytes but the maximum is {} bytes. Reduce the size of existing attributes or remove some attributes to free up space.",
                adapter.attribute_width(&definition)?,
                limits.document_size
            )));
        }

        Ok(true)
    }

    /// Removes an attribute (`deleteAttribute()`): the attribute dropped
    /// from every index on it (and every index left on none), then its
    /// column, then its definition.
    ///
    /// Fails ([`crate::Kind::NotFound`]) for a missing attribute,
    /// ([`crate::Kind::Database`]) for a relationship,
    /// ([`crate::Kind::Dependency`]) for an indexed array, and
    /// ([`crate::Kind::Refused`]) when the adapter does not drop the column.
    pub async fn delete_attribute(&self, collection: &str, key: &str) -> Result<()> {
        if let Some(parts) = self.mirror_parts() {
            return Box::pin(parts.delete_attribute(collection, key)).await;
        }
        let mut definition = self.silent(self.get_collection(collection)).await?;
        let id = definition.id().to_owned();
        let mut attributes = definition.attributes()?.to_vec();
        let Some(position) = attribute_position(&attributes, key) else {
            return Err(Error::not_found("Attribute not found"));
        };

        let attribute = attributes[position].clone();

        if attribute.kind == ColumnType::Relationship {
            return Err(Error::database("Cannot delete relationship as an attribute"));
        }

        let indexes = definition.indexes()?.to_vec();
        let adapter = &self.inner.adapter;

        if self.is_validating() {
            let validator = IndexDependency::new(indexes.clone(), adapter.supports(Capability::IndexArrayCast));
            if !validator.check(&attribute) {
                return Err(Error::dependency(validator.description()));
            }
        }

        attributes.remove(position);
        write_attributes(&mut definition, &attributes)?;
        write_indexes(&mut definition, &without_indexed_attribute(&indexes, key)?)?;

        let deleted_in_schema = self.delete_attribute_from_schema(&id, key).await?;

        self.update_metadata(
            &definition,
            Rollback::Silent(Box::pin(async { adapter.create_attribute(&id, &attribute).await.map(|_| ()) })),
            deleted_in_schema,
            &format!("attribute deletion '{key}'"),
        )
        .await?;

        self.purge_collection_caches(&id).await?;

        self.trigger(Event::AttributeDelete, || Domain::AttributeDeleted {
            collection: id.clone(),
            attribute: attribute.clone(),
        })
        .await?;

        Ok(())
    }

    /// Renames an attribute (`renameAttribute()`): its column, then its
    /// definition and the indexes on it.
    ///
    /// Fails ([`crate::Kind::Duplicate`]) when `new` is taken,
    /// ([`crate::Kind::NotFound`]) for a missing attribute,
    /// ([`crate::Kind::Dependency`]) for an indexed array,
    /// ([`crate::Kind::Database`]) when the adapter fails, and
    /// ([`crate::Kind::Refused`]) when it does not rename the column.
    pub async fn rename_attribute(&self, collection: &str, old: &str, new: &str) -> Result<()> {
        if let Some(parts) = self.mirror_parts() {
            return crate::mirror::in_order!(&*parts, "renameAttribute", |db| db
                .rename_attribute(collection, old, new));
        }
        let mut definition = self.silent(self.get_collection(collection)).await?;
        let id = definition.id().to_owned();
        let attributes = definition.attributes()?.to_vec();
        let position = attribute_position(&attributes, old);

        if attribute_position(&attributes, new).is_some() {
            return Err(Error::duplicate("Attribute name already used"));
        }

        let Some(position) = position else {
            return Err(Error::not_found("Attribute not found"));
        };

        let indexes = definition.indexes()?.to_vec();
        let adapter = &self.inner.adapter;

        if self.is_validating() {
            let validator = IndexDependency::new(indexes.clone(), adapter.supports(Capability::IndexArrayCast));
            if !validator.check(&attributes[position]) {
                return Err(Error::dependency(validator.description()));
            }
        }

        let renamed = attributes[position].apply(&Update { key: Some(new.to_owned()), ..Default::default() })?;
        let attributes = replacing(&attributes, old, &renamed);

        let renamed_in_schema = match adapter.rename_attribute(&id, old, new).await {
            Ok(r) => r,
            Err(error) if error.is_a(Kind::Duplicate) || error.is_a(Kind::NotFound) => return Err(error),
            Err(error) => {
                return Err(Error::database(format!(
                    "Failed to rename attribute '{old}' to '{new}': {}",
                    error.message()
                ))
                .with_previous(error));
            }
        };

        if !renamed_in_schema {
            return Err(Error::refused(format!("Failed to rename attribute '{old}' to '{new}'")));
        }

        write_attributes(&mut definition, &attributes)?;
        write_indexes(&mut definition, &rename_indexed_attribute(&indexes, old, new)?)?;

        self.update_metadata(
            &definition,
            Rollback::Throwing(Box::pin(async { adapter.rename_attribute(&id, new, old).await.map(|_| ()) })),
            true,
            &format!("attribute rename '{old}' to '{new}'"),
        )
        .await?;

        self.with_retries(|| self.purge_cached_collection(&id), 3, 100).await?;

        self.trigger(Event::AttributeRename, || Domain::AttributeRenamed {
            collection: id.clone(),
            old: old.to_owned(),
            attribute: renamed.clone(),
        })
        .await?;

        Ok(())
    }

    /// Creates an attribute's column (`createAttributeInSchema()`): whether
    /// this call created it, `false` when the schema already held it.
    ///
    /// Fails ([`crate::Kind::Mismatch`]) when the schema holds the column
    /// with another type, and ([`crate::Kind::Refused`]) when the adapter
    /// does not create it.
    async fn create_attribute_in_schema(&self, collection: &str, attribute: &Attribute) -> Result<bool> {
        match self.inner.adapter.create_attribute(collection, attribute).await {
            Ok(true) => Ok(true),
            Ok(false) => Err(Error::refused("Failed to create attribute")),
            Err(error) if error.is_a(Kind::Mismatch) => Err(error),
            // The column exists only in the physical schema (the metadata
            // check passed), so the metadata is written for it.
            Err(error) if error.is_a(Kind::Duplicate) => Ok(false),
            Err(error) => Err(error),
        }
    }

    /// Creates attributes' columns (`createAttributesInSchema()`): the
    /// attributes this call created, without those the schema already held.
    ///
    /// Fails as [`Database::create_attribute_in_schema`] does (`Failed to
    /// create attributes`).
    async fn create_attributes_in_schema(&self, collection: &str, attributes: &[Attribute]) -> Result<Vec<Attribute>> {
        match self.inner.adapter.create_attributes(collection, attributes).await {
            Ok(true) => Ok(attributes.to_vec()),
            Ok(false) => Err(Error::refused("Failed to create attributes")),
            Err(error) if error.is_a(Kind::Mismatch) => Err(error),
            Err(error) if error.is_a(Kind::Duplicate) => {
                // At least one column already exists, so each is created on
                // its own and the duplicates are skipped.
                let mut created = Vec::new();
                for attribute in attributes {
                    match self.create_attribute_in_schema(collection, attribute).await {
                        Ok(true) => created.push(attribute.clone()),
                        Ok(false) => {}
                        Err(error) => {
                            self.cleanup_attributes(collection, &created, 3).await?;
                            return Err(error);
                        }
                    }
                }
                Ok(created)
            }
            Err(error) => Err(error),
        }
    }

    /// Drops an attribute's column (`deleteAttributeFromSchema()`): whether
    /// this call dropped it, `false` when the schema no longer held it.
    ///
    /// Fails ([`crate::Kind::Refused`]) when the adapter does not drop it.
    async fn delete_attribute_from_schema(&self, collection: &str, key: &str) -> Result<bool> {
        match self.inner.adapter.delete_attribute(collection, key).await {
            Ok(true) => Ok(true),
            Ok(false) => Err(Error::refused("Failed to delete attribute")),
            // Already absent from the schema; the metadata is still removed.
            Err(error) if error.is_a(Kind::NotFound) => Ok(false),
            Err(error) => Err(error),
        }
    }

    /// A column in the schema but not in this collection's metadata is
    /// reused when its type matches the request, and dropped to be
    /// recreated otherwise; under shared tables it belongs to another
    /// tenant's collection, so a mismatch is refused instead
    /// (`reconcileSchemaOnlyColumn()`). Whether the column is reused.
    ///
    /// Fails ([`crate::Kind::Duplicate`]) with `duplicate` when the metadata
    /// holds the key, or for a shared column of another type.
    async fn reconcile_schema_only_column(
        &self,
        definition: &Collection,
        attribute: &Attribute,
        schema_attributes: &[Column],
        duplicate: Error,
    ) -> Result<bool> {
        let key = attribute.key.to_ascii_lowercase();
        if definition.attributes()?.iter().any(|existing| existing.key.to_ascii_lowercase() == key) {
            return Err(duplicate);
        }

        let adapter = &self.inner.adapter;
        let Some(expected) = adapter.column_type(attribute)? else {
            return Ok(true);
        };

        let filtered = adapter::filter(&attribute.key).to_ascii_lowercase();
        for column in schema_attributes {
            if column.name.to_ascii_lowercase() != filtered {
                continue;
            }

            if column.kind == expected {
                return Ok(true);
            }

            if self.shared_tables() {
                return Err(
                    Error::duplicate("Attribute exists in the shared table with another type").with_previous(duplicate)
                );
            }

            adapter.delete_attribute(definition.id(), &attribute.key).await?;

            return Ok(false);
        }

        Ok(true)
    }

    /// Validates a new attribute against the collection, its columns and
    /// the adapter's limits (`validateAttribute()`).
    ///
    /// Fails ([`crate::Kind::Duplicate`]) for an existing key,
    /// ([`crate::Kind::Limit`]) past a limit, and
    /// ([`crate::Kind::Database`]) otherwise.
    fn validate_attribute(
        &self,
        definition: &Collection,
        attribute: &Attribute,
        schema_attributes: &[Column],
    ) -> Result<()> {
        let mut with_attribute = definition.clone();
        with_attribute.set_with(collection::ATTRIBUTES, Value::from(attribute.to_document()?), SetType::Append)?;
        let with_attribute = Arc::new(with_attribute);

        let count_adapter = self.inner.adapter.clone();
        let count_collection = with_attribute.clone();
        let width_adapter = self.inner.adapter.clone();
        let width_collection = with_attribute;

        let validator = AttributeDefinition::new(definition.attributes()?.to_vec(), self.profile())
            .schema_attributes(schema_attributes)
            .measures(
                Some(Box::new(move |_| count_adapter.count_of_attributes(&count_collection))),
                Some(Box::new(move |_| width_adapter.attribute_width(&width_collection))),
            )
            .filter(Box::new(adapter::filter));

        validator.check(attribute)?;
        Ok(())
    }

    /// Checks the updated attribute against the adapter
    /// (`validateAttributeUpdate()`). `size` and `array` are the requested
    /// values, since the model normalises them away for types that take
    /// neither.
    ///
    /// Fails ([`crate::Kind::Database`]) with the reason.
    fn validate_attribute_update(&self, attribute: &Attribute, size: i64, array: bool) -> Result<()> {
        let adapter = &self.inner.adapter;
        let limits = adapter.limits();
        match attribute.kind {
            ColumnType::String => {
                if size == 0 {
                    return Err(Error::database("Size length is required"));
                }
                if size > limits.string {
                    return Err(Error::database(format!(
                        "Max size allowed for string is: {}",
                        number_format(Number::Int(limits.string))
                    )));
                }
            }
            ColumnType::Varchar => {
                if size == 0 {
                    return Err(Error::database("Size length is required"));
                }
                if size > limits.varchar {
                    return Err(Error::database(format!(
                        "Max size allowed for varchar is: {}",
                        number_format(Number::Int(limits.varchar))
                    )));
                }
            }
            ColumnType::Integer => {
                // `$integer / 2` is a float when the integer is odd.
                let max = limits.integer;
                let limit = if attribute.signed {
                    if max % 2 == 0 { Number::Int(max / 2) } else { Number::Float(max as f64 / 2.0) }
                } else {
                    Number::Int(max)
                };
                let over = match limit {
                    Number::Int(l) => size > l,
                    Number::Float(l) => (size as f64) > l,
                };
                if over {
                    return Err(Error::database(format!("Max size allowed for int is: {}", number_format(limit))));
                }
            }
            ColumnType::Float | ColumnType::Double | ColumnType::Boolean | ColumnType::Datetime => {
                if size != 0 {
                    return Err(Error::database("Size must be empty"));
                }
            }
            ColumnType::Object => {
                if !adapter.supports(Capability::Objects) {
                    return Err(Error::database("Object attributes are not supported"));
                }
                if size != 0 {
                    return Err(Error::database("Size must be empty for object attributes"));
                }
                if array {
                    return Err(Error::database("Object attributes cannot be arrays"));
                }
            }
            ColumnType::Point | ColumnType::Linestring | ColumnType::Polygon => {
                if !adapter.has_feature(Feature::Spatial) {
                    return Err(Error::database("Spatial attributes are not supported"));
                }
                if size != 0 {
                    return Err(Error::database("Size must be empty for spatial attributes"));
                }
                if array {
                    return Err(Error::database("Spatial attributes cannot be arrays"));
                }
            }
            ColumnType::Vector => {
                if !adapter.supports(Capability::Vectors) {
                    return Err(Error::database("Vector types are not supported by the current database"));
                }
                if array {
                    return Err(Error::database("Vector type cannot be an array"));
                }
                if size <= 0 {
                    return Err(Error::database("Vector dimensions must be a positive integer"));
                }
                if size > MAX_VECTOR_DIMENSIONS {
                    return Err(Error::database(format!("Vector dimensions cannot exceed {MAX_VECTOR_DIMENSIONS}")));
                }
                if !matches!(attribute.default, Value::Null) {
                    let Value::Array(components) = &attribute.default else {
                        return Err(Error::database("Vector default value must be an array"));
                    };
                    if components.len() as i64 != size {
                        return Err(Error::database(format!("Vector default value must have exactly {size} elements")));
                    }
                    if components.values().any(|c| !matches!(c, Value::Int(_) | Value::Float(_))) {
                        return Err(Error::database("Vector default value must contain only numeric elements"));
                    }
                }
            }
            _ => {}
        }

        if let Some(format) = &attribute.format
            && !formats::has(&format.name, attribute.kind)
        {
            return Err(Error::database(format!(
                "Format (\"{}\") not available for this attribute type (\"{}\")",
                format.name,
                attribute.kind.as_str()
            )));
        }

        if !matches!(attribute.default, Value::Null) {
            self.validate_default_types(attribute.kind, &attribute.default, attribute.signed)?;
        }

        Ok(())
    }

    /// Checks a default (or each element of an array default) against the
    /// type (`validateDefaultTypes()`).
    ///
    /// Fails ([`crate::Kind::Database`]) with the mismatch.
    pub(crate) fn validate_default_types(&self, kind: ColumnType, default: &Value, signed: bool) -> Result<()> {
        if let Value::Null = default {
            return Ok(());
        }

        if let Value::Array(items) = default {
            if matches!(kind, ColumnType::Point | ColumnType::Linestring | ColumnType::Polygon) {
                let spatial = Spatial::new(kind.as_str());
                if !spatial.is_valid(default) {
                    return Err(Error::database(format!("Invalid default value: {}", spatial.description())));
                }
                return Ok(());
            }

            if kind != ColumnType::Object {
                for value in items.values() {
                    self.validate_default_types(kind, value, signed)?;
                }
            }

            return Ok(());
        }

        let matches = match kind {
            ColumnType::String
            | ColumnType::Varchar
            | ColumnType::Text
            | ColumnType::MediumText
            | ColumnType::LongText
            | ColumnType::Datetime => matches!(default, Value::Str(_)),
            ColumnType::Integer => matches!(default, Value::Int(_)),
            ColumnType::Boolean => matches!(default, Value::Bool(_)),
            ColumnType::BigInteger => {
                BigInt::new(signed, self.inner.adapter.supports(Capability::UnsignedBigInt)).is_valid(default)
            }
            ColumnType::Float | ColumnType::Double => matches!(default, Value::Float(_)),
            ColumnType::Vector => matches!(default, Value::Int(_) | Value::Float(_)),
            _ => false,
        };

        if matches {
            return Ok(());
        }

        if kind == ColumnType::Vector {
            return Err(Error::database("Vector components must be numeric values (float or integer)"));
        }

        let value = match default {
            Value::Bool(_) | Value::Int(_) | Value::Float(_) | Value::Str(_) => crate::value::to_text(default),
            _ => "[non-scalar]".to_owned(),
        };

        if kind == ColumnType::BigInteger
            && let Value::Str(s) = default
            && !BigInt::is_integer_string(s.as_bytes(), signed)
        {
            return Err(Error::database(format!(
                "Default value {value} is not a valid integer string for type bigint"
            )));
        }

        Err(Error::database(format!(
            "Default value {value} does not match given type {}",
            Attribute::stored_type(kind)
        )))
    }

    /// The validator of attribute types alone (`typeValidator()`).
    pub(crate) fn type_validator(&self) -> AttributeDefinition {
        AttributeDefinition::new(Vec::new(), self.profile())
    }

    /// The validator of indexes over `attributes`, beside `indexes`
    /// (`indexValidator()`).
    pub(crate) fn index_validator(&self, attributes: Vec<Attribute>, indexes: Vec<Index>) -> IndexDefinition {
        IndexDefinition::new(attributes, indexes, self.profile())
    }

    /// The attribute with every field normalised for its type, as it is
    /// stored (`normalise()`).
    ///
    /// Fails ([`crate::Kind::Structure`]) as [`Attribute::apply`] does.
    pub(crate) fn normalise(attribute: &Attribute) -> Result<Attribute> {
        attribute.apply(&Update::default())
    }

    /// Purges a collection's documents and its cached definition, and
    /// dispatches [`Event::DocumentPurge`] of the definition, isolated
    /// (`purgeCollectionCaches()`).
    pub(crate) async fn purge_collection_caches(&self, collection: &str) -> Result<()> {
        self.with_retries(|| self.purge_cached_collection(collection), 3, 100).await?;
        self.with_retries(
            || async { self.purge_cached_document_internal(METADATA, Some(collection)).await.map(|_| ()) },
            3,
            100,
        )
        .await?;

        self.trigger(Event::DocumentPurge, || Domain::DocumentPurged {
            collection: METADATA.to_owned(),
            id: collection.to_owned(),
        })
        .await
    }

    /// Drops an attribute's column, with retries (`cleanupAttribute()`).
    ///
    /// Fails as the last attempt does.
    pub(crate) async fn cleanup_attribute(&self, collection: &str, key: &str, attempts: u32) -> Result<()> {
        self.cleanup(
            || async { self.inner.adapter.delete_attribute(collection, key).await.map(|_| ()) },
            "attribute",
            key,
            attempts,
        )
        .await
    }

    /// Drops the columns of `attributes` (`cleanupAttributes()`): the
    /// messages of the cleanups that failed.
    ///
    /// Fails with an engine error (PHP's `\Error`, which its `catch
    /// (Exception)` lets through) unchanged.
    pub(crate) async fn cleanup_attributes(
        &self,
        collection: &str,
        attributes: &[Attribute],
        attempts: u32,
    ) -> Result<Vec<String>> {
        let mut errors = Vec::new();
        for attribute in attributes {
            match self.cleanup_attribute(collection, &attribute.key, attempts).await {
                Ok(()) => {}
                Err(error) if error.is_engine_error() => return Err(error),
                Err(error) => errors.push(error.message().to_owned()),
            }
        }
        Ok(errors)
    }
}

/// Engines without nullable spatial indexes need every attribute a spatial
/// index covers to be required (`assertSpatialIndexesRequired()`).
///
/// Fails ([`crate::Kind::Index`]) naming the attribute.
fn assert_spatial_indexes_required(attributes: &[Attribute], indexes: &[Index]) -> Result<()> {
    for index in indexes {
        if index.kind != IndexType::Spatial {
            continue;
        }
        for key in &index.attributes {
            let lower = key.to_ascii_lowercase();
            let attribute = attributes.iter().rev().find(|a| a.key.to_ascii_lowercase() == lower);
            if let Some(attribute) = attribute
                && attribute.is_spatial()
                && !attribute.required
            {
                return Err(Error::index(format!(
                    "Spatial indexes do not allow null values. Mark the attribute \"{key}\" as required or create the index on a column with no null values."
                )));
            }
        }
    }
    Ok(())
}

/// The position of the attribute stored under `key` (`attributePosition()`).
pub(crate) fn attribute_position(attributes: &[Attribute], key: &str) -> Option<usize> {
    attributes.iter().position(|a| a.key == key)
}

/// `attributes` with the one under `key` replaced (`replacing()`).
fn replacing(attributes: &[Attribute], key: &str, replacement: &Attribute) -> Vec<Attribute> {
    attributes.iter().map(|a| if a.key == key { replacement.clone() } else { a.clone() }).collect()
}

/// Every index on `old` pointed at `new` (`renameIndexedAttribute()`).
///
/// Fails ([`crate::Kind::Index`]) as [`Index::from_document`] does.
fn rename_indexed_attribute(indexes: &[Index], old: &str, new: &str) -> Result<Vec<Index>> {
    indexes
        .iter()
        .map(|index| {
            if index.attributes.iter().any(|a| a == old) {
                let attributes: Vec<String> =
                    index.attributes.iter().map(|a| if a == old { new.to_owned() } else { a.clone() }).collect();
                with_indexed_attributes(index, &attributes)
            } else {
                Ok(index.clone())
            }
        })
        .collect()
}

/// Drops the attribute from every index on it, and every index left on no
/// attribute (`withoutIndexedAttribute()`).
///
/// Fails ([`crate::Kind::Index`]) as [`Index::from_document`] does.
fn without_indexed_attribute(indexes: &[Index], key: &str) -> Result<Vec<Index>> {
    let mut remaining = Vec::with_capacity(indexes.len());
    for index in indexes {
        if !index.attributes.iter().any(|a| a == key) {
            remaining.push(index.clone());
            continue;
        }
        let attributes: Vec<String> = index.attributes.iter().filter(|a| *a != key).cloned().collect();
        if !attributes.is_empty() {
            remaining.push(with_indexed_attributes(index, &attributes)?);
        }
    }
    Ok(remaining)
}

/// The index over `attributes`, read back from its stored form
/// (`withIndexedAttributes()`).
///
/// Fails ([`crate::Kind::Index`]) as [`Index::from_document`] does.
pub(crate) fn with_indexed_attributes(index: &Index, attributes: &[String]) -> Result<Index> {
    let mut document = index.to_document();
    document.set(INDEX_ATTRIBUTES, super::collections::string_list(attributes))?;
    Index::from_document(&document)
}

/// Stores `attributes` as the definition's attributes (`writeAttributes()`).
///
/// Fails as [`Attribute::to_document`] does.
pub(crate) fn write_attributes(definition: &mut Collection, attributes: &[Attribute]) -> Result<()> {
    let documents = attributes.iter().map(|a| a.to_document().map(Value::from)).collect::<Result<Vec<_>>>()?;
    definition.set(collection::ATTRIBUTES, Value::Array(Array::from_list(documents)))?;
    Ok(())
}

/// Stores `indexes` as the definition's indexes (`writeIndexes()`,
/// `writeIndexList()`).
///
/// Fails as [`Collection::set`] does.
pub(crate) fn write_indexes(definition: &mut Collection, indexes: &[Index]) -> Result<()> {
    let documents = indexes.iter().map(|i| Value::from(i.to_document())).collect();
    definition.set(collection::INDEXES, Value::Array(Array::from_list(documents)))?;
    Ok(())
}
