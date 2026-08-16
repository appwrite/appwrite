use std::fmt;

use serde_json::{json, Map, Value};

/// [`Utopia\Migration\Exception`](https://github.com/utopia-php/migration/blob/7e371c8f59bf/src/Migration/Exception.php).
#[derive(Debug, Clone)]
pub struct Exception {
    message: String,
    resource_name: String,
    resource_group: String,
    resource_id: Option<String>,
    code: i64,
}

impl Exception {
    pub const CODE_VALIDATION: i64 = 400;
    pub const CODE_UNAUTHORIZED: i64 = 401;
    pub const CODE_FORBIDDEN: i64 = 403;
    pub const CODE_NOT_FOUND: i64 = 404;
    pub const CODE_CONFLICT: i64 = 409;
    pub const CODE_RATE_LIMITED: i64 = 429;
    pub const CODE_INTERNAL: i64 = 500;

    pub fn new(
        resource_name: impl Into<String>,
        resource_group: impl Into<String>,
        resource_id: Option<String>,
        message: impl Into<String>,
        code: impl Into<ExceptionCode>,
    ) -> Self {
        Self {
            resource_name: resource_name.into(),
            resource_group: resource_group.into(),
            resource_id,
            message: message.into(),
            code: code.into().0,
        }
    }

    pub fn message_only(message: impl Into<String>) -> Self {
        Self::new("", "", None, message, Self::CODE_INTERNAL)
    }

    #[must_use]
    pub fn get_message(&self) -> &str {
        &self.message
    }

    #[must_use]
    pub fn get_resource_name(&self) -> &str {
        &self.resource_name
    }

    #[must_use]
    pub fn get_resource_group(&self) -> &str {
        &self.resource_group
    }

    #[must_use]
    pub fn get_resource_id(&self) -> &str {
        self.resource_id.as_deref().unwrap_or("")
    }

    #[must_use]
    pub fn get_code(&self) -> i64 {
        self.code
    }

    #[must_use]
    pub fn json_serialize(&self) -> Map<String, Value> {
        json!({
            "code": self.code,
            "message": self.message,
            "resourceName": self.resource_name,
            "resourceGroup": self.resource_group,
            "resourceId": self.resource_id,
            "trace": Value::Null,
        })
        .as_object()
        .cloned()
        .unwrap_or_default()
    }
}

impl fmt::Display for Exception {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        write!(f, "{}", self.message)
    }
}

impl std::error::Error for Exception {}

/// PHP `__construct` `$code` may be int or numeric/non-numeric string.
pub struct ExceptionCode(pub i64);

impl From<i64> for ExceptionCode {
    fn from(v: i64) -> Self {
        Self(v)
    }
}

impl From<i32> for ExceptionCode {
    fn from(v: i32) -> Self {
        Self(i64::from(v))
    }
}

impl From<&str> for ExceptionCode {
    fn from(v: &str) -> Self {
        Self(v.parse().unwrap_or(Exception::CODE_INTERNAL))
    }
}

impl From<String> for ExceptionCode {
    fn from(v: String) -> Self {
        Self::from(v.as_str())
    }
}
