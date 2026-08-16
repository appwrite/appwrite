# Agent instructions (Appwrite 3.x Rust)

Port Appwrite services to Rust under this workspace. Match PHP **product behavior** and **high-level architecture**. Do **not** replicate PHP runtime or language-specific APIs.

## Keep

- Module / Platform / Action layout (REST verbs only on HTTP)
- Utopia DI, hooks, events, response models, Document DB API
- Adapter selection via `_APP_DB_*` and shared cache keys with PHP where both run
- Traefik split routing (`/v1/users*` → Rust, everything else → PHP) until more services move

## Drop / avoid

- PHP-specific surfaces: PDO, `PDOStatement`, PHP DSNs as a public API, reflection patterns copied for their own sake
- Re-implementing C extensions or PHP stdlib when Rust crates already cover the need
- Catch-all “utils” crates that blur Utopia domain boundaries

## Database

Call engine adapters (`Postgres::connect`, `Mysql::connect_db`, `MariaDb::connect_db`, `Sqlite::open`, `Mongo::connect`). Prefer the high-level `Database` / `Document` / `Query` API from `appwrite-platform` (`dbForProject`, `dbForPlatform`).

[`SqlClient`](crates/utopia-database/src/sql_client.rs) is the Rust connection layer **behind** those adapters (using `postgres` / `mysql` / `rusqlite`). It is not a PDO port and should stay out of product code.

## Quality

From `3.x.x/`:

```bash
cargo fmt-check
cargo lint
cargo test -p appwrite-platform
cargo build -p appwrite-server --release
```

Users E2E via Traefik: compose up, point Scope at `http://appwrite.test/v1`, run `tests/e2e/Services/Users`.
