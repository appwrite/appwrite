use std::sync::Arc;

use parking_lot::Mutex;
use utopia_di::Container;

use super::{
    Adapter, AdapterHost, ErrorCallback, MessageCallback, SuccessCallback, WorkerCallback,
};
use crate::consumer::Consumer;
use crate::error::QueueError;

/// Run-to-completion adapter (PHP `Utopia\Queue\Adapter\KubernetesJob`).
///
/// Drains the queue and returns so a Kubernetes Job can complete. One process
/// is one worker. PHP shells out to Swoole coroutines when the extension is
/// loaded; this port processes each message on the calling thread (equivalent
/// isolation via per-message DI context).
#[derive(Clone)]
pub struct KubernetesJob {
    host: AdapterHost,
    on_worker_start: Arc<Mutex<Vec<WorkerCallback>>>,
    on_worker_stop: Arc<Mutex<Vec<WorkerCallback>>>,
}

impl std::fmt::Debug for KubernetesJob {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("KubernetesJob")
            .field("host", &self.host)
            .finish_non_exhaustive()
    }
}

impl KubernetesJob {
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
            Container::new(),
        )
    }

    pub fn new_full(
        consumer: Arc<dyn Consumer>,
        worker_num: usize,
        queue: impl Into<String>,
        namespace: impl Into<String>,
        resources: Container,
    ) -> Result<Self, QueueError> {
        Ok(Self {
            host: AdapterHost::new(consumer, worker_num, queue, namespace, resources)?,
            on_worker_start: Arc::new(Mutex::new(Vec::new())),
            on_worker_stop: Arc::new(Mutex::new(Vec::new())),
        })
    }
}

impl Adapter for KubernetesJob {
    fn host(&self) -> &AdapterHost {
        &self.host
    }

    fn start(&self) -> Result<(), QueueError> {
        let starts = self.on_worker_start.lock().clone();
        let stops = self.on_worker_stop.lock().clone();
        let result = std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
            for cb in &starts {
                cb("0");
            }
        }));
        for cb in stops {
            cb("0");
        }
        match result {
            Ok(()) => Ok(()),
            Err(_) => Err(QueueError::Other(
                "KubernetesJob workerStart callback panicked".into(),
            )),
        }
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
        self.host
            .consume_drain(message_callback, success_callback, error_callback);
    }
}
