use crate::adapter::{Adapter, WorkerCallback};

/// Multi-worker adapter matching PHP `Utopia\CLI\Adapters\Swoole`.
///
/// PHP drives a Swoole process pool. This port runs the start callback once
/// per worker on the current thread (no Swoole / coroutine runtime). Use
/// [`crate::adapters::Generic`] for single-process CLIs.
pub struct Swoole {
    /// PHP public `$workerNum`.
    pub worker_num: i32,
    on_worker_start: Option<WorkerCallback>,
    on_worker_stop: Option<WorkerCallback>,
    stopped: bool,
}

impl std::fmt::Debug for Swoole {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Swoole")
            .field("worker_num", &self.worker_num)
            .field("stopped", &self.stopped)
            .finish_non_exhaustive()
    }
}

impl Swoole {
    /// PHP `new Swoole($workerNum = 0)`.
    pub fn new(worker_num: i32) -> Self {
        Self {
            worker_num,
            on_worker_start: None,
            on_worker_stop: None,
            stopped: false,
        }
    }

    /// PHP `getNative()` - Swoole returns the `Process\Pool`; here the worker count.
    pub fn get_native(&self) -> i32 {
        self.worker_num
    }
}

impl Adapter for Swoole {
    fn worker_num(&self) -> i32 {
        self.worker_num
    }

    fn start(&mut self, callback: &mut dyn FnMut()) {
        self.stopped = false;
        let n = if self.worker_num <= 0 {
            1
        } else {
            self.worker_num
        };
        for id in 0..n {
            if self.stopped {
                break;
            }
            if let Some(on_start) = &self.on_worker_start {
                on_start(id.to_string());
            }
            callback();
            if let Some(on_stop) = &self.on_worker_stop {
                on_stop(id.to_string());
            }
        }
    }

    fn stop(&mut self) {
        self.stopped = true;
    }

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
