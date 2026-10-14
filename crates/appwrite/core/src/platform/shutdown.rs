//! Shutdown hooks: events (realtime, functions, webhooks), deletes, audits,
//! usage and onboarding. They run after the response is produced, in a
//! background task, exactly like PHP runs them after `send()`.

use std::sync::Arc;

use bytes::Bytes;
use http::StatusCode;
use serde_json::value::RawValue;
use serde_json::{Map, Value};
use utopia_database::value::to_text;
use utopia_database::{Database, Document, Query};
use utopia_queue::{Publisher as _, Queue};

use super::context::{Context, Mode};
use super::{Route, State};
use crate::auth::{KeyType, roles};
use crate::database::documents::{Attributes, User};
use crate::database::{Databases, Project, filters};
use crate::datetime;
use crate::event::{self, FunctionMessage, Metric, WebhookMessage};

struct RequestInfo {
    path: String,
    method: String,
    user_agent: String,
    hostname: String,
    ip: String,
    sdk: String,
    /// `X-SDK-Name` and `X-SDK-Language` as sent (onboarding's install stages).
    sdk_name: String,
    sdk_language: String,
    sdk_version: String,
    protocol: String,
    accept: String,
    accept_language: String,
    size: usize,
}

struct Job {
    state: Arc<State>,
    project: Arc<Project>,
    route: Arc<Route>,
    mode: Mode,
    key_kind: Option<KeyType>,
    actor: Value,
    has_user: bool,
    privileged: bool,
    params: Vec<(String, String)>,
    payload: Bytes,
    /// Payload keys left out of realtime messages.
    sensitive: Vec<&'static str>,
    created_users: Vec<(String, Bytes)>,
    /// An API key's disabled metrics: suffixes of the metric keys left out.
    disabled_metrics: Vec<String>,
    /// The user whose session the request created (the session limit).
    session_user: Option<String>,
    /// The abuse keys' parameters (the limits' reset).
    abuse_params: Vec<(String, String)>,
    /// A function changed: its events cache is purged.
    functions_changed: bool,
    deletes: Vec<Value>,
    metrics: Vec<(String, i64)>,
    request: RequestInfo,
    status: StatusCode,
    response_size: usize,
}

/// The onboarding stages (`app/config/onboarding.php`).
const ONBOARDING: &[&str] = &[
    "project.createWebPlatform",
    "project.createAndroidPlatform",
    "project.createApplePlatform",
    "project.createWindowsPlatform",
    "project.createLinuxPlatform",
    "project.createKey",
    "organization.createProjectKey",
    "cli.install",
    "mcp.install",
    "users.create",
    "account.create",
    "account.createAnonymousSession",
    "teams.create",
    "tablesDB.create",
    "tablesDB.createTable",
    "tablesDB.createRow",
    "documentsDB.create",
    "documentsDB.createCollection",
    "documentsDB.createDocument",
    "databases.create",
    "databases.createCollection",
    "databases.createDocument",
    "mysql.create",
    "postgresql.create",
    "mongo.create",
    "storage.createBucket",
    "storage.createFile",
    "functions.create",
    "functions.createDeployment",
    "functions.createTemplateDeployment",
    "functions.createVcsDeployment",
    "functions.updateFunctionDeployment",
    "messaging.createTopic",
    "messaging.createMailgunProvider",
    "messaging.createSendgridProvider",
    "messaging.createSesProvider",
    "messaging.createResendProvider",
    "messaging.createSmtpProvider",
    "messaging.createSMTPProvider",
    "messaging.createMsg91Provider",
    "messaging.createTelesignProvider",
    "messaging.createTextmagicProvider",
    "messaging.createTwilioProvider",
    "messaging.createVonageProvider",
    "messaging.createFcmProvider",
    "messaging.createFCMProvider",
    "messaging.createApnsProvider",
    "messaging.createAPNSProvider",
    "sites.create",
    "sites.createDeployment",
    "sites.createTemplateDeployment",
    "sites.createVcsDeployment",
    "sites.updateSiteDeployment",
];

pub(super) fn schedule(ctx: Context, status: StatusCode, body: Bytes, response_size: usize) {
    let request = &ctx.request;
    let origin_host = request.header("origin").and_then(crate::network::url_host);
    let info = RequestInfo {
        path: request.path().to_owned(),
        method: request.method().to_owned(),
        user_agent: request.header_or_empty("user-agent").to_owned(),
        hostname: origin_host.unwrap_or_else(|| ctx.hostname.clone()),
        ip: ctx.ip.clone(),
        sdk: request.header_or_empty("x-sdk-name").to_lowercase(),
        sdk_name: request.header_or_empty("x-sdk-name").to_owned(),
        sdk_language: request.header_or_empty("x-sdk-language").to_owned(),
        sdk_version: request.header_or_empty("x-sdk-version").to_owned(),
        protocol: request
            .header("x-forwarded-proto")
            .map(|p| p.split(',').next().unwrap_or("").trim().to_lowercase())
            .unwrap_or_else(|| "http".to_owned()),
        accept: request.header_or_empty("accept").to_owned(),
        accept_language: request.header_or_empty("accept-language").to_owned(),
        // PHP's `Request::getSize()` adds `php://input`, which Swoole leaves
        // empty: the body is never counted. Kept for parity (a PHP bug to fix
        // in both runtimes together).
        size: request.size(),
    };
    // PHP's events carry the `user` resource's context: empty for a project
    // or an organization key, the account for an account key.
    // An action may name the event's user itself (`setUser()`).
    let actor = match (&ctx.events.actor, &ctx.key, ctx.roles_user()) {
        (Some(actor), _, _) => actor.clone(),
        (None, Some(key), Some(u)) if key.kind == KeyType::Account => u.message_payload(),
        (None, Some(_), _) | (None, None, None) => event::empty_user(),
        (None, None, Some(u)) => u.message_payload(),
    };
    // The `session` group's session limit: the user of the session created.
    let session_user = if ctx.route.has_group("session") && status.is_success() {
        serde_json::from_slice::<Value>(&body)
            .ok()
            .and_then(|b| b.get("userId").and_then(Value::as_str).map(str::to_owned))
            .filter(|id| !id.is_empty())
    } else {
        None
    };
    let payload = ctx.events.payload.clone().unwrap_or(body);
    let listened = std::mem::take(&mut *ctx.listened.lock().unwrap_or_else(|e| e.into_inner()));
    // `create-trigger-events`: each created user, as the listener renders it.
    let created_users = listened
        .users
        .iter()
        .map(|document| {
            let user = User::from(document);
            let payload = crate::response::current(&crate::response::UserModel { user: &user, render: ctx.render() });
            (user.id.clone(), Bytes::from(payload))
        })
        .collect();
    let job = Job {
        state: ctx.state.clone(),
        project: ctx.project.clone(),
        route: ctx.route.clone(),
        mode: ctx.mode,
        key_kind: ctx.key.as_ref().filter(|k| k.role == roles::KEYS).map(|k| k.kind),
        disabled_metrics: ctx.key.as_ref().map(|k| k.disabled_metrics.clone()).unwrap_or_default(),
        actor,
        has_user: ctx.user.is_some(),
        privileged: roles::is_privileged(&ctx.roles),
        params: ctx.events.params,
        payload,
        sensitive: ctx.events.sensitive,
        created_users,
        functions_changed: listened.functions,
        session_user,
        abuse_params: ctx.abuse_params,
        deletes: ctx.events.deletes,
        metrics: listened.metrics,
        request: info,
        status,
        response_size,
    };
    tokio::spawn(job.run());
}

fn raw(bytes: &Bytes) -> Box<RawValue> {
    let text = std::str::from_utf8(bytes).unwrap_or("{}");
    RawValue::from_string(if text.is_empty() { "{}".to_owned() } else { text.to_owned() })
        .unwrap_or_else(|_| RawValue::from_string("{}".to_owned()).expect("valid json"))
}

impl Job {
    async fn run(self) {
        // Functions, webhooks and events all carry the project's context.
        let project_payload = self.project.message_payload();
        let trimmed = project_payload.clone();

        super::guards::abuse_reset(&self.state, self.route.labels, self.status.as_u16(), &self.abuse_params).await;
        self.session_limit().await;

        // `purge-function-events-cache`.
        if self.functions_changed
            && let Some(key) = self.function_events_key()
        {
            let _ = self.state.cache.purge(&key, "").await;
        }

        for delete in &self.deletes {
            self.publish(&self.state.config.queue_deletes, delete).await;
        }

        // Database listener: `users.[userId].create`.
        for (user_id, payload) in &self.created_users {
            let params = vec![("userId".to_owned(), user_id.clone())];
            let Ok(events) = event::generate_events("users.[userId].create", &params) else { continue };
            let payload = raw(payload);
            let message =
                FunctionMessage::new(&project_payload, &self.actor, &payload, &events, &self.state.config.platform);
            self.publish(&self.state.config.queue_functions, &message).await;
            if !self.project.webhooks.is_empty() {
                let message = WebhookMessage {
                    project: &trimmed,
                    user: &self.actor,
                    user_id: None,
                    payload: &payload,
                    context: [],
                    events: &events,
                    event_id: crate::database::unique_id(),
                };
                self.publish(&self.state.config.queue_webhooks, &message).await;
            }
            if !self.project.is_console() {
                self.realtime(&events, Some(user_id), &payload).await;
            }
        }

        if let Some(pattern) = self.route.event {
            match event::generate_events(pattern, &self.params) {
                Ok(events) => self.trigger(&events, &project_payload, &trimmed).await,
                Err(e) => tracing::warn!(error = %e, pattern, "event generation failed"),
            }
        }

        self.audit().await;
        self.usage().await;
        self.onboarding().await;
    }

    async fn publish<T: serde::Serialize>(&self, queue: &str, payload: &T) {
        let published = match (Queue::new(queue), utopia_queue::payload(payload)) {
            (Ok(queue), Ok(payload)) => self.state.publisher.publish(&queue, payload).await.map(|_| ()),
            (Err(e), _) | (_, Err(e)) => Err(e),
        };
        if let Err(e) = published {
            tracing::warn!(error = %e, queue, "queue publish failed");
        }
    }

    async fn realtime(&self, events: &[String], user_id: Option<&str>, payload: &RawValue) {
        let Some(first) = events.first() else { return };
        let Some(target) = event::realtime_target(first) else { return };
        let message = event::realtime_message(&self.project.id, &target, user_id, events, payload);
        let mut pubsub = self.state.pubsub.clone();
        let sent: Result<i64, _> =
            utopia_cache::redis::cmd("PUBLISH").arg("realtime").arg(&message[..]).query_async(&mut pubsub).await;
        if let Err(e) = sent {
            tracing::warn!(error = %e, "realtime publish failed");
        }
    }

    async fn trigger(&self, events: &[String], project_payload: &Value, trimmed: &Value) {
        let payload = raw(&self.payload);
        let user_id = self.params.iter().find(|(k, _)| k == "userId").map(|(_, v)| v.as_str());
        if !self.project.is_console() {
            if self.sensitive.is_empty() {
                self.realtime(events, user_id, &payload).await;
            } else {
                // `Realtime::getRealtimePayload()`: the sensitive keys left out.
                let mut trimmed: serde_json::Map<String, Value> =
                    serde_json::from_slice(&self.payload).unwrap_or_default();
                trimmed.retain(|k, _| !self.sensitive.contains(&k.as_str()));
                let bytes = Bytes::from(crate::json::to_vec(&Value::Object(trimmed)));
                self.realtime(events, user_id, &raw(&bytes)).await;
            }
        }
        let function_events = self.function_events().await;
        if events.iter().any(|e| function_events.contains(e)) {
            let message =
                FunctionMessage::new(project_payload, &self.actor, &payload, events, &self.state.config.platform);
            self.publish(&self.state.config.queue_functions, &message).await;
        }
        let webhook_events: Vec<&String> =
            self.project.webhooks.iter().filter(|w| w.enabled).flat_map(|w| w.events.iter()).collect();
        if events.iter().any(|e| webhook_events.contains(&e)) {
            let message = WebhookMessage {
                project: trimmed,
                user: &self.actor,
                user_id: None,
                payload: &payload,
                context: [],
                events,
                event_id: crate::database::unique_id(),
            };
            self.publish(&self.state.config.queue_webhooks, &message).await;
        }
    }

    /// The `session` group's limit: the user's oldest sessions beyond the
    /// project's `maxSessions` are deleted.
    async fn session_limit(&self) {
        let Some(user_id) = &self.session_user else { return };
        let limit = self.project.auths.get("maxSessions").and_then(Value::as_i64).unwrap_or(10);
        if limit == 0 {
            return;
        }
        let Ok(db) = self.state.databases.project(&self.project, Databases::unchecked()) else { return };
        let Ok(Some(user)) = crate::database::documents::load_user(&db, user_id).await else { return };
        let excess = user.sessions.len() as i64 - limit;
        if excess <= 0 {
            return;
        }
        for session in user.sessions.iter().take(excess as usize) {
            if let Err(e) = db.delete_document("sessions", &session.id).await {
                tracing::warn!(error = %e, "session limit delete failed");
            }
        }
        let _ = db.purge_cached_document("users", Some(user_id)).await;
    }

    /// The project's functions events cache key (none for the console).
    fn function_events_key(&self) -> Option<String> {
        if self.project.is_console() {
            return None;
        }
        let db = self.state.databases.project(&self.project, Databases::unchecked()).ok()?;
        Some(format!(
            "{}-cache-{}:{}:{}:project:{}:functions:events",
            db.cache_name(),
            db.adapter().hostname(),
            db.namespace(),
            to_text(&db.tenant()),
            self.project.id
        ))
    }

    /// Union of `functions.events` for the project, cached in the same Redis
    /// hash PHP uses (`...:project:<id>:functions:events`), so PHP's purges
    /// drop it, under a field only Rust reads: a native entry.
    async fn function_events(&self) -> Vec<String> {
        let Some(key) = self.function_events_key() else { return Vec::new() };
        let Ok(db) = self.state.databases.project(&self.project, Databases::unchecked()) else { return Vec::new() };
        // `v2` held a PHP array; `v3` is a native `Vec<String>`.
        const FIELD: &str = "rust:functions:events:v3";
        if let Ok(Some(cached)) = self.state.cache.load_native::<Vec<String>>(&key, 3600, FIELD).await {
            return cached;
        }
        // Read before the database, so a purge in between rejects the save.
        let generation = self.state.cache.generation(&key).await.ok();
        // `EventProcessor::getFunctionsEvents`: every function's events, by
        // pages of 100.
        let mut events: Vec<String> = Vec::new();
        let limit = 100;
        let mut offset = 0;
        loop {
            let page = db
                .find(
                    "functions",
                    vec![
                        Query::select(["$id", "events"]),
                        Query::limit(limit),
                        Query::offset(offset),
                        Query::order_asc("$sequence"),
                    ],
                    Database::PERMISSION_READ,
                )
                .await;
            let functions = match page {
                Ok(functions) => functions,
                Err(e) => {
                    tracing::warn!(error = %e, "functions events lookup failed");
                    return Vec::new();
                }
            };
            for function in &functions {
                for event in function.strings("events") {
                    if !events.contains(&event) {
                        events.push(event);
                    }
                }
            }
            if (functions.len() as i64) < limit {
                break;
            }
            offset += limit;
        }
        if let Some(generation) = generation {
            let _ = self.state.cache.save_native_with_lease(&key, &events, FIELD, &generation).await;
        }
        events
    }

    async fn audit(&self) {
        let Some(audit) = self.route.audit else { return };
        if self.state.config.edition == "self-hosted" {
            return;
        }
        let resource = render_template(audit.resource, &self.params, &self.payload);
        if resource.is_empty() {
            return;
        }
        let message = serde_json::json!({
            "project": self.project.message_payload(),
            "user": self.actor,
            "impersonatorUser": [],
            // `audits.payload`, which no route Rust serves sets.
            "payload": [],
            "resource": resource,
            "mode": if self.mode == Mode::Admin { "admin" } else { "default" },
            "ip": self.request.ip,
            "userAgent": self.request.user_agent,
            "event": audit.event,
            "hostname": self.request.hostname,
            "sdk": self.request.sdk,
            "sdkVersion": self.request.sdk_version,
        });
        self.publish(&self.state.config.queue_audits, &message).await;
    }

    async fn usage(&self) {
        if self.project.is_console() || !self.state.config.usage_stats {
            return;
        }
        // `general.php` looks the IP up for every API request; here it runs
        // after the response, from the in-process cache when it can.
        let country = match self.state.geo.country_code(&self.request.ip).await.as_str() {
            "--" => String::new(),
            code => code.to_lowercase(),
        };
        let base = |key: &str, value: i64| Metric {
            key: key.to_owned(),
            value,
            path: self.request.path.clone(),
            method: self.request.method.clone(),
            status: self.status.as_u16(),
            service: self.route.namespace.to_owned(),
            resource_type: "project".to_owned(),
            resource_id: self.project.id.clone(),
            resource_internal_id: self.project.sequence.clone(),
            resource_path: String::new(),
            team_id: String::new(),
            team_internal_id: String::new(),
            country: country.clone(),
            region: self.state.config.region.clone(),
            hostname: self.request.hostname.clone(),
            user_agent: self.request.user_agent.clone(),
            ip: self.request.ip.clone(),
            sdk: self.request.sdk.clone(),
            sdk_version: self.request.sdk_version.clone(),
            protocol: self.request.protocol.clone(),
            accept: self.request.accept.clone(),
            accept_language: self.request.accept_language.clone(),
            query_keys: String::new(),
        };
        let mut metrics: Vec<Metric> = self.metrics.iter().map(|(k, v)| base(k, *v)).collect();
        if !self.privileged {
            metrics.push(base("network.requests", 1));
            metrics.push(base("network.inbound", self.request.size as i64));
            metrics.push(base("network.outbound", self.response_size as i64));
        }
        if metrics.is_empty() {
            return;
        }
        // The message is still sent when the key disabled every metric.
        if !self.disabled_metrics.is_empty() {
            metrics.retain(|m| !self.disabled_metrics.iter().any(|p| m.key.ends_with(p.as_str())));
        }
        let message = event::usage_message(self.project.trimmed_payload(), &metrics);
        self.publish(&self.state.config.queue_usage, &message).await;
    }

    async fn onboarding(&self) {
        if !self.status.is_success() || self.project.is_console() {
            return;
        }
        // The route's SDK method, and the CLI or MCP install named by the
        // client's SDK headers.
        let mut methods: Vec<String> = Vec::new();
        let sdk = self.request.sdk_name.as_str();
        let cli = matches!(sdk, "cli" | "Command Line") || self.request.sdk_language == "cli";
        if self.route.namespace.is_empty() && sdk != "mcp" && !cli {
            return;
        }
        let method = format!("{}.{}", self.route.namespace, self.route.sdk_method);
        if ONBOARDING.contains(&method.as_str()) {
            methods.push(method);
        }
        let install = match sdk {
            "mcp" => Some("mcp.install"),
            _ if cli => Some("cli.install"),
            _ => None,
        };
        if let Some(install) = install.filter(|i| ONBOARDING.contains(i)) {
            methods.push(install.to_owned());
        }
        if methods.is_empty() {
            return;
        }
        let mut stages = match &self.project.onboarding {
            Value::Object(m) => m.clone(),
            _ => Map::new(),
        };
        methods.retain(|m| stages.get(m).and_then(|r| r.get("status")).and_then(Value::as_str) != Some("completed"));
        if methods.is_empty() {
            return;
        }
        let actor_type = match self.key_kind {
            Some(KeyType::Account) => "keyAccount",
            Some(KeyType::Organization) => "keyOrganization",
            Some(_) => "keyProject",
            None if self.has_user => {
                if self.mode == Mode::Admin {
                    "admin"
                } else {
                    "user"
                }
            }
            None => "guest",
        };
        let at = datetime::format_db(&datetime::now());
        for method in methods {
            let mut row = Map::new();
            row.insert("status".into(), Value::String("completed".into()));
            row.insert("at".into(), Value::String(at.clone()));
            row.insert("actorType".into(), Value::String(actor_type.into()));
            stages.insert(method, Value::Object(row));
        }
        // The `json` filter passes text through: stored as PHP encodes it.
        let encoded = crate::json::to_string(&Value::Object(stages));
        let Ok(db) = self.state.databases.platform(Databases::unchecked()) else { return };
        let project = self.project.clone();
        let state = self.state.clone();
        let lock_key = format!("lock:platform:{}:onboarding", project.sequence);
        let _ = self
            .state
            .lock
            .try_with_key(&lock_key, async move {
                let mut updates = Document::default();
                updates.set("onboarding", utopia_database::Value::from(encoded.as_str()));
                let skipped = filters::names(filters::PROJECTS_SUBQUERIES);
                let updated =
                    db.skip_filters(|| db.update_document("projects", &project.id, updates), Some(&skipped)).await;
                // A missing `onboarding` attribute must not break the request.
                if updated.is_ok() {
                    state.projects.forget(&project.id);
                }
            })
            .await;
    }
}

/// Renders audit resource templates (`user/{request.userId}`, `user/{response.$id}`).
fn render_template(template: &str, params: &[(String, String)], payload: &Bytes) -> String {
    let mut out = template.to_owned();
    if out.contains("{response.") {
        let response: Value = serde_json::from_slice(payload).unwrap_or(Value::Null);
        while let Some(start) = out.find("{response.") {
            let Some(end) = out[start..].find('}') else { break };
            let key = &out[start + 10..start + end];
            let value = response.get(key).and_then(Value::as_str).unwrap_or("").to_owned();
            out.replace_range(start..start + end + 1, &value);
        }
    }
    while let Some(start) = out.find("{request.") {
        let Some(end) = out[start..].find('}') else { break };
        let key = &out[start + 9..start + end];
        let value = params.iter().find(|(k, _)| k == key).map(|(_, v)| v.clone()).unwrap_or_default();
        out.replace_range(start..start + end + 1, &value);
    }
    out
}

#[cfg(test)]
mod onboarding_tests {
    /// The stages match `app/config/onboarding.php`.
    #[test]
    fn stages_follow_the_php_config() {
        let path = concat!(env!("CARGO_MANIFEST_DIR"), "/../../../app/config/onboarding.php");
        let php = std::fs::read_to_string(path).expect("onboarding.php");
        let keys: Vec<&str> = php
            .lines()
            .map(str::trim)
            .filter(|l| l.starts_with('\'') && l.ends_with("=> true,"))
            .filter_map(|l| l[1..].split('\'').next())
            .collect();
        assert_eq!(keys, super::ONBOARDING);
    }
}
