# Utopia Span

> [!IMPORTANT]
> This repository is a read-only mirror of the [utopia-php monorepo](https://github.com/utopia-php/monorepo). Development happens in [`packages/span`](https://github.com/utopia-php/monorepo/tree/main/packages/span) — please open issues and pull requests there.

A simple, memory-safe span tracing library for PHP with Swoole coroutine support.

## Installation

```bash
composer require utopia-php/span
```

## Quick start

```php
use Utopia\Span\Span;
use Utopia\Span\Storage;
use Utopia\Span\Exporter;

// Bootstrap once at startup
Span::setStorage(new Storage\Auto());
Span::setExporters(new Exporter\Stdout());

// Create a span
$span = Span::init('http.request');
$span->set('user.id', '123');
$span->finish();
```

## Usage

### Setting attributes

Everything is a flat key-value attribute. Only scalar types are allowed (string, int, float, bool, null):

```php
$span = Span::init('api.request');
$span->set('service.name', 'api');
$span->set('request.duration_ms', 42.5);
$span->set('request.cached', true);
$span->finish();
```

### Built-in attributes

Spans automatically include these attributes:

| Attribute          | Description                         |
| ------------------ | ----------------------------------- |
| `span.trace_id`    | Unique trace identifier (32 hex chars) |
| `span.id`          | Unique span identifier (16 hex chars)  |
| `span.started_at`  | Start timestamp in seconds (float)  |
| `span.finished_at` | End timestamp in seconds (float)    |
| `span.duration`    | Duration in seconds (float)         |
| `level`            | `error` if error set, `info` otherwise |

### Static helpers

Use static methods anywhere in your codebase without passing the span around:

```php
// Set attribute on current span
Span::add('db.query_count', 5);

// Add warning details as attributes
Span::add('warning', 'retry scheduled');
```

### Error handling

Pass the exception to `finish()` when the span ends because of an error:

```php
$span = Span::init('api.request');

try {
    // ...
} catch (Throwable $e) {
    $span->finish(error: $e);
    throw $e;
}
```

Exporters access the exception via `$span->getError()` and extract what they need (message, trace, etc.).

Use `setError()` when you need to record the error before the span ends, such as before cleanup work that should still be included in the same span.

The `level` attribute is set when the span finishes. It defaults to `Level::Error` when an error is captured and `Level::Info` otherwise. Pass a `Level` to `finish()` to override it:

```php
use Utopia\Span\Level;

$span->finish(level: Level::Warn, error: $e);
```

Level names follow Grafana Loki's `detected_level` vocabulary (note `warn`). The Sentry exporter only sends spans at `Level::Warn` or above — translating to Sentry's own terms (`warn` → `warning`) — and reports each at its own level; lower levels (`Info`, `Debug`) are skipped.

Use attributes for warning details that do not end the span:

```php
Span::add('warning', 'retry scheduled');
Span::add('warning.message', $message);
```

### Distributed tracing

Propagate trace context across services using W3C Trace Context headers:

```php
// Service A: outgoing request
$client->post('/api/downstream', $payload, [
    'traceparent' => Span::traceparent(),
]);

// Service B: incoming request
$span = Span::init('http.request', $request->getHeader('traceparent'));
```

### Sampling

Each exporter decides which spans it accepts via its `sample()` method. Most built-in exporters accept a `sampler` closure as their first constructor argument:

```php
Span::setExporters(
    new Exporter\Stdout(
        sampler: fn(Span $s) =>
            $s->getError() !== null ||          // errors
            $s->get('span.duration') > 5.0 ||   // slow requests (>5s)
            $s->get('plan') === 'enterprise'    // enterprise customers
    ),
);
```

The Sentry exporter is hard-wired to error spans only; a custom sampler is composed with that filter and can further restrict — but not broaden — what is sent.

## Storage backends

| Backend             | Use Case                                |
| ------------------- | --------------------------------------- |
| `Storage\Auto`      | Auto-detects best storage (recommended) |
| `Storage\Memory`    | Plain PHP (FPM, CLI)                    |
| `Storage\Coroutine` | Swoole coroutine contexts               |

## Exporters

| Exporter           | Description                          |
| ------------------ | ------------------------------------ |
| `Exporter\Stdout`  | JSON to stdout/stderr                |
| `Exporter\Pretty`  | Colourful human-readable output      |
| `Exporter\Sentry`  | Sentry events (Issues)               |
| `Exporter\None`    | Discard (for testing)                |

### Stdout exporter

```php
Span::setExporters(new Exporter\Stdout(
    maxTraceFrames: 3  // default, limits error stacktrace length
));
```

Outputs JSON to stdout (info) or stderr (errors). Exports every span by default; pass `sampler:` to filter.

### Pretty exporter

```php
Span::setExporters(new Exporter\Pretty(
    maxTraceFrames: 3,  // default, limits error stacktrace length
    width: 60           // default, separator line width
));
```

Colourful, multi-line output for local development. Attributes are displayed with aligned values, duration is colour-coded (green < 100ms, yellow < 1s, red >= 1s), and errors are highlighted in red. Writes to stdout (info) or stderr (errors).

```
http.request · 12.3ms · abc12345

  http.method GET
  http.url    /api/users
  user.id     42

────────────────────────────────────────────────────────────
```

### Sentry exporter

```php
Span::setExporters(new Exporter\Sentry(
    dsn: 'https://key@sentry.io/123',
    environment: 'production'  // optional
));
```

Only exports error spans with full stacktraces. Non-error spans are skipped, even if you pass a custom `sampler`.

Pass any PSR-18 client as `client` to control the HTTP transport. Workers can
inject a coroutine-safe `Utopia\Client\Pool`. When omitted, the exporter creates
a `Utopia\Client` using its cURL adapter.

```php
Span::setExporters(new Exporter\Sentry(
    dsn: 'https://key@sentry.io/123',
    client: $clientPool,
));
```

### Custom exporter

```php
use Utopia\Span\Exporter\Exporter;
use Utopia\Span\Span;

class MyExporter implements Exporter
{
    public function sample(Span $span): bool
    {
        return true; // export every span
    }

    public function export(Span $span): void
    {
        $data = $span->getAttributes();
        $error = $span->getError();
        // Send to your backend
    }
}
```

## Testing

Disable or capture spans in tests:

```php
// Option 1: Discard all spans
Span::setExporters(new Exporter\None());

// Option 2: Capture for assertions
$spans = [];
Span::setExporters(new class($spans) implements Exporter {
    public function __construct(private array &$spans) {}
    public function sample(Span $span): bool { return true; }
    public function export(Span $span): void {
        $this->spans[] = $span;
    }
});

// Run code...

$this->assertCount(1, $spans);
$this->assertEquals('http.request', $spans[0]->get('action'));
```

## API reference

### Span (static)

| Method                                               | Description                           |
| ---------------------------------------------------- | ------------------------------------- |
| `setStorage(?Storage $storage)`                      | Set the storage backend (null clears) |
| `setExporters(Exporter ...$exporters)`               | Replace all exporters                 |
| `init(string $action, ?string $traceparent): Span`   | Create and store a new span           |
| `current(): ?Span`                                   | Get the current span                  |
| `add(string $key, scalar $value)`                    | Set attribute on current span         |
| `traceparent(): ?string`                             | Get traceparent header from current span |

### Span (instance)

| Method                                  | Description                        |
| --------------------------------------- | ---------------------------------- |
| `set(string $key, scalar $value): self` | Set an attribute                   |
| `get(string $key): scalar`              | Get an attribute                   |
| `getAttributes(): array`                | Get all attributes                 |
| `getAction(): string`                   | Get the span action                |
| `setError(Throwable $e): self`          | Capture exception                  |
| `getError(): ?Throwable`                | Get captured exception             |
| `getTraceparent(): string`              | Get W3C traceparent header value   |
| `finish(?Level $level = null, ?Throwable $error = null): void` | End span and export |

### Attribute conventions

| Prefix   | Description            |
| -------- | ---------------------- |
| `span.*` | Built-in span metadata |
| `*`      | User-defined           |

## License

MIT
