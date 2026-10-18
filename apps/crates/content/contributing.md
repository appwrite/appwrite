## Set up

The crates live in [appwrite/appwrite](https://github.com/appwrite/appwrite): every Utopia crate in `crates/utopia/<name>`, and `php-std` in `crates/support/php-std`. Each repository under [utopia-rs](https://github.com/utopia-rs) is a read-only mirror of one of those directories, so open issues and pull requests against appwrite/appwrite. Run every command on this page from the root of a checkout.

You need Rust 1.97, the version CI builds and lints with. Comparing with PHP and running benchmarks also needs Docker.

```bash
git clone https://github.com/appwrite/appwrite.git
cd appwrite
cargo build --workspace
```

A crate's README and its page on this site both start from the `guide.md` next to its `Cargo.toml`. The guide is compiled and run with the crate's doctests, so its examples are tests too.

## Test

Before you open a pull request, run the checks CI runs on the whole workspace:

```bash
cargo fmt --all
cargo clippy --workspace --all-targets -- -D warnings
cargo test --workspace
```

While you work on one crate, run them for that crate only:

```bash
cargo test -p utopia-dns
cargo test -p utopia-dns -- zone
cargo test -p utopia-dns --doc
cargo clippy -p utopia-dns --all-targets -- -D warnings
```

The second command runs only the tests whose name contains `zone`, and `--doc` runs the guide and the doc examples. Async tests use `#[utopia_async::test]`, and crates spawn, sleep and connect through `utopia-async`, never through `tokio` directly.

## Measure compile times

`bin/compat build` times how long each crate takes to compile from scratch, with its third-party dependencies already built. It uses its own target directory (`target/compat-build`), so your everyday build is not touched.

```bash
bin/compat build dns http
bin/compat build --write
```

With no names it times every crate. `--write` adds the run to `tests/compat/build.json`, which keeps the latest 10 runs for the Migration dashboard. Run it on a quiet machine, never alongside benchmarks or other builds.

## Compare with PHP

Every crate ports a Utopia PHP library and must behave exactly like it. `bin/compat` sends the same operations to a PHP driver and a Rust driver, then compares return values, errors and the state each leaves in shared services. The [compat README](https://github.com/appwrite/appwrite/blob/main/tests/compat/README.md) has every option.

The fast loop needs no PHP. It replays the results PHP recorded for each case against Rust:

```bash
COMPAT_LIB=dns cargo test -p compat
COMPAT_LIB=dns COMPAT_CASE=zone cargo test -p compat
COMPAT_SERVICES=1 COMPAT_LIB=cache cargo test -p compat
```

`COMPAT_CASE` matches part of `<lib>/<file>#<case>`. Cases that need Redis, a database or another service run only with `COMPAT_SERVICES=1` and the dev stack up.

To run both runtimes live, start the dev stack. A library that needs more services ships `tests/compat/<lib>/services.sh`, which starts them as throwaway containers:

```bash
docker compose up -d
tests/compat/cache/services.sh up
```

| Command | What it does |
|---|---|
| `bin/compat run dns` | Runs every case on PHP and Rust and compares them. Add `--case <text>` to narrow it, `-v` for details. |
| `bin/compat fuzz dns` | Sends both runtimes generated inputs. `--op`, `--iterations` and `--seed` control the run. |
| `bin/compat call dns <op> '<args json>'` | Runs one operation and prints both results. |
| `bin/compat coverage dns` | Lists the PHP API the cases cover, and the PHP tests and tasks ported. |
| `bin/compat check dns` | The conversion gate: coverage, run and fuzz. |
| `bin/compat record dns` | Stores PHP's results in the case files, for the replay above. |
| `bin/compat examples` | Runs every doc example and the PHP version written after it. |
| `bin/compat ci` | Runs and fuzzes every library, as CI does. |

A difference is a Rust bug, a PHP quirk to keep and record, or a missing case. Never loosen a comparison to make it pass. [CONVERSION.md](https://github.com/appwrite/appwrite/blob/main/crates/CONVERSION.md) lists everything a converted library needs.

## Benchmark against PHP

A library's hot paths are benchmarked against its PHP library: parsing, validation, cache reads, route matching. Each benchmark is a name both drivers declare, and only the library call is timed.

```bash
bin/compat bench dns
bin/compat bench dns --write
```

Both drivers run in Linux containers on the dev stack's network, Rust as a release build. Each benchmark warms up, then takes 15 samples and reports the median time per call, CPU time and memory. The command fails when Rust is slower, or when a benchmark exists on one side only. Two kinds of benchmark are listed in the library's `spec.json` with a reason:

- `bench.bound`: bound by work both runtimes wait on equally, such as a Redis round trip. These must reach parity (0.95x) instead.
- `bench.compat`: a PHP-compatibility codec no Rust hot path uses. These are reported but never fail the command.

`--write` records the run in `tests/compat/<lib>/bench.json`, which the library's Benchmarks page shows. Timings from different machines are not comparable, so run it on a quiet machine, one library at a time. `--host` builds and runs Rust on your machine instead, for profiling. On macOS that adds Docker Desktop's port forwarding to every service round trip, so don't record those timings.

## Test the Appwrite API on PHP and Rust

The API is moving to Rust service by service. Traefik sends the migrated routes to the `appwrite-rust` container, and the PHP E2E suites run against it through the proxy. See [rfc/rust.md](https://github.com/appwrite/appwrite/blob/main/rfc/rust.md).

```bash
COMPOSE_PROFILES=postgresql,rust docker compose up -d --wait
docker compose exec -e _APP_E2E_ENDPOINT=http://appwrite.test/v1 appwrite \
  vendor/bin/paratest --functional tests/e2e/Services/Users
```

To record a run on a running dev stack:

```bash
bin/compat appwrite e2e Users --write
bin/compat appwrite e2e Users --side php --write
```

The first writes the Rust run to `tests/e2e/results/users.rust.json`, and the second the PHP run next to it. `tests/rust/run.sh` runs a port of the Users assertions against Rust alone, with no PHP.

## Benchmark the Appwrite API against PHP

`tests/benchmarks/users-compare.sh` drives a read-heavy Users workload with k6 against PHP (`:9501`) and Rust (`:9530`) on the same stack. It runs at fixed rates to compare CPU per request and memory at equal load, then closed-loop for maximum throughput.

```bash
bash tests/benchmarks/users-compare.sh
RATES="200 800" DURATION=120s bash tests/benchmarks/users-compare.sh
RESULTS=tests/benchmarks/results/users.json bash tests/benchmarks/users-compare.sh
```

`RESULTS` writes the run as JSON, which the Migration dashboard shows. Before you run it:

- Export `APPWRITE_ADMIN_EMAIL` with the email of an owner of the instance's organization.
- Raise PostgreSQL's `max_connections`. PHP's workers hold one connection each, which alone uses up the default of 100 under load.

Run it on dedicated hardware before and after every performance change, and attach the report to the pull request.
