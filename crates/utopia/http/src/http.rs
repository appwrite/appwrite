//! The application: routes, hooks by group, request dispatch with params,
//! validators and injections, and static files.
//!
//! Routes, hooks and the mode belong to an [`Http`] value, built once and
//! shared by every request.
//!
//! PHP: `Utopia\Http\Http`, which keeps routes, hooks and the mode in
//! static properties.

use std::sync::Arc;

use serde_json::Value;

use crate::compression::Compression;
use crate::error::{Error, Result};
use crate::files::Files;
use crate::hook::{BoxFuture, Callback, Hook, Resources, Route, Scope};
use crate::params::Params;
use crate::request::Request;
use crate::response::Response;
use crate::router::{Match, PathParams, Router};
use crate::telemetry::{Attribute, Instruments, NoTelemetry, Telemetry};

/// The names of the modes an application can run in (see [`Http::set_mode`]).
///
/// PHP: `Http::MODE_TYPE_*`.
pub mod mode {
    /// Development mode.
    pub const DEVELOPMENT: &str = "development";
    /// Staging mode.
    pub const STAGE: &str = "stage";
    /// Production mode.
    pub const PRODUCTION: &str = "production";
}

/// A request's match: the route's id and its path params.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct RouteMatch {
    /// The route's id (see [`Http::route`]).
    pub id: usize,
    /// The path params, by name.
    pub params: PathParams,
}

/// Where a hook runs: the matched route, its path params and the request params.
#[derive(Clone, Copy)]
struct At<'a> {
    route: Option<&'a Route>,
    values: &'a PathParams,
    params: &'a Params,
}

/// A web application: its routes, hooks, static files, settings and shared
/// resources.
///
/// Build it once, then dispatch each request with [`Http::run`].
pub struct Http {
    router: Router<Route>,
    counter: usize,
    init: Vec<Hook>,
    shutdown: Vec<Hook>,
    options: Vec<Hook>,
    errors: Vec<Hook>,
    start: Vec<Hook>,
    request: Vec<Hook>,
    mode: String,
    files: Files,
    compression: Option<Compression>,
    resources: Arc<Resources>,
    timezone: String,
    instruments: Instruments,
}

impl Default for Http {
    /// An application in the `UTC` time zone.
    fn default() -> Self {
        Self::new("UTC")
    }
}

/// A route being configured: [`Http::get`] and the other route methods
/// return it, and its setters chain.
pub struct RouteBuilder<'h> {
    http: &'h mut Http,
    id: usize,
}

impl RouteBuilder<'_> {
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
    pub fn param(mut self, key: &str, param: crate::hook::Param) -> Self {
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
    pub fn action<F>(mut self, f: F) -> Self
    where
        F: for<'a> Callback<'a> + AsyncFn(Scope<'_>) -> Result<()> + Send + Sync + 'static,
    {
        self.route().action(f);
        self
    }

    /// Sets an already wrapped action.
    pub fn set_action(mut self, action: crate::hook::Action) -> Self {
        self.route().set_action(action);
        self
    }

    /// Also registers the route for `path`, under the same methods. Fails
    /// like [`Router::insert`] when `path` is taken.
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

impl Http {
    /// An empty application; `timezone` is the zone of the static files'
    /// `Expires` header.
    ///
    /// PHP: `new Http($adapter, $timezone)`.
    pub fn new(timezone: &str) -> Self {
        Self {
            router: Router::new(),
            counter: 0,
            init: Vec::new(),
            shutdown: Vec::new(),
            options: Vec::new(),
            errors: Vec::new(),
            start: Vec::new(),
            request: Vec::new(),
            mode: String::new(),
            files: Files::new(),
            compression: None,
            resources: Arc::new(Resources::new()),
            timezone: timezone.to_owned(),
            instruments: Instruments::new(&NoTelemetry),
        }
    }

    // -- settings ------------------------------------------------------------

    /// Sets the response compression settings; `None` (the default) turns
    /// compression off.
    ///
    /// PHP: `setCompression($enabled)`, `setCompressionMinSize()`,
    /// `setCompressionSupported()`.
    pub fn set_compression(&mut self, compression: Option<Compression>) {
        self.compression = compression;
    }

    /// Sets where the server metrics go: request duration, requests in
    /// flight, and request and response body sizes.
    ///
    /// PHP: `setTelemetry($telemetry)`.
    pub fn set_telemetry(&mut self, telemetry: &dyn Telemetry) {
        self.instruments = Instruments::new(telemetry);
    }

    /// The compression settings, when set.
    pub fn compression(&self) -> Option<&Compression> {
        self.compression.as_ref()
    }

    /// The running mode, one of the [`mode`](crate::mode) names (empty until
    /// set).
    ///
    /// PHP: `getMode()`.
    pub fn mode(&self) -> &str {
        &self.mode
    }

    /// Sets the running mode.
    ///
    /// PHP: `setMode($value)`.
    pub fn set_mode(&mut self, mode: &str) {
        self.mode = mode.to_owned();
    }

    /// Whether the mode is [`mode::PRODUCTION`].
    ///
    /// PHP: `isProduction()`.
    pub fn is_production(&self) -> bool {
        self.mode == mode::PRODUCTION
    }

    /// Whether the mode is [`mode::DEVELOPMENT`].
    ///
    /// PHP: `isDevelopment()`.
    pub fn is_development(&self) -> bool {
        self.mode == mode::DEVELOPMENT
    }

    /// Whether the mode is [`mode::STAGE`].
    ///
    /// PHP: `isStage()`.
    pub fn is_stage(&self) -> bool {
        self.mode == mode::STAGE
    }

    /// Whether a route may replace one registered for the same method and
    /// path.
    ///
    /// PHP: `getAllowOverride()`.
    pub fn allow_override(&self) -> bool {
        self.router.allow_override()
    }

    /// Sets whether a route may replace one registered for the same method
    /// and path.
    ///
    /// PHP: `setAllowOverride($value)`.
    pub fn set_allow_override(&mut self, allow: bool) {
        self.router.set_allow_override(allow);
    }

    /// The resources shared by every request: the parent of each request's
    /// context.
    ///
    /// PHP: `resources()`.
    pub fn resources(&self) -> &Arc<Resources> {
        &self.resources
    }

    /// An environment variable, or `None` when it is unset or not UTF-8.
    ///
    /// PHP: `getEnv($key, $default)`, a server variable.
    pub fn env(key: &str) -> Option<String> {
        std::env::var(key).ok()
    }

    // -- routes --------------------------------------------------------------

    /// The routing table.
    pub fn router(&self) -> &Router<Route> {
        &self.router
    }

    /// A route by id.
    pub fn route(&self, id: usize) -> Option<&Route> {
        self.router.get(id)
    }

    /// Adds one route for several methods (duplicates ignored).
    ///
    /// Fails when `methods` is empty, names a method outside
    /// [`METHODS`](crate::METHODS), or the path is already registered.
    ///
    /// PHP: `Http::routes($methods, $url)`.
    pub fn routes(&mut self, methods: &[&str], path: &str) -> Result<RouteBuilder<'_>> {
        let mut unique: Vec<&str> = Vec::with_capacity(methods.len());
        for m in methods {
            if !unique.contains(m) {
                unique.push(m);
            }
        }
        if unique.is_empty() {
            return Err(Error::Generic("At least one HTTP method is required.".into()));
        }
        if let Some(m) = unique.iter().find(|m| !crate::router::METHODS.contains(m)) {
            return Err(Error::Generic(format!("Method ({m}) not supported.")));
        }
        self.insert(&unique, path)
    }

    /// Adds a route for one method. Fails when the method is not supported
    /// or the path is already registered.
    ///
    /// PHP: `Http::addRoute($method, $url)`.
    pub fn add_route(&mut self, method: &str, path: &str) -> Result<RouteBuilder<'_>> {
        self.insert(&[method], path)
    }

    /// Adds a route for `methods` and `path` without the checks of
    /// [`Http::routes`] (duplicates, an empty list); the router still
    /// rejects unsupported methods and taken paths.
    ///
    /// PHP: `Router::addRoute(new Route($methods, $path))`.
    pub fn insert(&mut self, methods: &[&str], path: &str) -> Result<RouteBuilder<'_>> {
        self.counter += 1;
        let route = Route::new(methods, path, self.counter);
        let id = self.router.insert(methods, path, route)?;
        Ok(RouteBuilder { http: self, id })
    }

    /// Adds a `GET` route (which also answers `HEAD`).
    ///
    /// PHP: `Http::get($url)`.
    pub fn get(&mut self, path: &str) -> Result<RouteBuilder<'_>> {
        self.routes(&["GET"], path)
    }

    /// Adds a `POST` route.
    ///
    /// PHP: `Http::post($url)`.
    pub fn post(&mut self, path: &str) -> Result<RouteBuilder<'_>> {
        self.routes(&["POST"], path)
    }

    /// Adds a `PUT` route.
    ///
    /// PHP: `Http::put($url)`.
    pub fn put(&mut self, path: &str) -> Result<RouteBuilder<'_>> {
        self.routes(&["PUT"], path)
    }

    /// Adds a `PATCH` route.
    ///
    /// PHP: `Http::patch($url)`.
    pub fn patch(&mut self, path: &str) -> Result<RouteBuilder<'_>> {
        self.routes(&["PATCH"], path)
    }

    /// Adds a `DELETE` route.
    ///
    /// PHP: `Http::delete($url)`.
    pub fn delete(&mut self, path: &str) -> Result<RouteBuilder<'_>> {
        self.routes(&["DELETE"], path)
    }

    /// Sets the route for requests nothing else matches, whatever their
    /// method; a second call replaces the first.
    ///
    /// PHP: `Http::wildcard()`.
    pub fn wildcard(&mut self) -> RouteBuilder<'_> {
        self.counter += 1;
        let id = self.router.push(Route::new(&[""], "", self.counter));
        self.router.set_wildcard(Some(id));
        RouteBuilder { http: self, id }
    }

    // -- hooks ---------------------------------------------------------------

    /// Adds a hook that runs before the routes of its groups (by default
    /// `*`: every route).
    ///
    /// PHP: `Http::init()`.
    pub fn init(&mut self) -> &mut Hook {
        self.init.push(Hook::with_groups(&["*"]));
        self.init.last_mut().unwrap_or_else(|| unreachable!())
    }

    /// Adds a hook that runs after the routes of its groups (by default `*`:
    /// every route).
    ///
    /// PHP: `Http::shutdown()`.
    pub fn shutdown(&mut self) -> &mut Hook {
        self.shutdown.push(Hook::with_groups(&["*"]));
        self.shutdown.last_mut().unwrap_or_else(|| unreachable!())
    }

    /// Adds a hook that answers `OPTIONS` requests for the routes of its
    /// groups (by default `*`: every request).
    ///
    /// PHP: `Http::options()`.
    pub fn options(&mut self) -> &mut Hook {
        self.options.push(Hook::with_groups(&["*"]));
        self.options.last_mut().unwrap_or_else(|| unreachable!())
    }

    /// Adds a hook that runs when a route or hook fails, or nothing matches
    /// (with a 404 [`Error::Http`]); the failure is its `error` resource
    /// (see [`Scope::error`]).
    ///
    /// PHP: `Http::error()`.
    pub fn error(&mut self) -> &mut Hook {
        self.errors.push(Hook::with_groups(&["*"]));
        self.errors.last_mut().unwrap_or_else(|| unreachable!())
    }

    /// Adds a hook that runs once when the server starts (see
    /// [`Http::start`]).
    ///
    /// PHP: `Http::onStart()`.
    pub fn on_start(&mut self) -> &mut Hook {
        self.start.push(Hook::new());
        self.start.last_mut().unwrap_or_else(|| unreachable!())
    }

    /// Adds a hook that runs first for every request, before static files
    /// and routing.
    ///
    /// PHP: `Http::onRequest()`.
    pub fn on_request(&mut self) -> &mut Hook {
        self.request.push(Hook::new());
        self.request.last_mut().unwrap_or_else(|| unreachable!())
    }

    // -- files ---------------------------------------------------------------

    /// Loads the files under `directory` to serve as static files (see
    /// [`Files::load`]).
    ///
    /// PHP: `loadFiles($directory, $root)`.
    pub fn load_files(&mut self, directory: &str, root: Option<&str>) -> Result<()> {
        self.files.load(directory, root)
    }

    /// The loaded static files.
    pub fn files(&self) -> &Files {
        &self.files
    }

    // -- dispatch ------------------------------------------------------------

    /// The route for the request's method (`HEAD` as `GET`) and path, with
    /// its path params.
    ///
    /// PHP: `match($request)`.
    pub fn find(&self, request: &Request) -> Option<Match<'_, Route>> {
        let path = request.route_path();
        let method = if request.method() == "HEAD" { "GET" } else { request.method() };
        self.router.find(method, &path)
    }

    /// The request's match as ids (see [`Http::find`]).
    pub fn match_request(&self, request: &Request) -> Option<RouteMatch> {
        self.find(request).map(|m| RouteMatch { id: m.id, params: m.params })
    }

    /// Runs a hook: resolves its arguments and calls its action.
    async fn call(
        &self,
        hook: &Hook,
        at: At<'_>,
        request: &Request,
        response: &mut Response,
        context: &Arc<Resources>,
    ) -> Result<()> {
        let (args, injected) = self.arguments(hook, at.values, at.params, context)?;
        let Some(action) = hook.get_action() else { return Ok(()) };
        let scope = Scope { http: self, request, response, route: at.route, path: at.values, context, args, injected };
        action(scope).await
    }

    /// Matches the request and runs the route between its init and shutdown
    /// hooks. `HEAD` runs as `GET` without a body; `OPTIONS` runs the options
    /// hooks only; a request nothing matches runs the global error hooks with
    /// a 404.
    ///
    /// A failure is handed to the error hooks of the route's groups, then to
    /// the global ones; an error is returned only when an error hook itself
    /// fails.
    ///
    /// Re-entrant: an action can dispatch a sub-request with its own request
    /// and response.
    ///
    /// PHP: `execute($request, $response)`.
    pub fn execute<'a>(
        &'a self,
        request: &'a Request,
        response: &'a mut Response,
        context: &'a Arc<Resources>,
    ) -> BoxFuture<'a, Result<()>> {
        Box::pin(async move {
            let mut method = request.method();
            if method == "HEAD" {
                method = "GET";
                response.disable_payload();
            }
            let matched = self.find(request);
            let params = request.params();

            if method == "OPTIONS" {
                let route = matched.as_ref().map(|m| m.route);
                let groups: Vec<String> = route.map(|r| r.get_groups().to_vec()).unwrap_or_default();
                let empty = PathParams::new();
                let result: Result<()> = async {
                    for group in &groups {
                        for hook in self.options.iter().filter(|h| h.in_group(group)) {
                            self.call(hook, At { values: &empty, params, route }, request, response, context).await?;
                        }
                    }
                    for hook in self.options.iter().filter(|h| h.in_group("*")) {
                        self.call(hook, At { values: &empty, params, route }, request, response, context).await?;
                    }
                    Ok(())
                }
                .await;
                if let Err(e) = result {
                    for hook in self.errors.iter().filter(|h| h.in_group("*")) {
                        context.set("error", Arc::new(e.clone()));
                        self.call(hook, At { values: &empty, params, route }, request, response, context).await?;
                    }
                }
                return Ok(());
            }

            let Some(matched) = matched else {
                let empty = PathParams::new();
                for hook in self.errors.iter().filter(|h| h.in_group("*")) {
                    context.set("error", Arc::new(Error::http("Not Found", 404)));
                    self.call(hook, At { values: &empty, params, route: None }, request, response, context).await?;
                }
                return Ok(());
            };

            let route = matched.route;
            let values = &matched.params;
            let groups = route.get_groups();
            let result: Result<()> = async {
                if route.hooks() {
                    for hook in self.init.iter().filter(|h| h.in_group("*")) {
                        self.call(hook, At { values, params, route: Some(route) }, request, response, context).await?;
                    }
                }
                for group in groups {
                    for hook in self.init.iter().filter(|h| h.in_group(group)) {
                        self.call(hook, At { values, params, route: Some(route) }, request, response, context).await?;
                    }
                }
                if !response.is_sent() {
                    self.call(route.hook(), At { values, params, route: Some(route) }, request, response, context)
                        .await?;
                }
                for group in groups {
                    for hook in self.shutdown.iter().filter(|h| h.in_group(group)) {
                        self.call(hook, At { values, params, route: Some(route) }, request, response, context).await?;
                    }
                }
                if route.hooks() {
                    for hook in self.shutdown.iter().filter(|h| h.in_group("*")) {
                        self.call(hook, At { values, params, route: Some(route) }, request, response, context).await?;
                    }
                }
                Ok(())
            }
            .await;

            if let Err(e) = result {
                context.set("error", Arc::new(e.clone()));
                for group in groups {
                    for hook in self.errors.iter().filter(|h| h.in_group(group)) {
                        if let Err(failure) =
                            self.call(hook, At { values, params, route: Some(route) }, request, response, context).await
                        {
                            return Err(Error::handler(&failure, e));
                        }
                    }
                }
                for hook in self.errors.iter().filter(|h| h.in_group("*")) {
                    if let Err(failure) =
                        self.call(hook, At { values, params, route: Some(route) }, request, response, context).await
                    {
                        return Err(Error::handler(&failure, e));
                    }
                }
            }
            Ok(())
        })
    }

    /// Dispatches a request through the whole lifecycle: compression
    /// settings, request hooks, static files, then [`Http::execute`], and
    /// records the server metrics.
    ///
    /// `context` is the request's context, from [`Http::context`].
    ///
    /// PHP: `run($request, $response)`.
    pub async fn run(&self, request: &Request, response: &mut Response, context: &Arc<Resources>) -> Result<()> {
        let method = Attribute::Str(request.method().to_owned());
        let scheme = Attribute::Str(request.protocol());
        self.instruments
            .active_requests
            .add(1, &[("http.request.method", method.clone()), ("url.scheme", scheme.clone())]);
        let start = std::time::Instant::now();
        self.run_internal(request, response, context).await?;
        let duration = start.elapsed().as_secs_f64();
        let route = match self.find(request) {
            Some(m) if !m.route.path().is_empty() => Attribute::Str(m.route.path().to_owned()),
            _ => Attribute::None,
        };
        let attributes = [
            ("url.scheme", scheme.clone()),
            ("http.request.method", method.clone()),
            ("http.route", route),
            ("http.response.status_code", Attribute::Int(i64::from(response.status_code()))),
        ];
        self.instruments.request_duration.record(duration, &attributes);
        self.instruments.request_body_size.record(request.size() as f64, &attributes);
        self.instruments.response_body_size.record(response.size() as f64, &attributes);
        self.instruments.active_requests.add(-1, &[("http.request.method", method), ("url.scheme", scheme)]);
        Ok(())
    }

    async fn run_internal(&self, request: &Request, response: &mut Response, context: &Arc<Resources>) -> Result<()> {
        if let Some(compression) = &self.compression {
            let accept = request.header_line_or("accept-encoding", "");
            response.set_compression(&accept, compression);
        }
        context.set("request", Arc::new(()));
        context.set("response", Arc::new(()));

        let empty = PathParams::new();
        let none = Params::new();
        for hook in &self.request {
            if let Err(e) =
                self.call(hook, At { values: &empty, params: &none, route: None }, request, response, context).await
            {
                context.set("error", Arc::new(e.clone()));
                for error in self.errors.iter().filter(|h| h.in_group("*")) {
                    if let Err(failure) = self
                        .call(error, At { values: &empty, params: &none, route: None }, request, response, context)
                        .await
                    {
                        return Err(Error::handler(&failure, e));
                    }
                }
                break;
            }
        }

        if self.files.is_loaded(request.uri()) {
            let file = self.files.get(request.uri())?;
            let time: i64 = 60 * 60 * 24 * 365 * 2;
            let expires = crate::response::Response::now() + time;
            let tz = php_std::datetime::TzInfo::get(&self.timezone).unwrap_or_else(php_std::datetime::TzInfo::utc);
            let date = php_std::datetime::date(b"D, d M Y H:i:s", expires, &tz);
            response
                .set_content_type(&file.mime, "")
                .add_header("Cache-Control", format!("public, max-age={time}"))
                .add_header("Expires", format!("{} GMT", String::from_utf8_lossy(&date)));
            response.send(&file.contents);
            return Ok(());
        }

        self.execute(request, response, context).await
    }

    /// Runs the start hooks, with `server` registered as the `server`
    /// resource. A failing hook stops the others and runs the global error
    /// hooks.
    ///
    /// PHP: `start()`.
    pub async fn start(&self, server: crate::hook::Resource) -> Result<()> {
        self.resources.set("server", server);
        let mut request = Request::new("GET", "/", std::iter::empty(), bytes::Bytes::new());
        request.set_method("");
        let mut response = Response::new();
        let empty = PathParams::new();
        let none = Params::new();
        for hook in &self.start {
            if let Err(e) = self
                .call(hook, At { values: &empty, params: &none, route: None }, &request, &mut response, &self.resources)
                .await
            {
                self.resources.set("error", Arc::new(e.clone()));
                for error in self.errors.iter().filter(|h| h.in_group("*")) {
                    if let Err(failure) = self
                        .call(
                            error,
                            At { values: &empty, params: &none, route: None },
                            &request,
                            &mut response,
                            &self.resources,
                        )
                        .await
                    {
                        return Err(Error::handler(&failure, e));
                    }
                }
                break;
            }
        }
        Ok(())
    }

    /// A new request context: a resource container whose parent is
    /// [`Http::resources`].
    ///
    /// PHP: `context()`.
    pub fn context(&self) -> Arc<Resources> {
        Arc::new(Resources::child(self.resources.clone()))
    }
}
