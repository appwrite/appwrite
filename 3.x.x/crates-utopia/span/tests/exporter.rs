use std::io::{Error as IoError, ErrorKind};

use utopia_span::{AttrValue, Exporter, NoneExporter, Pretty, Span, Stdout};

#[test]
fn none_export_does_not_throw() {
    let exporter = NoneExporter::new();
    let span = Span::new();
    exporter.export(&span);
    let err = Span::new();
    err.set("action", "test");
    err.set_error(IoError::new(ErrorKind::Other, "Error"));
    exporter.export(&err);
    for _ in 0..100 {
        exporter.export(&Span::new());
    }
    assert!(!exporter.sample(&span));
}

#[test]
fn pretty_export_handles_types_and_error() {
    let exporter = Pretty::new();
    let span = Span::with_action("test.types");
    span.set("string", "value");
    span.set("int", 42);
    span.set("float", 12.34);
    span.set("bool", true);
    span.set("null", AttrValue::Null);
    let rendered = exporter.format(&span);
    assert!(rendered.contains("test.types"));
    assert!(rendered.contains("string"));
    exporter.export(&span);

    let err = Span::with_action("test.error");
    err.set_error(IoError::new(ErrorKind::Other, "Test error"));
    let rendered = exporter.format(&err);
    assert!(rendered.contains("Test error"));
    exporter.export(&err);
}

#[test]
fn pretty_includes_metadata_after_finish() {
    let span = Span::with_action("test.meta");
    span.finish();
    let keys: Vec<_> = span.get_attributes().into_iter().map(|(k, _)| k).collect();
    assert!(keys.contains(&"span.trace_id".into()));
    assert!(keys.contains(&"span.finished_at".into()));
    assert!(keys.contains(&"span.duration".into()));
}

#[test]
fn stdout_writes_json_with_types() {
    let exporter = Stdout::new();
    let span = Span::new();
    span.set("string", "value");
    span.set("int", 42);
    span.set("float", 12.34);
    span.set("bool", true);
    span.set("null", AttrValue::Null);
    span.finish();
    let json = exporter.format(&span).expect("json");
    assert!(json.contains("\"action\""));
    assert!(json.contains("\"level\""));
    assert!(json.contains("42"));
    exporter.export(&span);

    let err = Span::new();
    err.set_error(IoError::new(ErrorKind::Other, "Test error"));
    let json = exporter.format(&err).expect("json");
    assert!(json.contains("error.type"));
    assert!(json.contains("Test error"));
}
