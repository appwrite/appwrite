//! PHP: `tests/unit/Validator/RolesTest.php`, `SpatialTest.php`,
//! `SpatialTypeTest.php` and `VectorTest.php` (utopia-php/database 8.1.3).

mod common;

use common::{s, val};
use utopia_database::validator::roles::{ROLES, Roles};
use utopia_database::validator::spatial::Spatial;
use utopia_database::validator::vector::Vector;
use utopia_database::value::{Array, Value};
use utopia_database::{Role, id};

fn roles() -> Roles {
    Roles::new(0, ROLES.iter().map(|r| r.to_string()).collect())
}

fn list(items: &[String]) -> Value {
    Value::Array(Array::from_list(items.iter().map(|r| s(r)).collect()))
}

#[test]
fn valid_role() {
    let object = roles();
    let team = id::custom("696f34ea003d48edab8e");
    for role in [
        Role::users(""),
        Role::users("verified"),
        Role::users("unverified"),
        Role::team(team.clone(), ""),
        Role::team(team.clone(), "project-696f34ea003d48edab8e-owner"),
        Role::team(team.clone(), "project-696f34ea003d48edab8e"),
        Role::label("vip"),
    ] {
        assert!(object.is_valid(&list(&[role.to_string()])), "{role}: {}", object.description());
    }
}

#[test]
fn not_an_array() {
    let object = roles();
    assert!(!object.is_valid(&s("not an array")));
    assert_eq!(object.description(), "Roles must be an array of strings.");
}

#[test]
fn exceed_length() {
    let object = Roles::new(2, ROLES.iter().map(|r| r.to_string()).collect());
    let users = Role::users("").to_string();
    assert!(!object.is_valid(&list(&[users.clone(), users.clone(), users])));
    assert_eq!(object.description(), "You can only provide up to 2 roles.");
}

#[test]
fn not_all_strings() {
    let object = roles();
    assert!(!object.is_valid(&Value::Array(Array::from_list(vec![s(&Role::users("").to_string()), Value::Int(123)]))));
    assert_eq!(object.description(), "Every role must be of type string.");
}

#[test]
fn obsolete_wildcard_role() {
    let object = roles();
    assert!(!object.is_valid(&val(r#"["*"]"#)));
    assert_eq!(object.description(), "Wildcard role \"*\" has been replaced. Use \"any\" instead.");
}

#[test]
fn obsolete_role_prefix() {
    let object = roles();
    assert!(!object.is_valid(&list(&["read(\"role:123\")".to_owned()])));
    assert_eq!(
        object.description(),
        "Roles using the \"role:\" prefix have been removed. Use \"users\", \"guests\", or \"any\" instead."
    );
}

#[test]
fn disallowed_roles() {
    let object = Roles::new(0, vec!["users".to_owned()]);
    assert!(!object.is_valid(&list(&[Role::any().to_string()])));
    assert_eq!(object.description(), "Role \"any\" is not allowed. Must be one of: users.");
}

#[test]
fn labels() {
    let object = roles();
    assert!(object.is_valid(&val(r#"["label:123"]"#)));
    assert!(!object.is_valid(&val(r#"["label:not-alphanumeric"]"#)));
}

/// `unparseableRoles()` and `misplacedIdsAndDimensions()`.
#[test]
fn unparseable_and_misplaced_roles() {
    let cases = [
        ("users/", "Dimension must not be empty"),
        ("users/verified/extra", "Only one dimension can be provided"),
        ("any:abc", "Role \"any\" can not have an ID value."),
        ("any/verified", "Role \"any\" can not have a dimension value."),
        ("guests/verified", "Role \"guests\" can not have a dimension value."),
        ("users/foo", "Role \"users\" dimension value is invalid. Must be one of: verified, unverified."),
    ];
    for (role, message) in cases {
        let object = roles();
        assert!(!object.is_valid(&list(&[role.to_owned()])), "{role}");
        assert_eq!(object.description(), message);
    }
}

#[test]
fn dimension_among_the_options() {
    let object = roles();
    assert!(object.is_valid(&val(r#"["users/verified"]"#)));
    assert!(object.is_valid(&val(r#"["user:abc/unverified"]"#)));
}

#[test]
fn spatial_valid_point() {
    let validator = Spatial::new("point");
    assert!(validator.is_valid(&val("[10, 20]")));
    assert!(validator.is_valid(&val("[0, 0]")));
    assert!(validator.is_valid(&val("[-180.0, 90.0]")));

    assert!(!validator.is_valid(&val("[10]")));
    assert!(!validator.is_valid(&val(r#"[10, "a"]"#)));
    assert!(!validator.is_valid(&val("[[10, 20]]")));
}

#[test]
fn spatial_valid_line_string() {
    let validator = Spatial::new("linestring");
    assert!(validator.is_valid(&val("[[0, 0], [1, 1]]")));
    assert!(validator.is_valid(&val("[[10, 10], [20, 20], [30, 30]]")));

    assert!(!validator.is_valid(&val("[[10, 10]]")));
    assert!(!validator.is_valid(&val("[[10, 10], [20]]")));
    assert!(!validator.is_valid(&val(r#"[[10, 10], ["x", "y"]]"#)));
}

#[test]
fn spatial_valid_polygon() {
    let validator = Spatial::new("polygon");
    assert!(validator.is_valid(&val("[[0, 0], [0, 1], [1, 1], [1, 0], [0, 0]]")));
    assert!(
        validator
            .is_valid(&val("[[[0, 0], [0, 4], [4, 4], [4, 0], [0, 0]], [[1, 1], [1, 2], [2, 2], [2, 1], [1, 1]]]"))
    );

    assert!(!validator.is_valid(&val("[]")));
    assert!(!validator.is_valid(&val("[[0, 0], [1, 1], [2, 2]]")));
    assert!(!validator.is_valid(&val("[[[0, 0], [1, 1], [1, 0]]]")));
    assert!(!validator.is_valid(&val(r#"[[[0, 0], [1, 1], [1, "a"], [0, 0]]]"#)));
}

#[test]
fn spatial_wkt_strings() {
    assert!(Spatial::is_wkt_string(b"POINT(1 2)"));
    assert!(Spatial::is_wkt_string(b"LINESTRING(0 0,1 1)"));
    assert!(Spatial::is_wkt_string(b"POLYGON((0 0,1 0,1 1,0 1,0 0))"));

    assert!(!Spatial::is_wkt_string(b"CIRCLE(0 0,1)"));
    assert!(!Spatial::is_wkt_string(b"POINT1(1 2)"));
}

#[test]
fn spatial_invalid_coordinate() {
    let validator = Spatial::new("point");
    assert!(!validator.is_valid(&val("[200, 10]")));
    assert!(validator.description().contains("Longitude"), "{}", validator.description());

    let validator = Spatial::new("point");
    assert!(!validator.is_valid(&val("[10, -100]")));
    assert!(validator.description().contains("Latitude"), "{}", validator.description());

    let validator = Spatial::new("linestring");
    assert!(!validator.is_valid(&val("[[0, 0], [181, 45]]")));
    assert!(validator.description().contains("Invalid coordinates"), "{}", validator.description());

    let validator = Spatial::new("polygon");
    assert!(!validator.is_valid(&val("[[[0, 0], [1, 1], [190, 5], [0, 0]]]")));
    assert!(validator.description().contains("Invalid coordinates"), "{}", validator.description());
}

/// `SpatialTypeTest::nonSpatialTypes()`.
#[test]
fn an_array_for_a_validator_of_a_non_spatial_type_is_an_unknown_spatial_type() {
    for kind in ["string", "vector", "", "circle"] {
        let validator = Spatial::new(kind);
        assert!(!validator.is_valid(&val("[1.0, 2.0]")));
        assert!(
            validator.description().ends_with(&format!("Unknown spatial type: {kind}")),
            "{}",
            validator.description()
        );
        assert!(validator.is_valid(&Value::Null), "null stays valid whatever the type");
    }
}

#[test]
fn an_array_for_a_point_validator_is_checked_as_a_point() {
    let validator = Spatial::new("point");
    assert!(validator.is_valid(&val("[1.0, 2.0]")), "{}", validator.description());
    assert!(!validator.description().contains("Unknown spatial type"));
}

#[test]
fn vector() {
    let validator = Vector::new(3);
    assert!(validator.is_valid(&val("[1.0, 2.0, 3.0]")));
    assert!(validator.is_valid(&val("[0, 0, 0]")));
    assert!(validator.is_valid(&val("[-1.5, 0.0, 2.5]")));

    assert!(!validator.is_valid(&val("[1.0, 2.0]")));
    assert!(!validator.is_valid(&val("[1.0, 2.0, 3.0, 4.0]")));
    assert!(!validator.is_valid(&s("not an array")));
    assert!(!validator.is_valid(&val(r#"["1", "2", "3"]"#)));
    assert!(!validator.is_valid(&val(r#"[1.0, "not numeric", 3.0]"#)));
    assert!(!validator.is_valid(&val("[1.0, null, 3.0]")));
    assert!(!validator.is_valid(&val("[]")));
    assert!(!validator.is_valid(&val(r#"{"x": 1.0, "y": 2.0, "z": 3.0}"#)));
    assert!(!validator.is_valid(&val("[1.0, true, 3.0]")));
}

#[test]
fn vector_with_different_dimensions() {
    let validator1 = Vector::new(1);
    assert!(validator1.is_valid(&val("[5.0]")));
    assert!(!validator1.is_valid(&val("[1.0, 2.0]")));

    let validator5 = Vector::new(5);
    assert!(validator5.is_valid(&val("[1.0, 2.0, 3.0, 4.0, 5.0]")));
    assert!(!validator5.is_valid(&val("[1.0, 2.0, 3.0]")));

    let validator128 = Vector::new(128);
    let ones = |n: usize| Value::Array(Array::from_list(vec![Value::Float(1.0); n]));
    assert!(validator128.is_valid(&ones(128)));
    assert!(!validator128.is_valid(&ones(127)));
}

#[test]
fn vector_description() {
    assert_eq!(Vector::new(3).description(), "Value must be an array of 3 numeric values");
    assert_eq!(Vector::new(256).description(), "Value must be an array of 256 numeric values");
}

#[test]
fn vector_type() {
    let validator = Vector::new(3);
    assert_eq!(validator.type_name(), "array");
    assert!(!utopia_validators::Validator::is_array(&validator));
}
