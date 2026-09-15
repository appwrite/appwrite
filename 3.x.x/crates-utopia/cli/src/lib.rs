//! Command-line task runner for Utopia.
//!
//! Rust port of [`utopia-php/cli`](https://github.com/utopia-php/cli).

mod adapter;
pub mod adapters;
mod cli;
mod error;
mod params;
mod task;

pub use adapter::{Adapter, WorkerCallback};
pub use cli::{camel_case_it, Cli};
pub use error::CliError;
pub use params::{ArgValue, BoundArg, Params};
pub use task::{ActionFn, CliHook, Task};

/// Prelude for common CLI types.
pub mod prelude {
    pub use crate::adapters::{Generic, Swoole};
    pub use crate::{Adapter, ArgValue, BoundArg, Cli, CliError, CliHook, Params, Task};
}
