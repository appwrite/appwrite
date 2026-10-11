//! Background dispatch on top of a synchronous publisher.

use std::sync::atomic::{AtomicBool, AtomicUsize, Ordering};
use std::sync::{Arc, Mutex as StdMutex};
use std::time::{Duration, Instant};

use php_std::Array;
use tokio::sync::{Mutex, mpsc};
use tokio::task::JoinHandle;

use crate::connection::BoxFuture;
use crate::contract::{AsyncPublisher, Publisher};
use crate::error::{Error, Result};
use crate::queue::Queue;
use crate::telemetry::Telemetry;

enum Task {
    Publish { queue: Queue, payload: Array, enqueued: Instant },
    Stop,
}

/// Where dispatch failures are written (stderr by default): there is no
/// producer left to report them to.
pub type Log = Arc<dyn Fn(&str) + Send + Sync>;

/// Wraps a synchronous [`Publisher`] and adds background dispatch
/// (`Utopia\Queue\Broker\Background`): [`AsyncPublisher::enqueue`] puts the
/// message on a bounded buffer and returns; reader tasks publish from it.
///
/// The buffer's capacity is the back pressure: once full, `enqueue` waits
/// for a slot up to `timeout` seconds (negative: forever) and then fails with
/// [`Error::BufferFull`]. With a batch interval and size, consecutive
/// messages for one queue are coalesced into `publish_many` calls, a partial
/// batch leaving when its oldest message reaches the interval. Before
/// [`Background::start`] (and after [`Background::shutdown`]) `enqueue`
/// publishes synchronously. More than one reader gives up FIFO order.
///
/// ```no_run
/// use std::sync::Arc;
/// use utopia_queue::broker::{Background, Redis};
/// use utopia_queue::connection::Redis as Connection;
/// use utopia_queue::{AsyncPublisher, Queue};
///
/// # async fn run() -> Result<(), utopia_queue::Error> {
/// let redis = Arc::new(Redis::new(Arc::new(Connection::new("localhost", 6379)), Arc::new(Connection::new("localhost", 6379))));
/// let background = Background::new(redis, 512, 1, -1.0, None)?;
/// background.start()?;
/// background.enqueue(&Queue::new("audits")?, utopia_queue::payload(&serde_json::json!({"event": "login"}))?).await?;
/// background.shutdown().await;
/// # Ok(()) }
/// ```
///
/// ```php
/// use Utopia\Queue\Broker\Background;
/// use Utopia\Queue\Broker\Redis;
/// use Utopia\Queue\Connection\Redis as Connection;
/// use Utopia\Queue\Queue;
///
/// $background = new Background(new Redis(new Connection('redis'), new Connection('redis')));
/// $background->enqueue(new Queue('compat-doc-audits'), ['event' => 'login']); // not started: publishes now
/// echo $background->getQueueSize(new Queue('compat-doc-audits')), "\n"; // 1
/// new Connection('redis')->remove('utopia-queue.queue.compat-doc-audits');
/// ```
pub struct Background<P> {
    publisher: Arc<P>,
    capacity: usize,
    coroutines: usize,
    timeout: f64,
    batch: Option<(f64, usize)>,
    sender: mpsc::Sender<Task>,
    receiver: Arc<Mutex<mpsc::Receiver<Task>>>,
    started: AtomicBool,
    active: AtomicUsize,
    readers: StdMutex<Vec<JoinHandle<()>>>,
    log: Log,
}

impl<P: Publisher + 'static> Background<P> {
    /// A background publisher: buffer `capacity` (at least one), `coroutines`
    /// readers (at least one), enqueue `timeout` in seconds, and an optional
    /// batch `(interval seconds, size)`, both positive.
    pub fn new(
        publisher: Arc<P>,
        capacity: usize,
        coroutines: usize,
        timeout: f64,
        batch: Option<(f64, usize)>,
    ) -> Result<Self> {
        if let Some((interval, size)) = batch {
            if interval <= 0.0 {
                return Err(Error::InvalidArgument("maxBatchInterval must be greater than zero.".into()));
            }
            if size < 1 {
                return Err(Error::InvalidArgument("maxBatchSize must be at least one.".into()));
            }
        }
        let capacity = capacity.max(1);
        let (sender, receiver) = mpsc::channel(capacity);
        Ok(Self {
            publisher,
            capacity,
            coroutines: coroutines.max(1),
            timeout,
            batch,
            sender,
            receiver: Arc::new(Mutex::new(receiver)),
            started: AtomicBool::new(false),
            active: AtomicUsize::new(0),
            readers: StdMutex::new(Vec::new()),
            log: Arc::new(|line: &str| eprintln!("{line}")),
        })
    }

    /// Where dispatch failures are written.
    pub fn with_log(mut self, log: Log) -> Self {
        self.log = log;
        self
    }

    /// Reports the buffer depth as the gauge `messaging.publisher.buffer.depth`.
    pub fn with_telemetry(self, telemetry: &dyn Telemetry) -> Self {
        let sender = self.sender.clone();
        let capacity = self.capacity;
        telemetry.observable_gauge(
            "messaging.publisher.buffer.depth",
            "{message}",
            "Publishes buffered awaiting background dispatch.",
            Arc::new(move || {
                let depth = (capacity - sender.capacity()) as i64;
                Box::pin(async move { vec![(depth, Vec::new())] })
            }),
        );
        self
    }

    /// Messages buffered and not dispatched yet.
    pub fn depth(&self) -> usize {
        self.capacity - self.sender.capacity()
    }

    /// Spawns the reader tasks. Must run inside a tokio runtime.
    pub fn start(&self) -> Result<()> {
        if self.started.load(Ordering::SeqCst) {
            return Ok(());
        }
        let Ok(handle) = tokio::runtime::Handle::try_current() else {
            return Err(Error::Runtime("Background publisher must be started inside a coroutine runtime.".into()));
        };
        self.started.store(true, Ordering::SeqCst);
        let mut readers = self.readers.lock().unwrap_or_else(|e| e.into_inner());
        for _ in 0..self.coroutines {
            let (publisher, receiver, batch, log) =
                (self.publisher.clone(), self.receiver.clone(), self.batch, self.log.clone());
            readers.push(handle.spawn(read(publisher, receiver, batch, log)));
        }
        Ok(())
    }

    /// Stops accepting background work, lets every accepted message be
    /// published, and waits for the readers to finish.
    pub async fn shutdown(&self) {
        if !self.started.swap(false, Ordering::SeqCst) {
            return;
        }
        while self.active.load(Ordering::SeqCst) > 0 {
            tokio::time::sleep(Duration::from_millis(1)).await;
        }
        let readers = std::mem::take(&mut *self.readers.lock().unwrap_or_else(|e| e.into_inner()));
        for _ in 0..readers.len() {
            let _ = self.sender.send(Task::Stop).await;
        }
        for reader in readers {
            let _ = reader.await;
        }
    }
}

async fn read<P: Publisher>(
    publisher: Arc<P>,
    receiver: Arc<Mutex<mpsc::Receiver<Task>>>,
    batch: Option<(f64, usize)>,
    log: Log,
) {
    let mut pending: Option<Task> = None;
    loop {
        let task = match pending.take() {
            Some(t) => t,
            None => match receiver.lock().await.recv().await {
                Some(t) => t,
                None => return,
            },
        };
        let Task::Publish { queue, payload, enqueued } = task else { return };
        let Some((interval, size)) = batch else {
            dispatch(publisher.as_ref(), &queue, vec![payload], false, &log).await;
            continue;
        };
        let deadline = enqueued + Duration::from_secs_f64(interval);
        let mut payloads = vec![payload];
        let mut stopping = false;
        while payloads.len() < size {
            let now = Instant::now();
            if now >= deadline {
                break;
            }
            let next = tokio::time::timeout(deadline - now, async { receiver.lock().await.recv().await }).await;
            match next {
                Err(_) | Ok(None) => break,
                Ok(Some(Task::Stop)) => {
                    stopping = true;
                    break;
                }
                Ok(Some(Task::Publish { queue: q, payload, enqueued })) => {
                    if q.name() != queue.name() || q.namespace() != queue.namespace() {
                        pending = Some(Task::Publish { queue: q, payload, enqueued });
                        break;
                    }
                    payloads.push(payload);
                }
            }
        }
        dispatch(publisher.as_ref(), &queue, payloads, true, &log).await;
        if stopping {
            return;
        }
    }
}

async fn dispatch<P: Publisher>(publisher: &P, queue: &Queue, mut payloads: Vec<Array>, batched: bool, log: &Log) {
    let result = if batched {
        publisher.publish_many(queue, payloads).await
    } else {
        publisher.publish(queue, payloads.pop().unwrap_or_default()).await
    };
    match result {
        Ok(true) => {}
        Ok(false) => log("Background queue publisher failed to publish a message."),
        Err(e) => log(&format!("Uncaught error while publishing queue message: {e}")),
    }
}

impl<P: Publisher + 'static> AsyncPublisher for Background<P> {
    fn enqueue<'a>(&'a self, queue: &'a Queue, payload: Array) -> BoxFuture<'a, Result<()>> {
        Box::pin(async move {
            if !self.started.load(Ordering::SeqCst) {
                self.publisher.publish(queue, payload).await?;
                return Ok(());
            }
            self.active.fetch_add(1, Ordering::SeqCst);
            let task = Task::Publish { queue: queue.clone(), payload, enqueued: Instant::now() };
            let accepted = if self.timeout < 0.0 {
                self.sender.send(task).await.is_ok()
            } else {
                self.sender.send_timeout(task, Duration::from_secs_f64(self.timeout)).await.is_ok()
            };
            self.active.fetch_sub(1, Ordering::SeqCst);
            if !accepted {
                return Err(Error::BufferFull("Publisher buffer full; enqueue timed out.".into()));
            }
            Ok(())
        })
    }
}

impl<P: Publisher + 'static> Publisher for Background<P> {
    fn publish<'a>(&'a self, queue: &'a Queue, payload: Array) -> BoxFuture<'a, Result<bool>> {
        self.publisher.publish(queue, payload)
    }

    fn publish_many<'a>(&'a self, queue: &'a Queue, payloads: Vec<Array>) -> BoxFuture<'a, Result<bool>> {
        self.publisher.publish_many(queue, payloads)
    }

    fn retry<'a>(&'a self, queue: &'a Queue, limit: Option<i64>) -> BoxFuture<'a, Result<()>> {
        self.publisher.retry(queue, limit)
    }

    fn queue_size<'a>(&'a self, queue: &'a Queue, failed: bool) -> BoxFuture<'a, Result<i64>> {
        self.publisher.queue_size(queue, failed)
    }

    fn pending_count<'a>(&'a self, queue: &'a Queue) -> BoxFuture<'a, Result<i64>> {
        self.publisher.pending_count(queue)
    }

    fn failed_count<'a>(&'a self, queue: &'a Queue) -> BoxFuture<'a, Result<i64>> {
        self.publisher.failed_count(queue)
    }
}
