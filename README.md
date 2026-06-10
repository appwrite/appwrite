# Utopia Telemetry

> [!IMPORTANT]
> This repository is a read-only mirror of the [utopia-php monorepo](https://github.com/utopia-php/monorepo). Development happens in [`packages/telemetry`](https://github.com/utopia-php/monorepo/tree/main/packages/telemetry) — please open issues and pull requests there.

![Total Downloads](https://img.shields.io/packagist/dt/utopia-php/telemetry.svg)
[![Discord](https://img.shields.io/discord/564160730845151244?label=discord)](https://appwrite.io/discord)

Utopia Telemetry is a powerful Telemtry library. This library is aiming to be as simple and easy to learn and use. This library is maintained by the [Appwrite team](https://appwrite.io).

Although this library is part of the [Utopia System](https://github.com/utopia-php) project it is dependency free and can be used as standalone with any other PHP project or framework.

## Getting Started

Install using composer:

```bash
composer require utopia-php/telemetry
```

Init in your application:

```php
<?php

require_once __DIR__ . '/vendor/autoload.php';

use Utopia\Telemetry\Adapter\OpenTelemetry;

$telemetry = new OpenTelemetry('http://localhost:4318/v1/metrics', 'namespace', 'app', 'unique-instance-id');

// Periodically collect and export metrics to the configured OpenTelemetry endpoint.
$telemetry->collect();

// Example using Swoole
\Swoole\Timer::tick(60_000, fn () => $telemetry->collect());
```

## Metric Types

### Counter

A monotonically increasing counter. Only positive increments are allowed.

```php
$counter = $telemetry->createCounter('http.server.requests', '{request}', 'Total HTTP requests');

$counter->add(1);
$counter->add(1, ['method' => 'GET', 'status' => '200']);
```

### UpDownCounter

A counter that can increase or decrease. Useful for tracking values like active connections.

```php
$upDownCounter = $telemetry->createUpDownCounter('http.server.active_requests', '{request}', 'Active HTTP requests');

$upDownCounter->add(1);   // request started
$upDownCounter->add(-1);  // request finished
```

### Histogram

Records a distribution of values. Useful for measuring latency or payload sizes.

```php
$histogram = $telemetry->createHistogram('http.server.request.duration', 'ms', 'HTTP request duration');

$histogram->record(142);
$histogram->record(98.5, ['route' => '/api/users']);
```

### Gauge

Records an instantaneous measurement. Useful for values that can arbitrarily go up or down.

```php
$gauge = $telemetry->createGauge('system.memory.usage', 'By', 'Memory usage');

$gauge->record(1_073_741_824);
$gauge->record(536_870_912, ['host' => 'server-1']);
```

### ObservableGauge

An asynchronous gauge whose value is collected via a callback at export time. Useful for values that are expensive to compute or come from an external source (e.g. CPU usage, queue depth).

```php
$observableGauge = $telemetry->createObservableGauge('process.cpu.usage', '%', 'CPU usage');

$observableGauge->observe(function (callable $observer): void {
    // This callback is invoked each time metrics are collected.
    $observer(sys_getloadavg()[0] * 100);
    $observer(72.4, ['core' => '0']);
    $observer(68.1, ['core' => '1']);
});
```

## System Requirements

Utopia Framework requires PHP 8.0 or later. We recommend using the latest PHP version whenever possible.

## Copyright and license

The MIT License (MIT) [http://www.opensource.org/licenses/mit-license.php](http://www.opensource.org/licenses/mit-license.php)
