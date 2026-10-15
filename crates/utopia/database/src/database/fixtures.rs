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
