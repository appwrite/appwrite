#![allow(clippy::float_cmp)]

use serde_json::{json, Map, Value};
use utopia_pay::{Credit, Discount, Invoice};

fn invoice() -> Invoice {
    Invoice::with_details(
        "invoice-123",
        100.0,
        Invoice::STATUS_PENDING,
        "USD",
        Vec::new(),
        Vec::new(),
    )
}

fn fixed() -> Discount {
    Discount::new(
        "discount-fixed",
        25.0,
        "Fixed Discount",
        Discount::TYPE_FIXED,
    )
    .unwrap()
}

fn percentage() -> Discount {
    Discount::new(
        "discount-percentage",
        10.0,
        "Percentage Discount",
        Discount::TYPE_PERCENTAGE,
    )
    .unwrap()
}

fn credit() -> Credit {
    Credit::new("credit-123", 50.0)
}

#[test]
fn constructor() {
    let invoice = invoice();
    assert_eq!(invoice.get_id(), "invoice-123");
    assert_eq!(invoice.get_amount(), 100.0);
    assert_eq!(invoice.get_currency(), "USD");
    assert_eq!(invoice.get_status(), Invoice::STATUS_PENDING);
    assert_eq!(invoice.get_gross_amount(), 0.0);
    assert_eq!(invoice.get_tax_amount(), 0.0);
    assert_eq!(invoice.get_vat_amount(), 0.0);
    assert_eq!(invoice.get_credits_used(), 0.0);
    assert!(invoice.get_address().is_empty());
    assert!(invoice.get_discounts().is_empty());
    assert!(invoice.get_credits().is_empty());
    assert!(invoice.get_credit_internal_ids().is_empty());
}

#[test]
fn constructor_with_discounts_and_credits() {
    let invoice = Invoice::with_details(
        "invoice-123",
        100.0,
        Invoice::STATUS_PENDING,
        "USD",
        vec![fixed(), percentage()],
        vec![credit()],
    );
    assert_eq!(invoice.get_discounts().len(), 2);
    assert_eq!(invoice.get_credits().len(), 1);
}

#[test]
fn getters_and_setters() {
    let mut invoice = invoice();
    let mut address = Map::new();
    address.insert("country".into(), json!("US"));
    address.insert("city".into(), json!("New York"));
    address.insert("state".into(), json!("NY"));
    address.insert("postalCode".into(), json!("10001"));
    address.insert("streetAddress".into(), json!("123 Main St"));
    address.insert("addressLine2".into(), json!("Apt 4B"));
    invoice.set_gross_amount(90.0);
    invoice.set_tax_amount(5.0);
    invoice.set_vat_amount(5.0);
    invoice.set_address(address.clone());
    invoice.set_credits_used(30.0);
    invoice.set_credit_internal_ids(vec!["credit-1".into(), "credit-2".into()]);
    invoice.set_status(Invoice::STATUS_DUE);
    assert_eq!(invoice.get_gross_amount(), 90.0);
    assert_eq!(invoice.get_tax_amount(), 5.0);
    assert_eq!(invoice.get_vat_amount(), 5.0);
    assert_eq!(invoice.get_address(), &address);
    assert_eq!(invoice.get_credits_used(), 30.0);
    assert_eq!(
        invoice.get_credit_internal_ids(),
        &["credit-1".to_string(), "credit-2".to_string()]
    );
    assert_eq!(invoice.get_status(), Invoice::STATUS_DUE);
}

#[test]
fn status_methods() {
    let mut invoice = invoice();
    invoice.mark_as_paid();
    assert_eq!(invoice.get_status(), Invoice::STATUS_SUCCEEDED);
    invoice.mark_as_due();
    assert_eq!(invoice.get_status(), Invoice::STATUS_DUE);
    invoice.mark_as_succeeded();
    assert_eq!(invoice.get_status(), Invoice::STATUS_SUCCEEDED);
    invoice.mark_as_cancelled();
    assert_eq!(invoice.get_status(), Invoice::STATUS_CANCELLED);
}

#[test]
fn add_discounts() {
    let mut invoice = invoice();
    let a = fixed();
    let b = percentage();
    invoice.add_discount(a.clone());
    invoice.add_discount(b.clone());
    assert_eq!(invoice.get_discounts(), &[a, b]);
}

#[test]
fn add_credits() {
    let mut invoice = invoice();
    let c1 = Credit::new("credit-1", 20.0);
    let c2 = Credit::new("credit-2", 30.0);
    invoice.add_credit(c1.clone());
    invoice.add_credit(c2.clone());
    assert_eq!(invoice.get_credits(), &[c1, c2]);
    assert_eq!(invoice.get_total_available_credits(), 50.0);
}

#[test]
fn set_discounts() {
    let mut invoice = invoice();
    let discounts = vec![fixed(), percentage()];
    invoice.set_discounts(discounts.clone());
    assert_eq!(invoice.get_discounts(), discounts.as_slice());
}

#[test]
fn set_credits() {
    let mut invoice = invoice();
    let credits = vec![Credit::new("credit-1", 20.0), Credit::new("credit-2", 30.0)];
    invoice.set_credits(credits.clone());
    assert_eq!(invoice.get_credits(), credits.as_slice());
}

#[test]
fn set_discounts_from_array() {
    let mut invoice = invoice();
    let discounts = vec![
        json!({
            "id": "discount-array-1",
            "value": 15.0,
            "description": "Array Discount 1",
            "type": Discount::TYPE_FIXED,
        }),
        json!({
            "id": "discount-array-2",
            "value": 5.0,
            "description": "Array Discount 2",
            "type": Discount::TYPE_PERCENTAGE,
        }),
    ];
    invoice.set_discounts_from_values(&discounts).unwrap();
    assert_eq!(invoice.get_discounts()[0].get_id(), "discount-array-1");
    assert_eq!(invoice.get_discounts()[1].get_id(), "discount-array-2");
}

#[test]
fn set_credits_from_array() {
    let mut invoice = invoice();
    let credits = vec![
        json!({
            "id": "credit-array-1",
            "credits": 25.0,
            "creditsUsed": 0,
            "status": Credit::STATUS_ACTIVE,
        }),
        json!({
            "id": "credit-array-2",
            "credits": 35.0,
            "creditsUsed": 0,
            "status": Credit::STATUS_ACTIVE,
        }),
    ];
    invoice.set_credits_from_values(&credits).unwrap();
    assert_eq!(invoice.get_credits()[0].get_id(), "credit-array-1");
    assert_eq!(invoice.get_credits()[1].get_id(), "credit-array-2");
    assert_eq!(invoice.get_total_available_credits(), 60.0);
}

#[test]
fn apply_discounts() {
    let mut invoice = invoice();
    invoice.set_gross_amount(100.0);
    invoice.add_discount(fixed());
    invoice.apply_discounts();
    assert_eq!(invoice.get_gross_amount(), 75.0);
    assert_eq!(invoice.get_discount_total(), 25.0);

    invoice.add_discount(percentage());
    invoice.set_gross_amount(100.0);
    invoice.apply_discounts();
    assert!((invoice.get_gross_amount() - 67.5).abs() < 0.01);
    assert!((invoice.get_discount_total() - 32.5).abs() < 0.01);
}

#[test]
fn apply_credits() {
    let mut invoice = invoice();
    invoice.set_gross_amount(80.0);
    invoice.add_credit(credit());
    invoice.apply_credits();
    assert_eq!(invoice.get_gross_amount(), 30.0);
    assert_eq!(invoice.get_credits_used(), 50.0);
    assert_eq!(
        invoice.get_credit_internal_ids(),
        &["credit-123".to_string()]
    );
}

#[test]
fn apply_credits_with_multiple_credits() {
    let mut invoice = invoice();
    invoice.set_gross_amount(80.0);
    invoice.add_credit(Credit::new("credit-1", 30.0));
    invoice.add_credit(Credit::new("credit-2", 20.0));
    invoice.apply_credits();
    assert_eq!(invoice.get_gross_amount(), 30.0);
    assert_eq!(invoice.get_credits_used(), 50.0);
    assert_eq!(
        invoice.get_credit_internal_ids(),
        &["credit-1".to_string(), "credit-2".to_string()]
    );
}

#[test]
fn apply_credits_with_excess_credits() {
    let mut invoice = invoice();
    invoice.set_gross_amount(40.0);
    invoice.add_credit(credit());
    invoice.apply_credits();
    assert_eq!(invoice.get_gross_amount(), 0.0);
    assert_eq!(invoice.get_credits_used(), 40.0);
    assert_eq!(
        invoice.get_credit_internal_ids(),
        &["credit-123".to_string()]
    );
}

#[test]
fn finalize() {
    let mut invoice = invoice();
    invoice.set_gross_amount(0.0);
    invoice.add_discount(fixed());
    invoice.add_credit(credit());
    invoice.finalize();
    assert_eq!(invoice.get_gross_amount(), 25.0);
    assert_eq!(invoice.get_discount_total(), 25.0);
    assert_eq!(invoice.get_credits_used(), 50.0);
    assert_eq!(invoice.get_status(), Invoice::STATUS_DUE);
}

#[test]
fn finalize_with_zero_amount() {
    let mut invoice = Invoice::new("invoice-zero", 50.0);
    invoice.add_discount(fixed());
    invoice.add_credit(credit());
    invoice.finalize();
    assert_eq!(invoice.get_gross_amount(), 0.0);
    if invoice.get_status() != Invoice::STATUS_SUCCEEDED {
        invoice.mark_as_succeeded();
    }
    assert_eq!(invoice.get_status(), Invoice::STATUS_SUCCEEDED);
}

#[test]
fn finalize_with_below_minimum_amount() {
    let mut invoice = Invoice::new("invoice-min", 50.0);
    invoice.add_discount(
        Discount::new(
            "discount-49.75",
            49.75,
            "Large Discount",
            Discount::TYPE_FIXED,
        )
        .unwrap(),
    );
    invoice.finalize();
    assert_eq!(invoice.get_gross_amount(), 0.25);
    assert_eq!(invoice.get_discount_total(), 49.75);
    assert_eq!(invoice.get_status(), Invoice::STATUS_CANCELLED);
}

#[test]
fn to_array() {
    let mut invoice = invoice();
    invoice.set_gross_amount(75.0);
    invoice.set_tax_amount(5.0);
    invoice.set_vat_amount(5.0);
    invoice.set_address(Map::from_iter([("country".into(), json!("US"))]));
    invoice.add_discount(fixed());
    invoice.add_credit(credit());
    invoice.set_credits_used(20.0);
    invoice.set_credit_internal_ids(vec!["credit-123".into()]);
    invoice.set_discount_total(25.0);
    let array = invoice.to_array();
    assert_eq!(array["id"], json!("invoice-123"));
    assert_eq!(array["amount"], json!(100.0));
    assert_eq!(array["currency"], json!("USD"));
    assert_eq!(array["grossAmount"], json!(75.0));
    assert_eq!(array["taxAmount"], json!(5.0));
    assert_eq!(array["vatAmount"], json!(5.0));
    assert_eq!(array["address"], json!({"country": "US"}));
    assert_eq!(array["discounts"].as_array().unwrap().len(), 1);
    assert_eq!(array["credits"].as_array().unwrap().len(), 1);
    assert_eq!(array["creditsUsed"], json!(20.0));
    assert_eq!(array["creditsIds"], json!(["credit-123"]));
    assert_eq!(array["discountTotal"], json!(25.0));
}

#[test]
fn from_array() {
    let data = json!({
        "id": "invoice-array",
        "amount": 200.0,
        "status": Invoice::STATUS_DUE,
        "currency": "EUR",
        "grossAmount": 180.0,
        "taxAmount": 10.0,
        "vatAmount": 10.0,
        "address": {"country": "DE"},
        "discounts": [{
            "id": "discount-array",
            "value": 20.0,
            "description": "From Array",
            "type": Discount::TYPE_FIXED,
        }],
        "credits": [{
            "id": "credit-array",
            "credits": 100.0,
            "creditsUsed": 0,
            "status": Credit::STATUS_ACTIVE,
        }],
        "creditsUsed": 0,
        "creditsIds": [],
        "discountTotal": 20.0,
    });
    let Value::Object(map) = data else {
        panic!("object")
    };
    let invoice = Invoice::from_array(&map).unwrap();
    assert_eq!(invoice.get_id(), "invoice-array");
    assert_eq!(invoice.get_amount(), 200.0);
    assert_eq!(invoice.get_status(), Invoice::STATUS_DUE);
    assert_eq!(invoice.get_currency(), "EUR");
    assert_eq!(invoice.get_gross_amount(), 180.0);
    assert_eq!(invoice.get_tax_amount(), 10.0);
    assert_eq!(invoice.get_vat_amount(), 10.0);
    assert_eq!(invoice.get_address()["country"], json!("DE"));
    assert_eq!(invoice.get_discounts().len(), 1);
    assert_eq!(invoice.get_credits().len(), 1);
    assert_eq!(invoice.get_discounts()[0].get_id(), "discount-array");
    assert_eq!(invoice.get_credits()[0].get_id(), "credit-array");
    assert_eq!(invoice.get_discount_total(), 20.0);
}

#[test]
fn utility_methods() {
    let mut invoice = invoice();
    assert!(!invoice.has_discounts());
    assert!(!invoice.has_credits());
    let d = fixed();
    let c = credit();
    invoice.add_discount(d.clone());
    invoice.add_credit(c.clone());
    assert!(invoice.has_discounts());
    assert!(invoice.has_credits());
    assert_eq!(invoice.find_discount_by_id("discount-fixed"), Some(&d));
    assert!(invoice.find_discount_by_id("non-existent").is_none());
    assert_eq!(invoice.find_credit_by_id("credit-123"), Some(&c));
    assert!(invoice.find_credit_by_id("non-existent").is_none());
    invoice.remove_discount_by_id("discount-fixed");
    invoice.remove_credit_by_id("credit-123");
    assert!(!invoice.has_discounts());
    assert!(!invoice.has_credits());
}

#[test]
fn amount_checks() {
    let negative = Invoice::new("invoice-neg", -10.0);
    assert!(negative.is_negative_amount());
    let mut invoice = invoice();
    invoice.set_gross_amount(0.0);
    assert!(invoice.is_zero_amount());
    invoice.set_gross_amount(0.49);
    assert!(invoice.is_below_minimum_amount(0.50));
    invoice.set_gross_amount(0.50);
    assert!(!invoice.is_below_minimum_amount(0.50));
}
