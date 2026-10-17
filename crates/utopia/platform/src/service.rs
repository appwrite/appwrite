//! Services group the actions of one server type; modules group services.

use std::borrow::Cow;
use std::sync::Arc;

use indexmap::IndexMap;

use crate::{Action, Error};

/// The server a service's actions register on. Any other name is kept, and
/// [`crate::Platform::init`] refuses it.
///
/// PHP: the `Service::TYPE_*` strings.
#[derive(Debug, Clone, PartialEq, Eq, Hash)]
pub struct ServiceType(Cow<'static, str>);

impl ServiceType {
    /// HTTP routes and hooks.
    ///
    /// PHP: `Service::TYPE_HTTP` (`http`).
    pub const HTTP: Self = Self(Cow::Borrowed("http"));
    /// GraphQL (nothing is registered yet).
    ///
    /// PHP: `Service::TYPE_GRAPHQL` (`GraphQL`).
    pub const GRAPHQL: Self = Self(Cow::Borrowed("GraphQL"));
    /// CLI tasks and hooks.
    ///
    /// PHP: `Service::TYPE_TASK` (`Task`).
    pub const TASK: Self = Self(Cow::Borrowed("Task"));
    /// Queue jobs and worker hooks.
    ///
    /// PHP: `Service::TYPE_WORKER` (`Worker`).
    pub const WORKER: Self = Self(Cow::Borrowed("Worker"));

    /// A type by name.
    pub fn new(name: impl Into<Cow<'static, str>>) -> Self {
        Self(name.into())
    }

    /// The type's name.
    pub fn as_str(&self) -> &str {
        &self.0
    }
}

/// The actions of one server type, by key (the CLI task name, the worker
/// name).
///
/// PHP: `Utopia\Platform\Service`.
///
/// ```
/// use utopia_platform::{Action, Service, ServiceType};
///
/// let mut service = Service::new(ServiceType::TASK);
/// service.add_action("build", Action::new()).add_action("clean", Action::new());
/// service.remove_action("clean");
/// assert_eq!(service.actions().keys().collect::<Vec<_>>(), ["build"]);
/// assert!(service.action("clean").is_none());
/// ```
///
/// ```php
/// use Utopia\Platform\Action;
/// use Utopia\Platform\Service;
///
/// $service = new class () extends Service {
///     public function __construct()
///     {
///         $this->type = Service::TYPE_TASK;
///         $this->addAction('build', new class () extends Action {});
///         $this->addAction('clean', new class () extends Action {});
///     }
/// };
/// $service->removeAction('clean');
/// echo implode(',', array_keys($service->getActions())), "\n"; // build
/// var_dump($service->getAction('clean'));                       // NULL
/// ```
#[derive(Clone, Debug)]
pub struct Service {
    kind: ServiceType,
    actions: IndexMap<String, Action>,
}

impl Service {
    /// A service of `kind` without actions.
    pub fn new(kind: ServiceType) -> Self {
        Self { kind, actions: IndexMap::new() }
    }

    /// Sets the type.
    ///
    /// PHP: `setType($type)`.
    pub fn set_type(&mut self, kind: ServiceType) -> &mut Self {
        self.kind = kind;
        self
    }

    /// The type.
    ///
    /// PHP: `getType()`.
    pub fn kind(&self) -> &ServiceType {
        &self.kind
    }

    /// Adds `action` under `key`, replacing one of that key in place.
    ///
    /// PHP: `addAction($key, $action)`.
    pub fn add_action(&mut self, key: &str, action: Action) -> &mut Self {
        self.actions.insert(key.to_owned(), action);
        self
    }

    /// Removes the action under `key`.
    ///
    /// PHP: `removeAction($key)`.
    pub fn remove_action(&mut self, key: &str) -> &mut Self {
        self.actions.shift_remove(key);
        self
    }

    /// The action under `key`.
    ///
    /// PHP: `getAction($key)`.
    pub fn action(&self, key: &str) -> Option<&Action> {
        self.actions.get(key)
    }

    /// The action under `key`, mutably.
    pub fn action_mut(&mut self, key: &str) -> Option<&mut Action> {
        self.actions.get_mut(key)
    }

    /// The actions by key, in order.
    ///
    /// PHP: `getActions()`.
    pub fn actions(&self) -> &IndexMap<String, Action> {
        &self.actions
    }
}

/// Services by key, also listed by type.
///
/// A service added again under the same key with another type stays listed
/// under its first type too, as in PHP.
///
/// PHP: `Utopia\Platform\Module`.
///
/// ```
/// use utopia_platform::{Module, Service, ServiceType};
///
/// let mut module = Module::new();
/// module.add_service("api", Service::new(ServiceType::HTTP)).add_service("tasks", Service::new(ServiceType::TASK));
/// assert_eq!(module.services_by_type(&ServiceType::HTTP).len(), 1);
/// assert_eq!(module.service("missing").unwrap_err().to_string(), "Service missing not found");
/// ```
///
/// ```php
/// use Utopia\Platform\Module;
/// use Utopia\Platform\Service;
///
/// $service = fn (string $type) => new class ($type) extends Service { public function __construct(string $type) { $this->type = $type; $this->actions = []; } };
/// $module = new class () extends Module {};
/// $module->addService('api', $service(Service::TYPE_HTTP))->addService('tasks', $service(Service::TYPE_TASK));
/// echo count($module->getServicesByType(Service::TYPE_HTTP)), "\n"; // 1
/// try {
///     $module->getService('missing');
/// } catch (\Exception $e) {
///     echo $e->getMessage(), "\n"; // Service missing not found
/// }
/// ```
#[derive(Clone, Debug, Default)]
pub struct Module {
    all: IndexMap<String, Arc<Service>>,
    by_type: IndexMap<ServiceType, IndexMap<String, Arc<Service>>>,
}

impl Module {
    /// A module without services.
    pub fn new() -> Self {
        let mut by_type = IndexMap::new();
        for kind in [ServiceType::TASK, ServiceType::HTTP, ServiceType::GRAPHQL, ServiceType::WORKER] {
            by_type.insert(kind, IndexMap::new());
        }
        Self { all: IndexMap::new(), by_type }
    }

    /// Adds `service` under `key`.
    ///
    /// PHP: `addService($key, $service)`.
    pub fn add_service(&mut self, key: &str, service: Service) -> &mut Self {
        let service = Arc::new(service);
        self.by_type.entry(service.kind().clone()).or_default().insert(key.to_owned(), service.clone());
        self.all.insert(key.to_owned(), service);
        self
    }

    /// Removes the service under `key`, from its type's list too.
    ///
    /// PHP: `removeService($key)`.
    pub fn remove_service(&mut self, key: &str) -> &mut Self {
        if let Some(service) = self.all.shift_remove(key)
            && let Some(list) = self.by_type.get_mut(service.kind())
        {
            list.shift_remove(key);
        }
        self
    }

    /// The service under `key`; [`Error::Exception`] (`Service <key> not
    /// found`) when there is none.
    ///
    /// PHP: `getService($key)`.
    pub fn service(&self, key: &str) -> Result<&Service, Error> {
        self.all.get(key).map(|s| &**s).ok_or_else(|| Error::Exception(format!("Service {key} not found")))
    }

    /// Every service by key.
    ///
    /// PHP: `getServices()`.
    pub fn services(&self) -> &IndexMap<String, Arc<Service>> {
        &self.all
    }

    /// The services of `kind` by key.
    ///
    /// PHP: `getServicesByType($type)`.
    pub fn services_by_type(&self, kind: &ServiceType) -> Vec<(&str, &Service)> {
        self.by_type.get(kind).map(|l| l.iter().map(|(k, s)| (k.as_str(), &**s)).collect()).unwrap_or_default()
    }
}
