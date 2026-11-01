//! Serialised access to a shared connection.

use utopia_lock::{Lock, Mutex, Wait};

use super::{BoxFuture, Connection, Reply};
use crate::error::{Error, Result};

/// Wraps a [`Connection`] and runs every command behind one lock, so tasks
/// sharing the connection cannot interleave on it. The lock is waited on
/// forever: a command is never dropped because the connection is busy.
///
/// ```
/// use utopia_queue::connection::{Locking, Redis};
///
/// let shared = Locking::new(Redis::new("localhost", 6379));
/// # let _ = shared;
/// ```
///
/// ```php
/// use Utopia\Queue\Connection\Locking;
/// use Utopia\Queue\Connection\Redis;
///
/// $shared = new Locking(new Redis('redis'));
/// var_dump($shared instanceof \Utopia\Queue\Connection); // bool(true)
/// ```
pub struct Locking<C, L = Mutex> {
    connection: C,
    lock: L,
}

impl<C: Connection> Locking<C, Mutex> {
    /// Guards `connection` with an in-process mutex.
    pub fn new(connection: C) -> Self {
        Self { connection, lock: Mutex::new() }
    }
}

impl<C: Connection, L: Lock + Send> Locking<C, L> {
    /// Guards `connection` with any lock.
    pub fn with_lock(connection: C, lock: L) -> Self {
        Self { connection, lock }
    }

    /// The wrapped connection.
    pub fn inner(&self) -> &C {
        &self.connection
    }
}

fn lock_error(e: utopia_lock::Error) -> Error {
    Error::Other { class: e.php_class().to_owned(), message: e.to_string() }
}

impl<C: Connection, L: Lock + Send> Connection for Locking<C, L> {
    fn query(&self, cmd: redis::Cmd, idempotent: bool) -> BoxFuture<'_, Result<Option<Reply>>> {
        Box::pin(async move {
            self.lock.with_lock(Wait::Forever, self.connection.query(cmd, idempotent)).await.map_err(lock_error)?
        })
    }

    fn execute<'a>(
        &'a self,
        script: &'a str,
        keys: &'a [Vec<u8>],
        args: &'a [Vec<u8>],
    ) -> BoxFuture<'a, Result<Reply>> {
        Box::pin(async move {
            self.lock.with_lock(Wait::Forever, self.connection.execute(script, keys, args)).await.map_err(lock_error)?
        })
    }

    fn ping(&self) -> BoxFuture<'_, bool> {
        Box::pin(async move { self.lock.with_lock(Wait::Forever, self.connection.ping()).await.unwrap_or(false) })
    }

    fn close(&self) -> BoxFuture<'_, ()> {
        Box::pin(async move {
            let _ = self.lock.with_lock(Wait::Forever, self.connection.close()).await;
        })
    }
}
