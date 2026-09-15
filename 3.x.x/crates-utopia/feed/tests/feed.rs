use std::sync::Arc;

use serde_json::{json, Map, Value};
use utopia_cache::adapter::Memory as MemoryCache;
use utopia_cache::{
    Adapter as CacheAdapter, Cache as UtopiaCache, CacheError, CacheValue, LoadResult, SaveResult,
};
use utopia_cloudevents::CloudEvent;
use utopia_feed::prelude::*;
use utopia_feed::{
    Batch, CacheCursor, Consumer, Cursor, Extensions, FeedError, Id, Key, NoneCursor, NoneStore,
    RecordingTransport, Remote, Server, MAX_BATCH, MAX_TIMEOUT,
};

fn event(id: &str, type_name: &str) -> CloudEvent {
    CloudEvent::create(type_name, "urn:test", id)
}

fn events(count: i32) -> Vec<CloudEvent> {
    (0..count)
        .map(|i| event(&format!("1-{i}"), &format!("event-{i}")))
        .collect()
}

#[test]
fn id_validates() {
    assert!(Id::is_valid("1690000000000-0"));
    assert!(Id::is_valid("1690000000000-42"));
    assert!(Id::is_valid("0-0"));
    assert!(!Id::is_valid(""));
    assert!(!Id::is_valid("1690000000000"));
    assert!(!Id::is_valid("abc-0"));
    assert!(!Id::is_valid("-1-0"));
    assert!(!Id::is_valid("1690000000000-"));
    assert!(!Id::is_valid("(1690000000000-0"));
    assert!(!Id::is_valid("-"));
    assert!(!Id::is_valid("$"));
}

#[test]
fn id_after_and_decode() {
    assert_eq!(Id::after("1690000000000-0").unwrap(), "1690000000000-1");
    assert_eq!(Id::after("1690000000000-42").unwrap(), "1690000000000-43");
    assert!(Id::after("not-an-id").is_err());
    assert_eq!(
        Id::decode("1690000000000-7").unwrap(),
        (1_690_000_000_000, 7)
    );
    assert_eq!(Id::decode(&Id::encode(12, 34)).unwrap(), (12, 34));
    assert!(Id::decode("10-0").unwrap() > Id::decode("9-0").unwrap());
    assert!(Id::decode("10-2").unwrap() > Id::decode("10-1").unwrap());
    assert_eq!(Id::decode("10-0").unwrap(), Id::decode("10-0").unwrap());
    assert!(Id::decode("nope").is_err());
}

#[test]
fn key_layout() {
    assert_eq!(Key::feed("edge"), "feed:edge");
    assert_eq!(
        Key::cursor("edge", "invalidator"),
        "feed:edge:cursor:invalidator"
    );
    assert_ne!(Key::feed("edge:cursor:x"), Key::cursor("edge", "x"));
    assert_ne!(
        Key::cursor("a:cursor:b", "c"),
        Key::cursor("a", "b:cursor:c")
    );
    assert_ne!(Key::feed("a:b"), Key::feed("a%3Ab"));
    assert_ne!(Key::cursor("a:b", "c"), Key::cursor("a%3Ab", "c"));
    let names = ["a:b", "a%b", "a%3A:b", "edge:cursor:x", "ünïcøde", "a/b"];
    for name in names {
        for other in names {
            if other == name {
                continue;
            }
            assert_ne!(Key::feed(other), Key::feed(name));
            assert_ne!(Key::cursor(other, "c"), Key::cursor(name, "c"));
            assert_ne!(Key::cursor("f", other), Key::cursor("f", name));
        }
    }
}

#[test]
fn batch_behaviour() {
    assert_eq!(Batch::new(events(3), 100).events().len(), 3);
    assert!(Batch::new(vec![], 100).is_empty());
    let types: Vec<_> = Batch::new(events(2), 100)
        .events()
        .iter()
        .map(|e| e.r#type.clone())
        .collect();
    assert_eq!(types, ["event-0", "event-1"]);
    assert_eq!(Batch::new(events(3), 100).last_id(), Some("1-2"));
    assert_eq!(
        Batch::new(events(2), 2).cache_control(false),
        "private, max-age=31536000"
    );
    assert_eq!(
        Batch::new(events(2), 2).cache_control(true),
        "public, max-age=31536000"
    );
    assert_eq!(Batch::new(events(1), 2).cache_control(false), "no-store");
    assert_eq!(Batch::new(vec![], 2).cache_control(false), "no-store");
    assert_eq!(Batch::new(vec![], 0).cache_control(false), "no-store");
    let payload = Batch::new(events(2), 100).to_array();
    assert_eq!(payload[0]["id"], json!("1-0"));
    assert_eq!(payload[0]["specversion"], json!("1.0"));
    assert!(Batch::new(vec![], 100).to_array().is_empty());

    let mut full = CloudEvent::create(
        "io.appwrite.edge.invalidate-rule",
        "urn:appwrite:cloud:fra",
        "1-0",
    );
    full.subject = Some("example.com".into());
    full.time = Some("2026-07-31T09:15:02.123Z".into());
    full.datacontenttype = Some("application/json".into());
    full.data = json!({"tags": {"domain": "example.com"}});
    full.dataschema = Some("https://example.com/schema.json".into());
    full.extensions.insert(
        "traceparent".into(),
        utopia_cloudevents::ExtensionValue::String("00-abc-def-01".into()),
    );
    let encoded = Batch::new(vec![full], 100).to_array();
    assert_eq!(encoded[0]["id"], json!("1-0"));
    assert_eq!(encoded[0]["traceparent"], json!("00-abc-def-01"));
    assert_eq!(encoded[0]["subject"], json!("example.com"));

    let mut sparse = CloudEvent::create("a", "urn:test", "1-0");
    sparse.datacontenttype = None;
    let keys: Vec<_> = Batch::new(vec![sparse], 100).to_array()[0]
        .keys()
        .cloned()
        .collect();
    assert_eq!(keys, ["specversion", "type", "source", "id"]);
}

#[test]
fn producer_and_memory_roundtrip() {
    let store = MemoryStore::new("edge").unwrap();
    let producer = Producer::new(store.clone(), "urn:appwrite:cloud:fra").unwrap();
    let id = producer.produce("test", json!([]), "").unwrap();
    assert!(Id::is_valid(&id));
    let events = store.read(None, 10).unwrap();
    assert_eq!(events[0].r#type, "test");
    assert_eq!(events[0].source, "urn:appwrite:cloud:fra");
    assert_eq!(events[0].specversion, "1.0");
}

#[test]
fn producer_none_and_empty_source() {
    assert!(Producer::new(NoneStore::new("edge").unwrap(), "").is_err());
    let producer =
        Producer::new(NoneStore::new("edge").unwrap(), "urn:appwrite:cloud:fra").unwrap();
    assert!(producer
        .produce("test", json!([]), "")
        .unwrap_err()
        .is_unsupported());
}

#[test]
fn consumer_memory() {
    let store = MemoryStore::new("edge").unwrap();
    let producer = Producer::new(store.clone(), "urn:test").unwrap();
    let cursor = Arc::new(MemoryCursor::new());
    producer.produce("a", json!([]), "").unwrap();
    let consumer = Consumer::new(Arc::new(store.clone()), cursor.clone(), "invalidator").unwrap();
    assert_eq!(consumer.consume_any(|_| {}).unwrap(), 1);
    assert_eq!(consumer.consume_any(|_| {}).unwrap(), 0);

    let two = Consumer::new(Arc::new(store.clone()), cursor.clone(), "invalidator").unwrap();
    producer.produce("b", json!([]), "").unwrap();
    assert_eq!(two.consume_any(|_| {}).unwrap(), 1);
}

#[test]
fn consumer_clamps_and_names() {
    let store = MemoryStore::new("edge").unwrap();
    let cursor = Arc::new(MemoryCursor::new());
    assert!(Consumer::with_options(
        Arc::new(store.clone()),
        cursor.clone(),
        "invalidator",
        "other",
        100,
        0,
        Consumer::START_OLDEST,
    )
    .is_err());
    Consumer::with_options(
        Arc::new(store),
        cursor,
        "invalidator",
        "edge",
        5_000,
        120_000,
        Consumer::START_OLDEST,
    )
    .unwrap();
}

#[test]
fn cursor_none_and_cache_transport() {
    let cursor = NoneCursor::new();
    cursor.save("edge", "invalidator", "1-0").unwrap();
    assert!(cursor.load("edge", "invalidator").unwrap().is_none());
    cursor.reset("edge", "invalidator").unwrap();
    assert!(cursor.load("", "invalidator").is_err());
    assert!(cursor.load("edge", "").is_err());

    struct Broken;
    impl CacheAdapter for Broken {
        fn load(&self, _k: &str, _t: i64, _h: &str) -> Result<LoadResult, CacheError> {
            Ok(LoadResult::Miss)
        }
        fn save(&self, _k: &str, _d: &CacheValue, _h: &str) -> Result<SaveResult, CacheError> {
            Ok(SaveResult::Failed)
        }
        fn touch(&self, _k: &str, _h: &str) -> Result<bool, CacheError> {
            Ok(false)
        }
        fn list(&self, _k: &str) -> Result<Vec<String>, CacheError> {
            Ok(vec![])
        }
        fn purge(&self, _k: &str, _h: &str) -> Result<bool, CacheError> {
            Ok(false)
        }
        fn flush(&self) -> Result<bool, CacheError> {
            Ok(false)
        }
        fn ping(&self) -> bool {
            true
        }
        fn get_size(&self) -> Result<i64, CacheError> {
            Ok(0)
        }
        fn get_name(&self, _k: Option<&str>) -> String {
            "broken".into()
        }
    }
    let cursor = CacheCursor::new(UtopiaCache::new(Broken));
    assert!(cursor
        .save("edge", "invalidator", "1-0")
        .unwrap_err()
        .is_transport());
}

#[test]
fn cache_store_roundtrip() {
    let store = CacheStore::new(UtopiaCache::new(MemoryCache::new()), "edge").unwrap();
    let producer = Producer::new(store, "urn:test").unwrap();
    let id = producer.produce("a", json!([]), "").unwrap();
    assert!(Id::is_valid(&id));
}

#[test]
fn server_clamps() {
    struct Rec {
        name: String,
        timeout: parking_lot::Mutex<i64>,
        limit: parking_lot::Mutex<i64>,
    }
    impl Readable for Rec {
        fn get_name(&self) -> &str {
            self.name.as_str()
        }
        fn read(&self, _l: Option<&str>, limit: i64) -> Result<Vec<CloudEvent>, FeedError> {
            *self.limit.lock() = limit;
            Ok(vec![])
        }
        fn poll(
            &self,
            _l: Option<&str>,
            limit: i64,
            timeout: i64,
        ) -> Result<Vec<CloudEvent>, FeedError> {
            *self.limit.lock() = limit;
            *self.timeout.lock() = timeout;
            Ok(vec![])
        }
        fn tip(&self) -> Result<Option<String>, FeedError> {
            Ok(None)
        }
    }
    let store = Rec {
        name: "edge".into(),
        timeout: parking_lot::Mutex::new(-1),
        limit: parking_lot::Mutex::new(-1),
    };
    let server = Server::new(store);
    let mut q = Map::new();
    q.insert("timeout".into(), json!("2500"));
    server.serve(&q).unwrap();

    assert!(Server::new(NoneStore::new("edge").unwrap())
        .read(None, 10)
        .is_err());
}

#[test]
fn server_timeout_limit_data() {
    use parking_lot::Mutex;
    struct Rec {
        timeout: Arc<Mutex<i64>>,
        limit: Arc<Mutex<i64>>,
        name: String,
    }
    impl Readable for Rec {
        fn get_name(&self) -> &str {
            &self.name
        }
        fn read(&self, _: Option<&str>, limit: i64) -> Result<Vec<CloudEvent>, FeedError> {
            *self.limit.lock() = limit;
            Ok(vec![])
        }
        fn poll(
            &self,
            _: Option<&str>,
            limit: i64,
            timeout: i64,
        ) -> Result<Vec<CloudEvent>, FeedError> {
            *self.limit.lock() = limit;
            *self.timeout.lock() = timeout;
            Ok(vec![])
        }
        fn tip(&self) -> Result<Option<String>, FeedError> {
            Ok(None)
        }
    }
    let rec = Rec {
        timeout: Arc::new(Mutex::new(0)),
        limit: Arc::new(Mutex::new(0)),
        name: String::from("edge"),
    };
    let t = rec.timeout.clone();
    let l = rec.limit.clone();
    // Rec not clone - use Arc
    let rec = Arc::new(rec);
    struct Wrap(Arc<Rec>);
    impl Readable for Wrap {
        fn get_name(&self) -> &str {
            self.0.get_name()
        }
        fn read(&self, a: Option<&str>, b: i64) -> Result<Vec<CloudEvent>, FeedError> {
            self.0.read(a, b)
        }
        fn poll(&self, a: Option<&str>, b: i64, c: i64) -> Result<Vec<CloudEvent>, FeedError> {
            self.0.poll(a, b, c)
        }
        fn tip(&self) -> Result<Option<String>, FeedError> {
            self.0.tip()
        }
    }
    let server = Server::new(Wrap(Arc::clone(&rec)));
    let Value::Object(q) = json!({"timeout": "120000", "limit": "5000"}) else {
        panic!();
    };
    server.serve(&q).unwrap();
    assert_eq!(*t.lock(), MAX_TIMEOUT);
    assert_eq!(*l.lock(), MAX_BATCH);
}

#[test]
fn remote_http() {
    let ev = {
        let mut e = CloudEvent::create("io.appwrite.edge.invalidate-rule", "urn:test", "1-0");
        e.data = json!({"tags": {"domain": "example.com"}});
        e
    };
    let batch = Batch::new(
        vec![
            ev,
            CloudEvent::create("io.appwrite.edge.invalidate", "urn:test", "1-1"),
        ],
        2,
    );
    let body = Value::Array(batch.to_array().into_iter().map(Value::Object).collect());
    let transport = RecordingTransport::of(vec![Ok(RecordingTransport::json(body, 200))]);
    let remote = Remote::new(transport, "edge").unwrap();
    let events = remote.read(None, 100).unwrap();
    assert_eq!(events.len(), 2);
    assert_eq!(events[0].id, "1-0");

    let transport =
        RecordingTransport::of(vec![]).with_base_uri("https://cloud.example.com/v1/feeds");
    let t = transport.clone();
    Remote::new(transport, "edge")
        .unwrap()
        .read(None, 100)
        .unwrap();
    assert!(t
        .last_uri()
        .unwrap()
        .starts_with("https://cloud.example.com/v1/feeds/edge"));

    let transport =
        RecordingTransport::of(vec![]).with_base_uri("https://cloud.example.com/v1/feeds/");
    let t = transport.clone();
    Remote::new(transport, "a b/c")
        .unwrap()
        .read(None, 100)
        .unwrap();
    assert!(t
        .last_uri()
        .unwrap()
        .starts_with("https://cloud.example.com/v1/feeds/a%20b%2Fc"));

    assert!(Remote::new(RecordingTransport::of(vec![]), "").is_err());
    assert!(Remote::new(RecordingTransport::of(vec![]), "edge")
        .unwrap()
        .tip()
        .unwrap_err()
        .is_unsupported());

    let transport = RecordingTransport::of(vec![Ok(RecordingTransport::raw("{}", 200))]);
    assert!(Remote::new(transport, "edge")
        .unwrap()
        .read(None, 10)
        .unwrap_err()
        .is_invalid());
}

#[test]
fn extensions_filter() {
    let mut map = Map::new();
    map.insert("type".into(), json!("x"));
    map.insert("traceparent".into(), json!("00-abc"));
    map.insert("BadName".into(), json!("no"));
    map.insert("ok".into(), json!(true));
    let filtered = Extensions::filter(&map);
    assert!(filtered.contains_key("traceparent"));
    assert!(filtered.contains_key("ok"));
    assert!(!filtered.contains_key("type"));
    assert!(!filtered.contains_key("BadName"));
}

#[test]
fn failing_cursor_stops_load() {
    struct FailLoad;
    impl Cursor for FailLoad {
        fn load(&self, _f: &str, _c: &str) -> Result<Option<String>, FeedError> {
            Err(FeedError::transport("Cursor store is unavailable"))
        }
        fn save(&self, _f: &str, _c: &str, _e: &str) -> Result<(), FeedError> {
            Ok(())
        }
        fn reset(&self, _f: &str, _c: &str) -> Result<(), FeedError> {
            Ok(())
        }
    }
    let store = MemoryStore::new("edge").unwrap();
    Producer::new(store.clone(), "urn:test")
        .unwrap()
        .produce("a", json!([]), "")
        .unwrap();
    let consumer = Consumer::new(Arc::new(store), Arc::new(FailLoad), "invalidator").unwrap();
    let err = consumer.consume_any(|_| {}).unwrap_err();
    assert_eq!(err.to_string(), "Cursor store is unavailable");
}

#[cfg(feature = "redis")]
#[test]
fn redis_e2e_append_and_read() {
    use utopia_feed::store::Redis;
    use utopia_feed::{Appendable, Readable};
    let url = std::env::var("REDIS_URL")
        .ok()
        .filter(|u| !u.is_empty())
        .unwrap_or_else(|| "redis://127.0.0.1:6379/".into());
    let client = redis::Client::open(url).expect("redis url");
    let conn = client
        .get_connection()
        .expect("Redis container (docker compose -f docker-compose.test.yml up -d redis)");
    let store = Redis::new(conn, format!("e2e-{}", std::process::id())).unwrap();
    let event = CloudEvent::create("utopia.e2e", "urn:test", "1-0");
    store.append(event).unwrap();
    let items = store.read(None, 10).unwrap();
    assert!(!items.is_empty());
}
