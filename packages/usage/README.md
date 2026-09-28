# Utopia Usage

![Total Downloads](https://img.shields.io/packagist/dt/utopia-php/usage.svg)
[![Discord](https://img.shields.io/discord/564160730845151244)](https://appwrite.io/discord)

Utopia framework usage library is a simple and lite library for managing application usage statistics. This library is aiming to be as simple and easy to learn and use. This library is maintained by the [Appwrite team](https://appwrite.io).

Although this library is part of the [Utopia Framework](https://github.com/utopia-php/framework) project it is dependency free and can be used as standalone with any other PHP project or framework.

## Features

- **Two Table Architecture**: Separate events and gauges tables optimized for their access patterns
- **Events Table**: Request-level metrics with dedicated columns for every dimension we filter on (path/method/status, service/resource ids, team ids, country/region, hostname, parsed UA fields)
- **Gauges Table**: Simple resource snapshots (storage size, user count, etc.)
- **Query-Time Aggregation**: No write-time period fan-out — aggregate by any interval at query time
- **Daily Materialized View**: Pre-aggregated daily SummingMergeTree for fast billing queries
- **Pluggable Adapters**: ClickHouse (production) and Database (development/testing)
- **In-Memory Buffering**: Collect metrics and flush in batch for high-throughput scenarios
- **Rich Queries**: Filter, sort, paginate using `Utopia\Query\Query` objects
- **Multi-Tenant**: Shared tables with tenant isolation via string tenant IDs
- **LowCardinality Columns**: Country uses `LowCardinality(String)` for efficient storage
- **Bloom Filter Indexes**: Fast filtering on all event columns

## Getting Started

Install using composer:
```bash
composer require utopia-php/usage
```

### Using ClickHouse Adapter

```php
<?php

use Utopia\Usage\Usage;
use Utopia\Usage\Adapter\ClickHouse;

// Configuration is fixed at construction. The adapter is fully stateless —
// the tenant is passed explicitly on every call (see below).
$adapter = new ClickHouse(
    host: 'clickhouse-server',
    username: 'default',
    password: '',
    port: 8123,
    secure: false,
    namespace: 'my_app',
    sharedTables: true,
);

$usage = new Usage($adapter);
$usage->setup(); // Creates events, gauges, and daily MV tables
```

### Using Database Adapter

```php
<?php

use Utopia\Usage\Usage;
use Utopia\Usage\Adapter\Database as DatabaseAdapter;

$adapter = new DatabaseAdapter($database); // Utopia\Database\Database instance
$usage = new Usage($adapter);
$usage->setup();
```

## Multi-tenancy

`Usage` is stateless: every query/mutation takes the tenant as its first
argument, and `addBatch` carries a `tenant` on each metric row (so one batch can
span tenants). This makes a single `Usage` instance safe to share across
tenants and coroutines.

```php
$usage->getTotal('project_123', 'bandwidth');           // read for one tenant
$usage->addBatch([
    ['tenant' => 'project_123', 'metric' => 'bandwidth', 'value' => 5000, 'tags' => []],
    ['tenant' => 'project_456', 'metric' => 'bandwidth', 'value' => 2000, 'tags' => []],
], Usage::TYPE_EVENT);
```

Callers that only ever touch one tenant can bind it once with the `Tenant`
decorator, which forwards to `Usage` with the tenant pre-filled (and stamps it
onto every `addBatch` row):

```php
use Utopia\Usage\Tenant;

$tenant = new Tenant($usage, 'project_123');
$tenant->getTotal('bandwidth');                          // no tenant argument
$tenant->addBatch([
    ['metric' => 'bandwidth', 'value' => 5000, 'tags' => []], // tenant stamped automatically
], Usage::TYPE_EVENT);
```

## Metric Types

### Events (Additive)

Events are request-level metrics like bandwidth, executions, API calls. They are summed when aggregated.

Event-specific columns (see `Metric::EVENT_COLUMNS`): `path`, `method`, `status`,
`service`, `resource`, `resourceId`, `resourceInternalId`, `teamId`,
`teamInternalId`, `country`, `region`, `hostname`, `osCode`, `osName`,
`osVersion`, `clientType`, `clientCode`, `clientName`, `clientVersion`,
`clientEngine`, `clientEngineVersion`, `deviceName`, `deviceBrand`,
`deviceModel`.

```php
use Utopia\Usage\Accumulator;

// Buffer metrics in memory and flush them in batch
$accumulator = new Accumulator($usage);

// Collect events — tenant first; values accumulate in-memory (summed per tenant+metric)
$accumulator->collect('project_123', 'bandwidth', 5000, Usage::TYPE_EVENT, [
    'path' => '/v1/storage/files',
    'method' => 'POST',
    'status' => '201',
    'service' => 'storage',
    'resourceType' => 'bucket',
    'resourceId' => 'abc123',
    'resourceInternalId' => '42',
    'teamId' => 'team_x',
    'teamInternalId' => '7',
    'country' => 'US',
    'region' => 'us-east',
    'hostname' => 'app.example.com',
    'osName' => 'iOS',
    'clientName' => 'Appwrite SDK',
    'deviceName' => 'smartphone',
]);
```

### Strict tag keys

All keys passed in the `tags` array must map to a known event or gauge
column (see `Metric::EVENT_COLUMNS` and `Metric::GAUGE_COLUMNS`). Unknown
keys throw at write time — there is no JSON catch-all. To add a new
dimension, widen the schema and bump the library version.

### Gauges (Point-in-Time)

Gauges are resource snapshots like storage size, user count, file count. Last-write-wins semantics.

Gauge-specific columns (see `Metric::GAUGE_COLUMNS`): `teamId`,
`teamInternalId`, `resourceId`, `resourceInternalId`.

```php
// Collect gauges — last value wins per tenant+metric in buffer
$accumulator->collect('project_123', 'users', 1500, Usage::TYPE_GAUGE);
$accumulator->collect('project_123', 'storage.size', 1048576, Usage::TYPE_GAUGE, [
    'teamId' => 'team_x',
    'teamInternalId' => '7',
    'resourceId' => 'abc123',
    'resourceInternalId' => '42',
]);
```

### Flushing

The accumulator exposes raw signals — `count()` (buffered entries) and
`elapsedSeconds()` (seconds since last flush) — and leaves the flush policy to
the caller.

```php
// Flush when the buffer grows large or enough time has passed
if ($accumulator->count() >= 5000 || $accumulator->elapsedSeconds() >= 10) {
    $accumulator->flush(); // Writes events to events table, gauges to gauges table
}
```

### Batch Writes

```php
// Write directly without buffering — each row carries its tenant
$usage->addBatch([
    ['tenant' => 'project_123', 'metric' => 'requests', 'value' => 100, 'tags' => ['path' => '/v1/users', 'method' => 'GET']],
    ['tenant' => 'project_123', 'metric' => 'bandwidth', 'value' => 50000, 'tags' => ['country' => 'DE', 'region' => 'fra']],
], Usage::TYPE_EVENT);

$usage->addBatch([
    ['tenant' => 'project_123', 'metric' => 'users', 'value' => 42, 'tags' => ['teamId' => 'team_x']],
], Usage::TYPE_GAUGE);
```

### Canonical Samples

Billable inputs that must survive request retries use the separate immutable
sample ledger. A sample's identity is derived from its environment, region,
project and database internal IDs, member, generation, sequence and metric.
Retrying the same identity and payload is safe. Reusing an identity with a
different interval, value or event version is returned as a conflict.
The event ID is SHA-256 over those identity fields in the documented order,
each encoded as its decimal byte length, `:`, then its UTF-8 value. The payload
hash uses the same encoding over event ID, UTC millisecond interval bounds,
value and event version.

```php
use Utopia\Usage\Sample;
use Utopia\Usage\SampleRange;

$sample = new Sample(
    environment: 'production',
    region: 'fra1',
    projectInternalId: '101',
    databaseInternalId: '202',
    member: 'mysql-0',
    generation: '01J...',
    sequence: 42,
    metric: 'bandwidth.inbound',
    intervalStart: new DateTimeImmutable('2026-08-01T00:42:00Z'),
    intervalEnd: new DateTimeImmutable('2026-08-01T00:43:00Z'),
    value: 4096,
    eventVersion: 1,
);

$range = new SampleRange(
    environment: 'production',
    region: 'fra1',
    projectInternalId: '101',
    databaseInternalId: '202',
    member: 'mysql-0',
    generation: '01J...',
    metric: 'bandwidth.inbound',
    firstSequence: 42,
    lastSequence: 42,
    intervalStart: new DateTimeImmutable('2026-08-01T00:42:00Z'),
    intervalEnd: new DateTimeImmutable('2026-08-01T00:43:00Z'),
);

$usage->addSamples([$sample]);
$watermark = $usage->getSampleWatermark($range, limit: 100);
$result = $usage->findSamples(
    $range,
    $watermark,
    limit: 100,
);

if (!$result->isComplete()) {
    // Refuse billing. Inspect conflicts, compact gap ranges and truncation.
}
```

Each supplied sample row receives an adapter-owned random ingestion ID before
its request is sent. `getSampleWatermark()` performs one bounded ClickHouse
snapshot read and captures each visible ingestion ID bound to its canonical ID
and payload hash for that stream and range. `findSamples()` admits only those
exact entry fingerprints, so later inserts or changed rows cannot cross the
boundary even when their server timestamps would be identical. A transport
retry of the same request retains its fingerprint and is counted once; a new
logical retry gets a new ingestion ID and is included only when visible to the
watermark query.

Both the watermark evidence and `findSamples()` result are explicitly bounded.
A result is complete only when neither bound is truncated and there are no
conflicts, sequence gaps or interval-boundary discontinuities. Conflicting
physical rows are never combined into a synthetic sample. The sample ledger
does not make HTTP delivery or a producer's local spool durable; callers must
retain a sample until the write is acknowledged and retry the identical
payload.

## Querying Metrics

### Find with Query Objects

```php
use Utopia\Query\Query;

// Find events filtered by metric and time range (tenant first)
$metrics = $usage->find('project_123', [
    Query::equal('metric', ['bandwidth']),
    Query::equal('country', ['US']),
    Query::greaterThanEqual('time', '2026-01-01'),
    Query::orderDesc('time'),
    Query::limit(100),
], Usage::TYPE_EVENT);

// Find gauges
$gauges = $usage->find('project_123', [
    Query::equal('metric', ['users', 'storage.size']),
], Usage::TYPE_GAUGE);

// Query both tables (type = null)
$all = $usage->find('project_123', [
    Query::equal('metric', ['bandwidth']),
]);
```

### Totals

```php
// Get total for a single metric (SUM for events, latest for gauges)
$total = $usage->getTotal('project_123', 'bandwidth', [
    Query::greaterThanEqual('time', '2026-03-01'),
], Usage::TYPE_EVENT);

// Batch totals — single query with GROUP BY
$totals = $usage->getTotalBatch(
    'project_123',
    ['bandwidth', 'executions', 'requests'],
    [Query::greaterThanEqual('time', '2026-03-01')],
    Usage::TYPE_EVENT
);
```

### Time Series

```php
// Get time series with query-time aggregation
// Events: SUM per bucket, Gauges: argMax per bucket
$series = $usage->getTimeSeries(
    tenant: 'project_123',
    metrics: ['bandwidth', 'requests'],
    interval: '1d',        // '1h' or '1d'
    startDate: '2026-03-01',
    endDate: '2026-04-01',
    zeroFill: true,        // Fill gaps with zeros
    type: Usage::TYPE_EVENT
);

// Returns: ['bandwidth' => ['total' => 5000000, 'data' => [['value' => 100, 'date' => '...'], ...]]]
```

### Billing Queries (Daily MV)

The daily materialized view pre-aggregates events by `metric + tenant + day` for fast billing:

```php
// Sum a single metric from the daily table
$total = $usage->sumDaily('project_123', [
    Query::equal('metric', ['bandwidth']),
    Query::between('time', '2026-03-01', '2026-04-01'),
]);

// Sum multiple metrics in one query
$totals = $usage->sumDailyBatch(
    'project_123',
    ['bandwidth', 'executions', 'storage.size'],
    [Query::between('time', '2026-03-01', '2026-04-01')]
);
// Returns: ['bandwidth' => 5000000, 'executions' => 12345, 'storage.size' => 0]

// Find daily aggregated rows
$rows = $usage->findDaily('project_123', [
    Query::equal('metric', ['bandwidth']),
    Query::orderDesc('time'),
    Query::limit(30),
]);
```

### Purge

```php
// Purge all event metrics older than 90 days
$usage->purge('project_123', [
    Query::lessThan('time', '2026-01-01'),
], Usage::TYPE_EVENT);

// Purge all gauge metrics
$usage->purge('project_123', [], Usage::TYPE_GAUGE);
```

## Architecture

### ClickHouse Tables

| Table | Engine | Purpose |
|-------|--------|---------|
| `{ns}_usage_events` | MergeTree | Raw request events with full metadata |
| `{ns}_usage_gauges` | MergeTree | Resource snapshot gauges |
| `{ns}_usage_events_daily` | SummingMergeTree | Pre-aggregated daily event totals |
| `{ns}_usage_events_daily_mv` | Materialized View | Auto-populates daily table on insert |
| `{ns}_usage_samples` | MergeTree | Immutable canonical samples with retry/conflict evidence |

### Events Table Schema

| Column | Type | Description |
|--------|------|-------------|
| id | String | UUID |
| metric | String | Metric name (e.g. bandwidth, requests) |
| value | Int64 | Metric value |
| time | DateTime64(3) | Event timestamp |
| path | Nullable(String) | API endpoint path |
| method | Nullable(String) | HTTP method |
| status | Nullable(String) | HTTP status code |
| service | LowCardinality(Nullable(String)) | API service (storage, databases, …) |
| resource | LowCardinality(Nullable(String)) | Resource type (bucket, file, …) |
| resourceId | Nullable(String) | External resource id |
| resourceInternalId | Nullable(String) | Internal resource sequence |
| teamId | Nullable(String) | External team id |
| teamInternalId | Nullable(String) | Internal team sequence |
| country | LowCardinality(Nullable(String)) | ISO country code (lowercased) |
| region | LowCardinality(Nullable(String)) | Region code (lowercased) |
| hostname | Nullable(String) | Caller origin host |
| osCode, osName | LowCardinality(Nullable(String)) | Parsed OS short code / name |
| osVersion | Nullable(String) | Parsed OS version |
| clientType, clientCode, clientName, clientEngine | LowCardinality(Nullable(String)) | Parsed client identity |
| clientVersion, clientEngineVersion | Nullable(String) | Parsed client versions |
| deviceName, deviceBrand | LowCardinality(Nullable(String)) | Parsed device identity |
| deviceModel | Nullable(String) | Parsed device model |
| tenant | Nullable(String) | Tenant ID (shared tables) |

### Gauges Table Schema

| Column | Type | Description |
|--------|------|-------------|
| id | String | UUID |
| metric | String | Metric name |
| value | Int64 | Current value |
| time | DateTime64(3) | Snapshot timestamp |
| teamId | Nullable(String) | External team id |
| teamInternalId | Nullable(String) | Internal team sequence |
| resourceId | Nullable(String) | External resource id |
| resourceInternalId | Nullable(String) | Internal resource sequence |
| tenant | Nullable(String) | Tenant ID (shared tables) |

### Daily Table Schema

| Column | Type | Description |
|--------|------|-------------|
| metric | String | Metric name |
| value | Int64 | Aggregated daily sum |
| time | DateTime64(3) | Day start timestamp |
| resource | LowCardinality(Nullable(String)) | Resource type |
| resourceId | Nullable(String) | External resource id |
| resourceInternalId | Nullable(String) | Internal resource sequence |
| teamId | Nullable(String) | External team id |
| teamInternalId | Nullable(String) | Internal team sequence |
| tenant | Nullable(String) | Tenant ID (shared tables) |

### Creating Custom Adapters

Extend `Utopia\Usage\Adapter` and implement:

- `getName()`, `setup()`, `healthCheck()`
- `addBatch(array $metrics, string $type, int $batchSize): bool` (each metric carries its own `tenant`)
- `find(string $tenant, array $queries, ?string $type): array`
- `count(string $tenant, array $queries, ?string $type, ?int $max): int`
- `sum(string $tenant, array $queries, string $attribute, string $type): int`
- `getTimeSeries(string $tenant, array $metrics, string $interval, string $startDate, string $endDate, array $queries, bool $zeroFill, ?string $type): array`
- `getTotal(string $tenant, string $metric, array $queries, ?string $type): int`
- `getTotalBatch(string $tenant, array $metrics, array $queries, ?string $type): array`
- `findDaily(string $tenant, array $queries): array`
- `sumDaily(string $tenant, array $queries, string $attribute): int`
- `sumDailyBatch(string $tenant, array $metrics, array $queries): array`
- `purge(string $tenant, array $queries, ?string $type): bool`

All configuration — namespace, database, shared-tables mode, async inserts,
query logging — is set once via the adapter constructor (see "Using ClickHouse
Adapter" above). Adapters hold no per-request state; the tenant is passed
explicitly on every call, so one instance is safe to share across tenants and
coroutines.

## System Requirements

Utopia Framework requires PHP 8.4 or later. We recommend using the latest PHP version whenever possible.

## Copyright and license

The MIT License (MIT) [http://www.opensource.org/licenses/mit-license.php](http://www.opensource.org/licenses/mit-license.php)
