use std::io::{Error as IoError, ErrorKind};
use std::sync::{Arc, Mutex};
use std::thread;
use std::time::Duration;

use parking_lot::Mutex as ParkMutex;
use utopia_span::{AttrValue, Exporter, Level, Memory, Span};

fn globals() -> parking_lot::MutexGuard<'static, ()> {
    static LOCK: ParkMutex<()> = ParkMutex::new(());
    LOCK.lock()
}

struct Capture {
    spans: Arc<Mutex<Vec<Span>>>,
    sampler: Box<dyn Fn(&Span) -> bool + Send + Sync>,
}

impl Capture {
    fn new(spans: Arc<Mutex<Vec<Span>>>) -> Self {
        Self {
            spans,
            sampler: Box::new(|_| true),
        }
    }

    fn with_sampler(
        spans: Arc<Mutex<Vec<Span>>>,
        sampler: impl Fn(&Span) -> bool + Send + Sync + 'static,
    ) -> Self {
        Self {
            spans,
            sampler: Box::new(sampler),
        }
    }
}

impl Exporter for Capture {
    fn sample(&self, span: &Span) -> bool {
        (self.sampler)(span)
    }

    fn export(&self, span: &Span) {
        self.spans.lock().expect("spans").push(span.clone());
    }
}

fn arc_exp(exporter: impl Exporter + 'static) -> Arc<dyn Exporter> {
    Arc::new(exporter)
}

fn setup() {
    Span::set_exporters(Vec::<Arc<dyn Exporter>>::new());
    Span::set_storage(Some(Arc::new(Memory::new())));
}

#[test]
fn constructor_sets_span_attributes() {
    let _g = globals();
    setup();
    let span = Span::new();
    let trace_id = span
        .get("span.trace_id")
        .and_then(|v| v.as_str().map(str::to_string));
    let span_id = span
        .get("span.id")
        .and_then(|v| v.as_str().map(str::to_string));
    let started_at = span.get("span.started_at").and_then(|v| v.as_f64());
    assert!(trace_id.as_ref().is_some_and(|s| s.len() == 32));
    assert!(span_id.as_ref().is_some_and(|s| s.len() == 16));
    assert!(started_at.is_some());
}

#[test]
fn set_and_get() {
    let span = Span::new();
    span.set("key", "value");
    assert_eq!(span.get("key"), Some(AttrValue::from("value")));
}

#[test]
fn set_returns_self() {
    let span = Span::new();
    let result = span.set("key", "value");
    assert!(std::ptr::eq(result, &span));
}

#[test]
fn get_returns_none_for_missing_key() {
    assert!(Span::new().get("nonexistent").is_none());
}

#[test]
fn set_accepts_scalars() {
    let span = Span::new();
    span.set("string", "string value");
    span.set("int", 42);
    span.set("float", 12.34);
    span.set("bool", true);
    span.set("null", AttrValue::Null);
    assert_eq!(span.get("string"), Some(AttrValue::from("string value")));
    assert_eq!(span.get("int"), Some(AttrValue::Int(42)));
    assert_eq!(span.get("float"), Some(AttrValue::Float(12.34)));
    assert_eq!(span.get("bool"), Some(AttrValue::Bool(true)));
    assert_eq!(span.get("null"), Some(AttrValue::Null));
}

#[test]
fn get_attributes_returns_all() {
    let span = Span::new();
    span.set("key1", "value1");
    span.set("key2", "value2");
    let attributes = span.get_attributes();
    assert!(attributes
        .iter()
        .any(|(k, v)| k == "key1" && *v == AttrValue::from("value1")));
    assert!(attributes.iter().any(|(k, _)| k == "span.trace_id"));
    assert!(attributes.iter().any(|(k, _)| k == "span.id"));
    assert!(attributes.iter().any(|(k, _)| k == "span.started_at"));
}

#[test]
fn set_error_stores_throwable() {
    let span = Span::new();
    span.set_error(IoError::new(ErrorKind::Other, "Test error"));
    let error = span.get_error().expect("error");
    assert_eq!(error.message, "Test error");
}

#[test]
fn finish_sets_finished_at_and_duration() {
    let _g = globals();
    setup();
    let span = Span::new();
    assert!(span.get("span.finished_at").is_none());
    assert!(span.get("span.duration").is_none());
    span.finish();
    assert!(span
        .get("span.finished_at")
        .and_then(|v| v.as_f64())
        .is_some());
    assert!(span.get("span.duration").and_then(|v| v.as_f64()).is_some());
}

#[test]
fn finish_calculates_duration() {
    let _g = globals();
    setup();
    let span = Span::new();
    thread::sleep(Duration::from_millis(10));
    span.finish();
    let duration = span.get("span.duration").and_then(|v| v.as_f64()).unwrap();
    assert!(duration > 0.009);
    assert!(duration < 0.1);
}

#[test]
fn finish_exports_to_all_exporters() {
    let _g = globals();
    setup();
    let a = Arc::new(Mutex::new(Vec::new()));
    let b = Arc::new(Mutex::new(Vec::new()));
    Span::set_exporters([
        arc_exp(Capture::new(Arc::clone(&a))),
        arc_exp(Capture::new(Arc::clone(&b))),
    ]);
    Span::init("test", None).finish();
    assert_eq!(a.lock().unwrap().len(), 1);
    assert_eq!(b.lock().unwrap().len(), 1);
}

#[test]
fn finish_clears_current_span() {
    let _g = globals();
    setup();
    let span = Span::init("test", None);
    assert!(Span::current().is_some());
    span.finish();
    assert!(Span::current().is_none());
}

#[test]
fn init_creates_and_stores_span() {
    let _g = globals();
    setup();
    let span = Span::init("test", None);
    assert_eq!(span.get_action(), "test");
    assert!(Span::current().is_some());
}

#[test]
fn current_returns_none_when_no_span() {
    let _g = globals();
    setup();
    assert!(Span::current().is_none());
}

#[test]
fn add_sets_attribute_on_current() {
    let _g = globals();
    setup();
    let span = Span::init("test", None);
    Span::add("key", "value");
    assert_eq!(span.get("key"), Some(AttrValue::from("value")));
}

#[test]
fn add_does_nothing_without_current() {
    let _g = globals();
    setup();
    Span::add("key", "value");
    assert!(Span::current().is_none());
}

#[test]
fn finish_accepts_error() {
    let _g = globals();
    setup();
    let span = Span::new();
    span.fail(IoError::new(ErrorKind::Other, "Test"));
    assert_eq!(span.get_error().unwrap().message, "Test");
}

#[test]
fn finish_with_error_sets_level_error() {
    let _g = globals();
    setup();
    let span = Span::new();
    span.fail(IoError::new(ErrorKind::Other, "Test"));
    assert_eq!(span.get("level"), Some(AttrValue::from("error")));
}

#[test]
fn sampler_filters_export() {
    let _g = globals();
    setup();
    let exported = Arc::new(Mutex::new(Vec::new()));
    Span::set_exporters([arc_exp(Capture::with_sampler(Arc::clone(&exported), |s| {
        s.get_error().is_some()
    }))]);
    Span::init("test", None).finish();
    let span2 = Span::init("test", None);
    span2.set_error(IoError::new(ErrorKind::Other, "Error"));
    span2.finish();
    assert_eq!(exported.lock().unwrap().len(), 1);
}

#[test]
fn set_exporters_replaces_and_clears() {
    let _g = globals();
    setup();
    let first = Arc::new(Mutex::new(Vec::new()));
    let second = Arc::new(Mutex::new(Vec::new()));
    Span::set_exporters([arc_exp(Capture::new(Arc::clone(&first)))]);
    Span::set_exporters([arc_exp(Capture::new(Arc::clone(&second)))]);
    Span::init("test", None).finish();
    assert_eq!(first.lock().unwrap().len(), 0);
    assert_eq!(second.lock().unwrap().len(), 1);
    Span::set_exporters(Vec::<Arc<dyn Exporter>>::new());
    Span::init("test", None).finish();
    assert_eq!(second.lock().unwrap().len(), 1);
}

#[test]
fn set_storage_null_clears() {
    let _g = globals();
    setup();
    Span::init("test", None);
    Span::set_storage(None);
    assert!(Span::current().is_none());
}

#[test]
fn init_without_storage_returns_span() {
    let _g = globals();
    Span::set_storage(None);
    let span = Span::init("test", None);
    assert_eq!(span.get_action(), "test");
}

#[test]
fn set_overwrites_existing() {
    let span = Span::new();
    span.set("key", "value1");
    span.set("key", "value2");
    assert_eq!(span.get("key"), Some(AttrValue::from("value2")));
}

#[test]
fn multiple_spans_in_sequence() {
    let _g = globals();
    setup();
    let exported = Arc::new(Mutex::new(Vec::new()));
    Span::set_exporters([arc_exp(Capture::new(Arc::clone(&exported)))]);
    let s1 = Span::init("test", None);
    s1.set("name", "first");
    s1.finish();
    let s2 = Span::init("test", None);
    s2.set("name", "second");
    s2.finish();
    let exported = exported.lock().unwrap();
    assert_eq!(exported.len(), 2);
    assert_eq!(exported[0].get("name"), Some(AttrValue::from("first")));
    assert_eq!(exported[1].get("name"), Some(AttrValue::from("second")));
}

#[test]
fn ids_are_unique() {
    let a = Span::new();
    let b = Span::new();
    assert_ne!(a.get("span.trace_id"), b.get("span.trace_id"));
    assert_ne!(a.get("span.id"), b.get("span.id"));
}

#[test]
fn can_overwrite_builtin_and_parent_id() {
    let span = Span::new();
    span.set("span.trace_id", "custom-trace-id-12345678");
    span.set("span.parent_id", "abc123def456");
    assert_eq!(
        span.get("span.trace_id"),
        Some(AttrValue::from("custom-trace-id-12345678"))
    );
    assert_eq!(
        span.get("span.parent_id"),
        Some(AttrValue::from("abc123def456"))
    );
}

#[test]
fn get_traceparent_format_and_roundtrip() {
    let _g = globals();
    setup();
    let span1 = Span::new();
    let tp = span1.get_traceparent();
    let parts: Vec<&str> = tp.split('-').collect();
    assert_eq!(parts.len(), 4);
    assert_eq!(parts[0], "00");
    assert_eq!(parts[1].len(), 32);
    assert_eq!(parts[2].len(), 16);
    assert_eq!(parts[3], "01");
    let span2 = Span::init("test", Some(&tp));
    assert_eq!(span1.get("span.trace_id"), span2.get("span.trace_id"));
    assert_eq!(span1.get("span.id"), span2.get("span.parent_id"));
}

#[test]
fn init_with_valid_and_invalid_traceparent() {
    let _g = globals();
    setup();
    let span = Span::init(
        "test",
        Some("00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01"),
    );
    assert_eq!(
        span.get("span.trace_id")
            .and_then(|v| v.as_str().map(str::to_string))
            .as_deref(),
        Some("0af7651916cd43dd8448eb211c80319c")
    );
    assert_eq!(
        span.get("span.parent_id")
            .and_then(|v| v.as_str().map(str::to_string))
            .as_deref(),
        Some("b7ad6b7169203331")
    );
    let invalid = Span::init("test", Some("invalid-traceparent"));
    assert_eq!(
        invalid
            .get("span.trace_id")
            .and_then(|v| v.as_str().map(|s| s.len())),
        Some(32)
    );
    assert!(invalid.get("span.parent_id").is_none());
}

#[test]
fn static_traceparent() {
    let _g = globals();
    setup();
    assert!(Span::traceparent().is_none());
    let span = Span::init("test", None);
    assert_eq!(Span::traceparent(), Some(span.get_traceparent()));
}

#[test]
fn finish_level_defaults_and_override() {
    let _g = globals();
    setup();
    let span = Span::new();
    assert!(span.get("level").is_none());
    span.finish();
    assert_eq!(span.get("level"), Some(AttrValue::from("info")));

    let err = Span::new();
    err.set_error(IoError::new(ErrorKind::Other, "Test"));
    err.finish();
    assert_eq!(err.get("level"), Some(AttrValue::from("error")));

    let warn = Span::new();
    warn.fail_with(Level::Warn, IoError::new(ErrorKind::Other, "Test"));
    assert_eq!(warn.get("level"), Some(AttrValue::from("warn")));

    let owned = Span::new();
    owned.set("level", "warning");
    owned.finish();
    assert_eq!(owned.get("level"), Some(AttrValue::from("info")));
}

#[test]
fn sampler_filters_by_duration() {
    let _g = globals();
    setup();
    let exported = Arc::new(Mutex::new(Vec::new()));
    Span::set_exporters([arc_exp(Capture::with_sampler(Arc::clone(&exported), |s| {
        s.get("span.duration")
            .and_then(|v| v.as_f64())
            .is_some_and(|d| d > 0.005)
    }))]);
    Span::init("test", None).finish();
    let slow = Span::init("test", None);
    thread::sleep(Duration::from_millis(6));
    slow.finish();
    assert_eq!(exported.lock().unwrap().len(), 1);
}

#[test]
fn independent_samplers() {
    let _g = globals();
    setup();
    let yes = Arc::new(Mutex::new(Vec::new()));
    let no = Arc::new(Mutex::new(Vec::new()));
    Span::set_exporters([
        arc_exp(Capture::with_sampler(Arc::clone(&yes), |_| true)),
        arc_exp(Capture::with_sampler(Arc::clone(&no), |_| false)),
    ]);
    Span::init("test", None).finish();
    assert_eq!(yes.lock().unwrap().len(), 1);
    assert_eq!(no.lock().unwrap().len(), 0);
}
