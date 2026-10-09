//! Per-request context handed to actions.

use std::sync::Arc;
use std::time::Instant;

use bytes::Bytes;
use http::StatusCode;
use serde::Serialize;
use serde_json::Value;
use utopia_database::Database;
use utopia_http::{Request, Response};
use utopia_validators::{Validator, php};

use super::{Route, State};
use crate::auth::{ApiKey, roles};
use crate::database::Project;
use crate::database::documents::{Session, User};
use crate::response::{Render, Version};
use crate::{Error, Result};

/// `default` or `admin` (`X-Appwrite-Mode`).
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Mode {
    Default,
    Admin,
}

/// Event state filled by actions (`$queueForEvents`).
#[derive(Debug, Default)]
pub struct EventState {
    pub params: Vec<(String, String)>,
    /// Explicit payload (`setPayload`); defaults to the response body.
    pub payload: Option<Bytes>,
    /// Users created during the request (database listener events).
    pub created_users: Vec<(String, Bytes)>,
    /// Extra delete messages to publish.
    pub deletes: Vec<Value>,
}

/// Request context.
pub struct Context {
    pub state: Arc<State>,
    pub request: Request,
    pub route: Arc<Route>,
    pub path_params: Vec<(&'static str, Value)>,
    pub started: Instant,

    pub project: Arc<Project>,
    pub project_found: bool,
    pub mode: Mode,
    pub locale: String,
    pub format: Option<Version>,

    /// Authenticated user (session or JWT), `None` for guests and keys.
    pub user: Option<User>,
    /// Current session of `user`.
    pub session: Option<Session>,
    /// Impersonated user (roles come from this user when set).
    pub target_user: Option<User>,
    pub key: Option<ApiKey>,
    pub role: String,
    pub roles: Vec<String>,
    pub scopes: Vec<String>,
    /// Whether document-level permission checks apply.
    pub authorization: bool,

    pub ip: String,
    pub hostname: String,
    pub events: EventState,
    /// Usage metrics added by the action (e.g. `sessions` ±1).
    pub metrics: Vec<(&'static str, i64)>,
    pub response_headers: Vec<(&'static str, String)>,
    db: Option<Database>,
}

impl Context {
    pub(crate) fn new(
        state: Arc<State>,
        request: Request,
        route: Arc<Route>,
        path_params: Vec<(&'static str, String)>,
    ) -> Self {
        let console = state.projects.console();
        let ip = crate::network::client_ip(
            request.remote_addr,
            &request.headers,
            &state.config.trusted_headers,
            &state.config.trusted_proxies,
        );
        let hostname = request_hostname(&request);
        let locale = state.config.locale.clone();
        Self {
            path_params: path_params.into_iter().map(|(k, v)| (k, Value::String(v))).collect(),
            state,
            request,
            route,
            started: Instant::now(),
            project: console,
            project_found: true,
            mode: Mode::Default,
            locale,
            format: None,
            user: None,
            session: None,
            target_user: None,
            key: None,
            role: roles::GUESTS.to_owned(),
            roles: vec!["any".to_owned()],
            scopes: Vec::new(),
            authorization: true,
            ip,
            hostname,
            events: EventState::default(),
            metrics: Vec::new(),
            response_headers: Vec::new(),
            db: None,
        }
    }

    /// `dbForProject`.
    pub fn db(&mut self) -> Result<Database> {
        if let Some(db) = &self.db {
            return Ok(db.clone());
        }
        let db = self.state.databases.project(&self.project)?;
        self.db = Some(db.clone());
        Ok(db)
    }

    /// `dbForPlatform`.
    pub fn platform_db(&self) -> Database {
        self.state.databases.platform()
    }

    /// Whether sensitive fields are visible (API keys and privileged users).
    pub fn render(&self) -> Render {
        Render { sensitive: roles::is_key(&self.roles) || roles::is_privileged(&self.roles), format: self.format }
    }

    pub fn is_key(&self) -> bool {
        roles::is_key(&self.roles)
    }

    pub fn is_privileged(&self) -> bool {
        roles::is_privileged(&self.roles)
    }

    /// The user whose roles apply (impersonated target or the session user).
    pub fn roles_user(&self) -> Option<&User> {
        self.target_user.as_ref().or(self.user.as_ref())
    }

    /// Authorization roles when permission checks apply.
    pub fn auth_roles(&self) -> Option<&[String]> {
        if self.authorization { Some(&self.roles) } else { None }
    }

    // ---- parameters -------------------------------------------------------

    /// Raw parameter: path params override request params.
    pub fn param(&self, key: &str) -> Option<&Value> {
        if let Some((_, v)) = self.path_params.iter().find(|(k, _)| *k == key) {
            return Some(v);
        }
        self.request.params().get(key)
    }

    /// A required parameter (`Param "x" is not optional.` when missing).
    pub fn required(&self, key: &str, validator: &dyn Validator) -> Result<Value> {
        let Some(value) = self.param(key) else {
            return Err(Error::missing_param(key));
        };
        validator.check(value).map_err(|d| Error::invalid_param(key, &d))?;
        Ok(value.clone())
    }

    /// An optional parameter with Utopia semantics: missing → default (not
    /// validated); explicit `null` with a non-null default → default.
    pub fn optional(&self, key: &str, default: Value, validator: &dyn Validator) -> Result<Value> {
        let Some(value) = self.param(key) else {
            return Ok(default);
        };
        if value.is_null() {
            if !default.is_null() && !validator.accepts_null() {
                return Ok(default);
            }
            return Ok(Value::Null);
        }
        validator.check(value).map_err(|d| Error::invalid_param(key, &d))?;
        Ok(value.clone())
    }

    /// Required string parameter (PHP string cast).
    pub fn required_str(&self, key: &str, validator: &dyn Validator) -> Result<String> {
        Ok(php::to_string(&self.required(key, validator)?).unwrap_or_default())
    }

    /// Optional string parameter (PHP string cast; `None` for null).
    pub fn optional_str(&self, key: &str, default: &str, validator: &dyn Validator) -> Result<Option<String>> {
        let v = self.optional(key, Value::String(default.to_owned()), validator)?;
        Ok(if v.is_null() { None } else { php::to_string(&v) })
    }

    /// The `queries` parameter validated against an allow-listed schema.
    pub fn queries(
        &self,
        collection: &utopia_database::Collection,
        allowed: &[&str],
    ) -> Result<Vec<utopia_database::Query>> {
        let Some(value) = self.param("queries") else {
            return Ok(Vec::new());
        };
        if value.is_null() {
            return Ok(Vec::new());
        }
        utopia_database::validator::Queries::new(collection, allowed)
            .validate(value)
            .map_err(|m| Error::invalid_param("queries", &m))
    }

    /// Optional loose boolean parameter cast like PHP (`(bool)`).
    pub fn optional_bool(&self, key: &str, default: bool) -> Result<bool> {
        let v = self.optional(key, Value::Bool(default), &utopia_validators::Boolean::LOOSE)?;
        Ok(php::truthy(&v))
    }

    // ---- events -----------------------------------------------------------

    /// `$queueForEvents->setParam($key, $value)`.
    pub fn event_param(&mut self, key: &str, value: impl Into<String>) {
        let value = value.into();
        if let Some(p) = self.events.params.iter_mut().find(|(k, _)| k == key) {
            p.1 = value;
        } else {
            self.events.params.push((key.to_owned(), value));
        }
    }

    /// `$queueForEvents->setPayload(...)`.
    pub fn event_payload<T: Serialize>(&mut self, payload: &T) {
        self.events.payload = Some(Bytes::from(crate::json::to_vec(payload)));
    }

    /// Records a usage metric (database listener equivalent).
    pub fn metric(&mut self, key: &'static str, value: i64) {
        self.metrics.push((key, value));
    }

    // ---- responses --------------------------------------------------------

    /// JSON response with PHP encoding.
    pub fn json<T: Serialize>(&self, status: StatusCode, model: &T) -> Response {
        Response::json(status, crate::json::to_vec(model))
    }

    pub fn ok<T: Serialize>(&self, model: &T) -> Response {
        self.json(StatusCode::OK, model)
    }

    pub fn created<T: Serialize>(&self, model: &T) -> Response {
        self.json(StatusCode::CREATED, model)
    }

    pub fn no_content(&self) -> Response {
        Response::no_content()
    }

    /// The `User-Agent` (with `X-Forwarded-User-Agent` for API keys).
    pub fn user_agent(&self, default: &str) -> String {
        if self.is_key()
            && let Some(v) = self.request.header("x-forwarded-user-agent").filter(|v| !v.is_empty())
        {
            return v.to_owned();
        }
        self.request.header("user-agent").map(str::to_owned).unwrap_or_else(|| default.to_owned())
    }

    /// Localised text.
    pub fn text(&self, key: &str, default: Option<&str>) -> String {
        self.state.translations.text(&self.locale, key, default)
    }

    /// Plan flags (`$plan`); empty in CE.
    pub fn plan_allows(&self, flag: &str) -> bool {
        if self.project.is_console() {
            return true;
        }
        match self.state.hooks.plan(&self.project) {
            None => true,
            Some(plan) if plan.is_empty() => true,
            Some(plan) => plan.get(flag).map(php::truthy).unwrap_or(false),
        }
    }
}

/// Request hostname (`x-forwarded-host` or `host`, lower-cased, no port).
fn request_hostname(request: &Request) -> String {
    let raw =
        request.header("x-forwarded-host").filter(|h| !h.is_empty()).or_else(|| request.header("host")).unwrap_or("");
    let host = if raw.starts_with('[') {
        raw.split(']').next().map(|h| format!("{h}]")).unwrap_or_default()
    } else {
        raw.split(':').next().unwrap_or("").to_owned()
    };
    host.to_lowercase()
}
