# utopia-usage

Usage metrics for Utopia. Rust port of [utopia-php/usage](https://github.com/utopia-php/usage) (PHP SHA [`baeef33bbcb6`](https://github.com/utopia-php/usage/commit/baeef33bbcb6)).

Two metric types: **events** (SUM) and **gauges** (argMax / last write). Adapters: Database (`utopia-database`), SQL helpers, ClickHouse HTTP (`utopia-client`).

## Usage

```rust
use utopia_usage::{Accumulator, Memory, Usage, TYPE_EVENT};

let mut usage = Usage::new(Memory::new());
usage.setup().unwrap();
let mut acc = Accumulator::new(usage);
acc.collect("tenant", "requests", 1, TYPE_EVENT, Default::default(), None, false).unwrap();
acc.flush().unwrap();
```

## API

| Type | PHP | Notes |
|------|-----|--------|
| `Usage` | `Usage` | `TYPE_EVENT` / `TYPE_GAUGE`, `add_batch`, `find`, `count`, `sum`, `purge`, `get_time_series`, daily MV helpers |
| `Accumulator` | `Accumulator` | In-memory fold then flush (`collect` / `flush`) |
| `Tenant` | `Tenant` | Binds tenant onto every call |
| `Metric` | `Metric` | Schemas, indexes, `extract_columns`, `validate` |
| `UsageQuery` | `UsageQuery` | `groupByInterval`, `groupBy`, `aggregate` on top of `utopia-query` |
| `ClickHouse` | `Adapter\ClickHouse` | HTTP via `utopia-client`; live tests hit the compose ClickHouse container |
| `DatabaseAdapter` | `Adapter\Database` | `utopia_database::Database` |
| `SqlAdapter` | `Adapter\SQL` | Shared schema / identifier helpers |
| `Memory` | (Rust) | In-process adapter for tests without a database |

PHP Fetch is PHP-runtime-only; HTTP uses [`utopia-client`](../utopia-client). ClickHouse live tests always hit the compose container (`CLICKHOUSE_HOST`/`CLICKHOUSE_PORT`).

### Intentional deviations

- Snake_case method names.
- `Memory` is Rust-only for in-process tests without ClickHouse.
- `UsageQuery::group_by_interval` / `group_by` / `aggregate` are local (not in `utopia-query::Method`).

## Tests

```bash
cargo test -p utopia-usage
```

## Benchmarks

```bash
cargo bench -p utopia-usage --bench usage_run
```
