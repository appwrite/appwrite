//! Publisher for `utopia-php/queue` Redis brokers.
//!
//! Messages are pushed with `LPUSH utopia-queue.queue.<name>` as a JSON
//! envelope `{"pid","queue","timestamp","payload"}`; PHP workers pop from the
//! other end. The payload is any `Serialize` value, so callers can embed
//! pre-encoded JSON (`serde_json::value::RawValue`) without re-serialising.

use std::time::{SystemTime, UNIX_EPOCH};

use redis::aio::ConnectionManager;
use serde::Serialize;

pub const DEFAULT_NAMESPACE: &str = "utopia-queue";

/// A named queue.
#[derive(Debug, Clone)]
pub struct Queue {
    pub name: String,
    pub namespace: String,
}

impl Queue {
    pub fn new(name: impl Into<String>) -> Self {
        Self { name: name.into(), namespace: DEFAULT_NAMESPACE.to_owned() }
    }

    pub fn key(&self) -> String {
        format!("{}.queue.{}", self.namespace, self.name)
    }
}

#[derive(Serialize)]
struct Envelope<'a, P: Serialize> {
    pid: String,
    queue: &'a str,
    timestamp: u64,
    payload: &'a P,
}

/// Publishes messages to Redis-backed queues.
#[derive(Clone)]
pub struct Publisher {
    conn: ConnectionManager,
}

impl Publisher {
    pub fn new(conn: ConnectionManager) -> Self {
        Self { conn }
    }

    /// Serialises the envelope and pushes it on the queue.
    pub async fn publish<P: Serialize>(&self, queue: &Queue, payload: &P) -> Result<(), Error> {
        let envelope = Envelope { pid: pid(), queue: &queue.name, timestamp: now(), payload };
        let body = serde_json::to_vec(&envelope)?;
        let mut conn = self.conn.clone();
        let _: i64 = redis::cmd("LPUSH").arg(queue.key()).arg(body).query_async(&mut conn).await?;
        Ok(())
    }

    /// `PUBLISH channel message` (used for realtime fan-out).
    pub async fn broadcast(&self, channel: &str, message: &[u8]) -> Result<(), Error> {
        let mut conn = self.conn.clone();
        let _: i64 = redis::cmd("PUBLISH").arg(channel).arg(message).query_async(&mut conn).await?;
        Ok(())
    }
}

#[derive(Debug)]
pub enum Error {
    Encode(serde_json::Error),
    Redis(redis::RedisError),
}

impl std::fmt::Display for Error {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        match self {
            Error::Encode(e) => write!(f, "queue encode error: {e}"),
            Error::Redis(e) => write!(f, "queue redis error: {e}"),
        }
    }
}

impl std::error::Error for Error {}

impl From<serde_json::Error> for Error {
    fn from(e: serde_json::Error) -> Self {
        Error::Encode(e)
    }
}

impl From<redis::RedisError> for Error {
    fn from(e: redis::RedisError) -> Self {
        Error::Redis(e)
    }
}

fn now() -> u64 {
    SystemTime::now().duration_since(UNIX_EPOCH).map(|d| d.as_secs()).unwrap_or(0)
}

/// Same shape as PHP `uniqid('', true)`: 13 hex chars + "<digit>.<8 digits>".
fn pid() -> String {
    let now = SystemTime::now().duration_since(UNIX_EPOCH).unwrap_or_default();
    let mut bytes = [0u8; 4];
    let _ = getrandom::fill(&mut bytes);
    let lcg = u32::from_le_bytes(bytes) as f64 / (u32::MAX as f64 + 1.0) * 10.0;
    format!("{:08x}{:05x}{:.8}", now.as_secs(), now.subsec_micros(), lcg)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn key_and_pid() {
        assert_eq!(Queue::new("v1-webhooks").key(), "utopia-queue.queue.v1-webhooks");
        let p = pid();
        assert_eq!(p.len(), 23, "{p}");
    }
}
