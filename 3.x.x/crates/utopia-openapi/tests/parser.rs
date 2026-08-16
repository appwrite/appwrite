//! Ports `tests/ParserTest.php`.

use indexmap::IndexMap;
use serde_json::json;
use utopia_openapi::model::{AdditionalProperties, HttpMethod, Schema};
use utopia_openapi::reference::LocalResolver;
use utopia_openapi::{InvalidSpecification, Json, OpenApiError, ParseException, Parser, Version};

fn parse(v: serde_json::Value) -> utopia_openapi::Specification {
    Parser::parse(v, None).expect("parse")
}

#[test]
fn parses_open_api31_into_canonical_model() {
    let document = json!({
        "openapi": "3.1.1",
        "info": {"title": "Pets", "version": "1.0.0", "x-owner": "team"},
        "servers": [{"url": "https://api.example.com/{version}", "variables": {"version": {"default": "v1"}}}],
        "tags": [{"name": "pets"}],
        "security": [{"Project": [], "Session": []}, {"Project": [], "JWT": []}],
        "paths": {
            "/pets/{id}": {
                "parameters": [{"name": "id", "in": "path", "required": true, "schema": {"type": "string"}}],
                "get": {
                    "operationId": "getPet",
                    "tags": ["pets"],
                    "parameters": [{"name": "id", "in": "path", "required": true, "description": "override", "schema": {"type": "string"}}],
                    "responses": {"200": {"description": "OK", "content": {"application/json": {"schema": {"$ref": "#/components/schemas/Pet"}}}}}
                },
                "delete": {"security": [], "responses": {"204": {"description": "Deleted"}}}
            }
        },
        "components": {
            "schemas": {
                "Pet": {
                    "type": "object",
                    "required": ["name"],
                    "properties": {
                        "name": {"type": "string"},
                        "parent": {"$ref": "#/components/schemas/Pet"},
                        "nickname": {"type": ["string", "null"]}
                    },
                    "additionalProperties": false
                },
                "Identifier": {"oneOf": [{"type": "string"}, {"type": "integer"}]}
            },
            "securitySchemes": {
                "Project": {"type": "apiKey", "in": "header", "name": "X-Project"},
                "Session": {"type": "http", "scheme": "bearer"},
                "JWT": {"type": "http", "scheme": "bearer"}
            }
        }
    });

    let spec = parse(document);
    assert_eq!(spec.version, Version::V31);
    assert_eq!(spec.source_version, "3.1.1");
    assert_eq!(
        spec.info.extensions.get("x-owner"),
        Some(&Json::String("team".into()))
    );
    assert_eq!(
        spec.servers[0].variables.get("version").unwrap().default,
        "v1"
    );
    assert_eq!(spec.security.len(), 2);
    let keys: Vec<_> = spec.security[0].schemes.keys().cloned().collect();
    assert_eq!(keys, vec!["Project", "Session"]);

    let get = spec.paths["/pets/{id}"].operation(HttpMethod::Get).unwrap();
    assert_eq!(get.id, "getPet");
    assert_eq!(get.parameters.len(), 1);
    assert_eq!(get.parameters[0].description.as_str(), "override");
    assert_eq!(get.security.len(), 2);
    assert!(std::ptr::eq(get, spec.operations_by_tag("pets")[0]));
    assert_eq!(
        spec.paths["/pets/{id}"]
            .operation(HttpMethod::Delete)
            .unwrap()
            .security
            .len(),
        0
    );

    let pet = spec.schemas["Pet"].as_object().unwrap();
    assert_eq!(
        pet.additional_properties,
        Some(AdditionalProperties::Boolean(false))
    );
    assert!(matches!(
        pet.properties.get("parent"),
        Some(Schema::Reference(_))
    ));
    assert!(pet.properties["nickname"].nullable());
    assert!(matches!(
        spec.schemas.get("Identifier"),
        Some(Schema::Composite(_))
    ));
    let schema = get.responses["200"].content["application/json"]
        .schema
        .as_ref()
        .unwrap();
    assert!(matches!(schema, Schema::Reference(_)));
}

#[test]
fn parses_open_api30_nullability_and_request_body() {
    let spec = parse(json!({
        "openapi": "3.0.3",
        "info": {"title": "Test", "version": "1"},
        "paths": {"/items": {"post": {
            "requestBody": {"required": true, "content": {"application/json": {"schema": {"type": "string", "nullable": true}}}},
            "responses": {"201": {"description": "Created"}}
        }}}
    }));
    assert_eq!(spec.version, Version::V30);
    let body = spec.paths["/items"]
        .operation(HttpMethod::Post)
        .unwrap()
        .request_body
        .as_ref()
        .unwrap();
    assert!(body.required);
    assert!(body.content["application/json"]
        .schema
        .as_ref()
        .unwrap()
        .nullable());
}

#[test]
fn parses_open_api2_directly() {
    let spec = parse(json!({
        "swagger": "2.0",
        "info": {"title": "Legacy", "version": "2"},
        "host": "api.example.com",
        "basePath": "/v1",
        "schemes": ["https"],
        "consumes": ["application/json"],
        "produces": ["application/json"],
        "securityDefinitions": {"Basic": {"type": "basic"}},
        "definitions": {"Pet": {"type": "object", "properties": {"name": {"type": "string"}}}},
        "paths": {"/pets": {"post": {
            "parameters": [{"name": "pet", "in": "body", "required": true, "schema": {"$ref": "#/definitions/Pet"}}],
            "responses": {"200": {"description": "OK", "schema": {"$ref": "#/definitions/Pet"}}}
        }}}
    }));
    assert_eq!(spec.version, Version::V2);
    assert_eq!(spec.servers[0].url, "https://api.example.com/v1");
    assert_eq!(
        spec.security_schemes["Basic"].scheme.as_deref(),
        Some("basic")
    );
    let operation = spec.paths["/pets"].operation(HttpMethod::Post).unwrap();
    assert!(operation.request_body.as_ref().unwrap().required);
    assert!(matches!(
        operation.request_body.as_ref().unwrap().content["application/json"]
            .schema
            .as_ref(),
        Some(Schema::Reference(_))
    ));
    assert!(matches!(
        operation.responses["200"].content["application/json"]
            .schema
            .as_ref(),
        Some(Schema::Reference(_))
    ));
}

#[test]
fn parses_open_api2_form_data_as_request_body() {
    let spec = parse(json!({
        "swagger": "2.0",
        "info": {"title": "Upload", "version": "1"},
        "paths": {"/upload": {"post": {
            "consumes": ["multipart/form-data"],
            "parameters": [
                {"name": "file", "in": "formData", "required": true, "type": "file"},
                {"name": "label", "in": "formData", "type": "string"}
            ],
            "responses": {"204": {"description": "Done"}}
        }}}
    }));
    let body = spec.paths["/upload"]
        .operation(HttpMethod::Post)
        .unwrap()
        .request_body
        .as_ref()
        .unwrap();
    let schema = body.content["multipart/form-data"]
        .schema
        .as_ref()
        .unwrap()
        .as_object()
        .unwrap();
    let file = schema.properties["file"].as_string().unwrap();
    assert_eq!(file.format.as_deref(), Some("binary"));
}

#[test]
fn resolves_escaped_local_json_pointer_and_detects_reference_cycles() {
    let mut components_params = IndexMap::new();
    let mut ab = IndexMap::new();
    ab.insert("name".into(), Json::String("id".into()));
    components_params.insert("a/b~c".into(), Json::Object(ab));
    let mut components = IndexMap::new();
    components.insert("parameters".into(), Json::Object(components_params));

    let mut a = IndexMap::new();
    a.insert("$ref".into(), Json::String("#/b".into()));
    let mut b = IndexMap::new();
    b.insert("$ref".into(), Json::String("#/a".into()));

    let mut doc = IndexMap::new();
    doc.insert("components".into(), Json::Object(components));
    doc.insert("a".into(), Json::Object(a));
    doc.insert("b".into(), Json::Object(b));

    let resolver = LocalResolver::new(doc);
    let resolved = resolver
        .resolve_object("#/components/parameters/a~1b~0c", &[])
        .unwrap();
    match resolved {
        Json::Object(map) => {
            assert_eq!(map.get("name"), Some(&Json::String("id".into())));
        }
        _ => panic!("expected object"),
    }

    let err = resolver.resolve_object("#/a", &[]).unwrap_err();
    assert!(matches!(err, OpenApiError::Circular(_)));
}

#[test]
fn empty_json_objects_are_not_confused_with_lists() {
    let spec = Parser::parse(
        r#"{"openapi":"3.1.0","info":{"title":"Empty","version":"1"},"paths":{},"components":{"schemas":{"Anything":{}}}}"#,
        None,
    )
    .unwrap();
    assert!(spec.paths.is_empty());
    assert!(spec.schemas.contains_key("Anything"));
}

#[test]
fn controlled_errors() {
    let err = Parser::parse("{", None).unwrap_err();
    match err {
        OpenApiError::Parse(ParseException(msg)) => {
            assert!(msg.contains("Invalid JSON"), "{msg}");
        }
        other => panic!("expected ParseException, got {other}"),
    }

    let err = Parser::parse(
        json!({
            "openapi": "3.0.0",
            "info": {"title": "x", "version": "1"},
            "paths": []
        }),
        Some(Version::V31),
    )
    .unwrap_err();
    assert!(matches!(
        err,
        OpenApiError::Invalid(InvalidSpecification(_))
    ));
}
