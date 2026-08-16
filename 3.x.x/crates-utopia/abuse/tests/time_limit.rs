use utopia_abuse::adapters::time_limit::{self, Memory};
use utopia_abuse::{Abuse, AbuseError, Adapter};

fn now() -> i64 {
    std::time::SystemTime::now()
        .duration_since(std::time::UNIX_EPOCH)
        .map(|duration| duration.as_secs() as i64)
        .unwrap_or(0)
}

fn make_adapter(key: &str, limit: i64, seconds: i64) -> Memory {
    Memory::new(key, limit, seconds)
}

#[test]
fn none_never_limits_requests() {
    let adapter = time_limit::None::new("none-key", 1, 60);
    let mut abuse = Abuse::new(adapter);
    assert!(!abuse.check().unwrap());
    assert!(!abuse.check().unwrap());
    assert!(!abuse.check().unwrap());
}

#[test]
fn none_returns_no_logs_and_cleanup_succeeds() {
    let mut adapter = time_limit::None::new("none-key", 1, 60);
    assert!(adapter.get_logs(None, Some(25)).unwrap().is_empty());
    assert!(adapter.cleanup(now()).unwrap());
}

#[test]
fn none_reset_is_noop() {
    let adapter = time_limit::None::new("none-key", 1, 60);
    let mut abuse = Abuse::new(adapter);
    abuse.reset().unwrap();
    assert!(!abuse.check().unwrap());
}

#[test]
fn static_key() {
    let mut abuse = Abuse::new(make_adapter("static-key", 2, 1));
    assert!(!abuse.check().unwrap());
    assert!(!abuse.check().unwrap());
    assert!(abuse.check().unwrap());
}

#[test]
fn dynamic_key() {
    let mut adapter = make_adapter("dynamic-key-{{ip}}", 2, 1);
    adapter.set_param("{{ip}}", "0.0.0.10");
    assert_eq!(adapter.parse_key(), "dynamic-key-0.0.0.10");
    let mut abuse = Abuse::new(adapter);
    assert!(!abuse.check().unwrap());
    assert!(!abuse.check().unwrap());
    assert!(abuse.check().unwrap());
}

#[test]
fn dynamic_key_with_2_params() {
    let mut adapter = make_adapter("two-params-{{ip}}-{{email}}", 2, 1);
    adapter.set_param("{{ip}}", "0.0.0.10");
    adapter.set_param("{{email}}", "test@test.com");
    let mut abuse = Abuse::new(adapter);
    assert!(!abuse.check().unwrap());
    assert!(!abuse.check().unwrap());
    assert!(abuse.check().unwrap());
}

#[test]
fn dynamic_key_fast_requests() {
    let mut adapter = make_adapter("fast-requests-{{ip}}", 10, 1);
    adapter.set_param("{{ip}}", "0.0.0.10");
    let mut abuse = Abuse::new(adapter);
    for _ in 0..10 {
        assert!(!abuse.check().unwrap());
    }
    assert!(abuse.check().unwrap());
}

#[test]
fn limit_reset_after_window() {
    let store = time_limit::MemoryStore::new();
    let mut first = Memory::with_store("limit-reset-{{ip}}", 10, 2, store.clone());
    first.set_param("{{ip}}", "127.0.0.1");
    let mut abuse = Abuse::new(first);
    for _ in 0..10 {
        assert!(!abuse.check().unwrap());
    }
    assert!(abuse.check().unwrap());

    std::thread::sleep(std::time::Duration::from_secs(2));

    let mut second = Memory::with_store("limit-reset-{{ip}}", 10, 1, store);
    second.set_param("{{ip}}", "127.0.0.1");
    let mut abuse = Abuse::new(second);
    assert!(!abuse.check().unwrap());
}

#[test]
fn time_format() {
    let adapter = make_adapter("", 1, 1);
    assert_eq!(adapter.time(), now());
}

#[test]
fn reset() {
    let mut adapter = make_adapter("reset-test-{{ip}}", 5, 600);
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

    let mut adapter = make_adapter("reset-test-{{ip}}", 2, 600);
    adapter.set_param("{{ip}}", "192.168.1.2");
    let mut abuse = Abuse::new(adapter);
    for _ in 0..15 {
        assert!(!abuse.check().unwrap());
        abuse.reset().unwrap();
    }
}

#[test]
fn remaining_and_unlimited() {
    let mut adapter = make_adapter("remaining-{{ip}}", 3, 60);
    adapter.set_param("{{ip}}", "0.0.0.12");
    assert_eq!(adapter.remaining(), 2);
    let mut abuse = Abuse::new(adapter);
    assert!(!abuse.check().unwrap());
    assert_eq!(abuse.adapter_mut().remaining(), 1);
    assert!(!abuse.check().unwrap());
    assert_eq!(abuse.adapter_mut().remaining(), 0);

    let mut unlimited = Abuse::new(make_adapter("unlimited", 0, 60));
    for _ in 0..20 {
        assert!(!unlimited.check().unwrap());
    }
}

#[test]
fn param_substitution_is_in_place() {
    let mut adapter = make_adapter("ip-{{ip}}-{{ip}}", 1, 60);
    adapter.set_param("{{ip}}", "10.0.0.1");
    assert_eq!(adapter.parse_key(), "ip-10.0.0.1-10.0.0.1");
}

#[test]
fn none_limit_and_time() {
    let adapter = time_limit::None::new("k", 4, 1);
    assert_eq!(adapter.limit(), 4);
    assert_eq!(adapter.remaining(), 3);
}

#[test]
fn method_not_supported_message() {
    assert_eq!(
        AbuseError::MethodNotSupported.to_string(),
        "Method not supported"
    );
}
