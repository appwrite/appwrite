use crate::{Validator, ValueType};
use regex::Regex;
use serde_json::Value;
use std::sync::OnceLock;

fn id_re() -> &'static Regex {
    static RE: OnceLock<Regex> = OnceLock::new();
    RE.get_or_init(|| Regex::new(r"^[a-zA-Z0-9][a-zA-Z0-9._-]{0,35}$").unwrap())
}

#[derive(Debug, Clone, Default)]
pub struct Identifier;

impl Validator for Identifier {
    fn description(&self) -> String {
        "Value must be a valid identifier".into()
    }

    fn value_type(&self) -> ValueType {
        ValueType::String
    }

    fn is_valid(&self, value: &Value) -> bool {
        value.as_str().is_some_and(|s| id_re().is_match(s))
    }
}
