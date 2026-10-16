//! PHP `Utopia\Database\Exception` and `Utopia\Database\Exception\*`.

/// PHP `Utopia\Database\Exception`.
#[derive(Debug, thiserror::Error)]
pub enum DatabaseError {
    #[error("{0}")]
    Database(String),
    #[error("{0}")]
    Structure(String),
    #[error("{0}")]
    Character(String),
    #[error("{0}")]
    Duplicate(String),
    #[error("{0}")]
    Unique(String),
    #[error("{0}")]
    Operator(String),
    #[error("{0}")]
    Restricted(String),
    #[error("{0}")]
    Authorization(String),
    #[error("{0}")]
    Truncate(String),
    #[error("{0}")]
    Query(String),
    #[error("{0}")]
    Timeout(String),
    #[error("{0}")]
    Transaction(String),
    #[error("{0}")]
    Limit(String),
    #[error("{message}")]
    Order {
        message: String,
        attribute: Option<String>,
    },
    #[error("{0}")]
    Index(String),
    #[error("{0}")]
    NotFound(String),
    #[error("{0}")]
    Relationship(String),
    #[error("{0}")]
    Conflict(String),
    #[error("{0}")]
    Type(String),
    #[error("{0}")]
    Dependency(String),
}

impl DatabaseError {
    pub fn database(message: impl Into<String>) -> Self {
        Self::Database(message.into())
    }

    pub fn structure(message: impl Into<String>) -> Self {
        Self::Structure(message.into())
    }

    pub fn duplicate(message: impl Into<String>) -> Self {
        Self::Duplicate(message.into())
    }

    pub fn unique(message: impl Into<String>) -> Self {
        Self::Unique(message.into())
    }

    pub fn operator(message: impl Into<String>) -> Self {
        Self::Operator(message.into())
    }

    pub fn query(message: impl Into<String>) -> Self {
        Self::Query(message.into())
    }

    pub fn not_found(message: impl Into<String>) -> Self {
        Self::NotFound(message.into())
    }

    pub fn authorization(message: impl Into<String>) -> Self {
        Self::Authorization(message.into())
    }

    pub fn limit(message: impl Into<String>) -> Self {
        Self::Limit(message.into())
    }

    pub fn index(message: impl Into<String>) -> Self {
        Self::Index(message.into())
    }

    pub fn relationship(message: impl Into<String>) -> Self {
        Self::Relationship(message.into())
    }

    pub fn conflict(message: impl Into<String>) -> Self {
        Self::Conflict(message.into())
    }

    pub fn timeout(message: impl Into<String>) -> Self {
        Self::Timeout(message.into())
    }

    pub fn transaction(message: impl Into<String>) -> Self {
        Self::Transaction(message.into())
    }

    pub fn restricted(message: impl Into<String>) -> Self {
        Self::Restricted(message.into())
    }

    pub fn order(message: impl Into<String>, attribute: Option<String>) -> Self {
        Self::Order {
            message: message.into(),
            attribute,
        }
    }

    pub fn message(&self) -> &str {
        match self {
            Self::Database(m)
            | Self::Structure(m)
            | Self::Character(m)
            | Self::Duplicate(m)
            | Self::Unique(m)
            | Self::Operator(m)
            | Self::Restricted(m)
            | Self::Authorization(m)
            | Self::Truncate(m)
            | Self::Query(m)
            | Self::Timeout(m)
            | Self::Transaction(m)
            | Self::Limit(m)
            | Self::Index(m)
            | Self::NotFound(m)
            | Self::Relationship(m)
            | Self::Conflict(m)
            | Self::Type(m)
            | Self::Dependency(m) => m,
            Self::Order { message, .. } => message,
        }
    }

    pub fn get_attribute(&self) -> Option<&str> {
        match self {
            Self::Order { attribute, .. } => attribute.as_deref(),
            _ => None,
        }
    }
}

impl From<String> for DatabaseError {
    fn from(value: String) -> Self {
        Self::Database(value)
    }
}

impl From<&str> for DatabaseError {
    fn from(value: &str) -> Self {
        Self::Database(value.to_owned())
    }
}

/// Convenience alias used throughout the crate.
pub type Result<T> = std::result::Result<T, DatabaseError>;
