//! PHP `Utopia\Pay\Adapter` and `Adapter\Stripe`.

mod stripe;

use serde_json::Value;

use crate::Address;
use crate::PayError;

pub use stripe::Stripe;

/// PHP `Utopia\Pay\Adapter`.
pub trait Adapter {
    fn set_test_mode(&mut self, test_mode: bool);
    fn get_test_mode(&self) -> bool;
    fn get_name(&self) -> &'static str;
    fn set_currency(&mut self, currency: String);
    fn get_currency(&self) -> &str;

    fn purchase(
        &self,
        amount: i64,
        customer_id: &str,
        payment_method_id: Option<&str>,
        additional: Value,
    ) -> Result<Value, PayError>;

    fn authorize(
        &self,
        amount: i64,
        customer_id: &str,
        payment_method_id: Option<&str>,
        additional: Value,
    ) -> Result<Value, PayError>;

    fn capture(
        &self,
        payment_id: &str,
        amount: Option<i64>,
        additional: Value,
    ) -> Result<Value, PayError>;

    fn cancel_authorization(&self, payment_id: &str, additional: Value) -> Result<Value, PayError>;

    fn update_payment(
        &self,
        payment_id: &str,
        payment_method_id: Option<&str>,
        amount: Option<i64>,
        currency: Option<&str>,
        additional: Value,
    ) -> Result<Value, PayError>;

    fn retry_purchase(
        &self,
        payment_id: &str,
        payment_method_id: Option<&str>,
        additional: Value,
    ) -> Result<Value, PayError>;

    fn refund(
        &self,
        payment_id: &str,
        amount: Option<i64>,
        reason: Option<&str>,
    ) -> Result<Value, PayError>;

    fn get_payment(&self, payment_id: &str) -> Result<Value, PayError>;

    fn create_payment_method(
        &self,
        customer_id: &str,
        r#type: &str,
        details: Value,
    ) -> Result<Value, PayError>;

    fn update_payment_method_billing_details(
        &self,
        payment_method_id: &str,
        name: Option<&str>,
        email: Option<&str>,
        phone: Option<&str>,
        address: Option<Value>,
    ) -> Result<Value, PayError>;

    fn update_payment_method(
        &self,
        payment_method_id: &str,
        r#type: &str,
        details: Value,
    ) -> Result<Value, PayError>;

    fn list_payment_methods(&self, customer_id: &str) -> Result<Value, PayError>;

    fn delete_payment_method(&self, payment_method_id: &str) -> Result<bool, PayError>;

    fn create_customer(
        &self,
        name: &str,
        email: &str,
        address: Value,
        payment_method: Option<&str>,
    ) -> Result<Value, PayError>;

    fn list_customers(&self) -> Result<Value, PayError>;

    fn get_customer(&self, customer_id: &str) -> Result<Value, PayError>;

    fn update_customer(
        &self,
        customer_id: &str,
        name: &str,
        email: &str,
        address: Option<&Address>,
        payment_method: Option<&str>,
    ) -> Result<Value, PayError>;

    fn delete_customer(&self, customer_id: &str) -> Result<bool, PayError>;

    fn get_payment_method(
        &self,
        customer_id: &str,
        payment_method_id: &str,
    ) -> Result<Value, PayError>;

    fn create_future_payment(
        &self,
        customer_id: &str,
        payment_method: Option<&str>,
        payment_method_types: Value,
        payment_method_options: Value,
        payment_method_configuration: Option<&str>,
    ) -> Result<Value, PayError>;

    fn list_future_payments(
        &self,
        customer_id: Option<&str>,
        payment_method_id: Option<&str>,
    ) -> Result<Value, PayError>;

    fn get_future_payment(&self, id: &str) -> Result<Value, PayError>;

    fn update_future_payment(
        &self,
        id: &str,
        customer_id: Option<&str>,
        payment_method: Option<&str>,
        payment_method_options: Value,
        payment_method_configuration: Option<&str>,
    ) -> Result<Value, PayError>;

    fn get_mandate(&self, id: &str) -> Result<Value, PayError>;

    fn list_disputes(
        &self,
        limit: Option<i64>,
        payment_intent_id: Option<&str>,
        charge_id: Option<&str>,
        created_after: Option<i64>,
    ) -> Result<Value, PayError>;
}
