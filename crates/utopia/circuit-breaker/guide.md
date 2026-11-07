A circuit breaker sits in front of a dependency (a database, a cache, another service) and stops calling it while it keeps failing, so callers get a fallback at once instead of waiting on errors, and the dependency gets room to recover. This library judges a dependency on the share of recent calls that failed, lets a few probes through after a cool-down, and can share its verdict between processes. The breaker is async, so the examples run it with `utopia_async::run`. This guide covers the common tasks.

## Guard a call

`call` runs the call while the circuit is closed and returns its value; when the call fails, or the circuit is open, it returns the fallback instead. The call is a future returning a `Result`; an `Err` counts as a failure.

```
use utopia_circuit_breaker::{CircuitBreaker, Options};

let breaker = CircuitBreaker::new(Options::default())?;

let value = utopia_async::run(async {
    breaker.call(|| "cached copy", async { Ok::<_, &str>("fresh value") }).await
})?;

assert_eq!(value, "fresh value");
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\CircuitBreaker\CircuitBreaker;

$breaker = new CircuitBreaker();

echo $breaker->call(open: fn () => 'cached copy', close: fn () => 'fresh value'), "\n"; // fresh value
```

## Decide when to open

A circuit opens once the window holds at least `minimum_throughput` calls and the share that failed reaches `failure_ratio`. A burst of failures among healthy traffic does not open it; sustained failure does.

```
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
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\CircuitBreaker\CircuitBreaker;

$breaker = new CircuitBreaker(window: 60, failureRatio: 0.5, minimumThroughput: 4);

for ($i = 0; $i < 4; $i++) {
    $breaker->call(open: fn () => 'fallback', close: fn () => throw new \RuntimeException('timeout'));
}
echo $breaker->getState()->value, "\n"; // open
echo $breaker->call(open: fn () => 'fallback', close: fn () => 'skipped'), "\n"; // fallback
```

## Probe for recovery

After `timeout` seconds an open circuit is half open: up to `half_open_permitted_calls` probes run at once, `success_threshold` successes close it, and one failure opens it again. `call_with_probe` runs a lighter call as the probe.

```
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
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\CircuitBreaker\CircuitBreaker;

$breaker = new CircuitBreaker(timeout: 0, successThreshold: 1);
$breaker->trip();

echo $breaker->call(open: fn () => 'fallback', close: fn () => 'full query', halfOpen: fn () => 'ping'), "\n"; // ping
echo $breaker->getState()->value, "\n"; // closed
```

## Share the verdict between processes

Breakers with the same key and store share their state: when one opens the circuit, the others see it open. Use `Redis` across hosts, or a `Table` within one process. Each breaker still measures its own window.

```
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
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\CircuitBreaker\Adapter\SwooleTable;
use Utopia\CircuitBreaker\CircuitBreaker;

$table = SwooleTable::createTable();
$first = new CircuitBreaker(cache: new SwooleTable($table), key: 'users-api');
$second = new CircuitBreaker(cache: new SwooleTable($table), key: 'users-api');

$first->trip();
var_dump($second->isOpen()); // bool(true)
```

## Report metrics

With a telemetry backend, a breaker counts its calls, fallbacks, failures and transitions, and reports its state as observable gauges read at collection.

```
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
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\CircuitBreaker\CircuitBreaker;
use Utopia\Telemetry\Adapter\Test;

$telemetry = new Test();
$breaker = new CircuitBreaker(telemetry: $telemetry);
$breaker->call(open: fn () => 'fallback', close: fn () => 'ok');

var_dump($telemetry->counters['breaker.calls']->values); // [1]
```
