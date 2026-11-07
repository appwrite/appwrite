//! Indexes: create, rename, delete.
//!
//! Area: schema.

use futures_util::FutureExt;

use super::Database;
use super::metadata::{Rollback, documents_at, set_documents};
use crate::document::Document;
use crate::error::{Error, Kind, Result};
use crate::validator::index::Index as IndexValidator;
use crate::value::{Array, ArrayKey, Str, Value, to_text};

/// The `$id` of an index entry, as `$index['$id']` reads it.
fn entry_id(index: &Document) -> Value {
    index.get("$id").cloned().unwrap_or(Value::Null)
}

/// `in_array($needle, $haystack)`: loose comparison.
fn in_array(needle: &Value, haystack: &[Value]) -> bool {
    haystack.iter().any(|v| v.loose_eq(needle))
}

/// The base attribute of an index attribute: `a` of the nested path `a.b.c`.
fn base_attribute(attribute: &str) -> &str {
    attribute.split_once('.').map_or(attribute, |(base, _)| base)
}

/// The strings of an index's `attributes`.
fn strings_at(index: &Document, key: &str) -> Vec<String> {
    match index.get(key) {
        Some(Value::Array(a)) => a.values().map(to_text).collect(),
        _ => Vec::new(),
    }
}

impl Database {
    /// The type of each index attribute, by the attribute (`a.b` nested
    /// paths by their base attribute `a`), for the adapter's index
    /// definition.
    ///
    /// PHP: the `$indexAttributesWithTypes` / `$indexAttributeTypes` of
    /// `createIndex()` and `deleteIndex()`.
    fn index_attribute_types(attributes: &[String], collection_attributes: &[Document]) -> Vec<(String, String)> {
        let mut types: Vec<(String, String)> = Vec::new();
        for attribute in attributes {
            let base = base_attribute(attribute);
            let found = collection_attributes
                .iter()
                .find(|a| matches!(a.get("key"), Some(Value::Str(k)) if k.as_bytes() == base.as_bytes()));
            if let Some(found) = found {
                let kind = found.get("type").map(to_text).unwrap_or_default();
                match types.iter_mut().find(|(k, _)| k == attribute) {
                    Some(entry) => entry.1 = kind,
                    None => types.push((attribute.clone(), kind)),
                }
            }
        }
        types
    }

    /// Creates index `id` of type `kind` over `attributes`, with optional
    /// per-attribute `lengths` and `orders` (`null` entries for none) and,
    /// for TTL indexes, `ttl` seconds; validates it against the collection
    /// and the adapter's capabilities, then persists the metadata (dropping
    /// the index when that fails); triggers
    /// [`Database::EVENT_INDEX_CREATE`].
    ///
    /// PHP: `Database::createIndex($collection, $id, $type, $attributes,
    /// $lengths = [], $orders = [], $ttl = 1)`. Validator: `Index`.
    #[allow(clippy::too_many_arguments)]
    pub async fn create_index(
        &self,
        collection: &str,
        id: &str,
        kind: &str,
        attributes: Vec<String>,
        lengths: Vec<Value>,
        orders: Vec<Value>,
        ttl: i64,
    ) -> Result<bool> {
        if attributes.is_empty() {
            return Err(Error::database("Missing attributes"));
        }
        let adapter = self.adapter().clone();

        let mut collection = self.silent(|| self.get_collection(collection), None).await?;
        let indexes = documents_at(&collection, "indexes");
        if indexes.iter().any(|i| i.id().eq_ignore_ascii_case(id)) {
            return Err(Error::duplicate("Index already exists"));
        }
        if adapter.count_of_indexes(&collection) >= adapter.limit_for_indexes() {
            return Err(Error::limit("Index limit reached. Cannot create new index."));
        }

        let collection_attributes = documents_at(&collection, "attributes");
        let mut lengths: Array = lengths.into_iter().collect();
        let mut orders: Array = orders.into_iter().collect();
        let max_index_length = adapter.max_index_length();
        for (i, attribute) in attributes.iter().enumerate() {
            let base = base_attribute(attribute);
            let key = ArrayKey::Int(i as i64);
            for collection_attribute in &collection_attributes {
                if !matches!(collection_attribute.get("key"), Some(Value::Str(k)) if k.as_bytes() == base.as_bytes()) {
                    continue;
                }
                let attribute_type = collection_attribute.get("type").cloned().unwrap_or(Value::Null);
                if Self::STRING_TYPES.iter().any(|t| Value::Str(Str::from(*t)).loose_eq(&attribute_type)) {
                    let length = lengths.get(&key).cloned().unwrap_or(Value::Null);
                    let size = collection_attribute.get("size").cloned().unwrap_or(Value::Null);
                    if length.truthy() && length.strict_eq(&size) && max_index_length > 0 {
                        lengths.set(key.clone(), Value::Null);
                    }
                }
                if collection_attribute.get("array").is_some_and(Value::truthy) {
                    if max_index_length > 0 {
                        lengths.set(key.clone(), Value::Int(Self::MAX_ARRAY_INDEX_LENGTH));
                    }
                    orders.set(key.clone(), Value::Null);
                }
                break;
            }
        }
        let attribute_types = Self::index_attribute_types(&attributes, &collection_attributes);

        let index = Document::new(
            [
                (ArrayKey::from("$id"), Value::Str(Str::from(crate::helpers::id::custom(id)))),
                (ArrayKey::from("key"), Value::Str(Str::from(id))),
                (ArrayKey::from("type"), Value::Str(Str::from(kind))),
                (
                    ArrayKey::from("attributes"),
                    Value::Array(attributes.iter().map(|a| Value::Str(Str::from(a.as_str()))).collect()),
                ),
                (ArrayKey::from("lengths"), Value::Array(lengths.clone())),
                (ArrayKey::from("orders"), Value::Array(orders.clone())),
                (ArrayKey::from("ttl"), Value::Int(ttl)),
            ]
            .into_iter()
            .collect(),
        )?;

        if self.state().validate {
            let validator = IndexValidator::new(
                &collection_attributes,
                indexes,
                max_index_length,
                adapter.internal_indexes_keys().iter().map(|k| (*k).to_owned()).collect(),
                self.index_support(),
            );
            if !validator.is_valid(&Value::from(index.clone())) {
                return Err(Error::index(validator.description()));
            }
        }

        let mut created = false;
        let mut exists_in_schema = false;
        if adapter.support_for_schema_indexes() && !(adapter.shared_tables() && self.is_migrating()) {
            let schema_indexes = self.schema_indexes(collection.id()).await?;
            let filtered_id = adapter.filter(id)?;
            for schema_index in &schema_indexes {
                if !schema_index.id().eq_ignore_ascii_case(&filtered_id) {
                    continue;
                }
                let columns = schema_index.get("columns").cloned().unwrap_or(Value::Array(Array::new()));
                let schema_lengths = schema_index.get("lengths").cloned().unwrap_or(Value::Array(Array::new()));
                let mut filtered = Array::new();
                for attribute in &attributes {
                    let _ = filtered.push(Value::Str(Str::from(adapter.filter(attribute)?)));
                }
                if columns.strict_eq(&Value::Array(filtered))
                    && schema_lengths.strict_eq(&Value::Array(lengths.clone()))
                {
                    exists_in_schema = true;
                } else {
                    match adapter.delete_index(collection.id(), id).await {
                        Ok(_) => {}
                        Err(e) if e.is_a(Kind::NotFound) => {}
                        Err(e) => return Err(e),
                    }
                }
                break;
            }
        }

        if !exists_in_schema {
            let result = adapter
                .create_index_arrays(
                    collection.id(),
                    id,
                    kind,
                    &attributes,
                    &lengths,
                    &orders,
                    &attribute_types,
                    &[],
                    ttl,
                )
                .await;
            match result {
                Ok(true) => created = true,
                Ok(false) => return Err(Error::database("Failed to create index")),
                Err(e) if e.is_a(Kind::Unique) => return Err(e),
                // The index exists only in the table (an orphan of a
                // partial failure): keep it and persist the metadata.
                Err(e) if e.is_a(Kind::Duplicate) => {}
                Err(e) => return Err(e),
            }
        }

        let mut all = documents_at(&collection, "indexes");
        all.push(index.clone());
        set_documents(&mut collection, "indexes", all);

        let collection_id = collection.id().to_owned();
        self.update_metadata(
            &collection,
            Some(Rollback::Throwing(self.cleanup_index(&collection_id, id, 3).boxed())),
            created,
            &format!("index creation '{id}'"),
        )
        .await?;

        self.trigger(Self::EVENT_INDEX_CREATE, &Value::from(index))?;
        Ok(true)
    }

    /// Renames an index; triggers [`Database::EVENT_INDEX_RENAME`].
    ///
    /// PHP: `Database::renameIndex()`.
    pub async fn rename_index(&self, collection: &str, old: &str, new: &str) -> Result<bool> {
        let adapter = self.adapter().clone();
        let mut collection = self.silent(|| self.get_collection(collection), None).await?;
        let mut indexes = documents_at(&collection, "indexes");
        let ids: Vec<Value> = indexes.iter().map(entry_id).collect();
        if !in_array(&Value::Str(Str::from(old)), &ids) {
            return Err(Error::not_found("Index not found"));
        }
        if in_array(&Value::Str(Str::from(new)), &ids) {
            return Err(Error::duplicate("Index name already used"));
        }

        // `false` when no index has exactly the id `old`.
        let mut renamed_index = Value::Bool(false);
        for index in &mut indexes {
            if matches!(index.get("$id"), Some(Value::Str(i)) if i.as_bytes() == old.as_bytes()) {
                index.set("key", Value::Str(Str::from(new)));
                index.set("$id", Value::Str(Str::from(new)));
                renamed_index = Value::from(index.clone());
                break;
            }
        }
        set_documents(&mut collection, "indexes", indexes);

        let collection_id = collection.id().to_owned();
        let renamed = match adapter.rename_index(&collection_id, old, new).await {
            Ok(true) => true,
            first => {
                let e = match first {
                    Err(e) => e,
                    _ => Error::database("Failed to rename index"),
                };
                // A prior partial failure may have renamed it already: if
                // the reverse rename works, the index was at `new`.
                let retried = async {
                    adapter.rename_index(&collection_id, new, old).await?;
                    adapter.rename_index(&collection_id, old, new).await
                }
                .await;
                match retried {
                    Ok(renamed) => renamed,
                    Err(_) => {
                        return Err(Error::database(format!(
                            "Failed to rename index '{old}' to '{new}': {}",
                            e.message()
                        ))
                        .with_previous(e));
                    }
                }
            }
        };

        let rollback = {
            let adapter = adapter.clone();
            let collection_id = collection_id.clone();
            let (new, old) = (new.to_owned(), old.to_owned());
            async move { adapter.rename_index(&collection_id, &new, &old).await.map(|_| ()) }.boxed()
        };
        self.update_metadata(
            &collection,
            Some(Rollback::Throwing(rollback)),
            renamed,
            &format!("index rename '{old}' to '{new}'"),
        )
        .await?;

        let purge =
            self.with_retries(|| async { self.purge_cached_collection(&collection_id).await.map(|_| ()) }, 3, 100, 2.0);
        purge.await?;

        let _ = self.trigger(Self::EVENT_INDEX_RENAME, &renamed_index);
        Ok(true)
    }

    /// Deletes an index; triggers [`Database::EVENT_INDEX_DELETE`].
    ///
    /// PHP: `Database::deleteIndex()`.
    pub async fn delete_index(&self, collection: &str, id: &str) -> Result<bool> {
        let adapter = self.adapter().clone();
        let mut collection = self.silent(|| self.get_collection(collection), None).await?;
        let mut deleted_index = None;
        let mut kept = Vec::new();
        for index in documents_at(&collection, "indexes") {
            if matches!(index.get("$id"), Some(Value::Str(i)) if i.as_bytes() == id.as_bytes()) {
                deleted_index = Some(index);
            } else {
                kept.push(index);
            }
        }
        let Some(deleted_index) = deleted_index else {
            return Err(Error::not_found("Index not found"));
        };

        let collection_id = collection.id().to_owned();
        let mut should_rollback = false;
        let deleted = match adapter.delete_index(&collection_id, id).await {
            Ok(true) => {
                should_rollback = true;
                true
            }
            Ok(false) => return Err(Error::database("Failed to delete index")),
            // Already absent from the table: deleted.
            Err(e) if e.is_a(Kind::NotFound) => true,
            Err(e) => return Err(e),
        };

        set_documents(&mut collection, "indexes", kept);

        let attributes = strings_at(&deleted_index, "attributes");
        let attribute_types = Self::index_attribute_types(&attributes, &documents_at(&collection, "attributes"));
        let rollback = {
            let adapter = adapter.clone();
            let collection_id = collection_id.clone();
            let id = id.to_owned();
            let kind = deleted_index.get("type").map(to_text).unwrap_or_default();
            let list = |key: &str| match deleted_index.get(key) {
                Some(Value::Array(a)) => a.clone(),
                _ => Array::new(),
            };
            let (lengths, orders) = (list("lengths"), list("orders"));
            let ttl = deleted_index.get("ttl").map_or(1, |t| match t {
                Value::Int(i) => *i,
                // An `int` parameter takes a float or numeric string by value.
                other => other.to_double() as i64,
            });
            async move {
                adapter
                    .create_index_arrays(
                        &collection_id,
                        &id,
                        &kind,
                        &attributes,
                        &lengths,
                        &orders,
                        &attribute_types,
                        &[],
                        ttl,
                    )
                    .await
                    .map(|_| ())
            }
            .boxed()
        };
        self.update_metadata(
            &collection,
            Some(Rollback::Silent(rollback)),
            should_rollback,
            &format!("index deletion '{id}'"),
        )
        .await?;

        let _ = self.trigger(Self::EVENT_INDEX_DELETE, &Value::from(deleted_index));
        Ok(deleted)
    }
}
