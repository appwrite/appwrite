//! Databases, and the connection: ping, reconnect, id, hostname.
//!
//! PHP: `create()`, `update()`, `exists()`, `collectionExists()`,
//! `delete()`, `ping()`, `reconnect()`, `id()`, `hostname()` of `SQL`,
//! `Postgres`, `MariaDB` and `SQLite`, `MariaDB::getTables()`.

use utopia_query::builder::Statement;
use utopia_query::schema::feature::Databases;

use super::exec::Prepared;
use super::pdo::ParamType;
use super::{Engine, Sql};
use crate::adapter::{Adapter, SqlSchema};
use crate::error::{Error, Kind, Result};
use crate::event::Event;
use crate::query::query_error;
use crate::value::{Value, string};

impl SqlSchema {
    /// `createDatabase($name)`.
    pub fn create_database(&self, name: &str) -> utopia_query::Result<Statement> {
        match self {
            SqlSchema::MySql(s) => s.create_database(name),
            SqlSchema::PostgreSql(s) => s.create_database(name),
            SqlSchema::Sqlite(_) => Err(utopia_query::Error::Unsupported("SQLite has no databases".into())),
        }
    }

    /// `dropDatabase($name)`.
    pub fn drop_database(&self, name: &str) -> utopia_query::Result<Statement> {
        match self {
            SqlSchema::MySql(s) => s.drop_database(name),
            SqlSchema::PostgreSql(s) => s.drop_database(name),
            SqlSchema::Sqlite(_) => Err(utopia_query::Error::Unsupported("SQLite has no databases".into())),
        }
    }
}

impl Sql {
    /// Runs a statement and whether it returned rows (`returnsRows()`):
    /// the base maps a missing schema to `false`, Postgres maps every
    /// driver error.
    async fn returns_rows(&self, mut prepared: Prepared) -> Result<bool> {
        let rows = async {
            self.execute(&mut prepared).await?;
            let rows = prepared.statement.fetch_all_assoc();
            prepared.statement.close_cursor();
            Ok::<_, Error>(rows)
        }
        .await;
        match rows {
            Ok(rows) => Ok(!rows.is_empty()),
            Err(e) => {
                let e = self.process_exception(e);
                if self.engine != Engine::Postgres && e.kind() == Kind::NotFound {
                    return Ok(false);
                }
                Err(e)
            }
        }
    }

    /// `create()`: the database (a schema on Postgres, with its extensions
    /// and the `utf8_ci_ai` collation), unless it exists. SQLite keeps no
    /// database name.
    pub(crate) async fn create(&self, name: &str) -> Result<bool> {
        if self.engine == Engine::Sqlite {
            return Ok(true);
        }
        let name = self.filter(name);
        if self.exists(&name).await? {
            return Ok(true);
        }
        let schema = self.schema();
        let sql = schema.create_database(&name).map_err(query_error)?.query;
        let created = self.execute_statement(&sql, Event::DatabaseCreate).await?;
        if let SqlSchema::PostgreSql(pg) = &schema {
            for extension in ["postgis", "vector", "pg_trgm"] {
                let sql = pg.create_extension(extension).map_err(query_error)?.query;
                // The extension may already exist through a concurrent worker.
                if let Err(e) = self.execute_statement(&sql, Event::DatabaseCreate).await
                    && e.kind() != Kind::Pdo
                {
                    return Err(e);
                }
            }
            let collation = pg
                .create_collation("utf8_ci_ai", &[("provider", "icu"), ("locale", "und-u-ks-level1")], false)
                .map_err(query_error)?
                .query;
            if let Err(e) = self.execute_statement(&collation, Event::DatabaseCreate).await
                && e.kind() != Kind::Pdo
            {
                return Err(e);
            }
        }
        Ok(created)
    }

    /// `update()`: renames a database. Postgres renames the schema;
    /// MariaDB and MySQL move every table into a new database in one
    /// `RENAME TABLE` and drop the old one; SQLite has nothing to rename.
    pub(crate) async fn update(&self, name: &str, new: &str) -> Result<bool> {
        if self.engine == Engine::Sqlite {
            return Ok(true);
        }
        if self.shared_tables() {
            return Err(Error::database("Cannot rename a database while shared tables are enabled"));
        }
        let name = self.filter(name);
        let new = self.filter(new);
        if !self.exists(&name).await? {
            return Err(Error::not_found("Database not found"));
        }
        if self.exists(&new).await? {
            return Err(Error::duplicate("Database already exists"));
        }
        if self.engine == Engine::Postgres {
            let sql = format!("ALTER SCHEMA {} RENAME TO {}", self.quote(&name), self.quote(&new));
            let mut prepared = self.prepare_statement(&sql, None).await?;
            return self.execute(&mut prepared).await.map_err(|e| self.process_exception(e));
        }
        let tables = self.tables_of(&name).await?;
        let schema = self.schema();
        self.execute_statement(&schema.create_database(&new).map_err(query_error)?.query, Event::DatabaseCreate)
            .await?;
        if !tables.is_empty() {
            let moves: Vec<String> = tables
                .iter()
                .map(|table| {
                    format!("{}.{} TO {}.{}", self.quote(&name), self.quote(table), self.quote(&new), self.quote(table))
                })
                .collect();
            let moved = async {
                let mut prepared = self.prepare_statement(&format!("RENAME TABLE {}", moves.join(", ")), None).await?;
                self.execute(&mut prepared).await
            }
            .await;
            if let Err(error) = moved {
                self.execute_statement(&schema.drop_database(&new).map_err(query_error)?.query, Event::DatabaseDelete)
                    .await?;
                return Err(self.process_exception(error));
            }
        }
        if !self.tables_of(&name).await?.is_empty() {
            return Err(Error::database(format!(
                "Database {name} was renamed to {new} but holds tables created during the rename, so it was not dropped"
            )));
        }
        self.execute_statement(&schema.drop_database(&name).map_err(query_error)?.query, Event::DatabaseDelete).await
    }

    /// `MariaDB::getTables()`: the tables of a database.
    async fn tables_of(&self, database: &str) -> Result<Vec<String>> {
        let mut builder = self.dialect_builder();
        builder.from_table("INFORMATION_SCHEMA.TABLES", "").map_err(query_error)?;
        builder.select_raw("TABLE_NAME", Vec::new());
        builder.filter(vec![utopia_query::Query::equal("TABLE_SCHEMA", [database])]);
        let result = builder.build().map_err(query_error)?;
        let mut prepared = self.execute_result(&result, Some(Event::DatabaseList), "").await?;
        let rows = async {
            self.execute(&mut prepared).await?;
            let rows = prepared.statement.fetch_all_assoc();
            prepared.statement.close_cursor();
            Ok::<_, Error>(rows)
        }
        .await
        .map_err(|e| self.process_exception(e))?;
        Ok(rows
            .iter()
            .filter_map(|row| match row.get(&"TABLE_NAME".into()).or_else(|| row.get(&"table_name".into())) {
                Some(Value::Str(s)) => Some(String::from_utf8_lossy(s.as_bytes()).into_owned()),
                _ => None,
            })
            .collect())
    }

    /// `exists()`: whether a database (a schema) exists. SQLite reports
    /// none.
    pub(crate) async fn exists(&self, database: &str) -> Result<bool> {
        match self.engine {
            Engine::Sqlite => Ok(false),
            Engine::Postgres => {
                let mut prepared = self
                    .prepare_statement(
                        "SELECT \"schema_name\" FROM information_schema.schemata WHERE \"schema_name\" = ?",
                        Some(Event::DatabaseList),
                    )
                    .await?;
                prepared.bind_value(1, string(self.filter(database)), ParamType::Str)?;
                self.returns_rows(prepared).await
            }
            _ => {
                let mut builder = self.dialect_builder();
                builder.from_table("INFORMATION_SCHEMA.SCHEMATA", "").map_err(query_error)?;
                builder.select_raw("SCHEMA_NAME", Vec::new());
                builder.filter(vec![utopia_query::Query::equal("SCHEMA_NAME", [self.filter(database)])]);
                let result = builder.build().map_err(query_error)?;
                let prepared = self.execute_result(&result, Some(Event::DatabaseList), "").await?;
                self.returns_rows(prepared).await
            }
        }
    }

    /// `collectionExists()`: whether a collection's table exists.
    pub(crate) async fn collection_exists(&self, database: &str, collection: &str) -> Result<bool> {
        match self.engine {
            Engine::Postgres => {
                let mut prepared = self
                    .prepare_statement(
                        "SELECT \"table_name\" FROM information_schema.tables WHERE \"table_schema\" = ? AND \"table_name\" = ?",
                        Some(Event::CollectionRead),
                    )
                    .await?;
                prepared.bind_value(1, string(self.filter(database)), ParamType::Str)?;
                let table = Sql::short_key(&format!("{}_{}", self.namespace(), self.filter(collection)));
                prepared.bind_value(2, string(table), ParamType::Str)?;
                self.returns_rows(prepared).await
            }
            Engine::Sqlite => {
                let name = format!("{}_{}", self.namespace(), self.filter(collection));
                let sql = "\n\t\t\tSELECT name FROM sqlite_master\n\t\t\tWHERE type='table' AND name = :table\n\t\t";
                let mut prepared = self.prepare_statement(sql, Some(Event::CollectionRead)).await?;
                prepared.bind_value(":table", string(name.as_str()), ParamType::Str)?;
                self.execute(&mut prepared).await?;
                let rows = prepared.statement.fetch_all_assoc();
                prepared.statement.close_cursor();
                Ok(match rows.first().and_then(|row| row.get(&"name".into())) {
                    Some(Value::Str(s)) => s.as_bytes() == name.as_bytes(),
                    _ => false,
                })
            }
            _ => {
                let mut builder = self.dialect_builder();
                builder.from_table("INFORMATION_SCHEMA.TABLES", "").map_err(query_error)?;
                builder.select_raw("TABLE_NAME", Vec::new());
                builder.filter(vec![
                    utopia_query::Query::equal("TABLE_SCHEMA", [self.filter(database)]),
                    utopia_query::Query::equal(
                        "TABLE_NAME",
                        [format!("{}_{}", self.namespace(), self.filter(collection))],
                    ),
                ]);
                let result = builder.build().map_err(query_error)?;
                let prepared = self.execute_result(&result, Some(Event::CollectionRead), "").await?;
                self.returns_rows(prepared).await
            }
        }
    }

    /// `delete()`: drops a database. SQLite keeps no database name.
    pub(crate) async fn delete(&self, name: &str) -> Result<bool> {
        if self.engine == Engine::Sqlite {
            return Ok(true);
        }
        let sql = self.schema().drop_database(&self.filter(name)).map_err(query_error)?.query;
        self.execute_statement(&sql, Event::DatabaseDelete).await
    }

    /// `ping()`: `SELECT 1`.
    pub(crate) async fn ping(&self) -> Result<bool> {
        let mut builder = self.dialect_builder();
        builder.from_none().select_raw("1", Vec::new());
        let result = builder.build().map_err(query_error)?;
        let mut prepared = self.prepare_statement(&result.query, None).await?;
        let mut pdo = self.connection.lock().await;
        prepared.statement.execute(&mut pdo).await
    }

    /// `reconnect()`: a new connection; the transaction depth is reset.
    pub(crate) async fn reconnect(&self) -> Result<()> {
        if self.engine == Engine::Postgres {
            self.engine_state().local_timeout = None;
        }
        self.connection.lock().await.reconnect().await?;
        self.state().lock().in_transaction = 0;
        if self.engine == Engine::Postgres {
            self.engine_state().local_timeout = Some(0);
        }
        Ok(())
    }

    /// `id()`: the server's id of the connection (`pg_backend_pid()`,
    /// `CONNECTION_ID()`); SQLite names its handle, unique within the
    /// process while it lives.
    pub(crate) async fn id(&self) -> Result<String> {
        let function = match self.engine {
            Engine::Postgres => "pg_backend_pid()",
            Engine::MariaDb | Engine::MySql => "CONNECTION_ID()",
            Engine::Sqlite => return Ok((std::ptr::from_ref(&self.connection) as usize).to_string()),
        };
        let mut builder = self.dialect_builder();
        builder.from_none().select_raw(function, Vec::new());
        let result = builder.build().map_err(query_error)?;
        let mut prepared = self.prepare_statement(&result.query, None).await?;
        let mut pdo = self.connection.lock().await;
        if !prepared.statement.execute(&mut pdo).await? {
            return Ok(String::new());
        }
        drop(pdo);
        Ok(match prepared.statement.fetch_column(0)? {
            Some(Value::Null) | None => String::new(),
            Some(value) => crate::value::to_text(&value),
        })
    }

    /// `hostname()`: the DSN's host, else the hostname set; `''` when
    /// neither is known.
    pub(crate) async fn hostname(&self) -> String {
        self.connection.lock().await.hostname().unwrap_or_default()
    }
}
