//! PHP: `Indexes\IndexValidationTest`, `Indexes\IndexSchemaReconciliationTest`,
//! `Indexes\CreateIndexesTest`, `Indexes\CreateReturnsStoredTest`,
//! `Indexes\OrphanIndexTest` (on stubs instead of Memory/SQLite).

use std::sync::{Arc, Mutex};

use utopia_query::OrderDirection;
use utopia_query::schema::IndexType;

use super::{Fixture, definition, describe, index_keys};
use crate::attribute::Attribute;
use crate::capability::Capability;
use crate::database::testing::{Call, Measures, Reply, block_on, limits};
use crate::error::{Error, Kind};
use crate::index::Index;
use crate::schema;
use crate::value::Value;

const COLLECTION: &str = "catalog";

fn key(name: &str, attributes: &[&str]) -> Index {
    Index::key(name, attributes.iter().copied(), Vec::new(), Vec::new()).unwrap()
}

fn catalog() -> Fixture {
    Fixture::new(vec![definition(
        COLLECTION,
        vec![Attribute::string("name", 32), Attribute::string("sku", 32)],
        vec![key("existing", &["sku"])],
    )])
}

/// The renames the adapter was asked for, as `old->new`.
fn renames(f: &Fixture) -> Vec<String> {
    f.adapter
        .calls_of("renameIndex")
        .into_iter()
        .map(|c| match c {
            Call::RenameIndex { old, new, .. } => format!("{old}->{new}"),
            _ => unreachable!(),
        })
        .collect()
}

// ---- IndexValidationTest -----------------------------------------------

#[test]
fn creating_an_index_on_a_missing_attribute_is_invalid() {
    block_on(async {
        let f = catalog();
        let error = f.db.create_index(COLLECTION, key("idx1", &["nonexistent"])).await.unwrap_err();
        assert!(error.is_a(Kind::Index), "{error:?}");
        assert!(f.adapter.calls_of("createIndex").is_empty());
    });
}

#[test]
fn creating_an_index_enforces_the_index_limit() {
    block_on(async {
        let f = catalog();
        f.adapter.set_limits(crate::adapter::Limits { indexes: 1, ..limits() });
        f.adapter.set_measures(Measures { indexes: Some(5), ..Measures::default() });
        let error = f.db.create_index(COLLECTION, key("idx_name", &["name"])).await.unwrap_err();
        assert_eq!(describe(&error), (Kind::Limit, "Index limit reached. Cannot create new index.".to_owned()));
    });
}

#[test]
fn creating_an_index_rejects_a_duplicate_key_in_any_case() {
    block_on(async {
        let f = catalog();
        for name in ["existing", "EXISTING"] {
            let error = f.db.create_index(COLLECTION, key(name, &["name"])).await.unwrap_err();
            assert_eq!(describe(&error), (Kind::Duplicate, "Index already exists".to_owned()));
        }
    });
}

#[test]
fn creating_an_index_without_attributes_is_refused() {
    block_on(async {
        let f = catalog();
        let error =
            f.db.create_index(COLLECTION, Index::fulltext("idx_empty", Vec::<String>::new())).await.unwrap_err();
        assert_eq!(describe(&error), (Kind::Database, "Missing attributes".to_owned()));
    });
}

#[test]
#[ignore = "waits on the writes area (updateDocument/createDocument/deleteDocument)"]
fn creating_a_valid_index_returns_it_as_stored() {
    block_on(async {
        let f = catalog();
        let created = f.db.create_index(COLLECTION, key("idx_name", &["name"])).await.unwrap();
        assert_eq!(created.key, "idx_name");
        assert_eq!(created.attributes, ["name"]);
        assert_eq!(index_keys(f.last_write().indexes().unwrap()), ["existing", "idx_name"]);
    });
}

#[test]
fn deleting_or_renaming_a_missing_index_is_not_found() {
    block_on(async {
        let f = catalog();
        let error = f.db.delete_index(COLLECTION, "nonexistent").await.unwrap_err();
        assert_eq!(describe(&error), (Kind::NotFound, "Index not found".to_owned()));
        let error = f.db.rename_index(COLLECTION, "nonexistent", "newname").await.unwrap_err();
        assert_eq!(describe(&error), (Kind::NotFound, "Index not found".to_owned()));
        assert!(f.adapter.calls_of("renameIndex").is_empty());
    });
}

#[test]
fn renaming_onto_an_existing_index_is_a_duplicate() {
    block_on(async {
        let f = Fixture::new(vec![definition(
            COLLECTION,
            vec![Attribute::string("name", 128), Attribute::string("title", 128)],
            vec![key("idx_name", &["name"]), key("idx_title", &["title"])],
        )]);
        let error = f.db.rename_index(COLLECTION, "idx_name", "idx_title").await.unwrap_err();
        assert_eq!(describe(&error), (Kind::Duplicate, "Index name already used".to_owned()));
    });
}

#[test]
#[ignore = "waits on the writes area (updateDocument/createDocument/deleteDocument)"]
fn renaming_an_index_stores_it_under_the_new_key() {
    block_on(async {
        let f = catalog();
        f.db.rename_index(COLLECTION, "existing", "renamed").await.unwrap();
        let indexes = f.last_write().indexes().unwrap().to_vec();
        assert_eq!(index_keys(&indexes), ["renamed"]);
        assert_eq!(indexes[0].attributes, ["sku"]);
    });
}

#[test]
fn a_second_ttl_index_is_refused_on_a_collection_create() {
    block_on(async {
        let f = Fixture::new(Vec::new());
        f.adapter.support(crate::capability::Capability::IndexTtl);
        let collection = crate::collection::Collection::create(
            "ttl",
            "",
            vec![Attribute::datetime("expiresAt"), Attribute::datetime("deletedAt")],
            vec![Index::ttl("a", "expiresAt", 60).unwrap(), Index::ttl("b", "deletedAt", 60).unwrap()],
            None,
            true,
            crate::value::Array::new(),
        )
        .unwrap();
        let error = f.db.create_collection(collection).await.unwrap_err();
        assert_eq!(describe(&error), (Kind::Index, "There can be only one TTL index in a collection".to_owned()));
        assert!(f.adapter.calls_of("createCollection").is_empty());
    });
}

// ---- IndexSchemaReconciliationTest ---------------------------------------

#[test]
fn an_adapter_that_does_not_rename_the_index_refuses_the_rename() {
    block_on(async {
        let f = catalog();
        f.on(|call| matches!(call, Call::RenameIndex { .. }).then_some(Reply::Bool(false)));
        let error = f.db.rename_index(COLLECTION, "existing", "renamed").await.unwrap_err();
        assert_eq!(describe(&error), (Kind::Refused, "Failed to rename index 'existing' to 'renamed'".to_owned()));
        assert!(error.previous().is_none());
        assert_eq!(renames(&f), ["existing->renamed"], "a refusal is not a prior rename to complete");
        assert_eq!(f.write_count(), 0);
    });
}

#[test]
fn an_adapter_that_does_not_create_the_index_fails_the_create() {
    block_on(async {
        let f = catalog();
        f.on(|call| {
            matches!(call, Call::CreateIndex { index, .. } if index.key == "byName").then_some(Reply::Bool(false))
        });
        let error = f.db.create_index(COLLECTION, key("byName", &["name"])).await.unwrap_err();
        assert_eq!(describe(&error), (Kind::Refused, "Failed to create index".to_owned()));
        assert_eq!(f.write_count(), 0);
    });
}

#[test]
#[ignore = "waits on the writes area (updateDocument/createDocument/deleteDocument)"]
fn an_index_only_in_the_schema_is_adopted() {
    block_on(async {
        let f = catalog();
        f.on(|call| {
            matches!(call, Call::CreateIndex { index, .. } if index.key == "byName")
                .then(|| Reply::Error(Error::duplicate("Index already exists in the schema")))
        });
        assert_eq!(f.db.create_index(COLLECTION, key("byName", &["name"])).await.unwrap().key, "byName");
        assert_eq!(index_keys(f.last_write().indexes().unwrap()), ["existing", "byName"]);
    });
}

#[test]
fn a_unique_violation_while_creating_the_index_reaches_the_caller() {
    block_on(async {
        let f = catalog();
        f.on(|call| {
            matches!(call, Call::CreateIndex { .. }).then(|| Reply::Error(Error::unique(crate::error::UNIQUE_MESSAGE)))
        });
        let error =
            f.db.create_index(COLLECTION, Index::unique("byName", ["name"], Vec::new(), Vec::new()).unwrap())
                .await
                .unwrap_err();
        assert!(error.is_a(Kind::Unique));
        assert_eq!(f.write_count(), 0);
    });
}

#[test]
fn renaming_an_index_the_schema_no_longer_has_is_refused() {
    block_on(async {
        let f = catalog();
        // Memory answers `false` for an index it does not hold.
        f.on(|call| matches!(call, Call::RenameIndex { .. }).then_some(Reply::Bool(false)));
        let error = f.db.rename_index(COLLECTION, "existing", "renamed").await.unwrap_err();
        assert_eq!(describe(&error), (Kind::Refused, "Failed to rename index 'existing' to 'renamed'".to_owned()));
    });
}

#[test]
#[ignore = "waits on the writes area (updateDocument/createDocument/deleteDocument)"]
fn a_rename_the_schema_already_applied_is_completed() {
    block_on(async {
        let f = catalog();
        let count = Arc::new(Mutex::new(0));
        f.on(move |call| {
            if !matches!(call, Call::RenameIndex { .. }) {
                return None;
            }
            let mut count = count.lock().unwrap();
            *count += 1;
            (*count == 1).then(|| Reply::Error(Error::not_found("Index not found in the schema")))
        });
        f.db.rename_index(COLLECTION, "existing", "renamed").await.unwrap();
        assert_eq!(renames(&f), ["existing->renamed", "renamed->existing", "existing->renamed"]);
        assert_eq!(index_keys(f.last_write().indexes().unwrap()), ["renamed"]);
    });
}

#[test]
fn a_rename_that_fails_both_ways_is_reported_with_its_cause() {
    block_on(async {
        let f = catalog();
        f.on(|call| {
            matches!(call, Call::RenameIndex { .. })
                .then(|| Reply::Error(Error::new(Kind::Runtime, "the engine refused the rename")))
        });
        let error = f.db.rename_index(COLLECTION, "existing", "renamed").await.unwrap_err();
        assert_eq!(
            describe(&error),
            (
                Kind::Database,
                "Failed to rename index 'existing' to 'renamed': the engine refused the rename".to_owned()
            )
        );
        assert!(error.previous().is_some_and(|p| p.kind() == Kind::Runtime));
        assert_eq!(f.write_count(), 0);
    });
}

#[test]
fn a_rename_the_adapter_does_not_redo_after_reversing_it_is_reported_with_its_cause() {
    block_on(async {
        let f = catalog();
        let count = Arc::new(Mutex::new(0));
        f.on(move |call| {
            if !matches!(call, Call::RenameIndex { .. }) {
                return None;
            }
            let mut count = count.lock().unwrap();
            *count += 1;
            Some(match *count {
                1 => Reply::Error(Error::new(Kind::Runtime, "the engine lost the index")),
                2 => Reply::Bool(true),
                _ => Reply::Bool(false),
            })
        });
        let error = f.db.rename_index(COLLECTION, "existing", "renamed").await.unwrap_err();
        assert_eq!(
            describe(&error),
            (Kind::Database, "Failed to rename index 'existing' to 'renamed': the engine lost the index".to_owned())
        );
        assert_eq!(renames(&f), ["existing->renamed", "renamed->existing", "existing->renamed"]);
    });
}

#[test]
fn a_typed_failure_the_rename_cannot_complete_is_the_adapters_own() {
    block_on(async {
        for failure in [Error::not_found("Collection not found"), Error::duplicate("Index already exists")] {
            let f = catalog();
            let reply = failure.clone();
            f.on(move |call| matches!(call, Call::RenameIndex { .. }).then(|| Reply::Error(reply.clone())));
            let error = f.db.rename_index(COLLECTION, "existing", "renamed").await.unwrap_err();
            assert_eq!(describe(&error), describe(&failure));
        }
    });
}

#[test]
#[ignore = "waits on the writes area (updateDocument/createDocument/deleteDocument)"]
fn deleting_an_index_the_schema_no_longer_has_succeeds() {
    block_on(async {
        let f = catalog();
        f.on(|call| {
            matches!(call, Call::DeleteIndex { .. })
                .then(|| Reply::Error(Error::not_found("Index not found in the schema")))
        });
        f.db.delete_index(COLLECTION, "existing").await.unwrap();
        assert!(f.last_write().indexes().unwrap().is_empty());
    });
}

#[test]
fn an_adapter_that_does_not_delete_the_index_fails_the_delete() {
    block_on(async {
        let f = catalog();
        f.on(|call| matches!(call, Call::DeleteIndex { .. }).then_some(Reply::Bool(false)));
        let error = f.db.delete_index(COLLECTION, "existing").await.unwrap_err();
        assert_eq!(describe(&error), (Kind::Refused, "Failed to delete index".to_owned()));
        assert_eq!(f.write_count(), 0);
    });
}

// ---- CreateIndexesTest -------------------------------------------------

#[test]
fn an_empty_list_creates_nothing() {
    block_on(async {
        let f = catalog();
        assert!(f.db.create_indexes(COLLECTION, Vec::new()).await.unwrap().is_empty());
        assert!(f.adapter.calls().is_empty());
    });
}

#[test]
fn an_invalid_index_creates_none() {
    block_on(async {
        let f = catalog();
        let error =
            f.db.create_indexes(COLLECTION, vec![key("byName", &["name"]), key("byMissing", &["missing"])])
                .await
                .unwrap_err();
        assert!(error.is_a(Kind::Index));
        assert!(f.adapter.calls_of("createIndex").is_empty());
    });
}

#[test]
fn keys_repeated_in_the_batch_create_none() {
    block_on(async {
        let f = catalog();
        let error =
            f.db.create_indexes(COLLECTION, vec![key("byName", &["name"]), key("BYNAME", &["sku"])]).await.unwrap_err();
        assert_eq!(describe(&error), (Kind::Duplicate, "Index already exists".to_owned()));
        assert!(f.adapter.calls_of("createIndex").is_empty());
    });
}

#[test]
fn a_batch_over_the_index_limit_creates_none() {
    block_on(async {
        let f = catalog();
        f.adapter.set_limits(crate::adapter::Limits { indexes: 2, ..limits() });
        f.adapter.set_measures(Measures { indexes: Some(-1), ..Measures::default() });
        let error =
            f.db.create_indexes(COLLECTION, vec![key("byName", &["name"]), key("bySku", &["sku", "name"])])
                .await
                .unwrap_err();
        assert!(error.is_a(Kind::Limit));
        assert!(f.adapter.calls_of("createIndex").is_empty());
    });
}

#[test]
fn an_engine_failure_part_way_drops_the_indexes_already_created() {
    block_on(async {
        let f = catalog();
        f.on(|call| {
            matches!(call, Call::CreateIndex { index, .. } if index.key == "bySku")
                .then(|| Reply::Error(Error::new(Kind::Runtime, "engine failure")))
        });
        let error =
            f.db.create_indexes(COLLECTION, vec![key("byName", &["name"]), key("bySku", &["sku", "name"])])
                .await
                .unwrap_err();
        assert_eq!(error.message(), "engine failure");
        let dropped: Vec<String> = f
            .adapter
            .calls_of("deleteIndex")
            .into_iter()
            .map(|c| match c {
                Call::DeleteIndex { key, .. } => key,
                _ => unreachable!(),
            })
            .collect();
        assert_eq!(dropped, ["byName"]);
        assert_eq!(f.write_count(), 0);
    });
}

#[test]
#[ignore = "waits on the writes area (updateDocument/createDocument/deleteDocument)"]
fn creates_every_index_and_returns_them_as_stored() {
    block_on(async {
        let f = catalog();
        let stored =
            f.db.create_indexes(COLLECTION, vec![key("byName", &["name"]), key("bySku", &["sku", "name"])])
                .await
                .unwrap();
        assert_eq!(index_keys(&stored), ["byName", "bySku"]);
        assert_eq!(index_keys(f.last_write().indexes().unwrap()), ["existing", "byName", "bySku"]);
        assert_eq!(f.write_count(), 1, "the metadata is written once");
    });
}

// ---- fitIndexToAttributes ----------------------------------------------

#[test]
fn indexes_are_fitted_to_their_attributes() {
    let f = catalog();
    let attributes = vec![Attribute::string("name", 32), Attribute::string("tags", 32).array(true)];
    let fitted =
        f.db.fit_index_to_attributes(
            &Index::key(
                "i",
                ["name", "tags"],
                vec![Some(32)],
                vec![Some(OrderDirection::Asc), Some(OrderDirection::Desc)],
            )
            .unwrap(),
            &attributes,
        )
        .unwrap();
    assert_eq!(fitted.lengths, vec![None, Some(crate::database::MAX_ARRAY_INDEX_LENGTH)]);
    assert_eq!(fitted.orders, vec![Some(OrderDirection::Asc), None]);

    let fulltext = f.db.fit_index_to_attributes(&Index::fulltext("f", ["tags"]), &attributes).unwrap();
    assert!(fulltext.lengths.is_empty());
}

// ---- OrphanIndexTest ---------------------------------------------------

fn orphaned(schema_index: schema::Index) -> Fixture {
    let f = catalog();
    f.adapter.support(Capability::SchemaIntrospection);
    f.on(move |call| matches!(call, Call::SchemaIndexes { .. }).then(|| Reply::Indexes(vec![schema_index.clone()])));
    f
}

fn schema_index(name: &str, kind: IndexType, columns: &[&str], lengths: Vec<Option<i64>>) -> schema::Index {
    schema::Index { name: name.to_owned(), kind, columns: columns.iter().map(|c| (*c).to_owned()).collect(), lengths }
}

#[test]
#[ignore = "waits on the writes area (updateDocument/createDocument/deleteDocument)"]
fn create_index_reuses_a_matching_orphan_index() {
    block_on(async {
        let f = orphaned(schema_index("byName", IndexType::Key, &["name"], vec![None]));
        f.db.create_index(COLLECTION, key("byName", &["name"])).await.unwrap();
        assert!(f.adapter.calls_of("createIndex").is_empty());
        assert!(f.adapter.calls_of("deleteIndex").is_empty());
    });
}

#[test]
#[ignore = "waits on the writes area (updateDocument/createDocument/deleteDocument)"]
fn create_index_replaces_a_mismatched_orphan_index() {
    block_on(async {
        let f = orphaned(schema_index("byName", IndexType::Unique, &["name"], vec![None]));
        f.db.create_index(COLLECTION, key("byName", &["name"])).await.unwrap();
        assert_eq!(f.adapter.calls_of("deleteIndex").len(), 1);
        assert_eq!(f.adapter.calls_of("createIndex").len(), 1);
    });
}

#[test]
fn shared_tables_refuse_another_tenants_index_of_another_definition() {
    block_on(async {
        let f = orphaned(schema_index("byName", IndexType::Key, &["_tenant", "sku"], vec![None, None]));
        f.db.set_shared_tables(true);
        f.db.set_tenant(Value::Int(1));
        let error = f.db.create_index(COLLECTION, key("byName", &["name"])).await.unwrap_err();
        assert_eq!(
            describe(&error),
            (Kind::Duplicate, "Index exists in the shared table with another definition".to_owned())
        );
        assert!(f.adapter.calls_of("deleteIndex").is_empty());
        assert!(f.adapter.calls_of("createIndex").is_empty());
    });
}

#[test]
fn the_schema_index_matches_past_the_tenant_and_without_prefix_lengths() {
    let f = catalog();
    f.db.set_shared_tables(true);
    let index = Index::key("byName", ["name"], vec![Some(16)], Vec::new()).unwrap();
    assert!(
        f.db.schema_index_matches(
            &schema_index("byName", IndexType::Key, &["_TENANT", "name"], vec![None, None]),
            &index
        )
    );
    assert!(f.db.schema_index_matches(
        &schema_index("byName", IndexType::Key, &["_tenant", "NAME"], vec![None, Some(16)]),
        &index
    ));
    assert!(!f.db.schema_index_matches(
        &schema_index("byName", IndexType::Key, &["_tenant", "name"], vec![None, Some(8)]),
        &index
    ));
    assert!(
        !f.db.schema_index_matches(&schema_index("byName", IndexType::Unique, &["_tenant", "name"], vec![]), &index)
    );
    assert!(!f.db.schema_index_matches(&schema_index("byName", IndexType::Key, &["name", "sku"], vec![]), &index));
}
