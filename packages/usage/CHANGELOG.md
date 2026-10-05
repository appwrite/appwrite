# Changelog

## Unreleased — query 0.6.x builder

### Added

- Added a separate immutable ClickHouse sample ledger for billable usage. A
  canonical identity covers environment, region, project/database internal
  IDs, member, generation, sequence and metric. Identical retries are
  deduplicated at read time; conflicting payloads, sequence gaps, bounded-read
  truncation and exact ingestion-ID watermark exclusions are explicit in
  `SampleResult`. Conflict representatives are selected as one physical tuple,
  never assembled from independent column aggregates.
- Added `Usage::addSamples()`, `Usage::getSampleWatermark()` and
  `Usage::findSamples()`. Existing events, gauges and daily rollups are
  unchanged and are not used as a canonical sample source.

### Breaking

- Bumped `utopia-php/query` from `0.1.*` to `0.6.*`.
  `Query::getMethod()` now returns the `Utopia\Query\Method` enum
  instead of a string, and the `Query::TYPE_*` /
  `UsageQuery::TYPE_GROUP_BY_INTERVAL` / `UsageQuery::TYPE_GROUP_BY`
  string constants are gone. Compare against `Method::GroupByTimeBucket`
  and `Method::GroupBy` instead; the `UsageQuery::groupByInterval()`
  and `UsageQuery::groupBy()` factories are unchanged (`groupBy()` also
  accepts an array of columns now, matching the base class).
- The ClickHouse adapter compiles its SQL through the utopia-php/query
  ClickHouse builder and schema layer (typed named bindings, schema-level
  CREATE TABLE / materialized view). Emitted DDL and query semantics are
  unchanged; projections, dim-column backfills and the daily
  materialized-view body stay raw SQL because the schema layer cannot
  express them yet.
- `contains()` / `containsAny()` / `notContains()` keep the
  utopia-php/database substring semantics, but now compile through the
  builder to `position(column, needle)` instead of `LIKE '%needle%'`.
  Needles are matched literally, so `%` and `_` no longer need
  escaping. Matching behaviour is unchanged.

## 0.11.0 — sdk dimensions

### Added

- Two event-only SDK dimensions in `Metric::EVENT_COLUMNS` and
  `Metric::getEventSchema()`: `sdk` (originating SDK name, e.g.
  `web`, `flutter`, `console`, `cli`) and `sdkVersion` (e.g.
  `14.0.0`). Both are optional strings. In ClickHouse both map to
  `LowCardinality(Nullable(String))` with `CODEC(ZSTD(3))`. Existing
  tables auto-materialize the columns on `setup()` via the
  `ADD COLUMN IF NOT EXISTS` path. Gauges are unchanged; these columns
  are not added to the primary key or indexes.

## 0.10.0 — premium geo dimensions

### Added

- Nine event-only premium geo dimensions in `Metric::EVENT_COLUMNS`
  and `Metric::getEventSchema()`: `city`, `continentCode`,
  `subdivisions`, `isp`, `autonomousSystemNumber`,
  `autonomousSystemOrganization`, `connectionType`,
  `connectionUsageType`, `connectionOrganization`. All are optional
  strings. In ClickHouse the lower-cardinality dims (`continentCode`,
  `subdivisions`, `connectionType`, `connectionUsageType`,
  `autonomousSystemNumber`) are `LowCardinality(Nullable(String))`;
  the high-cardinality dims (`city`, `isp`,
  `autonomousSystemOrganization`, `connectionOrganization`) are plain
  `Nullable(String)`. Existing tables auto-materialize the columns on
  `setup()` via the `ADD COLUMN IF NOT EXISTS` path. Gauges are
  unchanged; these columns are not added to the primary key or
  indexes.

## 0.9.0 — resourceType rename + queued time + ip dim + gauge fill

### Breaking

- Renamed the `resource` dimension to `resourceType` across the
  events, gauges, and events-daily tables. All Metric column
  constants, schema definitions, indexes, projections
  (`p_by_resource*` → `p_by_resourceType*`), materialized-view
  dimension list, and the `Metric::getResource()` getter (now
  `Metric::getResourceType()`) follow the new name. Callers must
  rename the `resource` tag key on writes and any queries that
  filter/group by the old name. A one-time ClickHouse column rename
  plus data backfill is required for existing deployments; that
  migration lives in the cloud repo, not in this library.

### Added

- `Accumulator::collect()` accepts an optional `\DateTime $time`
  argument. When provided, the row is written at that moment; when
  omitted the ClickHouse adapter still stamps `now()`. Events with
  the same (tenant, metric, tags) fold into the earliest supplied
  time; gauges follow last-write-wins on both value and time.
- `Usage::addBatch()` payloads may carry a `time` field per row
  (`DateTime|string`) — the ClickHouse adapter threads it through
  `formatDateTime()`.
- New event-only `ip` dimension in `Metric::EVENT_COLUMNS` and
  `Metric::getEventSchema()`. Stored as
  `LowCardinality(Nullable(String))` in ClickHouse, indexed with a
  `bloom_filter`, size 45 chars (IPv6 max). Gauges are unchanged.
  `Metric::getIp()` is a typed accessor. The base-table primary key
  is intentionally not extended with `ip`.
- Gauge time-series reads now carry the last observed value forward
  across empty buckets inside the window (LOCF) instead of collapsing
  to zero. Events keep zero-fill. Return shape is unchanged.

## 0.4.0 — Per-dimension ClickHouse projections and auto-routing

This release accelerates grouped reads against the events and gauges
tables by declaring ClickHouse `PROJECTION`s on the base tables. The
ClickHouse optimizer transparently routes any grouped query whose
GROUP BY shape matches a projection — no library-level routing
scaffolding required.

The projections live on the base tables themselves:

- `projects_usage_events.p_by_path`
- `projects_usage_events.p_by_country`
- `projects_usage_events.p_by_service`
- `projects_usage_gauges.p_by_service`
- `projects_usage_gauges.p_by_resource`
- `projects_usage_gauges.p_by_resourceId`
- `projects_usage_gauges.p_by_resource_resourceId`

`Usage::find()` issues a normal `GROUP BY` against the base table; the
optimizer picks the matching projection when one exists. The decision
is visible per query via `system.query_log.projections`.

### Upgrade note: projections are empty for pre-upgrade data

ClickHouse projections only capture rows inserted **after** the
projection is declared. Rows already in the events / gauges tables at
upgrade time are not materialized into the projection by
`ADD PROJECTION` alone. Queries that hit only pre-upgrade days will
return the same result either way (the optimizer falls back to the
base table when the projection can't satisfy the read), but to gain
the routing win on historical days you need to materialize each
projection per partition:

```sql
-- Materialize one partition at a time during off-peak hours.
ALTER TABLE <ns>_usage_events
  MATERIALIZE PROJECTION p_by_path
  IN PARTITION 'YYYYMM' SETTINGS mutations_sync = 2;
```

Repeat for `p_by_country`, `p_by_service` on events and
`p_by_service`, `p_by_resource`, `p_by_resourceId` on gauges. Throttle
by partition if the source table is large. Greenfield installs need
no action — projections capture all subsequent inserts.

### Other notable changes

- The pre-existing events daily MV (`projects_usage_events_daily`)
  and its routing in `Usage::sum()` / `Usage::getTotal()` are
  unchanged. The flat-sum path still chooses `daily` / `hybrid` /
  `raw` based on the query shape; the route log surfaces that choice.
- Gauges schema gains `service` and `resource` columns (idempotent
  `ALTER` applied in `setup()` for existing deployments).
- `ALTER TABLE ... MODIFY SETTING lightweight_mutation_projection_mode = 'rebuild'`
  is applied to the events and gauges tables so `DELETE` (the purge
  path) re-materializes affected projection parts atomically.
- The dual-read sampler now applies only to the events daily MV path
  (the one source that can drift from raw). Projection-routed reads
  are derived in the same write transaction as the parent insert and
  cannot diverge, so they're not sampled.
- The `daily` route on the events flat-sum path now requires both
  `time` bounds to fall on UTC midnight. A caller passing a mid-day
  bound (e.g. `time >= '2026-06-10 12:00:00'`) falls back to the raw
  scan; the daily MV stores rows at `toStartOfDay(time)` and a
  mid-day predicate would otherwise exclude the partial start day
  and over-include the partial end day.
- Narrow purges that target a column the events daily MV does not
  carry (e.g. `Query::equal('path', [...])`) AND carry no `time`
  bound are now treated as a no-op on the daily side. The raw
  events table still receives the narrow delete; the daily MV is
  left to overwrite stale rows on the next ingest cycle rather
  than issuing an unbounded `DELETE WHERE 1=1` that would wipe
  unrelated metrics. `value` is also removed from the daily-safe
  filter set: the daily `value` is a SUMmed aggregate, not a raw
  event value.
