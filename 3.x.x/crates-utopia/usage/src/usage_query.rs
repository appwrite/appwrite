//! Usage query. PHP `Utopia\Usage\UsageQuery`.

use serde_json::{json, Map, Value};
use utopia_query::method::Method;
use utopia_query::query::Query as BaseQuery;
use utopia_query::value::QueryValue;

use crate::error::{Result, UsageError};

/// Query descriptor including usage-specific `groupByInterval` / `groupBy` / `aggregate`.
#[derive(Debug, Clone, PartialEq)]
pub struct UsageQuery {
    method: String,
    attribute: String,
    values: Vec<QueryValue>,
}

impl UsageQuery {
    pub const TYPE_GROUP_BY_INTERVAL: &'static str = "groupByInterval";
    pub const TYPE_GROUP_BY: &'static str = "groupBy";
    pub const TYPE_AGGREGATE: &'static str = "aggregate";
    pub const VALID_AGGREGATES: &'static [&'static str] = &["max"];
    pub const VALID_INTERVALS: &'static [(&'static str, &'static str)] = &[
        ("1m", "INTERVAL 1 MINUTE"),
        ("5m", "INTERVAL 5 MINUTE"),
        ("15m", "INTERVAL 15 MINUTE"),
        ("30m", "INTERVAL 30 MINUTE"),
        ("1h", "INTERVAL 1 HOUR"),
        ("1d", "INTERVAL 1 DAY"),
        ("1w", "INTERVAL 1 WEEK"),
        ("1M", "INTERVAL 1 MONTH"),
    ];

    #[must_use]
    pub fn new(
        method: impl Into<String>,
        attribute: impl Into<String>,
        values: Vec<QueryValue>,
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
    pub fn get_values(&self) -> &[QueryValue] {
        &self.values
    }
    #[must_use]
    pub fn get_value(&self) -> QueryValue {
        self.values.first().cloned().unwrap_or(QueryValue::Null)
    }

    #[must_use]
    pub fn is_method(value: &str) -> bool {
        matches!(
            value,
            Self::TYPE_GROUP_BY_INTERVAL | Self::TYPE_GROUP_BY | Self::TYPE_AGGREGATE
        ) || Method::try_from_value(value).is_some()
            || BaseQuery::is_method(value)
    }

    pub fn group_by_interval(attribute: impl Into<String>, interval: &str) -> Result<Self> {
        if !Self::VALID_INTERVALS.iter().any(|(k, _)| *k == interval) {
            let allowed: Vec<&str> = Self::VALID_INTERVALS.iter().map(|(k, _)| *k).collect();
            return Err(UsageError::message(format!(
                "Invalid interval '{interval}'. Allowed: {}",
                allowed.join(", ")
            )));
        }
        Ok(Self::new(
            Self::TYPE_GROUP_BY_INTERVAL,
            attribute,
            vec![QueryValue::String(interval.to_owned())],
        ))
    }

    #[must_use]
    pub fn is_group_by_interval(query: &Self) -> bool {
        query.method == Self::TYPE_GROUP_BY_INTERVAL
    }

    #[must_use]
    pub fn extract_group_by_interval(queries: &[Self]) -> Option<&Self> {
        queries
            .iter()
            .find(|q| q.method == Self::TYPE_GROUP_BY_INTERVAL)
    }

    #[must_use]
    pub fn remove_group_by_interval(queries: &[Self]) -> Vec<Self> {
        queries
            .iter()
            .filter(|q| q.method != Self::TYPE_GROUP_BY_INTERVAL)
            .cloned()
            .collect()
    }

    #[must_use]
    pub fn group_by(attribute: impl Into<String>) -> Self {
        Self::new(Self::TYPE_GROUP_BY, attribute, Vec::new())
    }

    #[must_use]
    pub fn is_group_by(query: &Self) -> bool {
        query.method == Self::TYPE_GROUP_BY
    }

    #[must_use]
    pub fn extract_group_by(queries: &[Self]) -> Vec<Self> {
        queries
            .iter()
            .filter(|q| q.method == Self::TYPE_GROUP_BY)
            .cloned()
            .collect()
    }

    #[must_use]
    pub fn remove_group_by(queries: &[Self]) -> Vec<Self> {
        queries
            .iter()
            .filter(|q| q.method != Self::TYPE_GROUP_BY)
            .cloned()
            .collect()
    }

    pub fn aggregate(function: &str) -> Result<Self> {
        if !Self::VALID_AGGREGATES.contains(&function) {
            return Err(UsageError::message(format!(
                "Invalid aggregate '{function}'. Allowed: {}",
                Self::VALID_AGGREGATES.join(", ")
            )));
        }
        Ok(Self::new(
            Self::TYPE_AGGREGATE,
            "value",
            vec![QueryValue::String(function.to_owned())],
        ))
    }

    #[must_use]
    pub fn is_aggregate(query: &Self) -> bool {
        query.method == Self::TYPE_AGGREGATE
    }

    #[must_use]
    pub fn extract_aggregate(queries: &[Self]) -> Option<String> {
        queries.iter().find_map(|q| {
            if q.method == Self::TYPE_AGGREGATE {
                q.values
                    .first()
                    .and_then(QueryValue::as_str)
                    .map(str::to_owned)
            } else {
                None
            }
        })
    }

    #[must_use]
    pub fn remove_aggregate(queries: &[Self]) -> Vec<Self> {
        queries
            .iter()
            .filter(|q| q.method != Self::TYPE_AGGREGATE)
            .cloned()
            .collect()
    }

    pub fn parse(query: &str) -> Result<Self> {
        let decoded: Value = serde_json::from_str(query)
            .map_err(|e| UsageError::message(format!("Invalid query: {e}")))?;
        let Value::Object(map) = decoded else {
            return Err(UsageError::message("Invalid query. Must be an array"));
        };
        Self::parse_query(&map)
    }

    pub fn parse_query(query: &Map<String, Value>) -> Result<Self> {
        let method = match query.get("method").cloned().unwrap_or(json!("")) {
            Value::String(s) => s,
            other => {
                return Err(UsageError::message(format!(
                    "Invalid query method. Must be a string, got {}",
                    crate::metric::php_gettype_pub(&other)
                )));
            }
        };
        if !method.is_empty() && !Self::is_method(&method) {
            return Err(UsageError::message(format!(
                "Invalid query method: {method}"
            )));
        }
        let attribute = match query.get("attribute").cloned().unwrap_or(json!("")) {
            Value::String(s) => s,
            Value::Null => String::new(),
            other => {
                return Err(UsageError::message(format!(
                    "Invalid query attribute. Must be a string, got {}",
                    crate::metric::php_gettype_pub(&other)
                )));
            }
        };
        let values = match query.get("values").cloned().unwrap_or(json!([])) {
            Value::Array(items) => items
                .into_iter()
                .map(|v| QueryValue::from_json(&v))
                .collect(),
            Value::Null => Vec::new(),
            other => {
                return Err(UsageError::message(format!(
                    "Invalid query values. Must be an array, got {}",
                    crate::metric::php_gettype_pub(&other)
                )));
            }
        };
        Ok(Self::new(method, attribute, values))
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

impl From<BaseQuery> for UsageQuery {
    fn from(query: BaseQuery) -> Self {
        Self::new(
            query.get_method().as_str(),
            query.get_attribute(),
            query.get_values().to_vec(),
        )
    }
}

impl From<&BaseQuery> for UsageQuery {
    fn from(query: &BaseQuery) -> Self {
        Self::from(query.clone())
    }
}
