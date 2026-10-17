//! Ported from utopia-php/database 8.1.3 `tests/unit/Adapter/RedisAdapterPathsTest.php`,
//! against a real Redis (see `redis_probe`) instead of PHP's fake client.
//!
//! Ported at the adapter level (PHP drives some through a `Database`; the
//! adapter calls a database makes are made here directly):
//! `testNoRolesSeeNoDocumentsInADocumentSecurityCollection`,
//! `testRollbackRestoresARenamedDocument` (through `with_transaction`),
//! `testRenamingAManyToManyKeyOfATenantlessDefinition` (the definitions
//! written to `_metadata` as the database writes them), and
//! `testNullChecksAndUnsupportedMethodsOnAWholeObjectAttribute` (queries
//! typed `object` as the database types them).
//!
//! Deferred until the `Database` type is ported: `testOneToManyKeyRenamedFromTheChildSide`,
//! `testManyToOneTwoWayKeyRenamedFromTheChildSide` (they read related
//! documents through the relationships hook) and
//! `testAnOperatorLimitWithAFractionIsFlooredBeforeTheWrite` (the database
//! floors the fractional limit before the adapter sees it).

#![cfg(feature = "redis")]

mod redis_probe;

use std::sync::Arc;

use redis_probe::{Fault, adapter, collection, doc, namespace, s, snapshot};
use serde_json::json;
use utopia_database::adapter::redis::{KEY_PREFIX, Redis};
use utopia_database::adapter::{Find, with_transaction};
use utopia_database::attribute::Attribute;
use utopia_database::relationship::{self, Relationship, RelationshipSide};
use utopia_database::value::{Array, ArrayKey, Value};
use utopia_database::{Adapter, Authorization, Document, Error, Index, Kind, Query};
use utopia_query::CursorDirection;

const DATABASE: &str = "redis_paths";
const NOTES: &str = "notes";
const TENANT: i64 = 5;

async fn create_notes(adapter: &Redis<redis_probe::Probe>) {
    adapter.create_collection(NOTES, &[Attribute::string("title", 64)], &[]).await.unwrap();
}

fn ids(documents: &[Document]) -> Vec<String> {
    documents.iter().map(|d| d.id().to_owned()).collect()
}

fn unique(key: &str, attributes: &[&str]) -> Index {
    Index::unique(key, attributes.iter().copied(), vec![], vec![]).unwrap()
}

fn key_index(key: &str, attributes: &[&str]) -> Index {
    Index::key(key, attributes.iter().copied(), vec![], vec![]).unwrap()
}

#[utopia_async::test]
async fn test_unique_index_over_existing_duplicates_is_refused_by_the_adapter() {
    let probe = probe_or_skip!();
    let adapter = adapter(&probe, DATABASE, &namespace("paths_unique")).await;
    create_notes(&adapter).await;
    for id in ["first", "second"] {
        adapter
            .create_document(&collection(NOTES), doc(json!({"$id": id, "$permissions": [], "title": "same"})))
            .await
            .unwrap();
    }

    let e = adapter.create_index(NOTES, &unique("unique_title", &["title"]), &[], &[]).await.unwrap_err();
    assert_eq!(e.kind(), Kind::Unique);
    assert_eq!(e.message(), "Unique index violation");

    assert!(adapter.create_index(NOTES, &key_index("by_title", &["title"]), &[], &[]).await.unwrap());
    let e = adapter.create_index(NOTES, &key_index("by_title", &["title"]), &[], &[]).await.unwrap_err();
    assert_eq!(e.kind(), Kind::Duplicate);
    assert_eq!(e.message(), "Index already exists");

    let e = adapter.create_index("missing", &key_index("by_title", &["title"]), &[], &[]).await.unwrap_err();
    assert_eq!(e.kind(), Kind::NotFound);
    assert_eq!(e.message(), "Collection not found");
}

#[utopia_async::test]
async fn test_no_roles_see_no_documents_in_a_document_security_collection() {
    let probe = probe_or_skip!();
    let adapter = adapter(&probe, DATABASE, &namespace("paths_roles")).await;
    let authorization = Arc::new(Authorization::new(true));
    authorization.add_role("any");
    adapter.set_authorization(authorization.clone());
    create_notes(&adapter).await;

    assert!(adapter.find(&collection(NOTES), vec![], Find::default()).await.unwrap().is_empty());

    adapter
        .create_document(
            &collection(NOTES),
            doc(json!({"$id": "public", "title": "a", "$permissions": ["read(\"any\")"]})),
        )
        .await
        .unwrap();
    adapter
        .create_document(
            &collection(NOTES),
            doc(json!({"$id": "private", "title": "b", "$permissions": ["read(\"user:bob\")"]})),
        )
        .await
        .unwrap();

    assert_eq!(ids(&adapter.find(&collection(NOTES), vec![], Find::default()).await.unwrap()), ["public"]);
    let mut all = ids(&authorization.skip(adapter.find(&collection(NOTES), vec![], Find::default())).await.unwrap());
    all.sort();
    assert_eq!(all, ["private", "public"]);

    authorization.clean_roles();
    assert!(adapter.find(&collection(NOTES), vec![], Find::default()).await.unwrap().is_empty());
    assert_eq!(adapter.count(&collection(NOTES), vec![], None).await.unwrap(), 0);
}

#[utopia_async::test]
async fn test_rollback_restores_a_renamed_document() {
    let probe = probe_or_skip!();
    let adapter = adapter(&probe, DATABASE, &namespace("paths_rollback")).await;
    create_notes(&adapter).await;
    adapter
        .create_document(&collection(NOTES), doc(json!({"$id": "a", "$permissions": [], "title": "original"})))
        .await
        .unwrap();

    let result: Result<(), Error> = with_transaction(&adapter, || async {
        adapter.update_document(&collection(NOTES), "a", doc(json!({"$id": "b", "title": "renamed"})), false).await?;
        Err(Error::new(Kind::Runtime, "roll back"))
    })
    .await;
    assert_eq!(result.unwrap_err().message(), "roll back", "The transaction must rethrow");

    let a = adapter.get_document(&collection(NOTES), "a", vec![], false).await.unwrap();
    assert_eq!(a.get("title"), Some(&s("original")));
    assert!(adapter.get_document(&collection(NOTES), "b", vec![], false).await.unwrap().is_empty());
    assert!(
        adapter
            .find(&collection(NOTES), vec![Query::equal("$id", vec!["b"])], Find::default())
            .await
            .unwrap()
            .is_empty()
    );
    assert_eq!(ids(&adapter.find(&collection(NOTES), vec![], Find::default()).await.unwrap()), ["a"]);
}

#[utopia_async::test]
async fn test_rollback_refuses_an_unknown_journal_entry() {
    let probe = probe_or_skip!();
    let adapter = Redis::new(probe.clone());

    adapter.start_transaction().await.unwrap();
    adapter.journal("unknown", vec![]);

    let e = adapter.rollback_transaction().await.unwrap_err();
    assert_eq!(e.kind(), Kind::Transaction);
    assert_eq!(e.message(), "Unknown journal op: unknown");
    assert!(!adapter.in_transaction(), "A failed rollback resets the depth");
}

#[utopia_async::test]
async fn test_renaming_a_many_to_many_key_of_a_tenantless_definition() {
    let probe = probe_or_skip!();
    let adapter = adapter(&probe, DATABASE, &namespace("paths_junction")).await;
    adapter.set_shared_tables(true);
    adapter.set_tenant(Value::Null);
    // The definitions the database writes without a tenant.
    adapter.create_collection("_metadata", &[], &[]).await.unwrap();
    for (id, sequence) in [("books", "1"), ("authors", "2")] {
        adapter.create_collection(id, &[Attribute::string("name", 64)], &[]).await.unwrap();
        adapter
            .create_document(
                &collection("_metadata"),
                doc(json!({"$id": id, "$sequence": sequence, "$permissions": [], "name": id})),
            )
            .await
            .unwrap();
    }
    let junction = "_1_2";
    adapter
        .create_collection(junction, &[Attribute::string("authors", 64), Attribute::string("books", 64)], &[])
        .await
        .unwrap();

    adapter.set_tenant(Value::Int(TENANT));
    adapter
        .create_document(
            &collection(junction),
            doc(json!({"$id": "link", "$permissions": [], "$tenant": TENANT, "authors": "ann", "books": "dune"})),
        )
        .await
        .unwrap();

    let relationship = Relationship::many_to_many("authors").two_way(true).key("authors").two_way_key("books");
    let update = relationship::Update { key: Some("writers".into()), ..Default::default() };
    assert!(
        adapter
            .relationships()
            .unwrap()
            .update_relationship("books", &relationship, RelationshipSide::Parent, &update)
            .await
            .unwrap()
    );

    let link = adapter.get_document(&collection(junction), "link", vec![], false).await.unwrap();
    assert_eq!(
        link.get("writers"),
        Some(&s("ann")),
        "The junction of a tenantless definition must be found from a tenant"
    );
    assert_eq!(link.get("authors"), None);
}

#[utopia_async::test]
async fn test_null_checks_and_unsupported_methods_on_a_whole_object_attribute() {
    let probe = probe_or_skip!();
    let adapter = adapter(&probe, DATABASE, &namespace("paths_object")).await;
    adapter.create_collection(NOTES, &[Attribute::object("meta")], &[]).await.unwrap();
    adapter
        .create_document(
            &collection(NOTES),
            doc(json!({"$id": "filled", "$permissions": [], "meta": {"colour": "red"}})),
        )
        .await
        .unwrap();
    adapter
        .create_document(&collection(NOTES), doc(json!({"$id": "empty", "$permissions": [], "meta": null})))
        .await
        .unwrap();
    let typed = |mut query: Query| {
        query.set_attribute_type("object");
        query
    };

    let found = adapter.find(&collection(NOTES), vec![typed(Query::is_null("meta"))], Find::default()).await.unwrap();
    assert_eq!(ids(&found), ["empty"]);
    let found =
        adapter.find(&collection(NOTES), vec![typed(Query::is_not_null("meta"))], Find::default()).await.unwrap();
    assert_eq!(ids(&found), ["filled"]);

    let e = adapter
        .find(&collection(NOTES), vec![typed(Query::less_than("meta", "x"))], Find::default())
        .await
        .unwrap_err();
    assert_eq!(e.kind(), Kind::Query);
    assert_eq!(e.message(), "Query method lessThan not supported for object attributes");
}

#[utopia_async::test]
async fn test_schema_changes_on_a_collection_without_storage() {
    let probe = probe_or_skip!();
    probe.recorder.no_memory.store(true, std::sync::atomic::Ordering::SeqCst);
    let adapter = adapter(&probe, DATABASE, &namespace("paths_missing")).await;
    probe.take_hash_writes();

    assert!(adapter.delete_index("missing", "by_title").await.unwrap());
    let relationship = Relationship::one_to_one("gone").two_way(true).key("partner").two_way_key("partnerOf");
    assert!(adapter.relationships().unwrap().create_relationship("missing", &relationship).await.unwrap());
    assert_eq!(adapter.size_of_collection("missing").await.unwrap(), 0);
    assert!(probe.take_hash_writes().is_empty(), "A collection without storage must not be written to");

    let e = adapter.rename_index("missing", "by_title", "by_name").await.unwrap_err();
    assert_eq!(e.kind(), Kind::NotFound);
    assert_eq!(e.message(), "Collection not found");
}

#[utopia_async::test]
async fn test_renaming_an_index_the_collection_does_not_record_writes_nothing_and_reports_nothing_renamed() {
    let probe = probe_or_skip!();
    let adapter = adapter(&probe, DATABASE, &namespace("paths_rename_index")).await;
    create_notes(&adapter).await;
    adapter.create_index(NOTES, &key_index("by_title", &["title"]), &[], &[]).await.unwrap();
    probe.take_hash_writes();

    assert!(!adapter.rename_index(NOTES, "absent", "other").await.unwrap());
    assert!(probe.take_hash_writes().is_empty());

    assert!(adapter.rename_index(NOTES, "by_title", "by_name").await.unwrap());
    assert_eq!(probe.take_hash_writes().len(), 1);
}

#[utopia_async::test]
async fn test_renaming_an_index_the_collection_already_renamed_reports_it_renamed() {
    let probe = probe_or_skip!();
    let adapter = adapter(&probe, DATABASE, &namespace("paths_renamed_index")).await;
    create_notes(&adapter).await;
    adapter.create_index(NOTES, &key_index("by_name", &["title"]), &[], &[]).await.unwrap();
    probe.take_hash_writes();

    assert!(adapter.rename_index(NOTES, "by_title", "by_name").await.unwrap());
    assert!(probe.take_hash_writes().is_empty());
}

#[utopia_async::test]
async fn test_get_sequences_back_fills_only_the_documents_that_lack_one() {
    let probe = probe_or_skip!();
    let adapter = adapter(&probe, DATABASE, &namespace("paths_sequences")).await;
    create_notes(&adapter).await;
    let stored = adapter
        .create_document(&collection(NOTES), doc(json!({"$id": "stored", "$permissions": [], "title": "a"})))
        .await
        .unwrap();

    assert!(adapter.get_sequences(&collection(NOTES), vec![]).await.unwrap().is_empty());

    let documents = adapter
        .get_sequences(
            &collection(NOTES),
            vec![
                doc(json!({"$id": "stored"})),
                doc(json!({"$id": "missing"})),
                doc(json!({"$id": "given", "$sequence": "99"})),
            ],
        )
        .await
        .unwrap();
    assert_eq!(documents[0].sequence(), stored.sequence());
    assert_eq!(documents[1].sequence(), None);
    assert_eq!(documents[2].sequence(), Some(&s("99")));

    let complete =
        adapter.get_sequences(&collection(NOTES), vec![doc(json!({"$id": "given", "$sequence": "99"}))]).await.unwrap();
    assert_eq!(complete[0].sequence(), Some(&s("99")));
}

#[utopia_async::test]
async fn test_get_sequences_reports_a_failing_pipeline() {
    let probe = probe_or_skip!();
    probe.fault("GET", "", Fault::Lost("connection lost"));
    let adapter = Redis::new(probe.clone());

    let e = adapter.get_sequences(&collection(NOTES), vec![doc(json!({"$id": "first"}))]).await.unwrap_err();
    assert_eq!(e.kind(), Kind::Transaction);
    assert_eq!(e.message(), "Failed to load sequences: connection lost");
}

// 8.3.6 (RedisAdapterPathsTest; Adapter/Redis.php:1621-1634): a bound the stored value crosses answers false.
#[utopia_async::test]
async fn test_increment_guards_of_the_adapter() {
    let probe = probe_or_skip!();
    let adapter = adapter(&probe, DATABASE, &namespace("paths_increment")).await;
    adapter.create_collection(NOTES, &[Attribute::double("count")], &[]).await.unwrap();
    adapter
        .create_document(&collection(NOTES), doc(json!({"$id": "whole", "$permissions": [], "count": 10})))
        .await
        .unwrap();
    adapter
        .create_document(&collection(NOTES), doc(json!({"$id": "fraction", "$permissions": [], "count": 10.5})))
        .await
        .unwrap();

    for (id, stored) in [("whole", Value::Int(10)), ("fraction", Value::Float(10.5))] {
        let at = "2026-01-01 00:00:00.000";
        assert!(
            !adapter
                .increase_document_attribute(
                    &collection(NOTES),
                    id,
                    "count",
                    Value::Int(1),
                    at,
                    None,
                    Some(Value::Int(5))
                )
                .await
                .unwrap()
        );
        assert!(
            !adapter
                .increase_document_attribute(
                    &collection(NOTES),
                    id,
                    "count",
                    Value::Int(-1),
                    at,
                    Some(Value::Int(20)),
                    None
                )
                .await
                .unwrap()
        );
        let document = adapter.get_document(&collection(NOTES), id, vec![], false).await.unwrap();
        assert!(document.get("count").unwrap().strict_eq(&stored), "{id}");
    }

    let e = adapter
        .increase_document_attribute(
            &collection(NOTES),
            "vanished",
            "count",
            Value::Int(1),
            "2026-01-01 00:00:00.000",
            None,
            None,
        )
        .await
        .unwrap_err();
    assert_eq!(e.kind(), Kind::NotFound);
    assert_eq!(e.message(), "Document not found");
}

#[utopia_async::test]
async fn test_renaming_an_attribute_on_an_empty_collection_or_to_its_own_name() {
    let probe = probe_or_skip!();
    let adapter = adapter(&probe, DATABASE, &namespace("paths_rename_attribute")).await;
    create_notes(&adapter).await;

    assert!(adapter.rename_attribute(NOTES, "title", "heading").await.unwrap());
    adapter
        .create_document(&collection(NOTES), doc(json!({"$id": "first", "$permissions": [], "heading": "kept"})))
        .await
        .unwrap();

    assert!(adapter.rename_attribute(NOTES, "heading", "heading").await.unwrap());
    let first = adapter.get_document(&collection(NOTES), "first", vec![], false).await.unwrap();
    assert_eq!(first.get("heading"), Some(&s("kept")));
}

#[utopia_async::test]
async fn test_unvalidated_null_candidate_and_unsupported_method() {
    let probe = probe_or_skip!();
    let adapter = adapter(&probe, DATABASE, &namespace("paths_null_candidate")).await;
    create_notes(&adapter).await;
    for (id, title) in [("first", "x"), ("second", "y")] {
        adapter
            .create_document(&collection(NOTES), doc(json!({"$id": id, "$permissions": [], "title": title})))
            .await
            .unwrap();
    }

    let values: utopia_query::Array =
        [utopia_query::Value::Null, utopia_query::Value::Str("x".into())].into_iter().collect();
    let not_equal = Query::new(utopia_query::Method::NotEqual, "title", values, "");
    let found = adapter.find(&collection(NOTES), vec![not_equal], Find::default()).await.unwrap();
    assert!(found.is_empty(), "A null candidate makes NOT IN unknown for every row");

    let exists: utopia_query::Array = [utopia_query::Value::Str("title".into())].into_iter().collect();
    let e = adapter.find(&collection(NOTES), vec![Query::exists(exists)], Find::default()).await.unwrap_err();
    assert_eq!(e.kind(), Kind::Query);
    assert_eq!(e.message(), "Query method not supported by Redis adapter: exists");
}

#[utopia_async::test]
async fn test_unique_index_compares_array_values_by_content() {
    let probe = probe_or_skip!();
    let adapter = adapter(&probe, DATABASE, &namespace("paths_array_unique")).await;
    adapter.create_collection(NOTES, &[Attribute::string("tags", 16).array(true)], &[]).await.unwrap();
    adapter.create_index(NOTES, &unique("unique_tags", &["tags"]), &[], &[]).await.unwrap();
    adapter
        .create_document(&collection(NOTES), doc(json!({"$id": "first", "$permissions": [], "tags": ["a", "b"]})))
        .await
        .unwrap();
    adapter
        .create_document(&collection(NOTES), doc(json!({"$id": "other", "$permissions": [], "tags": ["b", "a"]})))
        .await
        .unwrap();

    let e = adapter
        .create_document(&collection(NOTES), doc(json!({"$id": "second", "$permissions": [], "tags": ["a", "b"]})))
        .await
        .unwrap_err();
    assert!(e.is_a(Kind::Duplicate));
}

#[utopia_async::test]
async fn test_a_cursor_without_an_order_pages_by_sequence() {
    let probe = probe_or_skip!();
    let adapter = adapter(&probe, DATABASE, &namespace("paths_cursor")).await;
    create_notes(&adapter).await;
    for id in ["first", "second", "third"] {
        adapter
            .create_document(&collection(NOTES), doc(json!({"$id": id, "$permissions": [], "title": id})))
            .await
            .unwrap();
    }
    let second = adapter.get_document(&collection(NOTES), "second", vec![], false).await.unwrap();
    let mut cursor = Array::new();
    cursor.set(ArrayKey::from("$sequence"), second.sequence().unwrap().clone());

    let after = Find { cursor: cursor.clone(), ..Find::default() };
    assert_eq!(ids(&adapter.find(&collection(NOTES), vec![], after).await.unwrap()), ["third"]);
    let before = Find { cursor, cursor_direction: CursorDirection::Before, ..Find::default() };
    assert_eq!(ids(&adapter.find(&collection(NOTES), vec![], before).await.unwrap()), ["first"]);
}

#[utopia_async::test]
async fn test_renaming_a_many_to_many_key_between_collections_without_definitions_renames_nothing() {
    let probe = probe_or_skip!();
    let ns = namespace("paths_no_definitions");
    let adapter = adapter(&probe, DATABASE, &ns).await;
    create_notes(&adapter).await;
    adapter.create_collection("tags", &[Attribute::string("name", 64)], &[]).await.unwrap();
    let prefix = format!("{KEY_PREFIX}:{ns}:");
    let before = snapshot(&probe, &prefix).await;

    let relationship = Relationship::many_to_many("tags").two_way(true).key("tags").two_way_key("notes");
    let update =
        relationship::Update { key: Some("labels".into()), two_way_key: Some("entries".into()), ..Default::default() };
    assert!(
        adapter
            .relationships()
            .unwrap()
            .update_relationship(NOTES, &relationship, RelationshipSide::Parent, &update)
            .await
            .unwrap()
    );

    assert_eq!(before, snapshot(&probe, &prefix).await, "without stored definitions there is no junction to rename");
}
