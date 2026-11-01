//! Usage-specific queries: time buckets, dimensions and the aggregate.
//!
//! PHP: `Utopia\Usage\UsageQuery`, which extends `Utopia\Query\Query`. Here
//! the factories return plain [`Query`] values (the method carries the
//! meaning), so parsed queries and built ones are the same thing.

use utopia_query::{Array, Method, Query, Value};

use crate::error::{Error, Result};

/// The bucket sizes `group_by_interval` accepts and their ClickHouse
/// `INTERVAL` expressions.
///
/// PHP: `UsageQuery::VALID_INTERVALS`.
pub const VALID_INTERVALS: &[(&str, &str)] = &[
    ("1m", "INTERVAL 1 MINUTE"),
    ("5m", "INTERVAL 5 MINUTE"),
    ("15m", "INTERVAL 15 MINUTE"),
    ("30m", "INTERVAL 30 MINUTE"),
    ("1h", "INTERVAL 1 HOUR"),
    ("1d", "INTERVAL 1 DAY"),
    ("1w", "INTERVAL 1 WEEK"),
    ("1M", "INTERVAL 1 MONTH"),
];

/// The aggregation functions `aggregate` accepts.
///
/// PHP: `UsageQuery::VALID_AGGREGATES`.
pub const VALID_AGGREGATES: &[&str] = &["max"];

/// The `INTERVAL` expression of a bucket size.
pub fn interval_sql(interval: &str) -> Option<&'static str> {
    VALID_INTERVALS.iter().find(|(name, _)| *name == interval).map(|(_, sql)| *sql)
}

fn interval_names() -> String {
    VALID_INTERVALS.iter().map(|(name, _)| *name).collect::<Vec<_>>().join(", ")
}

/// A `groupByTimeBucket` query: results bucketed by `interval` on
/// `attribute` (usually `time`).
///
/// PHP: `UsageQuery::groupByInterval($attribute, $interval)`.
pub fn group_by_interval(attribute: &str, interval: &str) -> Result<Query> {
    if interval_sql(interval).is_none() {
        return Err(Error::invalid_argument(format!("Invalid interval '{interval}'. Allowed: {}", interval_names())));
    }
    Ok(Query::new(Method::GroupByTimeBucket, attribute, Array::from_list(vec![Value::from(interval)]), ""))
}

/// A `groupBy` query on one dimension (`attribute`, no values).
///
/// PHP: `UsageQuery::groupBy('service')`.
pub fn group_by(attribute: &str) -> Query {
    Query::new(Method::GroupBy, attribute, Array::default(), "")
}

/// A `groupBy` query on several dimensions (the base `Query::groupBy`:
/// no attribute, the dimensions as values).
///
/// PHP: `UsageQuery::groupBy(['service', 'status'])`.
pub fn group_by_many<S: Into<String>>(attributes: impl IntoIterator<Item = S>) -> Query {
    Query::group_by(attributes)
}

/// The aggregate query: `max` takes the largest value per bucket instead
/// of the per-type default.
///
/// PHP: `UsageQuery::aggregate($function)`.
pub fn aggregate(function: &str) -> Result<Query> {
    if function != "max" {
        return Err(Error::invalid_argument(format!(
            "Invalid aggregate '{function}'. Allowed: {}",
            VALID_AGGREGATES.join(", ")
        )));
    }
    Ok(Query::new(Method::Max, "value", Array::default(), ""))
}

/// The aggregate function named by `queries` (the first `max`), if any.
///
/// PHP: `UsageQuery::extractAggregate($queries)`.
pub fn extract_aggregate(queries: &[Query]) -> Option<&'static str> {
    queries.iter().find(|q| q.method() == Method::Max).map(|_| "max")
}
