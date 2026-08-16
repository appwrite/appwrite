//! Usage adapters.

pub mod clickhouse;
pub mod database;
pub mod memory;
pub mod sql;

use serde_json::{Map, Value};

use crate::error::Result;
use crate::metric::Metric;
use crate::usage::{TYPE_EVENT, TYPE_GAUGE};
use crate::usage_query::UsageQuery;

pub use clickhouse::ClickHouse;
pub use database::DatabaseAdapter;
pub use memory::Memory;
pub use sql::SqlAdapter;

/// PHP `Utopia\Usage\Adapter`.
pub trait Adapter: Send + Sync {
    fn get_name(&self) -> &'static str;
    fn health_check(&self) -> Map<String, Value>;
    fn setup(&mut self) -> Result<()>;
    fn add_batch(
        &mut self,
        metrics: Vec<Map<String, Value>>,
        type_: &str,
        batch_size: i64,
    ) -> Result<bool>;
    fn get_time_series(
        &self,
        tenant: &str,
        metrics: &[String],
        interval: &str,
        start_date: &str,
        end_date: &str,
        queries: &[UsageQuery],
        zero_fill: bool,
        type_: Option<&str>,
    ) -> Result<Map<String, Value>>;
    fn get_total(
        &self,
        tenant: &str,
        metric: &str,
        queries: &[UsageQuery],
        type_: Option<&str>,
    ) -> Result<i64>;
    fn get_total_batch(
        &self,
        tenant: &str,
        metrics: &[String],
        queries: &[UsageQuery],
        type_: Option<&str>,
    ) -> Result<Map<String, Value>>;
    fn purge(&mut self, tenant: &str, queries: &[UsageQuery], type_: Option<&str>) -> Result<bool>;
    fn find(
        &self,
        tenant: &str,
        queries: &[UsageQuery],
        type_: Option<&str>,
    ) -> Result<Vec<Metric>>;
    fn find_across_tenants(
        &self,
        _queries: &[UsageQuery],
        _type_: Option<&str>,
    ) -> Result<Vec<Metric>> {
        Err(crate::error::UsageError::message(format!(
            "{} does not support cross-tenant reads",
            self.get_name()
        )))
    }
    fn count(
        &self,
        tenant: &str,
        queries: &[UsageQuery],
        type_: Option<&str>,
        max: Option<i64>,
    ) -> Result<i64>;
    fn sum(
        &self,
        tenant: &str,
        queries: &[UsageQuery],
        attribute: &str,
        type_: &str,
    ) -> Result<i64>;
    fn find_daily(&self, tenant: &str, queries: &[UsageQuery]) -> Result<Vec<Metric>>;
    fn sum_daily(&self, tenant: &str, queries: &[UsageQuery], attribute: &str) -> Result<i64>;
    fn sum_daily_batch(
        &self,
        tenant: &str,
        metrics: &[String],
        queries: &[UsageQuery],
    ) -> Result<Map<String, Value>>;
}

/// PHP `Usage::TYPE_*`.
pub fn is_type(type_: &str) -> bool {
    type_ == TYPE_EVENT || type_ == TYPE_GAUGE
}
