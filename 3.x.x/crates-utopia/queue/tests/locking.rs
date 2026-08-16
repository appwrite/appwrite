//! PHP `LockingTest` - every `Connection` method runs inside a single acquire/release.

use serde_json::{json, Value};
use utopia_queue::connection::Locking;
use utopia_queue::connection::{Connection, StoredValue};
use utopia_queue::lock::Lock;
use utopia_queue::QueueError;

struct Recorder {
    events: parking_lot::Mutex<Vec<String>>,
    calls: parking_lot::Mutex<Vec<(String, String)>>,
    last_timeout: parking_lot::Mutex<Option<f64>>,
}

impl Recorder {
    fn new() -> Self {
        Self {
            events: parking_lot::Mutex::new(Vec::new()),
            calls: parking_lot::Mutex::new(Vec::new()),
            last_timeout: parking_lot::Mutex::new(None),
        }
    }
}

struct RecordingLock {
    recorder: std::sync::Arc<Recorder>,
}

impl Lock for RecordingLock {
    fn acquire(&self, timeout: f64) -> bool {
        *self.recorder.last_timeout.lock() = Some(timeout);
        self.recorder.events.lock().push("acquire".into());
        true
    }

    fn try_acquire(&self) -> bool {
        self.acquire(0.0)
    }

    fn release(&self) {
        self.recorder.events.lock().push("release".into());
    }
}

struct RecordingConnection {
    recorder: std::sync::Arc<Recorder>,
}

impl RecordingConnection {
    fn record(&self, method: &str, args: String) {
        self.recorder.events.lock().push(method.to_owned());
        self.recorder.calls.lock().push((method.to_owned(), args));
    }
}

impl Connection for RecordingConnection {
    fn right_push_array(&self, queue: &str, payload: &Value) -> Result<bool, QueueError> {
        self.record("rightPushArray", format!("{queue}:{payload}"));
        Ok(true)
    }
    fn right_pop_array(&self, queue: &str, timeout: i64) -> Result<Option<Value>, QueueError> {
        self.record("rightPopArray", format!("{queue}:{timeout}"));
        Ok(Some(json!({"popped": "right"})))
    }
    fn right_pop_left_push_array(
        &self,
        queue: &str,
        destination: &str,
        timeout: i64,
    ) -> Result<Option<Value>, QueueError> {
        self.record(
            "rightPopLeftPushArray",
            format!("{queue}:{destination}:{timeout}"),
        );
        Ok(Some(json!({"rpoplpush": true})))
    }
    fn left_push_array(&self, queue: &str, payload: &Value) -> Result<bool, QueueError> {
        self.record("leftPushArray", format!("{queue}:{payload}"));
        Ok(true)
    }
    fn left_pop_array(&self, queue: &str, timeout: i64) -> Result<Option<Value>, QueueError> {
        self.record("leftPopArray", format!("{queue}:{timeout}"));
        Ok(Some(json!({"popped": "left"})))
    }
    fn right_push(&self, queue: &str, payload: &str) -> Result<bool, QueueError> {
        self.record("rightPush", format!("{queue}:{payload}"));
        Ok(true)
    }
    fn right_pop(&self, queue: &str, timeout: i64) -> Result<Option<String>, QueueError> {
        self.record("rightPop", format!("{queue}:{timeout}"));
        Ok(Some("right-pop".into()))
    }
    fn right_pop_left_push(
        &self,
        queue: &str,
        destination: &str,
        timeout: i64,
    ) -> Result<Option<String>, QueueError> {
        self.record(
            "rightPopLeftPush",
            format!("{queue}:{destination}:{timeout}"),
        );
        Ok(Some("rpoplpush".into()))
    }
    fn left_push(&self, queue: &str, payload: &str) -> Result<bool, QueueError> {
        self.record("leftPush", format!("{queue}:{payload}"));
        Ok(true)
    }
    fn left_pop(&self, queue: &str, timeout: i64) -> Result<Option<String>, QueueError> {
        self.record("leftPop", format!("{queue}:{timeout}"));
        Ok(Some("left-pop".into()))
    }
    fn list_remove(&self, queue: &str, key: &str) -> Result<bool, QueueError> {
        self.record("listRemove", format!("{queue}:{key}"));
        Ok(true)
    }
    fn list_size(&self, key: &str) -> Result<i64, QueueError> {
        self.record("listSize", key.to_owned());
        Ok(7)
    }
    fn list_range(&self, key: &str, total: i64, offset: i64) -> Result<Vec<Value>, QueueError> {
        self.record("listRange", format!("{key}:{total}:{offset}"));
        Ok(vec![json!("a"), json!("b")])
    }
    fn remove(&self, key: &str) -> Result<bool, QueueError> {
        self.record("remove", key.to_owned());
        Ok(true)
    }
    fn set(&self, key: &str, value: &str, ttl: i64) -> Result<bool, QueueError> {
        self.record("set", format!("{key}:{value}:{ttl}"));
        Ok(true)
    }
    fn get(&self, key: &str) -> Result<Option<StoredValue>, QueueError> {
        self.record("get", key.to_owned());
        Ok(Some(StoredValue::String("value".into())))
    }
    fn set_array(&self, key: &str, value: &Value, ttl: i64) -> Result<bool, QueueError> {
        self.record("setArray", format!("{key}:{value}:{ttl}"));
        Ok(true)
    }
    fn increment(&self, key: &str) -> Result<i64, QueueError> {
        self.record("increment", key.to_owned());
        Ok(3)
    }
    fn decrement(&self, key: &str) -> Result<i64, QueueError> {
        self.record("decrement", key.to_owned());
        Ok(2)
    }
    fn ping(&self) -> Result<bool, QueueError> {
        self.record("ping", String::new());
        Ok(true)
    }
    fn close(&self) {
        self.record("close", String::new());
    }
}

struct ThrowingConnection;

impl Connection for ThrowingConnection {
    fn right_push_array(&self, _: &str, _: &Value) -> Result<bool, QueueError> {
        Ok(true)
    }
    fn right_pop_array(&self, _: &str, _: i64) -> Result<Option<Value>, QueueError> {
        Ok(None)
    }
    fn right_pop_left_push_array(
        &self,
        _: &str,
        _: &str,
        _: i64,
    ) -> Result<Option<Value>, QueueError> {
        Ok(None)
    }
    fn left_push_array(&self, _: &str, _: &Value) -> Result<bool, QueueError> {
        Ok(true)
    }
    fn left_pop_array(&self, _: &str, _: i64) -> Result<Option<Value>, QueueError> {
        Ok(None)
    }
    fn right_push(&self, _: &str, _: &str) -> Result<bool, QueueError> {
        Ok(true)
    }
    fn right_pop(&self, _: &str, _: i64) -> Result<Option<String>, QueueError> {
        Ok(None)
    }
    fn right_pop_left_push(&self, _: &str, _: &str, _: i64) -> Result<Option<String>, QueueError> {
        Ok(None)
    }
    fn left_push(&self, _: &str, _: &str) -> Result<bool, QueueError> {
        Ok(true)
    }
    fn left_pop(&self, _: &str, _: i64) -> Result<Option<String>, QueueError> {
        Ok(None)
    }
    fn list_remove(&self, _: &str, _: &str) -> Result<bool, QueueError> {
        Ok(true)
    }
    fn list_size(&self, _: &str) -> Result<i64, QueueError> {
        Ok(0)
    }
    fn list_range(&self, _: &str, _: i64, _: i64) -> Result<Vec<Value>, QueueError> {
        Ok(vec![])
    }
    fn remove(&self, _: &str) -> Result<bool, QueueError> {
        Ok(true)
    }
    fn set(&self, _: &str, _: &str, _: i64) -> Result<bool, QueueError> {
        Ok(true)
    }
    fn get(&self, _: &str) -> Result<Option<StoredValue>, QueueError> {
        Ok(None)
    }
    fn set_array(&self, _: &str, _: &Value, _: i64) -> Result<bool, QueueError> {
        Ok(true)
    }
    fn increment(&self, _: &str) -> Result<i64, QueueError> {
        Ok(1)
    }
    fn decrement(&self, _: &str) -> Result<i64, QueueError> {
        Ok(0)
    }
    fn ping(&self) -> Result<bool, QueueError> {
        Err(QueueError::Other("boom".into()))
    }
    fn close(&self) {}
}

fn locking_with(recorder: std::sync::Arc<Recorder>) -> Locking<RecordingLock> {
    Locking::from_parts(
        std::sync::Arc::new(RecordingConnection {
            recorder: recorder.clone(),
        }),
        RecordingLock { recorder },
    )
}

#[test]
fn lock_is_acquired_with_wait_forever_timeout() {
    let recorder = std::sync::Arc::new(Recorder::new());
    let locking = locking_with(recorder.clone());
    locking.ping().unwrap();
    assert_eq!(*recorder.last_timeout.lock(), Some(-1.0));
}

#[test]
fn lock_is_released_when_inner_command_throws() {
    let recorder = std::sync::Arc::new(Recorder::new());
    let locking = Locking::from_parts(
        std::sync::Arc::new(ThrowingConnection),
        RecordingLock {
            recorder: recorder.clone(),
        },
    );
    let err = locking.ping().unwrap_err();
    assert_eq!(err.to_string(), "boom");
    assert_eq!(recorder.events.lock().as_slice(), ["acquire", "release"]);
}

#[test]
fn operations_are_synchronized() {
    let recorder = std::sync::Arc::new(Recorder::new());
    let locking = locking_with(recorder.clone());
    assert!(locking.right_push_array("queue", &json!({"a": 1})).unwrap());
    assert_eq!(
        recorder.events.lock().as_slice(),
        ["acquire", "rightPushArray", "release"]
    );
}

#[test]
fn remaining_connection_methods_run_inside_lock() {
    let recorder = std::sync::Arc::new(Recorder::new());
    let locking = locking_with(recorder.clone());
    let _ = locking.right_pop_array("queue", 5).unwrap();
    let _ = locking
        .right_pop_left_push_array("queue", "dest", 5)
        .unwrap();
    let _ = locking.left_push_array("queue", &json!({"a": 1})).unwrap();
    let _ = locking.left_pop_array("queue", 5).unwrap();
    let _ = locking.right_push("queue", "value").unwrap();
    let _ = locking.right_pop("queue", 5).unwrap();
    let _ = locking.right_pop_left_push("queue", "dest", 5).unwrap();
    let _ = locking.left_push("queue", "value").unwrap();
    let _ = locking.left_pop("queue", 5).unwrap();
    let _ = locking.list_remove("queue", "key").unwrap();
    let _ = locking.list_size("key").unwrap();
    let _ = locking.list_range("key", 10, 0).unwrap();
    let _ = locking.remove("key").unwrap();
    let _ = locking.set("key", "value", 60).unwrap();
    let _ = locking.get("key").unwrap();
    let _ = locking.set_array("key", &json!({"a": 1}), 60).unwrap();
    let _ = locking.increment("key").unwrap();
    let _ = locking.decrement("key").unwrap();
    locking.close();
    let events = recorder.events.lock();
    assert!(events.contains(&"acquire".to_string()));
    assert!(events.contains(&"release".to_string()));
    assert!(events.contains(&"close".to_string()));
}
