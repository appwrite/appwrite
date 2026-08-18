# utopia-http

Lite & fast micro HTTP framework - Rust port of [`utopia-php/http`](https://github.com/utopia-php/http).

Integrates `utopia-di`, `utopia-validators`, `utopia-servers`, `utopia-compression`, `utopia-telemetry`, and `utopia-system`.

## Install

```toml
utopia-http = { path = "../utopia-http" } # workspace
```

## Quickstart

```rust
use serde_json::json;
use utopia_http::prelude::*;

#[tokio::main]
async fn main() -> Result<()> {
    let resources = Container::new();
    let mut http = Http::new(HyperServer::bind("0.0.0.0:8080", resources), "UTC");

    http.get("/hello-world")?
        .param("name", json!("World"), Text::new(256), "Name to greet", true)
        .action(|ctx| async move {
            let name = ctx.param_str("name")?;
            ctx.response().json(&json!({ "Hello": name }))?;
            Ok(())
        });

    http.set_mode(Mode::Production);
    http.start().await
}
```

## Features

- Utopia-compatible router (`:param`, `*`, aliases, multi-method)
- Fluent `Route` / hooks (`init`, `shutdown`, `error`)
- DI via `utopia-di`, validation via `utopia-validators`
- Response compression via `utopia-compression`
- Telemetry hooks via `utopia-telemetry`
- Adapters: `HyperServer` (Tokio + Hyper) and `MemoryAdapter` (tests)

## Prelude

```rust
use utopia_http::prelude::*;
```

Re-exports: `ActionContext`, `Files`, `Http`, `HttpError`, `HyperServer`, `MemoryAdapter`, `Mode`, `Request`, `Response`, `Result`, `Route`, `Router`, `StatusCode`, plus `utopia_di::{Container, Resource}` and `utopia_validators::{Text, Validator, Wildcard}`.

**Not in prelude (but public):** `HeaderMap`, `RouteMatch`, `adapter::{Adapter, BoxedFuture, RequestHandler}`.

## Request lifecycle

```
adapter accepts connection
  → Http::start registers Adapter::on_request → Http::run
      1. metrics start (if enabled)
      2. Mode::Development → Response::set_debug_timing(true)  // x-debug-speed
      3. if compression: copy Accept-Encoding + min size onto Response
      4. Request::parse_query_from_uri
      5. fast path: no request_hooks && no static files → Http::execute
         else: DI child caches "request"/"response"; request_hooks; Files::get; else execute
      6. metrics end

Http::execute
  • HEAD → disable_payload; match as GET
  • OPTIONS → run options hooks → return
  • no match → HttpError::not_found → error hooks
  • else: init hooks → route action (if not sent) → shutdown hooks
          on Err → error hooks
```

**DI child scope:** `build_context` uses `Adapter::context()` (child of shared `resources`) when the hook/action has injections **or** an error is present, and caches `request` / `response` / `error`. Otherwise it reuses the shared app `Container` (hot path).

**Production mode:** `Mode::Production` / `is_production()` are available; they do not change dispatch today. Development enables `x-debug-speed`.

## API Reference

### `Http`

```rust
pub struct Http { /* opaque */ }
```

| Method | Signature (simplified) | Description |
|--------|------------------------|-------------|
| `new` | `fn new(adapter: impl Adapter + 'static, timezone: impl Into<String>) -> Self` | Default `Mode::None`, compression off, min size 1024, empty hooks/files, `NoneAdapter` metrics. |
| `resources` | `fn resources(&self) -> Container` | Clone of adapter’s **shared** DI container. |
| `set_mode` / `mode` | `fn set_mode(&mut self, Mode)` / `fn mode(&self) -> Mode` | Runtime mode. |
| `is_production` | `fn is_production(&self) -> bool` | `mode == Production`. |
| `set_compression` | `fn set_compression(&mut self, enabled: bool)` | When true, `run` sets Accept-Encoding on the response. |
| `set_allow_override` | `fn set_allow_override(&self, bool)` | Forwarded to `Router` (duplicate routes). |
| `load_files` | `fn load_files(&mut self, directory) -> Result<()>` | Eager-load static tree into `Files`. |
| `timezone` | `fn timezone(&self) -> &str` | Stored string. |
| `routes` | `fn routes(&self, methods: &[&str], path: &str) -> Result<Arc<Route>>` | Uppercases methods; `EmptyMethods` if empty. |
| `get` / `post` / `put` / `patch` / `delete` | `fn …(&self, path: &str) -> Result<Arc<Route>>` | Single-method convenience. |
| `wildcard` | `fn wildcard(&self) -> Arc<Route>` | Catch-all route. |
| `on_init` / `on_shutdown` / `on_error` | `fn on_*(F) -> HookBuilder<'_>` | Async `Fn(ActionContext) -> Fut`; default groups `["*"]`. |
| `match_request` | `fn match_request(&self, &Request) -> Option<RouteMatch>` | Empty path → `"/"`. |
| `execute` | `async fn execute(&self, Request, Response) -> Result<()>` | Core dispatch. Errors go to error hooks; returns `Ok(())`. |
| `run` | `async fn run(&self, Request, Response) -> Result<()>` | Pre-execute + metrics wrapper. |
| `start` | `async fn start(self) -> Result<()>` | Registers handler, `adapter.start()` (consumes `self` into `Arc`). |
| `router` | `fn router(&self) -> &Router` | Access the router. |

### `HookBuilder<'a>`

Returned by `on_init` / `on_shutdown` / `on_error` (not re-exported at crate root; use via chaining).

```rust
fn groups<I, S>(self, groups: I) -> Self
fn inject(self, name: impl Into<String>) -> Result<Self>
fn param(self, key, default: Value, validator: impl Validator + 'static,
         description, optional: bool) -> Self
```

### `Mode`

```rust
pub enum Mode { None, Production, Development, Stage }  // Default: None
fn as_str(self) -> &'static str  // "", "production", "development", "stage"
```

### `Route`

Fluent route descriptor held as `Arc<Route>`. Most builders take `&Arc<Self>` and return `Arc<Self>`.

| Method | Description |
|--------|-------------|
| `new(methods, path, order)` | Construct (usually via `Http::get` / `routes`). |
| `methods` / `path` / `order` | Accessors. |
| `get_hook_flag` / `hook(enabled)` | Whether global (`"*"`) hooks run for this route. |
| `desc` / `groups` / `label` | Metadata (via shared `Arc<Hook>`). |
| `inject(name)` | Declare DI injection; duplicate → `DuplicateInjection`. Presence of injections forces request-scoped child DI. |
| `param(key, default, validator, description, optional)` | Request/path param with validator. |
| `action(F)` | Async handler `Fn(ActionContext) -> Fut`. |
| `get_groups` / `get_action` / `hook_meta` | Introspection (`hook_meta` returns `Arc<Hook>` - cheap clone). |
| `alias(router, path)` | Register an alternate path. |
| `resolve_params` / `resolve_params_from_parts` | Path-param extraction after match. |

**Param resolution:** validates against request query/payload and path params at context build; optional nulls skip validation; string→integer coercion when validator type is Integer. Payload wins over query.

### `Router` / `RouteMatch`

```rust
pub struct RouteMatch {
    pub route: Arc<Route>,
    pub params: HashMap<String, String>,
}

pub struct Router { /* Arc<RwLock<…>> Clone + Default */ }
```

| Method | Description |
|--------|-------------|
| `new` | Tables for GET/POST/PUT/PATCH/DELETE. |
| `set_allow_override` / `get_allow_override` | Allow duplicate path+method. |
| `set_wildcard` | Global fallback route. |
| `add_route` / `add_route_alias` | `:param` → placeholder `:*:`; tracks param indexes. |
| `match_route(method, path)` | HEAD→GET; **exact static match first**, then parametric combinations, then `*` / prefix `*/`, then wildcard. |
| `reset` | Clears to empty method tables. |

Path patterns: `/users/:id`, trailing `*`, method-level `*`.

### `Request`

```rust
pub struct Request { /* Clone + Debug + Default → GET / */ }
```

| Method | Description |
|--------|-------------|
| `new(method, uri)` | Method uppercased. |
| `method` / `set_method` / `uri` / `set_uri` / `path` | `path` = URI before `?`. |
| `headers` / `headers_mut` / `header_line` / `set_header` | Via `HeaderMap`. |
| `cookie` / `set_cookie_params` | Cookie map. |
| `set_query` / `set_payload` / `set_raw_payload` / `raw_payload` | `set_query` marks query parsed. |
| `params` | Query then payload (payload overwrites) - allocates. |
| `param_ref` / `param` | **Hot path:** payload wins over query; `param_ref` avoids alloc. |
| `set_server` / `server` | CGI-style server vars. |
| `protocol` | `x-forwarded-proto` first hop, else `"http"`. |
| `ip` / `set_trusted_ip_headers` | Trusted headers first, else `REMOTE_ADDR` / `127.0.0.1`. |
| `size` | Raw body + header name/value lengths. |
| `parse_query_from_uri` | Idempotent; `+` / `%XX` decode. |

### `Response` / `StatusCode`

`Response` is `Clone` (shared `Arc<Mutex<…>>`). First successful `send` / `json` / … sets `sent`; later sends no-op.

```rust
pub struct StatusCode;
// OK=200, CREATED=201, NO_CONTENT=204, MOVED_PERMANENTLY=301, FOUND=302,
// BAD_REQUEST=400, UNAUTHORIZED=401, FORBIDDEN=403, NOT_FOUND=404,
// METHOD_NOT_ALLOWED=405, INTERNAL_SERVER_ERROR=500
```

| Method | Description |
|--------|-------------|
| `new` / `Default` | status 200. |
| `set_debug_timing` | Adds `x-debug-speed` on send. |
| `set_status` | Allowlist of known codes; else `UnknownStatus`. |
| `status_code` / `set_content_type` | Accessors. |
| `add_header` / `set_header` / `header_line` / `has_header` | Headers. |
| `disable_payload` | Clears body on send (HEAD). |
| `is_sent` / `size` | Size after send (body + headers). |
| `set_accept_encoding` / `set_compression_min_size` | Used by send compression. |
| `add_cookie` | Path/domain/secure/http_only/same_site → `Set-Cookie`. |
| `send` / `json` / `text` / `html` | Compresses compressible types above min size via `utopia-compression`. |
| `into_http_parts` | Adapter export: `(status, headers, body)`; auto-sends empty if not sent. |
| `redirect` / `no_content` | Helpers. |
| `take_body` / `body_string` / `headers_snapshot` / `for_each_header` | Test/adapter helpers. |

Compressible MIME: html/plain/css/js/json/xml/svg+xml.

### `HeaderMap`

Case-insensitive multi-value map; compact `Vec` store (linear scan for typical small header counts). Keys stored lowercase.

```rust
fn new() / has / get / get_line(name, default)
fn set / add / remove / iter / into_inner() -> HashMap<String, Vec<String>>
```

### `ActionContext`

```rust
pub struct ActionContext {
    pub request: Arc<Request>,
    pub response: Response,
    pub route: Option<Arc<Route>>,
    pub params: HashMap<String, Value>,  // validated + defaults
    pub container: Container,
    pub error: Option<Arc<HttpError>>,
}
```

| Method | Description |
|--------|-------------|
| `param_str` / `param_value` | Read validated params. |
| `request` / `response` | Accessors. |
| `resource(name)` | DI lookup on `container`. |
| `error` | Present in error hooks. |

### `HttpError` / `Result`

```rust
pub type Result<T> = std::result::Result<T, HttpError>;

pub enum HttpError {
    App { status: u16, message: String },
    MissingParam(String),                    // → 400
    InvalidParam { key, description },       // → 400
    DuplicateRoute { method, path },
    UnsupportedMethod(String),
    EmptyMethods,
    UnknownStatus,
    DuplicateInjection(String),
    Di(utopia_di::ContainerError),
    Io(std::io::Error),
    Other(String),                           // → 500
}

fn status(&self) -> u16
fn not_found() -> Self              // App { 404, "Not Found" }
fn app(status, message) -> Self
```

### `Files`

In-memory static file table `(bytes, mime)`.

```rust
fn new() / is_empty()
fn load(&mut self, directory, root: Option<&str>) -> io::Result<()>
fn is_loaded(&self, uri) / get(&self, uri) -> Option<&(Vec<u8>, String)>
```

URI strips query; MIME via `mime_guess`. Served from `Http::run` with `cache-control: public, max-age=63072000`.

### Adapters (`utopia_http::adapter`)

```rust
pub type BoxedFuture<'a, T> = Pin<Box<dyn Future<Output = T> + Send + 'a>>;
pub type RequestHandler = Box<dyn Fn(Request, Response) -> BoxedFuture<'static, ()> + Send + Sync>;

pub trait Adapter: Send + Sync + 'static {
    fn resources(&self) -> &Container;
    fn context(&self) -> Container;           // default: Container::child(resources)
    fn address(&self) -> Option<&str>;        // default None
    fn on_request(&self, handler: RequestHandler) -> BoxedFuture<'_, ()>;
    fn start(&self) -> BoxedFuture<'_, Result<()>>;
}
```

#### `HyperServer`

Tokio + Hyper HTTP/1.1, keep-alive, `SO_REUSEPORT` acceptors (default = CPU count).

```rust
fn bind(addr: impl Into<String>, resources: Container) -> Self
fn acceptors(self, n: usize) -> Self   // min 1
```

Skips body collect for GET/HEAD/OPTIONS/DELETE with zero Content-Length and no Transfer-Encoding. Enables `TCP_NODELAY`.

#### `MemoryAdapter`

```rust
fn new(resources: Container) -> Self
fn push(&self, Request, Response)
fn push_simple(&self, method, uri) -> Response  // enqueues + returns Response handle
```

`start()` drains the queue FIFO via the registered handler. Used by unit/integration tests.

## Tests & benches

```bash
cargo test -p utopia-http
cargo bench -p utopia-http --bench router
cargo bench -p utopia-http --bench dispatch
cargo run --example hello -p utopia-http
./benchmarks/run.sh
./benchmarks/http/e2e.sh
```

## Code quality

This crate inherits workspace linting:

- **rustfmt** - `cargo fmt -p <crate>` (config: repo-root `rustfmt.toml`)
- **Clippy + rustc lints** - `cargo clippy -p <crate> --all-targets -- -D warnings` (config: `clippy.toml`, `[workspace.lints]`)
- **Docs** - `cargo doc -p <crate> --no-deps` (`RUSTDOCFLAGS=-Dwarnings` in CI)
- **Supply chain** - `cargo deny check` (config: `deny.toml`)
