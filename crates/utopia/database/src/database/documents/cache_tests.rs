//! Tests of the document cache's writes, `withCache()`, the document purge
//! events, and `Trait\Databases`, on [`StubAdapter`].
//!
//! PHP: the parts of `WithCacheTest`, `Event\DocumentPurgeTest` and
//! `Documents\DocumentCacheEpochTest` that need no engine.

use std::sync::{Arc, Mutex};

use async_trait::async_trait;

use super::super::super::testing::{Call, Reply, StubAdapter, block_on};
use super::super::super::{Database, Hook};
use crate::cache::testing::{Leasable, leasable};
use crate::document::Document;
use crate::error::{Error, Kind, Result};
use crate::event::{Domain, Event};
use crate::hook::{Lifecycle, Named};
use crate::value::{Array, ArrayKey, Ext, Str, Value};

/// A lifecycle hook recording the events it receives, with the tenant the
/// database had then; failing when `fail` is set.
struct Recorder {
    name: Option<String>,
    database: Mutex<Option<Database>>,
    events: Mutex<Vec<(Event, String, Value)>>,
    fail: bool,
}

impl Recorder {
    fn new(database: &Database, name: Option<&str>, fail: bool) -> Arc<Self> {
        let recorder = Arc::new(Recorder {
            name: name.map(str::to_owned),
            database: Mutex::new(Some(database.clone())),
            events: Mutex::new(Vec::new()),
            fail,
        });
        database.add_hook(Hook::lifecycle(recorder.clone())).unwrap();
        recorder
    }

    fn purged(&self) -> Vec<String> {
        self.events
            .lock()
            .unwrap()
            .iter()
            .filter(|(e, _, _)| *e == Event::DocumentPurge)
            .map(|(_, p, _)| p.clone())
            .collect()
    }

    fn tenants(&self) -> Vec<Value> {
        self.events.lock().unwrap().iter().map(|(_, _, t)| t.clone()).collect()
    }
}

impl Named for Recorder {
    fn name(&self) -> &str {
        self.name.as_deref().unwrap_or("")
    }
}

#[async_trait]
impl Lifecycle for Recorder {
    async fn handle(&self, event: &Domain) -> Result<()> {
        let tenant = self.database.lock().unwrap().as_ref().map_or(Value::Null, Database::tenant);
        let path = match event {
            Domain::DocumentPurged { collection, id } => format!("{collection}/{id}"),
            _ => String::new(),
        };
        self.events.lock().unwrap().push((event.event(), path, tenant));
        if self.fail {
            return Err(Error::new(Kind::Runtime, "listener failed"));
        }
        Ok(())
    }

    fn named(&self) -> Option<&dyn Named> {
        self.name.as_ref().map(|_| self as &dyn Named)
    }
}

/// A database over a stub adapter, caching in a [`Leasable`] cache.
fn database() -> (Database, Arc<StubAdapter>, Arc<Leasable>) {
    let adapter = StubAdapter::new();
    let (cache_adapter, cache) = leasable();
    let database = Database::new(adapter.clone(), cache);
    database.authorization().add_role("any");
    (database, adapter, cache_adapter)
}

fn assoc(pairs: Vec<(&str, Value)>) -> Value {
    Value::Array(pairs.into_iter().map(|(k, v)| (ArrayKey::Str(Str::from(k)), v)).collect())
}

fn document(id: &str, collection: Option<&str>) -> Document {
    let mut pairs = vec![("$id", Value::from(id))];
    if let Some(c) = collection {
        pairs.push(("$collection", Value::from(c)));
    }
    Document::from_pairs(pairs).unwrap()
}

/// Runs `with_cache` counting the callback's calls.
async fn cached(db: &Database, key: &str, value: Value, hash: Option<&str>, calls: &Mutex<usize>) -> Value {
    db.with_cache(
        key,
        || async {
            *calls.lock().unwrap() += 1;
            Ok(value)
        },
        hash,
    )
    .await
    .unwrap()
}

#[test]
fn with_cache_uses_the_callback_on_a_miss_and_caches_the_result() {
    block_on(async {
        let (db, _, _) = database();
        let calls = Mutex::new(0);
        let fresh = assoc(vec![("value", Value::from("fresh"))]);
        assert_eq!(cached(&db, "key", fresh.clone(), Some(""), &calls).await, fresh);
        let other = assoc(vec![("value", Value::from("new"))]);
        assert_eq!(cached(&db, "key", other, Some(""), &calls).await, fresh);
        assert_eq!(*calls.lock().unwrap(), 1);
    });
}

#[test]
fn with_cache_caches_empty_and_null_values() {
    block_on(async {
        let (db, _, _) = database();
        let calls = Mutex::new(0);
        let empty = Value::Array(Array::new());
        assert_eq!(cached(&db, "key", empty.clone(), Some(""), &calls).await, empty);
        assert_eq!(cached(&db, "key", Value::from("miss"), Some(""), &calls).await, empty);
        assert_eq!(*calls.lock().unwrap(), 1);

        let (db, _, _) = database();
        let calls = Mutex::new(0);
        assert_eq!(cached(&db, "key", Value::Null, Some(""), &calls).await, Value::Null);
        assert_eq!(cached(&db, "key", Value::from("miss"), Some(""), &calls).await, Value::Null);
        assert_eq!(*calls.lock().unwrap(), 1);
    });
}

#[test]
fn with_cache_separates_payloads_by_hash_field() {
    block_on(async {
        let (db, _, cache) = database();
        let first_calls = Mutex::new(0);
        let second_calls = Mutex::new(0);
        let first = assoc(vec![("value", Value::from("first"))]);
        let second = assoc(vec![("value", Value::from("second"))]);
        assert_eq!(cached(&db, "key", first.clone(), Some("first-field"), &first_calls).await, first);
        assert_eq!(cached(&db, "key", second.clone(), Some("second-field"), &second_calls).await, second);
        let miss = assoc(vec![("value", Value::from("miss"))]);
        assert_eq!(cached(&db, "key", miss, Some("first-field"), &first_calls).await, first);
        assert_eq!(*first_calls.lock().unwrap(), 1);
        assert_eq!(*second_calls.lock().unwrap(), 1);
        assert_eq!(cache.keys().len(), 3);
    });
}

#[test]
fn with_cache_does_not_cache_false_values() {
    block_on(async {
        let (db, _, cache) = database();
        let calls = Mutex::new(0);
        assert_eq!(cached(&db, "key", Value::Bool(false), Some(""), &calls).await, Value::Bool(false));
        assert_eq!(cache.keys(), ["key#epoch"]);
        assert_eq!(cached(&db, "key", Value::from("fresh"), Some(""), &calls).await, Value::from("fresh"));
        assert_eq!(*calls.lock().unwrap(), 2);
    });
}

#[test]
fn with_cache_bypasses_the_cache_for_a_null_hash() {
    block_on(async {
        let (db, _, cache) = database();
        let calls = Mutex::new(0);
        assert_eq!(cached(&db, "key", Value::from("first"), None, &calls).await, Value::from("first"));
        assert_eq!(cached(&db, "key", Value::from("second"), None, &calls).await, Value::from("second"));
        assert_eq!(*calls.lock().unwrap(), 2);
        assert!(cache.keys().is_empty());
    });
}

#[test]
fn with_cache_does_not_cache_collectionless_documents_or_mixed_arrays() {
    block_on(async {
        let (db, _, cache) = database();
        let calls = Mutex::new(0);
        let first = Value::Ext(Ext::Document(Box::new(document("first", None))));
        let second = Value::Ext(Ext::Document(Box::new(document("second", None))));
        assert_eq!(cached(&db, "key", first.clone(), Some(""), &calls).await, first);
        assert_eq!(cached(&db, "key", second.clone(), Some(""), &calls).await, second);
        assert_eq!(*calls.lock().unwrap(), 2);

        let calls = Mutex::new(0);
        let rule = Value::Ext(Ext::Document(Box::new(document("rule-a", Some("wafRules")))));
        let mixed = Value::Array([Value::from("prefix"), rule].into_iter().collect());
        assert_eq!(cached(&db, "mixed", mixed.clone(), Some(""), &calls).await, mixed);
        assert_eq!(cached(&db, "mixed", mixed.clone(), Some(""), &calls).await, mixed);
        assert_eq!(*calls.lock().unwrap(), 2);
        assert_eq!(cache.keys(), ["key#epoch", "mixed#epoch"]);
    });
}

#[test]
fn with_cache_runs_uncached_inside_a_transaction() {
    block_on(async {
        let (db, _, cache) = database();
        let calls = Mutex::new(0);
        db.with_transaction(|| async {
            cached(&db, "key", Value::from("a"), Some(""), &calls).await;
            cached(&db, "key", Value::from("b"), Some(""), &calls).await;
            Ok(())
        })
        .await
        .unwrap();
        assert_eq!(*calls.lock().unwrap(), 2);
        assert!(cache.keys().is_empty());
    });
}

#[test]
fn purge_cached_queries_retires_what_with_cache_filled() {
    block_on(async {
        let (db, _, _) = database();
        let key = db.query_cache_key("users", None).await.unwrap();
        let calls = Mutex::new(0);
        cached(&db, &key, Value::from("old"), Some("h"), &calls).await;
        assert_eq!(cached(&db, &key, Value::from("new"), Some("h"), &calls).await, Value::from("old"));
        assert!(db.purge_cached_queries("users", None).await.unwrap());
        assert_eq!(cached(&db, &key, Value::from("new"), Some("h"), &calls).await, Value::from("new"));
        assert_eq!(*calls.lock().unwrap(), 2);
    });
}

#[test]
fn purge_cached_document_purges_the_document_and_fires_the_event() {
    block_on(async {
        let (db, _, cache) = database();
        let recorder = Recorder::new(&db, None, false);
        let (_, document_key) = db.cache_base_keys("posts", Some("first")).await.unwrap();
        db.cache().save(&document_key, &php_std::Value::from("cached"), "field", 0).await.unwrap();
        db.purge_cached_document("posts", "first").await.unwrap();
        assert_eq!(recorder.purged(), ["posts/first"]);
        assert!(cache.keys().iter().all(|k| *k != document_key.to_lowercase() || cache.raw(k, "field").is_none()));
        assert_eq!(db.cache().load(&document_key, 60, "field").await.unwrap(), None);
    });
}

#[test]
fn a_document_purge_hook_failure_reaches_the_caller() {
    block_on(async {
        let (db, _, _) = database();
        Recorder::new(&db, None, true);
        let error = db.purge_cached_document("posts", "first").await.unwrap_err();
        assert_eq!(error.message(), "listener failed");
        let error = db.queue_document_purge("posts", "first").await.unwrap_err();
        assert_eq!(error.message(), "listener failed");
    });
}

#[test]
fn purge_events_of_writes_without_an_adapter_transaction_fire_at_once() {
    block_on(async {
        let (db, _, _) = database();
        let recorder = Recorder::new(&db, None, false);
        db.queue_document_purge("posts", "first").await.unwrap();
        assert_eq!(recorder.purged(), ["posts/first"]);
    });
}

#[test]
fn purge_events_inside_a_transaction_fire_after_the_commit() {
    block_on(async {
        let (db, _, _) = database();
        let recorder = Recorder::new(&db, None, false);
        db.with_transaction(|| async {
            db.queue_document_purge("posts", "first").await?;
            assert!(recorder.purged().is_empty(), "nothing fires before the commit");
            Ok(())
        })
        .await
        .unwrap();
        assert_eq!(recorder.purged(), ["posts/first"]);
    });
}

#[test]
fn purge_events_are_dropped_when_the_transaction_rolls_back() {
    block_on(async {
        let (db, _, _) = database();
        let recorder = Recorder::new(&db, None, false);
        let result: Result<()> = db
            .with_transaction(|| async {
                db.queue_document_purge("posts", "first").await?;
                Err(Error::new(Kind::Runtime, "rolled back"))
            })
            .await;
        assert_eq!(result.unwrap_err().message(), "rolled back");
        assert!(recorder.purged().is_empty());
    });
}

#[test]
fn queued_purge_events_keep_their_tenant_and_silences() {
    block_on(async {
        let (db, _, _) = database();
        let recorder = Recorder::new(&db, None, false);
        let audits = Recorder::new(&db, Some("audits"), false);
        db.set_tenant(Value::Int(1));
        db.with_transaction(|| async {
            db.with_tenant(Value::Int(2), db.queue_document_purge("posts", "first")).await?;
            db.silent_hooks(&["audits"], db.queue_document_purge("posts", "second")).await?;
            Ok(())
        })
        .await
        .unwrap();
        assert_eq!(recorder.purged(), ["posts/first", "posts/second"]);
        assert_eq!(recorder.tenants(), [Value::Int(2), Value::Int(1)]);
        assert_eq!(audits.purged(), ["posts/first"], "a silenced hook stays silenced after the commit");
    });
}

#[test]
fn every_queued_purge_event_is_delivered_when_a_listener_fails() {
    block_on(async {
        let (db, _, _) = database();
        let failing = Recorder::new(&db, None, true);
        let result: Result<()> = db
            .with_transaction(|| async {
                db.queue_document_purge("posts", "first").await?;
                db.queue_document_purge("posts", "second").await?;
                Ok(())
            })
            .await;
        let error = result.unwrap_err();
        assert_eq!(error.message(), "listener failed");
        assert!(db.failed_after_commit(&error));
        assert_eq!(failing.purged(), ["posts/first", "posts/second"]);
    });
}

#[test]
fn a_write_outside_a_scope_publishes_a_fresh_document_cache_epoch() {
    block_on(async {
        let (db, _, _) = database();
        let (collection_key, _) = db.cache_base_keys("posts", None).await.unwrap();
        let before = db.load_document_cache_state(&collection_key).await.value.expect("an epoch");
        db.advance_collection_cache_epoch("posts", "first").await.unwrap();
        let after = db.load_document_cache_state(&collection_key).await.value.expect("a fresh epoch");
        assert_ne!(before, after);
        assert!(after.starts_with("active:"));
    });
}

#[test]
fn a_write_in_a_transaction_keeps_the_document_cache_blocked_until_it_ends() {
    block_on(async {
        let (db, _, _) = database();
        let (collection_key, _) = db.cache_base_keys("posts", None).await.unwrap();
        let before = db.load_document_cache_state(&collection_key).await.value.expect("an epoch");
        db.with_transaction(|| async {
            db.advance_collection_cache_epoch("posts", "first").await?;
            let state = db.load_document_cache_state(&collection_key).await;
            assert_eq!(state.value, None, "the collection stays blocked while the transaction runs");
            assert!(state.blocked_at.is_some());
            // A second write of the scope keeps the first tombstone.
            db.advance_collection_cache_epoch("posts", "second").await?;
            Ok(())
        })
        .await
        .unwrap();
        let after = db.load_document_cache_state(&collection_key).await.value.expect("a fresh epoch");
        assert_ne!(before, after);
    });
}

#[test]
fn a_document_written_in_a_transaction_is_purged_again_once_it_ends() {
    block_on(async {
        let (db, _, _) = database();
        let (_, document_key) = db.cache_base_keys("posts", Some("first")).await.unwrap();
        let cache = db.cache();
        db.with_transaction(|| async {
            db.purge_cached_document_internal("posts", Some("first")).await?;
            assert!(!db.is_cached_in_transaction(&document_key), "a written document is not served from the cache");
            // A reader outside the transaction fills the pre-commit row.
            cache.save(&document_key, &php_std::Value::from("stale"), "field", 0).await?;
            Ok(())
        })
        .await
        .unwrap();
        assert_eq!(cache.load(&document_key, 60, "field").await.unwrap(), None);
    });
}

#[test]
fn purge_cached_collection_retires_the_collection_epoch() {
    block_on(async {
        let (db, _, _) = database();
        let (collection_key, _) = db.cache_base_keys("posts", None).await.unwrap();
        let before = db.load_document_cache_state(&collection_key).await.value.unwrap();
        db.purge_cached_collection("posts").await.unwrap();
        let after = db.load_document_cache_state(&collection_key).await.value.unwrap();
        assert_ne!(before, after);
    });
}

#[test]
fn encoded_cache_values_match_php() {
    let rule = document("rule-a", Some("wafRules"));
    let single = super::encode_cache_value(&Value::Ext(Ext::Document(Box::new(rule.clone())))).unwrap();
    assert_eq!(
        String::from_utf8(php_std::serialize::serialize(&single)).unwrap(),
        r#"a:3:{s:10:"collection";s:8:"wafRules";s:4:"type";s:8:"document";s:5:"value";a:2:{s:3:"$id";s:6:"rule-a";s:11:"$collection";s:8:"wafRules";}}"#
    );
    let list = Value::Array([Value::Ext(Ext::Document(Box::new(rule)))].into_iter().collect());
    let many = super::encode_cache_value(&list).unwrap();
    assert!(String::from_utf8(php_std::serialize::serialize(&many)).unwrap().contains(r#"s:4:"type";s:9:"documents""#));
    let nested = Value::Array(
        [Value::Array([Value::Ext(Ext::Document(Box::new(document("x", Some("c")))))].into_iter().collect())]
            .into_iter()
            .collect(),
    );
    assert_eq!(super::encode_cache_value(&nested), None);
}

// ---- Databases ---------------------------------------------------------

#[test]
fn exists_and_collection_exists_default_to_the_current_database() {
    block_on(async {
        let (db, adapter, _) = database();
        db.set_database("main");
        adapter.on(|call| match call {
            Call::Exists { database } => Some(Reply::Bool(database == "main")),
            Call::CollectionExists { database, collection } => {
                Some(Reply::Bool(database == "other" && collection == "posts"))
            }
            _ => None,
        });
        assert!(db.exists(None).await.unwrap());
        assert!(!db.exists(Some("other")).await.unwrap());
        assert!(!db.collection_exists("posts", None).await.unwrap());
        assert!(db.collection_exists("posts", Some("other")).await.unwrap());
    });
}

#[test]
fn list_returns_the_databases_and_fires_the_event() {
    block_on(async {
        let (db, adapter, _) = database();
        let recorder = Recorder::new(&db, None, false);
        adapter.on(|call| matches!(call, Call::List).then(|| Reply::Documents(vec![document("main", None)])));
        let databases = db.list().await.unwrap();
        assert_eq!(databases.iter().map(|d| d.id().to_owned()).collect::<Vec<_>>(), ["main"]);
        assert_eq!(recorder.events.lock().unwrap()[0].0, Event::DatabaseList);
    });
}

#[test]
fn delete_flushes_the_cache_and_fires_the_event() {
    block_on(async {
        let (db, adapter, cache) = database();
        let recorder = Recorder::new(&db, None, false);
        db.set_database("main");
        db.cache().save("anything", &php_std::Value::from("x"), "", 0).await.unwrap();
        assert!(db.delete(None).await.unwrap());
        assert!(cache.keys().is_empty());
        assert!(adapter.calls().iter().any(|c| matches!(c, Call::Delete { name } if name == "main")));
        assert_eq!(recorder.events.lock().unwrap()[0].0, Event::DatabaseDelete);

        adapter.on(|call| matches!(call, Call::Delete { .. }).then_some(Reply::Bool(false)));
        let error = db.delete(Some("main")).await.unwrap_err();
        assert_eq!(error.kind(), Kind::Refused);
        assert_eq!(error.message(), "Failed to delete database");
    });
}

#[test]
fn create_is_refused_when_the_adapter_does_not_create_it() {
    block_on(async {
        let (db, adapter, _) = database();
        adapter.on(|call| matches!(call, Call::Create { .. }).then_some(Reply::Bool(false)));
        let error = db.create(Some("main")).await.unwrap_err();
        assert_eq!(error.kind(), Kind::Refused);
        assert_eq!(error.message(), "Failed to create database");
    });
}

#[test]
fn update_renames_the_current_database() {
    block_on(async {
        let (db, adapter, _) = database();
        let recorder = Recorder::new(&db, None, false);
        db.set_database("old");
        assert!(db.update("old", "new").await.unwrap());
        assert_eq!(db.database(), "new");
        assert!(
            adapter.calls().iter().any(|c| matches!(c, Call::Update { name, new } if name == "old" && new == "new"))
        );
        assert_eq!(recorder.events.lock().unwrap().last().unwrap().0, Event::DatabaseUpdate);

        // Another database is renamed without changing the current one.
        assert!(db.update("other", "renamed").await.unwrap());
        assert_eq!(db.database(), "new");

        adapter.on(|call| matches!(call, Call::Update { .. }).then_some(Reply::Bool(false)));
        let error = db.update("new", "newer").await.unwrap_err();
        assert_eq!(error.kind(), Kind::Refused);
        assert_eq!(error.message(), "Failed to rename database 'new' to 'newer'");

        db.set_shared_tables(true);
        let error = db.update("new", "newer").await.unwrap_err();
        assert_eq!(error.kind(), Kind::Database);
        assert_eq!(error.message(), "Cannot rename a database while shared tables are enabled");
    });
}
