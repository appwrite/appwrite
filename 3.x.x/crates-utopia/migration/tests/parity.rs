//! Ports of `PHPUnit` suites that do not need a live Appwrite/Postgres backend.

use serde_json::{json, Value};
use utopia_database::constants::LENGTH_KEY;
use utopia_migration::prelude::*;
use utopia_migration::resource::{
    STATUS_SUCCESS, TYPE_DATABASE_VECTORSDB, TYPE_DOCUMENT, TYPE_ROW,
};
use utopia_migration::resources::auth::OAuth2Provider;
use utopia_migration::resources::functions::Func;
use utopia_migration::resources::sites::Site;
use utopia_test_wiremock::{method, path, Mock, MockServer, ResponseTemplate};

fn fixture_csv(name: &str) -> String {
    let path = format!("{}/tests/fixtures/csv/{name}", env!("CARGO_MANIFEST_DIR"));
    std::fs::read_to_string(path).unwrap_or_default()
}

fn payload(overrides: Value) -> Value {
    let mut base = json!({
        "key": "column",
        "required": false,
        "default": Value::Null,
        "array": false,
        "$createdAt": "2026-01-01T00:00:00.000+00:00",
        "$updatedAt": "2026-01-01T00:00:00.000+00:00",
    });
    if let Some(obj) = overrides.as_object() {
        for (k, v) in obj {
            base[k] = v.clone();
        }
    }
    base
}

fn table() -> Table {
    Table::new(Database::new("main", "Main"), "Modules", "modules")
}

#[test]
fn column_resolve_formatted_string_without_size() {
    assert_eq!(
        Column::resolve(
            json!({"key":"email","type": Column::TYPE_STRING, "format": Column::TYPE_EMAIL})
                .as_object()
                .unwrap()
        ),
        json!({"type": Column::TYPE_STRING, "format": Column::TYPE_EMAIL, "size": 254})
            .as_object()
            .cloned()
            .unwrap()
    );
}

#[test]
fn column_resolve_unsized_and_unknown() {
    assert_eq!(
        Column::resolve(
            json!({"key":"slug","type": Column::TYPE_VARCHAR})
                .as_object()
                .unwrap()
        )["size"],
        json!(0)
    );
    assert_eq!(
        Column::resolve(json!({"key":"unknown"}).as_object().unwrap()),
        json!({"type": "", "format": "", "size": 0})
            .as_object()
            .cloned()
            .unwrap()
    );
}

#[test]
fn collection_dimension_round_trip() {
    let array = json!({
        "database": {
            "id": "vectors",
            "name": "Vectors",
            "type": TYPE_DATABASE_VECTORSDB,
        },
        "id": "embeddings-store",
        "name": "Embeddings Store",
        "rowSecurity": true,
        "permissions": [],
        "createdAt": "2026-01-01T00:00:00.000+00:00",
        "updatedAt": "2026-01-01T00:00:00.000+00:00",
        "enabled": true,
        "dimension": 1536,
    });
    let collection = Collection::from_array(array.as_object().unwrap());
    assert_eq!(
        collection.get_database().get_name(),
        TYPE_DATABASE_VECTORSDB
    );
    assert_eq!(collection.get_dimension(), Some(1536));
    assert_eq!(collection.json_serialize()["dimension"], json!(1536));
    let rehydrated = Collection::from_array(&collection.json_serialize());
    assert_eq!(rehydrated.get_dimension(), Some(1536));
}

#[test]
fn collection_dimension_defaults_to_null() {
    let array = json!({
        "database": {
            "id": "vectors",
            "name": "Vectors",
            "type": TYPE_DATABASE_VECTORSDB,
        },
        "id": "embeddings-store",
        "name": "Embeddings Store",
        "rowSecurity": true,
        "permissions": [],
        "createdAt": "",
        "updatedAt": "",
        "enabled": true,
    });
    let collection = Collection::from_array(array.as_object().unwrap());
    assert_eq!(collection.get_dimension(), None);
    assert!(!collection.json_serialize().contains_key("dimension"));
}

#[test]
fn oauth2_from_array_appwrite() {
    let provider = OAuth2Provider::from_array(
        "appwrite",
        json!({
            "id": "appwrite",
            "enabled": true,
            "clientId": "client-123",
            "clientSecret": "super-secret",
        })
        .as_object()
        .unwrap(),
    )
    .expect("provider");
    assert_eq!(provider.get_provider_key(), "appwrite");
    assert!(provider.get_enabled());
    assert_eq!(
        provider.get_settings().get("clientId"),
        Some(&json!("client-123"))
    );
    assert_eq!(
        provider.get_destination_app_id().as_deref(),
        Some("client-123")
    );
    assert!(provider.get_destination_secret_fields().is_empty());
    assert!(provider.is_configured());
}

#[test]
fn oauth2_from_array_never_copies_secrets() {
    for key in OAuth2Provider::provider_keys() {
        let provider = OAuth2Provider::from_array(
            key,
            json!({
                "id": key,
                "enabled": false,
                "clientId": "client-123",
                "clientSecret": "super-secret",
                "p8File": "p8-contents",
            })
            .as_object()
            .unwrap(),
        )
        .unwrap_or_else(|| panic!("provider {key}"));
        assert!(
            !provider.get_settings().contains_key("clientSecret"),
            "{key}"
        );
        assert!(!provider.get_settings().contains_key("p8File"), "{key}");
    }
}

#[test]
fn oauth2_from_array_unknown_provider() {
    assert!(OAuth2Provider::from_array(
        "unknown",
        json!({"id":"unknown","enabled":true,"clientId":"client-123"})
            .as_object()
            .unwrap(),
    )
    .is_none());
}

#[test]
fn function_and_site_specifications() {
    let function = Func::from_array(
        json!({
            "id": "function",
            "name": "Function",
            "runtime": "php-8.4",
            "runtimeSpecification": "s-1vcpu-512mb",
            "buildSpecification": "s-2vcpu-2gb",
        })
        .as_object()
        .unwrap(),
    );
    assert_eq!(function.get_runtime_specification(), "s-1vcpu-512mb");
    assert_eq!(function.get_build_specification(), "s-2vcpu-2gb");
    assert_eq!(
        function.json_serialize()["runtimeSpecification"],
        json!("s-1vcpu-512mb")
    );
    assert_eq!(
        function.json_serialize()["buildSpecification"],
        json!("s-2vcpu-2gb")
    );

    let site = Site::from_array(
        json!({
            "id": "site",
            "name": "Site",
            "framework": "other",
            "buildRuntime": "node-22",
            "runtimeSpecification": "s-1vcpu-512mb",
            "buildSpecification": "s-2vcpu-2gb",
        })
        .as_object()
        .unwrap(),
    );
    assert_eq!(site.get_runtime_specification(), "s-1vcpu-512mb");
    assert_eq!(site.get_build_specification(), "s-2vcpu-2gb");

    let legacy = Func::from_array(
        json!({
            "id": "function",
            "name": "Function",
            "runtime": "php-8.4",
            "specification": "s-1vcpu-512mb",
        })
        .as_object()
        .unwrap(),
    );
    assert_eq!(legacy.get_runtime_specification(), "s-1vcpu-512mb");
    assert_eq!(legacy.get_build_specification(), "s-1vcpu-512mb");
}

#[test]
fn appwrite_get_column_string_family() {
    let table = table();
    let text = AppwriteSource::get_column(
        &table,
        &payload(json!({"key":"modulePath","type": Column::TYPE_TEXT})),
    )
    .unwrap();
    assert_eq!(text.kind(), ColumnKind::RegularText);
    assert_eq!(text.get_type(), Column::TYPE_TEXT);
    assert_eq!(text.get_size(), 65535);

    let medium = AppwriteSource::get_column(
        &table,
        &payload(json!({"key":"summary","type": Column::TYPE_MEDIUMTEXT})),
    )
    .unwrap();
    assert_eq!(medium.kind(), ColumnKind::MediumText);
    assert_eq!(medium.get_size(), 16_777_215);

    let long = AppwriteSource::get_column(
        &table,
        &payload(json!({"key":"archive","type": Column::TYPE_LONGTEXT})),
    )
    .unwrap();
    assert_eq!(long.kind(), ColumnKind::LongText);
    assert_eq!(long.get_size(), 2_147_483_647);

    let varchar = AppwriteSource::get_column(
        &table,
        &payload(json!({"key":"slug","type": Column::TYPE_VARCHAR, "size": 64})),
    )
    .unwrap();
    assert_eq!(varchar.kind(), ColumnKind::Varchar);
    assert_eq!(varchar.get_size(), 64);

    let string = AppwriteSource::get_column(
        &table,
        &payload(json!({"key":"title","type": Column::TYPE_STRING, "size": 128, "format": ""})),
    )
    .unwrap();
    assert_eq!(string.kind(), ColumnKind::String);
    assert_eq!(string.get_size(), 128);

    let email = AppwriteSource::get_column(
        &table,
        &payload(json!({"key":"email","type": Column::TYPE_STRING, "format": Column::TYPE_EMAIL})),
    )
    .unwrap();
    assert_eq!(email.kind(), ColumnKind::Email);
    assert_eq!(email.get_format(), Column::TYPE_EMAIL);
    assert_eq!(email.get_size(), 254);

    let url = AppwriteSource::get_column(
        &table,
        &payload(json!({"key":"website","type": Column::TYPE_STRING, "format": Column::TYPE_URL})),
    )
    .unwrap();
    assert_eq!(url.get_size(), 2000);

    let ip = AppwriteSource::get_column(
        &table,
        &payload(json!({"key":"address","type": Column::TYPE_STRING, "format": Column::TYPE_IP})),
    )
    .unwrap();
    assert_eq!(ip.get_size(), 39);

    let enum_col = AppwriteSource::get_column(
        &table,
        &payload(json!({
            "key":"status",
            "type": Column::TYPE_STRING,
            "format": Column::TYPE_ENUM,
            "elements": ["on", "off"],
        })),
    )
    .unwrap();
    assert_eq!(enum_col.get_size(), LENGTH_KEY);
    assert_eq!(
        enum_col.get_elements(),
        vec!["on".to_owned(), "off".to_owned()]
    );

    let shorthand = AppwriteSource::get_column(
        &table,
        &payload(json!({"key":"email","type": Column::TYPE_EMAIL})),
    )
    .unwrap();
    let formatted = AppwriteSource::get_column(
        &table,
        &payload(json!({"key":"email","type": Column::TYPE_STRING, "format": Column::TYPE_EMAIL})),
    )
    .unwrap();
    assert_eq!(shorthand.json_serialize(), formatted.json_serialize());

    let attr = AppwriteSource::get_column(
        &table,
        &payload(json!({"key":"modulePath","type": Column::TYPE_TEXT})),
    )
    .unwrap()
    .get_attribute();
    assert_eq!(attr.get_type(), Column::TYPE_TEXT);
    assert_eq!(attr.get_size(), 65535);

    let err = AppwriteSource::get_column(&table, &payload(json!({"key":"unknown","type":"blob"})))
        .unwrap_err();
    assert_eq!(err.get_message(), "Unsupported column type: blob");
}

#[test]
fn appwrite_sdk_column_list_normalizes_payload() {
    let columns = json!([{
        "key": "title",
        "type": "string",
        "status": "available",
        "size": 255,
    }]);
    let payload = json!({"total": 1, "columns": columns});
    assert_eq!(
        AppwriteSource::list_columns_from_sdk_list(&payload),
        columns.as_array().unwrap().clone()
    );
}

#[test]
fn csv_detect_delimiter_fixtures() {
    let cases = [
        ("comma.csv", ','),
        ("single_column.csv", ','),
        ("empty.csv", ','),
        ("quoted_fields.csv", ','),
        ("semicolon.csv", ';'),
        ("tab.csv", '\t'),
        ("pipe.csv", '|'),
    ];
    for (file, expected) in cases {
        assert_eq!(
            CsvSource::detect_delimiter(&fixture_csv(file)),
            expected,
            "{file}"
        );
    }
}

fn csv_row(id: &str, table: &Table, data: Value) -> AnyResource {
    let row = Row::new(
        id,
        table.clone(),
        data.as_object().cloned().unwrap_or_default(),
    );
    row.into()
}

fn parse_csv_line(line: &str) -> Vec<String> {
    let mut out = Vec::new();
    let mut cur = String::new();
    let mut in_quotes = false;
    let chars: Vec<char> = line.chars().collect();
    let mut i = 0;
    while i < chars.len() {
        let c = chars[i];
        if c == '"' {
            if in_quotes && chars.get(i + 1) == Some(&'"') {
                cur.push('"');
                i += 2;
                continue;
            }
            in_quotes = !in_quotes;
            i += 1;
            continue;
        }
        if c == ',' && !in_quotes {
            out.push(std::mem::take(&mut cur));
            i += 1;
            continue;
        }
        cur.push(c);
        i += 1;
    }
    out.push(cur);
    out
}

#[test]
fn csv_export_basic_and_nulls_and_arrays() {
    let dir = tempfile::tempdir().unwrap();
    let device = utopia_storage::Local::new(dir.path());
    let mut dest = CsvDestination::new(
        device,
        "test_db:test_table_id",
        "",
        "test_db_test_table_id",
        Vec::new(),
        ",",
        "\"",
        true,
    );
    dest.set_skip_shutdown_transfer(true);
    let table = Table::new(Database::new("test_db", ""), "test_table", "test_table_id");

    let mut row1 = Row::new(
        "row1",
        table.clone(),
        json!({"name":"John Doe","age":30,"email":"john@example.com"})
            .as_object()
            .cloned()
            .unwrap(),
    );
    row1.set_permissions(vec!["read(\"user:123\")".into()]);
    dest.import(vec![row1.into()], &mut |resources| {
        assert_eq!(resources[0].get_status(), STATUS_SUCCESS);
    });

    dest.import(
        vec![Row::new(
            "null_row",
            table.clone(),
            json!({
                "name": "Test",
                "null_field": Value::Null,
                "empty_string": "",
                "zero": 0,
                "false_bool": false
            })
            .as_object()
            .cloned()
            .unwrap(),
        )
        .into()],
        &mut |_| {},
    );

    dest.import(
        vec![Row::new(
            "array_row",
            table.clone(),
            json!({
                "tags": ["php", "csv", "export"],
                "metadata": {"key1": "value1"},
                "empty_array": [],
                "nested": [{"id": 1}]
            })
            .as_object()
            .cloned()
            .unwrap(),
        )
        .into()],
        &mut |_| {},
    );

    let csv = std::fs::read_to_string(dest.local_root().join("test_db_test_table_id.csv")).unwrap();
    let mut lines = csv.lines();
    let header = parse_csv_line(lines.next().unwrap());
    assert!(header.contains(&"$id".to_owned()));
    assert!(header.contains(&"name".to_owned()));
    let first = parse_csv_line(lines.next().unwrap());
    assert_eq!(first[0], "row1");
    assert!(first[1].contains("user:123"));
    assert_eq!(first[4], "John Doe");
}

#[test]
fn csv_export_allowed_columns() {
    let dir = tempfile::tempdir().unwrap();
    let device = utopia_storage::Local::new(dir.path());
    let mut dest = CsvDestination::new(
        device,
        "test_db:test_table_id",
        "",
        "filtered",
        vec!["name".into(), "email".into()],
        ",",
        "\"",
        true,
    );
    dest.set_skip_shutdown_transfer(true);
    let table = Table::new(Database::new("test_db", ""), "test_table", "test_table_id");
    dest.import(
        vec![csv_row(
            "filtered_row",
            &table,
            json!({"name":"John Doe","age":30,"email":"john@example.com","secret":"nope"}),
        )],
        &mut |_| {},
    );
    let csv = std::fs::read_to_string(dest.local_root().join("filtered.csv")).unwrap();
    let header = parse_csv_line(csv.lines().next().unwrap());
    assert!(header.contains(&"name".to_owned()));
    assert!(header.contains(&"email".to_owned()));
    assert!(!header.contains(&"age".to_owned()));
    assert!(!header.contains(&"secret".to_owned()));
}

#[test]
fn json_export_nested_id_objects_and_nulls() {
    let dir = tempfile::tempdir().unwrap();
    let device = utopia_storage::Local::new(dir.path());
    let mut dest = JsonDestination::new(
        device,
        "test_db:test_table_id",
        "",
        "test_db_test_table_id",
        Vec::new(),
    );
    let table = Table::new(Database::new("test_db", ""), "test_table", "test_table_id");
    let payload = json!({
        "items": [
            {"$id": "nested1", "value": "keep-me"},
            {"$id": "nested2", "value": {"deep": true}},
        ],
        "object": {"$id": "nested-object", "meta": {"foo": "bar"}},
        "null_field": Value::Null,
        "false_bool": false,
        "zero": 0,
    });
    dest.import(
        vec![csv_row("nested_row", &table, payload.clone())],
        &mut |_| {},
    );
    dest.shutdown();
    let path = dir.path().join("test_db_test_table_id.json");
    let data: Vec<Value> = serde_json::from_str(&std::fs::read_to_string(path).unwrap()).unwrap();
    assert_eq!(data[0]["items"], payload["items"]);
    assert_eq!(data[0]["object"], payload["object"]);
    assert!(data[0]["null_field"].is_null());
    assert_eq!(data[0]["false_bool"], json!(false));
    assert_eq!(data[0]["zero"], json!(0));
}

#[test]
fn json_export_allowed_columns() {
    let dir = tempfile::tempdir().unwrap();
    let device = utopia_storage::Local::new(dir.path());
    let mut dest = JsonDestination::new(
        device,
        "test_db:test_table_id",
        "",
        "filtered",
        vec!["name".into(), "email".into()],
    );
    let table = Table::new(Database::new("test_db", ""), "test_table", "test_table_id");
    dest.import(
        vec![csv_row(
            "filtered_row",
            &table,
            json!({"name":"John Doe","age":30,"email":"john@example.com","secret":"nope"}),
        )],
        &mut |_| {},
    );
    dest.shutdown();
    let data: Vec<Value> =
        serde_json::from_str(&std::fs::read_to_string(dir.path().join("filtered.json")).unwrap())
            .unwrap();
    assert!(data[0].get("name").is_some());
    assert!(data[0].get("email").is_some());
    assert!(data[0].get("age").is_none());
    assert!(data[0].get("secret").is_none());
    assert!(data[0].get("$id").is_some());
}

#[test]
fn immutable_attribute_fields_match_php() {
    assert_eq!(
        ATTRIBUTE_IMMUTABLE_FIELDS,
        [
            "type",
            "array",
            "signed",
            "format",
            "formatOptions",
            "filters"
        ]
    );
}

#[test]
fn appwrite_column_list_via_wiremock() {
    let rt = tokio::runtime::Runtime::new().unwrap();
    rt.block_on(async {
        let server = MockServer::start().await;
        let columns = json!([{
            "key": "title",
            "type": "string",
            "status": "available",
            "error": "",
            "required": true,
            "array": false,
            "$createdAt": "2026-08-12T00:00:00.000+00:00",
            "$updatedAt": "2026-08-12T00:00:00.000+00:00",
            "size": 255,
            "default": Value::Null,
            "encrypt": false,
        }]);
        Mock::given(method("GET"))
            .and(path("/tables/database/columns"))
            .respond_with(ResponseTemplate::new(200).set_body_json(json!({
                "total": 1,
                "columns": columns,
            })))
            .mount(&server)
            .await;
        let url = format!("{}/tables/database/columns", server.uri());
        let body: Value = reqwest::Client::new()
            .get(url)
            .send()
            .await
            .unwrap()
            .json()
            .await
            .unwrap();
        assert_eq!(
            AppwriteSource::list_columns_from_sdk_list(&body),
            columns.as_array().unwrap().clone()
        );
    });
}

#[test]
fn nhost_e2e_requires_live_postgres() {
    let _ = NHost::new("sub", "eu", "secret", "db", "user", "pass", "5432");
}

#[test]
fn supabase_e2e_requires_live_postgres() {
    let _ = Supabase::new(
        "http://localhost",
        "key",
        "localhost",
        "db",
        "user",
        "pass",
        "5432",
    );
}

#[test]
fn csv_source_supported_resources_include_document() {
    assert!(CsvSource::supported_resources().contains(&TYPE_ROW));
    assert!(CsvSource::supported_resources().contains(&TYPE_DOCUMENT));
}
