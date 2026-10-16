//! WAF condition builder (`Utopia\WAF\Condition`).

mod matches;
mod php;

use crate::error::ConditionError;
use serde_json::{json, Map, Value};

pub(crate) use php::{php_compare, php_is_scalar, php_strict_eq, php_stringify_scalar};

/// Comparison operators.
pub const TYPE_EQUAL: &str = "equal";
pub const TYPE_NOT_EQUAL: &str = "notEqual";
pub const TYPE_LESS_THAN: &str = "lessThan";
pub const TYPE_LESS_THAN_EQUAL: &str = "lessThanEqual";
pub const TYPE_GREATER_THAN: &str = "greaterThan";
pub const TYPE_GREATER_THAN_EQUAL: &str = "greaterThanEqual";
pub const TYPE_BETWEEN: &str = "between";
pub const TYPE_NOT_BETWEEN: &str = "notBetween";

/// String helpers.
pub const TYPE_CONTAINS: &str = "contains";
pub const TYPE_NOT_CONTAINS: &str = "notContains";
pub const TYPE_STARTS_WITH: &str = "startsWith";
pub const TYPE_NOT_STARTS_WITH: &str = "notStartsWith";
pub const TYPE_ENDS_WITH: &str = "endsWith";
pub const TYPE_NOT_ENDS_WITH: &str = "notEndsWith";

/// Null helpers.
pub const TYPE_IS_NULL: &str = "isNull";
pub const TYPE_IS_NOT_NULL: &str = "isNotNull";

/// Logical operators.
pub const TYPE_AND: &str = "and";
pub const TYPE_OR: &str = "or";

const LOGICAL_TYPES: &[&str] = &[TYPE_AND, TYPE_OR];

const TYPES: &[&str] = &[
    TYPE_EQUAL,
    TYPE_NOT_EQUAL,
    TYPE_LESS_THAN,
    TYPE_LESS_THAN_EQUAL,
    TYPE_GREATER_THAN,
    TYPE_GREATER_THAN_EQUAL,
    TYPE_BETWEEN,
    TYPE_NOT_BETWEEN,
    TYPE_CONTAINS,
    TYPE_NOT_CONTAINS,
    TYPE_STARTS_WITH,
    TYPE_NOT_STARTS_WITH,
    TYPE_ENDS_WITH,
    TYPE_NOT_ENDS_WITH,
    TYPE_IS_NULL,
    TYPE_IS_NOT_NULL,
    TYPE_AND,
    TYPE_OR,
];

/// A WAF condition: comparison, string helper, null check, or logical group.
///
/// Inspired by `Utopia\Database\Query` with a pared-down list of operators
/// geared towards WAF rules.
#[derive(Debug, Clone)]
pub struct Condition {
    method: String,
    attribute: String,
    /// Literal operands for non-logical operators.
    values: Vec<Value>,
    /// Nested conditions for `and` / `or`.
    children: Vec<Condition>,
}

impl Condition {
    pub const TYPE_EQUAL: &'static str = TYPE_EQUAL;
    pub const TYPE_NOT_EQUAL: &'static str = TYPE_NOT_EQUAL;
    pub const TYPE_LESS_THAN: &'static str = TYPE_LESS_THAN;
    pub const TYPE_LESS_THAN_EQUAL: &'static str = TYPE_LESS_THAN_EQUAL;
    pub const TYPE_GREATER_THAN: &'static str = TYPE_GREATER_THAN;
    pub const TYPE_GREATER_THAN_EQUAL: &'static str = TYPE_GREATER_THAN_EQUAL;
    pub const TYPE_BETWEEN: &'static str = TYPE_BETWEEN;
    pub const TYPE_NOT_BETWEEN: &'static str = TYPE_NOT_BETWEEN;
    pub const TYPE_CONTAINS: &'static str = TYPE_CONTAINS;
    pub const TYPE_NOT_CONTAINS: &'static str = TYPE_NOT_CONTAINS;
    pub const TYPE_STARTS_WITH: &'static str = TYPE_STARTS_WITH;
    pub const TYPE_NOT_STARTS_WITH: &'static str = TYPE_NOT_STARTS_WITH;
    pub const TYPE_ENDS_WITH: &'static str = TYPE_ENDS_WITH;
    pub const TYPE_NOT_ENDS_WITH: &'static str = TYPE_NOT_ENDS_WITH;
    pub const TYPE_IS_NULL: &'static str = TYPE_IS_NULL;
    pub const TYPE_IS_NOT_NULL: &'static str = TYPE_IS_NOT_NULL;
    pub const TYPE_AND: &'static str = TYPE_AND;
    pub const TYPE_OR: &'static str = TYPE_OR;

    /// Construct a condition. Logical methods convert array-shaped values via [`Self::from_array`].
    pub fn new(
        method: impl Into<String>,
        attribute: impl Into<String>,
        values: Vec<Value>,
    ) -> Result<Self, ConditionError> {
        let method = method.into();
        if !Self::is_method(&method) {
            return Err(ConditionError::UnsupportedMethod(method));
        }
        let attribute = attribute.into();
        if is_logical_method(&method) {
            let children = normalize_logical_values(values)?;
            Ok(Self {
                method,
                attribute,
                values: Vec::new(),
                children,
            })
        } else {
            Ok(Self {
                method,
                attribute,
                values,
                children: Vec::new(),
            })
        }
    }

    /// Operator name (`equal`, `and`, …).
    pub fn get_method(&self) -> &str {
        &self.method
    }

    /// Attribute path (empty for logical conditions).
    pub fn get_attribute(&self) -> &str {
        &self.attribute
    }

    /// Literal operand list (empty for logical conditions).
    pub fn get_values(&self) -> &[Value] {
        &self.values
    }

    /// Nested conditions (`and` / `or`).
    pub fn get_children(&self) -> &[Condition] {
        &self.children
    }

    /// Whether this condition is a logical `and` / `or` group.
    pub fn is_logical(&self) -> bool {
        is_logical_method(&self.method)
    }

    /// Whether `value` is a supported operator name.
    pub fn is_method(value: &str) -> bool {
        TYPES.contains(&value)
    }

    /// Decode a JSON encoded condition string.
    pub fn decode(payload: &str) -> Result<Self, ConditionError> {
        let decoded: Value = serde_json::from_str(payload)
            .map_err(|err| ConditionError::InvalidPayload(err.to_string()))?;
        if !is_php_array(&decoded) {
            return Err(ConditionError::ExpectingArray);
        }
        Self::from_array(&decoded)
    }

    /// Build a condition from an associative array / JSON object definition.
    pub fn from_array(payload: &Value) -> Result<Self, ConditionError> {
        if !is_php_array(payload) {
            return Err(ConditionError::InvalidMethodDefinition);
        }

        let method = string_field(payload, "method")
            .map_err(|()| ConditionError::InvalidMethodDefinition)?;
        let attribute = string_field(payload, "attribute")
            .map_err(|()| ConditionError::InvalidAttributeDefinition)?;
        let values = values_field(payload)?;

        let mut values = values;
        if is_logical_method(&method) {
            values = nested_condition_arrays(values)?;
        }

        Self::new(method, attribute, values)
    }

    /// Build many conditions from array definitions.
    pub fn from_arrays(conditions: &[Value]) -> Result<Vec<Self>, ConditionError> {
        conditions.iter().map(Self::from_array).collect()
    }

    /// Serialize to the PHP `toArray()` shape (JSON object).
    pub fn to_array(&self) -> Value {
        Value::Object(self.to_array_map())
    }

    /// Encode condition as a JSON string.
    pub fn encode(&self) -> Result<String, ConditionError> {
        serde_json::to_string(&self.to_array())
            .map_err(|err| ConditionError::Encode(err.to_string()))
    }

    /// `equal($attribute, $values)`.
    pub fn equal(attribute: impl Into<String>, values: Vec<Value>) -> Self {
        Self::literal(TYPE_EQUAL, attribute, values)
    }

    /// `notEqual($attribute, $value)`.
    pub fn not_equal(attribute: impl Into<String>, value: impl Into<Value>) -> Self {
        Self::literal(TYPE_NOT_EQUAL, attribute, vec![value.into()])
    }

    /// `lessThan($attribute, $value)`.
    pub fn less_than(attribute: impl Into<String>, value: impl Into<Value>) -> Self {
        Self::literal(TYPE_LESS_THAN, attribute, vec![value.into()])
    }

    /// `lessThanEqual($attribute, $value)`.
    pub fn less_than_equal(attribute: impl Into<String>, value: impl Into<Value>) -> Self {
        Self::literal(TYPE_LESS_THAN_EQUAL, attribute, vec![value.into()])
    }

    /// `greaterThan($attribute, $value)`.
    pub fn greater_than(attribute: impl Into<String>, value: impl Into<Value>) -> Self {
        Self::literal(TYPE_GREATER_THAN, attribute, vec![value.into()])
    }

    /// `greaterThanEqual($attribute, $value)`.
    pub fn greater_than_equal(attribute: impl Into<String>, value: impl Into<Value>) -> Self {
        Self::literal(TYPE_GREATER_THAN_EQUAL, attribute, vec![value.into()])
    }

    /// `contains($attribute, $values)`.
    pub fn contains(attribute: impl Into<String>, values: Vec<Value>) -> Self {
        Self::literal(TYPE_CONTAINS, attribute, values)
    }

    /// `notContains($attribute, $values)`.
    pub fn not_contains(attribute: impl Into<String>, values: Vec<Value>) -> Self {
        Self::literal(TYPE_NOT_CONTAINS, attribute, values)
    }

    /// `between($attribute, $start, $end)`.
    pub fn between(
        attribute: impl Into<String>,
        start: impl Into<Value>,
        end: impl Into<Value>,
    ) -> Self {
        Self::literal(TYPE_BETWEEN, attribute, vec![start.into(), end.into()])
    }

    /// `notBetween($attribute, $start, $end)`.
    pub fn not_between(
        attribute: impl Into<String>,
        start: impl Into<Value>,
        end: impl Into<Value>,
    ) -> Self {
        Self::literal(TYPE_NOT_BETWEEN, attribute, vec![start.into(), end.into()])
    }

    /// `startsWith($attribute, $value)`.
    pub fn starts_with(attribute: impl Into<String>, value: impl Into<Value>) -> Self {
        Self::literal(TYPE_STARTS_WITH, attribute, vec![value.into()])
    }

    /// `notStartsWith($attribute, $value)`.
    pub fn not_starts_with(attribute: impl Into<String>, value: impl Into<Value>) -> Self {
        Self::literal(TYPE_NOT_STARTS_WITH, attribute, vec![value.into()])
    }

    /// `endsWith($attribute, $value)`.
    pub fn ends_with(attribute: impl Into<String>, value: impl Into<Value>) -> Self {
        Self::literal(TYPE_ENDS_WITH, attribute, vec![value.into()])
    }

    /// `notEndsWith($attribute, $value)`.
    pub fn not_ends_with(attribute: impl Into<String>, value: impl Into<Value>) -> Self {
        Self::literal(TYPE_NOT_ENDS_WITH, attribute, vec![value.into()])
    }

    /// `isNull($attribute)`.
    pub fn is_null(attribute: impl Into<String>) -> Self {
        Self::literal(TYPE_IS_NULL, attribute, Vec::new())
    }

    /// `isNotNull($attribute)`.
    pub fn is_not_null(attribute: impl Into<String>) -> Self {
        Self::literal(TYPE_IS_NOT_NULL, attribute, Vec::new())
    }

    /// `and($conditions)`.
    pub fn and(conditions: Vec<Self>) -> Self {
        Self::logical(TYPE_AND, conditions)
    }

    /// `or($conditions)`.
    pub fn or(conditions: Vec<Self>) -> Self {
        Self::logical(TYPE_OR, conditions)
    }

    fn literal(method: &str, attribute: impl Into<String>, values: Vec<Value>) -> Self {
        Self {
            method: method.to_string(),
            attribute: attribute.into(),
            values,
            children: Vec::new(),
        }
    }

    fn logical(method: &str, children: Vec<Self>) -> Self {
        Self {
            method: method.to_string(),
            attribute: String::new(),
            values: Vec::new(),
            children,
        }
    }

    fn to_array_map(&self) -> Map<String, Value> {
        let mut result = Map::new();
        result.insert("method".into(), json!(self.method));
        if !self.attribute.is_empty() {
            result.insert("attribute".into(), json!(self.attribute));
        }
        let values = if self.is_logical() {
            Value::Array(self.children.iter().map(Self::to_array).collect())
        } else {
            Value::Array(self.values.clone())
        };
        result.insert("values".into(), values);
        result
    }
}

fn is_logical_method(method: &str) -> bool {
    LOGICAL_TYPES.contains(&method)
}

fn is_php_array(value: &Value) -> bool {
    value.is_array() || value.is_object()
}

fn payload_get<'a>(payload: &'a Value, key: &str) -> Option<&'a Value> {
    payload.as_object().and_then(|map| map.get(key))
}

/// PHP `$payload[$key] ?? ''` with a type check: non-string non-null is an error.
fn string_field(payload: &Value, key: &str) -> Result<String, ()> {
    match payload_get(payload, key) {
        None | Some(Value::Null) => Ok(String::new()),
        Some(Value::String(s)) => Ok(s.clone()),
        Some(_) => Err(()),
    }
}

fn values_field(payload: &Value) -> Result<Vec<Value>, ConditionError> {
    match payload_get(payload, "values") {
        None | Some(Value::Null) => Ok(Vec::new()),
        Some(Value::Array(arr)) => Ok(arr.clone()),
        Some(Value::Object(map)) => Ok(map.values().cloned().collect()),
        Some(_) => Err(ConditionError::InvalidValuesDefinition),
    }
}

fn nested_condition_arrays(values: Vec<Value>) -> Result<Vec<Value>, ConditionError> {
    for value in &values {
        if !is_php_array(value) {
            return Err(ConditionError::InvalidNested);
        }
    }
    Ok(values)
}

fn normalize_logical_values(values: Vec<Value>) -> Result<Vec<Condition>, ConditionError> {
    values
        .into_iter()
        .map(|value| {
            if is_php_array(&value) {
                Condition::from_array(&value)
            } else {
                Err(ConditionError::LogicalRequiresNested)
            }
        })
        .collect()
}
