//! PHP: `Attributes\AttributeValidationTest`, `Attributes\CheckAttributeTest`,
//! `Attributes\AdapterRefusalTest`, and the stub-backed parts of
//! `Attributes\UpdateAttributeValidationTest` and
//! `Attributes\OrphanColumnTypeTest`.

use utopia_query::schema::ColumnType;

use super::{Fixture, definition, describe, keys};
use crate::attribute::{Attribute, Format, Patch, Update};
use crate::capability::Capability;
use crate::database::METADATA;
use crate::database::testing::{Call, Measures, Reply, block_on, limits};
use crate::error::{Error, Kind};
use crate::index::Index;
use crate::schema::Column;
use crate::value::{Array, Str, Value};

const COLLECTION: &str = "testCol";

fn title() -> Attribute {
    Attribute::string("title", 128)
}

fn fixture(attributes: Vec<Attribute>) -> Fixture {
    Fixture::new(vec![definition(COLLECTION, attributes, Vec::new())])
}

#[test]
fn creating_an_attribute_on_a_missing_collection_is_not_found() {
    block_on(async {
        let f = Fixture::new(Vec::new());
        let error = f.db.create_attribute("nonexistent", Attribute::string("name", 128)).await.unwrap_err();
        assert_eq!(describe(&error), (Kind::NotFound, "Collection not found".to_owned()));
    });
}

#[test]
fn creating_an_attribute_rejects_a_duplicate_key() {
    block_on(async {
        let f = fixture(vec![title()]);
        let error = f.db.create_attribute(COLLECTION, title()).await.unwrap_err();
        assert!(error.is_a(Kind::Duplicate), "{error:?}");
        assert_eq!(f.write_count(), 0);
    });
}

#[test]
fn creating_a_string_attribute_validates_its_size() {
    block_on(async {
        let f = fixture(Vec::new());
        let too_big = limits().string + 1;
        let error = f.db.create_attribute(COLLECTION, Attribute::string("bigstr", too_big)).await.unwrap_err();
        assert!(error.message().starts_with("Max size allowed for string"), "{error:?}");
    });
}

#[test]
#[ignore = "waits on the writes area (updateDocument/createDocument/deleteDocument)"]
fn creating_valid_attributes_returns_them_as_stored() {
    block_on(async {
        let f = fixture(Vec::new());
        for attribute in [
            Attribute::string("name", 128),
            Attribute::integer("age"),
            Attribute::boolean("active"),
            Attribute::double("score"),
        ] {
            let created = f.db.create_attribute(COLLECTION, attribute.clone()).await.unwrap();
            assert_eq!(created.key, attribute.key);
            assert_eq!(created.kind, attribute.kind);
        }
        assert_eq!(keys(f.last_write().attributes().unwrap()), ["name", "age", "active", "score"]);
    });
}

#[test]
fn creating_an_attribute_enforces_the_attribute_count_limit() {
    block_on(async {
        let f = fixture(Vec::new());
        f.adapter.set_limits(crate::adapter::Limits { attributes: 2, ..limits() });
        f.adapter.set_measures(Measures { attributes: Some(100), ..Measures::default() });
        let error = f.db.create_attribute(COLLECTION, Attribute::string("extra", 128)).await.unwrap_err();
        assert!(error.is_a(Kind::Limit), "{error:?}");
        assert!(error.message().starts_with("Column limit reached."));
    });
}

#[test]
fn creating_an_attribute_enforces_the_row_width_limit() {
    block_on(async {
        let f = fixture(Vec::new());
        f.adapter.set_limits(crate::adapter::Limits { document_size: 100, ..limits() });
        f.adapter.set_measures(Measures { width: Some(200), ..Measures::default() });
        let error = f.db.create_attribute(COLLECTION, Attribute::string("wide", 128)).await.unwrap_err();
        assert!(error.is_a(Kind::Limit), "{error:?}");
        assert!(error.message().starts_with("Row width limit reached."));
    });
}

#[test]
#[ignore = "waits on the writes area (updateDocument/createDocument/deleteDocument)"]
fn deleting_an_attribute_removes_it_from_the_collection() {
    block_on(async {
        let f = fixture(vec![Attribute::string("name", 128)]);
        f.db.delete_attribute(COLLECTION, "name").await.unwrap();
        assert!(f.last_write().attributes().unwrap().is_empty());
    });
}

#[test]
fn deleting_a_missing_attribute_is_not_found() {
    block_on(async {
        let f = fixture(Vec::new());
        let error = f.db.delete_attribute(COLLECTION, "nonexistent").await.unwrap_err();
        assert_eq!(describe(&error), (Kind::NotFound, "Attribute not found".to_owned()));
    });
}

#[test]
fn renaming_onto_a_used_name_is_a_duplicate() {
    block_on(async {
        let f = fixture(vec![Attribute::string("name", 128), title()]);
        let error = f.db.rename_attribute(COLLECTION, "name", "title").await.unwrap_err();
        assert_eq!(describe(&error), (Kind::Duplicate, "Attribute name already used".to_owned()));
    });
}

#[test]
fn renaming_a_missing_attribute_is_not_found() {
    block_on(async {
        let f = fixture(Vec::new());
        let error = f.db.rename_attribute(COLLECTION, "nonexistent", "newname").await.unwrap_err();
        assert!(error.is_a(Kind::NotFound));
    });
}

#[test]
fn a_batch_validates_each_attribute() {
    block_on(async {
        let f = fixture(vec![Attribute::string("name", 128)]);
        let error = f.db.create_attributes(COLLECTION, vec![Attribute::string("name", 128)]).await.unwrap_err();
        assert!(error.is_a(Kind::Duplicate), "{error:?}");
    });
}

#[test]
fn an_empty_batch_is_refused() {
    block_on(async {
        let f = fixture(Vec::new());
        let error = f.db.create_attributes(COLLECTION, Vec::new()).await.unwrap_err();
        assert_eq!(describe(&error), (Kind::Database, "No attributes to create".to_owned()));
    });
}

#[test]
fn a_batch_on_a_missing_collection_is_not_found() {
    block_on(async {
        let f = Fixture::new(Vec::new());
        let error = f.db.create_attributes("nonexistent", vec![Attribute::string("name", 128)]).await.unwrap_err();
        assert_eq!(describe(&error), (Kind::NotFound, "Collection not found".to_owned()));
    });
}

#[test]
fn a_batch_attribute_without_a_key_is_refused() {
    block_on(async {
        let f = fixture(Vec::new());
        let error = f.db.create_attributes(COLLECTION, vec![Attribute::string("", 128)]).await.unwrap_err();
        assert_eq!(describe(&error), (Kind::Database, "Missing attribute key".to_owned()));
    });
}

// ---- CheckAttributeTest ----------------------------------------------

fn checked(attributes: Vec<Attribute>) -> Fixture {
    let f = fixture(attributes);
    f.adapter.set_limits(crate::adapter::Limits { attributes: 4, ..limits() });
    f.adapter.set_measures(Measures { attributes: Some(-1), ..Measures::default() });
    f
}

#[test]
fn an_attribute_within_the_limit_passes_the_check() {
    block_on(async {
        let f = checked(vec![
            Attribute::string("first", 16),
            Attribute::string("second", 16),
            Attribute::string("third", 16),
        ]);
        assert!(f.db.check_attribute(COLLECTION, &Attribute::string("fourth", 16)).await.unwrap());
    });
}

#[test]
fn an_attribute_over_the_limit_is_refused() {
    block_on(async {
        let f = checked(vec![
            Attribute::string("first", 16),
            Attribute::string("second", 16),
            Attribute::string("third", 16),
            Attribute::string("fourth", 16),
        ]);
        let error = f.db.check_attribute(COLLECTION, &Attribute::string("fifth", 16)).await.unwrap_err();
        assert_eq!(
            describe(&error),
            (
                Kind::Limit,
                "Column limit reached. Cannot create new attribute. Current attribute count is 5 but the maximum is 4. Remove some attributes to free up space.".to_owned()
            )
        );
    });
}

#[test]
fn checking_does_not_store_the_attribute() {
    block_on(async {
        let f = checked(vec![Attribute::string("first", 16)]);
        f.db.check_attribute(COLLECTION, &Attribute::string("second", 16)).await.unwrap();
        assert_eq!(keys(f.db.get_collection(COLLECTION).await.unwrap().attributes().unwrap()), ["first"]);
        assert_eq!(f.write_count(), 0);
    });
}

#[test]
fn checking_on_a_missing_collection_is_not_found() {
    block_on(async {
        let f = checked(Vec::new());
        let error = f.db.check_attribute("missing", &Attribute::string("first", 16)).await.unwrap_err();
        assert!(error.is_a(Kind::NotFound));
    });
}

// ---- AdapterRefusalTest ----------------------------------------------

#[test]
fn an_attribute_the_adapter_did_not_create_is_refused_and_stays_out_of_the_metadata() {
    block_on(async {
        let f = Fixture::new(vec![definition("items", Vec::new(), Vec::new())]);
        f.on(|call| matches!(call, Call::CreateAttribute { .. }).then_some(Reply::Bool(false)));
        let error = f.db.create_attribute("items", Attribute::string("title", 64)).await.unwrap_err();
        assert_eq!(describe(&error), (Kind::Refused, "Failed to create attribute".to_owned()));
        assert_eq!(f.write_count(), 0);
        assert!(f.db.get_collection("items").await.unwrap().attributes().unwrap().is_empty());
    });
}

#[test]
fn a_batch_the_adapter_did_not_create_is_refused_and_stays_out_of_the_metadata() {
    block_on(async {
        let f = Fixture::new(vec![definition("items", Vec::new(), Vec::new())]);
        f.on(|call| matches!(call, Call::CreateAttributes { .. }).then_some(Reply::Bool(false)));
        let error =
            f.db.create_attributes("items", vec![Attribute::string("title", 64), Attribute::integer("age")])
                .await
                .unwrap_err();
        assert_eq!(describe(&error), (Kind::Refused, "Failed to create attributes".to_owned()));
        assert_eq!(f.write_count(), 0);
    });
}

#[test]
fn a_column_not_created_while_creating_a_duplicate_batch_one_by_one_is_refused() {
    block_on(async {
        let f = Fixture::new(vec![definition("items", Vec::new(), Vec::new())]);
        f.on(|call| match call {
            Call::CreateAttributes { .. } => Some(Reply::Error(Error::duplicate("Attribute already exists"))),
            Call::CreateAttribute { attribute, .. } => Some(Reply::Bool(attribute.key != "age")),
            _ => None,
        });
        let error =
            f.db.create_attributes("items", vec![Attribute::string("title", 64), Attribute::integer("age")])
                .await
                .unwrap_err();
        assert_eq!(describe(&error), (Kind::Refused, "Failed to create attribute".to_owned()));
        assert_eq!(f.write_count(), 0);
        // The column this call created is dropped again.
        assert!(
            f.adapter
                .calls_of("deleteAttribute")
                .iter()
                .any(|c| matches!(c, Call::DeleteAttribute { key, .. } if key == "title"))
        );
    });
}

#[test]
fn a_mismatch_while_creating_a_duplicate_batch_one_by_one_reaches_the_caller() {
    block_on(async {
        let f = Fixture::new(vec![definition("items", Vec::new(), Vec::new())]);
        f.on(|call| match call {
            Call::CreateAttributes { .. } => Some(Reply::Error(Error::duplicate("Attribute already exists"))),
            Call::CreateAttribute { attribute, .. } if attribute.key == "age" => {
                Some(Reply::Error(Error::mismatch("Attribute exists in the shared table with another type")))
            }
            _ => None,
        });
        let error =
            f.db.create_attributes("items", vec![Attribute::string("title", 64), Attribute::integer("age")])
                .await
                .unwrap_err();
        assert_eq!(
            describe(&error),
            (Kind::Mismatch, "Attribute exists in the shared table with another type".to_owned())
        );
        assert_eq!(f.write_count(), 0);
    });
}

// ---- UpdateAttributeValidationTest -----------------------------------

fn updatable() -> Fixture {
    fixture(vec![
        Attribute::string("label", 64),
        Attribute::string("name", 64).required(true),
        Attribute::integer("count"),
        Attribute::double("ratio"),
        Attribute::boolean("flag"),
        Attribute::string("tags", 32).array(true),
    ])
}

async fn refused(f: &Fixture, key: &str, update: Update) -> String {
    let error = f.db.update_attribute(COLLECTION, key, update).await.unwrap_err();
    assert!(error.is_a(Kind::Database), "{error:?}");
    error.message().to_owned()
}

#[test]
fn an_update_of_the_metadata_collection_is_refused() {
    block_on(async {
        let f = updatable();
        let error = f.db.update_attribute(METADATA, "name", Update { required: Some(false), ..Update::default() });
        assert_eq!(
            describe(&error.await.unwrap_err()),
            (Kind::Database, "Cannot update metadata attributes".to_owned())
        );
    });
}

#[test]
fn an_update_of_an_unknown_attribute_is_not_found() {
    block_on(async {
        let f = updatable();
        let error =
            f.db.update_attribute(COLLECTION, "missing", Update { size: Some(128), ..Update::default() })
                .await
                .unwrap_err();
        assert_eq!(describe(&error), (Kind::NotFound, "Attribute not found".to_owned()));
    });
}

#[test]
fn an_empty_update_returns_the_stored_attribute_without_a_write() {
    block_on(async {
        let f = updatable();
        let stored = f.db.update_attribute(COLLECTION, "label", Update::default()).await.unwrap();
        assert_eq!(stored, Attribute::string("label", 64));
        assert_eq!(f.write_count(), 0);
    });
}

#[test]
fn a_default_on_a_required_attribute_is_refused() {
    block_on(async {
        let f = updatable();
        let message =
            refused(&f, "name", Update { default: Patch::Set(Value::Str(Str::from("x"))), ..Update::default() }).await;
        assert_eq!(message, "Cannot set a default value on a required attribute");
    });
}

#[test]
fn an_updated_default_of_the_wrong_type_is_refused() {
    block_on(async {
        let f = updatable();
        let set = |v: Value| Update { default: Patch::Set(v), ..Update::default() };
        assert_eq!(
            refused(&f, "count", set(Value::Str(Str::from("x")))).await,
            "Default value x does not match given type integer"
        );
        assert_eq!(refused(&f, "flag", set(Value::Int(1))).await, "Default value 1 does not match given type boolean");
        assert_eq!(
            refused(&f, "ratio", set(Value::Bool(true))).await,
            "Default value 1 does not match given type double"
        );
        assert_eq!(
            refused(&f, "label", set(Value::Array(Array::from_list(vec![Value::Int(1)])))).await,
            "Default value 1 does not match given type string"
        );
    });
}

#[test]
fn the_size_rules_apply_on_update() {
    block_on(async {
        let f = updatable();
        let size = |s: i64| Update { size: Some(s), ..Update::default() };
        assert_eq!(refused(&f, "label", size(0)).await, "Size length is required");
        assert_eq!(refused(&f, "label", size(limits().string + 1)).await, "Max size allowed for string is: 16,777,215");
        assert_eq!(
            refused(&f, "label", Update { kind: Some(ColumnType::Varchar), size: Some(0), ..Update::default() }).await,
            "Size length is required"
        );
        assert_eq!(
            refused(
                &f,
                "label",
                Update { kind: Some(ColumnType::Varchar), size: Some(limits().varchar + 1), ..Update::default() }
            )
            .await,
            "Max size allowed for varchar is: 16,383"
        );
        assert_eq!(refused(&f, "count", size(1_073_741_824)).await, "Max size allowed for int is: 1,073,741,824");
        assert_eq!(refused(&f, "ratio", size(8)).await, "Size must be empty");
        assert_eq!(refused(&f, "flag", size(1)).await, "Size must be empty");
    });
}

#[test]
fn the_object_rules_apply_on_update() {
    block_on(async {
        let f = updatable();
        let object = |size: i64, array: Option<bool>| Update {
            kind: Some(ColumnType::Object),
            size: Some(size),
            array,
            ..Update::default()
        };
        assert_eq!(refused(&f, "label", object(0, None)).await, "Object attributes are not supported");
        f.adapter.support(Capability::Objects);
        let message = refused(&f, "label", Update { kind: Some(ColumnType::Object), ..Update::default() }).await;
        assert_eq!(message, "Size must be empty for object attributes");
        assert_eq!(refused(&f, "label", object(0, Some(true))).await, "Object attributes cannot be arrays");
    });
}

#[test]
fn spatial_and_vector_types_need_their_support_on_update() {
    block_on(async {
        let f = updatable();
        for spatial in [ColumnType::Point, ColumnType::Linestring, ColumnType::Polygon] {
            assert_eq!(
                refused(&f, "label", Update { kind: Some(spatial), size: Some(0), ..Update::default() }).await,
                "Spatial attributes are not supported"
            );
        }
        assert_eq!(
            refused(&f, "label", Update { kind: Some(ColumnType::Vector), size: Some(3), ..Update::default() }).await,
            "Vector types are not supported by the current database"
        );
    });
}

#[test]
fn the_vector_rules_apply_on_update() {
    block_on(async {
        let f = updatable();
        f.adapter.support(Capability::Vectors);
        let vector = |size: i64, array: Option<bool>, default: Patch<Value>| Update {
            kind: Some(ColumnType::Vector),
            size: Some(size),
            array,
            default,
            ..Update::default()
        };
        let list = |items: Vec<Value>| Patch::Set(Value::Array(Array::from_list(items)));
        assert_eq!(
            refused(&f, "label", vector(0, None, Patch::Unchanged)).await,
            "Vector dimensions must be a positive integer"
        );
        assert_eq!(
            refused(&f, "label", vector(16_001, None, Patch::Unchanged)).await,
            "Vector dimensions cannot exceed 16000"
        );
        assert_eq!(
            refused(&f, "label", vector(3, Some(true), Patch::Unchanged)).await,
            "Vector type cannot be an array"
        );
        assert_eq!(
            refused(&f, "label", vector(3, None, Patch::Set(Value::Str(Str::from("x"))))).await,
            "Vector default value must be an array"
        );
        assert_eq!(
            refused(&f, "label", vector(3, None, list(vec![Value::Int(1)]))).await,
            "Vector default value must have exactly 3 elements"
        );
        assert_eq!(
            refused(&f, "label", vector(2, None, list(vec![Value::Int(1), Value::Str(Str::from("a"))]))).await,
            "Vector default value must contain only numeric elements"
        );
    });
}

#[test]
fn a_format_of_another_type_is_refused() {
    block_on(async {
        let f = updatable();
        let update = Update {
            format: Patch::Set(Some(Format::new("no-such-format", Array::new()).unwrap())),
            ..Update::default()
        };
        assert_eq!(
            refused(&f, "label", update).await,
            "Format (\"no-such-format\") not available for this attribute type (\"string\")"
        );
    });
}

#[test]
fn a_relationship_cannot_be_updated_as_an_attribute() {
    block_on(async {
        let f = updatable();
        let error = f
            .db
            .update_attribute(COLLECTION, "label", Update { kind: Some(ColumnType::Relationship), ..Update::default() })
            .await
            .unwrap_err();
        assert!(error.is_a(Kind::Structure), "{error:?}");
    });
}

#[test]
fn an_update_past_the_row_width_limit_is_refused() {
    block_on(async {
        let f = updatable();
        f.adapter.set_limits(crate::adapter::Limits { document_size: 100, ..limits() });
        f.adapter.set_measures(Measures { width: Some(100), ..Measures::default() });
        let error =
            f.db.update_attribute(COLLECTION, "label", Update { size: Some(128), ..Update::default() })
                .await
                .unwrap_err();
        assert_eq!(describe(&error), (Kind::Limit, "Row width limit reached. Cannot update attribute.".to_owned()));
        assert!(f.adapter.calls_of("updateAttribute").is_empty());
    });
}

#[test]
fn an_adapter_that_does_not_update_the_column_fails_the_update() {
    block_on(async {
        let f = updatable();
        f.on(|call| matches!(call, Call::UpdateAttribute { .. }).then_some(Reply::Bool(false)));
        let error =
            f.db.update_attribute(COLLECTION, "label", Update { size: Some(128), ..Update::default() })
                .await
                .unwrap_err();
        assert_eq!(describe(&error), (Kind::Refused, "Failed to update attribute".to_owned()));
        assert_eq!(f.write_count(), 0);
    });
}

#[test]
fn a_rename_onto_a_used_key_by_update_is_a_duplicate() {
    block_on(async {
        let f = updatable();
        let error =
            f.db.update_attribute(COLLECTION, "label", Update { key: Some("count".to_owned()), ..Update::default() })
                .await
                .unwrap_err();
        assert_eq!(describe(&error), (Kind::Duplicate, "Attribute name already used".to_owned()));
    });
}

#[test]
fn a_rename_the_adapter_does_not_apply_is_refused_with_both_names() {
    block_on(async {
        let f = updatable();
        f.on(|call| matches!(call, Call::RenameAttribute { .. }).then_some(Reply::Bool(false)));
        let error = f.db.rename_attribute(COLLECTION, "label", "caption").await.unwrap_err();
        assert_eq!(describe(&error), (Kind::Refused, "Failed to rename attribute 'label' to 'caption'".to_owned()));
    });
}

#[test]
fn a_rename_the_engine_fails_is_wrapped_with_the_cause() {
    block_on(async {
        let f = updatable();
        f.on(|call| matches!(call, Call::RenameAttribute { .. }).then(|| Reply::Error(Error::timeout("slow"))));
        let error = f.db.rename_attribute(COLLECTION, "label", "caption").await.unwrap_err();
        assert_eq!(
            describe(&error),
            (Kind::Database, "Failed to rename attribute 'label' to 'caption': slow".to_owned())
        );
        assert!(error.previous().is_some_and(|p| p.is_a(Kind::Timeout)));
    });
}

#[test]
fn a_rename_onto_a_column_the_schema_holds_is_the_adapters_duplicate_or_not_found() {
    block_on(async {
        let f = updatable();
        f.on(|call| {
            matches!(call, Call::RenameAttribute { .. }).then(|| Reply::Error(Error::duplicate("Column exists")))
        });
        let error = f.db.rename_attribute(COLLECTION, "label", "caption").await.unwrap_err();
        assert_eq!(describe(&error), (Kind::Duplicate, "Column exists".to_owned()));

        f.on(|call| {
            matches!(call, Call::RenameAttribute { .. }).then(|| Reply::Error(Error::not_found("Column missing")))
        });
        let error = f.db.rename_attribute(COLLECTION, "label", "caption").await.unwrap_err();
        assert_eq!(describe(&error), (Kind::NotFound, "Column missing".to_owned()));
    });
}

#[test]
fn deleting_or_renaming_an_indexed_array_with_cast_indexes_is_a_dependency() {
    block_on(async {
        let f = Fixture::new(vec![definition(
            COLLECTION,
            vec![Attribute::string("tags", 32).array(true)],
            vec![Index::key("idx_tags", ["tags"], Vec::new(), Vec::new()).unwrap()],
        )]);
        f.adapter.support(Capability::IndexArrayCast);
        let message = "Attribute can't be deleted or renamed because it is used in an index";
        let error = f.db.delete_attribute(COLLECTION, "tags").await.unwrap_err();
        assert_eq!(describe(&error), (Kind::Dependency, message.to_owned()));
        let error = f.db.rename_attribute(COLLECTION, "tags", "labels").await.unwrap_err();
        assert_eq!(describe(&error), (Kind::Dependency, message.to_owned()));
    });
}

#[test]
fn a_relationship_cannot_be_deleted_as_an_attribute() {
    block_on(async {
        let relationship = crate::relationship::Relationship::one_to_one("other");
        let attribute =
            Attribute::relationship("other", relationship, crate::relationship::RelationshipSide::Parent).unwrap();
        let f = fixture(vec![attribute]);
        let error = f.db.delete_attribute(COLLECTION, "other").await.unwrap_err();
        assert_eq!(describe(&error), (Kind::Database, "Cannot delete relationship as an attribute".to_owned()));
        let error =
            f.db.update_attribute(COLLECTION, "other", Update { required: Some(true), ..Update::default() }).await;
        assert_eq!(
            describe(&error.unwrap_err()),
            (Kind::Database, "Cannot update relationship as an attribute".to_owned())
        );
    });
}

// ---- OrphanColumnTypeTest / SharedColumnTypeTest -----------------------

fn introspecting(columns: Vec<Column>) -> Fixture {
    let f = fixture(Vec::new());
    f.adapter.support(Capability::SchemaIntrospection);
    f.adapter.set_column_type(|attribute| {
        Some(match attribute.kind {
            ColumnType::Integer => "INT".to_owned(),
            _ => "VARCHAR".to_owned(),
        })
    });
    f.on(move |call| matches!(call, Call::SchemaAttributes { .. }).then(|| Reply::Columns(columns.clone())));
    f
}

fn column(name: &str, kind: &str) -> Column {
    Column { name: name.to_owned(), kind: kind.to_owned(), length: None, nullable: true }
}

#[test]
#[ignore = "waits on the writes area (updateDocument/createDocument/deleteDocument)"]
fn an_orphan_column_of_the_same_type_is_reused() {
    block_on(async {
        let f = introspecting(vec![column("age", "INT")]);
        f.db.create_attribute(COLLECTION, Attribute::integer("age")).await.unwrap();
        assert!(f.adapter.calls_of("createAttribute").is_empty());
        assert!(f.adapter.calls_of("deleteAttribute").is_empty());
        assert_eq!(keys(f.last_write().attributes().unwrap()), ["age"]);
    });
}

#[test]
#[ignore = "waits on the writes area (updateDocument/createDocument/deleteDocument)"]
fn an_orphan_column_of_another_type_is_dropped_and_recreated() {
    block_on(async {
        let f = introspecting(vec![column("age", "VARCHAR")]);
        f.db.create_attribute(COLLECTION, Attribute::integer("age")).await.unwrap();
        assert_eq!(f.adapter.calls_of("deleteAttribute").len(), 1);
        assert_eq!(f.adapter.calls_of("createAttribute").len(), 1);
    });
}

#[test]
fn a_shared_orphan_column_of_another_type_is_a_duplicate() {
    block_on(async {
        let f = introspecting(vec![column("age", "VARCHAR")]);
        f.db.set_shared_tables(true);
        f.db.set_tenant(Value::Int(1));
        // The stored definition belongs to no tenant, so it is the tenant's.
        let error = f.db.create_attribute(COLLECTION, Attribute::integer("age")).await.unwrap_err();
        assert_eq!(
            describe(&error),
            (Kind::Duplicate, "Attribute exists in the shared table with another type".to_owned())
        );
        assert!(error.previous().is_some_and(|p| p.is_a(Kind::Duplicate)));
        assert!(f.adapter.calls_of("deleteAttribute").is_empty());
        assert!(f.adapter.calls_of("createAttribute").is_empty());
    });
}
