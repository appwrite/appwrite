//! `LogOwl` adapter (PHP `Utopia\Logger\Adapter\LogOwl`).

use serde_json::{json, Map, Value};

use crate::adapter::Adapter;
use crate::error::LoggerError;
use crate::log::Log;
use crate::logger::Logger;

use super::http::{php_intval, php_isset, post_json, DEFAULT_CONNECT_TIMEOUT, DEFAULT_TIMEOUT};

/// `LogOwl` logging adapter.
///
/// PHP constructor: `new LogOwl($ticket, $host = '', $timeout = 5, $connectTimeout = 1)`.
#[derive(Debug, Clone)]
pub struct LogOwl {
    ticket: String,
    log_owl_host: String,
    timeout: i32,
    connect_timeout: i32,
}

impl LogOwl {
    /// Unique adapter name (PHP `LogOwl::getName()`).
    pub fn get_name() -> &'static str {
        "logOwl"
    }

    /// Adapter type sent in the payload (PHP `getAdapterType()`).
    pub fn get_adapter_type() -> &'static str {
        "utopia-logger"
    }

    /// Adapter version sent in the payload (PHP `getAdapterVersion()`).
    pub fn get_adapter_version() -> &'static str {
        Logger::LIBRARY_VERSION
    }

    /// Construct with service ticket. Host defaults to `https://api.logowl.io/logging/`.
    pub fn new(ticket: impl Into<String>) -> Self {
        Self::new_with(
            ticket,
            String::new(),
            DEFAULT_TIMEOUT,
            DEFAULT_CONNECT_TIMEOUT,
        )
    }

    /// Full PHP constructor including optional host and timeouts (seconds).
    pub fn new_with(
        ticket: impl Into<String>,
        host: impl Into<String>,
        timeout: i32,
        connect_timeout: i32,
    ) -> Self {
        let host = host.into();
        let log_owl_host = if host.is_empty() {
            "https://api.logowl.io/logging/".to_string()
        } else {
            host
        };
        Self {
            ticket: ticket.into(),
            log_owl_host,
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

    fn extra_field(extra: &Map<String, Value>, key: &str) -> Value {
        if php_isset(extra, key) {
            extra.get(key).cloned().unwrap_or_else(|| json!(""))
        } else {
            json!("")
        }
    }

    fn user_fields(log: &Log) -> (Value, Value, Value) {
        match log.get_user() {
            None => (Value::Null, Value::Null, Value::Null),
            Some(user) => (
                json!(user.get_id()),
                json!(user.get_email()),
                json!(user.get_username()),
            ),
        }
    }
}

impl Adapter for LogOwl {
    fn get_name(&self) -> &'static str {
        Self::get_name()
    }

    fn push(&self, log: &Log) -> Result<u16, LoggerError> {
        let extra = log.get_extra();
        let line = Self::extra_field(&extra, "line");
        let file = Self::extra_field(&extra, "file");
        let trace = Self::extra_field(&extra, "trace");
        let (id, email, username) = Self::user_fields(log);

        let breadcrumbs: Vec<Value> = log
            .get_breadcrumbs()
            .iter()
            .map(|breadcrumb| {
                json!({
                    "type": "log",
                    "log": breadcrumb.get_message(),
                    "timestamp": php_intval(breadcrumb.get_timestamp()),
                })
            })
            .collect();

        let request_body = json!({
            "ticket": self.ticket,
            "message": log.get_action(),
            "path": file,
            "line": line,
            "stacktrace": trace,
            "badges": {
                "environment": log.get_environment(),
                "namespace": log.get_namespace(),
                "version": log.get_version(),
                "message": log.get_message(),
                "id": id,
                "$email": email,
                "$username": username,
            },
            "type": log.get_type(),
            "metrics": {
                "platform": log.get_server(),
            },
            "logs": breadcrumbs,
            "timestamp": php_intval(log.get_timestamp()),
            "adapter": {
                "name": Self::get_name(),
                "type": Self::get_adapter_type(),
                "version": Self::get_adapter_version(),
            },
        });

        let url = format!("{}{}", self.log_owl_host, log.get_type());
        Ok(post_json(
            &url,
            &[],
            &request_body,
            self.timeout,
            self.connect_timeout,
            "LogOwl",
        ))
    }

    fn get_supported_types(&self) -> &'static [&'static str] {
        &[Log::TYPE_ERROR]
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
