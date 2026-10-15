//! Tests of the query cache, its invalidation and its owners.
//!
//! PHP: `Cache\QueryTest` and `Cache\OwnersTest`; the adapters PHP builds
//! these on (`RedisLeasableCache`, `OwnershipCache`, stubbed `Cache`s) are
//! played by [`Leasable`].

use std::sync::Arc;

use utopia_cache::Cache;

use super::testing::{Leasable, leasable, memory, over};
use super::{Entry, Invalidator, Owners, Query, Region, Scope, Target};
use crate::attribute::Attribute;
use crate::document::Document;
use crate::error::Kind;
use crate::event::Event;
use crate::relationship::{Relationship, RelationshipSide};
use crate::value::{Array, ArrayKey, Str, Value};

fn block_on<F: std::future::Future>(future: F) -> F::Output {
    tokio::runtime::Builder::new_current_thread().enable_all().build().expect("a runtime").block_on(future)
}

fn query_cache(cache: Arc<Cache>) -> Query {
    Query::new(cache, Query::SLOTS).unwrap()
}

fn doc(id: &str) -> Document {
    Document::from_pairs([("$id", Value::Str(Str::from(id)))]).unwrap()
}

fn ids(documents: Option<Vec<Document>>) -> Vec<String> {
    documents.unwrap_or_default().iter().map(|d| d.id().to_owned()).collect()
}

fn no_queries() -> Value {
    Value::Array(Array::new())
}

/// `[['limit' => n]]`.
fn limit(n: i64) -> Value {
    let inner: Array = [(ArrayKey::Str(Str::from("limit")), Value::Int(n))].into_iter().collect();
    Value::Array([(ArrayKey::Int(0), Value::Array(inner))].into_iter().collect())
}

fn scope_ns() -> Scope {
    Scope { namespace: "ns".into(), ..Scope::default() }
}

fn scope_named(name: &str) -> Scope {
    Scope { name: name.into(), ..Scope::default() }
}

fn scope_timeout(timeout: i64) -> Scope {
    Scope { namespace: "ns".into(), writer_timeout: timeout, ..Scope::default() }
}

async fn entry(q: &Query, scope: &Scope, collection: &str, queries: &Value, context: &str) -> Option<Entry> {
    q.entry(scope, collection, queries, context).await.unwrap()
}

async fn fill(q: &Query, entry: &Entry, id: &str) -> bool {
    let generation = q.generation(entry).await.unwrap();
    q.set(entry, &[doc(id)], &generation).await.unwrap()
}

/// `assertRetired()`.
async fn assert_retired<F: std::future::Future<Output = ()>>(q: &Query, scope: &Scope, collection: &str, action: F) {
    let before = entry(q, scope, collection, &no_queries(), "").await.expect("an entry before");
    assert!(fill(q, &before, "stale").await);
    assert_eq!(ids(q.get(&before).await.unwrap()), ["stale"]);
    action.await;
    let after = entry(q, scope, collection, &no_queries(), "").await.expect("the invalidation must leave it usable");
    assert_eq!(q.get(&after).await.unwrap(), None, "the invalidation must retire what '{collection}' filled");
    assert!(fill(q, &after, "fresh").await);
    assert_eq!(ids(q.get(&after).await.unwrap()), ["fresh"]);
}

/// `assertKept()`.
async fn assert_kept<F: std::future::Future<Output = ()>>(q: &Query, collections: &[&str], action: F) {
    for collection in collections {
        let before = entry(q, &Scope::default(), collection, &no_queries(), "").await.unwrap();
        assert!(fill(q, &before, "cached").await);
    }
    action.await;
    for collection in collections {
        let after = entry(q, &Scope::default(), collection, &no_queries(), "").await.expect("usable");
        assert_eq!(ids(q.get(&after).await.unwrap()), ["cached"], "'{collection}' must be kept");
    }
}

#[test]
fn keys_and_stored_entries_match_php() {
    block_on(async {
        let (adapter, cache) = leasable();
        let q = query_cache(cache);
        let scope = Scope {
            hostname: "host".into(),
            database: "db".into(),
            namespace: "ns".into(),
            tenant: Value::Int(7),
            name: "custom".into(),
            writer_timeout: 3600,
        };
        assert_eq!(q.collection_key(&scope, "Users"), "custom:qcache:Users:604c16bd6e320df13b85bd333d90d3b2");
        let string_tenant = Scope { tenant: Value::Str(Str::from("1")), ..Scope::default() };
        assert_eq!(q.collection_key(&string_tenant, "users"), "default:qcache:users:99182ca651cbeff20e8f6ef5e02cc0af");
        assert_eq!(
            q.collection_key(&Scope::default(), "users"),
            "default:qcache:users:971e90168d06981774b24c4d40887058"
        );

        let queries: Array = [
            (
                ArrayKey::Str(Str::from("a")),
                Value::Array([Value::Int(1), Value::Str(Str::from("x"))].into_iter().collect()),
            ),
            (ArrayKey::Str(Str::from("b")), Value::Null),
        ]
        .into_iter()
        .collect();
        let e = entry(&q, &Scope::default(), "users", &Value::Array(queries), "ctx").await.unwrap();
        assert_eq!(e.field, "b72f30def98ba5c4894033c34d09a999");
        assert_eq!(e.slot, "222");
        assert_eq!(e.epoch, "active:initial");

        let mut document = Document::from_pairs([
            ("$id", Value::Str(Str::from("d1"))),
            ("name", Value::Str(Str::from("A"))),
            ("n", Value::Array([Value::Int(1), Value::Int(2)].into_iter().collect())),
        ])
        .unwrap();
        document.put("rel", Value::Ext(crate::value::Ext::Document(Box::new(doc("r")))));
        assert!(q.set(&e, &[document], "0").await.unwrap());
        let stored = adapter.raw(&e.key, &e.slot).expect("stored");
        assert_eq!(
            String::from_utf8(php_std::serialize::serialize(&stored)).unwrap(),
            r#"a:4:{s:7:"version";i:2;s:5:"epoch";s:14:"active:initial";s:5:"field";s:32:"b72f30def98ba5c4894033c34d09a999";s:9:"documents";a:1:{i:0;a:4:{s:3:"$id";s:2:"d1";s:4:"name";s:1:"A";s:1:"n";a:2:{i:0;i:1;i:1;i:2;}s:3:"rel";a:1:{s:3:"$id";s:1:"r";}}}}"#
        );
    });
}

#[test]
fn constructor_needs_a_slot() {
    let error = Query::new(memory(), 0).unwrap_err();
    assert_eq!(error.kind(), Kind::InvalidArgument);
    assert_eq!(error.message(), "A query cache needs at least one slot");
}

#[test]
fn constructor_with_defaults() {
    block_on(async {
        let q = query_cache(memory());
        assert!(entry(&q, &Scope::default(), "any_collection", &no_queries(), "").await.is_some());
    });
}

#[test]
fn cache_names_keep_their_results_apart_on_the_same_cache() {
    block_on(async {
        let (adapter, cache) = leasable();
        let default = query_cache(cache);
        let custom = query_cache(over(&adapter));
        let e = entry(&custom, &scope_named("custom"), "users", &no_queries(), "").await.unwrap();
        assert!(fill(&custom, &e, "custom").await);
        let other = entry(&default, &Scope::default(), "users", &no_queries(), "").await.unwrap();
        assert_eq!(default.get(&other).await.unwrap(), None);
        default.invalidate_collection(&Scope::default(), "users").await.unwrap();
        let after = entry(&custom, &scope_named("custom"), "users", &no_queries(), "").await.unwrap();
        assert_eq!(ids(custom.get(&after).await.unwrap()), ["custom"]);
    });
}

#[test]
fn one_query_cache_keys_each_call_by_the_name_in_its_scope() {
    block_on(async {
        let (_, cache) = leasable();
        let q = query_cache(cache);
        let first = entry(&q, &scope_named("first"), "users", &no_queries(), "").await.unwrap();
        assert!(fill(&q, &first, "first").await);
        let second = entry(&q, &scope_named("second"), "users", &no_queries(), "").await.unwrap();
        assert_eq!(q.get(&second).await.unwrap(), None);
        q.invalidate_collection(&scope_named("second"), "users").await.unwrap();
        let kept = entry(&q, &scope_named("first"), "users", &no_queries(), "").await.unwrap();
        assert_eq!(ids(q.get(&kept).await.unwrap()), ["first"]);
    });
}

#[test]
fn the_writer_timeout_comes_from_the_scope_of_each_call() {
    block_on(async {
        let (_, cache) = leasable();
        let q = query_cache(cache);
        let key = q.collection_key(&Scope::default(), "users");
        q.block_collection(&key, &q.create_token()).await.unwrap();
        assert!(entry(&q, &Scope::default(), "users", &no_queries(), "").await.is_none());
        let zero = Scope { writer_timeout: 0, ..Scope::default() };
        assert!(entry(&q, &zero, "users", &no_queries(), "").await.is_some());
        assert!(entry(&q, &Scope::default(), "users", &no_queries(), "").await.is_none());
    });
}

#[test]
fn regions() {
    let q = query_cache(memory());
    let region = Region { ttl: 600, enabled: false };
    q.set_region("users", region);
    assert_eq!(q.region("users"), region);
    assert_eq!(q.region("unknown"), Region { ttl: 3600, enabled: true });
    assert_eq!(Region::default(), Region { ttl: 3600, enabled: true });
}

#[test]
fn entry_keys_are_stable_and_separate_scopes_queries_and_collections() {
    block_on(async {
        let q = query_cache(memory());
        let scope = Scope { namespace: "ns".into(), tenant: Value::Int(1), ..Scope::default() };
        let first = entry(&q, &scope, "users", &limit(1), "").await.unwrap();
        let second = entry(&q, &scope, "users", &limit(1), "").await.unwrap();
        assert_eq!(first.key, second.key);
        assert_eq!(first.field, second.field);
        let other = entry(&q, &scope, "users", &limit(2), "").await.unwrap();
        assert_ne!(first.field, other.field);
        let posts = entry(&q, &scope, "posts", &limit(1), "").await.unwrap();
        assert_ne!(first.key, posts.key);

        let base = |hostname: &str, database: &str, namespace: &str, tenant: Value| Scope {
            hostname: hostname.into(),
            database: database.into(),
            namespace: namespace.into(),
            tenant,
            ..Scope::default()
        };
        let key = q.collection_key(&base("host", "database", "namespace", Value::Int(1)), "users");
        for scope in [
            base("other", "database", "namespace", Value::Int(1)),
            base("host", "other", "namespace", Value::Int(1)),
            base("host", "database", "other", Value::Int(1)),
            base("host", "database", "namespace", Value::Int(2)),
            base("host", "database", "namespace", Value::Null),
            base("host", "database", "namespace", Value::Str(Str::from("1"))),
        ] {
            assert_ne!(key, q.collection_key(&scope, "users"));
        }
        assert_ne!(
            q.collection_key(&Scope::default(), "Users").to_lowercase(),
            q.collection_key(&Scope::default(), "users").to_lowercase()
        );
    });
}

#[test]
fn get_misses_on_an_empty_cache() {
    block_on(async {
        let q = query_cache(memory());
        let e = Entry { key: "some-key".into(), collection: "users".into(), ..Entry::default() };
        assert_eq!(q.get(&e).await.unwrap(), None);
    });
}

#[test]
fn filled_results_are_served_by_another_query_cache_on_the_same_cache() {
    block_on(async {
        let (adapter, cache) = leasable();
        let writer = query_cache(cache);
        let reader = query_cache(over(&adapter));
        let e = entry(&writer, &scope_ns(), "users", &limit(2), "").await.unwrap();
        let generation = writer.generation(&e).await.unwrap();
        let alice = Document::from_pairs([("$id", Value::from("doc1")), ("name", Value::from("Alice"))]).unwrap();
        let bob = Document::from_pairs([("$id", Value::from("doc2")), ("name", Value::from("Bob"))]).unwrap();
        assert!(writer.set(&e, &[alice, bob], &generation).await.unwrap());
        let served = entry(&reader, &scope_ns(), "users", &limit(2), "").await.unwrap();
        let result = reader.get(&served).await.unwrap().unwrap();
        assert_eq!(result.iter().map(|d| d.id().to_owned()).collect::<Vec<_>>(), ["doc1", "doc2"]);
        assert_eq!(result[1].get("name"), Some(&Value::from("Bob")));
    });
}

#[test]
fn a_fill_that_started_before_an_invalidation_is_rejected() {
    block_on(async {
        let (_, cache) = leasable();
        let q = query_cache(cache);
        let e = entry(&q, &scope_ns(), "users", &no_queries(), "").await.unwrap();
        let generation = q.generation(&e).await.unwrap();
        q.invalidate_collection(&scope_ns(), "users").await.unwrap();
        assert!(!q.set(&e, &[doc("stale")], &generation).await.unwrap());
        let fresh = entry(&q, &scope_ns(), "users", &no_queries(), "").await.unwrap();
        assert_eq!(q.get(&fresh).await.unwrap(), None);
    });
}

#[test]
fn entries_expire_with_the_region_but_epochs_never_do() {
    block_on(async {
        let (_, cache) = leasable();
        let q = query_cache(cache);
        q.set_region("users", Region { ttl: 0, enabled: true });
        q.invalidate_collection(&scope_ns(), "users").await.unwrap();
        let e = entry(&q, &scope_ns(), "users", &limit(10), "").await.expect("the published epoch stays usable");
        assert!(fill(&q, &e, "filled").await);
        assert_eq!(q.get(&e).await.unwrap(), None);
        q.set_region("users", Region { ttl: 60, enabled: true });
        assert_eq!(ids(q.get(&e).await.unwrap()), ["filled"]);
    });
}

#[test]
fn a_malformed_result_misses_and_is_replaced_by_the_next_fill() {
    block_on(async {
        for case in 0..3 {
            let (adapter, cache) = leasable();
            let q = query_cache(cache.clone());
            let e = entry(&q, &scope_ns(), "users", &no_queries(), "").await.unwrap();
            let assoc = |pairs: Vec<(&str, php_std::Value)>| {
                php_std::Value::Array(pairs.into_iter().map(|(k, v)| (ArrayKey::Str(Str::from(k)), v)).collect())
            };
            let payload = match case {
                0 => php_std::Value::from("not-an-array"),
                1 => assoc(vec![("foreign", php_std::Value::from("payload"))]),
                _ => assoc(vec![
                    ("version", php_std::Value::Int(2)),
                    ("epoch", php_std::Value::from(e.epoch.as_str())),
                    ("field", php_std::Value::from(e.field.as_str())),
                    ("documents", php_std::Value::Array([php_std::Value::from("invalid")].into_iter().collect())),
                ]),
            };
            cache.save(&e.key, &payload, &e.slot, 0).await.unwrap();
            assert_eq!(q.get(&e).await.unwrap(), None, "case {case}");
            assert!(fill(&q, &e, "fresh").await);
            assert_eq!(ids(q.get(&e).await.unwrap()), ["fresh"]);
            drop(adapter);
        }
    });
}

#[test]
fn a_malformed_payload_whose_purge_fails_is_reported() {
    block_on(async {
        let (adapter, cache) = leasable();
        let q = query_cache(cache.clone());
        let e = entry(&q, &scope_ns(), "users", &no_queries(), "").await.unwrap();
        cache.save(&e.key, &php_std::Value::from("not-an-array"), &e.slot, 0).await.unwrap();
        adapter.fail_field_purges();
        let error = q.get(&e).await.unwrap_err();
        assert_eq!(error.kind(), Kind::Runtime);
    });
}

#[test]
fn invalidate_collection_blocks_then_publishes_a_fresh_epoch() {
    block_on(async {
        let (_, cache) = leasable();
        let q = query_cache(cache);
        assert_retired(&q, &Scope::default(), "users", async {
            q.invalidate_collection(&Scope::default(), "users").await.unwrap();
        })
        .await;
    });
}

#[test]
fn an_invalid_epoch_is_a_miss() {
    block_on(async {
        let cache = memory();
        let q = query_cache(cache.clone());
        let epoch_key = format!("{}#epoch", q.collection_key(&scope_ns(), "users"));
        let value = php_std::Value::Array(
            [(ArrayKey::Str(Str::from("not")), php_std::Value::from("an epoch"))].into_iter().collect(),
        );
        cache.save(&epoch_key, &value, "", 0).await.unwrap();
        assert!(entry(&q, &scope_ns(), "users", &no_queries(), "").await.is_none());
    });
}

#[test]
fn entries_do_not_resolve_when_the_region_is_disabled() {
    block_on(async {
        let q = query_cache(memory());
        q.set_region("users", Region { ttl: 3600, enabled: false });
        assert!(entry(&q, &Scope::default(), "users", &no_queries(), "").await.is_none());
        assert!(entry(&q, &Scope::default(), "any", &no_queries(), "").await.is_some());
    });
}

#[test]
fn flush_drops_every_cached_result() {
    block_on(async {
        let (_, cache) = leasable();
        let q = query_cache(cache);
        let users = entry(&q, &scope_ns(), "users", &no_queries(), "").await.unwrap();
        let posts = entry(&q, &scope_ns(), "posts", &no_queries(), "").await.unwrap();
        assert!(fill(&q, &users, "user").await);
        assert!(fill(&q, &posts, "post").await);
        q.flush().await.unwrap();
        for collection in ["users", "posts"] {
            let e = entry(&q, &scope_ns(), collection, &no_queries(), "").await.expect("usable after a flush");
            assert_eq!(q.get(&e).await.unwrap(), None);
        }
    });
}

async fn assert_invalidator_invalidates(event: Event, target: Target, collections: &[&str]) {
    let (_, cache) = leasable();
    let q = Arc::new(query_cache(cache));
    let invalidator = Invalidator::new(q.clone());
    let mut befores = Vec::new();
    for collection in collections {
        let before = entry(&q, &Scope::default(), collection, &no_queries(), "").await.unwrap();
        assert!(fill(&q, &before, "stale").await);
        befores.push(before);
    }
    invalidator.handle(event, &[target]).await.unwrap();
    for collection in collections {
        let after = entry(&q, &Scope::default(), collection, &no_queries(), "").await.expect("usable");
        assert_eq!(q.get(&after).await.unwrap(), None, "'{collection}' must be retired");
        assert!(fill(&q, &after, "fresh").await);
        assert_eq!(ids(q.get(&after).await.unwrap()), ["fresh"]);
    }
}

fn written(id: &str, collection: &str) -> Target {
    Target::Document(
        Document::from_pairs([("$id", Value::from(id)), ("$collection", Value::from(collection))]).unwrap(),
    )
}

#[test]
fn invalidator_invalidates_on_document_writes() {
    block_on(async {
        assert_invalidator_invalidates(Event::DocumentCreate, written("doc1", "users"), &["users"]).await;
        assert_invalidator_invalidates(Event::DocumentUpdate, written("doc1", "posts"), &["posts"]).await;
        assert_invalidator_invalidates(Event::DocumentDelete, written("doc1", "users"), &["users"]).await;
        assert_invalidator_invalidates(Event::DocumentCreate, written("doc1", "orders"), &["orders"]).await;
        assert_invalidator_invalidates(Event::DocumentCreate, Target::Collection("products".into()), &["products"])
            .await;
    });
}

#[test]
fn invalidator_ignores_non_write_events_and_empty_collections() {
    block_on(async {
        let (_, cache) = leasable();
        let q = Arc::new(query_cache(cache));
        let invalidator = Invalidator::new(q.clone());
        assert_kept(&q, &["users"], async {
            invalidator.handle(Event::DocumentFind, &[written("doc1", "users")]).await.unwrap();
        })
        .await;
        assert!(!invalidator.is_mutation(Event::DocumentFind));
        assert!(invalidator.is_mutation(Event::PermissionsDelete));

        let (_, cache) = leasable();
        let q = Arc::new(query_cache(cache));
        let invalidator = Invalidator::new(q.clone());
        assert_kept(&q, &["users", ""], async {
            invalidator.handle(Event::DocumentCreate, &[Target::Document(doc("doc1"))]).await.unwrap();
        })
        .await;
    });
}

#[test]
fn invalidator_invalidates_both_relationship_collections() {
    block_on(async {
        let mut attribute =
            Attribute::relationship("author", Relationship::many_to_one("authors"), RelationshipSide::Parent)
                .unwrap()
                .to_document()
                .unwrap();
        attribute.put("$collection", Value::from("posts"));
        let target = Target::Document(attribute.clone());
        assert_invalidator_invalidates(Event::AttributeCreate, target, &["posts", "authors"]).await;

        // A written document's own options are data.
        let (_, cache) = leasable();
        let q = Arc::new(query_cache(cache));
        let tokens =
            Invalidator::new(q.clone()).tokens(Event::DocumentCreate, &[Target::Document(attribute)], None, false);
        assert_eq!(tokens.len(), 1);
        assert_eq!(tokens[0].0, q.collection_key(&Scope::default(), "posts"));
    });
}

#[test]
fn invalidator_uses_collection_identity_for_collection_mutations() {
    block_on(async {
        let target = written("users", crate::database::METADATA);
        assert_invalidator_invalidates(Event::CollectionUpdate, target, &["users"]).await;
    });
}

#[test]
fn invalidator_invalidates_the_scope_it_is_given() {
    block_on(async {
        let (_, cache) = leasable();
        let q = Arc::new(query_cache(cache));
        let scope = Scope {
            hostname: "host".into(),
            database: "database".into(),
            namespace: "namespace".into(),
            tenant: Value::Int(7),
            ..Scope::default()
        };
        let untouched = entry(&q, &Scope::default(), "users", &no_queries(), "").await.unwrap();
        assert!(fill(&q, &untouched, "untouched").await);
        let invalidator = Invalidator::new(q.clone());
        assert_retired(&q, &scope, "users", async {
            invalidator.invalidate(Event::DocumentCreate, &[Target::Collection("users".into())], &scope).await.unwrap();
        })
        .await;
        let other = entry(&q, &Scope::default(), "users", &no_queries(), "").await.unwrap();
        assert_eq!(ids(q.get(&other).await.unwrap()), ["untouched"]);

        let scope = Scope { namespace: "namespace".into(), tenant: Value::from("tenant"), ..Scope::default() };
        let invalidator = Invalidator::with_scope(q.clone(), scope.clone());
        assert_retired(&q, &scope, "users", async {
            invalidator.handle(Event::DocumentCreate, &[Target::Collection("users".into())]).await.unwrap();
        })
        .await;
    });
}

#[test]
fn invalidator_keys_tokens_by_the_scoped_collection() {
    let q = Arc::new(query_cache(memory()));
    let scope = Scope { namespace: "namespace".into(), tenant: Value::Int(7), ..Scope::default() };
    let tokens = Invalidator::new(q.clone()).tokens(
        Event::DocumentCreate,
        &[Target::Collection("users".into())],
        Some(&scope),
        false,
    );
    assert_eq!(tokens.iter().map(|(k, _)| k.clone()).collect::<Vec<_>>(), [q.collection_key(&scope, "users")]);
    let (time, hex) = tokens[0].1.split_once('.').unwrap();
    assert!(time.bytes().all(|b| b.is_ascii_digit()));
    assert_eq!(hex.len(), 32);
}

#[test]
fn invalidator_keys_each_document_under_its_tenant() {
    let q = Arc::new(query_cache(memory()));
    let scope = Scope { tenant: Value::Int(1), ..Scope::default() };
    let tenanted = |id: &str, tenant: Value| {
        Target::Document(
            Document::from_pairs([
                ("$id", Value::from(id)),
                ("$collection", Value::from("users")),
                ("$tenant", tenant),
            ])
            .unwrap(),
        )
    };
    let tokens = Invalidator::new(q.clone()).tokens(
        Event::DocumentsCreate,
        &[tenanted("a", Value::Int(2)), tenanted("b", Value::Null), tenanted("c", Value::Int(2))],
        Some(&scope),
        true,
    );
    let keys: Vec<String> = tokens.iter().map(|(k, _)| k.clone()).collect();
    assert_eq!(keys, [q.collection_key(&scope.with_tenant(Value::Int(2)), "users"), q.collection_key(&scope, "users")]);
}

#[test]
fn memory_adapter_keeps_physical_variants_isolated() {
    block_on(async {
        let q = query_cache(memory());
        let first = entry(&q, &scope_ns(), "users", &limit(1), "role:user-a").await.unwrap();
        let second = entry(&q, &scope_ns(), "users", &limit(2), "role:user-b").await.unwrap();
        assert!(fill(&q, &first, "private-a").await);
        assert!(fill(&q, &second, "private-b").await);
        assert_eq!(q.get(&first).await.unwrap(), None);
        assert_eq!(ids(q.get(&second).await.unwrap()), ["private-b"]);
        assert!(fill(&q, &first, "private-a").await);
        assert_eq!(ids(q.get(&first).await.unwrap()), ["private-a"]);
        assert_eq!(q.get(&second).await.unwrap(), None);
    });
}

#[test]
fn memory_adapter_stays_blocked_after_invalidation() {
    block_on(async {
        let q = query_cache(memory());
        let e = entry(&q, &scope_ns(), "users", &no_queries(), "").await.unwrap();
        assert!(fill(&q, &e, "old").await);
        assert_eq!(ids(q.get(&e).await.unwrap()), ["old"]);
        q.invalidate_collection(&scope_ns(), "users").await.unwrap();
        assert!(entry(&q, &scope_ns(), "users", &no_queries(), "").await.is_none());
    });
}

#[test]
fn a_tombstone_on_a_cache_without_generations_lapses_with_its_region() {
    block_on(async {
        let q = query_cache(memory());
        q.set_region("users", Region { ttl: 0, enabled: true });
        q.invalidate_collection(&Scope::default(), "users").await.unwrap();
        assert!(entry(&q, &Scope::default(), "users", &no_queries(), "").await.is_some());
    });
}

#[test]
fn a_killed_writer_does_not_disable_the_query_cache_forever() {
    block_on(async {
        let (_, cache) = leasable();
        let q = query_cache(cache);
        let scope = scope_timeout(0);
        let key = q.collection_key(&scope, "users");
        let before = entry(&q, &scope, "users", &no_queries(), "").await.unwrap();
        q.block_collection(&key, &q.create_token()).await.unwrap();
        assert!(fill(&q, &before, "stale").await);
        let e = entry(&q, &scope, "users", &no_queries(), "").await.expect("the tombstone lapses");
        assert_eq!(q.get(&e).await.unwrap(), None);
        assert!(fill(&q, &e, "fresh").await);
        assert_eq!(ids(q.get(&e).await.unwrap()), ["fresh"]);
    });
}

#[test]
fn a_write_after_a_killed_writer_reenables_the_query_cache() {
    block_on(async {
        let (adapter, cache) = leasable();
        let killed = query_cache(cache);
        let writer = query_cache(over(&adapter));
        let reader = query_cache(over(&adapter));
        killed.block_collection(&killed.collection_key(&scope_ns(), "users"), &killed.create_token()).await.unwrap();
        assert!(entry(&reader, &scope_ns(), "users", &no_queries(), "").await.is_none());
        writer.invalidate_collection(&scope_timeout(0), "users").await.unwrap();
        let e = entry(&reader, &scope_ns(), "users", &no_queries(), "").await.expect("reconciled");
        assert!(fill(&reader, &e, "fresh").await);
        assert_eq!(ids(reader.get(&e).await.unwrap()), ["fresh"]);
        writer.invalidate_collection(&scope_timeout(0), "users").await.unwrap();
        assert!(entry(&reader, &scope_ns(), "users", &no_queries(), "").await.is_some());
    });
}

#[test]
fn a_write_does_not_reenable_the_query_cache_while_another_writer_is_live() {
    block_on(async {
        let (adapter, cache) = leasable();
        let live = query_cache(cache);
        let writer = query_cache(over(&adapter));
        let key = live.collection_key(&scope_ns(), "users");
        let token = live.create_token();
        live.block_collection(&key, &token).await.unwrap();
        writer.invalidate_collection(&scope_ns(), "users").await.unwrap();
        assert!(entry(&writer, &scope_ns(), "users", &no_queries(), "").await.is_none());
        live.activate_collection(&key, &token, Scope::WRITER_TIMEOUT).await.unwrap();
        assert!(entry(&writer, &scope_ns(), "users", &no_queries(), "").await.is_some());
    });
}

#[test]
fn a_writer_whose_token_has_no_creation_time_counts_as_live() {
    block_on(async {
        let (adapter, cache) = leasable();
        let live = query_cache(cache);
        let writer = query_cache(over(&adapter));
        let reader = query_cache(over(&adapter));
        live.block_collection(&live.collection_key(&scope_ns(), "users"), "token-without-a-time").await.unwrap();
        writer.invalidate_collection(&scope_timeout(0), "users").await.unwrap();
        assert!(entry(&reader, &scope_ns(), "users", &no_queries(), "").await.is_none());
    });
}

#[test]
fn a_writer_past_the_timeout_retires_what_readers_filled_while_it_ran() {
    block_on(async {
        let (_, cache) = leasable();
        let q = query_cache(cache);
        let scope = scope_timeout(0);
        let key = q.collection_key(&scope, "users");
        let token = q.create_token();
        q.block_collection(&key, &token).await.unwrap();
        let during = entry(&q, &scope, "users", &no_queries(), "").await.unwrap();
        assert!(fill(&q, &during, "before-commit").await);
        q.activate_collection(&key, &token, 0).await.unwrap();
        let after = entry(&q, &scope, "users", &no_queries(), "").await.unwrap();
        assert_eq!(q.get(&after).await.unwrap(), None);
    });
}

#[test]
fn a_writer_judged_abandoned_still_retires_what_readers_filled_while_it_ran() {
    block_on(async {
        let (adapter, cache) = leasable();
        let slow = query_cache(cache);
        let writer = query_cache(over(&adapter));
        let key = slow.collection_key(&scope_ns(), "users");
        let token = slow.create_token();
        slow.block_collection(&key, &token).await.unwrap();
        writer.invalidate_collection(&scope_timeout(0), "users").await.unwrap();
        let during = entry(&slow, &scope_ns(), "users", &no_queries(), "").await.unwrap();
        assert!(fill(&slow, &during, "before-commit").await);
        slow.activate_collection(&key, &token, Scope::WRITER_TIMEOUT).await.unwrap();
        let after = entry(&slow, &scope_ns(), "users", &no_queries(), "").await.unwrap();
        assert_eq!(slow.get(&after).await.unwrap(), None);
    });
}

#[test]
fn fills_and_invalidations_keep_the_cache_size_bounded() {
    block_on(async {
        let slots = 4;
        let (adapter, cache) = leasable();
        let q = Query::new(cache, slots).unwrap();
        let cycle = |n: i64| {
            let q = &q;
            async move {
                let e = entry(q, &scope_ns(), "users", &limit(n), "").await.unwrap();
                assert_eq!(q.get(&e).await.unwrap(), None);
                assert!(fill(q, &e, &format!("query-{n}")).await);
                assert_eq!(ids(q.get(&e).await.unwrap()), [format!("query-{n}")]);
                q.invalidate_collection(&scope_ns(), "users").await.unwrap();
            }
        };
        for n in 1..=20 {
            cycle(n).await;
        }
        let keys = adapter.keys().len();
        let values = adapter.count_values();
        for n in 21..=100 {
            cycle(n).await;
        }
        assert!(adapter.keys().len() <= keys + slots as usize);
        assert!(adapter.count_values() <= values + slots as usize);
    });
}

#[test]
fn an_invalidation_retires_every_cached_result_of_the_scope() {
    block_on(async {
        let (_, cache) = leasable();
        let q = query_cache(cache);
        for n in 1..=50 {
            let e = entry(&q, &scope_ns(), "users", &limit(n), "").await.unwrap();
            assert!(fill(&q, &e, &format!("old-{n}")).await);
        }
        q.invalidate_collection(&scope_ns(), "users").await.unwrap();
        for n in 1..=50 {
            let e = entry(&q, &scope_ns(), "users", &limit(n), "").await.unwrap();
            assert_eq!(q.get(&e).await.unwrap(), None);
            assert!(fill(&q, &e, &format!("new-{n}")).await);
            assert_eq!(ids(q.get(&e).await.unwrap()), [format!("new-{n}")]);
        }
    });
}

#[test]
fn queries_sharing_a_slot_never_serve_each_other() {
    block_on(async {
        let (_, cache) = leasable();
        let q = Query::new(cache, 1).unwrap();
        let first = entry(&q, &scope_ns(), "users", &limit(1), "role:user-a").await.unwrap();
        let second = entry(&q, &scope_ns(), "users", &limit(2), "role:user-b").await.unwrap();
        assert!(fill(&q, &first, "private-a").await);
        assert!(fill(&q, &second, "private-b").await);
        assert_eq!(q.get(&first).await.unwrap(), None);
        assert_eq!(ids(q.get(&second).await.unwrap()), ["private-b"]);
    });
}

#[test]
fn queries_of_one_collection_keep_their_own_results_on_a_cache_with_fields() {
    block_on(async {
        let (_, cache) = leasable();
        let q = query_cache(cache);
        let first = entry(&q, &scope_ns(), "users", &limit(1), "role:user-a").await.unwrap();
        let second = entry(&q, &scope_ns(), "users", &limit(2), "role:user-b").await.unwrap();
        assert!(fill(&q, &first, "private-a").await);
        assert!(fill(&q, &second, "private-b").await);
        assert_eq!(ids(q.get(&first).await.unwrap()), ["private-a"]);
        assert_eq!(ids(q.get(&second).await.unwrap()), ["private-b"]);
    });
}

#[test]
fn a_result_filled_before_the_first_write_is_never_served_after_the_epoch_is_lost() {
    block_on(async {
        let (adapter, cache) = leasable();
        let q = query_cache(cache);
        let key = q.collection_key(&scope_ns(), "users");
        let before = entry(&q, &scope_ns(), "users", &no_queries(), "").await.unwrap();
        assert!(fill(&q, &before, "stale").await);
        q.invalidate_collection(&scope_ns(), "users").await.unwrap();
        adapter.evict(&format!("{key}#epoch"));
        adapter.evict(&format!("{key}#started"));
        if let Some(e) = entry(&q, &scope_ns(), "users", &no_queries(), "").await {
            assert_eq!(q.get(&e).await.unwrap(), None);
        }
    });
}

#[test]
fn a_fill_under_a_retired_epoch_is_never_served() {
    block_on(async {
        let (_, cache) = leasable();
        let q = query_cache(cache);
        let stale = entry(&q, &scope_ns(), "users", &no_queries(), "").await.unwrap();
        q.invalidate_collection(&scope_ns(), "users").await.unwrap();
        assert!(fill(&q, &stale, "stale").await);
        let fresh = entry(&q, &scope_ns(), "users", &no_queries(), "").await.unwrap();
        assert_eq!(stale.field, fresh.field);
        assert_eq!(q.get(&fresh).await.unwrap(), None);
    });
}

#[test]
fn overlapping_invalidations_succeed_on_a_cache_without_fields() {
    block_on(async {
        let q = query_cache(memory());
        let key = q.collection_key(&Scope::default(), "users");
        q.block_collection(&key, "first").await.unwrap();
        q.block_collection(&key, "second").await.unwrap();
        q.activate_collection(&key, "first", Scope::WRITER_TIMEOUT).await.unwrap();
        assert!(entry(&q, &Scope::default(), "users", &no_queries(), "").await.is_none());
        q.activate_collection(&key, "second", Scope::WRITER_TIMEOUT).await.unwrap();
        assert!(entry(&q, &Scope::default(), "users", &no_queries(), "").await.is_none());
    });
}

#[test]
fn activation_rejects_a_corrupted_owner_registration() {
    block_on(async {
        let (adapter, cache) = leasable();
        let q = query_cache(cache);
        let key = q.collection_key(&Scope::default(), "users");
        adapter.corrupt_field_writes(true);
        q.block_collection(&key, "owner").await.unwrap();
        let error = q.activate_collection(&key, "owner", Scope::WRITER_TIMEOUT).await.unwrap_err();
        assert_eq!(error.kind(), Kind::Runtime);
        assert!(error.message().contains("Invalid query cache owner"));
        assert!(entry(&q, &Scope::default(), "users", &no_queries(), "").await.is_none());
    });
}

#[test]
fn activation_propagates_an_owner_release_failure() {
    block_on(async {
        let (adapter, cache) = leasable();
        let q = query_cache(cache);
        let key = q.collection_key(&Scope::default(), "users");
        q.block_collection(&key, "owner").await.unwrap();
        adapter.fail_field_purges();
        let error = q.activate_collection(&key, "owner", Scope::WRITER_TIMEOUT).await.unwrap_err();
        assert!(error.message().contains("Failed to release query cache owner"));
    });
}

const OWNERS_KEY: &str = "default:qcache:users:scope";
const OWNERS_TTL: i64 = 3600;

#[test]
fn registering_and_finding_owners_lists_the_hash_once() {
    block_on(async {
        let (adapter, cache) = leasable();
        let tokens = ["first", "second", "third"];
        for token in tokens {
            assert!(Owners::new(cache.clone()).register(OWNERS_KEY, token).await.unwrap());
        }
        for token in tokens {
            let registration = Owners::new(cache.clone()).find(OWNERS_KEY, token).await.unwrap();
            assert_eq!(
                (registration.key.as_str(), registration.field.as_str()),
                (&*format!("{OWNERS_KEY}#owners"), token)
            );
            assert_eq!(
                cache.load(&registration.key, OWNERS_TTL, &registration.field).await.unwrap(),
                Some(php_std::Value::from(token))
            );
        }
        assert_eq!(adapter.lists(), 1, "only the first registration lists the owners hash");
        // Another cache object over the same adapter is asked on its own.
        assert!(!super::owners::kept(&over(&adapter)));
    });
}

#[test]
fn owners_resolve_on_a_cache_without_fields() {
    block_on(async {
        let cache = memory();
        for token in ["first", "second"] {
            assert!(Owners::new(cache.clone()).register(OWNERS_KEY, token).await.unwrap());
            let registration = Owners::new(cache.clone()).find(OWNERS_KEY, token).await.unwrap();
            assert_eq!(registration.key, format!("{OWNERS_KEY}#owner:{token}"));
            assert_eq!(registration.field, "");
            assert_eq!(
                cache.load(&registration.key, OWNERS_TTL, &registration.field).await.unwrap(),
                Some(php_std::Value::from(token))
            );
        }
        assert!(!super::owners::kept(&cache));
    });
}

#[test]
fn a_flush_before_the_first_list_does_not_pin_per_token_keys() {
    block_on(async {
        let (adapter, cache) = leasable();
        adapter.flush_on_next_list();
        assert!(Owners::new(cache.clone()).register(OWNERS_KEY, "flushed").await.unwrap());
        let flushed = Owners::new(cache.clone()).find(OWNERS_KEY, "flushed").await.unwrap();
        assert_eq!((flushed.key, flushed.field), (format!("{OWNERS_KEY}#owner:flushed"), String::new()));
        assert!(Owners::new(cache.clone()).register(OWNERS_KEY, "kept").await.unwrap());
        let kept = Owners::new(cache.clone()).find(OWNERS_KEY, "kept").await.unwrap();
        assert_eq!((kept.key, kept.field), (format!("{OWNERS_KEY}#owners"), "kept".to_owned()));
        let _: &Arc<Leasable> = &adapter;
    });
}

#[test]
fn flush_failure_is_reported() {
    // utopia-cache's adapters all flush; an adapter refusing it is covered by
    // the error message of `flush()` itself.
    block_on(async {
        let q = query_cache(Arc::new(Cache::from_dyn(Arc::new(utopia_cache::None))));
        match q.flush().await {
            Ok(()) => {}
            Err(e) => assert_eq!(e.message(), "Failed to flush query cache"),
        }
    });
}

// ---- The cases PHP runs on `OwnershipCache` and stubbed `Cache`s --------

/// PHP's `OwnershipCache`: one value per key, generations, no fields.
fn ownership() -> (Arc<Leasable>, Arc<Cache>) {
    let adapter = Leasable::plain_leased();
    let cache = over(&adapter);
    (adapter, cache)
}

#[test]
fn concurrent_owners_cannot_enable_the_cache_early() {
    block_on(async {
        for iteration in 0..10 {
            let (adapter, _) = ownership();
            let first = query_cache(over(&adapter));
            let second = Arc::new(query_cache(over(&adapter)));
            let reader = Arc::new(query_cache(over(&adapter)));
            let key = reader.collection_key(&scope_ns(), "users");
            let first_token = format!("first-{iteration}");
            let second_token = format!("second-{iteration}");

            let e = entry(&reader, &scope_ns(), "users", &no_queries(), "").await.unwrap();
            assert!(fill(&reader, &e, "old").await);
            assert_eq!(ids(query_cache(over(&adapter)).get(&e).await.unwrap()), ["old"]);

            first.block_collection(&key, &first_token).await.unwrap();
            let (paused_second, paused_reader, paused_key, paused_token) =
                (second.clone(), reader.clone(), key.clone(), second_token.clone());
            adapter.before_next_activation(async move {
                paused_second.block_collection(&paused_key, &paused_token).await.unwrap();
                assert!(entry(&paused_reader, &scope_ns(), "users", &no_queries(), "").await.is_none());
            });
            first.activate_collection(&key, &first_token, Scope::WRITER_TIMEOUT).await.unwrap();
            assert!(entry(&reader, &scope_ns(), "users", &no_queries(), "").await.is_none());

            second.activate_collection(&key, &second_token, Scope::WRITER_TIMEOUT).await.unwrap();
            let fresh = entry(&reader, &scope_ns(), "users", &no_queries(), "").await.expect("enabled");
            assert_eq!(reader.get(&fresh).await.unwrap(), None);
            assert!(fill(&reader, &fresh, "fresh").await);
            assert_eq!(ids(query_cache(over(&adapter)).get(&fresh).await.unwrap()), ["fresh"]);
        }
    });
}

#[test]
fn an_epoch_published_after_a_later_finish_stays_usable() {
    block_on(async {
        let (adapter, cache) = ownership();
        let q = Arc::new(query_cache(cache));
        let key = q.collection_key(&scope_ns(), "users");
        q.block_collection(&key, "first").await.unwrap();
        let (paused, paused_key) = (q.clone(), key.clone());
        adapter.before_next_activation(async move {
            paused.block_collection(&paused_key, "second").await.unwrap();
            paused.activate_collection(&paused_key, "second", Scope::WRITER_TIMEOUT).await.unwrap();
        });
        q.activate_collection(&key, "first", Scope::WRITER_TIMEOUT).await.unwrap();
        let e = entry(&q, &scope_ns(), "users", &no_queries(), "").await.expect("usable once every writer finished");
        assert!(fill(&q, &e, "fresh").await);
        assert_eq!(ids(q.get(&e).await.unwrap()), ["fresh"]);
    });
}

#[test]
fn a_tombstone_older_than_its_region_still_blocks_while_its_writer_is_in_flight() {
    block_on(async {
        let (_, cache) = ownership();
        let q = query_cache(cache);
        q.set_region("users", Region { ttl: 0, enabled: true });
        let key = q.collection_key(&Scope::default(), "users");
        let stale = entry(&q, &Scope::default(), "users", &no_queries(), "").await.unwrap();
        assert!(fill(&q, &stale, "stale").await);
        q.block_collection(&key, "writer").await.unwrap();
        assert!(entry(&q, &Scope::default(), "users", &no_queries(), "").await.is_none());
        q.activate_collection(&key, "writer", Scope::WRITER_TIMEOUT).await.unwrap();
        q.set_region("users", Region::default());
        let fresh = entry(&q, &Scope::default(), "users", &no_queries(), "").await.expect("a fresh epoch");
        assert_eq!(q.get(&fresh).await.unwrap(), None);
        assert!(fill(&q, &fresh, "fresh").await);
        assert_eq!(ids(q.get(&fresh).await.unwrap()), ["fresh"]);
    });
}

#[test]
fn a_cache_flush_during_or_before_activation_does_not_fail_the_invalidation() {
    block_on(async {
        for during in [true, false] {
            let (adapter, cache) = ownership();
            let q = query_cache(cache);
            let key = q.collection_key(&Scope::default(), "users");
            q.block_collection(&key, "owner").await.unwrap();
            if during {
                let weak = Arc::downgrade(&adapter);
                let armed = std::sync::atomic::AtomicBool::new(true);
                adapter.on_purge(move |key, _| {
                    (key.ends_with("#finished") && armed.swap(false, std::sync::atomic::Ordering::SeqCst)).then(|| {
                        weak.upgrade().unwrap().flush_now();
                        Ok(true)
                    })
                });
            } else {
                adapter.flush_now();
            }
            q.activate_collection(&key, "owner", Scope::WRITER_TIMEOUT).await.unwrap();
            assert!(entry(&q, &Scope::default(), "users", &no_queries(), "").await.is_some(), "during: {during}");
        }
    });
}

#[test]
fn an_activation_purge_failure_still_propagates() {
    block_on(async {
        let (adapter, cache) = ownership();
        let q = query_cache(cache);
        let key = q.collection_key(&Scope::default(), "users");
        q.block_collection(&key, "owner").await.unwrap();
        adapter.on_purge(|key, _| key.ends_with("#finished").then_some(Ok(false)));
        let error = q.activate_collection(&key, "owner", Scope::WRITER_TIMEOUT).await.unwrap_err();
        assert_eq!(error.kind(), Kind::Runtime);
        assert!(error.message().contains("finish query cache invalidation"), "{}", error.message());
        assert!(entry(&q, &Scope::default(), "users", &no_queries(), "").await.is_none());
    });
}

#[test]
fn an_invalidation_propagates_a_cache_write_failure() {
    block_on(async {
        let (adapter, cache) = leasable();
        let q = query_cache(cache);
        adapter.on_save(|_, hash, _| hash.is_empty().then_some(Ok(false)));
        let error = q.invalidate_collection(&Scope::default(), "users").await.unwrap_err();
        assert_eq!(error.kind(), Kind::Runtime);
        assert!(error.message().contains("Failed to block query cache epoch"), "{}", error.message());
    });
}

#[test]
fn a_flushed_writer_leaves_another_writers_tombstone_in_place() {
    block_on(async {
        let (adapter, cache) = ownership();
        let q = query_cache(cache);
        let key = q.collection_key(&scope_ns(), "users");
        q.block_collection(&key, "first").await.unwrap();
        adapter.flush_now();
        q.block_collection(&key, "second").await.unwrap();
        q.activate_collection(&key, "first", Scope::WRITER_TIMEOUT).await.unwrap();
        assert!(entry(&q, &scope_ns(), "users", &no_queries(), "").await.is_none());
        q.activate_collection(&key, "second", Scope::WRITER_TIMEOUT).await.unwrap();
        assert!(entry(&q, &scope_ns(), "users", &no_queries(), "").await.is_some());
    });
}

#[test]
fn an_owner_released_by_a_concurrent_flush_is_not_reported() {
    block_on(async {
        let (adapter, cache) = leasable();
        let q = query_cache(cache);
        let key = q.collection_key(&scope_ns(), "users");
        q.block_collection(&key, "owner").await.unwrap();
        let weak = Arc::downgrade(&adapter);
        let armed = std::sync::atomic::AtomicBool::new(true);
        adapter.on_purge(move |_, hash| {
            if !hash.is_empty() && armed.swap(false, std::sync::atomic::Ordering::SeqCst) {
                weak.upgrade().unwrap().flush_now();
            }
            None
        });
        q.activate_collection(&key, "owner", Scope::WRITER_TIMEOUT).await.unwrap();
        let e = entry(&q, &scope_ns(), "users", &no_queries(), "").await.expect("a fresh epoch");
        assert!(fill(&q, &e, "fresh").await);
        assert_eq!(ids(q.get(&e).await.unwrap()), ["fresh"]);
    });
}

#[test]
fn an_invalidation_propagates_an_owner_registration_failure() {
    block_on(async {
        let (adapter, cache) = leasable();
        let q = query_cache(cache);
        adapter.on_save(|_, hash, _| (!hash.is_empty()).then_some(Ok(false)));
        let before = entry(&q, &Scope::default(), "users", &no_queries(), "").await.unwrap();
        // The fill saves under a field too: let it through.
        adapter.on_save(|key, hash, _| (!hash.is_empty() && key.ends_with("#owners")).then_some(Ok(false)));
        assert!(fill(&q, &before, "cached").await);
        let error = q.invalidate_collection(&Scope::default(), "users").await.unwrap_err();
        assert!(error.message().contains("Failed to register query cache owner"), "{}", error.message());
        let after = entry(&q, &Scope::default(), "users", &no_queries(), "").await.expect("not blocked");
        assert_eq!(ids(q.get(&after).await.unwrap()), ["cached"]);
    });
}
