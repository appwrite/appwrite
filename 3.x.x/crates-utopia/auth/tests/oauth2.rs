use serde_json::{json, Map, Value};
use utopia_auth::oauth2::{
    ClientIdMetadataDocument, ClientIdentifierUrl, InvalidPromptException,
    InvalidRequestUriException, InvalidResourceException, Prompt, Prompts, RedirectUris,
    ResourceIndicators, PAR,
};

#[test]
fn par_builds_and_parses_request_uri() {
    const PREFIX: &str = "urn:appwrite:oauth2:request:";

    let par = PAR::from_id(PREFIX, "grant123").unwrap();
    assert_eq!(par.id(), "grant123");
    assert_eq!(par.request_uri(), "urn:appwrite:oauth2:request:grant123");

    let parsed = PAR::from_request_uri(PREFIX, par.request_uri()).unwrap();
    assert_eq!(parsed.id(), "grant123");
    assert_eq!(parsed.request_uri(), "urn:appwrite:oauth2:request:grant123");
    assert_eq!(InvalidRequestUriException::ERROR_CODE, "invalid_request");
    assert!(PAR::from_id("", "grant123").is_err());
    assert!(PAR::from_request_uri(PREFIX, PREFIX).is_err());
}

#[test]
fn prompts_parse_dedupe_and_reject_invalid_values() {
    let prompts = Prompts::from_string("login consent select_account consent").unwrap();
    assert_eq!(prompts.to_array(), ["login", "consent", "select_account"]);
    assert_eq!(prompts.to_string(), "login consent select_account");
    assert!(prompts.contains(Prompt::Login));
    assert!(prompts.contains(Prompt::Consent));
    assert!(prompts.contains(Prompt::SelectAccount));
    assert!(!prompts.contains(Prompt::None));

    let empty = Prompts::from_string("").unwrap();
    assert!(empty.to_array().is_empty());
    assert_eq!(InvalidPromptException::ERROR_CODE, "invalid_request");
    assert!(Prompts::from_string("login unknown").is_err());
    assert!(Prompts::from_string("none consent").is_err());
}

#[test]
fn redirect_uris_match_exact_and_loopback_ports() {
    let redirects =
        RedirectUris::from(["https://example.com/cb", "http://localhost:3118/callback"]);
    assert!(redirects.matches("https://example.com/cb", false));
    assert!(!redirects.matches("https://example.com/other", false));
    assert!(!redirects.matches("http://localhost:54155/callback", false));
    assert!(redirects.matches("http://localhost:54155/callback", true));
    assert!(redirects.matches("http://LOCALHOST:54155/callback", true));
    assert!(!redirects.matches("http://127.0.0.1:54155/callback", true));
    assert!(!redirects.matches("https://localhost:54155/callback", true));
    assert!(!redirects.matches("http://localhost:54155/callback#fragment", true));

    let filtered = RedirectUris::from(["https://example.com/cb", ""]);
    assert_eq!(filtered.to_array(), ["https://example.com/cb".to_owned()]);
}

#[test]
fn resource_indicators_normalize_validate_and_compare() {
    assert!(ResourceIndicators::from(None::<&str>, None)
        .unwrap()
        .to_array()
        .is_empty());
    assert_eq!(
        ResourceIndicators::from("https://api.example.com/", None)
            .unwrap()
            .to_array(),
        ["https://api.example.com/".to_owned()]
    );
    assert_eq!(
        ResourceIndicators::from(None::<&str>, Some("https://api.example.com/"))
            .unwrap()
            .to_array(),
        ["https://api.example.com/".to_owned()]
    );
    assert_eq!(
        ResourceIndicators::from(
            vec![
                "https://api.example.com/",
                "http://localhost:8080/v1",
                "https://api.example.com/",
            ],
            None,
        )
        .unwrap()
        .to_array(),
        [
            "https://api.example.com/".to_owned(),
            "http://localhost:8080/v1".to_owned()
        ]
    );

    let requested = ResourceIndicators::from(vec!["https://api.example.com/"], None).unwrap();
    let granted = ResourceIndicators::from(
        vec!["https://api.example.com/", "https://files.example.com/"],
        None,
    )
    .unwrap();
    assert!(requested.is_subset_of(&granted));
    assert!(granted.equals(
        &ResourceIndicators::from(
            vec!["https://files.example.com/", "https://api.example.com/"],
            None,
        )
        .unwrap()
    ));
    assert_eq!(
        ResourceIndicators::from(None::<&str>, None)
            .unwrap()
            .audience("https://cloud.appwrite.io/v1/project1"),
        ["https://cloud.appwrite.io/v1/project1".to_owned()]
    );

    assert_eq!(InvalidResourceException::ERROR_CODE, "invalid_target");
    assert!(ResourceIndicators::from("https://api.example.com/#section", None).is_err());
    assert!(ResourceIndicators::from("/relative", None).is_err());
    assert!(
        ResourceIndicators::from(vec![json!("https://api.example.com/"), json!(42)], None).is_err()
    );
    assert!(ResourceIndicators::from(
        vec!["https://api.example.com/"],
        Some("https://files.example.com/")
    )
    .is_err());
}

#[test]
fn client_identifier_url_validates_metadata_urls() {
    assert!(ClientIdentifierUrl::is_candidate(
        "HTTPS://client.example/metadata"
    ));
    assert!(!ClientIdentifierUrl::is_candidate("opaque-client-id"));

    let identifier =
        ClientIdentifierUrl::from_string("https://client.example:8443/metadata?version=1", false)
            .unwrap();
    assert_eq!(
        identifier.to_string(),
        "https://client.example:8443/metadata?version=1"
    );
    assert_eq!(identifier.host(), "client.example");
    assert!(ClientIdentifierUrl::from_string("http://localhost/metadata", true).is_ok());

    for invalid in [
        "",
        "opaque-client-id",
        "http://client.example/metadata",
        "https://client.example",
        "https:///metadata",
        "https://user:password@client.example/metadata",
        "https://client.example/metadata#fragment",
        "https://client.example/a/./metadata",
        "https://client.example/a/../metadata",
        "https://client.example/a path",
    ] {
        assert!(ClientIdentifierUrl::from_string(invalid, false).is_err());
    }
}

#[test]
fn client_id_metadata_document_accepts_valid_public_metadata() {
    let metadata = json!({
        "client_id": "https://client.example/metadata",
        "client_name": "Example MCP Client",
        "redirect_uris": ["https://client.example/callback", "myapp:/callback"],
        "grant_types": ["authorization_code", "refresh_token"],
        "response_types": ["code"],
        "token_endpoint_auth_method": "none",
        "extension_property": {"enabled": true},
        "nullable_extension": null
    });
    let metadata = object(metadata);
    let document = ClientIdMetadataDocument::from_json(
        client_id(),
        &serde_json::to_string(&Value::Object(metadata.clone())).unwrap(),
    )
    .unwrap();

    assert_eq!(
        document.client_id().to_string(),
        "https://client.example/metadata"
    );
    assert_eq!(document.token_endpoint_auth_method(), "none");
    assert_eq!(
        document.grant_types(),
        ["authorization_code".to_owned(), "refresh_token".to_owned()]
    );
    assert_eq!(document.response_types(), ["code".to_owned()]);
    assert_eq!(
        document.redirect_uris().to_array(),
        [
            "https://client.example/callback".to_owned(),
            "myapp:/callback".to_owned()
        ]
    );
    assert_eq!(
        document.get("extension_property"),
        Some(&json!({"enabled": true}))
    );
    assert_eq!(document.get("nullable_extension"), Some(&Value::Null));
    assert_eq!(document.to_array(), &metadata);

    let defaults = ClientIdMetadataDocument::from_array(
        client_id(),
        object(json!({
            "client_id": "https://client.example/metadata",
            "token_endpoint_auth_method": "none"
        })),
    )
    .unwrap();
    assert_eq!(defaults.grant_types(), ["authorization_code".to_owned()]);
    assert_eq!(defaults.response_types(), ["code".to_owned()]);
    assert!(defaults.redirect_uris().to_array().is_empty());
}

#[test]
fn client_id_metadata_document_rejects_invalid_metadata() {
    assert!(ClientIdMetadataDocument::from_json(client_id(), "{").is_err());
    assert!(ClientIdMetadataDocument::from_json(client_id(), "[]").is_err());

    let valid = || {
        object(json!({
            "client_id": "https://client.example/metadata",
            "token_endpoint_auth_method": "none"
        }))
    };

    for metadata in [
        object(json!({"token_endpoint_auth_method": "none"})),
        object(
            json!({"client_id": "https://other.example/metadata", "token_endpoint_auth_method": "none"}),
        ),
        object(json!({"client_id": "https://client.example/metadata"})),
        object(
            json!({"client_id": "https://client.example/metadata", "token_endpoint_auth_method": ""}),
        ),
        object(
            json!({"client_id": "https://client.example/metadata", "token_endpoint_auth_method": "client_secret_basic"}),
        ),
        {
            let mut metadata = valid();
            metadata.insert("client_secret".to_owned(), json!("secret"));
            metadata
        },
        {
            let mut metadata = valid();
            metadata.insert("grant_types".to_owned(), json!("authorization_code"));
            metadata
        },
        {
            let mut metadata = valid();
            metadata.insert("grant_types".to_owned(), json!([""]));
            metadata
        },
        {
            let mut metadata = valid();
            metadata.insert(
                "redirect_uris".to_owned(),
                json!(["https://client.example/callback#fragment"]),
            );
            metadata
        },
        {
            let mut metadata = valid();
            metadata.insert("client_name".to_owned(), json!(["Example"]));
            metadata
        },
        {
            let mut metadata = valid();
            metadata.insert("jwks".to_owned(), json!(null));
            metadata
        },
        {
            let mut metadata = valid();
            metadata.insert("jwks".to_owned(), json!({"keys": "not-a-list"}));
            metadata
        },
        {
            let mut metadata = valid();
            metadata.insert("jwks".to_owned(), json!({"keys": []}));
            metadata.insert(
                "jwks_uri".to_owned(),
                json!("https://client.example/jwks.json"),
            );
            metadata
        },
        {
            let mut metadata = valid();
            metadata.insert(
                "jwks".to_owned(),
                json!({"keys": [{"kty": "oct", "k": "secret"}]}),
            );
            metadata
        },
    ] {
        assert!(ClientIdMetadataDocument::from_array(client_id(), metadata).is_err());
    }
}

fn client_id() -> ClientIdentifierUrl {
    ClientIdentifierUrl::from_string("https://client.example/metadata", false).unwrap()
}

fn object(value: Value) -> Map<String, Value> {
    let Value::Object(map) = value else {
        panic!("test value must be an object");
    };
    map
}
