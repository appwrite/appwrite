use utopia_cache::adapter::{Memory, Sharding};
use utopia_cache::{Adapter, Cache, CacheError};

#[test]
fn sharding_empty_adapters() {
    let err = Sharding::new(vec![]).unwrap_err();
    assert!(matches!(err, CacheError::NoAdapters));
}

#[test]
fn sharding_crc32_matches_php_unsigned() {
    // PHP crc32 is IEEE CRC-32. Spot-check several keys against known values.
    let hello = crc32fast::hash(b"hello");
    assert_eq!(hello, 907_060_870);
    let planet = crc32fast::hash(b"planet");
    let color = crc32fast::hash(b"color");
    let doc = crc32fast::hash(b"doc:1");
    for count in [1usize, 2, 3, 7] {
        assert_eq!(
            Sharding::shard_index("hello", count),
            (hello as usize) % count
        );
        assert_eq!(
            Sharding::shard_index("planet", count),
            (planet as usize) % count
        );
        assert_eq!(
            Sharding::shard_index("color", count),
            (color as usize) % count
        );
        assert_eq!(
            Sharding::shard_index("doc:1", count),
            (doc as usize) % count
        );
    }
}

#[test]
fn sharding_routes_keys_to_different_adapters() {
    let a = Memory::new();
    let b = Memory::new();
    let shard = Sharding::new(vec![Box::new(a), Box::new(b)]).unwrap();
    let cache = Cache::new(shard);
    cache.save("alpha", "1", "").unwrap();
    cache.save("beta", "2", "").unwrap();
    cache.save("gamma", "3", "").unwrap();
    assert!(cache.load("alpha", 60, "").unwrap().is_hit());
    assert!(cache.load("beta", 60, "").unwrap().is_hit());
    assert!(cache.load("gamma", 60, "").unwrap().is_hit());
    assert_eq!(cache.get_size().unwrap(), 3);
}

#[test]
fn sharding_name_without_key_uses_first_adapter() {
    let shard = Sharding::new(vec![Box::new(Memory::new()), Box::new(Memory::new())]).unwrap();
    assert_eq!(shard.get_name(None), "memory");
}

#[test]
fn json_contains_empty_object() {
    use utopia_cache::adapter::Json;
    assert!(Json::contains_empty_object("{}"));
    assert!(Json::contains_empty_object("{ }"));
    assert!(Json::contains_empty_object(r#"{"a":{}}"#));
    assert!(!Json::contains_empty_object("[]"));
    assert!(!Json::contains_empty_object(r#"{"a":1}"#));
}

#[test]
fn json_decode_preserves_empty_object_vs_array() {
    use utopia_cache::adapter::Json;
    let v = Json::decode(r#"{"empty":{},"emptyArray":[]}"#).unwrap();
    assert_eq!(
        serde_json::to_string(&v).unwrap(),
        r#"{"empty":{},"emptyArray":[]}"#
    );
}
