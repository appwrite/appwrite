//! The platform: modules registered on the HTTP, CLI and worker servers.

use std::sync::Arc;

use indexmap::IndexMap;
use utopia_cli::Cli;
use utopia_http::Http;
use utopia_queue::Server;
use utopia_queue::adapter::{Adapter as QueueAdapter, ConsumerFactory, Runtime};
use utopia_servers::ParamDefault;

use crate::action::{Callback, Declaration};
use crate::{Action, ActionType, Error, Module, Service, ServiceType};

/// How one worker queue is consumed.
///
/// PHP: an entry of the `jobs` init param.
#[derive(Debug, Clone, Default, PartialEq, Eq)]
pub struct JobConfig {
    /// The queue name (default: the `queue_name` param, else `v1-<name>`).
    pub queue: Option<String>,
    /// The messages handled at once (at least 1, default 1).
    pub coroutines: Option<i64>,
    /// The unacknowledged messages allowed (default: `coroutines`).
    pub prefetch: Option<usize>,
}

/// What [`Platform::init`] needs for workers.
///
/// PHP: the `$params` of `init(Service::TYPE_WORKER, $params)`.
#[derive(Clone, Default)]
pub struct WorkerParams {
    /// The worker to register (`workerName`), when `workers` is empty.
    pub worker_name: Option<String>,
    /// The workers to register (`workers`); `all` (or none) registers every
    /// one. Compared in lower case.
    pub workers: Vec<String>,
    /// Queue settings by worker name (`jobs`).
    pub jobs: IndexMap<String, JobConfig>,
    /// The queue of workers without a configured one (`queueName`).
    pub queue_name: Option<String>,
    /// Makes a consumer per queue (`consumerFactory` or `consumer`); needed
    /// unless a worker server is set.
    pub consumer: Option<ConsumerFactory>,
    /// Worker processes of the server created (`workersNum`, default 0).
    pub workers_num: usize,
    /// The queues' key prefix (`namespace`, default `utopia-queue`).
    pub namespace: Option<String>,
}

impl std::fmt::Debug for WorkerParams {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("WorkerParams")
            .field("worker_name", &self.worker_name)
            .field("workers", &self.workers)
            .field("jobs", &self.jobs)
            .field("queue_name", &self.queue_name)
            .field("consumer", &self.consumer.is_some())
            .field("workers_num", &self.workers_num)
            .field("namespace", &self.namespace)
            .finish()
    }
}

/// What to initialize: the server type and its settings.
///
/// PHP: the `$type` and `$params` of `init()`.
#[derive(Debug, Clone)]
pub enum Init {
    /// HTTP routes and hooks, on the platform's [`Http`] application.
    Http,
    /// CLI tasks and hooks, on the platform's [`Cli`], built from the
    /// process's arguments when unset.
    ///
    /// PHP: the `adapter` param picks the adapter of that CLI; here set a
    /// [`Cli::with_adapter`] with [`Platform::set_cli`] first.
    Task,
    /// GraphQL: nothing to register yet.
    GraphQL,
    /// Queue jobs and worker hooks on the platform's worker server, created
    /// from the params when unset.
    Worker(WorkerParams),
    /// Any other type: refused.
    Other(ServiceType),
}

impl Init {
    fn kind(&self) -> ServiceType {
        match self {
            Init::Http => ServiceType::HTTP,
            Init::Task => ServiceType::TASK,
            Init::GraphQL => ServiceType::GRAPHQL,
            Init::Worker(_) => ServiceType::WORKER,
            Init::Other(kind) => kind.clone(),
        }
    }
}

/// An application made of modules: [`Platform::init`] registers the actions
/// of every module's services of one type on that type's server.
///
/// The core module (given to [`Platform::new`]) comes first; services added
/// to the platform go to it.
///
/// PHP: `Utopia\Platform\Platform`. Its HTTP application is the static
/// `Utopia\Http\Http`; here the platform holds an [`Http`] value.
///
/// ```
/// # futures_util::FutureExt::now_or_never(async {
/// use utopia_http::{Http, Request, Response};
/// use utopia_platform::{Action, Callback, Init, Module, Platform, Service, ServiceType};
///
/// let mut root = Action::new();
/// root.set_http_method("GET").set_http_path("/").inject("response").callback(Callback::http(async |scope| {
///     scope.response.send(b"Hello World!");
/// }));
/// let mut service = Service::new(ServiceType::HTTP);
/// service.add_action("root", root);
///
/// let mut platform = Platform::new(Module::new());
/// platform.add_service("web", service);
/// platform.init(Init::Http)?;
///
/// let http = platform.http();
/// let request = Request::new("GET", "/", std::iter::empty::<(&str, &str)>(), utopia_http::Bytes::new());
/// let mut response = Response::new();
/// http.run(&request, &mut response, &http.context()).await?;
/// assert_eq!(response.wire().body, b"Hello World!");
/// # Ok::<(), Box<dyn std::error::Error>>(())
/// # }).unwrap()?;
/// # Ok::<(), Box<dyn std::error::Error>>(())
/// ```
///
/// ```php
/// use Utopia\Platform\Action;
/// use Utopia\Platform\Module;
/// use Utopia\Platform\Platform;
/// use Utopia\Platform\Service;
///
/// $root = new class () extends Action {
///     public function __construct()
///     {
///         $this->setHttpMethod('GET')->setHttpPath('/')->inject('response')
///             ->callback(fn ($response) => $response->send('Hello World!'));
///     }
/// };
/// $service = new class ($root) extends Service {
///     public function __construct(Action $root) { $this->type = Service::TYPE_HTTP; $this->addAction('root', $root); }
/// };
/// $platform = new class (new class () extends Module {}) extends Platform {};
/// $platform->addService('web', $service);
/// $platform->init(Service::TYPE_HTTP); // registers GET / on Utopia\Http\Http
/// ```
pub struct Platform {
    modules: Vec<Module>,
    http: Option<Http>,
    cli: Option<Cli>,
    worker: Option<Server>,
}

impl std::fmt::Debug for Platform {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Platform")
            .field("modules", &self.modules)
            .field("cli", &self.cli)
            .field("worker", &self.worker.is_some())
            .finish()
    }
}

impl Platform {
    /// A platform whose core module is `core`.
    ///
    /// PHP: `new Platform($core)`.
    pub fn new(core: Module) -> Self {
        Self { modules: vec![core], http: None, cli: None, worker: None }
    }

    /// Adds a module after the others.
    ///
    /// PHP: `addModule($module)`.
    pub fn add_module(&mut self, module: Module) -> &mut Self {
        self.modules.push(module);
        self
    }

    /// The modules, the core one first.
    pub fn modules(&self) -> &[Module] {
        &self.modules
    }

    fn core(&mut self) -> &mut Module {
        &mut self.modules[0]
    }

    /// Adds a service to the core module.
    ///
    /// PHP: `addService($key, $service)`.
    pub fn add_service(&mut self, key: &str, service: Service) -> &mut Self {
        self.core().add_service(key, service);
        self
    }

    /// Removes a service from the core module.
    ///
    /// PHP: `removeService($key)`.
    pub fn remove_service(&mut self, key: &str) -> &mut Self {
        self.core().remove_service(key);
        self
    }

    /// A service of the core module.
    ///
    /// PHP: `getService($key)`.
    pub fn service(&self, key: &str) -> Result<&Service, Error> {
        self.modules[0].service(key)
    }

    /// The core module's services.
    ///
    /// PHP: `getServices()`.
    pub fn services(&self) -> &IndexMap<String, Arc<Service>> {
        self.modules[0].services()
    }

    /// The HTTP application routes are registered on (created in `UTC` on
    /// first use).
    pub fn http(&mut self) -> &mut Http {
        self.http.get_or_insert_with(Http::default)
    }

    /// Sets the HTTP application.
    pub fn set_http(&mut self, http: Http) -> &mut Self {
        self.http = Some(http);
        self
    }

    /// Takes the HTTP application, to serve it.
    pub fn take_http(&mut self) -> Option<Http> {
        self.http.take()
    }

    /// The CLI application, once set.
    ///
    /// PHP: `getCli()`.
    pub fn cli(&self) -> Option<&Cli> {
        self.cli.as_ref()
    }

    /// The CLI application, mutably.
    pub fn cli_mut(&mut self) -> Option<&mut Cli> {
        self.cli.as_mut()
    }

    /// Sets the CLI application.
    ///
    /// PHP: `setCli($cli)`.
    pub fn set_cli(&mut self, cli: Cli) -> &mut Self {
        self.cli = Some(cli);
        self
    }

    /// The worker server, once set or created.
    ///
    /// PHP: `getWorker()`.
    pub fn worker(&self) -> Option<&Server> {
        self.worker.as_ref()
    }

    /// The worker server, mutably.
    pub fn worker_mut(&mut self) -> Option<&mut Server> {
        self.worker.as_mut()
    }

    /// Sets the worker server.
    ///
    /// PHP: `setWorker($worker)`.
    pub fn set_worker(&mut self, worker: Server) -> &mut Self {
        self.worker = Some(worker);
        self
    }

    /// Registers the actions of every module's services of the type on its
    /// server, module by module, service by service, in order.
    ///
    /// Each action becomes, by its type: an init, shutdown, error or options
    /// hook (HTTP), an init, shutdown or error hook (CLI), an init, shutdown,
    /// error, worker-start or worker-stop hook (workers); or else a route
    /// for its methods and path with its aliases (HTTP), a task named by its
    /// key (CLI), or a job for its queue (workers). The hook gets the
    /// action's groups, description, params and injections in declaration
    /// order, labels and callback.
    ///
    /// Fails with [`Error::Exception`] for an unknown type
    /// (`Please provide which type of initialization you want to carry
    /// out.`) and for workers without a server or consumer (`Worker init
    /// requires consumerFactory or consumer`); with [`Error::Type`] for a
    /// route without a path or an action without a callback for the server
    /// (after its hook is registered); and with the server's error when it
    /// refuses a registration. Tasks without a CLI get one built from the
    /// process's arguments ([`Cli::from_env`]), which fails without a
    /// command.
    ///
    /// PHP: `init($type, $params)`.
    pub fn init(&mut self, init: Init) -> Result<(), Error> {
        let kind = init.kind();
        let modules = std::mem::take(&mut self.modules);
        let result = (|| {
            for module in &modules {
                let services = module.services_by_type(&kind);
                match &init {
                    Init::Http => self.init_http(&services)?,
                    Init::Task => self.init_tasks(&services)?,
                    Init::GraphQL => {}
                    Init::Worker(params) => {
                        if self.worker.is_none() {
                            let factory = params.consumer.clone().ok_or_else(|| {
                                Error::Exception("Worker init requires consumerFactory or consumer".into())
                            })?;
                            let adapter = QueueAdapter::with_factory(factory, params.workers_num, Runtime::Workers)
                                .with_namespace(params.namespace.clone().unwrap_or_else(|| "utopia-queue".into()));
                            self.worker = Some(Server::new(adapter));
                        }
                        self.init_worker(&services, params)?;
                    }
                    Init::Other(_) => {
                        return Err(Error::Exception(
                            "Please provide which type of initialization you want to carry out.".into(),
                        ));
                    }
                }
            }
            Ok(())
        })();
        self.modules = modules;
        result
    }

    fn init_http(&mut self, services: &[(&str, &Service)]) -> Result<(), Error> {
        let http = self.http.get_or_insert_with(Http::default);
        for (_, service) in services {
            for action in service.actions().values() {
                let kind = action.kind();
                if *kind == ActionType::INIT {
                    http_hook(http.init(), action)?;
                } else if *kind == ActionType::ERROR {
                    http_hook(http.error(), action)?;
                } else if *kind == ActionType::OPTIONS {
                    http_hook(http.options(), action)?;
                } else if *kind == ActionType::SHUTDOWN {
                    http_hook(http.shutdown(), action)?;
                } else {
                    let path = action.try_http_path()?;
                    let methods: Vec<&str> = action.http_methods().iter().map(String::as_str).collect();
                    let mut builder = http.routes(&methods, path)?;
                    // As in PHP, groups and description are set before the
                    // aliases, which may fail.
                    let groups: Vec<&str> = action.get_groups().iter().map(String::as_str).collect();
                    builder.get().groups(&groups).desc(action.description().unwrap_or(""));
                    for alias in action.http_aliases() {
                        builder.alias(alias)?;
                    }
                    http_hook(builder.get(), action)?;
                }
            }
        }
        Ok(())
    }

    fn init_tasks(&mut self, services: &[(&str, &Service)]) -> Result<(), Error> {
        if self.cli.is_none() {
            self.cli = Some(Cli::from_env()?);
        }
        let cli = self.cli.as_mut().unwrap_or_else(|| unreachable!());
        for (_, service) in services {
            for (key, action) in service.actions() {
                let kind = action.kind();
                let hook: &mut utopia_servers::Hook = if *kind == ActionType::INIT {
                    cli.init()
                } else if *kind == ActionType::ERROR {
                    cli.error()
                } else if *kind == ActionType::SHUTDOWN {
                    cli.shutdown()
                } else {
                    cli.task(key)
                };
                server_hook(hook, action)?;
            }
        }
        Ok(())
    }

    fn init_worker(&mut self, services: &[(&str, &Service)], params: &WorkerParams) -> Result<(), Error> {
        let worker = self.worker.as_mut().ok_or_else(|| Error::Exception("worker".into()))?;
        if let Some(factory) = &params.consumer {
            worker.consumer(factory.clone());
        }
        let mut names: Vec<String> = params.workers.clone();
        if names.is_empty()
            && let Some(name) = params.worker_name.as_deref().filter(|n| !n.is_empty())
        {
            names.push(name.to_owned());
        }
        let names: Vec<String> = names.iter().map(|n| n.to_ascii_lowercase()).collect();
        let all = names.is_empty() || names.iter().any(|n| n == "all");
        for (_, service) in services {
            for (key, action) in service.actions() {
                let kind = action.kind();
                let name = key.to_ascii_lowercase();
                if *kind == ActionType::DEFAULT && !all && !names.contains(&name) {
                    continue;
                }
                let hook: &mut utopia_queue::Hook = if *kind == ActionType::INIT {
                    worker.init()
                } else if *kind == ActionType::ERROR {
                    worker.error()
                } else if *kind == ActionType::SHUTDOWN {
                    worker.shutdown()
                } else if *kind == ActionType::WORKER_START {
                    worker.worker_start()
                } else if *kind == ActionType::WORKER_STOP {
                    worker.worker_stop()
                } else {
                    let config = params.jobs.get(&name).cloned().unwrap_or_default();
                    let queue =
                        config.queue.or_else(|| params.queue_name.clone()).unwrap_or_else(|| format!("v1-{name}"));
                    let coroutines = usize::try_from(config.coroutines.unwrap_or(1).max(1)).unwrap_or(1);
                    worker.job(&queue, coroutines, config.prefetch)?
                };
                let groups: Vec<&str> = action.get_groups().iter().map(String::as_str).collect();
                hook.groups(&groups).desc(action.description().unwrap_or(""));
                for declaration in action.options().values() {
                    match declaration {
                        Declaration::Param(key) => {
                            let p = &action.params()[key];
                            hook.param_with(
                                key,
                                utopia_queue::hook::Param {
                                    default: match &p.default {
                                        ParamDefault::Value(v) => php_std::Value::from_json(v),
                                        ParamDefault::Factory(_) => php_std::Value::Null,
                                    },
                                    validator: match &p.validator {
                                        utopia_servers::Check::Validator(v) => {
                                            utopia_queue::hook::Check::Validator(v.clone())
                                        }
                                        utopia_servers::Check::Factory(f) => {
                                            utopia_queue::hook::Check::Factory(p.injections.clone(), f.clone())
                                        }
                                    },
                                    description: p.description.clone(),
                                    optional: p.optional,
                                    aliases: p.aliases.clone(),
                                },
                            );
                        }
                        Declaration::Injection(name) => {
                            hook.inject(name);
                        }
                    }
                }
                for (key, value) in action.labels() {
                    hook.label(key, value.clone());
                }
                match action.get_callback() {
                    Some(Callback::Worker(callback)) => {
                        let callback = callback.clone();
                        hook.action(move |scope| callback(scope));
                    }
                    _ => return Err(no_callback()),
                }
            }
        }
        Ok(())
    }
}

/// What registering an action without a callback for the server fails with.
fn no_callback() -> Error {
    Error::Type("Utopia\\Servers\\Hook::action(): Argument #1 ($action) must be of type callable, null given".into())
}

/// Configures an HTTP hook or route from an action.
fn http_hook(hook: &mut utopia_http::Hook, action: &Action) -> Result<(), Error> {
    let groups: Vec<&str> = action.get_groups().iter().map(String::as_str).collect();
    hook.groups(&groups).desc(action.description().unwrap_or(""));
    for declaration in action.options().values() {
        match declaration {
            Declaration::Param(key) => {
                let p = &action.params()[key];
                let mut param = utopia_http::Param::new(
                    match &p.default {
                        ParamDefault::Value(v) => utopia_http::ParamDefault::Value(v.clone()),
                        ParamDefault::Factory(f) => utopia_http::ParamDefault::Factory(f.clone()),
                    },
                    match &p.validator {
                        utopia_servers::Check::Validator(v) => utopia_http::Check::Validator(v.clone()),
                        utopia_servers::Check::Factory(f) => utopia_http::Check::Factory(f.clone()),
                    },
                    &p.description,
                )
                .optional(p.optional)
                .skip_validation(p.skip_validation);
                param.injections = p.injections.clone();
                param.deprecated = p.deprecated;
                param.example = p.example.clone();
                param.aliases = p.aliases.clone();
                param.enumeration = p.enumeration.clone().map(|e| e as utopia_http::Resource);
                hook.param(key, param);
            }
            Declaration::Injection(name) => {
                hook.inject(name);
            }
        }
    }
    for (key, value) in action.labels() {
        hook.label(key, value.clone());
    }
    match action.get_callback() {
        Some(Callback::Http(callback)) => {
            hook.set_action(callback.clone());
            Ok(())
        }
        _ => Err(no_callback()),
    }
}

/// Configures a servers hook (a CLI task or hook) from an action.
fn server_hook(hook: &mut utopia_servers::Hook, action: &Action) -> Result<(), Error> {
    let groups: Vec<&str> = action.get_groups().iter().map(String::as_str).collect();
    hook.groups(&groups).desc(action.description().unwrap_or(""));
    for declaration in action.options().values() {
        match declaration {
            Declaration::Param(key) => {
                let p = &action.params()[key];
                let injections: Vec<&str> = p.injections.iter().map(String::as_str).collect();
                let aliases: Vec<&str> = p.aliases.iter().map(String::as_str).collect();
                hook.param(
                    key,
                    utopia_servers::Param::new(p.default.clone(), p.validator.clone(), &p.description)
                        .optional(p.optional)
                        .injections(&injections)
                        .skip_validation(p.skip_validation)
                        .deprecated(p.deprecated)
                        .example(&p.example)
                        .aliases(&aliases)
                        .enumeration(p.enumeration.clone().map(|e| e as utopia_servers::Resource)),
                );
            }
            Declaration::Injection(name) => {
                hook.try_inject(name)?;
            }
        }
    }
    for (key, value) in action.labels() {
        hook.label(key, value.clone());
    }
    match action.get_callback() {
        Some(Callback::Task(callback)) => {
            hook.set_action(callback.clone());
            Ok(())
        }
        _ => Err(no_callback()),
    }
}
