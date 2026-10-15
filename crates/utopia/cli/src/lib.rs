//! Command-line applications: a [`Cli`] parses `<command> --key=value ...`,
//! matches the command to a [`Task`], resolves the task's params from the
//! arguments (validated, booleans coerced) and its injected resources from a
//! container, and runs it between init and shutdown hooks; failures go to
//! the error hooks. An [`Adapter`] decides how the work runs: once
//! ([`Generic`]) or in several workers ([`Workers`]).
//!
//! PHP: ports `utopia-php/cli`.
//!
//! | PHP | Rust |
//! |---|---|
//! | `Utopia\CLI\CLI` | [`Cli`] |
//! | `new CLI($adapter, $args, $container)` | [`Cli::new`], [`Cli::with_adapter`], [`Cli::with_container`] |
//! | `task()`, `init()`, `shutdown()`, `error()` | [`Cli::task`], [`Cli::init`], [`Cli::shutdown`], [`Cli::error`] |
//! | `run()`, `match()`, `parse()` | [`Cli::run`], [`Cli::matched`], [`Cli::parse`] |
//! | `getTasks()`, `getArgs()` | [`Cli::tasks`], [`Cli::args`] |
//! | `setResource()`, `getResource()`, `getResources()` | [`Cli::set_resource`], [`Cli::resource`], [`Cli::resources`] |
//! | `getContainer()`, `setContainer()`, `reset()` | [`Cli::container`], [`Cli::set_container`], [`Cli::reset`] |
//! | `Utopia\CLI\Task` | [`Task`] (a [`Hook`] with a name) |
//! | `Utopia\CLI\Adapter`, `Adapters\Generic`, `Adapters\Swoole` | [`Adapter`], [`Generic`], [`Workers`] |
//! | `\Exception` | [`Error`] (from `utopia-servers`) |
//!
//! PHP: callbacks are called with named arguments (each param's key in
//! camelCase, then each injection not already passed); here they receive
//! the same names in [`Arguments`].
//!
//! ```
//! # futures_util::FutureExt::now_or_never(async {
//! use std::sync::{Arc, Mutex};
//! use serde_json::json;
//! use utopia_cli::Cli;
//! use utopia_servers::Param;
//! use utopia_validators::Text;
//!
//! let mut cli = Cli::new(&["app", "build", "--email=me@example.com"])?;
//! let seen = Arc::new(Mutex::new(None));
//! let out = seen.clone();
//! cli.task("build")
//!     .param("email", Param::new(json!(null), Text::new(0), "Valid email address"))
//!     .action(move |args| {
//!         *out.lock().unwrap() = args.value("email").cloned();
//!         async {}
//!     });
//!
//! cli.run().await?;
//! assert_eq!(*seen.lock().unwrap(), Some(json!("me@example.com")));
//! # Ok::<(), utopia_cli::Error>(())
//! # }).unwrap()?;
//! # Ok::<(), utopia_cli::Error>(())
//! ```
//!
//! ```php
//! use Utopia\CLI\Adapters\Generic;
//! use Utopia\CLI\CLI;
//! use Utopia\Validator\Text;
//!
//! $cli = new CLI(new Generic(), ['app', 'build', '--email=me@example.com']);
//! $cli->task('build')
//!     ->param('email', null, new Text(0), 'Valid email address')
//!     ->action(function ($email) {
//!         echo $email, "\n"; // me@example.com
//!     });
//! $cli->run();
//! ```

#![warn(missing_docs)]

mod adapter;

use std::sync::{Arc, Mutex};

use indexmap::IndexMap;
use serde_json::Value;
use utopia_di::Container;
pub use utopia_servers::{Argument, Arguments, Error, Hook};
use utopia_servers::{BoxFuture, Check, ParamDefault};
use utopia_validators::{Type, Validator};

pub use adapter::{Adapter, Generic, Job, WorkerCallback, Workers};

/// The getting started guide (`guide.md`), compiled and run with the doctests.
#[cfg(doctest)]
#[doc = include_str!("../guide.md")]
pub struct GettingStarted;

/// A command a [`Cli`] runs: a [`Hook`] with a name. It dereferences to its
/// hook, so the hook's setters and getters apply to it.
///
/// PHP: `Utopia\CLI\Task`.
///
/// ```
/// use serde_json::json;
/// use utopia_cli::Task;
///
/// let mut task = Task::new("test");
/// task.desc("test task").label("key", json!("value"));
/// assert_eq!(task.name(), "test");
/// assert_eq!(task.description(), "test task");
/// assert_eq!(task.get_label("key"), Some(&json!("value")));
/// ```
///
/// ```php
/// use Utopia\CLI\Task;
///
/// $task = (new Task('test'))->desc('test task')->label('key', 'value');
/// echo $task->getName(), ' ', $task->getDesc(), ' ', $task->getLabel('key', 'default'), "\n"; // test test task value
/// ```
#[derive(Clone, Debug)]
pub struct Task {
    name: String,
    hook: Hook,
}

/// What calling a task without a callback fails with.
const NO_CALLBACK: &str =
    "call_user_func_array(): Argument #1 ($callback) must be a valid callback, no array or string given";

impl Task {
    /// A task named `name`, without a callback: until [`Hook::action`] sets
    /// one, calling it fails with [`Error::Type`], which [`Cli::run`] does
    /// not hand to the error hooks.
    ///
    /// PHP: `new Task($name)`, which does not call the `Hook` constructor, so
    /// the task's action is `null` rather than a callback doing nothing
    /// (a quirk kept as is).
    ///
    /// ```
    /// # futures_util::FutureExt::now_or_never(async {
    /// use utopia_cli::{Arguments, Task};
    ///
    /// let task = Task::new("build");
    /// let error = (task.get_action())(Arguments::new()).await.unwrap_err();
    /// assert_eq!(error.php_class(), "TypeError");
    /// # }).unwrap();
    /// ```
    ///
    /// ```php
    /// use Utopia\CLI\Task;
    ///
    /// var_dump((new Task('build'))->getAction()); // NULL
    /// ```
    pub fn new(name: &str) -> Self {
        let mut hook = Hook::new();
        hook.action(|_| async { Err(Error::Type(NO_CALLBACK.to_owned())) });
        Self { name: name.to_owned(), hook }
    }

    /// The task's name: the command that runs it.
    ///
    /// PHP: `getName()`.
    pub fn name(&self) -> &str {
        &self.name
    }

    /// The task's hook.
    pub fn hook(&self) -> &Hook {
        &self.hook
    }
}

impl std::ops::Deref for Task {
    type Target = Hook;
    fn deref(&self) -> &Hook {
        &self.hook
    }
}

impl std::ops::DerefMut for Task {
    fn deref_mut(&mut self) -> &mut Hook {
        &mut self.hook
    }
}

/// A parsed argument value: a string, `null` for `--key` without `=`, or a
/// list of those for a key given several times.
pub type Args = IndexMap<String, Value>;

/// A command-line application.
///
/// PHP: `Utopia\CLI\CLI`.
pub struct Cli {
    adapter: Arc<dyn Adapter>,
    command: String,
    args: Args,
    container: Arc<Container>,
    parent: Option<Arc<Container>>,
    tasks: IndexMap<String, Task>,
    init: Vec<Hook>,
    shutdown: Vec<Hook>,
    errors: Vec<Hook>,
}

impl std::fmt::Debug for Cli {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Cli")
            .field("command", &self.command)
            .field("args", &self.args)
            .field("tasks", &self.tasks.keys().collect::<Vec<_>>())
            .finish()
    }
}

/// `camelCaseIt()`: `-` and `_` separate words, joined in camel case.
fn camel_case(key: &str) -> String {
    let key = key.replace('-', "_");
    let words = php_std::string::ucwords(key.as_bytes(), b"_");
    let joined: Vec<u8> = words.iter().copied().filter(|b| *b != b'_').collect();
    String::from_utf8_lossy(&php_std::string::lcfirst(&joined)).into_owned()
}

fn exception(message: String, code: i64) -> Error {
    Error::exception(message, code)
}

impl Cli {
    /// An application for `args` (the program, the command, then
    /// `--key=value` arguments), run by [`Generic`], with an empty
    /// container; [`Error::Exception`] (`Missing command`) without a
    /// command.
    ///
    /// PHP: `new CLI(null, $args)`.
    pub fn new<S: AsRef<str>>(args: &[S]) -> Result<Self, Error> {
        Self::with_container(Arc::new(Generic), args, None)
    }

    /// An application for the process's arguments.
    ///
    /// PHP: `new CLI()`, reading `$_SERVER['argv']`.
    pub fn from_env() -> Result<Self, Error> {
        let args: Vec<String> = std::env::args().collect();
        Self::new(&args)
    }

    /// An application run by `adapter`.
    ///
    /// PHP: `new CLI($adapter, $args)`.
    pub fn with_adapter<S: AsRef<str>>(adapter: Arc<dyn Adapter>, args: &[S]) -> Result<Self, Error> {
        Self::with_container(adapter, args, None)
    }

    /// An application whose container is a child of `container`, so its
    /// resources are found too.
    ///
    /// PHP: `new CLI($adapter, $args, $container)`.
    pub fn with_container<S: AsRef<str>>(
        adapter: Arc<dyn Adapter>,
        args: &[S],
        container: Option<Arc<Container>>,
    ) -> Result<Self, Error> {
        let container_child = Arc::new(match &container {
            Some(parent) => Container::with_parent(parent.clone()),
            None => Container::new(),
        });
        let mut cli = Self {
            adapter,
            command: String::new(),
            args: Args::new(),
            container: container_child,
            parent: container,
            tasks: IndexMap::new(),
            init: Vec::new(),
            shutdown: Vec::new(),
            errors: Vec::new(),
        };
        cli.args = cli.parse(args)?;
        Ok(cli)
    }

    /// Adds a hook run before the task.
    ///
    /// PHP: `init()`.
    pub fn init(&mut self) -> &mut Hook {
        self.init.push(Hook::new());
        self.init.last_mut().unwrap_or_else(|| unreachable!())
    }

    /// Adds a hook run after the task.
    ///
    /// PHP: `shutdown()`.
    pub fn shutdown(&mut self) -> &mut Hook {
        self.shutdown.push(Hook::new());
        self.shutdown.last_mut().unwrap_or_else(|| unreachable!())
    }

    /// Adds a hook run when the task, a hook or resolving their params fails,
    /// with the failure injectable as `error` (an `Arc<`[`Error`]`>`).
    ///
    /// PHP: `error()`.
    pub fn error(&mut self) -> &mut Hook {
        self.errors.push(Hook::new());
        self.errors.last_mut().unwrap_or_else(|| unreachable!())
    }

    /// Adds the task run by the command `name`, replacing one of that name.
    ///
    /// PHP: `task($name)`.
    pub fn task(&mut self, name: &str) -> &mut Task {
        self.tasks.insert(name.to_owned(), Task::new(name));
        &mut self.tasks[name]
    }

    /// The resource `name`; [`Error::Exception`] (`Failed to find resource:
    /// "<name>"`) when neither the container nor its parents define it.
    ///
    /// PHP: `getResource($name)`.
    pub fn resource(&self, name: &str) -> Result<utopia_servers::Resource, Error> {
        if !self.container.has(name) {
            return Err(exception(format!("Failed to find resource: \"{name}\""), 0));
        }
        Ok(self.container.get_any(name)?)
    }

    /// The resources named in `names`, by name.
    ///
    /// PHP: `getResources($list)`.
    pub fn resources(&self, names: &[&str]) -> Result<IndexMap<String, utopia_servers::Resource>, Error> {
        names.iter().map(|n| Ok(((*n).to_owned(), self.resource(n)?))).collect()
    }

    /// Registers the resource `name`, built once from `dependencies` by
    /// `factory`.
    ///
    /// PHP: `setResource($name, $callback, $dependencies)`.
    pub fn set_resource<T, F>(&self, name: &str, dependencies: &[&str], factory: F)
    where
        T: std::any::Any + Send + Sync,
        F: Fn(&utopia_di::Dependencies<'_>) -> Result<T, utopia_di::Error> + Send + Sync + 'static,
    {
        self.container.set(name, dependencies, factory);
    }

    /// The application's container.
    ///
    /// PHP: `getContainer()`.
    pub fn container(&self) -> &Arc<Container> {
        &self.container
    }

    /// Makes the container a fresh child of `container`.
    ///
    /// PHP: `setContainer($container)`.
    pub fn set_container(&mut self, container: Arc<Container>) -> &mut Self {
        self.container = Arc::new(Container::with_parent(container.clone()));
        self.parent = Some(container);
        self
    }

    /// Replaces the container with a fresh one (a child of the container
    /// given at construction or to [`Cli::set_container`]), dropping the
    /// resources set since.
    ///
    /// PHP: `reset()`.
    pub fn reset(&mut self) {
        self.container = Arc::new(match &self.parent {
            Some(parent) => Container::with_parent(parent.clone()),
            None => Container::new(),
        });
    }

    /// Parses `args`: the first (the program) is skipped, the second is the
    /// command, the rest are `key=value` (a leading `--` is dropped). A key
    /// without `=` has the value `null` (PHP also warns `Undefined array
    /// key 1`); a key given more than once has the
    /// list of its values. [`Error::Exception`] (`Missing command`) without a
    /// command.
    ///
    /// PHP: `parse($args)`, which also sets the command.
    ///
    /// ```
    /// use serde_json::json;
    /// use utopia_cli::Cli;
    ///
    /// let cli = Cli::new(&["app", "build", "--email=me@example.com", "--list=a", "--list=b", "--dry-run="])?;
    /// assert_eq!(cli.command(), "build");
    /// assert_eq!(cli.args()["email"], json!("me@example.com"));
    /// assert_eq!(cli.args()["list"], json!(["a", "b"]));
    /// assert_eq!(cli.args()["dry-run"], json!(""));
    /// # Ok::<(), utopia_cli::Error>(())
    /// ```
    ///
    /// ```php
    /// use Utopia\CLI\CLI;
    ///
    /// $cli = new CLI(null, ['app', 'build', '--email=me@example.com', '--list=a', '--list=b', '--dry-run=']);
    /// echo json_encode($cli->getArgs()), "\n"; // {"email":"me@example.com","list":["a","b"],"dry-run":""}
    /// ```
    pub fn parse<S: AsRef<str>>(&mut self, args: &[S]) -> Result<Args, Error> {
        let mut rest = args.iter().map(AsRef::as_ref).skip(1);
        let Some(command) = rest.next() else {
            return Err(exception("Missing command".to_owned(), 0));
        };
        command.clone_into(&mut self.command);
        let mut lists: IndexMap<String, Vec<Value>> = IndexMap::new();
        for arg in rest {
            let arg = arg.strip_prefix("--").unwrap_or(arg);
            let (key, value) = match arg.split_once('=') {
                Some((k, v)) => (k, Value::String(v.to_owned())),
                None => (arg, Value::Null),
            };
            lists.entry(php_key(key)).or_default().push(value);
        }
        Ok(lists
            .into_iter()
            .map(|(k, mut v)| if v.len() == 1 { (k, v.remove(0)) } else { (k, Value::Array(v)) })
            .collect())
    }

    /// The command this process was asked to run.
    pub fn command(&self) -> &str {
        &self.command
    }

    /// The task the command names, if one is registered.
    ///
    /// PHP: `match()`.
    pub fn matched(&self) -> Option<&Task> {
        self.tasks.get(&self.command)
    }

    /// The registered tasks, by name.
    ///
    /// PHP: `getTasks()`.
    pub fn tasks(&self) -> &IndexMap<String, Task> {
        &self.tasks
    }

    /// The parsed arguments, by key.
    ///
    /// PHP: `getArgs()`.
    pub fn args(&self) -> &Args {
        &self.args
    }

    /// The init hooks.
    pub fn init_hooks(&self) -> &[Hook] {
        &self.init
    }

    /// The shutdown hooks.
    pub fn shutdown_hooks(&self) -> &[Hook] {
        &self.shutdown
    }

    /// The error hooks.
    pub fn error_hooks(&self) -> &[Hook] {
        &self.errors
    }

    /// The adapter.
    pub fn adapter(&self) -> &Arc<dyn Adapter> {
        &self.adapter
    }

    /// Resolves a hook's arguments: each param from the arguments (by key,
    /// else by alias) or its default, validated unless it is the empty
    /// string (which an optional param accepts) and coerced to a boolean
    /// for a boolean validator; then each injected resource whose name is
    /// not a param's.
    ///
    /// PHP: `getParams($hook)` (protected).
    pub fn arguments(&self, hook: &Hook) -> Result<Arguments, Error> {
        let mut out = Arguments::new();
        for (key, param) in hook.params() {
            let given = |k: &str| self.args.get(&php_key(k)).filter(|v| !v.is_null());
            let value = match given(key).or_else(|| param.aliases.iter().find_map(|a| given(a))) {
                Some(v) => v.clone(),
                None => match &param.default {
                    ParamDefault::Value(v) => v.clone(),
                    ParamDefault::Factory(f) => f(&self.inject(&param.injections)?),
                },
            };
            let validator = || -> Arc<dyn Validator> {
                match &param.validator {
                    Check::Validator(v) => v.clone(),
                    Check::Factory(f) => f(&[]),
                }
            };
            if value.as_str() != Some("") {
                let validator = validator();
                if !validator.is_valid(&value) {
                    return Err(exception(format!("Invalid {key}: {}", validator.description()), 400));
                }
            } else if !param.optional {
                return Err(exception(format!("Param \"{key}\" is not optional."), 400));
            }
            out.push(&camel_case(key), Argument::Value(coerce(validator, value)));
        }
        for dependency in hook.dependencies() {
            let name = camel_case(dependency);
            if out.contains(&name) {
                continue;
            }
            out.push(&name, Argument::Resource(self.resource(dependency)?));
        }
        Ok(out)
    }

    fn inject(&self, names: &[String]) -> Result<Vec<utopia_servers::Resource>, Error> {
        names.iter().map(|n| self.resource(n)).collect()
    }

    async fn call(&self, hook: &Hook) -> Result<(), Error> {
        let args = self.arguments(hook)?;
        (hook.get_action())(args).await
    }

    async fn dispatch(&self) -> Result<(), Error> {
        let outcome = async {
            let Some(task) = self.matched() else {
                return Err(exception("No command found".to_owned(), 0));
            };
            for hook in &self.init {
                self.call(hook).await?;
            }
            self.call(task).await?;
            for hook in &self.shutdown {
                self.call(hook).await?;
            }
            Ok(())
        }
        .await;
        if let Err(error) = outcome {
            // PHP catches `\Exception` only: an engine error escapes run().
            if matches!(error, Error::Type(_)) {
                return Err(error);
            }
            let error = Arc::new(error);
            for hook in &self.errors {
                let error = error.clone();
                self.container.set("error", &[], move |_| Ok(error.clone()));
                self.call(hook).await?;
            }
        }
        Ok(())
    }

    /// Runs the matched task through the adapter: the init hooks, the task,
    /// then the shutdown hooks. When there is no task for the command
    /// (`No command found`) or any step fails, every error hook runs instead,
    /// with the failure as the `error` resource. Fails when an error hook
    /// does, or with an [`Error::Type`] (a task without a callback), which
    /// skips the error hooks.
    ///
    /// PHP: `run()`.
    pub async fn run(&self) -> Result<(), Error> {
        let failure: Mutex<Option<Error>> = Mutex::new(None);
        let job = |_worker: usize| -> BoxFuture<'_, ()> {
            Box::pin(async {
                if let Err(e) = self.dispatch().await {
                    *failure.lock().unwrap_or_else(|e| e.into_inner()) = Some(e);
                }
            })
        };
        self.adapter.start(&job).await;
        match failure.into_inner().unwrap_or_else(|e| e.into_inner()) {
            Some(e) => Err(e),
            None => Ok(()),
        }
    }
}

/// A PHP array key as text: what `$output[$key]` stores it under.
fn php_key(key: &str) -> String {
    key.to_owned()
}

/// `coerce()`: a non-empty string checked by a boolean validator (also
/// inside `Nullable`) becomes the boolean `filter_var()` reads in it, when
/// it reads one.
fn coerce(validator: impl Fn() -> Arc<dyn Validator>, value: Value) -> Value {
    let Some(s) = value.as_str() else { return value };
    if s.is_empty() || validator().kind() != Type::Boolean {
        return value;
    }
    use php_std::filter::{FILTER_NULL_ON_FAILURE, FILTER_VALIDATE_BOOL, Options, Value as Php, filter_var};
    match filter_var(&Php::Str(s.as_bytes().to_vec()), FILTER_VALIDATE_BOOL, &Options::Flags(FILTER_NULL_ON_FAILURE)) {
        Ok(f) => match f.value {
            Php::Bool(b) => Value::Bool(b),
            _ => value,
        },
        Err(_) => value,
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn camel() {
        assert_eq!(camel_case("project-id"), "projectId");
        assert_eq!(camel_case("foo_bar_baz"), "fooBarBaz");
        assert_eq!(camel_case("Email"), "email");
    }

    #[test]
    fn missing_command() {
        assert_eq!(Cli::new(&["app"]).unwrap_err().to_string(), "Missing command");
    }
}
