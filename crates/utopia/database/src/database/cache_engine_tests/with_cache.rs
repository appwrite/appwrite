//! PHP: `WithCacheTest` (the cases on `Adapter\Memory` that the stub could
//! not run) and `Documents\WithCacheTest`.

use std::sync::Arc;
use std::sync::atomic::{AtomicUsize, Ordering};

use crate::adapter::memory::Memory;
use crate::cache::testing::{Leasable, over};
use crate::database::Database;
use crate::database::cache_fixtures::run;
use crate::database::cache_fixtures::{
    collection, database_over, doc, ids, setup, string, strings, unavailable, with_id,
};
use crate::document::Document;
use crate::error::{Kind, Result};
use crate::filter::{Callback, Codec};
use crate::index::Index;
use crate::permission::{Permission, PermissionType};
use crate::query::Query;
use crate::role::Role;
use crate::validator::authorization::Authorization;
use crate::value::{Array, ArrayKey, Ext, Str, Value, as_document};

/// `findWithCache()`.
async fn find_with_cache(database: &Database, collection: &str, queries: Vec<Query>, namespace: &str) -> Vec<Document> {
    if queries.iter().any(|q| q.method() == utopia_query::Method::OrderRandom) {
        return database.find(collection, queries, PermissionType::Read).await.unwrap();
    }
    let definition = database.get_collection(collection).await.unwrap();
    let key = database.query_cache_key(definition.id(), Some(namespace)).await.unwrap();
    let hash = database.query_cache_field(Some(&definition), &queries, "", PermissionType::Read).unwrap();
    let value = database
        .with_cache(
            &key,
            || async { Ok(documents(database.find(collection, queries.clone(), PermissionType::Read).await?)) },
            hash.as_deref(),
        )
        .await
        .unwrap();
    as_documents(&value)
}

fn documents(documents: Vec<Document>) -> Value {
    Value::Array(documents.into_iter().map(|d| Value::Ext(Ext::Document(Box::new(d)))).collect())
}

fn as_documents(value: &Value) -> Vec<Document> {
    match value {
        Value::Array(items) => items.iter().filter_map(|(_, v)| as_document(v).cloned()).collect(),
        _ => panic!("documents, not {value:?}"),
    }
}

fn read_create() -> Vec<String> {
    vec![Permission::read(&Role::any()), Permission::create(&Role::any())]
}

async fn waf_rules(database: &Database, documents: &[&str]) {
    database
        .create_collection(collection("wafRules", vec![string("projectId")], vec![], Some(read_create()), false))
        .await
        .unwrap();
    for id in documents {
        database
            .create_document("wafRules", doc(vec![("$id", Value::from(*id)), ("projectId", Value::from("project-a"))]))
            .await
            .unwrap();
    }
}

fn project_queries() -> Vec<Query> {
    vec![Query::equal("projectId", ["project-a"]), Query::order_asc("$id", None), Query::limit(25)]
}

/// `storeCacheValue()`.
async fn store(cache: &Arc<Leasable>, key: &str, hash: &str, value: php_std::Value) {
    let epoch = "test-epoch";
    cache.put(&format!("{key}#epoch").to_lowercase(), "", php_std::Value::from(epoch));
    cache.put(&format!("{key}#{epoch}:{hash}").to_lowercase(), "", value);
}

fn assoc(pairs: Vec<(&str, php_std::Value)>) -> php_std::Value {
    php_std::Value::Array(pairs.into_iter().map(|(k, v)| (ArrayKey::Str(Str::from(k)), v)).collect())
}

fn list(items: Vec<php_std::Value>) -> php_std::Value {
    php_std::Value::Array(items.into_iter().collect())
}

#[test]
fn with_cache_caches_a_single_document() {
    run(|| async {
        let database = database_over(Arc::new(Memory::new()), over(&Leasable::hash_memory())).await;
        database
            .create_collection(collection(
                "wafRules",
                vec![],
                vec![],
                Some(vec![Permission::read(&Role::any()), Permission::create(&Role::any())]),
                false,
            ))
            .await
            .unwrap();
        database.create_document("wafRules", with_id("rule-a")).await.unwrap();
        let definition = database.get_collection("wafRules").await.unwrap();
        let key = database.query_cache_key(definition.id(), Some("_39")).await.unwrap();
        let hash = database.query_cache_field(Some(&definition), &[], "document", PermissionType::Read).unwrap();
        let calls = AtomicUsize::new(0);
        let read = |id: &'static str| {
            let (database, calls) = (&database, &calls);
            move || async move {
                calls.fetch_add(1, Ordering::SeqCst);
                Ok(Value::Ext(Ext::Document(Box::new(database.get_document("wafRules", id, vec![], false).await?))))
            }
        };
        let first = database.with_cache(&key, read("rule-a"), hash.as_deref()).await.unwrap();
        let second = database.with_cache(&key, read("missing"), hash.as_deref()).await.unwrap();
        assert_eq!(as_document(&first).unwrap().id(), "rule-a");
        assert_eq!(as_document(&second).unwrap().id(), "rule-a");
        assert_eq!(calls.load(Ordering::SeqCst), 1);
    });
}

#[test]
fn with_cache_caches_static_query_values() {
    run(|| async {
        let database = database_over(Arc::new(Memory::new()), over(&Leasable::hash_memory())).await;
        database
            .create_collection(collection(
                "wafRules",
                vec![],
                vec![],
                Some(vec![Permission::read(&Role::any())]),
                false,
            ))
            .await
            .unwrap();
        let definition = database.get_collection("wafRules").await.unwrap();
        let key = database.query_cache_key(definition.id(), Some("_39")).await.unwrap();
        let hash = database.query_cache_field(Some(&definition), &[], "count", PermissionType::Read).unwrap();
        let calls = AtomicUsize::new(0);
        let count = |n: i64| {
            let calls = &calls;
            move || async move {
                calls.fetch_add(1, Ordering::SeqCst);
                Ok(Value::Int(n))
            }
        };
        assert_eq!(database.with_cache(&key, count(10), hash.as_deref()).await.unwrap(), Value::Int(10));
        assert_eq!(database.with_cache(&key, count(20), hash.as_deref()).await.unwrap(), Value::Int(10));
        assert_eq!(calls.load(Ordering::SeqCst), 1);
    });
}

#[test]
fn the_query_cache_is_used_until_purged() {
    run(|| async {
        let database = database_over(Arc::new(Memory::new()), over(&Leasable::hash_memory())).await;
        waf_rules(&database, &["rule-a"]).await;
        assert_eq!(ids(&find_with_cache(&database, "wafRules", project_queries(), "_39").await), ["rule-a"]);
        database
            .create_document(
                "wafRules",
                doc(vec![("$id", Value::from("rule-b")), ("projectId", Value::from("project-a"))]),
            )
            .await
            .unwrap();
        assert_eq!(ids(&find_with_cache(&database, "wafRules", project_queries(), "_39").await), ["rule-a"]);
        assert!(database.purge_cached_queries("wafRules", Some("_39")).await.unwrap());
        assert_eq!(ids(&find_with_cache(&database, "wafRules", project_queries(), "_39").await), ["rule-a", "rule-b"]);
    });
}

#[test]
fn the_query_cache_separates_entries_by_authorization_context() {
    run(|| async {
        let database = database_over(Arc::new(Memory::new()), over(&Leasable::hash_memory())).await;
        waf_rules(&database, &["rule-a"]).await;
        find_with_cache(&database, "wafRules", project_queries(), "_39").await;
        database
            .create_document(
                "wafRules",
                doc(vec![("$id", Value::from("rule-b")), ("projectId", Value::from("project-a"))]),
            )
            .await
            .unwrap();
        assert_eq!(find_with_cache(&database, "wafRules", project_queries(), "_39").await.len(), 1);
        database.authorization().add_role(&Role::user("user-1", "").to_string());
        assert_eq!(find_with_cache(&database, "wafRules", project_queries(), "_39").await.len(), 2);
    });
}

#[test]
fn the_query_cache_recasts_cache_hits() {
    run(|| async {
        let database = database_over(Arc::new(Memory::new()), over(&Leasable::json_hash_memory())).await;
        database
            .create_collection(collection(
                "metrics",
                vec![crate::Attribute::double("value")],
                vec![],
                Some(read_create()),
                false,
            ))
            .await
            .unwrap();
        database
            .create_document("metrics", doc(vec![("$id", Value::from("metric-a")), ("value", Value::Float(1.0))]))
            .await
            .unwrap();
        let queries = || vec![Query::order_asc("$id", None), Query::limit(25)];
        let fresh = find_with_cache(&database, "metrics", queries(), "_39").await;
        let cached = find_with_cache(&database, "metrics", queries(), "_39").await;
        assert_eq!(fresh[0].get("value"), Some(&Value::Float(1.0)));
        assert_eq!(cached[0].get("value"), Some(&Value::Float(1.0)));
    });
}

#[test]
fn the_query_cache_does_not_double_decode_custom_filters() {
    run(|| async {
        let wrapped = Callback::new(
            "wrapped",
            |v: Value| Ok(crate::value::string(format!("encoded:{}", crate::value::to_text(&v)))),
            |v: Value| {
                let text = crate::value::to_text(&v);
                Ok(crate::value::string(match text.strip_prefix("encoded:") {
                    Some(rest) => rest.to_owned(),
                    None => format!("double:{text}"),
                }))
            },
        );
        let database = Database::with_filters(
            Arc::new(Memory::new()),
            over(&Leasable::hash_memory()),
            vec![Arc::new(wrapped) as Arc<dyn Codec>],
        )
        .unwrap();
        setup(&database).await;
        database
            .create_collection(collection(
                "secrets",
                vec![string("secret").filters(["wrapped"])],
                vec![],
                Some(read_create()),
                false,
            ))
            .await
            .unwrap();
        database
            .create_document("secrets", doc(vec![("$id", Value::from("secret-a")), ("secret", Value::from("value"))]))
            .await
            .unwrap();
        let queries = || vec![Query::order_asc("$id", None), Query::limit(25)];
        let fresh = find_with_cache(&database, "secrets", queries(), "_39").await;
        let cached = find_with_cache(&database, "secrets", queries(), "_39").await;
        assert_eq!(fresh[0].get("secret"), Some(&Value::from("value")));
        assert_eq!(cached[0].get("secret"), Some(&Value::from("value")));
    });
}

#[test]
fn the_query_cache_is_bypassed_for_a_random_order() {
    run(|| async {
        let database = database_over(Arc::new(Memory::new()), over(&Leasable::hash_memory())).await;
        waf_rules(&database, &["rule-a"]).await;
        let queries = || vec![Query::equal("projectId", ["project-a"]), Query::order_random(), Query::limit(25)];
        assert_eq!(find_with_cache(&database, "wafRules", queries(), "_39").await.len(), 1);
        database
            .create_document(
                "wafRules",
                doc(vec![("$id", Value::from("rule-b")), ("projectId", Value::from("project-a"))]),
            )
            .await
            .unwrap();
        assert_eq!(find_with_cache(&database, "wafRules", queries(), "_39").await.len(), 2);
    });
}

#[test]
fn the_query_cache_relies_on_a_purge_for_document_security_collections() {
    run(|| async {
        let database = database_over(Arc::new(Memory::new()), over(&Leasable::hash_memory())).await;
        let authorization = database.authorization();
        let user1 = Role::user("user-1", "");
        authorization
            .skip(async {
                database
                    .create_collection(collection(
                        "secureRules",
                        vec![string("projectId")],
                        vec![],
                        Some(vec![Permission::create(&Role::any())]),
                        true,
                    ))
                    .await
                    .unwrap();
                database
                    .create_document(
                        "secureRules",
                        doc(vec![
                            ("$id", Value::from("rule-a")),
                            ("$permissions", strings([Permission::read(&user1), Permission::update(&Role::any())])),
                            ("projectId", Value::from("project-a")),
                        ]),
                    )
                    .await
                    .unwrap();
            })
            .await;
        authorization.add_role(&user1.to_string());
        assert_eq!(find_with_cache(&database, "secureRules", project_queries(), "_39").await.len(), 1);
        let revoked = strings([Permission::read(&Role::user("user-2", "")), Permission::update(&Role::any())]);
        authorization
            .skip(database.update_document("secureRules", "rule-a", doc(vec![("$permissions", revoked)])))
            .await
            .unwrap();
        assert_eq!(find_with_cache(&database, "secureRules", project_queries(), "_39").await.len(), 1);
        assert!(database.purge_cached_queries("secureRules", Some("_39")).await.unwrap());
        assert!(find_with_cache(&database, "secureRules", project_queries(), "_39").await.is_empty());
    });
}

#[test]
fn the_query_cache_filters_document_security_payloads_on_a_hit() {
    run(|| async {
        let cache = Leasable::hash_memory();
        let database = database_over(Arc::new(Memory::new()), over(&cache)).await;
        let authorization = database.authorization();
        authorization
            .skip(database.create_collection(collection(
                "secureRules",
                vec![],
                vec![],
                Some(vec![Permission::create(&Role::any())]),
                true,
            )))
            .await
            .unwrap();
        authorization.add_role(&Role::user("user-1", "").to_string());
        let definition = database.get_collection("secureRules").await.unwrap();
        let key = database.query_cache_key(definition.id(), Some("_39")).await.unwrap();
        let hash = database.query_cache_field(Some(&definition), &[], "", PermissionType::Read).unwrap().unwrap();
        let permissions = list(vec![php_std::Value::from(Permission::read(&Role::user("user-2", "")).as_str())]);
        store(
            &cache,
            &key,
            &hash,
            assoc(vec![
                ("collection", php_std::Value::from(definition.id())),
                ("type", php_std::Value::from("documents")),
                (
                    "value",
                    list(vec![assoc(vec![("$id", php_std::Value::from("rule-a")), ("$permissions", permissions)])]),
                ),
            ]),
        )
        .await;
        let calls = AtomicUsize::new(0);
        let value = database
            .with_cache(
                &key,
                || async {
                    calls.fetch_add(1, Ordering::SeqCst);
                    Ok(documents(vec![with_id("fresh")]))
                },
                Some(&hash),
            )
            .await
            .unwrap();
        assert_eq!(value, Value::Array(Array::new()));
        assert_eq!(calls.load(Ordering::SeqCst), 0);
    });
}

#[test]
fn the_query_cache_rehydrates_nested_document_payloads() {
    run(|| async {
        let cache = Leasable::hash_memory();
        let database = database_over(Arc::new(Memory::new()), over(&cache)).await;
        database
            .create_collection(collection("parents", vec![], vec![], Some(vec![Permission::read(&Role::any())]), false))
            .await
            .unwrap();
        let queries = vec![Query::limit(25)];
        let definition = database.get_collection("parents").await.unwrap();
        let hash = database.query_cache_field(Some(&definition), &queries, "", PermissionType::Read).unwrap().unwrap();
        let key = database.query_cache_key(definition.id(), Some("_39")).await.unwrap();
        let child = assoc(vec![
            ("$id", php_std::Value::from("child-a")),
            ("$collection", php_std::Value::from("children")),
            ("name", php_std::Value::from("Child A")),
        ]);
        store(
            &cache,
            &key,
            &hash,
            assoc(vec![
                ("collection", php_std::Value::from("parents")),
                ("type", php_std::Value::from("documents")),
                ("value", list(vec![assoc(vec![("$id", php_std::Value::from("parent-a")), ("child", child)])])),
            ]),
        )
        .await;
        let parents = find_with_cache(&database, "parents", queries, "_39").await;
        assert_eq!(parents.len(), 1);
        let child = parents[0].get("child").and_then(as_document).expect("a nested document");
        assert_eq!(child.id(), "child-a");
    });
}

#[test]
fn the_query_cache_refreshes_an_invalid_payload() {
    for case in 0..2 {
        run(move || async move {
            let cache = Leasable::hash_memory();
            let database = database_over(Arc::new(Memory::new()), over(&cache)).await;
            waf_rules(&database, if case == 0 { &["rule-a"] } else { &["rule-a", "rule-b"] }).await;
            let definition = database.get_collection("wafRules").await.unwrap();
            let hash = database
                .query_cache_field(Some(&definition), &project_queries(), "", PermissionType::Read)
                .unwrap()
                .unwrap();
            let key = database.query_cache_key(definition.id(), Some("_39")).await.unwrap();
            let value = if case == 0 {
                php_std::Value::from("invalid")
            } else {
                list(vec![
                    assoc(vec![
                        ("$id", php_std::Value::from("rule-a")),
                        ("projectId", php_std::Value::from("project-a")),
                    ]),
                    php_std::Value::from("invalid"),
                ])
            };
            store(
                &cache,
                &key,
                &hash,
                assoc(vec![
                    ("collection", php_std::Value::from("wafRules")),
                    ("type", php_std::Value::from("documents")),
                    ("value", value),
                ]),
            )
            .await;
            let rules = find_with_cache(&database, "wafRules", project_queries(), "_39").await;
            let expected: &[&str] = if case == 0 { &["rule-a"] } else { &["rule-a", "rule-b"] };
            assert_eq!(ids(&rules), expected);
        });
    }
}

// ---- Documents\WithCacheTest ------------------------------------------

const COLLECTION: &str = "reports";
const KEY: &str = "reports:summary";

/// `database()`.
async fn reports(
    adapter: Arc<dyn crate::adapter::Adapter>,
    cache: Arc<utopia_cache::Cache>,
    authorization: Option<Arc<Authorization>>,
    permissions: Option<Vec<String>>,
    document_security: bool,
) -> Database {
    let database = Database::new(adapter, cache);
    if let Some(authorization) = authorization {
        database.set_authorization(authorization);
    }
    database.set_database("with_cache").set_namespace(&crate::database::cache_fixtures::unique("with_cache_"));
    database.create(None).await.unwrap();
    let any = Role::any();
    let permissions =
        permissions.unwrap_or_else(|| vec![Permission::create(&any), Permission::read(&any), Permission::delete(&any)]);
    database
        .authorization()
        .skip(database.create_collection(collection(
            COLLECTION,
            vec![crate::Attribute::string("title", 32), crate::Attribute::datetime("publishedAt")],
            vec![],
            Some(permissions),
            document_security,
        )))
        .await
        .unwrap();
    database
}

/// `cached()`.
async fn cached<F>(database: &Database, calls: &AtomicUsize, callback: F) -> Result<Value>
where
    F: std::future::Future<Output = Result<Value>>,
{
    database
        .with_cache(
            KEY,
            || async {
                calls.fetch_add(1, Ordering::SeqCst);
                callback.await
            },
            Some(""),
        )
        .await
}

fn memory_cache() -> Arc<utopia_cache::Cache> {
    over(&Leasable::plain())
}

async fn get(database: &Database, id: &str) -> Result<Value> {
    Ok(Value::Ext(Ext::Document(Box::new(database.get_document(COLLECTION, id, vec![], false).await?))))
}

#[test]
fn a_cached_value_is_refused_to_a_caller_who_cannot_read_the_collection() {
    run(|| async {
        let authorization = Arc::new(Authorization::new(true));
        let reader = Role::user("reader", "");
        authorization.add_role(&reader.to_string());
        let database = reports(
            Arc::new(Memory::new()),
            memory_cache(),
            Some(authorization.clone()),
            Some(vec![Permission::read(&reader)]),
            false,
        )
        .await;
        authorization
            .skip(
                database
                    .create_document(COLLECTION, doc(vec![("$id", Value::from("q3")), ("title", Value::from("Q3"))])),
            )
            .await
            .unwrap();
        let calls = AtomicUsize::new(0);
        for _ in 0..2 {
            let value = cached(&database, &calls, get(&database, "q3")).await.unwrap();
            assert_eq!(as_document(&value).unwrap().get("title"), Some(&Value::from("Q3")));
        }
        assert_eq!(calls.load(Ordering::SeqCst), 1);
        authorization.remove_role(&reader.to_string());
        let error = cached(&database, &calls, get(&database, "q3")).await.unwrap_err();
        assert_eq!(error.kind(), Kind::Authorization);
    });
}

#[test]
fn a_cached_document_the_caller_cannot_read_is_recomputed() {
    run(|| async {
        let authorization = Arc::new(Authorization::new(true));
        let owner = Role::user("owner", "");
        authorization.add_role(&owner.to_string());
        let database =
            reports(Arc::new(Memory::new()), memory_cache(), Some(authorization.clone()), Some(vec![]), true).await;
        authorization
            .skip(database.create_document(
                COLLECTION,
                doc(vec![
                    ("$id", Value::from("q3")),
                    ("$permissions", strings([Permission::read(&owner)])),
                    ("title", Value::from("Q3")),
                ]),
            ))
            .await
            .unwrap();
        let calls = AtomicUsize::new(0);
        let value = cached(&database, &calls, get(&database, "q3")).await.unwrap();
        assert_eq!(as_document(&value).unwrap().get("title"), Some(&Value::from("Q3")));
        authorization.remove_role(&owner.to_string());
        authorization.add_role(&Role::user("stranger", "").to_string());
        let value = cached(&database, &calls, get(&database, "q3")).await.unwrap();
        assert!(as_document(&value).unwrap().is_empty());
        assert_eq!(calls.load(Ordering::SeqCst), 2);
    });
}

#[test]
fn a_cached_value_of_a_deleted_collection_is_recomputed() {
    run(|| async {
        let database = reports(Arc::new(Memory::new()), memory_cache(), None, None, false).await;
        database
            .create_document(COLLECTION, doc(vec![("$id", Value::from("q3")), ("title", Value::from("Q3"))]))
            .await
            .unwrap();
        let calls = AtomicUsize::new(0);
        cached(&database, &calls, async {
            Ok(documents(database.find(COLLECTION, vec![], PermissionType::Read).await?))
        })
        .await
        .unwrap();
        database.delete_collection(COLLECTION).await.unwrap();
        let recomputed = cached(&database, &calls, async { Ok(Value::from("recomputed")) }).await.unwrap();
        assert_eq!(recomputed, Value::from("recomputed"));
        assert_eq!(calls.load(Ordering::SeqCst), 2);
    });
}

#[test]
fn a_cache_failure_falls_back_to_the_callback() {
    for failure in ["loadEpoch", "saveEpoch", "listEpoch", "loadValue", "purge", "getGeneration", "saveWithLease"] {
        run(move || async move {
            // PHP's failing cache is leasable; the leased calls fail on one
            // that is.
            let cache =
                if matches!(failure, "getGeneration" | "saveWithLease") { Leasable::new() } else { Leasable::plain() };
            let database = reports(Arc::new(Memory::new()), over(&cache), None, None, false).await;
            if failure == "listEpoch" {
                cache.put(&format!("{KEY}#epoch"), "", list(vec![php_std::Value::from("not")]));
            }
            if failure == "purge" {
                cache.put(&format!("{KEY}#epoch"), "", php_std::Value::from("fixed"));
                cache.put(&format!("{KEY}#fixed:"), "", php_std::Value::from("not a cache entry"));
            }
            match failure {
                "loadEpoch" => cache.on_load(|key, _| (key == format!("{KEY}#epoch")).then(unavailable)),
                "loadValue" => {
                    cache.on_load(|key, _| (key.starts_with(KEY) && !key.ends_with("#epoch")).then(unavailable))
                }
                "saveEpoch" => cache.on_save(|key, _, _| (key == format!("{KEY}#epoch")).then_some(Ok(false))),
                "purge" => cache.on_purge(|key, _| key.starts_with(&format!("{KEY}#")).then(unavailable)),
                "getGeneration" => cache.on_generation(|key| key.starts_with(&format!("{KEY}#")).then(unavailable)),
                "saveWithLease" => cache.on_save(|key, _, _| {
                    (key.starts_with(&format!("{KEY}#")) && !key.ends_with("#epoch")).then(unavailable)
                }),
                _ => {}
            }
            let calls = AtomicUsize::new(0);
            let value = cached(&database, &calls, async { Ok(Value::from("computed")) }).await.unwrap();
            assert_eq!(value, Value::from("computed"), "{failure}");
            assert_eq!(calls.load(Ordering::SeqCst), 1, "{failure}");
        });
    }
}

#[test]
fn a_cached_document_past_its_time_to_live_is_recomputed() {
    run(|| async {
        let adapter = crate::database::observed::Observed::memory();
        adapter.add_capability(crate::capability::Capability::IndexTtl);
        let database = reports(adapter, memory_cache(), None, None, false).await;
        database.create_index(COLLECTION, Index::ttl("expiry", "publishedAt", 1).unwrap()).await.unwrap();
        let now = crate::value::string(crate::datetime::now());
        database
            .create_document(
                COLLECTION,
                doc(vec![("$id", Value::from("q3")), ("title", Value::from("Q3")), ("publishedAt", now)]),
            )
            .await
            .unwrap();
        let calls = AtomicUsize::new(0);
        let find = || async { Ok(documents(database.find(COLLECTION, vec![], PermissionType::Read).await?)) };
        cached(&database, &calls, find()).await.unwrap();
        cached(&database, &calls, find()).await.unwrap();
        assert_eq!(calls.load(Ordering::SeqCst), 1);
        std::thread::sleep(std::time::Duration::from_secs(2));
        cached(&database, &calls, find()).await.unwrap();
        assert_eq!(calls.load(Ordering::SeqCst), 2, "a cached document past its time to live is not served");
    });
}
