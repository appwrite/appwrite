# Utopia Circuit Breaker

> [!IMPORTANT]
> This repository is a read-only mirror of [`crates/utopia/circuit-breaker`](https://github.com/appwrite/appwrite/tree/main/crates/utopia/circuit-breaker) in [appwrite/appwrite](https://github.com/appwrite/appwrite). Development happens there — please open issues and pull requests against appwrite/appwrite.

[![Discord](https://img.shields.io/discord/564160730845151244?label=discord)](https://discord.gg/GSeTUeA)

Circuit breakers judged on a rolling failure rate, with half-open probes, state shared through Redis or an in-process table, and telemetry.

This library is maintained by the [Appwrite team](https://appwrite.io).

## Getting started

```toml
[dependencies]
utopia-circuit-breaker = { git = "https://github.com/utopia-rs/circuit-breaker" }
```

A circuit breaker sits in front of a dependency (a database, a cache, another service) and stops calling it while it keeps failing, so callers get a fallback at once instead of waiting on errors, and the dependency gets room to recover. This library judges a dependency on the share of recent calls that failed, lets a few probes through after a cool-down, and can share its verdict between processes. The breaker is async, so the examples run it with `utopia_async::run`. This guide covers the common tasks.

## Guard a call

`call` runs the call while the circuit is closed and returns its value; when the call fails, or the circuit is open, it returns the fallback instead. The call is a future returning a `Result`; an `Err` counts as a failure.

```rust
use utopia_circuit_breaker::{CircuitBreaker, Options};

let breaker = CircuitBreaker::new(Options::default())?;

let value = utopia_async::run(async {
    breaker.call(|| "cached copy", async { Ok::<_, &str>("fresh value") }).await
})?;

assert_eq!(value, "fresh value");
```

## Decide when to open

A circuit opens once the window holds at least `minimum_throughput` calls and the share that failed reaches `failure_ratio`. A burst of failures among healthy traffic does not open it; sustained failure does.

```rust
use utopia_circuit_breaker::{CircuitBreaker, Options, State};

let breaker = CircuitBreaker::new(Options { window: 60, failure_ratio: 0.5, minimum_throughput: 4, ..Options::default() })?;

utopia_async::run(async {
    for _ in 0..4 {
        breaker.call(|| "fallback", async { Err::<&str, _>("timeout") }).await?;
    }
    assert_eq!(breaker.state().await?, State::Open);
    assert_eq!(breaker.call(|| "fallback", async { Ok::<_, &str>("skipped") }).await?, "fallback");
    Ok::<_, utopia_circuit_breaker::Error>(())
})?;
```

## Probe for recovery

After `timeout` seconds an open circuit is half open: up to `half_open_permitted_calls` probes run at once, `success_threshold` successes close it, and one failure opens it again. `call_with_probe` runs a lighter call as the probe.

```rust
use utopia_circuit_breaker::{CircuitBreaker, Options, State};

let breaker = CircuitBreaker::new(Options { timeout: 0, success_threshold: 1, ..Options::default() })?;

utopia_async::run(async {
    breaker.trip().await?;
    let value = breaker
        .call_with_probe(|| "fallback", async { Ok::<_, &str>("full query") }, async { Ok("ping") })
        .await?;
    assert_eq!(value, "ping");
    assert_eq!(breaker.state().await?, State::Closed);
    Ok::<_, utopia_circuit_breaker::Error>(())
})?;
```

## Share the verdict between processes

Breakers with the same key and store share their state: when one opens the circuit, the others see it open. Use `Redis` across hosts, or a `Table` within one process. Each breaker still measures its own window.

```rust
use std::sync::Arc;

use utopia_circuit_breaker::{CircuitBreaker, Options, SharedTable, State, Table};

let table = SharedTable::default();
let options = Options { key: "users-api".into(), ..Options::default() };
let first = CircuitBreaker::with_cache(options.clone(), Arc::new(Table::new(table.clone(), "breaker:")))?;
let second = CircuitBreaker::with_cache(options, Arc::new(Table::new(table, "breaker:")))?;

utopia_async::run(async {
    first.trip().await?;
    assert_eq!(second.state().await?, State::Open);
    Ok::<_, utopia_circuit_breaker::Error>(())
})?;
```

## Report metrics

With a telemetry backend, a breaker counts its calls, fallbacks, failures and transitions, and reports its state as observable gauges read at collection.

```rust
use std::sync::Arc;

use utopia_circuit_breaker::{CircuitBreaker, Options};
use utopia_telemetry::{Kind, MemoryTelemetry};

let telemetry = MemoryTelemetry::new();
let breaker = CircuitBreaker::new(Options::default())?;
breaker.set_telemetry(Arc::new(telemetry.clone()));

utopia_async::run(async {
    breaker.call(|| "fallback", async { Ok::<_, &str>("ok") }).await
})?;

assert_eq!(telemetry.values(Kind::Counter, "breaker.calls"), Some(vec![1.into()]));
```

## Contributing

Development happens in [appwrite/appwrite](https://github.com/appwrite/appwrite), where this crate lives at [`crates/utopia/circuit-breaker`](https://github.com/appwrite/appwrite/tree/main/crates/utopia/circuit-breaker). Run these commands from a checkout of it. The [contributing guide](https://utopia-docs.appwrite.network/contributing) covers each step.

### Test

```bash
cargo test -p utopia-circuit-breaker
cargo clippy -p utopia-circuit-breaker --all-targets -- -D warnings
cargo fmt --all --check
```

### Compare with PHP

Replay the results PHP recorded for every case against Rust, without PHP:

```bash
COMPAT_SERVICES=1 COMPAT_LIB=circuit-breaker cargo test -p compat
```

Without `COMPAT_SERVICES=1` the cases that need a service are skipped.

Run every case on both runtimes, fuzz both with generated inputs, then run the whole conversion check. These need the dev stack (`docker compose up -d`):

```bash
bin/compat run circuit-breaker
bin/compat fuzz circuit-breaker
bin/compat check circuit-breaker
```

### Benchmark

```bash
bin/compat bench circuit-breaker
```

This times every benchmark on PHP and on a release build of Rust, both in containers on the dev stack. It fails if Rust is slower. Add `--write` to record the run in [`tests/compat/circuit-breaker/bench.json`](https://github.com/appwrite/appwrite/blob/main/tests/compat/circuit-breaker/bench.json). Run it on a quiet machine, one library at a time.

## License

BSD-3-Clause. See [LICENSE](LICENSE).
