//! Nonces in Redis: [`Redis`], [`RedisCluster`] and [`RedisPool`].

use std::sync::Arc;

use redis::aio::{ConnectionLike, ConnectionManager};

use super::Store;
use crate::Result;
use crate::redis_conn::{self, Connection, Pool};

/// `SET key 1 NX EX ttl`: `true` when Redis set it, `false` when the key
/// existed (or for an error reply, which phpredis reports as `false`).
async fn claim<C: ConnectionLike + Send>(conn: &mut C, key: &[u8], ttl: i64, cluster: bool) -> Result<bool> {
    let reply: Option<redis::Value> = redis_conn::reply(
        redis::cmd("SET").arg(key).arg("1").arg("NX").arg("EX").arg(ttl).query_async(conn).await,
        cluster,
    )?;
    Ok(matches!(reply, Some(redis::Value::Okay)))
}

/// Nonces in one Redis server.
///
/// PHP: `Utopia\Abuse\Challenge\Nonce\Redis`.
#[derive(Clone)]
pub struct Redis<C = ConnectionManager> {
    conn: C,
}

impl<C> std::fmt::Debug for Redis<C> {
    /// Writes the store's name.
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.write_str("nonce::Redis")
    }
}

impl<C: ConnectionLike + Clone + Send + Sync> Redis<C> {
    /// Nonces over `conn`.
    pub fn new(conn: C) -> Self {
        Redis { conn }
    }
}

impl<C: ConnectionLike + Clone + Send + Sync> Store for Redis<C> {
    /// `SET key 1 NX EX ttl`.
    async fn claim(&self, key: &[u8], ttl: i64) -> Result<bool> {
        claim(&mut self.conn.clone(), key, ttl, false).await
    }
}

/// Nonces in a Redis Cluster.
///
/// PHP: `Utopia\Abuse\Challenge\Nonce\RedisCluster`.
#[cfg(feature = "redis-cluster")]
#[derive(Clone)]
pub struct RedisCluster {
    conn: redis::cluster_async::ClusterConnection,
}

#[cfg(feature = "redis-cluster")]
impl std::fmt::Debug for RedisCluster {
    /// Writes the store's name.
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.write_str("nonce::RedisCluster")
    }
}

#[cfg(feature = "redis-cluster")]
impl RedisCluster {
    /// Nonces over a cluster connection.
    pub fn new(conn: redis::cluster_async::ClusterConnection) -> Self {
        RedisCluster { conn }
    }
}

#[cfg(feature = "redis-cluster")]
impl Store for RedisCluster {
    /// `SET key 1 NX EX ttl`.
    async fn claim(&self, key: &[u8], ttl: i64) -> Result<bool> {
        claim(&mut self.conn.clone(), key, ttl, true).await
    }
}

/// Nonces in Redis or a Redis Cluster, through connections borrowed from a
/// [`Pool`] for each claim.
///
/// PHP: `Utopia\Abuse\Challenge\Nonce\RedisPool`.
pub struct RedisPool<P> {
    pool: Arc<P>,
}

impl<P> Clone for RedisPool<P> {
    /// Shares the pool.
    fn clone(&self) -> Self {
        RedisPool { pool: self.pool.clone() }
    }
}

impl<P> std::fmt::Debug for RedisPool<P> {
    /// Writes the store's name.
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.write_str("nonce::RedisPool")
    }
}

impl<P: Pool> RedisPool<P> {
    /// Nonces over connections from `pool`.
    pub fn new(pool: P) -> Self {
        RedisPool { pool: Arc::new(pool) }
    }

    /// Nonces over connections from a shared `pool`.
    pub fn shared(pool: Arc<P>) -> Self {
        RedisPool { pool }
    }
}

impl<P: Pool + 'static> Store for RedisPool<P> {
    /// `SET key 1 NX EX ttl` on a borrowed connection.
    async fn claim(&self, key: &[u8], ttl: i64) -> Result<bool> {
        let mut guard = self.pool.connection().await?;
        match &mut *guard {
            Connection::Redis(conn) => claim(conn, key, ttl, false).await,
            #[cfg(feature = "redis-cluster")]
            Connection::Cluster(conn) => claim(conn, key, ttl, true).await,
        }
    }
}
