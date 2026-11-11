//! Stores that share a breaker's verdict between processes: [`Adapter`],
//! with [`Table`] (an in-process table) and [`Redis`].

use std::collections::HashMap;
use std::sync::{Arc, Mutex, MutexGuard};

use sha1::{Digest, Sha1};
use utopia_telemetry::BoxFuture;

use crate::Error;

/// A value in a store: a string or an integer.
///
/// PHP: `int|string`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum Stored {
    /// An integer.
    Int(i64),
    /// A string.
    Str(String),
}

impl From<i64> for Stored {
    /// An integer value.
    fn from(value: i64) -> Self {
        Stored::Int(value)
    }
}

impl From<&str> for Stored {
    /// A string value.
    fn from(value: &str) -> Self {
        Stored::Str(value.to_owned())
    }
}

/// Where breakers keep the state they share: a key-value store with
/// increments. Implement it to share state another way.
///
/// PHP: `Utopia\CircuitBreaker\Adapter`.
pub trait Adapter: Send + Sync {
    /// The value at `key`, `None` when there is none.
    fn get<'a>(&'a self, key: &'a str) -> BoxFuture<'a, Result<Option<Stored>, Error>>;

    /// Stores `value` at `key`.
    fn set<'a>(&'a self, key: &'a str, value: Stored) -> BoxFuture<'a, Result<(), Error>>;

    /// Adds `by` to the integer at `key` (0 when missing) and returns the sum.
    fn increment<'a>(&'a self, key: &'a str, by: i64) -> BoxFuture<'a, Result<i64, Error>>;

    /// Removes `key`.
    fn delete<'a>(&'a self, key: &'a str) -> BoxFuture<'a, Result<(), Error>>;
}

impl<T: Adapter + ?Sized> Adapter for Arc<T> {
    /// Reads through the shared store.
    fn get<'a>(&'a self, key: &'a str) -> BoxFuture<'a, Result<Option<Stored>, Error>> {
        (**self).get(key)
    }

    /// Writes through the shared store.
    fn set<'a>(&'a self, key: &'a str, value: Stored) -> BoxFuture<'a, Result<(), Error>> {
        (**self).set(key, value)
    }

    /// Increments through the shared store.
    fn increment<'a>(&'a self, key: &'a str, by: i64) -> BoxFuture<'a, Result<i64, Error>> {
        (**self).increment(key, by)
    }

    /// Deletes through the shared store.
    fn delete<'a>(&'a self, key: &'a str) -> BoxFuture<'a, Result<(), Error>> {
        (**self).delete(key)
    }
}

/// The longest key a [`SharedTable`] holds; longer keys are hashed.
const MAX_KEY_LENGTH: usize = 63;

#[derive(Debug, Clone, Default)]
struct Row {
    value: Vec<u8>,
    number: i64,
    /// 1: a string, 2: an integer, 0: neither yet.
    kind: i64,
}

/// A table of rows shared by every [`Table`] adapter made on it: one per
/// process (or per worker group) where Swoole would share memory.
///
/// PHP: the `Swoole\Table` that `SwooleTable::createTable()` creates, with
/// `value`, `number` and `type` columns.
#[derive(Debug, Clone)]
pub struct SharedTable {
    rows: Arc<Mutex<HashMap<Vec<u8>, Row>>>,
    value_length: usize,
}

impl SharedTable {
    /// A table for about `size` keys whose string values keep at most
    /// `value_length` bytes (longer ones are cut).
    ///
    /// PHP: `SwooleTable::createTable($size, $valueLength)`.
    pub fn new(size: usize, value_length: usize) -> Self {
        SharedTable { rows: Arc::new(Mutex::new(HashMap::with_capacity(size))), value_length }
    }

    fn lock(&self) -> MutexGuard<'_, HashMap<Vec<u8>, Row>> {
        self.rows.lock().unwrap_or_else(|e| e.into_inner())
    }
}

impl Default for SharedTable {
    /// A table for 1024 keys with values of up to 255 bytes.
    fn default() -> Self {
        Self::new(1024, 255)
    }
}

/// Shares breaker state through a [`SharedTable`] in this process, under a
/// key prefix (`breaker:` by default). Keys longer than 63 bytes are hashed
/// to fit.
///
/// ```
/// use utopia_circuit_breaker::{Adapter, SharedTable, Stored, Table};
///
/// # utopia_async::Builder::current_thread().build()?.block_on(async {
/// let table = SharedTable::new(32, 255);
/// let first = Table::new(table.clone(), "test:");
/// let second = Table::new(table, "test:");
///
/// first.set("failures", Stored::Int(2)).await?;
/// assert_eq!(second.increment("failures", 1).await?, 3);
/// # Ok::<(), utopia_circuit_breaker::Error>(())
/// # })?;
/// # Ok::<(), Box<dyn std::error::Error>>(())
/// ```
///
/// ```php
/// use Utopia\CircuitBreaker\Adapter\SwooleTable;
///
/// $table = SwooleTable::createTable(32);
/// $first = new SwooleTable($table, 'test:');
/// $second = new SwooleTable($table, 'test:');
///
/// $first->set('failures', 2);
/// echo $second->increment('failures'), "\n"; // 3
/// ```
///
/// PHP: `Utopia\CircuitBreaker\Adapter\SwooleTable`.
#[derive(Debug, Clone)]
pub struct Table {
    table: SharedTable,
    prefix: String,
}

impl Table {
    /// An adapter on `table` whose keys start with `prefix`.
    pub fn new(table: SharedTable, prefix: &str) -> Self {
        Table { table, prefix: prefix.to_owned() }
    }

    fn key(&self, key: &str) -> Vec<u8> {
        let full = format!("{}{key}", self.prefix);
        if full.len() <= MAX_KEY_LENGTH {
            return full.into_bytes();
        }
        let mut out = self.prefix.as_bytes()[..self.prefix.len().min(20)].to_vec();
        out.extend_from_slice(hex::encode(Sha1::digest(full.as_bytes())).as_bytes());
        out
    }
}

fn ready<'a, T: Send + 'a>(value: T) -> BoxFuture<'a, T> {
    Box::pin(std::future::ready(value))
}

impl Adapter for Table {
    /// The row's string or integer.
    fn get<'a>(&'a self, key: &'a str) -> BoxFuture<'a, Result<Option<Stored>, Error>> {
        let rows = self.table.lock();
        ready(match rows.get(&self.key(key)) {
            None => Ok(None),
            Some(row) if row.kind == 1 => Ok(Some(Stored::Str(String::from_utf8_lossy(&row.value).into_owned()))),
            Some(row) if row.kind == 2 => Ok(Some(Stored::Int(row.number))),
            Some(_) => Err(Error::Adapter(format!("Unexpected Swoole table value type for cache key \"{key}\"."))),
        })
    }

    /// Replaces the row; strings are cut to the table's value length.
    fn set<'a>(&'a self, key: &'a str, value: Stored) -> BoxFuture<'a, Result<(), Error>> {
        let row = match value {
            Stored::Int(number) => Row { value: Vec::new(), number, kind: 2 },
            Stored::Str(s) => {
                let bytes = s.as_bytes();
                Row { value: bytes[..bytes.len().min(self.table.value_length)].to_vec(), number: 0, kind: 1 }
            }
        };
        self.table.lock().insert(self.key(key), row);
        ready(Ok(()))
    }

    /// Increments the row's integer; fails on a string row.
    fn increment<'a>(&'a self, key: &'a str, by: i64) -> BoxFuture<'a, Result<i64, Error>> {
        let mut rows = self.table.lock();
        let row = rows.entry(self.key(key)).or_default();
        if row.kind == 1 {
            return ready(Err(Error::Adapter(format!("Cannot increment non-numeric Swoole table key \"{key}\"."))));
        }
        row.number = row.number.wrapping_add(by);
        row.kind = 2;
        ready(Ok(row.number))
    }

    /// Removes the row.
    fn delete<'a>(&'a self, key: &'a str) -> BoxFuture<'a, Result<(), Error>> {
        self.table.lock().remove(&self.key(key));
        ready(Ok(()))
    }
}

#[cfg(feature = "redis")]
pub use self::redis_adapter::Redis;

#[cfg(feature = "redis")]
mod redis_adapter {
    use redis::aio::ConnectionLike;

    use super::{Adapter, Stored};
    use crate::Error;
    use utopia_telemetry::BoxFuture;

    /// Shares breaker state through Redis under a key prefix (`breaker:` by
    /// default), as strings: integers read back as strings.
    ///
    /// ```no_run
    /// use utopia_circuit_breaker::{Adapter, Redis, Stored};
    ///
    /// # async fn run() -> Result<(), Box<dyn std::error::Error>> {
    /// let client = redis::Client::open("redis://localhost:6379")?;
    /// let store = Redis::new(client.get_connection_manager().await?, "breaker:");
    /// store.set("users-api:state", Stored::from("open")).await?;
    /// assert_eq!(store.get("users-api:state").await?, Some(Stored::from("open")));
    /// # Ok(())
    /// # }
    /// ```
    ///
    /// ```php
    /// use Utopia\CircuitBreaker\Adapter\Redis;
    ///
    /// $redis = new \Redis();
    /// $redis->connect('localhost', 6379);
    /// $store = new Redis($redis, 'breaker:');
    /// $store->set('users-api:state', 'open');
    /// echo $store->get('users-api:state'), "\n"; // open
    /// ```
    ///
    /// PHP: `Utopia\CircuitBreaker\Adapter\Redis`.
    #[derive(Clone)]
    pub struct Redis<C> {
        connection: C,
        prefix: String,
    }

    impl<C> Redis<C> {
        /// An adapter on `connection` whose keys start with `prefix`.
        pub fn new(connection: C, prefix: &str) -> Self {
            Redis { connection, prefix: prefix.to_owned() }
        }
    }

    fn failed(e: redis::RedisError) -> Error {
        Error::Adapter(e.to_string())
    }

    impl<C: ConnectionLike + Clone + Send + Sync + 'static> Adapter for Redis<C> {
        /// `GET`; a missing key is `None`.
        fn get<'a>(&'a self, key: &'a str) -> BoxFuture<'a, Result<Option<Stored>, Error>> {
            Box::pin(async move {
                let mut c = self.connection.clone();
                let value: Option<Vec<u8>> =
                    redis::cmd("GET").arg(format!("{}{key}", self.prefix)).query_async(&mut c).await.map_err(failed)?;
                Ok(value.map(|v| Stored::Str(String::from_utf8_lossy(&v).into_owned())))
            })
        }

        /// `SET`, integers as their decimal string.
        fn set<'a>(&'a self, key: &'a str, value: Stored) -> BoxFuture<'a, Result<(), Error>> {
            Box::pin(async move {
                let mut c = self.connection.clone();
                let value = match value {
                    Stored::Int(i) => i.to_string(),
                    Stored::Str(s) => s,
                };
                let reply: redis::Value = redis::cmd("SET")
                    .arg(format!("{}{key}", self.prefix))
                    .arg(value)
                    .query_async(&mut c)
                    .await
                    .map_err(failed)?;
                match reply {
                    redis::Value::Okay => Ok(()),
                    redis::Value::SimpleString(s) if s.eq_ignore_ascii_case("OK") => Ok(()),
                    _ => Err(Error::Adapter(format!("Failed to set cache key \"{key}\"."))),
                }
            })
        }

        /// `INCRBY`.
        fn increment<'a>(&'a self, key: &'a str, by: i64) -> BoxFuture<'a, Result<i64, Error>> {
            Box::pin(async move {
                let mut c = self.connection.clone();
                redis::cmd("INCRBY")
                    .arg(format!("{}{key}", self.prefix))
                    .arg(by)
                    .query_async(&mut c)
                    .await
                    .map_err(failed)
            })
        }

        /// `DEL`.
        fn delete<'a>(&'a self, key: &'a str) -> BoxFuture<'a, Result<(), Error>> {
            Box::pin(async move {
                let mut c = self.connection.clone();
                let _: i64 =
                    redis::cmd("DEL").arg(format!("{}{key}", self.prefix)).query_async(&mut c).await.map_err(failed)?;
                Ok(())
            })
        }
    }
}
