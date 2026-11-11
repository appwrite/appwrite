//! PHP: `tests/unit/Validator/DateTimeTest.php` (utopia-php/database
//! 8.1.3).

use php_std::datetime::{DateTime, TzInfo};
use utopia_database::datetime;
use utopia_database::validator::datetime::{Datetime, Precision};
use utopia_database::value::{Array, Value};

const MIN: &str = "0000-01-01 00:00:00";
const MAX: &str = "9999-12-31 23:59:59";

/// `new \DateTime($text)`.
fn date(text: &str) -> DateTime {
    DateTime::new(text.as_bytes(), None, &TzInfo::utc()).unwrap()
}

fn validator(require_date_in_future: bool, precision: Precision, offset: i64) -> Datetime {
    Datetime::new(date(MIN), date(MAX), require_date_in_future, precision, offset).unwrap()
}

fn plain() -> Datetime {
    Datetime::between(date(MIN), date(MAX))
}

fn s(text: &str) -> Value {
    Value::from(text)
}

/// `DateTime::addSeconds(new \DateTime(), $seconds)`.
fn from_now(seconds: i64) -> Value {
    s(&datetime::add_seconds(&mut date("now"), seconds).unwrap())
}

/// `DateTime::format(new \DateTime($text))`.
fn formatted(text: &str) -> Value {
    s(&datetime::format(&date(text)))
}

#[test]
fn create_datetime() {
    let validator = plain();

    assert!(validator.is_valid(&s("2022-12-04")));
    assert!(validator.is_valid(&s("2022-1-4 11:31")));
    assert!(validator.is_valid(&s("2022-12-04 11:31:52")));
    assert!(validator.is_valid(&s("2022-1-4 11:31:52.123456789")));
    let now = datetime::now();
    assert_eq!(now.len(), 23);
    assert!(now.as_str() > "2020-1-1 11:31:52.680");
    assert_eq!(validator.description(), format!("Value must be valid date between {MIN} and {MAX}."));

    let text = "2022-07-02 18:31:52.680";
    let object = date(text);
    assert_eq!(datetime::format(&object), text);
    for (format, expected) in
        [("Y", "2022"), ("m", "07"), ("d", "02"), ("H", "18"), ("i", "31"), ("s", "52"), ("v", "680")]
    {
        assert_eq!(object.format(format.as_bytes()), expected.as_bytes());
    }

    assert!(validator.is_valid(&s("2022-12-04 11:31:52.680+02:00")));
    assert_eq!(datetime::set_timezone("2022-12-04 11:31:52.680+02:00").unwrap(), "2022-12-04 09:31:52.680");
    assert_eq!(datetime::format_tz(Some("2022-12-04 09:31:52.681")).unwrap(), "2022-12-04T09:31:52.681+00:00");

    assert!(!validator.is_valid(&s("2022-13-04 11:31:52.680")));
    assert!(!validator.is_valid(&s("-0001-13-04 00:00:00")));
    assert!(!validator.is_valid(&s("0000-00-00 00:00:00")));
    assert!(!validator.is_valid(&s("10000-01-01 00:00:00")));
}

#[test]
fn past_date_validation() {
    let validator = validator(true, Precision::Any, 0);
    assert!(!validator.is_valid(&from_now(-3)));
    assert!(validator.is_valid(&from_now(5)));
    assert_eq!(validator.description(), format!("Value must be valid date in the future and between {MIN} and {MAX}."));

    let validator = validator_false();
    assert!(validator.is_valid(&from_now(-3)));
    assert!(validator.is_valid(&from_now(5)));
    assert_eq!(validator.description(), format!("Value must be valid date between {MIN} and {MAX}."));
}

fn validator_false() -> Datetime {
    validator(false, Precision::Any, 0)
}

#[test]
fn date_precision() {
    let check = |precision: Precision, cases: &[(&str, bool)]| {
        let name = precision.as_str().to_owned();
        let validator = validator(false, precision, 0);
        for (text, valid) in cases {
            assert_eq!(validator.is_valid(&formatted(text)), *valid, "{name} {text}");
        }
        assert_eq!(
            validator.description(),
            format!("Value must be valid date with {name} precision between {MIN} and {MAX}.")
        );
    };

    check(
        Precision::Seconds,
        &[("2019-04-08 19:36:26.255", false), ("2019-04-08 19:36:26.000", true), ("2019-04-08 19:36:26", true)],
    );
    check(
        Precision::Minutes,
        &[
            ("2019-04-08 19:36:26.255", false),
            ("2019-04-08 19:36:26.000", false),
            ("2019-04-08 19:36:26", false),
            ("2019-04-08 19:36:00", true),
        ],
    );
    check(
        Precision::Hours,
        &[
            ("2019-04-08 19:36:26.255", false),
            ("2019-04-08 19:36:26.000", false),
            ("2019-04-08 19:36:26", false),
            ("2019-04-08 19:36:00", false),
            ("2019-04-08 19:00:00", true),
        ],
    );
    check(
        Precision::Days,
        &[
            ("2019-04-08 19:36:26.255", false),
            ("2019-04-08 19:36:26.000", false),
            ("2019-04-08 19:36:26", false),
            ("2019-04-08 19:36:00", false),
            ("2019-04-08 19:00:00", false),
            ("2019-04-08 00:00:00", true),
        ],
    );
    check(
        Precision::Minutes,
        &[("2019-04-08 19:36:26.255", false), ("2100-04-08 19:36:26.255", false), ("2100-04-08 19:36:00", true)],
    );
}

#[test]
fn offset() {
    let validator = validator(false, Precision::Any, 60);
    let mut time = date("now");
    assert!(!validator.is_valid(&s(&datetime::format(&time))));
    assert!(!validator.is_valid(&s(&datetime::add_seconds(&mut time, 50).unwrap())));
    assert!(validator.is_valid(&s(&datetime::add_seconds(&mut time, 20).unwrap())));
    assert_eq!(
        validator.description(),
        format!("Value must be valid date at least 60 seconds in the future and between {MIN} and {MAX}.")
    );

    let validator = validator_with_future_offset();
    let mut time = date("now");
    datetime::add_seconds(&mut time, 50).unwrap();
    assert!(validator.is_valid(&s(&datetime::add_seconds(&mut time, 20).unwrap())));
    assert_eq!(
        validator.description(),
        format!("Value must be valid date at least 60 seconds in the future and between {MIN} and {MAX}.")
    );

    let error = Datetime::new(date(MIN), date(MAX), false, Precision::Any, -60).unwrap_err();
    assert_eq!(error.message(), "Offset must be a positive integer.");
}

fn validator_with_future_offset() -> Datetime {
    validator(true, Precision::Any, 60)
}

#[test]
fn empty_and_non_string_values() {
    let validator = plain();
    assert!(!validator.is_valid(&s("")));
    assert!(!validator.is_valid(&Value::Int(12345)));
    assert!(!validator.is_valid(&Value::Null));
    assert!(!validator.is_valid(&Value::Array(Array::new())));
    assert!(!validator.is_valid(&Value::Bool(false)));
}

#[test]
fn year_outside_min_max_range() {
    let validator = Datetime::between(date("2000-01-01"), date("2050-12-31"));
    assert!(!validator.is_valid(&s("1999-06-15 12:00:00")));
    assert!(!validator.is_valid(&s("2051-01-01 00:00:00")));
    assert!(validator.is_valid(&s("2025-06-15 12:00:00")));
}

#[test]
fn value_without_four_digit_year() {
    let validator = plain();
    assert!(!validator.is_valid(&s("noon")));
    assert!(!validator.is_valid(&s("tomorrow")));
    assert!(!validator.is_valid(&s("next Monday")));
}

#[test]
fn is_array_and_type() {
    let validator = plain();
    assert!(!utopia_validators::Validator::is_array(&validator));
    assert_eq!(validator.type_name(), "string");
}
