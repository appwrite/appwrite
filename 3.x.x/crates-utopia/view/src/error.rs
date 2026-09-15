use thiserror::Error;

/// Errors raised by [`crate::View`].
#[derive(Debug, Error, Clone, PartialEq, Eq)]
pub enum ViewError {
    /// PHP: `$key can't contain a dot "." character`
    #[error("$key can't contain a dot \".\" character")]
    DottedKey,

    /// PHP: `Filter "{name}" is not registered`
    #[error("Filter \"{name}\" is not registered")]
    FilterNotRegistered {
        /// Filter name that was requested.
        name: String,
    },

    /// PHP: `"{path}" view template is not readable`
    #[error("\"{path}\" view template is not readable")]
    TemplateNotReadable {
        /// Template path that failed [`std::fs`] readability checks.
        path: String,
    },

    /// Template parse / evaluation error (Rust interpreter; PHP would surface a parse error).
    #[error("{0}")]
    Template(String),
}
