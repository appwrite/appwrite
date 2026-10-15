//! PHP: `tests/unit/Relationships/DeleteRelatedUpdateTest.php` (the Memory
//! cases). `DeleteRelatedUpdateRetention` watches whether a delete keeps the
//! documents of earlier chunks alive through PHP's garbage collector; Rust
//! drops each chunk when it is done, so only the writes it counts are
//! checked here.

use std::sync::atomic::{AtomicUsize, Ordering};

use super::*;
use crate::database::spy::Spy;
use crate::event::{Domain, Event};
use crate::relationship::RelationshipDeleteAction as OnDelete;

fn document_permissions() -> Vec<String> {
    let any = Role::any();
    vec![Permission::read(&any), Permission::update(&any), Permission::delete(&any)]
}

async fn database_over(adapter: Arc<dyn crate::adapter::Adapter>) -> Database {
    let db = database_on(adapter, "related_update").await;
    db.add_hook(Hook::Relationships(Arc::new(Relationships::default()))).unwrap();
    db.add_hook(Hook::write(Arc::new(Permissions::new()))).unwrap();
    db
}

async fn database() -> Database {
    database_over(Arc::new(Memory::new())).await
}

async fn create_collections(db: &Database, ids: &[&str]) {
    for id in ids {
        collection(db, id, Vec::new(), crud(), true).await;
    }
}

async fn relate_parent_to_children(db: &Database, on_delete: OnDelete) {
    create_collections(db, &["parent", "child"]).await;
    relate(
        db,
        "parent",
        Relationship::one_to_many("child").two_way(true).key("children").two_way_key("parent").on_delete(on_delete),
    )
    .await;
}

fn owned(id: &str) -> Document {
    doc(&[("$id", s(id)), ("$permissions", perms(&document_permissions()))])
}

async fn create_family(db: &Database, parent: &str, children: &[&str]) {
    for child in children {
        create(db, "child", owned(child)).await;
    }
    let mut parent = owned(parent);
    parent.put("children", strs(children));
    create(db, "parent", parent).await;
}

/// `updated()`: the documents of the update events received.
fn updated(recorder: &Recorder) -> Vec<(String, Document)> {
    recorder
        .received(Event::DocumentUpdate)
        .into_iter()
        .map(|event| match event {
            Domain::DocumentUpdated { collection, document } => (collection, document),
            other => panic!("not an update: {other:?}"),
        })
        .collect()
}

/// `reported()`: the sorted ids of the updated documents.
fn reported(recorder: &Recorder) -> Vec<String> {
    let mut ids: Vec<String> = updated(recorder).iter().map(|(_, d)| d.id().to_owned()).collect();
    ids.sort();
    ids
}

#[test]
fn deleting_a_parent_reports_each_child_as_the_set_null_wrote_it() {
    block_on(async {
        let db = database().await;
        relate_parent_to_children(&db, OnDelete::SetNull).await;
        create_family(&db, "parent1", &["child1", "child2"]).await;

        let recorder = Recorder::on(&db);
        assert!(db.delete_document("parent", "parent1").await.unwrap());

        assert_eq!(reported(&recorder), names(&["child1", "child2"]));
        for (collection, related) in updated(&recorder) {
            assert_eq!(collection, "child");
            assert_eq!(related.collection(), "child");
            assert!(related.has("parent"));
            assert_eq!(related.get("parent"), None);
            let stored = get(&db, "child", related.id()).await;
            assert_eq!(stored.updated_at(), related.updated_at());
        }
    });
}

#[test]
fn deleting_a_child_reports_the_parent_it_never_wrote() {
    block_on(async {
        let db = database().await;
        relate_parent_to_children(&db, OnDelete::SetNull).await;
        create_family(&db, "parent1", &["child1", "child2"]).await;

        let recorder = Recorder::on(&db);
        db.delete_document("child", "child1").await.unwrap();

        assert_eq!(reported(&recorder), names(&["parent1"]));
        assert_eq!(updated(&recorder)[0].1.collection(), "parent");
    });
}

async fn owner_setup(db: &Database) {
    collection(db, "owner", Vec::new(), crud(), true).await;
    relate(
        db,
        "parent",
        Relationship::many_to_one("owner").two_way(true).key("owner").two_way_key("owned").on_delete(OnDelete::SetNull),
    )
    .await;
    create(db, "owner", owned("owner1")).await;
    create(db, "child", owned("child1")).await;
    let mut parent = owned("parent1");
    parent.put("children", strs(&["child1"]));
    parent.put("owner", s("owner1"));
    create(db, "parent", parent).await;
}

#[test]
fn a_cascade_reports_only_the_peers_it_did_not_remove() {
    block_on(async {
        let db = database().await;
        relate_parent_to_children(&db, OnDelete::Cascade).await;
        owner_setup(&db).await;

        let recorder = Recorder::on(&db);
        db.delete_document("parent", "parent1").await.unwrap();

        assert_eq!(reported(&recorder), names(&["owner1"]));
        assert!(get(&db, "child", "child1").await.is_empty());
    });
}

#[test]
fn deleting_a_child_under_restrict_reports_its_parent() {
    block_on(async {
        let db = database().await;
        relate_parent_to_children(&db, OnDelete::Restrict).await;
        create_family(&db, "parent1", &["child1"]).await;

        let recorder = Recorder::on(&db);
        db.delete_document("child", "child1").await.unwrap();
        assert_eq!(reported(&recorder), names(&["parent1"]));
    });
}

#[test]
fn deleting_a_many_to_many_side_reports_the_peers() {
    block_on(async {
        let db = database().await;
        create_collections(&db, &["parent", "child"]).await;
        relate(
            &db,
            "parent",
            Relationship::many_to_many("child")
                .two_way(true)
                .key("children")
                .two_way_key("parents")
                .on_delete(OnDelete::SetNull),
        )
        .await;
        create_family(&db, "parent1", &["child1", "child2"]).await;

        let recorder = Recorder::on(&db);
        db.delete_document("parent", "parent1").await.unwrap();
        assert_eq!(reported(&recorder), names(&["child1", "child2"]));
    });
}

#[test]
fn deleting_a_one_to_one_side_reports_its_partner() {
    block_on(async {
        let db = database().await;
        create_collections(&db, &["parent", "child"]).await;
        relate(
            &db,
            "parent",
            Relationship::one_to_one("child")
                .two_way(true)
                .key("partner")
                .two_way_key("partnerOf")
                .on_delete(OnDelete::SetNull),
        )
        .await;
        create(&db, "child", owned("child1")).await;
        let mut parent = owned("parent1");
        parent.put("partner", s("child1"));
        create(&db, "parent", parent).await;

        let recorder = Recorder::on(&db);
        db.delete_document("parent", "parent1").await.unwrap();
        assert_eq!(reported(&recorder), names(&["child1"]));
    });
}

#[test]
fn one_way_peers_are_not_reported_while_two_way_peers_are() {
    block_on(async {
        let db = database().await;
        create_collections(&db, &["parent", "stray", "child"]).await;
        relate(&db, "parent", Relationship::one_to_many("stray").key("strays").on_delete(OnDelete::SetNull)).await;
        relate(
            &db,
            "parent",
            Relationship::many_to_one("stray").key("stray").two_way_key("strayOf").on_delete(OnDelete::SetNull),
        )
        .await;
        create(&db, "stray", owned("stray1")).await;
        relate(
            &db,
            "parent",
            Relationship::one_to_many("child")
                .two_way(true)
                .key("children")
                .two_way_key("parent")
                .on_delete(OnDelete::SetNull),
        )
        .await;
        create(&db, "child", owned("child1")).await;
        let mut parent = owned("parent1");
        parent.put("strays", strs(&["stray1"]));
        parent.put("stray", s("stray1"));
        parent.put("children", strs(&["child1"]));
        create(&db, "parent", parent).await;

        let recorder = Recorder::on(&db);
        db.delete_document("parent", "parent1").await.unwrap();
        assert_eq!(reported(&recorder), names(&["child1"]));
        assert!(!get(&db, "stray", "stray1").await.is_empty());
    });
}

#[test]
fn a_peer_cascaded_away_by_another_relationship_is_not_reported_while_a_survivor_is() {
    block_on(async {
        let db = database().await;
        relate_parent_to_children(&db, OnDelete::SetNull).await;
        collection(&db, "pair", Vec::new(), crud(), true).await;
        relate(
            &db,
            "parent",
            Relationship::many_to_one("pair")
                .two_way(true)
                .key("owner")
                .two_way_key("owned")
                .on_delete(OnDelete::SetNull),
        )
        .await;
        relate(
            &db,
            "parent",
            Relationship::one_to_one("pair")
                .two_way(true)
                .key("buddy")
                .two_way_key("buddyOf")
                .on_delete(OnDelete::Cascade),
        )
        .await;
        create(&db, "pair", owned("pair1")).await;
        create(&db, "child", owned("child1")).await;
        let mut parent = owned("parent1");
        parent.put("owner", s("pair1"));
        parent.put("buddy", s("pair1"));
        parent.put("children", strs(&["child1"]));
        create(&db, "parent", parent).await;

        let recorder = Recorder::on(&db);
        db.delete_document("parent", "parent1").await.unwrap();
        assert_eq!(reported(&recorder), names(&["child1"]));
        assert!(get(&db, "pair", "pair1").await.is_empty());
    });
}

#[test]
fn a_peer_removed_down_a_cascade_chain_is_not_reported_while_its_sibling_is() {
    block_on(async {
        let db = database().await;
        relate_parent_to_children(&db, OnDelete::SetNull).await;
        collection(&db, "pair", Vec::new(), crud(), true).await;
        relate(
            &db,
            "parent",
            Relationship::one_to_one("pair")
                .two_way(true)
                .key("buddy")
                .two_way_key("buddyOf")
                .on_delete(OnDelete::Cascade),
        )
        .await;
        relate(
            &db,
            "pair",
            Relationship::one_to_one("child")
                .two_way(true)
                .key("tail")
                .two_way_key("tailOf")
                .on_delete(OnDelete::Cascade),
        )
        .await;
        for child in ["child1", "child2"] {
            create(&db, "child", owned(child)).await;
        }
        let mut pair = owned("pair1");
        pair.put("tail", s("child1"));
        create(&db, "pair", pair).await;
        let mut parent = owned("parent1");
        parent.put("children", strs(&["child1", "child2"]));
        parent.put("buddy", s("pair1"));
        create(&db, "parent", parent).await;

        let recorder = Recorder::on(&db);
        db.delete_document("parent", "parent1").await.unwrap();
        assert_eq!(reported(&recorder), names(&["child2"]));
        assert!(get(&db, "child", "child1").await.is_empty());
    });
}

#[test]
fn related_updates_fire_after_the_delete() {
    block_on(async {
        let db = database().await;
        relate_parent_to_children(&db, OnDelete::SetNull).await;
        create_family(&db, "parent1", &["child1"]).await;

        let recorder = Recorder::on(&db);
        db.delete_document("parent", "parent1").await.unwrap();
        assert_eq!(recorder.events(), vec![Event::DocumentPurge, Event::DocumentDelete, Event::DocumentUpdate]);
    });
}

#[test]
fn a_silent_delete_reports_nothing_while_a_heard_one_does() {
    block_on(async {
        let db = database().await;
        relate_parent_to_children(&db, OnDelete::SetNull).await;
        create_family(&db, "parent1", &["child1"]).await;
        create_family(&db, "parent2", &["child2"]).await;

        let recorder = Recorder::on(&db);
        db.silent(db.delete_document("parent", "parent1")).await.unwrap();
        assert!(recorder.events().is_empty());
        assert_eq!(get(&db, "child", "child1").await.get("parent"), None);

        db.delete_document("parent", "parent2").await.unwrap();
        assert_eq!(reported(&recorder), names(&["child2"]));
    });
}

#[test]
fn a_bulk_delete_reports_no_related_updates_while_a_single_delete_does() {
    block_on(async {
        let db = database().await;
        relate_parent_to_children(&db, OnDelete::SetNull).await;
        create_family(&db, "parent1", &["child1"]).await;
        create_family(&db, "parent2", &["child2"]).await;

        let recorder = Recorder::on(&db);
        assert_eq!(delete_all(&db, "parent", vec![Query::equal("$id", ["parent1"])]).await.unwrap(), 1);
        assert!(reported(&recorder).is_empty());

        db.delete_document("parent", "parent2").await.unwrap();
        assert_eq!(reported(&recorder), names(&["child2"]));
    });
}

#[test]
fn one_failing_report_does_not_cost_the_others_theirs() {
    block_on(async {
        let db = database().await;
        relate_parent_to_children(&db, OnDelete::SetNull).await;
        create_family(&db, "parent1", &["child1", "child2"]).await;

        let recorder = Recorder::on(&db);
        let failure_error = Error::new(Kind::Runtime, "related update hook failed");
        db.add_hook(Hook::lifecycle(Arc::new(Failing(Event::DocumentUpdate, failure_error)))).unwrap();

        let error = failure(db.delete_document("parent", "parent1").await, Kind::Runtime);
        assert_eq!(error.message(), "related update hook failed");
        assert_eq!(reported(&recorder), names(&["child1", "child2"]));
        assert!(get(&db, "parent", "parent1").await.is_empty());
    });
}

#[test]
fn a_failing_delete_hook_does_not_cost_the_related_updates() {
    block_on(async {
        let db = database().await;
        relate_parent_to_children(&db, OnDelete::SetNull).await;
        create_family(&db, "parent1", &["child1"]).await;

        let failure_error = Error::new(Kind::Runtime, "delete hook failed");
        db.add_hook(Hook::lifecycle(Arc::new(Failing(Event::DocumentDelete, failure_error)))).unwrap();
        let recorder = Recorder::on(&db);

        let error = failure(db.delete_document("parent", "parent1").await, Kind::Runtime);
        assert_eq!(error.message(), "delete hook failed");
        assert_eq!(reported(&recorder), names(&["child1"]));
    });
}

/// A Memory adapter counting the children each `updateDocuments()` writes
/// (`DeleteRelatedUpdateRetention::$written`).
fn watched() -> (Arc<Spy>, Arc<AtomicUsize>) {
    let spy = Spy::memory();
    let written = Arc::new(AtomicUsize::new(0));
    let counted = written.clone();
    spy.watch(|w| {
        w.update_documents = Some(Arc::new(move |(collection, documents): (Collection, Vec<Document>)| {
            if collection.id() == "child" {
                counted.fetch_add(documents.len(), Ordering::SeqCst);
            }
        }));
    });
    (spy, written)
}

async fn child_ids(db: &Database, parent: &str) -> Vec<String> {
    related_ids(&get(db, "parent", parent).await, "children")
}

#[test]
fn a_set_null_delete_nobody_hears_clears_every_chunk_of_peers() {
    block_on(async {
        let (spy, written) = watched();
        let db = database_over(spy).await;
        relate_parent_to_children(&db, OnDelete::SetNull).await;
        create_family(&db, "parent1", &["child1", "child2", "child3"]).await;
        let deleted = Arc::new(std::sync::Mutex::new(Vec::new()));
        struct Deletes(Arc<std::sync::Mutex<Vec<String>>>);
        #[async_trait::async_trait]
        impl crate::hook::Lifecycle for Deletes {
            async fn handle(&self, event: &Domain) -> Result<()> {
                if let Domain::DocumentDeleted { document, .. } = event {
                    self.0.lock().unwrap().push(document.id().to_owned());
                }
                Ok(())
            }
            fn selective(&self) -> Option<&dyn crate::hook::Selective> {
                Some(self)
            }
        }
        impl crate::hook::Selective for Deletes {
            fn handles(&self, event: Event) -> bool {
                event == Event::DocumentDelete
            }
        }
        db.add_hook(Hook::lifecycle(Arc::new(Deletes(deleted.clone())))).unwrap();
        db.set_max_query_values(1);
        assert_eq!(child_ids(&db, "parent1").await, names(&["child1", "child2", "child3"]));

        written.store(0, Ordering::SeqCst);
        assert!(db.delete_document("parent", "parent1").await.unwrap());

        assert_eq!(written.load(Ordering::SeqCst), 3, "every child is cleared");
        assert_eq!(*deleted.lock().unwrap(), names(&["parent1"]));
        for child in ["child1", "child2", "child3"] {
            assert_eq!(get(&db, "child", child).await.get("parent"), None, "{child}");
        }
    });
}

#[test]
fn a_set_null_bulk_delete_clears_every_chunk_of_peers() {
    block_on(async {
        let (spy, written) = watched();
        let db = database_over(spy).await;
        relate_parent_to_children(&db, OnDelete::SetNull).await;
        create_family(&db, "parent1", &["child1", "child2", "child3"]).await;
        Recorder::on(&db);
        db.set_max_query_values(1);

        written.store(0, Ordering::SeqCst);
        assert_eq!(delete_all(&db, "parent", vec![Query::equal("$id", ["parent1"])]).await.unwrap(), 1);

        assert_eq!(written.load(Ordering::SeqCst), 3, "every child is cleared");
        for child in ["child1", "child2", "child3"] {
            assert_eq!(get(&db, "child", child).await.get("parent"), None, "{child}");
        }
    });
}

#[test]
fn a_set_null_delete_someone_hears_reports_every_chunks_peers() {
    block_on(async {
        let (spy, written) = watched();
        let db = database_over(spy).await;
        relate_parent_to_children(&db, OnDelete::SetNull).await;
        create_family(&db, "parent1", &["child1", "child2", "child3"]).await;
        let recorder = Recorder::on(&db);
        db.set_max_query_values(1);

        written.store(0, Ordering::SeqCst);
        assert!(db.delete_document("parent", "parent1").await.unwrap());

        assert_eq!(written.load(Ordering::SeqCst), 3, "every child is cleared");
        assert_eq!(reported(&recorder), names(&["child1", "child2", "child3"]));
        for (_, related) in updated(&recorder) {
            assert_eq!(related.get("parent"), None);
        }
    });
}

#[test]
fn a_commit_that_fails_and_retries_reports_each_peer_once() {
    block_on(async {
        let spy = Spy::memory();
        let fail_next = Arc::new(std::sync::atomic::AtomicBool::new(false));
        let armed = fail_next.clone();
        spy.watch(|w| {
            w.commit = Some(Arc::new(move |depth| {
                (depth == 1 && armed.swap(false, Ordering::SeqCst))
                    .then(|| Error::transaction("Failed to commit transaction: commit failed"))
            }));
        });
        let db = database_over(spy).await;
        relate_parent_to_children(&db, OnDelete::SetNull).await;
        create_family(&db, "parent1", &["child1", "child2"]).await;

        let recorder = Recorder::on(&db);
        fail_next.store(true, Ordering::SeqCst);
        assert!(db.delete_document("parent", "parent1").await.unwrap());

        assert!(!fail_next.load(Ordering::SeqCst));
        assert_eq!(reported(&recorder), names(&["child1", "child2"]));
    });
}
