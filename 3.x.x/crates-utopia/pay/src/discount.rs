use serde_json::{json, Map, Value};

use crate::PayError;

/// PHP `Utopia\Pay\Discount\Discount`.
#[derive(Debug, Clone, PartialEq)]
pub struct Discount {
    id: String,
    value: f64,
    description: String,
    r#type: String,
}

impl Discount {
    pub const TYPE_FIXED: &'static str = "fixed";
    pub const TYPE_PERCENTAGE: &'static str = "percentage";

    pub fn new(
        id: impl Into<String>,
        value: f64,
        description: impl Into<String>,
        r#type: impl Into<String>,
    ) -> Result<Self, PayError> {
        if value < 0.0 {
            return Err(PayError::InvalidArgument(
                "Discount value cannot be negative".into(),
            ));
        }
        Ok(Self {
            id: id.into(),
            value,
            description: description.into(),
            r#type: r#type.into(),
        })
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
    pub fn get_value(&self) -> f64 {
        self.value
    }

    pub fn set_value(&mut self, value: f64) -> Result<&mut Self, PayError> {
        if value < 0.0 {
            return Err(PayError::InvalidArgument(
                "Discount value cannot be negative".into(),
            ));
        }
        self.value = value;
        Ok(self)
    }

    #[must_use]
    pub fn get_description(&self) -> &str {
        &self.description
    }

    pub fn set_description(&mut self, description: impl Into<String>) -> &mut Self {
        self.description = description.into();
        self
    }

    #[must_use]
    pub fn get_type(&self) -> &str {
        &self.r#type
    }

    pub fn set_type(&mut self, r#type: impl Into<String>) -> Result<&mut Self, PayError> {
        let t = r#type.into();
        if t != Self::TYPE_FIXED && t != Self::TYPE_PERCENTAGE {
            return Err(PayError::InvalidArgument(
                "Discount type must be TYPE_FIXED or TYPE_PERCENTAGE".into(),
            ));
        }
        self.r#type = t;
        Ok(self)
    }

    #[must_use]
    pub fn calculate_discount(&self, amount: f64) -> f64 {
        if amount <= 0.0 {
            return 0.0;
        }
        if self.r#type == Self::TYPE_FIXED {
            self.value.min(amount)
        } else if self.r#type == Self::TYPE_PERCENTAGE {
            (self.value / 100.0) * amount
        } else {
            0.0
        }
    }

    #[must_use]
    pub fn to_array(&self) -> Map<String, Value> {
        let mut m = Map::new();
        m.insert("id".into(), json!(self.id));
        m.insert("value".into(), json!(self.value));
        m.insert("description".into(), json!(self.description));
        m.insert("type".into(), json!(self.r#type));
        m
    }

    pub fn from_array(data: &Map<String, Value>) -> Result<Self, PayError> {
        let value = match data.get("value") {
            None | Some(Value::Null) => {
                return Err(PayError::InvalidArgument(
                    "Discount value cannot be null".into(),
                ));
            }
            Some(v) => v.as_f64().unwrap_or(0.0),
        };
        if value < 0.0 {
            return Err(PayError::InvalidArgument(
                "Discount value cannot be negative".into(),
            ));
        }
        let id = data
            .get("id")
            .or_else(|| data.get("$id"))
            .and_then(Value::as_str)
            .unwrap_or("")
            .to_owned();
        let description = data
            .get("description")
            .and_then(Value::as_str)
            .unwrap_or("")
            .to_owned();
        let r#type = data
            .get("type")
            .and_then(Value::as_str)
            .unwrap_or(Self::TYPE_FIXED)
            .to_owned();
        Self::new(id, value, description, r#type)
    }
}
