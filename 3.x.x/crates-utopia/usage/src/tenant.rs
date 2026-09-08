//! Tenant-scoped Usage view. PHP `Utopia\Usage\Tenant`.

use serde_json::{json, Map, Value};

use crate::adapter::Adapter;
use crate::error::{Result, UsageError};
use crate::metric::Metric;
use crate::usage::Usage;
use crate::usage_query::UsageQuery;

#[allow(missing_debug_implementations)]
pub struct Tenant<A: Adapter> {
    usage: Usage<A>,
    tenant: String,
}

impl<A: Adapter> std::fmt::Debug for Tenant<A> {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Tenant")
            .field("tenant", &self.tenant)
            .finish_non_exhaustive()
    }
}

impl<A: Adapter> Tenant<A> {
    pub fn new(usage: Usage<A>, tenant: impl Into<String>) -> Result<Self> {
        let tenant = tenant.into();
        if tenant.is_empty() {
            return Err(UsageError::message("Tenant cannot be empty"));
        }
        Ok(Self { usage, tenant })
    }

    pub fn add_batch(
        &mut self,
        mut metrics: Vec<Map<String, Value>>,
        type_: &str,
        batch_size: i64,
    ) -> Result<bool> {
        for metric in &mut metrics {
            metric.insert("tenant".into(), json!(self.tenant));
        }
        self.usage.add_batch(metrics, type_, batch_size)
    }

    pub fn get_time_series(
        &self,
        metrics: &[String],
        interval: &str,
        start_date: &str,
        end_date: &str,
        queries: &[UsageQuery],
        zero_fill: bool,
        type_: Option<&str>,
    ) -> Result<Map<String, Value>> {
        self.usage.get_time_series(
            &self.tenant,
            metrics,
            interval,
            start_date,
            end_date,
            queries,
            zero_fill,
            type_,
        )
    }

    pub fn get_total(
        &self,
        metric: &str,
        queries: &[UsageQuery],
        type_: Option<&str>,
    ) -> Result<i64> {
        self.usage.get_total(&self.tenant, metric, queries, type_)
    }

    pub fn get_total_batch(
        &self,
        metrics: &[String],
        queries: &[UsageQuery],
        type_: Option<&str>,
    ) -> Result<Map<String, Value>> {
        self.usage
            .get_total_batch(&self.tenant, metrics, queries, type_)
    }

    pub fn purge(&mut self, queries: &[UsageQuery], type_: Option<&str>) -> Result<bool> {
        self.usage.purge(&self.tenant, queries, type_)
    }

    pub fn find(&self, queries: &[UsageQuery], type_: Option<&str>) -> Result<Vec<Metric>> {
        self.usage.find(&self.tenant, queries, type_)
    }

    pub fn count(
        &self,
        queries: &[UsageQuery],
        type_: Option<&str>,
        max: Option<i64>,
    ) -> Result<i64> {
        self.usage.count(&self.tenant, queries, type_, max)
    }

    pub fn sum(&self, queries: &[UsageQuery], attribute: &str, type_: &str) -> Result<i64> {
        self.usage.sum(&self.tenant, queries, attribute, type_)
    }

    pub fn find_daily(&self, queries: &[UsageQuery]) -> Result<Vec<Metric>> {
        self.usage.find_daily(&self.tenant, queries)
    }

    pub fn sum_daily(&self, queries: &[UsageQuery], attribute: &str) -> Result<i64> {
        self.usage.sum_daily(&self.tenant, queries, attribute)
    }

    pub fn sum_daily_batch(
        &self,
        metrics: &[String],
        queries: &[UsageQuery],
    ) -> Result<Map<String, Value>> {
        self.usage.sum_daily_batch(&self.tenant, metrics, queries)
    }
}
