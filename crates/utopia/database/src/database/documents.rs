//! Documents: get, create, update, upsert, increase/decrease, delete,
//! singly and in batches.
//!
//! Area: documents.
//!
//! PHP `Document`s are shared objects that the library mutates in place
//! (`encode`, `decode`, `casting`, the adapter's writes); here each step
//! takes the document and returns it, and the result is kept where PHP's
//! object would have been changed.
//!
//! Relationship work (`create/update/delete_document_relationships`,
//! `populate_documents_relationships`) runs only for collections that have
//! relationship attributes: PHP's methods do nothing for the others.

use std::collections::HashMap;

use futures_util::future::BoxFuture;
use php_std::datetime::{DateTime, TzInfo};
use php_std::value::Number;

use super::{Callback, Database};
use crate::change::Change;
use crate::document::Document;
use crate::error::{Error, Kind, Result};
use crate::operator::Operator;
use crate::query::Query;
use crate::validator::authorization::Input;
use crate::validator::queries::document::Document as DocumentValidator;
use crate::validator::queries::documents::{Documents as DocumentsValidator, DocumentsOptions};
use crate::validator::structure::{Structure, StructureOptions};
use crate::validator::{array_key, index};
use crate::value::{Array, ArrayKey, Ext, KeyRef, Str, Value, to_text};

/// `Database::CACHE_EMPTY_MARKER`: the cache entry of a document that does
/// not exist.
const CACHE_EMPTY_MARKER: &str = "$empty";

/// No nested selections (`populateDocumentsRelationships`' default).
static NO_SELECTS: super::Selects = Vec::new();

/// `$collection->getAttribute('attributes', [])` as attribute documents.
pub(crate) fn attribute_documents(collection: &Document) -> Vec<Document> {
    super::filters::collection_attributes(collection).into_iter().map(attribute_document).collect()
}

/// An attribute (a document, or an array) as a document.
fn attribute_document(value: Value) -> Document {
    match value {
        Value::Ext(Ext::Document(d)) => *d,
        Value::Array(a) => Document::from_array_unchecked(a),
        _ => Document::empty(),
    }
}

/// The relationship attributes of a collection (`$attribute['type'] ===
/// Database::VAR_RELATIONSHIP`).
pub(crate) fn relationship_attributes(collection: &Document) -> Vec<Document> {
    super::filters::collection_attributes(collection)
        .into_iter()
        .filter(is_relationship)
        .map(attribute_document)
        .collect()
}

/// Whether an attribute is a relationship.
fn is_relationship(attribute: &Value) -> bool {
    matches!(index(attribute, "type"), Value::Str(s) if s.as_bytes() == Database::VAR_RELATIONSHIP.as_bytes())
}

/// `$document->getSequence()`: `?string`, other scalars coerced as PHP's
/// return type does.
pub(crate) fn sequence(document: &Document) -> Value {
    match document.get("$sequence") {
        None => Value::Null,
        Some(v @ Value::Str(_)) => v.clone(),
        Some(v @ (Value::Int(_) | Value::Float(_) | Value::Bool(_))) => {
            Value::Str(v.to_php_string().unwrap_or_default())
        }
        Some(other) => other.clone(),
    }
}

/// `sort($permissions)`.
fn sorted(mut permissions: Vec<String>) -> Vec<String> {
    permissions.sort_by(|a, b| Value::from(a.as_str()).cmp_php(&Value::from(b.as_str())));
    permissions
}

/// `$a + $b` on numbers: integers and floats as they are, numeric strings
/// converted, anything else `0`.
fn number(value: &Value) -> Number {
    match value {
        Value::Int(i) => Number::Int(*i),
        Value::Float(f) => Number::Float(*f),
        Value::Bool(b) => Number::Int(i64::from(*b)),
        Value::Str(s) => php_std::value::numeric_bytes_ex(s.as_bytes()).map(|(n, _)| n).unwrap_or(Number::Int(0)),
        _ => Number::Int(0),
    }
}

/// A number as a value.
fn value_of(number: Number) -> Value {
    match number {
        Number::Int(i) => Value::Int(i),
        Number::Float(f) => Value::Float(f),
    }
}

/// `array_merge($a, $b)`: string keys of `b` replace those of `a` in place,
/// integer keys are renumbered and appended.
pub(crate) fn array_merge(a: Array, b: Array) -> Array {
    let mut out = Array::with_capacity(a.len() + b.len());
    for (k, v) in a.into_iter().chain(b) {
        match k {
            ArrayKey::Int(_) => {
                let _ = out.push(v);
            }
            k => {
                out.set(k, v);
            }
        }
    }
    out
}

/// A document of `pairs`.
fn event_document(pairs: Vec<(&str, Value)>) -> Value {
    Value::from(Document::from_array_unchecked(pairs.into_iter().map(|(k, v)| (ArrayKey::from(k), v)).collect()))
}

/// `new \DateTime($value)` (`null` is now).
fn parse_datetime(value: Option<&Value>) -> Result<DateTime> {
    let text = value.map(to_text).unwrap_or_default();
    let text = if text.is_empty() { "now".to_owned() } else { text };
    DateTime::new(text.as_bytes(), None, &TzInfo::utc()).map_err(|e| Error::database(e.message()))
}

/// Calls `on_next` with `args`; its error goes to `on_error` when there is
/// one, and is raised otherwise.
///
/// PHP: `try { $onNext && $onNext(...); } catch (\Throwable $e) { $onError ? $onError($e) : throw $e; }`.
pub(crate) async fn notify<A: Send + 'static>(
    on_next: &mut Option<&mut dyn Callback<A>>,
    on_error: &mut Option<&mut dyn Callback<Error>>,
    args: A,
) -> Result<()> {
    let Some(callback) = on_next.as_mut() else {
        return Ok(());
    };
    if let Err(e) = callback.call(args).await {
        match on_error.as_mut() {
            Some(handler) => handler.call(e).await?,
            None => return Err(e),
        }
    }
    Ok(())
}

impl Database {
    /// The options of a `Structure` / `PartialStructure` validator for this
    /// adapter.
    pub(crate) fn structure_options(&self, current_document: Option<Document>) -> StructureOptions {
        let adapter = &self.inner.adapter;
        StructureOptions {
            min_allowed_date: adapter.min_date_time(),
            max_allowed_date: adapter.max_date_time(),
            support_for_attributes: adapter.support_for_attributes(),
            support_unsigned_big_int: adapter.support_for_unsigned_big_int(),
            current_document,
        }
    }

    /// `new DocumentsValidator(...)` for a collection, as every bulk method
    /// builds it.
    pub(crate) fn documents_validator(&self, collection: &Document) -> DocumentsValidator {
        let adapter = &self.inner.adapter;
        let indexes = match collection.get("indexes") {
            Some(Value::Array(a)) => a.values().cloned().map(attribute_document).collect(),
            _ => Vec::new(),
        };
        DocumentsValidator::with_options(
            &attribute_documents(collection),
            &indexes,
            adapter.id_attribute_type(),
            DocumentsOptions {
                max_values_count: self.max_query_values(),
                max_uid_length: adapter.max_uid_length(),
                min_allowed_date: adapter.min_date_time(),
                max_allowed_date: adapter.max_date_time(),
                support_for_attributes: adapter.support_for_attributes(),
                support_unsigned_big_int: adapter.support_for_unsigned_big_int(),
            },
        )
    }

    /// Fails with a `QueryException` when the queries do not suit the
    /// collection (validation on).
    pub(crate) fn validate_queries(&self, collection: &Document, queries: &[Query]) -> Result<()> {
        self.check_query_types(queries)?;
        if self.state().validate {
            let validator = self.documents_validator(collection);
            if !validator.check(queries) {
                return Err(Error::query(validator.description()));
            }
        }
        Ok(())
    }

    /// Whether the authorization allows `action` on `permissions`.
    pub(crate) fn allowed(&self, action: &str, permissions: Vec<String>) -> bool {
        self.authorization().is_valid(&Input::new(action, permissions))
    }

    /// An `AuthorizationException` with the authorization's description.
    pub(crate) fn unauthorized(&self) -> Error {
        Error::authorization(self.authorization().description())
    }

    /// `$document` as an instance of the collection's custom class, when
    /// one is set (`createDocumentInstance($id, $document->getArrayCopy())`).
    pub(crate) fn typed(&self, collection: &str, document: Document) -> Result<Document> {
        if self.document_type(collection).is_none() {
            return Ok(document);
        }
        self.create_document_instance(collection, document.array_copy(&[], &[]))
    }

    /// Fails with a `ConflictException` when `updated_at` is after the
    /// request timestamp.
    fn check_timestamp(&self, updated_at: Option<&Value>) -> Result<()> {
        let old = parse_datetime(updated_at)?;
        let timestamp = self.state().timestamp.clone();
        if let Some(timestamp) = timestamp
            && old > timestamp
        {
            return Err(Error::conflict("Document was updated after the request timestamp"));
        }
        Ok(())
    }

    /// The document `id` of `collection` (empty when it does not exist or
    /// is not readable), honouring `select` queries, the cache, TTL
    /// indexes and relationships. `for_update` locks the row (inside a
    /// transaction).
    ///
    /// PHP: `Database::getDocument($collection, $id, $queries = [], $forUpdate = false)`.
    ///
    /// Boxed (a named future type): reading the collection reads a document,
    /// and relationship population reads documents back through here.
    pub fn get_document<'a>(
        &'a self,
        collection: &'a str,
        id: &'a str,
        queries: Vec<Query>,
        for_update: bool,
    ) -> BoxFuture<'a, Result<Document>> {
        Box::pin(self.read_document(collection, id, queries, for_update))
    }

    /// [`Database::get_document`].
    async fn read_document(
        &self,
        collection: &str,
        id: &str,
        queries: Vec<Query>,
        for_update: bool,
    ) -> Result<Document> {
        if collection == Self::METADATA && id == Self::METADATA {
            return Ok(Self::metadata_collection());
        }
        if collection.is_empty() || collection == "0" {
            return Err(Error::not_found("Collection not found"));
        }
        if id.is_empty() || id == "0" {
            return self.create_document_instance(collection, Array::new());
        }
        let collection = self.silent(|| self.get_collection(collection), None).await?;
        if collection.is_empty() {
            return Err(Error::not_found("Collection not found"));
        }
        let adapter = self.inner.adapter.clone();

        self.check_query_types(&queries)?;
        if self.state().validate {
            let validator = DocumentValidator::new(&attribute_documents(&collection), adapter.support_for_attributes());
            if !validator.check(&queries) {
                return Err(Error::query(validator.description()));
            }
        }

        let relationships = relationship_attributes(&collection);
        let selects = Query::group_by_type(&queries).selections;
        let selections = self.validate_selections(&collection, &selects)?;
        let mut queries = queries;
        let nested_selections = if relationships.is_empty() {
            Vec::new()
        } else {
            self.process_relationship_queries(&relationships, &mut queries)?
        };
        let document_security = collection.get("documentSecurity").is_some_and(Value::truthy);
        let (collection_key, document_key, hash_key) = self.cache_keys(collection.id(), Some(id), &selections);
        let cache = self.cache();

        let mut cached = None;
        if !for_update {
            cached =
                cache.load(&document_key, Self::TTL, &hash_key).await.unwrap_or(None).map(crate::value::from_plain);
        }
        if let Some(Value::Array(entry)) = &cached
            && entry.get_str(CACHE_EMPTY_MARKER).is_some_and(|v| !matches!(v, Value::Null))
        {
            return self.create_document_instance(collection.id(), Array::new());
        }
        if let Some(cached) = cached.filter(Value::truthy) {
            let attrs = match cached {
                Value::Array(a) => a,
                _ => Array::new(),
            };
            let document = self.create_document_instance(collection.id(), attrs)?;
            let document = self.casting(&collection, document)?;
            if collection.id() != Self::METADATA {
                let mut permissions = collection.read();
                if document_security {
                    permissions.extend(document.read());
                }
                if !self.allowed(Self::PERMISSION_READ, permissions) {
                    return self.create_document_instance(collection.id(), Array::new());
                }
            }
            self.trigger_with(Self::EVENT_DOCUMENT_READ, || Value::from(document.clone()))?;
            if self.is_ttl_expired(&collection, &document) {
                return self.create_document_instance(collection.id(), Array::new());
            }
            return Ok(document);
        }

        let mut generation = "0".to_owned();
        if !for_update {
            generation = cache.generation(&document_key).await.unwrap_or_else(|_| "0".to_owned());
        }

        let document = adapter.get_document(&collection, id, &queries, for_update).await?;
        if document.is_empty() {
            if !for_update && relationships.is_empty() {
                let marker: Array = [(ArrayKey::from(CACHE_EMPTY_MARKER), Value::Bool(true))].into_iter().collect();
                let marker = crate::value::to_plain(&Value::Array(marker));
                if cache.save_with_lease(&document_key, &marker, &hash_key, &generation).await.unwrap_or(false) {
                    let _ = cache.save(&collection_key, &php_std::Value::Str("empty".into()), &document_key, 0).await;
                }
            }
            return self.create_document_instance(collection.id(), Array::new());
        }
        if self.is_ttl_expired(&collection, &document) {
            return self.create_document_instance(collection.id(), Array::new());
        }

        let document = adapter.casting_after(&collection, document)?;
        let mut document = self.typed(collection.id(), document)?;
        document.set("$collection", Value::from(collection.id()));

        if collection.id() != Self::METADATA {
            let mut permissions = collection.read();
            if document_security {
                permissions.extend(document.read());
            }
            if !self.allowed(Self::PERMISSION_READ, permissions) {
                return self.create_document_instance(collection.id(), Array::new());
            }
        }

        let document = self.casting(&collection, document)?;
        let mut document = self.decode(&collection, document, &selections).await?;

        let (in_batch, resolve, depth) = {
            let s = self.state();
            (s.in_batch_relationship_population, s.resolve_relationships, s.relationship_fetch_depth)
        };
        if !in_batch && resolve && !relationships.is_empty() && (selects.is_empty() || !nested_selections.is_empty()) {
            let documents = self
                .silent(
                    || self.populate_documents_relationships(vec![document], &collection, depth, &nested_selections),
                    None,
                )
                .await?;
            document = documents.into_iter().next().unwrap_or_default();
        }

        if !for_update && relationships.is_empty() {
            let copy = crate::value::to_plain(&Value::Array(document.array_copy(&[], &[])));
            if cache.save_with_lease(&document_key, &copy, &hash_key, &generation).await.unwrap_or(false) {
                let _ = cache.save(&collection_key, &php_std::Value::Str("empty".into()), &document_key, 0).await;
            }
        }

        self.trigger_with(Self::EVENT_DOCUMENT_READ, || Value::from(document.clone()))?;
        Ok(document)
    }

    /// Whether a TTL index of `collection` says `document` expired.
    ///
    /// PHP: `Database::isTtlExpired()` (private).
    pub(crate) fn is_ttl_expired(&self, collection: &Document, document: &Document) -> bool {
        if !self.inner.adapter.support_for_ttl_indexes() {
            return false;
        }
        let indexes = match collection.get("indexes") {
            Some(Value::Array(a)) => a.values().cloned().collect::<Vec<_>>(),
            _ => Vec::new(),
        };
        for index in &indexes {
            if crate::validator::index(index, "type") != &Value::Str(Self::INDEX_TTL.into()) {
                continue;
            }
            let ttl = crate::value::to_long(crate::validator::index(index, "ttl"));
            let attribute = crate::validator::nth(crate::validator::index(index, "attributes"), 0);
            if ttl <= 0 || !attribute.truthy() {
                return false;
            }
            if let Some(Value::Str(start)) = document.get(&crate::value::to_text(attribute)) {
                let utc = php_std::datetime::TzInfo::utc();
                let (Ok(mut expiry), Ok(now)) = (
                    php_std::datetime::DateTime::new(start.as_bytes(), None, &utc),
                    php_std::datetime::DateTime::new(b"now", None, &utc),
                ) else {
                    return false;
                };
                if expiry.modify(format!("+{ttl} seconds").as_bytes()).is_err() {
                    return false;
                }
                return now > expiry;
            }
        }
        false
    }

    /// The `$id`, `$collection`, `$createdAt`, `$updatedAt`, `$permissions`
    /// and `$tenant` a new document gets.
    fn prepare_new(&self, collection: &Document, document: &mut Document, time: &str, metadata: bool) -> Result<()> {
        let adapter = &self.inner.adapter;
        let preserve_dates = self.state().preserve_dates;
        let created_at = document.get("$createdAt").cloned();
        let updated_at = document.get("$updatedAt").cloned();
        let id = document.id().to_owned();
        let id = if id.is_empty() || id == "0" { crate::helpers::id::unique()? } else { id };
        document.set("$id", Value::from(id.as_str()));
        document.set("$collection", Value::from(collection.id()));
        document.set(
            "$createdAt",
            match created_at {
                Some(v) if preserve_dates => v,
                _ => Value::from(time),
            },
        );
        document.set(
            "$updatedAt",
            match updated_at {
                Some(v) if preserve_dates => v,
                _ => Value::from(time),
            },
        );
        if document.permissions().is_empty() {
            document.set("$permissions", Value::Array(Array::new()));
        }
        if adapter.shared_tables() {
            if adapter.tenant_per_document() {
                if !metadata && document.tenant().is_none() {
                    return Err(Error::database(
                        "Missing tenant. Tenant must be set when tenant per document is enabled.",
                    ));
                }
            } else {
                document.set("$tenant", adapter.tenant());
            }
        }
        Ok(())
    }

    /// Creates a document (and its related documents); triggers
    /// [`Database::EVENT_DOCUMENT_CREATE`].
    ///
    /// PHP: `Database::createDocument()`.
    ///
    /// Boxed: creating a collection creates its metadata document, and
    /// relationship writes create related documents through here.
    pub fn create_document<'a>(&'a self, collection: &'a str, document: Document) -> BoxFuture<'a, Result<Document>> {
        Box::pin(self.write_document(collection, document))
    }

    /// [`Database::create_document`].
    async fn write_document(&self, collection: &str, mut document: Document) -> Result<Document> {
        let adapter = self.inner.adapter.clone();
        if collection != Self::METADATA
            && adapter.shared_tables()
            && !adapter.tenant_per_document()
            && !adapter.tenant().truthy()
        {
            return Err(Error::database("Missing tenant. Tenant must be set when table sharing is enabled."));
        }
        if !adapter.shared_tables() && adapter.tenant_per_document() {
            return Err(Error::database("Shared tables must be enabled if tenant per document is enabled."));
        }

        let collection = self.silent(|| self.get_collection(collection), None).await?;
        let metadata = collection.id() == Self::METADATA;
        if !metadata && !self.allowed(Self::PERMISSION_CREATE, collection.create()) {
            return Err(self.unauthorized());
        }

        let time = crate::datetime::now();
        self.prepare_new(&collection, &mut document, &time, metadata)?;

        let mut document = self.encode(&collection, document, true).await?;

        if self.state().validate {
            let validator = crate::validator::permissions::Permissions::default();
            let permissions = Value::Array(document.permission_list()?);
            if !validator.is_valid(&permissions) {
                return Err(Error::database(validator.description()));
            }
            let structure =
                Structure::with_options(collection.clone(), adapter.id_attribute_type(), self.structure_options(None));
            if !structure.validate(&mut document)? {
                return Err(Error::structure(structure.description()));
            }
        }

        let document = adapter.casting_before(&collection, document)?;
        let has_relationships = !relationship_attributes(&collection).is_empty();

        let document = self
            .with_transaction(|| {
                let (collection, document, adapter) = (&collection, document.clone(), &adapter);
                async move {
                    let document = if has_relationships && self.state().resolve_relationships {
                        self.silent(|| self.create_document_relationships(collection, document), None).await?
                    } else {
                        document
                    };
                    adapter.create_document(collection, document).await
                }
            })
            .await?;

        self.with_document_tenant(&document, || {
            self.purge_cached_document_internal(collection.id(), Some(document.id()))
        })
        .await?;

        let (in_batch, resolve, depth) = {
            let s = self.state();
            (s.in_batch_relationship_population, s.resolve_relationships, s.relationship_write_stack.len() as i64)
        };
        let mut document = document;
        if !in_batch && resolve {
            if has_relationships {
                let documents = self
                    .silent(
                        || self.populate_documents_relationships(vec![document], &collection, depth, &NO_SELECTS),
                        None,
                    )
                    .await?;
                document = documents.into_iter().next().unwrap_or_default();
            }
            document = adapter.casting_after(&collection, document)?;
        }

        let document = self.casting(&collection, document)?;
        let document = self.decode(&collection, document, &[]).await?;
        let document = self.typed(collection.id(), document)?;

        self.trigger_with(Self::EVENT_DOCUMENT_CREATE, || Value::from(document.clone()))?;
        Ok(document)
    }

    /// Creates documents in batches of `batch_size`, calling `on_next` with
    /// each created document; returns how many were created.
    ///
    /// PHP: `Database::createDocuments($collection, $documents, $batchSize =
    /// self::INSERT_BATCH_SIZE, $onNext = null, $onError = null)`.
    pub async fn create_documents(
        &self,
        collection: &str,
        documents: Vec<Document>,
        batch_size: i64,
        mut on_next: Option<&mut dyn Callback<Document>>,
        mut on_error: Option<&mut dyn Callback<Error>>,
    ) -> Result<i64> {
        let adapter = self.inner.adapter.clone();
        if !adapter.shared_tables() && adapter.tenant_per_document() {
            return Err(Error::database("Shared tables must be enabled if tenant per document is enabled."));
        }
        if documents.is_empty() {
            return Ok(0);
        }
        let batch_size = Self::INSERT_BATCH_SIZE.min(batch_size.max(1)) as usize;
        let collection = self.silent(|| self.get_collection(collection), None).await?;
        if collection.id() != Self::METADATA && !self.allowed(Self::PERMISSION_CREATE, collection.create()) {
            return Err(self.unauthorized());
        }

        let time = crate::datetime::now();
        let mut modified = 0;
        let has_relationships = !relationship_attributes(&collection).is_empty();
        // PHP's caller holds the documents it passed, which end as the ones
        // handed to `on_next`.
        let mut capture = self.state().capture.take();

        let mut prepared = Vec::with_capacity(documents.len());
        for mut document in documents {
            // Unlike createDocument, the metadata collection gets no exception.
            self.prepare_new(&collection, &mut document, &time, false)?;
            let mut document = self.encode(&collection, document, true).await?;
            if self.state().validate {
                let structure = Structure::with_options(
                    collection.clone(),
                    adapter.id_attribute_type(),
                    self.structure_options(None),
                );
                if !structure.validate(&mut document)? {
                    return Err(Error::structure(structure.description()));
                }
            }
            if has_relationships && self.state().resolve_relationships {
                document = self.silent(|| self.create_document_relationships(&collection, document), None).await?;
            }
            prepared.push(adapter.casting_before(&collection, document)?);
        }

        for chunk in prepared.chunks(batch_size) {
            let insert = || {
                self.with_transaction(|| {
                    let (collection, chunk, adapter) = (&collection, chunk.to_vec(), &adapter);
                    async move { adapter.create_documents(collection, chunk).await }
                })
            };
            let mut batch = if self.state().skip_duplicates {
                let previous = adapter.set_skip_duplicates(true);
                let result = insert().await;
                adapter.set_skip_duplicates(previous);
                result?
            } else {
                insert().await?
            };

            if on_next.is_some() || has_relationships {
                batch = adapter.get_sequences(collection.id(), batch).await?;
            }

            let (in_batch, resolve, depth) = {
                let s = self.state();
                (s.in_batch_relationship_population, s.resolve_relationships, s.relationship_fetch_depth)
            };
            if !in_batch && resolve && has_relationships {
                batch = self
                    .silent(|| self.populate_documents_relationships(batch, &collection, depth, &NO_SELECTS), None)
                    .await?;
            }

            for document in batch {
                let document = adapter.casting_after(&collection, document)?;
                let document = self.casting(&collection, document)?;
                let document = self.decode(&collection, document, &[]).await?;
                self.with_document_tenant(&document, || {
                    self.purge_cached_document_internal(collection.id(), Some(document.id()))
                })
                .await?;
                if let Some(captured) = capture.as_mut() {
                    captured.push(document.clone());
                }
                notify(&mut on_next, &mut on_error, document).await?;
                modified += 1;
            }
        }

        if capture.is_some() {
            self.state().capture = capture;
        }
        self.trigger(
            Self::EVENT_DOCUMENTS_CREATE,
            &event_document(vec![("$collection", Value::from(collection.id())), ("modified", Value::Int(modified))]),
        )?;
        Ok(modified)
    }

    /// Updates document `id` with the attributes of `document` (and its
    /// relationships, operators); triggers
    /// [`Database::EVENT_DOCUMENT_UPDATE`].
    ///
    /// PHP: `Database::updateDocument()`.
    ///
    /// Boxed: relationship writes update related documents through here.
    pub fn update_document<'a>(
        &'a self,
        collection: &'a str,
        id: &'a str,
        document: Document,
    ) -> BoxFuture<'a, Result<Document>> {
        Box::pin(self.rewrite_document(collection, id, document))
    }

    /// [`Database::update_document`].
    async fn rewrite_document(&self, collection: &str, id: &str, document: Document) -> Result<Document> {
        if id.is_empty() || id == "0" {
            return Err(Error::database("Must define $id attribute"));
        }
        let collection = self.silent(|| self.get_collection(collection), None).await?;
        let new_updated_at = document.get("$updatedAt").cloned();

        let (document, has_operators) = self
            .with_transaction(|| self.update_in_transaction(&collection, id, document.clone(), new_updated_at.clone()))
            .await?;

        if document.is_empty() {
            return Ok(document);
        }

        self.purge_cached_document_internal(collection.id(), Some(id)).await?;

        let mut document = document;
        let (in_batch, resolve, depth) = {
            let s = self.state();
            (s.in_batch_relationship_population, s.resolve_relationships, s.relationship_fetch_depth)
        };
        if !in_batch && resolve && !relationship_attributes(&collection).is_empty() {
            let documents = self
                .silent(|| self.populate_documents_relationships(vec![document], &collection, depth, &NO_SELECTS), None)
                .await?;
            document = documents.into_iter().next().unwrap_or_default();
        }

        if !has_operators {
            document = self.decode(&collection, document, &[]).await?;
        }
        let document = self.typed(collection.id(), document)?;

        self.trigger_with(Self::EVENT_DOCUMENT_UPDATE, || Value::from(document.clone()))?;
        Ok(document)
    }

    /// The transaction of [`Database::update_document`]: the document
    /// written (empty when there is none to update), and whether it held
    /// operators.
    async fn update_in_transaction(
        &self,
        collection: &Document,
        id: &str,
        document: Document,
        new_updated_at: Option<Value>,
    ) -> Result<(Document, bool)> {
        let adapter = self.inner.adapter.clone();
        let time = crate::datetime::now();
        let authorization = self.authorization();
        let mut old = authorization
            .skip(|| self.silent(|| self.get_document(collection.id(), id, Vec::new(), true), None))
            .await?;
        if old.is_empty() {
            return Ok((Document::empty(), false));
        }

        let mut skip_permissions_update = true;
        if document.has("$permissions") {
            skip_permissions_update = sorted(old.permissions()) == sorted(document.permissions());
        }
        if document.has("$id") && document.id() != id {
            skip_permissions_update = false;
        }

        let created_at = document.get("$createdAt").cloned();
        let preserve_dates = self.state().preserve_dates;
        let mut merged = array_merge(old.array_copy(&[], &[]), document.array_copy(&[], &[]));
        merged.set(ArrayKey::from("$collection"), old.get("$collection").cloned().unwrap_or(Value::Null));
        merged.set(ArrayKey::from("$sequence"), sequence(&old));
        merged.set(
            ArrayKey::from("$createdAt"),
            match created_at {
                Some(v) if preserve_dates => v,
                _ => old.get("$createdAt").cloned().unwrap_or(Value::Null),
            },
        );
        if adapter.shared_tables() {
            let tenant = old.tenant().unwrap_or(Value::Null);
            merged.set(ArrayKey::from("$tenant"), tenant.clone());
            old.set("$tenant", tenant);
        }
        let document = Document::new(merged)?;
        let mut document = self.remove_unknown_attributes(collection, document, None);

        let relationships = relationship_attributes(collection);
        let mut should_update = false;

        if collection.id() != Self::METADATA {
            let document_security = collection.get("documentSecurity").is_some_and(Value::truthy);
            let by_key: HashMap<String, Document> =
                relationships.iter().map(|r| (to_text(r.get_or("key", &Value::Null)), r.clone())).collect();

            should_update = document.iter().any(|(_, v)| Operator::is_operator(v));

            if !should_update {
                let stack = self.state().relationship_write_stack.len() as i64;
                for (key, value) in document.iter() {
                    let key = match key {
                        KeyRef::Int(i) => i.to_string(),
                        KeyRef::Str(s) => String::from_utf8_lossy(s).into_owned(),
                    };
                    if let Some(relationship) = by_key.get(&key) {
                        if stack >= Self::RELATION_MAX_DEPTH - 1 {
                            continue;
                        }
                        if relationship_changed(relationship, &old, &key, value)? {
                            should_update = true;
                            break;
                        }
                        continue;
                    }
                    let old_value = old.get(&key).cloned().unwrap_or(Value::Null);
                    if !Self::values_equal(value, &old_value) {
                        should_update = true;
                        break;
                    }
                }
            }

            let (action, mut permissions, own) = if should_update {
                (Self::PERMISSION_UPDATE, collection.update(), old.update())
            } else {
                (Self::PERMISSION_READ, collection.read(), old.read())
            };
            if document_security {
                permissions.extend(own);
            }
            if !self.allowed(action, permissions) {
                return Err(self.unauthorized());
            }
        }

        if should_update {
            document.set(
                "$updatedAt",
                match new_updated_at {
                    Some(v) if preserve_dates => v,
                    _ => Value::from(time.as_str()),
                },
            );
        }

        self.check_timestamp(old.get("$updatedAt"))?;

        let mut document = self.encode(collection, document, true).await?;

        if self.state().validate {
            let structure = Structure::with_options(
                collection.clone(),
                adapter.id_attribute_type(),
                self.structure_options(Some(old.clone())),
            );
            if !structure.validate(&mut document)? {
                return Err(Error::structure(structure.description()));
            }
        }

        if !relationships.is_empty() && self.state().resolve_relationships {
            document = self.silent(|| self.update_document_relationships(collection, &old, document), None).await?;
        }

        let document = adapter.casting_before(collection, document)?;
        // PHP carries on with its own document, which an adapter may change
        // in place (Memory applies the operators to it): adapters return
        // that document, not their own result.
        let document = adapter.update_document(collection, id, document, skip_permissions_update).await?;
        let document = adapter.casting_after(collection, document)?;

        self.purge_cached_document(collection.id(), Some(id)).await?;
        if document.id() != id {
            self.purge_cached_document(collection.id(), Some(document.id())).await?;
        }

        let has_operators = document.iter().any(|(_, v)| Operator::is_operator(v));
        if has_operators {
            let refetched = self.refetch_documents(collection, vec![document], &[]).await?;
            return Ok((refetched.into_iter().next().unwrap_or_default(), true));
        }
        Ok((document, false))
    }

    /// Applies `updates` to every document matching `queries`, in batches,
    /// calling `on_next` with each `(updated, old)` pair; returns how many
    /// were updated.
    ///
    /// PHP: `Database::updateDocuments($collection, $updates, $queries = [],
    /// $batchSize = self::INSERT_BATCH_SIZE, $onNext = null, $onError = null)`.
    pub async fn update_documents(
        &self,
        collection: &str,
        updates: Document,
        queries: Vec<Query>,
        batch_size: i64,
        mut on_next: Option<&mut dyn Callback<(Document, Document)>>,
        mut on_error: Option<&mut dyn Callback<Error>>,
    ) -> Result<i64> {
        if updates.is_empty() {
            return Ok(0);
        }
        let adapter = self.inner.adapter.clone();
        let mut batch_size = Self::INSERT_BATCH_SIZE.min(batch_size.max(1));
        let collection = self.silent(|| self.get_collection(collection), None).await?;
        if collection.is_empty() {
            return Err(Error::database("Collection not found"));
        }
        let document_security = collection.get("documentSecurity").is_some_and(Value::truthy);
        let skip_auth = self.allowed(Self::PERMISSION_UPDATE, collection.update());
        if !skip_auth && !document_security && collection.id() != Self::METADATA {
            return Err(self.unauthorized());
        }

        self.validate_queries(&collection, &queries)?;

        let grouped = Query::group_by_type(&queries);
        let mut limit = grouped.limit.as_ref().map(crate::value::to_long).unwrap_or(0);
        let cursor = grouped.cursor.clone().filter(Value::truthy);
        check_cursor_collection(cursor.as_ref(), &collection, "Cursor")?;

        let mut updates = updates;
        updates.remove("$id");
        updates.remove("$tenant");
        let preserve_dates = self.state().preserve_dates;
        match updates.get("$createdAt").cloned() {
            Some(created_at) if preserve_dates => {
                updates.set("$createdAt", created_at);
            }
            _ => {
                updates.remove("$createdAt");
            }
        }
        if adapter.shared_tables() {
            updates.set("$tenant", adapter.tenant());
        }
        let updated_at = match updates.get("$updatedAt").cloned() {
            Some(v) if preserve_dates => v,
            _ => crate::value::string(crate::datetime::now()),
        };
        updates.set("$updatedAt", updated_at);

        let mut updates = self.encode(&collection, updates, false).await?;

        if self.state().validate {
            let validator = crate::validator::partial_structure::PartialStructure::with_options(
                collection.clone(),
                adapter.id_attribute_type(),
                self.structure_options(None),
            );
            if !validator.validate(&mut updates)? {
                return Err(Error::structure(validator.description()));
            }
        }
        // PHP's caller holds the updates it passed, encoded by now.
        if let Some(captured) = self.state().capture.as_mut() {
            captured.push(updates.clone());
        }

        let has_relationships = !relationship_attributes(&collection).is_empty();
        let original_limit = limit;
        let mut last = cursor;
        let mut modified: i64 = 0;

        loop {
            if limit != 0 && limit < batch_size {
                batch_size = limit;
            } else if limit != 0 {
                limit -= batch_size;
            }

            let mut new = vec![Query::limit(batch_size)];
            if let Some(Value::Ext(Ext::Document(last))) = &last {
                new.push(Query::cursor_after((**last).clone()));
            }
            new.extend(queries.iter().cloned());

            let batch = self.silent(|| self.find(collection.id(), new, Self::PERMISSION_UPDATE), None).await?;
            if batch.is_empty() {
                break;
            }

            let old = batch.clone();
            let current_permissions = sorted(updates.permissions());

            let mut batch = self
                .with_transaction(|| {
                    let (collection, updates, batch, adapter) = (&collection, &updates, batch.clone(), &adapter);
                    let current_permissions = &current_permissions;
                    async move {
                        let mut written = Vec::with_capacity(batch.len());
                        for mut document in batch {
                            let mut skip_permissions_update = true;
                            if updates.has("$permissions") {
                                if !document.has("$permissions") {
                                    return Err(Error::query("Permission document missing in select"));
                                }
                                skip_permissions_update = sorted(document.permissions()) == *current_permissions;
                            }
                            document.set("$skipPermissionsUpdate", Value::Bool(skip_permissions_update));
                            let mut new = Document::new(array_merge(
                                document.array_copy(&[], &[]),
                                updates.array_copy(&[], &[]),
                            ))?;
                            if has_relationships && self.state().resolve_relationships {
                                new = self
                                    .silent(|| self.update_document_relationships(collection, &document, new), None)
                                    .await?;
                            }
                            self.check_timestamp(new.get("$updatedAt"))?;
                            let encoded = self.encode(collection, new, true).await?;
                            written.push(adapter.casting_before(collection, encoded)?);
                        }
                        adapter.update_documents(collection, updates, &written).await?;
                        Ok(written)
                    }
                })
                .await?;

            updates = adapter.casting_before(&collection, updates)?;

            let has_operators = updates.iter().any(|(_, v)| Operator::is_operator(v));
            if has_operators {
                batch = self.refetch_documents(&collection, batch, &grouped.selections).await?;
            }

            for (index, doc) in batch.iter_mut().enumerate() {
                let mut d = adapter.casting_after(&collection, std::mem::take(doc))?;
                d.remove("$skipPermissionsUpdate");
                self.purge_cached_document(collection.id(), Some(d.id())).await?;
                if !has_operators {
                    d = self.decode(&collection, d, &[]).await?;
                }
                *doc = d;
                notify(&mut on_next, &mut on_error, (doc.clone(), old[index].clone())).await?;
                modified += 1;
            }

            if (batch.len() as i64) < batch_size || (original_limit != 0 && modified == original_limit) {
                break;
            }
            last = batch.last().cloned().map(Value::from);
        }

        self.trigger(
            Self::EVENT_DOCUMENTS_UPDATE,
            &event_document(vec![("$collection", Value::from(collection.id())), ("modified", Value::Int(modified))]),
        )?;
        Ok(modified)
    }

    /// Creates or updates one document.
    ///
    /// PHP: `Database::upsertDocument()`.
    pub async fn upsert_document(&self, collection: &str, document: Document) -> Result<Document> {
        let id = document.id().to_owned();
        let mut result: Option<Document> = None;
        let mut capture = |(document, _old): (Document, Option<Document>)| -> Result<()> {
            result = Some(document);
            Ok(())
        };
        self.upsert_documents_with_increase(
            collection,
            "",
            vec![document],
            Some(&mut capture),
            None,
            Self::INSERT_BATCH_SIZE,
        )
        .await?;
        match result {
            Some(document) => Ok(document),
            None => self.get_document(collection, &id, Vec::new(), false).await,
        }
    }

    /// Creates or updates documents in batches, calling `on_next` with each
    /// `(document, old)` pair (`old` is `None` for created ones); returns
    /// how many were written.
    ///
    /// PHP: `Database::upsertDocuments($collection, $documents, $batchSize =
    /// self::INSERT_BATCH_SIZE, $onNext = null, $onError = null)`.
    pub async fn upsert_documents(
        &self,
        collection: &str,
        documents: Vec<Document>,
        batch_size: i64,
        on_next: Option<&mut dyn Callback<(Document, Option<Document>)>>,
        on_error: Option<&mut dyn Callback<Error>>,
    ) -> Result<i64> {
        self.upsert_documents_with_increase(collection, "", documents, on_next, on_error, batch_size).await
    }

    /// The stored documents among `documents` (by id, read without
    /// authorization), keyed by their tenant key.
    async fn existing_documents(
        &self,
        collection: &Document,
        documents: &[Document],
    ) -> Result<HashMap<String, Document>> {
        let mut existing = HashMap::new();
        let chunk_size = self.max_query_values().max(1) as usize;
        let max_query_values = self.max_query_values();
        let authorization = self.authorization();
        let mut groups: Vec<(Option<Value>, Vec<String>)> = Vec::new();
        if self.shared_tables() && self.tenant_per_document() {
            let mut by_tenant: Vec<(ArrayKey, Vec<String>)> = Vec::new();
            for document in documents.iter().filter(|d| !d.id().is_empty()) {
                let key = array_key(&document.tenant().unwrap_or(Value::Null));
                match by_tenant.iter_mut().find(|(k, _)| *k == key) {
                    Some((_, ids)) => ids.push(document.id().to_owned()),
                    None => by_tenant.push((key, vec![document.id().to_owned()])),
                }
            }
            for (key, ids) in by_tenant {
                let tenant = match key {
                    ArrayKey::Int(i) => Value::Int(i),
                    other => Value::Str(Str::from(match other.as_ref() {
                        KeyRef::Str(s) => s.to_vec(),
                        KeyRef::Int(i) => i.to_string().into_bytes(),
                    })),
                };
                groups.push((Some(tenant), unique(ids)));
            }
        } else {
            let ids: Vec<String> = documents.iter().map(|d| d.id().to_owned()).filter(|id| !id.is_empty()).collect();
            if !ids.is_empty() {
                groups.push((None, unique(ids)));
            }
        }
        for (tenant, ids) in groups {
            for chunk in ids.chunks(chunk_size) {
                let queries = vec![
                    Query::equal("$id", chunk.iter().map(|id| Value::from(id.as_str()))),
                    Query::limit(max_query_values),
                ];
                let find = || self.silent(|| self.find(collection.id(), queries.clone(), Self::PERMISSION_READ), None);
                let found = match &tenant {
                    Some(tenant) => authorization.skip(|| self.with_tenant(tenant.clone(), find)).await?,
                    None => authorization.skip(find).await?,
                };
                for document in found {
                    existing.insert(self.tenant_key(&document), document);
                }
            }
        }
        Ok(existing)
    }

    /// [`Database::upsert_documents`] where existing documents get
    /// `attribute` increased by the given value instead of replaced (an
    /// empty `attribute` replaces).
    ///
    /// PHP: `Database::upsertDocumentsWithIncrease($collection, $attribute,
    /// $documents, $onNext = null, $onError = null, $batchSize =
    /// self::INSERT_BATCH_SIZE)`.
    pub async fn upsert_documents_with_increase(
        &self,
        collection: &str,
        attribute: &str,
        documents: Vec<Document>,
        mut on_next: Option<&mut dyn Callback<(Document, Option<Document>)>>,
        mut on_error: Option<&mut dyn Callback<Error>>,
        batch_size: i64,
    ) -> Result<i64> {
        if documents.is_empty() {
            return Ok(0);
        }
        let adapter = self.inner.adapter.clone();
        let batch_size = Self::INSERT_BATCH_SIZE.min(batch_size.max(1)) as usize;
        let collection = self.silent(|| self.get_collection(collection), None).await?;
        let document_security = collection.get("documentSecurity").is_some_and(Value::truthy);
        let collection_attributes = attribute_documents(&collection);
        let time = crate::datetime::now();
        let mut created: i64 = 0;
        let mut updated: i64 = 0;
        let mut seen_ids: Vec<String> = Vec::new();
        let has_relationships = !relationship_attributes(&collection).is_empty();
        let internal_keys: Vec<String> = Self::internal_attributes_const().iter().map(|a| a.id().to_owned()).collect();
        let is_internal =
            |key: KeyRef<'_>| matches!(key, KeyRef::Str(s) if internal_keys.iter().any(|k| k.as_bytes() == s));

        let existing = self.existing_documents(&collection, &documents).await?;
        let (preserve_dates, preserve_sequence) = {
            let s = self.state();
            (s.preserve_dates, s.preserve_sequence)
        };

        // PHP's caller holds the documents it passed: one skipped as
        // unchanged ends with its unknown attributes removed, the others as
        // the ones handed to `on_next` (before an operator refetch).
        let mut capture = self.state().capture.take();
        let mut ends: Vec<Option<Document>> = Vec::new();
        let mut changed: Vec<usize> = Vec::new();

        let mut changes: Vec<Change> = Vec::new();
        for document in documents {
            let old = existing.get(&self.tenant_key(&document)).cloned().unwrap_or_default();
            let mut document = self.remove_unknown_attributes(&collection, document, None);
            if capture.is_some() {
                ends.push(Some(document.clone()));
            }

            let extracted = Operator::extract_operators(document.array_copy(&[], &[]));
            let user_updates: Array = extracted
                .updates
                .iter()
                .filter(|(k, _)| !is_internal(*k))
                .map(|(k, v)| (k.to_owned(), v.clone()))
                .collect();

            let mut skip_permissions_update = true;
            if document.has("$permissions") {
                skip_permissions_update = sorted(old.permissions()) == sorted(document.permissions());
            }

            let has_changes = if !extracted.operators.is_empty()
                || !(attribute.is_empty() || attribute == "0")
                || !skip_permissions_update
            {
                true
            } else {
                let old_attributes = old.attributes();
                let changed = user_updates.iter().any(|(k, v)| {
                    let old_value = old_attributes.get_ref(k).cloned().unwrap_or(Value::Null);
                    !old_value.loose_eq(v)
                });
                changed || old_attributes.keys().filter(|k| !is_internal(*k)).any(|k| user_updates.get_ref(k).is_none())
            };
            if !has_changes {
                continue;
            }

            if old.is_empty() {
                if !self.allowed(Self::PERMISSION_CREATE, collection.create()) {
                    return Err(self.unauthorized());
                }
            } else {
                let mut permissions = collection.update();
                if document_security {
                    permissions.extend(old.update());
                }
                if !self.allowed(Self::PERMISSION_UPDATE, permissions) {
                    return Err(self.unauthorized());
                }
            }

            let updated_at = document.get("$updatedAt").cloned();
            let id = document.id().to_owned();
            let id = if id.is_empty() || id == "0" { crate::helpers::id::unique()? } else { id };
            document.set("$id", Value::from(id.as_str()));
            document.set("$collection", Value::from(collection.id()));
            document.set(
                "$updatedAt",
                match updated_at {
                    Some(v) if preserve_dates => v,
                    _ => Value::from(time.as_str()),
                },
            );
            if !preserve_sequence {
                document.remove("$sequence");
            }
            match document.get("$createdAt").cloned() {
                Some(created_at) if preserve_dates => {
                    document.set("$createdAt", created_at);
                }
                _ => {
                    let created_at = if old.is_empty() {
                        Value::from(time.as_str())
                    } else {
                        old.get("$createdAt").cloned().unwrap_or(Value::Null)
                    };
                    document.set("$createdAt", created_at);
                }
            }

            for attr in &collection_attributes {
                let attr_id = to_text(attr.raw("$id").unwrap_or(&Value::Null));
                if !attr.get("required").is_some_and(Value::truthy) && !document.has(&attr_id) {
                    let default = attr.get("default").cloned().unwrap_or(Value::Null);
                    let value = old.get(&attr_id).cloned().unwrap_or(default);
                    document.set(attr_id.as_str(), value);
                }
            }

            if skip_permissions_update {
                document.set("$permissions", Value::Array(old.permission_list().unwrap_or_default()));
            }

            if adapter.shared_tables() {
                if adapter.tenant_per_document() {
                    let tenant = document.tenant();
                    let Some(tenant) = tenant else {
                        return Err(Error::database(
                            "Missing tenant. Tenant must be set when tenant per document is enabled.",
                        ));
                    };
                    if !old.is_empty() && !old.tenant().unwrap_or(Value::Null).loose_eq(&tenant) {
                        return Err(Error::database("Tenant cannot be changed."));
                    }
                } else {
                    document.set("$tenant", adapter.tenant());
                }
            }

            let mut document = self.encode(&collection, document, true).await?;

            if self.state().validate {
                let current = if old.is_empty() { None } else { Some(old.clone()) };
                let structure = Structure::with_options(
                    collection.clone(),
                    adapter.id_attribute_type(),
                    self.structure_options(current),
                );
                if !structure.validate(&mut document)? {
                    return Err(Error::structure(structure.description()));
                }
            }

            if !old.is_empty() {
                self.check_timestamp(old.get("$updatedAt"))?;
            }

            if has_relationships && self.state().resolve_relationships {
                document = self.silent(|| self.create_document_relationships(&collection, document), None).await?;
            }

            seen_ids.push(self.tenant_key(&document));
            let old = adapter.casting_before(&collection, old)?;
            let document = adapter.casting_before(&collection, document)?;
            changed.push(ends.len().saturating_sub(1));
            changes.push(Change::new(old, document));
        }

        if seen_ids.len() != unique(seen_ids.clone()).len() {
            return Err(Error::duplicate("Duplicate document IDs found in the input array."));
        }

        let authorization = self.authorization();
        for (at, chunk) in changes.chunks(batch_size).enumerate() {
            let mut batch = self
                .with_transaction(|| {
                    let (collection, chunk, adapter, authorization) =
                        (&collection, chunk.to_vec(), &adapter, &authorization);
                    async move { authorization.skip(|| adapter.upsert_documents(collection, attribute, chunk)).await }
                })
                .await?;

            for (index, doc) in batch.iter_mut().enumerate() {
                let empty = !sequence(doc).truthy();
                let old_sequence = chunk.get(index).map(|c| sequence(&c.old)).unwrap_or(Value::Null);
                if empty && old_sequence.truthy() {
                    doc.set("$sequence", old_sequence);
                }
            }

            if on_next.is_some() || has_relationships {
                batch = adapter.get_sequences(collection.id(), batch).await?;
            }

            for change in chunk {
                if change.old.is_empty() {
                    created += 1;
                } else {
                    updated += 1;
                }
            }

            let (in_batch, resolve, depth) = {
                let s = self.state();
                (s.in_batch_relationship_population, s.resolve_relationships, s.relationship_fetch_depth)
            };
            if !in_batch && resolve && has_relationships {
                batch = self
                    .silent(|| self.populate_documents_relationships(batch, &collection, depth, &NO_SELECTS), None)
                    .await?;
            }

            let has_operators = batch.iter().any(|d| d.iter().any(|(_, v)| Operator::is_operator(v)));
            let refetch = has_operators && on_next.is_some();
            if refetch && capture.is_some() {
                for (index, doc) in batch.iter().enumerate() {
                    if let Some(end) = changed.get(at * batch_size + index).and_then(|i| ends.get_mut(*i)) {
                        *end = Some(doc.clone());
                    }
                }
            }
            if refetch {
                batch = self.refetch_documents(&collection, batch, &[]).await?;
            }

            for (index, doc) in batch.into_iter().enumerate() {
                let mut doc = adapter.casting_after(&collection, doc)?;
                if !has_operators {
                    doc = self.decode(&collection, doc, &[]).await?;
                }
                if capture.is_some()
                    && !refetch
                    && let Some(end) = changed.get(at * batch_size + index).and_then(|i| ends.get_mut(*i))
                {
                    *end = Some(doc.clone());
                }
                self.with_document_tenant(&doc, || self.purge_cached_document(collection.id(), Some(doc.id()))).await?;
                let old = chunk.get(index).map(|c| c.old.clone()).unwrap_or_default();
                let old = if old.is_empty() { None } else { Some(adapter.casting_after(&collection, old)?) };
                notify(&mut on_next, &mut on_error, (doc, old)).await?;
            }
        }

        if let Some(captured) = capture.as_mut() {
            captured.extend(ends.into_iter().flatten());
            self.state().capture = capture;
        }
        self.trigger(
            Self::EVENT_DOCUMENTS_UPSERT,
            &event_document(vec![
                ("$collection", Value::from(collection.id())),
                ("created", Value::Int(created)),
                ("updated", Value::Int(updated)),
            ]),
        )?;
        Ok(created + updated)
    }

    /// Atomically increases a numeric attribute by `value` (an int or a
    /// float), up to `max`; triggers [`Database::EVENT_DOCUMENT_INCREASE`].
    ///
    /// PHP: `Database::increaseDocumentAttribute($collection, $id,
    /// $attribute, $value = 1, $max = null)`.
    pub async fn increase_document_attribute(
        &self,
        collection: &str,
        id: &str,
        attribute: &str,
        value: Value,
        max: Option<Value>,
    ) -> Result<Document> {
        self.step_document_attribute(collection, id, attribute, value, max, true).await
    }

    /// Atomically decreases a numeric attribute by `value` (an int or a
    /// float), down to `min`; triggers [`Database::EVENT_DOCUMENT_DECREASE`].
    ///
    /// PHP: `Database::decreaseDocumentAttribute($collection, $id,
    /// $attribute, $value = 1, $min = null)`.
    pub async fn decrease_document_attribute(
        &self,
        collection: &str,
        id: &str,
        attribute: &str,
        value: Value,
        min: Option<Value>,
    ) -> Result<Document> {
        self.step_document_attribute(collection, id, attribute, value, min, false).await
    }

    /// [`Database::increase_document_attribute`] (`increase`) and
    /// [`Database::decrease_document_attribute`], which PHP writes out twice.
    async fn step_document_attribute(
        &self,
        collection: &str,
        id: &str,
        attribute: &str,
        value: Value,
        bound: Option<Value>,
        increase: bool,
    ) -> Result<Document> {
        if value.cmp_php(&Value::Int(0)) != std::cmp::Ordering::Greater {
            // PHP: \InvalidArgumentException, which has no error kind here.
            return Err(Error::new(Kind::Exception, "Value must be numeric and greater than 0"));
        }
        let adapter = self.inner.adapter.clone();
        let collection = self.silent(|| self.get_collection(collection), None).await?;
        if adapter.support_for_attributes() {
            let attr = attribute_documents(&collection)
                .into_iter()
                .rfind(|a| a.raw("$id").is_some_and(|v| v.strict_eq(&Value::from(attribute))));
            let Some(attr) = attr else {
                return Err(Error::not_found("Attribute not found"));
            };
            let kind = attr.get("type").cloned().unwrap_or(Value::Null);
            let numeric =
                [Self::VAR_INTEGER, Self::VAR_BIGINT, Self::VAR_FLOAT].iter().any(|t| kind.loose_eq(&Value::from(*t)));
            if !numeric || attr.get("array").is_some_and(Value::truthy) {
                return Err(Error::type_error("Attribute must be an integer or float and can not be an array."));
            }
        }

        let step = number(&value);
        let document = self
            .with_transaction(|| {
                let (collection, adapter, bound) = (&collection, &adapter, bound.clone());
                async move {
                    let authorization = self.authorization();
                    let mut document = authorization
                        .skip(|| self.silent(|| self.get_document(collection.id(), id, Vec::new(), true), None))
                        .await?;
                    if document.is_empty() {
                        return Err(Error::not_found("Document not found"));
                    }
                    if collection.id() != Self::METADATA {
                        let mut permissions = collection.update();
                        if collection.get("documentSecurity").is_some_and(Value::truthy) {
                            permissions.extend(document.update());
                        }
                        if !self.allowed(Self::PERMISSION_UPDATE, permissions) {
                            return Err(self.unauthorized());
                        }
                    }
                    let current = number(document.get(attribute).unwrap_or(&Value::Null));
                    let next = if increase {
                        php_std::format::add(current, step)
                    } else {
                        php_std::format::sub(current, step)
                    };
                    if let Some(bound) = &bound {
                        let exceeded = if increase {
                            value_of(next).cmp_php(bound) == std::cmp::Ordering::Greater
                        } else {
                            value_of(next).cmp_php(bound) == std::cmp::Ordering::Less
                        };
                        if exceeded {
                            let which = if increase { "maximum" } else { "minimum" };
                            return Err(Error::limit(format!(
                                "Attribute value exceeds {which} limit: {}",
                                to_text(bound)
                            )));
                        }
                    }

                    let time = crate::datetime::now();
                    let updated_at = match document.get("$updatedAt") {
                        Some(v) if v.truthy() && self.state().preserve_dates => {
                            crate::datetime::format(&parse_datetime(Some(v))?)
                        }
                        _ => time,
                    };
                    let limit = bound.filter(Value::truthy).map(|b| {
                        value_of(if increase {
                            php_std::format::sub(number(&b), step)
                        } else {
                            php_std::format::add(number(&b), step)
                        })
                    });
                    let delta =
                        if increase { value_of(step) } else { value_of(php_std::format::mul(step, Number::Int(-1))) };
                    let (min, max) = if increase { (None, limit.as_ref()) } else { (limit.as_ref(), None) };
                    adapter
                        .increase_document_attribute(collection.id(), id, attribute, &delta, &updated_at, min, max)
                        .await?;
                    document.set(attribute, value_of(next));
                    Ok(document)
                }
            })
            .await?;

        self.purge_cached_document(collection.id(), Some(id)).await?;
        let event = if increase { Self::EVENT_DOCUMENT_INCREASE } else { Self::EVENT_DOCUMENT_DECREASE };
        self.trigger_with(event, || Value::from(document.clone()))?;
        Ok(document)
    }

    /// Deletes a document (applying its relationships' on-delete rules);
    /// triggers [`Database::EVENT_DOCUMENT_DELETE`].
    ///
    /// PHP: `Database::deleteDocument()`.
    ///
    /// Boxed: on-delete rules delete related documents through here.
    pub fn delete_document<'a>(&'a self, collection: &'a str, id: &'a str) -> BoxFuture<'a, Result<bool>> {
        Box::pin(self.remove_document(collection, id))
    }

    /// [`Database::delete_document`].
    async fn remove_document(&self, collection: &str, id: &str) -> Result<bool> {
        let collection = self.silent(|| self.get_collection(collection), None).await?;
        let adapter = self.inner.adapter.clone();
        let has_relationships = !relationship_attributes(&collection).is_empty();

        let (deleted, document, related) = self
            .with_transaction(|| {
                let (collection, adapter) = (&collection, &adapter);
                async move {
                    let authorization = self.authorization();
                    let document = authorization
                        .skip(|| self.silent(|| self.get_document(collection.id(), id, Vec::new(), true), None))
                        .await?;
                    if document.is_empty() {
                        return Ok((false, document, Vec::new()));
                    }
                    if collection.id() != Self::METADATA {
                        let mut permissions = collection.delete();
                        if collection.get("documentSecurity").is_some_and(Value::truthy) {
                            permissions.extend(document.delete());
                        }
                        if !self.allowed(Self::PERMISSION_DELETE, permissions) {
                            return Err(self.unauthorized());
                        }
                    }
                    self.check_timestamp(document.get("$updatedAt"))?;

                    let mut related = Vec::new();
                    if has_relationships && self.state().resolve_relationships {
                        // A delete made while silenced, like a cascade's, has no one to report to.
                        let report = self.state().silent_listeners.is_some();
                        related = self
                            .silent(|| self.delete_document_relationships(collection, &document, report), None)
                            .await?;
                    }

                    let result = adapter.delete_document(collection.id(), id).await?;
                    self.purge_cached_document(collection.id(), Some(id)).await?;
                    Ok((result, document, related))
                }
            })
            .await?;

        if deleted {
            self.purge_cached_document_internal(collection.id(), Some(id)).await?;
            self.trigger(Self::EVENT_DOCUMENT_DELETE, &Value::from(document))?;
            for (_, relation) in related {
                self.trigger(Self::EVENT_DOCUMENT_UPDATE, &Value::from(relation))?;
            }
        }
        Ok(deleted)
    }

    /// Deletes every document matching `queries`, in batches, calling
    /// `on_next` with each `(deleted, old)` pair; returns how many were
    /// deleted.
    ///
    /// PHP: `Database::deleteDocuments($collection, $queries = [],
    /// $batchSize = self::DELETE_BATCH_SIZE, $onNext = null, $onError = null)`.
    pub async fn delete_documents(
        &self,
        collection: &str,
        queries: Vec<Query>,
        batch_size: i64,
        mut on_next: Option<&mut dyn Callback<(Document, Document)>>,
        mut on_error: Option<&mut dyn Callback<Error>>,
    ) -> Result<i64> {
        let adapter = self.inner.adapter.clone();
        if adapter.shared_tables() && !adapter.tenant().truthy() {
            return Err(Error::database("Missing tenant. Tenant must be set when table sharing is enabled."));
        }
        let mut batch_size = Self::DELETE_BATCH_SIZE.min(batch_size.max(1));
        let collection = self.silent(|| self.get_collection(collection), None).await?;
        if collection.is_empty() {
            return Err(Error::database("Collection not found"));
        }
        let document_security = collection.get("documentSecurity").is_some_and(Value::truthy);
        let skip_auth = self.allowed(Self::PERMISSION_DELETE, collection.delete());
        if !skip_auth && !document_security && collection.id() != Self::METADATA {
            return Err(self.unauthorized());
        }

        self.validate_queries(&collection, &queries)?;

        let grouped = Query::group_by_type(&queries);
        let mut limit = grouped.limit.as_ref().map(crate::value::to_long).unwrap_or(0);
        let cursor = grouped.cursor.clone().filter(Value::truthy);
        check_cursor_collection(cursor.as_ref(), &collection, "Cursor")?;

        let has_relationships = !relationship_attributes(&collection).is_empty();
        let original_limit = limit;
        let mut last = cursor;
        let mut modified: i64 = 0;

        loop {
            if limit != 0 && limit < batch_size && limit > 0 {
                batch_size = limit;
            } else if limit != 0 {
                limit -= batch_size;
            }

            let mut new = vec![Query::limit(batch_size)];
            if let Some(Value::Ext(Ext::Document(last))) = &last {
                new.push(Query::cursor_after((**last).clone()));
            }
            new.extend(queries.iter().cloned());

            let batch = self.silent(|| self.find(collection.id(), new, Self::PERMISSION_DELETE), None).await?;
            if batch.is_empty() {
                break;
            }
            let old = batch.clone();

            self.with_transaction(|| {
                let (collection, batch, adapter) = (&collection, &batch, &adapter);
                async move {
                    let mut sequences = Vec::with_capacity(batch.len());
                    let mut permission_ids = Vec::new();
                    for document in batch {
                        sequences.push(sequence(document));
                        if !document.permissions().is_empty() {
                            permission_ids.push(document.id().to_owned());
                        }
                        if has_relationships && self.state().resolve_relationships {
                            self.silent(|| self.delete_document_relationships(collection, document, false), None)
                                .await?;
                        }
                        self.check_timestamp(document.get("$updatedAt"))?;
                    }
                    adapter.delete_documents(collection.id(), &sequences, &permission_ids).await
                }
            })
            .await?;

            for (index, document) in batch.iter().enumerate() {
                self.with_document_tenant(document, || {
                    self.purge_cached_document(collection.id(), Some(document.id()))
                })
                .await?;
                notify(&mut on_next, &mut on_error, (document.clone(), old[index].clone())).await?;
                modified += 1;
            }

            if (batch.len() as i64) < batch_size || (original_limit != 0 && modified >= original_limit) {
                break;
            }
            last = batch.last().cloned().map(Value::from);
        }

        self.trigger(
            Self::EVENT_DOCUMENTS_DELETE,
            &event_document(vec![("$collection", Value::from(collection.id())), ("modified", Value::Int(modified))]),
        )?;
        Ok(modified)
    }

    /// The documents read back (by `$sequence`, keeping `selections`) after
    /// operators computed their values; a document not found stays as is.
    ///
    /// PHP: `Database::refetchDocuments()` (protected).
    pub(crate) async fn refetch_documents(
        &self,
        collection: &Document,
        documents: Vec<Document>,
        selections: &[Query],
    ) -> Result<Vec<Document>> {
        if documents.is_empty() {
            return Ok(documents);
        }
        let mut sequences = Vec::with_capacity(documents.len());
        for document in &documents {
            let sequence = sequence(document);
            if matches!(sequence, Value::Null) {
                return Err(Error::database(format!("Cannot refetch document without a $sequence: {}", document.id())));
            }
            sequences.push(sequence);
        }
        let mut refetched_map: HashMap<ArrayKey, Document> = HashMap::new();
        let authorization = self.authorization();
        for chunk in sequences.chunks(self.max_query_values().max(1) as usize) {
            let mut queries = vec![Query::equal("$sequence", chunk.to_vec()), Query::limit(chunk.len() as i64)];
            queries.extend(selections.iter().cloned());
            let refetched = authorization
                .skip(|| self.silent(|| self.find(collection.id(), queries, Self::PERMISSION_READ), None))
                .await?;
            for document in refetched {
                refetched_map.insert(array_key(&sequence(&document)), document);
            }
        }
        Ok(documents
            .into_iter()
            .zip(sequences)
            .map(|(document, sequence)| refetched_map.get(&array_key(&sequence)).cloned().unwrap_or(document))
            .collect())
    }

    /// Removes from `documents` the attributes `select_queries` do not
    /// select (internal attributes stay).
    ///
    /// PHP: `Database::applySelectFiltersToDocuments()` (private).
    pub(crate) fn apply_select_filters_to_documents(&self, documents: &mut [Document], select_queries: &[Query]) {
        if select_queries.is_empty() || documents.is_empty() {
            return;
        }
        let mut keep: Vec<ArrayKey> = Vec::new();
        for query in select_queries {
            for value in query.values() {
                keep.push(array_key(value));
            }
        }
        if keep.contains(&ArrayKey::from("*")) {
            return;
        }
        for attribute in self.internal_attributes() {
            keep.push(ArrayKey::from(attribute.id()));
        }
        for document in documents {
            let keys: Vec<ArrayKey> = document.keys().map(KeyRef::to_owned).collect();
            for key in keys {
                let internal = matches!(key.as_ref(), KeyRef::Str(s) if s.starts_with(b"$"));
                if !keep.contains(&key) && !internal {
                    document.as_array_mut().remove(&key);
                }
            }
        }
    }

    /// Whether `value` and `old` are identical, comparing `stdClass`
    /// values as arrays, recursively.
    ///
    /// PHP: `Database::valuesEqual()` (private static). Objects of this
    /// library (documents, operators, queries) are identical only to
    /// themselves; the values compared here are never the same object.
    pub(crate) fn values_equal(value: &Value, old: &Value) -> bool {
        match (value, old) {
            (Value::Object(a), Value::Object(b)) | (Value::Array(a), Value::Array(b)) => {
                if a.len() != b.len() || a.keys().zip(b.keys()).any(|(x, y)| x != y) {
                    return false;
                }
                a.iter().all(|(k, v)| Self::values_equal(v, b.get_ref(k).unwrap_or(&Value::Null)))
            }
            (Value::Ext(_), _) | (_, Value::Ext(_)) => false,
            _ => value.strict_eq(old),
        }
    }
}

/// `array_values(array_unique($list))`.
fn unique(list: Vec<String>) -> Vec<String> {
    let mut out: Vec<String> = Vec::with_capacity(list.len());
    for item in list {
        if !out.contains(&item) {
            out.push(item);
        }
    }
    out
}

/// Fails when a cursor document belongs to another collection.
fn check_cursor_collection(cursor: Option<&Value>, collection: &Document, what: &str) -> Result<()> {
    let Some(cursor) = cursor else {
        return Ok(());
    };
    let of = match cursor {
        Value::Ext(Ext::Document(d)) => d.collection().to_owned(),
        other => {
            return Err(Error::new(
                Kind::Error,
                format!("Call to a member function getCollection() on {}", other.debug_type()),
            ));
        }
    };
    if of != collection.id() {
        return Err(Error::database(format!("{what} Document must be from the same Collection.")));
    }
    Ok(())
}

/// Whether the value of relationship `key` of an update differs from the
/// stored one (`updateDocument`'s change detection).
fn relationship_changed(relationship: &Document, old: &Document, key: &str, value: &Value) -> Result<bool> {
    let options = relationship.get("options").cloned().unwrap_or(Value::Null);
    let relation_type = to_text(index(&options, "relationType"));
    let side = to_text(index(&options, "side"));
    let id_of = |v: Option<&Value>| -> Value {
        match v {
            Some(Value::Ext(Ext::Document(d))) => Value::from(d.id()),
            Some(v) => v.clone(),
            None => Value::Null,
        }
    };
    let single_changed = |old_value: Value| -> bool {
        let value_null = matches!(value, Value::Null);
        let old_null = matches!(old_value, Value::Null);
        value_null != old_null
            || (matches!(value, Value::Str(_)) && !value.strict_eq(&old_value))
            || matches!(value, Value::Ext(Ext::Document(d)) if !Value::from(d.id()).strict_eq(&old_value))
    };
    match relation_type.as_str() {
        Database::RELATION_ONE_TO_ONE => Ok(single_changed(id_of(old.get(key)))),
        Database::RELATION_ONE_TO_MANY | Database::RELATION_MANY_TO_ONE | Database::RELATION_MANY_TO_MANY => {
            if (relation_type == Database::RELATION_MANY_TO_ONE && side == Database::RELATION_SIDE_PARENT)
                || (relation_type == Database::RELATION_ONE_TO_MANY && side == Database::RELATION_SIDE_CHILD)
            {
                return Ok(single_changed(id_of(old.get(key))));
            }
            if Operator::is_operator(value) {
                return Ok(true);
            }
            let Value::Array(list) = value else {
                return Err(Error::relationship(format!(
                    "Invalid relationship value. Must be either an array of documents or document IDs, {} given.",
                    value.type_name()
                )));
            };
            if !list.is_list() {
                return Err(Error::relationship(
                    "Invalid relationship value. Must be either an array of documents or document IDs, array given.",
                ));
            }
            let old_list = match old.get(key) {
                Some(Value::Array(a)) => a.clone(),
                _ => Array::new(),
            };
            if old_list.len() != list.len() {
                return Ok(true);
            }
            for (index, relation) in list.iter() {
                let old_value = id_of(old_list.get_ref(index));
                let changed = (matches!(relation, Value::Str(_)) && !relation.strict_eq(&old_value))
                    || matches!(relation, Value::Ext(Ext::Document(d)) if !Value::from(d.id()).strict_eq(&old_value));
                if changed {
                    return Ok(true);
                }
            }
            Ok(false)
        }
        _ => Ok(false),
    }
}
