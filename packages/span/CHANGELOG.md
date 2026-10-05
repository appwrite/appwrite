# Changelog

## Unreleased

- Sentry exporter: deliver envelopes exclusively through PSR-18. It defaults to `utopia-php/client` with its cURL adapter and accepts injected clients such as a coroutine-safe `Utopia\Client\Pool`.

## 3.0.0

### Breaking changes

- Removed `Span::addExporter()`. Use `Span::setExporters(Exporter ...$exporters)` to register exporters; calls replace the full set.
- Removed `Span::resetExporters()`. Call `Span::setExporters()` with no arguments to clear.
- Removed `Span::resetStorage()` and `Span::reset()`. `Span::setStorage()` now accepts `?Storage` — pass `null` to clear.
- `Exporter` interface gained a `sample(Span $span): bool` method. Exporters decide per-span whether `export()` is called; the per-registration sampler closure is gone.

### Exporter behaviour

- Built-in exporters now take an optional `sampler` closure as their first constructor argument by convention.
- `Stdout` and `Pretty` default to exporting every span.
- `Sentry` is hard-wired to error spans only. A user-supplied `sampler` is composed (AND) with the error filter, so it can further restrict but not broaden what is sent.
- `None` always returns `false` from `sample()`.

### Other

- `Span` now declares `strict_types=1`.
- `Span::finish()` accepts the triggering error directly: `finish(?string $level = null, ?Throwable $error = null)`. The level override is also passed through `finish()` rather than set beforehand.
- Added `Pretty` exporter for colourful, human-readable local development output.
- Added automatic `level` attribute on spans (`error` when an error is captured, `info` otherwise; overridable via `finish(level: ...)`).
- Sentry exporter: added `release`, `server_name`, SDK and runtime metadata; configurable attribute classifier (tag/context/extra); fixes for dropped HTTP attributes and empty extras.
- Dropped PHP 8.1 support.
