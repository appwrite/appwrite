//! [`Pool`]: every operation on an adapter borrowed from a pool.

use std::future::Future;
use std::ops::Deref;
use std::sync::{Arc, Mutex};

use php_std::types::Value;
use tokio::sync::Semaphore;

use crate::adapter::{Adapter, Fields};
use crate::{Error, Result};

/// Lends adapters for the duration of one operation. Implement it to back a
/// [`Pool`] with another pool.
///
/// PHP: `Utopia\Pools\Pool`.
pub trait Lender: Send + Sync {
    /// The adapters lent.
    type Adapter: Adapter;
    /// A borrowed adapter, returned to the pool when dropped.
    type Guard<'a>: Deref<Target = Self::Adapter> + Send
    where
        Self: 'a;

    /// Borrows an adapter, waiting for one to come back when all are lent.
    fn lend(&self) -> impl Future<Output = Result<Self::Guard<'_>>> + Send;
}

/// A pool of up to `size` adapters made on demand by a factory and reused
/// last-in first-out.
///
/// PHP: `Utopia\Pools\Adapter\Stack`.
pub struct Stack<A> {
    idle: Mutex<Vec<A>>,
    permits: Semaphore,
    make: Box<dyn Fn() -> A + Send + Sync>,
}

impl<A: Adapter> Stack<A> {
    /// A pool of at most `size` adapters made by `make`.
    pub fn new(size: usize, make: impl Fn() -> A + Send + Sync + 'static) -> Self {
        Stack { idle: Mutex::new(Vec::new()), permits: Semaphore::new(size), make: Box::new(make) }
    }
}

/// An adapter borrowed from a [`Stack`].
pub struct Lent<'a, A> {
    adapter: Option<A>,
    stack: &'a Stack<A>,
    _permit: tokio::sync::SemaphorePermit<'a>,
}

impl<A> Deref for Lent<'_, A> {
    type Target = A;

    /// The borrowed adapter.
    fn deref(&self) -> &A {
        self.adapter.as_ref().expect("a lent adapter is present until dropped")
    }
}

impl<A> Drop for Lent<'_, A> {
    /// Returns the adapter to the stack.
    fn drop(&mut self) {
        if let Some(adapter) = self.adapter.take() {
            self.stack.idle.lock().unwrap_or_else(|e| e.into_inner()).push(adapter);
        }
    }
}

impl<A: Adapter + 'static> Lender for Stack<A> {
    type Adapter = A;
    type Guard<'a> = Lent<'a, A>;

    async fn lend(&self) -> Result<Lent<'_, A>> {
        let permit = self.permits.acquire().await.map_err(|_| Error::Exception("Pool is closed".into()))?;
        let idle = self.idle.lock().unwrap_or_else(|e| e.into_inner()).pop();
        let adapter = idle.unwrap_or_else(|| (self.make)());
        Ok(Lent { adapter: Some(adapter), stack: self, _permit: permit })
    }
}

/// Runs every operation on an adapter borrowed from a [`Lender`], returning
/// it afterwards, so concurrent tasks never share one connection.
///
/// ```
/// use php_std::types::Value;
/// use utopia_cache::{Adapter, Memory, Pool, Stack};
///
/// # tokio::runtime::Runtime::new()?.block_on(async {
/// let pool = Pool::new(Stack::new(4, Memory::new)).await?;
/// assert!(pool.save("key", &Value::from("value"), "", 0).await?);
/// assert_eq!(pool.name(None), "memory");
/// # Ok::<(), utopia_cache::Error>(())
/// # })?;
/// # Ok::<(), Box<dyn std::error::Error>>(())
/// ```
///
/// ```php
/// use Utopia\Cache\Adapter\Memory;
/// use Utopia\Cache\Adapter\Pool;
/// use Utopia\Pools\Adapter\Stack;
/// use Utopia\Pools\Pool as UtopiaPool;
///
/// $pool = new Pool(new UtopiaPool(new Stack(), 'cache', 4, fn () => new Memory(), timeout: 0.0));
/// var_dump($pool->save('key', 'value')); // string(5) "value"
/// echo $pool->getName(), "\n"; // memory
/// ```
pub struct Pool<P> {
    lender: P,
    name: String,
}

impl<P: Lender> Pool<P> {
    /// A pool adapter running every operation through `lender`.
    ///
    /// Borrows one adapter right away and keeps its name; fails when none can
    /// be borrowed.
    ///
    /// PHP: the constructor borrows one too, to check the pool's contents.
    pub async fn new(lender: P) -> Result<Self> {
        let name = lender.lend().await?.name(None).to_owned();
        Ok(Pool { lender, name })
    }

    /// The lender.
    pub fn lender(&self) -> &P {
        &self.lender
    }
}

impl<P: Lender + 'static> Adapter for Pool<P> {
    async fn load(&self, key: &str, ttl: i64, hash: &str) -> Result<Option<Value>> {
        self.lender.lend().await?.load(key, ttl, hash).await
    }

    async fn save(&self, key: &str, data: &Value, hash: &str, ttl: i64) -> Result<bool> {
        self.lender.lend().await?.save(key, data, hash, ttl).await
    }

    async fn touch(&self, key: &str, hash: &str) -> Result<bool> {
        self.lender.lend().await?.touch(key, hash).await
    }

    async fn list(&self, key: &str) -> Result<Vec<String>> {
        self.lender.lend().await?.list(key).await
    }

    async fn purge(&self, key: &str, hash: &str) -> Result<bool> {
        self.lender.lend().await?.purge(key, hash).await
    }

    async fn flush(&self) -> Result<bool> {
        self.lender.lend().await?.flush().await
    }

    async fn ping(&self) -> Result<bool> {
        self.lender.lend().await?.ping().await
    }

    async fn size(&self) -> Result<i64> {
        self.lender.lend().await?.size().await
    }

    fn name(&self, _key: Option<&str>) -> &str {
        &self.name
    }

    fn batchable(&self) -> bool {
        true
    }

    async fn load_many(&self, key: &str, fields: &[&str], ttl: i64) -> Result<Fields> {
        self.lender.lend().await?.load_many(key, fields, ttl).await
    }

    async fn save_many(&self, key: &str, data: &Fields, ttl: i64) -> Result<bool> {
        self.lender.lend().await?.save_many(key, data, ttl).await
    }

    fn leasable(&self) -> bool {
        true
    }

    async fn generation(&self, key: &str) -> Result<String> {
        self.lender.lend().await?.generation(key).await
    }

    async fn save_with_lease(&self, key: &str, data: &Value, hash: &str, generation: &str) -> Result<bool> {
        self.lender.lend().await?.save_with_lease(key, data, hash, generation).await
    }
}

/// A pool shared between owners lends like the pool itself.
impl<L: Lender> Lender for Arc<L> {
    type Adapter = L::Adapter;
    type Guard<'a>
        = L::Guard<'a>
    where
        Self: 'a;

    fn lend(&self) -> impl Future<Output = Result<Self::Guard<'_>>> + Send {
        (**self).lend()
    }
}
