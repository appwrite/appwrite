//! What an orchestrator reports and is told: containers, networks, usage
//! statistics, restart policies and how to run a container.

use std::fmt;

/// A container as a listing reports it.
///
/// PHP: `Utopia\Orchestration\Container` (its getters and setters are the
/// public fields).
#[derive(Debug, Clone, Default, PartialEq, Eq)]
pub struct Container {
    /// The container's name, without Docker's leading `/`.
    pub name: String,
    /// The full container ID.
    pub id: String,
    /// Docker's human-readable status (`Up 3 seconds`, `Exited (0) 2 minutes ago`).
    pub status: String,
    /// The labels, in Docker's order.
    pub labels: Vec<(String, String)>,
}

impl Container {
    /// A container with these fields.
    ///
    /// PHP: `new Container($name, $id, $status, $labels)`.
    pub fn new(
        name: impl Into<String>,
        id: impl Into<String>,
        status: impl Into<String>,
        labels: Vec<(String, String)>,
    ) -> Self {
        Container { name: name.into(), id: id.into(), status: status.into(), labels }
    }

    /// The value of a label.
    pub fn label(&self, key: &str) -> Option<&str> {
        self.labels.iter().find(|(k, _)| k == key).map(|(_, v)| v.as_str())
    }
}

/// A network as a listing reports it.
///
/// PHP: `Utopia\Orchestration\Network` (its getters and setters are the
/// public fields).
#[derive(Debug, Clone, Default, PartialEq, Eq)]
pub struct Network {
    /// The network's name.
    pub name: String,
    /// The network ID.
    pub id: String,
    /// The driver (`bridge`, `host`, `overlay`, ...).
    pub driver: String,
    /// The scope (`local`, `swarm`).
    pub scope: String,
}

impl Network {
    /// A network with these fields.
    ///
    /// PHP: `new Network($name, $id, $driver, $scope)`.
    pub fn new(
        name: impl Into<String>,
        id: impl Into<String>,
        driver: impl Into<String>,
        scope: impl Into<String>,
    ) -> Self {
        Network { name: name.into(), id: id.into(), driver: driver.into(), scope: scope.into() }
    }
}

/// Bytes in and out (read and written, received and sent).
///
/// PHP: the `['in' => ..., 'out' => ...]` arrays of `Stats`.
#[derive(Debug, Clone, Copy, Default, PartialEq)]
pub struct Io {
    /// Bytes in (read, received; for memory: in use).
    pub input: f64,
    /// Bytes out (written, sent; for memory: the limit or the peak).
    pub output: f64,
}

/// A container's resource usage at one moment.
///
/// PHP: `Utopia\Orchestration\Container\Stats` (its getters are the public
/// fields).
#[derive(Debug, Clone, Default, PartialEq)]
pub struct Stats {
    /// The full container ID.
    pub container_id: String,
    /// The container's name.
    pub container_name: String,
    /// CPU usage, 1.0 being one core fully used.
    pub cpu_usage: f64,
    /// Memory usage, in percent of the limit.
    pub memory_usage: f64,
    /// Block I/O.
    pub disk_io: Io,
    /// Memory in use, and its limit (CLI) or peak (API).
    pub memory_io: Io,
    /// Network I/O.
    pub network_io: Io,
}

/// When Docker restarts a container that stopped.
///
/// PHP: the `Adapter::RESTART_*` constants (`$restart` is a string).
#[derive(Debug, Clone, Default, PartialEq, Eq)]
pub enum Restart {
    /// Never. PHP: `RESTART_NO` (`no`).
    #[default]
    No,
    /// After any exit. PHP: `RESTART_ALWAYS` (`always`).
    Always,
    /// After a non-zero exit. PHP: `RESTART_ON_FAILURE` (`on-failure`).
    OnFailure,
    /// After any exit, unless stopped by hand. PHP:
    /// `RESTART_UNLESS_STOPPED` (`unless-stopped`).
    UnlessStopped,
    /// Another policy, passed to Docker as written (`on-failure:3`).
    Other(String),
}

impl Restart {
    /// The policy as Docker names it.
    pub fn as_str(&self) -> &str {
        match self {
            Restart::No => "no",
            Restart::Always => "always",
            Restart::OnFailure => "on-failure",
            Restart::UnlessStopped => "unless-stopped",
            Restart::Other(policy) => policy,
        }
    }
}

impl From<&str> for Restart {
    /// The policy of that name (`Other` for any but the four constants).
    fn from(policy: &str) -> Self {
        match policy {
            "no" => Restart::No,
            "always" => Restart::Always,
            "on-failure" => Restart::OnFailure,
            "unless-stopped" => Restart::UnlessStopped,
            other => Restart::Other(other.to_owned()),
        }
    }
}

impl fmt::Display for Restart {
    /// Writes [`Restart::as_str`].
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.write_str(self.as_str())
    }
}

/// How to run a container: everything but the image and the name, each
/// empty or off by default.
///
/// ```
/// use utopia_orchestration::{Restart, RunOptions};
///
/// let options = RunOptions {
///     command: vec!["sh".into(), "-c".into(), "tail -f /dev/null".into()],
///     workdir: "/usr/local/src".into(),
///     vars: vec![("APP_ENV".into(), "production".into())],
///     labels: vec![("team".into(), "billing".into())],
///     restart: Restart::UnlessStopped,
///     ..RunOptions::default()
/// };
/// assert_eq!(options.restart.as_str(), "unless-stopped");
/// ```
///
/// ```php
/// use Utopia\Orchestration\Adapter;
///
/// // $orchestration->run($image, $name, ['sh', '-c', 'tail -f /dev/null'], workdir: '/usr/local/src',
/// //     vars: ['APP_ENV' => 'production'], labels: ['team' => 'billing'], restart: Adapter::RESTART_UNLESS_STOPPED);
/// echo Adapter::RESTART_UNLESS_STOPPED, "\n"; // unless-stopped
/// ```
///
/// PHP: the optional parameters of `run()`.
#[derive(Debug, Clone, Default, PartialEq, Eq)]
pub struct RunOptions {
    /// The command and its arguments (the image's when empty).
    pub command: Vec<String>,
    /// The entrypoint (the image's when empty).
    pub entrypoint: String,
    /// The working directory (the image's when empty).
    pub workdir: String,
    /// Volumes, as Docker writes them (`/host/path:/container/path:rw`).
    pub volumes: Vec<String>,
    /// Environment variables; names keep only `A-Za-z0-9_.-`.
    pub vars: Vec<(String, String)>,
    /// A host folder mounted at `/tmp`.
    pub mount_folder: String,
    /// Labels, besides the `<namespace>-type` and `<namespace>-created` ones
    /// every container gets.
    pub labels: Vec<(String, String)>,
    /// The hostname (Docker's when empty).
    pub hostname: String,
    /// Whether Docker removes the container when it exits.
    pub remove: bool,
    /// The network to start the container on (the default when empty).
    pub network: String,
    /// The restart policy.
    pub restart: Restart,
}
