//! PHP: `Cache\QueryPurgeTest`.

use std::sync::Arc;

use super::query_cache;
use crate::adapter::memory::Memory;
use crate::cache::Query as QueryCache;
use crate::cache::testing::{Leasable, over};
use crate::database::Database;
use crate::database::fixtures::run;
use crate::database::fixtures::{ids, open_collection, unique, with_id};
use crate::permission::PermissionType;
use crate::query::Query;
use crate::role::Role;
use crate::validator::authorization::Authorization;

/// A reader with the query cache and a writer on the same data without it
/// (`createDatabases()`).
async fn databases(namespace: &str, query: Option<Arc<QueryCache>>) -> (Database, Database) {
    let adapter = Arc::new(Memory::new());
    let cache = over(&Leasable::lenient());
    let authorization = Arc::new(Authorization::new(true));
    authorization.add_role(&Role::any().to_string());
    let make = || {
        let database = Database::new(adapter.clone(), cache.clone());
        database.set_authorization(authorization.clone()).set_database("purge").set_namespace(namespace);
        database
    };
    let (reader, bypass) = (make(), make());
    reader.create(None).await.unwrap();
    reader.set_query_cache(Some(query.unwrap_or_else(query_cache)));
    reader.create_collection(open_collection("posts", false)).await.unwrap();
    reader.create_document("posts", with_id("first")).await.unwrap();
    (reader, bypass)
}

async fn find(database: &Database) -> Vec<String> {
    ids(&database.find("posts", vec![Query::order_asc("$id", None)], PermissionType::Read).await.unwrap())
}

#[test]
fn purge_cached_queries_drops_the_results_find_cached() {
    run(|| async {
        let (reader, bypass) = databases(&unique("posts_"), None).await;
        assert_eq!(find(&reader).await, ["first"]);
        bypass.create_document("posts", with_id("second")).await.unwrap();
        assert_eq!(find(&reader).await, ["first"], "a write the query cache never saw stays invisible");
        assert!(reader.purge_cached_queries("posts", None).await.unwrap());
        assert_eq!(find(&reader).await, ["first", "second"]);
    });
}

#[test]
fn purge_cached_queries_reaches_the_namespace_it_names() {
    run(|| async {
        let namespace = unique("posts_");
        let shared = query_cache();
        let (reader, bypass) = databases(&namespace, Some(shared.clone())).await;
        let caller = Database::new(Arc::new(Memory::new()), over(&Leasable::lenient()));
        caller.set_database("purge").set_namespace(&unique("caller_"));
        caller.set_query_cache(Some(shared));
        caller.create(None).await.unwrap();

        assert_eq!(find(&reader).await, ["first"]);
        bypass.create_document("posts", with_id("second")).await.unwrap();
        assert!(caller.purge_cached_queries("posts", Some(&namespace)).await.unwrap());
        assert_eq!(find(&reader).await, ["first", "second"]);
    });
}

#[test]
fn purge_cached_queries_reports_a_query_cache_it_could_not_purge() {
    run(|| async {
        let failing = Leasable::plain();
        let (reader, _) =
            databases(&unique("posts_"), Some(Arc::new(QueryCache::new(over(&failing), QueryCache::SLOTS).unwrap())))
                .await;
        failing.fail_blocks();
        assert!(!reader.purge_cached_queries("posts", None).await.unwrap());
    });
}

#[test]
fn purge_cached_collection_invalidates_cached_finds() {
    run(|| async {
        let (reader, bypass) = databases(&unique("posts_"), None).await;
        assert_eq!(find(&reader).await, ["first"]);
        bypass.create_document("posts", with_id("second")).await.unwrap();
        reader.purge_cached_collection("posts").await.unwrap();
        assert_eq!(find(&reader).await, ["first", "second"]);
    });
}

#[test]
fn purge_cached_queries_returns_false_when_the_cache_fails() {
    run(|| async {
        let cache = Leasable::plain();
        let database = crate::database::fixtures::database_over(Arc::new(Memory::new()), over(&cache)).await;
        database.create_collection(open_collection("posts", false)).await.unwrap();

        cache.on_load(move |key, _| key.ends_with(":query#epoch").then(unavailable).flatten());
        cache.on_save(move |key, _, _| key.ends_with(":query#epoch").then(unavailable).flatten());
        cache.on_purge(move |key, _| key.ends_with(":query#epoch").then(unavailable).flatten());
        assert!(!database.purge_cached_queries("posts", None).await.unwrap());
    });
}

fn unavailable<T>() -> Option<utopia_cache::Result<T>> {
    Some(Err(utopia_cache::Error::Runtime("Cache unavailable".into())))
}
