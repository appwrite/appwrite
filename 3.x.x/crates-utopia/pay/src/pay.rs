use serde_json::Value;

use crate::adapter::Adapter;
use crate::{Address, PayError};

/// PHP `Utopia\Pay\Pay`.
#[derive(Debug)]
pub struct Pay<A: Adapter> {
    adapter: A,
}

impl<A: Adapter> Pay<A> {
    #[must_use]
    pub fn new(adapter: A) -> Self {
        Self { adapter }
    }

    pub fn set_test_mode(&mut self, test_mode: bool) {
        self.adapter.set_test_mode(test_mode);
    }

    #[must_use]
    pub fn get_test_mode(&self) -> bool {
        self.adapter.get_test_mode()
    }

    #[must_use]
    pub fn get_name(&self) -> &'static str {
        self.adapter.get_name()
    }

    pub fn set_currency(&mut self, currency: impl Into<String>) {
        self.adapter.set_currency(currency.into());
    }

    #[must_use]
    pub fn get_currency(&self) -> &str {
        self.adapter.get_currency()
    }

    pub fn purchase(
        &self,
        amount: i64,
        customer_id: &str,
        payment_method_id: Option<&str>,
        additional: Value,
    ) -> Result<Value, PayError> {
        self.adapter
            .purchase(amount, customer_id, payment_method_id, additional)
    }

    pub fn authorize(
        &self,
        amount: i64,
        customer_id: &str,
        payment_method_id: Option<&str>,
        additional: Value,
    ) -> Result<Value, PayError> {
        self.adapter
            .authorize(amount, customer_id, payment_method_id, additional)
    }

    pub fn capture(
        &self,
        payment_id: &str,
        amount: Option<i64>,
        additional: Value,
    ) -> Result<Value, PayError> {
        self.adapter.capture(payment_id, amount, additional)
    }

    pub fn cancel_authorization(
        &self,
        payment_id: &str,
        additional: Value,
    ) -> Result<Value, PayError> {
        self.adapter.cancel_authorization(payment_id, additional)
    }

    pub fn retry_purchase(
        &self,
        payment_id: &str,
        payment_method_id: Option<&str>,
        additional: Value,
    ) -> Result<Value, PayError> {
        self.adapter
            .retry_purchase(payment_id, payment_method_id, additional)
    }

    pub fn refund(&self, payment_id: &str, amount: i64) -> Result<Value, PayError> {
        self.adapter.refund(payment_id, Some(amount), None)
    }

    pub fn get_payment(&self, payment_id: &str) -> Result<Value, PayError> {
        self.adapter.get_payment(payment_id)
    }

    pub fn update_payment(
        &self,
        payment_id: &str,
        payment_method_id: Option<&str>,
        amount: Option<i64>,
        currency: Option<&str>,
        additional: Value,
    ) -> Result<Value, PayError> {
        self.adapter
            .update_payment(payment_id, payment_method_id, amount, currency, additional)
    }

    pub fn delete_payment_method(&self, payment_method_id: &str) -> Result<bool, PayError> {
        self.adapter.delete_payment_method(payment_method_id)
    }

    pub fn create_payment_method(
        &self,
        customer_id: &str,
        r#type: &str,
        details: Value,
    ) -> Result<Value, PayError> {
        self.adapter
            .create_payment_method(customer_id, r#type, details)
    }

    /// PHP wrapper drops `$type` when calling the adapter.
    pub fn update_payment_method_billing_details(
        &self,
        payment_method_id: &str,
        _type: &str,
        name: Option<&str>,
        email: Option<&str>,
        phone: Option<&str>,
        address: Option<Value>,
    ) -> Result<Value, PayError> {
        self.adapter.update_payment_method_billing_details(
            payment_method_id,
            name,
            email,
            phone,
            address,
        )
    }

    pub fn update_payment_method(
        &self,
        payment_method_id: &str,
        r#type: &str,
        details: Value,
    ) -> Result<Value, PayError> {
        self.adapter
            .update_payment_method(payment_method_id, r#type, details)
    }

    pub fn get_payment_method(
        &self,
        customer_id: &str,
        payment_method_id: &str,
    ) -> Result<Value, PayError> {
        self.adapter
            .get_payment_method(customer_id, payment_method_id)
    }

    pub fn list_payment_methods(&self, customer_id: &str) -> Result<Value, PayError> {
        self.adapter.list_payment_methods(customer_id)
    }

    pub fn list_customers(&self) -> Result<Value, PayError> {
        self.adapter.list_customers()
    }

    pub fn create_customer(
        &self,
        name: &str,
        email: &str,
        address: Value,
        payment_method: Option<&str>,
    ) -> Result<Value, PayError> {
        self.adapter
            .create_customer(name, email, address, payment_method)
    }

    pub fn get_customer(&self, customer_id: &str) -> Result<Value, PayError> {
        self.adapter.get_customer(customer_id)
    }

    pub fn update_customer(
        &self,
        customer_id: &str,
        name: &str,
        email: &str,
        address: Option<&Address>,
        payment_method: Option<&str>,
    ) -> Result<Value, PayError> {
        self.adapter
            .update_customer(customer_id, name, email, address, payment_method)
    }

    pub fn delete_customer(&self, customer_id: &str) -> Result<bool, PayError> {
        self.adapter.delete_customer(customer_id)
    }

    pub fn create_future_payment(
        &self,
        customer_id: &str,
        payment_method: Option<&str>,
        payment_method_types: Value,
        payment_method_options: Value,
        payment_method_configuration: Option<&str>,
    ) -> Result<Value, PayError> {
        self.adapter.create_future_payment(
            customer_id,
            payment_method,
            payment_method_types,
            payment_method_options,
            payment_method_configuration,
        )
    }

    pub fn get_future_payment(&self, id: &str) -> Result<Value, PayError> {
        self.adapter.get_future_payment(id)
    }

    pub fn update_future_payment(
        &self,
        id: &str,
        customer_id: Option<&str>,
        payment_method: Option<&str>,
        payment_method_options: Value,
        payment_method_configuration: Option<&str>,
    ) -> Result<Value, PayError> {
        self.adapter.update_future_payment(
            id,
            customer_id,
            payment_method,
            payment_method_options,
            payment_method_configuration,
        )
    }

    pub fn list_future_payment(
        &self,
        customer_id: Option<&str>,
        payment_method_id: Option<&str>,
    ) -> Result<Value, PayError> {
        self.adapter
            .list_future_payments(customer_id, payment_method_id)
    }

    pub fn get_mandate(&self, id: &str) -> Result<Value, PayError> {
        self.adapter.get_mandate(id)
    }

    pub fn list_disputes(
        &self,
        limit: Option<i64>,
        payment_intent_id: Option<&str>,
        charge_id: Option<&str>,
        created_after: Option<i64>,
    ) -> Result<Value, PayError> {
        self.adapter
            .list_disputes(limit, payment_intent_id, charge_id, created_after)
    }
}
