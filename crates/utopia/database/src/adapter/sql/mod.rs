//! The SQL adapters: PostgreSQL, MariaDB, MySQL and SQLite over one shared
//! base, as PHP's `Postgres`, `MariaDB`, `MySQL` and `SQLite` extend
//! `Adapter\SQL` (and `MySQL` extends `MariaDB`).
//!
//! One type, [`Sql`], is every SQL adapter: its [`Engine`] picks what PHP's
//! subclasses override. Each area of the adapter lives in its own module,
//! as inherent methods of [`Sql`] the [`Adapter`] implementation forwards
//! to:
//!
//! | Module | PHP |
//! |---|---|
//! | `exec` | statement preparation, bindings, execution, profiling, comments, transforms, timeouts |
//! | `errors` | `processException()`, `processSelectException()` per engine |
//! | `transaction` | `startTransaction()`, `commitTransaction()`, `rollbackTransaction()`, `abandonTransaction()` |
//! | `tables` | `getTable()`, `getTableRaw()`, `qualifyTable()`, `dialectBuilder()`, `builder()`, `schema()`, `newBuilder()`, the scopes and permission hooks, `syncWriteHooks()`, `writeContext()` |
//! | `scope` | `Adapter\SQL\Scope\{Raw, Filter}` |
//! | `row` | `remapRow()` |
//! | `limits` | `capabilities()`, `limits()`, `getKeywords()`, `getAttributeWidth()`, `getMaxPointSize()` |
//! | `database` | `create()`, `update()`, `exists()`, `list()`, `delete()`, `id()`, `ping()`, `reconnect()` |
//! | `collection` | `createCollection()`, `deleteCollection()`, `analyzeCollection()`, sizes |
//! | `attribute` | attributes, column types (`getSqlType()`, `getColumnType()`, `addTableColumn()`, ...) |
//! | `index` | indexes (and SQLite's FTS5 tables) |
//! | `relationship` | `createRelationship()`, `updateRelationship()`, `deleteRelationship()` |
//! | `introspection` | `getSchemaAttributes()`, `getSchemaIndexes()`, `getColumnNames()` |
//! | `spatial` | WKT, `encode()`, `decode()`, spatial SQL types |
//! | `documents` | document writes and `getDocument()` |
//! | `operator` | operator SQL and bindings |
//! | `upsert` | `upsertDocument(s)`, `executeUpsertBatch()` |
//! | `find` | `find()`, cursors, pages, joins, full outer join emulation |
//! | `aggregate` | `count()`, `sum()`, aggregates |
//!
//! PHP: `Utopia\Database\Adapter\SQL` and its subclasses, `Adapter\SQL\*`,
//! `Utopia\Database\PDO`, `Utopia\Database\PDOStatement`.

use std::any::Any;
use std::sync::{Arc, Mutex};

use async_trait::async_trait;
use utopia_query::schema::{ColumnType, IndexType};

use crate::adapter::{
    Adapter, Connection, Find, Limits, QueryBuilder, RawQuery, Relationships, Spatial, SqlSchema, State, TimeoutState,
    Timeouts, Upserts,
};
use crate::attribute::Attribute;
use crate::capability::Capabilities;
use crate::change::Change;
use crate::collection::Collection;
use crate::document::Document;
use crate::error::Result;
use crate::event::Event;
use crate::hook::SqlBuilder;
use crate::index::Index;
use crate::query::Query;
use crate::relationship::{self, Relationship, RelationshipSide};
use crate::schema;
use crate::value::Value;

pub mod hook;
pub mod pdo;

mod aggregate;
mod attribute;
mod collection;
mod database;
mod documents;
mod errors;
mod exec;
mod find;
mod fts;
mod index;
mod introspection;
mod limits;
mod operator;
mod relationship_storage;
mod row;
pub mod scope;
mod spatial;
mod tables;
mod transaction;
mod upsert;

pub use exec::Prepared;

/// The engine a [`Sql`] adapter speaks to: which PHP subclass of
/// `Adapter\SQL` it is.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum Engine {
    /// `Utopia\Database\Adapter\Postgres`.
    Postgres,
    /// `Utopia\Database\Adapter\MariaDB`.
    MariaDb,
    /// `Utopia\Database\Adapter\MySQL` (extends `MariaDB`).
    MySql,
    /// `Utopia\Database\Adapter\SQLite`.
    Sqlite,
}

impl Engine {
    /// The PHP class.
    pub fn php_class(self) -> &'static str {
        match self {
            Engine::Postgres => "Utopia\\Database\\Adapter\\Postgres",
            Engine::MariaDb => "Utopia\\Database\\Adapter\\MariaDB",
            Engine::MySql => "Utopia\\Database\\Adapter\\MySQL",
            Engine::Sqlite => "Utopia\\Database\\Adapter\\SQLite",
        }
    }

    /// Whether the engine is MariaDB or MySQL (PHP: `instanceof MariaDB`).
    pub fn is_mariadb_family(self) -> bool {
        matches!(self, Engine::MariaDb | Engine::MySql)
    }
}

/// SQLite's FTS5 table of each attribute of a collection (`null` when none
/// covers it), as `$ftsTableCache` keeps it per table prefix.
pub(crate) type FtsAttributeMap = Vec<(String, Option<String>)>;

/// The state PHP's subclasses keep besides the base's.
#[derive(Debug, Default)]
pub(crate) struct EngineState {
    /// MariaDB/MySQL `$appliedTimeout`: the session timeout in force.
    pub applied_timeout: i64,
    /// Postgres `$localTimeout`: the `LOCAL` timeout in force in the open
    /// transaction (`None` when unknown after a rollback to a savepoint).
    pub local_timeout: Option<i64>,
    /// SQLite `$ftsTableCache`: attribute → FTS5 table, per table prefix.
    pub fts_tables: Vec<(String, FtsAttributeMap)>,
    /// SQLite `$emulateMySQL`.
    pub emulate_mysql: bool,
    /// The metadata the statement comments were last written for, and the
    /// comments (`$commentedMetadata`, `$comments`).
    pub comments: Option<(Vec<(String, Value)>, String)>,
}

/// A SQL adapter: PostgreSQL, MariaDB, MySQL or SQLite (see the [module
/// docs](self)).
///
/// PHP: `Utopia\Database\Adapter\SQL` and its subclasses.
pub struct Sql {
    pub(crate) state: State,
    pub(crate) engine: Engine,
    pub(crate) connection: exec::Connection,
    pub(crate) float_precision: Mutex<i64>,
    pub(crate) timeouts: TimeoutState,
    pub(crate) engine_state: Mutex<EngineState>,
}

impl std::fmt::Debug for Sql {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Sql").field("engine", &self.engine).finish_non_exhaustive()
    }
}

impl Sql {
    /// An adapter of `engine` over a PDO handle (`new Postgres($pdo)`,
    /// ...). SQLite registers its `REGEXP` function on the connection.
    pub fn new(engine: Engine, mut pdo: pdo::Pdo) -> Self {
        if engine == Engine::Sqlite {
            pdo.register_regexp();
        }
        Sql {
            state: State::default(),
            engine,
            connection: utopia_async::sync::Mutex::new(pdo),
            float_precision: Mutex::new(17),
            timeouts: TimeoutState::default(),
            engine_state: Mutex::new(EngineState { local_timeout: Some(0), ..EngineState::default() }),
        }
    }

    /// Connects to a PHP DSN (`pgsql:host=..;port=..`, `mysql:host=..;port=..;charset=utf8mb4`,
    /// `sqlite::memory:`) and builds the adapter of `engine` over it.
    pub async fn connect(engine: Engine, dsn: &str, username: Option<&str>, password: Option<&str>) -> Result<Self> {
        Ok(Sql::new(engine, pdo::Pdo::connect(dsn, username, password).await?))
    }

    /// The connection, for a caller that runs its own statements on it.
    pub fn pdo(&self) -> &utopia_async::sync::Mutex<pdo::Pdo> {
        &self.connection
    }

    /// The engine.
    pub fn engine(&self) -> Engine {
        self.engine
    }

    /// `setFloatPrecision()`: the fractional digits floats are bound with
    /// in finds (17 by default).
    pub fn set_float_precision(&self, precision: i64) {
        *self.float_precision.lock().unwrap_or_else(|e| e.into_inner()) = precision;
    }

    /// SQLite's `$emulateMySQL`: MariaDB-shaped column metadata, attribute
    /// resizing, and MariaDB-style types for internal columns.
    pub fn set_emulate_mysql(&self, emulate: bool) {
        self.engine_state().emulate_mysql = emulate;
    }

    pub(crate) fn engine_state(&self) -> std::sync::MutexGuard<'_, EngineState> {
        self.engine_state.lock().unwrap_or_else(|e| e.into_inner())
    }

    /// `currentTenant()`: the tenant the current future sees.
    pub(crate) fn current_tenant(&self) -> Value {
        self.tenant()
    }
}

#[async_trait]
impl Adapter for Sql {
    fn state(&self) -> &State {
        &self.state
    }

    fn as_any(&self) -> &dyn Any {
        self
    }

    fn capabilities(&self) -> Capabilities {
        Sql::capabilities(self)
    }

    fn limits(&self) -> Arc<Limits> {
        Sql::limits(self)
    }

    fn connection(&self) -> Option<&dyn Connection> {
        Some(self)
    }

    fn query_builder(&self) -> Option<&dyn QueryBuilder> {
        Some(self)
    }

    fn raw_query(&self) -> Option<&dyn RawQuery> {
        Some(self)
    }

    fn relationships(&self) -> Option<&dyn Relationships> {
        Some(self)
    }

    fn spatial(&self) -> Option<&dyn Spatial> {
        (self.engine != Engine::Sqlite).then_some(self as &dyn Spatial)
    }

    fn timeouts(&self) -> Option<&dyn Timeouts> {
        (self.engine != Engine::Sqlite).then_some(self as &dyn Timeouts)
    }

    fn upserts(&self) -> Option<&dyn Upserts> {
        Some(self)
    }

    fn set_tenant(&self, tenant: Value) {
        Sql::set_tenant_impl(self, tenant);
    }

    fn set_namespace(&self, namespace: &str) {
        Sql::set_namespace_impl(self, namespace);
    }

    fn set_shared_tables(&self, shared_tables: bool) {
        Sql::set_shared_tables_impl(self, shared_tables);
    }

    async fn start_transaction(&self) -> Result<bool> {
        Sql::start_transaction(self).await
    }

    async fn commit_transaction(&self) -> Result<bool> {
        Sql::commit_transaction(self).await
    }

    async fn rollback_transaction(&self) -> Result<bool> {
        Sql::rollback_transaction(self).await
    }

    async fn abandon_transaction(&self) {
        Sql::abandon_transaction(self).await
    }

    fn is_transient(&self, error: &crate::Error) -> bool {
        Sql::is_transient(self, error)
    }

    async fn create(&self, name: &str) -> Result<bool> {
        Sql::create(self, name).await
    }

    async fn update(&self, name: &str, new: &str) -> Result<bool> {
        Sql::update(self, name, new).await
    }

    async fn exists(&self, database: &str) -> Result<bool> {
        Sql::exists(self, database).await
    }

    async fn collection_exists(&self, database: &str, collection: &str) -> Result<bool> {
        Sql::collection_exists(self, database, collection).await
    }

    async fn list(&self) -> Result<Vec<Document>> {
        Ok(Vec::new())
    }

    async fn delete(&self, name: &str) -> Result<bool> {
        Sql::delete(self, name).await
    }

    async fn create_collection(&self, collection: &str, attributes: &[Attribute], indexes: &[Index]) -> Result<bool> {
        Sql::create_collection(self, collection, attributes, indexes).await
    }

    async fn delete_collection(&self, collection: &str) -> Result<bool> {
        Sql::delete_collection(self, collection).await
    }

    async fn analyze_collection(&self, collection: &str) -> Result<bool> {
        Sql::analyze_collection(self, collection).await
    }

    async fn size_of_collection(&self, collection: &str) -> Result<i64> {
        Sql::size_of_collection(self, collection).await
    }

    async fn size_of_collection_on_disk(&self, collection: &str) -> Result<i64> {
        Sql::size_of_collection_on_disk(self, collection).await
    }

    fn attribute_width(&self, collection: &Collection) -> Result<i64> {
        Sql::attribute_width(self, collection)
    }

    async fn create_attribute(&self, collection: &str, attribute: &Attribute) -> Result<bool> {
        Sql::create_attribute(self, collection, attribute).await
    }

    async fn create_attributes(&self, collection: &str, attributes: &[Attribute]) -> Result<bool> {
        Sql::create_attributes(self, collection, attributes).await
    }

    async fn update_attribute(&self, collection: &str, key: &str, attribute: &Attribute) -> Result<bool> {
        Sql::update_attribute(self, collection, key, attribute).await
    }

    async fn relax_attribute_required(&self, collection: &str, id: &str) -> Result<bool> {
        Sql::relax_attribute_required(self, collection, id).await
    }

    async fn delete_attribute(&self, collection: &str, key: &str) -> Result<bool> {
        Sql::delete_attribute(self, collection, key).await
    }

    async fn rename_attribute(&self, collection: &str, old: &str, new: &str) -> Result<bool> {
        Sql::rename_attribute(self, collection, old, new).await
    }

    async fn schema_attributes(&self, collection: &str) -> Result<Vec<schema::Column>> {
        Sql::schema_attributes(self, collection).await
    }

    async fn schema_indexes(&self, collection: &str) -> Result<Vec<schema::Index>> {
        Sql::schema_indexes(self, collection).await
    }

    fn schema_index_type(&self, kind: IndexType) -> IndexType {
        Sql::schema_index_type(self, kind)
    }

    fn column_type(&self, attribute: &Attribute) -> Result<Option<String>> {
        Sql::column_type(self, attribute)
    }

    async fn create_index(
        &self,
        collection: &str,
        index: &Index,
        attribute_types: &[(String, ColumnType)],
        collation: &[(String, String)],
    ) -> Result<bool> {
        Sql::create_index(self, collection, index, attribute_types, collation).await
    }

    async fn delete_index(&self, collection: &str, key: &str) -> Result<bool> {
        Sql::delete_index(self, collection, key).await
    }

    async fn rename_index(&self, collection: &str, old: &str, new: &str) -> Result<bool> {
        Sql::rename_index(self, collection, old, new).await
    }

    async fn create_document(&self, collection: &Collection, document: Document) -> Result<Document> {
        Sql::create_document(self, collection, document).await
    }

    async fn create_documents(&self, collection: &Collection, documents: Vec<Document>) -> Result<Vec<Document>> {
        Sql::create_documents(self, collection, documents).await
    }

    async fn get_document(
        &self,
        collection: &Collection,
        id: &str,
        queries: Vec<Query>,
        for_update: bool,
    ) -> Result<Document> {
        Sql::get_document(self, collection, id, queries, for_update).await
    }

    async fn update_document(
        &self,
        collection: &Collection,
        id: &str,
        document: Document,
        skip_permissions: bool,
    ) -> Result<Document> {
        Sql::update_document(self, collection, id, document, skip_permissions).await
    }

    async fn update_documents(
        &self,
        collection: &Collection,
        updates: &Document,
        documents: Vec<Document>,
        skip_permissions: &[String],
    ) -> Result<i64> {
        Sql::update_documents(self, collection, updates, documents, skip_permissions).await
    }

    async fn increase_document_attribute(
        &self,
        collection: &Collection,
        id: &str,
        attribute: &str,
        value: Value,
        updated_at: &str,
        min: Option<Value>,
        max: Option<Value>,
    ) -> Result<bool> {
        Sql::increase_document_attribute(self, collection, id, attribute, value, updated_at, min, max).await
    }

    async fn delete_document(&self, collection: &Collection, id: &str) -> Result<bool> {
        Sql::delete_document(self, collection, id).await
    }

    async fn delete_documents(
        &self,
        collection: &Collection,
        sequences: Vec<Value>,
        permission_ids: Vec<String>,
    ) -> Result<i64> {
        Sql::delete_documents(self, collection, sequences, permission_ids, Vec::new()).await
    }

    async fn delete_documents_matching(
        &self,
        collection: &Collection,
        sequences: Vec<Value>,
        permission_ids: Vec<String>,
        queries: Vec<Query>,
    ) -> Result<i64> {
        Sql::delete_documents(self, collection, sequences, permission_ids, queries).await
    }

    async fn find(&self, collection: &Collection, queries: Vec<Query>, options: Find) -> Result<Vec<Document>> {
        Sql::find(self, collection, queries, options).await
    }

    async fn count(&self, collection: &Collection, queries: Vec<Query>, max: Option<i64>) -> Result<i64> {
        Sql::count(self, collection, queries, max).await
    }

    async fn sum(
        &self,
        collection: &Collection,
        attribute: &str,
        queries: Vec<Query>,
        max: Option<i64>,
    ) -> Result<Value> {
        Sql::sum(self, collection, attribute, queries, max).await
    }

    async fn get_sequences(&self, collection: &Collection, documents: Vec<Document>) -> Result<Vec<Document>> {
        Sql::get_sequences(self, collection, documents).await
    }
}

#[async_trait]
impl Connection for Sql {
    async fn ping(&self) -> Result<bool> {
        Sql::ping(self).await
    }

    async fn reconnect(&self) -> Result<()> {
        Sql::reconnect(self).await
    }

    async fn id(&self) -> Result<String> {
        Sql::id(self).await
    }

    async fn hostname(&self) -> Result<String> {
        Ok(Sql::hostname(self).await)
    }
}

impl QueryBuilder for Sql {
    fn builder(&self) -> Result<SqlBuilder> {
        Sql::builder(self)
    }

    fn schema(&self) -> Result<SqlSchema> {
        Ok(Sql::schema(self))
    }
}

#[async_trait]
impl RawQuery for Sql {
    async fn raw_query(&self, query: &str, bindings: utopia_query::Array) -> Result<Vec<Document>> {
        Sql::raw_query(self, query, bindings).await
    }

    async fn raw_mutation(&self, query: &str, bindings: utopia_query::Array) -> Result<i64> {
        Sql::raw_mutation(self, query, bindings).await
    }
}

#[async_trait]
impl Relationships for Sql {
    async fn create_relationship(&self, collection: &str, relationship: &Relationship) -> Result<bool> {
        Sql::create_relationship(self, collection, relationship).await
    }

    async fn update_relationship(
        &self,
        collection: &str,
        relationship: &Relationship,
        side: RelationshipSide,
        update: &relationship::Update,
    ) -> Result<bool> {
        Sql::update_relationship(self, collection, relationship, side, update).await
    }

    async fn delete_relationship(
        &self,
        collection: &str,
        relationship: &Relationship,
        side: RelationshipSide,
    ) -> Result<bool> {
        Sql::delete_relationship(self, collection, relationship, side).await
    }
}

impl Spatial for Sql {
    fn encode(&self, value: &Value, kind: ColumnType) -> Result<String> {
        Sql::encode(self, value, kind)
    }

    fn decode(&self, value: &[u8], kind: ColumnType) -> Result<Value> {
        Sql::decode(self, value, kind)
    }
}

impl Timeouts for Sql {
    fn set_timeout(&self, milliseconds: i64, event: Event) -> Result<()> {
        Sql::set_timeout(self, milliseconds, event)
    }

    fn clear_timeout(&self, event: Event) {
        Sql::clear_timeout(self, event)
    }

    fn timeout(&self, event: Event) -> i64 {
        self.timeouts.get(event)
    }
}

#[async_trait]
impl Upserts for Sql {
    async fn upsert_document(&self, collection: &Collection, change: Change) -> Result<Document> {
        Sql::upsert_document(self, collection, change).await
    }

    async fn upsert_documents(
        &self,
        collection: &Collection,
        changes: Vec<Change>,
        increase: Option<&str>,
    ) -> Result<Vec<Document>> {
        Sql::upsert_documents(self, collection, changes, increase).await
    }
}
