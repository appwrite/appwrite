//! Attributes: create, update, rename, delete, and the checks they run.
//!
//! Area: schema.

use futures_util::FutureExt;
use php_std::value::Number;

use super::Database;
use super::metadata::{Rollback, documents_at, set_documents};
use crate::document::{Document, SetType};
use crate::error::{Error, Kind, Result};
use crate::validator::attribute::{Attribute as AttributeValidator, AttributeOptions};
use crate::validator::big_int::BigInt;
use crate::validator::index::Index as IndexValidator;
use crate::validator::index_dependency::IndexDependency;
use crate::validator::structure::formats;
use crate::value::{Array, ArrayKey, Str, Value, to_text};

/// `in_array($value, $haystack)` for constant strings: loose comparison.
fn in_list(value: &Value, list: &[&str]) -> bool {
    list.iter().any(|t| Value::Str(Str::from(*t)).loose_eq(value))
}

/// `gettype($value)`.
fn gettype(value: &Value) -> &'static str {
    match value {
        Value::Null => "NULL",
        Value::Bool(_) => "boolean",
        Value::Int(_) => "integer",
        Value::Float(_) => "double",
        Value::Str(_) => "string",
        Value::Array(_) => "array",
        Value::Object(_) | Value::Ext(_) => "object",
    }
}

/// A string value.
fn s(text: &str) -> Value {
    Value::Str(Str::from(text))
}

/// `number_format($n)`.
fn number_format(n: Number) -> String {
    String::from_utf8_lossy(&php_std::format::number_format(n, 0, b".", b",")).into_owned()
}

/// A value passed to an `int` parameter (PHP's coercion without
/// `strict_types`): integers as they are, floats and numeric strings
/// truncated, booleans as 0 or 1; anything else fails like PHP.
fn int_param(value: &Value, function: &str, position: usize, name: &str) -> Result<i64> {
    match value {
        Value::Int(i) => Ok(*i),
        Value::Bool(b) => Ok(i64::from(*b)),
        Value::Float(f) if f.fract() == 0.0 && f.is_finite() => Ok(*f as i64),
        Value::Str(v) if php_std::value::numeric_bytes_ex(v.as_bytes()).is_some() => Ok(value.to_double() as i64),
        other => Err(Error::new(
            Kind::TypeError,
            format!(
                "Utopia\\Database\\Database::{function}(): Argument #{position} (${name}) must be of type int, {} given",
                other.type_name()
            ),
        )),
    }
}

/// A value passed to a `string` parameter: strings as they are, other
/// scalars as their text; anything else fails like PHP.
fn string_param(value: &Value, function: &str, position: usize, name: &str) -> Result<String> {
    match value {
        Value::Str(_) | Value::Int(_) | Value::Float(_) | Value::Bool(_) => Ok(to_text(value)),
        other => Err(Error::new(
            Kind::TypeError,
            format!(
                "Utopia\\Database\\Database::{function}(): Argument #{position} (${name}) must be of type string, {} given",
                other.type_name()
            ),
        )),
    }
}

/// A value passed to a `bool` parameter: scalars by truthiness.
fn bool_param(value: &Value, function: &str, position: usize, name: &str) -> Result<bool> {
    match value {
        Value::Bool(_) | Value::Int(_) | Value::Float(_) | Value::Str(_) => Ok(value.truthy()),
        other => Err(Error::new(
            Kind::TypeError,
            format!(
                "Utopia\\Database\\Database::{function}(): Argument #{position} (${name}) must be of type bool, {} given",
                other.type_name()
            ),
        )),
    }
}

/// A value passed to an `array` parameter.
fn array_param(value: &Value, function: &str, position: usize, name: &str) -> Result<Array> {
    match value {
        Value::Array(a) => Ok(a.clone()),
        other => Err(Error::new(
            Kind::TypeError,
            format!(
                "Utopia\\Database\\Database::{function}(): Argument #{position} (${name}) must be of type array, {} given",
                other.type_name()
            ),
        )),
    }
}

/// `array_unique($values)`: the first of the values with the same string
/// form, keeping their keys.
fn array_unique(values: Array) -> Array {
    let mut seen: Vec<String> = Vec::new();
    let mut out = Array::new();
    for (k, v) in values.iter() {
        let text = to_text(v);
        if !seen.contains(&text) {
            seen.push(text);
            out.set(k.to_owned(), v.clone());
        }
    }
    out
}

/// The attribute metadata document PHP builds (`new Document([...])`).
#[allow(clippy::too_many_arguments)]
fn attribute_document(
    id: &str,
    kind: &str,
    size: i64,
    required: bool,
    default: &Value,
    signed: bool,
    array: bool,
    format: Option<&str>,
    format_options: &Array,
    filters: &Array,
) -> Result<Document> {
    Document::new(
        [
            (ArrayKey::from("$id"), s(&crate::helpers::id::custom(id))),
            (ArrayKey::from("key"), s(id)),
            (ArrayKey::from("type"), s(kind)),
            (ArrayKey::from("size"), Value::Int(size)),
            (ArrayKey::from("required"), Value::Bool(required)),
            (ArrayKey::from("default"), default.clone()),
            (ArrayKey::from("signed"), Value::Bool(signed)),
            (ArrayKey::from("array"), Value::Bool(array)),
            (ArrayKey::from("format"), format.map_or(Value::Null, s)),
            (ArrayKey::from("formatOptions"), Value::Array(format_options.clone())),
            (ArrayKey::from("filters"), Value::Array(filters.clone())),
        ]
        .into_iter()
        .collect(),
    )
}

/// The position of the entry whose `$id` loosely equals `id`
/// (`array_search($id, array_map(fn ($a) => $a['$id'], $entries))`).
fn search(entries: &[Document], id: &str) -> Option<usize> {
    let id = s(id);
    entries.iter().position(|e| e.get("$id").unwrap_or(&Value::Null).loose_eq(&id))
}

/// The `$collection => _metadata` document of the purge event.
fn purge_payload(collection_id: &str) -> Value {
    Value::from(Document::from_array_unchecked(
        [(ArrayKey::from("$id"), s(collection_id)), (ArrayKey::from("$collection"), s(Database::METADATA))]
            .into_iter()
            .collect(),
    ))
}

/// Whether the `type` of `document` is exactly `kind`.
fn has_type(document: &Document, kind: &str) -> bool {
    matches!(document.get("type"), Some(Value::Str(t)) if t.as_bytes() == kind.as_bytes())
}

impl Database {
    /// Purges the cached collection and its metadata document (with
    /// retries) and triggers [`Database::EVENT_DOCUMENT_PURGE`] for it.
    async fn purge_collection_metadata(&self, collection_id: &str, collection: bool) -> Result<()> {
        if collection {
            self.with_retries(|| async { self.purge_cached_collection(collection_id).await.map(|_| ()) }, 3, 100, 2.0)
                .await?;
        }
        self.with_retries(
            || async { self.purge_cached_document_internal(Self::METADATA, Some(collection_id)).await.map(|_| ()) },
            3,
            100,
            2.0,
        )
        .await?;
        let _ = self.trigger(Self::EVENT_DOCUMENT_PURGE, &purge_payload(collection_id));
        Ok(())
    }

    /// The column type the adapter would give an attribute, compared with
    /// an orphan column's: `true` when the orphan can be kept.
    #[allow(clippy::too_many_arguments)]
    fn orphan_column_matches(
        &self,
        schema_attributes: &[Document],
        id: &str,
        kind: &str,
        size: i64,
        signed: bool,
        array: bool,
        required: bool,
    ) -> Result<Option<bool>> {
        let adapter = self.adapter();
        let expected = adapter.column_type(kind, size, signed, array, required)?;
        if expected.is_empty() {
            return Ok(None);
        }
        let filtered = adapter.filter(id)?.to_ascii_lowercase();
        for schema in schema_attributes {
            if schema.id().to_ascii_lowercase() == filtered {
                let actual = schema.get("columnType").map(to_text).unwrap_or_default().to_ascii_uppercase();
                return Ok(Some(actual == expected.to_ascii_uppercase()));
            }
        }
        Ok(None)
    }

    /// Whether `collection` already has an attribute with key `id` (any
    /// case) in its metadata.
    fn exists_in_metadata(collection: &Document, id: &str) -> bool {
        let id = id.to_ascii_lowercase();
        documents_at(collection, "attributes").iter().any(|a| {
            let key = a.get("key").map_or_else(|| a.id().to_owned(), to_text);
            key.to_ascii_lowercase() == id
        })
    }

    /// Creates attribute `id` on `collection`: validates it (type, size,
    /// default, format, filters, limits), alters the table, then persists
    /// the metadata (rolling the column back when that fails); triggers
    /// [`Database::EVENT_ATTRIBUTE_CREATE`].
    ///
    /// PHP: `Database::createAttribute($collection, $id, $type, $size,
    /// $required, $default = null, $signed = true, $array = false, $format =
    /// null, $formatOptions = [], $filters = [])`. Validator: `Attribute`.
    #[allow(clippy::too_many_arguments)]
    pub async fn create_attribute(
        &self,
        collection: &str,
        id: &str,
        kind: &str,
        size: i64,
        required: bool,
        default: Value,
        signed: bool,
        array: bool,
        format: Option<&str>,
        format_options: Array,
        filters: Vec<String>,
    ) -> Result<bool> {
        let adapter = self.adapter().clone();
        let mut collection = self.silent(|| self.get_collection(collection), None).await?;
        if collection.is_empty() {
            return Err(Error::not_found("Collection not found"));
        }

        let mut filters: Array = filters.iter().map(|f| s(f)).collect();
        if Self::ATTRIBUTE_FILTER_TYPES.contains(&kind) {
            let _ = filters.push(s(kind));
            filters = array_unique(filters);
        }
        let size = self.normalize_big_int_size(kind, size);

        let schema_attributes = if adapter.support_for_schema_attributes() {
            self.schema_attributes(collection.id()).await?
        } else {
            Vec::new()
        };

        let mut exists_in_schema = false;
        let attribute = match self
            .validate_attribute(
                &collection,
                id,
                kind,
                size,
                required,
                &default,
                signed,
                array,
                format,
                &format_options,
                &filters,
                Some(&schema_attributes),
            )
            .await
        {
            Ok(attribute) => attribute,
            Err(e) if e.is_a(Kind::Duplicate) => {
                // A column without metadata: the remains of a partial
                // failure. Keep it when its type matches, drop it otherwise.
                if Self::exists_in_metadata(&collection, id) {
                    return Err(e);
                }
                match self.orphan_column_matches(&schema_attributes, id, kind, size, signed, array, required)? {
                    Some(false) => {
                        adapter.delete_attribute(collection.id(), id).await?;
                    }
                    _ => exists_in_schema = true,
                }
                attribute_document(
                    id,
                    kind,
                    size,
                    required,
                    &default,
                    signed,
                    array,
                    format,
                    &format_options,
                    &filters,
                )?
            }
            Err(e) => return Err(e),
        };

        let mut created = false;
        if !exists_in_schema {
            match adapter.create_attribute(collection.id(), id, kind, size, signed, array, required).await {
                Ok(true) => created = true,
                Ok(false) => return Err(Error::database("Failed to create attribute")),
                // The column exists only in the table: persist the metadata.
                Err(e) if e.is_a(Kind::Duplicate) => {}
                Err(e) => return Err(e),
            }
        }

        collection.set_with("attributes", Value::from(attribute.clone()), SetType::Append);

        let collection_id = collection.id().to_owned();
        self.update_metadata(
            &collection,
            Some(Rollback::Throwing(self.cleanup_attribute(&collection_id, id, 3).boxed())),
            created,
            &format!("attribute creation '{id}'"),
        )
        .await?;

        self.purge_collection_metadata(&collection_id, true).await?;
        let _ = self.trigger(Self::EVENT_ATTRIBUTE_CREATE, &Value::from(attribute));
        Ok(true)
    }

    /// Creates several attributes at once, each an array with `$id`,
    /// `type`, `size`, `required` and optionally `default`, `signed`,
    /// `array`, `format`, `formatOptions`, `filters` (missing keys raise
    /// PHP's errors); triggers [`Database::EVENT_ATTRIBUTE_CREATE`] with
    /// the list.
    ///
    /// PHP: `Database::createAttributes()`.
    pub async fn create_attributes(&self, collection: &str, attributes: Vec<Array>) -> Result<bool> {
        if attributes.is_empty() {
            return Err(Error::database("No attributes to create"));
        }
        let adapter = self.adapter().clone();
        let mut collection = self.silent(|| self.get_collection(collection), None).await?;
        if collection.is_empty() {
            return Err(Error::not_found("Collection not found"));
        }
        let schema_attributes = if adapter.support_for_schema_attributes() {
            self.schema_attributes(collection.id()).await?
        } else {
            Vec::new()
        };

        let isset = |a: &Array, k: &str| !matches!(a.get_str(k), None | Some(Value::Null));
        let mut documents = Vec::new();
        let mut to_create: Vec<Array> = Vec::new();
        for mut attribute in attributes {
            for (key, message) in [
                ("$id", "Missing attribute key"),
                ("type", "Missing attribute type"),
                ("size", "Missing attribute size"),
                ("required", "Missing attribute required"),
            ] {
                if !isset(&attribute, key) {
                    return Err(Error::database(message));
                }
            }
            for (key, default) in [
                ("default", Value::Null),
                ("signed", Value::Bool(true)),
                ("array", Value::Bool(false)),
                ("format", Value::Null),
                ("formatOptions", Value::Array(Array::new())),
                ("filters", Value::Array(Array::new())),
            ] {
                if !isset(&attribute, key) {
                    attribute.set(ArrayKey::from(key), default);
                }
            }
            let get = |a: &Array, k: &str| a.get_str(k).cloned().unwrap_or(Value::Null);
            let kind = string_param(&get(&attribute, "type"), "normalizeBigIntSize", 1, "type")?;
            let size = int_param(&get(&attribute, "size"), "normalizeBigIntSize", 2, "size")?;
            let size = self.normalize_big_int_size(&kind, size);
            attribute.set(ArrayKey::from("size"), Value::Int(size));

            let id = string_param(&get(&attribute, "$id"), "validateAttribute", 2, "id")?;
            let required = bool_param(&get(&attribute, "required"), "validateAttribute", 5, "required")?;
            let default = get(&attribute, "default");
            let signed = bool_param(&get(&attribute, "signed"), "validateAttribute", 7, "signed")?;
            let array = bool_param(&get(&attribute, "array"), "validateAttribute", 8, "array")?;
            let format = match get(&attribute, "format") {
                Value::Null => None,
                f => Some(string_param(&f, "validateAttribute", 9, "format")?),
            };
            let format_options =
                array_param(&get(&attribute, "formatOptions"), "validateAttribute", 10, "formatOptions")?;
            let filters = array_param(&get(&attribute, "filters"), "validateAttribute", 11, "filters")?;

            let mut exists_in_schema = false;
            let document = match self
                .validate_attribute(
                    &collection,
                    &id,
                    &kind,
                    size,
                    required,
                    &default,
                    signed,
                    array,
                    format.as_deref(),
                    &format_options,
                    &filters,
                    Some(&schema_attributes),
                )
                .await
            {
                Ok(document) => document,
                Err(e) if e.is_a(Kind::Duplicate) => {
                    if Self::exists_in_metadata(&collection, &id) {
                        return Err(e);
                    }
                    match self.orphan_column_matches(&schema_attributes, &id, &kind, size, signed, array, required)? {
                        Some(false) => {
                            adapter.delete_attribute(collection.id(), &id).await?;
                        }
                        Some(true) => exists_in_schema = true,
                        None => {}
                    }
                    attribute_document(
                        &id,
                        &kind,
                        size,
                        required,
                        &default,
                        signed,
                        array,
                        format.as_deref(),
                        &format_options,
                        &filters,
                    )?
                }
                Err(e) => return Err(e),
            };
            documents.push(document);
            if !exists_in_schema {
                to_create.push(attribute);
            }
        }

        let mut created = false;
        if !to_create.is_empty() {
            match adapter.create_attributes(collection.id(), &to_create).await {
                Ok(true) => created = true,
                Ok(false) => return Err(Error::database("Failed to create attributes")),
                Err(e) if e.is_a(Kind::Duplicate) => {
                    // One column exists already: create the others one by one.
                    for attribute in &to_create {
                        let get = |k: &str| attribute.get_str(k).cloned().unwrap_or(Value::Null);
                        let result = adapter
                            .create_attribute(
                                collection.id(),
                                &to_text(&get("$id")),
                                &to_text(&get("type")),
                                int_param(&get("size"), "createAttribute", 4, "size")?,
                                get("signed").truthy(),
                                get("array").truthy(),
                                get("required").truthy(),
                            )
                            .await;
                        match result {
                            Ok(_) => created = true,
                            Err(e) if e.is_a(Kind::Duplicate) => {}
                            Err(e) => return Err(e),
                        }
                    }
                }
                Err(e) => return Err(e),
            }
        }

        for document in &documents {
            collection.set_with("attributes", Value::from(document.clone()), SetType::Append);
        }

        let collection_id = collection.id().to_owned();
        self.update_metadata(
            &collection,
            Some(Rollback::Errors(self.cleanup_attributes(&collection_id, &documents, 3).boxed())),
            created,
            "attributes creation",
        )
        .await?;

        self.purge_collection_metadata(&collection_id, true).await?;
        let payload = Value::Array(documents.into_iter().map(Value::from).collect());
        let _ = self.trigger(Self::EVENT_ATTRIBUTE_CREATE, &payload);
        Ok(true)
    }

    /// `0` for `bigint` (sized by the adapter), `size` otherwise.
    ///
    /// PHP: `Database::normalizeBigIntSize()` (private).
    pub(crate) fn normalize_big_int_size(&self, kind: &str, size: i64) -> i64 {
        if kind == Self::VAR_BIGINT { 0 } else { size }
    }

    /// Validates a new attribute against `collection` and returns its
    /// metadata document. `schema_attributes` are the table's columns when
    /// the caller already fetched them.
    ///
    /// PHP: `Database::validateAttribute()` (private). Validator:
    /// `Attribute::new(..).is_valid(&attribute)`.
    #[allow(clippy::too_many_arguments)]
    pub(crate) async fn validate_attribute(
        &self,
        collection: &Document,
        id: &str,
        kind: &str,
        size: i64,
        required: bool,
        default: &Value,
        signed: bool,
        array: bool,
        format: Option<&str>,
        format_options: &Array,
        filters: &Array,
        schema_attributes: Option<&[Document]>,
    ) -> Result<Document> {
        let adapter = self.adapter().clone();
        let size = self.normalize_big_int_size(kind, size);
        let attribute =
            attribute_document(id, kind, size, required, default, signed, array, format, format_options, filters)?;

        let mut clone = collection.clone();
        clone.set_with("attributes", Value::from(attribute.clone()), SetType::Append);

        let schema_attributes = match schema_attributes {
            Some(s) => s.to_vec(),
            None if adapter.support_for_schema_attributes() => self.schema_attributes(collection.id()).await?,
            None => Vec::new(),
        };
        let (count_adapter, width_adapter, filter_adapter) = (adapter.clone(), adapter.clone(), adapter.clone());
        let (count_clone, width_clone) = (clone.clone(), clone);
        let validator = AttributeValidator::new(
            &documents_at(collection, "attributes"),
            &schema_attributes,
            AttributeOptions {
                max_attributes: adapter.limit_for_attributes(),
                max_width: adapter.document_size_limit(),
                max_string_length: adapter.limit_for_string(),
                max_varchar_length: adapter.max_varchar_length(),
                max_int_length: adapter.limit_for_int(),
                max_big_int_length: adapter.limit_for_big_int(),
                support_for_schema_attributes: adapter.support_for_schema_attributes(),
                support_for_vectors: adapter.support_for_vectors(),
                support_for_spatial_attributes: adapter.support_for_spatial_attributes(),
                support_for_object: adapter.support_for_object(),
                support_unsigned_big_int: adapter.support_for_unsigned_big_int(),
                attribute_count: Some(Box::new(move |_| Ok(count_adapter.count_of_attributes(&count_clone)))),
                attribute_width: Some(Box::new(move |_| width_adapter.attribute_width(&width_clone))),
                filter: Some(Box::new(move |id| filter_adapter.filter(id).unwrap_or_default())),
                is_migrating: self.is_migrating(),
                shared_tables: self.shared_tables(),
            },
        );
        validator.check(&attribute)?;
        Ok(attribute)
    }

    /// The filters an attribute of type `kind` must carry (`datetime`).
    ///
    /// PHP: `Database::getRequiredFilters()` (protected).
    pub(crate) fn required_filters(&self, kind: Option<&str>) -> Vec<String> {
        match kind {
            Some(Self::VAR_DATETIME) => vec!["datetime".to_owned()],
            _ => Vec::new(),
        }
    }

    /// The types `Unknown attribute type` lists.
    fn supported_types(&self) -> Vec<&'static str> {
        let mut supported = vec![
            Self::VAR_STRING,
            Self::VAR_VARCHAR,
            Self::VAR_TEXT,
            Self::VAR_MEDIUMTEXT,
            Self::VAR_LONGTEXT,
            Self::VAR_INTEGER,
            Self::VAR_BIGINT,
            Self::VAR_FLOAT,
            Self::VAR_BOOLEAN,
            Self::VAR_DATETIME,
            Self::VAR_RELATIONSHIP,
        ];
        if self.adapter().support_for_vectors() {
            supported.push(Self::VAR_VECTOR);
        }
        if self.adapter().support_for_spatial_attributes() {
            supported.extend(Self::SPATIAL_TYPES);
        }
        supported
    }

    /// Checks that `default` suits an attribute of type `kind`.
    ///
    /// PHP: `Database::validateDefaultTypes()` (protected).
    pub(crate) fn validate_default_types(&self, kind: &str, default: &Value) -> Result<()> {
        let default_type = gettype(default);
        if default_type == "NULL" {
            return Ok(());
        }
        if let Value::Array(values) = default {
            // Spatial types and objects take the array itself.
            if !Self::SPATIAL_TYPES.contains(&kind) && !s(kind).loose_eq(&s(Self::VAR_OBJECT)) {
                for value in values.values() {
                    self.validate_default_types(kind, value)?;
                }
            }
            return Ok(());
        }
        let mismatch =
            || Error::database(format!("Default value {} does not match given type {kind}", to_text(default)));
        match kind {
            Self::VAR_STRING | Self::VAR_VARCHAR | Self::VAR_TEXT | Self::VAR_MEDIUMTEXT | Self::VAR_LONGTEXT => {
                if default_type != "string" {
                    return Err(mismatch());
                }
            }
            Self::VAR_INTEGER | Self::VAR_FLOAT | Self::VAR_BOOLEAN => {
                if kind != default_type {
                    return Err(mismatch());
                }
            }
            Self::VAR_BIGINT => {
                if default_type != "integer" && default_type != "string" {
                    return Err(mismatch());
                }
                if let Value::Str(v) = default
                    && !BigInt::is_integer_string(v.as_bytes(), true)
                {
                    return Err(Error::database(format!(
                        "Default value {} is not a valid integer string for type bigint",
                        to_text(default)
                    )));
                }
            }
            Self::VAR_DATETIME => {
                if default_type != Self::VAR_STRING {
                    return Err(mismatch());
                }
            }
            Self::VAR_VECTOR => {
                if default_type != "double" && default_type != "integer" {
                    return Err(Error::database("Vector components must be numeric values (float or integer)"));
                }
            }
            _ => {
                return Err(Error::database(format!(
                    "Unknown attribute type: {kind}. Must be one of {}",
                    self.supported_types().join(", ")
                )));
            }
        }
        Ok(())
    }

    /// Applies `update` to index `id` of `collection` (receiving the index,
    /// the collection and the index's position) and persists the metadata.
    /// Returns the updated index.
    ///
    /// PHP: `Database::updateIndexMeta()` (protected).
    pub(crate) async fn update_index_meta<F>(&self, collection: &str, id: &str, update: F) -> Result<Document>
    where
        F: FnOnce(&mut Document, &Document, usize) -> Result<()> + Send,
    {
        let mut collection = self.silent(|| self.get_collection(collection), None).await?;
        if collection.id() == Self::METADATA {
            return Err(Error::database("Cannot update metadata indexes"));
        }
        let mut indexes = documents_at(&collection, "indexes");
        let Some(position) = search(&indexes, id) else {
            return Err(Error::not_found("Index not found"));
        };
        update(&mut indexes[position], &collection, position)?;
        let index = indexes[position].clone();
        set_documents(&mut collection, "indexes", indexes);
        self.update_metadata(&collection, None, false, &format!("index metadata update '{id}'")).await?;
        Ok(index)
    }

    /// Applies `update` to attribute `id` of `collection` (receiving the
    /// attribute, the collection and the attribute's position), persists
    /// the metadata and triggers [`Database::EVENT_ATTRIBUTE_UPDATE`].
    /// Returns the updated attribute.
    ///
    /// PHP: `Database::updateAttributeMeta()` (protected).
    pub(crate) async fn update_attribute_meta<F>(&self, collection: &str, id: &str, update: F) -> Result<Document>
    where
        F: FnOnce(&mut Document, &Document, usize) -> Result<()> + Send,
    {
        let mut collection = self.silent(|| self.get_collection(collection), None).await?;
        if collection.id() == Self::METADATA {
            return Err(Error::database("Cannot update metadata attributes"));
        }
        let mut attributes = documents_at(&collection, "attributes");
        let Some(position) = search(&attributes, id) else {
            return Err(Error::not_found("Attribute not found"));
        };
        update(&mut attributes[position], &collection, position)?;
        let attribute = attributes[position].clone();
        set_documents(&mut collection, "attributes", attributes);
        self.update_metadata(&collection, None, false, &format!("attribute metadata update '{id}'")).await?;
        let _ = self.trigger(Self::EVENT_ATTRIBUTE_UPDATE, &Value::from(attribute.clone()));
        Ok(attribute)
    }

    /// Sets whether an attribute is required (metadata only).
    ///
    /// PHP: `Database::updateAttributeRequired()`.
    pub async fn update_attribute_required(&self, collection: &str, id: &str, required: bool) -> Result<Document> {
        self.update_attribute_meta(collection, id, |attribute, _, _| {
            attribute.set("required", Value::Bool(required));
            Ok(())
        })
        .await
    }

    /// Sets an attribute's format (metadata only).
    ///
    /// PHP: `Database::updateAttributeFormat()`.
    pub async fn update_attribute_format(&self, collection: &str, id: &str, format: &str) -> Result<Document> {
        self.update_attribute_meta(collection, id, |attribute, _, _| {
            let kind = attribute.get("type").map(to_text).unwrap_or_default();
            if !formats::has(format, &kind) {
                return Err(Error::database(format!(
                    "Format \"{format}\" not available for attribute type \"{kind}\""
                )));
            }
            attribute.set("format", s(format));
            Ok(())
        })
        .await
    }

    /// Sets an attribute's format options (metadata only).
    ///
    /// PHP: `Database::updateAttributeFormatOptions()`.
    pub async fn update_attribute_format_options(
        &self,
        collection: &str,
        id: &str,
        format_options: Array,
    ) -> Result<Document> {
        self.update_attribute_meta(collection, id, |attribute, _, _| {
            attribute.set("formatOptions", Value::Array(format_options));
            Ok(())
        })
        .await
    }

    /// Sets an attribute's filters (metadata only).
    ///
    /// PHP: `Database::updateAttributeFilters()`.
    pub async fn update_attribute_filters(&self, collection: &str, id: &str, filters: Vec<String>) -> Result<Document> {
        self.update_attribute_meta(collection, id, |attribute, _, _| {
            attribute.set("filters", Value::Array(filters.iter().map(|f| s(f)).collect()));
            Ok(())
        })
        .await
    }

    /// Sets an attribute's default (metadata only).
    ///
    /// PHP: `Database::updateAttributeDefault()`.
    pub async fn update_attribute_default(&self, collection: &str, id: &str, default: Value) -> Result<Document> {
        self.update_attribute_meta(collection, id, |attribute, _, _| {
            if matches!(attribute.get("required"), Some(Value::Bool(true))) {
                return Err(Error::database("Cannot set a default value on a required attribute"));
            }
            let kind = string_param(attribute.get("type").unwrap_or(&Value::Null), "validateDefaultTypes", 1, "type")?;
            self.validate_default_types(&kind, &default)?;
            attribute.set("default", default);
            Ok(())
        })
        .await
    }

    /// Updates an attribute: each `Some` argument, and a `default` other
    /// than [`Value::Null`] (PHP's `??=`), replaces the current setting;
    /// alters the column when its type, size, signedness,
    /// array-ness or key changes; persists the metadata (reverting the
    /// column when that fails).
    ///
    /// PHP: `Database::updateAttribute($collection, $id, $type = null, $size
    /// = null, $required = null, $default = null, $signed = null, $array =
    /// null, $format = null, $formatOptions = null, $filters = null, $newKey
    /// = null)`.
    #[allow(clippy::too_many_arguments)]
    pub async fn update_attribute(
        &self,
        collection: &str,
        id: &str,
        kind: Option<&str>,
        size: Option<i64>,
        required: Option<bool>,
        default: Value,
        signed: Option<bool>,
        array: Option<bool>,
        format: Option<&str>,
        format_options: Option<Array>,
        filters: Option<Vec<String>>,
        new_key: Option<&str>,
    ) -> Result<Document> {
        let adapter = self.adapter().clone();
        let mut collection_doc = self.silent(|| self.get_collection(collection), None).await?;
        if collection_doc.id() == Self::METADATA {
            return Err(Error::database("Cannot update metadata attributes"));
        }
        let mut attributes = documents_at(&collection_doc, "attributes");
        let Some(position) = search(&attributes, id) else {
            return Err(Error::not_found("Attribute not found"));
        };
        let mut attribute = attributes[position].clone();
        let current = |key: &str| attribute.get(key).cloned().unwrap_or(Value::Null);

        let original_type = current("type");
        let original_size = current("size");
        let original_signed = current("signed");
        let original_array = current("array");
        let original_required = current("required");
        let original_key = current("key");
        let original_indexes = documents_at(&collection_doc, "indexes");

        let mut altering = kind.is_some() || size.is_some() || signed.is_some() || array.is_some() || new_key.is_some();
        let kind = kind.map_or_else(|| current("type"), s);
        let size = size.map_or_else(|| current("size"), Value::Int);
        let signed = signed.map_or_else(|| current("signed"), Value::Bool);
        let required = required.map_or_else(|| current("required"), Value::Bool);
        let mut default = if matches!(default, Value::Null) { current("default") } else { default };
        let array = array.map_or_else(|| current("array"), Value::Bool);
        let format = format.map_or_else(|| current("format"), s);
        let format_options = format_options.map_or_else(|| current("formatOptions"), Value::Array);
        let filters = filters.map_or_else(|| current("filters"), |f| Value::Array(f.iter().map(|f| s(f)).collect()));

        let kind_text = string_param(&kind, "normalizeBigIntSize", 1, "type")?;
        let size = self.normalize_big_int_size(&kind_text, int_param(&size, "normalizeBigIntSize", 2, "size")?);

        if matches!(required, Value::Bool(true)) && !matches!(default, Value::Null) {
            default = Value::Null;
        }
        if !adapter.support_for_spatial_index_null() && in_list(&kind, &Self::SPATIAL_TYPES) {
            altering = true;
        }

        match kind_text.as_str() {
            Self::VAR_STRING => {
                if size == 0 {
                    return Err(Error::database("Size length is required"));
                }
                if size > adapter.limit_for_string() {
                    return Err(Error::database(format!(
                        "Max size allowed for string is: {}",
                        number_format(Number::Int(adapter.limit_for_string()))
                    )));
                }
            }
            Self::VAR_VARCHAR => {
                if size == 0 {
                    return Err(Error::database("Size length is required"));
                }
                if size > adapter.max_varchar_length() {
                    return Err(Error::database(format!(
                        "Max size allowed for varchar is: {}",
                        number_format(Number::Int(adapter.max_varchar_length()))
                    )));
                }
            }
            Self::VAR_TEXT | Self::VAR_MEDIUMTEXT | Self::VAR_LONGTEXT | Self::VAR_BIGINT => {}
            Self::VAR_INTEGER => {
                let max = adapter.limit_for_int();
                // `$limit / 2` is a float when the limit is odd.
                let limit = if signed.truthy() {
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
            Self::VAR_FLOAT | Self::VAR_BOOLEAN | Self::VAR_DATETIME => {
                if size != 0 {
                    return Err(Error::database("Size must be empty"));
                }
            }
            Self::VAR_OBJECT => {
                if !adapter.support_for_object() {
                    return Err(Error::database("Object attributes are not supported"));
                }
                if size != 0 {
                    return Err(Error::database("Size must be empty for object attributes"));
                }
                if array.truthy() {
                    return Err(Error::database("Object attributes cannot be arrays"));
                }
            }
            Self::VAR_POINT | Self::VAR_LINESTRING | Self::VAR_POLYGON => {
                if !adapter.support_for_spatial_attributes() {
                    return Err(Error::database("Spatial attributes are not supported"));
                }
                if size != 0 {
                    return Err(Error::database("Size must be empty for spatial attributes"));
                }
                if array.truthy() {
                    return Err(Error::database("Spatial attributes cannot be arrays"));
                }
            }
            Self::VAR_VECTOR => {
                if !adapter.support_for_vectors() {
                    return Err(Error::database("Vector types are not supported by the current database"));
                }
                if array.truthy() {
                    return Err(Error::database("Vector type cannot be an array"));
                }
                if size <= 0 {
                    return Err(Error::database("Vector dimensions must be a positive integer"));
                }
                if size > Self::MAX_VECTOR_DIMENSIONS {
                    return Err(Error::database(format!(
                        "Vector dimensions cannot exceed {}",
                        Self::MAX_VECTOR_DIMENSIONS
                    )));
                }
                if !matches!(default, Value::Null) {
                    let Value::Array(components) = &default else {
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
            _ => {
                return Err(Error::database(format!(
                    "Unknown attribute type: {kind_text}. Must be one of {}",
                    self.supported_types().join(", ")
                )));
            }
        }

        let required_filters = self.required_filters(Some(&kind_text));
        let Value::Array(filter_list) = &filters else {
            return Err(Error::new(
                Kind::TypeError,
                format!("array_diff(): Argument #2 must be of type array, {} given", filters.type_name()),
            ));
        };
        let given: Vec<String> = filter_list.values().map(to_text).collect();
        if required_filters.iter().any(|f| !given.contains(f)) {
            return Err(Error::database(format!(
                "Attribute of type: {kind_text} requires the following filters: {}",
                required_filters.join(",")
            )));
        }

        if format.truthy() {
            let format_text = to_text(&format);
            if !formats::has(&format_text, &kind_text) {
                return Err(Error::database(format!(
                    "Format (\"{format_text}\") not available for this attribute type (\"{kind_text}\")"
                )));
            }
        }

        if !matches!(default, Value::Null) {
            if required.truthy() {
                return Err(Error::database("Cannot set a default value on a required attribute"));
            }
            self.validate_default_types(&kind_text, &default)?;
        }

        let key = s(new_key.unwrap_or(id));
        attribute.set("$id", key.clone());
        attribute.set("key", key);
        attribute.set("type", kind.clone());
        attribute.set("size", Value::Int(size));
        attribute.set("signed", signed.clone());
        attribute.set("array", array.clone());
        attribute.set("format", format);
        attribute.set("formatOptions", format_options);
        attribute.set("filters", filters);
        attribute.set("required", required.clone());
        attribute.set("default", default);

        attributes[position] = attribute.clone();
        set_documents(&mut collection_doc, "attributes", attributes.clone());

        if adapter.document_size_limit() > 0
            && adapter.attribute_width(&collection_doc)? >= adapter.document_size_limit()
        {
            return Err(Error::limit("Row width limit reached. Cannot update attribute."));
        }

        if Self::SPATIAL_TYPES.contains(&kind_text.as_str())
            && matches!(kind, Value::Str(_))
            && !adapter.support_for_spatial_index_null()
        {
            let lookup = |name: &str| {
                let name = name.to_ascii_lowercase();
                attributes.iter().rev().find(|a| {
                    let key = a.get("key").or_else(|| a.get("$id")).map(to_text).unwrap_or_default();
                    key.to_ascii_lowercase() == name
                })
            };
            for index in documents_at(&collection_doc, "indexes") {
                if !has_type(&index, Self::INDEX_SPATIAL) {
                    continue;
                }
                let names: Vec<String> = match index.get("attributes") {
                    Some(Value::Array(a)) => a.values().map(to_text).collect(),
                    _ => Vec::new(),
                };
                for name in names {
                    let Some(found) = lookup(&name) else { continue };
                    let found_type = found.get("type").cloned().unwrap_or(Value::Null);
                    let found_required = found.get("required").is_some_and(Value::truthy);
                    if matches!(&found_type, Value::Str(t) if Self::SPATIAL_TYPES.iter().any(|s| s.as_bytes() == t.as_bytes()))
                        && !found_required
                    {
                        return Err(Error::index(format!(
                            "Spatial indexes do not allow null values. Mark the attribute \"{name}\" as required or create the index on a column with no null values."
                        )));
                    }
                }
            }
        }

        let signed_bool = signed.truthy();
        let array_bool = array.truthy();
        let required_bool = required.truthy();
        let mut updated = false;
        if altering {
            let mut indexes = documents_at(&collection_doc, "indexes");
            if let Some(new_key) = new_key
                && id != new_key
            {
                let old = s(id);
                for index in &mut indexes {
                    let Some(Value::Array(names)) = index.get("attributes") else { continue };
                    if !names.values().any(|n| n.loose_eq(&old)) {
                        continue;
                    }
                    let renamed: Array = names
                        .iter()
                        .map(|(k, n)| (k.to_owned(), if n.strict_eq(&old) { s(new_key) } else { n.clone() }))
                        .collect();
                    index.set("attributes", Value::Array(renamed));
                }
                set_documents(&mut collection_doc, "indexes", indexes.clone());

                let validator = IndexDependency::new(indexes.clone(), adapter.support_for_cast_index_array());
                if !validator.is_valid(&Value::from(attribute.clone())) {
                    return Err(Error::dependency(validator.description()));
                }
            }

            if self.state().validate {
                let validator = IndexValidator::new(
                    &attributes,
                    original_indexes,
                    adapter.max_index_length(),
                    adapter.internal_indexes_keys().iter().map(|k| (*k).to_owned()).collect(),
                    self.index_support(),
                );
                for index in &indexes {
                    if !validator.is_valid(&Value::from(index.clone())) {
                        return Err(Error::index(validator.description()));
                    }
                }
            }

            updated = adapter
                .update_attribute(collection, id, &kind_text, size, signed_bool, array_bool, new_key, required_bool)
                .await?;
            if !updated {
                return Err(Error::database("Failed to update attribute"));
            }
        }

        let rollback = {
            let adapter = adapter.clone();
            let collection = collection.to_owned();
            let current_key = new_key.unwrap_or(id).to_owned();
            async move {
                let original_type = string_param(&original_type, "updateAttribute", 3, "type")?;
                let original_size = int_param(&original_size, "updateAttribute", 4, "size").unwrap_or(0);
                let original_key = match original_key {
                    Value::Null => None,
                    k => Some(to_text(&k)),
                };
                adapter
                    .update_attribute(
                        &collection,
                        &current_key,
                        &original_type,
                        original_size,
                        original_signed.truthy(),
                        original_array.truthy(),
                        original_key.as_deref(),
                        original_required.truthy(),
                    )
                    .await
                    .map(|_| ())
            }
            .boxed()
        };
        self.update_metadata(
            &collection_doc,
            Some(Rollback::Silent(rollback)),
            updated,
            &format!("attribute update '{id}'"),
        )
        .await?;

        self.purge_collection_metadata(collection, altering).await?;
        let _ = self.trigger(Self::EVENT_ATTRIBUTE_UPDATE, &Value::from(attribute.clone()));
        Ok(attribute)
    }

    /// Checks that `attribute` can be added to `collection` (attribute
    /// count and row width limits).
    ///
    /// PHP: `Database::checkAttribute()`.
    pub fn check_attribute(&self, collection: &Document, attribute: &Document) -> Result<bool> {
        let adapter = self.adapter();
        let mut collection = collection.clone();
        collection.set_with("attributes", Value::from(attribute.clone()), SetType::Append);

        let limit = adapter.limit_for_attributes();
        if limit > 0 && adapter.count_of_attributes(&collection) > limit {
            return Err(Error::limit(format!(
                "Column limit reached. Cannot create new attribute. Current attribute count is {} but the maximum is {limit}. Remove some attributes to free up space.",
                adapter.count_of_attributes(&collection)
            )));
        }
        let size = adapter.document_size_limit();
        if size > 0 && adapter.attribute_width(&collection)? >= size {
            return Err(Error::limit(format!(
                "Row width limit reached. Cannot create new attribute. Current row width is {} bytes but the maximum is {size} bytes. Reduce the size of existing attributes or remove some attributes to free up space.",
                adapter.attribute_width(&collection)?
            )));
        }
        Ok(true)
    }

    /// Deletes an attribute and the indexes over it; triggers
    /// [`Database::EVENT_ATTRIBUTE_DELETE`].
    ///
    /// PHP: `Database::deleteAttribute()`.
    pub async fn delete_attribute(&self, collection: &str, id: &str) -> Result<bool> {
        let adapter = self.adapter().clone();
        let mut collection = self.silent(|| self.get_collection(collection), None).await?;
        let mut attributes = documents_at(&collection, "attributes");
        let indexes = documents_at(&collection, "indexes");

        let Some(position) = attributes
            .iter()
            .position(|a| matches!(a.get("$id"), Some(Value::Str(i)) if i.as_bytes() == id.as_bytes()))
        else {
            return Err(Error::not_found("Attribute not found"));
        };
        let attribute = attributes.remove(position);

        if has_type(&attribute, Self::VAR_RELATIONSHIP) {
            return Err(Error::database("Cannot delete relationship as an attribute"));
        }

        if self.state().validate {
            let validator = IndexDependency::new(indexes.clone(), adapter.support_for_cast_index_array());
            if !validator.is_valid(&Value::from(attribute.clone())) {
                return Err(Error::dependency(validator.description()));
            }
        }

        let id_value = s(id);
        let mut kept = Vec::new();
        for mut index in indexes {
            let names: Vec<Value> = match index.get("attributes") {
                Some(Value::Array(a)) => a.values().filter(|n| !n.strict_eq(&id_value)).cloned().collect(),
                _ => Vec::new(),
            };
            if names.is_empty() {
                continue;
            }
            index.set("attributes", Value::Array(names.into_iter().collect()));
            kept.push(index);
        }
        set_documents(&mut collection, "attributes", attributes);
        set_documents(&mut collection, "indexes", kept);

        let collection_id = collection.id().to_owned();
        let mut should_rollback = false;
        match adapter.delete_attribute(&collection_id, id).await {
            Ok(true) => should_rollback = true,
            Ok(false) => return Err(Error::database("Failed to delete attribute")),
            Err(e) if e.is_a(Kind::NotFound) => {}
            Err(e) => return Err(e),
        }

        let rollback = {
            let adapter = adapter.clone();
            let collection_id = collection_id.clone();
            let id = id.to_owned();
            let get = |k: &str| attribute.get(k).cloned().unwrap_or(Value::Null);
            let (kind, size) = (get("type"), get("size"));
            let or = |v: Value, d: bool| if matches!(v, Value::Null) { d } else { v.truthy() };
            let signed = or(get("signed"), true);
            let array = or(get("array"), false);
            let required = or(get("required"), false);
            async move {
                let kind = string_param(&kind, "createAttribute", 3, "type")?;
                let size = int_param(&size, "createAttribute", 4, "size")?;
                adapter.create_attribute(&collection_id, &id, &kind, size, signed, array, required).await.map(|_| ())
            }
            .boxed()
        };
        self.update_metadata(
            &collection,
            Some(Rollback::Silent(rollback)),
            should_rollback,
            &format!("attribute deletion '{id}'"),
        )
        .await?;

        self.purge_collection_metadata(&collection_id, true).await?;
        let _ = self.trigger(Self::EVENT_ATTRIBUTE_DELETE, &Value::from(attribute));
        Ok(true)
    }

    /// Renames an attribute (and its references in indexes).
    ///
    /// PHP: `Database::renameAttribute()`.
    pub async fn rename_attribute(&self, collection: &str, old: &str, new: &str) -> Result<bool> {
        let adapter = self.adapter().clone();
        let mut collection = self.silent(|| self.get_collection(collection), None).await?;
        let mut attributes = documents_at(&collection, "attributes");
        let mut indexes = documents_at(&collection, "indexes");

        let mut position = None;
        for (i, value) in attributes.iter().enumerate() {
            if value.id() == old {
                position = Some(i);
            }
            if value.id() == new {
                return Err(Error::duplicate("Attribute name already used"));
            }
        }
        let Some(position) = position.filter(|p| !attributes[*p].is_empty()) else {
            return Err(Error::not_found("Attribute not found"));
        };

        if self.state().validate {
            let validator = IndexDependency::new(indexes.clone(), adapter.support_for_cast_index_array());
            if !validator.is_valid(&Value::from(attributes[position].clone())) {
                return Err(Error::dependency(validator.description()));
            }
        }

        attributes[position].set("$id", s(new));
        attributes[position].set("key", s(new));
        let attribute = attributes[position].clone();

        let old_value = s(old);
        for index in &mut indexes {
            let names = match index.get("attributes") {
                Some(Value::Array(a)) => a.clone(),
                _ => Array::new(),
            };
            let renamed: Array = names
                .iter()
                .map(|(k, n)| (k.to_owned(), if n.strict_eq(&old_value) { s(new) } else { n.clone() }))
                .collect();
            index.set("attributes", Value::Array(renamed));
        }

        let collection_id = collection.id().to_owned();
        let renamed = match adapter.rename_attribute(&collection_id, old, new).await {
            Ok(true) => true,
            failed => {
                let e = match failed {
                    Err(e) => e,
                    _ => Error::database("Failed to rename attribute"),
                };
                let fail = |e: Error| {
                    Error::database(format!("Failed to rename attribute '{old}' to '{new}': {}", e.message()))
                        .with_previous(e)
                };
                if !adapter.support_for_schema_attributes() {
                    return Err(fail(e));
                }
                // A prior partial failure may have renamed the column.
                let filtered = adapter.filter(new)?.to_ascii_lowercase();
                let schema = self.schema_attributes(&collection_id).await?;
                if !schema.iter().any(|a| a.id().to_ascii_lowercase() == filtered) {
                    return Err(fail(e));
                }
                true
            }
        };

        set_documents(&mut collection, "attributes", attributes);
        set_documents(&mut collection, "indexes", indexes);

        let rollback = {
            let adapter = adapter.clone();
            let collection_id = collection_id.clone();
            let (old, new) = (old.to_owned(), new.to_owned());
            async move { adapter.rename_attribute(&collection_id, &new, &old).await.map(|_| ()) }.boxed()
        };
        self.update_metadata(
            &collection,
            Some(Rollback::Throwing(rollback)),
            renamed,
            &format!("attribute rename '{old}' to '{new}'"),
        )
        .await?;

        self.with_retries(|| async { self.purge_cached_collection(&collection_id).await.map(|_| ()) }, 3, 100, 2.0)
            .await?;
        let _ = self.trigger(Self::EVENT_ATTRIBUTE_UPDATE, &Value::from(attribute));
        Ok(renamed)
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn array_unique_keeps_the_first_keys() {
        let values: Array = [s("x"), s("x"), s("datetime")].into_iter().collect();
        let unique = array_unique(values);
        assert_eq!(unique.len(), 2);
        assert!(unique.get(&ArrayKey::Int(0)).is_some_and(|v| v.strict_eq(&s("x"))));
        assert!(unique.get(&ArrayKey::Int(2)).is_some_and(|v| v.strict_eq(&s("datetime"))));
    }

    #[test]
    fn gettype_names() {
        assert_eq!(gettype(&Value::Float(1.0)), "double");
        assert_eq!(gettype(&Value::Null), "NULL");
        assert_eq!(gettype(&Value::Bool(true)), "boolean");
    }

    #[test]
    fn int_params_coerce_like_php() {
        assert_eq!(int_param(&s("12"), "f", 1, "size").unwrap(), 12);
        assert_eq!(int_param(&Value::Float(3.0), "f", 1, "size").unwrap(), 3);
        assert!(int_param(&Value::Null, "f", 1, "size").unwrap_err().is_a(Kind::TypeError));
    }
}
