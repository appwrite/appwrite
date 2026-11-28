//! PHP: `tests/unit/Event/AttributeCreateTest.php`,
//! `HookErrorCleanupTest.php`, `HookFailureTest.php` and
//! `TypedEventTest.php`, on Memory (the upsert cases wait for an adapter
//! with upserts).

use std::future::Future;
use std::pin::Pin;

use super::*;
use crate::Kind;
use crate::index::Index;
use crate::permission::PermissionType;
use crate::query::Query;
use crate::relationship::{Relationship, RelationshipDeleteAction, Update as RelationshipUpdate};

// ----- AttributeCreateTest -----

fn describe(event: &Domain) -> String {
    match event {
        Domain::AttributeCreated { collection, attribute } => format!("{collection}/{}", attribute.key),
        other => panic!("not an attribute creation: {other:?}"),
    }
}

#[test]
fn create_attributes_fires_attribute_create_per_attribute_then_attributes_create() {
    block_on(async {
        let db = memory().await;
        let recorder = Recorder::new();
        db.add_hook(Hook::lifecycle(recorder.clone())).unwrap();
        db.create_attributes(COLLECTION, vec![Attribute::string("summary", 64), Attribute::integer("likes")])
            .await
            .unwrap();
        assert_eq!(
            recorder.events(),
            vec![Event::DocumentPurge, Event::AttributeCreate, Event::AttributeCreate, Event::AttributesCreate]
        );
        let created: Vec<String> = recorder.received(Event::AttributeCreate).iter().map(describe).collect();
        assert_eq!(created, ["posts/summary", "posts/likes"]);
        let batches = recorder.received(Event::AttributesCreate);
        assert_eq!(batches.len(), 1);
        let Domain::AttributeBatchCreated { collection, attributes } = &batches[0] else { panic!("a batch") };
        assert_eq!(collection, "posts");
        assert_eq!(attributes.iter().map(|a| a.key.as_str()).collect::<Vec<_>>(), ["summary", "likes"]);
    });
}

#[test]
fn create_attribute_fires_attribute_create_once() {
    block_on(async {
        let db = memory().await;
        let recorder = Recorder::new();
        db.add_hook(Hook::lifecycle(recorder.clone())).unwrap();
        db.create_attribute(COLLECTION, Attribute::string("summary", 64)).await.unwrap();
        assert_eq!(recorder.events(), vec![Event::DocumentPurge, Event::AttributeCreate]);
        let created: Vec<String> = recorder.received(Event::AttributeCreate).iter().map(describe).collect();
        assert_eq!(created, ["posts/summary"]);
    });
}

// ----- HookErrorCleanupTest -----

const NOTES: &str = "notes";

async fn cleanup_database() -> Database {
    let cache = Arc::new(utopia_cache::Cache::from_dyn(Arc::new(utopia_cache::Memory::new())));
    let db = Database::new(Arc::new(Memory::new()), cache);
    db.set_authorization(Arc::new(Authorization::new(true)));
    db.set_database("hooks").set_namespace(&format!("hooks_{}", NAMESPACES.fetch_add(1, Ordering::Relaxed)));
    db.create(None).await.unwrap();
    db
}

fn crud() -> Vec<String> {
    let any = Role::any();
    vec![Permission::create(&any), Permission::read(&any), Permission::update(&any), Permission::delete(&any)]
}

async fn create_notes(db: &Database) {
    let notes = Collection::create(
        NOTES,
        "",
        vec![Attribute::string("name", 64)],
        Vec::new(),
        Some(crud()),
        false,
        Array::new(),
    );
    db.create_collection(notes.unwrap()).await.unwrap();
}

fn broken() -> Error {
    Error::new(Kind::TypeError, "broken hook")
}

#[test]
fn a_collection_delete_clears_the_cached_documents_when_a_hook_fails() {
    block_on(async {
        let db = cleanup_database().await;
        create_notes(&db).await;
        let one = Document::from_pairs([("$id", value::string("one")), ("name", value::string("old"))]).unwrap();
        db.create_document(NOTES, one).await.unwrap();
        db.get_document(NOTES, "one", Vec::new(), false).await.unwrap();
        db.add_hook(failing(Event::CollectionDelete, broken())).unwrap();

        let error = db.delete_collection(NOTES).await.unwrap_err();
        assert_eq!(error.kind(), Kind::TypeError);

        create_notes(&db).await;
        assert!(
            db.get_document(NOTES, "one", Vec::new(), false).await.unwrap().is_empty(),
            "the re-created collection served a document cached before the delete"
        );
    });
}

#[test]
fn a_database_delete_flushes_the_cache_when_a_hook_fails() {
    block_on(async {
        let db = cleanup_database().await;
        create_notes(&db).await;
        db.get_collection(NOTES).await.unwrap();
        db.add_hook(failing(Event::DatabaseDelete, broken())).unwrap();

        let error = db.delete(None).await.unwrap_err();
        assert_eq!(error.kind(), Kind::TypeError);

        db.create(None).await.unwrap();
        assert!(
            db.find_collection(NOTES).await.unwrap().is_none(),
            "the re-created database served collection metadata cached before the delete"
        );
    });
}

#[test]
fn a_relationship_rename_keeps_metadata_and_columns_together_when_a_hook_fails() {
    block_on(async {
        let db = cleanup_database().await;
        for id in ["parent", "child"] {
            let collection = Collection::create(id, "", Vec::new(), Vec::new(), Some(crud()), false, Array::new());
            db.create_collection(collection.unwrap()).await.unwrap();
        }
        db.create_relationship(
            "parent",
            Relationship::one_to_many("child")
                .two_way(true)
                .key("children")
                .two_way_key("parent")
                .on_delete(RelationshipDeleteAction::SetNull),
        )
        .await
        .unwrap();
        db.create_document("parent", Document::from_pairs([("$id", value::string("owner"))]).unwrap()).await.unwrap();
        let member =
            Document::from_pairs([("$id", value::string("member")), ("parent", value::string("owner"))]).unwrap();
        db.create_document("child", member).await.unwrap();
        db.add_hook(failing(Event::AttributeUpdate, broken())).unwrap();

        let update = RelationshipUpdate { two_way_key: Some("owner".into()), ..RelationshipUpdate::default() };
        let error = db.update_relationship("parent", "children", update).await.unwrap_err();
        assert_eq!(error.kind(), Kind::TypeError);

        let owner = db.get_document("child", "member", Vec::new(), false).await.unwrap();
        let owner = match owner.get("owner") {
            Some(Value::Ext(value::Ext::Document(d))) => d.id().to_owned(),
            Some(v) => value::to_text(v),
            None => String::new(),
        };
        assert_eq!(owner, "owner", "the metadata names the renamed key while the column kept its old name");
    });
}

// ----- HookFailureTest -----

type Operation = for<'a> fn(&'a Database) -> Pin<Box<dyn Future<Output = Result<()>> + Send + 'a>>;

fn document(id: &str, title: &str, views: i64) -> Document {
    Document::from_pairs([("$id", value::string(id)), ("title", value::string(title)), ("views", Value::Int(views))])
        .unwrap()
}

fn isolated() -> Vec<(&'static str, Event, Operation)> {
    vec![
        ("database list", Event::DatabaseList, |db| Box::pin(async move { db.list().await.map(|_| ()) })),
        ("collection create", Event::CollectionCreate, |db| {
            Box::pin(async move {
                let comments = Collection::create("comments", "", Vec::new(), Vec::new(), None, true, Array::new())?;
                db.create_collection(comments).await.map(|_| ())
            })
        }),
        ("collection update", Event::CollectionUpdate, |db| {
            Box::pin(async move {
                let update = crate::collection::Update {
                    permissions: Some(vec![Permission::read(&Role::any())]),
                    document_security: Some(true),
                };
                db.update_collection(COLLECTION, update).await.map(|_| ())
            })
        }),
        ("collection read", Event::CollectionRead, |db| {
            Box::pin(async move { db.get_collection(COLLECTION).await.map(|_| ()) })
        }),
        ("collection list", Event::CollectionList, |db| {
            Box::pin(async move { db.list_collections(25, 0).await.map(|_| ()) })
        }),
        ("document purge from createAttribute", Event::DocumentPurge, |db| {
            Box::pin(async move { db.create_attribute(COLLECTION, Attribute::string("summary", 64)).await.map(|_| ()) })
        }),
        ("attribute create", Event::AttributeCreate, |db| {
            Box::pin(async move { db.create_attribute(COLLECTION, Attribute::string("summary", 64)).await.map(|_| ()) })
        }),
        ("attributes create", Event::AttributesCreate, |db| {
            Box::pin(async move {
                db.create_attributes(COLLECTION, vec![Attribute::string("summary", 64)]).await.map(|_| ())
            })
        }),
        ("attribute update", Event::AttributeUpdate, |db| {
            Box::pin(async move {
                let update = crate::attribute::Update { required: Some(true), ..Default::default() };
                db.update_attribute(COLLECTION, "title", update).await.map(|_| ())
            })
        }),
        ("attribute delete", Event::AttributeDelete, |db| {
            Box::pin(async move { db.delete_attribute(COLLECTION, "views").await })
        }),
        ("index rename", Event::IndexRename, |db| {
            Box::pin(async move {
                db.create_index(COLLECTION, Index::key("by_title", ["title"], Vec::new(), Vec::new())?).await?;
                db.rename_index(COLLECTION, "by_title", "by_heading").await
            })
        }),
        ("index delete", Event::IndexDelete, |db| {
            Box::pin(async move {
                db.create_index(COLLECTION, Index::key("by_title", ["title"], Vec::new(), Vec::new())?).await?;
                db.delete_index(COLLECTION, "by_title").await
            })
        }),
        ("collection delete", Event::CollectionDelete, |db| {
            Box::pin(async move { db.delete_collection(COLLECTION).await })
        }),
    ]
}

fn propagating() -> Vec<(&'static str, Event, Operation)> {
    vec![
        ("index create", Event::IndexCreate, |db| {
            Box::pin(async move {
                db.create_index(COLLECTION, Index::key("by_title", ["title"], Vec::new(), Vec::new())?)
                    .await
                    .map(|_| ())
            })
        }),
        ("document read", Event::DocumentRead, |db| {
            Box::pin(async move { db.get_document(COLLECTION, "first", Vec::new(), false).await.map(|_| ()) })
        }),
        ("document create", Event::DocumentCreate, |db| {
            Box::pin(async move { db.create_document(COLLECTION, document("second", "second", 2)).await.map(|_| ()) })
        }),
        ("documents create", Event::DocumentsCreate, |db| {
            Box::pin(async move {
                db.create_documents(COLLECTION, vec![document("second", "second", 2)], super::super::BATCH_SIZE, None)
                    .await
                    .map(|_| ())
            })
        }),
        ("document update", Event::DocumentUpdate, |db| {
            Box::pin(async move {
                let renamed = Document::from_pairs([("title", value::string("renamed"))])?;
                db.update_document(COLLECTION, "first", renamed).await.map(|_| ())
            })
        }),
        ("documents update", Event::DocumentsUpdate, |db| {
            Box::pin(async move {
                let updates = Document::from_pairs([("views", Value::Int(10))])?;
                db.update_documents(COLLECTION, updates, Vec::new(), super::super::BATCH_SIZE, None).await.map(|_| ())
            })
        }),
        ("document increase", Event::DocumentIncrease, |db| {
            Box::pin(async move {
                db.increase_document_attribute(COLLECTION, "first", "views", Value::Int(1), None).await.map(|_| ())
            })
        }),
        ("document decrease", Event::DocumentDecrease, |db| {
            Box::pin(async move {
                db.decrease_document_attribute(COLLECTION, "first", "views", Value::Int(1), None).await.map(|_| ())
            })
        }),
        ("document delete", Event::DocumentDelete, |db| {
            Box::pin(async move { db.delete_document(COLLECTION, "first").await.map(|_| ()) })
        }),
        ("documents delete", Event::DocumentsDelete, |db| {
            Box::pin(async move {
                db.delete_documents(COLLECTION, Vec::new(), super::super::BATCH_SIZE, None).await.map(|_| ())
            })
        }),
        ("document find", Event::DocumentFind, |db| {
            Box::pin(async move { db.find(COLLECTION, Vec::new(), PermissionType::Read).await.map(|_| ()) })
        }),
        ("document find one", Event::DocumentFind, |db| {
            Box::pin(async move { db.find_one(COLLECTION, Vec::new()).await.map(|_| ()) })
        }),
        ("document count", Event::DocumentCount, |db| {
            Box::pin(async move { db.count(COLLECTION, Vec::new(), None).await.map(|_| ()) })
        }),
        ("document sum", Event::DocumentSum, |db| {
            Box::pin(async move { db.sum(COLLECTION, "views", Vec::new(), None).await.map(|_| ()) })
        }),
    ]
}

async fn failure_database() -> Database {
    let db = memory().await;
    seed(&db, &["first"]).await;
    db
}

#[test]
fn an_isolated_event_swallows_the_hook_failure_and_runs_later_hooks() {
    for (name, event, operation) in isolated() {
        block_on(async {
            let db = failure_database().await;
            let later = Recorder::new();
            db.add_hook(failing(event, Error::new(Kind::Runtime, "isolated"))).unwrap();
            db.add_hook(Hook::lifecycle(later.clone())).unwrap();
            operation(&db).await.unwrap_or_else(|e| panic!("{name}: {}", e.message()));
            assert!(later.events().contains(&event), "{name}");
        });
    }
}

#[test]
fn a_propagating_event_surfaces_the_first_hook_failure() {
    for (name, event, operation) in propagating() {
        block_on(async {
            let db = failure_database().await;
            let later = Recorder::new();
            db.add_hook(failing(event, Error::new(Kind::Runtime, "propagated"))).unwrap();
            db.add_hook(Hook::lifecycle(later.clone())).unwrap();
            let error = operation(&db).await.expect_err(name);
            assert_eq!((error.kind(), error.message()), (Kind::Runtime, "propagated"), "{name}");
            assert!(!later.events().contains(&event), "{name}");
        });
    }
}

#[test]
fn an_engine_error_always_surfaces() {
    for (name, event, operation) in isolated().into_iter().chain(propagating()) {
        block_on(async {
            let db = failure_database().await;
            db.add_hook(failing(event, broken())).unwrap();
            let error = operation(&db).await.expect_err(name);
            assert_eq!((error.kind(), error.message()), (Kind::TypeError, "broken hook"), "{name}");
        });
    }
}

// ----- TypedEventTest -----

async fn typed() -> (Database, Arc<Recorder>) {
    let db = memory().await;
    let recorder = Recorder::new();
    db.add_hook(Hook::lifecycle(recorder.clone())).unwrap();
    (db, recorder)
}

/// `only()`: the one event of `kind` received.
#[track_caller]
fn only(recorder: &Recorder, kind: Event) -> Domain {
    let received = recorder.received(kind);
    assert_eq!(received.len(), 1, "{} fired {} times", kind.as_str(), received.len());
    received.into_iter().next().unwrap()
}

#[test]
fn every_case_a_hook_receives_names_a_domain_event() {
    let unhooked = [Event::All, Event::PermissionsCreate, Event::PermissionsRead, Event::PermissionsDelete];
    for case in Event::CASES {
        if unhooked.contains(case) {
            assert_eq!(case.domain(), None, "{} names an event class", case.as_str());
        } else {
            assert!(case.domain().is_some(), "{} has no event class", case.as_str());
        }
    }
}

#[test]
fn database_events() {
    block_on(async {
        let (db, recorder) = typed().await;
        db.list().await.unwrap();
        db.update("hooks", "renamed").await.unwrap();
        db.delete(Some("renamed")).await.unwrap();

        assert_eq!(recorder.events(), vec![Event::DatabaseList, Event::DatabaseUpdate, Event::DatabaseDelete]);
        assert!(matches!(only(&recorder, Event::DatabaseList), Domain::DatabaseListed { .. }));
        let Domain::DatabaseUpdated { database, new } = only(&recorder, Event::DatabaseUpdate) else { panic!() };
        assert_eq!((database.as_str(), new.as_str()), ("hooks", "renamed"));
        let Domain::DatabaseDeleted { database } = only(&recorder, Event::DatabaseDelete) else { panic!() };
        assert_eq!(database, "renamed");
    });
}

#[test]
fn collection_events() {
    block_on(async {
        let (db, recorder) = typed().await;
        let comments = Collection::create("comments", "", Vec::new(), Vec::new(), None, true, Array::new()).unwrap();
        db.create_collection(comments).await.unwrap();
        db.get_collection("comments").await.unwrap();
        db.update_collection(
            "comments",
            crate::collection::Update { document_security: Some(true), ..Default::default() },
        )
        .await
        .unwrap();
        let collections = db.list_collections(25, 0).await.unwrap();
        db.delete_collection("comments").await.unwrap();

        let Domain::CollectionCreated { collection, definition } = only(&recorder, Event::CollectionCreate) else {
            panic!()
        };
        assert_eq!((collection.as_str(), definition.id()), ("comments", "comments"));
        let Domain::CollectionRead { definition, .. } = only(&recorder, Event::CollectionRead) else { panic!() };
        assert_eq!(definition.id(), "comments");
        let Domain::CollectionUpdated { definition, .. } = only(&recorder, Event::CollectionUpdate) else { panic!() };
        assert!(definition.document_security());
        let Domain::CollectionListed { collections: listed } = only(&recorder, Event::CollectionList) else { panic!() };
        assert_eq!(listed, collections);
        let Domain::CollectionDeleted { collection, .. } = only(&recorder, Event::CollectionDelete) else { panic!() };
        assert_eq!(collection, "comments");
    });
}

#[test]
fn attribute_events() {
    block_on(async {
        let (db, recorder) = typed().await;
        db.create_attribute(COLLECTION, Attribute::string("summary", 64)).await.unwrap();
        db.create_attributes(COLLECTION, vec![Attribute::integer("likes")]).await.unwrap();
        db.update_attribute(
            COLLECTION,
            "likes",
            crate::attribute::Update { required: Some(true), ..Default::default() },
        )
        .await
        .unwrap();
        db.rename_attribute(COLLECTION, "summary", "abstract").await.unwrap();
        db.delete_attribute(COLLECTION, "abstract").await.unwrap();

        let created: Vec<String> = recorder
            .received(Event::AttributeCreate)
            .iter()
            .map(|e| match e {
                Domain::AttributeCreated { attribute, .. } => attribute.key.clone(),
                _ => String::new(),
            })
            .collect();
        assert_eq!(created, ["summary", "likes"]);
        let Domain::AttributeBatchCreated { collection, attributes } = only(&recorder, Event::AttributesCreate) else {
            panic!()
        };
        assert_eq!(collection, COLLECTION);
        assert_eq!(attributes.iter().map(|a| a.key.as_str()).collect::<Vec<_>>(), ["likes"]);
        let Domain::AttributeUpdated { attribute, .. } = only(&recorder, Event::AttributeUpdate) else { panic!() };
        assert_eq!((attribute.key.as_str(), attribute.required), ("likes", true));
        let Domain::AttributeRenamed { collection, old, attribute } = only(&recorder, Event::AttributeRename) else {
            panic!()
        };
        assert_eq!((collection.as_str(), old.as_str(), attribute.key.as_str()), (COLLECTION, "summary", "abstract"));
        let Domain::AttributeDeleted { attribute, .. } = only(&recorder, Event::AttributeDelete) else { panic!() };
        assert_eq!(attribute.key, "abstract");
    });
}

#[test]
fn index_events() {
    block_on(async {
        let (db, recorder) = typed().await;
        let key =
            |key: &str, attributes: &[&str]| Index::key(key, attributes.to_vec(), Vec::new(), Vec::new()).unwrap();
        db.create_index(COLLECTION, key("byTitle", &["title"])).await.unwrap();
        db.create_indexes(COLLECTION, vec![key("byViews", &["views"]), key("byBoth", &["title", "views"])])
            .await
            .unwrap();
        db.rename_index(COLLECTION, "byTitle", "titles").await.unwrap();
        db.delete_index(COLLECTION, "titles").await.unwrap();

        let created: Vec<String> = recorder
            .received(Event::IndexCreate)
            .iter()
            .map(|e| match e {
                Domain::IndexCreated { index, .. } => index.key.clone(),
                _ => String::new(),
            })
            .collect();
        assert_eq!(created, ["byTitle", "byViews", "byBoth"]);
        let Domain::IndexBatchCreated { indexes, .. } = only(&recorder, Event::IndexesCreate) else { panic!() };
        assert_eq!(indexes.iter().map(|i| i.key.as_str()).collect::<Vec<_>>(), ["byViews", "byBoth"]);
        let Domain::IndexRenamed { old, index, .. } = only(&recorder, Event::IndexRename) else { panic!() };
        assert_eq!((old.as_str(), index.key.as_str()), ("byTitle", "titles"));
        let Domain::IndexDeleted { collection, index } = only(&recorder, Event::IndexDelete) else { panic!() };
        assert_eq!((collection.as_str(), index.key.as_str()), (COLLECTION, "titles"));
    });
}

#[test]
fn document_events() {
    block_on(async {
        let (db, recorder) = typed().await;
        db.create_document(COLLECTION, document("first", "first", 1)).await.unwrap();
        db.get_document(COLLECTION, "first", Vec::new(), false).await.unwrap();
        db.update_document(COLLECTION, "first", Document::from_pairs([("title", value::string("renamed"))]).unwrap())
            .await
            .unwrap();
        db.create_document(COLLECTION, document("second", "second", 2)).await.unwrap();
        db.increase_document_attribute(COLLECTION, "first", "views", Value::Int(2), None).await.unwrap();
        db.decrease_document_attribute(COLLECTION, "first", "views", Value::Int(1), None).await.unwrap();
        db.find(COLLECTION, vec![Query::order_asc("title", None)], PermissionType::Read).await.unwrap();
        db.count(COLLECTION, Vec::new(), None).await.unwrap();
        db.sum(COLLECTION, "views", Vec::new(), None).await.unwrap();
        db.delete_document(COLLECTION, "second").await.unwrap();

        let created = recorder.received(Event::DocumentCreate);
        let Domain::DocumentCreated { document, .. } = &created[0] else { panic!() };
        assert_eq!(document.id(), "first");
        let Domain::DocumentRead { document, .. } = only(&recorder, Event::DocumentRead) else { panic!() };
        assert_eq!(document.id(), "first");
        let Domain::DocumentUpdated { document, .. } = only(&recorder, Event::DocumentUpdate) else { panic!() };
        assert_eq!(document.get("title"), Some(&value::string("renamed")));
        let Domain::DocumentIncreased { attribute, document, .. } = only(&recorder, Event::DocumentIncrease) else {
            panic!()
        };
        assert_eq!((attribute.as_str(), document.get("views")), ("views", Some(&Value::Int(3))));
        let Domain::DocumentDecreased { attribute, document, .. } = only(&recorder, Event::DocumentDecrease) else {
            panic!()
        };
        assert_eq!((attribute.as_str(), document.get("views")), ("views", Some(&Value::Int(2))));
        let Domain::DocumentFound { documents, .. } = only(&recorder, Event::DocumentFind) else { panic!() };
        assert_eq!(documents.iter().map(Document::id).collect::<Vec<_>>(), ["first", "second"]);
        let Domain::DocumentCounted { collection, count } = only(&recorder, Event::DocumentCount) else { panic!() };
        assert_eq!((collection.as_str(), count), (COLLECTION, 2));
        let Domain::DocumentSummed { attribute, sum, .. } = only(&recorder, Event::DocumentSum) else { panic!() };
        assert_eq!((attribute.as_str(), sum), ("views", Value::Int(4)));
        let Domain::DocumentDeleted { collection, document } = only(&recorder, Event::DocumentDelete) else { panic!() };
        assert_eq!(
            (collection.as_str(), document.id(), document.get("views")),
            (COLLECTION, "second", Some(&Value::Int(2)))
        );
        let purged: Vec<String> = recorder
            .received(Event::DocumentPurge)
            .iter()
            .map(|e| match e {
                Domain::DocumentPurged { collection, id } => format!("{collection}/{id}"),
                _ => String::new(),
            })
            .collect();
        assert!(purged.contains(&format!("{COLLECTION}/second")));
    });
}

#[test]
fn no_event_is_built_for_an_event_no_hook_handles() {
    block_on(async {
        let db = memory().await;
        let selective = Arc::new(Selecting::default());
        db.add_hook(Hook::lifecycle(selective.clone())).unwrap();
        seed(&db, &["first"]).await;
        db.get_document(COLLECTION, "first", Vec::new(), false).await.unwrap();
        db.find(COLLECTION, Vec::new(), PermissionType::Read).await.unwrap();
        assert_eq!(*selective.0.lock().unwrap(), vec![Event::DocumentCreate]);
    });
}
