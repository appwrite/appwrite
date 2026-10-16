use utopia_cache::adapter::None as NoneCache;
use utopia_cache::{Adapter, Cache, LoadResult, SaveResult};

#[test]
fn none_get_size() {
    let cache = Cache::new(NoneCache::new());
    assert_eq!(cache.get_size().unwrap(), 0);
}

#[test]
fn none_save_always_fails() {
    let cache = Cache::new(NoneCache::new());
    assert_eq!(
        cache
            .save("test-key-for-cache", "test data string", "")
            .unwrap(),
        SaveResult::Failed
    );
}

#[test]
fn none_load_always_misses() {
    let cache = Cache::new(NoneCache::new());
    cache.purge("test-key-for-cache", "").unwrap();
    assert_eq!(
        cache
            .load("test-key-for-cache", 60 * 60 * 24 * 30 * 3, "")
            .unwrap(),
        LoadResult::Miss
    );
}

#[test]
fn none_purge_true_flush_true_touch_false() {
    let cache = Cache::new(NoneCache::new());
    assert!(cache.purge("test-key-for-cache", "").unwrap());
    assert!(cache.flush().unwrap());
    assert!(!cache.touch("test-key-for-cache", "").unwrap());
    assert!(cache.ping());
}

#[test]
fn none_name() {
    assert_eq!(NoneCache::new().get_name(None), "none");
}
