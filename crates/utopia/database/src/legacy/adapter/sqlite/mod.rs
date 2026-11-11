//! [`Sqlite`]: the SQLite adapter.
//!
//! One adapter owns one SQLite database file, like PHP's PDO handle: every
//! collection is a table of that file named `<namespace>_<collection>` (SQLite
//! has no schemas, so the database name only reaches cache keys), a
//! transaction is per adapter (`BEGIN IMMEDIATE`), nested transactions are
//! savepoints, and the settings (namespace, tenant, statement transforms,
//! authorization) live in its [`State`].
//!
//! It writes the SQL PHP's adapter writes, byte for byte (the text
//! [`Adapter::before`] transforms see), and runs it the way PHP's PDO sqlite
//! driver does (see [`pdo`]), so tables, indexes, rows, results and error
//! messages are the ones PHP's adapter produces on the same file. Fulltext
//! indexes are FTS5 virtual tables kept in sync by triggers; regular
//! expressions run through a `REGEXP` function with PHP's PCRE semantics.
//!
//! ```no_run
//! # async fn demo() -> utopia_database::Result<()> {
//! use utopia_database::Adapter;
//! use utopia_database::adapter::sqlite::Sqlite;
//!
//! let adapter = Sqlite::open("/tmp/app.sqlite")?;
//! adapter.set_namespace("_1")?;
//! adapter.create("appwrite").await?;
//! # Ok(()) }
//! ```
//!
//! PHP: `Utopia\Database\Adapter\SQLite` and the parts of
//! `Utopia\Database\Adapter\MariaDB` and `Utopia\Database\Adapter\SQL` it
//! inherits, over `Utopia\Database\PDO` with the sqlite driver.

use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::{Mutex, MutexGuard, OnceLock};

use async_trait::async_trait;
use php_std::datetime::{DateTime, TzInfo};
use rusqlite::functions::FunctionFlags;
use rusqlite::types::ValueRef;

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
mod sql;

pub use sql::{process_exception, violated_columns};

/// `SQLite::REGEXP_MAX_PATTERN_LENGTH`: longer patterns never match.
const REGEXP_MAX_PATTERN_LENGTH: usize = 512;

/// The SQLite adapter. See the [module docs](self).
///
/// PHP: `Utopia\Database\Adapter\SQLite` (extends `Adapter\MariaDB`).
pub struct Sqlite {
    state: State,
    path: String,
    pdo: Mutex<pdo::Pdo>,
    float_precision: Mutex<i64>,
    /// `$emulateMySQL`.
    emulate_mysql: AtomicBool,
    /// `$pcreRegistered`: whether the `REGEXP` function was registered.
    pcre_registered: bool,
    /// `$ftsTableCache`: collection => FTS5 column => table.
    fts_tables: Mutex<FtsTables>,
}

/// `$ftsTableCache`: per collection, its FTS5 tables by column.
type FtsTables = Vec<(String, Vec<(String, String)>)>;

impl Sqlite {
    /// Opens (or creates) the database file at `path`.
    ///
    /// PHP: `new SQLite(new PDO("sqlite:$path", null, null, SQLite::getPDOAttributes()))`.
    pub fn open(path: &str) -> Result<Sqlite> {
        let pdo = pdo::Pdo::open(path)?;
        let pcre_registered = register_functions(&pdo);
        Ok(Sqlite {
            state: State::default(),
            path: path.to_owned(),
            pdo: Mutex::new(pdo),
            float_precision: Mutex::new(17),
            emulate_mysql: AtomicBool::new(false),
            pcre_registered,
            fts_tables: Mutex::new(Vec::new()),
        })
    }

    /// The file the adapter opened.
    pub fn path(&self) -> &str {
        &self.path
    }

    /// `setEmulateMySQL($emulate)`: report MariaDB-shaped column metadata,
    /// MariaDB's resizing checks and `_tenant` type.
    ///
    /// PHP: `Utopia\Database\Adapter\SQLite::setEmulateMySQL`.
    pub fn set_emulate_mysql(&self, emulate: bool) -> &Self {
        self.emulate_mysql.store(emulate, Ordering::Relaxed);
        self
    }

    /// `getEmulateMySQL()`.
    ///
    /// PHP: `Utopia\Database\Adapter\SQLite::getEmulateMySQL`.
    pub fn emulate_mysql(&self) -> bool {
        self.emulate_mysql.load(Ordering::Relaxed)
    }

    /// `setFloatPrecision($precision)`: the fractional digits floats are
    /// bound with in finds (17 by default).
    ///
    /// PHP: `Utopia\Database\Adapter\SQL::setFloatPrecision`.
    pub fn set_float_precision(&self, precision: i64) {
        *lock(&self.float_precision) = precision;
    }

    /// `getFloatPrecision($value)`: `sprintf('%.{precision}F', $value)`.
    fn float_literal(&self, value: f64) -> Result<Value> {
        let precision = *lock(&self.float_precision);
        let format = format!("%.{precision}F");
        let out = php_std::format::sprintf(format.as_bytes(), &[php_std::format::Arg::Float(value)])
            .map_err(|e| Error::new(crate::Kind::ValueError, e.message().to_owned()))?;
        Ok(Value::Str(crate::value::Str::from(out)))
    }

    /// `getSupportForNumericCasting()`: `true`.
    ///
    /// PHP: `Utopia\Database\Adapter\MariaDB::getSupportForNumericCasting`.
    pub fn support_for_numeric_casting(&self) -> bool {
        true
    }

    /// `getSupportForJSONOverlaps()`: `false` (SQLite has no `JSON_OVERLAPS`).
    ///
    /// PHP: `Utopia\Database\Adapter\SQLite::getSupportForJSONOverlaps`.
    pub fn support_for_json_overlaps(&self) -> bool {
        false
    }

    /// `getLikeOperator()`: `LIKE`.
    ///
    /// PHP: `Utopia\Database\Adapter\SQL::getLikeOperator`.
    pub fn like_operator(&self) -> &'static str {
        "LIKE"
    }

    /// `getRegexOperator()`: `REGEXP`.
    ///
    /// PHP: `Utopia\Database\Adapter\SQL::getRegexOperator`.
    pub fn regex_operator(&self) -> &'static str {
        "REGEXP"
    }

    /// `getLockType()`: `''` (SQLite has no `ALTER ... LOCK`).
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
        sql::type_from_wkt(wkt)
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
        let out = self.conditions(queries, &mut b, separator, None);
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
        self.pdo().exec(sql).map_err(pdo::PdoError::into_error)
    }

    /// `getDriver()->prepare($sql)->execute()` then `fetchAll()`: the rows,
    /// every value a string (or `null`), as PHP's PDO fetches them.
    ///
    /// PHP: `Utopia\Database\Adapter\SQL::getDriver` (the PDO handle).
    pub async fn driver_query(&self, sql: &str) -> Result<Vec<Array>> {
        let r = self.run(&pdo::Statement::new(sql)).map_err(sql::raw)?;
        Ok(r.rows)
    }

    /// The PDO handle, locked for the duration of one call (never across
    /// an await).
    fn pdo(&self) -> MutexGuard<'_, pdo::Pdo> {
        lock(&self.pdo)
    }

    /// Clears `$ftsTableCache` (all collections).
    fn forget_fts_tables(&self) {
        lock(&self.fts_tables).clear();
    }

    /// `getSupportForMathFunctions()`: whether `POWER()` works, checked
    /// once per process (PHP's function-static cache).
    fn support_for_math_functions(&self) -> bool {
        static AVAILABLE: OnceLock<bool> = OnceLock::new();
        *AVAILABLE.get_or_init(|| match self.run(&pdo::Statement::new("SELECT POWER(2, 3) as test")) {
            Ok(r) => r.rows.first().and_then(|row| row.values().next()).is_some_and(|v| v.loose_eq(&Value::Int(8))),
            Err(_) => false,
        })
    }
}

fn lock<T>(m: &Mutex<T>) -> MutexGuard<'_, T> {
    m.lock().unwrap_or_else(|e| e.into_inner())
}

/// `registerUserFunctions()`: the `REGEXP` function SQLite's `REGEXP`
/// operator calls, matching with PHP's `preg_match` (a pattern longer than
/// 512 bytes, or one that fails, never matches). Returns whether it was
/// registered (`$pcreRegistered`).
fn register_functions(pdo: &pdo::Pdo) -> bool {
    let regexp = |ctx: &rusqlite::functions::Context<'_>| -> rusqlite::Result<i64> {
        let (Some(pattern), Some(value)) = (udf_string(ctx.get_raw(0)), udf_string(ctx.get_raw(1))) else {
            return Ok(0);
        };
        if pattern.len() > REGEXP_MAX_PATTERN_LENGTH {
            return Ok(0);
        }
        let mut delimited = Vec::with_capacity(pattern.len() + 3);
        delimited.push(0x01);
        delimited.extend_from_slice(&pattern);
        delimited.extend_from_slice(b"\x01u");
        Ok(match php_std::pcre::preg_match_bare(&delimited, &value) {
            Ok(r) if matches!(r.value, php_std::pcre::Value::Int(1)) => 1,
            _ => 0,
        })
    };
    pdo.connection().create_scalar_function("REGEXP", 2, FunctionFlags::SQLITE_UTF8, regexp).is_ok()
}

/// A UDF argument as the PHP closure's `?string` parameter receives it.
fn udf_string(value: ValueRef<'_>) -> Option<Vec<u8>> {
    match value {
        ValueRef::Null => None,
        ValueRef::Integer(i) => Some(i.to_string().into_bytes()),
        ValueRef::Real(f) => Some(php_std::number::to_string(f).into_bytes()),
        ValueRef::Text(t) | ValueRef::Blob(t) => Some(t.to_vec()),
    }
}

#[async_trait]
impl Adapter for Sqlite {
    fn state(&self) -> &State {
        &self.state
    }

    /// `setNamespace()`: also forgets the FTS5 tables found so far.
    fn set_namespace(&self, namespace: &str) -> Result<()> {
        let filtered = self.filter(namespace)?;
        self.state.lock().namespace = filtered;
        self.forget_fts_tables();
        Ok(())
    }

    /// `setSharedTables()`: also forgets the FTS5 tables when it changes.
    fn set_shared_tables(&self, shared: bool) -> bool {
        let changed = std::mem::replace(&mut self.state.lock().shared_tables, shared) != shared;
        if changed {
            self.forget_fts_tables();
        }
        true
    }

    /// `setTenant()`: also forgets the FTS5 tables when it changes.
    fn set_tenant(&self, tenant: Value) -> bool {
        let changed = {
            let mut s = self.state.lock();
            let changed = !s.tenant.strict_eq(&tenant);
            s.tenant = tenant;
            changed
        };
        if changed {
            self.forget_fts_tables();
        }
        true
    }

    /// `getHostname()`: `''` (a file has no host).
    fn hostname(&self) -> String {
        String::new()
    }

    /// `setTimeout()`: nothing (SQLite enforces no statement timeout).
    fn set_timeout(&self, _milliseconds: i64, _event: &str) -> Result<()> {
        Ok(())
    }

    fn column_type(&self, kind: &str, size: i64, signed: bool, array: bool, required: bool) -> Result<String> {
        sql::sql_type(kind, size, signed, array, required)
    }

    async fn start_transaction(&self) -> Result<bool> {
        self.start_transaction_impl()
    }

    async fn commit_transaction(&self) -> Result<bool> {
        self.commit_transaction_impl()
    }

    async fn rollback_transaction(&self) -> Result<bool> {
        self.rollback_transaction_impl()
    }

    async fn ping(&self) -> Result<bool> {
        self.run(&pdo::Statement::new("SELECT 1;")).map(|r| r.ok).map_err(sql::raw)
    }

    /// `reconnect()`: a new connection to the file. As in PHP, the
    /// `REGEXP` function registered on the old one is not registered again.
    async fn reconnect(&self) -> Result<()> {
        let fresh = pdo::Pdo::open(&self.path)?;
        *self.pdo() = fresh;
        self.state.lock().in_transaction = 0;
        Ok(())
    }

    async fn connection_id(&self) -> Result<String> {
        let r = self.run(&pdo::Statement::new("SELECT CONNECTION_ID();")).map_err(sql::raw)?;
        Ok(r.column().map(|v| crate::value::to_text(&v)).unwrap_or_default())
    }

    async fn create(&self, _name: &str) -> Result<bool> {
        Ok(true)
    }

    async fn exists(&self, database: &str, collection: Option<&str>) -> Result<bool> {
        self.exists_impl(database, collection)
    }

    async fn list(&self) -> Result<Vec<Document>> {
        Ok(Vec::new())
    }

    async fn delete(&self, _name: &str) -> Result<bool> {
        Ok(true)
    }

    async fn create_collection(&self, name: &str, attributes: &[Document], indexes: &[Document]) -> Result<bool> {
        self.create_collection_impl(name, attributes, indexes).await
    }

    async fn delete_collection(&self, id: &str) -> Result<bool> {
        self.delete_collection_impl(id)
    }

    async fn analyze_collection(&self, _collection: &str) -> Result<bool> {
        Ok(false)
    }

    async fn size_of_collection(&self, collection: &str) -> Result<i64> {
        self.collection_size(collection)
    }

    async fn size_of_collection_on_disk(&self, collection: &str) -> Result<i64> {
        self.collection_size(collection)
    }

    async fn schema_attributes(&self, collection: &str) -> Result<Vec<Document>> {
        self.schema_attributes_impl(collection)
    }

    async fn schema_indexes(&self, collection: &str) -> Result<Vec<Document>> {
        self.schema_indexes_impl(collection).await
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
        self.create_attribute_impl(collection, id, kind, size, signed, array, required)
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
        self.update_attribute_impl(collection, id, kind, size, signed, array, new_key, required)
    }

    async fn delete_attribute(&self, collection: &str, id: &str) -> Result<bool> {
        self.delete_attribute_impl(collection, id).await
    }

    async fn rename_attribute(&self, collection: &str, old: &str, new: &str) -> Result<bool> {
        self.rename_attribute_impl(collection, old, new)
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
        self.create_relationship_impl(collection, related_collection, kind, two_way, id, two_way_key)
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
        _attribute_types: &[(String, String)],
        _collation: &[String],
        _ttl: i64,
    ) -> Result<bool> {
        self.create_index_impl(collection, id, kind, attributes, lengths, orders)
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
        self.get_document_impl(collection, &Value::Str(id.into()), queries, for_update)
    }

    async fn create_document(&self, collection: &Document, document: Document) -> Result<Document> {
        self.create_document_impl(collection, document)
    }

    async fn create_documents(&self, collection: &Document, documents: Vec<Document>) -> Result<Vec<Document>> {
        self.create_documents_impl(collection, documents)
    }

    async fn update_document(
        &self,
        collection: &Document,
        id: &str,
        document: Document,
        skip_permissions: bool,
    ) -> Result<Document> {
        self.update_document_impl(collection, id, document, skip_permissions)
    }

    async fn update_documents(&self, collection: &Document, updates: &Document, documents: &[Document]) -> Result<i64> {
        self.update_documents_impl(collection, updates, documents)
    }

    async fn upsert_documents(
        &self,
        collection: &Document,
        attribute: &str,
        changes: Vec<Change>,
    ) -> Result<Vec<Document>> {
        self.upsert_documents_impl(collection, attribute, changes)
    }

    async fn get_sequences(&self, collection: &str, documents: Vec<Document>) -> Result<Vec<Document>> {
        self.get_sequences_impl(collection, documents)
    }

    async fn delete_document(&self, collection: &str, id: &str) -> Result<bool> {
        self.delete_document_impl(collection, id)
    }

    async fn delete_documents(&self, collection: &str, sequences: &[Value], permission_ids: &[String]) -> Result<i64> {
        self.delete_documents_impl(collection, sequences, permission_ids)
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
        self.increase_impl(collection, id, attribute, value, updated_at, min, max)
    }

    async fn find(&self, collection: &Document, queries: &[Query], options: FindOptions<'_>) -> Result<Vec<Document>> {
        self.find_impl(collection, queries, options)
    }

    async fn sum(&self, collection: &Document, attribute: &str, queries: &[Query], max: Option<i64>) -> Result<Value> {
        self.sum_impl(collection, attribute, queries, max)
    }

    async fn count(&self, collection: &Document, queries: &[Query], max: Option<i64>) -> Result<i64> {
        self.count_impl(collection, queries, max)
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
        sql::decode_point(wkb)
    }

    fn decode_linestring(&self, wkb: &[u8]) -> Result<Value> {
        sql::decode_linestring(wkb)
    }

    fn decode_polygon(&self, wkb: &[u8]) -> Result<Value> {
        sql::decode_polygon(wkb)
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
        DateTime::new(b"1000-01-01 00:00:00", None, &TzInfo::utc()).unwrap_or_else(|_| unreachable!("a valid date"))
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
        &["primary", "_created_at", "_updated_at", "_tenant_id"]
    }

    fn support_for_schemas(&self) -> bool {
        false
    }
    fn support_for_attributes(&self) -> bool {
        true
    }
    fn set_support_for_attributes(&self, _support: bool) -> bool {
        true
    }
    fn support_for_schema_attributes(&self) -> bool {
        true
    }
    fn support_for_schema_indexes(&self) -> bool {
        true
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
        false
    }
    fn support_for_timeouts(&self) -> bool {
        false
    }
    fn support_for_relationships(&self) -> bool {
        true
    }
    fn support_for_update_lock(&self) -> bool {
        false
    }
    fn support_for_batch_operations(&self) -> bool {
        true
    }
    fn support_for_attribute_resizing(&self) -> bool {
        self.emulate_mysql()
    }
    fn support_for_get_connection_id(&self) -> bool {
        false
    }
    fn support_for_upserts(&self) -> bool {
        true
    }
    fn support_for_upsert_on_unique_index(&self) -> bool {
        false
    }
    fn support_for_vectors(&self) -> bool {
        false
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
        false
    }
    fn support_for_batch_create_attributes(&self) -> bool {
        true
    }
    fn support_for_spatial_attributes(&self) -> bool {
        false
    }
    fn support_for_object(&self) -> bool {
        false
    }
    fn support_for_object_indexes(&self) -> bool {
        false
    }
    fn support_for_spatial_index_null(&self) -> bool {
        false
    }
    fn support_for_operators(&self) -> bool {
        true
    }
    fn support_for_optional_spatial_attribute_with_existing_rows(&self) -> bool {
        true
    }
    fn support_for_spatial_index_order(&self) -> bool {
        false
    }
    fn support_for_spatial_axis_order(&self) -> bool {
        false
    }
    fn support_for_boundary_inclusive_contains(&self) -> bool {
        false
    }
    fn support_for_distance_between_multi_dimension_geometry_in_meters(&self) -> bool {
        false
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
    fn support_for_unsigned_big_int(&self) -> bool {
        false
    }
    fn support_for_internal_casting(&self) -> bool {
        false
    }
    fn support_for_utc_casting(&self) -> bool {
        false
    }
    fn support_for_integer_booleans(&self) -> bool {
        true
    }
    fn support_for_alter_locks(&self) -> bool {
        false
    }
    fn support_non_utf_characters(&self) -> bool {
        false
    }
    fn support_for_trigram_index(&self) -> bool {
        false
    }
    fn support_for_pcre_regex(&self) -> bool {
        self.pcre_registered
    }
    fn support_for_posix_regex(&self) -> bool {
        false
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

impl Sqlite {
    /// `startTransaction()` (SQLite): `BEGIN IMMEDIATE` at depth 0 (after a
    /// `ROLLBACK` of any transaction the connection still holds), a
    /// savepoint deeper.
    fn start_transaction_impl(&self) -> Result<bool> {
        let depth = self.depth();
        let result = {
            let pdo = self.pdo();
            if depth == 0 {
                let rolled_back = if pdo.in_transaction() {
                    pdo.execute(&pdo::Statement::new("ROLLBACK")).map(|_| ())
                } else {
                    Ok(())
                };
                rolled_back.and_then(|()| pdo.execute(&pdo::Statement::new("BEGIN IMMEDIATE")).map(|_| ()))
            } else {
                pdo.execute(&pdo::Statement::new(format!("SAVEPOINT transaction{depth}"))).map(|_| ())
            }
        };
        if let Err(e) = result {
            return Err(match e {
                pdo::PdoFailure::Pdo(e) => Error::transaction(format!("Failed to start transaction: {}", e.message)),
                pdo::PdoFailure::Other(e) => e,
            });
        }
        self.state.lock().in_transaction += 1;
        Ok(true)
    }

    /// `commitTransaction()` (SQLite): `RELEASE SAVEPOINT` when nested,
    /// `COMMIT` otherwise.
    fn commit_transaction_impl(&self) -> Result<bool> {
        let depth = self.depth();
        if depth == 0 {
            return Ok(false);
        }
        let pdo = self.pdo();
        if depth > 1 {
            let r = pdo.execute(&pdo::Statement::new(format!("RELEASE SAVEPOINT transaction{}", depth - 1)));
            return match r {
                Ok(r) => {
                    self.state.lock().in_transaction -= 1;
                    Ok(r.ok)
                }
                Err(pdo::PdoFailure::Pdo(e)) => {
                    Err(Error::transaction(format!("Failed to commit transaction: {}", e.message)))
                }
                Err(pdo::PdoFailure::Other(e)) => Err(e),
            };
        }
        match pdo.execute(&pdo::Statement::new("COMMIT")) {
            Ok(r) => {
                self.state.lock().in_transaction = 0;
                Ok(r.ok)
            }
            Err(pdo::PdoFailure::Pdo(e)) => {
                Err(Error::transaction(format!("Failed to commit transaction: {}", e.message)))
            }
            Err(pdo::PdoFailure::Other(e)) => Err(e),
        }
    }

    /// `rollbackTransaction()` (SQLite): to the savepoint when nested,
    /// `ROLLBACK` otherwise.
    fn rollback_transaction_impl(&self) -> Result<bool> {
        let depth = self.depth();
        if depth == 0 {
            return Ok(false);
        }
        let pdo = self.pdo();
        let sql = if depth > 1 { format!("ROLLBACK TO transaction{}", depth - 1) } else { "ROLLBACK".to_owned() };
        match pdo.execute(&pdo::Statement::new(sql)) {
            Ok(_) => {
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
                Err(match e {
                    pdo::PdoFailure::Pdo(e) => {
                        Error::database(format!("Failed to rollback transaction: {}", e.message))
                    }
                    pdo::PdoFailure::Other(e) => e,
                })
            }
        }
    }
}
