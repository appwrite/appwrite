use std::collections::HashMap;
use std::sync::Arc;
use std::thread;
use std::time::Duration;

use parking_lot::Mutex;
use serde_json::Value;

use super::{Connection, StoredValue};
use crate::error::QueueError;

#[derive(Default)]
struct Inner {
    lists: HashMap<String, Vec<Value>>,
    values: HashMap<String, StoredValue>,
    counters: HashMap<String, i64>,
}

/// Minimal in-memory [`Connection`] (PHP `tests/Queue/E2E/Adapter/InMemoryConnection.php`).
#[derive(Clone, Default)]
pub struct InMemoryConnection {
    inner: Arc<Mutex<Inner>>,
    empty_yield: Duration,
}

impl std::fmt::Debug for InMemoryConnection {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("InMemoryConnection").finish_non_exhaustive()
    }
}

impl InMemoryConnection {
    pub fn new() -> Self {
        Self {
            inner: Arc::new(Mutex::new(Inner::default())),
            empty_yield: Duration::from_millis(5),
        }
    }

    pub fn with_empty_yield(mut self, duration: Duration) -> Self {
        self.empty_yield = duration;
        self
    }

    fn pop_locked(inner: &mut Inner, queue: &str, from_tail: bool) -> Option<Value> {
        let list = inner.lists.get_mut(queue)?;
        if list.is_empty() {
            return None;
        }
        if from_tail {
            list.pop()
        } else {
            Some(list.remove(0))
        }
    }

    fn pop(&self, queue: &str, from_tail: bool) -> Option<Value> {
        let value = {
            let mut inner = self.inner.lock();
            Self::pop_locked(&mut inner, queue, from_tail)
        };
        if value.is_none() && !self.empty_yield.is_zero() {
            thread::sleep(self.empty_yield);
        }
        value
    }

    fn is_array(value: &Value) -> bool {
        value.is_array() || value.is_object()
    }

    fn as_string(value: Value) -> Option<String> {
        match value {
            Value::String(s) => Some(s),
            _ => None,
        }
    }
}

impl Connection for InMemoryConnection {
    fn right_push_array(&self, queue: &str, payload: &Value) -> Result<bool, QueueError> {
        self.inner
            .lock()
            .lists
            .entry(queue.to_owned())
            .or_default()
            .push(payload.clone());
        Ok(true)
    }

    fn right_pop_array(&self, queue: &str, _timeout: i64) -> Result<Option<Value>, QueueError> {
        Ok(self.pop(queue, true).filter(Self::is_array))
    }

    fn right_pop_left_push_array(
        &self,
        queue: &str,
        destination: &str,
        timeout: i64,
    ) -> Result<Option<Value>, QueueError> {
        let value = self.right_pop_array(queue, timeout)?;
        if let Some(ref v) = value {
            self.inner
                .lock()
                .lists
                .entry(destination.to_owned())
                .or_default()
                .insert(0, v.clone());
        }
        Ok(value)
    }

    fn left_push_array(&self, queue: &str, payload: &Value) -> Result<bool, QueueError> {
        self.inner
            .lock()
            .lists
            .entry(queue.to_owned())
            .or_default()
            .insert(0, payload.clone());
        Ok(true)
    }

    fn left_pop_array(&self, queue: &str, _timeout: i64) -> Result<Option<Value>, QueueError> {
        Ok(self.pop(queue, false).filter(Self::is_array))
    }

    fn right_push(&self, queue: &str, payload: &str) -> Result<bool, QueueError> {
        self.inner
            .lock()
            .lists
            .entry(queue.to_owned())
            .or_default()
            .push(Value::String(payload.to_owned()));
        Ok(true)
    }

    fn right_pop(&self, queue: &str, _timeout: i64) -> Result<Option<String>, QueueError> {
        Ok(self.pop(queue, true).and_then(Self::as_string))
    }

    fn right_pop_left_push(
        &self,
        queue: &str,
        destination: &str,
        timeout: i64,
    ) -> Result<Option<String>, QueueError> {
        let value = self.right_pop(queue, timeout)?;
        if let Some(ref v) = value {
            self.inner
                .lock()
                .lists
                .entry(destination.to_owned())
                .or_default()
                .insert(0, Value::String(v.clone()));
        }
        Ok(value)
    }

    fn left_push(&self, queue: &str, payload: &str) -> Result<bool, QueueError> {
        self.inner
            .lock()
            .lists
            .entry(queue.to_owned())
            .or_default()
            .insert(0, Value::String(payload.to_owned()));
        Ok(true)
    }

    fn left_pop(&self, queue: &str, _timeout: i64) -> Result<Option<String>, QueueError> {
        Ok(self.pop(queue, false).and_then(Self::as_string))
    }

    fn list_remove(&self, queue: &str, key: &str) -> Result<bool, QueueError> {
        let mut inner = self.inner.lock();
        let Some(list) = inner.lists.get_mut(queue) else {
            return Ok(false);
        };
        let index = list.iter().position(|v| match v {
            Value::String(s) => s == key,
            _ => false,
        });
        if let Some(index) = index {
            list.remove(index);
            Ok(true)
        } else {
            Ok(false)
        }
    }

    fn list_size(&self, key: &str) -> Result<i64, QueueError> {
        Ok(self
            .inner
            .lock()
            .lists
            .get(key)
            .map_or(0, |l| l.len() as i64))
    }

    fn list_range(&self, key: &str, total: i64, offset: i64) -> Result<Vec<Value>, QueueError> {
        let inner = self.inner.lock();
        let list = inner.lists.get(key).cloned().unwrap_or_default();
        let start = usize::try_from(offset.max(0)).unwrap_or(0);
        let total = usize::try_from(total.max(0)).unwrap_or(0);
        Ok(list.into_iter().skip(start).take(total).collect())
    }

    fn remove(&self, key: &str) -> Result<bool, QueueError> {
        self.inner.lock().values.remove(key);
        Ok(true)
    }

    fn set(&self, key: &str, value: &str, _ttl: i64) -> Result<bool, QueueError> {
        self.inner
            .lock()
            .values
            .insert(key.to_owned(), StoredValue::String(value.to_owned()));
        Ok(true)
    }

    fn get(&self, key: &str) -> Result<Option<StoredValue>, QueueError> {
        Ok(self.inner.lock().values.get(key).cloned())
    }

    fn set_array(&self, key: &str, value: &Value, _ttl: i64) -> Result<bool, QueueError> {
        self.inner
            .lock()
            .values
            .insert(key.to_owned(), StoredValue::Array(value.clone()));
        Ok(true)
    }

    fn increment(&self, key: &str) -> Result<i64, QueueError> {
        let mut inner = self.inner.lock();
        let entry = inner.counters.entry(key.to_owned()).or_insert(0);
        *entry += 1;
        Ok(*entry)
    }

    fn decrement(&self, key: &str) -> Result<i64, QueueError> {
        let mut inner = self.inner.lock();
        let entry = inner.counters.entry(key.to_owned()).or_insert(0);
        *entry -= 1;
        Ok(*entry)
    }

    fn ping(&self) -> Result<bool, QueueError> {
        Ok(true)
    }

    fn close(&self) {}
}
