use crate::{Validator, ValueType};
use regex::Regex;
use serde_json::Value;
use std::sync::OnceLock;

fn phone_re() -> &'static Regex {
    static RE: OnceLock<Regex> = OnceLock::new();
    RE.get_or_init(|| Regex::new(r"^\+?[1-9]\d{6,14}$").unwrap())
}

#[derive(Debug, Clone, Default)]
pub struct Phone;

impl Validator for Phone {
    fn description(&self) -> String {
        "Value must be a valid phone number".into()
    }

    fn value_type(&self) -> ValueType {
        ValueType::String
    }

    fn is_valid(&self, value: &Value) -> bool {
        value.as_str().is_some_and(|s| {
            let compact: String = s
                .chars()
                .filter(|c| c.is_ascii_digit() || *c == '+')
                .collect();
            phone_re().is_match(&compact)
        })
    }
}
