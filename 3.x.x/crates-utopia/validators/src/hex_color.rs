use crate::{Validator, ValueType};
use regex::Regex;
use serde_json::Value;
use std::sync::OnceLock;

fn hex_re() -> &'static Regex {
    static RE: OnceLock<Regex> = OnceLock::new();
    RE.get_or_init(|| Regex::new(r"^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$").unwrap())
}

#[derive(Debug, Clone, Default)]
pub struct HexColor;

impl Validator for HexColor {
    fn description(&self) -> String {
        "Value must be a valid hex color code".into()
    }

    fn value_type(&self) -> ValueType {
        ValueType::String
    }

    fn is_valid(&self, value: &Value) -> bool {
        value.as_str().is_some_and(|s| hex_re().is_match(s))
    }
}
