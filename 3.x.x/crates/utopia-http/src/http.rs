use crate::adapter::Adapter;
use crate::context::ActionContext;
use crate::error::{HttpError, Result};
use crate::files::Files;
use crate::mode::Mode;
use crate::request::Request;
use crate::response::Response;
use crate::route::{ActionFn, Route};
use crate::router::{RouteMatch, Router};
use serde_json::Value;
use std::collections::HashMap;
use std::fmt;
use std::future::Future;
use std::sync::atomic::{AtomicUsize, Ordering};
use std::sync::{Arc, OnceLock};
use utopia_di::{Container, Resource};
use utopia_servers::{EnumMeta, Hook};
use utopia_telemetry::{
    Adapter as TelemetryAdapter, Attributes, Histogram, NoneAdapter, UpDownCounter,
};
use utopia_validators::{Validator, ValueType};

fn empty_params() -> &'static HashMap<String, Value> {
    static EMPTY: OnceLock<HashMap<String, Value>> = OnceLock::new();
    EMPTY.get_or_init(HashMap::new)
}

type HookAction = ActionFn;

struct HookEntry {
    meta: Hook,
    action: HookAction,
}

pub struct Http {
    adapter: Arc<dyn Adapter>,
    router: Router,
    mode: Mode,
    timezone: String,
    files: Files,
    compression: bool,
    compression_min_size: usize,
    init: Vec<HookEntry>,
    shutdown: Vec<HookEntry>,
    errors: Vec<HookEntry>,
    options: Vec<HookEntry>,
    #[allow(dead_code)]
    start_hooks: Vec<HookEntry>,
    request_hooks: Vec<HookEntry>,
    route_counter: AtomicUsize,
    request_duration: Arc<dyn Histogram>,
    active_requests: Arc<dyn UpDownCounter>,
    request_body_size: Arc<dyn Histogram>,
    response_body_size: Arc<dyn Histogram>,
    metrics_enabled: bool,
}

impl fmt::Debug for Http {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.debug_struct("Http")
            .field("mode", &self.mode)
            .field("timezone", &self.timezone)
            .field("compression", &self.compression)
            .field("compression_min_size", &self.compression_min_size)
            .field("metrics_enabled", &self.metrics_enabled)
            .finish_non_exhaustive()
    }
}

impl Http {
    pub fn new(adapter: impl Adapter + 'static, timezone: impl Into<String>) -> Self {
        let telemetry = NoneAdapter::new();
        Self {
            adapter: Arc::new(adapter),
            router: Router::new(),
            mode: Mode::None,
            timezone: timezone.into(),
            files: Files::new(),
            compression: false,
            compression_min_size: 1024,
            init: Vec::new(),
            shutdown: Vec::new(),
            errors: Vec::new(),
            options: Vec::new(),
            start_hooks: Vec::new(),
            request_hooks: Vec::new(),
            route_counter: AtomicUsize::new(0),
            request_duration: telemetry.create_histogram(
                "http.server.request.duration",
                Some("s"),
                None,
                Attributes::new(),
            ),
            active_requests: telemetry.create_up_down_counter(
                "http.server.active_requests",
                Some("{request}"),
                None,
                Attributes::new(),
            ),
            request_body_size: telemetry.create_histogram(
                "http.server.request.body.size",
                Some("By"),
                None,
                Attributes::new(),
            ),
            response_body_size: telemetry.create_histogram(
                "http.server.response.body.size",
                Some("By"),
                None,
                Attributes::new(),
            ),
            metrics_enabled: telemetry.enabled(),
        }
    }

    pub fn resources(&self) -> Container {
        self.adapter.resources().clone()
    }

    pub fn set_mode(&mut self, mode: Mode) {
        self.mode = mode;
    }

    pub fn mode(&self) -> Mode {
        self.mode
    }

    pub fn is_production(&self) -> bool {
        self.mode == Mode::Production
    }

    pub fn set_compression(&mut self, enabled: bool) {
        self.compression = enabled;
    }

    pub fn set_allow_override(&self, value: bool) {
        self.router.set_allow_override(value);
    }

    pub fn load_files(&mut self, directory: impl AsRef<std::path::Path>) -> Result<()> {
        self.files.load(directory, None)?;
        Ok(())
    }

    pub fn timezone(&self) -> &str {
        &self.timezone
    }

    fn next_order(&self) -> usize {
        self.route_counter.fetch_add(1, Ordering::SeqCst) + 1
    }

    pub fn routes(&self, methods: &[&str], path: &str) -> Result<Arc<Route>> {
        if methods.is_empty() {
            return Err(HttpError::EmptyMethods);
        }
        let methods: Vec<String> = methods.iter().map(|m| m.to_ascii_uppercase()).collect();
        let route = Arc::new(Route::new(methods, path, self.next_order()));
        self.router.add_route(route.clone())?;
        Ok(route)
    }

    pub fn get(&self, path: &str) -> Result<Arc<Route>> {
        self.routes(&["GET"], path)
    }
    pub fn post(&self, path: &str) -> Result<Arc<Route>> {
        self.routes(&["POST"], path)
    }
    pub fn put(&self, path: &str) -> Result<Arc<Route>> {
        self.routes(&["PUT"], path)
    }
    pub fn patch(&self, path: &str) -> Result<Arc<Route>> {
        self.routes(&["PATCH"], path)
    }
    pub fn delete(&self, path: &str) -> Result<Arc<Route>> {
        self.routes(&["DELETE"], path)
    }

    pub fn wildcard(&self) -> Arc<Route> {
        let route = Arc::new(Route::new(vec![], "", self.next_order()));
        self.router.set_wildcard(route.clone());
        route
    }

    pub fn on_init<F, Fut>(&mut self, f: F) -> HookBuilder<'_>
    where
        F: Fn(ActionContext) -> Fut + Send + Sync + 'static,
        Fut: Future<Output = Result<()>> + Send + 'static,
    {
        let action: HookAction = Arc::new(move |ctx| Box::pin(f(ctx)));
        self.init.push(HookEntry {
            meta: {
                let mut h = Hook::new();
                h.groups(["*"]);
                h
            },
            action,
        });
        HookBuilder {
            hook: &mut self.init.last_mut().unwrap().meta,
        }
    }

    pub fn on_shutdown<F, Fut>(&mut self, f: F) -> HookBuilder<'_>
    where
        F: Fn(ActionContext) -> Fut + Send + Sync + 'static,
        Fut: Future<Output = Result<()>> + Send + 'static,
    {
        let action: HookAction = Arc::new(move |ctx| Box::pin(f(ctx)));
        self.shutdown.push(HookEntry {
            meta: {
                let mut h = Hook::new();
                h.groups(["*"]);
                h
            },
            action,
        });
        HookBuilder {
            hook: &mut self.shutdown.last_mut().unwrap().meta,
        }
    }

    pub fn on_error<F, Fut>(&mut self, f: F) -> HookBuilder<'_>
    where
        F: Fn(ActionContext) -> Fut + Send + Sync + 'static,
        Fut: Future<Output = Result<()>> + Send + 'static,
    {
        let action: HookAction = Arc::new(move |ctx| Box::pin(f(ctx)));
        self.errors.push(HookEntry {
            meta: {
                let mut h = Hook::new();
                h.groups(["*"]);
                h
            },
            action,
        });
        HookBuilder {
            hook: &mut self.errors.last_mut().unwrap().meta,
        }
    }

    pub fn on_options<F, Fut>(&mut self, f: F) -> HookBuilder<'_>
    where
        F: Fn(ActionContext) -> Fut + Send + Sync + 'static,
        Fut: Future<Output = Result<()>> + Send + 'static,
    {
        let action: HookAction = Arc::new(move |ctx| Box::pin(f(ctx)));
        self.options.push(HookEntry {
            meta: {
                let mut h = Hook::new();
                h.groups(["*"]);
                h
            },
            action,
        });
        HookBuilder {
            hook: &mut self.options.last_mut().unwrap().meta,
        }
    }

    pub fn match_request(&self, request: &Request) -> Option<RouteMatch> {
        let path = request.path();
        let path = if path.is_empty() { "/" } else { path };
        self.router.match_route(request.method(), path)
    }

    pub async fn execute(&self, request: Request, response: Response) -> Result<()> {
        let is_head = request.method() == "HEAD";
        if is_head {
            response.disable_payload();
        }
        let request = Arc::new(request);
        let match_method = if is_head { "GET" } else { request.method() };
        let match_ = self.router.match_route(match_method, request.path());

        if request.method() == "OPTIONS" {
            let groups = match_
                .as_ref()
                .map(|m| m.route.get_groups())
                .unwrap_or_default();
            if let Err(e) = self
                .run_hooks(
                    &self.options,
                    &groups,
                    true,
                    &request,
                    &response,
                    match_.as_ref().map(|m| m.route.clone()),
                    empty_params(),
                    None,
                    None,
                )
                .await
            {
                let _ = self
                    .run_error_hooks(
                        &[],
                        &request,
                        &response,
                        None,
                        empty_params(),
                        Some(e),
                        None,
                    )
                    .await;
            }
            return Ok(());
        }

        let Some(m) = match_ else {
            let err = HttpError::not_found();
            let _ = self
                .run_error_hooks(
                    &[],
                    &request,
                    &response,
                    None,
                    empty_params(),
                    Some(err),
                    None,
                )
                .await;
            return Ok(());
        };

        let route = m.route;
        let groups = route.get_groups();
        let ctx_params: HashMap<String, Value> = m
            .params
            .into_iter()
            .map(|(k, v)| (k, Value::String(v)))
            .collect();
        let include_global = route.get_hook_flag();

        // One request-scoped DI container shared across init hooks, the route
        // action, and shutdown hooks -- mirrors PHP `Utopia\App`'s single
        // per-request `Container`, so resources an `api`-group `Init` hook binds
        // (`project`, `dbForProject`, `apiKey`, ...) stay visible downstream.
        let shared_context = self.adapter.context();

        let result = async {
            self.run_hooks(
                &self.init,
                &groups,
                include_global,
                &request,
                &response,
                Some(route.clone()),
                &ctx_params,
                None,
                Some(&shared_context),
            )
            .await?;

            if !response.is_sent() {
                let action = route
                    .get_action()
                    .ok_or_else(|| HttpError::Other("Route has no action".into()))?;
                let meta = route.hook_meta();
                let ctx = self.build_context(
                    &meta,
                    &request,
                    &response,
                    Some(route.clone()),
                    &ctx_params,
                    None,
                    Some(&shared_context),
                )?;
                action(ctx).await?;
            }

            self.run_hooks(
                &self.shutdown,
                &groups,
                include_global,
                &request,
                &response,
                Some(route.clone()),
                &ctx_params,
                None,
                Some(&shared_context),
            )
            .await?;
            Ok::<(), HttpError>(())
        }
        .await;

        if let Err(e) = result {
            let _ = self
                .run_error_hooks(
                    &groups,
                    &request,
                    &response,
                    Some(route),
                    &ctx_params,
                    Some(e),
                    Some(&shared_context),
                )
                .await;
        }
        Ok(())
    }

    pub async fn run(&self, mut request: Request, response: Response) -> Result<()> {
        let start = if self.metrics_enabled {
            Some(std::time::Instant::now())
        } else {
            None
        };
        let mut attrs = Attributes::new();
        if self.metrics_enabled {
            attrs.insert("http.request.method".into(), request.method().into());
            attrs.insert("url.scheme".into(), request.protocol());
            self.active_requests.add(1.0, &attrs);
        }

        if self.mode == Mode::Development {
            response.set_debug_timing(true);
        }
        if self.compression {
            response.set_accept_encoding(request.header_line("accept-encoding"));
            response.set_compression_min_size(self.compression_min_size);
        }
        request.parse_query_from_uri();

        let req_size = request.size();

        // Fast path: no request hooks / static files → skip pre-execute DI wiring.
        if self.request_hooks.is_empty() && self.files.is_empty() {
            let _ = self.execute(request, response.clone()).await;
        } else {
            let context = self.adapter.context();
            let req_arc = Arc::new(request.clone());
            context.set_cached("request", Resource::new(req_arc.clone()));
            context.set_cached("response", Resource::new(response.clone()));

            if let Err(e) = self
                .run_hooks(
                    &self.request_hooks,
                    &[],
                    true,
                    &req_arc,
                    &response,
                    None,
                    empty_params(),
                    None,
                    Some(&context),
                )
                .await
            {
                let _ = self
                    .run_error_hooks(
                        &[],
                        &req_arc,
                        &response,
                        None,
                        empty_params(),
                        Some(e),
                        Some(&context),
                    )
                    .await;
            } else if let Some((bytes, mime)) = self.files.get(request.uri()).cloned() {
                response.set_content_type(mime);
                response.add_header("cache-control", "public, max-age=63072000");
                let _ = response.send(bytes);
            } else {
                let _ = self.execute(request, response.clone()).await;
            }
        }

        if self.metrics_enabled {
            if let Some(start) = start {
                let mut end_attrs = attrs.clone();
                end_attrs.insert(
                    "http.response.status_code".into(),
                    response.status_code().to_string(),
                );
                self.request_duration
                    .record(start.elapsed().as_secs_f64(), &end_attrs);
                self.request_body_size.record(req_size as f64, &end_attrs);
                self.response_body_size
                    .record(response.size() as f64, &end_attrs);
                self.active_requests.add(-1.0, &attrs);
            }
        }
        Ok(())
    }

    #[allow(clippy::too_many_arguments)]
    async fn run_hooks(
        &self,
        hooks: &[HookEntry],
        groups: &[String],
        include_global: bool,
        request: &Arc<Request>,
        response: &Response,
        route: Option<Arc<Route>>,
        path_params: &HashMap<String, Value>,
        error: Option<HttpError>,
        shared: Option<&Container>,
    ) -> Result<()> {
        if hooks.is_empty() {
            return Ok(());
        }
        let error = error.map(Arc::new);
        for hook in hooks {
            let hook_groups = hook.meta.get_groups();
            let run = if include_global && hook_groups.iter().any(|g| g == "*") {
                true
            } else {
                hook_groups.iter().any(|g| groups.contains(g))
            };
            if !run {
                continue;
            }
            let ctx = self.build_context(
                &hook.meta,
                request,
                response,
                route.clone(),
                path_params,
                error.clone(),
                shared,
            )?;
            (hook.action)(ctx).await?;
            if response.is_sent() {
                break;
            }
        }
        Ok(())
    }

    #[allow(clippy::too_many_arguments)]
    async fn run_error_hooks(
        &self,
        groups: &[String],
        request: &Arc<Request>,
        response: &Response,
        route: Option<Arc<Route>>,
        path_params: &HashMap<String, Value>,
        error: Option<HttpError>,
        shared: Option<&Container>,
    ) -> Result<()> {
        self.run_hooks(
            &self.errors,
            groups,
            true,
            request,
            response,
            route,
            path_params,
            error,
            shared,
        )
        .await
    }

    #[allow(clippy::too_many_arguments)]
    fn build_context(
        &self,
        hook: &Hook,
        request: &Arc<Request>,
        response: &Response,
        route: Option<Arc<Route>>,
        path_params: &HashMap<String, Value>,
        error: Option<Arc<HttpError>>,
        shared: Option<&Container>,
    ) -> Result<ActionContext> {
        // Prefer shared app container when the action/hook does not need DI bindings.
        // Otherwise reuse the request-scoped container passed in from `execute()`/`run()`
        // (falling back to a fresh child) so concurrent requests never mutate the parent,
        // while resources bound by one hook stay visible to the next hook/action in the
        // same request -- mirrors PHP `Utopia\App`'s single per-request `Container`.
        let context = if error.is_some() || hook.has_injections() {
            let context = shared.cloned().unwrap_or_else(|| self.adapter.context());
            if let Some(err) = &error {
                context.set_cached("error", Resource::new(err.clone()));
            }
            context.set_cached("request", Resource::new(request.clone()));
            context.set_cached("response", Resource::new(response.clone()));
            context
        } else {
            self.adapter.resources().clone()
        };

        let params = hook.get_params();
        let mut resolved = HashMap::with_capacity(params.len());

        for (key, param) in params {
            let request_key = if request.param_ref(key).is_some() {
                key.as_str()
            } else {
                param
                    .aliases
                    .iter()
                    .find(|alias| request.param_ref(alias).is_some())
                    .map_or(key.as_str(), String::as_str)
            };
            let values_key = if path_params.contains_key(key) {
                key.as_str()
            } else {
                param
                    .aliases
                    .iter()
                    .find(|alias| path_params.contains_key(alias.as_str()))
                    .map_or(key.as_str(), String::as_str)
            };
            let from_path = path_params.get(values_key);
            let from_request = request.param_ref(request_key);
            let param_exists = from_path.is_some() || from_request.is_some();
            let mut value = if let Some(v) = from_path {
                v.clone()
            } else if let Some(v) = from_request {
                v.clone()
            } else {
                param.default.clone()
            };

            if !param.skip_validation {
                if !param_exists && !param.optional {
                    return Err(HttpError::MissingParam(key.clone()));
                }
                let skip_null_optional = param.optional && value.is_null();
                if param_exists && !skip_null_optional && !param.validator.is_valid(&value) {
                    return Err(HttpError::InvalidParam {
                        key: key.clone(),
                        description: param.validator.description(),
                    });
                }
            }
            // coerce numbers from strings for convenience
            if let Value::String(s) = &value {
                if param.validator.value_type() == ValueType::Integer {
                    if let Ok(n) = s.parse::<i64>() {
                        value = Value::from(n);
                    }
                }
            }
            resolved.insert(key.clone(), value);
        }

        Ok(ActionContext {
            request: request.clone(),
            response: response.clone(),
            route,
            params: resolved,
            container: context,
            error,
        })
    }

    pub async fn start(self) -> Result<()> {
        let http = Arc::new(self);
        let http2 = http.clone();
        http.adapter
            .on_request(Box::new(move |req, res| {
                let http = http2.clone();
                Box::pin(async move {
                    let _ = http.run(req, res).await;
                })
            }))
            .await;
        http.adapter.start().await
    }

    pub fn router(&self) -> &Router {
        &self.router
    }
}

pub struct HookBuilder<'a> {
    hook: &'a mut Hook,
}

impl fmt::Debug for HookBuilder<'_> {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.debug_struct("HookBuilder")
            .field("hook", &self.hook)
            .finish()
    }
}

impl HookBuilder<'_> {
    pub fn groups<I, S>(self, groups: I) -> Self
    where
        I: IntoIterator<Item = S>,
        S: Into<String>,
    {
        self.hook.groups(groups);
        self
    }

    pub fn inject(self, name: impl Into<String>) -> Result<Self> {
        self.hook
            .inject(name)
            .map_err(|e| HttpError::Other(e.to_string()))?;
        Ok(self)
    }

    pub fn param(
        self,
        key: impl Into<String>,
        default: Value,
        validator: impl Validator + 'static,
        description: impl Into<String>,
        optional: bool,
    ) -> Self {
        self.param_full(
            key,
            default,
            validator,
            description,
            optional,
            Vec::new(),
            false,
            false,
            "",
            Vec::new(),
            None,
        )
    }

    #[allow(clippy::too_many_arguments)]
    pub fn param_full(
        self,
        key: impl Into<String>,
        default: Value,
        validator: impl Validator + 'static,
        description: impl Into<String>,
        optional: bool,
        injections: Vec<String>,
        skip_validation: bool,
        deprecated: bool,
        example: impl Into<String>,
        aliases: Vec<String>,
        enum_meta: Option<EnumMeta>,
    ) -> Self {
        self.hook.param_full(
            key,
            default,
            validator,
            description,
            optional,
            injections,
            skip_validation,
            deprecated,
            example,
            aliases,
            enum_meta,
        );
        self
    }
}
