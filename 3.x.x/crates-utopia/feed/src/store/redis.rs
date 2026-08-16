use redis::Connection;
use utopia_cloudevents::CloudEvent;
use utopia_pools::Recover;

use super::{
    decode_pairs, encode_fields, store_poll, validate_store, DEFAULT_MAX_SIZE,
    DEFAULT_POLL_INTERVAL,
};
use crate::{Appendable, FeedError, Id, Key, Readable, Store, TIP};

type RedisEntry = (String, Vec<(String, String)>);

/// Pooled Redis connection (PHP `\Redis`). Implements [`Recover`] so a pool reclaims it.
pub struct RedisConn {
    /// Underlying redis-rs connection.
    pub inner: Connection,
}

impl std::fmt::Debug for RedisConn {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("RedisConn").finish_non_exhaustive()
    }
}

impl Recover for RedisConn {
    fn recover(&mut self) -> bool {
        true
    }
}

fn is_wrong_type(err: &redis::RedisError) -> bool {
    err.to_string().contains("WRONGTYPE")
}

pub(crate) fn redis_append(
    conn: &mut Connection,
    name: &str,
    max_size: usize,
    event: &CloudEvent,
) -> Result<String, FeedError> {
    let fields = encode_fields(event)?;
    let mut cmd = redis::cmd("XADD");
    cmd.arg(Key::feed(name))
        .arg("MAXLEN")
        .arg("~")
        .arg(max_size)
        .arg("*");
    for (k, v) in &fields {
        cmd.arg(k).arg(v);
    }
    let id: Option<String> = cmd
        .query(conn)
        .map_err(|e| FeedError::transport(format!("Failed to append to the {name} feed: {e}")))?;
    match id {
        Some(id) if !id.is_empty() => Ok(id),
        _ => Err(FeedError::transport(format!(
            "Failed to append to the {name} feed"
        ))),
    }
}

pub(crate) fn redis_read(
    conn: &mut Connection,
    name: &str,
    last_event_id: Option<&str>,
    limit: i64,
    tip: Option<String>,
) -> Result<Vec<CloudEvent>, FeedError> {
    let last = if last_event_id == Some(TIP) {
        tip
    } else {
        last_event_id.map(str::to_owned)
    };
    let start = match last.as_deref() {
        None => "-".to_owned(),
        Some(id) => Id::after(id)?,
    };
    let entries: Result<Vec<RedisEntry>, _> = redis::cmd("XRANGE")
        .arg(Key::feed(name))
        .arg(&start)
        .arg("+")
        .arg("COUNT")
        .arg(limit.max(0))
        .query(conn);
    match entries {
        Ok(entries) => {
            let mut events = Vec::new();
            for (id, fields) in entries {
                events.push(decode_pairs(&id, &fields)?);
            }
            Ok(events)
        }
        Err(e) if is_wrong_type(&e) => Ok(Vec::new()),
        Err(e) => Err(FeedError::transport(format!(
            "Failed to read the {name} feed: {e}"
        ))),
    }
}

pub(crate) fn redis_tip(conn: &mut Connection, name: &str) -> Result<Option<String>, FeedError> {
    let entries: Result<Vec<RedisEntry>, _> = redis::cmd("XREVRANGE")
        .arg(Key::feed(name))
        .arg("+")
        .arg("-")
        .arg("COUNT")
        .arg(1)
        .query(conn);
    match entries {
        Ok(entries) => Ok(entries.into_iter().next().map(|(id, _)| id)),
        Err(e) if is_wrong_type(&e) => Ok(None),
        Err(e) => Err(FeedError::transport(format!(
            "Failed to read the {name} feed: {e}"
        ))),
    }
}

/// PHP `Utopia\Feed\Store\Redis`.
pub struct Redis {
    conn: parking_lot::Mutex<Connection>,
    name: String,
    max_size: usize,
    poll_interval: i64,
}

impl std::fmt::Debug for Redis {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Redis")
            .field("name", &self.name)
            .field("max_size", &self.max_size)
            .field("poll_interval", &self.poll_interval)
            .finish_non_exhaustive()
    }
}

impl Redis {
    pub fn new(conn: Connection, name: impl Into<String>) -> Result<Self, FeedError> {
        Self::with_limits(conn, name, DEFAULT_MAX_SIZE, DEFAULT_POLL_INTERVAL)
    }

    pub fn with_limits(
        conn: Connection,
        name: impl Into<String>,
        max_size: usize,
        poll_interval: i64,
    ) -> Result<Self, FeedError> {
        let name = name.into();
        validate_store(&name, max_size, poll_interval)?;
        Ok(Self {
            conn: parking_lot::Mutex::new(conn),
            name,
            max_size,
            poll_interval,
        })
    }
}

impl Readable for Redis {
    fn get_name(&self) -> &str {
        &self.name
    }

    fn is_store(&self) -> bool {
        true
    }

    fn read(&self, last_event_id: Option<&str>, limit: i64) -> Result<Vec<CloudEvent>, FeedError> {
        let mut conn = self.conn.lock();
        let tip = if last_event_id == Some(TIP) {
            redis_tip(&mut conn, &self.name)?
        } else {
            None
        };
        redis_read(&mut conn, &self.name, last_event_id, limit, tip)
    }

    fn poll(
        &self,
        last_event_id: Option<&str>,
        limit: i64,
        timeout: i64,
    ) -> Result<Vec<CloudEvent>, FeedError> {
        store_poll(
            self,
            last_event_id,
            limit,
            timeout,
            self.poll_interval as u64,
        )
    }

    fn tip(&self) -> Result<Option<String>, FeedError> {
        redis_tip(&mut self.conn.lock(), &self.name)
    }
}

impl Appendable for Redis {
    fn append(&self, event: CloudEvent) -> Result<String, FeedError> {
        redis_append(&mut self.conn.lock(), &self.name, self.max_size, &event)
    }
}

impl Store for Redis {}
