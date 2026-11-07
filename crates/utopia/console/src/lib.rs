//! Console helpers for command-line programs: coloured log lines, prompts,
//! the process title, shell-safe [`Command`]s, running commands with
//! [`execute`], and repeating work with [`repeat`].
//!
//! [`Command`] builds a command line word by word, validating each one, and
//! never by concatenating strings: a plain command runs without a shell, and
//! pipelines, `&&`/`||` lists, groups and redirections render with every
//! word quoted. [`execute`] runs a command with an input, collects its
//! output (reporting progress as it arrives) and kills it after a timeout.
//!
//! PHP: ports `utopia-php/console`.
//!
//! | PHP | Rust |
//! |---|---|
//! | `Utopia\Console\Console::log/success/error/info/warning()` | [`log`], [`success`], [`error`], [`info`], [`warning`], or [`Console`] over any streams |
//! | `Console::confirm()` | [`confirm`], [`Console::confirm`] |
//! | `Console::title()`, `exit()`, `isInteractive()` | [`title`], [`exit`], [`is_interactive`] |
//! | `Console::execute()` | [`execute`] with a [`Program`], returning an [`Output`] |
//! | `Console::loop()` | [`repeat`] |
//! | `Utopia\Console\Command` | [`Command`] ([`Check`], [`Arg`], [`Operator`], [`Redirect`]) |
//! | `Utopia\Console` and `Utopia\Command` (aliases of the old names) | the same items |
//! | `\InvalidArgumentException`, `\ValueError` | [`Error`] |
//!
//! ```
//! use utopia_console::{Command, Console};
//!
//! let deploy = Command::and(vec![
//!     Command::group(Command::or(vec![Command::new("build")?, Command::new("build:fallback")?])?),
//!     Command::new("publish")?,
//! ])?;
//! assert_eq!(deploy.to_shell()?, "( 'build' || 'build:fallback' ) && 'publish'");
//!
//! let mut console = Console::new(Vec::new(), Vec::new());
//! assert_eq!(console.info("deploying")?, 19);
//! # Ok::<(), Box<dyn std::error::Error>>(())
//! ```
//!
//! ```php
//! use Utopia\Console\Command;
//! use Utopia\Console\Console;
//!
//! $deploy = Command::and(
//!     Command::group(Command::or(new Command('build'), new Command('build:fallback'))),
//!     new Command('publish'),
//! );
//! echo $deploy, "\n"; // ( 'build' || 'build:fallback' ) && 'publish'
//!
//! Console::info('deploying'); // 19 bytes, blue, on STDOUT
//! ```

#![warn(missing_docs)]

mod command;
mod console;
mod execute;

pub use command::{Arg, Check, Command, Operator, Redirect};
pub use console::{Console, confirm, error, exit, info, is_interactive, log, repeat, success, title, warning};
pub use execute::{Output, Program, Progress, execute};

/// The getting started guide (`guide.md`), compiled and run with the doctests.
#[cfg(doctest)]
#[doc = include_str!("../guide.md")]
pub struct GettingStarted;

/// Why a command could not be built or run.
///
/// PHP: the exceptions `utopia-php/console` throws, one variant per class.
#[derive(Debug, Clone, PartialEq, Eq, thiserror::Error)]
pub enum Error {
    /// A command was built with an invalid flag, option, argument or
    /// redirect target, or converted in a way its kind does not allow.
    ///
    /// PHP: `\InvalidArgumentException`.
    #[error("{0}")]
    InvalidArgument(String),
    /// A word cannot be quoted (it holds a NUL byte), or an empty argument
    /// list was given to [`execute`].
    ///
    /// PHP: `\ValueError` from `escapeshellarg()` or `proc_open()`.
    #[error("{0}")]
    Value(String),
}

impl Error {
    /// The name of the PHP exception class this error matches.
    pub fn php_class(&self) -> &'static str {
        match self {
            Error::InvalidArgument(_) => "InvalidArgumentException",
            Error::Value(_) => "ValueError",
        }
    }
}
