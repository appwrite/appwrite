//! PHP: `tests/unit/Validator/AttributeDefinitionFiltersTest.php`
//! (utopia-php/database 8.1.3).

mod common;

use common::{P, dv};
use utopia_database::attribute::Attribute;
use utopia_database::filter::Filter;
use utopia_database::validator::attribute_definition::AttributeDefinition;
use utopia_database::value::{Array, Value};

/// PHP lists the filters as enum cases (`[Filter::Datetime]`); a Rust
/// value holds their names.
#[test]
fn a_declaration_listing_its_filters_as_enum_cases_is_valid() {
    let validator = AttributeDefinition::new(vec![], P::default().build());
    let mut declaration = Attribute::datetime("publishedAt").to_document().unwrap();
    declaration.put("filters", Value::Array(Array::from_list(vec![Value::from(Filter::Datetime.as_str())])));
    assert!(validator.is_valid(&dv(declaration)).unwrap(), "{}", validator.description());
}
