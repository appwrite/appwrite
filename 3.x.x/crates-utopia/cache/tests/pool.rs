mod common;

use tempfile::TempDir;
use utopia_cache::adapter::{Filesystem, MemoryPool, Pool};
use utopia_cache::Cache;

#[test]
fn pool_base_suite() {
    let dir = TempDir::new().unwrap();
    let pool = MemoryPool::single(Filesystem::new(dir.path().to_str().unwrap()));
    let mut cache = Cache::new(Pool::new(pool).unwrap());
    common::assert_base_suite(&mut cache);
    common::assert_touch(&cache);
    common::assert_case_insensitivity(&cache);
    assert!(cache.ping());
}

#[test]
fn pool_get_size() {
    let dir = TempDir::new().unwrap();
    let pool = MemoryPool::single(Filesystem::new(dir.path().to_str().unwrap()));
    let cache = Cache::new(Pool::new(pool).unwrap());
    cache.save("test", "test", "").unwrap();
    assert_eq!(cache.get_size().unwrap(), 4);
}

#[test]
fn pool_lease_fallback_for_non_leasable_adapter() {
    let dir = TempDir::new().unwrap();
    let pool = MemoryPool::single(Filesystem::new(dir.path().to_str().unwrap()));
    let cache = Cache::new(Pool::new(pool).unwrap());
    assert_eq!(cache.get_generation("lease:fallback").unwrap(), "0");
    assert!(cache
        .save_with_lease("lease:fallback", "value", "lease:fallback", "0")
        .unwrap()
        .is_saved());
    match cache.load("lease:fallback", 60, "lease:fallback").unwrap() {
        utopia_cache::LoadResult::Hit(v) => assert_eq!(v.as_str(), Some("value")),
        utopia_cache::LoadResult::Miss => panic!("expected hit"),
    }
}
