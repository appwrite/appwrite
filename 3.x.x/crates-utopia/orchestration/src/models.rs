use std::collections::HashMap;

/// PHP `Utopia\Orchestration\Container`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Container {
    name: String,
    id: String,
    status: String,
    labels: HashMap<String, String>,
}

impl Container {
    /// PHP `__construct(string $name = '', string $id = '', string $status = '', array $labels = [])`.
    #[must_use]
    pub fn new(
        name: impl Into<String>,
        id: impl Into<String>,
        status: impl Into<String>,
        labels: HashMap<String, String>,
    ) -> Self {
        Self {
            name: name.into(),
            id: id.into(),
            status: status.into(),
            labels,
        }
    }

    #[must_use]
    pub fn get_name(&self) -> &str {
        &self.name
    }
    #[must_use]
    pub fn get_id(&self) -> &str {
        &self.id
    }
    #[must_use]
    pub fn get_status(&self) -> &str {
        &self.status
    }
    #[must_use]
    pub fn get_labels(&self) -> &HashMap<String, String> {
        &self.labels
    }
    pub fn set_name(&mut self, name: impl Into<String>) -> &mut Self {
        self.name = name.into();
        self
    }
    pub fn set_id(&mut self, id: impl Into<String>) -> &mut Self {
        self.id = id.into();
        self
    }
    pub fn set_status(&mut self, status: impl Into<String>) -> &mut Self {
        self.status = status.into();
        self
    }
    pub fn set_labels(&mut self, labels: HashMap<String, String>) -> &mut Self {
        self.labels = labels;
        self
    }
}

/// PHP `Utopia\Orchestration\Network`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Network {
    name: String,
    id: String,
    driver: String,
    scope: String,
}

impl Network {
    /// PHP `__construct(string $name = '', string $id = '', string $driver = '', string $scope = '')`.
    #[must_use]
    pub fn new(
        name: impl Into<String>,
        id: impl Into<String>,
        driver: impl Into<String>,
        scope: impl Into<String>,
    ) -> Self {
        Self {
            name: name.into(),
            id: id.into(),
            driver: driver.into(),
            scope: scope.into(),
        }
    }

    #[must_use]
    pub fn get_name(&self) -> &str {
        &self.name
    }
    #[must_use]
    pub fn get_id(&self) -> &str {
        &self.id
    }
    #[must_use]
    pub fn get_driver(&self) -> &str {
        &self.driver
    }
    #[must_use]
    pub fn get_scope(&self) -> &str {
        &self.scope
    }
    pub fn set_name(&mut self, name: impl Into<String>) -> &mut Self {
        self.name = name.into();
        self
    }
    pub fn set_id(&mut self, id: impl Into<String>) -> &mut Self {
        self.id = id.into();
        self
    }
    pub fn set_driver(&mut self, driver: impl Into<String>) -> &mut Self {
        self.driver = driver.into();
        self
    }
    pub fn set_scope(&mut self, scope: impl Into<String>) -> &mut Self {
        self.scope = scope.into();
        self
    }
}

/// PHP `Utopia\Orchestration\Container\Stats`.
#[derive(Debug, Clone, PartialEq)]
pub struct Stats {
    container_id: String,
    container_name: String,
    cpu_usage: f64,
    memory_usage: f64,
    disk_io: HashMap<String, f64>,
    memory_io: HashMap<String, f64>,
    network_io: HashMap<String, f64>,
}

impl Stats {
    /// PHP `__construct(...)`.
    #[must_use]
    pub fn new(
        container_id: impl Into<String>,
        container_name: impl Into<String>,
        cpu_usage: f64,
        memory_usage: f64,
        disk_io: HashMap<String, f64>,
        memory_io: HashMap<String, f64>,
        network_io: HashMap<String, f64>,
    ) -> Self {
        Self {
            container_id: container_id.into(),
            container_name: container_name.into(),
            cpu_usage,
            memory_usage,
            disk_io,
            memory_io,
            network_io,
        }
    }

    #[must_use]
    pub fn get_container_id(&self) -> &str {
        &self.container_id
    }
    #[must_use]
    pub fn get_container_name(&self) -> &str {
        &self.container_name
    }
    #[must_use]
    pub fn get_cpu_usage(&self) -> f64 {
        self.cpu_usage
    }
    #[must_use]
    pub fn get_memory_usage(&self) -> f64 {
        self.memory_usage
    }
    #[must_use]
    pub fn get_memory_io(&self) -> &HashMap<String, f64> {
        &self.memory_io
    }
    #[must_use]
    pub fn get_disk_io(&self) -> &HashMap<String, f64> {
        &self.disk_io
    }
    #[must_use]
    pub fn get_network_io(&self) -> &HashMap<String, f64> {
        &self.network_io
    }
}
