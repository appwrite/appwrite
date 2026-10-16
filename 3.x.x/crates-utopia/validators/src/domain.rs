use crate::{Validator, ValueType};
use regex::Regex;
use serde_json::Value;
use std::sync::OnceLock;

fn domain_re() -> &'static Regex {
    static RE: OnceLock<Regex> = OnceLock::new();
    RE.get_or_init(|| {
        Regex::new(r"(?i)^([a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$").unwrap()
    })
}

#[derive(Debug, Clone, Default)]
pub struct Domain;

impl Validator for Domain {
    fn description(&self) -> String {
        "Value must be a valid domain".into()
    }

    fn value_type(&self) -> ValueType {
        ValueType::String
    }

    fn is_valid(&self, value: &Value) -> bool {
        value.as_str().is_some_and(|s| domain_re().is_match(s))
    }
}
