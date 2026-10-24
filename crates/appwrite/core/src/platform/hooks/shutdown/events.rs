//! Events: realtime, functions and webhooks (PHP:
//! `app/controllers/shared/api.php`, the `api` group's shutdown hook that
//! triggers `$queueForEvents`; hooks.json `events`).

use bytes::Bytes;
use serde_json::Value;
use serde_json::value::RawValue;
use utopia_database::value::to_text;
use utopia_database::{PermissionType, Query};

use super::{Job, raw};
use crate::database::Databases;
use crate::database::documents::Attributes;
use crate::event::{self, FunctionMessage, WebhookMessage};

impl Job {
    pub(super) async fn realtime(&self, events: &[String], user_id: Option<&str>, payload: &RawValue) {
        let Some(first) = events.first() else { return };
        let member = serde_json::from_str::<Value>(payload.get())
            .ok()
            .and_then(|p| p.get("userId").and_then(Value::as_str).map(str::to_owned));
        let Some(target) = event::realtime_target(first, member.as_deref()) else { return };
        let message = event::realtime_message(&self.project.id, &target, user_id, events, payload);
        let mut pubsub = self.state.pubsub.clone();
        let sent: Result<i64, _> =
            utopia_cache::redis::cmd("PUBLISH").arg("realtime").arg(&message[..]).query_async(&mut pubsub).await;
        if let Err(e) = sent {
            tracing::warn!(error = %e, "realtime publish failed");
        }
    }

    pub(super) async fn trigger(&self, events: &[String], project_payload: &Value, trimmed: &Value) {
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

    /// The project's functions events cache key (none for the console).
    pub(super) async fn function_events_key(&self) -> Option<String> {
        if self.project.is_console() {
            return None;
        }
        let db = self.state.databases.project(&self.project, Databases::unchecked()).ok()?;
        Some(format!(
            "{}-cache-{}:{}:{}:project:{}:functions:events",
            db.cache_name(),
            db.hostname().await.ok().flatten().unwrap_or_default(),
            db.namespace(),
            to_text(&db.tenant()),
            self.project.id
        ))
    }

    /// Union of `functions.events` for the project, cached in the same Redis
    /// hash PHP uses (`...:project:<id>:functions:events`), so PHP's purges
    /// drop it, under a field only Rust reads: a native entry.
    async fn function_events(&self) -> Vec<String> {
        let Some(key) = self.function_events_key().await else { return Vec::new() };
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
                        Query::order_asc("$sequence", None),
                    ],
                    PermissionType::Read,
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
}
