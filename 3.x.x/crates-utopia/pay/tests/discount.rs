#![allow(clippy::float_cmp)]

use serde_json::json;
use utopia_pay::{Discount, PayError};

fn setup() -> (Discount, Discount) {
    let fixed = Discount::new("discount-123", 25.0, "Test Discount", Discount::TYPE_FIXED).unwrap();
    let percentage = Discount::new(
        "discount-456",
        10.0,
        "Percentage Discount",
        Discount::TYPE_PERCENTAGE,
    )
    .unwrap();
    (fixed, percentage)
}

#[test]
fn constructor() {
    let (fixed, _) = setup();
    assert_eq!(fixed.get_id(), "discount-123");
    assert_eq!(fixed.get_value(), 25.0);
    assert_eq!(fixed.get_description(), "Test Discount");
    assert_eq!(fixed.get_type(), Discount::TYPE_FIXED);
}

#[test]
fn getters_and_setters() {
    let (mut fixed, _) = setup();
    fixed.set_id("discount-789");
    fixed.set_value(50.0).unwrap();
    fixed.set_description("Updated Discount");
    fixed.set_type(Discount::TYPE_PERCENTAGE).unwrap();
    assert_eq!(fixed.get_id(), "discount-789");
    assert_eq!(fixed.get_value(), 50.0);
    assert_eq!(fixed.get_description(), "Updated Discount");
    assert_eq!(fixed.get_type(), Discount::TYPE_PERCENTAGE);
}

#[test]
fn calculate_discount_fixed() {
    let (fixed, _) = setup();
    let amount = 100.0;
    assert_eq!(fixed.calculate_discount(amount), 25.0_f64.min(amount));
}

#[test]
fn calculate_discount_fixed_with_lower_invoice_amount() {
    let (fixed, _) = setup();
    assert_eq!(fixed.calculate_discount(20.0), 20.0);
}

#[test]
fn calculate_discount_percentage() {
    let (_, percentage) = setup();
    assert_eq!(percentage.calculate_discount(200.0), 20.0);
}

#[test]
fn calculate_discount_with_zero_invoice_amount() {
    let (fixed, percentage) = setup();
    assert_eq!(fixed.calculate_discount(0.0), 0.0);
    assert_eq!(percentage.calculate_discount(0.0), 0.0);
}

#[test]
fn calculate_discount_with_negative_invoice_amount() {
    let (fixed, percentage) = setup();
    assert_eq!(fixed.calculate_discount(-50.0), 0.0);
    assert_eq!(percentage.calculate_discount(-50.0), 0.0);
}

#[test]
fn to_array() {
    let (fixed, _) = setup();
    let array = fixed.to_array();
    assert_eq!(array["id"], json!("discount-123"));
    assert_eq!(array["value"], json!(25.0));
    assert_eq!(array["description"], json!("Test Discount"));
    assert_eq!(array["type"], json!(Discount::TYPE_FIXED));
}

#[test]
fn from_array() {
    let data = serde_json::Map::from_iter([
        ("id".into(), json!("discount-789")),
        ("value".into(), json!(30.0)),
        ("description".into(), json!("From Array Discount")),
        ("type".into(), json!(Discount::TYPE_FIXED)),
    ]);
    let discount = Discount::from_array(&data).unwrap();
    assert_eq!(discount.get_id(), "discount-789");
    assert_eq!(discount.get_value(), 30.0);
    assert_eq!(discount.get_description(), "From Array Discount");
    assert_eq!(discount.get_type(), Discount::TYPE_FIXED);
}

#[test]
fn from_array_with_minimal_data() {
    let data = serde_json::Map::from_iter([
        ("id".into(), json!("discount-789")),
        ("value".into(), json!(30.0)),
    ]);
    let discount = Discount::from_array(&data).unwrap();
    assert_eq!(discount.get_id(), "discount-789");
    assert_eq!(discount.get_value(), 30.0);
    assert_eq!(discount.get_description(), "");
    assert_eq!(discount.get_type(), Discount::TYPE_FIXED);
}

#[test]
fn negative_discount_value_handling() {
    let err = Discount::new(
        "negative-discount",
        -10.0,
        "Negative test",
        Discount::TYPE_FIXED,
    )
    .unwrap_err();
    match err {
        PayError::InvalidArgument(msg) => assert_eq!(msg, "Discount value cannot be negative"),
        other => panic!("unexpected {other:?}"),
    }
}

#[test]
fn set_negative_discount_value() {
    let (mut fixed, _) = setup();
    let err = fixed.set_value(-20.0).unwrap_err();
    match err {
        PayError::InvalidArgument(msg) => assert_eq!(msg, "Discount value cannot be negative"),
        other => panic!("unexpected {other:?}"),
    }
}

#[test]
fn from_array_with_negative_value() {
    let data = serde_json::Map::from_iter([
        ("id".into(), json!("discount-negative")),
        ("value".into(), json!(-10.0)),
        ("type".into(), json!(Discount::TYPE_FIXED)),
    ]);
    let err = Discount::from_array(&data).unwrap_err();
    match err {
        PayError::InvalidArgument(msg) => assert_eq!(msg, "Discount value cannot be negative"),
        other => panic!("unexpected {other:?}"),
    }
}

#[test]
fn from_array_with_null_value() {
    let data = serde_json::Map::from_iter([
        ("id".into(), json!("discount-null")),
        ("type".into(), json!(Discount::TYPE_FIXED)),
    ]);
    let err = Discount::from_array(&data).unwrap_err();
    match err {
        PayError::InvalidArgument(msg) => assert_eq!(msg, "Discount value cannot be null"),
        other => panic!("unexpected {other:?}"),
    }
}
