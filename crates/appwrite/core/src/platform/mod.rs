//! The Appwrite platform: modules, routes, shared state and the request
//! lifecycle (`Appwrite\Platform` + `app/controllers/general.php` +
//! `app/controllers/shared/api.php`).
//!
//! ## Extending CE (Appwrite Cloud)
//!
//! * register additional [`Module`]s (new services or replacements);
//! * implement [`Hooks`] to inject plan limits, extra password policies or
//!   per-request checks;
//! * build a binary that composes CE modules with Cloud modules.
//!
//! None of this requires changes to CE crates.

mod context;
mod lifecycle;
mod shutdown;
mod state;

pub mod pwned;

use std::future::Future;
use std::pin::Pin;
use std::sync::Arc;

use serde_json::{Map, Value};
use utopia_http::{Method, Request, Response, Router};

pub use context::{Context, EventState, Mode};
pub use state::State;

use crate::Result;
use crate::database::Project;
use crate::database::documents::User;

/// Boxed future returned by actions.
pub type BoxFuture<'a, T> = Pin<Box<dyn Future<Output = T> + Send + 'a>>;

/// An HTTP action.
pub type Action = for<'a> fn(&'a mut Context) -> BoxFuture<'a, Result<Response>>;

/// Audit labels (`audits.event`, `audits.resource`).
#[derive(Debug, Clone, Copy)]
pub struct Audit {
    pub event: &'static str,
    pub resource: &'static str,
}

/// A route and its labels (`Utopia\Platform\Action`).
#[derive(Clone)]
pub struct Route {
    pub method: Method,
    pub path: &'static str,
    /// `getName()`.
    pub name: &'static str,
    /// SDK namespace (`users`), used for service toggles and onboarding.
    pub namespace: &'static str,
    /// SDK method name (`create`), used for onboarding and format filters.
    pub sdk_method: &'static str,
    /// Any-of scopes.
    pub scopes: &'static [&'static str],
    /// Event pattern (`users.[userId].update.name`).
    pub event: Option<&'static str>,
    pub audit: Option<Audit>,
    pub groups: &'static [&'static str],
    pub action: Action,
}

impl Route {
    pub fn has_group(&self, group: &str) -> bool {
        self.groups.contains(&group)
    }
}

/// Wraps an `async fn(&mut Context) -> Result<Response>` into an [`Action`].
#[macro_export]
macro_rules! action {
    ($f:path) => {{
        fn __action(
            ctx: &mut $crate::platform::Context,
        ) -> $crate::platform::BoxFuture<'_, $crate::Result<$crate::platform::Reply>> {
            Box::pin($f(ctx))
        }
        __action as $crate::platform::Action
    }};
}

/// Response type returned by actions.
pub type Reply = Response;

/// A service module (`Utopia\Platform\Module`).
pub trait Module: Send + Sync {
    fn name(&self) -> &'static str;
    fn routes(&self) -> Vec<Route>;
}

/// Extension points for editions built on top of CE (e.g. Appwrite Cloud).
///
/// All methods have CE defaults. Hooks are called a handful of times per
/// request at most, so dynamic dispatch is negligible.
pub trait Hooks: Send + Sync + 'static {
    /// Plan flags for a project (`$plan`); `None` means no plan restrictions.
    fn plan(&self, _project: &Project) -> Option<Map<String, Value>> {
        None
    }

    /// `passwordValidator` hook, called before storing a plaintext password.
    fn password_validator<'a>(
        &'a self,
        _ctx: &'a Context,
        _password: &'a str,
        _user: Option<&'a User>,
    ) -> BoxFuture<'a, Result<()>> {
        Box::pin(async { Ok(()) })
    }

    /// Runs after authentication, before the action.
    fn init<'a>(&'a self, _ctx: &'a mut Context) -> BoxFuture<'a, Result<()>> {
        Box::pin(async { Ok(()) })
    }
}

/// CE hooks (no-ops).
pub struct DefaultHooks;

impl Hooks for DefaultHooks {}

/// The composed API: routes from all modules plus the shared state.
pub struct Platform {
    state: Arc<State>,
    router: Router<Arc<Route>>,
    wildcard: Arc<Route>,
    paths: Vec<(Method, &'static str)>,
}

impl Platform {
    pub fn new(state: Arc<State>) -> Self {
        Self { state, router: Router::new(), wildcard: Arc::new(lifecycle::wildcard_route()), paths: Vec::new() }
    }

    /// Registers every route of a module.
    pub fn module(mut self, module: &dyn Module) -> Self {
        for route in module.routes() {
            self.paths.push((route.method.clone(), route.path));
            let method = route.method.clone();
            let path = route.path;
            self.router.add(method, path, Arc::new(route));
        }
        tracing::info!(module = module.name(), "module registered");
        self
    }

    pub fn state(&self) -> &Arc<State> {
        &self.state
    }

    /// Registered `(method, path)` pairs (used to generate proxy rules).
    pub fn paths(&self) -> &[(Method, &'static str)] {
        &self.paths
    }
}

impl utopia_http::Handler for Platform {
    fn handle(&self, request: Request) -> impl Future<Output = Response> + Send {
        lifecycle::run(self, request)
    }
}
