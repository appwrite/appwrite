use thiserror::Error;

pub type Result<T> = std::result::Result<T, HttpError>;

#[derive(Debug, Error)]
pub enum HttpError {
    #[error("{message}")]
    App { status: u16, message: String },
    #[error("Param \"{0}\" is not optional.")]
    MissingParam(String),
    #[error("Invalid `{key}` param: {description}")]
    InvalidParam { key: String, description: String },
    #[error("Route for ({method}:{path}) already registered.")]
    DuplicateRoute { method: String, path: String },
    #[error("Method ({0}) not supported.")]
    UnsupportedMethod(String),
    #[error("At least one HTTP method is required.")]
    EmptyMethods,
    #[error("Unknown HTTP status code")]
    UnknownStatus,
    #[error("Injection already declared for {0}")]
    DuplicateInjection(String),
    #[error(transparent)]
    Di(#[from] utopia_di::ContainerError),
    #[error(transparent)]
    Io(#[from] std::io::Error),
    #[error("{0}")]
    Other(String),
}

impl HttpError {
    pub fn status(&self) -> u16 {
        match self {
            Self::App { status, .. } => *status,
            Self::MissingParam(_) | Self::InvalidParam { .. } => 400,
            _ => 500,
        }
    }

    pub fn not_found() -> Self {
        Self::App {
            status: 404,
            message: "Not Found".into(),
        }
    }

    pub fn app(status: u16, message: impl Into<String>) -> Self {
        Self::App {
            status,
            message: message.into(),
        }
    }
}
