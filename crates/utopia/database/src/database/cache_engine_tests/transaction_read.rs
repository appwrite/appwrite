//! PHP: `Documents\TransactionCacheReadTest` (its Memory cases).

use std::sync::atomic::{AtomicUsize, Ordering};
use std::sync::Arc;

use crate::cache::testing::{Leasable, over};
use crate::collection::Update;
use crate::database::fixtures::{any_permissions, collection, doc, run, string, strings, unique};
use crate::database::observed::{Observed, Op};
use crate::database::{Database, Hook, METADATA};
use crate::document::Document;
use crate::error::Result;
use crate::hook::relationships::Relationships;
use crate::permission::Permission;
use crate::relationship::{Relationship, RelationshipType};
use crate::role::Role;
use crate::value::Value;

/// `createDatabase()`.
async fn create(adapter: Arc<Observed>, cache: &Arc<Leasable>, namespace: Option<&str>) -> Database {
    let database = Database::new(adapter, over(cache));
    database.set_database("utopiaTests").set_namespace(&namespace.map_or_else(|| unique("transaction_cache_"), str::to_owned));
    database.authorization().add_role(&Role::any().to_string());
    database.create(None).await.unwrap();
    database
        .create_collection(collection(
            "webhooks",
            vec![string("name"), crate::Attribute::integer("count").default(10)],
            vec![],
            Some(any_permissions()),
            true,
        ))
        .await
        .unwrap();
    database
}

/// `hook()`.
fn hook(id: &str) -> Document {
    let any = Role::any();
    doc(vec![
        ("$id", Value::from(id)),
        ("$permissions", strings([Permission::read(&any), Permission::update(&any), Permission::delete(&any)])),
        ("name", Value::from("hook")),
    ])
}

fn document_reads(adapter: &Observed) -> usize {
    adapter.count(|op| matches!(op, Op::GetDocument { collection, .. } if collection != METADATA))
}

fn metadata_reads(adapter: &Observed) -> usize {
    adapter.count(Op::is_metadata_read)
}

async fn get(database: &Database, id: &str) -> Document {
    database.get_document("webhooks", id, vec![], false).await.unwrap()
}

/// `createSharedMemoryDatabases()`: two databases on their own Memory
/// adapters, sharing a cache and a namespace, each holding `hook`.
async fn shared() -> (Database, Database) {
    let cache = Leasable::new();
    let namespace = unique("transaction_cache_");
    let writer = create(Observed::memory(), &cache, Some(&namespace)).await;
    let reader = create(Observed::memory(), &cache, Some(&namespace)).await;
    for database in [&writer, &reader] {
        database.create_document("webhooks", hook("hook")).await.unwrap();
    }
    (writer, reader)
}

#[test]
fn writes_read_their_collection_definition_from_the_cache() {
    for write in 0..4 {
        run(move || async move {
            let adapter = Observed::memory();
            let database = create(adapter.clone(), &Leasable::new(), None).await;
            database.create_document("webhooks", hook("hook")).await.unwrap();
            get(&database, "hook").await;
            adapter.reset();
            let result: Result<()> = match write {
                0 => database.update_document("webhooks", "hook", doc(vec![("name", Value::from("renamed"))])).await.map(|_| ()),
                1 => database.increase_document_attribute("webhooks", "hook", "count", Value::Int(1), None).await.map(|_| ()),
                2 => database.decrease_document_attribute("webhooks", "hook", "count", Value::Int(1), None).await.map(|_| ()),
                _ => database.delete_document("webhooks", "hook").await.map(|_| ()),
            };
            result.unwrap();
            assert_eq!(metadata_reads(&adapter), 0, "write {write} reads its definition from the cache");
        });
    }
}

#[test]
fn reads_inside_a_transaction_are_served_from_the_cache() {
    run(|| async {
        let adapter = Observed::memory();
        let database = create(adapter.clone(), &Leasable::new(), None).await;
        database.create_document("webhooks", hook("written")).await.unwrap();
        database.create_document("webhooks", hook("sibling")).await.unwrap();
        get(&database, "written").await;
        let sibling = get(&database, "sibling").await;
        adapter.reset();
        let read = database
            .with_transaction(|| async {
                database.update_document("webhooks", "written", doc(vec![("name", Value::from("renamed"))])).await?;
                database.get_document("webhooks", "sibling", vec![], false).await
            })
            .await
            .unwrap();
        assert_eq!(read.array_copy(), sibling.array_copy());
        assert_eq!(metadata_reads(&adapter), 0);
        assert_eq!(document_reads(&adapter), 1, "only the locking read of the written document reaches the adapter");
    });
}

#[test]
fn a_transaction_reads_its_own_writes_under_another_casing() {
    run(|| async {
        let (writer, reader) = shared().await;
        assert_eq!(get(&writer, "HOOK").await.get("name"), Some(&Value::from("hook")));
        let read = writer
            .with_transaction(|| async {
                writer.update_document("webhooks", "hook", doc(vec![("name", Value::from("renamed"))])).await?;
                assert_eq!(get(&reader, "HOOK").await.get("name"), Some(&Value::from("hook")));
                writer.get_document("webhooks", "HOOK", vec![], false).await
            })
            .await
            .unwrap();
        assert_eq!(read.get("name"), Some(&Value::from("renamed")));
    });
}

#[test]
fn a_transaction_started_on_the_adapter_reads_its_own_writes() {
    run(|| async {
        let (writer, reader) = shared().await;
        let adapter = writer.adapter().clone();
        let read = crate::adapter::with_transaction(adapter.as_ref(), || async {
            writer.update_document("webhooks", "hook", doc(vec![("name", Value::from("renamed"))])).await?;
            assert_eq!(get(&reader, "hook").await.get("name"), Some(&Value::from("hook")));
            writer.with_transaction(|| writer.get_document("webhooks", "hook", vec![], false)).await
        })
        .await
        .unwrap();
        assert_eq!(read.get("name"), Some(&Value::from("renamed")));
    });
}

#[test]
fn a_transaction_reads_the_documents_its_batch_write_changed() {
    run(|| async {
        let (writer, reader) = shared().await;
        let read = writer
            .with_transaction(|| async {
                writer.update_documents("webhooks", doc(vec![("name", Value::from("renamed"))]), vec![], 100, None).await?;
                assert_eq!(get(&reader, "hook").await.get("name"), Some(&Value::from("hook")));
                writer.get_document("webhooks", "hook", vec![], false).await
            })
            .await
            .unwrap();
        assert_eq!(read.get("name"), Some(&Value::from("renamed")));
    });
}

#[test]
fn a_transaction_reads_the_collection_definition_it_changed() {
    run(|| async {
        let (writer, reader) = shared().await;
        let read = writer
            .with_transaction(|| async {
                writer
                    .update_collection(
                        "webhooks",
                        Update {
                            permissions: Some(vec![Permission::read(&Role::any()), Permission::update(&Role::any())]),
                            document_security: Some(false),
                        },
                    )
                    .await?;
                assert!(reader.get_collection("webhooks").await?.document_security());
                writer.get_collection("webhooks").await
            })
            .await
            .unwrap();
        assert!(!read.document_security());
    });
}

#[test]
fn reads_inside_a_transaction_fill_nothing() {
    run(|| async {
        let cache = Leasable::new();
        let fills = Arc::new(AtomicUsize::new(0));
        let counter = fills.clone();
        cache.on_save(move |_, _, _| {
            counter.fetch_add(1, Ordering::SeqCst);
            None
        });
        let database = create(Observed::memory(), &cache, None).await;
        database.create_document("webhooks", hook("hook")).await.unwrap();
        database.create_document("webhooks", hook("cold")).await.unwrap();
        get(&database, "hook").await;
        let before = fills.load(Ordering::SeqCst);
        database
            .with_transaction(|| async {
                assert_eq!(get(&database, "hook").await.get("name"), Some(&Value::from("hook")));
                assert_eq!(get(&database, "cold").await.get("name"), Some(&Value::from("hook")));
                assert!(get(&database, "absent").await.is_empty());
                assert!(database.find_collection("absent").await?.is_none());
                Ok(())
            })
            .await
            .unwrap();
        assert_eq!(fills.load(Ordering::SeqCst), before, "a read inside a transaction saves nothing");
    });
}

fn library(id: &str, prefix: &str) -> Document {
    let books: Vec<Value> = (0..3)
        .map(|index| {
            let book = format!("{prefix}{index}");
            Value::Array(
                [
                    (crate::value::ArrayKey::from("$id"), Value::from(book.as_str())),
                    (crate::value::ArrayKey::from("$permissions"), strings([Permission::read(&Role::any())])),
                    (crate::value::ArrayKey::from("title"), Value::from(book.as_str())),
                ]
                .into_iter()
                .collect(),
            )
        })
        .collect();
    doc(vec![
        ("$id", Value::from(id)),
        ("$permissions", strings([Permission::read(&Role::any())])),
        ("name", Value::from(id)),
        ("books", Value::Array(books.into_iter().collect())),
    ])
}

#[test]
fn a_nested_relationship_create_reads_no_collection_definition() {
    run(|| async {
        let adapter = Observed::memory();
        let database = create(adapter.clone(), &Leasable::new(), None).await;
        database.add_hook(Hook::Relationships(Arc::new(Relationships::default()))).unwrap();
        let any = Role::any();
        let permissions = || Some(vec![Permission::read(&any), Permission::create(&any), Permission::update(&any)]);
        database.create_collection(collection("libraries", vec![string("name")], vec![], permissions(), true)).await.unwrap();
        database.create_collection(collection("books", vec![string("title")], vec![], permissions(), true)).await.unwrap();
        database
            .create_relationship(
                "libraries",
                Relationship::new("books", RelationshipType::OneToMany).two_way(true).key("books").two_way_key("library"),
            )
            .await
            .unwrap();
        database.create_document("libraries", library("warm", "warm")).await.unwrap();
        adapter.reset();
        database.create_document("libraries", library("library", "book")).await.unwrap();
        assert_eq!(metadata_reads(&adapter), 0, "a create with 3 nested documents reads no definition");
        let created = database.get_document("libraries", "library", vec![], false).await.unwrap();
        assert!(matches!(created.get("books"), Some(Value::Array(books)) if books.len() == 3));
    });
}

#[test]
fn a_missing_collection_costs_one_metadata_read() {
    run(|| async {
        let adapter = Observed::memory();
        let database = create(adapter.clone(), &Leasable::new(), None).await;
        adapter.reset();
        assert!(database.find_collection("missing").await.unwrap().is_none());
        assert_eq!(metadata_reads(&adapter), 1);
        assert!(database.find_collection("missing").await.unwrap().is_none());
        assert_eq!(metadata_reads(&adapter), 1, "a missing collection is served from the cache once read");
    });
}

#[test]
fn create_collection_checks_its_id_with_one_metadata_read() {
    run(|| async {
        let adapter = Observed::memory();
        let database = create(adapter.clone(), &Leasable::new(), None).await;
        adapter.reset();
        database
            .create_collection(collection("logs", vec![string("message")], vec![], Some(vec![Permission::read(&Role::any())]), true))
            .await
            .unwrap();
        assert_eq!(metadata_reads(&adapter), 1);
    });
}

#[test]
fn create_collection_after_a_probe_reads_no_definition() {
    run(|| async {
        let adapter = Observed::memory();
        let database = create(adapter.clone(), &Leasable::new(), None).await;
        assert!(database.find_collection("logs").await.unwrap().is_none());
        database
            .create_collection(collection("audits", vec![], vec![], Some(vec![Permission::read(&Role::any())]), true))
            .await
            .unwrap();
        adapter.reset();
        database
            .create_collection(collection("logs", vec![], vec![], Some(vec![Permission::read(&Role::any())]), true))
            .await
            .unwrap();
        assert_eq!(metadata_reads(&adapter), 0, "a cached miss for the new id survives other definitions being written");
    });
}
