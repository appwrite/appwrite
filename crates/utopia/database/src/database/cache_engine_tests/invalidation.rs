//! PHP: `Documents\DocumentCacheInvalidationTest` (its Memory cases).

use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::Arc;

use crate::cache::testing::{Leasable, over};
use crate::collection::Update;
use crate::database::fixtures::{any_permissions, collection, doc, run, string, strings, unique};
use crate::database::observed::{Observed, Op};
use crate::database::{Database, METADATA};
use crate::document::Document;
use crate::error::Result;
use crate::permission::Permission;
use crate::query::Query;
use crate::role::Role;
use crate::value::Value;

const PURGE_FAILURE: &str = "The cache refused the purge";

/// `createDatabase()`.
async fn create(adapter: Arc<Observed>, cache: &Arc<Leasable>) -> Database {
    let database = Database::new(adapter, over(cache));
    database.set_database("utopiaTests").set_namespace(&unique("document_cache_"));
    database.authorization().add_role(&Role::any().to_string());
    database.create(None).await.unwrap();
    database
        .create_collection(collection(
            "webhooks",
            vec![string("name"), string("description"), crate::Attribute::integer("count").default(10)],
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
        ("description", Value::from("description")),
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

#[test]
fn repeated_writes_and_reads_keep_the_key_count_bounded() {
    run(|| async {
        let cache = Leasable::new();
        let database = create(Observed::memory(), &cache).await;
        for index in 0..5 {
            database.create_document("webhooks", hook(&format!("hook{index}"))).await.unwrap();
        }
        let mut keys = 0;
        for round in 1..=20 {
            let name = format!("round {round}");
            for index in 0..5 {
                let id = format!("hook{index}");
                database.update_document("webhooks", &id, doc(vec![("name", Value::from(name.as_str()))])).await.unwrap();
                assert_eq!(get(&database, &id).await.get("name"), Some(&Value::from(name.as_str())));
            }
            if round == 1 {
                keys = cache.keys().len();
            }
        }
        assert_eq!(keys, cache.keys().len(), "writes and reads of the same documents add no keys");
    });
}

#[test]
fn a_cache_without_fields_serves_each_selection_its_own_copy() {
    run(|| async {
        let database = create(Observed::memory(), &Leasable::plain()).await;
        database.create_document("webhooks", hook("hook")).await.unwrap();
        for _ in 0..2 {
            assert_eq!(get(&database, "hook").await.get("description"), Some(&Value::from("description")));
            let projected = database.get_document("webhooks", "hook", vec![Query::select(["name"])], false).await.unwrap();
            assert_eq!(projected.get("name"), Some(&Value::from("hook")));
            assert!(!projected.has("description"), "a projected read gets only what it selected");
        }
    });
}

#[test]
fn a_write_keeps_its_siblings_cached() {
    run(|| async {
        let adapter = Observed::memory();
        let database = create(adapter.clone(), &Leasable::new()).await;
        database.create_document("webhooks", hook("written")).await.unwrap();
        database.create_document("webhooks", hook("sibling")).await.unwrap();
        let sibling = get(&database, "sibling").await;
        database.update_document("webhooks", "written", doc(vec![("name", Value::from("renamed"))])).await.unwrap();
        adapter.reset();
        assert_eq!(get(&database, "sibling").await.array_copy(), sibling.array_copy());
        assert_eq!(document_reads(&adapter), 0, "a write leaves its siblings cached");
        assert_eq!(get(&database, "written").await.get("name"), Some(&Value::from("renamed")));
    });
}

#[test]
fn single_document_writes_do_not_block_the_collection() {
    for write in 0..5 {
        run(move || async move {
            let adapter = Observed::memory();
            let database = create(adapter.clone(), &Leasable::new()).await;
            database.create_document("webhooks", hook("hook")).await.unwrap();
            database.create_document("webhooks", hook("sibling")).await.unwrap();
            get(&database, "hook").await;
            get(&database, "sibling").await;
            let result: Result<()> = match write {
                0 => database
                    .create_document(
                        "webhooks",
                        doc(vec![
                            ("$id", Value::from("created")),
                            ("$permissions", strings([Permission::read(&Role::any())])),
                            ("name", Value::from("created")),
                        ]),
                    )
                    .await
                    .map(|_| ()),
                1 => database.update_document("webhooks", "hook", doc(vec![("name", Value::from("renamed"))])).await.map(|_| ()),
                2 => database.increase_document_attribute("webhooks", "hook", "count", Value::Int(1), None).await.map(|_| ()),
                3 => database.decrease_document_attribute("webhooks", "hook", "count", Value::Int(1), None).await.map(|_| ()),
                _ => database.delete_document("webhooks", "hook").await.map(|_| ()),
            };
            result.unwrap();
            adapter.reset();
            assert_eq!(get(&database, "sibling").await.get("name"), Some(&Value::from("hook")));
            assert_eq!(document_reads(&adapter), 0, "write {write} leaves the rest of its collection cached");
        });
    }
}

#[test]
fn a_collection_definition_write_keeps_the_other_definitions_cached() {
    run(|| async {
        let adapter = Observed::memory();
        let database = create(adapter.clone(), &Leasable::new()).await;
        database
            .create_collection(collection("logs", vec![], vec![], Some(vec![Permission::read(&Role::any())]), true))
            .await
            .unwrap();
        database.get_collection("webhooks").await.unwrap();
        database.get_collection("logs").await.unwrap();
        database
            .update_collection(
                "logs",
                Update {
                    permissions: Some(vec![Permission::read(&Role::any()), Permission::create(&Role::any())]),
                    document_security: Some(true),
                },
            )
            .await
            .unwrap();
        adapter.reset();
        assert!(database.find_collection("webhooks").await.unwrap().is_some());
        assert_eq!(metadata_reads(&adapter), 0, "a write to one definition leaves the others cached");
        assert!(database.get_collection("logs").await.unwrap().document_security());
    });
}

/// A cache whose purges fail while `refusing` is set, once.
fn purge_refusing() -> (Arc<Leasable>, Arc<AtomicBool>) {
    let cache = Leasable::new();
    let refusing = Arc::new(AtomicBool::new(false));
    let flag = refusing.clone();
    cache.on_purge(move |_, _| {
        flag.swap(false, Ordering::SeqCst).then(|| Err(utopia_cache::Error::Runtime(PURGE_FAILURE.into())))
    });
    (cache, refusing)
}

#[test]
fn a_failed_purge_inside_the_transaction_rolls_the_write_back() {
    run(|| async {
        let (cache, refusing) = purge_refusing();
        let database = create(Observed::memory(), &cache).await;
        database.create_document("webhooks", hook("hook")).await.unwrap();
        assert_eq!(get(&database, "hook").await.get("name"), Some(&Value::from("hook")));
        refusing.store(true, Ordering::SeqCst);
        let error = database
            .update_document("webhooks", "hook", doc(vec![("name", Value::from("renamed"))]))
            .await
            .unwrap_err();
        assert_eq!(error.message(), PURGE_FAILURE);
        assert_eq!(get(&database, "hook").await.get("name"), Some(&Value::from("hook")), "the write rolls back");
    });
}

#[test]
fn a_rollback_keeps_serving_the_committed_document() {
    run(|| async {
        let database = create(Observed::memory(), &Leasable::new()).await;
        database.create_document("webhooks", hook("hook")).await.unwrap();
        assert_eq!(get(&database, "hook").await.get("name"), Some(&Value::from("hook")));
        let result: Result<()> = database
            .with_transaction(|| async {
                database.update_document("webhooks", "hook", doc(vec![("name", Value::from("renamed"))])).await?;
                Err(crate::Error::new(crate::Kind::Runtime, "rollback"))
            })
            .await;
        assert!(result.is_err());
        for _ in 0..2 {
            assert_eq!(get(&database, "hook").await.get("name"), Some(&Value::from("hook")));
        }
    });
}

#[test]
fn create_and_delete_churn_leaves_only_a_generation_per_document() {
    run(|| async {
        let cache = Leasable::new();
        let database = create(Observed::memory(), &cache).await;
        database.create_document("webhooks", hook("warm")).await.unwrap();
        get(&database, "warm").await;
        let keys = cache.keys().len();
        let values = cache.count_values();
        let churned = 10;
        for index in 0..churned {
            let id = format!("churn{index}");
            database.create_document("webhooks", hook(&id)).await.unwrap();
            assert!(!get(&database, &id).await.is_empty());
            assert!(database.delete_document("webhooks", &id).await.unwrap());
            assert!(get(&database, &id).await.is_empty());
            database.purge_cached_document("webhooks", &id).await.unwrap();
        }
        assert!(cache.keys().len() <= keys + churned, "{} keys", cache.keys().len());
        assert!(cache.count_values() <= values, "{} values", cache.count_values());
    });
}
