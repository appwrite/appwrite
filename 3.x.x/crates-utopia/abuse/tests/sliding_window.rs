use utopia_abuse::adapters::sliding_window::{self, Memory};
use utopia_abuse::{Abuse, AbuseError, Adapter};

fn adapter(key: &str, limit: i64, window_size: i64, ttl: i64) -> Memory {
    Memory::new(key, limit, window_size, ttl).expect("adapter")
}

#[test]
fn none_never_limits() {
    let adapter = sliding_window::None::new("none", 1, 1, 2).unwrap();
    let mut abuse = Abuse::new(adapter);
    assert!(!abuse.check().unwrap());
}

#[test]
fn none_rejects_zero_window() {
    let err = sliding_window::None::new("none", 1, 0, 2).unwrap_err();
    assert!(matches!(err, AbuseError::InvalidWindowSize));
}

#[test]
fn static_key() {
    let mut abuse = Abuse::new(adapter("sw-static-key", 2, 1, 2));
    assert!(!abuse.check().unwrap());
    assert!(!abuse.check().unwrap());
    assert!(abuse.check().unwrap());
}

#[test]
fn dynamic_key() {
    let mut adapter = adapter("sw-dynamic-key-{{ip}}", 2, 1, 2);
    adapter.set_param("{{ip}}", "0.0.0.10");
    let mut abuse = Abuse::new(adapter);
    assert!(!abuse.check().unwrap());
    assert!(!abuse.check().unwrap());
    assert!(abuse.check().unwrap());
}

#[test]
fn dynamic_key_with_2_params() {
    let mut adapter = adapter("sw-two-params-{{ip}}-{{email}}", 2, 1, 2);
    adapter.set_param("{{ip}}", "0.0.0.10");
    adapter.set_param("{{email}}", "test@test.com");
    let mut abuse = Abuse::new(adapter);
    assert!(!abuse.check().unwrap());
    assert!(!abuse.check().unwrap());
    assert!(abuse.check().unwrap());
}

#[test]
fn fast_requests() {
    let mut adapter = adapter("sw-fast-requests-{{ip}}", 10, 1, 2);
    adapter.set_param("{{ip}}", "0.0.0.11");
    let mut abuse = Abuse::new(adapter);
    for _ in 0..10 {
        assert!(!abuse.check().unwrap());
    }
    assert!(abuse.check().unwrap());
}

#[test]
fn remaining() {
    let mut adapter = adapter("sw-remaining-{{ip}}", 3, 60, 120);
    adapter.set_param("{{ip}}", "0.0.0.12");
    let mut abuse = Abuse::new(adapter);
    assert_eq!(abuse.adapter_mut().remaining(), 2);
    assert!(!abuse.check().unwrap());
    assert_eq!(abuse.adapter_mut().remaining(), 1);
    assert!(!abuse.check().unwrap());
    assert_eq!(abuse.adapter_mut().remaining(), 0);
}

#[test]
fn window_expiry() {
    let store = sliding_window::MemoryStore::new();
    let mut first = Memory::with_store("sw-window-expiry-{{ip}}", 3, 1, 2, store.clone()).unwrap();
    first.set_param("{{ip}}", "127.0.0.1");
    let mut abuse = Abuse::new(first);
    for _ in 0..3 {
        assert!(!abuse.check().unwrap());
    }
    assert!(abuse.check().unwrap());

    std::thread::sleep(std::time::Duration::from_secs(3));

    let mut second = Memory::with_store("sw-window-expiry-{{ip}}", 3, 1, 2, store).unwrap();
    second.set_param("{{ip}}", "127.0.0.1");
    let mut abuse = Abuse::new(second);
    assert!(!abuse.check().unwrap());
}

#[test]
fn time_format() {
    let window_size = 1;
    let now = std::time::SystemTime::now()
        .duration_since(std::time::UNIX_EPOCH)
        .map(|duration| duration.as_secs() as i64)
        .unwrap_or(0);
    let mut adapter = adapter("sw-time", 1, window_size, 2);
    assert_eq!(adapter.time(), now - now.rem_euclid(window_size));
}

#[test]
fn reset() {
    let mut adapter = adapter("sw-reset-test-{{ip}}", 5, 600, 1200);
    adapter.set_param("{{ip}}", "192.168.1.1");
    let mut abuse = Abuse::new(adapter);
    for _ in 0..5 {
        assert!(!abuse.check().unwrap());
    }
    assert!(abuse.check().unwrap());
    abuse.reset().unwrap();
    for _ in 0..5 {
        assert!(!abuse.check().unwrap());
    }
    assert!(abuse.check().unwrap());
}

#[test]
fn ttl_guard() {
    let err = Memory::new("sw-guard", 1, 10, 5).unwrap_err();
    assert!(matches!(err, AbuseError::InvalidTtl));
}

#[test]
fn unlimited() {
    let mut abuse = Abuse::new(adapter("sw-unlimited", 0, 1, 2));
    for _ in 0..20 {
        assert!(!abuse.check().unwrap());
    }
}
