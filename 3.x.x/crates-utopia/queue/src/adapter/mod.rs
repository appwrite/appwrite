use std::io::{self, Write};
use std::sync::atomic::{AtomicBool, AtomicI64, AtomicU64, Ordering};
use std::sync::Arc;
use std::thread;
use std::time::Duration;

use parking_lot::Mutex;
use utopia_di::Container;

use crate::consumer::Consumer;
use crate::error::QueueError;
use crate::message::Message;
use crate::queue::Queue;

pub mod kubernetes_job;
pub mod tokio_runtime;

pub use kubernetes_job::KubernetesJob;
pub use tokio_runtime::{Swoole, Workerman};

/// PHP `Adapter::RECEIVE_TIMEOUT`.
pub const RECEIVE_TIMEOUT: i64 = 2;
/// PHP `Adapter::RECEIVE_BACKOFF` seconds.
pub const RECEIVE_BACKOFF: u64 = 1;

pub type MessageCallback = Arc<dyn Fn(&Message) -> Result<(), QueueError> + Send + Sync>;
pub type SuccessCallback = Arc<dyn Fn(&Message) -> Result<(), QueueError> + Send + Sync>;
pub type ErrorCallback =
    Arc<dyn Fn(Option<&Message>, &QueueError) -> Result<(), QueueError> + Send + Sync>;
pub type WorkerCallback = Arc<dyn Fn(&str) + Send + Sync>;

thread_local! {
    static CURRENT_CONTEXT: std::cell::RefCell<Option<Container>> =
        const { std::cell::RefCell::new(None) };
}

/// Where [`AdapterHost::report_unreported`] writes.
pub trait TraceSink: Send + Sync {
    fn write_trace(&self, line: &str);
}

/// PHP `STDERR` / `php://stderr`.
#[derive(Debug, Default, Clone, Copy)]
pub struct StderrTrace;

impl TraceSink for StderrTrace {
    fn write_trace(&self, line: &str) {
        let _ = io::stderr().write_all(line.as_bytes());
    }
}

/// In-memory sink so tests can assert the last-resort trace.
#[derive(Debug, Default, Clone)]
pub struct BufferTrace {
    pub buf: Arc<Mutex<Vec<u8>>>,
}

impl BufferTrace {
    pub fn new() -> Self {
        Self::default()
    }

    pub fn contents(&self) -> String {
        String::from_utf8_lossy(&self.buf.lock()).into_owned()
    }
}

impl TraceSink for BufferTrace {
    fn write_trace(&self, line: &str) {
        self.buf.lock().extend_from_slice(line.as_bytes());
    }
}

/// Shared consume/process state. PHP abstract `Utopia\Queue\Adapter`.
#[derive(Clone)]
pub struct AdapterHost {
    pub consumer: Arc<dyn Consumer>,
    pub worker_num: usize,
    pub queue: Queue,
    resources: Container,
    stopped: Arc<AtomicBool>,
    receive_timeout: Arc<AtomicI64>,
    receive_backoff_ms: Arc<AtomicU64>,
    trace: Arc<Mutex<Arc<dyn TraceSink>>>,
}

impl std::fmt::Debug for AdapterHost {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("AdapterHost")
            .field("worker_num", &self.worker_num)
            .field("queue", &self.queue)
            .field("stopped", &self.stopped.load(Ordering::Relaxed))
            .finish_non_exhaustive()
    }
}

pub fn current_context() -> Option<Container> {
    CURRENT_CONTEXT.with(|c| c.borrow().clone())
}

impl AdapterHost {
    pub fn new(
        consumer: Arc<dyn Consumer>,
        worker_num: usize,
        queue: impl Into<String>,
        namespace: impl Into<String>,
        resources: Container,
    ) -> Result<Self, QueueError> {
        Ok(Self {
            queue: Queue::with_namespace(queue, namespace)?,
            consumer,
            worker_num: worker_num.max(1),
            resources,
            stopped: Arc::new(AtomicBool::new(false)),
            receive_timeout: Arc::new(AtomicI64::new(RECEIVE_TIMEOUT)),
            receive_backoff_ms: Arc::new(AtomicU64::new(RECEIVE_BACKOFF * 1000)),
            trace: Arc::new(Mutex::new(Arc::new(StderrTrace))),
        })
    }

    pub fn resources(&self) -> &Container {
        &self.resources
    }

    pub fn context(&self) -> Container {
        current_context().unwrap_or_else(|| Container::child(&self.resources))
    }

    pub fn set_context(&self, context: Container) {
        CURRENT_CONTEXT.with(|c| {
            *c.borrow_mut() = Some(context);
        });
    }

    pub fn clear_context(&self) {
        CURRENT_CONTEXT.with(|c| {
            *c.borrow_mut() = None;
        });
    }

    pub fn is_stopped(&self) -> bool {
        self.stopped.load(Ordering::SeqCst)
    }

    pub fn stop(&self) {
        self.stopped.store(true, Ordering::SeqCst);
    }

    pub fn reset_stopped(&self) {
        self.stopped.store(false, Ordering::SeqCst);
    }

    pub fn set_receive_timeout(&self, seconds: i64) {
        self.receive_timeout.store(seconds, Ordering::SeqCst);
    }

    pub fn receive_timeout(&self) -> i64 {
        self.receive_timeout.load(Ordering::SeqCst)
    }

    /// PHP `RECEIVE_BACKOFF` (seconds). `0` skips the pause (tests).
    pub fn set_receive_backoff(&self, seconds: u64) {
        self.receive_backoff_ms
            .store(seconds.saturating_mul(1000), Ordering::SeqCst);
    }

    pub fn set_trace_sink(&self, sink: Arc<dyn TraceSink>) {
        *self.trace.lock() = sink;
    }

    pub fn next_message(&self, error_callback: &ErrorCallback) -> Option<Message> {
        match self.consumer.receive(&self.queue, self.receive_timeout()) {
            Ok(msg) => msg,
            Err(error) => {
                if let Err(report_failure) = error_callback(None, &error) {
                    self.report_unreported(&error, &report_failure, None);
                }
                let backoff = self.receive_backoff_ms.load(Ordering::SeqCst);
                if backoff > 0 {
                    thread::sleep(Duration::from_millis(backoff));
                }
                None
            }
        }
    }

    pub fn process(
        &self,
        message: &Message,
        message_callback: &MessageCallback,
        success_callback: &SuccessCallback,
        error_callback: &ErrorCallback,
    ) {
        let ctx = Container::child(&self.resources);
        self.set_context(ctx);
        let result = message_callback(message);
        match result {
            Ok(()) => {
                if let Err(error) = self.consumer.commit(&self.queue, message) {
                    self.invoke_error(Some(message), &error, error_callback);
                    self.clear_context();
                    return;
                }
                if let Err(error) = success_callback(message) {
                    let _ = self.consumer.reject(&self.queue, message);
                    self.invoke_error(Some(message), &error, error_callback);
                }
            }
            Err(error) => {
                let _ = self.consumer.reject(&self.queue, message);
                self.invoke_error(Some(message), &error, error_callback);
            }
        }
        self.clear_context();
    }

    fn invoke_error(
        &self,
        message: Option<&Message>,
        error: &QueueError,
        error_callback: &ErrorCallback,
    ) {
        if let Err(report_failure) = error_callback(message, error) {
            self.report_unreported(error, &report_failure, message);
        }
    }

    /// Last-resort stderr trace when the error hook also fails.
    pub fn report_unreported(
        &self,
        error: &QueueError,
        report_failure: &QueueError,
        message: Option<&Message>,
    ) {
        let who = message.map_or_else(
            || "receive".to_owned(),
            |m| format!("message {}", m.get_pid()),
        );
        let line = format!(
            "[queue] {who} failed and its error report failed too: {error} (adapter.rs:0) | report: {report_failure}\n"
        );
        self.trace.lock().write_trace(&line);
    }

    /// Long-running consume loop (PHP default `Adapter::consume`).
    pub fn consume_loop(
        &self,
        message_callback: MessageCallback,
        success_callback: SuccessCallback,
        error_callback: ErrorCallback,
    ) {
        self.reset_stopped();
        while !self.is_stopped() {
            let Some(message) = self.next_message(&error_callback) else {
                continue;
            };
            self.process(
                &message,
                &message_callback,
                &success_callback,
                &error_callback,
            );
        }
    }

    /// Drain until a receive times out (PHP `KubernetesJob::consume`).
    pub fn consume_drain(
        &self,
        message_callback: MessageCallback,
        success_callback: SuccessCallback,
        error_callback: ErrorCallback,
    ) {
        self.reset_stopped();
        while !self.is_stopped() {
            match self.consumer.receive(&self.queue, self.receive_timeout()) {
                Ok(Some(message)) => {
                    self.process(
                        &message,
                        &message_callback,
                        &success_callback,
                        &error_callback,
                    );
                }
                Ok(None) => break,
                Err(error) => {
                    self.invoke_error(None, &error, &error_callback);
                    break;
                }
            }
        }
    }

    /// Concurrent consume: reserve a slot **before** receive (PHP `Swoole::consume`).
    pub fn consume_concurrent(
        &self,
        max_coroutines: usize,
        message_callback: MessageCallback,
        success_callback: SuccessCallback,
        error_callback: ErrorCallback,
    ) {
        self.reset_stopped();
        let max = max_coroutines.max(1);
        let slots = Arc::new(tokio::sync::Semaphore::new(max));
        let mut joins = Vec::new();
        while !self.is_stopped() {
            let permit = loop {
                if self.is_stopped() {
                    wait_joins(&mut joins);
                    return;
                }
                match slots.clone().try_acquire_owned() {
                    Ok(p) => break p,
                    Err(_) => thread::sleep(Duration::from_millis(5)),
                }
            };
            let Some(message) = self.next_message(&error_callback) else {
                drop(permit);
                continue;
            };
            let host = self.clone();
            let message_callback = message_callback.clone();
            let success_callback = success_callback.clone();
            let error_callback = error_callback.clone();
            joins.push(thread::spawn(move || {
                host.process(
                    &message,
                    &message_callback,
                    &success_callback,
                    &error_callback,
                );
                drop(permit);
            }));
        }
        wait_joins(&mut joins);
    }
}

fn wait_joins(joins: &mut Vec<thread::JoinHandle<()>>) {
    for j in joins.drain(..) {
        let _ = j.join();
    }
}

/// Worker runtime: start/stop + consume strategy.
pub trait Adapter: Send + Sync + Clone {
    fn host(&self) -> &AdapterHost;

    fn start(&self) -> Result<(), QueueError>;
    fn stop(&self) -> Result<(), QueueError>;

    fn worker_start(&self, callback: WorkerCallback) -> &Self;
    fn worker_stop(&self, callback: WorkerCallback) -> &Self;

    fn consume(
        &self,
        message_callback: MessageCallback,
        success_callback: SuccessCallback,
        error_callback: ErrorCallback,
    ) {
        self.host()
            .consume_loop(message_callback, success_callback, error_callback);
    }

    fn resources(&self) -> &Container {
        self.host().resources()
    }

    fn context(&self) -> Container {
        self.host().context()
    }

    fn queue(&self) -> &Queue {
        &self.host().queue
    }

    fn consumer(&self) -> &Arc<dyn Consumer> {
        &self.host().consumer
    }
}
