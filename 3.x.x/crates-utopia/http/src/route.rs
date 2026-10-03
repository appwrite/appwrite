use crate::error::{HttpError, Result};
use crate::router::Router;
use parking_lot::Mutex;
use serde_json::Value;
use std::collections::HashMap;
use std::fmt;
use std::future::Future;
use std::pin::Pin;
use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::Arc;
use utopia_servers::{EnumMeta, Hook};
use utopia_validators::Validator;

use crate::context::ActionContext;

pub type BoxFuture<'a, T> = Pin<Box<dyn Future<Output = T> + Send + 'a>>;
pub type ActionFn = Arc<dyn Fn(ActionContext) -> BoxFuture<'static, Result<()>> + Send + Sync>;

pub struct Route {
    methods: Vec<String>,
    path: String,
    alias_paths: Mutex<Vec<String>>,
    path_params: Mutex<HashMap<String, HashMap<String, usize>>>,
    /// Shared hook metadata (params/validators/injections). Cheap to clone via Arc.
    hook_meta: Mutex<Arc<Hook>>,
    action: Mutex<Option<ActionFn>>,
    use_hooks: AtomicBool,
    order: usize,
}

impl fmt::Debug for Route {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.debug_struct("Route")
            .field("methods", &self.methods)
            .field("path", &self.path)
            .field("order", &self.order)
            .field("use_hooks", &self.use_hooks.load(Ordering::Relaxed))
            .finish_non_exhaustive()
    }
}

impl Route {
    pub fn new(methods: Vec<String>, path: impl Into<String>, order: usize) -> Self {
        Self {
            methods,
            path: path.into(),
            alias_paths: Mutex::new(Vec::new()),
            path_params: Mutex::new(HashMap::new()),
            hook_meta: Mutex::new(Arc::new(Hook::new())),
            action: Mutex::new(None),
            use_hooks: AtomicBool::new(true),
            order,
        }
    }

    pub fn methods(&self) -> &[String] {
        &self.methods
    }

    pub fn path(&self) -> &str {
        &self.path
    }

    pub fn order(&self) -> usize {
        self.order
    }

    pub fn get_hook_flag(&self) -> bool {
        self.use_hooks.load(Ordering::Relaxed)
    }

    pub fn hook(self: &Arc<Self>, enabled: bool) -> Arc<Self> {
        self.use_hooks.store(enabled, Ordering::Relaxed);
        self.clone()
    }

    fn hook_meta_mut(&self) -> impl std::ops::DerefMut<Target = Hook> + '_ {
        parking_lot::MutexGuard::map(self.hook_meta.lock(), |arc| Arc::make_mut(arc))
    }

    pub fn desc(self: &Arc<Self>, desc: impl Into<String>) -> Arc<Self> {
        self.hook_meta_mut().desc(desc);
        self.clone()
    }

    pub fn groups<I, S>(self: &Arc<Self>, groups: I) -> Arc<Self>
    where
        I: IntoIterator<Item = S>,
        S: Into<String>,
    {
        self.hook_meta_mut().groups(groups);
        self.clone()
    }

    pub fn get_groups(&self) -> Vec<String> {
        self.hook_meta.lock().get_groups().to_vec()
    }

    pub fn label(self: &Arc<Self>, key: impl Into<String>, value: impl Into<Value>) -> Arc<Self> {
        self.hook_meta_mut().label(key, value);
        self.clone()
    }

    pub fn inject(self: &Arc<Self>, name: impl Into<String>) -> Result<Arc<Self>> {
        self.hook_meta_mut()
            .inject(name)
            .map_err(|e| HttpError::DuplicateInjection(e.to_string()))?;
        Ok(self.clone())
    }

    pub fn param(
        self: &Arc<Self>,
        key: impl Into<String>,
        default: Value,
        validator: impl Validator + 'static,
        description: impl Into<String>,
        optional: bool,
    ) -> Arc<Self> {
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
        self: &Arc<Self>,
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
    ) -> Arc<Self> {
        self.hook_meta_mut().param_full(
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
        self.clone()
    }

    pub fn action<F, Fut>(self: &Arc<Self>, f: F) -> Arc<Self>
    where
        F: Fn(ActionContext) -> Fut + Send + Sync + 'static,
        Fut: Future<Output = Result<()>> + Send + 'static,
    {
        let boxed: ActionFn = Arc::new(move |ctx| Box::pin(f(ctx)));
        *self.action.lock() = Some(boxed);
        self.hook_meta_mut().action_marker();
        self.clone()
    }

    pub fn get_action(&self) -> Option<ActionFn> {
        self.action.lock().clone()
    }

    /// Shared hook metadata (Arc clone - no deep copy of params/validators).
    pub fn hook_meta(&self) -> Arc<Hook> {
        self.hook_meta.lock().clone()
    }

    pub fn alias(self: &Arc<Self>, router: &Router, path: &str) -> Result<Arc<Self>> {
        router.add_route_alias(path, self.clone())?;
        Ok(self.clone())
    }

    pub fn add_alias_path(&self, path: impl Into<String>) {
        let path = path.into();
        let mut aliases = self.alias_paths.lock();
        if !aliases.iter().any(|p| p == &path) {
            aliases.push(path);
        }
    }

    pub fn set_path_param(&self, key: &str, index: usize, path: &str) {
        self.path_params
            .lock()
            .entry(path.to_string())
            .or_default()
            .insert(key.to_string(), index);
    }

    pub fn resolve_params(&self, url: &str, matched_template: &str) -> HashMap<String, String> {
        let parts: Vec<&str> = url.split('/').filter(|s| !s.is_empty()).collect();
        self.resolve_params_from_parts(&parts, matched_template)
    }

    pub fn resolve_params_from_parts(
        &self,
        parts: &[&str],
        matched_template: &str,
    ) -> HashMap<String, String> {
        let guard = self.path_params.lock();
        let path_params = if matched_template.is_empty() {
            guard.values().next()
        } else {
            guard.get(matched_template)
        };
        let mut out = HashMap::new();
        if let Some(map) = path_params {
            for (key, index) in map {
                if let Some(v) = parts.get(*index) {
                    out.insert(key.clone(), (*v).to_string());
                }
            }
        }
        out
    }
}
