//! Appwrite lifecycle hooks.
//!
//! Rust port of `Appwrite\Hooks\Hooks` (`src/Appwrite/Hooks/Hooks.php`): a
//! named-callback registry with a single `trigger(name, params)` entry point.
//!
//! PHP registers hooks in a process-wide static array (`self::$hooks`);
//! this port uses an instance-owned registry instead so hooks can be scoped
//! per request/container rather than shared mutable global state -- a
//! deliberate, documented deviation for testability (see `README.md`).
//!
//! ```
//! use appwrite_hooks::Hooks;
//! use serde_json::json;
//!
//! let mut hooks = Hooks::new();
//! hooks.add(appwrite_hooks::PASSWORD_VALIDATOR, |params| {
//!     let password = params.first().and_then(|v| v.as_str()).unwrap_or_default();
//!     json!(password.len() >= 8)
//! });
//!
//! assert_eq!(hooks.trigger(appwrite_hooks::PASSWORD_VALIDATOR, &[json!("short")]), Some(json!(false)));
//! assert_eq!(hooks.trigger("unregistered", &[]), None);
//! ```

use std::collections::HashMap;
use std::sync::Arc;

use serde_json::Value;

/// PHP `Appwrite\Hooks\Hooks::trigger()` callback signature: receives the
/// positional params array and returns an arbitrary JSON value (PHP `mixed`).
pub type HookFn = dyn Fn(&[Value]) -> Value + Send + Sync;

/// Hook name used by password strength/dictionary/history checks to allow
/// the caller (server bootstrap) to plug in project-specific policy without
/// `appwrite-auth` depending on it directly.
pub const PASSWORD_VALIDATOR: &str = "passwordValidator";

/// Named hook registry. Rust port of `Appwrite\Hooks\Hooks`.
#[derive(Default)]
pub struct Hooks {
    hooks: HashMap<String, Arc<HookFn>>,
}

impl std::fmt::Debug for Hooks {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Hooks")
            .field("registered", &self.hooks.keys().collect::<Vec<_>>())
            .finish()
    }
}

impl Hooks {
    /// Create an empty hook registry.
    #[must_use]
    pub fn new() -> Self {
        Self::default()
    }

    /// PHP `Hooks::add(string $name, callable $action)`.
    pub fn add<F>(&mut self, name: impl Into<String>, action: F)
    where
        F: Fn(&[Value]) -> Value + Send + Sync + 'static,
    {
        self.hooks.insert(name.into(), Arc::new(action));
    }

    /// Remove a previously registered hook, if any.
    pub fn remove(&mut self, name: &str) {
        self.hooks.remove(name);
    }

    /// Whether a hook with `name` is registered.
    #[must_use]
    pub fn has(&self, name: &str) -> bool {
        self.hooks.contains_key(name)
    }

    /// Number of registered hooks.
    #[must_use]
    pub fn len(&self) -> usize {
        self.hooks.len()
    }

    /// Whether no hooks are registered.
    #[must_use]
    pub fn is_empty(&self) -> bool {
        self.hooks.is_empty()
    }

    /// PHP `Hooks::trigger(string $name, array $params = []): mixed`.
    ///
    /// Returns `None` when no hook is registered under `name` (PHP returns
    /// `null` in that case, which is indistinguishable from a hook that
    /// itself returns `null`; `Option` makes that distinction explicit).
    #[must_use]
    pub fn trigger(&self, name: &str, params: &[Value]) -> Option<Value> {
        self.hooks.get(name).map(|hook| hook(params))
    }
}
