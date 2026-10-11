//! Brokers leased from pools.

use std::sync::{Arc, Mutex};

use php_std::Array;
use tokio::sync::Semaphore;

use super::Redis;
use crate::connection::BoxFuture;
use crate::contract::{Consumer, Publisher};
use crate::error::{Error, Result};
use crate::message::Message;
use crate::queue::Queue;

/// A fixed-size pool of brokers, created on first use (the part of
/// `Utopia\Pools\Pool` a pooled broker needs): a caller leases one for the
/// length of a call, waiting while all are leased.
pub struct Resources<B> {
    factory: Box<dyn Fn() -> B + Send + Sync>,
    idle: Mutex<Vec<Arc<B>>>,
    permits: Semaphore,
    created: Mutex<usize>,
    size: usize,
}

impl<B: Send + Sync + 'static> Resources<B> {
    /// A pool of at most `size` brokers made by `factory`.
    pub fn new(size: usize, factory: impl Fn() -> B + Send + Sync + 'static) -> Self {
        let size = size.max(1);
        Self {
            factory: Box::new(factory),
            idle: Mutex::new(Vec::new()),
            permits: Semaphore::new(size),
            created: Mutex::new(0),
            size,
        }
    }

    /// The pool's size.
    pub fn size(&self) -> usize {
        self.size
    }

    /// Leases a broker for the length of `f`.
    pub async fn lease<T, F, Fut>(&self, f: F) -> Result<T>
    where
        F: FnOnce(Arc<B>) -> Fut,
        Fut: Future<Output = T>,
    {
        let _permit = self.permits.acquire().await.map_err(|e| Error::Runtime(e.to_string()))?;
        let leased = {
            let mut idle = self.idle.lock().unwrap_or_else(|e| e.into_inner());
            idle.pop()
        };
        let broker = match leased {
            Some(b) => b,
            None => {
                *self.created.lock().unwrap_or_else(|e| e.into_inner()) += 1;
                Arc::new((self.factory)())
            }
        };
        let result = f(broker.clone()).await;
        self.idle.lock().unwrap_or_else(|e| e.into_inner()).push(broker);
        Ok(result)
    }

    /// The brokers sitting idle (not leased) right now.
    pub fn idle(&self) -> Vec<Arc<B>> {
        self.idle.lock().map(|i| i.clone()).unwrap_or_default()
    }

    /// Removes an idle broker from the pool.
    pub fn discard(&self, broker: &Arc<B>) {
        let mut idle = self.idle.lock().unwrap_or_else(|e| e.into_inner());
        let before = idle.len();
        idle.retain(|b| !Arc::ptr_eq(b, broker));
        if idle.len() < before {
            let mut created = self.created.lock().unwrap_or_else(|e| e.into_inner());
            *created = created.saturating_sub(1);
        }
    }
}

/// A broker that leases a publisher and a consumer from pools for each
/// call (`Utopia\Queue\Broker\Pool`). Either pool may be absent: receiving
/// without a consumer pool returns nothing.
///
/// ```
/// use std::sync::Arc;
/// use utopia_queue::broker::{Pool, PoolResources, Redis};
/// use utopia_queue::connection::Redis as Connection;
///
/// let brokers = Arc::new(PoolResources::new(4, || {
///     Redis::new(Arc::new(Connection::new("localhost", 6379)), Arc::new(Connection::new("localhost", 6379)))
/// }));
/// let pool = Pool::new(Some(brokers.clone()), Some(brokers));
/// # let _ = pool;
/// ```
///
/// ```php
/// use Utopia\Pools\Pool as UtopiaPool;
/// use Utopia\Queue\Broker\Pool;
/// use Utopia\Queue\Broker\Redis;
/// use Utopia\Queue\Connection\Redis as Connection;
///
/// $brokers = new UtopiaPool(new \Utopia\Pools\Adapter\Stack(), 'brokers', 4, fn () => new Redis(new Connection('redis'), new Connection('redis')), 1.0);
/// $pool = new Pool($brokers, $brokers);
/// var_dump($pool instanceof \Utopia\Queue\Consumer); // bool(true)
/// ```
pub struct Pool<B> {
    publisher: Option<Arc<Resources<B>>>,
    consumer: Option<Arc<Resources<B>>>,
}

impl<B> Pool<B> {
    /// A pooled broker; the two pools may be the same.
    pub fn new(publisher: Option<Arc<Resources<B>>>, consumer: Option<Arc<Resources<B>>>) -> Self {
        Self { publisher, consumer }
    }
}

fn none(method: &str, kind: &str) -> Error {
    Error::Type(format!("Utopia\\Queue\\Broker\\Pool::{method}(): Return value must be of type {kind}, null returned"))
}

impl<B: Publisher + Consumer + 'static> Pool<B> {
    async fn on_publisher<T, F, Fut>(&self, method: &str, kind: &str, f: F) -> Result<T>
    where
        F: FnOnce(Arc<B>) -> Fut,
        Fut: Future<Output = Result<T>>,
    {
        let Some(pool) = &self.publisher else { return Err(none(method, kind)) };
        pool.lease(f).await?
    }

    /// Idle upkeep of both pools: only brokers not leased are swept, so it is
    /// safe while a receive runs.
    pub async fn maintain_pools(&self) -> Result<()> {
        if let Some(publisher) = &self.publisher {
            sweep(publisher).await?;
        }
        if let Some(consumer) = &self.consumer
            && !self.publisher.as_ref().is_some_and(|p| Arc::ptr_eq(p, consumer))
        {
            sweep(consumer).await?;
        }
        Ok(())
    }
}

/// Upkeep of the idle brokers. One that cannot keep itself alive is dropped
/// from the pool (the next lease makes a fresh one) rather than handed to the
/// next caller.
async fn sweep<B: Consumer + 'static>(pool: &Resources<B>) -> Result<()> {
    for broker in pool.idle() {
        if let Some(upkeep) = broker.maintain()
            && upkeep.await.is_err()
        {
            pool.discard(&broker);
        }
    }
    Ok(())
}

impl Pool<Redis> {
    /// [`Redis::retry_with`] on a leased publisher.
    pub async fn retry_with(
        &self,
        queue: &Queue,
        limit: Option<i64>,
        max_attempts: Option<i64>,
        newer_than: Option<i64>,
    ) -> Result<()> {
        match &self.publisher {
            None => Ok(()),
            Some(pool) => {
                pool.lease(|b| async move { b.retry_with(queue, limit, max_attempts, newer_than).await }).await?
            }
        }
    }

    /// [`Redis::reap`] on a leased publisher.
    pub async fn reap(
        &self,
        queue: &Queue,
        older_than: i64,
        limit: Option<i64>,
        max_attempts: Option<i64>,
        newer_than: Option<i64>,
    ) -> Result<i64> {
        self.on_publisher("reap", "int", |b| async move {
            b.reap(queue, older_than, limit, max_attempts, newer_than, None).await
        })
        .await
    }
}

impl<B: Publisher + Consumer + 'static> Publisher for Pool<B> {
    fn publish<'a>(&'a self, queue: &'a Queue, payload: Array) -> BoxFuture<'a, Result<bool>> {
        Box::pin(self.on_publisher("publish", "bool", move |b| async move { b.publish(queue, payload).await }))
    }

    fn publish_many<'a>(&'a self, queue: &'a Queue, payloads: Vec<Array>) -> BoxFuture<'a, Result<bool>> {
        Box::pin(self.on_publisher(
            "publishMany",
            "bool",
            move |b| async move { b.publish_many(queue, payloads).await },
        ))
    }

    fn retry<'a>(&'a self, queue: &'a Queue, limit: Option<i64>) -> BoxFuture<'a, Result<()>> {
        Box::pin(async move {
            match &self.publisher {
                None => Ok(()),
                Some(pool) => pool.lease(|b| async move { b.retry(queue, limit).await }).await?,
            }
        })
    }

    fn queue_size<'a>(&'a self, queue: &'a Queue, failed: bool) -> BoxFuture<'a, Result<i64>> {
        Box::pin(self.on_publisher("getQueueSize", "int", move |b| async move { b.queue_size(queue, failed).await }))
    }

    fn pending_count<'a>(&'a self, queue: &'a Queue) -> BoxFuture<'a, Result<i64>> {
        Box::pin(self.on_publisher("getQueueSize", "int", move |b| async move { b.pending_count(queue).await }))
    }

    fn failed_count<'a>(&'a self, queue: &'a Queue) -> BoxFuture<'a, Result<i64>> {
        Box::pin(self.on_publisher("getFailedCount", "int", move |b| async move { b.failed_count(queue).await }))
    }
}

impl<B: Publisher + Consumer + 'static> Consumer for Pool<B> {
    fn receive<'a>(&'a self, queue: &'a Queue, timeout: i64, n: i64) -> BoxFuture<'a, Result<Vec<Message>>> {
        Box::pin(async move {
            match &self.consumer {
                None => Ok(Vec::new()),
                Some(pool) => pool.lease(|b| async move { b.receive(queue, timeout, n).await }).await?,
            }
        })
    }

    fn commit<'a>(&'a self, queue: &'a Queue, message: &'a Message) -> BoxFuture<'a, Result<()>> {
        Box::pin(async move {
            match &self.consumer {
                None => Ok(()),
                Some(pool) => pool.lease(|b| async move { b.commit(queue, message).await }).await?,
            }
        })
    }

    fn reject<'a>(&'a self, queue: &'a Queue, message: &'a Message) -> BoxFuture<'a, Result<()>> {
        Box::pin(async move {
            match &self.consumer {
                None => Ok(()),
                Some(pool) => pool.lease(|b| async move { b.reject(queue, message).await }).await?,
            }
        })
    }

    fn close(&self) -> BoxFuture<'_, ()> {
        Box::pin(async {})
    }

    fn release<'a>(&'a self, queue: &'a Queue, messages: &'a [Message]) -> Option<BoxFuture<'a, Result<()>>> {
        Some(Box::pin(async move {
            match &self.consumer {
                None => Ok(()),
                Some(pool) => {
                    pool.lease(|b| async move {
                        match b.release(queue, messages) {
                            Some(release) => release.await,
                            None => Ok(()),
                        }
                    })
                    .await?
                }
            }
        }))
    }

    fn extend<'a>(&'a self, queue: &'a Queue, messages: &'a [Message]) -> BoxFuture<'a, Result<()>> {
        Box::pin(async move {
            match &self.consumer {
                None => Ok(()),
                Some(pool) => pool.lease(|b| async move { b.extend(queue, messages).await }).await?,
            }
        })
    }

    fn extend_interval(&self) -> BoxFuture<'_, Option<f64>> {
        Box::pin(async move {
            match &self.consumer {
                None => None,
                Some(pool) => pool.lease(|b| async move { b.extend_interval().await }).await.ok().flatten(),
            }
        })
    }

    fn maintain(&self) -> Option<BoxFuture<'_, Result<()>>> {
        Some(Box::pin(self.maintain_pools()))
    }

    fn in_flight_ceiling(&self) -> BoxFuture<'_, Option<i64>> {
        Box::pin(async move {
            match &self.consumer {
                None => None,
                Some(pool) => pool.lease(|b| async move { b.in_flight_ceiling().await }).await.ok().flatten(),
            }
        })
    }

    fn name(&self) -> &str {
        "Utopia\\Queue\\Broker\\Pool"
    }
}
