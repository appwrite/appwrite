//! Indexes (and SQLite's FTS5 tables).
//!
//! PHP: `createIndex()`, `deleteIndex()`, `renameIndex()` of `SQL` and its
//! subclasses; MariaDB `compileIndexColumn()`; Postgres `getIndexName()`,
//! `getCollectionCreators()`, `anyIndexExists()`; SQLite `getSqlIndex()`,
//! `createFulltextIndex()`, `getTenantSqlLiteral()`,
//! `dropFulltextIndexById()`, `resolveFulltextTableById()`,
//! `getFulltextTablesByIndexId()`, `findFulltextTables()`,
//! `escapeLikePattern()`.

use utopia_query::schema::{ColumnType, IndexOptions, IndexType};

use super::attribute::interpolate;
use super::exec::Prepared;
use super::fts::FTS_TABLE_SUFFIX;
use super::pdo::ParamType;
use super::{Engine, Sql};
use crate::adapter::Adapter;
use crate::capability::Capability;
use crate::collection::Collection;
use crate::database;
use crate::error::{Error, Kind, Result, UNIQUE_MESSAGE};
use crate::event::Event;
use crate::index::Index;
use crate::query::query_error;
use crate::storage;
use crate::validator::object_path::ObjectPath;
use crate::value::{Value, string};

/// The AFTER INSERT trigger suffix of an FTS5 table (`SQLite::FTS_TRIGGER_INSERT`).
const FTS_TRIGGER_INSERT: &str = "ai";
/// The AFTER DELETE trigger suffix (`SQLite::FTS_TRIGGER_DELETE`).
const FTS_TRIGGER_DELETE: &str = "ad";
/// The AFTER UPDATE trigger suffix (`SQLite::FTS_TRIGGER_UPDATE`).
const FTS_TRIGGER_UPDATE: &str = "au";

/// The message every index type error lists the types of.
fn unknown_index_type(kind: IndexType, types: &[IndexType]) -> Error {
    let names: Vec<&str> = types.iter().map(|t| t.as_str()).collect();
    Error::database(format!("Unknown index type: {}. Must be one of {}", kind.as_str(), names.join(", ")))
}

/// `escapeLikePattern()`: `\`, `_` and `%` escaped for a `LIKE ... ESCAPE '\'`.
pub(crate) fn escape_like_pattern(value: &str) -> String {
    value.replace('\\', "\\\\").replace('_', "\\_").replace('%', "\\%")
}

/// Postgres `buildJsonbPath($path, true)`: a nested object path as a text
/// expression.
fn jsonb_text_path(sql: &Sql, path: &str) -> Result<String> {
    let mut parts: Vec<&str> = path.split('.').collect();
    for part in &parts {
        if !ObjectPath::is_key(part.as_bytes()) {
            return Err(Error::database(format!("Invalid JSON key {part}")));
        }
    }
    if parts.len() == 1 {
        return Ok(sql.quote(&sql.filter(parts[0])));
    }
    let base = sql.quote(&sql.filter(parts.remove(0)));
    let last = parts.pop().unwrap_or_default();
    let mut chain = base;
    for key in parts {
        chain.push_str(&format!("->'{key}'"));
    }
    Ok(format!("(({chain}->>'{last}')::text)"))
}

impl Sql {
    /// `createIndex()`
    pub(crate) async fn create_index(
        &self,
        collection: &str,
        index: &Index,
        attribute_types: &[(String, ColumnType)],
        collation: &[(String, String)],
    ) -> Result<bool> {
        self.create_index_with_event(collection, index, attribute_types, collation, Event::IndexCreate).await
    }

    /// `createIndex()` with its event (PHP's trailing `$event` argument of
    /// Postgres and SQLite; MariaDB always reports `IndexCreate`).
    pub(crate) async fn create_index_with_event(
        &self,
        collection: &str,
        index: &Index,
        attribute_types: &[(String, ColumnType)],
        _collation: &[(String, String)],
        event: Event,
    ) -> Result<bool> {
        match self.engine {
            Engine::Postgres => self.create_index_postgres(collection, index, attribute_types, event).await,
            Engine::Sqlite => self.create_index_sqlite(collection, index, event).await,
            Engine::MariaDb | Engine::MySql => self.create_index_mariadb(collection, index).await,
        }
    }

    /// Postgres `createIndex()`.
    async fn create_index_postgres(
        &self,
        collection: &str,
        index: &Index,
        attribute_types: &[(String, ColumnType)],
        event: Event,
    ) -> Result<bool> {
        let collection = self.filter(collection);
        let id = self.filter(&index.key);
        let kind = index.kind;
        match kind {
            IndexType::Key
            | IndexType::Fulltext
            | IndexType::Spatial
            | IndexType::HnswEuclidean
            | IndexType::HnswCosine
            | IndexType::HnswDot
            | IndexType::Object
            | IndexType::Trigram
            | IndexType::Unique => {}
            other => {
                return Err(unknown_index_type(
                    other,
                    &[
                        IndexType::Key,
                        IndexType::Unique,
                        IndexType::Fulltext,
                        IndexType::Spatial,
                        IndexType::Object,
                        IndexType::HnswEuclidean,
                        IndexType::HnswCosine,
                        IndexType::HnswDot,
                    ],
                ));
            }
        }
        let key_name = self.postgres_index_name(&collection, &id, &self.current_tenant());
        let table = self.table_raw(&collection);
        let operator_class = match kind {
            IndexType::HnswEuclidean => "vector_l2_ops",
            IndexType::HnswCosine => "vector_cosine_ops",
            IndexType::HnswDot => "vector_ip_ops",
            IndexType::Trigram => "gin_trgm_ops",
            _ => "",
        };
        let mut columns = Vec::with_capacity(index.attributes.len() + 1);
        for (position, attribute) in index.attributes.iter().enumerate() {
            let nested = attribute.contains('.')
                && attribute_types.iter().any(|(a, t)| a == attribute && *t == ColumnType::Object);
            let column = if nested {
                jsonb_text_path(self, attribute)?
            } else {
                self.quote(&self.filter(storage::column(attribute)))
            };
            let order = if kind == IndexType::Fulltext {
                ""
            } else {
                index.orders.get(position).copied().flatten().map_or("", |o| o.as_str())
            };
            let mut part = column;
            if !operator_class.is_empty() {
                part.push(' ');
                part.push_str(operator_class);
            }
            if !order.is_empty() {
                part.push(' ');
                part.push_str(order);
            }
            columns.push(part);
        }
        if self.shared_tables() && matches!(kind, IndexType::Key | IndexType::Unique) {
            columns.insert(0, self.quote(storage::TENANT));
        }
        let method = match kind {
            IndexType::Spatial => "gist",
            IndexType::Object | IndexType::Trigram => "gin",
            IndexType::HnswEuclidean | IndexType::HnswCosine | IndexType::HnswDot => "hnsw",
            _ => "",
        };
        let options = IndexOptions {
            unique: kind == IndexType::Unique,
            method: method.to_owned(),
            raw_columns: columns,
            ..IndexOptions::default()
        };
        let sql = self.schema().create_index(&table, &key_name, &[], &options).map_err(query_error)?.query;
        match self.execute_statement(&sql, event).await {
            Ok(done) => Ok(done),
            Err(e) if e.kind() == Kind::Pdo && e.state() == Some("23505") && e.driver_code() == Some(7) => {
                Err(Error::unique(UNIQUE_MESSAGE).with_string_code(b"23505").with_previous(e))
            }
            Err(e) => Err(self.process_exception(e)),
        }
    }

    /// MariaDB `createIndex()`: the key parts of the collection's index,
    /// array attributes cast where the engine indexes them so.
    async fn create_index_mariadb(&self, collection: &str, index: &Index) -> Result<bool> {
        let stored = self.metadata_document(collection).await?;
        if stored.is_empty() {
            return Err(Error::not_found("Collection not found"));
        }
        let definition = Collection::from_document(&stored)?;
        let attributes = definition.attributes()?;
        let id = self.filter(&index.key);
        let kind = index.kind;
        let table = self.table_raw(stored.id());
        let mut columns = Vec::with_capacity(index.attributes.len() + 1);
        for (position, key) in index.attributes.iter().enumerate() {
            let array = attributes.iter().find(|a| a.key.eq_ignore_ascii_case(key)).is_some_and(|a| a.array);
            let order = if kind == IndexType::Fulltext {
                ""
            } else {
                index.orders.get(position).copied().flatten().map_or("", |o| o.as_str())
            };
            columns.push(self.compile_index_column(
                &self.filter(storage::column(key)),
                array,
                index.lengths.get(position).copied().flatten().unwrap_or(0),
                order,
            ));
        }
        if self.shared_tables() && kind != IndexType::Fulltext && kind != IndexType::Spatial {
            columns.insert(0, self.quote(storage::TENANT));
        }
        let schema_kind = match kind {
            IndexType::Key | IndexType::Unique => None,
            IndexType::Fulltext => Some(IndexType::Fulltext),
            IndexType::Spatial => Some(IndexType::Spatial),
            other => {
                return Err(unknown_index_type(
                    other,
                    &[IndexType::Key, IndexType::Unique, IndexType::Fulltext, IndexType::Spatial],
                ));
            }
        };
        let options = IndexOptions {
            unique: kind == IndexType::Unique,
            kind: schema_kind,
            raw_columns: columns,
            ..IndexOptions::default()
        };
        let sql = self.schema().create_index(&table, &id, &[], &options).map_err(query_error)?.query;
        match self.execute_statement(&sql, Event::IndexCreate).await {
            Ok(done) => Ok(done),
            Err(e) if e.kind() == Kind::Pdo && e.state() == Some("23000") && e.driver_code() == Some(1062) => {
                Err(Error::unique(UNIQUE_MESSAGE).with_string_code(b"23000").with_previous(e))
            }
            Err(e) => Err(self.process_exception(e)),
        }
    }

    /// MariaDB `compileIndexColumn()`: one key part, rendered raw so the
    /// parts keep the caller's order.
    pub(crate) fn compile_index_column(&self, column: &str, array: bool, length: i64, order: &str) -> String {
        if array && self.supports(Capability::IndexArrayCast) {
            return format!("(CAST({} AS char({}) ARRAY))", self.quote(column), database::MAX_ARRAY_INDEX_LENGTH);
        }
        let mut part = self.quote(column);
        if length > 0 {
            part.push_str(&format!("({length})"));
        }
        if !order.is_empty() {
            part.push(' ');
            part.push_str(order);
        }
        part
    }

    /// SQLite `createIndex()`: an FTS5 table for a fulltext index, else the
    /// index unless one of its name exists.
    async fn create_index_sqlite(&self, collection: &str, index: &Index, event: Event) -> Result<bool> {
        let name = self.filter(collection);
        let id = self.filter(&index.key);
        if index.kind == IndexType::Fulltext {
            return self.create_fulltext_index(&name, &id, &index.attributes, event).await;
        }
        let mut statement = self
            .sqlite_prepare(
                "\n\t\t\tSELECT name\n\t\t\tFROM sqlite_master\n\t\t\tWHERE type='index' AND name=:_index;\n\t\t\t",
                Some(event),
            )
            .await?;
        statement.bind_value(
            ":_index",
            string(format!("{}_{}_{name}_{id}", self.namespace(), self.tenant_segment())),
            ParamType::Str,
        )?;
        self.execute(&mut statement).await?;
        let existing = statement.statement.fetch()?;
        statement.statement.close_cursor();
        if existing.as_ref().is_some_and(Value::truthy) {
            return Ok(true);
        }
        let sql = self.sqlite_index_sql(&name, &id, index.kind, &index.attributes)?;
        self.sqlite_run(&sql, Some(event)).await.map_err(|e| self.process_exception(e))
    }

    /// SQLite `getSqlIndex()`.
    pub(crate) fn sqlite_index_sql(
        &self,
        collection: &str,
        id: &str,
        kind: IndexType,
        attributes: &[String],
    ) -> Result<String> {
        let (sql_type, postfix) = match kind {
            IndexType::Key => ("INDEX", String::new()),
            IndexType::Unique => ("UNIQUE INDEX", format!("COLLATE {}", crate::builder::SQLITE_COLLATION)),
            other => {
                return Err(unknown_index_type(other, &[IndexType::Key, IndexType::Unique, IndexType::Fulltext]));
            }
        };
        let columns: Vec<String> = attributes
            .iter()
            .map(|attribute| format!("`{}` {postfix}", self.filter(storage::column(attribute))))
            .collect();
        let namespace = self.namespace();
        let key = format!("`{namespace}_{}_{collection}_{id}`", self.tenant_segment());
        let mut columns = columns.join(", ");
        if self.shared_tables() {
            columns = format!("{}, {columns}", self.quote(storage::TENANT));
        }
        Ok(format!("CREATE {sql_type} {key} ON `{namespace}_{collection}` ({columns})"))
    }

    /// SQLite `getTenantSqlLiteral()`: the tenant as a SQL literal, for
    /// trigger bodies.
    async fn tenant_sql_literal(&self) -> Result<String> {
        match self.current_tenant() {
            Value::Null => Ok("NULL".into()),
            Value::Int(i) => Ok(i.to_string()),
            tenant => {
                let text = crate::value::to_text(&tenant);
                let quoted = self.connection.lock().await.quote(text.as_bytes()).ok().flatten();
                match quoted {
                    Some(q) => Ok(String::from_utf8_lossy(q.as_bytes()).into_owned()),
                    None => Err(Error::database("Failed to quote SQLite tenant")),
                }
            }
        }
    }

    /// SQLite `createFulltextIndex()`: an FTS5 table over the attributes
    /// and the triggers keeping it in sync with the collection's table.
    async fn create_fulltext_index(
        &self,
        collection: &str,
        _id: &str,
        attributes: &[String],
        event: Event,
    ) -> Result<bool> {
        if attributes.is_empty() {
            return Err(Error::database("Fulltext index requires at least one attribute"));
        }
        let attributes: Vec<String> = attributes.iter().map(|a| storage::column(a).to_owned()).collect();
        let fts = self.fulltext_table_name(collection, &attributes);
        let parent = format!("{}_{collection}", self.namespace());
        let mut statement = self
            .sqlite_prepare(
                "\n            SELECT name\n            FROM sqlite_master\n            WHERE type='table' AND name=:_table;\n        ",
                Some(event),
            )
            .await?;
        statement.bind_value(":_table", string(fts.as_str()), ParamType::Str)?;
        self.execute(&mut statement).await?;
        let exists = statement.statement.fetch()?.as_ref().is_some_and(Value::truthy);
        statement.statement.close_cursor();
        if exists {
            return Ok(true);
        }
        let columns: Vec<String> = attributes.iter().map(|a| self.filter(a)).collect();
        let fts_columns = columns.join(", ");
        let column_list = columns.iter().map(|c| format!("`{c}`")).collect::<Vec<_>>().join(", ");
        let new_columns = columns.iter().map(|c| format!("NEW.`{c}`")).collect::<Vec<_>>().join(", ");
        let old_columns = columns.iter().map(|c| format!("OLD.`{c}`")).collect::<Vec<_>>().join(", ");
        let tenant = if self.shared_tables() { Some(self.tenant_sql_literal().await?) } else { None };
        let t = self.quote(storage::TENANT);
        let (insert_when, delete_when, update_when, backfill_where) = match &tenant {
            Some(l) => (
                format!(" WHEN NEW.{t} IS {l}"),
                format!(" WHEN OLD.{t} IS {l}"),
                format!(" WHEN OLD.{t} IS {l} OR NEW.{t} IS {l}"),
                format!(" WHERE {t} IS {l}"),
            ),
            None => Default::default(),
        };
        let seq = self.quote(storage::SEQUENCE);
        let statements = [
            format!(
                "CREATE VIRTUAL TABLE `{fts}` USING fts5({fts_columns}, content=\"{parent}\", content_rowid=\"{}\")",
                storage::SEQUENCE
            ),
            format!(
                "\n                CREATE TRIGGER `{fts}_{FTS_TRIGGER_INSERT}` AFTER INSERT ON `{parent}`{insert_when} BEGIN\n                    INSERT INTO `{fts}` (rowid, {column_list}) VALUES (NEW.{seq}, {new_columns});\n                END\n            "
            ),
            format!(
                "\n                CREATE TRIGGER `{fts}_{FTS_TRIGGER_DELETE}` AFTER DELETE ON `{parent}`{delete_when} BEGIN\n                    INSERT INTO `{fts}` (`{fts}`, rowid, {column_list}) VALUES ('delete', OLD.{seq}, {old_columns});\n                END\n            "
            ),
            format!(
                "\n                CREATE TRIGGER `{fts}_{FTS_TRIGGER_UPDATE}` AFTER UPDATE OF {column_list} ON `{parent}`{update_when} BEGIN\n                    INSERT INTO `{fts}` (`{fts}`, rowid, {column_list}) VALUES ('delete', OLD.{seq}, {old_columns});\n                    INSERT INTO `{fts}` (rowid, {column_list}) VALUES (NEW.{seq}, {new_columns});\n                END\n            "
            ),
            format!(
                "INSERT INTO `{fts}` (rowid, {column_list}) SELECT {seq}, {column_list} FROM `{parent}`{backfill_where}"
            ),
        ];
        self.start_transaction().await?;
        let created = async {
            for sql in &statements {
                let mut prepared = self.sqlite_prepare(sql, Some(event)).await?;
                self.execute(&mut prepared).await?;
            }
            self.commit_transaction().await
        }
        .await;
        if let Err(e) = created {
            let _ = self.rollback_transaction().await;
            return Err(e);
        }
        self.forget_fulltext_tables(collection);
        Ok(true)
    }

    /// `unset($this->ftsTableCache[$this->getFulltextTablePrefix($collection)])`.
    pub(crate) fn forget_fulltext_tables(&self, collection: &str) {
        let prefix = self.fulltext_table_prefix(collection);
        self.engine_state().fts_tables.retain(|(p, _)| *p != prefix);
    }

    /// SQLite's private `prepare()`: `prepareStatement()` with SQLite's
    /// message for a statement the driver could not prepare.
    pub(crate) async fn sqlite_prepare(&self, sql: &str, event: Option<Event>) -> Result<Prepared> {
        match self.prepare_statement(sql, event).await {
            Err(e) if e.kind() == Kind::Database && e.message() == "Failed to prepare SQL statement" => {
                Err(Error::new(Kind::Database, "Failed to prepare SQLite statement").with_previous(e))
            }
            other => other,
        }
    }

    /// `$this->execute($this->prepare($sql, event: $event))`: SQLite
    /// compiles a statement when it is prepared, so a statement on a missing
    /// table, column or index fails here, where PHP's callers catch it.
    pub(crate) async fn sqlite_run(&self, sql: &str, event: Option<Event>) -> Result<bool> {
        let mut prepared = self.sqlite_prepare(sql, event).await?;
        self.execute(&mut prepared).await
    }

    /// `deleteIndex()`
    pub(crate) async fn delete_index(&self, collection: &str, key: &str) -> Result<bool> {
        self.delete_index_with_event(collection, key, Event::IndexDelete).await
    }

    /// `deleteIndex()` with SQLite's event argument.
    pub(crate) async fn delete_index_with_event(&self, collection: &str, key: &str, event: Event) -> Result<bool> {
        let name = self.filter(collection);
        let id = self.filter(key);
        match self.engine {
            Engine::Postgres => {
                let key_name = self.postgres_index_name(&name, &id, &self.current_tenant());
                let qualified = format!("{}.{key_name}", self.database());
                let sql = self
                    .schema()
                    .drop_index(&self.table_raw(&name), &qualified)
                    .map_err(query_error)?
                    .query
                    .replace("DROP INDEX", "DROP INDEX IF EXISTS");
                self.execute_statement(&sql, Event::IndexDelete).await
            }
            Engine::MariaDb | Engine::MySql => {
                let sql = self.schema().drop_index(&self.table_raw(&name), &id).map_err(query_error)?.query;
                match self.execute_statement(&sql, Event::IndexDelete).await {
                    Ok(done) => Ok(done),
                    Err(e) if e.kind() == Kind::Pdo && e.state() == Some("42000") && e.driver_code() == Some(1091) => {
                        Ok(true)
                    }
                    Err(e) => Err(e),
                }
            }
            Engine::Sqlite => {
                let regular = format!("{}_{}_{name}_{id}", self.namespace(), self.tenant_segment());
                let mut statement = self
                    .sqlite_prepare(
                        "\n            SELECT name FROM sqlite_master WHERE type='index' AND name=:_index\n        ",
                        Some(event),
                    )
                    .await?;
                statement.bind_value(":_index", string(regular.as_str()), ParamType::Str)?;
                self.execute(&mut statement).await?;
                let has_regular = statement.statement.fetch_column(0)?.is_some();
                statement.statement.close_cursor();
                if !has_regular && self.drop_fulltext_index_by_id(&name, &id, event).await? {
                    return Ok(true);
                }
                let sql = format!("DROP INDEX `{regular}`");
                match self.sqlite_run(&sql, Some(event)).await {
                    Ok(done) => Ok(done),
                    Err(e) if e.message().contains("no such index") => Ok(true),
                    Err(e) => Err(e),
                }
            }
        }
    }

    /// `renameIndex()`
    pub(crate) async fn rename_index(&self, collection: &str, old: &str, new: &str) -> Result<bool> {
        match self.engine {
            Engine::Postgres => {
                let name = self.filter(collection);
                let old = self.filter(old);
                let new = self.filter(new);
                let tenant = self.current_tenant();
                let old_name = self.postgres_index_name(&name, &old, &tenant);
                let new_name = self.postgres_index_name(&name, &new, &tenant);
                let sql = self
                    .schema()
                    .rename_index(&self.table_raw(&name), &format!("{}.{old_name}", self.database()), &new_name)
                    .map_err(query_error)?
                    .query
                    .replace("ALTER INDEX", "ALTER INDEX IF EXISTS");
                self.execute_statement(&sql, Event::IndexRename).await?;
                let mut names = vec![new_name];
                if self.shared_tables() {
                    for creator in self.collection_creators(collection).await? {
                        names.push(self.postgres_index_name(&name, &old, &creator));
                        names.push(self.postgres_index_name(&name, &new, &creator));
                    }
                }
                self.any_index_exists(names).await
            }
            Engine::MariaDb | Engine::MySql => {
                let collection = self.filter(collection);
                let sql = self
                    .schema()
                    .rename_index(&self.table_raw(&collection), &self.filter(old), &self.filter(new))
                    .map_err(query_error)?
                    .query;
                self.execute_statement(&sql, Event::IndexRename).await
            }
            Engine::Sqlite => {
                let stored = self.metadata_document(collection).await?;
                if stored.is_empty() {
                    return Err(Error::not_found("Collection not found"));
                }
                let old = self.filter(old);
                let new = self.filter(new);
                let definition = Collection::from_document(&stored)?;
                let index = definition.indexes()?.iter().find(|i| i.key == old).cloned();
                if let Some(index) = index
                    && self.delete_index_with_event(stored.id(), &old, Event::IndexRename).await?
                    && self
                        .create_index_with_event(stored.id(), &index.with_key(new), &[], &[], Event::IndexRename)
                        .await?
                {
                    return Ok(true);
                }
                Ok(false)
            }
        }
    }

    /// Postgres `getIndexName()`: the index named after its tenant.
    fn postgres_index_name(&self, collection: &str, id: &str, tenant: &Value) -> String {
        Sql::short_key(&format!("{}_{}_{collection}_{id}", self.namespace(), interpolate(tenant)))
    }

    /// Postgres `getCollectionCreators()`: the tenant whose definition of a
    /// shared collection was stored first; empty when none is stored.
    async fn collection_creators(&self, collection: &str) -> Result<Vec<Value>> {
        let sql = format!(
            "SELECT {} FROM {} WHERE {} = ? ORDER BY {} ASC LIMIT 1",
            self.quote(storage::TENANT),
            self.table(database::METADATA),
            self.quote(storage::UID),
            self.quote(storage::SEQUENCE)
        );
        let mut prepared = self.prepare_statement(&sql, Some(Event::IndexRename)).await?;
        prepared.bind_value(1, string(collection), ParamType::Str)?;
        let row = async {
            self.execute(&mut prepared).await?;
            let row = prepared.statement.fetch_num();
            prepared.statement.close_cursor();
            Ok::<_, Error>(row)
        }
        .await
        .map_err(|e| self.process_exception(e))?;
        let Some(row) = row else {
            return Ok(Vec::new());
        };
        let tenant = row.values().next().cloned().unwrap_or(Value::Null);
        Ok(vec![match tenant {
            Value::Int(_) | Value::Str(_) => tenant,
            _ => Value::Null,
        }])
    }

    /// Postgres `anyIndexExists()`.
    async fn any_index_exists(&self, names: Vec<String>) -> Result<bool> {
        let mut unique: Vec<String> = Vec::with_capacity(names.len());
        for name in names {
            if !unique.contains(&name) {
                unique.push(name);
            }
        }
        let placeholders = vec!["?"; unique.len()].join(", ");
        let sql = format!(
            "SELECT c.relname FROM pg_catalog.pg_class c JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = ? AND c.relkind = 'i' AND c.relname IN ({placeholders})"
        );
        let mut prepared = self.prepare_statement(&sql, Some(Event::IndexRename)).await?;
        prepared.bind_value(1, string(self.database()), ParamType::Str)?;
        for (position, name) in unique.iter().enumerate() {
            prepared.bind_value(position + 2, string(name.as_str()), ParamType::Str)?;
        }
        let found = async {
            self.execute(&mut prepared).await?;
            let found = prepared.statement.fetch_all_column(0)?;
            prepared.statement.close_cursor();
            Ok::<_, Error>(found)
        }
        .await
        .map_err(|e| self.process_exception(e))?;
        Ok(!found.is_empty())
    }

    /// SQLite `dropFulltextIndexById()`: drops the FTS5 table backing index
    /// `id` and its triggers; `false` when the collection has none.
    pub(crate) async fn drop_fulltext_index_by_id(&self, collection: &str, id: &str, event: Event) -> Result<bool> {
        let tables = self.find_fulltext_tables(collection).await?;
        if tables.is_empty() {
            return Ok(false);
        }
        let fts = match self.resolve_fulltext_table_by_id(collection, id, &tables).await? {
            Some(table) => table,
            None if tables.len() == 1 => tables[0].clone(),
            None => {
                return Err(Error::database(format!(
                    "Cannot resolve fulltext index '{id}' on '{collection}': {} FTS5 tables exist and metadata does not match any of them.",
                    tables.len()
                )));
            }
        };
        self.start_transaction().await?;
        let dropped = async {
            for suffix in [FTS_TRIGGER_INSERT, FTS_TRIGGER_DELETE, FTS_TRIGGER_UPDATE] {
                let mut prepared =
                    self.sqlite_prepare(&format!("DROP TRIGGER IF EXISTS `{fts}_{suffix}`"), Some(event)).await?;
                self.execute(&mut prepared).await?;
            }
            let mut prepared = self.sqlite_prepare(&format!("DROP TABLE IF EXISTS `{fts}`"), Some(event)).await?;
            self.execute(&mut prepared).await?;
            self.commit_transaction().await
        }
        .await;
        if let Err(e) = dropped {
            let _ = self.rollback_transaction().await;
            return Err(e);
        }
        self.forget_fulltext_tables(collection);
        Ok(true)
    }

    /// SQLite `resolveFulltextTableById()`: the FTS5 table of index `id`
    /// through the stored metadata, when it is one of `candidates`.
    pub(crate) async fn resolve_fulltext_table_by_id(
        &self,
        collection: &str,
        id: &str,
        candidates: &[String],
    ) -> Result<Option<String>> {
        let id = self.filter(id);
        let table = self.fulltext_tables_by_index_id(collection).await?.into_iter().find(|(k, _)| *k == id);
        Ok(table.map(|(_, t)| t).filter(|t| candidates.contains(t)))
    }

    /// SQLite `getFulltextTablesByIndexId()`: the FTS5 table of each
    /// fulltext index of the stored definition, by index id.
    pub(crate) async fn fulltext_tables_by_index_id(&self, collection: &str) -> Result<Vec<(String, String)>> {
        let stored = match self.metadata_document(collection).await {
            Ok(document) => document,
            Err(e) if e.kind() == Kind::NotFound => return Ok(Vec::new()),
            Err(e) => return Err(e),
        };
        let definition = Collection::from_document(&stored)?;
        let mut tables: Vec<(String, String)> = Vec::new();
        for index in definition.indexes()? {
            if index.kind != IndexType::Fulltext {
                continue;
            }
            let internal: Vec<String> = index.attributes.iter().map(|a| storage::column(a).to_owned()).collect();
            let key = self.filter(&index.key);
            let table = self.fulltext_table_name(collection, &internal);
            match tables.iter_mut().find(|(k, _)| *k == key) {
                Some(entry) => entry.1 = table,
                None => tables.push((key, table)),
            }
        }
        Ok(tables)
    }

    /// SQLite `findFulltextTables()`: the FTS5 tables of a collection.
    pub(crate) async fn find_fulltext_tables(&self, collection: &str) -> Result<Vec<String>> {
        let mut statement = self
            .sqlite_prepare(
                "\n            SELECT name FROM sqlite_master\n            WHERE type='table'\n              AND name LIKE :_prefix ESCAPE '\\'\n              AND name LIKE :_suffix ESCAPE '\\'\n        ",
                None,
            )
            .await?;
        statement.bind_value(
            ":_prefix",
            string(format!("{}%", escape_like_pattern(&self.fulltext_table_prefix(collection)))),
            ParamType::Str,
        )?;
        statement.bind_value(
            ":_suffix",
            string(format!("%{}", escape_like_pattern(FTS_TABLE_SUFFIX))),
            ParamType::Str,
        )?;
        {
            let mut pdo = self.connection.lock().await;
            statement.statement.execute(&mut pdo).await?;
        }
        let tables = statement.statement.fetch_all_column(0)?;
        statement.statement.close_cursor();
        Ok(tables
            .iter()
            .map(|t| match t {
                Value::Str(s) => String::from_utf8_lossy(s.as_bytes()).into_owned(),
                _ => String::new(),
            })
            .collect())
    }
}
