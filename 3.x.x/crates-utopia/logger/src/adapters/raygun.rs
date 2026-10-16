//! Raygun adapter (PHP `Utopia\Logger\Adapter\Raygun`).

use serde_json::{json, Map, Value};

use crate::adapter::Adapter;
use crate::error::LoggerError;
use crate::log::Log;
use crate::logger::Logger;

use super::http::{php_intval, post_json, DEFAULT_CONNECT_TIMEOUT, DEFAULT_TIMEOUT};

const DEFAULT_HOST: &str = "https://api.raygun.com";

/// Raygun crash-reporting adapter.
///
/// PHP constructor: `new Raygun($key, $timeout = 5, $connectTimeout = 1)`.
#[derive(Debug, Clone)]
pub struct Raygun {
    api_key: String,
    timeout: i32,
    connect_timeout: i32,
    host: String,
}

impl Raygun {
    /// Unique adapter name (PHP `Raygun::getName()`).
    pub fn get_name() -> &'static str {
        "raygun"
    }

    /// Construct with API key.
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

    /// Override the API origin. PHP hardcodes `https://api.raygun.com`.
    /// Used in tests with a mock HTTP server.
    pub fn with_host(mut self, host: impl Into<String>) -> Self {
        let host = host.into();
        self.host = host.trim_end_matches('/').to_string();
        self
    }

    fn entries_url(&self) -> String {
        format!("{}/entries", self.host)
    }
}

impl Adapter for Raygun {
    fn get_name(&self) -> &'static str {
        Self::get_name()
    }

    fn push(&self, log: &Log) -> Result<u16, LoggerError> {
        let breadcrumbs: Vec<Value> = log
            .get_breadcrumbs()
            .iter()
            .map(|breadcrumb| {
                json!({
                    "category": breadcrumb.get_category(),
                    "message": breadcrumb.get_message(),
                    "type": breadcrumb.get_type(),
                    "level": "request",
                    "timestamp": php_intval(breadcrumb.get_timestamp()),
                })
            })
            .collect();

        let mut tags_array: Vec<Value> = log
            .tags_ordered()
            .into_iter()
            .map(|(key, value)| Value::String(format!("{key}: {value}")))
            .collect();
        tags_array.push(Value::String(format!("type: {}", log.get_type())));
        tags_array.push(Value::String(format!(
            "environment: {}",
            log.get_environment()
        )));
        tags_array.push(Value::String(format!(
            "sdk: utopia-logger/{}",
            Logger::LIBRARY_VERSION
        )));

        let user_obj = match log.get_user() {
            None => json!({
                "isAnonymous": true,
                "identifier": Value::Null,
                "email": Value::Null,
                "fullName": Value::Null,
            }),
            Some(user) => json!({
                "isAnonymous": false,
                "identifier": user.get_id(),
                "email": user.get_email(),
                "fullName": user.get_username(),
            }),
        };

        let extra: Map<String, Value> = log.get_extra();
        let request_body = json!({
            "occurredOn": php_intval(log.get_timestamp()),
            "details": {
                "machineName": log.get_server(),
                "groupingKey": log.get_namespace(),
                "version": log.get_version(),
                "error": {
                    "className": log.get_action(),
                    "message": log.get_message(),
                },
                "tags": tags_array,
                "userCustomData": super::http::php_assoc(extra),
                "user": user_obj,
                "breadcrumbs": breadcrumbs,
            },
        });

        Ok(post_json(
            &self.entries_url(),
            &[("X-ApiKey", self.api_key.as_str())],
            &request_body,
            self.timeout,
            self.connect_timeout,
            "Raygun",
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
