//! PHP: `TogglesTest` (the cases that need an engine), the write cases of
//! `ScopedToggleCoroutineTest` (coroutines are futures joined in one task
//! here), and `Documents/SkipDuplicatesPermissionTest`'s Memory case.

use std::sync::Arc;

use utopia_async::sync::oneshot;

use super::s;
use crate::adapter::memory::Memory;
use crate::attribute::Attribute;
use crate::collection::Collection;
use crate::database::Database;
use crate::database::testing::block_on;
use crate::database::testing_memory::{collection, doc, memory};
use crate::document::Document;
use crate::error::{Kind, Result};
use crate::filter::Callback;
use crate::index::Index;
use crate::permission::{Permission, PermissionType};
use crate::query::Query;
use crate::role::Role;
use crate::validator::authorization::Authorization;
use crate::value::{Array, Value, list};

const NOTES: &str = "notes";
const SHOUT: &str = "shout";
const PAST: &str = "2000-01-01T00:00:00.000+00:00";

fn shout() -> Arc<dyn crate::filter::Codec> {
    let map = |f: fn(&str) -> String| {
        move |value: Value| {
            Ok(match &value {
                Value::Str(text) => s(&f(&String::from_utf8_lossy(text.as_bytes()))),
                _ => value,
            })
        }
    };
    Arc::new(Callback::new(SHOUT, map(|t| t.to_lowercase()), map(|t| t.to_uppercase())))
}

async fn seeded() -> Database {
    let cache = Arc::new(utopia_cache::Cache::from_dyn(Arc::new(utopia_cache::None)));
    let db = Database::with_filters(Arc::new(Memory::new()), cache, vec![shout()]).unwrap();
    db.set_authorization(Arc::new(Authorization::new(true)));
    db.set_database("toggles").set_namespace(&format!("toggles_{}", crate::id::unique().unwrap()));
    db.create(None).await.unwrap();
    let any = Role::any();
    let attributes = vec![Attribute::string("title", 64).filters([SHOUT])];
    collection(&db, NOTES, attributes, vec![Permission::create(&any), Permission::read(&any)], true).await;
    db.create_document(NOTES, doc(vec![("$id", s("note")), ("title", s("Quiet"))])).await.unwrap();
    db
}

async fn title(db: &Database) -> Option<Value> {
    db.get_document(NOTES, "note", Vec::new(), false).await.unwrap().get("title").cloned()
}

#[test]
fn filtering_off_reads_the_stored_value() {
    block_on(async {
        let db = seeded().await;
        let none: Option<&[&str]> = None;
        assert_eq!(title(&db).await, Some(s("QUIET")));
        assert_eq!(Box::pin(db.with_filtering(false, none, title(&db))).await, Some(s("quiet")));
        db.set_filtering(false);
        assert_eq!(title(&db).await, Some(s("quiet")));
        assert_eq!(Box::pin(db.with_filtering(true, none, title(&db))).await, Some(s("QUIET")));
    });
}

#[test]
fn named_filters_are_turned_off_and_back_on() {
    block_on(async {
        let db = seeded().await;
        let named = Some(&[SHOUT][..]);
        assert_eq!(Box::pin(db.with_filtering(false, named, title(&db))).await, Some(s("quiet")));
        assert!(db.with_filtering(false, named, async { db.is_filtering() }).await);
        let back_on = db.with_filtering(true, named, title(&db));
        assert_eq!(Box::pin(db.skip_filters(named, back_on)).await, Some(s("QUIET")));
    });
}

fn dated(id: &str) -> Document {
    doc(vec![("$id", s(id)), ("$createdAt", s(PAST)), ("title", s(id))])
}

#[test]
fn preserved_dates_keep_the_given_creation_time() {
    block_on(async {
        let db = seeded().await;
        let preserved = Box::pin(db.with_preserve_dates(true, db.create_document(NOTES, dated("preserved")))).await;
        let stamped = Box::pin(db.with_preserve_dates(false, db.create_document(NOTES, dated("stamped")))).await;
        assert_eq!(preserved.unwrap().created_at(), Some(PAST));
        assert_ne!(stamped.unwrap().created_at(), Some(PAST));
    });
}

#[test]
fn ignore_duplicates_skips_an_existing_id_only_inside_its_future() {
    block_on(async {
        let db = seeded().await;
        let duplicate =
            || db.create_documents(NOTES, vec![doc(vec![("$id", s("note")), ("title", s("again"))])], 1000, None);
        assert_eq!(Box::pin(db.ignore_duplicates(duplicate())).await.unwrap(), 0);
        assert_eq!(title(&db).await, Some(s("QUIET")));
        assert!(duplicate().await.unwrap_err().is_a(Kind::Duplicate));
    });
}

// ---- ScopedToggleCoroutineTest --------------------------------------------

async fn shared_notes() -> Database {
    let db = memory("scoped").await;
    db.set_shared_tables(true).set_tenant(Value::Int(1));
    let any = Role::any();
    let permissions = vec![Permission::create(&any), Permission::read(&any), Permission::update(&any)];
    for tenant in [1, 2, 3] {
        let create = collection(&db, NOTES, vec![Attribute::string("title", 64)], permissions.clone(), true);
        Box::pin(db.with_tenant(Value::Int(tenant), create)).await;
    }
    db
}

async fn ids_under(db: &Database, tenant: i64) -> Vec<String> {
    let found = Box::pin(db.with_tenant(Value::Int(tenant), db.find(NOTES, Vec::new(), PermissionType::Read))).await;
    found.unwrap().iter().map(|d| d.id().to_owned()).collect()
}

#[test]
fn a_sibling_writes_under_its_own_tenant_while_another_is_inside_with_tenant() {
    block_on(async {
        let db = shared_notes().await;
        let (entered_tx, entered_rx) = oneshot::channel::<()>();
        let (written_tx, written_rx) = oneshot::channel::<()>();
        let note = |id: &str| doc(vec![("$id", s(id)), ("title", s(id))]);
        let scoped = db.with_tenant(Value::Int(2), async {
            let _ = entered_tx.send(());
            let _ = written_rx.await;
            db.create_document(NOTES, note("scoped")).await.map(drop)
        });
        let sibling = async {
            let _ = entered_rx.await;
            let sibling = db.create_document(NOTES, note("sibling")).await.map(drop);
            let own = db.with_tenant(Value::Int(3), db.create_document(NOTES, note("own"))).await.map(drop);
            let _ = written_tx.send(());
            (sibling, own)
        };
        let (scoped, (sibling, own)) = futures_util::join!(Box::pin(scoped), Box::pin(sibling));
        scoped.unwrap();
        sibling.unwrap();
        own.unwrap();
        assert_eq!(db.tenant(), Value::Int(1));
        assert_eq!(ids_under(&db, 1).await, ["sibling"]);
        assert_eq!(ids_under(&db, 2).await, ["scoped"]);
        assert_eq!(ids_under(&db, 3).await, ["own"]);
    });
}

#[test]
fn a_siblings_dates_are_not_preserved_while_another_is_inside_with_preserve_dates() {
    block_on(async {
        let db = memory("scoped").await;
        let any = Role::any();
        let permissions = vec![Permission::create(&any), Permission::read(&any)];
        collection(&db, NOTES, vec![Attribute::string("title", 64)], permissions, true).await;
        let (entered_tx, entered_rx) = oneshot::channel::<()>();
        let (written_tx, written_rx) = oneshot::channel::<()>();
        let inside = db.with_preserve_dates(true, async {
            let _ = entered_tx.send(());
            let _ = written_rx.await;
            db.create_document(NOTES, dated("preserved")).await.map(drop)
        });
        let sibling = async {
            let _ = entered_rx.await;
            let stamped = db.create_document(NOTES, dated("stamped")).await.map(drop);
            let _ = written_tx.send(());
            stamped
        };
        let (inside, sibling) = futures_util::join!(Box::pin(inside), Box::pin(sibling));
        inside.unwrap();
        sibling.unwrap();
        let db = &db;
        let created = |id: &'static str| async move {
            db.get_document(NOTES, id, Vec::new(), false).await.unwrap().created_at().map(str::to_owned)
        };
        assert_eq!(created("preserved").await.as_deref(), Some(PAST));
        assert_ne!(created("stamped").await.as_deref(), Some(PAST));
    });
}

#[test]
fn a_sibling_is_rejected_while_another_is_inside_ignore_duplicates() {
    block_on(async {
        let db = seeded().await;
        let (entered_tx, entered_rx) = oneshot::channel::<()>();
        let (released_tx, released_rx) = oneshot::channel::<()>();
        let duplicate =
            || db.create_documents(NOTES, vec![doc(vec![("$id", s("note")), ("title", s("again"))])], 1000, None);
        let inside = db.ignore_duplicates(async {
            let _ = entered_tx.send(());
            let _ = released_rx.await;
            duplicate().await
        });
        let sibling = async {
            let _ = entered_rx.await;
            let outcome = duplicate().await;
            let _ = released_tx.send(());
            outcome
        };
        let (inside, sibling) = futures_util::join!(Box::pin(inside), Box::pin(sibling));
        assert_eq!(inside.unwrap(), 0, "skipped inside");
        assert!(sibling.unwrap_err().is_a(Kind::Duplicate), "rejected in the sibling");
    });
}

// ---- SkipDuplicatesPermissionTest (Memory) --------------------------------

#[test]
fn the_memory_adapter_counts_only_inserted_documents() {
    block_on(async {
        let authorization = Arc::new(Authorization::new(true));
        let cache = Arc::new(utopia_cache::Cache::from_dyn(Arc::new(utopia_cache::None)));
        let db = Database::new(Arc::new(Memory::new()), cache);
        db.set_authorization(authorization.clone());
        db.set_database("skip_duplicates_permission").set_namespace("skip_duplicates_permission");
        db.create(None).await.unwrap();
        let definition = Collection::create(
            NOTES,
            NOTES,
            vec![Attribute::integer("rank"), Attribute::string("slug", 64)],
            vec![Index::unique("slug", ["slug"], Vec::new(), Vec::new()).unwrap()],
            Some(vec![Permission::create(&Role::any())]),
            true,
            Array::new(),
        )
        .unwrap();
        db.create_collection(definition).await.unwrap();
        let note = |id: &str, reader: &Role, rank: i64, slug: Option<&str>| {
            doc(vec![
                ("$id", s(id)),
                ("$permissions", list([s(&Permission::read(reader))])),
                ("rank", Value::Int(rank)),
                ("slug", s(slug.unwrap_or(id))),
            ])
        };
        let alice = Role::user("alice", "");
        db.create_document(NOTES, note("existing", &alice, 5, None)).await.unwrap();
        let mut emitted = Vec::new();
        let mut on_next = |(document, _): (Document, Option<Document>)| {
            emitted.push(document.id().to_owned());
            Ok(())
        };
        let any = Role::any();
        let batch = vec![
            note("existing", &any, 7, None),
            note("other", &any, 4, Some("existing")),
            note("fresh", &any, 3, None),
            note("fresh", &any, 2, None),
        ];
        let created: Result<i64> =
            Box::pin(db.ignore_duplicates(db.create_documents(NOTES, batch, 1000, Some(&mut on_next)))).await;
        let readable = db.find(NOTES, vec![Query::order_asc("$id", None)], PermissionType::Read).await.unwrap();
        let readable: Vec<&str> = readable.iter().map(Document::id).collect();
        assert_eq!(readable, ["fresh"]);
        assert_eq!(created.unwrap(), 1);
        assert_eq!(emitted, ["fresh"]);
    });
}
