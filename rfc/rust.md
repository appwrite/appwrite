# RFC: Gradual migration of the Appwrite API from PHP to Rust

Status: in progress — phase 1 (Users service) implemented.

## 1. Goals

1. **Cut infrastructure cost per request.** Less CPU time per request, lower baseline memory, lower memory per concurrent request, fewer allocations and fewer network round trips, so the same workload runs on fewer cores and less RAM.
2. **Keep the API contract.** The PHP E2E suite is the source of truth. A service counts as migrated only when that suite passes against Rust.
3. **Migrate gradually.** One service at a time, with PHP and Rust running side by side on the same PostgreSQL, Redis, queues and caches for as long as needed.
4. **Keep the architecture.** One monorepo. Modular Utopia libraries. CE stays extensible by Cloud without forks.

Non-goal: a line-by-line port. The Rust code keeps the boundaries and responsibilities of the PHP code, not its class hierarchy.

## 2. Repository layout

```
Cargo.toml                 workspace (resolver 3, edition 2024, MSRV 1.88)
Cargo.lock                 committed: the workspace ships a binary
Dockerfile.rust            image for the Rust API (distroless, non-root)
crates/
  utopia/<name>/           Rust Utopia libraries: generic, no Appwrite knowledge
  appwrite/core/           Appwrite domain core (src/Appwrite + app/ equivalents)
  appwrite/users/          the Users service module (Platform/Modules/Users)
  appwrite/server/         the `appwrite-rust` binary composing modules
tests/rust/                local integration harness (PostgreSQL + Redis, no PHP)
tests/benchmarks/users*    PHP vs Rust load, CPU and memory comparison
```

Rust crates live in `crates/`, not `packages/`. `packages/` is wired into Composer autoloading, `bin/monorepo`, the per-package CI matrix and the PHP image. A Rust crate there would break all four. `crates/` falls into the "server" branch of `.github/scripts/changes.sh`, so a Rust change also runs the PHP suites.

### Crate map

| Rust crate | PHP counterpart | Responsibility |
|---|---|---|
| `utopia-system` | `packages/system` | env access with `getEnv` semantics (unset, empty or `"0"` falls back to the default) |
| `utopia-validators` | `packages/validators` | parameter validators with byte-identical descriptions, and PHP value semantics (`is_array`, `is_numeric`, truthiness) |
| `utopia-http` | `packages/http` | hyper-based server, segment-trie router (static segments beat params), PHP-style params (`a[]=`), compression |
| `utopia-database` | `utopia-php/database` | same tables, columns, `_perms`, query semantics, validation messages and cache keys as PHP; PostgreSQL adapter |
| `utopia-cache` | `packages/cache` | Redis hash cache with the same generation and lease protocol and the same Lua purge scripts |
| `utopia-queue` | `packages/queue` | `LPUSH utopia-queue.queue.<name>` envelopes consumed by the PHP workers |
| `utopia-lock` | `packages/lock` | `SET NX EX` try-locks |
| `utopia-auth` | `packages/auth` | argon2, bcrypt, md5, sha, phpass, scrypt and scryptMod hashes; tokens; session store |
| `utopia-emails` | `packages/emails` | `FILTER_VALIDATE_EMAIL`, canonical forms, free/disposable/corporate classification |
| `utopia-locale` | `packages/locale` | translations lookup |
| `utopia-user-agent` | `packages/user-agent` | OS, client and device detection |
| `utopia-dsn` | `packages/dsn` | DSN parsing |
| `appwrite-core` | `src/Appwrite`, `app/` | config, errors catalogue, auth (keys, sessions, JWT, roles), crypto filter, documents, events, network (CORS, origin, IP), response models, request lifecycle |
| `appwrite-users` | `Platform/Modules/Users` | all 47 `/v1/users` routes |
| `appwrite-server` | `app/http.php` | process entry point, health probe |

Package boundaries are enforced by Cargo: Utopia crates never depend on `appwrite-*`, and service crates depend only on `appwrite-core` and Utopia crates. A Utopia crate is created only when a service needs it (Users needed the twelve above). Its internals are idiomatic Rust, not a mirror of the PHP class.

## 3. Running side by side

```
             ┌──────────── Traefik ────────────┐
 client ───► │ /v1/users, /v1/users/*  (p=1000) │──► appwrite-rust :8080
             │ everything else      (catch-all) │──► appwrite (PHP) :80
             └──────────────────────────────────┘
                     │                     │
                     └── PostgreSQL ◄──────┘  same tables
                     └── Redis      ◄──────┘  same cache hashes, queues, locks, pub/sub
                               PHP workers consume both runtimes' messages
```

- **Routing.** The Traefik router is restricted to the platform hosts (`_APP_DOMAIN`, `_APP_CONSOLE_DOMAIN`, `localhost`) and excludes `x-appwrite-hostname`. Custom domains for functions and sites are therefore never captured. The router has priority 1000. Migrating a service means adding its paths to this rule.
- **Data.** Rust reads and writes the PHP tables directly: `"appwrite"."<namespace>_<collection>"`, the `_id`/`_uid`/`_tenant`/`_permissions` columns and the `_perms` side tables. Namespaces come from `projects.database` exactly as `Appwrite\Database\Factory` builds them, in both dedicated and shared-tables mode. Encrypted attributes use the same AES-128-GCM envelope, and timestamps are stored as UTC `TIMESTAMP(3)`.
- **Schema lag.** A project's tables can trail the collection config until its migration runs (e.g. `authenticators.name` and `accessedAt` from passkeys on a console project created before them). Like `utopia-php/database`, Rust selects `*` and reads a missing column as `null` instead of failing the request. An explicit column list would turn every not-yet-migrated attribute into a 500.
- **Cache coherence.** PHP caches documents in Redis hashes and invalidates them by bumping a generation field. Rust keeps its own entries (e.g. projects) as Rust-specific fields **in the same hashes**:
  - a PHP purge deletes the whole hash, Rust fields included;
  - every Rust write runs the same `LUA_PURGE_FIELD`/`LUA_PURGE_BUMP` scripts on the PHP keys, pipelined in one round trip;
  - Rust applies the same relation purges PHP does (e.g. `users/<id>` after a session, target or authenticator change).
  
  Neither runtime ever reads the other's serialized values, so no igbinary decoding is needed.
- **Side effects.** Events (`users.*`), functions, webhooks, realtime, deletes, usage and onboarding are published in the formats the PHP workers already consume, including the database-listener `users.[userId].create` event. Audits are skipped on `self-hosted`, as in PHP.
- **Auth.** API keys (standard and ephemeral), session cookies and headers, fallback cookies, JWTs, console admin mode (team membership roles plus the project read check), impersonation and MFA factor checks all follow `app/controllers/shared/api.php`, in the same order. The order matters because it decides which error a client sees.

### Enabling it

```bash
COMPOSE_PROFILES=postgresql,rust docker compose up -d --wait   # dev: profile `rust` in docker-compose.override.yml
docker compose exec -e _APP_E2E_ENDPOINT=http://appwrite.test/v1 appwrite \
  vendor/bin/paratest --functional tests/e2e/Services/Users           # PHP E2E through Traefik → Rust
```

`docker-compose.yml` is the self-hosted installer template, so it is deliberately unchanged in phase 1. Shipping to self-hosted users comes after the CI E2E job has stayed green for a full release cycle.

## 4. Correctness

- The PHP Users suite (`tests/e2e/Services/Users`, 62 tests) runs against Rust in CI through Traefik. This is the `e2e_rust` job, for PostgreSQL in both dedicated and shared mode. `Scope::$endpoint` can now be overridden with `_APP_E2E_ENDPOINT`. Requests to `/account`, `/teams` and `/projects` in the same tests keep hitting PHP, which exercises cross-runtime consistency: for example, a password set by Rust followed by a login through PHP, or a session created by Rust used on `/account`.
- `tests/rust/run.sh` runs a port of the Users assertions against Rust alone. It uses real PostgreSQL and Redis, and no PHP is needed. Covered: creation including all 7 hash imports, validation messages, auth errors, queries, cursors, search, every attribute update, labels, prefs, password, sessions, tokens, JWT, targets, MFA, memberships, identities, passkeys, deletion, console admin mode, session/JWT/impersonation auth and CORS.
- PHP behaviours that look like bugs are **kept** for parity and documented in code. Examples: the empty-password update that answers and then keeps going; `sha512/224` returning 500; `total=false` counting because PHP casts `"false"` to `true`; target lookup falling back to the user document. They should be fixed in PHP and Rust together, in a later release.

### Known gaps (phase 1)

| Area | Status |
|---|---|
| Database adapters | PostgreSQL only. With MariaDB or MongoDB the Rust API refuses to start, and Traefik must not route to it. |
| `_APP_PWNED_PASSWORDS_DSN` `hibp://`/`appwrite://` | Answer 503 (`general_pwned_passwords_unavailable`) until an outbound HTTPS client is added. `none://` and `mock://` are supported. |
| Account and organization API keys | Treated like unknown keys (guest scopes). Project keys (standard and ephemeral) are fully supported. |
| Request filter for formats older than 1.5.0 (old query syntax) | Not implemented; the response filters are. |
| CORS from `rules` (custom domains) | Not consulted; platform hosts and project platforms are. |
| Abuse limits | Not implemented; the Users routes have no abuse labels. |
| Usage `country` tag | Empty. |

## 5. Performance design

What the Rust implementation does differently, and why it is cheaper per request:

1. **No per-request bootstrap.** One process per container, a multi-threaded tokio runtime and a fixed memory footprint. The Rust binary is about 10 MB, in a distroless image of about 30 MB.
2. **Fewer round trips.**
   - Writes are single statements. Insert plus `_perms` uses a data-modifying CTE; update and delete are single statements; cascading target, subscriber and topic deletion is one CTE.
   - PHP's per-query `SET statement_timeout`/`RESET` pair is replaced by a connection option.
   - Statements use `query_typed`: one round trip, no server-side prepare, no unbounded statement cache across thousands of project namespaces.
3. **Load only what an action needs.** PHP decodes every user with six sub-queries (sessions, tokens, challenges, authenticators, memberships, targets). Rust loads only the relations an action uses, and pipelines them on one connection in a single round trip.
4. **Sparse updates.** `UPDATE ... SET <changed columns>`, with `_updatedAt` bumped in SQL only when a value changed. There is no full-row rewrite and no re-encryption of the password on every user update.
5. **Project lookups.** One SQL statement with `json_agg` for keys, platforms and webhooks, cached in Redis next to PHP's entry. An in-process map keyed by the hash generation skips JSON decoding while nothing changed.
6. **Zero-copy responses.** Models serialise directly from the row structs into the response buffer, with PHP-compatible escaping. The same bytes are reused as event payloads (`RawValue`) instead of being encoded again for every queue.
7. **Off the request path.**
   - Events, usage, onboarding and `accessedAt` updates run in background tasks after the response, which is the same point at which PHP runs its shutdown hooks.
   - CPU-heavy hashing (Argon2) runs on the blocking pool and never stalls the event loop.
8. **Pools sized for density.** Connection pools are de-duplicated by server: `console` and `database_db_main` share connections on single-server installs. Redis uses a few multiplexed connections. mimalloc is the global allocator.

## 6. Benchmarks

`tests/benchmarks/users.js` (k6) drives a weighted, read-heavy Users workload: get, list, search, prefs, name, targets, create/delete, sessions and tokens. `tests/benchmarks/users-compare.sh` runs it against PHP (`:9501`) and Rust (`:9530`) on the same stack:

- **at fixed arrival rates** (default 50, 200 and 500 req/s), to compare **CPU time per request** and memory at equal load;
- **closed-loop**, to compare **maximum throughput** and tail latency.

CPU is read from cgroup counters, or integrated from `docker stats` where cgroups are not readable. It is reported separately for the API container and for PostgreSQL + Redis, so savings from fewer queries are visible too. `users-report.py` produces a Markdown table: req/s, p50, p95, p99, CPU ms/request, DB CPU ms/request, and baseline, average and peak memory.

```bash
bash tests/benchmarks/users-compare.sh             # default ladder
RATES="200 800" DURATION=120s HASHING=true bash tests/benchmarks/users-compare.sh
```

Run it on dedicated hardware before and after every performance change, and attach the report to the PR. The numbers that matter most are CPU ms/request at equal load and memory under the highest sustained rate.

Once the instance has an organization (self-hosted allows one), export `APPWRITE_ADMIN_EMAIL` for an owner of it. The PHP dev stack holds one PostgreSQL connection per Swoole worker (16 cores × `_APP_WORKER_PER_CORE=6`), which alone exhausts the default `max_connections=100` under load; raise it for benchmark runs.

### First results (development laptop)

Default ladder, 60 s per step, dedicated tables, 200 seeded users, no plaintext-password hashing. Apple Silicon, 16 cores, Docker Desktop. k6 ran on the same machine, and CPU was integrated from `docker stats` (no cgroup counters on macOS), so read the CPU columns as indicative. `max_connections` was raised to 500. 0 failures in every step.

| target | load | req/s | p50 ms | p95 ms | p99 ms | API CPU ms/req | DB+Redis CPU ms/req | mem baseline MiB | mem peak MiB |
|---|---|---:|---:|---:|---:|---:|---:|---:|---:|
| PHP | 50 req/s | 56 | 7.0 | 13.0 | 17.6 | 4.73 | 2.27 | 555 | 1005 |
| Rust | 50 req/s | 57 | 1.7 | 4.1 | 5.6 | 0.55 | 1.58 | 55 | 65 |
| PHP | 200 req/s | 216 | 5.8 | 11.1 | 16.1 | 3.93 | 1.76 | 1004 | 2013 |
| Rust | 200 req/s | 220 | 1.2 | 3.1 | 4.0 | 0.34 | 1.06 | 64 | 69 |
| PHP | 500 req/s | 533 | 5.9 | 11.7 | 15.6 | 4.00 | 1.70 | 2013 | 2322 |
| Rust | 500 req/s | 543 | 1.0 | 2.9 | 4.1 | 0.29 | 0.90 | 66 | 86 |
| PHP | 50 VUs | 382 | 89.4 | 343.5 | 619.0 | 21.44 | 7.77 | 2316 | 2328 |
| Rust | 50 VUs | 2799 | 13.5 | 42.7 | 68.3 | 0.65 | 2.40 | 84 | 130 |
| PHP | 200 VUs | 491 | 363.8 | 865.3 | 1155.7 | 17.27 | 5.02 | 2317 | 2356 |
| Rust | 200 VUs | 4316 | 36.0 | 110.5 | 159.4 | 0.47 | 2.10 | 128 | 173 |

At equal load Rust used about 9–14× less API CPU per request, 3–4× lower p99 and 15–30× less memory. Closed-loop it sustained about 7–9× PHP's throughput. PHP's resident memory grew across steps and was not released (555 → 2316 MiB baseline), so later PHP rows start from a warmer, larger heap. Repeat on dedicated Linux hardware with cgroup CPU accounting before quoting these numbers externally.

A wider ladder on the same machine (50–2000 req/s, 10–400 VUs) found where each runtime breaks. PHP held 500 req/s; at 1000 req/s and above it passed Swoole's `max_concurrency` of 1000 in-flight requests and shed load with 503s (about 35,000 failed requests in the 1000 req/s minute). Rust held 2000 req/s with no failures and p99 of 21 ms. Closed-loop, PHP peaked at about 1,050 req/s (100 VUs) and fell to about 460 req/s at 400 VUs, while Rust peaked at about 4,380 req/s and stayed above 3,500 req/s; at that point k6 and PostgreSQL on the same laptop were part of the limit.

## 7. Extensibility (Cloud)

Cloud depends on the CE crates and builds its own binary:

```rust
let platform = Platform::new(state)          // State::new(config, Arc::new(CloudHooks))
    .module(&appwrite_users::Users)
    .module(&cloud_billing::Billing);         // Cloud-only modules
```

- `platform::Module` is a service: a list of `Route`s with the same labels PHP uses (scopes, events, audits, SDK namespace).
- `platform::Hooks` is the extension surface. It covers plan flags (`$plan`), the `passwordValidator` hook and a per-request `init` hook. It has CE defaults, and every method is called at most a handful of times per request.
- New cross-cutting behaviour goes into `appwrite-core` behind a hook, never into a fork.

## 8. Migration plan

1. **Users** (this RFC): Rust behind the `rust` compose profile, and the `e2e_rust` CI job green in both modes.
2. Close the gaps in §4: the outbound HTTPS client for pwned passwords, account and organization keys, and the MariaDB adapter in `utopia-database`.
3. **Teams**, then **Account**. These reuse `appwrite-core` auth and documents; Account adds OAuth and mail queues.
4. **Databases (TablesDB)**. Generic, metadata-driven documents in `utopia-database`, plus relationships.
5. **Storage**, **Functions** control plane, **Messaging**, and so on.

For each service:
- implement the module;
- add only the Utopia crates it needs;
- route its paths to Rust in Traefik;
- make its PHP E2E suite pass through `_APP_E2E_ENDPOINT`;
- benchmark it.

Remove the PHP module only after the Rust route has served production traffic for a release. Remove PHP packages when no PHP code depends on them.

## 9. Working on the Rust code

```bash
cargo fmt --all && cargo clippy --workspace --all-targets -- -D warnings && cargo test --workspace
PG_BIN=... REDIS_SERVER=... BIN=target/release/appwrite-rust bash tests/rust/run.sh   # local integration
```

Conventions:
- Keep Utopia crates generic.
- Mirror PHP names and messages wherever they are part of the API contract.
- Prefer borrowing over cloning on hot paths.
- One SQL statement per write.
- Purge the PHP cache keys after every write.
