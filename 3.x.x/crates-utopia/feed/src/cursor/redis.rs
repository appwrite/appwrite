use parking_lot::Mutex;
use redis::Connection;

use super::{cursor_key, Cursor};
use crate::FeedError;

pub(crate) fn redis_load(
    conn: &mut Connection,
    feed: &str,
    consumer: &str,
) -> Result<Option<String>, FeedError> {
    let key = cursor_key(feed, consumer)?;
    match redis::cmd("GET").arg(&key).query::<Option<String>>(conn) {
        Ok(v) => Ok(v.filter(|s| !s.is_empty())),
        Err(e) if e.to_string().contains("WRONGTYPE") => load_stream(conn, &key, consumer),
        Err(e) => Err(FeedError::transport(format!(
            "Failed to load the {consumer} cursor: {e}"
        ))),
    }
}

fn load_stream(
    conn: &mut Connection,
    key: &str,
    consumer: &str,
) -> Result<Option<String>, FeedError> {
    let entries: Vec<(String, Vec<(String, String)>)> = redis::cmd("XREVRANGE")
        .arg(key)
        .arg("+")
        .arg("-")
        .arg("COUNT")
        .arg(1)
        .query(conn)
        .map_err(|e| FeedError::transport(format!("Failed to load the {consumer} cursor: {e}")))?;
    Ok(entries.into_iter().next().map(|(id, _)| id))
}

pub(crate) fn redis_save(
    conn: &mut Connection,
    feed: &str,
    consumer: &str,
    event_id: &str,
) -> Result<(), FeedError> {
    let key = cursor_key(feed, consumer)?;
    redis::cmd("SET")
        .arg(key)
        .arg(event_id)
        .query::<()>(conn)
        .map_err(|e| FeedError::transport(format!("Failed to save the {consumer} cursor: {e}")))
}

pub(crate) fn redis_reset(
    conn: &mut Connection,
    feed: &str,
    consumer: &str,
) -> Result<(), FeedError> {
    let key = cursor_key(feed, consumer)?;
    redis::cmd("DEL")
        .arg(key)
        .query::<()>(conn)
        .map_err(|e| FeedError::transport(format!("Failed to reset the {consumer} cursor: {e}")))
}

/// PHP `Utopia\Feed\Cursor\Redis`.
pub struct Redis {
    conn: Mutex<Connection>,
}

impl std::fmt::Debug for Redis {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Redis").finish_non_exhaustive()
    }
}

impl Redis {
    #[must_use]
    pub fn new(conn: Connection) -> Self {
        Self {
            conn: Mutex::new(conn),
        }
    }
}

impl Cursor for Redis {
    fn load(&self, feed: &str, consumer: &str) -> Result<Option<String>, FeedError> {
        redis_load(&mut self.conn.lock(), feed, consumer)
    }

    fn save(&self, feed: &str, consumer: &str, event_id: &str) -> Result<(), FeedError> {
        redis_save(&mut self.conn.lock(), feed, consumer, event_id)
    }

    fn reset(&self, feed: &str, consumer: &str) -> Result<(), FeedError> {
        redis_reset(&mut self.conn.lock(), feed, consumer)
    }
}
