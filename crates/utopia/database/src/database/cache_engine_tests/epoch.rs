//! PHP: `Documents\DocumentCacheEpochTest` (its Memory cases) and
//! `Documents\DocumentCacheActivationTest`.

use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::{Arc, Mutex, Weak};

use crate::adapter::Adapter;
use crate::cache::Query as QueryCache;
use crate::cache::testing::{Leasable, over};
use crate::database::fixtures::run;
use crate::database::fixtures::{collection, doc, string, uncached_twin, unique, with_id};
use crate::database::observed::{Observed, Op};
use crate::database::{Database, METADATA, TTL};
use crate::error::Result;
use crate::permission::Permission;
use crate::query::Query;
use crate::role::Role;
use crate::value::Value;

fn permissions() -> Vec<String> {
    let any = Role::any();
    vec![Permission::read(&any), Permission::create(&any), Permission::update(&any)]
}

/// `createDatabaseWithCache()`.
async fn with_cache(cache: &Arc<Leasable>, namespace: Option<&str>, adapter: Option<Arc<dyn Adapter>>) -> Database {
    let adapter = adapter.unwrap_or_else(|| Observed::memory());
    let database = Database::new(adapter, over(cache));
    database.set_database("utopiaTests").set_namespace(&namespace.map_or_else(|| unique("epoch_"), str::to_owned));
    database.create(None).await.unwrap();
    database.authorization().add_role(&Role::any().to_string());
    database
        .create_collection(collection("webhooks", vec![string("name")], vec![], Some(permissions()), true))
        .await
        .unwrap();
    database
}

/// `createCountedDatabase()`: with `hook` named `original`, read once.
async fn counted(cache: &Arc<Leasable>) -> (Database, Arc<Observed>) {
    let adapter = Observed::memory();
    let database = with_cache(cache, None, Some(adapter.clone())).await;
    create_hook(&database).await;
    (database, adapter)
}

async fn create_hook(database: &Database) {
    database
        .create_document("webhooks", doc(vec![("$id", Value::from("hook")), ("name", Value::from("original"))]))
        .await
        .unwrap();
    assert_eq!(name(database, "webhooks", "hook").await, Value::from("original"));
}

async fn name(database: &Database, collection: &str, id: &str) -> Value {
    database.get_document(collection, id, vec![], false).await.unwrap().get("name").cloned().unwrap_or(Value::Null)
}

/// `renameDocument()`: a batch update of one document.
async fn rename(database: &Database, collection: &str, id: &str, name: &str) -> Result<i64> {
    database
        .update_documents(
            collection,
            doc(vec![("name", Value::from(name))]),
            vec![Query::equal("$id", [id])],
            100,
            None,
        )
        .await
}

async fn change(database: &Database, collection: &str, id: &str) {
    uncached_twin(database).update_document(collection, id, doc(vec![("name", Value::from("changed"))])).await.unwrap();
}

fn document_reads(adapter: &Observed) -> usize {
    adapter.count(|op| matches!(op, Op::GetDocument { collection, .. } if collection != METADATA))
}

/// `FailDocumentEpochMemory`: a Memory cache whose tombstones or epoch
/// activations can be refused.
struct FailEpochs {
    blocks: AtomicBool,
    activations: Mutex<Option<String>>,
}

fn fail_epochs() -> (Arc<Leasable>, Arc<FailEpochs>) {
    let cache = Leasable::plain();
    let fails = Arc::new(FailEpochs { blocks: AtomicBool::new(false), activations: Mutex::new(None) });
    let state = fails.clone();
    cache.on_save(move |key, _, data| {
        let php_std::Value::Str(data) = data else { return None };
        if !key.ends_with("#epoch") {
            return None;
        }
        let blocked = data.as_bytes().starts_with(b"blocked:");
        if blocked && state.blocks.load(Ordering::SeqCst) {
            return Some(Ok(false));
        }
        match state.activations.lock().unwrap().as_deref() {
            Some(fragment) if !blocked && key.contains(fragment) => Some(Ok(false)),
            _ => None,
        }
    });
    (cache, fails)
}

#[test]
fn purge_cached_collection_retires_its_cached_documents() {
    run(|| async {
        let database = with_cache(&Leasable::new(), None, None).await;
        create_hook(&database).await;
        change(&database, "webhooks", "hook").await;
        assert_eq!(name(&database, "webhooks", "hook").await, Value::from("original"));
        database.purge_cached_collection("webhooks").await.unwrap();
        assert_eq!(name(&database, "webhooks", "hook").await, Value::from("changed"));
    });
}

#[test]
fn a_block_failure_rolls_back_the_mutation() {
    run(|| async {
        let (cache, fails) = fail_epochs();
        let database = with_cache(&cache, None, None).await;
        create_hook(&database).await;
        fails.blocks.store(true, Ordering::SeqCst);
        let error = rename(&database, "webhooks", "hook", "updated").await.unwrap_err();
        assert!(error.message().contains("block document cache epoch"), "{}", error.message());
        assert_eq!(name(&database, "webhooks", "hook").await, Value::from("original"));
    });
}

#[test]
fn an_activation_failure_after_commit_leaves_the_epoch_blocked() {
    run(|| async {
        let (cache, fails) = fail_epochs();
        let database = with_cache(&cache, None, None).await;
        create_hook(&database).await;
        *fails.activations.lock().unwrap() = Some("#epoch".into());
        let error = rename(&database, "webhooks", "hook", "updated").await.unwrap_err();
        assert!(error.message().contains("activate document cache epoch"), "{}", error.message());
        assert_eq!(name(&database, "webhooks", "hook").await, Value::from("updated"));
        change(&database, "webhooks", "hook").await;
        assert_eq!(name(&database, "webhooks", "hook").await, Value::from("changed"), "it stays uncached");
    });
}

#[test]
fn an_activation_failure_does_not_strand_other_collection_epochs() {
    run(|| async {
        let (cache, fails) = fail_epochs();
        let adapter = Observed::memory();
        let database = with_cache(&cache, None, Some(adapter.clone())).await;
        database
            .create_collection(collection("logs", vec![string("name")], vec![], Some(permissions()), true))
            .await
            .unwrap();
        create_hook(&database).await;
        database
            .create_document("logs", doc(vec![("$id", Value::from("log")), ("name", Value::from("original"))]))
            .await
            .unwrap();
        assert_eq!(name(&database, "logs", "log").await, Value::from("original"));
        *fails.activations.lock().unwrap() = Some("collection:webhooks#epoch".into());
        let error = database
            .with_transaction(|| async {
                rename(&database, "webhooks", "hook", "updated").await?;
                rename(&database, "logs", "log", "updated").await?;
                Ok(())
            })
            .await
            .unwrap_err();
        assert!(error.message().contains("activate document cache epoch"), "{}", error.message());
        assert_eq!(name(&database, "webhooks", "hook").await, Value::from("updated"));
        change(&database, "webhooks", "hook").await;
        assert_eq!(name(&database, "webhooks", "hook").await, Value::from("changed"));
        assert_eq!(name(&database, "logs", "log").await, Value::from("updated"));
        adapter.reset();
        assert_eq!(name(&database, "logs", "log").await, Value::from("updated"));
        assert_eq!(document_reads(&adapter), 0, "the other collection is cached again");
    });
}

/// `FlushDuringActivationMemory`.
fn flush_during_activation() -> Arc<Leasable> {
    Leasable::plain_leased()
}

#[test]
fn a_cache_flush_during_activation_does_not_fail_the_committed_mutation() {
    run(|| async {
        let cache = flush_during_activation();
        let database = with_cache(&cache, None, None).await;
        create_hook(&database).await;
        cache.flush_now();
        let weak: Weak<Leasable> = Arc::downgrade(&cache);
        let armed = AtomicBool::new(true);
        cache.on_purge(move |key, _| {
            (key.ends_with("#finished") && armed.swap(false, Ordering::SeqCst)).then(|| {
                weak.upgrade().unwrap().flush_now();
                Ok(true)
            })
        });
        assert_eq!(rename(&database, "webhooks", "hook", "updated").await.unwrap(), 1);
        assert_eq!(name(&database, "webhooks", "hook").await, Value::from("updated"));
    });
}

#[test]
fn a_cache_flush_after_activation_reads_generations_does_not_fail_the_committed_mutation() {
    run(|| async {
        let cache = flush_during_activation();
        let database = with_cache(&cache, None, None).await;
        cache.flush_now();
        database.purge_cached_collection("webhooks").await.unwrap();
        let weak: Weak<Leasable> = Arc::downgrade(&cache);
        let armed = AtomicBool::new(true);
        cache.on_generation(move |key| {
            (key.ends_with("collection:webhooks#finished") && armed.swap(false, Ordering::SeqCst)).then(|| {
                let cache = weak.upgrade().unwrap();
                let generation = cache.generation_now(key);
                cache.flush_now();
                Ok(generation)
            })
        });
        database.delete_collection("webhooks").await.unwrap();
        assert!(database.find_collection("webhooks").await.unwrap().is_none());
    });
}

#[test]
fn an_activation_purge_failure_still_propagates() {
    run(|| async {
        let cache = flush_during_activation();
        let database = with_cache(&cache, None, None).await;
        database
            .create_document("webhooks", doc(vec![("$id", Value::from("hook")), ("name", Value::from("original"))]))
            .await
            .unwrap();
        cache.flush_now();
        cache.on_purge(|key, _| key.ends_with("#finished").then_some(Ok(false)));
        let error = rename(&database, "webhooks", "hook", "updated").await.unwrap_err();
        assert!(error.message().contains("finish document cache invalidation"), "{}", error.message());
        assert_eq!(name(&database, "webhooks", "hook").await, Value::from("updated"));
    });
}

#[test]
fn writes_do_not_add_keys_to_a_cache_that_keeps_purged_keys() {
    run(|| async {
        let cache = Leasable::new();
        let database = with_cache(&cache, None, None).await;
        database.set_query_cache(Some(Arc::new(QueryCache::new(over(&cache), QueryCache::SLOTS).unwrap())));
        for index in 0..=20 {
            database
                .create_document(
                    "webhooks",
                    doc(vec![
                        ("$id", Value::from(format!("hook{index}").as_str())),
                        ("name", Value::from(format!("hook {index}").as_str())),
                    ]),
                )
                .await
                .unwrap();
        }
        let read_every_hook = || async {
            for index in 0..=20 {
                assert!(
                    !database
                        .get_document("webhooks", &format!("hook{index}"), vec![], false)
                        .await
                        .unwrap()
                        .is_empty()
                );
            }
        };
        read_every_hook().await;
        let mut keys = 0;
        for round in 1..=3 {
            let updated = format!("updated {round}");
            database
                .with_transaction(|| async {
                    database
                        .update_document("webhooks", "hook1", doc(vec![("name", Value::from(updated.as_str()))]))
                        .await?;
                    database
                        .update_document("webhooks", "hook2", doc(vec![("name", Value::from(updated.as_str()))]))
                        .await?;
                    Ok(())
                })
                .await
                .unwrap();
            rename(&database, "webhooks", "hook3", &updated).await.unwrap();
            read_every_hook().await;
            if round == 1 {
                keys = cache.keys().len();
            }
        }
        assert_eq!(keys, cache.keys().len(), "writes and the reads between them leave no keys of their own");
        assert_eq!(name(&database, "webhooks", "hook1").await, Value::from("updated 3"));
    });
}

#[test]
fn overlapping_writes_succeed_on_a_cache_without_fields() {
    run(|| async {
        let (cache, fails) = fail_epochs();
        let writer = with_cache(&cache, None, None).await;
        let other = with_cache(&cache, Some(&writer.namespace()), None).await;
        for database in [&writer, &other] {
            database
                .create_document("webhooks", doc(vec![("$id", Value::from("hook")), ("name", Value::from("original"))]))
                .await
                .unwrap();
        }
        writer
            .with_transaction(|| async {
                rename(&writer, "webhooks", "hook", "updated").await?;
                fails.blocks.store(true, Ordering::SeqCst);
                let error = rename(&other, "webhooks", "hook", "updated").await.unwrap_err();
                fails.blocks.store(false, Ordering::SeqCst);
                assert!(error.message().contains("block document cache epoch"), "{}", error.message());
                Ok(())
            })
            .await
            .unwrap();
        assert_eq!(name(&writer, "webhooks", "hook").await, Value::from("updated"));
    });
}

#[test]
fn activation_rejects_a_corrupted_owner_registration() {
    run(|| async {
        let cache = Leasable::new();
        let database = with_cache(&cache, None, None).await;
        database
            .create_document("webhooks", doc(vec![("$id", Value::from("hook")), ("name", Value::from("original"))]))
            .await
            .unwrap();
        cache.corrupt_field_writes(true);
        let error = rename(&database, "webhooks", "hook", "updated").await.unwrap_err();
        assert!(error.message().contains("Invalid document cache owner"), "{}", error.message());
        cache.corrupt_field_writes(false);
        assert_eq!(name(&database, "webhooks", "hook").await, Value::from("updated"));
        change(&database, "webhooks", "hook").await;
        assert_eq!(name(&database, "webhooks", "hook").await, Value::from("changed"));
    });
}

#[test]
fn activation_propagates_an_owner_release_failure() {
    run(|| async {
        let cache = Leasable::new();
        let database = with_cache(&cache, None, None).await;
        database
            .create_document("webhooks", doc(vec![("$id", Value::from("hook")), ("name", Value::from("original"))]))
            .await
            .unwrap();
        cache.fail_field_purges();
        let error = rename(&database, "webhooks", "hook", "updated").await.unwrap_err();
        assert!(error.message().contains("Failed to release document cache owner"), "{}", error.message());
        assert_eq!(name(&database, "webhooks", "hook").await, Value::from("updated"));
        change(&database, "webhooks", "hook").await;
        assert_eq!(name(&database, "webhooks", "hook").await, Value::from("changed"));
    });
}

#[test]
fn a_lost_activation_does_not_keep_the_collection_uncached() {
    run(|| async {
        let (cache, fails) = fail_epochs();
        let (database, adapter) = counted(&cache).await;
        *fails.activations.lock().unwrap() = Some("#epoch".into());
        let error = rename(&database, "webhooks", "hook", "updated").await.unwrap_err();
        assert!(error.message().contains("activate document cache epoch"), "{}", error.message());
        assert_eq!(name(&database, "webhooks", "hook").await, Value::from("updated"));
        change(&database, "webhooks", "hook").await;
        assert_eq!(name(&database, "webhooks", "hook").await, Value::from("changed"));
        database.set_cache_writer_timeout(0);
        assert_eq!(name(&database, "webhooks", "hook").await, Value::from("changed"));
        adapter.reset();
        for _ in 0..3 {
            assert_eq!(name(&database, "webhooks", "hook").await, Value::from("changed"));
        }
        assert_eq!(document_reads(&adapter), 0, "past the writer timeout the lost activation lapses");
    });
}

/// `AbandoningCache`: refuses the next release of an owner registration.
fn abandoning() -> (Arc<Leasable>, Arc<AtomicBool>) {
    let cache = Leasable::new();
    let abandoning = Arc::new(AtomicBool::new(false));
    let flag = abandoning.clone();
    cache.on_purge(move |key, hash| {
        (!hash.is_empty() && key.ends_with("#owners") && flag.swap(false, Ordering::SeqCst)).then_some(Ok(false))
    });
    (cache, abandoning)
}

#[test]
fn a_write_after_an_abandoned_write_reenables_the_collection() {
    run(|| async {
        let (cache, abandon) = abandoning();
        let (database, adapter) = counted(&cache).await;
        abandon.store(true, Ordering::SeqCst);
        let error = rename(&database, "webhooks", "hook", "abandoned").await.unwrap_err();
        assert!(error.message().contains("release document cache owner"), "{}", error.message());
        rename(&database, "webhooks", "hook", "second").await.unwrap();
        assert_eq!(name(&database, "webhooks", "hook").await, Value::from("second"));
        change(&database, "webhooks", "hook").await;
        assert_eq!(name(&database, "webhooks", "hook").await, Value::from("changed"));
        database.set_cache_writer_timeout(0);
        rename(&database, "webhooks", "hook", "third").await.unwrap();
        database.set_cache_writer_timeout(3600);
        assert_eq!(name(&database, "webhooks", "hook").await, Value::from("third"));
        adapter.reset();
        assert_eq!(name(&database, "webhooks", "hook").await, Value::from("third"));
        assert_eq!(document_reads(&adapter), 0, "the first write past the writer timeout re-enables the collection");
    });
}

#[test]
fn a_transaction_reads_its_batch_write_after_another_reader_refilled_the_definition() {
    run(|| async {
        let cache = Leasable::plain();
        let database = with_cache(&cache, None, None).await;
        create_hook(&database).await;
        let definition_key = database.cache_base_keys(METADATA, Some("webhooks")).await.unwrap().1.to_lowercase();
        let definition = database.cache().load(&definition_key, TTL, "").await.unwrap();
        let Some(definition @ php_std::Value::Array(_)) = definition else {
            panic!("no cached definition under '{definition_key}'");
        };
        let read = database
            .with_transaction(|| async {
                rename(&database, "webhooks", "hook", "updated").await?;
                database.cache().save(&definition_key, &definition, "", 0).await?;
                Ok(name(&database, "webhooks", "hook").await)
            })
            .await
            .unwrap();
        assert_eq!(read, Value::from("updated"));
    });
}

#[test]
fn a_definition_filled_across_a_write_on_a_cache_without_generations_is_dropped() {
    run(|| async {
        let cache = Leasable::plain();
        let database = with_cache(&cache, None, None).await;
        create_hook(&database).await;
        database.purge_cached_document(METADATA, "webhooks").await.unwrap();
        let writer = database.clone();
        cache.before_next_save(":_metadata:webhooks", async move {
            rename(&writer, "webhooks", "hook", "updated").await.unwrap();
        });
        database.get_collection("webhooks").await.unwrap();
        assert_eq!(name(&database, "webhooks", "hook").await, Value::from("updated"));
    });
}

#[test]
fn a_registration_released_just_before_its_own_release_finishes_the_write() {
    run(|| async {
        let cache = Leasable::new();
        let weak: Weak<Leasable> = Arc::downgrade(&cache);
        let racing = AtomicBool::new(false);
        let (database, adapter) = counted(&cache).await;
        racing.store(true, Ordering::SeqCst);
        cache.on_purge(move |key, hash| {
            if !hash.is_empty() && key.ends_with("#owners") && racing.swap(false, Ordering::SeqCst) {
                weak.upgrade().unwrap().purge_now(key, hash);
            }
            None
        });
        assert_eq!(rename(&database, "webhooks", "hook", "updated").await.unwrap(), 1);
        assert_eq!(name(&database, "webhooks", "hook").await, Value::from("updated"));
        adapter.reset();
        assert_eq!(name(&database, "webhooks", "hook").await, Value::from("updated"));
        assert_eq!(document_reads(&adapter), 0, "a registration another worker released is not a failed release");
    });
}

// ---- DocumentCacheActivationTest ---------------------------------------

async fn activation_database(adapter: Arc<Observed>, cache: Arc<utopia_cache::Cache>, namespace: &str) -> Database {
    let database = Database::new(adapter, cache);
    database.set_database("activation").set_namespace(namespace);
    database.create(None).await.unwrap();
    database.authorization().add_role(&Role::any().to_string());
    database
        .create_collection(collection(
            "webhooks",
            vec![crate::Attribute::string("name", 32)],
            vec![],
            Some(permissions()),
            true,
        ))
        .await
        .unwrap();
    database
        .create_document("webhooks", doc(vec![("$id", Value::from("hook")), ("name", Value::from("original"))]))
        .await
        .unwrap();
    database
}

#[test]
fn a_writer_activating_while_another_is_in_flight_leaves_its_barrier_in_place() {
    for flush in [false, true] {
        run(move || async move {
            let adapter = if flush { Leasable::lenient() } else { Leasable::plain() };
            let cache = over(&adapter);
            let namespace = unique("activation_");
            let first_adapter = Observed::memory();
            let first = activation_database(first_adapter.clone(), cache.clone(), &namespace).await;
            cache.flush().await.unwrap();
            let second = activation_database(Observed::memory(), cache.clone(), &namespace).await;
            cache.flush().await.unwrap();

            second
                .with_transaction(|| async {
                    first
                        .with_transaction(|| async {
                            first
                                .update_documents(
                                    "webhooks",
                                    doc(vec![("name", Value::from("first"))]),
                                    vec![],
                                    100,
                                    None,
                                )
                                .await?;
                            if flush {
                                cache.flush().await?;
                            }
                            second
                                .update_documents(
                                    "webhooks",
                                    doc(vec![("name", Value::from("second"))]),
                                    vec![],
                                    100,
                                    None,
                                )
                                .await?;
                            Ok(())
                        })
                        .await?;
                    assert_eq!(name(&first, "webhooks", "hook").await, Value::from("first"));
                    uncached_twin(&first)
                        .update_document("webhooks", "hook", doc(vec![("name", Value::from("changed"))]))
                        .await?;
                    assert_eq!(
                        name(&first, "webhooks", "hook").await,
                        Value::from("changed"),
                        "no read is cached meanwhile"
                    );
                    Ok(())
                })
                .await
                .unwrap();

            assert_eq!(name(&first, "webhooks", "hook").await, Value::from("changed"));
            first_adapter.reset();
            assert_eq!(name(&first, "webhooks", "hook").await, Value::from("changed"));
            assert_eq!(
                document_reads(&first_adapter),
                0,
                "caching resumes once no writer is in flight (flush: {flush})"
            );
        });
    }
}
