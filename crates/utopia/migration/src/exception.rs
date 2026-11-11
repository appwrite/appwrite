//! A failure tied to one resource, and a warning about one.

use php_std::{Array, Value};

use crate::error::{Code, Error};

/// A failure while migrating a resource: which resource (name, group, id),
/// what went wrong, and the error that caused it.
///
/// Sources and destinations collect these ([`crate::Target::add_error`])
/// instead of stopping the transfer; [`crate::Transfer::status_counters`]
/// counts them per resource group.
///
/// PHP: `Utopia\Migration\Exception` (constants `CODE_*` are associated
/// constants).
#[derive(Debug, Clone, PartialEq)]
pub struct Exception {
    /// The resource type (`getResourceName()`).
    pub resource_name: String,
    /// The resource group the error is counted under (`getResourceGroup()`).
    pub resource_group: String,
    /// The resource id, when known.
    pub resource_id: Option<String>,
    /// The message.
    pub message: String,
    /// The code, an HTTP-like status.
    pub code: i64,
    /// The error that caused this one.
    pub previous: Option<Box<Error>>,
}

impl Exception {
    /// A validation failure.
    pub const CODE_VALIDATION: i64 = 400;
    /// Missing or invalid credentials.
    pub const CODE_UNAUTHORIZED: i64 = 401;
    /// Missing permissions.
    pub const CODE_FORBIDDEN: i64 = 403;
    /// A resource that does not exist.
    pub const CODE_NOT_FOUND: i64 = 404;
    /// A resource that already exists.
    pub const CODE_CONFLICT: i64 = 409;
    /// Too many requests or connections.
    pub const CODE_RATE_LIMITED: i64 = 429;
    /// Anything else.
    pub const CODE_INTERNAL: i64 = 500;

    /// A new exception. A string `code` that is numeric becomes that number,
    /// any other string (a PDO SQLSTATE) [`Exception::CODE_INTERNAL`].
    ///
    /// PHP: `new Exception($resourceName, $resourceGroup, $resourceId, $message, $code, $previous)`.
    pub fn new(
        resource_name: impl Into<String>,
        resource_group: impl Into<String>,
        resource_id: Option<String>,
        message: impl Into<String>,
        code: impl Into<Code>,
        previous: Option<Error>,
    ) -> Self {
        let code = match code.into() {
            Code::Int(i) => i,
            Code::Str(s) => match php_std::value::numeric_str(&s) {
                Some(php_std::value::Number::Int(i)) => i,
                Some(php_std::value::Number::Float(f)) => f as i64,
                None => Self::CODE_INTERNAL,
            },
        };
        Self {
            resource_name: resource_name.into(),
            resource_group: resource_group.into(),
            resource_id,
            message: message.into(),
            code,
            previous: previous.map(Box::new),
        }
    }

    /// The resource id, `''` when unknown.
    pub fn resource_id(&self) -> &str {
        self.resource_id.as_deref().unwrap_or("")
    }

    /// The JSON form: code, message, resource name, group and id, and the
    /// previous error's trace.
    ///
    /// PHP: `jsonSerialize()`. `trace` is `getTraceAsString()` of the previous
    /// exception, a PHP stack trace; Rust has none and reports the previous
    /// error's message instead (null without one).
    pub fn json(&self) -> Value {
        let mut a = Array::new();
        a.set("code".into(), Value::Int(self.code));
        a.set("message".into(), Value::from(self.message.as_str()));
        a.set("resourceName".into(), Value::from(self.resource_name.as_str()));
        a.set("resourceGroup".into(), Value::from(self.resource_group.as_str()));
        a.set("resourceId".into(), self.resource_id.as_deref().map_or(Value::Null, Value::from));
        a.set("trace".into(), self.previous.as_ref().map_or(Value::Null, |p| Value::from(p.message().as_str())));
        Value::Array(a)
    }
}

/// A non-fatal problem with a resource, such as an unsupported default value
/// that was dropped.
///
/// PHP: `Utopia\Migration\Warning`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Warning {
    /// The resource type.
    pub resource_name: String,
    /// The resource group.
    pub resource_group: String,
    /// The resource id, `''` when unknown.
    pub resource_id: String,
    /// The message.
    pub message: String,
}

impl Warning {
    /// A new warning.
    ///
    /// PHP: `new Warning($resourceName, $resourceGroup, $message, $resourceId = '')`.
    pub fn new(
        resource_name: impl Into<String>,
        resource_group: impl Into<String>,
        message: impl Into<String>,
        resource_id: impl Into<String>,
    ) -> Self {
        Self {
            resource_name: resource_name.into(),
            resource_group: resource_group.into(),
            resource_id: resource_id.into(),
            message: message.into(),
        }
    }
}
