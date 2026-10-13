//! [`Usage`], the entry point, and [`Tenant`], a view bound to one tenant.

use std::sync::Arc;

use utopia_query::{Array, Query};

use crate::adapter::{Adapter, Entry, TimeSeries, Totals};
use crate::error::{Error, Result};
use crate::metric::{Kind, Metric};
use crate::sample::{Sample, SampleRange, SampleResult, SampleWatermark};

/// The default number of metrics per write.
pub const BATCH_SIZE: i64 = 1000;

/// Usage metrics over an [`Adapter`]: events (summed) and gauges (latest
/// value) written in batches and read back as rows, totals and series.
/// Cloning shares the adapter.
///
/// PHP: `Utopia\Usage\Usage`.
#[derive(Clone)]
pub struct Usage {
    adapter: Arc<dyn Adapter>,
}

impl std::fmt::Debug for Usage {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Usage").field("adapter", &self.adapter.name()).finish()
    }
}

impl Usage {
    /// Usage over `adapter`.
    ///
    /// PHP: `new Usage($adapter)`.
    pub fn new(adapter: Arc<dyn Adapter>) -> Usage {
        Usage { adapter }
    }

    /// The adapter.
    ///
    /// PHP: `getAdapter()`.
    pub fn adapter(&self) -> &Arc<dyn Adapter> {
        &self.adapter
    }

    /// PHP: `healthCheck()`.
    pub async fn health_check(&self) -> Array {
        self.adapter.health_check().await
    }

    /// PHP: `setup()`.
    pub async fn setup(&self) -> Result<()> {
        self.adapter.setup().await
    }

    /// Appends `metrics` of `kind`, at most `batch_size` per write
    /// ([`BATCH_SIZE`] by default in PHP). Each entry carries its own tenant.
    ///
    /// PHP: `addBatch($metrics, $type, $batchSize)`.
    pub async fn add_batch(&self, metrics: &[Entry], kind: Kind, batch_size: i64) -> Result<bool> {
        self.adapter.add_batch(metrics, kind, batch_size).await
    }

    /// PHP: `addSamples($samples, $batchSize)`.
    pub async fn add_samples(&self, samples: &[Sample], batch_size: i64) -> Result<bool> {
        self.adapter.add_samples(samples, batch_size).await
    }

    /// PHP: `getSampleWatermark($range, $limit)`.
    pub async fn sample_watermark(&self, range: &SampleRange, limit: i64) -> Result<SampleWatermark> {
        self.adapter.sample_watermark(range, limit).await
    }

    /// PHP: `findSamples($range, $watermark, $limit)`.
    pub async fn find_samples(
        &self,
        range: &SampleRange,
        watermark: &SampleWatermark,
        limit: i64,
    ) -> Result<SampleResult> {
        self.adapter.find_samples(range, watermark, limit).await
    }

    /// PHP: `getTimeSeries($tenant, $metrics, $interval, $startDate, $endDate, $queries, $zeroFill, $type)`.
    #[allow(clippy::too_many_arguments)]
    pub async fn time_series(
        &self,
        tenant: &str,
        metrics: &[String],
        interval: &str,
        start: &str,
        end: &str,
        queries: &[Query],
        zero_fill: bool,
        kind: Option<Kind>,
    ) -> Result<TimeSeries> {
        self.adapter.time_series(tenant, metrics, interval, start, end, queries, zero_fill, kind).await
    }

    /// PHP: `getTotal($tenant, $metric, $queries, $type)`.
    pub async fn total(&self, tenant: &str, metric: &str, queries: &[Query], kind: Option<Kind>) -> Result<i64> {
        self.adapter.total(tenant, metric, queries, kind).await
    }

    /// PHP: `getTotalBatch($tenant, $metrics, $queries, $type)`.
    pub async fn total_batch(
        &self,
        tenant: &str,
        metrics: &[String],
        queries: &[Query],
        kind: Option<Kind>,
    ) -> Result<Totals> {
        self.adapter.total_batch(tenant, metrics, queries, kind).await
    }

    /// PHP: `purge($tenant, $queries, $type)`.
    pub async fn purge(&self, tenant: &str, queries: &[Query], kind: Option<Kind>) -> Result<bool> {
        self.adapter.purge(tenant, queries, kind).await
    }

    /// PHP: `find($tenant, $queries, $type)`.
    pub async fn find(&self, tenant: &str, queries: &[Query], kind: Option<Kind>) -> Result<Vec<Metric>> {
        self.adapter.find(tenant, queries, kind).await
    }

    /// Every tenant's metrics: for operator-side aggregation only, never a
    /// tenant-scoped request (pair with a `groupBy` on `tenant`).
    ///
    /// PHP: `findAcrossTenants($queries, $type)`.
    pub async fn find_across_tenants(&self, queries: &[Query], kind: Option<Kind>) -> Result<Vec<Metric>> {
        self.adapter.find_across_tenants(queries, kind).await
    }

    /// PHP: `count($tenant, $queries, $type, $max)`.
    pub async fn count(&self, tenant: &str, queries: &[Query], kind: Option<Kind>, max: Option<i64>) -> Result<i64> {
        self.adapter.count(tenant, queries, kind, max).await
    }

    /// PHP: `sum($tenant, $queries, $attribute, $type)` (events by default there).
    pub async fn sum(&self, tenant: &str, queries: &[Query], attribute: &str, kind: Kind) -> Result<i64> {
        self.adapter.sum(tenant, queries, attribute, kind).await
    }

    /// PHP: `findDaily($tenant, $queries)`.
    pub async fn find_daily(&self, tenant: &str, queries: &[Query]) -> Result<Vec<Metric>> {
        self.adapter.find_daily(tenant, queries).await
    }

    /// PHP: `sumDaily($tenant, $queries, $attribute)`.
    pub async fn sum_daily(&self, tenant: &str, queries: &[Query], attribute: &str) -> Result<i64> {
        self.adapter.sum_daily(tenant, queries, attribute).await
    }

    /// PHP: `sumDailyBatch($tenant, $metrics, $queries)`.
    pub async fn sum_daily_batch(&self, tenant: &str, metrics: &[String], queries: &[Query]) -> Result<Totals> {
        self.adapter.sum_daily_batch(tenant, metrics, queries).await
    }

    /// PHP: `backfillDaily($from, $to, $force)`.
    pub async fn backfill_daily(&self, from: &str, to: &str, force: bool) -> Result<()> {
        self.adapter.backfill_daily(from, to, force).await
    }
}

/// A [`Usage`] bound to one tenant, which every call passes on.
///
/// PHP: `Utopia\Usage\Tenant`.
#[derive(Debug, Clone)]
pub struct Tenant {
    usage: Usage,
    tenant: String,
}

impl Tenant {
    /// A view of `usage` for `tenant` (not empty; `"0"` is a tenant).
    ///
    /// PHP: `new Tenant($usage, $tenant)`.
    pub fn new(usage: Usage, tenant: impl Into<String>) -> Result<Tenant> {
        let tenant = tenant.into();
        if tenant.is_empty() {
            return Err(Error::invalid_argument("Tenant cannot be empty"));
        }
        Ok(Tenant { usage, tenant })
    }

    /// The tenant.
    pub fn tenant(&self) -> &str {
        &self.tenant
    }

    /// Appends `metrics`, each stamped with this tenant.
    ///
    /// PHP: `addBatch($metrics, $type, $batchSize)`.
    pub async fn add_batch(&self, metrics: &[Entry], kind: Kind, batch_size: i64) -> Result<bool> {
        let stamped: Vec<Entry> =
            metrics.iter().map(|m| Entry { tenant: Some(self.tenant.clone()), ..m.clone() }).collect();
        self.usage.add_batch(&stamped, kind, batch_size).await
    }

    /// PHP: `getTimeSeries(...)`.
    #[allow(clippy::too_many_arguments)]
    pub async fn time_series(
        &self,
        metrics: &[String],
        interval: &str,
        start: &str,
        end: &str,
        queries: &[Query],
        zero_fill: bool,
        kind: Option<Kind>,
    ) -> Result<TimeSeries> {
        self.usage.time_series(&self.tenant, metrics, interval, start, end, queries, zero_fill, kind).await
    }

    /// PHP: `getTotal(...)`.
    pub async fn total(&self, metric: &str, queries: &[Query], kind: Option<Kind>) -> Result<i64> {
        self.usage.total(&self.tenant, metric, queries, kind).await
    }

    /// PHP: `getTotalBatch(...)`.
    pub async fn total_batch(&self, metrics: &[String], queries: &[Query], kind: Option<Kind>) -> Result<Totals> {
        self.usage.total_batch(&self.tenant, metrics, queries, kind).await
    }

    /// PHP: `purge(...)`.
    pub async fn purge(&self, queries: &[Query], kind: Option<Kind>) -> Result<bool> {
        self.usage.purge(&self.tenant, queries, kind).await
    }

    /// PHP: `find(...)`.
    pub async fn find(&self, queries: &[Query], kind: Option<Kind>) -> Result<Vec<Metric>> {
        self.usage.find(&self.tenant, queries, kind).await
    }

    /// PHP: `count(...)`.
    pub async fn count(&self, queries: &[Query], kind: Option<Kind>, max: Option<i64>) -> Result<i64> {
        self.usage.count(&self.tenant, queries, kind, max).await
    }

    /// PHP: `sum(...)`.
    pub async fn sum(&self, queries: &[Query], attribute: &str, kind: Kind) -> Result<i64> {
        self.usage.sum(&self.tenant, queries, attribute, kind).await
    }

    /// PHP: `findDaily(...)`.
    pub async fn find_daily(&self, queries: &[Query]) -> Result<Vec<Metric>> {
        self.usage.find_daily(&self.tenant, queries).await
    }

    /// PHP: `sumDaily(...)`.
    pub async fn sum_daily(&self, queries: &[Query], attribute: &str) -> Result<i64> {
        self.usage.sum_daily(&self.tenant, queries, attribute).await
    }

    /// PHP: `sumDailyBatch(...)`.
    pub async fn sum_daily_batch(&self, metrics: &[String], queries: &[Query]) -> Result<Totals> {
        self.usage.sum_daily_batch(&self.tenant, metrics, queries).await
    }
}
