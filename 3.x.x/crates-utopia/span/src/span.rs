//! Span context (PHP `Utopia\Span\Span`).

use std::panic::{catch_unwind, AssertUnwindSafe, Location};
use std::sync::{Arc, OnceLock};
use std::time::{SystemTime, UNIX_EPOCH};

use parking_lot::Mutex;
use rand::RngCore;

use crate::attr::AttrValue;
use crate::error::SpanError;
use crate::exporter::Exporter;
use crate::level::Level;
use crate::storage::Storage;

struct Inner {
    action: String,
    attributes: Vec<(String, AttrValue)>,
    error: Option<SpanError>,
}

/// A tracing span. Cheap to clone (shared inner state, like a PHP object).
#[derive(Clone)]
pub struct Span {
    inner: Arc<Mutex<Inner>>,
}

impl std::fmt::Debug for Span {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        let inner = self.inner.lock();
        f.debug_struct("Span")
            .field("action", &inner.action)
            .field("attributes", &inner.attributes)
            .finish_non_exhaustive()
    }
}

static STORAGE: OnceLock<Mutex<Option<Arc<dyn Storage>>>> = OnceLock::new();
static EXPORTERS: OnceLock<Mutex<Vec<Arc<dyn Exporter>>>> = OnceLock::new();

fn storage_slot() -> &'static Mutex<Option<Arc<dyn Storage>>> {
    STORAGE.get_or_init(|| Mutex::new(None))
}

fn exporters_slot() -> &'static Mutex<Vec<Arc<dyn Exporter>>> {
    EXPORTERS.get_or_init(|| Mutex::new(Vec::new()))
}

impl Span {
    /// PHP `new Span($action = 'unknown')`.
    pub fn new() -> Self {
        Self::with_action("unknown")
    }

    pub fn with_action(action: impl Into<String>) -> Self {
        let mut trace_id = [0u8; 16];
        let mut span_id = [0u8; 8];
        let mut rng = rand::thread_rng();
        rng.fill_bytes(&mut trace_id);
        rng.fill_bytes(&mut span_id);
        let started = unix_seconds();
        Self {
            inner: Arc::new(Mutex::new(Inner {
                action: action.into(),
                attributes: vec![
                    (
                        "span.trace_id".into(),
                        AttrValue::String(hex_encode(&trace_id)),
                    ),
                    ("span.id".into(), AttrValue::String(hex_encode(&span_id))),
                    ("span.started_at".into(), AttrValue::Float(started)),
                ],
                error: None,
            })),
        }
    }

    /// PHP `Span::setStorage(?Storage $storage)`.
    pub fn set_storage(storage: Option<Arc<dyn Storage>>) {
        *storage_slot().lock() = storage;
    }

    /// PHP `Span::setExporters(Exporter ...$exporters)`.
    pub fn set_exporters(exporters: impl IntoIterator<Item = Arc<dyn Exporter>>) {
        *exporters_slot().lock() = exporters.into_iter().collect();
    }

    /// PHP `Span::init($action, $traceparent = null)`.
    pub fn init(action: impl Into<String>, traceparent: Option<&str>) -> Self {
        let span = Self::with_action(action);
        if let Some(header) = traceparent {
            if let Some((trace_id, parent_id)) = parse_traceparent(header) {
                span.set("span.trace_id", trace_id);
                span.set("span.parent_id", parent_id);
            }
        }
        if let Some(storage) = storage_slot().lock().clone() {
            storage.set(Some(span.clone()));
        }
        span
    }

    /// PHP `Span::current()`.
    pub fn current() -> Option<Self> {
        storage_slot().lock().as_ref().and_then(|s| s.get())
    }

    /// PHP `Span::add($key, $value)`.
    pub fn add(key: impl Into<String>, value: impl Into<AttrValue>) {
        if let Some(span) = Self::current() {
            span.set(key, value);
        }
    }

    /// PHP `Span::traceparent()`.
    pub fn traceparent() -> Option<String> {
        Self::current().map(|span| span.get_traceparent())
    }

    /// PHP `$span->set($key, $value)`.
    pub fn set(&self, key: impl Into<String>, value: impl Into<AttrValue>) -> &Self {
        let key = key.into();
        let value = value.into();
        let mut inner = self.inner.lock();
        if let Some(existing) = inner.attributes.iter_mut().find(|(k, _)| *k == key) {
            existing.1 = value;
        } else {
            inner.attributes.push((key, value));
        }
        self
    }

    /// PHP `$span->setError($error)`.
    #[track_caller]
    pub fn set_error<E: std::error::Error + Send + Sync + 'static>(&self, error: E) -> &Self {
        let location = Location::caller();
        self.inner.lock().error = Some(SpanError::from_typed(&error, location));
        self
    }

    pub fn get_error(&self) -> Option<SpanError> {
        self.inner.lock().error.clone()
    }

    pub fn get_action(&self) -> String {
        self.inner.lock().action.clone()
    }

    pub fn get(&self, key: &str) -> Option<AttrValue> {
        self.inner
            .lock()
            .attributes
            .iter()
            .find(|(k, _)| k == key)
            .map(|(_, v)| v.clone())
    }

    /// PHP `$span->getTraceparent()`.
    pub fn get_traceparent(&self) -> String {
        let trace_id = self
            .get("span.trace_id")
            .and_then(|v| v.as_str().map(str::to_string))
            .unwrap_or_default();
        let span_id = self
            .get("span.id")
            .and_then(|v| v.as_str().map(str::to_string))
            .unwrap_or_default();
        format!("00-{trace_id}-{span_id}-01")
    }

    pub fn get_attributes(&self) -> Vec<(String, AttrValue)> {
        self.inner.lock().attributes.clone()
    }

    /// PHP `$span->finish()`.
    pub fn finish(&self) {
        self.finish_inner(None);
    }

    /// PHP `$span->finish(level: $level)`.
    pub fn finish_level(&self, level: Level) {
        self.finish_inner(Some(level));
    }

    /// PHP `$span->finish(error: $error)`.
    #[track_caller]
    pub fn fail<E: std::error::Error + Send + Sync + 'static>(&self, error: E) {
        let location = Location::caller();
        self.inner.lock().error = Some(SpanError::from_typed(&error, location));
        self.finish_inner(None);
    }

    /// PHP `$span->finish(level: $level, error: $error)`.
    #[track_caller]
    pub fn fail_with<E: std::error::Error + Send + Sync + 'static>(&self, level: Level, error: E) {
        let location = Location::caller();
        self.inner.lock().error = Some(SpanError::from_typed(&error, location));
        self.finish_inner(Some(level));
    }

    fn finish_inner(&self, level: Option<Level>) {
        let finished_at = unix_seconds();
        {
            let mut inner = self.inner.lock();
            let started_at = inner
                .attributes
                .iter()
                .find(|(k, _)| k == "span.started_at")
                .and_then(|(_, v)| v.as_f64())
                .unwrap_or(finished_at);
            upsert(
                &mut inner.attributes,
                "span.finished_at",
                AttrValue::Float(finished_at),
            );
            upsert(
                &mut inner.attributes,
                "span.duration",
                AttrValue::Float(finished_at - started_at),
            );
            let resolved = level.unwrap_or(if inner.error.is_some() {
                Level::Error
            } else {
                Level::Info
            });
            upsert(
                &mut inner.attributes,
                "level",
                AttrValue::String(resolved.as_str().to_string()),
            );
        }

        let exporters = exporters_slot().lock().clone();
        for exporter in exporters {
            let _ = catch_unwind(AssertUnwindSafe(|| {
                if exporter.sample(self) {
                    exporter.export(self);
                }
            }));
        }

        if let Some(storage) = storage_slot().lock().clone() {
            storage.set(None);
        }
    }
}

impl Default for Span {
    fn default() -> Self {
        Self::new()
    }
}

fn upsert(attrs: &mut Vec<(String, AttrValue)>, key: &str, value: AttrValue) {
    if let Some(existing) = attrs.iter_mut().find(|(k, _)| k == key) {
        existing.1 = value;
    } else {
        attrs.push((key.to_string(), value));
    }
}

fn unix_seconds() -> f64 {
    SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .map(|d| d.as_secs_f64())
        .unwrap_or(0.0)
}

fn hex_encode(bytes: &[u8]) -> String {
    const HEX: &[u8; 16] = b"0123456789abcdef";
    let mut out = String::with_capacity(bytes.len() * 2);
    for byte in bytes {
        out.push(HEX[(byte >> 4) as usize] as char);
        out.push(HEX[(byte & 0x0f) as usize] as char);
    }
    out
}

/// W3C traceparent: `{version}-{trace_id}-{parent_id}-{flags}`.
fn parse_traceparent(header: &str) -> Option<(String, String)> {
    let parts: Vec<&str> = header.split('-').collect();
    if parts.len() != 4 {
        return None;
    }
    if parts[0] != "00" {
        return None;
    }
    if parts[1].len() != 32 || !parts[1].bytes().all(|b| b.is_ascii_hexdigit()) {
        return None;
    }
    if parts[2].len() != 16 || !parts[2].bytes().all(|b| b.is_ascii_hexdigit()) {
        return None;
    }
    if parts[3].len() != 2 || !parts[3].bytes().all(|b| b.is_ascii_hexdigit()) {
        return None;
    }
    Some((parts[1].to_string(), parts[2].to_string()))
}
