use serde_json::json;
use utopia_usage::Metric;

#[test]
fn get_event_schema_returns_attribute_definitions() {
    let schema = Metric::get_event_schema();
    assert_eq!(schema.len(), 3 + Metric::EVENT_COLUMNS.len());
    assert_eq!(schema[0]["$id"], json!("metric"));
    assert_eq!(schema[0]["type"], json!("string"));
    assert_eq!(schema[0]["size"], json!(255));
    assert_eq!(schema[0]["required"], json!(true));
    assert_eq!(schema[1]["$id"], json!("value"));
    assert_eq!(schema[1]["type"], json!("integer"));
    assert_eq!(schema[2]["$id"], json!("time"));
    assert_eq!(schema[2]["type"], json!("datetime"));
    let ids: Vec<_> = schema
        .iter()
        .filter_map(|a| a["$id"].as_str().map(str::to_owned))
        .collect();
    for col in Metric::EVENT_COLUMNS {
        assert!(ids.contains(&(*col).to_owned()), "missing {col}");
    }
}

#[test]
fn get_gauge_schema() {
    let schema = Metric::get_gauge_schema();
    assert_eq!(schema.len(), 3 + Metric::GAUGE_COLUMNS.len());
    let ids: Vec<_> = schema
        .iter()
        .filter_map(|a| a["$id"].as_str().map(str::to_owned))
        .collect();
    for col in Metric::GAUGE_COLUMNS {
        assert!(ids.contains(&(*col).to_owned()));
    }
}

#[test]
fn get_schema_returns_event_schema() {
    assert_eq!(Metric::get_schema(), Metric::get_event_schema());
}

#[test]
fn event_indexes() {
    let indexes = Metric::get_event_indexes();
    let ids: Vec<_> = indexes
        .iter()
        .filter_map(|i| i["$id"].as_str().map(str::to_owned))
        .collect();
    assert!(!ids.iter().any(|i| i == "index-userAgent"));
    let mut indexed = Vec::new();
    for idx in &indexes {
        if let Some(arr) = idx["attributes"].as_array() {
            for a in arr {
                if let Some(s) = a.as_str() {
                    indexed.push(s.to_owned());
                }
            }
        }
    }
    for col in [
        "path",
        "method",
        "status",
        "service",
        "resourceType",
        "resourceId",
        "resourceInternalId",
        "teamId",
        "teamInternalId",
        "country",
        "region",
        "hostname",
        "ip",
        "osName",
        "clientType",
        "clientName",
        "deviceName",
    ] {
        assert!(indexed.contains(&col.to_owned()), "missing {col}");
    }
}

#[test]
fn gauge_indexes() {
    let indexes = Metric::get_gauge_indexes();
    assert_eq!(indexes.len(), Metric::GAUGE_COLUMNS.len());
}

#[test]
fn get_indexes_returns_event_indexes() {
    assert_eq!(Metric::get_indexes(), Metric::get_event_indexes());
}

#[test]
fn validate_accepts_valid_event_data() {
    let data = json!({
        "metric": "requests",
        "value": 100,
        "time": "2024-01-01 12:00:00",
        "path": "/v1/storage/files",
        "method": "POST",
        "status": "201",
        "resourceType": "bucket",
        "resourceId": "abc123",
        "region": "us",
    })
    .as_object()
    .cloned()
    .unwrap();
    Metric::validate(&data, "event").unwrap();
}

#[test]
fn extract_columns_unknown_key() {
    let mut tags = serde_json::Map::new();
    tags.insert("notAColumn".into(), json!("x"));
    let err = Metric::extract_columns(&tags, "event").unwrap_err();
    assert!(err.to_string().contains("Unknown column 'notAColumn'"));
}

#[test]
fn extract_columns_lowercases_country() {
    let mut tags = serde_json::Map::new();
    tags.insert("country".into(), json!("US"));
    let cols = Metric::extract_columns(&tags, "event").unwrap();
    assert_eq!(cols["country"], json!("us"));
}
