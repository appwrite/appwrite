//! PHP: `Documents/CounterRangeRaceTest` (utopia-php/database 8.3.6): an
//! increase or decrease is held to the range its column's format holds it
//! to, and refused when the write's own condition left the row unchanged.
//! Lines cited are of `src/Database/Trait/Documents.php` at 8.3.6.

use std::sync::Arc;

use super::{refused, s};
use crate::attribute::{Attribute, Format};
use crate::database::Database;
use crate::database::testing::block_on;
use crate::database::testing_memory::{Tweaked, collection, database_on, doc, memory};
use crate::error::Kind;
use crate::permission::Permission;
use crate::role::Role;
use crate::validator::structure::formats;
use crate::value::{ArrayKey, Str, Value};
use utopia_query::schema::ColumnType;
use utopia_validators::Range;

const COLLECTION: &str = "counters";
const INTEGER_RANGE: &str = "counterRangeInteger";
const DOUBLE_RANGE: &str = "counterRangeDouble";

fn options(min: Value, max: Value) -> crate::value::Array {
    let mut options = crate::value::Array::new();
    options.set(ArrayKey::Str(Str::from("min")), min);
    options.set(ArrayKey::Str(Str::from("max")), max);
    options
}

fn register() {
    formats::add(INTEGER_RANGE, Arc::new(|_| Box::new(Range::new(0, 10))), ColumnType::Integer);
    formats::add(
        DOUBLE_RANGE,
        Arc::new(|_| {
            Box::new(Range::with_format(
                php_std::value::Number::Float(-1.5),
                php_std::value::Number::Float(2.5),
                utopia_validators::Type::Float,
            ))
        }),
        ColumnType::Double,
    );
}

async fn database(b: i64, f: f64) -> Database {
    database_on_engine(b, f, None).await
}

/// The race database on an engine that interleaves another connection's
/// increase (`Interleaving\Memory`).
async fn racing(b: i64, f: f64) -> (Database, Arc<Tweaked>) {
    let engine = Arc::new(Tweaked::new());
    (database_on_engine(b, f, Some(engine.clone())).await, engine)
}

async fn database_on_engine(b: i64, f: f64, engine: Option<Arc<Tweaked>>) -> Database {
    register();
    let database = match engine {
        Some(engine) => database_on(engine, "counter_race").await,
        None => memory("counter_range").await,
    };
    let any = Role::any();
    collection(
        &database,
        COLLECTION,
        vec![
            Attribute::integer("b").format(Format::new(INTEGER_RANGE, options(Value::Int(0), Value::Int(10))).unwrap()),
            Attribute::double("f")
                .format(Format::new(DOUBLE_RANGE, options(Value::Float(-1.5), Value::Float(2.5))).unwrap()),
            Attribute::integer("n"),
        ],
        vec![Permission::create(&any), Permission::read(&any), Permission::update(&any)],
        false,
    )
    .await;
    database
        .create_document(
            COLLECTION,
            doc(vec![("$id", s("row")), ("b", Value::Int(b)), ("f", Value::Float(f)), ("n", Value::Int(0))]),
        )
        .await
        .unwrap();
    database
}

async fn stored(database: &Database, attribute: &str) -> Option<Value> {
    database.get_document(COLLECTION, "row", Vec::new(), false).await.unwrap().get(attribute).cloned()
}

/// Documents.php:2809 (`tighterMaximum()`, 294-308): the column's range
/// bounds an increase given no maximum, and a looser one.
#[test]
fn an_increase_is_held_to_the_column_maximum() {
    block_on(async {
        let database = database(9, 2.0).await;
        refused(
            database.increase_document_attribute(COLLECTION, "row", "b", Value::Int(2), None).await,
            Kind::Limit,
            "Attribute value exceeds maximum limit: 10",
        );
        refused(
            database.increase_document_attribute(COLLECTION, "row", "b", Value::Int(2), Some(Value::Int(50))).await,
            Kind::Limit,
            "Attribute value exceeds maximum limit: 10",
        );
        database.increase_document_attribute(COLLECTION, "row", "b", Value::Int(1), None).await.unwrap();
        assert_eq!(stored(&database, "b").await, Some(Value::Int(10)));
    });
}

/// Documents.php:2946 (`tighterMinimum()`, 313-327): a decrease of a double
/// is held to the column's minimum.
#[test]
fn a_decrease_is_held_to_the_column_minimum() {
    block_on(async {
        let database = database(1, -1.0).await;
        refused(
            database.decrease_document_attribute(COLLECTION, "row", "f", Value::Float(0.75), None).await,
            Kind::Limit,
            "Attribute value exceeds minimum limit: -1.5",
        );
        database
            .decrease_document_attribute(COLLECTION, "row", "f", Value::Float(0.5), Some(Value::Int(-5)))
            .await
            .unwrap();
        assert_eq!(stored(&database, "f").await, Some(Value::Float(-1.5)));
    });
}

/// Adapter/Memory.php:1919-1932 (8.3.6): an increase whose stored value
/// already crossed its bound changes nothing and answers false, which
/// Documents.php:2879 refuses.
#[test]
fn an_increase_past_its_bound_in_the_write_answers_false() {
    block_on(async {
        let database = database(9, 2.0).await;
        let collection = database.get_collection(COLLECTION).await.unwrap();
        let adapter = database.adapter();
        let changed = adapter
            .increase_document_attribute(
                &collection,
                "row",
                "n",
                Value::Int(1),
                "2026-01-01 00:00:00.000",
                None,
                Some(Value::Int(-1)),
            )
            .await
            .unwrap();
        assert!(!changed);
        assert_eq!(stored(&database, "n").await, Some(Value::Int(0)));
    });
}

/// CounterRangeRaceTest::testAnIncreaseThatAnotherIncreaseCarriedPastTheColumnMaximumIsRefused
/// (Documents.php:2878-2881): the write's own bound refuses an increase
/// another one carried past the column maximum after the read.
#[test]
fn an_increase_another_carried_past_the_maximum_is_refused() {
    block_on(async {
        let (database, engine) = racing(9, 2.0).await;
        engine.increase_before_next("b", Value::Int(1));
        refused(
            database.increase_document_attribute(COLLECTION, "row", "b", Value::Int(1), None).await,
            Kind::Limit,
            "Attribute value exceeds maximum limit: 10",
        );
    });
}

/// CounterRangeRaceTest::testADecreaseThatAnotherDecreaseCarriedPastTheColumnMinimumIsRefused.
#[test]
fn a_decrease_another_carried_past_the_minimum_is_refused() {
    block_on(async {
        let (database, engine) = racing(1, -1.0).await;
        engine.increase_before_next("f", Value::Float(-0.5));
        refused(
            database.decrease_document_attribute(COLLECTION, "row", "f", Value::Float(0.25), None).await,
            Kind::Limit,
            "Attribute value exceeds minimum limit: -1.5",
        );
    });
}

/// CounterRangeRaceTest::testAnIncreaseThatAnotherIncreaseCarriedPastTheGivenMaximumIsRefused.
#[test]
fn an_increase_another_carried_past_the_given_maximum_is_refused() {
    block_on(async {
        let (database, engine) = racing(0, 0.0).await;
        engine.increase_before_next("n", Value::Int(5));
        refused(
            database.increase_document_attribute(COLLECTION, "row", "n", Value::Int(3), Some(Value::Int(6))).await,
            Kind::Limit,
            "Attribute value exceeds maximum limit: 6",
        );
    });
}

/// CounterRangeRaceTest::testAnIncreaseThatStillFitsAfterAnotherIncreaseIsApplied.
#[test]
fn an_increase_that_still_fits_after_another_is_applied() {
    block_on(async {
        let (database, engine) = racing(7, 2.0).await;
        engine.increase_before_next("b", Value::Int(1));
        database.increase_document_attribute(COLLECTION, "row", "b", Value::Int(2), None).await.unwrap();
        assert_eq!(stored(&database, "b").await, Some(Value::Int(10)));
    });
}
