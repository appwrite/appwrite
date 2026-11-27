A span records one unit of work, such as a request, a job or a query: what it was, the attributes that describe it, how long it took and whether it failed. Start a span when the work starts, add attributes from anywhere while it runs, and finish it to send it to your exporters.

## Trace a request

Choose where the current span lives once at startup, start a span per request, and add attributes without passing the span around.

```
use std::sync::Arc;
use utopia_span::{Memory, Span, Value};

Span::set_storage(Some(Arc::new(Memory::new())));

let span = Span::init("http.request", None);
Span::add("http.method", "POST");
Span::add("http.path", "/v1/users");
Span::add("http.response.status_code", 201);

assert_eq!(span.get("http.response.status_code"), Value::Int(201));
span.finish();
assert_eq!(span.get("level"), Value::from("info"));
```

```php
use Utopia\Span\Span;
use Utopia\Span\Storage\Memory;

Span::setStorage(new Memory());

$span = Span::init('http.request');
Span::add('http.method', 'POST');
Span::add('http.path', '/v1/users');
Span::add('http.response.status_code', 201);

echo $span->get('http.response.status_code'), "\n"; // 201
$span->finish();
echo $span->get('level'), "\n"; // info
```

## Keep one span per task

In an async server, each request runs in its own task. The `Task` storage keeps one current span per task scope, and `Auto` falls back to the process's span outside one.

```
use std::sync::Arc;
use utopia_span::{Auto, Span, Task, Value};

Span::set_storage(Some(Arc::new(Auto::new())));

# tokio::runtime::Builder::new_current_thread().build().unwrap().block_on(async {
let first = Task::scope(async {
    let span = Span::init("job", None);
    Span::add("job.id", "a");
    span
});
let second = Task::scope(async {
    let span = Span::init("job", None);
    Span::add("job.id", "b");
    span
});
let (first, second) = (first.await, second.await);
assert_eq!(first.get("job.id"), Value::from("a"));
assert_eq!(second.get("job.id"), Value::from("b"));
# });
```

```php
use Utopia\Span\Span;
use Utopia\Span\Storage\Auto;

Span::setStorage(new Auto());

\Swoole\Coroutine\run(function () {
    \Swoole\Coroutine::create(function () {
        $span = Span::init('job');
        Span::add('job.id', 'a');
        echo $span->get('job.id'), "\n"; // a
    });
    \Swoole\Coroutine::create(function () {
        $span = Span::init('job');
        Span::add('job.id', 'b');
        echo $span->get('job.id'), "\n"; // b
    });
});
```

## Continue a trace across services

Pass the current span's `traceparent` header to the services you call; they start their spans from it, so all spans share one trace id.

```
use utopia_span::Span;

let incoming = Span::new("gateway");
let header = incoming.traceparent();

let downstream = Span::init("users.get", Some(&header));
assert_eq!(downstream.get("span.trace_id"), incoming.get("span.trace_id"));
assert_eq!(downstream.get("span.parent_id"), incoming.get("span.id"));
```

```php
use Utopia\Span\Span;

$incoming = new Span('gateway');
$header = $incoming->getTraceparent();

$downstream = Span::init('users.get', $header);
var_dump($downstream->get('span.trace_id') === $incoming->get('span.trace_id')); // bool(true)
var_dump($downstream->get('span.parent_id') === $incoming->get('span.id'));     // bool(true)
```

## Export spans

Exporters receive finished spans. `Stdout` writes JSON lines for log collectors, `Pretty` writes readable output for local development, and `Sentry` reports failed spans. Each takes a sampler to choose which spans it exports.

```
use std::sync::Arc;
use utopia_span::{Exporter, Failure, Span, Stdout};

let errors_only = Stdout::new().sampler(|span: &Span| span.error().is_some());

let ok = Span::new("job");
let failed = Span::new("job");
failed.set_error(Failure::new("RuntimeException", "timeout"));

assert!(!errors_only.sample(&ok));
assert!(errors_only.sample(&failed));

Span::set_exporters(vec![Arc::new(errors_only)]);
```

```php
use Utopia\Span\Exporter\Stdout;
use Utopia\Span\Span;

$errorsOnly = new Stdout(fn (Span $span) => $span->getError() !== null);

$ok = new Span('job');
$failed = new Span('job');
$failed->setError(new \RuntimeException('timeout'));

var_dump($errorsOnly->sample($ok));     // bool(false)
var_dump($errorsOnly->sample($failed)); // bool(true)

Span::setExporters($errorsOnly);
```

## Report errors to Sentry

The Sentry exporter sends spans at warning level or above that carry an error. Attributes become contexts by default; a classifier turns low-cardinality ones into searchable tags.

```
use utopia_span::exporter::SentryField;
use utopia_span::{Exporter, Failure, Level, Sentry, Span};

let sentry = Sentry::new("https://publickey@o0.ingest.sentry.io/42")?
    .environment("production")
    .classifier(|key| if key.starts_with("tenant.") { SentryField::Tag } else { SentryField::Context });

let span = Span::new("api.request");
span.set("tenant.id", "acme");
span.finish_with(Some(Level::Error), Some(Failure::new("RuntimeException", "database unavailable")));

assert!(sentry.sample(&span));
# Ok::<(), utopia_span::Error>(())
```

```php
use Utopia\Span\Exporter\Sentry;
use Utopia\Span\Exporter\SentryField;
use Utopia\Span\Level;
use Utopia\Span\Span;

$sentry = new Sentry(
    dsn: 'https://publickey@o0.ingest.sentry.io/42',
    environment: 'production',
    classifier: fn (string $key) => str_starts_with($key, 'tenant.') ? SentryField::Tag : SentryField::Context,
);

$span = new Span('api.request');
$span->set('tenant.id', 'acme');
$span->finish(level: Level::Error, error: new \RuntimeException('database unavailable'));

var_dump($sentry->sample($span)); // bool(true)
```
