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
- **Errors** are `{"$error": {"class": "<PHP exception class>", "message": "..."}}`. Rust adapters report the PHP class of each error (every crate's error enum has `php_class()`).
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
```

`cargo test -p compat` replays every recorded expectation against Rust without PHP (cases needing Redis or a database only with `COMPAT_SERVICES=1`), so the workspace test run guards compatibility too.

Environment: `COMPAT_PHP` / `COMPAT_RUST` override the driver commands, `COMPAT_TIMEOUT` the seconds to wait for a reply (60), `COMPAT_SEED` the fuzz seed, `COMPAT_IMAGE` / `COMPAT_NETWORK` the PHP driver's image and network. Service endpoints for each runtime and for the runner are in `compat.json` (`${VAR}` reads the environment, then `.env`).

The dev stack must be running (`docker compose up -d`) for the PHP driver image and the services.

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

## Snapshots

The runner snapshots shared state itself after each case, per namespace, for the kinds in `state` (see `crates/tools/compat/src/runner/snapshot.rs`):

| Kind | Captures |
|---|---|
| `redis` | every key containing the namespace: type, value, whether it expires |

A library that writes to another service adds a kind there (PostgreSQL tables, MongoDB collections, files), touching only resources whose names contain the namespace.

## php-std

`tests/compat/php-std` checks the PHP engine functions reimplemented in `crates/support/php-std` (type juggling, string and number formatting, JSON, `filter_var`, `parse_url`) against the real built-ins. Its operations live in `ops/<area>.php` and `src/libs/php_std/<area>.rs`, one file per area.
