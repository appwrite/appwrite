//! Per-request context handed to actions.

use std::sync::{Arc, Mutex, OnceLock};
use std::time::Instant;

use bytes::Bytes;
use http::StatusCode;
use serde::Serialize;
use serde_json::Value;
use utopia_database::{Authorization, Database, Query};
use utopia_http::{Request, Response, coerce};
use utopia_validators::{Validator, php};

use super::hooks::resources::database_listeners::{self, Listened, UsageMetric};
use super::hooks::resources::hostnames;
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

/// The session store a request carries (`Utopia\Auth\Store` as the `user`
/// resource decodes it from the cookie, `X-Appwrite-Session` or
/// `X-Fallback-Cookies`).
#[derive(Debug, Clone, Default)]
pub struct Store {
    /// `a_session_<projectId>` (the console's in admin mode).
    pub key: String,
    pub id: String,
    pub secret: String,
}

/// Event state filled by actions (`$queueForEvents`).
#[derive(Debug, Default)]
pub struct EventState {
    /// `setEvent()`: replaces the route's event pattern.
    pub event: Option<String>,
    pub params: Vec<(String, String)>,
    /// Explicit payload (`setPayload`); defaults to the response body.
    pub payload: Option<Bytes>,
    /// Extra delete messages to publish.
    pub deletes: Vec<Value>,
    /// Payload keys realtime leaves out (`setPayload($payload, sensitive: [...])`).
    pub sensitive: Vec<&'static str>,
    /// The event's user, when the action sets one (`$queueForEvents->setUser()`,
    /// or `$user->setAttributes($profile)`): its message payload. `None` keeps
    /// the request's.
    pub actor: Option<Value>,
    /// Messages for other worker queues (`(queue, message)`: mails, SMS),
    /// published after the created users' events, as PHP's listeners send
    /// those before the action's own publishers.
    pub queued: Vec<(String, Value)>,
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
    /// The action answered and then failed (PHP sends a redirect, then
    /// throws): the error hook runs instead of the shutdown hooks.
    pub failed: bool,
    /// The session store the request carries (`$store`): its key
    /// (`a_session_<project>`) and the decoded `id` and `secret`.
    pub store: Store,
    /// The response an init hook already sent (PHP's `$response->send()`
    /// before the action, `isSent()` after): the action is skipped, and an
    /// error a later hook throws is not sent.
    pub sent: Option<Response>,
    /// Whether the security headers were set when [`Context::send`] sent.
    pub sent_secured: bool,
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
            failed: false,
            store: Store::default(),
            sent: None,
            sent_secured: false,
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
            authorization: Arc::new(Authorization::new(true)),
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
        super::hooks::init::database_timeout::database_timeout(&self.state, &self.request, &db)?;
        // The console's database is the platform's, without listeners.
        if !self.project.is_console() && !self.project.id.is_empty() {
            database_listeners::listen(&db, &self.listened)?;
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
        Ok(coerce(value, validator))
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
        Ok(coerce(value, validator))
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
                Query::parse(&utopia_database::value::to_text(q), false)
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

    /// Publishes a message to a queue the PHP workers consume, now (as the
    /// action's publishers do: `$publisherForMails->enqueue(...)`).
    pub async fn enqueue<T: Serialize>(&self, queue: &str, message: &T) {
        use utopia_queue::Publisher as _;
        let published = match (utopia_queue::Queue::new(queue), utopia_queue::payload(message)) {
            (Ok(queue), Ok(payload)) => self.state.publisher.publish(&queue, payload).await.map(|_| ()),
            (Err(e), _) | (_, Err(e)) => Err(e),
        };
        if let Err(e) = published {
            tracing::warn!(error = %e, queue, "queue publish failed");
        }
    }

    /// Records a usage metric (database listener equivalent).
    pub fn metric(&mut self, key: &str, value: i64) {
        self.listened.lock().unwrap_or_else(|e| e.into_inner()).metrics.push(UsageMetric::new(key, value));
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

    /// Sends `response` from an init hook (`$response->send()`): with the
    /// headers added so far, and no action after it.
    pub fn send(&mut self, mut response: Response) {
        for (k, v) in &self.response_headers {
            response.add(k, v);
        }
        self.sent_secured = self.secured;
        self.sent = Some(response);
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

    /// The `allowedHostnames` resource (CORS, origins and redirects).
    pub fn allowed_hostnames(&self) -> Vec<String> {
        hostnames::allowed_hostnames(
            &self.state,
            &self.project,
            self.project_found,
            &self.request,
            self.rule_domain.as_deref(),
        )
    }

    /// The `allowedSchemes` resource.
    pub fn allowed_schemes(&self) -> Vec<String> {
        hostnames::allowed_schemes(&self.state, &self.project, self.project_found)
    }

    /// The `redirectValidator` resource: `url` must be on an allowed
    /// hostname or scheme. The error is the validator's description.
    pub fn validate_redirect(&self, url: &str) -> std::result::Result<(), String> {
        let hosts = hostnames::allowed_hostnames(
            &self.state,
            &self.project,
            self.project_found,
            &self.request,
            self.rule_domain.as_deref(),
        );
        let schemes = hostnames::allowed_schemes(&self.state, &self.project, self.project_found);
        crate::network::validate_redirect(url, &hosts, &schemes)
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
