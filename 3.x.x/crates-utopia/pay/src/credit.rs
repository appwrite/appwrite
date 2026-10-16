use serde_json::{json, Map, Value};

/// PHP `Utopia\Pay\Credit\Credit`.
#[derive(Debug, Clone, PartialEq)]
pub struct Credit {
    id: String,
    credits: f64,
    credits_used: f64,
    status: String,
}

impl Credit {
    pub const STATUS_ACTIVE: &'static str = "active";
    pub const STATUS_APPLIED: &'static str = "applied";
    pub const STATUS_EXPIRED: &'static str = "expired";

    #[must_use]
    pub fn new(id: impl Into<String>, credits: f64) -> Self {
        Self::with_status(id, credits, 0.0, Self::STATUS_ACTIVE)
    }

    #[must_use]
    pub fn with_status(
        id: impl Into<String>,
        credits: f64,
        credits_used: f64,
        status: impl Into<String>,
    ) -> Self {
        Self {
            id: id.into(),
            credits,
            credits_used,
            status: status.into(),
        }
    }

    #[must_use]
    pub fn get_status(&self) -> &str {
        &self.status
    }

    pub fn mark_as_applied(&mut self) -> &mut Self {
        self.status = Self::STATUS_APPLIED.into();
        self
    }

    #[must_use]
    pub fn get_id(&self) -> &str {
        &self.id
    }

    pub fn set_id(&mut self, id: impl Into<String>) -> &mut Self {
        self.id = id.into();
        self
    }

    #[must_use]
    pub fn get_credits(&self) -> f64 {
        self.credits
    }

    pub fn set_credits(&mut self, credits: f64) -> &mut Self {
        self.credits = credits;
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
    pub fn has_available_credits(&self) -> bool {
        self.credits > 0.0
    }

    pub fn use_credits(&mut self, amount: f64) -> f64 {
        if amount <= 0.0 {
            return 0.0;
        }
        if self.credits <= 0.0 {
            self.status = Self::STATUS_APPLIED.into();
            return amount;
        }
        let credits_to_use = amount.min(self.credits);
        self.credits -= credits_to_use;
        self.credits_used += credits_to_use;
        if (self.credits - 0.0).abs() < f64::EPSILON {
            self.status = Self::STATUS_APPLIED.into();
        }
        credits_to_use
    }

    pub fn set_status(&mut self, status: impl Into<String>) -> &mut Self {
        self.status = status.into();
        self
    }

    #[must_use]
    pub fn is_fully_used(&self) -> bool {
        (self.credits - 0.0).abs() < f64::EPSILON || self.status == Self::STATUS_APPLIED
    }

    pub fn from_array(data: &Map<String, Value>) -> Self {
        let id = data
            .get("id")
            .or_else(|| data.get("$id"))
            .and_then(Value::as_str)
            .map_or_else(|| format!("credit_{}", uniq()), str::to_owned);
        let credits = data.get("credits").and_then(Value::as_f64).unwrap_or(0.0);
        let credits_used = data
            .get("creditsUsed")
            .and_then(Value::as_f64)
            .unwrap_or(0.0);
        let status = data
            .get("status")
            .and_then(Value::as_str)
            .unwrap_or(Self::STATUS_ACTIVE)
            .to_owned();
        Self::with_status(id, credits, credits_used, status)
    }

    #[must_use]
    pub fn to_array(&self) -> Map<String, Value> {
        let mut m = Map::new();
        m.insert("id".into(), json!(self.id));
        m.insert("credits".into(), json!(self.credits));
        m.insert("creditsUsed".into(), json!(self.credits_used));
        m.insert("status".into(), json!(self.status));
        m
    }
}

fn uniq() -> u64 {
    std::time::SystemTime::now()
        .duration_since(std::time::UNIX_EPOCH)
        .map(|d| d.as_nanos() as u64)
        .unwrap_or(0)
}

impl From<&Map<String, Value>> for Credit {
    fn from(value: &Map<String, Value>) -> Self {
        Self::from_array(value)
    }
}
