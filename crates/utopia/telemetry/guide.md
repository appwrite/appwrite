Telemetry is how a service reports what it does: requests served, time spent, connections open. This library gives code one way to record metrics, a `Telemetry` backend that creates instruments, and lets the application pick where they go: nowhere (`NoTelemetry`), into memory for tests (`MemoryTelemetry`), or to an OpenTelemetry collector (`OpenTelemetry`). Libraries take a backend and record; they never care which one it is. This guide covers the common tasks.

## Count events

A counter adds up events. Create it once from the backend and call `add` with the amount and attributes that split it into series. Attribute values are strings, integers, floats or booleans.

```
use utopia_telemetry::{Kind, MemoryTelemetry, Number, Telemetry, Value};

let telemetry = MemoryTelemetry::new();
let requests = telemetry.counter("http.server.requests", Some("{request}"), None);

requests.add(1, &[("route", Value::from("/v1/health")), ("status", Value::Int(200))]);
requests.add(1, &[("route", Value::from("/v1/health")), ("status", Value::Int(200))]);

assert_eq!(telemetry.values(Kind::Counter, "http.server.requests"), Some(vec![Number::Int(1), Number::Int(1)]));
```

```php
use Utopia\Telemetry\Adapter\Test;

$telemetry = new Test();
$requests = $telemetry->createCounter('http.server.requests', '{request}');

$requests->add(1, ['route' => '/v1/health', 'status' => 200]);
$requests->add(1, ['route' => '/v1/health', 'status' => 200]);

var_dump($telemetry->counters['http.server.requests']->values); // [1, 1]
```

## Measure durations

A histogram keeps a distribution. Give it bucket boundaries that fit the values, or let the backend use its defaults.

```
use utopia_telemetry::{Kind, MemoryTelemetry, Telemetry, Value};

let telemetry = MemoryTelemetry::new();
let duration = telemetry.histogram("cache.operation.duration", Some("s"), None, Some(&[0.001, 0.01, 0.1, 1.0]));

duration.record(0.004, &[("operation", Value::from("load"))]);

assert_eq!(telemetry.values(Kind::Histogram, "cache.operation.duration"), Some(vec![0.004.into()]));
```

```php
use Utopia\Telemetry\Adapter\Test;

$telemetry = new Test();
$duration = $telemetry->createHistogram('cache.operation.duration', 's', advisory: ['ExplicitBucketBoundaries' => [0.001, 0.01, 0.1, 1]]);

$duration->record(0.004, ['operation' => 'load']);

var_dump($telemetry->histograms['cache.operation.duration']->values); // [0.004]
```

## Report current values when collected

Some values are cheaper to read when the backend collects than to record on every change: pool sizes, queue depths. An observable gauge takes callbacks; each reports its own series. Gauges of one name are one instrument, so every source adds its callback.

```
use utopia_telemetry::{MemoryTelemetry, Number, Telemetry, Value};

let telemetry = MemoryTelemetry::new();
for (pool, active) in [("db", 3), ("cache", 1)] {
    telemetry
        .observable_gauge("pool.connection.active.count", None, None)
        .observe(move |observer| observer.observe(Number::Int(active), &[("pool", Value::from(pool))]));
}

let observed = utopia_async::Builder::current_thread()
    .build()?
    .block_on(telemetry.observe("pool.connection.active.count"))
    .unwrap();
assert_eq!(observed.iter().map(|o| o.value).collect::<Vec<_>>(), vec![Number::Int(3), Number::Int(1)]);
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\Telemetry\Adapter\Test;

$telemetry = new Test();
foreach (['db' => 3, 'cache' => 1] as $pool => $active) {
    $telemetry->createObservableGauge('pool.connection.active.count')
        ->observe(fn (callable $observe) => $observe($active, ['pool' => $pool]));
}

$values = [];
foreach ($telemetry->observableGauges['pool.connection.active.count']->callbacks as $callback) {
    $callback(function ($value) use (&$values) {
        $values[] = $value;
    });
}
var_dump($values); // [3, 1]
```

## Export to an OpenTelemetry collector

`OpenTelemetry` aggregates in memory and sends everything as one OTLP request when you call `collect`, typically on a timer. Instruments that never recorded are not exported, and when nothing has a data point nothing is sent.

```
use utopia_telemetry::{OpenTelemetry, Telemetry, Value};

let telemetry = OpenTelemetry::new("http://otel-collector:4318/v1/metrics", "appwrite", "api", "api-1")?;
telemetry.counter("http.server.requests", Some("{request}"), None).add(1, &[("route", Value::from("/v1/health"))]);

// On a timer: `telemetry.collect().await` sends everything recorded so far.
# Ok::<(), utopia_telemetry::Error>(())
```

```php
use Utopia\Telemetry\Adapter\OpenTelemetry;

$telemetry = new OpenTelemetry('http://otel-collector:4318/v1/metrics', 'appwrite', 'api', 'api-1');
$telemetry->createCounter('http.server.requests', '{request}')->add(1, ['route' => '/v1/health']);

// On a timer: $telemetry->collect() sends everything recorded so far.
```

## Record nothing

Code that takes a backend but has nowhere to send metrics gets `NoTelemetry`: every instrument does nothing.

```
use utopia_telemetry::{NoTelemetry, Telemetry};

let telemetry = NoTelemetry;
telemetry.histogram("ignored", None, None, None).record(1.5, &[]);
```

```php
use Utopia\Telemetry\Adapter\None;

$telemetry = new None();
$telemetry->createHistogram('ignored')->record(1.5);
```
