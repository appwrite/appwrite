//! The [`Adapter`] trait every orchestrator implements, and the
//! [`Settings`] they share.

use crate::{BoxFuture, Container, Error, Network, RunOptions, Stats};

/// What every container gets from its orchestrator: the namespace of the
/// labels it is given, and its CPU, memory and swap limits.
///
/// PHP: the protected `$namespace`, `$cpus`, `$memory` and `$swap` of
/// `Utopia\Orchestration\Adapter`, set through `setNamespace()`,
/// `setCpus()`, `setMemory()` and `setSwap()`.
#[derive(Debug, Clone, PartialEq)]
pub struct Settings {
    /// Prefix of the `<namespace>-type` and `<namespace>-created` labels.
    pub namespace: String,
    /// CPU cores a container may use (0: no limit).
    pub cpus: f64,
    /// Megabytes of memory a container may use (0: no limit).
    pub memory: i64,
    /// Megabytes of memory and swap together (0: Docker's default).
    pub swap: i64,
}

impl Default for Settings {
    /// Namespace `utopia`, no limits.
    fn default() -> Self {
        Settings { namespace: "utopia".into(), cpus: 0.0, memory: 0, swap: 0 }
    }
}

/// Runs containers and networks on a container engine. Implement it to add
/// an engine; [`DockerCli`](crate::DockerCli) and
/// [`DockerApi`](crate::DockerApi) are Docker's.
///
/// The futures borrow their arguments, so the trait is object safe.
///
/// PHP: `Utopia\Orchestration\Adapter`.
pub trait Adapter: Send + Sync {
    /// The settings containers are run with.
    fn settings(&self) -> &Settings;

    /// The settings, to change them.
    fn settings_mut(&mut self) -> &mut Settings;

    /// Creates a network, internal (no outside access) when asked.
    ///
    /// PHP: `createNetwork($name, $internal)`.
    fn create_network<'a>(&'a self, name: &'a str, internal: bool) -> BoxFuture<'a, Result<bool, Error>>;

    /// Removes a network.
    ///
    /// PHP: `removeNetwork($name)`.
    fn remove_network<'a>(&'a self, name: &'a str) -> BoxFuture<'a, Result<bool, Error>>;

    /// Connects a container to a network.
    ///
    /// PHP: `networkConnect($container, $network)`.
    fn network_connect<'a>(&'a self, container: &'a str, network: &'a str) -> BoxFuture<'a, Result<bool, Error>>;

    /// Disconnects a container from a network, forcibly when asked.
    ///
    /// PHP: `networkDisconnect($container, $network, $force)`.
    fn network_disconnect<'a>(
        &'a self,
        container: &'a str,
        network: &'a str,
        force: bool,
    ) -> BoxFuture<'a, Result<bool, Error>>;

    /// Whether a network of that name exists.
    ///
    /// PHP: `networkExists($name)`.
    fn network_exists<'a>(&'a self, name: &'a str) -> BoxFuture<'a, Result<bool, Error>>;

    /// Every network.
    ///
    /// PHP: `listNetworks()`.
    fn list_networks<'a>(&'a self) -> BoxFuture<'a, Result<Vec<Network>, Error>>;

    /// The usage of one container, or of every container the filters
    /// (Docker's `key=value` filters) select.
    ///
    /// PHP: `getStats($container, $filters)`.
    fn stats<'a>(
        &'a self,
        container: Option<&'a str>,
        filters: &'a [(String, String)],
    ) -> BoxFuture<'a, Result<Vec<Stats>, Error>>;

    /// Pulls an image; whether it was pulled.
    ///
    /// PHP: `pull($image)`.
    fn pull<'a>(&'a self, image: &'a str) -> BoxFuture<'a, Result<bool, Error>>;

    /// Every container (running or not) the filters select.
    ///
    /// PHP: `list($filters)`.
    fn list<'a>(&'a self, filters: &'a [(String, String)]) -> BoxFuture<'a, Result<Vec<Container>, Error>>;

    /// Creates and starts a container; its ID.
    ///
    /// PHP: `run($image, $name, $command, $entrypoint, $workdir, $volumes,
    /// $vars, $mountFolder, $labels, $hostname, $remove, $network, $restart)`.
    fn run<'a>(
        &'a self,
        image: &'a str,
        name: &'a str,
        options: &'a RunOptions,
    ) -> BoxFuture<'a, Result<String, Error>>;

    /// Runs a command in a container, with these environment variables and
    /// a timeout in seconds (none when not positive). `output` is set to
    /// what it printed, also when it fails once it ran.
    ///
    /// PHP: `execute($name, $command, &$output, $vars, $timeout)`.
    fn execute<'a>(
        &'a self,
        name: &'a str,
        command: &'a [String],
        output: &'a mut Vec<u8>,
        vars: &'a [(String, String)],
        timeout: i64,
    ) -> BoxFuture<'a, Result<bool, Error>>;

    /// Removes a container, forcibly (a running one) when asked.
    ///
    /// PHP: `remove($name, $force)`.
    fn remove<'a>(&'a self, name: &'a str, force: bool) -> BoxFuture<'a, Result<bool, Error>>;
}

/// An environment variable name as Docker is given it: every byte but
/// `A-Za-z0-9_.-` dropped.
///
/// ```
/// use utopia_orchestration::filter_env_key;
///
/// assert_eq!(filter_env_key("APP ENV!"), "APPENV");
/// assert_eq!(filter_env_key("_APP_REDIS.HOST-1"), "_APP_REDIS.HOST-1");
/// ```
///
/// ```php
/// use Utopia\Orchestration\Adapter\DockerCLI;
///
/// echo (new DockerCLI())->filterEnvKey('APP ENV!'), "\n"; // APPENV
/// ```
///
/// PHP: `Adapter::filterEnvKey()`.
pub fn filter_env_key(key: &str) -> String {
    let allowed = |b: &u8| b.is_ascii_alphanumeric() || matches!(b, b'_' | b'.' | b'-');
    if key.bytes().all(|b| allowed(&b)) {
        return key.to_owned();
    }
    // Only ASCII bytes are kept, so the result is valid UTF-8.
    key.bytes().filter(allowed).map(char::from).collect()
}

impl<T: Adapter + ?Sized> Adapter for Box<T> {
    fn settings(&self) -> &Settings {
        (**self).settings()
    }

    fn settings_mut(&mut self) -> &mut Settings {
        (**self).settings_mut()
    }

    /// Creates through the boxed adapter.
    fn create_network<'a>(&'a self, name: &'a str, internal: bool) -> BoxFuture<'a, Result<bool, Error>> {
        (**self).create_network(name, internal)
    }

    /// Removes through the boxed adapter.
    fn remove_network<'a>(&'a self, name: &'a str) -> BoxFuture<'a, Result<bool, Error>> {
        (**self).remove_network(name)
    }

    /// Connects through the boxed adapter.
    fn network_connect<'a>(&'a self, container: &'a str, network: &'a str) -> BoxFuture<'a, Result<bool, Error>> {
        (**self).network_connect(container, network)
    }

    /// Disconnects through the boxed adapter.
    fn network_disconnect<'a>(
        &'a self,
        container: &'a str,
        network: &'a str,
        force: bool,
    ) -> BoxFuture<'a, Result<bool, Error>> {
        (**self).network_disconnect(container, network, force)
    }

    /// Asks the boxed adapter.
    fn network_exists<'a>(&'a self, name: &'a str) -> BoxFuture<'a, Result<bool, Error>> {
        (**self).network_exists(name)
    }

    /// Lists through the boxed adapter.
    fn list_networks<'a>(&'a self) -> BoxFuture<'a, Result<Vec<Network>, Error>> {
        (**self).list_networks()
    }

    /// Asks the boxed adapter.
    fn stats<'a>(
        &'a self,
        container: Option<&'a str>,
        filters: &'a [(String, String)],
    ) -> BoxFuture<'a, Result<Vec<Stats>, Error>> {
        (**self).stats(container, filters)
    }

    /// Pulls through the boxed adapter.
    fn pull<'a>(&'a self, image: &'a str) -> BoxFuture<'a, Result<bool, Error>> {
        (**self).pull(image)
    }

    /// Lists through the boxed adapter.
    fn list<'a>(&'a self, filters: &'a [(String, String)]) -> BoxFuture<'a, Result<Vec<Container>, Error>> {
        (**self).list(filters)
    }

    /// Runs through the boxed adapter.
    fn run<'a>(
        &'a self,
        image: &'a str,
        name: &'a str,
        options: &'a RunOptions,
    ) -> BoxFuture<'a, Result<String, Error>> {
        (**self).run(image, name, options)
    }

    /// Executes through the boxed adapter.
    fn execute<'a>(
        &'a self,
        name: &'a str,
        command: &'a [String],
        output: &'a mut Vec<u8>,
        vars: &'a [(String, String)],
        timeout: i64,
    ) -> BoxFuture<'a, Result<bool, Error>> {
        (**self).execute(name, command, output, vars, timeout)
    }

    /// Removes through the boxed adapter.
    fn remove<'a>(&'a self, name: &'a str, force: bool) -> BoxFuture<'a, Result<bool, Error>> {
        (**self).remove(name, force)
    }
}
