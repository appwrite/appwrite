//! Ports of `tests/unit/Validator/OperatorTest.php` and
//! `tests/unit/Validator/OperatorValidatorTest.php` (utopia-php/database
//! 8.1.3).

use utopia_database::database::{MAX_INT, METADATA, MIN_INT};
use utopia_database::operator::{Operator, OperatorType};
use utopia_database::relationship::{RelationshipSide, RelationshipType};
use utopia_database::validator::operator::Operator as OperatorValidator;
use utopia_database::value::{Array, Value};
use utopia_database::{Attribute, Document, Kind};

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

fn valid(validator: &OperatorValidator, value: impl Into<Value>) -> bool {
    validator.is_valid(&value.into()).unwrap()
}

fn on(mut operator: Operator, attribute: &str) -> Operator {
    operator.set_attribute(attribute);
    operator
}

// Validator/OperatorTest

fn attribute(key: &str, kind: &str, array: bool, size: Option<i64>) -> Value {
    let mut pairs = vec![
        ("$id", Value::from(key)),
        ("key", Value::from(key)),
        ("type", Value::from(kind)),
        ("array", Value::Bool(array)),
    ];
    if let Some(size) = size {
        pairs.push(("size", Value::Int(size)));
    }
    doc(pairs).into()
}

fn validator() -> OperatorValidator {
    let collection = doc(vec![
        ("$id", Value::from("test_collection")),
        (
            "attributes",
            list(vec![
                attribute("count", "integer", false, None),
                attribute("score", "double", false, None),
                attribute("title", "string", false, Some(100)),
                attribute("tags", "string", true, None),
                attribute("active", "boolean", false, None),
                attribute("createdAt", "datetime", false, None),
            ]),
        ),
    ]);
    OperatorValidator::from_document(&collection, None, true).unwrap()
}

#[track_caller]
fn assert_rejected(v: &OperatorValidator, value: impl Into<Value>, contains: &str) {
    assert!(!valid(v, value));
    let description = v.description();
    assert!(description.contains(contains), "{description:?} does not contain {contains:?}");
}

#[test]
fn test_parse_string_operator() {
    let v = validator();
    let json = on(Operator::increment(5, None), "count").to_string().unwrap();
    assert!(valid(&v, json.as_str()), "{}", v.description());
}

#[test]
fn test_parse_invalid_string_operator() {
    assert_rejected(&validator(), "invalid json", "Invalid operator:");
}

#[test]
fn test_parse_string_operator_with_invalid_method() {
    let json = r#"{"method":"invalidMethod","attribute":"count","values":[1]}"#;
    assert_rejected(&validator(), json, "Invalid operator method:");
}

#[test]
fn test_increment_operator() {
    let v = validator();
    assert!(valid(&v, on(Operator::increment(5, None), "count")), "{}", v.description());
}

#[test]
fn test_increment_on_non_numeric() {
    assert_rejected(
        &validator(),
        on(Operator::increment(5, None), "title"),
        "Cannot apply increment operator to non-numeric field",
    );
}

#[test]
fn test_decrement_operator() {
    assert!(valid(&validator(), on(Operator::decrement(3, None), "count")));
}

#[test]
fn test_multiply_operator() {
    assert!(valid(&validator(), on(Operator::multiply(2.5, None), "score")));
}

#[test]
fn test_divide_by_zero() {
    let e = Operator::divide(0, None).unwrap_err();
    assert_eq!(e.kind(), Kind::Operator);
    assert_eq!(e.message(), "Division by zero is not allowed");
}

#[test]
fn test_modulo_by_zero() {
    let e = Operator::modulo(0).unwrap_err();
    assert_eq!(e.kind(), Kind::Operator);
    assert_eq!(e.message(), "Modulo by zero is not allowed");
}

#[test]
fn test_array_append() {
    assert!(valid(&validator(), on(Operator::array_append(["new-tag"]), "tags")));
}

#[test]
fn test_array_append_on_non_array() {
    assert_rejected(
        &validator(),
        on(Operator::array_append(["value"]), "title"),
        "Cannot apply arrayAppend operator to non-array field",
    );
}

#[test]
fn test_array_unique() {
    assert!(valid(&validator(), on(Operator::array_unique(), "tags")));
}

#[test]
fn test_array_unique_on_non_array() {
    assert_rejected(
        &validator(),
        on(Operator::array_unique(), "count"),
        "Cannot apply arrayUnique operator to non-array field",
    );
}

#[test]
fn test_array_intersect() {
    assert!(valid(&validator(), on(Operator::array_intersect(["tag1", "tag2"]), "tags")));
}

#[test]
fn test_array_intersect_with_empty_array() {
    assert_rejected(
        &validator(),
        on(Operator::array_intersect(Vec::<Value>::new()), "tags"),
        "requires a non-empty array value",
    );
}

#[test]
fn test_array_diff() {
    assert!(valid(&validator(), on(Operator::array_diff(["unwanted"]), "tags")));
}

#[test]
fn test_array_filter() {
    assert!(valid(&validator(), on(Operator::array_filter("equal", "active"), "tags")));
}

#[test]
fn test_array_filter_invalid_condition() {
    assert_rejected(
        &validator(),
        on(Operator::array_filter("invalidCondition", "value"), "tags"),
        "Invalid array filter condition",
    );
}

#[test]
fn test_string_concat() {
    assert!(valid(&validator(), on(Operator::string_concat(" - Updated"), "title")));
}

#[test]
fn test_string_concat_on_non_string() {
    assert_rejected(
        &validator(),
        on(Operator::string_concat(" suffix"), "count"),
        "Cannot apply stringConcat operator to non-string field",
    );
}

#[test]
fn test_string_replace() {
    assert!(valid(&validator(), on(Operator::string_replace("old", "new"), "title")));
}

#[test]
fn test_toggle() {
    assert!(valid(&validator(), on(Operator::toggle(), "active")));
}

#[test]
fn test_toggle_on_non_boolean() {
    assert_rejected(&validator(), on(Operator::toggle(), "count"), "Cannot apply toggle operator to non-boolean field");
}

#[test]
fn test_date_add_days() {
    assert!(valid(&validator(), on(Operator::date_add_days(7), "createdAt")));
}

#[test]
fn test_date_add_days_on_non_date_time() {
    assert_rejected(
        &validator(),
        on(Operator::date_add_days(7), "count"),
        "Cannot apply dateAddDays operator to non-datetime field",
    );
}

#[test]
fn test_date_sub_days() {
    assert!(valid(&validator(), on(Operator::date_sub_days(3), "createdAt")));
}

#[test]
fn test_date_sub_days_on_non_date_time() {
    assert_rejected(
        &validator(),
        on(Operator::date_sub_days(3), "title"),
        "Cannot apply dateSubDays operator to non-datetime field",
    );
}

#[test]
fn test_date_set_now() {
    assert!(valid(&validator(), on(Operator::date_set_now(), "createdAt")));
}

#[test]
fn test_non_existent_attribute() {
    assert_rejected(
        &validator(),
        on(Operator::increment(1, None), "nonExistentField"),
        "Attribute 'nonExistentField' does not exist in collection",
    );
}

#[test]
fn test_multiple_string_operators() {
    let v = validator();
    let operators = [
        Operator::increment(1, None),
        Operator::array_append(["tag"]),
        Operator::string_concat(" suffix"),
        Operator::toggle(),
        Operator::date_add_days(7),
    ];
    let attributes = ["count", "tags", "title", "active", "createdAt"];
    for (operator, attribute) in operators.into_iter().zip(attributes) {
        let json = on(operator, attribute).to_string().unwrap();
        assert!(valid(&v, json.as_str()), "Failed for operator {attribute}: {}", v.description());
    }
}

// Validator/OperatorValidatorTest

const RELATION: &str = "related";
const SINGLE_VALUE: &str = "single-value relationship";
const DOCUMENT_IDS: &str = "relationship values must be document IDs (strings) or Document objects";

fn collection(attributes: Vec<Value>) -> Document {
    doc(vec![
        ("$id", Value::from("operands")),
        ("$collection", Value::from(METADATA)),
        ("name", Value::from("operands")),
        ("attributes", list(attributes)),
        ("indexes", list(vec![])),
    ])
}

fn relationship_validator(kind: RelationshipType, side: RelationshipSide) -> OperatorValidator {
    let options: Array = [
        ("relatedCollection", Value::from("others")),
        ("relationType", Value::from(kind.as_str())),
        ("twoWay", Value::Bool(true)),
        ("twoWayKey", Value::from("back")),
        ("side", Value::from(side.as_str())),
    ]
    .into_iter()
    .map(|(k, v)| (k.into(), v))
    .collect();
    let attribute = doc(vec![
        ("$id", Value::from(RELATION)),
        ("key", Value::from(RELATION)),
        ("type", Value::from("relationship")),
        ("array", Value::Bool(false)),
        ("options", Value::Array(options)),
    ]);
    OperatorValidator::from_document(&collection(vec![attribute.into()]), None, true).unwrap()
}

fn numeric_validator(current: Option<Document>) -> OperatorValidator {
    let attribute = Attribute::integer("count").to_document().unwrap();
    OperatorValidator::from_document(&collection(vec![attribute.into()]), current, true).unwrap()
}

fn double_validator() -> OperatorValidator {
    let attribute = Attribute::double("ratio").to_document().unwrap();
    OperatorValidator::from_document(&collection(vec![attribute.into()]), None, true).unwrap()
}

fn id_document(id: &str) -> Value {
    doc(vec![("$id", Value::from(id))]).into()
}

#[test]
fn test_array_operator_on_a_single_value_relationship_is_rejected() {
    let sides = [
        (RelationshipType::OneToOne, RelationshipSide::Parent),
        (RelationshipType::OneToOne, RelationshipSide::Child),
        (RelationshipType::OneToMany, RelationshipSide::Child),
        (RelationshipType::ManyToOne, RelationshipSide::Parent),
    ];
    let operators = [
        (ArrayUnique, vec![]),
        (ArrayInsert, vals![0, "b"]),
        (ArrayRemove, vals!["b"]),
        (ArrayIntersect, vals!["b"]),
        (ArrayDiff, vals!["b"]),
        (ArrayFilter, vals!["isNotNull"]),
    ];
    for (kind, side) in sides {
        for (method, values) in &operators {
            let v = relationship_validator(kind, side);
            assert_rejected(&v, Operator::new(*method, RELATION, values.clone()), SINGLE_VALUE);
        }
    }
}

#[test]
fn test_non_identifier_relationship_values_are_rejected() {
    let cases = [
        (ArrayAppend, vals![5]),
        (ArrayAppend, vec![list(vals!["x"])]),
        (ArrayPrepend, vals![true]),
        (ArrayInsert, vals![0, 5]),
        (ArrayInsert, vec![Value::Int(0), list(vals!["x"])]),
        (ArrayRemove, vals![5]),
        (ArrayRemove, vec![list(vals![1.5])]),
        (ArrayIntersect, vals![5]),
        (ArrayDiff, vals![5]),
    ];
    for (method, values) in cases {
        let v = relationship_validator(RelationshipType::ManyToMany, RelationshipSide::Parent);
        assert_rejected(&v, Operator::new(method, RELATION, values), DOCUMENT_IDS);
    }
}

#[test]
fn test_identifier_values_on_a_many_to_many_relationship_are_accepted() {
    let cases = [
        (ArrayAppend, vec![Value::from("b"), id_document("c")]),
        (ArrayInsert, vec![Value::Int(0), id_document("c")]),
        (ArrayRemove, vec![list(vals!["b", "c"])]),
        (ArrayIntersect, vals!["b"]),
        (ArrayDiff, vec![id_document("b")]),
        (ArrayUnique, vec![]),
        (ArrayFilter, vals!["isNotNull"]),
    ];
    for (method, values) in cases {
        let v = relationship_validator(RelationshipType::ManyToMany, RelationshipSide::Child);
        assert!(valid(&v, Operator::new(method, RELATION, values)), "{}", v.description());
    }
}

#[test]
fn test_a_stored_value_outside_the_attribute_range_is_rejected() {
    for stored in [Value::from("abc"), Value::Int(MAX_INT + 1), Value::Int(MIN_INT - 1), Value::Float(1.5)] {
        let v = numeric_validator(Some(doc(vec![("count", stored)])));
        assert!(!valid(&v, Operator::new(Increment, "count", vals![1])));
        assert_eq!(v.description(), "Cannot apply increment operator: current value is outside the attribute range");
    }
}

#[test]
fn test_a_stored_value_inside_the_attribute_range_is_accepted() {
    let v = numeric_validator(Some(doc(vec![("count", Value::Int(MAX_INT - 1))])));
    assert!(valid(&v, Operator::new(Increment, "count", vals![1])), "{}", v.description());
}

#[test]
fn test_a_result_that_cannot_be_predicted_is_rejected() {
    let v = numeric_validator(Some(doc(vec![("count", Value::Int(2))])));
    assert!(!valid(&v, Operator::new(Power, "count", vals![-1])));
    assert_eq!(v.description(), "Cannot apply power operator: result is outside the attribute range");
    assert!(valid(&v, Operator::new(Power, "count", vals![3])), "{}", v.description());
}

/// PHP also passes the side as the enum case; a document holds its string.
#[test]
fn test_a_side_given_as_the_enum_decides_whether_the_relationship_holds_a_list() {
    let cases = [
        (RelationshipType::OneToMany, RelationshipSide::Parent, true),
        (RelationshipType::OneToMany, RelationshipSide::Child, false),
        (RelationshipType::ManyToOne, RelationshipSide::Child, true),
        (RelationshipType::ManyToOne, RelationshipSide::Parent, false),
    ];
    for (kind, side, holds_a_list) in cases {
        let v = relationship_validator(kind, side);
        assert_eq!(valid(&v, Operator::new(ArrayAppend, RELATION, vals!["b"])), holds_a_list, "{}", v.description());
    }
}

#[test]
fn test_non_numeric_and_non_finite_operands_on_a_double_are_rejected() {
    for operand in [
        Value::from("abc"),
        Value::Float(f64::INFINITY),
        Value::Float(f64::NEG_INFINITY),
        Value::Float(f64::NAN),
        Value::from("1e999"),
        Value::Bool(true),
    ] {
        let v = double_validator();
        assert!(!valid(&v, Operator::new(Increment, "ratio", vec![operand])));
        assert!(v.description().starts_with("Cannot apply increment operator: value must be numeric"));
    }
}

#[test]
fn test_numeric_string_operands_on_a_double_are_accepted() {
    for operand in ["1.5", "10", "2e3", "-0.25"] {
        let v = double_validator();
        assert!(valid(&v, Operator::new(Increment, "ratio", vals![operand])), "{}", v.description());
        assert!(valid(&v, Operator::new(Multiply, "ratio", vals![operand, "100"])), "{}", v.description());
    }
}

#[test]
fn test_a_whole_float_operand_on_an_integer_is_accepted_as_the_integer() {
    let v = numeric_validator(Some(doc(vec![("count", Value::Int(MAX_INT - 5))])));
    assert!(valid(&v, Operator::new(Increment, "count", vals![5.0])), "{}", v.description());
    assert!(!valid(&v, Operator::new(Increment, "count", vals![6.0])));
    assert!(v.description().contains("would overflow maximum value"));
    assert!(!valid(&v, Operator::new(Increment, "count", vals![5.5])));
    assert!(v.description().starts_with("Cannot apply increment operator: value must be numeric"));
    assert!(!valid(&v, Operator::new(Divide, "count", vals![0.0])));
    assert_eq!(v.description(), "Cannot apply divide operator: division by zero");
}

/// Values that are not operators are read through `Operator::parse()` as
/// PHP's coercive typing passes them (confirmed against PHP 8.5).
#[test]
fn test_values_that_are_not_operators() {
    let v = validator();
    assert!(!valid(&v, Value::Null));
    assert_eq!(
        v.description(),
        "Invalid operator: Utopia\\Database\\Operator::parse(): Argument #1 ($operator) must be of type string, null given, called in /usr/src/code/vendor/utopia-php/database/src/Database/Validator/Operator.php on line 201"
    );
    assert!(!valid(&v, Value::Int(5)));
    assert_eq!(v.description(), "Invalid operator: Invalid operator. Must be an array, got integer");
    let e = OperatorValidator::from_document(
        &doc(vec![("attributes", list(vec![attribute("s", "string", false, None)]))]),
        Some(doc(vec![("s", list(vals!["a"]))])),
        true,
    )
    .unwrap()
    .is_valid(&Operator::new(StringConcat, "s", vals!["x"]).into())
    .unwrap_err();
    assert_eq!(e.kind(), Kind::TypeError);
    assert_eq!(e.message(), "strlen(): Argument #1 ($string) must be of type string, array given");
}
