//! Hooks: callbacks with the params, injections, groups and labels they
//! declare.

use std::any::Any;
use std::future::Future;
use std::pin::Pin;
use std::sync::Arc;

use indexmap::IndexMap;
use serde_json::Value;
use utopia_validators::Validator;

use crate::Error;

/// A boxed, sendable future, as hook callbacks return.
pub type BoxFuture<'a, T> = Pin<Box<dyn Future<Output = T> + Send + 'a>>;

/// An injectable resource: any shared value.
///
/// PHP: a value a `Utopia\DI\Container` resolves.
pub type Resource = Arc<dyn Any + Send + Sync>;

/// A hook's callback: receives its resolved [`Arguments`].
///
/// PHP: the callable given to `action()`.
pub type Action = Arc<dyn Fn(Arguments) -> BoxFuture<'static, Result<(), Error>> + Send + Sync>;

/// Wraps an async closure over the resolved [`Arguments`] into an [`Action`].
pub fn action<F, Fut>(f: F) -> Action
where
    F: Fn(Arguments) -> Fut + Send + Sync + 'static,
    Fut: Future<Output = Result<(), Error>> + Send + 'static,
{
    Arc::new(move |args| Box::pin(f(args)))
}

/// Builds a param's default from its injections.
pub type DefaultFactory = Arc<dyn Fn(&[Resource]) -> Value + Send + Sync>;

/// Builds a param's validator from its injections.
pub type ValidatorFactory = Arc<dyn Fn(&[Resource]) -> Arc<dyn Validator> + Send + Sync>;

/// A param's default: a value, or a factory called with its injections.
///
/// Re-exported from the crate root as `ParamDefault`.
///
/// PHP: the `$default` of `param()`, a value or a callable.
#[derive(Clone)]
pub enum Default {
    /// A fixed value.
    Value(Value),
    /// Called with the param's injections, resolved, when the value is
    /// missing.
    Factory(DefaultFactory),
}

impl From<Value> for Default {
    /// A fixed default.
    fn from(v: Value) -> Self {
        Default::Value(v)
    }
}

impl std::fmt::Debug for Default {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        match self {
            Default::Value(v) => f.debug_tuple("Value").field(v).finish(),
            Default::Factory(_) => f.write_str("Factory"),
        }
    }
}

/// A param's validator: an instance, or a factory called with its
/// injections.
///
/// PHP: the `$validator` of `param()`, a `Validator` or a callable returning
/// one.
#[derive(Clone)]
pub enum Check {
    /// A validator instance.
    Validator(Arc<dyn Validator>),
    /// Builds the validator from the param's injections, resolved.
    Factory(ValidatorFactory),
}

impl<V: Validator + 'static> From<V> for Check {
    /// Wraps a validator instance.
    fn from(v: V) -> Self {
        Check::Validator(Arc::new(v))
    }
}

impl std::fmt::Debug for Check {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        match self {
            Check::Validator(v) => write!(f, "Validator({:?})", v.description()),
            Check::Factory(_) => f.write_str("Factory"),
        }
    }
}

/// A param a hook declares: where its value comes from, how it is validated,
/// and what documents it.
///
/// PHP: one entry of `Hook::getParams()`.
///
/// ```
/// use serde_json::json;
/// use utopia_servers::Param;
/// use utopia_validators::Text;
///
/// let param = Param::new(json!(""), Text::new(64), "Project ID.")
///     .optional(true)
///     .aliases(&["project", "project_id"]);
/// assert!(param.optional);
/// assert_eq!(param.aliases, ["project", "project_id"]);
/// ```
///
/// ```php
/// use Utopia\Servers\Hook;
/// use Utopia\Validator\Text;
///
/// $hook = (new Hook())->param('projectId', '', new Text(64), 'Project ID.', optional: true, aliases: ['project', 'project_id']);
/// echo implode(',', $hook->getParams()['projectId']['aliases']), "\n"; // project,project_id
/// ```
#[derive(Clone, Debug)]
pub struct Param {
    /// The value used when the param is not sent.
    pub default: Default,
    /// The validator a sent value must pass.
    pub validator: Check,
    /// A human description.
    pub description: String,
    /// Whether the param may be omitted.
    pub optional: bool,
    /// Resources passed to a default or validator factory.
    pub injections: Vec<String>,
    /// Whether the value is passed through without validation.
    pub skip_validation: bool,
    /// Whether the param is deprecated (documentation).
    pub deprecated: bool,
    /// An example value (documentation).
    pub example: String,
    /// The model the param refers to (documentation).
    pub model: Option<String>,
    /// Other names the value may be sent under.
    pub aliases: Vec<String>,
    /// Enum metadata for documentation generators: any value.
    ///
    /// PHP: `$enum`, any object.
    pub enumeration: Option<Resource>,
    /// The value resolved by the last [`crate::Base::prepare`] or set by
    /// [`Hook::set_param_value`]; `null` before.
    pub value: Value,
    /// The param's position among the hook's params and injections.
    pub order: usize,
}

impl Param {
    /// A required param with a default, a validator and a description.
    ///
    /// PHP: `param($key, $default, $validator, $description)`.
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
            enumeration: None,
            value: Value::Null,
            order: 0,
        }
    }

    /// Sets whether the param may be omitted.
    ///
    /// PHP: `$optional`.
    pub fn optional(mut self, optional: bool) -> Self {
        self.optional = optional;
        self
    }

    /// Sets the resources a default or validator factory receives.
    ///
    /// PHP: `$injections`.
    pub fn injections(mut self, injections: &[&str]) -> Self {
        self.injections = injections.iter().map(|s| (*s).to_owned()).collect();
        self
    }

    /// Sets whether the value skips validation.
    ///
    /// PHP: `$skipValidation`.
    pub fn skip_validation(mut self, skip: bool) -> Self {
        self.skip_validation = skip;
        self
    }

    /// Marks the param deprecated.
    ///
    /// PHP: `$deprecated`.
    pub fn deprecated(mut self, deprecated: bool) -> Self {
        self.deprecated = deprecated;
        self
    }

    /// Sets the example value.
    ///
    /// PHP: `$example`.
    pub fn example(mut self, example: &str) -> Self {
        self.example = example.to_owned();
        self
    }

    /// Sets the model the param refers to.
    ///
    /// PHP: `$model`.
    pub fn model(mut self, model: Option<&str>) -> Self {
        self.model = model.map(str::to_owned);
        self
    }

    /// Sets the other names the value may be sent under.
    ///
    /// PHP: `$aliases`.
    pub fn aliases(mut self, aliases: &[&str]) -> Self {
        self.aliases = aliases.iter().map(|s| (*s).to_owned()).collect();
        self
    }

    /// Sets the enum metadata.
    ///
    /// PHP: `$enum`.
    pub fn enumeration(mut self, enumeration: Option<Resource>) -> Self {
        self.enumeration = enumeration;
        self
    }
}

/// A resource a hook injects, and its position among the hook's params and
/// injections.
///
/// PHP: one entry of `Hook::getInjections()`.
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct Injection {
    /// The resource's name.
    pub name: String,
    /// Its position among the hook's params and injections.
    pub order: usize,
}

/// One resolved argument of a callback.
#[derive(Clone)]
pub enum Argument {
    /// A param's value.
    Value(Value),
    /// An injected resource.
    Resource(Resource),
}

impl std::fmt::Debug for Argument {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        match self {
            Argument::Value(v) => f.debug_tuple("Value").field(v).finish(),
            Argument::Resource(_) => f.write_str("Resource"),
        }
    }
}

/// What a callback receives: its arguments by name, in the order the server
/// resolved them.
///
/// PHP: the arguments a callback is called with (named for CLI tasks).
///
/// ```
/// use std::sync::Arc;
/// use serde_json::json;
/// use utopia_servers::{Argument, Arguments};
///
/// let mut args = Arguments::new();
/// args.push("email", Argument::Value(json!("me@example.com")));
/// args.push("db", Argument::Resource(Arc::new(String::from("primary"))));
/// assert_eq!(args.value("email"), Some(&json!("me@example.com")));
/// assert_eq!(args.resource::<String>("db").map(String::as_str), Some("primary"));
/// ```
///
/// ```php
/// $callback = function (string $email, string $db) {
///     echo $email, ' ', $db, "\n"; // me@example.com primary
/// };
/// $callback(...['email' => 'me@example.com', 'db' => 'primary']);
/// ```
#[derive(Clone, Debug, Default)]
pub struct Arguments {
    entries: Vec<(String, Argument)>,
}

impl Arguments {
    /// No arguments.
    pub fn new() -> Self {
        Self::default()
    }

    /// Appends an argument, replacing one of the same name in place.
    pub fn push(&mut self, name: &str, argument: Argument) {
        match self.entries.iter_mut().find(|(n, _)| n == name) {
            Some(entry) => entry.1 = argument,
            None => self.entries.push((name.to_owned(), argument)),
        }
    }

    /// Whether an argument of that name exists.
    pub fn contains(&self, name: &str) -> bool {
        self.entries.iter().any(|(n, _)| n == name)
    }

    /// The argument named `name`.
    pub fn get(&self, name: &str) -> Option<&Argument> {
        self.entries.iter().find(|(n, _)| n == name).map(|(_, a)| a)
    }

    /// The param value named `name`; `None` for a resource or a missing name.
    pub fn value(&self, name: &str) -> Option<&Value> {
        match self.get(name)? {
            Argument::Value(v) => Some(v),
            Argument::Resource(_) => None,
        }
    }

    /// The resource named `name` as a `T`; `None` when it is missing, a param
    /// value or another type.
    pub fn resource<T: Any + Send + Sync>(&self, name: &str) -> Option<&T> {
        match self.get(name)? {
            Argument::Resource(r) => r.downcast_ref::<T>(),
            Argument::Value(_) => None,
        }
    }

    /// The arguments, in order.
    pub fn iter(&self) -> impl ExactSizeIterator<Item = (&str, &Argument)> {
        self.entries.iter().map(|(n, a)| (n.as_str(), a))
    }

    /// The number of arguments.
    pub fn len(&self) -> usize {
        self.entries.len()
    }

    /// Whether there are no arguments.
    pub fn is_empty(&self) -> bool {
        self.entries.is_empty()
    }
}

/// The callback a hook has before one is set: does nothing.
fn noop() -> Action {
    Arc::new(|_| Box::pin(async { Ok(()) }))
}

/// A callback with its description, params, injections, groups and labels.
///
/// PHP: `Utopia\Servers\Hook`.
///
/// ```
/// use serde_json::json;
/// use utopia_servers::{Hook, Param};
/// use utopia_validators::Text;
///
/// let mut hook = Hook::new();
/// hook.inject("user").param("x", Param::new(json!(""), Text::new(10), "")).inject("time");
/// assert_eq!(hook.dependencies(), ["user", "time"]);
/// assert_eq!(hook.injections()["time"].order, 2);
/// ```
///
/// ```php
/// use Utopia\Servers\Hook;
/// use Utopia\Validator\Text;
///
/// $hook = (new Hook())->inject('user')->param('x', '', new Text(10))->inject('time');
/// echo implode(',', $hook->getDependencies()), "\n"; // user,time
/// echo $hook->getInjections()['time']['order'], "\n"; // 2
/// ```
#[derive(Clone)]
pub struct Hook {
    desc: String,
    params: IndexMap<String, Param>,
    groups: Vec<String>,
    labels: IndexMap<String, Value>,
    action: Action,
    injections: IndexMap<String, Injection>,
}

impl std::default::Default for Hook {
    fn default() -> Self {
        Self::new()
    }
}

impl std::fmt::Debug for Hook {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Hook")
            .field("desc", &self.desc)
            .field("groups", &self.groups)
            .field("labels", &self.labels)
            .field("params", &self.params.keys().collect::<Vec<_>>())
            .field("injections", &self.injections.keys().collect::<Vec<_>>())
            .finish()
    }
}

impl Hook {
    /// A hook without params, injections, groups or labels, whose callback
    /// does nothing.
    ///
    /// PHP: `new Hook()`.
    pub fn new() -> Self {
        Self {
            desc: String::new(),
            params: IndexMap::new(),
            groups: Vec::new(),
            labels: IndexMap::new(),
            action: noop(),
            injections: IndexMap::new(),
        }
    }

    /// Sets the description.
    ///
    /// PHP: `desc($desc)`.
    pub fn desc(&mut self, desc: &str) -> &mut Self {
        self.desc = desc.to_owned();
        self
    }

    /// The description (empty by default).
    ///
    /// PHP: `getDesc()`.
    pub fn description(&self) -> &str {
        &self.desc
    }

    /// Sets the groups, replacing the previous ones.
    ///
    /// PHP: `groups($groups)`.
    pub fn groups(&mut self, groups: &[&str]) -> &mut Self {
        self.groups = groups.iter().map(|g| (*g).to_owned()).collect();
        self
    }

    /// The groups.
    ///
    /// PHP: `getGroups()`.
    pub fn get_groups(&self) -> &[String] {
        &self.groups
    }

    /// Sets the label `key`.
    ///
    /// PHP: `label($key, $value)`.
    pub fn label(&mut self, key: &str, value: Value) -> &mut Self {
        self.labels.insert(key.to_owned(), value);
        self
    }

    /// The label `key`; `None` when it is not set or `null`.
    ///
    /// PHP: `getLabel($key, $default)` returns `$default` in those cases.
    ///
    /// ```
    /// use serde_json::json;
    /// use utopia_servers::Hook;
    ///
    /// let mut hook = Hook::new();
    /// hook.label("key", json!("value"));
    /// assert_eq!(hook.get_label("key"), Some(&json!("value")));
    /// assert_eq!(hook.get_label("unknown").unwrap_or(&json!("default")), "default");
    /// ```
    ///
    /// ```php
    /// use Utopia\Servers\Hook;
    ///
    /// $hook = (new Hook())->label('key', 'value');
    /// echo $hook->getLabel('key', 'default'), "\n";     // value
    /// echo $hook->getLabel('unknown', 'default'), "\n"; // default
    /// ```
    pub fn get_label(&self, key: &str) -> Option<&Value> {
        self.labels.get(key).filter(|v| !v.is_null())
    }

    /// Every label, in the order they were first set.
    pub fn labels(&self) -> &IndexMap<String, Value> {
        &self.labels
    }

    /// Sets the callback: an async closure over the resolved [`Arguments`].
    ///
    /// PHP: `action($callback)`.
    pub fn action<F, Fut>(&mut self, f: F) -> &mut Self
    where
        F: Fn(Arguments) -> Fut + Send + Sync + 'static,
        Fut: Future<Output = Result<(), Error>> + Send + 'static,
    {
        self.action = action(f);
        self
    }

    /// Sets an already wrapped callback.
    pub fn set_action(&mut self, action: Action) -> &mut Self {
        self.action = action;
        self
    }

    /// The callback: the one set, or one doing nothing.
    ///
    /// PHP: `getAction()`.
    pub fn get_action(&self) -> &Action {
        &self.action
    }

    /// The injections by name, with their positions.
    ///
    /// PHP: `getInjections()`.
    pub fn injections(&self) -> &IndexMap<String, Injection> {
        &self.injections
    }

    /// The names of the injected resources, in declaration order.
    ///
    /// PHP: `getDependencies()`.
    pub fn dependencies(&self) -> Vec<&str> {
        let mut list: Vec<&Injection> = self.injections.values().collect();
        list.sort_by_key(|i| i.order);
        list.into_iter().map(|i| i.name.as_str()).collect()
    }

    /// Declares a resource to inject.
    ///
    /// Fails with [`Error::Exception`] when it is already declared; use
    /// [`Hook::try_inject`] to handle that, this method panics.
    ///
    /// PHP: `inject($name)`, which throws `\Exception`.
    pub fn inject(&mut self, name: &str) -> &mut Self {
        if let Err(e) = self.try_inject(name) {
            panic!("{e}");
        }
        self
    }

    /// Declares a resource to inject; [`Error::Exception`]
    /// (`Injection already declared for <name>`) when it is already declared.
    ///
    /// PHP: `inject($name)`.
    pub fn try_inject(&mut self, name: &str) -> Result<&mut Self, Error> {
        if self.injections.contains_key(name) {
            return Err(Error::exception(format!("Injection already declared for {name}"), 0));
        }
        let order = self.params.len() + self.injections.len();
        self.injections.insert(name.to_owned(), Injection { name: name.to_owned(), order });
        Ok(self)
    }

    /// Declares the param `key`, replacing one of the same key in place.
    ///
    /// PHP: `param($key, ...)`.
    pub fn param(&mut self, key: &str, mut param: Param) -> &mut Self {
        param.order = self.params.len() + self.injections.len();
        param.value = Value::Null;
        self.params.insert(key.to_owned(), param);
        self
    }

    /// The params by key, in declaration order.
    ///
    /// PHP: `getParams()`.
    pub fn params(&self) -> &IndexMap<String, Param> {
        &self.params
    }

    /// Each param's resolved value, by key.
    ///
    /// PHP: `getParamsValues()`.
    pub fn params_values(&self) -> IndexMap<&str, &Value> {
        self.params.iter().map(|(k, p)| (k.as_str(), &p.value)).collect()
    }

    /// Sets the resolved value of the param `key`; [`Error::Exception`]
    /// (`Unknown key`) when no such param is declared.
    ///
    /// PHP: `setParamValue($key, $value)`.
    pub fn set_param_value(&mut self, key: &str, value: Value) -> Result<&mut Self, Error> {
        match self.params.get_mut(key) {
            Some(p) => {
                p.value = value;
                Ok(self)
            }
            None => Err(Error::exception("Unknown key", 0)),
        }
    }

    /// The resolved value of the param `key`; [`Error::Exception`]
    /// (`Unknown key`) when no such param is declared.
    ///
    /// PHP: `getParamValue($key)`.
    pub fn param_value(&self, key: &str) -> Result<&Value, Error> {
        self.params.get(key).map(|p| &p.value).ok_or_else(|| Error::exception("Unknown key", 0))
    }
}
