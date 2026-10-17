//! PHP: `WithCacheLeaseTest` and `Cache\QueryMetadataTest` (its Memory
//! case).

use std::sync::Arc;
use std::sync::atomic::{AtomicUsize, Ordering};

use futures_util::future::BoxFuture;

use super::none_cache;
use crate::adapter::Adapter;
use crate::adapter::memory::Memory;
use crate::cache::testing::{Leasable, over};
use crate::database::Database;
use crate::database::cache_fixtures::run;
use crate::database::cache_fixtures::{collection, doc, string, strings, unique};
use crate::database::observed::{Observed, Op};
use crate::permission::Permission;
use crate::query::Query;
use crate::role::Role;
use crate::value::{Ext, Value};

/// `setUp()`: a `projects` collection holding `project`, named `fresh`.
async fn projects(adapter: Arc<dyn Adapter>) -> Database {
    let database = Database::new(adapter, over(&Leasable::new()));
    database.set_database("utopiaTests").set_namespace(&unique("with_cache_"));
    database.create(None).await.unwrap();
    database.create_collection(collection("projects", vec![], vec![], None, true)).await.unwrap();
    database.create_attribute("projects", string("name")).await.unwrap();
    database
        .create_document(
            "projects",
            doc(vec![
                ("$id", Value::from("project")),
                ("$permissions", strings([Permission::read(&Role::any())])),
                ("name", Value::from("fresh")),
            ]),
        )
        .await
        .unwrap();
    database
}

/// Writes the row through the adapter, leaving the cache holding the old
/// copy (`staleCache()`).
async fn stale(database: &Database, value: &str) {
    let definition = database.get_collection("projects").await.unwrap();
    let adapter = database.adapter();
    let mut row = adapter.get_document(&definition, "project", vec![], false).await.unwrap();
    row.set("name", Value::from(value)).unwrap();
    adapter.update_document(&definition, "project", row, true).await.unwrap();
}

async fn name(database: &Database, queries: Vec<Query>) -> Value {
    database
        .get_document("projects", "project", queries, false)
        .await
        .unwrap()
        .get("name")
        .cloned()
        .unwrap_or(Value::Null)
}

#[test]
fn a_document_purge_removes_all_variants() {
    run(|| async {
        let database = projects(Arc::new(Memory::new())).await;
        let projected = || vec![Query::select(["name"])];
        assert_eq!(name(&database, vec![]).await, Value::from("fresh"));
        assert_eq!(name(&database, projected()).await, Value::from("fresh"));
        stale(&database, "changed").await;
        assert_eq!(name(&database, vec![]).await, Value::from("fresh"));
        assert_eq!(name(&database, projected()).await, Value::from("fresh"));
        database.purge_cached_document("projects", "project").await.unwrap();
        assert_eq!(name(&database, vec![]).await, Value::from("changed"));
        assert_eq!(name(&database, projected()).await, Value::from("changed"));
    });
}

#[test]
fn a_read_in_flight_during_a_purge_cannot_cache_what_it_read() {
    run(|| async {
        let adapter = Observed::memory();
        let database = projects(adapter.clone()).await;
        let writer = database.clone();
        let mut armed = true;
        adapter.once(move |op| match op {
            Op::GetDocument { collection, .. } if armed && collection != crate::database::METADATA => {
                armed = false;
                let writer = writer.clone();
                Some(Box::pin(async move {
                    stale(&writer, "changed").await;
                    writer.purge_cached_document("projects", "project").await
                }) as BoxFuture<'static, crate::Result<()>>)
            }
            _ => None,
        });
        assert_eq!(name(&database, vec![]).await, Value::from("fresh"), "the read returns the row it read");
        assert_eq!(name(&database, vec![]).await, Value::from("changed"), "the read before the purge cached nothing");
    });
}

fn listed(document: &crate::Document) -> Value {
    Value::Array([Value::Ext(Ext::Document(Box::new(document.clone())))].into_iter().collect())
}

#[test]
fn a_stale_list_write_after_a_concurrent_purge_is_rejected() {
    run(|| async {
        let database = projects(Arc::new(Memory::new())).await;
        let key = database.query_cache_key("projects", None).await.unwrap();
        let document = database.get_document("projects", "project", vec![], false).await.unwrap();
        let result = database
            .with_cache(
                &key,
                || async {
                    database.purge_cached_queries("projects", None).await?;
                    Ok(listed(&document))
                },
                Some("list-hash"),
            )
            .await
            .unwrap();
        assert!(matches!(&result, Value::Array(a) if a.len() == 1));
        let calls = AtomicUsize::new(0);
        let fresh = database
            .with_cache(
                &key,
                || async {
                    calls.fetch_add(1, Ordering::SeqCst);
                    Ok(Value::Array(Default::default()))
                },
                Some("list-hash"),
            )
            .await
            .unwrap();
        assert_eq!(fresh, Value::Array(Default::default()));
        assert_eq!(calls.load(Ordering::SeqCst), 1);
    });
}

#[test]
fn a_list_write_lands_when_no_purge_runs_concurrently() {
    run(|| async {
        let database = projects(Arc::new(Memory::new())).await;
        let key = database.query_cache_key("projects", None).await.unwrap();
        let document = database.get_document("projects", "project", vec![], false).await.unwrap();
        database.with_cache(&key, || async { Ok(listed(&document)) }, Some("list-hash")).await.unwrap();
        let calls = AtomicUsize::new(0);
        let cached = database
            .with_cache(
                &key,
                || async {
                    calls.fetch_add(1, Ordering::SeqCst);
                    Ok(Value::Array(Default::default()))
                },
                Some("list-hash"),
            )
            .await
            .unwrap();
        assert!(matches!(&cached, Value::Array(a) if a.len() == 1));
        assert_eq!(calls.load(Ordering::SeqCst), 0);
    });
}

// ---- Cache\QueryMetadataTest -------------------------------------------

#[test]
fn collection_listings_follow_created_and_deleted_collections() {
    run(|| async {
        let database = Database::new(Arc::new(Memory::new()), none_cache());
        database.set_database("metadata").set_namespace(&unique("metadata_"));
        database.create(None).await.unwrap();
        database.authorization().add_role(&Role::any().to_string());
        database.set_query_cache(Some(super::query_cache()));
        let permissions = || Some(vec![Permission::read(&Role::any()), Permission::create(&Role::any())]);
        let listing = || async {
            let mut ids: Vec<String> =
                database.list_collections(25, 0).await.unwrap().iter().map(|c| c.id().to_owned()).collect();
            ids.sort();
            ids
        };
        database.create_collection(collection("first", vec![], vec![], permissions(), true)).await.unwrap();
        assert_eq!(listing().await, ["first"]);
        database.create_collection(collection("second", vec![], vec![], permissions(), true)).await.unwrap();
        assert_eq!(listing().await, ["first", "second"]);
        database.delete_collection("first").await.unwrap();
        assert_eq!(listing().await, ["second"]);
    });
}
