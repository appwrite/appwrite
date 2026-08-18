# utopia-span

Span tracing for Utopia. Rust port of [utopia-php/span](https://github.com/utopia-php/span).

Storage backends keep the current span so `Span::add` works without threading a handle. Exporters receive finished spans (NDJSON, pretty terminal, Sentry Issues, or discard).

## Install

```toml
utopia-span = { path = "../utopia-span" }
```

## Usage

```rust
use std::sync::Arc;
use utopia_span::{Memory, Span, Stdout, Storage};

Span::set_storage(Some(Arc::new(Memory::new())));
Span::set_exporters([Arc::new(Stdout::new()) as _]);

let span = Span::init("http.request", None);
span.set("user.id", "123");
span.finish();
```

### Static helpers

```rust
Span::add("db.query_count", 5);
let header = Span::traceparent(); // W3C traceparent for downstream calls
```

### Errors and levels

```rust
use utopia_span::Level;

let span = Span::init("api.request", None);
if let Err(e) = do_work() {
    span.fail(e); // level defaults to error
}
// Or override: span.fail_with(Level::Warn, e);
```

Level names follow Grafana Loki (`warn`, not `warning`). Sentry only exports `Warn` / `Error` / `Fatal`.

### Distributed tracing

```rust
let span = Span::init("http.request", request_traceparent);
let outgoing = span.get_traceparent();
```

## Prelude

```rust
use utopia_span::prelude::*;
```

## API Reference

### `Span` (static)

| Method | Description |
|--------|-------------|
| `set_storage(Option<Arc<dyn Storage>>)` | PHP `setStorage`. `None` clears. |
| `set_exporters(impl IntoIterator<Item = Arc<dyn Exporter>>)` | PHP `setExporters`. Empty iterator clears. |
| `init(action, Option<&str>)` | Create, optionally continue a W3C traceparent, store as current. |
| `current()` | Current span, or `None`. |
| `add(key, value)` | Set attribute on current span (no-op if none). |
| `traceparent()` | Current span's W3C header, or `None`. |

### `Span` (instance)

| Method | Description |
|--------|-------------|
| `new()` / `with_action(action)` | PHP `new Span($action = 'unknown')`. |
| `set(key, value)` | Scalar attribute (`string\|int\|float\|bool\|null`). Fluent. |
| `get(key)` | `Option<AttrValue>`. |
| `get_attributes()` | Insertion-ordered pairs. |
| `get_action()` | Constructor action. |
| `set_error(error)` | Capture `std::error::Error` + `source()` chain. |
| `get_error()` | Captured [`SpanError`]. |
| `get_traceparent()` | `00-{trace_id}-{span_id}-01`. |
| `finish()` | End span, default level `info` (or `error` if an error is set). |
| `finish_level(level)` | Override level without an error. |
| `fail(error)` / `fail_with(level, error)` | PHP `finish(error:)` / `finish(level:, error:)`. |

Built-in attributes: `span.trace_id` (32 hex), `span.id` (16 hex), `span.started_at`, `span.finished_at`, `span.duration`, `level`. Invalid traceparents are ignored (new trace). `finish` swallows exporter panics so tracing cannot break the app.

### `AttrValue`

`String`, `Int(i64)`, `Float(f64)`, `Bool`, `Null`. `From` for `&str`, `String`, integers, `f64`, `bool`, `Option<T>`.

### `Level`

`Debug`, `Info`, `Warn`, `Error`, `Fatal` - values `debug` / `info` / `warn` / `error` / `fatal`.

### Storage

| Type | PHP | Notes |
|------|-----|--------|
| `Memory` | `Storage\Memory` | One current span per instance. |
| `Coroutine` | `Storage\Coroutine` | Tokio task-local (`try_id()`). No-op outside a task. |
| `Auto` | `Storage\Auto` | Coroutine inside a Tokio task, else Memory. |

### Exporters

| Type | PHP | Notes |
|------|-----|--------|
| `Stdout` | `Exporter\Stdout` | NDJSON. Errors → stderr. `new_with(sampler, max_trace_frames)`. |
| `Pretty` | `Exporter\Pretty` | ANSI multi-line. Duration colours: green &lt; 100ms, yellow &lt; 1s, red ≥ 1s. |
| `Sentry` | `Exporter\Sentry` | Warn+ only. Envelope POST. `Sentry::new(dsn)?`. |
| `NoneExporter` | `Exporter\None` | `sample` is always false. |

`Exporter::sample` / `export`. Custom samplers are `Box<dyn Fn(&Span) -> bool + Send + Sync>`.

### `Sentry`

PHP `new Sentry($sampler, $dsn, $environment, $release, $serverName, $classifier)`.

DSN errors match PHP `InvalidArgumentException`:

| Variant | Message |
|---------|---------|
| `DsnRequired` | `Sentry DSN is required` |
| `InvalidDsn` | `Invalid Sentry DSN` |
| `IncompleteDsn` | `Invalid Sentry DSN: must include public key, host, and project ID` |

HTTP conventions: `http.url` / `http.method` / `http.query` / `http.response.status_code`. Classifier maps remaining attributes to `SentryField::{Tag, Context, Extra}`. Tags are strings, max 200 chars. Exception chain via `Error::source()`, cap 10. `span.handled` bool overrides the handled flag (else non-fatal ⇒ handled).

Timeouts: 1000ms request / 500ms connect. HTTP failures are logged, never thrown.

### Intentional deviations

- PHP `Storage\Coroutine` (Swoole) → Tokio task ids.
- `finish` is split into `finish` / `finish_level` / `fail` / `fail_with` because Rust has no named optional args.
- Sentry envelope `platform` is `"rust"` and `sdk.name` is `utopia-span` (PHP: `php` / `utopia-php/span`). Runtime context is `rust` rather than PHP SAPI.
- Stack frames come from `std::backtrace` plus `#[track_caller]`, not PHP `getTrace()`.

## Tests

```bash
cargo test -p utopia-span
```

Ports PHP `SpanTest`, storage tests, exporter tests, and `SentryTest` (envelope payload via `build_envelope`, no live Sentry). Default CI does not need credentials.

## Benchmarks

```bash
cargo bench -p utopia-span
```

PHP twin: `benchmarks/span/`.

## License

MIT - see [LICENSE](LICENSE).
