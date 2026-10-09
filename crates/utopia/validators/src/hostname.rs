use serde_json::Value;

use crate::Validator;

/// `Utopia\Validator\Hostname`: hostname matched against an allow list.
///
/// An empty list allows everything; `*` allows everything; `*.example.com`
/// allows any subdomain of `example.com` (but not the apex).
#[derive(Debug, Clone, Default)]
pub struct Hostname {
    pub allow_list: Vec<String>,
}

impl Hostname {
    pub fn new(allow_list: Vec<String>) -> Self {
        Self { allow_list }
    }

    pub fn matches(&self, value: &str) -> bool {
        if value.is_empty() || value == "0" {
            return false;
        }
        if value.chars().count() > 253 || value.contains('/') || value.contains(':') {
            return false;
        }
        if self.allow_list.is_empty() {
            return true;
        }
        for allowed in &self.allow_list {
            if value == allowed || allowed == "*" {
                return true;
            }
            if let Some(suffix) = allowed.strip_prefix('*')
                && value.ends_with(suffix)
            {
                return true;
            }
        }
        false
    }
}

impl Validator for Hostname {
    fn description(&self) -> String {
        "Value must be a valid hostname without path, port and protocol.".to_owned()
    }

    fn is_valid(&self, value: &Value) -> bool {
        match value {
            Value::String(s) => self.matches(s),
            _ => false,
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn wildcard() {
        let h = Hostname::new(vec!["*.appwrite.io".into(), "localhost".into()]);
        assert!(h.matches("cloud.appwrite.io"));
        assert!(!h.matches("appwrite.io"));
        assert!(h.matches("localhost"));
        assert!(!h.matches("localhost:3000"));
    }
}
