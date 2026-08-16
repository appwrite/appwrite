//! Port of `tests/Unit/HeadersTest.php`.

use utopia_nats::Headers;

#[test]
fn test_set_and_get() {
    let mut h = Headers::new();
    h.set("X-Foo", "bar");
    assert_eq!(h.get("X-Foo"), Some("bar"));
}

#[test]
fn test_add_multiple_values() {
    let mut h = Headers::new();
    h.add("X-Multi", "a");
    h.add("X-Multi", "b");
    assert_eq!(h.get("X-Multi"), Some("a"));
    assert_eq!(h.get_all("X-Multi"), vec!["a".to_string(), "b".to_string()]);
}

#[test]
fn test_set_overwrites() {
    let mut h = Headers::new();
    h.add("X-Key", "a");
    h.add("X-Key", "b");
    h.set("X-Key", "c");
    assert_eq!(h.get_all("X-Key"), vec!["c".to_string()]);
}

#[test]
fn test_has() {
    let mut h = Headers::new();
    assert!(!h.has("X-Missing"));
    h.set("X-Present", "yes");
    assert!(h.has("X-Present"));
}

#[test]
fn test_delete() {
    let mut h = Headers::new();
    h.set("X-Del", "val");
    h.delete("X-Del");
    assert!(!h.has("X-Del"));
}

#[test]
fn test_count() {
    let mut h = Headers::new();
    assert_eq!(h.len(), 0);
    h.set("A", "1");
    h.set("B", "2");
    assert_eq!(h.len(), 2);
}

#[test]
fn test_to_wire() {
    let mut h = Headers::new();
    h.set("X-Key", "value");
    h.set("Content-Type", "text/plain");
    let wire = h.to_wire();
    assert!(wire.starts_with("NATS/1.0\r\n"));
    assert!(wire.contains("X-Key: value\r\n"));
    assert!(wire.contains("Content-Type: text/plain\r\n"));
    assert!(wire.ends_with("\r\n\r\n"));
}

#[test]
fn test_to_wire_with_status() {
    let mut h = Headers::new();
    h.set_status("503", "No Responders");
    let wire = h.to_wire();
    assert!(wire.starts_with("NATS/1.0 503 No Responders\r\n"));
}

#[test]
fn test_from_wire() {
    let wire = "NATS/1.0\r\nX-Key: value\r\nAnother: test\r\n\r\n";
    let h = Headers::from_wire(wire).unwrap();
    assert_eq!(h.get("X-Key"), Some("value"));
    assert_eq!(h.get("Another"), Some("test"));
    assert_eq!(h.get_status(), "");
}

#[test]
fn test_from_wire_with_status() {
    let wire = "NATS/1.0 503 No Responders\r\n\r\n";
    let h = Headers::from_wire(wire).unwrap();
    assert_eq!(h.get_status(), "503");
    assert_eq!(h.get_description(), "No Responders");
}

#[test]
fn test_from_wire_with_status_only() {
    let wire = "NATS/1.0 408\r\n\r\n";
    let h = Headers::from_wire(wire).unwrap();
    assert_eq!(h.get_status(), "408");
    assert_eq!(h.get_description(), "");
}

#[test]
fn test_round_trip() {
    let mut original = Headers::new();
    original.set("Nats-Msg-Id", "abc-123");
    original.set("X-Custom", "test");
    let parsed = Headers::from_wire(&original.to_wire()).unwrap();
    assert_eq!(parsed.get("Nats-Msg-Id"), Some("abc-123"));
    assert_eq!(parsed.get("X-Custom"), Some("test"));
}
