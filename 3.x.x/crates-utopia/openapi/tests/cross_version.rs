//! Ports `tests/CrossVersionFixtureTest.php`.

use serde_json::Value as SerdeValue;
use std::fs;
use utopia_openapi::model::{HttpMethod, ParameterLocation, Schema, SecuritySchemeType};
use utopia_openapi::{Parser, Specification, Version};

fn fixture_contents(name: &str) -> String {
    let path = format!("{}/tests/fixtures/{name}", env!("CARGO_MANIFEST_DIR"));
    fs::read_to_string(path).expect("fixture")
}

fn parse_fixture(name: &str) -> Specification {
    Parser::parse(fixture_contents(name), None).expect("parse fixture")
}

fn pet_reference(version: Version) -> &'static str {
    if version == Version::V2 {
        "#/definitions/Pet"
    } else {
        "#/components/schemas/Pet"
    }
}

fn cases() -> [(&'static str, Version, &'static str); 3] {
    [
        ("openapi-2.0.json", Version::V2, "2.0"),
        ("openapi-3.0.json", Version::V30, "3.0.3"),
        ("openapi-3.1.json", Version::V31, "3.1.1"),
    ]
}

fn assert_canonical(specification: &Specification, version: Version, source_version: &str) {
    assert_eq!(specification.version, version);
    assert_eq!(specification.source_version, source_version);
    assert_eq!(specification.info.title, "Pet API");
    assert_eq!(
        specification.info.description,
        "Cross-version parser fixture"
    );
    assert_eq!(specification.info.version, "1.0.0");
    assert_eq!(
        specification.info.extensions.get("x-owner"),
        Some(&utopia_openapi::Json::String("platform".into()))
    );
    assert_eq!(specification.servers[0].url, "https://api.example.com/v1");

    let tag_keys: Vec<_> = specification.tags.keys().cloned().collect();
    assert_eq!(tag_keys, vec!["pets"]);
    assert_eq!(specification.tags["pets"].description, "Pet operations");
    let path_keys: Vec<_> = specification.paths.keys().cloned().collect();
    assert_eq!(path_keys, vec!["/pets", "/pets/{id}"]);
    assert_eq!(specification.operations().len(), 3);
    assert_eq!(specification.operations_by_tag("pets").len(), 3);

    let create = specification.paths["/pets"]
        .operation(HttpMethod::Post)
        .unwrap();
    assert_eq!(create.id, "createPet");
    assert_eq!(create.summary, "Create a pet");
    assert_eq!(
        create.extensions.get("x-stability"),
        Some(&utopia_openapi::Json::String("stable".into()))
    );
    let body = create.request_body.as_ref().unwrap();
    assert!(body.required);
    assert_eq!(body.description, "Pet to create");
    let media: Vec<_> = body.content.keys().cloned().collect();
    assert_eq!(media, vec!["application/json"]);
    let schema = body.content["application/json"].schema.as_ref().unwrap();
    assert_eq!(
        schema.as_reference().unwrap().reference,
        pet_reference(version)
    );

    let codes: Vec<_> = create.responses.keys().cloned().collect();
    assert_eq!(codes, vec!["201"]);
    assert_eq!(create.responses["201"].description, "Created");
    let headers: Vec<_> = create.responses["201"].headers.keys().cloned().collect();
    assert_eq!(headers, vec!["X-Request-Id"]);
    assert!(matches!(
        create.responses["201"].headers["X-Request-Id"].schema,
        Some(Schema::String(_))
    ));
    let resp_media: Vec<_> = create.responses["201"].content.keys().cloned().collect();
    assert_eq!(resp_media, vec!["application/json"]);
    assert!(matches!(
        create.responses["201"].content["application/json"].schema,
        Some(Schema::Reference(_))
    ));

    let get = specification.paths["/pets/{id}"]
        .operation(HttpMethod::Get)
        .unwrap();
    assert_eq!(get.id, "getPet");
    assert_eq!(get.parameters.len(), 1);
    assert_eq!(get.parameters[0].name, "id");
    assert_eq!(get.parameters[0].location, ParameterLocation::Path);
    assert!(get.parameters[0].required);
    assert_eq!(get.parameters[0].description, "Operation identifier");
    let get_codes: Vec<_> = get.responses.keys().cloned().collect();
    assert_eq!(get_codes, vec!["200", "default"]);
    assert_eq!(get.responses["default"].description, "Unexpected error");

    assert_eq!(specification.security.len(), 2);
    let s0: Vec<_> = specification.security[0].schemes.keys().cloned().collect();
    assert_eq!(s0, vec!["Project", "Basic"]);
    let s1: Vec<_> = specification.security[1].schemes.keys().cloned().collect();
    assert_eq!(s1, vec!["Project"]);
    assert_eq!(get.security, specification.security);
    assert!(specification.paths["/pets/{id}"]
        .operation(HttpMethod::Delete)
        .unwrap()
        .security
        .is_empty());

    assert_eq!(
        specification.security_schemes["Project"].type_,
        SecuritySchemeType::ApiKey
    );
    assert_eq!(
        specification.security_schemes["Project"].name.as_deref(),
        Some("X-Project")
    );
    assert_eq!(
        specification.security_schemes["Project"].location,
        Some(ParameterLocation::Header)
    );
    assert_eq!(
        specification.security_schemes["Basic"].type_,
        SecuritySchemeType::Http
    );
    assert_eq!(
        specification.security_schemes["Basic"].scheme.as_deref(),
        Some("basic")
    );

    let pet = specification.schemas["Pet"].as_object().unwrap();
    assert_eq!(pet.description, "A pet");
    assert_eq!(pet.required, vec!["id", "name"]);
    assert_eq!(
        pet.additional_properties,
        Some(utopia_openapi::AdditionalProperties::Boolean(false))
    );
    let props: Vec<_> = pet.properties.keys().cloned().collect();
    assert_eq!(props, vec!["id", "name", "nickname", "parent", "status"]);
    assert!(matches!(pet.properties["id"], Schema::Integer(_)));
    assert_eq!(
        pet.properties["id"].as_integer().unwrap().format.as_deref(),
        Some("int64")
    );
    assert!(matches!(pet.properties["name"], Schema::String(_)));
    assert_eq!(
        pet.properties["name"].as_string().unwrap().min_length,
        Some(1)
    );
    assert!(pet.properties["nickname"].nullable());
    assert_eq!(
        pet.properties["parent"].as_reference().unwrap().reference,
        pet_reference(version)
    );
    assert_eq!(
        pet.properties["status"].enum_values(),
        &[
            utopia_openapi::Json::String("available".into()),
            utopia_openapi::Json::String("adopted".into())
        ]
    );
}

#[test]
fn equivalent_documents_produce_equivalent_canonical_behavior() {
    for (fixture, version, source) in cases() {
        assert_canonical(&parse_fixture(fixture), version, source);
    }
}

#[test]
fn fixture_can_be_parsed_from_json_and_decoded_array() {
    for (fixture, version, source) in cases() {
        let json = fixture_contents(fixture);
        let decoded: SerdeValue = serde_json::from_str(&json).unwrap();
        let from_json = Parser::parse(json, Some(version)).unwrap();
        let from_array = Parser::parse(decoded, Some(version)).unwrap();
        assert_eq!(from_json, from_array);
        assert_eq!(from_array.source_version, source);
    }
}

fn semantic_snapshot(specification: &Specification) -> String {
    let create = specification.paths["/pets"]
        .operation(HttpMethod::Post)
        .unwrap();
    let get = specification.paths["/pets/{id}"]
        .operation(HttpMethod::Get)
        .unwrap();
    let delete = specification.paths["/pets/{id}"]
        .operation(HttpMethod::Delete)
        .unwrap();
    let pet = specification.schemas["Pet"].as_object().unwrap();
    format!(
        "{:?}|{:?}|{:?}|{:?}|{:?}|{:?}|{:?}|{:?}|{:?}|{:?}|{:?}|{:?}|{:?}|{:?}|{:?}",
        (
            &specification.info.title,
            &specification.info.description,
            &specification.info.version
        ),
        specification
            .servers
            .iter()
            .map(|s| s.url.as_str())
            .collect::<Vec<_>>(),
        specification.tags.keys().cloned().collect::<Vec<_>>(),
        specification
            .operations()
            .iter()
            .map(|o| (o.id.as_str(), o.method.as_str(), o.path.as_str(), &o.tags))
            .collect::<Vec<_>>(),
        create
            .request_body
            .as_ref()
            .map(|b| b.content.keys().cloned().collect::<Vec<_>>()),
        create.request_body.as_ref().map(|b| b.required),
        create.responses.keys().cloned().collect::<Vec<_>>(),
        create.responses["201"]
            .content
            .keys()
            .cloned()
            .collect::<Vec<_>>(),
        create.responses["201"]
            .headers
            .keys()
            .cloned()
            .collect::<Vec<_>>(),
        (
            get.parameters[0].name.as_str(),
            get.parameters[0].location.as_str(),
            get.parameters[0].description.as_str()
        ),
        get.responses.keys().cloned().collect::<Vec<_>>(),
        get.security
            .iter()
            .map(|s| s.schemes.clone())
            .collect::<Vec<_>>(),
        &delete.security,
        specification
            .security_schemes
            .iter()
            .map(|(k, s)| (
                k.clone(),
                s.type_.as_str(),
                s.name.clone(),
                s.location.map(|l| l.as_str().to_owned()),
                s.scheme.clone()
            ))
            .collect::<Vec<_>>(),
        (
            pet.description.as_str(),
            &pet.required,
            &pet.additional_properties,
            pet.properties.keys().cloned().collect::<Vec<_>>(),
            pet.properties["id"].as_integer().unwrap().format.clone(),
            pet.properties["name"].as_string().unwrap().min_length,
            pet.properties["nickname"].nullable(),
            pet.properties["status"].enum_values().to_vec()
        )
    )
}

#[test]
fn fixtures_have_equivalent_semantic_snapshots() {
    let snapshots: Vec<_> = cases()
        .iter()
        .map(|(fixture, _, _)| semantic_snapshot(&parse_fixture(fixture)))
        .collect();
    assert_eq!(snapshots[0], snapshots[1]);
    assert_eq!(snapshots[1], snapshots[2]);
}
