use crate::{Validator, ValueType};
use regex::Regex;
use serde_json::Value;
use std::sync::OnceLock;

fn hostname_re() -> &'static Regex {
    static RE: OnceLock<Regex> = OnceLock::new();
    RE.get_or_init(|| {
        Regex::new(r"(?i)^(([a-z0-9]|[a-z0-9][a-z0-9\-]*[a-z0-9])\.)*([a-z0-9]|[a-z0-9][a-z0-9\-]*[a-z0-9])$").unwrap()
    })
}

#[derive(Debug, Clone, Default)]
pub struct Hostname {
    allow_local: bool,
}

impl Hostname {
    pub fn new() -> Self {
        Self::default()
    }

    pub fn allow_local(mut self, allow: bool) -> Self {
        self.allow_local = allow;
        self
    }
}

impl Validator for Hostname {
    fn description(&self) -> String {
        "Value must be a valid hostname".into()
    }

    fn value_type(&self) -> ValueType {
        ValueType::String
    }

    fn is_valid(&self, value: &Value) -> bool {
        let Some(s) = value.as_str() else {
            return false;
        };
        if s.is_empty() || s.len() > 253 {
            return false;
        }
        if self.allow_local && (s.eq_ignore_ascii_case("localhost") || !s.contains('.')) {
            return hostname_re().is_match(s);
        }
        hostname_re().is_match(s)
    }
}
