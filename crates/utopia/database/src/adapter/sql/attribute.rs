//! Attributes and their columns.
//!
//! PHP: the attribute methods and column types of `SQL` and its subclasses:
//! `createAttribute()`, `createAttributeWithEvent()`, `createAttributes()`,
//! `updateAttribute()`, `relaxAttributeRequired()`, `deleteAttribute()`,
//! `renameAttribute()`, `isRenamed()`, `getLockType()`, `getColumnType()`,
//! `canonicalColumnType()`, `getSqlType()`, `getSpatialSqlType()`,
//! `addAttributeColumn()`, `getAttributeSqlType()`, `storesColumn()`,
//! `addTableColumn()`, `assertVarcharSize()`, `addSpatialColumn()`,
//! `getSpatialColumnSrid()`, `addVectorColumn()`, Postgres
//! `refuseSharedColumnsOfAnotherType()` and `assertVectorDimensions()`.

use php_std::pcre;
use utopia_query::builder::Statement;
use utopia_query::schema::{self as qs, ColumnType, Schema as _, Table};

use super::pdo::ParamType;
use super::{Engine, Sql};
use crate::adapter::{Adapter, SqlSchema};
use crate::attribute::Attribute;
use crate::capability::Capability;
use crate::database;
use crate::error::{Error, Kind, Result};
use crate::event::Event;
use crate::query::query_error;
use crate::relationship::{RelationshipSide, RelationshipType};
use crate::storage;
use crate::value::{Value, string};

/// A table blueprint of the adapter's schema (PHP: the `Table` that
/// `schema()->table()` returns).
#[derive(Debug, Clone)]
pub enum SqlTable {
    /// MySQL and MariaDB (and SQLite, which compiles its DDL through
    /// MySQL's schema).
    MySql(Table<qs::MySql>),
    /// PostgreSQL.
    PostgreSql(Table<qs::PostgreSql>),
    /// SQLite.
    Sqlite(Table<qs::Sqlite>),
}

/// Runs `$body` with `$t` bound to the blueprint of whichever dialect.
macro_rules! each_table {
    ($table:expr, $t:ident => $body:expr) => {
        match $table {
            $crate::adapter::sql::attribute::SqlTable::MySql($t) => $body,
            $crate::adapter::sql::attribute::SqlTable::PostgreSql($t) => $body,
            $crate::adapter::sql::attribute::SqlTable::Sqlite($t) => $body,
        }
    };
}
pub(crate) use each_table;

impl SqlTable {
    /// `alter()`.
    pub fn alter(&self) -> utopia_query::Result<Statement> {
        each_table!(self, t => t.alter())
    }

    /// `create()`.
    pub fn create(&self) -> utopia_query::Result<Statement> {
        each_table!(self, t => t.create())
    }

    /// `drop()`.
    pub fn drop(&self) -> utopia_query::Result<Statement> {
        each_table!(self, t => t.drop())
    }

    /// `dropIfExists()`.
    pub fn drop_if_exists(&self) -> utopia_query::Result<Statement> {
        each_table!(self, t => t.drop_if_exists())
    }

    /// `renameColumn()`.
    pub fn rename_column(&mut self, from: &str, to: &str) -> &mut Self {
        each_table!(&mut *self, t => {
            t.rename_column(from, to);
        });
        self
    }

    /// `dropColumn()`.
    pub fn drop_column(&mut self, name: &str) -> &mut Self {
        each_table!(&mut *self, t => {
            t.drop_column(name);
        });
        self
    }

    /// `rawColumn()`.
    pub fn raw_column(&mut self, definition: &str) -> &mut Self {
        each_table!(&mut *self, t => {
            t.raw_column(definition);
        });
        self
    }

    /// `addIndex()`.
    pub fn add_index(&mut self, index: qs::Index) -> utopia_query::Result<&mut Self> {
        each_table!(&mut *self, t => {
            t.add_index(index)?;
        });
        Ok(self)
    }

    /// `string($name, $length)->nullable()->default(null)` (a relationship
    /// key column).
    pub fn nullable_string(&mut self, name: &str, length: i64) -> &mut Self {
        each_table!(&mut *self, t => {
            t.string(name, length).nullable().default(utopia_query::Value::Null);
        });
        self
    }
}

impl SqlSchema {
    /// `table($name)`: a blueprint of table `name`.
    pub fn table(&self, name: &str) -> SqlTable {
        match self {
            SqlSchema::MySql(s) => SqlTable::MySql(s.table(name)),
            SqlSchema::PostgreSql(s) => SqlTable::PostgreSql(s.table(name)),
            SqlSchema::Sqlite(s) => SqlTable::Sqlite(s.table(name)),
        }
    }

    /// `createIndex($table, $name, $columns, ...)`.
    pub fn create_index(
        &self,
        table: &str,
        name: &str,
        columns: &[&str],
        options: &qs::IndexOptions,
    ) -> utopia_query::Result<Statement> {
        match self {
            SqlSchema::MySql(s) => s.create_index(table, name, columns, options),
            SqlSchema::PostgreSql(s) => s.create_index(table, name, columns, options),
            SqlSchema::Sqlite(s) => s.create_index(table, name, columns, options),
        }
    }

    /// `dropIndex($table, $name)`.
    pub fn drop_index(&self, table: &str, name: &str) -> utopia_query::Result<Statement> {
        match self {
            SqlSchema::MySql(s) => s.drop_index(table, name),
            SqlSchema::PostgreSql(s) => s.drop_index(table, name),
            SqlSchema::Sqlite(s) => s.drop_index(table, name),
        }
    }

    /// `renameIndex($table, $from, $to)`.
    pub fn rename_index(&self, table: &str, from: &str, to: &str) -> utopia_query::Result<Statement> {
        use utopia_query::schema::feature::RenameIndex;
        match self {
            SqlSchema::MySql(s) => s.rename_index(table, from, to),
            SqlSchema::PostgreSql(s) => s.rename_index(table, from, to),
            SqlSchema::Sqlite(_) => Err(utopia_query::Error::Unsupported("SQLite cannot rename an index".into())),
        }
    }

    /// `analyzeTable($table)`.
    pub fn analyze_table(&self, table: &str) -> utopia_query::Result<Statement> {
        use utopia_query::schema::feature::AnalyzeTable;
        match self {
            SqlSchema::MySql(s) => s.analyze_table(table),
            SqlSchema::PostgreSql(s) => s.analyze_table(table),
            SqlSchema::Sqlite(_) => Err(utopia_query::Error::Unsupported("SQLite has no ANALYZE TABLE".into())),
        }
    }
}

/// The spellings `SQL::canonicalColumnType()` rewrites
/// (`SQL::COLUMN_TYPE_SPELLINGS`).
const COLUMN_TYPE_SPELLINGS: [(&[u8], &[u8]); 4] = [
    (br"/\s+/", b" "),
    (br"/ (NOT )?NULL$/", b""),
    (br"/^(POINT|LINESTRING|POLYGON)\b.*$/", b"$1"),
    (br"/\b(TINYINT|SMALLINT|MEDIUMINT|INT|INTEGER|BIGINT)\(\d+\)/", b"$1"),
];

/// The catalog's `format_type()` spellings mapped onto `getSqlType()`'s
/// (`Postgres::CATALOG_TYPE_SPELLINGS`).
const CATALOG_TYPE_SPELLINGS: [(&[u8], &[u8]); 3] =
    [(b"CHARACTER VARYING", b"VARCHAR"), (b" WITHOUT TIME ZONE", b""), (b", ", b",")];

/// PHP's `trim()` characters.
const WHITESPACE: &[u8] = b" \n\r\t\x0B\0";

/// `preg_replace($pattern, $replacement, $subject)`, `None` on a failure.
fn preg_replace(pattern: &[u8], replacement: &[u8], subject: &[u8]) -> Option<Vec<u8>> {
    let result = pcre::preg_replace(
        &pcre::StrOrArray::Str(pattern),
        &pcre::StrOrArray::Str(replacement),
        &pcre::StrOrArray::Str(subject),
        -1,
    )
    .ok()?;
    match result.value.result {
        pcre::Value::Str(s) => Some(s),
        _ => None,
    }
}

/// PHP's `empty()` of a string.
pub(crate) fn php_empty(value: &str) -> bool {
    value.is_empty() || value == "0"
}

/// The text of a scalar the way PHP interpolates it (`null` as `''`).
pub(crate) fn interpolate(value: &Value) -> String {
    match value {
        Value::Null => String::new(),
        other => crate::value::to_text(other),
    }
}

impl Sql {
    /// `getLockType()`: `,LOCK=SHARED` where `ALTER TABLE` takes a lock
    /// and locks are enabled.
    pub(crate) fn lock_type(&self) -> &'static str {
        if self.supports(Capability::AlterLock) && self.state().lock().locks { ",LOCK=SHARED" } else { "" }
    }

    /// `createAttribute()`
    pub(crate) async fn create_attribute(&self, collection: &str, attribute: &Attribute) -> Result<bool> {
        if self.engine == Engine::Postgres {
            assert_vector_dimensions(attribute)?;
            self.refuse_shared_columns_of_another_type(collection, std::slice::from_ref(attribute)).await?;
            let mut table = self.schema().table(&self.table_raw(collection));
            self.add_attribute_column(&mut table, attribute)?;
            let sql = table.alter().map_err(query_error)?.query;
            return self
                .execute_statement(&sql, Event::AttributeCreate)
                .await
                .map_err(|e| self.process_exception(e));
        }
        self.create_attribute_with_event(collection, attribute, Event::AttributeCreate).await
    }

    /// `createAttributeWithEvent()`: one column added, under the lock type.
    pub(crate) async fn create_attribute_with_event(
        &self,
        collection: &str,
        attribute: &Attribute,
        event: Event,
    ) -> Result<bool> {
        let mut table = self.schema().table(&self.table_raw(collection));
        self.add_attribute_column(&mut table, attribute)?;
        let sql = self.with_lock_type(table.alter().map_err(query_error)?.query);
        self.execute_statement(&sql, event).await.map_err(|e| self.process_exception(e))
    }

    /// `rtrim($sql, ';').' '.$lockType` when there is a lock type.
    fn with_lock_type(&self, sql: String) -> String {
        let lock_type = self.lock_type();
        if lock_type.is_empty() {
            return sql;
        }
        format!("{} {lock_type}", sql.trim_end_matches(';'))
    }

    /// `createAttributes()`: every column in one `ALTER TABLE`; SQLite adds
    /// them one by one in a transaction.
    pub(crate) async fn create_attributes(&self, collection: &str, attributes: &[Attribute]) -> Result<bool> {
        if self.engine == Engine::Sqlite {
            self.start_transaction().await?;
            let created = async {
                for attribute in attributes {
                    self.create_attribute_with_event(collection, attribute, Event::AttributesCreate).await?;
                }
                self.commit_transaction().await
            }
            .await;
            if let Err(e) = created {
                let _ = self.rollback_transaction().await;
                return Err(e);
            }
            return Ok(true);
        }
        if self.engine == Engine::Postgres {
            self.refuse_shared_columns_of_another_type(collection, attributes).await?;
        }
        let mut table = self.schema().table(&self.table_raw(collection));
        for attribute in attributes {
            self.add_attribute_column(&mut table, attribute)?;
        }
        let sql = self.with_lock_type(table.alter().map_err(query_error)?.query);
        self.execute_statement(&sql, Event::AttributesCreate).await.map_err(|e| self.process_exception(e))
    }

    /// Postgres `refuseSharedColumnsOfAnotherType()`: under shared tables
    /// an attribute whose column exists with another type is refused.
    async fn refuse_shared_columns_of_another_type(&self, collection: &str, attributes: &[Attribute]) -> Result<()> {
        if !self.shared_tables() {
            return Ok(());
        }
        let mut prepared = self
            .prepare_statement(
                "SELECT a.attname, format_type(a.atttypid, a.atttypmod) FROM pg_attribute a WHERE a.attrelid = to_regclass(?) AND a.attnum > 0 AND NOT a.attisdropped",
                Some(Event::CollectionRead),
            )
            .await?;
        prepared.bind_value(1, string(self.table(&self.filter(collection))), ParamType::Str)?;
        let columns = async {
            self.execute(&mut prepared).await?;
            let columns = prepared.statement.fetch_all_key_pair()?;
            prepared.statement.close_cursor();
            Ok::<_, Error>(columns)
        }
        .await
        .map_err(|e| self.process_exception(e))?;
        for attribute in attributes {
            let Some(existing) = columns.get_str(&self.filter(&attribute.key)) else {
                continue;
            };
            if matches!(existing, Value::Null) {
                continue;
            }
            let existing = crate::value::to_text(existing);
            let requested = self.attribute_sql_type(attribute)?;
            if self.canonical_column_type(&existing) != self.canonical_column_type(&requested) {
                return Err(Error::mismatch("Attribute exists in the shared table with another type"));
            }
        }
        Ok(())
    }

    /// `updateAttribute()`
    pub(crate) async fn update_attribute(&self, collection: &str, key: &str, attribute: &Attribute) -> Result<bool> {
        match self.engine {
            Engine::Postgres => self.update_attribute_postgres(collection, key, attribute).await,
            Engine::Sqlite => self.update_attribute_sqlite(collection, key, attribute).await,
            Engine::MariaDb | Engine::MySql => {
                let name = self.filter(collection);
                let mut id = self.filter(key);
                let mut new_key = (attribute.key != key).then(|| self.filter(&attribute.key));
                let sql_type = self.attribute_sql_type(attribute)?;
                let schema = self.schema();
                let table = self.table_raw(&name);
                if let Some(nk) = new_key.clone()
                    && !php_empty(&nk)
                    && self.is_renamed(collection, &id, &nk).await?
                {
                    id = nk;
                    new_key = None;
                }
                let SqlSchema::MySql(mysql) = &schema else {
                    return Err(Error::database("Unexpected schema"));
                };
                let result = match new_key.filter(|k| !php_empty(k)) {
                    Some(new_key) => mysql.change_column(&table, &id, &new_key, &sql_type),
                    None => mysql.modify_column(&table, &id, &sql_type),
                }
                .map_err(query_error)?;
                self.execute_statement(&result.query, Event::AttributeUpdate)
                    .await
                    .map_err(|e| self.process_exception(e))
            }
        }
    }

    /// Postgres `updateAttribute()`.
    async fn update_attribute_postgres(&self, collection: &str, key: &str, attribute: &Attribute) -> Result<bool> {
        let name = self.filter(collection);
        let mut id = self.filter(key);
        let mut new_key = (attribute.key != key).then(|| self.filter(&attribute.key));
        assert_vector_dimensions(attribute)?;
        let schema = self.schema();
        let SqlSchema::PostgreSql(pg) = &schema else {
            return Err(Error::database("Unexpected schema"));
        };
        if let Some(nk) = new_key.clone()
            && !php_empty(&nk)
            && self.is_renamed(collection, &id, &nk).await?
        {
            id = nk;
            new_key = None;
        }
        if let Some(nk) = new_key.filter(|k| !php_empty(k) && *k != id) {
            let new_key = self.filter(&nk);
            let mut rename = schema.table(&self.table_raw(collection));
            rename.rename_column(&id, &new_key);
            let sql = rename.alter().map_err(query_error)?.query;
            let result =
                self.execute_statement(&sql, Event::AttributeUpdate).await.map_err(|e| self.process_exception(e))?;
            if !result {
                return Ok(false);
            }
            id = new_key;
        }
        let sql_type = self.attribute_sql_type(attribute)?;
        let table = self.table_raw(&name);
        let result = if sql_type == "TIMESTAMP(3)" {
            pg.alter_column_type(&table, &id, "TIMESTAMP(3)", &format!("{}::TIMESTAMP(3)", self.quote(&id)))
        } else {
            pg.alter_column_type(&table, &id, &sql_type, "")
        }
        .map_err(query_error)?;
        let run = async {
            let mut ok = self.execute_statement(&result.query, Event::AttributeUpdate).await?;
            if ok && !attribute.required {
                let nullable = pg.alter_column_nullable(&table, &id, true).map_err(query_error)?;
                ok = self.execute_statement(&nullable.query, Event::AttributeUpdate).await?;
            }
            Ok::<_, Error>(ok)
        };
        run.await.map_err(|e| self.process_exception(e))
    }

    /// SQLite `updateAttribute()`: a rename, or under MySQL emulation a
    /// check that no value exceeds a smaller string size.
    async fn update_attribute_sqlite(&self, collection: &str, key: &str, attribute: &Attribute) -> Result<bool> {
        if attribute.key != key {
            return self.rename_attribute(collection, key, &attribute.key).await;
        }
        let size = attribute.size.unwrap_or(0);
        let emulate = self.engine_state().emulate_mysql;
        if emulate && attribute.kind == ColumnType::String && size > 0 && !attribute.array {
            let name = self.filter(collection);
            let column = self.filter(&attribute.key);
            let tenant_clause = if self.shared_tables() {
                format!(" AND {} = :{}", self.quote(storage::TENANT), storage::TENANT)
            } else {
                String::new()
            };
            let sql = format!(
                "SELECT 1 FROM {} WHERE LENGTH(`{column}`) > :max{tenant_clause} LIMIT 1",
                self.table(&name)
            );
            let mut prepared = self.sqlite_prepare(&sql, Some(Event::AttributeUpdate)).await?;
            prepared.bind_value(":max", Value::Int(size), ParamType::Int)?;
            if self.shared_tables() {
                let tenant = self.current_tenant();
                let ty = if matches!(tenant, Value::Int(_)) { ParamType::Int } else { ParamType::Str };
                prepared.bind_value(format!(":{}", storage::TENANT), tenant, ty)?;
            }
            let exceeds = async {
                self.execute(&mut prepared).await?;
                Ok::<_, Error>(prepared.statement.fetch_column(0)?.is_some())
            }
            .await;
            prepared.statement.close_cursor();
            if exceeds? {
                return Err(Error::truncate(format!(
                    "Attribute '{}' has values exceeding new size {size}",
                    attribute.key
                )));
            }
        }
        Ok(true)
    }

    /// `relaxAttributeRequired()`: Postgres drops `NOT NULL`; the others
    /// keep no constraint to relax.
    pub(crate) async fn relax_attribute_required(&self, collection: &str, id: &str) -> Result<bool> {
        let schema = self.schema();
        let SqlSchema::PostgreSql(pg) = &schema else {
            return Ok(true);
        };
        let statement = pg
            .alter_column_nullable(&self.table_raw(&self.filter(collection)), &self.filter(id), true)
            .map_err(query_error)?;
        self.execute_statement(&statement.query, Event::AttributeUpdate).await.map_err(|e| self.process_exception(e))
    }

    /// `deleteAttribute()`
    pub(crate) async fn delete_attribute(&self, collection: &str, key: &str) -> Result<bool> {
        if self.engine == Engine::Sqlite {
            return self.delete_attribute_sqlite(collection, key).await;
        }
        let mut table = self.schema().table(&self.table_raw(collection));
        table.drop_column(&self.filter(key));
        let sql = table.alter().map_err(query_error)?.query;
        match self.execute_statement(&sql, Event::AttributeDelete).await {
            Ok(done) => Ok(done),
            Err(e) if self.engine == Engine::Postgres => {
                if e.kind() == Kind::Pdo && e.state() == Some("42703") && e.driver_code() == Some(7) {
                    return Ok(true);
                }
                Err(e)
            }
            Err(e) => Err(self.process_exception(e)),
        }
    }

    /// SQLite `deleteAttribute()`: the indexes over the column are dropped
    /// (and recreated over the other attributes) before the column.
    async fn delete_attribute_sqlite(&self, collection: &str, key: &str) -> Result<bool> {
        let name = self.filter(collection);
        let id = self.filter(key);
        let stored = self.metadata_document(&name).await?;
        if stored.is_empty() {
            return Err(Error::not_found("Collection not found"));
        }
        let definition = crate::collection::Collection::from_document(&stored)?;
        for index in definition.indexes()? {
            if index.attributes == [id.clone()] {
                self.delete_index_with_event(&name, &index.key, Event::AttributeDelete).await?;
            } else if index.attributes.contains(&id) {
                self.delete_index_with_event(&name, &index.key, Event::AttributeDelete).await?;
                let remaining = crate::index::Index {
                    attributes: index.attributes.iter().filter(|a| **a != id).cloned().collect(),
                    ..index.clone()
                };
                self.create_index_with_event(&name, &remaining, &[], &[], Event::AttributeDelete).await?;
            }
        }
        let sql = format!("ALTER TABLE {} DROP COLUMN `{id}`", self.table(&name));
        let mut prepared = self.sqlite_prepare(&sql, Some(Event::AttributeDelete)).await?;
        match self.execute(&mut prepared).await {
            Ok(done) => Ok(done),
            Err(e) if e.message().contains("no such column") => Ok(true),
            Err(e) => Err(e),
        }
    }

    /// `renameAttribute()`
    pub(crate) async fn rename_attribute(&self, collection: &str, old: &str, new: &str) -> Result<bool> {
        if self.is_renamed(collection, old, new).await? {
            return Ok(true);
        }
        let mut table = self.schema().table(&self.table_raw(collection));
        table.rename_column(&self.filter(old), &self.filter(new));
        let sql = table.alter().map_err(query_error)?.query;
        self.execute_statement(&sql, Event::AttributeUpdate).await.map_err(|e| self.process_exception(e))
    }

    /// `isRenamed()`: whether an earlier rename already moved the column
    /// (under shared tables every tenant renames the one physical column).
    pub(crate) async fn is_renamed(&self, collection: &str, old: &str, new: &str) -> Result<bool> {
        let old = self.filter(old);
        let new = self.filter(new);
        if old == new {
            return Ok(false);
        }
        let columns = self.column_names(collection).await?;
        Ok(!columns.contains(&old) && columns.contains(&new))
    }

    /// `getColumnType()`
    pub(crate) fn column_type(&self, attribute: &Attribute) -> Result<Option<String>> {
        let kind = self.attribute_sql_type(attribute)?;
        Ok(if kind.is_empty() { None } else { Some(self.canonical_column_type(&kind)) })
    }

    /// `canonicalColumnType()`: one spelling for a native type, whether the
    /// adapter wrote it or the engine's catalog reports it.
    pub(crate) fn canonical_column_type(&self, kind: &str) -> String {
        let upper = php_std::string::strtoupper(php_std::string::trim(kind.as_bytes(), WHITESPACE)).into_owned();
        if self.engine == Engine::Postgres {
            let spelled = php_std::string::strtr_array(&upper, &CATALOG_TYPE_SPELLINGS);
            return String::from_utf8_lossy(&spelled).into_owned();
        }
        let mut canonical = Some(upper);
        for (pattern, replacement) in COLUMN_TYPE_SPELLINGS {
            canonical = canonical.and_then(|subject| preg_replace(pattern, replacement, &subject));
        }
        let canonical = match canonical {
            Some(c) => String::from_utf8_lossy(&c).into_owned(),
            None => kind.to_owned(),
        };
        if canonical == "JSON" { "LONGTEXT".to_owned() } else { canonical }
    }

    /// `getSqlType()`: the SQL type of a column, per engine.
    pub(crate) fn sql_type(
        &self,
        kind: ColumnType,
        size: i64,
        signed: bool,
        array: bool,
        required: bool,
    ) -> Result<String> {
        if self.engine == Engine::Postgres {
            return self.postgres_sql_type(kind, size, array);
        }
        if crate::attribute::is_spatial_type(kind) {
            return Ok(if self.engine == Engine::Sqlite {
                String::new()
            } else {
                self.spatial_sql_type(kind.as_str(), required)
            });
        }
        if array {
            return Ok("JSON".into());
        }
        if kind == ColumnType::String {
            return Ok(if size > 16_777_215 {
                "LONGTEXT".into()
            } else if size > 65_535 {
                "MEDIUMTEXT".into()
            } else if size > self.limits().varchar {
                "TEXT".into()
            } else {
                format!("VARCHAR({size})")
            });
        }
        if kind == ColumnType::Varchar {
            self.assert_varchar_size(size)?;
            return Ok(format!("VARCHAR({size})"));
        }
        if matches!(kind, ColumnType::Integer | ColumnType::BigInteger) {
            let suffix = if signed { "" } else { " UNSIGNED" };
            let base = if kind == ColumnType::Integer && size < 8 { "INT" } else { "BIGINT" };
            return Ok(format!("{base}{suffix}"));
        }
        if matches!(kind, ColumnType::Float | ColumnType::Double) {
            return Ok(format!("DOUBLE{}", if signed { "" } else { " UNSIGNED" }));
        }
        Ok(match kind {
            ColumnType::Id => "BIGINT UNSIGNED".into(),
            ColumnType::Text => "TEXT".into(),
            ColumnType::MediumText => "MEDIUMTEXT".into(),
            ColumnType::LongText => "LONGTEXT".into(),
            ColumnType::Boolean => "TINYINT(1)".into(),
            ColumnType::Relationship => "VARCHAR(255)".into(),
            ColumnType::Datetime => "DATETIME(3)".into(),
            other => {
                let mut types = vec![
                    ColumnType::String,
                    ColumnType::Varchar,
                    ColumnType::Text,
                    ColumnType::MediumText,
                    ColumnType::LongText,
                    ColumnType::Integer,
                    ColumnType::Double,
                    ColumnType::Boolean,
                    ColumnType::Datetime,
                    ColumnType::Relationship,
                ];
                if self.engine != Engine::Sqlite {
                    types.extend([ColumnType::Point, ColumnType::Linestring, ColumnType::Polygon]);
                }
                let names: Vec<&str> = types.iter().map(|t| t.as_str()).collect();
                return Err(Error::database(format!(
                    "Unknown type: {}. Must be one of {}",
                    other.as_str(),
                    names.join(", ")
                )));
            }
        })
    }

    /// Postgres `getSqlType()`.
    fn postgres_sql_type(&self, kind: ColumnType, size: i64, array: bool) -> Result<String> {
        if array {
            return Ok("JSONB".into());
        }
        let srid = database::DEFAULT_SRID;
        Ok(match kind {
            ColumnType::Id => "BIGINT".into(),
            ColumnType::String => {
                if size <= 0 || size > self.limits().varchar {
                    "TEXT".into()
                } else {
                    format!("VARCHAR({size})")
                }
            }
            ColumnType::Varchar => format!("VARCHAR({size})"),
            ColumnType::Text | ColumnType::MediumText | ColumnType::LongText => "TEXT".into(),
            ColumnType::Integer => (if size >= 8 { "BIGINT" } else { "INTEGER" }).into(),
            ColumnType::BigInteger => "BIGINT".into(),
            ColumnType::Float | ColumnType::Double => "DOUBLE PRECISION".into(),
            ColumnType::Boolean => "BOOLEAN".into(),
            ColumnType::Relationship => "VARCHAR(255)".into(),
            ColumnType::Datetime => "TIMESTAMP(3)".into(),
            ColumnType::Object => "JSONB".into(),
            ColumnType::Point => format!("GEOMETRY(POINT,{srid})"),
            ColumnType::Linestring => format!("GEOMETRY(LINESTRING,{srid})"),
            ColumnType::Polygon => format!("GEOMETRY(POLYGON,{srid})"),
            ColumnType::Vector => format!("VECTOR({size})"),
            other => {
                let names: Vec<&str> = [
                    ColumnType::String,
                    ColumnType::Varchar,
                    ColumnType::Text,
                    ColumnType::MediumText,
                    ColumnType::LongText,
                    ColumnType::Integer,
                    ColumnType::Double,
                    ColumnType::Boolean,
                    ColumnType::Datetime,
                    ColumnType::Relationship,
                    ColumnType::Object,
                    ColumnType::Point,
                    ColumnType::Linestring,
                    ColumnType::Polygon,
                ]
                .iter()
                .map(|t| t.as_str())
                .collect();
                return Err(Error::database(format!(
                    "Unknown Type: {}. Must be one of {}",
                    other.as_str(),
                    names.join(", ")
                )));
            }
        })
    }

    /// `getSpatialSqlType()` (and MySQL's, which declares the SRID).
    pub(crate) fn spatial_sql_type(&self, kind: &str, required: bool) -> String {
        let nullability = if self.supports(Capability::IndexSpatialNull) {
            ""
        } else if required {
            " NOT NULL"
        } else {
            " NULL"
        };
        let name = match kind {
            "point" => "POINT",
            "linestring" => "LINESTRING",
            "polygon" => "POLYGON",
            _ => return String::new(),
        };
        if self.engine == Engine::MySql {
            return format!("{name} SRID {}{nullability}", database::DEFAULT_SRID);
        }
        let modifier = match self.spatial_column_srid() {
            Some(srid) => format!("({srid})"),
            None => String::new(),
        };
        format!("{name}{modifier}{nullability}")
    }

    /// `getSpatialColumnSrid()`: the SRID written into spatial column
    /// definitions, none on MariaDB (which cannot declare one).
    pub(crate) fn spatial_column_srid(&self) -> Option<i64> {
        match self.engine {
            Engine::MariaDb => None,
            _ => Some(database::DEFAULT_SRID),
        }
    }

    /// `getAttributeSqlType()`
    pub(crate) fn attribute_sql_type(&self, attribute: &Attribute) -> Result<String> {
        self.sql_type(
            attribute.kind,
            attribute.size.unwrap_or(0),
            attribute.signed,
            attribute.array,
            attribute.required,
        )
    }

    /// `storesColumn()`: a relationship stores a column on the side that
    /// holds the foreign key, never for many-to-many.
    pub(crate) fn stores_column(attribute: &Attribute) -> bool {
        let Some(relationship) = &attribute.relationship else {
            return true;
        };
        let parent = attribute.side == Some(RelationshipSide::Parent);
        match relationship.kind {
            RelationshipType::OneToOne => parent || relationship.two_way,
            RelationshipType::OneToMany => !parent,
            RelationshipType::ManyToOne => parent,
            RelationshipType::ManyToMany => false,
        }
    }

    /// `addAttributeColumn()`
    pub(crate) fn add_attribute_column(&self, table: &mut SqlTable, attribute: &Attribute) -> Result<()> {
        self.add_table_column(
            table,
            &attribute.key,
            attribute.kind,
            attribute.size.unwrap_or(0),
            attribute.signed,
            attribute.array,
            attribute.required,
        )
    }

    /// `addTableColumn()`: a database type as a schema column.
    #[allow(clippy::too_many_arguments)]
    pub(crate) fn add_table_column(
        &self,
        table: &mut SqlTable,
        id: &str,
        kind: ColumnType,
        size: i64,
        signed: bool,
        array: bool,
        required: bool,
    ) -> Result<()> {
        let filtered = self.filter(id);
        if crate::attribute::is_spatial_type(kind) {
            let srid = self.spatial_column_srid();
            let nullable = !required || self.supports(Capability::IndexSpatialNull);
            each_table!(table, t => {
                let column = match srid {
                    None => t.add_column(filtered.as_str(), kind, None),
                    Some(srid) => match kind {
                        ColumnType::Point => t.point(filtered.as_str(), srid),
                        ColumnType::Linestring => t.linestring(filtered.as_str(), srid),
                        _ => t.polygon(filtered.as_str(), srid),
                    },
                };
                if nullable {
                    column.nullable();
                }
            });
            return Ok(());
        }
        if array {
            each_table!(table, t => {
                t.json(filtered.as_str()).nullable();
            });
            return Ok(());
        }
        if kind == ColumnType::Varchar {
            self.assert_varchar_size(size)?;
        }
        if kind == ColumnType::Vector {
            let SqlTable::PostgreSql(t) = table else {
                return Err(Error::database("Vector columns are only supported on PostgreSQL"));
            };
            t.vector(filtered.as_str(), size).nullable();
            return Ok(());
        }
        let varchar = self.limits().varchar;
        let unsigned = (!signed
            && matches!(kind, ColumnType::Integer | ColumnType::BigInteger | ColumnType::Float | ColumnType::Double))
            || kind == ColumnType::Id;
        each_table!(table, t => {
            let name = filtered.as_str();
            let column = match kind {
                ColumnType::String => {
                    if size > 16_777_215 {
                        t.long_text(name)
                    } else if size > 65_535 {
                        t.medium_text(name)
                    } else if size > varchar || size <= 0 {
                        t.text(name)
                    } else {
                        t.string(name, size)
                    }
                }
                ColumnType::Integer => {
                    if size >= 8 {
                        t.big_integer(name)
                    } else {
                        t.integer(name)
                    }
                }
                ColumnType::BigInteger | ColumnType::Id => t.big_integer(name),
                ColumnType::Float | ColumnType::Double => t.float(name),
                ColumnType::Boolean => t.boolean(name),
                ColumnType::Datetime => t.datetime(name, 3),
                ColumnType::Relationship => t.string(name, 255),
                ColumnType::Varchar => t.string(name, size),
                ColumnType::Text => t.text(name),
                ColumnType::MediumText => t.medium_text(name),
                ColumnType::LongText => t.long_text(name),
                ColumnType::Object => t.json(name),
                other => return Err(Error::database(format!("Unknown type: {}", other.as_str()))),
            };
            if unsigned {
                column.unsigned();
            }
            column.nullable();
        });
        Ok(())
    }

    /// `assertVarcharSize()`
    pub(crate) fn assert_varchar_size(&self, size: i64) -> Result<()> {
        if size <= 0 {
            return Err(Error::database(format!(
                "VARCHAR size {size} is invalid; must be > 0. Use TEXT, MEDIUMTEXT, or LONGTEXT instead."
            )));
        }
        let varchar = self.limits().varchar;
        if size > varchar {
            return Err(Error::database(format!(
                "VARCHAR size {size} exceeds maximum varchar length {varchar}. Use TEXT, MEDIUMTEXT, or LONGTEXT instead."
            )));
        }
        Ok(())
    }
}

/// Postgres `assertVectorDimensions()`.
fn assert_vector_dimensions(attribute: &Attribute) -> Result<()> {
    if attribute.kind != ColumnType::Vector {
        return Ok(());
    }
    let dimensions = attribute.size.unwrap_or(0);
    if dimensions <= 0 {
        return Err(Error::database("Vector dimensions must be a positive integer"));
    }
    if dimensions > database::MAX_VECTOR_DIMENSIONS {
        return Err(Error::database(format!("Vector dimensions cannot exceed {}", database::MAX_VECTOR_DIMENSIONS)));
    }
    Ok(())
}
