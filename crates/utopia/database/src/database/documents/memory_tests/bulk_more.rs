//! PHP: `Documents/BulkWriteJoinTest` (its Memory cases; the Mirror case
//! belongs to the mirror) and `Documents/UpdateDocumentsOperatorDecodeTest`
//! (SQLite in PHP; the decoding is the database's, so here on Memory).

use std::collections::BTreeMap;
use std::sync::{Arc, Mutex};

use super::bulk::wrapped;
use super::{refused, s};
use crate::adapter::memory::Memory;
use crate::attribute::Attribute;
use crate::database::Database;
use crate::database::testing::block_on;
use crate::database::testing_memory::{collection, crud, doc, memory};
use crate::document::Document;
use crate::error::Kind;
use crate::operator::Operator;
use crate::permission::Permission;
use crate::query::Query;
use crate::role::Role;
use crate::value::Value;

fn int(i: i64) -> Value {
    Value::Int(i)
}

// ---- BulkWriteJoinTest ----------------------------------------------------

async fn orders() -> Database {
    let db = memory("bulk_join").await;
    collection(&db, "customers", vec![Attribute::string("name", 255)], crud(), false).await;
    let attributes = vec![Attribute::string("customerId", 255), Attribute::integer("amount")];
    collection(&db, "orders", attributes, crud(), false).await;
    db.create_document("customers", doc(vec![("$id", s("c1")), ("name", s("Customer"))])).await.unwrap();
    let order = doc(vec![("$id", s("o1")), ("customerId", s("c1")), ("amount", int(10))]);
    db.create_document("orders", order).await.unwrap();
    db
}

fn join() -> Query {
    Query::join("customers", "j0", vec![Query::on("customerId", "$id", "=")]).unwrap()
}

async fn amount(db: &Database) -> Option<Value> {
    db.get_document("orders", "o1", Vec::new(), false).await.unwrap().get("amount").cloned()
}

#[test]
fn bulk_writes_reject_joins() {
    block_on(async {
        let db = orders().await;
        let updates = doc(vec![("amount", int(0))]);
        refused(
            db.update_documents("orders", updates.clone(), vec![join()], 1000, None).await,
            Kind::Query,
            "Join queries are not supported for bulk updates",
        );
        assert_eq!(amount(&db).await, Some(int(10)));
        let left = Query::left_join("customers", "j0", vec![Query::on("customerId", "$id", "=")]).unwrap();
        refused(
            db.delete_documents("orders", vec![left], 1000, None).await,
            Kind::Query,
            "Join queries are not supported for bulk deletes",
        );
        assert!(!db.get_document("orders", "o1", Vec::new(), false).await.unwrap().is_empty());

        let without = Box::pin(db.skip_validation(db.update_documents("orders", updates, vec![join()], 1000, None)));
        refused(without.await, Kind::Query, "Join queries are not supported for bulk updates");
        let without = Box::pin(db.skip_validation(db.delete_documents("orders", vec![join()], 1000, None)));
        refused(without.await, Kind::Query, "Join queries are not supported for bulk deletes");
        assert_eq!(amount(&db).await, Some(int(10)));
    });
}

#[test]
fn bulk_writes_without_joins_still_apply() {
    block_on(async {
        let db = orders().await;
        let filter = || vec![Query::equal("customerId", vec!["c1"])];
        assert_eq!(
            db.update_documents("orders", doc(vec![("amount", int(20))]), filter(), 1000, None).await.unwrap(),
            1
        );
        assert_eq!(amount(&db).await, Some(int(20)));
        assert_eq!(db.delete_documents("orders", filter(), 1000, None).await.unwrap(), 1);
        assert!(db.get_document("orders", "o1", Vec::new(), false).await.unwrap().is_empty());
    });
}

// ---- UpdateDocumentsOperatorDecodeTest ------------------------------------

async fn counters() -> Database {
    let cache = Arc::new(utopia_cache::Cache::from_dyn(Arc::new(utopia_cache::None)));
    let db = Database::with_filters(Arc::new(Memory::new()), cache, vec![wrapped()]).unwrap();
    db.set_database("operator_decode").set_namespace(&format!("operator_decode_{}", crate::id::unique().unwrap()));
    db.create(None).await.unwrap();
    let any = Role::any();
    let attributes = vec![Attribute::integer("counter"), Attribute::string("secret", 128).filters(["wrapped"])];
    let permissions = vec![Permission::create(&any), Permission::read(&any), Permission::update(&any)];
    collection(&db, "counters", attributes, permissions, true).await;
    let documents = vec![
        doc(vec![("$id", s("first")), ("counter", int(1)), ("secret", s("alpha"))]),
        doc(vec![("$id", s("second")), ("counter", int(10)), ("secret", s("beta"))]),
    ];
    db.create_documents("counters", documents, 1000, None).await.unwrap();
    db
}

type Seen = BTreeMap<String, (Option<Value>, Option<Value>)>;

async fn update(db: &Database, updates: Document, queries: Vec<Query>) -> (i64, Seen) {
    let seen = Mutex::new(Seen::new());
    let mut on_next = |(document, _): (Document, Option<Document>)| {
        let entry = (document.get("counter").cloned(), document.get("secret").cloned());
        seen.lock().unwrap().insert(document.id().to_owned(), entry);
        Ok(())
    };
    let modified = db.update_documents("counters", updates, queries, 1000, Some(&mut on_next)).await.unwrap();
    let seen = seen.lock().unwrap().clone();
    (modified, seen)
}

fn pair(counter: i64, secret: &str) -> (Option<Value>, Option<Value>) {
    (Some(int(counter)), Some(s(secret)))
}

#[test]
fn an_operator_update_hands_each_document_to_on_next_decoded_once() {
    block_on(async {
        let db = counters().await;
        let updates = doc(vec![("counter", Value::from(Operator::increment(1, None)))]);
        let (modified, seen) = update(&db, updates, vec![Query::order_asc("counter", None)]).await;
        assert_eq!(modified, 2);
        let expected: Seen = [("first".to_owned(), pair(2, "alpha")), ("second".to_owned(), pair(11, "beta"))].into();
        assert_eq!(seen, expected);
    });
}

#[test]
fn an_operator_update_with_selections_decodes_the_selected_attributes_once() {
    block_on(async {
        let db = counters().await;
        let updates = doc(vec![("counter", Value::from(Operator::increment(5, None)))]);
        let queries = vec![Query::select(["counter", "secret"]), Query::equal("$id", vec!["first"])];
        let (_, seen) = update(&db, updates, queries).await;
        let expected: Seen = [("first".to_owned(), pair(6, "alpha"))].into();
        assert_eq!(seen, expected);
    });
}

#[test]
fn a_plain_update_still_decodes_the_written_documents() {
    block_on(async {
        let db = counters().await;
        let (_, seen) = update(&db, doc(vec![("counter", int(7))]), vec![Query::order_asc("counter", None)]).await;
        let expected: Seen = [("first".to_owned(), pair(7, "alpha")), ("second".to_owned(), pair(7, "beta"))].into();
        assert_eq!(seen, expected);
    });
}

#[test]
fn an_operator_update_persists_the_encoded_value_untouched() {
    block_on(async {
        let db = counters().await;
        let updates = doc(vec![("counter", Value::from(Operator::increment(1, None)))]);
        db.update_documents("counters", updates, Vec::new(), 1000, None).await.unwrap();
        let first = db.get_document("counters", "first", Vec::new(), false).await.unwrap();
        assert_eq!(first.get("counter"), Some(&int(2)));
        assert_eq!(first.get("secret"), Some(&s("alpha")));
    });
}
