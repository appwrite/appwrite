use utopia_usage::{Adapter, ClickHouse};

fn adapter() -> ClickHouse {
    ClickHouse::new(
        "clickhouse",
        "default",
        "",
        8123,
        false,
        "utopia_usage_coltype",
        "default",
        false,
        false,
        true,
        0.0,
        None,
    )
    .unwrap()
}

#[test]
fn low_cardinality_premium_geo() {
    let a = adapter();
    for col in [
        "continentCode",
        "subdivisions",
        "connectionType",
        "connectionUsageType",
        "autonomousSystemNumber",
    ] {
        assert_eq!(
            a.get_column_type(col, "event").unwrap(),
            "LowCardinality(Nullable(String))",
            "{col}"
        );
    }
}

#[test]
fn high_cardinality_premium_geo() {
    let a = adapter();
    for col in [
        "city",
        "isp",
        "autonomousSystemOrganization",
        "connectionOrganization",
    ] {
        assert_eq!(
            a.get_column_type(col, "event").unwrap(),
            "Nullable(String)",
            "{col}"
        );
    }
}

#[test]
fn low_cardinality_sdk() {
    let a = adapter();
    for col in ["sdk", "sdkVersion"] {
        assert_eq!(
            a.get_column_type(col, "event").unwrap(),
            "LowCardinality(Nullable(String))"
        );
    }
}

#[test]
fn low_cardinality_ordinal() {
    let a = adapter();
    assert_eq!(
        a.get_column_type("ordinal", "gauge").unwrap(),
        "LowCardinality(Nullable(String))"
    );
}

#[test]
fn constructor_rejects_empty_host() {
    let err = ClickHouse::new(
        "", "default", "", 8123, false, "", "default", false, false, true, 0.0, None,
    )
    .unwrap_err();
    assert!(err.to_string().contains("hostname"));
}

#[test]
fn live_health_check() {
    let host = std::env::var("CLICKHOUSE_HOST").unwrap_or_else(|_| "127.0.0.1".into());
    let port: i32 = std::env::var("CLICKHOUSE_PORT")
        .ok()
        .and_then(|p| p.parse().ok())
        .unwrap_or(8124);
    let a = ClickHouse::new(
        &host, "default", "", port, false, "", "default", false, false, true, 0.0, None,
    )
    .unwrap();
    let health = a.health_check();
    assert_eq!(
        health.get("healthy").and_then(serde_json::Value::as_bool),
        Some(true),
        "ClickHouse container (docker compose -f docker-compose.test.yml up -d clickhouse): {health:?}"
    );
}
