//! Per-request context handed to actions.

use std::sync::{Arc, Mutex, OnceLock};
use std::time::Instant;

use async_trait::async_trait;
use bytes::Bytes;
use http::StatusCode;
use serde::Serialize;
use serde_json::Value;
use utopia_database::database::Hook;
use utopia_database::event::{Domain, Event};
use utopia_database::hook::{Lifecycle, Selective};
use utopia_database::{Authorization, Database, Query};
use utopia_http::{Request, Response, coerce};
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
        // `x-appwrite-timeout` (the `api` group's database timeout hook):
        // development only.
        if self.state.config.development {
            let timeout = php_std::format::intval(
                php_std::format::Arg::Str(self.request.header_or_empty("x-appwrite-timeout").as_bytes()),
                10,
            );
            if timeout != 0 {
                db.set_timeout(timeout, Event::All)?;
            }
        }
        // The console's database is the platform's, without listeners.
        if !self.project.is_console() && !self.project.id.is_empty() {
            listen(&db, &self.listened)?;
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
        super::lifecycle::allowed_hostnames(
            &self.state,
            &self.project,
            self.project_found,
            &self.request,
            self.rule_domain.as_deref(),
        )
    }

    /// The `allowedSchemes` resource.
    pub fn allowed_schemes(&self) -> Vec<String> {
        super::lifecycle::allowed_schemes(&self.state, &self.project, self.project_found)
    }

    /// The `redirectValidator` resource: `url` must be on an allowed
    /// hostname or scheme. The error is the validator's description.
    pub fn validate_redirect(&self, url: &str) -> std::result::Result<(), String> {
        let hosts = super::lifecycle::allowed_hostnames(
            &self.state,
            &self.project,
            self.project_found,
            &self.request,
            self.rule_domain.as_deref(),
        );
        let schemes = super::lifecycle::allowed_schemes(&self.state, &self.project, self.project_found);
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

/// The `team` resource: the organization that owns the project, or on the
/// console the one the route acts on: the project of a
/// `/v1/projects/:projectId` route, the `teamId` param of `/v1/projects`,
/// or `X-Appwrite-Organization` on an `organization` group route. Empty
/// when there is none. Authorization is skipped.
pub(super) async fn team(
    platform_db: &Database,
    project: &Project,
    route: &Route,
    request: &Request,
    path_params: &[(String, Value)],
) -> Result<utopia_database::Document> {
    let authorization = platform_db.authorization();
    let text = |document: &utopia_database::Document, key: &str| {
        document.get(key).map(utopia_database::value::to_text).unwrap_or_default()
    };
    let skipping = &authorization;
    let get = |collection: &'static str, id: String| async move {
        if id.is_empty() {
            return Ok(utopia_database::Document::default());
        }
        skipping.skip(platform_db.get_document(collection, &id, Vec::new(), false)).await
    };
    let team_internal_id = if !project.is_console() {
        project.team_internal_id.clone()
    } else {
        // The matched route's path; an unmatched request's URI.
        let path = if route.name == "wildcard" { request.uri() } else { route.path };
        let organization = request.header_or_empty("x-appwrite-organization");
        if path.starts_with("/v1/projects/:projectId") {
            text(&get("projects", project_id_from_path(request, path_params)).await?, "teamInternalId")
        } else if path == "/v1/projects" {
            let team_id = request.params().get("teamId").and_then(Value::as_str).unwrap_or_default().to_owned();
            return Ok(get("teams", team_id).await?);
        } else if route.has_group("organization") && !organization.is_empty() {
            return Ok(get("teams", organization.to_owned()).await?);
        } else {
            String::new()
        }
    };
    if team_internal_id.is_empty() {
        return Ok(utopia_database::Document::default());
    }
    Ok(authorization
        .skip(platform_db.find_one("teams", vec![Query::equal("$sequence", [team_internal_id.as_str()])]))
        .await?)
}

/// The `projectIdFromPath` resource: the `projectId` route param of a
/// `/v1/projects/...` URI, else empty.
fn project_id_from_path(request: &Request, path_params: &[(String, Value)]) -> String {
    let mut segments = request.uri().split('/').filter(|s| !s.is_empty());
    if segments.next() != Some("v1") || segments.next() != Some("projects") {
        return String::new();
    }
    path_params
        .iter()
        .find(|(name, _)| name == "projectId")
        .and_then(|(_, v)| v.as_str())
        .unwrap_or_default()
        .to_owned()
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
    pub metrics: Vec<UsageMetric>,
    /// A function was created, updated or deleted
    /// (`purge-function-events-cache`).
    pub functions: bool,
}

/// A usage metric a request records (`Appwrite\Usage\Context`'s
/// `addMetric()` and `addResourceMetric()`).
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct UsageMetric {
    pub key: String,
    pub value: i64,
    /// `addResourceMetric()`'s resource type and internal ID, over the
    /// request's (the project).
    pub resource: Option<(String, String)>,
}

impl UsageMetric {
    /// `addMetric()`: counted against the request's resource.
    pub fn new(key: &str, value: i64) -> Self {
        UsageMetric { key: key.to_owned(), value, resource: None }
    }
}

/// Registers PHP's hooks on a project database (the `dbForProject`
/// resource): `Usage`, `UserEvents` and `FunctionCache`, in that order.
fn listen(db: &Database, listened: &Arc<Mutex<Listened>>) -> Result<()> {
    db.add_hook(Hook::lifecycle(Arc::new(Usage(listened.clone()))))?;
    db.add_hook(Hook::lifecycle(Arc::new(UserEvents(listened.clone()))))?;
    db.add_hook(Hook::lifecycle(Arc::new(FunctionCache(listened.clone()))))?;
    Ok(())
}

fn lock(listened: &Mutex<Listened>) -> std::sync::MutexGuard<'_, Listened> {
    listened.lock().unwrap_or_else(|e| e.into_inner())
}

/// `Appwrite\Utopia\Database\Hook\Usage`: sessions and deployments counted
/// as they are created and deleted.
struct Usage(Arc<Mutex<Listened>>);

impl Usage {
    fn track_document(&self, collection: &str, document: &utopia_database::Document, value: i64) {
        let mut listened = lock(&self.0);
        match collection {
            "sessions" => listened.metrics.push(UsageMetric::new("sessions", value)),
            "deployments" => {
                let kind = document.get("resourceType").map(utopia_database::value::to_text).unwrap_or_default();
                let size = document.get("size").map(utopia_database::value::to_long).unwrap_or(0);
                // `addResourceMetric()`: counted against the deployment's
                // function or site (`rtrim($resourceType, 's')`).
                let owner = kind.trim_end_matches('s').to_owned();
                let owner_id =
                    document.get("resourceInternalId").map(utopia_database::value::to_text).unwrap_or_default();
                let resource = Some((owner, owner_id));
                listened.metrics.push(UsageMetric {
                    key: format!("{kind}.deployments"),
                    value,
                    resource: resource.clone(),
                });
                listened.metrics.push(UsageMetric {
                    key: format!("{kind}.deployments.storage"),
                    value: size * value,
                    resource,
                });
            }
            _ => {}
        }
    }

    fn track_batch(&self, collection: &str, value: i64) {
        if collection == "sessions" {
            lock(&self.0).metrics.push(UsageMetric::new("sessions", value));
        }
    }
}

#[async_trait]
impl Lifecycle for Usage {
    async fn handle(&self, event: &Domain) -> utopia_database::Result<()> {
        match event {
            Domain::DocumentCreated { collection, document } => self.track_document(collection, document, 1),
            Domain::DocumentDeleted { collection, document } => self.track_document(collection, document, -1),
            Domain::DocumentBatchCreated { collection, count } => self.track_batch(collection, *count),
            Domain::DocumentBatchDeleted { collection, count } => self.track_batch(collection, -count),
            Domain::DocumentBatchUpserted { collection, created, .. } => self.track_batch(collection, *created),
            _ => {}
        }
        Ok(())
    }

    fn selective(&self) -> Option<&dyn Selective> {
        Some(self)
    }
}

impl Selective for Usage {
    fn handles(&self, event: Event) -> bool {
        matches!(
            event,
            Event::DocumentCreate
                | Event::DocumentDelete
                | Event::DocumentsCreate
                | Event::DocumentsDelete
                | Event::DocumentsUpsert
        )
    }
}

/// `Appwrite\Utopia\Database\Hook\UserEvents`: each user created, for the
/// `users.[userId].create` events the shutdown triggers.
struct UserEvents(Arc<Mutex<Listened>>);

#[async_trait]
impl Lifecycle for UserEvents {
    async fn handle(&self, event: &Domain) -> utopia_database::Result<()> {
        if let Domain::DocumentCreated { collection, document } = event
            && collection == "users"
        {
            lock(&self.0).users.push(document.clone());
        }
        Ok(())
    }

    fn selective(&self) -> Option<&dyn Selective> {
        Some(self)
    }
}

impl Selective for UserEvents {
    fn handles(&self, event: Event) -> bool {
        event == Event::DocumentCreate
    }
}

/// `Appwrite\Utopia\Database\Hook\FunctionCache`: a function created,
/// updated or deleted, for the shutdown to purge the function events cache.
struct FunctionCache(Arc<Mutex<Listened>>);

#[async_trait]
impl Lifecycle for FunctionCache {
    async fn handle(&self, event: &Domain) -> utopia_database::Result<()> {
        if let Domain::DocumentCreated { collection, .. }
        | Domain::DocumentUpdated { collection, .. }
        | Domain::DocumentDeleted { collection, .. } = event
            && collection == "functions"
        {
            lock(&self.0).functions = true;
        }
        Ok(())
    }

    fn selective(&self) -> Option<&dyn Selective> {
        Some(self)
    }
}

impl Selective for FunctionCache {
    fn handles(&self, event: Event) -> bool {
        matches!(event, Event::DocumentCreate | Event::DocumentUpdate | Event::DocumentDelete)
    }
}

#[cfg(test)]
mod usage_tests {
    use utopia_database::{Document, Value};

    use super::*;

    fn document(fields: &[(&str, Value)]) -> Document {
        let mut document = Document::default();
        for (key, value) in fields {
            document.put(*key, value.clone());
        }
        document
    }

    /// The metrics PHP's `Usage` hook records for the same events (printed
    /// by `Appwrite\Usage\Context::getMetrics()` on the oracle): a
    /// deployment counts against its function or site, a session against
    /// the request's resource.
    #[utopia_async::test]
    async fn deployments_count_against_their_owner() {
        let listened = Arc::new(Mutex::new(Listened::default()));
        let usage = Usage(listened.clone());
        let created = document(&[
            ("resourceType", Value::from("functions")),
            ("resourceInternalId", Value::Int(12)),
            ("size", Value::from("2048")),
        ]);
        let deleted = document(&[
            ("resourceType", Value::from("sites")),
            ("resourceInternalId", Value::from("3")),
            ("size", Value::Int(10)),
        ]);
        let events = [
            Domain::DocumentCreated { collection: "deployments".into(), document: created },
            Domain::DocumentDeleted { collection: "deployments".into(), document: deleted },
            Domain::DocumentCreated { collection: "sessions".into(), document: Document::default() },
        ];
        for event in &events {
            usage.handle(event).await.unwrap();
        }
        let owner = |kind: &str, id: &str| Some((kind.to_owned(), id.to_owned()));
        let expected = vec![
            UsageMetric { key: "functions.deployments".into(), value: 1, resource: owner("function", "12") },
            UsageMetric { key: "functions.deployments.storage".into(), value: 2048, resource: owner("function", "12") },
            UsageMetric { key: "sites.deployments".into(), value: -1, resource: owner("site", "3") },
            UsageMetric { key: "sites.deployments.storage".into(), value: -10, resource: owner("site", "3") },
            UsageMetric::new("sessions", 1),
        ];
        assert_eq!(lock(&listened).metrics, expected);
    }
}

#[cfg(test)]
mod team_tests {
    use utopia_database::{Attribute, Collection, Document, Value};

    use super::*;

    /// A platform database with an organization `t1` and its project `p1`.
    async fn platform() -> (Database, String) {
        let cache = Arc::new(utopia_cache::Cache::from_dyn(Arc::new(utopia_cache::Memory::new())));
        let db = Database::new(Arc::new(utopia_database::adapter::memory::Memory::new()), cache);
        db.set_authorization(crate::database::Databases::unchecked());
        db.set_database("appwrite").set_namespace("_console");
        db.create(None).await.unwrap();
        for (id, attributes) in [
            ("teams", vec![Attribute::string("name", 128)]),
            ("projects", vec![Attribute::string("teamInternalId", 64)]),
        ] {
            let collection = Collection::create(id, id, attributes, vec![], None, false, Default::default()).unwrap();
            db.create_collection(collection).await.unwrap();
        }
        let mut team = Document::default();
        team.put("$id", Value::from("t1"));
        team.put("name", Value::from("Org"));
        let team = db.create_document("teams", team).await.unwrap();
        let sequence = team.sequence().map(utopia_database::value::to_text).unwrap_or_default();
        let mut project = Document::default();
        project.put("$id", Value::from("p1"));
        project.put("teamInternalId", Value::from(sequence.as_str()));
        db.create_document("projects", project).await.unwrap();
        (db, sequence)
    }

    fn route(path: &'static str, groups: &'static [&'static str]) -> Route {
        Route { path, name: "route", groups, ..super::super::lifecycle::wildcard_route() }
    }

    async fn team_of(
        db: &Database,
        project: &Project,
        route: &Route,
        target: &str,
        headers: &[(&str, &str)],
        path_params: &[(&str, &str)],
    ) -> String {
        let request = Request::new("GET", target, headers.iter().copied(), Bytes::new());
        let params: Vec<(String, serde_json::Value)> =
            path_params.iter().map(|(k, v)| (k.to_string(), serde_json::Value::String(v.to_string()))).collect();
        team(db, project, route, &request, &params).await.unwrap().id().to_owned()
    }

    /// PHP's `team` resource: a project's own organization; on the console,
    /// the organization a projects route or an `organization` route names.
    #[utopia_async::test]
    async fn teams_follow_php() {
        let (db, sequence) = platform().await;
        let users = route("/v1/users", &["api", "users"]);
        let mut project = Project::empty();
        project.id = "p1".into();
        project.team_internal_id = sequence;
        assert_eq!(team_of(&db, &project, &users, "/v1/users", &[], &[]).await, "t1");
        project.team_internal_id = String::new();
        assert_eq!(team_of(&db, &project, &users, "/v1/users", &[], &[]).await, "");

        let mut console = Project::empty();
        console.id = Project::CONSOLE.into();
        let organization = [("x-appwrite-organization", "t1")];
        // Console routes name no organization...
        assert_eq!(team_of(&db, &console, &users, "/v1/users", &organization, &[]).await, "");
        // ...unless they act on a project,
        let project_route = route("/v1/projects/:projectId", &["api", "projects"]);
        let path = [("projectId", "p1")];
        assert_eq!(team_of(&db, &console, &project_route, "/v1/projects/p1", &[], &path).await, "t1");
        let missing = [("projectId", "missing")];
        assert_eq!(team_of(&db, &console, &project_route, "/v1/projects/missing", &[], &missing).await, "");
        // list the projects of a `teamId`,
        let projects = route("/v1/projects", &["api", "projects"]);
        assert_eq!(team_of(&db, &console, &projects, "/v1/projects?teamId=t1", &[], &[]).await, "t1");
        assert_eq!(team_of(&db, &console, &projects, "/v1/projects", &organization, &[]).await, "");
        // or are in the `organization` group, with the header.
        let keys = route("/v1/organization/keys", &["api", "organization"]);
        assert_eq!(team_of(&db, &console, &keys, "/v1/organization/keys", &organization, &[]).await, "t1");
        assert_eq!(team_of(&db, &console, &keys, "/v1/organization/keys", &[], &[]).await, "");
    }
}
