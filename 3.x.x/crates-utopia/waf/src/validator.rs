//! Conditions list validator (`Utopia\WAF\Validator\Conditions`).

use crate::attribute::Attribute;
use crate::condition::{Condition, TYPE_AND, TYPE_OR};
use crate::firewall::Firewall;
use crate::AttributeTypes;
use serde_json::Value;
use std::sync::Arc;
use utopia_validators::{Validator, ValueType};

/// Validate an array of at least one WAF condition definition.
#[derive(Debug, Clone)]
pub struct Conditions {
    max_conditions: usize,
    max_payload_length: usize,
    attribute_types: AttributeTypes,
    allowed_attributes: Vec<String>,
    allowed_prefixes: Vec<String>,
}

impl Default for Conditions {
    fn default() -> Self {
        Self::new()
    }
}

impl Conditions {
    /// PHP `Validator::TYPE_ARRAY`.
    pub const TYPE_ARRAY: ValueType = ValueType::Array;

    /// Defaults: `maxConditions = 100`, `maxPayloadLength = 4096`.
    pub fn new() -> Self {
        Self {
            max_conditions: 100,
            max_payload_length: 4096,
            attribute_types: AttributeTypes::new(),
            allowed_attributes: Vec::new(),
            allowed_prefixes: Vec::new(),
        }
    }

    /// Maximum number of (nested) conditions. `0` means unlimited.
    pub fn max_conditions(mut self, max_conditions: usize) -> Self {
        self.max_conditions = max_conditions;
        self
    }

    /// Maximum JSON payload length in bytes. `0` means unlimited.
    pub fn max_payload_length(mut self, max_payload_length: usize) -> Self {
        self.max_payload_length = max_payload_length;
        self
    }

    /// Typed value validation, keyed by attribute name (aliases are normalized).
    pub fn attribute_types(mut self, attribute_types: AttributeTypes) -> Self {
        let mut normalized = AttributeTypes::new();
        for (attribute, type_) in attribute_types {
            normalized.insert(Firewall::normalize_attribute_name(&attribute), type_);
        }
        self.attribute_types = normalized;
        self
    }

    /// Register a single attribute type.
    pub fn attribute_type(mut self, attribute: &str, type_: impl Attribute + 'static) -> Self {
        self.attribute_types.insert(
            Firewall::normalize_attribute_name(attribute),
            Arc::new(type_),
        );
        self
    }

    /// Attribute names conditions may reference. Entries ending with `.` are
    /// prefixes for nested map lookups (e.g. `"headers."`). Empty means any
    /// attribute is accepted.
    pub fn allowed_attributes(
        mut self,
        allowed_attributes: impl IntoIterator<Item = impl Into<String>>,
    ) -> Self {
        self.allowed_attributes.clear();
        self.allowed_prefixes.clear();
        for allowed in allowed_attributes {
            let allowed = allowed.into();
            if allowed.is_empty() {
                continue;
            }
            if allowed.ends_with('.') {
                self.allowed_prefixes.push(allowed.to_ascii_lowercase());
            } else {
                self.allowed_attributes
                    .push(Firewall::normalize_attribute_name(&allowed));
            }
        }
        self
    }

    fn is_valid_condition(&self, payload: &Value, count: &mut usize) -> bool {
        let payload_owned;
        let payload = if let Some(s) = payload.as_str() {
            if self.max_payload_length > 0 && s.len() > self.max_payload_length {
                return false;
            }
            match Condition::decode(s) {
                Ok(condition) => {
                    payload_owned = condition.to_array();
                    &payload_owned
                }
                Err(_) => return false,
            }
        } else if payload.is_object() || payload.is_array() {
            payload
        } else {
            return false;
        };

        *count += 1;
        if self.max_conditions > 0 && *count > self.max_conditions {
            return false;
        }

        if self.max_payload_length > 0 && !self.is_within_payload_limit(payload) {
            return false;
        }

        if !self.has_allowed_attribute(payload) {
            return false;
        }
        if !self.has_valid_typed_values(payload) {
            return false;
        }

        let method = payload.get("method").and_then(Value::as_str).unwrap_or("");
        if method == TYPE_AND || method == TYPE_OR {
            let Some(values) = php_array_values(payload.get("values").unwrap_or(&Value::Null))
            else {
                return false;
            };
            if values.is_empty() {
                return false;
            }
            for value in values {
                if !(value.is_object() || value.is_array()) {
                    return false;
                }
                if !self.is_valid_condition(value, count) {
                    return false;
                }
            }
        }

        Condition::from_array(payload).is_ok()
    }

    fn has_allowed_attribute(&self, payload: &Value) -> bool {
        if self.allowed_attributes.is_empty() && self.allowed_prefixes.is_empty() {
            return true;
        }

        let method = payload.get("method").and_then(Value::as_str).unwrap_or("");
        if method == TYPE_AND || method == TYPE_OR {
            return true;
        }

        let Some(attribute) = payload.get("attribute").and_then(Value::as_str) else {
            return false;
        };
        if attribute.is_empty() {
            return false;
        }

        let normalized = Firewall::normalize_attribute_name(attribute);
        if self.allowed_attributes.iter().any(|a| a == &normalized) {
            return true;
        }

        self.allowed_prefixes
            .iter()
            .any(|prefix| normalized.starts_with(prefix.as_str()) && normalized != *prefix)
    }

    fn has_valid_typed_values(&self, payload: &Value) -> bool {
        if self.attribute_types.is_empty() {
            return true;
        }

        let Some(method) = string_or_skip(payload.get("method")) else {
            return true;
        };
        let Some(attribute) = string_or_skip(payload.get("attribute")) else {
            return true;
        };
        let Some(values) = php_array_values(payload.get("values").unwrap_or(&Value::Null)) else {
            return true;
        };

        if method == TYPE_AND || method == TYPE_OR {
            return true;
        }

        let Some(type_) = self
            .attribute_types
            .get(&Firewall::normalize_attribute_name(attribute))
        else {
            return true;
        };

        values
            .iter()
            .all(|value| type_.validate_value(method, value).is_none())
    }

    fn is_within_payload_limit(&self, payload: &Value) -> bool {
        match serde_json::to_string(payload) {
            Ok(encoded) => encoded.len() <= self.max_payload_length,
            Err(_) => false,
        }
    }
}

impl Validator for Conditions {
    fn description(&self) -> String {
        "Array of at least one WAF condition definition.".into()
    }

    fn is_array(&self) -> bool {
        true
    }

    fn value_type(&self) -> ValueType {
        ValueType::Array
    }

    fn is_valid(&self, value: &Value) -> bool {
        let Some(items) = value.as_array() else {
            return false;
        };
        if items.is_empty() {
            return false;
        }

        let mut count = 0usize;
        items.iter().all(|condition| {
            if !(condition.is_object() || condition.is_string() || condition.is_array()) {
                return false;
            }
            self.is_valid_condition(condition, &mut count)
        })
    }
}

/// PHP `$payload[$key]` string check used by typed-value validation: non-string
/// yields "skip / treat as structurally invalid later".
fn string_or_skip(value: Option<&Value>) -> Option<&str> {
    match value {
        Some(Value::String(s)) => Some(s),
        Some(_) => None,
        None => Some(""),
    }
}

fn php_array_values(value: &Value) -> Option<Vec<&Value>> {
    match value {
        Value::Array(arr) => Some(arr.iter().collect()),
        Value::Object(map) => Some(map.values().collect()),
        Value::Null => Some(Vec::new()),
        _ => None,
    }
}
