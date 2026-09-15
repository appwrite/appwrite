use crate::action::{Action, ActionType};
use crate::error::{PlatformError, Result};
use crate::module::Module;
use crate::service::{Service, ServiceType};

#[cfg(feature = "worker")]
use crate::worker::{GenericWorker, WorkerRegistrar};

/// Application container that wires modules and services into runtimes.
#[derive(Debug, Clone)]
pub struct Platform {
    core: Module,
    modules: Vec<Module>,
    #[cfg(feature = "worker")]
    worker: Option<GenericWorker>,
}

impl Platform {
    pub fn new(core: Module) -> Self {
        Self {
            core,
            modules: Vec::new(),
            #[cfg(feature = "worker")]
            worker: None,
        }
    }

    pub fn core(&self) -> &Module {
        &self.core
    }

    pub fn core_mut(&mut self) -> &mut Module {
        &mut self.core
    }

    pub fn modules(&self) -> &[Module] {
        &self.modules
    }

    pub fn add_module(mut self, module: Module) -> Self {
        self.modules.push(module);
        self
    }

    pub fn add_service(mut self, key: impl Into<String>, service: Service) -> Self {
        self.core = self.core.clone().add_service(key, service);
        self
    }

    pub fn remove_service(&mut self, key: &str) -> &mut Self {
        self.core.remove_service(key);
        self
    }

    pub fn get_service(&self, key: &str) -> Result<&Service> {
        self.core.get_service(key)
    }

    pub fn get_services(&self) -> &std::collections::HashMap<String, Service> {
        self.core.get_services()
    }

    #[cfg(feature = "worker")]
    pub fn get_worker(&self) -> Option<&GenericWorker> {
        self.worker.as_ref()
    }

    #[cfg(feature = "worker")]
    pub fn set_worker(&mut self, worker: GenericWorker) -> &mut Self {
        self.worker = Some(worker);
        self
    }

    /// Initialize services for the given runtime type.
    pub fn init(&mut self, service_type: ServiceType) -> Result<()> {
        match service_type {
            ServiceType::Http => {
                #[cfg(feature = "http")]
                {
                    Err(PlatformError::Other(
                        "call `init_http(&mut Http)` to register HTTP services".into(),
                    ))
                }
                #[cfg(not(feature = "http"))]
                {
                    Err(PlatformError::FeatureNotEnabled("http"))
                }
            }
            ServiceType::Task => {
                #[cfg(feature = "cli")]
                {
                    Err(PlatformError::Other(
                        "call `init_cli(&mut Cli)` to register CLI task services".into(),
                    ))
                }
                #[cfg(not(feature = "cli"))]
                {
                    Err(PlatformError::FeatureNotEnabled("cli"))
                }
            }
            ServiceType::GraphQL => self.init_graphql(),
            ServiceType::Worker => self.init_worker(),
        }
    }

    /// Initialize HTTP services from a string type label (`"http"`).
    pub fn init_str(&mut self, service_type: &str) -> Result<()> {
        let service_type = ServiceType::parse(service_type)
            .ok_or_else(|| PlatformError::UnsupportedInitType(service_type.to_string()))?;
        self.init(service_type)
    }

    /// Register HTTP services onto [`utopia_http::Http`].
    ///
    /// ```
    /// use utopia_di::Container;
    /// use utopia_http::{Http, MemoryAdapter, Request, Response};
    /// use utopia_platform::{Action, HttpMethod, Module, Platform, Service};
    ///
    /// # let rt = tokio::runtime::Runtime::new().unwrap();
    /// # rt.block_on(async {
    /// let hello = Action::new()
    ///     .set_http_path("/hello")
    ///     .set_http_method(HttpMethod::Get)
    ///     .http_action(|ctx| async move {
    ///         ctx.response.send("Hello World!")?;
    ///         Ok(())
    ///     });
    /// let mut platform = Platform::new(Module::new())
    ///     .add_service("helloService", Service::http().add_action("hello", hello));
    /// let mut http = Http::new(MemoryAdapter::new(Container::new()), "UTC");
    /// platform.init_http(&mut http).unwrap();
    ///
    /// let response = Response::new();
    /// http.run(Request::new("GET", "/hello"), response.clone())
    ///     .await
    ///     .unwrap();
    /// assert_eq!(response.body_string(), "Hello World!");
    /// # });
    /// ```
    #[cfg(feature = "http")]
    pub fn init_http(&mut self, http: &mut utopia_http::Http) -> Result<()> {
        use crate::http::UtopiaHttpRegistrar;

        let mut registrar = UtopiaHttpRegistrar::new(http);
        self.register_http_actions(&mut registrar)?;
        Ok(())
    }

    #[cfg(not(feature = "http"))]
    pub fn init_http(&mut self) -> Result<()> {
        Err(PlatformError::FeatureNotEnabled("http"))
    }

    #[cfg(feature = "http")]
    fn register_http_actions<R: crate::http::HttpRegistrar>(
        &self,
        registrar: &mut R,
    ) -> Result<()> {
        for module in std::iter::once(&self.core).chain(self.modules.iter()) {
            for service in module.get_services_by_type(ServiceType::Http).values() {
                for action in service.get_actions().values() {
                    registrar.register_action(action)?;
                }
            }
        }
        Ok(())
    }

    /// Register CLI task services onto [`utopia_cli::Cli`].
    ///
    /// ```
    /// use serde_json::Value;
    /// use utopia_cli::Cli;
    /// use utopia_platform::{Action, Module, Platform, Service};
    /// use utopia_validators::{ArrayList, Text};
    ///
    /// let build = Action::new()
    ///     .param("email", Value::Null, Text::new(0), "Email address", false)
    ///     .param(
    ///         "list",
    ///         Value::Null,
    ///         ArrayList::new(Text::new(256)),
    ///         "List of strings",
    ///         false,
    ///     )
    ///     .cli_action(|params| {
    ///         let email = params.get_str("email").unwrap_or("");
    ///         let list = params.get_list("list").unwrap_or_default();
    ///         println!("{}-{}", email, list.join("-"));
    ///         Value::Null
    ///     });
    /// let mut platform = Platform::new(Module::new()).add_service(
    ///     "cli",
    ///     Service::task()
    ///         .add_action("build", build.clone())
    ///         .add_action("build2", build),
    /// );
    /// let mut cli = Cli::with_args(vec![
    ///     "app".into(),
    ///     "build".into(),
    ///     "--email=me@example.com".into(),
    ///     "--list=item1".into(),
    ///     "--list=item2".into(),
    /// ])
    /// .unwrap();
    /// platform.init_cli(&mut cli).unwrap();
    /// cli.run();
    /// ```
    #[cfg(feature = "cli")]
    pub fn init_cli(&mut self, cli: &mut utopia_cli::Cli) -> Result<()> {
        use crate::cli::UtopiaCliRegistrar;

        let mut registrar = UtopiaCliRegistrar::new(cli);
        self.register_cli_actions(&mut registrar)?;
        Ok(())
    }

    #[cfg(not(feature = "cli"))]
    pub fn init_cli(&mut self) -> Result<()> {
        Err(PlatformError::FeatureNotEnabled("cli"))
    }

    #[cfg(feature = "cli")]
    fn register_cli_actions<R: crate::cli::CliRegistrar>(&self, registrar: &mut R) -> Result<()> {
        for module in std::iter::once(&self.core).chain(self.modules.iter()) {
            for service in module.get_services_by_type(ServiceType::Task).values() {
                for (action_key, action) in service.get_actions() {
                    registrar.register_action(action_key, action)?;
                }
            }
        }
        Ok(())
    }

    pub fn init_graphql(&mut self) -> Result<()> {
        Ok(())
    }

    pub fn init_worker(&mut self) -> Result<()> {
        self.init_worker_with_name(None)
    }

    pub fn init_worker_with_name(&mut self, worker_name: Option<&str>) -> Result<()> {
        #[cfg(feature = "worker")]
        {
            if self.worker.is_none() {
                self.worker = Some(GenericWorker::new());
            }
            let worker = self.worker.as_mut().expect("worker initialized above");
            for module in std::iter::once(&self.core).chain(self.modules.iter()) {
                for service in module.get_services_by_type(ServiceType::Worker).values() {
                    for (action_key, action) in service.get_actions() {
                        worker.register_action(action_key, action, worker_name)?;
                    }
                }
            }
            Ok(())
        }
        #[cfg(not(feature = "worker"))]
        {
            let _ = worker_name;
            Err(PlatformError::FeatureNotEnabled("worker"))
        }
    }

    /// Register a single action on a platform (used by benchmarks).
    pub fn register_action(
        &mut self,
        service_key: &str,
        action_key: &str,
        action: Action,
    ) -> Result<()> {
        let service = self.get_service(service_key)?;
        let service_type = service.service_type();
        let mut updated = service.clone();
        updated = updated.add_action(action_key, action);
        self.core = self.core.clone();
        self.core.remove_service(service_key);
        self.core = self.core.clone().add_service(service_key, updated);
        let _ = service_type;
        Ok(())
    }
}

/// Convenience helper for HTTP action types.
pub fn is_hook_action(action_type: ActionType) -> bool {
    matches!(
        action_type,
        ActionType::Init
            | ActionType::Error
            | ActionType::Options
            | ActionType::Shutdown
            | ActionType::WorkerStart
            | ActionType::WorkerStop
    )
}
