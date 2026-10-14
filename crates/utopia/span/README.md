# Utopia Span

> [!IMPORTANT]
> This repository is a read-only mirror of [`crates/utopia/span`](https://github.com/appwrite/appwrite/tree/main/crates/utopia/span) in [appwrite/appwrite](https://github.com/appwrite/appwrite). Development happens there — please open issues and pull requests against appwrite/appwrite.

[![Discord](https://img.shields.io/discord/564160730845151244?label=discord)](https://discord.gg/GSeTUeA)

Span tracing: attributes on the current span of each task, W3C trace context, and exporters to stdout, a terminal and Sentry.

This library is maintained by the [Appwrite team](https://appwrite.io).

## Getting started

```toml
[dependencies]
utopia-span = { git = "https://github.com/utopia-rs/span" }
```

A span records one unit of work, such as a request, a job or a query: what it was, the attributes that describe it, how long it took and whether it failed. Start a span when the work starts, add attributes from anywhere while it runs, and finish it to send it to your exporters.

## Trace a request

Choose where the current span lives once at startup, start a span per request, and add attributes without passing the span around.

```rust
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

## Keep one span per task

In an async server, each request runs in its own task. The `Task` storage keeps one current span per task scope, and `Auto` falls back to the process's span outside one.

```rust
use std::sync::Arc;
use utopia_span::{Auto, Span, Task, Value};

Span::set_storage(Some(Arc::new(Auto::new())));

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
```

## Continue a trace across services

Pass the current span's `traceparent` header to the services you call; they start their spans from it, so all spans share one trace id.

```rust
use utopia_span::Span;

let incoming = Span::new("gateway");
let header = incoming.traceparent();

let downstream = Span::init("users.get", Some(&header));
assert_eq!(downstream.get("span.trace_id"), incoming.get("span.trace_id"));
assert_eq!(downstream.get("span.parent_id"), incoming.get("span.id"));
```

## Export spans

Exporters receive finished spans. `Stdout` writes JSON lines for log collectors, `Pretty` writes readable output for local development, and `Sentry` reports failed spans. Each takes a sampler to choose which spans it exports.

```rust
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

## Report errors to Sentry

The Sentry exporter sends spans at warning level or above that carry an error. Attributes become contexts by default; a classifier turns low-cardinality ones into searchable tags.

```rust
use utopia_span::exporter::SentryField;
use utopia_span::{Exporter, Failure, Level, Sentry, Span};

let sentry = Sentry::new("https://publickey@o0.ingest.sentry.io/42")?
    .environment("production")
    .classifier(|key| if key.starts_with("tenant.") { SentryField::Tag } else { SentryField::Context });

let span = Span::new("api.request");
span.set("tenant.id", "acme");
span.finish_with(Some(Level::Error), Some(Failure::new("RuntimeException", "database unavailable")));

assert!(sentry.sample(&span));
```

## License

BSD-3-Clause. See [LICENSE](LICENSE).
