//! Port of `tests/unit/OperatorTest.php` (utopia-php/database 8.1.3).

use utopia_database::Kind;
use utopia_database::operator::{Extracted, Operator, OperatorType};
use utopia_database::value::{Array, ArrayKey, Value};

macro_rules! vals {
    ($($v:expr),* $(,)?) => { vec![$(Value::from($v)),*] };
}

fn values(op: &Operator) -> Vec<Value> {
    op.values().values().cloned().collect()
}

fn list(items: Vec<Value>) -> Value {
    Value::Array(Array::from_list(items))
}

fn data(pairs: Vec<(&str, Value)>) -> Array {
    pairs.into_iter().map(|(k, v)| (ArrayKey::from(k), v)).collect()
}

fn operator<'a>(extracted: &'a Extracted, key: &str) -> &'a Operator {
    &extracted.operators.iter().find(|(k, _)| *k == ArrayKey::from(key)).expect(key).1
}

fn expected_array(method: OperatorType, attribute: &str, items: Vec<Value>) -> Array {
    data(vec![("method", Value::from(method.as_str())), ("attribute", Value::from(attribute)), ("values", list(items))])
}

/// `toArray()` equals `$expected`, and `json_decode(toString())` too.
fn assert_serializes(op: &Operator, expected: &Array) {
    assert_eq!(&op.to_array(), expected);
    let decoded = Operator::parse(op.to_string().unwrap()).unwrap();
    assert_eq!(&decoded.to_array(), expected);
}

fn assert_parses(method: OperatorType, attribute: &str, items: Vec<Value>) {
    let array = expected_array(method, attribute, items.clone());
    let op = Operator::parse_operator(&array).unwrap();
    assert_eq!(op.method(), method);
    assert_eq!(op.attribute(), attribute);
    assert_eq!(values(&op), items);
    let json = Operator::new(method, attribute, items.clone()).to_string().unwrap();
    let op = Operator::parse(json).unwrap();
    assert_eq!(op.method(), method);
    assert_eq!(op.attribute(), attribute);
    assert_eq!(values(&op), items);
}

fn assert_kinds(op: &Operator, numeric: bool, array: bool, string: bool, boolean: bool, date: bool) {
    assert_eq!(op.is_numeric_operation(), numeric);
    assert_eq!(op.is_array_operation(), array);
    assert_eq!(op.is_string_operation(), string);
    assert_eq!(op.is_boolean_operation(), boolean);
    assert_eq!(op.is_date_operation(), date);
}

fn assert_error(result: utopia_database::Result<Operator>, message: &str) {
    let e = result.unwrap_err();
    assert_eq!(e.kind(), Kind::Operator);
    assert!(e.message().contains(message), "{}", e.message());
}

#[test]
fn test_create() {
    let op = Operator::new(OperatorType::Increment, "count", vals![1]);
    assert_eq!(op.method(), OperatorType::Increment);
    assert_eq!(op.attribute(), "count");
    assert_eq!(values(&op), vals![1]);
    assert_eq!(op.value(), Some(Value::Int(1)));

    let op = Operator::new(OperatorType::ArrayAppend, "tags", vals!["php", "database"]);
    assert_eq!(op.method(), OperatorType::ArrayAppend);
    assert_eq!(op.attribute(), "tags");
    assert_eq!(values(&op), vals!["php", "database"]);
    assert_eq!(op.value(), Some(Value::from("php")));
}

#[test]
fn test_helper_methods() {
    let cases: Vec<(Operator, OperatorType, Vec<Value>)> = vec![
        (Operator::increment(5, None), OperatorType::Increment, vals![5]),
        (Operator::decrement(1, None), OperatorType::Decrement, vals![1]),
        (Operator::string_concat(" - Updated"), OperatorType::StringConcat, vals![" - Updated"]),
        (Operator::string_replace("old", "new"), OperatorType::StringReplace, vals!["old", "new"]),
        (Operator::multiply(2, Some(1000.into())), OperatorType::Multiply, vals![2, 1000]),
        (Operator::divide(2, Some(1.into())).unwrap(), OperatorType::Divide, vals![2, 1]),
        (Operator::toggle(), OperatorType::Toggle, vec![]),
        (Operator::date_set_now(), OperatorType::DateSetNow, vec![]),
        (Operator::modulo(3).unwrap(), OperatorType::Modulo, vals![3]),
        (Operator::power(2, Some(1000.into())), OperatorType::Power, vals![2, 1000]),
        (Operator::array_append(["new", "values"]), OperatorType::ArrayAppend, vals!["new", "values"]),
        (Operator::array_prepend(["first", "second"]), OperatorType::ArrayPrepend, vals!["first", "second"]),
        (Operator::array_insert(2, "inserted"), OperatorType::ArrayInsert, vals![2, "inserted"]),
        (Operator::array_remove("unwanted"), OperatorType::ArrayRemove, vals!["unwanted"]),
    ];
    for (op, method, items) in cases {
        assert_eq!(op.method(), method);
        assert_eq!(op.attribute(), "");
        assert_eq!(values(&op), items);
    }
    // PHP's default increment is 1.
    assert_eq!(Operator::increment(1, None).value(), Some(Value::Int(1)));
}

#[test]
fn test_setters() {
    let mut op = Operator::new(OperatorType::Increment, "test", vals![1]);
    op.set_method(OperatorType::Decrement);
    assert_eq!(op.method(), OperatorType::Decrement);
    op.set_attribute("newAttribute");
    assert_eq!(op.attribute(), "newAttribute");
    op.set_values(vals![10, 20]);
    assert_eq!(values(&op), vals![10, 20]);
    op.set_value(Value::Int(50));
    assert_eq!(values(&op), vals![50]);
    assert_eq!(op.value(), Some(Value::Int(50)));
}

#[test]
fn test_type_methods() {
    assert_kinds(&Operator::increment(1, None), true, false, false, false, false);
    assert_kinds(&Operator::decrement(1, None), true, false, false, false, false);
    assert_kinds(&Operator::string_concat("suffix"), false, false, true, false, false);
    assert_kinds(&Operator::string_replace("old", "new"), false, false, true, false, false);
    assert_kinds(&Operator::toggle(), false, false, false, true, false);
    assert_kinds(&Operator::date_set_now(), false, false, false, false, true);
    assert_kinds(&Operator::array_append(["tag"]), false, true, false, false, false);
    assert_kinds(&Operator::array_prepend(["tag"]), false, true, false, false, false);
    assert_kinds(&Operator::array_insert(0, "item"), false, true, false, false, false);
    assert_kinds(&Operator::array_remove("unwanted"), false, true, false, false, false);
}

#[test]
fn test_is_method() {
    use OperatorType::*;
    for method in [
        Increment,
        Decrement,
        Multiply,
        Divide,
        StringConcat,
        StringReplace,
        Toggle,
        DateSetNow,
        Modulo,
        Power,
        ArrayAppend,
        ArrayPrepend,
        ArrayInsert,
        ArrayRemove,
    ] {
        assert!(Operator::is_method(method.as_str()));
    }
    for invalid in ["invalid", "", "append", "prepend", "insert"] {
        assert!(!Operator::is_method(invalid));
    }
}

#[test]
fn test_is_operator() {
    assert!(Operator::is_operator(&Value::from(Operator::increment(1, None))));
    assert!(!Operator::is_operator(&Value::from("string")));
    assert!(!Operator::is_operator(&Value::Int(123)));
    assert!(!Operator::is_operator(&list(vec![])));
    assert!(!Operator::is_operator(&Value::Null));
}

#[test]
fn test_extract_operators() {
    let extracted = Operator::extract_operators(data(vec![
        ("name", Value::from("John")),
        ("count", Operator::increment(5, None).into()),
        ("tags", Operator::array_append(["new"]).into()),
        ("age", Value::Int(30)),
    ]));
    assert_eq!(extracted.operators.len(), 2);
    assert_eq!(operator(&extracted, "count").attribute(), "count");
    assert_eq!(operator(&extracted, "tags").attribute(), "tags");
    assert_eq!(extracted.updates, data(vec![("name", Value::from("John")), ("age", Value::Int(30))]));
}

#[test]
fn test_serialization() {
    let mut op = Operator::increment(10, None);
    op.set_attribute("score");
    assert_serializes(&op, &expected_array(OperatorType::Increment, "score", vals![10]));
}

#[test]
fn test_parsing() {
    assert_parses(OperatorType::Increment, "score", vals![5]);
}

#[test]
fn test_parse_operators() {
    let json1 = Operator::new(OperatorType::Increment, "count", vals![1]).to_string().unwrap();
    let json2 = Operator::new(OperatorType::ArrayAppend, "tags", vals!["new"]).to_string().unwrap();
    let parsed = Operator::parse_operators(&[json1, json2]).unwrap();
    assert_eq!(parsed.len(), 2);
    assert_eq!(parsed[0].method(), OperatorType::Increment);
    assert_eq!(parsed[1].method(), OperatorType::ArrayAppend);
}

#[test]
fn test_clone() {
    let op1 = Operator::increment(5, None);
    let mut op2 = op1.clone();
    assert_eq!(op1.method(), op2.method());
    assert_eq!(op1.attribute(), op2.attribute());
    assert_eq!(values(&op1), values(&op2));
    op2.set_method(OperatorType::Decrement);
    assert_eq!(op1.method(), OperatorType::Increment);
    assert_eq!(op2.method(), OperatorType::Decrement);
}

#[test]
fn test_get_value_with_default() {
    let op = Operator::increment(5, None);
    assert_eq!(op.value(), Some(Value::Int(5)));
    assert_eq!(op.value().unwrap_or(Value::from("default")), Value::Int(5));
    let empty = Operator::new(OperatorType::Increment, "count", vec![]);
    assert_eq!(empty.value().unwrap_or(Value::from("default")), Value::from("default"));
    assert_eq!(empty.value(), None);
}

#[test]
fn test_parse_invalid_json() {
    assert_error(Operator::parse("invalid json"), "Invalid operator");
}

#[test]
fn test_parse_non_array() {
    assert_error(Operator::parse("\"string\""), "Invalid operator. Must be an array");
}

#[test]
fn test_parse_invalid_method() {
    let array = data(vec![("method", Value::Int(123)), ("attribute", Value::from("test")), ("values", list(vec![]))]);
    assert_error(Operator::parse_operator(&array), "Invalid operator method. Must be a string");
}

#[test]
fn test_parse_unsupported_method() {
    let array =
        data(vec![("method", Value::from("invalid")), ("attribute", Value::from("test")), ("values", list(vec![]))]);
    assert_error(Operator::parse_operator(&array), "Invalid operator method: invalid");
}

#[test]
fn test_parse_invalid_attribute() {
    let array =
        data(vec![("method", Value::from("increment")), ("attribute", Value::Int(123)), ("values", list(vec![]))]);
    assert_error(Operator::parse_operator(&array), "Invalid operator attribute. Must be a string");
}

#[test]
fn test_parse_invalid_values() {
    let array = data(vec![
        ("method", Value::from("increment")),
        ("attribute", Value::from("test")),
        ("values", Value::from("not array")),
    ]);
    assert_error(Operator::parse_operator(&array), "Invalid operator values. Must be an array");
}

/// PHP encodes a resource; a NaN cannot be encoded either.
#[test]
fn test_to_string_invalid_json() {
    let mut op = Operator::new(OperatorType::Increment, "test", vec![]);
    op.set_values(vals![f64::NAN]);
    let e = op.to_string().unwrap_err();
    assert_eq!(e.kind(), Kind::Operator);
    assert!(e.message().starts_with("Invalid Json"), "{}", e.message());
}

#[test]
fn test_increment_with_max() {
    let op = Operator::increment(5, Some(10.into()));
    assert_eq!(op.method(), OperatorType::Increment);
    assert_eq!(values(&op), vals![5, 10]);
    assert_eq!(values(&Operator::increment(5, None)), vals![5]);
}

#[test]
fn test_decrement_with_min() {
    let op = Operator::decrement(3, Some(0.into()));
    assert_eq!(op.method(), OperatorType::Decrement);
    assert_eq!(values(&op), vals![3, 0]);
    assert_eq!(values(&Operator::decrement(3, None)), vals![3]);
}

#[test]
fn test_array_remove() {
    let op = Operator::array_remove("spam");
    assert_eq!(op.method(), OperatorType::ArrayRemove);
    assert_eq!(values(&op), vals!["spam"]);
    assert_eq!(op.value(), Some(Value::from("spam")));
}

#[test]
fn test_extract_operators_with_new_methods() {
    let extracted = Operator::extract_operators(data(vec![
        ("name", Value::from("John")),
        ("count", Operator::increment(5, Some(100.into())).into()),
        ("score", Operator::decrement(1, Some(0.into())).into()),
        ("tags", Operator::array_append(["new"]).into()),
        ("items", Operator::array_prepend(["first"]).into()),
        ("list", Operator::array_insert(2, "value").into()),
        ("blacklist", Operator::array_remove("spam").into()),
        ("title", Operator::string_concat(" - Updated").into()),
        ("content", Operator::string_replace("old", "new").into()),
        ("views", Operator::multiply(2, Some(1000.into())).into()),
        ("rating", Operator::divide(2, Some(1.into())).unwrap().into()),
        ("featured", Operator::toggle().into()),
        ("last_modified", Operator::date_set_now().into()),
        ("title_prefix", Operator::string_concat(" - Updated").into()),
        ("views_modulo", Operator::modulo(3).unwrap().into()),
        ("score_power", Operator::power(2, Some(1000.into())).into()),
        ("age", Value::Int(30)),
    ]));
    assert_eq!(extracted.operators.len(), 15);
    assert_eq!(operator(&extracted, "tags").attribute(), "tags");
    assert_eq!(operator(&extracted, "tags").method(), OperatorType::ArrayAppend);
    assert_eq!(operator(&extracted, "blacklist").attribute(), "blacklist");
    assert_eq!(operator(&extracted, "blacklist").method(), OperatorType::ArrayRemove);
    assert_eq!(operator(&extracted, "title").method(), OperatorType::StringConcat);
    assert_eq!(operator(&extracted, "content").method(), OperatorType::StringReplace);
    assert_eq!(operator(&extracted, "views").method(), OperatorType::Multiply);
    assert_eq!(operator(&extracted, "rating").method(), OperatorType::Divide);
    assert_eq!(operator(&extracted, "featured").method(), OperatorType::Toggle);
    assert_eq!(operator(&extracted, "title_prefix").method(), OperatorType::StringConcat);
    assert_eq!(operator(&extracted, "views_modulo").method(), OperatorType::Modulo);
    assert_eq!(operator(&extracted, "score_power").method(), OperatorType::Power);
    assert_eq!(operator(&extracted, "last_modified").method(), OperatorType::DateSetNow);
    assert_eq!(values(operator(&extracted, "count")), vals![5, 100]);
    assert_eq!(values(operator(&extracted, "score")), vals![1, 0]);
    assert_eq!(extracted.updates, data(vec![("name", Value::from("John")), ("age", Value::Int(30))]));
}

#[test]
fn test_parsing_with_new_constants() {
    let op = Operator::parse_operator(&expected_array(OperatorType::ArrayRemove, "blacklist", vals!["spam"])).unwrap();
    assert_eq!(op.method(), OperatorType::ArrayRemove);
    assert_eq!(op.attribute(), "blacklist");
    assert_eq!(values(&op), vals!["spam"]);
    let op = Operator::parse_operator(&expected_array(OperatorType::Increment, "score", vals![1, 10])).unwrap();
    assert_eq!(values(&op), vals![1, 10]);
}

#[test]
fn test_increment_max_limit_edge_cases() {
    assert_eq!(values(&Operator::increment(5, Some(10.into()))), vals![5, 10]);
    assert_eq!(values(&Operator::increment(1.5, Some(9.9.into()))), vals![1.5, 9.9]);
    assert_eq!(values(&Operator::increment(1, Some((-5).into()))), vals![1, -5]);
}

#[test]
fn test_decrement_min_limit_edge_cases() {
    assert_eq!(values(&Operator::decrement(3, Some(0.into()))), vals![3, 0]);
    assert_eq!(values(&Operator::decrement(2.5, Some(0.1.into()))), vals![2.5, 0.1]);
    assert_eq!(values(&Operator::decrement(1, Some((-10).into()))), vals![1, -10]);
}

#[test]
fn test_array_remove_edge_cases() {
    assert_eq!(Operator::array_remove("string").value(), Some(Value::from("string")));
    assert_eq!(Operator::array_remove(42).value(), Some(Value::Int(42)));
    assert_eq!(Operator::array_remove(Value::Null).value(), None);
    assert_eq!(Operator::array_remove(true).value(), Some(Value::Bool(true)));
    assert_eq!(Operator::array_remove(list(vals!["nested"])).value(), Some(list(vals!["nested"])));
}

#[test]
fn test_operator_cloning_with_new_methods() {
    let op1 = Operator::increment(5, Some(10.into()));
    let mut op2 = op1.clone();
    assert_eq!(values(&op1), values(&op2));
    assert_eq!(values(&op2), vals![5, 10]);
    op2.set_values(vals![3, 8]);
    assert_eq!(values(&op1), vals![5, 10]);
    assert_eq!(values(&op2), vals![3, 8]);

    let remove1 = Operator::array_remove("spam");
    let mut remove2 = remove1.clone();
    assert_eq!(remove1.value(), remove2.value());
    remove2.set_value(Value::from("ham"));
    assert_eq!(remove1.value(), Some(Value::from("spam")));
    assert_eq!(remove2.value(), Some(Value::from("ham")));
}

#[test]
fn test_serialization_with_new_operators() {
    let mut op = Operator::increment(5, Some(100.into()));
    op.set_attribute("score");
    assert_eq!(op.to_array(), expected_array(OperatorType::Increment, "score", vals![5, 100]));
    let mut op = Operator::array_remove("unwanted");
    op.set_attribute("blacklist");
    assert_serializes(&op, &expected_array(OperatorType::ArrayRemove, "blacklist", vals!["unwanted"]));
}

#[test]
fn test_mixed_operator_types() {
    let extracted = Operator::extract_operators(data(vec![
        ("arrayAppend", Operator::array_append(["new"]).into()),
        ("incrementWithMax", Operator::increment(1, Some(10.into())).into()),
        ("decrementWithMin", Operator::decrement(2, Some(0.into())).into()),
        ("multiply", Operator::multiply(3, Some(100.into())).into()),
        ("divide", Operator::divide(2, Some(1.into())).unwrap().into()),
        ("concat", Operator::string_concat(" suffix").into()),
        ("replace", Operator::string_replace("old", "new").into()),
        ("toggle", Operator::toggle().into()),
        ("dateSetNow", Operator::date_set_now().into()),
        ("modulo", Operator::modulo(3).unwrap().into()),
        ("power", Operator::power(2, None).into()),
        ("remove", Operator::array_remove("bad").into()),
    ]));
    assert_eq!(extracted.operators.len(), 12);
    let m = |k: &str| operator(&extracted, k).method();
    let v = |k: &str| values(operator(&extracted, k));
    assert_eq!(m("arrayAppend"), OperatorType::ArrayAppend);
    assert_eq!(m("incrementWithMax"), OperatorType::Increment);
    assert_eq!(v("incrementWithMax"), vals![1, 10]);
    assert_eq!(m("decrementWithMin"), OperatorType::Decrement);
    assert_eq!(v("decrementWithMin"), vals![2, 0]);
    assert_eq!(m("multiply"), OperatorType::Multiply);
    assert_eq!(v("multiply"), vals![3, 100]);
    assert_eq!(m("divide"), OperatorType::Divide);
    assert_eq!(v("divide"), vals![2, 1]);
    assert_eq!(m("concat"), OperatorType::StringConcat);
    assert_eq!(m("replace"), OperatorType::StringReplace);
    assert_eq!(m("toggle"), OperatorType::Toggle);
    assert_eq!(m("dateSetNow"), OperatorType::DateSetNow);
    assert_eq!(m("modulo"), OperatorType::Modulo);
    assert_eq!(m("power"), OperatorType::Power);
    assert_eq!(m("remove"), OperatorType::ArrayRemove);
}

#[test]
fn test_type_validation_with_new_methods() {
    let empty: [&str; 0] = [];
    assert!(Operator::array_append(empty).is_array_operation());
    assert!(Operator::array_prepend(empty).is_array_operation());
    assert!(Operator::array_insert(0, "value").is_array_operation());
    assert!(Operator::array_remove("value").is_array_operation());
    assert!(!Operator::array_append(empty).is_numeric_operation());
    assert!(!Operator::array_prepend(empty).is_numeric_operation());
    assert!(!Operator::array_insert(0, "value").is_numeric_operation());
    assert!(!Operator::array_remove("value").is_numeric_operation());
    assert!(Operator::multiply(2, None).is_numeric_operation());
    assert!(Operator::divide(2, None).unwrap().is_numeric_operation());
    assert!(!Operator::multiply(2, None).is_array_operation());
    assert!(!Operator::divide(2, None).unwrap().is_array_operation());
    assert!(Operator::string_concat("test").is_string_operation());
    assert!(Operator::string_replace("old", "new").is_string_operation());
    assert!(!Operator::string_concat("test").is_numeric_operation());
    assert!(!Operator::string_replace("old", "new").is_array_operation());
    assert!(Operator::toggle().is_boolean_operation());
    assert!(!Operator::toggle().is_numeric_operation());
    assert!(!Operator::toggle().is_array_operation());
    assert!(Operator::date_set_now().is_date_operation());
    assert!(!Operator::date_set_now().is_numeric_operation());
}

#[test]
fn test_string_operators() {
    let op = Operator::string_concat(" - Updated");
    assert_eq!(op.method(), OperatorType::StringConcat);
    assert_eq!(values(&op), vals![" - Updated"]);
    assert_eq!(op.value(), Some(Value::from(" - Updated")));
    assert_eq!(op.attribute(), "");
    let op = Operator::string_concat("prefix-");
    assert_eq!(values(&op), vals!["prefix-"]);
    assert_eq!(op.value(), Some(Value::from("prefix-")));
    let op = Operator::string_replace("old", "new");
    assert_eq!(op.method(), OperatorType::StringReplace);
    assert_eq!(values(&op), vals!["old", "new"]);
    assert_eq!(op.value(), Some(Value::from("old")));
}

#[test]
fn test_math_operators() {
    let op = Operator::multiply(2.5, Some(100.into()));
    assert_eq!(op.method(), OperatorType::Multiply);
    assert_eq!(values(&op), vals![2.5, 100]);
    assert_eq!(op.value(), Some(Value::Float(2.5)));
    assert_eq!(values(&Operator::multiply(3, None)), vals![3]);
    let op = Operator::divide(2, Some(1.into())).unwrap();
    assert_eq!(op.method(), OperatorType::Divide);
    assert_eq!(values(&op), vals![2, 1]);
    assert_eq!(op.value(), Some(Value::Int(2)));
    assert_eq!(values(&Operator::divide(4, None).unwrap()), vals![4]);
    let op = Operator::modulo(3).unwrap();
    assert_eq!(op.method(), OperatorType::Modulo);
    assert_eq!(values(&op), vals![3]);
    assert_eq!(op.value(), Some(Value::Int(3)));
    let op = Operator::power(2, Some(1000.into()));
    assert_eq!(op.method(), OperatorType::Power);
    assert_eq!(values(&op), vals![2, 1000]);
    assert_eq!(op.value(), Some(Value::Int(2)));
    assert_eq!(values(&Operator::power(3, None)), vals![3]);
}

#[test]
fn test_divide_by_zero() {
    assert_error(Operator::divide(0, None), "Division by zero is not allowed");
}

#[test]
fn test_modulo_by_zero() {
    assert_error(Operator::modulo(0), "Modulo by zero is not allowed");
}

#[test]
fn test_boolean_operator() {
    let op = Operator::toggle();
    assert_eq!(op.method(), OperatorType::Toggle);
    assert!(values(&op).is_empty());
    assert_eq!(op.value(), None);
}

#[test]
fn test_utility_operators() {
    let op = Operator::date_set_now();
    assert_eq!(op.method(), OperatorType::DateSetNow);
    assert!(values(&op).is_empty());
    assert_eq!(op.value(), None);
}

#[test]
fn test_new_operator_parsing() {
    let cases = [
        (OperatorType::StringConcat, "title", vals![" - Updated"]),
        (OperatorType::StringConcat, "subtitle", vals![" - Updated"]),
        (OperatorType::StringReplace, "content", vals!["old", "new"]),
        (OperatorType::Multiply, "score", vals![2, 100]),
        (OperatorType::Divide, "rating", vals![2, 1]),
        (OperatorType::Modulo, "remainder", vals![3]),
        (OperatorType::Power, "exponential", vals![2, 1000]),
        (OperatorType::Toggle, "active", vec![]),
        (OperatorType::DateSetNow, "updated", vec![]),
    ];
    for (method, attribute, items) in cases {
        let op = Operator::parse_operator(&expected_array(method, attribute, items.clone())).unwrap();
        assert_eq!(op.method().as_str(), method.as_str());
        assert_eq!(op.attribute(), attribute);
        assert_eq!(values(&op), items);
        let parsed = Operator::parse(op.to_string().unwrap()).unwrap();
        assert_eq!(op.method(), parsed.method());
        assert_eq!(op.attribute(), parsed.attribute());
        assert_eq!(values(&op), values(&parsed));
    }
}

#[test]
fn test_operator_cloning() {
    let operators = [
        Operator::string_concat(" suffix"),
        Operator::string_replace("old", "new"),
        Operator::multiply(2, Some(100.into())),
        Operator::divide(2, Some(1.into())).unwrap(),
        Operator::modulo(3).unwrap(),
        Operator::power(2, Some(100.into())),
        Operator::toggle(),
        Operator::date_set_now(),
    ];
    for op in operators {
        let mut cloned = op.clone();
        assert_eq!(op.method(), cloned.method());
        assert_eq!(op.attribute(), cloned.attribute());
        assert_eq!(values(&op), values(&cloned));
        cloned.set_attribute("different");
        assert_ne!(op.attribute(), cloned.attribute());
    }
}

#[test]
fn test_operator_edge_cases() {
    assert_eq!(Operator::multiply(0, None).value(), Some(Value::Int(0)));
    assert_eq!(values(&Operator::divide(0.5, Some(0.1.into())).unwrap()), vals![0.5, 0.1]);
    assert_eq!(Operator::string_concat("").value(), Some(Value::from("")));
    assert_eq!(values(&Operator::string_replace("same", "same")), vals!["same", "same"]);
    assert_eq!(Operator::modulo(1.5).unwrap().value(), Some(Value::Float(1.5)));
    assert_eq!(Operator::power(0, None).value(), Some(Value::Int(0)));
}

#[test]
fn test_power_operator_with_max() {
    let op = Operator::power(2, Some(1000.into()));
    assert_eq!(op.method(), OperatorType::Power);
    assert_eq!(values(&op), vals![2, 1000]);
    assert_eq!(values(&Operator::power(3, None)), vals![3]);
}

#[test]
fn test_operator_type_validation() {
    assert_kinds(&Operator::power(2, None), true, false, false, false, false);
    let modulo = Operator::modulo(5).unwrap();
    assert!(modulo.is_numeric_operation());
    assert!(!modulo.is_array_operation());
}

#[test]
fn test_array_unique() {
    let op = Operator::array_unique();
    assert_eq!(op.method(), OperatorType::ArrayUnique);
    assert_eq!(op.attribute(), "");
    assert!(values(&op).is_empty());
    assert_eq!(op.value(), None);
    assert_kinds(&op, false, true, false, false, false);
}

#[test]
fn test_array_unique_serialization() {
    let mut op = Operator::array_unique();
    op.set_attribute("tags");
    assert_serializes(&op, &expected_array(OperatorType::ArrayUnique, "tags", vec![]));
}

#[test]
fn test_array_unique_parsing() {
    assert_parses(OperatorType::ArrayUnique, "items", vec![]);
}

#[test]
fn test_array_unique_cloning() {
    let mut op1 = Operator::array_unique();
    op1.set_attribute("original");
    let mut op2 = op1.clone();
    assert_eq!(op1.method(), op2.method());
    assert_eq!(op1.attribute(), op2.attribute());
    assert_eq!(values(&op1), values(&op2));
    op2.set_attribute("cloned");
    assert_eq!(op1.attribute(), "original");
    assert_eq!(op2.attribute(), "cloned");
}

#[test]
fn test_array_intersect() {
    let op = Operator::array_intersect(["a", "b", "c"]);
    assert_eq!(op.method(), OperatorType::ArrayIntersect);
    assert_eq!(op.attribute(), "");
    assert_eq!(values(&op), vals!["a", "b", "c"]);
    assert_eq!(op.value(), Some(Value::from("a")));
    assert_kinds(&op, false, true, false, false, false);
}

#[test]
fn test_array_intersect_edge_cases() {
    let op = Operator::array_intersect(Vec::<Value>::new());
    assert!(values(&op).is_empty());
    assert_eq!(op.value(), None);
    let op = Operator::array_intersect([1i64, 2, 3]);
    assert_eq!(values(&op), vals![1, 2, 3]);
    assert_eq!(op.value(), Some(Value::Int(1)));
    let mixed = vec![Value::from("string"), Value::Int(42), Value::Bool(true), Value::Null];
    let op = Operator::array_intersect(mixed.clone());
    assert_eq!(values(&op), mixed);
    assert_eq!(op.value(), Some(Value::from("string")));
    let nested = vec![list(vals!["nested"]), list(vals!["array"])];
    assert_eq!(values(&Operator::array_intersect(nested.clone())), nested);
}

#[test]
fn test_array_intersect_serialization() {
    let mut op = Operator::array_intersect(["x", "y", "z"]);
    op.set_attribute("common");
    assert_serializes(&op, &expected_array(OperatorType::ArrayIntersect, "common", vals!["x", "y", "z"]));
}

#[test]
fn test_array_intersect_parsing() {
    assert_parses(OperatorType::ArrayIntersect, "allowed", vals!["admin", "user"]);
}

#[test]
fn test_array_diff() {
    let op = Operator::array_diff(["remove", "these"]);
    assert_eq!(op.method(), OperatorType::ArrayDiff);
    assert_eq!(op.attribute(), "");
    assert_eq!(values(&op), vals!["remove", "these"]);
    assert_eq!(op.value(), Some(Value::from("remove")));
    assert_kinds(&op, false, true, false, false, false);
}

#[test]
fn test_array_diff_edge_cases() {
    let op = Operator::array_diff(Vec::<Value>::new());
    assert!(values(&op).is_empty());
    assert_eq!(op.value(), None);
    let op = Operator::array_diff(["only-one"]);
    assert_eq!(values(&op), vals!["only-one"]);
    assert_eq!(op.value(), Some(Value::from("only-one")));
    assert_eq!(values(&Operator::array_diff([10i64, 20, 30])), vals![10, 20, 30]);
    let mixed = vec![Value::Bool(false), Value::Int(0), Value::from("")];
    assert_eq!(values(&Operator::array_diff(mixed.clone())), mixed);
}

#[test]
fn test_array_diff_serialization() {
    let mut op = Operator::array_diff(["spam", "unwanted"]);
    op.set_attribute("blocklist");
    assert_serializes(&op, &expected_array(OperatorType::ArrayDiff, "blocklist", vals!["spam", "unwanted"]));
}

#[test]
fn test_array_diff_parsing() {
    assert_parses(OperatorType::ArrayDiff, "exclude", vals!["bad", "invalid"]);
}

#[test]
fn test_array_filter() {
    let op = Operator::array_filter("equals", "active");
    assert_eq!(op.method(), OperatorType::ArrayFilter);
    assert_eq!(op.attribute(), "");
    assert_eq!(values(&op), vals!["equals", "active"]);
    assert_eq!(op.value(), Some(Value::from("equals")));
    assert_kinds(&op, false, true, false, false, false);
}

#[test]
fn test_array_filter_conditions() {
    assert_eq!(values(&Operator::array_filter("notEquals", "inactive")), vals!["notEquals", "inactive"]);
    assert_eq!(values(&Operator::array_filter("greaterThan", 100)), vals!["greaterThan", 100]);
    assert_eq!(values(&Operator::array_filter("lessThan", 50)), vals!["lessThan", 50]);
    assert_eq!(values(&Operator::array_filter("null", Value::Null)), vec![Value::from("null"), Value::Null]);
    assert_eq!(values(&Operator::array_filter("notNull", Value::Null)), vec![Value::from("notNull"), Value::Null]);
}

#[test]
fn test_array_filter_edge_cases() {
    assert_eq!(values(&Operator::array_filter("equals", true)), vals!["equals", true]);
    assert_eq!(values(&Operator::array_filter("equals", 0)), vals!["equals", 0]);
    assert_eq!(values(&Operator::array_filter("equals", "")), vals!["equals", ""]);
    let nested = list(vals!["nested", "array"]);
    assert_eq!(values(&Operator::array_filter("equals", nested.clone())), vec![Value::from("equals"), nested]);
}

#[test]
fn test_array_filter_serialization() {
    let mut op = Operator::array_filter("greaterThan", 100);
    op.set_attribute("scores");
    assert_serializes(&op, &expected_array(OperatorType::ArrayFilter, "scores", vals!["greaterThan", 100]));
}

#[test]
fn test_array_filter_parsing() {
    assert_parses(OperatorType::ArrayFilter, "ratings", vals!["lessThan", 3]);
}

#[test]
fn test_date_add_days() {
    let op = Operator::date_add_days(7);
    assert_eq!(op.method(), OperatorType::DateAddDays);
    assert_eq!(op.attribute(), "");
    assert_eq!(values(&op), vals![7]);
    assert_eq!(op.value(), Some(Value::Int(7)));
    assert_kinds(&op, false, false, false, false, true);
}

#[test]
fn test_date_add_days_edge_cases() {
    for days in [0, -5, 365, -1000] {
        let op = Operator::date_add_days(days);
        assert_eq!(values(&op), vals![days]);
        assert_eq!(op.value(), Some(Value::Int(days)));
    }
}

#[test]
fn test_date_add_days_serialization() {
    let mut op = Operator::date_add_days(30);
    op.set_attribute("expiresAt");
    assert_serializes(&op, &expected_array(OperatorType::DateAddDays, "expiresAt", vals![30]));
}

#[test]
fn test_date_add_days_parsing() {
    assert_parses(OperatorType::DateAddDays, "scheduledFor", vals![14]);
}

#[test]
fn test_date_add_days_cloning() {
    let mut op1 = Operator::date_add_days(10);
    op1.set_attribute("date1");
    let mut op2 = op1.clone();
    assert_eq!(op1.method(), op2.method());
    assert_eq!(op1.attribute(), op2.attribute());
    assert_eq!(values(&op1), values(&op2));
    op2.set_values(vals![20]);
    assert_eq!(values(&op1), vals![10]);
    assert_eq!(values(&op2), vals![20]);
}

#[test]
fn test_date_sub_days() {
    let op = Operator::date_sub_days(3);
    assert_eq!(op.method(), OperatorType::DateSubDays);
    assert_eq!(op.attribute(), "");
    assert_eq!(values(&op), vals![3]);
    assert_eq!(op.value(), Some(Value::Int(3)));
    assert_kinds(&op, false, false, false, false, true);
}

#[test]
fn test_date_sub_days_edge_cases() {
    for days in [0, 1, 90, 10000] {
        let op = Operator::date_sub_days(days);
        assert_eq!(values(&op), vals![days]);
        assert_eq!(op.value(), Some(Value::Int(days)));
    }
}

#[test]
fn test_date_sub_days_serialization() {
    let mut op = Operator::date_sub_days(7);
    op.set_attribute("reminderDate");
    assert_serializes(&op, &expected_array(OperatorType::DateSubDays, "reminderDate", vals![7]));
}

#[test]
fn test_date_sub_days_parsing() {
    assert_parses(OperatorType::DateSubDays, "dueDate", vals![5]);
}

#[test]
fn test_date_sub_days_cloning() {
    let mut op1 = Operator::date_sub_days(15);
    op1.set_attribute("date1");
    let mut op2 = op1.clone();
    assert_eq!(values(&op1), values(&op2));
    op2.set_values(vals![25]);
    assert_eq!(values(&op1), vals![15]);
    assert_eq!(values(&op2), vals![25]);
}

#[test]
fn test_is_method_for_new_operators() {
    use OperatorType::*;
    for method in [ArrayUnique, ArrayIntersect, ArrayDiff, ArrayFilter, DateAddDays, DateSubDays] {
        assert!(Operator::is_method(method.as_str()));
    }
}

#[test]
fn test_extract_operators_with_new_operators() {
    let extracted = Operator::extract_operators(data(vec![
        ("uniqueTags", Operator::array_unique().into()),
        ("commonItems", Operator::array_intersect(["a", "b"]).into()),
        ("filteredList", Operator::array_diff(["spam"]).into()),
        ("activeUsers", Operator::array_filter("equals", true).into()),
        ("expiry", Operator::date_add_days(30).into()),
        ("reminder", Operator::date_sub_days(7).into()),
        ("name", Value::from("Regular value")),
    ]));
    assert_eq!(extracted.operators.len(), 6);
    let m = |k: &str| operator(&extracted, k).method();
    assert_eq!(m("uniqueTags"), OperatorType::ArrayUnique);
    assert_eq!(m("commonItems"), OperatorType::ArrayIntersect);
    assert_eq!(m("filteredList"), OperatorType::ArrayDiff);
    assert_eq!(m("activeUsers"), OperatorType::ArrayFilter);
    assert_eq!(m("expiry"), OperatorType::DateAddDays);
    assert_eq!(m("reminder"), OperatorType::DateSubDays);
    assert_eq!(extracted.updates, data(vec![("name", Value::from("Regular value"))]));
}

#[test]
fn test_clone_deep_copies_nested_operator_values() {
    let nested = Operator::increment(1, None);
    let parent = Operator::new(OperatorType::ArrayAppend, "items", vec![nested.clone().into(), Value::from("plain")]);
    let mut cloned = parent.clone();
    let first = |op: &Operator| utopia_database::value::as_operator(&values(op)[0]).cloned().unwrap();
    assert_eq!(first(&cloned).method(), nested.method());
    assert_eq!(values(&first(&cloned)), values(&nested));
    // Change the clone's nested operator: the original's stays.
    let mut items = values(&cloned);
    if let Value::Ext(utopia_database::value::Ext::Operator(o)) = &mut items[0] {
        o.set_method(OperatorType::Decrement);
    }
    cloned.set_values(items);
    assert_eq!(first(&cloned).method(), OperatorType::Decrement);
    assert_eq!(first(&parent).method(), OperatorType::Increment);
}

#[test]
fn test_is_method_with_operator_type_enum() {
    use OperatorType::*;
    for method in [Increment, Decrement, ArrayAppend, Toggle, DateSetNow] {
        assert!(Operator::is_method(method.as_str()));
    }
}

/// `getValues()` reads a numeric string `power` exponent as its number.
#[test]
fn test_power_exponent_string_reads_as_number() {
    let op = Operator::new(OperatorType::Power, "x", vals!["2e1"]);
    assert_eq!(values(&op), vals![20.0]);
    assert_eq!(op.raw_values().get(&ArrayKey::Int(0)), Some(&Value::from("2e1")));
    let op = Operator::new(OperatorType::Increment, "x", vals!["2"]);
    assert_eq!(values(&op), vals!["2"]);
    assert!(Operator::divide("", None).is_ok());
    assert!(Operator::divide("0.0", None).is_err());
}
