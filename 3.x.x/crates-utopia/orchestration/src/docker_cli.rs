use crate::adapter::{Adapter, AdapterSettings};
use crate::error::OrchestrationError;
use crate::models::{Container, Network, Stats};
use crate::php::{
    filter_env_key, parse_io_stats, php_empty_f64, php_empty_i64, php_empty_str, php_parse_str,
};
use std::collections::HashMap;
use std::time::{SystemTime, UNIX_EPOCH};
use utopia_console::{Command, Console};

/// PHP `Utopia\Orchestration\Adapter\DockerCLI`.
#[derive(Debug, Clone)]
pub struct DockerCLI {
    settings: AdapterSettings,
}

impl DockerCLI {
    /// PHP `Adapter::RESTART_NO`.
    pub const RESTART_NO: &'static str = "no";
    /// PHP `Adapter::RESTART_ALWAYS`.
    pub const RESTART_ALWAYS: &'static str = "always";
    /// PHP `Adapter::RESTART_ON_FAILURE`.
    pub const RESTART_ON_FAILURE: &'static str = "on-failure";
    /// PHP `Adapter::RESTART_UNLESS_STOPPED`.
    pub const RESTART_UNLESS_STOPPED: &'static str = "unless-stopped";

    /// PHP `__construct(?string $username = null, ?string $password = null)`.
    pub fn new(username: Option<&str>, password: Option<&str>) -> Result<Self, OrchestrationError> {
        if let (Some(user), Some(pass)) = (username, password) {
            if !user.is_empty() && !pass.is_empty() {
                let command = docker()?
                    .argument("login", None)
                    .and_then(|c| c.option("--username", user, None))
                    .and_then(|c| c.flag("--password-stdin"))
                    .map_err(|e| OrchestrationError::Orchestration(e.to_string()))?;
                let (code, output, stderr) = run(command, pass, -1);
                if code != 0 {
                    let error = if stderr.is_empty() { output } else { stderr };
                    return Err(OrchestrationError::docker(error));
                }
            }
        }
        Ok(Self {
            settings: AdapterSettings::default(),
        })
    }
}

fn docker() -> Result<Command, OrchestrationError> {
    Command::new("docker").map_err(|e| OrchestrationError::Orchestration(e.to_string()))
}

fn run(cmd: Command, stdin: &str, timeout: i64) -> (i32, String, String) {
    let mut stdout = String::new();
    let mut stderr = String::new();
    let code = Console::execute(cmd, stdin, &mut stdout, &mut stderr, timeout, None);
    (code, stdout, stderr)
}

fn cmd_err(e: utopia_console::CommandError) -> OrchestrationError {
    OrchestrationError::Orchestration(e.to_string())
}

impl Adapter for DockerCLI {
    fn create_network(&self, name: &str, internal: bool) -> Result<bool, OrchestrationError> {
        let mut command = docker()?
            .argument("network", None)
            .and_then(|c| c.argument("create", None))
            .map_err(cmd_err)?;
        if internal {
            command = command.flag("--internal").map_err(cmd_err)?;
        }
        command = command.argument(name, None).map_err(cmd_err)?;
        let (code, _, _) = run(command, "", -1);
        Ok(code == 0)
    }

    fn remove_network(&self, name: &str) -> Result<bool, OrchestrationError> {
        let command = docker()?
            .argument("network", None)
            .and_then(|c| c.argument("rm", None))
            .and_then(|c| c.argument(name, None))
            .map_err(cmd_err)?;
        let (code, _, _) = run(command, "", -1);
        Ok(code == 0)
    }

    fn network_connect(&self, container: &str, network: &str) -> Result<bool, OrchestrationError> {
        let command = docker()?
            .argument("network", None)
            .and_then(|c| c.argument("connect", None))
            .and_then(|c| c.argument(network, None))
            .and_then(|c| c.argument(container, None))
            .map_err(cmd_err)?;
        let (code, _, _) = run(command, "", -1);
        Ok(code == 0)
    }

    fn network_disconnect(
        &self,
        container: &str,
        network: &str,
        force: bool,
    ) -> Result<bool, OrchestrationError> {
        let mut command = docker()?
            .argument("network", None)
            .and_then(|c| c.argument("disconnect", None))
            .map_err(cmd_err)?;
        if force {
            command = command.flag("--force").map_err(cmd_err)?;
        }
        command = command
            .argument(network, None)
            .and_then(|c| c.argument(container, None))
            .map_err(cmd_err)?;
        let (code, _, _) = run(command, "", -1);
        Ok(code == 0)
    }

    fn network_exists(&self, name: &str) -> Result<bool, OrchestrationError> {
        let command = docker()?
            .argument("network", None)
            .and_then(|c| c.argument("inspect", None))
            .and_then(|c| c.argument(name, None))
            .and_then(|c| c.option("--format", "{{.Name}}", None))
            .map_err(cmd_err)?;
        let (code, output, _) = run(command, "", -1);
        Ok(code == 0 && output.trim() == name)
    }

    fn get_stats(
        &self,
        container: Option<&str>,
        filters: HashMap<String, String>,
    ) -> Result<Vec<Stats>, OrchestrationError> {
        let container_ids: Vec<String> = if let Some(id) = container {
            vec![id.to_string()]
        } else {
            self.list(filters.clone())?
                .into_iter()
                .map(|c| c.get_id().to_string())
                .collect()
        };
        if container_ids.is_empty() && !filters.is_empty() {
            return Ok(Vec::new());
        }
        let mut command = docker()?
            .argument("stats", None)
            .and_then(|c| c.flag("--no-trunc"))
            .and_then(|c| {
                c.option(
                    "--format",
                    "id={{.ID}}&name={{.Name}}&cpu={{.CPUPerc}}&memory={{.MemPerc}}&diskIO={{.BlockIO}}&memoryIO={{.MemUsage}}&networkIO={{.NetIO}}",
                    None,
                )
            })
            .and_then(|c| c.flag("--no-stream"))
            .map_err(cmd_err)?;
        for id in &container_ids {
            command = command.argument(id, None).map_err(cmd_err)?;
        }
        let (code, output, _) = run(command, "", -1);
        if code != 0 {
            return Ok(Vec::new());
        }
        let mut stats = Vec::new();
        for line in output.split('\n') {
            if line.is_empty() {
                continue;
            }
            let stat = php_parse_str(line);
            let cpu = stat.get("cpu").map_or("", String::as_str);
            let memory = stat.get("memory").map_or("", String::as_str);
            let cpu_usage = cpu.trim_end_matches('%').parse::<f64>().unwrap_or(0.0) / 100.0;
            let memory_usage = if memory.is_empty() {
                0.0
            } else {
                memory.trim_end_matches('%').parse::<f64>().unwrap_or(0.0)
            };
            stats.push(Stats::new(
                stat.get("id").cloned().unwrap_or_default(),
                stat.get("name").cloned().unwrap_or_default(),
                cpu_usage,
                memory_usage,
                parse_io_stats(stat.get("diskIO").map_or("", String::as_str)),
                parse_io_stats(stat.get("memoryIO").map_or("", String::as_str)),
                parse_io_stats(stat.get("networkIO").map_or("", String::as_str)),
            ));
        }
        Ok(stats)
    }

    fn list_networks(&self) -> Result<Vec<Network>, OrchestrationError> {
        let command = docker()?
            .argument("network", None)
            .and_then(|c| c.argument("ls", None))
            .and_then(|c| {
                c.option(
                    "--format",
                    "id={{.ID}}&name={{.Name}}&driver={{.Driver}}&scope={{.Scope}}",
                    None,
                )
            })
            .map_err(cmd_err)?;
        let (code, output, stderr) = run(command, "", -1);
        if code != 0 {
            let error = if stderr.is_empty() { output } else { stderr };
            return Err(OrchestrationError::docker(error));
        }
        let mut list = Vec::new();
        for value in output.split('\n') {
            let network = php_parse_str(value);
            if let Some(name) = network.get("name") {
                list.push(Network::new(
                    name.clone(),
                    network.get("id").cloned().unwrap_or_default(),
                    network.get("driver").cloned().unwrap_or_default(),
                    network.get("scope").cloned().unwrap_or_default(),
                ));
            }
        }
        Ok(list)
    }

    fn pull(&self, image: &str) -> Result<bool, OrchestrationError> {
        let command = docker()?
            .argument("pull", None)
            .and_then(|c| c.argument(image, None))
            .map_err(cmd_err)?;
        let (code, _, _) = run(command, "", -1);
        Ok(code == 0)
    }

    fn list(&self, filters: HashMap<String, String>) -> Result<Vec<Container>, OrchestrationError> {
        let mut command = docker()?
            .argument("ps", None)
            .and_then(|c| c.flag("--all"))
            .and_then(|c| c.flag("--no-trunc"))
            .and_then(|c| {
                c.option(
                    "--format",
                    "id={{.ID}}&name={{.Names}}&status={{.Status}}&labels={{.Labels}}",
                    None,
                )
            })
            .map_err(cmd_err)?;
        for (key, value) in &filters {
            command = command
                .option("--filter", format!("{key}={value}"), None)
                .map_err(cmd_err)?;
        }
        let (code, output, stderr) = run(command, "", -1);
        if code != 0 && code != -1 {
            let error = if stderr.is_empty() { output } else { stderr };
            return Err(OrchestrationError::docker(error));
        }
        let mut list = Vec::new();
        for value in output.split('\n') {
            let container = php_parse_str(value);
            if let Some(name) = container.get("name") {
                let mut labels_parsed = HashMap::new();
                for label in container
                    .get("labels")
                    .map_or("", String::as_str)
                    .split(',')
                {
                    let mut parts = label.splitn(2, '=');
                    if let (Some(k), Some(v)) = (parts.next(), parts.next()) {
                        if !k.is_empty() {
                            labels_parsed.insert(k.to_string(), v.to_string());
                        }
                    }
                }
                list.push(Container::new(
                    name.clone(),
                    container.get("id").cloned().unwrap_or_default(),
                    container.get("status").cloned().unwrap_or_default(),
                    labels_parsed,
                ));
            }
        }
        Ok(list)
    }

    #[allow(clippy::too_many_arguments, clippy::too_many_lines)]
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
    ) -> Result<String, OrchestrationError> {
        let time = SystemTime::now()
            .duration_since(UNIX_EPOCH)
            .map(|d| d.as_secs())
            .unwrap_or(0);
        let mut docker_command = docker()?
            .argument("run", None)
            .and_then(|c| c.flag("-d"))
            .map_err(cmd_err)?;
        if remove {
            docker_command = docker_command.flag("--rm").map_err(cmd_err)?;
        }
        if !php_empty_str(network) {
            docker_command = docker_command
                .option("--network", network, None)
                .map_err(cmd_err)?;
        }
        if !php_empty_str(entrypoint) {
            docker_command = docker_command
                .option("--entrypoint", entrypoint, None)
                .map_err(cmd_err)?;
        }
        if !php_empty_f64(self.settings.cpus) {
            docker_command = docker_command
                .option("--cpus", self.settings.cpus.to_string(), None)
                .map_err(cmd_err)?;
        }
        if !php_empty_i64(self.settings.memory) {
            docker_command = docker_command
                .option("--memory", format!("{}m", self.settings.memory), None)
                .map_err(cmd_err)?;
        }
        if !php_empty_i64(self.settings.swap) {
            docker_command = docker_command
                .option("--memory-swap", format!("{}m", self.settings.swap), None)
                .map_err(cmd_err)?;
        }
        docker_command = docker_command
            .option("--restart", restart, None)
            .and_then(|c| c.option("--name", name, None))
            .and_then(|c| {
                c.option(
                    "--label",
                    format!("{}-type=runtime", self.settings.namespace),
                    None,
                )
            })
            .and_then(|c| {
                c.option(
                    "--label",
                    format!("{}-created={time}", self.settings.namespace),
                    None,
                )
            })
            .map_err(cmd_err)?;
        if !php_empty_str(mount_folder) {
            docker_command = docker_command
                .option("--volume", format!("{mount_folder}:/tmp:rw"), None)
                .map_err(cmd_err)?;
        }
        for volume in volumes {
            docker_command = docker_command
                .option("--volume", volume, None)
                .map_err(cmd_err)?;
        }
        for (key, label) in labels {
            docker_command = docker_command
                .option("--label", format!("{key}={label}"), None)
                .map_err(cmd_err)?;
        }
        if !php_empty_str(workdir) {
            docker_command = docker_command
                .option("--workdir", workdir, None)
                .map_err(cmd_err)?;
        }
        if !php_empty_str(hostname) {
            docker_command = docker_command
                .option("--hostname", hostname, None)
                .map_err(cmd_err)?;
        }
        for (key, value) in vars {
            docker_command = docker_command
                .option("--env", format!("{}={value}", filter_env_key(key)), None)
                .map_err(cmd_err)?;
        }
        docker_command = docker_command.argument(image, None).map_err(cmd_err)?;
        for value in command {
            docker_command = docker_command.argument(value, None).map_err(cmd_err)?;
        }
        let (result, output, stderr) = run(docker_command, "", 30);
        if result != 0 {
            let error = if stderr.is_empty() { output } else { stderr };
            return Err(OrchestrationError::docker(error));
        }
        let first = output.split('\n').next().unwrap_or("").trim_end();
        Ok(first.to_string())
    }

    fn execute(
        &self,
        name: &str,
        command: &[String],
        output: &mut String,
        vars: &HashMap<String, String>,
        timeout: i64,
    ) -> Result<bool, OrchestrationError> {
        let mut docker_command = docker()?.argument("exec", None).map_err(cmd_err)?;
        for (key, value) in vars {
            docker_command = docker_command
                .option("--env", format!("{}={value}", filter_env_key(key)), None)
                .map_err(cmd_err)?;
        }
        docker_command = docker_command.argument(name, None).map_err(cmd_err)?;
        for value in command {
            docker_command = docker_command.argument(value, None).map_err(cmd_err)?;
        }
        let mut stderr = String::new();
        let start = std::time::Instant::now();
        let result = Console::execute(docker_command, "", output, &mut stderr, timeout, None);
        if result != 0 {
            if timeout > 0 && start.elapsed().as_secs() as i64 >= timeout {
                return Err(OrchestrationError::timed_out());
            }
            if result == 124 {
                return Err(OrchestrationError::timed_out());
            }
            let error = if stderr.is_empty() {
                output.clone()
            } else {
                stderr
            };
            return Err(OrchestrationError::docker(error));
        }
        Ok(true)
    }

    fn remove(&self, name: &str, force: bool) -> Result<bool, OrchestrationError> {
        let mut command = docker()?.argument("rm", None).map_err(cmd_err)?;
        if force {
            command = command.flag("--force").map_err(cmd_err)?;
        }
        command = command.argument(name, None).map_err(cmd_err)?;
        let (result, output, stderr) = run(command, "", -1);
        let combined = format!("{output}{stderr}");
        if !combined.starts_with(name) || combined.contains("No such container") {
            return Err(OrchestrationError::docker(combined));
        }
        Ok(result == 0)
    }

    fn set_namespace(&mut self, namespace: impl Into<String>) -> &mut Self {
        self.settings.namespace = namespace.into();
        self
    }
    fn set_cpus(&mut self, cores: f64) -> &mut Self {
        self.settings.cpus = cores;
        self
    }
    fn set_memory(&mut self, mb: i64) -> &mut Self {
        self.settings.memory = mb;
        self
    }
    fn set_swap(&mut self, mb: i64) -> &mut Self {
        self.settings.swap = mb;
        self
    }
}
