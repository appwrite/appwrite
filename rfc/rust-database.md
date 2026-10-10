# Rust conversion of utopia-php/database and utopia-php/query: final plan

The plan applies `crates/CONVERSION.md`, the standard and the document to refer to, and the tester in `tests/compat/README.md`. The reference conversion is `crates/utopia/lock`, with `tests/compat/lock` and `crates/tools/compat/src/libs/lock.rs`. Section 10 lists the amendments those documents need.

## 0. Verified ground truth (read before anything else)

| # | Fact | Evidence | Consequence |
|---|---|---|---|
| 1 | utopia-php/database 7.4.1 does not use utopia-php/query. | Its `composer.json` requires validators, console, cache ^4\|^5, pools 2.\*, mongo 1.\*. Grepping its src for `Utopia\Query` finds nothing. | Two independent crates with no dependency edge. CONVERSION.md §1 is wrong and S0 fixes it. |
| 2 | utopia-php/query's consumers are elsewhere. | packages/usage, packages/audit, src/Appwrite/Usage/{Connection,Concurrency,Policy}.php, src/Appwrite/Execution/Store.php, Modules/Usage/Http/*. | `utopia-query` depends on php-std only. |
| 3 | PHP's SQL adapters see strings and `Database::casting` decides types. | `SQL::getPDOAttributes` and `app/init/registers.php` set `ATTR_EMULATE_PREPARES=true` and `ATTR_STRINGIFY_FETCHES=true`. | Rust must return exactly the values casting() would produce from PDO's strings. |
| 4 | Postgres does shorten table names. | `Postgres::getSQLTable` calls `getShortKey` (Postgres.php:2857). | The legacy `table()` is correct. The fidelity proposal's "divergence 1" is false. |
| 5 | Unique-violation mapping differs in the legacy crate. | `Postgres::processException` returns `Unique('Unique index violation')`, except when the violated columns are `[_uid]` or `[_tenant,_uid]` (`Duplicate('Document already exists')`). It also maps 42P07, 42701, 22001, 22003, 2201F, 22008, 42P01 and 42703 (all gated on errorInfo[1]===7). The legacy crate maps only three codes, with different messages. | A real divergence. D10 fixes it, and it needs consumer updates in I1. |
| 6 | SQL text is observable. | `Adapter::before`/`trigger`. `setMetadata` installs `before(EVENT_ALL,'metadata')`, which prefixes `/* k: v */\n`. Appwrite calls `setMetadata` on every request (`app/init/resources/request.php:583`, `app/controllers/shared/api.php:387`). MariaDB and MySQL `setTimeout` install a `'timeout'` transform. CollectionTests appends ` AND 1=0` to getDocument SQL through `before()` and asserts the comment. | Triggered SQL text is a contract. "Appwrite registers no transforms" is false. |
| 7 | Postgres pays two extra round trips per statement. | `Postgres::execute` sends `SET [LOCAL] statement_timeout` and `RESET` around every statement. | Unobservable traffic, so it may be dropped. |
| 8 | Document cache fields depend on PHP source locations. | `getCacheKeys` field = `md5(resolveRelationships ':' json(filterSignatures) ':' json(sortedSelects))`. Signatures are closure `file:line` strings. Default filters are closures in the vendored Database.php; Appwrite's are in `app/init/database/filters.php`. | The cache field depends on PHP source locations. |
| 9 | The cache codec is igbinary and the value is the decoded document. | Appwrite registers the Igbinary cache codec. The cached value is `getArrayCopy()` of the decoded document, so it includes subQuery relations and decrypted values. | Interop requires igbinary in Rust. |
| 10 | The legacy crate writes Redis state PHP never writes. | It writes the Rust-only field `rust:project:v1` (`core/src/database/project.rs:320`). rfc §3 chose Rust-private fields. | Conflicts with CONVERSION §3's side-effect rule for a full conversion. |
| 11 | Adapter calls can be traced through the Pool. | `Adapter\Pool::delegate()` is public and routes 125 methods. Appwrite runs `Database\Adapter\Pool` in production (`app/http.php:329`). `Pool::before` delegates to one pooled adapter only. | A RecordingPool can produce adapter traces. The `before` behaviour is a quirk to record. |
| 12 | Transaction semantics. | `withTransaction`: 2 retries, `usleep(50ms*(n+1))`, and no retry on Duplicate, Restricted, Authorization, Relationship, Conflict, Limit or Timeout. Nesting uses `SAVEPOINT transactionN`. `startTransaction` sends ROLLBACK before BEGIN. | Callbacks are re-run, so in Rust they are `FnMut`. |
| 13 | groupByType quirks. | `$offset = $values[0] ?? $limit`. An order with an empty attribute pushes an order type but no attribute. | Kept and pinned. |
| 14 | Runner constraints. | It runs PHP and Rust at the same time in namespaces `cx{run}{n}p` and `cx{run}{n}r`. Case loading is flat (`load_dir`). The only snapshot kinds are redis and files. | Database cases need sequential runs in the same namespace, because md5 short keys depend on the namespace. The runner also needs recursion, sidecars and new snapshot kinds. |
| 15 | Legacy hot path. | `row::index` does a linear name search for every column of every row. `load_user` pipelines 5 `query_typed` statements. Three handlers match `NotFound(m) if m.starts_with("cursor:")`. Eight appwrite files contain hand-written SQL. | The baseline to keep or beat. |
| 16 | php-std values. | php-std carries values as `serde_json::Value`, which has no int keys and no stdClass distinction. json, serialize, pcre and datetime are 4-line stubs. | The value model and these engine functions are prerequisites. |
| 17 | Sizes. | Database: src 49.8k lines; Database.php 11.2k lines with 130 public methods; Mirror.php 53 public methods; 84 test files, 67k lines; 16 e2e profiles. Query: 297 src files, 25.6k lines; 101 test classes, 75k lines. packages/cache: 5.2k lines. packages/pools: 1k lines. utopia-php/mongo: 3k lines, a Swoole wire client. | Scale of the work. |
| 18 | Toolchain. | Edition 2024, rust-version 1.88. RPITIT and async closures are available. The future of an `AsyncFn*` cannot be given a nameable Send bound. | Closure APIs take an owned handle and a plain `FnMut(..) -> Fut + Send`. |

## 1. Decisions

| Area | Decision | From |
|---|---|---|
| Crate graph | `utopia-query` and `utopia-database` have no edge between them. Database requires `utopia-cache` (full conversion), `utopia-pools` (new, full) and `utopia-mongo` (new, full, `mongodb` feature). utopia-php/console is not converted: `Console::warning` becomes `tracing::warn!` (logging only), noted in the database spec. | all; pools from fidelity and performance |
| Value model | One PHP value model in php-std, `php_std::Value<X: Extension>` (Null, Bool, Int, Float, Str, Array, Object, Ext). `Str` is a byte string with three representations: inline up to 23 bytes, `Bytes`, and static. `Array` is packed while it is a list and an ordered hash otherwise, with `Int|Str` keys and PHP numeric-key normalisation. json, igbinary, juggling and comparisons are implemented once and fuzzed against the engine. | performance (+ fidelity's codecs) |
| Cache interop | Shared entries. igbinary goes in php-std, utopia-cache gets a byte-identical Envelope and leases, and Rust uses the same hash fields through a `SignatureTable` (filter name → PHP signature string). The table is dumped from the PHP image at build time, and an interop case fails when it is stale. The whole arrangement is recorded as a quirk, to be replaced in both runtimes by explicit signatures. `rust:project:v1` is removed and rfc §3 is updated. | fidelity |
| SQL text | Every statement PHP passes through `trigger()` is generated byte-identical in the faithful path and verified by statement traces. Fused statements are allowed only when no transform other than the built-in `metadata` and `timeout` ones is registered for the events involved. Rust applies those built-ins to fused statements itself. Traffic that is never triggered (BEGIN, ROLLBACK, SET/RESET timeout) is not a contract. | parallelism + fidelity's nuance |
| Binding and typing | See below. | new synthesis |
| Adapter trait | It mirrors Adapter.php method for method, so traces replay 1:1. It uses RPITIT `-> impl Future<Output = Result<T>> + Send`. Per-handle state lives in a `Scope` passed by reference, so one `Arc` adapter (driver plus pool) serves every request. Dispatch is `AnyAdapter` (an enum of built-ins with static dispatch) plus `Custom(Arc<dyn DynAdapter>)` for Cloud. | all three |
| SQL sharing | `Sql<D: Dialect>` is SQL.php. Every hook a PHP subclass overrides is a `Dialect` method whose default is SQL.php's body. `MySql` and `Sqlite` wrap the `MariaDb` dialect and forward what their PHP class does not override. An override-parity check compares PHP reflection with each dialect's `OVERRIDES` const. | fidelity |
| Errors | One enum, one variant per PHP class, plus `Driver` (PDOException, Mongo and Redis exceptions that escape unmapped) and `Engine` (TypeError, ValueError, JsonException). It provides `php_class()`, `is_a()` (instanceof: Unique is_a Duplicate) and `code()` (Exception.php's string→int normalisation). | fidelity |
| Handle | `Database<A>` holds `Arc<Shared<A>>`, a `Scope`, flags, filters and listeners. Setters take `&mut self`. PHP's callback-scoped helpers become by-value views (`db.skip_relationships()`) so nothing mutates shared state. Three public operations run caller code: `with_transaction` takes `F: FnMut(Database<A>) -> Fut + Send` (an owned handle, `FnMut` because PHP retries the callback), `with_cache` takes `FnOnce(Database<A>) -> Fut` (called at most once, on a miss), and `foreach` takes `FnMut(Document) -> Fut`, called once per document. Listeners and filters are registered objects (`Listener`, `Filter`), not closures passed per call. | performance and parallelism; FnMut is new |
| Authorization | One `Arc<Authorization>` per request, shared by dbForProject and dbForPlatform as in PHP. `skip()` is a task-local overlay keyed by the instance, so concurrent branches cannot leak a disabled status. | fidelity |
| Filters | Sync `encode(v, &mut Document, ctx)`, because PHP filters mutate the document (Appwrite's `enum` does). `decode` returns `FilterResult::Ready(..)` or `Pending(BoxFuture)`, so sync filters never box. Filters also have `signature()` and an optional `prefetch()`, which lets subQuery* children travel in the parent's flight. | fidelity + performance |
| Schema metadata | `_metadata` documents are decoded once into `Arc<Collection>`, cached in process and keyed by the cache generation. The generation is re-checked in the same Redis pipeline as the document lookup. No compile-time fixed schemas: they would be a second source of truth and lag the tables. | fidelity + parallelism; performance's fixed schemas rejected |
| Hot path | The legacy crate moves verbatim to `utopia_database::legacy` on day 0. A typed tier (`Record`/`RowReader`, `#[derive(Record)]`, `ColumnPlan`) runs on the same planner, writer and cache protocol as the faithful path. A write-strategy planner chooses Single or Transactional. A benchmark gate decides when each endpoint moves. | parallelism, performance, fidelity |
| Compat | 16 profiles, one per PHP e2e class. Database cases run sequentially in the same namespace. Expectations live in per-profile sidecars. Cases are generated from reflection recorders of PHPUnit runs. RecordingPool adapter traces back a Rust replay adapter. Each case also runs with a capturing transform (statement traces) and a second time under the auto strategy. Masks are ordinal. Differential find fuzzing. Interop in both directions. The PHP glue is a reflective dispatcher. | all three; reflective glue is new |

Binding and typing, by driver:
- **PostgreSQL.** Extended protocol with the unnamed statement (`query_typed`: one round trip, no statement cache). PARAM_STR values are sent in text format with an unspecified type (OID 0), so the server infers the type from context exactly as it does for PDO's emulated quoted literals. PARAM_INT and PARAM_BOOL are typed. Rows are decoded from binary into the values `casting()` produces from PDO's text: bigint stays a string unless it fits a PHP int; float4 is printed shortest and then read as f64; datetimes are formatted as PG text. A spike in S0 proves this on a type matrix before D9 starts.
- **MariaDB and MySQL.** Text protocol (COM_QUERY) with literals rendered by `php_std::pdo::quote_mysql`, fuzzed against `PDO::quote`. Results come back as text, exactly what PHP sees.
- **SQLite.** pdo_sqlite never emulates, so native prepared statements are used and results are stringified like `sqlite3_column_text`.

## 2. Crates, features, dependency graph

```
php-std ◄─ utopia-validators ◄─ utopia-cache ◄─ utopia-database ◄─ appwrite-core ◄─ appwrite-users / appwrite-server
php-std ◄─ utopia-query                (later: Rust ports of usage, audit, executions)
utopia-pools ◄─ utopia-database[pool]  utopia-mongo ◄─ utopia-database[mongodb]
utopia-database-derive (proc-macro, optional re-export behind feature "derive")
crates/tools/compat ◄─ everything (--all-features)
```

`utopia-database` features:
- `default = ["postgres", "memory"]`
- `postgres`: tokio-postgres and deadpool-postgres
- `mariadb`: mysql_async
- `mysql = ["mariadb"]`
- `sqlite`: rusqlite with bundled SQLite, the only C dependency, justified in its PR. It runs as a per-connection blocking actor.
- `mongodb`: utopia-mongo
- `redis`, `pool`, `mirror`, `derive`

The Appwrite server enables postgres, mariadb, mongodb, pool and derive.

`utopia-query` features: `mysql` (MySQL and MariaDB), `postgresql`, `sqlite`, `clickhouse`, `mongodb`, `ast`. All are default; the crate is pure code, so features only trim binaries.

The module trees are in the skeleton. The PHP file is the map: one Rust file per PHP class or area, and the crate docs carry the PHP→Rust table (§4 naming rule).

## 3. Core types (signatures in the skeleton)

**Values.**
- `utopia_database::Value = php_std::Value<Ext>`, where `Ext` is `Document(Box<Document>)` or `Operator(Box<Operator>)`. That reproduces `instanceof Document` for relationship values, for constructor auto-wrapping (arrays with `$id` or `$collection`) and for `getArrayCopy` unwrapping.
- `Name = Str`. Keys from a cached `Collection` are `Str::Shared` clones, so decoding a row allocates no key strings.
- `Tenant` is `Int(i64)` or `Str(Str)`, matching PHP's `int|string`. It renders into cache keys with PHP string conversion.

**Document.**
- A PHP array plus an optional `DocumentClass`, for setDocumentType (Appwrite's `User`).
- Methods match Document.php 1:1 in Rust names.
- Insertion-order semantics follow PHP exactly: `remove` then `set` appends. Adapters reproduce PHP's operation order, for example unsetting `_uid` and then setting `$id`.
- `DocumentType` gives typed access by moving values, never cloning.

**Query and Operator.**
- `Query { method: Method, attribute: Name, values: SmallVec<[QueryValue; 2]>, attribute_type, on_array }`, with `QueryValue` = `Value | Query`. `Method` is the closed `TYPE_*` set.
- parse, parseQuery, parseQueries, toArray, toString, fingerprint, shape and every static constructor are byte-identical, error messages included.
- `group_by_type` borrows into a `Grouped<'q>` and keeps both quirks.
- `utopia-query` has its own Query type, because the two PHP classes differ.

**Errors.** As in the decisions table. Constant messages are `Cow::Borrowed`, so they do not allocate. Order errors carry `attribute`.

**Authorization.** Roles are an `IndexSet<Str>` (PHP insertion order matters for messages) behind a `parking_lot::RwLock`, plus status and default status. `is_valid(&Input) -> Result<(), String>` returns a byte-identical description. Per-action SQL literals for the permission predicate are cached per role generation, not rebuilt per query.

**Schema views.** `Collection`, `Attribute`, `Index`, `AttributeType` (VAR_*), `IndexType`, `RelationType`, `RelationSide` and `OnDelete` are parsed once from the `_metadata` document. `Collection::document()` returns the raw document unchanged, because `getCollection` returns it as is.

## 4. Adapter design

**Trait.** The trait is Adapter.php's whole public surface:
- Every `getSupportFor*` folds into `capabilities(&Scope) -> Capabilities`, a Copy struct with one bool per getter, const for static adapters with a Scope overlay for setSupportForAttributes. `limits()` covers getLimitFor*, getMax*, getMin*, getCountOfDefault* and getDocumentSizeLimit.
- Also: keywords, internal index keys, hostname, filter, tenant_query, casting_before and casting_after, set_utc_datetime, decode_point, decode_linestring, decode_polygon, count_of_attributes, count_of_indexes, attribute_width.
- Async methods take `Cx { scope: &Scope, tx: Option<&Self::Tx> }` and cover lifecycle, databases, collections, attributes, relationships, indexes, documents, find, count and sum.
- Strategy hooks have default bodies returning `Ok(None)` ("not supported, use the faithful sequence"): `fused_create`, `fused_update`, `fused_delete`, `get_document_prefetch`.
- `RecordSource` is a second trait with generic `get_records` and `find_records` for the typed tier. AnyAdapter implements it by dispatch; the Custom and Mongo arms return Unsupported, and the caller then falls back to Document plus `Record::from_document`.

**Transactions.**
- `begin(scope, parent)` checks out and pins a connection (`Self::Tx`), issuing BEGIN or SAVEPOINT transactionN. commit and rollback mirror PHP.
- `Database::with_transaction` implements Adapter::withTransaction's retry policy exactly, using `tokio::time::sleep`.
- The pinned `Tx` lives in the derived handle, so nested operations reuse it. This is Pool's `pinnedAdapter`.

**Timeouts.** Postgres sends `SET statement_timeout` only when the connection's current value differs, pipelined with the statement. Inside a transaction it uses `SET LOCAL`. The session state is identical and no round trip is added. MariaDB and MySQL apply their `timeout` transform exactly as PHP does.

**Transforms.** `Scope.transforms` holds `EVENT_ALL` first and then per-event transforms, in insertion order, as `Arc<dyn Transform>` with `fn apply(&self, sql: String) -> String`. `SqlWriter` emits PHP's text with PHP's named placeholders (`:_uid`, `:key_0`). `exec.rs` applies the transforms, then compiles placeholders to `$n` or `?` in one O(len) pass. With no transforms, the writer emits driver placeholders directly.

**Pool.**
- `adapter::pool::Pool<A>` over `utopia_pools::Pool<A::Conn>` reproduces PoolTest and PoolTimeoutTest: delegation, per-checkout timeouts, pinning in transactions, and the `before` quirk.
- SQL adapters also own a native pool (deadpool, a mysql_async Pool, a SQLite actor set). Pools are deduplicated by host, as today.

**Non-SQL adapters.**
- Mongo implements the trait over `utopia-mongo`, with PHP's conversion rules (Int32, Int64, Double, UTCDateTime, ObjectId).
- Memory and Redis share `adapter/eval`, a single implementation of PHP comparison, LIKE, regex and fulltext emulation and ordering, so the two cannot drift. Redis keeps PHP's pipelining, journal and rollback order.

## 5. Database API

- Every public method of Database.php (130) is an inherent method with the snake_case name and the same arguments.
- Long signatures take argument structs with `Default`: `AttributeSpec`, `UpdateAttribute`, `IndexSpec`, `CreateRelationship`, `UpdateRelationship`, `CreateCollection`.
- Inputs are borrowed (`&str`, `&[Query]`); returned documents are owned.
- `iterate` returns `impl Stream<Item = Result<Document>>`.
- Bulk methods take `on_next: impl FnMut(&Document[, &Document])` and `on_error: impl FnMut(Error) -> Result<()>`.

**Registries.**
- `Registry::global()` holds `Database::$filters` and `filtersVersion`, copy-on-write through arc-swap, with defaults registered on first touch and an explicit add_filter winning.
- Instance filters shadow statics, as in PHP.
- `Structure::addFormat` becomes `Registry::add_format`.
- Listeners are sync (`fn handle(&self, Event, &Payload)`) and silencing follows PHP. `Payload::Lazy` builds a Document only when a listener exists.
- `before(event, name, Option<Arc<dyn Transform>>)`.

**Cache.**
- getCacheBaseKeys, getCacheKeys, getQueryCacheKey and getQueryCacheField are byte-identical.
- Payloads follow `saveWithLease(documentKey, getArrayCopy(), hashKey, generation)` through utopia-cache's Igbinary codec and Envelope.
- Also ported: the `$empty` negative marker, the collection index HSET, purgeCachedDocument[Internal] (with withDocumentTenant), purgeCachedCollection, purgeCachedQueries and withCache with lease.
- Redis operations that PHP issues back to back are pipelined into one flight: load + generation + metadata generation, then lease save + index.

**Relationships.**
- PHP's machinery is ported 1:1: BFS batch population, RELATION_MAX_DEPTH, skip keys, nested selects, write/fetch/delete stacks, junction collections, cascade/restrict/setNull and relationship-query conversion.
- The PHP mutable stacks become an explicit per-call `RelationCtx`. Recursion uses boxed futures.
- Reads of independent relation attributes at the same level are pipelined on one connection.

**Tenancy.** sharedTables, tenantPerDocument and globalCollections are ported, including a null tenant segment for global `_metadata` keys and the exact "Missing tenant…" guards.

**Mirror.** `Mirror` wraps two Database handles with PHP's override list of 53 methods, SOURCE_ONLY_COLLECTIONS, write filters (`mirroring::Filter` trait) and onError.

## 6. Hot path (Users), without regression

1. **Day 0 (S0).** `git mv` the current crate to `src/legacy/` and rewrite imports mechanically. Behaviour does not change. Record baselines: users-compare.sh (CPU ms/req, p99, peak memory at 50, 200 and 500 req/s, plus closed-loop) and criterion benches for load_user, the users list and insert/update.
2. **The faithful path is fast by construction:**
   - static dispatch, and capability branches that fold away;
   - generation-validated L1 schema cache;
   - interned keys and `ColumnPlan` decoding, O(cols) with no key allocations;
   - `SqlWriter` writing into one reused buffer with SmallVec binds;
   - permission literals cached per Authorization;
   - pipelined Redis flights;
   - `get_document_prefetch`, so subQuery children (sessions, tokens, challenges, authenticators, memberships, targets) go in the parent's PG flight through correlated `(SELECT _id::text … WHERE _uid = $1 AND tenant)`. This is today's trick, moved into the library and proven equal to the filter's `decode` by a compat case per filter;
   - no SET/RESET round trips.
3. **Write-strategy planner (`database/plan.rs`).** It chooses the cheapest strategy that yields PHP's exact returned document, rows, perms, cache state, events and errors.
   - **Single create.** One CTE, insert plus `_perms`, used when there are no relationships.
   - **Single update.** A sparse SET with `_updatedAt` bumped through CASE … IS DISTINCT FROM, the permission predicate, the conflict guard (`_updatedAt <= requestTimestamp`) and a perms diff in one statement with RETURNING. It is eligible when:
     - there are no relationships and no operators;
     - `$id` is unchanged;
     - every touched attribute is SQL-comparable, meaning SQL equality equals PHP's comparison after casting (strings, ints, bools, string arrays, canonical datetimes; not json/object values, floats or arbitrary jsonb).
   - **Single delete.** One CTE.
   - **Increase.** One bounded UPDATE.
   - **Zero affected rows.** A cold probe reproduces PHP's error and its precedence: NotFound (empty document), Authorization, Conflict or Limit.
   - **Transactional.** Everything else follows PHP's sequence, with BEGIN + SELECT FOR UPDATE pipelined, then UPDATE + perms + COMMIT pipelined.
   - Fused strategies run only under the transform rule in §1. `Database::force_strategy(Faithful)` exists for compat.
4. **Typed tier (D17).** `get_as::<T: Record>`, `find_as`, `count_and_find_as`, `create_as`, `update_as(old: Option<&T>)` and `delete` are the same operations with the same planner, statements, authorization, cache protocol, purges and events. Only the decode differs: rows or cached igbinary Values go straight into T through `ColumnPlan` or `Record::from_value`. A cache hit decodes the PHP entry. A miss loads the full faithful document flight, so the lease save writes exactly what PHP would cache. The fallback, used only if the benchmarks require it, is an `uncached()` view that is provably equal to PHP with a None cache for reads; writes still purge.
5. **Target flights.**

   | Route | Today | Target |
   |---|---|---|
   | GET /users/:id | 1 PG | hit: 1 Redis; miss: 1 Redis + 1 PG + 1 Redis |
   | POST /users | 1 PG + 1 Redis | 1 PG + 1 Redis |
   | PATCH attribute | 2 PG + 1 Redis | 1 Redis (get, normally a hit) + 1 PG (Single update) + 1 Redis |
   | GET /users | find‖count on 2 connections + targets | find + count pipelined on 1 connection + targets |
   | DELETE | 1 PG + 1 Redis | 1 PG + 1 Redis |

6. **Gates.**
   - criterion and allocation baselines (counting allocator) are committed. CI fails on a regression above 5% in time or any increase in allocations.
   - `bin/compat bench database` compares PHP and Rust per operation.
   - users-compare.sh at equal load: an endpoint moves from legacy to the typed tier only when CPU ms/req and peak memory are equal or better within ±3%, with the report attached to the PR.
   - `legacy/` is deleted only when every endpoint has moved (I1).
   - The PHP Users E2E suite (`e2e_rust`) must stay green, in dedicated and shared modes, after every step.

## 7. utopia-query

- `Builder<D: Dialect>` holds Builder.php's state, with `&mut self` fluent methods and `build()/insert()/update()/delete()/upsert() -> Result<Statement>`. Cloning a builder is plain `Clone`.
- PHP `Trait\*` become impl blocks in `builder/core`.
- Each PHP `Feature\*` interface becomes a trait implemented only for the dialects whose PHP class implements it. Calling an undefined method in PHP is a compile error in Rust, recorded as a deviation. UnsupportedException paths stay runtime errors with PHP's messages.
- `Statement { query, bindings, read_only, named_bindings }` with `with_executor(Arc<dyn Executor>)`. The executor returns a BoxFuture because consumers use async clients.
- Hooks (`Filter`, `Attribute`, `JoinFilter`, `Write`) are public traits; Tenant and Map are concrete.
- Also ported: Schema builders per dialect, tokenizers, the AST (nodes, parser, walker, three visitors, per-dialect serializers) and classifiers.
- The MongoDB builder outputs php-std Values, so there is no bson dependency.

## 8. Compat plan

### Framework extensions

C1 implements these; S1 freezes their format.

**Profiles.** `spec.d/profiles.json` declares the 16 profiles, one per PHP e2e class, each with `source`, `services`, `state` and an open config:
- postgres, postgres:shared
- mariadb, mariadb:shared
- mysql, mysql:shared
- sqlite, sqlite:shared
- mongodb, mongodb:shared, mongodb:schemaless
- memory
- redis, redis:shared
- pool (over MySQL)
- mirror (MariaDB→MariaDB)

Cases use `"profiles": ["*"]` or a list, and `"requires": [capability…]`, evaluated through `adapter.capabilities`, which must agree between the two runtimes. Filters: `--profile` and `--area`. `profile_covers` credits adapter classes per profile.

**Isolation.** `"isolation": "sequential"` for database. PHP runs in `${ns}`; the runner snapshots and cleans up; Rust then runs in the same `${ns}`. Short-key md5s and identity values are therefore equal, and parallelism comes from `-j N` shards that each have their own driver pair.

**Case files and expectations.**
- `cases/<area>/**` is loaded recursively.
- Expectations live in sidecars, `expect/<profile>/<case path>.json`, written only by `bin/compat record --profile`.
- Fixtures (`use: [...]`) replace `@depends` chains, and suites support setup and teardown.

**Codec tags and the step interpreter.**
- Both drivers understand:
  - `$document` with `$class`, `$query`, `$operator`, `$datetime`, `$object` (non-empty stdClass) and `$array` (non-list arrays with int keys);
  - `$steps` (a callable) and `$arg` (the callable's arguments);
  - `$throw`.
- Both drivers interpret nested steps.
- Errors gain `code` and `attribute` where the spec opts in.

**Run variants.**
- **Strategy and statement-trace runs.** On SQL profiles each case runs on PHP once, with an identity capturing transform. Rust runs twice: forced faithful (capturing transform; results, state and the SQL trace compared) and auto (no transform; results and state compared).
- **Replay.** `--replay` drives Rust orchestration against a `ReplayAdapter` that answers from the PHP RecordingPool trace. It fails on the first adapter call whose method or arguments differ; a declared concurrent group matches as a multiset, with a reason. The cache is None in replay.
- **Masks.** Ordinal masks turn timestamps, uniqid ids and envelope time into first-appearance tokens, so equality and ordering are still checked. `"compare": "unordered"` covers orderRandom. Every mask carries a reason.

**Other runner features.**
- `bin/compat capture <lib> <TestFile>`, `bin/compat bench`, and `bin/compat overrides database` (the dialect override parity check).
- Chain coverage through `$resolve`: a builder method counts as covered only when a case calls it.

### Operation vocabulary

PHP glue is reflective: `<object>.<snake_method>` calls the camelCase method with named arguments (PHP 8 named-argument spread), so `ops/*.php` holds only special cases. The Rust glue is hand-written per area.

- **Database:**
  - `profile.open`, `profile.sql`, `profile.mongo`, `profile.redis`;
  - `database.<method>` for all 130 methods;
  - `db.scoped {scope, steps}`, `db.transaction {steps, throw}`, `events.listen` and `events.drain`;
  - `adapter.capabilities`, `adapter.limits` and `adapter.<method>`;
  - value classes: `document.*`, `query.*`, `operator.*`, `datetime.*`, `id.*`, `role.*`, `permission.*`, `authorization.*`;
  - `validator.check {class, config, value}` covering all 31 classes;
  - `mirror.*`, `pool.*`, `cache.*`;
  - `fixture.*`: the PHP test doubles (TestUser, Format, HashAwareMemoryCache, …) on both sides;
  - `typed.*`: parity ops, PHP calls the equivalent Database method;
  - `fault.inject {method, error, times}`, which replaces anonymous PHP fakes.
- **Query:**
  - `query.*`;
  - `builder.build {dialect, calls: [[method, …args]], hooks}` with `$fn`, `$builder` and `$expr` tags, returning `{query, bindings, namedBindings, readOnly}`;
  - `schema.build`, `tokenizer.tokenize`, `ast.parse`, `ast.serialize`, `ast.walk`, `classifier.classify`;
  - `exec.<engine>` for E2E.

### Snapshot kinds

Each kind has one file under `runner/snapshot/` and touches only names containing `${ns}`.

| Kind | Captures |
|---|---|
| postgres | tables; columns (type, length, nullability, default); `pg_get_indexdef` with the schema stripped; constraints; identity last values; rows ordered by `_id`, every column as `::text`; `_perms` ordered |
| mariadb, mysql | information_schema TABLES, COLUMNS and STATISTICS; rows as JSON by primary key |
| sqlite | files under `/tmp/compat-fs/${ns}/` read through `sqlite_master`, `PRAGMA` and rows, FTS shadow tables included |
| mongodb | collections with options and validators; `listIndexes`; documents in canonical Extended JSON sorted by `_uid` |
| clickhouse | for query E2E |
| redis | the existing kind, plus decoded `igbinary` envelope and `json` views |

### Services

A `compat` compose profile pins each library's own test versions:
- database: mysql 8.0.43, a second MariaDB 10.11 for the mirror, postgres 16 with pgvector, postgis and pg_trgm (check appwrite/postgres:0.1.0; otherwise build the vendor postgres.dockerfile), mongo 8.0 as a replica set, redis;
- query E2E: mysql 8.4, mariadb 11, pgvector pg16, clickhouse 24, mongo 7.

### Porting the tests

- **Capture (C2).** Generated `RecordingDatabase`, `RecordingMirror`, `RecordingAuthorization` and `RecordingPool`, plus a recorder per Builder and Schema dialect, run the 64 + 101 PHP classes under PHPUnit in the dev image. Each test method becomes a draft case whose `source` names the PHP file. Calls inside closures become `$steps`; raw PDO calls become `profile.sql`. Identical recordings across profiles collapse.
- **Hand-ported:** closures that assert internally, pg_stat and EXPLAIN checks, sleep/TTL tests, and CreateCollectionRaceTest (as interop steps).
- **PDOTest and PDOStatementTest** become reconnect and fault cases on each SQL profile. `Utopia\Database\PDO*` is waived as PHP plumbing; its reconnect-and-retry behaviour is converted.
- **Completion.** A profile counts as complete when every trait case runs on it and the class's own methods are ported in `cases/adapters/<Class>.json`.

### Fuzz, interop, CI

- **Fuzz:**
  - `Query::parse` and validators;
  - random documents against random schemas (Structure);
  - attribute and index definitions;
  - permission, role and datetime strings;
  - encode/decode/casting round trips;
  - differential find fuzzing (random filter, order and cursor programs over a seeded collection per profile);
  - stateful operation sequences on memory and postgres;
  - for query: grammar-generated builder programs per dialect, SQL tokenize→parse→serialize round trips and classifier payloads.
- **Interop, both directions per profile:**
  - rows, `_perms` and `_metadata` written by one runtime and read by the other;
  - document and query cache hits across runtimes;
  - cross-runtime purges;
  - the filter-signature field;
  - MariaDB JSON text escaping;
  - float storage at 14 digits;
  - bigint as string;
  - sequences;
  - forUpdate locking across runtimes;
  - encrypted values in appwrite-database.
- **`tests/compat/appwrite-database`.** The PHP side loads `app/init/database/{filters,formats}.php`, the User document type and the collections. The Rust side uses appwrite-core's registry. Cases are captured from the Users E2E suite and include the `typed.*` parity and prefetch≡decode checks.
- **CI.**
  - Per PR: pure cases, memory, postgres and postgres:shared (auto, faithful and trace), replay, interop and short fuzz.
  - Nightly: all 16 profiles and full fuzz.
  - `cargo test -p compat` replays the sidecars without PHP.
  - `bin/compat check database|query|cache|pools|mongo` gates `complete: true`.

## 9. Work packages and waves

Ownership rules:
- After S0, S0q and S1 land, every `lib.rs`, every `mod.rs` and every `Cargo.toml` is frozen. Changes go through the architecture owner in small PRs that others rebase onto.
- A package may add private submodules only inside directories it owns.
- `spec.d/<area>.json`, `cases/<area>/**` and the Rust adapter file of an area belong to that area's package.
- `expect/<profile>/**` belongs to the profile's adapter package.

Waves:

| Wave | Weeks | Packages |
|---|---|---|
| Upfront | 0–2 | S0 (php-std types and the workspace land first, around day 3, so P1–P3 can start), S0q, S1 |
| 1 | 2–6 | P1, P2, P3, L1, L2, L3, C1, C2, D1 (M1 at week 3), D2, D3, D9 (M2 at week 4), D14 (M3 at week 4), Q1–Q7 |
| 2 | 4–11 | D4, D5, D6, D10, D11, D12, D13, D15 |
| 3 | 8–15 | D7, D8, D16, D17, Q8 |
| 4 | 12–17 | I1 |

There are two critical paths, S0 → D1 → D4 → D6 → D8 and S0 → D9 → D10 → D17 → I1. The estimate is about 165 engineer-weeks, or roughly 17 calendar weeks with 12–15 parallel engineers or agents.

## 10. Standards documents (written in S0 and S1)

- **crates/CONVERSION.md:**
  - Fix §1: query is a sibling crate with no dependency edge.
  - Add §8, "Libraries with several backends":
    - one compat profile per PHP adapter test class;
    - adapter traces and replay as required evidence;
    - only triggered SQL text is a contract;
    - the fusion rule;
    - text-equivalent decoding (results equal casting() of the PHP driver's strings);
    - parity ops for any fast tier, plus the benchmark gate;
    - PHP-only encodings in shared caches are implemented (igbinary), never side-stepped with private fields.
- **crates/utopia/database/DESIGN.md and crates/utopia/query/DESIGN.md:** the PHP class→Rust item tables, tiers, strategies and quirks.
- **tests/compat/README.md:** profiles, isolation, sidecars, codec tags, steps, replay, traces, capture and the new snapshot kinds.
- **tests/compat/database/README.md:** the ownership table and the profile matrix.
- **rfc/rust.md:** I1 updates §3 (cache coherence: shared entries) and §4 (MariaDB and MongoDB gaps closed).

## 11. Risks and mitigations

1. **Scale.** About 75k lines of PHP source and 142k lines of PHP tests. Mitigations: capture-generated cases, replay for orchestration, profile shards, and a nightly full matrix.
2. **Engine semantics are the long pole.** DateTime parsing, PCRE `/u`, igbinary, json flags, sort flags and pdo quoting each go into php-std and are fuzzed clean before the database areas that use them can converge.
3. **Cache signatures depend on file:line.** Mitigations: the generated SignatureTable, a stale-table interop case, and a recorded quirk. The long-term fix is explicit versioned signatures in both runtimes together.
4. **Fast strategies diverging.** Mitigations: explicit eligibility rules, strategy runs on every case, cold-path error probes, and tier-equivalence property tests.
5. **Binding fidelity.** Mitigations: the S0 binding spike on the type matrix, and type-mismatch error cases pinned. Unmapped PDOException text (LINE context) is rebuilt from the server's error fields; anything that cannot be rebuilt becomes a reviewed quirk.
6. **Reference semantics.** Relationship population mutates shared PHP objects. Full-result comparison will expose any difference; if needed, D8 uses index arenas.
7. **Nondeterminism.** Ordinal masks with reasons, explicit ids, preserveDates and request timestamps, and the None cache in replay.
8. **Infrastructure.** MySQL and a second MariaDB are added in the compat profile; Mongo needs a replica set for transactions; the SQLite volume is shared with the PHP driver.
9. **Build time.** Feature-gated backends and a single `Database<AnyAdapter>` instantiation in Appwrite. DynAdapter boxing is paid only by the Custom arm.
10. **Upstream churn.** The PHP versions are pinned in spec.json, `bin/compat check` fails when composer.lock moves, and a coverage diff runs on every bump.
11. **Changing current Rust behaviour.** Unique vs Duplicate, the error mapping, the cursor API (PHP takes a cursor Document) and the cache fields all change. Consumers are updated in I1, and the Users E2E suite runs after each change.

## Appendix A. Skeleton

UPFRONT SKELETON (S0, S0q and S1 land before fan-out; every body is `todo!("<WP id>")`; `cargo check --workspace --all-features` and the compat build must pass; signatures below are frozen)

=== Workspace (S0) ===
Cargo.toml [workspace.dependencies] add (exact versions, each at least two weeks old, with a reason in the PR):
  path crates: utopia-query, utopia-pools, utopia-mongo, utopia-database-derive
  third party: smallvec, indexmap, arc-swap, parking_lot, ryu, mysql_async, mysql_common, rusqlite (bundled), mongodb, bson, syn, quote, proc-macro2, criterion (dev)
members are unchanged (globs).

=== crates/support/php-std (S0 writes signatures; P1–P3 fill them in) ===
src/lib.rs adds: pub mod types; pub mod igbinary; pub mod pdo; pub mod sort; pub mod uniqid;
  pub use types::{Value, Str, Array, ArrayKey, Extension, Never};
src/types/{mod.rs, str.rs, array.rs, value.rs}
  pub enum Never {}
  pub trait Extension: Clone + PartialEq + core::fmt::Debug + Send + Sync + 'static {
      fn class(&self) -> &str;                         // get_class()
      fn to_array(&self) -> Array<Self>;               // (array) / getArrayCopy(), used by json, igbinary and serialize
  }
  #[derive(Clone)] pub struct Str(/* Inline{len,[u8;23]} | Static(&'static [u8]) | Shared(bytes::Bytes) */);
  impl Str { pub const fn from_static(s: &'static str) -> Self; pub fn from_bytes(b: bytes::Bytes) -> Self; pub fn copy_from(b: &[u8]) -> Self;
             pub fn as_bytes(&self) -> &[u8]; pub fn as_str(&self) -> Option<&str>; pub fn len(&self) -> usize; pub fn is_empty(&self) -> bool; }
  impl Deref<Target=[u8]>, Eq, Ord, Hash, From<&str>, From<String>, From<bytes::Bytes>, Borrow<[u8]> for Str
  #[derive(Clone, PartialEq, Eq, Hash)] pub enum ArrayKey { Int(i64), Str(Str) }
  impl ArrayKey { pub fn normalize(s: Str) -> Self /* "12" → Int(12); "012", "-0", "1.5" stay Str */ }
  #[derive(Clone)] pub struct Array<X: Extension = Never>(/* Packed(Vec<Value<X>>) | Hash{entries, index, next_free} */);
  impl<X: Extension> Array<X> {
      pub fn new() -> Self; pub fn with_capacity(n: usize) -> Self; pub fn from_list(v: Vec<Value<X>>) -> Self;
      pub fn is_list(&self) -> bool; pub fn len(&self) -> usize; pub fn is_empty(&self) -> bool;
      pub fn get(&self, k: &ArrayKey) -> Option<&Value<X>>; pub fn get_str(&self, k: &str) -> Option<&Value<X>>; pub fn get_mut(&mut self, k: &ArrayKey) -> Option<&mut Value<X>>;
      pub fn contains_key(&self, k: &ArrayKey) -> bool;
      pub fn set(&mut self, k: ArrayKey, v: Value<X>) -> Option<Value<X>>;   // $a[k] = v, keeps position
      pub fn push(&mut self, v: Value<X>) -> Result<(), ArrayFull>;          // $a[] = v
      pub fn remove(&mut self, k: &ArrayKey) -> Option<Value<X>>;           // unset; a later set appends at the end
      pub fn iter(&self) -> impl Iterator<Item = (&ArrayKey, &Value<X>)>; pub fn values(&self) -> impl Iterator<Item = &Value<X>>;
      pub fn retain(&mut self, f: impl FnMut(&ArrayKey, &Value<X>) -> bool);
  }
  #[derive(Clone)] pub enum Value<X: Extension = Never> { Null, Bool(bool), Int(i64), Float(f64), Str(Str), Array(Array<X>), Object(Array<X>) /* stdClass */, Ext(X) }
  impl<X: Extension> Value<X> { pub fn type_name(&self) -> &'static str; pub fn truthy(&self) -> bool; pub fn to_php_string(&self) -> Result<Str, EngineError>;
      pub fn loose_eq(&self, o: &Self) -> bool; pub fn strict_eq(&self, o: &Self) -> bool; pub fn cmp_php(&self, o: &Self) -> core::cmp::Ordering;
      pub fn from_json(v: &serde_json::Value) -> Self /* Request decoding: {} stays Object */; pub fn to_json(&self) -> serde_json::Value; }
  pub struct EngineError { pub class: &'static str /* TypeError|ValueError|JsonException */, pub message: String }
src/json.rs
  #[derive(Clone, Copy)] pub struct JsonFlags(u32);  // consts HEX_TAG, HEX_AMP, HEX_APOS, HEX_QUOT, FORCE_OBJECT, NUMERIC_CHECK, UNESCAPED_SLASHES, PRETTY_PRINT, UNESCAPED_UNICODE, PARTIAL_OUTPUT_ON_ERROR, PRESERVE_ZERO_FRACTION, UNESCAPED_LINE_TERMINATORS, INVALID_UTF8_IGNORE, INVALID_UTF8_SUBSTITUTE, THROW_ON_ERROR, OBJECT_AS_ARRAY, BIGINT_AS_STRING
  pub enum JsonError { Depth, StateMismatch, CtrlChar, Syntax, Utf8, Recursion, InfOrNan, UnsupportedType, InvalidPropertyName, Utf16 }
  impl JsonError { pub fn code(&self) -> i64; pub fn message(&self) -> &'static str /* json_last_error_msg */ }
  pub fn encode<X: Extension>(v: &Value<X>, flags: JsonFlags, depth: u32) -> Result<Str, JsonError>;
  pub fn encode_into<X: Extension>(out: &mut Vec<u8>, v: &Value<X>, flags: JsonFlags, depth: u32) -> Result<(), JsonError>;
  pub fn decode(input: &[u8], assoc: bool, depth: u32, flags: JsonFlags) -> Result<Value, JsonError>;
src/igbinary.rs
  pub fn serialize<X: Extension>(v: &Value<X>) -> Vec<u8>;
  pub fn unserialize(input: &bytes::Bytes) -> Result<Value, IgbinaryError>;   // strings are zero-copy Str::Shared
src/serialize.rs   pub fn serialize<X: Extension>(v: &Value<X>) -> Vec<u8>; pub fn unserialize(input: &[u8]) -> Result<Value, SerializeError>;
src/datetime.rs (+ datetime/**)
  pub struct DateTime { /* instant (µs) + Zone as PHP keeps it (offset | abbreviation | identifier) */ }
  pub fn parse(s: &str, default_tz: &Zone) -> Result<DateTime, DateTimeError>;   // new \DateTime($s); messages identical
  impl DateTime { pub fn format(&self, fmt: &str) -> String; pub fn modify(&mut self, m: &str) -> Result<(), DateTimeError>; pub fn set_timezone(&mut self, z: Zone); pub fn timestamp(&self) -> i64; pub fn micros(&self) -> i64; }
src/sort.rs   pub enum SortFlags { Regular, Numeric, String, StringFoldCase, Natural, Locale }
  pub fn sort<X: Extension>(a: &mut Array<X>, f: SortFlags); pub fn ksort<X: Extension>(a: &mut Array<X>, f: SortFlags);
  pub fn array_unique<X: Extension>(a: &Array<X>, f: SortFlags) -> Array<X>; pub fn array_diff<X: Extension>(a: &Array<X>, b: &[&Array<X>]) -> Array<X>;
  pub fn array_diff_key<X: Extension>(a: &Array<X>, b: &[&Array<X>]) -> Array<X>; pub fn in_array<X: Extension>(n: &Value<X>, h: &Array<X>, strict: bool) -> bool;
src/pcre.rs   pub struct Pattern; impl Pattern { pub fn compile(php: &str) -> Result<Self, PcreError>; pub fn is_match(&self, s: &[u8]) -> bool;
  pub fn captures(&self, s: &[u8]) -> Option<Vec<Option<core::ops::Range<usize>>>>; pub fn replace(&self, s: &[u8], rep: &[u8], limit: Option<usize>) -> Vec<u8>; pub fn split(&self, s: &[u8], limit: Option<usize>) -> Vec<Str>; }
src/uniqid.rs pub fn uniqid(prefix: &str, more_entropy: bool) -> String;
src/pdo.rs    pub struct PdoError { pub sqlstate: Str, pub driver_code: Option<i64>, pub message: String }
  pub fn sqlstate_message(state: &str) -> &'static str; pub fn quote_mysql(s: &[u8], no_backslash_escapes: bool) -> Vec<u8>;
  pub fn format_pgsql(state: &str, server_msg: &str) -> String; pub fn format_mysql(state: &str, code: i64, msg: &str) -> String; pub fn format_sqlite(code: i64, msg: &str) -> String;

=== crates/utopia/pools (S0 signatures; L2 fills them in) ===
  pub struct Pool<T: Send + 'static>; impl<T> Pool<T> { pub fn new(name: &str, size: usize, init: impl Fn() -> BoxFuture<'static, Result<T>> + Send + Sync + 'static) -> Self;
      pub async fn pop(&self) -> Result<Connection<T>>; pub fn push(&self, c: Connection<T>); pub async fn use_<R, F: AsyncFnOnce(&mut T) -> Result<R>>(&self, f: F) -> Result<R>;
      pub fn count(&self) -> usize; pub fn is_empty(&self) -> bool; pub fn is_full(&self) -> bool; pub fn reclaim(&self); /* reconnect_attempts, retry_sleep, telemetry setters */ }
  pub struct Connection<T> { /* id, resource, pool back-ref */ } pub struct Group<T>; pub trait Adapter (Stack | Tokio); pub enum Error {..} (php_class())
=== crates/utopia/mongo (S0 signatures; L3 fills them in) ===
  pub struct Client; impl Client { pub async fn connect(cfg: Config) -> Result<Self>; pub async fn command(&self, db: &str, cmd: bson::Document) -> Result<bson::Document>;
      plus every public utopia-php/mongo Client method: create_collection, drop_collection, list_collections, create_indexes, drop_indexes, insert, insert_many, update, upsert, find, aggregate, count, delete, start_transaction, commit, abort, ... }
  pub enum Error { Mongo{code, message} /* Utopia\Mongo\Exception */, Unsent{..}, ... } with php_class()

=== crates/utopia/database (S0 writes ALL files; legacy moved verbatim) ===
Cargo.toml features: default=["postgres","memory"]; postgres; mariadb; mysql=["mariadb"]; sqlite; mongodb; redis; pool; mirror; derive
DESIGN.md (PHP class → Rust item table, tiers, strategies, quirks)
src/
  lib.rs           re-exports below; #[doc(hidden)] pub mod legacy;   (legacy/** = today's crate; appwrite-core/users import utopia_database::legacy::*)
  error.rs
    pub struct Detail { pub message: Cow<'static, str>, pub code: i64, pub previous: Option<Box<Error>> }
    #[derive(Debug, thiserror::Error)] pub enum Error {
      Database(Detail), Authorization(Detail), Character(Detail), Conflict(Detail), Dependency(Detail), Duplicate(Detail), Index(Detail),
      Limit(Detail), NotFound(Detail), Operator(Detail), Order(Detail, Option<String>), Query(Detail), Relationship(Detail), Restricted(Detail),
      Structure(Detail), Timeout(Detail), Transaction(Detail), Truncate(Detail), Type(Detail), Unique(Detail),
      Driver(DriverError /* PDOException | Utopia\Mongo\Exception | RedisException: class, sqlstate/code, message */), Engine(php_std::types::EngineError) }
    #[derive(Clone, Copy, PartialEq, Eq)] pub enum PhpClass { Database, Authorization, Character, Conflict, Dependency, Duplicate, Index, Limit, NotFound, Operator, Order, Query, Relationship, Restricted, Structure, Timeout, Transaction, Truncate, Type, Unique, PdoException, MongoException, RedisException, TypeError, ValueError, JsonException }
    impl Error { pub fn php_class(&self) -> &'static str; pub fn class(&self) -> PhpClass; pub fn is_a(&self, c: PhpClass) -> bool;
                 pub fn message(&self) -> &str; pub fn code(&self) -> i64; pub fn previous(&self) -> Option<&Error>; pub fn attribute(&self) -> Option<&str>;
                 pub fn new(c: PhpClass, message: impl Into<Cow<'static, str>>) -> Self; pub fn with_code(self, code: CodeArg /* int|string, Exception.php rule */) -> Self; }
    pub type Result<T, E = Error> = core::result::Result<T, E>;
  value.rs
    #[derive(Clone, PartialEq, Debug)] pub enum Ext { Document(Box<Document>), Operator(Box<Operator>) }   impl php_std::Extension for Ext
    pub type Value = php_std::Value<Ext>; pub type Array = php_std::Array<Ext>; pub type Name = php_std::Str;
    #[derive(Clone, PartialEq, Eq, Hash, Debug)] pub enum Tenant { Int(i64), Str(Name) }   impl Display (PHP string conversion)
    #[derive(Clone, Copy, Debug)] pub enum Number { Int(i64), Float(f64) }
  document.rs
    #[derive(Clone, PartialEq, Debug, Default)] pub struct Document { attrs: Array, class: Option<DocumentClass> }
    pub enum SetType { Assign, Prepend, Append }  #[derive(Clone, PartialEq, Eq, Debug)] pub struct DocumentClass(pub Name);
    pub trait DocumentType: Sized + Send { const CLASS: &'static str; fn from_document(d: Document) -> Result<Self>; fn into_document(self) -> Document; }
    impl Document { pub fn new(attrs: Array) -> Result<Self>; pub fn empty() -> Self; pub fn with_class(self, c: DocumentClass) -> Self; pub fn class(&self) -> Option<&DocumentClass>;
      pub fn id(&self) -> &str; pub fn sequence(&self) -> Option<&Name>; pub fn collection(&self) -> &str; pub fn tenant(&self) -> Option<Tenant>;
      pub fn created_at(&self) -> Option<&str>; pub fn updated_at(&self) -> Option<&str>;
      pub fn permissions(&self) -> Vec<Name>; pub fn read(&self) -> Vec<Name>; pub fn create(&self) -> Vec<Name>; pub fn update(&self) -> Vec<Name>; pub fn delete(&self) -> Vec<Name>; pub fn write(&self) -> Vec<Name>;
      pub fn permissions_by_type(&self, t: Action) -> Vec<Name>; pub fn attributes(&self) -> Array;
      pub fn get(&self, key: &str) -> Option<&Value>; pub fn get_or<'a>(&'a self, key: &str, default: &'a Value) -> &'a Value; pub fn get_mut(&mut self, key: &str) -> Option<&mut Value>;
      pub fn set(&mut self, key: impl Into<Name>, v: Value, how: SetType) -> &mut Self; pub fn set_many(&mut self, a: Array) -> &mut Self; pub fn remove(&mut self, key: &str) -> Option<Value>;
      pub fn find(&self, key: &str, needle: &Value, subject: &str) -> Option<&Value>;
      pub fn find_and_replace(&mut self, key: &str, needle: &Value, replacement: Value, subject: &str) -> bool;
      pub fn find_and_remove(&mut self, key: &str, needle: &Value, subject: &str) -> bool;
      pub fn is_empty(&self) -> bool; pub fn is_set(&self, key: &str) -> bool; pub fn array_copy(&self, allow: &[&str], disallow: &[&str]) -> Array; pub fn into_array(self) -> Array; pub fn as_array(&self) -> &Array; }
  change.rs   pub struct Change { pub old: Document, pub new: Document }
  datetime.rs pub fn now() -> String; pub fn format(d: &php_std::datetime::DateTime) -> String; pub fn format_tz(..) -> String; pub fn add_seconds(..) -> ..; pub fn set_timezone(..) -> ..; (DateTime.php 1:1)
  connection.rs pub fn has_lost_connection(message: &str) -> bool  (Connection.php message table)
  query/{mod.rs, method.rs, parse.rs, ctor.rs, group.rs, shape.rs}
    #[derive(Clone, Copy, PartialEq, Eq, Hash, Debug)] pub enum Method { /* every Query::TYPE_* constant, frozen from Query::TYPES */ }
    impl Method { pub fn as_str(self) -> &'static str; pub fn parse(s: &str) -> Option<Self>; pub fn is_filter(self) -> bool; pub fn is_spatial(self) -> bool; pub fn is_vector(self) -> bool; pub fn is_nested(self) -> bool; }
    #[derive(Clone, PartialEq, Debug)] pub enum QueryValue { Value(Value), Query(Query) }
    #[derive(Clone, PartialEq, Debug)] pub struct Query { method: Method, attribute: Name, values: smallvec::SmallVec<[QueryValue; 2]>, attribute_type: Option<AttributeType>, on_array: bool }
    impl Query { pub fn new(m: Method, attribute: impl Into<Name>, values: impl IntoIterator<Item = QueryValue>) -> Self;
      /* one constructor per PHP static: equal, not_equal, less_than, ..., between, starts_with, regex, exists, select, order_asc, order_desc, order_random, limit, offset, cursor_after(Document), cursor_before(Document), and, or, elem_match, contains_all, distance_*, intersects, crosses, overlaps, touches, vector_* ... */
      pub fn parse(s: &str) -> Result<Query>; pub fn parse_query(v: &Value) -> Result<Query>; pub fn parse_queries(list: &[Name]) -> Result<Vec<Query>>;
      pub fn to_array(&self) -> Array; pub fn to_string(&self) -> Result<String>; pub fn shape(&self) -> String; pub fn fingerprint(qs: &[Query]) -> String;
      pub fn get_by_type<'q>(qs: &'q [Query], ms: &[Method]) -> Vec<&'q Query>; pub fn get_cursor_queries<'q>(qs: &'q [Query]) -> Vec<&'q Query>;
      pub fn group_by_type(qs: &[Query]) -> Grouped<'_>;
      pub fn method(&self) -> Method; pub fn attribute(&self) -> &Name; pub fn values(&self) -> &[QueryValue]; pub fn value(&self) -> Option<&QueryValue>;
      pub fn on_array(&self) -> bool; pub fn attribute_type(&self) -> Option<AttributeType>; pub fn set_attribute(&mut self, a: Name); pub fn set_values(&mut self, v: impl IntoIterator<Item = QueryValue>); pub fn set_on_array(&mut self, b: bool); pub fn set_attribute_type(&mut self, t: AttributeType); pub fn is_nested(&self) -> bool; }
    pub struct Grouped<'q> { pub filters: Vec<&'q Query>, pub selections: Vec<&'q Query>, pub limit: Option<&'q QueryValue>, pub offset: Option<&'q QueryValue>,
                             pub order_attributes: Vec<&'q Name>, pub order_types: Vec<OrderType>, pub cursor: Option<&'q QueryValue>, pub cursor_direction: Option<CursorDirection> }
    #[derive(Clone, Copy, PartialEq, Eq, Debug)] pub enum OrderType { Asc, Desc, Random }   #[derive(Clone, Copy, PartialEq, Eq, Debug)] pub enum CursorDirection { After, Before }
  operator.rs  #[derive(Clone, Copy, PartialEq, Eq, Debug)] pub enum OperatorMethod { /* every Operator::TYPE_* */ }
    #[derive(Clone, PartialEq, Debug)] pub struct Operator { method: OperatorMethod, attribute: Name, values: Vec<Value> }
    impl Operator { /* static constructors */ pub fn parse(s: &str) -> Result<Self>; pub fn parse_operators(list: &[Name]) -> Result<Vec<Self>>; pub fn to_string(&self) -> Result<String>; pub fn is_method(s: &str) -> bool; }
  helpers/{id.rs, permission.rs, role.rs}
    pub mod id { pub fn unique(padding: usize) -> String; pub fn custom(id: &str) -> String; }
    #[derive(Clone, Copy, PartialEq, Eq, Debug)] pub enum PermissionType { Create, Read, Update, Delete, Write }
    pub struct Permission { pub kind: PermissionType, pub role: Role }  impl Permission { pub fn parse(s: &str) -> Result<Self>; pub fn aggregate(ps: &[Name], allowed: &[PermissionType]) -> Vec<Name>; } impl Display
    pub struct Role { pub name: RoleName, pub identifier: Name, pub dimension: Name }  impl Role { pub fn parse(s: &str) -> Result<Self>; pub fn any() -> Self; guests; users(dim); user(id, dim); team(id, dim); member(id); label(l); } impl Display
  authorization.rs
    #[derive(Clone, Copy, PartialEq, Eq, Debug)] pub enum Action { Create, Read, Update, Delete, Write }
    pub struct Input<'a> { pub action: Action, pub permissions: &'a [Name] }
    pub struct Authorization { /* RwLock<IndexSet<Name>>, AtomicBool status, AtomicBool default_status, AtomicU64 role_generation */ }
    impl Authorization { pub fn new() -> Arc<Self>; pub fn add_role(&self, r: &str); pub fn remove_role(&self, r: &str); pub fn roles(&self) -> Vec<Name>; pub fn clean_roles(&self); pub fn has_role(&self, r: &str) -> bool;
      pub fn set_status(&self, b: bool); pub fn status(&self) -> bool /* honours the task-local skip overlay */; pub fn set_default_status(&self, b: bool); pub fn enable(&self); pub fn disable(&self); pub fn reset(&self);
      pub fn is_valid(&self, input: &Input<'_>) -> core::result::Result<(), String>; pub fn description(&self) -> String;
      pub async fn skip<F: Future>(self: &Arc<Self>, f: F) -> F::Output; }
  schema/{mod.rs, collection.rs, attribute.rs, index.rs, consts.rs}
    pub struct Collection { /* doc, id, attributes: Arc<[Attribute]>, by_key, indexes: Arc<[Index]>, permissions, document_security */ }
    impl Collection { pub fn from_document(d: Document) -> Result<Arc<Self>>; pub fn document(&self) -> &Document; pub fn id(&self) -> &str; pub fn attribute(&self, key: &str) -> Option<&Attribute>;
      pub fn attributes(&self) -> &[Attribute]; pub fn indexes(&self) -> &[Index]; pub fn permissions(&self, a: Action) -> Vec<Name>; pub fn document_security(&self) -> bool; }
    pub struct Attribute { pub key: Name, pub kind: AttributeType, pub size: i64, pub required: bool, pub signed: bool, pub array: bool, pub format: Option<Name>, pub format_options: Value, pub filters: smallvec::SmallVec<[Name; 2]>, pub default: Value, pub options: Value }
    pub enum AttributeType { /* every VAR_* */ } pub enum IndexType { /* every INDEX_* */ } pub enum RelationType { OneToOne, OneToMany, ManyToOne, ManyToMany } pub enum RelationSide { Parent, Child } pub enum OnDelete { Restrict, Cascade, SetNull }
    pub struct Index { pub key: Name, pub kind: IndexType, pub attributes: Vec<Name>, pub lengths: Vec<Option<i64>>, pub orders: Vec<Option<Name>>, pub ttl: i64 }
    #[derive(Default)] pub struct AttributeSpec { pub id: Name, pub kind: Option<AttributeType>, pub size: i64, pub required: bool, pub default: Value, pub signed: bool, pub array: bool, pub format: Option<Name>, pub format_options: Array, pub filters: Vec<Name> }
    #[derive(Default)] pub struct UpdateAttribute { pub kind: Option<AttributeType>, pub size: Option<i64>, pub required: Option<bool>, pub default: Option<Value>, pub signed: Option<bool>, pub array: Option<bool>, pub format: Option<Name>, pub format_options: Option<Array>, pub filters: Option<Vec<Name>>, pub new_key: Option<Name> }
    pub struct IndexSpec { pub id: Name, pub kind: IndexType, pub attributes: Vec<Name>, pub lengths: Vec<Option<i64>>, pub orders: Vec<Option<Name>>, pub attribute_types: Vec<AttributeType>, pub collation: Vec<Name>, pub ttl: i64 }
    pub struct RelationSpec { pub collection: Name, pub related: Name, pub kind: RelationType, pub two_way: bool, pub key: Name, pub two_way_key: Name, pub side: RelationSide, pub on_delete: OnDelete }
    pub mod consts { pub const METADATA: &str = "_metadata"; INTERNAL_ATTRIBUTES, INTERNAL_INDEXES, COLLECTION (meta-schema), INSERT_BATCH_SIZE, DELETE_BATCH_SIZE, RELATION_MAX_DEPTH, TTL, PERMISSIONS, ... }
  validator/  one file per PHP class: attribute, authorization_input (re-export), bigint, byte_length, datetime, index, index_dependency, indexed_queries, key, label, object, operator, partial_structure, permissions, queries, queries/{document, documents}, query/{base, cursor, filter, limit, offset, order, select}, roles, sequence, spatial, structure, uid, vector
    every struct implements utopia_validators::Validator (description(), is_valid(&serde_json::Value), is_array(), kind()) and has a typed `validate(&self, v: &Value) -> core::result::Result<(), Cow<'static, str>>`
  filter/{mod.rs, builtin.rs}
    pub trait Filter: Send + Sync + 'static {
      fn signature(&self) -> &str;
      fn encode(&self, v: Value, doc: &mut Document, ctx: &dyn FilterContext) -> Result<Value>;
      fn decode<'a>(&'a self, v: Value, doc: &'a mut Document, ctx: &'a dyn FilterContext) -> FilterResult<'a>;
      fn deterministic(&self) -> bool { true }
      fn prefetch(&self, attribute: &Attribute) -> Option<Prefetch> { None }
      fn decode_prefetched(&self, rows: Vec<Document>, doc: &mut Document) -> Result<Value> { unimplemented!() } }
    pub enum FilterResult<'a> { Ready(Result<Value>), Pending(futures::future::BoxFuture<'a, Result<Value>>) }
    pub trait FilterContext: Send + Sync { fn find<'a>(&'a self, collection: &'a str, queries: &'a [Query]) -> BoxFuture<'a, Result<Vec<Document>>>; fn authorization(&self) -> &Arc<Authorization>; fn namespace(&self) -> &str; fn tenant(&self) -> Option<&Tenant>; }
    pub struct Prefetch { pub collection: Name, pub attribute: Name, pub key: PrefetchKey /* Sequence | Id */, pub queries: Vec<Query>, pub skip_auth: bool }
    pub struct SignatureTable(/* HashMap<Name, Name> */); impl SignatureTable { pub fn load(json: &[u8]) -> Result<Self>; pub fn get(&self, name: &str) -> Option<&str>; }
    pub struct Registry { /* ArcSwap<IndexMap<Name, Arc<dyn Filter>>>, formats, version: AtomicU64 */ }
    impl Registry { pub fn global() -> &'static Registry; pub fn add_filter(&self, name: &str, f: Arc<dyn Filter>); pub fn filter(&self, name: &str) -> Option<Arc<dyn Filter>>; pub fn version(&self) -> u64;
                    pub fn add_format(&self, name: &str, f: Arc<dyn FormatFactory>, kind: AttributeType); pub fn set_signatures(&self, t: SignatureTable); }
    pub struct FilterSet { /* instance filters, disabled names, cached signature json */ }
    pub trait FormatFactory: Send + Sync { fn validator(&self, attribute: &Attribute) -> Box<dyn utopia_validators::Validator>; }
  event.rs
    #[derive(Clone, Copy, PartialEq, Eq, Hash, Debug)] pub enum Event { All, /* every EVENT_* */ }  impl Event { pub fn as_str(self) -> &'static str }
    pub enum Payload<'a> { Document(&'a Document), Documents(&'a [Document]), Count(u64), Value(&'a Value), Lazy(&'a (dyn Fn() -> Value + Sync)) }
    pub trait Listener: Send + Sync { fn handle(&self, event: Event, payload: &Payload<'_>) -> Result<()>; }
    pub trait Transform: Send + Sync { fn apply(&self, sql: String) -> String; }
  adapter/mod.rs
    #[derive(Clone)] pub struct Scope { pub database: Name, pub namespace: Name, pub shared_tables: bool, pub tenant: Option<Tenant>, pub tenant_per_document: bool,
      pub authorization: Arc<Authorization>, pub timeouts: smallvec::SmallVec<[(Event, u32); 2]>, pub metadata: Option<Arc<Metadata>>, pub transforms: Option<Arc<Transforms>>,
      pub support_for_attributes: Option<bool>, pub skip_duplicates: bool, pub alter_locks: bool, pub debug: Option<Arc<Debug>> }
    pub struct Cx<'a, T> { pub scope: &'a Scope, pub tx: Option<&'a T> }  (Copy)
    #[derive(Clone, Copy, Default, Debug)] pub struct Capabilities { /* one `pub <name>: bool` per getSupportFor*, e.g. schemas, attributes, index, unique_index, fulltext_index, casting, relationships, update_lock, batch_operations, upserts, vectors, caching, hostname, spatial_attributes, object, operators, order_random, transaction_retries, nested_transactions, ttl_indexes, json_overlaps, pcre_regex, posix_regex, ... */ }
    #[derive(Clone, Copy, Debug)] pub struct Limits { pub string: i64, pub int: i64, pub bigint: i64, pub attributes: i64, pub indexes: i64, pub index_length: i64, pub varchar: i64, pub uid: i64, pub min_datetime: &'static str, pub max_datetime: &'static str, pub document_size: i64, pub default_attributes: i64, pub default_indexes: i64, pub id_attribute_type: &'static str }
    pub struct FindSpec<'a> { pub queries: &'a [Query], pub limit: Option<i64>, pub offset: Option<i64>, pub order_attributes: &'a [Name], pub order_types: &'a [OrderType], pub cursor: &'a Array, pub cursor_direction: CursorDirection, pub for_permission: Action }
    pub enum WritePlan { /* computed by database::plan: touched columns, perms diff, guards */ }
    pub trait Adapter: Send + Sync + 'static {
      type Tx: Send + Sync + 'static;
      const DRIVER: &'static str;
      fn capabilities(&self, s: &Scope) -> Capabilities; fn limits(&self) -> &Limits; fn keywords(&self) -> &'static [&'static str]; fn internal_indexes_keys(&self) -> &'static [&'static str];
      fn hostname(&self) -> &str; fn filter(&self, value: &str) -> Result<Name>; fn tenant_query(&self, s: &Scope, collection: &str, alias: &str) -> String;
      fn casting_before(&self, s: &Scope, c: &Collection, d: Document) -> Result<Document>; fn casting_after(&self, s: &Scope, c: &Collection, d: Document) -> Result<Document>;
      fn set_utc_datetime(&self, v: &str) -> Result<Value>; fn decode_point(&self, wkb: &[u8]) -> Result<Value>; fn decode_linestring(&self, wkb: &[u8]) -> Result<Value>; fn decode_polygon(&self, wkb: &[u8]) -> Result<Value>;
      fn count_of_attributes(&self, c: &Collection) -> i64; fn count_of_indexes(&self, c: &Collection) -> i64; fn attribute_width(&self, c: &Collection) -> i64;
      // all async methods return `impl Future<Output = Result<T>> + Send`, abbreviated F<T>:
      fn ping(&self, cx: Cx<'_, Self::Tx>) -> F<bool>; fn reconnect(&self) -> F<()>; fn connection_id(&self, cx: Cx<'_, Self::Tx>) -> F<String>;
      fn begin(&self, s: &Scope, parent: Option<&Self::Tx>) -> F<Self::Tx>; fn commit(&self, tx: &Self::Tx) -> F<bool>; fn rollback(&self, tx: &Self::Tx) -> F<bool>;
      fn create(&self, cx, name: &str) -> F<bool>; fn exists(&self, cx, database: &str, collection: Option<&str>) -> F<bool>; fn list(&self, cx) -> F<Vec<Document>>; fn delete(&self, cx, name: &str) -> F<bool>;
      fn create_collection(&self, cx, name: &str, attributes: &[Attribute], indexes: &[Index]) -> F<bool>; fn delete_collection(&self, cx, id: &str) -> F<bool>; fn analyze_collection(&self, cx, id: &str) -> F<bool>;
      fn size_of_collection(&self, cx, id: &str) -> F<i64>; fn size_of_collection_on_disk(&self, cx, id: &str) -> F<i64>; fn schema_attributes(&self, cx, id: &str) -> F<Vec<Document>>; fn schema_indexes(&self, cx, id: &str) -> F<Vec<Document>>;
      fn create_attribute(&self, cx, collection: &str, a: &AttributeSpec) -> F<bool>; fn create_attributes(&self, cx, collection: &str, a: &[AttributeSpec]) -> F<bool>;
      fn update_attribute(&self, cx, collection: &str, id: &str, a: &AttributeSpec, new_key: Option<&str>) -> F<bool>; fn delete_attribute(&self, cx, collection: &str, id: &str) -> F<bool>; fn rename_attribute(&self, cx, collection: &str, old: &str, new: &str) -> F<bool>;
      fn create_relationship(&self, cx, r: &RelationSpec) -> F<bool>; fn update_relationship(&self, cx, r: &RelationSpec, new_key: Option<&str>, new_two_way_key: Option<&str>) -> F<bool>; fn delete_relationship(&self, cx, r: &RelationSpec) -> F<bool>;
      fn create_index(&self, cx, collection: &str, i: &IndexSpec) -> F<bool>; fn delete_index(&self, cx, collection: &str, id: &str) -> F<bool>; fn rename_index(&self, cx, collection: &str, old: &str, new: &str) -> F<bool>;
      fn get_document(&self, cx, c: &Collection, id: &str, queries: &[Query], for_update: bool) -> F<Document>;
      fn create_document(&self, cx, c: &Collection, d: Document) -> F<Document>; fn create_documents(&self, cx, c: &Collection, ds: Vec<Document>) -> F<Vec<Document>>;
      fn update_document(&self, cx, c: &Collection, id: &str, d: Document, skip_permissions: bool) -> F<Document>; fn update_documents(&self, cx, c: &Collection, updates: &Document, ds: &[Document]) -> F<u64>;
      fn upsert_documents(&self, cx, c: &Collection, attribute: &str, changes: Vec<Change>) -> F<Vec<Document>>; fn get_sequences(&self, cx, collection: &str, ds: Vec<Document>) -> F<Vec<Document>>;
      fn delete_document(&self, cx, collection: &str, id: &str) -> F<bool>; fn delete_documents(&self, cx, collection: &str, sequences: &[Name], permission_ids: &[Name]) -> F<u64>;
      fn increase_document_attribute(&self, cx, collection: &str, id: &str, attribute: &str, by: Number, updated_at: &str, min: Option<Number>, max: Option<Number>) -> F<bool>;
      fn find(&self, cx, c: &Collection, spec: &FindSpec<'_>) -> F<Vec<Document>>; fn count(&self, cx, c: &Collection, q: &[Query], max: Option<i64>) -> F<i64>; fn sum(&self, cx, c: &Collection, attribute: &str, q: &[Query], max: Option<i64>) -> F<Number>;
      // strategy hooks (defaults return Ok(None) = use the faithful sequence)
      fn fused_create(&self, cx, c: &Collection, d: &Document, p: &WritePlan) -> F<Option<Document>> { async { Ok(None) } }
      fn fused_update(&self, cx, c: &Collection, id: &str, p: &WritePlan) -> F<Option<FusedOutcome>> { async { Ok(None) } }
      fn fused_delete(&self, cx, c: &Collection, id: &str, p: &WritePlan) -> F<Option<bool>> { async { Ok(None) } }
      fn get_document_prefetch(&self, cx, c: &Collection, id: &str, q: &[Query], pre: &[Prefetch]) -> F<Option<(Document, Vec<Vec<Document>>)>> { async { Ok(None) } } }
    pub trait RecordSource: Adapter { fn get_records<T: Record>(&self, cx: Cx<'_, Self::Tx>, c: &Collection, id: &str, pre: &[Prefetch]) -> F<Option<(T, Vec<Vec<Document>>)>>; fn find_records<T: Record>(&self, cx: Cx<'_, Self::Tx>, c: &Collection, spec: &FindSpec<'_>) -> F<Vec<T>>; }
  adapter/any.rs     pub enum AnyAdapter { #[cfg(feature="postgres")] Postgres(Postgres), #[cfg(feature="mariadb")] MariaDb(MariaDb), #[cfg(feature="mysql")] MySql(MySql), #[cfg(feature="sqlite")] Sqlite(Sqlite), #[cfg(feature="mongodb")] Mongo(Mongo), #[cfg(feature="memory")] Memory(Memory), #[cfg(feature="redis")] Redis(RedisAdapter), #[cfg(feature="pool")] Pool(pool::Pool<Box<AnyAdapter>>), Custom(Arc<dyn DynAdapter>) }   dispatch!{} macro: impl Adapter + RecordSource for AnyAdapter
  adapter/dynamic.rs pub trait DynAdapter: Send + Sync { /* object-safe mirror of Adapter: every async fn returns BoxFuture<'_, Result<T>>, Tx = Box<dyn Any + Send + Sync> */ }  impl<A: Adapter> DynAdapter for A
  adapter/row.rs     pub trait RowReader { fn len(&self) -> usize; fn name(&self, i: usize) -> &str; fn str(&self, i: usize) -> Result<Option<&str>>; fn i64(&self, i: usize) -> Result<Option<i64>>; fn f64(&self, i: usize) -> Result<Option<f64>>; fn bool(&self, i: usize) -> Result<Option<bool>>; fn bytes(&self, i: usize) -> Result<Option<&[u8]>>; fn php(&self, i: usize, kind: ColumnKind) -> Result<Value> /* = casting() of the PHP driver's string */; }
                     pub struct ColumnPlan { /* column index → (attribute Name, ColumnKind, filters) resolved once per statement shape */ } impl ColumnPlan { pub fn build(c: &Collection, names: impl Iterator<Item = &str>) -> Self; }
  adapter/sql/{mod.rs, dialect.rs, driver.rs, writer.rs, bind.rs, exec.rs, conditions.rs, permissions.rs, projection.rs, cursor.rs, ddl.rs, dml.rs, upsert.rs, operators.rs, spatial.rs, casting.rs}
    pub struct Sql<D: Dialect> { pub(crate) dialect: D, pub(crate) driver: D::Driver }   impl<D: Dialect> Adapter for Sql<D>; impl<D: Dialect> RecordSource for Sql<D>
    pub trait Driver: Send + Sync + 'static { type Conn: Send + Sync; type Row: RowReader + Send;
      fn acquire(&self) -> F<Self::Conn>; fn query(&self, c: &mut Self::Conn, st: &Stmt<'_>) -> F<Vec<Self::Row>>; fn execute(&self, c: &mut Self::Conn, st: &Stmt<'_>) -> F<u64>;
      fn pipeline(&self, c: &mut Self::Conn, sts: &[Stmt<'_>]) -> F<Vec<Vec<Self::Row>>>; fn begin(&self, c: &mut Self::Conn) -> F<()>; fn savepoint(&self, c: &mut Self::Conn, n: u32) -> F<()>; fn commit(&self, c: &mut Self::Conn) -> F<()>; fn rollback(&self, c: &mut Self::Conn, to: Option<u32>) -> F<()>; fn hostname(&self) -> &str; }
    pub struct Stmt<'a> { pub event: Event, pub text: Cow<'a, str> /* PHP-identical after transforms */, pub binds: &'a [Bind] }
    pub enum Bind { Null, Int(i64), Float(f64), Bool(bool), Str(Name), Json(Value), Bytes(bytes::Bytes) }   pub enum PdoType { Null, Int, Str, Bool, Lob }
    pub struct SqlWriter { /* String buffer, SmallVec<[(Name /* :key_N */, Bind); 16]> */ }
    impl SqlWriter { pub fn new(capacity: usize) -> Self; pub fn push(&mut self, s: &str); pub fn ident(&mut self, quote: char, s: &str); pub fn bind(&mut self, name: Option<&str>, b: Bind) -> &str; pub fn finish(self, event: Event, s: &Scope) -> OwnedStmt; }
    pub trait Dialect: Send + Sync + Sized + 'static {
      type Driver: Driver; const NAME: &'static str; const QUOTE: char; const MAX_IDENTIFIER: usize; const BASE_CAPABILITIES: Capabilities; const LIMITS: Limits; const OVERRIDES: &'static [&'static str];
      fn sql_type(&self, a: &AttributeSpec) -> Result<String>; fn sql_index_type(&self, t: IndexType) -> Result<&'static str>;
      fn sql_table(&self, s: &Scope, name: &str) -> Result<String> { /* SQL.php */ } fn short_key<'k>(&self, key: &'k str) -> Cow<'k, str> { Cow::Borrowed(key) }
      fn sql_condition(&self, w: &mut SqlWriter, s: &Scope, q: &Query, for_collection: Option<&str>) -> Result<()>;
      fn sql_permissions_condition(&self, w: &mut SqlWriter, s: &Scope, collection: &str, alias: &str, action: Action) -> Result<()> { /* SQL.php */ }
      fn tenant_condition(&self, w: &mut SqlWriter, s: &Scope, collection: &str, alias: &str) { /* SQL.php */ }
      fn fulltext_value(&self, v: &str) -> String; fn operator_sql(&self, w: &mut SqlWriter, column: &str, op: &Operator) -> Result<bool>; fn upsert_statement(&self, w: &mut SqlWriter, s: &Scope, table: &str, columns: &[Name], attribute: &str) -> Result<()>;
      fn pdo_type(&self, v: &Value) -> PdoType; fn random_order(&self) -> &'static str; fn max_point_size(&self) -> usize;
      fn spatial_geom_from_text(&self, w: &mut SqlWriter, wkt: &str, srid: Option<i64>); fn spatial_axis_order_spec(&self) -> &'static str { "" };
      fn insert_keyword(&self, s: &Scope) -> &'static str { "INSERT INTO" } fn insert_suffix(&self, s: &Scope, table: &str) -> String { String::new() } fn insert_permissions_suffix(&self, s: &Scope) -> String { String::new() }
      fn attribute_projection(&self, w: &mut SqlWriter, selections: &[Name], prefix: &str); fn process_exception(&self, e: DriverError) -> Error;
      // public-method overrides; the default delegates to the generic SQL.php body in sql::{ddl,dml,…}
      fn create_collection(&self, a: &Sql<Self>, cx: Cx<'_, SqlTx<Self>>, name: &str, attrs: &[Attribute], idx: &[Index]) -> F<bool> { sql::ddl::create_collection(a, cx, name, attrs, idx) }
      /* same pattern for: create, delete, exists, list, create_attribute(s), update_attribute, delete_attribute, rename_attribute, create/update/delete_relationship, create_index, delete_index, rename_index, get_document, create_document(s), update_document(s), upsert_documents, increase_document_attribute, delete_document(s), find, count, sum, size_of_collection(_on_disk), schema_attributes, schema_indexes, connection_id, casting_before, casting_after, fused_create, fused_update, fused_delete, get_document_prefetch */ }
    pub type SqlTx<D> = Arc<tokio::sync::Mutex<TxConn<<<D as Dialect>::Driver as Driver>::Conn>>>;
  adapter/postgres/{mod.rs, dialect.rs, driver.rs, ddl.rs, documents.rs, conditions.rs, errors.rs, strategies.rs}   pub struct PostgresDialect; pub type Postgres = Sql<PostgresDialect>;  impl Postgres { pub fn connect(cfg: PgConfig) -> Result<Self>; pub fn from_pool(p: deadpool_postgres::Pool, host: &str) -> Self; }
  adapter/mariadb/{mod.rs, dialect.rs, driver.rs, ddl.rs, documents.rs, conditions.rs, errors.rs}  pub struct MariaDbDialect; pub type MariaDb = Sql<MariaDbDialect>;
  adapter/mysql.rs   pub struct MySqlDialect(MariaDbDialect); pub type MySql = Sql<MySqlDialect>;   (delegate! macro forwards the non-overridden hooks)
  adapter/sqlite/{mod.rs, dialect.rs, driver.rs /* blocking actor */, ddl.rs, documents.rs, errors.rs}  pub struct SqliteDialect(MariaDbDialect); pub type Sqlite = Sql<SqliteDialect>;
  adapter/mongo/{mod.rs, filters.rs, ddl.rs, dml.rs, errors.rs}  pub struct Mongo; impl Adapter for Mongo;  impl Mongo { pub fn new(client: utopia_mongo::Client) -> Self }
  adapter/eval/{mod.rs, compare.rs, matcher.rs, order.rs}   pub(crate) fn matches(doc: &Document, q: &Query, c: &Collection) -> Result<bool>; pub(crate) fn sort(docs: &mut [Document], spec: &FindSpec<'_>) -> Result<()>;
  adapter/memory/**  pub struct Memory; impl Adapter for Memory      adapter/redis/** pub struct RedisAdapter; impl Adapter for RedisAdapter
  adapter/pool.rs    pub struct Pool<A: Adapter> { /* utopia_pools::Pool<A> */ } impl<A: Adapter> Adapter for Pool<A>
  database/mod.rs
    pub struct Database<A: Adapter = AnyAdapter> { shared: Arc<Shared<A>>, scope: Scope, flags: Flags, filters: Option<Arc<FilterSet>>, listeners: Listeners, silenced: Option<Arc<[Name]>>,
      document_types: Arc<HashMap<Name, DocumentClass>>, request_timestamp: Option<php_std::datetime::DateTime>, max_query_values: u32, global_collections: Arc<HashSet<Name>>,
      tx: Option<Arc<A::Tx>>, relation: RelationCtx, strategy: StrategyMode }
    pub struct Shared<A: Adapter> { pub(crate) adapter: A, pub(crate) cache: Arc<utopia_cache::Cache>, pub(crate) cache_name: Name, pub(crate) registry: &'static Registry, pub(crate) schemas: SchemaCache, pub(crate) mirror: Option<Arc<crate::mirror::Tap>> }
    bitflags-like struct Flags (FILTERS, VALIDATE, RESOLVE_RELATIONSHIPS, CHECK_RELATIONSHIPS_EXIST, SKIP_DUPLICATES, PRESERVE_DATES, PRESERVE_SEQUENCE, MIGRATING, DROP_UNKNOWN, LOCKS)
    pub enum StrategyMode { Auto, Faithful }
    impl<A: Adapter> Clone for Database<A>;   impl<A: Adapter> FilterContext for Database<A>
    impl<A: Adapter> Database<A> { pub fn new(adapter: A, cache: Arc<utopia_cache::Cache>, filters: FilterSet) -> Self; pub fn adapter(&self) -> &A; pub fn force_strategy(&mut self, m: StrategyMode); }
  database/config.rs  (&mut self setters returning Result<&mut Self> when PHP can throw; getters &self)
    on(event, name, Option<Arc<dyn Listener>>); before(event, name, Option<Arc<dyn Transform>>); connection_id().await; set_namespace/namespace; id_attribute_type; set_database/database; set_cache/cache;
    set_cache_name/cache_name; set_metadata/metadata/reset_metadata; set_authorization/authorization; set_timeout(ms, Event)/clear_timeout(Event); enable_filters/disable_filters/instance_filters;
    enable_validation/disable_validation; shared_tables/set_shared_tables; set_tenant/tenant; set_tenant_per_document/tenant_per_document; enable_locks; set_document_type/document_type/clear_document_type/clear_all_document_types;
    drop_unknown_attributes/set_drop_unknown_attributes; preserve_dates/set_preserve_dates; set_migrating/is_migrating; preserve_sequence/set_preserve_sequence; set_max_query_values/max_query_values;
    set_global_collections/global_collections/reset_global_collections; keywords; limit_for_attributes; limit_for_indexes; internal_attributes; schema_attributes(c).await; schema_indexes(c).await; ping().await; reconnect().await
  database/scope.rs   (by-value views; nothing shared is mutated)
    pub fn silent(&self, only: Option<&[&str]>) -> Self; skip_relationships(&self) -> Self; skip_relationships_exist_check(&self) -> Self; skip_duplicates(&self) -> Self; with_request_timestamp(&self, ts: Option<DateTime>) -> Self;
    skip_filters(&self, names: Option<&[&str]>) -> Self; skip_validation(&self) -> Self; with_tenant(&self, t: Option<Tenant>) -> Self; with_preserve_dates(&self, b: bool) -> Self; with_preserve_sequence(&self, b: bool) -> Self; uncached(&self) -> Self
  database/transaction.rs  pub async fn with_transaction<T, F, Fut>(&self, f: F) -> Result<T> where F: FnMut(Database<A>) -> Fut + Send, Fut: Future<Output = Result<T>> + Send, T: Send;
  database/{tenancy.rs, convert.rs}  pub fn convert_queries(&self, c: &Collection, q: Vec<Query>) -> Result<Vec<Query>>; pub fn convert_query(&self, c: &Collection, q: Query) -> Result<Query>;
  database/codec.rs   pub async fn encode(&self, c: &Collection, d: Document, apply_defaults: bool) -> Result<Document>; pub async fn decode(&self, c: &Collection, d: Document, selections: &[Name]) -> Result<Document>; pub fn casting(&self, c: &Collection, d: Document) -> Result<Document>;
  database/cache.rs   pub fn cache_base_keys(&self, collection: &str, id: Option<&str>) -> (String, String); pub fn cache_keys(&self, collection: &str, id: Option<&str>, selects: &[Name]) -> (String, String, String);
    pub fn query_cache_key(&self, collection: &str, namespace: Option<&str>) -> String; pub fn query_cache_field(&self, c: Option<&Collection>, q: &[Query], field: &str, p: Action) -> Result<Option<String>>;
    pub async fn purge_cached_collection(&self, id: &str) -> Result<bool>; pub async fn purge_cached_document(&self, collection: &str, id: Option<&str>) -> Result<bool>; pub async fn purge_cached_queries(&self, collection: &str, ns: Option<&str>) -> Result<bool>;
    pub async fn with_cache<T, F, Fut>(&self, key: &str, hash: Option<&str>, f: F) -> Result<Value> where F: FnOnce(Database<A>) -> Fut + Send, Fut: Future<Output = Result<Value>> + Send;
  database/collections.rs  create(name) exists(db, Option<collection>) list() delete(name); create_collection(id, CreateCollection) -> Document; update_collection(id, permissions: Vec<Name>, document_security: bool) -> Document; get_collection(id) -> Document;
    list_collections(limit, offset) -> Vec<Document>; size_of_collection(id) -> i64; size_of_collection_on_disk(id) -> i64; analyze_collection(id) -> bool; delete_collection(id) -> bool
    #[derive(Default)] pub struct CreateCollection { pub attributes: Vec<Document>, pub indexes: Vec<Document>, pub permissions: Option<Vec<Name>>, pub document_security: bool /* default true */ }
  database/attributes.rs   create_attribute(c, AttributeSpec) -> bool; create_attributes(c, Vec<AttributeSpec>) -> bool; update_attribute_required(c, id, bool) -> Document; update_attribute_format(c, id, Name) -> Document;
    update_attribute_format_options(c, id, Array) -> Document; update_attribute_filters(c, id, Vec<Name>) -> Document; update_attribute_default(c, id, Option<Value>) -> Document; update_attribute(c, id, UpdateAttribute) -> Document;
    check_attribute(&Document /*collection*/, &Document /*attribute*/) -> bool; delete_attribute(c, id) -> bool; rename_attribute(c, old, new) -> bool
  database/indexes.rs      create_index(c, IndexSpec) -> bool; rename_index(c, old, new) -> bool; delete_index(c, id) -> bool
  database/metadata.rs     (pub(crate)) update_metadata, rollback_attribute_metadata, cleanup_*, with_retries
  database/documents/{mod.rs, get.rs, create.rs, update.rs, delete.rs, bulk.rs, upsert.rs, increase.rs}
    get_document(c, id, &[Query], for_update: bool) -> Document; create_document(c, Document) -> Document; update_document(c, id, Document) -> Document; delete_document(c, id) -> bool;
    create_documents(c, Vec<Document>, batch: usize, on_next: impl FnMut(&Document) + Send, on_error: impl FnMut(Error) -> Result<()> + Send) -> u64;
    update_documents(c, updates: Document, &[Query], batch, on_next: impl FnMut(&Document, &Document) + Send, on_error) -> u64;
    upsert_document(c, Document) -> Document; upsert_documents(c, Vec<Document>, batch, on_next, on_error) -> u64; upsert_documents_with_increase(c, attribute, Vec<Document>, on_next, on_error, batch) -> u64;
    increase_document_attribute(c, id, attribute, by: Number, max: Option<Number>) -> Document; decrease_document_attribute(c, id, attribute, by: Number, min: Option<Number>) -> Document;
    delete_documents(c, &[Query], batch, on_next, on_error) -> u64
  database/find.rs   find(c, &[Query], for_permission: Action) -> Vec<Document>; find_one(c, &[Query]) -> Document; count(c, &[Query], max: Option<i64>) -> i64; sum(c, attribute, &[Query], max: Option<i64>) -> Number;
    iterate(c, Vec<Query>, Action) -> impl Stream<Item = Result<Document>> + Send; foreach<F, Fut>(c, f: F, Vec<Query>, Action) -> Result<()> where F: FnMut(Document) -> Fut + Send, Fut: Future<Output = Result<()>> + Send
  database/plan.rs   pub(crate) fn plan_create/plan_update/plan_delete(&self, c: &Collection, ..) -> Strategy; pub enum Strategy { Single(WritePlan), Transactional }
  database/spatial.rs, database/relationships/{mod.rs, schema.rs, write.rs, delete.rs, populate.rs, queries.rs}
    create_relationship(c, related, CreateRelationship) -> bool; update_relationship(c, id, UpdateRelationship) -> bool; delete_relationship(c, id) -> bool
    pub(crate): create_document_relationships, update_document_relationships, delete_document_relationships, populate_documents_relationships(docs, c, depth, selects), convert_relationship_queries
  mirror/{mod.rs, filter.rs}   pub struct Mirror<S: Adapter, D: Adapter> { .. }  impl { pub fn new(source: Database<S>, destination: Option<Database<D>>, filters: Vec<Arc<dyn mirror::Filter>>) -> Self; source(); destination(); set_write_filters(); on_error(); /* the 53 PHP Mirror methods */ }
    pub trait Filter: Send + Sync { /* Mirroring\Filter hooks: before/after create/update/delete per collection, document and attribute */ }
  typed/{mod.rs, pipeline.rs, relations.rs}
    pub trait Record: Sized + Send { const COLLECTION: &'static str; fn plan(c: &Collection, columns: &[&str]) -> ColumnPlan; fn from_row<R: RowReader>(r: &R, p: &ColumnPlan) -> Result<Self>; fn from_value(v: &Array) -> Result<Self>; fn from_document(d: &Document) -> Result<Self>; }
    impl<A: Adapter + RecordSource> Database<A> { pub async fn get_as<T: Record>(&self, c: &str, id: &str, rel: &[Prefetch]) -> Result<Option<T>>; pub async fn find_as<T: Record>(&self, c: &str, q: &[Query]) -> Result<Vec<T>>;
      pub async fn count_and_find_as<T: Record>(&self, c: &str, q: &[Query], max: Option<i64>) -> Result<(Vec<T>, i64)>; pub async fn create_as<T: Record>(&self, c: &str, d: Document) -> Result<T>;
      pub async fn update_as<T: Record>(&self, c: &str, id: &str, patch: Document, old: Option<&T>) -> Result<Option<T>>; pub fn pipeline(&self) -> Pipeline<'_, A>; }
derive/ (crate utopia-database-derive)   #[proc_macro_derive(Record, attributes(record))]: generates plan/from_row/from_value/from_document
benches/{get.rs, find.rs, write.rs, decode.rs}  (counting global allocator; baselines committed by D17)

=== crates/utopia/cache (S0 freezes the API that database uses; L1 converts the rest) ===
  pub struct Cache; impl Cache { pub fn new(a: Arc<dyn Adapter>) -> Self; pub fn with_codec(self, c: Arc<dyn Codec>) -> Self; pub fn set_case_sensitivity(&mut self, b: bool) -> bool;
    pub async fn load(&self, key: &str, ttl: u64, hash: &str) -> Result<Option<Value>>; pub async fn save(&self, key: &str, data: &Value, hash: &str, ttl: u64) -> Result<SaveResult>;
    pub async fn load_many(&self, key: &str, ttl: u64, fields: &[&str]) -> Result<Vec<(Name, Value)>>; pub async fn save_many(..); pub async fn get_generation(&self, key: &str) -> Result<Name>;
    pub async fn save_with_lease(&self, key: &str, data: &Value, hash: &str, generation: &str) -> Result<bool>; pub async fn touch(..); pub async fn list(&self, key: &str) -> Result<Vec<Name>>;
    pub async fn purge(&self, key: &str, hash: &str) -> Result<bool>; pub async fn flush(&self) -> Result<bool>; pub async fn ping(&self) -> Result<bool>; pub async fn size(&self) -> Result<i64>;
    pub fn batch(&self) -> Batch<'_> /* Feature\Batchable: queue loads, generations, lease saves and purges; one flight per shard */ }
  pub trait Codec: Send + Sync { fn encode(&self, v: &Value) -> Result<Vec<u8>>; fn decode(&self, b: &bytes::Bytes) -> Result<Value>; }  Igbinary, Json;  pub struct Envelope;
  pub trait Adapter: Send + Sync { /* Redis, RedisCluster, Sharding, Memory, None, Filesystem, Memcached, Hazelcast, Pool, CircuitBreaker */ }
  pub mod legacy  (today's API, kept until I1)

=== crates/utopia/query (S0q writes ALL files) ===
src/
  lib.rs; error.rs: pub enum Error { Query(Cow<'static, str>), Validation(..), Unsupported(..), BadMethodCall(..) } with php_class()
  value.rs (pub type Value = php_std::Value); method.rs (pub enum Method { /* 1:1 PHP Method enum */ }); enums.rs (CursorDirection, OrderDirection, NullsPosition, ColumnType from Type.php)
  query.rs  pub struct Query { method: Method, attribute: Name, attribute_type: Option<ColumnType>, on_array: bool, values: SmallVec<[QueryValue; 2]> }  (parse, compile, page, merge, diff, validate, every static constructor)
  quotes.rs (QuotesIdentifiers); compiler.rs (pub trait Compiler)
  builder/mod.rs  pub trait Dialect: Default + Clone + Send + Sync + 'static { const NAME: &'static str; fn quote(&self, id: &str) -> Result<String>; fn placeholder(&self, n: usize, out: &mut String); /* hooks Builder.php leaves to subclasses */ }
    pub struct Builder<D: Dialect> { dialect: D, state: State }
    impl<D: Dialect> Builder<D> { pub fn new() -> Self; pub fn from(&mut self, table: &str, alias: Option<&str>) -> &mut Self; pub fn select(&mut self, cols: &[&str]) -> &mut Self; pub fn filter(&mut self, q: &[Query]) -> &mut Self;
      /* the full Builder.php surface */ pub fn build(&mut self) -> Result<Statement>; pub fn reset(&mut self) -> &mut Self; }
    statement.rs  pub struct Statement { pub query: String, pub bindings: Vec<Value>, pub read_only: bool, pub named_bindings: Option<IndexMap<String, Value>>, executor: Option<Arc<dyn Executor>> }
                  impl Statement { pub fn with_executor(self, e: Arc<dyn Executor>) -> Self; pub async fn execute(&self) -> Result<Value>; }  pub trait Executor: Send + Sync { fn execute<'a>(&'a self, s: &'a Statement) -> BoxFuture<'a, Result<Value>>; }
    binding.rs, condition.rs, case.rs, cte.rs, join.rs, union.rs, window.rs, subselect.rs, lock.rs, parsed.rs, merge.rs
    feature/{mod.rs, aggregates.rs, …}  one trait per PHP Feature\* (Selects, Inserts, Updates, Deletes, Joins, CrossJoins, FullOuterJoins, LateralJoins, Unions, CTEs, Windows, Aggregates, BitwiseAggregates, ConditionalAggregates, StatisticalAggregates, StringAggregates, Json, Spatial, FullTextSearch, NegatedFullTextSearch, Locking, Hints, Hooks, InsertOrIgnore, Upsert, UpsertSelect, Returning, RawSql, Rollup, Cube, Totals, TableSampling, Sequences, Transactions; ClickHouse/*, MariaDB/*, MongoDB/*, PostgreSQL/*)
    core/**  impl blocks porting Trait\* (generic over D where D: the dialect marker that has the feature)
    sql.rs; mysql.rs; mariadb.rs; postgresql.rs; sqlite.rs; clickhouse/{mod.rs, format.rs}; mongodb/{mod.rs, pipeline.rs}; ast.rs (to_ast/from_ast)
    pub type MySql = Builder<dialect::MySql>; MariaDb; PostgreSql; Sqlite; ClickHouse; MongoDb
  hook/{mod.rs, filter.rs (trait Filter + Tenant), attribute.rs (trait Attribute + Map), join.rs (trait JoinFilter, Condition, Placement), write.rs (trait Write)}
  schema/{mod.rs, table.rs, column.rs, index.rs, foreign_key.rs, check.rs, forwarder/**, feature/**, mysql.rs, postgresql.rs, sqlite.rs, clickhouse.rs, mongodb.rs}  pub struct Schema<D: SchemaDialect>
  tokenizer/{mod.rs (Token, TokenType, trait Tokenizer { fn tokenize(&self, sql: &str) -> Result<Vec<Token>> }), mysql.rs, mariadb.rs, postgresql.rs, sqlite.rs, clickhouse.rs}
  ast/{node.rs (enum Statement, Expression, Reference, Literal, …), parser.rs, walker.rs, visitor/{mod.rs (trait Visitor), column_validator.rs, filter_injector.rs, table_renamer.rs}, serializer/{mod.rs (trait Serializer), mysql.rs, mariadb.rs, postgresql.rs, sqlite.rs, clickhouse.rs}}
  classifier/{mod.rs (trait Classifier { fn classify(&self, payload: &[u8]) -> Classification }), sql.rs, mysql.rs, postgresql.rs, mongodb.rs}

=== Compat skeleton (S1) ===
tests/compat/README.md  documents: profiles, requires, isolation:"sequential", recursive cases/<area>/**, expect/<profile>/** sidecars, fixtures/use, codec tags ($document+$class, $query, $operator, $datetime, $object, $array, $steps, $arg, $throw), error fields (code, attribute), strategy and trace runs, replay, ordinal masks, unordered compare, chain coverage ($resolve), capture, new snapshot kinds
tests/compat/{database,query,cache,pools,mongo,appwrite-database}/
  spec.json (php.src, php.tests, pinned php.version, rust.crate, services, state, isolation)
  spec.d/<area>.json with every op and its `covers`, so `bin/compat coverage` maps 100% of symbols from day one
    database areas: profiles (frozen), values, validators-query, validators-schema, core, schema, documents, bulk, relationships, adapter, typed, pool, mirror
    query areas: query, builder-core, builder-sql, builder-clickhouse, builder-mongodb, schema, tokenizer, ast, classifier, hooks, exec
  Adapter.php: reflective dispatcher (`<object>.<snake_method>` → method with named arguments) + require of ops/*.php for special cases
  ops/profiles.php: all 16 PHP profile openers (complete, not stubs)
  cases/<area>/, expect/<profile>/, fixtures/ (empty directories with .gitkeep)
crates/tools/compat/src/libs/database/mod.rs (area dispatch by op prefix, like php_std/mod.rs) + empty area files: codec.rs, values.rs, validators_query.rs, validators_schema.rs, core.rs, schema.rs, documents.rs, bulk.rs, relationships.rs, adapter.rs, typed.rs, mirror.rs, replay.rs, profiles/{mod.rs (complete match → open_<profile>()), postgres.rs, mariadb.rs, sqlite.rs, mongo.rs, memory.rs, redis.rs, pool.rs, mirror.rs}
crates/tools/compat/src/libs/query/mod.rs + empty: query.rs, builder/{core.rs, mysql.rs, mariadb.rs, postgresql.rs, sqlite.rs, clickhouse.rs, mongodb.rs}, schema.rs, tokenizer.rs, ast.rs, classifier.rs, hooks.rs, exec.rs
crates/tools/compat/src/libs/{cache.rs → cache/mod.rs, pools.rs, mongo.rs, appwrite_database.rs} + libs/mod.rs registration
crates/tools/compat/src/bin/compat.rs: subcommands `capture`, `bench` and `overrides` registered, calling stubs in crates/tools/compat/src/{capture/mod.rs, bench.rs, overrides.rs}
tests/compat/database/README.md: ownership table (paths → WP) and profile → owner map

## Appendix B. Work packages

| Package | Owns | Depends on | Size |
|---|---|---|---|
| S0 Architecture skeleton: support crates, utopia-database, pools/mongo/cache API, legacy move, standards | Cargo.toml (workspace deps/entries); crates/support/php-std/src/{lib.rs, types/**} plus signature stubs for json.rs, igbinary.rs, serialize.rs, datetime.rs, sort.rs, pcre.rs, uniqid.rs, pdo.rs; crates/utopia/database/** (every file, signatures only, legacy/** = today's crate moved verbatim, derive/ skeleton, benches/ stubs, DESIGN.md); crates/utopia/pools/** and crates/utopia/mongo/** skeletons; crates/utopia/cache/src/lib.rs public API freeze (Cache/Codec/Adapter signatures + `legacy` module re-exporting today's API); mechanical import rewrites in crates/appwrite/{core,users}; crates/CONVERSION.md (§1 fix, new §8); crates/README.md. After landing: sole editor of every lib.rs/mod.rs/Cargo.toml in these crates. Also runs the Postgres binding spike (OID-0 text params, binary→PDO-text equivalence on the type matrix) and records the users-compare.sh and criterion baselines. | none. Lands in two PRs: php-std types + workspace on about day 3, which unblocks P1–P3; the rest by the end of week 2. | L (2 weeks, lead architect) |
| S0q utopia-query skeleton | crates/utopia/query/** (every file with signatures: Method enum complete, Query, Statement/Executor, Dialect, Builder<D>, every feature trait, hook traits, Schema, Token/TokenType, AST node enums, Serializer/Visitor/Classifier traits), crates/utopia/query/DESIGN.md | S0 php-std types PR (Value/Str) | M (1.5 weeks) |
| S1 Compat format and per-area skeletons | tests/compat/README.md; tests/compat/database/README.md (ownership table); tests/compat/{database,query,cache,pools,mongo,appwrite-database}/{spec.json, spec.d/*.json with every op + covers, Adapter.php reflective dispatcher, ops/profiles.php (all 16 PHP profile openers), cases/, expect/, fixtures/ dirs}; crates/tools/compat/src/libs/mod.rs; libs/{database,query}/mod.rs and every empty area file; libs/database/profiles/mod.rs; libs/{cache/mod.rs,pools.rs,mongo.rs,appwrite_database.rs} stubs; stub registration of capture/bench/overrides subcommands (crates/tools/compat/src/{capture/mod.rs,bench.rs,overrides.rs} as empty stubs) | none (parallel with S0; reads the PHP inventory only) | M (1.5–2 weeks, compat lead) |
| C1 Runner, drivers and snapshot kinds | crates/tools/compat/src/runner/** (profiles, requires, sequential same-ns isolation, recursive case loading, expect sidecars, fixtures/use, ordinal masks, unordered compare, strategy and trace runs, -j/--profile/--area, profile_covers, chain coverage $resolve), runner/snapshot/{mod,redis,files,postgres,mariadb,mysql,sqlite,mongodb,clickhouse}.rs (redis gains igbinary/json decoded views), crates/tools/compat/src/{adapter.rs (step interpreter, codec tags, error fields), driver.rs, bin/**, bench.rs, overrides.rs}, crates/tools/compat/tests/replay.rs, tests/compat/php/** (Codec tags, Session step interpreter, Inventory trait resolution, driver), tests/compat/compat.json, docker-compose compat profile (mysql 8.0.43, mariadb-mirror 10.11, pg16+pgvector/postgis/pg_trgm, query E2E services), bin/compat* | S1 (format frozen); P3 for the igbinary view (can land later) | L (2–3 weeks, 2 engineers; profiles + postgres snapshot in week 1) |
| C2 Capture, adapter traces and replay | tests/compat/capture/** (reflection-generated RecordingDatabase, RecordingMirror, RecordingAuthorization, RecordingPool (adapter traces via public delegate), Recording Builder/Schema per query dialect, tokenizer/parser/classifier shims, PHPUnit bootstrap + test rewriter), crates/tools/compat/src/capture/**, crates/tools/compat/src/libs/database/replay.rs (ReplayAdapter), tests/compat/database/fixtures/signatures.php (filter-signature dump); first generated drafts of every cases/** file and initial PHP expect sidecars, handed to the owning WPs in one PR | S0 (Adapter trait frozen), S1 (op vocabulary); C1 to run, not to generate | L (3 weeks; query builder capture first, then database e2e + traces) |
| P1 php-std value model and JSON | crates/support/php-std/src/{types/** bodies, json.rs, number.rs completion, value.rs view helpers}; tests/compat/php-std/{ops/{json,types}.php, spec.d/{json,types}.json, cases/{json,types}*.json}; crates/tools/compat/src/libs/php_std/{json.rs, types.rs} | S0 php-std types PR. Milestone: Array/Str/Value usable at week 1, so D1 can start. | M (3 weeks) |
| P2 php-std datetime | crates/support/php-std/src/datetime.rs + datetime/**; tests/compat/php-std/{ops/datetime.php, spec.d/datetime.json, cases/datetime*.json}; libs/php_std/datetime.rs | S0 php-std types PR | L (4 weeks) |
| P3 php-std igbinary, serialize, pcre, sort, uniqid, pdo | crates/support/php-std/src/{igbinary.rs, serialize.rs, pcre.rs, sort.rs, uniqid.rs, pdo.rs}; matching tests/compat/php-std/{ops,spec.d,cases} files; libs/php_std/{serialize.rs, pcre.rs, sort.rs, uniqid.rs, pdo.rs, igbinary.rs} | S0 php-std types PR (P1 types bodies for igbinary encode) | L (4 weeks; igbinary + pdo quoting first) |
| L1 utopia-cache full conversion | crates/utopia/cache/** (except the frozen public signatures in lib.rs); tests/compat/cache/** (all 13 PHP test classes + E2E); crates/tools/compat/src/libs/cache/** | S0; P3 (igbinary) for the Igbinary codec and Envelope interop. Milestone at week 4: Redis + Memory + None adapters, leases, Batchable, so D4 can integrate. | L (4 weeks) |
| L2 utopia-pools | crates/utopia/pools/**, tests/compat/pools/**, crates/tools/compat/src/libs/pools.rs | S0, S1 | S (1.5 weeks) |
| L3 utopia-mongo | crates/utopia/mongo/**, tests/compat/mongo/** (AuthTest, ClientTest, MongoTest, TransactionTest, UnsentErrorTest), crates/tools/compat/src/libs/mongo.rs | S0, S1 | L (3 weeks) |
| D1 Values, Document, Query, Operator, helpers, Authorization, schema views | crates/utopia/database/src/{value.rs, document.rs, change.rs, datetime.rs, connection.rs, query/**, operator.rs, helpers/**, authorization.rs, schema/**, error.rs bodies}; libs/database/{codec.rs, values.rs}; spec.d/values.json; cases/unit/{DocumentTest, QueryTest, OperatorTest, IDTest, RoleTest, PermissionTest, EmptyDocumentTypeTest, Validator/AuthorizationTest}.json; cases/fuzz/values*.json | S0, S1, P1 (milestone), P2 for DateTime parsing. Milestone M1 at week 3: Value, Document, Query (parse, constructors, group_by_type), Authorization, Collection views. | L (3 weeks) |
| D2 Query validators | crates/utopia/database/src/validator/{queries.rs, queries/**, query/**, indexed_queries.rs, operator.rs}; libs/database/validators_query.rs; spec.d/validators-query.json; cases/unit/Validator/{QueriesTest, DocumentQueriesTest, DocumentsQueriesTest, IndexedQueriesTest, QueryTest, OperatorTest, Query/*}.json; query fuzz profiles | D1 M1 | L (3 weeks) |
| D3 Schema and structure validators | crates/utopia/database/src/validator/{structure.rs, partial_structure.rs, attribute.rs, index.rs, index_dependency.rs, key.rs, uid.rs, label.rs, permissions.rs, roles.rs, datetime.rs, bigint.rs, byte_length.rs, sequence.rs, spatial.rs, vector.rs, object.rs, authorization_input.rs}; libs/database/validators_schema.rs; spec.d/validators-schema.json; cases/unit/Validator/{AttributeTest, IndexTest, StructureTest, KeyTest, UIDTest, LabelTest, PermissionsTest, RolesTest, DateTimeTest, SpatialTest, VectorTest, ObjectTest}.json; structure/attribute fuzz | D1 M1, P2 | L (4 weeks) |
| D4 Orchestration core: config, views, tenancy, filters, codec, cache, events, transactions | crates/utopia/database/src/database/{mod.rs bodies, config.rs, scope.rs, tenancy.rs, codec.rs, cache.rs, convert.rs, transaction.rs}, src/filter/**, src/event.rs; libs/database/core.rs; spec.d/core.json; cases/unit/{CacheKeyTest, FilterRegistryTest, QueryCacheTest, WithCacheLeaseTest, ForUpdateCacheTest, TransactionRetryTest, SelectProjectionTest, SpatialFilterTest}.json; cases/scopes/GeneralTests/**; cases/interop/{cache, query-cache, signatures}.json | D1 M1, D2, L1 milestone, P3; converges through C2 replay and the D14 M3 memory backend | XL (5 weeks, 2 engineers) |
| D5 Schema orchestration: collections, attributes, indexes, metadata | crates/utopia/database/src/database/{collections.rs, attributes.rs, indexes.rs, metadata.rs}; libs/database/schema.rs; spec.d/schema.json; cases/scopes/{CollectionTests, AttributeTests, IndexTests, ObjectAttributeTests}/**; cases/unit/CreateCollectionRaceTest.json; cases/interop/metadata.json | D3, D4 (config/cache); replay first, then D14 M3 / D10 | L (4 weeks) |
| D6 Documents, find and the write-strategy planner | crates/utopia/database/src/database/{documents/{mod.rs, get.rs, create.rs, update.rs, delete.rs}, find.rs, plan.rs, spatial.rs}; libs/database/documents.rs; spec.d/documents.json; cases/scopes/{DocumentTests, PermissionTests, SpatialTests, VectorTests, CustomDocumentTypeTests}/**; cases/interop/documents.json; differential find fuzz | D2, D4; replay first, then D14 M3 and D10 for strategies | XL (5 weeks, 2 engineers) |
| D7 Bulk, upsert, increase, operators | crates/utopia/database/src/database/documents/{bulk.rs, upsert.rs, increase.rs}; libs/database/bulk.rs; spec.d/bulk.json; cases/scopes/OperatorTests/** | D6 (single-document path), D2 (operator validator) | L (3 weeks) |
| D8 Relationships | crates/utopia/database/src/database/relationships/**; libs/database/relationships.rs; spec.d/relationships.json; cases/scopes/{RelationshipTests, Relationships/OneToOneTests, OneToManyTests, ManyToOneTests, ManyToManyTests}/** | D5, D6; relationship DDL from D10/D11 | XL (5 weeks) |
| D9 SQL core (SQL.php): writer, binds, exec, transforms, shared DDL/DML, row reading | crates/utopia/database/src/adapter/sql/** (bodies of mod.rs, dialect.rs defaults, driver.rs, writer.rs, bind.rs, exec.rs, conditions.rs, permissions.rs, projection.rs, cursor.rs, ddl.rs, dml.rs, upsert.rs, operators.rs, spatial.rs, casting.rs), src/adapter/row.rs; libs/database/adapter.rs; spec.d/adapter.json; cases/unit/{SQLGetDocumentTest, SQLTransactionTest, UniqueViolationTest, PDOTest, PDOStatementTest}.json | S0 (binding spike result), D1 M1, P3 (pdo). Milestone M2 at week 4: get, find, create, update and delete paths, which D10, D11 and D12 build on. | XL (5 weeks, 2 engineers) |
| D10 PostgreSQL dialect, driver and fused strategies | crates/utopia/database/src/adapter/postgres/**; libs/database/profiles/postgres.rs; cases/adapters/{PostgresTest, SharedTables/PostgresTest}.json; expect/{postgres, postgres-shared}/** | D9 M2; D6 for the fused-strategy convergence | XL (5 weeks) |
| D11 MariaDB and MySQL | crates/utopia/database/src/adapter/{mariadb/**, mysql.rs}; libs/database/profiles/mariadb.rs (both); cases/adapters/{MariaDBTest, MySQLTest, SharedTables/MariaDBTest, SharedTables/MySQLTest}.json; expect/{mariadb, mariadb-shared, mysql, mysql-shared}/** | D9 M2 (Dialect frozen in S0, so DDL, types and conditions can start earlier), P3 (quote_mysql) | L (4 weeks) |
| D12 SQLite | crates/utopia/database/src/adapter/sqlite/**; libs/database/profiles/sqlite.rs; cases/adapters/{SQLiteTest, SharedTables/SQLiteTest}.json; expect/{sqlite, sqlite-shared}/** | D9 M2, D11 (MariaDB dialect it wraps) | L (4 weeks) |
| D13 MongoDB adapter and schemaless | crates/utopia/database/src/adapter/mongo/**; libs/database/profiles/mongo.rs; cases/adapters/{MongoDBTest, SharedTables/MongoDBTest, Schemaless/MongoDBTest}.json; cases/scopes/SchemalessTests/**; cases/unit/MongoPermissionStringsTest.json; expect/{mongodb, mongodb-shared, mongodb-schemaless}/** | L3, D1 M1; D6 to converge | XL (5 weeks) |
| D14 Memory adapter and shared evaluator | crates/utopia/database/src/adapter/{memory/**, eval/**}; libs/database/profiles/memory.rs; cases/adapters/MemoryTest.json; expect/memory/** | D1 M1, P3 (sort). Milestone M3 at week 4: CRUD and find, giving D4–D8 a service-free real backend. | L (4 weeks) |
| D15 Redis database adapter | crates/utopia/database/src/adapter/redis/**; libs/database/profiles/redis.rs; cases/adapters/{RedisTest, SharedTables/RedisTest}.json; expect/{redis, redis-shared}/** | D14 (eval), P1 (json) | L (4 weeks) |
| D16 Pool adapter, Mirror and Mirroring Filter | crates/utopia/database/src/adapter/pool.rs, src/mirror/**; libs/database/{mirror.rs, profiles/pool.rs, profiles/mirror.rs}; spec.d/{pool,mirror}.json; cases/adapters/{PoolTest, MirrorTest}.json; cases/unit/PoolTimeoutTest.json; expect/{pool, mirror}/** | L2, D6, D11 (MySQL for pool, MariaDB for mirror) | M (3 weeks) |
| D17 Typed tier, derive, benchmarks and parity ops | crates/utopia/database/src/typed/**, crates/utopia/database/derive/**, crates/utopia/database/benches/** (+ committed baselines), crates/utopia/database/tests/tiers.rs; libs/database/typed.rs; spec.d/typed.json; cases/typed/** | D6, D10 (RecordSource for Postgres), D4 | L (4 weeks) |
| I1 Appwrite integration, hot-path migration and legacy removal | crates/appwrite/** (Filter impls for encrypt/userSearch/subQuery* with prefetch, Records via derive, removal of hand-written SQL, error mapping, cursor API, project cache moved to shared igbinary entries without `rust:project:v1`), deletion of crates/utopia/database/src/legacy/** and utopia-cache legacy module, tests/compat/appwrite-database/** + libs/appwrite_database.rs, image build step dumping filter signatures (app/config/database/signatures.json + script), tests/benchmarks/**, tests/compat/STATUS.md, rfc/rust.md §3/§4 updates | D17, D10, D4, L1; can start migrating endpoint by endpoint at week 10; each endpoint moves only behind the users-compare gate | L (4–5 weeks) |
| Q1 Query value object, Method, exceptions, quotes, compiler | crates/utopia/query/src/{value.rs, query.rs, method.rs, enums.rs, error.rs, quotes.rs, compiler.rs}; libs/query/query.rs; tests/compat/query/spec.d/query.json; cases/{QueryTest, MethodTest, QuotesIdentifiersTest, API/*, Exception/*}.json; Query JSON fuzz | S0q, P1 | M (3 weeks) |
| Q2 Builder core, SQL base, feature traits, hooks | crates/utopia/query/src/builder/{mod.rs, statement.rs, binding.rs, condition.rs, case.rs, cte.rs, join.rs, union.rs, window.rs, subselect.rs, lock.rs, parsed.rs, merge.rs, sql.rs, feature/* (generic), core/* (generic)}, src/hook/**; libs/query/builder/core.rs; spec.d/{builder-core,hooks}.json; cases/{Builder/ConditionTest, Builder/CursorTest, Builder/EmptyInputTest, Hook/**, Regression/**}.json | S0q, Q1. Milestone M4 at week 3: build() for selects and filters. | XL (5 weeks) |
| Q3 MySQL, MariaDB, PostgreSQL and SQLite builders | crates/utopia/query/src/builder/{mysql.rs, mariadb.rs, postgresql.rs, sqlite.rs, feature/{mariadb,postgresql}/**, core/{mariadb,postgresql}/**}; libs/query/builder/{mysql,mariadb,postgresql,sqlite}.rs; spec.d/builder-sql.json; cases/Builder/{MySQLTest, MariaDBTest, PostgreSQLTest, SQLiteTest}/**, cases/Builder/Feature/{MariaDB,PostgreSQL}/** | Q2 M4 | XL (5 weeks, 2 engineers) |
| Q4 ClickHouse builder | crates/utopia/query/src/builder/{clickhouse/**, feature/clickhouse/**, core/clickhouse/**}; libs/query/builder/clickhouse.rs; spec.d/builder-clickhouse.json; cases/Builder/ClickHouseTest/**, cases/Builder/Feature/ClickHouse/** | Q2 M4 | L (4 weeks) |
| Q5 MongoDB builder | crates/utopia/query/src/builder/{mongodb/**, feature/mongodb/**, core/mongodb/**}; libs/query/builder/mongodb.rs; spec.d/builder-mongodb.json; cases/Builder/MongoDBTest/**, cases/Builder/Feature/MongoDB/**, cases/MongoDBClientObjectToArrayTest.json | Q2 M4, P1 (json fidelity) | L (4 weeks) |
| Q6 Schema builders | crates/utopia/query/src/schema/**; libs/query/schema.rs; spec.d/schema.json; cases/Schema/** | S0q, Q1 (no Builder dependency; starts day 1 of wave 1) | L (4 weeks) |
| Q7 Tokenizer, AST and Classifier | crates/utopia/query/src/{tokenizer/**, ast/**, classifier/**, builder/ast.rs}; libs/query/{tokenizer.rs, ast.rs, classifier.rs}; spec.d/{tokenizer,ast,classifier}.json; cases/{Tokenizer,AST,Classifier}/**; parse/serialize round-trip fuzz | S0q (frozen AST enums); builder/ast.rs after Q2 M4 | L (5 weeks) |
| Q8 Query E2E against live engines | tests/compat/query/cases/E2E/**, crates/tools/compat/src/libs/query/exec.rs, spec.d/exec.json, expect sidecars for the query E2E profiles | C1 (clickhouse/mysql/mariadb services and snapshots), Q3, Q4, Q5, Q6 | M (3 weeks) |
