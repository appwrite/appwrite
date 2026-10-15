//! Ports of `tests/unit/Operator/OperatorValidationTest.php` and
//! `tests/unit/Operator/OperatorLimitTest.php` (utopia-php/database 8.1.3).

use utopia_database::database::{MAX_INT, METADATA, MIN_INT};
use utopia_database::operator::{Operator, OperatorType};
use utopia_database::validator::operator::Operator as OperatorValidator;
use utopia_database::value::{Array, Value};
use utopia_database::{Attribute, Document, Kind};
use utopia_query::schema::ColumnType;

use OperatorType::*;

macro_rules! vals {
    ($($v:expr),* $(,)?) => { vec![$(Value::from($v)),*] };
}

fn list(items: Vec<Value>) -> Value {
    Value::Array(Array::from_list(items))
}

fn doc(pairs: Vec<(&str, Value)>) -> Document {
    Document::from_pairs(pairs).unwrap()
}

fn make_collection(attributes: &[Attribute]) -> Document {
    let documents = attributes.iter().map(|a| Value::from(a.to_document().unwrap())).collect();
    doc(vec![
        ("$id", Value::from("test_collection")),
        ("$collection", Value::from(METADATA)),
        ("name", Value::from("test_collection")),
        ("attributes", list(documents)),
        ("indexes", list(vec![])),
    ])
}

fn make_validator(attributes: &[Attribute], current: Option<Document>) -> OperatorValidator {
    OperatorValidator::from_document(&make_collection(attributes), current, true).unwrap()
}

fn op(method: OperatorType, attribute: &str, values: Vec<Value>) -> Operator {
    Operator::new(method, attribute, values)
}

fn valid(validator: &OperatorValidator, operator: Operator) -> bool {
    validator.is_valid(&Value::from(operator)).unwrap()
}

#[track_caller]
fn assert_invalid(validator: &OperatorValidator, operator: Operator, contains: &str) {
    assert!(!valid(validator, operator));
    let description = validator.description();
    assert!(description.contains(contains), "{description:?} does not contain {contains:?}");
}

fn current(key: &str, value: impl Into<Value>) -> Option<Document> {
    Some(doc(vec![(key, value.into())]))
}

fn string(key: &str) -> Attribute {
    Attribute::string(key, 255)
}

fn strings(key: &str) -> Attribute {
    Attribute::string(key, 50).array(true)
}

fn integers(key: &str) -> Attribute {
    Attribute::integer(key).array(true)
}

// OperatorValidationTest

#[test]
fn test_increment_on_integer() {
    assert!(valid(&make_validator(&[Attribute::integer("count")], None), op(Increment, "count", vals![3])));
}

#[test]
fn test_increment_exceeds_max() {
    let v = make_validator(&[Attribute::integer("count")], current("count", MAX_INT - 5));
    assert_invalid(&v, op(Increment, "count", vals![10]), "would overflow");
}

#[test]
fn test_decrement_on_integer() {
    assert!(valid(&make_validator(&[Attribute::integer("count")], None), op(Decrement, "count", vals![3])));
}

#[test]
fn test_decrement_below_min() {
    let v = make_validator(&[Attribute::integer("count")], current("count", MIN_INT + 5));
    assert_invalid(&v, op(Decrement, "count", vals![10]), "would underflow");
}

#[test]
fn test_multiply_on_integer() {
    assert!(valid(&make_validator(&[Attribute::integer("value")], None), op(Multiply, "value", vals![3])));
}

#[test]
fn test_multiply_on_float() {
    assert!(valid(&make_validator(&[Attribute::double("score")], None), op(Multiply, "score", vals![2.5])));
}

#[test]
fn test_multiply_violates_range() {
    let v = make_validator(&[Attribute::integer("value")], current("value", MAX_INT));
    assert_invalid(&v, op(Multiply, "value", vals![2]), "would overflow");
}

#[test]
fn test_multiply_negative() {
    let v = make_validator(&[Attribute::integer("value")], current("value", MAX_INT));
    assert_invalid(&v, op(Multiply, "value", vals![-2]), "would underflow");
}

#[test]
fn test_divide_on_integer() {
    assert!(valid(&make_validator(&[Attribute::integer("value")], None), op(Divide, "value", vals![2])));
}

#[test]
fn test_divide_on_float() {
    assert!(valid(&make_validator(&[Attribute::double("score")], None), op(Divide, "score", vals![3.0])));
}

#[test]
fn test_divide_by_zero() {
    let e = Operator::divide(0, None).unwrap_err();
    assert_eq!(e.kind(), Kind::Operator);
    assert_eq!(e.message(), "Division by zero is not allowed");
}

#[test]
fn test_divide_by_zero_validator() {
    let v = make_validator(&[Attribute::integer("value")], None);
    assert_invalid(&v, op(Divide, "value", vals![0]), "division");
}

#[test]
fn test_modulo_on_integer() {
    assert!(valid(&make_validator(&[Attribute::integer("value")], None), op(Modulo, "value", vals![3])));
}

#[test]
fn test_modulo_by_zero() {
    let e = Operator::modulo(0).unwrap_err();
    assert_eq!(e.kind(), Kind::Operator);
    assert_eq!(e.message(), "Modulo by zero is not allowed");
}

#[test]
fn test_modulo_by_zero_validator() {
    let v = make_validator(&[Attribute::integer("value")], None);
    assert_invalid(&v, op(Modulo, "value", vals![0]), "modulo");
}

#[test]
fn test_modulo_negative() {
    assert!(valid(&make_validator(&[Attribute::integer("value")], None), op(Modulo, "value", vals![-3])));
}

#[test]
fn test_power_on_integer() {
    assert!(valid(&make_validator(&[Attribute::integer("value")], None), op(Power, "value", vals![3])));
}

#[test]
fn test_power_fractional() {
    assert!(valid(&make_validator(&[Attribute::double("value")], None), op(Power, "value", vals![0.5])));
}

#[test]
fn test_power_negative_exponent() {
    assert!(valid(&make_validator(&[Attribute::double("value")], None), op(Power, "value", vals![-2])));
}

#[test]
fn test_power_overflow() {
    let v = make_validator(&[Attribute::integer("value")], current("value", 100));
    assert_invalid(&v, op(Power, "value", vals![10]), "would overflow");
}

#[test]
fn test_string_concat() {
    assert!(valid(&make_validator(&[string("title")], None), op(StringConcat, "title", vals![" World"])));
}

#[test]
fn test_string_concat_exceeds_max_length() {
    let v = make_validator(&[Attribute::string("title", 100)], current("title", "a".repeat(95).as_str()));
    assert_invalid(&v, op(StringConcat, "title", vals!["b".repeat(10).as_str()]), "exceed maximum length");
}

#[test]
fn test_string_concat_within_max_length() {
    let v = make_validator(&[Attribute::string("title", 100)], current("title", "a".repeat(90).as_str()));
    assert!(valid(&v, op(StringConcat, "title", vals!["b".repeat(10).as_str()])));
}

#[test]
fn test_string_concat_requires_string_value() {
    let v = make_validator(&[string("title")], None);
    assert_invalid(&v, op(StringConcat, "title", vec![]), "requires a string value");
}

#[test]
fn test_string_concat_non_string_value() {
    let v = make_validator(&[string("title")], None);
    assert_invalid(&v, op(StringConcat, "title", vals![123]), "requires a string value");
}

#[test]
fn test_string_replace() {
    assert!(valid(&make_validator(&[string("text")], None), op(StringReplace, "text", vals!["old", "new"])));
}

#[test]
fn test_string_replace_multiple_occurrences() {
    assert!(valid(&make_validator(&[string("text")], None), op(StringReplace, "text", vals!["test", "demo"])));
}

#[test]
fn test_string_replace_validation() {
    let v = make_validator(&[string("text")], None);
    assert_invalid(&v, op(StringReplace, "text", vals!["only_search"]), "requires exactly 2 string values");
}

#[test]
fn test_string_replace_with_non_string_values() {
    let v = make_validator(&[string("text")], None);
    assert_invalid(&v, op(StringReplace, "text", vals![123, 456]), "requires exactly 2 string values");
}

#[test]
fn test_string_replace_on_non_string_field() {
    let v = make_validator(&[Attribute::integer("number")], None);
    assert_invalid(&v, op(StringReplace, "number", vals!["old", "new"]), "non-string field");
}

#[test]
fn test_toggle_boolean() {
    assert!(valid(&make_validator(&[Attribute::boolean("active")], None), op(Toggle, "active", vec![])));
}

#[test]
fn test_toggle_from_default() {
    let v = make_validator(&[Attribute::boolean("active").default(false)], None);
    assert!(valid(&v, op(Toggle, "active", vec![])));
}

#[test]
fn test_toggle_on_non_boolean() {
    let v = make_validator(&[Attribute::integer("count")], None);
    assert_invalid(&v, op(Toggle, "count", vec![]), "non-boolean field");
}

#[test]
fn test_toggle_on_string_field() {
    let v = make_validator(&[string("name")], None);
    assert_invalid(&v, op(Toggle, "name", vec![]), "non-boolean field");
}

#[test]
fn test_date_add_days() {
    assert!(valid(&make_validator(&[Attribute::datetime("date")], None), op(DateAddDays, "date", vals![5])));
}

#[test]
fn test_date_sub_days() {
    assert!(valid(&make_validator(&[Attribute::datetime("date")], None), op(DateSubDays, "date", vals![3])));
}

#[test]
fn test_date_set_now() {
    let v = make_validator(&[Attribute::datetime("timestamp")], None);
    assert!(valid(&v, op(DateSetNow, "timestamp", vec![])));
}

#[test]
fn test_date_at_year_boundaries() {
    let v = make_validator(&[Attribute::datetime("date")], None);
    assert!(valid(&v, op(DateAddDays, "date", vals![365])));
    assert!(valid(&v, op(DateSubDays, "date", vals![365])));
    assert!(valid(&v, op(DateAddDays, "date", vals![-365])));
}

#[test]
fn test_date_add_days_on_non_date_field() {
    let v = make_validator(&[string("name")], None);
    assert_invalid(&v, op(DateAddDays, "name", vals![5]), "non-datetime field");
}

#[test]
fn test_date_add_days_requires_int_value() {
    let v = make_validator(&[Attribute::datetime("date")], None);
    assert_invalid(&v, op(DateAddDays, "date", vec![]), "requires an integer number of days");
}

#[test]
fn test_date_add_days_non_integer_value() {
    let v = make_validator(&[Attribute::datetime("date")], None);
    assert_invalid(&v, op(DateAddDays, "date", vals![3.5]), "requires an integer number of days");
}

#[test]
fn test_date_set_now_on_non_date_field() {
    let v = make_validator(&[string("name")], None);
    assert_invalid(&v, op(DateSetNow, "name", vec![]), "non-datetime field");
}

#[test]
fn test_array_append() {
    assert!(valid(&make_validator(&[strings("tags")], None), op(ArrayAppend, "tags", vals!["new", "items"])));
}

#[test]
fn test_array_append_violates_constraints() {
    let v = make_validator(&[string("name")], None);
    assert_invalid(&v, op(ArrayAppend, "name", vals!["item"]), "non-array field");
}

#[test]
fn test_array_append_integer_bounds() {
    let v = make_validator(&[integers("numbers")], None);
    assert_invalid(&v, op(ArrayAppend, "numbers", vals![MAX_INT + 1]), "array items must be between");
}

#[test]
fn test_array_prepend() {
    assert!(valid(&make_validator(&[strings("tags")], None), op(ArrayPrepend, "tags", vals!["first", "second"])));
}

#[test]
fn test_array_prepend_on_non_array() {
    let v = make_validator(&[string("name")], None);
    assert_invalid(&v, op(ArrayPrepend, "name", vals!["item"]), "non-array field");
}

#[test]
fn test_array_insert() {
    let v = make_validator(&[integers("numbers")], current("numbers", list(vals![1, 2, 3])));
    assert!(valid(&v, op(ArrayInsert, "numbers", vals![1, 99])));
}

#[test]
fn test_array_insert_at_boundaries() {
    let v = make_validator(&[integers("numbers")], current("numbers", list(vals![1, 2, 3])));
    assert!(valid(&v, op(ArrayInsert, "numbers", vals![0, 0])));
    assert!(valid(&v, op(ArrayInsert, "numbers", vals![3, 4])));
}

#[test]
fn test_array_insert_out_of_bounds() {
    let v = make_validator(&[strings("items")], current("items", list(vals!["a", "b", "c"])));
    assert_invalid(&v, op(ArrayInsert, "items", vals![10, "new"]), "index 10 is out of bounds for array of length 3");
}

#[test]
fn test_array_insert_negative_index() {
    let v = make_validator(&[strings("items")], None);
    assert_invalid(&v, op(ArrayInsert, "items", vals![-1, "new"]), "index must be a non-negative integer");
}

#[test]
fn test_array_insert_missing_values() {
    let v = make_validator(&[strings("items")], None);
    assert_invalid(&v, op(ArrayInsert, "items", vals![0]), "requires exactly 2 values");
}

#[test]
fn test_array_insert_on_non_array() {
    let v = make_validator(&[string("name")], None);
    assert_invalid(&v, op(ArrayInsert, "name", vals![0, "val"]), "non-array field");
}

#[test]
fn test_array_remove() {
    assert!(valid(&make_validator(&[strings("tags")], None), op(ArrayRemove, "tags", vals!["unwanted"])));
}

#[test]
fn test_array_remove_on_non_array() {
    let v = make_validator(&[string("name")], None);
    assert_invalid(&v, op(ArrayRemove, "name", vals!["val"]), "non-array field");
}

#[test]
fn test_array_remove_empty_values() {
    let v = make_validator(&[strings("tags")], None);
    assert_invalid(&v, op(ArrayRemove, "tags", vec![]), "requires a value to remove");
}

#[test]
fn test_array_filter() {
    let v = make_validator(&[integers("numbers")], None);
    assert!(valid(&v, op(ArrayFilter, "numbers", vals!["greaterThan", 5])));
}

#[test]
fn test_array_filter_numeric() {
    let v = make_validator(&[integers("numbers")], None);
    assert!(valid(&v, op(ArrayFilter, "numbers", vals!["greaterThan", 10])));
    assert!(valid(&v, op(ArrayFilter, "numbers", vals!["lessThan", 3])));
    assert!(valid(&v, op(ArrayFilter, "numbers", vals!["greaterThanEqual", 5])));
    assert!(valid(&v, op(ArrayFilter, "numbers", vals!["lessThanEqual", 5])));
}

#[test]
fn test_array_filter_validation() {
    let v = make_validator(&[integers("numbers")], None);
    assert_invalid(&v, op(ArrayFilter, "numbers", vals!["invalidCondition", 5]), "Invalid array filter condition");
}

#[test]
fn test_array_filter_on_non_array() {
    let v = make_validator(&[string("name")], None);
    assert_invalid(&v, op(ArrayFilter, "name", vals!["equal", "test"]), "non-array field");
}

#[test]
fn test_array_filter_empty_values() {
    let v = make_validator(&[integers("numbers")], None);
    assert_invalid(&v, op(ArrayFilter, "numbers", vec![]), "requires 1 or 2 values");
}

#[test]
fn test_array_filter_too_many_values() {
    let v = make_validator(&[integers("numbers")], None);
    assert_invalid(&v, op(ArrayFilter, "numbers", vals!["greaterThan", 5, "extra"]), "requires 1 or 2 values");
}

#[test]
fn test_array_filter_condition_not_string() {
    let v = make_validator(&[integers("numbers")], None);
    assert_invalid(&v, op(ArrayFilter, "numbers", vals![123, 5]), "condition must be a string");
}

#[test]
fn test_array_filter_null_conditions() {
    let v = make_validator(&[strings("tags")], None);
    assert!(valid(&v, op(ArrayFilter, "tags", vals!["isNull"])));
    assert!(valid(&v, op(ArrayFilter, "tags", vals!["isNotNull"])));
}

#[test]
fn test_array_diff() {
    assert!(valid(&make_validator(&[strings("tags")], None), op(ArrayDiff, "tags", vals!["remove_me", "and_me"])));
}

#[test]
fn test_array_diff_on_non_array() {
    let v = make_validator(&[string("name")], None);
    assert_invalid(&v, op(ArrayDiff, "name", vals!["val"]), "non-array attribute");
}

#[test]
fn test_array_intersect() {
    assert!(valid(&make_validator(&[strings("items")], None), op(ArrayIntersect, "items", vals!["a", "b", "c"])));
}

#[test]
fn test_array_intersect_empty() {
    let v = make_validator(&[strings("items")], None);
    assert_invalid(&v, op(ArrayIntersect, "items", vec![]), "requires a non-empty array value");
}

#[test]
fn test_array_intersect_on_non_array() {
    let v = make_validator(&[string("name")], None);
    assert_invalid(&v, op(ArrayIntersect, "name", vals!["val"]), "non-array attribute");
}

#[test]
fn test_array_unique() {
    assert!(valid(&make_validator(&[strings("items")], None), op(ArrayUnique, "items", vec![])));
}

#[test]
fn test_array_unique_on_non_array() {
    let v = make_validator(&[string("name")], None);
    assert_invalid(&v, op(ArrayUnique, "name", vec![]), "non-array field");
}

#[test]
fn test_array_operations_on_empty() {
    let v = make_validator(&[strings("items")], current("items", list(vec![])));
    assert!(valid(&v, op(ArrayAppend, "items", vals!["first"])));
    assert!(valid(&v, op(ArrayPrepend, "items", vals!["first"])));
    assert!(valid(&v, op(ArrayInsert, "items", vals![0, "first"])));
    assert!(!valid(&v, op(ArrayInsert, "items", vals![1, "second"])));
}

#[test]
fn test_array_with_single_element() {
    let v = make_validator(&[strings("items")], current("items", list(vals!["only"])));
    assert!(valid(&v, op(ArrayInsert, "items", vals![0, "before"])));
    assert!(valid(&v, op(ArrayInsert, "items", vals![1, "after"])));
    assert!(!valid(&v, op(ArrayInsert, "items", vals![2, "oob"])));
}

#[test]
fn test_array_with_null() {
    let v = make_validator(&[strings("items")], current("items", Value::Null));
    assert!(valid(&v, op(ArrayAppend, "items", vals!["first"])));
}

#[test]
fn test_increment_on_float() {
    assert!(valid(&make_validator(&[Attribute::double("score")], None), op(Increment, "score", vals![1.5])));
}

#[test]
fn test_increment_with_precise_floats() {
    let v = make_validator(&[Attribute::double("score")], None);
    assert!(valid(&v, op(Increment, "score", vals![0.1])));
    assert!(valid(&v, op(Increment, "score", vals![f64::EPSILON])));
}

#[test]
fn test_float_precision_loss() {
    let v = make_validator(&[Attribute::double("score")], None);
    assert!(valid(&v, op(Increment, "score", vals![0.000000001])));
    assert!(valid(&v, op(Multiply, "score", vals![1.0000000001])));
}

#[test]
fn test_sequential_operators() {
    let v = make_validator(&[Attribute::integer("count"), Attribute::double("score"), string("name")], None);
    assert!(valid(&v, op(Increment, "count", vals![1])));
    assert!(valid(&v, op(Multiply, "score", vals![2.0])));
    assert!(valid(&v, op(StringConcat, "name", vals![" suffix"])));
}

#[test]
fn test_complex_scenarios() {
    let current = doc(vec![
        ("count", Value::Int(50)),
        ("tags", list(vals!["a", "b", "c"])),
        ("name", Value::from("Hello")),
        ("active", Value::Bool(false)),
        ("date", Value::from("2023-01-01 00:00:00")),
    ]);
    let v = make_validator(
        &[
            Attribute::integer("count"),
            strings("tags"),
            string("name"),
            Attribute::boolean("active"),
            Attribute::datetime("date"),
        ],
        Some(current),
    );
    assert!(valid(&v, op(Increment, "count", vals![10])));
    assert!(valid(&v, op(ArrayAppend, "tags", vals!["new"])));
    assert!(valid(&v, op(StringConcat, "name", vals![" World"])));
    assert!(valid(&v, op(Toggle, "active", vec![])));
    assert!(valid(&v, op(DateAddDays, "date", vals![7])));
}

#[test]
fn test_error_handling() {
    let v = make_validator(&[Attribute::integer("count")], None);
    assert_invalid(&v, op(Increment, "nonexistent", vals![1]), "does not exist");
}

#[test]
fn test_null_value_handling() {
    let current = doc(vec![("count", Value::Null), ("name", Value::Null)]);
    let v = make_validator(&[Attribute::integer("count"), Attribute::string("name", 100)], Some(current));
    assert!(valid(&v, op(Increment, "count", vals![5])));
    assert!(valid(&v, op(StringConcat, "name", vals!["hello"])));
}

#[test]
fn test_value_limits() {
    let v = make_validator(&[Attribute::integer("counter")], None);
    assert!(valid(&v, op(Increment, "counter", vals![5, 50])));
    assert!(valid(&v, op(Decrement, "counter", vals![5, 0])));
    assert!(valid(&v, op(Multiply, "counter", vals![2, 100])));
    assert!(valid(&v, op(Power, "counter", vals![3, 1000])));
}

#[test]
fn test_value_limits_non_numeric() {
    let v = make_validator(&[Attribute::integer("counter")], None);
    assert_invalid(&v, op(Increment, "counter", vals![5, "not_a_number"]), "max/min limit must be numeric");
}

#[test]
fn test_attribute_constraints() {
    let v = make_validator(&[Attribute::double("score"), strings("tags")], None);
    assert!(!valid(&v, op(Increment, "tags", vals![1])));
    assert!(!valid(&v, op(ArrayAppend, "score", vals!["val"])));
}

#[test]
fn test_empty_strings() {
    let v = make_validator(&[string("text")], None);
    assert!(valid(&v, op(StringConcat, "text", vals![""])));
    assert!(valid(&v, op(StringReplace, "text", vals!["old", ""])));
}

#[test]
fn test_extreme_integer_values() {
    let v = make_validator(&[Attribute::integer("value")], current("value", 0));
    assert!(valid(&v, op(Increment, "value", vals![MAX_INT])));
    let v2 = make_validator(&[Attribute::integer("value")], current("value", 1));
    assert!(!valid(&v2, op(Increment, "value", vals![MAX_INT])));
}

#[test]
fn test_unicode_characters() {
    let v = make_validator(&[string("text")], None);
    assert!(valid(&v, op(StringConcat, "text", vals![" mundo"])));
    assert!(valid(&v, op(StringReplace, "text", vals!["hello", "hola"])));
}

#[test]
fn test_very_long_strings() {
    let v = make_validator(&[Attribute::string("text", 100)], current("text", ""));
    assert!(valid(&v, op(StringConcat, "text", vals!["x".repeat(100).as_str()])));
    assert_invalid(&v, op(StringConcat, "text", vals!["x".repeat(101).as_str()]), "exceed maximum length");
}

#[test]
fn test_zero_values() {
    let v = make_validator(&[Attribute::integer("count"), Attribute::double("score")], None);
    assert!(valid(&v, op(Increment, "count", vals![0])));
    assert!(valid(&v, op(Decrement, "count", vals![0])));
    assert!(valid(&v, op(Multiply, "score", vals![0])));
}

#[test]
fn test_batch_operators() {
    let v = make_validator(
        &[
            Attribute::integer("count"),
            Attribute::double("score"),
            strings("tags"),
            string("title"),
            Attribute::boolean("active"),
            Attribute::datetime("date"),
        ],
        None,
    );
    for operator in [
        op(Increment, "count", vals![5]),
        op(Multiply, "score", vals![2.0]),
        op(ArrayAppend, "tags", vals!["new"]),
        op(StringConcat, "title", vals![" Updated"]),
        op(Toggle, "active", vec![]),
        op(DateSetNow, "date", vec![]),
    ] {
        let label = format!("{} on {}", operator.method(), operator.attribute());
        assert!(valid(&v, operator), "Failed for operator: {label}");
    }
}

#[test]
fn test_increment_on_text_attribute() {
    let v = make_validator(&[Attribute::string("text_field", 100)], None);
    assert_invalid(&v, op(Increment, "text_field", vals![1]), "non-numeric field 'text_field'");
}

#[test]
fn test_increment_on_array_attribute() {
    let v = make_validator(&[strings("tags")], None);
    assert_invalid(&v, op(Increment, "tags", vals![1]), "non-numeric field");
}

#[test]
fn test_increment_on_boolean_attribute() {
    let v = make_validator(&[Attribute::boolean("active")], None);
    assert_invalid(&v, op(Increment, "active", vals![1]), "non-numeric field");
}

#[test]
fn test_numeric_operator_non_numeric_value() {
    let v = make_validator(&[Attribute::integer("count")], None);
    assert_invalid(&v, op(Increment, "count", vals!["not_a_number"]), "value must be numeric");
}

#[test]
fn test_numeric_operator_empty_values() {
    let v = make_validator(&[Attribute::integer("count")], None);
    assert_invalid(&v, op(Increment, "count", vec![]), "value must be numeric");
}

#[test]
fn test_string_concat_on_non_string_field() {
    let v = make_validator(&[Attribute::integer("count")], None);
    assert_invalid(&v, op(StringConcat, "count", vals![" suffix"]), "non-string field");
}

#[test]
fn test_string_concat_on_array_field() {
    let v = make_validator(&[strings("tags")], None);
    assert_invalid(&v, op(StringConcat, "tags", vals![" suffix"]), "non-string field");
}

#[test]
fn test_array_insert_integer_bounds() {
    let v = make_validator(&[integers("numbers")], None);
    assert_invalid(&v, op(ArrayInsert, "numbers", vals![0, MAX_INT + 1]), "array items must be between");
}

#[test]
fn test_decrement_underflow() {
    let v = make_validator(&[Attribute::integer("count")], current("count", MIN_INT + 2));
    assert_invalid(&v, op(Decrement, "count", vals![5]), "would underflow");
}

#[test]
fn test_modulo_on_float() {
    assert!(valid(&make_validator(&[Attribute::double("score")], None), op(Modulo, "score", vals![3.5])));
}

#[test]
fn test_power_with_max_limit() {
    assert!(valid(&make_validator(&[Attribute::integer("value")], None), op(Power, "value", vals![2, 1000])));
}

#[test]
fn test_divide_with_min_limit() {
    assert!(valid(&make_validator(&[Attribute::double("value")], None), op(Divide, "value", vals![2.0, 1.0])));
}

#[test]
fn test_increment_with_max_cap() {
    assert!(valid(&make_validator(&[Attribute::integer("counter")], None), op(Increment, "counter", vals![100, 50])));
}

#[test]
fn test_decrement_with_min_cap() {
    assert!(valid(&make_validator(&[Attribute::integer("counter")], None), op(Decrement, "counter", vals![100, 0])));
}

#[test]
fn test_operator_on_nonexistent_attribute() {
    let v = make_validator(&[Attribute::integer("count")], None);
    assert_invalid(&v, op(Increment, "nonexistent", vals![1]), "'nonexistent' does not exist");
}

#[test]
fn test_all_numeric_operators_on_string() {
    let v = make_validator(&[string("name")], None);
    for method in [Increment, Decrement, Multiply, Divide, Modulo, Power] {
        assert_invalid(&v, op(method, "name", vals![1]), "non-numeric field");
    }
}

#[test]
fn test_all_array_operators_on_non_array() {
    let v = make_validator(&[string("name")], None);
    assert!(!valid(&v, op(ArrayAppend, "name", vals!["val"])));
    assert!(!valid(&v, op(ArrayPrepend, "name", vals!["val"])));
    assert!(!valid(&v, op(ArrayInsert, "name", vals![0, "val"])));
    assert!(!valid(&v, op(ArrayRemove, "name", vals!["val"])));
    assert!(!valid(&v, op(ArrayUnique, "name", vec![])));
    assert!(!valid(&v, op(ArrayDiff, "name", vals!["val"])));
    assert!(!valid(&v, op(ArrayIntersect, "name", vals!["val"])));
    assert!(!valid(&v, op(ArrayFilter, "name", vals!["equal", "val"])));
}

#[test]
fn test_date_operators_on_non_date_fields() {
    let v = make_validator(&[Attribute::integer("count"), string("name"), Attribute::boolean("active")], None);
    for field in ["count", "name", "active"] {
        assert_invalid(&v, op(DateAddDays, field, vals![5]), "non-datetime field");
        assert_invalid(&v, op(DateSubDays, field, vals![5]), "non-datetime field");
        assert_invalid(&v, op(DateSetNow, field, vec![]), "non-datetime field");
    }
}

#[test]
fn test_extract_operators_and_validate() {
    let data: Array = [
        ("count", Value::from(Operator::increment(5, None))),
        ("tags", Value::from(Operator::array_append(["new"]))),
        ("name", Value::from("Regular value")),
    ]
    .into_iter()
    .map(|(k, v)| (k.into(), v))
    .collect();
    let extracted = Operator::extract_operators(data);
    assert_eq!(extracted.operators.len(), 2);
    assert_eq!(extracted.updates.len(), 1);
    let v = make_validator(&[Attribute::integer("count"), strings("tags"), string("name")], None);
    for (_, operator) in extracted.operators {
        assert!(valid(&v, operator));
    }
}

#[test]
fn test_operator_type_classification_methods() {
    for method in [Increment, Decrement, Multiply, Divide, Modulo, Power] {
        assert!(method.is_numeric());
    }
    for method in
        [ArrayAppend, ArrayPrepend, ArrayInsert, ArrayRemove, ArrayUnique, ArrayIntersect, ArrayDiff, ArrayFilter]
    {
        assert!(method.is_array());
    }
    assert!(StringConcat.is_string());
    assert!(StringReplace.is_string());
    assert!(Toggle.is_boolean());
    assert!(DateAddDays.is_date());
    assert!(DateSubDays.is_date());
    assert!(DateSetNow.is_date());
    assert!(!Increment.is_array());
    assert!(!ArrayAppend.is_numeric());
    assert!(!StringConcat.is_numeric());
    assert!(!Toggle.is_numeric());
    assert!(!DateAddDays.is_numeric());
}

#[test]
fn test_operator_helper_methods() {
    let values = |o: &Operator| o.values().values().cloned().collect::<Vec<_>>();
    let inc = Operator::increment(5, Some(100.into()));
    assert_eq!(inc.method(), Increment);
    assert_eq!(values(&inc), vals![5, 100]);
    let dec = Operator::decrement(3, Some(0.into()));
    assert_eq!(dec.method(), Decrement);
    assert_eq!(values(&dec), vals![3, 0]);
    let mul = Operator::multiply(2, Some(50.into()));
    assert_eq!(mul.method(), Multiply);
    assert_eq!(values(&mul), vals![2, 50]);
    let div = Operator::divide(4, Some(1.into())).unwrap();
    assert_eq!(div.method(), Divide);
    assert_eq!(values(&div), vals![4, 1]);
    let modulo = Operator::modulo(7).unwrap();
    assert_eq!(modulo.method(), Modulo);
    assert_eq!(values(&modulo), vals![7]);
    let pow = Operator::power(3, Some(999.into()));
    assert_eq!(pow.method(), Power);
    assert_eq!(values(&pow), vals![3, 999]);
}

#[test]
fn test_array_filter_equal_condition() {
    assert!(valid(&make_validator(&[strings("tags")], None), op(ArrayFilter, "tags", vals!["equal", "active"])));
}

#[test]
fn test_array_filter_not_equal_condition() {
    let v = make_validator(&[strings("tags")], None);
    assert!(valid(&v, op(ArrayFilter, "tags", vals!["notEqual", "inactive"])));
}

#[test]
fn test_multiply_by_zero() {
    let v = make_validator(&[Attribute::integer("value")], current("value", 42));
    assert!(valid(&v, op(Multiply, "value", vals![0])));
}

#[test]
fn test_decrement_from_zero() {
    let v = make_validator(&[Attribute::integer("value")], current("value", 0));
    assert!(valid(&v, op(Decrement, "value", vals![1])));
}

#[test]
fn test_increment_from_max_minus_one() {
    let v = make_validator(&[Attribute::integer("value")], current("value", MAX_INT - 1));
    assert!(valid(&v, op(Increment, "value", vals![1])));
}

#[test]
fn test_decrement_from_min_plus_one() {
    let v = make_validator(&[Attribute::integer("value")], current("value", MIN_INT + 1));
    assert!(valid(&v, op(Decrement, "value", vals![1])));
}

#[test]
fn test_float_operators_skip_overflow_check() {
    let v = make_validator(&[Attribute::double("score")], current("score", f64::MAX / 2.0));
    assert!(valid(&v, op(Increment, "score", vals![f64::MAX / 2.0])));
}

#[test]
fn test_integer_overflow_with_max_cap() {
    let v = make_validator(&[Attribute::integer("value")], current("value", MAX_INT - 5));
    assert!(valid(&v, op(Increment, "value", vals![100, MAX_INT])));
    assert!(!valid(&v, op(Increment, "value", vals![100])));
}

#[test]
fn test_numeric_operators_accept_every_numeric_column_type() {
    for kind in [ColumnType::Integer, ColumnType::Float, ColumnType::Double, ColumnType::BigInteger] {
        let attribute = Attribute::from_array(
            &[("key", Value::from("value")), ("type", Value::from(kind.as_str()))]
                .into_iter()
                .map(|(k, v)| (k.into(), v))
                .collect(),
        )
        .unwrap();
        let v = make_validator(&[attribute], None);
        assert!(valid(&v, op(Increment, "value", vals![1])), "{}", kind.as_str());
    }
}

#[test]
fn test_numeric_operator_accepts_legacy_big_integer_metadata() {
    let mut collection = make_collection(&[]);
    let attribute = doc(vec![
        ("$id", Value::from("value")),
        ("key", Value::from("value")),
        ("type", Value::from("bigint")),
        ("signed", Value::Bool(true)),
        ("array", Value::Bool(false)),
    ]);
    collection.set("attributes", list(vec![attribute.into()])).unwrap();
    let v = OperatorValidator::from_document(&collection, current("value", i64::MAX - 1), true).unwrap();
    assert!(valid(&v, op(Increment, "value", vals![1])));
}

#[test]
fn test_big_integer_operator_rejects_overflow() {
    let v = make_validator(&[Attribute::big_integer("value")], current("value", i64::MAX));
    assert_invalid(&v, op(Increment, "value", vals![1]), "would overflow");
}

#[test]
fn test_unsigned_big_integer_operator_rejects_underflow() {
    let v = make_validator(&[Attribute::big_integer("value").signed(false)], current("value", 0));
    assert_invalid(&v, op(Decrement, "value", vals![1]), "would underflow");
}

#[test]
fn test_unsigned_big_integer_operator_crosses_php_integer_boundary() {
    let v = make_validator(&[Attribute::big_integer("value").signed(false)], current("value", i64::MAX));
    assert!(valid(&v, op(Increment, "value", vals![1])));
}

#[test]
fn test_unsigned_big_integer_operator_accepts_and_protects_exact_maximum() {
    let attribute = Attribute::big_integer("value").signed(false);
    let at_boundary = make_validator(std::slice::from_ref(&attribute), current("value", "18446744073709551614"));
    let overflow = make_validator(&[attribute], current("value", "18446744073709551615"));
    assert!(valid(&at_boundary, op(Increment, "value", vals![1])));
    assert_invalid(&overflow, op(Increment, "value", vals![1]), "18446744073709551615");
}

#[test]
fn test_unsigned_big_integer_operator_rejects_unsupported_adapter() {
    let collection = make_collection(&[Attribute::big_integer("value").signed(false)]);
    let v = OperatorValidator::from_document(&collection, current("value", 1), false).unwrap();
    assert_invalid(&v, op(Increment, "value", vals![1]), "not supported");
}

#[test]
fn test_unsigned_big_integer_array_operator_uses_exact_bounds() {
    let v = make_validator(&[Attribute::big_integer("values").signed(false).array(true)], None);
    assert!(valid(&v, op(ArrayAppend, "values", vec![list(vals!["18446744073709551615"])])));
    assert!(!valid(&v, op(ArrayAppend, "values", vec![list(vals!["18446744073709551616"])])));
}

// OperatorLimitTest

fn limit_validator() -> OperatorValidator {
    make_validator(
        &[
            Attribute::integer("count"),
            Attribute::big_integer("big"),
            Attribute::big_integer("unsigned").signed(false),
            Attribute::double("score"),
        ],
        None,
    )
}

#[test]
fn test_a_fractional_limit_on_an_integer_attribute_is_refused() {
    let cases: [(OperatorType, &str, Value, &str); 7] = [
        (Increment, "count", Value::Float(102.4), "102.4"),
        (Decrement, "count", Value::Float(-0.5), "-0.5"),
        (Multiply, "count", Value::Float(99.9), "99.9"),
        (Divide, "count", Value::Float(1.5), "1.5"),
        (Power, "count", Value::Float(1000.01), "1000.01"),
        (Increment, "count", Value::from("102.4"), "102.4"),
        (Increment, "big", Value::Float(4.0e15 + 0.5), "4.0E+15"),
    ];
    for (method, attribute, limit, shown) in cases {
        let v = limit_validator();
        assert!(!valid(&v, op(method, attribute, vec![Value::Int(2), limit])));
        assert_eq!(
            v.description(),
            format!(
                "Cannot apply {} operator: max/min limit must be a whole number for integer attribute '{attribute}', got {shown}",
                method.as_str()
            )
        );
    }
}

#[test]
fn test_a_whole_limit_is_accepted() {
    let cases: [(&str, Value); 7] = [
        ("count", Value::Int(100)),
        ("count", Value::Float(100.0)),
        ("count", Value::from("100")),
        ("count", Value::from("100.0")),
        ("big", Value::Float(9.0e18)),
        ("unsigned", Value::Float(1.0e19)),
        ("score", Value::Float(102.4)),
    ];
    for (attribute, limit) in cases {
        let v = limit_validator();
        assert!(valid(&v, op(Increment, attribute, vec![Value::Int(1), limit])), "{}", v.description());
    }
}

#[test]
fn test_a_limit_outside_the_attribute_range_is_refused() {
    let v = limit_validator();
    assert!(!valid(&v, op(Increment, "big", vals![1, 1.0e19])));
    assert_eq!(
        v.description(),
        "Cannot apply increment operator: max/min limit must be between -9223372036854775808 and 9223372036854775807"
    );
}

#[test]
fn test_a_non_numeric_limit_is_refused() {
    let v = limit_validator();
    assert!(!valid(&v, op(Increment, "count", vals![1, "many"])));
    assert_eq!(v.description(), "Cannot apply increment operator: max/min limit must be numeric, got string");
}
