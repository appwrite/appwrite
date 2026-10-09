use std::time::{Duration, Instant};

use redis::aio::{ConnectionLike, ConnectionManager};

use crate::{Error, Lock, Wait};

const RELEASE: &str = r#"if redis.call("get", KEYS[1]) == ARGV[1] then
    return redis.call("del", KEYS[1])
else
    return 0
end"#;

const REFRESH: &str = r#"if redis.call("get", KEYS[1]) == ARGV[1] then
    return redis.call("expire", KEYS[1], ARGV[2])
else
    return 0
end"#;

const BACKOFF_MIN: f64 = 0.05;
const BACKOFF_MAX: f64 = 1.0;

type Logger = Box<dyn Fn(&str) + Send + Sync>;

/// A lease on a Redis key: `SET key token NX EX ttl`, released and refreshed
/// only by the holder of the token. Instances on the same key compete.
pub struct Distributed<C = ConnectionManager> {
    conn: C,
    key: String,
    ttl: i64,
    token: std::sync::Mutex<Option<String>>,
    logger: Option<Logger>,
}

/// phpredis answers `false` for an error reply and throws only when the
/// connection fails; do the same.
fn reply<T>(result: redis::RedisResult<T>) -> Result<Option<T>, Error> {
    match result {
        Ok(v) => Ok(Some(v)),
        Err(e) if e.is_io_error() || e.is_connection_refusal() || e.is_connection_dropped() || e.is_timeout() => {
            Err(e.into())
        }
        Err(_) => Ok(None),
    }
}

impl<C: ConnectionLike + Clone + Send + Sync> Distributed<C> {
    /// A lock on `key` whose lease expires after `ttl` seconds (PHP's default is 600).
    pub fn new(conn: C, key: impl Into<String>, ttl: i64) -> Self {
        Self { conn, key: key.into(), ttl, token: std::sync::Mutex::new(None), logger: None }
    }

    /// Receives contention and timeout messages.
    pub fn with_logger(mut self, logger: impl Fn(&str) + Send + Sync + 'static) -> Self {
        self.logger = Some(Box::new(logger));
        self
    }

    pub fn key(&self) -> &str {
        &self.key
    }

    /// The value the last successful acquisition wrote to the key, or `None`
    /// before one and after [`Lock::release`]. It does not prove the lease is
    /// still live: [`Distributed::is_held`] and [`Distributed::refresh`] do.
    pub fn token(&self) -> Option<String> {
        self.token.lock().unwrap_or_else(|e| e.into_inner()).clone()
    }

    fn set_token(&self, token: Option<String>) {
        *self.token.lock().unwrap_or_else(|e| e.into_inner()) = token;
    }

    /// Uses a token minted by another instance for this key, delegating
    /// token-guarded commands (refresh, release) to this one.
    pub fn adopt(&self, token: &str) -> Result<(), Error> {
        if token.is_empty() {
            return Err(Error::InvalidArgument("Token must not be empty".into()));
        }
        let mut current = self.token.lock().unwrap_or_else(|e| e.into_inner());
        if current.is_some() {
            return Err(Error::Logic("Cannot replace the token of a distributed lock".into()));
        }
        *current = Some(token.to_owned());
        Ok(())
    }

    /// Extends the lease to the full TTL if this instance still holds it.
    pub async fn refresh(&self) -> Result<bool, Error> {
        let Some(token) = self.token() else { return Ok(false) };
        let mut conn = self.conn.clone();
        let r: Option<i64> = reply(
            redis::cmd("EVAL")
                .arg(REFRESH)
                .arg(1)
                .arg(&self.key)
                .arg(&token)
                .arg(self.ttl.to_string())
                .query_async(&mut conn)
                .await,
        )?;
        Ok(r == Some(1))
    }

    /// Whether the key still holds this instance's token.
    pub async fn is_held(&self) -> Result<bool, Error> {
        let Some(token) = self.token() else { return Ok(false) };
        let mut conn = self.conn.clone();
        let current: Option<Option<String>> = reply(redis::cmd("GET").arg(&self.key).query_async(&mut conn).await)?;
        Ok(current.flatten().as_deref() == Some(token.as_str()))
    }

    fn log(&self, message: &str) {
        if let Some(logger) = &self.logger {
            logger(message);
        }
    }

    /// `host:pid:uniqid`, the same shape PHP writes.
    fn generate_token() -> String {
        format!(
            "{}:{}:{}",
            php_std::system::gethostname(),
            php_std::system::getmypid(),
            php_std::system::uniqid("", true)
        )
    }

    /// A random wait in the second half of `delay`, so waiters spread out.
    fn jitter(delay: f64) -> f64 {
        let mut bytes = [0u8; 8];
        let _ = getrandom::fill(&mut bytes);
        let r = (u64::from_le_bytes(bytes) >> 11) as f64 / (1u64 << 53) as f64;
        let half = delay / 2.0;
        half + r * half
    }
}

impl<C: ConnectionLike + Clone + Send + Sync> Lock for Distributed<C> {
    async fn acquire(&self, wait: Wait) -> Result<bool, Error> {
        if self.try_acquire().await? {
            return Ok(true);
        }
        let Wait::For(timeout) = wait else { return Ok(false) };
        let deadline = Instant::now() + timeout;
        let mut delay = BACKOFF_MIN;
        while Instant::now() < deadline {
            let remaining = deadline.saturating_duration_since(Instant::now()).as_secs_f64();
            let sleep = Self::jitter(delay).min(remaining);
            if sleep > 0.0 {
                tokio::time::sleep(Duration::from_micros((sleep * 1_000_000.0) as u64)).await;
            }
            if self.try_acquire().await? {
                return Ok(true);
            }
            self.log(&format!("Lock contention for {}, retrying", self.key));
            delay = (delay * 2.0).min(BACKOFF_MAX);
        }
        self.log(&format!(
            "Failed to acquire lock for {} within {}s",
            self.key,
            php_std::number::to_string(timeout.as_secs_f64())
        ));
        Ok(false)
    }

    async fn try_acquire(&self) -> Result<bool, Error> {
        let token = Self::generate_token();
        let mut conn = self.conn.clone();
        let set: Option<Option<String>> = reply(
            redis::cmd("SET").arg(&self.key).arg(&token).arg("NX").arg("EX").arg(self.ttl).query_async(&mut conn).await,
        )?;
        if set.flatten().is_some() {
            self.set_token(Some(token));
            return Ok(true);
        }
        Ok(false)
    }

    async fn release(&self) -> Result<(), Error> {
        let Some(token) = self.token() else { return Ok(()) };
        let mut conn = self.conn.clone();
        let _: Option<i64> =
            reply(redis::cmd("EVAL").arg(RELEASE).arg(1).arg(&self.key).arg(&token).query_async(&mut conn).await)?;
        self.set_token(None);
        Ok(())
    }

    fn contention(&self) -> Error {
        Error::Contention(format!("Failed to acquire distributed lock: {}", self.key))
    }
}
