//! PHP: `tests/unit/Hook/NamedTest.php`, `RemoveHookTest.php`,
//! `AddUnknownHookTest.php`, `DecoratorBatchTest.php`, `AttachTest.php` and
//! the relationship cases of `tests/unit/State/CoroutineStateTest.php`.
//!
//! Not ported: the Mirror cases (no Mirror yet), the write-hook and
//! transform cases (SQL adapters only), and the unknown hook cases: hooks
//! are an enum here, so a hook of no known kind cannot be added.

use std::future::Future;
use std::pin::Pin;

use super::*;
use crate::hook::relationships::Relationships;
use crate::hook::{Decorator, Queries};
use crate::query::Query;
use crate::relationship::Relationship;

#[test]
fn add_hook_replaces_the_hook_registered_under_the_same_name() {
    block_on(async {
        let db = memory().await;
        let (replaced, replacement) = (Recorder::named("audits"), Recorder::named("audits"));
        db.add_hook(Hook::lifecycle(replaced.clone())).unwrap();
        db.add_hook(Hook::lifecycle(replacement.clone())).unwrap();
        db.get_collection(COLLECTION).await.unwrap();
        assert!(replaced.events().is_empty());
        assert_eq!(replacement.events(), vec![Event::CollectionRead]);
    });
}

/// A hook appending its label to a shared journal, named or not.
struct Journaling {
    name: Option<String>,
    label: String,
    journal: Arc<Mutex<Vec<String>>>,
}

#[async_trait]
impl Lifecycle for Journaling {
    async fn handle(&self, _event: &Domain) -> Result<()> {
        self.journal.lock().unwrap().push(self.label.clone());
        Ok(())
    }

    fn named(&self) -> Option<&dyn Named> {
        self.name.as_ref().map(|_| self as &dyn Named)
    }
}

impl Named for Journaling {
    fn name(&self) -> &str {
        self.name.as_deref().unwrap_or("")
    }
}

#[test]
fn a_replacement_keeps_the_registration_position() {
    block_on(async {
        let db = memory().await;
        let journal = Arc::new(Mutex::new(Vec::new()));
        for (name, label) in [(Some("audits"), "replaced"), (None, "unnamed"), (Some("audits"), "replacement")] {
            let hook = Journaling { name: name.map(str::to_owned), label: label.to_owned(), journal: journal.clone() };
            db.add_hook(Hook::lifecycle(Arc::new(hook))).unwrap();
        }
        db.get_collection(COLLECTION).await.unwrap();
        assert_eq!(*journal.lock().unwrap(), ["replacement", "unnamed"]);
    });
}

#[test]
fn unnamed_hooks_keep_appending() {
    block_on(async {
        let db = memory().await;
        let (first, second) = (Recorder::new(), Recorder::new());
        db.add_hook(Hook::lifecycle(first.clone())).unwrap();
        db.add_hook(Hook::lifecycle(second.clone())).unwrap();
        db.add_hook(Hook::lifecycle(first.clone())).unwrap();
        db.get_collection(COLLECTION).await.unwrap();
        assert_eq!(first.events(), vec![Event::CollectionRead, Event::CollectionRead]);
        assert_eq!(second.events(), vec![Event::CollectionRead]);
    });
}

#[test]
fn silent_without_listeners_silences_every_hook() {
    block_on(async {
        let db = memory().await;
        let (named, unnamed) = (Recorder::named("audits"), Recorder::new());
        db.add_hook(Hook::lifecycle(named.clone())).unwrap();
        db.add_hook(Hook::lifecycle(unnamed.clone())).unwrap();
        db.silent(db.get_collection(COLLECTION)).await.unwrap();
        assert!(named.events().is_empty());
        assert!(unnamed.events().is_empty());
    });
}

#[test]
fn silent_with_listeners_silences_only_the_named_hooks() {
    block_on(async {
        let db = memory().await;
        let (audits, usage, unnamed) = (Recorder::named("audits"), Recorder::named("usage"), Recorder::new());
        for hook in [&audits, &usage, &unnamed] {
            db.add_hook(Hook::lifecycle(hook.clone())).unwrap();
        }
        db.silent_hooks(&["audits"], db.get_collection(COLLECTION)).await.unwrap();
        assert!(audits.events().is_empty());
        assert_eq!(usage.events(), vec![Event::CollectionRead]);
        assert_eq!(unnamed.events(), vec![Event::CollectionRead]);
    });
}

#[test]
fn silent_with_listeners_keeps_unnamed_hooks_firing() {
    block_on(async {
        let db = memory().await;
        let unnamed = Recorder::new();
        db.add_hook(Hook::lifecycle(unnamed.clone())).unwrap();
        db.silent_hooks(&["audits"], db.get_collection(COLLECTION)).await.unwrap();
        assert_eq!(unnamed.events(), vec![Event::CollectionRead]);
    });
}

#[test]
fn a_nested_silence_never_narrows_an_outer_silence() {
    block_on(async {
        let db = memory().await;
        let (audits, usage, unnamed) = (Recorder::named("audits"), Recorder::named("usage"), Recorder::new());
        for hook in [&audits, &usage, &unnamed] {
            db.add_hook(Hook::lifecycle(hook.clone())).unwrap();
        }
        db.silent(db.silent_hooks(&["audits"], db.get_collection(COLLECTION))).await.unwrap();
        assert!(unnamed.events().is_empty());

        db.silent_hooks(&["audits"], async {
            db.silent_hooks(&["usage"], db.list_collections(25, 0)).await.unwrap();
            db.get_collection(COLLECTION).await.unwrap();
        })
        .await;
        assert!(audits.events().is_empty());
        assert_eq!(usage.events(), vec![Event::CollectionRead]);
        assert_eq!(unnamed.events(), vec![Event::CollectionList, Event::CollectionRead]);
    });
}

#[test]
fn a_silence_ends_when_its_work_fails() {
    block_on(async {
        let db = memory().await;
        let (audits, unnamed) = (Recorder::named("audits"), Recorder::new());
        db.add_hook(Hook::lifecycle(audits.clone())).unwrap();
        db.add_hook(Hook::lifecycle(unnamed.clone())).unwrap();
        let failed = || async { Err::<(), Error>(Error::new(crate::Kind::Runtime, "callback failed")) };
        assert!(db.silent(failed()).await.is_err());
        assert!(db.silent_hooks(&["audits"], failed()).await.is_err());
        db.get_collection(COLLECTION).await.unwrap();
        assert_eq!(audits.events(), vec![Event::CollectionRead]);
        assert_eq!(unnamed.events(), vec![Event::CollectionRead]);
    });
}

#[test]
fn a_named_silence_also_silences_document_purge() {
    block_on(async {
        let db = memory().await;
        seed(&db, &["first"]).await;
        let regions = Failing {
            event: Event::DocumentPurge,
            error: Error::new(crate::Kind::Runtime, "region broadcast failed"),
            name: Some("regions".into()),
        };
        db.add_hook(Hook::lifecycle(Arc::new(regions))).unwrap();

        let renamed = Document::from_pairs([("title", value::string("renamed"))]).unwrap();
        db.silent_hooks(&["regions"], db.update_document(COLLECTION, "first", renamed)).await.unwrap();
        db.silent_hooks(&["regions"], db.purge_cached_document(COLLECTION, "first")).await.unwrap();

        let first = db.get_document(COLLECTION, "first", Vec::new(), false).await.unwrap();
        assert_eq!(first.get("title"), Some(&value::string("renamed")));
    });
}

/// Marks every document it decorates with `decorated`.
struct Marking;

#[async_trait]
impl Decorator for Marking {
    async fn decorate(&self, _event: Event, _collection: &Collection, mut document: Document) -> Result<Document> {
        document.put("decorated", Value::Bool(true));
        Ok(document)
    }
}

#[test]
fn decorators_keep_running_during_a_named_silence() {
    block_on(async {
        let db = memory().await;
        seed(&db, &["first"]).await;
        db.add_hook(Hook::decorator(Arc::new(Marking))).unwrap();
        let named =
            db.silent_hooks(&["audits"], db.get_document(COLLECTION, "first", Vec::new(), false)).await.unwrap();
        let silenced = db.silent(db.get_document(COLLECTION, "first", Vec::new(), false)).await.unwrap();
        assert_eq!(named.get("decorated"), Some(&Value::Bool(true)));
        assert_eq!(silenced.get("decorated"), None);
    });
}

#[test]
fn a_named_silence_is_scoped_to_its_task() {
    block_on(async {
        let db = memory().await;
        let audits = Recorder::named("audits");
        db.add_hook(Hook::lifecycle(audits.clone())).unwrap();

        let (entered, wait_entered) = utopia_async::sync::oneshot::channel::<()>();
        let (release, released) = utopia_async::sync::oneshot::channel::<()>();
        let silenced = {
            let db = db.clone();
            utopia_async::spawn(async move {
                db.silent_hooks(&["audits"], async {
                    db.get_collection(COLLECTION).await.unwrap();
                    entered.send(()).unwrap();
                    released.await.unwrap();
                })
                .await;
            })
        };
        let other = {
            let db = db.clone();
            utopia_async::spawn(async move {
                wait_entered.await.unwrap();
                db.list_collections(25, 0).await.unwrap();
                release.send(()).unwrap();
            })
        };
        silenced.await.unwrap();
        other.await.unwrap();
        assert_eq!(audits.events(), vec![Event::CollectionList]);
    });
}

// ----- RemoveHookTest -----

#[test]
fn removing_a_lifecycle_hook_stops_its_events_and_keeps_the_others() {
    block_on(async {
        let db = memory().await;
        let (removed, kept) = (Recorder::new(), Recorder::new());
        let removed_hook = Hook::lifecycle(removed.clone());
        db.add_hook(Hook::lifecycle(removed.clone())).unwrap();
        db.add_hook(Hook::lifecycle(kept.clone())).unwrap();
        assert!(db.remove_hook(&removed_hook).same(&db));
        db.get_collection(COLLECTION).await.unwrap();
        assert!(removed.events().is_empty());
        assert_eq!(kept.events(), vec![Event::CollectionRead]);
    });
}

#[test]
fn removing_a_class_removes_every_hook_of_it() {
    block_on(async {
        let db = memory().await;
        let (first, second) = (Recorder::new(), Recorder::new());
        db.add_hook(Hook::lifecycle(first.clone())).unwrap();
        db.add_hook(Hook::lifecycle(second.clone())).unwrap();
        db.remove_hook_class(std::any::type_name::<Recorder>());
        db.get_collection(COLLECTION).await.unwrap();
        assert!(first.events().is_empty());
        assert!(second.events().is_empty());
    });
}

#[test]
fn removing_a_decorator_leaves_documents_undecorated() {
    block_on(async {
        let db = memory().await;
        let decorator = Arc::new(Marking);
        db.add_hook(Hook::decorator(decorator.clone())).unwrap();
        seed(&db, &["first"]).await;
        db.remove_hook(&Hook::decorator(decorator));
        let first = db.get_document(COLLECTION, "first", Vec::new(), false).await.unwrap();
        assert_eq!(first.get("decorated"), None);
    });
}

/// `QueriesRecorder`: records each rewrite, adding `added` when given.
struct QueriesRecorder {
    added: Option<Query>,
    calls: Mutex<Vec<(Event, String)>>,
}

#[async_trait]
impl Queries for QueriesRecorder {
    async fn rewrite(&self, event: Event, collection: &Collection, mut queries: Vec<Query>) -> Result<Vec<Query>> {
        self.calls.lock().unwrap().push((event, collection.id().to_owned()));
        if let Some(added) = &self.added
            && !queries.contains(added)
        {
            queries.push(added.clone());
        }
        Ok(queries)
    }
}

#[test]
fn removing_a_queries_hook_stops_it_rewriting_reads() {
    block_on(async {
        let db = memory().await;
        seed(&db, &["first", "second"]).await;
        let removed =
            Arc::new(QueriesRecorder { added: Some(Query::equal("title", ["first"])), calls: Mutex::default() });
        let kept = Arc::new(QueriesRecorder { added: None, calls: Mutex::default() });
        db.add_hook(Hook::queries(removed.clone())).unwrap();
        db.add_hook(Hook::queries(kept.clone())).unwrap();

        assert!(db.remove_hook(&Hook::queries(removed.clone())).same(&db));
        assert_eq!(db.count(COLLECTION, Vec::new(), None).await.unwrap(), 2);
        assert!(removed.calls.lock().unwrap().is_empty());
        assert_eq!(*kept.calls.lock().unwrap(), vec![(Event::DocumentCount, COLLECTION.to_owned())]);

        db.remove_hook_class(std::any::type_name::<QueriesRecorder>());
        assert_eq!(db.count(COLLECTION, Vec::new(), None).await.unwrap(), 2);
        assert_eq!(*kept.calls.lock().unwrap(), vec![(Event::DocumentCount, COLLECTION.to_owned())]);
    });
}

/// `family()`: parents with children, no relationships hook.
async fn family() -> Database {
    let db =
        Database::new(Arc::new(Memory::new()), Arc::new(utopia_cache::Cache::from_dyn(Arc::new(utopia_cache::None))));
    db.set_database("remove_hook")
        .set_namespace(&format!("remove_hook_{}", NAMESPACES.fetch_add(1, Ordering::Relaxed)));
    db.create(None).await.unwrap();
    let any = Role::any();
    for id in ["parents", "children"] {
        let collection = Collection::create(
            id,
            "",
            vec![Attribute::string("name", 64)],
            Vec::new(),
            Some(vec![Permission::create(&any), Permission::read(&any), Permission::update(&any)]),
            true,
            Array::new(),
        )
        .unwrap();
        db.create_collection(collection).await.unwrap();
    }
    db.create_relationship(
        "parents",
        Relationship::one_to_many("children").two_way(true).key("children").two_way_key("parent"),
    )
    .await
    .unwrap();
    db
}

fn parent() -> Document {
    let child = Document::from_pairs([("$id", value::string("c1")), ("name", value::string("c1"))]).unwrap();
    Document::from_pairs([
        ("$id", value::string("p1")),
        ("name", value::string("p1")),
        ("children", value::list([Value::from(child)])),
    ])
    .unwrap()
}

#[test]
fn removing_the_relationships_hook_stops_relating_documents() {
    block_on(async {
        let db = family().await;
        db.add_hook(Hook::Relationships(Arc::new(Relationships::default()))).unwrap();
        db.remove_hook_class(std::any::type_name::<Relationships>());
        db.create_document("parents", parent()).await.unwrap();
        assert!(db.get_document("children", "c1", Vec::new(), false).await.unwrap().is_empty());
    });
}

// ----- AddUnknownHookTest -----

#[test]
fn a_known_hook_is_registered() {
    block_on(async {
        let db = memory().await;
        let recorder = Recorder::new();
        assert!(db.add_hook(Hook::lifecycle(recorder.clone())).unwrap().same(&db));
        db.get_collection(COLLECTION).await.unwrap();
        assert_eq!(recorder.events(), vec![Event::CollectionRead]);
    });
}

// ----- DecoratorBatchTest -----

const MARK: &str = "decoratedBy";

/// Marks every document with the event that decorates it.
struct ByEvent;

#[async_trait]
impl Decorator for ByEvent {
    async fn decorate(&self, event: Event, _collection: &Collection, document: Document) -> Result<Document> {
        let mut array = document.array_copy();
        array.set(value::ArrayKey::from(MARK), value::string(event.as_str()));
        Document::new(array)
    }
}

#[test]
fn find_decorates_every_document() {
    block_on(async {
        let db = memory().await;
        seed(&db, &["a", "b", "c"]).await;
        db.add_hook(Hook::decorator(Arc::new(ByEvent))).unwrap();
        let documents = db
            .find(COLLECTION, vec![Query::order_asc("views", None)], crate::permission::PermissionType::Read)
            .await
            .unwrap();
        let ids: Vec<&str> = documents.iter().map(Document::id).collect();
        assert_eq!(ids, ["a", "b", "c"]);
        for document in &documents {
            assert_eq!(document.get(MARK), Some(&value::string(Event::DocumentFind.as_str())), "{}", document.id());
        }
    });
}

#[test]
fn a_silenced_find_is_not_decorated() {
    block_on(async {
        let db = memory().await;
        seed(&db, &["a", "b"]).await;
        db.add_hook(Hook::decorator(Arc::new(ByEvent))).unwrap();
        let documents =
            db.silent(db.find(COLLECTION, Vec::new(), crate::permission::PermissionType::Read)).await.unwrap();
        assert_eq!(documents.len(), 2);
        assert!(documents.iter().all(|d| d.get(MARK).is_none()));
    });
}

type Marks = Arc<Mutex<Vec<(String, Option<Value>)>>>;

fn marks(marks: &Marks) -> Vec<(String, Option<Value>)> {
    let mut marks = marks.lock().unwrap().clone();
    marks.sort_by(|a, b| a.0.cmp(&b.0));
    marks
}

#[test]
fn create_documents_hands_decorated_documents_to_its_callback() {
    block_on(async {
        let db = memory().await;
        db.add_hook(Hook::decorator(Arc::new(ByEvent))).unwrap();
        let seen: Marks = Arc::default();
        let collected = seen.clone();
        let mut on_next = move |(document, _): (Document, Option<Document>)| -> Result<()> {
            collected.lock().unwrap().push((document.id().to_owned(), document.get(MARK).cloned()));
            Ok(())
        };
        let documents = ["a", "b"]
            .iter()
            .enumerate()
            .map(|(i, id)| {
                Document::from_pairs([
                    ("$id", value::string(*id)),
                    ("title", value::string(*id)),
                    ("views", Value::Int(i as i64 + 1)),
                ])
                .unwrap()
            })
            .collect();
        let count =
            db.create_documents(COLLECTION, documents, super::super::BATCH_SIZE, Some(&mut on_next)).await.unwrap();
        let marks = marks(&seen);
        assert_eq!(count as usize, marks.len());
        let mark = Some(value::string(Event::DocumentsCreate.as_str()));
        assert_eq!(marks, vec![("a".to_owned(), mark.clone()), ("b".to_owned(), mark)]);
    });
}

#[test]
fn update_documents_hands_decorated_documents_to_its_callback() {
    block_on(async {
        let db = memory().await;
        seed(&db, &["a", "b"]).await;
        db.add_hook(Hook::decorator(Arc::new(ByEvent))).unwrap();
        let seen: Marks = Arc::default();
        let collected = seen.clone();
        let mut on_next = move |(document, _): (Document, Option<Document>)| -> Result<()> {
            collected.lock().unwrap().push((document.id().to_owned(), document.get(MARK).cloned()));
            Ok(())
        };
        let updates = Document::from_pairs([("views", Value::Int(10))]).unwrap();
        let count = db
            .update_documents(COLLECTION, updates, Vec::new(), super::super::BATCH_SIZE, Some(&mut on_next))
            .await
            .unwrap();
        let marks = marks(&seen);
        assert_eq!(count as usize, marks.len());
        let mark = Some(value::string(Event::DocumentsUpdate.as_str()));
        assert_eq!(marks, vec![("a".to_owned(), mark.clone()), ("b".to_owned(), mark)]);
    });
}

// ----- AttachTest -----

/// `relatesThrough()`: whether `database`'s relationship state is `hook`'s.
async fn relates_through(database: &Database, hook: &Relationships) -> bool {
    hook.with_enabled(false, async { !database.snapshot().relationships }).await
}

fn bare() -> Database {
    Database::new(Arc::new(Memory::new()), Arc::new(utopia_cache::Cache::from_dyn(Arc::new(utopia_cache::None))))
}

#[test]
fn the_relationships_hook_relates_through_the_database_it_is_added_to() {
    block_on(async {
        let db = bare();
        db.set_database("attach").set_namespace(&format!("attach_{}", NAMESPACES.fetch_add(1, Ordering::Relaxed)));
        db.create(None).await.unwrap();
        let any = Role::any();
        for id in ["parents", "children"] {
            let collection = Collection::create(
                id,
                "",
                vec![Attribute::string("name", 64)],
                Vec::new(),
                Some(vec![Permission::create(&any), Permission::read(&any), Permission::update(&any)]),
                true,
                Array::new(),
            )
            .unwrap();
            db.create_collection(collection).await.unwrap();
        }
        db.create_relationship(
            "parents",
            Relationship::one_to_many("children").two_way(true).key("children").two_way_key("parent"),
        )
        .await
        .unwrap();
        db.add_hook(Hook::Relationships(Arc::new(Relationships::default()))).unwrap();
        db.create_document("parents", parent()).await.unwrap();

        let children =
            db.get_document("parents", "p1", Vec::new(), false).await.unwrap().documents("children").unwrap();
        assert_eq!(children.iter().map(Document::id).collect::<Vec<_>>(), ["c1"]);
    });
}

#[test]
fn the_relationships_hook_refuses_to_move_to_another_database() {
    block_on(async {
        let hook = Arc::new(Relationships::default());
        let (first, second) = (bare(), bare());
        first.add_hook(Hook::Relationships(hook.clone())).unwrap();
        let error = second.add_hook(Hook::Relationships(hook.clone())).unwrap_err();
        assert_eq!(
            error.message(),
            "This relationships hook is already attached to another database; attach a clone of it instead"
        );
        assert!(relates_through(&first, &hook).await);
        assert!(!relates_through(&second, &hook).await);
    });
}

#[test]
fn the_relationships_hook_can_be_added_again_to_its_own_database() {
    block_on(async {
        let hook = Arc::new(Relationships::default());
        let db = bare();
        db.add_hook(Hook::Relationships(hook.clone())).unwrap();
        db.add_hook(Hook::Relationships(hook.clone())).unwrap();
        assert!(relates_through(&db, &hook).await);
    });
}

#[test]
fn a_clone_of_an_attached_relationships_hook_attaches_to_another_database() {
    block_on(async {
        let hook = Arc::new(Relationships::default());
        let (first, second) = (bare(), bare());
        first.add_hook(Hook::Relationships(hook.clone())).unwrap();
        let clone = Arc::new(hook.fresh());
        second.add_hook(Hook::Relationships(clone.clone())).unwrap();
        assert!(relates_through(&first, &hook).await);
        assert!(relates_through(&second, &clone).await);
        assert!(!relates_through(&second, &hook).await);
    });
}

// ----- CoroutineStateTest (relationship cases) -----

type Scope =
    Box<dyn FnOnce(Pin<Box<dyn Future<Output = ()> + Send>>) -> Pin<Box<dyn Future<Output = ()> + Send>> + Send>;

/// `whileASiblingIsInside()`: opens a scope in one task and reads the state
/// from inside it, from the parent and from a sibling task while it is
/// open, and from the parent after it closed.
async fn while_a_sibling_is_inside(scope: Scope, read: Arc<dyn Fn() -> bool + Send + Sync>) -> [bool; 4] {
    let (entered, wait_entered) = utopia_async::sync::oneshot::channel::<()>();
    let (release, released) = utopia_async::sync::oneshot::channel::<()>();
    let inside = Arc::new(Mutex::new(None));
    let task = {
        let (inside, read) = (inside.clone(), read.clone());
        utopia_async::spawn(scope(Box::pin(async move {
            *inside.lock().unwrap() = Some(read());
            entered.send(()).unwrap();
            released.await.unwrap();
        })))
    };
    wait_entered.await.unwrap();
    let parent = read();
    let sibling = {
        let read = read.clone();
        utopia_async::spawn(async move { read() }).await.unwrap()
    };
    release.send(()).unwrap();
    task.await.unwrap();
    let after = read();
    let inside = inside.lock().unwrap().unwrap();
    [inside, parent, sibling, after]
}

async fn coroutine_fixture() -> (Database, Arc<Relationships>) {
    let db = memory().await;
    let hook = Arc::new(Relationships::default());
    db.add_hook(Hook::Relationships(hook.clone())).unwrap();
    (db, hook)
}

#[test]
fn skip_relationships_in_one_sibling_leaves_the_parent_and_another_sibling_enabled() {
    block_on(async {
        let (db, hook) = coroutine_fixture().await;
        let scope: Scope = {
            let db = db.clone();
            Box::new(move |inside| Box::pin(async move { db.skip_relationships(inside).await }))
        };
        let read = {
            let hook = hook.clone();
            Arc::new(move || hook.is_enabled())
        };
        assert_eq!(while_a_sibling_is_inside(scope, read).await, [false, true, true, true]);
    });
}

#[test]
fn skip_relationships_exist_check_in_one_sibling_leaves_the_parent_and_another_sibling_checking() {
    block_on(async {
        let (db, hook) = coroutine_fixture().await;
        let scope: Scope = {
            let db = db.clone();
            Box::new(move |inside| Box::pin(async move { db.skip_relationships_exist_check(inside).await }))
        };
        let read = {
            let hook = hook.clone();
            Arc::new(move || hook.should_check_exist())
        };
        assert_eq!(while_a_sibling_is_inside(scope, read).await, [false, true, true, true]);
    });
}

#[test]
fn population_in_one_sibling_leaves_another_sibling_free_to_populate() {
    block_on(async {
        let (db, hook) = coroutine_fixture().await;
        let mut snapshot = db.snapshot();
        snapshot.population = true;
        let scope: Scope = {
            let hook = hook.clone();
            Box::new(move |inside| Box::pin(async move { hook.with_snapshot(&snapshot, inside).await }))
        };
        let read = {
            let hook = hook.clone();
            Arc::new(move || hook.is_in_batch_population())
        };
        assert_eq!(while_a_sibling_is_inside(scope, read).await, [true, false, false, false]);
    });
}

#[test]
fn a_snapshot_carries_the_callers_state_into_a_task_that_outlives_it() {
    block_on(async {
        let (db, hook) = coroutine_fixture().await;
        let audits = Recorder::named("audits");
        db.add_hook(Hook::lifecycle(audits.clone())).unwrap();

        let snapshot =
            db.authorization().skip(db.skip_relationships(db.silent_hooks(&["audits"], async { db.snapshot() }))).await;
        let seen = {
            let (db, hook) = (db.clone(), hook.clone());
            utopia_async::spawn(async move {
                db.with_snapshot(&snapshot, async {
                    let seen = (db.authorization().status(), hook.is_enabled());
                    db.get_collection(COLLECTION).await.unwrap();
                    db.authorization().disable();
                    hook.set_enabled(false);
                    seen
                })
                .await
            })
            .await
            .unwrap()
        };
        assert_eq!(seen, (false, false));
        assert!(db.authorization().status());
        assert!(hook.is_enabled());
        assert!(audits.events().is_empty());
    });
}

#[test]
fn a_snapshot_applies_to_another_handle() {
    block_on(async {
        let (db, _hook) = coroutine_fixture().await;
        let destination = memory().await;
        let destination_hook = Arc::new(Relationships::default());
        destination.add_hook(Hook::Relationships(destination_hook.clone())).unwrap();

        let snapshot = db.authorization().skip(db.skip_relationships_exist_check(async { db.snapshot() })).await;
        let seen = destination
            .with_snapshot(&snapshot, async {
                (destination.authorization().status(), destination_hook.should_check_exist())
            })
            .await;
        assert_eq!(seen, (false, false));
        assert!(destination.authorization().status());
        assert!(destination_hook.should_check_exist());
    });
}
