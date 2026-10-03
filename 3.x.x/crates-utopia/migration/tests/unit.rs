use serde_json::{json, Map};
use utopia_database::constants::LENGTH_KEY;
use utopia_migration::prelude::*;
use utopia_migration::resource::{
    STATUS_PENDING, STATUS_SUCCESS, TYPE_DATABASE, TYPE_ROW, TYPE_TABLE, TYPE_USER,
};
use utopia_migration::transfer::{Transfer as TransferNs, GROUP_DATABASES};
use utopia_migration::{Cache, OnDuplicate, SchemaAction};
use utopia_storage::Device;

#[test]
fn column_resolve_fixed_width_types() {
    assert_eq!(
        Column::resolve(
            json!({"key":"body","type": Column::TYPE_TEXT})
                .as_object()
                .unwrap()
        ),
        json!({"type": Column::TYPE_TEXT, "format": "", "size": 65535})
            .as_object()
            .cloned()
            .unwrap()
    );
    assert_eq!(
        Column::resolve(
            json!({"key":"summary","type": Column::TYPE_MEDIUMTEXT})
                .as_object()
                .unwrap()
        ),
        json!({"type": Column::TYPE_MEDIUMTEXT, "format": "", "size": 16_777_215})
            .as_object()
            .cloned()
            .unwrap()
    );
    assert_eq!(
        Column::resolve(
            json!({"key":"archive","type": Column::TYPE_LONGTEXT})
                .as_object()
                .unwrap()
        ),
        json!({"type": Column::TYPE_LONGTEXT, "format": "", "size": 2_147_483_647})
            .as_object()
            .cloned()
            .unwrap()
    );
}

#[test]
fn column_resolve_ignores_reported_size_for_fixed_types() {
    assert_eq!(
        Column::resolve(
            json!({"key":"body","type": Column::TYPE_TEXT, "size": 128})
                .as_object()
                .unwrap()
        )["size"],
        json!(65535)
    );
}

#[test]
fn column_resolve_format_shorthands() {
    assert_eq!(
        Column::resolve(
            json!({"key":"email","type": Column::TYPE_EMAIL})
                .as_object()
                .unwrap()
        ),
        json!({"type": Column::TYPE_STRING, "format": Column::TYPE_EMAIL, "size": 254})
            .as_object()
            .cloned()
            .unwrap()
    );
    assert_eq!(
        Column::resolve(
            json!({"key":"website","type": Column::TYPE_URL})
                .as_object()
                .unwrap()
        )["size"],
        json!(2000)
    );
    assert_eq!(
        Column::resolve(
            json!({"key":"address","type": Column::TYPE_IP})
                .as_object()
                .unwrap()
        )["size"],
        json!(39)
    );
    assert_eq!(
        Column::resolve(
            json!({"key":"status","type": Column::TYPE_ENUM})
                .as_object()
                .unwrap()
        )["size"],
        json!(LENGTH_KEY)
    );
}

#[test]
fn column_explicit_size_wins() {
    assert_eq!(
        Column::resolve(
            json!({"key":"email","type": Column::TYPE_EMAIL, "size": 512})
                .as_object()
                .unwrap()
        )["size"],
        json!(512)
    );
    assert_eq!(
        Column::resolve(
            json!({"key":"slug","type": Column::TYPE_VARCHAR, "size": "64"})
                .as_object()
                .unwrap()
        )["size"],
        json!(64)
    );
}

#[test]
fn on_duplicate_matrix() {
    for mode in [OnDuplicate::Fail, OnDuplicate::Skip, OnDuplicate::Overwrite] {
        assert_eq!(
            mode.resolve_schema_action(false, None, None),
            SchemaAction::Create
        );
    }
    assert_eq!(
        OnDuplicate::Fail.resolve_schema_action(true, None, None),
        SchemaAction::Create
    );
    assert_eq!(
        OnDuplicate::Skip.resolve_schema_action(
            true,
            Some("2026-01-01T00:00:00.000+00:00"),
            Some("2020-01-01T00:00:00.000+00:00"),
        ),
        SchemaAction::Skip
    );
    assert_eq!(
        OnDuplicate::Overwrite.resolve_schema_action(
            true,
            Some("2026-04-23T10:00:00.000+00:00"),
            Some("2026-04-23T09:59:59.000+00:00"),
        ),
        SchemaAction::Overwrite
    );
    let when = "2026-04-23T10:00:00.000+00:00";
    assert_eq!(
        OnDuplicate::Overwrite.resolve_schema_action(true, Some(when), Some(when)),
        SchemaAction::Skip
    );
    assert_eq!(
        OnDuplicate::Overwrite.resolve_schema_action(true, None, None),
        SchemaAction::Skip
    );
    assert_eq!(OnDuplicate::values(), ["fail", "skip", "overwrite"]);
}

#[test]
fn cache_add_update_and_row_counters() {
    let mut cache = Cache::new();
    let mut db1: AnyResource = Database::new("db1", "db1").into();
    cache.add(&mut db1);
    assert_eq!(db1.get_name(), TYPE_DATABASE);
    assert_eq!(db1.get_status(), STATUS_PENDING);
    assert_eq!(cache.get(TYPE_DATABASE).len(), 1);

    let mut db2: AnyResource = Database::new("db2", "db2").into();
    cache.add(&mut db2);
    cache.add(&mut db2);
    assert_eq!(cache.get(TYPE_DATABASE).len(), 2);

    db1.set_status(STATUS_SUCCESS, "");
    cache.update(&mut db1);
    assert_eq!(cache.get(TYPE_DATABASE).len(), 2);

    let mut table: AnyResource = Table::new(Database::new("db1", "db1"), "table", "table1").into();
    cache.add(&mut table);
    assert_eq!(cache.get(TYPE_TABLE).len(), 1);

    let mut row: AnyResource = {
        let AnyResource::Table(t) = table.clone() else {
            panic!("table");
        };
        Row::new("row1", t, Map::default()).into()
    };
    cache.add(&mut row);
    let rows = cache.get(TYPE_ROW);
    assert_eq!(
        rows.get(STATUS_PENDING).and_then(|e| e.as_counter()),
        Some("1")
    );

    row.set_status(STATUS_SUCCESS, "");
    cache.update(&mut row);
    let rows = cache.get(TYPE_ROW);
    assert_eq!(
        rows.get(STATUS_SUCCESS).and_then(|e| e.as_counter()),
        Some("1")
    );
    row.set_status(STATUS_SUCCESS, "");
    cache.update(&mut row);
    let rows = cache.get(TYPE_ROW);
    assert_eq!(
        rows.get(STATUS_SUCCESS).and_then(|e| e.as_counter()),
        Some("2")
    );
}

#[test]
fn transfer_requires_type_when_id_set() {
    let mut transfer = TransferNs::new(MockSource::new(), MockDestination::new());
    let err = transfer
        .run(
            &[TYPE_USER, TYPE_DATABASE],
            &mut |_| {},
            Some("rootResourceId"),
            None,
        )
        .unwrap_err();
    assert_eq!(
        err.get_message(),
        "Resource type must be set when resource ID is set."
    );
}

#[test]
fn transfer_root_resource_id() {
    let mut source = MockSource::new();
    source.push_mock_resource(Database::new("test", "test"));
    source.push_mock_resource(Database::new("test2", "test"));
    let mut transfer = TransferNs::new(source, MockDestination::new());
    transfer
        .run(
            &[TYPE_DATABASE],
            &mut |_| {},
            Some("test"),
            Some(TYPE_DATABASE),
        )
        .unwrap();
    let ids = transfer
        .destination()
        .get_resource_type_data(GROUP_DATABASES, TYPE_DATABASE);
    assert_eq!(ids.len(), 1);
    assert!(ids.contains(&"test".to_owned()));
}

#[test]
fn transfer_legacy_compound_root_resource_id() {
    let database = Database::new("database", "Database");
    let first = Table::new(database.clone(), "First table", "first");
    let second = Table::new(database.clone(), "Second table", "second");
    let mut source = MockSource::new();
    source.push_mock_resource(database);
    source.push_mock_resource(first);
    source.push_mock_resource(second.clone());
    let mut transfer = TransferNs::new(source, MockDestination::new());
    transfer
        .run(
            &[TYPE_DATABASE, TYPE_TABLE],
            &mut |_| {},
            Some("database:second"),
            Some(TYPE_DATABASE),
        )
        .unwrap();
    let tables = transfer
        .destination()
        .get_resource_type_data(GROUP_DATABASES, TYPE_TABLE);
    assert_eq!(tables, vec!["second".to_owned()]);
}

#[test]
fn transfer_explicit_selector_keeps_colon_ids() {
    let database = Database::new("database:with:colon", "Database");
    let first = Table::new(database.clone(), "First table", "first");
    let second = Table::new(database.clone(), "Second table", "table:with:colon");
    let mut source = MockSource::new();
    source.push_mock_resource(database.clone());
    source.push_mock_resource(first);
    source.push_mock_resource(second.clone());
    let mut transfer = TransferNs::new(source, MockDestination::new());
    transfer
        .run_with_resource_selector(
            &[TYPE_DATABASE, TYPE_TABLE],
            &mut |_| {},
            "table:with:colon",
            "2",
            TYPE_TABLE,
            "database:with:colon",
            "1",
            TYPE_DATABASE,
        )
        .unwrap();
    let tables = transfer
        .destination()
        .get_resource_type_data(GROUP_DATABASES, TYPE_TABLE);
    assert_eq!(tables, vec!["table:with:colon".to_owned()]);
}

#[test]
fn transfer_status_counters_ignore_unrequested_rows() {
    let transfer = TransferNs::new(MockSource::new(), MockDestination::new());
    let table = Table::new(Database::new("db", "db"), "table", "table");
    let mut row: AnyResource = {
        let mut r = Row::new("row-1", table, Map::default());
        r.set_status(STATUS_SUCCESS, "");
        r.into()
    };
    transfer.get_cache().add(&mut row);
    let counters = transfer.get_status_counters();
    assert!(!counters.contains_key(TYPE_ROW));
    assert!(counters.is_empty());
}

#[test]
fn appwrite_dsn_without_resolver_is_empty() {
    let dest = AppwriteDestination::new(
        "destination-project",
        "http://example.test/v1",
        "test-key",
        "1",
        OnDuplicate::Fail,
        None,
    );
    let mut resource = Database::new("src-database", "src");
    resource.set_database(Some("database_db_fra1_self_hosted_11_0".into()));
    assert_eq!(dest.resolve_destination_dsn(&resource), "");
}

#[test]
fn appwrite_dsn_with_resolver() {
    let expected = "appwrite://database_db_fra1_self_hosted_17_0?database=appwrite&namespace=_1";
    let dest = AppwriteDestination::new(
        "destination-project",
        "http://example.test/v1",
        "test-key",
        "1",
        OnDuplicate::Fail,
        Some(std::sync::Arc::new({
            let expected = expected.to_owned();
            move |_r: &Database| expected.clone()
        })),
    );
    let mut resource = Database::new("src-database", "src");
    resource.set_database(Some("database_db_fra1_self_hosted_11_0".into()));
    let resolved = dest.resolve_destination_dsn(&resource);
    assert_eq!(resolved, expected);
    assert_ne!(resource.get_database().unwrap_or(""), resolved);
}

#[test]
fn json_source_factory_keeps_colon_ids() {
    let dir = tempfile::tempdir().unwrap();
    let device = utopia_storage::Local::new(dir.path());
    let path = device.get_path("input.json");
    std::fs::write(&path, r#"[{"$id":"row"}]"#).unwrap();
    let source = JsonSource::from_resource_ids(
        "database:with:colon",
        "table:with:colon",
        path,
        utopia_storage::Local::new(dir.path()),
        None,
    );
    let dest = MockDestination::new();
    let mut transfer = TransferNs::new(source, dest);
    transfer.run(&[TYPE_ROW], &mut |_| {}, None, None).unwrap();
    let row = transfer
        .destination()
        .get_resource_by_id(GROUP_DATABASES, TYPE_ROW, "row")
        .expect("row");
    match row {
        AnyResource::Row(r) => {
            assert_eq!(r.get_table().get_id(), "table:with:colon");
            assert_eq!(r.get_table().get_database().get_id(), "database:with:colon");
        }
        _ => panic!("expected row"),
    }
}

#[test]
fn csv_detect_delimiter() {
    assert_eq!(CsvSource::detect_delimiter("name,email,city"), ',');
    assert_eq!(CsvSource::detect_delimiter("name;email;city;role"), ';');
    assert_eq!(CsvSource::detect_delimiter("name\temail\tcity"), '\t');
}

#[test]
fn extract_services() {
    let resources = TransferNs::<MockSource, MockDestination>::extract_services(&["auth"]).unwrap();
    assert!(resources.contains(&"user"));
    assert!(TransferNs::<MockSource, MockDestination>::extract_services(&["nope"]).is_err());
}

#[test]
fn constructors() {
    let _ = Firebase::new(Map::new());
    let _ = NHost::new("sub", "eu", "secret", "db", "user", "pass", "5432");
    let _ = Supabase::new(
        "http://localhost",
        "key",
        "localhost",
        "db",
        "user",
        "pass",
        "5432",
    );
    let _ = AppwriteSource::new(
        "proj",
        "http://localhost/v1",
        "key",
        AppwriteSource::SOURCE_API,
    );
    let dir = tempfile::tempdir().unwrap();
    let _ = LocalDestination::new(dir.path());
}
