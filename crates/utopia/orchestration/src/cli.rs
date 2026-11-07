//! [`DockerCli`]: Docker through its command-line client.

use std::time::Duration;

use php_std::encoding::parse_str;
use php_std::types::{Array, KeyRef, Value};
use utopia_console::{Command, Output, execute};

use crate::{Adapter, BoxFuture, Container, Error, Io, Network, RunOptions, Settings, Stats, filter_env_key};

/// Docker through the `docker` command-line client on the `PATH`, which
/// reaches whatever daemon its environment or context names.
///
/// Commands run without a shell, every word passed as its own argument.
///
/// ```no_run
/// use utopia_orchestration::{Adapter, DockerCli};
///
/// # async fn run() -> Result<(), utopia_orchestration::Error> {
/// let docker = DockerCli::new();
/// for container in docker.list(&[("label".into(), "team=billing".into())]).await? {
///     println!("{} {}", container.name, container.status);
/// }
/// # Ok(())
/// # }
/// ```
///
/// ```php
/// use Utopia\Orchestration\Adapter\DockerCLI;
///
/// $docker = new DockerCLI();
/// // foreach ($docker->list(['label' => 'team=billing']) as $container) {
/// //     echo $container->getName(), ' ', $container->getStatus(), "\n";
/// // }
/// ```
///
/// PHP: `Utopia\Orchestration\Adapter\DockerCLI`.
#[derive(Debug, Clone)]
pub struct DockerCli {
    settings: Settings,
    executable: String,
}

impl Default for DockerCli {
    /// See [`DockerCli::new`].
    fn default() -> Self {
        DockerCli { settings: Settings::default(), executable: "docker".into() }
    }
}

/// The output of a failed command, as PHP quotes it: standard error, or
/// standard output when there is none.
fn docker_error(output: &Output) -> Error {
    let empty = |s: &[u8]| s.is_empty() || s == b"0";
    let quoted = if empty(&output.stderr) { &output.stdout } else { &output.stderr };
    Error::Orchestration(format!("Docker Error: {}", String::from_utf8_lossy(quoted)))
}

/// `$array[$key]` of a `parse_str()` result, as a string (`""` when absent
/// or an array).
fn field(fields: &Array, key: &str) -> String {
    match fields.get_ref(KeyRef::normalize(key.as_bytes())) {
        Some(Value::Str(s)) => String::from_utf8_lossy(s.as_bytes()).into_owned(),
        _ => String::new(),
    }
}

/// Whether a `parse_str()` result has the key (PHP's `isset()`).
fn has(fields: &Array, key: &str) -> bool {
    matches!(fields.get_ref(KeyRef::normalize(key.as_bytes())), Some(v) if !matches!(v, Value::Null))
}

/// `floatval()` of a string.
fn float(s: &str) -> f64 {
    php_std::format::str_to_float(s.as_bytes())
}

/// `rtrim($s, $characters)`.
fn rtrim<'a>(s: &'a str, characters: &str) -> &'a str {
    s.trim_end_matches(|c| characters.contains(c))
}

/// An I/O pair as the CLI prints it (`2.133MiB / 62.8GiB`), in bytes.
fn parse_io(stats: &str) -> Io {
    const UNITS: [(&str, f64); 9] = [
        ("b", 1.0),
        ("kb", 1_000.0),
        ("mb", 1_000_000.0),
        ("gb", 1_000_000_000.0),
        ("tb", 1_000_000_000_000.0),
        ("kib", 1_024.0),
        ("mib", 1_048_576.0),
        ("gib", 1_073_741_824.0),
        ("tib", 1_099_511_627_776.0),
    ];
    let stats = stats.to_ascii_lowercase();
    let mut parts = stats.split(" / ");
    let (input, output) = (parts.next().unwrap_or_default(), parts.next().unwrap_or_default());
    let unit = |value: &str| UNITS.iter().rev().find(|(u, _)| value.ends_with(u)).copied();
    let amount = |value: &str| match unit(value) {
        Some((u, multiply)) => float(rtrim(value, u)) * multiply,
        None => float(value),
    };
    Io { input: amount(input), output: amount(output) }
}

impl DockerCli {
    /// An adapter running `docker` from the `PATH`, with the client's
    /// current login.
    ///
    /// PHP: `new DockerCLI()`.
    pub fn new() -> Self {
        DockerCli::default()
    }

    /// The same adapter running another client executable (a path, or a
    /// name looked up on the `PATH`).
    pub fn with_executable(mut self, executable: impl Into<String>) -> Self {
        self.executable = executable.into();
        self
    }

    /// Logs the client in (`docker login`, the password on standard input)
    /// when both the username and the password are non-empty (and not
    /// `0`); the adapter, once logged in.
    ///
    /// Fails with [`Error::Orchestration`] when the login fails.
    ///
    /// PHP: `new DockerCLI($username, $password)`.
    pub async fn login(self, username: &str, password: &str) -> Result<Self, Error> {
        let truthy = |s: &str| !s.is_empty() && s != "0";
        if truthy(username) && truthy(password) {
            let command = self.docker()?.argument("login")?.option("--username", username)?.flag("--password-stdin")?;
            let output = execute(&command, password.as_bytes(), None, None).await?;
            if output.status != 0 {
                return Err(docker_error(&output));
            }
        }
        Ok(self)
    }

    fn docker(&self) -> Result<Command, Error> {
        Ok(Command::new(self.executable.as_str())?)
    }

    async fn run_command(command: &Command, timeout: i64) -> Result<Output, Error> {
        let timeout = (timeout > 0).then(|| Duration::from_secs(timeout as u64));
        Ok(execute(command, b"", timeout, None).await?)
    }

    async fn create_network(&self, name: &str, internal: bool) -> Result<bool, Error> {
        let mut command = self.docker()?.argument("network")?.argument("create")?;
        if internal {
            command = command.flag("--internal")?;
        }
        let command = command.argument(name)?;
        Ok(Self::run_command(&command, -1).await?.status == 0)
    }

    async fn remove_network(&self, name: &str) -> Result<bool, Error> {
        let command = self.docker()?.argument("network")?.argument("rm")?.argument(name)?;
        Ok(Self::run_command(&command, -1).await?.status == 0)
    }

    async fn network_connect(&self, container: &str, network: &str) -> Result<bool, Error> {
        let command =
            self.docker()?.argument("network")?.argument("connect")?.argument(network)?.argument(container)?;
        Ok(Self::run_command(&command, -1).await?.status == 0)
    }

    async fn network_disconnect(&self, container: &str, network: &str, force: bool) -> Result<bool, Error> {
        let mut command = self.docker()?.argument("network")?.argument("disconnect")?;
        if force {
            command = command.flag("--force")?;
        }
        let command = command.argument(network)?.argument(container)?;
        Ok(Self::run_command(&command, -1).await?.status == 0)
    }

    async fn network_exists(&self, name: &str) -> Result<bool, Error> {
        let command =
            self.docker()?.argument("network")?.argument("inspect")?.argument(name)?.option("--format", "{{.Name}}")?;
        let output = Self::run_command(&command, -1).await?;
        Ok(output.status == 0 && php_std::string::trim(&output.stdout, b" \n\r\t\x0B\0") == name.as_bytes())
    }

    async fn stats(&self, container: Option<&str>, filters: &[(String, String)]) -> Result<Vec<Stats>, Error> {
        // docker stats cannot filter, so the containers are listed first.
        let ids: Vec<String> = match container {
            None => self.list(filters).await?.into_iter().map(|c| c.id).collect(),
            Some(container) => vec![container.to_owned()],
        };
        if ids.is_empty() && !filters.is_empty() {
            return Ok(Vec::new());
        }
        let mut command = self.docker()?
            .argument("stats")?
            .flag("--no-trunc")?
            .option(
                "--format",
                "id={{.ID}}&name={{.Name}}&cpu={{.CPUPerc}}&memory={{.MemPerc}}&diskIO={{.BlockIO}}&memoryIO={{.MemUsage}}&networkIO={{.NetIO}}",
            )?
            .flag("--no-stream")?;
        for id in &ids {
            command = command.argument(id.as_str())?;
        }
        let output = Self::run_command(&command, -1).await?;
        if output.status != 0 {
            return Ok(Vec::new());
        }
        let mut stats = Vec::new();
        for line in output.stdout.split(|&b| b == b'\n') {
            if line.is_empty() || line == b"0" {
                continue;
            }
            let fields = parse_str(line);
            let memory = field(&fields, "memory");
            stats.push(Stats {
                container_id: field(&fields, "id"),
                container_name: field(&fields, "name"),
                cpu_usage: float(rtrim(&field(&fields, "cpu"), "%")) / 100.0,
                memory_usage: if memory.is_empty() || memory == "0" { 0.0 } else { float(rtrim(&memory, "%")) },
                disk_io: parse_io(&field(&fields, "diskIO")),
                memory_io: parse_io(&field(&fields, "memoryIO")),
                network_io: parse_io(&field(&fields, "networkIO")),
            });
        }
        Ok(stats)
    }

    async fn list_networks(&self) -> Result<Vec<Network>, Error> {
        let command = self
            .docker()?
            .argument("network")?
            .argument("ls")?
            .option("--format", "id={{.ID}}&name={{.Name}}&driver={{.Driver}}&scope={{.Scope}}")?;
        let output = Self::run_command(&command, -1).await?;
        if output.status != 0 {
            return Err(docker_error(&output));
        }
        let mut list = Vec::new();
        for line in output.stdout.split(|&b| b == b'\n') {
            let fields = parse_str(line);
            if has(&fields, "name") {
                list.push(Network::new(
                    field(&fields, "name"),
                    field(&fields, "id"),
                    field(&fields, "driver"),
                    field(&fields, "scope"),
                ));
            }
        }
        Ok(list)
    }

    async fn pull(&self, image: &str) -> Result<bool, Error> {
        let command = self.docker()?.argument("pull")?.argument(image)?;
        Ok(Self::run_command(&command, -1).await?.status == 0)
    }

    async fn list(&self, filters: &[(String, String)]) -> Result<Vec<Container>, Error> {
        let mut command = self
            .docker()?
            .argument("ps")?
            .flag("--all")?
            .flag("--no-trunc")?
            .option("--format", "id={{.ID}}&name={{.Names}}&status={{.Status}}&labels={{.Labels}}")?;
        for (key, value) in filters {
            command = command.option("--filter", format!("{key}={value}"))?;
        }
        let output = Self::run_command(&command, -1).await?;
        if output.status != 0 && output.status != -1 {
            return Err(docker_error(&output));
        }
        let mut list = Vec::new();
        for line in output.stdout.split(|&b| b == b'\n') {
            let fields = parse_str(line);
            if !has(&fields, "name") {
                continue;
            }
            let mut labels: Vec<(String, String)> = Vec::new();
            for label in field(&fields, "labels").split(',') {
                let mut parts = label.split('=');
                if let (Some(key), Some(value)) = (parts.next(), parts.next()) {
                    match labels.iter_mut().find(|(k, _)| same_key(k, key)) {
                        Some(entry) => entry.1 = value.to_owned(),
                        None => labels.push((key.to_owned(), value.to_owned())),
                    }
                }
            }
            list.push(Container::new(field(&fields, "name"), field(&fields, "id"), field(&fields, "status"), labels));
        }
        Ok(list)
    }

    async fn run(&self, image: &str, name: &str, options: &RunOptions) -> Result<String, Error> {
        let time = php_std::datetime::time();
        let settings = &self.settings;
        let mut command = self.docker()?.argument("run")?.flag("-d")?;
        if options.remove {
            command = command.flag("--rm")?;
        }
        if !options.network.is_empty() && options.network != "0" {
            command = command.option("--network", options.network.as_str())?;
        }
        if !options.entrypoint.is_empty() && options.entrypoint != "0" {
            command = command.option("--entrypoint", options.entrypoint.as_str())?;
        }
        if settings.cpus != 0.0 {
            command = command.option("--cpus", settings.cpus)?;
        }
        if settings.memory != 0 {
            command = command.option("--memory", format!("{}m", settings.memory))?;
        }
        if settings.swap != 0 {
            command = command.option("--memory-swap", format!("{}m", settings.swap))?;
        }
        command = command
            .option("--restart", options.restart.as_str())?
            .option("--name", name)?
            .option("--label", format!("{}-type=runtime", settings.namespace))?
            .option("--label", format!("{}-created={time}", settings.namespace))?;
        if !options.mount_folder.is_empty() && options.mount_folder != "0" {
            command = command.option("--volume", format!("{}:/tmp:rw", options.mount_folder))?;
        }
        for volume in &options.volumes {
            command = command.option("--volume", volume.as_str())?;
        }
        for (key, label) in &options.labels {
            command = command.option("--label", format!("{key}={label}"))?;
        }
        if !options.workdir.is_empty() && options.workdir != "0" {
            command = command.option("--workdir", options.workdir.as_str())?;
        }
        if !options.hostname.is_empty() && options.hostname != "0" {
            command = command.option("--hostname", options.hostname.as_str())?;
        }
        for (key, value) in &options.vars {
            command = command.option("--env", format!("{}={value}", filter_env_key(key)))?;
        }
        command = command.argument(image)?;
        for word in &options.command {
            command = command.argument(word.as_str())?;
        }
        let output = Self::run_command(&command, 30).await?;
        if output.status != 0 {
            return Err(docker_error(&output));
        }
        // The first line only: the client can add warnings.
        let first = output.stdout.split(|&b| b == b'\n').next().unwrap_or_default();
        let id = php_std::string::rtrim(first, b" \n\r\t\x0B\0");
        Ok(String::from_utf8_lossy(id).into_owned())
    }

    async fn execute(
        &self,
        name: &str,
        command: &[String],
        output: &mut Vec<u8>,
        vars: &[(String, String)],
        timeout: i64,
    ) -> Result<bool, Error> {
        let mut docker = self.docker()?.argument("exec")?;
        for (key, value) in vars {
            docker = docker.option("--env", format!("{}={value}", filter_env_key(key)))?;
        }
        docker = docker.argument(name)?;
        for word in command {
            docker = docker.argument(word.as_str())?;
        }
        let result = Self::run_command(&docker, timeout).await?;
        *output = result.stdout.clone();
        if result.status != 0 {
            if result.status == 124 {
                return Err(Error::Timeout("Command timed out".into()));
            }
            return Err(docker_error(&result));
        }
        Ok(true)
    }

    async fn remove(&self, name: &str, force: bool) -> Result<bool, Error> {
        let mut command = self.docker()?.argument("rm")?;
        if force {
            command = command.flag("--force")?;
        }
        let command = command.argument(name)?;
        let output = Self::run_command(&command, -1).await?;
        let mut combined = output.stdout.clone();
        combined.extend_from_slice(&output.stderr);
        let contains = |needle: &[u8]| combined.windows(needle.len()).any(|w| w == needle);
        if !combined.starts_with(name.as_bytes()) || contains(b"No such container") {
            return Err(Error::Orchestration(format!("Docker Error: {}", String::from_utf8_lossy(&combined))));
        }
        Ok(output.status == 0)
    }
}

/// Whether two label keys are one key of a PHP array (`"1"` and `"01"` are
/// not; `"1"` and `1` are).
fn same_key(a: &str, b: &str) -> bool {
    KeyRef::normalize(a.as_bytes()) == KeyRef::normalize(b.as_bytes())
}

impl Adapter for DockerCli {
    fn settings(&self) -> &Settings {
        &self.settings
    }

    fn settings_mut(&mut self) -> &mut Settings {
        &mut self.settings
    }

    /// `docker network create [--internal] <name>`; whether it succeeded.
    fn create_network<'a>(&'a self, name: &'a str, internal: bool) -> BoxFuture<'a, Result<bool, Error>> {
        Box::pin(self.create_network(name, internal))
    }

    /// `docker network rm <name>`; whether it succeeded.
    fn remove_network<'a>(&'a self, name: &'a str) -> BoxFuture<'a, Result<bool, Error>> {
        Box::pin(self.remove_network(name))
    }

    /// `docker network connect <network> <container>`; whether it succeeded.
    fn network_connect<'a>(&'a self, container: &'a str, network: &'a str) -> BoxFuture<'a, Result<bool, Error>> {
        Box::pin(self.network_connect(container, network))
    }

    /// `docker network disconnect [--force] <network> <container>`; whether
    /// it succeeded.
    fn network_disconnect<'a>(
        &'a self,
        container: &'a str,
        network: &'a str,
        force: bool,
    ) -> BoxFuture<'a, Result<bool, Error>> {
        Box::pin(self.network_disconnect(container, network, force))
    }

    /// Whether `docker network inspect` prints that name.
    fn network_exists<'a>(&'a self, name: &'a str) -> BoxFuture<'a, Result<bool, Error>> {
        Box::pin(self.network_exists(name))
    }

    /// `docker network ls`, read back.
    fn list_networks<'a>(&'a self) -> BoxFuture<'a, Result<Vec<Network>, Error>> {
        Box::pin(self.list_networks())
    }

    /// `docker stats --no-stream` of the container, or of the listed ones
    /// (none when the filters select none; every running one without
    /// filters); none when the client fails.
    fn stats<'a>(
        &'a self,
        container: Option<&'a str>,
        filters: &'a [(String, String)],
    ) -> BoxFuture<'a, Result<Vec<Stats>, Error>> {
        Box::pin(self.stats(container, filters))
    }

    /// `docker pull <image>`; whether it succeeded.
    fn pull<'a>(&'a self, image: &'a str) -> BoxFuture<'a, Result<bool, Error>> {
        Box::pin(self.pull(image))
    }

    /// `docker ps --all` with the filters, read back.
    fn list<'a>(&'a self, filters: &'a [(String, String)]) -> BoxFuture<'a, Result<Vec<Container>, Error>> {
        Box::pin(self.list(filters))
    }

    /// `docker run -d` with the options, killed after 30 seconds.
    fn run<'a>(
        &'a self,
        image: &'a str,
        name: &'a str,
        options: &'a RunOptions,
    ) -> BoxFuture<'a, Result<String, Error>> {
        Box::pin(self.run(image, name, options))
    }

    /// `docker exec`; `output` is its standard output.
    fn execute<'a>(
        &'a self,
        name: &'a str,
        command: &'a [String],
        output: &'a mut Vec<u8>,
        vars: &'a [(String, String)],
        timeout: i64,
    ) -> BoxFuture<'a, Result<bool, Error>> {
        Box::pin(self.execute(name, command, output, vars, timeout))
    }

    /// `docker rm [--force]`; fails unless Docker echoes the name.
    fn remove<'a>(&'a self, name: &'a str, force: bool) -> BoxFuture<'a, Result<bool, Error>> {
        Box::pin(self.remove(name, force))
    }
}
