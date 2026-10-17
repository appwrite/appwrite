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
bin/compat run [<lib>...] [--case <text>|@<file>] [--fail-fast] [-v]   # every case on both runtimes (`@<file>`: the case labels it lists, one per line)
bin/compat fuzz <lib> [--op <op>] [--iterations <n>] [--seed <n>] [--save]
bin/compat record <lib>          # store PHP's results as `expect` in the case files
bin/compat coverage <lib>        # API coverage, adapter parity, ported tests, ported tasks
bin/compat check <lib>           # coverage + run + fuzz: the conversion gate
bin/compat ci                    # run + fuzz every library; check the complete ones
bin/compat ci database           # only these libraries; `'!database'` leaves one out
bin/compat ci database@mongo     # one CI shard of a library's cases (spec.json `shards`: shard -> case file prefixes, `"*"` the rest, which also fuzzes)
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

`cargo test -p compat` replays every recorded expectation against Rust without PHP (cases needing Redis or a database only with `COMPAT_SERVICES=1`), so the workspace test run guards compatibility too. `COMPAT_LIB=<lib>` and `COMPAT_CASE=<text>` (a substring of `<lib>/<file>#<case>`) narrow it, a fast loop while converting a library. Which cases need services: those that snapshot state on a service (`state`; the `sqlite` kind reads the cases' own files, so it does not count) or list the `services` requirement, and, once any operation of the spec declares the services it needs (`"services": [...]` on the op), those with a step of such an operation, unless the step's arguments match one of the op's `services_unless` patterns (argument => allowed values, `null` for absent and `"*"` for present: `db.open` on the SQLite or in-memory engine opens no service); a spec whose operations declare none keeps the older rule (every case of a spec listing `services` needs them).

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
  "tasks": {},
  "tasks_waived": {},
  "quirks": [{ "symbol": "...", "description": "...", "case": "Text.json#..." }]
}
```

A large spec can be split: `spec.d/*.json` files hold more `ops`, `generators`, `waivers`, `tests_waived`, `tasks`, `tasks_waived`, `quirks`, `deviations` and `scrub` (one file per area or owner), merged into `spec.json`; a name defined twice is an error.

`flakes` (spec.json only) maps a fragment of a PHP error message to the reason it is the PHP library failing under load rather than a difference (database: a metadata transaction lost on a busy CI runner). A case whose PHP side fails with one runs once more, logged as `retry <case>: ...`, unless a step of its recording expects that failure.

`scrub` replaces values a library generates nondeterministically in every result and snapshot, before comparing and recording (bound results keep the raw values, so later steps use the real ids). Each entry has a `kind` and a `reason` (`crates/tools/compat/src/runner/scrub.rs`):

| Kind | Replaces | With |
|---|---|---|
| `now` | a datetime string within `seconds` (default 3600) of the runner's clock, as `Y-m-d\TH:i:s.vP`, `Y-m-d H:i:s.v` or `Y-m-d H:i:s` (zone optional) | `<now>` |
| `uniqid` | a token of 13 to 36 lowercase hex digits whose first 8 are a unix time within a day of the clock (`uniqid()` plus padding, as `ID::unique()` makes, and the `.dddddddd` decimals of `uniqid('', true)`), alone or delimited inside a string | `<uniqid>` |
| `long` | a string over 64 KiB, kept out of the case files | `<long:<bytes>:<sha256>>` |

PHP test files to port (`php.tests`) are the `*Test.php` classes and the `*Tests.php` traits test classes are assembled from (utopia-php/database's e2e scopes hold the test bodies). Directories that are not on the host (`vendor/`, which only the dev image has) are listed by the PHP driver (`$tests`).

A fuzz profile with `"isolate": true` runs in fresh PHP and Rust driver processes, for functions with process-wide state that other operations touch on one side only (PHP's PCRE cache and JIT switch, which engine functions like `filter_var` also fill). Each library's `run`, `fuzz` and `record` also starts fresh drivers, so a library never inherits another's process-wide state: after `php-std`'s fuzzing has switched the PCRE JIT off, `user-agent`'s backtracking cases would run interpreted, much slower. A library behaves in `ci` exactly as it does alone.

A library **not converted, by design** ([crates/CONVERSION.md](../../crates/CONVERSION.md) §8) keeps only its `spec.json`, with `"rust": null` and the decision's reason, and nothing else (no `ops`, cases or adapters):

```json
{
  "lib": "psr7",
  "description": "...",
  "php": { "src": ["packages/psr7/src"], "tests": ["packages/psr7/tests"] },
  "rust": null,
  "not_converted": { "reason": "PHP's PSR-7/PSR-17/PSR-18 HTTP message interfaces; Rust uses the http crate (multipart encoding lives in utopia-client)" }
}
```

`run`, `ci`, `coverage`, `report` and the replay leave it out of "every library", naming it in a command is an error that prints the reason, `status` lists it under "Not converted, by design", and `crates` gives it the status `retired` with the reason.

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

- `bind` names a step's result; `{"$ref": "name", "path": "/json/pointer"}` uses it in later arguments (each runtime its own value, so handles work); with `"default": <value>`, that value stands in when the result has nothing at the path.
- `${ns}` in any string is the run's namespace.
- `$equal` (`{"a": ..., "b": ...}`) is a built-in operation comparing two values, e.g. two bound tokens.
- `mask` on a step, with a `reason`, hides values that legitimately differ between runs.
- `state` on a case overrides the spec's snapshot kinds (`[]` for none).
- Interop cases run twice, with sides `a`/`b` as PHP/Rust and then Rust/PHP on one shared namespace; both directions must agree with each other and with `expect`.
- `expect` is written by `bin/compat record`, never by hand.
- `requires` lists what a case needs from the machine: `case-sensitive-files`, `driver-process` (each runtime's own driver process, so not replayed in-process), or `services` (a service of `compat.json`, such as a fixture server, in a library whose spec lists none, so `cargo test -p compat` replays it only with `COMPAT_SERVICES=1`).

## Tasks

A library's tasks are the programs it ships besides its API: a data import, a code generator, a maintenance command ([CONVERSION.md](../../crates/CONVERSION.md) §2 rule 6). `bin/compat coverage` discovers them in the PHP package (the parent of each `php.src` directory; one only the dev image has, under `vendor/`, is read through the PHP driver's `$programs`), so none can be missed:

- every `bin` of `composer.json`;
- every `composer.json` script except development tooling (`test`, `lint`, `format`, `analyze`, `check`, `refactor`, `bench` and the like, with any `:<suffix>`). A script that only runs a PHP file of the package (`"import:all": "php import.php all --commit=true"`) is a way to run that file's task, listed with it;
- every PHP file outside `src/`, `tests/` and `vendor/` that is a program: a file whose first statement (after `declare`, `namespace` and `use`) is `return` is data or configuration (`data/data.php`, `rector.php`), not a program.

Each one is ported or waived in `spec.json`, by its id (the program's repository path, or `<package>/composer.json#<script>`); anything else is pending and the library is not converted:

```json
"tasks": {
  "import": {
    "doc": "Refreshes the free and disposable email domain lists from their sources.",
    "php": "packages/emails/import.php",
    "rust": { "package": "utopia-emails", "bin": "emails-import" },
    "writes": ["crates/utopia/emails/data/disposable-domains.txt", "crates/utopia/emails/data/free-domains.txt"],
    "covered_by": ["cases/import.json"]
  }
},
"tasks_waived": {
  "packages/user-agent/benchmark/benchmark.php": "A benchmark of the PHP parser: bin/compat bench compares both runtimes"
}
```

A ported task needs the binary (`src/bin/<bin>.rs` of the crate, or a `[[bin]]`) and case files that run it. Cases run each runtime's task as its own process, the way a developer runs it: `Tests\Compat\Task::run()` (`tests/compat/php/Task.php`) starts `php <script>` from the repository root, and `compat::task::run()` (`crates/tools/compat/src/task.rs`) builds the binary with cargo, into the driver's own target directory, and starts it. Both pass extra environment variables, and an operation per task (`import.run`) returns `{exit, stdout, stderr}` and what the task wrote, read back by each adapter from its own format (PHP's data files, the crate's), with each runtime's paths replaced by placeholders.

Tasks that download never reach the internet in tests: the PHP script and the Rust binary both read each source's URL from an environment variable (`UTOPIA_DOMAINS_PSL_URL`, `UTOPIA_EMAILS_<TYPE>_<SOURCE>_URL`) and where to write (`UTOPIA_DOMAINS_DATA_DIR`, `UTOPIA_EMAILS_DATA_DIR`), and the cases serve fixture files from a server the library's `services.sh` starts (`domains-http`, `emails-http`). The data a task writes is the crate's own (`crates/utopia/<name>/data/`), embedded at compile time; a `data.digest` case checks it holds the same entries in the same order as the PHP library's data files.

`bin/compat crates` documents each crate's tasks on the docs site: the binary's `//!` comment (its first paragraph, then `# Commands`, `# Arguments`, `# Environment` and `# Exit codes` sections), the files it writes, and the PHP command it replaces.

## Adding a library

1. `bin/compat new <lib>`, then read the PHP source and tests end to end.
2. `bin/compat coverage <lib>` lists every public symbol. Design operations that exercise them and add them to `spec.json` with `covers`.
3. Implement them in `Adapter.php` and `src/libs/<lib>.rs`, port every PHP test class to a case file, add interop cases for shared formats and fuzz profiles for pure operations. Port every task `coverage` lists (or waive it), with cases that run both runtimes' tasks against fixtures ([Tasks](#tasks)).
4. Converge: `bin/compat run`, `fuzz`, `coverage`. A difference is a Rust bug, a PHP quirk to keep and record, or a missing case. Never loosen a comparison to make it pass.
5. `bin/compat record <lib>`, set `"complete": true` once `bin/compat check <lib>` passes, and `bin/compat status --write`.

## Benchmarks

A converted library's hot paths are benchmarked against PHP ([CONVERSION.md](../../crates/CONVERSION.md) §2 rule 8). Each benchmark is a name both adapters declare: `BENCHES` and `bench(name, session)` in `crates/tools/compat/src/libs/<lib>.rs` (listed in `libs::benches` and `libs::bench`), and `benchmarks()` in `Adapter.php` (implementing `Tests\Compat\Benchmarks`). It is a setup that returns the closure to time, so only the library call is measured, not argument decoding:

```php
'parse' => ['Parse a Redis connection string', fn (Session $s) => fn () => new DSN(self::DSN)],
```

```rust
"parse" => Bench::sync(|| { black_box(Dsn::parse(black_box(DSN)).ok()); }),
```

Both drivers time a benchmark the same way (`crates/tools/compat/src/bench.rs`, `php/Bench.php`): warm up, size samples to about 20 ms, take 15, report the median time per call with the fastest and slowest sample. The Rust driver is built in release mode for this; PHP runs as the dev image configures it (opcache and the tracing JIT). Pick what a request does many times: parsing, validation, a cache read, a route match. Benchmark async or networked work with `Bench::future` against the same services the cases use.

Around the 15 timed samples (not the warm-up or the sizing runs) both drivers also record what the process spent. Each runtime's timing in `bench.json` holds:

| Field | Unit | Meaning | PHP | Rust |
|---|---|---|---|---|
| `ns`, `min`, `max` | ns per call | Wall time: the median, fastest and slowest sample | `hrtime()` | `Instant` |
| `samples`, `calls` | | Samples taken, calls per sample | | |
| `cpu` | ns per call | Process CPU time (user and system) over the samples, divided by the calls | `getrusage()` (microsecond resolution) | `clock_gettime(CLOCK_PROCESS_CPUTIME_ID)` |
| `peak` | bytes | The most heap the samples held above what was live when they started | `memory_get_peak_usage()` after `memory_reset_peak_usage()`, minus `memory_get_usage()` at the start | the counting global allocator's live bytes (alloc minus free) |
| `retained` | bytes per call | Live heap growth over the samples, divided by the calls: what calls keep (caches, interned values, leaks) | `memory_get_usage()` delta | the allocator's live bytes delta |
| `rss` | bytes | The driver process's peak resident set so far (`ru_maxrss`) | `getrusage()` | `getrusage(RUSAGE_SELF)` |
| `allocations`, `bytes` | per call | Heap allocations (reallocations included) and bytes allocated | not available | the counting allocator |

Read them with their caveats:

- **CPU** counts every thread of the driver process (the Rust driver runs a current-thread utopia-async runtime, PHP one thread), and nothing outside it: a call waiting on PostgreSQL or Redis spends that service's CPU, not the driver's, so a networked benchmark's CPU per call is well below its wall time. Under load from other processes CPU time is steadier than wall time but not immune (cache and frequency effects). In Docker Desktop's Linux VM it is less so: when the host preempts the VM's virtual CPUs, the guest kernel still charges that time to the running process, so on a busy macOS host a containerised driver's CPU per call can even exceed its wall time per call. Compare CPU only between runs where both drivers ran in the same place (both in containers, the default), on a quiet machine.
- **Heap** is counted by two different allocators. PHP's figures are the Zend memory manager's request heap: allocations rounded up to its size classes, and none of the engine's persistent memory (opcache, interned strings, classes). Rust's are every allocation through the global allocator (mimalloc, wrapped by the counter in `src/bin/compat-driver.rs`) at its requested size. Compare them as orders of magnitude. `peak` is the working set of one call when calls free what they allocate (the usual case), so it does not grow with the number of calls; Rust's is 0 when a call does not allocate.
- **RSS** is a running maximum for the whole driver process: the runtime's baseline (PHP's engine and loaded classes, Rust's binary) plus whatever the benchmarks run before this one touched. It describes the driver, not the benchmark; the site shows the largest value per runtime.
- The gate compares wall time only; CPU and memory are published, never gated. Files written before these fields existed lack them, and the site shows "not measured" rather than a number.

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
| `sqlite` | every SQLite database file under `/tmp/compat-fs/${ns}/`, read through SQLite: `sqlite_master` (type, name, table, SQL; sorted) and per table its columns (`PRAGMA table_info`) and rows (sorted, each value with its storage class). Cleanup removes the directory, as `files` does |
| `clickhouse@<service>` | every ClickHouse database named `${ns}` or `${ns}_*` on a service of `compat.json` (its `runner` URL, `http://user:password@host:port`; utopia-php/audit's cases on `audit-clickhouse`, utopia-php/usage's on `usage-clickhouse`), over the HTTP interface: per table (sorted) its engine, `create_table_query` and rows (`FORMAT JSON` objects with 64-bit integers as strings, read `FINAL` from the engines that fold rows in merges, sorted by their columns other than the random `id` and `ingestId`, then by the whole row). Views have no rows. A namespace without a database snapshots as `{}`. Cleanup drops the databases |

A library that writes to another service adds a kind there (PostgreSQL tables, MongoDB collections, files), touching only resources whose names contain the namespace.

## audit

utopia-php/audit runs its suites on both adapters. The ClickHouse ones use a ClickHouse server of their own, `compat-audit-clickhouse` (`tests/compat/audit/services.sh up`, service `audit-clickhouse`), never the dev stack's: `clickhouse.new` without a `host` creates the case's database `${ns}` outside the library (every request of the adapter names its database, so it cannot create it) and points the adapter at it, and the `clickhouse@audit-clickhouse` snapshot compares and drops it. The Database ones run on the database library's MariaDB (`database-mariadb`, `tests/compat/database/services.sh up`) and on the in-memory adapter, which needs no service. `ClickHouseTest`'s recording client (`tests/Adapter/Client.php`) is used as is by PHP and mirrored by `Fake` in `src/libs/audit.rs`; `clickhouse.call` runs one adapter call on it, which the fuzz profiles use to compare the SQL, the parameters and the request bodies both runtimes send.

## database

utopia-php/database 8.3.0 (with utopia-php/query, its query and builder types) is driven through three vocabularies: `core` (`db.*` and `database.*`: a `Database` and what hangs off it), `objects` (`object.*`: every other class of the library, by reflection) and `adapter` (`adapter.*`: an adapter without a `Database`, and the fuzz programs). PHP: `tests/compat/database/ops/<area>.php`, values through `Values.php`, the objects `object.*` stores through `Objects.php`, the code the library takes as callables (filters, hooks, formats, codecs, mirror filters) as named fixtures in `Fixtures.php`. Rust: `crates/tools/compat/src/libs/database/`. Specs: `spec.d/<area>.json`.

### Values

Values cross as JSON (see [How it works](#how-it-works)) with these tags, decoded by `Values::decode()` and written by `Values::encode()` (the Rust twin is `codec.rs`). A class `<name>` is relative to `Utopia\Database` (`Attribute`, `Event\Document\Created`, `Validator\Structure`) or, with a `Query\` prefix, to `Utopia` (`Query\Method`, `Query\Schema\ColumnType`, `Query\Builder\Statement`):

| Tag | Is |
|---|---|
| `{"$document": {...}}` | a `Document` (nested tags decoded first; `{}` an empty one) |
| `{"$document": {...}, "$class": "<name>"}` | a `Document` subclass: `Collection` (decoded with `Collection::fromArray()`), or a custom document type (results only: `Tests\E2E\Adapter\Support\User`, ...) |
| `{"$query": {"method", "attribute", "values", "alias"?}}` | `new Query(method, attribute, values, alias)`; `alias` only when not empty |
| `{"$operator": {"method", "attribute", "values"}}` | `new Operator(OperatorType::from(method), attribute, values)` |
| `{"$enum": "<name>::<Case>"}` | an enum case (`Event::DocumentCreate`, `Query\Schema\ColumnType::String`, `PermissionType::Read`) |
| `{"$value": "<name>", "fields": {...}}` | a value object (`Attribute`, `Index`, `Relationship`, `Format`, `Attribute\Update`, `Collection\Update`, `Relationship\Update`, `Change`, `Adapter\Limits`, `Adapter\Profile`, `Schema\Column`, `ParsedQuery`, the events, `Mirror\Failure`, `Profiler\Log`, `Query\Builder\Statement`, ...): its public properties in declaration order, parents' first. Decoded by setting them without the constructor, or, for a class with state of its own besides them (`Adapter\Profile`), by its constructor with the fields as named arguments |
| `{"$datetime": "<string>"}` | a `\DateTime` (`Y-m-d\TH:i:s.uP` when encoded) |
| `{"$object": {...}}` | a non-empty `stdClass` |
| `{"$bson": <canonical extended JSON>}` | a `MongoDB\BSON` value (UTCDateTime, ObjectId, Regex) |
| `{"$error": {"class", "message"}}` | an exception inside a result (a `Mirror\Failure`'s); results only |
| `{"$closure": true}` | a closure inside a result (a `Profile`'s attribute check); results only |

Arguments also take, before Values decodes them (`Core::tags()`): `{"$datetime": "<string>"}` relative to the clock (`-3600 seconds`), `{"$now": <seconds>, "format": "<DateTime::format>"}` (the string of now plus the seconds), `{"$repeat": "<text>", "times": <n>}` (the text repeated), and per parameter (`Core::argument()`):

- `{"$obj": "<name>"}` anywhere: the object an earlier step stored under that name (the same instance: its setters' changes are seen).
- `{"$pdo": {"dsn", "wrapper"?, "username"?, "password"?, "options"?}}`: a `\PDO` (a `Utopia\Database\PDO` with `wrapper`) on that DSN (the unit tests' `sqlite::memory:`).
- `{"$throwable": {"class", "message", "code"?, "errorInfo"?, "previous"?}}`: an exception (`Connection::hasError()`'s argument); a string `code` (an SQLSTATE) is set as PDO sets it.
- A `Document` parameter takes a `$document` tag or a plain array; a `Collection` one a `$document` tag, a plain array or a `Collection`; `queries` takes `$query` tags, query arrays or query strings; a docblock `array<Document>` parameter documents; a `DateTime` one a string for `new \DateTime()`; a backed enum one its value or a `$enum` tag; `Cache` a cache kind (below); `Authorization` `{"roles": [...]}` or a database handle (that database's); `Adapter`/`Database` a database handle; `Builder|Statement` a `Query\Builder\Statement` value (or `{"query", "bindings"}`).
- Hooks (`addHook`, `removeHook`): `"<name>"` or `{"$hook": "<name>", ...parameters, "as"?: "<name>"}`, a fixture (below); a string where the parameter also takes `string` (`removeHook(Relationships::class)`, `removeTransform()`) is a class name, a fixture's name naming its class. `as` stores the instance for `$obj` (so `removeHook({"$obj": ...})` removes that instance).
- Codecs (a `Filter\Codec`, the `Database` constructor's `filters`, `setFilters()`'s registry: a list): `"<name>"` or `{"$codec": "<name>", ...constructor arguments}`.
- A query cache (`setQueryCache`): `{"cache": "<kind>"|"database", "slots"?: n, "as"?}`; `database` is the database's own cache.
- A callable: `true` (records its calls) or `{"steps": [...]}`; see [Callbacks](#callbacks).

Cache kinds: `memory` (`Utopia\Cache\Adapter\Memory`), `none`, `unreachable` (a Redis cache whose client fails every command with `RedisException('Redis server redis:6379 went away')`, no retries: GeneralTests' stubbed client), and the Redis caches `redis`, `redis-mirror` (private to each runtime, flushed when a database of the runtime opens) and `redis-shared` (one both runtimes read and write, which `interop.json` drives), each with Json entries or, with a `-igbinary` suffix, igbinary ones.

Results are `Values::encode`d, plus: a `Database` (fluent setters) is `{"$database": true}`, an adapter `{"$adapter": "<its library class>"}` (`Postgres`, `SQLite`, ...: a fixture subclass reports its library class), a cache `{"$cache": true}`, a query cache `{"$queryCache": true}`, an authorization `{"$authorization": true}`, a profiler `{"$profiler": true}`, a filter registry `{"$registry": [<names>]}`, a codec `{"$codec": true}`, a query builder `{"$builder": true}`, a schema builder `{"$schema": true}`, a hook `{"$hook": "<its library class>"}`, a generator (`cursor()`) the list it yields. Every operation takes `"as": "<name>"`, which stores its raw result for `{"$obj": "<name>"}`.

### Fixtures

Code the library is given is a named fixture, the same on both runtimes (`Fixtures.php`). Each logs what it receives to `db.log`:

| Fixture | Is | Logs |
|---|---|---|
| global filters (`database.add_filter`): `encrypt`, `encrypt_operator`, `subQueryProbe`, `operator_double_decode`, `joinedSeal` | the e2e suite's filters (AttributeTests, OperatorTests, JoinTests); static methods of `FilterFixtures`, so their signatures, which cache keys carry, are `Tests\Compat\Database\FilterFixtures::<method>` | `{"filter": <fixture>, "call": "encode"\|"decode"}` |
| formats (`db.add_format`): `priceRange`, `priceRangeNew`, `email`, `structureFormatProbe`, `testformat`, `updateAttributeIntegerOnly`, `storedRange`, `storedEnum` | `Structure::addFormat()` with the suites' validators (`Range`, `Text`, `WhiteList`, an email `Text`) | `email`, `structureFormatProbe`: `{"format", "attribute"}` |
| hook `Relationships` `{prepare?}`, `Permissions`, `Tenancy` `{column?}` | the library's hooks | |
| hook `Log` `{name?, events?}` | a `Hook\Lifecycle` (`Named` with `name`, `Selective` with `events`, a list of event values): the suite's `EventRecorder` | `{"hook": <name>\|null, "event": <the typed event, a $value tag>}` |
| hook `Transform` `{table, event, append}` | a `Hook\Transform` appending `append` to the statements of `event` (a value) naming `table` (CollectionTests::testTransformations) | `{"transform": <event>, "query": <the statement before>}` |
| hook `Nested` `{marker}` | a `Hook\Nested` giving a document whose id is `marker` a new `Id::unique()` (NestedPreparationTests) | `{"nested": [<collection id>, <document id>]}` |
| hook `Decorator` `{attribute, value}`, `Queries` `{limit}` | a `Hook\Decorator` setting an attribute; a `Hook\Queries` adding a limit to reads without one | `{"decorate", "collection", "document"}`, `{"rewrite", "collection", "queries"}` |
| codecs `reversed` `{name?}`, `rot13` `{name?}`, `prefixed` `{prefix}` | the unit tests' `Filter\Codec`s (`Reversed`, `Rot13`, `CodecTestPrefixed`); classes `Tests\Compat\Database\Fixture\Codec\{Reversed,Rot13,Prefixed}`, which name their cache signatures | |
| mirror filter `log` | a `Mirror\Filter` passing everything through | `{"mirror_filter": <hook>, "collection", ...}` |

The e2e Base registers `Relationships` and `Permissions` on its database before every test; `db.open` does the same unless given `hooks`. Each case (session) starts with the library's process-wide registries as a fresh process has them: the built-in filters plus the canonical global filter fixtures, and no formats (`Core::fresh()`), so what an earlier case registered or overrode does not leak into it.

### The `core` vocabulary

A case opens a database first. Its PostgreSQL database lives in schema `${ns}` on service `database-postgres` (`tests/compat/database/services.sh up`), which the `postgres@database-postgres` snapshot compares.

| Operation | Arguments | Result |
|---|---|---|
| `db.open` | `namespace` (`"compat"`), `database` (`${ns}`), `shared_tables`, `tenant`, `tenant_per_document`, `cache` (a kind, `"memory"`), `create` (true), `roles` (`["any"]`), `hooks` (`["Relationships", "Permissions"]`), `filters` (codec specs, the constructor's), `schemaless` (`setSchemaless(true)`), `engine` (`"postgres"`, `"sqlite"`, `"memory"`, `"mongo"`), `driver` (`"postgres"`, `"mariadb"` on `database-mariadb`, `"mysql"` on `database-mysql`), `file` (SQLite: the file under `/tmp/compat-fs/${ns}/`, `database.sqlite`), `emulate_mysql` (SQLite: the suites' adapter with `$emulateMySQL = true`, `Fixture\EmulatedSQLite`), `pool` (`{"size": n}`: an `Adapter\Pool` over a `Utopia\Pools\Pool` of `driver` connections from a Stack, waiting 0 s, with a connection of its own for `db.sql`; `"unreachable": true` refuses every connection; `"read": {"size"}` makes it a `ReadWritePool` with a read pool; `{"of": <handle>}` another Pool database's pool), `redis` (true: an `Adapter\Redis` on `database-redis-adapter`), `mirror` (`{"destination"?: true, "filters"?: [<mirror filter fixtures>], "cache"?: "redis-mirror"}`: a `Mirror` whose source is this adapter and whose destination is a PostgreSQL database on `database-postgres-mirror`), `adapter` (a database handle: a peer on that database's adapter), `detached` (a fresh adapter of `engine`, not set up and not made current: a test's own `new Memory()`) | a handle; the database becomes the session's current one. Registers every canonical global filter fixture first (`encrypt`, `subQueryProbe`, `operator_double_decode`, `joinedSeal`: process-wide, so cache keys do not depend on earlier cases), builds `new Database(adapter, cache, codecs)` with its own `Authorization` holding `roles`, then `setSchemaless`, `addHook` each hook, `setDatabase`, `setSharedTables`, `setTenant`, `setTenantPerDocument`, `setNamespace` and `create()`. MongoDB: `new Mongo(new Client(database, ...))` on `database-mongo`; PDOs take 7.4's attributes (`Core::PDO_ATTRIBUTES`) |
| `db.select` | `db` | `true`; makes `db` current |
| `database.<method>` | the method's parameters by name; `db` picks a database other than the current one | every public method of `Database` and `Mirror` in snake case (`createDocument` is `database.create_document`), plus the static `collection_definition` and `internal_attributes_for`; arguments as above |
| `db.authorization.<method>` | as `database.*` | on `$db->getAuthorization()` |
| `db.adapter` | `method`, `args` | `$db->getAdapter()->{method}(...args)`: any public adapter method (`supports`, `capabilities`, `limits`, `rawQuery`, `id`, `hostname`, ...) |
| `db.profiler` | `method`, `args` | a method of the database's `Profiler` (`setProfiling(true)` made it) |
| `database.add_filter` | `name`, `fixture` (`name`) | `null`; `Database::addFilter(name, ...)` with that global filter fixture (process-wide, until the next `db.open` registers the canonical ones again) |
| `db.add_format` | `name` | `true`; `Structure::addFormat` with that format fixture |
| `database.on_error` | `name` | `{"$database": true}`; `Mirror::onError` with a callback logging `{"mirror": <failure method>, "callback": <name>, "event": <event value or null>, "error": <$error>}` |
| `db.log` | | what fixtures logged since the last `db.log`, in order, then cleared |
| `db.sql` | `sql`, naming no schema but the database's (`"${ns}".`, or `` `${ns}`. `` on MariaDB/MySQL) | rows, as lists of column => text |
| `db.cache` | `method` (`load`, `save`, `save_with_lease`, `get_generation`, `touch`, `list`, `purge`), `args` by name | the database's `Cache` call; saves report whether they stored |
| `db.mirror` | `part`: `source` or `destination` | a handle on that part of a Mirror (null without a destination) |
| `db.redis` | `command`: a list of strings | `null`; a raw command on the Redis adapter's database, behind the adapter |

### The `objects` vocabulary

| Operation | Arguments | Result |
|---|---|---|
| `object.new` | `class`, `args` (the constructor's parameters by name, callbacks included), `as` | `new <class>(...args)`, encoded (with `calls` when given callbacks); stored under `as` |
| `object.call` | `class` (a static method) or `this` (`{"$obj": <name>}`, or a value tag decoded afresh: a `$document`, `$query`, `$enum`, ...), `method`, `args`, `as` | the result, encoded; stored under `as` |
| `object.get` | `this`, `property` | a public property |

Arguments are decoded as for `database.*`. A class is any of the library (`Document`, `Query`, `Operator`, `Attribute`, `Index`, `Relationship`, `Collection`, the update models, `Permission`, `Role`, `Id`, `DateTime`, `Connection`, `Change`, the validators, `Validator\Authorization` and its `Input`, the exceptions, `Filter\Registry`, `Cache\*`, `Builder\*`, `Hook\*`, `State\*`, `Storage`, the enums, ...). A `Database` or an adapter an `object.new` builds is one like `db.open`'s, without its setup.

### Callbacks

A callable argument takes `true` (a callback that only records its calls) or `{"steps": [...]}`, steps run at each call by a small interpreter: `{"op", "args", "bind"?, "catch"?, "return"?}`, where `{"$local": <bind>, "path"?}` in arguments is an earlier step's encoded result and `{"$arg": <n>, "path"?}` the callback's n-th argument. Arguments are encoded as the call receives them. A step that throws records `{"$error": ...}` and, without `"catch": true`, the exception leaves the callback (and, say, rolls the transaction back). `{"op": "$throw", "args": {"class", "message"}}` throws; `{"op": "$return", "args": {"value"}}` sets the callback's return value, as does `"return": true` on a step (its raw result). With a callback, the result is `{"result": <encoded>, "calls": {"<param>": [{"args": [...], "steps": [<result or $error>, ...]}, ...]}}`.

### The `adapter` vocabulary

| Operation | Arguments | Result |
|---|---|---|
| `adapter.open` | `driver` (`postgres`, `mariadb`, `mysql`) or `engine` (`sqlite`: file `/tmp/compat-fs/${ns}/<file>`, `adapter.sqlite`; `memory`), `database` (`${ns}`), `namespace` (`ns`), `shared_tables`, `tenant`, `tenant_per_document`, `roles`/`status` (its `Authorization`), `hooks` (write hooks, `["Permissions"]`) | a handle on a bare adapter, its own connection (not persistent) |
| `adapter.call` | `adapter`, `method`, `args` | `$adapter->{method}(...args)`, arguments as for `database.*` |
| `adapter.fuzz_find`, `mongo.fuzz_find` | `queries`, `order_attributes`, `order_types` (`ASC`/`DESC`), `limit`, `offset`, `cursor`, `cursor_direction`, `roles`, `attribute_types`, `arrays`, `driver`/`engine` | `find()` on collection `fz` of a database `${ns}` seeded once per namespace and driver (24 documents; `$sequence` ends every order) |
| `adapter.fuzz_roundtrip`, `mongo.fuzz_roundtrip` | `value`, `driver`/`engine` | create, get, find (equal) and delete of a document holding `value` in a text, an array and (PostgreSQL, MongoDB) an object attribute of collection `rt` |

### The e2e suites

`tests/compat/database/recorder/record.php <profile>` ports the e2e suites by running them in the PHP image (PHPUnit's `TestCase` outside PHPUnit's runner: declaration order, `@depends` first, data providers' sets) against the compat services with a recording `Database`, adapter and `Authorization` (generated subclasses, `Classes.php`), and a recording wrapper of the `Profiler` it hands out. Each call test code makes, at depth 0 (not the library's own calls to itself), becomes a step: `database.*`, `db.adapter`, `db.profiler` or `db.authorization.*`, named arguments encoded before the call. A callback the library runs opens a frame of its own, whose calls become the callback's `steps` (`catch` when the test caught a step's exception, `$throw` when the callback threw on its own, `return` or `$return` for its return value). A builder (`from()`, `schema()`) is not recorded: the statement it runs is (`database.mutate`, `database.query`, `db.adapter rawQuery`). Test code that builds a second `Database` (`new Database(...)`) loads from a copy where that is a recorded peer (`db.open` with `adapter` and `hooks: []`; on an adapter the test built itself, `db.open` with `detached`). The suite's global filters, formats and hooks are recorded as their fixtures (`Recorder::FILTERS` names a filter by where the test declares it; the suite's `EventRecorder`, transform and nested hook log their calls from patched copies of their sources), and a `db.log` follows every step during which they ran. A hook, cache or query cache the test hands back later (`removeHook($hook)`, `setCache($original)`) is stored with `as` where it first appears and named with `$obj` after.

Every test method becomes a case `<Trait>::<method>[#<data set>]` in `cases/Scope-[<Profile>-]<Trait>.json` (`<Suite>Test.json` for the suite's own tests), self-contained:

- `db.open` (bound `db`), then, when the database's settings differed from a fresh one's as the test began (leftovers of earlier tests: roles, tenant, metadata, timeouts, toggles, document types, hooks, ...), the steps restoring them (`Target::restore()`); the suite's EventRecorders a test leaves registered are not carried on (they only record);
- a dependency slice: every earlier write touching a collection the test touches, closed over the collections those writes touch (a collection deleted before the test starts afresh, everything when the suite's own database was deleted), every step whose result or stored object a kept step refers to, and process-wide registrations (filters, formats);
- the test's own steps.

Ids the tests generate (`Id::unique()`, `uniqid()`: they first appear in arguments) become deterministic fakes of the same length (`u000...1`); ids the library generates (they first appear in a result) become `{"$ref": "s<step>", "path": ...}` (or `{"$local": ...}` inside a callback). Datetimes within 50 hours of the clock become `$now` (strings) or relative `$datetime` (objects); long strings `$repeat`; the recorder's schema `${ns}`, and the other databases the tests name (`sharedTables`, `schema1`, `hellodb`, ...) `${ns}_<name>`. Masked, with their reasons: finds ordered by `Query::orderRandom()`, pages after a cursor on `$createdAt`/`$updatedAt`, InnoDB table sizes, MySQL's `getSchemaAttributes()` order, MongoDB's database listings and storage sizes, host names and connection ids, profiled statements' durations and backtraces, and the rows of a join that tie on everything the find orders by (its orders, the main `$sequence`, each joined `$id`, which a generated id scrubs): `recorder/ties.py --write` masks those, and steps paging from them, after expectations are recorded. The recorder writes what PHP returned while recording to `recorder/out/` (not committed); `recorder/fidelity.py` compares it with the recorded expectations, test by test.

Profiles (`record.php <profile>`, each `db.open` and snapshot):

| Profile | Suite | Cases | `db.open` | Snapshot |
|---|---|---|---|---|
| `postgres` | `PostgresTest` | `Scope-*.json`, `PostgresTest.json` | `{}` | `postgres@database-postgres` |
| `shared` | `SharedTables/PostgresTest` | `Scope-SharedTables-*` | `namespace: ""`, shared tables, tenant 999 | `postgres@database-postgres` |
| `pool` | `PoolTest` (on PostgreSQL) | `Scope-Pool-*`, `PoolTest.json` | `pool: {size: 10}`, `cache: redis` | `postgres@database-postgres` |
| `mirror` | `MirrorTest` (PostgreSQL onto PostgreSQL) | `Scope-Mirror-*`, `MirrorTest.json` | `mirror: {destination: true}`, `cache: redis`; binds `src`, `dst` | both PostgreSQL services |
| `redis`, `shared-redis` | `RedisTest`, `SharedTables/RedisTest` | `Scope-Redis-*`, `Scope-SharedTables-Redis-*` | `redis: true`, `cache: none` (`namespace: ${ns}`, or shared tables, tenant 999) | `redis-json@database-redis-adapter` |
| `sqlite`, `sqlite-shared` | `SQLiteTest`, `SharedTables/SQLiteTest` | `Scope-SQLite-*`, `Scope-SQLite-SharedTables-*`, `SQLiteTest.json` | `engine: sqlite`, `emulate_mysql` | `sqlite` |
| `memory` | `MemoryTest` | `Scope-Memory-*`, `MemoryTest.json` | `engine: memory` | none |
| `mariadb`, `shared-mariadb`, `mysql`, `shared-mysql` | `MariaDBTest`, `MySQLTest` and their `SharedTables` twins | `MariaDB-Scope-[SharedTables-]*`, `MySQL-Scope-[SharedTables-]*` | `driver` | `mysql@database-<driver>` |
| `mongo`, `mongo-shared`, `mongo-schemaless` | `MongoDBTest` and its `SharedTables`, `Schemaless` twins | `Scope-Mongo-[SharedTables-\|Schemaless-]*`, `Mongo-[SharedTables-\|Schemaless-]MongoDBTest.json` | `engine: mongo` (shared tables, tenant 999; `schemaless`) | `mongo@database-mongo` |

MariaDB and MySQL run on services `database-mariadb` (mariadb:10.11) and `database-mysql` (mysql:8.0.43), snapshotted as `mysql@database-<driver>` (the database `${ns}`: columns, indexes and rows as the server's `INFORMATION_SCHEMA` reports them). The adapter-level cases of both (`cases/Adapter-MariaDB-*.json`, `Adapter-MySQL-*.json`) are derived from the PostgreSQL ones by `recorder/derive.py` (the same steps on an adapter opened with `driver`). Each runtime's SQLite database is a file of its own under `/tmp/compat-fs/${ns}/`, compared by the `sqlite` snapshot kind (the schema in `sqlite_master` and every table's rows with their storage classes) and removed with the namespace; Memory keeps nothing outside the process. The MongoDB suites run against `compat-database-mongo` (a replica set, `services.sh`); a test's own `new Client('mongo', ...)` is pointed there too. Over Redis, collection sizes (`MEMORY USAGE` of keys named after the case namespace) and the shared adapter's database list are masked. `core-*.json`, `interop.json`, `values-*.json`, `helpers-*.json`, `validators-edge.json` and `Mongo-*.json` (besides the suites' own `Mongo-*MongoDBTest.json`) are written by hand: what the suites leave out (a Mirror's write filters and `onError` callbacks, the shared Redis cache read by both runtimes, the value classes' edges), and `Adapter-*.json` the adapters' public API called directly.

Not ported, by design: `GeneralTests::testCacheReconnect` (it runs `docker stop`/`docker start` on the cache's Redis container between two reads; the compat Redis containers are shared by both runtimes and other cases, so nothing here stops them); the planner statistics of `PostgresTest::testReadDoesNotTouchThePermissionsTable` and `testVectorSearchUsesTheIndex` (`pg_stat_*` counters, timing dependent; their Database calls are ported); what tests read directly from Redis or count on a test cache wrapper (`CountingCache` round trips: the database calls are ported, over the profile's cache); `DocumentTests::testConcurrentIncrementsOnMongoDBAllLand` (writers racing from Swoole coroutines on clients of their own: not a sequence of steps).

### Unit tests

`tests/compat/database/recorder/units.php [<path>,...]` ports the unit tests (`tests/unit/**/*Test.php`) the same way, generically: it loads each test class (and every `Tests\Unit` file it uses) from a copy that `Rewriter.php` rewrites (nikic/php-parser) so that `new <library class>(...)`, `<library class>::method(...)` and `$object->method(...)` go through the recorder, which records them as `object.new` and `object.call` when the object is the library's, storing every library object a step returns or builds under a name (`as`, `$obj`). Each test (setUp, the test with each data set, tearDown) becomes a case `<Class>::<method>[#<set>]` of `cases/Unit-<path>.json`, self-contained: its own steps and the earlier ones they refer to (a data provider's objects), with the process-wide registrations. A test that drives the library through something a case cannot carry is left out, with the reason, in `recorder/out/units-*.json`: a PHPUnit mock or stub, a test's own subclass of a library class (`CountingMemory`, `RelationshipSQLite`, anonymous classes), a pool whose connections a test closure builds, code the library calls back that is not a callback argument, a value too large to encode. `spec.d/units.json`'s `tests_waived` lists the classes none of whose tests can be ported, with the reason.

### Tasks

The PHP package ships no `bin` in its `composer.json`; its scripts (`build`, `start`, `test`, `lint`, `format`, `check`) and its programs (the benchmarking CLI `bin/cli.php`, its `bin/tasks/{load,index,query,relationships,operators}.php` the `bin/view/index.php` results page and the `stubs/Swoole/Database/*.stub.php` PHPStan stubs) are development tooling. The package lives only in the PHP image (`vendor/`), so `bin/compat coverage` discovers them through the PHP driver (`$programs`); each is waived, with its reason, under `tasks_waived` in `spec.d/core.json` (`test`, `lint`, `format` and `check` are development scripts by name and need no waiver).

CI runs the cases in five shards (`spec.json` `shards`, by file prefix): `mysql` (MariaDB and MySQL), `sqlite` (SQLite, Memory and the unit tests' `Unit-*`, which need no service), `mongo`, `redis` (the Redis adapter and caches, Pool, Mirror) and `postgres` (the rest).

```bash
bin/compat-php tests/compat/database/recorder/record.php postgres   # cases/Scope-*.json, PostgresTest.json (any profile above)
bin/compat-php tests/compat/database/recorder/units.php              # cases/Unit-*.json
python3 tests/compat/database/recorder/derive.py                     # cases/Adapter-{MariaDB,MySQL}-*.json
bin/compat record database --case Scope                              # their expectations
```

The recordings are PHP 8.3.0's, the version `composer.lock` pins, so they come from a dev image built from this checkout's lockfile. When the shared `appwrite-dev` image may be rebuilt from another branch while you record, pin it first and record from the copy: `docker tag appwrite-dev compat-db830-php`, then `COMPAT_IMAGE=compat-db830-php` on each command above (check its version with `docker run --rm --entrypoint grep compat-db830-php -A1 '"name": "utopia-php/database"' /usr/src/code/vendor/composer/installed.json`). After re-recording join cases, run `python3 tests/compat/database/recorder/ties.py --write`.

## php-std

`tests/compat/php-std` checks the PHP engine functions reimplemented in `crates/support/php-std` (the value model, type juggling, string and number formatting, JSON, `serialize`, igbinary, sorting, `filter_var`, `parse_url`) against the real built-ins. Its operations live in `ops/<area>.php` and `src/libs/php_std/<area>.rs`, one file per area.

Operations on structured values (areas `types`, `igbinary`, `sort`, and `json.decode_typed`, `serialize.unserialize_typed`) exchange them exactly, through `Typed.php` and `src/libs/php_std/typed.rs`: on input, `{"$array": [[k, v], ...]}` is an array with those keys (assigned like `$a[$k] = $v`) and `{"$object": [[k, v], ...]}` a `stdClass`, besides everything the Codec decodes; a pair may also be `{"k": k, "v": v}`, which the `object` generator can build. Results dump lists as lists, other arrays as `{"$array": ...}`, `stdClass` as `{"$object": ...}` and `-0.0` as `{"$float": "-0"}`, so key types, key order and objects are compared, not just their JSON look-alikes. Every empty array decodes to the engine's shared empty array, as `json_decode` and `unserialize` produce it.
