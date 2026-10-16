//! Query exceptions. PHP `Utopia\Query\Exception` and subclasses.

/// PHP `Utopia\Query\Exception`.
#[derive(Debug, thiserror::Error)]
pub enum QueryError {
    #[error("{message}")]
    Exception {
        message: String,
        code: i32,
        #[source]
        source: Option<Box<QueryError>>,
    },
    #[error("{message}")]
    Validation {
        message: String,
        code: i32,
        #[source]
        source: Option<Box<QueryError>>,
    },
    #[error("{message}")]
    Unsupported {
        message: String,
        code: i32,
        #[source]
        source: Option<Box<QueryError>>,
    },
}

impl QueryError {
    pub fn exception(message: impl Into<String>) -> Self {
        Self::Exception {
            message: message.into(),
            code: 0,
            source: None,
        }
    }

    pub fn exception_with_code(message: impl Into<String>, code: impl IntoExceptionCode) -> Self {
        Self::Exception {
            message: message.into(),
            code: code.into_exception_code(),
            source: None,
        }
    }

    pub fn exception_with_previous(
        message: impl Into<String>,
        code: impl IntoExceptionCode,
        previous: QueryError,
    ) -> Self {
        Self::Exception {
            message: message.into(),
            code: code.into_exception_code(),
            source: Some(Box::new(previous)),
        }
    }

    pub fn validation(message: impl Into<String>) -> Self {
        Self::Validation {
            message: message.into(),
            code: 0,
            source: None,
        }
    }

    pub fn unsupported(message: impl Into<String>) -> Self {
        Self::Unsupported {
            message: message.into(),
            code: 0,
            source: None,
        }
    }

    pub fn get_message(&self) -> &str {
        match self {
            Self::Exception { message, .. }
            | Self::Validation { message, .. }
            | Self::Unsupported { message, .. } => message,
        }
    }

    pub fn get_code(&self) -> i32 {
        match self {
            Self::Exception { code, .. }
            | Self::Validation { code, .. }
            | Self::Unsupported { code, .. } => *code,
        }
    }

    pub fn get_previous(&self) -> Option<&QueryError> {
        match self {
            Self::Exception { source, .. }
            | Self::Validation { source, .. }
            | Self::Unsupported { source, .. } => source.as_deref(),
        }
    }

    pub fn is_validation(&self) -> bool {
        matches!(self, Self::Validation { .. })
    }

    pub fn is_unsupported(&self) -> bool {
        matches!(self, Self::Unsupported { .. })
    }
}

/// PHP constructor `$code` is `int|string`.
pub trait IntoExceptionCode {
    fn into_exception_code(self) -> i32;
}

impl IntoExceptionCode for i32 {
    fn into_exception_code(self) -> i32 {
        self
    }
}

impl IntoExceptionCode for u32 {
    fn into_exception_code(self) -> i32 {
        i32::try_from(self).unwrap_or(0)
    }
}

impl IntoExceptionCode for i64 {
    fn into_exception_code(self) -> i32 {
        i32::try_from(self).unwrap_or(0)
    }
}

impl IntoExceptionCode for &str {
    fn into_exception_code(self) -> i32 {
        php_numeric_string_code(self)
    }
}

impl IntoExceptionCode for String {
    fn into_exception_code(self) -> i32 {
        php_numeric_string_code(&self)
    }
}

/// PHP `(int) $code` when `is_numeric($code)`, else `0`.
fn php_numeric_string_code(code: &str) -> i32 {
    let trimmed = code.trim();
    if trimmed.is_empty() {
        return 0;
    }
    if let Ok(n) = trimmed.parse::<i32>() {
        return n;
    }
    if let Ok(n) = trimmed.parse::<f64>() {
        #[allow(clippy::cast_possible_truncation)]
        return n as i32;
    }
    0
}

/// Type aliases matching PHP class names.
pub type Exception = QueryError;
pub type ValidationException = QueryError;
pub type UnsupportedException = QueryError;
