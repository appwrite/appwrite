use crate::adapter::Adapter;
use crate::error::OrchestrationError;
use crate::models::{Container, Network, Stats};
use crate::php::parse_command_string;
use std::collections::HashMap;

/// PHP `Utopia\Orchestration\Orchestration`.
#[derive(Debug)]
pub struct Orchestration<A: Adapter> {
    adapter: A,
}

impl<A: Adapter> Orchestration<A> {
    /// PHP `__construct(Adapter $adapter)`.
    pub fn new(adapter: A) -> Self {
        Self { adapter }
    }

    /// PHP `parseCommandString`.
    pub fn parse_command_string(command: &str) -> Result<Vec<String>, OrchestrationError> {
        parse_command_string(command)
    }

    pub fn create_network(&self, name: &str, internal: bool) -> Result<bool, OrchestrationError> {
        self.adapter.create_network(name, internal)
    }
    pub fn remove_network(&self, name: &str) -> Result<bool, OrchestrationError> {
        self.adapter.remove_network(name)
    }
    pub fn list_networks(&self) -> Result<Vec<Network>, OrchestrationError> {
        self.adapter.list_networks()
    }
    pub fn network_connect(
        &self,
        container: &str,
        network: &str,
    ) -> Result<bool, OrchestrationError> {
        self.adapter.network_connect(container, network)
    }
    pub fn get_stats(
        &self,
        container: Option<&str>,
        filters: HashMap<String, String>,
    ) -> Result<Vec<Stats>, OrchestrationError> {
        self.adapter.get_stats(container, filters)
    }
    pub fn network_disconnect(
        &self,
        container: &str,
        network: &str,
        force: bool,
    ) -> Result<bool, OrchestrationError> {
        self.adapter.network_disconnect(container, network, force)
    }
    pub fn network_exists(&self, name: &str) -> Result<bool, OrchestrationError> {
        self.adapter.network_exists(name)
    }
    pub fn pull(&self, image: &str) -> Result<bool, OrchestrationError> {
        self.adapter.pull(image)
    }
    pub fn list(
        &self,
        filters: HashMap<String, String>,
    ) -> Result<Vec<Container>, OrchestrationError> {
        self.adapter.list(filters)
    }

    #[allow(clippy::too_many_arguments)]
    pub fn run(
        &self,
        image: &str,
        name: &str,
        command: &[String],
        entrypoint: &str,
        workdir: &str,
        volumes: &[String],
        vars: &HashMap<String, String>,
        mount_folder: &str,
        labels: &HashMap<String, String>,
        hostname: &str,
        remove: bool,
        network: &str,
        restart: &str,
    ) -> Result<String, OrchestrationError> {
        self.adapter.run(
            image,
            name,
            command,
            entrypoint,
            workdir,
            volumes,
            vars,
            mount_folder,
            labels,
            hostname,
            remove,
            network,
            restart,
        )
    }

    pub fn execute(
        &self,
        name: &str,
        command: &[String],
        output: &mut String,
        vars: &HashMap<String, String>,
        timeout: i64,
    ) -> Result<bool, OrchestrationError> {
        self.adapter.execute(name, command, output, vars, timeout)
    }

    pub fn remove(&self, name: &str, force: bool) -> Result<bool, OrchestrationError> {
        self.adapter.remove(name, force)
    }

    pub fn set_namespace(&mut self, namespace: impl Into<String>) -> &mut Self {
        self.adapter.set_namespace(namespace);
        self
    }
    pub fn set_cpus(&mut self, cores: f64) -> &mut Self {
        self.adapter.set_cpus(cores);
        self
    }
    pub fn set_memory(&mut self, mb: i64) -> &mut Self {
        self.adapter.set_memory(mb);
        self
    }
    pub fn set_swap(&mut self, mb: i64) -> &mut Self {
        self.adapter.set_swap(mb);
        self
    }

    pub fn adapter(&self) -> &A {
        &self.adapter
    }
}
