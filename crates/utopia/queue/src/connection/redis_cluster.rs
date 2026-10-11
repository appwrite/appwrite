//! A Redis Cluster.

use std::time::Duration;

use redis::cluster::ClusterClient;
use redis::cluster_async::ClusterConnection;
use tokio::sync::Mutex;
use utopia_dsn::Dsn;

use super::redis::jitter;
use super::{BoxFuture, Connection, Reply, server_message};
use crate::error::{Error, Result};

const CONNECT_MAX_ATTEMPTS: u32 = 5;
const CONNECT_BACKOFF_MS: u64 = 100;
const CONNECT_MAX_BACKOFF_MS: u64 = 3_000;

/// A connection to a Redis Cluster through its seed nodes.
///
/// Scripts run with `EVAL` on the node owning their keys, so every key of
/// one script must share a hash tag: give queues a namespace like
/// `{utopia-queue}`. A script whose keys do not is refused before anything
/// is sent.
///
/// ```
/// use utopia_dsn::Dsn;
/// use utopia_queue::connection::RedisCluster;
///
/// let dsn = Dsn::parse("redis-cluster://[node-1:7000;node-2]:7001")?;
/// let cluster = RedisCluster::from_dsn(&dsn, -1.0, -1.0)?;
/// assert_eq!(cluster.seeds(), ["node-1:7000", "node-2:7001"]);
/// # Ok::<(), Box<dyn std::error::Error>>(())
/// ```
///
/// ```php
/// use Utopia\DSN\DSN;
/// use Utopia\Queue\Connection\RedisCluster;
///
/// $cluster = RedisCluster::fromDSN(new DSN('redis-cluster://[node-1:7000;node-2]:7001'));
/// var_dump($cluster instanceof RedisCluster); // bool(true)
/// ```
pub struct RedisCluster {
    seeds: Vec<String>,
    connect_timeout: f64,
    read_timeout: f64,
    user: Option<String>,
    password: Option<String>,
    conn: Mutex<Option<ClusterConnection>>,
}

impl RedisCluster {
    /// A cluster reached through `seeds` (`host:port`), with timeouts in seconds
    /// (negative for none) and credentials.
    pub fn new(
        seeds: Vec<String>,
        connect_timeout: f64,
        read_timeout: f64,
        user: Option<String>,
        password: Option<String>,
    ) -> Self {
        Self { seeds, connect_timeout, read_timeout, user, password, conn: Mutex::new(None) }
    }

    /// `redis-cluster://[user]:[password]@[host:port;host:port]`. A port
    /// after the list applies to every seed naming none. Fails when the DSN
    /// names no seed.
    pub fn from_dsn(dsn: &Dsn, connect_timeout: f64, read_timeout: f64) -> Result<Self> {
        let port = dsn.port();
        let seeds: Vec<String> = dsn
            .host()
            .trim_matches(|c| c == '[' || c == ']')
            .split(';')
            .map(str::trim)
            .filter(|s| !s.is_empty())
            .map(|s| match port {
                Some(p) if !s.contains(':') => format!("{s}:{p}"),
                _ => s.to_owned(),
            })
            .collect();
        if seeds.is_empty() {
            return Err(Error::InvalidArgument("Redis cluster DSN names no seed hosts.".into()));
        }
        let text = |b: Option<&[u8]>| b.map(|b| String::from_utf8_lossy(b).into_owned());
        Ok(Self::new(seeds, connect_timeout, read_timeout, text(dsn.user()), text(dsn.password())))
    }

    /// The seed nodes.
    pub fn seeds(&self) -> &[String] {
        &self.seeds
    }

    async fn open(&self) -> Result<ClusterConnection> {
        let nodes: Vec<String> = self.seeds.iter().map(|s| format!("redis://{s}")).collect();
        let mut attempt = 1;
        loop {
            let mut builder = ClusterClient::builder(nodes.clone());
            if let Some(password) = self.password.as_deref().filter(|p| !p.is_empty()) {
                builder = builder.password(password.to_owned());
                if let Some(user) = self.user.as_deref().filter(|u| !u.is_empty()) {
                    builder = builder.username(user.to_owned());
                }
            }
            if self.connect_timeout > 0.0 {
                builder = builder.connection_timeout(Duration::from_secs_f64(self.connect_timeout));
            }
            if self.read_timeout > 0.0 {
                builder = builder.response_timeout(Duration::from_secs_f64(self.read_timeout));
            }
            let result = match builder.build() {
                Ok(client) => client.get_async_connection().await,
                Err(e) => Err(e),
            };
            match result {
                Ok(conn) => return Ok(conn),
                Err(e) if attempt >= CONNECT_MAX_ATTEMPTS => {
                    return Err(Error::RedisCluster(format!(
                        "Failed to connect to Redis cluster nodes [{}] after {} attempts: {}",
                        self.seeds.join(", "),
                        CONNECT_MAX_ATTEMPTS,
                        server_message(&e)
                    )));
                }
                Err(_) => {
                    let backoff = CONNECT_MAX_BACKOFF_MS.min(CONNECT_BACKOFF_MS * (1 << (attempt - 1)));
                    tokio::time::sleep(Duration::from_millis(jitter(backoff))).await;
                    attempt += 1;
                }
            }
        }
    }

    async fn connection(&self) -> Result<ClusterConnection> {
        let mut guard = self.conn.lock().await;
        if let Some(conn) = guard.as_ref() {
            return Ok(conn.clone());
        }
        let conn = self.open().await?;
        *guard = Some(conn.clone());
        Ok(conn)
    }
}

/// The first `{tag}` of a key (`/\{([^{}]+)\}/`).
fn hash_tag(key: &[u8]) -> Option<&[u8]> {
    let mut i = 0;
    while i < key.len() {
        if key[i] == b'{' {
            let rest = &key[i + 1..];
            let end = rest.iter().position(|&b| b == b'{' || b == b'}');
            if let Some(end) = end
                && end > 0
                && rest[end] == b'}'
            {
                return Some(&rest[..end]);
            }
        }
        i += 1;
    }
    None
}

impl Connection for RedisCluster {
    fn query(&self, cmd: redis::Cmd, _idempotent: bool) -> BoxFuture<'_, Result<Option<Reply>>> {
        Box::pin(async move {
            let mut conn = self.connection().await?;
            match super::send(&mut conn, &cmd).await {
                Ok(reply) => match super::raised(&reply) {
                    Some(message) => Err(Error::RedisCluster(message)),
                    None => Ok(Some(reply)),
                },
                Err(e) if super::is_transport(&e) || super::raises(&server_message(&e)) => {
                    Err(Error::RedisCluster(server_message(&e)))
                }
                Err(_) => Ok(None),
            }
        })
    }

    fn execute<'a>(
        &'a self,
        script: &'a str,
        keys: &'a [Vec<u8>],
        args: &'a [Vec<u8>],
    ) -> BoxFuture<'a, Result<Reply>> {
        Box::pin(async move {
            let mut tag: Option<&[u8]> = None;
            for key in keys {
                match hash_tag(key) {
                    Some(t) if tag.is_none_or(|seen| seen == t) => tag = Some(t),
                    _ => {
                        return Err(Error::InvalidArgument(
                            "Atomic Redis queue operations require a shared hash tag in the queue namespace, for example {utopia-queue}. Migrate existing keys before changing the namespace.".into(),
                        ));
                    }
                }
            }
            let mut conn = self.connection().await?;
            let mut eval = redis::cmd("EVAL");
            eval.arg(script).arg(keys.len());
            for k in keys {
                eval.arg(k.as_slice());
            }
            for a in args {
                eval.arg(a.as_slice());
            }
            let reply = super::send(&mut conn, &eval).await.map_err(|e| Error::RedisCluster(server_message(&e)))?;
            match super::raised(&reply) {
                Some(message) => Err(Error::RedisCluster(message)),
                None => Ok(reply),
            }
        })
    }

    fn ping(&self) -> BoxFuture<'_, bool> {
        Box::pin(async move {
            match self.connection().await {
                Ok(mut conn) => redis::cmd("PING").query_async::<Reply>(&mut conn).await.is_ok(),
                Err(_) => false,
            }
        })
    }

    fn close(&self) -> BoxFuture<'_, ()> {
        Box::pin(async move {
            *self.conn.lock().await = None;
        })
    }
}

#[cfg(test)]
mod tests {
    use super::hash_tag;

    #[test]
    fn hash_tags() {
        assert_eq!(hash_tag(b"{utopia-queue}.queue.a"), Some(&b"utopia-queue"[..]));
        assert_eq!(hash_tag(b"{}.{x}"), Some(&b"x"[..]));
        assert_eq!(hash_tag(b"{{a}"), Some(&b"a"[..]));
        assert_eq!(hash_tag(b"plain"), None);
        assert_eq!(hash_tag(b"{open"), None);
    }
}
