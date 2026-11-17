//! Queries over audit logs: [`utopia_query::Query`], with audit's lenient
//! factories and the legacy `TYPE_*` method names.
//!
//! `Audit\Query` extends `Utopia\Query\Query`; its factories take a single
//! value of any type where the base takes lists or scalars. Every other
//! factory (`orderDesc`, `limit`, `cursorAfter`, `containsString`, ...) is
//! the base one: use [`Query`]'s.
//!
//! ```
//! use utopia_audit::query::{self, Query};
//!
//! let q = query::equal("userId", "u1");
//! assert_eq!(q.method().as_str(), query::TYPE_EQUAL);
//! assert_eq!(q.to_string()?, r#"{"method":"equal","attribute":"userId","values":["u1"]}"#);
//! let page = Query::limit(10);
//! assert_eq!(page.method().as_str(), query::TYPE_LIMIT);
//! # Ok::<(), utopia_query::Error>(())
//! ```
//!
//! ```php
//! use Utopia\Audit\Query;
//!
//! $q = Query::equal('userId', 'u1');
//! echo $q->getMethod()->value === Query::TYPE_EQUAL ? 'equal' : '', "\n"; // equal
//! echo $q->toString(), "\n"; // {"method":"equal","attribute":"userId","values":["u1"]}
//! ```
//!
//! PHP: `Utopia\Audit\Query`.

use utopia_query::{Array, Value};
pub use utopia_query::{Method, Query};

/// `equal`. PHP: `Query::TYPE_EQUAL`.
pub const TYPE_EQUAL: &str = "equal";
/// `notEqual`. PHP: `Query::TYPE_NOT_EQUAL`.
pub const TYPE_NOT_EQUAL: &str = "notEqual";
/// `lessThan`. PHP: `Query::TYPE_LESSER`.
pub const TYPE_LESSER: &str = "lessThan";
/// `lessThanEqual`. PHP: `Query::TYPE_LESSER_EQUAL`.
pub const TYPE_LESSER_EQUAL: &str = "lessThanEqual";
/// `greaterThan`. PHP: `Query::TYPE_GREATER`.
pub const TYPE_GREATER: &str = "greaterThan";
/// `greaterThanEqual`. PHP: `Query::TYPE_GREATER_EQUAL`.
pub const TYPE_GREATER_EQUAL: &str = "greaterThanEqual";
/// `between`. PHP: `Query::TYPE_BETWEEN`.
pub const TYPE_BETWEEN: &str = "between";
/// `notBetween`. PHP: `Query::TYPE_NOT_BETWEEN`.
pub const TYPE_NOT_BETWEEN: &str = "notBetween";
/// `contains`. PHP: `Query::TYPE_CONTAINS`.
pub const TYPE_CONTAINS: &str = "contains";
/// `notContains`. PHP: `Query::TYPE_NOT_CONTAINS`.
pub const TYPE_NOT_CONTAINS: &str = "notContains";
/// `isNull`. PHP: `Query::TYPE_IS_NULL`.
pub const TYPE_IS_NULL: &str = "isNull";
/// `isNotNull`. PHP: `Query::TYPE_IS_NOT_NULL`.
pub const TYPE_IS_NOT_NULL: &str = "isNotNull";
/// `startsWith`. PHP: `Query::TYPE_STARTS_WITH`.
pub const TYPE_STARTS_WITH: &str = "startsWith";
/// `notStartsWith`. PHP: `Query::TYPE_NOT_STARTS_WITH`.
pub const TYPE_NOT_STARTS_WITH: &str = "notStartsWith";
/// `endsWith`. PHP: `Query::TYPE_ENDS_WITH`.
pub const TYPE_ENDS_WITH: &str = "endsWith";
/// `notEndsWith`. PHP: `Query::TYPE_NOT_ENDS_WITH`.
pub const TYPE_NOT_ENDS_WITH: &str = "notEndsWith";
/// `regex`. PHP: `Query::TYPE_REGEX`.
pub const TYPE_REGEX: &str = "regex";
/// `select`. PHP: `Query::TYPE_SELECT`.
pub const TYPE_SELECT: &str = "select";
/// `orderDesc`. PHP: `Query::TYPE_ORDER_DESC`.
pub const TYPE_ORDER_DESC: &str = "orderDesc";
/// `orderAsc`. PHP: `Query::TYPE_ORDER_ASC`.
pub const TYPE_ORDER_ASC: &str = "orderAsc";
/// `orderRandom`. PHP: `Query::TYPE_ORDER_RANDOM`.
pub const TYPE_ORDER_RANDOM: &str = "orderRandom";
/// `limit`. PHP: `Query::TYPE_LIMIT`.
pub const TYPE_LIMIT: &str = "limit";
/// `offset`. PHP: `Query::TYPE_OFFSET`.
pub const TYPE_OFFSET: &str = "offset";
/// `cursorAfter`. PHP: `Query::TYPE_CURSOR_AFTER`.
pub const TYPE_CURSOR_AFTER: &str = "cursorAfter";
/// `cursorBefore`. PHP: `Query::TYPE_CURSOR_BEFORE`.
pub const TYPE_CURSOR_BEFORE: &str = "cursorBefore";

/// A query of `method` on `attribute` with `values`.
///
/// PHP: `new Query($method, $attribute, $values)`.
pub fn new(method: Method, attribute: impl Into<String>, values: Array) -> Query {
    Query::new(method, attribute, values, "")
}

/// `attribute` equal to `value`, or to any of its values when it is an array.
///
/// PHP: `Query::equal($attribute, $value)`.
pub fn equal(attribute: impl Into<String>, value: impl Into<Value>) -> Query {
    let values = match value.into() {
        Value::Array(values) => values,
        value => Array::from_list(vec![value]),
    };
    new(Method::Equal, attribute, values)
}

/// `attribute` less than `value`.
///
/// PHP: `Query::lessThan($attribute, $value)`.
pub fn less_than(attribute: impl Into<String>, value: impl Into<Value>) -> Query {
    new(Method::LessThan, attribute, Array::from_list(vec![value.into()]))
}

/// `attribute` greater than `value`.
///
/// PHP: `Query::greaterThan($attribute, $value)`.
pub fn greater_than(attribute: impl Into<String>, value: impl Into<Value>) -> Query {
    new(Method::GreaterThan, attribute, Array::from_list(vec![value.into()]))
}

/// `attribute` between `start` and `end`, inclusive.
///
/// PHP: `Query::between($attribute, $start, $end)`.
pub fn between(attribute: impl Into<String>, start: impl Into<Value>, end: impl Into<Value>) -> Query {
    new(Method::Between, attribute, Array::from_list(vec![start.into(), end.into()]))
}
