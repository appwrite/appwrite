# appwrite-hooks

Appwrite lifecycle hooks. Rust port of `Appwrite\Hooks\Hooks`
(`src/Appwrite/Hooks/Hooks.php`): a named-callback registry with a single
`trigger(name, params)` entry point.

## Install

```toml
appwrite-hooks = { workspace = true }
```

## API

```rust
pub type HookFn = dyn Fn(&[serde_json::Value]) -> serde_json::Value + Send + Sync;

pub const PASSWORD_VALIDATOR: &str = "passwordValidator";

pub struct Hooks { /* ... */ }

impl Hooks {
    pub fn new() -> Self;
    pub fn add<F>(&mut self, name: impl Into<String>, action: F)
    where F: Fn(&[serde_json::Value]) -> serde_json::Value + Send + Sync + 'static;
    pub fn remove(&mut self, name: &str);
    pub fn has(&self, name: &str) -> bool;
    pub fn len(&self) -> usize;
    pub fn is_empty(&self) -> bool;
    pub fn trigger(&self, name: &str, params: &[serde_json::Value]) -> Option<serde_json::Value>;
}
```

`PASSWORD_VALIDATOR` is a well-known hook name reserved for password
strength/dictionary/history checks, so callers (e.g. `appwrite-auth`, server
bootstrap) can plug in project-specific policy without a hard dependency
between crates.

### Deviation from PHP

PHP's `Hooks::add()` is a `static` method writing into a process-wide static
array, and `trigger()` is an instance method reading that same static state.
This port uses a single instance-owned `HashMap` instead: `Hooks::new()`
creates an independent registry. This is a deliberate deviation for
testability (parallel tests do not share hook state) and to fit the
DI-container composition style used elsewhere in this workspace
(`utopia-di`); callers that want a single shared registry can hold one
`Hooks` instance behind an `Arc` and share it.

## Status

Complete port of the (small) PHP surface, plus the `PASSWORD_VALIDATOR` hook
slot needed for the Users API foundation.
