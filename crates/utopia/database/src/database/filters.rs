//! Attribute filters (the global registry and the defaults), document
//! encoding, decoding and casting, and the filter signatures cache keys
//! carry.
//!
//! Area: schema.

// TEMPORARY (phase A skeleton): the `todo!()` bodies leave parameters and
// helpers unused until the schema area is implemented.
#![allow(unused_variables, dead_code)]

use std::collections::HashSet;
use std::sync::{Arc, LazyLock, RwLock};

use async_trait::async_trait;

use super::Database;
use crate::document::Document;
use crate::error::Result;
use crate::value::Value;

/// An attribute filter: transforms an attribute's value on its way to the
/// adapter (`encode`) and back (`decode`). Attributes name their filters in
/// their `filters` list; filters run in that order on encode and in reverse
/// on decode.
///
/// Register one globally with [`Database::add_filter`] or for one database
/// with [`Database::new`]. Filters receive the document and the database
/// (Appwrite's filters run finds through it).
///
/// PHP: the `['encode' => callable, 'decode' => callable]` pairs of
/// `Database::addFilter()` and the constructor's `$filters`.
#[async_trait]
pub trait Filter: Send + Sync {
    /// The value to store for `value`.
    ///
    /// PHP: the `encode` callable, `fn (mixed $value, Document $document, Database $database)`.
    async fn encode(&self, value: Value, document: &Document, database: &Database) -> Result<Value>;

    /// The value to return for the stored `value`.
    ///
    /// PHP: the `decode` callable.
    async fn decode(&self, value: Value, document: &Document, database: &Database) -> Result<Value>;

    /// A string identifying this filter's behaviour, which cache keys
    /// carry so that a changed filter misses entries written with the old
    /// one. Cache entries are shared with PHP, so a filter that ports a PHP
    /// filter returns PHP's signature for it: `"<encode>:<decode>"`, each
    /// part `file:line` of the closure (or `Class::method`, or the function
    /// name).
    ///
    /// PHP: `Database::computeCallableSignature()` of the two callables.
    fn signature(&self) -> String;
}

/// The global filters (PHP's static `$filters`, `$filtersVersion` and
/// `$defaultFiltersRegistered`).
struct Registry {
    /// Name => filter, in registration order (re-registering keeps the
    /// position, as a PHP array assignment does).
    filters: Vec<(String, Arc<dyn Filter>)>,
    /// Bumped by every [`Database::add_filter`].
    version: i64,
    /// Whether the defaults were registered.
    defaults_registered: bool,
}

static REGISTRY: LazyLock<RwLock<Registry>> =
    LazyLock::new(|| RwLock::new(Registry { filters: Vec::new(), version: 0, defaults_registered: false }));

fn registry_read() -> std::sync::RwLockReadGuard<'static, Registry> {
    REGISTRY.read().unwrap_or_else(|e| e.into_inner())
}

fn registry_write() -> std::sync::RwLockWriteGuard<'static, Registry> {
    REGISTRY.write().unwrap_or_else(|e| e.into_inner())
}

impl Database {
    /// Registers `filter` as `name` for every database (replacing one of
    /// the same name). Instance filters given to [`Database::new`] take
    /// precedence.
    ///
    /// PHP: `Database::addFilter($name, $encode, $decode)` (static).
    pub fn add_filter(name: &str, filter: Arc<dyn Filter>) {
        Self::register_default_filters();
        let mut r = registry_write();
        match r.filters.iter_mut().find(|(n, _)| n == name) {
            Some(entry) => entry.1 = filter,
            None => r.filters.push((name.to_owned(), filter)),
        }
        r.version += 1;
    }

    /// Registers the default filters once, before anything else touches
    /// the registry, so an explicit [`Database::add_filter`] always wins.
    ///
    /// PHP: `Database::registerDefaultFilters()` (private static).
    pub(crate) fn register_default_filters() {
        {
            let mut r = registry_write();
            if r.defaults_registered {
                return;
            }
            r.defaults_registered = true;
        }
        Self::add_filter("json", Arc::new(JsonFilter));
        Self::add_filter("datetime", Arc::new(DatetimeFilter));
        Self::add_filter(Self::VAR_POINT, Arc::new(PointFilter));
        Self::add_filter(Self::VAR_LINESTRING, Arc::new(LinestringFilter));
        Self::add_filter(Self::VAR_POLYGON, Arc::new(PolygonFilter));
        Self::add_filter(Self::VAR_VECTOR, Arc::new(VectorFilter));
        Self::add_filter(Self::VAR_OBJECT, Arc::new(ObjectFilter));
    }

    /// The global filter registered as `name`.
    ///
    /// PHP: `self::$filters[$name]`.
    pub(crate) fn global_filter(name: &str) -> Option<Arc<dyn Filter>> {
        registry_read().filters.iter().find(|(n, _)| n == name).map(|(_, f)| f.clone())
    }

    /// The global filters, in registration order.
    ///
    /// PHP: `self::$filters`.
    pub(crate) fn global_filters() -> Vec<(String, Arc<dyn Filter>)> {
        registry_read().filters.clone()
    }

    /// The registry version (bumped by every registration).
    ///
    /// PHP: `self::$filtersVersion`.
    pub(crate) fn filters_version() -> i64 {
        registry_read().version
    }

    /// The filter `name` this database uses: the instance one, else the
    /// global one.
    pub(crate) fn filter_named(&self, name: &str) -> Option<Arc<dyn Filter>> {
        self.inner
            .instance_filters
            .iter()
            .find(|(n, _)| n == name)
            .map(|(_, f)| f.clone())
            .or_else(|| Self::global_filter(name))
    }

    /// `document` without the attributes `collection` does not declare
    /// (internal attributes are kept). `known` is the set of attribute ids
    /// already collected by the caller.
    ///
    /// PHP: `Database::removeUnknownAttributes()` (protected).
    pub(crate) fn remove_unknown_attributes(
        &self,
        collection: &Document,
        document: Document,
        known: Option<&HashSet<String>>,
    ) -> Document {
        todo!("schema")
    }

    /// `document` ready for the adapter: defaults applied (when
    /// `apply_defaults`), each attribute's filters' `encode` run in order,
    /// unknown attributes removed when dropping them is on.
    ///
    /// PHP: `Database::encode()`.
    pub async fn encode(&self, collection: &Document, document: Document, apply_defaults: bool) -> Result<Document> {
        todo!("schema")
    }

    /// `document` as the adapter returned it, decoded: each attribute's
    /// filters' `decode` run in reverse order, keys mapped back from their
    /// internal names, limited to `selections` when not empty.
    ///
    /// PHP: `Database::decode()`.
    pub async fn decode(&self, collection: &Document, document: Document, selections: &[String]) -> Result<Document> {
        todo!("schema")
    }

    /// `document` with attribute values cast to their declared types (for
    /// adapters that return strings).
    ///
    /// PHP: `Database::casting()`.
    pub fn casting(&self, collection: &Document, document: Document) -> Result<Document> {
        todo!("schema")
    }

    /// Runs filter `name`'s `encode` on `value`. A filter error becomes a
    /// database error with the same message.
    ///
    /// PHP: `Database::encodeAttribute()` (protected). Throws
    /// `NotFoundException("Filter: {name} not found")` for unknown filters.
    pub(crate) async fn encode_attribute(&self, name: &str, value: Value, document: &Document) -> Result<Value> {
        todo!("schema")
    }

    /// Runs filter `filter`'s `decode` on `value` (unless filters are off
    /// or this one is disabled).
    ///
    /// PHP: `Database::decodeAttribute()` (protected).
    pub(crate) async fn decode_attribute(
        &self,
        filter: &str,
        value: Value,
        document: &Document,
        attribute: &str,
    ) -> Result<Value> {
        todo!("schema")
    }

    /// The WKT of a spatial value (`point`, `linestring`, `polygon`).
    ///
    /// PHP: `Database::encodeSpatialData()` (protected).
    pub(crate) fn encode_spatial_data(&self, value: &Value, kind: &str) -> Result<String> {
        todo!("schema")
    }

    /// Decodes a stored `object` attribute: `json_decode(.., true)`, except
    /// that empty JSON objects stay `stdClass`.
    ///
    /// PHP: `Database::decodeObject()` (private static).
    pub(crate) fn decode_object(value: &[u8]) -> Value {
        todo!("schema")
    }

    /// Converts decoded `stdClass` values to arrays, recursively, keeping
    /// empty ones as `stdClass`.
    ///
    /// PHP: `Database::toAssociative()` (private static).
    pub(crate) fn to_associative(value: Value) -> Value {
        todo!("schema")
    }

    // ----- Filter signatures -----

    /// The signatures of the filters in effect (none when filters are off,
    /// without the disabled ones), sorted by name.
    ///
    /// PHP: `Database::getActiveFilterSignatures()` (private).
    pub(crate) fn active_filter_signatures(&self) -> Vec<(String, String)> {
        todo!("schema")
    }

    /// Recomputes the filter signatures when the global registry changed.
    ///
    /// PHP: `Database::refreshFilterSignatures()` (private).
    pub(crate) fn refresh_filter_signatures(&self) {
        todo!("schema")
    }

    /// The JSON of the active filter signatures, as document cache keys
    /// carry it (`""` when filters are off).
    ///
    /// PHP: `Database::getFilterSignatureKey()` (private).
    pub(crate) fn filter_signature_key(&self) -> String {
        todo!("schema")
    }
}

// ----- The default filters (PHP: the closures of `registerDefaultFilters()`) -----

/// `json`: arrays, `stdClass` and documents to JSON; JSON back to arrays,
/// with `$id`-carrying objects as documents.
struct JsonFilter;

/// `datetime`: dates to the storage format in the default timezone; back
/// with `DateTime::formatTz`.
struct DatetimeFilter;

/// `point`: coordinates to WKT; back with the adapter's `decodePoint`.
struct PointFilter;

/// `linestring`: coordinates to WKT; back with `decodeLinestring`.
struct LinestringFilter;

/// `polygon`: rings to WKT; back with `decodePolygon`.
struct PolygonFilter;

/// `vector`: lists of numbers to JSON floats; JSON back to a list.
struct VectorFilter;

/// `object`: arrays and `stdClass` to JSON; back with `decodeObject`.
struct ObjectFilter;

macro_rules! default_filter {
    ($($ty:ident),*) => {$(
        #[async_trait]
        impl Filter for $ty {
            async fn encode(&self, value: Value, document: &Document, database: &Database) -> Result<Value> {
                todo!("schema")
            }

            async fn decode(&self, value: Value, document: &Document, database: &Database) -> Result<Value> {
                todo!("schema")
            }

            fn signature(&self) -> String {
                todo!("schema")
            }
        }
    )*};
}

default_filter!(JsonFilter, DatetimeFilter, PointFilter, LinestringFilter, PolygonFilter, VectorFilter, ObjectFilter);
