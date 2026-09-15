//! Condition evaluation against resolved attributes.

use super::{
    php_compare, php_is_scalar, php_strict_eq, php_stringify_scalar, Condition, TYPE_AND,
    TYPE_BETWEEN, TYPE_CONTAINS, TYPE_ENDS_WITH, TYPE_EQUAL, TYPE_GREATER_THAN,
    TYPE_GREATER_THAN_EQUAL, TYPE_IS_NOT_NULL, TYPE_IS_NULL, TYPE_LESS_THAN, TYPE_LESS_THAN_EQUAL,
    TYPE_NOT_BETWEEN, TYPE_NOT_CONTAINS, TYPE_NOT_ENDS_WITH, TYPE_NOT_EQUAL, TYPE_NOT_STARTS_WITH,
    TYPE_OR, TYPE_STARTS_WITH,
};
use crate::firewall::Firewall;
use crate::AttributeTypes;
use serde_json::{Map, Value};

const JSON_NULL: Value = Value::Null;

impl Condition {
    /// Evaluate against resolved attributes using default (untyped) comparison.
    pub fn matches(&self, attributes: &Map<String, Value>) -> bool {
        self.matches_with(attributes, &AttributeTypes::new())
    }

    /// Evaluate against resolved attributes and typed matching semantics.
    pub fn matches_with(&self, attributes: &Map<String, Value>, types: &AttributeTypes) -> bool {
        if self.is_logical() {
            return self.matches_logical(attributes, types);
        }

        let resolved = resolve_value(&self.attribute, attributes);
        let value = resolved.unwrap_or(&JSON_NULL);
        let type_ = types
            .get(&Firewall::normalize_attribute_name(&self.attribute))
            .map(AsRef::as_ref);

        match self.method.as_str() {
            TYPE_EQUAL => self.matches_equal(value, type_),
            TYPE_NOT_EQUAL => !self.matches_equal(value, type_),
            TYPE_LESS_THAN => self.matches_relational(value, self.values.first(), |r| r < 0, type_),
            TYPE_LESS_THAN_EQUAL => {
                self.matches_relational(value, self.values.first(), |r| r <= 0, type_)
            }
            TYPE_GREATER_THAN => {
                self.matches_relational(value, self.values.first(), |r| r > 0, type_)
            }
            TYPE_GREATER_THAN_EQUAL => {
                self.matches_relational(value, self.values.first(), |r| r >= 0, type_)
            }
            TYPE_CONTAINS => self.matches_contains(value, &self.values, type_),
            TYPE_NOT_CONTAINS => !self.matches_contains(value, &self.values, type_),
            TYPE_BETWEEN => self.matches_range(value, true, type_),
            TYPE_NOT_BETWEEN => !self.matches_range(value, true, type_),
            TYPE_STARTS_WITH => self.matches_prefix(value, type_),
            TYPE_NOT_STARTS_WITH => !self.matches_prefix(value, type_),
            TYPE_ENDS_WITH => self.matches_suffix(value, type_),
            TYPE_NOT_ENDS_WITH => !self.matches_suffix(value, type_),
            TYPE_IS_NULL => value.is_null(),
            TYPE_IS_NOT_NULL => !value.is_null(),
            _ => false,
        }
    }

    fn matches_logical(&self, attributes: &Map<String, Value>, types: &AttributeTypes) -> bool {
        if self.method == TYPE_AND {
            return self
                .children
                .iter()
                .all(|condition| condition.matches_with(attributes, types));
        }
        if self.method == TYPE_OR {
            return self
                .children
                .iter()
                .any(|condition| condition.matches_with(attributes, types));
        }
        false
    }

    fn matches_equal(&self, value: &Value, type_: Option<&dyn crate::Attribute>) -> bool {
        for expected in &self.values {
            let handled = type_.and_then(|t| t.compare(TYPE_EQUAL, value, expected));
            if handled == Some(true) {
                return true;
            }
            if handled == Some(false) {
                continue;
            }

            if let (Value::String(expected_s), Value::String(value_s)) = (expected, value) {
                if expected_s.eq_ignore_ascii_case(value_s) {
                    return true;
                }
                continue;
            }

            if php_strict_eq(expected, value) {
                return true;
            }
        }
        false
    }

    fn matches_contains(
        &self,
        value: &Value,
        needles: &[Value],
        type_: Option<&dyn crate::Attribute>,
    ) -> bool {
        for needle in needles {
            let handled = type_.and_then(|t| t.compare(TYPE_CONTAINS, value, needle));
            if handled == Some(true) {
                return true;
            }
            if handled == Some(false) {
                continue;
            }

            if let Some(items) = php_array_items(value) {
                for item in items {
                    if php_is_scalar(item) && php_is_scalar(needle) {
                        if let (Some(item_s), Some(needle_s)) =
                            (php_stringify_scalar(item), php_stringify_scalar(needle))
                        {
                            if item_s.eq_ignore_ascii_case(&needle_s) {
                                return true;
                            }
                        }
                    }
                }
                continue;
            }

            if let (Value::String(haystack), Value::String(needle_s)) = (value, needle) {
                if !needle_s.is_empty()
                    && haystack
                        .to_ascii_lowercase()
                        .contains(&needle_s.to_ascii_lowercase())
                {
                    return true;
                }
            }
        }
        false
    }

    fn matches_range(
        &self,
        value: &Value,
        inclusive: bool,
        type_: Option<&dyn crate::Attribute>,
    ) -> bool {
        let expected = Value::Array(self.values.clone());
        if let Some(handled) = type_.and_then(|t| t.compare(TYPE_BETWEEN, value, &expected)) {
            return handled;
        }

        if self.values.len() < 2 {
            return false;
        }

        let start = &self.values[0];
        let end = &self.values[1];
        if value.is_null() || start.is_null() || end.is_null() {
            return false;
        }

        let start_comparison = php_compare(value, start);
        let end_comparison = php_compare(value, end);
        let (Some(start_comparison), Some(end_comparison)) = (start_comparison, end_comparison)
        else {
            return false;
        };

        if inclusive {
            start_comparison >= 0 && end_comparison <= 0
        } else {
            start_comparison > 0 && end_comparison < 0
        }
    }

    fn matches_prefix(&self, value: &Value, type_: Option<&dyn crate::Attribute>) -> bool {
        let prefix = self.values.first().unwrap_or(&JSON_NULL);
        if let Some(handled) = type_.and_then(|t| t.compare(TYPE_STARTS_WITH, value, prefix)) {
            return handled;
        }
        match (value, prefix) {
            (Value::String(value), Value::String(prefix)) => value
                .to_ascii_lowercase()
                .starts_with(&prefix.to_ascii_lowercase()),
            _ => false,
        }
    }

    fn matches_suffix(&self, value: &Value, type_: Option<&dyn crate::Attribute>) -> bool {
        let suffix = self.values.first().unwrap_or(&JSON_NULL);
        if let Some(handled) = type_.and_then(|t| t.compare(TYPE_ENDS_WITH, value, suffix)) {
            return handled;
        }
        match (value, suffix) {
            (Value::String(value), Value::String(suffix)) => value
                .to_ascii_lowercase()
                .ends_with(&suffix.to_ascii_lowercase()),
            _ => false,
        }
    }

    fn matches_relational(
        &self,
        value: &Value,
        reference: Option<&Value>,
        verdict: impl Fn(i8) -> bool,
        type_: Option<&dyn crate::Attribute>,
    ) -> bool {
        let reference = reference.unwrap_or(&JSON_NULL);
        if let Some(handled) = type_.and_then(|t| t.compare(&self.method, value, reference)) {
            return handled;
        }
        if value.is_null() || reference.is_null() {
            return false;
        }
        php_compare(value, reference).is_some_and(verdict)
    }
}

fn resolve_value<'a>(attribute: &str, attributes: &'a Map<String, Value>) -> Option<&'a Value> {
    if attribute.is_empty() {
        return None;
    }
    if let Some(value) = attributes.get(attribute) {
        return Some(value);
    }
    if !attribute.contains('.') {
        return None;
    }

    let mut segments = attribute.split('.');
    let first = segments.next()?;
    let mut current = attributes.get(first)?;
    for segment in segments {
        current = match current {
            Value::Object(map) => map.get(segment)?,
            Value::Array(arr) => {
                let index: usize = segment.parse().ok()?;
                arr.get(index)?
            }
            _ => return None,
        };
    }
    Some(current)
}

/// PHP `is_array` iteration: JSON arrays and objects (assoc arrays).
fn php_array_items(value: &Value) -> Option<Vec<&Value>> {
    match value {
        Value::Array(arr) => Some(arr.iter().collect()),
        Value::Object(map) => Some(map.values().collect()),
        _ => None,
    }
}
