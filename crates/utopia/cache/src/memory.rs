//! [`Memory`] and [`None`]: in-process adapters.

use std::future::Future;
use std::sync::Mutex;

use indexmap::IndexMap;
use php_std::types::Value;

use crate::adapter::{Adapter, Snapshot, blank, coerce, empty, now};
use crate::versioned::fresh as fresh_version;
use crate::{Error, Result};

/// Entries in this process, gone when it exits. Hashes are ignored: one
/// entry per key. Keeps opaque versions ([`Adapter::compare_and_swap`]).
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
    store: Mutex<IndexMap<String, Entry>>,
}

/// One key: when it was written, its data (`false` for a key that only holds
/// a version) and its version, once one was asked for or written.
///
/// PHP: `array{time: int, data: mixed, version?: string}`.
#[derive(Debug, Clone)]
struct Entry {
    time: i64,
    data: Value,
    version: Option<String>,
}

impl Memory {
    /// An empty store.
    pub fn new() -> Self {
        Self::default()
    }

    fn with<T>(&self, f: impl FnOnce(&mut IndexMap<String, Entry>) -> T) -> T {
        f(&mut self.store.lock().unwrap_or_else(|e| e.into_inner()))
    }
}

impl Adapter for Memory {
    fn load(&self, key: &str, ttl: i64, _hash: &str) -> impl Future<Output = Result<Option<Value>>> + Send {
        let result = if blank(key) {
            None
        } else {
            self.with(|store| match store.get(key) {
                Some(entry) if fresh(entry.time, ttl, now()) => hit(&entry.data),
                _ => None,
            })
        };
        async move { Ok(result) }
    }

    fn save(&self, key: &str, data: &Value, _hash: &str, _ttl: i64) -> impl Future<Output = Result<bool>> + Send {
        let result = coerce(data, "Utopia\\Cache\\Adapter\\Memory::save(): Argument #2 ($data)").map(|data| {
            let stored = !blank(key) && !empty(&data);
            if stored {
                let entry = Entry { time: now(), data: data.into_owned(), version: Some(fresh_version()) };
                self.with(|store| store.insert(key.to_owned(), entry));
            }
            stored
        });
        async move { result }
    }

    fn touch(&self, key: &str, _hash: &str) -> impl Future<Output = Result<bool>> + Send {
        let touched = !blank(key)
            && self.with(|store| match store.get_mut(key) {
                Some(entry) => {
                    entry.time = now();
                    entry.version = Some(fresh_version());
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

    fn supports_versioning(&self) -> bool {
        true
    }

    async fn version(&self, key: &str) -> Result<String> {
        Ok(self.with(|store| version(store, key)))
    }

    async fn load_versioned(&self, key: &str) -> Result<Snapshot> {
        let version = self.with(|store| version(store, key));
        let value = self.load(key, i64::MAX, "").await?;
        Ok(Snapshot { version, value })
    }

    async fn compare_and_swap(&self, key: &str, data: &Value, hash: &str, version: &str) -> Result<Option<String>> {
        if self.with(|store| self::version(store, key)) != version {
            return Ok(None);
        }
        if !self.save(key, data, hash, 0).await? {
            return Err(Error::Unavailable { message: "Unable to save cache snapshot".to_owned(), previous: None });
        }
        let next = fresh_version();
        self.with(|store| {
            if let Some(entry) = store.get_mut(key) {
                entry.version = Some(next.clone());
            }
        });
        Ok(Some(next))
    }
}

/// The data of an entry, `None` for a key that only holds a version.
fn hit(data: &Value) -> Option<Value> {
    match data {
        Value::Bool(false) => None,
        data => Some(data.clone()),
    }
}

/// The key's version, giving a missing key an entry that holds only a
/// version, as PHP's `getVersion()` does (`$this->store[$key] ??= ...`).
fn version(store: &mut IndexMap<String, Entry>, key: &str) -> String {
    let entry =
        store.entry(key.to_owned()).or_insert_with(|| Entry { time: now(), data: Value::Bool(false), version: None });
    entry.version.get_or_insert_with(fresh_version).clone()
}

/// `time + ttl > now`, overflowing into floats like PHP.
pub(crate) fn fresh(time: i64, ttl: i64, now: i64) -> bool {
    match time.checked_add(ttl) {
        Some(expiry) => expiry > now,
        None => (time as f64 + ttl as f64) > now as f64,
    }
}
