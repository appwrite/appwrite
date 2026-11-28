//! The writes' unit tests, on [`StubAdapter`] (PHP's PHPUnit stubs of
//! `Adapter`): `CreateDocumentLogicTest`, `UpdateDocumentLogicTest`,
//! `IncreaseDecreaseTest`, `SkipPermissionsTest`, and the parts of
//! `IncreaseValueTest` and `BatchSizeTest` that fail before the engine is
//! reached; plus the bulk writes, upserts and deletes over a stub.
//!
//! Every write purges the document cache and reads with `find()`, which the
//! cache and reads areas port: until they land these tests are ignored.

use std::sync::{Arc, Mutex};

use crate::capability::Capability;
use crate::database::testing::{Call, Reply, StubAdapter, block_on, uncached};
use crate::database::{Database, METADATA, collection_definition};
use crate::document::Document;
use crate::error::Kind;
use crate::operator::Operator;
use crate::permission::Permission;
use crate::query::Query;
use crate::role::Role;
use crate::value::{Array, Str, Value, list, string};

/// A stored attribute definition (`new Document(['$id' => ..., 'type' => ...])`).
fn attribute(key: &str, kind: &str, size: i64, required: bool, signed: bool) -> Value {
    Value::from(
        Document::from_pairs([
            ("$id", string(key)),
            ("key", string(key)),
            ("type", string(kind)),
            ("size", Value::Int(size)),
            ("required", Value::Bool(required)),
            ("array", Value::Bool(false)),
            ("signed", Value::Bool(signed)),
            ("filters", Value::Array(Array::new())),
        ])
        .unwrap(),
    )
}

fn strings(items: &[String]) -> Value {
    list(items.iter().map(|p| Value::Str(Str::from(p.as_str()))))
}

fn all_permissions() -> Vec<String> {
    let any = Role::any();
    vec![Permission::read(&any), Permission::create(&any), Permission::update(&any), Permission::delete(&any)]
}

/// A collection definition as the metadata collection stores it.
fn collection(id: &str, attributes: Vec<Value>, permissions: Vec<String>) -> Document {
    Document::from_pairs([
        ("$id", string(id)),
        ("$collection", string(METADATA)),
        ("$permissions", strings(&permissions)),
        ("name", string(id)),
        ("attributes", list(attributes)),
        ("indexes", Value::Array(Array::new())),
        ("documentSecurity", Value::Bool(true)),
    ])
    .unwrap()
}

/// A stub serving `definition` and the stored `documents` of it, and
/// `documents` to every `find()`.
fn stub(definition: Document, documents: Vec<Document>) -> Arc<StubAdapter> {
    let adapter = StubAdapter::new();
    let id = definition.id().to_owned();
    adapter.on(move |call| match call {
        Call::GetDocument { collection: c, id: doc, .. } if c.id() == METADATA => {
            if doc == METADATA {
                Some(Reply::Document(collection_definition().into()))
            } else if *doc == id {
                Some(Reply::Document(definition.clone()))
            } else {
                Some(Reply::Document(Document::empty()))
            }
        }
        Call::GetDocument { collection: c, id: doc, .. } if c.id() == id => {
            Some(Reply::Document(documents.iter().find(|d| d.id() == doc).cloned().unwrap_or_default()))
        }
        Call::Find { .. } => Some(Reply::Documents(documents.clone())),
        _ => None,
    });
    adapter
}

fn note(pairs: Vec<(&str, Value)>) -> Document {
    Document::from_pairs(pairs).unwrap()
}

fn any_read() -> Value {
    strings(&[Permission::read(&Role::any())])
}

fn any_read_update() -> Value {
    strings(&[Permission::read(&Role::any()), Permission::update(&Role::any())])
}

// ---- CreateDocumentLogicTest ----------------------------------------------

fn create_database() -> Database {
    uncached(stub(collection("testCol", Vec::new(), all_permissions()), Vec::new()))
}

fn new_note() -> Document {
    note(vec![("$permissions", any_read()), ("$collection", string("testCol"))])
}

#[test]

fn create_document_sets_created_at_and_updated_at_and_an_id() {
    block_on(async {
        let db = create_database();
        let result = db.create_document("testCol", new_note()).await.unwrap();
        assert!(result.created_at().is_some());
        assert!(result.updated_at().is_some());
        assert!(!result.id().is_empty());
        assert_eq!(result.get("$collection"), Some(&string("testCol")));
    });
}

#[test]

fn create_document_uses_the_provided_id() {
    block_on(async {
        let db = create_database();
        let mut document = new_note();
        document.put("$id", string("custom-id"));
        let result = db.create_document("testCol", document).await.unwrap();
        assert_eq!(result.id(), "custom-id");
    });
}

#[test]
fn create_document_validates_the_structure_when_enabled() {
    block_on(async {
        let title = attribute("title", "string", 128, true, true);
        let db = uncached(stub(collection("testCol", vec![title], all_permissions()), Vec::new()));
        db.set_validation(true);
        let err = db.create_document("testCol", new_note()).await.unwrap_err();
        assert_eq!(err.kind(), Kind::Structure);
    });
}

#[test]

fn create_document_skips_validation_when_disabled() {
    block_on(async {
        let title = attribute("title", "string", 128, true, true);
        let db = uncached(stub(collection("testCol", vec![title], all_permissions()), Vec::new()));
        let result = db.skip_validation(db.create_document("testCol", new_note())).await.unwrap();
        assert!(!result.id().is_empty());
    });
}

#[test]
fn create_document_checks_create_permission() {
    block_on(async {
        let definition = collection("restricted", Vec::new(), vec![Permission::create(&Role::user("admin", ""))]);
        let adapter = stub(definition, Vec::new());
        let cache = Arc::new(utopia_cache::Cache::from_dyn(Arc::new(utopia_cache::None)));
        let db = Database::new(adapter, cache);
        let mut document = new_note();
        document.put("$collection", string("restricted"));
        let err = db.create_document("restricted", document).await.unwrap_err();
        assert_eq!(err.kind(), Kind::Authorization);
    });
}

#[test]
fn create_document_validates_the_permissions_format() {
    block_on(async {
        let db = create_database();
        db.set_validation(true);
        let document = note(vec![
            ("$permissions", list([string("invalid-permission-format")])),
            ("$collection", string("testCol")),
        ]);
        let err = db.create_document("testCol", document).await.unwrap_err();
        assert!(err.is_a(Kind::Database));
    });
}

#[test]

fn create_document_sets_empty_permissions_when_none_are_provided() {
    block_on(async {
        let db = create_database();
        let result = db.create_document("testCol", note(vec![("$collection", string("testCol"))])).await.unwrap();
        assert!(result.permissions().unwrap().is_empty());
    });
}

#[test]

fn create_documents_hands_on_next_every_document() {
    block_on(async {
        let db = create_database();
        let mut ids: Vec<(String, bool)> = Vec::new();
        let mut on_next = |(document, previous): (Document, Option<Document>)| {
            ids.push((document.id().to_owned(), previous.is_none()));
            Ok(())
        };
        let count =
            db.create_documents("testCol", vec![new_note(), new_note()], 100, Some(&mut on_next)).await.unwrap();
        assert_eq!(count, 2);
        assert_eq!(ids.len(), 2);
        assert!(ids.iter().all(|(id, created)| !id.is_empty() && *created));
    });
}

#[test]

fn create_documents_lets_an_on_next_failure_reach_the_caller() {
    block_on(async {
        let db = create_database();
        let mut on_next = |_: (Document, Option<Document>)| Err(crate::Error::new(Kind::Runtime, "onNext error"));
        let err = db.create_documents("testCol", vec![new_note()], 100, Some(&mut on_next)).await.unwrap_err();
        assert_eq!(err.message(), "onNext error");
    });
}

#[test]
fn create_documents_returns_zero_for_no_documents() {
    block_on(async {
        let db = create_database();
        assert_eq!(db.create_documents("testCol", Vec::new(), 1000, None).await.unwrap(), 0);
    });
}

// ---- UpdateDocumentLogicTest ----------------------------------------------

fn stored(name_key: &str, value: Value, permissions: Value) -> Document {
    note(vec![
        ("$id", string("doc1")),
        ("$collection", string("testCol")),
        ("$createdAt", string("2024-01-01T00:00:00.000+00:00")),
        ("$updatedAt", string("2024-01-01T00:00:00.000+00:00")),
        ("$permissions", permissions),
        (name_key, value),
    ])
}

fn update_database(existing: Document, attributes: Vec<Value>) -> (Database, Arc<StubAdapter>) {
    let adapter = stub(collection("testCol", attributes, all_permissions()), vec![existing]);
    (uncached(adapter.clone()), adapter)
}

fn name() -> Value {
    attribute("name", "string", 128, false, true)
}

fn change(key: &str, value: Value, permissions: Value) -> Document {
    note(vec![("$id", string("doc1")), ("$collection", string("testCol")), ("$permissions", permissions), (key, value)])
}

#[test]

fn update_document_sets_updated_at_and_keeps_created_at() {
    block_on(async {
        let (db, adapter) = update_database(stored("name", string("old"), any_read_update()), vec![name()]);
        let result =
            db.update_document("testCol", "doc1", change("name", string("new"), any_read_update())).await.unwrap();
        assert_ne!(result.updated_at(), Some("2024-01-01T00:00:00.000+00:00"));
        assert_eq!(result.created_at(), Some("2024-01-01T00:00:00.000+00:00"));
        assert_eq!(result.get("name"), Some(&string("new")));
        let updates = adapter.calls_of("updateDocument");
        let Call::UpdateDocument { skip_permissions, .. } = &updates[0] else { unreachable!() };
        assert!(skip_permissions, "the permissions did not change");
    });
}

#[test]
fn update_document_checks_update_permission() {
    block_on(async {
        let existing = stored("name", string("old"), strings(&[Permission::update(&Role::user("admin", ""))]));
        let definition = collection(
            "restricted",
            vec![name()],
            vec![
                Permission::read(&Role::any()),
                Permission::create(&Role::any()),
                Permission::update(&Role::user("admin", "")),
            ],
        );
        let mut existing = existing;
        existing.put("$collection", string("restricted"));
        let adapter = stub(definition, vec![existing]);
        let cache = Arc::new(utopia_cache::Cache::from_dyn(Arc::new(utopia_cache::None)));
        let db = Database::new(adapter, cache);
        let document =
            note(vec![("$id", string("doc1")), ("$collection", string("restricted")), ("name", string("new"))]);
        let err = db.update_document("restricted", "doc1", document).await.unwrap_err();
        assert_eq!(err.kind(), Kind::Authorization);
    });
}

#[test]
fn update_document_validates_the_structure() {
    block_on(async {
        let title = attribute("title", "string", 5, true, true);
        let (db, _) = update_database(stored("title", string("ok"), any_read_update()), vec![title]);
        db.set_validation(true);
        let document = change("title", string("this string is way too long for size 5"), any_read_update());
        let err = db.update_document("testCol", "doc1", document).await.unwrap_err();
        assert_eq!(err.kind(), Kind::Structure);
    });
}

#[test]

fn update_document_without_changes_keeps_updated_at() {
    block_on(async {
        let (db, _) = update_database(stored("name", string("same"), any_read_update()), vec![name()]);
        let result =
            db.update_document("testCol", "doc1", change("name", string("same"), any_read_update())).await.unwrap();
        assert_eq!(result.updated_at(), Some("2024-01-01T00:00:00.000+00:00"));
    });
}

#[test]
fn update_document_requires_an_id() {
    block_on(async {
        let (db, _) = update_database(Document::empty(), Vec::new());
        let err = db.update_document("testCol", "", Document::empty()).await.unwrap_err();
        assert_eq!(err.kind(), Kind::Database);
        assert_eq!(err.message(), "Must define $id attribute");
    });
}

#[test]
fn update_document_returns_an_empty_document_for_a_missing_one() {
    block_on(async {
        let (db, _) = update_database(Document::empty(), Vec::new());
        let result =
            db.update_document("testCol", "nonexistent", note(vec![("$id", string("nonexistent"))])).await.unwrap();
        assert!(result.is_empty());
    });
}

#[test]

fn update_document_rewrites_changed_permissions() {
    block_on(async {
        let (db, adapter) = update_database(stored("name", string("same"), any_read_update()), vec![name()]);
        let permissions = strings(&[
            Permission::read(&Role::any()),
            Permission::update(&Role::any()),
            Permission::delete(&Role::any()),
        ]);
        let result = db.update_document("testCol", "doc1", change("name", string("same"), permissions)).await.unwrap();
        assert!(!result.id().is_empty());
        let updates = adapter.calls_of("updateDocument");
        let Call::UpdateDocument { skip_permissions, .. } = &updates[0] else { unreachable!() };
        assert!(!skip_permissions);
    });
}

#[test]
fn update_document_refuses_a_document_changed_after_the_request() {
    block_on(async {
        let (db, adapter) = update_database(stored("name", string("old"), any_read_update()), vec![name()]);
        let utc = php_std::datetime::TzInfo::utc();
        let before = php_std::datetime::DateTime::new(b"2023-01-01", None, &utc).unwrap();
        let err = db
            .with_request_timestamp(
                Some(before),
                db.update_document("testCol", "doc1", change("name", string("new"), any_read_update())),
            )
            .await
            .unwrap_err();
        assert_eq!(err.kind(), Kind::Conflict);
        assert_eq!(err.message(), "Document was updated after the request timestamp");
        assert!(adapter.calls_of("updateDocument").is_empty());
    });
}

// ---- IncreaseDecreaseTest -------------------------------------------------

fn counter_database(value: Value, kind: &str, signed: bool, defined: bool) -> Database {
    let existing = note(vec![
        ("$id", string("doc1")),
        ("$collection", string("testCol")),
        ("$permissions", any_read_update()),
        ("counter", value),
    ]);
    let attributes = if defined { vec![attribute("counter", kind, 0, false, signed)] } else { Vec::new() };
    let adapter = stub(collection("testCol", attributes, all_permissions()), vec![existing]);
    let mut capabilities = vec![Capability::IndexKey, Capability::IndexArray, Capability::IndexUnique];
    capabilities.push(Capability::UnsignedBigInt);
    if defined {
        capabilities.push(Capability::DefinedAttributes);
    }
    adapter.set_capabilities(&capabilities);
    uncached(adapter)
}

#[test]

fn increase_and_decrease_an_integer() {
    block_on(async {
        let db = counter_database(Value::Int(5), "integer", true, true);
        let result = db.increase_document_attribute("testCol", "doc1", "counter", Value::Int(1), None).await.unwrap();
        assert_eq!(result.get("counter"), Some(&Value::Int(6)));
        let result = db.decrease_document_attribute("testCol", "doc1", "counter", Value::Int(1), None).await.unwrap();
        assert_eq!(result.get("counter"), Some(&Value::Int(4)));
        let result = db
            .increase_document_attribute("testCol", "doc1", "counter", Value::Int(1), Some(Value::Int(10)))
            .await
            .unwrap();
        assert_eq!(result.get("counter"), Some(&Value::Int(6)));
        let result = db
            .decrease_document_attribute("testCol", "doc1", "counter", Value::Int(1), Some(Value::Int(0)))
            .await
            .unwrap();
        assert_eq!(result.get("counter"), Some(&Value::Int(4)));
    });
}

#[test]

fn increase_passes_the_limit_less_the_change_to_the_adapter() {
    block_on(async {
        let existing = note(vec![
            ("$id", string("doc1")),
            ("$collection", string("testCol")),
            ("$permissions", any_read_update()),
            ("counter", Value::Int(8)),
        ]);
        let adapter = stub(
            collection("testCol", vec![attribute("counter", "integer", 0, false, true)], all_permissions()),
            vec![existing],
        );
        let db = uncached(adapter.clone());
        db.increase_document_attribute("testCol", "doc1", "counter", Value::Float(1.4), Some(Value::Float(10.9)))
            .await
            .unwrap();
        let calls = adapter.calls_of("increaseDocumentAttribute");
        let Call::IncreaseDocumentAttribute { value, max, min, .. } = &calls[0] else { unreachable!() };
        assert_eq!(value, &Value::Int(1), "the change is rounded half away from zero");
        assert_eq!(max, &Some(Value::Int(9)), "the max is floored, less the change");
        assert_eq!(min, &None);
    });
}

#[test]
fn increase_and_decrease_refuse_crossing_their_limit() {
    block_on(async {
        let db = counter_database(Value::Int(10), "integer", true, true);
        let err = db
            .increase_document_attribute("testCol", "doc1", "counter", Value::Int(1), Some(Value::Int(10)))
            .await
            .unwrap_err();
        assert_eq!(err.kind(), Kind::Limit);
        assert_eq!(err.message(), "Attribute value exceeds maximum limit: 10");

        let db = counter_database(Value::Int(3), "integer", true, true);
        let err = db
            .decrease_document_attribute("testCol", "doc1", "counter", Value::Int(5), Some(Value::Int(0)))
            .await
            .unwrap_err();
        assert_eq!(err.kind(), Kind::Limit);
        assert_eq!(err.message(), "Attribute value exceeds minimum limit: 0");
    });
}

#[test]

fn increase_a_float_by_a_custom_value() {
    block_on(async {
        let db = counter_database(Value::Float(10.0), "double", true, true);
        let result =
            db.increase_document_attribute("testCol", "doc1", "counter", Value::Float(2.5), None).await.unwrap();
        assert_eq!(result.get("counter"), Some(&Value::Float(12.5)));
    });
}

#[test]

fn increase_accepts_float_big_integer_and_legacy_metadata() {
    block_on(async {
        // PHP's stub casts in the adapter, so its float stays the integer 5; this
        // stub does not, and the read casts it to 5.0.
        let expected = [("float", Value::Float(6.0)), ("biginteger", Value::Int(6)), ("bigint", Value::Int(6))];
        for (kind, expected) in expected {
            let db = counter_database(Value::Int(5), kind, true, true);
            let result =
                db.increase_document_attribute("testCol", "doc1", "counter", Value::Int(1), None).await.unwrap();
            assert_eq!(result.get("counter"), Some(&expected), "{kind}");
        }
    });
}

#[test]
fn increase_and_decrease_refuse_serial_columns() {
    block_on(async {
        for kind in ["serial", "bigserial", "smallserial"] {
            let db = counter_database(Value::Int(5), kind, true, true);
            let err =
                db.increase_document_attribute("testCol", "doc1", "counter", Value::Int(1), None).await.unwrap_err();
            assert_eq!(err.kind(), Kind::Structure);
            assert_eq!(err.message(), format!("Unknown attribute type: {kind}"));
            let err =
                db.decrease_document_attribute("testCol", "doc1", "counter", Value::Int(1), None).await.unwrap_err();
            assert_eq!(err.message(), format!("Unknown attribute type: {kind}"));
        }
    });
}

#[test]
fn big_integers_refuse_overflow_and_underflow() {
    block_on(async {
        let db = counter_database(Value::Int(i64::MAX), "biginteger", true, true);
        let err = db.increase_document_attribute("testCol", "doc1", "counter", Value::Int(1), None).await.unwrap_err();
        assert_eq!(err.kind(), Kind::Limit);

        let db = counter_database(Value::Int(0), "biginteger", false, true);
        let err = db.decrease_document_attribute("testCol", "doc1", "counter", Value::Int(1), None).await.unwrap_err();
        assert_eq!(err.kind(), Kind::Limit);

        let db = counter_database(string("18446744073709551615"), "biginteger", false, true);
        let err = db.increase_document_attribute("testCol", "doc1", "counter", Value::Int(1), None).await.unwrap_err();
        assert_eq!(err.kind(), Kind::Limit);
    });
}

#[test]

fn unsigned_big_integers_cross_the_php_integer_boundary_exactly() {
    block_on(async {
        let db = counter_database(Value::Int(i64::MAX), "biginteger", false, true);
        let increased =
            db.increase_document_attribute("testCol", "doc1", "counter", Value::Int(1), None).await.unwrap();
        assert_eq!(increased.get("counter"), Some(&string("9223372036854775808")));

        let db = counter_database(string("18446744073709551614"), "biginteger", false, true);
        let result = db
            .increase_document_attribute(
                "testCol",
                "doc1",
                "counter",
                Value::Int(1),
                Some(string("18446744073709551615")),
            )
            .await
            .unwrap();
        assert_eq!(result.get("counter"), Some(&string("18446744073709551615")));
    });
}

#[test]
fn increase_and_decrease_refuse_a_change_that_is_not_a_positive_number() {
    block_on(async {
        let db = counter_database(Value::Int(1), "integer", true, true);
        let changes = [
            Value::Int(0),
            Value::Int(-1),
            Value::Float(0.0),
            Value::Float(-0.5),
            string("0"),
            string("-4"),
            string("-1.5"),
            string("many"),
        ];
        for change in changes {
            let err =
                db.increase_document_attribute("testCol", "doc1", "counter", change.clone(), None).await.unwrap_err();
            assert_eq!(err.kind(), Kind::Type, "{change:?}");
            assert!(err.is_a(Kind::Database));
            assert_eq!(err.message(), "Value must be numeric and greater than 0");
            let err = db.decrease_document_attribute("testCol", "doc1", "counter", change, None).await.unwrap_err();
            assert_eq!(err.message(), "Value must be numeric and greater than 0");
        }
    });
}

#[test]
fn increase_of_a_missing_document_or_attribute_is_not_found() {
    block_on(async {
        let db = counter_database(Value::Int(5), "integer", true, true);
        let err =
            db.increase_document_attribute("testCol", "nonexistent", "counter", Value::Int(1), None).await.unwrap_err();
        assert_eq!(err.kind(), Kind::NotFound);
        assert_eq!(err.message(), "Document not found");
        let err = db.increase_document_attribute("testCol", "doc1", "missing", Value::Int(1), None).await.unwrap_err();
        assert_eq!(err.kind(), Kind::NotFound);
        assert_eq!(err.message(), "Attribute not found");
    });
}

#[test]

fn schemaless_increase_defaults_only_a_missing_attribute_to_zero() {
    block_on(async {
        let existing = note(vec![
            ("$id", string("missing")),
            ("$collection", string("testCol")),
            ("$updatedAt", string("2024-01-01T00:00:00.000+00:00")),
            ("$permissions", any_read_update()),
        ]);
        let adapter = stub(collection("testCol", Vec::new(), all_permissions()), vec![existing]);
        adapter.set_capabilities(&[Capability::IndexKey]);
        let db = uncached(adapter);
        let result =
            db.increase_document_attribute("testCol", "missing", "counter", Value::Int(1), None).await.unwrap();
        assert_eq!(result.get("counter"), Some(&Value::Int(1)));
    });
}

#[test]
fn schemaless_increase_refuses_an_explicit_null() {
    block_on(async {
        let db = counter_database(Value::Null, "integer", true, false);
        let err = db.increase_document_attribute("testCol", "doc1", "counter", Value::Int(1), None).await.unwrap_err();
        assert_eq!(err.kind(), Kind::Type);
        assert_eq!(err.message(), "Attribute value must be numeric.");
    });
}

#[test]
fn a_not_a_number_change_of_a_float_is_refused() {
    block_on(async {
        let db = counter_database(Value::Float(1.5), "double", true, true);
        let err = db
            .increase_document_attribute("testCol", "doc1", "counter", Value::Float(f64::NAN), None)
            .await
            .unwrap_err();
        assert_eq!(err.kind(), Kind::Type);
        assert_eq!(err.message(), "Attribute value must be a finite numeric value.");
        let err = db
            .decrease_document_attribute("testCol", "doc1", "counter", Value::Float(f64::NAN), None)
            .await
            .unwrap_err();
        assert_eq!(err.message(), "Attribute value must be a finite numeric value.");
    });
}

// ---- SkipPermissionsTest --------------------------------------------------

#[test]

fn create_document_with_skipped_permissions() {
    block_on(async {
        let title = attribute("title", "string", 256, false, true);
        let definition = collection("restricted", vec![title], vec![Permission::create(&Role::user("admin", ""))]);
        let cache = Arc::new(utopia_cache::Cache::from_dyn(Arc::new(utopia_cache::None)));
        let db = Database::new(stub(definition, Vec::new()), cache);
        let document = note(vec![
            ("$permissions", any_read()),
            ("$collection", string("restricted")),
            ("title", string("Created via skip")),
        ]);
        let result = db.authorization().skip(db.create_document("restricted", document)).await.unwrap();
        assert!(!result.id().is_empty());
        assert_eq!(result.get("title"), Some(&string("Created via skip")));
    });
}

// ---- BatchSizeTest --------------------------------------------------------

#[test]
fn a_batch_size_above_the_maximum_is_refused_before_any_write() {
    block_on(async {
        let adapter = stub(collection("testCol", Vec::new(), all_permissions()), Vec::new());
        adapter.support_upserts();
        let db = uncached(adapter.clone());
        let message = "Batch size must be at most 1000, got 1001";
        let err = db.create_documents("testCol", vec![new_note()], 1001, None).await.unwrap_err();
        assert_eq!((err.kind(), err.message()), (Kind::Limit, message));
        let updates = note(vec![("views", Value::Int(10))]);
        let err = db.update_documents("testCol", updates, Vec::new(), 1001, None).await.unwrap_err();
        assert_eq!((err.kind(), err.message()), (Kind::Limit, message));
        let err = db.upsert_documents("testCol", vec![new_note()], 1001, None, None).await.unwrap_err();
        assert_eq!((err.kind(), err.message()), (Kind::Limit, message));
        let err = db.delete_documents("testCol", Vec::new(), 1001, None).await.unwrap_err();
        assert_eq!((err.kind(), err.message()), (Kind::Limit, message));
        assert!(adapter.calls().is_empty(), "nothing was read or written");
    });
}

// ---- Bulk writes, upserts and deletes -------------------------------------

fn views(id: &str, views: i64) -> Document {
    note(vec![
        ("$id", string(id)),
        ("$sequence", Value::Int(views)),
        ("$collection", string("testCol")),
        ("$updatedAt", string("2024-01-01 00:00:00.000")),
        ("$permissions", any_read_update()),
        ("views", Value::Int(views)),
    ])
}

fn views_database(stored: Vec<Document>) -> (Database, Arc<StubAdapter>) {
    let definition = collection("testCol", vec![attribute("views", "integer", 0, false, true)], all_permissions());
    let adapter = stub(definition, stored);
    adapter.support_upserts();
    (uncached(adapter.clone()), adapter)
}

#[test]
fn bulk_writes_refuse_joins() {
    block_on(async {
        let (db, _) = views_database(Vec::new());
        let join = Query::join("other", "o", vec![Query::equal("$id", vec!["x"])]).unwrap();
        let err = db
            .update_documents("testCol", note(vec![("views", Value::Int(1))]), vec![join.clone()], 10, None)
            .await
            .unwrap_err();
        assert_eq!((err.kind(), err.message()), (Kind::Query, "Join queries are not supported for bulk updates"));
        let err = db.delete_documents("testCol", vec![join], 10, None).await.unwrap_err();
        assert_eq!((err.kind(), err.message()), (Kind::Query, "Join queries are not supported for bulk deletes"));
    });
}

#[test]

fn update_documents_hands_on_next_each_document_and_the_stored_one() {
    block_on(async {
        let (db, adapter) = views_database(vec![views("first", 1), views("second", 2)]);
        let seen = Mutex::new(Vec::new());
        let mut on_next = |(document, previous): (Document, Option<Document>)| {
            let previous = previous.unwrap();
            seen.lock().unwrap().push((
                document.id().to_owned(),
                document.get("views").cloned(),
                previous.get("views").cloned(),
            ));
            Ok(())
        };
        let count = db
            .update_documents("testCol", note(vec![("views", Value::Int(10))]), Vec::new(), 1000, Some(&mut on_next))
            .await
            .unwrap();
        assert_eq!(count, 2);
        assert_eq!(
            *seen.lock().unwrap(),
            vec![
                ("first".to_owned(), Some(Value::Int(10)), Some(Value::Int(1))),
                ("second".to_owned(), Some(Value::Int(10)), Some(Value::Int(2))),
            ]
        );
        let calls = adapter.calls_of("updateDocuments");
        let Call::UpdateDocuments { updates, skip_permissions, .. } = &calls[0] else { unreachable!() };
        assert_eq!(updates.get("views"), Some(&Value::Int(10)));
        assert!(!updates.has("$id"));
        assert_eq!(skip_permissions, &vec!["first".to_owned(), "second".to_owned()]);
    });
}

#[test]

fn update_documents_drops_an_increment_rounding_to_nothing() {
    block_on(async {
        let (db, adapter) = views_database(vec![views("first", 1)]);
        let updates = note(vec![("views", Value::from(Operator::increment(0.4, None)))]);
        assert_eq!(db.update_documents("testCol", updates, Vec::new(), 1000, None).await.unwrap(), 0);
        assert!(adapter.calls_of("updateDocuments").is_empty());
    });
}

#[test]

fn delete_documents_deletes_by_sequence() {
    block_on(async {
        let (db, adapter) = views_database(vec![views("first", 1), views("second", 2)]);
        let mut deleted = Vec::new();
        let mut on_next = |document: Document| {
            deleted.push(document.id().to_owned());
            Ok(())
        };
        assert_eq!(db.delete_documents("testCol", Vec::new(), 1000, Some(&mut on_next)).await.unwrap(), 2);
        assert_eq!(deleted, ["first", "second"]);
        let calls = adapter.calls_of("deleteDocuments");
        let Call::DeleteDocuments { sequences, permission_ids, .. } = &calls[0] else { unreachable!() };
        // The read casts the sequences to the id type, strings here.
        assert_eq!(sequences, &vec![string("1"), string("2")]);
        assert_eq!(permission_ids, &vec!["first".to_owned(), "second".to_owned()]);
    });
}

#[test]

fn delete_document_deletes_a_stored_document_only() {
    block_on(async {
        let (db, adapter) = views_database(vec![views("first", 1)]);
        assert!(db.delete_document("testCol", "first").await.unwrap());
        assert!(!db.delete_document("testCol", "absent").await.unwrap());
        assert_eq!(adapter.calls_of("deleteDocument").len(), 1);
    });
}

#[test]
fn delete_document_checks_delete_permission() {
    block_on(async {
        let definition = collection("testCol", Vec::new(), vec![Permission::read(&Role::any())]);
        let adapter = stub(definition, vec![views("first", 1)]);
        let db = uncached(adapter.clone());
        let err = db.delete_document("testCol", "first").await.unwrap_err();
        assert_eq!(err.kind(), Kind::Authorization);
        assert!(adapter.calls_of("deleteDocument").is_empty());
    });
}

#[test]
fn upserts_need_the_adapter_feature() {
    block_on(async {
        let adapter = stub(collection("testCol", Vec::new(), all_permissions()), Vec::new());
        let db = uncached(adapter);
        let err = db.upsert_documents("testCol", vec![new_note()], 10, None, None).await.unwrap_err();
        assert_eq!((err.kind(), err.message()), (Kind::Database, "Adapter does not support upserts"));
    });
}

#[test]

fn upsert_documents_creates_and_updates() {
    block_on(async {
        let (db, adapter) = views_database(vec![views("first", 1)]);
        let seen = Mutex::new(Vec::new());
        let mut on_next = |(document, previous): (Document, Option<Document>)| {
            seen.lock().unwrap().push((document.id().to_owned(), previous.map(|p| p.get("views").cloned())));
            Ok(())
        };
        let documents = vec![
            note(vec![("$id", string("first")), ("views", Value::Int(5))]),
            note(vec![("$id", string("third")), ("views", Value::Int(3))]),
        ];
        assert_eq!(db.upsert_documents("testCol", documents, 1000, Some(&mut on_next), None).await.unwrap(), 2);
        assert_eq!(
            *seen.lock().unwrap(),
            vec![("first".to_owned(), Some(Some(Value::Int(1)))), ("third".to_owned(), None)]
        );
        let calls = adapter.calls_of("upsertDocuments");
        let Call::UpsertDocuments { changes, .. } = &calls[0] else { unreachable!() };
        assert_eq!(changes.len(), 2);
        assert_eq!(changes[0].old.id(), "first");
        assert!(changes[1].old.is_empty());
    });
}

#[test]

fn upsert_document_returns_the_stored_one_when_nothing_changed() {
    block_on(async {
        let (db, adapter) = views_database(vec![views("first", 1)]);
        let result = db
            .upsert_document("testCol", note(vec![("$id", string("first")), ("views", Value::Int(1))]))
            .await
            .unwrap();
        assert_eq!(result.id(), "first");
        assert!(adapter.calls_of("upsertDocuments").is_empty(), "nothing changed, nothing written");
    });
}

#[test]

fn upsert_documents_refuses_an_id_given_twice() {
    block_on(async {
        let (db, _) = views_database(Vec::new());
        let documents = vec![
            note(vec![("$id", string("same")), ("views", Value::Int(1))]),
            note(vec![("$id", string("same")), ("views", Value::Int(2))]),
        ];
        let err = db.upsert_documents("testCol", documents, 1000, None, None).await.unwrap_err();
        assert_eq!((err.kind(), err.message()), (Kind::Duplicate, "Duplicate document IDs found in the input array."));
    });
}

#[test]
fn round_operators_rounds_integer_increments() {
    block_on(async {
        let (db, _) = views_database(Vec::new());
        let definition = db.get_collection("testCol").await.unwrap();
        let mut document = note(vec![("views", Value::from(Operator::increment(0.4, Some(9.7.into()))))]);
        let unchanged = db.round_operators(&definition, &mut document).unwrap();
        assert_eq!(unchanged, ["views"]);
        let Some(Value::Ext(crate::value::Ext::Operator(operator))) = document.get("views") else { unreachable!() };
        assert_eq!(operator.values().get(&crate::value::ArrayKey::Int(0)), Some(&Value::Int(0)));
        assert_eq!(operator.values().get(&crate::value::ArrayKey::Int(1)), Some(&Value::Int(9)));
    });
}

// ---- TenantWriteGuardsTest (the refusals) ---------------------------------

fn tenant_note(id: &str, tenant: Option<i64>) -> Document {
    note(vec![
        ("$id", string(id)),
        ("$tenant", tenant.map_or(Value::Null, Value::Int)),
        ("$permissions", any_read_update()),
        ("body", string(id)),
    ])
}

#[test]
fn tenant_guards_refuse_writes_before_the_engine() {
    block_on(async {
        let per_document_without_sharing = "Shared tables must be enabled if tenant per document is enabled.";
        let missing_document_tenant = "Missing tenant. Tenant must be set when tenant per document is enabled.";
        let missing_tenant = "Missing tenant. Tenant must be set when table sharing is enabled.";
        let refused = |result: crate::Result<()>, message: &str| {
            let err = result.unwrap_err();
            assert_eq!((err.kind(), err.message()), (Kind::Database, message));
        };

        let definition = collection("notes", vec![attribute("body", "string", 32, false, true)], all_permissions());
        let adapter = stub(definition, Vec::new());
        adapter.support_upserts();
        let db = uncached(adapter.clone());

        db.set_tenant_per_document(true);
        refused(db.create_document("notes", tenant_note("a", Some(1))).await.map(drop), per_document_without_sharing);
        let one = || vec![tenant_note("a", Some(1))];
        refused(db.create_documents("notes", one(), 10, None).await.map(drop), per_document_without_sharing);
        refused(db.upsert_documents("notes", one(), 10, None, None).await.map(drop), per_document_without_sharing);

        db.set_shared_tables(true);
        refused(db.create_document("notes", tenant_note("a", None)).await.map(drop), missing_document_tenant);
        let documents = vec![tenant_note("b", Some(1)), tenant_note("c", None)];
        refused(db.create_documents("notes", documents, 10, None).await.map(drop), missing_document_tenant);

        db.set_tenant_per_document(false);
        let none = || vec![tenant_note("a", None)];
        refused(db.create_documents("notes", none(), 10, None).await.map(drop), missing_tenant);
        refused(db.upsert_documents("notes", none(), 10, None, None).await.map(drop), missing_tenant);
        refused(db.delete_documents("notes", Vec::new(), 10, None).await.map(drop), missing_tenant);

        let written = adapter.calls().into_iter().any(|c| {
            matches!(c, Call::CreateDocument { .. } | Call::CreateDocuments { .. } | Call::UpsertDocuments { .. })
        });
        assert!(!written, "nothing was written");
    });
}
