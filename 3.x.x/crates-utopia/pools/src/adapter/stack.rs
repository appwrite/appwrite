use std::time::Duration;

use async_trait::async_trait;
use parking_lot::Mutex;

use crate::adapter::Adapter;
use crate::{Connection, PoolError};

/// PHP `Utopia\Pools\Adapter\Stack`.
///
/// Array-backed idle list. `timeout` is ignored: nothing can return a connection
/// while the caller waits.
#[derive(Debug, Default)]
pub struct Stack<T> {
    idle: Mutex<Vec<Connection<T>>>,
}

impl<T> Stack<T> {
    #[must_use]
    pub fn new() -> Self {
        Self {
            idle: Mutex::new(Vec::new()),
        }
    }
}

#[async_trait]
impl<T: Send + 'static> Adapter<T> for Stack<T> {
    fn initialize(&self, _size: usize) {
        self.idle.lock().clear();
    }

    fn push(&self, connection: Connection<T>) {
        self.idle.lock().push(connection);
    }

    async fn pop(&self, _timeout: Duration) -> Option<Connection<T>> {
        self.idle.lock().pop()
    }

    fn count(&self) -> usize {
        self.idle.lock().len()
    }

    fn synchronized(&self, callback: Box<dyn FnOnce() + Send>) -> Result<(), PoolError> {
        callback();
        Ok(())
    }
}
