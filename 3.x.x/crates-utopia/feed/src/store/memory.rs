use parking_lot::Mutex;
use std::sync::Arc;
use utopia_cloudevents::CloudEvent;

use super::{
    decode, encode, store_poll, unix_millis, validate_store, DEFAULT_MAX_SIZE,
    DEFAULT_POLL_INTERVAL,
};
use crate::{Appendable, FeedError, Id, Readable, Store, TIP};

#[derive(Debug)]
struct Inner {
    events: Vec<CloudEvent>,
    timestamp: i64,
    sequence: i64,
}

/// PHP `Utopia\Feed\Store\Memory`.
#[derive(Clone)]
pub struct Memory {
    name: String,
    max_size: usize,
    poll_interval: i64,
    inner: Arc<Mutex<Inner>>,
}

impl Memory {
    pub fn new(name: impl Into<String>) -> Result<Self, FeedError> {
        Self::with_limits(name, DEFAULT_MAX_SIZE, DEFAULT_POLL_INTERVAL)
    }

    pub fn with_limits(
        name: impl Into<String>,
        max_size: usize,
        poll_interval: i64,
    ) -> Result<Self, FeedError> {
        let name = name.into();
        validate_store(&name, max_size, poll_interval)?;
        Ok(Self {
            name,
            max_size,
            poll_interval,
            inner: Arc::new(Mutex::new(Inner {
                events: Vec::new(),
                timestamp: 0,
                sequence: -1,
            })),
        })
    }

    pub fn poll_interval(&self) -> i64 {
        self.poll_interval
    }

    pub fn max_size(&self) -> usize {
        self.max_size
    }
}

impl std::fmt::Debug for Memory {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Memory")
            .field("name", &self.name)
            .field("max_size", &self.max_size)
            .field("poll_interval", &self.poll_interval)
            .finish_non_exhaustive()
    }
}

impl Readable for Memory {
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
        let after = match last.as_deref() {
            None => None,
            Some(id) => Some(Id::decode(id)?),
        };
        let inner = self.inner.lock();
        let mut events = Vec::new();
        for event in &inner.events {
            if let Some(after) = after {
                if Id::decode(&event.id)? <= after {
                    continue;
                }
            }
            events.push(event.clone());
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
        Ok(self.inner.lock().events.last().map(|e| e.id.clone()))
    }
}

impl Appendable for Memory {
    fn append(&self, event: CloudEvent) -> Result<String, FeedError> {
        let encoded = encode(&event)?;
        let mut inner = self.inner.lock();
        let now = unix_millis();
        if now > inner.timestamp {
            inner.timestamp = now;
            inner.sequence = 0;
        } else {
            inner.sequence += 1;
        }
        let id = Id::encode(inner.timestamp, inner.sequence);
        let stored = decode(&id, &encoded)?;
        inner.events.push(stored);
        if inner.events.len() > self.max_size {
            let drain = inner.events.len() - self.max_size;
            inner.events.drain(0..drain);
        }
        Ok(id)
    }
}

impl Store for Memory {}
