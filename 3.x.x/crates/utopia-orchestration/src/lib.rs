//! Container orchestration for Utopia.
//!
//! Rust port of [`utopia-php/orchestration`](https://github.com/utopia-php/orchestration).

mod adapter;
mod docker_api;
mod docker_cli;
mod error;
mod http;
mod models;
mod orchestration;
mod php;

pub use adapter::{Adapter, AdapterSettings};
pub use docker_api::DockerAPI;
pub use docker_cli::DockerCLI;
pub use error::OrchestrationError;
pub use models::{Container, Network, Stats};
pub use orchestration::Orchestration;
pub use php::{filter_env_key, parse_command_string, parse_io_stats};

/// Restart policy constants (PHP `Adapter::RESTART_*`).
pub mod restart {
    pub const NO: &str = "no";
    pub const ALWAYS: &str = "always";
    pub const ON_FAILURE: &str = "on-failure";
    pub const UNLESS_STOPPED: &str = "unless-stopped";
}

/// Prelude for common orchestration types.
pub mod prelude {
    pub use crate::{
        Adapter, Container, DockerAPI, DockerCLI, Network, Orchestration, OrchestrationError, Stats,
    };
}
