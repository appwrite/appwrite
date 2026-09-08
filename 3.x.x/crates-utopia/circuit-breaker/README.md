# utopia-circuit-breaker

Circuit breaker for Utopia. Rust port of [utopia-php/circuit-breaker](https://github.com/utopia-php/circuit-breaker).

Prevents cascading failures: after `threshold` failures the circuit opens, `open` (fallback) runs until `timeout` seconds, then half-open probes run until `success_threshold` successes close it again.

## Install

```toml
utopia-circuit-breaker = { path = "../utopia-circuit-breaker" }
```

## Usage

```rust
use utopia_circuit_breaker::CircuitBreaker;

let breaker = CircuitBreaker::with_config(2, 30, 1);
let value = breaker.call(|| "fallback", || Err::<&str, _>("failed"));
assert_eq!(value, "fallback");
```

`close` returning `Err` is PHP throwing from the closed callback.

## API Reference

### `CircuitBreaker`

| Method | Description |
|--------|-------------|
| `new` | Defaults: threshold 3, timeout 30s, success_threshold 2. |
| `with_threshold` | Threshold only; PHP timeout 30 / success 2. |
| `with_config` | `threshold`, `timeout` (seconds), `success_threshold`. |
| `with_adapter` | Full constructor including cache adapter, key, telemetry, metric prefix. |
| `call` | `open` fallback, `close` protected call (`Result`). |
| `call_half_open` | PHP `halfOpen` callback while half-open. |
| `get_state` / `is_open` / `is_closed` / `is_half_open` | Inspect state (may transition open → half-open on timeout). |
| `get_failure_count` / `get_success_count` | Counters. |
| `trip` | Force open. Idempotent. |
| `set_telemetry` | Attach `utopia-telemetry` adapter. |

### `CircuitState`

`Closed` (`closed` / 0), `Open` (`open` / 1), `HalfOpen` (`half_open` / 2).

### Adapters

| Type | PHP |
|------|-----|
| `Memory` | In-memory (PHP unit-test anonymous class). |
| `Table` | `Adapter\SwooleTable` column semantics. |
| `Redis` | `Adapter\Redis` (`redis` feature). |

Empty cache key with an adapter configured: `Key must not be empty when a cache adapter is configured.`

PHP `composer.json` has no Utopia requires; the PHP sources still call OpenTelemetry. This crate depends on [`utopia-telemetry`](../utopia-telemetry) to match that source surface.

## Tests

```bash
cargo test -p utopia-circuit-breaker
```

## License

MIT - see [LICENSE](LICENSE).
