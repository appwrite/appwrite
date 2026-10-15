//! Project settings: variables, protocols, labels, services, environments, SMTP and webhooks.

use php_std::{Array, Value};

use super::{Input, cast_array, cast_bool, cast_int, cast_string, param, to_array, to_string};
use crate::error::Result;
use crate::resource::{Base, Kind, Resource, ResourceRef};

fn based(kind: Kind, id: String, created: String, updated: String) -> ResourceRef {
    Resource::new(kind, Base { id, created_at: created, updated_at: updated, ..Base::default() })
}

/// Reads `id`, `createdAt` and `updatedAt` (constructor positions `1`, `c`, `c + 1`).
fn ids(i: &Input<'_>, class: &'static str, c: usize) -> Result<(String, String, String)> {
    let id = to_string(&i.raw("id"), &param(class, 1, "id"))?;
    let created = to_string(&i.or("createdAt", Value::from("")), &param(class, c, "createdAt"))?;
    let updated = to_string(&i.or("updatedAt", Value::from("")), &param(class, c + 1, "updatedAt"))?;
    Ok((id, created, updated))
}

/// The project's labels.
///
/// PHP: `Utopia\Migration\Resources\Settings\Labels`.
#[derive(Debug, Clone, PartialEq, Default)]
pub struct Labels {
    /// The labels.
    pub labels: Array,
}

impl Labels {
    /// A resource of these labels.
    pub fn resource(
        self,
        id: impl Into<String>,
        created_at: impl Into<String>,
        updated_at: impl Into<String>,
    ) -> ResourceRef {
        based(Kind::Labels(self), id.into(), created_at.into(), updated_at.into())
    }

    /// PHP: `Labels::fromArray()`.
    pub fn from_array(array: &Array) -> Result<ResourceRef> {
        let i = Input::new(array);
        let (id, c, u) = ids(&i, "Utopia\\Migration\\Resources\\Settings\\Labels::__construct", 3)?;
        Ok(Labels { labels: cast_array(&i.or("labels", Value::Array(Array::new()))) }.resource(id, c, u))
    }
}

/// An environment of the project: a separate project in the same
/// organization, billed under its parent. Only the environment itself is
/// carried; its own resources belong to a different project.
///
/// PHP: `Utopia\Migration\Resources\Settings\Environment`.
#[derive(Debug, Clone, PartialEq, Default)]
pub struct Environment {
    /// The name (`getEnvironmentName()`).
    pub name: String,
}

impl Environment {
    /// A resource of this environment.
    pub fn resource(
        self,
        id: impl Into<String>,
        created_at: impl Into<String>,
        updated_at: impl Into<String>,
    ) -> ResourceRef {
        based(Kind::Environment(self), id.into(), created_at.into(), updated_at.into())
    }

    /// PHP: `Environment::fromArray()`.
    pub fn from_array(array: &Array) -> Result<ResourceRef> {
        let i = Input::new(array);
        let class = "Utopia\\Migration\\Resources\\Settings\\Environment::__construct";
        let id = to_string(&i.raw("id"), &param(class, 1, "id"))?;
        let name = to_string(&i.or("name", Value::from("")), &param(class, 2, "name"))?;
        let created = to_string(&i.or("createdAt", Value::from("")), &param(class, 3, "createdAt"))?;
        let updated = to_string(&i.or("updatedAt", Value::from("")), &param(class, 4, "updatedAt"))?;
        Ok(Environment { name }.resource(id, created, updated))
    }
}

/// A project variable.
///
/// PHP: `Utopia\Migration\Resources\Settings\ProjectVariable`.
#[derive(Debug, Clone, PartialEq, Default)]
pub struct ProjectVariable {
    /// The key.
    pub key: String,
    /// The value.
    pub value: String,
    /// Whether the value is secret (`isSecret()`).
    pub secret: bool,
}

impl ProjectVariable {
    /// A resource of this variable.
    pub fn resource(
        self,
        id: impl Into<String>,
        created_at: impl Into<String>,
        updated_at: impl Into<String>,
    ) -> ResourceRef {
        based(Kind::ProjectVariable(self), id.into(), created_at.into(), updated_at.into())
    }

    /// PHP: `ProjectVariable::fromArray()`.
    pub fn from_array(array: &Array) -> Result<ResourceRef> {
        let i = Input::new(array);
        let class = "Utopia\\Migration\\Resources\\Settings\\ProjectVariable::__construct";
        let id = to_string(&i.raw("id"), &param(class, 1, "id"))?;
        let v = ProjectVariable {
            key: to_string(&i.raw("key"), &param(class, 2, "key"))?,
            value: to_string(&i.or("value", Value::from("")), &param(class, 3, "value"))?,
            secret: cast_bool(&i.or("secret", Value::Bool(false))),
        };
        let created = to_string(&i.or("createdAt", Value::from("")), &param(class, 5, "createdAt"))?;
        let updated = to_string(&i.or("updatedAt", Value::from("")), &param(class, 6, "updatedAt"))?;
        Ok(v.resource(id, created, updated))
    }
}

/// The project's API protocols.
///
/// PHP: `Utopia\Migration\Resources\Settings\Protocols`.
#[derive(Debug, Clone, PartialEq)]
pub struct Protocols {
    /// REST.
    pub rest: bool,
    /// GraphQL.
    pub graphql: bool,
    /// Realtime websockets.
    pub websocket: bool,
}

impl Default for Protocols {
    fn default() -> Self {
        Self { rest: true, graphql: true, websocket: true }
    }
}

impl Protocols {
    /// A resource of these protocols.
    pub fn resource(
        self,
        id: impl Into<String>,
        created_at: impl Into<String>,
        updated_at: impl Into<String>,
    ) -> ResourceRef {
        based(Kind::Protocols(self), id.into(), created_at.into(), updated_at.into())
    }

    /// PHP: `Protocols::fromArray()`.
    pub fn from_array(array: &Array) -> Result<ResourceRef> {
        let i = Input::new(array);
        let (id, c, u) = ids(&i, "Utopia\\Migration\\Resources\\Settings\\Protocols::__construct", 5)?;
        let b = |k: &str| cast_bool(&i.or(k, Value::Bool(true)));
        Ok(Protocols { rest: b("rest"), graphql: b("graphql"), websocket: b("websocket") }.resource(id, c, u))
    }
}

/// The project's enabled services.
///
/// PHP: `Utopia\Migration\Resources\Settings\Services`.
#[derive(Debug, Clone, PartialEq, Default)]
pub struct Services {
    /// Service id => enabled.
    pub services: Array,
}

impl Services {
    /// A resource of these services.
    pub fn resource(
        self,
        id: impl Into<String>,
        created_at: impl Into<String>,
        updated_at: impl Into<String>,
    ) -> ResourceRef {
        based(Kind::Services(self), id.into(), created_at.into(), updated_at.into())
    }

    /// PHP: `Services::fromArray()`.
    pub fn from_array(array: &Array) -> Result<ResourceRef> {
        let i = Input::new(array);
        let (id, c, u) = ids(&i, "Utopia\\Migration\\Resources\\Settings\\Services::__construct", 3)?;
        Ok(Services { services: cast_array(&i.or("services", Value::Array(Array::new()))) }.resource(id, c, u))
    }
}

/// The project's SMTP settings (never the password).
///
/// PHP: `Utopia\Migration\Resources\Settings\SMTP`.
#[derive(Debug, Clone, PartialEq, Default)]
pub struct Smtp {
    /// Whether custom SMTP is enabled.
    pub enabled: bool,
    /// The sender name.
    pub sender_name: String,
    /// The sender email.
    pub sender_email: String,
    /// The reply-to name.
    pub reply_to_name: String,
    /// The reply-to email.
    pub reply_to_email: String,
    /// The SMTP host.
    pub host: String,
    /// The SMTP port.
    pub port: i64,
    /// The SMTP user.
    pub username: String,
    /// `tls`, `ssl` or empty.
    pub secure: String,
}

impl Smtp {
    /// A resource of these settings.
    pub fn resource(
        self,
        id: impl Into<String>,
        created_at: impl Into<String>,
        updated_at: impl Into<String>,
    ) -> ResourceRef {
        based(Kind::Smtp(self), id.into(), created_at.into(), updated_at.into())
    }

    /// PHP: `SMTP::fromArray()`.
    pub fn from_array(array: &Array) -> Result<ResourceRef> {
        let i = Input::new(array);
        let (id, c, u) = ids(&i, "Utopia\\Migration\\Resources\\Settings\\SMTP::__construct", 11)?;
        let s = |k: &str| cast_string(&i.or(k, Value::from("")));
        Ok(Smtp {
            enabled: cast_bool(&i.or("enabled", Value::Bool(false))),
            sender_name: s("senderName"),
            sender_email: s("senderEmail"),
            reply_to_name: s("replyToName"),
            reply_to_email: s("replyToEmail"),
            host: s("host"),
            port: cast_int(&i.or("port", Value::Int(0))),
            username: s("username"),
            secure: s("secure"),
        }
        .resource(id, c, u))
    }
}

/// A webhook.
///
/// PHP: `Utopia\Migration\Resources\Settings\Webhook` (`getWebhookName()` is
/// the name, `isEnabled()` whether it is enabled).
#[derive(Debug, Clone, PartialEq)]
pub struct Webhook {
    /// The name.
    pub name: String,
    /// The URL.
    pub url: String,
    /// The events.
    pub events: Array,
    /// Whether TLS certificates are checked.
    pub security: bool,
    /// The basic auth user.
    pub http_user: String,
    /// The basic auth password.
    pub http_pass: String,
    /// Whether it is enabled.
    pub enabled: bool,
}

impl Default for Webhook {
    fn default() -> Self {
        Self {
            name: String::new(),
            url: String::new(),
            events: Array::new(),
            security: false,
            http_user: String::new(),
            http_pass: String::new(),
            enabled: true,
        }
    }
}

impl Webhook {
    /// A resource of this webhook.
    pub fn resource(
        self,
        id: impl Into<String>,
        created_at: impl Into<String>,
        updated_at: impl Into<String>,
    ) -> ResourceRef {
        based(Kind::Webhook(self), id.into(), created_at.into(), updated_at.into())
    }

    /// PHP: `Webhook::fromArray()`.
    pub fn from_array(array: &Array) -> Result<ResourceRef> {
        let i = Input::new(array);
        let class = "Utopia\\Migration\\Resources\\Settings\\Webhook::__construct";
        let p = |n, name| param(class, n, name);
        let id = to_string(&i.raw("id"), &p(1, "id"))?;
        let w = Webhook {
            name: to_string(&i.raw("name"), &p(2, "name"))?,
            url: to_string(&i.raw("url"), &p(3, "url"))?,
            events: to_array(&i.or("events", Value::Array(Array::new())), &p(4, "events"))?,
            security: cast_bool(&i.or("security", Value::Bool(false))),
            http_user: to_string(&i.or("httpUser", Value::from("")), &p(6, "httpUser"))?,
            http_pass: to_string(&i.or("httpPass", Value::from("")), &p(7, "httpPass"))?,
            enabled: cast_bool(&i.or("enabled", Value::Bool(true))),
        };
        let created = to_string(&i.or("createdAt", Value::from("")), &p(9, "createdAt"))?;
        let updated = to_string(&i.or("updatedAt", Value::from("")), &p(10, "updatedAt"))?;
        Ok(w.resource(id, created, updated))
    }
}
