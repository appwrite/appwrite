use std::sync::Arc;
use std::thread;

use parking_lot::Mutex;
use utopia_di::Container;

use super::{
    Adapter, AdapterHost, ErrorCallback, MessageCallback, SuccessCallback, WorkerCallback,
};
use crate::consumer::Consumer;
use crate::error::QueueError;

/// Multi-task worker runtime (Rust replacement for PHP Swoole / Workerman).
///
/// PHP `Utopia\Queue\Adapter\Swoole` spawns worker processes + coroutines.
/// This adapter runs `worker_num` OS threads, each consuming with up to
/// `max_coroutines` in-flight handlers (slot reserved **before** receive).
#[derive(Clone)]
pub struct Swoole {
    host: AdapterHost,
    max_coroutines: usize,
    on_worker_start: Arc<Mutex<Vec<WorkerCallback>>>,
    on_worker_stop: Arc<Mutex<Vec<WorkerCallback>>>,
}

impl std::fmt::Debug for Swoole {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Swoole")
            .field("host", &self.host)
            .field("max_coroutines", &self.max_coroutines)
            .finish_non_exhaustive()
    }
}

impl Swoole {
    pub fn new(
        consumer: impl Consumer + 'static,
        worker_num: usize,
        queue: impl Into<String>,
    ) -> Result<Self, QueueError> {
        Self::new_full(
            Arc::new(consumer),
            worker_num,
            queue,
            "utopia-queue",
            1,
            Container::new(),
        )
    }

    pub fn new_full(
        consumer: Arc<dyn Consumer>,
        worker_num: usize,
        queue: impl Into<String>,
        namespace: impl Into<String>,
        max_coroutines: usize,
        resources: Container,
    ) -> Result<Self, QueueError> {
        Ok(Self {
            host: AdapterHost::new(consumer, worker_num, queue, namespace, resources)?,
            max_coroutines: max_coroutines.max(1),
            on_worker_start: Arc::new(Mutex::new(Vec::new())),
            on_worker_stop: Arc::new(Mutex::new(Vec::new())),
        })
    }

    pub fn with_namespace(self, namespace: impl Into<String>) -> Result<Self, QueueError> {
        let mut this = self;
        this.host.queue.namespace = namespace.into();
        Ok(this)
    }

    pub fn with_max_coroutines(mut self, max_coroutines: usize) -> Self {
        self.max_coroutines = max_coroutines.max(1);
        self
    }

    pub fn max_coroutines(&self) -> usize {
        self.max_coroutines
    }
}

impl Adapter for Swoole {
    fn host(&self) -> &AdapterHost {
        &self.host
    }

    fn start(&self) -> Result<(), QueueError> {
        let mut joins = Vec::new();
        for i in 0..self.host.worker_num {
            let this = self.clone();
            joins.push(thread::spawn(move || {
                let id = i.to_string();
                let starts = this.on_worker_start.lock().clone();
                for cb in starts {
                    cb(&id);
                }
                let stops = this.on_worker_stop.lock().clone();
                for cb in stops {
                    cb(&id);
                }
            }));
        }
        for j in joins {
            let _ = j.join();
        }
        Ok(())
    }

    fn stop(&self) -> Result<(), QueueError> {
        self.host.stop();
        Ok(())
    }

    fn worker_start(&self, callback: WorkerCallback) -> &Self {
        self.on_worker_start.lock().push(callback);
        self
    }

    fn worker_stop(&self, callback: WorkerCallback) -> &Self {
        self.on_worker_stop.lock().push(callback);
        self
    }

    fn consume(
        &self,
        message_callback: MessageCallback,
        success_callback: SuccessCallback,
        error_callback: ErrorCallback,
    ) {
        self.host.consume_concurrent(
            self.max_coroutines,
            message_callback,
            success_callback,
            error_callback,
        );
    }
}

/// Rust-only alias: this runtime is Tokio under the PHP `Swoole` name.
pub type Tokio = Swoole;

/// PHP `Utopia\Queue\Adapter\Workerman` (`max_coroutines = 1`).
#[derive(Clone, Debug)]
pub struct Workerman(pub Swoole);

impl Workerman {
    pub fn new(
        consumer: impl Consumer + 'static,
        worker_num: usize,
        queue: impl Into<String>,
    ) -> Result<Self, QueueError> {
        Ok(Self(
            Swoole::new(consumer, worker_num, queue)?.with_max_coroutines(1),
        ))
    }

    pub fn new_full(
        consumer: Arc<dyn Consumer>,
        worker_num: usize,
        queue: impl Into<String>,
        namespace: impl Into<String>,
        resources: Container,
    ) -> Result<Self, QueueError> {
        Ok(Self(Swoole::new_full(
            consumer, worker_num, queue, namespace, 1, resources,
        )?))
    }
}

impl Adapter for Workerman {
    fn host(&self) -> &AdapterHost {
        self.0.host()
    }

    fn start(&self) -> Result<(), QueueError> {
        self.0.start()
    }

    fn stop(&self) -> Result<(), QueueError> {
        self.0.stop()
    }

    fn worker_start(&self, callback: WorkerCallback) -> &Self {
        self.0.worker_start(callback);
        self
    }

    fn worker_stop(&self, callback: WorkerCallback) -> &Self {
        self.0.worker_stop(callback);
        self
    }

    fn consume(
        &self,
        message_callback: MessageCallback,
        success_callback: SuccessCallback,
        error_callback: ErrorCallback,
    ) {
        self.0
            .consume(message_callback, success_callback, error_callback);
    }
}
