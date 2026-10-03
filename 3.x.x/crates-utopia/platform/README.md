# utopia-platform

Object-oriented application layer for Utopia - a Rust port of [`utopia-php/platform`](https://github.com/utopia-php/platform).

`Platform`, `Module`, `Service`, and `Action` provide a structured way to declare HTTP routes, CLI tasks, worker jobs, lifecycle hooks, parameters, and injections that are registered into runtimes at init time.

## Features

| Feature | Default | Description |
|---------|---------|-------------|
| `http` | yes | Wire actions into `utopia-http` via [`HttpRegistrar`](src/http.rs) |
| `cli` | yes | Wire task services into `utopia-cli` via [`CliRegistrar`](src/cli.rs) |
| `worker` | yes | Wire worker services into [`GenericWorker`](src/worker.rs) (portable registrar) |

GraphQL initialization is a no-op success (matching PHP `initGraphQL`).

## Getting started

Declare `Action`s on a typed `Service`, attach the service to a `Platform`, then register into a runtime with `init_http` or `init_cli`. Platform does not store the `Http` / `Cli` instance.

```toml
[dependencies]
utopia-platform = { path = "../crates-utopia/platform", default-features = true }
utopia-http = { path = "../crates-utopia/http" }
utopia-cli = { path = "../crates-utopia/cli" }
utopia-di = { path = "../crates-utopia/di" }
utopia-validators = { path = "../crates-utopia/validators" }
tokio = { version = "1", features = ["rt", "macros"] }
```

### HTTP

```rust
use utopia_di::Container;
use utopia_http::{Http, MemoryAdapter, Request, Response};
use utopia_platform::{Action, HttpMethod, Module, Platform, Service};

#[tokio::main]
async fn main() -> Result<(), Box<dyn std::error::Error>> {
    let hello = Action::new()
        .set_http_path("/hello")
        .set_http_method(HttpMethod::Get)
        .http_action(|ctx| async move {
            ctx.response.send("Hello World!")?;
            Ok(())
        });

    let service = Service::http().add_action("hello", hello);
    let mut platform = Platform::new(Module::new()).add_service("helloService", service);

    let mut http = Http::new(MemoryAdapter::new(Container::new()), "UTC");
    platform.init_http(&mut http)?;

    let response = Response::new();
    http.run(Request::new("GET", "/hello"), response.clone())
        .await?;
    assert_eq!(response.body_string(), "Hello World!");
    Ok(())
}
```

### CLI

```rust
use serde_json::Value;
use utopia_cli::Cli;
use utopia_platform::{Action, Module, Platform, Service};
use utopia_validators::{ArrayList, Text};

fn main() -> Result<(), Box<dyn std::error::Error>> {
    let build = Action::new()
        .param("email", Value::Null, Text::new(0), "Email address", false)
        .param(
            "list",
            Value::Null,
            ArrayList::new(Text::new(256)),
            "List of strings",
            false,
        )
        .cli_action(|params| {
            let email = params.get_str("email").unwrap_or("");
            let list = params.get_list("list").unwrap_or_default();
            println!("{}-{}", email, list.join("-"));
            Value::Null
        });

    let service = Service::task()
        .add_action("build", build.clone())
        .add_action("build2", build);
    let mut platform = Platform::new(Module::new()).add_service("cli", service);

    let mut cli = Cli::with_args(vec![
        "app".into(),
        "build".into(),
        "--email=me@example.com".into(),
        "--list=item1".into(),
        "--list=item2".into(),
    ])?;
    platform.init_cli(&mut cli)?;
    cli.run();
    Ok(())
}
```

```bash
cargo run -p my-app -- build --email=me@example.com --list=item1 --list=item2
# prints: me@example.com-item1-item2
```

### Workers

```rust
use utopia_platform::{Action, ActionType, GenericWorker, Module, Platform, Service};

let on_start = Action::new()
    .set_type(ActionType::WorkerStart)
    .callback(|| { /* ... */ });
let service = Service::worker().add_action("workerStartHook", on_start);
let mut platform = Platform::new(Module::new()).add_service("worker", service);

platform.set_worker(GenericWorker::new());
platform.init_worker_with_name(Some("my-worker"))?;

let hooks = platform.get_worker().expect("worker").get_worker_start();
```

## API Reference

### `Enum`

Metadata for whitelist-backed parameters (generated enum registry).

| Field | Type | Description |
|-------|------|-------------|
| `name` | `Option<String>` | Generated enum name |
| `map` | `Option<HashMap<String, String>>` | Whitelist value → case name |
| `exclude` | `Option<Vec<String>>` | Values omitted from generated enums |

Builder helpers: `with_name`, `with_map`, `with_exclude`.

---

### `Action`

Fluent builder for a single platform action.

#### Types

| Variant | Description |
|---------|-------------|
| `Default` | HTTP route, CLI task, or worker job |
| `Init` | Runtime init hook |
| `Error` | Error hook |
| `Options` | OPTIONS hook (wired via `utopia-http::Http::on_options`) |
| `Shutdown` | Shutdown hook |
| `WorkerStart` / `WorkerStop` | Worker lifecycle hooks |

#### Methods

| Method | Description |
|--------|-------------|
| `new()` | Create a default action |
| `set_type(ActionType)` | Set lifecycle type |
| `desc(str)` | Human-readable description |
| `groups(iter)` | Route/hook groups |
| `label(key, value)` | Arbitrary metadata label |
| `param(...)` / `param_full(...)` | Declare validated parameter (all fields forwarded to runtimes) |
| `inject(name)` | Declare DI injection (errors on duplicate) |
| `callback(fn)` | Sync handler (`Fn() + Send + Sync`); also used as a fallback CLI/HTTP body |
| `http_action(async fn)` | Async HTTP handler (`ActionContext` → `Result<()>`) |
| `cli_action(fn)` | CLI handler (`&Params` → `Value`) |
| `set_http_path` / `set_http_method` / `set_http_methods` | HTTP routing |
| `http_alias(path)` | Additional route paths |

Parameter metadata (`aliases`, `deprecated`, `example`, `enum_meta`, `skip_validation`, `injections`) is forwarded to `utopia-http` routes and CLI/worker hooks.

---

### `Service`

Groups actions for one runtime.

| Method | Description |
|--------|-------------|
| `http()` / `task()` / `graphql()` / `worker()` | Construct typed service |
| `add_action(key, action)` | Register action |
| `remove_action(key)` | Remove action |
| `get_action` / `get_actions` | Lookup |

---

### `Module`

Holds named services, indexed by [`ServiceType`](src/service.rs).

| Method | Description |
|--------|-------------|
| `add_service(key, service)` | Register service |
| `remove_service(key)` | Remove service |
| `get_service(key)` | Lookup (errors if missing) |
| `get_services_by_type(type)` | Filter by runtime |

---

### `Platform`

Top-level application container.

| Method | Description |
|--------|-------------|
| `new(core: Module)` | Create with core module |
| `add_module(module)` | Attach additional module |
| `add_service` / `remove_service` / `get_service` | Core module service helpers |
| `init(ServiceType)` | Dispatch init (`Http` → use `init_http`, `Task` → use `init_cli`) |
| `init_http(&mut Http)` | Register HTTP services (`http` feature) |
| `init_cli(&mut Cli)` | Register CLI task services (`cli` feature) |
| `init_graphql()` | No-op success (GraphQL stub) |
| `init_worker()` / `init_worker_with_name(name)` | Register worker services (`worker` feature) |
| `set_worker` / `get_worker` | Worker registrar (`worker` feature) |

---

### `CliRegistrar` (`cli` feature)

Trait for registering [`Action`](src/action.rs) values onto a CLI stack. [`UtopiaCliRegistrar`](src/cli.rs) is the built-in adapter for `utopia-cli`, mirroring PHP `Platform::initTasks` (`CLI::init` / `error` / `shutdown` / `task`). `Platform` does not store the `Cli` instance (same pattern as `init_http`).

---

### `GenericWorker` / `WorkerRegistrar` (`worker` feature)

Portable worker registrar mirroring PHP queue `Server` hooks (`init`, `error`, `shutdown`, `workerStart`, `workerStop`, `job`). PHP `Platform` requires `utopia-php/queue` because `Worker` extends `Queue\Server`; this crate keeps a registrar-only surface so apps can attach a real [`utopia-queue`](../utopia-queue) `Server` without pulling the queue runtime into every platform binary.

---

### `HttpRegistrar` (`http` feature)

Trait for registering [`Action`](src/action.rs) values onto an HTTP stack. [`UtopiaHttpRegistrar`](src/http.rs) is the built-in adapter for `utopia-http`.

---

### Errors - `PlatformError`

| Variant | Description |
|---------|-------------|
| `ServiceNotFound` | Unknown service key |
| `DuplicateInjection` | Repeated `inject()` |
| `MissingCallback` | No handler on action |
| `MissingHttpPath` / `MissingHttpMethods` | Invalid default HTTP action |
| `FeatureNotEnabled` | Runtime without Cargo feature |
| `Http` | `utopia-http` error (with `http` feature) |
| `Cli` | `utopia-cli` error (with `cli` feature) |

## Benchmarks

```bash
cargo bench --manifest-path crates-utopia/platform/Cargo.toml --bench platform
```

Reports `platform_register_action` throughput (action + service + platform construction).

## Tests

```bash
cargo test -p utopia-platform --all-features
cargo clippy -p utopia-platform --all-features -- -D warnings
```

## License

MIT - see [LICENSE](LICENSE).
