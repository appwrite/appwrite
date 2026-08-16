//! Sentry adapter (PHP `Utopia\Logger\Adapter\Sentry`).

use serde_json::{json, Map, Value};

use crate::adapter::Adapter;
use crate::error::LoggerError;
use crate::log::Log;
use crate::logger::Logger;

use super::http::{
    php_array_values, php_assoc, php_index, php_is_array, php_isset, post_json,
    DEFAULT_CONNECT_TIMEOUT, DEFAULT_TIMEOUT,
};

/// Sentry error reporting adapter.
///
/// PHP constructor: `new Sentry($projectId, $key, $host = '', $timeout = 5, $connectTimeout = 1)`.
#[derive(Debug, Clone)]
pub struct Sentry {
    sentry_key: String,
    project_id: String,
    sentry_host: String,
    timeout: i32,
    connect_timeout: i32,
}

impl Sentry {
    /// Unique adapter name (PHP `Sentry::getName()`).
    pub fn get_name() -> &'static str {
        "sentry"
    }

    /// Construct with required credentials. Host defaults to `https://sentry.io`.
    pub fn new(project_id: impl Into<String>, key: impl Into<String>) -> Self {
        Self::new_with(
            project_id,
            key,
            String::new(),
            DEFAULT_TIMEOUT,
            DEFAULT_CONNECT_TIMEOUT,
        )
    }

    /// Full PHP constructor including optional host and timeouts (seconds).
    pub fn new_with(
        project_id: impl Into<String>,
        key: impl Into<String>,
        host: impl Into<String>,
        timeout: i32,
        connect_timeout: i32,
    ) -> Self {
        let host = host.into();
        let sentry_host = if host.is_empty() {
            "https://sentry.io".to_string()
        } else {
            host
        };
        Self {
            sentry_key: key.into(),
            project_id: project_id.into(),
            sentry_host,
            timeout: if timeout > 0 {
                timeout
            } else {
                DEFAULT_TIMEOUT
            },
            connect_timeout: if connect_timeout > 0 {
                connect_timeout
            } else {
                DEFAULT_CONNECT_TIMEOUT
            },
        }
    }

    fn store_url(&self) -> String {
        format!("{}/api/{}/store/", self.sentry_host, self.project_id)
    }

    fn auth_header(&self) -> String {
        format!(
            "Sentry sentry_version=7, sentry_key={}, sentry_client=utopia-logger/{}",
            self.sentry_key,
            Logger::LIBRARY_VERSION
        )
    }

    fn breadcrumbs_json(log: &Log) -> Value {
        let items: Vec<Value> = log
            .get_breadcrumbs()
            .iter()
            .map(|breadcrumb| {
                json!({
                    "type": "default",
                    "level": breadcrumb.get_type(),
                    "category": breadcrumb.get_category(),
                    "message": breadcrumb.get_message(),
                    "timestamp": breadcrumb.get_timestamp(),
                })
            })
            .collect();
        Value::Array(items)
    }

    fn stack_frames(extra: &Map<String, Value>) -> Result<Vec<Value>, LoggerError> {
        let mut stack_frames = Vec::new();
        if php_isset(extra, "detailedTrace") {
            let detailed_trace = extra.get("detailedTrace").cloned().unwrap_or(Value::Null);
            if !php_is_array(&detailed_trace) {
                return Err(LoggerError::Message(
                    "detailedTrace must be an array".to_string(),
                ));
            }
            for trace in php_array_values(&detailed_trace) {
                if !php_is_array(trace) {
                    return Err(LoggerError::Message(
                        "detailedTrace must be an array of arrays".to_string(),
                    ));
                }
                let filename = php_index(trace, "file")
                    .cloned()
                    .unwrap_or_else(|| json!(""));
                let lineno = php_index(trace, "line")
                    .cloned()
                    .unwrap_or_else(|| json!(0));
                let function = php_index(trace, "function")
                    .cloned()
                    .unwrap_or_else(|| json!(""));
                stack_frames.push(json!({
                    "filename": filename,
                    "lineno": lineno,
                    "function": function,
                }));
            }
        }
        stack_frames.reverse();
        Ok(stack_frames)
    }

    fn user_json(log: &Log) -> Value {
        match log.get_user() {
            None => Value::Null,
            Some(user) => json!({
                "id": user.get_id(),
                "email": user.get_email(),
                "username": user.get_username(),
            }),
        }
    }
}

impl Adapter for Sentry {
    fn get_name(&self) -> &'static str {
        Self::get_name()
    }

    fn push(&self, log: &Log) -> Result<u16, LoggerError> {
        let extra = log.get_extra();
        let stack_frames = Self::stack_frames(&extra)?;

        let request_body = json!({
            "timestamp": log.get_timestamp(),
            "platform": "php",
            "level": "error",
            "logger": log.get_namespace(),
            "transaction": log.get_action(),
            "server_name": log.get_server(),
            "release": log.get_version(),
            "environment": log.get_environment(),
            "message": {
                "message": log.get_message(),
            },
            "exception": {
                "values": [{
                    "type": log.get_message(),
                    "stacktrace": {
                        "frames": stack_frames,
                    },
                }],
            },
            "tags": php_assoc(log.tags_ordered().into_iter().map(|(k, v)| (k, Value::String(v))).collect()),
            "extra": php_assoc(extra),
            "breadcrumbs": Self::breadcrumbs_json(log),
            "user": Self::user_json(log),
        });

        let auth = self.auth_header();
        Ok(post_json(
            &self.store_url(),
            &[("X-Sentry-Auth", auth.as_str())],
            &request_body,
            self.timeout,
            self.connect_timeout,
            "Sentry",
        ))
    }

    fn get_supported_types(&self) -> &'static [&'static str] {
        &[
            Log::TYPE_INFO,
            Log::TYPE_DEBUG,
            Log::TYPE_WARNING,
            Log::TYPE_ERROR,
        ]
    }

    fn get_supported_environments(&self) -> &'static [&'static str] {
        &[Log::ENVIRONMENT_STAGING, Log::ENVIRONMENT_PRODUCTION]
    }

    fn get_supported_breadcrumb_types(&self) -> &'static [&'static str] {
        &[
            Log::TYPE_INFO,
            Log::TYPE_DEBUG,
            Log::TYPE_WARNING,
            Log::TYPE_ERROR,
        ]
    }
}
