use crate::attribute::Attribute;
use crate::attributes::Ip;
use crate::rule::{apply_rule, Rule};
use crate::{AttributeTypes, Attributes};
use serde_json::Value;
use std::sync::Arc;

/// Firewall orchestrator (`Utopia\WAF\Firewall`).
///
/// Evaluates registered rules against populated request attributes.
#[derive(Debug)]
pub struct Firewall {
    attributes: Attributes,
    rules: Vec<Arc<dyn Rule>>,
    attribute_types: AttributeTypes,
    last_matched: Option<Arc<dyn Rule>>,
}

impl Default for Firewall {
    fn default() -> Self {
        Self::new()
    }
}

impl Firewall {
    /// Create a firewall with the default `ip` attribute type.
    pub fn new() -> Self {
        let mut attribute_types = AttributeTypes::new();
        attribute_types.insert("ip".into(), Arc::new(Ip));
        Self {
            attributes: Attributes::new(),
            rules: Vec::new(),
            attribute_types,
            last_matched: None,
        }
    }

    /// Register typed matching semantics for an attribute name (aliases normalized).
    pub fn set_attribute_type(
        &mut self,
        attribute: &str,
        type_: impl Attribute + 'static,
    ) -> &mut Self {
        self.attribute_types
            .insert(Self::normalize_attribute_name(attribute), Arc::new(type_));
        self
    }

    /// Typed matching semantics, keyed by normalized attribute name.
    pub fn get_attribute_types(&self) -> &AttributeTypes {
        &self.attribute_types
    }

    /// Normalize an attribute name for type lookup, mirroring the aliasing
    /// applied by [`Self::set_attribute`] (`"requestIp"` and `"IP"` both resolve
    /// to `"ip"`).
    pub fn normalize_attribute_name(name: &str) -> String {
        let mut name = name.to_string();
        if let Some(without_prefix) = strip_request_prefix(&name) {
            if !without_prefix.is_empty() {
                name = without_prefix.to_string();
            }
        }
        name.to_ascii_lowercase()
    }

    /// Store an attribute under its original name plus request/IP aliases.
    pub fn set_attribute(&mut self, name: impl AsRef<str>, value: impl Into<Value>) -> &mut Self {
        let value = value.into();
        for key in attribute_aliases(name.as_ref()) {
            self.attributes.insert(key, value.clone());
        }
        self
    }

    /// Store many attributes.
    pub fn set_attributes(&mut self, attributes: &Attributes) -> &mut Self {
        for (name, value) in attributes {
            self.set_attribute(name, value.clone());
        }
        self
    }

    /// Look up a stored attribute. Missing keys yield `None`; explicit JSON
    /// `null` yields `Some(Null)`.
    pub fn get_attribute(&self, name: &str) -> Option<&Value> {
        self.attributes.get(name)
    }

    /// Look up a stored attribute, returning `default` when the key is absent.
    pub fn get_attribute_or(&self, name: &str, default: Value) -> Value {
        self.attributes.get(name).cloned().unwrap_or(default)
    }

    /// Append a rule.
    pub fn add_rule(&mut self, rule: impl Rule + 'static) -> &mut Self {
        self.rules.push(Arc::new(rule));
        self
    }

    /// Replace the rule list.
    pub fn set_rules(&mut self, rules: Vec<Arc<dyn Rule>>) -> &mut Self {
        self.rules = rules;
        self
    }

    /// Registered rules in evaluation order.
    pub fn get_rules(&self) -> &[Arc<dyn Rule>] {
        &self.rules
    }

    /// Remove all rules. Does not clear [`Self::get_last_matched_rule`].
    pub fn clear_rules(&mut self) -> &mut Self {
        self.rules.clear();
        self
    }

    /// Rule whose conditions matched during the last [`Self::verify`] call.
    pub fn get_last_matched_rule(&self) -> Option<&dyn Rule> {
        self.last_matched.as_deref()
    }

    /// Evaluate registered rules in order against populated attributes.
    ///
    /// Sets the matched rule via [`Self::get_last_matched_rule`] when a rule's
    /// conditions match. Returns whether that rule's action allows the request
    /// to continue (`bypass` / `rateLimit`) or should be blocked
    /// (`deny` / `challenge` / `redirect`). Returns `false` when no rule matches.
    pub fn verify(&mut self) -> bool {
        self.last_matched = None;

        for rule in &self.rules {
            if !rule.matches(&self.attributes, &self.attribute_types) {
                continue;
            }

            self.last_matched = Some(Arc::clone(rule));
            return apply_rule(rule.as_ref());
        }

        false
    }
}

fn attribute_aliases(name: &str) -> Vec<String> {
    let mut aliases = vec![name.to_string()];

    let normalized = normalize_request_key(name);
    if normalized != name {
        aliases.push(normalized.clone());
    }

    let lower = normalized.to_ascii_lowercase();
    if !aliases.iter().any(|alias| alias == &lower) {
        aliases.push(lower);
    }

    aliases
}

fn normalize_request_key(name: &str) -> String {
    if let Some(without_prefix) = strip_request_prefix(name) {
        if !without_prefix.is_empty() {
            return lcfirst(without_prefix);
        }
    }
    name.to_string()
}

fn strip_request_prefix(name: &str) -> Option<&str> {
    if name.len() >= 7 && name[..7].eq_ignore_ascii_case("request") {
        Some(&name[7..])
    } else {
        None
    }
}

fn lcfirst(name: &str) -> String {
    let mut chars = name.chars();
    match chars.next() {
        None => String::new(),
        Some(first) => first.to_ascii_lowercase().to_string() + chars.as_str(),
    }
}
