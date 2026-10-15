//! Ported from utopia-php/database 8.1.3: the Redis parts of
//! `tests/unit/BigIntegerTest.php` (the Redis adapter's own
//! `applyOperator()`, reached through [`Redis::apply_operator`] as PHP
//! reaches it through a subclass) and the Redis schema-records test of
//! `tests/unit/BigIntegerSpellingTest.php`.
//!
//! The Memory halves of the shared tests belong to the Memory adapter's
//! port.

#![cfg(feature = "redis")]

mod redis_probe;

use redis_probe::{namespace, raw};
use utopia_database::Kind;
use utopia_database::adapter::Adapter;
use utopia_database::adapter::redis::Redis;
use utopia_database::attribute::Attribute;
use utopia_database::operator::{Number, Operator, OperatorType};
use utopia_database::validator::big_int::UNSIGNED_MAX;
use utopia_database::value::{Str, Value};

/// A connection that is never used: the operator arithmetic does not reach
/// the server, so these tests run without one.
#[derive(Clone)]
struct Offline;

impl redis::aio::ConnectionLike for Offline {
    fn req_packed_command<'a>(&'a mut self, _cmd: &'a redis::Cmd) -> redis::RedisFuture<'a, redis::Value> {
        Box::pin(async { Err(std::io::Error::other("offline").into()) })
    }

    fn req_packed_commands<'a>(
        &'a mut self,
        _cmd: &'a redis::Pipeline,
        _offset: usize,
        _count: usize,
    ) -> redis::RedisFuture<'a, Vec<redis::Value>> {
        Box::pin(async { Err(std::io::Error::other("offline").into()) })
    }

    fn get_db(&self) -> i64 {
        0
    }
}

/// An adapter for its operators only (PHP's subclass over a stub client).
fn operators() -> Redis<Offline> {
    Redis::new(Offline)
}

fn s(text: &str) -> Value {
    Value::Str(Str::from(text))
}

fn same(actual: &Value, expected: &Value, case: &str) {
    assert!(actual.strict_eq(expected), "{case}: expected {expected:?}, got {actual:?}");
}

#[tokio::test]
async fn test_memory_and_redis_operators_leave_values_unchanged_when_bounds_are_crossed() {
    let redis = operators();
    let n = |i: i64| Some(Number::from(i));
    let cases: Vec<(Value, Operator, Value)> = vec![
        (Value::Int(10), Operator::increment(100, n(50)), Value::Int(10)),
        (Value::Float(5.0), Operator::decrement(10, n(0)), Value::Float(5.0)),
        (Value::Int(10), Operator::multiply(10, n(75)), Value::Int(10)),
        (Value::Float(100.0), Operator::divide(-4, n(-10)).unwrap(), Value::Float(100.0)),
        (Value::Float(20.0), Operator::divide(-2, n(-50)).unwrap(), Value::Float(-10.0)),
        (Value::Float(80.0), Operator::multiply(0.5, n(50)), Value::Float(40.0)),
        (Value::Float(52.0), Operator::increment(-5, n(50)), Value::Float(47.0)),
        (Value::Int(10), Operator::increment(5, n(15)), Value::Int(15)),
        (Value::Int(10), Operator::decrement(5, n(5)), Value::Int(5)),
        (Value::Float(-10.0), Operator::multiply(-2, n(50)), Value::Float(20.0)),
        (Value::Float(5.0), Operator::power(3, n(100)), Value::Float(5.0)),
        (Value::Float(100.0), Operator::power(0.5, n(50)), Value::Float(10.0)),
        (Value::Float(-4.0), Operator::power(2, n(20)), Value::Float(16.0)),
        (Value::Float(-2.0), Operator::power(3, n(100)), Value::Float(-8.0)),
        (Value::Float(0.0), Operator::power(-1, n(100)), Value::Float(0.0)),
        (Value::Float(-4.0), Operator::power(0.5, n(100)), Value::Float(-4.0)),
        (Value::Int(i64::MAX), Operator::increment(2, n(i64::MAX)), Value::Int(i64::MAX)),
        (Value::Int(i64::MAX), Operator::increment(1, Some(Number::from(UNSIGNED_MAX))), s("9223372036854775808")),
    ];
    for (i, (current, operator, expected)) in cases.iter().enumerate() {
        same(&redis.apply_operator(current, operator).unwrap(), expected, &format!("case {i}"));
    }
}

#[tokio::test]
async fn test_memory_and_redis_reject_unbounded_invalid_powers() {
    let redis = operators();
    let e = redis.apply_operator(&Value::Float(0.0), &Operator::power(-1, None)).unwrap_err();
    assert_eq!(e.kind(), Kind::Limit);
    assert_eq!(e.message(), "Value out of range");
}

#[tokio::test]
async fn test_memory_and_redis_honour_a_float_bound_on_the_exact_big_integer_path() {
    let redis = operators();
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
            s("9223372036854775808"),
        ),
        (
            "increment below a float max",
            Value::Int(i64::MAX),
            Operator::increment(1, Some(1.0e19.into())),
            s("9223372036854775808"),
        ),
        (
            "increment below a whole string max",
            Value::Int(i64::MAX),
            Operator::increment(1, Some("9223372036854775808.0".into())),
            s("9223372036854775808"),
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
            s("-9223372036854775809"),
        ),
        (
            "multiply above a float max",
            Value::Int(i64::MAX),
            Operator::multiply(2, Some(1.5e19.into())),
            Value::Int(i64::MAX),
        ),
        ("unbounded increment", Value::Int(i64::MAX), Operator::increment(1, None), s("9223372036854775808")),
    ];
    for (case, current, operator, expected) in &cases {
        same(&redis.apply_operator(current, operator).unwrap(), expected, case);
    }
}

#[tokio::test]
async fn test_memory_and_redis_refuse_a_fractional_bound_on_the_exact_big_integer_path() {
    let redis = operators();
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
    for (case, operator, message) in &cases {
        let current = if operator.method() == OperatorType::Decrement { i64::MIN } else { i64::MAX };
        let e = redis.apply_operator(&Value::Int(current), operator).unwrap_err();
        assert_eq!(e.kind(), Kind::Operator, "{case}");
        assert_eq!(e.message(), *message, "{case}");
    }
}

#[tokio::test]
async fn test_redis_operator_preserves_unsigned_integer_strings() {
    let redis = operators();
    same(
        &redis.apply_operator(&Value::Int(i64::MAX), &Operator::increment(1, None)).unwrap(),
        &s("9223372036854775808"),
        "max + 1",
    );
    same(
        &redis.apply_operator(&s("18446744073709551614"), &Operator::increment(1, None)).unwrap(),
        &s("18446744073709551615"),
        "unsigned + 1",
    );
    same(
        &redis.apply_operator(&s("9223372036854775808"), &Operator::decrement(1, None)).unwrap(),
        &Value::Int(i64::MAX),
        "back to native",
    );
}

#[tokio::test]
async fn test_redis_keeps_the_stored_value_where_an_operator_cannot_apply() {
    let redis = operators();
    same(
        &redis.apply_operator(&Value::Float(10.0), &Operator::power(400, Some(1000.into()))).unwrap(),
        &Value::Float(10.0),
        "power",
    );
    let divide = Operator::new(OperatorType::Divide, "value", vec![Value::Int(0)]);
    same(&redis.apply_operator(&Value::Int(10), &divide).unwrap(), &Value::Int(10), "divide");
    let modulo = Operator::new(OperatorType::Modulo, "value", vec![Value::Int(0)]);
    same(&redis.apply_operator(&Value::Int(10), &modulo).unwrap(), &Value::Int(10), "modulo");

    let e = redis.apply_operator(&Value::Float(10.0), &Operator::power(400, None)).unwrap_err();
    assert_eq!(e.kind(), Kind::Limit);
    assert_eq!(e.message(), "Value out of range");
}

#[tokio::test]
async fn test_redis_keeps_an_unparsable_date() {
    let redis = operators();
    same(&redis.apply_operator(&s("not-a-date"), &Operator::date_add_days(1)).unwrap(), &s("not-a-date"), "add");
    same(&redis.apply_operator(&s("not-a-date"), &Operator::date_sub_days(1)).unwrap(), &s("not-a-date"), "sub");
}

/// `BigIntegerSpellingTest::testRedisSchemaRecordsPersistTheBigintSpelling`:
/// the attribute records of the meta hash spell a big integer `bigint`.
#[tokio::test]
async fn test_redis_schema_records_persist_the_bigint_spelling() {
    let probe = probe_or_skip!();
    let ns = namespace("bigint_spelling");
    let adapter = Redis::new(probe.clone());
    adapter.set_namespace(&ns);
    adapter.create_collection("ledger", &[Attribute::big_integer("inline")], &[]).await.unwrap();
    adapter.create_attribute("ledger", &Attribute::big_integer("single")).await.unwrap();
    adapter.update_attribute("ledger", "inline", &Attribute::big_integer("inline").required(true)).await.unwrap();

    let meta = format!("utopia:{ns}::meta:ledger");
    let mut records = Vec::new();
    for field in ["attrs", "schema"] {
        let encoded: String = raw(&probe, redis::cmd("HGET").arg(&meta).arg(field)).await;
        let decoded: serde_json::Value = serde_json::from_str(&encoded).unwrap();
        let attributes = if field == "schema" { decoded["attributes"].clone() } else { decoded };
        for attribute in attributes.as_array().unwrap() {
            records.push((format!("{field}:{}", attribute["key"].as_str().unwrap()), attribute["type"].clone()));
        }
    }
    records.sort_by(|a, b| a.0.cmp(&b.0));

    assert_eq!(
        records,
        [
            ("attrs:inline".to_owned(), serde_json::json!("bigint")),
            ("attrs:single".to_owned(), serde_json::json!("bigint")),
            ("schema:inline".to_owned(), serde_json::json!("bigint")),
        ]
    );
}

/// Every operator of the Redis adapter on the inputs of
/// `fixtures/redis_operators_php.json`, as PHP's `applyOperator()` answers
/// them (`var_export()` of the result, or the class and message thrown);
/// regenerate the fixture with `fixtures/redis_operators_php.php`.
#[tokio::test]
async fn test_redis_operators_answer_as_php() {
    let redis = operators();
    let cases: Vec<serde_json::Value> =
        serde_json::from_str(include_str!("fixtures/redis_operators_php.json")).expect("the fixture is JSON");
    let value = |json: &serde_json::Value| utopia_database::value::from_plain(php_std::Value::from_json(json));
    for case in &cases {
        let current = value(&case["current"]);
        let method = OperatorType::from_value(case["method"].as_str().unwrap()).unwrap();
        let values: Vec<Value> = case["values"].as_array().unwrap().iter().map(value).collect();
        let operator = Operator::new(method, "value", values);
        let label = format!("{} {} {}", case["current"], case["method"], case["values"]);
        let expected = &case["result"];
        match redis.apply_operator(&current, &operator) {
            Ok(result) => {
                assert_eq!(expected[0], "ok", "{label}: PHP threw {expected}, Rust returned {result:?}");
                let exported = String::from_utf8(php_std::serialize::var_export(&result)).unwrap();
                assert_eq!(exported, expected[1].as_str().unwrap(), "{label}");
            }
            Err(e) => {
                assert_eq!(expected[0], "error", "{label}: PHP returned {expected}, Rust threw {}", e.message());
                assert_eq!(e.php_class(), expected[1].as_str().unwrap(), "{label}");
                assert_eq!(e.message(), expected[2].as_str().unwrap(), "{label}");
            }
        }
    }
}
