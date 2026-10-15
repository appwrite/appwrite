//! The PHP tests of hooks and events (`tests/unit/Hook/*`,
//! `tests/unit/Event/*`), on the Memory adapter. PHP runs several of them on
//! SQLite through `HookFixture::sqlite()`; what they check does not depend
//! on the engine, so they run on Memory here, except the parts that need an
//! SQL engine (write hooks, transforms, upserts), which wait for the SQL
//! adapters.

mod events;
mod hooks;

use std::sync::atomic::{AtomicU64, Ordering};
use std::sync::{Arc, Mutex};

use async_trait::async_trait;

use super::{Database, Hook};
use crate::adapter::memory::Memory;
use crate::attribute::Attribute;
use crate::collection::Collection;
use crate::document::Document;
use crate::error::{Error, Result};
use crate::event::{Domain, Event};
use crate::hook::{Lifecycle, Named, Selective};
use crate::permission::Permission;
use crate::role::Role;
use crate::validator::authorization::Authorization;
use crate::value::{self, Array, Value};

pub(crate) use super::testing::block_on;

/// `HookFixture::COLLECTION`.
const COLLECTION: &str = "posts";

static NAMESPACES: AtomicU64 = AtomicU64::new(0);

/// A database over `adapter` with the `posts` collection
/// (`HookFixture::database()`), caching in `cache`.
async fn fixture_on(adapter: Arc<dyn crate::adapter::Adapter>, cache: Arc<utopia_cache::Cache>) -> Database {
    let database = Database::new(adapter, cache);
    database.set_authorization(Arc::new(Authorization::new(true)));
    database.set_database("hooks").set_namespace(&format!("hooks_{}", NAMESPACES.fetch_add(1, Ordering::Relaxed)));
    database.create(None).await.unwrap();
    let any = Role::any();
    let posts = Collection::create(
        COLLECTION,
        "",
        vec![Attribute::string("title", 64), Attribute::integer("views")],
        Vec::new(),
        Some(vec![
            Permission::create(&any),
            Permission::read(&any),
            Permission::update(&any),
            Permission::delete(&any),
        ]),
        true,
        Array::new(),
    )
    .unwrap();
    database.create_collection(posts).await.unwrap();
    database
}

/// `HookFixture::memory()`.
async fn memory() -> Database {
    fixture_on(Arc::new(Memory::new()), Arc::new(utopia_cache::Cache::from_dyn(Arc::new(utopia_cache::None)))).await
}

/// `HookFixture::seed()`.
async fn seed(database: &Database, ids: &[&str]) {
    for (index, id) in ids.iter().enumerate() {
        let document = Document::from_pairs([
            ("$id", value::string(*id)),
            ("title", value::string(*id)),
            ("views", Value::Int(index as i64 + 1)),
        ])
        .unwrap();
        database.create_document(COLLECTION, document).await.unwrap();
    }
}

/// `RecordingLifecycle` and `NamedRecordingLifecycle`.
#[derive(Default)]
struct Recorder {
    name: Option<String>,
    events: Mutex<Vec<Domain>>,
}

#[async_trait]
impl Lifecycle for Recorder {
    async fn handle(&self, event: &Domain) -> Result<()> {
        self.events.lock().unwrap().push(event.clone());
        Ok(())
    }

    fn named(&self) -> Option<&dyn Named> {
        self.name.as_ref().map(|_| self as &dyn Named)
    }
}

impl Named for Recorder {
    fn name(&self) -> &str {
        self.name.as_deref().unwrap_or("")
    }
}

impl Recorder {
    fn new() -> Arc<Recorder> {
        Arc::new(Recorder::default())
    }

    fn named(name: &str) -> Arc<Recorder> {
        Arc::new(Recorder { name: Some(name.to_owned()), events: Mutex::default() })
    }

    /// `getEvents()`.
    fn events(&self) -> Vec<Event> {
        self.events.lock().unwrap().iter().map(Domain::event).collect()
    }

    /// `received()`.
    fn received(&self, event: Event) -> Vec<Domain> {
        self.events.lock().unwrap().iter().filter(|e| e.event() == event).cloned().collect()
    }
}

/// `FailingLifecycle`, named when given a name.
struct Failing {
    event: Event,
    error: Error,
    name: Option<String>,
}

#[async_trait]
impl Lifecycle for Failing {
    async fn handle(&self, event: &Domain) -> Result<()> {
        if event.event() == self.event {
            return Err(self.error.clone());
        }
        Ok(())
    }

    fn named(&self) -> Option<&dyn Named> {
        self.name.as_ref().map(|_| self as &dyn Named)
    }
}

impl Named for Failing {
    fn name(&self) -> &str {
        self.name.as_deref().unwrap_or("")
    }
}

fn failing(event: Event, error: Error) -> Hook {
    Hook::lifecycle(Arc::new(Failing { event, error, name: None }))
}

/// A recorder handling only `DocumentCreate` (a `Selective` hook).
#[derive(Default)]
struct Selecting(Mutex<Vec<Event>>);

#[async_trait]
impl Lifecycle for Selecting {
    async fn handle(&self, event: &Domain) -> Result<()> {
        self.0.lock().unwrap().push(event.event());
        Ok(())
    }

    fn selective(&self) -> Option<&dyn Selective> {
        Some(self)
    }
}

impl Selective for Selecting {
    fn handles(&self, event: Event) -> bool {
        event == Event::DocumentCreate
    }
}
