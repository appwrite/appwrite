//! [`Mirror`]: a database whose writes are copied to a second one.
//!
//! A mirror reads from and writes to its source; every write that succeeds
//! there is repeated on the destination (when it has one), through the
//! [`Filter`]s, and a failure on the destination is only reported to the
//! [`Mirror::on_error`] callbacks: the source stays the truth. Document
//! writes reach the destination only for collections the source's
//! `upgrades` collection marks `upgraded`, which a collection created
//! through the mirror is.
//!
//! Everything a mirror does not copy runs on [`Mirror::database`], a
//! [`Database`] of its own over the source's adapter and cache (its own
//! settings, its events the source's), as PHP's `Mirror` extends `Database`.
//!
//! ```no_run
//! # async fn demo(source: utopia_database::Database, destination: utopia_database::Database) -> utopia_database::Result<()> {
//! use std::sync::Arc;
//!
//! use utopia_database::Document;
//! use utopia_database::mirror::Mirror;
//!
//! let mirror = Mirror::new(source, Some(destination), Vec::new());
//! mirror.on_error(Arc::new(|action: &str, error: &utopia_database::Error| {
//!     eprintln!("{action}: {}", error.message());
//!     Ok(())
//! }));
//! mirror.create_collection("people", Vec::new(), Vec::new(), None, true).await?;
//! mirror.create_document("people", Document::default()).await?;
//! # Ok(()) }
//! ```
//!
//! PHP: `Utopia\Database\Mirror`.

use std::future::Future;
use std::sync::{Arc, Mutex, Weak};

use futures_util::future::BoxFuture;
use php_std::datetime::DateTime;

use crate::database::{Callback, Database, Overrides};
use crate::document::Document;
use crate::error::{Error, Kind, Result};
use crate::mirroring::Filter;
use crate::query::Query;
use crate::validator::authorization::Authorization;
use crate::value::{Array, ArrayKey, Value, to_text};

/// A callback [`Mirror::on_error`] registers: the action (`createDocument`,
/// ...) and the error the destination raised. An error it returns leaves
/// the mirror's call.
pub type ErrorCallback = Arc<dyn Fn(&str, &Error) -> Result<()> + Send + Sync>;

/// Collections only the source keeps (`SOURCE_ONLY_COLLECTIONS`).
const SOURCE_ONLY_COLLECTIONS: [&str; 1] = ["upgrades"];

/// A database whose writes are copied to a destination. See the [module
/// docs](self).
///
/// PHP: `Utopia\Database\Mirror`.
#[derive(Clone)]
pub struct Mirror {
    inner: Arc<Shared>,
}

struct Shared {
    /// The `Database` PHP's `Mirror` is: over the source's adapter and
    /// cache, with settings of its own.
    base: Database,
    source: Database,
    destination: Option<Database>,
    filters: Vec<Arc<dyn Filter>>,
    callbacks: Mutex<Vec<ErrorCallback>>,
}

/// Whether the source's `upgrades` row marks a collection upgraded.
fn upgraded(upgrade: &Option<Document>) -> bool {
    matches!(upgrade.as_ref().and_then(|u| u.get("status")), Some(Value::Str(s)) if s.as_bytes() == b"upgraded")
}

/// An attribute document's value as a `?string` parameter.
fn opt_string(document: &Document, key: &str) -> Option<String> {
    match document.get(key) {
        None | Some(Value::Null) => None,
        Some(v) => Some(to_text(v)),
    }
}

/// An attribute document's value as a `?int` parameter.
fn opt_int(document: &Document, key: &str) -> Option<i64> {
    match document.get(key) {
        None | Some(Value::Null) => None,
        Some(v) => Some(int_of(v)),
    }
}

/// `(int) $value`.
fn int_of(value: &Value) -> i64 {
    match value {
        Value::Null | Value::Bool(false) => 0,
        Value::Bool(true) => 1,
        Value::Int(i) => *i,
        Value::Float(f) => php_std::format::dval_to_lval(*f),
        Value::Str(s) => php_std::format::str_to_int(s.as_bytes()),
        Value::Array(a) => i64::from(!a.is_empty()),
        Value::Object(_) | Value::Ext(_) => 1,
    }
}

/// An attribute document's value as a `?bool` parameter.
fn opt_bool(document: &Document, key: &str) -> Option<bool> {
    match document.get(key) {
        None | Some(Value::Null) => None,
        Some(v) => Some(v.truthy()),
    }
}

/// An attribute document's value as a `?array` parameter.
fn opt_array(document: &Document, key: &str) -> Option<Array> {
    match document.get(key) {
        Some(Value::Array(a)) => Some(a.clone()),
        _ => None,
    }
}

/// An attribute document's value as an `array<string>` parameter.
fn opt_strings(document: &Document, key: &str) -> Option<Vec<String>> {
    opt_array(document, key).map(|a| a.values().map(to_text).collect())
}

/// `new Document([...])` of the pairs.
fn document_of(pairs: Vec<(&str, Value)>) -> Document {
    Document::from_array_unchecked(pairs.into_iter().map(|(k, v)| (ArrayKey::from(k), v)).collect())
}

/// What PHP raises calling a method on the `null` a filter returned.
fn on_null(method: &str) -> Error {
    Error::new(Kind::Error, format!("Call to a member function {method}() on null"))
}

/// PHP's `delegate()`: `$body` (using `$db`, awaited) on the source, then on
/// the destination, whose result wins unless it failed (reported to the
/// callbacks). A macro rather than a function taking an async closure, so
/// the futures stay `Send` for every lifetime.
macro_rules! delegate {
    ($shared:expr, $method:literal, |$db:ident| $body:expr) => {{
        let shared = &$shared;
        let mut result = {
            let $db = &shared.source;
            $body
        }?;
        if let Some($db) = &shared.destination {
            match $body {
                Ok(r) => result = r,
                Err(e) => shared.log_error($method, &e)?,
            }
        }
        Ok::<_, Error>(result)
    }};
}

impl Mirror {
    /// A mirror of `source` onto `destination` (none: a plain database over
    /// the source), with `filters` around every copied write. Its own
    /// [`Database`] takes the source's adapter and cache, and gives the
    /// adapter a fresh authorization, as PHP's constructor does.
    ///
    /// PHP: `new Mirror($source, $destination, $filters)`.
    pub fn new(source: Database, destination: Option<Database>, filters: Vec<Arc<dyn Filter>>) -> Self {
        let base = Database::new(source.adapter().clone(), source.cache(), Vec::new());
        let inner = Arc::new(Shared { base, source, destination, filters, callbacks: Mutex::new(Vec::new()) });
        let overrides: Weak<dyn Overrides> = Arc::downgrade(&inner) as Weak<dyn Overrides>;
        inner.base.set_overrides(overrides);
        Mirror { inner }
    }

    /// The database the mirror is: every method it does not copy runs here.
    pub fn database(&self) -> &Database {
        &self.inner.base
    }

    /// PHP: `getSource()`.
    pub fn source(&self) -> &Database {
        &self.inner.source
    }

    /// PHP: `getDestination()`.
    pub fn destination(&self) -> Option<&Database> {
        self.inner.destination.as_ref()
    }

    /// PHP: `getWriteFilters()`.
    pub fn write_filters(&self) -> &[Arc<dyn Filter>] {
        &self.inner.filters
    }

    /// Adds a callback for destination failures.
    ///
    /// PHP: `onError()`.
    pub fn on_error(&self, callback: ErrorCallback) {
        self.inner.callbacks.lock().unwrap_or_else(|e| e.into_inner()).push(callback);
    }

    // ----- Settings: on both databases (and its own where PHP sets it) -----

    /// PHP: `setDatabase()`.
    pub async fn set_database(&self, name: &str) -> Result<&Self> {
        delegate!(self.inner, "setDatabase", |db| db.set_database(name).map(|_| ()))?;
        Ok(self)
    }

    /// PHP: `setNamespace()`.
    pub async fn set_namespace(&self, namespace: &str) -> Result<&Self> {
        delegate!(self.inner, "setNamespace", |db| db.set_namespace(namespace).map(|_| ()))?;
        Ok(self)
    }

    /// PHP: `setSharedTables()`.
    pub async fn set_shared_tables(&self, shared: bool) -> Result<&Self> {
        delegate!(self.inner, "setSharedTables", |db| {
            db.set_shared_tables(shared);
            Ok::<(), Error>(())
        })?;
        Ok(self)
    }

    /// PHP: `setTenant()`.
    pub async fn set_tenant(&self, tenant: Value) -> Result<&Self> {
        delegate!(self.inner, "setTenant", |db| {
            db.set_tenant(tenant.clone());
            Ok::<(), Error>(())
        })?;
        Ok(self)
    }

    /// PHP: `setDropUnknownAttributes()`.
    pub async fn set_drop_unknown_attributes(&self, drop: bool) -> Result<&Self> {
        delegate!(self.inner, "setDropUnknownAttributes", |db| {
            db.set_drop_unknown_attributes(drop);
            Ok::<(), Error>(())
        })?;
        self.inner.base.set_drop_unknown_attributes(drop);
        Ok(self)
    }

    /// PHP: `setPreserveDates()`.
    pub async fn set_preserve_dates(&self, preserve: bool) -> Result<&Self> {
        delegate!(self.inner, "setPreserveDates", |db| {
            db.set_preserve_dates(preserve);
            Ok::<(), Error>(())
        })?;
        self.inner.base.set_preserve_dates(preserve);
        Ok(self)
    }

    /// PHP: `setPreserveSequence()`.
    pub async fn set_preserve_sequence(&self, preserve: bool) -> Result<&Self> {
        delegate!(self.inner, "setPreserveSequence", |db| {
            db.set_preserve_sequence(preserve);
            Ok::<(), Error>(())
        })?;
        self.inner.base.set_preserve_sequence(preserve);
        Ok(self)
    }

    /// PHP: `enableValidation()`.
    pub async fn enable_validation(&self) -> Result<&Self> {
        delegate!(self.inner, "enableValidation", |db| {
            db.enable_validation();
            Ok::<(), Error>(())
        })?;
        self.inner.base.enable_validation();
        Ok(self)
    }

    /// PHP: `disableValidation()`.
    pub async fn disable_validation(&self) -> Result<&Self> {
        delegate!(self.inner, "disableValidation", |db| {
            db.disable_validation();
            Ok::<(), Error>(())
        })?;
        self.inner.base.disable_validation();
        Ok(self)
    }

    /// Runs `f` without validation: on both databases and its own, of which
    /// only its own is restored afterwards (PHP's inherited `skipValidation`
    /// calls the mirror's `disableValidation`, then resets its own flag).
    ///
    /// PHP: `skipValidation()` (inherited).
    pub async fn skip_validation<T, F, Fut>(&self, f: F) -> Result<T>
    where
        F: FnOnce() -> Fut,
        Fut: Future<Output = T>,
    {
        let initial = self.inner.base.validating();
        self.disable_validation().await?;
        let result = f().await;
        if initial {
            self.inner.base.enable_validation();
        } else {
            self.inner.base.disable_validation();
        }
        Ok(result)
    }

    /// Registers a listener on the source, whose events the mirror triggers.
    ///
    /// PHP: `on()`.
    pub fn on(&self, event: &str, name: &str, listener: Option<crate::database::Listener>) -> &Self {
        self.inner.source.on(event, name, listener);
        self
    }

    /// Runs `f` with the source's listeners silenced.
    ///
    /// PHP: `silent()`.
    pub async fn silent<T, F, Fut>(&self, f: F, listeners: Option<&[String]>) -> T
    where
        F: FnOnce() -> Fut,
        Fut: Future<Output = T>,
    {
        self.inner.source.silent(f, listeners).await
    }

    /// Runs `f` with the request timestamp on the source, then again (a
    /// second call of `f`) on the destination; the destination's result
    /// unless it failed.
    ///
    /// PHP: `withRequestTimestamp()`.
    pub async fn with_request_timestamp<T, F, Fut>(&self, timestamp: Option<DateTime>, f: F) -> Result<T>
    where
        F: Fn() -> Fut,
        Fut: Future<Output = Result<T>>,
    {
        delegate!(self.inner, "withRequestTimestamp", |db| db.with_request_timestamp(timestamp.clone(), &f).await)
    }

    /// Sets the authorization of its own database, the source and the
    /// destination.
    ///
    /// PHP: `setAuthorization()`.
    pub fn set_authorization(&self, authorization: Arc<Authorization>) -> &Self {
        self.inner.base.set_authorization(authorization.clone());
        self.inner.source.set_authorization(authorization.clone());
        if let Some(destination) = &self.inner.destination {
            destination.set_authorization(authorization);
        }
        self
    }

    /// PHP: `setDocumentType()`: on both databases (where the class must
    /// exist), then on its own without a check.
    pub async fn set_document_type(&self, collection: &str, class: &str) -> Result<&Self> {
        delegate!(self.inner, "setDocumentType", |db| db.set_document_type(collection, class).map(|_| ()))?;
        self.inner.base.set_document_type(collection, class)?;
        Ok(self)
    }

    /// PHP: `clearDocumentType()`.
    pub async fn clear_document_type(&self, collection: &str) -> Result<&Self> {
        delegate!(self.inner, "clearDocumentType", |db| {
            db.clear_document_type(collection);
            Ok::<(), Error>(())
        })?;
        self.inner.base.clear_document_type(collection);
        Ok(self)
    }

    /// PHP: `clearAllDocumentTypes()`.
    pub async fn clear_all_document_types(&self) -> Result<&Self> {
        delegate!(self.inner, "clearAllDocumentTypes", |db| {
            db.clear_all_document_types();
            Ok::<(), Error>(())
        })?;
        self.inner.base.clear_all_document_types();
        Ok(self)
    }

    // ----- Databases -----

    /// PHP: `exists()`.
    pub async fn exists(&self, database: Option<&str>, collection: Option<&str>) -> Result<bool> {
        delegate!(self.inner, "exists", |db| db.exists(database, collection).await)
    }

    /// PHP: `create()`.
    pub async fn create(&self, database: Option<&str>) -> Result<bool> {
        delegate!(self.inner, "create", |db| db.create(database).await)
    }

    /// PHP: `delete()`.
    pub async fn delete(&self, database: Option<&str>) -> Result<bool> {
        delegate!(self.inner, "delete", |db| db.delete(database).await)
    }

    // ----- Collections -----

    /// Creates the collection on the source, then on the destination (after
    /// the filters), and marks it upgraded in the source's `upgrades`.
    ///
    /// PHP: `createCollection()`.
    pub async fn create_collection(
        &self,
        id: &str,
        attributes: Vec<Document>,
        indexes: Vec<Document>,
        permissions: Option<Vec<String>>,
        document_security: bool,
    ) -> Result<Document> {
        let s = &self.inner;
        let mut result = Some(
            s.source
                .create_collection(id, attributes.clone(), indexes.clone(), permissions.clone(), document_security)
                .await?,
        );
        let Some(destination) = &s.destination else {
            return result.ok_or_else(|| on_null("createCollection"));
        };
        let attempt = async {
            for filter in &s.filters {
                result = filter.before_create_collection(&s.source, destination, id, result.take()).await?;
            }
            destination.create_collection(id, attributes, indexes, permissions, document_security).await?;
            s.source
                .silent(
                    || async {
                        self.create_upgrades().await?;
                        let upgrade = document_of(vec![
                            ("$id", Value::from(id)),
                            ("collectionId", Value::from(id)),
                            ("status", Value::from("upgraded")),
                        ]);
                        s.source.create_document("upgrades", upgrade).await.map(|_| ())
                    },
                    None,
                )
                .await
        }
        .await;
        if let Err(e) = attempt {
            s.log_error("createCollection", &e)?;
        }
        result.ok_or_else(|| {
            Error::new(
                Kind::TypeError,
                "Utopia\\Database\\Mirror::createCollection(): Return value must be of type Utopia\\Database\\Document, null returned",
            )
        })
    }

    /// PHP: `updateCollection()`.
    pub async fn update_collection(
        &self,
        id: &str,
        permissions: Vec<String>,
        document_security: bool,
    ) -> Result<Document> {
        let s = &self.inner;
        let mut result = Some(s.source.update_collection(id, permissions.clone(), document_security).await?);
        let Some(destination) = &s.destination else {
            return result.ok_or_else(|| on_null("updateCollection"));
        };
        let attempt = async {
            for filter in &s.filters {
                result = filter.before_update_collection(&s.source, destination, id, result.take()).await?;
            }
            destination.update_collection(id, permissions, document_security).await.map(|_| ())
        }
        .await;
        if let Err(e) = attempt {
            s.log_error("updateCollection", &e)?;
        }
        result.ok_or_else(|| {
            Error::new(
                Kind::TypeError,
                "Utopia\\Database\\Mirror::updateCollection(): Return value must be of type Utopia\\Database\\Document, null returned",
            )
        })
    }

    /// PHP: `deleteCollection()`.
    pub async fn delete_collection(&self, id: &str) -> Result<bool> {
        let s = &self.inner;
        let result = s.source.delete_collection(id).await?;
        let Some(destination) = &s.destination else {
            return Ok(result);
        };
        let attempt = async {
            destination.delete_collection(id).await?;
            for filter in &s.filters {
                filter.before_delete_collection(&s.source, destination, id).await?;
            }
            Ok(())
        }
        .await;
        if let Err(e) = attempt {
            s.log_error("deleteCollection", &e)?;
        }
        Ok(result)
    }

    // ----- Attributes -----

    /// PHP: `createAttribute()`. The destination's result is returned when
    /// it succeeds.
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
        let s = &self.inner;
        let mut result = s
            .source
            .create_attribute(
                collection,
                id,
                kind,
                size,
                required,
                default.clone(),
                signed,
                array,
                format,
                format_options.clone(),
                filters.clone(),
            )
            .await?;
        let Some(destination) = &s.destination else {
            return Ok(result);
        };
        let attempt = async {
            let mut document = Some(document_of(vec![
                ("$id", Value::from(id)),
                ("type", Value::from(kind)),
                ("size", Value::Int(size)),
                ("required", Value::Bool(required)),
                ("default", default),
                ("signed", Value::Bool(signed)),
                ("array", Value::Bool(array)),
                ("format", format.map(Value::from).unwrap_or(Value::Null)),
                ("formatOptions", Value::Array(format_options)),
                ("filters", Value::Array(filters.iter().map(|f| Value::from(f.as_str())).collect())),
            ]));
            for filter in &s.filters {
                document =
                    filter.before_create_attribute(&s.source, destination, collection, id, document.take()).await?;
            }
            let document = document.ok_or_else(|| on_null("getId"))?;
            destination
                .create_attribute(
                    collection,
                    document.id(),
                    &opt_string(&document, "type").unwrap_or_default(),
                    opt_int(&document, "size").unwrap_or(0),
                    opt_bool(&document, "required").unwrap_or(false),
                    document.get("default").cloned().unwrap_or(Value::Null),
                    opt_bool(&document, "signed").unwrap_or(false),
                    opt_bool(&document, "array").unwrap_or(false),
                    opt_string(&document, "format").as_deref(),
                    opt_array(&document, "formatOptions").unwrap_or_default(),
                    opt_strings(&document, "filters").unwrap_or_default(),
                )
                .await
        }
        .await;
        match attempt {
            Ok(r) => result = r,
            Err(e) => s.log_error("createAttribute", &e)?,
        }
        Ok(result)
    }

    /// PHP: `createAttributes()`.
    pub async fn create_attributes(&self, collection: &str, attributes: Vec<Array>) -> Result<bool> {
        let s = &self.inner;
        let mut result = s.source.create_attributes(collection, attributes.clone()).await?;
        let Some(destination) = &s.destination else {
            return Ok(result);
        };
        let attempt = async {
            let mut attributes = attributes;
            for attribute in attributes.iter_mut() {
                for filter in &s.filters {
                    let id = attribute.get_str("$id").map(to_text).unwrap_or_default();
                    let document = Document::from_array_unchecked(attribute.clone());
                    let document = filter
                        .before_create_attribute(&s.source, destination, collection, &id, Some(document))
                        .await?
                        .ok_or_else(|| on_null("getArrayCopy"))?;
                    *attribute = document.array_copy(&[], &[]);
                }
            }
            destination.create_attributes(collection, attributes).await
        }
        .await;
        match attempt {
            Ok(r) => result = r,
            Err(e) => s.log_error("createAttributes", &e)?,
        }
        Ok(result)
    }

    /// PHP: `updateAttribute()`. Returns the source's attribute, after the
    /// filters.
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
        let s = &self.inner;
        let mut document = Some(
            s.source
                .update_attribute(
                    collection,
                    id,
                    kind,
                    size,
                    required,
                    default,
                    signed,
                    array,
                    format,
                    format_options,
                    filters,
                    new_key,
                )
                .await?,
        );
        let Some(destination) = &s.destination else {
            return document.ok_or_else(|| on_null("updateAttribute"));
        };
        let attempt = async {
            for filter in &s.filters {
                document =
                    filter.before_update_attribute(&s.source, destination, collection, id, document.take()).await?;
            }
            let attribute = document.clone().ok_or_else(|| on_null("getAttribute"))?;
            destination
                .update_attribute(
                    collection,
                    id,
                    opt_string(&attribute, "type").as_deref(),
                    opt_int(&attribute, "size"),
                    opt_bool(&attribute, "required"),
                    attribute.get("default").cloned().unwrap_or(Value::Null),
                    opt_bool(&attribute, "signed"),
                    opt_bool(&attribute, "array"),
                    opt_string(&attribute, "format").as_deref(),
                    opt_array(&attribute, "formatOptions"),
                    opt_strings(&attribute, "filters"),
                    new_key,
                )
                .await
                .map(|_| ())
        }
        .await;
        if let Err(e) = attempt {
            s.log_error("updateAttribute", &e)?;
        }
        document.ok_or_else(|| {
            Error::new(
                Kind::TypeError,
                "Utopia\\Database\\Mirror::updateAttribute(): Return value must be of type Utopia\\Database\\Document, null returned",
            )
        })
    }

    /// PHP: `deleteAttribute()`.
    pub async fn delete_attribute(&self, collection: &str, id: &str) -> Result<bool> {
        let s = &self.inner;
        let result = s.source.delete_attribute(collection, id).await?;
        let Some(destination) = &s.destination else {
            return Ok(result);
        };
        let attempt = async {
            for filter in &s.filters {
                filter.before_delete_attribute(&s.source, destination, collection, id).await?;
            }
            destination.delete_attribute(collection, id).await.map(|_| ())
        }
        .await;
        if let Err(e) = attempt {
            s.log_error("deleteAttribute", &e)?;
        }
        Ok(result)
    }

    /// PHP: `updateAttributeRequired()`.
    pub async fn update_attribute_required(&self, collection: &str, id: &str, required: bool) -> Result<Document> {
        delegate!(self.inner, "updateAttributeRequired", |db| db
            .update_attribute_required(collection, id, required)
            .await)
    }

    /// PHP: `updateAttributeFormat()`.
    pub async fn update_attribute_format(&self, collection: &str, id: &str, format: &str) -> Result<Document> {
        delegate!(self.inner, "updateAttributeFormat", |db| db.update_attribute_format(collection, id, format).await)
    }

    /// PHP: `updateAttributeFormatOptions()`.
    pub async fn update_attribute_format_options(
        &self,
        collection: &str,
        id: &str,
        format_options: Array,
    ) -> Result<Document> {
        delegate!(self.inner, "updateAttributeFormatOptions", |db| {
            db.update_attribute_format_options(collection, id, format_options.clone()).await
        })
    }

    /// PHP: `updateAttributeFilters()`.
    pub async fn update_attribute_filters(&self, collection: &str, id: &str, filters: Vec<String>) -> Result<Document> {
        delegate!(self.inner, "updateAttributeFilters", |db| db
            .update_attribute_filters(collection, id, filters.clone())
            .await)
    }

    /// PHP: `updateAttributeDefault()`.
    pub async fn update_attribute_default(&self, collection: &str, id: &str, default: Value) -> Result<Document> {
        delegate!(self.inner, "updateAttributeDefault", |db| db
            .update_attribute_default(collection, id, default.clone())
            .await)
    }

    /// PHP: `renameAttribute()`.
    pub async fn rename_attribute(&self, collection: &str, old: &str, new: &str) -> Result<bool> {
        delegate!(self.inner, "renameAttribute", |db| db.rename_attribute(collection, old, new).await)
    }

    // ----- Relationships -----

    /// PHP: `createRelationship()`.
    #[allow(clippy::too_many_arguments)]
    pub async fn create_relationship(
        &self,
        collection: &str,
        related_collection: &str,
        kind: &str,
        two_way: bool,
        id: Option<&str>,
        two_way_key: Option<&str>,
        on_delete: &str,
    ) -> Result<bool> {
        delegate!(self.inner, "createRelationship", |db| {
            db.create_relationship(collection, related_collection, kind, two_way, id, two_way_key, on_delete).await
        })
    }

    /// PHP: `updateRelationship()`.
    pub async fn update_relationship(
        &self,
        collection: &str,
        id: &str,
        new_key: Option<&str>,
        new_two_way_key: Option<&str>,
        two_way: Option<bool>,
        on_delete: Option<&str>,
    ) -> Result<bool> {
        delegate!(self.inner, "updateRelationship", |db| {
            db.update_relationship(collection, id, new_key, new_two_way_key, two_way, on_delete).await
        })
    }

    /// PHP: `deleteRelationship()`.
    pub async fn delete_relationship(&self, collection: &str, id: &str) -> Result<bool> {
        delegate!(self.inner, "deleteRelationship", |db| db.delete_relationship(collection, id).await)
    }

    // ----- Indexes -----

    /// PHP: `createIndex()`. The destination gets the index after the
    /// filters, with a TTL of 0 (PHP reads a `ttl` the index document does
    /// not carry).
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
        let s = &self.inner;
        let mut result = s
            .source
            .create_index(collection, id, kind, attributes.clone(), lengths.clone(), orders.clone(), ttl)
            .await?;
        let Some(destination) = &s.destination else {
            return Ok(result);
        };
        let attempt = async {
            let mut document = Some(document_of(vec![
                ("$id", Value::from(id)),
                ("type", Value::from(kind)),
                ("attributes", Value::Array(attributes.iter().map(|a| Value::from(a.as_str())).collect())),
                ("lengths", Value::Array(lengths.into_iter().collect())),
                ("orders", Value::Array(orders.into_iter().collect())),
            ]));
            for filter in &s.filters {
                document = filter.before_create_index(&s.source, destination, collection, id, document.take()).await?;
            }
            let document = document.ok_or_else(|| on_null("getId"))?;
            let list = |key: &str| match document.get(key) {
                Some(Value::Array(a)) => a.values().cloned().collect::<Vec<_>>(),
                _ => Vec::new(),
            };
            destination
                .create_index(
                    collection,
                    document.id(),
                    &opt_string(&document, "type").unwrap_or_default(),
                    list("attributes").iter().map(to_text).collect(),
                    list("lengths"),
                    list("orders"),
                    opt_int(&document, "ttl").unwrap_or(0),
                )
                .await
        }
        .await;
        match attempt {
            Ok(r) => result = r,
            Err(e) => s.log_error("createIndex", &e)?,
        }
        Ok(result)
    }

    /// PHP: `deleteIndex()`.
    pub async fn delete_index(&self, collection: &str, id: &str) -> Result<bool> {
        let s = &self.inner;
        let result = s.source.delete_index(collection, id).await?;
        let Some(destination) = &s.destination else {
            return Ok(result);
        };
        let attempt = async {
            destination.delete_index(collection, id).await?;
            for filter in &s.filters {
                filter.before_delete_index(&s.source, destination, collection, id).await?;
            }
            Ok(())
        }
        .await;
        if let Err(e) = attempt {
            s.log_error("deleteIndex", &e)?;
        }
        Ok(result)
    }

    /// PHP: `renameIndex()`.
    pub async fn rename_index(&self, collection: &str, old: &str, new: &str) -> Result<bool> {
        delegate!(self.inner, "renameIndex", |db| db.rename_index(collection, old, new).await)
    }

    // ----- Documents -----

    /// PHP: `createDocument()`. Returns the destination's document when it
    /// was written there.
    pub async fn create_document(&self, collection: &str, document: Document) -> Result<Document> {
        self.inner.create_document(collection, document).await
    }

    /// PHP: `createDocuments()`: on the source (skipping duplicates when the
    /// mirror does), then every given document, as the source left it, on
    /// an upgraded destination.
    pub async fn create_documents(
        &self,
        collection: &str,
        documents: Vec<Document>,
        batch_size: i64,
        on_next: Option<&mut dyn Callback<Document>>,
        on_error: Option<&mut dyn Callback<Error>>,
    ) -> Result<i64> {
        let s = &self.inner;
        let skip = s.base.skipping_duplicates();
        s.source.state().capture = Some(Vec::new());
        let modified = if skip {
            s.source
                .skip_duplicates(|| s.source.create_documents(collection, documents, batch_size, on_next, on_error))
                .await
        } else {
            s.source.create_documents(collection, documents, batch_size, on_next, on_error).await
        };
        let documents = s.source.state().capture.take().unwrap_or_default();
        let modified = modified?;
        let Some(destination) = s.copies(collection).await else {
            return Ok(modified);
        };
        let attempt = async {
            let mut clones = Vec::with_capacity(documents.len());
            for document in documents {
                let mut clone = document;
                for filter in &s.filters {
                    clone = filter.before_create_document(&s.source, destination, collection, clone).await?;
                }
                clones.push(clone);
            }
            let write = || {
                destination.with_preserve_dates(|| {
                    destination.create_documents(collection, clones.clone(), batch_size, None, None)
                })
            };
            if skip {
                destination.skip_duplicates(write).await?;
            } else {
                write().await?;
            }
            for clone in &clones {
                for filter in &s.filters {
                    filter.after_create_document(&s.source, destination, collection, clone.clone()).await?;
                }
            }
            Ok(())
        }
        .await;
        if let Err(e) = attempt {
            s.log_error("createDocuments", &e)?;
        }
        Ok(modified)
    }

    /// PHP: `updateDocument()`. Returns the source's document.
    pub async fn update_document(&self, collection: &str, id: &str, document: Document) -> Result<Document> {
        self.inner.update_document(collection, id, document).await
    }

    /// PHP: `updateDocuments()`: on the source, then the updates as the
    /// source left them on an upgraded destination.
    pub async fn update_documents(
        &self,
        collection: &str,
        updates: Document,
        queries: Vec<Query>,
        batch_size: i64,
        on_next: Option<&mut dyn Callback<(Document, Document)>>,
        on_error: Option<&mut dyn Callback<Error>>,
    ) -> Result<i64> {
        let s = &self.inner;
        s.source.state().capture = Some(Vec::new());
        let modified = s
            .source
            .update_documents(collection, updates.clone(), queries.clone(), batch_size, on_next, on_error)
            .await;
        let captured = s.source.state().capture.take().unwrap_or_default();
        let modified = modified?;
        let Some(destination) = s.copies(collection).await else {
            return Ok(modified);
        };
        // The source returns early, leaving them as given, on empty updates.
        let updates = captured.into_iter().next().unwrap_or(updates);
        let attempt = async {
            let mut clone = updates;
            for filter in &s.filters {
                clone = filter.before_update_documents(&s.source, destination, collection, clone, &queries).await?;
            }
            destination
                .with_preserve_dates(|| {
                    destination.update_documents(collection, clone.clone(), queries.clone(), batch_size, None, None)
                })
                .await?;
            for filter in &s.filters {
                filter.after_update_documents(&s.source, destination, collection, clone.clone(), &queries).await?;
            }
            Ok(())
        }
        .await;
        if let Err(e) = attempt {
            s.log_error("updateDocuments", &e)?;
        }
        Ok(modified)
    }

    /// PHP: `upsertDocuments()`.
    pub async fn upsert_documents(
        &self,
        collection: &str,
        documents: Vec<Document>,
        batch_size: i64,
        on_next: Option<&mut dyn Callback<(Document, Option<Document>)>>,
        on_error: Option<&mut dyn Callback<Error>>,
    ) -> Result<i64> {
        self.inner.upsert_documents(collection, documents, batch_size, on_next, on_error).await
    }

    /// PHP: `upsertDocument()` (inherited): through the mirror's
    /// `upsertDocumentsWithIncrease`, which it does not override, on its own
    /// database.
    pub async fn upsert_document(&self, collection: &str, document: Document) -> Result<Document> {
        self.inner.base.upsert_document(collection, document).await
    }

    /// PHP: `deleteDocument()`.
    pub async fn delete_document(&self, collection: &str, id: &str) -> Result<bool> {
        self.inner.delete_document(collection, id).await
    }

    /// PHP: `deleteDocuments()`.
    pub async fn delete_documents(
        &self,
        collection: &str,
        queries: Vec<Query>,
        batch_size: i64,
        on_next: Option<&mut dyn Callback<(Document, Document)>>,
        on_error: Option<&mut dyn Callback<Error>>,
    ) -> Result<i64> {
        let s = &self.inner;
        let modified = s.source.delete_documents(collection, queries.clone(), batch_size, on_next, on_error).await?;
        let Some(destination) = s.copies(collection).await else {
            return Ok(modified);
        };
        let attempt = async {
            for filter in &s.filters {
                filter.before_delete_documents(&s.source, destination, collection, &queries).await?;
            }
            destination.delete_documents(collection, queries.clone(), batch_size, None, None).await?;
            for filter in &s.filters {
                filter.after_delete_documents(&s.source, destination, collection, &queries).await?;
            }
            Ok(())
        }
        .await;
        if let Err(e) = attempt {
            s.log_error("deleteDocuments", &e)?;
        }
        Ok(modified)
    }

    /// PHP: `increaseDocumentAttribute()`.
    pub async fn increase_document_attribute(
        &self,
        collection: &str,
        id: &str,
        attribute: &str,
        value: Value,
        max: Option<Value>,
    ) -> Result<Document> {
        delegate!(self.inner, "increaseDocumentAttribute", |db| {
            db.increase_document_attribute(collection, id, attribute, value.clone(), max.clone()).await
        })
    }

    /// PHP: `decreaseDocumentAttribute()`.
    pub async fn decrease_document_attribute(
        &self,
        collection: &str,
        id: &str,
        attribute: &str,
        value: Value,
        min: Option<Value>,
    ) -> Result<Document> {
        delegate!(self.inner, "decreaseDocumentAttribute", |db| {
            db.decrease_document_attribute(collection, id, attribute, value.clone(), min.clone()).await
        })
    }

    /// Creates the source's `upgrades` collection, unless it exists.
    ///
    /// PHP: `createUpgrades()`.
    pub async fn create_upgrades(&self) -> Result<()> {
        let source = &self.inner.source;
        if !source.get_collection("upgrades").await?.is_empty() {
            return Ok(());
        }
        let attribute = |id: &str, required: bool| {
            document_of(vec![
                ("$id", Value::from(id)),
                ("type", Value::from(Database::VAR_STRING)),
                ("size", Value::Int(Database::LENGTH_KEY)),
                ("required", Value::Bool(required)),
                ("signed", Value::Bool(true)),
                ("array", Value::Bool(false)),
                ("filters", Value::Array(Array::new())),
                ("default", Value::Null),
                ("format", Value::from("")),
            ])
        };
        let index = |id: &str, kind: &str, attribute: &str, orders: Vec<Value>| {
            document_of(vec![
                ("$id", Value::from(id)),
                ("type", Value::from(kind)),
                ("attributes", Value::Array([Value::from(attribute)].into_iter().collect())),
                ("lengths", Value::Array([Value::Int(Database::LENGTH_KEY)].into_iter().collect())),
                ("orders", Value::Array(orders.into_iter().collect())),
            ])
        };
        source
            .create_collection(
                "upgrades",
                vec![attribute("collectionId", true), attribute("status", false)],
                vec![
                    index("_unique_collection", Database::INDEX_UNIQUE, "collectionId", Vec::new()),
                    index("_status_index", Database::INDEX_KEY, "status", vec![Value::from(Database::ORDER_ASC)]),
                ],
                None,
                true,
            )
            .await?;
        Ok(())
    }
}

impl Shared {
    /// Reports a destination failure to the callbacks.
    ///
    /// PHP: `logError()`.
    fn log_error(&self, action: &str, error: &Error) -> Result<()> {
        let callbacks = self.callbacks.lock().unwrap_or_else(|e| e.into_inner()).clone();
        for callback in callbacks {
            callback(action, error)?;
        }
        Ok(())
    }

    /// The source's `upgrades` row of a collection: empty for `upgrades`
    /// and the metadata, `None` when it cannot be read.
    ///
    /// PHP: `getUpgradeStatus()`.
    async fn upgrade_status(&self, collection: &str) -> Option<Document> {
        if collection == "upgrades" || collection == Database::METADATA {
            return Some(Document::default());
        }
        self.source
            .authorization()
            .skip(|| async { self.source.get_document("upgrades", collection, Vec::new(), false).await.ok() })
            .await
    }

    /// The destination a document write on `collection` is copied to: none
    /// for a source-only collection, without a destination, or for a
    /// collection the source does not mark upgraded.
    async fn copies(&self, collection: &str) -> Option<&Database> {
        if SOURCE_ONLY_COLLECTIONS.contains(&collection) {
            return None;
        }
        let destination = self.destination.as_ref()?;
        let upgrade = self.source.silent(|| self.upgrade_status(collection), None).await;
        upgraded(&upgrade).then_some(destination)
    }

    async fn create_document(&self, collection: &str, document: Document) -> Result<Document> {
        let mut document = self.source.create_document(collection, document).await?;
        let Some(destination) = self.copies(collection).await else {
            return Ok(document);
        };
        let attempt = async {
            let mut clone = document.clone();
            for filter in &self.filters {
                clone = filter.before_create_document(&self.source, destination, collection, clone).await?;
            }
            destination.set_preserve_dates(true);
            document = destination.create_document(collection, clone.clone()).await?;
            destination.set_preserve_dates(false);
            for filter in &self.filters {
                filter.after_create_document(&self.source, destination, collection, clone.clone()).await?;
            }
            Ok(())
        }
        .await;
        if let Err(e) = attempt {
            self.log_error("createDocument", &e)?;
        }
        Ok(document)
    }

    async fn update_document(&self, collection: &str, id: &str, document: Document) -> Result<Document> {
        let document = self.source.update_document(collection, id, document).await?;
        let Some(destination) = self.copies(collection).await else {
            return Ok(document);
        };
        let attempt = async {
            let mut clone = document.clone();
            for filter in &self.filters {
                clone = filter.before_update_document(&self.source, destination, collection, clone).await?;
            }
            destination.set_preserve_dates(true);
            destination.update_document(collection, id, clone.clone()).await?;
            destination.set_preserve_dates(false);
            for filter in &self.filters {
                filter.after_update_document(&self.source, destination, collection, clone.clone()).await?;
            }
            Ok(())
        }
        .await;
        if let Err(e) = attempt {
            self.log_error("updateDocument", &e)?;
        }
        Ok(document)
    }

    async fn upsert_documents(
        &self,
        collection: &str,
        documents: Vec<Document>,
        batch_size: i64,
        on_next: Option<&mut dyn Callback<(Document, Option<Document>)>>,
        on_error: Option<&mut dyn Callback<Error>>,
    ) -> Result<i64> {
        self.source.state().capture = Some(Vec::new());
        let modified = self.source.upsert_documents(collection, documents, batch_size, on_next, on_error).await;
        let documents = self.source.state().capture.take().unwrap_or_default();
        let modified = modified?;
        let Some(destination) = self.copies(collection).await else {
            return Ok(modified);
        };
        let attempt = async {
            let mut clones = Vec::with_capacity(documents.len());
            for document in documents {
                let mut clone = document;
                for filter in &self.filters {
                    clone =
                        filter.before_create_or_update_document(&self.source, destination, collection, clone).await?;
                }
                clones.push(clone);
            }
            destination
                .with_preserve_dates(|| {
                    destination.upsert_documents(collection, clones.clone(), batch_size, None, None)
                })
                .await?;
            for clone in &clones {
                for filter in &self.filters {
                    filter
                        .after_create_or_update_document(&self.source, destination, collection, clone.clone())
                        .await?;
                }
            }
            Ok(())
        }
        .await;
        if let Err(e) = attempt {
            self.log_error("upsertDocuments", &e)?;
        }
        Ok(modified)
    }

    async fn delete_document(&self, collection: &str, id: &str) -> Result<bool> {
        let result = self.source.delete_document(collection, id).await?;
        let Some(destination) = self.copies(collection).await else {
            return Ok(result);
        };
        let attempt = async {
            for filter in &self.filters {
                filter.before_delete_document(&self.source, destination, collection, id).await?;
            }
            destination.delete_document(collection, id).await?;
            for filter in &self.filters {
                filter.after_delete_document(&self.source, destination, collection, id).await?;
            }
            Ok(())
        }
        .await;
        if let Err(e) = attempt {
            self.log_error("deleteDocument", &e)?;
        }
        Ok(result)
    }
}

impl Overrides for Shared {
    fn events(&self) -> Database {
        self.source.clone()
    }

    fn create_document<'a>(&'a self, collection: &'a str, document: Document) -> BoxFuture<'a, Result<Document>> {
        Box::pin(Shared::create_document(self, collection, document))
    }

    fn update_document<'a>(
        &'a self,
        collection: &'a str,
        id: &'a str,
        document: Document,
    ) -> BoxFuture<'a, Result<Document>> {
        Box::pin(Shared::update_document(self, collection, id, document))
    }

    fn delete_document<'a>(&'a self, collection: &'a str, id: &'a str) -> BoxFuture<'a, Result<bool>> {
        Box::pin(Shared::delete_document(self, collection, id))
    }
}
