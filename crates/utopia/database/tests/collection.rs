//! Ported from utopia-php/database 8.1.3 `tests/unit/Model/CollectionTest.php`,
//! `Model/CollectionUpdateTest.php`, `Model/CollectionFromDocumentTest.php`,
//! `tests/unit/CollectionDefinitionTest.php` and the pure tests of
//! `tests/unit/Collections/CollectionDefinitionTest.php`.
//!
//! Not ported:
//! - `CollectionTest::testConstructorIsPrivate`, `CollectionUpdateTest::testPropertiesAreReadonly`:
//!   PHP visibility rules.
//! - `CollectionTest::testAttributeModelsWrittenStraightIntoStorageAreRejected`,
//!   `testIndexModelsWrittenStraightIntoStorageAreRejected`: a Rust [`Value`]
//!   cannot hold an `Attribute` or `Index` model (the type system rules the
//!   mistake out); `testFromArrayStoresModelsAndArraysAsDocuments` is ported
//!   with the models given as their documents.
//! - `CollectionTest::testTheFingerprintIsStableUntilTheSchemaChanges`,
//!   `testTheFingerprintFollowsEverySchemaChange`,
//!   `testFingerprintsOfDifferentSchemasWithNanDefaultsDiffer`:
//!   `Collection::fingerprint` is ported on another branch (src/serialize.rs).
//! - `CollectionFromDocumentTest::testACollectionIsReturnedAsItIs`,
//!   `testTheHydratedAttributesOfACollectionAreKept`: Rust's `from_document`
//!   takes a plain document; a `Collection` is used as it is without a call.
//! - `CollectionDefinitionTest::testMetadataSchemaHasNoExternalId`, and from
//!   `Collections/CollectionDefinitionTest`: `testGetCollectionReturnsTheDefinitionForTheMetadataCollection`,
//!   `testInternalAttributesFollowTheSharedTablesSetting`: need a `Database`.
//!   (`internalAttributes()` is ported through `database::internal_attributes`.)

mod common;

use common::{arr, assert_error, document, list, v};
use utopia_database::collection::Update;
use utopia_database::database::{self as db, collection_definition, internal_attributes};
use utopia_database::document::{self as d, SetType};
use utopia_database::filter::Filter;
use utopia_database::value::{Array, Value};
use utopia_database::{Attribute, Collection, Document, Index, Kind, Permission, Role};
use utopia_query::OrderDirection;
use utopia_query::schema::{ColumnType, IndexType};

fn create(id: &str) -> Collection {
    Collection::create(id, "", vec![], vec![], None, true, Array::new()).unwrap()
}

fn books() -> Collection {
    Collection::create(
        "books",
        "",
        vec![Attribute::string("title", 128)],
        vec![Index::key("by_title", ["title"], vec![], vec![]).unwrap()],
        None,
        true,
        Array::new(),
    )
    .unwrap()
}

fn attribute_keys(c: &Collection) -> Vec<String> {
    c.attributes().unwrap().iter().map(|a| a.key.clone()).collect()
}

fn index_keys(c: &Collection) -> Vec<String> {
    c.indexes().unwrap().iter().map(|i| i.key.clone()).collect()
}

fn attribute_documents(c: &Collection) -> Vec<Array> {
    c.attributes().unwrap().iter().map(|a| a.to_document().unwrap().array_copy()).collect()
}

fn index_documents(c: &Collection) -> Vec<Array> {
    c.indexes().unwrap().iter().map(|i| i.to_document().array_copy()).collect()
}

fn attr_doc(a: Attribute) -> Value {
    Value::from(a.to_document().unwrap())
}

fn index_doc(i: Index) -> Value {
    Value::from(i.to_document())
}

fn key_by_pages() -> Index {
    Index::key("by_pages", ["pages"], vec![], vec![]).unwrap()
}

/// Whether the models are the same hydrated instances (PHP's `assertSame`
/// on lists of objects).
fn same_attributes(a: &[Attribute], c: &Collection) -> bool {
    std::ptr::eq(a.as_ptr(), c.attributes().unwrap().as_ptr())
}

fn same_indexes(i: &[Index], c: &Collection) -> bool {
    std::ptr::eq(i.as_ptr(), c.indexes().unwrap().as_ptr())
}

// CollectionTest

#[test]
fn test_create_stores_every_field() {
    let c = Collection::create(
        "books",
        "Books",
        vec![Attribute::string("title", 128), Attribute::integer("pages")],
        vec![Index::key("by_title", ["title"], vec![], vec![Some(OrderDirection::Asc)]).unwrap()],
        Some(vec![Permission::read(&Role::any())]),
        false,
        Array::new(),
    )
    .unwrap();
    assert_eq!(c.id(), "books");
    assert_eq!(c.name(), "Books");
    assert_eq!(attribute_keys(&c), ["title", "pages"]);
    let kinds: Vec<ColumnType> = c.attributes().unwrap().iter().map(|a| a.kind).collect();
    assert_eq!(kinds, [ColumnType::String, ColumnType::Integer]);
    assert_eq!(index_keys(&c), ["by_title"]);
    assert_eq!(c.indexes().unwrap()[0].orders, [Some(OrderDirection::Asc)]);
    assert_eq!(c.declared_permissions().unwrap(), Some(vec!["read(\"any\")".to_owned()]));
    assert!(!c.document_security());
    assert_eq!(c.get("documentSecurity"), Some(&v(false)));
}

#[test]
fn test_create_defaults() {
    let c = create("books");
    assert_eq!(c.name(), "books");
    assert!(c.attributes().unwrap().is_empty());
    assert!(c.indexes().unwrap().is_empty());
    assert_eq!(c.declared_permissions().unwrap(), None);
    assert!(c.document_security());
    assert_eq!(c.get("documentSecurity"), Some(&v(true)));
    assert_eq!(c.class(), "Utopia\\Database\\Collection");
}

#[test]
fn test_create_stores_attributes_and_indexes_as_documents() {
    let c = Collection::create(
        "books",
        "",
        vec![Attribute::string("title", 128)],
        vec![Index::fulltext("search", ["title"])],
        None,
        true,
        Array::new(),
    )
    .unwrap();
    let attributes = c.documents("attributes").unwrap();
    let indexes = c.documents("indexes").unwrap();
    assert!(c.array("attributes").values().all(|x| utopia_database::value::as_document(x).is_some()));
    assert!(c.array("indexes").values().all(|x| utopia_database::value::as_document(x).is_some()));
    assert_eq!(attributes[0].get("key"), Some(&v("title")));
    assert_eq!(indexes[0].get("type"), Some(&v("fulltext")));
}

#[test]
fn test_create_with_no_permissions_declares_an_empty_list() {
    let c = Collection::create("books", "", vec![], vec![], Some(vec![]), true, Array::new()).unwrap();
    assert_eq!(c.declared_permissions().unwrap(), Some(vec![]));
}

#[test]
fn test_create_keeps_metadata_in_storage() {
    let c = Collection::create("books", "", vec![], vec![], None, true, arr! {"category" => "fiction", "shelf" => 4})
        .unwrap();
    assert_eq!(c.get("category"), Some(&v("fiction")));
    assert_eq!(c.get("shelf"), Some(&v(4)));
    assert_eq!(c.to_document().get("category"), Some(&v("fiction")));
}

#[test]
fn test_from_array_keeps_extras_and_normalises_the_core_keys() {
    let c = Collection::from_array(arr! {
        "$id" => "books",
        "attributes" => list![arr! {"$id" => "title", "key" => "title", "type" => "string", "size" => 128, "required" => false, "signed" => true, "array" => false, "filters" => list![]}],
        "indexes" => list![arr! {"$id" => "by_title", "key" => "by_title", "type" => "key", "attributes" => list!["title"], "lengths" => list![], "orders" => list!["desc"]}],
        "category" => "fiction",
    })
    .unwrap();
    assert_eq!(c.name(), "books");
    assert!(c.document_security());
    assert_eq!(c.get("documentSecurity"), Some(&v(true)));
    assert_eq!(c.declared_permissions().unwrap(), None);
    assert_eq!(c.get("category"), Some(&v("fiction")));
    assert_eq!(c.attributes().unwrap()[0].key, "title");
    assert_eq!(c.attributes().unwrap()[0].kind, ColumnType::String);
    assert_eq!(c.indexes().unwrap()[0].orders, [Some(OrderDirection::Desc)]);
}

#[test]
fn test_from_array_stores_models_and_arrays_as_documents() {
    let c = Collection::from_array(arr! {
        "$id" => "books",
        "attributes" => list![attr_doc(Attribute::string("title", 128)), arr! {"key" => "pages", "type" => "integer"}],
        "indexes" => list![
            index_doc(Index::key("by_title", ["title"], vec![], vec![]).unwrap()),
            arr! {"key" => "by_pages", "type" => "key", "attributes" => list!["pages"]},
        ],
    })
    .unwrap();
    assert!(c.array("attributes").values().all(|x| utopia_database::value::as_document(x).is_some()));
    assert!(c.array("indexes").values().all(|x| utopia_database::value::as_document(x).is_some()));
    assert_eq!(attribute_keys(&c), ["title", "pages"]);
    assert_eq!(index_keys(&c), ["by_title", "by_pages"]);
}

#[test]
fn test_from_array_reads_the_stored_document_security() {
    assert!(!Collection::from_array(arr! {"$id" => "books", "documentSecurity" => false}).unwrap().document_security());
}

#[test]
fn test_from_array_drops_permissions_that_are_not_a_list() {
    let c = Collection::from_array(arr! {"$id" => "books", "$permissions" => "read(\"any\")"}).unwrap();
    assert_eq!(c.declared_permissions().unwrap(), None);
}

#[test]
fn test_from_array_rejects_invalid_permission_entries() {
    assert_error(
        Collection::from_array(arr! {"$id" => "books", "$permissions" => list![1]}),
        Kind::Structure,
        "Every permission must be of type string",
    );
}

#[test]
fn test_from_array_without_an_id_is_empty_named() {
    let c = Collection::from_array(Array::new()).unwrap();
    assert_eq!(c.id(), "");
    assert_eq!(c.name(), "");
    assert!(c.attributes().unwrap().is_empty());
}

#[test]
fn test_from_array_keeps_encoded_lists_until_they_are_decoded() {
    let c = Collection::from_array(arr! {"$id" => "books", "attributes" => "[]", "indexes" => "[]"}).unwrap();
    assert_eq!(c.get("attributes"), Some(&v("[]")));
    assert!(c.attributes().unwrap().is_empty());
    assert!(c.indexes().unwrap().is_empty());
}

#[test]
fn test_from_array_rejects_an_attribute_that_is_not_a_document() {
    assert_error(
        Collection::from_array(arr! {"$id" => "books", "attributes" => list!["title"]}),
        Kind::Structure,
        "Collection attributes must be attribute documents",
    );
}

#[test]
fn test_from_array_rejects_an_index_that_is_not_a_document() {
    assert_error(
        Collection::from_array(arr! {"$id" => "books", "indexes" => list![7]}),
        Kind::Index,
        "Collection indexes must be index documents",
    );
}

#[test]
fn test_to_document_is_a_plain_independent_copy() {
    let c = Collection::create(
        "books",
        "",
        vec![Attribute::string("title", 128)],
        vec![],
        None,
        true,
        arr! {"category" => "fiction"},
    )
    .unwrap();
    let mut document = c.to_document();
    assert_eq!(document.class(), d::CLASS);
    assert_eq!(c.array_copy(), document.array_copy());

    document.set("category", v("poetry")).unwrap();
    if let Some(Value::Array(attributes)) = document.get_mut("attributes") {
        utopia_database::value::as_document_mut(attributes.get_mut(&0.into()).unwrap())
            .unwrap()
            .set("key", v("heading"))
            .unwrap();
    }

    assert_eq!(c.get("category"), Some(&v("fiction")));
    assert_eq!(c.attributes().unwrap()[0].key, "title");
}

#[test]
fn test_name_falls_back_to_the_id() {
    assert_eq!(Collection::from_array(arr! {"$id" => "books", "name" => ""}).unwrap().name(), "books");
    assert_eq!(Collection::from_array(arr! {"$id" => "books", "name" => 5}).unwrap().name(), "books");
    assert_eq!(Collection::from_array(arr! {"$id" => "books", "name" => "Books"}).unwrap().name(), "Books");
}

#[test]
fn test_declared_permissions_follow_storage() {
    let mut c = create("books");
    c.set("$permissions", v(list![Permission::read(&Role::any())])).unwrap();
    assert_eq!(c.declared_permissions().unwrap(), Some(vec!["read(\"any\")".to_owned()]));
    c.remove("$permissions");
    assert_eq!(c.declared_permissions().unwrap(), None);
}

#[test]
fn test_document_security_follows_storage() {
    let mut c = create("books");
    c.set("documentSecurity", v(false)).unwrap();
    assert!(!c.document_security());
}

#[test]
fn test_attributes_and_indexes_are_hydrated_once_per_instance() {
    let c = books();
    let attributes = c.attributes().unwrap();
    let indexes = c.indexes().unwrap();
    assert!(same_attributes(attributes, &c));
    assert!(same_indexes(indexes, &c));
}

#[test]
fn test_set_attribute_refreshes_the_memo() {
    let mut c = books();
    let _ = c.attributes();
    let _ = c.indexes();
    c.set("attributes", v(list![attr_doc(Attribute::integer("pages"))])).unwrap();
    c.set("indexes", v(list![index_doc(key_by_pages())])).unwrap();
    assert_eq!(attribute_keys(&c), ["pages"]);
    assert_eq!(index_keys(&c), ["by_pages"]);
}

#[test]
fn test_set_attribute_append_refreshes_the_memo() {
    let mut c = books();
    let _ = c.attributes();
    let _ = c.indexes();
    c.set_with("attributes", attr_doc(Attribute::integer("pages")), SetType::Append).unwrap();
    c.set_with("indexes", index_doc(key_by_pages()), SetType::Append).unwrap();
    assert_eq!(attribute_keys(&c), ["title", "pages"]);
    assert_eq!(index_keys(&c), ["by_title", "by_pages"]);
}

#[test]
fn test_set_attribute_prepend_refreshes_the_memo() {
    let mut c = books();
    let _ = c.attributes();
    let _ = c.indexes();
    c.set_with("attributes", attr_doc(Attribute::integer("pages")), SetType::Prepend).unwrap();
    c.set_with("indexes", index_doc(key_by_pages()), SetType::Prepend).unwrap();
    assert_eq!(attribute_keys(&c), ["pages", "title"]);
    assert_eq!(index_keys(&c), ["by_pages", "by_title"]);
}

/// PHP mutates the stored attribute document in place (documents are
/// objects) and sets the list back; Rust's `documents()` hands out copies,
/// so the mutated list is the one set back.
fn renamed_first_attribute(c: &Collection) -> Value {
    let mut list = c.array("attributes");
    if let Some(first) = list.get_mut(&0.into()) {
        utopia_database::value::as_document_mut(first).unwrap().set("key", v("heading")).unwrap();
    }
    Value::Array(list)
}

#[test]
fn test_set_attribute_with_the_same_mutated_list_refreshes_the_memo() {
    let mut c = books();
    let _ = c.attributes();
    let list = renamed_first_attribute(&c);
    c.set("attributes", list).unwrap();
    assert_eq!(attribute_keys(&c), ["heading"]);
}

#[test]
fn test_set_attributes_refreshes_the_memo() {
    let mut c = books();
    let _ = c.attributes();
    let _ = c.indexes();
    c.set_many(arr! {"attributes" => list![attr_doc(Attribute::integer("pages"))], "indexes" => list![]}).unwrap();
    assert_eq!(attribute_keys(&c), ["pages"]);
    assert!(index_keys(&c).is_empty());
}

#[test]
fn test_remove_attribute_refreshes_the_memo() {
    let mut c = books();
    let _ = c.attributes();
    let _ = c.indexes();
    c.remove("attributes");
    c.remove("indexes");
    assert!(c.attributes().unwrap().is_empty());
    assert!(c.indexes().unwrap().is_empty());
}

#[test]
fn test_offset_set_refreshes_the_memo() {
    let mut c = books();
    let _ = c.attributes();
    let _ = c.indexes();
    c.document_mut().as_array_mut().set("attributes".into(), v(list![attr_doc(Attribute::integer("pages"))]));
    c.document_mut()
        .as_array_mut()
        .set("indexes".into(), v(list![index_doc(Index::unique("by_pages", ["pages"], vec![], vec![]).unwrap())]));
    assert_eq!(attribute_keys(&c), ["pages"]);
    assert_eq!(c.indexes().unwrap()[0].kind, IndexType::Unique);
}

#[test]
fn test_offset_unset_refreshes_the_memo() {
    let mut c = books();
    let _ = c.attributes();
    let _ = c.indexes();
    c.document_mut().remove("attributes");
    c.document_mut().remove("indexes");
    assert!(c.attributes().unwrap().is_empty());
    assert!(c.indexes().unwrap().is_empty());
}

#[test]
fn test_exchange_array_refreshes_the_memo() {
    let mut c = books();
    let _ = c.attributes();
    let _ = c.indexes();
    c.exchange_array(arr! {
        "$id" => "books",
        "attributes" => list![attr_doc(Attribute::integer("pages"))],
        "indexes" => list![index_doc(key_by_pages())],
    });
    assert_eq!(attribute_keys(&c), ["pages"]);
    assert_eq!(index_keys(&c), ["by_pages"]);
}

#[test]
fn test_append_refreshes_the_memo() {
    let mut c = books();
    let _ = c.attributes();
    let _ = c.indexes();
    c.document_mut().as_array_mut().push(v("unkeyed")).unwrap();
    assert_eq!(attribute_keys(&c), ["title"]);
    assert_eq!(index_keys(&c), ["by_title"]);
}

#[test]
fn test_indirect_write_refreshes_the_memo() {
    let mut c = books();
    let _ = c.attributes();
    let _ = c.indexes();
    if let Some(Value::Array(a)) = c.document_mut().get_mut("attributes") {
        a.push(attr_doc(Attribute::integer("pages"))).unwrap();
    }
    if let Some(Value::Array(i)) = c.document_mut().get_mut("indexes") {
        i.push(index_doc(key_by_pages())).unwrap();
    }
    assert_eq!(attribute_keys(&c), ["title", "pages"]);
    assert_eq!(index_keys(&c), ["by_title", "by_pages"]);
}

#[test]
fn test_a_clone_does_not_share_a_stale_memo() {
    let c = books();
    let _ = c.attributes();
    let mut clone = c.clone();
    clone.set_with("attributes", attr_doc(Attribute::integer("pages")), SetType::Append).unwrap();
    assert_eq!(attribute_keys(&clone), ["title", "pages"]);
    assert_eq!(attribute_keys(&c), ["title"]);
}

#[test]
fn test_a_clone_has_the_original_lists_until_it_is_mutated() {
    let c = books();
    let attributes = attribute_documents(&c);
    let indexes = index_documents(&c);
    let mut clone = c.clone();
    assert_eq!(attributes, attribute_documents(&clone));
    assert_eq!(indexes, index_documents(&clone));

    clone.set_with("attributes", attr_doc(Attribute::integer("pages")), SetType::Append).unwrap();
    clone.set("indexes", v(list![index_doc(Index::unique("by_pages", ["pages"], vec![], vec![]).unwrap())])).unwrap();

    assert_eq!(attribute_keys(&clone), ["title", "pages"]);
    assert_eq!(index_keys(&clone), ["by_pages"]);
    assert_eq!(attributes, attribute_documents(&c));
    assert_eq!(indexes, index_documents(&c));
}

#[test]
fn test_a_clone_of_an_unread_collection_hydrates_its_own_lists() {
    let c = books();
    let mut clone = c.clone();
    clone.set("name", v("Copy")).unwrap();
    assert_eq!(attribute_keys(&clone), ["title"]);
    assert_eq!(index_keys(&clone), ["by_title"]);
    assert_eq!(attribute_keys(&c), ["title"]);
}

#[test]
fn test_in_place_nested_mutation_needs_the_list_set_back() {
    let mut c = books();
    let _ = c.attributes();
    let list = renamed_first_attribute(&c);
    assert_eq!(attribute_keys(&c), ["title"]);
    c.set("attributes", list).unwrap();
    assert_eq!(attribute_keys(&c), ["heading"]);
}

#[test]
fn test_create_rejects_core_keys_in_metadata() {
    for key in ["attributes", "indexes", "documentSecurity"] {
        assert_error(
            Collection::create("books", "", vec![], vec![], None, true, arr! {key => list![]}),
            Kind::Structure,
            &format!("Collection metadata must not set {key}; pass them as arguments"),
        );
    }
    assert_error(
        Collection::create("books", "", vec![], vec![], None, true, arr! {"indexes" => (), "attributes" => ()}),
        Kind::Structure,
        "Collection metadata must not set attributes, indexes; pass them as arguments",
    );
}

#[test]
fn test_storage_writes_to_other_keys_keep_the_lists_correct() {
    let mut c = books();
    let _ = c.attributes();
    c.set("name", v("Renamed")).unwrap();
    assert_eq!(attribute_keys(&c), ["title"]);
    assert_eq!(c.name(), "Renamed");
}

#[test]
fn test_writes_to_permissions_keep_the_hydrated_lists() {
    let mut c = books();
    let attributes = c.attributes().unwrap().as_ptr();
    let indexes = c.indexes().unwrap().as_ptr();
    c.set("$permissions", v(list![Permission::read(&Role::any())])).unwrap();
    c.set("documentSecurity", v(false)).unwrap();
    c.remove("name");
    assert!(std::ptr::eq(attributes, c.attributes().unwrap().as_ptr()));
    assert!(std::ptr::eq(indexes, c.indexes().unwrap().as_ptr()));
}

#[test]
fn test_exchange_array_with_the_same_lists_keeps_the_hydrated_lists() {
    let mut c = books();
    let attributes = c.attributes().unwrap().as_ptr();
    let indexes = c.indexes().unwrap().as_ptr();
    let mut data = c.as_array().clone();
    data.set("name".into(), v("Renamed"));
    c.exchange_array(data);
    assert!(std::ptr::eq(attributes, c.attributes().unwrap().as_ptr()));
    assert!(std::ptr::eq(indexes, c.indexes().unwrap().as_ptr()));
    assert_eq!(c.name(), "Renamed");
}

#[test]
fn test_encoded_lists_are_decoded() {
    let encode = |a: Array| {
        let json = utopia_database::value::to_plain(&Value::Array(list![a])).to_json();
        serde_json::to_string(&json).unwrap()
    };
    let c = Collection::from_array(arr! {
        "$id" => "books",
        "attributes" => encode(Attribute::string("title", 128).to_document().unwrap().array_copy()),
        "indexes" => encode(Index::key("by_title", ["title"], vec![], vec![]).unwrap().to_document().array_copy()),
    })
    .unwrap();
    assert_eq!(attribute_keys(&c), ["title"]);
    assert_eq!(index_keys(&c), ["by_title"]);
    assert!(std::ptr::eq(c.attributes().unwrap().as_ptr(), c.attributes().unwrap().as_ptr()));
}

#[test]
fn test_an_undecodable_attribute_list_is_refused() {
    for stored in [v("{not json"), v("7"), v(7)] {
        let mut c = books();
        c.set("attributes", stored).unwrap();
        assert_error(c.attributes(), Kind::Structure, "Collection attributes must be a list of attribute documents");
    }
}

#[test]
fn test_an_undecodable_index_list_is_refused() {
    for stored in [v("{not json"), v("7"), v(7)] {
        let mut c = books();
        c.set("indexes", stored).unwrap();
        assert_error(c.indexes(), Kind::Index, "Collection indexes must be a list of index documents");
    }
}

#[test]
fn test_attributes_with_internal_are_built_once_per_schema_state() {
    let mut c = books();
    let internal = [Attribute::string("$id", db::LENGTH_KEY).required(true)];
    let keys = |list: Vec<Attribute>| list.into_iter().map(|a| a.key).collect::<Vec<_>>();

    assert_eq!(keys(c.attributes_with(&internal).unwrap()), ["title", "$id"]);

    c.set_with("attributes", attr_doc(Attribute::integer("pages")), SetType::Append).unwrap();
    assert_eq!(keys(c.attributes_with(&internal).unwrap()), ["title", "pages", "$id"]);

    let other = [Attribute::string("$collection", db::LENGTH_KEY).required(true)];
    assert_eq!(keys(c.attributes_with(&other).unwrap()), ["title", "pages", "$collection"]);
}

// CollectionUpdateTest

#[test]
fn test_defaults_change_nothing() {
    let u = Update::default();
    assert_eq!(u.permissions, None);
    assert_eq!(u.document_security, None);
}

#[test]
fn test_carries_only_the_given_changes() {
    let permissions = Update { permissions: Some(vec![Permission::read(&Role::any())]), ..Default::default() };
    let security = Update { document_security: Some(false), ..Default::default() };
    assert_eq!(permissions.permissions, Some(vec!["read(\"any\")".to_owned()]));
    assert_eq!(permissions.document_security, None);
    assert_eq!(security.permissions, None);
    assert_eq!(security.document_security, Some(false));
}

#[test]
fn test_an_empty_permission_list_is_a_change() {
    let u = Update { permissions: Some(vec![]), ..Default::default() };
    assert_eq!(u.permissions, Some(vec![]));
}

// CollectionFromDocumentTest

#[test]
fn test_a_plain_definition_is_hydrated() {
    let definition = Collection::create(
        "books",
        "",
        vec![Attribute::string("title", 64), Attribute::integer("pages").required(true)],
        vec![key_by_pages()],
        None,
        true,
        Array::new(),
    )
    .unwrap()
    .to_document();
    let c = Collection::from_document(&definition).unwrap();
    assert_eq!(c.id(), "books");
    assert_eq!(attribute_keys(&c), ["title", "pages"]);
    assert_eq!(c.attributes().unwrap()[1].kind, ColumnType::Integer);
    assert!(c.attributes().unwrap()[1].required);
    assert_eq!(c.indexes().unwrap()[0].key, "by_pages");
    assert_eq!(c.indexes().unwrap()[0].kind, IndexType::Key);
}

#[test]
fn test_a_plain_definition_without_attributes_has_none() {
    let c = Collection::from_document(&document! {d::ID => "empty"}).unwrap();
    assert!(c.attributes().unwrap().is_empty());
    assert!(c.indexes().unwrap().is_empty());
}

#[test]
fn test_an_attribute_of_an_unknown_type_is_refused() {
    let c = Collection::from_document(&document! {
        d::ID => "books",
        "attributes" => list![document! {d::ID => "title", "type" => "mystery"}],
    })
    .unwrap();
    assert_error(c.attributes(), Kind::Structure, "Unknown attribute type: mystery");
}

// CollectionDefinitionTest (tests/unit and tests/unit/Collections)

fn keys_of(c: &Collection) -> Vec<String> {
    attribute_keys(c)
}

#[test]
fn test_collection_definition_has_no_external_id() {
    let definition = collection_definition();
    assert!(!keys_of(&definition).contains(&"externalId".to_owned()));
    assert!(!definition.is_set("externalId"));
}

#[test]
fn test_collection_definition_keys() {
    let definition = collection_definition();
    assert_eq!(definition.id(), db::METADATA);
    assert_eq!(definition.get(d::COLLECTION), Some(&v(db::METADATA)));
    assert_eq!(definition.name(), "collections");
    assert_eq!(keys_of(&definition), ["name", "attributes", "indexes", "documentSecurity"]);
}

#[test]
fn test_collection_definition_attribute_types() {
    let definition = collection_definition();
    let by_key = |k: &str| definition.attributes().unwrap().iter().find(|a| a.key == k).unwrap().clone();
    assert_eq!(by_key("name").kind, ColumnType::String);
    assert_eq!(by_key("name").size, Some(256));
    assert!(by_key("name").required);
    assert_eq!(by_key("attributes").kind, ColumnType::String);
    assert_eq!(by_key("attributes").size, Some(1_000_000));
    assert!(by_key("attributes").filters.contains(&Filter::Json.as_str().to_owned()));
    assert_eq!(by_key("indexes").kind, ColumnType::String);
    assert_eq!(by_key("indexes").size, Some(1_000_000));
    assert!(by_key("indexes").filters.contains(&Filter::Json.as_str().to_owned()));
    assert_eq!(by_key("documentSecurity").kind, ColumnType::Boolean);
    assert!(by_key("documentSecurity").required);
}

#[test]
fn test_the_metadata_collection_is_defined() {
    let definition = collection_definition();
    assert_eq!(definition.id(), db::METADATA);
    assert_eq!(definition.name(), "collections");
    assert_eq!(definition.collection(), db::METADATA);
    assert!(!definition.document_security());
    assert!(definition.indexes().unwrap().is_empty());
}

#[test]
fn test_the_metadata_attributes_keep_their_stored_shape() {
    type Shape = (String, ColumnType, Option<i64>, bool, Vec<String>);
    let shapes: Vec<Shape> = collection_definition()
        .attributes()
        .unwrap()
        .iter()
        .map(|a| (a.key.clone(), a.kind, a.size, a.required, a.filters.clone()))
        .collect();
    let json = vec!["json".to_owned()];
    assert_eq!(
        shapes,
        [
            ("name".to_owned(), ColumnType::String, Some(256), true, vec![]),
            ("attributes".to_owned(), ColumnType::String, Some(1_000_000), false, json.clone()),
            ("indexes".to_owned(), ColumnType::String, Some(1_000_000), false, json),
            ("documentSecurity".to_owned(), ColumnType::Boolean, None, true, vec![]),
        ]
    );
}

#[test]
fn test_every_call_returns_an_independent_copy() {
    let mut first = collection_definition();
    first.set("name", v("changed")).unwrap();
    first.set("attributes", v(list![])).unwrap();
    let second = collection_definition();
    assert_eq!(second.name(), "collections");
    assert_eq!(second.attributes().unwrap().len(), 4);
}

#[test]
fn test_internal_attributes_leave_out_the_tenant_without_shared_tables() {
    let keys: Vec<&str> = internal_attributes(false).iter().map(|a| a.key.as_str()).collect();
    assert_eq!(keys, [d::ID, d::SEQUENCE, d::COLLECTION, d::CREATED_AT, d::UPDATED_AT, d::PERMISSIONS]);
}

#[test]
fn test_internal_attributes_include_the_tenant_under_shared_tables() {
    let keys: Vec<&str> = internal_attributes(true).iter().map(|a| a.key.as_str()).collect();
    assert_eq!(keys, [d::ID, d::SEQUENCE, d::COLLECTION, d::TENANT, d::CREATED_AT, d::UPDATED_AT, d::PERMISSIONS]);
}

#[test]
fn test_internal_attributes_keep_their_stored_shape() {
    let expected = [
        (d::ID, list!["string", db::LENGTH_KEY, true, (), true, false, list![]]),
        (d::SEQUENCE, list!["id", 0, true, (), true, false, list![]]),
        (d::COLLECTION, list!["string", db::LENGTH_KEY, true, (), true, false, list![]]),
        (d::TENANT, list!["id", 0, false, (), true, false, list![]]),
        (d::CREATED_AT, list!["datetime", 0, false, (), false, false, list!["datetime"]]),
        (d::UPDATED_AT, list!["datetime", 0, false, (), false, false, list!["datetime"]]),
        (d::PERMISSIONS, list!["string", 1_000_000, false, list![], true, false, list!["json"]]),
    ];
    let attributes = internal_attributes(true);
    assert_eq!(attributes.len(), expected.len());
    for (attribute, (key, shape)) in attributes.iter().zip(expected) {
        let document: Document = attribute.to_document().unwrap();
        let got: Array = ["type", "size", "required", "default", "signed", "array", "filters"]
            .iter()
            .map(|k| document.get(k).cloned().unwrap_or(Value::Null))
            .collect();
        assert_eq!(attribute.key, key);
        assert_eq!(got, shape, "{key}");
    }
}
