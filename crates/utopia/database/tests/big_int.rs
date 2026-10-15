//! Ports of `tests/unit/Validator/BigIntTest.php`, and of the parts of
//! `tests/unit/BigIntegerTest.php` and `tests/unit/BigIntegerSpellingTest.php`
//! that need no database or adapter (utopia-php/database 8.1.3), plus
//! `Rounding` cases confirmed against PHP 8.5.

use std::cmp::Ordering;

use utopia_database::attribute::{self, Update};
use utopia_database::rounding::Rounding;
use utopia_database::validator::big_int::{BigInt, Integer, UNSIGNED_MAX};
use utopia_database::value::{Array, Value};
use utopia_database::{Attribute, Document, Kind};
use utopia_query::schema::ColumnType;

fn int(i: i64) -> Integer {
    Integer::Int(i)
}

fn big(s: &str) -> Integer {
    Integer::Str(s.to_owned())
}

#[track_caller]
fn assert_invalid_argument<T: std::fmt::Debug>(result: utopia_database::Result<T>, message: &str) {
    let e = result.unwrap_err();
    assert_eq!(e.kind(), Kind::InvalidArgument);
    assert_eq!(e.php_class(), "InvalidArgumentException");
    assert_eq!(e.message(), message);
}

// BigIntTest

#[test]
fn test_type_is_the_big_integer_column_type() {
    assert_eq!(BigInt::new(true, true).type_name(), ColumnType::BigInteger.as_str());
    assert_eq!(BigInt::new(false, true).type_name(), ColumnType::BigInteger.as_str());
}

#[test]
fn test_negating_zero_gives_the_integer_zero() {
    assert_eq!(BigInt::negate(0).unwrap(), int(0));
    assert_eq!(BigInt::negate("0").unwrap(), int(0));
    assert_eq!(BigInt::negate("-0").unwrap(), int(0));
    assert_eq!(BigInt::subtract(5, 0).unwrap(), int(5));
}

#[test]
fn test_division_signs_the_quotient() {
    assert_eq!(BigInt::divide(-10, 3).unwrap(), int(-3));
    assert_eq!(BigInt::divide(10, -3).unwrap(), int(-3));
    assert_eq!(BigInt::divide(-10, -3).unwrap(), int(3));
    assert_eq!(BigInt::divide(-1, 3).unwrap(), int(0));
}

#[test]
fn test_division_by_zero_is_refused() {
    assert_invalid_argument(BigInt::divide(1, 0), "Division by zero is not allowed.");
}

#[test]
fn test_modulo_by_zero_is_refused() {
    assert_invalid_argument(BigInt::modulo(5, 0), "Modulo by zero is not allowed.");
}

#[test]
fn test_normalizing_accepts_whole_floats_and_integer_strings() {
    assert_eq!(BigInt::normalize_integer(&Value::Float(5.0)).unwrap(), "5");
    assert_eq!(BigInt::normalize_integer(&Value::Float(-5.0)).unwrap(), "-5");
    assert_eq!(BigInt::normalize_integer(&Value::from("007")).unwrap(), "7");
    assert_eq!(BigInt::normalize_integer(&Value::from("-0")).unwrap(), "0");
    assert_eq!(BigInt::normalize_integer(&Value::from(UNSIGNED_MAX)).unwrap(), UNSIGNED_MAX);
}

#[test]
fn test_normalizing_rejects_a_value_that_is_not_an_integer() {
    for value in [
        Value::Float(1.5),
        Value::Float(f64::INFINITY),
        Value::Float(f64::NAN),
        Value::Float(1e20),
        Value::Float(-1e20),
        Value::Bool(true),
        Value::Null,
        Value::from("abc"),
        Value::from("1.5"),
    ] {
        assert_invalid_argument(BigInt::normalize_integer(&value), "Value must be an integer.");
    }
}

#[test]
fn test_power_refuses_a_negative_exponent() {
    assert_invalid_argument(BigInt::power(5, -1), "Integer power exponent must not be negative.");
}

#[test]
fn test_power_of_zero_exponent_and_unit_bases() {
    assert_eq!(BigInt::power(2, 0).unwrap(), int(1));
    assert_eq!(BigInt::power(0, 0).unwrap(), int(1));
    assert_eq!(BigInt::power(0, 99).unwrap(), int(0));
    assert_eq!(BigInt::power(1, 99).unwrap(), int(1));
    assert_eq!(BigInt::power(-1, 3).unwrap(), int(-1));
    assert_eq!(BigInt::power(-1, 4).unwrap(), int(1));
    assert_eq!(BigInt::power(-1, "99999999999999999999999").unwrap(), int(-1));
}

#[test]
fn test_power_above_sixty_four_is_an_overflow_sentinel() {
    let sentinel = format!("{UNSIGNED_MAX}0");
    assert_eq!(BigInt::power(2, 65).unwrap(), big(&sentinel));
    assert_eq!(BigInt::power(-2, 65).unwrap(), big(&format!("-{sentinel}")));
    assert_eq!(BigInt::power(-2, 66).unwrap(), big(&sentinel));
    assert_eq!(BigInt::power(2, "99999999999999999999").unwrap(), big(&sentinel));
}

#[test]
fn test_native_integer_arithmetic_at_the_signed_limits() {
    assert_eq!(BigInt::add(i64::MAX - 1, 1).unwrap(), int(i64::MAX));
    assert_eq!(BigInt::add(i64::MIN + 1, -1).unwrap(), int(i64::MIN));
    assert_eq!(BigInt::subtract(i64::MAX - 1, -1).unwrap(), int(i64::MAX));
    assert_eq!(BigInt::add("5", "7").unwrap(), int(12));
    assert_eq!(BigInt::add(i64::MAX, 1).unwrap(), big("9223372036854775808"));
    assert_eq!(BigInt::add(i64::MIN, -1).unwrap(), big("-9223372036854775809"));
    assert_eq!(BigInt::subtract(i64::MIN + 1, 1).unwrap(), int(i64::MIN));
    assert_eq!(BigInt::subtract(i64::MIN, 1).unwrap(), big("-9223372036854775809"));
    assert_eq!(BigInt::negate(i64::MIN).unwrap(), big("9223372036854775808"));
    assert_eq!(BigInt::negate(i64::MAX).unwrap(), int(-i64::MAX));
    assert_eq!(BigInt::add(5, -5).unwrap(), int(0));
}

#[test]
fn test_string_arithmetic_carries_and_borrows_across_long_numbers() {
    let nines = "9".repeat(36);
    let power = format!("1{}", "0".repeat(36));
    assert_eq!(BigInt::add(&nines, 1).unwrap(), big(&power));
    assert_eq!(BigInt::add(&"9".repeat(40), &"9".repeat(40)).unwrap(), big(&format!("1{}8", "9".repeat(39))));
    assert_eq!(
        BigInt::add("123456789012345678901234567890", "987654321098765432109876543210").unwrap(),
        big("1111111110111111111011111111100")
    );
    assert_eq!(BigInt::subtract(&power, 1).unwrap(), big(&nines));
    assert_eq!(BigInt::subtract(&format!("1{}", "0".repeat(40)), &"9".repeat(40)).unwrap(), int(1));
    assert_eq!(BigInt::add(&power, &format!("-{nines}")).unwrap(), int(1));
    assert_eq!(BigInt::add(&format!("-{power}"), 1).unwrap(), big(&format!("-{nines}")));
    assert_eq!(BigInt::subtract(&format!("-{nines}"), 1).unwrap(), big(&format!("-{power}")));
    assert_eq!(
        BigInt::add("99999999999999999999999999999999999999", "99999999999999900000000000000000000000000000000000001")
            .unwrap(),
        big("100000000000000000000000000000000000000000000000000000")
    );
    assert_eq!(BigInt::add(&"9".repeat(1_000_000), 1).unwrap(), big(&format!("1{}", "0".repeat(1_000_000))));
    assert_eq!(BigInt::subtract(&format!("1{}", "0".repeat(1_000_000)), 1).unwrap(), big(&"9".repeat(1_000_000)));
}

#[test]
fn test_comparing_native_and_string_integers_agrees() {
    assert_eq!(BigInt::compare(-2, 1).unwrap(), Ordering::Less);
    assert_eq!(BigInt::compare(7, "7").unwrap(), Ordering::Equal);
    assert_eq!(BigInt::compare(i64::MAX, i64::MIN).unwrap(), Ordering::Greater);
    assert_eq!(BigInt::compare(i64::MAX, UNSIGNED_MAX).unwrap(), Ordering::Less);
}

#[test]
fn test_native_integers_normalize_to_their_digits() {
    assert_eq!(BigInt::normalize_integer(&Value::Int(-42)).unwrap(), "-42");
    assert_eq!(BigInt::normalize_integer(&Value::Int(0)).unwrap(), "0");
    assert_eq!(BigInt::to_native(i64::MIN.into()).unwrap(), int(i64::MIN));
    assert_eq!(BigInt::to_native(12.into()).unwrap(), int(12));
}

// BigIntegerTest (the parts without a database or adapter)

#[test]
fn test_unsigned_bounds_and_arithmetic_stay_exact() {
    let bounds = Attribute::big_integer("total").signed(false).bounds().unwrap();
    assert_eq!(bounds.max, Value::from("18446744073709551615"));
    assert_eq!(BigInt::add(i64::MAX, 1).unwrap(), big("9223372036854775808"));
    assert_eq!(BigInt::subtract("9223372036854775808", 1).unwrap(), int(i64::MAX));
    assert_eq!(BigInt::add("18446744073709551614", 1).unwrap(), big("18446744073709551615"));
    assert_eq!(BigInt::add(UNSIGNED_MAX, 1).unwrap(), big("18446744073709551616"));
    assert_eq!(BigInt::subtract(UNSIGNED_MAX, 1).unwrap(), big("18446744073709551614"));
    assert_eq!(BigInt::multiply("9223372036854775807", 2).unwrap(), big("18446744073709551614"));
    assert_eq!(BigInt::modulo(UNSIGNED_MAX, 2).unwrap(), int(1));
}

// BigIntegerSpellingTest (the parts without a database or adapter)

const PERSISTED: &str = "bigint";

#[test]
fn test_stored_type_keeps_the_bigint_spelling_for_big_integer_only() {
    for kind in attribute::TYPES {
        let expected = if kind == ColumnType::BigInteger { PERSISTED } else { kind.as_str() };
        assert_eq!(Attribute::stored_type(kind), expected, "{}", kind.name());
        assert_eq!(Attribute::type_from_stored(Attribute::stored_type(kind)).unwrap(), kind, "{}", kind.name());
    }
}

#[test]
fn test_both_spellings_normalize_to_big_integer() {
    for spelling in [PERSISTED, ColumnType::BigInteger.as_str()] {
        assert_eq!(Attribute::type_from_stored(spelling).unwrap(), ColumnType::BigInteger, "{spelling}");
    }
    assert_eq!(Attribute::type_from_stored("huge").unwrap_err().kind(), Kind::Structure);
}

#[test]
fn test_attribute_models_hold_the_persisted_spelling() {
    let stored_type = |a: &Attribute| a.to_document().unwrap().get("type").cloned();
    let attribute = Attribute::big_integer("total");
    assert_eq!(attribute.kind, ColumnType::BigInteger);
    assert_eq!(stored_type(&attribute), Some(Value::from(PERSISTED)));
    let array = |kind: &str| -> Array {
        [("$id", Value::from("total")), ("type", Value::from(kind))].into_iter().map(|(k, v)| (k.into(), v)).collect()
    };
    assert_eq!(
        stored_type(&Attribute::from_array(&array(ColumnType::BigInteger.as_str())).unwrap()),
        Some(Value::from(PERSISTED))
    );
    let document = Document::from_pairs([("$id", Value::from("total")), ("type", Value::from(PERSISTED))]).unwrap();
    assert_eq!(Attribute::from_document(&document).unwrap().kind, ColumnType::BigInteger);

    let changed = Attribute::integer("count")
        .apply(&Update { kind: Some(ColumnType::BigInteger), ..Default::default() })
        .unwrap();
    assert_eq!(changed.kind, ColumnType::BigInteger);
    assert_eq!(stored_type(&changed), Some(Value::from(PERSISTED)));
    let changed = changed.apply(&Update { kind: Some(ColumnType::Integer), ..Default::default() }).unwrap();
    assert_eq!(changed.kind, ColumnType::Integer);
    assert_eq!(stored_type(&changed), Some(Value::from(ColumnType::Integer.as_str())));

    let huge: Array = [("$id", Value::from("count")), ("type", Value::from("huge"))]
        .into_iter()
        .map(|(k, v)| (k.into(), v))
        .collect();
    assert_eq!(Attribute::from_array(&huge).unwrap_err().kind(), Kind::Structure);
}

// Exact semantics confirmed against PHP 8.5 with utopia-php/database 8.1.3.

#[test]
fn test_php_edge_cases() {
    // `(int) 2^63` wraps, as PHP's cast does.
    assert_eq!(BigInt::normalize_integer(&Value::Float(9_223_372_036_854_775_808.0)).unwrap(), "-9223372036854775808");
    assert!(BigInt::to_native(" 5".into()).is_err());
    assert_eq!(BigInt::to_native("5\n".into()).unwrap(), int(5));
    assert_eq!(BigInt::integral_value("12.00\n".into()), Some(int(12)));
    assert_eq!(BigInt::integral_value(1.0e19.into()), Some(big("10000000000000000000")));
    assert_eq!(BigInt::integral_value((-1.5e20).into()), Some(big("-150000000000000000000")));
    assert_eq!(BigInt::integral_value(102.4.into()), None);
    assert_eq!(BigInt::integral_value("102.4".into()), None);
    assert_eq!(BigInt::power(-3, 41).unwrap(), big("-36472996377170786403"));
    assert_eq!(BigInt::power(-2, 63).unwrap(), int(i64::MIN));
    assert_eq!(BigInt::modulo(-7, 3).unwrap(), int(-1));
    assert_eq!(BigInt::divide("100000000000000000000000", "7").unwrap(), big("14285714285714285714285"));
    assert_eq!(
        BigInt::multiply(&"9".repeat(30), &"9".repeat(30)).unwrap(),
        big(&format!("{}8{}1", "9".repeat(29), "0".repeat(29)))
    );
}

#[test]
fn test_rounding() {
    let s = |v: &str| Value::from(v);
    assert_eq!(Rounding::HalfAwayFromZero.round(&s(" +.5")), Some(int(1)));
    assert_eq!(Rounding::Floor.round(&s("-0.5")), Some(int(-1)));
    assert_eq!(Rounding::Ceil.round(&s("9007199254740993.5")), Some(int(9007199254740994)));
    assert_eq!(Rounding::HalfAwayFromZero.round(&s("1.")), Some(int(1)));
    assert_eq!(Rounding::HalfAwayFromZero.round(&Value::Float(-0.5)), Some(int(-1)));
    assert_eq!(Rounding::HalfAwayFromZero.round(&Value::Float(1.5)), Some(int(2)));
    assert_eq!(Rounding::HalfAwayFromZero.round(&Value::Float(-2.5)), Some(int(-3)));
    assert_eq!(Rounding::Floor.round(&Value::Float(101.9)), Some(int(101)));
    assert_eq!(Rounding::Ceil.round(&Value::Float(99.1)), Some(int(100)));
    assert_eq!(Rounding::HalfAwayFromZero.round(&s("9223372036854775807.5")), Some(big("9223372036854775808")));
    assert_eq!(Rounding::Floor.round(&s("5")), None);
    assert_eq!(Rounding::Floor.round(&s("1.5e2")), None);
    assert_eq!(Rounding::Floor.round(&Value::Float(f64::INFINITY)), None);
    assert_eq!(Rounding::Floor.round(&Value::Int(5)), None);
}
