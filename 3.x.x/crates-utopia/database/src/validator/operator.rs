//! PHP `Utopia\Database\Validator\Operator`.

use parking_lot::Mutex;
use serde_json::Value;
use utopia_validators::{Validator, ValueType};

use crate::constants::{
    MAX_INT, MIN_INT, RELATION_MANY_TO_MANY, RELATION_MANY_TO_ONE, RELATION_ONE_TO_MANY,
    RELATION_SIDE_CHILD, RELATION_SIDE_PARENT, VAR_FLOAT, VAR_INTEGER, VAR_RELATIONSHIP,
    VAR_STRING,
};
use crate::document::Document;
use crate::operator::{
    Operator as DatabaseOperator, MAX_ARRAY_OPERATOR_SIZE, TYPE_ARRAY_APPEND, TYPE_ARRAY_DIFF,
    TYPE_ARRAY_FILTER, TYPE_ARRAY_INSERT, TYPE_ARRAY_INTERSECT, TYPE_ARRAY_PREPEND,
    TYPE_ARRAY_REMOVE, TYPE_ARRAY_UNIQUE, TYPE_DATE_ADD_DAYS, TYPE_DATE_SET_NOW,
    TYPE_DATE_SUB_DAYS, TYPE_DECREMENT, TYPE_DIVIDE, TYPE_INCREMENT, TYPE_MODULO, TYPE_MULTIPLY,
    TYPE_POWER, TYPE_STRING_CONCAT, TYPE_STRING_REPLACE, TYPE_TOGGLE,
};
use crate::value::AttrValue;

/// PHP `Utopia\Database\Validator\Operator`.
#[derive(Debug)]
pub struct OperatorValidator {
    attributes: indexmap::IndexMap<String, Document>,
    current_document: Option<Document>,
    message: Mutex<String>,
}

impl OperatorValidator {
    pub fn new(collection: Document, current_document: Option<Document>) -> Self {
        let mut attributes = indexmap::IndexMap::new();
        if let AttrValue::Array(items) = collection.get_attribute("attributes") {
            for value in items.values() {
                if let AttrValue::Document(doc) = value {
                    let key = match doc.get_attribute("key") {
                        AttrValue::String(s) if !s.is_empty() => s.clone(),
                        _ => doc.get_id(),
                    };
                    attributes.insert(key, (**doc).clone());
                }
            }
        }
        Self {
            attributes,
            current_document,
            message: Mutex::new("Invalid operator".into()),
        }
    }

    fn set_message(&self, message: impl Into<String>) {
        *self.message.lock() = message.into();
    }

    pub fn is_valid_operator(&self, value: &DatabaseOperator) -> bool {
        if !DatabaseOperator::is_method(value.get_method()) {
            self.set_message(format!("Invalid operator method: {}", value.get_method()));
            return false;
        }
        let Some(attribute) = self.attributes.get(value.get_attribute()) else {
            self.set_message(format!(
                "Attribute '{}' does not exist in collection",
                value.get_attribute()
            ));
            return false;
        };
        self.validate_for_attribute(value, attribute)
    }

    fn is_relationship_array(&self, attribute: &Document) -> bool {
        let options = match attribute.get_attribute("options") {
            AttrValue::Array(m) => m.clone(),
            AttrValue::Document(d) => d.as_map().clone(),
            _ => return false,
        };
        let relation_type = options
            .get("relationType")
            .and_then(AttrValue::as_str)
            .unwrap_or("");
        let side = options
            .get("side")
            .and_then(AttrValue::as_str)
            .unwrap_or("");
        relation_type == RELATION_MANY_TO_MANY
            || (relation_type == RELATION_ONE_TO_MANY && side == RELATION_SIDE_PARENT)
            || (relation_type == RELATION_MANY_TO_ONE && side == RELATION_SIDE_CHILD)
    }

    fn validate_for_attribute(&self, operator: &DatabaseOperator, attribute: &Document) -> bool {
        let method = operator.get_method();
        let values = operator.get_values();
        let type_ = attribute.get_attribute("type").as_str().unwrap_or("");
        let is_array = attribute.get_attribute("array").as_bool().unwrap_or(false);
        if matches!(
            method,
            TYPE_ARRAY_APPEND
                | TYPE_ARRAY_PREPEND
                | TYPE_ARRAY_INTERSECT
                | TYPE_ARRAY_DIFF
                | TYPE_ARRAY_REMOVE
        ) {
            let payload_len = match values.first() {
                Some(AttrValue::Array(a)) => a.len(),
                _ => values.len(),
            };
            if payload_len > MAX_ARRAY_OPERATOR_SIZE {
                self.set_message(format!(
                    "Array size {payload_len} exceeds maximum allowed size of {MAX_ARRAY_OPERATOR_SIZE} for array operations"
                ));
                return false;
            }
        }
        match method {
            TYPE_INCREMENT | TYPE_DECREMENT | TYPE_MULTIPLY | TYPE_DIVIDE | TYPE_MODULO
            | TYPE_POWER => {
                if type_ != VAR_INTEGER && type_ != VAR_FLOAT {
                    self.set_message(format!(
                        "Cannot apply {method} operator to non-numeric field '{}'",
                        operator.get_attribute()
                    ));
                    return false;
                }
                if values.first().and_then(AttrValue::as_f64).is_none() {
                    self.set_message(format!(
                        "Cannot apply {method} operator: value must be numeric, got {}",
                        crate::value::php_gettype_attr(operator.get_value())
                    ));
                    return false;
                }
                if matches!(method, TYPE_DIVIDE | TYPE_MODULO)
                    && values.first().and_then(AttrValue::as_f64) == Some(0.0)
                {
                    let word = if method == TYPE_DIVIDE {
                        "division"
                    } else {
                        "modulo"
                    };
                    self.set_message(format!("Cannot apply {method} operator: {word} by zero"));
                    return false;
                }
            }
            TYPE_ARRAY_APPEND | TYPE_ARRAY_PREPEND | TYPE_ARRAY_UNIQUE | TYPE_ARRAY_REMOVE
            | TYPE_ARRAY_INTERSECT | TYPE_ARRAY_DIFF | TYPE_ARRAY_FILTER | TYPE_ARRAY_INSERT => {
                if type_ == VAR_RELATIONSHIP {
                    if !self.is_relationship_array(attribute) {
                        self.set_message(format!(
                            "Cannot apply {method} operator to single-value relationship '{}'",
                            operator.get_attribute()
                        ));
                        return false;
                    }
                } else if !is_array {
                    self.set_message(format!(
                        "Cannot apply {method} operator to non-array field '{}'",
                        operator.get_attribute()
                    ));
                    return false;
                }
            }
            TYPE_STRING_CONCAT | TYPE_STRING_REPLACE => {
                if type_ != VAR_STRING && !crate::constants::STRING_TYPES.contains(&type_) {
                    self.set_message(format!(
                        "Cannot apply {method} operator to non-string field '{}'",
                        operator.get_attribute()
                    ));
                    return false;
                }
            }
            TYPE_TOGGLE => {
                if type_ != crate::constants::VAR_BOOLEAN {
                    self.set_message(format!(
                        "Cannot apply {method} operator to non-boolean field '{}'",
                        operator.get_attribute()
                    ));
                    return false;
                }
            }
            TYPE_DATE_ADD_DAYS | TYPE_DATE_SUB_DAYS | TYPE_DATE_SET_NOW => {
                if type_ != crate::constants::VAR_DATETIME {
                    self.set_message(format!(
                        "Cannot apply {method} operator to non-datetime field '{}'",
                        operator.get_attribute()
                    ));
                    return false;
                }
            }
            _ => {}
        }
        let _ = (MAX_INT, MIN_INT, self.current_document.as_ref());
        true
    }
}

impl Validator for OperatorValidator {
    fn description(&self) -> String {
        self.message.lock().clone()
    }
    fn value_type(&self) -> ValueType {
        ValueType::Object
    }
    fn is_valid(&self, value: &Value) -> bool {
        match value.as_object() {
            Some(obj) => DatabaseOperator::parse_operator(obj)
                .map(|op| self.is_valid_operator(&op))
                .unwrap_or(false),
            None => value
                .as_str()
                .and_then(|s| DatabaseOperator::parse(s).ok())
                .is_some_and(|op| self.is_valid_operator(&op)),
        }
    }
}
