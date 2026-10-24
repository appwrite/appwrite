//! Writes read the document under the definition they resolved, and a read
//! falls back to the database when the cache fails.
//!
//! PHP: `tests/unit/Documents/ResolvedCollectionReadTest.php` (on
//! `CountingMemory` and `CountingCache`) and the cache cases of
//! `Documents/GetDocumentFallbackTest.php`. `ResolvedCollectionReadTest`'s
//! decorator cases have no port: a Rust decorator is handed the definition
//! by shared reference and cannot change it; nor has
//! `testAFirstDefinitionReadBuildsTheCollectionKeyOnce`, which overrides
//! `getCacheBaseKeys()`.

use std::sync::{Arc, Mutex};

use async_trait::async_trait;
use utopia_cache::Cache;

use super::super::fixtures::{ObservedCache, any, created, definition, doc, unique};
use super::super::spy::{Spy, Stage};
use super::super::testing::block_on;
use super::super::{Callback, Database, METADATA};
use crate::adapter::Adapter;
use crate::attribute::Attribute;
use crate::capability::Capability;
use crate::document::Document;
use crate::error::{Kind, Result};
use crate::permission::PermissionType;
use crate::role::Role;
use crate::value::{Value, list, string};

const WEBHOOKS: &str = "webhooks";

/// `createDatabase()`: `webhooks` with `name` and `count` (default 10), and
/// the cached document `hook`.
async fn database(spy: Arc<Spy>) -> (Database, Arc<Spy>, Arc<ObservedCache>, Arc<Cache>) {
    let (counting, cache) = ObservedCache::new();
    let db = Database::new(spy.clone(), cache.clone());
    db.set_database("utopiaTests").set_namespace(&unique("resolved"));
    db.authorization().add_role("any");
    db.create(None).await.unwrap();
    db.create_collection(definition(
        WEBHOOKS,
        vec![Attribute::string("name", super::super::LENGTH_KEY), Attribute::integer("count").default(10)],
        any(&["read", "create", "update", "delete"]),
        true,
    ))
    .await
    .unwrap();
    db.create_document(WEBHOOKS, row("hook")).await.unwrap();
    db.get_document(WEBHOOKS, "hook", Vec::new(), false).await.unwrap();
    (db, spy, counting, cache)
}

fn row(id: &str) -> Document {
    let role = Role::any();
    let permissions = [
        crate::permission::Permission::read(&role),
        crate::permission::Permission::update(&role),
        crate::permission::Permission::delete(&role),
    ];
    doc(vec![
        ("$id", string(id)),
        ("$permissions", list(permissions.map(string))),
        ("name", string(id)),
        ("count", Value::Int(1)),
    ])
}

/// `UncachedTwin::of()`: the same database without a cache.
fn twin(db: &Database) -> Database {
    let twin = Database::new(db.adapter().clone(), super::super::fixtures::no_cache());
    twin.set_authorization(db.authorization());
    twin.set_database(&db.database()).set_namespace(&db.namespace());
    twin
}

/// The metadata reads the adapter served (`CountingMemory::$metadataReads`).
fn metadata_reads(spy: &Spy) -> Arc<Mutex<usize>> {
    let reads = Arc::new(Mutex::new(0usize));
    let counted = reads.clone();
    spy.intercept(move |seen| {
        if seen.stage == Stage::Before && matches!(seen.method, "getDocument" | "find") && seen.name == Some(METADATA) {
            *counted.lock().unwrap() += 1;
        }
        Ok(())
    });
    reads
}

#[derive(Clone, Copy, Debug)]
enum Write {
    Update,
    Increase,
    Decrease,
    Delete,
}

const WRITES: [Write; 4] = [Write::Update, Write::Increase, Write::Decrease, Write::Delete];

async fn write(db: &Database, write: Write) {
    match write {
        Write::Update => {
            db.update_document(WEBHOOKS, "hook", doc(vec![("name", string("renamed"))])).await.unwrap();
        }
        Write::Increase => {
            db.increase_document_attribute(WEBHOOKS, "hook", "count", Value::Int(1), None).await.unwrap();
        }
        Write::Decrease => {
            db.decrease_document_attribute(WEBHOOKS, "hook", "count", Value::Int(1), None).await.unwrap();
        }
        Write::Delete => {
            db.delete_document(WEBHOOKS, "hook").await.unwrap();
        }
    }
}

#[test]
fn a_write_of_a_cached_document_takes_three_round_trips_and_loads_its_definition_once() {
    block_on(async {
        for w in WRITES {
            let (db, spy, counting, _) = database(Spy::memory()).await;
            let reads = metadata_reads(&spy);
            counting.reset();
            write(&db, w).await;
            assert_eq!(
                counting.operations(),
                3,
                "{w:?}: one definition load, the purge inside the transaction and the purge after it commits"
            );
            assert_eq!(*reads.lock().unwrap(), 0, "{w:?}");

            let (db, _, counting, _) = database(Spy::memory()).await;
            let key = db.cache_keys(METADATA, Some(WEBHOOKS), &[]).await.unwrap().1.to_lowercase();
            counting.reset();
            write(&db, w).await;
            assert_eq!(counting.loads(&key), 1, "{w:?}");
        }
    });
}

#[test]
fn writes_return_the_document_a_read_returns_after_them() {
    block_on(async {
        let (db, _, _, _) = database(Spy::memory()).await;
        let updated = db.update_document(WEBHOOKS, "hook", doc(vec![("name", string("renamed"))])).await.unwrap();
        assert_eq!(updated.get("name"), Some(&string("renamed")));
        let fresh = twin(&db).get_document(WEBHOOKS, "hook", Vec::new(), false).await.unwrap();
        assert_eq!(fresh.array_copy(), updated.array_copy());
        let cached = db.get_document(WEBHOOKS, "hook", Vec::new(), false).await.unwrap();
        assert_eq!(updated.array_copy(), cached.array_copy());

        let (db, _, _, _) = database(Spy::memory()).await;
        let increased = db.increase_document_attribute(WEBHOOKS, "hook", "count", Value::Int(5), None).await.unwrap();
        assert_eq!(increased.get("count"), Some(&Value::Int(6)));
        assert_eq!(increased.get("name"), Some(&string("hook")));
        assert_eq!(twin(&db).get_document(WEBHOOKS, "hook", Vec::new(), false).await.unwrap().id(), increased.id());
        assert_eq!(
            db.get_document(WEBHOOKS, "hook", Vec::new(), false).await.unwrap().get("count"),
            Some(&Value::Int(6))
        );

        let decreased = db.decrease_document_attribute(WEBHOOKS, "hook", "count", Value::Int(2), None).await.unwrap();
        assert_eq!(decreased.get("count"), Some(&Value::Int(4)));
        assert_eq!(decreased.get("name"), Some(&string("hook")));
        let fresh = twin(&db).get_document(WEBHOOKS, "hook", Vec::new(), false).await.unwrap();
        assert_eq!(fresh.get("count"), Some(&Value::Int(4)));
        assert_eq!(
            db.get_document(WEBHOOKS, "hook", Vec::new(), false).await.unwrap().get("count"),
            Some(&Value::Int(4))
        );
    });
}

#[test]
fn a_delete_removes_the_document_for_every_reader_and_writes_of_a_missing_document_fail() {
    block_on(async {
        let (db, _, _, _) = database(Spy::memory()).await;
        assert!(db.delete_document(WEBHOOKS, "hook").await.unwrap());
        assert!(db.get_document(WEBHOOKS, "hook", Vec::new(), false).await.unwrap().is_empty());
        assert!(twin(&db).get_document(WEBHOOKS, "hook", Vec::new(), false).await.unwrap().is_empty());

        let (db, _, _, _) = database(Spy::memory()).await;
        let updated = db.update_document(WEBHOOKS, "missing", doc(vec![("name", string("renamed"))])).await.unwrap();
        assert!(updated.is_empty());
        assert!(!db.delete_document(WEBHOOKS, "missing").await.unwrap());
        let err = db.increase_document_attribute(WEBHOOKS, "missing", "count", Value::Int(1), None).await.unwrap_err();
        assert_eq!(err.kind(), Kind::NotFound);
    });
}

/// A database sharing the spy and the cache (PHP's `$concurrent`).
fn concurrent(db: &Database, spy: &Arc<Spy>, cache: &Arc<Cache>) -> Database {
    let other = Database::new(spy.clone(), cache.clone());
    other.set_authorization(db.authorization());
    other.set_database(&db.database()).set_namespace(&db.namespace());
    other
}

fn keys(collection: &crate::collection::Collection) -> Vec<String> {
    collection.attributes().unwrap().iter().map(|a| a.key.clone()).collect()
}

#[test]
fn the_locked_read_uses_the_definition_the_write_resolved() {
    block_on(async {
        for w in WRITES {
            let (db, spy, _, cache) = database(Spy::memory()).await;
            let other = concurrent(&db, &spy, &cache);
            spy.before_next(
                "startTransaction",
                Box::pin(async move {
                    other
                        .create_attribute(WEBHOOKS, Attribute::string("label", super::super::LENGTH_KEY))
                        .await
                        .unwrap();
                }),
            );
            let locked = Arc::new(Mutex::new(Vec::new()));
            let seen_locked = locked.clone();
            spy.intercept(move |seen| {
                if seen.stage == Stage::Before
                    && seen.method == "getDocument"
                    && seen.for_update
                    && seen.name == Some(WEBHOOKS)
                {
                    seen_locked.lock().unwrap().push(keys(seen.collection.unwrap()));
                }
                Ok(())
            });

            write(&db, w).await;

            assert!(!spy.pending(), "{w:?}: the schema change ran between the definition lookup and the locked read");
            assert_eq!(*locked.lock().unwrap(), vec![vec!["name".to_owned(), "count".to_owned()]], "{w:?}");
            assert_eq!(keys(&db.get_collection(WEBHOOKS).await.unwrap()), ["name", "count", "label"], "{w:?}");
        }
    });
}

async fn siblings(db: &Database) {
    for id in ["b", "c", "d", "e", "f"] {
        db.create_document(WEBHOOKS, row(id)).await.unwrap();
    }
    db.get_collection(WEBHOOKS).await.unwrap();
}

#[derive(Clone, Copy, Debug)]
enum Bulk {
    Update,
    Delete,
}

async fn bulk(db: &Database, bulk: Bulk, on_next: Option<&mut dyn Callback<Document>>) -> i64 {
    match bulk {
        Bulk::Update => {
            let mut on_next = on_next;
            let mut adapt = on_next.as_mut().map(|f| Adapt(&mut **f));
            db.update_documents(
                WEBHOOKS,
                doc(vec![("name", string("renamed"))]),
                Vec::new(),
                2,
                adapt.as_mut().map(|a| a as &mut dyn Callback<(Document, Option<Document>)>),
            )
            .await
            .unwrap()
        }
        Bulk::Delete => db.delete_documents(WEBHOOKS, Vec::new(), 2, on_next).await.unwrap(),
    }
}

/// An `on_next` of `deleteDocuments()` as one of `updateDocuments()`.
struct Adapt<'a>(&'a mut dyn Callback<Document>);

#[async_trait]
impl Callback<(Document, Option<Document>)> for Adapt<'_> {
    async fn call(&mut self, (document, _): (Document, Option<Document>)) -> Result<()> {
        self.0.call(document).await
    }
}

#[test]
fn a_bulk_write_reads_its_definition_at_most_once() {
    block_on(async {
        for b in [Bulk::Update, Bulk::Delete] {
            let (db, spy, _, _) = database(Spy::memory()).await;
            siblings(&db).await;
            let reads = metadata_reads(&spy);
            assert_eq!(bulk(&db, b, None).await, 6, "{b:?}");
            assert_eq!(*reads.lock().unwrap(), 0, "{b:?}: three pages of a cached definition read no _metadata row");

            let (db, spy, _, _) = database(Spy::memory()).await;
            siblings(&db).await;
            db.purge_cached_document(METADATA, WEBHOOKS).await.unwrap();
            let reads = metadata_reads(&spy);
            assert_eq!(bulk(&db, b, None).await, 6, "{b:?}");
            assert_eq!(*reads.lock().unwrap(), 1, "{b:?}");
        }
    });
}

/// Changes the schema through another database on the first document.
struct ChangeOnce(Option<Database>);

#[async_trait]
impl Callback<Document> for ChangeOnce {
    async fn call(&mut self, _document: Document) -> Result<()> {
        if let Some(other) = self.0.take() {
            other.create_attribute(WEBHOOKS, Attribute::string("label", super::super::LENGTH_KEY)).await?;
        }
        Ok(())
    }
}

#[test]
fn every_page_of_a_bulk_write_uses_the_definition_it_resolved() {
    block_on(async {
        for b in [Bulk::Update, Bulk::Delete] {
            let (db, spy, _, cache) = database(Spy::memory()).await;
            siblings(&db).await;
            let pages = Arc::new(Mutex::new(Vec::new()));
            let seen_pages = pages.clone();
            spy.intercept(move |seen| {
                if seen.stage == Stage::Before
                    && seen.method == "find"
                    && seen.name == Some(WEBHOOKS)
                    && seen.for_permission != Some(PermissionType::Read)
                {
                    seen_pages.lock().unwrap().push(keys(seen.collection.unwrap()));
                }
                Ok(())
            });
            let mut change = ChangeOnce(Some(concurrent(&db, &spy, &cache)));
            assert_eq!(bulk(&db, b, Some(&mut change)).await, 6, "{b:?}");
            assert!(change.0.is_none(), "{b:?}");
            assert_eq!(*pages.lock().unwrap(), vec![vec!["name".to_owned(), "count".to_owned()]; 4], "{b:?}");
            assert_eq!(keys(&db.get_collection(WEBHOOKS).await.unwrap()), ["name", "count", "label"], "{b:?}");
        }
    });
}

// ---- Documents/GetDocumentFallbackTest (cache cases) -----------------------

async fn sessions(adapter: Arc<dyn Adapter>, cache: Arc<Cache>) -> Database {
    let db = created(adapter, cache, "fallback", "fallback", false).await;
    db.create_collection(definition(
        "sessions",
        vec![Attribute::string("owner", 32), Attribute::datetime("startedAt")],
        any(&["create", "read"]),
        false,
    ))
    .await
    .unwrap();
    db
}

#[test]
fn a_cache_that_cannot_be_read_or_written_falls_back_to_the_database() {
    block_on(async {
        let (counting, cache) = ObservedCache::new();
        let db = sessions(Arc::new(crate::adapter::memory::Memory::new()), cache).await;
        db.create_document("sessions", doc(vec![("$id", string("session")), ("owner", string("ada"))])).await.unwrap();
        let refused = Arc::new(Mutex::new(Vec::new()));
        let seen = refused.clone();
        counting.intercept(move |operation, key| {
            if !key.contains('#')
                && key.contains(":sessions:")
                && matches!(operation, "load" | "getGeneration" | "saveWithLease")
            {
                seen.lock().unwrap().push(operation.to_owned());
                return Err(format!("the cache refused {operation}"));
            }
            Ok(())
        });

        let document = db.get_document("sessions", "session", Vec::new(), false).await.unwrap();
        let missing = db.get_document("sessions", "absent", Vec::new(), false).await.unwrap();
        assert_eq!(document.get("owner"), Some(&string("ada")));
        assert!(missing.is_empty());
        // PHP checks the warnings it prints; each one stands for an
        // operation the cache refused.
        let refused = refused.lock().unwrap().clone();
        for operation in ["load", "getGeneration", "saveWithLease"] {
            assert!(refused.iter().any(|o| o == operation), "{operation} was attempted: {refused:?}");
        }
    });
}

#[test]
fn a_cached_document_past_its_time_to_live_reads_as_empty() {
    block_on(async {
        let spy = Spy::memory();
        spy.support(Capability::IndexTtl);
        let db = sessions(spy, super::super::fixtures::memory_cache()).await;
        db.create_index("sessions", crate::index::Index::ttl("expiry", "startedAt", 1).unwrap()).await.unwrap();
        db.create_document(
            "sessions",
            doc(vec![
                ("$id", string("session")),
                ("owner", string("ada")),
                ("startedAt", string(crate::datetime::now())),
            ]),
        )
        .await
        .unwrap();
        assert_eq!(
            db.get_document("sessions", "session", Vec::new(), false).await.unwrap().get("owner"),
            Some(&string("ada"))
        );
        utopia_async::sleep(std::time::Duration::from_secs(2)).await;
        assert!(
            db.get_document("sessions", "session", Vec::new(), false).await.unwrap().is_empty(),
            "the cached copy is past its time to live"
        );
    });
}
