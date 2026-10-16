use crate::adapter::{Adapter, WorkerCallback};

/// In-process adapter: `start` / `on_job` invoke the callback immediately.
///
/// PHP `Utopia\CLI\Adapters\Generic`.
pub struct Generic {
    /// PHP public `$workerNum`.
    pub worker_num: i32,
    on_worker_start: Option<WorkerCallback>,
    on_worker_stop: Option<WorkerCallback>,
}

impl std::fmt::Debug for Generic {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Generic")
            .field("worker_num", &self.worker_num)
            .finish_non_exhaustive()
    }
}

impl Default for Generic {
    fn default() -> Self {
        Self::new()
    }
}

impl Generic {
    /// PHP `new Generic()` (`workerNum = 0`).
    pub fn new() -> Self {
        Self::with_workers(0)
    }

    /// PHP `new Generic($workerNum)`.
    pub fn with_workers(worker_num: i32) -> Self {
        Self {
            worker_num,
            on_worker_start: None,
            on_worker_stop: None,
        }
    }
}

impl Adapter for Generic {
    fn worker_num(&self) -> i32 {
        self.worker_num
    }

    fn start(&mut self, callback: &mut dyn FnMut()) {
        callback();
    }

    fn stop(&mut self) {}

    fn on_worker_start(&mut self, callback: WorkerCallback) {
        self.on_worker_start = Some(callback);
    }

    fn on_worker_stop(&mut self, callback: WorkerCallback) {
        self.on_worker_stop = Some(callback);
    }

    fn on_job(&mut self, callback: &mut dyn FnMut()) {
        callback();
    }
}
