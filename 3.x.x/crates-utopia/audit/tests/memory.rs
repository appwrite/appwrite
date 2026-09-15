//! Ports `tests/Audit/AuditBase.php` against the in-memory adapter.

use chrono::{Duration, TimeZone, Utc};
use serde_json::{json, Map, Value};
use utopia_audit::{adapter::SqlAdapter, Audit, Log, Memory, Query};

fn required() -> Map<String, Value> {
    Map::new()
}

fn merge_required(mut data: Map<String, Value>) -> Map<String, Value> {
    for (k, v) in required() {
        data.entry(k).or_insert(v);
    }
    data
}

fn apply_required_batch(events: Vec<Map<String, Value>>) -> Vec<Map<String, Value>> {
    events.into_iter().map(merge_required).collect()
}

fn create_logs(audit: &mut Audit<Memory>) {
    let user_id = Some("userId");
    let ua = "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_14_6) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/79.0.3945.88 Safari/537.36";
    let ip = "127.0.0.1";
    let data = merge_required(json_map(json!({"key1": "value1", "key2": "value2"})));
    assert!(audit
        .log(
            user_id,
            "update",
            "database/document/1",
            ua,
            ip,
            data.clone()
        )
        .is_ok());
    assert!(audit
        .log(
            user_id,
            "update",
            "database/document/2",
            ua,
            ip,
            data.clone()
        )
        .is_ok());
    assert!(audit
        .log(
            user_id,
            "delete",
            "database/document/2",
            ua,
            ip,
            data.clone()
        )
        .is_ok());
    assert!(audit.log(None, "insert", "user/null", ua, ip, data).is_ok());
}

fn json_map(value: Value) -> Map<String, Value> {
    match value {
        Value::Object(m) => m,
        _ => Map::new(),
    }
}

fn setup_audit() -> Audit<Memory> {
    let mut audit = Audit::new(Memory::new());
    audit.setup().unwrap();
    audit.cleanup(Utc::now()).unwrap();
    create_logs(&mut audit);
    audit
}

fn none_dt() -> (Option<chrono::DateTime<Utc>>, Option<chrono::DateTime<Utc>>) {
    (None, None)
}

#[test]
fn ping() {
    let audit = setup_audit();
    assert!(audit.ping());
}

#[test]
fn get_logs_by_user() {
    let audit = setup_audit();
    let (after, before) = none_dt();
    let logs = audit
        .get_logs_by_user("userId", after, before, 25, 0, false)
        .unwrap();
    assert_eq!(logs.len(), 3);
    let count = audit
        .count_logs_by_user("userId", after, before, None)
        .unwrap();
    assert_eq!(count, 3);
    let logs1 = audit
        .get_logs_by_user("userId", after, before, 1, 1, false)
        .unwrap();
    assert_eq!(logs1.len(), 1);
    assert_eq!(logs1[0].get_id(), logs[1].get_id());
}

#[test]
fn get_logs_by_user_and_events() {
    let audit = setup_audit();
    let (after, before) = none_dt();
    let logs1 = audit
        .get_logs_by_user_and_events("userId", &["update".into()], after, before, 25, 0, false)
        .unwrap();
    let logs2 = audit
        .get_logs_by_user_and_events(
            "userId",
            &["update".into(), "delete".into()],
            after,
            before,
            25,
            0,
            false,
        )
        .unwrap();
    assert_eq!(logs1.len(), 2);
    assert_eq!(logs2.len(), 3);
    let logs3 = audit
        .get_logs_by_user_and_events(
            "userId",
            &["update".into(), "delete".into()],
            after,
            before,
            1,
            1,
            false,
        )
        .unwrap();
    assert_eq!(logs3.len(), 1);
    assert_eq!(logs3[0].get_id(), logs2[1].get_id());
}

#[test]
fn get_logs_by_resource_and_events() {
    let audit = setup_audit();
    let (after, before) = none_dt();
    let logs1 = audit
        .get_logs_by_resource_and_events(
            "database/document/1",
            &["update".into()],
            after,
            before,
            25,
            0,
            false,
        )
        .unwrap();
    let logs2 = audit
        .get_logs_by_resource_and_events(
            "database/document/2",
            &["update".into(), "delete".into()],
            after,
            before,
            25,
            0,
            false,
        )
        .unwrap();
    assert_eq!(logs1.len(), 1);
    assert_eq!(logs2.len(), 2);
}

#[test]
fn get_logs_by_resource() {
    let audit = setup_audit();
    let (after, before) = none_dt();
    let logs1 = audit
        .get_logs_by_resource("database/document/1", after, before, 25, 0, false)
        .unwrap();
    let logs2 = audit
        .get_logs_by_resource("database/document/2", after, before, 25, 0, false)
        .unwrap();
    assert_eq!(logs1.len(), 1);
    assert_eq!(logs2.len(), 2);
    let logs5 = audit
        .get_logs_by_resource("user/null", after, before, 25, 0, false)
        .unwrap();
    assert_eq!(logs5.len(), 1);
    assert!(logs5[0]["userId"].is_null());
    assert_eq!(logs5[0]["ip"], json!("127.0.0.1"));
}

#[test]
fn get_log_by_id() {
    let mut audit = setup_audit();
    let log = audit
        .log(
            Some("testGetByIdUser"),
            "create",
            "test/resource/123",
            "Mozilla/5.0 Test",
            "192.168.1.100",
            merge_required(json_map(json!({"test": "getById"}))),
        )
        .unwrap();
    let retrieved = audit.get_log_by_id(&log.get_id()).unwrap().unwrap();
    assert_eq!(retrieved.get_id(), log.get_id());
    assert_eq!(
        retrieved.get_attribute("userId").and_then(Value::as_str),
        Some("testGetByIdUser")
    );
    assert_eq!(
        retrieved.get_attribute("event").and_then(Value::as_str),
        Some("create")
    );
    assert!(audit
        .get_log_by_id("non-existent-id-12345")
        .unwrap()
        .is_none());
}

#[test]
fn log_by_batch() {
    let mut audit = setup_audit();
    audit.cleanup(Utc::now()).unwrap();
    let ts1 = (Utc::now() - Duration::seconds(120))
        .format("%Y-%m-%dT%H:%M:%S%.3f%:z")
        .to_string();
    let ts2 = (Utc::now() - Duration::seconds(60))
        .format("%Y-%m-%dT%H:%M:%S%.3f%:z")
        .to_string();
    let ts3 = Utc::now().format("%Y-%m-%dT%H:%M:%S%.3f%:z").to_string();
    let batch = apply_required_batch(vec![
        json_map(json!({
            "userId": "batchUserId",
            "event": "create",
            "resource": "database/document/batch1",
            "userAgent": "Mozilla/5.0 (Test User Agent)",
            "ip": "192.168.1.1",
            "data": {"key": "value1"},
            "time": ts1,
        })),
        json_map(json!({
            "userId": "batchUserId",
            "event": "update",
            "resource": "database/document/batch2",
            "userAgent": "Mozilla/5.0 (Test User Agent)",
            "ip": "192.168.1.1",
            "data": {"key": "value2"},
            "time": ts2,
        })),
        json_map(json!({
            "userId": "batchUserId",
            "event": "delete",
            "resource": "database/document/batch3",
            "userAgent": "Mozilla/5.0 (Test User Agent)",
            "ip": "192.168.1.1",
            "data": {"key": "value3"},
            "time": ts3,
        })),
        json_map(json!({
            "userId": null,
            "event": "insert",
            "resource": "user1/null",
            "userAgent": "Mozilla/5.0 (Test User Agent)",
            "ip": "192.168.1.1",
            "data": {"key": "value4"},
            "time": ts3,
        })),
    ]);
    assert!(audit.log_batch(batch).unwrap());
    let (after, before) = none_dt();
    let logs = audit
        .get_logs_by_user("batchUserId", after, before, 25, 0, false)
        .unwrap();
    assert_eq!(logs.len(), 3);
    assert_eq!(
        logs[0].get_attribute("event").and_then(Value::as_str),
        Some("delete")
    );
    assert_eq!(
        logs[1].get_attribute("event").and_then(Value::as_str),
        Some("update")
    );
    assert_eq!(
        logs[2].get_attribute("event").and_then(Value::as_str),
        Some("create")
    );
}

#[test]
fn find_and_count() {
    let mut audit = setup_audit();
    audit.cleanup(Utc::now()).unwrap();
    let mut batch = Vec::new();
    for i in 0..3 {
        let t = Utc.with_ymd_and_hms(2024, 6, 15, 12, i, 0).unwrap();
        batch.push(json_map(json!({
            "userId": "userId",
            "event": format!("event_{i}"),
            "resource": format!("doc/{i}"),
            "userAgent": "Mozilla/5.0",
            "ip": "192.168.1.1",
            "data": {"sequence": i},
            "time": t.format("%Y-%m-%d %H:%M:%S%.3f").to_string(),
        })));
    }
    audit.log_batch(apply_required_batch(batch)).unwrap();
    let logs = audit.find(&[Query::equal("userId", "userId")]).unwrap();
    assert_eq!(logs.len(), 3);
    let logs = audit
        .find(&[Query::equal("userId", "userId"), Query::limit(2)])
        .unwrap();
    assert_eq!(logs.len(), 2);
    let count = audit
        .count(
            &[
                Query::equal("userId", "userId"),
                Query::limit(2),
                Query::offset(1),
            ],
            None,
        )
        .unwrap();
    assert_eq!(count, 3);
    let contains = audit
        .find(&[Query::contains("event", vec!["event_0", "event_1"])])
        .unwrap();
    assert!(contains.len() >= 2);
    let substring = audit
        .find(&[Query::contains("event", vec!["vent_0"])])
        .unwrap();
    assert!(!substring.is_empty());
    for log in &substring {
        assert!(log.get_event().contains("vent_0"));
    }
}

#[test]
fn parse_resource_paths() {
    let adapter = Memory::new();
    let parsed = adapter.parse_resource("database/abc/collection/def/document/ghi");
    assert_eq!(parsed.resource_id, "ghi");
    assert_eq!(parsed.resource_type, "document");
    assert_eq!(parsed.resource_parent, "database/abc/collection/def");
    let odd = adapter.parse_resource("odd/path/here");
    assert_eq!(odd.resource_id, "odd/path/here");
    assert!(odd.resource_type.is_empty());
}

#[test]
fn log_getters() {
    let mut data = Map::new();
    data.insert("$id".into(), json!("abc"));
    data.insert("event".into(), json!("create"));
    data.insert("userId".into(), json!("u1"));
    let log = Log::new(data);
    assert_eq!(log.get_id(), "abc");
    assert_eq!(log.get_event(), "create");
    assert_eq!(log.get_user_id().as_deref(), Some("u1"));
}
