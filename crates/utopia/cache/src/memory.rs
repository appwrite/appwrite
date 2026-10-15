//! [`Memory`] and [`None`]: in-process adapters.

use std::future::Future;
use std::sync::Mutex;

use indexmap::IndexMap;
use php_std::types::Value;

use crate::Result;
use crate::adapter::{Adapter, blank, coerce, empty, now};

/// Entries in this process, gone when it exits. Hashes are ignored: one
/// entry per key.
///
/// ```
/// use php_std::types::Value;
/// use utopia_cache::{Adapter, Memory};
///
/// # utopia_async::Runtime::new()?.block_on(async {
/// let memory = Memory::new();
/// memory.save("greeting", &Value::from("hello"), "", 0).await?;
/// assert_eq!(memory.load("greeting", 60, "").await?, Some(Value::from("hello")));
/// assert_eq!(memory.size().await?, 1);
/// # Ok::<(), utopia_cache::Error>(())
/// # })?;
/// # Ok::<(), Box<dyn std::error::Error>>(())
/// ```
///
/// ```php
/// use Utopia\Cache\Adapter\Memory;
///
/// $memory = new Memory();
/// $memory->save('greeting', 'hello');
/// echo $memory->load('greeting', 60), "\n"; // hello
/// echo $memory->getSize(), "\n"; // 1
/// ```
#[derive(Debug, Default)]
pub struct Memory {
    store: Mutex<IndexMap<String, (i64, Value)>>,
}

impl Memory {
    /// An empty store.
    pub fn new() -> Self {
        Self::default()
    }

    fn with<T>(&self, f: impl FnOnce(&mut IndexMap<String, (i64, Value)>) -> T) -> T {
        f(&mut self.store.lock().unwrap_or_else(|e| e.into_inner()))
    }
}

impl Adapter for Memory {
    fn load(&self, key: &str, ttl: i64, _hash: &str) -> impl Future<Output = Result<Option<Value>>> + Send {
        let result = if blank(key) {
            None
        } else {
            self.with(|store| match store.get(key) {
                Some((time, data)) if fresh(*time, ttl, now()) => Some(data.clone()),
                _ => None,
            })
        };
        async move { Ok(result) }
    }

    fn save(&self, key: &str, data: &Value, _hash: &str, _ttl: i64) -> impl Future<Output = Result<bool>> + Send {
        let result = coerce(data, "Utopia\\Cache\\Adapter\\Memory::save(): Argument #2 ($data)").map(|data| {
            let stored = !blank(key) && !empty(&data);
            if stored {
                self.with(|store| store.insert(key.to_owned(), (now(), data.into_owned())));
            }
            stored
        });
        async move { result }
    }

    fn touch(&self, key: &str, _hash: &str) -> impl Future<Output = Result<bool>> + Send {
        let touched = !blank(key)
            && self.with(|store| match store.get_mut(key) {
                Some(entry) => {
                    entry.0 = now();
                    true
                }
                None => false,
            });
        async move { Ok(touched) }
    }

    async fn list(&self, _key: &str) -> Result<Vec<String>> {
        Ok(Vec::new())
    }

    fn purge(&self, key: &str, _hash: &str) -> impl Future<Output = Result<bool>> + Send {
        let purged = !blank(key) && self.with(|store| store.shift_remove(key).is_some());
        async move { Ok(purged) }
    }

    fn flush(&self) -> impl Future<Output = Result<bool>> + Send {
        self.with(|store| store.clear());
        async { Ok(true) }
    }

    async fn ping(&self) -> Result<bool> {
        Ok(true)
    }

    fn size(&self) -> impl Future<Output = Result<i64>> + Send {
        let size = self.with(|store| store.len() as i64);
        async move { Ok(size) }
    }

    fn name(&self, _key: Option<&str>) -> &str {
        "memory"
    }
}

/// `time + ttl > now`, overflowing into floats like PHP.
pub(crate) fn fresh(time: i64, ttl: i64, now: i64) -> bool {
    match time.checked_add(ttl) {
        Some(expiry) => expiry > now,
        None => (time as f64 + ttl as f64) > now as f64,
    }
}
