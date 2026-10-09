//! Events and queue messages consumed by the PHP workers (`Appwrite\Event`).
//!
//! Payloads are kept as pre-encoded JSON (`RawValue`) so the response body
//! is reused verbatim instead of being re-serialised for every queue.

use serde::Serialize;
use serde_json::value::RawValue;
use serde_json::{Map, Value, json};
use utopia_database::{datetime, id};

/// `Event::generateEvents($pattern, $params)`.
pub fn generate_events(pattern: &str, params: &[(String, String)]) -> Result<Vec<String>, String> {
    let parts: Vec<&str> = pattern.split('.').collect();
    let count = parts.len();
    let kind = parts[0];
    let resource = parts.get(1).copied();
    let has_sub = count > 3 && parts[3].starts_with('[');
    let has_sub_sub = count > 5 && parts[5].starts_with('[') && has_sub;
    let (mut sub_type, mut sub_resource, mut sub_sub_type, mut sub_sub_resource, mut attribute) =
        (None, None, None, None, None);
    if has_sub {
        sub_type = Some(parts[2]);
        sub_resource = Some(parts[3]);
    }
    if has_sub_sub {
        sub_sub_type = Some(parts[4]);
        sub_sub_resource = Some(parts[5]);
        if count == 8 {
            attribute = Some(parts[7]);
        }
    }
    if has_sub && !has_sub_sub && count == 6 {
        attribute = Some(parts[5]);
    }
    if !has_sub && count == 4 {
        attribute = Some(parts[3]);
    }
    let action = if !has_sub && count > 2 {
        Some(parts[2])
    } else if has_sub_sub {
        parts.get(6).copied()
    } else if has_sub && count > 4 {
        Some(parts[4])
    } else {
        None
    };

    let keys: Vec<&str> = params.iter().map(|(k, _)| k.as_str()).collect();
    for r in [resource, sub_resource, sub_sub_resource].into_iter().flatten() {
        let name = r.trim_matches(|c| c == '[' || c == ']');
        if !keys.contains(&name) {
            return Err(format!("{r} is missing from the params."));
        }
    }

    let resource = resource.unwrap_or("");
    let join = |items: &[&str]| items.join(".");
    let mut patterns: Vec<String> = Vec::new();
    if let Some(action) = action {
        if let (true, Some(st), Some(sr), Some(sst), Some(ssr)) =
            (has_sub_sub, sub_type, sub_resource, sub_sub_type, sub_sub_resource)
        {
            if let Some(a) = attribute {
                patterns.push(join(&[kind, resource, st, sr, sst, ssr, action, a]));
            }
            patterns.push(join(&[kind, resource, st, sr, sst, ssr, action]));
            patterns.push(join(&[kind, resource, st, sr, sst, ssr]));
        } else if let (Some(st), Some(sr)) = (sub_type, sub_resource) {
            if let Some(a) = attribute {
                patterns.push(join(&[kind, resource, st, sr, action, a]));
            }
            patterns.push(join(&[kind, resource, st, sr, action]));
            patterns.push(join(&[kind, resource, st, sr]));
        } else {
            if let Some(a) = attribute {
                patterns.push(join(&[kind, resource, action, a]));
            }
            patterns.push(join(&[kind, resource, action]));
        }
    }
    if let (Some(st), Some(sr), Some(sst), Some(ssr)) = (sub_type, sub_resource, sub_sub_type, sub_sub_resource) {
        patterns.push(join(&[kind, resource, st, sr, sst, ssr]));
    }
    if let (Some(st), Some(sr)) = (sub_type, sub_resource) {
        patterns.push(join(&[kind, resource, st, sr]));
    }
    patterns.push(join(&[kind, resource]));
    let patterns = unique(patterns);

    let replace_all = |subject: &str, with_values: bool| -> String {
        let mut out = subject.to_owned();
        for (k, v) in params {
            out = out.replace(k.as_str(), if with_values { v.as_str() } else { "*" });
        }
        out
    };

    let mut events: Vec<String> = Vec::new();
    for pattern in &patterns {
        events.push(replace_all(pattern, true));
        events.push(replace_all(pattern, false));
        let mut wildcards: Vec<String> = Vec::new();
        let mut add = |w: String| {
            if !wildcards.contains(&w) {
                wildcards.push(w);
            }
        };
        for key in &keys {
            for current in &keys {
                if has_sub_sub {
                    for sub_current in &keys {
                        if sub_current == current || sub_current == key {
                            continue;
                        }
                        let wildcard = pattern.replace(sub_current, "*");
                        let narrowed = wildcard.replace(current, "*");
                        add(wildcard);
                        add(narrowed);
                        add(pattern.replace(current, "*"));
                    }
                } else {
                    if current == key {
                        continue;
                    }
                    add(pattern.replace(current, "*"));
                }
            }
        }
        for w in wildcards {
            events.push(replace_all(&w, true));
        }
    }
    let events: Vec<String> = events.into_iter().map(|e| e.replace(['[', ']'], "")).collect();
    Ok(unique(events))
}

fn unique(items: Vec<String>) -> Vec<String> {
    let mut out: Vec<String> = Vec::with_capacity(items.len());
    for i in items {
        if !out.contains(&i) {
            out.push(i);
        }
    }
    out
}

const USER_EVENTS_WITHOUT_ACCESS_CHANGE: [&str; 16] = [
    "create",
    "update.name",
    "update.prefs",
    "update.avatar",
    "update.mfa",
    "update.mfa.recovery-codes",
    "create.mfa.recovery-codes",
    "delete.mfa",
    "recovery.*.create",
    "verification.*.create",
    "targets.*.create",
    "targets.*.update",
    "targets.*.delete",
    "tokens.*.create",
    "challenges.*.create",
    "identities.*.delete",
];

/// Realtime target for an event (`Messaging\Adapter\Realtime::fromPayload`, users subset).
pub struct RealtimeTarget {
    pub channels: Vec<String>,
    pub roles: Vec<String>,
    pub permissions_changed: bool,
}

pub fn realtime_target(event: &str) -> Option<RealtimeTarget> {
    let parts: Vec<&str> = event.split('.').collect();
    match parts.first().copied() {
        Some("users") if parts.len() > 1 => {
            let id = parts[1];
            let suffix = &parts[2..];
            let mut permissions_changed = true;
            for pattern in USER_EVENTS_WITHOUT_ACCESS_CHANGE {
                let segments: Vec<&str> = pattern.split('.').collect();
                if segments.len() != suffix.len() {
                    continue;
                }
                if segments.iter().zip(suffix).all(|(s, p)| *s == "*" || s == p) {
                    permissions_changed = false;
                    break;
                }
            }
            Some(RealtimeTarget {
                channels: vec!["account".to_owned(), format!("account.{id}")],
                roles: vec![format!("user:{id}")],
                permissions_changed,
            })
        }
        _ => None,
    }
}

/// Functions queue message (`Message\Func::toArray`).
#[derive(Serialize)]
pub struct FunctionMessage<'a> {
    pub project: &'a Value,
    pub user: &'a Value,
    #[serde(rename = "userId")]
    pub user_id: Option<&'a str>,
    pub function: Option<()>,
    #[serde(rename = "functionId")]
    pub function_id: Option<()>,
    pub execution: Option<()>,
    #[serde(rename = "type")]
    pub kind: &'a str,
    pub jwt: &'a str,
    pub payload: &'a RawValue,
    pub events: &'a [String],
    pub body: &'a str,
    pub path: &'a str,
    pub headers: [(); 0],
    pub method: &'a str,
    pub platform: [(); 0],
    #[serde(rename = "eventId")]
    pub event_id: String,
}

impl<'a> FunctionMessage<'a> {
    pub fn new(project: &'a Value, user: &'a Value, payload: &'a RawValue, events: &'a [String]) -> Self {
        Self {
            project,
            user,
            user_id: None,
            function: None,
            function_id: None,
            execution: None,
            kind: "",
            jwt: "",
            payload,
            events,
            body: "",
            path: "",
            headers: [],
            method: "",
            platform: [],
            event_id: id::unique(),
        }
    }
}

/// Webhooks queue message.
#[derive(Serialize)]
pub struct WebhookMessage<'a> {
    pub project: &'a Value,
    pub user: &'a Value,
    #[serde(rename = "userId")]
    pub user_id: Option<&'a str>,
    pub payload: &'a RawValue,
    pub context: [(); 0],
    pub events: &'a [String],
    #[serde(rename = "eventId")]
    pub event_id: String,
}

/// Realtime publish message.
pub fn realtime_message(
    project_id: &str,
    target: &RealtimeTarget,
    user_id: Option<&str>,
    events: &[String],
    payload: &RawValue,
) -> Vec<u8> {
    #[derive(Serialize)]
    struct Data<'a> {
        events: &'a [String],
        channels: &'a [String],
        timestamp: String,
        payload: &'a RawValue,
    }
    #[derive(Serialize)]
    struct Message<'a> {
        project: &'a str,
        roles: &'a [String],
        #[serde(rename = "permissionsChanged")]
        permissions_changed: bool,
        #[serde(rename = "userId")]
        user_id: Option<&'a str>,
        data: Data<'a>,
    }
    let message = Message {
        project: project_id,
        roles: &target.roles,
        permissions_changed: target.permissions_changed,
        user_id,
        data: Data { events, channels: &target.channels, timestamp: datetime::format_tz(&datetime::now()), payload },
    };
    crate::json::to_vec(&message)
}

/// Deletes queue message (`Message\Delete::toArray`).
pub fn delete_message(project: &Value, kind: &str, document: Value) -> Value {
    json!({
        "project": project,
        "type": kind,
        "document": document,
        "resource": null,
        "resourceType": null,
        "datetime": null,
    })
}

/// One usage metric with its request context (23 fields, PHP order).
#[derive(Debug, Clone, Serialize)]
pub struct Metric {
    pub key: String,
    pub value: i64,
    pub path: String,
    pub method: String,
    pub status: u16,
    pub service: String,
    #[serde(rename = "resourceType")]
    pub resource_type: String,
    #[serde(rename = "resourceId")]
    pub resource_id: String,
    #[serde(rename = "resourceInternalId")]
    pub resource_internal_id: String,
    #[serde(rename = "resourcePath")]
    pub resource_path: String,
    #[serde(rename = "teamId")]
    pub team_id: String,
    #[serde(rename = "teamInternalId")]
    pub team_internal_id: String,
    pub country: String,
    pub region: String,
    pub hostname: String,
    #[serde(rename = "userAgent")]
    pub user_agent: String,
    pub ip: String,
    pub sdk: String,
    #[serde(rename = "sdkVersion")]
    pub sdk_version: String,
    pub protocol: String,
    pub accept: String,
    #[serde(rename = "acceptLanguage")]
    pub accept_language: String,
    #[serde(rename = "queryKeys")]
    pub query_keys: String,
}

/// Usage queue message.
pub fn usage_message(project: Value, metrics: &[Metric]) -> Value {
    json!({ "project": project, "metrics": metrics, "reduce": [] })
}

/// Empty actor document as PHP serialises an empty `User`.
pub fn empty_user() -> Value {
    Value::Array(Vec::new())
}

/// Minimal actor document for queue messages.
pub fn actor(id: &str, email: Option<&str>, name: Option<&str>) -> Value {
    let mut m = Map::new();
    m.insert("$id".into(), Value::String(id.to_owned()));
    if let Some(e) = email {
        m.insert("email".into(), Value::String(e.to_owned()));
    }
    if let Some(n) = name {
        m.insert("name".into(), Value::String(n.to_owned()));
    }
    Value::Object(m)
}

#[cfg(test)]
mod tests {
    use super::*;

    fn p(items: &[(&str, &str)]) -> Vec<(String, String)> {
        items.iter().map(|(k, v)| ((*k).to_owned(), (*v).to_owned())).collect()
    }

    #[test]
    fn user_events() {
        assert_eq!(
            generate_events("users.[userId].create", &p(&[("userId", "u")])).unwrap(),
            vec!["users.u.create", "users.*.create", "users.u", "users.*"]
        );
        assert_eq!(
            generate_events("users.[userId].update.email", &p(&[("userId", "u")])).unwrap(),
            vec![
                "users.u.update.email",
                "users.*.update.email",
                "users.u.update",
                "users.*.update",
                "users.u",
                "users.*"
            ]
        );
        assert_eq!(
            generate_events("users.[userId].create.mfa.recovery-codes", &p(&[("userId", "u")])).unwrap(),
            vec!["users.u.create", "users.*.create", "users.u", "users.*"]
        );
        assert_eq!(
            generate_events("users.[userId].sessions.[sessionId].create", &p(&[("userId", "u"), ("sessionId", "s")]))
                .unwrap(),
            vec![
                "users.u.sessions.s.create",
                "users.*.sessions.*.create",
                "users.u.sessions.*.create",
                "users.*.sessions.s.create",
                "users.u.sessions.s",
                "users.*.sessions.*",
                "users.u.sessions.*",
                "users.*.sessions.s",
                "users.u",
                "users.*"
            ]
        );
        assert_eq!(
            generate_events("users.[userId].sessions.delete", &p(&[("userId", "u")])).unwrap(),
            vec![
                "users.u.sessions.delete",
                "users.*.sessions.delete",
                "users.u.sessions",
                "users.*.sessions",
                "users.u",
                "users.*"
            ]
        );
        assert!(generate_events("users.[userId].create", &[]).is_err());
    }

    #[test]
    fn realtime() {
        let t = realtime_target("users.u1.update.name").unwrap();
        assert!(!t.permissions_changed);
        assert_eq!(t.channels, vec!["account", "account.u1"]);
        assert!(realtime_target("users.u1.update.email").unwrap().permissions_changed);
        assert!(!realtime_target("users.u1.targets.t1.create").unwrap().permissions_changed);
    }
}
