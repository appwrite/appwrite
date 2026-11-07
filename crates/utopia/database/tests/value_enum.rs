//! A backed enum case held as a value, as PHP stores one where the library
//! takes `ColumnType|string` and the like: it survives a query value, reads
//! as its backing value, and an attribute document typed by a `ColumnType`
//! case, filtered by `Filter` cases or sided by a `RelationshipSide` case
//! reads as the strings would (`Attribute.php` `resolveType()` line 587,
//! `Filter::names()`).

use utopia_database::value::{self, Array, ArrayKey, EnumValue, Ext, Str, Value};
use utopia_database::{Attribute, Document};
use utopia_query::schema::ColumnType;

fn case(class: &str, name: &str, backing: &str) -> Value {
    Value::Ext(Ext::Enum(Box::new(EnumValue {
        class: class.to_owned(),
        case: name.to_owned(),
        backing: Value::Str(Str::from(backing)),
    })))
}

fn document(pairs: Vec<(&str, Value)>) -> Document {
    Document::new(pairs.into_iter().map(|(k, v)| (ArrayKey::Str(Str::from(k)), v)).collect::<Array>()).unwrap()
}

#[test]
fn an_enum_case_round_trips_through_a_query_value() {
    let value = case("Utopia\\Query\\Schema\\ColumnType", "String", "string");
    assert_eq!(value::from_query(&value::to_query(&value)), value);
    assert_eq!(value::backing(&value), &Value::Str(Str::from("string")));
    assert_eq!(value.debug_type(), "Utopia\\Query\\Schema\\ColumnType");
}

#[test]
fn an_attribute_typed_and_filtered_by_enum_cases_reads_as_strings() {
    let attribute = Attribute::from_document(&document(vec![
        ("$id", Value::Str(Str::from("when"))),
        ("type", case("Utopia\\Query\\Schema\\ColumnType", "Datetime", "datetime")),
        ("filters", Value::Array(std::iter::once(case("Utopia\\Database\\Filter", "Datetime", "datetime")).collect())),
    ]))
    .unwrap();
    assert_eq!(attribute.kind, ColumnType::Datetime);
    assert_eq!(attribute.filters, ["datetime"]);
}

#[test]
fn an_attribute_typed_by_another_enum_is_refused_by_its_class() {
    let error = Attribute::from_document(&document(vec![
        ("$id", Value::Str(Str::from("side"))),
        ("type", case("Utopia\\Database\\RelationshipSide", "Parent", "parent")),
    ]))
    .unwrap_err();
    assert_eq!(error.message(), "Attribute type must be a string, Utopia\\Database\\RelationshipSide given");
}
