//! [`Sharding`]: keys spread over several adapters.

use std::future::Future;
use std::sync::Arc;

use php_std::types::Value;

use crate::adapter::{Adapter, Fields};
use crate::{Error, Result};
use utopia_telemetry::Telemetry;

/// Spreads keys over several adapters: `crc32(key) % shards` picks the one
/// that stores a key, so each key always lives on the same shard until the
/// shard list changes (adding a shard moves keys, and the hit rate drops
/// until they are cached again). Flush, ping and size cover every shard.
///
/// ```
/// use php_std::types::Value;
/// use utopia_cache::{Adapter, Memory, Sharding};
///
/// # tokio::runtime::Runtime::new()?.block_on(async {
/// let shards = Sharding::new(vec![Memory::new(), Memory::new(), Memory::new()])?;
/// shards.save("a", &Value::from("1"), "", 0).await?;
/// shards.save("b", &Value::from("2"), "", 0).await?;
/// assert_eq!(shards.load("b", 60, "").await?, Some(Value::from("2")));
/// assert_eq!(shards.size().await?, 2);
/// assert!(Sharding::<Memory>::new(vec![]).is_err());
/// # Ok::<(), utopia_cache::Error>(())
/// # })?;
/// # Ok::<(), Box<dyn std::error::Error>>(())
/// ```
///
/// ```php
/// use Utopia\Cache\Adapter\Memory;
/// use Utopia\Cache\Adapter\Sharding;
///
/// $shards = new Sharding([new Memory(), new Memory(), new Memory()]);
/// $shards->save('a', '1');
/// $shards->save('b', '2');
/// echo $shards->load('b', 60), "\n"; // 2
/// echo $shards->getSize(), "\n"; // 2
/// ```
#[derive(Debug)]
pub struct Sharding<A> {
    adapters: Vec<A>,
}

impl<A: Adapter> Sharding<A> {
    /// Shards over `adapters`, in order. Fails without adapters.
    pub fn new(adapters: Vec<A>) -> Result<Self> {
        if adapters.is_empty() {
            return Err(Error::Exception("No adapters provided".into()));
        }
        Ok(Sharding { adapters })
    }

    /// The shards.
    pub fn adapters(&self) -> &[A] {
        &self.adapters
    }

    /// The shard storing `key`.
    pub fn shard(&self, key: &str) -> &A {
        let index = crc32fast::hash(key.as_bytes()) as usize % self.adapters.len();
        &self.adapters[index]
    }
}

impl<A: Adapter> Adapter for Sharding<A> {
    fn load(&self, key: &str, ttl: i64, hash: &str) -> impl Future<Output = Result<Option<Value>>> + Send {
        self.shard(key).load(key, ttl, hash)
    }

    fn save(&self, key: &str, data: &Value, hash: &str, ttl: i64) -> impl Future<Output = Result<bool>> + Send {
        self.shard(key).save(key, data, hash, ttl)
    }

    fn touch(&self, key: &str, hash: &str) -> impl Future<Output = Result<bool>> + Send {
        self.shard(key).touch(key, hash)
    }

    fn list(&self, key: &str) -> impl Future<Output = Result<Vec<String>>> + Send {
        self.shard(key).list(key)
    }

    fn purge(&self, key: &str, hash: &str) -> impl Future<Output = Result<bool>> + Send {
        self.shard(key).purge(key, hash)
    }

    async fn flush(&self) -> Result<bool> {
        let mut result = true;
        for adapter in &self.adapters {
            result = adapter.flush().await? && result;
        }
        Ok(result)
    }

    async fn ping(&self) -> Result<bool> {
        for adapter in &self.adapters {
            if !adapter.ping().await? {
                return Ok(false);
            }
        }
        Ok(true)
    }

    async fn size(&self) -> Result<i64> {
        let mut size = 0i64;
        for adapter in &self.adapters {
            size = size.wrapping_add(adapter.size().await?);
        }
        Ok(size)
    }

    fn name(&self, key: Option<&str>) -> &str {
        match key {
            Some(key) => self.shard(key).name(Option::None),
            Option::None => self.adapters[0].name(Option::None),
        }
    }

    fn batchable(&self) -> bool {
        true
    }

    fn load_many(&self, key: &str, fields: &[&str], ttl: i64) -> impl Future<Output = Result<Fields>> + Send {
        self.shard(key).load_many(key, fields, ttl)
    }

    fn save_many(&self, key: &str, data: &Fields, ttl: i64) -> impl Future<Output = Result<bool>> + Send {
        self.shard(key).save_many(key, data, ttl)
    }

    fn leasable(&self) -> bool {
        true
    }

    fn generation(&self, key: &str) -> impl Future<Output = Result<String>> + Send {
        self.shard(key).generation(key)
    }

    fn save_with_lease(
        &self,
        key: &str,
        data: &Value,
        hash: &str,
        generation: &str,
    ) -> impl Future<Output = Result<bool>> + Send {
        self.shard(key).save_with_lease(key, data, hash, generation)
    }

    fn load_bytes(&self, key: &str, hash: &str) -> impl Future<Output = Result<Option<Vec<u8>>>> + Send {
        self.shard(key).load_bytes(key, hash)
    }

    fn save_bytes(&self, key: &str, bytes: &[u8], hash: &str, ttl: i64) -> impl Future<Output = Result<bool>> + Send {
        self.shard(key).save_bytes(key, bytes, hash, ttl)
    }

    fn save_bytes_with_lease(
        &self,
        key: &str,
        bytes: &[u8],
        hash: &str,
        generation: &str,
    ) -> impl Future<Output = Result<bool>> + Send {
        self.shard(key).save_bytes_with_lease(key, bytes, hash, generation)
    }

    fn set_telemetry(&self, _telemetry: Arc<dyn Telemetry>) {
        // Sharding does not pass telemetry on to its shards.
    }
}
