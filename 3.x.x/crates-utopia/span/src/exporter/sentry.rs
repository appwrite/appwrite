use std::time::{SystemTime, UNIX_EPOCH};

use serde_json::{json, Map, Value};
use thiserror::Error;

use crate::attr::AttrValue;
use crate::error::SpanError;
use crate::exporter::{Exporter, SentryField, SentryLevel};
use crate::level::Level;
use crate::php_url::parse_url;
use crate::span::Span;

const EXPORT_LEVELS: [Level; 3] = [Level::Warn, Level::Error, Level::Fatal];
const MAX_CHAIN_DEPTH: usize = 10;
const HANDLED_HTTP_KEYS: [&str; 4] = [
    "http.url",
    "http.method",
    "http.query",
    "http.response.status_code",
];

type Sampler = Box<dyn Fn(&Span) -> bool + Send + Sync>;
type Classifier = Box<dyn Fn(&str) -> SentryField + Send + Sync>;

/// PHP `InvalidArgumentException` from the Sentry exporter constructor.
#[derive(Debug, Error, Clone, PartialEq, Eq)]
pub enum SentryError {
    #[error("Sentry DSN is required")]
    DsnRequired,
    #[error("Invalid Sentry DSN")]
    InvalidDsn,
    #[error("Invalid Sentry DSN: must include public key, host, and project ID")]
    IncompleteDsn,
}

/// Exports warning-or-higher spans to Sentry Issues (PHP `Exporter\Sentry`).
pub struct Sentry {
    dsn: String,
    endpoint: String,
    public_key: String,
    environment: Option<String>,
    release: Option<String>,
    server_name: Option<String>,
    classifier: Classifier,
    sampler: Sampler,
}

impl std::fmt::Debug for Sentry {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Sentry")
            .field("endpoint", &self.endpoint)
            .field("environment", &self.environment)
            .finish_non_exhaustive()
    }
}

impl Sentry {
    pub fn new(dsn: impl Into<String>) -> Result<Self, SentryError> {
        Self::new_with(None, dsn, None, None, None, None)
    }

    pub fn new_with(
        sampler: Option<Sampler>,
        dsn: impl Into<String>,
        environment: Option<String>,
        release: Option<String>,
        server_name: Option<String>,
        classifier: Option<Classifier>,
    ) -> Result<Self, SentryError> {
        let dsn = dsn.into();
        let user_sampler = sampler;
        let composed: Sampler = Box::new(move |span: &Span| {
            let level = span
                .get("level")
                .and_then(|v| v.as_str().map(str::to_string))
                .and_then(|s| Level::try_from_attr(&s));
            let Some(level) = level else {
                return false;
            };
            if !EXPORT_LEVELS.contains(&level) {
                return false;
            }
            match user_sampler.as_ref() {
                Some(sampler) => sampler(span),
                None => true,
            }
        });
        if dsn.is_empty() {
            return Err(SentryError::DsnRequired);
        }
        let parsed = parse_url(&dsn).ok_or(SentryError::InvalidDsn)?;
        let public_key = parsed.user.unwrap_or_default();
        let host = parsed.host.unwrap_or_default();
        let project_id = parsed
            .path
            .unwrap_or_default()
            .trim_start_matches('/')
            .to_string();
        if public_key.is_empty() || host.is_empty() || project_id.is_empty() {
            return Err(SentryError::IncompleteDsn);
        }
        let scheme = parsed.scheme.unwrap_or_else(|| "https".to_string());
        let port = parsed.port.map(|p| format!(":{p}")).unwrap_or_default();
        let endpoint = format!("{scheme}://{host}{port}/api/{project_id}/envelope/");
        Ok(Self {
            dsn,
            endpoint,
            public_key,
            environment,
            release,
            server_name,
            classifier: classifier.unwrap_or_else(|| Box::new(|_| SentryField::Context)),
            sampler: composed,
        })
    }

    pub fn build_envelope(&self, span: &Span) -> Option<String> {
        let error = span.get_error()?;
        let attributes = span.get_attributes();
        let attr = |key: &str| {
            attributes
                .iter()
                .find(|(k, _)| k == key)
                .map(|(_, v)| v.clone())
        };

        let trace_id = attr("span.trace_id")
            .and_then(|v| v.as_str().map(str::to_string))
            .unwrap_or_default();
        let span_id = attr("span.id")
            .and_then(|v| v.as_str().map(str::to_string))
            .unwrap_or_default();
        let parent_id = attr("span.parent_id").and_then(|v| v.as_str().map(str::to_string));
        let started_at = attr("span.started_at")
            .and_then(|v| v.as_f64())
            .unwrap_or_else(unix_seconds);
        let finished_at = attr("span.finished_at")
            .and_then(|v| v.as_f64())
            .unwrap_or_else(unix_seconds);
        let action = span.get_action();

        let mut trace_context = json!({
            "trace_id": trace_id,
            "span_id": span_id,
        });
        if let Some(parent) = parent_id {
            trace_context["parent_span_id"] = Value::String(parent);
        }

        let header = json!({
            "event_id": trace_id.replace('-', ""),
            "sent_at": date_c(),
            "dsn": self.dsn,
        });
        let item_header = json!({
            "type": "event",
            "content_type": "application/json",
        });

        let mut contexts = Map::new();
        contexts.insert("trace".into(), trace_context);
        contexts.insert(
            "runtime".into(),
            json!({
                "name": "rust",
                "version": option_env!("RUSTC_SEMVER").unwrap_or("unknown"),
            }),
        );

        let request = build_request(&attributes);
        let response = build_response(&attributes);
        if !response.is_empty() {
            contexts.insert("response".into(), Value::Object(response));
        }

        let (tags, custom, extra) = self.classify(&attributes);
        if !custom.is_empty() {
            contexts.insert("custom".into(), Value::Object(custom));
        }

        let level = attr("level")
            .and_then(|v| v.as_str().map(str::to_string))
            .and_then(|s| Level::try_from_attr(&s))
            .unwrap_or(Level::Error);
        let handled_override = match attr("span.handled") {
            Some(AttrValue::Bool(value)) => Some(value),
            _ => None,
        };

        let mut payload = json!({
            "level": SentryLevel::from_span(level).as_str(),
            "platform": "rust",
            "sdk": {
                "name": "utopia-span",
                "version": env!("CARGO_PKG_VERSION"),
            },
            "start_timestamp": started_at,
            "timestamp": finished_at,
            "transaction": action,
            "message": error.message,
            "contexts": contexts,
            "exception": {
                "values": build_exception_values(&error, level, handled_override),
            },
        });

        if !tags.is_empty() {
            payload["tags"] = Value::Object(tags);
        }
        if !extra.is_empty() {
            payload["extra"] = Value::Object(extra);
        }
        if !request.is_empty() {
            payload["request"] = Value::Object(request);
        }
        if let Some(environment) = &self.environment {
            payload["environment"] = Value::String(environment.clone());
        }
        if let Some(release) = &self.release {
            payload["release"] = Value::String(release.clone());
        }
        if let Some(server_name) = &self.server_name {
            payload["server_name"] = Value::String(server_name.clone());
        }

        let header = serde_json::to_string(&header).ok()?;
        let item_header = serde_json::to_string(&item_header).ok()?;
        let payload = serde_json::to_string(&payload).ok()?;
        Some(format!("{header}\n{item_header}\n{payload}"))
    }

    fn classify(
        &self,
        attributes: &[(String, AttrValue)],
    ) -> (Map<String, Value>, Map<String, Value>, Map<String, Value>) {
        let mut tags = Map::new();
        let mut contexts = Map::new();
        let mut extra = Map::new();
        for (key, value) in attributes {
            if key.starts_with("span.")
                || key == "level"
                || HANDLED_HTTP_KEYS.contains(&key.as_str())
            {
                continue;
            }
            match (self.classifier)(key) {
                SentryField::Tag => {
                    let rendered = value.display();
                    let truncated: String = rendered.chars().take(200).collect();
                    tags.insert(key.clone(), Value::String(truncated));
                }
                SentryField::Context => {
                    contexts.insert(key.clone(), value.to_json());
                }
                SentryField::Extra => {
                    extra.insert(key.clone(), value.to_json());
                }
            }
        }
        (tags, contexts, extra)
    }
}

impl Exporter for Sentry {
    fn sample(&self, span: &Span) -> bool {
        (self.sampler)(span)
    }

    fn export(&self, span: &Span) {
        let Some(envelope) = self.build_envelope(span) else {
            return;
        };
        let client = match reqwest::blocking::Client::builder()
            .timeout(std::time::Duration::from_millis(1000))
            .connect_timeout(std::time::Duration::from_millis(500))
            .build()
        {
            Ok(client) => client,
            Err(err) => {
                eprintln!("Sentry exporter: Failed to initialize curl: {err}");
                return;
            }
        };
        let auth = format!("Sentry sentry_version=7, sentry_key={}", self.public_key);
        match client
            .post(&self.endpoint)
            .header("Content-Type", "application/x-sentry-envelope")
            .header("X-Sentry-Auth", auth)
            .body(envelope)
            .send()
        {
            Ok(response) => {
                let status = response.status().as_u16();
                if status >= 400 {
                    let body = response.text().unwrap_or_default();
                    eprintln!("Sentry exporter: HTTP {status} - {body}");
                }
            }
            Err(err) => eprintln!("Sentry exporter: {err}"),
        }
    }
}

fn build_exception_values(
    error: &SpanError,
    level: Level,
    handled_override: Option<bool>,
) -> Vec<Value> {
    let mut chain = Vec::new();
    let mut current = Some(error);
    while let Some(err) = current {
        if chain.len() >= MAX_CHAIN_DEPTH {
            break;
        }
        chain.push(err);
        current = err.previous.as_deref();
    }
    let handled = handled_override.unwrap_or(level != Level::Fatal);
    let chained = chain.len() > 1;
    let mut values = Vec::new();
    for (id, exception) in chain.iter().enumerate() {
        let mut mechanism = json!({
            "type": "generic",
            "handled": handled,
        });
        if chained {
            mechanism["exception_id"] = json!(id);
            if id > 0 {
                mechanism["parent_id"] = json!(id - 1);
                mechanism["source"] = json!("__previous__");
            }
        }
        let mut value = json!({
            "type": exception.type_name,
            "value": exception.message,
            "stacktrace": { "frames": build_frames(exception) },
            "mechanism": mechanism,
        });
        if let Some(module) = exception.module() {
            value["module"] = Value::String(module.to_string());
        }
        values.push(value);
    }
    values.reverse();
    values
}

fn build_frames(error: &SpanError) -> Vec<Value> {
    let mut frames = Vec::new();
    for frame in error.frames.iter().rev() {
        let Some(file) = &frame.file else {
            continue;
        };
        if file == &error.file && frame.line == Some(error.line) {
            continue;
        }
        let mut obj = json!({
            "filename": file,
            "lineno": frame.line.unwrap_or(0),
            "in_app": in_app(file),
        });
        if !frame.function.is_empty() {
            obj["function"] = Value::String(frame.function.clone());
        }
        frames.push(obj);
    }
    let mut throw_site = json!({
        "filename": error.file,
        "lineno": error.line,
        "in_app": in_app(&error.file),
    });
    if let Some(frame) = error.frames.first() {
        if !frame.function.is_empty() {
            throw_site["function"] = Value::String(frame.function.clone());
        }
    }
    frames.push(throw_site);
    frames
}

fn in_app(file: &str) -> bool {
    !file.contains("/vendor/") && !file.contains("/.cargo/") && !file.contains("/rustc/")
}

fn build_request(attributes: &[(String, AttrValue)]) -> Map<String, Value> {
    let mut request = Map::new();
    if let Some(AttrValue::String(url)) = get_attr(attributes, "http.url") {
        request.insert("url".into(), Value::String(url.clone()));
    }
    if let Some(AttrValue::String(method)) = get_attr(attributes, "http.method") {
        request.insert("method".into(), Value::String(method.clone()));
    }
    if let Some(AttrValue::String(query)) = get_attr(attributes, "http.query") {
        request.insert("query_string".into(), Value::String(query.clone()));
    }
    request
}

fn build_response(attributes: &[(String, AttrValue)]) -> Map<String, Value> {
    let mut response = Map::new();
    if let Some(AttrValue::Int(code)) = get_attr(attributes, "http.response.status_code") {
        response.insert("status_code".into(), json!(code));
    }
    response
}

fn get_attr<'a>(attributes: &'a [(String, AttrValue)], key: &str) -> Option<&'a AttrValue> {
    attributes.iter().find(|(k, _)| k == key).map(|(_, v)| v)
}

fn unix_seconds() -> f64 {
    SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .map(|d| d.as_secs_f64())
        .unwrap_or(0.0)
}

fn date_c() -> String {
    let secs = SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .map(|d| d.as_secs() as i64)
        .unwrap_or(0);
    let (year, month, day, hour, min, sec) = civil_utc(secs);
    format!("{year:04}-{month:02}-{day:02}T{hour:02}:{min:02}:{sec:02}+00:00")
}

fn civil_utc(unix: i64) -> (i32, u32, u32, u32, u32, u32) {
    let day_secs = 86_400;
    let days = unix.div_euclid(day_secs);
    let rem = unix.rem_euclid(day_secs) as u32;
    let hour = rem / 3600;
    let min = (rem % 3600) / 60;
    let sec = rem % 60;
    let (year, month, day) = civil_from_days(days);
    (year, month, day, hour, min, sec)
}

/// Howard Hinnant civil-from-days (UTC).
fn civil_from_days(z: i64) -> (i32, u32, u32) {
    let z = z + 719_468;
    let era = if z >= 0 { z } else { z - 146_096 } / 146_097;
    let doe = (z - era * 146_097) as u64;
    let yoe = (doe - doe / 1_460 + doe / 36_524 - doe / 146_096) / 365;
    let y = yoe as i64 + era * 400;
    let doy = doe - (365 * yoe + yoe / 4 - yoe / 100);
    let mp = (5 * doy + 2) / 153;
    let d = doy - (153 * mp + 2) / 5 + 1;
    let m = if mp < 10 { mp + 3 } else { mp - 9 };
    let y = if m <= 2 { y + 1 } else { y };
    (y as i32, m as u32, d as u32)
}
