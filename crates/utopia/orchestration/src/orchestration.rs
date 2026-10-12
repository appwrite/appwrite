//! [`Orchestration`], the front over an adapter, and [`parse_command`].

use crate::{Adapter, Container, Error, Network, RunOptions, Stats};

/// Runs containers and networks through an [`Adapter`].
///
/// ```no_run
/// use utopia_orchestration::{DockerCli, Orchestration, RunOptions};
///
/// # async fn run() -> Result<(), utopia_orchestration::Error> {
/// let mut orchestration = Orchestration::new(DockerCli::new());
/// orchestration.set_namespace("appwrite").set_cpus(1.0).set_memory(512);
///
/// orchestration.pull("alpine:3.20").await?;
/// let options = RunOptions { command: vec!["sleep".into(), "60".into()], ..RunOptions::default() };
/// let id = orchestration.run("alpine:3.20", "worker-1", &options).await?;
///
/// let mut output = Vec::new();
/// orchestration.execute("worker-1", &["echo".into(), "hello".into()], &mut output, &[], 10).await?;
/// assert_eq!(output, b"hello\n");
/// orchestration.remove(&id, true).await?;
/// # Ok(())
/// # }
/// ```
///
/// ```php
/// use Utopia\Orchestration\Adapter\DockerCLI;
/// use Utopia\Orchestration\Orchestration;
///
/// $orchestration = new Orchestration(new DockerCLI());
/// $orchestration->setNamespace('appwrite')->setCpus(1)->setMemory(512);
/// // $orchestration->pull('alpine:3.20');
/// // $id = $orchestration->run('alpine:3.20', 'worker-1', ['sleep', '60']);
/// // $orchestration->execute('worker-1', ['echo', 'hello'], $output, [], 10);
/// // $orchestration->remove($id, true);
/// ```
///
/// PHP: `Utopia\Orchestration\Orchestration`.
#[derive(Debug, Clone)]
pub struct Orchestration<A> {
    adapter: A,
}

impl<A: Adapter> Orchestration<A> {
    /// An orchestrator over `adapter`.
    ///
    /// PHP: `new Orchestration($adapter)`.
    pub fn new(adapter: A) -> Self {
        Orchestration { adapter }
    }

    /// The adapter.
    pub fn adapter(&self) -> &A {
        &self.adapter
    }

    /// Prefixes the `-type` and `-created` labels every container gets.
    ///
    /// PHP: `setNamespace($namespace)`.
    pub fn set_namespace(&mut self, namespace: impl Into<String>) -> &mut Self {
        self.adapter.settings_mut().namespace = namespace.into();
        self
    }

    /// The CPU cores a container may use (0: no limit).
    ///
    /// PHP: `setCpus($cores)`.
    pub fn set_cpus(&mut self, cores: f64) -> &mut Self {
        self.adapter.settings_mut().cpus = cores;
        self
    }

    /// The megabytes of memory a container may use (0: no limit).
    ///
    /// PHP: `setMemory($mb)`.
    pub fn set_memory(&mut self, mb: i64) -> &mut Self {
        self.adapter.settings_mut().memory = mb;
        self
    }

    /// The megabytes of memory and swap a container may use together.
    ///
    /// PHP: `setSwap($mb)`.
    pub fn set_swap(&mut self, mb: i64) -> &mut Self {
        self.adapter.settings_mut().swap = mb;
        self
    }

    /// See [`Adapter::create_network`].
    ///
    /// PHP: `createNetwork($name, $internal)`.
    pub async fn create_network(&self, name: &str, internal: bool) -> Result<bool, Error> {
        self.adapter.create_network(name, internal).await
    }

    /// See [`Adapter::remove_network`].
    ///
    /// PHP: `removeNetwork($name)`.
    pub async fn remove_network(&self, name: &str) -> Result<bool, Error> {
        self.adapter.remove_network(name).await
    }

    /// See [`Adapter::list_networks`].
    ///
    /// PHP: `listNetworks()`.
    pub async fn list_networks(&self) -> Result<Vec<Network>, Error> {
        self.adapter.list_networks().await
    }

    /// See [`Adapter::network_connect`].
    ///
    /// PHP: `networkConnect($container, $network)`.
    pub async fn network_connect(&self, container: &str, network: &str) -> Result<bool, Error> {
        self.adapter.network_connect(container, network).await
    }

    /// See [`Adapter::network_disconnect`].
    ///
    /// PHP: `networkDisconnect($container, $network, $force)`.
    pub async fn network_disconnect(&self, container: &str, network: &str, force: bool) -> Result<bool, Error> {
        self.adapter.network_disconnect(container, network, force).await
    }

    /// See [`Adapter::network_exists`].
    ///
    /// PHP: `networkExists($name)`.
    pub async fn network_exists(&self, name: &str) -> Result<bool, Error> {
        self.adapter.network_exists(name).await
    }

    /// See [`Adapter::stats`].
    ///
    /// PHP: `getStats($container, $filters)`.
    pub async fn stats(&self, container: Option<&str>, filters: &[(String, String)]) -> Result<Vec<Stats>, Error> {
        self.adapter.stats(container, filters).await
    }

    /// See [`Adapter::pull`].
    ///
    /// PHP: `pull($image)`.
    pub async fn pull(&self, image: &str) -> Result<bool, Error> {
        self.adapter.pull(image).await
    }

    /// See [`Adapter::list`].
    ///
    /// PHP: `list($filters)`.
    pub async fn list(&self, filters: &[(String, String)]) -> Result<Vec<Container>, Error> {
        self.adapter.list(filters).await
    }

    /// See [`Adapter::run`].
    ///
    /// PHP: `run($image, $name, ...)`.
    pub async fn run(&self, image: &str, name: &str, options: &RunOptions) -> Result<String, Error> {
        self.adapter.run(image, name, options).await
    }

    /// See [`Adapter::execute`].
    ///
    /// PHP: `execute($name, $command, &$output, $vars, $timeout)`.
    pub async fn execute(
        &self,
        name: &str,
        command: &[String],
        output: &mut Vec<u8>,
        vars: &[(String, String)],
        timeout: i64,
    ) -> Result<bool, Error> {
        self.adapter.execute(name, command, output, vars, timeout).await
    }

    /// See [`Adapter::remove`].
    ///
    /// PHP: `remove($name, $force)`.
    pub async fn remove(&self, name: &str, force: bool) -> Result<bool, Error> {
        self.adapter.remove(name, force).await
    }
}

/// Splits a command line at spaces, keeping a `'`-quoted argument (which
/// must follow a space) whole, quotes included, so a script can be passed
/// to `sh -c`. A line without a space is one argument.
///
/// Fails with [`Error::Command`] when a quote is not closed.
///
/// The pieces are slices of `command`. Kept from PHP: the piece after a
/// quoted argument is cut by an offset used as a length, and a final piece
/// that is `0` is dropped.
///
/// ```
/// use utopia_orchestration::parse_command;
///
/// let args = parse_command(b"sh -c 'echo hello && sleep 1'")?;
/// assert_eq!(args, [&b"sh"[..], b"-c", b"'echo hello && sleep 1'"]);
/// assert!(parse_command(b"sh -c 'unclosed").is_err());
/// # Ok::<(), utopia_orchestration::Error>(())
/// ```
///
/// ```php
/// use Utopia\Orchestration\Adapter\DockerCLI;
/// use Utopia\Orchestration\Orchestration;
///
/// $args = (new Orchestration(new DockerCLI()))->parseCommandString("sh -c 'echo hello && sleep 1'");
/// echo json_encode($args), "\n"; // ["sh","-c","'echo hello && sleep 1'"]
/// ```
///
/// PHP: `Orchestration::parseCommandString()`.
pub fn parse_command(command: &[u8]) -> Result<Vec<&[u8]>, Error> {
    let find = |needle: u8, from: usize| {
        command.get(from..).and_then(|rest| rest.iter().position(|&b| b == needle)).map(|i| from + i)
    };
    // substr() with a start and a length that are never negative here.
    let substr = |start: usize, length: usize| {
        let start = start.min(command.len());
        &command[start..(start + length).min(command.len())]
    };
    let mut current = 0;
    let mut pieces = Vec::with_capacity(4);
    // The next space at or after `current`: PHP searches it twice per
    // piece, once to end the loop and once for the last piece.
    let mut next = find(b' ', current);
    if next.is_none() {
        return Ok(vec![command]);
    }
    while let Some(space) = next {
        let mut place = space;
        pieces.push(substr(current, place - current));
        if command.get(place + 1) != Some(&b'\'') {
            place += 1;
        } else {
            let Some(closing) = find(b'\'', place + 2).filter(|&c| c != 0) else {
                return Err(Error::Command("Invalid Command given, are you missing an `'` at the end?".into()));
            };
            pieces.push(substr(place + 1, closing));
            place = closing + 1;
        }
        next = find(b' ', place);
        if next.is_none() {
            let rest = substr(place, command.len() - current);
            if !rest.is_empty() && rest != b"0" {
                pieces.push(rest);
            }
        }
        current = place;
    }
    Ok(pieces)
}
