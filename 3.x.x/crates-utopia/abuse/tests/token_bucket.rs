use utopia_abuse::adapters::token_bucket::{self, Memory};
use utopia_abuse::{Abuse, AbuseError, Adapter};

fn adapter(key: &str, tokens: i64, refill_rate: f64) -> Memory {
    Memory::new(key, tokens, refill_rate).expect("adapter")
}

#[test]
fn none_never_limits() {
    let adapter = token_bucket::None::new("none", 1, 0.0);
    let mut abuse = Abuse::new(adapter);
    assert!(!abuse.check().unwrap());
    assert!(abuse.get_logs(None, None).unwrap().is_empty());
    assert!(abuse.cleanup(0).unwrap());
    abuse.reset().unwrap();
}

#[test]
fn static_key() {
    let mut abuse = Abuse::new(adapter("tb-static-key", 2, 0.001));
    assert!(!abuse.check().unwrap());
    assert!(!abuse.check().unwrap());
    assert!(abuse.check().unwrap());
}

#[test]
fn dynamic_key() {
    let mut adapter = adapter("tb-dynamic-key-{{ip}}", 2, 0.001);
    adapter.set_param("{{ip}}", "0.0.0.10");
    let mut abuse = Abuse::new(adapter);
    assert!(!abuse.check().unwrap());
    assert!(!abuse.check().unwrap());
    assert!(abuse.check().unwrap());
}

#[test]
fn dynamic_key_with_2_params() {
    let mut adapter = adapter("tb-two-params-{{ip}}-{{email}}", 2, 0.001);
    adapter.set_param("{{ip}}", "0.0.0.10");
    adapter.set_param("{{email}}", "test@test.com");
    let mut abuse = Abuse::new(adapter);
    assert!(!abuse.check().unwrap());
    assert!(!abuse.check().unwrap());
    assert!(abuse.check().unwrap());
}

#[test]
fn burst() {
    let mut adapter = adapter("tb-burst-{{ip}}", 10, 0.001);
    adapter.set_param("{{ip}}", "0.0.0.11");
    let mut abuse = Abuse::new(adapter);
    for _ in 0..10 {
        assert!(!abuse.check().unwrap());
    }
    assert!(abuse.check().unwrap());
}

#[test]
fn remaining() {
    let mut adapter = adapter("tb-remaining-{{ip}}", 3, 0.001);
    adapter.set_param("{{ip}}", "0.0.0.12");
    let mut abuse = Abuse::new(adapter);
    assert_eq!(abuse.adapter_mut().remaining(), 2);
    assert!(!abuse.check().unwrap());
    assert_eq!(abuse.adapter_mut().remaining(), 1);
    assert!(!abuse.check().unwrap());
    assert_eq!(abuse.adapter_mut().remaining(), 0);
}

#[test]
fn refill() {
    let mut adapter = adapter("tb-refill-{{ip}}", 1, 1.0);
    adapter.set_param("{{ip}}", "0.0.0.13");
    let mut abuse = Abuse::new(adapter);
    assert!(!abuse.check().unwrap());
    assert!(abuse.check().unwrap());
    std::thread::sleep(std::time::Duration::from_secs(2));
    assert!(!abuse.check().unwrap());
}

#[test]
fn time_format() {
    let adapter = adapter("tb-time", 1, 1.0);
    let _ = adapter.time();
}

#[test]
fn reset() {
    let mut adapter = adapter("tb-reset-test-{{ip}}", 5, 0.001);
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
fn refill_rate_guard() {
    let err = Memory::new("tb-guard", 1, 0.0).unwrap_err();
    assert!(matches!(err, AbuseError::InvalidRefillRate));
}

#[test]
fn unlimited() {
    let mut abuse = Abuse::new(adapter("tb-unlimited", 0, 1.0));
    for _ in 0..20 {
        assert!(!abuse.check().unwrap());
    }
}
