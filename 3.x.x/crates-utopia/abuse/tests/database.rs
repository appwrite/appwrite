use serde_json::json;
use utopia_abuse::adapters::time_limit::Database as TimeLimitDatabase;
use utopia_abuse::database::{Database, Document, MemoryDatabase, Query};
use utopia_abuse::{Abuse, AbuseError, Adapter};

fn setup_db() -> MemoryDatabase {
    let db = MemoryDatabase::new("utopiaTests");
    db.create();
    let adapter = TimeLimitDatabase::new("", 1, 1, db.clone());
    adapter.setup().expect("setup");
    db
}

#[test]
fn setup_requires_existing_database() {
    let db = MemoryDatabase::new("missing");
    let adapter = TimeLimitDatabase::new("", 1, 1, db);
    let err = adapter.setup().unwrap_err();
    assert!(matches!(err, AbuseError::DatabaseNotCreated));
}

#[test]
fn setup_is_idempotent() {
    let db = setup_db();
    let adapter = TimeLimitDatabase::new("", 1, 1, db);
    adapter.setup().unwrap();
    adapter.setup().unwrap();
}

#[test]
fn hit_remaining_reset_cleanup() {
    let db = setup_db();
    let mut adapter = TimeLimitDatabase::new("login-{{ip}}", 2, 60, db.clone());
    adapter.set_param("{{ip}}", "10.0.0.1");
    let mut abuse = Abuse::new(adapter);
    assert_eq!(abuse.adapter_mut().remaining().unwrap(), 1);
    assert!(!abuse.check().unwrap());
    assert_eq!(abuse.adapter_mut().remaining().unwrap(), 0);
    assert!(!abuse.check().unwrap());
    assert!(abuse.check().unwrap());

    abuse.reset().unwrap();
    assert!(!abuse.check().unwrap());
    assert!(!abuse.check().unwrap());
    assert!(abuse.check().unwrap());

    let logs = abuse.get_logs(None, Some(25)).unwrap();
    assert!(!logs.is_empty());

    assert!(abuse.cleanup(2_000_000_000).unwrap());
    let logs = abuse.get_logs(None, Some(25)).unwrap();
    assert!(logs.is_empty());
}

#[test]
fn unlimited_skips_storage() {
    let db = setup_db();
    let mut abuse = Abuse::new(TimeLimitDatabase::new("free", 0, 60, db));
    for _ in 0..10 {
        assert!(!abuse.check().unwrap());
    }
}

#[test]
fn find_one_empty_and_unique_duplicate() {
    let db = setup_db();
    let empty = db
        .find_one("abuse", &[Query::equal("key", vec![json!("missing")])])
        .unwrap();
    assert!(empty.is_empty());

    let mut doc = serde_json::Map::new();
    doc.insert("key".into(), json!("k"));
    doc.insert("time".into(), json!("2020-01-01 00:00:00.000"));
    doc.insert("count".into(), json!(1));
    db.create_document("abuse", Document::new(doc.clone()))
        .unwrap();
    let dup = db.create_document("abuse", Document::new(doc));
    assert!(matches!(
        dup.unwrap_err(),
        utopia_abuse::database::DatabaseError::Duplicate
    ));
}

#[test]
fn delete_database() {
    let db = setup_db();
    db.delete();
    assert!(!db.exists("utopiaTests").unwrap());
}
