//! Describe an application once, as modules of services of actions, and
//! register it on its servers: HTTP routes and hooks on `utopia-http`, CLI
//! tasks and hooks on `utopia-cli`, queue jobs and worker hooks on
//! `utopia-queue`.
//!
//! An [`Action`] is one endpoint, task or job: its name (its key in a
//! [`Service`]), HTTP methods, path and aliases, params with validators,
//! injections, labels and [`Callback`]. A [`Service`] holds the actions of
//! one server type; a [`Module`] holds services; a [`Platform`] holds
//! modules and, on [`Platform::init`], registers every service of one type.
//!
//! PHP: ports `utopia-php/platform`, the layer Appwrite's modules are built
//! on (`src/Appwrite/Platform`).
//!
//! | PHP | Rust |
//! |---|---|
//! | `Utopia\Platform\Action` (with `Scope\HTTP`) | [`Action`] |
//! | `Action::TYPE_*`, `Action::HTTP_REQUEST_METHOD_*` | [`ActionType`], [`HTTP_REQUEST_METHOD_GET`], ... |
//! | `Action::param()` arguments, `Utopia\Platform\Enum` | [`Param`], [`Enum`] |
//! | `Action::getOptions()` | [`Action::options`] ([`Declaration`]) |
//! | `Scope\HTTP`: `setHttpPath()`, `getHttpPath()`, `setHttpMethod(s)()`, `getHttpMethod(s)()`, `httpAlias()`, `getHttpAliases()` | [`Action::set_http_path`], [`Action::try_http_path`], [`Action::set_http_method`], [`Action::set_http_methods`], [`Action::http_methods`], [`Action::http_alias`], [`Action::http_aliases`] |
//! | `Action::callback()` | [`Action::callback`] with a [`Callback`] |
//! | `Utopia\Platform\Service`, `Service::TYPE_*` | [`Service`], [`ServiceType`] |
//! | `Utopia\Platform\Module` | [`Module`] |
//! | `Utopia\Platform\Platform` | [`Platform`] |
//! | `Platform::init($type, $params)` | [`Platform::init`] with [`Init`] ([`WorkerParams`], [`JobConfig`]) |
//! | `\Exception`, `\TypeError` | [`Error`] |
//!
//! PHP: an action's callback is one closure every server calls with the
//! action's params and injections as arguments. Here a callback is written
//! for the server it runs on ([`Callback::Http`], [`Callback::Task`],
//! [`Callback::Worker`]) and receives that server's scope; registering an
//! action without a callback for its server fails, as PHP's `action(null)`
//! does.
//!
//! ```
//! use serde_json::json;
//! use utopia_cli::Cli;
//! use utopia_platform::{Action, Callback, Init, Module, Param, Platform, Service, ServiceType};
//! use utopia_validators::Text;
//!
//! let mut build = Action::new();
//! build
//!     .desc("Build the project")
//!     .param("email", Param::new(json!(null), Text::new(0), "Valid email address"))
//!     .callback(Callback::Task(std::sync::Arc::new(|_args| Box::pin(async { Ok(()) }))));
//! let mut tasks = Service::new(ServiceType::TASK);
//! tasks.add_action("build", build);
//!
//! let mut platform = Platform::new(Module::new());
//! platform.add_service("tasks", tasks);
//! platform.set_cli(Cli::new(&["app", "build", "--email=me@example.com"])?);
//! platform.init(Init::Task)?;
//!
//! let cli = platform.cli().unwrap();
//! assert_eq!(cli.matched().map(|t| t.description()), Some("Build the project"));
//! # Ok::<(), Box<dyn std::error::Error>>(())
//! ```
//!
//! ```php
//! use Utopia\CLI\Adapters\Generic;
//! use Utopia\CLI\CLI;
//! use Utopia\Platform\Action;
//! use Utopia\Platform\Module;
//! use Utopia\Platform\Platform;
//! use Utopia\Platform\Service;
//! use Utopia\Validator\Text;
//!
//! $build = new class () extends Action {
//!     public function __construct()
//!     {
//!         $this->desc('Build the project')
//!             ->param('email', null, new Text(0), 'Valid email address')
//!             ->callback(fn (string $email) => null);
//!     }
//! };
//! $tasks = new class ($build) extends Service {
//!     public function __construct(Action $build) { $this->type = Service::TYPE_TASK; $this->addAction('build', $build); }
//! };
//! $platform = new class (new class () extends Module {}) extends Platform {};
//! $platform->addService('tasks', $tasks);
//! $platform->setCli(new CLI(new Generic(), ['app', 'build', '--email=me@example.com']));
//! $platform->init(Service::TYPE_TASK);
//!
//! echo $platform->getCli()->match()->getDesc(), "\n"; // Build the project
//! ```

#![warn(missing_docs)]

mod action;
mod platform;
mod service;

pub use action::{
    Action, ActionType, Callback, Declaration, Enum, HTTP_REQUEST_METHOD_DELETE, HTTP_REQUEST_METHOD_GET,
    HTTP_REQUEST_METHOD_HEAD, HTTP_REQUEST_METHOD_OPTIONS, HTTP_REQUEST_METHOD_PATCH, HTTP_REQUEST_METHOD_POST,
    HTTP_REQUEST_METHOD_PUT, Param,
};
pub use platform::{Init, JobConfig, Platform, WorkerParams};
pub use service::{Module, Service, ServiceType};

/// The getting started guide (`guide.md`), compiled and run with the doctests.
#[cfg(doctest)]
#[doc = include_str!("../guide.md")]
pub struct GettingStarted;

/// Why an action could not be declared or registered.
///
/// PHP: the exceptions `utopia-php/platform` throws, and those of the
/// servers it registers on.
#[derive(Debug, thiserror::Error)]
pub enum Error {
    /// A missing service, an injection declared twice, an unknown init type,
    /// or workers without a consumer.
    ///
    /// PHP: `\Exception`.
    #[error("{0}")]
    Exception(String),
    /// A route without a path, or an action without a callback for the
    /// server it registers on.
    ///
    /// PHP: `\TypeError` from `getHttpPath()`, or from `Hook::action(null)`
    /// (whose message also names the calling file and line).
    #[error("{0}")]
    Type(String),
    /// The HTTP application refused a route (a path taken twice).
    #[error(transparent)]
    Http(#[from] utopia_http::Error),
    /// The worker server refused a job (no queue name, prefetch below
    /// coroutines).
    #[error(transparent)]
    Queue(#[from] utopia_queue::Error),
    /// The CLI refused a hook (an injection declared twice).
    #[error(transparent)]
    Servers(#[from] utopia_servers::Error),
}

impl Error {
    /// The name of the PHP exception class this error matches.
    pub fn php_class(&self) -> &str {
        match self {
            Error::Exception(_) => "Exception",
            Error::Type(_) => "TypeError",
            Error::Http(e) => e.php_class(),
            Error::Queue(e) => e.php_class(),
            Error::Servers(e) => e.php_class(),
        }
    }
}
