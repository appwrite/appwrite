#![allow(
    clippy::unused_async,
    clippy::default_trait_access,
    clippy::float_cmp,
    clippy::unreadable_literal
)]

use serde_json::{json, Value};
use utopia_pay::{Adapter, Pay, Stripe};
use utopia_test_wiremock::{header, method, path, Mock, MockServer, ResponseTemplate};

async fn stripe_at(mock: &MockServer) -> Stripe {
    Stripe::new("sk_test_123").with_base_url(mock.uri() + "/v1")
}

async fn blocking<T, F>(f: F) -> T
where
    T: Send + 'static,
    F: FnOnce() -> T + Send + 'static,
{
    tokio::task::spawn_blocking(f)
        .await
        .expect("blocking stripe call")
}

#[tokio::test(flavor = "multi_thread")]
async fn name() {
    let stripe = Stripe::new("sk_test_123");
    assert_eq!(stripe.get_name(), "Stripe");
}

#[tokio::test(flavor = "multi_thread")]
async fn create_and_get_customer() {
    let mock = MockServer::start().await;
    Mock::given(method("POST"))
        .and(path("/v1/customers"))
        .and(header("Authorization", "Bearer sk_test_123"))
        .respond_with(ResponseTemplate::new(200).set_body_json(json!({
            "id": "cus_123",
            "name": "Test customer",
            "email": "testcustomer@email.com",
        })))
        .mount(&mock)
        .await;
    Mock::given(method("GET"))
        .and(path("/v1/customers/cus_123"))
        .respond_with(ResponseTemplate::new(200).set_body_json(json!({
            "id": "cus_123",
            "name": "Test customer",
            "email": "testcustomer@email.com",
        })))
        .mount(&mock)
        .await;

    let stripe = stripe_at(&mock).await;
    let stripe2 = stripe.clone();
    let customer = blocking(move || {
        stripe.create_customer(
            "Test customer",
            "testcustomer@email.com",
            json!({
                "city": "Kathmandu",
                "country": "NP",
                "line1": "Gaurighat",
                "line2": "Pambu Marga",
                "postal_code": "44600",
                "state": "Bagmati",
            }),
            None,
        )
    })
    .await
    .unwrap();
    assert_eq!(customer["id"], "cus_123");
    assert_eq!(customer["name"], "Test customer");
    assert_eq!(customer["email"], "testcustomer@email.com");

    let fetched = blocking(move || stripe2.get_customer("cus_123"))
        .await
        .unwrap();
    assert_eq!(fetched["id"], "cus_123");
}

#[tokio::test(flavor = "multi_thread")]
async fn update_list_delete_customer() {
    let mock = MockServer::start().await;
    Mock::given(method("POST"))
        .and(path("/v1/customers/cus_123"))
        .respond_with(ResponseTemplate::new(200).set_body_json(json!({
            "id": "cus_123",
            "name": "Test Updated",
            "email": "testcustomerupdated@email.com",
        })))
        .mount(&mock)
        .await;
    Mock::given(method("GET"))
        .and(path("/v1/customers"))
        .respond_with(ResponseTemplate::new(200).set_body_json(json!({
            "data": [{"id": "cus_123", "name": "Test Updated", "email": "a@b.c"}]
        })))
        .mount(&mock)
        .await;
    Mock::given(method("DELETE"))
        .and(path("/v1/customers/cus_123"))
        .respond_with(
            ResponseTemplate::new(200).set_body_json(json!({"deleted": true, "id": "cus_123"})),
        )
        .mount(&mock)
        .await;

    let stripe = stripe_at(&mock).await;
    let stripe2 = stripe.clone();
    let stripe3 = stripe.clone();
    let customer = blocking(move || {
        stripe.update_customer(
            "cus_123",
            "Test Updated",
            "testcustomerupdated@email.com",
            None,
            None,
        )
    })
    .await
    .unwrap();
    assert_eq!(customer["name"], "Test Updated");
    let listed = blocking(move || stripe2.list_customers()).await.unwrap();
    assert!(!listed["data"].as_array().unwrap().is_empty());
    assert!(blocking(move || stripe3.delete_customer("cus_123"))
        .await
        .unwrap());
}

#[tokio::test(flavor = "multi_thread")]
async fn payment_method_flow() {
    let mock = MockServer::start().await;
    Mock::given(method("POST"))
        .and(path("/v1/payment_methods"))
        .respond_with(ResponseTemplate::new(200).set_body_json(json!({
            "id": "pm_123",
            "card": {"brand": "visa", "country": "US", "exp_year": 2030, "exp_month": 8, "last4": "4242"}
        })))
        .mount(&mock)
        .await;
    Mock::given(method("POST"))
        .and(path("/v1/payment_methods/pm_123/attach"))
        .respond_with(ResponseTemplate::new(200).set_body_json(json!({
            "id": "pm_123",
            "card": {"brand": "visa", "country": "US", "exp_year": 2030, "exp_month": 8, "last4": "4242"}
        })))
        .mount(&mock)
        .await;
    Mock::given(method("GET"))
        .and(path("/v1/customers/cus_123/payment_methods"))
        .respond_with(ResponseTemplate::new(200).set_body_json(json!({
            "data": [{
                "id": "pm_123",
                "card": {"brand": "visa", "country": "US", "exp_year": 2030, "exp_month": 8, "last4": "4242"}
            }]
        })))
        .mount(&mock)
        .await;
    Mock::given(method("GET"))
        .and(path("/v1/customers/cus_123/payment_methods/pm_123"))
        .respond_with(ResponseTemplate::new(200).set_body_json(json!({
            "id": "pm_123",
            "card": {"brand": "visa", "country": "US", "exp_year": 2030, "exp_month": 8, "last4": "4242"}
        })))
        .mount(&mock)
        .await;

    let stripe = stripe_at(&mock).await;
    let stripe2 = stripe.clone();
    let stripe3 = stripe.clone();
    let pm = blocking(move || {
        stripe.create_payment_method(
            "cus_123",
            "card",
            json!({"number": 4242424242424242i64, "exp_month": 8, "exp_year": 2030, "cvc": 123}),
        )
    })
    .await
    .unwrap();
    assert_eq!(pm["id"], "pm_123");
    assert_eq!(pm["card"]["brand"], "visa");
    let listed = blocking(move || stripe2.list_payment_methods("cus_123"))
        .await
        .unwrap();
    assert_eq!(listed["data"][0]["card"]["last4"], "4242");
    let fetched = blocking(move || stripe3.get_payment_method("cus_123", "pm_123"))
        .await
        .unwrap();
    assert_eq!(fetched["id"], "pm_123");
}

#[tokio::test(flavor = "multi_thread")]
async fn purchase_and_errors() {
    let mock = MockServer::start().await;
    Mock::given(method("POST"))
        .and(path("/v1/payment_intents"))
        .respond_with(ResponseTemplate::new(200).set_body_json(json!({
            "id": "pi_123",
            "status": "succeeded",
            "amount": 1000,
        })))
        .mount(&mock)
        .await;

    let stripe = stripe_at(&mock).await;
    let intent = blocking(move || {
        Pay::new(stripe).purchase(
            1000,
            "cus_123",
            Some("pm_123"),
            Value::Object(Default::default()),
        )
    })
    .await
    .unwrap();
    assert_eq!(intent["id"], "pi_123");
}

#[tokio::test(flavor = "multi_thread")]
async fn stripe_error_maps_card_decline() {
    let mock = MockServer::start().await;
    Mock::given(method("POST"))
        .and(path("/v1/payment_intents"))
        .respond_with(ResponseTemplate::new(402).set_body_json(json!({
            "error": {
                "type": "card_error",
                "code": "card_declined",
                "decline_code": "insufficient_funds",
                "message": "Your card has insufficient funds.",
            }
        })))
        .mount(&mock)
        .await;

    let stripe = stripe_at(&mock).await;
    let err = blocking(move || stripe.purchase(500, "cus_123", Some("pm_123"), json!({})))
        .await
        .unwrap_err();
    assert_eq!(err.get_type(), "insufficient_funds");
    assert_eq!(err.get_code(), 402);
}

#[tokio::test(flavor = "multi_thread")]
async fn setup_intent_and_disputes() {
    let mock = MockServer::start().await;
    Mock::given(method("POST"))
        .and(path("/v1/setup_intents"))
        .respond_with(ResponseTemplate::new(200).set_body_json(json!({
            "id": "seti_123",
            "client_secret": "secret",
        })))
        .mount(&mock)
        .await;
    Mock::given(method("GET"))
        .and(path("/v1/setup_intents"))
        .respond_with(ResponseTemplate::new(200).set_body_json(json!({
            "data": [{"id": "seti_123"}]
        })))
        .mount(&mock)
        .await;
    Mock::given(method("GET"))
        .and(path("/v1/disputes"))
        .respond_with(ResponseTemplate::new(200).set_body_json(json!({
            "data": [{"id": "dp_123"}]
        })))
        .mount(&mock)
        .await;

    let stripe = stripe_at(&mock).await;
    let stripe2 = stripe.clone();
    let stripe3 = stripe.clone();
    let setup = blocking(move || {
        stripe.create_future_payment(
            "cus_123",
            None,
            json!(["card"]),
            json!({"card": {"mandate_options": {"amount": 15000}}}),
            None,
        )
    })
    .await
    .unwrap();
    assert_eq!(setup["client_secret"], "secret");
    let listed = blocking(move || stripe2.list_future_payments(Some("cus_123"), None))
        .await
        .unwrap();
    assert_eq!(listed[0]["id"], "seti_123");
    let disputes = blocking(move || stripe3.list_disputes(Some(10), None, None, None))
        .await
        .unwrap();
    assert_eq!(disputes[0]["id"], "dp_123");
}
