//! The type of each aggregate of a row `aggregate()` returns: counts and
//! bitwise aggregates integers, averages, deviations and variances floats,
//! a sum an integer over an integer attribute and a float otherwise, and
//! `min()` and `max()` typed as the attribute they read.
//!
//! The engines return them as the driver reads them (PostgreSQL's `COUNT`
//! as an integer, its `AVG` as a numeric string, everything a string under
//! `PDO::ATTR_STRINGIFY_FETCHES`), so the value is typed here, the same on
//! every adapter.
//!
//! PHP (utopia-php/database 8.3.0): `Aggregation::from()`, the aggregate
//! half of `Aggregation::decode()`, `aggregate()`, `name()`, `cast()`,
//! `scalar()`, `integer()`, `float()` and `attributes()`.

use php_std::filter::{FILTER_VALIDATE_BOOL, FILTER_VALIDATE_INT, Options, Value as Filtered, filter_var};
use utopia_query::Method;
use utopia_query::schema::ColumnType;

use super::joins::Joined;
use super::numeric::is_numeric;
use crate::attribute::Attribute;
use crate::collection::Collection;
use crate::database::filters::json_decode_assoc;
use crate::datetime::format_tz;

use crate::filter::Filter;
use crate::query::Query;
use crate::value::{Array, ArrayKey, Ext, Str, Value};

/// `Aggregation::COUNTS`.
const COUNTS: [Method; 5] = [Method::Count, Method::CountDistinct, Method::BitAnd, Method::BitOr, Method::BitXor];

/// `Aggregation::STATISTICS`.
const STATISTICS: [Method; 7] = [
    Method::Avg,
    Method::Stddev,
    Method::StddevPop,
    Method::StddevSamp,
    Method::Variance,
    Method::VarPop,
    Method::VarSamp,
];

/// `Aggregation::INTEGERS`.
const INTEGERS: [ColumnType; 7] = [
    ColumnType::TinyInteger,
    ColumnType::SmallInteger,
    ColumnType::Integer,
    ColumnType::BigInteger,
    ColumnType::Serial,
    ColumnType::SmallSerial,
    ColumnType::BigSerial,
];

/// `Aggregation::FLOATS`.
const FLOATS: [ColumnType; 3] = [ColumnType::Float, ColumnType::Double, ColumnType::Decimal];

/// `Aggregation::DATETIMES`.
const DATETIMES: [ColumnType; 2] = [ColumnType::Datetime, ColumnType::Timestamp];

/// `Aggregation::OBJECTS`.
const OBJECTS: [ColumnType; 2] = [ColumnType::Json, ColumnType::Object];

/// Each aggregate of an `aggregate()` read, by alias: its method and the
/// attribute it reads, if the read declares one.
pub(crate) struct Aggregation {
    aggregates: Vec<(String, Method, Option<Attribute>)>,
}

impl Aggregation {
    /// The aggregates of `queries` (each aliased), their operands looked up
    /// among `internal`, the attributes of `collection` and, qualified by
    /// their alias, those of each joined collection (`Aggregation::from()`).
    pub(crate) fn from(internal: &[Attribute], collection: &Collection, joined: &Joined, queries: &[Query]) -> Self {
        let mut schema: Vec<(String, Attribute)> = Vec::new();
        let mut put = |name: String, attribute: Attribute| match schema.iter_mut().find(|(n, _)| *n == name) {
            Some(slot) => slot.1 = attribute,
            None => schema.push((name, attribute)),
        };
        for attribute in internal.iter().cloned().chain(attributes(collection)) {
            put(attribute.key.clone(), attribute);
        }
        for (alias, definition) in joined {
            for attribute in internal.iter().cloned().chain(attributes(definition)) {
                put(format!("{alias}.{}", attribute.key), attribute);
            }
        }

        let aggregates = queries
            .iter()
            .filter(|q| q.method().is_aggregate())
            .map(|q| {
                let name = name(&schema, q.attribute());
                let operand = schema.iter().find(|(n, _)| *n == name).map(|(_, a)| a.clone());
                (q.alias().to_owned(), q.method(), operand)
            })
            .collect();
        Aggregation { aggregates }
    }

    /// `row` with each aggregate typed (`Aggregation::decode()` for the
    /// aggregates; the groups are left as the adapter reads them).
    pub(crate) fn decode(&self, mut row: Array) -> Array {
        for (alias, method, operand) in &self.aggregates {
            if let Some(value) = row.get_mut(&ArrayKey::from(alias.as_str())) {
                *value = aggregate(*method, operand.as_ref(), std::mem::take(value));
            }
        }
        row
    }
}

/// The attributes `collection` declares that load as models; any other is
/// left out (`Aggregation::attributes()`).
fn attributes(collection: &Collection) -> Vec<Attribute> {
    let Some(Value::Array(stored)) = collection.document().get("attributes") else {
        return Vec::new();
    };
    stored
        .values()
        .filter_map(|a| match a {
            Value::Ext(Ext::Document(d)) => Attribute::from_document(d).ok(),
            _ => None,
        })
        .collect()
}

/// The name a read returns `attribute` under: a joined one qualified by its
/// join alias, though the queries may name it bare when no other collection
/// of the read declares it (`Aggregation::name()`).
fn name(schema: &[(String, Attribute)], attribute: &str) -> String {
    if attribute.contains('.') || schema.iter().any(|(n, _)| n == attribute) {
        return attribute.to_owned();
    }
    let suffix = format!(".{attribute}");
    let matches: Vec<&String> = schema.iter().map(|(n, _)| n).filter(|n| n.ends_with(&suffix)).collect();
    match matches.as_slice() {
        [only] => (*only).clone(),
        _ => attribute.to_owned(),
    }
}

/// One aggregate's value, typed by its method and operand
/// (`Aggregation::aggregate()`).
fn aggregate(method: Method, operand: Option<&Attribute>, value: Value) -> Value {
    if matches!(value, Value::Null) {
        return Value::Null;
    }
    if COUNTS.contains(&method) {
        return integer(&value);
    }
    if STATISTICS.contains(&method) {
        return Value::Float(float(&value));
    }
    if method == Method::Sum {
        return if operand.is_some_and(|a| INTEGERS.contains(&a.kind)) {
            integer(&value)
        } else {
            Value::Float(float(&value))
        };
    }
    match operand {
        None => value,
        Some(attribute) => cast(attribute, value),
    }
}

/// `Aggregation::cast()`.
fn cast(attribute: &Attribute, value: Value) -> Value {
    if matches!(value, Value::Null) {
        return Value::Null;
    }
    let encoded = attribute.array
        || OBJECTS.contains(&attribute.kind)
        || attribute.filters.iter().any(|f| f == Filter::Json.as_str());
    let value = match value {
        Value::Str(s) if encoded => json_decode_assoc(s.as_bytes()),
        other => other,
    };
    match value {
        Value::Array(list) if attribute.array => {
            Value::Array(list.iter().map(|(k, v)| (k.to_owned(), scalar(attribute.kind, v.clone()))).collect())
        }
        other => scalar(attribute.kind, other),
    }
}

/// `Aggregation::scalar()`.
fn scalar(kind: ColumnType, value: Value) -> Value {
    if matches!(value, Value::Null | Value::Array(_)) {
        return value;
    }
    if kind == ColumnType::Boolean {
        return match value {
            Value::Bool(_) => value,
            other => Value::Bool(matches!(validate(&other, FILTER_VALIDATE_BOOL), Some(Filtered::Bool(true)))),
        };
    }
    if kind == ColumnType::Id {
        return match &value {
            Value::Bool(_) | Value::Int(_) | Value::Float(_) | Value::Str(_) => {
                value.to_php_string().map(Value::Str).unwrap_or(value)
            }
            _ => value,
        };
    }
    if INTEGERS.contains(&kind) {
        return integer(&value);
    }
    if FLOATS.contains(&kind) {
        return Value::Float(float(&value));
    }
    if DATETIMES.contains(&kind) {
        return match &value {
            Value::Str(s) => match format_tz(Some(&String::from_utf8_lossy(s.as_bytes()))) {
                Some(formatted) => Value::Str(Str::from(formatted)),
                None => Value::Null,
            },
            _ => value,
        };
    }
    value
}

/// `filter_var($value, $filter)` of a scalar.
fn validate(value: &Value, filter: i64) -> Option<Filtered> {
    let plain = match value {
        Value::Bool(b) => Filtered::Bool(*b),
        Value::Int(i) => Filtered::Int(*i),
        Value::Float(f) => Filtered::Float(*f),
        Value::Str(s) => Filtered::Str(s.as_bytes().to_vec()),
        _ => return None,
    };
    filter_var(&plain, filter, &Options::Flags(0)).ok().map(|f| f.value)
}

/// An integer, or a float past the platform's integers. MariaDB sums
/// integers into `DECIMAL`, so a whole value can arrive as `"7.0000"`
/// (`Aggregation::integer()`).
fn integer(value: &Value) -> Value {
    if let Value::Int(i) = value {
        return Value::Int(*i);
    }
    if let Some(Filtered::Int(i)) = validate(value, FILTER_VALIDATE_INT) {
        return Value::Int(i);
    }
    let float = float(value);
    if float.floor() == float && float.abs() < i64::MAX as f64 { Value::Int(float as i64) } else { Value::Float(float) }
}

/// `(float) $value` of a numeric value, `0.0` of any other
/// (`Aggregation::float()`).
fn float(value: &Value) -> f64 {
    if is_numeric(value) { value.to_double() } else { 0.0 }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::document::Document;

    fn attribute(key: &str, kind: ColumnType) -> Attribute {
        Attribute::from_document(
            &Document::new(
                [
                    (ArrayKey::from("$id"), Value::Str(Str::from(key))),
                    (ArrayKey::from("key"), Value::Str(Str::from(key))),
                    (ArrayKey::from("type"), Value::Str(Str::from(kind.as_str()))),
                ]
                .into_iter()
                .collect(),
            )
            .unwrap(),
        )
        .unwrap()
    }

    fn str(s: &str) -> Value {
        Value::Str(Str::from(s))
    }

    // PHP 8.3.0 Aggregation.php:296-303:
    //   \in_array($method, self::COUNTS, true) => self::integer($value),
    //   \in_array($method, self::STATISTICS, true) => self::float($value),
    //   $method === Method::Sum => ... INTEGERS ? self::integer($value) : self::float($value),
    //   default => $operand === null ? $value : self::cast($operand, $value),
    #[test]
    fn types_each_aggregate_by_its_method_and_operand() {
        let price = attribute("price", ColumnType::Double);
        let stock = attribute("stock", ColumnType::Integer);

        // PostgreSQL's COUNT is an int already; MariaDB and SQLite stringify it.
        assert_eq!(aggregate(Method::Count, None, Value::Int(9)), Value::Int(9));
        assert_eq!(aggregate(Method::Count, None, str("9")), Value::Int(9));
        // AVG of an integer column is a numeric string on PostgreSQL.
        assert_eq!(aggregate(Method::Avg, Some(&stock), str("309.4444444444444444")), Value::Float(309.44444444444446));
        assert_eq!(aggregate(Method::Avg, Some(&price), str("4.0888888888889")), Value::Float(4.0888888888889));
        // MariaDB sums integers into DECIMAL.
        assert_eq!(aggregate(Method::Sum, Some(&stock), str("1495.0000")), Value::Int(1495));
        assert_eq!(aggregate(Method::Sum, Some(&price), str("2785")), Value::Float(2785.0));
        assert_eq!(aggregate(Method::Min, Some(&stock), str("10")), Value::Int(10));
        assert_eq!(aggregate(Method::Max, Some(&price), str("1200")), Value::Float(1200.0));
        assert_eq!(aggregate(Method::Max, None, str("x")), str("x"));
        assert_eq!(aggregate(Method::Sum, Some(&stock), Value::Null), Value::Null);
        assert_eq!(aggregate(Method::BitAnd, None, str("0")), Value::Int(0));
    }

    #[test]
    fn types_min_and_max_as_their_attribute() {
        assert_eq!(
            aggregate(Method::Max, Some(&attribute("at", ColumnType::Datetime)), str("2024-01-01 09:00:00.000")),
            str("2024-01-01T09:00:00.000+00:00")
        );
        assert_eq!(aggregate(Method::Min, Some(&attribute("on", ColumnType::Boolean)), str("1")), Value::Bool(true));
        assert_eq!(
            aggregate(Method::Min, Some(&attribute("on", ColumnType::Boolean)), Value::Int(0)),
            Value::Bool(false)
        );
    }

    #[test]
    fn resolves_a_bare_attribute_to_the_one_join_that_declares_it() {
        let status = attribute("status", ColumnType::Integer);
        let schema = vec![("total".to_owned(), status.clone()), ("j1.status".to_owned(), status)];
        assert_eq!(name(&schema, "status"), "j1.status");
        assert_eq!(name(&schema, "total"), "total");
        assert_eq!(name(&schema, "missing"), "missing");
    }
}
