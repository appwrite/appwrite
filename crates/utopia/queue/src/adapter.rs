//! How a worker runs its consume loops (`Utopia\Queue\Adapter` and its
//! Swoole and KubernetesJob runtimes).

use std::collections::HashMap;
use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::{Arc, Mutex};
use std::time::Duration;

use tokio::sync::{Notify, Semaphore};

use crate::connection::BoxFuture;
use crate::contract::Consumer;
use crate::error::{Error, Result};
use crate::hook::Resources;
use crate::message::Message;
use crate::queue::Queue;

/// Seconds a receive waits for the first message.
pub const RECEIVE_TIMEOUT: i64 = 2;
/// Seconds to wait after the broker failed to answer.
pub const RECEIVE_BACKOFF: u64 = 1;
/// Seconds between idle-upkeep sweeps of a running worker.
pub const MAINTENANCE_INTERVAL: f64 = 30.0;
const MAINTENANCE_POLL: f64 = 0.5;

/// Makes a consumer for a queue name (`''` for the adapter's own).
pub type ConsumerFactory = Arc<dyn Fn(&str) -> Arc<dyn Consumer> + Send + Sync>;

/// Runs a message's handler; its result decides commit or reject.
///
/// Each callback gets the message's context: a child of the static
/// resources, created per message and dropped once it is settled.
pub type MessageCallback = Arc<dyn Fn(Arc<Message>, Arc<Resources>) -> BoxFuture<'static, Result<()>> + Send + Sync>;
/// Runs after a message is committed.
pub type SuccessCallback = Arc<dyn Fn(Arc<Message>, Arc<Resources>) -> BoxFuture<'static, Result<()>> + Send + Sync>;
/// Reports a failure: with the message, or `None` when obtaining one failed.
pub type ErrorCallback =
    Arc<dyn Fn(Option<Arc<Message>>, Error, Arc<Resources>) -> BoxFuture<'static, Result<()>> + Send + Sync>;

/// One queue to consume: identity, handler concurrency, prefetch and consumer.
#[derive(Clone)]
pub struct Spec {
    /// The queue.
    pub queue: Queue,
    /// Handlers that may run at once.
    pub coroutines: usize,
    /// Unacknowledged messages allowed at once (at least `coroutines`).
    pub prefetch: usize,
    /// The consumer for this queue.
    pub consumer: Arc<dyn Consumer>,
}

/// Stops an [`Adapter`]'s consume loops ([`Adapter::stopper`]).
#[derive(Clone)]
pub struct Stopper(Arc<AtomicBool>);

impl Stopper {
    /// Asks the loops to stop; running handlers finish first.
    pub fn stop(&self) {
        self.0.store(true, Ordering::SeqCst);
    }
}

/// How the consume loops run.
#[derive(Debug, Clone, Copy, PartialEq)]
pub enum Runtime {
    /// Long-running workers (`Adapter\Swoole`): one loop per queue with up to
    /// `coroutines` handlers at once, prefetch, delivery-deadline extension
    /// and idle upkeep, until stopped. Stopping drains running handlers and
    /// releases prefetched work.
    Workers,
    /// Run to completion (`Adapter\KubernetesJob`): drain each queue in turn,
    /// one message at a time, and return when a receive times out.
    Drain,
}

/// The transport side of a worker server: its consumer (or a factory), how
/// many workers run, the queue namespace, and the static resources.
pub struct Adapter {
    consumer: Arc<dyn Consumer>,
    factory: ConsumerFactory,
    shared: bool,
    workers: usize,
    namespace: String,
    resources: Arc<Resources>,
    runtime: Runtime,
    stopped: Arc<AtomicBool>,
    consumers: Mutex<Vec<Arc<dyn Consumer>>>,
    trace: Arc<dyn Fn(&str) + Send + Sync>,
    receive_timeout: i64,
}

impl Adapter {
    /// An adapter sharing one consumer between every queue (single-queue workers only).
    pub fn new(consumer: Arc<dyn Consumer>, workers: usize, runtime: Runtime) -> Self {
        let shared = consumer.clone();
        let mut adapter = Self::build(Arc::new(move |_: &str| shared.clone()), workers, runtime);
        adapter.consumer = consumer;
        adapter.shared = true;
        adapter
    }

    /// An adapter making a consumer per queue, so each consume loop has its
    /// own receive connection. The adapter's own consumer is `factory("")`.
    pub fn with_factory(factory: ConsumerFactory, workers: usize, runtime: Runtime) -> Self {
        Self::build(factory, workers, runtime)
    }

    fn build(factory: ConsumerFactory, workers: usize, runtime: Runtime) -> Self {
        Self {
            consumer: factory(""),
            factory,
            shared: false,
            workers,
            namespace: crate::queue::DEFAULT_NAMESPACE.to_owned(),
            resources: Arc::new(Resources::new()),
            runtime,
            stopped: Arc::new(AtomicBool::new(false)),
            consumers: Mutex::new(Vec::new()),
            trace: Arc::new(|line: &str| eprint!("{line}")),
            receive_timeout: RECEIVE_TIMEOUT,
        }
    }

    /// The key prefix shared by every job on this adapter.
    pub fn with_namespace(mut self, namespace: impl Into<String>) -> Self {
        self.namespace = namespace.into();
        self
    }

    /// Static resources shared by every message.
    pub fn with_resources(mut self, resources: Arc<Resources>) -> Self {
        self.resources = resources;
        self
    }

    /// Where a failure whose report also failed is written (stderr by default).
    pub fn with_trace(mut self, trace: Arc<dyn Fn(&str) + Send + Sync>) -> Self {
        self.trace = trace;
        self
    }

    /// Seconds a receive waits for the first message (default 2).
    pub fn with_receive_timeout(mut self, seconds: i64) -> Self {
        self.receive_timeout = seconds;
        self
    }

    /// The namespace.
    pub fn namespace(&self) -> &str {
        &self.namespace
    }

    /// The worker count.
    pub fn workers(&self) -> usize {
        self.workers
    }

    /// The runtime.
    pub fn runtime(&self) -> Runtime {
        self.runtime
    }

    /// The adapter's own consumer.
    pub fn consumer(&self) -> &Arc<dyn Consumer> {
        &self.consumer
    }

    /// `createConsumer($queue)`.
    pub fn create_consumer(&self, queue: &str) -> Arc<dyn Consumer> {
        (self.factory)(queue)
    }

    /// Whether the adapter was built with one shared consumer.
    pub fn shares_consumer(&self) -> bool {
        self.shared
    }

    /// The static resources.
    pub fn resources(&self) -> &Arc<Resources> {
        &self.resources
    }

    /// Asks the consume loops to stop; running handlers finish first.
    pub fn stop(&self) {
        self.stopped.store(true, Ordering::SeqCst);
    }

    /// Whether [`Adapter::stop`] was called.
    pub fn is_stopped(&self) -> bool {
        self.stopped.load(Ordering::SeqCst)
    }

    /// A handle that stops this adapter from anywhere: a signal handler, a
    /// job, another task.
    pub fn stopper(&self) -> Stopper {
        Stopper(self.stopped.clone())
    }

    pub(crate) fn reset(&self) {
        self.stopped.store(false, Ordering::SeqCst);
    }

    /// Sweeps every consumer this adapter owns for idle upkeep. Consumers
    /// without upkeep are skipped; failures go to `error` and never escape.
    pub async fn maintain(&self, error: Option<&ErrorCallback>) {
        let mut targets: Vec<Arc<dyn Consumer>> = vec![self.consumer.clone()];
        if let Ok(consumers) = self.consumers.lock() {
            for c in consumers.iter() {
                if !targets.iter().any(|t| Arc::ptr_eq(t, c)) {
                    targets.push(c.clone());
                }
            }
        }
        for target in targets {
            let Some(sweep) = target.maintain() else { continue };
            if let Err(e) = sweep.await
                && let Some(cb) = error
            {
                let _ = cb(None, e, Arc::new(Resources::child(self.resources.clone()))).await;
            }
        }
    }

    /// Runs the consume loops for `queues` until they end.
    pub async fn consume(
        &self,
        message: MessageCallback,
        success: SuccessCallback,
        error: ErrorCallback,
        queues: Vec<Spec>,
    ) -> Result<()> {
        self.reset();
        if queues.is_empty() {
            return Err(Error::Logic("At least one queue is required".into()));
        }
        match self.runtime {
            Runtime::Drain => {
                for spec in queues {
                    self.drain(&spec, &message, &success, &error).await?;
                }
                Ok(())
            }
            Runtime::Workers => {
                let maintaining = Arc::new(AtomicBool::new(true));
                let maintenance = self.maintenance(maintaining.clone(), error.clone());
                let loops = futures_util::future::join_all(
                    queues.iter().map(|spec| self.run(spec, message.clone(), success.clone(), error.clone())),
                );
                let (results, ()) = tokio::join!(loops, async {
                    maintenance.await;
                });
                let _ = maintaining;
                let consumers = std::mem::take(&mut *self.consumers.lock().unwrap_or_else(|e| e.into_inner()));
                for c in consumers {
                    c.close().await;
                }
                results.into_iter().collect::<Result<Vec<()>>>().map(|_| ())
            }
        }
    }

    async fn maintenance(&self, maintaining: Arc<AtomicBool>, error: ErrorCallback) {
        while maintaining.load(Ordering::SeqCst) && !self.is_stopped() {
            let mut waited = 0.0;
            while waited < MAINTENANCE_INTERVAL && !self.is_stopped() {
                tokio::time::sleep(Duration::from_secs_f64(MAINTENANCE_POLL)).await;
                waited += MAINTENANCE_POLL;
            }
            if self.is_stopped() {
                return;
            }
            self.maintain(Some(&error)).await;
        }
    }

    /// One run-to-completion pass over a queue.
    async fn drain(
        &self,
        spec: &Spec,
        message: &MessageCallback,
        success: &SuccessCallback,
        error: &ErrorCallback,
    ) -> Result<()> {
        while !self.is_stopped() {
            let received = match spec.consumer.receive(&spec.queue, self.receive_timeout, 1).await {
                Ok(mut batch) if !batch.is_empty() => batch.swap_remove(0),
                Ok(_) => break,
                Err(e) => return Err(e),
            };
            let context = Arc::new(Resources::child(self.resources.clone()));
            let callbacks = Callbacks { message, success, error };
            process(received, &spec.queue, spec.consumer.as_ref(), callbacks, context, None, self.trace.as_ref()).await;
        }
        Ok(())
    }

    /// Claims up to `max` messages; a failed receive is reported and waited out.
    async fn next_batch(
        &self,
        error: &ErrorCallback,
        queue: &Queue,
        consumer: &dyn Consumer,
        max: usize,
    ) -> Vec<Message> {
        match consumer.receive(queue, self.receive_timeout, max as i64).await {
            Ok(batch) => batch,
            Err(e) => {
                let text = e.to_string();
                if let Err(report) = error(None, e, Arc::new(Resources::child(self.resources.clone()))).await {
                    self.unreported("receive", &text, &report);
                }
                tokio::time::sleep(Duration::from_secs(RECEIVE_BACKOFF)).await;
                Vec::new()
            }
        }
    }

    /// The long-running loop of one queue.
    async fn run(
        &self,
        spec: &Spec,
        message: MessageCallback,
        success: SuccessCallback,
        error: ErrorCallback,
    ) -> Result<()> {
        let consumer = spec.consumer.clone();
        if !Arc::ptr_eq(&consumer, &self.consumer)
            && let Ok(mut c) = self.consumers.lock()
        {
            c.push(consumer.clone());
        }
        let (coroutines, prefetch) = (spec.coroutines, spec.prefetch);
        if coroutines < 1 || prefetch < coroutines {
            return Err(Error::InvalidArgument("Prefetch must be at least the positive number of coroutines".into()));
        }
        let queue = &spec.queue;
        let running = Arc::new(Semaphore::new(coroutines));
        let available = Arc::new(Notify::new());
        let deliveries: Arc<Mutex<HashMap<u64, Message>>> = Arc::new(Mutex::new(HashMap::new()));
        let finished = Arc::new(Notify::new());
        let done = Arc::new(AtomicBool::new(false));
        let beat = consumer.extend_interval().await.filter(|b| *b > 0.0);
        let renew = beat.map(|beat| {
            let (consumer, deliveries, finished, done, error, queue) =
                (consumer.clone(), deliveries.clone(), finished.clone(), done.clone(), error.clone(), queue.clone());
            let trace = self.trace.clone();
            let resources = self.resources.clone();
            tokio::spawn(async move {
                while !done.load(Ordering::SeqCst) {
                    let wait = tokio::time::timeout(Duration::from_secs_f64(beat), finished.notified()).await;
                    if wait.is_ok() || done.load(Ordering::SeqCst) {
                        break;
                    }
                    let held: Vec<Message> =
                        deliveries.lock().map(|d| d.values().cloned().collect()).unwrap_or_default();
                    if held.is_empty() {
                        continue;
                    }
                    if let Err(e) = consumer.extend(&queue, &held).await {
                        let text = e.to_string();
                        if let Err(report) = error(None, e, Arc::new(Resources::child(resources.clone()))).await {
                            trace(&format!(
                                "[queue] receive failed and its error report failed too: {text} | report: {report}\n"
                            ));
                        }
                    }
                }
            })
        });
        let mut tasks = tokio::task::JoinSet::new();
        let mut next_id: u64 = 0;
        let in_flight = |d: &Arc<Mutex<HashMap<u64, Message>>>| d.lock().map(|d| d.len()).unwrap_or(0);
        while !self.is_stopped() {
            while prefetch - in_flight(&deliveries).min(prefetch) < (prefetch / 2).max(1) && !self.is_stopped() {
                let _ = tokio::time::timeout(Duration::from_millis(100), available.notified()).await;
            }
            if self.is_stopped() {
                break;
            }
            let room = prefetch - in_flight(&deliveries).min(prefetch);
            let batch = self.next_batch(&error, queue, consumer.as_ref(), room).await;
            let mut ids = Vec::with_capacity(batch.len());
            if let Ok(mut d) = deliveries.lock() {
                for m in &batch {
                    next_id += 1;
                    d.insert(next_id, m.clone());
                    ids.push(next_id);
                }
            }
            for (index, (message_value, id)) in batch.iter().zip(ids.iter()).enumerate() {
                let permit = running.clone().acquire_owned().await.map_err(|e| Error::Runtime(e.to_string()))?;
                if self.is_stopped() {
                    drop(permit);
                    let waiting = &batch[index..];
                    if let Some(release) = consumer.release(queue, waiting) {
                        let result = release.await;
                        if let Err(e) = result {
                            let text = e.to_string();
                            if let Err(report) =
                                error(None, e, Arc::new(Resources::child(self.resources.clone()))).await
                            {
                                self.unreported("receive", &text, &report);
                            }
                        }
                    }
                    if let Ok(mut d) = deliveries.lock() {
                        for id in &ids[index..] {
                            d.remove(id);
                        }
                    }
                    break;
                }
                let (m, consumer, queue, message_cb, success, error, deliveries, available, id) = (
                    message_value.clone(),
                    consumer.clone(),
                    queue.clone(),
                    message.clone(),
                    success.clone(),
                    error.clone(),
                    deliveries.clone(),
                    available.clone(),
                    *id,
                );
                let trace = self.trace.clone();
                let context = Arc::new(Resources::child(self.resources.clone()));
                tasks.spawn(async move {
                    let permit = Mutex::new(Some(permit));
                    let handled = || {
                        if let Ok(mut p) = permit.lock() {
                            p.take();
                        }
                    };
                    let callbacks = Callbacks { message: &message_cb, success: &success, error: &error };
                    process(m, &queue, consumer.as_ref(), callbacks, context, Some(&handled), trace.as_ref()).await;
                    if let Ok(mut d) = deliveries.lock() {
                        d.remove(&id);
                    }
                    available.notify_one();
                });
            }
        }
        while tasks.join_next().await.is_some() {}
        done.store(true, Ordering::SeqCst);
        finished.notify_one();
        if let Some(renew) = renew {
            let _ = renew.await;
        }
        Ok(())
    }

    fn unreported(&self, who: &str, error: &str, report: &Error) {
        (self.trace)(&format!("[queue] {who} failed and its error report failed too: {error} | report: {report}\n"));
    }
}

/// The three callbacks of a consume loop.
#[derive(Clone, Copy)]
struct Callbacks<'a> {
    message: &'a MessageCallback,
    success: &'a SuccessCallback,
    error: &'a ErrorCallback,
}

/// The three phases of one message: the handler (rejected on failure, as
/// terminal for a permanent failure or a type or value error), the commit
/// (reported, never rejected: the work is done), the success callback
/// (reported). Every failure goes to `error`; a failing report is traced.
async fn process(
    received: Message,
    queue: &Queue,
    consumer: &dyn Consumer,
    callbacks: Callbacks<'_>,
    context: Arc<Resources>,
    handled: Option<&(dyn Fn() + Sync)>,
    trace: &(dyn Fn(&str) + Send + Sync),
) {
    let Callbacks { message, success, error } = callbacks;
    let shared = Arc::new(received.clone());
    context.set("message", shared.clone());
    let outcome = message(shared.clone(), context.clone()).await;
    if let Some(handled) = handled {
        handled();
    }
    let report = |e: Error, m: Arc<Message>| async {
        let text = e.to_string();
        let pid = String::from_utf8_lossy(m.pid().as_bytes()).into_owned();
        if let Err(failure) = error(Some(m), e, context.clone()).await {
            trace(&format!(
                "[queue] message {pid} failed and its error report failed too: {text} | report: {failure}\n"
            ));
        }
    };
    if let Err(e) = outcome {
        if e.is_terminal() {
            received.terminal(true);
        }
        let _ = consumer.reject(queue, &received).await;
        report(e, Arc::new(received)).await;
        return;
    }
    if let Err(e) = consumer.commit(queue, &received).await {
        report(e, shared).await;
        return;
    }
    if let Err(e) = success(shared.clone(), context.clone()).await {
        report(e, shared).await;
    }
}
