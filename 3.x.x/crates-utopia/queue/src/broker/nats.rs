use std::sync::Arc;

use serde_json::Value;

use crate::consumer::Consumer;
use crate::error::QueueError;
use crate::message::Message;
use crate::publisher::Publisher;
use crate::queue::Queue;

/// NATS `JetStream` broker.
///
/// PHP `Utopia\Queue\Broker\Nats`. Live protocol I/O is compiled behind the
/// `nats` feature (`async-nats`). Without it the type still exists and methods
/// return [`QueueError::NatsDisabled`].
pub struct Nats {
    url_factory: Arc<dyn Fn() -> String + Send + Sync>,
    ack_wait: f64,
    max_deliver: i32,
    replicas: i32,
    #[cfg(feature = "nats")]
    inner: parking_lot::Mutex<Option<live::NatsInner>>,
}

impl std::fmt::Debug for Nats {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Nats")
            .field("ack_wait", &self.ack_wait)
            .field("max_deliver", &self.max_deliver)
            .field("replicas", &self.replicas)
            .finish_non_exhaustive()
    }
}

impl Nats {
    pub fn new(url: impl Into<String>) -> Self {
        let url = url.into();
        Self::from_factory(move || url.clone())
    }

    pub fn from_factory(factory: impl Fn() -> String + Send + Sync + 'static) -> Self {
        Self {
            url_factory: Arc::new(factory),
            ack_wait: 30.0,
            max_deliver: 5,
            replicas: 1,
            #[cfg(feature = "nats")]
            inner: parking_lot::Mutex::new(None),
        }
    }

    pub fn with_ack_wait(mut self, ack_wait: f64) -> Self {
        self.ack_wait = ack_wait;
        self
    }

    pub fn with_max_deliver(mut self, max_deliver: i32) -> Self {
        self.max_deliver = max_deliver;
        self
    }

    pub fn with_replicas(mut self, replicas: i32) -> Self {
        self.replicas = replicas;
        self
    }

    pub fn ack_wait(&self) -> f64 {
        self.ack_wait
    }

    pub fn max_deliver(&self) -> i32 {
        self.max_deliver
    }

    pub fn replicas(&self) -> i32 {
        self.replicas
    }

    pub fn url(&self) -> String {
        (self.url_factory)()
    }
}

impl Publisher for Nats {
    fn enqueue(&self, queue: &Queue, payload: Value, priority: bool) -> Result<bool, QueueError> {
        #[cfg(feature = "nats")]
        {
            return live::enqueue(self, queue, payload, priority);
        }
        #[cfg(not(feature = "nats"))]
        {
            let _ = (queue, payload, priority);
            Err(QueueError::NatsDisabled)
        }
    }

    fn retry(
        &self,
        queue: &Queue,
        limit: Option<i64>,
        max_attempts: Option<i64>,
        newer_than: Option<i64>,
    ) -> Result<(), QueueError> {
        #[cfg(feature = "nats")]
        {
            return live::retry(self, queue, limit, max_attempts, newer_than);
        }
        #[cfg(not(feature = "nats"))]
        {
            let _ = (queue, limit, max_attempts, newer_than);
            Err(QueueError::NatsDisabled)
        }
    }

    fn get_queue_size(&self, queue: &Queue, failed_jobs: bool) -> Result<i64, QueueError> {
        #[cfg(feature = "nats")]
        {
            return live::get_queue_size(self, queue, failed_jobs);
        }
        #[cfg(not(feature = "nats"))]
        {
            let _ = (queue, failed_jobs);
            Err(QueueError::NatsDisabled)
        }
    }

    fn reap(
        &self,
        _queue: &Queue,
        _older_than: i64,
        _limit: Option<i64>,
        _max_attempts: Option<i64>,
        _newer_than: Option<i64>,
    ) -> Result<i64, QueueError> {
        // PHP: JetStream AckWait reclaims stranded jobs; reap always returns 0.
        Ok(0)
    }
}

impl Consumer for Nats {
    fn receive(&self, queue: &Queue, timeout: i64) -> Result<Option<Message>, QueueError> {
        #[cfg(feature = "nats")]
        {
            return live::receive(self, queue, timeout);
        }
        #[cfg(not(feature = "nats"))]
        {
            let _ = (queue, timeout);
            Err(QueueError::NatsDisabled)
        }
    }

    fn commit(&self, queue: &Queue, message: &Message) -> Result<(), QueueError> {
        #[cfg(feature = "nats")]
        {
            return live::commit(self, queue, message);
        }
        #[cfg(not(feature = "nats"))]
        {
            let _ = (queue, message);
            Err(QueueError::NatsDisabled)
        }
    }

    fn reject(&self, queue: &Queue, message: &Message) -> Result<(), QueueError> {
        #[cfg(feature = "nats")]
        {
            return live::reject(self, queue, message);
        }
        #[cfg(not(feature = "nats"))]
        {
            let _ = (queue, message);
            Err(QueueError::NatsDisabled)
        }
    }

    fn close(&self) {
        #[cfg(feature = "nats")]
        {
            live::close(self);
        }
    }

    fn as_publisher(&self) -> Option<&dyn Publisher> {
        Some(self)
    }
}

#[cfg(feature = "nats")]
mod live {
    use super::Nats;
    use crate::broker::redis::{uniqid, unix_now};
    use crate::error::QueueError;
    use crate::message::Message;
    use crate::queue::Queue;
    use serde_json::{json, Value};
    use sha2::{Digest, Sha256};
    use std::collections::HashMap;
    use std::time::Duration;

    const STREAM_PREFIX: &str = "QUEUE_";
    const DEAD_STREAM_SUFFIX: &str = "_DEAD";
    const SUBJECT_PREFIX: &str = "Q.";
    const SUBJECT_NORMAL: &str = "normal";
    const SUBJECT_PRIORITY: &str = "priority";
    const SUBJECT_DEAD: &str = "dead";

    pub(super) struct NatsInner {
        client: async_nats::Client,
        in_flight: HashMap<String, async_nats::jetstream::message::Message>,
        _runtime: tokio::runtime::Handle,
    }

    fn block_on<T>(f: impl std::future::Future<Output = T>) -> T {
        match tokio::runtime::Handle::try_current() {
            Ok(h) => tokio::task::block_in_place(|| h.block_on(f)),
            Err(_) => {
                let rt = tokio::runtime::Builder::new_current_thread()
                    .enable_all()
                    .build()
                    .expect("nats runtime");
                rt.block_on(f)
            }
        }
    }

    fn identity(queue: &Queue) -> String {
        format!(
            "{}:{}:{}",
            queue.namespace.len(),
            queue.namespace,
            queue.name
        )
    }

    fn sanitize(name: &str) -> String {
        name.chars()
            .map(|c| {
                if c.is_ascii_alphanumeric() || c == '_' || c == '-' {
                    c
                } else {
                    '_'
                }
            })
            .collect()
    }

    fn sha256_hex(s: &str) -> String {
        let mut hasher = Sha256::new();
        hasher.update(s.as_bytes());
        hex::encode(hasher.finalize())
    }

    fn work_stream(queue: &Queue) -> String {
        let prefix: String = sanitize(&format!("{}_{}", queue.namespace, queue.name))
            .chars()
            .take(40)
            .collect();
        format!("{STREAM_PREFIX}{prefix}_{}", sha256_hex(&identity(queue)))
    }

    fn dead_stream(queue: &Queue) -> String {
        format!("{}{DEAD_STREAM_SUFFIX}", work_stream(queue))
    }

    fn subject_base(queue: &Queue) -> String {
        format!("{SUBJECT_PREFIX}{}", sha256_hex(&identity(queue)))
    }

    fn work_subject(queue: &Queue) -> String {
        format!("{}.{SUBJECT_NORMAL}", subject_base(queue))
    }

    fn priority_subject(queue: &Queue) -> String {
        format!("{}.{SUBJECT_PRIORITY}", subject_base(queue))
    }

    fn dead_subject(queue: &Queue) -> String {
        format!("{}.{SUBJECT_DEAD}", subject_base(queue))
    }

    async fn connect(nats: &Nats) -> Result<async_nats::Client, QueueError> {
        async_nats::connect(nats.url())
            .await
            .map_err(|e| QueueError::Nats(e.to_string()))
    }

    async fn ensure(
        client: &async_nats::Client,
        nats: &Nats,
        queue: &Queue,
    ) -> Result<(), QueueError> {
        let js = async_nats::jetstream::new(client.clone());
        let max_age = if queue.job_ttl > 0 {
            Some(Duration::from_secs(queue.job_ttl as u64))
        } else {
            None
        };
        let mut work = async_nats::jetstream::stream::Config {
            name: work_stream(queue),
            subjects: vec![work_subject(queue), priority_subject(queue)],
            retention: async_nats::jetstream::stream::RetentionPolicy::WorkQueue,
            storage: async_nats::jetstream::stream::StorageType::File,
            num_replicas: nats.replicas.max(1) as usize,
            ..Default::default()
        };
        if let Some(age) = max_age {
            work.max_age = age;
        }
        js.get_or_create_stream(work)
            .await
            .map_err(|e| QueueError::Nats(e.to_string()))?;

        let dead = async_nats::jetstream::stream::Config {
            name: dead_stream(queue),
            subjects: vec![dead_subject(queue)],
            retention: async_nats::jetstream::stream::RetentionPolicy::WorkQueue,
            storage: async_nats::jetstream::stream::StorageType::File,
            num_replicas: nats.replicas.max(1) as usize,
            ..Default::default()
        };
        js.get_or_create_stream(dead)
            .await
            .map_err(|e| QueueError::Nats(e.to_string()))?;
        Ok(())
    }

    fn inner<'a>(
        nats: &'a Nats,
    ) -> Result<parking_lot::MutexGuard<'a, Option<NatsInner>>, QueueError> {
        let mut guard = nats.inner.lock();
        if guard.is_none() {
            let client = block_on(connect(nats))?;
            let handle = tokio::runtime::Handle::try_current().unwrap_or_else(|_| {
                tokio::runtime::Builder::new_current_thread()
                    .enable_all()
                    .build()
                    .expect("nats handle")
                    .handle()
                    .clone()
            });
            *guard = Some(NatsInner {
                client,
                in_flight: HashMap::new(),
                _runtime: handle,
            });
        }
        Ok(guard)
    }

    pub(super) fn enqueue(
        nats: &Nats,
        queue: &Queue,
        payload: Value,
        priority: bool,
    ) -> Result<bool, QueueError> {
        block_on(async {
            let client = {
                let mut g = inner(nats)?;
                let inner = g.as_mut().expect("inner");
                ensure(&inner.client, nats, queue).await?;
                inner.client.clone()
            };
            let js = async_nats::jetstream::new(client);
            let message = json!({
                "pid": uniqid(),
                "queue": queue.name,
                "timestamp": unix_now(),
                "payload": payload,
            });
            let subject = if priority {
                priority_subject(queue)
            } else {
                work_subject(queue)
            };
            let bytes =
                serde_json::to_vec(&message).map_err(|e| QueueError::Other(e.to_string()))?;
            js.publish(subject, bytes.into())
                .await
                .map_err(|e| QueueError::Nats(e.to_string()))?
                .await
                .map_err(|e| QueueError::Nats(e.to_string()))?;
            Ok(true)
        })
    }

    pub(super) fn receive(
        nats: &Nats,
        queue: &Queue,
        timeout: i64,
    ) -> Result<Option<Message>, QueueError> {
        block_on(async {
            let client = {
                let mut g = inner(nats)?;
                let inner = g.as_mut().expect("inner");
                ensure(&inner.client, nats, queue).await?;
                inner.client.clone()
            };
            let js = async_nats::jetstream::new(client);
            let stream = js
                .get_stream(work_stream(queue))
                .await
                .map_err(|e| QueueError::Nats(e.to_string()))?;

            let fetch = |filter: String, wait: Duration| {
                let stream = stream.clone();
                async move {
                    let consumer = stream
                        .create_consumer(async_nats::jetstream::consumer::pull::Config {
                            filter_subject: filter,
                            ack_policy: async_nats::jetstream::consumer::AckPolicy::Explicit,
                            ack_wait: Duration::from_secs_f64(nats.ack_wait.max(0.1)),
                            max_deliver: i64::from(nats.max_deliver),
                            ..Default::default()
                        })
                        .await
                        .ok()?;
                    let mut batch = consumer
                        .fetch()
                        .max_messages(1)
                        .expires(wait)
                        .messages()
                        .await
                        .ok()?;
                    futures::StreamExt::next(&mut batch).await?.ok()
                }
            };

            let js_msg = fetch(priority_subject(queue), Duration::from_millis(250))
                .await
                .or(fetch(
                    work_subject(queue),
                    Duration::from_secs(timeout.max(0) as u64),
                )
                .await);

            let Some(js_msg) = js_msg else {
                return Ok(None);
            };
            let data: Value = serde_json::from_slice(&js_msg.payload)
                .map_err(|e| QueueError::Other(e.to_string()))?;
            let mut message = Message::from_value(&data);
            let info = js_msg.info().map_err(|e| QueueError::Nats(e.to_string()))?;
            message.set_attempts(info.delivered.saturating_sub(1));
            let pid = message.get_pid().to_owned();
            inner(nats)?
                .as_mut()
                .expect("inner")
                .in_flight
                .insert(pid, js_msg);
            Ok(Some(message))
        })
    }

    pub(super) fn commit(nats: &Nats, _queue: &Queue, message: &Message) -> Result<(), QueueError> {
        block_on(async {
            let msg = {
                let mut g = inner(nats)?;
                g.as_mut()
                    .expect("inner")
                    .in_flight
                    .remove(message.get_pid())
            };
            if let Some(msg) = msg {
                msg.ack()
                    .await
                    .map_err(|e| QueueError::Nats(e.to_string()))?;
            }
            Ok(())
        })
    }

    pub(super) fn reject(nats: &Nats, queue: &Queue, message: &Message) -> Result<(), QueueError> {
        block_on(async {
            let js_msg = {
                let mut g = inner(nats)?;
                g.as_mut()
                    .expect("inner")
                    .in_flight
                    .remove(message.get_pid())
            };
            let Some(js_msg) = js_msg else {
                return Ok(());
            };
            let info = js_msg.info().map_err(|e| QueueError::Nats(e.to_string()))?;
            if info.delivered >= i64::from(nats.max_deliver) {
                let client = inner(nats)?.as_ref().expect("inner").client.clone();
                let js = async_nats::jetstream::new(client);
                js.publish(dead_subject(queue), js_msg.payload.clone())
                    .await
                    .map_err(|e| QueueError::Nats(e.to_string()))?;
                let _ = js_msg.ack().await;
                return Ok(());
            }
            js_msg
                .ack_with(async_nats::jetstream::AckKind::Nak(None))
                .await
                .map_err(|e| QueueError::Nats(e.to_string()))?;
            Ok(())
        })
    }

    pub(super) fn retry(
        nats: &Nats,
        queue: &Queue,
        limit: Option<i64>,
        _max_attempts: Option<i64>,
        _newer_than: Option<i64>,
    ) -> Result<(), QueueError> {
        block_on(async {
            let client = {
                let mut g = inner(nats)?;
                let inner = g.as_mut().expect("inner");
                ensure(&inner.client, nats, queue).await?;
                inner.client.clone()
            };
            let js = async_nats::jetstream::new(client);
            let stream = js
                .get_stream(dead_stream(queue))
                .await
                .map_err(|e| QueueError::Nats(e.to_string()))?;
            let consumer = stream
                .create_consumer(async_nats::jetstream::consumer::pull::Config {
                    durable_name: Some("retry".into()),
                    filter_subject: dead_subject(queue),
                    ack_policy: async_nats::jetstream::consumer::AckPolicy::Explicit,
                    ack_wait: Duration::from_secs_f64(nats.ack_wait.max(0.1)),
                    ..Default::default()
                })
                .await
                .map_err(|e| QueueError::Nats(e.to_string()))?;
            let mut remaining = limit.unwrap_or(500);
            while remaining > 0 {
                let mut batch = consumer
                    .fetch()
                    .max_messages(1)
                    .expires(Duration::from_secs(1))
                    .messages()
                    .await
                    .map_err(|e| QueueError::Nats(e.to_string()))?;
                let Some(msg) = futures::StreamExt::next(&mut batch).await else {
                    break;
                };
                let msg = msg.map_err(|e| QueueError::Nats(e.to_string()))?;
                js.publish(work_subject(queue), msg.payload.clone())
                    .await
                    .map_err(|e| QueueError::Nats(e.to_string()))?;
                msg.ack()
                    .await
                    .map_err(|e| QueueError::Nats(e.to_string()))?;
                remaining -= 1;
            }
            Ok(())
        })
    }

    pub(super) fn get_queue_size(
        nats: &Nats,
        queue: &Queue,
        failed_jobs: bool,
    ) -> Result<i64, QueueError> {
        block_on(async {
            let client = {
                let mut g = inner(nats)?;
                let inner = g.as_mut().expect("inner");
                ensure(&inner.client, nats, queue).await?;
                inner.client.clone()
            };
            let js = async_nats::jetstream::new(client);
            if failed_jobs {
                let mut stream = js
                    .get_stream(dead_stream(queue))
                    .await
                    .map_err(|e| QueueError::Nats(e.to_string()))?;
                let info = stream
                    .info()
                    .await
                    .map_err(|e| QueueError::Nats(e.to_string()))?;
                return Ok(info.state.messages as i64);
            }
            let mut stream = js
                .get_stream(work_stream(queue))
                .await
                .map_err(|e| QueueError::Nats(e.to_string()))?;
            let info = stream
                .info()
                .await
                .map_err(|e| QueueError::Nats(e.to_string()))?;
            Ok(info.state.messages as i64)
        })
    }

    pub(super) fn close(nats: &Nats) {
        *nats.inner.lock() = None;
    }
}
