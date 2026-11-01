//! The mirror on two Memory databases (PHP: `tests/unit/MirrorTest.php`,
//! `tests/unit/Mirror/*`).

use std::sync::{Arc, Mutex};

use super::{Failure, Mirror};
use crate::attribute::{self, Attribute, Patch};
use crate::collection::{self, Collection};
use crate::database::Hook;
use crate::database::testing::block_on;
use crate::database::testing_memory::{crud, memory};
use crate::document::Document;
use crate::error::Kind;
use crate::event::Event;
use crate::hook::relationships::Relationships;
use crate::index::Index;
use crate::query::Query;
use crate::value::Value;

fn people() -> Collection {
    Collection::create(
        "people",
        "",
        vec![Attribute::string("name", 64), Attribute::integer("age")],
        vec![],
        Some(crud()),
        false,
        Default::default(),
    )
    .unwrap()
}

fn person(id: &str, name: &str, age: i64) -> Document {
    let mut document = Document::default();
    document.put("$id", Value::from(id)).put("name", Value::from(name)).put("age", Value::Int(age));
    document
}

/// A mirror of two Memory databases, recording what it reports.
async fn mirror() -> (Mirror, Arc<Mutex<Vec<(String, Option<Event>, Kind)>>>) {
    let source = memory("source").await;
    let destination = memory("destination").await;
    let mirror = Mirror::new(source, Some(destination), Vec::new());
    mirror.add_hook(Hook::Relationships(Arc::new(Relationships::new(true)))).unwrap();
    let failures = Arc::new(Mutex::new(Vec::new()));
    let recorded = failures.clone();
    mirror.on_error(Arc::new(move |f: &Failure| {
        recorded.lock().unwrap().push((f.method.clone(), f.event, f.error.kind()));
        Ok(())
    }));
    (mirror, failures)
}

#[test]
fn a_mirror_is_a_database_that_knows_its_parts() {
    block_on(async {
        let (mirror, _) = mirror().await;
        let database: crate::Database = mirror.clone().into();
        let found = Mirror::of(&database).expect("a mirror");
        assert!(found.source().same(mirror.source()));
        assert!(found.destination().unwrap().same(mirror.destination().unwrap()));
        assert!(Mirror::of(mirror.source()).is_none());
        assert!(mirror.authorization().status());
        assert!(Arc::ptr_eq(&mirror.authorization(), &mirror.source().authorization()));
    });
}

#[test]
fn settings_reach_the_source_and_the_destination() {
    block_on(async {
        let (mirror, _) = mirror().await;
        mirror.set_database("renamed");
        mirror.set_namespace("spaced");
        assert_eq!(mirror.source().database(), "renamed");
        assert_eq!(mirror.destination().unwrap().database(), "renamed");
        assert_eq!(mirror.destination().unwrap().namespace(), "spaced");
        mirror.set_validation(false);
        assert!(!mirror.source().is_validating());
        assert!(!mirror.destination().unwrap().is_validating());
    });
}

#[test]
fn writes_are_replicated_to_the_destination() {
    block_on(async {
        let (mirror, failures) = mirror().await;
        let source = mirror.source().clone();
        let destination = mirror.destination().unwrap().clone();
        mirror.create_collection(people()).await.unwrap();
        assert!(destination.find_collection("people").await.unwrap().is_some());
        assert!(source.find_collection("upgrades").await.unwrap().is_some());

        mirror.create_document("people", person("ada", "Ada", 36)).await.unwrap();
        mirror.create_documents("people", vec![person("grace", "Grace", 85)], 100, None).await.unwrap();
        let mut changes = Document::default();
        changes.put("age", Value::Int(37));
        mirror.update_document("people", "ada", changes).await.unwrap();
        mirror.create_document("people", person("alan", "Alan", 41)).await.unwrap();
        mirror.delete_document("people", "grace").await.unwrap();

        let copies = destination.find("people", vec![], crate::PermissionType::Read).await.unwrap();
        let ids: Vec<&str> = copies.iter().map(|d| d.id()).collect();
        assert_eq!(ids, vec!["ada", "alan"]);
        assert_eq!(copies[0].get("age"), Some(&Value::Int(37)));
        assert_eq!(mirror.list_collections(25, 0).await.unwrap().len(), 1);
        assert!(failures.lock().unwrap().is_empty());

        mirror.create_attribute("people", Attribute::string("city", 32)).await.unwrap();
        let update = attribute::Update { size: Some(48), ..Default::default() };
        mirror.update_attribute("people", "city", update).await.unwrap();
        let index = Index::key("by_name", ["name"], vec![], vec![]).unwrap();
        mirror.create_index("people", index).await.unwrap();
        let definition = destination.get_collection("people").await.unwrap();
        let city = definition.attributes().unwrap().iter().find(|a| a.key == "city").cloned().unwrap();
        assert_eq!(city.size, Some(48));
        assert_eq!(definition.indexes().unwrap().len(), 1);
    });
}

#[test]
fn destination_failures_are_reported_not_thrown() {
    block_on(async {
        let (mirror, failures) = mirror().await;
        mirror.create_collection(people()).await.unwrap();
        mirror.destination().unwrap().create_document("people", person("dup", "D", 1)).await.unwrap();
        let created = mirror.create_document("people", person("dup", "S", 2)).await.unwrap();
        assert_eq!(created.get("name"), Some(&Value::from("S")));
        mirror.destination().unwrap().delete_collection("people").await.unwrap();
        mirror
            .update_collection("people", collection::Update { document_security: Some(true), ..Default::default() })
            .await
            .unwrap();
        let failures = failures.lock().unwrap();
        assert_eq!(failures[0].0, "createDocument");
        assert_eq!(failures[0].1, Some(Event::DocumentCreate));
        assert_eq!(failures[1].0, "updateCollection");
        assert_eq!(failures[1].2, Kind::NotFound);
    });
}

#[test]
fn collections_the_source_has_not_upgraded_are_not_replicated() {
    block_on(async {
        let (mirror, _) = mirror().await;
        mirror.source().create_collection(people()).await.unwrap();
        mirror.create_document("people", person("one", "One", 1)).await.unwrap();
        assert!(mirror.destination().unwrap().find_collection("people").await.unwrap().is_none());
        mirror.create_upgrades().await.unwrap();
        mirror.create_upgrades().await.unwrap();
        let upgrades =
            mirror.source().find("upgrades", vec![Query::limit(5)], crate::PermissionType::Read).await.unwrap();
        assert!(upgrades.is_empty());
    });
}

#[test]
fn a_negative_replication_timeout_is_refused() {
    block_on(async {
        let (mirror, _) = mirror().await;
        assert!(mirror.await_replications(None).is_ok());
        assert!(mirror.await_replications(Some(0)).is_ok());
        let error = mirror.await_replications(Some(-1)).unwrap_err();
        assert_eq!(error.message(), "A replication timeout cannot be negative, -1 given");
    });
}

#[test]
fn a_filtered_update_names_only_what_changed() {
    let source = Attribute::string("city", 32);
    let mut filtered = source.clone();
    filtered.required = true;
    let update = attribute::Update { size: Some(32), ..Default::default() };
    let applied = super::filtered_update(&update, &source, &filtered);
    assert_eq!(applied.size, Some(32));
    assert_eq!(applied.required, Some(true));
    assert_eq!(applied.kind, None);
    assert!(matches!(applied.default, Patch::Unchanged));
    assert!(matches!(applied.format, Patch::Unchanged));
}
