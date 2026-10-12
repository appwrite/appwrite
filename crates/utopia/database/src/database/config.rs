//! Settings: listeners, statement transforms, scoped flags, namespace and
//! database, cache, metadata, authorization, timeouts, validation, tenancy,
//! document types, connection.
//!
//! Area: schema.

use std::collections::HashSet;
use std::future::Future;
use std::sync::Arc;

use php_std::datetime::DateTime;
use utopia_cache::Cache;

use super::{Database, Filter, Finally, Listener};
use crate::adapter::Transform;
use crate::document::Document;
use crate::error::Result;
use crate::validator::authorization::Authorization;
use crate::value::{Array, Value};

impl Database {
    // ----- Events -----

    /// Registers `listener` as `name` for `event` (`*` for every event);
    /// `None` removes it. Re-registering a name keeps its position.
    ///
    /// PHP: `Database::on()`.
    pub fn on(&self, event: &str, name: &str, listener: Option<Listener>) -> &Self {
        let mut s = self.state();
        let slot = match s.listeners.iter().position(|(e, _)| e == event) {
            Some(i) => i,
            None => match listener {
                None => return self,
                Some(_) => {
                    s.listeners.push((event.to_owned(), Vec::new()));
                    s.listeners.len() - 1
                }
            },
        };
        let list = &mut s.listeners[slot].1;
        match listener {
            None => list.retain(|(n, _)| n != name),
            Some(l) => match list.iter_mut().find(|(n, _)| n == name) {
                Some(entry) => entry.1 = l,
                None => list.push((name.to_owned(), l)),
            },
        }
        self
    }

    /// Registers a statement transform on the adapter; `None` removes it.
    ///
    /// PHP: `Database::before()`.
    pub fn before(&self, event: &str, name: &str, transform: Option<Transform>) -> &Self {
        self.inner.adapter.before(event, name, transform);
        self
    }

    /// Runs `f` with the `listeners` named silenced (all of them for
    /// `None`), then restores the previous set.
    ///
    /// PHP: `Database::silent($callback, $listeners)`.
    pub async fn silent<T, F, Fut>(&self, f: F, listeners: Option<&[String]>) -> T
    where
        F: FnOnce() -> Fut,
        Fut: Future<Output = T>,
    {
        let silenced = listeners.map(|l| l.iter().cloned().collect::<HashSet<_>>());
        let previous = std::mem::replace(&mut self.state().silent_listeners, silenced);
        let _restore = Finally::new(|| self.state().silent_listeners = previous);
        f().await
    }

    /// Calls the listeners of `*`, then those of `event`, skipping silenced
    /// ones; the first error a listener returns stops the dispatch and is
    /// returned.
    ///
    /// PHP: `Database::trigger()` (protected).
    pub(crate) fn trigger(&self, event: &str, args: &Value) -> Result<()> {
        let listeners: Vec<Listener> = {
            let s = self.state();
            let Some(silent) = &s.silent_listeners else {
                return Ok(());
            };
            let of = |e: &str| {
                s.listeners
                    .iter()
                    .find(|(name, _)| name == e)
                    .map(|(_, l)| l.as_slice())
                    .unwrap_or(&[])
                    .iter()
                    .filter(|(name, _)| !silent.contains(name))
                    .map(|(_, l)| l.clone())
                    .collect::<Vec<_>>()
            };
            let mut all = of(Self::EVENT_ALL);
            all.extend(of(event));
            all
        };
        for listener in listeners {
            listener(event, args)?;
        }
        Ok(())
    }

    // ----- Scopes -----

    /// Runs `f` without resolving relationships.
    ///
    /// PHP: `Database::skipRelationships()`.
    pub async fn skip_relationships<T, F, Fut>(&self, f: F) -> T
    where
        F: FnOnce() -> Fut,
        Fut: Future<Output = T>,
    {
        let previous = std::mem::replace(&mut self.state().resolve_relationships, false);
        let _restore = Finally::new(|| self.state().resolve_relationships = previous);
        f().await
    }

    /// Runs `f` without checking that related documents exist.
    ///
    /// PHP: `Database::skipRelationshipsExistCheck()`.
    pub async fn skip_relationships_exist_check<T, F, Fut>(&self, f: F) -> T
    where
        F: FnOnce() -> Fut,
        Fut: Future<Output = T>,
    {
        let previous = std::mem::replace(&mut self.state().check_relationships_exist, false);
        let _restore = Finally::new(|| self.state().check_relationships_exist = previous);
        f().await
    }

    /// Runs `f` skipping duplicate documents on create.
    ///
    /// PHP: `Database::skipDuplicates()`.
    pub async fn skip_duplicates<T, F, Fut>(&self, f: F) -> T
    where
        F: FnOnce() -> Fut,
        Fut: Future<Output = T>,
    {
        let previous = std::mem::replace(&mut self.state().skip_duplicates, true);
        let _restore = Finally::new(|| self.state().skip_duplicates = previous);
        f().await
    }

    /// Runs `f` with `timestamp` as the request timestamp (`None` clears
    /// it).
    ///
    /// PHP: `Database::withRequestTimestamp()`.
    pub async fn with_request_timestamp<T, F, Fut>(&self, timestamp: Option<DateTime>, f: F) -> T
    where
        F: FnOnce() -> Fut,
        Fut: Future<Output = T>,
    {
        let previous = std::mem::replace(&mut self.state().timestamp, timestamp);
        let _restore = Finally::new(|| self.state().timestamp = previous);
        f().await
    }

    /// Runs `f` with filters disabled: all of them when `filters` is `None`
    /// or empty, otherwise only those named.
    ///
    /// PHP: `Database::skipFilters()`.
    pub async fn skip_filters<T, F, Fut>(&self, f: F, filters: Option<&[String]>) -> T
    where
        F: FnOnce() -> Fut,
        Fut: Future<Output = T>,
    {
        match filters {
            Some(names) if !names.is_empty() => {
                let disabled: HashSet<String> = names.iter().cloned().collect();
                let (previous, previous_disabled) = {
                    let mut s = self.state();
                    (s.filter, s.disabled_filters.replace(disabled))
                };
                let _restore = Finally::new(|| {
                    let mut s = self.state();
                    s.filter = previous;
                    s.disabled_filters = previous_disabled;
                });
                f().await
            }
            _ => {
                let initial = std::mem::replace(&mut self.state().filter, false);
                let _restore = Finally::new(|| self.state().filter = initial);
                f().await
            }
        }
    }

    /// Runs `f` without validating documents and queries.
    ///
    /// PHP: `Database::skipValidation()`.
    pub async fn skip_validation<T, F, Fut>(&self, f: F) -> T
    where
        F: FnOnce() -> Fut,
        Fut: Future<Output = T>,
    {
        let initial = std::mem::replace(&mut self.state().validate, false);
        let _restore = Finally::new(|| self.state().validate = initial);
        f().await
    }

    /// Runs `f` with `tenant` as the adapter's tenant.
    ///
    /// PHP: `Database::withTenant()`.
    pub async fn with_tenant<T, F, Fut>(&self, tenant: Value, f: F) -> T
    where
        F: FnOnce() -> Fut,
        Fut: Future<Output = T>,
    {
        let previous = self.inner.adapter.tenant();
        self.inner.adapter.set_tenant(tenant);
        let _restore = Finally::new(|| {
            self.inner.adapter.set_tenant(previous);
        });
        f().await
    }

    /// Runs `f` keeping the `$createdAt` / `$updatedAt` documents carry.
    ///
    /// PHP: `Database::withPreserveDates()`.
    pub async fn with_preserve_dates<T, F, Fut>(&self, f: F) -> T
    where
        F: FnOnce() -> Fut,
        Fut: Future<Output = T>,
    {
        let previous = std::mem::replace(&mut self.state().preserve_dates, true);
        let _restore = Finally::new(|| self.state().preserve_dates = previous);
        f().await
    }

    /// Runs `f` keeping the `$sequence` documents carry.
    ///
    /// PHP: `Database::withPreserveSequence()`.
    pub async fn with_preserve_sequence<T, F, Fut>(&self, f: F) -> T
    where
        F: FnOnce() -> Fut,
        Fut: Future<Output = T>,
    {
        let previous = std::mem::replace(&mut self.state().preserve_sequence, true);
        let _restore = Finally::new(|| self.state().preserve_sequence = previous);
        f().await
    }

    // ----- Namespace, database, cache -----

    /// Sets the namespace (table prefix) on the adapter.
    ///
    /// PHP: `Database::setNamespace()`.
    pub fn set_namespace(&self, namespace: &str) -> Result<&Self> {
        self.inner.adapter.set_namespace(namespace)?;
        Ok(self)
    }

    /// The namespace.
    ///
    /// PHP: `Database::getNamespace()`.
    pub fn namespace(&self) -> String {
        self.inner.adapter.namespace()
    }

    /// The type of `$sequence` values (`integer` or `uuid7`).
    ///
    /// PHP: `Database::getIdAttributeType()`.
    pub fn id_attribute_type(&self) -> &'static str {
        self.inner.adapter.id_attribute_type()
    }

    /// Sets the database (schema) name on the adapter.
    ///
    /// PHP: `Database::setDatabase()`.
    pub fn set_database(&self, name: &str) -> Result<&Self> {
        self.inner.adapter.set_database(name)?;
        Ok(self)
    }

    /// The database (schema) name.
    ///
    /// PHP: `Database::getDatabase()`.
    pub fn database(&self) -> String {
        self.inner.adapter.database()
    }

    /// Replaces the cache.
    ///
    /// PHP: `Database::setCache()`.
    pub fn set_cache(&self, cache: Arc<Cache>) -> &Self {
        self.state().cache = cache;
        self
    }

    /// The cache.
    ///
    /// PHP: `Database::getCache()`.
    pub fn cache(&self) -> Arc<Cache> {
        self.state().cache.clone()
    }

    /// Sets the cache name, the first segment of every cache key.
    ///
    /// PHP: `Database::setCacheName()`.
    pub fn set_cache_name(&self, name: &str) -> &Self {
        self.state().cache_name = name.to_owned();
        self
    }

    /// The cache name.
    ///
    /// PHP: `Database::getCacheName()`.
    pub fn cache_name(&self) -> String {
        self.state().cache_name.clone()
    }

    // ----- Metadata, authorization, timeouts -----

    /// Sets a metadata entry the adapter prefixes statements with.
    ///
    /// PHP: `Database::setMetadata()`.
    pub fn set_metadata(&self, key: &str, value: &str) -> &Self {
        self.inner.adapter.set_metadata(key, value);
        self
    }

    /// The metadata entries, in insertion order.
    ///
    /// PHP: `Database::getMetadata()`.
    pub fn metadata(&self) -> Vec<(String, String)> {
        self.inner.adapter.metadata()
    }

    /// Clears the metadata entries.
    ///
    /// PHP: `Database::resetMetadata()`.
    pub fn reset_metadata(&self) {
        self.inner.adapter.reset_metadata();
    }

    /// Replaces the authorization, on the database and the adapter.
    ///
    /// PHP: `Database::setAuthorization()`.
    pub fn set_authorization(&self, authorization: Arc<Authorization>) -> &Self {
        self.inner.adapter.set_authorization(authorization.clone());
        self.state().authorization = authorization;
        self
    }

    /// The authorization.
    ///
    /// PHP: `Database::getAuthorization()`.
    pub fn authorization(&self) -> Arc<Authorization> {
        self.state().authorization.clone()
    }

    /// Sets the statement timeout for `event` (`*` for every statement).
    ///
    /// PHP: `Database::setTimeout()`.
    pub fn set_timeout(&self, milliseconds: i64, event: &str) -> Result<&Self> {
        self.inner.adapter.set_timeout(milliseconds, event)?;
        Ok(self)
    }

    /// Clears the statement timeout for `event`.
    ///
    /// PHP: `Database::clearTimeout()`.
    pub fn clear_timeout(&self, event: &str) {
        self.inner.adapter.clear_timeout(event);
    }

    // ----- Filters and validation flags -----

    /// Enables filters.
    ///
    /// PHP: `Database::enableFilters()`.
    pub fn enable_filters(&self) -> &Self {
        self.state().filter = true;
        self
    }

    /// Disables filters.
    ///
    /// PHP: `Database::disableFilters()`.
    pub fn disable_filters(&self) -> &Self {
        self.state().filter = false;
        self
    }

    /// The filters given to the constructor.
    ///
    /// PHP: `Database::getInstanceFilters()`.
    pub fn instance_filters(&self) -> &[(String, Arc<dyn Filter>)] {
        &self.inner.instance_filters
    }

    /// Enables validation.
    ///
    /// PHP: `Database::enableValidation()`.
    pub fn enable_validation(&self) -> &Self {
        self.state().validate = true;
        self
    }

    /// Disables validation.
    ///
    /// PHP: `Database::disableValidation()`.
    pub fn disable_validation(&self) -> &Self {
        self.state().validate = false;
        self
    }

    // ----- Tenancy -----

    /// Whether collections share tables across tenants.
    ///
    /// PHP: `Database::getSharedTables()`.
    pub fn shared_tables(&self) -> bool {
        self.inner.adapter.shared_tables()
    }

    /// Sets whether collections share tables across tenants.
    ///
    /// PHP: `Database::setSharedTables()`.
    pub fn set_shared_tables(&self, shared: bool) -> &Self {
        self.inner.adapter.set_shared_tables(shared);
        self
    }

    /// Sets the tenant (`int|string|null`).
    ///
    /// PHP: `Database::setTenant()`.
    pub fn set_tenant(&self, tenant: Value) -> &Self {
        self.inner.adapter.set_tenant(tenant);
        self
    }

    /// The tenant.
    ///
    /// PHP: `Database::getTenant()`.
    pub fn tenant(&self) -> Value {
        self.inner.adapter.tenant()
    }

    /// Sets whether each document carries its own tenant.
    ///
    /// PHP: `Database::setTenantPerDocument()`.
    pub fn set_tenant_per_document(&self, enabled: bool) -> &Self {
        self.inner.adapter.set_tenant_per_document(enabled);
        self
    }

    /// Whether each document carries its own tenant.
    ///
    /// PHP: `Database::getTenantPerDocument()`.
    pub fn tenant_per_document(&self) -> bool {
        self.inner.adapter.tenant_per_document()
    }

    /// Enables or disables `ALTER` locks, where the adapter supports them.
    ///
    /// PHP: `Database::enableLocks()`.
    pub fn enable_locks(&self, enabled: bool) -> &Self {
        if self.inner.adapter.support_for_alter_locks() {
            self.inner.adapter.enable_alter_locks(enabled);
        }
        self
    }

    // ----- Document types -----

    /// Makes documents of `collection` instances of the custom class
    /// `class` ([`Document::class`]).
    ///
    /// PHP: `Database::setDocumentType()`. PHP checks the class exists and
    /// extends `Document`; Rust has no classes to check, so any name is
    /// accepted (a deviation).
    pub fn set_document_type(&self, collection: &str, class: &str) -> Result<&Self> {
        let mut s = self.state();
        match s.document_types.iter_mut().find(|(c, _)| c == collection) {
            Some(entry) => entry.1 = class.to_owned(),
            None => s.document_types.push((collection.to_owned(), class.to_owned())),
        }
        drop(s);
        Ok(self)
    }

    /// The custom class of `collection`'s documents.
    ///
    /// PHP: `Database::getDocumentType()`.
    pub fn document_type(&self, collection: &str) -> Option<String> {
        self.state().document_types.iter().find(|(c, _)| c == collection).map(|(_, class)| class.clone())
    }

    /// Removes the custom class of `collection`'s documents.
    ///
    /// PHP: `Database::clearDocumentType()`.
    pub fn clear_document_type(&self, collection: &str) -> &Self {
        self.state().document_types.retain(|(c, _)| c != collection);
        self
    }

    /// Removes every custom document class.
    ///
    /// PHP: `Database::clearAllDocumentTypes()`.
    pub fn clear_all_document_types(&self) -> &Self {
        self.state().document_types.clear();
        self
    }

    /// A document of `collection`'s class from `data`.
    ///
    /// PHP: `Database::createDocumentInstance()` (protected).
    pub(crate) fn create_document_instance(&self, collection: &str, data: Array) -> Result<Document> {
        let document = Document::new(data)?;
        Ok(match self.document_type(collection) {
            Some(class) => document.with_class(class),
            None => document,
        })
    }

    // ----- Write flags -----

    /// Whether unknown attributes are dropped instead of rejected.
    ///
    /// PHP: `Database::getDropUnknownAttributes()`.
    pub fn drop_unknown_attributes(&self) -> bool {
        self.state().drop_unknown_attributes
    }

    /// Sets whether unknown attributes are dropped instead of rejected.
    ///
    /// PHP: `Database::setDropUnknownAttributes()`.
    pub fn set_drop_unknown_attributes(&self, drop: bool) -> &Self {
        self.state().drop_unknown_attributes = drop;
        self
    }

    /// Whether documents keep the dates they carry.
    ///
    /// PHP: `Database::getPreserveDates()`.
    pub fn preserve_dates(&self) -> bool {
        self.state().preserve_dates
    }

    /// Sets whether documents keep the dates they carry.
    ///
    /// PHP: `Database::setPreserveDates()`.
    pub fn set_preserve_dates(&self, preserve: bool) -> &Self {
        self.state().preserve_dates = preserve;
        self
    }

    /// Sets the migration mode.
    ///
    /// PHP: `Database::setMigrating()`.
    pub fn set_migrating(&self, migrating: bool) -> &Self {
        self.state().migrating = migrating;
        self
    }

    /// Whether the migration mode is on.
    ///
    /// PHP: `Database::isMigrating()`.
    pub fn is_migrating(&self) -> bool {
        self.state().migrating
    }

    /// Whether documents keep the `$sequence` they carry.
    ///
    /// PHP: `Database::getPreserveSequence()`.
    pub fn preserve_sequence(&self) -> bool {
        self.state().preserve_sequence
    }

    /// Sets whether documents keep the `$sequence` they carry.
    ///
    /// PHP: `Database::setPreserveSequence()`.
    pub fn set_preserve_sequence(&self, preserve: bool) -> &Self {
        self.state().preserve_sequence = preserve;
        self
    }

    /// Sets the maximum number of values one query may carry.
    ///
    /// PHP: `Database::setMaxQueryValues()`.
    pub fn set_max_query_values(&self, max: i64) -> &Self {
        self.state().max_query_values = max;
        self
    }

    /// The maximum number of values one query may carry.
    ///
    /// PHP: `Database::getMaxQueryValues()`.
    pub fn max_query_values(&self) -> i64 {
        self.state().max_query_values
    }

    // ----- Global collections -----

    /// Adds collections whose metadata is shared by every tenant.
    ///
    /// PHP: `Database::setGlobalCollections()`.
    pub fn set_global_collections(&self, collections: &[String]) -> &Self {
        let mut s = self.state();
        for c in collections {
            if !s.global_collections.contains(c) {
                s.global_collections.push(c.clone());
            }
        }
        drop(s);
        self
    }

    /// The global collections, in insertion order.
    ///
    /// PHP: `Database::getGlobalCollections()`.
    pub fn global_collections(&self) -> Vec<String> {
        self.state().global_collections.clone()
    }

    /// Clears the global collections.
    ///
    /// PHP: `Database::resetGlobalCollections()`.
    pub fn reset_global_collections(&self) {
        self.state().global_collections.clear();
    }

    // ----- Adapter -----

    /// The adapter's reserved keywords.
    ///
    /// PHP: `Database::getKeywords()`.
    pub fn keywords(&self) -> &'static [&'static str] {
        self.inner.adapter.keywords()
    }

    /// The connection's id.
    ///
    /// PHP: `Database::getConnectionId()`.
    pub async fn connection_id(&self) -> Result<String> {
        self.inner.adapter.connection_id().await
    }

    /// Checks the connection.
    ///
    /// PHP: `Database::ping()`.
    pub async fn ping(&self) -> Result<bool> {
        self.inner.adapter.ping().await
    }

    /// Reconnects.
    ///
    /// PHP: `Database::reconnect()`.
    pub async fn reconnect(&self) -> Result<()> {
        self.inner.adapter.reconnect().await
    }
}
