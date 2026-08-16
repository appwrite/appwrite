//! Port of `tests/Attributes/IPTest.php`.

use serde_json::json;
use utopia_waf::{Attribute, Condition, Ip};

fn ip() -> Ip {
    Ip
}

#[test]
fn compare_handles_cidr_values() {
    let t = ip();
    assert_eq!(
        t.compare(
            Condition::TYPE_EQUAL,
            &json!("10.0.0.1"),
            &json!("10.0.0.0/8")
        ),
        Some(true)
    );
    assert_eq!(
        t.compare(
            Condition::TYPE_EQUAL,
            &json!("10.255.255.255"),
            &json!("10.0.0.0/8")
        ),
        Some(true)
    );
    assert_eq!(
        t.compare(
            Condition::TYPE_EQUAL,
            &json!("11.0.0.0"),
            &json!("10.0.0.0/8")
        ),
        Some(false)
    );
    assert_eq!(
        t.compare(
            Condition::TYPE_EQUAL,
            &json!("9.255.255.255"),
            &json!("10.0.0.0/8")
        ),
        Some(false)
    );
}

#[test]
fn compare_handles_non_octet_aligned_prefixes() {
    let t = ip();
    assert_eq!(
        t.compare(
            Condition::TYPE_EQUAL,
            &json!("192.168.31.255"),
            &json!("192.168.16.0/20")
        ),
        Some(true)
    );
    assert_eq!(
        t.compare(
            Condition::TYPE_EQUAL,
            &json!("192.168.32.0"),
            &json!("192.168.16.0/20")
        ),
        Some(false)
    );
}

#[test]
fn compare_handles_host_and_catch_all_prefixes() {
    let t = ip();
    assert_eq!(
        t.compare(
            Condition::TYPE_EQUAL,
            &json!("203.0.113.10"),
            &json!("203.0.113.10/32")
        ),
        Some(true)
    );
    assert_eq!(
        t.compare(
            Condition::TYPE_EQUAL,
            &json!("203.0.113.11"),
            &json!("203.0.113.10/32")
        ),
        Some(false)
    );
    assert_eq!(
        t.compare(
            Condition::TYPE_EQUAL,
            &json!("203.0.113.10"),
            &json!("0.0.0.0/0")
        ),
        Some(true)
    );
    assert_eq!(
        t.compare(
            Condition::TYPE_EQUAL,
            &json!("10.200.0.1"),
            &json!("10.1.2.3/8")
        ),
        Some(true)
    );
}

#[test]
fn compare_handles_ipv6() {
    let t = ip();
    assert_eq!(
        t.compare(
            Condition::TYPE_EQUAL,
            &json!("2001:db8::1"),
            &json!("2001:db8::/32")
        ),
        Some(true)
    );
    assert_eq!(
        t.compare(
            Condition::TYPE_EQUAL,
            &json!("2001:db8:ffff:ffff:ffff:ffff:ffff:ffff"),
            &json!("2001:db8::/32")
        ),
        Some(true)
    );
    assert_eq!(
        t.compare(
            Condition::TYPE_EQUAL,
            &json!("2001:db9::1"),
            &json!("2001:db8::/32")
        ),
        Some(false)
    );
    assert_eq!(
        t.compare(Condition::TYPE_EQUAL, &json!("::1"), &json!("::1/128")),
        Some(true)
    );
    assert_eq!(
        t.compare(Condition::TYPE_EQUAL, &json!("::2"), &json!("::1/128")),
        Some(false)
    );
    assert_eq!(
        t.compare(Condition::TYPE_EQUAL, &json!("2001:db8::1"), &json!("::/0")),
        Some(true)
    );
}

#[test]
fn compare_rejects_family_mismatch() {
    let t = ip();
    assert_eq!(
        t.compare(
            Condition::TYPE_EQUAL,
            &json!("2001:db8::1"),
            &json!("10.0.0.0/8")
        ),
        Some(false)
    );
    assert_eq!(
        t.compare(
            Condition::TYPE_EQUAL,
            &json!("10.0.0.1"),
            &json!("2001:db8::/32")
        ),
        Some(false)
    );
}

#[test]
fn compare_falls_back_for_plain_ips() {
    assert_eq!(
        ip().compare(
            Condition::TYPE_EQUAL,
            &json!("10.0.0.1"),
            &json!("10.0.0.1")
        ),
        None
    );
}

#[test]
fn compare_falls_back_for_malformed_cidr_values() {
    let t = ip();
    let value = json!("10.0.0.1");
    assert_eq!(
        t.compare(Condition::TYPE_EQUAL, &value, &json!("10.0.0.0/33")),
        None
    );
    assert_eq!(
        t.compare(Condition::TYPE_EQUAL, &value, &json!("2001:db8::/129")),
        None
    );
    assert_eq!(
        t.compare(Condition::TYPE_EQUAL, &value, &json!("10.0.0.0/")),
        None
    );
    assert_eq!(
        t.compare(Condition::TYPE_EQUAL, &value, &json!("10.0.0.0/-1")),
        None
    );
    assert_eq!(
        t.compare(Condition::TYPE_EQUAL, &value, &json!("10.0.0.0/8.5")),
        None
    );
    assert_eq!(
        t.compare(Condition::TYPE_EQUAL, &value, &json!("not-an-ip/8")),
        None
    );
    assert_eq!(t.compare(Condition::TYPE_EQUAL, &value, &json!("/8")), None);
    assert_eq!(t.compare(Condition::TYPE_EQUAL, &value, &json!("")), None);
}

#[test]
fn compare_falls_back_for_other_methods() {
    let t = ip();
    assert_eq!(
        t.compare(
            Condition::TYPE_CONTAINS,
            &json!("10.0.0.1"),
            &json!("10.0.0.0/8")
        ),
        None
    );
    assert_eq!(
        t.compare(
            Condition::TYPE_STARTS_WITH,
            &json!("10.0.0.1"),
            &json!("10.0.0.0/8")
        ),
        None
    );
}

#[test]
fn compare_rejects_non_string_values_against_cidr() {
    let t = ip();
    assert_eq!(
        t.compare(Condition::TYPE_EQUAL, &json!(null), &json!("10.0.0.0/8")),
        Some(false)
    );
    assert_eq!(
        t.compare(Condition::TYPE_EQUAL, &json!(42), &json!("10.0.0.0/8")),
        Some(false)
    );
    assert_eq!(
        t.compare(
            Condition::TYPE_EQUAL,
            &json!("not-an-ip"),
            &json!("10.0.0.0/8")
        ),
        Some(false)
    );
}

#[test]
fn validate_value() {
    let t = ip();
    assert!(t
        .validate_value(Condition::TYPE_EQUAL, &json!("203.0.113.10"))
        .is_none());
    assert!(t
        .validate_value(Condition::TYPE_EQUAL, &json!("10.0.0.0/8"))
        .is_none());
    assert!(t
        .validate_value(Condition::TYPE_NOT_EQUAL, &json!("2001:db8::/32"))
        .is_none());

    assert!(t
        .validate_value(Condition::TYPE_EQUAL, &json!("10.0.0.0/33"))
        .is_some());
    assert!(t
        .validate_value(Condition::TYPE_EQUAL, &json!("not-an-ip"))
        .is_some());
    assert!(t
        .validate_value(Condition::TYPE_EQUAL, &json!(42))
        .is_some());

    assert!(t
        .validate_value(Condition::TYPE_IS_NULL, &json!("anything"))
        .is_none());
}
