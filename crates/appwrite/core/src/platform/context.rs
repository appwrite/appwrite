//! Per-request context handed to actions.

use std::sync::{Arc, Mutex, OnceLock};
use std::time::Instant;

use bytes::Bytes;
use http::StatusCode;
use serde::Serialize;
use serde_json::Value;
use utopia_database::{Authorization, Database, Query};
use utopia_http::{Request, Response};
use utopia_validators::{Validator, php};

use super::{Route, State};
use crate::auth::{ApiKey, roles};
use crate::database::Project;
use crate::database::collections::Schema;
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
    /// Extra delete messages to publish.
    pub deletes: Vec<Value>,
}

/// Request context.
pub struct Context {
    pub state: Arc<State>,
    pub request: Request,
    pub route: Arc<Route>,
    pub path_params: Vec<(String, Value)>,
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
    /// The request's roles and whether document permissions are checked
    /// (`$authorization`), shared by its databases.
    pub authorization: Arc<Authorization>,

    pub ip: String,
    pub hostname: String,
    pub events: EventState,
    /// What the project database's listeners recorded, and the usage
    /// metrics actions add, in the order they happened.
    pub listened: Arc<Mutex<Listened>>,
    pub response_headers: Vec<(&'static str, String)>,
    /// Whether the security headers (`Server`, `X-Content-Type-Options`,
    /// HSTS) apply. PHP's `general.php` adds them after it resolved the
    /// project, the mode and the API key; an error before that is sent
    /// without them.
    pub secured: bool,
    /// The domain of the custom domain rule the request comes from, when it
    /// serves the project (the `rule` resource).
    pub rule_domain: Option<String>,
    /// The abuse keys' parameters (`{projectId}`, `{ip}`, ...), for the
    /// limits' reset after the action.
    pub abuse_params: Vec<(String, String)>,
    /// The keys of the account API key's user (see the `user` resource).
    pub account_keys: Vec<crate::database::project::Key>,
    db: Option<Database>,
    platform_db: OnceLock<Database>,
}

impl Context {
    pub(crate) fn new(
        state: Arc<State>,
        request: Request,
        route: Arc<Route>,
        path_params: Vec<(String, String)>,
    ) -> Self {
        let console = state.projects.console();
        let ip = crate::network::client_ip(
            request.remote_addr().unwrap_or(std::net::IpAddr::V4(std::net::Ipv4Addr::UNSPECIFIED)),
            request.headers(),
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
            secured: false,
            rule_domain: None,
            abuse_params: Vec::new(),
            account_keys: Vec::new(),
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
            authorization: Arc::new(Authorization::new()),
            ip,
            hostname,
            events: EventState::default(),
            listened: Arc::default(),
            response_headers: Vec::new(),
            db: None,
            platform_db: OnceLock::new(),
        }
    }

    /// `dbForProject`.
    pub fn db(&mut self) -> Result<Database> {
        if let Some(db) = &self.db {
            return Ok(db.clone());
        }
        let db = self.state.databases.project(&self.project, self.authorization.clone())?;
        // `x-appwrite-timeout` (the `api` group's database timeout hook):
        // development only.
        if self.state.config.development {
            let timeout = php_std::format::intval(
                php_std::format::Arg::Str(self.request.header_or_empty("x-appwrite-timeout").as_bytes()),
                10,
            );
            if timeout != 0 {
                db.set_timeout(timeout, Database::EVENT_ALL)?;
            }
        }
        // The console's database is the platform's, without listeners.
        if !self.project.is_console() && !self.project.id.is_empty() {
            listen(&db, &self.listened);
        }
        self.db = Some(db.clone());
        Ok(db)
    }

    /// `dbForPlatform`.
    pub fn platform_db(&self) -> Result<Database> {
        if let Some(db) = self.platform_db.get() {
            return Ok(db.clone());
        }
        let db = self.state.databases.platform(self.authorization.clone())?;
        Ok(self.platform_db.get_or_init(|| db).clone())
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

    /// The `queries` parameter validated by `schema`'s `Queries` validator,
    /// then parsed (`Query::parseQueries`).
    pub fn queries(&self, schema: &Schema) -> Result<Vec<Query>> {
        let Some(value) = self.param("queries") else {
            return Ok(Vec::new());
        };
        if value.is_null() {
            return Ok(Vec::new());
        }
        let value = utopia_database::Value::from_json(value);
        let validator = schema.validator();
        if !validator.is_valid(&value) {
            return Err(Error::invalid_param("queries", &validator.description()));
        }
        let utopia_database::Value::Array(items) = value else {
            return Ok(Vec::new());
        };
        items
            .values()
            .map(|q| {
                Query::parse(utopia_database::value::to_text(q))
                    .map_err(|e| Error::with_message(crate::ErrorType::GeneralQueryInvalid, e.message().to_owned()))
            })
            .collect()
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
    /// `setPayload($response->output(...))`: rendered before the response
    /// format's filters, as PHP's `output()` renders it.
    pub fn event_payload<T: Serialize>(&mut self, payload: &T) {
        self.events.payload = Some(Bytes::from(crate::response::current(payload)));
    }

    /// Records a usage metric (database listener equivalent).
    pub fn metric(&mut self, key: &str, value: i64) {
        self.listened.lock().unwrap_or_else(|e| e.into_inner()).metrics.push((key.to_owned(), value));
    }

    // ---- responses --------------------------------------------------------

    /// JSON response with PHP encoding.
    pub fn json<T: Serialize>(&self, status: StatusCode, model: &T) -> Response {
        Response::json_bytes(status, crate::json::to_vec(model))
    }

    pub fn ok<T: Serialize>(&self, model: &T) -> Response {
        self.json(StatusCode::OK, model)
    }

    pub fn created<T: Serialize>(&self, model: &T) -> Response {
        self.json(StatusCode::CREATED, model)
    }

    pub fn no_content(&self) -> Response {
        // `noContent()` sends the default content type.
        let mut response = Response::no_content_response();
        response.set("content-type", "text/html");
        response
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

/// What the project database's listeners saw during a request (PHP's
/// `dbForProject` listeners).
#[derive(Debug, Default)]
pub struct Listened {
    /// Users created (`create-trigger-events`): their
    /// `users.[userId].create` events.
    pub users: Vec<utopia_database::Document>,
    /// Usage metrics (`calculate-usage`, and [`Context::metric`]).
    pub metrics: Vec<(String, i64)>,
    /// A function was created, updated or deleted
    /// (`purge-function-events-cache`).
    pub functions: bool,
}

/// Registers PHP's listeners on a project database.
fn listen(db: &Database, listened: &Arc<Mutex<Listened>>) {
    let usage_events = [
        Database::EVENT_DOCUMENT_CREATE,
        Database::EVENT_DOCUMENT_DELETE,
        Database::EVENT_DOCUMENTS_CREATE,
        Database::EVENT_DOCUMENTS_DELETE,
        Database::EVENT_DOCUMENTS_UPSERT,
    ];
    for event in usage_events {
        let listened = listened.clone();
        db.on(
            event,
            "calculate-usage",
            Some(Arc::new(move |event: &str, value: &utopia_database::Value| {
                usage(&listened, event, value);
                Ok(())
            })),
        );
    }
    let created = listened.clone();
    db.on(
        Database::EVENT_DOCUMENT_CREATE,
        "create-trigger-events",
        Some(Arc::new(move |_: &str, value: &utopia_database::Value| {
            if let Some(document) = utopia_database::value::as_document(value)
                && document.collection() == "users"
            {
                created.lock().unwrap_or_else(|e| e.into_inner()).users.push(document.clone());
            }
            Ok(())
        })),
    );
    for event in [Database::EVENT_DOCUMENT_CREATE, Database::EVENT_DOCUMENT_UPDATE, Database::EVENT_DOCUMENT_DELETE] {
        let listened = listened.clone();
        db.on(
            event,
            "purge-function-events-cache",
            Some(Arc::new(move |_: &str, value: &utopia_database::Value| {
                if utopia_database::value::as_document(value).is_some_and(|d| d.collection() == "functions") {
                    listened.lock().unwrap_or_else(|e| e.into_inner()).functions = true;
                }
                Ok(())
            })),
        );
    }
}

/// `calculate-usage`: sessions and deployments counted as they are
/// created and deleted.
fn usage(listened: &Mutex<Listened>, event: &str, value: &utopia_database::Value) {
    let Some(document) = utopia_database::value::as_document(value) else { return };
    let count = |key: &str| document.get(key).map(utopia_database::value::to_long).unwrap_or(0);
    let value = match event {
        Database::EVENT_DOCUMENT_DELETE => -1,
        Database::EVENT_DOCUMENTS_DELETE => -count("modified"),
        Database::EVENT_DOCUMENTS_CREATE => count("modified"),
        Database::EVENT_DOCUMENTS_UPSERT => count("created"),
        _ => 1,
    };
    let mut listened = listened.lock().unwrap_or_else(|e| e.into_inner());
    match document.collection() {
        "sessions" => listened.metrics.push(("sessions".to_owned(), value)),
        "deployments" => {
            let kind = document.get("resourceType").and_then(utopia_database::value::as_str).unwrap_or("").to_owned();
            let size = count("size");
            listened.metrics.push((format!("{kind}.deployments"), value));
            listened.metrics.push((format!("{kind}.deployments.storage"), size * value));
        }
        _ => {}
    }
}
