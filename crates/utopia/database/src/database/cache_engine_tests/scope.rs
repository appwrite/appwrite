//! PHP: `Cache\QueryScopeTest` and `Cache\QueryRoundTripTest`.

use std::sync::{Arc, Mutex};

use super::{finds, none_cache, on_commit};
use crate::cache::Query as QueryCache;
use crate::cache::testing::{Leasable, over};
use crate::database::Database;
use crate::database::fixtures::{ids, open_collection, unique, with_id};
use crate::database::observed::Observed;
use crate::database::fixtures::run;
use crate::permission::PermissionType;
use crate::query::Query;
use crate::role::Role;
use crate::value::Value;

async fn find(database: &Database) -> Vec<String> {
    ids(&database.find("posts", vec![Query::order_asc("$id", None)], PermissionType::Read).await.unwrap())
}

/// Sets a database's scope: a tenant, a namespace or a database name.
fn apply(database: &Database, kind: usize, side: &str) {
    match kind {
        0 => {
            database.set_shared_tables(true).set_tenant(Value::Int(if side == "writer" { 1 } else { 2 }));
        }
        1 => {
            database.set_namespace(side);
        }
        _ => {
            database.set_database(side);
        }
    }
}

/// `createDatabase()`.
async fn create(cache: &Arc<Leasable>, kind: usize, side: &str, seed: &str) -> (Database, Arc<Observed>) {
    let adapter = Observed::memory();
    let database = Database::new(adapter.clone(), none_cache());
    database.set_database("scope").set_namespace("scope");
    apply(&database, kind, side);
    database.create(None).await.unwrap();
    database.authorization().add_role(&Role::any().to_string());
    database.set_query_cache(Some(Arc::new(QueryCache::new(over(cache), QueryCache::SLOTS).unwrap())));
    database.create_collection(open_collection("posts", false)).await.unwrap();
    database.create_document("posts", with_id(seed)).await.unwrap();
    (database, adapter)
}

#[test]
fn a_write_leaves_the_cached_results_of_another_scope_in_place() {
    for kind in 0..3 {
        run(move || async move {
            let cache = Leasable::lenient();
            let (writer, _) = create(&cache, kind, "writer", "writer-seed").await;
            let (reader, reader_adapter) = create(&cache, kind, "reader", "reader-seed").await;
            assert_eq!(find(&reader).await, ["reader-seed"]);
            reader_adapter.reset();
            writer.create_document("posts", with_id("written")).await.unwrap();
            assert_eq!(find(&reader).await, ["reader-seed"]);
            assert_eq!(finds(&reader_adapter, "posts"), 0, "scope {kind}");
        });
    }
}

#[test]
fn a_pending_write_leaves_the_query_cache_of_another_scope_on() {
    for kind in 0..3 {
        run(move || async move {
            let cache = Leasable::lenient();
            let (writer, writer_adapter) = create(&cache, kind, "writer", "writer-seed").await;
            let (reader, reader_adapter) = create(&cache, kind, "reader", "reader-seed").await;
            assert_eq!(find(&reader).await, ["reader-seed"]);
            reader_adapter.reset();
            let during = Arc::new(Mutex::new(Vec::new()));
            let (seen, during_reader) = (during.clone(), reader.clone());
            on_commit(&writer_adapter, async move {
                *seen.lock().unwrap() = find(&during_reader).await;
                Ok(())
            });
            writer.create_document("posts", with_id("written")).await.unwrap();
            assert_eq!(*during.lock().unwrap(), ["reader-seed"]);
            assert_eq!(finds(&reader_adapter, "posts"), 0, "scope {kind}");
        });
    }
}

/// `createTenants()`.
async fn tenants() -> (Database, Arc<Observed>) {
    let adapter = Observed::memory();
    let database = Database::new(adapter.clone(), none_cache());
    database.set_database("scope").set_namespace(&unique("tenants_")).set_shared_tables(true).set_tenant(Value::Int(1));
    database.create(None).await.unwrap();
    database.authorization().add_role(&Role::any().to_string());
    database.set_query_cache(Some(super::query_cache()));
    for tenant in [1, 2] {
        database
            .with_tenant(Value::Int(tenant), async {
                database.create_collection(open_collection("posts", false)).await.unwrap();
                database.create_document("posts", with_id(&format!("seed-{tenant}"))).await.unwrap();
            })
            .await;
    }
    (database, adapter)
}

async fn find_as(database: &Database, tenant: i64) -> Vec<String> {
    database.with_tenant(Value::Int(tenant), find(database)).await
}

#[test]
fn a_write_under_another_tenant_of_the_same_database_leaves_the_cached_results_in_place() {
    run(|| async {
        let (database, adapter) = tenants().await;
        assert_eq!(find_as(&database, 2).await, ["seed-2"]);
        adapter.reset();
        database.with_tenant(Value::Int(1), database.create_document("posts", with_id("written"))).await.unwrap();
        assert_eq!(find_as(&database, 2).await, ["seed-2"]);
        assert_eq!(finds(&adapter, "posts"), 0);
    });
}

#[test]
fn a_write_refreshes_the_cached_results_of_its_own_tenant() {
    run(|| async {
        let (database, adapter) = tenants().await;
        assert_eq!(find_as(&database, 1).await, ["seed-1"]);
        database.with_tenant(Value::Int(1), database.create_document("posts", with_id("written"))).await.unwrap();
        adapter.reset();
        assert_eq!(find_as(&database, 1).await, ["seed-1", "written"]);
        assert_eq!(find_as(&database, 1).await, ["seed-1", "written"]);
        assert_eq!(finds(&adapter, "posts"), 1);
    });
}

#[test]
fn a_transaction_refreshes_every_tenant_it_wrote_under() {
    run(|| async {
        let (database, adapter) = tenants().await;
        for tenant in [1, 2] {
            find_as(&database, tenant).await;
        }
        database
            .with_transaction(|| async {
                for tenant in [1, 2] {
                    database
                        .with_tenant(Value::Int(tenant), database.create_document("posts", with_id(&format!("written-{tenant}"))))
                        .await?;
                }
                Ok(())
            })
            .await
            .unwrap();
        adapter.reset();
        for tenant in [1, 2] {
            for _ in 0..2 {
                assert_eq!(find_as(&database, tenant).await, [format!("seed-{tenant}"), format!("written-{tenant}")]);
            }
        }
        assert_eq!(finds(&adapter, "posts"), 2);
    });
}

#[test]
fn tenants_never_read_each_others_cached_results() {
    run(|| async {
        let (database, _) = tenants().await;
        for tenant in [1, 2, 1, 2] {
            assert_eq!(find_as(&database, tenant).await, [format!("seed-{tenant}")]);
        }
    });
}

// ---- QueryRoundTripTest -----------------------------------------------

async fn round_trips() -> (Database, Arc<Observed>, Arc<Leasable>) {
    let adapter = Observed::memory();
    let cache = Leasable::lenient();
    let database = Database::new(adapter.clone(), none_cache());
    database.set_database("round_trips").set_namespace(&unique("round_trips_"));
    database.create(None).await.unwrap();
    database.authorization().add_role(&Role::any().to_string());
    database.set_query_cache(Some(Arc::new(QueryCache::new(over(&cache), QueryCache::SLOTS).unwrap())));
    database.create_collection(open_collection("posts", false)).await.unwrap();
    database.create_document("posts", with_id("first")).await.unwrap();
    (database, adapter, cache)
}

#[test]
fn a_cached_find_stays_within_the_hit_round_trip_budget() {
    run(|| async {
        let (database, adapter, cache) = round_trips().await;
        find(&database).await;
        adapter.reset();
        cache.reset_operations();
        assert_eq!(find(&database).await, ["first"]);
        assert_eq!(finds(&adapter, "posts"), 0);
        assert!(cache.operations() <= 3, "{} cache operations", cache.operations());
    });
}

#[test]
fn an_uncached_find_stays_within_the_miss_round_trip_budget() {
    run(|| async {
        let (database, adapter, cache) = round_trips().await;
        adapter.reset();
        cache.reset_operations();
        assert_eq!(find(&database).await, ["first"]);
        assert_eq!(finds(&adapter, "posts"), 1);
        assert!(cache.operations() <= 5, "{} cache operations", cache.operations());
    });
}
