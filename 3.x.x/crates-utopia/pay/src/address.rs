use serde_json::{json, Map, Value};

/// PHP `Utopia\Pay\Address`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Address {
    city: String,
    country: String,
    line1: Option<String>,
    line2: Option<String>,
    postal_code: Option<String>,
    state: Option<String>,
}

impl Address {
    #[must_use]
    pub fn new(
        city: impl Into<String>,
        country: impl Into<String>,
        line1: Option<String>,
        line2: Option<String>,
        postal_code: Option<String>,
        state: Option<String>,
    ) -> Self {
        Self {
            city: city.into(),
            country: country.into(),
            line1,
            line2,
            postal_code,
            state,
        }
    }

    #[must_use]
    pub fn get_city(&self) -> Option<&str> {
        Some(self.city.as_str())
    }

    pub fn set_city(&mut self, city: impl Into<String>) -> &mut Self {
        self.city = city.into();
        self
    }

    #[must_use]
    pub fn get_country(&self) -> &str {
        &self.country
    }

    pub fn set_country(&mut self, country: impl Into<String>) -> &mut Self {
        self.country = country.into();
        self
    }

    #[must_use]
    pub fn get_line1(&self) -> Option<&str> {
        self.line1.as_deref()
    }

    pub fn set_line1(&mut self, line1: impl Into<String>) -> &mut Self {
        self.line1 = Some(line1.into());
        self
    }

    #[must_use]
    pub fn get_line2(&self) -> Option<&str> {
        self.line2.as_deref()
    }

    pub fn set_line2(&mut self, line2: impl Into<String>) -> &mut Self {
        self.line2 = Some(line2.into());
        self
    }

    #[must_use]
    pub fn get_postal_code(&self) -> Option<&str> {
        self.postal_code.as_deref()
    }

    pub fn set_postal_code(&mut self, postal_code: impl Into<String>) -> &mut Self {
        self.postal_code = Some(postal_code.into());
        self
    }

    #[must_use]
    pub fn get_state(&self) -> Option<&str> {
        self.state.as_deref()
    }

    pub fn set_state(&mut self, state: impl Into<String>) -> &mut Self {
        self.state = Some(state.into());
        self
    }

    /// PHP `asArray()`.
    #[must_use]
    pub fn as_array(&self) -> Map<String, Value> {
        let mut out = Map::new();
        out.insert("city".into(), json!(self.city));
        out.insert("country".into(), json!(self.country));
        out.insert("line1".into(), json!(self.line1));
        out.insert("line2".into(), json!(self.line2));
        out.insert("postal_code".into(), json!(self.postal_code));
        out.insert("state".into(), json!(self.state));
        out
    }
}
