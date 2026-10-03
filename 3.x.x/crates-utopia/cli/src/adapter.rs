/// Runtime that executes the CLI dispatch callback.
///
/// PHP `Utopia\CLI\Adapter`.
pub trait Adapter: Send + std::fmt::Debug {
    /// PHP public `$workerNum`.
    fn worker_num(&self) -> i32;

    /// PHP `$adapter->start($callback)`.
    fn start(&mut self, callback: &mut dyn FnMut());

    /// PHP `$adapter->stop()`.
    fn stop(&mut self);

    /// PHP `$adapter->onWorkerStart($callback)`.
    fn on_worker_start(&mut self, callback: WorkerCallback);

    /// PHP `$adapter->onWorkerStop($callback)`.
    fn on_worker_stop(&mut self, callback: WorkerCallback);

    /// PHP `$adapter->onJob($callback)`.
    fn on_job(&mut self, callback: &mut dyn FnMut());
}

/// Worker lifecycle callback. PHP receives `($pool, $workerId)`; Rust gets the worker id.
pub type WorkerCallback = Box<dyn Fn(String) + Send + Sync>;
