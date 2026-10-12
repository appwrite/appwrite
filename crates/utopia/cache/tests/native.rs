//! Native entries ([`Native`], `Cache::load_native` and friends): typed
//! values through every kind of adapter, with the TTL, leases and purges the
//! PHP-format entries have.
//!
//! The Redis tests need a server: set `UTOPIA_CACHE_REDIS` (for example
//! `redis://localhost:9513/6`, the dev stack's Redis) to run them.

use std::sync::Arc;

use php_std::types::Value;
use serde::{Deserialize, Serialize};
use utopia_cache::{Adapter, Cache, DynAdapter, Memory, Native, Sharding};

#[derive(Debug, Clone, PartialEq, Serialize, Deserialize)]
struct Project {
    id: String,
    sequence: i64,
    scopes: Vec<String>,
    team: Option<String>,
}

fn project() -> Project {
    Project { id: "6650f2b1".into(), sequence: 42, scopes: vec!["users.read".into(), "users.write".into()], team: None }
}

#[tokio::test]
async fn memory_round_trips_typed_values() {
    let cache = Cache::new(Memory::new());
    assert!(cache.save_native("Projects/1", &project(), "", 0).await.unwrap());
    // Keys fold like every other entry.
    assert_eq!(cache.load_native::<Project>("projects/1", 60, "").await.unwrap(), Some(project()));
    // A TTL of 0 is "older than now".
    assert_eq!(cache.load_native::<Project>("projects/1", 0, "").await.unwrap(), None);
    // Another type's entry is a miss.
    assert_eq!(cache.load_native::<Vec<u8>>("projects/1", 60, "").await.unwrap(), None);
    assert!(cache.purge("projects/1", "").await.unwrap());
    assert_eq!(cache.load_native::<Project>("projects/1", 60, "").await.unwrap(), None);
}

#[tokio::test]
async fn php_values_are_misses() {
    let cache = Cache::new(Memory::new());
    cache.save("k", &Value::from(r#"{"id":"6650f2b1"}"#), "", 0).await.unwrap();
    assert_eq!(cache.load_native::<Project>("k", 60, "").await.unwrap(), None);
    // The next save replaces it.
    assert!(cache.save_native("k", &project(), "", 0).await.unwrap());
    assert_eq!(cache.load_native::<Project>("k", 60, "").await.unwrap(), Some(project()));
}

#[tokio::test]
async fn wrappers_and_dynamic_adapters_pass_bytes_on() {
    let sharded: Arc<dyn DynAdapter> = Arc::new(Sharding::new(vec![Memory::new(), Memory::new()]).unwrap());
    let cache = Cache::from_dyn(sharded.clone());
    for i in 0..8 {
        let key = format!("key:{i}");
        assert!(cache.save_native_with_lease(&key, &i, "", "0").await.unwrap());
        assert_eq!(cache.load_native::<i32>(&key, 60, "").await.unwrap(), Some(i));
        let stored = sharded.load_bytes(&key, "").await.unwrap().unwrap();
        assert!(Native::time(&stored).is_some());
    }
}

/// The dev stack's Redis, when `UTOPIA_CACHE_REDIS` names it.
#[cfg(feature = "redis")]
async fn redis() -> Option<utopia_cache::redis::aio::ConnectionManager> {
    let url = std::env::var("UTOPIA_CACHE_REDIS").ok()?;
    let client = utopia_cache::redis::Client::open(url).expect("redis url");
    Some(client.get_connection_manager().await.expect("redis connection"))
}

#[cfg(feature = "redis")]
fn unique(name: &str) -> String {
    let nanos = std::time::SystemTime::now().duration_since(std::time::UNIX_EPOCH).unwrap().as_nanos();
    format!("utopia-cache-test:{name}:{}:{nanos}", std::process::id())
}

#[cfg(feature = "redis")]
#[tokio::test]
async fn redis_keeps_native_entries_beside_php_fields() {
    let Some(connection) = redis().await else { return };
    // A JSON-envelope adapter, like Appwrite's: native bytes bypass its codec.
    let cache = Cache::new(utopia_cache::Redis::new(connection.clone()));
    let key = unique("fields");

    assert!(cache.save(&key, &Value::from("php"), "php", 60).await.unwrap());
    assert!(cache.save_native(&key, &project(), "rust:v1", 60).await.unwrap());
    assert_eq!(cache.load_native::<Project>(&key, 60, "rust:v1").await.unwrap(), Some(project()));
    // The PHP field is untouched and still reads as PHP wrote it; PHP's
    // codec reading the native field is a miss, not garbage.
    assert_eq!(cache.load(&key, 60, "php").await.unwrap(), Some(Value::from("php")));
    assert_eq!(cache.load(&key, 60, "rust:v1").await.unwrap(), None);
    // The key got its expiry.
    let ttl: i64 = utopia_cache::redis::cmd("TTL").arg(&key).query_async(&mut connection.clone()).await.unwrap();
    assert!(ttl > 0 && ttl <= 60, "{ttl}");

    // Reserved fields are refused.
    assert!(!cache.save_native(&key, &1, "__utopia_gen__", 0).await.unwrap());
    assert_eq!(cache.load_native::<i32>(&key, 60, "__utopia_gen__").await.unwrap(), None);

    // A purge of the key drops both.
    assert!(cache.purge(&key, "").await.unwrap());
    assert_eq!(cache.load_native::<Project>(&key, 60, "rust:v1").await.unwrap(), None);
    let _: () = utopia_cache::redis::cmd("DEL").arg(&key).query_async(&mut connection.clone()).await.unwrap();
}

#[cfg(feature = "redis")]
#[tokio::test]
async fn redis_leases_guard_native_saves() {
    let Some(connection) = redis().await else { return };
    let multiplexing = {
        let url = std::env::var("UTOPIA_CACHE_REDIS").unwrap();
        let (host, rest) = url.trim_start_matches("redis://").split_once(':').unwrap();
        let (port, db) = rest.split_once('/').unwrap_or((rest, "0"));
        let mut options = utopia_cache::MultiplexingOptions::new(host, port.parse().unwrap());
        options.db = db.parse().unwrap();
        utopia_cache::Multiplexing::connect(options).await.unwrap()
    };
    let caches: [Cache; 2] = [
        Cache::from_dyn(Arc::new(utopia_cache::Redis::new(connection.clone()))),
        Cache::from_dyn(Arc::new(multiplexing)),
    ];
    for cache in caches {
        let key = unique("lease");
        let generation = cache.generation(&key).await.unwrap();
        // A purge after the read: the stale save is refused.
        cache.purge(&key, "").await.unwrap();
        assert!(!cache.save_native_with_lease(&key, &project(), "rust", &generation).await.unwrap());
        assert_eq!(cache.load_native::<Project>(&key, 60, "rust").await.unwrap(), None);
        // With the current generation it lands.
        let generation = cache.generation(&key).await.unwrap();
        assert!(cache.save_native_with_lease(&key, &project(), "rust", &generation).await.unwrap());
        assert_eq!(cache.load_native::<Project>(&key, 60, "rust").await.unwrap(), Some(project()));
        // An entry an older format wrote in the field is a miss, then replaced.
        assert!(cache.save(&key, &Value::from(r#"{"id":"old"}"#), "rust", 0).await.unwrap());
        assert_eq!(cache.load_native::<Project>(&key, 60, "rust").await.unwrap(), None);
        assert!(cache.save_native(&key, &project(), "rust", 0).await.unwrap());
        assert_eq!(cache.load_native::<Project>(&key, 60, "rust").await.unwrap(), Some(project()));
        let _: () = utopia_cache::redis::cmd("DEL").arg(&key).query_async(&mut connection.clone()).await.unwrap();
    }
}
