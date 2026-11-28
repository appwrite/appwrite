//! PHP: `UniqueViolationTest` (its Memory cases through the database),
//! `Adapter/PowerNumericTextTest` (its Memory case) and
//! `Documents/UpdateDocumentsCastingTest`.

use std::sync::Arc;

use super::s;
use crate::attribute::Attribute;
use crate::collection::Collection;
use crate::database::Database;
use crate::database::testing::block_on;
use crate::database::testing_memory::{Tweaked, collection, database_on, doc, memory};
use crate::document::Document;
use crate::error::{Error, Kind};
use crate::index::Index;
use crate::operator::Operator;
use crate::permission::Permission;
use crate::query::Query;
use crate::role::Role;
use crate::value::{Array, ArrayKey, Ext, Value, list};

fn int(i: i64) -> Value {
    Value::Int(i)
}

// ---- UniqueViolationTest --------------------------------------------------

const MOVIES: &str = "movies";
const TAKEN_SLUG: &str = "prefix_uid_suffix";

fn movie(id: &str, slug: &str) -> Document {
    doc(vec![("$id", s(id)), ("$permissions", list([s(&Permission::read(&Role::any()))])), ("slug", s(slug))])
}

async fn movies() -> Database {
    let db = memory("unique_violation").await;
    let any = Role::any();
    let definition = Collection::create(
        MOVIES,
        MOVIES,
        vec![Attribute::string("slug", 128)],
        vec![Index::unique("slug_unique", ["slug"], vec![Some(128)], Vec::new()).unwrap()],
        Some(vec![Permission::create(&any), Permission::read(&any), Permission::update(&any)]),
        true,
        Array::new(),
    )
    .unwrap();
    db.create_collection(definition).await.unwrap();
    db.create_documents(MOVIES, vec![movie("movie-1", TAKEN_SLUG), movie("movie-2", "frozen")], 1000, None)
        .await
        .unwrap();
    db
}

#[track_caller]
fn duplicate(err: Error) {
    assert!(err.is_a(Kind::Duplicate) && !err.is_a(Kind::Unique), "{err:?}");
    assert_eq!(err.message(), "Document already exists");
}

#[track_caller]
fn unique(err: Error) {
    assert!(err.is_a(Kind::Unique), "{err:?}");
    assert_eq!(err.message(), "Unique index violation");
}

#[test]
fn a_document_id_conflict_is_a_duplicate() {
    block_on(async {
        let db = movies().await;
        duplicate(db.create_document(MOVIES, movie("movie-1", "sequel")).await.unwrap_err());
    });
}

#[test]
fn unique_index_conflicts_are_unique_violations() {
    block_on(async {
        let db = movies().await;
        unique(db.create_document(MOVIES, movie("movie-3", TAKEN_SLUG)).await.unwrap_err());
        let taken = || doc(vec![("slug", s(TAKEN_SLUG))]);
        unique(db.update_document(MOVIES, "movie-2", taken()).await.unwrap_err());
        let one = vec![Query::equal("$id", vec!["movie-2"])];
        unique(db.update_documents(MOVIES, taken(), one, 1000, None).await.unwrap_err());
        let sequel = doc(vec![("slug", s("sequel"))]);
        unique(db.update_documents(MOVIES, sequel, Vec::new(), 1000, None).await.unwrap_err());
    });
}

// ---- PowerNumericTextTest -------------------------------------------------

#[test]
fn a_numeric_text_exponent_is_applied_and_non_numeric_text_refused() {
    block_on(async {
        let db = memory("power_text").await;
        let any = Role::any();
        let attributes = vec![Attribute::integer("count").default(4), Attribute::float("ratio")];
        let permissions = vec![Permission::create(&any), Permission::read(&any), Permission::update(&any)];
        collection(&db, "items", attributes, permissions, false).await;
        let first = doc(vec![("$id", s("first")), ("count", int(3)), ("ratio", Value::Float(1.5))]);
        db.create_document("items", first).await.unwrap();

        let power = |limit: Option<&str>| Value::from(Operator::power("2", limit.map(Into::into)));
        let updated = db.update_document("items", "first", doc(vec![("count", power(None)), ("ratio", power(None))]));
        let updated = updated.await.unwrap();
        assert_eq!(updated.get("count"), Some(&int(9)));
        assert_eq!(updated.get("ratio"), Some(&Value::Float(2.25)));
        let updated = db.update_document("items", "first", doc(vec![("count", power(Some("50")))])).await.unwrap();
        assert_eq!(updated.get("count"), Some(&int(9)));

        let two = doc(vec![("count", Value::from(Operator::power("two", None)))]);
        let err = db.update_document("items", "first", two).await.unwrap_err();
        assert_eq!(err.kind(), Kind::Structure);
        assert!(err.message().contains("value must be numeric"), "{}", err.message());
        let stored = db.get_document("items", "first", Vec::new(), false).await.unwrap();
        assert_eq!(stored.get("count"), Some(&int(9)));
    });
}

// ---- UpdateDocumentsCastingTest -------------------------------------------

/// PHP's `castBefore()` of the test: an operator on `value` adds 2, and the
/// updates (the document without an id) get a fixed `$updatedAt`.
fn cast(document: &mut Document) {
    let without_id = document.id().is_empty();
    if let Some(Value::Ext(Ext::Operator(operator))) = document.as_array_mut().get_mut(&ArrayKey::from("value")) {
        operator.set_values(vec![int(2)]);
        if without_id {
            document.put("$updatedAt", s("2000-01-01 00:00:00.000"));
        }
    }
}

#[test]
fn updates_are_cast_before_every_batch_write() {
    block_on(async {
        let adapter = Arc::new(Tweaked::new().casting_with(cast));
        let db = database_on(adapter.clone(), "casting").await;
        db.authorization().disable();
        collection(&db, "counters", vec![Attribute::integer("value")], Vec::new(), true).await;
        let documents =
            vec![doc(vec![("$id", s("first")), ("value", int(1))]), doc(vec![("$id", s("second")), ("value", int(2))])];
        db.create_documents("counters", documents, 1000, None).await.unwrap();

        let operator = Operator::increment("1", None);
        let updates = doc(vec![("value", Value::from(operator.clone()))]);
        assert_eq!(db.update_documents("counters", updates, Vec::new(), 1, None).await.unwrap(), 2);
        let received: Vec<Option<String>> =
            adapter.received_updates().iter().map(|u| u.updated_at().map(str::to_owned)).collect();
        let cast_at = Some("2000-01-01 00:00:00.000".to_owned());
        assert_eq!(received, vec![cast_at.clone(), cast_at], "each batch hands the adapter the cast updates");
        assert_eq!(operator.values().get(&ArrayKey::Int(0)), Some(&s("1")), "the caller's operator is left as given");
        let value = |id: &'static str| {
            let db = &db;
            async move { db.get_document("counters", id, Vec::new(), false).await.unwrap().get("value").cloned() }
        };
        assert_eq!(value("first").await, Some(int(3)));
        assert_eq!(value("second").await, Some(int(4)));
    });
}
