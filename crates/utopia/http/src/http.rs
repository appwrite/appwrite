//! `Utopia\Http\Http`: the application. Routes, hooks by group, request
//! dispatch with params, validators and injections, static files.
//!
//! PHP keeps routes, hooks and the mode in static properties; here they
//! belong to an [`Http`] value, built once and shared by every request.

use std::sync::Arc;

use serde_json::Value;

use crate::compression::Compression;
use crate::error::{Error, Result};
use crate::files::Files;
use crate::hook::{BoxFuture, Hook, Resources, Route, Scope};
use crate::params::Params;
use crate::request::Request;
use crate::response::Response;
use crate::router::{Match, PathParams, Router};

/// The running mode (`Http::MODE_TYPE_*`).
pub mod mode {
    pub const DEVELOPMENT: &str = "development";
    pub const STAGE: &str = "stage";
    pub const PRODUCTION: &str = "production";
}

/// A request's match: the route's id and its path params.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct RouteMatch {
    pub id: usize,
    pub params: PathParams,
}

/// The application.
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
}

impl Default for Http {
    fn default() -> Self {
        Self::new("UTC")
    }
}

/// A route being configured (`Http::get()` and friends return it).
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

    /// `desc($desc)`.
    pub fn desc(mut self, desc: &str) -> Self {
        self.route().desc(desc);
        self
    }

    /// `groups($groups)`.
    pub fn groups(mut self, groups: &[&str]) -> Self {
        self.route().groups(groups);
        self
    }

    /// `label($key, $value)`.
    pub fn label(mut self, key: &str, value: Value) -> Self {
        self.route().label(key, value);
        self
    }

    /// `param($key, ...)`.
    pub fn param(mut self, key: &str, param: crate::hook::Param) -> Self {
        self.route().param(key, param);
        self
    }

    /// `inject($name)`.
    pub fn inject(mut self, name: &str) -> Self {
        self.route().inject(name);
        self
    }

    /// `hook($hook)`: whether global init and shutdown hooks run.
    pub fn hook(mut self, hooks: bool) -> Self {
        self.route().set_hooks(hooks);
        self
    }

    /// `path($path)`: changes the reported path.
    pub fn path(mut self, path: &str) -> Self {
        self.route().set_path(path);
        self
    }

    /// `action($callback)`.
    pub fn action<F>(mut self, f: F) -> Self
    where
        F: for<'a> Fn(Scope<'a>) -> BoxFuture<'a, Result<()>> + Send + Sync + 'static,
    {
        self.route().action(f);
        self
    }

    /// Sets an already wrapped action.
    pub fn set_action(mut self, action: crate::hook::Action) -> Self {
        self.route().set_action(action);
        self
    }

    /// `alias($path)`: also registers the route for `path`.
    pub fn alias(&mut self, path: &str) -> Result<&mut Self> {
        let methods: Vec<String> = self.route().methods().to_vec();
        let methods: Vec<&str> = methods.iter().map(String::as_str).collect();
        let id = self.id;
        self.http.router.alias(id, &methods, path)?;
        self.route().push_alias(path);
        Ok(self)
    }

    /// The route.
    pub fn get(&mut self) -> &mut Route {
        self.route()
    }
}

impl Http {
    /// `new Http($adapter, $timezone)`: `timezone` is the zone of the static
    /// files' `Expires` header.
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
        }
    }

    // -- settings ------------------------------------------------------------

    /// `setCompression($enabled)`, `setCompressionMinSize()`, `setCompressionSupported()`.
    pub fn set_compression(&mut self, compression: Option<Compression>) {
        self.compression = compression;
    }

    /// The compression settings, when enabled.
    pub fn compression(&self) -> Option<&Compression> {
        self.compression.as_ref()
    }

    /// `getMode()`.
    pub fn mode(&self) -> &str {
        &self.mode
    }

    /// `setMode($value)`.
    pub fn set_mode(&mut self, mode: &str) {
        self.mode = mode.to_owned();
    }

    /// `isProduction()`.
    pub fn is_production(&self) -> bool {
        self.mode == mode::PRODUCTION
    }

    /// `isDevelopment()`.
    pub fn is_development(&self) -> bool {
        self.mode == mode::DEVELOPMENT
    }

    /// `isStage()`.
    pub fn is_stage(&self) -> bool {
        self.mode == mode::STAGE
    }

    /// `getAllowOverride()`.
    pub fn allow_override(&self) -> bool {
        self.router.allow_override()
    }

    /// `setAllowOverride($value)`.
    pub fn set_allow_override(&mut self, allow: bool) {
        self.router.set_allow_override(allow);
    }

    /// `resources()`: resources shared by every request.
    pub fn resources(&self) -> &Arc<Resources> {
        &self.resources
    }

    /// `getEnv($key, $default)`: a server variable (the environment).
    pub fn env(key: &str) -> Option<String> {
        std::env::var(key).ok()
    }

    // -- routes --------------------------------------------------------------

    /// The router.
    pub fn router(&self) -> &Router<Route> {
        &self.router
    }

    /// A route by id.
    pub fn route(&self, id: usize) -> Option<&Route> {
        self.router.get(id)
    }

    /// `Http::routes($methods, $url)`: one route for several methods.
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

    /// `Http::addRoute($method, $url)`.
    pub fn add_route(&mut self, method: &str, path: &str) -> Result<RouteBuilder<'_>> {
        self.insert(&[method], path)
    }

    /// `Router::addRoute(new Route($methods, $path))`: no method validation before the router's own.
    pub fn insert(&mut self, methods: &[&str], path: &str) -> Result<RouteBuilder<'_>> {
        self.counter += 1;
        let route = Route::new(methods, path, self.counter);
        let id = self.router.insert(methods, path, route)?;
        Ok(RouteBuilder { http: self, id })
    }

    /// `Http::get($url)`.
    pub fn get(&mut self, path: &str) -> Result<RouteBuilder<'_>> {
        self.routes(&["GET"], path)
    }

    /// `Http::post($url)`.
    pub fn post(&mut self, path: &str) -> Result<RouteBuilder<'_>> {
        self.routes(&["POST"], path)
    }

    /// `Http::put($url)`.
    pub fn put(&mut self, path: &str) -> Result<RouteBuilder<'_>> {
        self.routes(&["PUT"], path)
    }

    /// `Http::patch($url)`.
    pub fn patch(&mut self, path: &str) -> Result<RouteBuilder<'_>> {
        self.routes(&["PATCH"], path)
    }

    /// `Http::delete($url)`.
    pub fn delete(&mut self, path: &str) -> Result<RouteBuilder<'_>> {
        self.routes(&["DELETE"], path)
    }

    /// `Http::wildcard()`: the route for requests nothing else matches.
    pub fn wildcard(&mut self) -> RouteBuilder<'_> {
        self.counter += 1;
        let id = self.router.push(Route::new(&[""], "", self.counter));
        self.router.set_wildcard(Some(id));
        RouteBuilder { http: self, id }
    }

    // -- hooks ---------------------------------------------------------------

    /// `Http::init()`: runs before routes of its groups (`*`: every route).
    pub fn init(&mut self) -> &mut Hook {
        self.init.push(Hook::with_groups(&["*"]));
        self.init.last_mut().unwrap_or_else(|| unreachable!())
    }

    /// `Http::shutdown()`: runs after routes of its groups.
    pub fn shutdown(&mut self) -> &mut Hook {
        self.shutdown.push(Hook::with_groups(&["*"]));
        self.shutdown.last_mut().unwrap_or_else(|| unreachable!())
    }

    /// `Http::options()`: runs for `OPTIONS` requests.
    pub fn options(&mut self) -> &mut Hook {
        self.options.push(Hook::with_groups(&["*"]));
        self.options.last_mut().unwrap_or_else(|| unreachable!())
    }

    /// `Http::error()`: runs when a route or hook fails, or nothing matches.
    pub fn error(&mut self) -> &mut Hook {
        self.errors.push(Hook::with_groups(&["*"]));
        self.errors.last_mut().unwrap_or_else(|| unreachable!())
    }

    /// `Http::onStart()`: runs when the server starts.
    pub fn on_start(&mut self) -> &mut Hook {
        self.start.push(Hook::new());
        self.start.last_mut().unwrap_or_else(|| unreachable!())
    }

    /// `Http::onRequest()`: runs first for every request.
    pub fn on_request(&mut self) -> &mut Hook {
        self.request.push(Hook::new());
        self.request.last_mut().unwrap_or_else(|| unreachable!())
    }

    // -- files ---------------------------------------------------------------

    /// `loadFiles($directory, $root)`.
    pub fn load_files(&mut self, directory: &str, root: Option<&str>) -> Result<()> {
        self.files.load(directory, root)
    }

    /// The loaded static files.
    pub fn files(&self) -> &Files {
        &self.files
    }

    // -- dispatch ------------------------------------------------------------

    /// `match($request)`: the route for the request's method (HEAD as GET)
    /// and path.
    pub fn find(&self, request: &Request) -> Option<Match<'_, Route>> {
        let uri = request.uri();
        let path = php_std::url::parse_url(uri.as_bytes()).and_then(|u| u.path().map(|p| p.into_owned()));
        let path = match path {
            Some(p) if p.is_empty() => "/".to_owned(),
            Some(p) => String::from_utf8_lossy(&p).into_owned(),
            None => "/".to_owned(),
        };
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
        values: &PathParams,
        params: &Params,
        route: Option<&Route>,
        request: &Request,
        response: &mut Response,
        context: &Arc<Resources>,
    ) -> Result<()> {
        let (args, injected) = self.arguments(hook, values, params, context)?;
        let Some(action) = hook.get_action() else { return Ok(()) };
        let scope = Scope { http: self, request, response, route, path: values, context, args, injected };
        action(scope).await
    }

    /// `execute($request, $response)`: matches the request and runs the
    /// route with its init, shutdown and error hooks. HEAD runs as GET without
    /// a body; OPTIONS runs the options hooks only; a request nothing matches
    /// runs the global error hooks with a 404.
    ///
    /// Re-entrant: an action can dispatch a sub-request with its own request
    /// and response.
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
                            self.call(hook, &empty, params, route, request, response, context).await?;
                        }
                    }
                    for hook in self.options.iter().filter(|h| h.in_group("*")) {
                        self.call(hook, &empty, params, route, request, response, context).await?;
                    }
                    Ok(())
                }
                .await;
                if let Err(e) = result {
                    for hook in self.errors.iter().filter(|h| h.in_group("*")) {
                        context.set("error", Arc::new(e.clone()));
                        self.call(hook, &empty, params, route, request, response, context).await?;
                    }
                }
                return Ok(());
            }

            let Some(matched) = matched else {
                let empty = PathParams::new();
                for hook in self.errors.iter().filter(|h| h.in_group("*")) {
                    context.set("error", Arc::new(Error::http("Not Found", 404)));
                    self.call(hook, &empty, params, None, request, response, context).await?;
                }
                return Ok(());
            };

            let route = matched.route;
            let values = &matched.params;
            let groups = route.get_groups();
            let result: Result<()> = async {
                if route.hooks() {
                    for hook in self.init.iter().filter(|h| h.in_group("*")) {
                        self.call(hook, values, params, Some(route), request, response, context).await?;
                    }
                }
                for group in groups {
                    for hook in self.init.iter().filter(|h| h.in_group(group)) {
                        self.call(hook, values, params, Some(route), request, response, context).await?;
                    }
                }
                if !response.is_sent() {
                    self.call(route.hook(), values, params, Some(route), request, response, context).await?;
                }
                for group in groups {
                    for hook in self.shutdown.iter().filter(|h| h.in_group(group)) {
                        self.call(hook, values, params, Some(route), request, response, context).await?;
                    }
                }
                if route.hooks() {
                    for hook in self.shutdown.iter().filter(|h| h.in_group("*")) {
                        self.call(hook, values, params, Some(route), request, response, context).await?;
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
                            self.call(hook, values, params, Some(route), request, response, context).await
                        {
                            return Err(Error::handler(&failure, e));
                        }
                    }
                }
                for hook in self.errors.iter().filter(|h| h.in_group("*")) {
                    if let Err(failure) = self.call(hook, values, params, Some(route), request, response, context).await
                    {
                        return Err(Error::handler(&failure, e));
                    }
                }
            }
            Ok(())
        })
    }

    /// `run($request, $response)`: the top-level request lifecycle:
    /// compression settings, request hooks, static files, then
    /// [`Http::execute`]. `context` is the request's context (a child of
    /// [`Http::resources`]).
    pub async fn run(&self, request: &Request, response: &mut Response, context: &Arc<Resources>) -> Result<()> {
        if let Some(compression) = &self.compression {
            let accept = request.header_line_or("accept-encoding", "");
            response.set_compression(&accept, compression);
        }
        context.set("request", Arc::new(()));
        context.set("response", Arc::new(()));

        let empty = PathParams::new();
        let none = Params::new();
        for hook in &self.request {
            if let Err(e) = self.call(hook, &empty, &none, None, request, response, context).await {
                context.set("error", Arc::new(e.clone()));
                for error in self.errors.iter().filter(|h| h.in_group("*")) {
                    if let Err(failure) = self.call(error, &empty, &none, None, request, response, context).await {
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

    /// `start()`: runs the start hooks with the server as the `server`
    /// resource; a failing hook runs the global error hooks.
    pub async fn start(&self, server: crate::hook::Resource) -> Result<()> {
        self.resources.set("server", server);
        let mut request = Request::new("GET", "/", std::iter::empty(), bytes::Bytes::new());
        request.set_method("");
        let mut response = Response::new();
        let empty = PathParams::new();
        let none = Params::new();
        for hook in &self.start {
            if let Err(e) = self.call(hook, &empty, &none, None, &request, &mut response, &self.resources).await {
                self.resources.set("error", Arc::new(e.clone()));
                for error in self.errors.iter().filter(|h| h.in_group("*")) {
                    if let Err(failure) =
                        self.call(error, &empty, &none, None, &request, &mut response, &self.resources).await
                    {
                        return Err(Error::handler(&failure, e));
                    }
                }
                break;
            }
        }
        Ok(())
    }

    /// A request context: a child of the static resources.
    pub fn context(&self) -> Arc<Resources> {
        Arc::new(Resources::child(self.resources.clone()))
    }
}
