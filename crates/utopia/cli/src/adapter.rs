//! Adapters: how a CLI application's work is run.

use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::{Arc, Mutex};

use utopia_servers::BoxFuture;

/// The work an adapter runs: called with the worker's number (0 for the
/// only one).
pub type Job<'a> = &'a (dyn Fn(usize) -> BoxFuture<'a, ()> + Send + Sync);

/// A callback an adapter calls with a worker's number.
pub type WorkerCallback = Arc<dyn Fn(usize) + Send + Sync>;

/// Runs a CLI application's work: once in the calling task, or in several
/// workers.
///
/// PHP: `Utopia\CLI\Adapter`. Implement it to run tasks some other way.
pub trait Adapter: Send + Sync {
    /// The number of workers it runs.
    ///
    /// PHP: `$workerNum`.
    fn workers(&self) -> usize;

    /// Runs `job` and completes when every run of it has.
    ///
    /// PHP: `start($callback)`.
    fn start<'a>(&'a self, job: Job<'a>) -> BoxFuture<'a, ()>;

    /// Asks the workers to stop.
    ///
    /// PHP: `stop()`.
    fn stop(&self);

    /// Registers a callback run when a worker starts.
    ///
    /// PHP: `onWorkerStart($callback)`.
    fn on_worker_start(&self, callback: WorkerCallback);

    /// Registers a callback run when a worker stops.
    ///
    /// PHP: `onWorkerStop($callback)`.
    fn on_worker_stop(&self, callback: WorkerCallback);

    /// Runs a job.
    ///
    /// PHP: `onJob($callback)`.
    fn on_job(&self, callback: &mut dyn FnMut());
}

/// Runs the work once, in the calling task: worker callbacks are ignored
/// and [`Adapter::on_job`] runs the job at once.
///
/// PHP: `Utopia\CLI\Adapters\Generic`.
///
/// ```
/// use utopia_cli::{Adapter, Generic};
///
/// let adapter = Generic::new();
/// assert_eq!(adapter.workers(), 0);
/// let mut ran = false;
/// adapter.on_job(&mut || ran = true);
/// assert!(ran);
/// ```
///
/// ```php
/// use Utopia\CLI\Adapters\Generic;
///
/// $adapter = new Generic();
/// echo $adapter->workerNum, "\n"; // 0
/// $adapter->onJob(fn () => print("ran\n")); // ran
/// ```
#[derive(Debug, Default, Clone, Copy)]
pub struct Generic;

impl Generic {
    /// The adapter.
    pub fn new() -> Self {
        Generic
    }
}

impl Adapter for Generic {
    fn workers(&self) -> usize {
        0
    }

    fn start<'a>(&'a self, job: Job<'a>) -> BoxFuture<'a, ()> {
        job(0)
    }

    fn stop(&self) {}

    fn on_worker_start(&self, _callback: WorkerCallback) {}

    fn on_worker_stop(&self, _callback: WorkerCallback) {}

    fn on_job(&self, callback: &mut dyn FnMut()) {
        callback();
    }
}

/// Runs the work in `n` concurrent workers (at least one), each calling its
/// worker-start callbacks, the job, then its worker-stop callbacks.
///
/// PHP: `Utopia\CLI\Adapters\Swoole`, which forks a process per worker; here
/// the workers are futures run together in the calling task.
///
/// ```
/// # futures_util::FutureExt::now_or_never(async {
/// use std::sync::atomic::{AtomicUsize, Ordering};
/// use utopia_cli::{Adapter, Workers};
///
/// let runs = AtomicUsize::new(0);
/// let adapter = Workers::new(3);
/// adapter.start(&|_worker| Box::pin(async { runs.fetch_add(1, Ordering::SeqCst); })).await;
/// assert_eq!(runs.load(Ordering::SeqCst), 3);
/// # }).unwrap();
/// ```
///
/// ```php
/// use Utopia\CLI\Adapters\Swoole;
///
/// $adapter = new Swoole(3);
/// echo $adapter->workerNum, "\n"; // 3
/// ```
#[derive(Default)]
pub struct Workers {
    workers: usize,
    stopped: AtomicBool,
    started: Mutex<Vec<WorkerCallback>>,
    stopping: Mutex<Vec<WorkerCallback>>,
}

impl std::fmt::Debug for Workers {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Workers").field("workers", &self.workers).field("stopped", &self.stopped).finish()
    }
}

impl Workers {
    /// An adapter running `workers` workers.
    pub fn new(workers: usize) -> Self {
        Self { workers, ..Self::default() }
    }

    /// Whether [`Adapter::stop`] was called.
    pub fn is_stopped(&self) -> bool {
        self.stopped.load(Ordering::Relaxed)
    }

    fn callbacks(list: &Mutex<Vec<WorkerCallback>>) -> Vec<WorkerCallback> {
        list.lock().unwrap_or_else(|e| e.into_inner()).clone()
    }
}

impl Adapter for Workers {
    fn workers(&self) -> usize {
        self.workers
    }

    fn start<'a>(&'a self, job: Job<'a>) -> BoxFuture<'a, ()> {
        Box::pin(async move {
            let runs = (0..self.workers.max(1)).map(|worker| async move {
                for callback in Self::callbacks(&self.started) {
                    callback(worker);
                }
                job(worker).await;
                for callback in Self::callbacks(&self.stopping) {
                    callback(worker);
                }
            });
            futures_util::future::join_all(runs).await;
        })
    }

    fn stop(&self) {
        self.stopped.store(true, Ordering::Relaxed);
    }

    fn on_worker_start(&self, callback: WorkerCallback) {
        self.started.lock().unwrap_or_else(|e| e.into_inner()).push(callback);
    }

    fn on_worker_stop(&self, callback: WorkerCallback) {
        self.stopping.lock().unwrap_or_else(|e| e.into_inner()).push(callback);
    }

    fn on_job(&self, callback: &mut dyn FnMut()) {
        callback();
    }
}
