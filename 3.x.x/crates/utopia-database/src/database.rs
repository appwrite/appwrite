//! PHP `Utopia\Database\Database`.

use std::collections::HashMap;
use std::sync::{Arc, Mutex as StdMutex};

use indexmap::IndexMap;
use md5::{Digest, Md5};
use once_cell::sync::Lazy;
use serde_json::{json, Value};
use utopia_cache::{Cache, LoadResult, SaveResult};
use utopia_validators::Validator;

use crate::adapter::Adapter;
use crate::constants::*;
use crate::datetime::DateTime;
use crate::document::Document;
use crate::error::{DatabaseError, Result};
use crate::helpers::{Id, Permission, Role};
use crate::query::{Query, VECTOR_TYPES};
use crate::validator::authorization::{Authorization, Input};
use crate::validator::queries_doc::{DocumentQueries, DocumentsQueries};
use crate::validator::{Index as IndexValidator, Permissions, Structure};
use crate::value::AttrValue;

type FilterFn = Arc<dyn Fn(&AttrValue) -> AttrValue + Send + Sync>;
type ListenerFn = Arc<dyn Fn(&AttrValue) + Send + Sync>;

struct FilterPair {
    encode: FilterFn,
    decode: FilterFn,
    #[allow(dead_code)]
    signature: String,
}

static FILTERS: Lazy<StdMutex<HashMap<String, FilterPair>>> =
    Lazy::new(|| StdMutex::new(HashMap::new()));

/// PHP `Utopia\Database\Database`.
pub struct Database<A: Adapter> {
    adapter: A,
    cache: Cache,
    cache_name: String,
    instance_filters: HashMap<String, FilterPair>,
    listeners: HashMap<String, HashMap<String, Option<ListenerFn>>>,
    silent: bool,
    filters: bool,
    validate: bool,
    resolve_relationships: bool,
    skip_relationships_exist_check: bool,
    preserve_dates: bool,
    preserve_sequence: bool,
    migrating: bool,
    max_query_values: i64,
    global_collections: HashMap<String, bool>,
    document_types: HashMap<String, String>,
    #[allow(dead_code)]
    relationship_fetch_depth: i64,
    #[allow(dead_code)]
    in_batch_relationship_population: bool,
    request_timestamp: Option<String>,
    skip_duplicates: bool,
}

impl<A: Adapter> std::fmt::Debug for Database<A> {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Database")
            .field("cache_name", &self.cache_name)
            .field("validate", &self.validate)
            .finish_non_exhaustive()
    }
}

impl<A: Adapter> Database<A> {
    /// PHP `__construct(Adapter $adapter, Cache $cache, array $filters = [])`.
    #[must_use]
    pub fn new(mut adapter: A, cache: Cache) -> Self {
        adapter.set_authorization(Authorization::new());
        let db = Self {
            adapter,
            cache,
            cache_name: "default".into(),
            instance_filters: HashMap::new(),
            listeners: HashMap::from([(EVENT_ALL.into(), HashMap::new())]),
            silent: false,
            filters: true,
            validate: true,
            resolve_relationships: true,
            skip_relationships_exist_check: false,
            preserve_dates: false,
            preserve_sequence: false,
            migrating: false,
            max_query_values: 100,
            global_collections: HashMap::new(),
            document_types: HashMap::new(),
            relationship_fetch_depth: RELATION_MAX_DEPTH,
            in_batch_relationship_population: false,
            request_timestamp: None,
            skip_duplicates: false,
        };
        db.register_builtin_filters();
        db
    }

    fn register_builtin_filters(&self) {
        Self::add_filter(
            "json",
            Arc::new(|value: &AttrValue| encode_json(value)),
            Arc::new(|value: &AttrValue| decode_json(value)),
        );
        Self::add_filter(
            "datetime",
            Arc::new(|value: &AttrValue| {
                if value.is_null() {
                    return AttrValue::Null;
                }
                let Some(s) = value.as_str() else {
                    return value.clone();
                };
                crate::datetime::parse_php_datetime(s)
                    .map(DateTime::format)
                    .map_or_else(|| value.clone(), AttrValue::from)
            }),
            Arc::new(|value: &AttrValue| {
                AttrValue::from(
                    DateTime::format_tz(value.as_str())
                        .unwrap_or_else(|| value.as_str().unwrap_or("").to_owned()),
                )
            }),
        );
        Self::add_filter(
            VAR_VECTOR,
            Arc::new(|value: &AttrValue| {
                let Some(arr) = value.as_array() else {
                    return value.clone();
                };
                if arr.keys().any(|k| k.parse::<usize>().is_err()) {
                    return value.clone();
                }
                AttrValue::from(serde_json::to_string(&value.to_json()).unwrap_or_default())
            }),
            Arc::new(|value: &AttrValue| {
                let Some(s) = value.as_str() else {
                    return value.clone();
                };
                serde_json::from_str::<Value>(s)
                    .map_or_else(|_| value.clone(), AttrValue::from_json)
            }),
        );
        Self::add_filter(
            VAR_OBJECT,
            Arc::new(|value: &AttrValue| {
                if matches!(value, AttrValue::Array(_) | AttrValue::Document(_)) {
                    AttrValue::from(serde_json::to_string(&value.to_json()).unwrap_or_default())
                } else {
                    value.clone()
                }
            }),
            Arc::new(|value: &AttrValue| {
                if value.is_null() {
                    return AttrValue::Null;
                }
                let Some(s) = value.as_str() else {
                    return value.clone();
                };
                serde_json::from_str::<Value>(s)
                    .map_or_else(|_| value.clone(), AttrValue::from_json)
            }),
        );
        for spatial in [VAR_POINT, VAR_LINESTRING, VAR_POLYGON] {
            Self::add_filter(
                spatial,
                Arc::new(|value: &AttrValue| value.clone()),
                Arc::new(|value: &AttrValue| value.clone()),
            );
        }
    }

    /// PHP `Database::addFilter`.
    pub fn add_filter(name: impl Into<String>, encode: FilterFn, decode: FilterFn) {
        let name = name.into();
        let signature = format!("{name}:encode:{name}:decode");
        FILTERS.lock().unwrap_or_else(|e| e.into_inner()).insert(
            name,
            FilterPair {
                encode,
                decode,
                signature,
            },
        );
    }

    /// PHP `on`.
    pub fn on(&mut self, event: &str, name: &str, callback: Option<ListenerFn>) -> &mut Self {
        let entry = self.listeners.entry(event.to_owned()).or_default();
        if callback.is_none() {
            entry.remove(name);
        } else {
            entry.insert(name.to_owned(), callback);
        }
        self
    }

    /// PHP `before` - alias of `on`.
    pub fn before(&mut self, event: &str, name: &str, callback: Option<ListenerFn>) -> &mut Self {
        self.on(event, name, callback)
    }

    /// PHP `Authorization::skip` applied to this database.
    pub fn skip_authorization<T, F: FnOnce(&mut Self) -> T>(&mut self, callback: F) -> T {
        let initial = self.adapter.get_authorization().get_status();
        self.adapter.get_authorization_mut().disable();
        let result = callback(self);
        self.adapter.get_authorization_mut().set_status(initial);
        result
    }

    /// PHP `silent`.
    pub fn silent<T, F: FnOnce(&mut Self) -> T>(&mut self, callback: F) -> T {
        let previous = self.silent;
        self.silent = true;
        let result = callback(self);
        self.silent = previous;
        result
    }

    fn trigger(&self, event: &str, payload: AttrValue) {
        if self.silent {
            return;
        }
        for map in [self.listeners.get(event), self.listeners.get(EVENT_ALL)]
            .into_iter()
            .flatten()
        {
            for cb in map.values().flatten() {
                cb(&payload);
            }
        }
    }

    /// PHP `getConnectionId`.
    #[must_use]
    pub fn get_connection_id(&self) -> String {
        self.adapter.get_connection_id()
    }

    /// PHP `skipRelationships`.
    pub fn skip_relationships<T, F: FnOnce(&mut Self) -> T>(&mut self, callback: F) -> T {
        let previous = self.resolve_relationships;
        self.resolve_relationships = false;
        let result = callback(self);
        self.resolve_relationships = previous;
        result
    }

    /// PHP `skipRelationshipsExistCheck`.
    pub fn skip_relationships_exist_check<T, F: FnOnce(&mut Self) -> T>(
        &mut self,
        callback: F,
    ) -> T {
        let previous = self.skip_relationships_exist_check;
        self.skip_relationships_exist_check = true;
        let result = callback(self);
        self.skip_relationships_exist_check = previous;
        result
    }

    /// PHP `skipDuplicates`.
    pub fn skip_duplicates<T, F: FnOnce(&mut Self) -> T>(&mut self, callback: F) -> T {
        let previous = self.skip_duplicates;
        self.skip_duplicates = true;
        self.adapter.set_skip_duplicates(true);
        let result = callback(self);
        self.skip_duplicates = previous;
        self.adapter.set_skip_duplicates(previous);
        result
    }

    /// PHP `withRequestTimestamp`.
    pub fn with_request_timestamp<T, F: FnOnce(&mut Self) -> T>(
        &mut self,
        timestamp: Option<String>,
        callback: F,
    ) -> T {
        let previous = self.request_timestamp.clone();
        self.request_timestamp = timestamp;
        let result = callback(self);
        self.request_timestamp = previous;
        result
    }

    /// PHP `setNamespace`.
    pub fn set_namespace(&mut self, namespace: &str) -> Result<&mut Self> {
        self.adapter.set_namespace(namespace)?;
        Ok(self)
    }
    #[must_use]
    pub fn get_namespace(&self) -> &str {
        self.adapter.get_namespace()
    }
    #[must_use]
    pub fn get_id_attribute_type(&self) -> &'static str {
        self.adapter.get_id_attribute_type()
    }
    pub fn set_database(&mut self, name: &str) -> Result<&mut Self> {
        self.adapter.set_database(name)?;
        Ok(self)
    }
    #[must_use]
    pub fn get_database(&self) -> &str {
        self.adapter.get_database()
    }
    pub fn set_cache(&mut self, cache: Cache) -> &mut Self {
        self.cache = cache;
        self
    }
    #[must_use]
    pub fn get_cache(&self) -> &Cache {
        &self.cache
    }
    pub fn set_cache_name(&mut self, name: impl Into<String>) -> &mut Self {
        self.cache_name = name.into();
        self
    }
    #[must_use]
    pub fn get_cache_name(&self) -> &str {
        &self.cache_name
    }
    pub fn set_metadata(&mut self, key: impl Into<String>, value: AttrValue) -> &mut Self {
        self.adapter.state_mut().metadata.insert(key.into(), value);
        self
    }
    #[must_use]
    pub fn get_metadata(&self) -> &IndexMap<String, AttrValue> {
        &self.adapter.state().metadata
    }
    pub fn set_authorization(&mut self, authorization: Authorization) -> &mut Self {
        self.adapter.set_authorization(authorization);
        self
    }
    #[must_use]
    pub fn get_authorization(&self) -> &Authorization {
        self.adapter.get_authorization()
    }
    pub fn get_authorization_mut(&mut self) -> &mut Authorization {
        self.adapter.get_authorization_mut()
    }
    pub fn reset_metadata(&mut self) {
        self.adapter.state_mut().metadata.clear();
    }
    pub fn set_timeout(&mut self, milliseconds: i64, event: &str) -> &mut Self {
        self.adapter.set_timeout(milliseconds, event);
        self
    }
    pub fn clear_timeout(&mut self, event: &str) {
        self.adapter.set_timeout(0, event);
    }
    pub fn enable_filters(&mut self) -> &mut Self {
        self.filters = true;
        self
    }
    pub fn disable_filters(&mut self) -> &mut Self {
        self.filters = false;
        self
    }
    pub fn skip_filters<T, F: FnOnce(&mut Self) -> T>(&mut self, callback: F) -> T {
        let previous = self.filters;
        self.filters = false;
        let result = callback(self);
        self.filters = previous;
        result
    }
    #[must_use]
    pub fn get_instance_filters(&self) -> Vec<String> {
        self.instance_filters.keys().cloned().collect()
    }
    pub fn enable_validation(&mut self) -> &mut Self {
        self.validate = true;
        self
    }
    pub fn disable_validation(&mut self) -> &mut Self {
        self.validate = false;
        self
    }
    pub fn skip_validation<T, F: FnOnce(&mut Self) -> T>(&mut self, callback: F) -> T {
        let previous = self.validate;
        self.validate = false;
        let result = callback(self);
        self.validate = previous;
        result
    }
    #[must_use]
    pub fn get_shared_tables(&self) -> bool {
        self.adapter.get_shared_tables()
    }
    pub fn set_shared_tables(&mut self, shared: bool) -> &mut Self {
        self.adapter.set_shared_tables(shared);
        self
    }
    pub fn set_tenant(&mut self, tenant: Option<AttrValue>) -> &mut Self {
        self.adapter.set_tenant(tenant);
        self
    }
    #[must_use]
    pub fn get_tenant(&self) -> Option<&AttrValue> {
        self.adapter.get_tenant()
    }
    pub fn with_tenant<T, F: FnOnce(&mut Self) -> T>(
        &mut self,
        tenant: Option<AttrValue>,
        callback: F,
    ) -> T {
        let previous = self.adapter.get_tenant().cloned();
        self.adapter.set_tenant(tenant);
        let result = callback(self);
        self.adapter.set_tenant(previous);
        result
    }
    pub fn set_tenant_per_document(&mut self, enabled: bool) -> &mut Self {
        self.adapter.set_tenant_per_document(enabled);
        self
    }
    #[must_use]
    pub fn get_tenant_per_document(&self) -> bool {
        self.adapter.get_tenant_per_document()
    }
    pub fn enable_locks(&mut self, enabled: bool) -> &mut Self {
        self.adapter.state_mut().alter_locks = enabled;
        self
    }
    pub fn set_document_type(
        &mut self,
        collection: impl Into<String>,
        class_name: impl Into<String>,
    ) -> &mut Self {
        self.document_types
            .insert(collection.into(), class_name.into());
        self
    }
    #[must_use]
    pub fn get_document_type(&self, collection: &str) -> Option<&str> {
        self.document_types.get(collection).map(String::as_str)
    }
    pub fn clear_document_type(&mut self, collection: &str) -> &mut Self {
        self.document_types.remove(collection);
        self
    }
    pub fn clear_all_document_types(&mut self) -> &mut Self {
        self.document_types.clear();
        self
    }
    #[must_use]
    pub fn get_preserve_dates(&self) -> bool {
        self.preserve_dates
    }
    pub fn set_preserve_dates(&mut self, preserve: bool) -> &mut Self {
        self.preserve_dates = preserve;
        self
    }
    pub fn set_migrating(&mut self, migrating: bool) -> &mut Self {
        self.migrating = migrating;
        self
    }
    #[must_use]
    pub fn is_migrating(&self) -> bool {
        self.migrating
    }
    pub fn with_preserve_dates<T, F: FnOnce(&mut Self) -> T>(&mut self, callback: F) -> T {
        let previous = self.preserve_dates;
        self.preserve_dates = true;
        let result = callback(self);
        self.preserve_dates = previous;
        result
    }
    #[must_use]
    pub fn get_preserve_sequence(&self) -> bool {
        self.preserve_sequence
    }
    pub fn set_preserve_sequence(&mut self, preserve: bool) -> &mut Self {
        self.preserve_sequence = preserve;
        self
    }
    pub fn with_preserve_sequence<T, F: FnOnce(&mut Self) -> T>(&mut self, callback: F) -> T {
        let previous = self.preserve_sequence;
        self.preserve_sequence = true;
        let result = callback(self);
        self.preserve_sequence = previous;
        result
    }
    pub fn set_max_query_values(&mut self, max: i64) -> &mut Self {
        self.max_query_values = max;
        self
    }
    #[must_use]
    pub fn get_max_query_values(&self) -> i64 {
        self.max_query_values
    }
    pub fn set_global_collections(&mut self, collections: Vec<String>) -> &mut Self {
        self.global_collections = collections.into_iter().map(|c| (c, true)).collect();
        self
    }
    #[must_use]
    pub fn get_global_collections(&self) -> Vec<String> {
        self.global_collections.keys().cloned().collect()
    }
    pub fn reset_global_collections(&mut self) {
        self.global_collections.clear();
    }
    #[must_use]
    pub fn get_keywords(&self) -> Vec<String> {
        self.adapter.get_keywords()
    }
    pub fn get_adapter(&self) -> &A {
        &self.adapter
    }
    pub fn get_adapter_mut(&mut self) -> &mut A {
        &mut self.adapter
    }
    pub fn with_transaction<T, F: FnOnce(&mut Self) -> Result<T>>(
        &mut self,
        callback: F,
    ) -> Result<T> {
        self.adapter.start_transaction()?;
        match callback(self) {
            Ok(value) => {
                self.adapter.commit_transaction()?;
                Ok(value)
            }
            Err(err) => {
                let _ = self.adapter.rollback_transaction();
                Err(err)
            }
        }
    }
    #[must_use]
    pub fn ping(&mut self) -> bool {
        self.adapter.ping()
    }
    pub fn reconnect(&mut self) -> Result<()> {
        self.adapter.reconnect()
    }

    /// PHP `create`.
    pub fn create(&mut self, database: Option<&str>) -> Result<bool> {
        let name =
            database.map_or_else(|| self.adapter.get_database().to_owned(), ToOwned::to_owned);
        self.adapter.create(&name)?;
        let attributes = metadata_attribute_docs();
        self.silent(|db| db.create_collection(METADATA, attributes, Vec::new(), None, true))?;
        self.trigger(EVENT_DATABASE_CREATE, AttrValue::from(name.as_str()));
        Ok(true)
    }

    pub fn exists(&mut self, database: Option<&str>, collection: Option<&str>) -> Result<bool> {
        let name = match database {
            Some(n) => n.to_owned(),
            None => self.adapter.get_database().to_owned(),
        };
        self.adapter.exists(&name, collection)
    }

    pub fn list(&mut self) -> Result<Vec<String>> {
        let databases = self.adapter.list()?;
        self.trigger(EVENT_DATABASE_LIST, AttrValue::from(databases.clone()));
        Ok(databases)
    }

    pub fn delete(&mut self, database: Option<&str>) -> Result<bool> {
        let name =
            database.map_or_else(|| self.adapter.get_database().to_owned(), ToOwned::to_owned);
        let deleted = self.adapter.delete(&name)?;
        let _ = self.cache.flush();
        Ok(deleted)
    }

    /// PHP `createCollection`.
    #[allow(clippy::too_many_arguments)]
    pub fn create_collection(
        &mut self,
        id: &str,
        mut attributes: Vec<Document>,
        mut indexes: Vec<Document>,
        permissions: Option<Vec<String>>,
        document_security: bool,
    ) -> Result<Document> {
        for attribute in &mut attributes {
            let typ = attribute
                .get_attribute("type")
                .as_str()
                .unwrap_or("")
                .to_owned();
            if ATTRIBUTE_FILTER_TYPES.contains(&typ.as_str()) {
                let mut filters = match attribute.get_attribute("filters") {
                    AttrValue::Array(a) => a
                        .values()
                        .filter_map(|v| v.as_str().map(ToOwned::to_owned))
                        .collect::<Vec<_>>(),
                    AttrValue::String(s) => vec![s.clone()],
                    _ => Vec::new(),
                };
                if !filters.iter().any(|f| f == &typ) {
                    filters.push(typ);
                }
                attribute.set_attribute("filters", AttrValue::from(filters));
            }
        }
        let permissions = permissions.unwrap_or_else(|| vec![Permission::create(&Role::any())]);
        if self.validate {
            let validator = Permissions::default();
            let json = Value::Array(permissions.iter().cloned().map(Value::String).collect());
            if !validator.is_valid(&json) {
                return Err(DatabaseError::database(validator.description()));
            }
        }
        let existing = self.silent(|db| db.get_collection(id))?;
        if !existing.is_empty() && id != METADATA {
            return Err(DatabaseError::duplicate(format!(
                "Collection {id} already exists"
            )));
        }
        if self.validate && self.adapter.get_support_for_ttl_indexes() {
            let ttl = indexes
                .iter()
                .filter(|i| i.get_attribute("type").as_str() == Some(INDEX_TTL))
                .count();
            if ttl > 1 {
                return Err(DatabaseError::index(
                    "There can be only one TTL index in a collection",
                ));
            }
        }
        for index in &mut indexes {
            let lengths = index.get_attribute("lengths").clone();
            let orders = index.get_attribute("orders").clone();
            let _ = (lengths, orders);
        }
        let collection = Document::from_pairs([
            ("$id", AttrValue::from(Id::custom(id))),
            ("$permissions", AttrValue::from(permissions)),
            ("name", AttrValue::from(id)),
            (
                "attributes",
                AttrValue::from(
                    attributes
                        .iter()
                        .cloned()
                        .map(AttrValue::from)
                        .collect::<Vec<_>>(),
                ),
            ),
            (
                "indexes",
                AttrValue::from(
                    indexes
                        .iter()
                        .cloned()
                        .map(AttrValue::from)
                        .collect::<Vec<_>>(),
                ),
            ),
            ("documentSecurity", AttrValue::from(document_security)),
        ])?;
        if self.validate {
            let validator = IndexValidator::new(
                attributes.clone(),
                Vec::new(),
                self.adapter.get_max_index_length(),
                self.adapter.get_internal_indexes_keys(),
                self.adapter.get_support_for_index_array(),
                self.adapter.get_support_for_spatial_index_null(),
                self.adapter.get_support_for_spatial_index_order(),
                self.adapter.get_support_for_vectors(),
                self.adapter.get_support_for_attributes(),
                self.adapter.get_support_for_multiple_fulltext_indexes(),
                self.adapter.get_support_for_identical_indexes(),
                self.adapter.get_support_for_object_indexes(),
                self.adapter.get_support_for_trigram_index(),
                self.adapter.get_support_for_spatial_attributes(),
                self.adapter.get_support_for_index(),
                self.adapter.get_support_for_unique_index(),
                self.adapter.get_support_for_fulltext_index(),
                self.adapter.get_support_for_ttl_indexes(),
                self.adapter.get_support_for_object(),
            );
            for index in &indexes {
                if !validator.is_valid_document(index) {
                    return Err(DatabaseError::index(validator.description()));
                }
            }
        }
        if !indexes.is_empty()
            && self.adapter.get_count_of_indexes(&collection) > self.adapter.get_limit_for_indexes()
        {
            return Err(DatabaseError::limit(format!(
                "Index limit of {} exceeded. Cannot create collection.",
                self.adapter.get_limit_for_indexes()
            )));
        }
        if !attributes.is_empty() {
            if self.adapter.get_limit_for_attributes() > 0
                && self.adapter.get_count_of_attributes(&collection)
                    > self.adapter.get_limit_for_attributes()
            {
                return Err(DatabaseError::limit(format!(
                    "Attribute limit of {} exceeded. Cannot create collection.",
                    self.adapter.get_limit_for_attributes()
                )));
            }
            if self.adapter.get_document_size_limit() > 0
                && self.adapter.get_attribute_width(&collection)
                    > self.adapter.get_document_size_limit()
            {
                return Err(DatabaseError::limit(format!(
                    "Document size limit of {} exceeded. Cannot create collection.",
                    self.adapter.get_document_size_limit()
                )));
            }
        }
        match self.adapter.create_collection(id, &attributes, &indexes) {
            Ok(_) => {}
            Err(DatabaseError::Duplicate(_)) if id == METADATA => {}
            Err(DatabaseError::Duplicate(_)) => {
                let _ = self.adapter.delete_collection(id);
                self.adapter.create_collection(id, &attributes, &indexes)?;
            }
            Err(e) => return Err(e),
        }
        if id == METADATA {
            return collection_metadata_document();
        }
        let created = self.silent(|db| db.create_document(METADATA, collection))?;
        self.trigger(EVENT_COLLECTION_CREATE, AttrValue::from(created.clone()));
        Ok(created)
    }

    pub fn update_collection(
        &mut self,
        id: &str,
        permissions: Vec<String>,
        document_security: bool,
    ) -> Result<Document> {
        if self.validate {
            let validator = Permissions::default();
            let json = Value::Array(permissions.iter().cloned().map(Value::String).collect());
            if !validator.is_valid(&json) {
                return Err(DatabaseError::database(validator.description()));
            }
        }
        let mut collection = self.silent(|db| db.get_collection(id))?;
        if collection.is_empty() {
            return Err(DatabaseError::not_found("Collection not found"));
        }
        collection.set_attribute("$permissions", AttrValue::from(permissions));
        collection.set_attribute("documentSecurity", AttrValue::from(document_security));
        let collection =
            self.silent(|db| db.update_document(METADATA, &collection.get_id(), collection))?;
        self.trigger(EVENT_COLLECTION_UPDATE, AttrValue::from(collection.clone()));
        Ok(collection)
    }

    pub fn get_collection(&mut self, id: &str) -> Result<Document> {
        let collection = self.silent(|db| db.get_document(METADATA, id, &[], false))?;
        self.trigger(EVENT_COLLECTION_READ, AttrValue::from(collection.clone()));
        Ok(collection)
    }

    pub fn list_collections(&mut self, limit: i64, offset: i64) -> Result<Vec<Document>> {
        let result = self.silent(|db| {
            db.find(
                METADATA,
                &[Query::limit(limit), Query::offset(offset)],
                PERMISSION_READ,
            )
        })?;
        Ok(result)
    }

    pub fn get_size_of_collection(&mut self, collection: &str) -> Result<i64> {
        let collection = self.silent(|db| db.get_collection(collection))?;
        if collection.is_empty() {
            return Err(DatabaseError::not_found("Collection not found"));
        }
        self.adapter.get_size_of_collection(&collection.get_id())
    }

    pub fn get_size_of_collection_on_disk(&mut self, collection: &str) -> Result<i64> {
        let collection = self.silent(|db| db.get_collection(collection))?;
        if collection.is_empty() {
            return Err(DatabaseError::not_found("Collection not found"));
        }
        self.adapter
            .get_size_of_collection_on_disk(&collection.get_id())
    }

    pub fn analyze_collection(&mut self, collection: &str) -> Result<bool> {
        self.adapter.analyze_collection(collection)
    }

    pub fn delete_collection(&mut self, id: &str) -> Result<bool> {
        let collection = self.silent(|db| db.get_document(METADATA, id, &[], false))?;
        if collection.is_empty() {
            return Err(DatabaseError::not_found("Collection not found"));
        }
        let _ = self.adapter.delete_collection(id);
        let deleted = if id == METADATA {
            true
        } else {
            self.silent(|db| db.delete_document(METADATA, id))?
        };
        self.purge_cached_collection(id)?;
        Ok(deleted)
    }

    #[allow(clippy::too_many_arguments)]
    pub fn create_attribute(
        &mut self,
        collection: &str,
        id: &str,
        type_: &str,
        size: i64,
        required: bool,
        default: AttrValue,
        signed: bool,
        array: bool,
        format: Option<&str>,
        format_options: IndexMap<String, AttrValue>,
        mut filters: Vec<String>,
    ) -> Result<bool> {
        let mut collection_doc = self.silent(|db| db.get_collection(collection))?;
        if collection_doc.is_empty() {
            return Err(DatabaseError::not_found("Collection not found"));
        }
        if ATTRIBUTE_FILTER_TYPES.contains(&type_) && !filters.iter().any(|f| f == type_) {
            filters.push(type_.to_owned());
        }
        let attribute = Document::from_pairs([
            ("$id", AttrValue::from(Id::custom(id))),
            ("key", AttrValue::from(id)),
            ("type", AttrValue::from(type_)),
            ("size", AttrValue::from(size)),
            ("required", AttrValue::from(required)),
            ("default", default),
            ("signed", AttrValue::from(signed)),
            ("array", AttrValue::from(array)),
            ("format", AttrValue::from(format.unwrap_or(""))),
            ("formatOptions", AttrValue::from(format_options)),
            ("filters", AttrValue::from(filters)),
        ])?;
        self.adapter.create_attribute(
            &collection_doc.get_id(),
            id,
            type_,
            size,
            signed,
            array,
            required,
        )?;
        let mut attrs = attr_docs(collection_doc.get_attribute("attributes"));
        attrs.push(attribute.clone());
        collection_doc.set_attribute(
            "attributes",
            AttrValue::from(attrs.into_iter().map(AttrValue::from).collect::<Vec<_>>()),
        );
        self.silent(|db| db.update_document(METADATA, &collection_doc.get_id(), collection_doc))?;
        let _ = attribute;
        Ok(true)
    }

    pub fn create_attributes(
        &mut self,
        collection: &str,
        attributes: Vec<Document>,
    ) -> Result<bool> {
        for attribute in attributes {
            self.create_attribute(
                collection,
                &attribute.get_id(),
                attribute.get_attribute("type").as_str().unwrap_or(""),
                attribute.get_attribute("size").as_i64().unwrap_or(0),
                attribute
                    .get_attribute("required")
                    .as_bool()
                    .unwrap_or(false),
                attribute.get_attribute("default").clone(),
                attribute.get_attribute("signed").as_bool().unwrap_or(true),
                attribute.get_attribute("array").as_bool().unwrap_or(false),
                attribute.get_attribute("format").as_str(),
                IndexMap::new(),
                match attribute.get_attribute("filters") {
                    AttrValue::Array(a) => a
                        .values()
                        .filter_map(|v| v.as_str().map(ToOwned::to_owned))
                        .collect(),
                    _ => Vec::new(),
                },
            )?;
        }
        Ok(true)
    }

    pub fn update_attribute_required(
        &mut self,
        collection: &str,
        id: &str,
        required: bool,
    ) -> Result<Document> {
        self.patch_attribute(collection, id, |attr| {
            attr.set_attribute("required", AttrValue::from(required));
        })
    }
    pub fn update_attribute_format(
        &mut self,
        collection: &str,
        id: &str,
        format: &str,
    ) -> Result<Document> {
        self.patch_attribute(collection, id, |attr| {
            attr.set_attribute("format", AttrValue::from(format));
        })
    }
    pub fn update_attribute_format_options(
        &mut self,
        collection: &str,
        id: &str,
        format_options: IndexMap<String, AttrValue>,
    ) -> Result<Document> {
        self.patch_attribute(collection, id, |attr| {
            attr.set_attribute("formatOptions", AttrValue::from(format_options));
        })
    }
    pub fn update_attribute_filters(
        &mut self,
        collection: &str,
        id: &str,
        filters: Vec<String>,
    ) -> Result<Document> {
        self.patch_attribute(collection, id, |attr| {
            attr.set_attribute("filters", AttrValue::from(filters));
        })
    }
    pub fn update_attribute_default(
        &mut self,
        collection: &str,
        id: &str,
        default: AttrValue,
    ) -> Result<Document> {
        self.patch_attribute(collection, id, |attr| {
            attr.set_attribute("default", default);
        })
    }

    fn patch_attribute(
        &mut self,
        collection: &str,
        id: &str,
        patch: impl FnOnce(&mut Document),
    ) -> Result<Document> {
        let mut collection_doc = self.silent(|db| db.get_collection(collection))?;
        if collection_doc.is_empty() {
            return Err(DatabaseError::not_found("Collection not found"));
        }
        let mut attrs = attr_docs(collection_doc.get_attribute("attributes"));
        let Some(attr) = attrs.iter_mut().find(|a| a.get_id() == id) else {
            return Err(DatabaseError::not_found("Attribute not found"));
        };
        patch(attr);
        let cloned = attr.clone();
        collection_doc.set_attribute(
            "attributes",
            AttrValue::from(attrs.into_iter().map(AttrValue::from).collect::<Vec<_>>()),
        );
        self.silent(|db| db.update_document(METADATA, &collection_doc.get_id(), collection_doc))?;
        Ok(cloned)
    }

    #[allow(clippy::too_many_arguments)]
    pub fn update_attribute(
        &mut self,
        collection: &str,
        id: &str,
        type_: Option<&str>,
        size: Option<i64>,
        required: Option<bool>,
        default: Option<AttrValue>,
        signed: Option<bool>,
        array: Option<bool>,
        format: Option<&str>,
        format_options: Option<IndexMap<String, AttrValue>>,
        filters: Option<Vec<String>>,
        new_key: Option<&str>,
    ) -> Result<Document> {
        let collection_id = collection.to_owned();
        let attr_id = id.to_owned();
        self.patch_attribute(&collection_id, &attr_id, |attr| {
            if let Some(type_) = type_ {
                attr.set_attribute("type", AttrValue::from(type_));
            }
            if let Some(size) = size {
                attr.set_attribute("size", AttrValue::from(size));
            }
            if let Some(required) = required {
                attr.set_attribute("required", AttrValue::from(required));
            }
            if let Some(default) = default {
                attr.set_attribute("default", default);
            }
            if let Some(signed) = signed {
                attr.set_attribute("signed", AttrValue::from(signed));
            }
            if let Some(array) = array {
                attr.set_attribute("array", AttrValue::from(array));
            }
            if let Some(format) = format {
                attr.set_attribute("format", AttrValue::from(format));
            }
            if let Some(format_options) = format_options {
                attr.set_attribute("formatOptions", AttrValue::from(format_options));
            }
            if let Some(filters) = filters {
                attr.set_attribute("filters", AttrValue::from(filters));
            }
            if let Some(new_key) = new_key {
                attr.set_attribute("$id", AttrValue::from(new_key));
                attr.set_attribute("key", AttrValue::from(new_key));
            }
        })?;
        let type_s = type_.unwrap_or("string");
        self.adapter.update_attribute(
            collection,
            id,
            type_s,
            size.unwrap_or(0),
            signed.unwrap_or(true),
            array.unwrap_or(false),
            new_key,
            required.unwrap_or(false),
        )?;
        self.silent(|db| db.get_collection(collection))
    }

    pub fn check_attribute(
        &mut self,
        collection: &Document,
        _attribute: &Document,
    ) -> Result<bool> {
        if collection.is_empty() {
            return Err(DatabaseError::not_found("Collection not found"));
        }
        Ok(true)
    }

    pub fn delete_attribute(&mut self, collection: &str, id: &str) -> Result<bool> {
        let mut collection_doc = self.silent(|db| db.get_collection(collection))?;
        if collection_doc.is_empty() {
            return Err(DatabaseError::not_found("Collection not found"));
        }
        self.adapter
            .delete_attribute(&collection_doc.get_id(), id)?;
        let attrs: Vec<Document> = attr_docs(collection_doc.get_attribute("attributes"))
            .into_iter()
            .filter(|a| a.get_id() != id)
            .collect();
        collection_doc.set_attribute(
            "attributes",
            AttrValue::from(attrs.into_iter().map(AttrValue::from).collect::<Vec<_>>()),
        );
        self.silent(|db| db.update_document(METADATA, &collection_doc.get_id(), collection_doc))?;
        Ok(true)
    }

    pub fn rename_attribute(&mut self, collection: &str, old: &str, new: &str) -> Result<bool> {
        self.adapter.rename_attribute(collection, old, new)?;
        let _ = self.update_attribute(
            collection,
            old,
            None,
            None,
            None,
            None,
            None,
            None,
            None,
            None,
            None,
            Some(new),
        )?;
        Ok(true)
    }

    #[allow(clippy::too_many_arguments)]
    pub fn create_relationship(
        &mut self,
        collection: &str,
        related_collection: &str,
        type_: &str,
        two_way: bool,
        id: Option<&str>,
        two_way_key: Option<&str>,
        on_delete: &str,
    ) -> Result<bool> {
        let id = id.unwrap_or(related_collection);
        let two_way_key = two_way_key.unwrap_or(collection);
        self.adapter.create_relationship(
            collection,
            related_collection,
            type_,
            two_way,
            id,
            two_way_key,
        )?;
        let mut options = IndexMap::new();
        options.insert(
            "relatedCollection".into(),
            AttrValue::from(related_collection),
        );
        options.insert("relationType".into(), AttrValue::from(type_));
        options.insert("twoWay".into(), AttrValue::from(two_way));
        options.insert("twoWayKey".into(), AttrValue::from(two_way_key));
        options.insert("onDelete".into(), AttrValue::from(on_delete));
        options.insert("side".into(), AttrValue::from(RELATION_SIDE_PARENT));
        self.create_attribute(
            collection,
            id,
            VAR_RELATIONSHIP,
            0,
            false,
            AttrValue::Null,
            true,
            false,
            None,
            options,
            Vec::new(),
        )?;
        Ok(true)
    }

    #[allow(clippy::too_many_arguments)]
    pub fn update_relationship(
        &mut self,
        collection: &str,
        id: &str,
        new_key: Option<&str>,
        new_two_way_key: Option<&str>,
        two_way: Option<bool>,
        on_delete: Option<&str>,
    ) -> Result<bool> {
        let collection_doc = self.silent(|db| db.get_collection(collection))?;
        let related = related_from_attribute(&collection_doc, id).unwrap_or_default();
        self.adapter.update_relationship(
            collection,
            &related,
            "",
            two_way.unwrap_or(false),
            id,
            new_two_way_key.unwrap_or(""),
            RELATION_SIDE_PARENT,
            new_key,
            new_two_way_key,
        )?;
        let _ = on_delete;
        Ok(true)
    }

    pub fn delete_relationship(&mut self, collection: &str, id: &str) -> Result<bool> {
        let collection_doc = self.silent(|db| db.get_collection(collection))?;
        let related = related_from_attribute(&collection_doc, id).unwrap_or_default();
        self.adapter.delete_relationship(
            collection,
            &related,
            "",
            false,
            id,
            "",
            RELATION_SIDE_PARENT,
        )?;
        self.delete_attribute(collection, id)
    }

    pub fn rename_index(&mut self, collection: &str, old: &str, new: &str) -> Result<bool> {
        self.adapter.rename_index(collection, old, new)
    }

    #[allow(clippy::too_many_arguments)]
    pub fn create_index(
        &mut self,
        collection: &str,
        id: &str,
        type_: &str,
        attributes: Vec<String>,
        lengths: Vec<i64>,
        orders: Vec<String>,
        ttl: i64,
    ) -> Result<bool> {
        let mut collection_doc = self.silent(|db| db.get_collection(collection))?;
        if collection_doc.is_empty() {
            return Err(DatabaseError::not_found("Collection not found"));
        }
        self.adapter.create_index(
            &collection_doc.get_id(),
            id,
            type_,
            &attributes,
            &lengths,
            &orders,
            &[],
            &[],
            ttl,
        )?;
        let index = Document::from_pairs([
            ("$id", AttrValue::from(Id::custom(id))),
            ("key", AttrValue::from(id)),
            ("type", AttrValue::from(type_)),
            ("attributes", AttrValue::from(attributes)),
            (
                "lengths",
                AttrValue::from(lengths.into_iter().map(AttrValue::from).collect::<Vec<_>>()),
            ),
            ("orders", AttrValue::from(orders)),
        ])?;
        let mut indexes = attr_docs(collection_doc.get_attribute("indexes"));
        indexes.push(index);
        collection_doc.set_attribute(
            "indexes",
            AttrValue::from(indexes.into_iter().map(AttrValue::from).collect::<Vec<_>>()),
        );
        self.silent(|db| db.update_document(METADATA, &collection_doc.get_id(), collection_doc))?;
        Ok(true)
    }

    pub fn delete_index(&mut self, collection: &str, id: &str) -> Result<bool> {
        let mut collection_doc = self.silent(|db| db.get_collection(collection))?;
        if collection_doc.is_empty() {
            return Err(DatabaseError::not_found("Collection not found"));
        }
        self.adapter.delete_index(&collection_doc.get_id(), id)?;
        let indexes: Vec<Document> = attr_docs(collection_doc.get_attribute("indexes"))
            .into_iter()
            .filter(|i| i.get_id() != id)
            .collect();
        collection_doc.set_attribute(
            "indexes",
            AttrValue::from(indexes.into_iter().map(AttrValue::from).collect::<Vec<_>>()),
        );
        self.silent(|db| db.update_document(METADATA, &collection_doc.get_id(), collection_doc))?;
        Ok(true)
    }

    pub fn get_document(
        &mut self,
        collection: &str,
        id: &str,
        queries: &[Query],
        for_update: bool,
    ) -> Result<Document> {
        if collection == METADATA && id == METADATA {
            return collection_metadata_document();
        }
        if collection.is_empty() {
            return Err(DatabaseError::not_found("Collection not found"));
        }
        if id.is_empty() {
            return Ok(Document::new());
        }
        let collection_doc = self.silent(|db| db.get_collection(collection))?;
        if collection_doc.is_empty() {
            return Err(DatabaseError::not_found("Collection not found"));
        }
        if self.validate {
            let attributes = attr_docs(collection_doc.get_attribute("attributes"));
            let validator =
                DocumentQueries::new(attributes, self.adapter.get_support_for_attributes());
            if !validator.is_valid_queries(queries) {
                return Err(DatabaseError::query(validator.description()));
            }
        }
        let grouped = Query::group_by_type(queries);
        let selections: Vec<String> = grouped
            .selections
            .iter()
            .flat_map(|q| {
                q.get_values()
                    .iter()
                    .filter_map(|v| v.as_str().map(ToOwned::to_owned))
                    .collect::<Vec<_>>()
            })
            .collect();
        let (collection_key, document_key, hash_key) =
            self.get_cache_keys(&collection_doc.get_id(), Some(id), &selections);
        if !for_update {
            if let Ok(LoadResult::Hit(cached)) = self.cache.load(&document_key, TTL, &hash_key) {
                let json = cached.into_json();
                if json.get(CACHE_EMPTY_MARKER).is_some() {
                    return Ok(Document::new());
                }
                if let Ok(mut document) = Document::try_from_json(json) {
                    document = self.casting(&collection_doc, document);
                    if !self.authorize_read(&collection_doc, &document) {
                        return Ok(Document::new());
                    }
                    self.trigger(EVENT_DOCUMENT_READ, AttrValue::from(document.clone()));
                    return Ok(document);
                }
            }
        }
        let mut document = self
            .adapter
            .get_document(&collection_doc, id, queries, for_update)?;
        if document.is_empty() {
            if !for_update {
                let marker = json!({ CACHE_EMPTY_MARKER: true });
                let _ = self
                    .cache
                    .save_with_lease(&document_key, marker, &hash_key, "0");
                let _ = self.cache.save(&collection_key, "empty", &document_key);
            }
            return Ok(Document::new());
        }
        document.set_attribute("$collection", AttrValue::from(collection_doc.get_id()));
        if !self.authorize_read(&collection_doc, &document) {
            return Ok(Document::new());
        }
        document = self.adapter.casting_after(&collection_doc, document);
        document = self.casting(&collection_doc, document);
        document = self.decode(&collection_doc, document, &selections)?;
        if !for_update {
            let copy = Value::Object(document.get_array_copy_json(&[], &[]));
            if self
                .cache
                .save_with_lease(&document_key, copy, &hash_key, "0")
                .ok()
                .is_some_and(|r| !matches!(r, SaveResult::Failed))
            {
                let _ = self.cache.save(&collection_key, "empty", &document_key);
            }
        }
        self.trigger(EVENT_DOCUMENT_READ, AttrValue::from(document.clone()));
        Ok(document)
    }

    fn authorize_read(&mut self, collection: &Document, document: &Document) -> bool {
        if collection.get_id() == METADATA {
            return true;
        }
        let document_security = collection
            .get_attribute("documentSecurity")
            .as_bool()
            .unwrap_or(false);
        let mut perms = collection.get_read();
        if document_security {
            perms.extend(document.get_read());
        }
        self.adapter
            .get_authorization_mut()
            .is_valid_input(&Input::new(PERMISSION_READ, perms))
    }

    pub fn create_document(
        &mut self,
        collection: &str,
        mut document: Document,
    ) -> Result<Document> {
        if self.adapter.get_shared_tables()
            && !self.adapter.get_tenant_per_document()
            && collection != METADATA
            && self.adapter.get_tenant().is_none()
        {
            return Err(DatabaseError::database(
                "Missing tenant. Tenant must be set when table sharing is enabled.",
            ));
        }
        let collection_doc = self.silent(|db| db.get_collection(collection))?;
        if collection_doc.get_id() != METADATA {
            let ok = self
                .adapter
                .get_authorization_mut()
                .is_valid_input(&Input::new(PERMISSION_CREATE, collection_doc.get_create()));
            if !ok {
                return Err(DatabaseError::authorization(
                    self.adapter.get_authorization().description(),
                ));
            }
        }
        let time = DateTime::now();
        let created_at = document.get_created_at();
        let updated_at = document.get_updated_at();
        let id = if document.get_id().is_empty() {
            Id::unique()?
        } else {
            document.get_id()
        };
        document.set_attribute("$id", AttrValue::from(id));
        document.set_attribute("$collection", AttrValue::from(collection_doc.get_id()));
        if created_at.is_none() || !self.preserve_dates {
            document.set_attribute("$createdAt", AttrValue::from(time.as_str()));
        }
        if updated_at.is_none() || !self.preserve_dates {
            document.set_attribute("$updatedAt", AttrValue::from(time.as_str()));
        }
        if document.get_permissions().is_empty() {
            document.set_attribute("$permissions", AttrValue::from(Vec::<String>::new()));
        }
        if self.adapter.get_shared_tables() && !self.adapter.get_tenant_per_document() {
            if let Some(tenant) = self.adapter.get_tenant().cloned() {
                document.set_attribute("$tenant", tenant);
            }
        }
        document = self.encode(&collection_doc, document, true)?;
        if self.validate {
            let validator = Permissions::default();
            let json = Value::Array(
                document
                    .get_permissions()
                    .into_iter()
                    .map(Value::String)
                    .collect(),
            );
            if !validator.is_valid(&json) {
                return Err(DatabaseError::database(validator.description()));
            }
            let structure = Structure::new(
                collection_doc.clone(),
                self.adapter.get_id_attribute_type(),
                self.adapter.get_min_date_time(),
                self.adapter.get_max_date_time(),
                self.adapter.get_support_for_attributes(),
                self.adapter.get_support_for_unsigned_big_int(),
                None,
            );
            if !structure.is_valid_document(&document) {
                return Err(DatabaseError::structure(structure.description()));
            }
        }
        document = self.adapter.casting_before(&collection_doc, document);
        document = self.adapter.create_document(&collection_doc, document)?;
        self.purge_cached_document(&collection_doc.get_id(), Some(&document.get_id()))?;
        document = self.adapter.casting_after(&collection_doc, document);
        document = self.casting(&collection_doc, document);
        document = self.decode(&collection_doc, document, &[])?;
        self.trigger(EVENT_DOCUMENT_CREATE, AttrValue::from(document.clone()));
        Ok(document)
    }

    pub fn create_documents(
        &mut self,
        collection: &str,
        documents: Vec<Document>,
        _batch_size: i64,
    ) -> Result<i64> {
        let mut count = 0;
        for document in documents {
            self.create_document(collection, document)?;
            count += 1;
        }
        Ok(count)
    }

    pub fn update_document(
        &mut self,
        collection: &str,
        id: &str,
        mut document: Document,
    ) -> Result<Document> {
        let collection_doc = self.silent(|db| db.get_collection(collection))?;
        if collection_doc.is_empty() {
            return Err(DatabaseError::not_found("Collection not found"));
        }
        if collection_doc.get_id() != METADATA {
            let mut perms = collection_doc.get_update();
            let security = collection_doc
                .get_attribute("documentSecurity")
                .as_bool()
                .unwrap_or(false);
            if security {
                perms.extend(document.get_update());
            }
            if !self
                .adapter
                .get_authorization_mut()
                .is_valid_input(&Input::new(PERMISSION_UPDATE, perms))
            {
                return Err(DatabaseError::authorization(
                    self.adapter.get_authorization().description(),
                ));
            }
        }
        if !self.preserve_dates {
            document.set_attribute("$updatedAt", AttrValue::from(DateTime::now()));
        }
        document.set_attribute("$id", AttrValue::from(id));
        document.set_attribute("$collection", AttrValue::from(collection_doc.get_id()));
        document = self.encode(&collection_doc, document, false)?;
        document = self
            .adapter
            .update_document(&collection_doc, id, document, false)?;
        self.purge_cached_document(&collection_doc.get_id(), Some(id))?;
        document = self.decode(&collection_doc, document, &[])?;
        self.trigger(EVENT_DOCUMENT_UPDATE, AttrValue::from(document.clone()));
        Ok(document)
    }

    pub fn update_documents(
        &mut self,
        collection: &str,
        updates: &Document,
        queries: &[Query],
    ) -> Result<i64> {
        let found = self.find(collection, queries, PERMISSION_UPDATE)?;
        let mut count = 0;
        for mut document in found {
            for (k, v) in updates.get_attributes() {
                document.set_attribute(k, v);
            }
            let id = document.get_id();
            self.update_document(collection, &id, document)?;
            count += 1;
        }
        Ok(count)
    }

    pub fn upsert_document(&mut self, collection: &str, document: Document) -> Result<Document> {
        let id = document.get_id();
        let existing = self.silent(|db| db.get_document(collection, &id, &[], false))?;
        if existing.is_empty() {
            self.create_document(collection, document)
        } else {
            self.update_document(collection, &id, document)
        }
    }

    pub fn upsert_documents(
        &mut self,
        collection: &str,
        documents: Vec<Document>,
        _batch_size: i64,
    ) -> Result<i64> {
        let mut count = 0;
        for document in documents {
            self.upsert_document(collection, document)?;
            count += 1;
        }
        Ok(count)
    }

    pub fn upsert_documents_with_increase(
        &mut self,
        collection: &str,
        documents: Vec<Document>,
        _attribute: &str,
        _value: f64,
    ) -> Result<i64> {
        self.upsert_documents(collection, documents, INSERT_BATCH_SIZE)
    }

    pub fn increase_document_attribute(
        &mut self,
        collection: &str,
        id: &str,
        attribute: &str,
        value: f64,
        min: Option<f64>,
        max: Option<f64>,
    ) -> Result<bool> {
        let updated_at = DateTime::now();
        let ok = self.adapter.increase_document_attribute(
            collection,
            id,
            attribute,
            value,
            &updated_at,
            min,
            max,
        )?;
        self.purge_cached_document(collection, Some(id))?;
        Ok(ok)
    }

    pub fn decrease_document_attribute(
        &mut self,
        collection: &str,
        id: &str,
        attribute: &str,
        value: f64,
        min: Option<f64>,
        max: Option<f64>,
    ) -> Result<bool> {
        self.increase_document_attribute(collection, id, attribute, -value, min, max)
    }

    pub fn delete_document(&mut self, collection: &str, id: &str) -> Result<bool> {
        let collection_doc = self.silent(|db| db.get_collection(collection))?;
        if collection_doc.is_empty() && collection != METADATA {
            return Err(DatabaseError::not_found("Collection not found"));
        }
        if collection != METADATA && collection_doc.get_id() != METADATA {
            let document = self.silent(|db| db.get_document(collection, id, &[], false))?;
            let mut perms = collection_doc.get_delete();
            if collection_doc
                .get_attribute("documentSecurity")
                .as_bool()
                .unwrap_or(false)
            {
                perms.extend(document.get_delete());
            }
            if !self
                .adapter
                .get_authorization_mut()
                .is_valid_input(&Input::new(PERMISSION_DELETE, perms))
            {
                return Err(DatabaseError::authorization(
                    self.adapter.get_authorization().description(),
                ));
            }
        }
        let deleted = self.adapter.delete_document(collection, id)?;
        self.purge_cached_document(collection, Some(id))?;
        Ok(deleted)
    }

    pub fn delete_documents(&mut self, collection: &str, queries: &[Query]) -> Result<i64> {
        let found = self.find(collection, queries, PERMISSION_DELETE)?;
        let mut count = 0;
        for document in found {
            if self.delete_document(collection, &document.get_id())? {
                count += 1;
            }
        }
        Ok(count)
    }

    pub fn purge_cached_collection(&mut self, collection_id: &str) -> Result<bool> {
        let (collection_key, _, _) = self.get_cache_keys(collection_id, None, &[]);
        if let Ok(keys) = self.cache.list(&collection_key) {
            for key in keys {
                let _ = self.cache.purge(&key, "");
            }
        }
        let _ = self.cache.purge(&collection_key, "");
        Ok(true)
    }

    pub fn purge_cached_document(&mut self, collection_id: &str, id: Option<&str>) -> Result<bool> {
        let Some(id) = id else {
            return self.purge_cached_collection(collection_id);
        };
        let (collection_key, document_key, _) = self.get_cache_keys(collection_id, Some(id), &[]);
        let _ = self.cache.purge(&document_key, "");
        let _ = self.cache.purge(&collection_key, &document_key);
        Ok(true)
    }

    pub fn find(
        &mut self,
        collection: &str,
        queries: &[Query],
        for_permission: &str,
    ) -> Result<Vec<Document>> {
        let collection_doc = self.silent(|db| db.get_collection(collection))?;
        if collection_doc.is_empty() {
            return Err(DatabaseError::not_found("Collection not found"));
        }
        if self.validate {
            let attributes = attr_docs(collection_doc.get_attribute("attributes"));
            let indexes = attr_docs(collection_doc.get_attribute("indexes"));
            let validator = DocumentsQueries::new(
                attributes,
                indexes,
                self.adapter.get_id_attribute_type(),
                self.max_query_values,
                self.adapter.get_max_uid_length(),
                self.adapter.get_min_date_time(),
                self.adapter.get_max_date_time(),
                self.adapter.get_support_for_attributes(),
                self.adapter.get_support_for_unsigned_big_int(),
            );
            if !validator.is_valid_queries(queries) {
                return Err(DatabaseError::query(validator.description()));
            }
        }
        let document_security = collection_doc
            .get_attribute("documentSecurity")
            .as_bool()
            .unwrap_or(false);
        let skip_auth = self
            .adapter
            .get_authorization_mut()
            .is_valid_input(&Input::new(
                for_permission,
                collection_doc.get_permissions_by_type(for_permission),
            ));
        if !skip_auth && !document_security && collection_doc.get_id() != METADATA {
            return Err(DatabaseError::authorization(
                self.adapter.get_authorization().description(),
            ));
        }
        let grouped = Query::group_by_type(queries);
        let mut order_attributes = grouped.order_attributes.clone();
        let mut order_types = grouped.order_types.clone();
        let unique_order = order_attributes
            .iter()
            .any(|o| o == "$id" || o == "$sequence");
        let vector_search = grouped
            .filters
            .iter()
            .any(|f| VECTOR_TYPES.contains(&f.get_method()));
        if !unique_order && (!vector_search || grouped.cursor.is_some()) {
            let leading = order_attributes.first().cloned();
            let leading_type = order_types
                .first()
                .cloned()
                .unwrap_or_else(|| ORDER_ASC.to_owned());
            if matches!(leading.as_deref(), Some("$createdAt" | "$updatedAt")) {
                order_attributes.push("$sequence".into());
                order_types.push(leading_type);
            } else {
                order_attributes.push("$sequence".into());
                order_types.push(ORDER_ASC.into());
            }
        }
        if let Some(cursor) = &grouped.cursor {
            if cursor.get_collection() != collection_doc.get_id() {
                return Err(DatabaseError::database(
                    "cursor Document must be from the same Collection.",
                ));
            }
        }
        let queries = self.convert_queries(&collection_doc, grouped.filters.clone())?;
        let cursor = grouped.cursor.as_ref();
        let get_results = |db: &mut Self| {
            db.adapter.find(
                &collection_doc,
                &queries,
                grouped.limit.or(Some(25)),
                grouped.offset.or(Some(0)),
                &order_attributes,
                &order_types,
                cursor,
                grouped.cursor_direction.as_deref().unwrap_or(CURSOR_AFTER),
                for_permission,
            )
        };
        let mut results = if skip_auth {
            let initial = self.adapter.get_authorization().get_status();
            self.adapter.get_authorization_mut().disable();
            let result = get_results(self);
            self.adapter.get_authorization_mut().set_status(initial);
            result?
        } else {
            get_results(self)?
        };
        for document in &mut results {
            *document = self.casting(&collection_doc, document.clone());
            *document = self.decode(&collection_doc, document.clone(), &[])?;
        }
        self.trigger(
            EVENT_DOCUMENT_FIND,
            AttrValue::from(
                results
                    .iter()
                    .cloned()
                    .map(AttrValue::from)
                    .collect::<Vec<_>>(),
            ),
        );
        Ok(results)
    }

    pub fn purge_cached_queries(
        &mut self,
        collection: &str,
        namespace: Option<&str>,
    ) -> Result<bool> {
        let key = self.get_query_cache_key(collection, namespace);
        let _ = self.cache.purge(&key, "");
        Ok(true)
    }

    pub fn foreach(
        &mut self,
        collection: &str,
        mut callback: impl FnMut(&Document),
        queries: &[Query],
        for_permission: &str,
    ) -> Result<()> {
        for document in self.find(collection, queries, for_permission)? {
            callback(&document);
        }
        Ok(())
    }

    pub fn find_one(&mut self, collection: &str, queries: &[Query]) -> Result<Document> {
        let mut queries = queries.to_vec();
        queries.push(Query::limit(1));
        Ok(self
            .find(collection, &queries, PERMISSION_READ)?
            .into_iter()
            .next()
            .unwrap_or_default())
    }

    pub fn count(&mut self, collection: &str, queries: &[Query], max: Option<i64>) -> Result<i64> {
        let collection_doc = self.silent(|db| db.get_collection(collection))?;
        if collection_doc.is_empty() {
            return Err(DatabaseError::not_found("Collection not found"));
        }
        let grouped = Query::group_by_type(queries);
        let converted = self.convert_queries(&collection_doc, grouped.filters)?;
        let count = self.adapter.count(&collection_doc, &converted, max)?;
        self.trigger(EVENT_DOCUMENT_COUNT, AttrValue::from(count));
        Ok(count)
    }

    pub fn sum(
        &mut self,
        collection: &str,
        attribute: &str,
        queries: &[Query],
        max: Option<i64>,
    ) -> Result<f64> {
        let collection_doc = self.silent(|db| db.get_collection(collection))?;
        if collection_doc.is_empty() {
            return Err(DatabaseError::not_found("Collection not found"));
        }
        let grouped = Query::group_by_type(queries);
        let converted = self.convert_queries(&collection_doc, grouped.filters)?;
        let sum = self
            .adapter
            .sum(&collection_doc, attribute, &converted, max)?;
        self.trigger(EVENT_DOCUMENT_SUM, AttrValue::from(sum));
        Ok(sum)
    }

    pub fn encode(
        &self,
        collection: &Document,
        mut document: Document,
        apply_defaults: bool,
    ) -> Result<Document> {
        if !self.filters {
            return Ok(document);
        }
        let mut attributes = attr_docs(collection.get_attribute("attributes"));
        attributes.extend(self.get_internal_attributes());
        for attribute in attributes {
            let key = attribute.get_id();
            if key == "$permissions" {
                continue;
            }
            let array = attribute.get_attribute("array").as_bool().unwrap_or(false);
            let default = attribute.get_attribute("default").clone();
            let filters = match attribute.get_attribute("filters") {
                AttrValue::Array(a) => a
                    .values()
                    .filter_map(|v| v.as_str().map(ToOwned::to_owned))
                    .collect::<Vec<_>>(),
                _ => Vec::new(),
            };
            let mut value = document.get_attribute(&key).clone();
            if matches!(key.as_str(), "$createdAt" | "$updatedAt") {
                if let AttrValue::String(s) = &value {
                    if s.is_empty() {
                        document.set_attribute(&key, AttrValue::Null);
                        continue;
                    }
                }
            }
            if value.is_null() && default.is_null() {
                continue;
            }
            if matches!(value, AttrValue::Operator(_)) {
                continue;
            }
            if value.is_null() && !default.is_null() {
                if !apply_defaults {
                    continue;
                }
                value = if array {
                    default
                } else {
                    AttrValue::from(vec![default])
                };
            } else if !array {
                value = AttrValue::from(vec![value]);
            }
            if let AttrValue::Array(items) = &mut value {
                for item in items.values_mut() {
                    for filter in &filters {
                        *item = self.apply_filter(filter, item, true);
                    }
                }
            }
            if array {
                document.set_attribute(&key, value);
            } else if let AttrValue::Array(items) = value {
                document.set_attribute(&key, items.into_values().next().unwrap_or(AttrValue::Null));
            }
        }
        Ok(document)
    }

    pub fn decode(
        &self,
        collection: &Document,
        mut document: Document,
        _selections: &[String],
    ) -> Result<Document> {
        if !self.filters {
            return Ok(document);
        }
        let mut attributes = attr_docs(collection.get_attribute("attributes"));
        attributes.extend(self.get_internal_attributes());
        for attribute in attributes {
            let key = attribute.get_id();
            if key == "$permissions" {
                continue;
            }
            let array = attribute.get_attribute("array").as_bool().unwrap_or(false);
            let filters = match attribute.get_attribute("filters") {
                AttrValue::Array(a) => a
                    .values()
                    .filter_map(|v| v.as_str().map(ToOwned::to_owned))
                    .collect::<Vec<_>>(),
                _ => Vec::new(),
            };
            let mut value = document.get_attribute(&key).clone();
            if value.is_null() {
                continue;
            }
            if !array {
                value = AttrValue::from(vec![value]);
            }
            if let AttrValue::Array(items) = &mut value {
                for item in items.values_mut() {
                    for filter in filters.iter().rev() {
                        *item = self.apply_filter(filter, item, false);
                    }
                }
            }
            if array {
                document.set_attribute(&key, value);
            } else if let AttrValue::Array(items) = value {
                document.set_attribute(&key, items.into_values().next().unwrap_or(AttrValue::Null));
            }
        }
        Ok(document)
    }

    fn apply_filter(&self, name: &str, value: &AttrValue, encode: bool) -> AttrValue {
        if let Some(pair) = self.instance_filters.get(name) {
            return if encode {
                (pair.encode)(value)
            } else {
                (pair.decode)(value)
            };
        }
        if let Some(pair) = FILTERS.lock().unwrap_or_else(|e| e.into_inner()).get(name) {
            return if encode {
                (pair.encode)(value)
            } else {
                (pair.decode)(value)
            };
        }
        value.clone()
    }

    pub fn casting(&self, collection: &Document, mut document: Document) -> Document {
        if !self.adapter.get_support_for_casting() {
            return document;
        }
        for attribute in attr_docs(collection.get_attribute("attributes")) {
            let key = attribute.get_id();
            let type_ = attribute.get_attribute("type").as_str().unwrap_or("");
            let value = document.get_attribute(&key).clone();
            if value.is_null() {
                continue;
            }
            match type_ {
                VAR_INTEGER | VAR_BIGINT => {
                    if let Some(n) = value.as_i64() {
                        document.set_attribute(&key, AttrValue::from(n));
                    }
                }
                VAR_FLOAT => {
                    if let Some(n) = value.as_f64() {
                        document.set_attribute(&key, AttrValue::from(n));
                    }
                }
                VAR_BOOLEAN => {
                    if let Some(b) = value.as_bool() {
                        document.set_attribute(&key, AttrValue::from(b));
                    }
                }
                _ => {}
            }
        }
        document
    }

    #[must_use]
    pub fn get_limit_for_attributes(&self) -> i64 {
        self.adapter.get_limit_for_attributes()
    }
    #[must_use]
    pub fn get_limit_for_indexes(&self) -> i64 {
        self.adapter.get_limit_for_indexes()
    }

    pub fn convert_queries(
        &self,
        collection: &Document,
        queries: Vec<Query>,
    ) -> Result<Vec<Query>> {
        queries
            .into_iter()
            .map(|q| self.convert_query(collection, q))
            .collect()
    }

    pub fn convert_query(&self, collection: &Document, mut query: Query) -> Result<Query> {
        let attr = query.get_attribute();
        for attribute in attr_docs(collection.get_attribute("attributes")) {
            if attribute.get_id() == attr {
                query.set_attribute_type(attribute.get_attribute("type").as_str().unwrap_or(""));
                break;
            }
        }
        Ok(query)
    }

    #[must_use]
    pub fn get_internal_attributes(&self) -> Vec<Document> {
        INTERNAL_ATTRIBUTES
            .iter()
            .filter_map(|v| Document::try_from_json(v.clone()).ok())
            .collect()
    }

    pub fn get_schema_attributes(&mut self, collection: &str) -> Result<Vec<Document>> {
        self.adapter.get_schema_attributes(collection)
    }
    pub fn get_schema_indexes(&mut self, collection: &str) -> Result<Vec<Document>> {
        self.adapter.get_schema_indexes(collection)
    }

    #[must_use]
    pub fn get_cache_keys(
        &self,
        collection_id: &str,
        document_id: Option<&str>,
        selects: &[String],
    ) -> (String, String, String) {
        let hostname = if self.adapter.get_support_for_hostname() {
            self.adapter.get_hostname().to_owned()
        } else {
            String::new()
        };
        let tenant = self
            .adapter
            .get_tenant()
            .map(|t| match t {
                AttrValue::String(s) => s.clone(),
                AttrValue::Number(n) => n.to_string(),
                _ => String::new(),
            })
            .unwrap_or_default();
        let collection_key = format!(
            "{}-cache-{}:{}:{}:collection:{}",
            self.cache_name,
            hostname,
            self.get_namespace(),
            tenant,
            collection_id
        );
        let Some(document_id) = document_id else {
            return (collection_key, String::new(), String::new());
        };
        let document_key = format!("{collection_key}:{document_id}");
        let mut sorted = selects.to_vec();
        sorted.sort();
        let payload = json!({
            "selects": sorted,
            "relationships": self.resolve_relationships,
        });
        let digest = Md5::digest(payload.to_string().as_bytes());
        let hash = format!("{document_key}:{}", hex::encode(digest));
        (collection_key, document_key, hash)
    }

    #[must_use]
    pub fn get_query_cache_key(&self, collection_id: &str, namespace: Option<&str>) -> String {
        let hostname = if self.adapter.get_support_for_hostname() {
            self.adapter.get_hostname().to_owned()
        } else {
            String::new()
        };
        let tenant = self
            .adapter
            .get_tenant()
            .map(|t| match t {
                AttrValue::String(s) => s.clone(),
                AttrValue::Number(n) => n.to_string(),
                _ => String::new(),
            })
            .unwrap_or_default();
        format!(
            "{}-cache-{}:{}:{}:collection:{}:query",
            self.cache_name,
            hostname,
            namespace.unwrap_or_else(|| self.get_namespace()),
            tenant,
            collection_id
        )
    }

    #[must_use]
    pub fn get_query_cache_field(
        &self,
        _collection: Option<&Document>,
        queries: &[Query],
        field: &str,
        for_permission: &str,
    ) -> String {
        let payload = json!({
            "queries": queries.iter().filter_map(|q| q.to_string().ok()).collect::<Vec<_>>(),
            "field": field,
            "permission": for_permission,
        });
        hex::encode(Md5::digest(payload.to_string().as_bytes()))
    }
}

fn encode_json(value: &AttrValue) -> AttrValue {
    match value {
        AttrValue::Document(_) | AttrValue::Array(_) => {
            AttrValue::from(serde_json::to_string(&value.to_json()).unwrap_or_default())
        }
        other => other.clone(),
    }
}

fn decode_json(value: &AttrValue) -> AttrValue {
    let Some(s) = value.as_str() else {
        return value.clone();
    };
    match serde_json::from_str::<Value>(s) {
        Ok(Value::Object(obj)) if obj.contains_key("$id") => {
            Document::try_from_json_object(obj).map_or_else(|_| value.clone(), AttrValue::from)
        }
        Ok(parsed) => AttrValue::from_json(parsed),
        Err(_) => value.clone(),
    }
}

fn attr_docs(value: &AttrValue) -> Vec<Document> {
    match value {
        AttrValue::Array(items) => items
            .values()
            .filter_map(|v| match v {
                AttrValue::Document(d) => Some((**d).clone()),
                AttrValue::Array(inner) => Document::from_map(inner.clone()).ok(),
                _ => {
                    let json = v.to_json();
                    Document::try_from_json(json).ok()
                }
            })
            .collect(),
        AttrValue::String(s) => serde_json::from_str::<Value>(s)
            .ok()
            .map(|v| attr_docs(&AttrValue::from_json(v)))
            .unwrap_or_default(),
        _ => Vec::new(),
    }
}

fn metadata_attribute_docs() -> Vec<Document> {
    match collection_metadata().get("attributes") {
        Some(Value::Array(items)) => items
            .iter()
            .filter_map(|v| Document::try_from_json(v.clone()).ok())
            .collect(),
        _ => Vec::new(),
    }
}

fn collection_metadata_document() -> Result<Document> {
    Document::try_from_json(collection_metadata())
}

fn related_from_attribute(collection: &Document, id: &str) -> Option<String> {
    for attr in attr_docs(collection.get_attribute("attributes")) {
        if attr.get_id() == id {
            if let AttrValue::Array(opts) = attr.get_attribute("options") {
                if let Some(AttrValue::String(s)) = opts.get("relatedCollection") {
                    return Some(s.clone());
                }
            }
            if let AttrValue::String(s) = attr.get_attribute("relatedCollection") {
                return Some(s.clone());
            }
        }
    }
    None
}
