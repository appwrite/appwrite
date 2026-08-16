//! PHP `Utopia\Database\Operator`.

use indexmap::IndexMap;
use serde_json::{Map, Value};

use crate::error::{DatabaseError, Result};
use crate::value::{php_gettype, AttrValue};

pub const TYPE_INCREMENT: &str = "increment";
pub const TYPE_DECREMENT: &str = "decrement";
pub const TYPE_MODULO: &str = "modulo";
pub const TYPE_POWER: &str = "power";
pub const TYPE_MULTIPLY: &str = "multiply";
pub const TYPE_DIVIDE: &str = "divide";
pub const TYPE_ARRAY_APPEND: &str = "arrayAppend";
pub const TYPE_ARRAY_PREPEND: &str = "arrayPrepend";
pub const TYPE_ARRAY_INSERT: &str = "arrayInsert";
pub const TYPE_ARRAY_REMOVE: &str = "arrayRemove";
pub const TYPE_ARRAY_UNIQUE: &str = "arrayUnique";
pub const TYPE_ARRAY_INTERSECT: &str = "arrayIntersect";
pub const TYPE_ARRAY_DIFF: &str = "arrayDiff";
pub const TYPE_ARRAY_FILTER: &str = "arrayFilter";
pub const TYPE_STRING_CONCAT: &str = "stringConcat";
pub const TYPE_STRING_REPLACE: &str = "stringReplace";
pub const TYPE_TOGGLE: &str = "toggle";
pub const TYPE_DATE_ADD_DAYS: &str = "dateAddDays";
pub const TYPE_DATE_SUB_DAYS: &str = "dateSubDays";
pub const TYPE_DATE_SET_NOW: &str = "dateSetNow";

pub const TYPES: &[&str] = &[
    TYPE_INCREMENT,
    TYPE_DECREMENT,
    TYPE_MULTIPLY,
    TYPE_DIVIDE,
    TYPE_MODULO,
    TYPE_POWER,
    TYPE_STRING_CONCAT,
    TYPE_STRING_REPLACE,
    TYPE_ARRAY_APPEND,
    TYPE_ARRAY_PREPEND,
    TYPE_ARRAY_INSERT,
    TYPE_ARRAY_REMOVE,
    TYPE_ARRAY_UNIQUE,
    TYPE_ARRAY_INTERSECT,
    TYPE_ARRAY_DIFF,
    TYPE_ARRAY_FILTER,
    TYPE_TOGGLE,
    TYPE_DATE_ADD_DAYS,
    TYPE_DATE_SUB_DAYS,
    TYPE_DATE_SET_NOW,
];

pub const MAX_ARRAY_OPERATOR_SIZE: usize = 10_000;
pub const ARRAY_FILTER_CONDITIONS: &[&str] = &[
    "equal",
    "notEqual",
    "greaterThan",
    "greaterThanEqual",
    "lessThan",
    "lessThanEqual",
    "isNull",
    "isNotNull",
];

const NUMERIC_TYPES: &[&str] = &[
    TYPE_INCREMENT,
    TYPE_DECREMENT,
    TYPE_MULTIPLY,
    TYPE_DIVIDE,
    TYPE_MODULO,
    TYPE_POWER,
];
const ARRAY_TYPES: &[&str] = &[
    TYPE_ARRAY_APPEND,
    TYPE_ARRAY_PREPEND,
    TYPE_ARRAY_INSERT,
    TYPE_ARRAY_REMOVE,
    TYPE_ARRAY_UNIQUE,
    TYPE_ARRAY_INTERSECT,
    TYPE_ARRAY_DIFF,
    TYPE_ARRAY_FILTER,
];
const STRING_TYPES: &[&str] = &[TYPE_STRING_CONCAT, TYPE_STRING_REPLACE];
const BOOLEAN_TYPES: &[&str] = &[TYPE_TOGGLE];
const DATE_TYPES: &[&str] = &[TYPE_DATE_ADD_DAYS, TYPE_DATE_SUB_DAYS, TYPE_DATE_SET_NOW];

/// PHP `Utopia\Database\Operator`.
#[derive(Debug, Clone)]
pub struct Operator {
    method: String,
    attribute: String,
    values: Vec<AttrValue>,
}

impl Operator {
    #[must_use]
    pub fn new(
        method: impl Into<String>,
        attribute: impl Into<String>,
        values: Vec<AttrValue>,
    ) -> Self {
        Self {
            method: method.into(),
            attribute: attribute.into(),
            values,
        }
    }

    #[must_use]
    pub fn get_method(&self) -> &str {
        &self.method
    }
    #[must_use]
    pub fn get_attribute(&self) -> &str {
        &self.attribute
    }
    #[must_use]
    pub fn get_values(&self) -> &[AttrValue] {
        &self.values
    }
    #[must_use]
    pub fn get_value(&self) -> &AttrValue {
        self.values.first().unwrap_or(&AttrValue::Null)
    }

    /// PHP `getValue(mixed $default = null)`.
    #[must_use]
    pub fn get_value_or<'a>(&'a self, default: &'a AttrValue) -> &'a AttrValue {
        self.values.first().unwrap_or(default)
    }
    pub fn set_method(&mut self, method: impl Into<String>) -> &mut Self {
        self.method = method.into();
        self
    }
    pub fn set_attribute(&mut self, attribute: impl Into<String>) -> &mut Self {
        self.attribute = attribute.into();
        self
    }
    pub fn set_values(&mut self, values: Vec<AttrValue>) -> &mut Self {
        self.values = values;
        self
    }
    pub fn set_value(&mut self, value: impl Into<AttrValue>) -> &mut Self {
        self.values = vec![value.into()];
        self
    }

    #[must_use]
    pub fn is_method(value: &str) -> bool {
        TYPES.contains(&value)
    }
    #[must_use]
    pub fn is_numeric_operation(&self) -> bool {
        NUMERIC_TYPES.contains(&self.method.as_str())
    }
    #[must_use]
    pub fn is_array_operation(&self) -> bool {
        ARRAY_TYPES.contains(&self.method.as_str())
    }
    #[must_use]
    pub fn is_string_operation(&self) -> bool {
        STRING_TYPES.contains(&self.method.as_str())
    }
    #[must_use]
    pub fn is_boolean_operation(&self) -> bool {
        BOOLEAN_TYPES.contains(&self.method.as_str())
    }
    #[must_use]
    pub fn is_date_operation(&self) -> bool {
        DATE_TYPES.contains(&self.method.as_str())
    }

    pub fn parse(operator: &str) -> Result<Self> {
        let decoded: Value = serde_json::from_str(operator)
            .map_err(|e| DatabaseError::operator(format!("Invalid operator: {e}")))?;
        let Value::Object(obj) = decoded else {
            return Err(DatabaseError::operator(format!(
                "Invalid operator. Must be an array, got {}",
                php_gettype(&decoded)
            )));
        };
        Self::parse_operator(&obj)
    }

    pub fn parse_operator(operator: &Map<String, Value>) -> Result<Self> {
        let method_v = operator
            .get("method")
            .cloned()
            .unwrap_or(Value::String(String::new()));
        let Value::String(method) = method_v else {
            return Err(DatabaseError::operator(format!(
                "Invalid operator method. Must be a string, got {}",
                php_gettype(&method_v)
            )));
        };
        if !Self::is_method(&method) {
            return Err(DatabaseError::operator(format!(
                "Invalid operator method: {method}"
            )));
        }
        let attribute_v = operator
            .get("attribute")
            .cloned()
            .unwrap_or(Value::String(String::new()));
        let Value::String(attribute) = attribute_v else {
            return Err(DatabaseError::operator(format!(
                "Invalid operator attribute. Must be a string, got {}",
                php_gettype(&attribute_v)
            )));
        };
        let values_v = operator
            .get("values")
            .cloned()
            .unwrap_or(Value::Array(vec![]));
        let Value::Array(values) = values_v else {
            return Err(DatabaseError::operator(format!(
                "Invalid operator values. Must be an array, got {}",
                php_gettype(&values_v)
            )));
        };
        Ok(Self::new(
            method,
            attribute,
            values.into_iter().map(AttrValue::from_json).collect(),
        ))
    }

    pub fn parse_operators(operators: &[String]) -> Result<Vec<Self>> {
        operators.iter().map(|o| Self::parse(o)).collect()
    }

    #[must_use]
    pub fn to_array(&self) -> Map<String, Value> {
        let mut map = Map::new();
        map.insert("method".into(), Value::String(self.method.clone()));
        map.insert("attribute".into(), Value::String(self.attribute.clone()));
        map.insert(
            "values".into(),
            Value::Array(self.values.iter().map(AttrValue::to_json).collect()),
        );
        map
    }

    #[must_use]
    pub fn to_json_value(&self) -> Value {
        Value::Object(self.to_array())
    }

    pub fn to_string(&self) -> Result<String> {
        serde_json::to_string(&self.to_json_value())
            .map_err(|e| DatabaseError::operator(format!("Invalid Json: {e}")))
    }

    #[must_use]
    pub fn increment(value: f64, max: Option<f64>) -> Self {
        let mut values = vec![num_value(value)];
        if let Some(max) = max {
            values.push(num_value(max));
        }
        Self::new(TYPE_INCREMENT, "", values)
    }
    #[must_use]
    pub fn decrement(value: f64, min: Option<f64>) -> Self {
        let mut values = vec![num_value(value)];
        if let Some(min) = min {
            values.push(num_value(min));
        }
        Self::new(TYPE_DECREMENT, "", values)
    }
    #[must_use]
    pub fn array_append(values: Vec<AttrValue>) -> Self {
        Self::new(TYPE_ARRAY_APPEND, "", values)
    }
    #[must_use]
    pub fn array_prepend(values: Vec<AttrValue>) -> Self {
        Self::new(TYPE_ARRAY_PREPEND, "", values)
    }
    #[must_use]
    pub fn array_insert(index: i64, value: impl Into<AttrValue>) -> Self {
        Self::new(
            TYPE_ARRAY_INSERT,
            "",
            vec![AttrValue::from(index), value.into()],
        )
    }
    #[must_use]
    pub fn array_remove(value: impl Into<AttrValue>) -> Self {
        Self::new(TYPE_ARRAY_REMOVE, "", vec![value.into()])
    }
    #[must_use]
    pub fn string_concat(value: impl Into<AttrValue>) -> Self {
        Self::new(TYPE_STRING_CONCAT, "", vec![value.into()])
    }
    #[must_use]
    pub fn string_replace(search: impl Into<String>, replace: impl Into<String>) -> Self {
        Self::new(
            TYPE_STRING_REPLACE,
            "",
            vec![
                AttrValue::String(search.into()),
                AttrValue::String(replace.into()),
            ],
        )
    }
    #[must_use]
    pub fn multiply(factor: f64, max: Option<f64>) -> Self {
        let mut values = vec![num_value(factor)];
        if let Some(max) = max {
            values.push(num_value(max));
        }
        Self::new(TYPE_MULTIPLY, "", values)
    }
    pub fn divide(divisor: f64, min: Option<f64>) -> Result<Self> {
        if divisor == 0.0 {
            return Err(DatabaseError::operator("Division by zero is not allowed"));
        }
        let mut values = vec![num_value(divisor)];
        if let Some(min) = min {
            values.push(num_value(min));
        }
        Ok(Self::new(TYPE_DIVIDE, "", values))
    }
    #[must_use]
    pub fn toggle() -> Self {
        Self::new(TYPE_TOGGLE, "", vec![])
    }
    #[must_use]
    pub fn date_add_days(days: i64) -> Self {
        Self::new(TYPE_DATE_ADD_DAYS, "", vec![AttrValue::from(days)])
    }
    #[must_use]
    pub fn date_sub_days(days: i64) -> Self {
        Self::new(TYPE_DATE_SUB_DAYS, "", vec![AttrValue::from(days)])
    }
    #[must_use]
    pub fn date_set_now() -> Self {
        Self::new(TYPE_DATE_SET_NOW, "", vec![])
    }
    pub fn modulo(divisor: f64) -> Result<Self> {
        if divisor == 0.0 {
            return Err(DatabaseError::operator("Modulo by zero is not allowed"));
        }
        Ok(Self::new(TYPE_MODULO, "", vec![num_value(divisor)]))
    }
    #[must_use]
    pub fn power(exponent: f64, max: Option<f64>) -> Self {
        let mut values = vec![num_value(exponent)];
        if let Some(max) = max {
            values.push(num_value(max));
        }
        Self::new(TYPE_POWER, "", values)
    }
    #[must_use]
    pub fn array_unique() -> Self {
        Self::new(TYPE_ARRAY_UNIQUE, "", vec![])
    }
    #[must_use]
    pub fn array_intersect(values: Vec<AttrValue>) -> Self {
        Self::new(TYPE_ARRAY_INTERSECT, "", values)
    }
    #[must_use]
    pub fn array_diff(values: Vec<AttrValue>) -> Self {
        Self::new(TYPE_ARRAY_DIFF, "", values)
    }
    #[must_use]
    pub fn array_filter(condition: impl Into<String>, value: impl Into<AttrValue>) -> Self {
        Self::new(
            TYPE_ARRAY_FILTER,
            "",
            vec![AttrValue::String(condition.into()), value.into()],
        )
    }

    #[must_use]
    pub fn is_operator(value: &AttrValue) -> bool {
        matches!(value, AttrValue::Operator(_))
    }

    #[must_use]
    pub fn extract_operators(
        data: IndexMap<String, AttrValue>,
    ) -> (IndexMap<String, Operator>, IndexMap<String, AttrValue>) {
        let mut operators = IndexMap::new();
        let mut updates = IndexMap::new();
        for (key, mut value) in data {
            if let AttrValue::Operator(op) = &mut value {
                if op.get_attribute().is_empty() {
                    op.set_attribute(&key);
                }
                operators.insert(key, *op.clone());
            } else {
                updates.insert(key, value);
            }
        }
        (operators, updates)
    }
}

/// Prefer integers when the PHP helper received a whole number.
fn num_value(value: f64) -> AttrValue {
    if value.fract() == 0.0 && value >= i64::MIN as f64 && value <= i64::MAX as f64 {
        AttrValue::from(value as i64)
    } else {
        AttrValue::from(value)
    }
}
