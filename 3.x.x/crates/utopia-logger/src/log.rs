//! Structured log event (PHP `Utopia\Logger\Log`).

use std::collections::HashMap;
use std::time::{SystemTime, UNIX_EPOCH};

use serde_json::{Map, Value};

use crate::breadcrumb::Breadcrumb;
use crate::error::LoggerError;
use crate::user::User;

/// A single log record to push through a [`crate::Logger`].
#[derive(Debug, Clone)]
pub struct Log {
    timestamp: f64,
    type_: String,
    message: String,
    version: String,
    environment: String,
    action: String,
    tags: Vec<(String, String)>,
    extra: Vec<(String, Value)>,
    namespace: String,
    server: Option<String>,
    user: Option<User>,
    breadcrumbs: Vec<Breadcrumb>,
    masked: Vec<String>,
}

impl Default for Log {
    fn default() -> Self {
        Self::new()
    }
}

impl Log {
    pub const TYPE_DEBUG: &'static str = "debug";
    pub const TYPE_ERROR: &'static str = "error";
    pub const TYPE_WARNING: &'static str = "warning";
    pub const TYPE_INFO: &'static str = "info";
    pub const TYPE_VERBOSE: &'static str = "verbose";

    pub const ENVIRONMENT_PRODUCTION: &'static str = "production";
    pub const ENVIRONMENT_STAGING: &'static str = "staging";

    /// Create a log with the current timestamp (PHP `microtime(true)`).
    pub fn new() -> Self {
        Self {
            timestamp: unix_timestamp_secs(),
            type_: String::new(),
            message: String::new(),
            version: String::new(),
            environment: String::new(),
            action: String::new(),
            tags: Vec::new(),
            extra: Vec::new(),
            namespace: "UNKNOWN".to_string(),
            server: None,
            user: None,
            breadcrumbs: Vec::new(),
            masked: Vec::new(),
        }
    }

    /// Set the log type. Must be one of the `TYPE_*` constants.
    pub fn set_type(&mut self, type_: impl Into<String>) -> Result<(), LoggerError> {
        let type_ = type_.into();
        match type_.as_str() {
            Self::TYPE_DEBUG
            | Self::TYPE_ERROR
            | Self::TYPE_VERBOSE
            | Self::TYPE_INFO
            | Self::TYPE_WARNING => {}
            _ => return Err(LoggerError::UnsupportedType),
        }
        self.type_ = type_;
        Ok(())
    }

    /// Log type (PHP `getType()`).
    pub fn get_type(&self) -> &str {
        &self.type_
    }

    /// Set timestamp in seconds when the log occurred.
    pub fn set_timestamp(&mut self, timestamp: f64) {
        self.timestamp = timestamp;
    }

    /// Timestamp in seconds (PHP `getTimestamp()`).
    pub fn get_timestamp(&self) -> f64 {
        self.timestamp
    }

    /// Set the main message.
    pub fn set_message(&mut self, message: impl Into<String>) {
        self.message = message.into();
    }

    /// Main message (PHP `getMessage()`).
    pub fn get_message(&self) -> &str {
        &self.message
    }

    /// Set a custom namespace for categorizing.
    pub fn set_namespace(&mut self, namespace: impl Into<String>) {
        self.namespace = namespace.into();
    }

    /// Namespace (PHP `getNamespace()`). Defaults to `"UNKNOWN"`.
    pub fn get_namespace(&self) -> &str {
        &self.namespace
    }

    /// Set the action that caused this log.
    pub fn set_action(&mut self, action: impl Into<String>) {
        self.action = action.into();
    }

    /// Action (PHP `getAction()`).
    pub fn get_action(&self) -> &str {
        &self.action
    }

    /// Set identifier of the server where the log happened.
    pub fn set_server(&mut self, server: Option<impl Into<String>>) {
        self.server = server.map(Into::into);
    }

    /// Server identifier (PHP `getServer()`).
    pub fn get_server(&self) -> Option<&str> {
        self.server.as_deref()
    }

    /// Set application version.
    pub fn set_version(&mut self, version: impl Into<String>) {
        self.version = version.into();
    }

    /// Application version (PHP `getVersion()`).
    pub fn get_version(&self) -> &str {
        &self.version
    }

    /// Set environment. Must be `ENVIRONMENT_PRODUCTION` or `ENVIRONMENT_STAGING`.
    pub fn set_environment(&mut self, environment: impl Into<String>) -> Result<(), LoggerError> {
        let environment = environment.into();
        match environment.as_str() {
            Self::ENVIRONMENT_PRODUCTION | Self::ENVIRONMENT_STAGING => {}
            _ => return Err(LoggerError::UnsupportedEnvironment),
        }
        self.environment = environment;
        Ok(())
    }

    /// Environment (PHP `getEnvironment()`).
    pub fn get_environment(&self) -> &str {
        &self.environment
    }

    /// Add a tag (label).
    pub fn add_tag(&mut self, key: impl Into<String>, value: impl Into<String>) {
        self.tags.push((key.into(), value.into()));
    }

    /// Tags with masked fields applied (PHP `getTags()`).
    pub fn get_tags(&self) -> HashMap<String, String> {
        let mut map = HashMap::new();
        for (key, value) in &self.tags {
            let masked = if self.masked.iter().any(|m| m == key) {
                "*".repeat(value.len())
            } else {
                value.clone()
            };
            map.insert(key.clone(), masked);
        }
        map
    }

    /// Tags in insertion order, with masking applied. Used by adapters.
    pub(crate) fn tags_ordered(&self) -> Vec<(String, String)> {
        self.tags
            .iter()
            .map(|(key, value)| {
                let masked = if self.masked.iter().any(|m| m == key) {
                    "*".repeat(value.len())
                } else {
                    value.clone()
                };
                (key.clone(), masked)
            })
            .collect()
    }

    /// Add extra metadata. Values are stored as JSON (PHP `mixed`).
    pub fn add_extra(&mut self, key: impl Into<String>, value: impl Into<Value>) {
        self.extra.push((key.into(), value.into()));
    }

    /// Extra metadata with masked fields applied (PHP `getExtra()`).
    pub fn get_extra(&self) -> Map<String, Value> {
        mask_pairs(&self.extra, &self.masked)
    }

    /// Set the user who caused the log.
    pub fn set_user(&mut self, user: User) {
        self.user = Some(user);
    }

    /// User who caused the log (PHP `getUser()`).
    pub fn get_user(&self) -> Option<&User> {
        self.user.as_ref()
    }

    /// Add a reproduction step.
    pub fn add_breadcrumb(&mut self, breadcrumb: Breadcrumb) {
        self.breadcrumbs.push(breadcrumb);
    }

    /// Reproduction steps (PHP `getBreadcrumbs()`).
    pub fn get_breadcrumbs(&self) -> &[Breadcrumb] {
        &self.breadcrumbs
    }

    /// Set field names that will be replaced by asterisks of the same length.
    pub fn set_masked(&mut self, masked: impl IntoIterator<Item = impl Into<String>>) {
        self.masked = masked.into_iter().map(Into::into).collect();
    }

    /// Whether PHP `empty()` would treat this string as empty (`""` or `"0"`).
    pub(crate) fn php_empty(value: &str) -> bool {
        value.is_empty() || value == "0"
    }

    /// Required fields for [`crate::Logger::add_log`].
    pub(crate) fn is_ready(&self) -> bool {
        !Self::php_empty(&self.action)
            && !Self::php_empty(&self.environment)
            && !Self::php_empty(&self.message)
            && !Self::php_empty(&self.type_)
            && !Self::php_empty(&self.version)
    }
}

fn unix_timestamp_secs() -> f64 {
    SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .map_or(0.0, |d| d.as_secs_f64())
}

fn mask_pairs(data: &[(String, Value)], masked: &[String]) -> Map<String, Value> {
    let mut out = Map::new();
    for (key, value) in data {
        out.insert(key.clone(), mask_value(key, value, masked));
    }
    out
}

fn mask_value(key: &str, value: &Value, masked: &[String]) -> Value {
    match value {
        Value::String(s) if masked.iter().any(|m| m == key) => Value::String("*".repeat(s.len())),
        Value::Array(items) => Value::Array(
            items
                .iter()
                .enumerate()
                .map(|(i, item)| mask_value(&i.to_string(), item, masked))
                .collect(),
        ),
        Value::Object(map) => {
            let mut out = Map::new();
            for (nested_key, nested_value) in map {
                out.insert(
                    nested_key.clone(),
                    mask_value(nested_key, nested_value, masked),
                );
            }
            Value::Object(out)
        }
        other => other.clone(),
    }
}
