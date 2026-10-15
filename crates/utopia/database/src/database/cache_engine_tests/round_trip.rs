//! PHP: `Documents\DocumentCacheRoundTripTest`.

use std::sync::Arc;

use crate::cache::testing::{Leasable, over};
use crate::collection::Update;
use crate::database::fixtures::{any_permissions, collection, doc, run, string, strings, uncached_twin, unique};
use crate::database::observed::{Observed, Op};
use crate::database::{Database, METADATA};
use crate::document::Document;
use crate::error::Result;
use crate::permission::{Permission, PermissionType};
use crate::query::Query;
use crate::role::Role;
use crate::value::Value;

fn attributes() -> Vec<crate::Attribute> {
    vec![string("name"), crate::Attribute::integer("count").default(10)]
}

/// `createDatabase()`.
async fn create() -> (Database, Arc<Observed>, Arc<Leasable>) {
    let adapter = Observed::memory();
    let cache = Leasable::new();
    let database = Database::new(adapter.clone(), over(&cache));
    database.set_database("utopiaTests").set_namespace(&unique("round_trips_"));
    database.authorization().add_role(&Role::any().to_string());
    database.create(None).await.unwrap();
    database
        .create_collection(collection("webhooks", attributes(), vec![], Some(any_permissions()), true))
        .await
        .unwrap();
    (database, adapter, cache)
}

/// `hook()`.
fn hook(id: &str) -> Document {
    let any = Role::any();
    doc(vec![
        ("$id", Value::from(id)),
        ("$permissions", strings([Permission::read(&any), Permission::update(&any), Permission::delete(&any)])),
        ("name", Value::from("hook")),
        ("count", Value::Int(1)),
    ])
}

fn document_reads(adapter: &Observed) -> usize {
    adapter.count(|op| matches!(op, Op::GetDocument { collection, .. } if collection != METADATA))
}

fn metadata_reads(adapter: &Observed) -> usize {
    adapter.count(Op::is_metadata_read)
}

async fn name(database: &Database, id: &str) -> Value {
    database.get_document("webhooks", id, vec![], false).await.unwrap().get("name").cloned().unwrap_or(Value::Null)
}

fn reset(adapter: &Observed, cache: &Leasable) {
    adapter.reset();
    cache.reset_operations();
}

#[test]
fn a_get_collection_hit_stays_within_seven_three_round_trips() {
    run(|| async {
        let (database, adapter, cache) = create().await;
        database.get_collection("webhooks").await.unwrap();
        reset(&adapter, &cache);
        assert!(database.find_collection("webhooks").await.unwrap().is_some());
        assert!(cache.operations() <= 1, "{} operations", cache.operations());
        assert_eq!(metadata_reads(&adapter), 0);
    });
}

#[test]
fn a_get_document_hit_stays_within_seven_three_round_trips() {
    run(|| async {
        let (database, adapter, cache) = create().await;
        database.create_document("webhooks", hook("hook")).await.unwrap();
        name(&database, "hook").await;
        reset(&adapter, &cache);
        assert_eq!(name(&database, "hook").await, Value::from("hook"));
        assert!(cache.operations() <= 2, "{} operations", cache.operations());
        assert_eq!(document_reads(&adapter) + metadata_reads(&adapter), 0);
    });
}

#[test]
fn a_cached_miss_stays_within_seven_three_round_trips() {
    run(|| async {
        let (database, adapter, cache) = create().await;
        database.get_document("webhooks", "missing", vec![], false).await.unwrap();
        reset(&adapter, &cache);
        assert!(database.get_document("webhooks", "missing", vec![], false).await.unwrap().is_empty());
        assert!(cache.operations() <= 2, "{} operations", cache.operations());
        assert_eq!(document_reads(&adapter), 0);
    });
}

#[test]
fn an_uncached_get_document_stays_within_seven_three_round_trips() {
    run(|| async {
        let (database, adapter, cache) = create().await;
        database.create_document("webhooks", hook("hook")).await.unwrap();
        database.get_collection("webhooks").await.unwrap();
        reset(&adapter, &cache);
        assert_eq!(name(&database, "hook").await, Value::from("hook"));
        assert!(cache.operations() <= 5, "{} operations", cache.operations());
        assert_eq!(document_reads(&adapter), 1);
    });
}

async fn collection_read(database: &Database, read: usize) {
    let by_name = || vec![Query::equal("name", ["hook"])];
    match read {
        0 => {
            database.find("webhooks", by_name(), PermissionType::Read).await.unwrap();
        }
        1 => {
            database.count("webhooks", by_name(), None).await.unwrap();
        }
        _ => {
            database.sum("webhooks", "count", vec![], None).await.unwrap();
        }
    }
}

#[test]
fn collection_reads_stay_within_seven_three_round_trips() {
    for read in 0..3 {
        run(move || async move {
            let (database, adapter, cache) = create().await;
            database.create_document("webhooks", hook("hook")).await.unwrap();
            collection_read(&database, read).await;
            reset(&adapter, &cache);
            collection_read(&database, read).await;
            assert!(cache.operations() <= 1, "read {read}: {} operations", cache.operations());
            assert_eq!(metadata_reads(&adapter), 0);
        });
    }
}

#[test]
fn a_sibling_read_after_a_write_stays_within_seven_three_round_trips_and_reads_nothing() {
    run(|| async {
        let (database, adapter, cache) = create().await;
        database.create_document("webhooks", hook("written")).await.unwrap();
        database.create_document("webhooks", hook("sibling")).await.unwrap();
        name(&database, "sibling").await;
        database.update_document("webhooks", "written", doc(vec![("name", Value::from("renamed"))])).await.unwrap();
        reset(&adapter, &cache);
        assert_eq!(name(&database, "sibling").await, Value::from("hook"));
        assert!(cache.operations() <= 2, "{} operations", cache.operations());
        assert_eq!(document_reads(&adapter) + metadata_reads(&adapter), 0);
    });
}

#[test]
fn single_document_writes_stay_within_seven_three_round_trips() {
    for (write, baseline) in [(0, 3), (1, 6), (2, 4), (3, 4), (4, 6)] {
        run(move || async move {
            let (database, _, cache) = create().await;
            database.create_document("webhooks", hook("hook")).await.unwrap();
            name(&database, "hook").await;
            cache.reset_operations();
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
                1 => database
                    .update_document("webhooks", "hook", doc(vec![("name", Value::from("renamed"))]))
                    .await
                    .map(|_| ()),
                2 => database
                    .increase_document_attribute("webhooks", "hook", "count", Value::Int(1), None)
                    .await
                    .map(|_| ()),
                3 => database
                    .decrease_document_attribute("webhooks", "hook", "count", Value::Int(1), None)
                    .await
                    .map(|_| ()),
                _ => database.delete_document("webhooks", "hook").await.map(|_| ()),
            };
            result.unwrap();
            assert!(
                cache.operations() <= baseline,
                "write {write}: {} operations, 7.3.12: {baseline}",
                cache.operations()
            );
        });
    }
}

#[test]
fn an_update_and_a_read_in_a_transaction_stay_within_seven_three_round_trips() {
    run(|| async {
        let (database, adapter, cache) = create().await;
        database.create_document("webhooks", hook("written")).await.unwrap();
        database.create_document("webhooks", hook("sibling")).await.unwrap();
        name(&database, "written").await;
        name(&database, "sibling").await;
        reset(&adapter, &cache);
        let read = database
            .with_transaction(|| async {
                database.update_document("webhooks", "written", doc(vec![("name", Value::from("renamed"))])).await?;
                database.get_document("webhooks", "sibling", vec![], false).await
            })
            .await
            .unwrap();
        assert_eq!(read.get("name"), Some(&Value::from("hook")));
        assert!(cache.operations() <= 11, "{} operations", cache.operations());
        assert_eq!(metadata_reads(&adapter), 0);
        assert!(document_reads(&adapter) <= 1, "{} document reads", document_reads(&adapter));
    });
}

#[test]
fn an_update_and_a_read_of_it_stay_within_seven_three_round_trips() {
    run(|| async {
        let (database, adapter, cache) = create().await;
        database.create_document("webhooks", hook("hook")).await.unwrap();
        name(&database, "hook").await;
        reset(&adapter, &cache);
        for round in 1..=10 {
            let renamed = format!("round {round}");
            database
                .update_document("webhooks", "hook", doc(vec![("name", Value::from(renamed.as_str()))]))
                .await
                .unwrap();
            assert_eq!(name(&database, "hook").await, Value::from(renamed.as_str()));
        }
        assert!(cache.operations() <= 110, "{} operations", cache.operations());
        assert!(document_reads(&adapter) <= 20, "{} document reads", document_reads(&adapter));
        assert_eq!(metadata_reads(&adapter), 0);
    });
}

#[test]
fn a_tenant_never_serves_under_another_tenants_epoch_of_a_global_definition() {
    run(|| async {
        let adapter = Observed::memory();
        let database = Database::new(adapter, over(&Leasable::new()));
        database
            .set_database("utopiaTests")
            .set_namespace(&unique("global_"))
            .set_shared_tables(true)
            .set_tenant(Value::Null)
            .set_global_collections(&["webhooks"]);
        database.authorization().add_role(&Role::any().to_string());
        database.create(None).await.unwrap();
        let any = Role::any();
        database
            .create_collection(collection(
                "webhooks",
                attributes(),
                vec![],
                Some(vec![Permission::read(&any), Permission::create(&any), Permission::update(&any)]),
                true,
            ))
            .await
            .unwrap();
        for tenant in [1, 2] {
            database.set_tenant(Value::Int(tenant));
            database.create_document("webhooks", hook("hook")).await.unwrap();
        }
        database.purge_cached_document(METADATA, "webhooks").await.unwrap();
        database.set_tenant(Value::Int(2));
        assert_eq!(name(&database, "hook").await, Value::from("hook"));
        database.set_tenant(Value::Int(1));
        assert_eq!(name(&database, "hook").await, Value::from("hook"));
        database
            .update_documents("webhooks", doc(vec![("name", Value::from("renamed"))]), vec![], 100, None)
            .await
            .unwrap();
        database.set_tenant(Value::Int(2));
        assert_eq!(name(&database, "hook").await, Value::from("hook"));
        database.set_tenant(Value::Int(1));
        assert_eq!(
            name(&database, "hook").await,
            Value::from("renamed"),
            "the epoch a global definition carries is each tenant's own"
        );
    });
}

#[test]
fn purging_the_metadata_collection_retires_every_cached_definition() {
    run(|| async {
        let (database, _, _) = create().await;
        database
            .create_collection(collection("logs", vec![], vec![], Some(vec![Permission::read(&Role::any())]), true))
            .await
            .unwrap();
        assert!(database.get_collection("webhooks").await.unwrap().document_security());
        assert!(database.get_collection("logs").await.unwrap().document_security());
        let uncached = uncached_twin(&database);
        for id in ["webhooks", "logs"] {
            uncached
                .update_collection(
                    id,
                    Update { permissions: Some(vec![Permission::read(&Role::any())]), document_security: Some(false) },
                )
                .await
                .unwrap();
        }
        assert!(database.get_collection("webhooks").await.unwrap().document_security(), "the cached definition stays");
        database.purge_cached_collection(METADATA).await.unwrap();
        assert!(!database.get_collection("webhooks").await.unwrap().document_security());
        assert!(!database.get_collection("logs").await.unwrap().document_security());
    });
}

#[test]
fn purging_the_metadata_collection_retires_a_cached_missing_collection() {
    run(|| async {
        let (database, _, _) = create().await;
        assert!(database.find_collection("logs").await.unwrap().is_none());
        uncached_twin(&database)
            .create_collection(collection("logs", vec![], vec![], Some(vec![Permission::read(&Role::any())]), true))
            .await
            .unwrap();
        assert!(database.find_collection("logs").await.unwrap().is_none(), "the cached miss stays");
        database.purge_cached_collection(METADATA).await.unwrap();
        assert!(database.find_collection("logs").await.unwrap().is_some());
    });
}
