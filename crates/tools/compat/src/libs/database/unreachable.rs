//! The `unreachable` cache: a Redis cache whose server is gone, every
//! command failing as phpredis fails on a lost server, without retries
//! (`Fixture\UnreachableRedis`, GeneralTests' stubbed client).

use php_std::types::Value;
use utopia_cache::{Adapter, Error, Result};

/// The message of every failed command.
const MESSAGE: &str = "Redis server redis:6379 went away";

/// A Redis cache adapter whose every command fails.
pub struct Unreachable;

fn gone<T>() -> Result<T> {
    Err(Error::Redis(MESSAGE.to_owned()))
}

/// What a lease script fails with: the adapter runs it through `evalSha`
/// after `clearLastError()`, which the stub leaves to phpredis, whose
/// client without a connection says so.
fn lease_gone<T>() -> Result<T> {
    Err(Error::Redis("Redis server went away".to_owned()))
}

impl Adapter for Unreachable {
    async fn load(&self, _key: &str, _ttl: i64, _hash: &str) -> Result<Option<Value>> {
        gone()
    }

    async fn save(&self, _key: &str, _data: &Value, _hash: &str, _ttl: i64) -> Result<bool> {
        gone()
    }

    async fn touch(&self, _key: &str, _hash: &str) -> Result<bool> {
        gone()
    }

    async fn list(&self, _key: &str) -> Result<Vec<String>> {
        gone()
    }

    async fn purge(&self, _key: &str, _hash: &str) -> Result<bool> {
        lease_gone()
    }

    async fn flush(&self) -> Result<bool> {
        gone()
    }

    async fn ping(&self) -> Result<bool> {
        gone()
    }

    async fn size(&self) -> Result<i64> {
        gone()
    }

    fn name(&self, _key: Option<&str>) -> &str {
        "redis"
    }

    fn batchable(&self) -> bool {
        true
    }

    async fn load_many(&self, _key: &str, _fields: &[&str], _ttl: i64) -> Result<utopia_cache::Fields> {
        gone()
    }

    async fn save_many(&self, _key: &str, _data: &utopia_cache::Fields, _ttl: i64) -> Result<bool> {
        gone()
    }

    fn leasable(&self) -> bool {
        true
    }

    async fn generation(&self, _key: &str) -> Result<String> {
        gone()
    }

    async fn save_with_lease(&self, _key: &str, _data: &Value, _hash: &str, _generation: &str) -> Result<bool> {
        lease_gone()
    }
}
