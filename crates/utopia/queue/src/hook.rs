//! Hooks and jobs: callbacks with params read from the payload and
//! resources injected by name (`Utopia\Servers\Hook`, `Utopia\Queue\Job`,
//! `Utopia\DI\Container`).

use std::any::Any;
use std::collections::HashMap;
use std::sync::{Arc, Mutex};

use indexmap::IndexMap;
use php_std::{Array, Value};
use utopia_validators::Validator;

use crate::connection::BoxFuture;
use crate::error::{Error, Result};
use crate::message::Message;

/// An injectable resource.
pub type Resource = Arc<dyn Any + Send + Sync>;

/// A hook's callback.
pub type Action = Arc<dyn Fn(Scope) -> BoxFuture<'static, Result<()>> + Send + Sync>;

/// Builds a param's validator from its injected resources.
pub type ValidatorFactory = Arc<dyn Fn(&[Resource]) -> Arc<dyn Validator> + Send + Sync>;

/// Resources by name, falling back to a parent: the server's resources are
/// the parent of every message's context.
#[derive(Default)]
pub struct Resources {
    values: Mutex<HashMap<String, Resource>>,
    parent: Option<Arc<Resources>>,
}

impl Resources {
    /// An empty container.
    pub fn new() -> Self {
        Self::default()
    }

    /// A child container: lookups fall through to `parent`.
    pub fn child(parent: Arc<Resources>) -> Self {
        Self { values: Mutex::new(HashMap::new()), parent: Some(parent) }
    }

    /// Registers a resource.
    pub fn set(&self, name: &str, value: Resource) {
        if let Ok(mut v) = self.values.lock() {
            v.insert(name.to_owned(), value);
        }
    }

    /// A resource, here or in a parent.
    pub fn get(&self, name: &str) -> Result<Resource> {
        if let Some(v) = self.values.lock().ok().and_then(|v| v.get(name).cloned()) {
            return Ok(v);
        }
        match &self.parent {
            Some(parent) => parent.get(name),
            None => Err(Error::Other {
                class: "Utopia\\DI\\Exceptions\\NotFoundException".into(),
                message: format!("Dependency {name} not found"),
            }),
        }
    }

    /// Whether a resource is registered here or in a parent.
    pub fn has(&self, name: &str) -> bool {
        self.get(name).is_ok()
    }
}

/// A param's validator: an instance, or a factory given injected resources.
#[derive(Clone)]
pub enum Check {
    /// A validator instance.
    Validator(Arc<dyn Validator>),
    /// Built per call from the named resources.
    Factory(Vec<String>, ValidatorFactory),
}

/// A param read from the message payload.
#[derive(Clone)]
pub struct Param {
    /// Used when the payload has no value, or `null` or `""`.
    pub default: Value,
    /// Validates the value.
    pub validator: Check,
    /// What the param is.
    pub description: String,
    /// Whether a missing value is allowed.
    pub optional: bool,
    /// Other payload keys the value may arrive under.
    pub aliases: Vec<String>,
}

/// The arguments a hook's action receives: params by key and injected
/// resources by name, in declaration order.
#[derive(Clone, Default)]
pub struct Scope {
    params: IndexMap<String, Value>,
    resources: IndexMap<String, Resource>,
    context: Option<Arc<Resources>>,
}

impl Scope {
    /// A param's value (`null` when the hook declares no such param).
    pub fn param(&self, key: &str) -> &Value {
        self.params.get(key).unwrap_or(&Value::Null)
    }

    /// Every param, in declaration order.
    pub fn params(&self) -> &IndexMap<String, Value> {
        &self.params
    }

    /// An injected resource of type `T`.
    pub fn get<T: Any + Send + Sync>(&self, name: &str) -> Option<Arc<T>> {
        self.resources.get(name).cloned().and_then(|r| r.downcast::<T>().ok())
    }

    /// The injected `message`.
    pub fn message(&self) -> Option<Arc<Message>> {
        self.get("message")
    }

    /// The injected `error`.
    pub fn error(&self) -> Option<Arc<Error>> {
        self.get("error")
    }

    /// The container the hook runs in (the message's context for message
    /// hooks), to register resources later hooks of the same message inject.
    pub fn context(&self) -> Option<&Arc<Resources>> {
        self.context.as_ref()
    }
}

/// A callback with its groups, params and injections.
///
/// ```
/// use utopia_queue::Hook;
///
/// let mut hook = Hook::new();
/// hook.groups(&["emails"]).inject("message").action(|scope| async move {
///     let _message = scope.message();
///     Ok(())
/// });
/// assert_eq!(hook.group_names(), ["emails"]);
/// ```
///
/// ```php
/// use Utopia\Servers\Hook;
///
/// $hook = new Hook()->groups(['emails'])->inject('message')->action(fn ($message) => null);
/// echo implode(',', $hook->getGroups()), "\n"; // emails
/// ```
#[derive(Clone, Default)]
pub struct Hook {
    desc: String,
    groups: Vec<String>,
    params: IndexMap<String, Param>,
    injections: Vec<String>,
    order: Vec<(bool, String)>,
    action: Option<Action>,
}

impl Hook {
    /// A hook in no group, doing nothing.
    pub fn new() -> Self {
        Self::default()
    }

    /// Describes the hook.
    pub fn desc(&mut self, desc: &str) -> &mut Self {
        self.desc = desc.to_owned();
        self
    }

    /// The description.
    pub fn description(&self) -> &str {
        &self.desc
    }

    /// Sets the groups (`*` matches every job).
    pub fn groups(&mut self, groups: &[&str]) -> &mut Self {
        self.groups = groups.iter().map(|g| (*g).to_owned()).collect();
        self
    }

    /// The groups.
    pub fn group_names(&self) -> Vec<&str> {
        self.groups.iter().map(String::as_str).collect()
    }

    /// Declares a param read from the payload.
    pub fn param(
        &mut self,
        key: &str,
        default: Value,
        validator: impl Validator + 'static,
        description: &str,
        optional: bool,
    ) -> &mut Self {
        self.param_with(
            key,
            Param {
                default,
                validator: Check::Validator(Arc::new(validator)),
                description: description.to_owned(),
                optional,
                aliases: Vec::new(),
            },
        )
    }

    /// Declares a param with every option.
    pub fn param_with(&mut self, key: &str, param: Param) -> &mut Self {
        if self.params.insert(key.to_owned(), param).is_none() {
            self.order.push((true, key.to_owned()));
        }
        self
    }

    /// Declares an injected resource.
    pub fn inject(&mut self, name: &str) -> &mut Self {
        self.injections.push(name.to_owned());
        self.order.push((false, name.to_owned()));
        self
    }

    /// Sets the callback.
    pub fn action<F, Fut>(&mut self, f: F) -> &mut Self
    where
        F: Fn(Scope) -> Fut + Send + Sync + 'static,
        Fut: std::future::Future<Output = Result<()>> + Send + 'static,
    {
        self.action = Some(Arc::new(move |scope| Box::pin(f(scope))));
        self
    }

    /// The declared params.
    pub fn params(&self) -> &IndexMap<String, Param> {
        &self.params
    }

    /// The declared injections.
    pub fn injections(&self) -> &[String] {
        &self.injections
    }

    /// Resolves the arguments and runs the callback.
    pub(crate) async fn run(&self, context: &Arc<Resources>, payload: &Array) -> Result<()> {
        let mut scope = self.arguments(context, payload)?;
        scope.context = Some(context.clone());
        match &self.action {
            Some(action) => action(scope).await,
            None => Ok(()),
        }
    }

    /// `getArguments()`: each param from the payload (or an alias, or its
    /// default for a missing, `null` or `""` value), validated; then each
    /// injection from the context.
    fn arguments(&self, context: &Resources, payload: &Array) -> Result<Scope> {
        let mut scope = Scope::default();
        for (is_param, name) in &self.order {
            if *is_param {
                let param = &self.params[name];
                let mut source = name.as_str();
                if payload.get_str(name).is_none()
                    && let Some(alias) = param.aliases.iter().find(|a| payload.get_str(a).is_some())
                {
                    source = alias;
                }
                let mut value = match payload.get_str(source) {
                    Some(Value::Null) | None => param.default.clone(),
                    Some(v) => v.clone(),
                };
                if is_blank(&value) {
                    value = param.default.clone();
                }
                validate(name, param, &value, context)?;
                scope.params.insert(name.clone(), value);
            } else {
                scope.resources.insert(name.clone(), context.get(name)?);
            }
        }
        Ok(scope)
    }
}

fn is_blank(value: &Value) -> bool {
    match value {
        Value::Null => true,
        Value::Str(s) => s.is_empty(),
        _ => false,
    }
}

fn validate(key: &str, param: &Param, value: &Value, context: &Resources) -> Result<()> {
    if is_blank(value) {
        if !param.optional {
            return Err(Error::Server { message: format!("Param {key} is not optional."), code: 400 });
        }
        return Ok(());
    }
    let validator = match &param.validator {
        Check::Validator(v) => v.clone(),
        Check::Factory(names, factory) => {
            let resources = names.iter().map(|n| context.get(n)).collect::<Result<Vec<_>>>()?;
            factory(&resources)
        }
    };
    if !validator.is_valid(&value.to_json()) {
        return Err(Error::Server { message: format!("Invalid {key}: {}", validator.description()), code: 400 });
    }
    Ok(())
}

/// The handler of one queue: a [`Hook`] whose init and shutdown hooks can
/// be switched off.
#[derive(Clone)]
pub struct Job {
    hook: Hook,
    hooks: bool,
}

impl Default for Job {
    fn default() -> Self {
        Self { hook: Hook::new(), hooks: true }
    }
}

impl Job {
    /// A job that runs the `*` hooks.
    pub fn new() -> Self {
        Self::default()
    }

    /// Whether the `*` init and shutdown hooks run around this job.
    pub fn hook(&mut self, hooks: bool) -> &mut Self {
        self.hooks = hooks;
        self
    }

    /// `getHook()`.
    pub fn runs_hooks(&self) -> bool {
        self.hooks
    }
}

impl std::ops::Deref for Job {
    type Target = Hook;

    fn deref(&self) -> &Hook {
        &self.hook
    }
}

impl std::ops::DerefMut for Job {
    fn deref_mut(&mut self) -> &mut Hook {
        &mut self.hook
    }
}
