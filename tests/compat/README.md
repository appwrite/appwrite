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

`cargo test -p compat` replays every recorded expectation against Rust without PHP (cases needing Redis or a database only with `COMPAT_SERVICES=1`), so the workspace test run guards compatibility too.

Environment: `COMPAT_PHP` / `COMPAT_RUST` override the driver commands, `COMPAT_TIMEOUT` the seconds to wait for a reply (60), `COMPAT_SEED` the fuzz seed, `COMPAT_IMAGE` / `COMPAT_NETWORK` the PHP driver's image and network. Service endpoints for each runtime and for the runner are in `compat.json` (`${VAR}` reads the environment, then `.env`): `php` on the dev stack's network, `rust` and `runner` through the host's forwarded ports, and optionally `rust-container`, where a Rust driver in a container on the network reaches the service (`compat bench`; see [Benchmarks](#benchmarks)).

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

A large spec can be split: `spec.d/*.json` files hold more `ops`, `generators`, `waivers`, `tests_waived`, `quirks` and `deviations` (one file per area or owner), merged into `spec.json`; a name defined twice is an error.

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

## Snapshots

The runner snapshots shared state itself after each case, per namespace, for the kinds in `state` (see `crates/tools/compat/src/runner/snapshot.rs`):

| Kind | Captures |
|---|---|
| `redis` | every key containing the namespace: type, value, whether it expires |
| `files` | every file under `/tmp/compat-fs/${ns}/` (mounted into the PHP driver's container at the same path): relative path and content |

A library that writes to another service adds a kind there (PostgreSQL tables, MongoDB collections, files), touching only resources whose names contain the namespace.

## php-std

`tests/compat/php-std` checks the PHP engine functions reimplemented in `crates/support/php-std` (the value model, type juggling, string and number formatting, JSON, `serialize`, igbinary, sorting, `filter_var`, `parse_url`) against the real built-ins. Its operations live in `ops/<area>.php` and `src/libs/php_std/<area>.rs`, one file per area.

Operations on structured values (areas `types`, `igbinary`, `sort`, and `json.decode_typed`, `serialize.unserialize_typed`) exchange them exactly, through `Typed.php` and `src/libs/php_std/typed.rs`: on input, `{"$array": [[k, v], ...]}` is an array with those keys (assigned like `$a[$k] = $v`) and `{"$object": [[k, v], ...]}` a `stdClass`, besides everything the Codec decodes; a pair may also be `{"k": k, "v": v}`, which the `object` generator can build. Results dump lists as lists, other arrays as `{"$array": ...}`, `stdClass` as `{"$object": ...}` and `-0.0` as `{"$float": "-0"}`, so key types, key order and objects are compared, not just their JSON look-alikes. Every empty array decodes to the engine's shared empty array, as `json_decode` and `unserialize` produce it.
