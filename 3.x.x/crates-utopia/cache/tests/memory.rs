mod common;

use utopia_cache::adapter::Memory;
use utopia_cache::{Adapter, Cache};

#[test]
fn memory_base_suite() {
    let mut cache = Cache::new(Memory::new());
    common::assert_base_suite(&mut cache);
    common::assert_touch(&cache);
    common::assert_case_insensitivity(&cache);
    common::assert_case_sensitivity(&mut cache);
    assert!(cache.ping());
    common::assert_flush(&cache);
}

#[test]
fn memory_get_size() {
    let cache = Cache::new(Memory::new());
    cache.save("test:file33", "file33", "").unwrap();
    cache.save("test:file34", "file34", "").unwrap();
    cache.save("test:file35", "file35", "").unwrap();
    cache.save("test:file36", "file36", "").unwrap();
    assert_eq!(cache.get_size().unwrap(), 4);
}

#[test]
fn memory_rejects_empty_keys_and_data() {
    let cache = Cache::new(Memory::new());
    assert!(cache.save("", "x", "").unwrap().is_failed());
    assert!(cache.save("0", "x", "").unwrap().is_failed());
    assert!(cache.save("k", "", "").unwrap().is_failed());
    assert!(cache.save("k", "0", "").unwrap().is_failed());
    assert!(cache
        .save("k", utopia_cache::CacheValue::from(Vec::<&str>::new()), "")
        .unwrap()
        .is_failed());
    assert!(cache.load("", 60, "").unwrap().is_miss());
    assert!(cache.load("0", 60, "").unwrap().is_miss());
    assert!(!cache.purge("", "").unwrap());
    assert!(!cache.purge("0", "").unwrap());
}

#[test]
fn memory_name() {
    assert_eq!(Memory::new().get_name(None), "memory");
}

#[test]
fn memory_empty_object_fidelity() {
    let cache = Cache::new(Memory::new());
    common::assert_empty_object_fidelity(&cache);
}
