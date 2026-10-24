//! Ported from utopia-php/database 8.1.3 `tests/unit/Event/TypedEventTest.php`
//! and `tests/unit/Event/DispatcherHookTest.php` (pure parts).
//!
//! Not ported (they need a `Database` with the Memory or SQLite adapter):
//! TypedEventTest `testDatabaseEvents`, `testCollectionEvents`,
//! `testAttributeEvents`, `testIndexEvents`, `testDocumentEvents`,
//! `testBatchUpsertCountsCreatedAndUpdatedDocuments`,
//! `testNoEventIsBuiltForAnEventNoHookHandles`; DispatcherHookTest
//! `testDeleteCollectionDeliversCollectionDeletedOnce`,
//! `testBulkWritesDeliverBulkEventsWithTheirCounts`,
//! `testAListenerErrorReachesTheCaller`,
//! `testAListenerExceptionAtAnIsolatedEventIsSwallowedAndTheOtherListenersRun`,
//! `testAListenerExceptionAtADocumentEventReachesTheCaller`,
//! `testADispatcherErrorReachesTheCaller`,
//! `testADispatcherExceptionFollowsTheHookFailurePolicy`. The hook-level half
//! of the error policy (an engine `\Error` is not caught) is ported below.

mod common;

use std::sync::{Arc, Mutex};

use async_trait::async_trait;
use common::document;
use utopia_database::event::{Dispatcher, DispatcherHook, Domain, Event};
use utopia_database::hook::{Lifecycle, Selective};
use utopia_database::{Error, Kind, Result};

// TypedEventTest

#[test]
fn test_every_case_a_hook_receives_names_a_final_event_class_that_carries_it() {
    let unhooked = [Event::All, Event::PermissionsCreate, Event::PermissionsRead, Event::PermissionsDelete];
    for case in Event::CASES {
        let class = case.domain();
        if unhooked.contains(case) {
            assert_eq!(class, None, "{} names an event class", case.as_str());
            continue;
        }
        let class = class.unwrap_or_else(|| panic!("{} has no event class", case.as_str()));
        assert!(class.starts_with("Utopia\\Database\\Event\\"), "{class}");
    }
    assert_eq!(Event::CASES.len(), 38);
    assert_eq!(Event::DocumentsUpsert.domain(), Some("Utopia\\Database\\Event\\Document\\BatchUpserted"));
    assert_eq!(Event::try_from_value("*"), Some(Event::All));
}

#[test]
fn test_each_typed_event_names_its_case() {
    let created = Domain::DocumentCreated { collection: "users".into(), document: document! {"$id" => "doc-1"} };
    assert_eq!(created.event(), Event::DocumentCreate);
    assert_eq!(created.php_class(), "Utopia\\Database\\Event\\Document\\Created");
    let batch = Domain::document_batch_upserted("users", 2, 1);
    assert_eq!(batch, Domain::DocumentBatchUpserted { collection: "users".into(), created: 2, updated: 1, count: 3 });
    assert_eq!(batch.event(), Event::DocumentsUpsert);
}

// DispatcherHookTest

struct Echo;

#[async_trait]
impl Dispatcher for Echo {
    async fn dispatch(&self, _event: &Domain) -> Result<()> {
        Ok(())
    }
}

fn created(id: &str) -> Domain {
    Domain::DocumentCreated { collection: "test".into(), document: document! {"$id" => id} }
}

#[test]
fn test_the_hook_handles_only_events_it_has_a_listener_or_a_dispatcher_for() {
    let hook = DispatcherHook::default();
    assert!(!hook.handles(Event::DocumentUpdate));

    hook.on(Event::DocumentUpdate, |_| Ok(()));

    assert!(hook.handles(Event::DocumentUpdate));
    assert!(!hook.handles(Event::DocumentDelete));
    assert!(!hook.handles(Event::All), "All selects timeouts and is never an event");

    let dispatching = DispatcherHook::new(Some(Arc::new(Echo)));
    assert!(dispatching.handles(Event::DocumentDelete));
    assert!(dispatching.handles(Event::DocumentRead));
    assert!(!dispatching.handles(Event::All));
    assert!(dispatching.selective().is_some());
}

#[utopia_async::test]
async fn test_the_listeners_of_the_event_class_receive_the_event() {
    let hook = DispatcherHook::default();
    let received = Arc::new(Mutex::new(Vec::new()));
    let sink = received.clone();
    hook.on(Event::DocumentCreate, move |e| {
        sink.lock().unwrap().push(e.clone());
        Ok(())
    });
    hook.on(Event::DocumentUpdate, |_| Err(Error::new(Kind::Runtime, "Not the event class")));

    let event = Domain::DocumentCreated { collection: "users".into(), document: document! {"$id" => "doc-1"} };
    hook.handle(&event).await.unwrap();

    assert_eq!(*received.lock().unwrap(), vec![event]);
}

#[utopia_async::test]
async fn test_handle_runs_every_listener_then_rethrows_the_first_exception() {
    let hook = DispatcherHook::default();
    let calls = Arc::new(Mutex::new(Vec::new()));
    let (a, b, c) = (calls.clone(), calls.clone(), calls.clone());
    hook.on(Event::DocumentCreate, move |_| {
        a.lock().unwrap().push("first");
        Err(Error::new(Kind::Runtime, "first"))
    });
    hook.on(Event::DocumentCreate, move |_| {
        b.lock().unwrap().push("second");
        Err(Error::new(Kind::Runtime, "second"))
    });
    hook.on(Event::DocumentCreate, move |_| {
        c.lock().unwrap().push("third");
        Ok(())
    });

    let e = hook.handle(&created("doc-5")).await.unwrap_err();
    assert_eq!(e, Error::new(Kind::Runtime, "first"), "The first listener exception must reach the database");
    assert_eq!(*calls.lock().unwrap(), ["first", "second", "third"]);
}

#[utopia_async::test]
async fn test_a_listener_error_is_not_caught() {
    // PHP's `\Error` escapes `catch (Exception)` at once: the later listeners
    // do not run.
    let hook = DispatcherHook::default();
    let ran = Arc::new(Mutex::new(false));
    let flag = ran.clone();
    hook.on(Event::CollectionCreate, |_| Err(Error::new(Kind::Error, "listener bug")));
    hook.on(Event::CollectionCreate, move |_| {
        *flag.lock().unwrap() = true;
        Ok(())
    });
    let event = Domain::CollectionCreated {
        collection: "comments".into(),
        definition: utopia_database::Collection::create("comments", "", vec![], vec![], None, true, Default::default())
            .unwrap(),
    };
    let e = hook.handle(&event).await.unwrap_err();
    assert_eq!((e.kind(), e.message()), (Kind::Error, "listener bug"));
    assert!(!*ran.lock().unwrap());
}

struct Failing(Kind);

#[async_trait]
impl Dispatcher for Failing {
    async fn dispatch(&self, _event: &Domain) -> Result<()> {
        Err(Error::new(self.0, "dispatcher failure"))
    }
}

#[utopia_async::test]
async fn test_a_dispatcher_failure_follows_the_listeners() {
    let hook = DispatcherHook::new(Some(Arc::new(Failing(Kind::Runtime))));
    let e = hook.handle(&created("x")).await.unwrap_err();
    assert_eq!((e.kind(), e.message()), (Kind::Runtime, "dispatcher failure"));

    let hook = DispatcherHook::new(Some(Arc::new(Failing(Kind::Runtime))));
    hook.on(Event::DocumentCreate, |_| Err(Error::new(Kind::Runtime, "listener failure")));
    let e = hook.handle(&created("x")).await.unwrap_err();
    assert_eq!(e.message(), "listener failure", "the first failure wins");
}
