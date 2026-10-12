//! [`Query`]: one filter, order, selection or pagination step of a find.
//!
//! PHP: `Utopia\Database\Query`. The method is a [`Method`] (`TYPE_*`);
//! methods PHP stores without knowing them (`new Query('foo')`) are
//! [`Method::Other`], which validators reject as PHP's do. Values are
//! [`Value`]s: nested queries of `and`, `or` and `elemMatch` are
//! [`crate::value::Ext::Query`] values and a cursor's document a
//! [`crate::value::Ext::Document`], as in PHP's mixed `$values`.
//!
//! Overlap with `utopia-php/query` (`crates/utopia/query`): that library has
//! its own `Query` class with a different method set and SQL builders; the
//! two are not related in PHP (utopia-php/database 7.4 does not depend on
//! it). See the crate guide for how they are meant to be reconciled.

use crate::document::Document;
use crate::error::{Error, Result};
use crate::value::{Array, ArrayKey, Ext, Str, Value, as_query};

/// A query method (`Query::TYPE_*`).
#[derive(Clone, PartialEq, Eq, Hash, Debug)]
pub enum Method {
    /// `equal`
    Equal,
    /// `notEqual`
    NotEqual,
    /// `lessThan`
    LessThan,
    /// `lessThanEqual`
    LessThanEqual,
    /// `greaterThan`
    GreaterThan,
    /// `greaterThanEqual`
    GreaterThanEqual,
    /// `contains`
    Contains,
    /// `containsAny`
    ContainsAny,
    /// `notContains`
    NotContains,
    /// `search`
    Search,
    /// `notSearch`
    NotSearch,
    /// `isNull`
    IsNull,
    /// `isNotNull`
    IsNotNull,
    /// `between`
    Between,
    /// `notBetween`
    NotBetween,
    /// `startsWith`
    StartsWith,
    /// `notStartsWith`
    NotStartsWith,
    /// `endsWith`
    EndsWith,
    /// `notEndsWith`
    NotEndsWith,
    /// `regex`
    Regex,
    /// `exists`
    Exists,
    /// `notExists`
    NotExists,
    /// `crosses`
    Crosses,
    /// `notCrosses`
    NotCrosses,
    /// `distanceEqual`
    DistanceEqual,
    /// `distanceNotEqual`
    DistanceNotEqual,
    /// `distanceGreaterThan`
    DistanceGreaterThan,
    /// `distanceLessThan`
    DistanceLessThan,
    /// `intersects`
    Intersects,
    /// `notIntersects`
    NotIntersects,
    /// `overlaps`
    Overlaps,
    /// `notOverlaps`
    NotOverlaps,
    /// `touches`
    Touches,
    /// `notTouches`
    NotTouches,
    /// `vectorDot`
    VectorDot,
    /// `vectorCosine`
    VectorCosine,
    /// `vectorEuclidean`
    VectorEuclidean,
    /// `select`
    Select,
    /// `orderDesc`
    OrderDesc,
    /// `orderAsc`
    OrderAsc,
    /// `orderRandom`
    OrderRandom,
    /// `limit`
    Limit,
    /// `offset`
    Offset,
    /// `cursorAfter`
    CursorAfter,
    /// `cursorBefore`
    CursorBefore,
    /// `and`
    And,
    /// `or`
    Or,
    /// `containsAll`
    ContainsAll,
    /// `elemMatch`
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
        TYPES.iter().find(|m| m.as_str() == name).cloned().unwrap_or_else(|| Method::Other(name.into()))
    }

    /// Whether the method is one PHP knows (`Query::isMethod`).
    pub fn is_known(&self) -> bool {
        !matches!(self, Method::Other(_))
    }

    /// Whether the method nests queries (`and`, `or`, `elemMatch`).
    pub fn is_logical(&self) -> bool {
        matches!(self, Method::And | Method::Or | Method::ElemMatch)
    }

    /// Whether the method is a spatial filter (`isSpatialQuery`).
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

    /// Whether the method is a vector similarity (`VECTOR_TYPES`).
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
#[derive(Clone, PartialEq, Debug)]
pub struct Query {
    method: Method,
    attribute: String,
    attribute_type: String,
    on_array: bool,
    is_object_attribute: bool,
    values: Vec<Value>,
}

impl Query {
    /// `new Query($method, $attribute, $values)`: an empty attribute of
    /// `orderAsc` and `orderDesc` is `$sequence`.
    pub fn new(method: Method, attribute: impl Into<String>, values: Vec<Value>) -> Self {
        let mut attribute = attribute.into();
        if attribute.is_empty() && matches!(method, Method::OrderAsc | Method::OrderDesc) {
            attribute = "$sequence".to_owned();
        }
        Self { method, attribute, attribute_type: String::new(), on_array: false, is_object_attribute: false, values }
    }

    /// The method (`getMethod()`).
    pub fn method(&self) -> &Method {
        &self.method
    }

    /// The attribute (`getAttribute()`).
    pub fn attribute(&self) -> &str {
        &self.attribute
    }

    /// The values (`getValues()`).
    pub fn values(&self) -> &[Value] {
        &self.values
    }

    /// The values, mutably.
    pub fn values_mut(&mut self) -> &mut Vec<Value> {
        &mut self.values
    }

    /// The first value (`getValue()`).
    pub fn value(&self) -> Option<&Value> {
        self.values.first()
    }

    /// Sets the method (`setMethod()`).
    pub fn set_method(&mut self, method: Method) -> &mut Self {
        self.method = method;
        self
    }

    /// Sets the attribute (`setAttribute()`).
    pub fn set_attribute(&mut self, attribute: impl Into<String>) -> &mut Self {
        self.attribute = attribute.into();
        self
    }

    /// Sets the values (`setValues()`).
    pub fn set_values(&mut self, values: Vec<Value>) -> &mut Self {
        self.values = values;
        self
    }

    /// Sets the only value (`setValue()`).
    pub fn set_value(&mut self, value: Value) -> &mut Self {
        self.values = vec![value];
        self
    }

    /// The nested queries of a logical query (values that are queries).
    pub fn queries(&self) -> impl Iterator<Item = &Query> {
        self.values.iter().filter_map(as_query)
    }

    /// Whether the method nests queries (`isNested()`).
    pub fn is_nested(&self) -> bool {
        self.method.is_logical()
    }

    /// Whether the query is a spatial filter (`isSpatialQuery()`).
    pub fn is_spatial_query(&self) -> bool {
        self.method.is_spatial()
    }

    /// Whether the attribute is an array (`onArray()`).
    pub fn on_array(&self) -> bool {
        self.on_array
    }

    /// Marks the attribute as an array (`setOnArray()`).
    pub fn set_on_array(&mut self, on_array: bool) {
        self.on_array = on_array;
    }

    /// The attribute's type, set by the database (`getAttributeType()`).
    pub fn attribute_type(&self) -> &str {
        &self.attribute_type
    }

    /// Sets the attribute's type (`setAttributeType()`).
    pub fn set_attribute_type(&mut self, kind: impl Into<String>) {
        self.attribute_type = kind.into();
    }

    /// Whether the attribute is spatial (`isSpatialAttribute()`).
    pub fn is_spatial_attribute(&self) -> bool {
        matches!(self.attribute_type.as_str(), "point" | "linestring" | "polygon")
    }

    /// Whether the attribute is an object (`isObjectAttribute()`).
    pub fn is_object_attribute(&self) -> bool {
        self.attribute_type == "object"
    }

    /// Whether the query's method is known (`Query::isMethod`).
    pub fn is_method(value: &str) -> bool {
        Method::from_name(value).is_known()
    }

    /// `Query::parse($json)`.
    pub fn parse(query: &str) -> Result<Query> {
        let value = php_std::json::decode(
            query.as_bytes(),
            Some(true),
            php_std::json::DEFAULT_DEPTH,
            php_std::json::Flags::NONE,
        )
        .map_err(|e| Error::query(format!("Invalid query: {}", e.message())))?;
        match crate::value::from_plain(value) {
            Value::Array(a) => Self::parse_query(&a),
            other => Err(Error::query(format!("Invalid query. Must be an array, got {}", other.type_name()))),
        }
    }

    /// `Query::parseQuery($array)`.
    pub fn parse_query(query: &Array) -> Result<Query> {
        let empty = Value::Str(Str::from(""));
        let method = query.get_str("method").filter(|v| !matches!(v, Value::Null)).unwrap_or(&empty);
        let attribute = query.get_str("attribute").filter(|v| !matches!(v, Value::Null)).unwrap_or(&empty);
        let no_values = Value::Array(Array::new());
        let values = query.get_str("values").filter(|v| !matches!(v, Value::Null)).unwrap_or(&no_values);

        let Value::Str(method) = method else {
            return Err(Error::query(format!("Invalid query method. Must be a string, got {}", method.type_name())));
        };
        let method_text = String::from_utf8_lossy(method.as_bytes()).into_owned();
        let method = Method::from_name(&method_text);
        if !method.is_known() {
            return Err(Error::query(format!("Invalid query method: {method_text}")));
        }
        let Value::Str(attribute) = attribute else {
            return Err(Error::query(format!(
                "Invalid query attribute. Must be a string, got {}",
                attribute.type_name()
            )));
        };
        let Value::Array(values) = values else {
            return Err(Error::query(format!("Invalid query values. Must be an array, got {}", values.type_name())));
        };
        let mut values = values.clone();
        if method.is_logical() {
            let keys: Vec<ArrayKey> = values.keys().map(|k| k.to_owned()).collect();
            for key in keys {
                let parsed = match values.get(&key) {
                    Some(Value::Str(s)) => Self::parse(&String::from_utf8_lossy(s.as_bytes()))?,
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
        Ok(Query::new(
            method,
            String::from_utf8_lossy(attribute.as_bytes()).into_owned(),
            values.into_iter().map(|(_, v)| v).collect(),
        ))
    }

    /// `Query::toArray()`.
    pub fn to_array(&self) -> Array {
        let mut out = Array::new();
        out.set(ArrayKey::from("method"), Value::Str(Str::from(self.method.as_str())));
        if !self.attribute.is_empty() && self.attribute != "0" {
            out.set(ArrayKey::from("attribute"), Value::Str(Str::from(self.attribute.as_str())));
        }
        let cursor = matches!(self.method, Method::CursorAfter | Method::CursorBefore);
        let values: Array = if self.method.is_logical() {
            self.values
                .iter()
                .map(|v| match v {
                    Value::Ext(Ext::Query(q)) => Value::Array(q.to_array()),
                    other => other.clone(),
                })
                .collect()
        } else {
            self.values
                .iter()
                .map(|v| match v {
                    Value::Ext(Ext::Document(d)) if cursor => Value::Str(Str::from(d.id())),
                    other => other.clone(),
                })
                .collect()
        };
        // PHP leaves `values` unset for a logical query without values.
        if !(self.method.is_logical() && values.is_empty()) {
            out.set(ArrayKey::from("values"), Value::Array(values));
        }
        out
    }

    /// The cursor document of `cursorAfter`/`cursorBefore`, if its value is one.
    pub fn cursor_document(&self) -> Option<&Document> {
        self.values.first().and_then(crate::value::as_document)
    }
}
