# utopia-di

Lightweight parent/child dependency injection container. Rust port of [utopia-php/di](https://github.com/utopia-php/di).

Values are type-erased as `Resource` (`Arc<dyn Any + Send + Sync>`). `Container` is `Clone` via a shared `Arc` - clones share the same registry and cache. Internals use `parking_lot::RwLock` and are safe to share across threads.

## Install

```toml
utopia-di = { path = "../utopia-di" } # workspace
```

## Usage

```rust
use utopia_di::{Container, Resource};

let di = Container::new();
di.set("age", || Ok(Resource::i64(25)));
di.set_with_deps("john", &["age"], |deps| {
    let age = deps[0].get_as::<i64>("age")?;
    Ok(Resource::string(format!("John Doe is {age} years old.")))
});

assert_eq!(di.get_as::<String>("john").unwrap(), "John Doe is 25 years old.");

// Request-scoped overrides (does not mutate parent)
let child = Container::child(&di);
child.set_cached("request", Resource::string("req-1"));
assert_eq!(child.get_as::<String>("request").unwrap(), "req-1");
assert!(di.get("request").is_err());
```

## Prelude

```rust
use utopia_di::prelude::*;
// Container, ContainerError, NotFoundError, Resource
```

## API Reference

### `Container`

```rust
#[derive(Clone, Default)]
pub struct Container { /* Arc<Inner> */ }
```

Parent/child DI container with lazy factories and a per-container concrete cache.

| Method | Signature | Description |
|--------|-----------|-------------|
| `new` | `fn new() -> Self` | Empty root container (no parent). |
| `child` | `fn child(parent: &Container) -> Self` | Child that falls through to `parent` for missing factories/`get`. Own entries and concrete cache are separate. |
| `set` | `fn set<F>(&self, id: impl Into<String>, factory: F) -> &Self` where `F: Fn() -> Result<Resource, ContainerError> + Send + Sync + 'static` | Register a zero-dep factory. Replacing an id clears that id’s local concrete cache. |
| `set_with_deps` | `fn set_with_deps<F>(&self, id: impl Into<String>, deps: &[&str], factory: F) -> &Self` where `F: Fn(&[Resource]) -> Result<Resource, ContainerError> + Send + Sync + 'static` | Register a factory; deps are resolved via `get` in declaration order and passed as `&[Resource]`. |
| `set_value` | `fn set_value(&self, id: impl Into<String>, value: Resource) -> &Self` | Convenience: wraps a cloned value in a factory via `set`. |
| `set_cached` | `fn set_cached(&self, id: impl Into<String>, value: Resource) -> &Self` | Bind a concrete value **without** allocating a factory (request-scoped hot path). Visible to `get`. Prefer this over `set_value` for per-request keys (`request`, `response`, `error`) on a `child`. |
| `get` | `fn get(&self, id: &str) -> Result<Resource, ContainerError>` | Resolve: local concrete cache → local factory (then cache) → parent `get` → `NotFound`. |
| `get_as` | `fn get_as<T: Any + Send + Sync + Clone>(&self, id: &str) -> Result<T, ContainerError>` | `get` then downcast/clone as `T`; mismatch → `TypeMismatch`. |
| `has` | `fn has(&self, id: &str) -> bool` | `true` if a **factory entry** exists locally or on an ancestor. Does **not** inspect the concrete cache - `set_cached`-only keys may resolve via `get` but return `false` from `has`. |
| `clear_cache` | `fn clear_cache(&self)` | Clears this container’s concrete cache only (not parents, not factory entries). |

**Notes**

- Factories run once per container until cache clear or re-`set`; parent-resolved values stay cached on the parent.
- Child override of an id does not mutate the parent.
- `utopia-http` uses `Container::child` + `set_cached` for request/response/error bindings.

### `Resource`

```rust
#[derive(Clone)]
pub struct Resource(/* Arc<dyn Any + Send + Sync> */);
```

Type-erased container value. `Clone` bumps the `Arc` (cheap). Stored types must be `Send + Sync`.

| Method | Signature | Description |
|--------|-----------|-------------|
| `new` | `fn new<T: Any + Send + Sync>(value: T) -> Self` | Wrap any `Send + Sync + 'static` value. |
| `bool` | `fn bool(v: bool) -> Self` | Wrap a `bool`. |
| `i64` | `fn i64(v: i64) -> Self` | Wrap an `i64`. |
| `f64` | `fn f64(v: f64) -> Self` | Wrap an `f64`. |
| `string` | `fn string(v: impl Into<String>) -> Self` | Wrap a `String`. |
| `downcast_ref` | `fn downcast_ref<T: Any + Send + Sync>(&self) -> Option<&T>` | Borrowing downcast; `None` on mismatch. |
| `get_as` | `fn get_as<T: Any + Send + Sync + Clone>(&self, id: &str) -> Result<T, ContainerError>` | Clone out `T`, or `TypeMismatch { id, expected }`. |

### `NotFoundError`

```rust
#[derive(Debug, Error, Clone, PartialEq, Eq)]
#[error("Dependency {0} not found")]
pub struct NotFoundError(pub String);
```

Missing dependency id. Converts into `ContainerError::NotFound` via `From`.

### `ContainerError`

```rust
#[derive(Debug, Error)]
pub enum ContainerError {
    NotFound(NotFoundError),
    Factory { id: String, message: String },
    TypeMismatch { id: String, expected: &'static str },
}
```

| Constructor | Signature | Description |
|-------------|-----------|-------------|
| `factory` | `fn factory(id: impl Into<String>, message: impl Into<String>) -> Self` | Build `Factory { id, message }` from user factories. |

## Tests

```bash
cargo test -p utopia-di
```

## Benchmarks

```bash
cargo bench -p utopia-di
# PHP twin: ../../benchmarks/di/
```

## Code quality

This crate inherits workspace linting:

- **rustfmt** - `cargo fmt -p <crate>` (config: repo-root `rustfmt.toml`)
- **Clippy + rustc lints** - `cargo clippy -p <crate> --all-targets -- -D warnings` (config: `clippy.toml`, `[workspace.lints]`)
- **Docs** - `cargo doc -p <crate> --no-deps` (`RUSTDOCFLAGS=-Dwarnings` in CI)
- **Supply chain** - `cargo deny check` (config: `deny.toml`)
