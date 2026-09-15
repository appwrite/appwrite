//! PHP `Utopia\Database\Adapter` and adapter implementations.

pub mod memory;
#[cfg(feature = "mongo")]
pub mod mongo;
#[cfg(feature = "mysql")]
pub mod mysql;
pub mod pool;
#[cfg(feature = "postgres")]
pub mod postgres;
#[cfg(feature = "redis")]
pub mod redis_adapter;
pub mod sql;
#[cfg(feature = "sqlite")]
pub mod sqlite;

pub use memory::Memory;
pub use pool::PoolAdapter;
pub use sql::{Sql, SqlAdapter};

use crate::constants::{CURSOR_AFTER, EVENT_ALL, VAR_INTEGER};
use crate::document::Document;
use crate::error::{DatabaseError, Result};
use crate::query::Query;
use crate::validator::authorization::Authorization;
use crate::value::AttrValue;
use chrono::NaiveDateTime;
use indexmap::IndexMap;

/// Shared adapter state (PHP `Adapter` properties).
#[derive(Debug, Clone)]
pub struct AdapterState {
    pub database: String,
    pub hostname: String,
    pub namespace: String,
    pub shared_tables: bool,
    pub tenant: Option<AttrValue>,
    pub tenant_per_document: bool,
    pub timeout: i64,
    pub in_transaction: i32,
    pub alter_locks: bool,
    pub skip_duplicates: bool,
    pub debug: IndexMap<String, AttrValue>,
    pub metadata: IndexMap<String, AttrValue>,
    pub authorization: Authorization,
}

impl Default for AdapterState {
    fn default() -> Self {
        Self {
            database: String::new(),
            hostname: String::new(),
            namespace: String::new(),
            shared_tables: false,
            tenant: None,
            tenant_per_document: false,
            timeout: 0,
            in_transaction: 0,
            alter_locks: false,
            skip_duplicates: false,
            debug: IndexMap::new(),
            metadata: IndexMap::new(),
            authorization: Authorization::new(),
        }
    }
}

/// PHP `Adapter::filter`.
#[must_use]
pub fn filter_key(value: &str) -> String {
    value
        .chars()
        .filter(|c| c.is_ascii_alphanumeric() || *c == '_' || *c == '-')
        .collect()
}

/// PHP `Utopia\Database\Adapter`.
pub trait Adapter: Send {
    fn state(&self) -> &AdapterState;
    fn state_mut(&mut self) -> &mut AdapterState;

    fn filter(&self, value: &str) -> String {
        filter_key(value)
    }

    fn set_authorization(&mut self, authorization: Authorization) -> &mut Self {
        self.state_mut().authorization = authorization;
        self
    }
    fn get_authorization(&self) -> &Authorization {
        &self.state().authorization
    }
    fn get_authorization_mut(&mut self) -> &mut Authorization {
        &mut self.state_mut().authorization
    }

    fn set_namespace(&mut self, namespace: &str) -> Result<&mut Self> {
        self.state_mut().namespace = self.filter(namespace);
        Ok(self)
    }
    fn get_namespace(&self) -> &str {
        &self.state().namespace
    }
    fn set_hostname(&mut self, hostname: impl Into<String>) -> &mut Self {
        self.state_mut().hostname = hostname.into();
        self
    }
    fn get_hostname(&self) -> &str {
        &self.state().hostname
    }
    fn set_database(&mut self, name: &str) -> Result<bool> {
        self.state_mut().database = self.filter(name);
        Ok(true)
    }
    fn get_database(&self) -> &str {
        &self.state().database
    }
    fn set_shared_tables(&mut self, shared: bool) -> bool {
        self.state_mut().shared_tables = shared;
        true
    }
    fn get_shared_tables(&self) -> bool {
        self.state().shared_tables
    }
    fn set_tenant(&mut self, tenant: Option<AttrValue>) -> bool {
        self.state_mut().tenant = tenant;
        true
    }
    fn get_tenant(&self) -> Option<&AttrValue> {
        self.state().tenant.as_ref()
    }
    fn set_tenant_per_document(&mut self, enabled: bool) -> bool {
        self.state_mut().tenant_per_document = enabled;
        true
    }
    fn get_tenant_per_document(&self) -> bool {
        self.state().tenant_per_document
    }
    fn set_skip_duplicates(&mut self, skip: bool) {
        self.state_mut().skip_duplicates = skip;
    }

    fn set_timeout(&mut self, milliseconds: i64, _event: &str) {
        self.state_mut().timeout = milliseconds;
    }
    fn ping(&mut self) -> bool {
        true
    }
    fn reconnect(&mut self) -> Result<()> {
        Ok(())
    }
    fn start_transaction(&mut self) -> Result<bool> {
        self.state_mut().in_transaction += 1;
        Ok(true)
    }
    fn commit_transaction(&mut self) -> Result<bool> {
        if self.state().in_transaction == 0 {
            return Ok(false);
        }
        self.state_mut().in_transaction -= 1;
        Ok(true)
    }
    fn rollback_transaction(&mut self) -> Result<bool> {
        if self.state().in_transaction == 0 {
            return Ok(false);
        }
        self.state_mut().in_transaction -= 1;
        Ok(true)
    }

    fn create(&mut self, _name: &str) -> Result<bool> {
        Ok(true)
    }
    fn exists(&mut self, _database: &str, _collection: Option<&str>) -> Result<bool> {
        Ok(false)
    }
    fn list(&mut self) -> Result<Vec<String>> {
        Ok(Vec::new())
    }
    fn delete(&mut self, _name: &str) -> Result<bool> {
        Ok(true)
    }
    fn create_collection(
        &mut self,
        _name: &str,
        _attributes: &[Document],
        _indexes: &[Document],
    ) -> Result<bool> {
        Ok(true)
    }
    fn delete_collection(&mut self, _id: &str) -> Result<bool> {
        Ok(true)
    }
    fn analyze_collection(&mut self, _collection: &str) -> Result<bool> {
        Ok(false)
    }
    fn create_attribute(
        &mut self,
        _collection: &str,
        _id: &str,
        _type_: &str,
        _size: i64,
        _signed: bool,
        _array: bool,
        _required: bool,
    ) -> Result<bool> {
        Ok(true)
    }
    fn create_attributes(&mut self, collection: &str, attributes: &[Document]) -> Result<bool> {
        for attribute in attributes {
            self.create_attribute(
                collection,
                &attribute.get_id(),
                attribute.get_attribute("type").as_str().unwrap_or(""),
                attribute.get_attribute("size").as_i64().unwrap_or(0),
                attribute.get_attribute("signed").as_bool().unwrap_or(true),
                attribute.get_attribute("array").as_bool().unwrap_or(false),
                attribute
                    .get_attribute("required")
                    .as_bool()
                    .unwrap_or(false),
            )?;
        }
        Ok(true)
    }
    fn update_attribute(
        &mut self,
        _collection: &str,
        _id: &str,
        _type_: &str,
        _size: i64,
        _signed: bool,
        _array: bool,
        _new_key: Option<&str>,
        _required: bool,
    ) -> Result<bool> {
        Ok(true)
    }
    fn delete_attribute(&mut self, _collection: &str, _id: &str) -> Result<bool> {
        Ok(true)
    }
    fn rename_attribute(&mut self, _collection: &str, _old: &str, _new: &str) -> Result<bool> {
        Ok(true)
    }
    fn create_relationship(
        &mut self,
        _collection: &str,
        _related_collection: &str,
        _type_: &str,
        _two_way: bool,
        _id: &str,
        _two_way_key: &str,
    ) -> Result<bool> {
        Ok(true)
    }
    fn update_relationship(
        &mut self,
        _collection: &str,
        _related_collection: &str,
        _type_: &str,
        _two_way: bool,
        _key: &str,
        _two_way_key: &str,
        _side: &str,
        _new_key: Option<&str>,
        _new_two_way_key: Option<&str>,
    ) -> Result<bool> {
        Ok(true)
    }
    fn delete_relationship(
        &mut self,
        _collection: &str,
        _related_collection: &str,
        _type_: &str,
        _two_way: bool,
        _key: &str,
        _two_way_key: &str,
        _side: &str,
    ) -> Result<bool> {
        Ok(true)
    }
    fn rename_index(&mut self, _collection: &str, _old: &str, _new: &str) -> Result<bool> {
        Ok(true)
    }
    fn create_index(
        &mut self,
        _collection: &str,
        _id: &str,
        _type_: &str,
        _attributes: &[String],
        _lengths: &[i64],
        _orders: &[String],
        _index_attribute_types: &[String],
        _collation: &[String],
        _ttl: i64,
    ) -> Result<bool> {
        Ok(true)
    }
    fn delete_index(&mut self, _collection: &str, _id: &str) -> Result<bool> {
        Ok(true)
    }
    fn get_document(
        &mut self,
        _collection: &Document,
        _id: &str,
        _queries: &[Query],
        _for_update: bool,
    ) -> Result<Document> {
        Ok(Document::new())
    }
    fn create_document(&mut self, _collection: &Document, document: Document) -> Result<Document> {
        Ok(document)
    }
    fn create_documents(
        &mut self,
        _collection: &Document,
        documents: Vec<Document>,
    ) -> Result<Vec<Document>> {
        Ok(documents)
    }
    fn update_document(
        &mut self,
        _collection: &Document,
        _id: &str,
        document: Document,
        _skip_permissions: bool,
    ) -> Result<Document> {
        Ok(document)
    }
    fn update_documents(
        &mut self,
        _collection: &Document,
        _updates: &Document,
        documents: &[Document],
    ) -> Result<i64> {
        Ok(documents.len() as i64)
    }
    fn upsert_documents(
        &mut self,
        _collection: &Document,
        documents: Vec<Document>,
    ) -> Result<Vec<Document>> {
        Ok(documents)
    }
    fn get_sequences(&mut self, _collection: &str, documents: &[Document]) -> Result<Vec<String>> {
        Ok(documents
            .iter()
            .filter_map(Document::get_sequence)
            .collect())
    }
    fn delete_document(&mut self, _collection: &str, _id: &str) -> Result<bool> {
        Ok(true)
    }
    fn delete_documents(
        &mut self,
        _collection: &str,
        sequences: &[String],
        _permission_ids: &[String],
    ) -> Result<i64> {
        Ok(sequences.len() as i64)
    }
    fn find(
        &mut self,
        _collection: &Document,
        _queries: &[Query],
        _limit: Option<i64>,
        _offset: Option<i64>,
        _order_attributes: &[String],
        _order_types: &[String],
        _cursor: Option<&Document>,
        _cursor_direction: &str,
        _for_permission: &str,
    ) -> Result<Vec<Document>> {
        Ok(Vec::new())
    }
    fn sum(
        &mut self,
        _collection: &Document,
        _attribute: &str,
        _queries: &[Query],
        _max: Option<i64>,
    ) -> Result<f64> {
        Ok(0.0)
    }
    fn count(
        &mut self,
        _collection: &Document,
        _queries: &[Query],
        _max: Option<i64>,
    ) -> Result<i64> {
        Ok(0)
    }
    fn get_size_of_collection(&mut self, _collection: &str) -> Result<i64> {
        Ok(0)
    }
    fn get_size_of_collection_on_disk(&mut self, _collection: &str) -> Result<i64> {
        Ok(0)
    }
    fn increase_document_attribute(
        &mut self,
        _collection: &str,
        _id: &str,
        _attribute: &str,
        _value: f64,
        _updated_at: &str,
        _min: Option<f64>,
        _max: Option<f64>,
    ) -> Result<bool> {
        Ok(true)
    }

    fn get_limit_for_string(&self) -> i64 {
        16_384
    }
    fn get_limit_for_int(&self) -> i64 {
        4
    }
    fn get_limit_for_big_int(&self) -> i64 {
        8
    }
    fn get_limit_for_attributes(&self) -> i64 {
        0
    }
    fn get_limit_for_indexes(&self) -> i64 {
        64
    }
    fn get_max_index_length(&self) -> i64 {
        768
    }
    fn get_max_varchar_length(&self) -> i64 {
        16_381
    }
    fn get_max_uid_length(&self) -> i64 {
        36
    }
    fn get_min_date_time(&self) -> NaiveDateTime {
        chrono::NaiveDate::from_ymd_opt(1, 1, 1)
            .unwrap()
            .and_hms_opt(0, 0, 0)
            .unwrap()
    }
    fn get_max_date_time(&self) -> NaiveDateTime {
        chrono::NaiveDate::from_ymd_opt(9999, 12, 31)
            .unwrap()
            .and_hms_opt(23, 59, 59)
            .unwrap()
    }
    fn get_id_attribute_type(&self) -> &'static str {
        VAR_INTEGER
    }
    fn get_support_for_unsigned_big_int(&self) -> bool {
        false
    }

    fn get_support_for_schemas(&self) -> bool {
        true
    }
    fn get_support_for_attributes(&self) -> bool {
        true
    }
    fn set_support_for_attributes(&mut self, _support: bool) -> bool {
        true
    }
    fn get_support_for_schema_attributes(&self) -> bool {
        true
    }
    fn get_support_for_schema_indexes(&self) -> bool {
        true
    }
    fn get_support_for_index(&self) -> bool {
        true
    }
    fn get_support_for_index_array(&self) -> bool {
        true
    }
    fn get_support_for_cast_index_array(&self) -> bool {
        false
    }
    fn get_support_for_unique_index(&self) -> bool {
        true
    }
    fn get_support_for_fulltext_index(&self) -> bool {
        true
    }
    fn get_support_for_fulltext_wildcard_index(&self) -> bool {
        false
    }
    fn get_support_for_casting(&self) -> bool {
        false
    }
    fn get_support_for_query_contains(&self) -> bool {
        true
    }
    fn get_support_for_timeouts(&self) -> bool {
        false
    }
    fn get_support_for_relationships(&self) -> bool {
        true
    }
    fn get_support_for_update_lock(&self) -> bool {
        false
    }
    fn get_support_for_batch_operations(&self) -> bool {
        true
    }
    fn get_support_for_attribute_resizing(&self) -> bool {
        true
    }
    fn get_support_for_get_connection_id(&self) -> bool {
        false
    }
    fn get_support_for_upserts(&self) -> bool {
        true
    }
    fn get_support_for_upsert_on_unique_index(&self) -> bool {
        false
    }
    fn get_support_for_vectors(&self) -> bool {
        false
    }
    fn get_support_for_cache_skip_on_failure(&self) -> bool {
        false
    }
    fn get_support_for_caching(&self) -> bool {
        true
    }
    fn get_support_for_reconnection(&self) -> bool {
        false
    }
    fn get_support_for_hostname(&self) -> bool {
        false
    }
    fn get_support_for_batch_create_attributes(&self) -> bool {
        true
    }
    fn get_support_for_spatial_attributes(&self) -> bool {
        false
    }
    fn get_support_for_object(&self) -> bool {
        true
    }
    fn get_support_for_object_indexes(&self) -> bool {
        false
    }
    fn get_support_for_spatial_index_null(&self) -> bool {
        false
    }
    fn get_support_for_operators(&self) -> bool {
        true
    }
    fn get_support_for_optional_spatial_attribute_with_existing_rows(&self) -> bool {
        false
    }
    fn get_support_for_spatial_index_order(&self) -> bool {
        false
    }
    fn get_support_for_spatial_axis_order(&self) -> bool {
        false
    }
    fn get_support_for_boundary_inclusive_contains(&self) -> bool {
        true
    }
    fn get_support_for_distance_between_multi_dimension_geometry_in_meters(&self) -> bool {
        false
    }
    fn get_support_for_multiple_fulltext_indexes(&self) -> bool {
        true
    }
    fn get_support_for_identical_indexes(&self) -> bool {
        true
    }
    fn get_support_for_order_random(&self) -> bool {
        true
    }
    fn get_support_for_internal_casting(&self) -> bool {
        false
    }
    fn get_support_for_utc_casting(&self) -> bool {
        false
    }
    fn get_support_for_integer_booleans(&self) -> bool {
        false
    }
    fn get_support_for_alter_locks(&self) -> bool {
        false
    }
    fn get_support_non_utf_characters(&self) -> bool {
        true
    }
    fn get_support_for_trigram_index(&self) -> bool {
        false
    }
    fn get_support_for_pcre_regex(&self) -> bool {
        true
    }
    fn get_support_for_posix_regex(&self) -> bool {
        false
    }
    fn get_support_for_transaction_retries(&self) -> bool {
        true
    }
    fn get_support_for_nested_transactions(&self) -> bool {
        true
    }
    fn get_support_for_ttl_indexes(&self) -> bool {
        false
    }

    fn get_count_of_attributes(&self, collection: &Document) -> i64 {
        let n = match collection.get_attribute("attributes") {
            AttrValue::Array(a) => a.len() as i64,
            _ => 0,
        };
        n + self.get_count_of_default_attributes()
    }
    fn get_count_of_indexes(&self, collection: &Document) -> i64 {
        let n = match collection.get_attribute("indexes") {
            AttrValue::Array(a) => a.len() as i64,
            _ => 0,
        };
        n + self.get_count_of_default_indexes()
    }
    fn get_count_of_default_attributes(&self) -> i64 {
        crate::constants::INTERNAL_ATTRIBUTES.len() as i64
    }
    fn get_count_of_default_indexes(&self) -> i64 {
        crate::constants::INTERNAL_INDEXES.len() as i64
    }
    fn get_document_size_limit(&self) -> i64 {
        0
    }
    fn get_attribute_width(&self, _collection: &Document) -> i64 {
        0
    }
    fn get_keywords(&self) -> Vec<String> {
        Vec::new()
    }
    fn get_connection_id(&self) -> String {
        "0".into()
    }
    fn get_internal_indexes_keys(&self) -> Vec<String> {
        Vec::new()
    }
    fn get_schema_attributes(&mut self, _collection: &str) -> Result<Vec<Document>> {
        Ok(Vec::new())
    }
    fn get_schema_indexes(&mut self, _collection: &str) -> Result<Vec<Document>> {
        Ok(Vec::new())
    }
    fn get_tenant_query(&self, _collection: &str, _alias: &str) -> String {
        String::new()
    }
    fn decode_point(&self, _wkb: &str) -> Result<AttrValue> {
        Err(DatabaseError::database("Spatial decode is not implemented"))
    }
    fn decode_linestring(&self, _wkb: &str) -> Result<AttrValue> {
        Err(DatabaseError::database("Spatial decode is not implemented"))
    }
    fn decode_polygon(&self, _wkb: &str) -> Result<AttrValue> {
        Err(DatabaseError::database("Spatial decode is not implemented"))
    }
    fn casting_before(&self, collection: &Document, document: Document) -> Document {
        let _ = collection;
        document
    }
    fn casting_after(&self, collection: &Document, document: Document) -> Document {
        let _ = collection;
        document
    }
    fn set_utc_datetime(&self, value: &str) -> AttrValue {
        AttrValue::from(value)
    }
    fn get_driver(&self) -> AttrValue {
        AttrValue::from("adapter")
    }
}

/// PHP `Adapter::withTransaction` (kept off the trait so `dyn Adapter` stays object-safe).
pub fn with_transaction<A, T, F>(adapter: &mut A, mut callback: F) -> Result<T>
where
    A: Adapter,
    F: FnMut(&mut A) -> Result<T>,
{
    let retries = 2;
    let mut last = DatabaseError::transaction("Failed to execute transaction");
    for attempt in 0..=retries {
        adapter.start_transaction()?;
        match callback(adapter) {
            Ok(result) => {
                adapter.commit_transaction()?;
                return Ok(result);
            }
            Err(action) => {
                let _ = adapter.rollback_transaction();
                match &action {
                    DatabaseError::Duplicate(_)
                    | DatabaseError::Restricted(_)
                    | DatabaseError::Authorization(_)
                    | DatabaseError::Relationship(_)
                    | DatabaseError::Conflict(_)
                    | DatabaseError::Limit(_)
                    | DatabaseError::Timeout(_) => return Err(action),
                    _ => {
                        last = action;
                        if attempt < retries {
                            std::thread::sleep(std::time::Duration::from_millis(
                                50 * (attempt as u64 + 1),
                            ));
                            continue;
                        }
                    }
                }
            }
        }
    }
    Err(last)
}

/// Default find cursor direction.
pub fn default_cursor_direction() -> &'static str {
    CURSOR_AFTER
}

/// Default timeout event.
pub fn default_timeout_event() -> &'static str {
    EVENT_ALL
}
