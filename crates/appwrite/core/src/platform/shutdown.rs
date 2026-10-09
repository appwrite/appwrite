//! Shutdown hooks: events (realtime, functions, webhooks), deletes, audits,
//! usage and onboarding. They run after the response is produced, in a
//! background task, exactly like PHP runs them after `send()`.

use std::sync::Arc;

use bytes::Bytes;
use http::StatusCode;
use serde_json::value::RawValue;
use serde_json::{Map, Value};
use utopia_database::sql::Builder;
use utopia_database::{Param, datetime};
use utopia_queue::Queue;

use super::context::{Context, Mode};
use super::{Route, State};
use crate::auth::{KeyType, roles};
use crate::database::Project;
use crate::event::{self, FunctionMessage, Metric, WebhookMessage};

struct RequestInfo {
    path: String,
    method: String,
    user_agent: String,
    hostname: String,
    ip: String,
    sdk: String,
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
    created_users: Vec<(String, Bytes)>,
    deletes: Vec<Value>,
    metrics: Vec<(&'static str, i64)>,
    request: RequestInfo,
    status: StatusCode,
    response_size: usize,
}

pub(super) fn schedule(ctx: Context, status: StatusCode, body: Bytes, response_size: usize) {
    let request = &ctx.request;
    let origin_host = request.header("origin").and_then(crate::network::url_host);
    let info = RequestInfo {
        path: request.path.clone(),
        method: request.method.as_str().to_owned(),
        user_agent: request.header_or_empty("user-agent").to_owned(),
        hostname: origin_host.unwrap_or_else(|| ctx.hostname.clone()),
        ip: ctx.ip.clone(),
        sdk: request.header_or_empty("x-sdk-name").to_lowercase(),
        sdk_version: request.header_or_empty("x-sdk-version").to_owned(),
        protocol: request
            .header("x-forwarded-proto")
            .map(|p| p.split(',').next().unwrap_or("").trim().to_lowercase())
            .unwrap_or_else(|| "http".to_owned()),
        accept: request.header_or_empty("accept").to_owned(),
        accept_language: request.header_or_empty("accept-language").to_owned(),
        size: request.size() + request.body.len(),
    };
    let actor = match (&ctx.key, ctx.roles_user()) {
        (Some(_), _) | (None, None) => event::empty_user(),
        (None, Some(u)) => event::actor(&u.id, u.email.as_deref(), u.name.as_deref()),
    };
    let payload = ctx.events.payload.clone().unwrap_or(body);
    let job = Job {
        state: ctx.state.clone(),
        project: ctx.project.clone(),
        route: ctx.route.clone(),
        mode: ctx.mode,
        key_kind: ctx.key.as_ref().filter(|k| k.role == roles::KEYS).map(|k| k.kind),
        actor,
        has_user: ctx.user.is_some(),
        privileged: roles::is_privileged(&ctx.roles),
        params: ctx.events.params,
        payload,
        created_users: ctx.events.created_users,
        deletes: ctx.events.deletes,
        metrics: ctx.metrics,
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
        let project_payload = self.project.message_payload();
        let trimmed = self.project.trimmed_payload();

        for delete in &self.deletes {
            self.publish(&self.state.config.queue_deletes, delete).await;
        }

        // Database listener: `users.[userId].create`.
        for (user_id, payload) in &self.created_users {
            let params = vec![("userId".to_owned(), user_id.clone())];
            let Ok(events) = event::generate_events("users.[userId].create", &params) else { continue };
            let payload = raw(payload);
            let message = FunctionMessage::new(&project_payload, &self.actor, &payload, &events);
            self.publish(&self.state.config.queue_functions, &message).await;
            if !self.project.webhooks.is_empty() {
                let message = WebhookMessage {
                    project: &trimmed,
                    user: &self.actor,
                    user_id: None,
                    payload: &payload,
                    context: [],
                    events: &events,
                    event_id: utopia_database::id::unique(),
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
        if let Err(e) = self.state.publisher.publish(&Queue::new(queue), payload).await {
            tracing::warn!(error = %e, queue, "queue publish failed");
        }
    }

    async fn realtime(&self, events: &[String], user_id: Option<&str>, payload: &RawValue) {
        let Some(first) = events.first() else { return };
        let Some(target) = event::realtime_target(first) else { return };
        let message = event::realtime_message(&self.project.id, &target, user_id, events, payload);
        if let Err(e) = self.state.publisher.broadcast("realtime", &message).await {
            tracing::warn!(error = %e, "realtime publish failed");
        }
    }

    async fn trigger(&self, events: &[String], project_payload: &Value, trimmed: &Value) {
        let payload = raw(&self.payload);
        let user_id = self.params.iter().find(|(k, _)| k == "userId").map(|(_, v)| v.as_str());
        if !self.project.is_console() {
            self.realtime(events, user_id, &payload).await;
        }
        let function_events = self.function_events().await;
        if events.iter().any(|e| function_events.contains(e)) {
            let message = FunctionMessage::new(project_payload, &self.actor, &payload, events);
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
                event_id: utopia_database::id::unique(),
            };
            self.publish(&self.state.config.queue_webhooks, &message).await;
        }
    }

    /// Union of `functions.events` for the project, cached in the same Redis
    /// hash PHP uses (`...:project:<id>:functions:events`).
    async fn function_events(&self) -> Vec<String> {
        if self.project.is_console() {
            return Vec::new();
        }
        let Ok(db) = self.state.databases.project(&self.project) else { return Vec::new() };
        let key = format!(
            "default-cache-{}:{}:{}:project:{}:functions:events",
            db.pool().host(),
            db.namespace(),
            db.tenant().map(|t| t.to_string()).unwrap_or_default(),
            self.project.id
        );
        const FIELD: &str = "rust:functions:events:v1";
        let lookup = self.state.cache.lookup(&key, FIELD, 3600).await.ok();
        if let Some(bytes) = lookup.as_ref().and_then(|l| l.value.as_ref())
            && let Ok(events) = serde_json::from_slice::<Vec<String>>(bytes)
        {
            return events;
        }
        let mut b = Builder::new();
        let tenant = db.tenant_condition(&mut b, "");
        let sql = format!(
            "SELECT DISTINCT jsonb_array_elements_text(\"events\") AS e FROM {} WHERE \"events\" IS NOT NULL{tenant}",
            db.table("functions")
        );
        let events: Vec<String> = match db.query(&sql, &b).await {
            Ok(rows) => rows.iter().filter_map(|r| r.try_get::<_, String>(0).ok()).collect(),
            Err(e) => {
                tracing::warn!(error = %e, "functions events lookup failed");
                return Vec::new();
            }
        };
        if let Some(lookup) = lookup
            && let Ok(bytes) = serde_json::to_vec(&events)
        {
            let _ = self.state.cache.save_with_lease(&key, FIELD, &bytes, &lookup.generation).await;
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
        let mut project = Map::new();
        project.insert("$id".into(), Value::String(self.project.id.clone()));
        project.insert("$sequence".into(), Value::String(self.project.sequence.clone()));
        let message = serde_json::json!({
            "project": project,
            "user": self.actor,
            "impersonatorUser": null,
            "payload": raw(&self.payload),
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
            country: String::new(),
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
        let message = event::usage_message(self.project.trimmed_payload(), &metrics);
        self.publish(&self.state.config.queue_usage, &message).await;
    }

    async fn onboarding(&self) {
        if !self.status.is_success() || self.project.is_console() {
            return;
        }
        let method = format!("{}.{}", self.route.namespace, self.route.sdk_method);
        if method != "users.create" {
            return;
        }
        let mut stages = match &self.project.onboarding {
            Value::Object(m) => m.clone(),
            _ => Map::new(),
        };
        if stages.get(&method).and_then(|r| r.get("status")).and_then(Value::as_str) == Some("completed") {
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
        let mut row = Map::new();
        row.insert("status".into(), Value::String("completed".into()));
        row.insert("at".into(), Value::String(datetime::format_db(&datetime::now())));
        row.insert("actorType".into(), Value::String(actor_type.into()));
        stages.insert(method, Value::Object(row));
        let encoded = crate::json::to_string(&Value::Object(stages));
        let db = self.state.databases.platform();
        let project = self.project.clone();
        let state = self.state.clone();
        let lock_key = format!("lock:platform:{}:onboarding", project.sequence);
        let _ = self
            .state
            .lock
            .try_with_key(&lock_key, async move {
                if db
                    .update("projects", &project.id, vec![("onboarding", Param::Text(encoded))], "\"_uid\"")
                    .await
                    .is_ok()
                {
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
