//! Audit query. PHP `Utopia\Audit\Query` - thin extension of `Utopia\Query\Query`
//! with lenient single-value factory signatures.

use serde_json::{json, Map, Value};
use utopia_query::method::Method;
use utopia_query::value::QueryValue;

use crate::error::{AuditError, Result};

/// Audit query filter / order / pagination descriptor.
#[derive(Debug, Clone, PartialEq)]
pub struct Query {
    method: String,
    attribute: String,
    values: Vec<QueryValue>,
}

impl Query {
    pub const TYPE_EQUAL: &'static str = "equal";
    pub const TYPE_NOT_EQUAL: &'static str = "notEqual";
    pub const TYPE_LESSER: &'static str = "lessThan";
    pub const TYPE_LESSER_EQUAL: &'static str = "lessThanEqual";
    pub const TYPE_GREATER: &'static str = "greaterThan";
    pub const TYPE_GREATER_EQUAL: &'static str = "greaterThanEqual";
    pub const TYPE_CONTAINS: &'static str = "contains";
    pub const TYPE_CONTAINS_ANY: &'static str = "containsAny";
    pub const TYPE_NOT_CONTAINS: &'static str = "notContains";
    pub const TYPE_SEARCH: &'static str = "search";
    pub const TYPE_NOT_SEARCH: &'static str = "notSearch";
    pub const TYPE_IS_NULL: &'static str = "isNull";
    pub const TYPE_IS_NOT_NULL: &'static str = "isNotNull";
    pub const TYPE_BETWEEN: &'static str = "between";
    pub const TYPE_NOT_BETWEEN: &'static str = "notBetween";
    pub const TYPE_STARTS_WITH: &'static str = "startsWith";
    pub const TYPE_NOT_STARTS_WITH: &'static str = "notStartsWith";
    pub const TYPE_ENDS_WITH: &'static str = "endsWith";
    pub const TYPE_NOT_ENDS_WITH: &'static str = "notEndsWith";
    pub const TYPE_REGEX: &'static str = "regex";
    pub const TYPE_EXISTS: &'static str = "exists";
    pub const TYPE_NOT_EXISTS: &'static str = "notExists";
    pub const TYPE_SELECT: &'static str = "select";
    pub const TYPE_ORDER_DESC: &'static str = "orderDesc";
    pub const TYPE_ORDER_ASC: &'static str = "orderAsc";
    pub const TYPE_ORDER_RANDOM: &'static str = "orderRandom";
    pub const TYPE_LIMIT: &'static str = "limit";
    pub const TYPE_OFFSET: &'static str = "offset";
    pub const TYPE_CURSOR_AFTER: &'static str = "cursorAfter";
    pub const TYPE_CURSOR_BEFORE: &'static str = "cursorBefore";
    pub const TYPE_AND: &'static str = "and";
    pub const TYPE_OR: &'static str = "or";

    #[must_use]
    pub fn new(
        method: impl Into<String>,
        attribute: impl Into<String>,
        values: Vec<QueryValue>,
    ) -> Self {
        let mut attribute = attribute.into();
        let method = method.into();
        if attribute.is_empty()
            && (method == Self::TYPE_ORDER_ASC || method == Self::TYPE_ORDER_DESC)
        {
            "$sequence".clone_into(&mut attribute);
        }
        Self {
            method,
            attribute,
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
    pub fn get_values(&self) -> &[QueryValue] {
        &self.values
    }

    /// PHP `getValue(?mixed $default = null)`.
    #[must_use]
    pub fn get_value(&self) -> QueryValue {
        self.values.first().cloned().unwrap_or(QueryValue::Null)
    }

    #[must_use]
    pub fn get_value_or(&self, default: impl Into<QueryValue>) -> QueryValue {
        self.values
            .first()
            .cloned()
            .unwrap_or_else(|| default.into())
    }

    #[must_use]
    pub fn is_method(value: &str) -> bool {
        Method::try_from_value(value).is_some()
            || matches!(
                value,
                Self::TYPE_EQUAL
                    | Self::TYPE_NOT_EQUAL
                    | Self::TYPE_LESSER
                    | Self::TYPE_LESSER_EQUAL
                    | Self::TYPE_GREATER
                    | Self::TYPE_GREATER_EQUAL
                    | Self::TYPE_CONTAINS
                    | Self::TYPE_NOT_CONTAINS
                    | Self::TYPE_SELECT
                    | Self::TYPE_ORDER_DESC
                    | Self::TYPE_ORDER_ASC
                    | Self::TYPE_ORDER_RANDOM
                    | Self::TYPE_LIMIT
                    | Self::TYPE_OFFSET
                    | Self::TYPE_CURSOR_AFTER
                    | Self::TYPE_CURSOR_BEFORE
                    | Self::TYPE_REGEX
                    | Self::TYPE_IS_NULL
                    | Self::TYPE_IS_NOT_NULL
                    | Self::TYPE_BETWEEN
                    | Self::TYPE_NOT_BETWEEN
                    | Self::TYPE_STARTS_WITH
                    | Self::TYPE_NOT_STARTS_WITH
                    | Self::TYPE_ENDS_WITH
                    | Self::TYPE_NOT_ENDS_WITH
            )
    }

    /// Lenient equal: a single scalar is stored as a one-element values array.
    #[must_use]
    pub fn equal(attribute: impl Into<String>, value: impl Into<QueryValue>) -> Self {
        let value = value.into();
        let values = match value {
            QueryValue::Array(items) => items,
            other => vec![other],
        };
        Self::new(Self::TYPE_EQUAL, attribute, values)
    }

    #[must_use]
    pub fn not_equal(attribute: impl Into<String>, value: impl Into<QueryValue>) -> Self {
        let value = value.into();
        let values = match value {
            QueryValue::Array(items) => items,
            other => vec![other],
        };
        Self::new(Self::TYPE_NOT_EQUAL, attribute, values)
    }

    #[must_use]
    pub fn less_than(attribute: impl Into<String>, value: impl Into<QueryValue>) -> Self {
        Self::new(Self::TYPE_LESSER, attribute, vec![value.into()])
    }

    #[must_use]
    pub fn less_than_equal(attribute: impl Into<String>, value: impl Into<QueryValue>) -> Self {
        Self::new(Self::TYPE_LESSER_EQUAL, attribute, vec![value.into()])
    }

    #[must_use]
    pub fn greater_than(attribute: impl Into<String>, value: impl Into<QueryValue>) -> Self {
        Self::new(Self::TYPE_GREATER, attribute, vec![value.into()])
    }

    #[must_use]
    pub fn greater_than_equal(attribute: impl Into<String>, value: impl Into<QueryValue>) -> Self {
        Self::new(Self::TYPE_GREATER_EQUAL, attribute, vec![value.into()])
    }

    #[must_use]
    pub fn between(
        attribute: impl Into<String>,
        start: impl Into<QueryValue>,
        end: impl Into<QueryValue>,
    ) -> Self {
        Self::new(
            Self::TYPE_BETWEEN,
            attribute,
            vec![start.into(), end.into()],
        )
    }

    #[must_use]
    pub fn not_between(
        attribute: impl Into<String>,
        start: impl Into<QueryValue>,
        end: impl Into<QueryValue>,
    ) -> Self {
        Self::new(
            Self::TYPE_NOT_BETWEEN,
            attribute,
            vec![start.into(), end.into()],
        )
    }

    #[must_use]
    pub fn contains(attribute: impl Into<String>, value: impl Into<QueryValue>) -> Self {
        let value = value.into();
        let values = match value {
            QueryValue::Array(items) => items,
            other => vec![other],
        };
        Self::new(Self::TYPE_CONTAINS, attribute, values)
    }

    #[must_use]
    pub fn not_contains(attribute: impl Into<String>, value: impl Into<QueryValue>) -> Self {
        let value = value.into();
        let values = match value {
            QueryValue::Array(items) => items,
            other => vec![other],
        };
        Self::new(Self::TYPE_NOT_CONTAINS, attribute, values)
    }

    #[must_use]
    pub fn is_null(attribute: impl Into<String>) -> Self {
        Self::new(Self::TYPE_IS_NULL, attribute, Vec::new())
    }

    #[must_use]
    pub fn is_not_null(attribute: impl Into<String>) -> Self {
        Self::new(Self::TYPE_IS_NOT_NULL, attribute, Vec::new())
    }

    #[must_use]
    pub fn starts_with(attribute: impl Into<String>, value: impl Into<QueryValue>) -> Self {
        Self::new(Self::TYPE_STARTS_WITH, attribute, vec![value.into()])
    }

    #[must_use]
    pub fn not_starts_with(attribute: impl Into<String>, value: impl Into<QueryValue>) -> Self {
        Self::new(Self::TYPE_NOT_STARTS_WITH, attribute, vec![value.into()])
    }

    #[must_use]
    pub fn ends_with(attribute: impl Into<String>, value: impl Into<QueryValue>) -> Self {
        Self::new(Self::TYPE_ENDS_WITH, attribute, vec![value.into()])
    }

    #[must_use]
    pub fn not_ends_with(attribute: impl Into<String>, value: impl Into<QueryValue>) -> Self {
        Self::new(Self::TYPE_NOT_ENDS_WITH, attribute, vec![value.into()])
    }

    #[must_use]
    pub fn regex(attribute: impl Into<String>, pattern: impl Into<QueryValue>) -> Self {
        Self::new(Self::TYPE_REGEX, attribute, vec![pattern.into()])
    }

    #[must_use]
    pub fn select(columns: Vec<impl Into<String>>) -> Self {
        let values = columns
            .into_iter()
            .map(|c| QueryValue::String(c.into()))
            .collect();
        Self::new(Self::TYPE_SELECT, "", values)
    }

    #[must_use]
    pub fn order_desc(attribute: impl Into<String>) -> Self {
        Self::new(Self::TYPE_ORDER_DESC, attribute, Vec::new())
    }

    #[must_use]
    pub fn order_asc(attribute: impl Into<String>) -> Self {
        Self::new(Self::TYPE_ORDER_ASC, attribute, Vec::new())
    }

    #[must_use]
    pub fn order_random() -> Self {
        Self::new(Self::TYPE_ORDER_RANDOM, "", Vec::new())
    }

    #[must_use]
    pub fn limit(value: i64) -> Self {
        Self::new(Self::TYPE_LIMIT, "", vec![QueryValue::Int(value)])
    }

    #[must_use]
    pub fn offset(value: i64) -> Self {
        Self::new(Self::TYPE_OFFSET, "", vec![QueryValue::Int(value)])
    }

    #[must_use]
    pub fn cursor_after(value: impl Into<QueryValue>) -> Self {
        Self::new(Self::TYPE_CURSOR_AFTER, "", vec![value.into()])
    }

    #[must_use]
    pub fn cursor_before(value: impl Into<QueryValue>) -> Self {
        Self::new(Self::TYPE_CURSOR_BEFORE, "", vec![value.into()])
    }

    pub fn parse(query: &str) -> Result<Self> {
        let decoded: Value = serde_json::from_str(query)
            .map_err(|e| AuditError::message(format!("Invalid query: {e}")))?;
        if !decoded.is_object() && !decoded.is_array() {
            let got = php_gettype(&decoded);
            return Err(AuditError::message(format!(
                "Invalid query. Must be an array, got {got}"
            )));
        }
        let Value::Object(map) = decoded else {
            return Err(AuditError::message(
                "Invalid query. Must be an array, got array",
            ));
        };
        Self::parse_query(&map)
    }

    pub fn parse_query(query: &Map<String, Value>) -> Result<Self> {
        let method_val = query
            .get("method")
            .cloned()
            .unwrap_or(Value::String(String::new()));
        let attribute_val = query
            .get("attribute")
            .cloned()
            .unwrap_or(Value::String(String::new()));
        let values_val = query.get("values").cloned().unwrap_or(json!([]));

        let method = match method_val {
            Value::String(s) => s,
            other => {
                return Err(AuditError::message(format!(
                    "Invalid query method. Must be a string, got {}",
                    php_gettype(&other)
                )));
            }
        };
        if !Self::is_method(&method) && !method.is_empty() {
            return Err(AuditError::message(format!(
                "Invalid query method: {method}"
            )));
        }
        let attribute = match attribute_val {
            Value::String(s) => s,
            other => {
                return Err(AuditError::message(format!(
                    "Invalid query attribute. Must be a string, got {}",
                    php_gettype(&other)
                )));
            }
        };
        let values = match values_val {
            Value::Array(items) => items
                .into_iter()
                .map(|v| QueryValue::from_json(&v))
                .collect(),
            other => {
                return Err(AuditError::message(format!(
                    "Invalid query values. Must be an array, got {}",
                    php_gettype(&other)
                )));
            }
        };
        Ok(Self::new(method, attribute, values))
    }

    pub fn parse_queries(queries: &[String]) -> Result<Vec<Self>> {
        queries.iter().map(|q| Self::parse(q)).collect()
    }

    pub fn to_string(&self) -> Result<String> {
        serde_json::to_string(&self.to_array()).map_err(|e| AuditError::message(e.to_string()))
    }

    #[must_use]
    pub fn to_array(&self) -> Value {
        json!({
            "method": self.method,
            "attribute": self.attribute,
            "values": self.values.iter().map(QueryValue::to_json).collect::<Vec<_>>(),
        })
    }
}

fn php_gettype(value: &Value) -> &'static str {
    match value {
        Value::Null => "NULL",
        Value::Bool(_) => "boolean",
        Value::Number(n) => {
            if n.is_i64() || n.is_u64() {
                "integer"
            } else {
                "double"
            }
        }
        Value::String(_) => "string",
        Value::Array(_) | Value::Object(_) => "array",
    }
}
