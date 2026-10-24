use serde_json::Value;

use crate::error::Result;
use crate::hook::{Action, Callback, IntoResult, Param, Scope};
use crate::http::Http;
use crate::route::Route;

/// A route being configured: [`Http::get`] and the other route methods
/// return it, and its setters chain.
pub struct RouteBuilder<'h> {
    http: &'h mut Http,
    id: usize,
}

impl<'h> RouteBuilder<'h> {
    pub(super) fn new(http: &'h mut Http, id: usize) -> Self {
        Self { http, id }
    }

    fn route(&mut self) -> &mut Route {
        self.http.router.get_mut(self.id).unwrap_or_else(|| unreachable!("route ids are stable"))
    }

    /// The route's id.
    pub fn id(&self) -> usize {
        self.id
    }

    /// Sets the route's description.
    ///
    /// PHP: `desc($desc)`.
    pub fn desc(mut self, desc: &str) -> Self {
        self.route().desc(desc);
        self
    }

    /// Sets the route's groups: the init, shutdown, options and error hooks
    /// of these groups run for it.
    ///
    /// PHP: `groups($groups)`.
    pub fn groups(mut self, groups: &[&str]) -> Self {
        self.route().groups(groups);
        self
    }

    /// Sets a label: free-form metadata under `key`.
    ///
    /// PHP: `label($key, $value)`.
    pub fn label(mut self, key: &str, value: Value) -> Self {
        self.route().label(key, value);
        self
    }

    /// Declares a param under `key`.
    ///
    /// PHP: `param($key, ...)`.
    pub fn param(mut self, key: &str, param: Param) -> Self {
        self.route().param(key, param);
        self
    }

    /// Declares a resource to inject by name.
    ///
    /// PHP: `inject($name)`.
    pub fn inject(mut self, name: &str) -> Self {
        self.route().inject(name);
        self
    }

    /// Sets whether the global (`*`) init and shutdown hooks run around the
    /// route.
    ///
    /// PHP: `hook($hook)`.
    pub fn hook(mut self, hooks: bool) -> Self {
        self.route().set_hooks(hooks);
        self
    }

    /// Changes the path the route reports (not the one it matches).
    ///
    /// PHP: `path($path)`.
    pub fn path(mut self, path: &str) -> Self {
        self.route().set_path(path);
        self
    }

    /// Sets the route's callback, an async closure that receives the call's
    /// [`Scope`].
    ///
    /// PHP: `action($callback)`.
    pub fn action<F, R>(mut self, f: F) -> Self
    where
        F: for<'a> Callback<'a> + AsyncFn(Scope<'_>) -> R + Send + Sync + 'static,
        R: IntoResult,
    {
        self.route().action(f);
        self
    }

    /// Sets an already wrapped action.
    pub fn set_action(mut self, action: Action) -> Self {
        self.route().set_action(action);
        self
    }

    /// Also registers the route for `path`, under the same methods. Fails
    /// like [`Router::insert`](crate::Router::insert) when `path` is taken.
    ///
    /// PHP: `alias($path)`.
    pub fn alias(&mut self, path: &str) -> Result<&mut Self> {
        let methods: Vec<String> = self.route().methods().to_vec();
        let methods: Vec<&str> = methods.iter().map(String::as_str).collect();
        let id = self.id;
        self.http.router.alias(id, &methods, path)?;
        self.route().push_alias(path);
        Ok(self)
    }

    /// The route being configured.
    pub fn get(&mut self) -> &mut Route {
        self.route()
    }
}
