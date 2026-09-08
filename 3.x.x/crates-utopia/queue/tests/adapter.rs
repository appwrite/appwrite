//! PHP `KubernetesJobAdapterTest` + `ConsumerResilienceTest` + `report_unreported`.

use std::sync::atomic::{AtomicU32, Ordering};
use std::sync::{Arc, Mutex};

use serde_json::json;
use utopia_queue::adapter::{Adapter, BufferTrace, MessageCallback};
use utopia_queue::broker::Redis;
use utopia_queue::prelude::*;
use utopia_queue::{Message, QueueError};

#[test]
fn drains_queue_then_returns() {
    let connection = InMemoryConnection::new().with_empty_yield(std::time::Duration::ZERO);
    let broker = Redis::new(connection.clone(), connection);
    let queue = Queue::with_namespace("keda-unit", "tests").unwrap();
    for n in 1..=5 {
        broker.enqueue(&queue, json!({"n": n}), false).unwrap();
    }
    let processed = Arc::new(Mutex::new(Vec::new()));
    let captured = processed.clone();
    let adapter = KubernetesJob::new_full(
        Arc::new(broker.clone()),
        1,
        "keda-unit",
        "tests",
        utopia_di::Container::new(),
    )
    .unwrap();
    let mut server = Server::new(adapter);
    server.job().inject("message").unwrap().action(move |args| {
        captured
            .lock()
            .unwrap()
            .push(args.message()?.get_payload()["n"].as_i64().unwrap());
        Ok(())
    });
    server.start().unwrap();
    assert_eq!(*processed.lock().unwrap(), vec![1, 2, 3, 4, 5]);
    assert_eq!(broker.get_queue_size(&queue, false).unwrap(), 0);
}

#[test]
fn returns_immediately_when_queue_empty() {
    let connection = InMemoryConnection::new().with_empty_yield(std::time::Duration::ZERO);
    let broker = Redis::new(connection.clone(), connection);
    let processed = Arc::new(AtomicU32::new(0));
    let captured = processed.clone();
    let adapter = KubernetesJob::new_full(
        Arc::new(broker),
        1,
        "keda-unit",
        "tests",
        utopia_di::Container::new(),
    )
    .unwrap();
    let mut server = Server::new(adapter);
    server.job().action(move |_| {
        captured.fetch_add(1, Ordering::SeqCst);
        Ok(())
    });
    server.start().unwrap();
    assert_eq!(processed.load(Ordering::SeqCst), 0);
}

#[test]
fn failed_message_is_rejected_and_drain_continues() {
    let connection = InMemoryConnection::new().with_empty_yield(std::time::Duration::ZERO);
    let broker = Redis::new(connection.clone(), connection);
    let queue = Queue::with_namespace("keda-unit", "tests").unwrap();
    broker.enqueue(&queue, json!({"ok": false}), false).unwrap();
    broker.enqueue(&queue, json!({"ok": true}), false).unwrap();
    let succeeded = Arc::new(AtomicU32::new(0));
    let captured = succeeded.clone();
    let adapter = KubernetesJob::new_full(
        Arc::new(broker.clone()),
        1,
        "keda-unit",
        "tests",
        utopia_di::Container::new(),
    )
    .unwrap();
    let mut server = Server::new(adapter);
    server.job().inject("message").unwrap().action(move |args| {
        if args.message()?.get_payload()["ok"] == json!(false) {
            return Err(QueueError::Other("boom".into()));
        }
        captured.fetch_add(1, Ordering::SeqCst);
        Ok(())
    });
    server.start().unwrap();
    assert_eq!(succeeded.load(Ordering::SeqCst), 1);
    assert_eq!(broker.get_queue_size(&queue, false).unwrap(), 0);
    assert_eq!(broker.get_queue_size(&queue, true).unwrap(), 1);
}

struct Flaky {
    inner: Redis,
    failures: AtomicU32,
}

impl Consumer for Flaky {
    fn receive(&self, queue: &Queue, timeout: i64) -> Result<Option<Message>, QueueError> {
        if self.failures.load(Ordering::SeqCst) < 2 {
            self.failures.fetch_add(1, Ordering::SeqCst);
            return Err(QueueError::Other("broker unreachable".into()));
        }
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
}

#[test]
fn consume_survives_broker_failures_and_resumes() {
    let connection = InMemoryConnection::new().with_empty_yield(std::time::Duration::ZERO);
    let broker = Redis::new(connection.clone(), connection);
    let queue = Queue::with_namespace("resilience", "tests").unwrap();
    broker.enqueue(&queue, json!({"n": 1}), false).unwrap();

    let flaky = Flaky {
        inner: broker,
        failures: AtomicU32::new(0),
    };
    let failures = Arc::new(AtomicU32::new(0));
    // copy pointer via the struct's atomic after consume - we'll read flaky.failures after
    let adapter = Swoole::new_full(
        Arc::new(flaky),
        1,
        "resilience",
        "tests",
        1,
        utopia_di::Container::new(),
    )
    .unwrap();
    adapter.host().set_receive_backoff(0);

    let processed = Arc::new(AtomicU32::new(0));
    let reported = Arc::new(Mutex::new(Vec::new()));
    let reported_msgs = Arc::new(Mutex::new(Vec::new()));

    let p = processed.clone();
    let adapter_stop = adapter.clone();
    let r = reported.clone();
    let rm = reported_msgs.clone();

    adapter.consume(
        Arc::new(move |_m| {
            p.fetch_add(1, Ordering::SeqCst);
            adapter_stop.stop().unwrap();
            Ok(())
        }),
        Arc::new(|_m| Ok(())),
        Arc::new(move |message, error| {
            r.lock().unwrap().push(error.to_string());
            rm.lock()
                .unwrap()
                .push(message.map(|m| m.get_pid().to_owned()));
            Ok(())
        }),
    );

    assert_eq!(
        *reported.lock().unwrap(),
        vec!["broker unreachable", "broker unreachable"]
    );
    assert_eq!(*reported_msgs.lock().unwrap(), vec![None, None]);
    assert_eq!(processed.load(Ordering::SeqCst), 1);
    let _ = failures;
}

#[test]
fn a_failed_error_report_still_leaves_a_trace() {
    let connection = InMemoryConnection::new().with_empty_yield(std::time::Duration::ZERO);
    let broker = Redis::new(connection.clone(), connection);
    let queue = Queue::with_namespace("resilience", "tests").unwrap();
    broker.enqueue(&queue, json!({"n": 1}), false).unwrap();

    let adapter = KubernetesJob::new_full(
        Arc::new(broker.clone()),
        1,
        "resilience",
        "tests",
        utopia_di::Container::new(),
    )
    .unwrap();
    let sink = BufferTrace::new();
    adapter.host().set_trace_sink(Arc::new(sink.clone()));

    let message = adapter
        .consumer()
        .receive(adapter.queue(), 0)
        .unwrap()
        .unwrap();
    fn fail_handler(_m: &Message) -> Result<(), QueueError> {
        Err(QueueError::Other("the database is gone".into()))
    }
    fn fail_report(_m: Option<&Message>, _e: &QueueError) -> Result<(), QueueError> {
        Err(QueueError::Other("reporting needs the database too".into()))
    }
    #[allow(clippy::unnecessary_wraps)]
    fn ok_success(_m: &Message) -> Result<(), QueueError> {
        Ok(())
    }
    let msg_cb: MessageCallback = Arc::new(fail_handler);
    let ok: utopia_queue::adapter::SuccessCallback = Arc::new(ok_success);
    let err: utopia_queue::adapter::ErrorCallback = Arc::new(fail_report);
    adapter.host().process(&message, &msg_cb, &ok, &err);

    let trace = sink.contents();
    assert!(trace.contains("the database is gone"));
    assert!(trace.contains("reporting needs the database too"));
    assert_eq!(broker.get_queue_size(&queue, true).unwrap(), 1);
}

#[test]
fn tokio_concurrency_is_bounded() {
    let connection = InMemoryConnection::new().with_empty_yield(std::time::Duration::ZERO);
    let broker = Redis::new(connection.clone(), connection);
    let queue = Queue::with_namespace("concurrency", "tests").unwrap();
    for i in 0..9 {
        broker.enqueue(&queue, json!({"n": i}), false).unwrap();
    }

    let adapter = Swoole::new_full(
        Arc::new(broker),
        1,
        "concurrency",
        "tests",
        3,
        utopia_di::Container::new(),
    )
    .unwrap();

    let active = Arc::new(AtomicU32::new(0));
    let max_active = Arc::new(AtomicU32::new(0));
    let processed = Arc::new(AtomicU32::new(0));
    let a = active.clone();
    let m = max_active.clone();
    let p = processed.clone();
    let stop = adapter.clone();

    adapter.consume(
        Arc::new(move |_msg| {
            let cur = a.fetch_add(1, Ordering::SeqCst) + 1;
            m.fetch_max(cur, Ordering::SeqCst);
            std::thread::sleep(std::time::Duration::from_millis(20));
            a.fetch_sub(1, Ordering::SeqCst);
            if p.fetch_add(1, Ordering::SeqCst) + 1 == 9 {
                stop.stop().unwrap();
            }
            Ok(())
        }),
        Arc::new(|_m| Ok(())),
        Arc::new(|_m, _e| Ok(())),
    );

    assert_eq!(processed.load(Ordering::SeqCst), 9);
    assert_eq!(max_active.load(Ordering::SeqCst), 3);
}

#[test]
fn message_without_free_slot_stays_in_broker() {
    let connection = InMemoryConnection::new().with_empty_yield(std::time::Duration::ZERO);
    let broker = Redis::new(connection.clone(), connection);
    let queue = Queue::with_namespace("concurrency", "tests").unwrap();
    broker.enqueue(&queue, json!({"n": 0}), false).unwrap();
    broker.enqueue(&queue, json!({"n": 1}), false).unwrap();

    let adapter = Swoole::new_full(
        Arc::new(broker.clone()),
        1,
        "concurrency",
        "tests",
        1,
        utopia_di::Container::new(),
    )
    .unwrap();

    let processed = Arc::new(AtomicU32::new(0));
    let pending = Arc::new(Mutex::new(None));
    let p = processed.clone();
    let pend = pending.clone();
    let stop = adapter.clone();
    let broker2 = broker.clone();
    let queue2 = queue.clone();

    adapter.consume(
        Arc::new(move |_msg| {
            if p.load(Ordering::SeqCst) == 0 {
                std::thread::sleep(std::time::Duration::from_millis(100));
                *pend.lock().unwrap() = Some(broker2.get_queue_size(&queue2, false).unwrap());
            }
            if p.fetch_add(1, Ordering::SeqCst) + 1 == 2 {
                stop.stop().unwrap();
            }
            Ok(())
        }),
        Arc::new(|_m| Ok(())),
        Arc::new(|_m, _e| Ok(())),
    );

    assert_eq!(processed.load(Ordering::SeqCst), 2);
    assert_eq!(*pending.lock().unwrap(), Some(1));
}

#[cfg(feature = "redis")]
#[test]
fn live_redis_ping() {
    use utopia_queue::connection::{Connection, Redis};
    let host = std::env::var("REDIS_HOST")
        .ok()
        .filter(|h| !h.is_empty())
        .unwrap_or_else(|| "127.0.0.1".into());
    let port: u16 = std::env::var("REDIS_PORT")
        .ok()
        .and_then(|p| p.parse().ok())
        .unwrap_or(6379);
    let redis = Redis::new(host, port);
    assert!(redis
        .ping()
        .expect("Redis container (docker compose -f docker-compose.test.yml up -d redis)"));
    let key = format!("utopia-queue-e2e-{}", std::process::id());
    redis.set(&key, "ok", 30).unwrap();
    assert_eq!(
        redis
            .get(&key)
            .unwrap()
            .and_then(|v| v.as_str().map(str::to_owned)),
        Some("ok".into())
    );
    redis.remove(&key).unwrap();
}

#[cfg(feature = "nats")]
#[test]
fn live_nats_url_connects() {
    let url = std::env::var("NATS_URL")
        .ok()
        .filter(|u| !u.is_empty())
        .unwrap_or_else(|| "nats://127.0.0.1:4222".into());
    std::net::TcpStream::connect("127.0.0.1:4222")
        .unwrap_or_else(|e| panic!("NATS container required ({url}): {e}"));
}
