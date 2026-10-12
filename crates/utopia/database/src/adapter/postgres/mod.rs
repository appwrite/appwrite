//! [`Postgres`]: the PostgreSQL adapter.
//!
//! One adapter owns one server connection, like PHP's PDO handle: a
//! transaction is per adapter, nested transactions are savepoints, and the
//! settings (schema, namespace, tenant, timeout, statement transforms,
//! authorization) live in its [`State`].
//!
//! It writes the SQL PHP's adapter writes, byte for byte (the text
//! [`Adapter::before`] transforms see), and runs it the way PHP's PDO does
//! with emulated prepares: placeholders replaced by quoted literals, the
//! statement sent as text, every fetched value a string (see [`pdo`]). So
//! tables, indexes, rows, results and error messages are the ones PHP's
//! adapter produces against the same server.
//!
//! ```no_run
//! # async fn demo() -> utopia_database::Result<()> {
//! use utopia_database::Adapter;
//! use utopia_database::adapter::postgres::Postgres;
//!
//! let adapter = Postgres::connect("postgres://root:password@localhost:5432/root").await?;
//! adapter.set_database("appwrite")?;
//! adapter.set_namespace("_1")?;
//! adapter.create("appwrite").await?;
//! # Ok(()) }
//! ```
//!
//! PHP: `Utopia\Database\Adapter\Postgres` and the parts of
//! `Utopia\Database\Adapter\SQL` it inherits, over `Utopia\Database\PDO`.

use std::sync::Mutex as SyncMutex;

use async_trait::async_trait;
use php_std::datetime::{DateTime, TzInfo};

use super::{Adapter, FindOptions, State};
use crate::change::Change;
use crate::database::Database;
use crate::document::Document;
use crate::error::{Error, Result};
use crate::query::Query;
use crate::value::{Array, Value};

mod ddl;
mod documents;
mod find;
mod operators;
pub mod pdo;
mod spatial;
mod sql;
mod widths;
pub mod wire;

pub use sql::{process_exception, violated_columns};
pub use wire::Config;

/// The PostgreSQL adapter. See the [module docs](self).
///
/// PHP: `Utopia\Database\Adapter\Postgres` (extends `Adapter\SQL`).
pub struct Postgres {
    state: State,
    pdo: tokio::sync::Mutex<pdo::Pdo>,
    float_precision: SyncMutex<i64>,
    host: String,
}

impl Postgres {
    /// Connects to `url` (`postgres://user:password@host:port/database`).
    ///
    /// PHP: `new Postgres(new PDO("pgsql:host=...;port=...;dbname=...", $user, $password, SQL::getPDOAttributes()))`.
    pub async fn connect(url: &str) -> Result<Postgres> {
        let config = Config::parse(url).ok_or_else(|| Error::database(format!("Invalid PostgreSQL URL: {url}")))?;
        Self::with_config(config).await
    }

    /// Connects with explicit settings.
    ///
    /// PHP: `new Postgres($pdo)`.
    pub async fn with_config(config: Config) -> Result<Postgres> {
        let pdo = pdo::Pdo::connect(config).await?;
        let host = pdo.config().host.clone();
        Ok(Postgres {
            state: State::default(),
            pdo: tokio::sync::Mutex::new(pdo),
            float_precision: SyncMutex::new(17),
            host,
        })
    }

    /// The same adapter reporting `hostname` as its host name
    /// ([`Adapter::hostname`], which cache keys carry) instead of the host
    /// it connected to: for a server reached through a forwarded port or an
    /// alias, so that keys match those of runtimes reaching it by name.
    pub fn with_hostname(mut self, hostname: impl Into<String>) -> Self {
        self.host = hostname.into();
        self
    }

    /// The connection settings.
    pub async fn config(&self) -> Config {
        self.pdo.lock().await.config().clone()
    }

    /// `setFloatPrecision($precision)`: the fractional digits floats are
    /// bound with in finds (17 by default).
    ///
    /// PHP: `Utopia\Database\Adapter\SQL::setFloatPrecision`.
    pub fn set_float_precision(&self, precision: i64) {
        *self.float_precision.lock().unwrap_or_else(|e| e.into_inner()) = precision;
    }

    /// `getFloatPrecision($value)`: `sprintf('%.{precision}F', $value)`.
    fn float_literal(&self, value: f64) -> Result<Value> {
        let precision = *self.float_precision.lock().unwrap_or_else(|e| e.into_inner());
        let format = format!("%.{precision}F");
        let out = php_std::format::sprintf(format.as_bytes(), &[php_std::format::Arg::Float(value)])
            .map_err(|e| Error::new(crate::Kind::ValueError, e.message().to_owned()))?;
        Ok(Value::Str(crate::value::Str::from(out)))
    }

    /// `getSupportForNumericCasting()`: `false`.
    ///
    /// PHP: `Utopia\Database\Adapter\SQL::getSupportForNumericCasting`.
    pub fn support_for_numeric_casting(&self) -> bool {
        false
    }

    /// `getSupportForJSONOverlaps()`: `false`.
    ///
    /// PHP: `Utopia\Database\Adapter\Postgres::getSupportForJSONOverlaps`.
    pub fn support_for_json_overlaps(&self) -> bool {
        false
    }

    /// `getLikeOperator()`: `ILIKE`.
    ///
    /// PHP: `Utopia\Database\Adapter\Postgres::getLikeOperator`.
    pub fn like_operator(&self) -> &'static str {
        "ILIKE"
    }

    /// `getRegexOperator()`: `~`.
    ///
    /// PHP: `Utopia\Database\Adapter\Postgres::getRegexOperator`.
    pub fn regex_operator(&self) -> &'static str {
        "~"
    }

    /// `getLockType()`: `''` (PostgreSQL has no `ALTER ... LOCK`).
    ///
    /// PHP: `Utopia\Database\Adapter\SQL::getLockType`.
    pub fn lock_type(&self) -> &'static str {
        ""
    }

    /// `getPDOAttributes()`: the PDO settings the PHP adapter needs, as
    /// `(attribute, value)` pairs; the Rust connection always behaves so.
    ///
    /// PHP: `Utopia\Database\Adapter\SQL::getPDOAttributes`.
    pub fn pdo_attributes() -> &'static [(&'static str, &'static str)] {
        &[
            ("ATTR_TIMEOUT", "3"),
            ("ATTR_PERSISTENT", "true"),
            ("ATTR_DEFAULT_FETCH_MODE", "FETCH_ASSOC"),
            ("ATTR_ERRMODE", "ERRMODE_EXCEPTION"),
            ("ATTR_EMULATE_PREPARES", "true"),
            ("ATTR_STRINGIFY_FETCHES", "true"),
        ]
    }

    /// `getSpatialTypeFromWKT($wkt)`: `point`, `linestring`, `polygon`, ...
    ///
    /// PHP: `Utopia\Database\Adapter\SQL::getSpatialTypeFromWKT`.
    pub fn spatial_type_from_wkt(&self, wkt: &[u8]) -> Result<Vec<u8>> {
        spatial::type_from_wkt(wkt)
    }

    /// `getSQLConditions($queries, $binds, $separator)`: the `WHERE`
    /// conditions of `queries`, joined with `separator`, adding their bound
    /// values to `binds`.
    ///
    /// PHP: `Utopia\Database\Adapter\SQL::getSQLConditions`.
    pub fn sql_conditions(
        &self,
        queries: &[Query],
        binds: &mut Vec<(String, Value)>,
        separator: &str,
    ) -> Result<String> {
        let mut b = sql::Binds(std::mem::take(binds));
        let out = self.conditions(queries, &mut b, separator);
        *binds = b.0;
        out
    }

    /// `getTenantQuery($collection, $alias, $tenantCount, $condition)`: the
    /// tenant condition with `tenant_count` numbered placeholders
    /// (`:_tenant_0`, ...; `:_tenant` for 0) after `condition`.
    ///
    /// PHP: `Utopia\Database\Adapter\SQL::getTenantQuery`.
    pub fn tenant_query_full(&self, collection: &str, alias: &str, tenant_count: usize, condition: &str) -> String {
        self.tenant_query_with(collection, alias, tenant_count, condition)
    }

    /// `getDriver()->exec($sql)`: runs SQL on the adapter's connection as
    /// it is, returning the affected row count.
    ///
    /// PHP: `Utopia\Database\Adapter\SQL::getDriver` (the PDO handle).
    pub async fn driver_exec(&self, sql: &str) -> Result<i64> {
        if sql.is_empty() {
            return Err(Error::new(crate::Kind::ValueError, "PDO::exec(): Argument #1 ($statement) must not be empty"));
        }
        self.pdo.lock().await.exec(sql).await.map_err(pdo::PdoError::into_error)
    }

    /// `getDriver()->prepare($sql)->execute()` then `fetchAll()`: the rows,
    /// every value a string (or `null`), as PHP's PDO fetches them.
    ///
    /// PHP: `Utopia\Database\Adapter\SQL::getDriver` (the PDO handle).
    pub async fn driver_query(&self, sql: &str) -> Result<Vec<Array>> {
        let r = self.pdo.lock().await.execute(&pdo::Statement::new(sql)).await.map_err(sql::raw)?;
        Ok(r.rows)
    }
}

#[async_trait]
impl Adapter for Postgres {
    fn state(&self) -> &State {
        &self.state
    }

    /// `getHostname()`: the host the connection was made to.
    fn hostname(&self) -> String {
        self.host.clone()
    }

    fn set_timeout(&self, milliseconds: i64, _event: &str) -> Result<()> {
        if milliseconds <= 0 {
            return Err(Error::database("Timeout must be greater than 0"));
        }
        self.state.lock().timeout = milliseconds;
        Ok(())
    }

    fn column_type(&self, kind: &str, size: i64, signed: bool, array: bool, required: bool) -> Result<String> {
        sql::sql_type(kind, size, signed, array, required)
    }

    async fn start_transaction(&self) -> Result<bool> {
        self.start_transaction_impl().await
    }

    async fn commit_transaction(&self) -> Result<bool> {
        self.commit_transaction_impl().await
    }

    async fn rollback_transaction(&self) -> Result<bool> {
        self.rollback_transaction_impl().await
    }

    async fn ping(&self) -> Result<bool> {
        let stmt = pdo::Statement::new("SELECT 1;");
        self.run(&stmt).await.map(|r| r.ok).map_err(sql::raw)
    }

    async fn reconnect(&self) -> Result<()> {
        self.pdo.lock().await.reconnect().await?;
        self.state.lock().in_transaction = 0;
        Ok(())
    }

    async fn connection_id(&self) -> Result<String> {
        let mut pdo = self.pdo.lock().await;
        let r = pdo.execute(&pdo::Statement::new("SELECT pg_backend_pid();")).await.map_err(sql::raw)?;
        Ok(r.column().map(|v| crate::value::to_text(&v)).unwrap_or_default())
    }

    async fn create(&self, name: &str) -> Result<bool> {
        self.create_database(name).await
    }

    async fn exists(&self, database: &str, collection: Option<&str>) -> Result<bool> {
        self.exists_impl(database, collection).await
    }

    async fn list(&self) -> Result<Vec<Document>> {
        Ok(Vec::new())
    }

    async fn delete(&self, name: &str) -> Result<bool> {
        self.delete_database(name).await
    }

    async fn create_collection(&self, name: &str, attributes: &[Document], indexes: &[Document]) -> Result<bool> {
        self.create_collection_impl(name, attributes, indexes).await
    }

    async fn delete_collection(&self, id: &str) -> Result<bool> {
        self.delete_collection_impl(id).await
    }

    async fn analyze_collection(&self, _collection: &str) -> Result<bool> {
        Ok(false)
    }

    async fn size_of_collection(&self, collection: &str) -> Result<i64> {
        self.collection_size(collection, "pg_relation_size").await
    }

    async fn size_of_collection_on_disk(&self, collection: &str) -> Result<i64> {
        self.collection_size(collection, "pg_total_relation_size").await
    }

    async fn schema_attributes(&self, _collection: &str) -> Result<Vec<Document>> {
        Ok(Vec::new())
    }

    async fn schema_indexes(&self, _collection: &str) -> Result<Vec<Document>> {
        Ok(Vec::new())
    }

    async fn create_attribute(
        &self,
        collection: &str,
        id: &str,
        kind: &str,
        size: i64,
        signed: bool,
        array: bool,
        required: bool,
    ) -> Result<bool> {
        self.create_attribute_impl(collection, id, kind, size, signed, array, required).await
    }

    async fn create_attributes(&self, collection: &str, attributes: &[Array]) -> Result<bool> {
        self.create_attributes_impl(collection, attributes).await
    }

    async fn update_attribute(
        &self,
        collection: &str,
        id: &str,
        kind: &str,
        size: i64,
        signed: bool,
        array: bool,
        new_key: Option<&str>,
        required: bool,
    ) -> Result<bool> {
        self.update_attribute_impl(collection, id, kind, size, signed, array, new_key, required).await
    }

    async fn delete_attribute(&self, collection: &str, id: &str) -> Result<bool> {
        self.delete_attribute_impl(collection, id).await
    }

    async fn rename_attribute(&self, collection: &str, old: &str, new: &str) -> Result<bool> {
        self.rename_attribute_impl(collection, old, new).await
    }

    async fn create_relationship(
        &self,
        collection: &str,
        related_collection: &str,
        kind: &str,
        two_way: bool,
        id: &str,
        two_way_key: &str,
    ) -> Result<bool> {
        self.create_relationship_impl(collection, related_collection, kind, two_way, id, two_way_key).await
    }

    async fn update_relationship(
        &self,
        collection: &str,
        related_collection: &str,
        kind: &str,
        two_way: bool,
        key: &str,
        two_way_key: &str,
        side: &str,
        new_key: Option<&str>,
        new_two_way_key: Option<&str>,
    ) -> Result<bool> {
        self.update_relationship_impl(
            collection,
            related_collection,
            kind,
            two_way,
            key,
            two_way_key,
            side,
            new_key,
            new_two_way_key,
        )
        .await
    }

    async fn delete_relationship(
        &self,
        collection: &str,
        related_collection: &str,
        kind: &str,
        two_way: bool,
        key: &str,
        two_way_key: &str,
        side: &str,
    ) -> Result<bool> {
        self.delete_relationship_impl(collection, related_collection, kind, two_way, key, two_way_key, side).await
    }

    async fn create_index(
        &self,
        collection: &str,
        id: &str,
        kind: &str,
        attributes: &[String],
        lengths: &[Value],
        orders: &[Value],
        attribute_types: &[(String, String)],
        collation: &[String],
        ttl: i64,
    ) -> Result<bool> {
        self.create_index_impl(collection, id, kind, attributes, lengths, orders, attribute_types, collation, ttl).await
    }

    async fn delete_index(&self, collection: &str, id: &str) -> Result<bool> {
        self.delete_index_impl(collection, id).await
    }

    async fn rename_index(&self, collection: &str, old: &str, new: &str) -> Result<bool> {
        self.rename_index_impl(collection, old, new).await
    }

    async fn get_document(
        &self,
        collection: &Document,
        id: &str,
        queries: &[Query],
        for_update: bool,
    ) -> Result<Document> {
        self.get_document_impl(collection, &Value::Str(id.into()), queries, for_update).await
    }

    async fn create_document(&self, collection: &Document, document: Document) -> Result<Document> {
        self.create_document_impl(collection, document).await
    }

    async fn create_documents(&self, collection: &Document, documents: Vec<Document>) -> Result<Vec<Document>> {
        self.create_documents_impl(collection, documents).await
    }

    async fn update_document(
        &self,
        collection: &Document,
        id: &str,
        document: Document,
        skip_permissions: bool,
    ) -> Result<Document> {
        self.update_document_impl(collection, id, document, skip_permissions).await
    }

    async fn update_documents(&self, collection: &Document, updates: &Document, documents: &[Document]) -> Result<i64> {
        self.update_documents_impl(collection, updates, documents).await
    }

    async fn upsert_documents(
        &self,
        collection: &Document,
        attribute: &str,
        changes: Vec<Change>,
    ) -> Result<Vec<Document>> {
        self.upsert_documents_impl(collection, attribute, changes).await
    }

    async fn get_sequences(&self, collection: &str, documents: Vec<Document>) -> Result<Vec<Document>> {
        self.get_sequences_impl(collection, documents).await
    }

    async fn delete_document(&self, collection: &str, id: &str) -> Result<bool> {
        self.delete_document_impl(collection, id).await
    }

    async fn delete_documents(&self, collection: &str, sequences: &[Value], permission_ids: &[String]) -> Result<i64> {
        self.delete_documents_impl(collection, sequences, permission_ids).await
    }

    async fn increase_document_attribute(
        &self,
        collection: &str,
        id: &str,
        attribute: &str,
        value: &Value,
        updated_at: &str,
        min: Option<&Value>,
        max: Option<&Value>,
    ) -> Result<bool> {
        self.increase_impl(collection, id, attribute, value, updated_at, min, max).await
    }

    async fn find(&self, collection: &Document, queries: &[Query], options: FindOptions<'_>) -> Result<Vec<Document>> {
        self.find_impl(collection, queries, options).await
    }

    async fn sum(&self, collection: &Document, attribute: &str, queries: &[Query], max: Option<i64>) -> Result<Value> {
        self.sum_impl(collection, attribute, queries, max).await
    }

    async fn count(&self, collection: &Document, queries: &[Query], max: Option<i64>) -> Result<i64> {
        self.count_impl(collection, queries, max).await
    }

    fn casting_before(&self, _collection: &Document, document: Document) -> Result<Document> {
        Ok(document)
    }

    fn casting_after(&self, _collection: &Document, document: Document) -> Result<Document> {
        Ok(document)
    }

    fn set_utc_datetime(&self, value: &str) -> Result<Value> {
        Ok(Value::Str(value.into()))
    }

    fn decode_point(&self, wkb: &[u8]) -> Result<Value> {
        spatial::decode_point(wkb)
    }

    fn decode_linestring(&self, wkb: &[u8]) -> Result<Value> {
        spatial::decode_linestring(wkb)
    }

    fn decode_polygon(&self, wkb: &[u8]) -> Result<Value> {
        spatial::decode_polygon(wkb)
    }

    fn tenant_query(&self, collection: &str, alias: &str) -> String {
        self.tenant_query_with(collection, alias, 0, "AND")
    }

    fn limit_for_string(&self) -> i64 {
        4294967295
    }

    fn limit_for_int(&self) -> i64 {
        4294967295
    }

    fn limit_for_big_int(&self) -> i64 {
        Database::MAX_BIG_INT
    }

    fn limit_for_attributes(&self) -> i64 {
        1017
    }

    fn limit_for_indexes(&self) -> i64 {
        64
    }

    fn max_index_length(&self) -> i64 {
        if self.shared() { 767 } else { 768 }
    }

    fn max_varchar_length(&self) -> i64 {
        sql::MAX_VARCHAR_LENGTH
    }

    fn max_uid_length(&self) -> i64 {
        36
    }

    fn min_date_time(&self) -> DateTime {
        DateTime::new(b"-4713-01-01 00:00:00", None, &TzInfo::utc()).unwrap_or_else(|_| unreachable!("a valid date"))
    }

    fn id_attribute_type(&self) -> &'static str {
        Database::VAR_INTEGER
    }

    fn count_of_attributes(&self, collection: &Document) -> i64 {
        count_of(collection, "attributes") + self.count_of_default_attributes()
    }

    fn count_of_indexes(&self, collection: &Document) -> i64 {
        count_of(collection, "indexes") + self.count_of_default_indexes()
    }

    fn count_of_default_attributes(&self) -> i64 {
        Database::internal_attributes_const().len() as i64
    }

    fn count_of_default_indexes(&self) -> i64 {
        Database::INTERNAL_INDEXES.len() as i64
    }

    fn document_size_limit(&self) -> i64 {
        65535
    }

    fn attribute_width(&self, collection: &Document) -> Result<i64> {
        ddl::attribute_width(collection)
    }

    fn keywords(&self) -> &'static [&'static str] {
        ddl::KEYWORDS
    }

    fn internal_indexes_keys(&self) -> &'static [&'static str] {
        &[]
    }

    fn support_for_schemas(&self) -> bool {
        true
    }
    fn support_for_attributes(&self) -> bool {
        true
    }
    fn set_support_for_attributes(&self, _support: bool) -> bool {
        true
    }
    fn support_for_schema_attributes(&self) -> bool {
        false
    }
    fn support_for_schema_indexes(&self) -> bool {
        false
    }
    fn support_for_index(&self) -> bool {
        true
    }
    fn support_for_index_array(&self) -> bool {
        true
    }
    fn support_for_cast_index_array(&self) -> bool {
        false
    }
    fn support_for_unique_index(&self) -> bool {
        true
    }
    fn support_for_fulltext_index(&self) -> bool {
        true
    }
    fn support_for_fulltext_wildcard_index(&self) -> bool {
        false
    }
    fn support_for_casting(&self) -> bool {
        true
    }
    fn support_for_query_contains(&self) -> bool {
        true
    }
    fn support_for_timeouts(&self) -> bool {
        true
    }
    fn support_for_relationships(&self) -> bool {
        true
    }
    fn support_for_update_lock(&self) -> bool {
        true
    }
    fn support_for_batch_operations(&self) -> bool {
        true
    }
    fn support_for_attribute_resizing(&self) -> bool {
        true
    }
    fn support_for_get_connection_id(&self) -> bool {
        true
    }
    fn support_for_upserts(&self) -> bool {
        true
    }
    fn support_for_upsert_on_unique_index(&self) -> bool {
        false
    }
    fn support_for_vectors(&self) -> bool {
        true
    }
    fn support_for_cache_skip_on_failure(&self) -> bool {
        true
    }
    fn support_for_caching(&self) -> bool {
        true
    }
    fn support_for_reconnection(&self) -> bool {
        true
    }
    fn support_for_hostname(&self) -> bool {
        true
    }
    fn support_for_batch_create_attributes(&self) -> bool {
        true
    }
    fn support_for_spatial_attributes(&self) -> bool {
        true
    }
    fn support_for_object(&self) -> bool {
        true
    }
    fn support_for_object_indexes(&self) -> bool {
        true
    }
    fn support_for_spatial_index_null(&self) -> bool {
        true
    }
    fn support_for_operators(&self) -> bool {
        true
    }
    fn support_for_optional_spatial_attribute_with_existing_rows(&self) -> bool {
        false
    }
    fn support_for_spatial_index_order(&self) -> bool {
        false
    }
    fn support_for_spatial_axis_order(&self) -> bool {
        false
    }
    fn support_for_boundary_inclusive_contains(&self) -> bool {
        true
    }
    fn support_for_distance_between_multi_dimension_geometry_in_meters(&self) -> bool {
        true
    }
    fn support_for_multiple_fulltext_indexes(&self) -> bool {
        true
    }
    fn support_for_identical_indexes(&self) -> bool {
        true
    }
    fn support_for_order_random(&self) -> bool {
        true
    }
    fn support_for_internal_casting(&self) -> bool {
        false
    }
    fn support_for_utc_casting(&self) -> bool {
        false
    }
    fn support_for_integer_booleans(&self) -> bool {
        false
    }
    fn support_for_alter_locks(&self) -> bool {
        false
    }
    fn support_non_utf_characters(&self) -> bool {
        false
    }
    fn support_for_trigram_index(&self) -> bool {
        true
    }
    fn support_for_pcre_regex(&self) -> bool {
        false
    }
    fn support_for_posix_regex(&self) -> bool {
        true
    }
    fn support_for_ttl_indexes(&self) -> bool {
        false
    }
    fn support_for_transaction_retries(&self) -> bool {
        true
    }
    fn support_for_nested_transactions(&self) -> bool {
        true
    }
}

/// `\count($collection->getAttribute($key) ?? [])`.
fn count_of(collection: &Document, key: &str) -> i64 {
    match collection.get(key) {
        Some(Value::Array(a)) => a.len() as i64,
        Some(Value::Ext(crate::value::Ext::Document(d))) => d.len() as i64,
        _ => 0,
    }
}

impl Postgres {
    /// `startTransaction()`: `BEGIN` at depth 0 (after a `ROLLBACK` of any
    /// transaction the connection still holds), a savepoint deeper.
    async fn start_transaction_impl(&self) -> Result<bool> {
        let depth = self.depth();
        let mut pdo = self.pdo.lock().await;
        let result = if depth == 0 {
            // Best-effort cleanup; its failure is swallowed.
            if pdo.in_transaction() {
                let _ = pdo.rollback().await;
            } else {
                let _ = pdo.execute(&pdo::Statement::new("ROLLBACK")).await;
            }
            pdo.begin().await
        } else {
            pdo.exec(&format!("SAVEPOINT transaction{depth}")).await.map(|_| ())
        };
        drop(pdo);
        if let Err(e) = result {
            return Err(Error::transaction(format!("Failed to start transaction: {}", e.message)));
        }
        self.state.lock().in_transaction += 1;
        Ok(true)
    }

    /// `commitTransaction()`.
    async fn commit_transaction_impl(&self) -> Result<bool> {
        let depth = self.depth();
        if depth == 0 {
            return Ok(false);
        }
        let mut pdo = self.pdo.lock().await;
        if !pdo.in_transaction() {
            self.state.lock().in_transaction = 0;
            return Ok(false);
        }
        if depth > 1 {
            self.state.lock().in_transaction -= 1;
            return Ok(true);
        }
        match pdo.commit().await {
            Ok(()) => {
                self.state.lock().in_transaction = 0;
                Ok(true)
            }
            Err(e) => Err(Error::transaction(format!("Failed to commit transaction: {}", e.message))),
        }
    }

    /// `rollbackTransaction()` (Postgres): to the savepoint when nested,
    /// `ROLLBACK` otherwise.
    async fn rollback_transaction_impl(&self) -> Result<bool> {
        let depth = self.depth();
        if depth == 0 {
            return Ok(false);
        }
        let mut pdo = self.pdo.lock().await;
        let result = if depth > 1 {
            pdo.exec(&format!("ROLLBACK TO transaction{}", depth - 1)).await.map(|_| ())
        } else {
            pdo.rollback().await
        };
        drop(pdo);
        match result {
            Ok(()) => {
                let mut s = self.state.lock();
                if depth > 1 {
                    s.in_transaction -= 1;
                } else {
                    s.in_transaction = 0;
                }
                Ok(true)
            }
            Err(e) => {
                self.state.lock().in_transaction = 0;
                Err(Error::database(format!("Failed to rollback transaction: {}", e.message)))
            }
        }
    }
}
