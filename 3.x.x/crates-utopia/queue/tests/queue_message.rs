use serde_json::json;
use utopia_queue::{Message, Queue, QueueError};

#[test]
fn empty_name_throws() {
    let err = Queue::new("").unwrap_err();
    assert_eq!(
        err,
        QueueError::invalid_argument("Cannot create queue with empty name.")
    );
}

#[test]
fn name_zero_throws() {
    let err = Queue::new("0").unwrap_err();
    assert_eq!(
        err,
        QueueError::invalid_argument("Cannot create queue with empty name.")
    );
}

#[test]
fn queue_defaults() {
    let q = Queue::new("emails").unwrap();
    assert_eq!(q.name, "emails");
    assert_eq!(q.namespace, "utopia-queue");
    assert_eq!(q.job_ttl, 0);
}

#[test]
fn message_as_array_keys() {
    let mut msg = Message::new();
    msg.set_pid("p1")
        .set_queue("q")
        .set_timestamp(1_700_000_000)
        .set_payload(json!({"n": 1}))
        .set_attempts(2);
    let arr = msg.as_array();
    assert_eq!(arr["pid"], "p1");
    assert_eq!(arr["queue"], "q");
    assert_eq!(arr["timestamp"], 1_700_000_000);
    assert_eq!(arr["payload"]["n"], 1);
    assert_eq!(arr["attempts"], 2);
}

#[test]
fn message_empty_payload_is_null() {
    let msg = Message::new();
    assert!(msg.as_array()["payload"].is_null());
}

#[test]
fn message_from_value() {
    let msg = Message::from_value(&json!({
        "pid": "abc",
        "queue": "emails",
        "timestamp": 123,
        "payload": {"a": 1},
        "attempts": 4
    }));
    assert_eq!(msg.get_pid(), "abc");
    assert_eq!(msg.get_queue(), "emails");
    assert_eq!(msg.get_timestamp(), 123);
    assert_eq!(msg.get_payload()["a"], 1);
    assert_eq!(msg.get_attempts(), 4);
}
