//! The worker server: jobs per queue, hooks around them, and the consume
//! loops that run them.

use std::sync::Arc;

use indexmap::IndexMap;
use php_std::Array;

use crate::adapter::{Adapter, ErrorCallback, MessageCallback, Spec, SuccessCallback};
use crate::contract::Publisher;
use crate::error::{Error, Result};
use crate::hook::{Hook, Job, Resources};
use crate::message::Message;
use crate::queue::Queue;
use crate::telemetry::{Histogram, NoTelemetry, Telemetry};

/// Bucket boundaries of the duration histograms, in seconds: a queue spans
/// milliseconds to hours of backlog.
pub const DURATION_BUCKETS: [f64; 24] = [
    0.005, 0.01, 0.025, 0.05, 0.075, 0.1, 0.25, 0.5, 0.75, 1.0, 2.5, 5.0, 7.5, 10.0, 20.0, 30.0, 60.0, 120.0, 300.0,
    600.0, 1200.0, 1800.0, 3600.0, 7200.0,
];

/// Bucket boundaries of the message size histogram, in bytes: 1 KiB to 256 MiB.
pub const SIZE_BUCKETS: [f64; 10] =
    [1024.0, 4096.0, 16384.0, 65536.0, 262_144.0, 1_048_576.0, 4_194_304.0, 16_777_216.0, 67_108_864.0, 268_435_456.0];

/// Makes the consumer for one queue, overriding the adapter's.
pub type ConsumerFactory = crate::adapter::ConsumerFactory;

struct Registered {
    job: Job,
    coroutines: usize,
    prefetch: usize,
}

/// A worker server: register a [`Job`] per queue, hooks to run around
/// them, then [`Server::start`].
///
/// For each message: the `*` init hooks (unless the job turned hooks off)
/// and the init hooks of the job's groups run, then the job; a failure
/// rejects the message (terminally for [`Error::PermanentFailure`],
/// [`Error::Type`] and [`Error::Value`]) and runs the error hooks; success
/// commits it and runs the shutdown hooks. Hooks and jobs read params from
/// the payload and inject resources (`message`, `error`, `workerId`, and
/// anything set on [`Server::resources`]).
///
/// ```no_run
/// use std::sync::Arc;
/// use utopia_queue::adapter::{Adapter, Runtime};
/// use utopia_queue::broker::Redis;
/// use utopia_queue::connection::Redis as Connection;
/// use utopia_queue::Server;
///
/// # async fn run() -> Result<(), utopia_queue::Error> {
/// let broker = Arc::new(Redis::new(Arc::new(Connection::new("localhost", 6379)), Arc::new(Connection::new("localhost", 6379))));
/// let mut server = Server::new(Adapter::new(broker, 1, Runtime::Workers));
/// server.job("emails", 4, None)?.inject("message").action(|scope| async move {
///     let _message = scope.message();
///     Ok(())
/// });
/// std::sync::Arc::new(server).start().await?;
/// # Ok(()) }
/// ```
///
/// ```php
/// use Utopia\Queue\Adapter\KubernetesJob;
/// use Utopia\Queue\Broker\Redis;
/// use Utopia\Queue\Connection\Redis as Connection;
/// use Utopia\Queue\Server;
///
/// $broker = new Redis(new Connection('redis'), new Connection('redis'));
/// $server = new Server(new KubernetesJob($broker, 1));
/// $server->job('compat-doc-emails', 4)->inject('message')->action(fn ($message) => null);
/// echo count($server->jobs()), "\n"; // 1
/// ```
pub struct Server {
    adapter: Adapter,
    default: Job,
    jobs: IndexMap<String, Registered>,
    // The registered queue names, read by the depth gauges when observed.
    queues: Arc<std::sync::Mutex<Vec<String>>>,
    consumer: Option<ConsumerFactory>,
    init: Vec<Hook>,
    shutdown: Vec<Hook>,
    errors: Vec<Hook>,
    worker_start: Vec<Hook>,
    worker_stop: Vec<Hook>,
    wait_time: Arc<dyn Histogram>,
    process_duration: Arc<dyn Histogram>,
    message_size: Arc<dyn Histogram>,
    publisher: Option<Arc<dyn Publisher>>,
    telemetry: Arc<dyn Telemetry>,
}

fn star() -> Hook {
    let mut hook = Hook::new();
    hook.groups(&["*"]);
    hook
}

impl Server {
    /// A server on `adapter`.
    pub fn new(adapter: Adapter) -> Self {
        let none = NoTelemetry;
        Self {
            adapter,
            default: Job::new(),
            jobs: IndexMap::new(),
            queues: Arc::new(std::sync::Mutex::new(Vec::new())),
            consumer: None,
            init: Vec::new(),
            shutdown: Vec::new(),
            errors: Vec::new(),
            worker_start: Vec::new(),
            worker_stop: Vec::new(),
            wait_time: none.histogram("messaging.process.wait.duration", "s", None, &DURATION_BUCKETS),
            process_duration: none.histogram("messaging.process.duration", "s", None, &DURATION_BUCKETS),
            message_size: none.histogram("messaging.process.message.size", "By", None, &SIZE_BUCKETS),
            publisher: None,
            telemetry: Arc::new(NoTelemetry),
        }
    }

    /// Registers the job of a queue: `coroutines` handlers at once, and up
    /// to `prefetch` (default `coroutines`, at least that) unacknowledged
    /// messages.
    pub fn job(&mut self, queue: &str, coroutines: usize, prefetch: Option<usize>) -> Result<&mut Job> {
        if queue.is_empty() {
            return Err(Error::server("Queue name is required"));
        }
        let coroutines = coroutines.max(1);
        let prefetch = prefetch.unwrap_or(coroutines);
        if prefetch < coroutines {
            return Err(Error::InvalidArgument("Prefetch must be at least the number of coroutines".into()));
        }
        self.jobs.insert(queue.to_owned(), Registered { job: Job::new(), coroutines, prefetch });
        if let Ok(mut names) = self.queues.lock()
            && !names.iter().any(|n| n == queue)
        {
            names.push(queue.to_owned());
        }
        Ok(&mut self.jobs.get_mut(queue).expect("just inserted").job)
    }

    /// Overrides the adapter's consumer factory.
    pub fn consumer(&mut self, factory: ConsumerFactory) -> &mut Self {
        self.consumer = Some(factory);
        self
    }

    /// The registered queues, in order.
    pub fn jobs(&self) -> Vec<&str> {
        self.jobs.keys().map(String::as_str).collect()
    }

    /// The job registered for a queue.
    ///
    /// PHP: `jobs()[$queue]`.
    pub fn get_job(&self, queue: &str) -> Option<&Job> {
        self.jobs.get(queue).map(|r| &r.job)
    }

    /// The handler cap of a queue (1 when unregistered).
    pub fn coroutines(&self, queue: &str) -> usize {
        self.jobs.get(queue).map_or(1, |j| j.coroutines)
    }

    /// The prefetch of a queue (1 when unregistered).
    pub fn prefetch(&self, queue: &str) -> usize {
        self.jobs.get(queue).map_or(1, |j| j.prefetch)
    }

    /// The adapter.
    pub fn adapter(&self) -> &Adapter {
        &self.adapter
    }

    /// The static resources, shared by every message.
    pub fn resources(&self) -> &Arc<Resources> {
        self.adapter.resources()
    }

    /// Reports wait time, processing duration and consumed message size histograms, and queue
    /// depth gauges read through `publisher` (the consumer's own publisher
    /// side: the broker, when it can count).
    pub fn set_telemetry(&mut self, telemetry: Arc<dyn Telemetry>, publisher: Option<Arc<dyn Publisher>>) {
        self.wait_time = telemetry.histogram("messaging.process.wait.duration", "s", None, &DURATION_BUCKETS);
        self.process_duration = telemetry.histogram("messaging.process.duration", "s", None, &DURATION_BUCKETS);
        self.message_size = telemetry.histogram("messaging.process.message.size", "By", None, &SIZE_BUCKETS);
        self.publisher = publisher;
        self.telemetry = telemetry;
        self.depth_gauge("messaging.queue.depth", "Number of pending messages in the queue.", false);
        self.depth_gauge("messaging.queue.failed.depth", "Number of messages in the failed queue.", true);
    }

    fn depth_gauge(&self, name: &str, description: &str, failed: bool) {
        let Some(publisher) = self.publisher.clone() else { return };
        let queues = self.queues.clone();
        let namespace = self.adapter.namespace().to_owned();
        self.telemetry.observable_gauge(
            name,
            "{message}",
            description,
            Arc::new(move || {
                let (publisher, queues, namespace) = (publisher.clone(), queues.clone(), namespace.clone());
                Box::pin(async move {
                    let mut out = Vec::new();
                    let names: Vec<String> = queues.lock().map(|q| q.clone()).unwrap_or_default();
                    for name in names {
                        let Ok(queue) = Queue::with(name.clone(), namespace.clone(), 0) else { continue };
                        if let Ok(size) = publisher.queue_size(&queue, failed).await {
                            out.push((
                                size,
                                vec![
                                    ("messaging.destination.name".to_owned(), name),
                                    ("messaging.destination.namespace".to_owned(), namespace.clone()),
                                ],
                            ));
                        }
                    }
                    out
                })
            }),
        );
    }

    /// Adds a hook run before each job.
    pub fn init(&mut self) -> &mut Hook {
        self.init.push(star());
        self.init.last_mut().expect("just pushed")
    }

    /// Adds a hook run after each successful job.
    pub fn shutdown(&mut self) -> &mut Hook {
        self.shutdown.push(star());
        self.shutdown.last_mut().expect("just pushed")
    }

    /// Adds a hook run on every failure, with `error` injectable.
    pub fn error(&mut self) -> &mut Hook {
        self.errors.push(star());
        self.errors.last_mut().expect("just pushed")
    }

    /// Adds a hook run when a worker starts, with `workerId` injectable.
    pub fn worker_start(&mut self) -> &mut Hook {
        self.worker_start.push(star());
        self.worker_start.last_mut().expect("just pushed")
    }

    /// Adds a hook run when a worker stops.
    pub fn worker_stop(&mut self) -> &mut Hook {
        self.worker_stop.push(star());
        self.worker_stop.last_mut().expect("just pushed")
    }

    /// The worker start hooks.
    pub fn worker_start_hooks(&self) -> &[Hook] {
        &self.worker_start
    }

    /// The worker stop hooks.
    pub fn worker_stop_hooks(&self) -> &[Hook] {
        &self.worker_stop
    }

    /// Stops the consume loops; running handlers finish first.
    pub fn stop(&self) {
        self.adapter.stop();
    }

    fn job_for<'a>(jobs: &'a IndexMap<String, Registered>, default: &'a Job, message: &Message) -> &'a Job {
        std::str::from_utf8(message.queue().as_bytes()).ok().and_then(|q| jobs.get(q)).map_or(default, |r| &r.job)
    }

    async fn run_errors(&self, context: &Arc<Resources>) -> Result<()> {
        for hook in &self.errors {
            hook.run(context, &Array::new()).await?;
        }
        Ok(())
    }

    /// Runs the workers until they are stopped (or, for
    /// [`crate::adapter::Runtime::Drain`], until the queues are empty).
    /// A failure to start runs the error hooks and is returned.
    pub async fn start(self: &Arc<Self>) -> Result<()> {
        let result = self.run_workers().await;
        if let Err(e) = &result {
            self.resources().set("error", Arc::new(e.clone()));
            let _ = self.run_errors(self.resources()).await;
        }
        result
    }

    async fn run_workers(self: &Arc<Self>) -> Result<()> {
        let workers = self.adapter.workers().max(1);
        let results = futures_util::future::join_all((0..workers).map(|id| self.worker(id))).await;
        results.into_iter().collect::<Result<Vec<()>>>().map(|_| ())
    }

    async fn worker(self: &Arc<Self>, id: usize) -> Result<()> {
        let resources = self.resources().clone();
        resources.set("workerId", Arc::new(id.to_string()));
        let consumed = async {
            for hook in &self.worker_start {
                hook.run(&resources, &Array::new()).await?;
            }
            self.consume().await
        }
        .await;
        // Stop hooks run however the worker ended; their failures are ignored.
        for hook in &self.worker_stop {
            let _ = hook.run(&resources, &Array::new()).await;
        }
        self.adapter.consumer().close().await;
        consumed
    }

    async fn consume(self: &Arc<Self>) -> Result<()> {
        if self.jobs.is_empty() {
            return Err(Error::server("At least one job() must be registered before start()"));
        }
        if self.jobs.len() > 1 && self.consumer.is_none() && self.adapter.shares_consumer() {
            return Err(Error::server(
                "Multi-queue workers must pass a callable factory to the Adapter constructor (or Server::consumer()) — a shared Consumer cannot be used across concurrent receive loops",
            ));
        }
        let mut specs = Vec::with_capacity(self.jobs.len());
        for (name, registered) in &self.jobs {
            let consumer = match &self.consumer {
                Some(factory) => factory(name),
                None => self.adapter.create_consumer(name),
            };
            let (coroutines, prefetch) = (registered.coroutines, registered.prefetch);
            if prefetch > 1 && consumer.is_exclusive() {
                return Err(Error::server(format!(
                    "Queue '{name}' is registered with job('{name}', {coroutines}), but its consumer {} drives a single socket that only one coroutine may read at a time. Use one coroutine with prefetch 1 and add replicas for throughput.",
                    consumer.name()
                )));
            }
            if let Some(ceiling) = consumer.in_flight_ceiling().await
                && ceiling <= prefetch as i64
            {
                return Err(Error::server(format!(
                    "Queue '{name}' is registered with job('{name}', {coroutines}), but its consumer {} holds at most {ceiling} message(s) in flight. Waiting retries also count toward this ceiling, so filling it with local deliveries can stop further delivery. Raise the in-flight ceiling (Broker\\Nats: maxAckPending) above {prefetch}, or lower the prefetch limit.",
                    consumer.name()
                )));
            }
            specs.push(Spec {
                queue: Queue::with(name.clone(), self.adapter.namespace(), 0)?,
                coroutines,
                prefetch,
                consumer,
            });
        }
        let (on_message, on_success, on_error) = self.callbacks();
        self.adapter.consume(on_message, on_success, on_error, specs).await
    }

    fn callbacks(self: &Arc<Self>) -> (MessageCallback, SuccessCallback, ErrorCallback) {
        let server = self.clone();
        let on_message: MessageCallback = Arc::new(move |message: Arc<Message>, context: Arc<Resources>| {
            let server = server.clone();
            Box::pin(async move {
                let received = php_std::datetime::microtime_float();
                server.wait_time.record((received - message.timestamp() as f64).max(0.0));
                if let Some(size) = message.size() {
                    server.message_size.record(size as f64);
                }
                let result = server.handle(&message, &context).await;
                server.process_duration.record(php_std::datetime::microtime_float() - received);
                result
            })
        });
        let server = self.clone();
        let on_success: SuccessCallback = Arc::new(move |message: Arc<Message>, context: Arc<Resources>| {
            let server = server.clone();
            Box::pin(async move {
                let job = Self::job_for(&server.jobs, &server.default, &message);
                context.set("message", message.clone());
                server.around(&server.shutdown, job, &context, &message).await
            })
        });
        let server = self.clone();
        let on_error: ErrorCallback =
            Arc::new(move |message: Option<Arc<Message>>, error: Error, context: Arc<Resources>| {
                let server = server.clone();
                Box::pin(async move {
                    context.set("error", Arc::new(error));
                    if let Some(message) = message {
                        context.set("message", message);
                    }
                    server.run_errors(&context).await
                })
            });
        (on_message, on_success, on_error)
    }

    /// The `*` hooks (when the job runs them), then those of the job's groups.
    async fn around(&self, hooks: &[Hook], job: &Job, context: &Arc<Resources>, message: &Message) -> Result<()> {
        if job.runs_hooks() {
            for hook in hooks.iter().filter(|h| h.group_names().contains(&"*")) {
                hook.run(context, message.payload()).await?;
            }
        }
        for group in job.group_names() {
            for hook in hooks.iter().filter(|h| h.group_names().contains(&group)) {
                hook.run(context, message.payload()).await?;
            }
        }
        Ok(())
    }

    async fn handle(&self, message: &Arc<Message>, context: &Arc<Resources>) -> Result<()> {
        let job = Self::job_for(&self.jobs, &self.default, message);
        context.set("message", message.clone());
        self.around(&self.init, job, context, message).await?;
        job.run(context, message.payload()).await
    }
}
