# Appwrite 3.x (Rust)

Rust workspace for the Appwrite 3.x server rewrite. Utopia domain crates live under `crates/utopia-*`. Appwrite product crates live under `crates/appwrite-*`. The runnable HTTP binary is `apps/server`.

## Layout

| Path | Role |
|------|------|
| `crates/utopia-*` | Domain building blocks ported from Utopia PHP. No Appwrite product logic here. |
| `crates/appwrite-*` | Appwrite-specific layers (exceptions, hooks, locale, auth, events, response models, database helpers, platform/Users module). |
| `apps/server` | Binary crate (`appwrite-server`). Serves `/v1/users*` (+ health) for Traefik split routing. |
| `benchmarks/users/` | PHP vs Rust Users service benchmarks. |

Naming makes ownership obvious: `cargo test -p utopia-http` vs `cargo test -p appwrite-auth`.

### Appwrite crates

| Crate | Purpose |
|-------|---------|
| `appwrite-exception` | Error types + Error JSON envelope |
| `appwrite-hooks` | Named hook registry (`passwordValidator`, …) |
| `appwrite-locale` | `GeoRecord` helpers |
| `appwrite-auth` | API `Key` decode, password/phone validators, MFA constants |
| `appwrite-event` | Event / delete / audit message publishers |
| `appwrite-response` | `MODEL_*` + `dynamic()` serialization |
| `appwrite-database` | `CustomId`, encrypt filter, query helpers |
| `appwrite-platform` | Shared `api` hooks + Users HTTP module |

## Database adapters

`apps/server` follows PHP's `_APP_DB_ADAPTER` (see `app/init/registers.php`):

| `_APP_DB_ADAPTER` | Rust adapter | Default port |
|-------------------|--------------|--------------|
| `postgresql` / `postgres` | `utopia-database` Postgres | 5432 |
| `mysql` | MySQL | 3306 |
| `mariadb` | MariaDB | 3306 |
| `mongodb` / `mongo` | MongoDB | 27017 |
| `memory` / unset / connect failure | in-process Memory | — |

Shared env with PHP: `_APP_DB_HOST`, `_APP_DB_PORT`, `_APP_DB_USER`, `_APP_DB_PASS`, `_APP_DB_SCHEMA`, `_APP_OPENSSL_KEY_V1`.

## Architecture (keep / drop)

Follow Appwrite's **high-level** shape from PHP, not PHP's runtime:

**Keep (domain architecture):**

- Platform composition, Modules, HTTP Actions (`create` / `get` / `list` / `update` / `delete`)
- Utopia DI, hooks, events, response models
- Document / Query / `Database` adapter API (`dbForPlatform`, `dbForProject`)
- Shared env vars and Traefik split routing with PHP

**Drop (PHP-specific tooling):**

- PDO, `PDOStatement`, PHP DSNs, reflection-heavy patterns, and other PHP runtime APIs
- Replicating PHP extension surfaces when a Rust crate already does the job (`postgres`, `mysql`, `rusqlite`, Tokio, Hyper, …)

Prefer idiomatic Rust under the Utopia/Appwrite public APIs. Engine connections live behind `Postgres::connect` / `Mysql::connect_db` / `Sqlite::open` ([`SqlClient`](crates/utopia-database/src/sql_client.rs) is an internal adapter helper, not a PDO port).

## Build and test

From this directory (`3.x.x/`):

```bash
cargo build -p appwrite-server
cargo test -p appwrite-platform
cargo test -p appwrite-exception -p appwrite-auth -p appwrite-response

# Memory-mode local server (seeded project + key)
_APP_RUST_SEED=1 _APP_RUST_SEED_KEY=devkey APPWRITE_BIND=127.0.0.1:8080 cargo run -p appwrite-server

# Postgres mode (same env as PHP Appwrite)
_APP_DB_ADAPTER=postgresql _APP_DB_HOST=127.0.0.1 _APP_DB_PORT=5432 \
  _APP_DB_SCHEMA=appwrite _APP_DB_USER=... _APP_DB_PASS=... _APP_OPENSSL_KEY_V1=... \
  APPWRITE_BIND=127.0.0.1:8080 cargo run -p appwrite-server
```

Docker (from this directory):

```bash
docker build -t appwrite-rust:local -f apps/server/Dockerfile .
```

Compose (from Appwrite repo root): service `appwrite-rust` is labeled so Traefik routes `PathPrefix(/v1/users)` (priority 100) to Rust while PHP keeps the catch-all.

```bash
docker compose up -d --build appwrite-rust
docker compose exec appwrite test tests/e2e/Services/Users
```

## Notes

- Workspace metadata is inherited via `*.workspace = true`.
- Do not put Appwrite types inside `utopia-*`.
- Toolchain: `rust-toolchain.toml` (Rust 1.97.1).
