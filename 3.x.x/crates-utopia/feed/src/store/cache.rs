use std::sync::Arc;

use serde_json::{json, Map, Value};
use utopia_cache::{Cache as UtopiaCache, CacheValue, LoadResult, SaveResult};
use utopia_cloudevents::CloudEvent;

use super::{decode, encode, store_poll, unix_millis, validate_store, DEFAULT_POLL_INTERVAL};
use crate::{Appendable, FeedError, Id, Key, Readable, Store, TIP};

type StoredEntry = (String, Map<String, Value>);

/// PHP `Utopia\Feed\Store\Cache`.
#[derive(Clone)]
pub struct Cache {
    cache: Arc<UtopiaCache>,
    name: String,
    max_size: usize,
    ttl: i64,
    poll_interval: i64,
}

impl std::fmt::Debug for Cache {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Cache")
            .field("name", &self.name)
            .field("max_size", &self.max_size)
            .field("ttl", &self.ttl)
            .field("poll_interval", &self.poll_interval)
            .finish_non_exhaustive()
    }
}

impl Cache {
    /// PHP `Store\Cache::TTL`.
    pub const TTL: i64 = 30 * 24 * 60 * 60;
    /// PHP `Store\Cache::MAX_SIZE`.
    pub const MAX_SIZE: usize = 1_000;

    pub fn new(cache: UtopiaCache, name: impl Into<String>) -> Result<Self, FeedError> {
        Self::from_arc_limits(
            Arc::new(cache),
            name,
            Self::MAX_SIZE,
            Self::TTL,
            DEFAULT_POLL_INTERVAL,
        )
    }

    /// Share one [`UtopiaCache`] between a store and a cursor (PHP object handle).
    pub fn from_arc(cache: Arc<UtopiaCache>, name: impl Into<String>) -> Result<Self, FeedError> {
        Self::from_arc_limits(
            cache,
            name,
            Self::MAX_SIZE,
            Self::TTL,
            DEFAULT_POLL_INTERVAL,
        )
    }

    pub fn with_limits(
        cache: UtopiaCache,
        name: impl Into<String>,
        max_size: usize,
        ttl: i64,
        poll_interval: i64,
    ) -> Result<Self, FeedError> {
        Self::from_arc_limits(Arc::new(cache), name, max_size, ttl, poll_interval)
    }

    pub fn from_arc_limits(
        cache: Arc<UtopiaCache>,
        name: impl Into<String>,
        max_size: usize,
        ttl: i64,
        poll_interval: i64,
    ) -> Result<Self, FeedError> {
        let name = name.into();
        validate_store(&name, max_size, poll_interval)?;
        Ok(Self {
            cache,
            name,
            max_size,
            ttl,
            poll_interval,
        })
    }

    fn write(&self, key: &str, value: Value) -> Result<(), FeedError> {
        match self.cache.save(key, CacheValue::from_json(value), "") {
            Ok(SaveResult::Saved(_)) => Ok(()),
            Ok(SaveResult::Failed) => Err(FeedError::transport(format!(
                "Failed to append to the {} feed",
                self.name
            ))),
            Err(e) => Err(FeedError::transport(format!(
                "Failed to append to the {} feed: {e}",
                self.name
            ))),
        }
    }

    fn load_entries(&self) -> Result<Vec<StoredEntry>, FeedError> {
        let stored = self
            .cache
            .load(&Key::feed(&self.name), self.ttl, "")
            .map_err(|e| {
                FeedError::transport(format!("Failed to read the {} feed: {e}", self.name))
            })?;
        let LoadResult::Hit(value) = stored else {
            return Ok(Vec::new());
        };
        let json = value.into_json();
        let Value::Array(items) = json else {
            return Ok(Vec::new());
        };
        let mut entries = Vec::new();
        for entry in items {
            let Value::Object(map) = entry else {
                return Ok(Vec::new());
            };
            let Some(Value::String(id)) = map.get("id") else {
                return Ok(Vec::new());
            };
            if id.is_empty() {
                return Ok(Vec::new());
            }
            let fields = match map.get("fields") {
                Some(Value::Object(f)) => f.clone(),
                _ => return Ok(Vec::new()),
            };
            entries.push((id.clone(), fields));
        }
        Ok(entries)
    }

    fn caught_up(&self, last_event_id: &str) -> Result<bool, FeedError> {
        let tip = self
            .cache
            .load(&Key::tip(&self.name), self.ttl, "")
            .map_err(|e| {
                FeedError::transport(format!("Failed to read the {} feed: {e}", self.name))
            })?;
        let LoadResult::Hit(value) = tip else {
            return Ok(false);
        };
        let Some(tip) = value.as_str() else {
            return Ok(false);
        };
        if !Id::is_valid(tip) {
            return Ok(false);
        }
        Ok(Id::decode(tip)? <= Id::decode(last_event_id)?)
    }
}

impl Readable for Cache {
    fn get_name(&self) -> &str {
        &self.name
    }

    fn is_store(&self) -> bool {
        true
    }

    fn read(&self, last_event_id: Option<&str>, limit: i64) -> Result<Vec<CloudEvent>, FeedError> {
        let last = if last_event_id == Some(TIP) {
            self.tip()?
        } else {
            last_event_id.map(str::to_owned)
        };
        if let Some(ref id) = last {
            if self.caught_up(id)? {
                return Ok(Vec::new());
            }
        }
        let after = match last.as_deref() {
            None => None,
            Some(id) => Some(Id::decode(id)?),
        };
        let mut events = Vec::new();
        for (id, fields) in self.load_entries()? {
            if let Some(after) = after {
                if Id::decode(&id)? <= after {
                    continue;
                }
            }
            events.push(decode(&id, &fields)?);
            if events.len() as i64 >= limit {
                break;
            }
        }
        Ok(events)
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
        Ok(self.load_entries()?.last().map(|(id, _)| id.clone()))
    }
}

impl Appendable for Cache {
    fn append(&self, event: CloudEvent) -> Result<String, FeedError> {
        let mut entries = self.load_entries()?;
        let last = entries.last().map(|(id, _)| id.as_str());
        let now = unix_millis();
        let id = match last {
            None => Id::encode(now, 0),
            Some(last) => {
                let (timestamp, sequence) = Id::decode(last)?;
                if now > timestamp {
                    Id::encode(now, 0)
                } else {
                    Id::encode(timestamp, sequence + 1)
                }
            }
        };
        let fields = encode(&event)?;
        entries.push((id.clone(), fields));
        if entries.len() > self.max_size {
            let drain = entries.len() - self.max_size;
            entries.drain(0..drain);
        }
        let payload: Vec<Value> = entries
            .into_iter()
            .map(|(eid, fields)| json!({"id": eid, "fields": fields}))
            .collect();
        self.write(&Key::tip(&self.name), json!(id))?;
        self.write(&Key::feed(&self.name), json!(payload))?;
        Ok(id)
    }
}

impl Store for Cache {}
