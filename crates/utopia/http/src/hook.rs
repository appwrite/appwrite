//! Hooks and routes: callbacks with declared params and injections
//! (`Utopia\Servers\Hook`, `Utopia\Http\Route`), and the resources they are
//! injected from (`Utopia\DI\Container`).

use std::any::Any;
use std::collections::HashMap;
use std::future::Future;
use std::pin::Pin;
use std::sync::{Arc, Mutex};

use indexmap::IndexMap;
use serde_json::Value;
use utopia_validators::Validator;

use crate::error::{Error, Result};
use crate::http::Http;
use crate::request::Request;
use crate::response::Response;
use crate::router::PathParams;

/// A boxed future.
pub type BoxFuture<'a, T> = Pin<Box<dyn Future<Output = T> + Send + 'a>>;

/// An injectable resource.
pub type Resource = Arc<dyn Any + Send + Sync>;

/// A hook or route callback.
pub type Action = Arc<dyn for<'a> Fn(Scope<'a>) -> BoxFuture<'a, Result<()>> + Send + Sync>;

/// Wraps an async callback into an [`Action`].
pub fn action<F>(f: F) -> Action
where
    F: for<'a> Fn(Scope<'a>) -> BoxFuture<'a, Result<()>> + Send + Sync + 'static,
{
    Arc::new(f)
}

/// Resources by name, falling back to a parent container
/// (`Utopia\DI\Container`). The server's static resources are the parent of
/// every request's context.
#[derive(Default)]
pub struct Resources {
    values: Mutex<HashMap<String, Resource>>,
    parent: Option<Arc<Resources>>,
}

impl std::fmt::Debug for Resources {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        let names: Vec<String> = self.values.lock().map(|v| v.keys().cloned().collect()).unwrap_or_default();
        f.debug_struct("Resources").field("names", &names).field("parent", &self.parent).finish()
    }
}

impl Resources {
    pub fn new() -> Self {
        Self::default()
    }

    /// A child container: lookups fall through to `parent`.
    pub fn child(parent: Arc<Resources>) -> Self {
        Self { values: Mutex::new(HashMap::new()), parent: Some(parent) }
    }

    /// `set($name, fn () => $value)`.
    pub fn set(&self, name: &str, value: Resource) {
        if let Ok(mut v) = self.values.lock() {
            v.insert(name.to_owned(), value);
        }
    }

    /// `get($name)`.
    pub fn get(&self, name: &str) -> Result<Resource> {
        if let Some(v) = self.values.lock().ok().and_then(|v| v.get(name).cloned()) {
            return Ok(v);
        }
        match &self.parent {
            Some(parent) => parent.get(name),
            None => Err(Error::NotFound(name.to_owned())),
        }
    }

    /// `has($name)`.
    pub fn has(&self, name: &str) -> bool {
        self.get(name).is_ok()
    }

    fn resolve(&self, names: &[String]) -> Result<Vec<Resource>> {
        names.iter().map(|n| self.get(n)).collect()
    }
}

/// Builds a param's default from its injections.
pub type DefaultFactory = Arc<dyn Fn(&[Resource]) -> Value + Send + Sync>;

/// Builds a param's validator from its injections.
pub type ValidatorFactory = Arc<dyn Fn(&[Resource]) -> Arc<dyn Validator> + Send + Sync>;

/// A hook's resolved arguments: params by key, then injections by name.
pub(crate) type Arguments = (Vec<(String, Value)>, Vec<(String, Option<Resource>)>);

/// A param's default: a value, or a factory called with its injections.
#[derive(Clone)]
pub enum Default {
    Value(Value),
    Factory(DefaultFactory),
}

impl Default {
    fn is_null(&self) -> bool {
        matches!(self, Default::Value(Value::Null))
    }
}

impl From<Value> for Default {
    fn from(v: Value) -> Self {
        Default::Value(v)
    }
}

/// A param's validator: an instance, or a factory called with its injections.
#[derive(Clone)]
pub enum Check {
    Validator(Arc<dyn Validator>),
    Factory(ValidatorFactory),
}

impl<V: Validator + 'static> From<V> for Check {
    fn from(v: V) -> Self {
        Check::Validator(Arc::new(v))
    }
}

/// A declared param (`Hook::param(...)`).
#[derive(Clone)]
pub struct Param {
    pub default: Default,
    pub validator: Check,
    pub description: String,
    pub optional: bool,
    /// Resources passed to a default or validator factory.
    pub injections: Vec<String>,
    pub skip_validation: bool,
    pub deprecated: bool,
    pub example: String,
    pub model: Option<String>,
    /// Other names the value may be sent under.
    pub aliases: Vec<String>,
}

impl Param {
    /// A required param.
    pub fn new(default: impl Into<Default>, validator: impl Into<Check>, description: &str) -> Self {
        Self {
            default: default.into(),
            validator: validator.into(),
            description: description.to_owned(),
            optional: false,
            injections: Vec::new(),
            skip_validation: false,
            deprecated: false,
            example: String::new(),
            model: None,
            aliases: Vec::new(),
        }
    }

    pub fn optional(mut self, optional: bool) -> Self {
        self.optional = optional;
        self
    }

    pub fn injections(mut self, injections: &[&str]) -> Self {
        self.injections = injections.iter().map(|s| (*s).to_owned()).collect();
        self
    }

    pub fn skip_validation(mut self, skip: bool) -> Self {
        self.skip_validation = skip;
        self
    }

    pub fn aliases(mut self, aliases: &[&str]) -> Self {
        self.aliases = aliases.iter().map(|s| (*s).to_owned()).collect();
        self
    }
}

/// A callback with its declared params and injections (`Utopia\Servers\Hook`).
#[derive(Clone, Default)]
pub struct Hook {
    desc: String,
    groups: Vec<String>,
    labels: IndexMap<String, Value>,
    params: IndexMap<String, Param>,
    injections: Vec<String>,
    action: Option<Action>,
}

impl Hook {
    pub fn new() -> Self {
        Self::default()
    }

    pub(crate) fn with_groups(groups: &[&str]) -> Self {
        Self { groups: groups.iter().map(|g| (*g).to_owned()).collect(), ..Self::default() }
    }

    /// `desc($desc)`.
    pub fn desc(&mut self, desc: &str) -> &mut Self {
        self.desc = desc.to_owned();
        self
    }

    /// `getDesc()`.
    pub fn description(&self) -> &str {
        &self.desc
    }

    /// `groups($groups)`.
    pub fn groups(&mut self, groups: &[&str]) -> &mut Self {
        self.groups = groups.iter().map(|g| (*g).to_owned()).collect();
        self
    }

    /// `getGroups()`.
    pub fn get_groups(&self) -> &[String] {
        &self.groups
    }

    /// `label($key, $value)`.
    pub fn label(&mut self, key: &str, value: Value) -> &mut Self {
        self.labels.insert(key.to_owned(), value);
        self
    }

    /// `getLabel($key, $default)`.
    pub fn get_label(&self, key: &str) -> Option<&Value> {
        self.labels.get(key).filter(|v| !v.is_null())
    }

    /// `param($key, ...)`.
    pub fn param(&mut self, key: &str, param: Param) -> &mut Self {
        self.params.insert(key.to_owned(), param);
        self
    }

    /// `getParams()`.
    pub fn params(&self) -> &IndexMap<String, Param> {
        &self.params
    }

    /// `inject($name)`; a name already injected is kept once.
    pub fn inject(&mut self, name: &str) -> &mut Self {
        if !self.injections.iter().any(|i| i == name) {
            self.injections.push(name.to_owned());
        }
        self
    }

    /// `getInjections()`.
    pub fn injections(&self) -> &[String] {
        &self.injections
    }

    /// `action($callback)`.
    pub fn action<F>(&mut self, f: F) -> &mut Self
    where
        F: for<'a> Fn(Scope<'a>) -> BoxFuture<'a, Result<()>> + Send + Sync + 'static,
    {
        self.action = Some(Arc::new(f));
        self
    }

    /// Sets an already wrapped [`Action`].
    pub fn set_action(&mut self, action: Action) -> &mut Self {
        self.action = Some(action);
        self
    }

    /// `getAction()`.
    pub fn get_action(&self) -> Option<&Action> {
        self.action.as_ref()
    }

    pub(crate) fn in_group(&self, group: &str) -> bool {
        self.groups.iter().any(|g| g == group)
    }
}

/// A route: a hook bound to methods and a path (`Utopia\Http\Route`).
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
    fn deref(&self) -> &Hook {
        &self.hook
    }
}

impl std::ops::DerefMut for Route {
    fn deref_mut(&mut self) -> &mut Hook {
        &mut self.hook
    }
}

impl Route {
    /// `new Route($methods, $path)`: methods deduplicated in order.
    pub fn new(methods: &[&str], path: &str, order: usize) -> Self {
        let mut unique: Vec<String> = Vec::with_capacity(methods.len());
        for m in methods {
            if !unique.iter().any(|u| u == m) {
                unique.push((*m).to_owned());
            }
        }
        Self { hook: Hook::new(), methods: unique, path: path.to_owned(), hooks: true, aliases: Vec::new(), order }
    }

    /// `getOrder()`.
    pub fn order(&self) -> usize {
        self.order
    }

    /// `path($path)`: changes the reported path (not the registration).
    pub fn set_path(&mut self, path: &str) -> &mut Self {
        self.path = path.to_owned();
        self
    }

    /// `getPath()`.
    pub fn path(&self) -> &str {
        &self.path
    }

    /// `getMethods()`.
    pub fn methods(&self) -> &[String] {
        &self.methods
    }

    /// `getMethod()` (deprecated): the primary method.
    pub fn method(&self) -> &str {
        self.methods.first().map(String::as_str).unwrap_or("")
    }

    /// `hook($hook)`: whether global init and shutdown hooks run.
    pub fn set_hooks(&mut self, hooks: bool) -> &mut Self {
        self.hooks = hooks;
        self
    }

    /// `getHook()`.
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

/// What a callback receives: its params and injections, the request being
/// dispatched and its response.
pub struct Scope<'a> {
    pub http: &'a Http,
    pub request: &'a Request,
    pub response: &'a mut Response,
    /// The matched route (`route` injection); `None` outside a route.
    pub route: Option<&'a Route>,
    /// Path params of the match (`params` injection).
    pub path: &'a PathParams,
    /// The request context (`$http->context()`).
    pub context: &'a Arc<Resources>,
    pub(crate) args: Vec<(String, Value)>,
    pub(crate) injected: Vec<(String, Option<Resource>)>,
}

impl Scope<'_> {
    /// A declared param's value.
    pub fn param(&self, key: &str) -> Option<&Value> {
        self.args.iter().find(|(k, _)| k == key).map(|(_, v)| v)
    }

    /// Every declared param, in declaration order.
    pub fn params(&self) -> &[(String, Value)] {
        &self.args
    }

    /// A declared injection that is a resource of type `T`.
    pub fn resource<T: Any + Send + Sync>(&self, name: &str) -> Option<&T> {
        self.injected.iter().find(|(n, _)| n == name).and_then(|(_, r)| r.as_ref()).and_then(|r| r.downcast_ref::<T>())
    }

    /// The error being handled (`error` injection in error hooks).
    pub fn error(&self) -> Option<Arc<Error>> {
        self.context.get("error").ok().and_then(|e| e.downcast::<Error>().ok())
    }

    /// The names of the declared injections.
    pub fn injections(&self) -> impl Iterator<Item = &str> {
        self.injected.iter().map(|(n, _)| n.as_str())
    }
}

impl Http {
    /// Resolves a hook's arguments (`Http::getArguments`): params from the
    /// path (`values`) or the request, with aliases, defaults and
    /// validation, then injections.
    pub(crate) fn arguments(
        &self,
        hook: &Hook,
        values: &PathParams,
        request: &crate::params::Params,
        context: &Resources,
    ) -> Result<Arguments> {
        let mut args = Vec::with_capacity(hook.params.len());
        for (key, param) in &hook.params {
            let mut request_key = key.as_str();
            if !request.contains_key(key)
                && let Some(alias) = param.aliases.iter().find(|a| request.contains_key(a.as_str()))
            {
                request_key = alias;
            }
            let in_values = |k: &str| values.iter().find(|(n, _)| n == k).map(|(_, v)| v);
            let mut values_key = key.as_str();
            if in_values(key).is_none()
                && let Some(alias) = param.aliases.iter().find(|a| in_values(a).is_some())
            {
                values_key = alias;
            }
            let mut exists_in_request = request.contains_key(request_key);
            let path_value = in_values(values_key);

            // An explicit null counts as omitted unless the validator accepts null.
            if exists_in_request
                && path_value.is_none()
                && request.get(request_key).is_some_and(Value::is_null)
                && param.optional
                && !param.default.is_null()
                && !param.skip_validation
                && !self.validator(param, context)?.is_valid(&Value::Null)
            {
                exists_in_request = false;
            }

            let exists = exists_in_request || path_value.is_some();
            let arg = if exists_in_request {
                request.get(request_key).cloned().unwrap_or(Value::Null)
            } else {
                match &param.default {
                    Default::Value(v) => v.clone(),
                    Default::Factory(f) => f(&context.resolve(&param.injections)?),
                }
            };
            let value = match path_value {
                Some(v) => Value::String(v.clone()),
                None => arg,
            };

            if !param.skip_validation {
                if !exists && !param.optional {
                    return Err(Error::http(format!("Param \"{key}\" is not optional."), 400));
                }
                if exists && !(param.optional && value.is_null()) {
                    let validator = self.validator(param, context)?;
                    if !validator.is_valid(&value) {
                        return Err(Error::http(format!("Invalid `{key}` param: {}", validator.description()), 400));
                    }
                }
            }
            args.push((key.clone(), value));
        }

        let mut injected = Vec::with_capacity(hook.injections.len());
        for name in &hook.injections {
            let resource = match name.as_str() {
                "route" | "params" => None,
                _ => Some(context.get(name)?),
            };
            injected.push((name.clone(), resource));
        }
        Ok((args, injected))
    }

    fn validator(&self, param: &Param, context: &Resources) -> Result<Arc<dyn Validator>> {
        Ok(match &param.validator {
            Check::Validator(v) => v.clone(),
            Check::Factory(f) => f(&context.resolve(&param.injections)?),
        })
    }
}
