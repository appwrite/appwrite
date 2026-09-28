# `tests/Benchmark/` — ClickHouse perf harness

Synthetic-load benchmarks for `utopia-php/usage`. Not run by `composer test`;
invoke with `composer bench`.

## Running

```bash
docker compose up -d --wait
docker compose exec usage composer bench
```

Dataset size is controlled by `BENCH_ROWS` (default: 1,000,000). The seed SQL
in `fixtures/seed.sql` is parameterised, so the same harness covers 1M / 10M /
100M:

```bash
BENCH_ROWS=10000000 docker compose exec usage composer bench
BENCH_ROWS=100000000 docker compose exec usage composer bench
```

Each scenario runs 1 warmup pass + 5 measured iterations (override
`$iterations` per scenario if needed). For inserts, fewer iterations are used
to keep dataset bloat bounded.

## Output

Per-class JSON reports land in `tests/Benchmark/output/<class>.json`:

```json
{
  "scenarios": {
    "bench_events_sum_30d": {
      "iterations": 5,
      "rows_dataset": 1000000,
      "wall_p50_ms": 31.2,
      "wall_p95_ms": 38.7,
      "ch_p50_ms": 27.4,
      "ch_p95_ms": 33.1,
      "rows_read_p50": 1000000,
      "rows_read_p95": 1000000,
      "read_bytes_p50": 18504192,
      "read_bytes_p95": 18504192,
      "samples": [...]
    }
  }
}
```

ClickHouse-side stats (`rows_read`, `read_bytes`, `query_duration_ms`) come
from `system.query_log`, joined by the `query_id` the harness forwards to
each call via `ClickHouse::setNextQueryId()`.

## Scenarios

Events (`EventsBench`):

| Name | Shape | Expected budget @ 1M |
|---|---|---|
| `bench_events_sum_30d` | flat sum, no interval, no dims | p50 < 50ms |
| `bench_events_timeseries_30d_1h` | `interval=1h`, no dims | p50 < 200ms |
| `bench_events_count_max_5k` | capped count | p50 < 10ms |
| `bench_insert_10k` | 10k-row batch insert | p50 < 2000ms |
| `bench_events_topN_path_30d` | closed-day `groupBy('path')` MV | p50 < 100ms |
| `bench_events_topN_country_30d` | closed-day `groupBy('country')` MV | p50 < 100ms |
| `bench_events_topN_service_30d` | closed-day `groupBy('service')` MV | p50 < 100ms |
| `bench_events_topN_path_today_partial` | today-only path hybrid | p50 < 80ms |
| `bench_events_topN_path_30d_filtered_resource` | dim+non-MV filter → raw | p50 < 400ms |
| `bench_events_topN_path_country` | multi-dim not in any single MV → raw | p50 < 500ms |
| `bench_insert_with_mvs` | 10k-row insert with MV fan-out | ≤ 1.3x `bench_insert_10k` |
| `bench_mv_lag` | write-then-read same key | p50 < 200ms |
| `bench_mv_storage_per_busy_project` | system.parts footprint | p50 < 50ms |

Gauges (`GaugesBench`):

| Name | Shape | Expected budget @ 10k |
|---|---|---|
| `bench_gauges_latest_in_window` | gauge `getTotal` argMax | p50 < 50ms |
| `bench_gauges_topN_service_30d` | closed-day gauge by_service AMT | p50 < 100ms |
| `bench_gauges_topN_resourceType_30d` | closed-day gauge by_resourceType AMT | p50 < 100ms |
| `bench_gauges_topN_service_today_partial` | gauge by_service hybrid | p50 < 80ms |

Budgets are guidance, not gates.

## Notes

* Seeding uses `INSERT … SELECT FROM numbers(N)` to bypass the library's
  batch path. Wall-time scales linearly with row count; expect a 100M seed to
  take several minutes.
* The harness flushes `system.query_log` (`SYSTEM FLUSH LOGS`) before
  reading per-iteration stats. ClickHouse Cloud and some hardened
  deployments deny that statement; in that case `rows_read`/`read_bytes`
  come back zero, but PHP-side `wall_ms` is still accurate.
* Each scenario uses a unique `query_id` so `system.query_log` rows never
  collide. The `query_id` is generated per-iteration in `BenchmarkBase`.
