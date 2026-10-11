//! Fixed-window counters as rows of an Appwrite table, through the TablesDB
//! API.

use std::future::Future;

use super::database::datetime;
use super::{Rule, Store};
use crate::{Error, Result};

/// A column of the `Abuse` table.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct Column {
    /// The column's key.
    pub key: &'static str,
    /// `string`, `datetime` or `integer`.
    pub kind: &'static str,
    /// Whether a value is required.
    pub required: bool,
    /// The size of a `string` column.
    pub size: Option<i64>,
    /// The minimum of an `integer` column.
    pub min: Option<i64>,
    /// The maximum of an `integer` column.
    pub max: Option<i64>,
}

/// An index of the `Abuse` table.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct Index {
    /// The index's key.
    pub key: &'static str,
    /// `unique` or `key`.
    pub kind: &'static str,
    /// The columns it covers.
    pub columns: &'static [&'static str],
}

/// The columns of the `Abuse` table, sent inline when it is created.
pub const COLUMNS: [Column; 3] = [
    Column { key: "key", kind: "string", required: true, size: Some(255), min: None, max: None },
    Column { key: "time", kind: "datetime", required: true, size: None, min: None, max: None },
    Column { key: "count", kind: "integer", required: true, size: None, min: Some(0), max: Some(i64::MAX) },
];

/// The indexes of the `Abuse` table, sent inline when it is created.
pub const INDEXES: [Index; 2] = [
    Index { key: "unique1", kind: "unique", columns: &["key", "time"] },
    Index { key: "index2", kind: "key", columns: &["time"] },
];

/// The database's name. PHP: `TablesDB::DATABASE_NAME`.
pub const DATABASE_NAME: &str = "Utopia";
/// The table's name. PHP: `TablesDB::TABLE_NAME`.
pub const TABLE_NAME: &str = "abuse";
/// The table's id. PHP: `TablesDB::TABLE_ID`.
pub const TABLE_ID: &str = "Abuse";
/// The table whose presence marks a finished setup. PHP: `TablesDB::TABLE_LOCK`.
pub const TABLE_LOCK: &str = "lock";

/// One row of the `Abuse` table.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Row {
    /// The row's id (`$id`).
    pub id: String,
    /// The limit's key.
    pub key: String,
    /// The window's start, `Y-m-d H:i:s.v`.
    pub time: String,
    /// The count.
    pub count: i64,
}

/// The TablesDB calls [`TablesDb`] makes, in database `database`. Errors are
/// [`Error::Appwrite`] with the API's error type.
///
/// PHP: the `Appwrite\Services\TablesDB` methods the adapter calls through
/// the Appwrite SDK. Implement it over an Appwrite client.
pub trait Tables: Send + Sync {
    /// `getTable`.
    fn get_table(&self, database: &str, table: &str) -> impl Future<Output = Result<()>> + Send;
    /// `create` (a database).
    fn create_database(&self, database: &str, name: &str) -> impl Future<Output = Result<()>> + Send;
    /// `createTable`, with inline columns and indexes.
    fn create_table(
        &self,
        database: &str,
        table: &str,
        name: &str,
        columns: &[Column],
        indexes: &[Index],
    ) -> impl Future<Output = Result<()>> + Send;
    /// `createStringColumn`, `createDatetimeColumn` or `createIntegerColumn`.
    fn create_column(&self, database: &str, table: &str, column: &Column) -> impl Future<Output = Result<()>> + Send;
    /// `createIndex`.
    fn create_index(&self, database: &str, table: &str, index: &Index) -> impl Future<Output = Result<()>> + Send;
    /// How many columns (`indexes` false) or indexes (`indexes` true) are not
    /// `available` yet, looking at one at most.
    ///
    /// PHP: `listColumns`/`listIndexes` with `Query::notEqual('status',
    /// 'available')` and `Query::limit(1)`.
    fn pending(&self, database: &str, table: &str, indexes: bool) -> impl Future<Output = Result<usize>> + Send;
    /// The first row of `key` and `time`.
    fn find_row(&self, database: &str, key: &str, time: &str) -> impl Future<Output = Result<Option<Row>>> + Send;
    /// `createRow` with a unique id.
    fn create_row(&self, database: &str, key: &str, time: &str, count: i64) -> impl Future<Output = Result<()>> + Send;
    /// `incrementRowColumn` of `count` by 1.
    fn increment(&self, database: &str, id: &str) -> impl Future<Output = Result<()>> + Send;
    /// `updateRow` of `count`.
    fn update_row(&self, database: &str, id: &str, count: i64) -> impl Future<Output = Result<()>> + Send;
    /// `listRows`, newest first, `limit` from `offset`.
    fn list_rows(
        &self,
        database: &str,
        offset: Option<i64>,
        limit: Option<i64>,
    ) -> impl Future<Output = Result<Vec<Row>>> + Send;
    /// `deleteRows` of rows whose time is before `time`: how many it deleted.
    fn delete_rows(&self, database: &str, time: &str) -> impl Future<Output = Result<i64>> + Send;
}

/// Counters as rows of the `Abuse` table of an Appwrite database.
///
/// PHP: `Utopia\Abuse\Adapter\TimeLimit\Appwrite\TablesDB`, over the Appwrite
/// SDK; here over any [`Tables`] client.
#[derive(Debug, Clone)]
pub struct TablesDb<T> {
    tables: T,
    database: String,
}

/// Ignores the API error of type `allowed`; whether the call succeeded.
fn tolerate(result: Result<()>, allowed: &str) -> Result<bool> {
    match result {
        Ok(()) => Ok(true),
        Err(Error::Appwrite { kind, .. }) if kind == allowed => Ok(false),
        Err(e) => Err(e),
    }
}

impl<T: Tables> TablesDb<T> {
    /// Counters in database `database`, through `tables`.
    pub fn new(tables: T, database: impl Into<String>) -> Self {
        TablesDb { tables, database: database.into() }
    }

    /// The client.
    pub fn tables(&self) -> &T {
        &self.tables
    }

    /// Creates the database, the table with its columns and indexes, and the
    /// lock table, unless the lock table exists. A table left by a setup that
    /// stopped half way gets its missing columns and indexes, waiting (up to
    /// 15 seconds each) until they are available.
    pub async fn setup(&self) -> Result<()> {
        let db = self.database.as_str();
        if self.tables.get_table(db, TABLE_LOCK).await.is_ok() {
            return Ok(());
        }
        tolerate(self.tables.create_database(db, DATABASE_NAME).await, "database_already_exists")?;
        let created = tolerate(
            self.tables.create_table(db, TABLE_ID, TABLE_NAME, &COLUMNS, &INDEXES).await,
            "table_already_exists",
        )?;
        if !created {
            for column in &COLUMNS {
                tolerate(self.tables.create_column(db, TABLE_ID, column).await, "column_already_exists")?;
            }
            self.wait(false).await?;
            for index in &INDEXES {
                tolerate(self.tables.create_index(db, TABLE_ID, index).await, "index_already_exists")?;
            }
            self.wait(true).await?;
        }
        tolerate(self.tables.create_table(db, TABLE_LOCK, TABLE_LOCK, &[], &[]).await, "table_already_exists")?;
        Ok(())
    }

    async fn wait(&self, indexes: bool) -> Result<()> {
        for _ in 0..15 {
            if self.tables.pending(&self.database, TABLE_ID, indexes).await? == 0 {
                return Ok(());
            }
            tokio::time::sleep(std::time::Duration::from_secs(1)).await;
        }
        Err(Error::Exception(format!("Failed to setup {}.", if indexes { "indexes" } else { "columns" })))
    }

    async fn find_or_create(&self, key: &str, time: &str, count: i64, lost: &str) -> Result<Option<Row>> {
        let db = self.database.as_str();
        if let Some(row) = self.tables.find_row(db, key, time).await? {
            return Ok(Some(row));
        }
        match self.tables.create_row(db, key, time, count).await {
            Ok(()) => Ok(None),
            Err(Error::Appwrite { kind, .. }) if kind == "row_already_exists" => {
                match self.tables.find_row(db, key, time).await? {
                    Some(row) => Ok(Some(row)),
                    None => Err(Error::Exception(lost.into())),
                }
            }
            Err(e) => Err(e),
        }
    }
}

impl<T: Tables> Store for TablesDb<T> {
    type Log = Row;

    /// Creates the window's row with a count of 1, or increments it unless
    /// it has reached the limit.
    async fn hit(&self, key: &str, window: i64, rule: Rule) -> Result<i64> {
        let time = datetime(window);
        let Some(row) = self.find_or_create(key, &time, 1, "Document Not Found").await? else {
            return Ok(0);
        };
        if row.count >= rule.limit {
            return Ok(row.count);
        }
        self.tables.increment(&self.database, &row.id).await?;
        Ok(row.count)
    }

    /// The window's count, `0` without a row.
    async fn count(&self, key: &str, window: i64) -> Result<i64> {
        Ok(self.tables.find_row(&self.database, key, &datetime(window)).await?.map_or(0, |r| r.count))
    }

    /// Creates the window's row with `value`, or updates its count.
    async fn set(&self, key: &str, window: i64, value: i64, _rule: Rule) -> Result<()> {
        let time = datetime(window);
        let lost = "Unable to find abuse tracking row after race condition handling";
        if let Some(row) = self.find_or_create(key, &time, value, lost).await? {
            self.tables.update_row(&self.database, &row.id, value).await?;
        }
        Ok(())
    }

    /// Rows newest first.
    async fn logs(&self, offset: Option<i64>, limit: Option<i64>) -> Result<Vec<Row>> {
        self.tables.list_rows(&self.database, offset, limit).await
    }

    /// Deletes the rows of windows before `timestamp` until none is left.
    async fn cleanup(&self, timestamp: i64) -> Result<bool> {
        let time = datetime(timestamp);
        while self.tables.delete_rows(&self.database, &time).await? > 0 {}
        Ok(true)
    }
}
