//! A cache of registrar answers per domain (prices, availability), on any
//! key-value [`Store`].

use std::collections::HashMap;
use std::future::Future;
use std::pin::Pin;
use std::sync::{Arc, Mutex};

use serde_json::Value;

/// A boxed future, as [`Store`] returns them (the trait is object safe).
pub type BoxFuture<'a, T> = Pin<Box<dyn Future<Output = T> + Send + 'a>>;

/// Where a [`Cache`] keeps its entries: implement it over any key-value
/// store (Redis, Memcached, a database). Keys arrive lower-cased.
///
/// [`MemoryStore`] keeps entries in the process; [`NoStore`] keeps nothing.
pub trait Store: Send + Sync {
    /// The entry under `key` if it was saved less than `ttl` seconds ago.
    fn load<'a>(&'a self, key: &'a str, ttl: i64) -> BoxFuture<'a, Option<Value>>;

    /// Saves an entry; returns whether it was stored.
    fn save<'a>(&'a self, key: &'a str, data: &'a Value) -> BoxFuture<'a, bool>;

    /// Removes an entry; returns whether one was removed (a store that keeps
    /// nothing reports `true`).
    fn purge<'a>(&'a self, key: &'a str) -> BoxFuture<'a, bool>;
}

/// Whether a value counts as empty: `false`, `0`, `0.0`, `""`, `"0"`, `[]`,
/// `null`.
///
/// PHP: `empty()` of a cached value.
fn empty(data: &Value) -> bool {
    match data {
        Value::Null => true,
        Value::Bool(b) => !b,
        Value::Number(n) => n.as_f64() == Some(0.0),
        Value::String(s) => s.is_empty() || s == "0",
        Value::Array(a) => a.is_empty(),
        Value::Object(o) => o.is_empty(),
    }
}

/// A store that keeps entries in this process, each with the second it was
/// saved. Empty values, and the keys `""` and `"0"`, are not stored.
///
/// PHP: `Utopia\Cache\Adapter\Memory`.
#[derive(Debug, Default)]
pub struct MemoryStore {
    entries: Mutex<HashMap<String, (i64, Value)>>,
}

impl MemoryStore {
    /// An empty store.
    pub fn new() -> Self {
        Self::default()
    }
}

fn usable_key(key: &str) -> bool {
    !key.is_empty() && key != "0"
}

impl Store for MemoryStore {
    fn load<'a>(&'a self, key: &'a str, ttl: i64) -> BoxFuture<'a, Option<Value>> {
        Box::pin(async move {
            if !usable_key(key) {
                return None;
            }
            let entries = self.entries.lock().unwrap_or_else(|e| e.into_inner());
            let (time, data) = entries.get(key)?;
            (time.saturating_add(ttl) > php_std::datetime::time()).then(|| data.clone())
        })
    }

    fn save<'a>(&'a self, key: &'a str, data: &'a Value) -> BoxFuture<'a, bool> {
        Box::pin(async move {
            if !usable_key(key) || empty(data) {
                return false;
            }
            let mut entries = self.entries.lock().unwrap_or_else(|e| e.into_inner());
            entries.insert(key.to_owned(), (php_std::datetime::time(), data.clone()));
            true
        })
    }

    fn purge<'a>(&'a self, key: &'a str) -> BoxFuture<'a, bool> {
        Box::pin(async move {
            usable_key(key) && self.entries.lock().unwrap_or_else(|e| e.into_inner()).remove(key).is_some()
        })
    }
}

/// A store that keeps nothing: every load misses, every save is refused, every
/// purge succeeds.
///
/// PHP: `Utopia\Cache\Adapter\None`.
#[derive(Debug, Clone, Copy, Default)]
pub struct NoStore;

impl Store for NoStore {
    fn load<'a>(&'a self, _key: &'a str, _ttl: i64) -> BoxFuture<'a, Option<Value>> {
        Box::pin(async { None })
    }

    fn save<'a>(&'a self, _key: &'a str, _data: &'a Value) -> BoxFuture<'a, bool> {
        Box::pin(async { false })
    }

    fn purge<'a>(&'a self, _key: &'a str) -> BoxFuture<'a, bool> {
        Box::pin(async { true })
    }
}

/// Registrar answers cached per domain, under `domain:<domain>` keys
/// (lower-cased) of a [`Store`]. Cloning shares the store.
///
/// ```
/// use serde_json::json;
/// use utopia_domains::{Cache, MemoryStore};
///
/// let cache = Cache::new(MemoryStore::new());
/// utopia_async::Runtime::new()?.block_on(async {
///     assert!(cache.save("example.com", &json!({"price": 12.99})).await);
///     assert_eq!(cache.load("example.com", 3600).await, Some(json!({"price": 12.99})));
///     assert!(cache.purge("example.com").await);
///     assert_eq!(cache.load("example.com", 3600).await, None);
/// });
/// # Ok::<(), std::io::Error>(())
/// ```
///
/// ```php
/// use Utopia\Cache\Adapter\Memory;
/// use Utopia\Cache\Cache as UtopiaCache;
/// use Utopia\Domains\Cache;
///
/// $cache = new Cache(new UtopiaCache(new Memory()));
/// $cache->save('example.com', ['price' => 12.99]);
/// var_dump($cache->load('example.com', 3600)['price']); // float(12.99)
/// var_dump($cache->purge('example.com'));               // bool(true)
/// var_dump($cache->load('example.com', 3600));          // bool(false)
/// ```
#[derive(Clone)]
pub struct Cache {
    store: Arc<dyn Store>,
}

impl std::fmt::Debug for Cache {
    /// Writes `Cache`; the store is not shown.
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.write_str("Cache")
    }
}

impl Cache {
    /// A cache on a store.
    pub fn new(store: impl Store + 'static) -> Self {
        Self { store: Arc::new(store) }
    }

    /// A cache on a shared store.
    pub fn shared(store: Arc<dyn Store>) -> Self {
        Self { store }
    }

    fn key(domain: &str) -> String {
        let key = format!("domain:{domain}");
        String::from_utf8(php_std::string::strtolower(key.as_bytes()).into_owned()).unwrap_or(key)
    }

    /// What was saved for the domain less than `ttl` seconds ago.
    ///
    /// PHP: `load()`.
    pub async fn load(&self, domain: &str, ttl: i64) -> Option<Value> {
        self.store.load(&Self::key(domain), ttl).await
    }

    /// Keeps `data` for the domain; returns whether it was stored.
    ///
    /// PHP: `save()`.
    pub async fn save(&self, domain: &str, data: &Value) -> bool {
        self.store.save(&Self::key(domain), data).await
    }

    /// Forgets the domain.
    ///
    /// PHP: `purge()`.
    pub async fn purge(&self, domain: &str) -> bool {
        self.store.purge(&Self::key(domain)).await
    }
}
