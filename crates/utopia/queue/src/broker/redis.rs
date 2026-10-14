//! The Redis broker.

use std::collections::HashMap;
use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::{Arc, Mutex};
use std::time::Duration;

use indexmap::IndexMap;
use php_std::format::{Arg, str_to_int};
use php_std::{Array, Value};

use crate::buffer::Buffer;
use crate::codec::{Codec, Json};
use crate::connection::{BoxFuture, Connection, Reply, bulk};
use crate::contract::{Clock, Consumer, Publisher, SystemClock};
use crate::error::{Error, Result};
use crate::message::{Message, envelope};
use crate::queue::Queue;

const POP_TIMEOUT: i64 = 2;
const RECONNECT_BACKOFF_MS: u64 = 100;
const RECONNECT_MAX_BACKOFF_MS: u64 = 5_000;
/// Heartbeat lifetime; handlers refresh it every third of this interval.
const CLAIM_TTL: i64 = 90;
/// Lock expiry schedules the next fleet sweep.
const REAP_INTERVAL: i64 = 60;
/// Maximum claims requeued per sweep.
const REAP_LIMIT: i64 = 1_000;

const RESERVE: &str = include_str!("redis/reserve.lua");
const CLAIM: &str = include_str!("redis/claim.lua");
const SETTLE: &str = include_str!("redis/settle.lua");
const EXTEND: &str = include_str!("redis/extend.lua");
const EXPIRED: &str = include_str!("redis/expired.lua");
const RECOVER: &str = include_str!("redis/recover.lua");
const RECLAIM: &str = include_str!("redis/reclaim.lua");
const ABANDON: &str = include_str!("redis/abandon.lua");

/// Called when a receive fails on the transport: the queue, the error, the
/// attempt number and the milliseconds the broker sleeps before returning.
pub type ReconnectCallback = Box<dyn Fn(&Queue, &Error, i64, i64) + Send + Sync>;
/// Called on the first successful receive after failures, with their count.
pub type ReconnectSuccessCallback = Box<dyn Fn(&Queue, i64) + Send + Sync>;

type Settlement = (Vec<Vec<u8>>, Vec<Vec<u8>>);

/// The Redis broker: publishes onto a list and delivers with at-least-once
/// semantics through Lua scripts that keep every claim recoverable.
///
/// Per queue `<namespace>.<kind>.<name>` keys hold the work list (`queue`),
/// in-flight pids (`processing`), rejected pids (`failed`), parked pids
/// (`dead`), undecodable bytes (`poison`), each claim's payload (`jobs.<pid>`),
/// heartbeat (`claims.<pid>`) and owner (`owners.<pid>`), and counters
/// (`stats.<name>.total|processing|success|failed`).
///
/// It takes two connections: `receive` for the blocking receive loop and its
/// claim writes (one caller), and `commands` for settlements and publishing
/// (wrap it in [`crate::connection::Locking`] when tasks share it).
///
/// ```no_run
/// use std::sync::Arc;
/// use utopia_queue::broker::Redis;
/// use utopia_queue::connection::Redis as Connection;
/// use utopia_queue::{Consumer, Publisher, Queue};
///
/// # async fn run() -> Result<(), utopia_queue::Error> {
/// let broker = Redis::new(Arc::new(Connection::new("localhost", 6379)), Arc::new(Connection::new("localhost", 6379)));
/// let queue = Queue::new("emails")?;
/// broker.publish(&queue, utopia_queue::payload(&serde_json::json!({"to": "ada@example.com"}))?).await?;
/// for message in broker.receive(&queue, 1, 10).await? {
///     broker.commit(&queue, &message).await?;
/// }
/// # Ok(()) }
/// ```
///
/// ```php
/// use Utopia\Queue\Broker\Redis;
/// use Utopia\Queue\Connection\Redis as Connection;
/// use Utopia\Queue\Queue;
///
/// $broker = new Redis(new Connection('redis'), new Connection('redis'));
/// $queue = new Queue('compat-doc-emails');
/// $broker->publish($queue, ['to' => 'ada@example.com']);
/// foreach ($broker->receive($queue, 1, 10) as $message) {
///     $broker->commit($queue, $message);
///     echo $message->getPayload()['to'], "\n"; // ada@example.com
/// }
/// ```
pub struct Redis {
    receive: Arc<dyn Connection>,
    commands: Arc<dyn Connection>,
    codec: Arc<dyn Codec>,
    reap_after: i64,
    clock: Arc<dyn Clock>,
    closed: AtomicBool,
    settlements: Mutex<HashMap<String, Arc<Buffer<Settlement, i64>>>>,
    served: Mutex<IndexMap<(String, String), Queue>>,
    reconnect: Mutex<(i64, u64)>,
    on_reconnect: Option<ReconnectCallback>,
    on_reconnect_success: Option<ReconnectSuccessCallback>,
}

impl Redis {
    /// A broker on two connections, writing JSON envelopes.
    pub fn new(receive: Arc<dyn Connection>, commands: Arc<dyn Connection>) -> Self {
        Self {
            receive,
            commands,
            codec: Arc::new(Json),
            reap_after: 90_000,
            clock: Arc::new(SystemClock),
            closed: AtomicBool::new(false),
            settlements: Mutex::new(HashMap::new()),
            served: Mutex::new(IndexMap::new()),
            reconnect: Mutex::new((0, RECONNECT_BACKOFF_MS)),
            on_reconnect: None,
            on_reconnect_success: None,
        }
    }

    /// How envelopes are written and read; see [`crate::codec::Compat`]
    /// before changing it on a queue that already holds messages.
    pub fn with_codec(mut self, codec: Arc<dyn Codec>) -> Self {
        self.codec = codec;
        self
    }

    /// Seconds since publication before a claim without a live heartbeat
    /// counts as stranded in [`Redis::maintain`] (default 90000).
    pub fn with_reap_after(mut self, seconds: i64) -> Self {
        self.reap_after = seconds;
        self
    }

    /// The clock and id source (tests pin it).
    pub fn with_clock(mut self, clock: Arc<dyn Clock>) -> Self {
        self.clock = clock;
        self
    }

    /// `setReconnectCallback()`: told about each failed receive.
    pub fn on_reconnect(mut self, callback: ReconnectCallback) -> Self {
        self.on_reconnect = Some(callback);
        self
    }

    /// `setReconnectSuccessCallback()`: told when receiving works again.
    pub fn on_reconnect_success(mut self, callback: ReconnectSuccessCallback) -> Self {
        self.on_reconnect_success = Some(callback);
        self
    }

    fn key(queue: &Queue, kind: &str) -> Vec<u8> {
        queue.key(kind).into_bytes()
    }

    fn pid_key(queue: &Queue, kind: &str, pid: &[u8]) -> Vec<u8> {
        let mut k = queue.key(kind).into_bytes();
        k.push(b'.');
        k.extend_from_slice(pid);
        k
    }

    fn stat(queue: &Queue, stat: &str) -> Vec<u8> {
        format!("{}.stats.{}.{}", queue.namespace(), queue.name(), stat).into_bytes()
    }

    fn encode(&self, value: &Value) -> Result<Vec<u8>> {
        self.codec.encode(value)
    }

    /// `receive()`.
    async fn receive_messages(&self, queue: &Queue, timeout: i64, n: i64) -> Result<Vec<Message>> {
        if self.closed.load(Ordering::SeqCst) {
            return Ok(Vec::new());
        }
        if let Ok(mut served) = self.served.lock() {
            served.entry((queue.namespace().to_owned(), queue.name().to_owned())).or_insert_with(|| queue.clone());
        }
        let key = Self::key(queue, "queue");
        let token = self.clock.token();
        let reservation = format!("{}.reservations.{}.{}", queue.namespace(), queue.name(), token).into_bytes();
        let keys = vec![
            key.clone(),
            Self::key(queue, "reservations"),
            reservation.clone(),
            Self::key(queue, "processing"),
            Self::stat(queue, "total"),
            Self::stat(queue, "processing"),
        ];
        let count = REAP_LIMIT.min(n.max(1));
        let attempt = async {
            let args = [count, CLAIM_TTL, timeout.max(0), 0].map(int);
            let mut batch = list(self.receive.execute(RESERVE, &keys, &args).await?);
            if batch.is_empty() && timeout > 0 {
                // The script registered this reservation before blocking: a
                // crash after the move is recoverable.
                if let Some(raw) = self.receive.right_pop_left_push(&key, &reservation, timeout).await? {
                    batch.push(raw);
                    if count > 1 {
                        let args = [count - 1, CLAIM_TTL, 0, 1].map(int);
                        batch.extend(list(self.receive.execute(RESERVE, &keys, &args).await?));
                    }
                }
            }
            Ok::<_, Error>(batch)
        };
        let batch = match attempt.await {
            Ok(batch) => {
                let attempts = self.reconnect.lock().map(|r| r.0).unwrap_or(0);
                if attempts > 0
                    && let Some(cb) = &self.on_reconnect_success
                {
                    cb(queue, attempts);
                }
                if let Ok(mut r) = self.reconnect.lock() {
                    *r = (0, RECONNECT_BACKOFF_MS);
                }
                batch
            }
            Err(e) if e.is_redis() => {
                if self.closed.load(Ordering::SeqCst) {
                    return Ok(Vec::new());
                }
                let (attempt, backoff) = {
                    let mut r = self.reconnect.lock().unwrap_or_else(|e| e.into_inner());
                    r.0 += 1;
                    let current = *r;
                    r.1 = RECONNECT_MAX_BACKOFF_MS.min(r.1 * 2);
                    current
                };
                self.receive.close().await;
                let sleep = crate::connection::jitter(backoff);
                if let Some(cb) = &self.on_reconnect {
                    cb(queue, &e, attempt, sleep as i64);
                }
                tokio::time::sleep(Duration::from_millis(sleep)).await;
                return Ok(Vec::new());
            }
            Err(e) => return Err(e),
        };
        if batch.is_empty() {
            return Ok(Vec::new());
        }
        self.claim(queue, batch, &token, reservation).await
    }

    /// Finalises raw reservations: decodes each, parks what is not an envelope.
    async fn claim(
        &self,
        queue: &Queue,
        batch: Vec<Vec<u8>>,
        token: &str,
        reservation: Vec<u8>,
    ) -> Result<Vec<Message>> {
        let mut keys = vec![
            Self::key(queue, "reservations"),
            reservation,
            Self::key(queue, "processing"),
            Self::stat(queue, "total"),
            Self::stat(queue, "processing"),
            Self::key(queue, "poison"),
        ];
        let mut messages = Vec::with_capacity(batch.len());
        let mut pairs = Vec::with_capacity(batch.len() * 2);
        let mut poison = Vec::new();
        for raw in batch {
            let size = raw.len() as i64;
            Message::warn_if_large(queue.name(), size);
            match self.codec.decode(&raw).ok().and_then(|v| claimed(&v)) {
                Some(mut message) => {
                    message.set_receipt(token).set_size(Some(size));
                    let pid = message.pid().as_bytes().to_vec();
                    keys.push(Self::pid_key(queue, "jobs", &pid));
                    keys.push(Self::pid_key(queue, "claims", &pid));
                    keys.push(Self::pid_key(queue, "owners", &pid));
                    pairs.push(raw);
                    pairs.push(pid);
                    messages.push(message);
                }
                None => poison.push(raw),
            }
        }
        let mut args = vec![int(CLAIM_TTL), token.as_bytes().to_vec(), int(messages.len() as i64)];
        args.extend(pairs);
        args.extend(poison);
        self.receive.execute(CLAIM, &keys, &args).await?;
        Ok(messages)
    }

    fn settlement(&self, namespace: &str) -> Arc<Buffer<Settlement, i64>> {
        let mut map = self.settlements.lock().unwrap_or_else(|e| e.into_inner());
        map.entry(namespace.to_owned())
            .or_insert_with(|| {
                let commands = self.commands.clone();
                Arc::new(Buffer::new(Arc::new(move |requests: Vec<Settlement>| {
                    let commands = commands.clone();
                    Box::pin(async move {
                        let mut keys = Vec::new();
                        let mut args = Vec::new();
                        for (k, a) in requests {
                            keys.extend(k);
                            args.extend(a);
                        }
                        let reply = commands.execute(SETTLE, &keys, &args).await?;
                        Ok(match reply {
                            Reply::Array(items) => items
                                .into_iter()
                                .map(|r| match r {
                                    Reply::Int(i) => Ok(i),
                                    Reply::ServerError(_) => Err(Error::Redis("Queue settlement failed".into())),
                                    _ => Ok(0),
                                })
                                .collect(),
                            _ => Vec::new(),
                        })
                    })
                })))
            })
            .clone()
    }

    async fn settle(&self, queue: &Queue, message: &Message, operation: &str) -> Result<()> {
        let pid = message.pid().as_bytes();
        let outcome = if operation == "commit" { "success" } else { "failed" };
        // A terminal verdict changes nothing here: a rejected message is
        // already out of the way on the failed list, where retry() can reach it.
        let list = if operation == "release" { "queue" } else { "failed" };
        let keys = vec![
            Self::pid_key(queue, "claims", pid),
            Self::pid_key(queue, "jobs", pid),
            Self::key(queue, "processing"),
            Self::stat(queue, "processing"),
            Self::stat(queue, outcome),
            Self::key(queue, list),
            Self::pid_key(queue, "owners", pid),
        ];
        let args = vec![
            message.receipt().unwrap_or("").as_bytes().to_vec(),
            pid.to_vec(),
            operation.as_bytes().to_vec(),
            int(queue.job_ttl()),
        ];
        let result = self.settlement(queue.namespace()).request((keys, args)).await?;
        if result != 1 {
            return Err(Error::Runtime("Queue delivery is no longer owned by this consumer".into()));
        }
        Ok(())
    }

    /// Returns prefetched work that never entered its handler, without
    /// counting a failure: back to the head of the work list, in order.
    pub async fn release_messages(&self, queue: &Queue, messages: &[Message]) -> Result<()> {
        for message in messages.iter().rev() {
            self.settle(queue, message, "release").await?;
        }
        Ok(())
    }

    /// Refreshes the heartbeat of claims this consumer still owns.
    pub async fn extend_messages(&self, queue: &Queue, messages: &[Message]) -> Result<()> {
        for chunk in messages.chunks(REAP_LIMIT as usize) {
            let mut keys = Vec::with_capacity(chunk.len() * 2);
            let mut args = vec![int(CLAIM_TTL)];
            for m in chunk {
                keys.push(Self::pid_key(queue, "claims", m.pid().as_bytes()));
                keys.push(Self::pid_key(queue, "owners", m.pid().as_bytes()));
                args.push(m.receipt().unwrap_or("").as_bytes().to_vec());
            }
            self.commands.execute(EXTEND, &keys, &args).await?;
        }
        Ok(())
    }

    /// How often to extend: a third of the heartbeat lifetime, so two beats can be missed.
    pub fn extend_interval_secs(&self) -> f64 {
        CLAIM_TTL as f64 / 3.0
    }

    /// Recovers stranded claims of every queue this broker has received
    /// from, at most once per fleet-wide interval (a `reap-lock` key).
    pub async fn maintain_queues(&self) -> Result<()> {
        let served: Vec<Queue> = self.served.lock().map(|s| s.values().cloned().collect()).unwrap_or_default();
        for queue in served {
            let lock = Self::key(&queue, "reap-lock");
            if !self.commands.set_not_exists(&lock, self.clock.time().to_string().as_bytes(), REAP_INTERVAL).await? {
                continue;
            }
            let registry = Self::key(&queue, "reservations");
            let expired =
                list(self.commands.execute(EXPIRED, std::slice::from_ref(&registry), &[int(REAP_LIMIT)]).await?);
            if !expired.is_empty() {
                let mut keys = vec![registry, Self::key(&queue, "queue")];
                keys.extend(expired);
                self.commands.execute(RECOVER, &keys, &[int(REAP_LIMIT)]).await?;
            }
            self.reap(&queue, self.reap_after, Some(REAP_LIMIT), None, None, Some(REAP_LIMIT * 2)).await?;
        }
        Ok(())
    }

    /// Takes failed messages and requeues them with their attempt count
    /// incremented, up to `limit`. Messages requeued `max_attempts` times,
    /// or published more than `newer_than` seconds ago, are parked on the
    /// dead list instead. Stops at a message this sweep already requeued.
    pub async fn retry_with(
        &self,
        queue: &Queue,
        limit: Option<i64>,
        max_attempts: Option<i64>,
        newer_than: Option<i64>,
    ) -> Result<()> {
        let start = self.clock.time();
        let failed = Self::key(queue, "failed");
        let mut processed = 0;
        while limit.is_none_or(|l| processed < l) {
            let Some(pid) = self.commands.right_pop(&failed, POP_TIMEOUT).await? else {
                break;
            };
            let Some(job) = self.job(queue, &pid).await? else {
                continue;
            };
            if job.timestamp() >= start {
                self.commands.right_push(&failed, &pid).await?;
                break;
            }
            if max_attempts.is_some_and(|m| job.attempts() >= m)
                || newer_than.is_some_and(|n| job.timestamp() < start - n)
            {
                self.commands.left_push(&Self::key(queue, "dead"), &pid).await?;
                continue;
            }
            let payload = self.retry_payload(queue, &job)?;
            self.commands.left_push(&Self::key(queue, "queue"), &payload).await?;
            processed += 1;
        }
        Ok(())
    }

    /// Recovers claims left between receive and settlement: claims without a
    /// live heartbeat published more than `older_than` seconds ago are
    /// requeued (or parked on the dead list past `max_attempts` or
    /// `newer_than`). `scan` bounds how many claims one call examines, with
    /// progress shared through a cursor key. Returns how many were requeued.
    pub async fn reap(
        &self,
        queue: &Queue,
        older_than: i64,
        limit: Option<i64>,
        max_attempts: Option<i64>,
        newer_than: Option<i64>,
        scan: Option<i64>,
    ) -> Result<i64> {
        let processing = Self::key(queue, "processing");
        let now = self.clock.time();
        let cutoff = now - older_than;
        let mut requeued = 0;
        let size = self.commands.list_size(&processing).await?;
        if size == 0 {
            return Ok(0);
        }
        let cursor_key = Self::key(queue, "reap-cursor");
        let (mut retained, mut remaining) = if scan.is_none() {
            (0, 0)
        } else {
            let raw = self.commands.get(&cursor_key).await?.unwrap_or_default();
            let mut parts = raw.split(|&b| b == b':');
            let first = parts.next().map(str_to_int).unwrap_or(0);
            let second = parts.next().map(str_to_int).unwrap_or(0);
            (first, second)
        };
        if retained < 0 || retained >= size || remaining <= 0 {
            retained = 0;
            remaining = size;
        }
        remaining = remaining.min(size - retained);
        let length = match scan {
            None => size,
            Some(s) => s.max(1).min(remaining),
        };
        let claims = self.commands.list_range(&processing, length, (size - retained - length).max(0)).await?;
        for pid in claims.into_iter().rev() {
            if limit.is_some_and(|l| requeued >= l) {
                break;
            }
            remaining -= 1;
            let owner_key = Self::pid_key(queue, "owners", &pid);
            let claim_key = Self::pid_key(queue, "claims", &pid);
            let job = self.job(queue, &pid).await?;
            let owner = self.commands.get(&owner_key).await?;
            let Some(job) = job else {
                let Some(owner) = owner else {
                    self.commands.list_remove(&processing, &pid).await?;
                    continue;
                };
                // Owned, but nothing left to requeue or park: dropped unless
                // its handler still heartbeats (commit tolerates a missing
                // payload). Failing here would end the sweep before its cursor
                // is saved, so every sweep would stop at the same orphan.
                let keys = vec![
                    owner_key,
                    claim_key,
                    Self::pid_key(queue, "jobs", &pid),
                    processing.clone(),
                    Self::stat(queue, "processing"),
                ];
                let args = vec![owner, pid.clone()];
                if !truthy(&self.commands.execute(ABANDON, &keys, &args).await?) {
                    retained += 1;
                }
                continue;
            };
            if job.timestamp() > cutoff || self.commands.get(&claim_key).await?.is_some() {
                retained += 1;
                continue;
            }
            let dead = max_attempts.is_some_and(|m| job.attempts() >= m)
                || newer_than.is_some_and(|n| job.timestamp() < now - n);
            let keys = vec![
                owner_key,
                claim_key,
                Self::pid_key(queue, "jobs", &pid),
                processing.clone(),
                Self::stat(queue, "processing"),
                Self::key(queue, if dead { "dead" } else { "queue" }),
            ];
            let replacement = if dead { Vec::new() } else { self.retry_payload(queue, &job)? };
            let args = vec![owner.unwrap_or_default(), pid.clone(), replacement, int(queue.job_ttl())];
            let moved = truthy(&self.commands.execute(RECLAIM, &keys, &args).await?);
            if moved && !dead {
                requeued += 1;
            } else if !moved {
                retained += 1;
            }
        }
        if scan.is_some() {
            self.commands.set(&cursor_key, format!("{retained}:{remaining}").as_bytes(), 0).await?;
        }
        Ok(requeued)
    }

    /// A requeue: fresh pid and timestamp, the attempt count carried forward.
    fn retry_payload(&self, queue: &Queue, job: &Message) -> Result<Vec<u8>> {
        let value = envelope(
            &self.clock.uniqid(),
            queue.name(),
            self.clock.time(),
            job.payload().clone(),
            Some(job.attempts() + 1),
        );
        self.encode(&value)
    }

    /// A stored payload, `None` when missing, expired or unreadable.
    async fn job(&self, queue: &Queue, pid: &[u8]) -> Result<Option<Message>> {
        let Some(raw) = self.commands.get(&Self::pid_key(queue, "jobs", pid)).await? else {
            return Ok(None);
        };
        match self.codec.decode(&raw) {
            Ok(Value::Array(a)) => Message::from_envelope(&a).map(Some),
            _ => Ok(None),
        }
    }
}

/// `$envelope['timestamp'] = (int) ...; new Message($envelope)`, or `None`
/// for what is not an envelope (parked as poison).
fn claimed(value: &Value) -> Option<Message> {
    let Value::Array(a) = value else { return None };
    let set = |k: &str| a.get_str(k).is_some_and(|v| !matches!(v, Value::Null));
    if !set("pid") || !set("queue") || !set("timestamp") {
        return None;
    }
    let mut a = a.clone();
    let timestamp = cast_int(a.get_str("timestamp").unwrap_or(&Value::Null));
    a.set("timestamp".into(), Value::Int(timestamp));
    Message::from_envelope(&a).ok()
}

/// PHP's `(int)` cast.
fn cast_int(value: &Value) -> i64 {
    match value {
        Value::Null => 0,
        Value::Bool(b) => i64::from(*b),
        Value::Int(i) => *i,
        Value::Float(f) => php_std::format::dval_to_lval(*f),
        Value::Str(s) => str_to_int(s.as_bytes()),
        Value::Array(a) => i64::from(!a.is_empty()),
        Value::Object(_) | Value::Ext(_) => Arg::Object.to_int(),
    }
}

fn int(i: i64) -> Vec<u8> {
    i.to_string().into_bytes()
}

fn list(reply: Reply) -> Vec<Vec<u8>> {
    match reply {
        Reply::Array(items) => items.into_iter().filter_map(bulk).collect(),
        _ => Vec::new(),
    }
}

fn truthy(reply: &Reply) -> bool {
    match reply {
        Reply::Int(i) => *i != 0,
        Reply::Nil => false,
        Reply::BulkString(b) => !(b.is_empty() || b == b"0"),
        _ => true,
    }
}

impl Publisher for Redis {
    fn publish<'a>(&'a self, queue: &'a Queue, payload: Array) -> BoxFuture<'a, Result<bool>> {
        Box::pin(async move {
            let bytes = self.encode(&envelope(&self.clock.uniqid(), queue.name(), self.clock.time(), payload, None))?;
            self.commands.left_push(&Self::key(queue, "queue"), &bytes).await
        })
    }

    fn publish_many<'a>(&'a self, queue: &'a Queue, payloads: Vec<Array>) -> BoxFuture<'a, Result<bool>> {
        Box::pin(async move {
            if payloads.is_empty() {
                return Ok(true);
            }
            let mut encoded = Vec::with_capacity(payloads.len());
            for payload in payloads {
                encoded.push(self.encode(&envelope(
                    &self.clock.uniqid(),
                    queue.name(),
                    self.clock.time(),
                    payload,
                    None,
                ))?);
            }
            self.commands.left_push_many(&Self::key(queue, "queue"), &encoded).await
        })
    }

    fn retry<'a>(&'a self, queue: &'a Queue, limit: Option<i64>) -> BoxFuture<'a, Result<()>> {
        Box::pin(self.retry_with(queue, limit, None, None))
    }

    fn pending_count<'a>(&'a self, queue: &'a Queue) -> BoxFuture<'a, Result<i64>> {
        Box::pin(async move { self.commands.list_size(&Self::key(queue, "queue")).await })
    }

    /// The failed, dead and poison lists together.
    fn failed_count<'a>(&'a self, queue: &'a Queue) -> BoxFuture<'a, Result<i64>> {
        Box::pin(async move {
            Ok(self.commands.list_size(&Self::key(queue, "failed")).await?
                + self.commands.list_size(&Self::key(queue, "dead")).await?
                + self.commands.list_size(&Self::key(queue, "poison")).await?)
        })
    }
}

impl Consumer for Redis {
    fn receive<'a>(&'a self, queue: &'a Queue, timeout: i64, n: i64) -> BoxFuture<'a, Result<Vec<Message>>> {
        Box::pin(self.receive_messages(queue, timeout, n))
    }

    fn commit<'a>(&'a self, queue: &'a Queue, message: &'a Message) -> BoxFuture<'a, Result<()>> {
        Box::pin(self.settle(queue, message, "commit"))
    }

    fn reject<'a>(&'a self, queue: &'a Queue, message: &'a Message) -> BoxFuture<'a, Result<()>> {
        Box::pin(self.settle(queue, message, "reject"))
    }

    fn close(&self) -> BoxFuture<'_, ()> {
        self.closed.store(true, Ordering::SeqCst);
        Box::pin(async {})
    }

    fn release<'a>(&'a self, queue: &'a Queue, messages: &'a [Message]) -> Option<BoxFuture<'a, Result<()>>> {
        Some(Box::pin(self.release_messages(queue, messages)))
    }

    fn extend<'a>(&'a self, queue: &'a Queue, messages: &'a [Message]) -> BoxFuture<'a, Result<()>> {
        Box::pin(self.extend_messages(queue, messages))
    }

    fn extend_interval(&self) -> BoxFuture<'_, Option<f64>> {
        let interval = self.extend_interval_secs();
        Box::pin(async move { Some(interval) })
    }

    fn maintain(&self) -> Option<BoxFuture<'_, Result<()>>> {
        Some(Box::pin(self.maintain_queues()))
    }

    fn name(&self) -> &str {
        "Utopia\\Queue\\Broker\\Redis"
    }
}
