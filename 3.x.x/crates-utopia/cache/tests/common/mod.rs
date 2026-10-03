#![allow(dead_code)]

use utopia_cache::{Cache, CacheValue, LoadResult, SaveResult};

pub const KEY: &str = "test-key-for-cache";
pub const DATA: &str = "test data string";
pub const TTL: i64 = 60 * 60 * 24 * 30 * 3;

pub fn saved_str(result: SaveResult) -> String {
    match result {
        SaveResult::Saved(CacheValue::String(s)) => s,
        other => panic!("expected saved string, got {other:?}"),
    }
}

#[allow(dead_code)]
pub fn saved_array(result: SaveResult) -> CacheValue {
    match result {
        SaveResult::Saved(v) => v,
        SaveResult::Failed => panic!("expected saved value, got Failed"),
    }
}

pub fn assert_base_suite(cache: &mut Cache) {
    let data_array: CacheValue = vec!["test", "data", "string"].into();
    let saved = cache.save(KEY, data_array.clone(), KEY).unwrap();
    assert_eq!(saved, SaveResult::Saved(data_array));

    let saved = cache.save(KEY, DATA, KEY).unwrap();
    assert_eq!(saved_str(saved), DATA);

    match cache.load(KEY, TTL, KEY).unwrap() {
        LoadResult::Hit(CacheValue::String(s)) => assert_eq!(s, DATA),
        other => panic!("expected hit, got {other:?}"),
    }

    assert!(matches!(
        cache.load(KEY, TTL, KEY).unwrap(),
        LoadResult::Hit(_)
    ));
    assert!(cache.purge(KEY, "").unwrap());
    assert!(cache.load(KEY, TTL, KEY).unwrap().is_miss());
}

pub fn assert_touch(cache: &Cache) {
    assert_eq!(
        saved_str(cache.save("touch-key", "touch data", "touch-key").unwrap()),
        "touch data"
    );
    std::thread::sleep(std::time::Duration::from_secs(3));
    assert!(cache.load("touch-key", 2, "touch-key").unwrap().is_miss());
    assert!(cache.touch("touch-key", "touch-key").unwrap());
    match cache.load("touch-key", 2, "touch-key").unwrap() {
        LoadResult::Hit(CacheValue::String(s)) => assert_eq!(s, "touch data"),
        other => panic!("expected hit after touch, got {other:?}"),
    }
    assert!(!cache
        .touch("missing-touch-key", "missing-touch-key")
        .unwrap());
    cache.purge("touch-key", "").unwrap();
}

pub fn assert_case_insensitivity(cache: &Cache) {
    assert_eq!(
        saved_str(cache.save("planet", "Earth", "planet").unwrap()),
        "Earth"
    );
    for key in ["planet", "PLANET", "PlAnEt"] {
        match cache.load(key, TTL, key).unwrap() {
            LoadResult::Hit(CacheValue::String(s)) => assert_eq!(s, "Earth"),
            other => panic!("expected Earth for {key}, got {other:?}"),
        }
    }
    assert!(cache.purge("PLaNEt", "").unwrap());
    assert!(cache.load("planet", TTL, "planet").unwrap().is_miss());
    assert!(cache.load("PLANET", TTL, "PLANET").unwrap().is_miss());
}

#[allow(dead_code)]
pub fn assert_case_sensitivity(cache: &mut Cache) {
    cache.set_case_sensitivity(true);
    assert_eq!(
        saved_str(cache.save("color", "pink", "color").unwrap()),
        "pink"
    );
    match cache.load("color", TTL, "color").unwrap() {
        LoadResult::Hit(CacheValue::String(s)) => assert_eq!(s, "pink"),
        other => panic!("{other:?}"),
    }
    assert!(cache.load("COLOR", TTL, "COLOR").unwrap().is_miss());
    assert!(cache.purge("color", "").unwrap());
    cache.set_case_sensitivity(false);
}

#[allow(dead_code)]
pub fn assert_flush(cache: &Cache) {
    cache.save("x", "x", "x").unwrap();
    cache.save("y", "y", "y").unwrap();
    match cache.load("x", 100, "x").unwrap() {
        LoadResult::Hit(CacheValue::String(s)) => assert_eq!(s, "x"),
        other => panic!("{other:?}"),
    }
    match cache.load("y", 100, "y").unwrap() {
        LoadResult::Hit(CacheValue::String(s)) => assert_eq!(s, "y"),
        other => panic!("{other:?}"),
    }
    assert!(cache.flush().unwrap());
    assert!(cache.load("x", 100, "x").unwrap().is_miss());
    assert!(cache.load("y", 100, "y").unwrap().is_miss());
}

#[allow(dead_code)]
pub fn assert_empty_object_fidelity(cache: &Cache) {
    let data = serde_json::json!({
        "empty": {},
        "nested": { "empty": {} },
        "list": [{}, {"x": 1}],
        "emptyArray": []
    });
    let key = "empty-object-fidelity";
    let saved = cache
        .save(key, CacheValue::Array(data.clone()), key)
        .unwrap();
    assert_eq!(saved, SaveResult::Saved(CacheValue::Array(data.clone())));
    let loaded = cache.load(key, 60, key).unwrap().into_value().unwrap();
    assert_eq!(
        serde_json::to_string(&loaded.into_json()).unwrap(),
        r#"{"empty":{},"nested":{"empty":{}},"list":[{},{"x":1}],"emptyArray":[]}"#
    );
    assert!(cache.touch(key, key).unwrap());
    let loaded = cache.load(key, 60, key).unwrap().into_value().unwrap();
    assert_eq!(
        serde_json::to_string(&loaded.into_json()).unwrap(),
        r#"{"empty":{},"nested":{"empty":{}},"list":[{},{"x":1}],"emptyArray":[]}"#
    );
    cache.purge(key, "").unwrap();
}
