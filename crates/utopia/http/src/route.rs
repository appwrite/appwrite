//! Routes: hooks bound to HTTP methods and a path.
//!
//! PHP: `Utopia\Http\Route`.

use crate::hook::Hook;

/// A route: a hook bound to HTTP methods and a path.
///
/// A route dereferences to its [`Hook`], so the hook's setters and getters
/// apply to it directly.
///
/// PHP: `Utopia\Http\Route`.
#[derive(Clone)]
pub struct Route {
    hook: Hook,
    methods: Vec<String>,
    path: String,
    hooks: bool,
    aliases: Vec<String>,
    order: usize,
}

impl std::ops::Deref for Route {
    type Target = Hook;
    /// The route's hook.
    fn deref(&self) -> &Hook {
        &self.hook
    }
}

impl std::ops::DerefMut for Route {
    /// The route's hook, mutably.
    fn deref_mut(&mut self) -> &mut Hook {
        &mut self.hook
    }
}

impl Route {
    /// A route for `methods` (deduplicated, in order) and `path`; `order` is
    /// its registration number.
    ///
    /// PHP: `new Route($methods, $path)`.
    pub fn new(methods: &[&str], path: &str, order: usize) -> Self {
        let mut unique: Vec<String> = Vec::with_capacity(methods.len());
        for m in methods {
            if !unique.iter().any(|u| u == m) {
                unique.push((*m).to_owned());
            }
        }
        Self { hook: Hook::new(), methods: unique, path: path.to_owned(), hooks: true, aliases: Vec::new(), order }
    }

    /// The registration number: routes are numbered from 1 as they are
    /// added to an application.
    ///
    /// PHP: `getOrder()`.
    pub fn order(&self) -> usize {
        self.order
    }

    /// Changes the path the route reports (not the one it is registered
    /// under).
    ///
    /// PHP: `path($path)`.
    pub fn set_path(&mut self, path: &str) -> &mut Self {
        self.path = path.to_owned();
        self
    }

    /// The route's path template, such as `/users/:id`.
    ///
    /// PHP: `getPath()`.
    pub fn path(&self) -> &str {
        &self.path
    }

    /// The HTTP methods the route is registered under.
    ///
    /// PHP: `getMethods()`.
    pub fn methods(&self) -> &[String] {
        &self.methods
    }

    /// The primary (first) method, or `""` when there is none.
    ///
    /// PHP: `getMethod()` (deprecated).
    pub fn method(&self) -> &str {
        self.methods.first().map(String::as_str).unwrap_or("")
    }

    /// Sets whether the application's global (`*`) init and shutdown hooks
    /// run around this route.
    ///
    /// PHP: `hook($hook)`.
    pub fn set_hooks(&mut self, hooks: bool) -> &mut Self {
        self.hooks = hooks;
        self
    }

    /// Whether the global init and shutdown hooks run around this route.
    ///
    /// PHP: `getHook()`.
    pub fn hooks(&self) -> bool {
        self.hooks
    }

    /// Alias paths this route is also registered under.
    pub fn aliases(&self) -> &[String] {
        &self.aliases
    }

    pub(crate) fn push_alias(&mut self, path: &str) {
        if !self.aliases.iter().any(|a| a == path) {
            self.aliases.push(path.to_owned());
        }
    }

    /// The hook part of the route.
    pub fn hook(&self) -> &Hook {
        &self.hook
    }
}
