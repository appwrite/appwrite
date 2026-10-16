# Agent instructions (Appwrite 3.x Rust)

Port Appwrite services to Rust under this workspace. Match PHP **product behavior** and **high-level architecture**. Do **not** replicate PHP runtime or language-specific APIs.

## Keep

- Module / Platform / Action layout (REST verbs only on HTTP)
- Utopia DI, hooks, events, response models, Document DB API
- Adapter selection via `_APP_DB_*` and shared cache keys with PHP where both run
- Traefik split routing (`/v1/users*` → Rust, everything else → PHP) until more services move

## Module and HTTP file layout (parity with PHP)

Mirror `src/Appwrite/Platform/Modules/{Name}/` in `crates-appwrite/platform/src/modules/{name}/`:

| PHP | Rust |
|-----|------|
| `Module.php` | `mod.rs` (module registration) |
| `Base.php` | `base.rs` |
| `Services/Http.php` | `services/http.rs` |
| `Http/{Service}/Create.php` | `http/{service}/create.rs` |
| `Http/.../XList.php` | `http/.../xlist.rs` |
| Nested resources (`Targets/`, `MFA/RecoveryCodes/`, …) | Same nesting under `http/` |

Rules:

- **One PHP action class → one Rust action file.** Do not combine Create/Get/Update/Delete/XList (or sibling resources) into a single `crud.rs` / `properties.rs`.
- Action files are only `create.rs`, `get.rs`, `update.rs`, `delete.rs`, `xlist.rs`, each exporting a fn of the same name that returns `Action`.
- **Shared logic belongs on `base.rs` (PHP `Base`).** PHP uses `class Create extends Base` and `$this->createUser(...)`. Rust has no class inheritance; action files call `base::create_user` / `base::create_hashed_user_action` / etc. instead. Do not invent parallel `helpers.rs` / `shared.rs` modules beside actions for that role.
- Directory names are snake_case versions of the PHP path (`recovery_codes`, `jwts`, `md5`).

Reference layout: `crates-appwrite/platform/src/modules/users/http/users/` ↔ `Modules/Users/Http/Users/`.

## Drop / avoid

- PHP-specific surfaces: PDO, `PDOStatement`, PHP DSNs as a public API, reflection patterns copied for their own sake
- Re-implementing C extensions or PHP stdlib when Rust crates already cover the need
- Catch-all “utils” crates that blur Utopia domain boundaries
- Collapsing PHP's HTTP directory tree into a few mega-files for convenience

## Database

Call engine adapters (`Postgres::connect`, `Mysql::connect_db`, `MariaDb::connect_db`, `Sqlite::open`, `Mongo::connect`). Prefer the high-level `Database` / `Document` / `Query` API from `appwrite-platform` (`dbForProject`, `dbForPlatform`).

[`SqlClient`](crates-utopia/database/src/sql_client.rs) is the Rust connection layer **behind** those adapters (using `postgres` / `mysql` / `rusqlite`). It is not a PDO port and should stay out of product code.

### Connection pooling

`ProjectDatabase` (per-project `dbForProject`) and the platform DB (`dbForPlatform`) are backed by a real pool of connections for live adapters (Postgres/MySQL/Mongo), not a single shared mutex:

- `ProjectDatabase` wraps `Arc<utopia_pools::Pool<ProjectDb>>` for live adapters; `ProjectDb` implements `utopia_pools::Recover` (`reset`/`reconnect` both ping the underlying connection).
- `ProjectDatabase::lock()` checks a connection **out** of the pool and returns a `ProjectDbGuard` that derefs to `&mut ProjectDb` and is held across multi-op handlers; `Drop` reclaims it back to the pool. Handler call sites are unchanged (`db_handle.lock()`).
- Memory adapter keeps a single `Mutex<ProjectDb>` (in-process, nothing to pool) via `ProjectDatabase`'s `Memory` variant.
- Pool size mirrors PHP's `app/init/registers.php` intent (`_APP_CONNECTIONS_MAX` / `_APP_POOL_CLIENTS` / available parallelism, floored by `_APP_WORKER_MAX_COROUTINES`, clamped `[2, 32]`); logged at boot in `apps/server`.
- **Hash before checkout**: Argon2/password hashing in `base::create_user`, `create_hashed_user`, and the password update handler happens *before* the pool checkout, so CPU-bound hashing never holds a DB connection idle.
- MySQL and Postgres blocking calls in `sql_client.rs` run inside `tokio::task::block_in_place` so one slow query doesn't stall the async runtime; the pool (not `block_in_place` alone) is what enables concurrent DB access across requests.

## Quality

From `3.x.x/`:

```bash
cargo fmt-check
cargo lint
cargo test -p appwrite-platform
cargo build -p appwrite-server --release
```

Users E2E via Traefik: compose up, point Scope at `http://appwrite.test/v1`, run `tests/e2e/Services/Users`.
