//! [`Adapter`]: where metrics are stored, and the values crossing it.

use async_trait::async_trait;
use indexmap::IndexMap;
use php_std::datetime::DateTime;
use utopia_query::{Array, Query};

use crate::error::{Error, Result};
use crate::metric::{Kind, Metric, Number};
use crate::sample::{Sample, SampleRange, SampleResult, SampleWatermark};

#[cfg(feature = "clickhouse")]
pub mod clickhouse;
#[cfg(feature = "database")]
pub mod database;

/// When a metric was emitted: a `DateTime`, or a string a `DateTime` is
/// made from.
///
/// PHP: the `time` of an `addBatch()` entry (`\DateTime|string`).
#[derive(Debug, Clone)]
pub enum Time {
    /// A date and time.
    At(Box<DateTime>),
    /// A date string (`new DateTime($text)`).
    Text(String),
}

/// One metric to write.
///
/// PHP: an entry of the `$metrics` array of `addBatch()`
/// (`['tenant' => ..., 'metric' => ..., 'value' => ..., 'tags' => [...], 'time' => ..., 'allowNegative' => ...]`).
#[derive(Debug, Clone, Default)]
pub struct Entry {
    /// The tenant (shared tables).
    pub tenant: Option<String>,
    /// The metric name.
    pub metric: String,
    /// The value.
    pub value: i64,
    /// Dimension tags: keys are the kind's columns (`path`, `service`, ...).
    pub tags: Array,
    /// When it was emitted (now when absent).
    pub time: Option<Time>,
    /// Whether a negative value is a genuine signed delta rather than a bug.
    pub allow_negative: bool,
}

impl Entry {
    /// An entry of `tenant`, `metric` and `value`, without tags.
    pub fn new(tenant: impl Into<String>, metric: impl Into<String>, value: i64) -> Entry {
        Entry { tenant: Some(tenant.into()), metric: metric.into(), value, ..Entry::default() }
    }

    /// The entry with `tags`.
    pub fn with_tags(mut self, tags: Array) -> Entry {
        self.tags = tags;
        self
    }

    /// The entry emitted at `time`.
    pub fn at(mut self, time: Time) -> Entry {
        self.time = Some(time);
        self
    }
}

/// One point of a time series.
#[derive(Debug, Clone, PartialEq)]
pub struct Point {
    /// The bucket's value.
    pub value: f64,
    /// The bucket, `Y-m-d\TH:i:s+00:00`.
    pub date: String,
}

/// One metric's time series and its total.
#[derive(Debug, Clone, PartialEq)]
pub struct Series {
    /// The sum of the points: a float once a table was read (`0` when none was).
    pub total: Number,
    /// The points, by bucket.
    pub data: Vec<Point>,
}

/// Time series by metric name, in the order the metrics were asked for.
pub type TimeSeries = IndexMap<String, Series>;

/// Totals by metric name, in the order the metrics were asked for.
pub type Totals = IndexMap<String, i64>;

/// A storage backend for usage metrics. Implement it to store metrics
/// elsewhere; the provided methods refuse what a backend does not support.
///
/// PHP: `Utopia\Usage\Adapter`.
#[async_trait]
pub trait Adapter: Send + Sync {
    /// The adapter's name.
    ///
    /// PHP: `getName()`.
    fn name(&self) -> &str;

    /// Connection and storage health: `healthy`, plus what the adapter
    /// knows (database, collection, version, timing, error).
    ///
    /// PHP: `healthCheck()`.
    async fn health_check(&self) -> Array;

    /// Creates the storage.
    ///
    /// PHP: `setup()`.
    async fn setup(&self) -> Result<()>;

    /// Brings an existing storage up to date without changing any policy
    /// [`Adapter::setup`] applies to it, such as retention. Adapters whose
    /// `setup` does more than create missing structure override this; by
    /// default it is `setup`.
    ///
    /// PHP: `migrate()`.
    async fn migrate(&self) -> Result<()> {
        self.setup().await
    }

    /// Appends `metrics` of `kind`, at most `batch_size` per write.
    ///
    /// PHP: `addBatch($metrics, $type, $batchSize)`.
    async fn add_batch(&self, metrics: &[Entry], kind: Kind, batch_size: i64) -> Result<bool>;

    /// Appends canonical samples.
    ///
    /// PHP: `addSamples($samples, $batchSize)`.
    async fn add_samples(&self, _samples: &[Sample], _batch_size: i64) -> Result<bool> {
        Err(Error::exception(format!("{} does not support canonical samples", self.name())))
    }

    /// Captures at most `limit` entries of one exact range.
    ///
    /// PHP: `getSampleWatermark($range, $limit)`.
    async fn sample_watermark(&self, _range: &SampleRange, _limit: i64) -> Result<SampleWatermark> {
        Err(Error::exception(format!("{} does not support canonical samples", self.name())))
    }

    /// Reads the samples of `range` that `watermark` captured.
    ///
    /// PHP: `findSamples($range, $watermark, $limit)`.
    async fn find_samples(
        &self,
        _range: &SampleRange,
        _watermark: &SampleWatermark,
        _limit: i64,
    ) -> Result<SampleResult> {
        Err(Error::exception(format!("{} does not support canonical samples", self.name())))
    }

    /// Time series of `metrics` bucketed by `interval` (`1h` or `1d`)
    /// between `start` and `end`.
    ///
    /// PHP: `getTimeSeries(...)`.
    #[allow(clippy::too_many_arguments)]
    async fn time_series(
        &self,
        tenant: &str,
        metrics: &[String],
        interval: &str,
        start: &str,
        end: &str,
        queries: &[Query],
        zero_fill: bool,
        kind: Option<Kind>,
    ) -> Result<TimeSeries>;

    /// The total of one metric: a sum of events, the latest gauge.
    ///
    /// PHP: `getTotal(...)`.
    async fn total(&self, tenant: &str, metric: &str, queries: &[Query], kind: Option<Kind>) -> Result<i64>;

    /// The totals of several metrics.
    ///
    /// PHP: `getTotalBatch(...)`.
    async fn total_batch(
        &self,
        tenant: &str,
        metrics: &[String],
        queries: &[Query],
        kind: Option<Kind>,
    ) -> Result<Totals>;

    /// Deletes the metrics matching `queries` (all when empty).
    ///
    /// PHP: `purge(...)`.
    async fn purge(&self, tenant: &str, queries: &[Query], kind: Option<Kind>) -> Result<bool>;

    /// The metrics matching `queries`.
    ///
    /// PHP: `find(...)`.
    async fn find(&self, tenant: &str, queries: &[Query], kind: Option<Kind>) -> Result<Vec<Metric>>;

    /// The metrics of every tenant matching `queries` (operator jobs only).
    ///
    /// PHP: `findAcrossTenants(...)`.
    async fn find_across_tenants(&self, _queries: &[Query], _kind: Option<Kind>) -> Result<Vec<Metric>> {
        Err(Error::exception(format!("{} does not support cross-tenant reads", self.name())))
    }

    /// How many metrics match, at most `max` when given.
    ///
    /// PHP: `count(...)`.
    async fn count(&self, tenant: &str, queries: &[Query], kind: Option<Kind>, max: Option<i64>) -> Result<i64>;

    /// The sum of `attribute` over the matching metrics.
    ///
    /// PHP: `sum(...)`.
    async fn sum(&self, tenant: &str, queries: &[Query], attribute: &str, kind: Kind) -> Result<i64>;

    /// Event metrics from the daily rollup.
    ///
    /// PHP: `findDaily(...)`.
    async fn find_daily(&self, tenant: &str, queries: &[Query]) -> Result<Vec<Metric>>;

    /// The sum of `attribute` over the daily rollup.
    ///
    /// PHP: `sumDaily(...)`.
    async fn sum_daily(&self, tenant: &str, queries: &[Query], attribute: &str) -> Result<i64>;

    /// Daily rollup sums of several metrics.
    ///
    /// PHP: `sumDailyBatch(...)`.
    async fn sum_daily_batch(&self, tenant: &str, metrics: &[String], queries: &[Query]) -> Result<Totals>;

    /// Rebuilds the daily rollup from raw events for `[from, to)`.
    ///
    /// PHP: `backfillDaily($from, $to, $force)`, whose base refuses with
    /// the adapter's class name (here its [`Adapter::name`]).
    async fn backfill_daily(&self, _from: &str, _to: &str, _force: bool) -> Result<()> {
        Err(Error::exception(format!("{} does not maintain a daily rollup to backfill.", self.name())))
    }
}
