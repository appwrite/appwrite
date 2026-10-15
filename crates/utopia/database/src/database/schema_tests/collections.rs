//! PHP: the stub-backed parts of `Collections\CollectionValidationTest`,
//! `Collections\CollectionGuardsTest`, `Collections\ExtrasTest`,
//! `Collections\GetCollectionThrowsTest`, `CreateCollectionRaceTest`,
//! `CreateCollectionCleanupTest` and `RefusedSchemaChangeTest`.

use super::{Fixture, definition, describe, keys};
use crate::attribute::Attribute;
use crate::collection::{Collection, Update};
use crate::database::testing::{Call, Measures, Reply, block_on, limits};
use crate::database::{METADATA, collection_definition};
use crate::error::{Error, Kind};
use crate::index::Index;
use crate::value::{Array, ArrayKey, Str, Value};

const COLLECTION: &str = "items";

fn create(id: &str, attributes: Vec<Attribute>, indexes: Vec<Index>) -> Collection {
    Collection::create(id, "", attributes, indexes, None, true, Array::new()).unwrap()
}

fn stored() -> Fixture {
    Fixture::new(vec![definition(COLLECTION, vec![Attribute::string("name", 32)], Vec::new())])
}

/// A fixture under shared tables, its collection stored without a tenant,
/// read as tenant 7 (`sharedDatabase()` + `setTenant()`).
fn shared() -> Fixture {
    let f = stored();
    f.db.set_shared_tables(true);
    f.db.set_tenant(Value::Int(7));
    f
}

#[test]
fn an_unknown_key_is_refused_on_create() {
    block_on(async {
        let f = Fixture::new(Vec::new());
        let mut metadata = Array::new();
        metadata.set(ArrayKey::Str(Str::from("color")), Value::Str(Str::from("red")));
        let collection = Collection::create("extras", "", Vec::new(), Vec::new(), None, true, metadata).unwrap();
        let error = f.db.create_collection(collection).await.unwrap_err();
        assert_eq!(describe(&error), (Kind::Structure, "Unknown collection keys: color".to_owned()));
        assert!(f.adapter.calls().is_empty());
    });
}

#[test]
fn creating_an_existing_collection_is_a_duplicate() {
    block_on(async {
        let f = stored();
        let error = f.db.create_collection(create(COLLECTION, Vec::new(), Vec::new())).await.unwrap_err();
        assert_eq!(describe(&error), (Kind::Duplicate, "Collection items already exists".to_owned()));
        assert!(f.adapter.calls_of("createCollection").is_empty());
    });
}

#[test]
fn creating_a_collection_validates_its_permissions() {
    block_on(async {
        let f = Fixture::new(Vec::new());
        let collection =
            Collection::create("perms", "", Vec::new(), Vec::new(), Some(vec!["*".to_owned()]), true, Array::new());
        let error = match collection {
            Ok(collection) => f.db.create_collection(collection).await.unwrap_err(),
            Err(error) => error,
        };
        assert!(error.is_a(Kind::Database), "{error:?}");
        assert!(f.adapter.calls_of("createCollection").is_empty());
    });
}

#[test]
fn creating_a_collection_checks_its_attribute_types() {
    block_on(async {
        let f = Fixture::new(Vec::new());
        let error =
            f.db.create_collection(create("big", vec![Attribute::string("s", limits().string + 1)], Vec::new()))
                .await
                .unwrap_err();
        assert_eq!(describe(&error), (Kind::Database, "Max size allowed for string is: 16,777,215".to_owned()));
    });
}

#[test]
fn creating_a_collection_enforces_the_attribute_index_and_size_limits() {
    block_on(async {
        let f = Fixture::new(Vec::new());
        let attributes = vec![Attribute::string("a", 32), Attribute::string("b", 32)];

        f.adapter.set_limits(crate::adapter::Limits { attributes: 1, ..limits() });
        f.adapter.set_measures(Measures { attributes: Some(-1), ..Measures::default() });
        let error = f.db.create_collection(create("limits", attributes.clone(), Vec::new())).await.unwrap_err();
        assert_eq!(
            describe(&error),
            (Kind::Limit, "Attribute limit of 1 exceeded. Cannot create collection.".to_owned())
        );

        f.adapter.set_limits(crate::adapter::Limits { document_size: 10, ..limits() });
        f.adapter.set_measures(Measures { width: Some(11), ..Measures::default() });
        let error = f.db.create_collection(create("limits", attributes.clone(), Vec::new())).await.unwrap_err();
        assert_eq!(
            describe(&error),
            (Kind::Limit, "Document size limit of 10 exceeded. Cannot create collection.".to_owned())
        );

        f.adapter.set_limits(crate::adapter::Limits { indexes: 1, ..limits() });
        f.adapter.set_measures(Measures { indexes: Some(2), ..Measures::default() });
        let indexes = vec![Index::key("i", ["a"], Vec::new(), Vec::new()).unwrap()];
        let error = f.db.create_collection(create("limits", attributes, indexes)).await.unwrap_err();
        assert_eq!(describe(&error), (Kind::Limit, "Index limit of 1 exceeded. Cannot create collection.".to_owned()));
        assert!(f.adapter.calls_of("createCollection").is_empty());
    });
}

#[test]
fn creating_a_collection_validates_its_indexes() {
    block_on(async {
        let f = Fixture::new(Vec::new());
        let indexes = vec![Index::key("i", ["missing"], Vec::new(), Vec::new()).unwrap()];
        let error =
            f.db.create_collection(create("indexed", vec![Attribute::string("a", 32)], indexes)).await.unwrap_err();
        assert!(error.is_a(Kind::Index), "{error:?}");
        assert!(f.adapter.calls_of("createCollection").is_empty());
    });
}

#[test]
fn a_collection_the_adapter_does_not_create_stores_no_definition() {
    block_on(async {
        let f = Fixture::new(Vec::new());
        f.on(|call| matches!(call, Call::CreateCollection { .. }).then_some(Reply::Bool(false)));
        let error = f.db.create_collection(create("refused", Vec::new(), Vec::new())).await.unwrap_err();
        assert_eq!(describe(&error), (Kind::Refused, "Failed to create collection".to_owned()));
        assert!(f.adapter.calls_of("createDocument").is_empty());
    });
}

#[test]
fn creating_the_metadata_collection_returns_its_definition_without_storing_it() {
    block_on(async {
        let f = Fixture::new(Vec::new());
        let created = f.db.create_collection(collection_definition()).await.unwrap();
        assert_eq!(created.id(), METADATA);
        assert_eq!(f.methods(), ["createCollection"]);

        // An existing metadata table is reused.
        f.on(|call| {
            matches!(call, Call::CreateCollection { .. }).then(|| Reply::Error(Error::duplicate("Table exists")))
        });
        assert_eq!(f.db.create_collection(collection_definition()).await.unwrap().id(), METADATA);
        assert!(f.adapter.calls_of("deleteCollection").is_empty());
    });
}

#[test]
#[ignore = "waits on the cache area (purgeCachedDocument)"]
fn a_table_this_call_did_not_create_is_kept_and_reported_as_a_duplicate() {
    block_on(async {
        let f = Fixture::new(Vec::new());
        f.on(|call| {
            matches!(call, Call::CreateCollection { .. }).then(|| Reply::Error(Error::duplicate("Table exists")))
        });
        let error = f.db.create_collection(create("preCommitCreate", Vec::new(), Vec::new())).await.unwrap_err();
        assert_eq!(describe(&error), (Kind::Duplicate, "Collection preCommitCreate already exists".to_owned()));
        assert!(error.previous().is_some_and(|p| p.is_a(Kind::Duplicate)));
        assert!(f.adapter.calls_of("deleteCollection").is_empty());
        assert!(f.adapter.calls_of("createDocument").is_empty());
    });
}

#[test]
#[ignore = "waits on the writes area (updateDocument/createDocument/deleteDocument)"]
fn a_collection_whose_definition_is_not_stored_drops_its_table() {
    block_on(async {
        let f = Fixture::new(Vec::new());
        f.on(|call| {
            matches!(call, Call::CreateDocument { collection, .. } if collection.id() == METADATA)
                .then(|| Reply::Error(Error::new(Kind::Runtime, "the metadata is down")))
        });
        let error = f.db.create_collection(create("dropped", Vec::new(), Vec::new())).await.unwrap_err();
        assert_eq!(
            describe(&error),
            (Kind::Database, "Failed to create collection metadata for 'dropped': the metadata is down".to_owned())
        );
        assert_eq!(f.adapter.calls_of("deleteCollection").len(), 1);
    });
}

#[test]
#[ignore = "waits on the writes area (updateDocument/createDocument/deleteDocument)"]
fn an_unconfirmed_definition_keeps_its_table() {
    block_on(async {
        let f = Fixture::new(Vec::new());
        f.on(|call| {
            matches!(call, Call::CreateDocument { collection, .. } if collection.id() == METADATA)
                .then(|| Reply::Error(Error::unconfirmed("commit unconfirmed")))
        });
        let error = f.db.create_collection(create("kept", Vec::new(), Vec::new())).await.unwrap_err();
        assert!(error.is_a(Kind::Unconfirmed));
        assert!(f.adapter.calls_of("deleteCollection").is_empty());
    });
}

#[test]
#[ignore = "waits on the writes area (updateDocument/createDocument/deleteDocument)"]
fn creating_a_collection_stores_its_normalised_definition() {
    block_on(async {
        let f = Fixture::new(Vec::new());
        let created = f.db.create_collection(create("fresh", vec![Attribute::string("name", 32)], Vec::new())).await;
        let created = created.unwrap();
        assert_eq!(keys(created.attributes().unwrap()), ["name"]);
        assert_eq!(created.permissions().unwrap(), ["create(\"any\")"]);
    });
}

#[test]
fn deleting_a_missing_collection_is_not_found() {
    block_on(async {
        let f = Fixture::new(Vec::new());
        let error = f.db.delete_collection("missing").await.unwrap_err();
        assert_eq!(describe(&error), (Kind::NotFound, "Collection not found".to_owned()));
    });
}

#[test]
fn a_refused_collection_drop_keeps_its_definition() {
    block_on(async {
        let f = stored();
        f.on(|call| matches!(call, Call::DeleteCollection { .. }).then_some(Reply::Bool(false)));
        let error = f.db.delete_collection(COLLECTION).await.unwrap_err();
        assert_eq!(describe(&error), (Kind::Refused, "Failed to delete collection".to_owned()));
        assert!(f.adapter.calls_of("deleteDocument").is_empty());
    });
}

#[test]
#[ignore = "waits on the writes area (updateDocument/createDocument/deleteDocument)"]
fn a_definition_that_cannot_be_deleted_restores_the_table() {
    block_on(async {
        let f = stored();
        f.on(|call| {
            matches!(call, Call::DeleteDocument { collection, .. } if collection.id() == METADATA)
                .then(|| Reply::Error(Error::new(Kind::Runtime, "the definition could not be deleted")))
        });
        let error = f.db.delete_collection(COLLECTION).await.unwrap_err();
        assert_eq!(
            describe(&error),
            (
                Kind::Database,
                "Failed to persist metadata for collection deletion 'items': the definition could not be deleted"
                    .to_owned()
            )
        );
        assert_eq!(f.adapter.calls_of("createCollection").len(), 1, "the table is created again");
    });
}

#[test]
fn a_tenant_cannot_change_delete_or_measure_a_tenantless_collection() {
    block_on(async {
        let f = shared();
        let any = crate::role::Role::any();
        let update =
            Update { permissions: Some(vec![crate::permission::Permission::read(&any)]), document_security: None };
        let error = f.db.update_collection(COLLECTION, update).await.unwrap_err();
        assert_eq!(describe(&error), (Kind::NotFound, "Collection not found".to_owned()));
        let error = f.db.delete_collection(COLLECTION).await.unwrap_err();
        assert_eq!(describe(&error), (Kind::NotFound, "Collection not found".to_owned()));
        let error = f.db.size_of_collection(COLLECTION).await.unwrap_err();
        assert_eq!(describe(&error), (Kind::NotFound, "Collection not found".to_owned()));
        let error = f.db.size_of_collection_on_disk(COLLECTION).await.unwrap_err();
        assert_eq!(describe(&error), (Kind::NotFound, "Collection not found".to_owned()));
        assert!(f.adapter.calls_of("deleteCollection").is_empty());
        assert_eq!(f.write_count(), 0);
    });
}

#[test]
fn the_size_on_disk_needs_a_tenant_under_shared_tables() {
    block_on(async {
        let f = stored();
        f.db.set_shared_tables(true);
        let error = f.db.size_of_collection_on_disk(COLLECTION).await.unwrap_err();
        assert_eq!(
            describe(&error),
            (Kind::Database, "Missing tenant. Tenant must be set when table sharing is enabled.".to_owned())
        );
    });
}

#[test]
fn sizes_and_analysis_are_the_adapters() {
    block_on(async {
        let f = stored();
        f.on(|call| match call {
            Call::SizeOfCollection { collection } if collection == COLLECTION => Some(Reply::Int(42)),
            Call::SizeOfCollectionOnDisk { collection } if collection == COLLECTION => Some(Reply::Int(4096)),
            Call::AnalyzeCollection { .. } => Some(Reply::Bool(true)),
            _ => None,
        });
        assert_eq!(f.db.size_of_collection(COLLECTION).await.unwrap(), 42);
        assert_eq!(f.db.size_of_collection_on_disk(COLLECTION).await.unwrap(), 4096);
        assert!(f.db.analyze_collection(COLLECTION).await.unwrap());
        let error = f.db.size_of_collection("missing").await.unwrap_err();
        assert_eq!(describe(&error), (Kind::NotFound, "Collection not found".to_owned()));
    });
}

#[test]
fn updating_a_collection_validates_permissions_and_finds_it() {
    block_on(async {
        let f = stored();
        let error = f
            .db
            .update_collection(COLLECTION, Update { permissions: Some(vec!["*".to_owned()]), document_security: None })
            .await
            .unwrap_err();
        assert!(error.is_a(Kind::Database), "{error:?}");
        let error =
            f.db.update_collection("missing", Update { permissions: None, document_security: Some(false) })
                .await
                .unwrap_err();
        assert_eq!(describe(&error), (Kind::NotFound, "Collection not found".to_owned()));
    });
}

#[test]
fn an_empty_collection_update_returns_the_definition_without_a_write() {
    block_on(async {
        let f = stored();
        let definition = f.db.update_collection(COLLECTION, Update::default()).await.unwrap();
        assert_eq!(definition.id(), COLLECTION);
        assert_eq!(f.write_count(), 0);
    });
}

#[test]
#[ignore = "waits on the writes area (updateDocument/createDocument/deleteDocument)"]
fn updating_a_collection_changes_only_the_given_fields() {
    block_on(async {
        let f = stored();
        let updated =
            f.db.update_collection(COLLECTION, Update { permissions: None, document_security: Some(false) })
                .await
                .unwrap();
        assert!(!updated.document_security());
        assert_eq!(updated.permissions().unwrap(), f.stored(COLLECTION).permissions().unwrap());
    });
}

#[test]
fn get_and_find_collection_read_the_stored_definition() {
    block_on(async {
        let f = stored();
        assert_eq!(keys(f.db.get_collection(COLLECTION).await.unwrap().attributes().unwrap()), ["name"]);
        assert!(f.db.find_collection("missing").await.unwrap().is_none());
        assert_eq!(f.db.get_collection(METADATA).await.unwrap().id(), METADATA);
        let error = f.db.get_collection("missing").await.unwrap_err();
        assert_eq!(describe(&error), (Kind::NotFound, "Collection not found".to_owned()));
    });
}
