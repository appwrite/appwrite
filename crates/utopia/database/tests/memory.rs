//! Ports of utopia-php/database 8.1.3's adapter-level tests of
//! `Adapter\Memory`: `tests/unit/Adapter/MemorySchemaPathsTest.php`,
//! `tests/unit/Adapter/MemoryWritePathsTest.php`, the Memory parts of
//! `tests/unit/BigIntegerTest.php`, `Adapter/LimitsTest.php` and
//! `Adapter/AdapterDatabaseRenameTest.php`, and the adapter-level
//! behaviour `Adapter/MemoryAdapterTest.php` and
//! `MemoryPermissionsTenantPerDocumentTest.php` check through a
//! `Database` (grants per tenant, unique values kept across a rename),
//! driven here on the adapter as the database drives it.
//!
//! Deferred until the `Database` is ported: the tests that build one
//! (`MemoryWritePathsTest::testRollbackRestoresARenamedDocument` and
//! `testNullChecksAndUnsupportedMethodsOnAWholeObjectAttribute`, which are
//! covered below on the adapter, `MemoryAdapterTest`'s
//! `testSharedTablesListTenantlessCollections` and
//! `testNoChangeUpdateChecksOnlyTheReadPermission`, the Memory cases of
//! `BigIntegerSpellingTest` and `PowerNumericTextTest`).

#![cfg(feature = "memory")]

mod support;

use serde_json::json;
use utopia_database::adapter::memory::Memory;
use utopia_database::adapter::{Adapter, Find};
use utopia_database::attribute::Attribute;
use utopia_database::collection::Collection;
use utopia_database::operator::{Operator, OperatorType};
use utopia_database::validator::authorization::Authorization;
use utopia_database::validator::big_int::UNSIGNED_MAX;
use utopia_database::value::{Array, ArrayKey, Value, string};
use utopia_database::{Document, Index, Kind, Query};
use utopia_query::{CursorDirection, Method, OrderDirection};

use support::{doc, q};

const DATABASE: &str = "schema_paths";
const PAIRS: &str = "pairs";
const ADDRESSES: &str = "addresses";
const UPDATED_AT: &str = "2026-01-01 00:00:00.000";

fn collection(id: &str) -> Collection {
    Collection::from_document(&doc(json!({"$id": id}))).unwrap()
}

fn disabled() -> std::sync::Arc<Authorization> {
    let authorization = Authorization::new(true);
    authorization.disable();
    std::sync::Arc::new(authorization)
}

fn ids(documents: &[Document]) -> Vec<String> {
    documents.iter().map(|d| d.id().to_owned()).collect()
}

fn get<'a>(array: &'a Array, path: &[&str]) -> Option<&'a Value> {
    let (last, init) = path.split_last()?;
    let mut current = array;
    for key in init {
        match current.get(&ArrayKey::normalize(key.to_string().into())) {
            Some(Value::Array(a)) => current = a,
            _ => return None,
        }
    }
    current.get(&ArrayKey::normalize(last.to_string().into()))
}

fn strings(value: Option<&Value>) -> Vec<String> {
    match value {
        Some(Value::Array(a)) => a.values().map(utopia_database::value::to_text).collect(),
        _ => Vec::new(),
    }
}

/// The definition of `index` in `collection` (`$this->data[$key]['indexes'][$index]`).
fn index_of(adapter: &Memory, collection: &str, index: &str) -> Array {
    let data = adapter.data();
    match get(&data, &[&adapter.storage_key(collection), "indexes", index]) {
        Some(Value::Array(a)) => a.clone(),
        _ => Array::new(),
    }
}

async fn find(adapter: &Memory, collection: &str, queries: Vec<Query>) -> utopia_database::Result<Vec<Document>> {
    adapter.find(&self::collection(collection), queries, Find::default()).await
}

// MemorySchemaPathsTest

async fn schema_adapter() -> Memory {
    let adapter = Memory::new();
    adapter.set_authorization(disabled());
    adapter.set_database(DATABASE);
    adapter.set_namespace(&format!("schema_paths_{}", php_std::system::uniqid("", false)));
    adapter.create(DATABASE).await.unwrap();
    adapter
}

async fn create_pairs(adapter: &Memory) {
    adapter.create_collection(PAIRS, &[], &[]).await.unwrap();
    for attribute in ["a", "b", "c"] {
        adapter.create_attribute(PAIRS, &Attribute::string(attribute, 32)).await.unwrap();
    }
}

fn pair(id: &str, a: &str, b: &str) -> Document {
    doc(json!({"$id": id, "$permissions": [], "a": a, "b": b}))
}

#[utopia_async::test]
async fn test_rollback_undoes_create_collection() {
    let adapter = schema_adapter().await;
    adapter.start_transaction().await.unwrap();
    assert!(adapter.create_collection("rolled", &[], &[]).await.unwrap());
    assert!(adapter.collection_exists(DATABASE, "rolled").await.unwrap());
    adapter.rollback_transaction().await.unwrap();

    assert!(
        !adapter.collection_exists(DATABASE, "rolled").await.unwrap(),
        "A rolled-back collection must leave its database"
    );
    assert!(adapter.create_collection("rolled", &[], &[]).await.unwrap());
}

#[utopia_async::test]
async fn test_delete_attribute_keeps_the_orders_of_the_remaining_index_attributes() {
    let adapter = schema_adapter().await;
    create_pairs(&adapter).await;
    let index = Index::key(
        "by_all",
        ["a", "b", "c"],
        vec![],
        vec![Some(OrderDirection::Asc), Some(OrderDirection::Desc), Some(OrderDirection::Asc)],
    )
    .unwrap();
    adapter.create_index(PAIRS, &index, &[], &[]).await.unwrap();

    assert!(adapter.delete_attribute(PAIRS, "a").await.unwrap());

    let index = index_of(&adapter, PAIRS, "by_all");
    assert_eq!(strings(index.get_str("attributes")), ["b", "c"]);
    assert_eq!(strings(index.get_str("orders")), ["DESC", "ASC"]);
}

#[utopia_async::test]
async fn test_rollback_of_delete_attribute_restores_values_and_indexes() {
    let adapter = schema_adapter().await;
    create_pairs(&adapter).await;
    adapter
        .create_index(PAIRS, &Index::unique("unique_pair", ["a", "b"], vec![], vec![]).unwrap(), &[], &[])
        .await
        .unwrap();
    adapter.create_document(&collection(PAIRS), pair("first", "x", "y")).await.unwrap();

    adapter.start_transaction().await.unwrap();
    adapter.delete_attribute(PAIRS, "a").await.unwrap();
    assert!(adapter.get_document(&collection(PAIRS), "first", vec![], false).await.unwrap().get("a").is_none());
    adapter.rollback_transaction().await.unwrap();

    let stored = adapter.get_document(&collection(PAIRS), "first", vec![], false).await.unwrap();
    assert_eq!(stored.get("a"), Some(&string("x")));
    assert_eq!(strings(index_of(&adapter, PAIRS, "unique_pair").get_str("attributes")), ["a", "b"]);

    let error = adapter.create_document(&collection(PAIRS), pair("second", "x", "y")).await.unwrap_err();
    assert!(error.is_a(Kind::Duplicate));
}

#[utopia_async::test]
async fn test_rollback_of_rename_attribute_restores_the_old_name() {
    let adapter = schema_adapter().await;
    create_pairs(&adapter).await;
    adapter.create_index(PAIRS, &Index::key("by_a", ["a"], vec![], vec![]).unwrap(), &[], &[]).await.unwrap();
    adapter.create_document(&collection(PAIRS), pair("first", "x", "y")).await.unwrap();

    adapter.start_transaction().await.unwrap();
    assert!(adapter.rename_attribute(PAIRS, "a", "renamed").await.unwrap());
    assert_eq!(strings(index_of(&adapter, PAIRS, "by_a").get_str("attributes")), ["renamed"]);
    adapter.rollback_transaction().await.unwrap();

    let stored = adapter.get_document(&collection(PAIRS), "first", vec![], false).await.unwrap();
    assert_eq!(stored.get("a"), Some(&string("x")));
    assert!(stored.get("renamed").is_none());
    assert_eq!(strings(index_of(&adapter, PAIRS, "by_a").get_str("attributes")), ["a"]);
    assert_eq!(ids(&find(&adapter, PAIRS, vec![Query::equal("a", ["x"])]).await.unwrap()), ["first"]);
}

#[utopia_async::test]
async fn test_unvalidated_queries_follow_sql_null_and_method_rules() {
    let adapter = schema_adapter().await;
    create_pairs(&adapter).await;
    adapter.create_document(&collection(PAIRS), pair("first", "x", "y")).await.unwrap();
    adapter.create_document(&collection(PAIRS), pair("second", "z", "y")).await.unwrap();

    assert!(
        find(&adapter, PAIRS, vec![q(Method::NotEqual, "a", json!([null, "x"]))]).await.unwrap().is_empty(),
        "A null candidate makes NOT IN unknown for every row"
    );
    assert!(
        find(&adapter, PAIRS, vec![q(Method::Regex, "a", json!([5]))]).await.unwrap().is_empty(),
        "A non-string pattern matches nothing"
    );

    let error = find(&adapter, PAIRS, vec![q(Method::Exists, "", json!(["a"]))]).await.unwrap_err();
    assert_eq!(error.kind(), Kind::Database);
    assert_eq!(error.message(), "Query method not implemented in the Memory adapter: exists");
}

#[utopia_async::test]
async fn test_unordered_read_returns_rows_in_sequence_order() {
    let adapter = schema_adapter().await;
    create_pairs(&adapter).await;
    for id in ["first", "second", "third"] {
        adapter.create_document(&collection(PAIRS), pair(id, id, "y")).await.unwrap();
    }
    adapter.update_document(&collection(PAIRS), "first", doc(json!({"$id": "renamed"})), true).await.unwrap();

    assert_eq!(ids(&find(&adapter, PAIRS, vec![]).await.unwrap()), ["renamed", "second", "third"]);
    let page = adapter
        .find(&collection(PAIRS), vec![], Find { limit: Some(1), offset: Some(1), ..Find::default() })
        .await
        .unwrap();
    assert_eq!(ids(&page), ["second"]);
}

// MemoryWritePathsTest

async fn create_addresses(adapter: &Memory) {
    adapter.create_collection(ADDRESSES, &[], &[]).await.unwrap();
    adapter.create_attribute(ADDRESSES, &Attribute::string("addr", 128).required(true)).await.unwrap();
    adapter.create_attribute(ADDRESSES, &Attribute::string("label", 32)).await.unwrap();
    adapter.create_attribute(ADDRESSES, &Attribute::double("visits")).await.unwrap();
}

async fn addresses() -> Memory {
    let adapter = Memory::new();
    adapter.set_namespace(&format!("write_paths_{}", php_std::system::uniqid("", false)));
    create_addresses(&adapter).await;
    adapter
}

async fn shared_addresses() -> Memory {
    let adapter = Memory::new();
    adapter.set_namespace(&format!("write_paths_shared_{}", php_std::system::uniqid("", false)));
    adapter.set_shared_tables(true);
    adapter.set_tenant(Value::Int(1));
    create_addresses(&adapter).await;
    adapter
}

fn address(id: &str, addr: &str, visits: Value, sequence: Option<&str>) -> Document {
    let mut document = doc(json!({
        "$id": id,
        "$permissions": [],
        "$updatedAt": "2025-01-01 00:00:00.000",
        "addr": addr,
    }));
    document.put("visits", visits);
    if let Some(sequence) = sequence {
        document.put("$sequence", string(sequence));
    }
    document
}

async fn store_address(adapter: &Memory, id: &str, addr: &str, visits: Value) -> utopia_database::Result<Document> {
    adapter.create_document(&collection(ADDRESSES), address(id, addr, visits, None)).await
}

#[track_caller]
fn assert_duplicate(result: utopia_database::Result<Document>, message: &str) {
    match result {
        Err(e) if e.is_a(Kind::Duplicate) => assert_eq!(e.message(), "Unique index violation"),
        other => panic!("{message}: {other:?}"),
    }
}

#[utopia_async::test]
async fn test_shared_tables_unique_index_over_existing_rows_is_per_tenant() {
    let adapter = shared_addresses().await;
    for tenant in [1, 2] {
        adapter.set_tenant(Value::Int(tenant));
        store_address(&adapter, "home", "x", Value::Null).await.unwrap();
    }

    adapter.set_tenant(Value::Int(1));
    assert!(
        adapter
            .create_index(ADDRESSES, &Index::unique("unique_addr", ["addr"], vec![], vec![]).unwrap(), &[], &[])
            .await
            .unwrap(),
        "Two tenants holding the same value must not block a unique index"
    );

    assert_duplicate(
        store_address(&adapter, "second", "x", Value::Null).await,
        "A same-tenant duplicate must be rejected once the index exists",
    );

    adapter.set_tenant(Value::Int(2));
    let home = adapter.get_document(&collection(ADDRESSES), "home", vec![], false).await.unwrap();
    assert_eq!(home.get("addr"), Some(&string("x")));
}

#[utopia_async::test]
async fn test_shared_tables_unique_index_rejects_duplicates_within_one_tenant() {
    let adapter = shared_addresses().await;
    adapter.set_tenant(Value::Int(1));
    store_address(&adapter, "first", "x", Value::Null).await.unwrap();
    store_address(&adapter, "second", "x", Value::Null).await.unwrap();

    let error = adapter
        .create_index(ADDRESSES, &Index::unique("unique_addr", ["addr"], vec![], vec![]).unwrap(), &[], &[])
        .await
        .unwrap_err();
    assert_eq!(error.kind(), Kind::Unique);
    assert_eq!(error.message(), "Unique index violation");
}

#[utopia_async::test]
async fn test_rolled_back_unique_index_leaves_no_hash_table() {
    let adapter = Memory::new();
    adapter.set_namespace(&format!("unique_rollback_{}", php_std::system::uniqid("", false)));
    create_addresses(&adapter).await;
    store_address(&adapter, "home", "x", Value::Null).await.unwrap();
    let hashes = |adapter: &Memory| match get(&adapter.unique_index_hashes(), &[&adapter.storage_key(ADDRESSES)]) {
        Some(Value::Array(a)) => a.clone(),
        _ => Array::new(),
    };

    adapter.start_transaction().await.unwrap();
    adapter
        .create_index(ADDRESSES, &Index::unique("unique_addr", ["addr"], vec![], vec![]).unwrap(), &[], &[])
        .await
        .unwrap();
    assert!(hashes(&adapter).get_str("unique_addr").is_some());
    adapter.rollback_transaction().await.unwrap();

    assert!(hashes(&adapter).is_empty());
}

#[utopia_async::test]
async fn test_shared_tables_unique_bindings_follow_their_tenant_on_update_and_delete() {
    let adapter = shared_addresses().await;
    adapter.set_tenant(Value::Int(1));
    adapter
        .create_index(ADDRESSES, &Index::unique("unique_addr", ["addr"], vec![], vec![]).unwrap(), &[], &[])
        .await
        .unwrap();
    for tenant in [1, 2] {
        adapter.set_tenant(Value::Int(tenant));
        store_address(&adapter, "home", "x", Value::Null).await.unwrap();
    }

    adapter.set_tenant(Value::Int(2));
    adapter
        .update_document(
            &collection(ADDRESSES),
            "home",
            doc(json!({"$id": "home", "addr": "x", "label": "kept"})),
            true,
        )
        .await
        .unwrap();
    assert!(adapter.delete_document(&collection(ADDRESSES), "home").await.unwrap());

    adapter.set_tenant(Value::Int(1));
    assert_duplicate(
        store_address(&adapter, "second", "x", Value::Null).await,
        "The first tenant's binding must survive the second tenant's update and delete",
    );

    adapter.set_tenant(Value::Int(2));
    store_address(&adapter, "again", "x", Value::Null).await.unwrap();
    let again = adapter.get_document(&collection(ADDRESSES), "again", vec![], false).await.unwrap();
    assert_eq!(again.get("addr"), Some(&string("x")));
}

/// `testRollbackRestoresARenamedDocument`, on the adapter: a rename rolled
/// back by a failed transaction leaves the document under its old id.
#[utopia_async::test]
async fn test_rollback_restores_a_renamed_document() {
    let adapter = addresses().await;
    adapter.set_authorization(disabled());
    adapter
        .create_document(&collection(ADDRESSES), doc(json!({"$id": "a", "addr": "x", "label": "original"})))
        .await
        .unwrap();

    let result: utopia_database::Result<()> = utopia_database::adapter::with_transaction(&adapter, || async {
        adapter
            .update_document(&collection(ADDRESSES), "a", doc(json!({"$id": "b", "label": "renamed"})), false)
            .await?;
        Err(utopia_database::Error::new(Kind::Runtime, "roll back"))
    })
    .await;
    assert_eq!(result.unwrap_err().message(), "roll back", "The transaction must rethrow");

    let a = adapter.get_document(&collection(ADDRESSES), "a", vec![], false).await.unwrap();
    assert_eq!(a.get("label"), Some(&string("original")));
    assert!(adapter.get_document(&collection(ADDRESSES), "b", vec![], false).await.unwrap().is_empty());
    assert_eq!(ids(&find(&adapter, ADDRESSES, vec![]).await.unwrap()), ["a"]);
}

#[utopia_async::test]
async fn test_rollback_undoes_an_increment() {
    let adapter = addresses().await;
    store_address(&adapter, "home", "x", Value::Int(1)).await.unwrap();
    let before = adapter.get_document(&collection(ADDRESSES), "home", vec![], false).await.unwrap();

    adapter.start_transaction().await.unwrap();
    assert!(
        adapter
            .increase_document_attribute(
                &collection(ADDRESSES),
                "home",
                "visits",
                Value::Int(5),
                UPDATED_AT,
                None,
                None
            )
            .await
            .unwrap()
    );
    let during = adapter.get_document(&collection(ADDRESSES), "home", vec![], false).await.unwrap();
    assert_eq!(during.get("visits"), Some(&Value::Int(6)));
    adapter.rollback_transaction().await.unwrap();

    let after = adapter.get_document(&collection(ADDRESSES), "home", vec![], false).await.unwrap();
    assert_eq!(after.get("visits"), Some(&Value::Int(1)));
    assert_eq!(before.updated_at(), after.updated_at());
}

#[utopia_async::test]
async fn test_rollback_of_an_increment_removes_the_value_and_timestamp_it_added() {
    let adapter = addresses().await;
    adapter
        .create_document(&collection(ADDRESSES), doc(json!({"$id": "home", "$permissions": [], "addr": "x"})))
        .await
        .unwrap();
    let before = adapter.get_document(&collection(ADDRESSES), "home", vec![], false).await.unwrap();
    assert!(before.get("visits").is_none());
    assert!(before.updated_at().is_none());

    adapter.start_transaction().await.unwrap();
    assert!(
        adapter
            .increase_document_attribute(
                &collection(ADDRESSES),
                "home",
                "visits",
                Value::Int(5),
                UPDATED_AT,
                None,
                None
            )
            .await
            .unwrap()
    );
    let during = adapter.get_document(&collection(ADDRESSES), "home", vec![], false).await.unwrap();
    assert_eq!(during.get("visits"), Some(&Value::Int(5)));
    adapter.rollback_transaction().await.unwrap();

    let after = adapter.get_document(&collection(ADDRESSES), "home", vec![], false).await.unwrap();
    assert!(after.get("visits").is_none());
    assert!(after.updated_at().is_none());
}

#[utopia_async::test]
async fn test_a_division_or_modulo_by_zero_that_skipped_validation_keeps_the_value() {
    let adapter = addresses().await;
    store_address(&adapter, "home", "x", Value::Int(10)).await.unwrap();

    for method in ["divide", "modulo"] {
        let operator =
            Operator::parse(format!(r#"{{"method":"{method}","attribute":"visits","values":[0]}}"#)).unwrap();
        let mut update = doc(json!({"$id": "home"}));
        update.put("visits", Value::from(operator));
        adapter.update_document(&collection(ADDRESSES), "home", update, true).await.unwrap();

        let home = adapter.get_document(&collection(ADDRESSES), "home", vec![], false).await.unwrap();
        assert_eq!(home.get("visits"), Some(&Value::Int(10)), "{method}");
    }
}

#[utopia_async::test]
async fn test_a_cursor_without_an_order_pages_by_sequence() {
    let adapter = addresses().await;
    adapter.set_authorization(disabled());
    for id in ["first", "second", "third"] {
        store_address(&adapter, id, "x", Value::Null).await.unwrap();
    }
    let second = adapter.get_document(&collection(ADDRESSES), "second", vec![], false).await.unwrap();
    let mut cursor = Array::new();
    cursor.set(ArrayKey::from("$sequence"), second.sequence().cloned().unwrap());

    let after =
        adapter.find(&collection(ADDRESSES), vec![], Find { cursor: cursor.clone(), ..Find::default() }).await.unwrap();
    assert_eq!(ids(&after), ["third"]);
    let before = adapter
        .find(
            &collection(ADDRESSES),
            vec![],
            Find { cursor, cursor_direction: CursorDirection::Before, ..Find::default() },
        )
        .await
        .unwrap();
    assert_eq!(ids(&before), ["first"]);
}

#[utopia_async::test]
async fn test_increment_is_a_no_op_when_the_stored_value_already_violates_the_bound() {
    let adapter = addresses().await;
    store_address(&adapter, "whole", "x", Value::Int(10)).await.unwrap();
    store_address(&adapter, "fraction", "y", Value::Float(10.5)).await.unwrap();

    for (id, stored) in [("whole", Value::Int(10)), ("fraction", Value::Float(10.5))] {
        let c = collection(ADDRESSES);
        assert!(
            adapter
                .increase_document_attribute(&c, id, "visits", Value::Int(1), UPDATED_AT, None, Some(Value::Int(5)))
                .await
                .unwrap()
        );
        assert!(
            adapter
                .increase_document_attribute(&c, id, "visits", Value::Int(-1), UPDATED_AT, Some(Value::Int(20)), None)
                .await
                .unwrap()
        );
        let document = adapter.get_document(&c, id, vec![], false).await.unwrap();
        assert_eq!(document.get("visits"), Some(&stored), "{id}");
    }
}

#[utopia_async::test]
async fn test_batch_mixing_documents_with_and_without_a_sequence_is_rejected() {
    for (first, second) in [(Some("10"), None), (None, Some("10"))] {
        let adapter = addresses().await;
        let documents = vec![address("first", "x", Value::Null, first), address("second", "y", Value::Null, second)];

        let error = adapter.create_documents(&collection(ADDRESSES), documents).await.unwrap_err();
        assert_eq!(error.kind(), Kind::Database);
        assert_eq!(error.message(), "All documents must have an sequence if one is set");

        adapter.set_authorization(disabled());
        assert!(find(&adapter, ADDRESSES, vec![]).await.unwrap().is_empty());
    }
}

/// `testNullChecksAndUnsupportedMethodsOnAWholeObjectAttribute`, on the
/// adapter: the queries carry the attribute type the database's validator
/// gives them.
#[utopia_async::test]
async fn test_null_checks_and_unsupported_methods_on_a_whole_object_attribute() {
    let adapter = addresses().await;
    adapter.set_authorization(disabled());
    adapter.create_attribute(ADDRESSES, &Attribute::object("meta")).await.unwrap();
    adapter
        .create_document(&collection(ADDRESSES), doc(json!({"$id": "filled", "addr": "x", "meta": {"colour": "red"}})))
        .await
        .unwrap();
    adapter
        .create_document(&collection(ADDRESSES), doc(json!({"$id": "empty", "addr": "y", "meta": null})))
        .await
        .unwrap();
    let object = |mut query: Query| {
        query.set_attribute_type("object");
        query
    };

    assert_eq!(ids(&find(&adapter, ADDRESSES, vec![object(Query::is_null("meta"))]).await.unwrap()), ["empty"]);
    assert_eq!(ids(&find(&adapter, ADDRESSES, vec![object(Query::is_not_null("meta"))]).await.unwrap()), ["filled"]);

    let error = find(&adapter, ADDRESSES, vec![object(Query::less_than("meta", "x"))]).await.unwrap_err();
    assert_eq!(error.kind(), Kind::Database);
    assert_eq!(error.message(), "Query method lessThan not supported for object attributes");
}

// BigIntegerTest (Memory)

fn apply(current: Value, operator: Operator) -> utopia_database::Result<Value> {
    Memory::new().apply_operator(&current, &operator)
}

fn big(digits: &str) -> Value {
    string(digits)
}

#[test]
fn test_memory_operator_preserves_unsigned_integer_strings() {
    assert_eq!(apply(Value::Int(i64::MAX), Operator::increment(1, None)).unwrap(), big("9223372036854775808"));
    assert_eq!(apply(big("18446744073709551614"), Operator::increment(1, None)).unwrap(), big("18446744073709551615"));
    assert_eq!(apply(big("9223372036854775808"), Operator::decrement(1, None)).unwrap(), Value::Int(i64::MAX));
}

#[test]
fn test_memory_operators_leave_values_unchanged_when_bounds_are_crossed() {
    let cases: Vec<(Value, Operator, Value)> = vec![
        (Value::Int(10), Operator::increment(100, Some(50.into())), Value::Int(10)),
        (Value::Float(5.0), Operator::decrement(10, Some(0.into())), Value::Float(5.0)),
        (Value::Int(10), Operator::multiply(10, Some(75.into())), Value::Int(10)),
        (Value::Float(100.0), Operator::divide(-4, Some((-10).into())).unwrap(), Value::Float(100.0)),
        (Value::Float(20.0), Operator::divide(-2, Some((-50).into())).unwrap(), Value::Float(-10.0)),
        (Value::Float(80.0), Operator::multiply(0.5, Some(50.into())), Value::Float(40.0)),
        (Value::Float(52.0), Operator::increment(-5, Some(50.into())), Value::Float(47.0)),
        (Value::Int(10), Operator::increment(5, Some(15.into())), Value::Int(15)),
        (Value::Int(10), Operator::decrement(5, Some(5.into())), Value::Int(5)),
        (Value::Float(-10.0), Operator::multiply(-2, Some(50.into())), Value::Float(20.0)),
        (Value::Float(5.0), Operator::power(3, Some(100.into())), Value::Float(5.0)),
        (Value::Float(100.0), Operator::power(0.5, Some(50.into())), Value::Float(10.0)),
        (Value::Float(-4.0), Operator::power(2, Some(20.into())), Value::Float(16.0)),
        (Value::Float(-2.0), Operator::power(3, Some(100.into())), Value::Float(-8.0)),
        (Value::Float(0.0), Operator::power(-1, Some(100.into())), Value::Float(0.0)),
        (Value::Float(-4.0), Operator::power(0.5, Some(100.into())), Value::Float(-4.0)),
        (Value::Int(i64::MAX), Operator::increment(2, Some(i64::MAX.into())), Value::Int(i64::MAX)),
        (Value::Int(i64::MAX), Operator::increment(1, Some(UNSIGNED_MAX.into())), big("9223372036854775808")),
    ];
    for (current, operator, expected) in cases {
        let label = format!("{current:?} {operator:?}");
        let result = apply(current, operator).unwrap();
        assert!(result.strict_eq(&expected), "{label}: {result:?} != {expected:?}");
    }
}

#[test]
fn test_memory_rejects_unbounded_invalid_powers() {
    let error = apply(Value::Float(0.0), Operator::power(-1, None)).unwrap_err();
    assert_eq!(error.kind(), Kind::Limit);
    assert_eq!(error.message(), "Value out of range");
}

#[test]
fn test_memory_honours_a_float_bound_on_the_exact_big_integer_path() {
    let cases: Vec<(&str, Value, Operator, Value)> = vec![
        (
            "increment above a float max",
            Value::Int(i64::MAX - 5),
            Operator::increment(10, Some(9.0e18.into())),
            Value::Int(i64::MAX - 5),
        ),
        (
            "increment above a whole float max",
            Value::Int(i64::MAX),
            Operator::increment(1, Some(100.0.into())),
            Value::Int(i64::MAX),
        ),
        (
            "increment onto a float max",
            Value::Int(i64::MAX),
            Operator::increment(1, Some(9_223_372_036_854_775_808.0.into())),
            big("9223372036854775808"),
        ),
        (
            "increment below a float max",
            Value::Int(i64::MAX),
            Operator::increment(1, Some(1.0e19.into())),
            big("9223372036854775808"),
        ),
        (
            "increment below a whole string max",
            Value::Int(i64::MAX),
            Operator::increment(1, Some("9223372036854775808.0".into())),
            big("9223372036854775808"),
        ),
        (
            "decrement below a float min",
            Value::Int(i64::MIN),
            Operator::decrement(1, Some((-9.0e18).into())),
            Value::Int(i64::MIN),
        ),
        (
            "decrement above a float min",
            Value::Int(i64::MIN),
            Operator::decrement(1, Some((-1.0e19).into())),
            big("-9223372036854775809"),
        ),
        (
            "multiply above a float max",
            Value::Int(i64::MAX),
            Operator::multiply(2, Some(1.5e19.into())),
            Value::Int(i64::MAX),
        ),
        ("unbounded increment", Value::Int(i64::MAX), Operator::increment(1, None), big("9223372036854775808")),
    ];
    for (case, current, operator, expected) in cases {
        let result = apply(current, operator).unwrap();
        assert!(result.strict_eq(&expected), "{case}: {result:?} != {expected:?}");
    }
}

#[test]
fn test_memory_refuses_a_fractional_bound_on_the_exact_big_integer_path() {
    let cases: Vec<(&str, Operator, &str)> = vec![
        (
            "float max",
            Operator::increment(1, Some(102.4.into())),
            "Cannot apply increment operator: max/min limit must be a whole number, got 102.4",
        ),
        (
            "string max",
            Operator::increment(1, Some("9223372036854775808.5".into())),
            "Cannot apply increment operator: max/min limit must be a whole number, got 9223372036854775808.5",
        ),
        (
            "float min",
            Operator::decrement(1, Some((-0.5).into())),
            "Cannot apply decrement operator: max/min limit must be a whole number, got -0.5",
        ),
    ];
    for (case, operator, message) in cases {
        let current =
            if operator.method() == OperatorType::Decrement { Value::Int(i64::MIN) } else { Value::Int(i64::MAX) };
        let error = apply(current, operator).unwrap_err();
        assert_eq!(error.kind(), Kind::Operator, "{case}");
        assert_eq!(error.message(), message, "{case}");
    }
}

#[test]
fn test_memory_keeps_the_stored_value_when_a_bounded_power_overflows() {
    let result = apply(Value::Float(10.0), Operator::power(400, Some(1000.into()))).unwrap();
    assert!(result.strict_eq(&Value::Float(10.0)));
    let error = apply(Value::Float(10.0), Operator::power(400, None)).unwrap_err();
    assert_eq!(error.kind(), Kind::Limit);
    assert_eq!(error.message(), "Value out of range");
}

#[test]
fn test_memory_operators_coerce_operands_and_keep_unparsable_dates() {
    assert!(apply(Value::Int(1), Operator::increment("2", None)).unwrap().strict_eq(&Value::Int(3)));
    assert!(apply(Value::Float(1.5), Operator::multiply("2.5", None)).unwrap().strict_eq(&Value::Float(3.75)));
    let concat_list =
        Operator::new(OperatorType::StringConcat, "", vec![Value::Array(Array::from_list(vec![string("x")]))]);
    assert_eq!(apply(string("ab"), concat_list).unwrap(), string("ab"));
    let wrapped = Value::Array(Array::from_list(vec![string("x")]));
    assert_eq!(apply(wrapped, Operator::string_concat("y")).unwrap(), string("y"));
    assert_eq!(apply(string("not-a-date"), Operator::date_add_days(1)).unwrap(), string("not-a-date"));
    assert_eq!(apply(string("not-a-date"), Operator::date_sub_days(1)).unwrap(), string("not-a-date"));
}

// LimitsTest (Memory)

#[test]
fn test_memory_keeps_a_positive_index_length_and_no_document_size_cap() {
    let limits = Memory::new().limits();
    assert_eq!(limits.attributes, 1017);
    assert_eq!(limits.index_length, 1024);
    assert_eq!(limits.uid_length, 255);
    assert_eq!(limits.document_size, 0);
    assert_eq!(String::from_utf8_lossy(&limits.min_date_time.format(b"Y-m-d H:i:s")), "0001-01-01 00:00:00");
    assert_eq!(limits.id_type, utopia_query::schema::ColumnType::Integer);
    assert!(limits.internal_index_keys.is_empty());
}

// AdapterDatabaseRenameTest (Memory)

#[utopia_async::test]
async fn test_shared_tables_refuse_the_rename() {
    let adapter = Memory::new();
    adapter.set_shared_tables(true);
    let error = adapter.update("library", "archive").await.unwrap_err();
    assert_eq!(error.kind(), Kind::Database);
    assert_eq!(error.message(), "Cannot rename a database while shared tables are enabled");
}

#[utopia_async::test]
async fn test_rename_moves_the_collections_and_rolls_back() {
    let adapter = Memory::new();
    adapter.set_authorization(disabled());
    adapter.set_database("library");
    adapter.create("library").await.unwrap();
    adapter.create_collection("books", &[], &[]).await.unwrap();
    adapter.create_document(&collection("books"), doc(json!({"$id": "one"}))).await.unwrap();

    assert_eq!(adapter.update("missing", "x").await.unwrap_err().kind(), Kind::NotFound);
    adapter.create("taken").await.unwrap();
    assert_eq!(adapter.update("library", "taken").await.unwrap_err().kind(), Kind::Duplicate);

    adapter.start_transaction().await.unwrap();
    assert!(adapter.update("library", "archive").await.unwrap());
    assert!(!adapter.exists("library").await.unwrap());
    assert!(adapter.collection_exists("archive", "books").await.unwrap());
    adapter.set_database("archive");
    assert_eq!(ids(&find(&adapter, "books", vec![]).await.unwrap()), ["one"]);
    adapter.rollback_transaction().await.unwrap();

    adapter.set_database("library");
    assert!(!adapter.exists("archive").await.unwrap());
    assert_eq!(ids(&find(&adapter, "books", vec![]).await.unwrap()), ["one"]);
}

// MemoryAdapterTest, on the adapter

const NOTES: &str = "notes";

fn readers(readers: &[&str]) -> Vec<String> {
    readers.iter().map(|r| format!("read(\"user:{r}\")")).collect()
}

fn note(readers: &[&str]) -> Document {
    doc(json!({"$id": "note", "$permissions": self::readers(readers), "title": "first"}))
}

async fn readable_by(adapter: &Memory, reader: &str) -> Vec<String> {
    let authorization = Authorization::new(true);
    authorization.add_role(&format!("user:{reader}"));
    let previous = adapter.authorization();
    adapter.set_authorization(std::sync::Arc::new(authorization));
    let found = ids(&find(adapter, NOTES, vec![]).await.unwrap());
    adapter.set_authorization(previous);
    found
}

async fn readable_under(adapter: &Memory, tenant: i64, reader: &str) -> Vec<String> {
    adapter.state().tenant().with(Value::Int(tenant), readable_by(adapter, reader)).await
}

async fn notes() -> Memory {
    let adapter = Memory::new();
    adapter.set_database("memory_adapter");
    adapter.set_namespace(&format!("memory_adapter_{}", php_std::system::uniqid("", false)));
    adapter.create("memory_adapter").await.unwrap();
    adapter.create_collection(NOTES, &[Attribute::string("title", 64)], &[]).await.unwrap();
    adapter
}

async fn shared_notes() -> Memory {
    let adapter = Memory::new();
    adapter.set_database("memory_adapter");
    adapter.set_namespace(&format!("memory_adapter_{}", php_std::system::uniqid("", false)));
    adapter.set_shared_tables(true);
    adapter.create("memory_adapter").await.unwrap();
    adapter.create_collection(NOTES, &[Attribute::string("title", 64)], &[]).await.unwrap();
    for tenant in [1, 2] {
        let mut document = note(&["alice", "bob"]);
        document.put("$tenant", Value::Int(tenant));
        adapter
            .state()
            .tenant()
            .with(Value::Int(tenant), adapter.create_document(&collection(NOTES), document))
            .await
            .unwrap();
    }
    adapter
}

#[utopia_async::test]
async fn test_delete_with_another_casing_removes_the_grants() {
    let adapter = notes().await;
    adapter.create_document(&collection(NOTES), note(&["alice"])).await.unwrap();

    assert!(adapter.delete_document(&collection(NOTES), "NOTE").await.unwrap());
    adapter.create_document(&collection(NOTES), note(&["bob"])).await.unwrap();

    assert!(
        readable_by(&adapter, "alice").await.is_empty(),
        "A delete under another casing must remove the deleted document's grants"
    );
    assert_eq!(readable_by(&adapter, "bob").await, ["note"]);
}

#[utopia_async::test]
async fn test_revoke_under_one_tenant_keeps_another_tenants_grants() {
    let adapter = shared_notes().await;
    let revoke = |readers: &[&str]| doc(json!({"$id": "note", "$permissions": self::readers(readers)}));

    adapter
        .state()
        .tenant()
        .with(Value::Int(1), adapter.update_document(&collection(NOTES), "note", revoke(&[]), false))
        .await
        .unwrap();

    assert!(readable_under(&adapter, 1, "alice").await.is_empty());
    assert_eq!(
        readable_under(&adapter, 2, "alice").await,
        ["note"],
        "A revoke under one tenant must keep another tenant's grants"
    );
    assert_eq!(readable_under(&adapter, 2, "bob").await, ["note"]);

    adapter
        .state()
        .tenant()
        .with(Value::Int(2), adapter.update_document(&collection(NOTES), "note", revoke(&["alice"]), false))
        .await
        .unwrap();

    assert_eq!(readable_under(&adapter, 2, "alice").await, ["note"]);
    assert!(
        readable_under(&adapter, 2, "bob").await.is_empty(),
        "The second tenant's own revoke must remove its grant"
    );
    assert!(readable_under(&adapter, 1, "bob").await.is_empty());
}

#[utopia_async::test]
async fn test_delete_under_one_tenant_keeps_another_tenants_grants() {
    let adapter = shared_notes().await;

    adapter.state().tenant().with(Value::Int(1), adapter.delete_document(&collection(NOTES), "note")).await.unwrap();
    assert_eq!(
        readable_under(&adapter, 2, "alice").await,
        ["note"],
        "A delete under one tenant must keep another tenant's grants"
    );

    adapter.state().tenant().with(Value::Int(2), adapter.delete_document(&collection(NOTES), "note")).await.unwrap();
    let mut bob = note(&["bob"]);
    bob.put("$tenant", Value::Int(2));
    adapter.state().tenant().with(Value::Int(2), adapter.create_document(&collection(NOTES), bob)).await.unwrap();

    assert!(
        readable_under(&adapter, 2, "alice").await.is_empty(),
        "The second tenant's own delete must remove its grants"
    );
    assert_eq!(readable_under(&adapter, 2, "bob").await, ["note"]);
    assert!(readable_under(&adapter, 1, "bob").await.is_empty());
}

#[utopia_async::test]
async fn test_renaming_keeps_its_unique_value() {
    let adapter = notes().await;
    adapter.set_authorization(disabled());
    adapter.create_collection("users", &[Attribute::string("email", 128)], &[]).await.unwrap();
    adapter
        .create_index("users", &Index::unique("emailUnique", ["email"], vec![Some(128)], vec![]).unwrap(), &[], &[])
        .await
        .unwrap();
    let users = collection("users");
    adapter.create_document(&users, doc(json!({"$id": "old", "email": "a@example.test"}))).await.unwrap();
    adapter.create_document(&users, doc(json!({"$id": "other", "email": "b@example.test"}))).await.unwrap();

    let renamed = adapter
        .update_document(&users, "old", doc(json!({"$id": "new", "email": "a@example.test"})), false)
        .await
        .unwrap();
    assert_eq!(renamed.id(), "new");
    assert!(adapter.get_document(&users, "old", vec![], false).await.unwrap().is_empty());
    let new = adapter.get_document(&users, "new", vec![], false).await.unwrap();
    assert_eq!(new.get("email"), Some(&string("a@example.test")));

    assert_duplicate(
        adapter.create_document(&users, doc(json!({"$id": "copy", "email": "a@example.test"}))).await,
        "The renamed document must still hold its unique value",
    );

    adapter.create_document(&users, doc(json!({"$id": "reuse", "email": "c@example.test"}))).await.unwrap();
    adapter
        .update_document(&users, "reuse", doc(json!({"$id": "reused", "email": "c@example.test"})), false)
        .await
        .unwrap();
    let mut emails: Vec<String> = find(&adapter, "users", vec![])
        .await
        .unwrap()
        .iter()
        .map(|d| d.get("email").map(utopia_database::value::to_text).unwrap_or_default())
        .collect();
    emails.sort();
    assert_eq!(emails, ["a@example.test", "b@example.test", "c@example.test"]);
}
