//! Attributes: create, update, rename, delete, and the checks they run.
//!
//! Area: schema.

// TEMPORARY (phase A skeleton): the `todo!()` bodies leave parameters and
// helpers unused until the schema area is implemented.
#![allow(unused_variables, dead_code)]

use super::Database;
use crate::document::Document;
use crate::error::Result;
use crate::value::{Array, Value};

impl Database {
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
        todo!("schema")
    }

    /// Creates several attributes at once, each an array with `$id`,
    /// `type`, `size`, `required` and optionally `default`, `signed`,
    /// `array`, `format`, `formatOptions`, `filters` (missing keys raise
    /// PHP's errors); triggers [`Database::EVENT_ATTRIBUTE_CREATE`] with
    /// the list.
    ///
    /// PHP: `Database::createAttributes()`.
    pub async fn create_attributes(&self, collection: &str, attributes: Vec<Array>) -> Result<bool> {
        todo!("schema")
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
        filters: &[String],
        schema_attributes: Option<&[Document]>,
    ) -> Result<Document> {
        todo!("schema")
    }

    /// The filters an attribute of type `kind` must carry (`datetime`,
    /// spatial types, `vector`, `object`).
    ///
    /// PHP: `Database::getRequiredFilters()` (protected).
    pub(crate) fn required_filters(&self, kind: Option<&str>) -> Vec<String> {
        todo!("schema")
    }

    /// Checks that `default` suits an attribute of type `kind`.
    ///
    /// PHP: `Database::validateDefaultTypes()` (protected).
    pub(crate) fn validate_default_types(&self, kind: &str, default: &Value) -> Result<()> {
        todo!("schema")
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
        todo!("schema")
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
        todo!("schema")
    }

    /// Sets whether an attribute is required (metadata only).
    ///
    /// PHP: `Database::updateAttributeRequired()`.
    pub async fn update_attribute_required(&self, collection: &str, id: &str, required: bool) -> Result<Document> {
        todo!("schema")
    }

    /// Sets an attribute's format (metadata only).
    ///
    /// PHP: `Database::updateAttributeFormat()`.
    pub async fn update_attribute_format(&self, collection: &str, id: &str, format: &str) -> Result<Document> {
        todo!("schema")
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
        todo!("schema")
    }

    /// Sets an attribute's filters (metadata only).
    ///
    /// PHP: `Database::updateAttributeFilters()`.
    pub async fn update_attribute_filters(&self, collection: &str, id: &str, filters: Vec<String>) -> Result<Document> {
        todo!("schema")
    }

    /// Sets an attribute's default (metadata only).
    ///
    /// PHP: `Database::updateAttributeDefault()`.
    pub async fn update_attribute_default(&self, collection: &str, id: &str, default: Value) -> Result<Document> {
        todo!("schema")
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
        todo!("schema")
    }

    /// Checks that `attribute` can be added to `collection` (attribute
    /// count and row width limits).
    ///
    /// PHP: `Database::checkAttribute()`.
    pub fn check_attribute(&self, collection: &Document, attribute: &Document) -> Result<bool> {
        todo!("schema")
    }

    /// Deletes an attribute and the indexes over it; triggers
    /// [`Database::EVENT_ATTRIBUTE_DELETE`].
    ///
    /// PHP: `Database::deleteAttribute()`.
    pub async fn delete_attribute(&self, collection: &str, id: &str) -> Result<bool> {
        todo!("schema")
    }

    /// Renames an attribute (and its references in indexes).
    ///
    /// PHP: `Database::renameAttribute()`.
    pub async fn rename_attribute(&self, collection: &str, old: &str, new: &str) -> Result<bool> {
        todo!("schema")
    }
}
