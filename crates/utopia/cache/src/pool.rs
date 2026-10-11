//! [`Pool`]: every operation on an adapter borrowed from a
//! [`utopia_pools::Pool`].

use php_std::types::Value;
use utopia_pools::{Adapter as Idle, Connection, Resource, Stack};

use crate::adapter::{Adapter, Fields};
use crate::{Error, Result};

/// A pool's error as a cache error: `init`'s own error when it is one.
fn pool_error(e: utopia_pools::Error) -> Error {
    match e {
        utopia_pools::Error::Init(inner) => match inner.downcast::<Error>() {
            Ok(e) => *e,
            Err(other) => Error::Exception(other.to_string()),
        },
        utopia_pools::Error::InvalidArgument(m) => Error::InvalidArgument(m),
        other => Error::Exception(other.to_string()),
    }
}

/// Runs every operation on an adapter borrowed from a
/// [`utopia_pools::Pool`], returning it afterwards (or discarding it when the
/// operation failed), so concurrent tasks never share one connection.
///
/// ```
/// use php_std::types::Value;
/// use utopia_cache::{Adapter, Memory, Pool};
///
/// # tokio::runtime::Runtime::new()?.block_on(async {
/// let memories = utopia_pools::Pool::new(
///     utopia_pools::Stack::new(),
///     "cache",
///     4,
///     || async { Ok::<_, std::convert::Infallible>(Memory::new()) },
///     0.0,
/// )
/// .unwrap();
/// let pool = Pool::new(memories).await?;
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
///
/// PHP: `Utopia\Cache\Adapter\Pool`.
pub struct Pool<A, S = Stack<A>> {
    pool: utopia_pools::Pool<A, S>,
    name: String,
}

impl<A: Adapter + Resource, S: Idle<A>> Pool<A, S> {
    /// A pool adapter running every operation through `pool`.
    ///
    /// Borrows one adapter right away and keeps its name; fails when none can
    /// be borrowed.
    ///
    /// PHP: the constructor borrows one too, to check the pool's contents.
    pub async fn new(pool: utopia_pools::Pool<A, S>) -> Result<Self> {
        let connection = pool.pop().await.map_err(pool_error)?;
        let name = connection.resource().name(None).to_owned();
        pool.reclaim(&connection);
        Ok(Pool { pool, name })
    }

    /// The pool the adapters are borrowed from.
    pub fn pool(&self) -> &utopia_pools::Pool<A, S> {
        &self.pool
    }

    async fn pop(&self) -> Result<Connection<A>> {
        self.pool.pop().await.map_err(pool_error)
    }

    /// Returns a borrowed adapter: reclaimed after a success, released as
    /// failed (discarded) after an error.
    async fn done<T>(&self, connection: Connection<A>, result: Result<T>) -> Result<T> {
        self.pool.release(&connection, result.is_err()).await;
        result
    }
}

impl<A: Adapter + Resource, S: Idle<A>> Adapter for Pool<A, S> {
    async fn load(&self, key: &str, ttl: i64, hash: &str) -> Result<Option<Value>> {
        let c = self.pop().await?;
        let r = c.load(key, ttl, hash).await;
        self.done(c, r).await
    }

    async fn save(&self, key: &str, data: &Value, hash: &str, ttl: i64) -> Result<bool> {
        let c = self.pop().await?;
        let r = c.save(key, data, hash, ttl).await;
        self.done(c, r).await
    }

    async fn touch(&self, key: &str, hash: &str) -> Result<bool> {
        let c = self.pop().await?;
        let r = c.touch(key, hash).await;
        self.done(c, r).await
    }

    async fn list(&self, key: &str) -> Result<Vec<String>> {
        let c = self.pop().await?;
        let r = c.list(key).await;
        self.done(c, r).await
    }

    async fn purge(&self, key: &str, hash: &str) -> Result<bool> {
        let c = self.pop().await?;
        let r = c.purge(key, hash).await;
        self.done(c, r).await
    }

    async fn flush(&self) -> Result<bool> {
        let c = self.pop().await?;
        let r = c.flush().await;
        self.done(c, r).await
    }

    async fn ping(&self) -> Result<bool> {
        let c = self.pop().await?;
        let r = c.ping().await;
        self.done(c, r).await
    }

    async fn size(&self) -> Result<i64> {
        let c = self.pop().await?;
        let r = c.size().await;
        self.done(c, r).await
    }

    fn name(&self, _key: Option<&str>) -> &str {
        &self.name
    }

    fn batchable(&self) -> bool {
        true
    }

    async fn load_many(&self, key: &str, fields: &[&str], ttl: i64) -> Result<Fields> {
        let c = self.pop().await?;
        let r = c.load_many(key, fields, ttl).await;
        self.done(c, r).await
    }

    async fn save_many(&self, key: &str, data: &Fields, ttl: i64) -> Result<bool> {
        let c = self.pop().await?;
        let r = c.save_many(key, data, ttl).await;
        self.done(c, r).await
    }

    fn leasable(&self) -> bool {
        true
    }

    async fn generation(&self, key: &str) -> Result<String> {
        let c = self.pop().await?;
        let r = c.generation(key).await;
        self.done(c, r).await
    }

    async fn save_with_lease(&self, key: &str, data: &Value, hash: &str, generation: &str) -> Result<bool> {
        let c = self.pop().await?;
        let r = c.save_with_lease(key, data, hash, generation).await;
        self.done(c, r).await
    }

    async fn load_bytes(&self, key: &str, hash: &str) -> Result<Option<Vec<u8>>> {
        let c = self.pop().await?;
        let r = c.load_bytes(key, hash).await;
        self.done(c, r).await
    }

    async fn save_bytes(&self, key: &str, bytes: &[u8], hash: &str, ttl: i64) -> Result<bool> {
        let c = self.pop().await?;
        let r = c.save_bytes(key, bytes, hash, ttl).await;
        self.done(c, r).await
    }

    async fn save_bytes_with_lease(&self, key: &str, bytes: &[u8], hash: &str, generation: &str) -> Result<bool> {
        let c = self.pop().await?;
        let r = c.save_bytes_with_lease(key, bytes, hash, generation).await;
        self.done(c, r).await
    }
}

/// A cache adapter in a pool: no recovery hook, so one whose operation
/// failed is discarded and replaced.
///
/// PHP: the Redis adapters' `reconnect()` is private, so the pool's probe for
/// it fails and the adapter is discarded too.
macro_rules! resources {
    ($($(#[$cfg:meta])* [$($g:ident),*] $t:ty;)*) => {$(
        $(#[$cfg])*
        impl<$($g: Send + Sync + 'static),*> Resource for $t {}
    )*};
}

resources! {
    [] crate::Memory;
    [] crate::None;
    [A] crate::Sharding<A>;
    #[cfg(feature = "filesystem")]
    [] crate::Filesystem;
    #[cfg(feature = "redis")]
    [C, K] crate::Redis<C, K>;
    #[cfg(feature = "redis")]
    [K] crate::Multiplexing<K>;
    #[cfg(feature = "redis-cluster")]
    [C, K] crate::RedisCluster<C, K>;
    #[cfg(feature = "memcached")]
    [] crate::Memcached;
    #[cfg(feature = "memcached")]
    [K] crate::Hazelcast<K>;
}
