//! PHP: `Documents/IntegerRoundingTest` (the Memory engine; the upsert
//! cases need an adapter with upserts, which Memory is not).

use super::{refused, s};
use crate::attribute::Attribute;
use crate::database::Database;
use crate::database::Hook;
use crate::database::testing::block_on;
use crate::database::testing_memory::{Recorder, collection, doc, memory};
use crate::document::Document;
use crate::error::{Kind, Result};
use crate::event::Event;
use crate::operator::Operator;
use crate::permission::Permission;
use crate::role::Role;
use crate::value::{Value, list};

const COLLECTION: &str = "counters";
const DOCUMENT: &str = "counter";

async fn database() -> Database {
    let database = memory("integer_rounding").await;
    let any = Role::any();
    collection(
        &database,
        COLLECTION,
        vec![
            Attribute::integer("count"),
            Attribute::big_integer("big"),
            Attribute::double("ratio"),
            Attribute::integer("counts").array(true),
        ],
        vec![Permission::create(&any), Permission::read(&any), Permission::update(&any)],
        false,
    )
    .await;
    database
        .create_document(
            COLLECTION,
            doc(vec![
                ("$id", s(DOCUMENT)),
                ("count", Value::Int(100)),
                ("big", Value::Int(0)),
                ("ratio", Value::Float(1.5)),
                ("counts", list([Value::Int(1)])),
            ]),
        )
        .await
        .unwrap();
    database
}

async fn increase(database: &Database, attribute: &str, value: Value, max: Option<Value>) -> Result<Document> {
    database.increase_document_attribute(COLLECTION, DOCUMENT, attribute, value, max).await
}

async fn decrease(database: &Database, attribute: &str, value: Value, min: Option<Value>) -> Result<Document> {
    database.decrease_document_attribute(COLLECTION, DOCUMENT, attribute, value, min).await
}

async fn update(database: &Database, key: &str, value: Value) -> Result<Document> {
    database.update_document(COLLECTION, DOCUMENT, doc(vec![(key, value)])).await
}

async fn stored_of(database: &Database, attribute: &str, id: &str) -> Option<Value> {
    database.get_document(COLLECTION, id, Vec::new(), false).await.unwrap().get(attribute).cloned()
}

async fn stored(database: &Database, attribute: &str) -> Option<Value> {
    stored_of(database, attribute, DOCUMENT).await
}

async fn updated_at(database: &Database) -> Option<String> {
    database.get_document(COLLECTION, DOCUMENT, Vec::new(), false).await.unwrap().updated_at().map(str::to_owned)
}

fn int(i: i64) -> Value {
    Value::Int(i)
}

fn float(f: f64) -> Value {
    Value::Float(f)
}

fn op(operator: Operator) -> Value {
    Value::from(operator)
}

#[track_caller]
fn got(result: Result<Document>, attribute: &str) -> Option<Value> {
    result.unwrap().get(attribute).cloned()
}

#[test]
fn an_amount_is_rounded_half_away_from_zero() {
    block_on(async {
        let db = database().await;
        assert_eq!(got(increase(&db, "count", float(1.5), None).await, "count"), Some(int(102)));
        assert_eq!(got(increase(&db, "count", float(2.5), None).await, "count"), Some(int(105)));
        assert_eq!(got(increase(&db, "count", s("0.5"), None).await, "count"), Some(int(106)));
        assert_eq!(got(increase(&db, "count", float(1.49), None).await, "count"), Some(int(107)));
        assert_eq!(got(increase(&db, "count", float(2.0), None).await, "count"), Some(int(109)));
        assert_eq!(got(decrease(&db, "count", float(2.5), None).await, "count"), Some(int(106)));
        assert_eq!(got(decrease(&db, "count", s("1.5"), None).await, "count"), Some(int(104)));
        assert_eq!(got(decrease(&db, "count", float(0.5), None).await, "count"), Some(int(103)));
        assert_eq!(stored(&db, "count").await, Some(int(103)));
    });
}

#[test]
fn an_amount_below_a_half_leaves_the_document_unchanged() {
    block_on(async {
        let db = database().await;
        let before = updated_at(&db).await;
        assert_eq!(got(increase(&db, "count", float(0.4), None).await, "count"), Some(int(100)));
        assert_eq!(got(decrease(&db, "count", s("0.49"), None).await, "count"), Some(int(100)));
        assert_eq!(got(increase(&db, "count", float(0.4), Some(int(50))).await, "count"), Some(int(100)));
        assert_eq!(stored(&db, "count").await, Some(int(100)));
        assert_eq!(updated_at(&db).await, before);
    });
}

#[test]
fn big_integer_text_is_rounded_exactly() {
    block_on(async {
        let db = database().await;
        let big = |r: Result<Document>| got(r, "big");
        assert_eq!(big(increase(&db, "big", s("9007199254740993.5"), None).await), Some(int(9007199254740994)));
        assert_eq!(big(increase(&db, "big", int(1), Some(s("9007199254740995.9"))).await), Some(int(9007199254740995)));
        refused(
            increase(&db, "big", int(1), Some(s("9007199254740995.9"))).await,
            Kind::Limit,
            "Attribute value exceeds maximum limit: 9007199254740995",
        );
        assert_eq!(big(decrease(&db, "big", s("9007199254740994.5"), None).await), Some(int(0)));
        assert_eq!(
            big(decrease(&db, "big", s("9007199254740994.5"), Some(s("-9007199254740995.2"))).await),
            Some(int(-9007199254740995))
        );
        update(&db, "big", int(i64::MAX - 1)).await.unwrap();
        assert_eq!(big(increase(&db, "big", s("0.5"), None).await), Some(int(i64::MAX)));
        assert_eq!(stored(&db, "big").await, Some(int(i64::MAX)));
    });
}

#[test]
fn a_maximum_is_floored_and_a_minimum_ceiled() {
    block_on(async {
        let db = database().await;
        let count = |r: Result<Document>| got(r, "count");
        assert_eq!(count(increase(&db, "count", int(1), Some(float(101.9))).await), Some(int(101)));
        let max = "Attribute value exceeds maximum limit: 101";
        refused(increase(&db, "count", int(1), Some(float(101.9))).await, Kind::Limit, max);
        refused(increase(&db, "count", int(1), Some(s("101.5"))).await, Kind::Limit, max);
        assert_eq!(count(decrease(&db, "count", int(1), Some(float(99.1))).await), Some(int(100)));
        let min = "Attribute value exceeds minimum limit: 100";
        refused(decrease(&db, "count", int(1), Some(float(99.1))).await, Kind::Limit, min);
        refused(decrease(&db, "count", int(1), Some(s("99.5"))).await, Kind::Limit, min);
        update(&db, "count", int(1)).await.unwrap();
        assert_eq!(count(decrease(&db, "count", int(1), Some(float(-0.5))).await), Some(int(0)));
        refused(
            decrease(&db, "count", int(1), Some(float(-0.5))).await,
            Kind::Limit,
            "Attribute value exceeds minimum limit: 0",
        );
        refused(
            increase(&db, "count", int(1), Some(float(-0.5))).await,
            Kind::Limit,
            "Attribute value exceeds maximum limit: -1",
        );
        assert_eq!(stored(&db, "count").await, Some(int(0)));
    });
}

#[test]
fn whole_bounds_are_compared_exactly() {
    block_on(async {
        let db = database().await;
        let count = |r: Result<Document>| got(r, "count");
        assert_eq!(count(increase(&db, "count", int(1), Some(float(101.0))).await), Some(int(101)));
        refused(
            increase(&db, "count", int(1), Some(s("101.00"))).await,
            Kind::Limit,
            "Attribute value exceeds maximum limit: 101",
        );
        assert_eq!(count(increase(&db, "count", int(1), Some(float(9.0e18))).await), Some(int(102)));
        assert_eq!(count(decrease(&db, "count", int(1), Some(float(-9.0e18))).await), Some(int(101)));
        assert_eq!(count(increase(&db, "count", int(1), Some(float(1.0e19))).await), Some(int(102)));
        assert_eq!(count(decrease(&db, "count", int(1), Some(s("-5.0"))).await), Some(int(101)));
        assert_eq!(stored(&db, "count").await, Some(int(101)));
    });
}

#[test]
fn what_is_not_a_finite_number_is_still_refused() {
    block_on(async {
        let db = database().await;
        refused(increase(&db, "count", int(1), Some(s("ten"))).await, Kind::Type, "Max must be an integer.");
        refused(decrease(&db, "count", int(1), Some(float(f64::NAN))).await, Kind::Type, "Min must be an integer.");
        refused(
            increase(&db, "count", float(f64::INFINITY), None).await,
            Kind::Type,
            "Change value must be an integer.",
        );
        assert_eq!(stored(&db, "count").await, Some(int(100)));
    });
}

#[test]
fn plain_decimal_text_is_rounded() {
    block_on(async {
        let db = database().await;
        let count = |r: Result<Document>| got(r, "count");
        assert_eq!(count(increase(&db, "count", s(" 1.5"), None).await), Some(int(102)));
        assert_eq!(count(increase(&db, "count", s("1.5 "), None).await), Some(int(104)));
        assert_eq!(count(increase(&db, "count", s("+1.5"), None).await), Some(int(106)));
        assert_eq!(count(increase(&db, "count", s(".5"), None).await), Some(int(107)));
        assert_eq!(count(increase(&db, "count", s("1."), None).await), Some(int(108)));
        assert_eq!(count(decrease(&db, "count", s("\t.5\n"), None).await), Some(int(107)));
        refused(
            increase(&db, "count", int(1), Some(s(" 107.9 "))).await,
            Kind::Limit,
            "Attribute value exceeds maximum limit: 107",
        );
        assert_eq!(count(decrease(&db, "count", int(1), Some(s("-.5"))).await), Some(int(106)));
        let operator = Operator::increment("+.5", Some(" 107.9".into()));
        assert_eq!(count(update(&db, "count", op(operator)).await), Some(int(107)));
        assert_eq!(stored(&db, "count").await, Some(int(107)));
    });
}

#[test]
fn a_million_digit_amount_is_refused_without_delay() {
    block_on(async {
        let db = database().await;
        let amount = format!("{}.5", "1".repeat(1_000_000));
        let start = std::time::Instant::now();
        refused(
            increase(&db, "count", s(&amount), None).await,
            Kind::Limit,
            "Attribute value exceeds maximum limit: 2147483647",
        );
        assert!(start.elapsed().as_secs_f64() < 2.0, "rounding a long amount takes time linear in its length");
        assert_eq!(stored(&db, "count").await, Some(int(100)));
    });
}

#[test]
fn text_with_an_exponent_is_refused() {
    block_on(async {
        let db = database().await;
        refused(increase(&db, "count", s("1.5e2"), None).await, Kind::Type, "Change value must be an integer.");
        refused(decrease(&db, "count", s("15e-1"), None).await, Kind::Type, "Change value must be an integer.");
        refused(increase(&db, "count", int(1), Some(s("1.5e2"))).await, Kind::Type, "Max must be an integer.");
        assert_eq!(stored(&db, "count").await, Some(int(100)));
    });
}

#[test]
fn an_operator_amount_given_as_text_is_rounded() {
    block_on(async {
        let db = database().await;
        let count = |r: Result<Document>| got(r, "count");
        assert_eq!(count(update(&db, "count", op(Operator::increment("-0.5", None))).await), Some(int(99)));
        assert_eq!(count(update(&db, "count", op(Operator::decrement("-0.5", None))).await), Some(int(100)));
        assert_eq!(count(update(&db, "count", op(Operator::decrement(" .5", None))).await), Some(int(99)));
        assert_eq!(stored(&db, "count").await, Some(int(99)));
    });
}

#[test]
fn a_counter_change_that_rounds_to_nothing_fires_no_event() {
    block_on(async {
        let db = database().await;
        let before = updated_at(&db).await;
        let recorder = Recorder::new();
        db.add_hook(Hook::lifecycle(recorder.clone())).unwrap();
        increase(&db, "count", float(0.4), None).await.unwrap();
        decrease(&db, "count", s("0.49"), None).await.unwrap();
        assert!(recorder.received(Event::DocumentIncrease).is_empty());
        assert!(recorder.received(Event::DocumentDecrease).is_empty());
        assert!(recorder.received(Event::DocumentUpdate).is_empty());
        assert_eq!(updated_at(&db).await, before);
    });
}

#[test]
fn an_operator_that_rounds_to_nothing_is_no_change() {
    block_on(async {
        let db = database().await;
        let before = updated_at(&db).await;
        let count = |r: Result<Document>| got(r, "count");
        assert_eq!(count(update(&db, "count", op(Operator::increment(0.4, None))).await), Some(int(100)));
        let operator = Operator::decrement("-0.4", Some(100.5.into()));
        assert_eq!(count(update(&db, "count", op(operator)).await), Some(int(100)));
        assert_eq!(updated_at(&db).await, before);

        let same_value = Recorder::new();
        db.add_hook(Hook::lifecycle(same_value.clone())).unwrap();
        update(&db, "count", int(100)).await.unwrap();
        let rounded_to_nothing = Recorder::new();
        db.add_hook(Hook::lifecycle(rounded_to_nothing.clone())).unwrap();
        update(&db, "count", op(Operator::increment(0.4, None))).await.unwrap();
        let expected: Vec<_> = same_value.events().into_iter().take(rounded_to_nothing.events().len()).collect();
        assert_eq!(rounded_to_nothing.events(), expected);
        assert_eq!(updated_at(&db).await, before);
    });
}

#[test]
fn update_documents_with_only_an_operator_that_rounds_to_nothing_updates_nothing() {
    block_on(async {
        let db = database().await;
        let recorder = Recorder::new();
        db.add_hook(Hook::lifecycle(recorder.clone())).unwrap();
        let updates = doc(vec![("count", op(Operator::increment(0.4, None)))]);
        assert_eq!(db.update_documents(COLLECTION, updates, Vec::new(), 1000, None).await.unwrap(), 0);
        let updates = doc(vec![("count", op(Operator::decrement(0.4, None))), ("big", int(7))]);
        assert_eq!(db.update_documents(COLLECTION, updates, Vec::new(), 1000, None).await.unwrap(), 1);
        assert_eq!(recorder.received(Event::DocumentsUpdate).len(), 1);
        assert_eq!(stored(&db, "count").await, Some(int(100)));
        assert_eq!(stored(&db, "big").await, Some(int(7)));
    });
}

#[test]
fn updates_with_an_operator_that_rounds_to_nothing_update_nothing_when_given_again() {
    block_on(async {
        let db = database().await;
        let before = updated_at(&db).await;
        let updates = doc(vec![("count", op(Operator::increment(0.4, None)))]);
        std::thread::sleep(std::time::Duration::from_millis(2));
        assert_eq!(db.update_documents(COLLECTION, updates.clone(), Vec::new(), 1000, None).await.unwrap(), 0);
        assert_eq!(db.update_documents(COLLECTION, updates, Vec::new(), 1000, None).await.unwrap(), 0);
        assert_eq!(updated_at(&db).await, before);
    });
}

#[test]
fn fractional_items_added_to_an_integer_array_are_refused() {
    block_on(async {
        let db = database().await;
        let structure = |r: Result<Document>| {
            let err = r.unwrap_err();
            assert_eq!(err.kind(), Kind::Structure);
            assert!(err.message().starts_with("Invalid document structure: "), "{}", err.message());
        };
        structure(update(&db, "counts", list([int(1), float(2.5)])).await);
        structure(update(&db, "counts", op(Operator::array_append([float(2.5)]))).await);
        assert_eq!(stored(&db, "counts").await, Some(list([int(1)])));
    });
}

#[test]
fn update_document_rounds_increment_and_decrement_operators() {
    block_on(async {
        let db = database().await;
        let count = |r: Result<Document>| got(r, "count");
        assert_eq!(count(update(&db, "count", op(Operator::increment(1.5, None))).await), Some(int(102)));
        assert_eq!(count(update(&db, "count", op(Operator::decrement("2.5", None))).await), Some(int(99)));
        assert_eq!(count(update(&db, "count", op(Operator::increment(-2.5, None))).await), Some(int(96)));
        assert_eq!(count(update(&db, "count", op(Operator::increment(0.4, None))).await), Some(int(96)));
        assert_eq!(count(update(&db, "count", op(Operator::increment(2, Some(98.7.into())))).await), Some(int(98)));
        assert_eq!(count(update(&db, "count", op(Operator::increment(1, Some(98.7.into())))).await), Some(int(98)));
        assert_eq!(count(update(&db, "count", op(Operator::decrement(4, Some("94.2".into())))).await), Some(int(98)));
        assert_eq!(count(update(&db, "count", op(Operator::decrement(3, Some("94.2".into())))).await), Some(int(95)));
        let big = update(&db, "big", op(Operator::increment("9007199254740993.5", None))).await;
        assert_eq!(got(big, "big"), Some(int(9007199254740994)));
        assert_eq!(stored(&db, "count").await, Some(int(95)));
        assert_eq!(stored(&db, "big").await, Some(int(9007199254740994)));
    });
}

#[test]
fn update_documents_rounds_increment_and_decrement_operators() {
    block_on(async {
        let db = database().await;
        let second = doc(vec![("$id", s("second")), ("count", int(10)), ("big", int(0)), ("ratio", float(0.0))]);
        db.create_document(COLLECTION, second).await.unwrap();
        let all = |operator: Operator| doc(vec![("count", op(operator))]);
        let updated = db.update_documents(COLLECTION, all(Operator::increment(2.5, None)), Vec::new(), 1000, None);
        assert_eq!(updated.await.unwrap(), 2);
        assert_eq!(stored(&db, "count").await, Some(int(103)));
        assert_eq!(stored_of(&db, "count", "second").await, Some(int(13)));
        let updates = all(Operator::increment(7, Some(20.9.into())));
        db.update_documents(COLLECTION, updates, Vec::new(), 1000, None).await.unwrap();
        assert_eq!(stored(&db, "count").await, Some(int(103)));
        assert_eq!(stored_of(&db, "count", "second").await, Some(int(20)));
        let updates = all(Operator::decrement(0.5, Some(15.5.into())));
        db.update_documents(COLLECTION, updates, Vec::new(), 1000, None).await.unwrap();
        assert_eq!(stored(&db, "count").await, Some(int(102)));
        assert_eq!(stored_of(&db, "count", "second").await, Some(int(19)));
    });
}

#[test]
fn multiply_divide_modulo_or_power_by_a_fraction_on_an_integer_is_refused() {
    block_on(async {
        let operators = [
            (Operator::multiply(1.5, None), "multiply"),
            (Operator::divide(2.5, None).unwrap(), "divide"),
            (Operator::modulo(1.5).unwrap(), "modulo"),
            (Operator::power(0.5, None), "power"),
        ];
        for (operator, method) in operators {
            let db = database().await;
            refused(
                update(&db, "count", op(operator)).await,
                Kind::Structure,
                &format!(
                    "Invalid document structure: Cannot apply {method} operator: value must be numeric, got double"
                ),
            );
            assert_eq!(stored(&db, "count").await, Some(int(100)));
        }
    });
}

#[test]
fn float_attributes_are_not_rounded() {
    block_on(async {
        let db = database().await;
        let ratio = |r: Result<Document>| got(r, "ratio");
        assert_eq!(ratio(increase(&db, "ratio", float(1.25), None).await), Some(float(2.75)));
        assert_eq!(ratio(decrease(&db, "ratio", float(0.5), Some(float(2.25))).await), Some(float(2.25)));
        refused(
            increase(&db, "ratio", float(0.5), Some(float(2.5))).await,
            Kind::Limit,
            "Attribute value exceeds maximum limit: 2.5",
        );
        assert_eq!(ratio(update(&db, "ratio", op(Operator::increment(0.5, None))).await), Some(float(2.75)));
        assert_eq!(
            ratio(update(&db, "ratio", op(Operator::increment(0.25, Some(3.25.into())))).await),
            Some(float(3.0))
        );
        assert_eq!(
            ratio(update(&db, "ratio", op(Operator::increment(0.5, Some(3.25.into())))).await),
            Some(float(3.0))
        );
        assert_eq!(ratio(update(&db, "ratio", op(Operator::multiply(0.5, None))).await), Some(float(1.5)));
        assert_eq!(stored(&db, "ratio").await, Some(float(1.5)));
    });
}
