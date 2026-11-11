//! Container orchestration: pull images, run containers with limits,
//! labels, volumes and networks, execute commands in them, read their
//! usage, and remove them, on Docker through its command-line client
//! ([`DockerCli`]) or its Engine API ([`DockerApi`]).
//!
//! [`Orchestration`] fronts an [`Adapter`] and holds the [`Settings`]
//! every container gets: a label namespace and CPU, memory and swap
//! limits.
//!
//! ```no_run
//! use utopia_orchestration::{DockerApi, Orchestration, RunOptions};
//!
//! # async fn run() -> Result<(), utopia_orchestration::Error> {
//! let mut orchestration = Orchestration::new(DockerApi::new());
//! orchestration.set_namespace("executor").set_memory(256);
//!
//! let options = RunOptions {
//!     command: vec!["sh".into(), "-c".into(), "tail -f /dev/null".into()],
//!     labels: vec![("function".into(), "resize-images".into())],
//!     ..RunOptions::default()
//! };
//! orchestration.run("alpine:3.20", "runtime-1", &options).await?;
//! let mut output = Vec::new();
//! orchestration.execute("runtime-1", &["uname".into()], &mut output, &[], 10).await?;
//! orchestration.remove("runtime-1", true).await?;
//! # Ok(())
//! # }
//! ```
//!
//! ```php
//! use Utopia\Orchestration\Adapter\DockerAPI;
//! use Utopia\Orchestration\Orchestration;
//!
//! $orchestration = new Orchestration(new DockerAPI());
//! $orchestration->setNamespace('executor')->setMemory(256);
//! // $orchestration->run('alpine:3.20', 'runtime-1', ['sh', '-c', 'tail -f /dev/null'], labels: ['function' => 'resize-images']);
//! // $orchestration->execute('runtime-1', ['uname'], $output, [], 10);
//! // $orchestration->remove('runtime-1', true);
//! ```
//!
//! PHP: ports `utopia-php/orchestration`.
//!
//! | PHP | Rust |
//! |---|---|
//! | `Utopia\Orchestration\Orchestration` | [`Orchestration`] |
//! | `createNetwork()`, `removeNetwork()`, `listNetworks()`, `networkConnect()`, `networkDisconnect()`, `networkExists()` | [`Orchestration::create_network`], [`Orchestration::remove_network`], [`Orchestration::list_networks`], [`Orchestration::network_connect`], [`Orchestration::network_disconnect`], [`Orchestration::network_exists`] |
//! | `getStats()`, `pull()`, `list()`, `run()`, `execute()`, `remove()` | [`Orchestration::stats`], [`Orchestration::pull`], [`Orchestration::list`], [`Orchestration::run`] (with [`RunOptions`]), [`Orchestration::execute`], [`Orchestration::remove`] |
//! | `setNamespace()`, `setCpus()`, `setMemory()`, `setSwap()` | [`Orchestration::set_namespace`], [`Orchestration::set_cpus`], [`Orchestration::set_memory`], [`Orchestration::set_swap`] ([`Settings`]) |
//! | `parseCommandString()` | [`parse_command`] |
//! | `Utopia\Orchestration\Adapter` | [`Adapter`] |
//! | `Adapter::filterEnvKey()` | [`filter_env_key`] |
//! | `Adapter::RESTART_*` | [`Restart`] |
//! | `Adapter\DockerCLI` (`new DockerCLI($username, $password)`) | [`DockerCli`] ([`DockerCli::login`]) |
//! | `Adapter\DockerAPI` (`new DockerAPI($username, $password, $email)`) | [`DockerApi`] ([`DockerApi::with_credentials`]) |
//! | `Utopia\Orchestration\Container` | [`Container`] |
//! | `Utopia\Orchestration\Network` | [`Network`] |
//! | `Utopia\Orchestration\Container\Stats` | [`Stats`] ([`Io`] for the `in`/`out` arrays) |
//! | `Exception\Orchestration`, `Exception\Timeout` | [`Error::Orchestration`], [`Error::Timeout`] |
//!
//! PHP's `execute()` writes the output to a reference; here it is a
//! `&mut Vec<u8>`, set whether or not the command succeeds.
#![warn(missing_docs)]

mod adapter;
mod api;
mod cli;
mod error;
mod model;
mod orchestration;

use std::future::Future;
use std::pin::Pin;

pub use adapter::{Adapter, Settings, filter_env_key};
pub use api::DockerApi;
pub use cli::DockerCli;
pub use error::Error;
pub use model::{Container, Io, Network, Restart, RunOptions, Stats};
pub use orchestration::{Orchestration, parse_command};

/// A boxed future, as the [`Adapter`] methods return (so the trait is
/// object safe).
pub type BoxFuture<'a, T> = Pin<Box<dyn Future<Output = T> + Send + 'a>>;

/// The getting started guide (`guide.md`), compiled and run with the doctests.
#[cfg(doctest)]
#[doc = include_str!("../guide.md")]
pub struct GettingStarted;
