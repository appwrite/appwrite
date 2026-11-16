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
mod hooks;
mod lifecycle;
mod state;

pub mod pwned;

use std::future::Future;
use std::pin::Pin;
use std::sync::Arc;

use serde_json::{Map, Value};
use utopia_http::{Method, Request, Response, Router};

pub use context::{Context, EventState, Mode, Store};
pub use hooks::resources::resource_token::ResourceToken;
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
    pub labels: Labels,
    pub action: Action,
}

/// The labels the generic hooks read.
#[derive(Debug, Clone, Copy)]
pub struct Labels {
    /// `abuse`: the route's rate limits, each counted on its own.
    pub abuse: &'static [Limit],
    /// `auth.type`: the authentication method the route uses.
    pub auth_type: &'static str,
    /// `session.allowActive`: the route may create a session for a caller
    /// who has one.
    pub session_allow_active: bool,
    /// `impersonation`: `allow` lets an impersonator write through an
    /// account route, `deny` refuses the route on any method, empty refuses
    /// account writes only.
    pub impersonation: &'static str,
    /// `error`: errors render the HTML error page for non-SDK requests
    /// (`app/views/general/error.phtml`).
    pub error_page: bool,
    /// `origin` = `*`: any origin may call the route.
    pub any_origin: bool,
    /// `cache`: a successful payload is written to the project's file cache.
    pub cache: bool,
    /// `cache.resource`: the `cache` entry's resource template
    /// (`file/{request.fileId}`), empty for none.
    pub cache_resource: &'static str,
    /// `cache.resourceType`: the `cache` entry's resource type template
    /// (`bucket/{request.bucketId}`), empty for none.
    pub cache_resource_type: &'static str,
    /// `cache.params`: the params that key the entry, `None` for all.
    pub cache_params: Option<&'static [&'static str]>,
    /// `cache.negotiate`: params that, set to `auto`, are picked from the
    /// `Accept` header: `(param, [(value, MIME type)])` in preference order.
    pub cache_negotiate: &'static [(&'static str, &'static [(&'static str, &'static str)])],
}

impl Labels {
    /// PHP's defaults for a route that sets none of them.
    pub const NONE: Labels = Labels {
        abuse: &[],
        auth_type: "",
        session_allow_active: false,
        impersonation: "",
        error_page: false,
        any_origin: false,
        cache: false,
        cache_resource: "",
        cache_resource_type: "",
        cache_params: None,
        cache_negotiate: &[],
    };
}

/// One rate limit a route declares in its `abuse` label (PHP:
/// `Appwrite\Abuse\Limit`): what the count is kept per, how many hits it
/// may take in a window, what counts as a hit, and who it counts for.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct Limit {
    /// The parts the count is kept per (`projectId`, `userId`, `userAgent`,
    /// `ip`, `url`, `method`, `chunkId` or `param.<name>`).
    pub by: &'static [&'static str],
    /// Hits per window.
    pub limit: i64,
    /// The window, in seconds.
    pub seconds: i64,
    /// Only failed requests count: each reserves its hit on arrival and a
    /// request that does not fail gives it back.
    pub failures: bool,
    /// Counts for trusted callers (keys, privileged users) too.
    pub everyone: bool,
}

impl Limit {
    /// `Limit::attempts($by, $limit, $seconds)`: every request counts.
    pub const fn attempts(by: &'static [&'static str], limit: i64, seconds: i64) -> Limit {
        Limit { by, limit, seconds, failures: false, everyone: false }
    }

    /// `Limit::failures($by, $limit, $seconds)`: only failed requests count.
    pub const fn failures(by: &'static [&'static str], limit: i64, seconds: i64) -> Limit {
        Limit { by, limit, seconds, failures: true, everyone: false }
    }

    /// `everyone: true`: the limit counts for trusted callers too.
    pub const fn everyone(self) -> Limit {
        Limit { everyone: true, ..self }
    }

    /// `Limit::counts($trusted)`.
    pub fn counts(&self, trusted: bool) -> bool {
        !trusted || self.everyone
    }

    /// `Limit::key($values)`: `part:value` for each part, comma-separated; a
    /// part the request does not carry is empty.
    pub fn key(&self, values: &[(String, String)]) -> String {
        self.by
            .iter()
            .map(|part| {
                let value = values.iter().find(|(k, _)| k == part).map(|(_, v)| v.as_str()).unwrap_or("");
                format!("{part}:{value}")
            })
            .collect::<Vec<_>>()
            .join(",")
    }
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
        Self { state, router: Router::new(), wildcard: Arc::new(hooks::wildcard::wildcard_route()), paths: Vec::new() }
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
