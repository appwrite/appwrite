//! CLI helpers for Utopia.
//!
//! Rust port of [`utopia-php/console`](https://github.com/utopia-php/console).

mod command;
mod console;
mod error;

pub use command::{escape_shell_arg, from_validator, Command, CommandValidator};
pub use console::{ansi, Console, ExecuteInput};
pub use error::{CommandError, ConsoleError};
