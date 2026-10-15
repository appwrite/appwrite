//! Tables, builders and the hooks that scope them.
//!
//! PHP: `SQL::getTable()`, `getTableRaw()`, `qualifyTable()` (and
//! `Postgres`'s and `SQLite`'s), `quote()`, `getIdentifierQuote()`,
//! `collateDocumentId()`, `dialectBuilder()`, `builder()`, `schema()`,
//! `scope()`, `newBuilder()`, `filterScope()`, `newPermissionHook()`,
//! `newJoinPermissionHook()`, `syncWriteHooks()`, `writeContext()`, and the
//! setters `SQLite` overrides.

use std::sync::Arc;

use async_trait::async_trait;
use utopia_query::builder::{JoinType, Statement};

use super::hook::{permission, tenant};
use super::scope::{self, Tables};
use super::{Engine, Sql};
use crate::adapter::{Adapter, SqlSchema};
use crate::builder::{self, SqlBuilder};
use crate::database;
use crate::document::Document;
use crate::error::Result;
use crate::event::Event;
use crate::hook::{Tenancy, WriteContext};
use crate::storage;
use crate::value::{Array, Value};

/// PostgreSQL's longest identifier (`Postgres::MAX_IDENTIFIER_NAME`).
pub(crate) const MAX_IDENTIFIER_NAME: usize = 63;

/// MySQL keeps every joined table's permission check a subquery from this
/// many joins (`MySQL::LARGE_JOIN`).
const LARGE_JOIN: usize = 5;

impl Sql {
    /// `filter()`.
    pub(crate) fn filter(&self, value: &str) -> String {
        crate::adapter::filter(value)
    }

    /// `quote()`: an identifier in the engine's quotes.
    pub(crate) fn quote(&self, identifier: &str) -> String {
        match self.engine {
            Engine::Postgres => format!("\"{}\"", identifier.replace('"', "\"\"")),
            _ => format!("`{}`", identifier.replace('`', "``")),
        }
    }

    /// `getIdentifierQuote()`.
    pub(crate) fn identifier_quote(&self) -> &'static str {
        match self.engine {
            Engine::Postgres => "\"",
            _ => "`",
        }
    }

    /// `collateDocumentId()`: the expression a raw lookup compares a
    /// document id column through.
    pub(crate) fn collate_document_id(&self, column: &str) -> String {
        match self.engine {
            Engine::Sqlite => format!("{column} COLLATE {}", builder::SQLITE_COLLATION),
            _ => column.to_owned(),
        }
    }

    /// `getShortKey()` (PostgreSQL): a key cut to 63 characters, as a hash
    /// keeping the last `_` segment when it fits.
    pub(crate) fn short_key(key: &str) -> String {
        if key.len() <= MAX_IDENTIFIER_NAME {
            return key.to_owned();
        }
        let suffix = key.rfind('_').map(|i| &key[i + 1..]).unwrap_or("");
        let hash = crate::query::md5_hex(key.as_bytes());
        if !suffix.is_empty() {
            let hashed = format!("{hash}_{suffix}");
            if hashed.len() <= MAX_IDENTIFIER_NAME {
                return hashed;
            }
        }
        hash[..MAX_IDENTIFIER_NAME.min(hash.len())].to_owned()
    }

    /// `qualifyTable()`: the unquoted name the table is stored under in the
    /// database and namespace given.
    pub(crate) fn qualify_table(engine: Engine, database: &str, namespace: &str, name: &str) -> String {
        let name = crate::adapter::filter(name);
        match engine {
            Engine::Postgres => format!("{database}.{}", Self::short_key(&format!("{namespace}_{name}"))),
            Engine::Sqlite => format!("{namespace}_{name}"),
            _ => format!("{database}.{namespace}_{name}"),
        }
    }

    /// `getTableRaw()`: the unquoted qualified table name.
    pub(crate) fn table_raw(&self, name: &str) -> String {
        Self::qualify_table(self.engine, &self.database(), &self.namespace(), name)
    }

    /// `getTable()`: the quoted qualified table name.
    pub(crate) fn table(&self, name: &str) -> String {
        let raw = self.table_raw(name);
        raw.splitn(2, '.').map(|part| self.quote(part)).collect::<Vec<_>>().join(".")
    }

    /// `tables()`: the table names with the database and namespace in
    /// effect now.
    pub(crate) fn tables(&self) -> Tables {
        let (engine, database, namespace) = (self.engine, self.database(), self.namespace());
        Arc::new(move |collection: &str| Self::qualify_table(engine, &database, &namespace, collection))
    }

    /// `dialectBuilder()`: a builder in the engine's dialect, unscoped.
    pub(crate) fn dialect_builder(&self) -> SqlBuilder {
        match self.engine {
            Engine::Postgres => SqlBuilder::PostgreSql(builder::postgres()),
            Engine::MariaDb => SqlBuilder::MariaDb(builder::mariadb()),
            Engine::MySql => SqlBuilder::MySql(builder::mysql()),
            Engine::Sqlite => SqlBuilder::Sqlite(builder::sqlite()),
        }
    }

    /// `builder()`: a builder scoped with `scope()`, whose `from()` takes a
    /// collection id.
    pub(crate) fn builder(&self) -> Result<SqlBuilder> {
        let mut builder = self.dialect_builder();
        builder.scope(Arc::new(self.raw_scope())).map_err(crate::query::query_error)?;
        Ok(builder)
    }

    /// `schema()`.
    pub(crate) fn schema(&self) -> SqlSchema {
        match self.engine {
            Engine::Postgres => SqlSchema::PostgreSql(utopia_query::schema::PostgreSql::new()),
            _ => SqlSchema::MySql(utopia_query::schema::MySql::new()),
        }
    }

    /// `scope()`: the scope of `builder()`.
    pub(crate) fn raw_scope(&self) -> scope::Raw {
        scope::Raw::new(self.tables(), self.shared_tables(), self.current_tenant(), self.identifier_quote())
    }

    /// `filterScope()`.
    pub(crate) fn filter_scope(
        &self,
        tenants: Vec<Value>,
        allow_null_tenant: bool,
        unindexed: Vec<String>,
    ) -> scope::Filter {
        scope::Filter::new(
            self.tables(),
            self.shared_tables(),
            tenants,
            self.identifier_quote(),
            allow_null_tenant,
            unindexed,
        )
    }

    /// `newBuilder()`: a builder reading `table` (a collection id) as
    /// `alias`, kept to `tenants` (the selected tenant when empty) under
    /// shared tables.
    pub(crate) fn new_builder(
        &self,
        table: &str,
        alias: &str,
        allow_null_tenant: bool,
        tenants: Vec<Value>,
        unindexed: Vec<String>,
    ) -> Result<SqlBuilder> {
        let tenants = if tenants.is_empty() { vec![self.current_tenant()] } else { tenants };
        let mut builder = self.dialect_builder();
        builder
            .scope(Arc::new(self.filter_scope(tenants, allow_null_tenant, unindexed)))
            .map_err(crate::query::query_error)?;
        builder.from(table, alias);
        Ok(builder)
    }

    /// `newBuilder($table, $alias)` with the defaults.
    pub(crate) fn simple_builder(&self, table: &str, alias: &str) -> Result<SqlBuilder> {
        self.new_builder(table, alias, false, Vec::new(), Vec::new())
    }

    /// `newPermissionHook()`.
    pub(crate) fn permission_hook(
        &self,
        collection: &str,
        roles: Vec<String>,
        kind: &str,
        document_column: &str,
    ) -> Result<permission::Filter> {
        if self.engine == Engine::Postgres {
            return Ok(permission::Filter::jsonb(roles, kind, document_column));
        }
        let permissions = self.table_raw(&storage::permissions_table(collection));
        let subquery_filter: Option<Arc<dyn utopia_query::hook::Filter>> = if self.shared_tables() {
            Some(Arc::new(
                tenant::Filter::new(vec![crate::value::to_query(&self.current_tenant())])
                    .with_metadata_collection(database::METADATA)
                    .with_collection(storage::permissions_table(collection))
                    .with_quote(self.identifier_quote()),
            ))
        } else {
            None
        };
        let filter = permission::Filter::with_options(
            roles,
            move |_table: &str| permissions.clone(),
            permission::Options {
                r#type: kind.to_owned(),
                document_column: document_column.to_owned(),
                permission_document_column: storage::PERMISSIONS_DOCUMENT.to_owned(),
                permission_role_column: storage::PERMISSIONS_PERMISSION.to_owned(),
                permission_type_column: storage::PERMISSIONS_TYPE.to_owned(),
                subquery_filter,
                quote: self.identifier_quote().to_owned(),
                ..permission::Options::default()
            },
        )?;
        match self.engine {
            Engine::Sqlite => filter.collate(builder::SQLITE_COLLATION),
            _ => Ok(filter),
        }
    }

    /// `newJoinPermissionHook()`: MySQL keeps the check of a large or outer
    /// join a subquery.
    pub(crate) fn join_permission_hook(
        &self,
        collection: &str,
        roles: Vec<String>,
        kind: &str,
        document_column: &str,
        joins: usize,
        join_type: JoinType,
    ) -> Result<permission::Filter> {
        let hook = self.permission_hook(collection, roles, kind, document_column)?;
        let outer = matches!(join_type, JoinType::Left | JoinType::Right | JoinType::FullOuter);
        if self.engine == Engine::MySql && (joins >= LARGE_JOIN || outer) {
            return Ok(hook.without_semi_join());
        }
        Ok(hook)
    }

    /// `syncWriteHooks()`: keeps `Tenancy` registered while shared tables
    /// are active.
    pub(crate) fn sync_write_hooks(&self) {
        let registered = self.tenant_hook().is_some();
        let shared = self.shared_tables();
        if registered == shared {
            return;
        }
        if shared {
            self.add_write_hook(Arc::new(Tenancy::default()));
        } else {
            self.remove_tenancy_hooks();
        }
    }

    /// `writeContext()`: what the write hooks write their own rows through.
    pub(crate) fn write_context(&self, skip_permissions: &[String]) -> SqlWriteContext<'_> {
        SqlWriteContext {
            adapter: self,
            ignore_duplicates: self.state().ignoring_duplicates().get(),
            skip_permissions: skip_permissions.to_vec(),
        }
    }

    /// `setTenant()` (SQLite forgets its FTS5 tables of another tenant).
    pub(crate) fn set_tenant_impl(&self, tenant: Value) {
        let changed = self.current_tenant() != tenant;
        self.state().tenant().set(tenant);
        if changed && self.engine == Engine::Sqlite {
            self.engine_state().fts_tables.clear();
        }
    }

    /// `setNamespace()` (SQLite forgets its FTS5 tables).
    pub(crate) fn set_namespace_impl(&self, namespace: &str) {
        self.state().lock().namespace = self.filter(namespace);
        if self.engine == Engine::Sqlite {
            self.engine_state().fts_tables.clear();
        }
    }

    /// `setSharedTables()` (SQLite forgets its FTS5 tables).
    pub(crate) fn set_shared_tables_impl(&self, shared_tables: bool) {
        let changed = {
            let mut state = self.state().lock();
            let changed = state.shared_tables != shared_tables;
            if changed {
                state.shared_tables = shared_tables;
                state.limits = None;
            }
            changed
        };
        if changed && self.engine == Engine::Sqlite {
            self.engine_state().fts_tables.clear();
        }
    }
}

/// The context a SQL adapter's write hooks write through.
///
/// PHP: `Utopia\Database\Adapter\SQL\Hook\WriteContext`.
pub struct SqlWriteContext<'a> {
    adapter: &'a Sql,
    ignore_duplicates: bool,
    skip_permissions: Vec<String>,
}

#[async_trait]
impl WriteContext for SqlWriteContext<'_> {
    fn builder(&self) -> SqlBuilder {
        let mut builder = self.adapter.dialect_builder();
        // A fresh builder has read no collection, so taking a scope cannot fail.
        let _ =
            builder.scope(Arc::new(self.adapter.filter_scope(vec![self.adapter.current_tenant()], false, Vec::new())));
        builder
    }

    fn raw_table(&self, table: &str) -> String {
        self.adapter.table_raw(table)
    }

    async fn run(&self, statement: Statement, event: Event) -> Result<bool> {
        let mut prepared = self.adapter.execute_result(&statement, Some(event), "").await?;
        self.adapter.execute(&mut prepared).await
    }

    async fn fetch(&self, statement: Statement, event: Event) -> Result<Vec<Array>> {
        let mut prepared = self.adapter.execute_result(&statement, Some(event), "").await?;
        self.adapter.execute(&mut prepared).await?;
        let rows = prepared.statement.fetch_all_assoc();
        prepared.statement.close_cursor();
        Ok(rows)
    }

    fn decorate_row(&self, row: Array, document: &Document) -> Array {
        self.adapter.decorate_row(row, document)
    }

    fn skip_permissions(&self, document: &Document) -> bool {
        self.skip_permissions.iter().any(|id| id == document.id())
    }

    fn ignore_duplicates(&self) -> bool {
        self.ignore_duplicates
    }
}

impl std::fmt::Debug for SqlWriteContext<'_> {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("SqlWriteContext").field("ignore_duplicates", &self.ignore_duplicates).finish()
    }
}
