use std::sync::atomic::{AtomicBool, AtomicU32, AtomicU64, Ordering};
use std::sync::Arc;
use std::thread;
use std::time::{Duration, SystemTime, UNIX_EPOCH};

use parking_lot::Mutex;
use serde_json::{json, Value};

use crate::connection::{Connection, StoredValue};
use crate::consumer::Consumer;
use crate::error::QueueError;
use crate::message::Message;
use crate::publisher::Publisher;
use crate::queue::Queue;

const POP_TIMEOUT: i64 = 2;
const RECONNECT_BACKOFF_MS: u64 = 100;
const RECONNECT_MAX_BACKOFF_MS: u64 = 5_000;

/// Redis list broker (processing / failed / dead keys).
///
/// PHP `Utopia\Queue\Broker\Redis`.
#[derive(Clone)]
pub struct Redis {
    receive: Arc<dyn Connection>,
    commands: Arc<dyn Connection>,
    state: Arc<RedisState>,
}

struct RedisState {
    closed: AtomicBool,
    reconnect_attempt: AtomicU32,
    reconnect_backoff_ms: AtomicU64,
    reconnect_callback: Mutex<Option<ReconnectCallback>>,
    reconnect_success_callback: Mutex<Option<ReconnectSuccessCallback>>,
}

/// `(queue, error, attempt, sleep_ms)`
pub type ReconnectCallback = Arc<dyn Fn(&Queue, &QueueError, u32, u64) + Send + Sync>;
/// `(queue, attempts)`
pub type ReconnectSuccessCallback = Arc<dyn Fn(&Queue, u32) + Send + Sync>;

impl std::fmt::Debug for Redis {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Redis")
            .field("closed", &self.state.closed.load(Ordering::Relaxed))
            .finish_non_exhaustive()
    }
}

impl Redis {
    pub fn new(receive: impl Connection + 'static, commands: impl Connection + 'static) -> Self {
        Self::from_arcs(Arc::new(receive), Arc::new(commands))
    }

    pub fn from_arcs(receive: Arc<dyn Connection>, commands: Arc<dyn Connection>) -> Self {
        Self {
            receive,
            commands,
            state: Arc::new(RedisState {
                closed: AtomicBool::new(false),
                reconnect_attempt: AtomicU32::new(0),
                reconnect_backoff_ms: AtomicU64::new(RECONNECT_BACKOFF_MS),
                reconnect_callback: Mutex::new(None),
                reconnect_success_callback: Mutex::new(None),
            }),
        }
    }

    pub fn set_reconnect_callback(&self, callback: Option<ReconnectCallback>) -> &Self {
        *self.state.reconnect_callback.lock() = callback;
        self
    }

    pub fn set_reconnect_success_callback(
        &self,
        callback: Option<ReconnectSuccessCallback>,
    ) -> &Self {
        *self.state.reconnect_success_callback.lock() = callback;
        self
    }

    fn is_closed(&self) -> bool {
        self.state.closed.load(Ordering::SeqCst)
    }

    fn trigger_reconnect(&self, queue: &Queue, error: &QueueError, attempt: u32, sleep_ms: u64) {
        if let Some(cb) = self.state.reconnect_callback.lock().clone() {
            cb(queue, error, attempt, sleep_ms);
        }
    }

    fn trigger_reconnect_success(&self, queue: &Queue, attempts: u32) {
        if let Some(cb) = self.state.reconnect_success_callback.lock().clone() {
            cb(queue, attempts);
        }
    }

    fn get_job(&self, queue: &Queue, pid: &str) -> Result<Option<Message>, QueueError> {
        let key = format!("{}.jobs.{}.{}", queue.namespace, queue.name, pid);
        let value = self.commands.get(&key)?;
        let json = match value {
            Some(StoredValue::Array(v)) => v,
            Some(StoredValue::String(s)) => {
                serde_json::from_str(&s).map_err(|e| QueueError::Other(e.to_string()))?
            }
            None => return Ok(None),
        };
        if json.is_object() {
            Ok(Some(Message::from_value(&json)))
        } else {
            Ok(None)
        }
    }

    fn requeue(&self, queue: &Queue, job: &Message) -> Result<(), QueueError> {
        let payload = json!({
            "pid": uniqid(),
            "queue": queue.name,
            "timestamp": unix_now(),
            "payload": job.get_payload(),
            "attempts": job.get_attempts() + 1,
        });
        self.commands
            .left_push_array(&queue.key("queue"), &payload)?;
        Ok(())
    }
}

impl Publisher for Redis {
    fn enqueue(&self, queue: &Queue, payload: Value, priority: bool) -> Result<bool, QueueError> {
        let envelope = json!({
            "pid": uniqid(),
            "queue": queue.name,
            "timestamp": unix_now(),
            "payload": payload,
        });
        let key = queue.key("queue");
        if priority {
            self.commands.right_push_array(&key, &envelope)
        } else {
            self.commands.left_push_array(&key, &envelope)
        }
    }

    fn retry(
        &self,
        queue: &Queue,
        limit: Option<i64>,
        max_attempts: Option<i64>,
        newer_than: Option<i64>,
    ) -> Result<(), QueueError> {
        let start = unix_now();
        let mut processed = 0i64;
        loop {
            if limit.is_some_and(|l| processed >= l) {
                break;
            }
            let pid = match self.commands.right_pop(&queue.key("failed"), POP_TIMEOUT)? {
                Some(pid) => pid,
                None => break,
            };
            let Some(job) = self.get_job(queue, &pid)? else {
                continue;
            };
            if job.get_timestamp() >= start {
                self.commands.right_push(&queue.key("failed"), &pid)?;
                break;
            }
            if max_attempts.is_some_and(|m| job.get_attempts() >= m)
                || newer_than.is_some_and(|n| job.get_timestamp() < start - n)
            {
                self.commands.left_push(&queue.key("dead"), &pid)?;
                continue;
            }
            self.requeue(queue, &job)?;
            processed += 1;
        }
        Ok(())
    }

    fn get_queue_size(&self, queue: &Queue, failed_jobs: bool) -> Result<i64, QueueError> {
        let key = if failed_jobs {
            queue.key("failed")
        } else {
            queue.key("queue")
        };
        self.commands.list_size(&key)
    }

    fn reap(
        &self,
        queue: &Queue,
        older_than: i64,
        limit: Option<i64>,
        max_attempts: Option<i64>,
        newer_than: Option<i64>,
    ) -> Result<i64, QueueError> {
        let processing_list = queue.key("processing");
        let now = unix_now();
        let cutoff = now - older_than;
        let mut requeued = 0i64;
        let size = self.commands.list_size(&processing_list)?;
        let claims = self.commands.list_range(&processing_list, size, 0)?;

        for pid_val in claims {
            if limit.is_some_and(|l| requeued >= l) {
                break;
            }
            let Some(pid) = pid_val.as_str() else {
                continue;
            };
            let Some(job) = self.get_job(queue, pid)? else {
                self.commands.list_remove(&processing_list, pid)?;
                continue;
            };
            if job.get_timestamp() > cutoff {
                continue;
            }
            if max_attempts.is_some_and(|m| job.get_attempts() >= m)
                || newer_than.is_some_and(|n| job.get_timestamp() < now - n)
            {
                self.commands.list_remove(&processing_list, pid)?;
                self.commands.left_push(&queue.key("dead"), pid)?;
                continue;
            }
            self.requeue(queue, &job)?;
            self.commands.list_remove(&processing_list, pid)?;
            requeued += 1;
        }
        Ok(requeued)
    }
}

impl Consumer for Redis {
    fn receive(&self, queue: &Queue, timeout: i64) -> Result<Option<Message>, QueueError> {
        if self.is_closed() {
            return Ok(None);
        }
        let next = match self.receive.right_pop_array(&queue.key("queue"), timeout) {
            Ok(v) => {
                let attempt = self.state.reconnect_attempt.load(Ordering::SeqCst);
                if attempt > 0 {
                    self.trigger_reconnect_success(queue, attempt);
                }
                self.state
                    .reconnect_backoff_ms
                    .store(RECONNECT_BACKOFF_MS, Ordering::SeqCst);
                self.state.reconnect_attempt.store(0, Ordering::SeqCst);
                v
            }
            Err(e) if e.is_redis() => {
                if self.is_closed() {
                    return Ok(None);
                }
                let attempt = self.state.reconnect_attempt.fetch_add(1, Ordering::SeqCst) + 1;
                self.receive.close();
                let cap = self.state.reconnect_backoff_ms.load(Ordering::SeqCst);
                let sleep_ms = jitter(cap);
                self.trigger_reconnect(queue, &e, attempt, sleep_ms);
                thread::sleep(Duration::from_millis(sleep_ms));
                let next_backoff = RECONNECT_MAX_BACKOFF_MS.min(cap.saturating_mul(2));
                self.state
                    .reconnect_backoff_ms
                    .store(next_backoff, Ordering::SeqCst);
                return Ok(None);
            }
            Err(e) => return Err(e),
        };

        let Some(mut next_message) = next else {
            return Ok(None);
        };
        if let Some(ts) = next_message.get("timestamp").cloned() {
            let ts_i = match ts {
                Value::Number(n) => n.as_i64().unwrap_or(0),
                Value::String(s) => s.parse().unwrap_or(0),
                _ => 0,
            };
            next_message["timestamp"] = json!(ts_i);
        }
        let message = Message::from_value(&next_message);
        let pid = message.get_pid().to_owned();
        let job_key = format!("{}.jobs.{}.{}", queue.namespace, queue.name, pid);
        self.receive
            .set_array(&job_key, &next_message, queue.job_ttl)?;
        self.receive.left_push(&queue.key("processing"), &pid)?;
        self.receive
            .increment(&format!("{}.stats.{}.total", queue.namespace, queue.name))?;
        self.receive.increment(&format!(
            "{}.stats.{}.processing",
            queue.namespace, queue.name
        ))?;
        Ok(Some(message))
    }

    fn commit(&self, queue: &Queue, message: &Message) -> Result<(), QueueError> {
        let pid = message.get_pid();
        self.commands
            .remove(&format!("{}.jobs.{}.{}", queue.namespace, queue.name, pid))?;
        self.commands
            .increment(&format!("{}.stats.{}.success", queue.namespace, queue.name))?;
        self.commands.list_remove(&queue.key("processing"), pid)?;
        self.commands.decrement(&format!(
            "{}.stats.{}.processing",
            queue.namespace, queue.name
        ))?;
        Ok(())
    }

    fn reject(&self, queue: &Queue, message: &Message) -> Result<(), QueueError> {
        let pid = message.get_pid();
        self.commands.left_push(&queue.key("failed"), pid)?;
        self.commands
            .increment(&format!("{}.stats.{}.failed", queue.namespace, queue.name))?;
        self.commands.list_remove(&queue.key("processing"), pid)?;
        self.commands.decrement(&format!(
            "{}.stats.{}.processing",
            queue.namespace, queue.name
        ))?;
        Ok(())
    }

    fn close(&self) {
        self.state.closed.store(true, Ordering::SeqCst);
    }

    fn as_publisher(&self) -> Option<&dyn Publisher> {
        Some(self)
    }
}

pub(crate) fn unix_now() -> i64 {
    SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .map(|d| d.as_secs() as i64)
        .unwrap_or(0)
}

pub(crate) fn unix_now_f64() -> f64 {
    SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .map(|d| d.as_secs_f64())
        .unwrap_or(0.0)
}

/// PHP `uniqid(more_entropy: true)`-shaped id.
pub(crate) fn uniqid() -> String {
    static COUNTER: AtomicU64 = AtomicU64::new(1);
    let now = SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .unwrap_or_default();
    let n = COUNTER.fetch_add(1, Ordering::Relaxed);
    format!(
        "{:08x}{:05x}.{:08x}",
        now.as_secs(),
        now.subsec_micros() % 0x1_00000,
        n as u32
    )
}

fn jitter(max_inclusive: u64) -> u64 {
    if max_inclusive == 0 {
        return 0;
    }
    let nanos = SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .map(|d| u64::from(d.subsec_nanos()))
        .unwrap_or(1);
    nanos % (max_inclusive + 1)
}
