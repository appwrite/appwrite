//! PHP: `Event\DocumentPurgeTest` (its Memory cases).

use std::sync::{Arc, Mutex};

use async_trait::async_trait;
use futures_util::future::BoxFuture;

use super::none_cache;
use crate::database::cache_fixtures::{collection, doc, run, unique};
use crate::database::observed::{Observed, Op};
use crate::database::{Database, Hook};
use crate::document::Document;
use crate::error::{Error, Kind, Result};
use crate::event::{Domain, Event};
use crate::hook::Lifecycle;
use crate::permission::Permission;
use crate::query::Query;
use crate::role::Role;
use crate::validator::authorization::Authorization;
use crate::value::Value;

const COLLECTION: &str = "posts";

/// `RecordingLifecycle`, also recording the tenant each purge was
/// announced under.
#[derive(Default)]
struct Recorder {
    events: Mutex<Vec<Domain>>,
    tenants: Mutex<Vec<Value>>,
    database: Mutex<Option<Database>>,
}

impl Recorder {
    fn events(&self) -> Vec<Event> {
        self.events.lock().unwrap().iter().map(Domain::event).collect()
    }

    /// `purged()`.
    fn purged(&self) -> Vec<String> {
        self.events
            .lock()
            .unwrap()
            .iter()
            .filter_map(|e| match e {
                Domain::DocumentPurged { collection, id } => Some(format!("{collection}/{id}")),
                _ => None,
            })
            .collect()
    }
}

#[async_trait]
impl Lifecycle for Recorder {
    async fn handle(&self, event: &Domain) -> Result<()> {
        if event.event() == Event::DocumentPurge
            && let Some(database) = self.database.lock().unwrap().as_ref()
        {
            self.tenants.lock().unwrap().push(database.tenant());
        }
        self.events.lock().unwrap().push(event.clone());
        Ok(())
    }
}

/// `HookFixture::database()`: the `posts` collection on a database that
/// caches nothing.
async fn fixture(adapter: Arc<Observed>) -> Database {
    let database = Database::new(adapter, none_cache());
    database
        .set_authorization(Arc::new(Authorization::new(true)))
        .set_database("hooks")
        .set_namespace(&unique("hooks_"));
    database.create(None).await.unwrap();
    let any = Role::any();
    database
        .create_collection(collection(
            COLLECTION,
            vec![crate::Attribute::string("title", 64), crate::Attribute::integer("views")],
            vec![],
            Some(vec![
                Permission::create(&any),
                Permission::read(&any),
                Permission::update(&any),
                Permission::delete(&any),
            ]),
            true,
        ))
        .await
        .unwrap();
    database
}

/// `seeded()`: the documents `ids`, then a recorder.
async fn seeded(adapter: Arc<Observed>, ids: &[&str]) -> (Database, Arc<Recorder>) {
    let database = fixture(adapter).await;
    for (index, id) in ids.iter().enumerate() {
        database
            .create_document(
                COLLECTION,
                doc(vec![
                    ("$id", Value::from(*id)),
                    ("title", Value::from(*id)),
                    ("views", Value::Int(index as i64 + 1)),
                ]),
            )
            .await
            .unwrap();
    }
    let recorder = Arc::new(Recorder::default());
    database.add_hook(Hook::lifecycle(recorder.clone())).unwrap();
    (database, recorder)
}

fn titled(title: &str) -> Document {
    doc(vec![("title", Value::from(title))])
}

#[test]
fn update_document_purges_the_document() {
    run(|| async {
        let (database, recorder) = seeded(Observed::memory(), &["first", "second"]).await;
        database.update_document(COLLECTION, "first", titled("renamed")).await.unwrap();
        assert_eq!(recorder.events(), [Event::DocumentPurge, Event::DocumentUpdate]);
        assert_eq!(recorder.purged(), ["posts/first"]);
    });
}

#[test]
fn update_document_purges_both_identifiers_of_a_renamed_document() {
    run(|| async {
        let (database, recorder) = seeded(Observed::memory(), &["first"]).await;
        database.update_document(COLLECTION, "first", doc(vec![("$id", Value::from("renamed"))])).await.unwrap();
        assert_eq!(recorder.events(), [Event::DocumentPurge, Event::DocumentPurge, Event::DocumentUpdate]);
        assert_eq!(recorder.purged(), ["posts/first", "posts/renamed"]);
    });
}

#[test]
fn update_documents_purges_every_updated_document() {
    run(|| async {
        let (database, recorder) = seeded(Observed::memory(), &["first", "second", "third"]).await;
        database
            .update_documents(
                COLLECTION,
                doc(vec![("views", Value::Int(10))]),
                vec![Query::not_equal("title", "third")],
                100,
                None,
            )
            .await
            .unwrap();
        assert_eq!(recorder.events(), [Event::DocumentPurge, Event::DocumentPurge, Event::DocumentsUpdate]);
        assert_eq!(recorder.purged(), ["posts/first", "posts/second"]);
    });
}

#[test]
fn increase_and_decrease_purge_the_document() {
    run(|| async {
        let (database, recorder) = seeded(Observed::memory(), &["first", "second"]).await;
        database.increase_document_attribute(COLLECTION, "first", "views", Value::Int(2), None).await.unwrap();
        assert_eq!(recorder.events(), [Event::DocumentPurge, Event::DocumentIncrease]);
        assert_eq!(recorder.purged(), ["posts/first"]);

        let (database, recorder) = seeded(Observed::memory(), &["first", "second"]).await;
        database.decrease_document_attribute(COLLECTION, "second", "views", Value::Int(1), None).await.unwrap();
        assert_eq!(recorder.events(), [Event::DocumentPurge, Event::DocumentDecrease]);
        assert_eq!(recorder.purged(), ["posts/second"]);
    });
}

#[test]
fn delete_document_purges_the_document() {
    run(|| async {
        let (database, recorder) = seeded(Observed::memory(), &["first", "second"]).await;
        database.delete_document(COLLECTION, "first").await.unwrap();
        assert_eq!(recorder.events(), [Event::DocumentPurge, Event::DocumentDelete]);
        assert_eq!(recorder.purged(), ["posts/first"]);
    });
}

#[test]
fn delete_documents_purges_every_deleted_document() {
    run(|| async {
        let (database, recorder) = seeded(Observed::memory(), &["first", "second", "third"]).await;
        database.delete_documents(COLLECTION, vec![Query::not_equal("title", "second")], 100, None).await.unwrap();
        assert_eq!(recorder.events(), [Event::DocumentPurge, Event::DocumentPurge, Event::DocumentsDelete]);
        assert_eq!(recorder.purged(), ["posts/first", "posts/third"]);
    });
}

#[test]
fn batch_writes_purge_every_batch() {
    run(|| async {
        let (database, recorder) = seeded(Observed::memory(), &["first", "second", "third"]).await;
        database.update_documents(COLLECTION, doc(vec![("views", Value::Int(10))]), vec![], 2, None).await.unwrap();
        database.delete_documents(COLLECTION, vec![], 2, None).await.unwrap();
        use Event::*;
        assert_eq!(
            recorder.events(),
            [
                DocumentPurge,
                DocumentPurge,
                DocumentPurge,
                DocumentsUpdate,
                DocumentPurge,
                DocumentPurge,
                DocumentPurge,
                DocumentsDelete
            ]
        );
        assert_eq!(
            recorder.purged(),
            ["posts/first", "posts/second", "posts/third", "posts/first", "posts/second", "posts/third"]
        );
    });
}

#[test]
fn writes_that_change_nothing_purge_nothing() {
    run(|| async {
        let (database, recorder) = seeded(Observed::memory(), &["first"]).await;
        let _ = database.update_document(COLLECTION, "missing", titled("renamed")).await;
        let _ = database.delete_document(COLLECTION, "missing").await;
        database
            .update_documents(
                COLLECTION,
                doc(vec![("views", Value::Int(10))]),
                vec![Query::equal("title", ["missing"])],
                100,
                None,
            )
            .await
            .unwrap();
        database.delete_documents(COLLECTION, vec![Query::equal("title", ["missing"])], 100, None).await.unwrap();
        assert_eq!(recorder.events(), [Event::DocumentsUpdate, Event::DocumentsDelete]);
    });
}

#[test]
fn purge_cached_document_purges_the_document() {
    run(|| async {
        let (database, recorder) = seeded(Observed::memory(), &["first"]).await;
        database.purge_cached_document(COLLECTION, "first").await.unwrap();
        assert_eq!(recorder.events(), [Event::DocumentPurge]);
        assert_eq!(recorder.purged(), ["posts/first"]);
    });
}

/// Fails the adapter's next commit as a lost one (`commitFailures = 1`).
fn lose_next_commit(adapter: &Observed) {
    let mut armed = true;
    adapter.once(move |op| {
        (*op == Op::Commit && std::mem::take(&mut armed)).then(|| {
            Box::pin(async { Err(Error::transaction("Failed to commit transaction: commit lost")) })
                as BoxFuture<'static, Result<()>>
        })
    });
}

#[test]
fn a_document_purge_fires_once_when_the_transaction_is_retried() {
    run(|| async {
        let adapter = Observed::memory();
        let (database, recorder) = seeded(adapter.clone(), &["first", "second"]).await;
        lose_next_commit(&adapter);
        database.update_document(COLLECTION, "first", titled("renamed")).await.unwrap();
        lose_next_commit(&adapter);
        database.delete_documents(COLLECTION, vec![], 100, None).await.unwrap();
        use Event::*;
        assert_eq!(recorder.events(), [DocumentPurge, DocumentUpdate, DocumentPurge, DocumentPurge, DocumentsDelete]);
        assert_eq!(recorder.purged(), ["posts/first", "posts/first", "posts/second"]);
    });
}

#[test]
fn purge_events_of_a_rolled_back_nested_transaction_are_dropped() {
    run(|| async {
        let (database, recorder) = seeded(Observed::memory(), &["first", "second"]).await;
        database
            .with_transaction(|| async {
                database.update_document(COLLECTION, "first", titled("renamed")).await?;
                let nested: Result<()> = database
                    .with_transaction(|| async {
                        database.update_document(COLLECTION, "second", titled("renamed")).await?;
                        Err(Error::new(Kind::Runtime, "abandoned"))
                    })
                    .await;
                assert_eq!(nested.unwrap_err().message(), "abandoned");
                Ok(())
            })
            .await
            .unwrap();
        assert_eq!(recorder.purged(), ["posts/first"]);
        let second = database.get_document(COLLECTION, "second", vec![], false).await.unwrap();
        assert_eq!(second.get("title"), Some(&Value::from("second")));
    });
}

#[test]
fn purge_events_of_a_failed_nested_call_without_savepoints_fire_after_the_outer_commit() {
    run(|| async {
        let adapter = Observed::memory();
        adapter.remove_capability(crate::capability::Capability::TransactionNested);
        let (database, recorder) = seeded(adapter, &["first", "second"]).await;
        database
            .with_transaction(|| async {
                database.update_document(COLLECTION, "first", titled("renamed")).await?;
                let nested: Result<()> = database
                    .with_transaction(|| async {
                        database.update_document(COLLECTION, "second", titled("renamed")).await?;
                        Err(Error::new(Kind::Runtime, "abandoned"))
                    })
                    .await;
                assert_eq!(nested.unwrap_err().message(), "abandoned");
                Ok(())
            })
            .await
            .unwrap();
        let second = database.get_document(COLLECTION, "second", vec![], false).await.unwrap();
        assert_eq!(second.get("title"), Some(&Value::from("renamed")), "nothing rolls the nested write back");
        assert_eq!(recorder.purged(), ["posts/first", "posts/second"]);
    });
}

#[test]
fn purge_events_of_writes_without_an_adapter_transaction_fire_at_once() {
    run(|| async {
        let adapter = Observed::memory();
        let (database, recorder) = seeded(adapter.clone(), &["first"]).await;
        adapter.without_transactions();
        let result: Result<()> = database
            .with_transaction(|| async {
                database.update_document(COLLECTION, "first", titled("renamed")).await?;
                Err(Error::new(Kind::Runtime, "abandoned"))
            })
            .await;
        assert_eq!(result.unwrap_err().message(), "abandoned");
        let first = database.get_document(COLLECTION, "first", vec![], false).await.unwrap();
        assert_eq!(first.get("title"), Some(&Value::from("renamed")));
        assert_eq!(recorder.purged(), ["posts/first"]);
    });
}

#[test]
fn queued_purge_events_keep_their_tenant() {
    run(|| async {
        let database = Database::new(Observed::memory(), none_cache());
        database
            .set_authorization(Arc::new(Authorization::new(true)))
            .set_database("hooks")
            .set_namespace(&unique("hooks_"))
            .set_shared_tables(true)
            .set_tenant(Value::Null)
            .set_tenant_per_document(true);
        database.create(None).await.unwrap();
        let any = Role::any();
        database
            .create_collection(collection(
                COLLECTION,
                vec![crate::Attribute::string("title", 64)],
                vec![],
                Some(vec![Permission::create(&any), Permission::read(&any), Permission::update(&any)]),
                true,
            ))
            .await
            .unwrap();
        for tenant in [7, 8] {
            database
                .create_document(
                    COLLECTION,
                    doc(vec![
                        ("$id", Value::from("first")),
                        ("$tenant", Value::Int(tenant)),
                        ("title", Value::from("first")),
                    ]),
                )
                .await
                .unwrap();
        }
        let recorder = Arc::new(Recorder::default());
        *recorder.database.lock().unwrap() = Some(database.clone());
        database.add_hook(Hook::lifecycle(recorder.clone())).unwrap();
        database
            .with_transaction(|| async {
                database
                    .with_tenant(Value::Int(7), database.update_document(COLLECTION, "first", titled("renamed")))
                    .await?;
                database
                    .with_tenant(
                        Value::Int(8),
                        database.update_documents(
                            COLLECTION,
                            titled("renamed"),
                            vec![Query::equal("$id", ["first"])],
                            100,
                            None,
                        ),
                    )
                    .await?;
                Ok(())
            })
            .await
            .unwrap();
        assert_eq!(*recorder.tenants.lock().unwrap(), [Value::Int(7), Value::Int(8)]);
        assert_eq!(database.tenant(), Value::Null);
        *recorder.database.lock().unwrap() = None;
    });
}
