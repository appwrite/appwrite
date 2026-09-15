//! Usage manager. PHP `Utopia\Usage\Usage`.

use serde_json::{Map, Value};

use crate::adapter::Adapter;
use crate::error::{Result, UsageError};
use crate::metric::Metric;
use crate::usage_query::UsageQuery;

/// PHP `Usage::TYPE_EVENT`.
pub const TYPE_EVENT: &str = "event";
/// PHP `Usage::TYPE_GAUGE`.
pub const TYPE_GAUGE: &str = "gauge";

/// PHP `Utopia\Usage\Usage`.
#[allow(missing_debug_implementations)]
pub struct Usage<A: Adapter> {
    adapter: A,
}

impl<A: Adapter> std::fmt::Debug for Usage<A> {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Usage")
            .field("adapter", &self.adapter.get_name())
            .finish()
    }
}

impl<A: Adapter> Usage<A> {
    pub const TYPE_EVENT: &'static str = TYPE_EVENT;
    pub const TYPE_GAUGE: &'static str = TYPE_GAUGE;

    pub fn new(adapter: A) -> Self {
        Self { adapter }
    }

    pub fn get_adapter(&self) -> &A {
        &self.adapter
    }

    pub fn get_adapter_mut(&mut self) -> &mut A {
        &mut self.adapter
    }

    pub fn health_check(&self) -> Map<String, Value> {
        self.adapter.health_check()
    }

    pub fn setup(&mut self) -> Result<()> {
        self.adapter.setup()
    }

    pub fn add_batch(
        &mut self,
        metrics: Vec<Map<String, Value>>,
        type_: &str,
        batch_size: i64,
    ) -> Result<bool> {
        self.adapter.add_batch(metrics, type_, batch_size)
    }

    pub fn get_time_series(
        &self,
        tenant: &str,
        metrics: &[String],
        interval: &str,
        start_date: &str,
        end_date: &str,
        queries: &[UsageQuery],
        zero_fill: bool,
        type_: Option<&str>,
    ) -> Result<Map<String, Value>> {
        self.adapter.get_time_series(
            tenant, metrics, interval, start_date, end_date, queries, zero_fill, type_,
        )
    }

    pub fn get_total(
        &self,
        tenant: &str,
        metric: &str,
        queries: &[UsageQuery],
        type_: Option<&str>,
    ) -> Result<i64> {
        self.adapter.get_total(tenant, metric, queries, type_)
    }

    pub fn get_total_batch(
        &self,
        tenant: &str,
        metrics: &[String],
        queries: &[UsageQuery],
        type_: Option<&str>,
    ) -> Result<Map<String, Value>> {
        self.adapter
            .get_total_batch(tenant, metrics, queries, type_)
    }

    pub fn purge(
        &mut self,
        tenant: &str,
        queries: &[UsageQuery],
        type_: Option<&str>,
    ) -> Result<bool> {
        self.adapter.purge(tenant, queries, type_)
    }

    pub fn find(
        &self,
        tenant: &str,
        queries: &[UsageQuery],
        type_: Option<&str>,
    ) -> Result<Vec<Metric>> {
        self.adapter.find(tenant, queries, type_)
    }

    pub fn find_across_tenants(
        &self,
        queries: &[UsageQuery],
        type_: Option<&str>,
    ) -> Result<Vec<Metric>> {
        self.adapter.find_across_tenants(queries, type_)
    }

    pub fn count(
        &self,
        tenant: &str,
        queries: &[UsageQuery],
        type_: Option<&str>,
        max: Option<i64>,
    ) -> Result<i64> {
        self.adapter.count(tenant, queries, type_, max)
    }

    pub fn sum(
        &self,
        tenant: &str,
        queries: &[UsageQuery],
        attribute: &str,
        type_: &str,
    ) -> Result<i64> {
        if type_ != Self::TYPE_EVENT && type_ != Self::TYPE_GAUGE {
            return Err(UsageError::message(format!(
                "Invalid type '{type_}'. Allowed: {}, {}",
                Self::TYPE_EVENT,
                Self::TYPE_GAUGE
            )));
        }
        self.adapter.sum(tenant, queries, attribute, type_)
    }

    pub fn find_daily(&self, tenant: &str, queries: &[UsageQuery]) -> Result<Vec<Metric>> {
        self.adapter.find_daily(tenant, queries)
    }

    pub fn sum_daily(&self, tenant: &str, queries: &[UsageQuery], attribute: &str) -> Result<i64> {
        self.adapter.sum_daily(tenant, queries, attribute)
    }

    pub fn sum_daily_batch(
        &self,
        tenant: &str,
        metrics: &[String],
        queries: &[UsageQuery],
    ) -> Result<Map<String, Value>> {
        self.adapter.sum_daily_batch(tenant, metrics, queries)
    }
}
