//! Queue message payloads. Rust port of `Appwrite\Event\Message\{Delete,Audit}`
//! (`src/Appwrite/Event/Message/{Delete,Audit}.php`).

use serde_json::{json, Value};

/// PHP `DELETE_TYPE_DOCUMENT` (`app/init/constants.php`): delete a single
/// document (the case a user deletion uses).
pub const DELETE_TYPE_DOCUMENT: &str = "document";
/// PHP `DELETE_TYPE_USERS` (`app/init/constants.php`): bulk/background user
/// cleanup, as opposed to a single document delete.
pub const DELETE_TYPE_USERS: &str = "users";
/// PHP `DELETE_TYPE_TARGET`.
pub const DELETE_TYPE_TARGET: &str = "target";
/// PHP `DELETE_TYPE_SESSIONS`.
pub const DELETE_TYPE_SESSIONS: &str = "sessions";

/// PHP `RESOURCE_TYPE_USERS` (`app/init/constants.php`).
pub const RESOURCE_TYPE_USERS: &str = "users";

/// `v1-deletes` queue payload. Rust port of `Appwrite\Event\Message\Delete`.
#[derive(Debug, Clone, Default, PartialEq)]
pub struct DeleteMessage {
    pub project: Option<Value>,
    pub type_: String,
    pub document: Option<Value>,
    pub resource: Option<String>,
    pub resource_type: Option<String>,
    pub datetime: Option<String>,
    pub hourly_usage_retention_datetime: Option<String>,
}

impl DeleteMessage {
    /// PHP `new Delete(type: ...)`.
    #[must_use]
    pub fn new(type_: impl Into<String>) -> Self {
        Self {
            type_: type_.into(),
            ..Default::default()
        }
    }

    #[must_use]
    pub fn with_project(mut self, project: Value) -> Self {
        self.project = Some(project);
        self
    }

    #[must_use]
    pub fn with_document(mut self, document: Value) -> Self {
        self.document = Some(document);
        self
    }

    #[must_use]
    pub fn with_resource(mut self, resource: impl Into<String>) -> Self {
        self.resource = Some(resource.into());
        self
    }

    #[must_use]
    pub fn with_resource_type(mut self, resource_type: impl Into<String>) -> Self {
        self.resource_type = Some(resource_type.into());
        self
    }

    #[must_use]
    pub fn with_datetime(mut self, datetime: impl Into<String>) -> Self {
        self.datetime = Some(datetime.into());
        self
    }

    /// PHP `Delete::toArray()`.
    #[must_use]
    pub fn to_json(&self) -> Value {
        json!({
            "project": self.project.clone(),
            "type": self.type_,
            "document": self.document.clone(),
            "resource": self.resource,
            "resourceType": self.resource_type,
            "datetime": self.datetime,
            "hourlyUsageRetentionDatetime": self.hourly_usage_retention_datetime,
        })
    }
}

/// `v1-audits` queue payload. Rust port of `Appwrite\Event\Message\Audit`.
#[derive(Debug, Clone, Default, PartialEq)]
pub struct AuditMessage {
    pub event: String,
    pub payload: Value,
    pub project: Option<Value>,
    pub user: Option<Value>,
    pub impersonator_user: Option<Value>,
    pub resource: String,
    pub mode: String,
    pub ip: String,
    pub user_agent: String,
    pub hostname: String,
    pub sdk: String,
    pub sdk_version: String,
}

impl AuditMessage {
    /// PHP `new Audit(event: ..., payload: ...)`.
    #[must_use]
    pub fn new(event: impl Into<String>, payload: Value) -> Self {
        Self {
            event: event.into(),
            payload,
            ..Default::default()
        }
    }

    #[must_use]
    pub fn with_project(mut self, project: Value) -> Self {
        self.project = Some(project);
        self
    }

    #[must_use]
    pub fn with_user(mut self, user: Value) -> Self {
        self.user = Some(user);
        self
    }

    #[must_use]
    pub fn with_resource(mut self, resource: impl Into<String>) -> Self {
        self.resource = resource.into();
        self
    }

    #[must_use]
    pub fn with_mode(mut self, mode: impl Into<String>) -> Self {
        self.mode = mode.into();
        self
    }

    #[must_use]
    pub fn with_ip(mut self, ip: impl Into<String>) -> Self {
        self.ip = ip.into();
        self
    }

    #[must_use]
    pub fn with_user_agent(mut self, user_agent: impl Into<String>) -> Self {
        self.user_agent = user_agent.into();
        self
    }

    /// PHP `Audit::toArray()`: note the project is trimmed down to
    /// `$id`/`$sequence`/`database`, matching how PHP's `Event::trimPayload()`
    /// keeps only what the audit worker needs.
    #[must_use]
    pub fn to_json(&self) -> Value {
        let project = self.project.as_ref().map_or_else(
            || json!({"$id": "", "$sequence": 0, "database": ""}),
            |project| {
                json!({
                    "$id": project.get("$id").cloned().unwrap_or(json!("")),
                    "$sequence": project.get("$sequence").cloned().unwrap_or(json!(0)),
                    "database": project.get("database").cloned().unwrap_or(json!("")),
                })
            },
        );

        json!({
            "project": project,
            "user": self.user.clone().unwrap_or(json!({})),
            "impersonatorUser": self.impersonator_user.clone().unwrap_or(json!({})),
            "payload": self.payload,
            "resource": self.resource,
            "mode": self.mode,
            "ip": self.ip,
            "userAgent": self.user_agent,
            "event": self.event,
            "hostname": self.hostname,
            "sdk": self.sdk,
            "sdkVersion": self.sdk_version,
        })
    }
}
