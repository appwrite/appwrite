//! PHP: `Attributes\CreateReturnsStoredTest`, `Attributes\RenamePathsTest`,
//! `Attributes\UpdateAttributeTest`, `Attributes\RelaxRequiredTest`,
//! `Attributes\CreateAttributesRollbackTest`, `Attributes\CheckAttributeTest`
//! and `Attributes\AdapterRefusalTest`, on Memory.

use std::sync::Arc;

use super::{attribute_keys, collection, memory};
use crate::attribute::{Attribute, Format, Patch, Update};
use crate::collection::{self, Collection};
use crate::database::Database;
use crate::database::testing::{Call, Reply, block_on};
use crate::database::testing_intercepted::Intercepted;
use crate::document::{self, Document};
use crate::error::{Error, Kind};
use crate::index::Index;
use crate::permission::Permission;
use crate::role::Role;
use crate::value::{Array, ArrayKey, Str, Value};

async fn stored(db: &Database, collection: &str, key: &str) -> Attribute {
    db.get_collection(collection)
        .await
        .unwrap()
        .attributes()
        .unwrap()
        .iter()
        .find(|a| a.key == key)
        .cloned()
        .unwrap_or_else(|| panic!("attribute {key} is missing from the metadata"))
}

fn from_array(pairs: &[(&str, Value)]) -> Attribute {
    let data: Array = pairs.iter().map(|(k, v)| (ArrayKey::Str(Str::from(*k)), v.clone())).collect();
    Attribute::from_array(&data).unwrap()
}

fn s(v: &str) -> Value {
    Value::Str(Str::from(v))
}

async fn items() -> (Arc<Intercepted>, Database) {
    let (adapter, db) = memory().await;
    db.create_collection(collection("items", Vec::new(), Vec::new())).await.unwrap();
    (adapter, db)
}

// ---- CreateReturnsStoredTest ------------------------------------------

#[test]
fn create_attribute_returns_the_stored_model() {
    block_on(async {
        let (_, db) = items().await;
        let created = db
            .create_attribute(
                "items",
                from_array(&[("key", s("at")), ("type", s("datetime")), ("signed", Value::Bool(true))]),
            )
            .await
            .unwrap();
        assert_eq!(created.filters, ["datetime"]);
        assert!(!created.signed);
        assert_eq!(created, stored(&db, "items", "at").await);
    });
}

#[test]
fn create_attributes_returns_the_stored_models_in_order() {
    block_on(async {
        let (_, db) = items().await;
        let created = db
            .create_attributes(
                "items",
                vec![
                    Attribute::string("title", 32),
                    from_array(&[("key", s("seen")), ("type", s("datetime"))]),
                    Attribute::integer("count"),
                ],
            )
            .await
            .unwrap();
        assert_eq!(created.iter().map(|a| a.key.as_str()).collect::<Vec<_>>(), ["title", "seen", "count"]);
        for attribute in &created {
            assert_eq!(*attribute, stored(&db, "items", &attribute.key).await);
        }
        assert_eq!(created[1].filters, ["datetime"]);
    });
}

#[test]
fn create_attribute_refuses_a_duplicate_key_and_a_missing_collection() {
    block_on(async {
        let (_, db) = items().await;
        db.create_attribute("items", Attribute::string("title", 32)).await.unwrap();
        assert!(db.create_attribute("items", Attribute::string("title", 32)).await.unwrap_err().is_a(Kind::Duplicate));
        assert!(db.create_attribute("missing", Attribute::string("title", 32)).await.unwrap_err().is_a(Kind::NotFound));
    });
}

#[test]
fn create_collection_stores_the_type_filters() {
    block_on(async {
        let (_, db) = memory().await;
        let key = |k: &str| ArrayKey::Str(Str::from(k));
        let attribute: Array = [(key("key"), s("at")), (key("type"), s("datetime"))].into_iter().collect();
        let mut data = Array::new();
        data.set(key(document::ID), s("events"));
        data.set(key(collection::ATTRIBUTES), Value::Array(Array::from_list(vec![Value::Array(attribute)])));
        db.create_collection(Collection::from_array(data).unwrap()).await.unwrap();
        assert_eq!(db.get_collection("events").await.unwrap().attributes().unwrap()[0].filters, ["datetime"]);
    });
}

// ---- RenamePathsTest --------------------------------------------------

async fn renamable() -> Database {
    let (_, db) = memory().await;
    let any = Role::any();
    let items = Collection::create(
        "items",
        "",
        vec![Attribute::string("name", 64), Attribute::string("code", 16)],
        vec![Index::key("name_code", ["name", "code"], Vec::new(), Vec::new()).unwrap()],
        Some(vec![Permission::create(&any), Permission::read(&any)]),
        true,
        Array::new(),
    )
    .unwrap();
    db.create_collection(items).await.unwrap();
    let mut document = Document::empty();
    document.set(document::ID, s("first")).unwrap();
    document.set(document::PERMISSIONS, Value::Array(Array::from_list(vec![s(&Permission::read(&any))]))).unwrap();
    document.set("name", s("First")).unwrap();
    document.set("code", s("f")).unwrap();
    db.create_document("items", document).await.unwrap();
    db
}

async fn assert_renamed(db: &Database) {
    let collection = db.get_collection("items").await.unwrap();
    assert_eq!(collection.attributes().unwrap().iter().map(|a| a.key.as_str()).collect::<Vec<_>>(), ["title", "code"]);
    assert_eq!(collection.indexes().unwrap()[0].attributes, ["title", "code"]);
    let document = db.get_document("items", "first", Vec::new(), false).await.unwrap();
    assert_eq!(document.get("title"), Some(&s("First")));
    assert!(!document.has("name"));
}

#[test]
fn rename_attribute_renames() {
    block_on(async {
        let db = renamable().await;
        db.rename_attribute("items", "name", "title").await.unwrap();
        assert_renamed(&db).await;
    });
}

#[test]
fn update_attribute_with_a_key_renames() {
    block_on(async {
        let db = renamable().await;
        let updated =
            db.update_attribute("items", "name", Update { key: Some("title".to_owned()), ..Update::default() }).await;
        assert_eq!(updated.unwrap().key, "title");
        assert_renamed(&db).await;
    });
}

#[test]
fn renaming_onto_a_key_in_use_is_a_duplicate() {
    block_on(async {
        let db = renamable().await;
        assert!(db.rename_attribute("items", "name", "code").await.unwrap_err().is_a(Kind::Duplicate));
        let update = Update { key: Some("code".to_owned()), ..Update::default() };
        assert!(db.update_attribute("items", "name", update).await.unwrap_err().is_a(Kind::Duplicate));
    });
}

// ---- UpdateAttributeTest ----------------------------------------------

async fn updatable() -> (Arc<Intercepted>, Database) {
    let (adapter, db) = memory().await;
    let any = Role::any();
    let items = Collection::create(
        "items",
        "",
        vec![Attribute::string("name", 64).default(s("none")), Attribute::string("code", 16).required(true)],
        Vec::new(),
        Some(vec![Permission::create(&any), Permission::read(&any)]),
        true,
        Array::new(),
    )
    .unwrap();
    db.create_collection(items).await.unwrap();
    (adapter, db)
}

fn default(value: Value) -> Patch<Value> {
    Patch::Set(value)
}

#[test]
fn unset_fields_keep_their_values() {
    block_on(async {
        let (_, db) = updatable().await;
        let updated =
            db.update_attribute("items", "name", Update { size: Some(128), ..Update::default() }).await.unwrap();
        assert_eq!(updated.size, Some(128));
        assert_eq!(updated.default, s("none"));
        assert!(!updated.required);
        assert_eq!(updated, stored(&db, "items", "name").await);
    });
}

#[test]
fn a_null_default_clears_the_default_and_a_value_sets_it() {
    block_on(async {
        let (_, db) = updatable().await;
        let updated =
            db.update_attribute("items", "name", Update { default: default(Value::Null), ..Update::default() }).await;
        assert_eq!(updated.unwrap().default, Value::Null);
        assert_eq!(stored(&db, "items", "name").await.default, Value::Null);
        db.update_attribute("items", "name", Update { default: default(s("other")), ..Update::default() })
            .await
            .unwrap();
        assert_eq!(stored(&db, "items", "name").await.default, s("other"));
    });
}

#[test]
fn a_default_on_a_required_attribute_is_refused() {
    block_on(async {
        let (_, db) = updatable().await;
        let error =
            db.update_attribute("items", "code", Update { default: default(s("x")), ..Update::default() }).await;
        assert_eq!(error.unwrap_err().message(), "Cannot set a default value on a required attribute");
        assert_eq!(stored(&db, "items", "code").await.default, Value::Null);

        let error = db
            .update_attribute(
                "items",
                "name",
                Update { required: Some(true), default: default(s("kept")), ..Update::default() },
            )
            .await;
        assert_eq!(error.unwrap_err().message(), "Cannot set a default value on a required attribute");
        let name = stored(&db, "items", "name").await;
        assert!(!name.required);
        assert_eq!(name.default, s("none"));
    });
}

#[test]
fn a_required_attribute_takes_a_default_when_made_optional_in_the_same_update() {
    block_on(async {
        let (_, db) = updatable().await;
        let updated = db
            .update_attribute(
                "items",
                "code",
                Update { required: Some(false), default: default(s("x")), ..Update::default() },
            )
            .await
            .unwrap();
        assert!(!updated.required);
        assert_eq!(stored(&db, "items", "code").await.default, s("x"));
    });
}

#[test]
fn making_an_attribute_required_clears_its_default() {
    block_on(async {
        let (_, db) = updatable().await;
        let updated = db.update_attribute("items", "name", Update { required: Some(true), ..Update::default() }).await;
        let updated = updated.unwrap();
        assert!(updated.required);
        assert_eq!(updated.default, Value::Null);
        assert_eq!(stored(&db, "items", "name").await.default, Value::Null);

        let (_, db) = updatable().await;
        let updated = db
            .update_attribute(
                "items",
                "name",
                Update { required: Some(true), default: default(Value::Null), ..Update::default() },
            )
            .await
            .unwrap();
        assert!(updated.required);
        assert_eq!(stored(&db, "items", "name").await.default, Value::Null);
    });
}

#[test]
fn making_an_attribute_optional_relaxes_its_column() {
    block_on(async {
        let (adapter, db) = updatable().await;
        db.update_attribute("items", "code", Update { required: Some(false), ..Update::default() }).await.unwrap();
        assert_eq!(adapter.count("relaxAttributeRequired"), 1);
        assert_eq!(adapter.count("updateAttribute"), 0);
        let mut document = Document::empty();
        document
            .set(document::PERMISSIONS, Value::Array(Array::from_list(vec![s(&Permission::read(&Role::any()))])))
            .unwrap();
        let created = db.create_document("items", document).await.unwrap();
        assert_eq!(created.get("code"), None);
        assert!(!stored(&db, "items", "code").await.required);
    });
}

#[test]
fn format_and_filters_are_replaced_and_an_unknown_format_is_refused() {
    block_on(async {
        let (_, db) = updatable().await;
        db.update_attribute(
            "items",
            "name",
            Update { format: Patch::Set(None), filters: Some(vec!["json".into()]), ..Update::default() },
        )
        .await
        .unwrap();
        let name = stored(&db, "items", "name").await;
        assert_eq!(name.format, None);
        assert_eq!(name.filters, ["json"]);

        let update = Update {
            format: Patch::Set(Some(Format::new("not-registered", Array::new()).unwrap())),
            ..Update::default()
        };
        assert!(db.update_attribute("items", "name", update).await.unwrap_err().is_a(Kind::Database));
    });
}

#[test]
fn missing_attributes_and_collections_are_not_found_and_an_empty_update_changes_nothing() {
    block_on(async {
        let (adapter, db) = updatable().await;
        let required = || Update { required: Some(true), ..Update::default() };
        assert!(db.update_attribute("items", "missing", required()).await.unwrap_err().is_a(Kind::NotFound));
        assert!(db.update_attribute("missing", "name", required()).await.unwrap_err().is_a(Kind::NotFound));
        let before = stored(&db, "items", "name").await;
        adapter.clear_calls();
        assert_eq!(db.update_attribute("items", "name", Update::default()).await.unwrap(), before);
        assert_eq!(stored(&db, "items", "name").await, before);
        assert_eq!(adapter.count("updateDocument"), 0);
    });
}

// ---- RelaxRequiredTest --------------------------------------------------

async fn relaxable() -> (Arc<Intercepted>, Database) {
    let (adapter, db) = memory().await;
    db.create_collection(collection("items", vec![Attribute::string("name", 64).required(true)], Vec::new()))
        .await
        .unwrap();
    (adapter, db)
}

#[test]
fn a_failed_or_refused_relax_leaves_the_attribute_required() {
    block_on(async {
        let (adapter, db) = relaxable().await;
        adapter.on(|call| {
            matches!(call, Call::RelaxAttributeRequired { .. })
                .then(|| Reply::Error(Error::database("Relaxing the column failed")))
        });
        let error = db.update_attribute("items", "name", Update { required: Some(false), ..Update::default() }).await;
        assert_eq!(error.unwrap_err().message(), "Relaxing the column failed");
        assert!(stored(&db, "items", "name").await.required);

        adapter.on(|call| matches!(call, Call::RelaxAttributeRequired { .. }).then_some(Reply::Bool(false)));
        let error = db.update_attribute("items", "name", Update { required: Some(false), ..Update::default() }).await;
        let error = error.unwrap_err();
        assert_eq!((error.kind(), error.message()), (Kind::Refused, "Failed to update attribute"));
        assert!(stored(&db, "items", "name").await.required);
    });
}

#[test]
fn a_relaxed_attribute_accepts_a_null() {
    block_on(async {
        let (_, db) = relaxable().await;
        let updated = db.update_attribute("items", "name", Update { required: Some(false), ..Update::default() }).await;
        assert!(!updated.unwrap().required);
        assert!(!stored(&db, "items", "name").await.required);
        let mut document = Document::empty();
        document.set(document::ID, s("nameless")).unwrap();
        document
            .set(document::PERMISSIONS, Value::Array(Array::from_list(vec![s(&Permission::read(&Role::any()))])))
            .unwrap();
        document.set("name", Value::Null).unwrap();
        assert_eq!(db.create_document("items", document).await.unwrap().id(), "nameless");
        assert_eq!(db.get_document("items", "nameless", Vec::new(), false).await.unwrap().get("name"), None);
    });
}

// ---- CreateAttributesRollbackTest -------------------------------------

const PREFIX: &str =
    "Failed to persist metadata after retries and cleanup encountered errors for attributes creation: ";

/// Memory whose metadata writes fail and whose drops of `locked` columns
/// fail with `drop` (`Column '<key>' is locked` by default).
async fn rolling_back(locked: &'static [&'static str], drop: fn(&str) -> Error) -> (Arc<Intercepted>, Database) {
    let (adapter, db) = memory().await;
    db.create_collection(collection("profiles", Vec::new(), Vec::new())).await.unwrap();
    adapter.on(move |call| match call {
        Call::UpdateDocument { collection, .. } if collection.id() == crate::database::METADATA => {
            Some(Reply::Error(Error::new(Kind::Runtime, "metadata store is read-only")))
        }
        Call::DeleteAttribute { key, .. } if locked.contains(&key.as_str()) => Some(Reply::Error(drop(key))),
        _ => None,
    });
    (adapter, db)
}

fn strings(keys: &[&str]) -> Vec<Attribute> {
    keys.iter().map(|k| Attribute::string(*k, 32)).collect()
}

fn dropped(adapter: &Intercepted, locked: &[&str]) -> Vec<String> {
    adapter
        .calls_of("deleteAttribute")
        .into_iter()
        .filter_map(|c| match c {
            Call::DeleteAttribute { key, .. } if !locked.contains(&key.as_str()) => Some(key),
            _ => None,
        })
        .collect()
}

#[test]
fn cleanup_errors_for_every_column_that_could_not_be_dropped_follow_the_metadata_error() {
    block_on(async {
        let locked: &'static [&'static str] = &["nick", "bio"];
        let (adapter, db) = rolling_back(locked, |key| Error::database(format!("Column '{key}' is locked"))).await;
        let error = db.create_attributes("profiles", strings(&["title", "nick", "bio"])).await.unwrap_err();
        assert_eq!(
            error.message(),
            format!(
                "{PREFIX}metadata store is read-only | Cleanup errors: Column 'nick' is locked, Column 'bio' is locked"
            )
        );
        assert_eq!(error.previous().map(Error::message), Some("metadata store is read-only"));
        assert_eq!(dropped(&adapter, locked), ["title"]);
        adapter.off();
        assert!(attribute_keys(&db, "profiles").await.is_empty());
    });
}

#[test]
fn a_clean_rollback_reports_only_the_metadata_error_and_lets_the_create_be_retried() {
    block_on(async {
        let (adapter, db) = rolling_back(&[], |key| Error::database(key.to_owned())).await;
        let error = db.create_attributes("profiles", strings(&["title", "nick"])).await.unwrap_err();
        assert_eq!(
            error.message(),
            "Failed to persist metadata after retries for attributes creation: metadata store is read-only"
        );
        assert_eq!(dropped(&adapter, &[]), ["title", "nick"]);
        adapter.off();
        assert!(attribute_keys(&db, "profiles").await.is_empty());
        let created = db.create_attributes("profiles", strings(&["title", "nick"])).await.unwrap();
        assert_eq!(created.iter().map(|a| a.key.as_str()).collect::<Vec<_>>(), ["title", "nick"]);
        assert_eq!(attribute_keys(&db, "profiles").await, ["title", "nick"]);
    });
}

#[test]
fn a_driver_error_while_dropping_a_column_is_collected_and_the_remaining_columns_are_still_dropped() {
    block_on(async {
        let locked: &'static [&'static str] = &["nick"];
        let (adapter, db) = rolling_back(locked, |key| {
            Error::new(Kind::Pdo, format!("SQLSTATE[55P03]: lock not available on '{key}'"))
        })
        .await;
        let error = db.create_attributes("profiles", strings(&["nick", "title"])).await.unwrap_err();
        assert_eq!(
            error.message(),
            format!(
                "{PREFIX}metadata store is read-only | Cleanup errors: SQLSTATE[55P03]: lock not available on 'nick'"
            )
        );
        assert_eq!(dropped(&adapter, locked), ["title"]);
    });
}

#[test]
fn an_engine_error_while_dropping_a_column_escapes_the_rollback_unchanged() {
    block_on(async {
        let locked: &'static [&'static str] = &["nick"];
        let (_, db) = rolling_back(locked, |_| {
            Error::new(Kind::TypeError, "deleteAttribute(): Argument #2 ($id) must be of type string")
        })
        .await;
        let error = db.create_attributes("profiles", strings(&["nick", "title"])).await.unwrap_err();
        assert_eq!(error.kind(), Kind::TypeError);
        assert_eq!(error.message(), "deleteAttribute(): Argument #2 ($id) must be of type string");
    });
}

// ---- CheckAttributeTest (Memory limits) --------------------------------

#[test]
fn the_check_counts_the_collections_attributes_against_the_limit() {
    block_on(async {
        let (adapter, db) = memory().await;
        let limits = crate::adapter::Adapter::limits(adapter.as_ref());
        adapter.set_limits(crate::adapter::Limits { attributes: limits.default_attributes + 4, ..(*limits).clone() });
        db.create_collection(collection(
            "items",
            vec![Attribute::string("first", 16), Attribute::string("second", 16), Attribute::string("third", 16)],
            Vec::new(),
        ))
        .await
        .unwrap();
        assert!(db.check_attribute("items", &Attribute::string("fourth", 16)).await.unwrap());
        db.create_attribute("items", Attribute::string("fourth", 16)).await.unwrap();
        assert!(db.check_attribute("items", &Attribute::string("fifth", 16)).await.unwrap_err().is_a(Kind::Limit));
        assert_eq!(attribute_keys(&db, "items").await, ["first", "second", "third", "fourth"]);
        assert!(db.check_attribute("missing", &Attribute::string("first", 16)).await.unwrap_err().is_a(Kind::NotFound));
    });
}

// ---- AdapterRefusalTest ------------------------------------------------

#[test]
fn attributes_the_adapter_did_not_create_stay_out_of_the_metadata() {
    block_on(async {
        let (adapter, db) = items().await;
        adapter.on(|call| matches!(call, Call::CreateAttribute { .. }).then_some(Reply::Bool(false)));
        let error = db.create_attribute("items", Attribute::string("title", 64)).await.unwrap_err();
        assert_eq!((error.kind(), error.message()), (Kind::Refused, "Failed to create attribute"));
        assert!(attribute_keys(&db, "items").await.is_empty());

        adapter.on(|call| match call {
            Call::CreateAttributes { .. } => Some(Reply::Error(Error::duplicate("Attribute already exists"))),
            Call::CreateAttribute { attribute, .. } if attribute.key == "age" => {
                Some(Reply::Error(Error::mismatch("Attribute exists in the shared table with another type")))
            }
            _ => None,
        });
        let error = db
            .create_attributes("items", vec![Attribute::string("title", 64), Attribute::integer("age")])
            .await
            .unwrap_err();
        assert_eq!(error.kind(), Kind::Mismatch);
        adapter.off();
        assert!(attribute_keys(&db, "items").await.is_empty());
    });
}
