use thiserror::Error;

/// Errors raised while building or converting [`crate::Command`] values.
#[derive(Debug, Error, PartialEq, Eq)]
pub enum CommandError {
    #[error("Only plain commands can be converted to an array")]
    NotPlain,

    #[error("Composed commands require at least two commands")]
    CompositeTooFew,

    #[error("Flags, options, and arguments can only be added to plain commands")]
    NotPlainMutation,

    #[error("Invalid command flag: {0}")]
    InvalidFlag(String),

    #[error("Invalid command option: {0}")]
    InvalidOption(String),

    #[error("{context} cannot be empty")]
    EmptyValue { context: &'static str },

    #[error("Invalid command argument: {value}")]
    InvalidArgument { value: String },

    #[error("Invalid command argument: {value} ({description})")]
    InvalidArgumentWithDescription { value: String, description: String },

    #[error("Unsupported command type")]
    UnsupportedType,
}

/// Errors raised while executing external commands.
#[derive(Debug, Error)]
pub enum ConsoleError {
    #[error("failed to spawn process: {0}")]
    Spawn(#[from] std::io::Error),

    #[error("failed to write process stdin")]
    StdinWrite,

    #[error("failed to read process output")]
    OutputRead,
}
