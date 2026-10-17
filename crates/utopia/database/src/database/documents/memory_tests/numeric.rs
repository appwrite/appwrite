//! PHP: `Documents/NumericUpdateGuardsTest`, `Documents/NullNumericIncrementTest`
//! (its Memory cases), `Documents/IncreaseValueTest`, and the increase,
//! decrease and bulk delete cases of `Documents/DocumentMinorsTest`.

use std::sync::Arc;

use super::{refused, s};
use crate::adapter::Adapter;
use crate::attribute::Attribute;
use crate::capability::Capability;
use crate::database::testing::block_on;
use crate::database::testing_memory::{POSTS, Tweaked, collection, database_on, doc, memory, posts, seed};
use crate::database::{Database, MAX_DOUBLE, METADATA};
use crate::document::Document;
use crate::error::{Kind, Result};
use crate::permission::Permission;
use crate::query::Query;
use crate::role::Role;
use crate::value::Value;

fn int(i: i64) -> Value {
    Value::Int(i)
}

fn float(f: f64) -> Value {
    Value::Float(f)
}

#[track_caller]
fn got(result: Result<Document>, attribute: &str) -> Option<Value> {
    result.unwrap().get(attribute).cloned()
}

async fn value(database: &Database, collection: &str, id: &str, attribute: &str) -> Option<Value> {
    database.get_document(collection, id, Vec::new(), false).await.unwrap().get(attribute).cloned()
}

// ---- NumericUpdateGuardsTest ----------------------------------------------

const LEDGERS: &str = "ledgers";

fn ledger_attributes() -> Vec<Attribute> {
    vec![Attribute::integer("count"), Attribute::float("ratio"), Attribute::float("share").signed(false)]
}

fn create_read_update() -> Vec<String> {
    let any = Role::any();
    vec![Permission::create(&any), Permission::read(&any), Permission::update(&any)]
}

async fn ledgers(
    adapter: Arc<dyn Adapter>,
    attributes: Vec<Attribute>,
    permissions: Vec<String>,
    security: bool,
) -> Database {
    let database = database_on(adapter, "numeric").await;
    collection(&database, LEDGERS, attributes, permissions, security).await;
    database
}

async fn increase(db: &Database, id: &str, attribute: &str, value: Value, max: Option<Value>) -> Result<Document> {
    db.increase_document_attribute(LEDGERS, id, attribute, value, max).await
}

async fn decrease(db: &Database, id: &str, attribute: &str, value: Value, min: Option<Value>) -> Result<Document> {
    db.decrease_document_attribute(LEDGERS, id, attribute, value, min).await
}

#[test]
fn unsigned_big_integer_arithmetic_needs_the_capability() {
    block_on(async {
        let adapter = Arc::new(Tweaked::new());
        adapter.set_supports(Capability::UnsignedBigInt, true);
        let attributes = vec![Attribute::big_integer("total").signed(false)];
        let db = ledgers(adapter.clone(), attributes, create_read_update(), false).await;
        db.create_document(LEDGERS, doc(vec![("$id", s("ledger")), ("total", int(5))])).await.unwrap();
        adapter.set_supports(Capability::UnsignedBigInt, false);
        let message = "Unsigned 64-bit arithmetic is not supported by this adapter.";
        refused(increase(&db, "ledger", "total", int(1), None).await, Kind::Type, message);
        refused(decrease(&db, "ledger", "total", int(1), None).await, Kind::Type, message);
        adapter.set_supports(Capability::UnsignedBigInt, true);
        assert_eq!(value(&db, LEDGERS, "ledger", "total").await, Some(int(5)));
    });
}

#[test]
fn a_stored_value_the_arithmetic_cannot_use_is_refused() {
    block_on(async {
        let cases = [
            ("count", s("1.5"), true, Kind::Type, "Attribute value must be an integer."),
            ("count", s("abc"), false, Kind::Type, "Attribute value must be an integer."),
            ("ratio", s("abc"), true, Kind::Type, "Attribute value must be numeric."),
            ("ratio", float(f64::INFINITY), true, Kind::Type, "Attribute value must be a finite numeric value."),
            ("share", float(-1.0), true, Kind::Limit, "Attribute value exceeds minimum limit: 0"),
        ];
        for (attribute, stored, up, kind, message) in cases {
            let adapter = Arc::new(Tweaked::new().casting());
            let db = ledgers(adapter, ledger_attributes(), create_read_update(), false).await;
            let document = doc(vec![("$id", s("ledger")), (attribute, stored)]);
            db.skip_validation(db.create_document(LEDGERS, document)).await.unwrap();
            let result = if up {
                increase(&db, "ledger", attribute, int(1), None).await
            } else {
                decrease(&db, "ledger", attribute, int(1), None).await
            };
            refused(result, kind, message);
        }
    });
}

#[test]
fn a_float_increase_past_the_maximum_is_refused() {
    block_on(async {
        let db = ledgers(Arc::new(Tweaked::new()), ledger_attributes(), create_read_update(), false).await;
        db.create_document(LEDGERS, doc(vec![("$id", s("ledger")), ("ratio", float(MAX_DOUBLE))])).await.unwrap();
        refused(
            increase(&db, "ledger", "ratio", float(MAX_DOUBLE), None).await,
            Kind::Limit,
            "Attribute value exceeds maximum limit: 1.7976931348623E+308",
        );
        assert_eq!(value(&db, LEDGERS, "ledger", "ratio").await, Some(float(MAX_DOUBLE)));
    });
}

#[test]
fn a_float_decrease_past_the_minimum_is_refused() {
    block_on(async {
        let db = ledgers(Arc::new(Tweaked::new()), ledger_attributes(), create_read_update(), false).await;
        let document = doc(vec![("$id", s("ledger")), ("share", float(0.5)), ("ratio", float(-MAX_DOUBLE))]);
        db.create_document(LEDGERS, document).await.unwrap();
        refused(
            decrease(&db, "ledger", "share", int(1), None).await,
            Kind::Limit,
            "Attribute value exceeds minimum limit: 0",
        );
        refused(
            decrease(&db, "ledger", "ratio", float(MAX_DOUBLE), None).await,
            Kind::Limit,
            "Attribute value exceeds minimum limit: -1.7976931348623E+308",
        );
        assert_eq!(value(&db, LEDGERS, "ledger", "share").await, Some(float(0.5)));
    });
}

#[test]
fn an_integer_string_change_is_checked_as_an_integer() {
    block_on(async {
        let db = ledgers(Arc::new(Tweaked::new()), ledger_attributes(), create_read_update(), false).await;
        db.create_document(LEDGERS, doc(vec![("$id", s("ledger")), ("count", int(10))])).await.unwrap();
        assert_eq!(got(increase(&db, "ledger", "count", s("5"), None).await, "count"), Some(int(15)));
        assert_eq!(got(decrease(&db, "ledger", "count", s("3"), None).await, "count"), Some(int(12)));
        for change in ["0", "-4"] {
            let message = "Value must be numeric and greater than 0";
            refused(increase(&db, "ledger", "count", s(change), None).await, Kind::Type, message);
            refused(decrease(&db, "ledger", "count", s(change), None).await, Kind::Type, message);
        }
        assert_eq!(value(&db, LEDGERS, "ledger", "count").await, Some(int(12)));
    });
}

#[test]
fn an_unknown_attribute_or_document_is_not_found() {
    block_on(async {
        let db = ledgers(Arc::new(Tweaked::new()), ledger_attributes(), create_read_update(), false).await;
        db.create_document(LEDGERS, doc(vec![("$id", s("ledger")), ("count", int(10))])).await.unwrap();
        refused(increase(&db, "ledger", "missing", int(1), None).await, Kind::NotFound, "Attribute not found");
        refused(decrease(&db, "ledger", "missing", int(1), None).await, Kind::NotFound, "Attribute not found");
        refused(increase(&db, "missing", "count", int(1), None).await, Kind::NotFound, "Document not found");
        refused(decrease(&db, "missing", "count", int(1), None).await, Kind::NotFound, "Document not found");

        let any = Role::any();
        let read_only = vec![Permission::create(&any), Permission::read(&any)];
        let guarded = ledgers(Arc::new(Tweaked::new()), ledger_attributes(), read_only, true).await;
        refused(increase(&guarded, "missing", "count", int(1), None).await, Kind::NotFound, "Document not found");
        refused(decrease(&guarded, "missing", "count", int(1), None).await, Kind::NotFound, "Document not found");
    });
}

#[test]
fn increasing_and_decreasing_need_update_permission() {
    block_on(async {
        let any = Role::any();
        let read_only = vec![Permission::create(&any), Permission::read(&any)];
        let db = ledgers(Arc::new(Tweaked::new()), ledger_attributes(), read_only, false).await;
        db.create_document(LEDGERS, doc(vec![("$id", s("ledger")), ("count", int(10))])).await.unwrap();
        assert_eq!(increase(&db, "ledger", "count", int(1), None).await.unwrap_err().kind(), Kind::Authorization);
        assert_eq!(decrease(&db, "ledger", "count", int(1), None).await.unwrap_err().kind(), Kind::Authorization);
        assert_eq!(value(&db, LEDGERS, "ledger", "count").await, Some(int(10)));
    });
}

#[test]
fn a_schemaless_decrease_starts_an_unset_attribute_at_zero_and_refuses_text() {
    block_on(async {
        let adapter = Arc::new(Tweaked::new().without(Capability::DefinedAttributes));
        let db = ledgers(adapter, ledger_attributes(), create_read_update(), false).await;
        db.create_document(LEDGERS, doc(vec![("$id", s("ledger")), ("label", s("text"))])).await.unwrap();
        assert_eq!(got(decrease(&db, "ledger", "balance", int(2), None).await, "balance"), Some(int(-2)));
        assert_eq!(value(&db, LEDGERS, "ledger", "balance").await, Some(int(-2)));
        refused(decrease(&db, "ledger", "label", int(1), None).await, Kind::Type, "Attribute value must be numeric.");
        refused(increase(&db, "ledger", "label", int(1), None).await, Kind::Type, "Attribute value must be numeric.");
    });
}

#[test]
fn a_schemaless_change_of_a_definition_skips_the_document_permissions() {
    block_on(async {
        let adapter = Arc::new(Tweaked::new().without(Capability::DefinedAttributes));
        let db = ledgers(adapter, ledger_attributes(), create_read_update(), false).await;
        let increased = db.increase_document_attribute(METADATA, LEDGERS, "revision", int(1), None).await;
        assert_eq!(got(increased, "revision"), Some(int(1)));
        let decreased = db.decrease_document_attribute(METADATA, LEDGERS, "revision", int(1), None).await;
        assert_eq!(got(decreased, "revision"), Some(int(0)));
    });
}

// ---- NullNumericIncrementTest (Memory) ------------------------------------

async fn unset() -> Database {
    let db = memory("null_increment").await;
    let attributes = vec![
        Attribute::integer("integer"),
        Attribute::big_integer("bigInteger"),
        Attribute::float("float"),
        Attribute::double("double"),
    ];
    collection(&db, "counters", attributes, create_read_update(), true).await;
    db.create_document("counters", doc(vec![("$id", s("unset"))])).await.unwrap();
    db
}

#[test]
fn an_unset_optional_number_counts_as_zero() {
    block_on(async {
        let increments = [("integer", int(3)), ("bigInteger", int(4)), ("float", float(1.5)), ("double", float(2.25))];
        for (attribute, change) in increments {
            let db = unset().await;
            assert_eq!(value(&db, "counters", "unset", attribute).await, None);
            let increased = db.increase_document_attribute("counters", "unset", attribute, change.clone(), None).await;
            assert_eq!(got(increased, attribute), Some(change.clone()), "{attribute}");
            assert_eq!(value(&db, "counters", "unset", attribute).await, Some(change));
        }
        let decrements = [
            ("integer", int(2), int(-2)),
            ("bigInteger", int(5), int(-5)),
            ("float", float(0.5), float(-0.5)),
            ("double", float(1.25), float(-1.25)),
        ];
        for (attribute, change, expected) in decrements {
            let db = unset().await;
            let decreased = db.decrease_document_attribute("counters", "unset", attribute, change, None).await;
            assert_eq!(got(decreased, attribute), Some(expected.clone()), "{attribute}");
            assert_eq!(value(&db, "counters", "unset", attribute).await, Some(expected));
        }
    });
}

#[test]
fn an_unset_number_still_enforces_its_limits() {
    block_on(async {
        let db = unset().await;
        let increased = db.increase_document_attribute("counters", "unset", "integer", int(5), Some(int(4))).await;
        assert_eq!(increased.unwrap_err().kind(), Kind::Limit);
        let decreased = db.decrease_document_attribute("counters", "unset", "float", float(2.5), Some(int(-2))).await;
        assert_eq!(decreased.unwrap_err().kind(), Kind::Limit);
        let increased = db.increase_document_attribute("counters", "unset", "integer", int(4), Some(int(4))).await;
        assert_eq!(got(increased, "integer"), Some(int(4)));
    });
}

// ---- IncreaseValueTest ----------------------------------------------------

#[test]
fn a_change_that_is_not_a_positive_number_is_refused() {
    block_on(async {
        let db = posts().await;
        seed(&db, &["first"]).await;
        let changes = [int(0), int(-1), float(0.0), float(-0.5), s("0"), s("-4"), s("-1.5"), s("many")];
        for change in changes {
            let message = "Value must be numeric and greater than 0";
            let increased = db.increase_document_attribute(POSTS, "first", "views", change.clone(), None).await;
            refused(increased, Kind::Type, message);
            let decreased = db.decrease_document_attribute(POSTS, "first", "views", change, None).await;
            let err = decreased.unwrap_err();
            assert!(err.is_a(Kind::Database), "the refusal is a database exception");
            assert_eq!(err.message(), message);
            assert_eq!(value(&db, POSTS, "first", "views").await, Some(int(1)));
        }
    });
}

#[test]
fn a_positive_change_is_applied() {
    block_on(async {
        let db = posts().await;
        seed(&db, &["first"]).await;
        assert_eq!(
            got(db.increase_document_attribute(POSTS, "first", "views", s("5"), None).await, "views"),
            Some(int(6))
        );
        assert_eq!(
            got(db.decrease_document_attribute(POSTS, "first", "views", int(2), None).await, "views"),
            Some(int(4))
        );
    });
}

// ---- DocumentMinorsTest ---------------------------------------------------

async fn entries(adapter: Arc<dyn Adapter>) -> Database {
    let db = database_on(adapter, "minors").await;
    let attributes = vec![Attribute::integer("count"), Attribute::float("ratio"), Attribute::datetime("recordedAt")];
    collection(&db, "entries", attributes, crate::database::testing_memory::crud(), true).await;
    db
}

#[test]
fn numeric_string_changes_keep_their_number_type() {
    block_on(async {
        let db = entries(Arc::new(Tweaked::new())).await;
        let entry = doc(vec![("$id", s("entry")), ("ratio", float(10.0)), ("count", int(1))]);
        db.create_document("entries", entry).await.unwrap();
        let ratio = |r: Result<Document>| got(r, "ratio");
        assert_eq!(
            ratio(db.increase_document_attribute("entries", "entry", "ratio", s("1.5"), None).await),
            Some(float(11.5))
        );
        assert_eq!(
            ratio(db.increase_document_attribute("entries", "entry", "ratio", s("2e0"), None).await),
            Some(float(13.5))
        );
        assert_eq!(
            ratio(db.decrease_document_attribute("entries", "entry", "ratio", s("3"), None).await),
            Some(float(10.5))
        );

        let schemaless = entries(Arc::new(Tweaked::new().without(Capability::DefinedAttributes))).await;
        schemaless.create_document("entries", doc(vec![("$id", s("entry"))])).await.unwrap();
        let hits = |r: Result<Document>| got(r, "hits");
        assert_eq!(
            hits(schemaless.increase_document_attribute("entries", "entry", "hits", s("3"), None).await),
            Some(int(3))
        );
        assert_eq!(
            hits(schemaless.increase_document_attribute("entries", "entry", "hits", s("1.5"), None).await),
            Some(float(4.5))
        );
    });
}

#[test]
fn a_non_numeric_bound_is_refused() {
    block_on(async {
        let db = entries(Arc::new(Tweaked::new())).await;
        db.create_document("entries", doc(vec![("$id", s("entry")), ("ratio", float(10.0))])).await.unwrap();
        let increased = db.increase_document_attribute("entries", "entry", "ratio", int(1), Some(s("plenty"))).await;
        refused(increased, Kind::Type, "Value must be numeric.");
        assert_eq!(value(&db, "entries", "entry", "ratio").await, Some(float(10.0)));
    });
}

#[test]
fn bulk_delete_guards() {
    block_on(async {
        let db = entries(Arc::new(Tweaked::new())).await;
        db.create_document("entries", doc(vec![("$id", s("entry")), ("count", int(1))])).await.unwrap();
        refused(db.delete_documents("missing", Vec::new(), 1000, None).await, Kind::NotFound, "Collection not found");
        refused(
            db.delete_documents("entries", vec![Query::equal("missing", vec!["x"])], 1000, None).await,
            Kind::Query,
            "Invalid query: Attribute not found in schema: missing",
        );
        let foreign = doc(vec![("$id", s("entry")), ("$collection", s("other"))]);
        refused(
            db.delete_documents("entries", vec![Query::cursor_after(&foreign)], 1000, None).await,
            Kind::Database,
            "Cursor document must be from the same Collection.",
        );
        let definition = db.get_collection("entries").await.unwrap();
        let adapter = db.adapter().clone();
        let mut stored = adapter.get_document(&definition, "entry", Vec::new(), false).await.unwrap();
        stored.put("$updatedAt", s("not-a-date"));
        adapter.update_document(&definition, "entry", stored, true).await.unwrap();
        assert!(db.delete_documents("entries", Vec::new(), 1000, None).await.unwrap_err().is_a(Kind::Database));
        assert_eq!(db.count("entries", Vec::new(), None).await.unwrap(), 1);
    });
}
