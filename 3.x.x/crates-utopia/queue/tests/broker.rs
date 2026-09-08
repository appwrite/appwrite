//! PHP `RedisRecoveryTest` against `InMemoryConnection`.

use serde_json::json;
use utopia_queue::broker::{Pool, Redis};
use utopia_queue::connection::{Connection, StoredValue};
use utopia_queue::prelude::*;
use utopia_queue::Publisher;

fn setup() -> (InMemoryConnection, Redis, Queue) {
    let connection = InMemoryConnection::new().with_empty_yield(std::time::Duration::ZERO);
    let broker = Redis::new(connection.clone(), connection.clone());
    let queue = Queue::with_namespace("recovery", "tests").unwrap();
    (connection, broker, queue)
}

fn processing_size(connection: &InMemoryConnection) -> i64 {
    connection.list_size("tests.processing.recovery").unwrap()
}

fn dead_size(connection: &InMemoryConnection) -> i64 {
    connection.list_size("tests.dead.recovery").unwrap()
}

fn backdate(connection: &InMemoryConnection, pid: &str, seconds: i64) {
    let key = format!("tests.jobs.recovery.{pid}");
    let mut job = connection
        .get(&key)
        .unwrap()
        .and_then(|v| match v {
            StoredValue::Array(v) => Some(v),
            StoredValue::String(s) => serde_json::from_str(&s).ok(),
        })
        .unwrap();
    let ts = job["timestamp"].as_i64().unwrap() - seconds;
    job["timestamp"] = json!(ts);
    connection.set_array(&key, &job, 0).unwrap();
}

#[test]
fn reap_requeues_a_stranded_claim() {
    let (connection, broker, queue) = setup();
    broker.enqueue(&queue, json!({"n": 1}), false).unwrap();
    let claimed = broker.receive(&queue, 0).unwrap().unwrap();
    assert_eq!(processing_size(&connection), 1);

    let requeued = broker.reap(&queue, 0, None, None, None).unwrap();
    assert_eq!(requeued, 1);
    assert_eq!(processing_size(&connection), 0);
    assert_eq!(broker.get_queue_size(&queue, false).unwrap(), 1);

    let retried = broker.receive(&queue, 0).unwrap().unwrap();
    assert_eq!(retried.get_payload()["n"], 1);
    assert_eq!(retried.get_attempts(), 1);
    let _ = claimed;
}

#[test]
fn reap_leaves_claims_younger_than_the_cutoff() {
    let (connection, broker, queue) = setup();
    broker.enqueue(&queue, json!({"n": 1}), false).unwrap();
    broker.receive(&queue, 0).unwrap();
    let requeued = broker.reap(&queue, 3600, None, None, None).unwrap();
    assert_eq!(requeued, 0);
    assert_eq!(processing_size(&connection), 1);
}

#[test]
fn reap_drops_claims_whose_payload_expired() {
    let (connection, broker, queue) = setup();
    broker.enqueue(&queue, json!({"n": 1}), false).unwrap();
    let claimed = broker.receive(&queue, 0).unwrap().unwrap();
    connection
        .remove(&format!("tests.jobs.recovery.{}", claimed.get_pid()))
        .unwrap();
    let requeued = broker.reap(&queue, 0, None, None, None).unwrap();
    assert_eq!(requeued, 0);
    assert_eq!(processing_size(&connection), 0);
    assert_eq!(broker.get_queue_size(&queue, false).unwrap(), 0);
}

#[test]
fn reap_parks_exhausted_claims_on_the_dead_queue() {
    let (connection, broker, queue) = setup();
    broker.enqueue(&queue, json!({"n": 1}), false).unwrap();
    let claimed = broker.receive(&queue, 0).unwrap().unwrap();
    assert_eq!(claimed.get_attempts(), 0);

    for attempt in 1..=2 {
        broker.reap(&queue, 0, None, None, None).unwrap();
        let claimed = broker.receive(&queue, 0).unwrap().unwrap();
        assert_eq!(claimed.get_attempts(), attempt);
    }

    let requeued = broker.reap(&queue, 0, None, Some(2), None).unwrap();
    assert_eq!(requeued, 0);
    assert_eq!(processing_size(&connection), 0);
    assert_eq!(dead_size(&connection), 1);
    assert_eq!(broker.get_queue_size(&queue, false).unwrap(), 0);
}

#[test]
fn retry_requeues_a_rejected_message_with_its_attempt_count() {
    let (connection, broker, queue) = setup();
    broker.enqueue(&queue, json!({"n": 1}), false).unwrap();
    let claimed = broker.receive(&queue, 0).unwrap().unwrap();
    broker.reject(&queue, &claimed).unwrap();
    backdate(&connection, claimed.get_pid(), 60);
    assert_eq!(broker.get_queue_size(&queue, true).unwrap(), 1);

    broker.retry(&queue, None, None, None).unwrap();
    assert_eq!(broker.get_queue_size(&queue, true).unwrap(), 0);
    let retried = broker.receive(&queue, 0).unwrap().unwrap();
    assert_eq!(retried.get_payload()["n"], 1);
    assert_eq!(retried.get_attempts(), 1);
}

#[test]
fn retry_parks_exhausted_messages_on_the_dead_queue() {
    let (connection, broker, queue) = setup();
    broker.enqueue(&queue, json!({"n": 1}), false).unwrap();
    let mut claimed = broker.receive(&queue, 0).unwrap().unwrap();
    claimed.set_attempts(3);
    connection
        .set_array(
            &format!("tests.jobs.recovery.{}", claimed.get_pid()),
            &claimed.as_array(),
            0,
        )
        .unwrap();
    broker.reject(&queue, &claimed).unwrap();
    backdate(&connection, claimed.get_pid(), 60);

    broker.retry(&queue, None, Some(3), None).unwrap();
    assert_eq!(broker.get_queue_size(&queue, false).unwrap(), 0);
    assert_eq!(broker.get_queue_size(&queue, true).unwrap(), 0);
    assert_eq!(dead_size(&connection), 1);
}

#[test]
fn retry_skips_entries_whose_payload_expired() {
    let (connection, broker, queue) = setup();
    broker.enqueue(&queue, json!({"n": 1}), false).unwrap();
    broker.enqueue(&queue, json!({"n": 2}), false).unwrap();
    let first = broker.receive(&queue, 0).unwrap().unwrap();
    let second = broker.receive(&queue, 0).unwrap().unwrap();
    broker.reject(&queue, &first).unwrap();
    broker.reject(&queue, &second).unwrap();
    connection
        .remove(&format!("tests.jobs.recovery.{}", first.get_pid()))
        .unwrap();
    backdate(&connection, second.get_pid(), 60);

    broker.retry(&queue, None, None, None).unwrap();
    assert_eq!(broker.get_queue_size(&queue, true).unwrap(), 0);
    assert_eq!(broker.get_queue_size(&queue, false).unwrap(), 1);
}

#[test]
fn retry_parks_entries_older_than_the_age_gate() {
    let (connection, broker, queue) = setup();
    broker.enqueue(&queue, json!({"n": 1}), false).unwrap();
    let claimed = broker.receive(&queue, 0).unwrap().unwrap();
    broker.reject(&queue, &claimed).unwrap();
    backdate(&connection, claimed.get_pid(), 3600);

    broker.retry(&queue, None, None, Some(600)).unwrap();
    assert_eq!(broker.get_queue_size(&queue, false).unwrap(), 0);
    assert_eq!(broker.get_queue_size(&queue, true).unwrap(), 0);
    assert_eq!(dead_size(&connection), 1);
}

#[test]
fn reap_parks_claims_older_than_the_age_gate() {
    let (connection, broker, queue) = setup();
    broker.enqueue(&queue, json!({"n": 1}), false).unwrap();
    let claimed = broker.receive(&queue, 0).unwrap().unwrap();
    backdate(&connection, claimed.get_pid(), 3600);

    let requeued = broker.reap(&queue, 0, None, None, Some(600)).unwrap();
    assert_eq!(requeued, 0);
    assert_eq!(processing_size(&connection), 0);
    assert_eq!(dead_size(&connection), 1);
}

#[test]
fn priority_job_is_consumed_before_normal_jobs() {
    let connection = InMemoryConnection::new().with_empty_yield(std::time::Duration::ZERO);
    let broker = Redis::new(connection.clone(), connection.clone());
    let queue = Queue::with_namespace("swoole-priority", "utopia-queue").unwrap();
    let key = queue.key("queue");

    broker
        .enqueue(&queue, json!({"order": "normal-1"}), false)
        .unwrap();
    broker
        .enqueue(&queue, json!({"order": "normal-2"}), false)
        .unwrap();
    broker
        .enqueue(&queue, json!({"order": "normal-3"}), false)
        .unwrap();
    broker
        .enqueue(&queue, json!({"order": "priority"}), true)
        .unwrap();

    let first = connection.right_pop_array(&key, 1).unwrap().unwrap();
    assert_eq!(first["payload"]["order"], "priority");
    let second = connection.right_pop_array(&key, 1).unwrap().unwrap();
    assert_eq!(second["payload"]["order"], "normal-1");
    let third = connection.right_pop_array(&key, 1).unwrap().unwrap();
    assert_eq!(third["payload"]["order"], "normal-2");
    let fourth = connection.right_pop_array(&key, 1).unwrap().unwrap();
    assert_eq!(fourth["payload"]["order"], "normal-3");
    assert!(connection.right_pop_array(&key, 1).unwrap().is_none());
}

#[test]
fn pool_delegates_enqueue_and_size() {
    use std::sync::Arc;
    use utopia_queue::pool::ResourcePool;

    let connection = InMemoryConnection::new().with_empty_yield(std::time::Duration::ZERO);
    let inner = Redis::new(connection.clone(), connection);
    let pub_pool = ResourcePool::new("redis", 1, {
        let inner = inner.clone();
        move || {
            let b: Arc<dyn Publisher> = Arc::new(inner.clone());
            b
        }
    });
    let cons_pool = ResourcePool::new("redis-c", 1, {
        let inner = inner.clone();
        move || {
            let b: Arc<dyn Consumer> = Arc::new(inner.clone());
            b
        }
    });
    let pool = Pool::new(Some(Arc::new(pub_pool)), Some(Arc::new(cons_pool)));
    let queue = Queue::new("pool").unwrap();
    assert!(pool.enqueue(&queue, json!({"n": 1}), false).unwrap());
    assert_eq!(pool.get_queue_size(&queue, false).unwrap(), 1);
}
