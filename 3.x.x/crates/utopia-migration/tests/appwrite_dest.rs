//! Ports of Appwrite destination `PHPUnit` suites (Memory adapter, no live HTTP).

use serde_json::{json, Value};
use utopia_cache::adapter::Memory as CacheMemory;
use utopia_cache::Cache as UtopiaCache;
use utopia_database::adapter::Memory;
use utopia_database::constants::{
    LENGTH_KEY, PERMISSION_READ, VAR_BOOLEAN, VAR_INTEGER, VAR_STRING,
};
use utopia_database::{Database as UtopiaDatabase, Document};
use utopia_migration::destinations::appwrite::CollectionStructure;
use utopia_migration::prelude::*;
use utopia_migration::resource::{
    STATUS_SKIPPED, TYPE_COLUMN, TYPE_DATABASE, TYPE_INDEX, TYPE_TABLE,
};
use utopia_migration::resources::database::Index;
use utopia_migration::transfer::Transfer;
use utopia_migration::{AppwriteDestination, OnDuplicate};

fn attr_doc(
    id: &str,
    type_: &str,
    required: bool,
    default: Value,
    size: i64,
    array: bool,
    filters: Vec<Value>,
) -> Document {
    Document::try_from_json(json!({
        "$id": id,
        "key": id,
        "type": type_,
        "size": size,
        "required": required,
        "default": default,
        "array": array,
        "signed": true,
        "filters": filters,
    }))
    .expect("attribute document")
}

fn attr_value(
    id: &str,
    type_: &str,
    required: bool,
    default: Value,
    size: i64,
    array: bool,
    filters: Vec<Value>,
) -> Value {
    json!({
        "$id": id,
        "key": id,
        "type": type_,
        "size": size,
        "required": required,
        "default": default,
        "array": array,
        "signed": true,
        "filters": filters,
    })
}

fn project_database(with_status: bool) -> UtopiaDatabase<Memory> {
    let mut db = UtopiaDatabase::new(Memory::new(), UtopiaCache::new(CacheMemory::new()));
    db.set_database("appwrite").unwrap();
    db.set_namespace("_project").unwrap();
    db.create(None).unwrap();
    // Subquery filters are not registered; dest stores column/index docs on the
    // table meta `attributes` array, which PHP types as a string + filter.
    db.disable_validation();

    let mut database_attrs = vec![
        attr_doc("name", VAR_STRING, true, Value::Null, 256, false, vec![]),
        attr_doc("enabled", VAR_BOOLEAN, false, json!(true), 0, false, vec![]),
        attr_doc(
            "search",
            VAR_STRING,
            false,
            Value::Null,
            16384,
            false,
            vec![],
        ),
        attr_doc(
            "originalId",
            VAR_STRING,
            false,
            Value::Null,
            LENGTH_KEY,
            false,
            vec![],
        ),
        attr_doc(
            "type",
            VAR_STRING,
            false,
            json!("tablesdb"),
            128,
            false,
            vec![],
        ),
        attr_doc(
            "database",
            VAR_STRING,
            false,
            Value::Null,
            2000,
            false,
            vec![],
        ),
    ];
    if with_status {
        database_attrs.push(attr_doc(
            "status",
            VAR_STRING,
            false,
            Value::Null,
            16,
            false,
            vec![],
        ));
    }
    db.create_collection("databases", database_attrs, vec![], None, true)
        .unwrap();

    db.create_collection(
        "attributes",
        vec![
            attr_doc("key", VAR_STRING, false, Value::Null, 256, false, vec![]),
            attr_doc(
                "databaseInternalId",
                VAR_STRING,
                false,
                Value::Null,
                LENGTH_KEY,
                false,
                vec![],
            ),
            attr_doc(
                "databaseId",
                VAR_STRING,
                false,
                Value::Null,
                LENGTH_KEY,
                false,
                vec![],
            ),
            attr_doc(
                "collectionInternalId",
                VAR_STRING,
                false,
                Value::Null,
                LENGTH_KEY,
                false,
                vec![],
            ),
            attr_doc(
                "collectionId",
                VAR_STRING,
                false,
                Value::Null,
                LENGTH_KEY,
                false,
                vec![],
            ),
            attr_doc("type", VAR_STRING, false, Value::Null, 256, false, vec![]),
            attr_doc("status", VAR_STRING, false, Value::Null, 64, false, vec![]),
            attr_doc("size", VAR_INTEGER, false, Value::Null, 0, false, vec![]),
            attr_doc(
                "required",
                VAR_BOOLEAN,
                false,
                json!(false),
                0,
                false,
                vec![],
            ),
            attr_doc("signed", VAR_BOOLEAN, false, json!(true), 0, false, vec![]),
            attr_doc(
                "default",
                VAR_STRING,
                false,
                Value::Null,
                16384,
                false,
                vec![],
            ),
            attr_doc("array", VAR_BOOLEAN, false, json!(false), 0, false, vec![]),
            attr_doc("format", VAR_STRING, false, Value::Null, 64, false, vec![]),
            attr_doc(
                "formatOptions",
                VAR_STRING,
                false,
                Value::Null,
                16384,
                false,
                vec![json!("json")],
            ),
            attr_doc("filters", VAR_STRING, false, Value::Null, 64, true, vec![]),
            attr_doc(
                "options",
                VAR_STRING,
                false,
                Value::Null,
                16384,
                false,
                vec![json!("json")],
            ),
            attr_doc("error", VAR_STRING, false, Value::Null, 2048, false, vec![]),
        ],
        vec![],
        None,
        true,
    )
    .unwrap();

    db.create_collection(
        "indexes",
        vec![
            attr_doc("key", VAR_STRING, false, Value::Null, 256, false, vec![]),
            attr_doc("status", VAR_STRING, false, Value::Null, 64, false, vec![]),
            attr_doc(
                "databaseInternalId",
                VAR_STRING,
                false,
                Value::Null,
                LENGTH_KEY,
                false,
                vec![],
            ),
            attr_doc(
                "databaseId",
                VAR_STRING,
                false,
                Value::Null,
                LENGTH_KEY,
                false,
                vec![],
            ),
            attr_doc(
                "collectionInternalId",
                VAR_STRING,
                false,
                Value::Null,
                LENGTH_KEY,
                false,
                vec![],
            ),
            attr_doc(
                "collectionId",
                VAR_STRING,
                false,
                Value::Null,
                LENGTH_KEY,
                false,
                vec![],
            ),
            attr_doc("type", VAR_STRING, false, Value::Null, 16, false, vec![]),
            attr_doc(
                "attributes",
                VAR_STRING,
                false,
                Value::Null,
                256,
                true,
                vec![],
            ),
            attr_doc("lengths", VAR_INTEGER, false, Value::Null, 0, true, vec![]),
            attr_doc("orders", VAR_STRING, false, Value::Null, 4, true, vec![]),
            attr_doc("error", VAR_STRING, false, Value::Null, 2048, false, vec![]),
        ],
        vec![],
        None,
        true,
    )
    .unwrap();

    db
}

fn table_collection_structure() -> CollectionStructure {
    CollectionStructure {
        attributes: vec![
            attr_value(
                "databaseInternalId",
                VAR_STRING,
                false,
                Value::Null,
                LENGTH_KEY,
                false,
                vec![],
            ),
            attr_value(
                "databaseId",
                VAR_STRING,
                false,
                Value::Null,
                LENGTH_KEY,
                false,
                vec![],
            ),
            attr_value("name", VAR_STRING, false, Value::Null, 256, false, vec![]),
            attr_value("enabled", VAR_BOOLEAN, false, json!(true), 0, false, vec![]),
            attr_value(
                "documentSecurity",
                VAR_BOOLEAN,
                false,
                json!(false),
                0,
                false,
                vec![],
            ),
            attr_value(
                "search",
                VAR_STRING,
                false,
                Value::Null,
                16384,
                false,
                vec![],
            ),
            attr_value(
                "attributes",
                VAR_STRING,
                false,
                Value::Null,
                16384,
                true,
                vec![],
            ),
            attr_value(
                "indexes",
                VAR_STRING,
                false,
                Value::Null,
                16384,
                true,
                vec![],
            ),
        ],
        indexes: vec![],
    }
}

fn make_dest(db: UtopiaDatabase<Memory>, on_duplicate: OnDuplicate) -> AppwriteDestination<Memory> {
    AppwriteDestination::with_database(
        "destination-project",
        "http://example.test/v1",
        "test-key",
        db,
        table_collection_structure(),
        "1",
        on_duplicate,
        None,
    )
}

fn shop_source(column_sizes: [i64; 2]) -> (MockSource, Table) {
    let mut source = MockSource::new();
    let mut database = Database::new("shop", "Shop");
    database.set_type("tablesdb");
    database.set_database(Some("source-dsn".into()));
    let table = Table::new(database.clone(), "Orders", "orders");
    source.push_mock_resource(database);
    source.push_mock_resource(table.clone());
    let mut col_a = Column::text("reference", table.clone(), column_sizes[0]);
    col_a.set_id("reference");
    let mut col_b = Column::text("channel", table.clone(), column_sizes[1]);
    col_b.set_id("channel");
    source.push_mock_resource(col_a);
    source.push_mock_resource(col_b);
    (source, table)
}

fn error_messages(dest: &AppwriteDestination<Memory>) -> Vec<String> {
    dest.get_errors()
        .iter()
        .map(|e| e.get_message().to_owned())
        .collect()
}

fn index_document(dest: &mut AppwriteDestination<Memory>) -> Document {
    dest.database_mut()
        .expect("db")
        .skip_authorization(|db| db.find("indexes", &[], PERMISSION_READ))
        .unwrap_or_default()
        .into_iter()
        .next()
        .unwrap_or_default()
}

fn lengths_of(doc: &Document) -> Vec<i64> {
    doc.get_attribute("lengths")
        .as_array()
        .map(|a| a.values().map(|v| v.as_i64().unwrap_or(0)).collect())
        .unwrap_or_default()
}

#[test]
fn restored_index_carries_source_prefix_lengths() {
    let (source, table) = shop_source([600, 600]);
    let dest = make_dest(project_database(false), OnDuplicate::Fail);
    let mut transfer = Transfer::new(source, dest);
    transfer
        .run(
            &[TYPE_DATABASE, TYPE_TABLE, TYPE_COLUMN],
            &mut |_| {},
            None,
            None,
        )
        .unwrap();
    transfer.source_mut().push_mock_resource(Index::new(
        "idx_reference_channel",
        "idx_reference_channel",
        table,
        "key",
        vec!["reference".into(), "channel".into()],
        vec![100, 20],
        vec!["ASC".into(), "ASC".into()],
    ));
    transfer
        .run(&[TYPE_INDEX], &mut |_| {}, None, None)
        .unwrap();
    assert_eq!(error_messages(transfer.destination()), Vec::<String>::new());
    let created = index_document(transfer.destination_mut());
    assert!(!created.is_empty(), "The index must be created");
    assert_eq!(lengths_of(&created), vec![100, 20]);
}

#[test]
fn restored_index_without_source_lengths_fails_adapter_limit() {
    let (source, table) = shop_source([600, 600]);
    let dest = make_dest(project_database(false), OnDuplicate::Fail);
    let mut transfer = Transfer::new(source, dest);
    transfer
        .run(
            &[TYPE_DATABASE, TYPE_TABLE, TYPE_COLUMN],
            &mut |_| {},
            None,
            None,
        )
        .unwrap();
    transfer.source_mut().push_mock_resource(Index::new(
        "idx_reference_channel",
        "idx_reference_channel",
        table,
        "key",
        vec!["reference".into(), "channel".into()],
        vec![],
        vec!["ASC".into(), "ASC".into()],
    ));
    transfer
        .run(&[TYPE_INDEX], &mut |_| {}, None, None)
        .unwrap();
    let messages = error_messages(transfer.destination());
    assert!(
        !messages.is_empty(),
        "Expected the full-width index to exceed the adapter limit"
    );
    assert!(
        messages[0].contains("Index length is longer than the maximum"),
        "{}",
        messages[0]
    );
    assert!(index_document(transfer.destination_mut()).is_empty());
}

#[test]
fn zero_source_length_means_no_prefix() {
    let (source, table) = shop_source([600, 30]);
    let dest = make_dest(project_database(false), OnDuplicate::Fail);
    let mut transfer = Transfer::new(source, dest);
    transfer
        .run(
            &[TYPE_DATABASE, TYPE_TABLE, TYPE_COLUMN],
            &mut |_| {},
            None,
            None,
        )
        .unwrap();
    transfer.source_mut().push_mock_resource(Index::new(
        "idx_reference_channel",
        "idx_reference_channel",
        table,
        "key",
        vec!["reference".into(), "channel".into()],
        vec![100, 0],
        vec!["ASC".into(), "ASC".into()],
    ));
    transfer
        .run(&[TYPE_INDEX], &mut |_| {}, None, None)
        .unwrap();
    assert_eq!(error_messages(transfer.destination()), Vec::<String>::new());
    assert_eq!(
        lengths_of(&index_document(transfer.destination_mut())),
        vec![100, 0]
    );
}

#[test]
fn overwrite_does_not_treat_length_only_difference_as_match() {
    let (source, table) = shop_source([600, 600]);
    let dest = make_dest(project_database(false), OnDuplicate::Overwrite);
    let mut transfer = Transfer::new(source, dest);
    transfer
        .run(
            &[TYPE_DATABASE, TYPE_TABLE, TYPE_COLUMN],
            &mut |_| {},
            None,
            None,
        )
        .unwrap();

    let mut existing = Index::new(
        "idx_reference_channel",
        "idx_reference_channel",
        table.clone(),
        "key",
        vec!["reference".into(), "channel".into()],
        vec![50, 20],
        vec!["ASC".into(), "ASC".into()],
    );
    existing.set_created_at("2026-01-01 00:00:00");
    existing.set_updated_at("2026-01-01 00:00:00");
    transfer.source_mut().push_mock_resource(existing);
    transfer
        .run(&[TYPE_INDEX], &mut |_| {}, None, None)
        .unwrap();

    let mut newer = Index::new(
        "idx_reference_channel",
        "idx_reference_channel",
        table,
        "key",
        vec!["reference".into(), "channel".into()],
        vec![100, 20],
        vec!["ASC".into(), "ASC".into()],
    );
    newer.set_created_at("2026-06-01 00:00:00");
    newer.set_updated_at("2026-06-01 00:00:00");
    transfer.source_mut().push_mock_resource(newer);
    transfer
        .run(&[TYPE_INDEX], &mut |_| {}, None, None)
        .unwrap();

    let status = transfer
        .get_cache()
        .get(TYPE_INDEX)
        .values()
        .filter_map(|e| e.as_resource())
        .find(|r| r.get_id() == "idx_reference_channel")
        .map(|r| r.get_status().to_owned());
    assert_ne!(
        status.as_deref(),
        Some(STATUS_SKIPPED),
        "A length-only difference must not be reported as already existing on the destination."
    );
}

fn run_database_status(with_status: bool, explicit: bool) -> (usize, Option<String>, Vec<String>) {
    let db = project_database(with_status);
    let mut source = MockSource::new();
    source.supports_database_status = true;
    let mut resource = Database::new("database", "Database");
    resource.set_type("tablesdb");
    resource.set_database(Some("source-dsn".into()));
    resource.set_database_status(Some("ready".into()));
    source.push_mock_resource(resource);
    let dest = make_dest(db, OnDuplicate::Fail);
    let mut transfer = Transfer::new(source, dest);
    if explicit {
        transfer
            .run_with_resource_selector(
                &[TYPE_DATABASE],
                &mut |_| {},
                "database",
                "1",
                TYPE_DATABASE,
                "",
                "",
                "",
            )
            .unwrap();
    } else {
        transfer
            .run(&[TYPE_DATABASE], &mut |_| {}, None, None)
            .unwrap();
    }
    let run_count = transfer.destination().run_count;
    let errors = error_messages(transfer.destination());
    let created = transfer
        .destination_mut()
        .database_mut()
        .expect("db")
        .skip_authorization(|db| db.get_document("databases", "database", &[], false))
        .unwrap_or_else(|_| Document::new());
    let status = created
        .get_attribute("status")
        .as_str()
        .map(ToOwned::to_owned);
    (run_count, status, errors)
}

#[test]
fn database_creation_omits_status() {
    for explicit in [false, true] {
        let (run_count, status, errors) = run_database_status(false, explicit);
        assert_eq!(errors, Vec::<String>::new());
        assert_eq!(run_count, 1);
        assert_eq!(status, None);
    }
}

#[test]
fn database_creation_preserves_lifecycle() {
    for explicit in [false, true] {
        let (run_count, status, errors) = run_database_status(true, explicit);
        assert_eq!(errors, Vec::<String>::new());
        assert_eq!(run_count, 1);
        assert_eq!(status.as_deref(), Some("ready"));
    }
}
