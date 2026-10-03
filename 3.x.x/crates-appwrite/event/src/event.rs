//! Event builder and event-name expansion. Rust port of `Appwrite\Event\Event`
//! (`src/Appwrite/Event/Event.php`), scoped to the pieces the Users API
//! foundation needs: building a queue payload and expanding a single event
//! pattern (e.g. `users.[userId].create`) into the concrete/wildcard event
//! names Realtime and webhooks match against.

use std::collections::BTreeMap;

use serde_json::{json, Map, Value};

/// Error building or expanding an [`Event`]. Rust port of the
/// `InvalidArgumentException` thrown by `Event::generateEvents()`.
#[derive(Debug, Clone, thiserror::Error, PartialEq, Eq)]
pub enum EventError {
    /// A placeholder in the event pattern (e.g. `[userId]`) has no matching
    /// param set via [`Event::set_param`].
    #[error("{0} is missing from the params.")]
    MissingParam(String),
}

/// Queue event builder. Rust port of `Appwrite\Event\Event`.
///
/// Only the subset used to assemble a queue message is ported here:
/// `setProject`/`setEvent`/`setParam`/`setPayload` plus `preparePayload()`
/// (exposed as [`Event::to_message`]). Publisher dispatch (`trigger()`) and
/// per-domain subclasses (`Database`, `Webhook`, `Func`, ...) belong in
/// `apps/server` once a `utopia-queue` publisher is wired up.
#[derive(Debug, Clone, Default)]
pub struct Event {
    event: String,
    params: BTreeMap<String, String>,
    payload: Value,
    context: Map<String, Value>,
    project: Option<Value>,
    user: Option<Value>,
    user_id: Option<String>,
    paused: bool,
}

impl Event {
    /// PHP `new Event($publisher)`, minus the publisher (queueing is not
    /// this crate's concern; see the module docs).
    #[must_use]
    pub fn new() -> Self {
        Self::default()
    }

    /// PHP `Event::setPaused()`.
    #[must_use]
    pub fn set_paused(mut self, paused: bool) -> Self {
        self.paused = paused;
        self
    }

    /// PHP `Event::paused` getter (no direct PHP equivalent method; exposed
    /// for symmetry with [`Self::set_paused`]).
    #[must_use]
    pub fn is_paused(&self) -> bool {
        self.paused
    }

    /// PHP `Event::setProject(Document $project)`.
    #[must_use]
    pub fn set_project(mut self, project: Value) -> Self {
        self.project = Some(project);
        self
    }

    /// PHP `Event::getProject()`.
    #[must_use]
    pub fn project(&self) -> Option<&Value> {
        self.project.as_ref()
    }

    /// PHP `Event::setUser(Document $user)`.
    #[must_use]
    pub fn set_user(mut self, user: Value) -> Self {
        self.user_id = user.get("$id").and_then(Value::as_str).map(str::to_string);
        self.user = Some(user);
        self
    }

    /// PHP `Event::getUser()`.
    #[must_use]
    pub fn user(&self) -> Option<&Value> {
        self.user.as_ref()
    }

    /// PHP `Event::getUserId()`.
    #[must_use]
    pub fn user_id(&self) -> Option<&str> {
        self.user_id.as_deref()
    }

    /// PHP `Event::setEvent(string $event)`. Stores the event pattern, e.g.
    /// `"users.[userId].create"`.
    #[must_use]
    pub fn set_event(mut self, event: impl Into<String>) -> Self {
        self.event = event.into();
        self
    }

    /// PHP `Event::getEvent()`.
    #[must_use]
    pub fn event(&self) -> &str {
        &self.event
    }

    /// PHP `Event::setParam(string $key, mixed $value)`. Values are stored
    /// as strings since they only ever substitute into a dot-separated event
    /// pattern.
    #[must_use]
    pub fn set_param(mut self, key: impl Into<String>, value: impl Into<String>) -> Self {
        self.params.insert(key.into(), value.into());
        self
    }

    /// PHP `Event::getParam(string $key)`.
    #[must_use]
    pub fn param(&self, key: &str) -> Option<&str> {
        self.params.get(key).map(String::as_str)
    }

    /// PHP `Event::getParams()`.
    #[must_use]
    pub fn params(&self) -> &BTreeMap<String, String> {
        &self.params
    }

    /// PHP `Event::setPayload(array $payload, array $sensitive = [])`.
    /// Sensitive-field trimming is left to the caller (Realtime/webhook
    /// filtering, not queue construction); only the raw payload is stored.
    #[must_use]
    pub fn set_payload(mut self, payload: Value) -> Self {
        self.payload = payload;
        self
    }

    /// PHP `Event::getPayload()`.
    #[must_use]
    pub fn payload(&self) -> &Value {
        &self.payload
    }

    /// PHP `Event::setContext(string $key, Document $context)`.
    #[must_use]
    pub fn set_context(mut self, key: impl Into<String>, context: Value) -> Self {
        self.context.insert(key.into(), context);
        self
    }

    /// PHP `Event::getContext(string $key)`.
    #[must_use]
    pub fn context(&self, key: &str) -> Option<&Value> {
        self.context.get(key)
    }

    /// PHP `Event::preparePayload()`: the queue message body before
    /// publisher-specific trimming (`Event::trimPayload()`) is merged in.
    /// Compatible with the PHP queue shape: `project`, `user`, `userId`,
    /// `payload`, `context`, `events`.
    pub fn to_message(&self) -> Result<Value, EventError> {
        let events = generate_events(&self.event, &self.params)?;
        Ok(json!({
            "project": self.project.clone().unwrap_or(Value::Null),
            "user": self.user.clone().unwrap_or(Value::Null),
            "userId": self.user_id.clone(),
            "payload": self.payload.clone(),
            "context": Value::Object(self.context.clone()),
            "events": events,
        }))
    }

    /// PHP `Event::reset()`.
    #[must_use]
    pub fn reset(mut self) -> Self {
        self.params.clear();
        self.event.clear();
        self.payload = Value::Null;
        self
    }
}

/// The parsed sections of an event pattern. Rust port of
/// `Event::parseEventPattern()`'s return array, scoped to patterns with at
/// most one sub-resource (`type.resource.subType.subResource.action`) --
/// the shape every Users API event uses (`users.[userId].sessions.[sessionId].create`,
/// `users.[userId].update.email`, ...). Deeper nesting
/// (`subSubResource`, used only by the legacy databases/collections/documents
/// hierarchy) is out of scope for this crate; see the README.
#[derive(Debug, Clone, PartialEq, Eq)]
struct ParsedPattern {
    type_: String,
    resource: Option<String>,
    sub_type: Option<String>,
    sub_resource: Option<String>,
    action: Option<String>,
    attribute: Option<String>,
}

/// PHP `Event::parseEventPattern(string $pattern)`, minus `subSubType` /
/// `subSubResource` (see [`ParsedPattern`]).
fn parse_event_pattern(pattern: &str) -> ParsedPattern {
    let parts: Vec<&str> = pattern.split('.').collect();
    let count = parts.len();

    let type_ = parts.first().copied().unwrap_or_default().to_string();
    let resource = parts.get(1).map(|s| s.to_string());
    let has_sub_resource = count > 3 && parts.get(3).is_some_and(|p| p.starts_with('['));

    let (sub_type, sub_resource) = if has_sub_resource {
        (
            parts.get(2).map(|s| s.to_string()),
            parts.get(3).map(|s| s.to_string()),
        )
    } else {
        (None, None)
    };

    let attribute = if has_sub_resource {
        if count == 6 {
            parts.get(5).map(|s| s.to_string())
        } else {
            None
        }
    } else if count == 4 {
        parts.get(3).map(|s| s.to_string())
    } else {
        None
    };

    let action = if !has_sub_resource && count > 2 {
        parts.get(2).map(|s| s.to_string())
    } else if has_sub_resource && count > 4 {
        parts.get(4).map(|s| s.to_string())
    } else {
        None
    };

    ParsedPattern {
        type_,
        resource,
        sub_type,
        sub_resource,
        action,
        attribute,
    }
}

/// PHP `Event::generateEvents(string $pattern, array $params)`, scoped to
/// patterns with at most one sub-resource level (see [`ParsedPattern`]) and
/// without the databases/collections-vs-tables mirroring PHP layers on top
/// (`Event::mirrorCollectionEvents()`, `Event::getDatabaseTypeEvents()`) --
/// neither applies to the Users API domain this crate is scaffolding for.
///
/// Expands a dot-separated pattern with `[placeholder]` segments into every
/// concrete event name (placeholders replaced with the matching param) plus
/// every wildcard variant (placeholders replaced with `*`), matching PHP's
/// behavior for Realtime/webhook event matching.
pub fn generate_events(
    pattern: &str,
    params: &BTreeMap<String, String>,
) -> Result<Vec<String>, EventError> {
    if pattern.is_empty() {
        return Ok(Vec::new());
    }

    let parsed = parse_event_pattern(pattern);

    for placeholder in [&parsed.resource, &parsed.sub_resource]
        .into_iter()
        .flatten()
    {
        let key = placeholder.trim_matches(|c| c == '[' || c == ']');
        if !params.contains_key(key) {
            return Err(EventError::MissingParam(key.to_string()));
        }
    }

    let mut base_patterns: Vec<String> = Vec::new();
    if let Some(action) = &parsed.action {
        if let Some(sub_resource) = &parsed.sub_resource {
            let sub_type = parsed.sub_type.as_deref().unwrap_or_default();
            let resource = parsed.resource.as_deref().unwrap_or_default();
            if let Some(attribute) = &parsed.attribute {
                base_patterns.push(join(&[
                    &parsed.type_,
                    resource,
                    sub_type,
                    sub_resource,
                    action,
                    attribute,
                ]));
            }
            base_patterns.push(join(&[
                &parsed.type_,
                resource,
                sub_type,
                sub_resource,
                action,
            ]));
            base_patterns.push(join(&[&parsed.type_, resource, sub_type, sub_resource]));
        } else {
            let resource = parsed.resource.as_deref().unwrap_or_default();
            base_patterns.push(join(&[&parsed.type_, resource, action]));
        }
        if let Some(attribute) = &parsed.attribute {
            let resource = parsed.resource.as_deref().unwrap_or_default();
            base_patterns.push(join(&[&parsed.type_, resource, action, attribute]));
        }
    }
    if let Some(sub_resource) = &parsed.sub_resource {
        let sub_type = parsed.sub_type.as_deref().unwrap_or_default();
        let resource = parsed.resource.as_deref().unwrap_or_default();
        base_patterns.push(join(&[&parsed.type_, resource, sub_type, sub_resource]));
    }
    base_patterns.push(join(&[
        &parsed.type_,
        parsed.resource.as_deref().unwrap_or_default(),
    ]));

    dedup_in_place(&mut base_patterns);

    let param_keys: Vec<&String> = params.keys().collect();
    let mut events: Vec<String> = Vec::new();

    for event_pattern in &base_patterns {
        events.push(substitute(event_pattern, params, &[]));
        events.push(substitute_wildcard(event_pattern, &param_keys));

        for current in &param_keys {
            let wildcard_one = &[(**current).as_str()][..];
            events.push(substitute(event_pattern, params, wildcard_one));
        }
    }

    let mut events: Vec<String> = events
        .into_iter()
        .map(|event| event.replace(['[', ']'], ""))
        .collect();
    dedup_in_place(&mut events);

    Ok(events)
}

fn join(parts: &[&str]) -> String {
    parts
        .iter()
        .filter(|p| !p.is_empty())
        .copied()
        .collect::<Vec<_>>()
        .join(".")
}

fn dedup_in_place(values: &mut Vec<String>) {
    let mut seen = std::collections::HashSet::new();
    values.retain(|value| seen.insert(value.clone()));
}

/// Replace every `[key]` placeholder with its param value, except keys in
/// `wildcard_keys`, which are replaced with `*` instead. Mirrors the
/// str_replace passes in PHP's `Event::generateEvents()`.
fn substitute(pattern: &str, params: &BTreeMap<String, String>, wildcard_keys: &[&str]) -> String {
    let mut result = pattern.to_string();
    for (key, value) in params {
        let placeholder = format!("[{key}]");
        let replacement = if wildcard_keys.contains(&key.as_str()) {
            "*"
        } else {
            value.as_str()
        };
        result = result.replace(&placeholder, replacement);
    }
    result
}

fn substitute_wildcard(pattern: &str, param_keys: &[&String]) -> String {
    let mut result = pattern.to_string();
    for key in param_keys {
        let placeholder = format!("[{key}]");
        result = result.replace(&placeholder, "*");
    }
    result
}
