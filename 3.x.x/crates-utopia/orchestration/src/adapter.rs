use crate::error::OrchestrationError;
use crate::models::{Container, Network, Stats};
use std::collections::HashMap;

/// PHP `Utopia\Orchestration\Adapter`.
pub trait Adapter: Send {
    /// PHP `Adapter::RESTART_NO`.
    const RESTART_NO: &'static str = "no";
    /// PHP `Adapter::RESTART_ALWAYS`.
    const RESTART_ALWAYS: &'static str = "always";
    /// PHP `Adapter::RESTART_ON_FAILURE`.
    const RESTART_ON_FAILURE: &'static str = "on-failure";
    /// PHP `Adapter::RESTART_UNLESS_STOPPED`.
    const RESTART_UNLESS_STOPPED: &'static str = "unless-stopped";

    fn create_network(&self, name: &str, internal: bool) -> Result<bool, OrchestrationError>;
    fn remove_network(&self, name: &str) -> Result<bool, OrchestrationError>;
    fn network_connect(&self, container: &str, network: &str) -> Result<bool, OrchestrationError>;
    fn network_disconnect(
        &self,
        container: &str,
        network: &str,
        force: bool,
    ) -> Result<bool, OrchestrationError>;
    fn network_exists(&self, name: &str) -> Result<bool, OrchestrationError>;
    fn list_networks(&self) -> Result<Vec<Network>, OrchestrationError>;
    fn get_stats(
        &self,
        container: Option<&str>,
        filters: HashMap<String, String>,
    ) -> Result<Vec<Stats>, OrchestrationError>;
    fn pull(&self, image: &str) -> Result<bool, OrchestrationError>;
    fn list(&self, filters: HashMap<String, String>) -> Result<Vec<Container>, OrchestrationError>;
    #[allow(clippy::too_many_arguments)]
    fn run(
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
    ) -> Result<String, OrchestrationError>;
    fn execute(
        &self,
        name: &str,
        command: &[String],
        output: &mut String,
        vars: &HashMap<String, String>,
        timeout: i64,
    ) -> Result<bool, OrchestrationError>;
    fn remove(&self, name: &str, force: bool) -> Result<bool, OrchestrationError>;
    fn set_namespace(&mut self, namespace: impl Into<String>) -> &mut Self;
    fn set_cpus(&mut self, cores: f64) -> &mut Self;
    fn set_memory(&mut self, mb: i64) -> &mut Self;
    fn set_swap(&mut self, mb: i64) -> &mut Self;
}

/// Shared adapter settings (PHP protected fields).
#[derive(Debug, Clone)]
pub struct AdapterSettings {
    pub namespace: String,
    pub cpus: f64,
    pub memory: i64,
    pub swap: i64,
}

impl Default for AdapterSettings {
    fn default() -> Self {
        Self {
            namespace: "utopia".to_string(),
            cpus: 0.0,
            memory: 0,
            swap: 0,
        }
    }
}
