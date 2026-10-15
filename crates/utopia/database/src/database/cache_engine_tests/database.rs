//! PHP: `Cache\DatabaseTest` (its `Adapter\Memory` cases).

use std::future::Future;
use std::sync::Arc;

use super::query_cache;
use crate::adapter::Adapter;
use crate::adapter::memory::Memory;
use crate::attribute::Update as AttributeUpdate;
use crate::cache::testing::{Leasable, over};
use crate::cache::{Query as QueryCache, Scope};
use crate::collection::Update as CollectionUpdate;
use crate::database::Database;
use crate::database::fixtures::{
    any_permissions, collection, database_over, doc, ids, open_collection, string, strings, unavailable, unique,
    with_id,
};
use crate::database::observed::{Observed, Op};
use crate::database::testing::block_on;
use crate::document::Document;
use crate::error::{Error, Kind};
use crate::index::Index;
use crate::permission::{Permission, PermissionType};
use crate::query::Query;
use crate::role::Role;
use crate::validator::authorization::Authorization;
use crate::value::Value;

/// `createDatabase()`: a database over `adapter` (a fresh Memory), caching
/// documents in `data` (a `LeasableHashCache`), with a query cache over a
/// `LeasableHashCache` when `query` is set.
async fn create(adapter: Option<Arc<dyn Adapter>>, query: bool, data: Option<Arc<Leasable>>) -> Database {
    let adapter = adapter.unwrap_or_else(|| Arc::new(Memory::new()));
    let database = database_over(adapter, over(&data.unwrap_or_else(Leasable::lenient))).await;
    if query {
        database.set_query_cache(Some(query_cache()));
    }
    database
}

async fn create_users(database: &Database) {
    database.create_collection(open_collection("users", false)).await.unwrap();
    for id in ["a", "b", "c"] {
        database.create_document("users", with_id(id)).await.unwrap();
    }
}

async fn find(database: &Database, collection: &str, queries: Vec<Query>) -> Vec<String> {
    ids(&database.find(collection, queries, PermissionType::Read).await.unwrap())
}

async fn names(database: &Database) -> Vec<(String, Value)> {
    database
        .find("users", vec![Query::order_asc("$id", None)], PermissionType::Read)
        .await
        .unwrap()
        .iter()
        .map(|d| (d.id().to_owned(), d.get("name").cloned().unwrap_or(Value::Null)))
        .collect()
}

async fn scope(database: &Database) -> Scope {
    database.query_cache_scope(None).await
}

/// `assertMutationRetiresQueries()`.
async fn assert_mutation_retires_queries<F: Future>(database: &Database, mutation: F) {
    let query_cache = database.query_cache().expect("a query cache");
    let scope = scope(database).await;
    let empty = Value::Array(Default::default());
    let before = query_cache.entry(&scope, "users", &empty, "").await.unwrap().expect("an entry");
    let generation = query_cache.generation(&before).await.unwrap();
    assert!(query_cache.set(&before, &[with_id("stale")], &generation).await.unwrap());
    assert_eq!(ids(&query_cache.get(&before).await.unwrap().unwrap()), ["stale"]);

    mutation.await;

    let after = query_cache.entry(&scope, "users", &empty, "").await.unwrap().expect("the mutation leaves it usable");
    assert_eq!(query_cache.get(&after).await.unwrap(), None, "the mutation must retire what was cached before it");
    let generation = query_cache.generation(&after).await.unwrap();
    assert!(query_cache.set(&after, &[with_id("fresh")], &generation).await.unwrap());
    assert_eq!(ids(&query_cache.get(&after).await.unwrap().unwrap()), ["fresh"]);
}

#[test]
fn query_cache_separates_complete_execution_shapes() {
    block_on(async {
        let database = create(None, true, None).await;
        create_users(&database).await;
        let asc = || Query::order_asc("$id", None);
        assert_eq!(find(&database, "users", vec![asc(), Query::limit(1)]).await, ["a"]);
        assert_eq!(find(&database, "users", vec![asc(), Query::limit(2)]).await, ["a", "b"]);
        assert_eq!(find(&database, "users", vec![asc(), Query::limit(1), Query::offset(1)]).await, ["b"]);
        assert_eq!(find(&database, "users", vec![Query::order_desc("$id", None), Query::limit(1)]).await, ["c"]);
        let a = database.get_document("users", "a", vec![], false).await.unwrap();
        let b = database.get_document("users", "b", vec![], false).await.unwrap();
        assert_eq!(find(&database, "users", vec![asc(), Query::cursor_after(&a), Query::limit(1)]).await, ["b"]);
        assert_eq!(find(&database, "users", vec![asc(), Query::cursor_after(&b), Query::limit(1)]).await, ["c"]);
    });
}

#[test]
fn find_falls_back_to_the_database_when_the_query_cache_fails() {
    for method in ["load", "generation", "lease"] {
        block_on(async move {
            let adapter = Observed::memory();
            let database = create(Some(adapter.clone()), false, None).await;
            create_users(&database).await;
            let cache = Leasable::lenient();
            database.set_query_cache(Some(Arc::new(QueryCache::new(over(&cache), QueryCache::SLOTS).unwrap())));
            match method {
                "load" => cache.on_load(move |_, _| Some(unavailable())),
                "lease" => cache.on_save(move |_, hash, _| (!hash.is_empty()).then(unavailable)),
                _ => cache.on_generation(move |_| Some(unavailable())),
            }
            adapter.reset();
            assert_eq!(find(&database, "users", vec![Query::order_asc("$id", None)]).await, ["a", "b", "c"]);
            assert_eq!(adapter.count(|op| *op == Op::Find { collection: "users".into() }), 1, "{method}");
        });
    }
}

#[test]
fn random_order_always_bypasses_the_query_cache() {
    block_on(async {
        let adapter = Observed::memory();
        let database = create(Some(adapter.clone()), true, None).await;
        create_users(&database).await;
        adapter.reset();
        find(&database, "users", vec![Query::order_random(), Query::limit(1)]).await;
        find(&database, "users", vec![Query::order_random(), Query::limit(1)]).await;
        assert_eq!(adapter.count(|op| *op == Op::Find { collection: "users".into() }), 2);
    });
}

#[test]
fn set_query_cache_invalidates_while_installed_and_stops_once_removed() {
    block_on(async {
        let database = create(None, false, None).await;
        database.create_collection(open_collection("users", false)).await.unwrap();
        let query = query_cache();
        database.set_query_cache(Some(query.clone()));
        database.set_query_cache(Some(query.clone()));
        assert_mutation_retires_queries(&database, async {
            database.create_document("users", with_id("a")).await.unwrap();
        })
        .await;

        database.set_query_cache(None);
        let scope = scope(&database).await;
        let empty = Value::Array(Default::default());
        let before = query.entry(&scope, "users", &empty, "").await.unwrap().unwrap();
        let generation = query.generation(&before).await.unwrap();
        assert!(query.set(&before, &[with_id("detached")], &generation).await.unwrap());
        database.create_document("users", with_id("b")).await.unwrap();
        let after = query.entry(&scope, "users", &empty, "").await.unwrap().unwrap();
        assert_eq!(ids(&query.get(&after).await.unwrap().unwrap()), ["detached"]);
    });
}

async fn named(name: &str, namespace: &str, query: Arc<QueryCache>, document: &str) -> Database {
    let database = Database::new(Arc::new(Memory::new()), over(&Leasable::lenient()));
    database.set_database("cache-tests").set_namespace(namespace).set_cache_name(name).set_query_cache(Some(query));
    database.create(None).await.unwrap();
    database.authorization().add_role(&Role::any().to_string());
    database.create_collection(open_collection("users", false)).await.unwrap();
    database.create_document("users", with_id(document)).await.unwrap();
    database
}

#[test]
fn one_query_cache_shared_by_databases_of_different_names_keeps_their_results_apart() {
    block_on(async {
        let query = query_cache();
        let namespace = unique("cache_");
        let first = named("first", &namespace, query.clone(), "a").await;
        let second = named("second", &namespace, query, "b").await;
        let asc = || vec![Query::order_asc("$id", None)];
        assert_eq!(find(&first, "users", vec![]).await, ["a"]);
        assert_eq!(find(&second, "users", vec![]).await, ["b"]);
        first.create_document("users", with_id("c")).await.unwrap();
        assert_eq!(find(&first, "users", asc()).await, ["a", "c"]);
        assert_eq!(find(&second, "users", vec![]).await, ["b"]);
        second.create_document("users", with_id("d")).await.unwrap();
        assert_eq!(find(&second, "users", asc()).await, ["b", "d"]);
        assert_eq!(find(&first, "users", asc()).await, ["a", "c"]);
    });
}

#[test]
fn reads_and_writes_use_the_cache_name_set_after_the_query_cache() {
    block_on(async {
        let query = query_cache();
        let namespace = unique("cache_");
        let named = named("x", &namespace, query.clone(), "a").await;
        let renamed = Database::new(Arc::new(Memory::new()), over(&Leasable::lenient()));
        renamed.set_database("cache-tests").set_namespace(&namespace).set_query_cache(Some(query)).set_cache_name("x");
        renamed.create(None).await.unwrap();
        renamed.authorization().add_role(&Role::any().to_string());
        renamed.create_collection(open_collection("users", false)).await.unwrap();
        renamed.create_document("users", with_id("b")).await.unwrap();

        let asc = || vec![Query::order_asc("$id", None)];
        assert_eq!(find(&named, "users", asc()).await, ["a"]);
        assert_eq!(find(&renamed, "users", asc()).await, ["a"], "a read uses the cache name set after the query cache");
        renamed.create_document("users", with_id("c")).await.unwrap();
        assert_eq!(find(&renamed, "users", asc()).await, ["b", "c"]);
    });
}

#[test]
fn schema_and_collection_mutations_invalidate_queries() {
    for mutation in 0..7 {
        block_on(async move {
            let database = create(None, true, None).await;
            database
                .create_collection(collection(
                    "users",
                    vec![string("name"), string("title")],
                    vec![Index::key("name", ["name"], vec![], vec![]).unwrap()],
                    Some(any_permissions()),
                    false,
                ))
                .await
                .unwrap();
            assert_mutation_retires_queries(&database, async {
                match mutation {
                    0 => {
                        let update = CollectionUpdate { permissions: Some(any_permissions()), document_security: Some(false) };
                        database.update_collection("users", update).await.map(|_| ())
                    }
                    1 => database.create_attribute("users", string("email")).await.map(|_| ()),
                    2 => {
                        let update = AttributeUpdate { size: Some(128), ..Default::default() };
                        database.update_attribute("users", "name", update).await.map(|_| ())
                    }
                    3 => database.delete_attribute("users", "title").await,
                    4 => database
                        .create_index("users", Index::key("title", ["title"], vec![], vec![]).unwrap())
                        .await
                        .map(|_| ()),
                    5 => database.rename_index("users", "name", "renamed").await,
                    _ => database.delete_index("users", "name").await,
                }
                .unwrap_or_else(|e| panic!("mutation {mutation}: {e:?}"));
            })
            .await;
        });
    }
}

#[test]
fn delete_and_recreate_cannot_reuse_old_collection_results() {
    block_on(async {
        let database = create(None, true, None).await;
        database.create_collection(open_collection("users", false)).await.unwrap();
        database.create_document("users", with_id("old")).await.unwrap();
        assert_eq!(find(&database, "users", vec![]).await, ["old"]);
        database.delete_collection("users").await.unwrap();
        database.create_collection(open_collection("users", false)).await.unwrap();
        database.create_document("users", with_id("new")).await.unwrap();
        assert_eq!(find(&database, "users", vec![]).await, ["new"]);
    });
}

#[test]
fn a_rolled_back_transaction_cannot_poison_the_query_cache() {
    block_on(async {
        let database = create(None, true, None).await;
        database.create_collection(open_collection("users", false)).await.unwrap();
        database.create_document("users", with_id("committed")).await.unwrap();
        assert_eq!(find(&database, "users", vec![]).await, ["committed"]);
        let result: crate::Result<()> = database
            .with_transaction(|| async {
                database.create_document("users", with_id("rolled-back")).await?;
                assert_eq!(
                    find(&database, "users", vec![Query::order_asc("$id", None)]).await,
                    ["committed", "rolled-back"]
                );
                Err(Error::conflict("rollback"))
            })
            .await;
        assert_eq!(result.unwrap_err().kind(), Kind::Conflict);
        assert_eq!(find(&database, "users", vec![Query::order_asc("$id", None)]).await, ["committed"]);
    });
}

#[test]
fn a_metadata_read_started_before_a_purge_cannot_publish() {
    block_on(async {
        let adapter = Observed::memory();
        // PHP's data cache is `None` here: nothing is cached at all.
        let none = Arc::new(utopia_cache::Cache::from_dyn(Arc::new(utopia_cache::None)));
        let database = database_over(adapter.clone(), none).await;
        database.create_collection(open_collection("users", false)).await.unwrap();
        database.purge_cached_collection("users").await.unwrap();
        adapter.reset();
        let purger = database.clone();
        adapter.once(move |op| {
            matches!(op, Op::GetDocument { collection, id } if collection == crate::database::METADATA && id == "users")
                .then(|| {
                    let purger = purger.clone();
                    Box::pin(async move { purger.purge_cached_collection("users").await })
                        as futures_util::future::BoxFuture<'static, crate::Result<()>>
                })
        });
        database.get_collection("users").await.unwrap();
        database.get_collection("users").await.unwrap();
        assert_eq!(
            adapter.count(|op| matches!(op, Op::GetDocument { collection, id } if collection == crate::database::METADATA && id == "users")),
            2
        );
    });
}

#[test]
fn a_memory_cache_separates_roles_and_execution_shapes() {
    block_on(async {
        let database = create(None, false, None).await;
        database.set_query_cache(Some(Arc::new(QueryCache::new(over(&Leasable::plain()), QueryCache::SLOTS).unwrap())));
        let authorization = database.authorization();
        authorization
            .skip(async {
                database
                    .create_collection(collection(
                        "private",
                        vec![],
                        vec![],
                        Some(vec![Permission::create(&Role::any())]),
                        true,
                    ))
                    .await
                    .unwrap();
                for (id, user) in [("a", "user-1"), ("b", "user-2"), ("c", "user-1")] {
                    let permissions = strings([Permission::read(&Role::user(user, ""))]);
                    database
                        .create_document("private", doc(vec![("$id", Value::from(id)), ("$permissions", permissions)]))
                        .await
                        .unwrap();
                }
            })
            .await;
        authorization.add_role(&Role::user("user-1", "").to_string());
        let asc = || Query::order_asc("$id", None);
        assert_eq!(find(&database, "private", vec![asc(), Query::limit(1)]).await, ["a"]);
        assert_eq!(find(&database, "private", vec![Query::order_desc("$id", None), Query::limit(1)]).await, ["c"]);
        assert_eq!(find(&database, "private", vec![asc(), Query::limit(2)]).await, ["a", "c"]);
        authorization.remove_role(&Role::user("user-1", "").to_string());
        authorization.add_role(&Role::user("user-2", "").to_string());
        assert_eq!(find(&database, "private", vec![asc(), Query::limit(2)]).await, ["b"]);
    });
}

#[test]
fn a_memory_cache_separates_point_selection_variants() {
    block_on(async {
        let database = create(None, false, Some(Leasable::plain())).await;
        database
            .create_collection(collection("users", vec![string("name"), string("email")], vec![], Some(any_permissions()), false))
            .await
            .unwrap();
        database
            .create_document(
                "users",
                doc(vec![
                    ("$id", Value::from("user")),
                    ("name", Value::from("Alice")),
                    ("email", Value::from("alice@example.com")),
                ]),
            )
            .await
            .unwrap();
        let name = database.get_document("users", "user", vec![Query::select(["name"])], false).await.unwrap();
        let email = database.get_document("users", "user", vec![Query::select(["email"])], false).await.unwrap();
        assert_eq!(name.get("name"), Some(&Value::from("Alice")));
        assert!(name.get("email").is_none_or(|v| *v == Value::Null));
        assert_eq!(email.get("email"), Some(&Value::from("alice@example.com")));
        assert!(email.get("name").is_none_or(|v| *v == Value::Null));
    });
}

#[test]
fn permission_revocation_is_fresh_across_database_instances() {
    block_on(async {
        let adapter: Arc<dyn Adapter> = Arc::new(Memory::new());
        let authorization = Arc::new(Authorization::new(true));
        authorization.clean_roles();
        let user = Role::user("user-1", "");
        authorization.add_role(&user.to_string());
        let none = || Arc::new(utopia_cache::Cache::from_dyn(Arc::new(utopia_cache::None)));
        let writer = Database::new(adapter.clone(), none());
        let reader = Database::new(adapter, none());
        for database in [&writer, &reader] {
            database.set_authorization(authorization.clone()).set_database("cache-tests").set_namespace("shared_metadata");
        }
        writer.create(None).await.unwrap();
        writer
            .create_collection(collection(
                "users",
                vec![],
                vec![],
                Some(vec![Permission::read(&user), Permission::create(&user), Permission::update(&user)]),
                true,
            ))
            .await
            .unwrap();
        reader.get_collection("users").await.unwrap();
        writer
            .update_collection(
                "users",
                CollectionUpdate {
                    permissions: Some(vec![Permission::create(&user), Permission::update(&user)]),
                    document_security: Some(false),
                },
            )
            .await
            .unwrap();
        let error = reader.find("users", vec![], PermissionType::Read).await.unwrap_err();
        assert_eq!(error.kind(), Kind::Authorization);
    });
}

#[test]
fn a_silent_permission_revocation_still_invalidates_the_query_cache() {
    block_on(async {
        let database = create(None, false, None).await;
        database.set_query_cache(Some(Arc::new(QueryCache::new(over(&Leasable::plain()), QueryCache::SLOTS).unwrap())));
        let authorization = database.authorization();
        let user1 = Role::user("user-1", "");
        authorization
            .skip(async {
                database
                    .create_collection(collection("private", vec![], vec![], Some(vec![Permission::create(&Role::any())]), true))
                    .await
                    .unwrap();
                let permissions = strings([Permission::read(&user1), Permission::update(&user1)]);
                database
                    .create_document("private", doc(vec![("$id", Value::from("secret")), ("$permissions", permissions)]))
                    .await
                    .unwrap();
            })
            .await;
        authorization.add_role(&user1.to_string());
        assert_eq!(find(&database, "private", vec![]).await, ["secret"]);
        let revoked = strings([Permission::read(&Role::user("user-2", ""))]);
        authorization
            .skip(database.silent(database.update_document("private", "secret", doc(vec![("$permissions", revoked)]))))
            .await
            .unwrap();
        assert!(find(&database, "private", vec![]).await.is_empty());
    });
}

#[test]
fn a_mandatory_invalidation_failure_escapes_a_silent_scope() {
    block_on(async {
        let cache = Leasable::plain();
        let database = create(None, false, None).await;
        database.set_query_cache(Some(Arc::new(QueryCache::new(over(&cache), QueryCache::SLOTS).unwrap())));
        database.create_collection(open_collection("users", false)).await.unwrap();
        find(&database, "users", vec![]).await;
        cache.fail_blocks();
        let error = database.silent(database.create_document("users", with_id("user"))).await.unwrap_err();
        assert_eq!(error.kind(), Kind::Runtime);
    });
}

/// `assertMutationRollsBackOnInvalidationFailure()`.
async fn assert_rolls_back_on_invalidation_failure(mutation: usize) {
    let adapter = Observed::memory();
    let database = Database::new(adapter.clone(), over(&Leasable::lenient()));
    database.set_database("cache-tests").set_namespace(&unique("cache_"));
    database.create(None).await.unwrap();
    database.authorization().add_role(&Role::any().to_string());
    database
        .create_collection(collection("users", vec![string("name").required(true)], vec![], Some(any_permissions()), false))
        .await
        .unwrap();
    database
        .create_document("users", doc(vec![("$id", Value::from("existing")), ("name", Value::from("original"))]))
        .await
        .unwrap();
    let cache = Leasable::plain();
    database.set_query_cache(Some(Arc::new(QueryCache::new(over(&cache), QueryCache::SLOTS).unwrap())));
    let original = vec![("existing".to_owned(), Value::from("original"))];
    assert_eq!(names(&database).await, original);

    cache.fail_blocks();
    let named = |id: &str, name: &str| doc(vec![("$id", Value::from(id)), ("name", Value::from(name))]);
    let result = match mutation {
        0 => database.create_document("users", named("created", "created")).await.map(|_| ()),
        1 => database
            .create_documents("users", vec![named("first", "first"), named("second", "second")], 100, None)
            .await
            .map(|_| ()),
        2 => database.update_document("users", "existing", doc(vec![("name", Value::from("updated"))])).await.map(|_| ()),
        _ => database.delete_document("users", "existing").await.map(|_| ()),
    };
    let error = result.expect_err("the invalidation failure is propagated");
    assert!(error.message().contains("query cache epoch"), "{}", error.message());
    assert_eq!(names(&database).await, original, "the cached result still matches the rolled-back collection");

    database.set_query_cache(None);
    adapter.reset();
    assert_eq!(names(&database).await, original);
    assert_eq!(adapter.count(|op| *op == Op::Find { collection: "users".into() }), 1);
}

#[test]
fn writes_roll_back_when_the_mandatory_invalidation_fails() {
    for mutation in 0..4 {
        block_on(assert_rolls_back_on_invalidation_failure(mutation));
    }
}
