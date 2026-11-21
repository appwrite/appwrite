//! Usage metrics: events (additive: requests, bandwidth) summed, gauges
//! (snapshots: storage, users) read as their latest value, written in
//! batches and read back as rows, totals, time series and dimensional
//! breakdowns.
//!
//! [`Usage`] is the entry point over an [`Adapter`]: ClickHouse
//! ([`adapter::clickhouse`], feature `clickhouse`), with a daily rollup and
//! projections it routes reads through, or one collection of a
//! `utopia-database` database ([`adapter::database`], feature `database`).
//! [`Tenant`] binds a tenant, [`Accumulator`] buffers metrics in memory
//! and flushes them in batches, and [`Sample`]s are canonical observations
//! read back with evidence of their completeness.
//!
//! Both runtimes read and write the same tables, so metrics written by a
//! PHP service are read identically by a Rust one, and the other way round.
//!
//! PHP: `utopia-php/usage`.
//!
//! | PHP | Rust |
//! |---|---|
//! | `Usage` (`TYPE_EVENT`, `TYPE_GAUGE`) | [`Usage`] ([`Kind`]) |
//! | `Tenant` | [`Tenant`] |
//! | `Accumulator` | [`Accumulator`] |
//! | `Metric` (`EVENT_COLUMNS`, `GAUGE_COLUMNS`) | [`Metric`] ([`metric::EVENT_COLUMNS`], [`metric::GAUGE_COLUMNS`], [`metric::Attribute`], [`metric::Index`]) |
//! | `UsageQuery` | [`query`] (queries are [`utopia_query::Query`] values) |
//! | `Sample`, `SampleRange`, `SampleWatermark`, `SampleGap`, `SampleResult` | [`Sample`], [`SampleRange`], [`SampleWatermark`], [`SampleGap`], [`SampleResult`] |
//! | `Adapter` | [`Adapter`] (entries: [`Entry`]) |
//! | `Adapter\SQL` | the schema methods of [`Metric`] and [`Kind`] |
//! | `Adapter\Database` | [`adapter::database::Database`] |
//! | `Adapter\ClickHouse` | [`adapter::clickhouse::ClickHouse`] ([`adapter::clickhouse::Config`]) |
//! | `\Exception`, `\InvalidArgumentException` | [`Error`] |

#![warn(missing_docs)]

/// The getting started guide (`guide.md`), compiled and run with the doctests.
#[cfg(doctest)]
#[doc = include_str!("../guide.md")]
pub struct GettingStarted;

mod accumulator;
pub mod adapter;
mod error;
pub mod metric;
mod php;
pub mod query;
mod sample;
mod usage;

pub use accumulator::Accumulator;
pub use adapter::{Adapter, Entry, Point, Series, Time, TimeSeries, Totals};
pub use error::{Error, Message, Result};
pub use metric::{Kind, Metric, Number};
pub use sample::{Sample, SampleGap, SampleRange, SampleResult, SampleWatermark};
pub use usage::{BATCH_SIZE, Tenant, Usage};
