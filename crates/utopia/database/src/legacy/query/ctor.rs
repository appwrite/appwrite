//! The query constructors (PHP's static helpers `Query::equal()`, ...).
//!
//! Array parameters take any iterable of values and build a list. PHP keeps
//! the keys of a keyed array passed to them; build such a query with
//! [`Query::from_array`].

use super::{Method, Query};
use crate::document::Document;
use crate::operator::Number;
use crate::value::{Array, Value};

fn list<V: Into<Value>>(values: impl IntoIterator<Item = V>) -> Vec<Value> {
    values.into_iter().map(Into::into).collect()
}

fn array<V: Into<Value>>(values: impl IntoIterator<Item = V>) -> Value {
    Value::Array(values.into_iter().map(Into::into).collect())
}

impl Query {
    /// `attribute` equals one of `values`.
    ///
    /// PHP: `Query::equal()`.
    pub fn equal<V: Into<Value>>(attribute: &str, values: impl IntoIterator<Item = V>) -> Self {
        Self::new(Method::Equal, attribute, list(values))
    }

    /// `attribute` differs from `value`. A list is taken as the values
    /// themselves; any other value (a scalar, or an array with keys) is the
    /// one value.
    ///
    /// PHP: `Query::notEqual()`.
    pub fn not_equal(attribute: &str, value: impl Into<Value>) -> Self {
        match value.into() {
            Value::Array(a) if a.is_list() => Self::from_array(Method::NotEqual, attribute, a),
            other => Self::new(Method::NotEqual, attribute, vec![other]),
        }
    }

    /// `attribute < value`.
    ///
    /// PHP: `Query::lessThan()`.
    pub fn less_than(attribute: &str, value: impl Into<Value>) -> Self {
        Self::new(Method::LessThan, attribute, vec![value.into()])
    }

    /// `attribute <= value`.
    ///
    /// PHP: `Query::lessThanEqual()`.
    pub fn less_than_equal(attribute: &str, value: impl Into<Value>) -> Self {
        Self::new(Method::LessThanEqual, attribute, vec![value.into()])
    }

    /// `attribute > value`.
    ///
    /// PHP: `Query::greaterThan()`.
    pub fn greater_than(attribute: &str, value: impl Into<Value>) -> Self {
        Self::new(Method::GreaterThan, attribute, vec![value.into()])
    }

    /// `attribute >= value`.
    ///
    /// PHP: `Query::greaterThanEqual()`.
    pub fn greater_than_equal(attribute: &str, value: impl Into<Value>) -> Self {
        Self::new(Method::GreaterThanEqual, attribute, vec![value.into()])
    }

    /// `attribute` contains one of `values` (a substring of a string, an
    /// element of an array).
    ///
    /// PHP: `Query::contains()` (deprecated there in favour of
    /// `containsAny()` for arrays).
    pub fn contains<V: Into<Value>>(attribute: &str, values: impl IntoIterator<Item = V>) -> Self {
        Self::new(Method::Contains, attribute, list(values))
    }

    /// The array `attribute` contains any of `values`.
    ///
    /// PHP: `Query::containsAny()`.
    pub fn contains_any<V: Into<Value>>(attribute: &str, values: impl IntoIterator<Item = V>) -> Self {
        Self::new(Method::ContainsAny, attribute, list(values))
    }

    /// `attribute` contains none of `values`.
    ///
    /// PHP: `Query::notContains()`.
    pub fn not_contains<V: Into<Value>>(attribute: &str, values: impl IntoIterator<Item = V>) -> Self {
        Self::new(Method::NotContains, attribute, list(values))
    }

    /// The array `attribute` contains all of `values`.
    ///
    /// PHP: `Query::containsAll()`.
    pub fn contains_all<V: Into<Value>>(attribute: &str, values: impl IntoIterator<Item = V>) -> Self {
        Self::new(Method::ContainsAll, attribute, list(values))
    }

    /// `start <= attribute <= end`.
    ///
    /// PHP: `Query::between()`.
    pub fn between(attribute: &str, start: impl Into<Value>, end: impl Into<Value>) -> Self {
        Self::new(Method::Between, attribute, vec![start.into(), end.into()])
    }

    /// `attribute` outside `start..=end`.
    ///
    /// PHP: `Query::notBetween()`.
    pub fn not_between(attribute: &str, start: impl Into<Value>, end: impl Into<Value>) -> Self {
        Self::new(Method::NotBetween, attribute, vec![start.into(), end.into()])
    }

    /// Full-text search of `value` in `attribute`.
    ///
    /// PHP: `Query::search()`.
    pub fn search(attribute: &str, value: &str) -> Self {
        Self::new(Method::Search, attribute, vec![Value::from(value)])
    }

    /// No full-text match of `value` in `attribute`.
    ///
    /// PHP: `Query::notSearch()`.
    pub fn not_search(attribute: &str, value: &str) -> Self {
        Self::new(Method::NotSearch, attribute, vec![Value::from(value)])
    }

    /// `attribute` starts with `value`.
    ///
    /// PHP: `Query::startsWith()`.
    pub fn starts_with(attribute: &str, value: &str) -> Self {
        Self::new(Method::StartsWith, attribute, vec![Value::from(value)])
    }

    /// `attribute` does not start with `value`.
    ///
    /// PHP: `Query::notStartsWith()`.
    pub fn not_starts_with(attribute: &str, value: &str) -> Self {
        Self::new(Method::NotStartsWith, attribute, vec![Value::from(value)])
    }

    /// `attribute` ends with `value`.
    ///
    /// PHP: `Query::endsWith()`.
    pub fn ends_with(attribute: &str, value: &str) -> Self {
        Self::new(Method::EndsWith, attribute, vec![Value::from(value)])
    }

    /// `attribute` does not end with `value`.
    ///
    /// PHP: `Query::notEndsWith()`.
    pub fn not_ends_with(attribute: &str, value: &str) -> Self {
        Self::new(Method::NotEndsWith, attribute, vec![Value::from(value)])
    }

    /// `attribute` matches the regular expression `pattern`.
    ///
    /// PHP: `Query::regex()`. [`Query::parse`] rejects the query it builds
    /// (see [`Method::is_method`]).
    pub fn regex(attribute: &str, pattern: &str) -> Self {
        Self::new(Method::Regex, attribute, vec![Value::from(pattern)])
    }

    /// Selects `attributes`.
    ///
    /// PHP: `Query::select()`.
    pub fn select<V: Into<Value>>(attributes: impl IntoIterator<Item = V>) -> Self {
        Self::new(Method::Select, "", list(attributes))
    }

    /// Orders by `attribute` descending (`""`: by `$sequence`).
    ///
    /// PHP: `Query::orderDesc()`.
    pub fn order_desc(attribute: &str) -> Self {
        Self::new(Method::OrderDesc, attribute, Vec::new())
    }

    /// Orders by `attribute` ascending (`""`: by `$sequence`).
    ///
    /// PHP: `Query::orderAsc()`.
    pub fn order_asc(attribute: &str) -> Self {
        Self::new(Method::OrderAsc, attribute, Vec::new())
    }

    /// Orders randomly.
    ///
    /// PHP: `Query::orderRandom()`.
    pub fn order_random() -> Self {
        Self::new(Method::OrderRandom, "", Vec::new())
    }

    /// Returns at most `value` documents.
    ///
    /// PHP: `Query::limit()`.
    pub fn limit(value: i64) -> Self {
        Self::new(Method::Limit, "", vec![Value::Int(value)])
    }

    /// Skips `value` documents.
    ///
    /// PHP: `Query::offset()`.
    pub fn offset(value: i64) -> Self {
        Self::new(Method::Offset, "", vec![Value::Int(value)])
    }

    /// Starts after `document`.
    ///
    /// PHP: `Query::cursorAfter()`.
    pub fn cursor_after(document: Document) -> Self {
        Self::new(Method::CursorAfter, "", vec![Value::from(document)])
    }

    /// Ends before `document`.
    ///
    /// PHP: `Query::cursorBefore()`.
    pub fn cursor_before(document: Document) -> Self {
        Self::new(Method::CursorBefore, "", vec![Value::from(document)])
    }

    /// `attribute` is `null`.
    ///
    /// PHP: `Query::isNull()`.
    pub fn is_null(attribute: &str) -> Self {
        Self::new(Method::IsNull, attribute, Vec::new())
    }

    /// `attribute` is not `null`.
    ///
    /// PHP: `Query::isNotNull()`.
    pub fn is_not_null(attribute: &str) -> Self {
        Self::new(Method::IsNotNull, attribute, Vec::new())
    }

    /// Created before `value` (`$createdAt < value`).
    ///
    /// PHP: `Query::createdBefore()`.
    pub fn created_before(value: &str) -> Self {
        Self::less_than("$createdAt", value)
    }

    /// Created after `value` (`$createdAt > value`).
    ///
    /// PHP: `Query::createdAfter()`.
    pub fn created_after(value: &str) -> Self {
        Self::greater_than("$createdAt", value)
    }

    /// Updated before `value` (`$updatedAt < value`).
    ///
    /// PHP: `Query::updatedBefore()`.
    pub fn updated_before(value: &str) -> Self {
        Self::less_than("$updatedAt", value)
    }

    /// Updated after `value` (`$updatedAt > value`).
    ///
    /// PHP: `Query::updatedAfter()`.
    pub fn updated_after(value: &str) -> Self {
        Self::greater_than("$updatedAt", value)
    }

    /// Created between `start` and `end`.
    ///
    /// PHP: `Query::createdBetween()`.
    pub fn created_between(start: &str, end: &str) -> Self {
        Self::between("$createdAt", start, end)
    }

    /// Updated between `start` and `end`.
    ///
    /// PHP: `Query::updatedBetween()`.
    pub fn updated_between(start: &str, end: &str) -> Self {
        Self::between("$updatedAt", start, end)
    }

    /// Any of `queries` holds.
    ///
    /// PHP: `Query::or()`.
    pub fn or(queries: Vec<Query>) -> Self {
        Self::new(Method::Or, "", queries.into_iter().map(Value::from).collect())
    }

    /// All of `queries` hold.
    ///
    /// PHP: `Query::and()`.
    pub fn and(queries: Vec<Query>) -> Self {
        Self::new(Method::And, "", queries.into_iter().map(Value::from).collect())
    }

    /// An element of the array `attribute` matches all of `queries`.
    ///
    /// PHP: `Query::elemMatch()`.
    pub fn elem_match(attribute: &str, queries: Vec<Query>) -> Self {
        Self::new(Method::ElemMatch, attribute, queries.into_iter().map(Value::from).collect())
    }

    fn distance(
        method: Method,
        attribute: &str,
        values: Vec<Value>,
        distance: impl Into<Number>,
        meters: bool,
    ) -> Self {
        let parameters = vec![Value::Array(Array::from_list(values)), distance.into().into(), Value::Bool(meters)];
        Self::new(method, attribute, vec![Value::Array(Array::from_list(parameters))])
    }

    /// The distance from the geometry `values` to `attribute` equals
    /// `distance` (in meters when `meters`, else in the SRID's units). The
    /// value is the list `[values, distance, meters]`.
    ///
    /// PHP: `Query::distanceEqual()`.
    pub fn distance_equal<V: Into<Value>>(
        attribute: &str,
        values: impl IntoIterator<Item = V>,
        distance: impl Into<Number>,
        meters: bool,
    ) -> Self {
        Self::distance(Method::DistanceEqual, attribute, list(values), distance, meters)
    }

    /// The distance differs from `distance` (see [`Query::distance_equal`]).
    ///
    /// PHP: `Query::distanceNotEqual()`.
    pub fn distance_not_equal<V: Into<Value>>(
        attribute: &str,
        values: impl IntoIterator<Item = V>,
        distance: impl Into<Number>,
        meters: bool,
    ) -> Self {
        Self::distance(Method::DistanceNotEqual, attribute, list(values), distance, meters)
    }

    /// The distance is greater than `distance` (see [`Query::distance_equal`]).
    ///
    /// PHP: `Query::distanceGreaterThan()`.
    pub fn distance_greater_than<V: Into<Value>>(
        attribute: &str,
        values: impl IntoIterator<Item = V>,
        distance: impl Into<Number>,
        meters: bool,
    ) -> Self {
        Self::distance(Method::DistanceGreaterThan, attribute, list(values), distance, meters)
    }

    /// The distance is less than `distance` (see [`Query::distance_equal`]).
    ///
    /// PHP: `Query::distanceLessThan()`.
    pub fn distance_less_than<V: Into<Value>>(
        attribute: &str,
        values: impl IntoIterator<Item = V>,
        distance: impl Into<Number>,
        meters: bool,
    ) -> Self {
        Self::distance(Method::DistanceLessThan, attribute, list(values), distance, meters)
    }

    /// `attribute` intersects the geometry `values` (the one value).
    ///
    /// PHP: `Query::intersects()`.
    pub fn intersects<V: Into<Value>>(attribute: &str, values: impl IntoIterator<Item = V>) -> Self {
        Self::new(Method::Intersects, attribute, vec![array(values)])
    }

    /// `attribute` does not intersect the geometry `values`.
    ///
    /// PHP: `Query::notIntersects()`.
    pub fn not_intersects<V: Into<Value>>(attribute: &str, values: impl IntoIterator<Item = V>) -> Self {
        Self::new(Method::NotIntersects, attribute, vec![array(values)])
    }

    /// `attribute` crosses the geometry `values`.
    ///
    /// PHP: `Query::crosses()`.
    pub fn crosses<V: Into<Value>>(attribute: &str, values: impl IntoIterator<Item = V>) -> Self {
        Self::new(Method::Crosses, attribute, vec![array(values)])
    }

    /// `attribute` does not cross the geometry `values`.
    ///
    /// PHP: `Query::notCrosses()`.
    pub fn not_crosses<V: Into<Value>>(attribute: &str, values: impl IntoIterator<Item = V>) -> Self {
        Self::new(Method::NotCrosses, attribute, vec![array(values)])
    }

    /// `attribute` overlaps the geometry `values`.
    ///
    /// PHP: `Query::overlaps()`.
    pub fn overlaps<V: Into<Value>>(attribute: &str, values: impl IntoIterator<Item = V>) -> Self {
        Self::new(Method::Overlaps, attribute, vec![array(values)])
    }

    /// `attribute` does not overlap the geometry `values`.
    ///
    /// PHP: `Query::notOverlaps()`.
    pub fn not_overlaps<V: Into<Value>>(attribute: &str, values: impl IntoIterator<Item = V>) -> Self {
        Self::new(Method::NotOverlaps, attribute, vec![array(values)])
    }

    /// `attribute` touches the geometry `values`.
    ///
    /// PHP: `Query::touches()`.
    pub fn touches<V: Into<Value>>(attribute: &str, values: impl IntoIterator<Item = V>) -> Self {
        Self::new(Method::Touches, attribute, vec![array(values)])
    }

    /// `attribute` does not touch the geometry `values`.
    ///
    /// PHP: `Query::notTouches()`.
    pub fn not_touches<V: Into<Value>>(attribute: &str, values: impl IntoIterator<Item = V>) -> Self {
        Self::new(Method::NotTouches, attribute, vec![array(values)])
    }

    /// Orders by dot-product similarity of `attribute` to `vector`.
    ///
    /// PHP: `Query::vectorDot()`.
    pub fn vector_dot<V: Into<Value>>(attribute: &str, vector: impl IntoIterator<Item = V>) -> Self {
        Self::new(Method::VectorDot, attribute, vec![array(vector)])
    }

    /// Orders by cosine similarity of `attribute` to `vector`.
    ///
    /// PHP: `Query::vectorCosine()`.
    pub fn vector_cosine<V: Into<Value>>(attribute: &str, vector: impl IntoIterator<Item = V>) -> Self {
        Self::new(Method::VectorCosine, attribute, vec![array(vector)])
    }

    /// Orders by Euclidean distance of `attribute` to `vector`.
    ///
    /// PHP: `Query::vectorEuclidean()`.
    pub fn vector_euclidean<V: Into<Value>>(attribute: &str, vector: impl IntoIterator<Item = V>) -> Self {
        Self::new(Method::VectorEuclidean, attribute, vec![array(vector)])
    }

    /// `attributes` exist (in schemaless documents).
    ///
    /// PHP: `Query::exists()`.
    pub fn exists<V: Into<Value>>(attributes: impl IntoIterator<Item = V>) -> Self {
        Self::new(Method::Exists, "", list(attributes))
    }

    /// `attribute` does not exist: an array is the attributes themselves
    /// (keys kept), any other value the one attribute.
    ///
    /// PHP: `Query::notExists()`.
    pub fn not_exists(attribute: impl Into<Value>) -> Self {
        match attribute.into() {
            Value::Array(a) => Self::from_array(Method::NotExists, "", a),
            other => Self::new(Method::NotExists, "", vec![other]),
        }
    }
}
