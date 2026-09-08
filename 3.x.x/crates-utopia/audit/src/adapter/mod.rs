//! Abstract audit adapter. PHP `Utopia\Audit\Adapter`.

pub mod clickhouse;
pub mod database;
pub mod memory;
pub mod sql;

use chrono::{DateTime, Utc};

use crate::error::Result;
use crate::log::Log;
use crate::query::Query;

pub use clickhouse::ClickHouse;
pub use database::DatabaseAdapter;
pub use memory::Memory;
pub use sql::{parse_resource, ParsedResource, SqlAdapter, COLLECTION};

/// Pluggable audit storage backend.
pub trait Adapter: Send + Sync {
    fn get_name(&self) -> &'static str;

    fn setup(&mut self) -> Result<()>;

    fn get_by_id(&self, id: &str) -> Result<Option<Log>>;

    fn create(&mut self, log: serde_json::Map<String, serde_json::Value>) -> Result<Log>;

    fn create_batch(
        &mut self,
        logs: Vec<serde_json::Map<String, serde_json::Value>>,
    ) -> Result<bool>;

    fn get_by_user(
        &self,
        user_id: &str,
        after: Option<DateTime<Utc>>,
        before: Option<DateTime<Utc>>,
        limit: i64,
        offset: i64,
        ascending: bool,
    ) -> Result<Vec<Log>>;

    fn count_by_user(
        &self,
        user_id: &str,
        after: Option<DateTime<Utc>>,
        before: Option<DateTime<Utc>>,
        max: Option<i64>,
    ) -> Result<i64>;

    fn get_by_resource(
        &self,
        resource: &str,
        after: Option<DateTime<Utc>>,
        before: Option<DateTime<Utc>>,
        limit: i64,
        offset: i64,
        ascending: bool,
    ) -> Result<Vec<Log>>;

    fn count_by_resource(
        &self,
        resource: &str,
        after: Option<DateTime<Utc>>,
        before: Option<DateTime<Utc>>,
        max: Option<i64>,
    ) -> Result<i64>;

    fn get_by_user_and_events(
        &self,
        user_id: &str,
        events: &[String],
        after: Option<DateTime<Utc>>,
        before: Option<DateTime<Utc>>,
        limit: i64,
        offset: i64,
        ascending: bool,
    ) -> Result<Vec<Log>>;

    fn count_by_user_and_events(
        &self,
        user_id: &str,
        events: &[String],
        after: Option<DateTime<Utc>>,
        before: Option<DateTime<Utc>>,
        max: Option<i64>,
    ) -> Result<i64>;

    fn get_by_resource_and_events(
        &self,
        resource: &str,
        events: &[String],
        after: Option<DateTime<Utc>>,
        before: Option<DateTime<Utc>>,
        limit: i64,
        offset: i64,
        ascending: bool,
    ) -> Result<Vec<Log>>;

    fn count_by_resource_and_events(
        &self,
        resource: &str,
        events: &[String],
        after: Option<DateTime<Utc>>,
        before: Option<DateTime<Utc>>,
        max: Option<i64>,
    ) -> Result<i64>;

    fn cleanup(&mut self, datetime: DateTime<Utc>) -> Result<bool>;

    fn find(&self, queries: &[Query]) -> Result<Vec<Log>>;

    fn count(&self, queries: &[Query], max: Option<i64>) -> Result<i64>;

    fn ping(&self) -> bool;
}
