//! `AppSignal` adapter (PHP `Utopia\Logger\Adapter\AppSignal`).

use serde_json::{json, Map, Value};

use crate::adapter::Adapter;
use crate::error::LoggerError;
use crate::log::Log;
use crate::logger::Logger;

use super::http::{
    php_assoc, php_empty_opt, php_intval, php_var_export, post_json, DEFAULT_CONNECT_TIMEOUT,
    DEFAULT_TIMEOUT,
};

const DEFAULT_HOST: &str = "https://appsignal-endpoint.net";

/// `AppSignal` error reporting adapter.
///
/// PHP constructor: `new AppSignal($key, $timeout = 5, $connectTimeout = 1)`.
#[derive(Debug, Clone)]
pub struct AppSignal {
    api_key: String,
    timeout: i32,
    connect_timeout: i32,
    host: String,
}

impl AppSignal {
    /// Unique adapter name (PHP `AppSignal::getName()`).
    pub fn get_name() -> &'static str {
        "appSignal"
    }

    /// Construct with push key.
    pub fn new(key: impl Into<String>) -> Self {
        Self::new_with(key, DEFAULT_TIMEOUT, DEFAULT_CONNECT_TIMEOUT)
    }

    /// Full PHP constructor including optional timeouts (seconds).
    pub fn new_with(key: impl Into<String>, timeout: i32, connect_timeout: i32) -> Self {
        Self {
            api_key: key.into(),
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
            host: DEFAULT_HOST.to_string(),
        }
    }

    /// Override the API origin. PHP hardcodes `https://appsignal-endpoint.net`.
    /// Used in tests with a mock HTTP server.
    pub fn with_host(mut self, host: impl Into<String>) -> Self {
        let host = host.into();
        self.host = host.trim_end_matches('/').to_string();
        self
    }

    fn collect_url(&self) -> String {
        format!(
            "{}/collect?api_key={}&version=1.3.19",
            self.host, self.api_key
        )
    }
}

impl Adapter for AppSignal {
    fn get_name(&self) -> &'static str {
        Self::get_name()
    }

    fn push(&self, log: &Log) -> Result<u16, LoggerError> {
        let extra = log.get_extra();
        let mut params = Map::new();
        for (param_key, param_value) in &extra {
            params.insert(
                param_key.clone(),
                Value::String(php_var_export(param_value)),
            );
        }

        let breadcrumbs: Vec<Value> = log
            .get_breadcrumbs()
            .iter()
            .map(|breadcrumb| {
                json!({
                    "timestamp": php_intval(breadcrumb.get_timestamp()),
                    "category": breadcrumb.get_category(),
                    "action": breadcrumb.get_message(),
                    "metadata": {
                        "type": breadcrumb.get_type(),
                    },
                })
            })
            .collect();

        let mut tags = Map::new();
        for (tag_key, tag_value) in log.tags_ordered() {
            tags.insert(tag_key, Value::String(tag_value));
        }

        if !Log::php_empty(log.get_type()) {
            tags.insert(
                "type".to_string(),
                Value::String(log.get_type().to_string()),
            );
        }
        if let Some(user) = log.get_user() {
            if let Some(id) = user.get_id() {
                if !php_empty_opt(Some(id)) {
                    tags.insert("userId".to_string(), Value::String(id.to_string()));
                }
            }
            if let Some(name) = user.get_username() {
                if !php_empty_opt(Some(name)) {
                    tags.insert("userName".to_string(), Value::String(name.to_string()));
                }
            }
            if let Some(email) = user.get_email() {
                if !php_empty_opt(Some(email)) {
                    tags.insert("userEmail".to_string(), Value::String(email.to_string()));
                }
            }
        }

        tags.insert(
            "sdk".to_string(),
            Value::String(format!("utopia-logger/{}", Logger::LIBRARY_VERSION)),
        );

        let request_body = json!({
            "timestamp": php_intval(log.get_timestamp()),
            "namespace": log.get_namespace(),
            "error": {
                "name": log.get_message(),
                "message": log.get_message(),
                "backtrace": [],
            },
            "environment": {
                "environment": log.get_environment(),
                "server": log.get_server(),
                "version": log.get_version(),
            },
            "revision": log.get_version(),
            "action": log.get_action(),
            "params": php_assoc(params),
            "tags": php_assoc(tags),
            "breadcrumbs": breadcrumbs,
        });

        Ok(post_json(
            &self.collect_url(),
            &[],
            &request_body,
            self.timeout,
            self.connect_timeout,
            "AppSignal",
        ))
    }

    fn get_supported_types(&self) -> &'static [&'static str] {
        &[
            Log::TYPE_INFO,
            Log::TYPE_DEBUG,
            Log::TYPE_VERBOSE,
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
            Log::TYPE_VERBOSE,
            Log::TYPE_WARNING,
            Log::TYPE_ERROR,
        ]
    }
}
