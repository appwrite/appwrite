use std::time::Duration;

use async_trait::async_trait;
use parking_lot::Mutex;
use tokio::sync::Notify;
use tokio::time::timeout;

use crate::adapter::Adapter;
use crate::{Connection, PoolError};

/// Shortest wait PHP Swoole will honour without treating it as unbounded.
const POLL: Duration = Duration::from_millis(1);

/// PHP `Utopia\Pools\Adapter\Swoole`.
///
/// Concurrent idle list. Waiters park on a Tokio `Notify` instead of a Swoole
/// coroutine channel. Construction does not need a runtime; `pop` does.
#[derive(Debug, Default)]
pub struct Swoole<T> {
    idle: Mutex<Vec<Connection<T>>>,
    notify: Notify,
    lock: Mutex<()>,
}

impl<T> Swoole<T> {
    #[must_use]
    pub fn new() -> Self {
        Self {
            idle: Mutex::new(Vec::new()),
            notify: Notify::new(),
            lock: Mutex::new(()),
        }
    }
}

#[async_trait]
impl<T: Send + 'static> Adapter<T> for Swoole<T> {
    fn initialize(&self, _size: usize) {
        self.idle.lock().clear();
    }

    fn push(&self, connection: Connection<T>) {
        self.idle.lock().push(connection);
        self.notify.notify_one();
    }

    async fn pop(&self, wait: Duration) -> Option<Connection<T>> {
        let wait = if wait.is_zero() { POLL } else { wait };
        let deadline = tokio::time::Instant::now() + wait;

        loop {
            if let Some(connection) = self.idle.lock().pop() {
                return Some(connection);
            }

            let remaining = deadline.saturating_duration_since(tokio::time::Instant::now());
            if remaining.is_zero() {
                return self.idle.lock().pop();
            }

            let notified = self.notify.notified();
            if let Some(connection) = self.idle.lock().pop() {
                return Some(connection);
            }

            if timeout(remaining, notified).await.is_err() {
                return self.idle.lock().pop();
            }
        }
    }

    fn count(&self) -> usize {
        self.idle.lock().len()
    }

    fn synchronized(&self, callback: Box<dyn FnOnce() + Send>) -> Result<(), PoolError> {
        let _guard = self.lock.lock();
        callback();
        Ok(())
    }
}
