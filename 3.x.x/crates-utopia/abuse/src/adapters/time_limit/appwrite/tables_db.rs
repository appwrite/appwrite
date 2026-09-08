use std::thread;
use std::time::Duration;

use http::Method;
use serde_json::{json, Map, Value};

use crate::adapter::{remaining_from, Adapter, AdapterState};
use crate::database::Document;
use crate::error::AbuseError;
use crate::logs::Logs;
use crate::time_util::{align_timestamp, format_datetime, unix_now};

use super::client::{unique_id, Client, Query};

/// PHP `TablesDB::DATABASE_NAME`.
pub const DATABASE_NAME: &str = "Utopia";
/// PHP `TablesDB::TABLE_NAME`.
pub const TABLE_NAME: &str = "abuse";
/// PHP `TablesDB::TABLE_ID`.
pub const TABLE_ID: &str = "Abuse";
/// PHP `TablesDB::TABLE_LOCK`.
pub const TABLE_LOCK: &str = "lock";

/// PHP `Utopia\Abuse\Adapters\TimeLimit\Appwrite\TablesDB`.
#[derive(Clone)]
pub struct TablesDB {
    state: AdapterState,
    limit: i64,
    timestamp: i64,
    count: Option<i64>,
    client: Client,
    database_id: String,
}

impl std::fmt::Debug for TablesDB {
    fn fmt(&self, formatter: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        formatter
            .debug_struct("TablesDB")
            .field("limit", &self.limit)
            .field("timestamp", &self.timestamp)
            .field("database_id", &self.database_id)
            .finish_non_exhaustive()
    }
}

impl TablesDB {
    /// PHP `new TablesDB($key, $limit, $seconds, $client, $databaseId)`.
    #[must_use]
    pub fn new(
        key: impl Into<String>,
        limit: i64,
        seconds: i64,
        client: Client,
        database_id: impl Into<String>,
    ) -> Self {
        let now = unix_now();
        Self {
            state: AdapterState::new(key),
            limit,
            timestamp: align_timestamp(now, seconds),
            count: None,
            client,
            database_id: database_id.into(),
        }
    }

    /// PHP `DATABASE_NAME`.
    pub const DATABASE_NAME: &'static str = DATABASE_NAME;
    /// PHP `TABLE_NAME`.
    pub const TABLE_NAME: &'static str = TABLE_NAME;
    /// PHP `TABLE_ID`.
    pub const TABLE_ID: &'static str = TABLE_ID;
    /// PHP `TABLE_LOCK`.
    pub const TABLE_LOCK: &'static str = TABLE_LOCK;

    /// PHP `setup()`.
    ///
    /// # Errors
    ///
    /// Appwrite API errors other than the tolerated already-exists types.
    pub fn setup(&self) -> Result<(), AbuseError> {
        if self.is_setup_complete() {
            return Ok(());
        }
        self.create_database()?;
        if !self.create_table()? {
            self.create_columns()?;
            self.wait_for_resources_ready("columns")?;
            self.create_indexes()?;
            self.wait_for_resources_ready("indexes")?;
        }
        self.create_lock_table()?;
        Ok(())
    }

    fn is_setup_complete(&self) -> bool {
        self.client
            .request(
                Method::GET,
                &format!("/tablesdb/{}/tables/{TABLE_LOCK}", self.database_id),
                None,
                &[],
            )
            .is_ok()
    }

    fn create_database(&self) -> Result<(), AbuseError> {
        let _ = self.execute_with_silent_error(
            || {
                self.client.request(
                    Method::POST,
                    "/tablesdb",
                    Some(json!({
                        "databaseId": self.database_id,
                        "name": DATABASE_NAME,
                    })),
                    &[],
                )?;
                Ok(())
            },
            "database_already_exists",
        )?;
        Ok(())
    }

    fn create_table(&self) -> Result<bool, AbuseError> {
        self.execute_with_silent_error(
            || {
                self.client.request(
                    Method::POST,
                    &format!("/tablesdb/{}/tables", self.database_id),
                    Some(json!({
                        "tableId": TABLE_ID,
                        "name": TABLE_NAME,
                        "columns": self.column_definitions(),
                        "indexes": self.index_definitions(),
                    })),
                    &[],
                )?;
                Ok(())
            },
            "table_already_exists",
        )
    }

    fn column_definitions(&self) -> Value {
        json!([
            { "key": "key", "type": "string", "size": 255, "required": true },
            { "key": "time", "type": "datetime", "required": true },
            { "key": "count", "type": "integer", "required": true, "min": 0, "max": i64::MAX },
        ])
    }

    fn index_definitions(&self) -> Value {
        json!([
            { "key": "unique1", "type": "unique", "attributes": ["key", "time"] },
            { "key": "index2", "type": "key", "attributes": ["time"] },
        ])
    }

    fn create_columns(&self) -> Result<(), AbuseError> {
        let columns = self.column_definitions();
        let Some(list) = columns.as_array() else {
            return Ok(());
        };
        for column in list {
            let key = column.get("key").and_then(Value::as_str).unwrap_or("");
            let required = column
                .get("required")
                .and_then(Value::as_bool)
                .unwrap_or(false);
            let col_type = column.get("type").and_then(Value::as_str).unwrap_or("");
            let path_body = match col_type {
                "string" => (
                    format!(
                        "/tablesdb/{}/tables/{TABLE_ID}/columns/string",
                        self.database_id
                    ),
                    json!({
                        "key": key,
                        "size": column.get("size").and_then(Value::as_u64).unwrap_or(0),
                        "required": required,
                    }),
                ),
                "datetime" => (
                    format!(
                        "/tablesdb/{}/tables/{TABLE_ID}/columns/datetime",
                        self.database_id
                    ),
                    json!({ "key": key, "required": required }),
                ),
                "integer" => (
                    format!(
                        "/tablesdb/{}/tables/{TABLE_ID}/columns/integer",
                        self.database_id
                    ),
                    json!({
                        "key": key,
                        "required": required,
                        "min": column.get("min").cloned().unwrap_or(json!(0)),
                        "max": column.get("max").cloned().unwrap_or(json!(i64::MAX)),
                    }),
                ),
                _ => return Err(AbuseError::NoColumnEndpoint(key.to_owned())),
            };
            let _ = self.execute_with_silent_error(
                || {
                    self.client.request(
                        Method::POST,
                        &path_body.0,
                        Some(path_body.1.clone()),
                        &[],
                    )?;
                    Ok(())
                },
                "column_already_exists",
            )?;
        }
        Ok(())
    }

    fn create_indexes(&self) -> Result<(), AbuseError> {
        let indexes = self.index_definitions();
        let Some(list) = indexes.as_array() else {
            return Ok(());
        };
        for index in list {
            let _ = self.execute_with_silent_error(
                || {
                    self.client.request(
                        Method::POST,
                        &format!("/tablesdb/{}/tables/{TABLE_ID}/indexes", self.database_id),
                        Some(json!({
                            "key": index.get("key"),
                            "type": index.get("type"),
                            "columns": index.get("attributes"),
                        })),
                        &[],
                    )?;
                    Ok(())
                },
                "index_already_exists",
            )?;
        }
        Ok(())
    }

    fn wait_for_resources_ready(&self, resource_type: &str) -> Result<(), AbuseError> {
        let mut attempts = 0;
        let max_attempts = 15;
        while attempts < max_attempts {
            attempts += 1;
            let path = if resource_type == "columns" {
                format!("/tablesdb/{}/tables/{TABLE_ID}/columns", self.database_id)
            } else {
                format!("/tablesdb/{}/tables/{TABLE_ID}/indexes", self.database_id)
            };
            let queries = vec![Query::not_equal("status", "available"), Query::limit(1)];
            let payload = self.client.request(Method::GET, &path, None, &queries)?;
            let key = if resource_type == "columns" {
                "columns"
            } else {
                "indexes"
            };
            let resources = payload.get(key).and_then(Value::as_array);
            let pending = resources.map_or(0, |items| {
                items
                    .iter()
                    .filter(|item| resource_status(item) != "available")
                    .count()
            });
            if pending == 0 {
                return Ok(());
            }
            thread::sleep(Duration::from_secs(1));
        }
        Err(AbuseError::SetupFailed(resource_type.to_owned()))
    }

    fn create_lock_table(&self) -> Result<(), AbuseError> {
        let _ = self.execute_with_silent_error(
            || {
                self.client.request(
                    Method::POST,
                    &format!("/tablesdb/{}/tables", self.database_id),
                    Some(json!({
                        "tableId": TABLE_LOCK,
                        "name": TABLE_LOCK,
                    })),
                    &[],
                )?;
                Ok(())
            },
            "table_already_exists",
        )?;
        Ok(())
    }

    fn execute_with_silent_error(
        &self,
        callback: impl FnOnce() -> Result<(), AbuseError>,
        allowed: &str,
    ) -> Result<bool, AbuseError> {
        match callback() {
            Ok(()) => Ok(true),
            Err(err) if err.appwrite_type() == Some(allowed) => Ok(false),
            Err(err) => Err(err),
        }
    }

    fn list_rows(&self, queries: &[String]) -> Result<Vec<Value>, AbuseError> {
        let payload = self.client.request(
            Method::GET,
            &format!("/tablesdb/{}/tables/{TABLE_ID}/rows", self.database_id),
            None,
            queries,
        )?;
        Ok(payload
            .get("rows")
            .and_then(Value::as_array)
            .cloned()
            .unwrap_or_default())
    }

    fn count(&mut self, key: &str, timestamp: i64) -> Result<i64, AbuseError> {
        if self.limit == 0 {
            return Ok(0);
        }
        if let Some(count) = self.count {
            return Ok(count);
        }
        let timestamp = format_datetime(timestamp);
        let rows = self.list_rows(&[
            Query::equal("key", &[key]),
            Query::equal("time", &[&timestamp]),
        ])?;
        let mut count = 0;
        if rows.len() == 1 {
            count = row_count(&rows[0]);
        }
        self.count = Some(count);
        Ok(count)
    }

    fn hit(&mut self, key: &str, timestamp: i64) -> Result<(), AbuseError> {
        if self.limit == 0 {
            return Ok(());
        }
        let timestamp = format_datetime(timestamp);
        let rows = self.list_rows(&[
            Query::equal("key", &[key]),
            Query::equal("time", &[&timestamp]),
        ])?;
        let row = rows.first();
        if row.is_none() {
            let data = json!({
                "key": key,
                "time": timestamp,
                "count": 1,
            });
            match self.client.request(
                Method::POST,
                &format!("/tablesdb/{}/tables/{TABLE_ID}/rows", self.database_id),
                Some(json!({
                    "rowId": unique_id(),
                    "data": data,
                })),
                &[],
            ) {
                Ok(_) => {}
                Err(err) if err.appwrite_type() == Some("row_already_exists") => {
                    let rows = self.list_rows(&[
                        Query::equal("key", &[key]),
                        Query::equal("time", &[&timestamp]),
                    ])?;
                    let Some(row) = rows.first() else {
                        return Err(AbuseError::DocumentNotFound);
                    };
                    self.count = Some(row_count(row));
                    self.increment_row(row_id(row))?;
                }
                Err(err) => return Err(err),
            }
        } else {
            self.increment_row(row_id(row.unwrap_or(&Value::Null)))?;
        }
        self.count = Some(self.count.unwrap_or(0) + 1);
        Ok(())
    }

    fn increment_row(&self, row_id: String) -> Result<(), AbuseError> {
        self.client.request(
            Method::PATCH,
            &format!(
                "/tablesdb/{}/tables/{TABLE_ID}/rows/{row_id}/count/increment",
                self.database_id
            ),
            Some(json!({ "value": 1 })),
            &[],
        )?;
        Ok(())
    }

    fn set(&mut self, key: &str, timestamp: i64, value: i64) -> Result<(), AbuseError> {
        let timestamp = format_datetime(timestamp);
        let rows = self.list_rows(&[
            Query::equal("key", &[key]),
            Query::equal("time", &[&timestamp]),
        ])?;
        let row = rows.first();
        if row.is_none() {
            let data = json!({
                "key": key,
                "time": timestamp,
                "count": value,
            });
            match self.client.request(
                Method::POST,
                &format!("/tablesdb/{}/tables/{TABLE_ID}/rows", self.database_id),
                Some(json!({
                    "rowId": unique_id(),
                    "data": data,
                })),
                &[],
            ) {
                Ok(_) => {}
                Err(err) if err.appwrite_type() == Some("row_already_exists") => {
                    let rows = self.list_rows(&[
                        Query::equal("key", &[key]),
                        Query::equal("time", &[&timestamp]),
                    ])?;
                    let Some(row) = rows.first() else {
                        return Err(AbuseError::RowRace);
                    };
                    self.update_row(row_id(row), value)?;
                }
                Err(err) => return Err(err),
            }
        } else {
            self.update_row(row_id(row.unwrap_or(&Value::Null)), value)?;
        }
        self.count = Some(value);
        Ok(())
    }

    fn update_row(&self, row_id: String, value: i64) -> Result<(), AbuseError> {
        self.client.request(
            Method::PATCH,
            &format!(
                "/tablesdb/{}/tables/{TABLE_ID}/rows/{row_id}",
                self.database_id
            ),
            Some(json!({ "data": { "count": value } })),
            &[],
        )?;
        Ok(())
    }

    /// PHP `remaining()`.
    ///
    /// # Errors
    ///
    /// Appwrite failures.
    pub fn remaining(&mut self) -> Result<i64, AbuseError> {
        let key = self.state.parse_key();
        let count = self.count(&key, self.timestamp)?;
        Ok(remaining_from(self.limit, count))
    }

    /// PHP `limit()`.
    #[must_use]
    pub fn limit(&self) -> i64 {
        self.limit
    }

    /// PHP `time()`.
    #[must_use]
    pub fn time(&self) -> i64 {
        self.timestamp
    }
}

impl Adapter for TablesDB {
    fn check(&mut self) -> Result<bool, AbuseError> {
        if self.limit == 0 {
            return Ok(false);
        }
        let key = self.state.parse_key();
        let timestamp = self.timestamp;
        if self.limit > self.count(&key, timestamp)? {
            self.hit(&key, timestamp)?;
            return Ok(false);
        }
        Ok(true)
    }

    fn set_param(&mut self, key: &str, value: &str) -> &mut Self {
        self.state.set_param(key, value);
        self
    }

    fn parse_key(&mut self) -> String {
        self.state.parse_key()
    }

    fn get_logs(&mut self, offset: Option<i64>, limit: Option<i64>) -> Result<Logs, AbuseError> {
        let mut queries = vec![Query::order_desc("")];
        if let Some(offset) = offset {
            queries.push(Query::offset(offset));
        }
        if let Some(limit) = limit {
            queries.push(Query::limit(limit));
        }
        let rows = self.list_rows(&queries)?;
        let docs = rows
            .into_iter()
            .map(|row| {
                let map = row.as_object().cloned().unwrap_or_else(Map::new);
                Document::new(map)
            })
            .collect();
        Ok(Logs::Documents(docs))
    }

    fn cleanup(&mut self, timestamp: i64) -> Result<bool, AbuseError> {
        let timestamp = format_datetime(timestamp);
        loop {
            let payload = self.client.request(
                Method::DELETE,
                &format!("/tablesdb/{}/tables/{TABLE_ID}/rows", self.database_id),
                Some(json!({
                    "queries": [Query::less_than("time", &timestamp)],
                })),
                &[],
            )?;
            let total = payload.get("total").and_then(Value::as_u64).unwrap_or(0);
            if total == 0 {
                break;
            }
        }
        Ok(true)
    }

    fn reset(&mut self) -> Result<(), AbuseError> {
        let key = self.state.parse_key();
        self.set(&key, self.timestamp, 0)
    }
}

fn resource_status(resource: &Value) -> String {
    resource
        .get("status")
        .and_then(Value::as_str)
        .unwrap_or("")
        .to_owned()
}

fn row_count(row: &Value) -> i64 {
    row.get("count")
        .or_else(|| row.get("data").and_then(|data| data.get("count")))
        .and_then(|value| {
            value
                .as_i64()
                .or_else(|| value.as_u64().map(|n| n as i64))
                .or_else(|| value.as_str().and_then(|text| text.parse().ok()))
        })
        .unwrap_or(0)
}

fn row_id(row: &Value) -> String {
    row.get("$id")
        .or_else(|| row.get("id"))
        .and_then(Value::as_str)
        .unwrap_or("")
        .to_owned()
}
