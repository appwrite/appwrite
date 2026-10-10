//! [`None`]: the adapter that stores nothing.

use std::future::Future;

use php_std::types::Value;

use crate::Result;
use crate::adapter::{Adapter, coerce};

/// Stores nothing: every load misses, every save fails, purges and flushes
/// succeed.
///
/// ```
/// use php_std::types::Value;
/// use utopia_cache::{Adapter, None};
///
/// # tokio::runtime::Runtime::new()?.block_on(async {
/// assert!(!None.save("key", &Value::from("value"), "", 0).await?);
/// assert_eq!(None.load("key", 60, "").await?, Option::None);
/// assert!(None.purge("key", "").await?);
/// # Ok::<(), utopia_cache::Error>(())
/// # })?;
/// # Ok::<(), Box<dyn std::error::Error>>(())
/// ```
///
/// ```php
/// use Utopia\Cache\Adapter\None;
///
/// $none = new None();
/// var_dump($none->save('key', 'value')); // bool(false)
/// var_dump($none->load('key', 60)); // bool(false)
/// var_dump($none->purge('key')); // bool(true)
/// ```
#[derive(Debug, Clone, Copy, Default)]
pub struct None;

impl Adapter for None {
    async fn load(&self, _key: &str, _ttl: i64, _hash: &str) -> Result<Option<Value>> {
        Ok(Option::None)
    }

    fn save(&self, _key: &str, data: &Value, _hash: &str, _ttl: i64) -> impl Future<Output = Result<bool>> + Send {
        let result = coerce(data, "Utopia\\Cache\\Adapter\\None::save(): Argument #2 ($data)").map(|_| false);
        async move { result }
    }

    async fn touch(&self, _key: &str, _hash: &str) -> Result<bool> {
        Ok(false)
    }

    async fn list(&self, _key: &str) -> Result<Vec<String>> {
        Ok(Vec::new())
    }

    async fn purge(&self, _key: &str, _hash: &str) -> Result<bool> {
        Ok(true)
    }

    async fn flush(&self) -> Result<bool> {
        Ok(true)
    }

    async fn ping(&self) -> Result<bool> {
        Ok(true)
    }

    async fn size(&self) -> Result<i64> {
        Ok(0)
    }

    fn name(&self, _key: Option<&str>) -> &str {
        "none"
    }
}
