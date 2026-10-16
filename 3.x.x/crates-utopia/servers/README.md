# utopia-servers

Shared `Hook` / action metadata builders for Utopia. Rust port of [utopia-php/servers](https://github.com/utopia-php/servers).

Used by `utopia-http` (and other servers) for route/hook params, DI injections, groups, and labels. The real async action callback lives in `utopia-http`; this crate only tracks an opaque `has_action` marker. Params use `serde_json::Value` and validators from `utopia-validators`. PHP `composer.json` requires `utopia-php/di` for inject type hints; this crate stores injection **names** only, so it does not depend on [`utopia-di`](../utopia-di) (HTTP/CLI resolve those names against a container).

## Install

```toml
utopia-servers = { path = "../utopia-servers" } # workspace
```

## Usage

```rust
use utopia_servers::Hook;
use utopia_validators::Text;
use serde_json::json;

let mut hook = Hook::new();
hook.desc("demo")
    .groups(["api"])
    .param("name", json!("World"), Text::new(256), "Name", true)
    .inject("response")?
    .label("scope", "public")
    .action_marker();

assert_eq!(hook.get_dependencies(), vec!["response".to_string()]);
```

## Prelude

```rust
use utopia_servers::prelude::*;
// ArgumentKind, Hook, HookError, ParamDef
```

## API Reference

### `Hook`

```rust
#[derive(Clone)]
pub struct Hook { /* desc, groups, labels, params, injections, has_action */ }
```

Fluent hook/route builder. Implements `Default` → `new()`.

| Method | Signature | Description |
|--------|-----------|-------------|
| `new` | `fn new() -> Self` | Empty hook. |
| `desc` | `fn desc(&mut self, desc: impl Into<String>) -> &mut Self` | Set description. |
| `get_desc` | `fn get_desc(&self) -> &str` | Current description. |
| `groups` | `fn groups<I, S>(&mut self, groups: I) -> &mut Self` | **Replace** group list (not append). |
| `get_groups` | `fn get_groups(&self) -> &[String]` | Current groups. |
| `label` | `fn label(&mut self, key: impl Into<String>, value: impl Into<Value>) -> &mut Self` | Set/overwrite a JSON label. |
| `get_label` | `fn get_label(&self, key: &str, default: Value) -> Value` | Label clone, or `default` if missing. |
| `action_marker` | `fn action_marker(&mut self) -> &mut Self` | Mark that an action is attached. |
| `has_action` | `fn has_action(&self) -> bool` | Whether `action_marker` was called. |
| `inject` | `fn inject(&mut self, injection: impl Into<String>) -> Result<&mut Self, HookError>` | Declare a DI injection name. Order = `params.len() + injections.len()` at insert time. Duplicate → `DuplicateInjection`. |
| `param` | `fn param(&mut self, key, default: Value, validator: impl Validator + 'static, description, optional: bool) -> &mut Self` | Shorthand `param_full` with empty injections/aliases/example and flags false. |
| `param_full` | `fn param_full(&mut self, key, default, validator, description, optional, injections, skip_validation, deprecated, example, aliases) -> &mut Self` | Full param metadata; shared order counter with injections. Same `key` overwrites. |
| `get_params` | `fn get_params(&self) -> &HashMap<String, ParamDef>` | Immutable param map. |
| `get_params_mut` | `fn get_params_mut(&mut self) -> &mut HashMap<String, ParamDef>` | Mutable param map. |
| `has_injections` | `fn has_injections(&self) -> bool` | Any injections declared. |
| `get_injections` | `fn get_injections(&self) -> Vec<(String, usize)>` | `(name, order)` sorted by order. |
| `get_dependencies` | `fn get_dependencies(&self) -> Vec<String>` | Injection names only, in order (PHP-style deps list). |
| `set_param_value` | `fn set_param_value(&mut self, key: &str, value: Value) -> Result<(), HookError>` | Set runtime `ParamDef.value`; unknown key → `UnknownKey`. |
| `get_param_value` | `fn get_param_value(&self, key: &str) -> Result<Option<&Value>, HookError>` | Runtime value ref; unknown key → `UnknownKey`. |
| `argument_order` | `fn argument_order(&self) -> Vec<(ArgumentKind, String, usize)>` | Params + injections sorted by declaration `order`. |

**Notes**

- Param vs injection order mirrors PHP: interleaved by call order via a shared counter.
- `inject` errors on duplicates; `param` / `param_full` silently overwrite the same key.
- `Clone` deep-copies maps/vecs (validators via `Arc`).

### `ArgumentKind`

```rust
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum ArgumentKind {
    Param,
    Injection,
}
```

Discriminator in `Hook::argument_order` for whether an ordered slot is a request param or a DI injection.

### `ParamDef`

```rust
#[derive(Clone)]
pub struct ParamDef {
    pub key: String,
    pub default: Value,
    pub validator: Arc<dyn Validator>,
    pub description: String,
    pub optional: bool,
    pub injections: Vec<String>,
    pub skip_validation: bool,
    pub deprecated: bool,
    pub example: String,
    pub aliases: Vec<String>,
    pub value: Option<Value>,  // runtime-resolved value
    pub order: usize,          // declaration order vs injections
}
```

Parameter metadata on a Hook/Route. All fields are public. `validator` is `Arc<dyn Validator>` from `utopia-validators`. `value` is filled at request time via `set_param_value`.

### `HookError`

```rust
#[derive(Debug, Error, Clone, PartialEq, Eq)]
pub enum HookError {
    DuplicateInjection(String),  // "Injection already declared for {0}"
    UnknownKey,                  // "Unknown key"
}
```

Returned by `inject`, `set_param_value`, and `get_param_value`.

## Tests

```bash
cargo test -p utopia-servers
cargo bench -p utopia-servers
```

## Benchmarks

```bash
cargo bench -p utopia-servers
./benchmarks/run.sh
```

## Code quality

This crate inherits workspace linting:

- **rustfmt** - `cargo fmt -p <crate>` (config: repo-root `rustfmt.toml`)
- **Clippy + rustc lints** - `cargo clippy -p <crate> --all-targets -- -D warnings` (config: `clippy.toml`, `[workspace.lints]`)
- **Docs** - `cargo doc -p <crate> --no-deps` (`RUSTDOCFLAGS=-Dwarnings` in CI)
- **Supply chain** - `cargo deny check` (config: `deny.toml`)
