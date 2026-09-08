use thiserror::Error;

#[derive(Debug, Error)]
pub enum PlatformError {
    #[error("service `{0}` not found")]
    ServiceNotFound(String),

    #[error("injection already declared for `{0}`")]
    DuplicateInjection(String),

    #[error("action has no callback")]
    MissingCallback,

    #[error("HTTP path is required for default actions")]
    MissingHttpPath,

    #[error("HTTP methods are required for default actions")]
    MissingHttpMethods,

    #[error("unsupported initialization type: {0}")]
    UnsupportedInitType(String),

    #[error(
        "feature `{0}` is not enabled; enable the Cargo feature or use `init_http` / `init_cli`"
    )]
    FeatureNotEnabled(&'static str),

    #[cfg(feature = "http")]
    #[error(transparent)]
    Http(#[from] utopia_http::HttpError),

    #[cfg(feature = "cli")]
    #[error(transparent)]
    Cli(#[from] utopia_cli::CliError),

    #[error("{0}")]
    Other(String),
}

pub type Result<T> = std::result::Result<T, PlatformError>;
