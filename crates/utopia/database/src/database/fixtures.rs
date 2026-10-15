//! Test fixtures over the [`Memory`] adapter: the databases PHP's unit
//! tests build on `Adapter\Memory` (`Tests\Unit\Event\HookFixture` and the
//! per-test `database()` helpers), and a lifecycle hook recording what it
//! handles (`Tests\Unit\Event\RecordingLifecycle`).

#![allow(dead_code)]

use std::sync::atomic::{AtomicU64, Ordering};
use std::sync::{Arc, Mutex};

use async_trait::async_trait;
use utopia_cache::Cache;

use super::Database;
use crate::adapter::Adapter;
use crate::adapter::memory::Memory;
use crate::attribute::Attribute;
use crate::collection::Collection;
use crate::document::Document;
use crate::error::Result;
use crate::event::{Domain, Event};
use crate::hook::Lifecycle;
use crate::permission::Permission;
use crate::role::Role;
use crate::validator::authorization::Authorization;
use crate::value::{Array, Value, string};

/// `HookFixture::COLLECTION`.
pub(crate) const POSTS: &str = "posts";

/// A cache that stores nothing (`new Cache(new None())`).
pub(crate) fn no_cache() -> Arc<Cache> {
    Arc::new(Cache::from_dyn(Arc::new(utopia_cache::None)))
}

/// An in-process cache (`new Cache(new Memory())`).
pub(crate) fn memory_cache() -> Arc<Cache> {
    Arc::new(Cache::from_dyn(Arc::new(utopia_cache::Memory::new())))
}

/// A namespace no other test uses (`'<prefix>_'.uniqid()`).
pub(crate) fn unique(prefix: &str) -> String {
    static NEXT: AtomicU64 = AtomicU64::new(0);
    format!("{prefix}_{}", NEXT.fetch_add(1, Ordering::SeqCst))
}

/// A created database over `adapter` (`new Database($adapter, $cache)`,
/// `setDatabase()`, `setNamespace()`, `create()`), with a fresh
/// authorization when `authorization` (`setAuthorization(new
/// Authorization())`).
pub(crate) async fn created(
    adapter: Arc<dyn Adapter>,
    cache: Arc<Cache>,
    database: &str,
    namespace: &str,
    authorization: bool,
) -> Database {
    let db = Database::new(adapter, cache);
    if authorization {
        db.set_authorization(Arc::new(Authorization::new(true)));
    }
    db.set_database(database).set_namespace(&unique(namespace));
    db.create(None).await.expect("the database is created");
    db
}

/// The permissions `any` holds on a fixture collection.
pub(crate) fn any(kinds: &[&str]) -> Vec<String> {
    let role = Role::any();
    kinds
        .iter()
        .map(|k| match *k {
            "create" => Permission::create(&role),
            "read" => Permission::read(&role),
            "update" => Permission::update(&role),
            "delete" => Permission::delete(&role),
            other => panic!("no {other} permission"),
        })
        .collect()
}

/// A collection definition (`Collection::create(...)`).
pub(crate) fn definition(id: &str, attributes: Vec<Attribute>, permissions: Vec<String>, security: bool) -> Collection {
    Collection::create(id, id, attributes, Vec::new(), Some(permissions), security, Array::new()).unwrap()
}

/// `HookFixture::database()`: `posts` with `title` and `views`, any role
/// granted every permission.
pub(crate) async fn hooks(adapter: Arc<dyn Adapter>, cache: Arc<Cache>) -> Database {
    let db = created(adapter, cache, "hooks", "hooks", true).await;
    db.create_collection(definition(
        POSTS,
        vec![Attribute::string("title", 64), Attribute::integer("views")],
        any(&["create", "read", "update", "delete"]),
        false,
    ))
    .await
    .expect("the fixture collection is created");
    db
}

/// `HookFixture::memory()`.
pub(crate) async fn hooks_memory() -> Database {
    hooks(Arc::new(Memory::new()), no_cache()).await
}

/// `HookFixture::seed()`: a post per id, its title the id and its views
/// its position from 1.
pub(crate) async fn seed(db: &Database, ids: &[&str]) {
    for (index, id) in ids.iter().enumerate() {
        db.create_document(
            POSTS,
            Document::from_pairs([
                ("$id", string(*id)),
                ("title", string(*id)),
                ("views", Value::Int(index as i64 + 1)),
            ])
            .unwrap(),
        )
        .await
        .expect("the fixture document is created");
    }
}

/// A document of `pairs`.
pub(crate) fn doc(pairs: Vec<(&str, Value)>) -> Document {
    Document::from_pairs(pairs).unwrap()
}

/// What an [`ObservedCache`] runs before each operation: the PHP name of
/// the operation and its key; an error fails the operation.
pub(crate) type CacheInterceptor = Arc<dyn Fn(&str, &str) -> std::result::Result<(), String> + Send + Sync>;

/// A cache adapter over [`crate::cache::testing::Leasable`] counting its
/// operations and the loads of each key, and letting a test fail any of
/// them (`Tests\Unit\Cache\CountingCache`, and the anonymous failing
/// caches of PHP's tests).
pub(crate) struct ObservedCache {
    inner: Arc<crate::cache::testing::Leasable>,
    operations: Mutex<usize>,
    loads: Mutex<Vec<(String, usize)>>,
    interceptor: Mutex<Option<CacheInterceptor>>,
}

impl ObservedCache {
    /// A counting cache over a new leasable cache, and the cache object.
    pub(crate) fn new() -> (Arc<Self>, Arc<Cache>) {
        let adapter = Arc::new(ObservedCache {
            inner: crate::cache::testing::Leasable::new(),
            operations: Mutex::new(0),
            loads: Mutex::new(Vec::new()),
            interceptor: Mutex::new(None),
        });
        (adapter.clone(), Arc::new(Cache::from_dyn(adapter)))
    }

    /// Runs `interceptor` before every operation from now on.
    pub(crate) fn intercept(
        &self,
        interceptor: impl Fn(&str, &str) -> std::result::Result<(), String> + Send + Sync + 'static,
    ) {
        *self.interceptor.lock().unwrap() = Some(Arc::new(interceptor));
    }

    /// `getOperations()`.
    pub(crate) fn operations(&self) -> usize {
        *self.operations.lock().unwrap()
    }

    /// How many times `key` was loaded (`getLoads()[$key]`).
    pub(crate) fn loads(&self, key: &str) -> usize {
        self.loads.lock().unwrap().iter().find(|(k, _)| k == key).map_or(0, |(_, n)| *n)
    }

    /// `resetOperations()`.
    pub(crate) fn reset(&self) {
        *self.operations.lock().unwrap() = 0;
        self.loads.lock().unwrap().clear();
    }

    fn count(&self, operation: &str, key: &str) -> utopia_cache::Result<()> {
        *self.operations.lock().unwrap() += 1;
        let interceptor = self.interceptor.lock().unwrap().clone();
        match interceptor {
            Some(i) => i(operation, key).map_err(utopia_cache::Error::Runtime),
            None => Ok(()),
        }
    }
}

impl utopia_cache::Adapter for ObservedCache {
    async fn load(&self, key: &str, ttl: i64, hash: &str) -> utopia_cache::Result<Option<php_std::Value>> {
        {
            let mut loads = self.loads.lock().unwrap();
            match loads.iter_mut().find(|(k, _)| k == key) {
                Some(entry) => entry.1 += 1,
                None => loads.push((key.to_owned(), 1)),
            }
        }
        self.count("load", key)?;
        self.inner.load(key, ttl, hash).await
    }

    async fn save(&self, key: &str, data: &php_std::Value, hash: &str, ttl: i64) -> utopia_cache::Result<bool> {
        self.count("save", key)?;
        self.inner.save(key, data, hash, ttl).await
    }

    async fn touch(&self, key: &str, hash: &str) -> utopia_cache::Result<bool> {
        self.count("touch", key)?;
        self.inner.touch(key, hash).await
    }

    async fn list(&self, key: &str) -> utopia_cache::Result<Vec<String>> {
        self.count("list", key)?;
        self.inner.list(key).await
    }

    async fn purge(&self, key: &str, hash: &str) -> utopia_cache::Result<bool> {
        self.count("purge", key)?;
        self.inner.purge(key, hash).await
    }

    async fn flush(&self) -> utopia_cache::Result<bool> {
        self.count("flush", "")?;
        self.inner.flush().await
    }

    async fn ping(&self) -> utopia_cache::Result<bool> {
        self.count("ping", "")?;
        self.inner.ping().await
    }

    async fn size(&self) -> utopia_cache::Result<i64> {
        self.count("getSize", "")?;
        self.inner.size().await
    }

    fn name(&self, key: Option<&str>) -> &str {
        self.inner.name(key)
    }

    fn leasable(&self) -> bool {
        true
    }

    async fn generation(&self, key: &str) -> utopia_cache::Result<String> {
        self.count("getGeneration", key)?;
        self.inner.generation(key).await
    }

    async fn save_with_lease(
        &self,
        key: &str,
        data: &php_std::Value,
        hash: &str,
        generation: &str,
    ) -> utopia_cache::Result<bool> {
        self.count("saveWithLease", key)?;
        self.inner.save_with_lease(key, data, hash, generation).await
    }
}

/// `RecordingLifecycle`: every event it handles, in order.
#[derive(Default)]
pub(crate) struct Recorder(Mutex<Vec<Domain>>);

impl Recorder {
    /// A recorder to register with `Hook::lifecycle`.
    pub(crate) fn new() -> Arc<Self> {
        Arc::new(Recorder::default())
    }

    /// The events of `kind` handled so far (`received()`).
    pub(crate) fn received(&self, kind: Event) -> Vec<Domain> {
        self.0.lock().unwrap().iter().filter(|e| e.event() == kind).cloned().collect()
    }

    /// Every event handled so far.
    pub(crate) fn all(&self) -> Vec<Domain> {
        self.0.lock().unwrap().clone()
    }
}

#[async_trait]
impl Lifecycle for Recorder {
    async fn handle(&self, event: &Domain) -> Result<()> {
        self.0.lock().unwrap().push(event.clone());
        Ok(())
    }
}
