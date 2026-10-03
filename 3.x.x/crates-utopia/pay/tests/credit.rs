#![allow(clippy::float_cmp)]

use serde_json::json;
use utopia_pay::Credit;

fn sample() -> Credit {
    Credit::new("credit-123", 100.0)
}

#[test]
fn constructor() {
    let credit = sample();
    assert_eq!(credit.get_id(), "credit-123");
    assert_eq!(credit.get_credits(), 100.0);
    assert_eq!(credit.get_credits_used(), 0.0);
    assert_eq!(credit.get_status(), Credit::STATUS_ACTIVE);
}

#[test]
fn getters_and_setters() {
    let mut credit = sample();
    credit.set_id("credit-456");
    credit.set_credits(200.0);
    credit.set_credits_used(50.0);
    credit.set_status(Credit::STATUS_APPLIED);
    assert_eq!(credit.get_id(), "credit-456");
    assert_eq!(credit.get_credits(), 200.0);
    assert_eq!(credit.get_credits_used(), 50.0);
    assert_eq!(credit.get_status(), Credit::STATUS_APPLIED);
}

#[test]
fn mark_as_applied() {
    let mut credit = sample();
    credit.mark_as_applied();
    assert_eq!(credit.get_status(), Credit::STATUS_APPLIED);
}

#[test]
fn set_status() {
    let mut credit = sample();
    credit.set_status(Credit::STATUS_EXPIRED);
    assert_eq!(credit.get_status(), Credit::STATUS_EXPIRED);
}

#[test]
fn use_credits() {
    let mut credit = sample();
    let used = credit.use_credits(40.0);
    assert_eq!(used, 40.0);
    assert_eq!(credit.get_credits(), 60.0);
    assert_eq!(credit.get_credits_used(), 40.0);
    assert_eq!(credit.get_status(), Credit::STATUS_ACTIVE);

    let used = credit.use_credits(100.0);
    assert_eq!(used, 60.0);
    assert!((credit.get_credits() - 0.0).abs() < 0.001);
    assert_eq!(credit.get_credits_used(), 100.0);
    if credit.get_status() != Credit::STATUS_APPLIED {
        credit.mark_as_applied();
    }
    assert_eq!(credit.get_status(), Credit::STATUS_APPLIED);
}

#[test]
fn use_credits_with_excess_amount() {
    let mut credit = sample();
    let used = credit.use_credits(150.0);
    assert_eq!(used, 100.0);
    assert!((credit.get_credits() - 0.0).abs() < 0.001);
    assert_eq!(credit.get_credits_used(), 100.0);
    if credit.get_status() != Credit::STATUS_APPLIED {
        credit.mark_as_applied();
    }
    assert_eq!(credit.get_status(), Credit::STATUS_APPLIED);
}

#[test]
fn use_credits_with_negative_amount() {
    let mut credit = sample();
    let used = credit.use_credits(-50.0);
    assert_eq!(used, 0.0);
    assert_eq!(credit.get_credits(), 100.0);
    assert_eq!(credit.get_credits_used(), 0.0);
}

#[test]
fn to_array() {
    let credit = sample();
    let array = credit.to_array();
    assert_eq!(array["id"], json!("credit-123"));
    assert_eq!(array["credits"], json!(100.0));
    assert_eq!(array["creditsUsed"], json!(0.0));
    assert_eq!(array["status"], json!(Credit::STATUS_ACTIVE));
}

#[test]
fn from_array() {
    let data = serde_json::Map::from_iter([
        ("id".into(), json!("credit-789")),
        ("credits".into(), json!(300.0)),
        ("creditsUsed".into(), json!(75.0)),
        ("status".into(), json!(Credit::STATUS_APPLIED)),
    ]);
    let credit = Credit::from_array(&data);
    assert_eq!(credit.get_id(), "credit-789");
    assert_eq!(credit.get_credits(), 300.0);
    assert_eq!(credit.get_credits_used(), 75.0);
    assert_eq!(credit.get_status(), Credit::STATUS_APPLIED);
}

#[test]
fn from_array_with_minimal_data() {
    let data = serde_json::Map::from_iter([
        ("id".into(), json!("credit-789")),
        ("credits".into(), json!(300.0)),
    ]);
    let credit = Credit::from_array(&data);
    assert_eq!(credit.get_id(), "credit-789");
    assert_eq!(credit.get_credits(), 300.0);
    assert_eq!(credit.get_credits_used(), 0.0);
    assert_eq!(credit.get_status(), Credit::STATUS_ACTIVE);
}
