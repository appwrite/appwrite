# utopia-audit

Audit logs for Utopia. Rust port of [utopia-php/audit](https://github.com/utopia-php/monorepo/tree/main/packages/audit) (PHP SHA [`c3ae00025014`](https://github.com/utopia-php/monorepo/commit/c3ae00025014)).

Pluggable adapters store structured audit events (who / what / which resource / when). The default product surface matches PHP: Database (utopia-database), SQL schema helpers, and ClickHouse over HTTP.

## Install

```toml
utopia-audit = { path = "../utopia-audit" }
```

## Usage

```rust
use serde_json::{json, Map};
use utopia_audit::{Audit, Memory};

let mut audit = Audit::new(Memory::new());
audit.setup().unwrap();

let mut data = Map::new();
data.insert("key".into(), json!("value"));
let log = audit
    .log(Some("userId"), "update", "database/document/1", "ua", "127.0.0.1", data)
    .unwrap();
assert!(!log.get_id().is_empty());
```

## API Reference

### `Audit<A: Adapter>`

| Method | Description |
|--------|-------------|
| `new` | PHP `__construct(Adapter $adapter)` |
| `get_adapter` / `setup` / `ping` | Adapter access, schema setup, connectivity |
| `log` / `log_batch` | Create one or many events |
| `get_log_by_id` | Fetch a single log |
| `get_logs_by_user` / `count_logs_by_user` | Filter by `userId` |
| `get_logs_by_resource` / `count_logs_by_resource` | Filter by resource path |
| `get_logs_by_user_and_events` / `count_logs_by_user_and_events` | User + event list |
| `get_logs_by_resource_and_events` / `count_logs_by_resource_and_events` | Resource + event list |
| `find` / `count` | Custom [`Query`] filters |
| `cleanup` | Delete logs older than a timestamp |

### `Query`

Lenient factories matching PHP `Utopia\Audit\Query` (single scalar or array). `TYPE_*` constants match utopia-query / utopia-database (`equal`, `lessThan`, `cursorAfter`, …). `parse` / `parse_queries` / `to_string` / `to_array` match PHP.

### Adapters

| Type | PHP class | Notes |
|------|-----------|--------|
| `Memory` | (Rust test helper) | In-memory store so default tests run without MariaDB |
| `DatabaseAdapter` | `Adapter\Database` | Uses `utopia_database::Database` + Memory/SQL adapters |
| `ClickHouse` | `Adapter\ClickHouse` | HTTP via [`utopia-client`](../utopia-client). Live tests hit the compose ClickHouse container |
| `SqlAdapter` | `Adapter\SQL` | Shared schema, `parse_resource`, column helpers |

ClickHouse extras: namespace / tenant / shared tables / retention TTL, `actorId` translation, resource path parse, LowCardinality columns, query compiler (equal IN, contains LIKE, cursor vs `orderRandom`).

PHP `Utopia\Fetch` is PHP-runtime-only; HTTP uses [`utopia-client`](../utopia-client) with the same ClickHouse headers (`X-ClickHouse-User` / `Key` / `Database`).

### Intentional deviations

- `Memory` is Rust-only so CI passes without MariaDB. PHP Database tests use MariaDB; Rust Database tests use `utopia-database` Memory when available.
- ClickHouse HTTP uses [`utopia-client`](../utopia-client) instead of `utopia-php/fetch`.
- Snake_case method names.

## Tests

```bash
cargo test -p utopia-audit
```

Live ClickHouse always hits `docker-compose.test.yml` (`CLICKHOUSE_HOST`/`CLICKHOUSE_PORT`, defaults `127.0.0.1:8124`).

## Benchmarks

```bash
cargo bench -p utopia-audit
```
