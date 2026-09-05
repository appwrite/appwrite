use std::sync::Arc;

use serde_json::Value;

use crate::consumer::Consumer;
use crate::error::QueueError;
use crate::message::Message;
use crate::pool::ResourcePool;
use crate::publisher::Publisher;
use crate::queue::Queue;

/// Checkout a publisher/consumer from in-memory pools.
///
/// PHP `Utopia\Queue\Broker\Pool`.
pub struct Pool {
    publisher: Option<Arc<ResourcePool<Arc<dyn Publisher>>>>,
    consumer: Option<Arc<ResourcePool<Arc<dyn Consumer>>>>,
}

impl std::fmt::Debug for Pool {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Pool")
            .field("publisher", &self.publisher.is_some())
            .field("consumer", &self.consumer.is_some())
            .finish()
    }
}

impl Pool {
    pub fn new(
        publisher: Option<Arc<ResourcePool<Arc<dyn Publisher>>>>,
        consumer: Option<Arc<ResourcePool<Arc<dyn Consumer>>>>,
    ) -> Self {
        Self {
            publisher,
            consumer,
        }
    }

    pub fn from_publisher_pool(pool: ResourcePool<Arc<dyn Publisher>>) -> Self {
        let pool = Arc::new(pool);
        Self {
            publisher: Some(pool.clone()),
            consumer: None,
        }
    }
}

impl Publisher for Pool {
    fn enqueue(&self, queue: &Queue, payload: Value, priority: bool) -> Result<bool, QueueError> {
        self.publisher
            .as_ref()
            .map(|p| p.use_item(|b| b.enqueue(queue, payload, priority)))
            .transpose()?
            .ok_or_else(|| QueueError::Other("publisher pool is not configured".into()))
    }

    fn retry(
        &self,
        queue: &Queue,
        limit: Option<i64>,
        max_attempts: Option<i64>,
        newer_than: Option<i64>,
    ) -> Result<(), QueueError> {
        if let Some(p) = &self.publisher {
            p.use_item(|b| b.retry(queue, limit, max_attempts, newer_than))?;
        }
        Ok(())
    }

    fn get_queue_size(&self, queue: &Queue, failed_jobs: bool) -> Result<i64, QueueError> {
        self.publisher
            .as_ref()
            .map(|p| p.use_item(|b| b.get_queue_size(queue, failed_jobs)))
            .transpose()?
            .ok_or_else(|| QueueError::Other("publisher pool is not configured".into()))
    }

    fn reap(
        &self,
        queue: &Queue,
        older_than: i64,
        limit: Option<i64>,
        max_attempts: Option<i64>,
        newer_than: Option<i64>,
    ) -> Result<i64, QueueError> {
        self.publisher
            .as_ref()
            .map(|p| p.use_item(|b| b.reap(queue, older_than, limit, max_attempts, newer_than)))
            .transpose()?
            .ok_or_else(|| QueueError::Other("publisher pool is not configured".into()))
    }
}

impl Consumer for Pool {
    fn receive(&self, queue: &Queue, timeout: i64) -> Result<Option<Message>, QueueError> {
        self.consumer
            .as_ref()
            .map(|p| p.use_item(|b| b.receive(queue, timeout)))
            .transpose()?
            .ok_or_else(|| QueueError::Other("consumer pool is not configured".into()))
    }

    fn commit(&self, queue: &Queue, message: &Message) -> Result<(), QueueError> {
        if let Some(p) = &self.consumer {
            p.use_item(|b| b.commit(queue, message))?;
        }
        Ok(())
    }

    fn reject(&self, queue: &Queue, message: &Message) -> Result<(), QueueError> {
        if let Some(p) = &self.consumer {
            p.use_item(|b| b.reject(queue, message))?;
        }
        Ok(())
    }

    fn close(&self) {
        // PHP: TODO close all connections in the pool.
    }

    fn as_publisher(&self) -> Option<&dyn Publisher> {
        if self.publisher.is_some() {
            Some(self)
        } else {
            None
        }
    }
}
