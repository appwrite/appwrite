//! PHP: `tests/unit/Validator/AttributeDefaultTypeTest.php`
//! (utopia-php/database 8.1.3).

mod common;

use common::{P, val};
use utopia_database::adapter::Feature;
use utopia_database::attribute::Attribute;
use utopia_database::capability::Capability;
use utopia_database::validator::attribute_definition::AttributeDefinition;
use utopia_database::value::Value;

fn validator(vectors: bool, spatial: bool) -> AttributeDefinition {
    let mut capabilities = vec![Capability::Objects];
    if vectors {
        capabilities.push(Capability::Vectors);
    }
    let features = if spatial { vec![Feature::Spatial] } else { vec![] };
    AttributeDefinition::new(vec![], P { capabilities, features, ..P::default() }.build())
}

fn from_array(json: &str) -> Attribute {
    match val(json) {
        Value::Array(a) => Attribute::from_array(&a).unwrap(),
        other => panic!("{other:?}"),
    }
}

/// `refusal()`: the message of the error `checkDefaultValue()` raises,
/// which is also the description.
fn refusal(validator: &AttributeDefinition, attribute: &Attribute) -> String {
    match validator.check_default_value(attribute) {
        Err(error) => {
            assert!(error.is_a(utopia_database::Kind::Database));
            assert_eq!(error.message(), validator.description());
            error.message().to_owned()
        }
        Ok(_) => panic!("A scalar default on {} must be refused", attribute.kind.as_str()),
    }
}

/// `scalarDefaultsOfTypesWithoutADefaultRule()`.
const SCALARS: [(&str, &str); 6] = [
    ("object", r#""x""#),
    ("point", r#""POINT(1 2)""#),
    ("linestring", "5"),
    ("polygon", "true"),
    ("id", r#""x""#),
    ("relationship", r#""x""#),
];

#[test]
fn a_scalar_default_of_a_type_without_a_default_rule_is_an_unknown_type() {
    for (kind, default) in SCALARS {
        let validator = validator(false, false);
        let attribute = from_array(&format!(
            r#"{{"key": "value", "type": "{kind}", "default": {default},
                "options": {{"relatedCollection": "others", "relationType": "oneToOne", "side": "parent"}}}}"#
        ));
        let message = refusal(&validator, &attribute);
        assert!(message.starts_with(&format!("Unknown attribute type: {kind}. Must be one of ")), "{message}");
        assert!(message.contains("string"));
        assert!(message.contains("relationship"));
        assert!(!message.contains("vector"));
        assert!(!message.contains("point,"));
    }
}

#[test]
fn the_listed_types_follow_the_vector_and_spatial_support() {
    let message = refusal(&validator(true, true), &from_array(r#"{"key": "value", "type": "object", "default": "x"}"#));
    assert!(message.contains("vector"));
    for spatial in ["point", "linestring", "polygon"] {
        assert!(message.contains(spatial));
    }

    let vectors_only = refusal(&validator(true, false), &Attribute::id("value").default("x"));
    assert!(vectors_only.contains("vector"));
    assert!(!vectors_only.contains("polygon"));
}

#[test]
fn an_array_default_of_a_spatial_or_object_type_is_not_checked_item_by_item() {
    let validator = validator(true, true);
    assert!(validator.check_default_value(&Attribute::object("value").default(val(r#"{"nested": "x"}"#))).unwrap());
}
