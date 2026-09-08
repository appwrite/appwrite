use thiserror::Error;

#[derive(Debug, Error, Clone, PartialEq, Eq)]
pub enum HookError {
    #[error("Injection already declared for {0}")]
    DuplicateInjection(String),
    #[error("Unknown key")]
    UnknownKey,
}
