//! Actions: what an endpoint, task or worker does, described once.

use std::borrow::Cow;
use std::future::Future;
use std::sync::Arc;

use indexmap::IndexMap;
use serde_json::Value;
use utopia_servers::{Check, ParamDefault};

use crate::Error;

/// `GET`.
pub const HTTP_REQUEST_METHOD_GET: &str = "GET";
/// `POST`.
pub const HTTP_REQUEST_METHOD_POST: &str = "POST";
/// `PUT`.
pub const HTTP_REQUEST_METHOD_PUT: &str = "PUT";
/// `PATCH`.
pub const HTTP_REQUEST_METHOD_PATCH: &str = "PATCH";
/// `DELETE`.
pub const HTTP_REQUEST_METHOD_DELETE: &str = "DELETE";
/// `OPTIONS`.
pub const HTTP_REQUEST_METHOD_OPTIONS: &str = "OPTIONS";
/// `HEAD`.
pub const HTTP_REQUEST_METHOD_HEAD: &str = "HEAD";

/// What an action is registered as on its server: the work itself
/// ([`ActionType::DEFAULT`]: a route, a task or a job) or one of the hooks
/// around it. Any other name registers like the default.
///
/// PHP: the `Action::TYPE_*` strings.
#[derive(Debug, Clone, PartialEq, Eq, Hash)]
pub struct ActionType(Cow<'static, str>);

impl ActionType {
    /// A route, a task or a job.
    ///
    /// PHP: `Action::TYPE_DEFAULT` (`Default`).
    pub const DEFAULT: Self = Self(Cow::Borrowed("Default"));
    /// A hook run before the work.
    ///
    /// PHP: `Action::TYPE_INIT` (`Init`).
    pub const INIT: Self = Self(Cow::Borrowed("Init"));
    /// A hook run after the work.
    ///
    /// PHP: `Action::TYPE_SHUTDOWN` (`Shutdown`).
    pub const SHUTDOWN: Self = Self(Cow::Borrowed("Shutdown"));
    /// A hook run when the work fails.
    ///
    /// PHP: `Action::TYPE_ERROR` (`Error`).
    pub const ERROR: Self = Self(Cow::Borrowed("Error"));
    /// An HTTP hook run for `OPTIONS` requests.
    ///
    /// PHP: `Action::TYPE_OPTIONS` (`Options`).
    pub const OPTIONS: Self = Self(Cow::Borrowed("Options"));
    /// A worker hook run when a worker starts.
    ///
    /// PHP: `Action::TYPE_WORKER_START` (`WorkerStart`).
    pub const WORKER_START: Self = Self(Cow::Borrowed("WorkerStart"));
    /// A worker hook run when a worker stops.
    ///
    /// PHP: `Action::TYPE_WORKER_STOP` (`WorkerStop`).
    pub const WORKER_STOP: Self = Self(Cow::Borrowed("WorkerStop"));

    /// A type by name.
    pub fn new(name: impl Into<Cow<'static, str>>) -> Self {
        Self(name.into())
    }

    /// The type's name.
    pub fn as_str(&self) -> &str {
        &self.0
    }
}

/// Enum metadata for a whitelist-backed param, for SDK generators.
///
/// PHP: `Utopia\Platform\Enum`.
///
/// ```
/// use utopia_platform::Enum;
///
/// let status = Enum::new().name("ArticleStatus").map(&[("draft", "Draft"), ("published", "Published")]);
/// assert_eq!(status.name.as_deref(), Some("ArticleStatus"));
/// assert_eq!(status.map.as_ref().map(|m| m["draft"].as_str()), Some("Draft"));
/// ```
///
/// ```php
/// use Utopia\Platform\Enum;
///
/// $status = new Enum(name: 'ArticleStatus', map: ['draft' => 'Draft', 'published' => 'Published']);
/// echo $status->name, ' ', $status->map['draft'], "\n"; // ArticleStatus Draft
/// ```
#[derive(Debug, Clone, Default, PartialEq, Eq)]
pub struct Enum {
    /// The generated enum's name.
    pub name: Option<String>,
    /// Whitelist values mapped to the generated enum's case names.
    pub map: Option<IndexMap<String, String>>,
    /// Whitelist values left out of the generated enum.
    pub exclude: Option<Vec<String>>,
}

impl Enum {
    /// No name, map or exclusions.
    ///
    /// PHP: `new Enum()`.
    pub fn new() -> Self {
        Self::default()
    }

    /// Sets the name.
    pub fn name(mut self, name: &str) -> Self {
        self.name = Some(name.to_owned());
        self
    }

    /// Sets the map of values to case names.
    pub fn map(mut self, map: &[(&str, &str)]) -> Self {
        self.map = Some(map.iter().map(|(k, v)| ((*k).to_owned(), (*v).to_owned())).collect());
        self
    }

    /// Sets the excluded values.
    pub fn exclude(mut self, exclude: &[&str]) -> Self {
        self.exclude = Some(exclude.iter().map(|s| (*s).to_owned()).collect());
        self
    }
}

/// A param an action declares, forwarded to the hook it registers as.
///
/// PHP: one entry of `Action::getParams()`.
#[derive(Clone, Debug)]
pub struct Param {
    /// The value used when the param is not sent.
    pub default: ParamDefault,
    /// The validator a sent value must pass.
    pub validator: Check,
    /// A human description.
    pub description: String,
    /// Whether the param may be omitted.
    pub optional: bool,
    /// Resources passed to a default or validator factory.
    pub injections: Vec<String>,
    /// Whether the value skips validation.
    pub skip_validation: bool,
    /// Whether the param is deprecated (documentation).
    pub deprecated: bool,
    /// An example value (documentation).
    pub example: String,
    /// Other names the value may be sent under.
    pub aliases: Vec<String>,
    /// Enum metadata (documentation).
    pub enumeration: Option<Arc<Enum>>,
}

impl Param {
    /// A required param.
    ///
    /// PHP: `param($key, $default, $validator, $description)`.
    pub fn new(default: impl Into<ParamDefault>, validator: impl Into<Check>, description: &str) -> Self {
        Self {
            default: default.into(),
            validator: validator.into(),
            description: description.to_owned(),
            optional: false,
            injections: Vec::new(),
            skip_validation: false,
            deprecated: false,
            example: String::new(),
            aliases: Vec::new(),
            enumeration: None,
        }
    }

    /// Sets whether the param may be omitted.
    pub fn optional(mut self, optional: bool) -> Self {
        self.optional = optional;
        self
    }

    /// Sets the resources a default or validator factory receives.
    pub fn injections(mut self, injections: &[&str]) -> Self {
        self.injections = injections.iter().map(|s| (*s).to_owned()).collect();
        self
    }

    /// Sets whether the value skips validation.
    pub fn skip_validation(mut self, skip: bool) -> Self {
        self.skip_validation = skip;
        self
    }

    /// Marks the param deprecated.
    pub fn deprecated(mut self, deprecated: bool) -> Self {
        self.deprecated = deprecated;
        self
    }

    /// Sets the example value.
    pub fn example(mut self, example: &str) -> Self {
        self.example = example.to_owned();
        self
    }

    /// Sets the other names the value may be sent under.
    pub fn aliases(mut self, aliases: &[&str]) -> Self {
        self.aliases = aliases.iter().map(|s| (*s).to_owned()).collect();
        self
    }

    /// Sets the enum metadata.
    pub fn enumeration(mut self, enumeration: Enum) -> Self {
        self.enumeration = Some(Arc::new(enumeration));
        self
    }
}

/// One declaration of an action, in order: a param or an injection.
///
/// PHP: one entry of `Action::getOptions()` (keys `param:<key>` and
/// `injection:<name>`).
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum Declaration {
    /// The param of that key.
    Param(String),
    /// The injection of that name.
    Injection(String),
}

/// An action's callback, for the server its service runs on.
///
/// PHP: the closure given to `callback()`, which every server calls with
/// the action's params and injections.
#[derive(Clone)]
pub enum Callback {
    /// An HTTP route or hook callback.
    Http(utopia_http::Action),
    /// A CLI task or hook callback.
    Task(utopia_servers::Action),
    /// A queue job or worker hook callback.
    Worker(utopia_queue::hook::Action),
}

impl Callback {
    /// An HTTP callback: `Callback::http(async |scope| { .. })`, as
    /// [`utopia_http::action`] takes it.
    pub fn http<F, R>(f: F) -> Self
    where
        F: for<'a> utopia_http::Callback<'a> + AsyncFn(utopia_http::Scope<'_>) -> R + Send + Sync + 'static,
        R: utopia_http::IntoResult,
    {
        Callback::Http(utopia_http::action(f))
    }

    /// A CLI callback: `Callback::task(|args| async move { .. })`, as
    /// [`utopia_servers::action`] takes it.
    pub fn task<F, Fut>(f: F) -> Self
    where
        F: Fn(utopia_servers::Arguments) -> Fut + Send + Sync + 'static,
        Fut: Future<Output: utopia_servers::IntoResult> + Send + 'static,
    {
        Callback::Task(utopia_servers::action(f))
    }

    /// A queue callback: `Callback::worker(|scope| async move { .. })`, as
    /// [`utopia_queue::hook::action`] takes it.
    pub fn worker<F, Fut>(f: F) -> Self
    where
        F: Fn(utopia_queue::hook::Scope) -> Fut + Send + Sync + 'static,
        Fut: Future<Output: utopia_queue::hook::IntoResult> + Send + 'static,
    {
        Callback::Worker(utopia_queue::hook::action(f))
    }
}

impl std::fmt::Debug for Callback {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.write_str(match self {
            Callback::Http(_) => "Http",
            Callback::Task(_) => "Task",
            Callback::Worker(_) => "Worker",
        })
    }
}

/// What an endpoint, a task or a worker job does: its params, injections,
/// labels and callback, and for HTTP its methods, path and aliases.
/// [`crate::Platform`] registers it on the server of its service.
///
/// PHP: `Utopia\Platform\Action` with the `Scope\HTTP` trait.
///
/// ```
/// use serde_json::json;
/// use utopia_platform::{Action, Param, HTTP_REQUEST_METHOD_POST};
/// use utopia_validators::Text;
///
/// let mut action = Action::new();
/// action
///     .set_http_method(HTTP_REQUEST_METHOD_POST)
///     .set_http_path("/v1/teams")
///     .desc("Create team")
///     .groups(&["api", "teams"])
///     .label("scope", json!("teams.write"))
///     .param("name", Param::new(json!(""), Text::new(128), "Team name."))
///     .inject("response");
///
/// assert_eq!(action.http_methods(), ["POST"]);
/// assert_eq!(action.http_path(), Some("/v1/teams"));
/// assert_eq!(action.injections(), ["response"]);
/// assert_eq!(action.labels()["scope"], json!("teams.write"));
/// ```
///
/// ```php
/// use Utopia\Platform\Action;
/// use Utopia\Validator\Text;
///
/// $action = new class () extends Action {
///     public function __construct()
///     {
///         $this->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
///             ->setHttpPath('/v1/teams')
///             ->desc('Create team')
///             ->groups(['api', 'teams'])
///             ->label('scope', 'teams.write')
///             ->param('name', '', new Text(128), 'Team name.')
///             ->inject('response');
///     }
/// };
///
/// echo implode(',', $action->getHttpMethods()), ' ', $action->getHttpPath(), "\n"; // POST /v1/teams
/// echo implode(',', $action->getInjections()), ' ', $action->getLabels()['scope'], "\n"; // response teams.write
/// ```
#[derive(Clone, Debug)]
pub struct Action {
    kind: ActionType,
    desc: Option<String>,
    groups: Vec<String>,
    callback: Option<Callback>,
    options: IndexMap<String, Declaration>,
    params: IndexMap<String, Param>,
    injections: Vec<String>,
    labels: IndexMap<String, Value>,
    http_methods: Vec<String>,
    http_path: Option<String>,
    http_aliases: Vec<String>,
}

impl Default for Action {
    fn default() -> Self {
        Self::new()
    }
}

impl Action {
    /// A default action without params, injections, labels or callback.
    pub fn new() -> Self {
        Self {
            kind: ActionType::DEFAULT,
            desc: None,
            groups: Vec::new(),
            callback: None,
            options: IndexMap::new(),
            params: IndexMap::new(),
            injections: Vec::new(),
            labels: IndexMap::new(),
            http_methods: Vec::new(),
            http_path: None,
            http_aliases: Vec::new(),
        }
    }

    /// Sets the type.
    ///
    /// PHP: `setType($type)`.
    pub fn set_type(&mut self, kind: ActionType) -> &mut Self {
        self.kind = kind;
        self
    }

    /// The type.
    ///
    /// PHP: `getType()`.
    pub fn kind(&self) -> &ActionType {
        &self.kind
    }

    /// Sets the description.
    ///
    /// PHP: `desc($description)`.
    pub fn desc(&mut self, description: &str) -> &mut Self {
        self.desc = Some(description.to_owned());
        self
    }

    /// The description, if set.
    ///
    /// PHP: `getDesc()`.
    pub fn description(&self) -> Option<&str> {
        self.desc.as_deref()
    }

    /// Sets the groups.
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

    /// Sets the callback.
    ///
    /// PHP: `callback($callback)`.
    pub fn callback(&mut self, callback: Callback) -> &mut Self {
        self.callback = Some(callback);
        self
    }

    /// The callback, if set.
    ///
    /// PHP: `getCallback()`.
    pub fn get_callback(&self) -> Option<&Callback> {
        self.callback.as_ref()
    }

    /// Declares the param `key`, replacing one of the same key in place.
    ///
    /// PHP: `param($key, ...)`.
    pub fn param(&mut self, key: &str, param: Param) -> &mut Self {
        self.options.insert(format!("param:{key}"), Declaration::Param(key.to_owned()));
        self.params.insert(key.to_owned(), param);
        self
    }

    /// The params by key.
    ///
    /// PHP: `getParams()`.
    pub fn params(&self) -> &IndexMap<String, Param> {
        &self.params
    }

    /// Declares a resource to inject. Injecting a name twice lists it twice
    /// in [`Action::injections`] and registers it once.
    ///
    /// Fails with [`Error::Exception`] (`Injection already declared for
    /// <name>`) only when `name` is an integer below the number of
    /// injections already declared; use [`Action::try_inject`] to handle
    /// that, this method panics.
    ///
    /// PHP: `inject($injection)`, which checks the name against the
    /// positions of the injections list (a quirk kept as is).
    pub fn inject(&mut self, name: &str) -> &mut Self {
        if let Err(e) = self.try_inject(name) {
            panic!("{e}");
        }
        self
    }

    /// Declares a resource to inject; see [`Action::inject`].
    pub fn try_inject(&mut self, name: &str) -> Result<&mut Self, Error> {
        let taken = php_std::types::numeric_key(name.as_bytes())
            .is_some_and(|i| usize::try_from(i).is_ok_and(|i| i < self.injections.len()));
        if taken {
            return Err(Error::Exception(format!("Injection already declared for {name}")));
        }
        self.options.insert(format!("injection:{name}"), Declaration::Injection(name.to_owned()));
        self.injections.push(name.to_owned());
        Ok(self)
    }

    /// The injected names, in declaration order.
    ///
    /// PHP: `getInjections()`.
    pub fn injections(&self) -> &[String] {
        &self.injections
    }

    /// Sets the label `key`.
    ///
    /// PHP: `label($key, $value)`.
    pub fn label(&mut self, key: &str, value: Value) -> &mut Self {
        self.labels.insert(key.to_owned(), value);
        self
    }

    /// The labels.
    ///
    /// PHP: `getLabels()`.
    pub fn labels(&self) -> &IndexMap<String, Value> {
        &self.labels
    }

    /// The params and injections in declaration order, by their option key
    /// (`param:<key>`, `injection:<name>`).
    ///
    /// PHP: `getOptions()`.
    pub fn options(&self) -> &IndexMap<String, Declaration> {
        &self.options
    }

    /// Sets the HTTP path.
    ///
    /// PHP: `setHttpPath($path)`.
    pub fn set_http_path(&mut self, path: &str) -> &mut Self {
        self.http_path = Some(path.to_owned());
        self
    }

    /// The HTTP path, once set.
    ///
    /// PHP: `getHttpPath()` (a `TypeError` before it is set).
    pub fn http_path(&self) -> Option<&str> {
        self.http_path.as_deref()
    }

    /// The HTTP path; [`Error::Type`] before it is set.
    ///
    /// PHP: `getHttpPath()`.
    ///
    /// ```
    /// use utopia_platform::Action;
    ///
    /// let error = Action::new().try_http_path().unwrap_err();
    /// assert_eq!(error.php_class(), "TypeError");
    /// assert_eq!(error.to_string(), "Utopia\\Platform\\Action::getHttpPath(): Return value must be of type string, null returned");
    /// ```
    ///
    /// ```php
    /// use Utopia\Platform\Action;
    ///
    /// try {
    ///     (new class () extends Action {})->getHttpPath();
    /// } catch (\TypeError $e) {
    ///     echo $e->getMessage(), "\n"; // Utopia\Platform\Action::getHttpPath(): Return value must be of type string, null returned
    /// }
    /// ```
    pub fn try_http_path(&self) -> Result<&str, Error> {
        self.http_path().ok_or_else(|| {
            Error::Type(
                "Utopia\\Platform\\Action::getHttpPath(): Return value must be of type string, null returned".into(),
            )
        })
    }

    /// Sets the only HTTP method.
    ///
    /// PHP: `setHttpMethod($method)`.
    pub fn set_http_method(&mut self, method: &str) -> &mut Self {
        self.http_methods = vec![method.to_owned()];
        self
    }

    /// Sets the HTTP methods, without duplicates.
    ///
    /// PHP: `setHttpMethods($methods)`.
    pub fn set_http_methods(&mut self, methods: &[&str]) -> &mut Self {
        self.http_methods.clear();
        for m in methods {
            if !self.http_methods.iter().any(|x| x == m) {
                self.http_methods.push((*m).to_owned());
            }
        }
        self
    }

    /// The HTTP methods.
    ///
    /// PHP: `getHttpMethods()`.
    pub fn http_methods(&self) -> &[String] {
        &self.http_methods
    }

    /// The first HTTP method, or `""`.
    ///
    /// PHP: `getHttpMethod()` (deprecated).
    pub fn http_method(&self) -> &str {
        self.http_methods.first().map_or("", String::as_str)
    }

    /// Adds a path the action also answers on.
    ///
    /// PHP: `httpAlias($path)`.
    pub fn http_alias(&mut self, path: &str) -> &mut Self {
        self.http_aliases.push(path.to_owned());
        self
    }

    /// The alias paths.
    ///
    /// PHP: `getHttpAliases()`.
    pub fn http_aliases(&self) -> &[String] {
        &self.http_aliases
    }
}
