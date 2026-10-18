//! Shutdown hooks: events (realtime, functions, webhooks), deletes, audits,
//! usage and onboarding. They run after the response is produced, in a
//! background task, exactly like PHP runs them after `send()` (PHP: the
//! `Http::shutdown()` hooks of `app/controllers/general.php` and
//! `app/controllers/shared/api.php`).
//!
//! One module per `shutdown` entry of `crates/appwrite/hooks.json`, each
//! adding its step to [`Job`]. PHP's order: `abuse-reset`, `cache-write`,
//! then (`session-limit` alongside) the function events cache purge, the
//! deletes, the database listeners' `users.[userId].create` events, the
//! queued messages, `events`, `audits`, `usage` (with `usage-status`) and
//! `onboarding`.

mod abuse_reset;
mod audits;
pub(in crate::platform) mod cache_write;
mod events;
mod onboarding;
mod session_limit;
mod usage;

use std::sync::Arc;

use bytes::Bytes;
use http::StatusCode;
use serde_json::Value;
use serde_json::value::RawValue;
use utopia_queue::{Publisher as _, Queue};

use crate::auth::{KeyType, roles};
use crate::database::Project;
use crate::database::documents::User;
use crate::event::{self, FunctionMessage, WebhookMessage};
use crate::platform::context::{Context, Mode};
use crate::platform::hooks::init::usage_context::RequestInfo;
use crate::platform::hooks::resources::database_listeners::UsageMetric;
use crate::platform::{Route, State};

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
    /// The event an action set (`setEvent()`), over the route's.
    event: Option<String>,
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
    /// The response cache entry to write (`cache` routes).
    cache: Option<cache_write::Entry>,
    /// A function changed: its events cache is purged.
    functions_changed: bool,
    deletes: Vec<Value>,
    queued: Vec<(String, Value)>,
    metrics: Vec<UsageMetric>,
    request: RequestInfo,
    status: StatusCode,
    response_size: usize,
}

pub(in crate::platform) fn schedule(
    ctx: Context,
    status: StatusCode,
    content_type: String,
    body: Bytes,
    response_size: usize,
) {
    let info = RequestInfo::of(&ctx);
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
    // The `cache` routes' entry: the request's key and the payload.
    let cache = if ctx.route.labels.cache {
        let key = cache_write::identifier(
            ctx.request.uri(),
            ctx.request.params(),
            ctx.route.labels.cache_params,
            ctx.request.header_or_empty("x-appwrite-project"),
        );
        // The route's params: its path params over the request's.
        let mut params = ctx.request.params().clone();
        for (name, value) in &ctx.path_params {
            params.insert(name.clone(), value.clone());
        }
        cache_write::Entry::of(key, &ctx.route.labels, &params, status.as_u16(), &content_type, &body)
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
        event: ctx.events.event,
        payload,
        sensitive: ctx.events.sensitive,
        created_users,
        functions_changed: listened.functions,
        cache,
        session_user,
        abuse_params: ctx.abuse_params,
        deletes: ctx.events.deletes,
        queued: ctx.events.queued,
        metrics: listened.metrics,
        request: info,
        status,
        response_size,
    };
    utopia_async::spawn(job.run());
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

        abuse_reset::abuse_reset(&self.state, self.route.labels, self.status.as_u16(), &self.abuse_params).await;
        self.cache_write().await;
        // The session limit only deletes rows and purges their cache, which
        // the work below neither reads nor orders against: it runs alongside.
        utopia_async::join!(self.session_limit(), self.dispatch(&project_payload, &trimmed));
    }

    /// The messages, purges and records of the request, in PHP's order.
    async fn dispatch(&self, project_payload: &Value, trimmed: &Value) {
        let (project_payload, trimmed) = (project_payload.clone(), trimmed.clone());
        // `purge-function-events-cache`.
        if self.functions_changed
            && let Some(key) = self.function_events_key().await
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

        for (queue, message) in &self.queued {
            self.publish(queue, message).await;
        }

        if let Some(pattern) = self.event.as_deref().or(self.route.event) {
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
}
