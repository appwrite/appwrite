use std::sync::Arc;

use serde_json::{json, Map, Value};

use super::Adapter;
use crate::http::{form_encode, php_empty_str, HttpClient, UtopiaClient};
use crate::{Address, PayError};

/// PHP `Utopia\Pay\Adapter\Stripe`.
#[derive(Clone)]
pub struct Stripe {
    secret_key: String,
    currency: String,
    test_mode: bool,
    base_url: String,
    client: Option<Arc<dyn HttpClient>>,
}

impl std::fmt::Debug for Stripe {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Stripe")
            .field("currency", &self.currency)
            .field("base_url", &self.base_url)
            .finish_non_exhaustive()
    }
}

impl Stripe {
    /// PHP `__construct(string $secretKey, string $currency = 'USD', ?ClientInterface $client = null)`.
    #[must_use]
    pub fn new(secret_key: impl Into<String>) -> Self {
        Self {
            secret_key: secret_key.into(),
            currency: "USD".into(),
            test_mode: false,
            base_url: "https://api.stripe.com/v1".into(),
            client: None,
        }
    }

    #[must_use]
    pub fn with_currency(mut self, currency: impl Into<String>) -> Self {
        self.currency = currency.into();
        self
    }

    #[must_use]
    pub fn with_client(mut self, client: Arc<dyn HttpClient>) -> Self {
        self.client = Some(client);
        self
    }

    /// Test helper: point at wiremock.
    #[must_use]
    pub fn with_base_url(mut self, base_url: impl Into<String>) -> Self {
        self.base_url = base_url.into();
        self
    }

    fn execute(&self, method: &str, path: &str, body: Value) -> Result<Value, PayError> {
        let mut url = format!("{}{path}", self.base_url);
        let encoded = if body.as_object().is_some_and(Map::is_empty) || body.is_null() {
            String::new()
        } else {
            form_encode(&body)
        };
        let mut headers = vec![
            (
                "Authorization".into(),
                format!("Bearer {}", self.secret_key),
            ),
            ("User-Agent".into(), "utopia-pay-rust".into()),
        ];
        let request_body = if method.eq_ignore_ascii_case("GET") {
            if !encoded.is_empty() {
                if url.contains('?') {
                    url.push('&');
                } else {
                    url.push('?');
                }
                url.push_str(&encoded);
            }
            None
        } else {
            headers.push((
                "Content-Type".into(),
                "application/x-www-form-urlencoded".into(),
            ));
            if encoded.is_empty() {
                None
            } else {
                Some(encoded)
            }
        };
        let client = self
            .client
            .clone()
            .unwrap_or_else(|| Arc::new(UtopiaClient::default()));
        let resp = client.send(method, &url, &headers, request_body.as_deref());
        if let Some(err) = resp.error {
            return Err(handle_error(0, Value::String(err)));
        }
        let parsed =
            if resp.content_type.contains("json") || resp.body.trim_start().starts_with('{') {
                serde_json::from_str(&resp.body).unwrap_or(Value::String(resp.body.clone()))
            } else {
                Value::String(resp.body.clone())
            };
        if resp.status >= 400 {
            return Err(handle_error(i32::from(resp.status), parsed));
        }
        Ok(parsed)
    }
}

fn handle_error(code: i32, response: Value) -> PayError {
    if let Value::Object(map) = &response {
        let error = map
            .get("error")
            .cloned()
            .unwrap_or(Value::Object(Map::new()));
        let mut r#type = error
            .get("code")
            .and_then(Value::as_str)
            .unwrap_or(PayError::GENERAL_UNKNOWN)
            .to_owned();
        let stripe_type = error.get("type").and_then(Value::as_str).unwrap_or("");
        if stripe_type == "card_error" {
            if let Some(decline) = error.get("decline_code").and_then(Value::as_str) {
                decline.clone_into(&mut r#type);
            }
        }
        let message = error
            .get("message")
            .and_then(Value::as_str)
            .unwrap_or("Unknown error")
            .to_owned();
        return PayError::gateway(r#type, message, code, error);
    }
    let message = response.as_str().unwrap_or("Unknown error").to_owned();
    PayError::gateway(PayError::GENERAL_UNKNOWN, message, code, response)
}

fn merge(mut base: Map<String, Value>, extra: Value) -> Value {
    if let Value::Object(extra) = extra {
        for (k, v) in extra {
            base.insert(k, v);
        }
    }
    Value::Object(base)
}

impl Adapter for Stripe {
    fn set_test_mode(&mut self, test_mode: bool) {
        self.test_mode = test_mode;
    }

    fn get_test_mode(&self) -> bool {
        self.test_mode
    }

    fn get_name(&self) -> &'static str {
        "Stripe"
    }

    fn set_currency(&mut self, currency: String) {
        self.currency = currency;
    }

    fn get_currency(&self) -> &str {
        &self.currency
    }

    fn purchase(
        &self,
        amount: i64,
        customer_id: &str,
        payment_method_id: Option<&str>,
        additional: Value,
    ) -> Result<Value, PayError> {
        let mut body = Map::new();
        body.insert("amount".into(), json!(amount));
        body.insert("currency".into(), json!(self.currency));
        body.insert("customer".into(), json!(customer_id));
        body.insert("payment_method".into(), json!(payment_method_id));
        body.insert("off_session".into(), json!("true"));
        body.insert("confirm".into(), json!("true"));
        self.execute("POST", "/payment_intents", merge(body, additional))
    }

    fn authorize(
        &self,
        amount: i64,
        customer_id: &str,
        payment_method_id: Option<&str>,
        additional: Value,
    ) -> Result<Value, PayError> {
        let mut body = Map::new();
        body.insert("amount".into(), json!(amount));
        body.insert("currency".into(), json!(self.currency));
        body.insert("customer".into(), json!(customer_id));
        body.insert("payment_method".into(), json!(payment_method_id));
        body.insert("capture_method".into(), json!("manual"));
        body.insert("off_session".into(), json!("true"));
        body.insert("confirm".into(), json!("true"));
        self.execute("POST", "/payment_intents", merge(body, additional))
    }

    fn capture(
        &self,
        payment_id: &str,
        amount: Option<i64>,
        additional: Value,
    ) -> Result<Value, PayError> {
        let mut body = Map::new();
        if let Some(amount) = amount {
            body.insert("amount_to_capture".into(), json!(amount));
        }
        self.execute(
            "POST",
            &format!("/payment_intents/{payment_id}/capture"),
            merge(body, additional),
        )
    }

    fn cancel_authorization(&self, payment_id: &str, additional: Value) -> Result<Value, PayError> {
        self.execute(
            "POST",
            &format!("/payment_intents/{payment_id}/cancel"),
            additional,
        )
    }

    fn update_payment(
        &self,
        payment_id: &str,
        payment_method_id: Option<&str>,
        amount: Option<i64>,
        currency: Option<&str>,
        additional: Value,
    ) -> Result<Value, PayError> {
        let mut body = Map::new();
        if payment_method_id.is_some_and(|s| s != "0") && payment_method_id.is_some() {
            body.insert("payment_method".into(), json!(payment_method_id));
        }
        if let Some(amount) = amount {
            if amount != 0 {
                body.insert("amount".into(), json!(amount));
            }
        }
        if currency.is_some_and(|s| s != "0") && currency.is_some() {
            body.insert("currency".into(), json!(currency));
        }
        self.execute(
            "POST",
            &format!("/payment_intents/{payment_id}"),
            merge(body, additional),
        )
    }

    fn retry_purchase(
        &self,
        payment_id: &str,
        payment_method_id: Option<&str>,
        additional: Value,
    ) -> Result<Value, PayError> {
        let mut body = Map::new();
        if !php_empty_str(payment_method_id) {
            body.insert("payment_method".into(), json!(payment_method_id));
        }
        self.execute(
            "POST",
            &format!("/payment_intents/{payment_id}/confirm"),
            merge(body, additional),
        )
    }

    fn refund(
        &self,
        payment_id: &str,
        amount: Option<i64>,
        reason: Option<&str>,
    ) -> Result<Value, PayError> {
        let mut body = Map::new();
        body.insert("payment_intent".into(), json!(payment_id));
        if let Some(amount) = amount {
            if amount != 0 {
                body.insert("amount".into(), json!(amount));
            }
        }
        if reason.is_some_and(|s| s != "0") && reason.is_some() {
            body.insert("reason".into(), json!(reason));
        }
        self.execute("POST", "/refunds", Value::Object(body))
    }

    fn get_payment(&self, payment_id: &str) -> Result<Value, PayError> {
        self.execute("GET", &format!("/payment_intents/{payment_id}"), json!({}))
    }

    fn create_payment_method(
        &self,
        customer_id: &str,
        r#type: &str,
        details: Value,
    ) -> Result<Value, PayError> {
        let mut body = Map::new();
        body.insert("type".into(), json!(r#type));
        body.insert(r#type.into(), details);
        let payment_method = self.execute("POST", "/payment_methods", Value::Object(body))?;
        let payment_method_id = payment_method
            .get("id")
            .and_then(Value::as_str)
            .unwrap_or_default();
        self.execute(
            "POST",
            &format!("/payment_methods/{payment_method_id}/attach"),
            json!({ "customer": customer_id }),
        )
    }

    fn update_payment_method_billing_details(
        &self,
        payment_method_id: &str,
        name: Option<&str>,
        email: Option<&str>,
        phone: Option<&str>,
        address: Option<Value>,
    ) -> Result<Value, PayError> {
        let mut billing = Map::new();
        if !php_empty_str(name) {
            billing.insert("name".into(), json!(name));
        }
        if !php_empty_str(email) {
            billing.insert("email".into(), json!(email));
        }
        if !php_empty_str(phone) {
            billing.insert("phone".into(), json!(phone));
        }
        if let Some(address) = address {
            billing.insert("address".into(), address);
        }
        self.execute(
            "POST",
            &format!("/payment_methods/{payment_method_id}"),
            json!({ "billing_details": billing }),
        )
    }

    fn update_payment_method(
        &self,
        payment_method_id: &str,
        r#type: &str,
        details: Value,
    ) -> Result<Value, PayError> {
        self.execute(
            "POST",
            &format!("/payment_methods/{payment_method_id}"),
            json!({ r#type: details }),
        )
    }

    fn list_payment_methods(&self, customer_id: &str) -> Result<Value, PayError> {
        self.execute(
            "GET",
            &format!("/customers/{customer_id}/payment_methods"),
            json!({}),
        )
    }

    fn delete_payment_method(&self, payment_method_id: &str) -> Result<bool, PayError> {
        self.execute(
            "POST",
            &format!("/payment_methods/{payment_method_id}/detach"),
            json!({}),
        )?;
        Ok(true)
    }

    fn create_customer(
        &self,
        name: &str,
        email: &str,
        address: Value,
        payment_method: Option<&str>,
    ) -> Result<Value, PayError> {
        let mut body = Map::new();
        body.insert("name".into(), json!(name));
        body.insert("email".into(), json!(email));
        if !php_empty_str(payment_method) {
            body.insert("payment_method".into(), json!(payment_method));
        }
        let address_empty = match &address {
            Value::Null => true,
            Value::Array(a) => a.is_empty(),
            Value::Object(o) => o.is_empty(),
            _ => false,
        };
        if !address_empty {
            body.insert("address".into(), address);
        }
        self.execute("POST", "/customers", Value::Object(body))
    }

    fn list_customers(&self) -> Result<Value, PayError> {
        self.execute("GET", "/customers", json!({}))
    }

    fn get_customer(&self, customer_id: &str) -> Result<Value, PayError> {
        self.execute("GET", &format!("/customers/{customer_id}"), json!({}))
    }

    fn update_customer(
        &self,
        customer_id: &str,
        name: &str,
        email: &str,
        address: Option<&Address>,
        payment_method: Option<&str>,
    ) -> Result<Value, PayError> {
        let mut body = Map::new();
        body.insert("name".into(), json!(name));
        body.insert("email".into(), json!(email));
        if !php_empty_str(payment_method) {
            body.insert("payment_method".into(), json!(payment_method));
        }
        if let Some(address) = address {
            body.insert("address".into(), Value::Object(address.as_array()));
        }
        self.execute(
            "POST",
            &format!("/customers/{customer_id}"),
            Value::Object(body),
        )
    }

    fn delete_customer(&self, customer_id: &str) -> Result<bool, PayError> {
        let result = self.execute("DELETE", &format!("/customers/{customer_id}"), json!({}))?;
        Ok(result
            .get("deleted")
            .and_then(Value::as_bool)
            .unwrap_or(false))
    }

    fn get_payment_method(
        &self,
        customer_id: &str,
        payment_method_id: &str,
    ) -> Result<Value, PayError> {
        self.execute(
            "GET",
            &format!("/customers/{customer_id}/payment_methods/{payment_method_id}"),
            json!({}),
        )
    }

    fn create_future_payment(
        &self,
        customer_id: &str,
        payment_method: Option<&str>,
        payment_method_types: Value,
        payment_method_options: Value,
        payment_method_configuration: Option<&str>,
    ) -> Result<Value, PayError> {
        let types = if payment_method_types.is_null() {
            json!(["card"])
        } else {
            payment_method_types
        };
        let mut body = Map::new();
        body.insert("customer".into(), json!(customer_id));
        body.insert("payment_method_types".into(), types);
        if payment_method.is_some() {
            body.insert("payment_method".into(), json!(payment_method));
        }
        if payment_method_configuration.is_some() {
            body.insert(
                "payment_method_configuration".into(),
                json!(payment_method_configuration),
            );
            body.insert(
                "automatic_payment_methods".into(),
                json!({ "enabled": "true" }),
            );
            body.remove("payment_method_types");
        }
        if let Value::Object(ref o) = payment_method_options {
            if !o.is_empty() {
                body.insert("payment_method_options".into(), payment_method_options);
            }
        } else if matches!(&payment_method_options, Value::Array(a) if !a.is_empty()) {
            body.insert("payment_method_options".into(), payment_method_options);
        }
        self.execute("POST", "/setup_intents", Value::Object(body))
    }

    fn get_future_payment(&self, id: &str) -> Result<Value, PayError> {
        self.execute("GET", &format!("/setup_intents/{id}"), json!({}))
    }

    fn list_future_payments(
        &self,
        customer_id: Option<&str>,
        payment_method_id: Option<&str>,
    ) -> Result<Value, PayError> {
        let mut body = Map::new();
        if customer_id.is_some() {
            body.insert("customer".into(), json!(customer_id));
        }
        if payment_method_id.is_some() {
            body.insert("payment_method".into(), json!(payment_method_id));
        }
        let result = self.execute("GET", "/setup_intents", Value::Object(body))?;
        Ok(result.get("data").cloned().unwrap_or(json!([])))
    }

    fn update_future_payment(
        &self,
        id: &str,
        customer_id: Option<&str>,
        payment_method: Option<&str>,
        payment_method_options: Value,
        payment_method_configuration: Option<&str>,
    ) -> Result<Value, PayError> {
        let mut body = Map::new();
        if customer_id.is_some() {
            body.insert("customer".into(), json!(customer_id));
        }
        if payment_method.is_some() {
            body.insert("payment_method".into(), json!(payment_method));
        }
        if payment_method_configuration.is_some() {
            body.insert(
                "payment_method_configuration".into(),
                json!(payment_method_configuration),
            );
        }
        if let Value::Object(ref o) = payment_method_options {
            if !o.is_empty() {
                body.insert("payment_method_options".into(), payment_method_options);
            }
        }
        self.execute("POST", &format!("/setup_intents/{id}"), Value::Object(body))
    }

    fn get_mandate(&self, id: &str) -> Result<Value, PayError> {
        self.execute("GET", &format!("/mandates/{id}"), json!({}))
    }

    fn list_disputes(
        &self,
        limit: Option<i64>,
        payment_intent_id: Option<&str>,
        charge_id: Option<&str>,
        created_after: Option<i64>,
    ) -> Result<Value, PayError> {
        let mut body = Map::new();
        if let Some(limit) = limit {
            body.insert("limit".into(), json!(limit));
        }
        if let Some(id) = payment_intent_id {
            body.insert("payment_intent".into(), json!(id));
        }
        if let Some(id) = charge_id {
            body.insert("charge".into(), json!(id));
        }
        if let Some(ts) = created_after {
            body.insert("created".into(), json!({ "gte": ts }));
        }
        let result = self.execute("GET", "/disputes", Value::Object(body))?;
        Ok(result.get("data").cloned().unwrap_or(json!([])))
    }
}
