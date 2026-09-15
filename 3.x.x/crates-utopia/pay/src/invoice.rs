use serde_json::{json, Map, Value};

use crate::{Credit, Discount, PayError};

/// PHP `Utopia\Pay\Invoice\Invoice`.
#[derive(Debug, Clone, PartialEq)]
pub struct Invoice {
    id: String,
    amount: f64,
    status: String,
    currency: String,
    discounts: Vec<Discount>,
    credits: Vec<Credit>,
    address: Map<String, Value>,
    gross_amount: f64,
    tax_amount: f64,
    vat_amount: f64,
    credits_used: f64,
    credits_ids: Vec<String>,
    discount_total: f64,
}

impl Invoice {
    pub const STATUS_PENDING: &'static str = "pending";
    pub const STATUS_DUE: &'static str = "due";
    pub const STATUS_REFUNDED: &'static str = "refunded";
    pub const STATUS_CANCELLED: &'static str = "cancelled";
    pub const STATUS_SUCCEEDED: &'static str = "succeeded";
    pub const STATUS_PROCESSING: &'static str = "processing";
    pub const STATUS_FAILED: &'static str = "failed";

    #[must_use]
    pub fn new(id: impl Into<String>, amount: f64) -> Self {
        Self {
            id: id.into(),
            amount,
            status: Self::STATUS_PENDING.into(),
            currency: "USD".into(),
            discounts: Vec::new(),
            credits: Vec::new(),
            address: Map::new(),
            gross_amount: 0.0,
            tax_amount: 0.0,
            vat_amount: 0.0,
            credits_used: 0.0,
            credits_ids: Vec::new(),
            discount_total: 0.0,
        }
    }

    #[must_use]
    #[allow(clippy::too_many_arguments)]
    pub fn with_details(
        id: impl Into<String>,
        amount: f64,
        status: impl Into<String>,
        currency: impl Into<String>,
        discounts: Vec<Discount>,
        credits: Vec<Credit>,
    ) -> Self {
        let mut invoice = Self::new(id, amount);
        invoice.status = status.into();
        invoice.currency = currency.into();
        invoice.discounts = discounts;
        invoice.credits = credits;
        invoice
    }

    #[must_use]
    pub fn get_id(&self) -> &str {
        &self.id
    }

    #[must_use]
    pub fn get_amount(&self) -> f64 {
        self.amount
    }

    #[must_use]
    pub fn get_currency(&self) -> &str {
        &self.currency
    }

    #[must_use]
    pub fn get_status(&self) -> &str {
        &self.status
    }

    pub fn mark_as_paid(&mut self) -> &mut Self {
        self.mark_as_succeeded()
    }

    #[must_use]
    pub fn get_gross_amount(&self) -> f64 {
        self.gross_amount
    }

    pub fn set_gross_amount(&mut self, gross_amount: f64) -> &mut Self {
        self.gross_amount = gross_amount;
        self
    }

    #[must_use]
    pub fn get_tax_amount(&self) -> f64 {
        self.tax_amount
    }

    pub fn set_tax_amount(&mut self, tax_amount: f64) -> &mut Self {
        self.tax_amount = tax_amount;
        self
    }

    #[must_use]
    pub fn get_vat_amount(&self) -> f64 {
        self.vat_amount
    }

    pub fn set_vat_amount(&mut self, vat_amount: f64) -> &mut Self {
        self.vat_amount = vat_amount;
        self
    }

    #[must_use]
    pub fn get_address(&self) -> &Map<String, Value> {
        &self.address
    }

    pub fn set_address(&mut self, address: Map<String, Value>) -> &mut Self {
        self.address = address;
        self
    }

    #[must_use]
    pub fn get_discounts(&self) -> &[Discount] {
        &self.discounts
    }

    pub fn set_discounts(&mut self, discounts: Vec<Discount>) -> &mut Self {
        self.set_discounts_objects(discounts)
    }

    pub fn set_discounts_objects(&mut self, discounts: Vec<Discount>) -> &mut Self {
        self.discounts = discounts;
        self
    }

    pub fn set_discounts_from_values(
        &mut self,
        discounts: &[Value],
    ) -> Result<&mut Self, PayError> {
        let mut objects = Vec::new();
        for discount in discounts {
            let Value::Object(map) = discount else {
                return Err(PayError::InvalidArgument(
                    "Discount must be either a Discount object or an array".into(),
                ));
            };
            objects.push(Discount::from_array(map)?);
        }
        self.discounts = objects;
        Ok(self)
    }

    pub fn add_discount(&mut self, discount: Discount) -> &mut Self {
        self.discounts.push(discount);
        self
    }

    #[must_use]
    pub fn get_credits_used(&self) -> f64 {
        self.credits_used
    }

    pub fn set_credits_used(&mut self, credits_used: f64) -> &mut Self {
        self.credits_used = credits_used;
        self
    }

    #[must_use]
    pub fn get_credit_internal_ids(&self) -> &[String] {
        &self.credits_ids
    }

    pub fn set_credit_internal_ids(&mut self, credits_ids: Vec<String>) -> &mut Self {
        self.credits_ids = credits_ids;
        self
    }

    pub fn set_status(&mut self, status: impl Into<String>) -> &mut Self {
        self.status = status.into();
        self
    }

    pub fn mark_as_due(&mut self) -> &mut Self {
        self.status = Self::STATUS_DUE.into();
        self
    }

    pub fn mark_as_succeeded(&mut self) -> &mut Self {
        self.status = Self::STATUS_SUCCEEDED.into();
        self
    }

    pub fn mark_as_cancelled(&mut self) -> &mut Self {
        self.status = Self::STATUS_CANCELLED.into();
        self
    }

    #[must_use]
    pub fn is_negative_amount(&self) -> bool {
        self.amount < 0.0
    }

    #[must_use]
    pub fn is_below_minimum_amount(&self, minimum_amount: f64) -> bool {
        self.gross_amount < minimum_amount
    }

    #[must_use]
    pub fn is_zero_amount(&self) -> bool {
        (self.gross_amount - 0.0).abs() < f64::EPSILON
    }

    #[must_use]
    pub fn get_discount_total(&self) -> f64 {
        self.discount_total
    }

    pub fn set_discount_total(&mut self, discount_total: f64) -> &mut Self {
        self.discount_total = discount_total;
        self
    }

    #[must_use]
    pub fn get_discounts_as_array(&self) -> Vec<Map<String, Value>> {
        self.discounts.iter().map(Discount::to_array).collect()
    }

    #[must_use]
    pub fn get_credits(&self) -> &[Credit] {
        &self.credits
    }

    pub fn set_credits(&mut self, credits: Vec<Credit>) -> &mut Self {
        self.set_credits_objects(credits)
    }

    pub fn set_credits_objects(&mut self, credits: Vec<Credit>) -> &mut Self {
        self.credits = credits;
        self
    }

    pub fn set_credits_from_values(&mut self, credits: &[Value]) -> Result<&mut Self, PayError> {
        let mut objects = Vec::new();
        for credit in credits {
            let Value::Object(map) = credit else {
                return Err(PayError::InvalidArgument(
                    "All items in credits array must be Credit objects or arrays with id and credits keys".into(),
                ));
            };
            objects.push(Credit::from_array(map));
        }
        self.credits = objects;
        Ok(self)
    }

    pub fn add_credit(&mut self, credit: Credit) -> &mut Self {
        self.credits.push(credit);
        self
    }

    #[must_use]
    pub fn get_total_available_credits(&self) -> f64 {
        self.credits.iter().map(Credit::get_credits).sum()
    }

    pub fn apply_credits(&mut self) -> &mut Self {
        let mut amount = self.gross_amount;
        let mut total_credits_used = 0.0;
        let mut credits_ids = Vec::new();
        for credit in &mut self.credits {
            if (amount - 0.0).abs() < f64::EPSILON {
                break;
            }
            let credit_to_use = credit.use_credits(amount);
            amount -= credit_to_use;
            total_credits_used += credit_to_use;
            credits_ids.push(credit.get_id().to_owned());
        }
        amount = (amount * 100.0).round() / 100.0;
        self.set_gross_amount(amount);
        self.set_credits_used(total_credits_used);
        self.set_credit_internal_ids(credits_ids);
        self
    }

    pub fn apply_discounts(&mut self) -> &mut Self {
        let mut discounts = self.discounts.clone();
        discounts.sort_by(|a, b| {
            if a.get_type() == Discount::TYPE_FIXED && b.get_type() == Discount::TYPE_PERCENTAGE {
                std::cmp::Ordering::Less
            } else if a.get_type() == Discount::TYPE_PERCENTAGE
                && b.get_type() == Discount::TYPE_FIXED
            {
                std::cmp::Ordering::Greater
            } else {
                std::cmp::Ordering::Equal
            }
        });
        let mut amount = self.gross_amount;
        let mut total_discount = 0.0;
        for discount in &discounts {
            if amount <= 0.0 {
                break;
            }
            let discount_to_use = discount.calculate_discount(amount);
            if discount_to_use <= 0.0 {
                continue;
            }
            amount -= discount_to_use;
            total_discount += discount_to_use;
        }
        amount = (amount * 100.0).round() / 100.0;
        total_discount = (total_discount * 100.0).round() / 100.0;
        self.set_gross_amount(amount);
        self.set_discount_total(total_discount);
        self
    }

    pub fn finalize(&mut self) -> &mut Self {
        self.gross_amount = (self.amount * 100.0).round() / 100.0;
        self.apply_discounts();
        self.tax_amount = (self.tax_amount * 100.0).round() / 100.0;
        self.vat_amount = (self.vat_amount * 100.0).round() / 100.0;
        self.gross_amount += self.tax_amount + self.vat_amount;
        self.apply_credits();
        if self.is_zero_amount() {
            self.mark_as_succeeded();
        } else if self.is_below_minimum_amount(0.50) {
            self.mark_as_cancelled();
        } else {
            self.mark_as_due();
        }
        self
    }

    #[must_use]
    pub fn has_discounts(&self) -> bool {
        !self.discounts.is_empty()
    }

    #[must_use]
    pub fn has_credits(&self) -> bool {
        !self.credits.is_empty()
    }

    #[must_use]
    pub fn get_credits_as_array(&self) -> Vec<Map<String, Value>> {
        self.credits.iter().map(Credit::to_array).collect()
    }

    #[must_use]
    pub fn find_discount_by_id(&self, id: &str) -> Option<&Discount> {
        self.discounts.iter().find(|d| d.get_id() == id)
    }

    #[must_use]
    pub fn find_credit_by_id(&self, id: &str) -> Option<&Credit> {
        self.credits.iter().find(|c| c.get_id() == id)
    }

    pub fn remove_discount_by_id(&mut self, id: &str) -> &mut Self {
        self.discounts.retain(|d| d.get_id() != id);
        self
    }

    pub fn remove_credit_by_id(&mut self, id: &str) -> &mut Self {
        self.credits.retain(|c| c.get_id() != id);
        self
    }

    #[must_use]
    pub fn to_array(&self) -> Map<String, Value> {
        let mut m = Map::new();
        m.insert("id".into(), json!(self.id));
        m.insert("amount".into(), json!(self.amount));
        m.insert("status".into(), json!(self.status));
        m.insert("currency".into(), json!(self.currency));
        m.insert("grossAmount".into(), json!(self.gross_amount));
        m.insert("taxAmount".into(), json!(self.tax_amount));
        m.insert("vatAmount".into(), json!(self.vat_amount));
        m.insert("address".into(), Value::Object(self.address.clone()));
        m.insert(
            "discounts".into(),
            json!(self
                .get_discounts_as_array()
                .into_iter()
                .map(Value::Object)
                .collect::<Vec<_>>()),
        );
        m.insert(
            "credits".into(),
            json!(self
                .get_credits_as_array()
                .into_iter()
                .map(Value::Object)
                .collect::<Vec<_>>()),
        );
        m.insert("creditsUsed".into(), json!(self.credits_used));
        m.insert("creditsIds".into(), json!(self.credits_ids));
        m.insert("discountTotal".into(), json!(self.discount_total));
        m
    }

    pub fn from_array(data: &Map<String, Value>) -> Result<Self, PayError> {
        let id = data
            .get("id")
            .or_else(|| data.get("$id"))
            .and_then(Value::as_str)
            .map_or_else(|| format!("invoice_{}", uniq()), str::to_owned);
        let amount = data.get("amount").and_then(Value::as_f64).unwrap_or(0.0);
        let status = data
            .get("status")
            .and_then(Value::as_str)
            .unwrap_or(Self::STATUS_PENDING)
            .to_owned();
        let currency = data
            .get("currency")
            .and_then(Value::as_str)
            .unwrap_or("USD")
            .to_owned();
        let mut invoice = Self::with_details(id, amount, status, currency, Vec::new(), Vec::new());
        invoice.gross_amount = data
            .get("grossAmount")
            .and_then(Value::as_f64)
            .unwrap_or(0.0);
        invoice.tax_amount = data.get("taxAmount").and_then(Value::as_f64).unwrap_or(0.0);
        invoice.vat_amount = data.get("vatAmount").and_then(Value::as_f64).unwrap_or(0.0);
        if let Some(Value::Object(addr)) = data.get("address") {
            invoice.address.clone_from(addr);
        }
        if let Some(Value::Array(discounts)) = data.get("discounts") {
            invoice.set_discounts_from_values(discounts)?;
        }
        if let Some(Value::Array(credits)) = data.get("credits") {
            invoice.set_credits_from_values(credits)?;
        }
        invoice.credits_used = data
            .get("creditsUsed")
            .and_then(Value::as_f64)
            .unwrap_or(0.0);
        if let Some(Value::Array(ids)) = data.get("creditsIds") {
            invoice.credits_ids = ids
                .iter()
                .filter_map(Value::as_str)
                .map(str::to_owned)
                .collect();
        }
        invoice.discount_total = data
            .get("discountTotal")
            .and_then(Value::as_f64)
            .unwrap_or(0.0);
        Ok(invoice)
    }
}

fn uniq() -> u64 {
    std::time::SystemTime::now()
        .duration_since(std::time::UNIX_EPOCH)
        .map(|d| d.as_nanos() as u64)
        .unwrap_or(0)
}
