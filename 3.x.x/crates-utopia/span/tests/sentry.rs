use std::error::Error;
use std::fmt;
use std::io::{Error as IoError, ErrorKind};

use serde_json::Value;
use utopia_span::{Exporter, Level, Sentry, SentryError, SentryField, Span};

#[derive(Debug)]
struct NamespacedTestException(&'static str);

impl fmt::Display for NamespacedTestException {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        write!(f, "{}", self.0)
    }
}

impl Error for NamespacedTestException {}

#[derive(Debug)]
struct LogicException {
    msg: &'static str,
}

impl fmt::Display for LogicException {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        write!(f, "{}", self.msg)
    }
}

impl Error for LogicException {}

#[derive(Debug)]
struct InvalidArgumentException {
    msg: &'static str,
    source: LogicException,
}

impl fmt::Display for InvalidArgumentException {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        write!(f, "{}", self.msg)
    }
}

impl Error for InvalidArgumentException {
    fn source(&self) -> Option<&(dyn Error + 'static)> {
        Some(&self.source)
    }
}

#[derive(Debug)]
struct RuntimeException {
    msg: String,
    source: Option<Box<dyn Error + Send + Sync>>,
}

impl fmt::Display for RuntimeException {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        write!(f, "{}", self.msg)
    }
}

impl Error for RuntimeException {
    fn source(&self) -> Option<&(dyn Error + 'static)> {
        #[allow(trivial_casts)]
        self.source
            .as_ref()
            .map(|e| e.as_ref() as &(dyn Error + 'static))
    }
}

fn payload(span: &Span) -> Value {
    let exporter = Sentry::new("https://key@sentry.io/123").unwrap();
    let envelope = exporter.build_envelope(span).expect("envelope");
    let lines: Vec<&str> = envelope.split('\n').collect();
    assert_eq!(lines.len(), 3);
    serde_json::from_str(lines[2]).expect("json")
}

#[test]
fn constructor_parses_dsn_variants() {
    assert!(Sentry::new("https://publickey@sentry.io/123456").is_ok());
    assert!(Sentry::new("https://publickey@sentry.example.com:9000/123").is_ok());
    assert!(Sentry::new("http://publickey@localhost/123").is_ok());
}

#[test]
fn constructor_throws_on_invalid_dsn() {
    let err = Sentry::new("http:///invalid").unwrap_err();
    assert!(err.to_string().contains("Invalid Sentry DSN"));
}

#[test]
fn constructor_throws_on_empty_dsn() {
    let err = Sentry::new("").unwrap_err();
    assert_eq!(err, SentryError::DsnRequired);
    assert_eq!(err.to_string(), "Sentry DSN is required");
}

#[test]
fn constructor_throws_on_missing_parts() {
    assert!(matches!(
        Sentry::new("https://sentry.io/123"),
        Err(SentryError::IncompleteDsn)
    ));
    assert!(matches!(
        Sentry::new("https://key@sentry.io"),
        Err(SentryError::IncompleteDsn)
    ));
}

#[test]
fn export_does_not_throw() {
    let exporter = Sentry::new("https://key@sentry.io/123").unwrap();
    let span = Span::new();
    span.set("action", "test");
    span.finish();
    exporter.export(&span);

    let parent = Span::new();
    parent.set("span.parent_id", "abc123def456");
    parent.finish();
    exporter.export(&parent);

    let err = Span::new();
    err.set_error(IoError::new(ErrorKind::Other, "Test error"));
    err.finish();
    exporter.export(&err);
}

#[test]
fn sample_level_filter() {
    let exporter = Sentry::new("https://key@sentry.io/123").unwrap();
    let info = Span::new();
    info.finish();
    assert!(!exporter.sample(&info));

    let warn = Span::new();
    warn.fail_with(Level::Warn, IoError::new(ErrorKind::Other, "Heads up"));
    assert!(exporter.sample(&warn));

    let error = Span::new();
    error.set_error(IoError::new(ErrorKind::Other, "Boom"));
    error.finish();
    assert!(exporter.sample(&error));

    let downgraded = Span::new();
    downgraded.fail_with(
        Level::Info,
        IoError::new(ErrorKind::Other, "Handled, not worth reporting"),
    );
    assert!(!exporter.sample(&downgraded));
}

#[test]
fn sample_composes_custom_sampler() {
    let exporter = Sentry::new_with(
        Some(Box::new(|span: &Span| span.get_action() == "keep")),
        "https://key@sentry.io/123",
        None,
        None,
        None,
        None,
    )
    .unwrap();
    let kept = Span::with_action("keep");
    kept.fail_with(Level::Warn, IoError::new(ErrorKind::Other, "Test"));
    let dropped = Span::with_action("drop");
    dropped.fail_with(Level::Warn, IoError::new(ErrorKind::Other, "Test"));
    assert!(exporter.sample(&kept));
    assert!(!exporter.sample(&dropped));
}

#[test]
fn envelope_chained_exceptions() {
    let root = LogicException { msg: "root cause" };
    let middle = InvalidArgumentException {
        msg: "middle",
        source: root,
    };
    let outer = RuntimeException {
        msg: "outer".into(),
        source: Some(Box::new(middle)),
    };
    let span = Span::with_action("test");
    span.fail(outer);
    let values = payload(&span)["exception"]["values"]
        .as_array()
        .cloned()
        .unwrap();
    assert_eq!(values.len(), 3);
    assert_eq!(values[0]["value"], "root cause");
    assert_eq!(values[2]["value"], "outer");
    assert_eq!(values[2]["mechanism"]["exception_id"], 0);
    assert!(values[2]["mechanism"].get("parent_id").is_none());
    assert_eq!(values[1]["mechanism"]["exception_id"], 1);
    assert_eq!(values[1]["mechanism"]["parent_id"], 0);
    assert_eq!(values[1]["mechanism"]["source"], "__previous__");
    assert_eq!(values[0]["mechanism"]["exception_id"], 2);
    for value in &values {
        assert!(!value["stacktrace"]["frames"].as_array().unwrap().is_empty());
    }
}

#[test]
fn envelope_single_exception_has_no_chain_ids() {
    let span = Span::with_action("test");
    span.fail(IoError::new(ErrorKind::Other, "alone"));
    let values = payload(&span)["exception"]["values"]
        .as_array()
        .cloned()
        .unwrap();
    assert_eq!(values.len(), 1);
    assert_eq!(values[0]["mechanism"]["type"], "generic");
    assert!(values[0]["mechanism"].get("exception_id").is_none());
    assert!(values[0]["mechanism"].get("parent_id").is_none());
}

#[test]
fn envelope_mechanism_handled_reflects_level_and_override() {
    let handled = Span::with_action("test");
    handled.fail_with(Level::Error, IoError::new(ErrorKind::Other, "caught"));
    assert_eq!(
        payload(&handled)["exception"]["values"][0]["mechanism"]["handled"],
        true
    );

    let fatal = Span::with_action("test");
    fatal.fail_with(Level::Fatal, IoError::new(ErrorKind::Other, "crashed"));
    assert_eq!(
        payload(&fatal)["exception"]["values"][0]["mechanism"]["handled"],
        false
    );

    let unhandled_error = Span::with_action("test");
    unhandled_error.set("span.handled", false);
    unhandled_error.fail_with(Level::Error, IoError::new(ErrorKind::Other, "escaped"));
    assert_eq!(
        payload(&unhandled_error)["exception"]["values"][0]["mechanism"]["handled"],
        false
    );

    let handled_fatal = Span::with_action("test");
    handled_fatal.set("span.handled", true);
    handled_fatal.fail_with(
        Level::Fatal,
        IoError::new(ErrorKind::Other, "caught but fatal"),
    );
    assert_eq!(
        payload(&handled_fatal)["exception"]["values"][0]["mechanism"]["handled"],
        true
    );
}

#[test]
fn envelope_includes_exception_module() {
    let span = Span::with_action("test");
    span.fail(NamespacedTestException("namespaced"));
    let values = payload(&span)["exception"]["values"]
        .as_array()
        .cloned()
        .unwrap();
    assert!(values[0]["type"]
        .as_str()
        .unwrap()
        .contains("NamespacedTestException"));
    assert!(values[0].get("module").is_some());
}

#[test]
fn envelope_frames_carry_function_names() {
    let error = throw_and_catch();
    let span = Span::with_action("test");
    span.fail(error);
    let frames = payload(&span)["exception"]["values"][0]["stacktrace"]["frames"]
        .as_array()
        .cloned()
        .unwrap();
    assert!(!frames.is_empty());
    let throw_site = frames.last().unwrap();
    assert!(throw_site["filename"]
        .as_str()
        .unwrap()
        .contains("sentry.rs"));
}

fn throw_and_catch() -> IoError {
    IoError::new(ErrorKind::Other, "thrown here")
}

#[test]
fn envelope_caps_chain_walk() {
    fn wrap(depth: usize) -> RuntimeException {
        if depth == 0 {
            return RuntimeException {
                msg: "level 0".into(),
                source: None,
            };
        }
        RuntimeException {
            msg: format!("level {depth}"),
            source: Some(Box::new(wrap(depth - 1))),
        }
    }
    let span = Span::with_action("test");
    span.fail(wrap(14));
    let values = payload(&span)["exception"]["values"]
        .as_array()
        .cloned()
        .unwrap();
    assert_eq!(values.len(), 10);
    assert_eq!(values[9]["value"], "level 14");
    assert_eq!(values[0]["value"], "level 5");
}

#[test]
fn classifier_distributes_attributes() {
    let exporter = Sentry::new_with(
        None,
        "https://key@sentry.io/123",
        None,
        None,
        None,
        Some(Box::new(|key: &str| {
            if key.starts_with("tenant.") {
                SentryField::Tag
            } else if key.starts_with("user.") {
                SentryField::Context
            } else {
                SentryField::Extra
            }
        })),
    )
    .unwrap();
    let span = Span::with_action("api.request");
    span.set("tenant.id", "acme-corp");
    span.set("user.id", "12345");
    span.set("debug.info", "some debug data");
    span.set_error(IoError::new(ErrorKind::Other, "Test error"));
    span.finish();
    exporter.export(&span);
}

#[test]
fn http_convention_attributes() {
    let span = Span::with_action("http.request");
    span.set("http.url", "https://api.example.com/users");
    span.set("http.method", "POST");
    span.set("http.query", "page=1&limit=10");
    span.set("http.response.status_code", 201_i64);
    span.set_error(IoError::new(ErrorKind::Other, "Request failed"));
    span.finish();
    let body = payload(&span);
    assert_eq!(body["request"]["url"], "https://api.example.com/users");
    assert_eq!(body["request"]["method"], "POST");
    assert_eq!(body["request"]["query_string"], "page=1&limit=10");
    assert_eq!(body["contexts"]["response"]["status_code"], 201);
}
