use serde_json::Value;
use thiserror::Error;

/// PHP `Utopia\Pay\Exception`.
#[derive(Debug, Error)]
pub enum PayError {
    /// Typed Stripe/gateway error (PHP `Exception` with `$type`).
    #[error("{message}")]
    Gateway {
        r#type: String,
        message: String,
        code: i32,
        metadata: Value,
    },
    /// PHP `\InvalidArgumentException`.
    #[error("{0}")]
    InvalidArgument(String),
    /// Transport / JSON failures.
    #[error("{0}")]
    Message(String),
}

impl PayError {
    pub const GENERAL_UNKNOWN: &'static str = "general_unknown";
    pub const AUTHENTICATION_REQUIRED: &'static str = "authentication_required";
    pub const INSUFFICIENT_FUNDS: &'static str = "insufficient_funds";
    pub const INCORRECT_NUMBER: &'static str = "incorrect_number";
    pub const GENERIC_DECLINE: &'static str = "generic_decline";

    #[must_use]
    pub fn gateway(
        r#type: impl Into<String>,
        message: impl Into<String>,
        code: i32,
        metadata: Value,
    ) -> Self {
        Self::Gateway {
            r#type: r#type.into(),
            message: message.into(),
            code,
            metadata,
        }
    }

    #[must_use]
    pub fn get_type(&self) -> &str {
        match self {
            Self::Gateway { r#type, .. } => r#type,
            _ => Self::GENERAL_UNKNOWN,
        }
    }

    #[must_use]
    pub fn get_code(&self) -> i32 {
        match self {
            Self::Gateway { code, .. } => *code,
            _ => 500,
        }
    }

    #[must_use]
    pub fn get_metadata(&self) -> Value {
        match self {
            Self::Gateway { metadata, .. } => metadata.clone(),
            _ => Value::Object(serde_json::Map::new()),
        }
    }
}
