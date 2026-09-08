//! PHP `ServerTelemetryTest` + validation + hook coverage.

use std::sync::{Arc, Mutex};

use serde_json::json;
use utopia_di::Resource;
use utopia_queue::adapter::KubernetesJob;
use utopia_queue::broker::Redis;
use utopia_queue::prelude::*;
use utopia_queue::{Message, QueueError};
use utopia_telemetry::TestAdapter as TestTelemetry;
use utopia_validators::Text;

fn now() -> i64 {
    std::time::SystemTime::now()
        .duration_since(std::time::UNIX_EPOCH)
        .unwrap()
        .as_secs() as i64
}

struct SeqConsumer {
    messages: Mutex<Vec<Message>>,
}

impl SeqConsumer {
    fn new(messages: Vec<Message>) -> Self {
        Self {
            messages: Mutex::new(messages),
        }
    }
}

impl Consumer for SeqConsumer {
    fn receive(&self, _queue: &Queue, _timeout: i64) -> Result<Option<Message>, QueueError> {
        let mut msgs = self.messages.lock().unwrap();
        if msgs.is_empty() {
            Ok(None)
        } else {
            Ok(Some(msgs.remove(0)))
        }
    }
    fn commit(&self, _queue: &Queue, _message: &Message) -> Result<(), QueueError> {
        Ok(())
    }
    fn reject(&self, _queue: &Queue, _message: &Message) -> Result<(), QueueError> {
        Ok(())
    }
    fn close(&self) {}
}

struct SizeConsumer {
    inner: SeqConsumer,
    sizes: Mutex<Vec<i64>>,
}

impl SizeConsumer {
    fn new(sizes: Vec<i64>) -> Self {
        Self {
            inner: SeqConsumer::new(vec![Message::from_value(&json!({
                "pid": "test-pid",
                "queue": "emails",
                "timestamp": now() - 1,
                "payload": {},
            }))]),
            sizes: Mutex::new(sizes),
        }
    }
}

impl Consumer for SizeConsumer {
    fn receive(&self, queue: &Queue, timeout: i64) -> Result<Option<Message>, QueueError> {
        self.inner.receive(queue, timeout)
    }
    fn commit(&self, q: &Queue, m: &Message) -> Result<(), QueueError> {
        self.inner.commit(q, m)
    }
    fn reject(&self, q: &Queue, m: &Message) -> Result<(), QueueError> {
        self.inner.reject(q, m)
    }
    fn close(&self) {
        self.inner.close();
    }
    fn as_publisher(&self) -> Option<&dyn Publisher> {
        Some(self)
    }
}

impl Publisher for SizeConsumer {
    fn enqueue(&self, _q: &Queue, _p: serde_json::Value, _pr: bool) -> Result<bool, QueueError> {
        Ok(true)
    }
    fn retry(
        &self,
        _q: &Queue,
        _l: Option<i64>,
        _m: Option<i64>,
        _n: Option<i64>,
    ) -> Result<(), QueueError> {
        Ok(())
    }
    fn get_queue_size(&self, _q: &Queue, _failed: bool) -> Result<i64, QueueError> {
        let mut sizes = self.sizes.lock().unwrap();
        if sizes.is_empty() {
            Ok(0)
        } else {
            Ok(sizes.remove(0))
        }
    }
}

struct FailingSizeConsumer {
    inner: SeqConsumer,
}

impl FailingSizeConsumer {
    fn new() -> Self {
        Self {
            inner: SeqConsumer::new(vec![Message::from_value(&json!({
                "pid": "test-pid",
                "queue": "emails",
                "timestamp": now() - 1,
                "payload": {},
            }))]),
        }
    }
}

impl Consumer for FailingSizeConsumer {
    fn receive(&self, queue: &Queue, timeout: i64) -> Result<Option<Message>, QueueError> {
        self.inner.receive(queue, timeout)
    }
    fn commit(&self, q: &Queue, m: &Message) -> Result<(), QueueError> {
        self.inner.commit(q, m)
    }
    fn reject(&self, q: &Queue, m: &Message) -> Result<(), QueueError> {
        self.inner.reject(q, m)
    }
    fn close(&self) {
        self.inner.close();
    }
    fn as_publisher(&self) -> Option<&dyn Publisher> {
        Some(self)
    }
}

impl Publisher for FailingSizeConsumer {
    fn enqueue(&self, _q: &Queue, _p: serde_json::Value, _pr: bool) -> Result<bool, QueueError> {
        Ok(true)
    }
    fn retry(
        &self,
        _q: &Queue,
        _l: Option<i64>,
        _m: Option<i64>,
        _n: Option<i64>,
    ) -> Result<(), QueueError> {
        Ok(())
    }
    fn get_queue_size(&self, _q: &Queue, _failed: bool) -> Result<i64, QueueError> {
        Err(QueueError::Other("Queue size unavailable.".into()))
    }
}

#[test]
fn records_job_wait_time_as_monotonic_when_publisher_clock_runs_ahead() {
    let consumer = SeqConsumer::new(vec![
        Message::from_value(&json!({
            "pid": "skewed-pid",
            "queue": "emails",
            "timestamp": now() + 60,
            "payload": {},
        })),
        Message::from_value(&json!({
            "pid": "normal-pid",
            "queue": "emails",
            "timestamp": now() - 1,
            "payload": {},
        })),
    ]);
    let adapter = KubernetesJob::new_full(
        Arc::new(consumer),
        1,
        "emails",
        "appwrite",
        utopia_di::Container::new(),
    )
    .unwrap();
    let telemetry = TestTelemetry::new();
    let mut server = Server::new(adapter);
    server.set_telemetry(&telemetry);
    server.job().inject("message").unwrap().action(|args| {
        let _ = args.message()?;
        Ok(())
    });
    server.start().unwrap();

    let values: Vec<f64> = telemetry
        .histogram_measurements("messaging.process.wait.duration")
        .into_iter()
        .map(|m| m.value)
        .collect();
    assert_eq!(values.len(), 2);
    assert!(values[0] <= f64::EPSILON);
    assert!(values[1] > 0.0);
    let mut sum = 0.0;
    for v in values {
        let previous = sum;
        sum += v;
        assert!(sum >= previous);
    }
}

#[test]
fn records_queue_depth() {
    let consumer = SizeConsumer::new(vec![3, 2]);
    let adapter = KubernetesJob::new_full(
        Arc::new(consumer),
        1,
        "emails",
        "appwrite",
        utopia_di::Container::new(),
    )
    .unwrap();
    let telemetry = TestTelemetry::new();
    let mut server = Server::new(adapter);
    server.set_telemetry(&telemetry);
    server.job().inject("message").unwrap().action(|_| Ok(()));
    server.start().unwrap();
    let first = server.observe_queue_depth();
    assert_eq!(first[0].0 as i64, 3);
    let second = server.observe_queue_depth();
    assert_eq!(second[0].0 as i64, 2);
}

#[test]
fn skips_queue_depth_when_consumer_cannot_report_size() {
    let consumer = SeqConsumer::new(vec![Message::from_value(&json!({
        "pid": "test-pid",
        "queue": "emails",
        "timestamp": now() - 1,
        "payload": {},
    }))]);
    let adapter = KubernetesJob::new(consumer, 1, "emails").unwrap();
    let telemetry = TestTelemetry::new();
    let mut server = Server::new(adapter);
    server.set_telemetry(&telemetry);
    server.job().action(|_| Ok(()));
    server.start().unwrap();
    assert!(server.observe_queue_depth().is_empty());
}

#[test]
fn skips_queue_depth_when_consumer_cannot_read_size() {
    let consumer = FailingSizeConsumer::new();
    let adapter = KubernetesJob::new(consumer, 1, "emails").unwrap();
    let telemetry = TestTelemetry::new();
    let mut server = Server::new(adapter);
    server.set_telemetry(&telemetry);
    server.job().action(|_| Ok(()));
    server.start().unwrap();
    assert!(server.observe_queue_depth().is_empty());
    assert!(telemetry
        .counter_measurements("messaging.queue.depth.errors")
        .is_empty());
}

#[test]
fn injects_adapter_resources_and_context() {
    let consumer = SeqConsumer::new(vec![Message::from_value(&json!({
        "pid": "test-pid",
        "queue": "emails",
        "timestamp": now() - 1,
        "payload": {},
    }))]);
    let adapter = KubernetesJob::new(consumer, 1, "emails").unwrap();
    let mut server = Server::new(adapter);
    let injections = Arc::new(Mutex::new(Vec::new()));
    server
        .resources()
        .set("resourceValue", || Ok(Resource::string("resource")));

    server.init().inject("message").unwrap().action(|args| {
        let message = args.message()?;
        args.container().set("contextValue", {
            let pid = message.get_pid().to_owned();
            move || Ok(Resource::string(pid.clone()))
        });
        Ok(())
    });

    let captured = injections.clone();
    server
        .job()
        .inject("message")
        .unwrap()
        .inject("resourceValue")
        .unwrap()
        .inject("contextValue")
        .unwrap()
        .action(move |args| {
            let message = args.message()?;
            let resource: String = args.inject("resourceValue")?;
            let context: String = args.inject("contextValue")?;
            *captured.lock().unwrap() = vec![message.get_pid().to_owned(), resource, context];
            Ok(())
        });

    server.start().unwrap();
    assert_eq!(
        *injections.lock().unwrap(),
        vec!["test-pid", "resource", "test-pid"]
    );
}

#[test]
fn context_does_not_leak_between_messages() {
    let consumer = SeqConsumer::new(vec![
        Message::from_value(&json!({
            "pid": "first-pid",
            "queue": "emails",
            "timestamp": now() - 1,
            "payload": {},
        })),
        Message::from_value(&json!({
            "pid": "second-pid",
            "queue": "emails",
            "timestamp": now() - 1,
            "payload": {},
        })),
    ]);
    let adapter = KubernetesJob::new(consumer, 1, "emails").unwrap();
    let mut server = Server::new(adapter);
    let context_values = Arc::new(Mutex::new(Vec::new()));

    server.init().inject("message").unwrap().action(|args| {
        let message = args.message()?;
        if message.get_pid() == "first-pid" {
            args.container().set("contextValue", {
                let pid = message.get_pid().to_owned();
                move || Ok(Resource::string(pid.clone()))
            });
        }
        Ok(())
    });

    let captured = context_values.clone();
    server.job().action(move |args| {
        let value = if args.container().has("contextValue") {
            Some(args.inject::<String>("contextValue")?)
        } else {
            None
        };
        captured.lock().unwrap().push(value);
        Ok(())
    });

    server.start().unwrap();
    assert_eq!(
        *context_values.lock().unwrap(),
        vec![Some("first-pid".into()), None]
    );
}

#[test]
fn validation_rejects_invalid_and_missing_required() {
    let connection = InMemoryConnection::new().with_empty_yield(std::time::Duration::ZERO);
    let broker = Redis::new(connection.clone(), connection);
    let queue = Queue::new("validate").unwrap();
    broker
        .enqueue(&queue, json!({"name": "too-long-name-for-limit"}), false)
        .unwrap();
    broker.enqueue(&queue, json!({}), false).unwrap();

    let errors = Arc::new(Mutex::new(Vec::new()));
    let adapter = KubernetesJob::new(broker, 1, "validate").unwrap();
    let mut server = Server::new(adapter);
    server
        .job()
        .param("name", json!(""), Text::new(3), "name", false)
        .action(|_| Ok(()));
    let captured = errors.clone();
    server.error().inject("error").unwrap().action(move |args| {
        captured.lock().unwrap().push(args.error()?.to_string());
        Ok(())
    });
    server.start().unwrap();
    let errs = errors.lock().unwrap().clone();
    assert!(errs.iter().any(|e| e.starts_with("Invalid name:")));
    assert!(errs.iter().any(|e| e == "Param name is not optional."));
}

#[test]
fn param_aliases_resolve() {
    let connection = InMemoryConnection::new().with_empty_yield(std::time::Duration::ZERO);
    let broker = Redis::new(connection.clone(), connection);
    let queue = Queue::new("alias").unwrap();
    broker
        .enqueue(&queue, json!({"alias_value": "first-alias"}), false)
        .unwrap();
    broker
        .enqueue(
            &queue,
            json!({"aliasValue": "canonical", "alias_value": "should-lose"}),
            false,
        )
        .unwrap();

    let seen = Arc::new(Mutex::new(Vec::new()));
    let adapter = KubernetesJob::new(broker, 1, "alias").unwrap();
    let mut server = Server::new(adapter);
    let captured = seen.clone();
    server
        .job()
        .param_full(
            "aliasValue",
            json!(""),
            Text::new(255).with_min(0),
            "alias",
            true,
            Vec::new(),
            false,
            false,
            "",
            vec!["alias_value".into(), "aliased".into()],
            None,
        )
        .action(move |args| {
            captured
                .lock()
                .unwrap()
                .push(args.param("aliasValue").cloned());
            Ok(())
        });
    server.start().unwrap();
    let values = seen.lock().unwrap().clone();
    assert_eq!(values[0], Some(json!("first-alias")));
    assert_eq!(values[1], Some(json!("canonical")));
}
