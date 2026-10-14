# Utopia Usage

> [!IMPORTANT]
> This repository is a read-only mirror of [`crates/utopia/usage`](https://github.com/appwrite/appwrite/tree/main/crates/utopia/usage) in [appwrite/appwrite](https://github.com/appwrite/appwrite). Development happens there — please open issues and pull requests against appwrite/appwrite.

[![Discord](https://img.shields.io/discord/564160730845151244?label=discord)](https://discord.gg/GSeTUeA)

Usage metrics: events summed and gauges sampled, written in batches and read as totals, time series and dimensional breakdowns over ClickHouse or a utopia-database collection; canonical samples with completeness evidence.

This library is maintained by the [Appwrite team](https://appwrite.io).

## Getting started

```toml
[dependencies]
utopia-usage = { git = "https://github.com/utopia-rs/usage" }
```

Usage metrics count what tenants use: requests and bandwidth (events, which add up) and storage or users (gauges, snapshots whose latest value counts). This library writes them in batches and reads them back as rows, totals, time series and breakdowns by dimension (service, path, country, ...). It stores them in ClickHouse, where a daily rollup and per-dimension projections keep large reads cheap, or in a collection of a `utopia-database` database. Adapters are async, so the examples run them on a Tokio runtime. This guide covers the common tasks.

## Buffer metrics and write them in batches

A worker sees one metric at a time. An `Accumulator` folds them in memory: events with the same tenant, metric and tags are summed, a gauge keeps its last value. `count` says how many rows a flush would write and `elapsed_seconds` how long it has been since the last one, so the caller decides when to `flush`. Negative values are refused unless the call says they are signed deltas.

```rust
use std::sync::Arc;

use utopia_query::{Array, Value};
use utopia_usage::adapter::clickhouse::{ClickHouse, Config};
use utopia_usage::{Accumulator, Kind, Usage};

let usage = Usage::new(Arc::new(ClickHouse::new(Config::new("clickhouse"))?));
let mut accumulator = Accumulator::new(usage);

let mut tags = Array::default();
tags.set("service".into(), Value::from("storage"));
accumulator.collect("42", "bandwidth", 1024, Kind::Event, tags.clone(), None, false)?;
accumulator.collect("42", "bandwidth", 2048, Kind::Event, tags, None, false)?;
accumulator.collect("42", "storage", 10_000, Kind::Gauge, Array::default(), None, false)?;
assert_eq!(accumulator.count(), 2);

let error = accumulator.collect("42", "bandwidth", -1, Kind::Event, Array::default(), None, false).unwrap_err();
assert_eq!(error.message(), "Value cannot be negative");
```

## Tag metrics with dimensions

Tags become dedicated columns: events carry the request's path, method, status, service, resource, team, geography and client; gauges their service, resource, team and replica ordinal. `extract_columns` is what an adapter stores for a row: scalars as strings, empty strings as nothing, country and region lower-cased, and an unknown tag refused rather than dropped.

```rust
use utopia_query::{Array, Value};
use utopia_usage::{Kind, Metric};

let mut tags = Array::default();
tags.set("country".into(), Value::from("US"));
tags.set("status".into(), Value::Int(201));
tags.set("path".into(), Value::from(""));
let columns = Metric::extract_columns(&tags, Kind::Event)?;

let column = |name: &str| columns.iter().find(|(c, _)| *c == name).and_then(|(_, v)| v.as_ref()).map(|v| v.as_bytes().to_vec());
assert_eq!(column("country"), Some(b"us".to_vec()));
assert_eq!(column("status"), Some(b"201".to_vec()));
assert_eq!(column("path"), None);

let mut unknown = Array::default();
unknown.set("bogus".into(), Value::from("v"));
assert_eq!(Metric::extract_columns(&unknown, Kind::Event).unwrap_err().message(), "Unknown column 'bogus' for event");
```

## Ask for time buckets, breakdowns and peaks

Reads take queries. Besides filters, three shape the result: `group_by_interval` buckets rows by time (`1m` to `1M`), `group_by` breaks them down by a dimension, and `aggregate("max")` takes the highest value per bucket instead of the sum (events) or the latest sample (gauges).

```rust
use utopia_query::Method;
use utopia_usage::query;

let hourly = query::group_by_interval("time", "1h")?;
assert_eq!(hourly.method(), Method::GroupByTimeBucket);

let by_service = query::group_by("service");
assert_eq!(by_service.attribute(), "service");

let peak = query::aggregate("max")?;
assert_eq!(query::extract_aggregate(&[hourly, by_service, peak]), Some("max"));

assert_eq!(
    query::group_by_interval("time", "2h").unwrap_err().message(),
    "Invalid interval '2h'. Allowed: 1m, 5m, 15m, 30m, 1h, 1d, 1w, 1M"
);
```

## Read a metric row

A read returns `Metric`s: the row's attributes as the adapter returned them, with typed getters for the common ones. A value is an integer or a float (aggregates may be either); a getter for a missing attribute is empty.

```rust
use utopia_query::{Array, Value};
use utopia_usage::{Metric, Number};

let mut row = Array::default();
row.set("metric".into(), Value::from("bandwidth"));
row.set("value".into(), Value::Int(1024));
row.set("path".into(), Value::from("/v1/storage/files"));
row.set("tenant".into(), Value::Int(42));
let metric = Metric::new(row);

assert_eq!(metric.metric(), b"bandwidth");
assert_eq!(metric.value(None), Some(Number::Int(1024)));
assert_eq!(metric.text("path"), Some(&b"/v1/storage/files"[..]));
assert_eq!(metric.text("method"), None);
assert_eq!(metric.tenant().map(|t| t.as_bytes().to_vec()), Some(b"42".to_vec()));
assert_eq!(metric.kind(), b"event");
```

## Identify canonical samples

A `Sample` is one observation of a stream (a database member counting bandwidth, say), identified by its stream and sequence number. Its id stays the same when a producer retries it, and its payload hash changes when the retry carries a different value, so a reader can tell a duplicate from a conflict. Both hashes are length-prefixed SHA-256, reproducible in any language.

```rust
use php_std::datetime::{DateTime, TzInfo};
use utopia_usage::Sample;

let at = |s: &str| DateTime::new(s.as_bytes(), None, &TzInfo::utc());
let sample = |value| {
    Sample::new("production", "fra1", "101", "202", "mysql-0", "generation-1", 7, "bandwidth.inbound",
        at("2026-08-01T00:00:00Z")?, at("2026-08-01T00:01:00Z")?, value, 1)
        .map_err(|e| Box::new(e) as Box<dyn std::error::Error>)
};

let first = sample(42)?;
let retry = sample(43)?;
assert_eq!(first.id(), "a8e0eebf28f6eb0e2f632fd59b40734624d5c46a83edd2ba0530b0d83fbf3249");
assert_eq!(first.id(), retry.id());
assert_ne!(first.payload_hash(), retry.payload_hash());
assert_eq!(first.formatted_interval_start(), "2026-08-01 00:00:00.000");
```

## Connect to ClickHouse

The ClickHouse adapter talks to the server's HTTP interface. Its configuration names the host, credentials, database and a table prefix, whether tables are shared across tenants (a `tenant` column every read filters on), async inserts, and a retention in days for raw events and the daily rollup. A health check reports instead of failing, so it is safe to call when the server is down.

```rust
use std::sync::Arc;

use utopia_usage::adapter::clickhouse::{ClickHouse, Config};
use utopia_usage::Usage;

let mut config = Config::new("localhost");
config.port = 1;
config.namespace = "project".into();
config.shared_tables = true;
let clickhouse = Arc::new(ClickHouse::new(config)?);
assert_eq!(clickhouse.connection_stats().request_count, 0);

let usage = Usage::new(clickhouse.clone());
tokio::runtime::Builder::new_current_thread().enable_all().build()?.block_on(async {
    let health = usage.health_check().await;
    assert_eq!(health.get(&"healthy".into()), Some(&utopia_query::Value::Bool(false)));
});
assert_eq!(clickhouse.connection_stats().request_count, 1);

let invalid = ClickHouse::new(Config { retention: Some(0), ..Config::new("localhost") }).unwrap_err();
assert_eq!(invalid.message(), "Retention must be a positive number of days");
```

## Write, then read totals and series

`add_batch` appends rows (each entry names its tenant, so one batch may span several). Reads filter with queries: `find` returns rows or aggregates, `sum` and `total` add up events (a gauge's total is its latest value), `time_series` buckets them by hour or day with empty buckets filled. Event totals over closed days read the daily rollup; a window that ends today reads the rollup for closed days and the raw table for today, and one whose edges fall mid-day adds the edges from the raw table: the route log says which.

```rust
use std::sync::Arc;

use utopia_query::Query;
use utopia_usage::adapter::clickhouse::{ClickHouse, Config};
use utopia_usage::{Entry, Kind, Time, Usage, BATCH_SIZE};

tokio::runtime::Builder::new_current_thread().enable_all().build()?.block_on(async {
    let mut config = Config::new("clickhouse");
    config.password = "password".into();
    config.shared_tables = true;
    let clickhouse = Arc::new(ClickHouse::new(config)?);
    let usage = Usage::new(clickhouse.clone());
    usage.setup().await?;

    let rows = [
        Entry::new("42", "requests", 100).at(Time::Text("2025-03-02 10:00:00".into())),
        Entry::new("42", "requests", 150).at(Time::Text("2025-03-03 11:00:00".into())),
    ];
    usage.add_batch(&rows, Kind::Event, BATCH_SIZE).await?;

    let march = [Query::greater_than_equal("time", "2025-03-01 00:00:00"), Query::less_than("time", "2025-03-04 00:00:00")];
    let total = usage.total("42", "requests", &march, Some(Kind::Event)).await?;
    assert_eq!(total, 250);
    assert_eq!(clickhouse.route_log()[0].route, "daily");

    let series = usage.time_series("42", &["requests".into()], "1d", "2025-03-01", "2025-03-03", &[], true, None).await?;
    assert_eq!(series["requests"].data.len(), 3);
    Ok::<_, utopia_usage::Error>(())
})?;
```

## How it is built

`Usage` holds an `Arc<dyn Adapter>`; `Adapter` is a public trait, so a backend outside this crate (a test double, another store) plugs in the same way the two built-in ones do. The ClickHouse adapter builds its statements with `utopia-query`'s ClickHouse builder and schema (the same statements, byte for byte, as PHP's, so the server sees the same SQL) and sends them through `utopia-client`; the Database adapter drives `utopia-database`. Values the library reads and writes stay PHP values (`utopia_query::Value`), so tags, rows and totals keep PHP's types: an integer stays an integer, a float a float.

## License

BSD-3-Clause. See [LICENSE](LICENSE).
