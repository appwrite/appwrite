//! PHP: `Documents\TransactionDefinitionReadTest` (its Memory cases).

use std::future::Future;
use std::sync::Arc;
use std::sync::atomic::{AtomicBool, Ordering};

use async_trait::async_trait;

use super::{after_definition_read, none_cache};
use crate::cache::testing::{Leasable, over};
use crate::collection::Update;
use crate::database::fixtures::{collection, doc, run, unique};
use crate::database::observed::{Observed, Op};
use crate::database::{Database, Hook, METADATA};
use crate::document::Document;
use crate::error::{Error, Kind, Result};
use crate::event::{Domain, Event};
use crate::hook::Lifecycle;
use crate::permission::Permission;
use crate::role::Role;
use crate::value::Value;

const COLLECTION: &str = "accounts";
const LEDGERS: &str = "ledgers";

fn metadata_reads(adapter: &Observed) -> usize {
    adapter.count(Op::is_metadata_read)
}

/// `createAccounts()`.
async fn create_accounts(database: &Database) {
    let any = Role::any();
    database
        .create_collection(collection(
            COLLECTION,
            vec![crate::Attribute::integer("balance")],
            vec![],
            Some(vec![Permission::create(&any), Permission::read(&any), Permission::update(&any)]),
            false,
        ))
        .await
        .unwrap();
    database
        .create_document(COLLECTION, doc(vec![("$id", Value::from("ada")), ("balance", Value::Int(1))]))
        .await
        .unwrap();
}

/// `createLedgers()`.
async fn create_ledgers(database: &Database) {
    database
        .create_collection(collection(
            LEDGERS,
            vec![crate::Attribute::integer("balance")],
            vec![],
            Some(vec![Permission::read(&Role::any())]),
            false,
        ))
        .await
        .unwrap();
}

/// `database()`.
async fn database(adapter: Arc<Observed>, cache: Option<Arc<utopia_cache::Cache>>) -> Database {
    let database = Database::new(adapter, cache.unwrap_or_else(|| over(&Leasable::new())));
    database.set_database("transactions").set_namespace(&unique("transactions_"));
    database.authorization().add_role(&Role::any().to_string());
    database.create(None).await.unwrap();
    create_accounts(&database).await;
    database.get_document(COLLECTION, "ada", vec![], false).await.unwrap();
    database
}

async fn balance(database: &Database) -> Value {
    database
        .get_document(COLLECTION, "ada", vec![], false)
        .await
        .unwrap()
        .get("balance")
        .cloned()
        .unwrap_or(Value::Null)
}

async fn update(database: &Database, balance: i64) -> Result<Document> {
    database.update_document(COLLECTION, "ada", doc(vec![("balance", Value::Int(balance))])).await
}

/// `readsOf()`.
async fn reads_of<F: Future>(adapter: &Observed, future: F) -> usize {
    adapter.reset();
    future.await;
    metadata_reads(adapter)
}

async fn purge(database: &Database, id: &str) {
    database.purge_cached_document(METADATA, id).await.unwrap();
}

fn documents_security() -> Update {
    Update {
        permissions: Some(vec![Permission::read(&Role::any()), Permission::update(&Role::any())]),
        document_security: Some(true),
    }
}

#[test]
fn a_transaction_reads_an_unchanged_definition_once() {
    run(|| async {
        let adapter = Observed::memory();
        let database = database(adapter.clone(), None).await;
        purge(&database, COLLECTION).await;
        let reads = database
            .with_transaction(|| async {
                adapter.reset();
                update(&database, 2).await?;
                database.get_document(COLLECTION, "ada", vec![], false).await?;
                database.get_collection(COLLECTION).await?;
                Ok(metadata_reads(&adapter))
            })
            .await
            .unwrap();
        assert_eq!(reads, 1);
        assert_eq!(balance(&database).await, Value::Int(2));
    });
}

#[test]
fn a_definition_a_transaction_read_is_cached_for_the_next_transaction() {
    run(|| async {
        let adapter = Observed::memory();
        let database = database(adapter.clone(), None).await;
        purge(&database, COLLECTION).await;
        database.with_transaction(|| update(&database, 2)).await.unwrap();
        let reads = database
            .with_transaction(|| async {
                adapter.reset();
                update(&database, 3).await?;
                database.get_document(COLLECTION, "ada", vec![], false).await?;
                Ok(metadata_reads(&adapter))
            })
            .await
            .unwrap();
        assert_eq!(reads, 0);
        assert_eq!(balance(&database).await, Value::Int(3));
    });
}

#[test]
fn a_definition_changed_after_a_transaction_read_it_is_read_afresh() {
    run(|| async {
        let adapter = Observed::memory();
        let database = database(adapter.clone(), None).await;
        purge(&database, COLLECTION).await;
        database.with_transaction(|| database.get_collection(COLLECTION)).await.unwrap();
        assert_eq!(reads_of(&adapter, database.with_transaction(|| database.get_collection(COLLECTION))).await, 0);
        database.update_collection(COLLECTION, documents_security()).await.unwrap();
        let read = database.with_transaction(|| database.get_collection(COLLECTION)).await.unwrap();
        assert!(read.document_security());
    });
}

#[test]
fn a_transaction_without_a_usable_cache_reads_its_definition_once() {
    run(|| async {
        let adapter = Observed::memory();
        let database = database(adapter.clone(), Some(none_cache())).await;
        assert_eq!(reads_of(&adapter, database.with_transaction(|| update(&database, 2))).await, 1);
        assert_eq!(reads_of(&adapter, database.with_transaction(|| update(&database, 3))).await, 1);
        adapter.reset();
        update(&database, 4).await.unwrap();
        database.get_collection(COLLECTION).await.unwrap();
        assert_eq!(metadata_reads(&adapter), 2, "outside a transaction every call reads the definition again");
        assert_eq!(balance(&database).await, Value::Int(4));
    });
}

#[test]
fn a_cache_that_fails_definition_reads_adds_no_definition_read() {
    run(|| async {
        let cache = Leasable::plain();
        let metadata = format!(":{METADATA}:");
        let fragment = metadata.clone();
        cache.on_load(move |key, _| key.contains(&fragment).then(unreachable));
        cache.on_save(move |key, _, _| key.contains(&metadata).then(unreachable));
        let adapter = Observed::memory();
        let database = database(adapter.clone(), Some(over(&cache))).await;
        assert_eq!(reads_of(&adapter, database.with_transaction(|| update(&database, 2))).await, 1);
        assert_eq!(balance(&database).await, Value::Int(2));
    });
}

#[test]
fn a_cache_that_refused_a_definition_is_filled_by_transactions_once_it_accepts_again() {
    run(|| async {
        let cache = Leasable::plain();
        let refusing = Arc::new(AtomicBool::new(false));
        let flag = refusing.clone();
        let metadata = format!(":{METADATA}:");
        cache.on_save(move |key, _, _| (flag.load(Ordering::SeqCst) && key.contains(&metadata)).then_some(Ok(false)));
        let adapter = Observed::memory();
        let database = database(adapter.clone(), Some(over(&cache))).await;
        refusing.store(true, Ordering::SeqCst);
        purge(&database, COLLECTION).await;
        assert_eq!(reads_of(&adapter, database.with_transaction(|| update(&database, 2))).await, 2);
        assert_eq!(reads_of(&adapter, database.with_transaction(|| update(&database, 2))).await, 1);
        assert_eq!(reads_of(&adapter, database.with_transaction(|| update(&database, 2))).await, 1);
        refusing.store(false, Ordering::SeqCst);
        database.get_collection(COLLECTION).await.unwrap();
        purge(&database, COLLECTION).await;
        assert_eq!(reads_of(&adapter, database.with_transaction(|| update(&database, 2))).await, 2);
        assert_eq!(reads_of(&adapter, database.with_transaction(|| update(&database, 2))).await, 0);
    });
}

#[test]
fn a_fill_refused_in_its_grace_window_leaves_transactions_filling_other_definitions() {
    run(|| async {
        let cache = Leasable::new();
        let adapter = Observed::memory();
        let database = database(adapter.clone(), Some(over(&cache))).await;
        create_ledgers(&database).await;
        cache.set_lease_grace_window(60_000);
        purge(&database, COLLECTION).await;
        database.get_collection(COLLECTION).await.unwrap();
        purge(&database, LEDGERS).await;
        cache.expire_tombstones();
        let read = || database.get_collection(LEDGERS);
        assert_eq!(
            reads_of(&adapter, database.with_transaction(read)).await,
            2,
            "the refused fill stopped the refills"
        );
        assert_eq!(reads_of(&adapter, database.with_transaction(read)).await, 0);
    });
}

#[test]
fn a_refill_refused_by_the_commits_own_purge_leaves_later_transactions_filling_theirs() {
    run(|| async {
        let cache = Leasable::new();
        let adapter = Observed::memory();
        let database = database(adapter.clone(), Some(over(&cache))).await;
        create_ledgers(&database).await;
        cache.set_lease_grace_window(60_000);
        purge(&database, COLLECTION).await;
        purge(&database, LEDGERS).await;
        cache.expire_tombstones();
        database
            .with_transaction(|| {
                database.update_documents(COLLECTION, doc(vec![("balance", Value::Int(2))]), vec![], 100, None)
            })
            .await
            .unwrap();
        cache.expire_tombstones();
        let read = || database.get_collection(LEDGERS);
        assert_eq!(reads_of(&adapter, database.with_transaction(read)).await, 2);
        assert_eq!(reads_of(&adapter, database.with_transaction(read)).await, 0);
        assert_eq!(balance(&database).await, Value::Int(2));
    });
}

#[test]
fn a_transaction_under_another_tenant_caches_that_tenants_definition() {
    run(|| async {
        let adapter = Observed::memory();
        let database = Database::new(adapter.clone(), over(&Leasable::new()));
        database.set_database("transactions").set_namespace(&unique("transactions_"));
        database.set_shared_tables(true).set_tenant(Value::Int(1));
        database.authorization().add_role(&Role::any().to_string());
        database.create(None).await.unwrap();
        for tenant in [1, 2] {
            database
                .with_tenant(Value::Int(tenant), async {
                    create_accounts(&database).await;
                    database.get_collection(COLLECTION).await.unwrap();
                })
                .await;
        }
        database.with_tenant(Value::Int(2), purge(&database, COLLECTION)).await;
        let write = || database.with_tenant(Value::Int(2), update(&database, 2));
        assert_eq!(reads_of(&adapter, database.with_transaction(write)).await, 2);
        assert_eq!(reads_of(&adapter, database.with_transaction(write)).await, 0);
    });
}

async fn raw_definition(database: &Database) -> Result<Document> {
    database.skip_filters(None::<&[&str]>, database.get_document(METADATA, COLLECTION, vec![], false)).await
}

#[test]
fn a_transaction_reading_a_raw_definition_caches_the_raw_definition() {
    run(|| async {
        let adapter = Observed::memory();
        let database = database(adapter.clone(), None).await;
        purge(&database, COLLECTION).await;
        let read = || raw_definition(&database);
        assert_eq!(reads_of(&adapter, database.with_transaction(read)).await, 2);
        assert_eq!(reads_of(&adapter, database.with_transaction(read)).await, 0);
        let raw = database.with_transaction(read).await.unwrap();
        assert!(matches!(raw.get("attributes"), Some(Value::Str(_))));
        let decoded = database.get_document(METADATA, COLLECTION, vec![], false).await.unwrap();
        assert!(matches!(decoded.get("attributes"), Some(Value::Array(_))));
    });
}

/// `FailingLifecycle`.
struct Failing {
    event: Event,
    failure: Error,
}

#[async_trait]
impl Lifecycle for Failing {
    async fn handle(&self, event: &Domain) -> Result<()> {
        if event.event() == self.event {
            return Err(self.failure.clone());
        }
        Ok(())
    }
}

#[test]
fn a_transaction_whose_invalidation_failed_leaves_its_definition_read_uncached() {
    run(|| async {
        let adapter = Observed::memory();
        let database = database(adapter.clone(), None).await;
        purge(&database, COLLECTION).await;
        let failure = Error::new(Kind::TypeError, "purge listener failed");
        database
            .add_hook(Hook::lifecycle(Arc::new(Failing { event: Event::DocumentPurge, failure: failure.clone() })))
            .unwrap();
        let error = database.with_transaction(|| update(&database, 2)).await.unwrap_err();
        assert_eq!(error.kind(), Kind::TypeError);
        assert_eq!(error.message(), "purge listener failed");
        adapter.reset();
        database.get_collection(COLLECTION).await.unwrap();
        assert_eq!(metadata_reads(&adapter), 1);
    });
}

#[test]
fn a_nested_transaction_caches_its_definition_only_after_the_outermost_commit() {
    run(|| async {
        let adapter = Observed::memory();
        let database = database(adapter.clone(), None).await;
        purge(&database, COLLECTION).await;
        adapter.reset();
        let before_outer_commit = database
            .with_transaction(|| async {
                database.with_transaction(|| update(&database, 2)).await?;
                Ok(metadata_reads(&adapter))
            })
            .await
            .unwrap();
        assert_eq!(before_outer_commit, 1);
        assert_eq!(metadata_reads(&adapter), 2);
        assert_eq!(reads_of(&adapter, database.with_transaction(|| database.get_collection(COLLECTION))).await, 0);
    });
}

#[test]
fn a_definition_changed_while_it_is_cached_after_commit_is_not_cached_stale() {
    run(|| async {
        let adapter = Observed::memory();
        let cache = over(&Leasable::new());
        let database = database(adapter.clone(), Some(cache.clone())).await;
        let writer = Database::new(adapter.clone(), cache);
        writer
            .set_authorization(database.authorization())
            .set_database(&database.database())
            .set_namespace(&database.namespace());
        purge(&database, COLLECTION).await;
        database
            .with_transaction(|| async {
                database.get_collection(COLLECTION).await?;
                let writer = writer.clone();
                after_definition_read(&adapter, COLLECTION, async move {
                    writer.update_collection(COLLECTION, documents_security()).await.map(|_| ())
                });
                Ok(())
            })
            .await
            .unwrap();
        let read = || database.get_collection(COLLECTION);
        assert_eq!(reads_of(&adapter, database.with_transaction(read)).await, 2);
        assert_eq!(reads_of(&adapter, database.with_transaction(read)).await, 0);
        assert!(database.get_collection(COLLECTION).await.unwrap().document_security());
    });
}

#[test]
fn a_rolled_back_transaction_leaves_its_definition_read_uncached() {
    run(|| async {
        let adapter = Observed::memory();
        let database = database(adapter.clone(), None).await;
        purge(&database, COLLECTION).await;
        let result: Result<()> = database
            .with_transaction(|| async {
                database.get_collection(COLLECTION).await?;
                Err(Error::new(Kind::Runtime, "rolled back"))
            })
            .await;
        assert!(result.is_err());
        adapter.reset();
        database.get_collection(COLLECTION).await.unwrap();
        assert_eq!(metadata_reads(&adapter), 1);
    });
}

#[test]
fn a_schema_change_inside_the_transaction_is_read() {
    run(|| async {
        let database = database(Observed::memory(), None).await;
        purge(&database, COLLECTION).await;
        let (before, after) = database
            .with_transaction(|| async {
                update(&database, 2).await?;
                let before = database.get_collection(COLLECTION).await?;
                database
                    .update_collection(
                        COLLECTION,
                        Update {
                            permissions: Some(vec![Permission::read(&Role::users(""))]),
                            document_security: Some(true),
                        },
                    )
                    .await?;
                Ok((before, database.get_collection(COLLECTION).await?))
            })
            .await
            .unwrap();
        assert!(!before.document_security());
        assert!(after.document_security());
        assert_eq!(after.permissions().unwrap(), ["read(\"users\")"]);
    });
}

#[test]
fn a_definition_read_in_one_transaction_is_not_reused_by_the_next() {
    run(|| async {
        let database = database(Observed::memory(), None).await;
        purge(&database, COLLECTION).await;
        database
            .with_transaction(|| async {
                update(&database, 2).await?;
                database.get_collection(COLLECTION).await
            })
            .await
            .unwrap();
        database.update_collection(COLLECTION, documents_security()).await.unwrap();
        purge(&database, COLLECTION).await;
        let read = database
            .with_transaction(|| async {
                update(&database, 3).await?;
                database.get_collection(COLLECTION).await
            })
            .await
            .unwrap();
        assert!(read.document_security());
    });
}

#[test]
fn changing_a_definition_read_in_a_transaction_does_not_change_the_next_read() {
    run(|| async {
        let database = database(Observed::memory(), None).await;
        purge(&database, COLLECTION).await;
        let second = database
            .with_transaction(|| async {
                update(&database, 2).await?;
                let mut first = database.get_collection(COLLECTION).await?;
                first.set("name", Value::from("changed"))?;
                database.get_collection(COLLECTION).await
            })
            .await
            .unwrap();
        assert_eq!(second.get("name"), Some(&Value::from(COLLECTION)));
        assert_eq!(second.attributes().unwrap()[0].size.unwrap_or(0), 0);
    });
}

#[test]
fn a_raw_definition_read_in_a_transaction_leaves_later_writes_working() {
    run(|| async {
        let database = database(Observed::memory(), None).await;
        purge(&database, COLLECTION).await;
        let (raw, created) = database
            .with_transaction(|| async {
                let raw = raw_definition(&database).await?;
                let created = database
                    .create_document(COLLECTION, doc(vec![("$id", Value::from("grace")), ("balance", Value::Int(5))]))
                    .await?;
                Ok((raw, created))
            })
            .await
            .unwrap();
        assert!(matches!(raw.get("attributes"), Some(Value::Str(_))));
        assert_eq!(created.get("balance"), Some(&Value::Int(5)));
        let read = database.get_document(COLLECTION, "grace", vec![], false).await.unwrap();
        assert_eq!(read.get("balance"), Some(&Value::Int(5)));
    });
}

#[test]
fn a_filtered_definition_read_in_a_transaction_leaves_later_raw_reads_raw() {
    run(|| async {
        let database = database(Observed::memory(), None).await;
        purge(&database, COLLECTION).await;
        let (collection, raw) = database
            .with_transaction(|| async {
                Ok((database.get_collection(COLLECTION).await?, raw_definition(&database).await?))
            })
            .await
            .unwrap();
        assert_eq!(collection.attributes().unwrap()[0].key, "balance");
        let Some(Value::Str(encoded)) = raw.get("attributes") else { panic!("raw attributes are JSON") };
        assert!(encoded.as_str().unwrap().contains("\"key\":\"balance\""));
    });
}

fn unreachable<T>() -> utopia_cache::Result<T> {
    Err(utopia_cache::Error::Runtime("cache unreachable".into()))
}
