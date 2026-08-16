//! Ports `ClickHouse` constructor / schema tests that do not need a live server.

use serde_json::Value;
use utopia_audit::{Adapter, ClickHouse, SqlAdapter};

fn adapter() -> ClickHouse {
    ClickHouse::new("clickhouse", "default", "clickhouse", 8123, false).unwrap()
}

#[test]
fn constructor_validates_host() {
    let err = ClickHouse::new("", "default", "", 8123, false).unwrap_err();
    assert!(err
        .to_string()
        .contains("ClickHouse host is not a valid hostname or IP address"));
}

#[test]
fn constructor_validates_port_too_low() {
    let err = ClickHouse::new("localhost", "default", "", 0, false).unwrap_err();
    assert!(err
        .to_string()
        .contains("ClickHouse port must be between 1 and 65535"));
}

#[test]
fn constructor_validates_port_too_high() {
    let err = ClickHouse::new("localhost", "default", "", 65536, false).unwrap_err();
    assert!(err
        .to_string()
        .contains("ClickHouse port must be between 1 and 65535"));
}

#[test]
fn constructor_with_valid_parameters() {
    let adapter = ClickHouse::new("clickhouse", "testuser", "testpass", 8443, true).unwrap();
    assert_eq!(adapter.get_name(), "ClickHouse");
}

#[test]
fn set_database_validates() {
    let mut a = adapter();
    assert!(a
        .set_database("")
        .unwrap_err()
        .to_string()
        .contains("Database cannot be empty"));
    assert!(a
        .set_database("a".repeat(256))
        .unwrap_err()
        .to_string()
        .contains("Database cannot exceed 255 characters"));
    assert!(a
        .set_database("123invalid")
        .unwrap_err()
        .to_string()
        .contains("Database must start with a letter or underscore"));
    assert!(a
        .set_database("SELECT")
        .unwrap_err()
        .to_string()
        .contains("Database cannot be a reserved SQL keyword"));
    a.set_database("my_database_123").unwrap();
}

#[test]
fn set_table_validates() {
    let mut a = adapter();
    assert!(a
        .set_table("")
        .unwrap_err()
        .to_string()
        .contains("Table cannot be empty"));
    a.set_table("my_audit_logs").unwrap();
    assert_eq!(a.get_table(), "my_audit_logs");
}

#[test]
fn set_namespace_allows_empty() {
    let mut a = adapter();
    a.set_namespace("").unwrap();
    assert_eq!(a.get_namespace(), "");
    a.set_namespace("project_123").unwrap();
    assert_eq!(a.get_namespace(), "project_123");
    assert!(a
        .set_namespace("9invalid")
        .unwrap_err()
        .to_string()
        .contains("Namespace must start with a letter or underscore"));
}

#[test]
fn set_retention() {
    let mut a = adapter();
    assert!(a.get_retention().is_none());
    a.set_retention(Some(30)).unwrap();
    assert_eq!(a.get_retention(), Some(30));
    a.set_retention(None).unwrap();
    assert!(a.get_retention().is_none());
    assert!(a
        .set_retention(Some(0))
        .unwrap_err()
        .to_string()
        .contains("Retention must be a positive number of days"));
    assert!(a
        .set_retention(Some(-1))
        .unwrap_err()
        .to_string()
        .contains("Retention must be a positive number of days"));
}

#[test]
fn shared_tables_configuration() {
    let mut a = adapter();
    assert!(!a.is_shared_tables());
    assert!(a.get_tenant().is_none());
    a.set_shared_tables(true);
    assert!(a.is_shared_tables());
    a.set_tenant(Some(42));
    assert_eq!(a.get_tenant(), Some(42));
}

#[test]
fn clickhouse_adapter_attributes() {
    let a = adapter();
    let ids: Vec<String> = a
        .get_attributes()
        .into_iter()
        .filter_map(|m| m.get("$id").and_then(Value::as_str).map(str::to_owned))
        .collect();
    for expected in [
        "actorType",
        "actorId",
        "actorInternalId",
        "resourceParent",
        "resourceType",
        "resourceId",
        "resourceInternalId",
        "event",
        "resource",
        "userAgent",
        "ip",
        "country",
        "time",
        "data",
        "projectId",
        "projectInternalId",
        "teamId",
        "teamInternalId",
        "hostname",
        "city",
        "continentCode",
        "subdivisions",
        "isp",
        "autonomousSystemNumber",
        "autonomousSystemOrganization",
        "connectionType",
        "connectionUsageType",
        "connectionOrganization",
        "sdk",
        "sdkVersion",
        "osCode",
        "osName",
        "osVersion",
        "clientType",
        "clientCode",
        "clientName",
        "clientVersion",
        "clientEngine",
        "clientEngineVersion",
        "deviceName",
        "deviceBrand",
        "deviceModel",
    ] {
        assert!(ids.contains(&expected.to_owned()), "missing {expected}");
    }
}

#[test]
fn user_agent_column_types() {
    let a = adapter();
    assert!(a
        .get_column_definition("osName")
        .unwrap()
        .contains("LowCardinality(Nullable(String))"));
    assert!(a
        .get_column_definition("osVersion")
        .unwrap()
        .contains("Nullable(String)"));
    assert!(!a
        .get_column_definition("osVersion")
        .unwrap()
        .contains("LowCardinality"));
    assert!(a
        .get_column_definition("deviceModel")
        .unwrap()
        .contains("Nullable(String)"));
}

#[test]
fn premium_geo_column_types() {
    let a = adapter();
    assert!(a
        .get_column_definition("continentCode")
        .unwrap()
        .contains("LowCardinality"));
    assert!(a
        .get_column_definition("autonomousSystemNumber")
        .unwrap()
        .contains("Nullable(String)"));
    assert!(!a
        .get_column_definition("autonomousSystemNumber")
        .unwrap()
        .contains("LowCardinality"));
}

#[test]
fn required_columns_not_nullable() {
    let a = adapter();
    let def = a.get_column_definition("event").unwrap();
    assert!(def.contains("LowCardinality(String)"));
    assert!(!def.contains("Nullable"));
}

#[test]
fn parse_resource() {
    let a = adapter();
    let parsed = a.parse_resource("database/db1/collection/col1/document/doc1");
    assert_eq!(parsed.resource_id, "doc1");
    assert_eq!(parsed.resource_type, "document");
    assert_eq!(parsed.resource_parent, "database/db1/collection/col1");
}

#[test]
fn contains_rejects_empty_values() {
    use utopia_audit::{Adapter, Query};
    let a = adapter();
    let err = a
        .find(&[Query::contains("event", Vec::<String>::new())])
        .unwrap_err();
    assert!(err.to_string().contains("require at least one value"));
}

#[test]
fn equal_rejects_empty_values() {
    use utopia_audit::{Adapter, Query};
    let a = adapter();
    let q = Query::new(Query::TYPE_EQUAL, "event", vec![]);
    let err = a.find(&[q]).unwrap_err();
    assert!(err.to_string().contains("require at least one value"));
}

#[test]
fn order_random_rejected_with_cursor() {
    use utopia_audit::{Adapter, Query};
    let a = adapter();
    let err = a
        .find(&[Query::order_random(), Query::cursor_after("x")])
        .unwrap_err();
    assert!(err
        .to_string()
        .contains("Cursor pagination cannot be combined with orderRandom"));
}

#[test]
fn order_random_rejected_with_column_order() {
    use utopia_audit::{Adapter, Query};
    let a = adapter();
    let err = a
        .find(&[Query::order_random(), Query::order_desc("time")])
        .unwrap_err();
    assert!(err
        .to_string()
        .contains("orderRandom cannot be combined with orderAsc/orderDesc"));
}

#[test]
fn live_ping() {
    let host = std::env::var("CLICKHOUSE_HOST").unwrap_or_else(|_| "127.0.0.1".into());
    let port: i32 = std::env::var("CLICKHOUSE_PORT")
        .ok()
        .and_then(|p| p.parse().ok())
        .unwrap_or(8124);
    let a = ClickHouse::new(&host, "default", "", port, false).unwrap();
    assert!(
        a.ping(),
        "ClickHouse container (docker compose -f docker-compose.test.yml up -d clickhouse)"
    );
}
