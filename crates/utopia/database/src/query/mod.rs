//! [`Query`]: one filter, order, selection or pagination step of a find.
//!
//! A query is a [`Method`], an attribute and a list of values. Build one with
//! the constructors ([`Query::equal`], [`Query::limit`], [`Query::or`], ...),
//! or parse the JSON clients send ([`Query::parse`]); [`Query::to_string`]
//! writes that JSON back. [`Query::group_by_type`] splits a find's queries
//! into filters, selections, orders, limit, offset and cursor, and
//! [`Query::fingerprint`] hashes their shape for slow-query grouping.
//!
//! ```
//! use utopia_database::Query;
//!
//! let q = Query::or(vec![Query::equal("title", ["Iron Man"]), Query::greater_than("year", 2000)]);
//! let json = q.to_string().unwrap();
//! assert_eq!(
//!     json,
//!     r#"{"method":"or","values":[{"method":"equal","attribute":"title","values":["Iron Man"]},{"method":"greaterThan","attribute":"year","values":[2000]}]}"#
//! );
//! assert_eq!(Query::parse(&json).unwrap(), q);
//! assert_eq!(q.shape(), "or:(equal:title|greaterThan:year)");
//! ```
//!
//! PHP: `Utopia\Database\Query`. The method is a [`Method`] (`TYPE_*`);
//! methods PHP stores without knowing them (`new Query('foo')`) are
//! [`Method::Other`], which validators reject as PHP's do. Values are
//! [`Value`]s: nested queries of `and`, `or` and `elemMatch` are
//! [`crate::value::Ext::Query`] values and a cursor's document a
//! [`crate::value::Ext::Document`], as in PHP's mixed `$values`. A value
//! array with keys other than `0, 1, ...` (a JSON object) keeps them, as in
//! PHP: [`Query::values`] is the list of values and [`Query::values_array`]
//! the PHP array. PHP's `__clone` (deep copy of nested queries) is Rust's
//! `Clone`; `getByType`'s `$clone` flag has no counterpart, as Rust values
//! are never shared.
//!
//! Overlap with `utopia-php/query` (`crates/utopia/query`): that library has
//! its own `Query` class with a different method set and SQL builders; the
//! two are not related in PHP (utopia-php/database 7.4 does not depend on
//! it). See the crate guide for how they are meant to be reconciled.

mod ctor;
mod group;
mod values;

pub use group::Grouped;
pub(crate) use values::Values;

use crate::database::Database;
use crate::document::Document;
use crate::error::{Error, Result};
use crate::value::{Array, ArrayKey, Ext, Str, Value, as_query};

/// A query method (`Query::TYPE_*`).
#[derive(Clone, PartialEq, Eq, Hash, Debug)]
pub enum Method {
    /// `equal` (`TYPE_EQUAL`)
    Equal,
    /// `notEqual` (`TYPE_NOT_EQUAL`)
    NotEqual,
    /// `lessThan` (`TYPE_LESSER`)
    LessThan,
    /// `lessThanEqual` (`TYPE_LESSER_EQUAL`)
    LessThanEqual,
    /// `greaterThan` (`TYPE_GREATER`)
    GreaterThan,
    /// `greaterThanEqual` (`TYPE_GREATER_EQUAL`)
    GreaterThanEqual,
    /// `contains` (`TYPE_CONTAINS`)
    Contains,
    /// `containsAny` (`TYPE_CONTAINS_ANY`)
    ContainsAny,
    /// `notContains` (`TYPE_NOT_CONTAINS`)
    NotContains,
    /// `search` (`TYPE_SEARCH`)
    Search,
    /// `notSearch` (`TYPE_NOT_SEARCH`)
    NotSearch,
    /// `isNull` (`TYPE_IS_NULL`)
    IsNull,
    /// `isNotNull` (`TYPE_IS_NOT_NULL`)
    IsNotNull,
    /// `between` (`TYPE_BETWEEN`)
    Between,
    /// `notBetween` (`TYPE_NOT_BETWEEN`)
    NotBetween,
    /// `startsWith` (`TYPE_STARTS_WITH`)
    StartsWith,
    /// `notStartsWith` (`TYPE_NOT_STARTS_WITH`)
    NotStartsWith,
    /// `endsWith` (`TYPE_ENDS_WITH`)
    EndsWith,
    /// `notEndsWith` (`TYPE_NOT_ENDS_WITH`)
    NotEndsWith,
    /// `regex` (`TYPE_REGEX`)
    Regex,
    /// `exists` (`TYPE_EXISTS`)
    Exists,
    /// `notExists` (`TYPE_NOT_EXISTS`)
    NotExists,
    /// `crosses` (`TYPE_CROSSES`)
    Crosses,
    /// `notCrosses` (`TYPE_NOT_CROSSES`)
    NotCrosses,
    /// `distanceEqual` (`TYPE_DISTANCE_EQUAL`)
    DistanceEqual,
    /// `distanceNotEqual` (`TYPE_DISTANCE_NOT_EQUAL`)
    DistanceNotEqual,
    /// `distanceGreaterThan` (`TYPE_DISTANCE_GREATER_THAN`)
    DistanceGreaterThan,
    /// `distanceLessThan` (`TYPE_DISTANCE_LESS_THAN`)
    DistanceLessThan,
    /// `intersects` (`TYPE_INTERSECTS`)
    Intersects,
    /// `notIntersects` (`TYPE_NOT_INTERSECTS`)
    NotIntersects,
    /// `overlaps` (`TYPE_OVERLAPS`)
    Overlaps,
    /// `notOverlaps` (`TYPE_NOT_OVERLAPS`)
    NotOverlaps,
    /// `touches` (`TYPE_TOUCHES`)
    Touches,
    /// `notTouches` (`TYPE_NOT_TOUCHES`)
    NotTouches,
    /// `vectorDot` (`TYPE_VECTOR_DOT`)
    VectorDot,
    /// `vectorCosine` (`TYPE_VECTOR_COSINE`)
    VectorCosine,
    /// `vectorEuclidean` (`TYPE_VECTOR_EUCLIDEAN`)
    VectorEuclidean,
    /// `select` (`TYPE_SELECT`)
    Select,
    /// `orderDesc` (`TYPE_ORDER_DESC`)
    OrderDesc,
    /// `orderAsc` (`TYPE_ORDER_ASC`)
    OrderAsc,
    /// `orderRandom` (`TYPE_ORDER_RANDOM`)
    OrderRandom,
    /// `limit` (`TYPE_LIMIT`)
    Limit,
    /// `offset` (`TYPE_OFFSET`)
    Offset,
    /// `cursorAfter` (`TYPE_CURSOR_AFTER`)
    CursorAfter,
    /// `cursorBefore` (`TYPE_CURSOR_BEFORE`)
    CursorBefore,
    /// `and` (`TYPE_AND`)
    And,
    /// `or` (`TYPE_OR`)
    Or,
    /// `containsAll` (`TYPE_CONTAINS_ALL`)
    ContainsAll,
    /// `elemMatch` (`TYPE_ELEM_MATCH`)
    ElemMatch,
    /// A method string PHP holds without knowing it (`new Query('foo')`).
    Other(Box<str>),
}

/// Every known method, in the order of PHP's `Query::TYPES`.
pub const TYPES: [Method; 49] = [
    Method::Equal,
    Method::NotEqual,
    Method::LessThan,
    Method::LessThanEqual,
    Method::GreaterThan,
    Method::GreaterThanEqual,
    Method::Contains,
    Method::ContainsAny,
    Method::NotContains,
    Method::Search,
    Method::NotSearch,
    Method::IsNull,
    Method::IsNotNull,
    Method::Between,
    Method::NotBetween,
    Method::StartsWith,
    Method::NotStartsWith,
    Method::EndsWith,
    Method::NotEndsWith,
    Method::Crosses,
    Method::NotCrosses,
    Method::DistanceEqual,
    Method::DistanceNotEqual,
    Method::DistanceGreaterThan,
    Method::DistanceLessThan,
    Method::Intersects,
    Method::NotIntersects,
    Method::Overlaps,
    Method::NotOverlaps,
    Method::Touches,
    Method::NotTouches,
    Method::VectorDot,
    Method::VectorCosine,
    Method::VectorEuclidean,
    Method::Exists,
    Method::NotExists,
    Method::Select,
    Method::OrderDesc,
    Method::OrderAsc,
    Method::OrderRandom,
    Method::Limit,
    Method::Offset,
    Method::CursorAfter,
    Method::CursorBefore,
    Method::And,
    Method::Or,
    Method::ContainsAll,
    Method::ElemMatch,
    Method::Regex,
];

/// The vector similarity methods (PHP `Query::VECTOR_TYPES`).
pub const VECTOR_TYPES: [Method; 3] = [Method::VectorDot, Method::VectorCosine, Method::VectorEuclidean];

/// The table alias SQL adapters give the collection (PHP `Query::DEFAULT_ALIAS`).
pub const DEFAULT_ALIAS: &str = "main";

impl Method {
    /// The method string (`TYPE_*`'s value).
    pub fn as_str(&self) -> &str {
        match self {
            Method::Equal => "equal",
            Method::NotEqual => "notEqual",
            Method::LessThan => "lessThan",
            Method::LessThanEqual => "lessThanEqual",
            Method::GreaterThan => "greaterThan",
            Method::GreaterThanEqual => "greaterThanEqual",
            Method::Contains => "contains",
            Method::ContainsAny => "containsAny",
            Method::NotContains => "notContains",
            Method::Search => "search",
            Method::NotSearch => "notSearch",
            Method::IsNull => "isNull",
            Method::IsNotNull => "isNotNull",
            Method::Between => "between",
            Method::NotBetween => "notBetween",
            Method::StartsWith => "startsWith",
            Method::NotStartsWith => "notStartsWith",
            Method::EndsWith => "endsWith",
            Method::NotEndsWith => "notEndsWith",
            Method::Regex => "regex",
            Method::Exists => "exists",
            Method::NotExists => "notExists",
            Method::Crosses => "crosses",
            Method::NotCrosses => "notCrosses",
            Method::DistanceEqual => "distanceEqual",
            Method::DistanceNotEqual => "distanceNotEqual",
            Method::DistanceGreaterThan => "distanceGreaterThan",
            Method::DistanceLessThan => "distanceLessThan",
            Method::Intersects => "intersects",
            Method::NotIntersects => "notIntersects",
            Method::Overlaps => "overlaps",
            Method::NotOverlaps => "notOverlaps",
            Method::Touches => "touches",
            Method::NotTouches => "notTouches",
            Method::VectorDot => "vectorDot",
            Method::VectorCosine => "vectorCosine",
            Method::VectorEuclidean => "vectorEuclidean",
            Method::Select => "select",
            Method::OrderDesc => "orderDesc",
            Method::OrderAsc => "orderAsc",
            Method::OrderRandom => "orderRandom",
            Method::Limit => "limit",
            Method::Offset => "offset",
            Method::CursorAfter => "cursorAfter",
            Method::CursorBefore => "cursorBefore",
            Method::And => "and",
            Method::Or => "or",
            Method::ContainsAll => "containsAll",
            Method::ElemMatch => "elemMatch",
            Method::Other(s) => s,
        }
    }

    /// The method a string names; unknown strings are [`Method::Other`].
    pub fn from_name(name: &str) -> Method {
        match name {
            "equal" => Method::Equal,
            "notEqual" => Method::NotEqual,
            "lessThan" => Method::LessThan,
            "lessThanEqual" => Method::LessThanEqual,
            "greaterThan" => Method::GreaterThan,
            "greaterThanEqual" => Method::GreaterThanEqual,
            "contains" => Method::Contains,
            "containsAny" => Method::ContainsAny,
            "notContains" => Method::NotContains,
            "search" => Method::Search,
            "notSearch" => Method::NotSearch,
            "isNull" => Method::IsNull,
            "isNotNull" => Method::IsNotNull,
            "between" => Method::Between,
            "notBetween" => Method::NotBetween,
            "startsWith" => Method::StartsWith,
            "notStartsWith" => Method::NotStartsWith,
            "endsWith" => Method::EndsWith,
            "notEndsWith" => Method::NotEndsWith,
            "regex" => Method::Regex,
            "exists" => Method::Exists,
            "notExists" => Method::NotExists,
            "crosses" => Method::Crosses,
            "notCrosses" => Method::NotCrosses,
            "distanceEqual" => Method::DistanceEqual,
            "distanceNotEqual" => Method::DistanceNotEqual,
            "distanceGreaterThan" => Method::DistanceGreaterThan,
            "distanceLessThan" => Method::DistanceLessThan,
            "intersects" => Method::Intersects,
            "notIntersects" => Method::NotIntersects,
            "overlaps" => Method::Overlaps,
            "notOverlaps" => Method::NotOverlaps,
            "touches" => Method::Touches,
            "notTouches" => Method::NotTouches,
            "vectorDot" => Method::VectorDot,
            "vectorCosine" => Method::VectorCosine,
            "vectorEuclidean" => Method::VectorEuclidean,
            "select" => Method::Select,
            "orderDesc" => Method::OrderDesc,
            "orderAsc" => Method::OrderAsc,
            "orderRandom" => Method::OrderRandom,
            "limit" => Method::Limit,
            "offset" => Method::Offset,
            "cursorAfter" => Method::CursorAfter,
            "cursorBefore" => Method::CursorBefore,
            "and" => Method::And,
            "or" => Method::Or,
            "containsAll" => Method::ContainsAll,
            "elemMatch" => Method::ElemMatch,
            other => Method::Other(other.into()),
        }
    }

    /// Whether the method is one of [`TYPES`] (not [`Method::Other`]).
    ///
    /// PHP: `in_array($method, Query::TYPES)`. Note that
    /// [`Method::is_method`] (PHP `Query::isMethod`) leaves out `regex`.
    pub fn is_known(&self) -> bool {
        !matches!(self, Method::Other(_))
    }

    /// Whether the method is one [`Query::parse`] accepts: every known
    /// method but `regex`.
    ///
    /// PHP: `Query::isMethod()`, whose list lacks `TYPE_REGEX` (a quirk:
    /// `Query::parse()` rejects a `regex` query that `Query::regex()` builds).
    pub fn is_method(&self) -> bool {
        self.is_known() && *self != Method::Regex
    }

    /// Whether the method nests queries (`and`, `or`, `elemMatch`).
    ///
    /// PHP: `in_array($method, Query::LOGICAL_TYPES)`.
    pub fn is_logical(&self) -> bool {
        matches!(self, Method::And | Method::Or | Method::ElemMatch)
    }

    /// Whether the method is a spatial filter.
    ///
    /// PHP: `Query::isSpatialQuery()`'s list.
    pub fn is_spatial(&self) -> bool {
        matches!(
            self,
            Method::Crosses
                | Method::NotCrosses
                | Method::DistanceEqual
                | Method::DistanceNotEqual
                | Method::DistanceGreaterThan
                | Method::DistanceLessThan
                | Method::Intersects
                | Method::NotIntersects
                | Method::Overlaps
                | Method::NotOverlaps
                | Method::Touches
                | Method::NotTouches
        )
    }

    /// Whether the method is a vector similarity (PHP `Query::VECTOR_TYPES`).
    pub fn is_vector(&self) -> bool {
        matches!(self, Method::VectorDot | Method::VectorCosine | Method::VectorEuclidean)
    }
}

impl std::fmt::Display for Method {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.write_str(self.as_str())
    }
}

/// One query.
///
/// PHP: `Utopia\Database\Query`.
#[derive(Clone, PartialEq, Debug)]
pub struct Query {
    method: Method,
    attribute: String,
    attribute_type: String,
    on_array: bool,
    values: Values,
}

impl Query {
    /// A query of `method` on `attribute` with `values`. An empty attribute
    /// of `orderAsc` and `orderDesc` is `$sequence`.
    ///
    /// PHP: `new Query($method, $attribute, $values)` with a list.
    pub fn new(method: Method, attribute: impl Into<String>, values: Vec<Value>) -> Self {
        Self::with_values(method, attribute.into(), Values::list(values))
    }

    /// [`Query::new`] with any PHP array of values: keys other than
    /// `0, 1, ...` are kept ([`Query::values_array`], [`Query::to_array`]).
    ///
    /// PHP: `new Query($method, $attribute, $values)`.
    pub fn from_array(method: Method, attribute: impl Into<String>, values: Array) -> Self {
        Self::with_values(method, attribute.into(), Values::array(values))
    }

    fn with_values(method: Method, mut attribute: String, values: Values) -> Self {
        if attribute.is_empty() && matches!(method, Method::OrderAsc | Method::OrderDesc) {
            attribute = "$sequence".to_owned();
        }
        Self { method, attribute, attribute_type: String::new(), on_array: false, values }
    }

    /// The method.
    ///
    /// PHP: `Query::getMethod()`.
    pub fn method(&self) -> &Method {
        &self.method
    }

    /// The attribute.
    ///
    /// PHP: `Query::getAttribute()`.
    pub fn attribute(&self) -> &str {
        &self.attribute
    }

    /// The values, in order (their keys dropped; see [`Query::values_array`]).
    ///
    /// PHP: `array_values($query->getValues())`.
    pub fn values(&self) -> &[Value] {
        self.values.as_slice()
    }

    /// The values as the PHP array they are, keys included.
    ///
    /// PHP: `Query::getValues()`.
    pub fn values_array(&self) -> Array {
        self.values.to_array()
    }

    /// The values, mutably. Values pushed past a keyed array's keys get the
    /// next free integer key.
    pub fn values_mut(&mut self) -> &mut Vec<Value> {
        self.values.as_vec_mut()
    }

    /// The value at key `0`; `None` when there is none or it is `null`
    /// (`getValue($default)` returns `$default` then).
    ///
    /// PHP: `Query::getValue()`, which is `$this->values[0] ?? $default`.
    pub fn value(&self) -> Option<&Value> {
        self.values.first()
    }

    /// Sets the method.
    ///
    /// PHP: `Query::setMethod()`.
    pub fn set_method(&mut self, method: Method) -> &mut Self {
        self.method = method;
        self
    }

    /// Sets the attribute.
    ///
    /// PHP: `Query::setAttribute()`.
    pub fn set_attribute(&mut self, attribute: impl Into<String>) -> &mut Self {
        self.attribute = attribute.into();
        self
    }

    /// Sets the values to a list.
    ///
    /// PHP: `Query::setValues()`.
    pub fn set_values(&mut self, values: Vec<Value>) -> &mut Self {
        self.values = Values::list(values);
        self
    }

    /// Sets the values to any PHP array, keys kept.
    ///
    /// PHP: `Query::setValues()`.
    pub fn set_values_array(&mut self, values: Array) -> &mut Self {
        self.values = Values::array(values);
        self
    }

    /// Sets the only value.
    ///
    /// PHP: `Query::setValue()`.
    pub fn set_value(&mut self, value: Value) -> &mut Self {
        self.values = Values::list(vec![value]);
        self
    }

    /// The nested queries of a logical query (values that are queries).
    pub fn queries(&self) -> impl Iterator<Item = &Query> {
        self.values().iter().filter_map(as_query)
    }

    /// Whether the method nests queries (`and`, `or`, `elemMatch`).
    ///
    /// PHP: `Query::isNested()`.
    pub fn is_nested(&self) -> bool {
        self.method.is_logical()
    }

    /// Whether the query is a spatial filter.
    ///
    /// PHP: `Query::isSpatialQuery()`.
    pub fn is_spatial_query(&self) -> bool {
        self.method.is_spatial()
    }

    /// Whether the attribute is an array (set by the database).
    ///
    /// PHP: `Query::onArray()`.
    pub fn on_array(&self) -> bool {
        self.on_array
    }

    /// Marks the attribute as an array.
    ///
    /// PHP: `Query::setOnArray()`.
    pub fn set_on_array(&mut self, on_array: bool) {
        self.on_array = on_array;
    }

    /// The attribute's type, set by the database (`""` until then).
    ///
    /// PHP: `Query::getAttributeType()`.
    pub fn attribute_type(&self) -> &str {
        &self.attribute_type
    }

    /// Sets the attribute's type.
    ///
    /// PHP: `Query::setAttributeType()`.
    pub fn set_attribute_type(&mut self, kind: impl Into<String>) {
        self.attribute_type = kind.into();
    }

    /// Whether the attribute is spatial (`point`, `linestring`, `polygon`).
    ///
    /// PHP: `Query::isSpatialAttribute()`.
    pub fn is_spatial_attribute(&self) -> bool {
        Database::SPATIAL_TYPES.contains(&self.attribute_type.as_str())
    }

    /// Whether the attribute is an `object`.
    ///
    /// PHP: `Query::isObjectAttribute()`.
    pub fn is_object_attribute(&self) -> bool {
        self.attribute_type == Database::VAR_OBJECT
    }

    /// Whether `value` names a method [`Query::parse`] accepts (every
    /// method but `regex`, see [`Method::is_method`]).
    ///
    /// PHP: `Query::isMethod()`.
    pub fn is_method(value: &str) -> bool {
        Method::from_name(value).is_method()
    }

    /// Parses a query from its JSON (what [`Query::to_string`] writes).
    ///
    /// Fails with a [`crate::Kind::Query`] error: `Invalid query: <JSON
    /// error>`, `Invalid query. Must be an array, got <type>`, or one of
    /// [`Query::parse_query`]'s.
    ///
    /// PHP: `Query::parse()`.
    pub fn parse(query: impl AsRef<[u8]>) -> Result<Query> {
        let value = php_std::json::decode_ext::<crate::value::Ext>(
            query.as_ref(),
            Some(true),
            php_std::json::DEFAULT_DEPTH,
            php_std::json::Flags::NONE,
        )
        .map_err(|e| Error::query(format!("Invalid query: {}", e.message())))?;
        match value {
            Value::Array(a) => Self::parse_owned(a),
            other => Err(Error::query(format!("Invalid query. Must be an array, got {}", other.type_name()))),
        }
    }

    /// Builds a query from its array form (`method`, `attribute`, `values`;
    /// nested queries of logical methods as arrays or JSON strings).
    ///
    /// PHP: `Query::parseQuery()`. Errors (all [`crate::Kind::Query`]) and
    /// their order are PHP's: the method must be a string, then a method
    /// [`Query::is_method`] accepts, the attribute a string, the values an
    /// array, and each nested value an array or a string.
    pub fn parse_query(query: &Array) -> Result<Query> {
        Self::parse_owned(query.clone())
    }

    /// [`Query::parse_query`] of an array it may take apart.
    fn parse_owned(mut query: Array) -> Result<Query> {
        let present = |query: &Array, key: &str| query.get_str(key).filter(|v| !matches!(v, Value::Null)).cloned();
        let method = match present(&query, "method") {
            None => Str::from(""),
            Some(Value::Str(s)) => s,
            Some(other) => {
                return Err(Error::query(format!("Invalid query method. Must be a string, got {}", other.type_name())));
            }
        };
        let method_text = String::from_utf8_lossy(method.as_bytes());
        let method = match method.as_str() {
            Some(name) => Method::from_name(name),
            None => Method::Other(method_text.as_ref().into()),
        };
        if !method.is_method() {
            return Err(Error::query(format!("Invalid query method: {method_text}")));
        }
        let attribute = match present(&query, "attribute") {
            None => String::new(),
            Some(Value::Str(s)) => String::from_utf8_lossy(s.as_bytes()).into_owned(),
            Some(other) => {
                return Err(Error::query(format!(
                    "Invalid query attribute. Must be a string, got {}",
                    other.type_name()
                )));
            }
        };
        let values = match query.get_str("values") {
            Some(Value::Array(_)) => match query.remove(&ArrayKey::from("values")) {
                Some(Value::Array(a)) => Some(a),
                _ => None,
            },
            _ => None,
        };
        let mut values = match values.map(Value::Array).or_else(|| present(&query, "values")) {
            None => Array::new(),
            Some(Value::Array(a)) => a,
            Some(other) => {
                return Err(Error::query(format!("Invalid query values. Must be an array, got {}", other.type_name())));
            }
        };
        if method.is_logical() {
            let keys: Vec<ArrayKey> = values.keys().map(|k| k.to_owned()).collect();
            for key in keys {
                let parsed = match values.get(&key) {
                    Some(Value::Str(s)) => Self::parse(s.as_bytes())?,
                    Some(Value::Array(a)) => Self::parse_query(a)?,
                    Some(other) => {
                        return Err(Error::query(format!(
                            "Invalid nested query. Must be an array or string, got {}",
                            other.type_name()
                        )));
                    }
                    None => continue,
                };
                values.set(key, Value::from(parsed));
            }
        }
        Ok(Query::from_array(method, attribute, values))
    }

    /// Parses each query of a list with [`Query::parse`].
    ///
    /// PHP: `Query::parseQueries()`.
    pub fn parse_queries<S: AsRef<[u8]>>(queries: &[S]) -> Result<Vec<Query>> {
        queries.iter().map(Query::parse).collect()
    }

    /// The array form: `method`, `attribute` unless it is empty (or `"0"`),
    /// and `values`. Logical queries hold their children's array forms, with
    /// the values' keys, and no `values` at all when they have none; other
    /// queries hold the list of values, a cursor document as its id.
    ///
    /// A logical query whose values are not all queries fails like PHP's
    /// call to `toArray()` on them: an [`crate::Kind::Error`] error
    /// (`Call to a member function toArray() on int`, `Call to undefined
    /// method Utopia\Database\Document::toArray()`); an [`crate::Operator`]
    /// child gives its own array form.
    ///
    /// PHP: `Query::toArray()`.
    pub fn to_array(&self) -> Result<Array> {
        let mut out = Array::new();
        out.set(ArrayKey::from("method"), Value::Str(Str::from(self.method.as_str())));
        if !(self.attribute.is_empty() || self.attribute == "0") {
            out.set(ArrayKey::from("attribute"), Value::Str(Str::from(self.attribute.as_str())));
        }
        if self.method.is_logical() {
            let mut values = Array::new();
            for (key, value) in self.values.to_array() {
                let child = match &value {
                    Value::Ext(Ext::Query(q)) => q.to_array()?,
                    Value::Ext(Ext::Operator(o)) => o.to_array(),
                    Value::Ext(Ext::Document(d)) => {
                        return Err(Error::new(
                            crate::Kind::Error,
                            format!("Call to undefined method {}::toArray()", d.class()),
                        ));
                    }
                    Value::Object(_) => {
                        return Err(Error::new(crate::Kind::Error, "Call to undefined method stdClass::toArray()"));
                    }
                    other => {
                        let kind = match other {
                            Value::Bool(true) => "true",
                            Value::Bool(false) => "false",
                            v => v.debug_type(),
                        };
                        return Err(Error::new(
                            crate::Kind::Error,
                            format!("Call to a member function toArray() on {kind}"),
                        ));
                    }
                };
                values.set(key, Value::Array(child));
            }
            if !values.is_empty() {
                out.set(ArrayKey::from("values"), Value::Array(values));
            }
        } else {
            let cursor = matches!(self.method, Method::CursorAfter | Method::CursorBefore);
            let values: Array = self
                .values()
                .iter()
                .map(|v| match v {
                    Value::Ext(Ext::Document(d)) if cursor => Value::Str(Str::from(d.id())),
                    other => other.clone(),
                })
                .collect();
            out.set(ArrayKey::from("values"), Value::Array(values));
        }
        Ok(out)
    }

    /// The JSON of [`Query::to_array`], as PHP's `json_encode` writes it
    /// (documents nested in values as objects of their attributes, queries
    /// and operators nested in non-logical values as `{}`).
    ///
    /// Fails with a [`crate::Kind::Query`] error `Invalid Json: <JSON
    /// error>` when a value cannot be encoded (a non-finite float, a string
    /// that is not UTF-8, nesting deeper than 512).
    ///
    /// PHP: `Query::toString()`.
    pub fn to_string(&self) -> Result<String> {
        let array = self.to_array()?;
        php_std::json::encode(
            &json_value(&Value::Array(array)),
            php_std::json::Flags::NONE,
            php_std::json::DEFAULT_DEPTH,
        )
        .map_err(|e| Error::query(format!("Invalid Json: {}", e.message())))
    }

    /// The query's shape: `method:attribute`, values left out; a logical
    /// query is `method:attribute(child|child|...)` with its children's
    /// shapes sorted, so their order does not matter.
    ///
    /// PHP: `Query::shape()`.
    pub fn shape(&self) -> String {
        if !self.method.is_logical() {
            return format!("{}:{}", self.method, self.attribute);
        }
        let mut children: Vec<String> = self.queries().map(Query::shape).collect();
        children.sort_unstable();
        format!("{}:{}({})", self.method, self.attribute, children.join("|"))
    }

    /// The md5 (hex) of the sorted shapes of `queries`, joined by `|`: the
    /// same for two sets of queries that differ only in their values.
    ///
    /// Each value is a query, or a string parsed with [`Query::parse`]
    /// (whose errors are returned); anything else fails with a
    /// [`crate::Kind::Query`] error.
    ///
    /// PHP: `Query::fingerprint()`.
    pub fn fingerprint(queries: &[Value]) -> Result<String> {
        let mut shapes = Vec::with_capacity(queries.len());
        for query in queries {
            shapes.push(match query {
                Value::Str(s) => Query::parse(s.as_bytes())?.shape(),
                Value::Ext(Ext::Query(q)) => q.shape(),
                _ => {
                    return Err(Error::query(
                        "Invalid query element for fingerprint: expected string or Query instance",
                    ));
                }
            });
        }
        shapes.sort_unstable();
        Ok(hex::encode(md5::Md5::digest(shapes.join("|").as_bytes())))
    }

    /// The cursor document of `cursorAfter`/`cursorBefore`, if its value is one.
    pub fn cursor_document(&self) -> Option<&Document> {
        self.values().first().and_then(crate::value::as_document)
    }
}

use md5::Digest as _;

/// A value as `json_encode` sees it: a document is an object of its
/// attributes (an `ArrayObject`), a query or operator an object without
/// public properties (`{}`).
pub(crate) fn json_value(value: &Value) -> php_std::Value {
    fn array(a: &Array) -> php_std::Array {
        a.iter().map(|(k, v)| (k.to_owned(), json_value(v))).collect()
    }
    match value {
        Value::Null => php_std::Value::Null,
        Value::Bool(b) => php_std::Value::Bool(*b),
        Value::Int(i) => php_std::Value::Int(*i),
        Value::Float(f) => php_std::Value::Float(*f),
        Value::Str(s) => php_std::Value::Str(s.clone()),
        Value::Array(a) => php_std::Value::Array(array(a)),
        Value::Object(a) => php_std::Value::Object(array(a)),
        Value::Ext(Ext::Document(d)) => php_std::Value::Object(array(d.as_array())),
        Value::Ext(Ext::Query(_) | Ext::Operator(_)) => php_std::Value::Object(php_std::Array::new()),
    }
}

#[cfg(test)]
mod tests;
