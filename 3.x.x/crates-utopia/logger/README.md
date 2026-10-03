# utopia-logger

Error and warning logging adapters for Utopia. Rust port of [utopia-php/logger](https://github.com/utopia-php/logger).

Pushes structured logs to Sentry, Raygun, AppSignal, or LogOwl over HTTP via [`utopia-client`](../utopia-client) (PHP `utopia-php/fetch`). Request JSON, headers, and URLs match the PHP adapters.

## Install

```toml
utopia-logger = { path = "../utopia-logger" }
```

## Usage

```rust
use utopia_logger::{AppSignal, Breadcrumb, Log, LogOwl, Logger, Raygun, Sentry, User};

let mut log = Log::new();
log.set_action("controller.database.deleteDocument");
log.set_environment(Log::ENVIRONMENT_PRODUCTION).unwrap();
log.set_namespace("api");
log.set_server(Some("digitalocean-us-001"));
log.set_type(Log::TYPE_ERROR).unwrap();
log.set_version("0.11.5");
log.set_message("Document efgh5678 not found");
log.set_user(User::new(Some("efgh5678"), None, None));
log.add_breadcrumb(
    Breadcrumb::new(
        Log::TYPE_DEBUG,
        "http",
        "DELETE /api/v1/database/abcd1234/efgh5678",
        1_700_000_000.0,
    )
    .unwrap(),
);
log.add_tag("sdk", "Flutter");
log.add_extra("urgent", false);

// Sentry - PHP `new Sentry($projectId, $key, $host = '', $timeout = 5, $connectTimeout = 1)`
let logger = Logger::new(Sentry::new("project-id", "sentry-key"));
logger.add_log(&log).unwrap();

// AppSignal
let logger = Logger::new(AppSignal::new("appsignal-push-key"));
logger.add_log(&log).unwrap();

// Raygun
let logger = Logger::new(Raygun::new("raygun-api-key"));
logger.add_log(&log).unwrap();

// LogOwl
let logger = Logger::new(LogOwl::new("service-ticket"));
logger.add_log(&log).unwrap();
```

### Adapters

| Adapter | Status | PHP name |
|---------|--------|----------|
| [`Sentry`](#sentry) | supported | `sentry` |
| [`AppSignal`](#appsignal) | supported | `appSignal` |
| [`Raygun`](#raygun) | supported | `raygun` |
| [`LogOwl`](#logowl) | supported | `logOwl` |

## API Reference

### `Logger`

| Method / const | Signature | Description |
|----------------|-----------|-------------|
| `LIBRARY_VERSION` | `&'static str` (`"0.1.0"`) | SDK version sent to providers. |
| `PROVIDERS` | `&'static [&'static str]` | `raygun`, `sentry`, `appSignal`, `logOwl`. |
| `new` | `fn new(adapter: A) -> Logger<A>` | PHP `__construct(Adapter $adapter)`. |
| `add_log` | `fn add_log(&self, log: &Log) -> Result<u16, LoggerError>` | Validate, sample, then `adapter.push`. |
| `get_providers` | `fn get_providers() -> &'static [&'static str]` | Copy of `PROVIDERS`. |
| `has_provider` | `fn has_provider(name: &str) -> bool` | Case-sensitive membership in `PROVIDERS`. |
| `set_sample` | `fn set_sample(&mut self, sample: f64) -> &mut Self` | Fraction `0.0..1.0` stored as percent internally (`sample * 100`). |
| `get_sample` | `fn get_sample(&self) -> Option<f64>` | Stored percent, or `None` if unset. |

`add_log` requires non-empty `action`, `environment`, `message`, `type`, and `version` (PHP `empty()`, so `"0"` counts as empty) or returns `LoggerError::NotReady` (`"Log is not ready to be pushed."`). When a sample rate is set, `rand(1, 100) >= sample_percent` skips the push and returns `0`. If `Adapter::validate` returns `false`, `add_log` returns `500` without pushing. Validate failures that throw (unsupported type/environment/breadcrumb) propagate as `LoggerError`.

### `Log`

| Const | Value |
|-------|-------|
| `TYPE_DEBUG` | `"debug"` |
| `TYPE_ERROR` | `"error"` |
| `TYPE_WARNING` | `"warning"` |
| `TYPE_INFO` | `"info"` |
| `TYPE_VERBOSE` | `"verbose"` |
| `ENVIRONMENT_PRODUCTION` | `"production"` |
| `ENVIRONMENT_STAGING` | `"staging"` |

| Method | Signature | Description |
|--------|-----------|-------------|
| `new` | `fn new() -> Log` | Timestamp defaults to current unix seconds (PHP `microtime(true)`). Namespace defaults to `"UNKNOWN"`. |
| `set_type` / `get_type` | `Result<(), LoggerError>` / `&str` | Must be a `TYPE_*` constant. |
| `set_timestamp` / `get_timestamp` | `f64` | Seconds when the log occurred. |
| `set_message` / `get_message` | `String` / `&str` | Main message. |
| `set_version` / `get_version` | `String` / `&str` | Application version. |
| `set_environment` / `get_environment` | `Result<(), LoggerError>` / `&str` | `ENVIRONMENT_PRODUCTION` or `ENVIRONMENT_STAGING`. |
| `set_action` / `get_action` | `String` / `&str` | Causing action. |
| `set_namespace` / `get_namespace` | `String` / `&str` | Category. Default `"UNKNOWN"`. |
| `set_server` / `get_server` | `Option<String>` / `Option<&str>` | Server identifier. |
| `add_tag` / `get_tags` | `(key, value)` / `HashMap<String, String>` | Labels; `get_tags` applies masking. |
| `add_extra` / `get_extra` | `(key, impl Into<Value>)` / `Map<String, Value>` | Mixed metadata; `get_extra` applies masking. |
| `set_user` / `get_user` | `User` / `Option<&User>` | User who caused the log. |
| `add_breadcrumb` / `get_breadcrumbs` | `Breadcrumb` / `&[Breadcrumb]` | Reproduction steps. |
| `set_masked` | `impl IntoIterator<Item = impl Into<String>>` | Field names replaced by asterisks of the same byte length (recursive on arrays/objects). |

Invalid `set_type` message matches PHP: `Unsupported log type. Must be one of Log::TYPE_DEBUG, Log::TYPE_ERROR, Log::TYPE_WARNING, Log::TYPE_INFO, Log::VERBOSE.`

### `User`

| Method | Signature | Description |
|--------|-----------|-------------|
| `new` | `fn new(id, email, username: Option<&str>) -> User` | All fields optional. |
| `get_id` | `fn get_id(&self) -> Option<&str>` | Identifier. |
| `get_email` | `fn get_email(&self) -> Option<&str>` | Email. |
| `get_username` | `fn get_username(&self) -> Option<&str>` | Display name. |

### `Breadcrumb`

| Method | Signature | Description |
|--------|-----------|-------------|
| `new` | `fn new(type, category, message, timestamp) -> Result<Breadcrumb, LoggerError>` | `type` must be a `Log::TYPE_*` constant. |
| `get_type` | `&str` | Breadcrumb type. |
| `get_category` | `&str` | Category. |
| `get_message` | `&str` | Message. |
| `get_timestamp` | `f64` | Unix seconds. |

Invalid type message matches PHP: `Type has to be one of Log::TYPE_DEBUG, Log::TYPE_ERROR, Log::TYPE_INFO, Log::TYPE_WARNING, Log::TYPE_VERBOSE.`

### `Adapter` trait

| Method | Signature | Description |
|--------|-----------|-------------|
| `get_name` | `fn get_name(&self) -> &'static str` | Unique provider id. |
| `push` | `fn push(&self, log: &Log) -> Result<u16, LoggerError>` | HTTP POST; returns status. Fetch errors return `500`. |
| `get_supported_types` | `&'static [&'static str]` | Allowed log types. |
| `get_supported_environments` | `&'static [&'static str]` | Allowed environments. |
| `get_supported_breadcrumb_types` | `&'static [&'static str]` | Allowed breadcrumb types. |
| `validate` | `fn validate(&self, log: &Log) -> Result<bool, LoggerError>` | Default impl throws if type/environment/breadcrumb is unsupported. |

### `Sentry`

PHP `new Sentry($projectId, $key, $host = '', $timeout = 5, $connectTimeout = 1)`.

| Method | Signature | Description |
|--------|-----------|-------------|
| `new` | `fn new(project_id, key) -> Sentry` | Host `https://sentry.io`, timeouts 5s / 1s. |
| `new_with` | `fn new_with(project_id, key, host, timeout, connect_timeout) -> Sentry` | Full PHP constructor. Empty host → `https://sentry.io`. `timeout <= 0` → defaults. |
| `get_name` | `"sentry"` | Static and instance. |

POST `{host}/api/{projectId}/store/` with `Content-Type: application/json` and `X-Sentry-Auth: Sentry sentry_version=7, sentry_key={key}, sentry_client=utopia-logger/0.1.0`. Body `platform` is `"php"` (PHP payload 1:1). Does not support `TYPE_VERBOSE`.

### `Raygun`

PHP `new Raygun($key, $timeout = 5, $connectTimeout = 1)`.

| Method | Signature | Description |
|--------|-----------|-------------|
| `new` | `fn new(key) -> Raygun` | Timeouts 5s / 1s. |
| `new_with` | `fn new_with(key, timeout, connect_timeout) -> Raygun` | Full PHP constructor. |
| `with_host` | `fn with_host(self, host) -> Self` | Override origin (PHP hardcodes `https://api.raygun.com`). For tests. |
| `get_name` | `"raygun"` | Static and instance. |

POST `{host}/entries` with `X-ApiKey`.

### `AppSignal`

PHP `new AppSignal($key, $timeout = 5, $connectTimeout = 1)`.

| Method | Signature | Description |
|--------|-----------|-------------|
| `new` | `fn new(key) -> AppSignal` | Timeouts 5s / 1s. |
| `new_with` | `fn new_with(key, timeout, connect_timeout) -> AppSignal` | Full PHP constructor. |
| `with_host` | `fn with_host(self, host) -> Self` | Override origin (PHP hardcodes `https://appsignal-endpoint.net`). For tests. |
| `get_name` | `"appSignal"` | Static and instance. |

POST `{host}/collect?api_key={key}&version=1.3.19`. Extra values are PHP `var_export` strings.

### `LogOwl`

PHP `new LogOwl($ticket, $host = '', $timeout = 5, $connectTimeout = 1)`.

| Method | Signature | Description |
|--------|-----------|-------------|
| `new` | `fn new(ticket) -> LogOwl` | Host `https://api.logowl.io/logging/`. |
| `new_with` | `fn new_with(ticket, host, timeout, connect_timeout) -> LogOwl` | Full PHP constructor. |
| `get_name` | `"logOwl"` | Static and instance. |
| `get_adapter_type` | `"utopia-logger"` | Payload `adapter.type`. |
| `get_adapter_version` | `Logger::LIBRARY_VERSION` | Payload `adapter.version`. |

POST `{host}{type}` (host already includes trailing path). Supports only `TYPE_ERROR`. Badge keys `$email` and `$username` match PHP.

### `LoggerError`

| Variant | PHP message |
|---------|-------------|
| `NotReady` | `Log is not ready to be pushed.` |
| `UnsupportedType` | `Unsupported log type. Must be one of Log::TYPE_DEBUG, ... Log::VERBOSE.` |
| `UnsupportedEnvironment` | `Unsupported environment of log. Must be one of ENVIRONMENT_PRODUCTION, ENVIRONMENT_STAGING.` |
| `InvalidBreadcrumbType` | `Type has to be one of Log::TYPE_DEBUG, ... Log::TYPE_VERBOSE.` |
| `UnsupportedAdapterLogType` | `Supported log types for this adapter are: {list}` |
| `UnsupportedAdapterEnvironment` | `Supported environments for this adapter are: {list}` |
| `UnsupportedAdapterBreadcrumbType` | `Supported breadcrumb types for this adapter are: {list}` |
| `Message` | Adapter-specific (e.g. `detailedTrace must be an array`) |

HTTP transport failures are **not** errors: adapters log to stderr (PHP `error_log`, including the text `fetch error`) and return status `500`.

## Tests

```bash
cargo test --manifest-path crates-utopia/logger/Cargo.toml
```

Unit tests port `LogTest`, `BreadcrumbTest`, and `UserTest`. Adapter and e2e tests use [utopia-test-wiremock](../utopia-test-wiremock) (compose/CI WireMock) and assert URL, headers, JSON body shape, and HTTP status from the PHP adapters. They do not require live credentials.

## Benchmarks

```bash
cargo bench --manifest-path crates-utopia/logger/Cargo.toml
```

Reports `log_construct` and `log_add_log` ops/s using a no-op adapter (PHP twin: `benchmarks/logger/`).

## Code quality

```bash
cargo fmt --manifest-path crates-utopia/logger/Cargo.toml
cargo clippy --manifest-path crates-utopia/logger/Cargo.toml --all-targets -- -D warnings
```

Inherits workspace lint policy (`[lints] workspace = true`).

## License

MIT - see [LICENSE](LICENSE).
