# compat: PHP ↔ Rust compatibility testing

`bin/compat` proves that a Rust Utopia crate behaves exactly like its PHP library. It sends the same operations to a PHP driver and a Rust driver and compares everything they report, byte for byte: return values (with types), exceptions (class and message), and the state each leaves in shared services. It also fuzzes both with generated inputs and measures how much of the PHP API is covered.

The standard it enforces is [crates/CONVERSION.md](../../crates/CONVERSION.md). Progress per library: [STATUS.md](STATUS.md).

## How it works

```
                   tests/compat/<lib>/spec.json + cases/*.json
                                    │
                              bin/compat (runner)
                     ┌──────────────┴──────────────┐
          bin/compat-php (PHP driver)        compat-driver (Rust driver)
     tests/compat/<lib>/Adapter.php      crates/tools/compat/src/libs/<lib>.rs
              utopia-php/<lib>                 crates/utopia/<lib>
                     └──────────────┬──────────────┘
                    Redis, PostgreSQL… (snapshotted per namespace)
```

- **Operations** are a neutral vocabulary per library (`lock.try_acquire`, `validate`, ...) declared in `spec.json`. Each runtime has an **adapter** mapping them onto its library: thin glue with no logic.
- **Drivers** speak JSON lines on stdin/stdout. The PHP driver runs in a throwaway container of the dev image (`appwrite-dev`) with this checkout's `tests/` and `packages/` mounted, so it works from any git worktree; the Rust driver is a binary of the `compat` crate.
- **Values** cross as JSON. PHP decodes them like `Utopia\Http\Request` (objects are associative arrays, except empty ones, which stay `stdClass`); `{"$bytes": "<base64>"}` carries binary strings and `{"$float": "INF"}` non-finite floats. Integers and floats stay distinct (`1` ≠ `1.0`), and object key order matters.
- **Errors** are `{"$error": {"class": "<PHP exception class>", "message": "..."}}`; a message that is not UTF-8 (one that quotes the input) is `{"$bytes": "<base64>"}` (`Outcome::err_bytes()`). Rust adapters report the PHP class of each error (every crate's error enum has `php_class()`).
- **Namespaces** isolate runs: each case gets a fresh `${ns}` per runtime. Everything an operation creates (keys, tables, files) must contain it, so the runner can clean up and snapshot it. Results have the namespace replaced back with `${ns}` before comparing.

## Commands

```bash
bin/compat run [<lib>...] [--case <text>] [--fail-fast] [-v]   # every case on both runtimes
bin/compat fuzz <lib> [--op <op>] [--iterations <n>] [--seed <n>] [--save]
bin/compat record <lib>          # store PHP's results as `expect` in the case files
bin/compat coverage <lib>        # API coverage, adapter parity, ported tests
bin/compat check <lib>           # coverage + run + fuzz: the conversion gate
bin/compat ci                    # run + fuzz every library; check the complete ones
bin/compat status --write        # regenerate STATUS.md
bin/compat call <lib> <op> '<args json>' [--side php|rust]     # one call, both results
bin/compat new <lib>             # scaffold tests/compat/<lib>
bin/compat report [<lib>...] [--iterations <n>] [--out <dir>]   # data for apps/compat, the conversion report
bin/compat crates [--out <dir>]  # data for apps/crates, the docs of every library
bin/compat examples [<crate dir>...]  # run every doc example: Rust doctests and their PHP twins
bin/compat sync <lib>... [--force]  # after `run <lib>` passes, record the PHP commit the crate matches
bin/compat bench <lib>... [--write]  # time each library's benchmarks on PHP and on a release build of Rust
```

`bin/compat report` runs every case and fuzz profile (200 inputs each by default) with both runtimes' results kept, compares every public PHP method's signature with its Rust counterpart's, maps each PHP symbol to the Rust items whose docs name it, and writes the JSON [`apps/compat`](../../apps/compat) renders. `bin/compat crates` writes the documentation of every Utopia library, converted or not, for [`apps/crates`](../../apps/crates), reading PHP signatures through the PHP driver. `bin/compat examples` runs every example in the crates' docs: the Rust doctests and the PHP twin written after each one (see [`apps/crates/README.md`](../../apps/crates/README.md#writing-examples)).

`cargo test -p compat` replays every recorded expectation against Rust without PHP (cases needing Redis or a database only with `COMPAT_SERVICES=1`), so the workspace test run guards compatibility too. Which cases need services: those that snapshot state (`state`), and, once any operation of the spec declares the services it needs (`"services": [...]` on the op), those with a step of such an operation; a spec whose operations declare none keeps the older rule (every case of a spec listing `services` needs them).

Environment: `COMPAT_PHP` / `COMPAT_RUST` override the driver commands, `COMPAT_TIMEOUT` the seconds to wait for a reply (60), `COMPAT_SEED` the fuzz seed, `COMPAT_FUZZ_DUMP` a file to append every generated fuzz input to (JSON lines, to replay a driver crash input by input), `COMPAT_IMAGE` / `COMPAT_NETWORK` the PHP driver's image and network. Service endpoints for each runtime and for the runner are in `compat.json` (`${VAR}` reads the environment, then `.env`): `php` on the dev stack's network, `rust` and `runner` through the host's forwarded ports, and optionally `rust-container`, where a Rust driver in a container on the network reaches the service (`compat bench`; see [Benchmarks](#benchmarks)).

The dev stack must be running (`docker compose up -d`) for the PHP driver image and the services.

A library that needs services beyond the dev stack's Redis and PostgreSQL ships `tests/compat/<lib>/services.sh up|down`, which starts them as throwaway containers on the dev stack's network (named `compat-<lib>-*`, removed when stopped) and waits until they answer. CI runs every library's script before `bin/compat ci`. Cache's also builds `compat-cache-php`, the dev image plus the Memcached extension, which CI runs the PHP driver in:

```bash
for s in tests/compat/*/services.sh; do "$s" up; done
COMPAT_IMAGE=compat-cache-php bin/compat ci
for s in tests/compat/*/services.sh; do "$s" down; done
```

## A library's files

| File | Holds |
|---|---|
| `tests/compat/<lib>/spec.json` | operations, the PHP symbols each covers, fuzz generators, waivers, quirks, services, state |
| `tests/compat/<lib>/Adapter.php` | PHP adapter: `operations()` returns `name => fn (array $args, Session $s)` |
| `tests/compat/<lib>/cases/<PhpTest>.json` | cases ported from one PHP test class (`source` names it) |
| `tests/compat/<lib>/cases/interop.json` | one runtime writes, the other reads |
| `tests/compat/<lib>/cases/fuzz.json` | regressions saved by `fuzz --save` |
| `crates/tools/compat/src/libs/<lib>.rs` | Rust adapter: `OPS` and `call(op, args, session)` |

### spec.json

```json
{
  "lib": "lock",
  "complete": false,
  "php": { "src": ["packages/lock/src"], "tests": ["packages/lock/tests"] },
  "rust": { "crate": "crates/utopia/lock" },
  "services": ["redis"],
  "state": ["redis"],
  "state_mask": { "redis": { "paths": ["/*/value"], "reason": "lease tokens are random" } },
  "ops": {
    "lock.try_acquire": { "doc": "$lock->tryAcquire()", "covers": ["Utopia\\Lock\\Distributed::tryAcquire"] },
    "validate": {
      "covers": ["Utopia\\Validator\\Text::*"],
      "fuzz": [{ "name": "text", "args": { "gen": "object", "fields": { "value": { "gen": "ref", "name": "scalar" } } }, "iterations": 5000 }],
      "mask": { "paths": ["/id"], "reason": "random" }
    }
  },
  "generators": { "scalar": { "gen": "one_of", "of": [{ "gen": "string", "tricky": 0.5 }, { "gen": "int" }] } },
  "waivers": { "Utopia\\Validator\\PHPStan\\*": "PHPStan extension, no runtime behaviour" },
  "tests_waived": {},
  "quirks": [{ "symbol": "...", "description": "...", "case": "Text.json#..." }]
}
```

A large spec can be split: `spec.d/*.json` files hold more `ops`, `generators`, `waivers`, `tests_waived`, `quirks`, `deviations` and `scrub` (one file per area or owner), merged into `spec.json`; a name defined twice is an error.

`scrub` replaces values a library generates nondeterministically in every result and snapshot, before comparing and recording (bound results keep the raw values, so later steps use the real ids). Each entry has a `kind` and a `reason` (`crates/tools/compat/src/runner/scrub.rs`):

| Kind | Replaces | With |
|---|---|---|
| `now` | a datetime string within `seconds` (default 3600) of the runner's clock, as `Y-m-d\TH:i:s.vP`, `Y-m-d H:i:s.v` or `Y-m-d H:i:s` (zone optional) | `<now>` |
| `uniqid` | a token of 13 to 36 lowercase hex digits whose first 8 are a unix time within a day of the clock (`uniqid()` plus padding, as `ID::unique()` makes), alone or delimited inside a string | `<uniqid>` |
| `long` | a string over 64 KiB, kept out of the case files | `<long:<bytes>:<sha256>>` |

PHP test files to port (`php.tests`) are the `*Test.php` classes and the `*Tests.php` traits test classes are assembled from (utopia-php/database's e2e scopes hold the test bodies). Directories that are not on the host (`vendor/`, which only the dev image has) are listed by the PHP driver (`$tests`).

A fuzz profile with `"isolate": true` runs in fresh PHP and Rust driver processes, for functions with process-wide state that other operations touch on one side only (PHP's PCRE cache and JIT switch, which engine functions like `filter_var` also fill). Each library's `run`, `fuzz` and `record` also starts fresh drivers, so a library never inherits another's process-wide state: after `php-std`'s fuzzing has switched the PCRE JIT off, `user-agent`'s backtracking cases would run interpreted, much slower. A library behaves in `ci` exactly as it does alone.

`covers` and waiver patterns are exact symbols, `Class::*` or `Namespace\*`. Generators are documented in `crates/tools/compat/src/runner/generate.rs`.

### Cases

```json
{
  "source": "packages/lock/tests/MutexTest.php",
  "cases": [
    { "name": "short form", "op": "semaphore.new", "args": { "permits": 0 } },
    { "name": "steps", "steps": [
      { "op": "mutex.new", "bind": "m" },
      { "op": "lock.acquire", "args": { "lock": { "$ref": "m" } } },
      { "op": "lock.try_acquire", "args": { "lock": { "$ref": "m" } } }
    ]},
    { "name": "interop", "interop": true, "steps": [
      { "side": "a", "op": "distributed.new", "args": { "key": "${ns}:k" }, "bind": "a" },
      { "side": "b", "op": "distributed.new", "args": { "key": "${ns}:k" }, "bind": "b" }
    ]}
  ]
}
```

- `bind` names a step's result; `{"$ref": "name", "path": "/json/pointer"}` uses it in later arguments (each runtime its own value, so handles work).
- `${ns}` in any string is the run's namespace.
- `$equal` (`{"a": ..., "b": ...}`) is a built-in operation comparing two values, e.g. two bound tokens.
- `mask` on a step, with a `reason`, hides values that legitimately differ between runs.
- `state` on a case overrides the spec's snapshot kinds (`[]` for none).
- Interop cases run twice, with sides `a`/`b` as PHP/Rust and then Rust/PHP on one shared namespace; both directions must agree with each other and with `expect`.
- `expect` is written by `bin/compat record`, never by hand.

## Adding a library

1. `bin/compat new <lib>`, then read the PHP source and tests end to end.
2. `bin/compat coverage <lib>` lists every public symbol. Design operations that exercise them and add them to `spec.json` with `covers`.
3. Implement them in `Adapter.php` and `src/libs/<lib>.rs`, port every PHP test class to a case file, add interop cases for shared formats and fuzz profiles for pure operations.
4. Converge: `bin/compat run`, `fuzz`, `coverage`. A difference is a Rust bug, a PHP quirk to keep and record, or a missing case. Never loosen a comparison to make it pass.
5. `bin/compat record <lib>`, set `"complete": true` once `bin/compat check <lib>` passes, and `bin/compat status --write`.

## Benchmarks

A converted library's hot paths are benchmarked against PHP ([CONVERSION.md](../../crates/CONVERSION.md) §2 rule 7). Each benchmark is a name both adapters declare: `BENCHES` and `bench(name, session)` in `crates/tools/compat/src/libs/<lib>.rs` (listed in `libs::benches` and `libs::bench`), and `benchmarks()` in `Adapter.php` (implementing `Tests\Compat\Benchmarks`). It is a setup that returns the closure to time, so only the library call is measured, not argument decoding:

```php
'parse' => ['Parse a Redis connection string', fn (Session $s) => fn () => new DSN(self::DSN)],
```

```rust
"parse" => Bench::sync(|| { black_box(Dsn::parse(black_box(DSN)).ok()); }),
```

Both drivers time a benchmark the same way (`crates/tools/compat/src/bench.rs`, `php/Bench.php`): warm up, size samples to about 20 ms, take 15, report the median time per call with the fastest and slowest sample. The Rust driver is built in release mode for this and counts heap allocations per call; PHP runs as the dev image configures it (opcache and the tracing JIT). Pick what a request does many times: parsing, validation, a cache read, a route match. Benchmark async or networked work with `Bench::future` against the same services the cases use.

Both drivers run in Linux containers on the dev stack's network, so they share a kernel, a network and its round trips: PHP through `bin/compat-php`, Rust through `bin/compat-rust`, which builds a release `compat-driver` in a container of Dockerfile.rust's toolchain (`tests/compat/rust.Dockerfile`, same `RUST_VERSION`; `COMPAT_RUST_VERSION` overrides it) and runs it with the checkout at `/usr/src/code` and `/tmp/compat-fs` mounted, as PHP has them. The cargo registry (`compat-rust-registry`) and the target dir (one `compat-rust-target-<hash>` volume per checkout) live in Docker volumes, so rebuilds are incremental; `docker volume rm` them to start over. In a container the Rust driver reaches each service at its `rust-container` address in `compat.json`, or else at PHP's host and port with Rust's own database number (`redis://localhost:9513/6` becomes `redis://redis:6379/6`). Timing Rust natively on a macOS host instead adds Docker Desktop's port forwarding to every service round trip (100 to 200 µs; a Redis lock took 270 µs on the host against 97 µs in a container and 95 µs in PHP), and a costlier `malloc`/`free` (about 36 ns a pair, against 16 ns for glibc in the container). `--host` (or `COMPAT_RUST`) still builds and runs Rust on the host, to profile it there; `run`, `fuzz` and `ci` always do.

`bin/compat bench <lib> --write` stores the results with the commit, machine, runtime versions, and where each side ran (image, OS and kernel) in `tests/compat/<lib>/bench.json`, which the docs site shows in migration mode. Run it on a quiet machine, one library at a time, never alongside other benchmarks or builds: timings from different machines are not comparable, so a file always holds one run of both runtimes. The command fails when a benchmark is slower in Rust, or exists on one side only. A benchmark bound by work both runtimes wait on equally (a service round trip, an algorithm whose cost is fixed by its parameters) is listed with its reason under `bench.bound` in `spec.json` and must reach parity (0.95x) instead:

```json
"bench": { "bound": { "redis_load": "A Redis round trip, the same on both runtimes, dominates the call" } }
```

A benchmark of a PHP-compatibility codec that no Rust hot path uses (PHP's `serialize` or igbinary formats, kept for data shared with PHP, while Rust's own caches use the native codec) is listed with its reason under `bench.compat`. It still runs, is printed with its reason and published with a `compat` label, but never fails the command, and the docs site leaves it out of the library's average speedup:

```json
"bench": { "compat": { "igbinary_unserialize": "PHP's igbinary format, kept for entries shared with PHP; Rust's own caches use the native codec" } }
```

## Snapshots

The runner snapshots shared state itself after each case, per namespace, for the kinds in `state` (see `crates/tools/compat/src/runner/snapshot.rs`):

| Kind | Captures |
|---|---|
| `redis` | every key containing the namespace: type, value, whether it expires |
| `files` | every file under `/tmp/compat-fs/${ns}/` (mounted into the PHP driver's container at the same path): relative path and content |
| `redis@<service>[/<db>,...]` | like `redis`, on another Redis service of `compat.json` |
| `postgres@<service>` | the PostgreSQL schema named `${ns}` on a service of `compat.json` (its `runner` URL): whether it exists, and per table (sorted) its columns in order (name, type with length or precision, nullability, default, identity, generated), indexes (`pg_get_indexdef`), constraints (`pg_get_constraintdef`) and rows (ordered by `_id`, then every column's text; every value as `::text`). Cleanup drops the schema and every `${ns}_<name>` schema (`DROP SCHEMA ... CASCADE`) |

A library that writes to another service adds a kind there (PostgreSQL tables, MongoDB collections, files), touching only resources whose names contain the namespace.

## database

utopia-php/database is converted area by area (`tests/compat/database/ops/<area>.php`, `crates/tools/compat/src/libs/database/<area>.rs` (`core/`, split by the `Database` area that implements each operation: `schema.rs`, `documents.rs`, `relationships.rs`, sharing `args.rs`), `spec.d/<area>.json`). Values cross through `Values.php` and `codec.rs`.

### The `core` vocabulary

Area `core` drives `Utopia\Database\Database` itself, on PostgreSQL service `database-postgres` (`tests/compat/database/services.sh up`). A case opens a database first; each runtime's database lives in schema `${ns}`, which the `postgres@database-postgres` snapshot compares. The cache is `Utopia\Cache\Adapter\Memory` by default (or `None`), private to each runtime. The Redis caches go through `Utopia\Cache\Adapter\Redis` with Json entries (`-igbinary` kinds: igbinary ones) and keep per-projection (hash) entries and generation leases: `redis` (services `database-redis`, a database per runtime) and `redis-mirror` (`database-redis-mirror`, a Mirror's destination) are private and flushed when a database of their runtime opens; `redis-shared` (`database-redis-shared`) is one both runtimes read and write, which `interop.json` drives in both directions. The Redis services are `compat-database-redis` (`services.sh up`).

| Operation | Arguments | Result |
|---|---|---|
| `db.open` | `namespace` (`"compat"`), `database` (`${ns}`), `shared_tables`, `tenant`, `tenant_per_document`, `cache` (`"memory"`, `"none"`, `"redis"`, `"redis-mirror"`, `"redis-shared"`, each Redis kind also `-igbinary`), `create` (true), `pool` (`{"size": n}`: an `Adapter\Pool` over a `Utopia\Pools\Pool` of PostgreSQL connections, `{"unreachable": true}` one whose connections are refused, `{"of": <handle>}` another Pool database's pool), `redis` (true: an `Adapter\Redis` on service `database-redis-adapter` instead of PostgreSQL), `mirror` (`{"destination"?: false, "filters"?: ["log"], "cache"?}`: a `Mirror` whose source is this adapter and whose destination is a PostgreSQL database on `database-postgres-mirror`), `roles` (`["any"]`), `adapter` (a database handle: a peer on that database's adapter, not made current), `filters` (instance filters: name => filter fixture name) | a handle; the database becomes the session's current one. Registers every filter fixture (below), then builds `new Database(new Postgres(PDO), Cache, filters)` with its own `Authorization` holding `roles`, then `setDatabase`, `setSharedTables`, `setTenant`, `setTenantPerDocument`, `setNamespace` and `create()` |
| `db.select` | `db` | `true`; makes `db` current |
| `database.<method>` | the method's parameters by name; `db` picks a database other than the current one | the result, encoded (below) |
| `db.authorization.<method>` | as `database.*`, on `$db->getAuthorization()` | |
| `db.adapter` | `method`, `args` (by name) | `$db->getAdapter()->$method(...)`: capabilities, limits, direct adapter calls |
| `database.add_filter` | `name`: `encrypt`, `subQueryProbe` or `operator_double_decode` (the e2e suite's filters) | `null`; `Database::addFilter` with that fixture, process-wide like PHP's. The fixtures are static methods of `FilterFixtures` (`ops/core.php`), so their signatures, which cache keys carry, are `Class::method` and do not move when the file is edited; every `db.open` registers all of them, so a case's cache keys do not depend on the cases its driver ran before |
| `db.add_format` | `name`: `priceRange` or `priceRangeNew` | `true`; `Structure::addFormat` with that fixture (a `Range` from the attribute's `formatOptions`, integer) |
| `database.on` | `event`, `name`, `callback`: `true` (a listener logging each event) or `null` (removes it) | `{"$database": true}` |
| `database.before` | `event`, `name`, `callback`: `{"append": "<sql>"}` (a transformation appending to the statement, logging the result) or `null` | `{"$database": true}` |
| `db.log` | | what fixtures logged since the last `db.log`, in order, then cleared: `{"event", "listener", "data"}`, `{"filter", "call": "encode"|"decode"}`, `{"transform", "name", "sql"}` |
| `db.sql` | `sql`, naming no schema but the database's (`"${ns}".`) | rows, as lists of column => text |
| `db.cache` | `method` (`load`, `save`, `save_with_lease`, `get_generation`, `touch`, `list`, `purge`), `args` by name | the database's `Cache` call; saves report whether they stored |
| `db.pool` | `method`: `opened`, `count`, `timeout`, `clear`, `pinned_timeout` | a Pool database's pool seen from outside its adapter: connections opened (attempts included), idle connections, the timeout of a raw checkout's connection, clearing it, the timeout of the connection a transaction pinned |
| `db.mirror` | `part`: `source` or `destination` | a handle on that part of a Mirror (null without a destination) |
| `database.on_error` | `name` | `null`; `Mirror::onError` with a callback logging `{"mirror": <action>, "callback", "error"}` to `db.log` |
| `db.redis` | `command`: a list of strings | `null`; a raw command on the Redis adapter's database, behind the adapter |

`database.<method>` and `db.authorization.<method>` are every public method in snake_case (`createDocument` is `database.create_document`). Arguments are decoded for each parameter's type: `Document` parameters (and `array<Document>` ones, by their docblock) take `$document` tags or plain arrays, `queries` takes `$query` tags or arrays/strings, `DateTime` a string for `new DateTime()` (relative forms such as `-3600 seconds` count from now), `Cache` `"memory"` or `"none"`, `Authorization` `{"roles": [...]}` or a database handle (that database's own), `Adapter`/`Database` a database handle. Before that, anywhere in the arguments: `{"$datetime": "<string>"}` is a `DateTime`, `{"$now": <seconds>, "format": "<DateTime::format>"}` the string of now plus the seconds, `{"$repeat": "<text>", "times": <n>}` the repeated text.

A callable parameter takes `true` (a callback that only records its calls) or `{"steps": [...]}`, steps run at each call by a small interpreter: `{"op", "args", "bind"?, "catch"?, "return"?}`, where `{"$local": <bind>, "path"?}` in arguments is an earlier step's encoded result and `{"$arg": <n>, "path"?}` the callback's n-th argument. Arguments are encoded as the call receives them: PHP may change a document after the call (`iterate()` encodes its last document in place as the next page's cursor), which a Rust callback, owning its copy, never sees. A step that throws records `{"$error": ...}` and, without `"catch": true`, the exception leaves the callback (and, say, rolls the transaction back). `{"op": "$throw", "args": {"class", "message"}}` throws; `{"op": "$return", "args": {"value"}}` sets the callback's return value, as does `"return": true` on a step (its raw result). With a callback, the result is `{"result": <encoded>, "calls": {"<param>": [{"args": [...], "steps": [<result or $error>, ...]}, ...]}}`.

Results are `Values::encode`d, plus: a `Database` (fluent setters) is `{"$database": true}`, an adapter `{"$adapter": "Postgres"}`, a cache `{"$cache": true}`, an authorization `{"$authorization": true}`, a `DateTime` `{"$datetime": "Y-m-d\TH:i:s.uP"}`, an exception (`onError`'s argument) `{"$error": {"class", "message"}}`, a generator (`iterate`) the list it yields.

### The e2e suites

`tests/compat/database/recorder/` ports `tests/e2e/Adapter/PostgresTest.php` and `SharedTables/PostgresTest.php` (shared tables, tenant 999), with every scope trait they use, by running them in the dev image (PHPUnit's `TestCase` from its vendor, outside PHPUnit's runner: declaration order, `@depends` first, `@dataProvider` sets) against the compat PostgreSQL with a recording `Database`, adapter and `Authorization` (generated subclasses). Each call test code makes, at depth 0 (not the library's own calls to itself), becomes a step: `database.*`, `db.adapter` or `db.authorization.*`, named arguments encoded before the call. A callback the library runs opens a frame of its own, whose calls become the callback's `steps` (`catch` when the test caught a step's exception, `$throw` when the callback threw on its own, `return` or `$return` for its return value). Test code that builds a second `Database` (`new Database(...)`) loads from a copy where that is a recorded peer (`db.open` with `adapter`). Filters, formats, listeners and query transformations are recorded by name (core's fixtures), and a `db.log` follows every step during which they ran.

Every test method becomes a case `<Trait>::<method>[#<data set>]` in `cases/Scope-[SharedTables-]<Trait>.json` (`PostgresTest.json` for the two tests of PostgresTest itself), self-contained:

- `db.open` (bound `db`), then, when the database's settings differed from a fresh one's as the test began (leftovers of earlier tests: roles, tenant, metadata, document types, ...), the last call of each setting before it;
- a dependency slice: every earlier write touching a collection the test touches, closed over the collections those writes touch (a collection deleted before the test starts afresh), every step whose result a kept step refers to, and process-wide registrations (filters, formats);
- the test's own steps.

Ids the tests generate (`ID::unique()`, `uniqid()`: they first appear in arguments) become deterministic fakes of the same length (`u000...1`); ids the library generates (they first appear in a result) become `{"$ref": "s<step>", "path": ...}` (or `{"$local": ...}` inside a callback). Datetimes within 50 hours of the clock become `$now` (strings) or relative `$datetime` (objects); long strings `$repeat` (a random one, in `AttributeTests::testUpdateAttributeSize`, becomes a repetition of its start of the same length); the recorder's schema `${ns}`, and the other databases the tests name (`sharedTables`, `schema1`, `hellodb`, ...) `${ns}_<name>`, so each runtime works in schemas of its own, which the cleanup of `${ns}` drops too. Finds ordered by `Query::orderRandom()` and the adapter's host name and connection id are masked. Every case snapshots `postgres@database-postgres`. The cases use a Memory cache, which ignores the hash field of an entry, so the suite's cache tests that rely on per-projection entries (`DocumentTests::testGetDocumentSelect`, `testCacheEmptyDocumentSelect`, `CollectionTests::testPurgeCollectionCache`, `AttributeTests::testFilterNotAppliedWhenAttributeNotSelected`, and the `PermissionTests` reads that a second role repeats: `testCollectionPermissions{Find,Get,RelationshipsFind,RelationshipsGet}Works`) record what PHP does over Memory, where their assertions fail; a hash-aware cache comes with phase 3's Redis. The recorder writes what PHP returned while recording to `recorder/out/` (not committed); `recorder/fidelity.py` compares it with the recorded expectations, test by test, to show where a slice does not reproduce the suite's state.

The same suites also run through four more profiles of the recorder (`record.php`): `Pool-` (`PoolTest`: an `Adapter\Pool` of ten connections and a `redis` cache, so the cache tests above run against a hash-aware cache there), `Mirror-` (`MirrorTest`: a Mirror over PostgreSQL schemas on `database-postgres` and `database-postgres-mirror`, both snapshotted; `MirrorTest.json` holds MirrorTest's own tests), `Redis-` (`RedisTest`: the Redis adapter, snapshotted with `redis-json@database-redis-adapter`) and `SharedTables-Redis-` (`SharedTables/RedisTest`, tenant 999). Over Redis, collection sizes (`MEMORY USAGE` of keys named after the case namespace) and the shared adapter's database list (every database of the empty namespace, other runs included) are masked. `core-Mirror.json` adds what the suites leave out: the write filters' hooks, `onError` callbacks, upgrades and a Mirror without a destination.

Not ported, by design: `GeneralTests::testCacheReconnect` (it runs `docker stop`/`docker start` on the cache's Redis container between two reads; the compat Redis containers are shared by both runtimes and other cases, so nothing here stops them); the planner statistics of `PostgresTest::testReadDoesNotTouchThePermissionsTable` and `testVectorSearchUsesTheIndex` (`pg_stat_*` counters, timing dependent; their Database calls are ported). The e2e test files of the other adapters are waived until their phase.

The library's unit tests run on the compat PostgreSQL where they drive `Database` (their `Memory` adapter is phase 2), writing behind the cache with `db.sql` where they write through the adapter. Not ported, by design: `CacheKeyTest`'s mocked adapter answers (its hostname and tenant come from the PostgreSQL adapter and `db.open` here) and `testQueryCacheFieldValidatesQueryTypes` (a non-`Query` in `queries` cannot cross the wire); `CreateCollectionRaceTest::testCreateCollectionStillReportsDuplicateWhenCachePurgeFails` (a cache adapter that throws on purge); `TransactionRetryTest::testNonRetriableActionAbortsWhenRollbackFails` (an adapter subclass whose rollback throws) and `testRedisRollbackFailureClearsJournalStack` (a Redis adapter subclass whose `rollbackJournal()` throws: phpredis answers a server error reply with `false` and reconnects a dropped connection, so no state behind the adapter makes the real replay throw); `FilterRegistryTest::testOverridingABuiltInFilterBeforeTheFirstInstanceStillWins` (it empties the process-wide filter registry by reflection and overrides the built-in `datetime` filter for the rest of the process); `SpatialFilterTest` runs on two PostgreSQL databases instead of mocks returning different points. `FilterRegistryTest`, `QueryCacheTest` and `WithCacheLeaseTest` run over the `redis` cache, `PoolTimeoutTest` over a Pool database; `MongoPermissionStringsTest` waits for the MongoDB adapter (`spec.d/core.json`).

```bash
bin/compat-php tests/compat/database/recorder/record.php postgres   # cases/Scope-*.json, PostgresTest.json
bin/compat-php tests/compat/database/recorder/record.php shared     # cases/Scope-SharedTables-*.json
bin/compat record database --case Scope                             # their expectations
```

### Tasks

The PHP package ships no `bin` in its `composer.json`, and its scripts (`build`, `start`, `test`, `lint`, `format`, `check`, `coverage`) are development tooling. Its programs are the benchmarking CLI under `bin/`, waived (record them under `tasks_waived` in `spec.d/core.json` once this branch has the tasks tooling):

| Program | Waiver |
|---|---|
| `vendor/utopia-php/database/bin/cli.php` | The entry point of the benchmarking tasks below (a `Utopia\CLI` app); no product behaviour |
| `vendor/utopia-php/database/bin/tasks/load.php` | Development benchmark: fills a scratch MariaDB, MySQL, PostgreSQL or MongoDB database of the library's docker-compose stack with mock articles through Swoole PDO pools and times it; `bin/compat bench database` compares both runtimes' writes |
| `vendor/utopia-php/database/bin/tasks/index.php` | Development benchmark: adds the mock data's indexes to that scratch database and times them |
| `vendor/utopia-php/database/bin/tasks/query.php` | Development benchmark: times queries over the mock data for growing role counts and writes the timings to `bin/view/results`; `bin/compat bench database` compares both runtimes' finds |
| `vendor/utopia-php/database/bin/tasks/relationships.php` | Development benchmark: loads and times mock relationship graphs in that scratch database |
| `vendor/utopia-php/database/bin/tasks/operators.php` | Development benchmark: times operator updates against read-modify-write in that scratch database |
| `vendor/utopia-php/database/bin/view/index.php` | An HTML page charting the `query` task's result files; no library behaviour |

## php-std

`tests/compat/php-std` checks the PHP engine functions reimplemented in `crates/support/php-std` (the value model, type juggling, string and number formatting, JSON, `serialize`, igbinary, sorting, `filter_var`, `parse_url`) against the real built-ins. Its operations live in `ops/<area>.php` and `src/libs/php_std/<area>.rs`, one file per area.

Operations on structured values (areas `types`, `igbinary`, `sort`, and `json.decode_typed`, `serialize.unserialize_typed`) exchange them exactly, through `Typed.php` and `src/libs/php_std/typed.rs`: on input, `{"$array": [[k, v], ...]}` is an array with those keys (assigned like `$a[$k] = $v`) and `{"$object": [[k, v], ...]}` a `stdClass`, besides everything the Codec decodes; a pair may also be `{"k": k, "v": v}`, which the `object` generator can build. Results dump lists as lists, other arrays as `{"$array": ...}`, `stdClass` as `{"$object": ...}` and `-0.0` as `{"$float": "-0"}`, so key types, key order and objects are compared, not just their JSON look-alikes. Every empty array decodes to the engine's shared empty array, as `json_decode` and `unserialize` produce it.
