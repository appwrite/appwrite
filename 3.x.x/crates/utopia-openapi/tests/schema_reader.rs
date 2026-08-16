//! Ports `tests/Schema/ReaderTest.php`.

use serde_json::json;
use utopia_openapi::model::{AdditionalProperties, Composition, JsonNumberOrInt, Schema};
use utopia_openapi::{Dialect, InvalidSpecification, Json, OpenApiError, SchemaReader, Version};

fn reader(version: Version) -> SchemaReader {
    SchemaReader::new(Dialect::for_version(version))
}

fn j(v: serde_json::Value) -> Json {
    Json::from_serde(v)
}

#[test]
fn boolean_schemas_are_read_only_under_the_three_one_dialect() {
    let r = reader(Version::V31);
    assert!(matches!(
        r.read(&Json::Bool(true), "#/x").unwrap(),
        Schema::Any(_)
    ));
    assert!(matches!(
        r.read(&Json::Bool(false), "#/x").unwrap(),
        Schema::Never(_)
    ));
    let err = reader(Version::V30)
        .read(&Json::Bool(true), "#/x")
        .unwrap_err();
    assert!(matches!(
        err,
        OpenApiError::Invalid(InvalidSpecification(_))
    ));
}

#[test]
fn type_arrays_are_read_only_under_the_three_one_dialect() {
    let r = reader(Version::V31);
    let nullable = r
        .read(&j(json!({"type": ["string", "null"]})), "#/x")
        .unwrap();
    assert!(matches!(nullable, Schema::String(_)));
    assert!(nullable.nullable());

    let union = r
        .read(&j(json!({"type": ["string", "integer", "null"]})), "#/x")
        .unwrap();
    let c = union.as_composite().unwrap();
    assert_eq!(c.composition, Some(Composition::AnyOf));
    assert_eq!(c.schemas.len(), 2);
    assert!(union.nullable());

    let err = reader(Version::V30)
        .read(&j(json!({"type": ["string", "null"]})), "#/x")
        .unwrap_err();
    assert!(matches!(err, OpenApiError::Invalid(_)));
}

#[test]
fn const_becomes_a_single_value_enum_only_under_the_three_one_dialect() {
    let v31 = reader(Version::V31)
        .read(&j(json!({"type": "string", "const": "pets"})), "#/x")
        .unwrap();
    assert_eq!(v31.enum_values(), &[Json::String("pets".into())]);

    let v30 = reader(Version::V30)
        .read(&j(json!({"type": "string", "const": "pets"})), "#/x")
        .unwrap();
    assert!(v30.enum_values().is_empty());
}

#[test]
fn an_explicit_enum_wins_over_const() {
    let schema = reader(Version::V31)
        .read(
            &j(json!({"type": "string", "const": "c", "enum": ["a", "b"]})),
            "#/x",
        )
        .unwrap();
    assert_eq!(
        schema.enum_values(),
        &[Json::String("a".into()), Json::String("b".into())]
    );
}

#[test]
fn nullability_is_read_from_either_keyword() {
    assert!(reader(Version::V30)
        .read(&j(json!({"type": "string", "nullable": true})), "#/x")
        .unwrap()
        .nullable());
    assert!(reader(Version::V2)
        .read(&j(json!({"type": "string", "x-nullable": true})), "#/x")
        .unwrap()
        .nullable());
    assert!(!reader(Version::V30)
        .read(&j(json!({"type": "string"})), "#/x")
        .unwrap()
        .nullable());
}

#[test]
fn references_are_left_unexpanded_so_recursive_graphs_terminate() {
    let schema = reader(Version::V31)
        .read(&j(json!({"$ref": "#/components/schemas/Pet"})), "#/x")
        .unwrap();
    assert_eq!(
        schema.as_reference().unwrap().reference,
        "#/components/schemas/Pet"
    );
}

#[test]
fn composition_and_not() {
    let r = reader(Version::V30);
    for composition in [Composition::OneOf, Composition::AnyOf, Composition::AllOf] {
        let schema = r
            .read(
                &j(json!({composition.as_str(): [{"type": "string"}, {"type": "integer"}]})),
                "#/x",
            )
            .unwrap();
        let c = schema.as_composite().unwrap();
        assert_eq!(c.composition, Some(composition));
        assert_eq!(c.schemas.len(), 2);
    }
    let negated = r
        .read(&j(json!({"not": {"type": "string"}})), "#/x")
        .unwrap();
    let c = negated.as_composite().unwrap();
    assert!(c.composition.is_none());
    assert!(matches!(c.not, Some(Schema::String(_))));
}

#[test]
fn discriminator_is_read_from_both_the_string_and_object_forms() {
    let r = reader(Version::V30);
    let from_string = r
        .read(&j(json!({"oneOf": [], "discriminator": "kind"})), "#/x")
        .unwrap();
    let d = from_string
        .as_composite()
        .unwrap()
        .discriminator
        .as_ref()
        .unwrap();
    assert_eq!(d.property_name, "kind");
    assert!(d.mapping.is_empty());

    let from_object = r
        .read(
            &j(json!({
                "oneOf": [],
                "discriminator": {"propertyName": "kind", "mapping": {"cat": "#/components/schemas/Cat"}}
            })),
            "#/x",
        )
        .unwrap();
    let d = from_object
        .as_composite()
        .unwrap()
        .discriminator
        .as_ref()
        .unwrap();
    assert_eq!(
        d.mapping.get("cat").map(String::as_str),
        Some("#/components/schemas/Cat")
    );
}

#[test]
fn discriminator_captures_extensions() {
    let r = reader(Version::V30);
    let schema = r
        .read(
            &j(json!({
                "oneOf": [],
                "discriminator": {
                    "propertyName": "type",
                    "mapping": {"string": "#/components/schemas/Text"},
                    "x-mapping": {
                        "#/components/schemas/Email": {"type": "string", "format": "email"},
                        "#/components/schemas/Text": {"type": "string"}
                    },
                    "x-propertyNames": ["type", "format"]
                }
            })),
            "#/x",
        )
        .unwrap();
    let d = schema
        .as_composite()
        .unwrap()
        .discriminator
        .as_ref()
        .unwrap();
    assert!(d.extensions.contains_key("x-mapping"));
    assert!(d.extensions.contains_key("x-propertyNames"));
    assert_eq!(d.property_name, "type");
    assert_eq!(
        d.mapping.get("string").map(String::as_str),
        Some("#/components/schemas/Text")
    );
}

#[test]
fn discriminator_extensions_default_to_empty() {
    let r = reader(Version::V30);
    let from_string = r
        .read(&j(json!({"oneOf": [], "discriminator": "kind"})), "#/x")
        .unwrap();
    assert!(from_string
        .as_composite()
        .unwrap()
        .discriminator
        .as_ref()
        .unwrap()
        .extensions
        .is_empty());
    let from_object = r
        .read(
            &j(json!({"oneOf": [], "discriminator": {"propertyName": "kind"}})),
            "#/x",
        )
        .unwrap();
    assert!(from_object
        .as_composite()
        .unwrap()
        .discriminator
        .as_ref()
        .unwrap()
        .extensions
        .is_empty());
}

#[test]
fn object_and_array_types_are_implied_from_their_keywords() {
    let r = reader(Version::V30);
    assert!(matches!(
        r.read(&j(json!({"properties": {"a": {"type": "string"}}})), "#/x")
            .unwrap(),
        Schema::Object(_)
    ));
    assert!(matches!(
        r.read(&j(json!({"additionalProperties": false})), "#/x")
            .unwrap(),
        Schema::Object(_)
    ));
    assert!(matches!(
        r.read(&j(json!({"items": {"type": "string"}})), "#/x")
            .unwrap(),
        Schema::Array(_)
    ));
    assert!(matches!(
        r.read(&j(json!({})), "#/x").unwrap(),
        Schema::Any(_)
    ));
}

#[test]
fn array_without_items_accepts_anything() {
    let schema = reader(Version::V30)
        .read(&j(json!({"type": "array"})), "#/x")
        .unwrap();
    let a = schema.as_array().unwrap();
    assert!(matches!(a.items, Schema::Any(_)));
}

#[test]
fn additional_properties_reads_as_boolean_or_schema() {
    let r = reader(Version::V30);
    let unspecified = r.read(&j(json!({"type": "object"})), "#/x").unwrap();
    assert!(unspecified
        .as_object()
        .unwrap()
        .additional_properties
        .is_none());

    let open = r
        .read(
            &j(json!({"type": "object", "additionalProperties": true})),
            "#/x",
        )
        .unwrap();
    assert_eq!(
        open.as_object().unwrap().additional_properties,
        Some(AdditionalProperties::Boolean(true))
    );

    let closed = r
        .read(
            &j(json!({"type": "object", "additionalProperties": false})),
            "#/x",
        )
        .unwrap();
    assert_eq!(
        closed.as_object().unwrap().additional_properties,
        Some(AdditionalProperties::Boolean(false))
    );

    let typed = r
        .read(
            &j(json!({"type": "object", "additionalProperties": {"type": "string"}})),
            "#/x",
        )
        .unwrap();
    assert!(matches!(
        typed.as_object().unwrap().additional_properties,
        Some(AdditionalProperties::Schema(ref s)) if matches!(s.as_ref(), Schema::String(_))
    ));
}

#[test]
fn file_type_reads_as_binary_string_under_every_dialect() {
    for version in [Version::V2, Version::V30, Version::V31] {
        let schema = reader(version)
            .read(&j(json!({"type": "file"})), "#/x")
            .unwrap();
        let s = schema.as_string().unwrap();
        assert_eq!(s.format.as_deref(), Some("binary"));
    }
}

#[test]
fn numeric_exclusive_bounds_collapse_into_bound_plus_flag() {
    for version in [Version::V2, Version::V30, Version::V31] {
        let schema = reader(version)
            .read(&j(json!({"type": "integer", "exclusiveMinimum": 5})), "#/x")
            .unwrap();
        let i = schema.as_integer().unwrap();
        assert_eq!(i.minimum, Some(JsonNumberOrInt::Int(5)));
        assert!(i.exclusive_minimum);
    }
    let boolean = reader(Version::V30)
        .read(
            &j(json!({"type": "integer", "minimum": 5, "exclusiveMinimum": true})),
            "#/x",
        )
        .unwrap();
    let i = boolean.as_integer().unwrap();
    assert_eq!(i.minimum, Some(JsonNumberOrInt::Int(5)));
    assert!(i.exclusive_minimum);
}

#[test]
fn parameter_fields_are_lifted_into_a_schema_and_non_schema_keys_dropped() {
    let mut data = indexmap::IndexMap::new();
    data.insert("name".into(), Json::String("limit".into()));
    data.insert("in".into(), Json::String("query".into()));
    data.insert("required".into(), Json::Bool(true));
    data.insert("type".into(), Json::String("integer".into()));
    data.insert(
        "minimum".into(),
        Json::Number(utopia_openapi::JsonNumber::Int(1)),
    );
    data.insert("x-nullable".into(), Json::Bool(true));

    let schema = reader(Version::V2)
        .read_parameter_fields(&data, "#/x")
        .unwrap();
    let i = schema.as_integer().unwrap();
    assert_eq!(i.minimum, Some(JsonNumberOrInt::Int(1)));
    assert!(schema.nullable());
    assert_eq!(
        schema.extensions().get("x-nullable"),
        Some(&Json::Bool(true))
    );
}

#[test]
fn extensions_are_carried_onto_the_schema() {
    let schema = reader(Version::V31)
        .read(
            &j(json!({"type": "string", "x-appwrite": {"method": "get"}})),
            "#/x",
        )
        .unwrap();
    assert!(schema.extensions().contains_key("x-appwrite"));
}

#[test]
fn unsupported_type_names_the_location() {
    let err = reader(Version::V31)
        .read(&j(json!({"type": "widget"})), "#/components/schemas/Pet")
        .unwrap_err();
    assert!(err.to_string().contains("#/components/schemas/Pet"));
}

#[test]
fn nested_failures_name_their_own_location() {
    let err = reader(Version::V30)
        .read(
            &j(json!({
                "type": "object",
                "properties": {"inner": {"type": "array", "items": {"type": "widget"}}}
            })),
            "#/x",
        )
        .unwrap_err();
    assert!(err.to_string().contains("#/x/properties/inner/items"));
}
