//! PHP: `Indexes\CreateIndexesTest`, `Indexes\CreateReturnsStoredTest`,
//! the Memory case of `Indexes\UniqueIndexOverDuplicatesTest`, and the
//! Memory cases of `Indexes\IndexSchemaReconciliationTest`.

use std::sync::Arc;

use utopia_query::OrderDirection;

use super::{index_keys, memory};
use crate::adapter::Adapter;
use crate::attribute::Attribute;
use crate::collection::Collection;
use crate::database::Database;
use crate::database::testing::{Call, Reply, block_on};
use crate::database::testing_memory::Intercepted;
use crate::document::{self, Document};
use crate::error::{Error, Kind};
use crate::index::Index;
use crate::permission::Permission;
use crate::role::Role;
use crate::value::{Array, Str, Value};

fn key(name: &str, attributes: &[&str]) -> Index {
    Index::key(name, attributes.iter().copied(), Vec::new(), Vec::new()).unwrap()
}

fn unique(name: &str, attributes: &[&str]) -> Index {
    Index::unique(name, attributes.iter().copied(), Vec::new(), Vec::new()).unwrap()
}

fn s(v: &str) -> Value {
    Value::Str(Str::from(v))
}

// ---- CreateIndexesTest --------------------------------------------------

async fn users() -> (Arc<Intercepted>, Database) {
    let (adapter, db) = memory().await;
    let any = Role::any();
    let users = Collection::create(
        "users",
        "",
        vec![Attribute::string("email", 128), Attribute::string("name", 64)],
        Vec::new(),
        Some(vec![Permission::create(&any), Permission::read(&any)]),
        false,
        Array::new(),
    )
    .unwrap();
    db.create_collection(users).await.unwrap();
    (adapter, db)
}

async fn create_user(db: &Database, id: &str, email: &str) -> crate::error::Result<Document> {
    let mut user = Document::empty();
    user.set(document::ID, s(id)).unwrap();
    user.set("email", s(email)).unwrap();
    user.set("name", s(id)).unwrap();
    db.create_document("users", user).await
}

async fn assert_no_indexes(db: &Database) {
    assert!(index_keys(db, "users").await.is_empty());
    create_user(db, "one", "same@example.com").await.unwrap();
    create_user(db, "two", "same@example.com").await.unwrap();
    assert_eq!(db.count("users", Vec::new(), None).await.unwrap(), 2);
}

#[test]
fn creates_every_index_and_returns_them_as_stored() {
    block_on(async {
        let (_, db) = users().await;
        let created = db
            .create_indexes(
                "users",
                vec![unique("by_email", &["email"]), Index::key("by_name", ["name"], vec![Some(64)], Vec::new()).unwrap()],
            )
            .await
            .unwrap();
        assert_eq!(created.iter().map(|i| i.key.as_str()).collect::<Vec<_>>(), ["by_email", "by_name"]);
        assert_eq!(created[1].lengths, vec![None]);
        assert_eq!(db.get_collection("users").await.unwrap().indexes().unwrap(), created.as_slice());
        create_user(&db, "one", "same@example.com").await.unwrap();
        assert!(create_user(&db, "two", "same@example.com").await.unwrap_err().is_a(Kind::Duplicate));
    });
}

#[test]
fn an_empty_list_creates_nothing() {
    block_on(async {
        let (_, db) = users().await;
        assert!(db.create_indexes("users", Vec::new()).await.unwrap().is_empty());
        assert!(index_keys(&db, "users").await.is_empty());
    });
}

#[test]
fn an_invalid_index_creates_none() {
    block_on(async {
        let (_, db) = users().await;
        let error =
            db.create_indexes("users", vec![unique("by_email", &["email"]), key("by_missing", &["missing"])]).await;
        let error = error.unwrap_err();
        assert_eq!((error.kind(), error.message()), (Kind::Index, "Invalid index attribute \"missing\" not found"));
        assert_no_indexes(&db).await;
    });
}

#[test]
fn keys_repeated_in_the_batch_create_none() {
    block_on(async {
        let (_, db) = users().await;
        let error = db.create_indexes("users", vec![unique("by_email", &["email"]), key("BY_EMAIL", &["name"])]).await;
        assert!(error.unwrap_err().is_a(Kind::Duplicate));
        assert_no_indexes(&db).await;
    });
}

#[test]
fn a_batch_over_the_index_limit_creates_none() {
    block_on(async {
        let (_, db) = users().await;
        let indexes = (0..65).map(|p| key(&format!("by_name_{p}"), &["name"])).collect();
        assert!(db.create_indexes("users", indexes).await.unwrap_err().is_a(Kind::Limit));
        assert_no_indexes(&db).await;
    });
}

#[test]
fn an_engine_failure_part_way_drops_the_indexes_already_created() {
    block_on(async {
        let (adapter, db) = users().await;
        adapter.on(|call| {
            matches!(call, Call::CreateIndex { index, .. } if index.key == "broken")
                .then(|| Reply::Error(Error::database("Engine refused the index")))
        });
        let error = db.create_indexes("users", vec![unique("by_email", &["email"]), key("broken", &["name"])]).await;
        assert_eq!(error.unwrap_err().message(), "Engine refused the index");
        adapter.off();
        assert_no_indexes(&db).await;
    });
}

// ---- CreateReturnsStoredTest -------------------------------------------

async fn books() -> (Arc<Intercepted>, Database) {
    let (adapter, db) = memory().await;
    let books = Collection::create(
        "books",
        "",
        vec![Attribute::string("title", 64), Attribute::string("isbn", 32)],
        Vec::new(),
        None,
        true,
        Array::new(),
    )
    .unwrap();
    db.create_collection(books).await.unwrap();
    (adapter, db)
}

#[test]
fn create_index_returns_the_stored_model() {
    block_on(async {
        let (_, db) = books().await;
        let created = db
            .create_index("books", Index::key("by_title", ["title"], vec![Some(64)], vec![Some(OrderDirection::Desc)]).unwrap())
            .await
            .unwrap();
        assert_eq!(created.lengths, vec![None]);
        assert_eq!(created.orders, vec![Some(OrderDirection::Desc)]);
        assert_eq!(db.get_collection("books").await.unwrap().indexes().unwrap(), [created]);
    });
}

#[test]
fn create_index_refuses_a_key_differing_only_in_case_and_an_index_without_attributes() {
    block_on(async {
        let (_, db) = books().await;
        db.create_index("books", key("by_title", &["title"])).await.unwrap();
        assert!(db.create_index("books", key("BY_TITLE", &["title"])).await.unwrap_err().is_a(Kind::Duplicate));
        let error = db.create_index("books", key("empty", &[])).await.unwrap_err();
        assert_eq!(error.message(), "Missing attributes");
    });
}

#[test]
fn rename_and_delete_index_update_the_metadata() {
    block_on(async {
        let (_, db) = books().await;
        db.create_index("books", key("by_title", &["title"])).await.unwrap();
        db.create_index("books", unique("by_isbn", &["isbn"])).await.unwrap();
        assert!(db.rename_index("books", "by_title", "by_isbn").await.unwrap_err().is_a(Kind::Duplicate));
        db.rename_index("books", "by_title", "title_lookup").await.unwrap();
        assert_eq!(index_keys(&db, "books").await, ["title_lookup", "by_isbn"]);
        let stored = db.get_collection("books").await.unwrap().indexes().unwrap()[0].clone();
        assert_eq!(stored.attributes, ["title"]);
        db.delete_index("books", "title_lookup").await.unwrap();
        assert_eq!(index_keys(&db, "books").await, ["by_isbn"]);
        assert!(db.delete_index("books", "missing").await.unwrap_err().is_a(Kind::NotFound));
    });
}

// ---- UniqueIndexOverDuplicatesTest (Memory) ----------------------------

#[test]
fn a_unique_index_over_duplicate_values_is_refused_without_metadata() {
    block_on(async {
        let (_, db) = memory().await;
        let any = Role::any();
        let pets = Collection::create(
            "pets",
            "",
            vec![Attribute::string("name", 64), Attribute::integer("age")],
            Vec::new(),
            Some(vec![Permission::create(&any), Permission::read(&any)]),
            false,
            Array::new(),
        )
        .unwrap();
        db.create_collection(pets).await.unwrap();
        let pet = |id: Option<&str>| {
            let mut d = Document::empty();
            if let Some(id) = id {
                d.set(document::ID, s(id)).unwrap();
            }
            d.set("name", s("chester")).unwrap();
            d.set("age", Value::Int(7)).unwrap();
            d
        };
        for id in ["first", "second"] {
            db.create_document("pets", pet(Some(id))).await.unwrap();
        }
        for attribute in ["name", "age"] {
            let error = db.create_index("pets", unique(&format!("unique_{attribute}"), &[attribute])).await.unwrap_err();
            assert!(error.is_a(Kind::Unique), "{attribute}: {error:?}");
            assert!(index_keys(&db, "pets").await.is_empty(), "no metadata is left for {attribute}");
        }
        db.create_document("pets", pet(None)).await.unwrap();
        assert_eq!(db.count("pets", Vec::new(), None).await.unwrap(), 3);
    });
}

// ---- IndexSchemaReconciliationTest (Memory) ----------------------------

async fn catalog() -> (Arc<Intercepted>, Database) {
    let (adapter, db) = memory().await;
    let any = Role::any();
    let catalog = Collection::create(
        "catalog",
        "",
        vec![Attribute::string("name", 32), Attribute::string("sku", 32)],
        vec![key("existing", &["sku"])],
        Some(vec![Permission::create(&any), Permission::read(&any)]),
        true,
        Array::new(),
    )
    .unwrap();
    db.create_collection(catalog).await.unwrap();
    (adapter, db)
}

#[test]
fn an_index_only_in_the_schema_is_adopted() {
    block_on(async {
        let (adapter, db) = catalog().await;
        adapter.on(|call| {
            matches!(call, Call::CreateIndex { index, .. } if index.key == "byName")
                .then(|| Reply::Error(Error::duplicate("Index already exists in the schema")))
        });
        assert_eq!(db.create_index("catalog", key("byName", &["name"])).await.unwrap().key, "byName");
        assert_eq!(index_keys(&db, "catalog").await, ["existing", "byName"]);
    });
}

#[test]
fn renaming_an_index_the_schema_no_longer_has_fails() {
    block_on(async {
        let (adapter, db) = catalog().await;
        adapter.memory().delete_index("catalog", "existing").await.unwrap();
        let error = db.rename_index("catalog", "existing", "renamed").await.unwrap_err();
        assert_eq!((error.kind(), error.message()), (Kind::Refused, "Failed to rename index 'existing' to 'renamed'"));
        assert_eq!(index_keys(&db, "catalog").await, ["existing"]);
        assert!(!adapter.memory().rename_index("catalog", "existing", "renamed").await.unwrap());
    });
}

#[test]
fn renaming_an_index_the_schema_already_renamed_completes() {
    block_on(async {
        let (adapter, db) = catalog().await;
        assert!(adapter.memory().rename_index("catalog", "existing", "renamed").await.unwrap());
        db.rename_index("catalog", "existing", "renamed").await.unwrap();
        assert_eq!(index_keys(&db, "catalog").await, ["renamed"]);
    });
}

#[test]
fn a_rename_the_schema_already_applied_is_completed() {
    block_on(async {
        let (adapter, db) = catalog().await;
        let first = std::sync::atomic::AtomicBool::new(true);
        adapter.on(move |call| {
            (matches!(call, Call::RenameIndex { .. }) && first.swap(false, std::sync::atomic::Ordering::SeqCst))
                .then(|| Reply::Error(Error::not_found("Index not found in the schema")))
        });
        db.rename_index("catalog", "existing", "renamed").await.unwrap();
        let renames: Vec<String> = adapter
            .calls_of("renameIndex")
            .into_iter()
            .map(|c| match c {
                Call::RenameIndex { old, new, .. } => format!("{old}->{new}"),
                _ => unreachable!(),
            })
            .collect();
        assert_eq!(renames, ["existing->renamed", "renamed->existing", "existing->renamed"]);
        assert_eq!(index_keys(&db, "catalog").await, ["renamed"]);
    });
}

#[test]
fn deleting_an_index_the_schema_no_longer_has_succeeds() {
    block_on(async {
        let (adapter, db) = catalog().await;
        adapter.on(|call| {
            matches!(call, Call::DeleteIndex { .. }).then(|| Reply::Error(Error::not_found("Index not found in the schema")))
        });
        db.delete_index("catalog", "existing").await.unwrap();
        assert!(index_keys(&db, "catalog").await.is_empty());
    });
}
